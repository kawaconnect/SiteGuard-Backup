<?php
/**
 * SiteGuard Backup - Remote Send Storage Method
 *
 * Sends backup files to another WordPress site via encrypted RPC.
 * Used for site-to-site migration.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Remotesend extends SiteGuard_Backup_Module {

	/** @var int Default chunk size (2MB) */
	private $chunk_size = 2097152;

	/** @var array Remote site configuration */
	private $remote_site;

	public function get_default_options() {
		return array(
			'site_id' => 0,
		);
	}

	/**
	 * Set which remote site to send to.
	 */
	public function set_remote_site($site_data) {
		$this->remote_site = $site_data;
	}

	/**
	 * Load remote site from stored configuration.
	 */
	private function load_remote_site($site_id = null) {
		if ($this->remote_site) return $this->remote_site;

		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());

		if (null === $site_id) {
			$options = $this->get_options();
			$site_id = isset($options['site_id']) ? $options['site_id'] : 0;
		}

		if (!isset($sites[$site_id])) {
			return new WP_Error('no_site', "Remote site #{$site_id} not found.");
		}

		$this->remote_site = $sites[$site_id];
		return $this->remote_site;
	}

	public function backup($file) {
		$sgbackup = SiteGuard_Backup::instance();

		// Use instance_id (set from service string e.g. "remotesend/3") so that
		// each multi-instance destination resolves to its own remote site.
		$site_id = ($this->instance_id !== '') ? intval($this->instance_id) : null;
		$sgbackup->log("RemoteSend: instance_id=" . var_export($this->instance_id, true) . " site_id=" . var_export($site_id, true));

		$site = $this->load_remote_site($site_id);
		if (is_wp_error($site)) {
			$sgbackup->log("RemoteSend: load_remote_site failed: " . $site->get_error_message(), 'error');
			return $site;
		}

		$sgbackup->log("RemoteSend: target URL = " . (isset($site['url']) ? $site['url'] : 'unknown'));

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$rpc = new SiteGuard_Backup_Remote_Communications();

		$filename = basename($file);
		$filesize = filesize($file);
		$sgbackup->log("Sending {$filename} (" . SiteGuard_Backup_Filesystem::format_bytes($filesize) . ") to remote site");

		// Check file status on remote (for resumption)
		$status = $rpc->send_message($site, 'get_file_status', array('file' => $filename));
		$start_offset = 0;
		if (!is_wp_error($status) && isset($status['size'])) {
			$start_offset = intval($status['size']);
			if ($start_offset > 0 && $start_offset < $filesize) {
				$sgbackup->log("Resuming from offset " . SiteGuard_Backup_Filesystem::format_bytes($start_offset));
			} elseif (isset($status['status']) && $status['status'] == 1) {
				$sgbackup->log("File already fully uploaded to remote site");
				return true;
			}
		}

		// Adaptive chunk size from transient
		$opts = SiteGuard_Backup_Options::instance();
		$adaptive_chunk = $opts->get_transient('sgbackup_remotesend_chunk_' . $site_id);
		if ($adaptive_chunk && $adaptive_chunk > 0) {
			$this->chunk_size = $adaptive_chunk;
		}

		// Each chunk exists ~5× over in memory while in flight (raw, base64, JSON,
		// AES ciphertext, HTTP body) and the receiver mirrors that. On low-RAM
		// hosts halve the default so a 2MB chunk cannot become a 10MB+ spike.
		if ($sgbackup->is_low_memory()) {
			$this->chunk_size = min($this->chunk_size, 1048576);
		}

		// Send in chunks
		$fh = fopen($file, 'rb');
		if (!$fh) return new WP_Error('file_open', "Cannot open file: {$filename}");

		if ($start_offset > 0) {
			fseek($fh, $start_offset);
		}

		$offset = $start_offset;
		$errors = 0;

		while ($offset < $filesize) {
			// Ensure file position matches offset (allows true retry on error)
			fseek($fh, $offset);
			$chunk = fread($fh, $this->chunk_size);
			if (false === $chunk || strlen($chunk) === 0) {
				// Short read: the file ended before all bytes were sent. Do NOT
				// signal upload_complete - the remote copy would be truncated.
				fclose($fh);
				return new WP_Error(
					'short_read',
					sprintf(
						'Short read while sending %s: read failed at offset %d of %d bytes. Upload not completed.',
						$filename,
						$offset,
						$filesize
					)
				);
			}

			$is_last = ($offset + strlen($chunk) >= $filesize);

			$result = $rpc->send_message($site, 'send_chunk', array(
				'file'       => $filename,
				'data'       => base64_encode($chunk),
				'start'      => $offset,
				'last_chunk' => $is_last,
			));

			if (is_wp_error($result)) {
				$errors++;
				$err_msg = $result->get_error_message();
				$sgbackup->log("Chunk send error at offset {$offset}: " . $err_msg, 'warning');

				// Out-of-sequence recovery: a lost response can leave the receiver
				// having written a chunk the sender thinks failed. Re-syncing the
				// offset from the receiver's authoritative size turns an otherwise
				// unrecoverable deterministic mismatch into a clean resume, instead
				// of burning the retry budget re-sending the same rejected offset.
				if ($result->get_error_code() === 'out_of_sequence') {
					$resync = $rpc->send_message($site, 'get_file_status', array('file' => $filename));
					if (is_array($resync) && isset($resync['size'])) {
						$new_offset = intval($resync['size']);
						if ($new_offset >= 0 && $new_offset <= $filesize && $new_offset !== $offset) {
							$sgbackup->log("Re-syncing offset from {$offset} to {$new_offset} after out-of-sequence");
							$offset = $new_offset;
							$errors = 0; // genuine progress signal, not a hard failure
						}
					}
					continue; // Retry from the corrected offset
				}

				// Back off on rate limit errors to let the window reset
				if (stripos($err_msg, 'rate limit') !== false) {
					$sgbackup->log("Rate limited - waiting 10 seconds before retry");
					sleep(10);
				} else {
					// Reduce chunk size on other errors
					$this->chunk_size = max(524288, intval($this->chunk_size / 2)); // Min 512KB
					$opts->set_transient('sgbackup_remotesend_chunk_' . $site_id, $this->chunk_size, 3600);
				}

				if ($errors > 5) {
					fclose($fh);
					return new WP_Error('too_many_errors', 'Too many chunk send errors');
				}

				continue; // Retry same chunk
			}

			$offset += strlen($chunk);
			$errors = 0; // Reset on success

			$sgbackup->log("Sent " . SiteGuard_Backup_Filesystem::format_bytes($offset) . " / " .
				SiteGuard_Backup_Filesystem::format_bytes($filesize));

			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		fclose($fh);

		// Signal upload complete
		$complete = $rpc->send_message($site, 'upload_complete', array('file' => $filename));
		if (is_wp_error($complete)) {
			$sgbackup->log("RemoteSend: upload_complete failed: " . $complete->get_error_message(), 'error');
			return new WP_Error(
				'upload_complete_failed',
				'All chunks were sent but the remote site did not acknowledge upload completion: ' . $complete->get_error_message()
			);
		}

		return true;
	}

	public function download($file, $dest_dir) {
		return new WP_Error('not_supported', 'Download from remote site not supported via this method.');
	}

	public function delete($files) {
		// Remote sites manage their own files
		return true;
	}

	public function credentials_test() {
		$site = $this->load_remote_site();
		if (is_wp_error($site)) return $site;

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$rpc = new SiteGuard_Backup_Remote_Communications();
		$result = $rpc->send_message($site, 'ping', array());

		return is_wp_error($result) ? $result : true;
	}
}
