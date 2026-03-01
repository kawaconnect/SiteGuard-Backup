<?php
/**
 * SiteGuard Backup - S3-Compatible Storage Method
 *
 * Supports AWS S3, DigitalOcean Spaces, MinIO, Backblaze B2.
 * Uses wp_remote_request() for S3 API calls (no SDK dependency).
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_S3 extends SiteGuard_Backup_Module {

	public function get_default_options() {
		return array(
			'access_key'            => '',
			'secret_key'            => '',
			'bucket'                => '',
			'region'                => 'us-east-1',
			'path'                  => '',
			'endpoint'              => '', // Custom endpoint for S3-compatible services
			'provider'              => 'aws', // aws, spaces, minio, b2
			'storage_class'         => 'STANDARD', // STANDARD, STANDARD_IA, INTELLIGENT_TIERING, ONEZONE_IA, GLACIER_IR, REDUCED_REDUNDANCY
			'object_lock_days'      => 0, // B2 Object Lock: 0=disabled, 1-3000 days
			'server_side_encryption' => false,
		);
	}

	protected function get_endpoint() {
		$options = $this->get_options();
		if (!empty($options['endpoint'])) {
			$endpoint = rtrim($options['endpoint'], '/');
			// Block non-HTTPS custom endpoints to prevent credential exposure
			if (strpos($endpoint, 'https://') !== 0) {
				error_log('SiteGuard Backup: S3 custom endpoint must use HTTPS. Got: ' . $endpoint);
				return str_replace('http://', 'https://', $endpoint);
			}
			return $endpoint;
		}

		// Backblaze B2: auto-generate endpoint from region if not explicitly set
		if ($options['provider'] === 'b2' && empty($options['endpoint'])) {
			$region = $options['region'] ?: 'us-west-004';
			return "https://s3.{$region}.backblazeb2.com";
		}

		// DreamObjects: auto-generate endpoint
		if ($options['provider'] === 'dreamobjects' && empty($options['endpoint'])) {
			return 'https://objects-us-east-1.dream.io';
		}

		$region = $options['region'] ?: 'us-east-1';
		return "https://s3.{$region}.amazonaws.com";
	}

	protected function get_host() {
		$options = $this->get_options();
		$endpoint = $this->get_endpoint();
		$parsed = wp_parse_url($endpoint);
		return $parsed['host'];
	}

	/**
	 * Generate AWS Signature V4 for S3 requests.
	 */
	protected function sign_request($method, $uri, $headers, $payload_hash, $query = '') {
		$options = $this->get_options();
		$region = $options['region'] ?: 'us-east-1';
		$service = 's3';
		$now = gmdate('Ymd\THis\Z');
		$date = gmdate('Ymd');

		$credential_scope = "{$date}/{$region}/{$service}/aws4_request";
		$credential = $options['access_key'] . '/' . $credential_scope;

		// Canonical headers
		$headers['x-amz-date'] = $now;
		$headers['x-amz-content-sha256'] = $payload_hash;

		ksort($headers);
		$canonical_headers = '';
		$signed_headers_list = array();
		foreach ($headers as $k => $v) {
			$lk = strtolower($k);
			$canonical_headers .= $lk . ':' . trim($v) . "\n";
			$signed_headers_list[] = $lk;
		}
		$signed_headers = implode(';', $signed_headers_list);

		// Sort query string parameters as required by SigV4 spec
		if (!empty($query)) {
			parse_str($query, $qp);
			ksort($qp);
			$query = http_build_query($qp, '', '&', PHP_QUERY_RFC3986);
		}

		// Canonical request
		$canonical = "{$method}\n{$uri}\n{$query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
		$canonical_hash = hash('sha256', $canonical);

		// String to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$credential_scope}\n{$canonical_hash}";

		// Signing key
		$date_key = hash_hmac('sha256', $date, "AWS4{$options['secret_key']}", true);
		$region_key = hash_hmac('sha256', $region, $date_key, true);
		$service_key = hash_hmac('sha256', $service, $region_key, true);
		$signing_key = hash_hmac('sha256', 'aws4_request', $service_key, true);

		$signature = hash_hmac('sha256', $string_to_sign, $signing_key);

		$auth = "AWS4-HMAC-SHA256 Credential={$credential}, SignedHeaders={$signed_headers}, Signature={$signature}";

		$headers['Authorization'] = $auth;
		return $headers;
	}

	protected function request($method, $key, $body = '', $extra_headers = array()) {
		$options = $this->get_options();
		$endpoint = $this->get_endpoint();
		$bucket = $options['bucket'];
		$path = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';

		// Separate key from query string for proper SigV4 signing
		$query_string = '';
		$qpos = strpos($key, '?');
		if ($qpos !== false) {
			$query_string = substr($key, $qpos + 1);
			$key = substr($key, 0, $qpos);
		}

		$uri = "/{$bucket}/{$path}{$key}";

		// Hash all request bodies (not just PUT) for Backblaze B2 compatibility
		$payload_hash = (!empty($body))
			? hash('sha256', $body)
			: hash('sha256', ''); // SHA256 of empty string

		$headers = array_merge(array(
			'Host' => $this->get_host(),
		), $extra_headers);

		if ($method === 'PUT' || ($method === 'POST' && !empty($body))) {
			$headers['Content-Length'] = strlen($body);
		}

		$signed_headers = $this->sign_request($method, $uri, $headers, $payload_hash, $query_string);

		$url = $endpoint . $uri;
		if (!empty($query_string)) {
			$url .= '?' . $query_string;
		}

		$args = array(
			'method'  => $method,
			'headers' => $signed_headers,
			'body'    => ($method === 'PUT' || ($method === 'POST' && !empty($body))) ? $body : null,
			'timeout' => 300,
		);

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code >= 400) {
			$body_response = wp_remote_retrieve_body($response);
			return new WP_Error('s3_error', "S3 error (HTTP {$code}): {$body_response}");
		}

		return $response;
	}

	/**
	 * Build extra headers for storage class and server-side encryption.
	 *
	 * @return array Extra headers to include in PUT/initiate requests.
	 */
	protected function get_storage_headers() {
		$opts = $this->get_options();
		$extra_headers = array();

		$storage_class = isset($opts['storage_class']) ? $opts['storage_class'] : 'STANDARD';
		if ($storage_class !== 'STANDARD') {
			$extra_headers['x-amz-storage-class'] = $storage_class;
		}

		$sse = isset($opts['server_side_encryption']) ? $opts['server_side_encryption'] : false;
		if ($sse) {
			$extra_headers['x-amz-server-side-encryption'] = 'AES256';
		}

		return $extra_headers;
	}

	/**
	 * Build Object Lock headers for Backblaze B2 GOVERNANCE mode.
	 *
	 * @return array Extra headers for object lock, empty if not applicable.
	 */
	protected function get_object_lock_headers() {
		$opts = $this->get_options();
		if (empty($opts['provider']) || $opts['provider'] !== 'b2') {
			return array();
		}

		$days = isset($opts['object_lock_days']) ? intval($opts['object_lock_days']) : 0;
		if ($days <= 0) {
			return array();
		}

		// Cap at 3000 days max
		$days = min($days, 3000);

		$retain_until = gmdate('Y-m-d\TH:i:s\Z', time() + ($days * 86400));

		return array(
			'x-amz-object-lock-mode'            => 'GOVERNANCE',
			'x-amz-object-lock-retain-until-date' => $retain_until,
		);
	}

	public function backup($file) {
		$filesize = filesize($file);
		$key = basename($file);

		$storage_headers = $this->get_storage_headers();

		// B2 Object Lock headers
		$object_lock_headers = $this->get_object_lock_headers();
		$storage_headers = array_merge($storage_headers, $object_lock_headers);

		// For files under 10MB, simple PUT (reads entire file into memory)
		if ($filesize < 10485760) {
			$body = file_get_contents($file);
			$result = $this->request('PUT', $key, $body, array_merge(array(
				'Content-Type' => 'application/octet-stream',
			), $storage_headers));
			return is_wp_error($result) ? $result : true;
		}

		// For larger files, use multipart upload (streams in chunks)
		return $this->multipart_upload($file, $key);
	}

	protected function multipart_upload($file, $key) {
		$storage_headers = $this->get_storage_headers();

		// Initiate multipart - query string is separated by request()
		$init = $this->request('POST', $key . '?uploads', '', array_merge(array(
			'Content-Type' => 'application/octet-stream',
		), $storage_headers));

		if (is_wp_error($init)) return $init;

		$body = wp_remote_retrieve_body($init);
		if (!preg_match('/<UploadId>([^<]+)</', $body, $m)) {
			return new WP_Error('s3_multipart_init', 'Failed to initiate multipart upload.');
		}
		$upload_id = $m[1];

		$chunk_size = 10485760; // 10MB per part
		$fh = fopen($file, 'rb');
		$part_number = 1;
		$etags = array();

		while (!feof($fh)) {
			$chunk = fread($fh, $chunk_size);
			if (false === $chunk || strlen($chunk) === 0) break;

			$part_key = $key . "?partNumber={$part_number}&uploadId=" . urlencode($upload_id);
			$result = $this->request('PUT', $part_key, $chunk);

			if (is_wp_error($result)) {
				fclose($fh);
				// Abort multipart
				$this->request('DELETE', $key . '?uploadId=' . urlencode($upload_id));
				return $result;
			}

			$headers = wp_remote_retrieve_headers($result);
			$etag = isset($headers['etag']) ? trim($headers['etag'], '"') : '';
			if (empty($etag)) {
				fclose($fh);
				$this->request('DELETE', $key . '?uploadId=' . urlencode($upload_id));
				return new WP_Error('s3_missing_etag', "Missing ETag for part {$part_number}");
			}
			$etags[$part_number] = $etag;

			$part_number++;
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}
		fclose($fh);

		// Complete multipart upload
		$complete_xml = '<CompleteMultipartUpload>';
		foreach ($etags as $num => $etag) {
			$complete_xml .= "<Part><PartNumber>{$num}</PartNumber><ETag>\"{$etag}\"</ETag></Part>";
		}
		$complete_xml .= '</CompleteMultipartUpload>';

		$complete = $this->request('POST', $key . '?uploadId=' . urlencode($upload_id), $complete_xml, array(
			'Content-Type' => 'application/xml',
		));

		return is_wp_error($complete) ? $complete : true;
	}

	public function download($file, $dest_dir) {
		$key = $file;
		$local_path = trailingslashit($dest_dir) . basename($file);

		// Use streaming download to avoid loading entire file into memory
		$options = $this->get_options();
		$endpoint = $this->get_endpoint();
		$bucket = $options['bucket'];
		$path = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';
		$uri = "/{$bucket}/{$path}{$key}";
		$payload_hash = 'UNSIGNED-PAYLOAD';

		$headers = array('Host' => $this->get_host());
		$signed_headers = $this->sign_request('GET', $uri, $headers, $payload_hash);

		$url = $endpoint . $uri;

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
			return new WP_Error('s3_download', "S3 download failed (HTTP {$code})");
		}

		return true;
	}

	public function delete($files) {
		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			$result = $this->request('DELETE', $file);
			if (is_wp_error($result)) {
				$errors[] = $file . ': ' . $result->get_error_message();
			}
		}

		if (!empty($errors)) {
			return new WP_Error('s3_delete', 'Failed to delete: ' . implode('; ', $errors));
		}

		return true;
	}

	public function listfiles() {
		$options = $this->get_options();
		$prefix = ltrim($options['path'], '/');
		if (!empty($prefix)) $prefix = rtrim($prefix, '/') . '/';

		$query = '?list-type=2&prefix=' . rawurlencode($prefix) . '&max-keys=1000';
		$response = $this->request('GET', $query);

		if (is_wp_error($response)) return array();

		$body = wp_remote_retrieve_body($response);
		$files = array();

		// Parse XML response
		if (preg_match_all('/<Key>([^<]+)<\/Key>/', $body, $matches)) {
			foreach ($matches[1] as $key) {
				$filename = basename($key);
				if (!empty($filename)) {
					$files[] = $filename;
				}
			}
		}

		return $files;
	}

	public function credentials_test() {
		$result = $this->request('HEAD', '');
		return is_wp_error($result) ? $result : true;
	}
}
