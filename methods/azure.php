<?php
/**
 * SiteGuard Backup - Microsoft Azure Blob Storage Method
 *
 * Uses Azure Blob Storage REST API with SharedKey authorization.
 * No Azure SDK dependency - pure REST via wp_remote_request().
 * Supports: upload (Put Blob for <50MB, Block Blob for larger), download, delete, list.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Azure extends SiteGuard_Backup_Module {

	/** @var string Azure Blob Storage API version */
	private $api_version = '2023-11-03';

	public function get_default_options() {
		return array(
			'account_name'    => '',
			'account_key'     => '',
			'container'       => '',
			'path'            => '',
			'endpoint_suffix' => 'blob.core.windows.net', // Global, Germany: blob.core.cloudapi.de, China: blob.core.chinacloudapi.cn, US Gov: blob.core.usgovcloudapi.net
		);
	}

	/**
	 * Get the base URL for Azure Blob Storage requests.
	 *
	 * @return string
	 */
	private function get_base_url() {
		$options = $this->get_options();
		$suffix = !empty($options['endpoint_suffix']) ? $options['endpoint_suffix'] : 'blob.core.windows.net';
		return 'https://' . $options['account_name'] . '.' . $suffix;
	}

	/**
	 * Build the blob path including optional prefix directory.
	 *
	 * @param string $blob_name The blob filename.
	 * @return string The full blob path within the container.
	 */
	private function get_blob_path($blob_name) {
		$options = $this->get_options();
		$path = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';
		return $path . $blob_name;
	}

	/**
	 * Encode a URI path per segment (RFC 3986), preserving forward slashes.
	 *
	 * The SharedKey canonicalized resource must contain the encoded URI path
	 * exactly as sent on the wire, so encode once and use the same string for
	 * both signing and the request URL.
	 *
	 * @param string $path URI path (e.g. /container/dir/blob name.zip).
	 * @return string Encoded path.
	 */
	private function encode_uri_path($path) {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * Generate SharedKey authorization signature for Azure Blob Storage.
	 *
	 * @param string $method     HTTP method (GET, PUT, DELETE, HEAD).
	 * @param string $uri        Request URI path.
	 * @param array  $headers    Request headers.
	 * @param string $query      Query string (for canonicalized resource).
	 * @param int    $content_length Content-Length value.
	 * @param string $content_type   Content-Type header value.
	 * @return array Signed headers including Authorization.
	 */
	private function sign_request($method, $uri, $headers, $query = '', $content_length = 0, $content_type = '') {
		$options = $this->get_options();
		$now = gmdate('D, d M Y H:i:s T');

		$headers['x-ms-date'] = $now;
		$headers['x-ms-version'] = $this->api_version;

		// Build canonicalized headers (lowercase, sorted, newline-separated)
		$canonical_headers = '';
		$ms_headers = array();
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			if (strpos($lk, 'x-ms-') === 0) {
				$ms_headers[$lk] = trim($v);
			}
		}
		ksort($ms_headers);
		foreach ($ms_headers as $k => $v) {
			$canonical_headers .= $k . ':' . $v . "\n";
		}

		// Build canonicalized resource
		$canonical_resource = '/' . $options['account_name'] . $uri;
		if (!empty($query)) {
			// Parse query parameters and sort them
			$query_params = array();
			parse_str($query, $query_params);
			ksort($query_params);
			foreach ($query_params as $pk => $pv) {
				$canonical_resource .= "\n" . strtolower($pk) . ':' . $pv;
			}
		}

		// Content-Length: empty string for 0, otherwise the value
		$content_length_str = ($content_length > 0) ? (string) $content_length : '';

		// StringToSign format for SharedKey:
		// VERB\nContent-Encoding\nContent-Language\nContent-Length\nContent-MD5\nContent-Type\n
		// Date\nIf-Modified-Since\nIf-Match\nIf-None-Match\nIf-Unmodified-Since\nRange\n
		// CanonicalizedHeaders\nCanonicalizedResource
		$string_to_sign = $method . "\n"      // VERB
			. "\n"                             // Content-Encoding
			. "\n"                             // Content-Language
			. $content_length_str . "\n"       // Content-Length
			. "\n"                             // Content-MD5
			. $content_type . "\n"             // Content-Type
			. "\n"                             // Date
			. "\n"                             // If-Modified-Since
			. "\n"                             // If-Match
			. "\n"                             // If-None-Match
			. "\n"                             // If-Unmodified-Since
			. "\n"                             // Range
			. $canonical_headers               // CanonicalizedHeaders (already has trailing \n)
			. $canonical_resource;             // CanonicalizedResource

		$decoded_key = base64_decode($options['account_key']);
		$signature = base64_encode(hash_hmac('sha256', $string_to_sign, $decoded_key, true));

		$headers['Authorization'] = 'SharedKey ' . $options['account_name'] . ':' . $signature;

		return $headers;
	}

	/**
	 * Perform an Azure Blob Storage REST API request.
	 *
	 * @param string $method  HTTP method.
	 * @param string $uri     URI path (e.g., /container/blob).
	 * @param string $body    Request body.
	 * @param array  $extra_headers Additional headers.
	 * @param string $query   Query string parameters.
	 * @return array|WP_Error Response or error.
	 */
	private function request($method, $uri, $body = '', $extra_headers = array(), $query = '') {
		$content_length = strlen($body);
		$content_type = isset($extra_headers['Content-Type']) ? $extra_headers['Content-Type'] : '';

		$headers = array_merge(array(), $extra_headers);

		$signed_headers = $this->sign_request($method, $uri, $headers, $query, $content_length, $content_type);

		$url = $this->get_base_url() . $uri;
		if (!empty($query)) {
			$url .= '?' . $query;
		}

		$args = array(
			'method'  => $method,
			'headers' => $signed_headers,
			'body'    => ($method === 'PUT' || $method === 'POST') ? $body : null,
			'timeout' => 300,
		);

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			$response_body = wp_remote_retrieve_body($response);
			return new WP_Error('azure_error', "Azure Blob error (HTTP {$code}): {$response_body}");
		}

		return $response;
	}

	/**
	 * Upload a backup file to Azure Blob Storage.
	 *
	 * Uses Put Blob for files under 50MB, Block Blob (chunked) for larger files.
	 *
	 * @param string $file Full path to the file to upload.
	 * @return bool|WP_Error
	 */
	public function backup($file) {
		$options = $this->get_options();
		$blob_name = $this->get_blob_path(basename($file));
		$uri = $this->encode_uri_path('/' . $options['container'] . '/' . $blob_name);
		$filesize = filesize($file);

		// For files under 50MB, use simple Put Blob
		if ($filesize < 52428800) {
			$body = file_get_contents($file);
			$result = $this->request('PUT', $uri, $body, array(
				'Content-Type'    => 'application/octet-stream',
				'x-ms-blob-type' => 'BlockBlob',
			));
			return is_wp_error($result) ? $result : true;
		}

		// For larger files, use Block Blob with Put Block + Put Block List
		return $this->block_blob_upload($file, $uri);
	}

	/**
	 * Upload a large file using Block Blob (Put Block + Put Block List).
	 *
	 * @param string $file Full path to the file.
	 * @param string $uri  Blob URI path.
	 * @return bool|WP_Error
	 */
	private function block_blob_upload($file, $uri) {
		$chunk_size = 10485760; // 10MB per block
		$fh = fopen($file, 'rb');
		if (!$fh) {
			return new WP_Error('azure_read_error', 'Failed to open file for reading.');
		}

		$block_ids = array();
		$block_index = 0;
		$offset = 0;
		$sgbackup = SiteGuard_Backup::instance();

		while (!feof($fh)) {
			$chunk = fread($fh, $chunk_size);
			if (false === $chunk || strlen($chunk) === 0) break;

			// Block ID must be base64-encoded, same length for all blocks
			$block_id = base64_encode(str_pad((string) $block_index, 6, '0', STR_PAD_LEFT));
			$block_ids[] = $block_id;

			$query = 'comp=block&blockid=' . urlencode($block_id);
			$result = $this->request_with_retries(function() use ($uri, $chunk, $query) {
				return $this->request('PUT', $uri, $chunk, array(
					'Content-Type' => 'application/octet-stream',
				), $query);
			}, 'Azure Put Block');

			if (is_wp_error($result)) {
				fclose($fh);
				return $result;
			}

			$offset += strlen($chunk);
			$block_index++;
			$sgbackup->log("Azure: Uploaded block {$block_index} (" .
				SiteGuard_Backup_Filesystem::format_bytes($offset) . ")");
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}
		fclose($fh);

		// Put Block List to commit all blocks
		$block_list_xml = '<?xml version="1.0" encoding="utf-8"?><BlockList>';
		foreach ($block_ids as $id) {
			$block_list_xml .= '<Latest>' . $id . '</Latest>';
		}
		$block_list_xml .= '</BlockList>';

		$result = $this->request('PUT', $uri, $block_list_xml, array(
			'Content-Type' => 'application/xml',
		), 'comp=blocklist');

		return is_wp_error($result) ? $result : true;
	}

	/**
	 * Download a backup file from Azure Blob Storage.
	 *
	 * @param string $file     Filename to download.
	 * @param string $dest_dir Local destination directory.
	 * @return bool|WP_Error
	 */
	public function download($file, $dest_dir) {
		$options = $this->get_options();
		$blob_name = $this->get_blob_path($file);
		$uri = $this->encode_uri_path('/' . $options['container'] . '/' . $blob_name);
		$local_path = trailingslashit($dest_dir) . basename($file);

		$headers = array();
		$signed_headers = $this->sign_request('GET', $uri, $headers);

		$url = $this->get_base_url() . $uri;

		$response = wp_remote_get($url, array(
			'headers'  => $signed_headers,
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $local_path,
		));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			@unlink($local_path);
			return new WP_Error('azure_download', "Azure download failed (HTTP {$code})");
		}

		return true;
	}

	/**
	 * Delete file(s) from Azure Blob Storage.
	 *
	 * @param array|string $files Filename(s) to delete.
	 * @return bool|WP_Error
	 */
	public function delete($files) {
		if (!is_array($files)) $files = array($files);

		$options = $this->get_options();
		$errors = array();

		foreach ($files as $file) {
			$blob_name = $this->get_blob_path($file);
			$uri = $this->encode_uri_path('/' . $options['container'] . '/' . $blob_name);
			$result = $this->request('DELETE', $uri);
			if (is_wp_error($result)) {
				$errors[] = $file . ': ' . $result->get_error_message();
			}
		}

		if (!empty($errors)) {
			return new WP_Error('azure_delete_failed', 'Failed to delete file(s): ' . implode('; ', $errors));
		}

		return true;
	}

	/**
	 * List files in the Azure Blob container.
	 *
	 * @return array List of filenames.
	 */
	public function listfiles() {
		$options = $this->get_options();
		$uri = $this->encode_uri_path('/' . $options['container']);
		$path = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';

		$query_parts = array('restype=container', 'comp=list');
		if (!empty($path)) {
			$query_parts[] = 'prefix=' . urlencode($path);
		}
		$query = implode('&', $query_parts);

		$result = $this->request('GET', $uri, '', array(), $query);
		if (is_wp_error($result)) return array();

		$body = wp_remote_retrieve_body($result);
		$files = array();

		// Parse XML response for blob names
		if (preg_match_all('/<Blob>\s*<Name>([^<]+)<\/Name>/s', $body, $matches)) {
			foreach ($matches[1] as $name) {
				// Strip path prefix to return just filenames
				if (!empty($path) && strpos($name, $path) === 0) {
					$name = substr($name, strlen($path));
				}
				if (!empty($name)) {
					$files[] = $name;
				}
			}
		}

		return $files;
	}

	/**
	 * Test Azure Blob Storage credentials.
	 *
	 * @return bool|WP_Error
	 */
	public function credentials_test() {
		$options = $this->get_options();
		if (empty($options['account_name']) || empty($options['account_key']) || empty($options['container'])) {
			return new WP_Error('azure_config', 'Account name, account key, and container are required.');
		}

		$uri = $this->encode_uri_path('/' . $options['container']);
		$query = 'restype=container';
		$result = $this->request('HEAD', $uri, '', array(), $query);

		return is_wp_error($result) ? $result : true;
	}
}
