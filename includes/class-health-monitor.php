<?php
/**
 * SiteGuard Backup - Multisite Health Monitor
 *
 * Checks HTTP health and SSL certificate expiry for all subsites.
 * HTTP checks go through localhost to avoid Cloudflare Bot Fight Mode.
 * SSL checks go to the public domain to see what users see.
 * Stores results in wp_options and exposes them via REST API.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Health_Monitor {

	private static $instance = null;

	/** @var int Max failed auth attempts before lockout */
	private static $AUTH_RATE_LIMIT = 20;

	/** @var int Rate limit window in seconds */
	private static $AUTH_RATE_WINDOW = 300;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Run health checks on all sites and store results.
	 */
	public function run_checks() {
		$opts = SiteGuard_Backup_Options::instance();
		$timeout = intval($opts->get_option('sgbackup_health_timeout', 10));
		$ssl_warn = intval($opts->get_option('sgbackup_health_ssl_warn_days', 14));
		$targets = $this->get_check_targets();
		$results = array();
		$previous = $opts->get_option('sgbackup_health_results', array());
		$prev_map = array();
		foreach ($previous as $p) {
			$prev_map[$p['site_id']] = $p;
		}

		// Time budget: stop checking after 55s to stay under Azure's 60s safe limit
		$deadline = microtime(true) + 55;

		foreach ($targets as $target) {
			$prev = isset($prev_map[$target['site_id']]) ? $prev_map[$target['site_id']] : null;

			if (microtime(true) > $deadline) {
				$result = array(
					'site_id'            => $target['site_id'],
					'url'                => $target['url'],
					'domain'             => wp_parse_url($target['url'], PHP_URL_HOST),
					'status'             => 'unknown',
					'http_code'          => 0,
					'response_time'      => 0,
					'ssl_expiry_days'    => isset($prev['ssl_expiry_days']) ? intval($prev['ssl_expiry_days']) : -1,
					'ssl_issuer'         => isset($prev['ssl_issuer']) ? (string) $prev['ssl_issuer'] : '',
					'ssl_ok'             => false,
					'ssl_error'          => 'skipped: time budget exceeded',
					'ssl_stale'          => true,
					'ssl_last_success'   => isset($prev['ssl_last_success']) ? intval($prev['ssl_last_success']) : 0,
					'ssl_checked_at'     => isset($prev['ssl_checked_at']) ? intval($prev['ssl_checked_at']) : 0,
					'last_check'         => 0,
					'error'              => 'Skipped: time budget exceeded',
					'consecutive_failures' => $prev ? intval($prev['consecutive_failures'] ?? 0) : 0,
					'check_method'       => 'skipped',
				);
				$results[] = $result;
				continue;
			}

			$result = $this->check_site($target, $timeout, $ssl_warn, $prev);
			// Track consecutive failures
			$prev_failures = $prev ? intval($prev['consecutive_failures'] ?? 0) : 0;
			$result['consecutive_failures'] = ($result['status'] === 'down') ? $prev_failures + 1 : 0;
			$results[] = $result;
		}

		$opts->update_option('sgbackup_health_results', $results);
		$opts->update_option('sgbackup_health_last_run', time());

		return $results;
	}

	/**
	 * Check a single site via internal localhost request + external SSL check.
	 *
	 * HTTP: requests localhost with the site's Host header so the check
	 * never leaves the server and never hits Cloudflare Bot Fight Mode.
	 *
	 * SSL: connects to the public domain on port 443 to capture the
	 * certificate users actually see (including Cloudflare edge certs).
	 */
	private function check_site($target, $timeout, $ssl_warn_days, $prev = null) {
		$url = $target['url'];
		$site_id = $target['site_id'];
		$domain = wp_parse_url($url, PHP_URL_HOST);
		$scheme = wp_parse_url($url, PHP_URL_SCHEME);

		$result = array(
			'site_id'         => $site_id,
			'url'             => $url,
			'domain'          => $domain,
			'status'          => 'down',
			'http_code'       => 0,
			'response_time'   => 0,
			'ssl_expiry_days' => -1,
			'ssl_issuer'      => '',
			'ssl_ok'          => false,
			'ssl_error'       => '',
			'ssl_stale'       => false,
			'ssl_last_success' => 0,
			'ssl_checked_at'  => 0,
			'last_check'      => time(),
			'error'           => '',
			'check_method'    => 'unknown',
		);

		// ---- Internal HTTP check (via localhost, bypasses Cloudflare) ----
		// Multisite on the same server: request localhost with Host header
		// so the web server routes it to the correct vhost without going external.
		$local_url = $this->get_local_url($target);
		if ($local_url) {
			$result['check_method'] = 'localhost';
			$start = microtime(true);
			$response = wp_remote_head($local_url, array(
				'timeout'     => $timeout,
				'sslverify'   => false,
				'redirection' => 0, // Don't follow redirects - a 301/302 means the site is alive
				'headers'     => array('Host' => $domain),
				'user-agent'  => 'SiteGuard-Health-Monitor/' . SGBACKUP_VERSION,
			));
			$elapsed = microtime(true) - $start;
			$result['response_time'] = round($elapsed, 3);

			if (is_wp_error($response)) {
				// Localhost failed - fall back to external check
				$result['check_method'] = 'external_fallback';
				$start2 = microtime(true);
				$response = wp_remote_head($url, array(
					'timeout'     => $timeout,
					'sslverify'   => false,
					'redirection' => 3,
					'user-agent'  => 'SiteGuard-Health-Monitor/' . SGBACKUP_VERSION,
				));
				$elapsed2 = microtime(true) - $start2;
				$result['response_time'] = round($elapsed2, 3);
			}
		} else {
			// Not multisite or can't determine local URL - use external
			$result['check_method'] = 'external';
			$start = microtime(true);
			$response = wp_remote_head($url, array(
				'timeout'     => $timeout,
				'sslverify'   => false,
				'redirection' => 3,
				'user-agent'  => 'SiteGuard-Health-Monitor/' . SGBACKUP_VERSION,
			));
			$elapsed = microtime(true) - $start;
			$result['response_time'] = round($elapsed, 3);
		}

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			$result['status'] = 'down';
		} else {
			$code = wp_remote_retrieve_response_code($response);
			$result['http_code'] = intval($code);

			// 2xx = operational, 3xx = operational (redirect means site is alive),
			// 403 from localhost is NOT Cloudflare, it's WordPress/nginx denying - still alive
			if ($code >= 200 && $code < 400) {
				$result['status'] = 'operational';
			} elseif ($code == 403 && $result['check_method'] === 'localhost') {
				// 403 from localhost is the web server responding - site is alive
				$result['status'] = 'operational';
				$result['error'] = '';
			} else {
				$result['status'] = 'down';
				$result['error'] = 'HTTP ' . $code;
			}
		}

		// ---- SSL certificate check (external, to public domain) ----
		if ($domain && $scheme === 'https') {
			$ssl = $this->get_ssl_expiry($domain, $timeout);
			$result['ssl_checked_at'] = time();

			// Hostname mismatch: probe succeeded but the cert isn't valid for our
			// domain. Treat as a probe failure with an explicit error so the user
			// can see why the site is serving the "wrong" cert.
			if (!empty($ssl['ok']) && !empty($ssl['san']) && is_array($ssl['san'])) {
				if (!$this->cert_matches_host($domain, $ssl['san'])) {
					$ssl['ok'] = false;
					$ssl['error'] = 'cert hostname mismatch: cert valid for ' . implode(', ', array_slice($ssl['san'], 0, 3)) . ' (expected ' . $domain . ')';
				}
			}

			if (!empty($ssl['ok'])) {
				// Fresh successful probe: write new values
				$result['ssl_expiry_days']  = intval($ssl['days']);
				$result['ssl_issuer']       = (string) $ssl['issuer'];
				$result['ssl_ok']           = true;
				$result['ssl_stale']        = false;
				$result['ssl_error']        = '';
				$result['ssl_last_success'] = time();

				// Only escalate status based on fresh cert data
				if ($ssl['days'] !== -1 && $ssl['days'] <= 0) {
					$result['status'] = 'down';
					$result['error'] = $result['error'] ? $result['error'] . '; SSL expired' : 'SSL certificate expired';
				} elseif ($ssl['days'] > 0 && $ssl['days'] <= $ssl_warn_days && $result['status'] === 'operational') {
					$result['status'] = 'degraded';
				}
			} else {
				// Probe failed: carry forward last-known-good data if we have any
				if ($prev && isset($prev['ssl_expiry_days']) && intval($prev['ssl_expiry_days']) >= 0) {
					$result['ssl_expiry_days']  = intval($prev['ssl_expiry_days']);
					$result['ssl_issuer']       = isset($prev['ssl_issuer']) ? (string) $prev['ssl_issuer'] : '';
					$result['ssl_last_success'] = isset($prev['ssl_last_success']) ? intval($prev['ssl_last_success']) : 0;
				}
				$result['ssl_ok']    = false;
				$result['ssl_stale'] = true;
				// Prefix the error with the domain so operators can see at a glance
				// which site failed (especially in error_log / Azure diagnostics).
				$result['ssl_error'] = 'SSL probe failed for ' . $domain . ': ' . (string) $ssl['error'];

				// Log to PHP error_log so the failure is visible in server diagnostic
				// logs (Azure App Service /home/LogFiles, docker logs, etc) without
				// needing to open the Health tab. Only log on state change (new
				// failure or error message changed) to avoid spamming the log every
				// cron tick when a site's probe is persistently broken.
				$prev_error = $prev && isset($prev['ssl_error']) ? (string) $prev['ssl_error'] : '';
				if ($prev_error !== $result['ssl_error']) {
					error_log('SiteGuard Backup: ' . $result['ssl_error']);
				}

				// Probe failure degrades (amber) but never marks site down - HTTP drives down.
				// Don't downgrade an already-down site either.
				if ($result['status'] === 'operational') {
					$result['status'] = 'degraded';
				}
			}
		} elseif ($domain && $scheme !== 'https') {
			// Explicit marker: http-only site, no cert to check. UI renders "-".
			$result['ssl_error'] = '';
		} elseif (!$domain) {
			// home_url() returned something unparseable - surface it rather than
			// silently skipping.
			$result['ssl_error'] = 'SSL probe skipped: could not parse domain from ' . $url;
			$result['ssl_stale'] = true;
			$prev_error = $prev && isset($prev['ssl_error']) ? (string) $prev['ssl_error'] : '';
			if ($prev_error !== $result['ssl_error']) {
				error_log('SiteGuard Backup: ' . $result['ssl_error']);
			}
		}

		// Slow response degrades operational status
		if ($result['status'] === 'operational' && $result['response_time'] > 5.0) {
			$result['status'] = 'degraded';
		}

		return $result;
	}

	/**
	 * Build a localhost URL for internal health checking.
	 * Returns http://127.0.0.1/ (or http://localhost:PORT/) so the request
	 * stays on the same server and never goes through Cloudflare.
	 */
	private function get_local_url($target) {
		// Determine the local port the web server is listening on
		$port = '';
		if (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
			$port = ':' . intval($_SERVER['SERVER_PORT']);
		}

		// Use 127.0.0.1 to avoid DNS - guaranteed to be the local machine
		return 'http://127.0.0.1' . $port . '/';
	}

	/**
	 * Get SSL certificate expiry info for a host.
	 * Connects to the public domain to see the cert users see (including Cloudflare edge certs).
	 *
	 * Returns ['ok' => bool, 'days' => int, 'issuer' => string, 'error' => string, 'method' => string].
	 * On failure, 'ok' is false and 'error' contains a human-readable reason.
	 *
	 * Tries two transports so the check survives hostile network environments
	 * (Azure App Service outbound restrictions, Cloudflare Bot Fight Mode, etc):
	 *   1. cURL via wp_remote_head + CURLINFO_CERTINFO - uses the same HTTP stack
	 *      the rest of the plugin uses, handles proxies/keep-alive/SNAT better.
	 *   2. Raw stream_socket_client('ssl://…') - pure-PHP fallback when cURL
	 *      isn't available or its request errors out before TLS completes.
	 */
	private function get_ssl_expiry($host, $timeout, $port = 443) {
		if (!function_exists('openssl_x509_parse')) {
			return array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => 'openssl extension missing', 'method' => 'none');
		}

		// Clamp timeout to sane bounds.
		$timeout = max(2, min(120, intval($timeout)));

		// Attempt 1: cURL via WordPress HTTP API, capturing the full cert chain.
		$curl_result = $this->get_ssl_expiry_via_curl($host, $timeout, $port);
		if (!empty($curl_result['ok'])) {
			return $curl_result;
		}

		// Attempt 2: raw stream_socket_client fallback.
		$stream_result = $this->get_ssl_expiry_via_stream($host, $timeout, $port);
		if (!empty($stream_result['ok'])) {
			return $stream_result;
		}

		// Both failed - return the more informative error (prefer cURL's message
		// unless it's empty/"cURL unavailable", in which case use the stream error).
		$curl_err = isset($curl_result['error']) ? (string) $curl_result['error'] : '';
		$stream_err = isset($stream_result['error']) ? (string) $stream_result['error'] : '';
		if ($curl_err === '' || strpos($curl_err, 'cURL unavailable') !== false) {
			return $stream_result;
		}
		// Combine both for maximum diagnostic value.
		$combined = $curl_err;
		if ($stream_err !== '' && $stream_err !== $curl_err) {
			$combined .= '; fallback: ' . $stream_err;
		}
		return array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => $combined, 'method' => 'both-failed');
	}

	/**
	 * SSL probe via cURL + CURLINFO_CERTINFO using wp_remote_head.
	 * Uses http_api_curl filter to enable cert capture, then reads the chain
	 * from curl_getinfo() in a second filter invocation.
	 */
	private function get_ssl_expiry_via_curl($host, $timeout, $port = 443) {
		$result = array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => '', 'method' => 'curl');

		if (!function_exists('curl_init') || !function_exists('curl_getinfo')) {
			$result['error'] = 'cURL unavailable';
			return $result;
		}

		$host_for_url = (strpos($host, ':') !== false && strpos($host, '[') === false) ? '[' . $host . ']' : $host;
		$url = 'https://' . $host_for_url . ($port !== 443 ? ':' . intval($port) : '') . '/';

		// Capture the cURL handle so we can pull CURLINFO_CERTINFO after the request.
		// WordPress's http_api_curl filter fires once before execution (when we can
		// set options) and we retain a reference to the handle for post-execution.
		$captured = array('handle' => null, 'certinfo' => null);

		$filter = function($handle, $r, $filter_url) use (&$captured, $url) {
			if ($filter_url !== $url) {
				return $handle;
			}
			$captured['handle'] = $handle;
			if (defined('CURLOPT_CERTINFO')) {
				@curl_setopt($handle, CURLOPT_CERTINFO, true);
			}
			// Don't follow redirects - we only need the TLS handshake to complete.
			@curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
			// CURLOPT_NOBODY is already set by wp_remote_head; belt-and-braces.
			@curl_setopt($handle, CURLOPT_NOBODY, true);
			return $handle;
		};
		add_action('http_api_curl', $filter, 10, 3);

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => false,
			'user-agent'  => 'SiteGuard-Health-Monitor/' . SGBACKUP_VERSION,
		);
		$response = wp_remote_head($url, $args);

		remove_action('http_api_curl', $filter, 10);

		// Pull CURLINFO_CERTINFO from the captured handle if possible. The handle
		// may still be alive - WP's Requests library closes it after the request,
		// but on many PHP builds curl_getinfo still returns data on a closed handle
		// within the same request cycle.
		if ($captured['handle']) {
			$info = @curl_getinfo($captured['handle']);
			if (is_array($info) && isset($info['certinfo']) && is_array($info['certinfo']) && !empty($info['certinfo'])) {
				$captured['certinfo'] = $info['certinfo'];
			}
		}

		if (is_wp_error($response)) {
			$msg = $response->get_error_message();
			// If the body errored but we still captured the cert chain during TLS
			// handshake, that's a win - cert check is what we care about.
			if (!empty($captured['certinfo'])) {
				return $this->parse_curl_certinfo($captured['certinfo']);
			}
			$result['error'] = 'curl: ' . ($msg !== '' ? $msg : 'request failed');
			return $result;
		}

		if (empty($captured['certinfo'])) {
			$result['error'] = 'curl: CURLINFO_CERTINFO empty (cURL compiled without cert capture?)';
			return $result;
		}

		return $this->parse_curl_certinfo($captured['certinfo']);
	}

	/**
	 * Extract SAN DNS names (+CN fallback) from a parsed x509 into a flat array
	 * of lowercase hostnames for hostname-match checks.
	 */
	private function extract_cert_names($parsed) {
		$names = array();
		if (!is_array($parsed)) return $names;

		if (!empty($parsed['extensions']['subjectAltName'])) {
			$san = (string) $parsed['extensions']['subjectAltName'];
			foreach (explode(',', $san) as $entry) {
				$entry = trim($entry);
				if (stripos($entry, 'DNS:') === 0) {
					$names[] = strtolower(substr($entry, 4));
				}
			}
		}
		if (!empty($parsed['subject']['CN'])) {
			$names[] = strtolower((string) $parsed['subject']['CN']);
		}

		return array_values(array_unique(array_filter($names)));
	}

	/**
	 * Check whether a host matches any of the cert's DNS names (supports
	 * one leading "*." wildcard label, per RFC 6125).
	 */
	private function cert_matches_host($host, $names) {
		$host = strtolower($host);
		foreach ($names as $name) {
			if ($name === $host) return true;
			if (strpos($name, '*.') === 0) {
				$suffix = substr($name, 1); // ".example.com"
				$host_label_end = strpos($host, '.');
				if ($host_label_end !== false && substr($host, $host_label_end) === $suffix) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Parse a CURLINFO_CERTINFO array (leaf cert at index 0) into the
	 * standard return shape.
	 */
	private function parse_curl_certinfo($certinfo) {
		$result = array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => '', 'method' => 'curl', 'san' => array());

		$leaf = isset($certinfo[0]) && is_array($certinfo[0]) ? $certinfo[0] : null;
		if (!$leaf) {
			$result['error'] = 'curl: certinfo empty';
			return $result;
		}

		// CURLINFO_CERTINFO returns string values keyed by field name.
		// "Expire date" format: "Jan  1 00:00:00 2027 GMT"
		$expire = null;
		foreach (array('Expire date', 'Expire Date', 'notAfter', 'NotAfter') as $k) {
			if (!empty($leaf[$k])) { $expire = $leaf[$k]; break; }
		}
		if ($expire) {
			$ts = strtotime($expire);
			if ($ts !== false) {
				$result['days'] = intval(floor(($ts - time()) / 86400));
			}
		}

		// If the leaf has a PEM blob we can parse the full x509 for a better issuer.
		if (!empty($leaf['Cert'])) {
			$parsed = @openssl_x509_parse($leaf['Cert']);
			if (is_array($parsed)) {
				if (isset($parsed['validTo_time_t'])) {
					$result['days'] = intval(floor(($parsed['validTo_time_t'] - time()) / 86400));
				}
				if (isset($parsed['issuer']['O'])) {
					$result['issuer'] = (string) $parsed['issuer']['O'];
				} elseif (isset($parsed['issuer']['CN'])) {
					$result['issuer'] = (string) $parsed['issuer']['CN'];
				}
				$result['san'] = $this->extract_cert_names($parsed);
			}
		}

		// CURLINFO_CERTINFO also exposes SAN as a "Subject Alternative Name" string.
		if (empty($result['san'])) {
			foreach (array('Subject Alternative Name', 'subjectAltName', 'X509v3 Subject Alternative Name') as $k) {
				if (!empty($leaf[$k])) {
					$names = array();
					foreach (explode(',', (string) $leaf[$k]) as $entry) {
						$entry = trim($entry);
						if (stripos($entry, 'DNS:') === 0) {
							$names[] = strtolower(substr($entry, 4));
						}
					}
					if ($names) $result['san'] = array_values(array_unique($names));
					break;
				}
			}
		}
		// Last-resort CN fallback from the raw "Subject" string.
		if (empty($result['san'])) {
			foreach (array('Subject', 'subject') as $k) {
				if (!empty($leaf[$k]) && preg_match('~CN\s*=\s*([^,/]+)~', (string) $leaf[$k], $m)) {
					$result['san'] = array(strtolower(trim($m[1])));
					break;
				}
			}
		}

		// Fall back to the "Issuer" field CURLINFO_CERTINFO sometimes exposes directly.
		if ($result['issuer'] === '') {
			foreach (array('Issuer', 'issuer') as $k) {
				if (!empty($leaf[$k])) {
					$raw = (string) $leaf[$k];
					if (preg_match('~O\s*=\s*([^,/]+)~', $raw, $m)) {
						$result['issuer'] = trim($m[1]);
					} elseif (preg_match('~CN\s*=\s*([^,/]+)~', $raw, $m)) {
						$result['issuer'] = trim($m[1]);
					}
					break;
				}
			}
		}

		if ($result['days'] === -1) {
			$result['error'] = 'curl: unable to parse expiry';
			return $result;
		}

		$result['ok'] = true;
		return $result;
	}

	/**
	 * Fallback SSL probe via raw stream_socket_client.
	 */
	private function get_ssl_expiry_via_stream($host, $timeout, $port = 443) {
		$result = array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => '', 'method' => 'stream', 'san' => array());

		if (!function_exists('stream_socket_client')) {
			$result['error'] = 'stream_socket_client unavailable';
			return $result;
		}

		$host_for_url = (strpos($host, ':') !== false && strpos($host, '[') === false) ? '[' . $host . ']' : $host;

		$context = stream_context_create(array(
			'ssl' => array(
				'capture_peer_cert' => true,
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'SNI_enabled'       => true,
				'peer_name'         => $host,
			),
		));

		$errno = 0;
		$errstr = '';
		$stream = @stream_socket_client(
			'ssl://' . $host_for_url . ':' . $port,
			$errno,
			$errstr,
			$timeout,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$stream) {
			$msg = trim((string) $errstr);
			if ($msg === '') {
				$msg = 'connection failed';
			}
			$result['error'] = 'stream: ' . $msg . ' (errno ' . intval($errno) . ')';
			return $result;
		}

		@stream_set_timeout($stream, $timeout);

		$params = stream_context_get_params($stream);
		fclose($stream);

		if (!isset($params['options']['ssl']['peer_certificate'])) {
			$result['error'] = 'stream: no peer certificate returned';
			return $result;
		}

		$cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
		if (!$cert) {
			$result['error'] = 'stream: cert parse failed';
			return $result;
		}
		if (!isset($cert['validTo_time_t'])) {
			$result['error'] = 'stream: cert missing expiry field';
			return $result;
		}

		$result['days'] = intval(floor(($cert['validTo_time_t'] - time()) / 86400));

		if (isset($cert['issuer']['O'])) {
			$result['issuer'] = $cert['issuer']['O'];
		} elseif (isset($cert['issuer']['CN'])) {
			$result['issuer'] = $cert['issuer']['CN'];
		}

		$result['san'] = $this->extract_cert_names($cert);
		$result['ok'] = true;
		return $result;
	}

	/**
	 * Get cached health check results.
	 */
	public function get_results() {
		$opts = SiteGuard_Backup_Options::instance();
		return $opts->get_option('sgbackup_health_results', array());
	}

	/**
	 * Get overall status across all sites.
	 */
	public function get_overall_status() {
		$results = $this->get_results();
		if (empty($results)) return 'unknown';

		$has_down = false;
		$has_degraded = false;

		foreach ($results as $site) {
			if ($site['status'] === 'down') $has_down = true;
			if ($site['status'] === 'degraded') $has_degraded = true;
		}

		if ($has_down) return 'down';
		if ($has_degraded) return 'degraded';
		return 'operational';
	}

	/**
	 * Get the configured check interval in seconds.
	 */
	public function get_interval_seconds() {
		$opts = SiteGuard_Backup_Options::instance();
		$key = $opts->get_option('sgbackup_health_interval', 'every10min');
		$schedules = wp_get_schedules();
		if (isset($schedules[$key])) {
			return intval($schedules[$key]['interval']);
		}
		return 600; // default 10 min
	}

	/**
	 * Enumerate all site URLs to check.
	 */
	private function get_check_targets() {
		$targets = array();

		if (is_multisite()) {
			$sites = get_sites(array('fields' => 'ids', 'number' => 0));
			foreach ($sites as $site_id) {
				switch_to_blog($site_id);
				$targets[] = array(
					'site_id' => intval($site_id),
					'url'     => home_url('/'),
				);
				restore_current_blog();
			}
		} else {
			$targets[] = array(
				'site_id' => 1,
				'url'     => home_url('/'),
			);
		}

		return $targets;
	}

	/**
	 * Get or auto-generate the API key for external polling.
	 */
	public function get_api_key() {
		$opts = SiteGuard_Backup_Options::instance();

		// Migrate legacy plaintext key to hashed storage
		$legacy = $opts->get_option('sgbackup_health_api_key', '');
		if (!empty($legacy)) {
			$opts->update_option('sgbackup_health_api_key_hash', wp_hash_password($legacy));
			$opts->delete_option('sgbackup_health_api_key');
			return $legacy;
		}

		$hash = $opts->get_option('sgbackup_health_api_key_hash', '');
		if (empty($hash)) {
			return $this->regenerate_api_key();
		}
		return '';
	}

	/**
	 * Regenerate the API key. Returns the plaintext key (only time it's visible).
	 */
	public function regenerate_api_key() {
		$key = wp_generate_password(48, false, false);
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_health_api_key_hash', wp_hash_password($key));
		$opts->delete_option('sgbackup_health_api_key');
		return $key;
	}

	/**
	 * Validate a provided API key against the stored hash.
	 */
	public function validate_api_key($key) {
		if (empty($key) || strlen($key) < 16) return false;

		$opts = SiteGuard_Backup_Options::instance();

		$legacy = $opts->get_option('sgbackup_health_api_key', '');
		if (!empty($legacy)) {
			$opts->update_option('sgbackup_health_api_key_hash', wp_hash_password($legacy));
			$opts->delete_option('sgbackup_health_api_key');
			return hash_equals($legacy, $key);
		}

		$hash = $opts->get_option('sgbackup_health_api_key_hash', '');
		if (empty($hash)) return false;

		if (!function_exists('wp_check_password')) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		return wp_check_password($key, $hash);
	}

	public function has_api_key() {
		$opts = SiteGuard_Backup_Options::instance();
		return !empty($opts->get_option('sgbackup_health_api_key_hash', '')) || !empty($opts->get_option('sgbackup_health_api_key', ''));
	}

	public function is_rate_limited($ip) {
		$key = 'sgbackup_health_rl_' . md5($ip);
		$attempts = (int) get_transient($key);
		return $attempts >= self::$AUTH_RATE_LIMIT;
	}

	public function record_failed_auth($ip) {
		$key = 'sgbackup_health_rl_' . md5($ip);
		$attempts = (int) get_transient($key);
		set_transient($key, $attempts + 1, self::$AUTH_RATE_WINDOW);
	}

	public static function get_client_ip() {
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			return sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
		}
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			return sanitize_text_field(trim($ips[0]));
		}
		return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
	}
}
