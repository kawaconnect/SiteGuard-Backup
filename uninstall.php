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
$wpdb->query($wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$wpdb->esc_like('sgbackup_') . '%'
));

if (is_multisite()) {
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like('sgbackup_') . '%'
	));
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
$wpdb->query($wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	'%' . $wpdb->esc_like('_transient_sgbackup_') . '%'
));

if (is_multisite()) {
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
		'%' . $wpdb->esc_like('_transient_sgbackup_') . '%'
	));
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like('_site_transient_sgbackup_') . '%'
	));
}
