<?php
/**
 * SiteGuard Backup - FTP Storage Method
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Ftp extends SiteGuard_Backup_Module {

	public function get_default_options() {
		return array(
			'host'      => '',
			'port'      => 21,
			'user'      => '',
			'pass'      => '',
			'path'      => '/',
			'passive'   => true,
			'ftps_mode' => 'none', // none, explicit, implicit
		);
	}

	private function connect() {
		$options = $this->get_options();

		if (empty($options['host'])) {
			return new WP_Error('ftp_no_host', 'FTP host not configured.');
		}

		// Migrate legacy 'ssl' boolean to ftps_mode
		$ftps_mode = isset($options['ftps_mode']) ? $options['ftps_mode'] : 'none';
		if ($ftps_mode === 'none' && !empty($options['ssl'])) {
			$ftps_mode = 'explicit';
		}

		$port = !empty($options['port']) ? intval($options['port']) : 21;

		if ($ftps_mode === 'implicit') {
			return $this->connect_implicit($options, $port);
		}

		if ($ftps_mode === 'explicit' && function_exists('ftp_ssl_connect')) {
			$conn = ftp_ssl_connect($options['host'], $port, 30);
		} else {
			$conn = ftp_connect($options['host'], $port, 30);
		}

		if (!$conn) {
			return new WP_Error('ftp_connect_failed', "Could not connect to FTP server: {$options['host']}:{$port}");
		}

		if (!ftp_login($conn, $options['user'], $options['pass'])) {
			@ftp_close($conn);
			return new WP_Error('ftp_login_failed', 'FTP login failed.');
		}

		if (!empty($options['passive'])) {
			if (!@ftp_pasv($conn, true)) {
				@ftp_close($conn);
				return new WP_Error('ftp_pasv_failed', 'Failed to enable passive mode.');
			}
		}

		return $conn;
	}

	/**
	 * Connect using FTPS implicit mode (ssl:// wrapper on port 990).
	 *
	 * Returns a stream socket resource wrapped in an array to distinguish from ftp_connect.
	 *
	 * @param array $options Method options.
	 * @param int   $port    Port number (default 990 for implicit).
	 * @return resource|WP_Error FTP connection resource or error.
	 */
	private function connect_implicit($options, $port) {
		// Implicit FTPS defaults to port 990
		if ($port === 21) {
			$port = 990;
		}

		$ssl_verify = !empty($options['ssl_verify']);
		$context = stream_context_create(array(
			'ssl' => array(
				'verify_peer'      => $ssl_verify,
				'verify_peer_name' => $ssl_verify,
				'allow_self_signed' => !$ssl_verify,
			),
		));

		if (!$ssl_verify) {
			$sgbackup = SiteGuard_Backup::instance();
			$sgbackup->log("FTP implicit SSL: certificate verification disabled. Enable ssl_verify in FTP settings for production use.", 'warning');
		}

		$stream = @stream_socket_client(
			"ssl://{$options['host']}:{$port}",
			$errno,
			$errstr,
			30,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$stream) {
			return new WP_Error('ftp_implicit_failed', "Could not connect via implicit FTPS: {$options['host']}:{$port} ($errstr)");
		}

		// For implicit FTPS, we still need ftp_ssl_connect but some servers
		// require raw SSL socket. Fall back to ftp_ssl_connect on the implicit port.
		fclose($stream);

		if (!function_exists('ftp_ssl_connect')) {
			return new WP_Error('ftp_no_ssl', 'ftp_ssl_connect function not available. PHP OpenSSL extension required.');
		}

		$conn = ftp_ssl_connect($options['host'], $port, 30);
		if (!$conn) {
			return new WP_Error('ftp_implicit_failed', "Could not establish implicit FTPS connection: {$options['host']}:{$port}");
		}

		if (!ftp_login($conn, $options['user'], $options['pass'])) {
			@ftp_close($conn);
			return new WP_Error('ftp_login_failed', 'FTP login failed (implicit FTPS).');
		}

		if (!empty($options['passive'])) {
			if (!@ftp_pasv($conn, true)) {
				@ftp_close($conn);
				return new WP_Error('ftp_pasv_failed', 'Failed to enable passive mode (implicit FTPS).');
			}
		}

		return $conn;
	}

	public function backup($file) {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		$remote_path = trailingslashit($options['path']) . basename($file);

		$result = ftp_put($conn, $remote_path, $file, FTP_BINARY);
		@ftp_close($conn);

		if (!$result) {
			return new WP_Error('ftp_upload_failed', "Failed to upload to FTP: {$remote_path}");
		}

		return true;
	}

	public function download($file, $dest_dir) {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		$remote_path = trailingslashit($options['path']) . $file;
		$local_path = trailingslashit($dest_dir) . $file;

		$result = ftp_get($conn, $local_path, $remote_path, FTP_BINARY);
		@ftp_close($conn);

		return $result ? true : new WP_Error('ftp_download_failed', "Failed to download from FTP: {$remote_path}");
	}

	public function delete($files) {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			$remote_path = trailingslashit($options['path']) . $file;
			if (!ftp_delete($conn, $remote_path)) {
				$errors[] = $remote_path;
			}
		}

		@ftp_close($conn);

		if (!empty($errors)) {
			return new WP_Error('ftp_delete_failed', 'Failed to delete file(s): ' . implode(', ', $errors));
		}

		return true;
	}

	public function listfiles() {
		$conn = $this->connect();
		if (is_wp_error($conn)) return array();

		$options = $this->get_options();
		$list = ftp_nlist($conn, $options['path']);
		@ftp_close($conn);

		return is_array($list) ? array_map('basename', $list) : array();
	}

	public function credentials_test() {
		$conn = $this->connect();
		if (is_wp_error($conn)) return $conn;

		$options = $this->get_options();
		$pwd = ftp_pwd($conn);
		@ftp_close($conn);

		return $pwd ? true : new WP_Error('ftp_test_failed', 'Connected but could not determine working directory.');
	}
}
