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
			// ftp_ssl_connect() only supports explicit FTPS (AUTH TLS after a
			// plaintext handshake); attempting it against an implicit-TLS port
			// hangs until timeout, so fail fast with a clear message instead.
			return new WP_Error(
				'ftp_implicit_unsupported',
				"Implicit FTPS (port 990) is not supported by PHP's FTP extension; use explicit FTPS on port 21 or SFTP instead."
			);
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
