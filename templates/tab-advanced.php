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
	<h2><?php esc_html_e('WP-CLI Commands Reference', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('SiteGuard Backup includes WP-CLI commands for automation, scripting, and server management. All commands are optional and only run when you explicitly invoke them.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-cli-ref-toggle"><?php esc_html_e('Show Command Reference', 'siteguardbackup'); ?></button>
	<div id="sgbackup-cli-reference" style="display:none;margin-top:15px;">

		<h3 style="margin-top:0;"><?php esc_html_e('Core Backup Operations', 'siteguardbackup'); ?></h3>
		<table class="widefat" style="margin-bottom:20px;">
			<thead><tr><th style="width:250px;"><?php esc_html_e('Command', 'siteguardbackup'); ?></th><th><?php esc_html_e('Description', 'siteguardbackup'); ?></th></tr></thead>
			<tbody>
				<tr><td><code>wp siteguardbackup backup</code></td><td><?php esc_html_e('Run a full or partial backup. Use --exclude-db for files only, --include-files=plugins,themes to select entities, --send-to-cloud to upload to configured storage, --incremental for changed-files-only backup.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup list</code></td><td><?php esc_html_e('List all backup sets showing date, size, entities included, storage location, and retention status. Supports --format=json for scripting.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup restore &lt;ts&gt;</code></td><td><?php esc_html_e('Restore a backup by its timestamp. Use --entities=db,plugins to restore selectively, or --tables=wp_posts to restore specific tables only.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup status</code></td><td><?php esc_html_e('Show active backup jobs, cron schedules (files/DB/incremental), and total backup inventory.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup verify &lt;ts&gt;</code></td><td><?php esc_html_e('Verify backup file integrity by checking file sizes and MD5 checksums against the backup manifest.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup backup_progress</code></td><td><?php esc_html_e('Show detailed progress of a running backup job: current entity, DB tables completed, phase percentage, elapsed time.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup get_latest_full_backup</code></td><td><?php esc_html_e('Show details of the most recent backup including per-entity sizes. Supports --format=json.', 'siteguardbackup'); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e('Azure-to-Docker Failover Integration', 'siteguardbackup'); ?></h3>
		<p class="description"><?php esc_html_e('These commands are designed for use with the Cloudflare failover system (cf-failover.sh, cf-rollback.sh). They allow scripted backup, import, and post-switch maintenance between Azure production and Docker standby servers.', 'siteguardbackup'); ?></p>
		<table class="widefat" style="margin-bottom:20px;">
			<thead><tr><th style="width:250px;"><?php esc_html_e('Command', 'siteguardbackup'); ?></th><th><?php esc_html_e('Description', 'siteguardbackup'); ?></th></tr></thead>
			<tbody>
				<tr><td><code>wp siteguardbackup export</code></td><td><?php esc_html_e('Create a full backup and output file paths (one per line) for scripting. Use --db-only for a fast database-only export, --output-dir to copy files to a custom location, --format=json for structured output.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup import &lt;dir&gt;</code></td><td><?php esc_html_e('Import backup files from a local directory or file. Automatically detects and registers backup sets. Use --restore to immediately restore after import, --entities=db to restore only the database, --delete-source to remove source files after import.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup prefailover</code></td><td><?php esc_html_e('Create a quick synchronous database-only backup before failover. Returns the backup file path. Use --quiet to output only the path (for scripting: BACKUP=$(wp siteguardbackup prefailover --quiet)).', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup postfailover</code></td><td><?php esc_html_e('Run after DNS failover: flushes all caches (object cache, transients, Redis, rewrite rules), verifies database connectivity, checks theme and plugins exist, reports multisite status. Use --search-replace="old|new" to update URLs, --flush-only to skip checks.', 'siteguardbackup'); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e('Maintenance & Monitoring', 'siteguardbackup'); ?></h3>
		<p class="description"><?php esc_html_e('Tools for keeping the backup system healthy. None of these run automatically - invoke them manually or via a scheduled script when needed.', 'siteguardbackup'); ?></p>
		<table class="widefat" style="margin-bottom:20px;">
			<thead><tr><th style="width:250px;"><?php esc_html_e('Command', 'siteguardbackup'); ?></th><th><?php esc_html_e('Description', 'siteguardbackup'); ?></th></tr></thead>
			<tbody>
				<tr><td><code>wp siteguardbackup healthcheck</code></td><td><?php esc_html_e('Run a health check: database connectivity, disk space, backup freshness, stuck jobs, lock status, PHP environment, multisite status. Returns exit code 0 if healthy, 1 if issues. Use --format=json for scripting, --max-age=24 to set freshness threshold in hours.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup cleanup</code></td><td><?php esc_html_e('Remove stale job data (>24h old), old log files (>30 days), orphan temp directories, and expired locks. Use --dry-run to preview, --max-log-age=7 to change log retention.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup schedule</code></td><td><?php esc_html_e('View current backup schedules, or set them: schedule set --files=daily --database=every4hours --retain=5 --retain-db=10. Valid intervals: manual, everyhour, every2hours, every4hours, every8hours, twicedaily, daily, weekly, fortnightly, monthly.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup prune</code></td><td><?php esc_html_e('Manually enforce the backup retention policy (oldest backups beyond the retention count are deleted). Use --type=full|db|all to target specific types, --dry-run to preview.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup lock</code></td><td><?php esc_html_e('View or manage backup semaphore locks. "lock status" shows if a lock is held and for how long. "lock clear" force-releases all locks (use if a backup got stuck).', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup sites</code></td><td><?php esc_html_e('List all sites in the multisite network with ID, domain, path, name, status (active/archived/spam), last updated date, and published post count.', 'siteguardbackup'); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e('Migration & Recovery', 'siteguardbackup'); ?></h3>
		<table class="widefat" style="margin-bottom:20px;">
			<thead><tr><th style="width:250px;"><?php esc_html_e('Command', 'siteguardbackup'); ?></th><th><?php esc_html_e('Description', 'siteguardbackup'); ?></th></tr></thead>
			<tbody>
				<tr><td><code>wp siteguardbackup search_replace</code></td><td><?php esc_html_e('Serialization-aware database search-replace. Safely handles nested serialized PHP data and JSON. Dry-run by default - use --no-dry-run to apply changes. Example: search_replace "http://old.com" "https://new.com"', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup create_migration_key</code></td><td><?php esc_html_e('Generate an RSA key bundle for site-to-site migration. The key is used to authenticate backup transfers between servers.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup rescan_storage</code></td><td><?php esc_html_e('Discover backup files not tracked in history (e.g., from manual copies or interrupted jobs). Use "rescan_storage local" to scan the backup directory, or "rescan_storage remote" to scan configured cloud storage.', 'siteguardbackup'); ?></td></tr>
				<tr><td><code>wp siteguardbackup get_most_recently_modified_log</code></td><td><?php esc_html_e('Find the most recent backup log file. Use --display to output its contents directly in the terminal.', 'siteguardbackup'); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Wipe Settings', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Reset all SiteGuard Backup settings to defaults. This will not delete backup files.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-wipe-settings-btn"><?php esc_html_e('Wipe Settings', 'siteguardbackup'); ?></button>
</div>
