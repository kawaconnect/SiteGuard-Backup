<?php
/**
 * SiteGuard Backup - S3-Compatible Storage Method
 *
 * Supports AWS S3, DigitalOcean Spaces, MinIO, DreamObjects and Backblaze B2.
 * Uses wp_remote_request() for S3 API calls (no SDK dependency).
 *
 * Requests are signed with AWS Signature Version 4:
 * https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_S3 extends SiteGuard_Backup_Module {

	/** S3 minimum part size (all parts but the last) and maximum part count. */
	const MIN_PART_SIZE = 5242880;
	const MAX_PARTS     = 10000;

	/** @var string|null Region learned from the service (AWS bucket-region hint). */
	protected $region_override = null;

	/** @var int Seconds added to local time when signing (learned from RequestTimeTooSkewed). */
	protected $clock_offset = 0;

	/** @var bool Whether the http:// endpoint notice has been logged. */
	private $insecure_noted = false;

	public function get_default_options() {
		return array(
			'access_key'            => '',
			'secret_key'            => '',
			'bucket'                => '',
			'region'                => 'us-east-1',
			'path'                  => '',
			'endpoint'              => '', // Custom endpoint for S3-compatible services
			'provider'              => 'aws', // aws, spaces, minio, dreamobjects, b2
			'storage_class'         => 'STANDARD', // STANDARD, STANDARD_IA, INTELLIGENT_TIERING, ONEZONE_IA, GLACIER_IR, REDUCED_REDUNDANCY
			'object_lock_days'      => 0, // B2 Object Lock: 0=disabled, 1-3000 days
			'server_side_encryption' => false,
			'allow_insecure'        => false, // allow an http:// custom endpoint (e.g. MinIO on a private network)
		);
	}

	/**
	 * Log through the job log when one is open, otherwise the PHP error log.
	 */
	protected function note($message, $level = 'warning') {
		if (class_exists('SiteGuard_Backup')) {
			SiteGuard_Backup::instance()->log($message, $level);
		} else {
			error_log('SiteGuard Backup: ' . $message);
		}
	}

	/**
	 * Normalise a configured endpoint URL. http:// is upgraded to https:// unless the
	 * admin explicitly allowed an unencrypted endpoint, so credentials and archives
	 * never travel in cleartext by accident.
	 */
	protected function normalise_endpoint($endpoint) {
		$endpoint = rtrim(trim((string) $endpoint), '/');
		if ('' === $endpoint) return '';
		if (!preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://' . $endpoint;
		}
		if (0 === stripos($endpoint, 'http://')) {
			$options = $this->get_options();
			if (!empty($options['allow_insecure'])) {
				if (!$this->insecure_noted) {
					$this->insecure_noted = true;
					$this->note('S3: using unencrypted endpoint ' . $endpoint . ' because "allow insecure" is enabled.');
				}
				return $endpoint;
			}
			error_log('SiteGuard Backup: S3 custom endpoint must use HTTPS; upgrading ' . $endpoint . ' to https://');
			$endpoint = 'https://' . substr($endpoint, 7);
		}
		return $endpoint;
	}

	/**
	 * Base endpoint URL (scheme + host [+ port]), or '' if it cannot be determined.
	 */
	protected function get_endpoint() {
		$options = $this->get_options();
		if (!empty($options['endpoint'])) {
			return $this->normalise_endpoint($options['endpoint']);
		}

		$provider = isset($options['provider']) ? $options['provider'] : 'aws';
		$region   = trim((string) (isset($options['region']) ? $options['region'] : ''));

		switch ($provider) {
			case 'b2':
				// https://www.backblaze.com/docs/cloud-storage-call-the-s3-compatible-api
				return 'https://s3.' . ($region ?: 'us-west-004') . '.backblazeb2.com';

			case 'dreamobjects':
				// https://help.dreamhost.com/hc/en-us/articles/360001370846 (cluster us-east-005)
				if ('' === $region || 'us-east-1' === $region) $region = 'us-east-005';
				return 'https://s3.' . $region . '.dream.io';

			case 'spaces':
				// https://docs.digitalocean.com/products/spaces/how-to/use-aws-sdks/
				// The datacenter (nyc3, fra1, ...) is selected by the endpoint host.
				if (preg_match('/^[a-z]{3}\d$/', $region)) {
					return 'https://' . $region . '.digitaloceanspaces.com';
				}
				return '';

			case 'minio':
				return ''; // MinIO is self-hosted; an endpoint is required.
		}

		return 'https://s3.' . $this->get_signing_region() . '.amazonaws.com';
	}

	/**
	 * Region used in the SigV4 credential scope.
	 */
	protected function get_signing_region() {
		if (null !== $this->region_override) return $this->region_override;

		$options  = $this->get_options();
		$provider = isset($options['provider']) ? $options['provider'] : 'aws';
		$region   = trim((string) (isset($options['region']) ? $options['region'] : ''));

		if ('spaces' === $provider) {
			// DigitalOcean: "set region to us-east-1 ... the datacenter region is
			// determined by the endpoint value."
			return 'us-east-1';
		}
		if ('dreamobjects' === $provider && ('' === $region || 'us-east-1' === $region)) {
			return 'us-east-005';
		}
		if ('gcs' === $provider && '' === $region) {
			return 'auto';
		}
		return '' !== $region ? $region : 'us-east-1';
	}

	/**
	 * Use virtual-hosted-style addressing (bucket in the host name)?
	 *
	 * AWS still accepts path-style URLs but has announced they will be discontinued,
	 * so AWS buckets with DNS-compatible names use virtual-hosted style. Dotted
	 * names break the *.s3 wildcard TLS certificate and stay on path-style, as do
	 * all third-party endpoints (MinIO, GCS, B2, R2, Spaces all accept path-style).
	 * https://docs.aws.amazon.com/AmazonS3/latest/userguide/VirtualHosting.html
	 */
	protected function use_virtual_host() {
		$options  = $this->get_options();
		$provider = isset($options['provider']) ? $options['provider'] : 'aws';
		if ('aws' !== $provider || !empty($options['endpoint'])) return false;
		return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', (string) $options['bucket']);
	}

	/**
	 * Host header value for an endpoint: host plus port when it is not the default.
	 */
	protected function host_header($endpoint) {
		$parsed = wp_parse_url($endpoint);
		$host = isset($parsed['host']) ? $parsed['host'] : '';
		if (!empty($parsed['port'])) {
			$default = (isset($parsed['scheme']) && 'http' === strtolower($parsed['scheme'])) ? 80 : 443;
			if ((int) $parsed['port'] !== $default) {
				$host .= ':' . (int) $parsed['port'];
			}
		}
		return $host;
	}

	protected function get_host() {
		return $this->host_header($this->get_endpoint());
	}

	/**
	 * Encode a URI path per segment (RFC 3986), preserving forward slashes.
	 *
	 * SigV4 requires the canonical URI to be the URI-encoded path exactly as
	 * sent on the wire (S3 encodes once), so the same encoded string is used for
	 * both the canonical request and the request URL.
	 *
	 * @param string $path URI path (e.g. /bucket/dir/file name.zip).
	 * @return string Encoded path.
	 */
	protected function encode_uri_path($path) {
		return implode('/', array_map('rawurlencode', explode('/', $path)));
	}

	/**
	 * SigV4 canonical query string: each name and value URI-encoded, sorted by
	 * encoded name (then value); a bare subresource such as "uploads" becomes
	 * "uploads=". The same string is sent on the wire so both sides agree.
	 */
	protected function canonical_query($query) {
		$query = ltrim((string) $query, '?');
		if ('' === $query) return '';

		$pairs = array();
		foreach (explode('&', $query) as $part) {
			if ('' === $part) continue;
			$kv = explode('=', $part, 2);
			$pairs[] = array(
				rawurlencode(rawurldecode($kv[0])),
				isset($kv[1]) ? rawurlencode(rawurldecode($kv[1])) : '',
			);
		}
		usort($pairs, function ($a, $b) {
			$c = strcmp($a[0], $b[0]);
			return (0 !== $c) ? $c : strcmp($a[1], $b[1]);
		});

		$out = array();
		foreach ($pairs as $p) {
			$out[] = $p[0] . '=' . $p[1];
		}
		return implode('&', $out);
	}

	/**
	 * Generate AWS Signature V4 for S3 requests.
	 *
	 * @param string $method       HTTP method.
	 * @param string $uri          Encoded canonical URI.
	 * @param array  $headers      Request headers (must include Host).
	 * @param string $payload_hash Hex SHA-256 of the body.
	 * @param string $query        Canonical query string (see canonical_query()).
	 * @return array Headers including x-amz-date, x-amz-content-sha256 and Authorization.
	 */
	protected function sign_request($method, $uri, $headers, $payload_hash, $query = '') {
		$options = $this->get_options();
		$region = $this->get_signing_region();
		$service = 's3';
		$time = time() + (int) $this->clock_offset;
		$now = gmdate('Ymd\THis\Z', $time);
		$date = gmdate('Ymd', $time);

		$credential_scope = "{$date}/{$region}/{$service}/aws4_request";
		$credential = $options['access_key'] . '/' . $credential_scope;

		$headers['x-amz-date'] = $now;
		$headers['x-amz-content-sha256'] = $payload_hash;

		// Canonical headers: lowercase names sorted by character code; values
		// trimmed with sequential spaces collapsed to one.
		$lowered = array();
		foreach ($headers as $k => $v) {
			$lowered[strtolower($k)] = preg_replace('/\s+/', ' ', trim((string) $v));
		}
		ksort($lowered, SORT_STRING);

		$canonical_headers = '';
		foreach ($lowered as $lk => $v) {
			$canonical_headers .= $lk . ':' . $v . "\n";
		}
		$signed_headers = implode(';', array_keys($lowered));

		$canonical = "{$method}\n{$uri}\n{$query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
		$string_to_sign = "AWS4-HMAC-SHA256\n{$now}\n{$credential_scope}\n" . hash('sha256', $canonical);

		$date_key = hash_hmac('sha256', $date, 'AWS4' . $options['secret_key'], true);
		$region_key = hash_hmac('sha256', $region, $date_key, true);
		$service_key = hash_hmac('sha256', $service, $region_key, true);
		$signing_key = hash_hmac('sha256', 'aws4_request', $service_key, true);
		$signature = hash_hmac('sha256', $string_to_sign, $signing_key);

		$headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$credential}, SignedHeaders={$signed_headers}, Signature={$signature}";
		return $headers;
	}

	/**
	 * Build the request target.
	 *
	 * @return array|WP_Error array(url, canonical uri, host header, canonical query)
	 */
	protected function build_target($key, $bucket_level, $query_string) {
		$options = $this->get_options();
		$endpoint = $this->get_endpoint();
		if ('' === $endpoint) {
			return new WP_Error('s3_config', 'S3: no endpoint could be determined. Enter the endpoint URL (required for MinIO, and for DigitalOcean Spaces unless Region is the datacenter slug such as nyc3).');
		}
		$bucket = (string) $options['bucket'];
		if ('' === $bucket) {
			return new WP_Error('s3_config', 'S3: no bucket configured.');
		}

		// Bucket-level operations (ListObjects, HeadBucket) must not have the
		// configured folder prefix inserted into the URI.
		$path = (!$bucket_level && !empty($options['path'])) ? trim($options['path'], '/') . '/' : '';

		if ($this->use_virtual_host()) {
			$parsed = wp_parse_url($endpoint);
			$endpoint = $parsed['scheme'] . '://' . $bucket . '.' . $parsed['host'] . (!empty($parsed['port']) ? ':' . (int) $parsed['port'] : '');
			$raw_path = $bucket_level ? '/' : "/{$path}{$key}";
		} else {
			$raw_path = $bucket_level ? "/{$bucket}" : "/{$bucket}/{$path}{$key}";
		}

		$uri = $this->encode_uri_path($raw_path);
		$query = $this->canonical_query($query_string);
		$url = $endpoint . $uri . ('' !== $query ? '?' . $query : '');

		return array($url, $uri, $this->host_header($endpoint), $query);
	}

	/**
	 * Region the service says the bucket lives in, if the response carries one.
	 */
	protected function region_hint($response, $body) {
		$hint = (string) wp_remote_retrieve_header($response, 'x-amz-bucket-region');
		if ('' === $hint && preg_match('#<Region>([a-z0-9-]+)</Region>#i', $body, $m)) {
			$hint = $m[1];
		}
		if ('' === $hint && preg_match("/expecting '([a-z0-9-]+)'/i", $body, $m)) {
			$hint = $m[1];
		}
		return preg_match('/^[a-z0-9-]{2,32}$/', $hint) ? $hint : '';
	}

	/**
	 * Clock offset implied by a RequestTimeTooSkewed error (0 if not applicable).
	 */
	protected function skew_offset($response, $body, $code) {
		$server = 0;
		if (false !== stripos($body, 'RequestTimeTooSkewed')) {
			if (preg_match('#<ServerTime>([^<]+)</ServerTime>#', $body, $m)) {
				$server = strtotime($m[1]);
			}
		} elseif (403 !== (int) $code || '' !== $body) {
			return 0; // Only a bodyless 403 (HEAD) is judged by the Date header alone.
		}
		if (!$server) {
			$date = wp_remote_retrieve_header($response, 'date');
			$server = $date ? strtotime($date) : 0;
		}
		if (!$server) return 0;
		$offset = $server - time();
		return (abs($offset) > 300) ? $offset : 0;
	}

	/**
	 * Short "Code: Message" summary of an S3 XML error body.
	 */
	protected function error_summary($body) {
		$code = preg_match('#<Code>([^<]*)</Code>#', $body, $m) ? $m[1] : '';
		$msg  = preg_match('#<Message>([^<]*)</Message>#', $body, $m) ? $m[1] : '';
		if ('' !== $code || '' !== $msg) {
			return trim($code . ': ' . html_entity_decode($msg, ENT_QUOTES | ENT_XML1), ': ');
		}
		return substr(trim(strip_tags($body)), 0, 300);
	}

	/**
	 * Perform a signed request. Any non-2xx status is an error (redirects are not
	 * followed: a re-signed request would be needed for another host).
	 *
	 * @param string $method        HTTP method.
	 * @param string $key           Object key, optionally followed by "?query".
	 * @param string $body          Request body.
	 * @param array  $extra_headers Extra headers to send (and sign).
	 * @param bool   $bucket_level  Address the bucket rather than an object.
	 * @param array  $transport     Extra wp_remote_request() args (e.g. stream/filename).
	 * @return array|WP_Error
	 */
	protected function request($method, $key, $body = '', $extra_headers = array(), $bucket_level = false, $transport = array()) {
		$query_string = '';
		$qpos = strpos($key, '?');
		if (false !== $qpos) {
			$query_string = substr($key, $qpos + 1);
			$key = substr($key, 0, $qpos);
		}

		$body = (string) $body;
		$has_body = ('PUT' === $method || 'POST' === $method);
		$streamed = !empty($transport['stream']) && !empty($transport['filename']);
		$region_retried = false;
		$skew_retried = false;

		for ($attempt = 0; $attempt < 3; $attempt++) {
			$target = $this->build_target($key, $bucket_level, $query_string);
			if (is_wp_error($target)) return $target;
			list($url, $uri, $host, $query) = $target;

			$headers = array_merge(array('Host' => $host), $extra_headers);
			if ($has_body) {
				$headers['Content-Length'] = strlen($body);
				// Content-MD5 lets the service reject a corrupted upload, and is
				// required by S3 for objects with an Object Lock retention period.
				if ('' !== $body && !isset($headers['Content-MD5'])) {
					$headers['Content-MD5'] = base64_encode(md5($body, true));
				}
			}

			$signed = $this->sign_request($method, $uri, $headers, hash('sha256', $body), $query);

			$args = array_merge(array(
				'method'      => $method,
				'headers'     => $signed,
				'body'        => $has_body ? $body : null,
				'timeout'     => 300,
				'redirection' => 0,
			), $transport);

			$response = wp_remote_request($url, $args);

			if (is_wp_error($response)) {
				if ($streamed) @unlink($transport['filename']);
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			if ($code >= 200 && $code < 300) {
				return $response;
			}

			$err_body = $streamed
				? (string) @file_get_contents($transport['filename'], false, null, 0, 8192)
				: (string) wp_remote_retrieve_body($response);
			if ($streamed) @unlink($transport['filename']);

			// Wrong region: AWS answers 301/400 with the bucket's real region. Follow
			// it once when the endpoint is derived from the region (plain AWS).
			$hint = $this->region_hint($response, $err_body);
			if (!$region_retried && '' !== $hint && $hint !== $this->get_signing_region()) {
				$options = $this->get_options();
				$provider = isset($options['provider']) ? $options['provider'] : 'aws';
				if ('aws' === $provider && empty($options['endpoint'])) {
					$region_retried = true;
					$this->note(sprintf('S3: bucket %s is in region %s, not %s; using %s. Update the Region setting to avoid this extra request.', $options['bucket'], $hint, $this->get_signing_region(), $hint));
					$this->region_override = $hint;
					continue;
				}
				return new WP_Error('s3_error', "S3 error (HTTP {$code}): the bucket is in region '{$hint}' - set Region to {$hint}. " . $this->error_summary($err_body));
			}

			// Local clock off by more than S3's 15-minute window: sign with the
			// server's time instead.
			$offset = $this->skew_offset($response, $err_body, $code);
			if (!$skew_retried && 0 !== $offset) {
				$skew_retried = true;
				$this->clock_offset = $offset;
				$this->note(sprintf('S3: local clock differs from the server by %d seconds; compensating. Fix the server clock (NTP).', $offset));
				continue;
			}

			return new WP_Error('s3_error', "S3 error (HTTP {$code}): " . $this->error_summary($err_body));
		}

		return new WP_Error('s3_error', 'S3 error: request failed after retries.');
	}

	/**
	 * Build extra headers for storage class and server-side encryption.
	 *
	 * @return array Extra headers to include in PUT/initiate requests.
	 */
	protected function get_storage_headers() {
		$opts = $this->get_options();
		$extra_headers = array();

		$storage_class = isset($opts['storage_class']) ? strtoupper(trim((string) $opts['storage_class'])) : 'STANDARD';
		if ('' !== $storage_class && 'STANDARD' !== $storage_class) {
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

	/**
	 * Multipart part size: 10 MiB (5 MiB on low-memory hosts), raised when needed
	 * to stay within 10,000 parts, never below the 5 MiB S3 minimum.
	 * https://docs.aws.amazon.com/AmazonS3/latest/userguide/qfacts.html
	 */
	protected function part_size($filesize) {
		$size = 10485760;
		if (class_exists('SiteGuard_Backup') && SiteGuard_Backup::instance()->is_low_memory()) {
			$size = self::MIN_PART_SIZE;
		}
		$size = (int) apply_filters('sgbackup_s3_part_size', $size, $filesize, $this->get_id());
		$needed = (int) ceil($filesize / self::MAX_PARTS);
		if ($needed > $size) {
			$size = (int) (ceil($needed / 1048576) * 1048576);
		}
		return max(self::MIN_PART_SIZE, $size);
	}

	public function backup($file) {
		$filesize = filesize($file);
		$key = basename($file);

		$storage_headers = array_merge($this->get_storage_headers(), $this->get_object_lock_headers());

		// Files smaller than one part: single PUT (reads the file into memory)
		if ($filesize < $this->part_size($filesize)) {
			$body = file_get_contents($file);
			if (false === $body || strlen($body) !== $filesize) {
				return new WP_Error('s3_read', 'Failed to read file for upload: ' . $key);
			}
			$result = $this->request_with_retries(function () use ($key, $body, $storage_headers) {
				return $this->request('PUT', $key, $body, array_merge(array(
					'Content-Type' => 'application/octet-stream',
				), $storage_headers));
			}, "S3 upload of {$key}");
			unset($body);
			if (is_wp_error($result)) return $result;
			return $this->verify_remote_size($key, $filesize);
		}

		// For larger files, use multipart upload (streams in chunks)
		$result = $this->multipart_upload($file, $key);
		if (is_wp_error($result)) return $result;
		return $this->verify_remote_size($key, $filesize);
	}

	/**
	 * HEAD the uploaded object and compare its size with the local file, so an
	 * upload is never reported complete for an object that is short or missing.
	 *
	 * @return true|WP_Error
	 */
	protected function verify_remote_size($key, $expected) {
		$head = $this->request('HEAD', $key);
		if (is_wp_error($head)) {
			return new WP_Error('s3_verify', "Uploaded {$key} but could not confirm it exists: " . $head->get_error_message());
		}
		$length = wp_remote_retrieve_header($head, 'content-length');
		if ('' !== $length && null !== $length && (int) $length !== (int) $expected) {
			return new WP_Error('s3_upload_incomplete', sprintf('Remote object %s is %d bytes, expected %d.', $key, (int) $length, $expected));
		}
		return true;
	}

	/** Option holding unfinished multipart uploads (resume state), per site. */
	const MPU_STATE_OPTION = 'sgbackup_s3_multipart_state';
	/** An unfinished upload not resumed within this time is aborted. */
	const MPU_STATE_TTL = 86400;

	/** Identifies one object on one account of one S3-family method. */
	protected function mpu_state_key($key) {
		$o = $this->get_options();
		return md5(implode('|', array($this->get_id(), (string) $this->instance_id, isset($o['bucket']) ? $o['bucket'] : '', isset($o['path']) ? $o['path'] : '', $key)));
	}

	protected function mpu_state_get($key) {
		$all = get_option(self::MPU_STATE_OPTION, array());
		$k = $this->mpu_state_key($key);
		return (is_array($all) && isset($all[$k]) && is_array($all[$k])) ? $all[$k] : null;
	}

	protected function mpu_state_set($key, $state) {
		$all = get_option(self::MPU_STATE_OPTION, array());
		if (!is_array($all)) $all = array();
		$k = $this->mpu_state_key($key);
		if (null === $state) {
			unset($all[$k]);
		} else {
			$all[$k] = $state;
		}
		if (empty($all)) {
			delete_option(self::MPU_STATE_OPTION);
		} else {
			update_option(self::MPU_STATE_OPTION, $all, false);
		}
	}

	/**
	 * Abort recorded uploads of this account that were not resumed in time
	 * (their job was abandoned), so their parts are not billed forever.
	 */
	protected function abort_expired_recorded_uploads() {
		$all = get_option(self::MPU_STATE_OPTION, array());
		if (!is_array($all) || empty($all)) return;
		$changed = false;
		foreach ($all as $k => $st) {
			if (!is_array($st) || empty($st['updated']) || (time() - (int) $st['updated']) < self::MPU_STATE_TTL) continue;
			if (!isset($st['method'], $st['instance'], $st['key'], $st['upload_id'])) { unset($all[$k]); $changed = true; continue; }
			if ($st['method'] !== $this->get_id() || (string) $st['instance'] !== (string) $this->instance_id) continue;
			$this->abort_multipart_upload($st['key'], $st['upload_id']);
			unset($all[$k]);
			$changed = true;
		}
		if ($changed) {
			empty($all) ? delete_option(self::MPU_STATE_OPTION) : update_option(self::MPU_STATE_OPTION, $all, false);
		}
	}

	/**
	 * Parts S3 already holds for an unfinished upload (ListParts), as
	 * part number => ETag; null when the upload no longer exists.
	 */
	protected function list_uploaded_parts($key, $upload_id) {
		$parts = array();
		$marker = 0;
		for ($page = 0; $page < 20; $page++) {
			$q = $key . '?uploadId=' . rawurlencode($upload_id) . ($marker ? '&part-number-marker=' . $marker : '');
			$r = $this->request('GET', $q);
			if (is_wp_error($r)) return null;
			$body = wp_remote_retrieve_body($r);
			if (!preg_match_all('#<Part>(.*?)</Part>#s', $body, $pm)) break;
			foreach ($pm[1] as $p) {
				if (!preg_match('#<PartNumber>(\d+)</PartNumber>#', $p, $n) || !preg_match('#<ETag>([^<]+)</ETag>#', $p, $e)) continue;
				$size = preg_match('#<Size>(\d+)</Size>#', $p, $sz) ? (int) $sz[1] : -1;
				$parts[(int) $n[1]] = array('etag' => trim(html_entity_decode($e[1], ENT_QUOTES | ENT_XML1), '"'), 'size' => $size);
			}
			if (!preg_match('#<IsTruncated>true</IsTruncated>#i', $body) || !preg_match('#<NextPartNumberMarker>(\d+)</NextPartNumberMarker>#', $body, $nm)) break;
			$marker = (int) $nm[1];
		}
		return $parts;
	}

	/**
	 * Multipart upload that resumes after an interruption.
	 *
	 * The upload id and part size are recorded before the first part. When the
	 * process is killed (execution time limit, memory, a deploy) and the job
	 * resumes, the next attempt asks S3 which parts it already has (ListParts),
	 * keeps those that are complete and uploads only the rest. It used to start a
	 * new upload from the first byte every time, so on a host with a short time
	 * limit a large archive could never finish, and every attempt left billable
	 * parts behind.
	 *
	 * A returned error (S3 refused something) still aborts the upload. Recorded
	 * uploads not resumed within a day are aborted, as are unfinished uploads of
	 * the same object that this site has no record of.
	 */
	protected function multipart_upload($file, $key) {
		// Object Lock (B2) and storage-class/SSE headers belong on
		// CreateMultipartUpload; they apply to the assembled object.
		$storage_headers = array_merge($this->get_storage_headers(), $this->get_object_lock_headers());
		clearstatcache(true, $file);
		$filesize = (int) filesize($file);
		$mtime = (int) filemtime($file);

		$this->abort_expired_recorded_uploads();

		$upload_id = '';
		$part_size = 0;
		$done = array();
		$state = $this->mpu_state_get($key);
		if ($state && isset($state['upload_id'], $state['size'], $state['mtime'], $state['part_size'])
			&& (int) $state['size'] === $filesize && (int) $state['mtime'] === $mtime) {
			$listed = $this->list_uploaded_parts($key, $state['upload_id']);
			if (is_array($listed)) {
				$upload_id = (string) $state['upload_id'];
				$part_size = (int) $state['part_size'];
				$last_part = (int) ceil($filesize / $part_size);
				foreach ($listed as $num => $info) {
					$expected = ($num < $last_part) ? $part_size : $filesize - ($last_part - 1) * $part_size;
					// Only parts S3 holds in full (a part cut short is uploaded again).
					if ($num >= 1 && $num <= $last_part && '' !== $info['etag'] && ($info['size'] < 0 || $info['size'] === $expected)) {
						$done[$num] = $info['etag'];
					}
				}
				$this->note(sprintf('S3: resuming the upload of %s: %d of %d parts already on S3', $key, count($done), $last_part));
			} else {
				$this->mpu_state_set($key, null); // the upload is gone (aborted or expired)
			}
		} elseif ($state) {
			// The local file changed since: that upload cannot be continued.
			$this->abort_multipart_upload($key, (string) $state['upload_id']);
			$this->mpu_state_set($key, null);
		}

		if ('' === $upload_id) {
			// Unfinished uploads of this object that this site has no record of
			// (left by a version without resume, or another installation's run)
			// are invisible and billed until aborted.
			$this->abort_stale_multipart_uploads($key);

			$init = $this->request_with_retries(function () use ($key, $storage_headers) {
				return $this->request('POST', $key . '?uploads', '', array_merge(array(
					'Content-Type' => 'application/octet-stream',
				), $storage_headers));
			}, "S3 multipart initiate for {$key}");
			if (is_wp_error($init)) return $init;

			$body = wp_remote_retrieve_body($init);
			if (!preg_match('/<UploadId>([^<]+)</', $body, $m)) {
				return new WP_Error('s3_multipart_init', 'Failed to initiate multipart upload.');
			}
			$upload_id = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
			$part_size = $this->part_size($filesize);
		}

		// Record before the first part, so a kill at any point can be resumed.
		$record = array(
			'method' => $this->get_id(), 'instance' => (string) $this->instance_id, 'key' => $key,
			'upload_id' => $upload_id, 'size' => $filesize, 'mtime' => $mtime, 'part_size' => $part_size,
			'updated' => time(),
		);
		$this->mpu_state_set($key, $record);

		$result = $this->upload_parts_and_complete($file, $key, $upload_id, $part_size, $done, $record);
		if (is_wp_error($result)) {
			$this->abort_multipart_upload($key, $upload_id);
			$this->mpu_state_set($key, null);
			return $result;
		}
		$this->mpu_state_set($key, null);
		return true;
	}

	/**
	 * Abort unfinished multipart uploads of exactly this object (best effort: a
	 * server without ListMultipartUploads just returns an error we ignore).
	 */
	protected function abort_stale_multipart_uploads($key) {
		try {
			$options = $this->get_options();
			$full_key = (!empty($options['path']) ? trim($options['path'], '/') . '/' : '') . $key;
			$list = $this->request('GET', '?uploads&prefix=' . rawurlencode($full_key), '', array(), true);
			if (is_wp_error($list) || 200 !== (int) wp_remote_retrieve_response_code($list)) return;
			$body = wp_remote_retrieve_body($list);
			if (!preg_match_all('#<Upload>(.*?)</Upload>#s', $body, $uploads)) return;
			$aborted = 0;
			foreach ($uploads[1] as $u) {
				if (!preg_match('#<Key>([^<]*)</Key>#', $u, $k) || !preg_match('#<UploadId>([^<]+)</UploadId>#', $u, $i)) continue;
				if (html_entity_decode($k[1], ENT_QUOTES | ENT_XML1) !== $full_key) continue; // exact object only
				$this->abort_multipart_upload($key, html_entity_decode($i[1], ENT_QUOTES | ENT_XML1));
				if (++$aborted >= 20) break;
			}
			if ($aborted && class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log("S3: aborted {$aborted} unfinished multipart upload(s) of {$key} left by an earlier attempt");
			}
		} catch (\Throwable $e) {
			// Cleanup only: never block the upload.
		}
	}

	/**
	 * Upload all parts of a multipart upload, then complete it.
	 *
	 * @param string $file      Full path to file.
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload ID.
	 * @return bool|WP_Error
	 */
	protected function upload_parts_and_complete($file, $key, $upload_id, $chunk_size = 0, $done = array(), $record = null) {
		$filesize = filesize($file);
		if ($chunk_size <= 0) $chunk_size = $this->part_size($filesize);
		$fh = fopen($file, 'rb');
		if (!$fh) {
			return new WP_Error('s3_read', 'Failed to open file for reading: ' . $file);
		}
		$part_number = 1;
		$etags = array();
		$sent = 0;
		$last_touch = time();

		while ($sent < $filesize) {
			// Already on S3 (resumed upload): skip this part's bytes.
			if (isset($done[$part_number])) {
				$skip = min($chunk_size, $filesize - $sent);
				if (0 !== fseek($fh, $sent + $skip)) {
					fclose($fh);
					return new WP_Error('s3_read', 'Could not seek in ' . basename($file));
				}
				$etags[$part_number] = $done[$part_number];
				$sent += $skip;
				$part_number++;
				continue;
			}
			// fread() on a local file can return fewer bytes than asked; S3 needs
			// every part but the last to be at least 5 MiB, so fill the part.
			$chunk = '';
			while (strlen($chunk) < $chunk_size && !feof($fh)) {
				$piece = fread($fh, $chunk_size - strlen($chunk));
				if (false === $piece || '' === $piece) break;
				$chunk .= $piece;
			}
			if ('' === $chunk) {
				// A read error must not be completed as a shorter object.
				fclose($fh);
				return new WP_Error('s3_read', sprintf('Read failed after %d of %d bytes of %s.', $sent, $filesize, basename($file)));
			}
			$sent += strlen($chunk);

			$part_key = $key . '?partNumber=' . $part_number . '&uploadId=' . rawurlencode($upload_id);
			$result = $this->request_with_retries(function () use ($part_key, $chunk) {
				return $this->request('PUT', $part_key, $chunk);
			}, "S3 part {$part_number} upload");
			unset($chunk);

			if (is_wp_error($result)) {
				fclose($fh);
				return $result;
			}

			$etag = trim((string) wp_remote_retrieve_header($result, 'etag'), '"');
			if ('' === $etag) {
				fclose($fh);
				return new WP_Error('s3_missing_etag', "Missing ETag for part {$part_number}");
			}
			$etags[$part_number] = $etag;

			$part_number++;
			// Keep the record fresh while parts are going up (expiry is a day).
			if (null !== $record && time() - $last_touch > 300) {
				$record['updated'] = $last_touch = time();
				$this->mpu_state_set($key, $record);
			}
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}
		fclose($fh);

		// Complete multipart upload
		$complete_xml = '<CompleteMultipartUpload>';
		foreach ($etags as $num => $etag) {
			$complete_xml .= '<Part><PartNumber>' . $num . '</PartNumber><ETag>"' . htmlspecialchars($etag, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"</ETag></Part>';
		}
		$complete_xml .= '</CompleteMultipartUpload>';

		$complete = $this->request_with_retries(function () use ($key, $upload_id, $complete_xml) {
			return $this->request('POST', $key . '?uploadId=' . rawurlencode($upload_id), $complete_xml, array(
				'Content-Type' => 'application/xml',
			));
		}, "S3 multipart complete for {$key}");
		if (is_wp_error($complete)) return $complete;

		// S3 may answer CompleteMultipartUpload with HTTP 200 and an <Error> body
		// (e.g. InternalError). Treat that as failure so the caller aborts the
		// upload instead of checkpointing a file that was never assembled.
		$complete_body = wp_remote_retrieve_body($complete);
		if (stripos($complete_body, '<Error>') !== false || stripos($complete_body, 'CompleteMultipartUploadResult') === false) {
			return new WP_Error('s3_complete', 'CompleteMultipartUpload did not confirm success: ' . substr(strip_tags($complete_body), 0, 200));
		}

		return true;
	}

	/**
	 * Best-effort AbortMultipartUpload for a failed multipart upload.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Multipart upload ID.
	 */
	protected function abort_multipart_upload($key, $upload_id) {
		$abort = $this->request('DELETE', $key . '?uploadId=' . rawurlencode($upload_id));
		if (is_wp_error($abort)) {
			$this->note("S3: failed to abort multipart upload {$upload_id} for {$key}: " . $abort->get_error_message());
		}
	}

	public function download($file, $dest_dir) {
		$key = basename($file);
		$local_path = $this->local_download_path($dest_dir, $key);

		// Stream straight to disk to avoid loading the archive into memory.
		$response = $this->request('GET', $key, '', array(), false, array(
			'stream'   => true,
			'filename' => $local_path,
		));
		if (is_wp_error($response)) {
			@unlink($local_path);
			return new WP_Error('s3_download', 'S3 download of ' . $key . ' failed: ' . $response->get_error_message());
		}

		$length = wp_remote_retrieve_header($response, 'content-length');
		return $this->verify_download($local_path, ('' === $length || null === $length) ? null : (int) $length, 'S3');
	}

	public function delete($files) {
		$files = $this->safe_remote_names($files);
		if (!$files) return true;
		if (!is_array($files)) $files = array($files);
		$errors = array();

		foreach ($files as $file) {
			$result = $this->request('DELETE', basename($file));
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
		$prefix = ltrim((string) $options['path'], '/');
		if (!empty($prefix)) $prefix = rtrim($prefix, '/') . '/';

		$files = array();
		$continuation = '';
		$pages = 0;
		do {
			$query = '?list-type=2&prefix=' . rawurlencode($prefix) . '&max-keys=1000';
			if ($continuation !== '') {
				$query .= '&continuation-token=' . rawurlencode($continuation);
			}
			$response = $this->request('GET', $query, '', array(), true);
			if (is_wp_error($response)) {
				error_log('SiteGuard Backup: S3 listing failed: ' . $response->get_error_message());
				return $files;
			}

			$body = wp_remote_retrieve_body($response);

			// Only objects directly under the prefix are backup files
			if (preg_match_all('#<Contents>.*?<Key>([^<]+)</Key>.*?</Contents>#s', $body, $matches)) {
				foreach ($matches[1] as $key) {
					$key = html_entity_decode($key, ENT_QUOTES | ENT_XML1);
					$rel = ($prefix !== '' && strpos($key, $prefix) === 0) ? substr($key, strlen($prefix)) : $key;
					if ($rel === '' || strpos($rel, '/') !== false) continue;
					$files[] = $rel;
				}
			}

			// Pagination: follow the continuation token so more than 1000 objects are seen.
			$continuation = '';
			if (preg_match('/<IsTruncated>true<\/IsTruncated>/i', $body)
				&& preg_match('/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $body, $tm)) {
				$continuation = html_entity_decode($tm[1], ENT_QUOTES | ENT_XML1);
			}
			$pages++;
		} while ($continuation !== '' && $pages < 100);

		return $files;
	}

	public function credentials_test() {
		$result = $this->request('HEAD', '', '', array(), true); // HeadBucket
		if (is_wp_error($result)) {
			// HeadBucket has no body; name the common causes.
			$msg = $result->get_error_message();
			if (false !== strpos($msg, 'HTTP 403')) {
				$msg .= ' Access denied: check the access key, secret key and bucket permissions.';
			} elseif (false !== strpos($msg, 'HTTP 404')) {
				$msg .= ' Bucket not found: check the bucket name and endpoint.';
			}
			return new WP_Error($result->get_error_code(), $msg);
		}
		if (null !== $this->region_override) {
			return new WP_Error('s3_region', sprintf('Connected, but the bucket is in region %s. Set Region to %s and save.', $this->region_override, $this->region_override));
		}
		return true;
	}
}

