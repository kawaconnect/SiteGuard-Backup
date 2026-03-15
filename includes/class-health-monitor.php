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
	 */
	public function get_api_key() {
		$opts = SiteGuard_Backup_Options::instance();
		$key = $opts->get_option('sgbackup_health_api_key', '');
		if (empty($key)) {
			$key = $this->regenerate_api_key();
		}
		return $key;
	}

	/**
	 * Regenerate the API key.
	 */
	public function regenerate_api_key() {
		$key = wp_generate_password(48, false, false);
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_health_api_key', $key);
		return $key;
	}

	/**
	 * Validate a provided API key.
	 */
	public function validate_api_key($key) {
		if (empty($key)) return false;
		$stored = $this->get_api_key();
		return hash_equals($stored, $key);
	}
}
