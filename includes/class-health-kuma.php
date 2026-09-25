<?php
/**
 * SiteGuard Backup - Uptime Kuma push for the health monitor.
 *
 * After every health run (cron, "Check Now", WP-CLI, REST) the monitor sends one
 * heartbeat to an Uptime Kuma "Push" monitor:
 *
 *   GET <push url without query>?status=up|down&msg=<summary>&ping=<run ms>
 *
 * Kuma alerts when a heartbeat says down, and also when heartbeats stop
 * arriving, so a dead PHP, database or WP-Cron is caught too.
 *
 * The push URL carries Kuma's token, so it is treated as a secret: it is shown
 * masked after saving, never logged, and never returned by the REST API, AJAX
 * or WP-CLI. It is not a default setting, so settings export leaves it out.
 *
 * The static helpers at the top are pure PHP (no WordPress calls) and are
 * unit-tested by tests/test-health-kuma.php.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Health_Kuma {

	const OPTION      = 'sgbackup_health_kuma_url';
	const LAST_OPTION = 'sgbackup_health_kuma_last';
	const TIMEOUT     = 10;
	const MSG_MAX     = 200;
	/** Days left at which an expiring certificate counts as critical (as in the REST alerts). */
	const SSL_CRITICAL_DAYS = 3;

	// ---------------------------------------------------------------------
	// Pure helpers
	// ---------------------------------------------------------------------

	/**
	 * Validate and normalise a push URL: https, a host, no user info, and a path
	 * ending in /api/push/<token>. Query and fragment are dropped (Kuma's copy
	 * button appends ?status=up&msg=OK&ping=).
	 *
	 * @return string|false The base URL, or false when invalid.
	 */
	public static function normalize_url($url) {
		if (!is_string($url)) return false;
		$url = trim($url);
		if ('' === $url || strlen($url) > 2048 || preg_match('/[\s\x00-\x1f\x7f]/', $url)) return false;
		$p = parse_url($url);
		if (!is_array($p) || empty($p['scheme']) || 'https' !== strtolower($p['scheme']) || empty($p['host'])) return false;
		if (isset($p['user']) || isset($p['pass'])) return false;
		if (!preg_match('/^[A-Za-z0-9.-]+$|^\[[0-9A-Fa-f:.]+\]$/', $p['host'])) return false;
		$path = isset($p['path']) ? $p['path'] : '';
		if (!preg_match('#^(/[A-Za-z0-9._~%-]+)*/api/push/[A-Za-z0-9_-]{4,128}/?$#', $path)) return false;
		$port = isset($p['port']) ? ':' . intval($p['port']) : '';
		return 'https://' . strtolower($p['host']) . $port . $path;
	}

	/**
	 * The push token (last path segment) of a normalised URL.
	 */
	public static function token_of($url) {
		return preg_match('#/api/push/([A-Za-z0-9_-]+)/?$#', (string) $url, $m) ? $m[1] : '';
	}

	/**
	 * Mask a push URL for display: host and path kept, the token replaced by
	 * asterisks and at most its last 4 characters (none when it is short).
	 */
	public static function mask_url($url) {
		$token = self::token_of($url);
		if ('' === $token) return '';
		$tail = strlen($token) >= 12 ? substr($token, -4) : '';
		$pos = strrpos($url, $token);
		return substr($url, 0, $pos) . '****' . $tail . substr($url, $pos + strlen($token));
	}

	/**
	 * Replace the URL and its token in any text (error messages) by the mask.
	 */
	public static function scrub($text, $url) {
		$text = (string) $text;
		$token = self::token_of($url);
		if ('' === $token) return $text;
		$mask = self::mask_url($url);
		$text = str_replace($url, $mask, $text);
		return str_replace($token, '****', $text);
	}

	/**
	 * The full GET URL for one heartbeat.
	 */
	public static function build_push_url($base, $status, $msg, $ping_ms) {
		return $base . '?' . http_build_query(array(
			'status' => ('up' === $status) ? 'up' : 'down',
			'msg'    => (string) $msg,
			'ping'   => max(0, intval($ping_ms)),
		), '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Decide status and message for a set of health results.
	 *
	 * down: any site is down (HTTP failure or expired certificate), or a freshly
	 *       probed certificate expires within SSL_CRITICAL_DAYS days (the REST
	 *       API's critical alerts), or there are no results / the run failed.
	 * up:   everything else. Warnings (slow, SSL probe failed, certificate
	 *       expiring within the warning threshold, skipped by the time budget)
	 *       are named in the message but do not page.
	 *
	 * @param array|null $results   Site rows from run_checks(), null if the run failed.
	 * @param int        $warn_days SSL warning threshold.
	 * @param string     $run_error Short reason when the run itself failed.
	 * @return array array('status' => 'up'|'down', 'msg' => string)
	 */
	public static function build_message($results, $warn_days = 14, $run_error = '') {
		if ('' !== (string) $run_error) {
			return array('status' => 'down', 'msg' => self::fit('health run failed: ' . $run_error, array()));
		}
		if (!is_array($results) || empty($results)) {
			return array('status' => 'down', 'msg' => 'no sites checked');
		}

		$total = count($results);
		$operational = 0;
		$critical = array(); // label => array(items)
		$warnings = array();
		foreach ($results as $site) {
			if (!is_array($site)) continue;
			$status = isset($site['status']) ? (string) $site['status'] : 'unknown';
			$domain = self::site_label($site);
			$http = isset($site['http_code']) ? intval($site['http_code']) : 0;
			$error = isset($site['error']) ? (string) $site['error'] : '';
			$days = isset($site['ssl_expiry_days']) ? intval($site['ssl_expiry_days']) : -1;
			$stale = !empty($site['ssl_stale']);
			$ssl_ok = !empty($site['ssl_ok']);
			$ssl_error = isset($site['ssl_error']) ? (string) $site['ssl_error'] : '';
			$is_https = isset($site['url']) && 0 === stripos((string) $site['url'], 'https://');
			$http_up = ($http >= 200 && $http < 400) || (403 === $http && isset($site['check_method']) && 'localhost' === $site['check_method']);

			if ('operational' === $status) $operational++;

			if ('down' === $status) {
				if ($http_up && false !== stripos($error, 'SSL') && false !== stripos($error, 'expired')) {
					$critical['ssl_expired'][] = $domain;
				} else {
					$critical['down'][] = $domain . ' (' . self::short_reason($http, $error) . ')';
				}
				continue;
			}
			if ('unknown' === $status) {
				$warnings['skipped'][] = $domain;
				continue;
			}
			if ($is_https && !$stale && $ssl_ok && $days >= 0) {
				if ($days <= self::SSL_CRITICAL_DAYS) {
					$critical['ssl_expiring'][] = $domain . ' (' . $days . 'd)';
					continue;
				}
				if ($days <= $warn_days) {
					$warnings['ssl_expiring'][] = $domain . ' (' . $days . 'd)';
					continue;
				}
			}
			if ($is_https && !$ssl_ok && '' !== $ssl_error) {
				$warnings['ssl_probe_failed'][] = $domain;
				continue;
			}
			if ('degraded' === $status) {
				$rt = isset($site['response_time']) ? (float) $site['response_time'] : 0;
				$warnings['slow'][] = $domain . ($rt > 0 ? ' (' . round($rt, 1) . 's)' : '');
			}
		}

		if (!empty($critical)) {
			$failing = 0;
			foreach ($critical as $items) $failing += count($items);
			return array('status' => 'down', 'msg' => self::fit($failing . '/' . $total . ' failing', self::merge_groups($critical, $warnings)));
		}
		return array('status' => 'up', 'msg' => self::fit($operational . '/' . $total . ' sites operational', $warnings));
	}

	/**
	 * "example.com", or "example.com/shop" for a subdirectory site.
	 */
	private static function site_label($site) {
		$domain = !empty($site['domain']) ? (string) $site['domain'] : '';
		$path = isset($site['url']) ? (string) parse_url((string) $site['url'], PHP_URL_PATH) : '';
		if ('' === $domain) {
			return isset($site['url']) ? (string) $site['url'] : '?';
		}
		$path = trim($path, '/');
		return '' === $path ? $domain : $domain . '/' . $path;
	}

	/**
	 * Critical groups first, then warnings (same label merged).
	 */
	private static function merge_groups($a, $b) {
		foreach ($b as $label => $items) {
			$a[$label] = isset($a[$label]) ? array_merge($a[$label], $items) : $items;
		}
		return $a;
	}

	/**
	 * "HTTP 503", "timeout", "unreachable" etc. from a site row.
	 */
	private static function short_reason($http, $error) {
		if ($http > 0 && ($http < 200 || $http >= 400)) return 'HTTP ' . $http;
		$e = strtolower($error);
		if ('' === $e) return 'unreachable';
		if (false !== strpos($e, 'timed out') || false !== strpos($e, 'timeout')) return 'timeout';
		if (false !== strpos($e, 'resolve')) return 'DNS';
		if (false !== strpos($e, 'refused')) return 'refused';
		if (false !== strpos($e, 'ssl')) return 'SSL';
		$e = preg_replace('/^curl error \d+:\s*/', '', $e);
		return substr(preg_replace('/[^a-z0-9 .:_-]/', '', $e), 0, 24);
	}

	/**
	 * "head; label: a, b; label: c" cut to MSG_MAX characters, ending in
	 * "+N more" when items had to be left out.
	 *
	 * @param string $head
	 * @param array  $groups Ordered label => items.
	 */
	private static function fit($head, $groups) {
		$parts = array();
		foreach ($groups as $label => $items) {
			foreach ((array) $items as $item) $parts[] = array($label, (string) $item);
		}
		$msg = $head;
		$last_label = null;
		$n = count($parts);
		foreach ($parts as $idx => $part) {
			$piece = ($part[0] === $last_label ? ', ' : '; ' . $part[0] . ': ') . $part[1];
			$more = ($idx < $n - 1) ? ' +' . ($n - 1 - $idx) . ' more' : '';
			if (strlen($msg . $piece . $more) > self::MSG_MAX) {
				return substr($msg . ' +' . ($n - $idx) . ' more', 0, self::MSG_MAX);
			}
			$msg .= $piece;
			$last_label = $part[0];
		}
		return substr($msg, 0, self::MSG_MAX);
	}

	/**
	 * Recommended Kuma heartbeat interval (seconds) for a health check interval:
	 * two check intervals plus the longest possible run (55 s budget + push).
	 */
	public static function recommended_heartbeat($check_interval) {
		$check_interval = max(60, intval($check_interval));
		return 2 * $check_interval + 120;
	}

	// ---------------------------------------------------------------------
	// Storage (network-level: the main site's options on multisite)
	// ---------------------------------------------------------------------

	private static function on_main_site($fn) {
		$switched = false;
		if (is_multisite() && !is_main_site()) {
			switch_to_blog(get_main_site_id());
			$switched = true;
		}
		try {
			return $fn(SiteGuard_Backup_Options::instance());
		} finally {
			if ($switched) restore_current_blog();
		}
	}

	/** The stored base URL ('' when not configured). Never display or log it. */
	public static function get_url() {
		return self::on_main_site(function ($opts) {
			$url = self::normalize_url((string) $opts->get_option(self::OPTION, ''));
			return $url ? $url : '';
		});
	}

	public static function is_configured() {
		return '' !== self::get_url();
	}

	/**
	 * Save a push URL.
	 *
	 * @return string|WP_Error The masked URL.
	 */
	public static function set_url($url) {
		$base = self::normalize_url($url);
		if (!$base) {
			return new WP_Error('invalid_kuma_url', __('Enter the Push URL of an Uptime Kuma "Push" monitor: https://<your kuma host>/api/push/<token>.', 'siteguardbackup'));
		}
		self::on_main_site(function ($opts) use ($base) {
			$opts->update_option(self::OPTION, $base);
			$opts->delete_option(self::LAST_OPTION);
		});
		return self::mask_url($base);
	}

	public static function clear_url() {
		self::on_main_site(function ($opts) {
			$opts->delete_option(self::OPTION);
			$opts->delete_option(self::LAST_OPTION);
		});
	}

	/** Result of the last push (no URL in it). */
	public static function get_last() {
		return self::on_main_site(function ($opts) {
			$last = $opts->get_option(self::LAST_OPTION, array());
			return is_array($last) ? $last : array();
		});
	}

	/**
	 * Masked status for the UI and WP-CLI.
	 */
	public static function get_status() {
		$url = self::get_url();
		return array(
			'configured' => '' !== $url,
			'masked'     => '' !== $url ? self::mask_url($url) : '',
			'last'       => self::get_last(),
		);
	}

	/**
	 * One-line description for WP-CLI output.
	 */
	public static function describe() {
		$s = self::get_status();
		if (!$s['configured']) return 'not configured';
		$line = $s['masked'];
		$l = $s['last'];
		if (!empty($l['time'])) {
			$line .= ' | last push ' . ($l['ok'] ? 'OK' : 'FAILED') . ' (status=' . $l['status']
				. ($l['code'] ? ', HTTP ' . intval($l['code']) : '') . ($l['error'] ? ', ' . $l['error'] : '')
				. ') ' . human_time_diff(intval($l['time'])) . ' ago';
		} else {
			$line .= ' | no push yet';
		}
		return $line;
	}

	/**
	 * Why pushes may arrive irregularly, for the UI and WP-CLI.
	 *
	 * @return string[] Warnings (translated).
	 */
	public static function cron_warnings($check_interval, $last_run, $enabled) {
		$out = array();
		if (!$enabled) {
			$out[] = __('The health monitor is disabled, so nothing is pushed and Uptime Kuma will report this site as down.', 'siteguardbackup');
			return $out;
		}
		if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
			$out[] = __('WP-Cron runs only when someone visits the site, so on a quiet site health checks (and pushes) run late and Uptime Kuma may report missed heartbeats. Set define(\'DISABLE_WP_CRON\', true) in wp-config.php and run WP-Cron from a system cron every minute, e.g. "* * * * * wp --path=/var/www/html cron event run --due-now" or a curl of wp-cron.php.', 'siteguardbackup');
		}
		$late = 2 * max(60, intval($check_interval)) + 120;
		if ($last_run && (time() - intval($last_run)) > $late) {
			/* translators: %s: time since the last health run */
			$out[] = sprintf(__('The last health run was %s ago, longer than two check intervals: WP-Cron does not seem to be running. Uptime Kuma will report this as down.', 'siteguardbackup'), human_time_diff(intval($last_run)));
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Push
	// ---------------------------------------------------------------------

	/**
	 * Push the outcome of a health run. Called at the end of every run, also
	 * when the run threw. Never throws; failures are logged without the URL.
	 *
	 * @param array|null $results  Site rows, null if the run failed.
	 * @param float      $started  microtime(true) at the start of the run.
	 * @param Throwable|null $error The run's exception, if any.
	 * @return array|null The push record, null when no push URL is set.
	 */
	public static function after_run($results, $started, $error = null) {
		try {
			if (!self::is_configured()) return null;
			$opts = SiteGuard_Backup_Options::instance();
			$warn = intval($opts->get_option('sgbackup_health_ssl_warn_days', 14));
			$run_error = $error ? get_class($error) : '';
			$m = self::build_message($results, $warn, $run_error);
			return self::push($m['status'], $m['msg'], (int) round((microtime(true) - $started) * 1000));
		} catch (Throwable $e) {
			error_log('SiteGuard Backup: Uptime Kuma push skipped: ' . get_class($e));
			return null;
		}
	}

	/**
	 * Push one heartbeat now from the cached results (WP-CLI --test, UI button).
	 *
	 * @return array|WP_Error The push record.
	 */
	public static function test_push() {
		if (!self::is_configured()) {
			return new WP_Error('kuma_not_configured', __('No Uptime Kuma push URL is set.', 'siteguardbackup'));
		}
		$opts = SiteGuard_Backup_Options::instance();
		$results = self::on_main_site(function ($o) {
			return $o->get_option('sgbackup_health_results', array());
		});
		if (empty($results)) {
			return self::push('up', 'test push from SiteGuard (no health data yet)', 0);
		}
		$m = self::build_message($results, intval($opts->get_option('sgbackup_health_ssl_warn_days', 14)));
		return self::push($m['status'], 'test: ' . $m['msg'], 0);
	}

	/**
	 * Send one heartbeat and record the outcome.
	 */
	private static function push($status, $msg, $ping_ms) {
		$url = self::get_url();
		$msg = substr((string) $msg, 0, self::MSG_MAX);
		$record = array(
			'time'   => time(),
			'ok'     => false,
			'code'   => 0,
			'error'  => '',
			'status' => ('up' === $status) ? 'up' : 'down',
			'msg'    => $msg,
			'ping'   => max(0, intval($ping_ms)),
		);
		// No redirects: a push URL answers directly, and following one would send
		// the heartbeat (and the token in its path) wherever the server points.
		// Not wp_safe_remote_get(): self-hosted Kuma usually sits on a private
		// address and port 3001, both of which it refuses.
		$response = wp_remote_get(self::build_push_url($url, $status, $msg, $ping_ms), array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'limit_response_size' => 8192,
			'user-agent'          => 'SiteGuard-Health-Monitor/' . (defined('SGBACKUP_VERSION') ? SGBACKUP_VERSION : ''),
		));
		if (is_wp_error($response)) {
			$record['error'] = substr(self::scrub($response->get_error_message(), $url), 0, 200);
		} else {
			$record['code'] = intval(wp_remote_retrieve_response_code($response));
			$body = json_decode((string) wp_remote_retrieve_body($response), true);
			$kuma_ok = !(is_array($body) && array_key_exists('ok', $body) && !$body['ok']);
			$record['ok'] = $record['code'] >= 200 && $record['code'] < 300 && $kuma_ok;
			if (!$record['ok']) {
				$reason = (is_array($body) && !empty($body['msg']) && is_string($body['msg'])) ? $body['msg'] : 'HTTP ' . $record['code'];
				$record['error'] = substr(self::scrub(sanitize_text_field($reason), $url), 0, 200);
			}
		}

		$previous = self::get_last();
		self::on_main_site(function ($opts) use ($record) {
			$opts->update_option(self::LAST_OPTION, $record);
		});

		// Log on change only, so a Kuma outage does not write a line every run.
		$prev_ok = empty($previous) || !empty($previous['ok']);
		$prev_err = isset($previous['error']) ? (string) $previous['error'] : '';
		if (!$record['ok'] && ($prev_ok || $prev_err !== $record['error'])) {
			error_log('SiteGuard Backup: Uptime Kuma push failed: ' . ($record['error'] !== '' ? $record['error'] : 'unknown error'));
		} elseif ($record['ok'] && !$prev_ok) {
			error_log('SiteGuard Backup: Uptime Kuma push working again');
		}
		return $record;
	}
}
