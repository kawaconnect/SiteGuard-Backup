<?php
/**
 * SiteGuard Backup - FTP / FTPS Storage Method
 *
 * Transfers go through PHP's curl extension (libcurl) when it supports FTP,
 * which it does on practically every host:
 *   - explicit FTPS (AUTH TLS) and implicit FTPS (ftps://, port 990),
 *   - TLS session reuse on the data connection, which servers such as vsftpd,
 *     pure-ftpd and FileZilla Server require (PHP's ext-ftp fails there with
 *     "data_accept: failed to retrieve the existing SSL session"),
 *   - server certificate verification with WordPress' CA bundle (ext-ftp never
 *     verified the certificate at all).
 * PHP's ftp extension is only a fallback for plain FTP / explicit FTPS when
 * curl has no FTP support.
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

	/** none | explicit | implicit (migrates the legacy 'ssl' boolean). */
	private function ftps_mode($options) {
		$mode = isset($options['ftps_mode']) ? $options['ftps_mode'] : 'none';
		if ('none' === $mode && !empty($options['ssl'])) $mode = 'explicit';
		return in_array($mode, array('none', 'explicit', 'implicit'), true) ? $mode : 'none';
	}

	private function port($options) {
		if (!empty($options['port'])) return (int) $options['port'];
		return 'implicit' === $this->ftps_mode($options) ? 990 : 21;
	}

	/** Can libcurl do this transfer (FTP, plus FTPS when encryption is asked for)? */
	private function curl_ok($options) {
		if (!function_exists('curl_init') || !function_exists('curl_version')) return false;
		$v = curl_version();
		$protocols = isset($v['protocols']) ? (array) $v['protocols'] : array();
		if (!in_array('ftp', $protocols, true)) return false;
		if ('none' !== $this->ftps_mode($options) && !in_array('ftps', $protocols, true)) return false;
		/** Filter: false forces PHP's ftp extension (tests, or a broken libcurl). */
		return (bool) apply_filters('sgbackup_ftp_use_curl', true, $options);
	}

	private function check_config($options) {
		if (empty($options['host'])) {
			return new WP_Error('ftp_no_host', 'FTP host not configured.');
		}
		if ($this->curl_ok($options)) return true;
		if ('implicit' === $this->ftps_mode($options)) {
			return new WP_Error('ftp_implicit_unsupported', "Implicit FTPS needs PHP's curl extension with FTPS support, which this server lacks. Use explicit FTPS or SFTP instead.");
		}
		// Many PHP builds (the official Docker images among them) ship without the
		// ftp extension; calling ftp_connect() there was a fatal error mid-backup.
		if (!function_exists('ftp_connect')) {
			return new WP_Error('ftp_unavailable', "This server's PHP has neither curl with FTP support nor the FTP extension (ext-ftp), so FTP/FTPS storage cannot be used here. Ask the host to enable one, or use SFTP instead.");
		}
		return true;
	}

	/** Remote directory as configured ('' = the login directory). */
	private function remote_dir($options) {
		$path = isset($options['path']) ? trim((string) $options['path']) : '/';
		return '' === $path ? '/' : $path;
	}

	// ------------------------------------------------------------------ curl ---

	/**
	 * URL for a directory (trailing slash) or a file in it. An absolute
	 * configured path is anchored with %2F, otherwise curl would treat it as
	 * relative to the login directory (ext-ftp passed it to the server as is).
	 */
	private function curl_url($options, $file = '') {
		$scheme = 'implicit' === $this->ftps_mode($options) ? 'ftps' : 'ftp';
		$host = (string) $options['host'];
		if (false !== strpos($host, ':') && '[' !== $host[0]) $host = '[' . $host . ']'; // IPv6
		$dir = $this->remote_dir($options);
		$segments = array_values(array_filter(explode('/', $dir), 'strlen'));
		$path = ('/' === $dir[0] ? '%2F' : '') . implode('/', array_map('rawurlencode', $segments));
		$path = rtrim($path, '/');
		$url = $scheme . '://' . $host . ':' . $this->port($options) . '/' . ('' !== $path ? $path . '/' : '');
		return $url . ('' !== $file ? rawurlencode($file) : '');
	}

	private function curl_handle($options, $url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_USERNAME, (string) $options['user']);
		curl_setopt($ch, CURLOPT_PASSWORD, (string) $options['pass']);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		// Abort a stalled transfer instead of hanging the job (no total time limit:
		// large archives on slow links are fine as long as bytes keep moving).
		curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
		curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 120);
		if (empty($options['passive'])) {
			curl_setopt($ch, CURLOPT_FTPPORT, '-');
		}
		if ('explicit' === $this->ftps_mode($options)) {
			// Encrypt control and data channels; never fall back to plaintext.
			curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
		}
		if ('none' !== $this->ftps_mode($options)) {
			// Same CA bundle and verification switch as the plugin's HTTP requests
			// (WordPress' bundle, the http_request_args filter, and SiteGuard's
			// "disable SSL verification" setting).
			$args = array('sslverify' => true, 'sslcertificates' => ABSPATH . WPINC . '/certificates/ca-bundle.crt');
			if (class_exists('SiteGuard_Backup')) $args = array_merge($args, SiteGuard_Backup::instance()->get_ssl_options());
			$args = apply_filters('http_request_args', $args, str_replace('ftp', 'https', strtok($url, ':')) . '://' . $options['host'] . '/');
			if (empty($args['sslverify'])) {
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
			} else {
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
				if (!empty($args['sslcertificates']) && is_readable($args['sslcertificates'])) {
					curl_setopt($ch, CURLOPT_CAINFO, $args['sslcertificates']);
				}
			}
		}
		return $ch;
	}

	private function curl_error($ch, $what) {
		$err = curl_error($ch);
		$code = curl_errno($ch);
		$resp = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		return new WP_Error('ftp_failed', sprintf('FTP %s failed: %s (curl %d%s)', $what, $err, $code, $resp ? ", server reply {$resp}" : ''));
	}

	/**
	 * Directory listing as [name => is_file]. MLSD tells files from folders;
	 * servers without it fall back to NLST (names only, folders included).
	 */
	private function curl_list($options) {
		$url = $this->curl_url($options);
		$ch = $this->curl_handle($options, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'MLSD');
		$out = curl_exec($ch);
		$entries = array();
		if (false !== $out && 0 === curl_errno($ch)) {
			foreach (preg_split('/\r?\n/', (string) $out) as $line) {
				if ('' === trim($line) || false === strpos($line, ' ')) continue;
				list($facts, $name) = explode(' ', $line, 2);
				if (!preg_match('/(?:^|;)type=([^;]*)/i', $facts, $m)) continue;
				$type = strtolower($m[1]);
				if ('cdir' === $type || 'pdir' === $type) continue;
				$entries[$name] = ('file' === $type);
			}
			curl_close($ch);
			return $entries;
		}
		curl_close($ch);

		$ch = $this->curl_handle($options, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
		$out = curl_exec($ch);
		if (false === $out || 0 !== curl_errno($ch)) {
			$e = $this->curl_error($ch, 'listing');
			curl_close($ch);
			return $e;
		}
		curl_close($ch);
		foreach (preg_split('/\r?\n/', (string) $out) as $name) {
			$name = basename(trim($name));
			if ('' === $name || '.' === $name || '..' === $name) continue;
			$entries[$name] = true;
		}
		return $entries;
	}

	// --------------------------------------------------------------- ext-ftp ---

	private function ext_connect($options) {
		$port = $this->port($options);
		if ('explicit' === $this->ftps_mode($options)) {
			if (!function_exists('ftp_ssl_connect')) {
				// Never fall back to plaintext when encryption was asked for.
				return new WP_Error('ftps_unavailable', 'FTPS was selected but this PHP build has no ftp_ssl_connect(); refusing to connect without encryption.');
			}
			$conn = ftp_ssl_connect($options['host'], $port, 30);
		} else {
			$conn = ftp_connect($options['host'], $port, 30);
		}
		if (!$conn) {
			return new WP_Error('ftp_connect_failed', "Could not connect to FTP server: {$options['host']}:{$port}");
		}
		if (!@ftp_login($conn, $options['user'], $options['pass'])) {
			@ftp_close($conn);
			return new WP_Error('ftp_login_failed', 'FTP login failed.');
		}
		if (!empty($options['passive']) && !@ftp_pasv($conn, true)) {
			@ftp_close($conn);
			return new WP_Error('ftp_pasv_failed', 'Failed to enable passive mode.');
		}
		return $conn;
	}

	private function ext_path($options, $file) {
		return trailingslashit($this->remote_dir($options)) . $file;
	}

	// ------------------------------------------------------------ operations ---

	public function backup($file) {
		$options = $this->get_options();
		$ok = $this->check_config($options);
		if (is_wp_error($ok)) return $ok;
		$name = basename($file);

		if ($this->curl_ok($options)) {
			$fh = fopen($file, 'rb');
			if (!$fh) return new WP_Error('ftp_read', "Could not open {$file}");
			$ch = $this->curl_handle($options, $this->curl_url($options, $name));
			curl_setopt($ch, CURLOPT_UPLOAD, true);
			curl_setopt($ch, CURLOPT_INFILE, $fh);
			curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file));
			$res = curl_exec($ch);
			$err = (false === $res || 0 !== curl_errno($ch)) ? $this->curl_error($ch, "upload of {$name}") : null;
			curl_close($ch);
			fclose($fh);
			return $err ? $err : true;
		}

		$conn = $this->ext_connect($options);
		if (is_wp_error($conn)) return $conn;
		$remote_path = $this->ext_path($options, $name);
		$result = @ftp_put($conn, $remote_path, $file, FTP_BINARY);
		@ftp_close($conn);
		return $result ? true : new WP_Error('ftp_upload_failed', "Failed to upload to FTP: {$remote_path}");
	}

	public function download($file, $dest_dir) {
		$options = $this->get_options();
		$ok = $this->check_config($options);
		if (is_wp_error($ok)) return $ok;
		$local_path = $this->local_download_path($dest_dir, $file);

		if ($this->curl_ok($options)) {
			$fh = fopen($local_path, 'wb');
			if (!$fh) return new WP_Error('ftp_write', "Could not write {$local_path}");
			$ch = $this->curl_handle($options, $this->curl_url($options, basename($file)));
			curl_setopt($ch, CURLOPT_FILE, $fh);
			$res = curl_exec($ch);
			$err = (false === $res || 0 !== curl_errno($ch)) ? $this->curl_error($ch, 'download of ' . basename($file)) : null;
			$expected = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
			curl_close($ch);
			fclose($fh);
			if ($err) {
				@unlink($local_path); // never leave a truncated archive that a restore could pick up
				return $err;
			}
			return $this->verify_download($local_path, $expected >= 0 ? (int) $expected : null, 'FTP');
		}

		$conn = $this->ext_connect($options);
		if (is_wp_error($conn)) return $conn;
		$remote_path = $this->ext_path($options, $file);
		$result = @ftp_get($conn, $local_path, $remote_path, FTP_BINARY);
		@ftp_close($conn);
		if (!$result) {
			@unlink($local_path); // never leave a truncated archive that a restore could pick up
			return new WP_Error('ftp_download_failed', "Failed to download from FTP: {$remote_path}");
		}
		return true;
	}

	public function delete($files) {
		$files = $this->safe_remote_names($files);
		if (!$files) return true;
		$options = $this->get_options();
		$ok = $this->check_config($options);
		if (is_wp_error($ok)) return $ok;
		$errors = array();

		if ($this->curl_ok($options)) {
			foreach ($files as $file) {
				// QUOTE commands run right after login, before curl changes directory,
				// so DELE gets the full path (as the configured path is meant).
				$ch = $this->curl_handle($options, $this->curl_url($options));
				curl_setopt($ch, CURLOPT_NOBODY, true);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_QUOTE, array('DELE ' . $this->ext_path($options, $file)));
				$res = curl_exec($ch);
				if (false === $res || 0 !== curl_errno($ch)) {
					$errors[] = $file . ': ' . $this->curl_error($ch, 'delete')->get_error_message();
				}
				curl_close($ch);
			}
		} else {
			$conn = $this->ext_connect($options);
			if (is_wp_error($conn)) return $conn;
			foreach ($files as $file) {
				$remote_path = $this->ext_path($options, $file);
				if (!@ftp_delete($conn, $remote_path)) $errors[] = $remote_path;
			}
			@ftp_close($conn);
		}

		if (!empty($errors)) {
			return new WP_Error('ftp_delete_failed', 'Failed to delete file(s): ' . implode('; ', $errors));
		}
		return true;
	}

	public function listfiles() {
		$options = $this->get_options();
		if (is_wp_error($this->check_config($options))) return array();

		if ($this->curl_ok($options)) {
			$entries = $this->curl_list($options);
			if (is_wp_error($entries)) return array();
			return array_keys(array_filter($entries));
		}

		$conn = $this->ext_connect($options);
		if (is_wp_error($conn)) return array();
		$list = @ftp_nlist($conn, $this->remote_dir($options));
		@ftp_close($conn);
		if (!is_array($list)) return array();
		// Servers list "." and ".." too; they are not backups.
		return array_values(array_filter(array_map('basename', $list), function ($n) {
			return '' !== $n && '.' !== $n && '..' !== $n;
		}));
	}

	public function credentials_test() {
		$options = $this->get_options();
		$ok = $this->check_config($options);
		if (is_wp_error($ok)) return $ok;

		if ($this->curl_ok($options)) {
			$entries = $this->curl_list($options);
			if (is_wp_error($entries)) return $entries;
		} else {
			$conn = $this->ext_connect($options);
			if (is_wp_error($conn)) return $conn;
			$pwd = @ftp_pwd($conn);
			@ftp_close($conn);
			if (!$pwd) return new WP_Error('ftp_test_failed', 'Connected but could not determine working directory.');
		}

		if ('none' === $this->ftps_mode($options)) {
			return array('success' => true, 'message' => 'Connected. Warning: plain FTP sends the login and the backups in cleartext; use FTPS or SFTP unless this is a private network.');
		}
		return true;
	}
}
