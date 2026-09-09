<?php
/**
 * Plugin Name: SiteGuard Backup
 * Description: Complete WordPress backup, restore, and migration solution with scheduled, incremental, and site-to-site migration support.
 * Version: 1.4.17
 * Author: KawaConnect LLC
 * License: GPL-2.0-or-later
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: siteguardbackup
 */

if (!defined('ABSPATH')) die('No direct access.');

// Core constants
define('SGBACKUP_VERSION', '1.4.17');
define('SGBACKUP_DIR', plugin_dir_path(__FILE__));
define('SGBACKUP_URL', plugin_dir_url(__FILE__));
define('SGBACKUP_PLUGIN_SLUG', plugin_basename(__FILE__));

// Backup engine constants
define('SGBACKUP_SPLIT_MIN', 25);           // Minimum split size in MB
define('SGBACKUP_SET_TIME_LIMIT', 900);     // Max execution time in seconds
define('SGBACKUP_MAXBATCHFILES', 1000);     // Max files per zip batch
define('SGBACKUP_WARN_FILE_SIZE', 262144000); // 250MB file warning
define('SGBACKUP_WARN_DB_ROWS', 150000);    // Row count warning
define('SGBACKUP_WARN_EMAIL_SIZE', 20971520); // 20MB email warning
define('SGBACKUP_DEFAULT_SPLIT', 400);      // Default split size in MB

// Extensions to store without compression in zip
define('SGBACKUP_ZIP_NOCOMPRESS', '.jpg,.jpeg,.png,.gif,.bmp,.webp,.avif,.mp4,.mp3,.avi,.mov,.mkv,.zip,.gz,.bz2,.xz,.rar,.7z,.pdf,.woff,.woff2,.ttf,.eot');

// Default excludes
define('SGBACKUP_DEFAULT_OTHERS_EXCLUDE', 'upgrade,cache,updraft,backup*,*backups,mysql.sql,debug.log,sgbackup,ai1wm-backups');
define('SGBACKUP_DEFAULT_UPLOADS_EXCLUDE', 'backup*,*backups,backwpup*,wp-clone,snapshots,wp-staging');

/**
 * Register custom cron schedules.
 */
function sgbackup_cron_schedules($schedules) {
	$new = array(
		'every5min' => array(
			'interval' => 300,
			'display'  => __('Every 5 minutes', 'siteguardbackup'),
		),
		'every10min' => array(
			'interval' => 600,
			'display'  => __('Every 10 minutes', 'siteguardbackup'),
		),
		'every15min' => array(
			'interval' => 900,
			'display'  => __('Every 15 minutes', 'siteguardbackup'),
		),
		'every30min' => array(
			'interval' => 1800,
			'display'  => __('Every 30 minutes', 'siteguardbackup'),
		),
		'everyhour' => array(
			'interval' => 3600,
			'display'  => __('Every hour', 'siteguardbackup'),
		),
		'every2hours' => array(
			'interval' => 7200,
			'display'  => __('Every 2 hours', 'siteguardbackup'),
		),
		'every4hours' => array(
			'interval' => 14400,
			'display'  => __('Every 4 hours', 'siteguardbackup'),
		),
		'every8hours' => array(
			'interval' => 28800,
			'display'  => __('Every 8 hours', 'siteguardbackup'),
		),
		'weekly' => array(
			'interval' => 604800,
			'display'  => __('Weekly', 'siteguardbackup'),
		),
		'fortnightly' => array(
			'interval' => 1209600,
			'display'  => __('Fortnightly', 'siteguardbackup'),
		),
		'monthly' => array(
			'interval' => 2592000,
			'display'  => __('Monthly', 'siteguardbackup'),
		),
	);

	foreach ($new as $key => $val) {
		if (!isset($schedules[$key])) {
			$schedules[$key] = $val;
		}
	}

	return $schedules;
}
add_filter('cron_schedules', 'sgbackup_cron_schedules', 30);

/**
 * Performance gate: only load on admin, cron, CLI, or AJAX.
 */
function sgbackup_should_load() {
	if (defined('DOING_CRON') && DOING_CRON) return true;
	if (defined('WP_CLI') && WP_CLI) return true;
	if (is_admin()) return true;
	if (defined('DOING_AJAX') && DOING_AJAX) return true;
	if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;

	return false;
}

/**
 * Returns true when the plugin is running on a multisite subsite and is NOT
 * network-activated - i.e. the site admin activated SiteGuard on their own
 * subsite via the per-site plugins screen. In that mode backups must default
 * to the current subsite only (never dump the whole network).
 */
function sgbackup_is_single_site_mode() {
	if (!is_multisite()) return false;
	if (get_current_blog_id() <= 1) return false;

	if (!function_exists('is_plugin_active_for_network')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return !is_plugin_active_for_network(plugin_basename(__FILE__));
}

/**
 * Load core class files.
 */
function sgbackup_load_classes() {
	require_once SGBACKUP_DIR . 'options.php';
	require_once SGBACKUP_DIR . 'includes/class-backup-history.php';
	require_once SGBACKUP_DIR . 'includes/class-job-scheduler.php';
	require_once SGBACKUP_DIR . 'includes/class-filesystem-functions.php';
	require_once SGBACKUP_DIR . 'includes/class-database-utility.php';
	require_once SGBACKUP_DIR . 'includes/class-semaphore.php';
	require_once SGBACKUP_DIR . 'includes/class-audit-log.php';
	require_once SGBACKUP_DIR . 'includes/class-webhook.php';
}

/**
 * Bootstrap the plugin.
 */
function sgbackup_init() {
	if (!sgbackup_should_load()) {
		// Still register cron hooks even if not loading full plugin
		sgbackup_register_cron_hooks();
		return;
	}

	sgbackup_load_classes();
	sgbackup_register_cron_hooks();

	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance();

	if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
		require_once SGBACKUP_DIR . 'admin.php';
		SiteGuard_Backup_Admin::instance();
	}

	// WP-CLI commands
	if (defined('WP_CLI') && WP_CLI) {
		require_once SGBACKUP_DIR . 'includes/class-wp-cli.php';
		WP_CLI::add_command('siteguardbackup', 'SiteGuard_Backup_CLI_Command');
	}

	// Multisite: dispatch subsite cron events from main site during cron runs
	if (is_multisite() && is_main_site() && defined('DOING_CRON') && DOING_CRON) {
		add_action('wp_loaded', 'sgbackup_multisite_cron_dispatch');
	}
}
add_action('plugins_loaded', 'sgbackup_init', 1);

/**
 * Check for missed scheduled backups and fire webhook if overdue.
 */
function sgbackup_check_schedule_missed() {
	if (!sgbackup_should_load()) {
		return;
	}

	// Cooldown: only check once per 24 hours
	$opts = SiteGuard_Backup_Options::instance();
	$last_check = (int) $opts->get_option('sgbackup_schedule_missed_notified', 0);
	if ($last_check && (time() - $last_check) < 86400) {
		return;
	}

	// Check each backup cron hook for overdue scheduling
	$hooks = array('sgbackup_backup', 'sgbackup_backup_database', 'sgbackup_backup_incremental', 'sgbackup_backup_remotesend');

	foreach ($hooks as $hook) {
		$event = wp_get_scheduled_event($hook);
		if (!$event || empty($event->interval)) {
			continue; // Not scheduled or single-fire event
		}

		$next = $event->timestamp;
		$interval = (int) $event->interval;

		// If overdue by more than 1.5x the interval, it's missed
		$overdue = time() - $next;
		if ($overdue > ($interval * 1.5)) {
			SiteGuard_Backup_Webhook::fire('schedule_missed', array(
				'hook'         => $hook,
				'scheduled_at' => $next,
				'overdue_by'   => $overdue,
				'interval'     => $interval,
			));
			$opts->update_option('sgbackup_schedule_missed_notified', time());
			return; // One notification per cooldown period
		}
	}
}
add_action('wp_loaded', 'sgbackup_check_schedule_missed');
add_action('wp_loaded', 'sgbackup_ensure_health_cron');

/**
 * Register cron action hooks (lightweight, always loaded).
 */
function sgbackup_register_cron_hooks() {
	add_action('sgbackup_backup', 'sgbackup_cron_backup_files');
	add_action('sgbackup_backup_database', 'sgbackup_cron_backup_database');
	add_action('sgbackup_backup_all', 'sgbackup_cron_backup_all');
	add_action('sgbackup_backup_resume', 'sgbackup_cron_backup_resume', 10, 2);
	add_action('sgbackup_backup_incremental', 'sgbackup_cron_backup_incremental');
	add_action('sgbackup_backup_remotesend', 'sgbackup_cron_backup_remotesend');
	add_action('sgbackup_health_check', 'sgbackup_cron_health_check');
}

function sgbackup_cron_backup_files() {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_files();
}

function sgbackup_cron_backup_database() {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_database();
}

function sgbackup_cron_backup_all($options = array()) {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_all($options);
}

function sgbackup_cron_backup_resume($resumption_no, $nonce) {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_resume($resumption_no, $nonce);
}

function sgbackup_cron_backup_incremental() {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_incremental();
}

function sgbackup_cron_health_check() {
	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	if ( ! $opts->get_option( 'sgbackup_health_enabled', false ) ) {
		return;
	}
	require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
	SiteGuard_Backup_Health_Monitor::instance()->run_checks();
}

/**
 * Self-healing: re-register the health check cron if it's enabled but missing.
 * WordPress cron events can silently disappear (plugin updates, DB issues, etc).
 */
function sgbackup_ensure_health_cron() {
	if ( ! sgbackup_should_load() ) {
		return; // never load the full class set on anonymous front-end views
	}
	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	if ( ! $opts->get_option( 'sgbackup_health_enabled', false ) ) {
		return;
	}
	if ( wp_next_scheduled( 'sgbackup_health_check' ) ) {
		return; // Already scheduled, nothing to do
	}
	$interval = $opts->get_option( 'sgbackup_health_interval', 'every10min' );
	$schedules = wp_get_schedules();
	if ( ! isset( $schedules[ $interval ] ) ) {
		$interval = 'every10min'; // unknown recurrence would fail (and retry) on every request
	}
	wp_schedule_event( time() + 60, $interval, 'sgbackup_health_check' );
	if ( function_exists( 'sgbackup_health_log' ) ) {
		sgbackup_health_log( 'Re-registered missing health check cron (interval: ' . $interval . ')' );
	}
}

function sgbackup_cron_backup_remotesend() {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';

	$opts = SiteGuard_Backup_Options::instance();
	$destinations = $opts->get_option('sgbackup_remotesend_destinations', array());
	if (empty($destinations)) return;

	$entities = $opts->get_option('sgbackup_remotesend_entities', array());
	$retain = intval($opts->get_option('sgbackup_remotesend_retain', 5));

	// Build service array from destinations
	$service = array();
	foreach ($destinations as $site_id) {
		$service[] = 'remotesend/' . intval($site_id);
	}

	$options = array(
		'service' => $service,
		'label'   => 'Scheduled remote send',
	);
	if (!empty($entities)) {
		$options['entities'] = $entities;
	}
	if ($retain > 0) {
		$options['retain'] = $retain;
	}

	SiteGuard_Backup::instance()->backup_all($options);
}

/**
 * Multisite cron dispatcher: fire due sgbackup cron events on subsites.
 *
 * WordPress cron only fires events for the site that receives the request.
 * On environments where subsites don't receive regular traffic (standby,
 * failover, or single-entry cron runners), their scheduled events never fire.
 * This dispatcher runs on the main site's cron and directly executes any
 * due sgbackup events on each subsite via switch_to_blog().
 */
function sgbackup_multisite_cron_dispatch() {
	if (!is_multisite() || !is_main_site()) return;

	// Throttle: run at most every 4 minutes (main site option, not per-site)
	$last_run = (int) get_option('sgbackup_cron_dispatch_last', 0);
	if ((time() - $last_run) < 240) return;
	update_option('sgbackup_cron_dispatch_last', time(), true);

	$sgb_hooks = array(
		'sgbackup_backup', 'sgbackup_backup_database', 'sgbackup_backup_all',
		'sgbackup_backup_incremental', 'sgbackup_backup_remotesend', 'sgbackup_backup_resume',
		'sgbackup_health_check',
	);

	$sites = get_sites(array('fields' => 'ids', 'number' => 0));
	$gmt_time = microtime(true);

	foreach ($sites as $site_id) {
		if ((int) $site_id === (int) get_main_site_id()) continue;

		switch_to_blog($site_id);

		$crons = _get_cron_array();
		if (is_array($crons)) {
			foreach ($crons as $timestamp => $cronhooks) {
				if ($timestamp > $gmt_time) break;

				foreach ($cronhooks as $hook => $keys) {
					if (!in_array($hook, $sgb_hooks, true)) continue;

					foreach ($keys as $k => $v) {
						if (!empty($v['schedule'])) {
							wp_reschedule_event($timestamp, $v['schedule'], $hook, $v['args']);
						}
						wp_unschedule_event($timestamp, $hook, $v['args']);
						do_action_ref_array($hook, $v['args']);
					}
				}
			}
		}

		restore_current_blog();
	}
}

/**
 * Activation hook.
 */
function sgbackup_activate() {
	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	$opts->get_backup_dir(); // Create and protect backup directory
}
register_activation_hook(__FILE__, 'sgbackup_activate');

/**
 * Deactivation hook.
 */
function sgbackup_deactivate() {
	wp_clear_scheduled_hook('sgbackup_backup');
	wp_clear_scheduled_hook('sgbackup_backup_database');
	wp_clear_scheduled_hook('sgbackup_backup_all');
	wp_clear_scheduled_hook('sgbackup_backup_incremental');
	wp_clear_scheduled_hook('sgbackup_backup_remotesend');
	wp_clear_scheduled_hook('sgbackup_health_check');
	// Don't clear sgbackup_backup_resume - let running jobs finish
}
register_deactivation_hook(__FILE__, 'sgbackup_deactivate');

/**
 * Gap 13: Auto-update this plugin when the option is enabled.
 */
function sgbackup_auto_update_plugin($update, $item) {
	if (!isset($item->plugin)) return $update;
	if ($item->plugin !== SGBACKUP_PLUGIN_SLUG) return $update;

	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	if ($opts->get_option('sgbackup_auto_update', false)) {
		return true;
	}
	return $update;
}
add_filter('auto_update_plugin', 'sgbackup_auto_update_plugin', 10, 2);

/**
 * Load migration listeners on plugins_loaded (needs early loading).
 */
function sgbackup_load_migration_listeners() {
	if (!function_exists('sgbackup_load_classes')) return;
	sgbackup_load_classes();

	$opts = SiteGuard_Backup_Options::instance();
	$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());
	if (!empty($local_keys)) {
		require_once SGBACKUP_DIR . 'includes/class-remote-send.php';
		SiteGuard_Backup_Remote_Send::instance();
	}
}
add_action('plugins_loaded', 'sgbackup_load_migration_listeners', 2);

/**
 * Register REST API endpoints for external health monitoring (Discord bot).
 *
 * Endpoints (all require `Authorization: Bearer <api_key>`):
 *   GET  /wp-json/sgbackup/v1/health        - full health payload (cached)
 *   GET  /wp-json/sgbackup/v1/health/ping   - auth smoke test, minimal body
 *   POST /wp-json/sgbackup/v1/health/check  - trigger an on-demand run, returns fresh payload
 */
function sgbackup_register_health_api() {
	register_rest_route('sgbackup/v1', '/health', array(
		'methods'             => 'GET',
		'callback'            => 'sgbackup_health_api_callback',
		'permission_callback' => 'sgbackup_health_api_auth',
	));
	register_rest_route('sgbackup/v1', '/health/ping', array(
		'methods'             => 'GET',
		'callback'            => 'sgbackup_health_api_ping_callback',
		'permission_callback' => 'sgbackup_health_api_auth',
	));
	register_rest_route('sgbackup/v1', '/health/check', array(
		'methods'             => 'POST',
		'callback'            => 'sgbackup_health_api_check_callback',
		'permission_callback' => 'sgbackup_health_api_auth',
	));
}
add_action('rest_api_init', 'sgbackup_register_health_api');

/**
 * Bot-facing auth smoke test. Returns 200 + small body so clients can verify
 * the API key without pulling the full site list (and without triggering a
 * health run).
 */
function sgbackup_health_api_ping_callback(WP_REST_Request $request) {
	return rest_ensure_response(array(
		'ok'          => true,
		'site_url'    => get_site_url(),
		'version'     => SGBACKUP_VERSION,
		'api_version' => 2,
		'time'        => time(),
	));
}

/**
 * Trigger an on-demand health run (same path as the admin "Check Now" button),
 * then return the fresh payload. Rate-limited by the existing auth rate limiter
 * plus a short per-token cooldown so a misbehaving bot can't hammer the cron.
 */
function sgbackup_health_api_check_callback(WP_REST_Request $request) {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';

	// Cooldown: at most one bot-triggered run every 30 seconds, regardless of
	// which key called. The cron tick is still free to run on its own schedule.
	$cooldown_key = 'sgbackup_health_bot_check_cooldown';
	if (get_transient($cooldown_key)) {
		return new WP_Error(
			'cooldown',
			'On-demand health check is on cooldown. Poll GET /health for cached data or retry in a few seconds.',
			array('status' => 429)
		);
	}
	set_transient($cooldown_key, 1, 30);

	$monitor = SiteGuard_Backup_Health_Monitor::instance();
	$monitor->run_checks();

	return sgbackup_health_api_callback($request);
}

function sgbackup_health_api_auth(WP_REST_Request $request) {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
	$monitor = SiteGuard_Backup_Health_Monitor::instance();

	// Block if health monitor is disabled
	$opts = SiteGuard_Backup_Options::instance();
	if (!$opts->get_option('sgbackup_health_enabled', false)) {
		return new WP_Error('disabled', 'Health monitor is not enabled', array('status' => 404));
	}

	// Rate limit by IP - block brute force key guessing
	$ip = SiteGuard_Backup_Health_Monitor::get_client_ip();
	if ($monitor->is_rate_limited($ip)) {
		return new WP_Error('rate_limited', 'Too many failed attempts. Try again later.', array('status' => 429));
	}

	$auth = $request->get_header('Authorization');
	if (!$auth || strpos($auth, 'Bearer ') !== 0) {
		$monitor->record_failed_auth($ip);
		return new WP_Error('unauthorized', 'Missing or invalid Authorization header', array('status' => 401));
	}
	$token = substr($auth, 7);
	if (!$monitor->validate_api_key($token)) {
		$monitor->record_failed_auth($ip);
		return new WP_Error('forbidden', 'Invalid API key', array('status' => 403));
	}
	return true;
}

function sgbackup_health_api_callback(WP_REST_Request $request) {
	sgbackup_load_classes();
	require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
	$monitor = SiteGuard_Backup_Health_Monitor::instance();
	$results = $monitor->get_results();

	$opts = SiteGuard_Backup_Options::instance();
	$ssl_warn_days = intval($opts->get_option('sgbackup_health_ssl_warn_days', 14));

	// Build a pre-computed summary + actionable alerts list so external
	// consumers (Discord bot, Slack, email) don't each re-implement the
	// same reduction over the per-site array. Every site row is still
	// returned in full via the 'sites' key for bots that want raw data.
	$summary = array(
		'total'            => count($results),
		'operational'      => 0,
		'degraded'         => 0,
		'down'             => 0,
		'unknown'          => 0,
		'ssl_ok'           => 0,
		'ssl_failing'      => 0,
		'ssl_stale'        => 0,
		'ssl_expiring_soon' => 0,  // days <= ssl_warn_days (includes expired)
		'ssl_expired'      => 0,   // days <= 0 from a fresh probe
	);
	$alerts = array();
	$ssl_last_check = 0;

	foreach ($results as $site) {
		$status = isset($site['status']) ? $site['status'] : 'unknown';
		if (isset($summary[$status])) $summary[$status]++;

		$domain = isset($site['domain']) ? (string) $site['domain'] : (isset($site['url']) ? (string) $site['url'] : '?');

		// HTTP-level alerts
		if ($status === 'down') {
			$err = !empty($site['error']) ? $site['error'] : 'site unreachable';
			$consec = isset($site['consecutive_failures']) ? intval($site['consecutive_failures']) : 0;
			$alerts[] = array(
				'severity' => 'critical',
				'type'     => 'site_down',
				'domain'   => $domain,
				'message'  => $domain . ' is DOWN: ' . $err . ($consec > 1 ? ' (' . $consec . ' consecutive failures)' : ''),
				'site_id'  => isset($site['site_id']) ? intval($site['site_id']) : 0,
			);
		}

		// SSL-level counts and alerts
		$ssl_ok         = !empty($site['ssl_ok']);
		$ssl_stale      = !empty($site['ssl_stale']);
		$ssl_error      = isset($site['ssl_error']) ? (string) $site['ssl_error'] : '';
		$ssl_days       = isset($site['ssl_expiry_days']) ? intval($site['ssl_expiry_days']) : -1;
		$ssl_at         = isset($site['ssl_checked_at']) ? intval($site['ssl_checked_at']) : 0;
		$ssl_last_ok    = isset($site['ssl_last_success']) ? intval($site['ssl_last_success']) : 0;
		$scheme         = isset($site['url']) ? parse_url($site['url'], PHP_URL_SCHEME) : '';

		if ($ssl_at > $ssl_last_check) $ssl_last_check = $ssl_at;

		if ($scheme === 'https') {
			if ($ssl_ok) {
				$summary['ssl_ok']++;
			}
			if ($ssl_stale) {
				$summary['ssl_stale']++;
			}
			if ($ssl_error && !$ssl_ok) {
				$summary['ssl_failing']++;
				$alerts[] = array(
					'severity' => 'warning',
					'type'     => 'ssl_probe_failed',
					'domain'   => $domain,
					'message'  => $ssl_error . ($ssl_last_ok ? ' (last successful probe ' . gmdate('Y-m-d H:i \U\T\C', $ssl_last_ok) . ')' : ''),
					'site_id'  => isset($site['site_id']) ? intval($site['site_id']) : 0,
					'ssl_last_success' => $ssl_last_ok,
				);
			}
			// Fresh data: flag expiry conditions (don't double-alert on stale).
			if (!$ssl_stale && $ssl_days >= 0) {
				if ($ssl_days <= 0) {
					$summary['ssl_expired']++;
					$summary['ssl_expiring_soon']++;
					$alerts[] = array(
						'severity' => 'critical',
						'type'     => 'ssl_expired',
						'domain'   => $domain,
						'message'  => $domain . ' SSL certificate is EXPIRED (days=' . $ssl_days . ')',
						'site_id'  => isset($site['site_id']) ? intval($site['site_id']) : 0,
						'ssl_expiry_days' => $ssl_days,
					);
				} elseif ($ssl_days <= $ssl_warn_days) {
					$summary['ssl_expiring_soon']++;
					$alerts[] = array(
						'severity' => ($ssl_days <= 3 ? 'critical' : 'warning'),
						'type'     => 'ssl_expiring',
						'domain'   => $domain,
						'message'  => $domain . ' SSL certificate expires in ' . $ssl_days . ' day' . ($ssl_days === 1 ? '' : 's') . ' (issuer: ' . (isset($site['ssl_issuer']) ? $site['ssl_issuer'] : '?') . ')',
						'site_id'  => isset($site['site_id']) ? intval($site['site_id']) : 0,
						'ssl_expiry_days' => $ssl_days,
					);
				}
			}
		}
	}

	return rest_ensure_response(array(
		'status'               => $monitor->get_overall_status(),
		'site_url'             => get_site_url(),
		'checked_at'           => !empty($results) ? max(array_column($results, 'last_check')) : 0,
		'ssl_checked_at'       => $ssl_last_check,
		'check_interval'       => $monitor->get_interval_seconds(),
		'check_interval_label' => $opts->get_option('sgbackup_health_interval', 'every10min'),
		'ssl_warn_days'        => $ssl_warn_days,
		'summary'              => $summary,
		'alerts'               => $alerts,
		'sites'                => $results,
		'version'              => SGBACKUP_VERSION,
		'api_version'          => 2,
	));
}

