<?php
/**
 * SiteGuard Backup - Remote Send (Migration Receiver)
 *
 * Listens for incoming backup chunks from remote sites.
 * Activated when sgbackup_migrator_localkeys contains keys.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Remote_Send {

	private static $instance = null;

	/** @var array PHP events collected during processing */
	private $php_events = array();

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Authenticated users: handle RPC via the main AJAX action
		add_action('wp_ajax_sgbackup_ajax', array($this, 'handle_rpc_message'), 5);
		// Unauthenticated cross-site RPC: must use the same action the sender POSTs to
		add_action('wp_ajax_nopriv_sgbackup_ajax', array($this, 'handle_rpc_message_nopriv'), 5);
	}

	/**
	 * RPC from a logged-in administrator's browser session (the same action name
	 * as the admin AJAX dispatcher; this runs first and only for rpc_message).
	 */
	public function handle_rpc_message() {
		if (empty($_POST['subaction']) || $_POST['subaction'] !== 'rpc_message') return;
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Insufficient permissions.'));
		}
		$this->process_rpc();
	}

	/**
	 * RPC from another site (no WordPress session). Authorized by the migration
	 * key: see process_rpc().
	 */
	public function handle_rpc_message_nopriv() {
		if (empty($_POST['subaction']) || $_POST['subaction'] !== 'rpc_message') return;
		$this->process_rpc();
	}

	/**
	 * Authenticate, decrypt, run and answer one RPC message.
	 *
	 * Order: rate limit, key lookup, HMAC with the key's secret (before any RSA
	 * work), key binding and expiry, decryption and replay checks, command. The
	 * answer is signed with the same secret and bound to the message nonce. Every
	 * authentication failure answers the same generic message.
	 */
	private function process_rpc() {
		if (empty($_SERVER['HTTP_X_SGBACKUP_RPC'])) {
			wp_send_json_error(array('message' => 'Authentication failed.'));
		}

		// Rate limiting: max 600 requests per 60-second window per IP.
		require_once SGBACKUP_DIR . 'includes/class-security.php';
		$ip = SiteGuard_Backup_Security::get_client_ip();
		if ('' === $ip) $ip = 'unknown';
		$rate_key = 'sgbackup_rpc_rate_' . md5($ip);
		$rate_data = get_transient($rate_key);
		if (!is_array($rate_data) || !isset($rate_data['t']) || (time() - $rate_data['t']) >= 60) {
			$rate_data = array('c' => 0, 't' => time());
		}
		$rate_data['c']++;
		if ($rate_data['c'] > 600) {
			wp_send_json_error(array('message' => 'Rate limit exceeded. Try again later.'));
		}
		set_transient($rate_key, $rate_data, 120);

		$fail = function () { wp_send_json_error(array('message' => 'Authentication failed.')); };

		$name_hash = isset($_POST['name_hash']) && is_string($_POST['name_hash']) ? sanitize_text_field($_POST['name_hash']) : '';
		$encrypted = isset($_POST['encrypted']) && is_string($_POST['encrypted']) ? $_POST['encrypted'] : '';
		$enc_key   = isset($_POST['key']) && is_string($_POST['key']) ? $_POST['key'] : '';
		$mac       = isset($_POST['mac']) && is_string($_POST['mac']) ? $_POST['mac'] : '';
		if ('' === $name_hash || '' === $encrypted || '' === $enc_key
			|| !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $encrypted) || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $enc_key)) {
			$fail();
		}

		$opts = SiteGuard_Backup_Options::instance();
		$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());
		if (!is_array($local_keys) || !isset($local_keys[$name_hash]) || !is_array($local_keys[$name_hash])) {
			$fail();
		}
		$key_data = $local_keys[$name_hash];
		if (empty($key_data['private_key']) || !is_string($key_data['private_key'])) {
			$fail();
		}

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$secret = SiteGuard_Backup_Remote_Communications::key_secret(isset($key_data['secret']) ? $key_data['secret'] : '');
		if ('' !== $secret) {
			$expected = SiteGuard_Backup_Remote_Communications::mac($secret, array('request', $name_hash, $encrypted, $enc_key));
			if ('' === $mac || !hash_equals($expected, $mac)) {
				$fail();
			}
		} elseif (!SiteGuard_Backup_Remote_Communications::legacy_keys_allowed()) {
			wp_send_json_error(array('message' => 'This migration key was made by a SiteGuard Backup version older than 1.7.0 and cannot authenticate messages. Create a new key on this site and add the destination again on the sending site.'));
		}

		// A key belongs to the site it was created on (keys are stored network-wide).
		if (isset($key_data['blog_id']) && (int) $key_data['blog_id'] !== (int) get_current_blog_id()) {
			$fail();
		}
		if (!empty($key_data['expires']) && time() > (int) $key_data['expires']) {
			wp_send_json_error(array('message' => 'This migration key has expired. Create a new key on this site and add the destination again on the sending site.'));
		}

		$rpc = new SiteGuard_Backup_Remote_Communications();
		$payload = $rpc->decrypt_message($encrypted, $enc_key, $this->decrypt_private_key($key_data['private_key']));
		if (is_wp_error($payload)) {
			$fail();
		}

		$command = isset($payload['command']) && is_string($payload['command']) ? $payload['command'] : '';
		$data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

		$result = $this->dispatch_command($command, $data, $name_hash, $key_data);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		if ('' !== $secret) {
			$json = wp_json_encode($result);
			wp_send_json_success(array(
				'signed' => $json,
				'mac'    => SiteGuard_Backup_Remote_Communications::mac($secret, array('response', (string) $payload['nonce'], $json)),
			));
		}
		wp_send_json_success($result);
	}

	/**
	 * Decrypt a stored migration private key.
	 *
	 * Keys generated after the at-rest-encryption change carry the 'sgenc:'
	 * prefix and are decrypted here. Legacy plaintext PEM keys (no prefix) pass
	 * straight through unchanged. If decryption fails (e.g. AUTH_KEY changed),
	 * decrypt_option() returns '' and flags the site - the empty key then fails
	 * key parsing loudly rather than being misused.
	 *
	 * @param string $stored Stored private key value.
	 * @return string Decrypted (or plaintext-legacy) PEM.
	 */
	private function decrypt_private_key($stored) {
		if (!is_string($stored) || $stored === '') return $stored;
		if (file_exists(SGBACKUP_DIR . 'includes/class-security.php')) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			if (class_exists('SiteGuard_Backup_Security')) {
				return SiteGuard_Backup_Security::decrypt_option($stored);
			}
		}
		return $stored;
	}

	/**
	 * Dispatch an RPC command.
	 */
	private function dispatch_command($command, $data, $name_hash, $key_data) {
		error_log("SiteGuard Backup RPC Receiver: command='{$command}' name_hash={$name_hash}");

		switch ($command) {
			case 'ping':
				return $this->cmd_ping($data, $name_hash);

			case 'send_chunk':
				return $this->cmd_send_chunk($data, $name_hash);

			case 'get_file_status':
				return $this->cmd_get_file_status($data);

			case 'upload_complete':
				return $this->cmd_upload_complete($data);

			case 'set_backup_metadata':
				return $this->cmd_set_backup_metadata($data, $name_hash);

			default:
				error_log("SiteGuard Backup RPC Receiver: unknown command '{$command}'");
				return new WP_Error('unknown_command', "Unknown RPC command: {$command}");
		}
	}

	/**
	 * Ping command - responds to connectivity test.
	 */
	private function cmd_ping($data, $name_hash) {
		return array(
			'status' => 'ok',
			'plugin' => 'siteguardbackup',
			'version' => SGBACKUP_VERSION,
			'wp_version' => get_bloginfo('version'),
			'site_url' => site_url(),
		);
	}

	/**
	 * Receive a file chunk.
	 */
	private function cmd_send_chunk($data, $name_hash) {
		if (empty($data['file']) || !isset($data['data']) || !isset($data['start'])) {
			error_log("SiteGuard Backup RPC Receiver: send_chunk missing params - file=" . (isset($data['file']) ? 'yes' : 'no') . " data=" . (isset($data['data']) ? 'yes' : 'no') . " start=" . (isset($data['start']) ? 'yes' : 'no'));
			return new WP_Error('invalid_chunk', 'Missing required chunk parameters.');
		}
		if (!is_string($data['file']) || !is_string($data['data']) || !is_scalar($data['start'])) {
			return new WP_Error('invalid_chunk', 'Chunk parameters have the wrong type.');
		}

		// Use basename() to strip any path components, then sanitize
		$filename = sanitize_file_name(basename($data['file']));
		error_log("SiteGuard Backup RPC Receiver: send_chunk file={$filename} start={$data['start']} last=" . (!empty($data['last_chunk']) ? 'yes' : 'no'));

		// Estimate decoded size before base64_decode to prevent memory abuse.
		// 2MB decoded = ceil(2097152 * 4/3) = 2796204 bytes base64 (with padding).
		$b64_len = is_string($data['data']) ? strlen($data['data']) : 0;
		if ($b64_len > 2796204) {
			return new WP_Error('chunk_too_large', 'Chunk data exceeds maximum size.');
		}

		$chunk_data = base64_decode($data['data']);
		if (false === $chunk_data) {
			return new WP_Error('invalid_data', 'Invalid base64 chunk data.');
		}

		// Cap max chunk size to 2MB to prevent memory abuse
		if (strlen($chunk_data) > 2097152) {
			return new WP_Error('chunk_too_large', 'Chunk size exceeds maximum of 2MB.');
		}

		$start = intval($data['start']);

		// Validate start offset is non-negative
		if ($start < 0) {
			return new WP_Error('invalid_start', 'Start offset must be non-negative.');
		}
		$is_last = !empty($data['last_chunk']);

		// Enforce maximum filename length
		if (strlen($filename) > 255) {
			return new WP_Error('filename_too_long', 'Filename exceeds maximum length.');
		}

		// Validate filename pattern - strict whitelist
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|json|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
			error_log("SiteGuard Backup RPC Receiver: rejected filename '{$filename}' - does not match whitelist pattern");
			return new WP_Error('invalid_filename', 'Invalid backup filename format.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Write to .tmp file during transfer
		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		// Atomic database-backed lock instead of flock() - CIFS/SMB mounts
		// (Azure App Service, NAS) don't support POSIX file locking.
		// Uses INSERT ... ON DUPLICATE KEY UPDATE for atomicity (no TOCTOU race).
		global $wpdb;
		$chunk_lock_key = 'sgbackup_chunk_' . md5($filename);
		$lock_value = wp_generate_password(12, false);
		$now = time();
		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE option_value = IF(
			   CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d,
			   VALUES(option_value), option_value
			 )",
			$chunk_lock_key, $lock_value . '|' . $now, $now - 30
		));
		wp_cache_delete($chunk_lock_key, 'options');
		$check = get_option($chunk_lock_key);
		$check_val = is_string($check) ? substr($check, 0, strrpos($check, '|')) : '';
		if ($check_val !== $lock_value) {
			return new WP_Error('file_lock', 'Cannot acquire chunk write lock.');
		}

		$fh = fopen($tmp_file, 'c+b');
		if (!$fh) {
			delete_option($chunk_lock_key);
			return new WP_Error('file_open', 'Cannot open temp file for writing.');
		}

		// Track expected next offset
		clearstatcache(true, $tmp_file);
		$expected_offset = filesize($tmp_file);
		if ($expected_offset === false) $expected_offset = 0;
		if ($start !== $expected_offset) {
			fclose($fh);
			delete_option($chunk_lock_key);
			return new WP_Error('out_of_sequence', sprintf('Out-of-sequence write: expected offset %d but got %d.', $expected_offset, $start));
		}

		fseek($fh, $start);
		$written = fwrite($fh, $chunk_data);

		// A short write (e.g. disk full) previously slipped through the
		// false===$written check and, on the last chunk, a truncated .tmp got
		// renamed to the final file and registered as a complete backup. Roll
		// the file back to its pre-chunk length so the sender can retry the
		// SAME offset, and never finalize a truncated file.
		if (false === $written || $written !== strlen($chunk_data)) {
			@ftruncate($fh, $start);
			@fflush($fh);
			fclose($fh);
			delete_option($chunk_lock_key);
			$got = (false === $written) ? 'write error' : ($written . ' of ' . strlen($chunk_data) . ' bytes');
			return new WP_Error('file_write', 'Failed to write full chunk data (' . $got . ') - rolled back for retry.');
		}

		fclose($fh);
		delete_option($chunk_lock_key);

		clearstatcache(true, $tmp_file);
		$result = array(
			'size'   => filesize($tmp_file),
			'status' => 0, // 0 = incomplete
		);

		// On last chunk, rename to final filename
		if ($is_last) {
			if (file_exists($final_file)) {
				// A peer may replace only its own earlier transfer. A file not in
				// history at all (null) may be a local backup still being written,
				// so it is protected too; that used to be deleted and replaced.
				if (true !== $this->file_owned_by_remote_set($final_file, $name_hash)) {
					@unlink($tmp_file);
					return new WP_Error('file_exists', 'A local backup file with this name already exists and will not be overwritten.');
				}
			}
			// rename() replaces the old copy atomically; nothing is deleted first.
			if (!@rename($tmp_file, $final_file)) {
				// rename fails across mount points (e.g. /tmp to /home on Azure):
				// copy next to the target, then swap it in.
				$staged = $final_file . '.sgbackup-tmp-' . wp_generate_password(6, false);
				if (!@copy($tmp_file, $staged) || filesize($staged) !== filesize($tmp_file) || !@rename($staged, $final_file)) {
					@unlink($staged);
					return new WP_Error('rename_failed', 'Failed to finalize uploaded file.');
				}
				@unlink($tmp_file);
			}

			$result['status'] = 1; // 1 = complete
			clearstatcache(true, $final_file);
			$result['size'] = filesize($final_file);

			// Register in backup history
			$this->register_received_file($filename, $name_hash);
		}

		return $result;
	}

	/**
	 * Get file status - check if a file exists or is partially uploaded.
	 */
	private function cmd_get_file_status($data) {
		if (empty($data['file']) || !is_string($data['file'])) {
			return new WP_Error('missing_file', 'Missing file parameter.');
		}

		$filename = sanitize_file_name(basename($data['file']));

		// Validate filename pattern (same whitelist as cmd_send_chunk)
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|json|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
			return new WP_Error('invalid_filename', 'Invalid backup filename format.');
		}
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		clearstatcache(true, $final_file);
		if (file_exists($final_file)) {
			// md5 lets the sender tell "already sent" from "a different file with
			// the same name" (older senders ignore it).
			return array('size' => filesize($final_file), 'status' => 1, 'md5' => md5_file($final_file));
		}

		clearstatcache(true, $tmp_file);
		if (file_exists($tmp_file)) {
			return array('size' => filesize($tmp_file), 'status' => 0);
		}

		return array('size' => 0, 'status' => 0);
	}

	/**
	 * Upload complete - acknowledge that all files for a backup have been received.
	 */
	private function cmd_upload_complete($data) {
		$filename = (isset($data['file']) && is_string($data['file'])) ? sanitize_file_name($data['file']) : '';
		return array('status' => 'acknowledged', 'file' => $filename);
	}

	/**
	 * Set metadata (label, always_keep, source_url) on a received backup.
	 */
	private function cmd_set_backup_metadata($data, $name_hash = '') {
		$timestamp = (isset($data['timestamp']) && is_scalar($data['timestamp'])) ? intval($data['timestamp']) : 0;
		$nonce = (isset($data['nonce']) && is_string($data['nonce'])) ? sanitize_text_field($data['nonce']) : '';
		// Scalar fields must be strings - an array here is a TypeError on PHP 8.
		foreach (array('label', 'source_url', 'subsite_domain', 'subsite_path', 'subsite_url', 'subsite_home_url', 'subsite_scheme', 'subsite_stylesheet', 'subsite_template') as $sk) {
			if (isset($data[$sk]) && !is_string($data[$sk])) unset($data[$sk]);
		}

		if (empty($timestamp) && empty($nonce)) {
			return new WP_Error('missing_param', 'Missing timestamp or nonce');
		}

		// Serialize this read-modify-write against the semaphore-protected
		// local history writers (class-backup-history.php) to avoid a lost-update
		// race. Same acquire/retry pattern as SiteGuard_Backup_History::add_backup().
		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
		// Flush cache to ensure fresh data inside the locked section
		wp_cache_delete('sgbackup_backup_history', 'options');
		wp_cache_delete('sgbackup_backup_history', 'site-options');
		$history = SiteGuard_Backup_History::get_history_raw();
		$target_ts = null;

		// Try exact timestamp match first
		if ($timestamp && isset($history[$timestamp])) {
			$target_ts = $timestamp;
		}

		// Fall back to nonce match
		if (!$target_ts && !empty($nonce)) {
			foreach ($history as $ts => $entry) {
				if (isset($entry['nonce']) && $entry['nonce'] === $nonce) {
					$target_ts = $ts;
					break;
				}
			}
		}

		if (!$target_ts) {
			return new WP_Error('not_found', 'No matching backup found in history');
		}

		// Only sets this site RECEIVED may be edited by a peer. Without this, a
		// key-bundle holder could flip a local full-site backup into a "subsite
		// backup" with an attacker-chosen source URL and steer the next restore.
		$target_services = isset($history[$target_ts]['service']) ? (array) $history[$target_ts]['service'] : array();
		if (!in_array('remotesend', $target_services, true)) {
			return new WP_Error('not_remote', 'Backup set was not received from a remote site; metadata cannot be changed remotely.');
		}
		// ...and only by the peer (migration key) that sent it.
		if (!isset($history[$target_ts]['remote_key']) || !hash_equals((string) $history[$target_ts]['remote_key'], (string) $name_hash)) {
			return new WP_Error('not_owner', 'This backup set was received with a different migration key; its metadata cannot be changed with this one.');
		}

		$changed = false;
		if (!empty($data['label'])) {
			$history[$target_ts]['label'] = sanitize_text_field(substr($data['label'], 0, 40));
			$changed = true;
		}
		if (isset($data['always_keep'])) {
			$history[$target_ts]['always_keep'] = (bool) $data['always_keep'];
			$changed = true;
		}
		if (!empty($data['source_url'])) {
			$history[$target_ts]['source_url'] = esc_url_raw($data['source_url']);
			$changed = true;
		}
		if (!empty($data['is_subsite_backup'])) {
			$history[$target_ts]['is_subsite_backup'] = true;
			$history[$target_ts]['subsite_blog_id'] = isset($data['subsite_blog_id']) ? intval($data['subsite_blog_id']) : 0;
			$history[$target_ts]['subsite_domain'] = isset($data['subsite_domain']) ? sanitize_text_field($data['subsite_domain']) : '';
			$history[$target_ts]['subsite_path']   = isset($data['subsite_path']) ? sanitize_text_field($data['subsite_path']) : '/';
			$history[$target_ts]['subsite_url']    = isset($data['subsite_url']) ? esc_url_raw($data['subsite_url']) : '';
			$history[$target_ts]['subsite_home_url'] = isset($data['subsite_home_url']) ? esc_url_raw($data['subsite_home_url']) : '';
			$history[$target_ts]['subsite_scheme']   = isset($data['subsite_scheme']) && in_array($data['subsite_scheme'], array('http', 'https'), true) ? $data['subsite_scheme'] : '';
			// Plugin/theme state from the source subsite. Stored as plain arrays
			// of slug strings; cap length defensively to prevent a rogue sender
			// from bloating history. 500 entries covers the largest realistic
			// plugin list by an order of magnitude.
			$cap_list = function($in) {
				if (!is_array($in)) return array();
				$out = array();
				foreach ($in as $v) {
					if (!is_string($v)) continue;
					$v = sanitize_text_field($v);
					if ($v === '') continue;
					$out[] = $v;
					if (count($out) >= 500) break;
				}
				return $out;
			};
			$history[$target_ts]['subsite_active_plugins']           = isset($data['subsite_active_plugins']) ? $cap_list($data['subsite_active_plugins']) : array();
			$history[$target_ts]['subsite_active_sitewide_plugins']  = isset($data['subsite_active_sitewide_plugins']) ? $cap_list($data['subsite_active_sitewide_plugins']) : array();
			$history[$target_ts]['subsite_stylesheet']               = isset($data['subsite_stylesheet']) ? sanitize_text_field($data['subsite_stylesheet']) : '';
			$history[$target_ts]['subsite_template']                 = isset($data['subsite_template']) ? sanitize_text_field($data['subsite_template']) : '';
			$changed = true;
		}

		if ($changed) {
			SiteGuard_Backup_History::save_history($history);
		}

		return array('status' => 'ok', 'timestamp' => $target_ts);
		} finally {
			$lock->unlock();
		}
	}

	/**
	 * Does $path (a final backup file in the backup dir) belong to a set this
	 * site received from a remote peer?
	 *
	 * @return bool|null True: in a remotesend set. False: in a locally created
	 *                   set (must not be touched by a peer). Null: not in history.
	 */
	private function file_owned_by_remote_set($path, $name_hash = '') {
		$name = basename($path);
		$history = SiteGuard_Backup_History::get_history_raw();
		foreach ($history as $entry) {
			if (!is_array($entry)) continue;
			foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore', 'manifest', 'unknown') as $ent) {
				if (empty($entry[$ent])) continue;
				$files = is_array($entry[$ent]) ? $entry[$ent] : array($entry[$ent]);
				if (in_array($name, $files, true)) {
					$services = isset($entry['service']) ? (array) $entry['service'] : array();
					// Only the peer that sent the set may replace its files.
					return in_array('remotesend', $services, true)
						&& isset($entry['remote_key']) && '' !== (string) $name_hash
						&& hash_equals((string) $entry['remote_key'], (string) $name_hash);
				}
			}
		}
		return null;
	}

	/**
	 * Register a received file in backup history.
	 */
	private function register_received_file($filename, $name_hash = '') {
		// Parse filename: backup_{date}_{site}_{nonce}-{entity}.{ext}
		if (!preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_([^_]+)_([a-zA-Z0-9]+)/', $filename, $m)) {
			error_log("SiteGuard Backup: Failed to register received file (filename doesn't match pattern): {$filename}");
			return;
		}

		$date_str = $m[1]; // e.g., 2026-03-01-0300
		$nonce = $m[3];

		// Parse timestamp
		$timestamp = strtotime(
			substr($date_str, 0, 10) . ' ' .
			substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2) . ':00'
		);

		if (!$timestamp) $timestamp = time();

		// Determine entity type - match entity suffix after the nonce.
		// Format: backup_{date}_{site}_{nonce}-{entity}.ext or ...-{entity}-{N}.ext
		$entity = 'unknown';
		foreach (array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db') as $e) {
			if (preg_match('/-' . preg_quote($e, '/') . '[\.\-]/', $filename)) {
				$entity = $e;
				break;
			}
		}

		// Serialize against the semaphore-protected local history writers to
		// avoid a lost-update race. Same pattern as add_backup().
		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
			// Flush cache to ensure fresh data inside the locked section
			wp_cache_delete('sgbackup_backup_history', 'options');
			wp_cache_delete('sgbackup_backup_history', 'site-options');
			$history = SiteGuard_Backup_History::get_history_raw();

			// Merge only into the set this transfer belongs to (same nonce, received
			// remotely). A minute-granular timestamp collision with a LOCAL backup
			// must not splice a peer's archive into that trusted set - walk forward
			// to a free key instead.
			while (isset($history[$timestamp])) {
				$existing = $history[$timestamp];
				$existing_services = isset($existing['service']) ? (array) $existing['service'] : array();
				$same_nonce = isset($existing['nonce']) && $existing['nonce'] === $nonce;
				$same_key = isset($existing['remote_key']) && (string) $existing['remote_key'] === (string) $name_hash;
				if ($same_nonce && $same_key && in_array('remotesend', $existing_services, true)) break;
				$timestamp++;
			}
			if (!isset($history[$timestamp])) {
				$history[$timestamp] = array(
					'nonce'      => $nonce,
					'service'    => array('remotesend'),
					'remote_key' => (string) $name_hash,
				);
			}

			if (!isset($history[$timestamp][$entity])) {
				$history[$timestamp][$entity] = array();
			}

			if (is_string($history[$timestamp][$entity])) {
				$history[$timestamp][$entity] = array($history[$timestamp][$entity]);
			}

			if (!in_array($filename, $history[$timestamp][$entity])) {
				$history[$timestamp][$entity][] = $filename;
			}

			SiteGuard_Backup_History::save_history($history);
		} finally {
			$lock->unlock();
		}
	}
}
