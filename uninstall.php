<?php
/**
 * SiteGuard Backup - Uninstall
 *
 * Clean up plugin data when the plugin is deleted.
 * Runs only when the plugin is uninstalled via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) die('No direct access.');

// Remove all sgbackup_* options
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sgbackup_%'");

if (is_multisite()) {
	$wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'sgbackup_%'");
}

// Clear scheduled events (including parameterized resume events)
wp_clear_scheduled_hook('sgbackup_backup');
wp_clear_scheduled_hook('sgbackup_backup_database');
wp_clear_scheduled_hook('sgbackup_backup_all');
wp_clear_scheduled_hook('sgbackup_backup_incremental');
wp_clear_scheduled_hook('sgbackup_backup_resume');

// Clean up any remaining resume events with args from the cron array
$crons = _get_cron_array();
if (is_array($crons)) {
	foreach ($crons as $timestamp => $cron_hooks) {
		if (isset($cron_hooks['sgbackup_backup_resume'])) {
			foreach ($cron_hooks['sgbackup_backup_resume'] as $hook_key => $event) {
				wp_unschedule_event($timestamp, 'sgbackup_backup_resume', $event['args']);
			}
		}
	}
}

// Remove transients
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%_transient_sgbackup_%'");

if (is_multisite()) {
	$wpdb->query("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '%_transient_sgbackup_%'");
}
