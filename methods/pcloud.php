<?php
/**
 * SiteGuard Backup - pCloud Storage Method
 *
 * Uses pCloud REST API with OAuth 2.0 access token.
 * Supports US (api.pcloud.com) and EU (eapi.pcloud.com) data regions.
 * Simple upload for files under 20MB, chunked upload for larger files.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Pcloud extends SiteGuard_Backup_Module {

	public function get_default_options() {
		return array(
			'client_id'     => '',
			'client_secret' => '',
			'access_token'  => '',
			'folder_id'     => 0,
			'use_eu'        => false,
		);
	}

	/**
	 * OAuth2 authorization URL, code flow (see SiteGuard_Backup_OAuth::start()).
	 * https://docs.pcloud.com/methods/oauth_2.0/authorize.html
	 */
	public function get_auth_url($state, $redirect_uri) {
		$options = $this->get_options();
		return 'https://my.pcloud.com/oauth2/authorize?' . http_build_query(array(
			'client_id'     => $options['client_id'],
			'response_type' => 'code',
			'redirect_uri'  => $redirect_uri,
			'state'         => $state,
		));
	}

	/**
	 * Exchange the code at the API host of the user's data region. The callback
	 * names that host; only pCloud's two API hosts are accepted, so the client
	 * secret never goes anywhere else. pCloud tokens do not expire.
	 */
	public function exchange_code($code, $redirect_uri, $extra = array()) {
		$options = $this->get_options();
		$host = isset($extra['hostname']) ? strtolower((string) $extra['hostname']) : 'api.pcloud.com';
		if (!in_array($host, array('api.pcloud.com', 'eapi.pcloud.com'), true)) {
			return new WP_Error('pcloud_auth', 'Unexpected pCloud API host: ' . $host);
		}
		$response = wp_remote_get('https://' . $host . '/oauth2_token?' . http_build_query(array(
			'client_id'     => $options['client_id'],
			'client_secret' => $options['client_secret'],
			'code'          => $code,
		)), array('timeout' => 30));
		if (is_wp_error($response)) return $response;
		$body = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($body) || (isset($body['result']) && 0 !== (int) $body['result']) || empty($body['access_token'])) {
			$msg = is_array($body) && isset($body['error']) ? $body['error'] : 'HTTP ' . wp_remote_retrieve_response_code($response);
			return new WP_Error('pcloud_auth', 'pCloud sign-in failed: ' . $msg);
		}
		$options['access_token'] = $body['access_token'];
		$options['use_eu'] = ('eapi.pcloud.com' === $host) || (isset($body['locationid']) && 2 === (int) $body['locationid']);
		$this->save_options($options);
		return true;
	}

	/**
	 * Get the pCloud API base URL based on region setting.
	 *
	 * @return string API base URL.
	 */
	private function get_api_base() {
		$options = $this->get_options();
		return !empty($options['use_eu'])
			? 'https://eapi.pcloud.com'
			: 'https://api.pcloud.com';
	}

	/**
	 * Perform a pCloud API request.
	 *
	 * @param string $endpoint API endpoint (e.g., '/userinfo').
	 * @param array  $params   Query parameters.
	 * @param string $method   HTTP method (GET or POST).
	 * @param array  $body_args Optional body args for multipart/form-data.
	 * @return array|WP_Error Decoded JSON response or error.
	 */
	private function api_request($endpoint, $params = array(), $method = 'GET', $body_args = array()) {
		$options = $this->get_options();
		$auth_header = array('Authorization' => 'Bearer ' . $options['access_token']);

		$url = $this->get_api_base() . $endpoint;

		if ($method === 'GET') {
			if (!empty($params)) {
				$url .= '?' . http_build_query($params);
			}
			$response = wp_remote_get($url, array('timeout' => 300, 'headers' => $auth_header));
		} else {
			if (!empty($body_args)) {
				// Multipart form upload - params go in URL, body is the file data
				if (!empty($params)) {
					$url .= '?' . http_build_query($params);
				}
				$merged = array_merge(array('timeout' => 300, 'headers' => $auth_header), $body_args);
				if (isset($merged['headers']) && is_array($merged['headers'])) {
					$merged['headers'] = array_merge($merged['headers'], $auth_header);
				}
				$response = wp_remote_post($url, $merged);
			} else {
				$response = wp_remote_post($url, array(
					'body'    => $params,
					'timeout' => 300,
					'headers' => $auth_header,
				));
			}
		}

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!is_array($data)) {
			return new WP_Error('pcloud_parse', "pCloud: Failed to parse response (HTTP {$code})");
		}

		// pCloud returns result code in JSON, not HTTP status
		if (isset($data['result']) && (int) $data['result'] !== 0) {
			$error_msg = isset($data['error']) ? $data['error'] : 'Unknown pCloud error (code: ' . $data['result'] . ')';
			return new WP_Error('pcloud_api', "pCloud API error: {$error_msg}");
		}

		return $data;
	}

	/**
	 * Upload a backup file to pCloud.
	 *
	 * Uses uploadfile API for files under 20MB, chunked upload for larger files.
	 *
	 * @param string $file Full path to the file to upload.
	 * @return bool|WP_Error
	 */
	public function backup($file) {
		$options = $this->get_options();
		$folder_id = (int) $options['folder_id'];
		$filesize = filesize($file);
		$filename = basename($file);

		// For files under 20MB, use simple upload (reads entire file into memory)
		if ($filesize < 20971520) {
			return $this->simple_upload($file, $folder_id, $filename);
		}

		// For larger files, use chunked upload
		return $this->chunked_pcloud_upload($file, $folder_id, $filename);
	}

	/**
	 * Simple file upload via multipart form data.
	 *
	 * @param string $file      Full path to the file.
	 * @param int    $folder_id pCloud folder ID.
	 * @param string $filename  Target filename.
	 * @return bool|WP_Error
	 */
	private function simple_upload($file, $folder_id, $filename) {
		$options = $this->get_options();

		// Build multipart boundary
		$boundary = wp_generate_password(24, false);
		$body = '';

		// File part
		$body .= '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . "\r\n";
		$body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
		$body .= file_get_contents($file) . "\r\n";
		$body .= '--' . $boundary . '--' . "\r\n";

		$url = $this->get_api_base() . '/uploadfile?' . http_build_query(array(
			'folderid'     => $folder_id,
			'filename'     => $filename,
			'nopartial'    => 1,
		));

		$response = wp_remote_post($url, array(
			'headers' => array(
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				'Authorization' => 'Bearer ' . $options['access_token'],
			),
			'body'    => $body,
			'timeout' => 300,
		));

		if (is_wp_error($response)) return $response;

		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data) || (isset($data['result']) && (int) $data['result'] !== 0)) {
			$error_msg = isset($data['error']) ? $data['error'] : 'Upload failed';
			return new WP_Error('pcloud_upload', "pCloud upload error: {$error_msg}");
		}

		return true;
	}

	/**
	 * Chunked upload for large files using upload_create, upload_write, upload_save.
	 *
	 * @param string $file      Full path to the file.
	 * @param int    $folder_id pCloud folder ID.
	 * @param string $filename  Target filename.
	 * @return bool|WP_Error
	 */
	private function chunked_pcloud_upload($file, $folder_id, $filename) {
		$sgbackup = SiteGuard_Backup::instance();

		// Create upload session
		$create = $this->api_request('/upload_create');
		if (is_wp_error($create)) return $create;

		if (empty($create['uploadid'])) {
			return new WP_Error('pcloud_chunk_init', 'Failed to create pCloud upload session.');
		}
		$upload_id = $create['uploadid'];

		// Upload chunks
		$chunk_size = 10485760; // 10MB per chunk
		$fh = fopen($file, 'rb');
		if (!$fh) {
			return new WP_Error('pcloud_read', 'Failed to open file for reading.');
		}

		$offset = 0;
		$total_size = filesize($file);

		while (!feof($fh)) {
			$chunk = fread($fh, $chunk_size);
			if (false === $chunk || strlen($chunk) === 0) break;

			$options = $this->get_options();
			$url = $this->get_api_base() . '/upload_write?' . http_build_query(array(
				'uploadid'     => $upload_id,
				'uploadoffset' => $offset,
			));

			// pCloud takes uploaded data as multipart/form-data POST or as the raw
			// body of a PUT (docs.pcloud.com, "Uploading files"; the official SDK
			// sends upload_write as PUT). A raw-body POST is neither, so every
			// archive over 20 MB failed to upload.
			$response = $this->request_with_retries(function() use ($url, $chunk, $options) {
				return wp_remote_request($url, array(
					'method'  => 'PUT',
					'headers' => array(
						'Content-Type'  => 'application/octet-stream',
						'Authorization' => 'Bearer ' . $options['access_token'],
					),
					'body'    => $chunk,
					'timeout' => 300,
				));
			}, 'pCloud chunk upload');

			if (is_wp_error($response)) {
				fclose($fh);
				return $response;
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);
			if (!is_array($data) || !isset($data['result']) || (int) $data['result'] !== 0
				|| wp_remote_retrieve_response_code($response) >= 400) {
				// A non-JSON (5xx/HTML) reply used to pass as success and the offset
				// advanced, committing a truncated file via upload_save.
				fclose($fh);
				$error_msg = (is_array($data) && isset($data['error'])) ? $data['error'] : ('Chunk upload failed (HTTP ' . wp_remote_retrieve_response_code($response) . ')');
				return new WP_Error('pcloud_chunk', "pCloud chunk error: {$error_msg}");
			}

			$offset += strlen($chunk);
			$sgbackup->log("pCloud: Uploaded " .
				SiteGuard_Backup_Filesystem::format_bytes($offset) . " / " .
				SiteGuard_Backup_Filesystem::format_bytes($total_size));
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}
		fclose($fh);

		// Save (commit) the upload
		$save = $this->api_request('/upload_save', array(
			'uploadid' => $upload_id,
			'folderid' => $folder_id,
			'name'     => $filename,
		));

		return is_wp_error($save) ? $save : true;
	}

	/**
	 * Download a backup file from pCloud.
	 *
	 * @param string $file     Filename to download.
	 * @param string $dest_dir Local destination directory.
	 * @return bool|WP_Error
	 */
	public function download($file, $dest_dir) {
		$options = $this->get_options();
		$folder_id = (int) $options['folder_id'];
		$local_path = trailingslashit($dest_dir) . basename($file);

		// List folder to find the file ID
		$file_id = $this->find_file_id($file, $folder_id);
		if (is_wp_error($file_id)) return $file_id;
		if (!$file_id) {
			return new WP_Error('pcloud_not_found', "File not found in pCloud: {$file}");
		}

		// Get download link
		$link_data = $this->api_request('/getfilelink', array('fileid' => $file_id));
		if (is_wp_error($link_data)) return $link_data;

		if (empty($link_data['hosts'][0]) || empty($link_data['path'])) {
			return new WP_Error('pcloud_link', 'Failed to get pCloud download link.');
		}

		$download_url = 'https://' . $link_data['hosts'][0] . $link_data['path'];

		$response = wp_remote_get($download_url, array(
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $local_path,
		));

		if (is_wp_error($response)) {
			@unlink($local_path); // a transport error mid-stream leaves a truncated file
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			@unlink($local_path);
			return new WP_Error('pcloud_download', "pCloud download failed (HTTP {$code})");
		}

		return true;
	}

	/**
	 * Delete file(s) from pCloud.
	 *
	 * @param array|string $files Filename(s) to delete.
	 * @return bool|WP_Error
	 */
	public function delete($files) {
		$files = $this->safe_remote_names($files);
		if (!$files) return true;
		if (!is_array($files)) $files = array($files);

		$options = $this->get_options();
		$folder_id = (int) $options['folder_id'];
		$errors = array();

		foreach ($files as $file) {
			$file_id = $this->find_file_id($file, $folder_id);
			if (is_wp_error($file_id)) {
				$errors[] = $file . ': ' . $file_id->get_error_message();
				continue;
			}
			if ($file_id) {
				$result = $this->api_request('/deletefile', array('fileid' => $file_id));
				if (is_wp_error($result)) {
					$errors[] = $file . ': ' . $result->get_error_message();
				}
			}
		}

		if (!empty($errors)) {
			return new WP_Error('pcloud_delete', 'Failed to delete: ' . implode('; ', $errors));
		}

		return true;
	}

	/**
	 * List files in the pCloud folder.
	 *
	 * @return array List of filenames.
	 */
	public function listfiles() {
		$options = $this->get_options();
		$folder_id = (int) $options['folder_id'];

		$result = $this->api_request('/listfolder', array('folderid' => $folder_id));
		if (is_wp_error($result)) return array();

		$files = array();
		if (!empty($result['metadata']['contents'])) {
			foreach ($result['metadata']['contents'] as $item) {
				if (empty($item['isfolder'])) {
					$files[] = $item['name'];
				}
			}
		}

		return $files;
	}

	/**
	 * Test pCloud credentials.
	 *
	 * @return bool|WP_Error
	 */
	public function credentials_test() {
		$options = $this->get_options();
		if (empty($options['access_token'])) {
			return new WP_Error('pcloud_config', 'Access token is required.');
		}

		$result = $this->api_request('/userinfo');
		if (is_wp_error($result)) return $result;

		return true;
	}

	/**
	 * Find a file's ID by name within a folder.
	 *
	 * @param string $filename  The filename to search for.
	 * @param int    $folder_id The pCloud folder ID to search in.
	 * @return int|false|WP_Error File ID, false if not found, or WP_Error.
	 */
	private function find_file_id($filename, $folder_id) {
		$result = $this->api_request('/listfolder', array('folderid' => $folder_id));
		if (is_wp_error($result)) return $result;

		if (!empty($result['metadata']['contents'])) {
			foreach ($result['metadata']['contents'] as $item) {
				if (empty($item['isfolder']) && $item['name'] === $filename) {
					return (int) $item['fileid'];
				}
			}
		}

		return false;
	}
}
