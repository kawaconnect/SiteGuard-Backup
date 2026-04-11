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
				$result['ssl_error'] = (string) $ssl['error'];

				// Probe failure degrades (amber) but never marks site down - HTTP drives down.
				// Don't downgrade an already-down site either.
				if ($result['status'] === 'operational') {
					$result['status'] = 'degraded';
				}
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
	 * Returns ['ok' => bool, 'days' => int, 'issuer' => string, 'error' => string].
	 * On failure, 'ok' is false and 'error' contains a human-readable reason.
	 */
	private function get_ssl_expiry($host, $timeout, $port = 443) {
		$result = array('ok' => false, 'days' => -1, 'issuer' => '', 'error' => '');

		if (!function_exists('stream_socket_client') || !function_exists('openssl_x509_parse')) {
			$result['error'] = 'openssl/stream extension missing';
			return $result;
		}

		// Bracket IPv6 literals for the ssl:// URL syntax.
		$host_for_url = (strpos($host, ':') !== false && strpos($host, '[') === false) ? '[' . $host . ']' : $host;

		// Clamp timeout to sane bounds and keep it an integer for stream_socket_client.
		$timeout = max(2, min(120, intval($timeout)));

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
			$result['error'] = 'connect failed: ' . $msg . ' (errno ' . intval($errno) . ')';
			return $result;
		}

		// The 4th arg to stream_socket_client governs connect; apply a read timeout
		// to cover the TLS handshake completion as well.
		@stream_set_timeout($stream, $timeout);

		$params = stream_context_get_params($stream);
		fclose($stream);

		if (!isset($params['options']['ssl']['peer_certificate'])) {
			$result['error'] = 'no peer certificate returned';
			return $result;
		}

		$cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
		if (!$cert) {
			$result['error'] = 'cert parse failed';
			return $result;
		}
		if (!isset($cert['validTo_time_t'])) {
			$result['error'] = 'cert missing expiry field';
			return $result;
		}

		$result['days'] = intval(floor(($cert['validTo_time_t'] - time()) / 86400));

		if (isset($cert['issuer']['O'])) {
			$result['issuer'] = $cert['issuer']['O'];
		} elseif (isset($cert['issuer']['CN'])) {
			$result['issuer'] = $cert['issuer']['CN'];
		}

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
