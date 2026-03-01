<?php
/**
 * Plugin Name: SiteGuard Backup
 * Description: Complete WordPress backup, restore, and migration solution with scheduled, incremental, and site-to-site migration support.
 * Version: 1.0.0
 * Author: SiteGuard
 * License: GPL-2.0-or-later
 * Network: true
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: siteguardbackup
 */

if (!defined('ABSPATH')) die('No direct access.');

// Core constants
define('SGBACKUP_VERSION', '1.0.1');
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
	$hooks = array('sgbackup_backup', 'sgbackup_backup_database', 'sgbackup_backup_incremental');

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

/**
 * Register cron action hooks (lightweight, always loaded).
 */
function sgbackup_register_cron_hooks() {
	add_action('sgbackup_backup', 'sgbackup_cron_backup_files');
	add_action('sgbackup_backup_database', 'sgbackup_cron_backup_database');
	add_action('sgbackup_backup_all', 'sgbackup_cron_backup_all');
	add_action('sgbackup_backup_resume', 'sgbackup_cron_backup_resume', 10, 2);
	add_action('sgbackup_backup_incremental', 'sgbackup_cron_backup_incremental');
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
 * Auto-backup before WordPress core, plugin, and theme updates.
 */
function sgbackup_autobackup_init() {
	if (!is_admin()) return;

	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	if (!$opts->get_option('sgbackup_autobackup_default', true)) return;

	add_action('admin_action_update-selected', 'sgbackup_autobackup_plugins', 5);
	add_action('admin_action_do-plugin-upgrade', 'sgbackup_autobackup_plugins', 5);
	add_action('admin_action_upgrade-plugin', 'sgbackup_autobackup_plugins', 5);
	add_action('admin_action_do-theme-upgrade', 'sgbackup_autobackup_themes', 5);
	add_action('admin_action_upgrade-theme', 'sgbackup_autobackup_themes', 5);
	add_action('admin_action_do-core-upgrade', 'sgbackup_autobackup_core', 5);
	add_action('admin_action_do-core-reinstall', 'sgbackup_autobackup_core', 5);

	add_filter('upgrader_pre_install', 'sgbackup_upgrader_pre_install', 10, 2);
}
add_action('admin_init', 'sgbackup_autobackup_init');

/** @var array Prevents duplicate auto-backups in one request */
$sgbackup_already_backed_up = array();

function sgbackup_autobackup_plugins() {
	global $sgbackup_already_backed_up;
	if (isset($sgbackup_already_backed_up['plugins'])) return;
	$sgbackup_already_backed_up['plugins'] = true;

	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_all(array(
		'entities'   => array('plugins'),
		'autobackup' => true,
		'nodb'       => false,
	));
}

function sgbackup_autobackup_themes() {
	global $sgbackup_already_backed_up;
	if (isset($sgbackup_already_backed_up['themes'])) return;
	$sgbackup_already_backed_up['themes'] = true;

	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_all(array(
		'entities'   => array('themes'),
		'autobackup' => true,
		'nodb'       => false,
	));
}

function sgbackup_autobackup_core() {
	global $sgbackup_already_backed_up;
	if (isset($sgbackup_already_backed_up['core'])) return;
	$sgbackup_already_backed_up['core'] = true;
	$sgbackup_already_backed_up['plugins'] = true;
	$sgbackup_already_backed_up['themes'] = true;

	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	SiteGuard_Backup::instance()->backup_all(array(
		'entities'   => array('plugins', 'themes'),
		'autobackup' => true,
	));
}

function sgbackup_upgrader_pre_install($return, $hook_extra) {
	global $sgbackup_already_backed_up;

	sgbackup_load_classes();
	$opts = SiteGuard_Backup_Options::instance();
	if (!$opts->get_option('sgbackup_autobackup_default', true)) return $return;

	// Validate type if present
	if (!empty($hook_extra['type']) && !in_array($hook_extra['type'], array('plugin', 'theme', 'core'), true)) {
		return $return;
	}

	$entity = 'plugins';
	if (!empty($hook_extra['type'])) {
		if ($hook_extra['type'] === 'theme') $entity = 'themes';
		elseif ($hook_extra['type'] === 'core') $entity = 'plugins'; // backup plugins+themes for core
	} elseif (!empty($hook_extra['theme'])) {
		$entity = 'themes';
	}

	if (isset($sgbackup_already_backed_up[$entity])) return $return;
	$sgbackup_already_backed_up[$entity] = true;

	require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	$entities = ($entity === 'plugins' && !empty($hook_extra['type']) && $hook_extra['type'] === 'core')
		? array('plugins', 'themes')
		: array($entity);

	SiteGuard_Backup::instance()->backup_all(array(
		'entities'   => $entities,
		'autobackup' => true,
		'nodb'       => false,
	));

	return $return;
}
