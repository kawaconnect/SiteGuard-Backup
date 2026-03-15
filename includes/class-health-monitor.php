<?php
/**
 * SiteGuard Backup - Multisite Health Monitor
 *
 * Checks HTTP health and SSL certificate expiry for all subsites.
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

		// Time budget: stop checking after 55s to stay under Azure's 60s safe limit
		// and well under PHP max_execution_time (120s default on Azure).
		$deadline = microtime(true) + 55;

		foreach ($targets as $target) {
			if (microtime(true) > $deadline) {
				// Mark remaining sites as unchecked with previous results if available
				$result = array(
					'site_id'         => $target['site_id'],
					'url'             => $target['url'],
					'domain'          => wp_parse_url($target['url'], PHP_URL_HOST),
					'status'          => 'unknown',
					'http_code'       => 0,
					'response_time'   => 0,
					'ssl_expiry_days' => -1,
					'ssl_issuer'      => '',
					'last_check'      => 0,
					'error'           => 'Skipped: time budget exceeded',
				);
				$results[] = $result;
				continue;
			}

			$result = $this->check_site($target['url'], $timeout, $ssl_warn);
			$result['site_id'] = $target['site_id'];
			$result['url'] = $target['url'];
			$result['domain'] = wp_parse_url($target['url'], PHP_URL_HOST);
			$results[] = $result;
		}

		$opts->update_option('sgbackup_health_results', $results);
		$opts->update_option('sgbackup_health_last_run', time());

		return $results;
	}

	/**
	 * Check a single site: HTTP HEAD + SSL certificate inspection.
	 */
	private function check_site($url, $timeout, $ssl_warn_days) {
		$result = array(
			'status'          => 'down',
			'http_code'       => 0,
			'response_time'   => 0,
			'ssl_expiry_days' => -1,
			'ssl_issuer'      => '',
			'last_check'      => time(),
			'error'           => '',
		);

		// HTTP health check
		$start = microtime(true);
		$response = wp_remote_head($url, array(
			'timeout'     => $timeout,
			'sslverify'   => false,
			'redirection' => 3,
			'user-agent'  => 'SiteGuard-Health-Monitor/' . SGBACKUP_VERSION,
		));
		$elapsed = microtime(true) - $start;
		$result['response_time'] = round($elapsed, 3);

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			$result['status'] = 'down';
		} else {
			$code = wp_remote_retrieve_response_code($response);
			$result['http_code'] = intval($code);

			if ($code >= 200 && $code < 400) {
				$result['status'] = 'operational';
			} else {
				$result['status'] = 'down';
				$result['error'] = 'HTTP ' . $code;
			}
		}

		// SSL certificate check
		$host = wp_parse_url($url, PHP_URL_HOST);
		$scheme = wp_parse_url($url, PHP_URL_SCHEME);
		if ($host && $scheme === 'https') {
			$ssl = $this->get_ssl_expiry($host);
			$result['ssl_expiry_days'] = $ssl['days'];
			$result['ssl_issuer'] = $ssl['issuer'];

			// -1 = couldn't check SSL (connection failed), skip SSL status logic
			// 0 or negative (but not -1) = cert expired or expires today
			// positive = cert valid, check against warning threshold
			if ($ssl['days'] !== -1 && $ssl['days'] <= 0) {
				$result['status'] = 'down';
				$result['error'] = $result['error'] ? $result['error'] . '; SSL expired' : 'SSL certificate expired';
			} elseif ($ssl['days'] > 0 && $ssl['days'] <= $ssl_warn_days && $result['status'] === 'operational') {
				$result['status'] = 'degraded';
			}
		}

		// Slow response degrades operational status
		if ($result['status'] === 'operational' && $result['response_time'] > 5.0) {
			$result['status'] = 'degraded';
		}

		return $result;
	}

	/**
	 * Get SSL certificate expiry info for a host.
	 */
	private function get_ssl_expiry($host, $port = 443) {
		$result = array('days' => -1, 'issuer' => '');

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
			'ssl://' . $host . ':' . $port,
			$errno,
			$errstr,
			5,
			STREAM_CLIENT_CONNECT,
			$context
		);

		if (!$stream) {
			return $result;
		}

		$params = stream_context_get_params($stream);
		fclose($stream);

		if (!isset($params['options']['ssl']['peer_certificate'])) {
			return $result;
		}

		$cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
		if (!$cert || !isset($cert['validTo_time_t'])) {
			return $result;
		}

		$result['days'] = intval(floor(($cert['validTo_time_t'] - time()) / 86400));

		if (isset($cert['issuer']['O'])) {
			$result['issuer'] = $cert['issuer']['O'];
		} elseif (isset($cert['issuer']['CN'])) {
			$result['issuer'] = $cert['issuer']['CN'];
		}

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
	 * Key is stored hashed - plaintext is only shown once at generation.
	 */
	public function get_api_key() {
		$opts = SiteGuard_Backup_Options::instance();

		// Migrate legacy plaintext key to hashed storage
		$legacy = $opts->get_option('sgbackup_health_api_key', '');
		if (!empty($legacy)) {
			$opts->update_option('sgbackup_health_api_key_hash', wp_hash_password($legacy));
			$opts->delete_option('sgbackup_health_api_key');
			return $legacy; // Return plaintext one last time during migration
		}

		$hash = $opts->get_option('sgbackup_health_api_key_hash', '');
		if (empty($hash)) {
			// No key exists yet - generate one
			return $this->regenerate_api_key();
		}
		// Return empty - hash exists but we never store the plaintext
		return '';
	}

	/**
	 * Regenerate the API key. Returns the plaintext key (only time it's visible).
	 */
	public function regenerate_api_key() {
		$key = wp_generate_password(48, false, false);
		$opts = SiteGuard_Backup_Options::instance();
		// Store only the hash - plaintext is never persisted
		$opts->update_option('sgbackup_health_api_key_hash', wp_hash_password($key));
		// Clean up any legacy plaintext key from pre-security-hardening versions
		$opts->delete_option('sgbackup_health_api_key');
		return $key;
	}

	/**
	 * Validate a provided API key against the stored hash.
	 */
	public function validate_api_key($key) {
		if (empty($key) || strlen($key) < 16) return false;

		$opts = SiteGuard_Backup_Options::instance();

		// Handle legacy plaintext key - migrate to hashed on first validation
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

	/**
	 * Check if the API key has been generated (hash exists).
	 */
	public function has_api_key() {
		$opts = SiteGuard_Backup_Options::instance();
		$hash = $opts->get_option('sgbackup_health_api_key_hash', '');
		return !empty($hash);
	}

	/**
	 * Rate-limit failed auth attempts by IP.
	 * Returns true if the IP is currently rate-limited.
	 */
	public function is_rate_limited($ip) {
		$key = 'sgbackup_health_rl_' . md5($ip);
		$attempts = (int) get_transient($key);
		return $attempts >= self::$AUTH_RATE_LIMIT;
	}

	/**
	 * Record a failed auth attempt for an IP.
	 */
	public function record_failed_auth($ip) {
		$key = 'sgbackup_health_rl_' . md5($ip);
		$attempts = (int) get_transient($key);
		set_transient($key, $attempts + 1, self::$AUTH_RATE_WINDOW);
	}

	/**
	 * Get client IP, respecting Cloudflare/proxy headers.
	 */
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
