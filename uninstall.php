<?php
/**
 * SiteGuard Backup - Uninstall
 *
 * Clean up plugin data when the plugin is deleted.
 * Runs only when the plugin is uninstalled via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) die('No direct access.');

global $wpdb;

// Remove sgbackup_* options from main site's wp_options
$wpdb->query($wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
	$wpdb->esc_like('sgbackup_') . '%'
));

// Remove transients from main site's wp_options
$wpdb->query($wpdb->prepare(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
	'%' . $wpdb->esc_like('_transient_sgbackup_') . '%',
	'%' . $wpdb->esc_like('_transient_timeout_sgbackup_') . '%'
));

// Clear scheduled events for the current site
// (Mirror every sgbackup_* hook that sgbackup_deactivate() clears in siteguardbackup.php.)
wp_clear_scheduled_hook('sgbackup_backup');
wp_clear_scheduled_hook('sgbackup_backup_database');
wp_clear_scheduled_hook('sgbackup_backup_all');
wp_clear_scheduled_hook('sgbackup_backup_incremental');
wp_clear_scheduled_hook('sgbackup_backup_remotesend');
wp_clear_scheduled_hook('sgbackup_health_check');
wp_clear_scheduled_hook('sgbackup_backup_resume');

// NOTE: the on-disk backup files under wp-content/sgbackup are intentionally RETAINED on
// uninstall so backups aren't destroyed by removing the plugin. Users delete them manually.

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

if (is_multisite()) {
	// Clean up network-wide options from wp_sitemeta
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like('sgbackup_') . '%'
	));
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		'%' . $wpdb->esc_like('_transient_sgbackup_') . '%',
		'%' . $wpdb->esc_like('_transient_timeout_sgbackup_') . '%'
	));
	$wpdb->query($wpdb->prepare(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
		$wpdb->esc_like('_site_transient_sgbackup_') . '%',
		$wpdb->esc_like('_site_transient_timeout_sgbackup_') . '%'
	));

	// Clean up per-site options, transients, and cron from each subsite
	$sites = get_sites(array('fields' => 'ids', 'number' => 0));
	foreach ($sites as $site_id) {
		if ((int) $site_id === (int) get_main_site_id()) {
			continue; // Main site already cleaned above
		}

		switch_to_blog($site_id);

		// Per-site options
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like('sgbackup_') . '%'
		));

		// Per-site transients
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			'%' . $wpdb->esc_like('_transient_sgbackup_') . '%',
			'%' . $wpdb->esc_like('_transient_timeout_sgbackup_') . '%'
		));

		// Per-site cron hooks
		wp_clear_scheduled_hook('sgbackup_backup');
		wp_clear_scheduled_hook('sgbackup_backup_database');
		wp_clear_scheduled_hook('sgbackup_backup_all');
		wp_clear_scheduled_hook('sgbackup_backup_incremental');
		wp_clear_scheduled_hook('sgbackup_backup_remotesend');
		wp_clear_scheduled_hook('sgbackup_health_check');
		wp_clear_scheduled_hook('sgbackup_backup_resume');

		$site_crons = _get_cron_array();
		if (is_array($site_crons)) {
			foreach ($site_crons as $timestamp => $cron_hooks) {
				if (isset($cron_hooks['sgbackup_backup_resume'])) {
					foreach ($cron_hooks['sgbackup_backup_resume'] as $hook_key => $event) {
						wp_unschedule_event($timestamp, 'sgbackup_backup_resume', $event['args']);
					}
				}
			}
		}

		restore_current_blog();
	}
}
