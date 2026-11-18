<?php
/**
 * SiteGuard Backup - Google Drive Storage Method
 *
 * OAuth2 flow with chunked resumable upload API.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Googledrive extends SiteGuard_Backup_Module {

	private $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth';
	private $token_url = 'https://oauth2.googleapis.com/token';
	private $api_url = 'https://www.googleapis.com/drive/v3';
	private $upload_url = 'https://www.googleapis.com/upload/drive/v3';

	public function get_default_options() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'token'         => array(),
			'folder_id'     => '',
			'folder_name'   => 'SiteGuard Backups',
		);
	}

	/**
	 * OAuth2 authorization URL (see SiteGuard_Backup_OAuth::start()).
	 */
	public function get_auth_url($state, $redirect_uri) {
		$options = $this->get_options();
		return $this->auth_url . '?' . http_build_query(array(
			'client_id'     => $options['client_id'],
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/drive.file',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		));
	}

	/**
	 * Exchange an authorization code for tokens and store them on this instance.
	 */
	public function exchange_code($code, $redirect_uri) {
		$options = $this->get_options();

		$response = wp_remote_post($this->token_url, array(
			'body' => array(
				'code'          => $code,
				'client_id'     => $options['client_id'],
				'client_secret' => $options['client_secret'],
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($body) || isset($body['error']) || empty($body['access_token'])) {
			$msg = is_array($body) ? ($body['error_description'] ?? ($body['error'] ?? 'no access token')) : 'HTTP ' . wp_remote_retrieve_response_code($response);
			return new WP_Error('gdrive_auth', $msg);
		}
		if (empty($body['refresh_token'])) {
			return new WP_Error('gdrive_auth', 'Google returned no refresh token, so backups would stop after an hour. Remove SiteGuard\'s access at myaccount.google.com/permissions and sign in again.');
		}

		$options['token'] = $body;
		$options['token']['obtained_at'] = time();
		$options['folder_id'] = ''; // a different account cannot see the old folder
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
			return new WP_Error('gdrive_no_token', 'Not authenticated with Google Drive.');
		}

		// Check if token expired
		$obtained = isset($token['obtained_at']) ? $token['obtained_at'] : 0;
		$expires_in = isset($token['expires_in']) ? $token['expires_in'] : 3600;

		if (time() > ($obtained + $expires_in - 300)) {
			// Refresh
			if (empty($token['refresh_token'])) {
				return new WP_Error('gdrive_no_refresh', 'No refresh token available. Re-authenticate.');
			}

			$response = wp_remote_post($this->token_url, array(
				'body' => array(
					'refresh_token' => $token['refresh_token'],
					'client_id'     => $options['client_id'],
					'client_secret' => $options['client_secret'],
					'grant_type'    => 'refresh_token',
				),
			));

			if (is_wp_error($response)) return $response;

			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (isset($body['error'])) {
				return new WP_Error('gdrive_refresh', $body['error_description'] ?? $body['error']);
			}
			if (!is_array($body) || empty($body['access_token']) || !is_string($body['access_token'])) {
				// A 5xx/HTML/proxy response used to be written back as access_token=null,
				// after which every call failed "Not authenticated" before ever retrying
				// the still-valid refresh token. Leave the stored token untouched.
				return new WP_Error('gdrive_refresh', 'Token refresh returned an unexpected response (HTTP ' . wp_remote_retrieve_response_code($response) . '); will retry later.');
			}

			$options['token']['access_token'] = $body['access_token'];
			$options['token']['expires_in'] = $body['expires_in'];
			$options['token']['obtained_at'] = time();
			// Google may rotate refresh tokens - save new one if provided
			if (!empty($body['refresh_token'])) {
				$options['token']['refresh_token'] = $body['refresh_token'];
			}
			$this->save_options($options);

			return $body['access_token'];
		}

		return $token['access_token'];
	}

	/**
	 * Ensure the backup folder exists.
	 */
	private function ensure_folder() {
		$options = $this->get_options();
		if (!empty($options['folder_id'])) return $options['folder_id'];

		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$folder_name = $options['folder_name'] ?: 'SiteGuard Backups';

		// Escape single quotes and backslashes for Google Drive API query string
		$folder_name_escaped = str_replace(array('\\', "'"), array('\\\\', "\\'"), $folder_name);

		// Search for existing folder
		$response = wp_remote_get($this->api_url . '/files?' . http_build_query(array(
			'q' => "name='{$folder_name_escaped}' and mimeType='application/vnd.google-apps.folder' and trashed=false",
			'fields' => 'files(id,name)',
		)), array(
			'headers' => array('Authorization' => "Bearer {$token}"),
		));

		if (!is_wp_error($response)) {
			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (!empty($body['files'])) {
				$options['folder_id'] = $body['files'][0]['id'];
				$this->save_options($options);
				return $options['folder_id'];
			}
		}

		// Create folder
		$response = wp_remote_post($this->api_url . '/files', array(
			'headers' => array(
				'Authorization' => "Bearer {$token}",
				'Content-Type'  => 'application/json',
			),
			'body' => json_encode(array(
				'name'     => $folder_name,
				'mimeType' => 'application/vnd.google-apps.folder',
			)),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($body['id'])) {
			return new WP_Error('gdrive_folder', 'Could not create backup folder.');
		}

		$options['folder_id'] = $body['id'];
		$this->save_options($options);

		return $body['id'];
	}

	public function backup($file) {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$folder_id = $this->ensure_folder();
		if (is_wp_error($folder_id)) return $folder_id;

		$filename = basename($file);
		$filesize = filesize($file);

		// Initiate resumable upload
		$metadata = json_encode(array(
			'name'    => $filename,
			'parents' => array($folder_id),
		));

		$response = wp_remote_post($this->upload_url . '/files?uploadType=resumable', array(
			'headers' => array(
				'Authorization'           => "Bearer {$token}",
				'Content-Type'            => 'application/json; charset=UTF-8',
				'X-Upload-Content-Type'   => 'application/octet-stream',
				'X-Upload-Content-Length' => $filesize,
			),
			'body' => $metadata,
		));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200 && $code !== 201) {
			return new WP_Error('gdrive_init_upload', 'Failed to initiate upload (HTTP ' . $code . ')');
		}

		$upload_uri = wp_remote_retrieve_header($response, 'location');
		if (empty($upload_uri)) {
			return new WP_Error('gdrive_no_upload_uri', 'No upload URI returned.');
		}

		// Chunked upload
		$chunk_size = 5242880; // 5MB
		$fh = fopen($file, 'rb');
		$offset = 0;

		while ($offset < $filesize) {
			$chunk = fread($fh, $chunk_size);
			if (false === $chunk || strlen($chunk) === 0) {
				fclose($fh);
				return new WP_Error('gdrive_read_error', 'Failed to read file chunk.');
			}
			$end = $offset + strlen($chunk) - 1;

			$upload_response = $this->request_with_retries(function() use ($upload_uri, $chunk, $offset, $end, $filesize) {
				return wp_remote_request($upload_uri, array(
					'method'  => 'PUT',
					'headers' => array(
						'Content-Length' => strlen($chunk),
						'Content-Range'  => "bytes {$offset}-{$end}/{$filesize}",
					),
					'body'    => $chunk,
					'timeout' => 120,
				));
			}, 'Google Drive chunk upload');

			if (is_wp_error($upload_response)) {
				fclose($fh);
				return $upload_response;
			}

			$upload_code = wp_remote_retrieve_response_code($upload_response);
			if ($upload_code !== 200 && $upload_code !== 201 && $upload_code !== 308) {
				fclose($fh);
				return new WP_Error('gdrive_chunk_failed', "Upload chunk failed (HTTP {$upload_code})");
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

		// Find file by name
		$folder_id = $this->ensure_folder();
		if (is_wp_error($folder_id)) return $folder_id;

		// Escape single quotes for Google Drive API query string
		$file_escaped = str_replace("'", "\\'", $file);

		$response = wp_remote_get($this->api_url . '/files?' . http_build_query(array(
			'q' => "name='{$file_escaped}' and '{$folder_id}' in parents and trashed=false",
			'fields' => 'files(id)',
		)), array(
			'headers' => array('Authorization' => "Bearer {$token}"),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (empty($body['files'])) {
			return new WP_Error('gdrive_not_found', "File not found: {$file}");
		}

		$file_id = $body['files'][0]['id'];

		// Download
		$dl_response = wp_remote_get($this->api_url . "/files/{$file_id}?alt=media", array(
			'headers' => array('Authorization' => "Bearer {$token}"),
			'timeout' => 300,
			'stream'  => true,
			'filename' => trailingslashit($dest_dir) . $file,
		));

		$local_path = trailingslashit($dest_dir) . $file;
		if (is_wp_error($dl_response)) {
			@unlink($local_path);
			return $dl_response;
		}
		$code = wp_remote_retrieve_response_code($dl_response);
		if ($code >= 400) {
			@unlink($local_path);
			return new WP_Error('gdrive_download_failed', "Google Drive download failed with HTTP {$code}");
		}
		return true;
	}

	public function delete($files) {
		$files = $this->safe_remote_names($files);
		if (!$files) return true;
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$folder_id = $this->ensure_folder();
		if (is_wp_error($folder_id)) return $folder_id;

		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			// Escape single quotes for Google Drive API query string
			$file_escaped = str_replace("'", "\\'", $file);
			$response = wp_remote_get($this->api_url . '/files?' . http_build_query(array(
				'q' => "name='{$file_escaped}' and '{$folder_id}' in parents and trashed=false",
				'fields' => 'files(id)',
			)), array(
				'headers' => array('Authorization' => "Bearer {$token}"),
			));

			if (is_wp_error($response)) {
				$errors[] = $file . ': ' . $response->get_error_message();
				continue;
			}

			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (!empty($body['files'])) {
				foreach ($body['files'] as $f) {
					$del_response = wp_remote_request($this->api_url . '/files/' . $f['id'], array(
						'method'  => 'DELETE',
						'headers' => array('Authorization' => "Bearer {$token}"),
					));

					if (is_wp_error($del_response)) {
						$errors[] = $file . ': ' . $del_response->get_error_message();
					} else {
						$del_code = wp_remote_retrieve_response_code($del_response);
						// Google Drive returns 204 No Content on successful delete
						if ($del_code >= 400) {
							$errors[] = $file . ": HTTP {$del_code}";
						}
					}
				}
			}
		}

		if (!empty($errors)) {
			return new WP_Error('gdrive_delete_failed', 'Failed to delete file(s): ' . implode('; ', $errors));
		}

		return true;
	}

	public function listfiles() {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return array();

		$folder_id = $this->ensure_folder();
		if (is_wp_error($folder_id)) return array();

		$files = array();
		$page_token = '';

		do {
			$params = array(
				'q'        => "'{$folder_id}' in parents and trashed=false",
				'fields'   => 'nextPageToken,files(name,size)',
				'pageSize' => 1000,
			);
			if (!empty($page_token)) {
				$params['pageToken'] = $page_token;
			}

			$response = wp_remote_get($this->api_url . '/files?' . http_build_query($params), array(
				'headers' => array('Authorization' => "Bearer {$token}"),
			));

			if (is_wp_error($response)) break;

			$body = json_decode(wp_remote_retrieve_body($response), true);
			if (isset($body['error'])) break;

			if (!empty($body['files'])) {
				foreach ($body['files'] as $f) {
					$files[] = $f['name'];
				}
			}

			$page_token = isset($body['nextPageToken']) ? $body['nextPageToken'] : '';
		} while (!empty($page_token));

		return $files;
	}

	public function credentials_test() {
		$token = $this->get_access_token();
		if (is_wp_error($token)) return $token;

		$response = wp_remote_get($this->api_url . '/about?fields=user', array(
			'headers' => array('Authorization' => "Bearer {$token}"),
		));

		if (is_wp_error($response)) return $response;

		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (isset($body['error'])) {
			return new WP_Error('gdrive_test', $body['error']['message']);
		}

		return true;
	}
}
