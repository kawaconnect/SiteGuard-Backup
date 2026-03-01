<?php
/**
 * SiteGuard Backup - OpenStack Swift / Rackspace Cloud Files Storage Method
 *
 * Uses OpenStack Swift REST API with Keystone authentication.
 * Compatible with Rackspace Cloud Files via v2 identity endpoint.
 * No SDK dependency - pure REST via wp_remote_request().
 * Supports: upload (PUT object, chunked via Static Large Objects), download, delete, list.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Openstack extends SiteGuard_Backup_Module {

	/** @var int Chunk size for Static Large Objects (100MB) */
	private $slo_chunk_size = 104857600;

	/** @var int Token cache duration in seconds (23 hours) */
	private $token_cache_duration = 82800;

	public function get_default_options() {
		return array(
			'auth_url'       => '',
			'username'       => '',
			'password'       => '',
			'api_key'        => '',
			'tenant'         => '',
			'region'         => '',
			'container'      => '',
			'path'           => '',
			'auth_version'   => 'v2',
			'instance_label' => '',
			'instance_enabled' => '1',
		);
	}

	/**
	 * Detect whether to use Rackspace-specific authentication.
	 *
	 * @return bool
	 */
	private function is_rackspace() {
		$options = $this->get_options();
		return (false !== strpos($options['auth_url'], 'rackspacecloud.com'));
	}

	/**
	 * Get the Keystone auth URL, normalized.
	 *
	 * @return string
	 */
	private function get_auth_url() {
		$options = $this->get_options();
		$url = rtrim($options['auth_url'], '/');

		// For Rackspace, ensure we use the identity endpoint
		if ($this->is_rackspace() && false === strpos($url, '/v2.0')) {
			$url = 'https://identity.api.rackspacecloud.com/v2.0';
		}

		return $url;
	}

	/**
	 * Get the object path within the container, including any directory prefix.
	 *
	 * @param string $object_name The object filename.
	 * @return string
	 */
	private function get_object_path($object_name) {
		$options = $this->get_options();
		$prefix = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';
		return $prefix . $object_name;
	}

	/**
	 * Authenticate with Keystone and get token + storage endpoint.
	 *
	 * Returns array with 'token' and 'storage_url' keys, or WP_Error on failure.
	 *
	 * @param bool $force_refresh Skip cached token.
	 * @return array|WP_Error
	 */
	private function authenticate($force_refresh = false) {
		$opts = SiteGuard_Backup_Options::instance();

		if (!$force_refresh) {
			$cached = $opts->get_transient('sgbackup_openstack_auth');
			if (!empty($cached) && is_array($cached) && !empty($cached['token']) && !empty($cached['storage_url'])) {
				return $cached;
			}
		}

		$options = $this->get_options();
		$auth_url = $this->get_auth_url();
		$auth_version = $options['auth_version'];

		if ('v3' === $auth_version) {
			$result = $this->authenticate_v3($auth_url, $options);
		} else {
			$result = $this->authenticate_v2($auth_url, $options);
		}

		if (is_wp_error($result)) {
			return $result;
		}

		// Cache the token
		$opts->set_transient('sgbackup_openstack_auth', $result, $this->token_cache_duration);

		return $result;
	}

	/**
	 * Keystone v2.0 authentication.
	 *
	 * @param string $auth_url Base auth URL.
	 * @param array  $options  Plugin options.
	 * @return array|WP_Error
	 */
	private function authenticate_v2($auth_url, $options) {
		$url = $auth_url . '/tokens';

		// Build credentials body
		if ($this->is_rackspace() && !empty($options['api_key'])) {
			// Rackspace: use API key auth
			$body = array(
				'auth' => array(
					'RAX-KSKEY:apiKeyCredentials' => array(
						'username' => $options['username'],
						'apiKey'   => $options['api_key'],
					),
				),
			);
		} else {
			// Standard Keystone v2
			$credentials = array(
				'username' => $options['username'],
				'password' => $options['password'],
			);
			$body = array(
				'auth' => array(
					'passwordCredentials' => $credentials,
				),
			);
			if (!empty($options['tenant'])) {
				$body['auth']['tenantName'] = $options['tenant'];
			}
		}

		$response = wp_remote_post($url, array(
			'headers' => array('Content-Type' => 'application/json'),
			'body'    => wp_json_encode($body),
			'timeout' => 30,
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$resp_body = wp_remote_retrieve_body($response);
		$data = json_decode($resp_body, true);

		if ($code < 200 || $code >= 300 || empty($data)) {
			$msg = isset($data['error']['message']) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error('auth_failed', 'OpenStack v2 authentication failed: ' . $msg);
		}

		$token = isset($data['access']['token']['id']) ? $data['access']['token']['id'] : '';
		if (empty($token)) {
			return new WP_Error('auth_failed', 'No token in v2 auth response');
		}

		// Find the object-store endpoint
		$storage_url = $this->find_storage_endpoint_v2($data, $options['region']);
		if (is_wp_error($storage_url)) {
			return $storage_url;
		}

		return array('token' => $token, 'storage_url' => $storage_url);
	}

	/**
	 * Find the object-store (Swift) endpoint from v2 service catalog.
	 *
	 * @param array  $data   Decoded auth response.
	 * @param string $region Preferred region.
	 * @return string|WP_Error
	 */
	private function find_storage_endpoint_v2($data, $region) {
		if (empty($data['access']['serviceCatalog'])) {
			return new WP_Error('no_catalog', 'No service catalog in auth response');
		}

		foreach ($data['access']['serviceCatalog'] as $service) {
			if (!isset($service['type']) || $service['type'] !== 'object-store') continue;
			if (empty($service['endpoints'])) continue;

			// Try to find matching region
			$fallback_url = '';
			foreach ($service['endpoints'] as $endpoint) {
				$url = isset($endpoint['publicURL']) ? $endpoint['publicURL'] : '';
				if (empty($url)) continue;

				$fallback_url = $url;
				if (!empty($region) && isset($endpoint['region']) && strtolower($endpoint['region']) === strtolower($region)) {
					return rtrim($url, '/');
				}
			}

			// No region match, use first available
			if (!empty($fallback_url)) {
				return rtrim($fallback_url, '/');
			}
		}

		return new WP_Error('no_endpoint', 'No object-store endpoint found in service catalog');
	}

	/**
	 * Keystone v3 authentication.
	 *
	 * @param string $auth_url Base auth URL.
	 * @param array  $options  Plugin options.
	 * @return array|WP_Error
	 */
	private function authenticate_v3($auth_url, $options) {
		$url = rtrim($auth_url, '/') . '/auth/tokens';

		$identity = array(
			'methods' => array('password'),
			'password' => array(
				'user' => array(
					'name'     => $options['username'],
					'password' => $options['password'],
					'domain'   => array('id' => 'default'),
				),
			),
		);

		$auth = array('identity' => $identity);

		if (!empty($options['tenant'])) {
			$auth['scope'] = array(
				'project' => array(
					'name'   => $options['tenant'],
					'domain' => array('id' => 'default'),
				),
			);
		}

		$body = array('auth' => $auth);

		$response = wp_remote_post($url, array(
			'headers' => array('Content-Type' => 'application/json'),
			'body'    => wp_json_encode($body),
			'timeout' => 30,
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$resp_body = wp_remote_retrieve_body($response);
		$data = json_decode($resp_body, true);

		if ($code < 200 || $code >= 300 || empty($data)) {
			$msg = isset($data['error']['message']) ? $data['error']['message'] : "HTTP {$code}";
			return new WP_Error('auth_failed', 'OpenStack v3 authentication failed: ' . $msg);
		}

		// v3 token is returned in the X-Subject-Token header
		$token = wp_remote_retrieve_header($response, 'x-subject-token');
		if (empty($token)) {
			return new WP_Error('auth_failed', 'No X-Subject-Token in v3 auth response');
		}

		// Find object-store endpoint from catalog
		$storage_url = $this->find_storage_endpoint_v3($data, $options['region']);
		if (is_wp_error($storage_url)) {
			return $storage_url;
		}

		return array('token' => $token, 'storage_url' => $storage_url);
	}

	/**
	 * Find the object-store endpoint from v3 service catalog.
	 *
	 * @param array  $data   Decoded auth response.
	 * @param string $region Preferred region.
	 * @return string|WP_Error
	 */
	private function find_storage_endpoint_v3($data, $region) {
		if (empty($data['token']['catalog'])) {
			return new WP_Error('no_catalog', 'No service catalog in v3 auth response');
		}

		foreach ($data['token']['catalog'] as $service) {
			if (!isset($service['type']) || $service['type'] !== 'object-store') continue;
			if (empty($service['endpoints'])) continue;

			$fallback_url = '';
			foreach ($service['endpoints'] as $endpoint) {
				if (isset($endpoint['interface']) && $endpoint['interface'] !== 'public') continue;
				$url = isset($endpoint['url']) ? $endpoint['url'] : '';
				if (empty($url)) continue;

				$fallback_url = $url;
				if (!empty($region) && isset($endpoint['region_id']) && strtolower($endpoint['region_id']) === strtolower($region)) {
					return rtrim($url, '/');
				}
				if (!empty($region) && isset($endpoint['region']) && strtolower($endpoint['region']) === strtolower($region)) {
					return rtrim($url, '/');
				}
			}

			if (!empty($fallback_url)) {
				return rtrim($fallback_url, '/');
			}
		}

		return new WP_Error('no_endpoint', 'No object-store endpoint found in v3 service catalog');
	}

	/**
	 * Make an authenticated request to Swift.
	 *
	 * @param string $method    HTTP method.
	 * @param string $url       Full URL.
	 * @param array  $args      Extra args for wp_remote_request.
	 * @param bool   $retry     Whether to retry on 401 with fresh token.
	 * @return array|WP_Error   wp_remote_request response or error.
	 */
	private function swift_request($method, $url, $args = array(), $retry = true) {
		$auth = $this->authenticate();
		if (is_wp_error($auth)) {
			return $auth;
		}

		if (!isset($args['headers'])) $args['headers'] = array();
		$args['headers']['X-Auth-Token'] = $auth['token'];
		$args['method'] = $method;
		if (!isset($args['timeout'])) $args['timeout'] = 60;

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);

		// Re-authenticate on 401 and retry once
		if (401 === $code && $retry) {
			$auth = $this->authenticate(true);
			if (is_wp_error($auth)) return $auth;

			$args['headers']['X-Auth-Token'] = $auth['token'];
			$response = wp_remote_request($url, $args);
		}

		return $response;
	}

	/**
	 * Upload a backup file to Swift container.
	 *
	 * Uses PUT for files under 100MB, Static Large Object (SLO) for larger files.
	 *
	 * @param string $file Full path to the file to upload.
	 * @return bool|WP_Error
	 */
	public function backup($file) {
		$auth = $this->authenticate();
		if (is_wp_error($auth)) return $auth;

		$options = $this->get_options();
		$object_name = $this->get_object_path(basename($file));
		$file_size = filesize($file);

		// Use SLO for large files
		if ($file_size > $this->slo_chunk_size) {
			return $this->upload_slo($file, $object_name, $auth);
		}

		// Simple PUT for smaller files
		$url = $auth['storage_url'] . '/' . rawurlencode($options['container']) . '/' . $this->encode_object_path($object_name);

		$file_contents = file_get_contents($file);
		if (false === $file_contents) {
			return new WP_Error('read_failed', 'Could not read file: ' . $file);
		}

		$response = $this->swift_request('PUT', $url, array(
			'headers' => array(
				'Content-Type'   => 'application/octet-stream',
				'Content-Length' => $file_size,
				'ETag'           => md5($file_contents),
			),
			'body'    => $file_contents,
			'timeout' => 300,
		));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return new WP_Error('upload_failed', 'Swift upload failed with HTTP ' . $code);
		}

		return true;
	}

	/**
	 * Upload a large file using Static Large Objects (SLO).
	 *
	 * @param string $file        Full path to file.
	 * @param string $object_name Object name in container.
	 * @param array  $auth        Auth credentials.
	 * @return bool|WP_Error
	 */
	private function upload_slo($file, $object_name, $auth) {
		$options = $this->get_options();
		$container = rawurlencode($options['container']);
		$file_size = filesize($file);
		$fh = fopen($file, 'rb');
		if (!$fh) {
			return new WP_Error('read_failed', 'Could not open file: ' . $file);
		}

		$sgbackup = SiteGuard_Backup::instance();
		$manifest = array();
		$segment_index = 0;
		$offset = 0;

		while ($offset < $file_size) {
			// Re-fetch auth each iteration so storage_url stays fresh after token refresh
			$auth = $this->authenticate();
			if (is_wp_error($auth)) {
				fclose($fh);
				return $auth;
			}

			$chunk = fread($fh, $this->slo_chunk_size);
			if (false === $chunk) {
				fclose($fh);
				return new WP_Error('read_failed', 'Failed to read chunk from file');
			}

			$chunk_size = strlen($chunk);
			$segment_name = $object_name . '/slo/' . str_pad($segment_index, 8, '0', STR_PAD_LEFT);
			$segment_etag = md5($chunk);
			$segment_url = $auth['storage_url'] . '/' . $container . '/' . $this->encode_object_path($segment_name);

			$response = $this->swift_request('PUT', $segment_url, array(
				'headers' => array(
					'Content-Type'   => 'application/octet-stream',
					'Content-Length' => $chunk_size,
					'ETag'           => $segment_etag,
				),
				'body'    => $chunk,
				'timeout' => 300,
			));

			if (is_wp_error($response)) {
				fclose($fh);
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			if ($code < 200 || $code >= 300) {
				fclose($fh);
				return new WP_Error('upload_failed', 'SLO segment upload failed with HTTP ' . $code);
			}

			$manifest[] = array(
				'path'       => '/' . $options['container'] . '/' . $segment_name,
				'etag'       => $segment_etag,
				'size_bytes' => $chunk_size,
			);

			$offset += $chunk_size;
			$segment_index++;

			$sgbackup->log("SLO segment {$segment_index} uploaded (" .
				SiteGuard_Backup_Filesystem::format_bytes($offset) . ' / ' .
				SiteGuard_Backup_Filesystem::format_bytes($file_size) . ')');

			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		fclose($fh);

		// Create the SLO manifest object - refresh auth for fresh storage_url
		$auth = $this->authenticate();
		if (is_wp_error($auth)) return $auth;
		$manifest_url = $auth['storage_url'] . '/' . $container . '/' . $this->encode_object_path($object_name) . '?multipart-manifest=put';
		$manifest_body = wp_json_encode($manifest);

		$response = $this->swift_request('PUT', $manifest_url, array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'Content-Length' => strlen($manifest_body),
			),
			'body'    => $manifest_body,
			'timeout' => 120,
		));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return new WP_Error('upload_failed', 'SLO manifest upload failed with HTTP ' . $code);
		}

		return true;
	}

	/**
	 * Download a backup file from Swift container.
	 *
	 * @param string $file     Filename to download.
	 * @param string $dest_dir Local destination directory.
	 * @return bool|WP_Error
	 */
	public function download($file, $dest_dir) {
		$auth = $this->authenticate();
		if (is_wp_error($auth)) return $auth;

		$options = $this->get_options();
		$object_name = $this->get_object_path($file);
		$url = $auth['storage_url'] . '/' . rawurlencode($options['container']) . '/' . $this->encode_object_path($object_name);

		$dest_path = trailingslashit($dest_dir) . $file;

		$response = $this->swift_request('GET', $url, array(
			'timeout'  => 300,
			'stream'   => true,
			'filename' => $dest_path,
		));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			@unlink($dest_path);
			return new WP_Error('download_failed', 'Swift download failed with HTTP ' . $code);
		}

		return true;
	}

	/**
	 * Delete file(s) from Swift container.
	 *
	 * @param array|string $files Filename(s) to delete.
	 * @return bool|WP_Error
	 */
	public function delete($files) {
		$auth = $this->authenticate();
		if (is_wp_error($auth)) return $auth;

		$options = $this->get_options();
		if (!is_array($files)) $files = array($files);

		foreach ($files as $file) {
			$object_name = $this->get_object_path($file);
			$url = $auth['storage_url'] . '/' . rawurlencode($options['container']) . '/' . $this->encode_object_path($object_name);

			// Delete with multipart-manifest=delete to clean up SLO segments too
			$response = $this->swift_request('DELETE', $url . '?multipart-manifest=delete', array(
				'timeout' => 60,
			));

			if (is_wp_error($response)) return $response;

			$code = wp_remote_retrieve_response_code($response);
			// 204 = deleted, 404 = already gone (both acceptable)
			if ($code !== 204 && $code !== 404) {
				return new WP_Error('delete_failed', 'Swift delete failed with HTTP ' . $code . ' for ' . $file);
			}
		}

		return true;
	}

	/**
	 * List files in the Swift container.
	 *
	 * @return array List of filenames.
	 */
	public function listfiles() {
		$auth = $this->authenticate();
		if (is_wp_error($auth)) return array();

		$options = $this->get_options();
		$prefix = !empty($options['path']) ? trim($options['path'], '/') . '/' : '';
		$url = $auth['storage_url'] . '/' . rawurlencode($options['container']) . '?format=json';

		if (!empty($prefix)) {
			$url .= '&prefix=' . rawurlencode($prefix);
		}

		$response = $this->swift_request('GET', $url, array('timeout' => 30));
		if (is_wp_error($response)) return array();

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) return array();

		$body = wp_remote_retrieve_body($response);
		$items = json_decode($body, true);
		if (!is_array($items)) return array();

		$files = array();
		foreach ($items as $item) {
			if (!isset($item['name'])) continue;
			// Strip prefix to return just the filename
			$name = $item['name'];
			if (!empty($prefix) && 0 === strpos($name, $prefix)) {
				$name = substr($name, strlen($prefix));
			}
			if (!empty($name)) {
				$files[] = $name;
			}
		}

		return $files;
	}

	/**
	 * Test credentials by authenticating and listing the container.
	 *
	 * @return bool|WP_Error
	 */
	public function credentials_test() {
		$options = $this->get_options();

		if (empty($options['auth_url'])) {
			return new WP_Error('missing_config', 'Authentication URL is required');
		}
		if (empty($options['username'])) {
			return new WP_Error('missing_config', 'Username is required');
		}
		if (empty($options['password']) && empty($options['api_key'])) {
			return new WP_Error('missing_config', 'Password or API key is required');
		}
		if (empty($options['container'])) {
			return new WP_Error('missing_config', 'Container name is required');
		}

		// Force re-authentication
		$auth = $this->authenticate(true);
		if (is_wp_error($auth)) return $auth;

		// Try to list the container (HEAD request)
		$url = $auth['storage_url'] . '/' . rawurlencode($options['container']);
		$response = $this->swift_request('HEAD', $url, array('timeout' => 15));

		if (is_wp_error($response)) return $response;

		$code = wp_remote_retrieve_response_code($response);
		if (404 === $code) {
			return new WP_Error('not_found', 'Container "' . $options['container'] . '" not found. Please create it first.');
		}
		if ($code < 200 || $code >= 300) {
			return new WP_Error('test_failed', 'Container access failed with HTTP ' . $code);
		}

		$object_count = wp_remote_retrieve_header($response, 'x-container-object-count');
		$bytes_used = wp_remote_retrieve_header($response, 'x-container-bytes-used');

		return true;
	}

	/**
	 * Encode an object path for use in URLs, preserving forward slashes.
	 *
	 * @param string $path Object path.
	 * @return string
	 */
	private function encode_object_path($path) {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}
}
