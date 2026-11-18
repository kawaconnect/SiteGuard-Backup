<?php
/**
 * SiteGuard Backup - SFTP Storage Method
 *
 * Uses PHP ssh2 extension or phpseclib.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Sftp extends SiteGuard_Backup_Module {

	public function get_default_options() {
		return array(
			'host'        => '',
			'port'        => 22,
			'user'        => '',
			'pass'        => '',
			'key'         => '',
			'path'        => '/',
			'protocol'    => 'sftp', // sftp or scp
			'fingerprint' => '',     // Server host key fingerprint for verification
		);
	}

	private function connect() {
		$options = $this->get_options();

		if (empty($options['host'])) {
			return new WP_Error('sftp_no_host', 'SFTP host not configured.');
		}

		$port = !empty($options['port']) ? intval($options['port']) : 22;

		// A pasted private key (what the settings page asks for) is loaded by
		// phpseclib from memory; the ssh2 extension only takes key files.
		$pasted_key = $this->is_pasted_key($options);

		// Try native ssh2 extension
		if (function_exists('ssh2_connect') && !$pasted_key) {
			$conn = $this->connect_ssh2($options, $port);
		}
		// Try phpseclib
		elseif (file_exists(SGBACKUP_DIR . 'vendor/phpseclib/Net/SFTP.php')) {
			$conn = $this->connect_phpseclib($options, $port);
		} else {
			return new WP_Error('sftp_no_lib', 'Neither ssh2 extension nor phpseclib available.');
		}

		// The host key is verified inside connect_ssh2()/connect_phpseclib(),
		// before authentication.
		return $conn;
	}

	/**
	 * Verify the server host key fingerprint.
	 *
	 * If a stored fingerprint exists and does not match, returns WP_Error.
	 * If no stored fingerprint, stores it on first connection (TOFU).
	 *
	 * @param array $conn    Connection array from connect_ssh2/connect_phpseclib.
	 * @param array $options Method options.
	 * @return true|WP_Error
	 */
	private function verify_fingerprint($conn, $options) {
		$server_fingerprint = '';

		if ($conn['type'] === 'ssh2' && isset($conn['conn'])) {
			$fp = @ssh2_fingerprint($conn['conn'], SSH2_FINGERPRINT_MD5 | SSH2_FINGERPRINT_HEX);
			if ($fp) {
				$server_fingerprint = strtolower($fp);
			}
		} elseif ($conn['type'] === 'phpseclib' && isset($conn['sftp'])) {
			$sftp_obj = $conn['sftp'];
			if (method_exists($sftp_obj, 'getServerPublicHostKey')) {
				$hostkey = $sftp_obj->getServerPublicHostKey();
				if ($hostkey) {
					// Extract raw key bytes to match ssh2_fingerprint behavior
					// phpseclib returns "ssh-rsa AAAA..." format
					$parts = explode(' ', $hostkey, 3);
					if (isset($parts[1])) {
						$raw_key = base64_decode($parts[1]);
						$server_fingerprint = strtolower(md5($raw_key, false));
					} else {
						$server_fingerprint = strtolower(md5($hostkey));
					}
				}
			}
		}

		if (empty($server_fingerprint)) {
			// Fail closed: without the host key we cannot tell the real server from
			// an impostor, and the next step would hand it our credentials.
			return new WP_Error('sftp_no_fingerprint', 'SFTP: could not read the server host key; refusing to send credentials to an unverified server.');
		}

		$stored_fingerprint = isset($options['fingerprint']) ? strtolower(trim($options['fingerprint'])) : '';

		if (!empty($stored_fingerprint)) {
			// Compare stored vs actual
			$normalized_stored = str_replace(':', '', $stored_fingerprint);
			$normalized_server = str_replace(':', '', $server_fingerprint);
			if ($normalized_stored !== $normalized_server) {
				return new WP_Error(
					'sftp_fingerprint_mismatch',
					sprintf(
						'Host key fingerprint mismatch. Expected: %s, Got: %s. This could indicate a man-in-the-middle attack.',
						$stored_fingerprint,
						$server_fingerprint
					)
				);
			}
		} else {
			// TOFU: Store fingerprint on first connection
			$options['fingerprint'] = $server_fingerprint;
			$this->save_options($options);
		}

		return true;
	}

	/** Is the "key" option the key itself rather than a path to a key file? */
	private function is_pasted_key($options) {
		$k = isset($options['key']) ? (string) $options['key'] : '';
		return '' !== $k && (false !== strpos($k, '-----BEGIN') || false !== strpos($k, 'PuTTY-User-Key-File'));
	}

	private function connect_ssh2($options, $port) {
		$conn = @ssh2_connect($options['host'], $port);
		if (!$conn) {
			return new WP_Error('sftp_connect_failed', "Could not connect to SFTP: {$options['host']}:{$port}");
		}

		// Verify the host key BEFORE authenticating: checking afterwards had already
		// sent the password or key to whoever answered.
		$verified = $this->verify_fingerprint(array('type' => 'ssh2', 'conn' => $conn), $options);
		if (is_wp_error($verified)) {
			return $verified;
		}

		// Key auth
		if (!empty($options['key'])) {
			$key_file = $options['key'];

			// Validate key path: must be within ABSPATH or wp-content, no '..' traversal
			$real_key = realpath($key_file);
			if (false === $real_key
				|| strpos($key_file, '..') !== false
				|| (strpos($real_key, rtrim(ABSPATH, '/') . '/') !== 0 && strpos($real_key, rtrim(WP_CONTENT_DIR, '/') . '/') !== 0)
			) {
				return new WP_Error('sftp_invalid_key', 'SFTP key path must be within ABSPATH or wp-content and must not contain path traversal.');
			}

			$pubkey = $key_file . '.pub';
			if (!@ssh2_auth_pubkey_file($conn, $options['user'], $pubkey, $key_file, $options['pass'])) {
				return new WP_Error('sftp_auth_failed', 'SFTP key authentication failed.');
			}
		} else {
			if (!@ssh2_auth_password($conn, $options['user'], $options['pass'])) {
				return new WP_Error('sftp_auth_failed', 'SFTP password authentication failed.');
			}
		}

		$sftp = @ssh2_sftp($conn);
		if (!$sftp) {
			return new WP_Error('sftp_subsystem_failed', 'Could not initialize SFTP subsystem.');
		}

		return array('type' => 'ssh2', 'conn' => $conn, 'sftp' => $sftp);
	}

	private function connect_phpseclib($options, $port) {
		// phpseclib 2 needs an autoloader (SFTP extends SSH2, uses Math, Crypt, ...).
		require_once SGBACKUP_DIR . 'vendor/phpseclib/autoload.php';

		if (class_exists('phpseclib\\Net\\SFTP')) {
			$sftp = new \phpseclib\Net\SFTP($options['host'], $port);
		} else {
			$sftp = new Net_SFTP($options['host'], $port);
		}

		// Key exchange happens here (getServerPublicHostKey connects); verify the
		// host key before login() sends any credentials.
		$verified = $this->verify_fingerprint(array('type' => 'phpseclib', 'sftp' => $sftp), $options);
		if (is_wp_error($verified)) {
			return $verified;
		}

		$auth = false;
		if (!empty($options['key'])) {
			if ($this->is_pasted_key($options)) {
				$key_data = (string) $options['key'];
			} else {
				$key_file = $options['key'];

				// Validate key path: must be within ABSPATH or wp-content, no '..' traversal
				$real_key = realpath($key_file);
				if (false === $real_key
					|| strpos($key_file, '..') !== false
					|| (strpos($real_key, rtrim(ABSPATH, '/') . '/') !== 0 && strpos($real_key, rtrim(WP_CONTENT_DIR, '/') . '/') !== 0)
				) {
					return new WP_Error('sftp_invalid_key', 'SFTP key: paste the private key itself, or give a key file path within ABSPATH or wp-content (no path traversal).');
				}
				$key_data = (string) file_get_contents($real_key);
			}

			$key = new \phpseclib\Crypt\RSA();
			if (!empty($options['pass'])) $key->setPassword($options['pass']);
			if (!$key->loadKey($key_data)) {
				return new WP_Error('sftp_invalid_key', 'SFTP key could not be read. The bundled SSH library supports RSA keys (PEM, PKCS#8 or OpenSSH format), not Ed25519 or ECDSA keys; for an encrypted key, enter its passphrase as the password.');
			}
			$auth = $sftp->login($options['user'], $key);
		} else {
			$auth = $sftp->login($options['user'], $options['pass']);
		}

		if (!$auth) {
			return new WP_Error('sftp_auth_failed', 'SFTP authentication failed.');
		}

		return array('type' => 'phpseclib', 'sftp' => $sftp);
	}

	public function backup($file) {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		$remote_path = trailingslashit($options['path']) . basename($file);
		$protocol = isset($options['protocol']) ? $options['protocol'] : 'sftp';

		// SCP protocol mode
		if ($protocol === 'scp') {
			return $this->scp_upload($conn, $file, $remote_path);
		}

		if ($conn['type'] === 'ssh2') {
			$sftp_stream = "ssh2.sftp://{$conn['sftp']}{$remote_path}";
			$result = @copy($file, $sftp_stream);
		} else {
			$result = $conn['sftp']->put($remote_path, $file, 1); // 1 = NET_SFTP_LOCAL_FILE
		}

		return $result ? true : new WP_Error('sftp_upload_failed', "Failed to upload via SFTP: {$remote_path}");
	}

	/**
	 * Upload a file using SCP protocol.
	 *
	 * @param array  $conn        Connection array.
	 * @param string $file        Local file path.
	 * @param string $remote_path Remote destination path.
	 * @return bool|WP_Error
	 */
	private function scp_upload($conn, $file, $remote_path) {
		if ($conn['type'] === 'ssh2') {
			$result = @ssh2_scp_send($conn['conn'], $file, $remote_path);
			return $result ? true : new WP_Error('scp_upload_failed', "Failed to upload via SCP: {$remote_path}");
		}

		// phpseclib SCP
		if (!class_exists('phpseclib\\Net\\SCP') && !class_exists('Net_SCP')) {
			$scp_file = SGBACKUP_DIR . 'vendor/phpseclib/Net/SCP.php';
			if (file_exists($scp_file)) {
				require_once $scp_file;
			} else {
				return new WP_Error('scp_no_lib', 'SCP library not available.');
			}
		}

		if (class_exists('phpseclib\\Net\\SCP')) {
			$scp = new \phpseclib\Net\SCP($conn['sftp']);
		} elseif (class_exists('Net_SCP')) {
			$scp = new Net_SCP($conn['sftp']);
		} else {
			return new WP_Error('scp_no_lib', 'SCP class not available.');
		}

		$result = $scp->put($remote_path, $file, 1); // 1 = NET_SCP_LOCAL_FILE
		return $result ? true : new WP_Error('scp_upload_failed', "Failed to upload via SCP: {$remote_path}");
	}

	public function download($file, $dest_dir) {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		$remote_path = trailingslashit($options['path']) . $file;
		$local_path = trailingslashit($dest_dir) . $file;
		$protocol = isset($options['protocol']) ? $options['protocol'] : 'sftp';

		// SCP protocol mode
		if ($protocol === 'scp') {
			return $this->scp_download($conn, $remote_path, $local_path);
		}

		if ($conn['type'] === 'ssh2') {
			$sftp_stream = "ssh2.sftp://{$conn['sftp']}{$remote_path}";
			$result = @copy($sftp_stream, $local_path);
		} else {
			$result = $conn['sftp']->get($remote_path, $local_path);
		}

		if (!$result) {
			@unlink($local_path); // never leave a truncated archive that a restore could pick up
			return new WP_Error('sftp_download_failed', "Failed to download via SFTP: {$remote_path}");
		}
		return true;
	}

	/**
	 * Download a file using SCP protocol.
	 *
	 * @param array  $conn        Connection array.
	 * @param string $remote_path Remote file path.
	 * @param string $local_path  Local destination path.
	 * @return bool|WP_Error
	 */
	private function scp_download($conn, $remote_path, $local_path) {
		if ($conn['type'] === 'ssh2') {
			$result = @ssh2_scp_recv($conn['conn'], $remote_path, $local_path);
			return $result ? true : new WP_Error('scp_download_failed', "Failed to download via SCP: {$remote_path}");
		}

		// phpseclib SCP
		if (!class_exists('phpseclib\\Net\\SCP') && !class_exists('Net_SCP')) {
			$scp_file = SGBACKUP_DIR . 'vendor/phpseclib/Net/SCP.php';
			if (file_exists($scp_file)) {
				require_once $scp_file;
			} else {
				return new WP_Error('scp_no_lib', 'SCP library not available.');
			}
		}

		if (class_exists('phpseclib\\Net\\SCP')) {
			$scp = new \phpseclib\Net\SCP($conn['sftp']);
		} elseif (class_exists('Net_SCP')) {
			$scp = new Net_SCP($conn['sftp']);
		} else {
			return new WP_Error('scp_no_lib', 'SCP class not available.');
		}

		// Pass the local path so Net_SCP::get() streams to disk instead of
		// returning the whole file as a string (avoids OOM on large archives).
		$result = $scp->get($remote_path, $local_path);
		if (false === $result) {
			return new WP_Error('scp_download_failed', "Failed to download via SCP: {$remote_path}");
		}

		return true;
	}

	public function delete($files) {
		$files = $this->safe_remote_names($files);
		if (!$files) return true;
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			$remote_path = trailingslashit($options['path']) . $file;
			if ($conn['type'] === 'ssh2') {
				if (!@ssh2_sftp_unlink($conn['sftp'], $remote_path)) {
					$errors[] = $remote_path;
				}
			} else {
				if (!$conn['sftp']->delete($remote_path)) {
					$errors[] = $remote_path;
				}
			}
		}

		if (!empty($errors)) {
			return new WP_Error('sftp_delete_failed', 'Failed to delete file(s): ' . implode(', ', $errors));
		}

		return true;
	}

	public function listfiles() {
		$conn = $this->connect();
		if (is_wp_error($conn)) return array();

		$options = $this->get_options();
		$path = isset($options['path']) ? $options['path'] : '/';

		$files = array();
		if ($conn['type'] === 'ssh2') {
			$handle = @opendir("ssh2.sftp://{$conn['sftp']}{$path}");
			if ($handle) {
				while (false !== ($entry = readdir($handle))) {
					if ($entry === '.' || $entry === '..') continue;
					$files[] = $entry;
				}
				closedir($handle);
			}
		} else {
			$list = $conn['sftp']->rawlist($path);
			if (is_array($list)) {
				foreach ($list as $name => $attrs) {
					if ($name === '.' || $name === '..') continue;
					$files[] = $name;
				}
			}
		}

		return $files;
	}

	public function credentials_test() {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		return true;
	}
}
