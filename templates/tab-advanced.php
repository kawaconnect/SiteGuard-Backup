<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
?>

<div class="sgbackup-section">
	<h2><?php esc_html_e('System Information', 'siteguardbackup'); ?></h2>
	<button type="button" class="button" id="sgbackup-sysinfo-btn"><?php esc_html_e('Show System Info', 'siteguardbackup'); ?></button>
	<div id="sgbackup-sysinfo" style="display:none;margin-top:10px;">
		<textarea id="sgbackup-sysinfo-text" rows="15" cols="80" class="widefat" readonly style="font-family:monospace;font-size:12px;"></textarea>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Backup Directory', 'siteguardbackup'); ?></h2>
	<p><?php echo esc_html($opts->get_backup_dir()); ?></p>
	<?php
	$disk = SiteGuard_Backup_Filesystem::check_disk_space($opts->get_backup_dir());
	if ($disk): ?>
		<p>
			<?php printf(
				esc_html__('Free disk space: %s / %s', 'siteguardbackup'),
				SiteGuard_Backup_Filesystem::format_bytes($disk['free']),
				SiteGuard_Backup_Filesystem::format_bytes($disk['total'])
			); ?>
		</p>
	<?php endif; ?>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Site Size', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Calculate the size of your WordPress installation by component.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-site-size-btn"><?php esc_html_e('Calculate Site Size', 'siteguardbackup'); ?></button>
	<span id="sgbackup-site-size-status" class="sgbackup-status"></span>
	<div id="sgbackup-site-size-result" style="display:none;margin-top:10px;">
		<table class="widefat" id="sgbackup-site-size-table">
			<thead><tr><th><?php esc_html_e('Component', 'siteguardbackup'); ?></th><th><?php esc_html_e('Size', 'siteguardbackup'); ?></th></tr></thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Database Size', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('View the size of each database table.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-db-size-btn"><?php esc_html_e('Calculate Database Size', 'siteguardbackup'); ?></button>
	<span id="sgbackup-db-size-status" class="sgbackup-status"></span>
	<div id="sgbackup-db-size-result" style="display:none;margin-top:10px;">
		<table class="widefat" id="sgbackup-db-size-table">
			<thead><tr>
				<th><?php esc_html_e('Table', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Rows', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Data Size', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Index Size', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Total', 'siteguardbackup'); ?></th>
			</tr></thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Cron Events', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('View scheduled WordPress cron events.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-cron-events-btn"><?php esc_html_e('Show Cron Events', 'siteguardbackup'); ?></button>
	<label style="margin-left:10px;"><input type="checkbox" id="sgbackup-cron-filter-sgbackup" checked /> <?php esc_html_e('SiteGuard Backup events only', 'siteguardbackup'); ?></label>
	<div id="sgbackup-cron-events-result" style="display:none;margin-top:10px;">
		<table class="widefat" id="sgbackup-cron-events-table">
			<thead><tr>
				<th><?php esc_html_e('Hook', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Next Run', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Schedule', 'siteguardbackup'); ?></th>
			</tr></thead>
			<tbody></tbody>
		</table>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Database Search / Replace', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Perform a search-replace on the database (serialization-aware).', 'siteguardbackup'); ?></p>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Search for', 'siteguardbackup'); ?></th>
			<td><input type="text" id="sgbackup-sr-search" class="regular-text" /></td>
		</tr>
		<tr>
			<th><?php esc_html_e('Replace with', 'siteguardbackup'); ?></th>
			<td><input type="text" id="sgbackup-sr-replace" class="regular-text" /></td>
		</tr>
	</table>
	<button type="button" class="button" id="sgbackup-searchreplace-btn"><?php esc_html_e('Search & Replace', 'siteguardbackup'); ?></button>
	<span id="sgbackup-sr-status" class="sgbackup-status"></span>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Export / Import Settings', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Export your plugin settings as a JSON file, or import settings from a previously exported file.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-export-settings-btn"><?php esc_html_e('Export Settings', 'siteguardbackup'); ?></button>
	<span id="sgbackup-export-status" class="sgbackup-status"></span>
	<br /><br />
	<label><?php esc_html_e('Import Settings:', 'siteguardbackup'); ?></label>
	<input type="file" id="sgbackup-import-settings-file" accept=".json" />
	<button type="button" class="button" id="sgbackup-import-settings-btn"><?php esc_html_e('Import Settings', 'siteguardbackup'); ?></button>
	<span id="sgbackup-import-status" class="sgbackup-status"></span>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Active Backup Jobs', 'siteguardbackup'); ?></h2>
	<button type="button" class="button" id="sgbackup-refresh-jobs-btn"><?php esc_html_e('Refresh', 'siteguardbackup'); ?></button>
	<div id="sgbackup-jobs-list" style="margin-top:10px;"></div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Wipe Settings', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Reset all SiteGuard Backup settings to defaults. This will not delete backup files.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-wipe-settings-btn"><?php esc_html_e('Wipe Settings', 'siteguardbackup'); ?></button>
</div>
