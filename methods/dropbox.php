<?php
/**
 * SiteGuard Backup - Dropbox Storage Method
 *
 * OAuth2 flow with chunked upload sessions.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Dropbox extends SiteGuard_Backup_Module {

	private $auth_url = 'https://www.dropbox.com/oauth2/authorize';
	private $token_url = 'https://api.dropboxapi.com/oauth2/token';
	private $api_url = 'https://api.dropboxapi.com/2';
	private $content_url = 'https://content.dropboxapi.com/2';

	public function get_default_options() {
		return array(
			'app_key'    => '',
			'app_secret' => '',
			'token'      => array(),
			'folder'     => '/SiteGuard Backups',
		);
	}

	/**
	 * Get OAuth2 authorization URL.
	 */
	public function get_auth_url() {
		$options = $this->get_options();
		$redirect = admin_url('admin-ajax.php') . '?action=sgbackup_ajax&subaction=dropbox_auth_callback';

		return $this->auth_url . '?' . http_build_query(array(
			'client_id'     => $options['app_key'],
			'redirect_uri'  => $redirect,
			'response_type' => 'code',
			'token_access_type' => 'offline',
			'state'         => wp_create_nonce('sgbackup-oauth-dropbox'),
		));
	}

	/**
	 * Exchange authorization code for tokens.
	 */
	public function exchange_code($code) {
		$options = $this->get_options();
		$redirect = admin_url('admin-ajax.php') . '?action=sgbackup_ajax&subaction=dropbox_auth_callback';

		$response = wp_remote_post($this->token_url, array(
			'body' => array(
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $redirect,
				'client_id'     => $options['app_key'],
				'client_secret' => $options['app_secret'],
			),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (isset($body['error'])) {
			$err_msg = isset($body['error_summary']) ? $body['error_summary'] : (is_string($body['error']) ? $body['error'] : 'Unknown error');
			return new WP_Error('dropbox_auth', $err_msg);
		}

		$options['token'] = $body;
		$options['token']['obtained_at'] = time();
		$this->save_options($options);

		return true;
	}

	/**
	 * Get a valid access token, refreshing if needed.
	 */
	private function get_access_token() {
		$options = $this->get_options();
		$token = $options['token'];

		if (empty($token['access_token'])) {
			return new WP_Error('dropbox_no_token', 'Not authenticated with Dropbox.');
		}

		// Check expiry
		$obtained = isset($token['obtained_at']) ? $token['obtained_at'] : 0;
		$expires_in = isset($token['expires_in']) ? $token['expires_in'] : 14400;

		if (time() > ($obtained + $expires_in - 600)) {
			if (empty($token['refresh_token'])) {
				return new WP_Error('dropbox_no_refresh', 'No refresh token. Re-authenticate.');
			}

			$response = wp_remote_post($this->token_url, array(
				'body' => array(
					'refresh_token' => $token['refresh_token'],
					'grant_type'    => 'refresh_token',
					'client_id'     => $options['app_key'],
					'client_secret' => $options['app_secret'],
				),
			));

			if (is_wp_error($response)) return $response;

			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (isset($body['error'])) {
				return new WP_Error('dropbox_refresh', $body['error_summary'] ?? $body['error']);
			}
			if (!is_array($body) || empty($body['access_token']) || !is_string($body['access_token'])) {
				// A 5xx/HTML/proxy response used to be written back as access_token=null,
				// after which every call failed "Not authenticated" before ever retrying
				// the still-valid refresh token. Leave the stored token untouched.
				return new WP_Error('dropbox_refresh', 'Token refresh returned an unexpected response (HTTP ' . wp_remote_retrieve_response_code($response) . '); will retry later.');
			}

			$options['token']['access_token'] = $body['access_token'];
			$options['token']['expires_in'] = $body['expires_in'];
			$options['token']['obtained_at'] = time();
			// Dropbox may rotate refresh tokens - save new one if provided
			if (!empty($body['refresh_token'])) {
				$options['token']['refresh_token'] = $body['refresh_token'];
			}
			$this->save_options($options);

			return $body['access_token'];
		}

		return $token['access_token'];
	}

	public function backup($file) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$options = $this->get_options();
		$folder = rtrim($options['folder'] ?: '/SiteGuard Backups', '/');
		$remote_path = $folder . '/' . basename($file);
		$filesize = filesize($file);

		// Files under 50MB: simple upload (reads into memory)
		if ($filesize < 52428800) {
			$data = file_get_contents($file);

			$response = wp_remote_post($this->content_url . '/files/upload', array(
				'headers' => array(
					'Authorization'   => "Bearer {$token}",
					'Content-Type'    => 'application/octet-stream',
					'Dropbox-API-Arg' => json_encode(array(
						'path'       => $remote_path,
						'mode'       => 'overwrite',
						'autorename' => false,
					)),
				),
				'body'    => $data,
				'timeout' => 300,
			));

			if (is_wp_error($response)) return $response;

			$code = wp_remote_retrieve_response_code($response);
			if ($code !== 200) {
				return new WP_Error('dropbox_upload', 'Upload failed (HTTP ' . $code . ')');
			}

			return true;
		}

		// Large files: upload session
		return $this->session_upload($file, $remote_path, $token);
	}

	private function session_upload($file, $remote_path, $token) {
		$chunk_size = 4194304; // 4MB
		$fh = fopen($file, 'rb');
		$filesize = filesize($file);
		$offset = 0;

		// Start session
		$chunk = fread($fh, $chunk_size);
		$response = wp_remote_post($this->content_url . '/files/upload_session/start', array(
			'headers' => array(
				'Authorization'   => "Bearer {$token}",
				'Content-Type'    => 'application/octet-stream',
				'Dropbox-API-Arg' => json_encode(array('close' => false)),
			),
			'body'    => $chunk,
			'timeout' => 120,
		));

		if (is_wp_error($response)) {
			fclose($fh);
			return $response;
		}

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($body['session_id'])) {
			fclose($fh);
			return new WP_Error('dropbox_session', 'Failed to start upload session.');
		}

		$session_id = $body['session_id'];
		$offset = strlen($chunk);

		// Append chunks
		while ($offset < $filesize) {
			$remaining = $filesize - $offset;
			$read_size = min($chunk_size, $remaining);
			$chunk = fread($fh, $read_size);

			if (strlen($chunk) === 0) {
				fclose($fh);
				return new WP_Error('dropbox_session_read', 'Failed to read data from file during upload session.');
			}

			$is_last = ($offset + strlen($chunk) >= $filesize);

			$response = $this->request_with_retries(function() use ($is_last, $token, $session_id, $offset, $chunk, $remote_path) {
				if ($is_last) {
					// Finish
					return wp_remote_post($this->content_url . '/files/upload_session/finish', array(
						'headers' => array(
							'Authorization'   => "Bearer {$token}",
							'Content-Type'    => 'application/octet-stream',
							'Dropbox-API-Arg' => json_encode(array(
								'cursor' => array('session_id' => $session_id, 'offset' => $offset),
								'commit' => array('path' => $remote_path, 'mode' => 'overwrite', 'autorename' => false),
							)),
						),
						'body'    => $chunk,
						'timeout' => 120,
					));
				}

				return wp_remote_post($this->content_url . '/files/upload_session/append_v2', array(
					'headers' => array(
						'Authorization'   => "Bearer {$token}",
						'Content-Type'    => 'application/octet-stream',
						'Dropbox-API-Arg' => json_encode(array(
							'cursor' => array('session_id' => $session_id, 'offset' => $offset),
							'close'  => false,
						)),
					),
					'body'    => $chunk,
					'timeout' => 120,
				));
			}, 'Dropbox chunk upload');

			if (is_wp_error($response)) {
				fclose($fh);
				return $response;
			}

			$resp_code = wp_remote_retrieve_response_code($response);
			if ($resp_code !== 200) {
				fclose($fh);
				$err_body = wp_remote_retrieve_body($response);
				return new WP_Error('dropbox_session_error', "Upload session error (HTTP {$resp_code}): " . substr($err_body, 0, 200));
			}

			$offset += strlen($chunk);
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		fclose($fh);
		return true;
	}

	public function download($file, $dest_dir) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$options = $this->get_options();
		$folder = rtrim($options['folder'] ?: '/SiteGuard Backups', '/');
		$remote_path = $folder . '/' . $file;
		$local_path = trailingslashit($dest_dir) . basename($file);

		// Stream download to file to avoid memory issues
		$response = wp_remote_post($this->content_url . '/files/download', array(
			'headers' => array(
				'Authorization'   => "Bearer {$token}",
				'Dropbox-API-Arg' => json_encode(array('path' => $remote_path)),
			),
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $local_path,
		));

		if (is_wp_error($response)) {
			@unlink($local_path);
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			@unlink($local_path);
			return new WP_Error('dropbox_download', "Dropbox download failed (HTTP {$code})");
		}

		return true;
	}

	public function delete($files) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$options = $this->get_options();
		$folder = rtrim($options['folder'] ?: '/SiteGuard Backups', '/');

		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			$response = wp_remote_post($this->api_url . '/files/delete_v2', array(
				'headers' => array(
					'Authorization' => "Bearer {$token}",
					'Content-Type'  => 'application/json',
				),
				'body' => json_encode(array('path' => $folder . '/' . $file)),
			));

			if (is_wp_error($response)) {
				$errors[] = $file . ': ' . $response->get_error_message();
				continue;
			}

			$code = wp_remote_retrieve_response_code($response);
			if ($code !== 200) {
				$body = json_decode(wp_remote_retrieve_body($response), true);
				$err_msg = isset($body['error_summary']) ? $body['error_summary'] : "HTTP {$code}";
				$errors[] = $file . ': ' . $err_msg;
			}
		}

		if (!empty($errors)) {
			return new WP_Error('dropbox_delete_failed', 'Failed to delete file(s): ' . implode('; ', $errors));
		}

		return true;
	}

	public function listfiles() {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return array();

		$options = $this->get_options();
		$folder = rtrim($options['folder'] ?: '/SiteGuard Backups', '/');

		$files = array();
		$cursor = '';
		$has_more = true;

		while ($has_more) {
			if (empty($cursor)) {
				$response = wp_remote_post($this->api_url . '/files/list_folder', array(
					'headers' => array(
						'Authorization' => "Bearer {$token}",
						'Content-Type'  => 'application/json',
					),
					'body' => json_encode(array('path' => $folder, 'limit' => 2000)),
				));
			} else {
				$response = wp_remote_post($this->api_url . '/files/list_folder/continue', array(
					'headers' => array(
						'Authorization' => "Bearer {$token}",
						'Content-Type'  => 'application/json',
					),
					'body' => json_encode(array('cursor' => $cursor)),
				));
			}

			if (is_wp_error($response)) break;

			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (isset($body['error'])) break;

			if (!empty($body['entries'])) {
				foreach ($body['entries'] as $entry) {
					if (isset($entry['.tag']) && $entry['.tag'] === 'file') {
						$files[] = $entry['name'];
					}
				}
			}

			$has_more = !empty($body['has_more']);
			$cursor = isset($body['cursor']) ? $body['cursor'] : '';
		}

		return $files;
	}

	public function credentials_test() {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$response = wp_remote_post($this->api_url . '/users/get_current_account', array(
			'headers' => array('Authorization' => "Bearer {$token}"),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (isset($body['error'])) {
			return new WP_Error('dropbox_test', $body['error_summary']);
		}

		return true;
	}
}
