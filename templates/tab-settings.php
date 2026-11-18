<?php if (!defined('ABSPATH')) die('No direct access.');
require_once SGBACKUP_DIR . 'includes/class-security.php';
$opts = SiteGuard_Backup_Options::instance();
$settings = $opts->get_all_settings();
$schedules = array(
	'manual'      => __('Manual', 'siteguardbackup'),
	'everyhour'   => __('Every hour', 'siteguardbackup'),
	'every2hours' => __('Every 2 hours', 'siteguardbackup'),
	'every4hours' => __('Every 4 hours', 'siteguardbackup'),
	'every8hours' => __('Every 8 hours', 'siteguardbackup'),
	'twicedaily'  => __('Twice daily', 'siteguardbackup'),
	'daily'       => __('Daily', 'siteguardbackup'),
	'weekly'      => __('Weekly', 'siteguardbackup'),
	'fortnightly' => __('Fortnightly', 'siteguardbackup'),
	'monthly'     => __('Monthly', 'siteguardbackup'),
);
$inc_schedules = array('none' => __('None', 'siteguardbackup')) + $schedules;
unset($inc_schedules['manual']);

$days_of_week = array(
	'0' => __('Sunday', 'siteguardbackup'),
	'1' => __('Monday', 'siteguardbackup'),
	'2' => __('Tuesday', 'siteguardbackup'),
	'3' => __('Wednesday', 'siteguardbackup'),
	'4' => __('Thursday', 'siteguardbackup'),
	'5' => __('Friday', 'siteguardbackup'),
	'6' => __('Saturday', 'siteguardbackup'),
);

$entities_list = array(
	'plugins' => __('Plugins', 'siteguardbackup'),
	'themes'  => __('Themes', 'siteguardbackup'),
	'uploads' => __('Uploads', 'siteguardbackup'),
	'others'  => __('Others (wp-content minus plugins/themes/uploads)', 'siteguardbackup'),
	'wpcore'  => __('WordPress Core', 'siteguardbackup'),
);

$sgbackup = SiteGuard_Backup::instance();
// Only show methods that have a configuration panel in this template
$configurable_methods = array('s3', 's3generic', 'b2', 'googledrive', 'dropbox', 'onedrive', 'ftp', 'sftp', 'webdav', 'azure', 'gcs', 'pcloud', 'openstack', 'email');

if (!function_exists('sgbackup_oauth_rows')) {
	/**
	 * Sign-in rows for an OAuth storage instance: the redirect URI to register
	 * with the provider, the connection state, and the buttons.
	 */
	function sgbackup_oauth_rows($method, $iid, $iopts, $provider_label, $console_hint) {
		require_once SGBACKUP_DIR . 'includes/class-oauth.php';
		$redirect = SiteGuard_Backup_OAuth::redirect_uri();
		?>
		<tr>
			<th><?php esc_html_e('Redirect URI', 'siteguardbackup'); ?></th>
			<td>
				<code class="sgbackup-redirect-uri" style="user-select:all"><?php echo esc_html($redirect); ?></code>
				<p class="description"><?php echo esc_html(sprintf(__('Register exactly this URI in your %s app (%s), save the credentials above, then sign in.', 'siteguardbackup'), $provider_label, $console_hint)); ?></p>
				<?php if (SiteGuard_Backup_OAuth::redirect_uri_has_query()): ?>
					<p class="description" style="color:#b32d2e"><?php esc_html_e('This site uses plain permalinks, so the redirect URI contains a query string. Microsoft rejects that for personal accounts; switch Settings > Permalinks to any other structure.', 'siteguardbackup'); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<td colspan="2">
				<?php if (!empty($iopts['token']['access_token']) || ('pcloud' === $method && !empty($iopts['access_token']))): ?>
					<span class="sgbackup-auth-status sgbackup-status success"><?php esc_html_e('Signed in', 'siteguardbackup'); ?></span>
					<button type="button" class="button sgbackup-auth-btn" data-method="<?php echo esc_attr($method); ?>" data-instance="<?php echo esc_attr($iid); ?>"><?php esc_html_e('Sign in again', 'siteguardbackup'); ?></button>
				<?php else: ?>
					<button type="button" class="button button-primary sgbackup-auth-btn" data-method="<?php echo esc_attr($method); ?>" data-instance="<?php echo esc_attr($iid); ?>"><?php echo esc_html(sprintf(__('Sign in with %s', 'siteguardbackup'), $provider_label)); ?></button>
				<?php endif; ?>
				<button type="button" class="button sgbackup-test-credentials" data-method="<?php echo esc_attr($method); ?>" data-instance="<?php echo esc_attr($iid); ?>"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
				<span class="sgbackup-test-result"></span>
			</td>
		</tr>
		<?php
	}
}
$methods = array_intersect_key($sgbackup->get_backup_methods(), array_flip($configurable_methods));
$current_services = $settings['service'];
if (!is_array($current_services)) $current_services = array($current_services);

$retain_extrarules = isset($settings['retain_extrarules']) ? $settings['retain_extrarules'] : array();
if (!is_array($retain_extrarules)) $retain_extrarules = array();

$period_labels = array(
	'3600'    => __('hours', 'siteguardbackup'),
	'86400'   => __('days', 'siteguardbackup'),
	'604800'  => __('weeks', 'siteguardbackup'),
	'2592000' => __('months', 'siteguardbackup'),
);
?>

<form id="sgbackup-settings-form" class="sgbackup-settings">
	<h2><?php esc_html_e('Schedule', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('File Backup Schedule', 'siteguardbackup'); ?></th>
			<td>
				<select name="interval" class="sgbackup-interval-select" data-day-target="schedule_day">
					<?php foreach ($schedules as $val => $label): ?>
						<option value="<?php echo esc_attr($val); ?>" <?php selected($settings['interval'], $val); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="schedule_day_dow" class="sgbackup-day-of-week-select" data-target="schedule_day" style="<?php echo in_array($settings['interval'], array('weekly', 'fortnightly'), true) ? '' : 'display:none;'; ?>">
					<option value=""><?php esc_html_e('Any day', 'siteguardbackup'); ?></option>
					<?php foreach ($days_of_week as $dval => $dlabel): ?>
						<option value="<?php echo esc_attr($dval); ?>" <?php selected($settings['schedule_day'] ?? '', $dval); ?>><?php echo esc_html($dlabel); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="schedule_day_dom" class="sgbackup-day-of-month-select" data-target="schedule_day" style="<?php echo ($settings['interval'] === 'monthly') ? '' : 'display:none;'; ?>">
					<option value=""><?php esc_html_e('Any day', 'siteguardbackup'); ?></option>
					<?php for ($d = 1; $d <= 28; $d++): ?>
						<option value="<?php echo esc_attr($d); ?>" <?php selected($settings['schedule_day'] ?? '', (string) $d); ?>><?php echo esc_html($d); ?></option>
					<?php endfor; ?>
				</select>
				<label><?php esc_html_e('Retain:', 'siteguardbackup'); ?>
					<input type="number" name="retain" value="<?php echo esc_attr($settings['retain']); ?>" min="1" max="100" style="width:60px;" />
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Database Backup Schedule', 'siteguardbackup'); ?></th>
			<td>
				<select name="interval_database" class="sgbackup-interval-select" data-day-target="schedule_day_db">
					<?php foreach ($schedules as $val => $label): ?>
						<option value="<?php echo esc_attr($val); ?>" <?php selected($settings['interval_database'], $val); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="schedule_day_db_dow" class="sgbackup-day-of-week-select" data-target="schedule_day_db" style="<?php echo in_array($settings['interval_database'], array('weekly', 'fortnightly'), true) ? '' : 'display:none;'; ?>">
					<option value=""><?php esc_html_e('Any day', 'siteguardbackup'); ?></option>
					<?php foreach ($days_of_week as $dval => $dlabel): ?>
						<option value="<?php echo esc_attr($dval); ?>" <?php selected($settings['schedule_day_db'] ?? '', $dval); ?>><?php echo esc_html($dlabel); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="schedule_day_db_dom" class="sgbackup-day-of-month-select" data-target="schedule_day_db" style="<?php echo ($settings['interval_database'] === 'monthly') ? '' : 'display:none;'; ?>">
					<option value=""><?php esc_html_e('Any day', 'siteguardbackup'); ?></option>
					<?php for ($d = 1; $d <= 28; $d++): ?>
						<option value="<?php echo esc_attr($d); ?>" <?php selected($settings['schedule_day_db'] ?? '', (string) $d); ?>><?php echo esc_html($d); ?></option>
					<?php endfor; ?>
				</select>
				<label><?php esc_html_e('Retain:', 'siteguardbackup'); ?>
					<input type="number" name="retain_db" value="<?php echo esc_attr($settings['retain_db']); ?>" min="1" max="100" style="width:60px;" />
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Incremental Schedule', 'siteguardbackup'); ?></th>
			<td>
				<select name="interval_incremental">
					<?php foreach ($inc_schedules as $val => $label): ?>
						<option value="<?php echo esc_attr($val); ?>" <?php selected($settings['interval_incremental'], $val); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e('Incremental backups only include files changed since the last full backup. No database.', 'siteguardbackup'); ?></p>
				<label style="display:block;margin-top:10px;">
					<?php esc_html_e('Force full backup after', 'siteguardbackup'); ?>
					<input type="number" name="max_incrementals_before_full" value="<?php echo esc_attr($settings['max_incrementals_before_full']); ?>" min="0" max="999" style="width:70px;" />
					<?php esc_html_e('incremental backups', 'siteguardbackup'); ?>
				</label>
				<p class="description"><?php esc_html_e('Set to 0 to disable. When the incremental chain reaches this length, the next scheduled incremental is promoted to a full backup.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Preferred Time', 'siteguardbackup'); ?></th>
			<td>
				<input type="time" name="schedule_time" value="<?php echo esc_attr($settings['schedule_time'] ?? ''); ?>" />
				<p class="description"><?php esc_html_e('Time of day for scheduled backups (leave empty for random 1-5 AM)', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<!-- H3: Tiered Retention Rules -->
	<h2><?php esc_html_e('Retention Rules', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('Define extra rules to thin out older backups beyond the basic retention count.', 'siteguardbackup'); ?></p>

	<h4><?php esc_html_e('File Backup Retention Rules', 'siteguardbackup'); ?></h4>
	<div id="sgbackup-retention-rules-files" class="sgbackup-retention-rules" data-type="files">
		<?php
		$file_rules = isset($retain_extrarules['files']) ? $retain_extrarules['files'] : array();
		if (!empty($file_rules)):
			foreach ($file_rules as $ri => $rule): ?>
				<div class="sgbackup-retention-rule" data-index="<?php echo esc_attr($ri); ?>">
					<?php esc_html_e('For backups after the first', 'siteguardbackup'); ?>
					<input type="number" class="sgbackup-rr-after-count" value="<?php echo esc_attr($rule['after_count']); ?>" min="1" max="999" style="width:60px;" />
					<?php esc_html_e('kept, older than', 'siteguardbackup'); ?>
					<select class="sgbackup-rr-after-period">
						<?php foreach ($period_labels as $pval => $plabel): ?>
							<option value="<?php echo esc_attr($pval); ?>" <?php selected($rule['after_period'], (int) $pval); ?>><?php echo esc_html($plabel); ?></option>
						<?php endforeach; ?>
					</select>
					<?php esc_html_e(', keep no more than 1 every', 'siteguardbackup'); ?>
					<select class="sgbackup-rr-keep-every">
						<?php foreach ($period_labels as $pval => $plabel): ?>
							<option value="<?php echo esc_attr($pval); ?>" <?php selected($rule['keep_every'], (int) $pval); ?>><?php echo esc_html($plabel); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button sgbackup-delete-retention-rule"><?php esc_html_e('Delete', 'siteguardbackup'); ?></button>
				</div>
			<?php endforeach;
		endif; ?>
	</div>
	<a href="#" class="sgbackup-add-retention-rule" data-type="files"><?php esc_html_e('+ Add file retention rule', 'siteguardbackup'); ?></a>

	<h4 style="margin-top:16px;"><?php esc_html_e('Database Backup Retention Rules', 'siteguardbackup'); ?></h4>
	<div id="sgbackup-retention-rules-db" class="sgbackup-retention-rules" data-type="db">
		<?php
		$db_rules = isset($retain_extrarules['db']) ? $retain_extrarules['db'] : array();
		if (!empty($db_rules)):
			foreach ($db_rules as $ri => $rule): ?>
				<div class="sgbackup-retention-rule" data-index="<?php echo esc_attr($ri); ?>">
					<?php esc_html_e('For backups after the first', 'siteguardbackup'); ?>
					<input type="number" class="sgbackup-rr-after-count" value="<?php echo esc_attr($rule['after_count']); ?>" min="1" max="999" style="width:60px;" />
					<?php esc_html_e('kept, older than', 'siteguardbackup'); ?>
					<select class="sgbackup-rr-after-period">
						<?php foreach ($period_labels as $pval => $plabel): ?>
							<option value="<?php echo esc_attr($pval); ?>" <?php selected($rule['after_period'], (int) $pval); ?>><?php echo esc_html($plabel); ?></option>
						<?php endforeach; ?>
					</select>
					<?php esc_html_e(', keep no more than 1 every', 'siteguardbackup'); ?>
					<select class="sgbackup-rr-keep-every">
						<?php foreach ($period_labels as $pval => $plabel): ?>
							<option value="<?php echo esc_attr($pval); ?>" <?php selected($rule['keep_every'], (int) $pval); ?>><?php echo esc_html($plabel); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="button sgbackup-delete-retention-rule"><?php esc_html_e('Delete', 'siteguardbackup'); ?></button>
				</div>
			<?php endforeach;
		endif; ?>
	</div>
	<a href="#" class="sgbackup-add-retention-rule" data-type="db"><?php esc_html_e('+ Add database retention rule', 'siteguardbackup'); ?></a>

	<h2><?php esc_html_e('Include in File Backups', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Entities', 'siteguardbackup'); ?></th>
			<td>
				<?php foreach ($entities_list as $key => $label): ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox" name="include_entities[]" value="<?php echo esc_attr($key); ?>"
							<?php checked(in_array($key, $settings['include_entities'])); ?> />
						<?php echo esc_html($label); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Exclude Files', 'siteguardbackup'); ?></th>
			<td>
				<textarea name="exclude_files" rows="3" cols="50" class="regular-text"><?php
					echo esc_textarea(implode("\n", (array) $settings['exclude_files']));
				?></textarea>
				<p class="description"><?php esc_html_e('One pattern per line (e.g., *.log, cache/)', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Extra Directories', 'siteguardbackup'); ?></th>
			<td>
				<?php
				$extra_dirs = isset($settings['extra_dirs']) ? (array) $settings['extra_dirs'] : array();
				?>
				<div id="sgbackup-extra-dirs">
					<?php if (!empty($extra_dirs)): ?>
						<?php foreach ($extra_dirs as $idx => $dir): ?>
							<div class="sgbackup-extra-dir-row" style="margin-bottom:4px;">
								<input type="text" class="regular-text sgbackup-extra-dir-input" value="<?php echo esc_attr($dir); ?>" placeholder="/absolute/path/to/directory" />
								<button type="button" class="button sgbackup-remove-extra-dir"><?php esc_html_e('Remove', 'siteguardbackup'); ?></button>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<button type="button" class="button" id="sgbackup-add-extra-dir"><?php esc_html_e('+ Add Directory', 'siteguardbackup'); ?></button>
				<p class="description"><?php esc_html_e('Add absolute paths to directories outside wp-content to include in file backups. Paths must exist and be readable.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Split Zip At', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" name="split_every" value="<?php echo esc_attr($settings['split_every']); ?>" min="25" max="5000" style="width:80px;" /> MB
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Low Memory Mode', 'siteguardbackup'); ?></th>
			<td>
				<?php
				$low_mem_setting = isset($settings['low_memory_mode']) ? $settings['low_memory_mode'] : 'auto';
				$low_mem_active = SiteGuard_Backup::instance()->is_low_memory();
				$low_mem_limit = SiteGuard_Backup::memory_limit_bytes();
				$low_mem_sys = SiteGuard_Backup::system_memory_bytes();
				?>
				<select name="low_memory_mode">
					<option value="auto" <?php selected($low_mem_setting, 'auto'); ?>><?php esc_html_e('Auto-detect (recommended)', 'siteguardbackup'); ?></option>
					<option value="on" <?php selected($low_mem_setting, 'on'); ?>><?php esc_html_e('Always on', 'siteguardbackup'); ?></option>
					<option value="off" <?php selected($low_mem_setting, 'off'); ?>><?php esc_html_e('Off', 'siteguardbackup'); ?></option>
				</select>
				<span style="margin-left:8px;">
					<?php if ($low_mem_active): ?>
						<strong style="color:#996800;"><?php esc_html_e('Currently active', 'siteguardbackup'); ?></strong>
					<?php else: ?>
						<span style="color:#666;"><?php esc_html_e('Currently inactive', 'siteguardbackup'); ?></span>
					<?php endif; ?>
					-
					<?php
					printf(
						/* translators: 1: PHP memory_limit, 2: host RAM */
						esc_html__('PHP memory_limit %1$s, host RAM %2$s', 'siteguardbackup'),
						esc_html($low_mem_limit > 0 ? size_format($low_mem_limit) : __('unlimited', 'siteguardbackup')),
						esc_html($low_mem_sys > 0 ? size_format($low_mem_sys) : __('unknown', 'siteguardbackup'))
					);
					?>
				</span>
				<p class="description"><?php esc_html_e('For small servers (512 MB RAM or less, or a PHP memory_limit under 128 MB). Uses 10 MB zip batches per run, fetches database rows in small byte-bounded batches, and sends 1 MB chunks to remote sites. Auto-detect turns this on when either threshold is met. Backups take more resumptions but stay well under the memory limit.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<!-- M4: Non-WordPress Tables -->
	<h2><?php esc_html_e('Database', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Non-WordPress Tables', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="backupdb_nonwp" value="1" <?php checked(!empty($settings['backupdb_nonwp'])); ?> />
					<?php esc_html_e('Also backup non-WordPress tables (tables without the configured prefix)', 'siteguardbackup'); ?>
				</label>
				<p class="description"><?php esc_html_e('Include all tables in the database, not just those beginning with the WordPress table prefix.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Anonymise Database', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="db_anonymise" value="1" <?php checked(!empty($settings['db_anonymise'])); ?> />
					<?php esc_html_e('Anonymise personal data in scheduled database backups', 'siteguardbackup'); ?>
				</label>
				<p class="description"><?php esc_html_e('Replaces names, emails, addresses and other PII with anonymous placeholders. Useful for sharing backups with developers.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Default Database Restore Mode', 'siteguardbackup'); ?></th>
			<td>
				<?php $default_db_mode = isset($settings['default_db_restore_mode']) ? intval($settings['default_db_restore_mode']) : 0; ?>
				<select name="default_db_restore_mode">
					<option value="0" <?php selected($default_db_mode, 0); ?>><?php esc_html_e('Migrate - Full overwrite + auto search-replace (default)', 'siteguardbackup'); ?></option>
					<option value="1" <?php selected($default_db_mode, 1); ?>><?php esc_html_e('Overwrite - Full overwrite, no search-replace', 'siteguardbackup'); ?></option>
					<option value="2" <?php selected($default_db_mode, 2); ?>><?php esc_html_e('Tables only - Import only tables not already in DB', 'siteguardbackup'); ?></option>
					<option value="3" <?php selected($default_db_mode, 3); ?>><?php esc_html_e('Skip database - Restore files only', 'siteguardbackup'); ?></option>
				</select>
				<p class="description"><?php esc_html_e('Default mode for WP-CLI restores, remote-receive migrations, and any restore where the mode is not explicitly chosen.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Default Health Monitor Key on Restore', 'siteguardbackup'); ?></th>
			<td>
				<?php $default_health_key_policy = (isset($settings['default_health_key_policy']) && 'backup' === $settings['default_health_key_policy']) ? 'backup' : 'keep'; ?>
				<select name="default_health_key_policy">
					<option value="keep" <?php selected($default_health_key_policy, 'keep'); ?>><?php esc_html_e("Keep this site's monitor key (default)", 'siteguardbackup'); ?></option>
					<option value="backup" <?php selected($default_health_key_policy, 'backup'); ?>><?php esc_html_e('Use the key from the backup', 'siteguardbackup'); ?></option>
				</select>
				<p class="description"><?php esc_html_e("If the bot monitors this server with this server's own key, keep the default: restores then never change it. Choose 'Use the key from the backup' only if the bot polls this server with the backup site's key. Applies to database restores that do not choose otherwise; if this site has no key, the backup's key is used either way.", 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<?php if (is_multisite()) : ?>
		<tr>
			<th><?php esc_html_e('Default Subsite Migration Mode', 'siteguardbackup'); ?></th>
			<td>
				<?php $default_subsite_mode = isset($settings['default_subsite_mode']) ? intval($settings['default_subsite_mode']) : 0; ?>
				<select name="default_subsite_mode">
					<option value="0" <?php selected($default_subsite_mode, 0); ?>><?php esc_html_e('Mirror all subsites to new domain (default)', 'siteguardbackup'); ?></option>
					<option value="1" <?php selected($default_subsite_mode, 1); ?>><?php esc_html_e('Main site only - remove subsites', 'siteguardbackup'); ?></option>
					<option value="2" <?php selected($default_subsite_mode, 2); ?>><?php esc_html_e('Keep original domains (no remap)', 'siteguardbackup'); ?></option>
					<option value="3" <?php selected($default_subsite_mode, 3); ?>><?php esc_html_e('Custom mapping - choose subdomain for each site', 'siteguardbackup'); ?></option>
				</select>
				<p class="description"><?php esc_html_e('How subsites are handled during multisite-to-multisite migrations. Only applies when database restore mode is "Migrate".', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<?php endif; ?>
		<tr>
			<th><?php esc_html_e('Keep Original Protocol', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="default_keep_protocol" value="1" <?php checked(!empty($settings['default_keep_protocol'])); ?> />
					<?php esc_html_e('Do not convert HTTP/HTTPS during migration restores', 'siteguardbackup'); ?>
				</label>
				<p class="description"><?php esc_html_e('When restoring a backup from a site with a different protocol, keep the original protocol instead of converting URLs. Useful when the destination does not have SSL configured.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr class="sgbackup-anonymise-mode-row" style="<?php echo empty($settings['db_anonymise']) ? 'display:none;' : ''; ?>">
			<th><?php esc_html_e('Anonymisation Mode', 'siteguardbackup'); ?></th>
			<td>
				<?php $anon_mode = isset($settings['db_anonymise_mode']) ? $settings['db_anonymise_mode'] : 'all'; ?>
				<label style="display:block;margin-bottom:4px;"><input type="radio" name="db_anonymise_mode" value="all" <?php checked($anon_mode, 'all'); ?> /> <?php esc_html_e('All users - Anonymise all user data except your own account', 'siteguardbackup'); ?></label>
				<label style="display:block;margin-bottom:4px;"><input type="radio" name="db_anonymise_mode" value="non_staff" <?php checked($anon_mode, 'non_staff'); ?> /> <?php esc_html_e('Non-staff only - Keep admin, editor, and shop manager data intact', 'siteguardbackup'); ?></label>
				<label style="display:block;margin-bottom:4px;"><input type="radio" name="db_anonymise_mode" value="wc_orders" <?php checked($anon_mode, 'wc_orders'); ?> /> <?php esc_html_e('WooCommerce orders only - Only anonymise order/billing/shipping data', 'siteguardbackup'); ?></label>
			</td>
		</tr>
	</table>

	<!-- M3: External Databases -->
	<h2><?php esc_html_e('External Databases', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('Backup additional MySQL databases alongside the WordPress database. Each external database will be exported as a separate file.', 'siteguardbackup'); ?></p>

	<?php
	$extradbs = isset($settings['extradbs']) ? $settings['extradbs'] : array();
	if (!is_array($extradbs)) $extradbs = array();
	?>

	<div id="sgbackup-extradbs-container">
		<?php if (!empty($extradbs)): foreach ($extradbs as $ei => $edb): ?>
			<div class="sgbackup-extradb-row" data-index="<?php echo esc_attr($ei); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Host', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-extradb-field regular-text" data-key="host" value="<?php echo esc_attr($edb['host'] ?? ''); ?>" placeholder="localhost" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Port', 'siteguardbackup'); ?></th>
						<td><input type="number" class="sgbackup-extradb-field" data-key="port" value="<?php echo esc_attr($edb['port'] ?? 3306); ?>" style="width:80px;" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Username', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-extradb-field regular-text" data-key="user" value="<?php echo esc_attr($edb['user'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-extradb-field regular-text" data-key="pass" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($edb['pass'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Database Name', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-extradb-field regular-text" data-key="name" value="<?php echo esc_attr($edb['name'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Table Prefix (optional)', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-extradb-field regular-text" data-key="prefix" value="<?php echo esc_attr($edb['prefix'] ?? ''); ?>" placeholder="<?php esc_attr_e('All tables', 'siteguardbackup'); ?>" />
							<p class="description"><?php esc_html_e('Only backup tables with this prefix. Leave empty to backup all tables.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-extdb" data-index="<?php echo esc_attr($ei); ?>"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-extdb-test-result"></span>
							<button type="button" class="button sgbackup-remove-extdb" data-index="<?php echo esc_attr($ei); ?>" style="float:right;color:#a00;"><?php esc_html_e('Remove', 'siteguardbackup'); ?></button>
						</td>
					</tr>
				</table>
				<hr />
			</div>
		<?php endforeach; endif; ?>
	</div>
	<p>
		<a href="#" id="sgbackup-add-extdb" class="button"><?php esc_html_e('+ Add External Database', 'siteguardbackup'); ?></a>
	</p>

	<h2><?php esc_html_e('Remote Storage', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Send backups to', 'siteguardbackup'); ?></th>
			<td>
				<?php foreach ($methods as $id => $label): ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox" name="service[]" value="<?php echo esc_attr($id); ?>"
							<?php checked(in_array($id, $current_services)); ?>
							class="sgbackup-service-toggle" data-service="<?php echo esc_attr($id); ?>" />
						<?php echo esc_html($label); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Delete local after upload', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="delete_local" value="1" <?php checked($settings['delete_local']); ?> />
					<?php esc_html_e('Delete local backup files after successful upload to remote storage', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
	</table>

	<?php
	/**
	 * Helper: Retrieve all instances for a storage method.
	 * Returns array of (instance_id => instance_settings).
	 */
	if (!function_exists('sgbackup_get_method_instances')) {
	function sgbackup_get_method_instances($opts, $method_id, $defaults = array()) {
		$stored = $opts->get_option(SiteGuard_Backup_Options::method_option($method_id), array());
		$instances = array();

		if (isset($stored['settings']) && is_array($stored['settings'])) {
			foreach ($stored['settings'] as $iid => $isettings) {
				$instances[$iid] = wp_parse_args($isettings, $defaults);
			}
		}

		// Ensure at least one default instance
		if (empty($instances)) {
			$flat = wp_parse_args($stored, $defaults);
			$instances['s-0'] = $flat;
		}

		return $instances;
	}
	} // end function_exists guard
	?>

	<!-- S3 Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-s3" style="<?php echo in_array('s3', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Amazon S3 (or compatible) Settings', 'siteguardbackup'); ?></h3>
		<?php
		$s3_defaults = array('access_key' => '', 'secret_key' => '', 'bucket' => '', 'region' => 'us-east-1', 'path' => '', 'endpoint' => '', 'provider' => 'aws', 'instance_label' => '', 'instance_enabled' => '1');
		$s3_instances = sgbackup_get_method_instances($opts, 's3', $s3_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="s3">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($s3_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('S3', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($s3_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Provider', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="provider" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="aws" <?php selected($iopts['provider'] ?? '', 'aws'); ?>>Amazon S3</option>
								<option value="spaces" <?php selected($iopts['provider'] ?? '', 'spaces'); ?>>DigitalOcean Spaces</option>
								<option value="minio" <?php selected($iopts['provider'] ?? '', 'minio'); ?>>MinIO</option>
								<option value="b2" <?php selected($iopts['provider'] ?? '', 'b2'); ?>>Backblaze B2</option>
								<option value="dreamobjects" <?php selected($iopts['provider'] ?? '', 'dreamobjects'); ?>>DreamObjects</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Access Key', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="access_key" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['access_key'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Secret Key', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="secret_key" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['secret_key'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Bucket', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="bucket" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['bucket'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Region', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="region" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['region'] ?? 'us-east-1'); ?>" placeholder="us-east-1" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Custom Endpoint', 'siteguardbackup'); ?></th>
						<td>
							<input type="url" class="sgbackup-instance-field regular-text" data-key="endpoint" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['endpoint'] ?? ''); ?>" placeholder="https://..." />
							<p class="description"><?php esc_html_e('Only for S3-compatible services (Spaces, MinIO, B2). Leave empty for AWS.', 'siteguardbackup'); ?></p>
							<label><input type="checkbox" class="sgbackup-instance-field" data-key="allow_insecure" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked(!empty($iopts['allow_insecure'])); ?> /> <?php esc_html_e('Allow an unencrypted http:// endpoint (private networks only)', 'siteguardbackup'); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Storage Class', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="storage_class" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="STANDARD" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'STANDARD'); ?>><?php esc_html_e('Standard', 'siteguardbackup'); ?></option>
								<option value="STANDARD_IA" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'STANDARD_IA'); ?>><?php esc_html_e('Standard-IA (Infrequent Access)', 'siteguardbackup'); ?></option>
								<option value="INTELLIGENT_TIERING" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'INTELLIGENT_TIERING'); ?>><?php esc_html_e('Intelligent Tiering', 'siteguardbackup'); ?></option>
								<option value="ONEZONE_IA" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'ONEZONE_IA'); ?>><?php esc_html_e('One Zone-IA', 'siteguardbackup'); ?></option>
								<option value="GLACIER_IR" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'GLACIER_IR'); ?>><?php esc_html_e('Glacier Instant Retrieval', 'siteguardbackup'); ?></option>
								<option value="REDUCED_REDUNDANCY" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'REDUCED_REDUNDANCY'); ?>><?php esc_html_e('Reduced Redundancy', 'siteguardbackup'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('B2 Object Lock (days)', 'siteguardbackup'); ?></th>
						<td>
							<input type="number" class="sgbackup-instance-field" data-key="object_lock_days" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['object_lock_days'] ?? 0); ?>" min="0" max="3000" style="width:100px;" />
							<p class="description"><?php esc_html_e('Backblaze B2 only. GOVERNANCE mode lock. 0 = disabled, max 3000 days.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Server-Side Encryption', 'siteguardbackup'); ?></th>
						<td>
							<label>
								<input type="checkbox" class="sgbackup-instance-field" data-key="server_side_encryption" data-method="s3" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked(!empty($iopts['server_side_encryption'])); ?> />
								<?php esc_html_e('Enable AES-256 server-side encryption (SSE-S3)', 'siteguardbackup'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="s3"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="s3"><?php esc_html_e('+ Add another S3 account', 'siteguardbackup'); ?></a>
	</div>

	<!-- S3-Compatible Generic Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-s3generic" style="<?php echo in_array('s3generic', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('S3-Compatible (Generic) Settings', 'siteguardbackup'); ?></h3>
		<?php
		$s3g_defaults = array('access_key' => '', 'secret_key' => '', 'bucket' => '', 'region' => 'us-east-1', 'path' => '', 'endpoint' => '', 'storage_class' => 'STANDARD', 'signature_version' => 'v4', 'instance_label' => '', 'instance_enabled' => '1');
		$s3g_instances = sgbackup_get_method_instances($opts, 's3generic', $s3g_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="s3generic">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($s3g_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('S3 Generic', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($s3g_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Endpoint (required)', 'siteguardbackup'); ?></th>
						<td>
							<input type="url" class="sgbackup-instance-field regular-text" data-key="endpoint" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['endpoint'] ?? ''); ?>" placeholder="https://s3.example.com" />
							<p class="description"><?php esc_html_e('The S3-compatible endpoint URL. Required for generic providers.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Access Key', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="access_key" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['access_key'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Secret Key', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="secret_key" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['secret_key'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Bucket', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="bucket" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['bucket'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Region', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="region" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['region'] ?? 'us-east-1'); ?>" placeholder="us-east-1" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Signature Version', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="signature_version" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="v4" <?php selected($iopts['signature_version'] ?? 'v4', 'v4'); ?>>V4 (recommended)</option>
								<option value="v2" <?php selected($iopts['signature_version'] ?? 'v4', 'v2'); ?>>V2 (legacy)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Storage Class', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="storage_class" data-method="s3generic" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['storage_class'] ?? 'STANDARD'); ?>" placeholder="STANDARD" />
							<p class="description"><?php esc_html_e('Storage class name. Varies by provider.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="s3generic"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="s3generic"><?php esc_html_e('+ Add another S3-Compatible account', 'siteguardbackup'); ?></a>
	</div>

	<!-- Backblaze B2 Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-b2" style="<?php echo in_array('b2', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Backblaze B2 Settings', 'siteguardbackup'); ?></h3>
		<?php
		$b2_defaults = array('access_key' => '', 'secret_key' => '', 'bucket' => '', 'region' => 'us-west-004', 'path' => '', 'storage_class' => 'STANDARD', 'object_lock_days' => '0', 'instance_label' => '', 'instance_enabled' => '1');
		$b2_instances = sgbackup_get_method_instances($opts, 'b2', $b2_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="b2">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($b2_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('B2', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($b2_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Application Key ID', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="access_key" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['access_key'] ?? ''); ?>" />
							<p class="description"><?php esc_html_e('The keyID from your B2 Application Key. Create one at B2 Cloud Storage > App Keys.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Application Key', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="secret_key" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['secret_key'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Bucket', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="bucket" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['bucket'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Region', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="region" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="us-west-004" <?php selected($iopts['region'] ?? 'us-west-004', 'us-west-004'); ?>>US West (us-west-004)</option>
								<option value="us-west-002" <?php selected($iopts['region'] ?? 'us-west-004', 'us-west-002'); ?>>US West (us-west-002)</option>
								<option value="us-east-005" <?php selected($iopts['region'] ?? 'us-west-004', 'us-east-005'); ?>>US East (us-east-005)</option>
								<option value="eu-central-003" <?php selected($iopts['region'] ?? 'us-west-004', 'eu-central-003'); ?>>EU Central (eu-central-003)</option>
							</select>
							<p class="description"><?php esc_html_e('Your B2 bucket region. Check your bucket settings in the B2 console.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Object Lock (days)', 'siteguardbackup'); ?></th>
						<td>
							<input type="number" class="sgbackup-instance-field small-text" data-key="object_lock_days" data-method="b2" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['object_lock_days'] ?? '0'); ?>" min="0" max="365" />
							<p class="description"><?php esc_html_e('Immutable backup retention in days. 0 = disabled. Requires Object Lock enabled on the bucket.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="b2"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="b2"><?php esc_html_e('+ Add another Backblaze B2 account', 'siteguardbackup'); ?></a>
	</div>

	<!-- Google Drive Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-googledrive" style="<?php echo in_array('googledrive', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Google Drive Settings', 'siteguardbackup'); ?></h3>
		<?php
		$gd_defaults = array('client_id' => '', 'client_secret' => '', 'token' => array(), 'folder_name' => 'SiteGuard Backups', 'instance_label' => '', 'instance_enabled' => '1');
		$gd_instances = sgbackup_get_method_instances($opts, 'googledrive', $gd_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="googledrive">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($gd_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('Google Drive', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($gd_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Client ID', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="client_id" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['client_id'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Client Secret', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="client_secret" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['client_secret'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Folder Name', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="folder_name" data-method="googledrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['folder_name'] ?? 'SiteGuard Backups'); ?>" />
							<p class="description"><?php esc_html_e('Name of the folder in your Google Drive root. Will be created if it does not exist (e.g., "Backups/MySite").', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<?php sgbackup_oauth_rows('googledrive', $iid, $iopts, 'Google', 'Google Cloud Console > APIs & Services > Credentials, OAuth client type \'Web application\', Google Drive API enabled'); ?>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="googledrive"><?php esc_html_e('+ Add another Google Drive account', 'siteguardbackup'); ?></a>
	</div>

	<!-- Dropbox Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-dropbox" style="<?php echo in_array('dropbox', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Dropbox Settings', 'siteguardbackup'); ?></h3>
		<?php
		$db_defaults = array('app_key' => '', 'app_secret' => '', 'token' => array(), 'folder' => '/SiteGuard Backups', 'instance_label' => '', 'instance_enabled' => '1');
		$db_instances = sgbackup_get_method_instances($opts, 'dropbox', $db_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="dropbox">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($db_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('Dropbox', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($db_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('App Key', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="app_key" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['app_key'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('App Secret', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="app_secret" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['app_secret'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Folder', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="folder" data-method="dropbox" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['folder'] ?? '/SiteGuard Backups'); ?>" />
							<p class="description"><?php esc_html_e('Path within your Dropbox. Must start with / (e.g., "/Backups/MySite").', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<?php sgbackup_oauth_rows('dropbox', $iid, $iopts, 'Dropbox', 'dropbox.com/developers/apps > your app > Settings > Redirect URIs'); ?>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="dropbox"><?php esc_html_e('+ Add another Dropbox account', 'siteguardbackup'); ?></a>
	</div>

	<!-- OneDrive Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-onedrive" style="<?php echo in_array('onedrive', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Microsoft OneDrive Settings', 'siteguardbackup'); ?></h3>
		<?php
		$od_defaults = array('client_id' => '', 'client_secret' => '', 'tenant' => 'common', 'token' => array(), 'folder' => 'SiteGuard Backups', 'instance_label' => '', 'instance_enabled' => '1');
		$od_instances = sgbackup_get_method_instances($opts, 'onedrive', $od_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="onedrive">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($od_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('OneDrive', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($od_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Application (client) ID', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="client_id" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['client_id'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Client Secret', 'siteguardbackup'); ?></th>
						<td>
							<input type="password" class="sgbackup-instance-field regular-text" data-key="client_secret" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['client_secret'] ?? '')); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('The secret Value (not its ID) from Certificates & secrets. Secrets expire; sign in again after replacing one.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Tenant', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="tenant" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['tenant'] ?? 'common'); ?>" />
							<p class="description"><?php esc_html_e('"common" for apps that allow personal and work accounts. For an app registered for a single organization, enter its tenant ID or domain.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Folder', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="folder" data-method="onedrive" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['folder'] ?? 'SiteGuard Backups'); ?>" />
							<p class="description"><?php esc_html_e('Folder in the root of the signed-in account\'s OneDrive. Created if it does not exist.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<?php sgbackup_oauth_rows('onedrive', $iid, $iopts, 'Microsoft', 'Microsoft Entra admin center > App registrations > Authentication > Web platform; API permission Files.ReadWrite (delegated)'); ?>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="onedrive"><?php esc_html_e('+ Add another OneDrive account', 'siteguardbackup'); ?></a>
	</div>

	<!-- FTP Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-ftp" style="<?php echo in_array('ftp', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('FTP Settings', 'siteguardbackup'); ?></h3>
		<?php
		$ftp_defaults = array('host' => '', 'port' => 21, 'user' => '', 'pass' => '', 'path' => '/', 'passive' => 1, 'ftps_mode' => 'none', 'instance_label' => '', 'instance_enabled' => '1');
		$ftp_instances = sgbackup_get_method_instances($opts, 'ftp', $ftp_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="ftp">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($ftp_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('FTP', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($ftp_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Host', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="host" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['host'] ?? ''); ?>" placeholder="ftp.example.com" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Username', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="user" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['user'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="pass" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['pass'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Remote Path', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? '/'); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Port', 'siteguardbackup'); ?></th>
						<td><input type="number" class="sgbackup-instance-field" data-key="port" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['port'] ?? 21); ?>" style="width:80px;" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Passive Mode', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-field" data-key="passive" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['passive'] ?? 1); ?> /> <?php esc_html_e('Use passive mode (recommended)', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('FTPS Mode', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="ftps_mode" data-method="ftp" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="none" <?php selected($iopts['ftps_mode'] ?? 'none', 'none'); ?>><?php esc_html_e('None (plain FTP)', 'siteguardbackup'); ?></option>
								<option value="explicit" <?php selected($iopts['ftps_mode'] ?? 'none', 'explicit'); ?>><?php esc_html_e('Explicit FTPS (AUTH TLS)', 'siteguardbackup'); ?></option>
								<option value="implicit" <?php selected($iopts['ftps_mode'] ?? 'none', 'implicit'); ?>><?php esc_html_e('Implicit FTPS (port 990)', 'siteguardbackup'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Explicit: starts plain then upgrades to TLS. Implicit: SSL from the start (typically port 990).', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="ftp"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="ftp"><?php esc_html_e('+ Add another FTP account', 'siteguardbackup'); ?></a>
	</div>

	<!-- SFTP Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-sftp" style="<?php echo in_array('sftp', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('SFTP Settings', 'siteguardbackup'); ?></h3>
		<?php
		$sftp_defaults = array('host' => '', 'port' => 22, 'user' => '', 'pass' => '', 'key' => '', 'path' => '/', 'protocol' => 'sftp', 'fingerprint' => '', 'instance_label' => '', 'instance_enabled' => '1');
		$sftp_instances = sgbackup_get_method_instances($opts, 'sftp', $sftp_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="sftp">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($sftp_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('SFTP', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($sftp_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Host', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="host" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['host'] ?? ''); ?>" placeholder="sftp.example.com" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Port', 'siteguardbackup'); ?></th>
						<td><input type="number" class="sgbackup-instance-field" data-key="port" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['port'] ?? 22); ?>" style="width:80px;" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Username', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="user" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['user'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="pass" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['pass'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('SSH Key (optional)', 'siteguardbackup'); ?></th>
						<td>
							<textarea class="sgbackup-instance-field regular-text" data-key="key" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" rows="3" style="font-family:monospace;"><?php echo esc_textarea(SiteGuard_Backup_Security::mask_secret($iopts['key'] ?? '')); ?></textarea>
							<p class="description"><?php esc_html_e('Paste the private key (RSA, in PEM or OpenSSH format; Ed25519 and ECDSA keys are not supported). With a key, the password field is the key passphrase. Leave empty to log in with the password.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Remote Path', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? '/'); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Protocol', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="protocol" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="sftp" <?php selected($iopts['protocol'] ?? 'sftp', 'sftp'); ?>>SFTP</option>
								<option value="scp" <?php selected($iopts['protocol'] ?? 'sftp', 'scp'); ?>>SCP</option>
							</select>
							<p class="description"><?php esc_html_e('SFTP is recommended. Use SCP only if the server does not support SFTP subsystem.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Host Fingerprint', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="fingerprint" data-method="sftp" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['fingerprint'] ?? ''); ?>" placeholder="<?php esc_attr_e('Auto-populated on first connection', 'siteguardbackup'); ?>" />
							<p class="description"><?php esc_html_e('MD5 fingerprint of the server host key. Populated automatically on first connection. Clear to re-learn.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="sftp"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="sftp"><?php esc_html_e('+ Add another SFTP account', 'siteguardbackup'); ?></a>
	</div>

	<!-- WebDAV Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-webdav" style="<?php echo in_array('webdav', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('WebDAV Settings', 'siteguardbackup'); ?></h3>
		<?php
		$wd_defaults = array('url' => '', 'user' => '', 'pass' => '', 'path' => '/', 'instance_label' => '', 'instance_enabled' => '1');
		$wd_instances = sgbackup_get_method_instances($opts, 'webdav', $wd_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="webdav">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($wd_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('WebDAV', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($wd_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Server URL', 'siteguardbackup'); ?></th>
						<td>
							<input type="url" class="sgbackup-instance-field regular-text" data-key="url" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['url'] ?? ''); ?>" placeholder="https://cloud.example.com/remote.php/dav/files/USER" />
							<p class="description"><?php esc_html_e('WebDAV base URL (Nextcloud, ownCloud, a NAS, ...). Use https://; plain http sends the password in cleartext.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Username', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="user" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['user'] ?? ''); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="pass" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['pass'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Folder', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? '/'); ?>" />
							<p class="description"><?php esc_html_e('Existing folder under the server URL (e.g., "/Backups/MySite").', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="webdav" data-instance="<?php echo esc_attr($iid); ?>"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="webdav"><?php esc_html_e('+ Add another WebDAV server', 'siteguardbackup'); ?></a>
	</div>

	<!-- Azure Blob Storage Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-azure" style="<?php echo in_array('azure', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Microsoft Azure Blob Storage Settings', 'siteguardbackup'); ?></h3>
		<?php
		$azure_defaults = array('account_name' => '', 'account_key' => '', 'container' => '', 'path' => '', 'endpoint_suffix' => 'blob.core.windows.net', 'instance_label' => '', 'instance_enabled' => '1');
		$azure_instances = sgbackup_get_method_instances($opts, 'azure', $azure_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="azure">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($azure_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('Azure', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($azure_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Account Name', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="account_name" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['account_name'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Account Key', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="account_key" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['account_key'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('SAS Token (optional)', 'siteguardbackup'); ?></th>
						<td>
							<input type="password" class="sgbackup-instance-field regular-text" data-key="sas_token" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['sas_token'] ?? '')); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('Used instead of the account key when set (for storage accounts with Shared Key access disabled).', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Container', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="container" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['container'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" />
							<p class="description"><?php esc_html_e('Optional prefix path within the container.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Endpoint (Cloud)', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="endpoint_suffix" data-method="azure" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="blob.core.windows.net" <?php selected($iopts['endpoint_suffix'] ?? 'blob.core.windows.net', 'blob.core.windows.net'); ?>><?php esc_html_e('Global (blob.core.windows.net)', 'siteguardbackup'); ?></option>
								<option value="blob.core.cloudapi.de" <?php selected($iopts['endpoint_suffix'] ?? 'blob.core.windows.net', 'blob.core.cloudapi.de'); ?>><?php esc_html_e('Germany (blob.core.cloudapi.de)', 'siteguardbackup'); ?></option>
								<option value="blob.core.chinacloudapi.cn" <?php selected($iopts['endpoint_suffix'] ?? 'blob.core.windows.net', 'blob.core.chinacloudapi.cn'); ?>><?php esc_html_e('China (blob.core.chinacloudapi.cn)', 'siteguardbackup'); ?></option>
								<option value="blob.core.usgovcloudapi.net" <?php selected($iopts['endpoint_suffix'] ?? 'blob.core.windows.net', 'blob.core.usgovcloudapi.net'); ?>><?php esc_html_e('US Gov (blob.core.usgovcloudapi.net)', 'siteguardbackup'); ?></option>
							</select>
							<p class="description"><?php esc_html_e('Select the Azure national cloud endpoint for your storage account.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="azure"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="azure"><?php esc_html_e('+ Add another Azure account', 'siteguardbackup'); ?></a>
	</div>

	<!-- Google Cloud Storage Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-gcs" style="<?php echo in_array('gcs', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Google Cloud Storage Settings', 'siteguardbackup'); ?></h3>
		<?php
		$gcs_defaults = array('access_key' => '', 'secret_key' => '', 'bucket' => '', 'path' => '', 'project_id' => '', 'storage_class' => 'STANDARD', 'location' => '', 'instance_label' => '', 'instance_enabled' => '1');
		$gcs_instances = sgbackup_get_method_instances($opts, 'gcs', $gcs_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="gcs">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($gcs_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('GCS', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($gcs_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('HMAC Access Key', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="access_key" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['access_key'] ?? ''); ?>" />
							<p class="description"><?php esc_html_e('GCS HMAC key for S3-compatible access. Create in Cloud Console > Storage > Settings > Interoperability.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('HMAC Secret Key', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="secret_key" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['secret_key'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Bucket', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="bucket" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['bucket'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Project ID (optional)', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="project_id" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['project_id'] ?? ''); ?>" />
							<p class="description"><?php esc_html_e('GCP project ID. Optional for most operations.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Storage Class', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="storage_class" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="STANDARD" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'STANDARD'); ?>><?php esc_html_e('Standard', 'siteguardbackup'); ?></option>
								<option value="NEARLINE" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'NEARLINE'); ?>><?php esc_html_e('Nearline', 'siteguardbackup'); ?></option>
								<option value="COLDLINE" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'COLDLINE'); ?>><?php esc_html_e('Coldline', 'siteguardbackup'); ?></option>
								<option value="ARCHIVE" <?php selected($iopts['storage_class'] ?? 'STANDARD', 'ARCHIVE'); ?>><?php esc_html_e('Archive', 'siteguardbackup'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Location (optional)', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="location" data-method="gcs" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['location'] ?? ''); ?>" placeholder="us-central1" />
							<p class="description"><?php esc_html_e('GCS bucket location (e.g., us-central1, eu, asia). Used for bucket creation reference.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="gcs"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="gcs"><?php esc_html_e('+ Add another GCS account', 'siteguardbackup'); ?></a>
	</div>

	<!-- pCloud Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-pcloud" style="<?php echo in_array('pcloud', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('pCloud Settings', 'siteguardbackup'); ?></h3>
		<?php
		$pcloud_defaults = array('client_id' => '', 'client_secret' => '', 'access_token' => '', 'folder_id' => 0, 'use_eu' => false, 'instance_label' => '', 'instance_enabled' => '1');
		$pcloud_instances = sgbackup_get_method_instances($opts, 'pcloud', $pcloud_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="pcloud">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($pcloud_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('pCloud', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($pcloud_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Client ID', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="client_id" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['client_id'] ?? ''); ?>" />
							<p class="description"><?php esc_html_e('From your app at docs.pcloud.com > My Apps. Then use "Sign in with pCloud" below; the data region (US/EU) is detected automatically.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Client Secret', 'siteguardbackup'); ?></th>
						<td><input type="password" class="sgbackup-instance-field regular-text" data-key="client_secret" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['client_secret'] ?? '')); ?>" autocomplete="off" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Access Token', 'siteguardbackup'); ?></th>
						<td>
							<input type="password" class="sgbackup-instance-field regular-text" data-key="access_token" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['access_token'] ?? '')); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('Filled in by "Sign in with pCloud". Only paste a token here if you obtained one yourself (pCloud tokens do not expire); then set EU Region to match the account.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Folder ID', 'siteguardbackup'); ?></th>
						<td>
							<input type="number" class="sgbackup-instance-field" data-key="folder_id" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['folder_id'] ?? 0); ?>" style="width:120px;" />
							<p class="description"><?php esc_html_e('pCloud folder ID to store backups in (0 = root folder).', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('EU Region', 'siteguardbackup'); ?></th>
						<td>
							<label>
								<input type="checkbox" class="sgbackup-instance-field" data-key="use_eu" data-method="pcloud" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked(!empty($iopts['use_eu'])); ?> />
								<?php esc_html_e('Use EU data center (eapi.pcloud.com instead of api.pcloud.com)', 'siteguardbackup'); ?>
							</label>
						</td>
					</tr>
					<?php sgbackup_oauth_rows('pcloud', $iid, $iopts, 'pCloud', 'docs.pcloud.com > My Apps > your app > Redirect URIs'); ?>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="pcloud"><?php esc_html_e('+ Add another pCloud account', 'siteguardbackup'); ?></a>
	</div>

	<!-- Email destination -->
	<div class="sgbackup-method-config" id="sgbackup-config-email" style="<?php echo in_array('email', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('Email Destination', 'siteguardbackup'); ?></h3>
		<?php
		$em_defaults = array('address' => get_option('admin_email'), 'instance_label' => '', 'instance_enabled' => '1');
		$em_instances = sgbackup_get_method_instances($opts, 'email', $em_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="email">
			<?php foreach ($em_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="email" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Send archives to', 'siteguardbackup'); ?></th>
						<td>
							<input type="email" class="sgbackup-instance-field regular-text" data-key="address" data-method="email" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['address'] ?? get_option('admin_email')); ?>" />
							<p class="description"><?php esc_html_e('Each archive is mailed as an attachment through wp_mail(). Archives over 25 MB are not sent (most mail servers reject them), so use email only for small sites or alongside another destination. Backup report emails are set under Email Reports.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="email" data-instance="<?php echo esc_attr($iid); ?>"><?php esc_html_e('Send Test Email', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- OpenStack (Swift / Rackspace) Configuration -->
	<div class="sgbackup-method-config" id="sgbackup-config-openstack" style="<?php echo in_array('openstack', $current_services) ? '' : 'display:none;'; ?>">
		<h3><?php esc_html_e('OpenStack Swift / Rackspace Cloud Files Settings', 'siteguardbackup'); ?></h3>
		<?php
		$openstack_defaults = array('auth_url' => '', 'username' => '', 'password' => '', 'api_key' => '', 'tenant' => '', 'region' => '', 'container' => '', 'path' => '', 'auth_version' => 'v2', 'instance_label' => '', 'instance_enabled' => '1');
		$openstack_instances = sgbackup_get_method_instances($opts, 'openstack', $openstack_defaults);
		?>
		<div class="sgbackup-instance-container" data-method="openstack">
			<div class="sgbackup-instance-tabs">
				<?php foreach ($openstack_instances as $iid => $iopts): ?>
					<span class="sgbackup-instance-tab" data-instance="<?php echo esc_attr($iid); ?>">
						<span class="sgbackup-instance-label" contenteditable="true"><?php echo esc_html(!empty($iopts['instance_label']) ? $iopts['instance_label'] : __('OpenStack', 'siteguardbackup')); ?></span>
						<?php if ($iid !== 's-0'): ?>
							<button type="button" class="sgbackup-delete-instance" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" title="<?php esc_attr_e('Delete instance', 'siteguardbackup'); ?>">&times;</button>
						<?php endif; ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php foreach ($openstack_instances as $iid => $iopts): ?>
			<div class="sgbackup-instance-panel" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>">
				<table class="form-table">
					<tr>
						<th><?php esc_html_e('Enabled', 'siteguardbackup'); ?></th>
						<td><label><input type="checkbox" class="sgbackup-instance-enabled" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="1" <?php checked($iopts['instance_enabled'] ?? '1', '1'); ?> /> <?php esc_html_e('Enable this instance', 'siteguardbackup'); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Authentication URL', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="auth_url" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['auth_url'] ?? ''); ?>" placeholder="https://identity.api.rackspacecloud.com/v2.0" />
							<p class="description"><?php esc_html_e('Keystone identity endpoint URL. For Rackspace, use https://identity.api.rackspacecloud.com/v2.0', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Auth Version', 'siteguardbackup'); ?></th>
						<td>
							<select class="sgbackup-instance-field" data-key="auth_version" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>">
								<option value="v2" <?php selected($iopts['auth_version'] ?? 'v2', 'v2'); ?>><?php esc_html_e('v2 (Keystone v2.0 / Rackspace)', 'siteguardbackup'); ?></option>
								<option value="v3" <?php selected($iopts['auth_version'] ?? 'v2', 'v3'); ?>><?php esc_html_e('v3 (Keystone v3)', 'siteguardbackup'); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Username', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="username" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['username'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
						<td>
							<input type="password" class="sgbackup-instance-field regular-text" data-key="password" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['password'] ?? '')); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('Keystone password. Leave empty if using API key (Rackspace).', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('API Key (Rackspace)', 'siteguardbackup'); ?></th>
						<td>
							<input type="password" class="sgbackup-instance-field regular-text" data-key="api_key" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr(SiteGuard_Backup_Security::mask_secret($iopts['api_key'] ?? '')); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e('Rackspace API key. Used instead of password for Rackspace Cloud Files.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Tenant / Project', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field regular-text" data-key="tenant" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['tenant'] ?? ''); ?>" />
							<p class="description"><?php esc_html_e('OpenStack project/tenant name. Required for most OpenStack deployments.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Region', 'siteguardbackup'); ?></th>
						<td>
							<input type="text" class="sgbackup-instance-field" data-key="region" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['region'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g. DFW, IAD, LON', 'siteguardbackup'); ?>" style="width:150px;" />
							<p class="description"><?php esc_html_e('Region for the object storage service. For Rackspace: DFW, ORD, IAD, LON, SYD, HKG.', 'siteguardbackup'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Container', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="container" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['container'] ?? ''); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Path (optional)', 'siteguardbackup'); ?></th>
						<td><input type="text" class="sgbackup-instance-field regular-text" data-key="path" data-method="openstack" data-instance="<?php echo esc_attr($iid); ?>" value="<?php echo esc_attr($iopts['path'] ?? ''); ?>" placeholder="backups/" /></td>
					</tr>
					<tr>
						<td colspan="2">
							<button type="button" class="button sgbackup-test-credentials" data-method="openstack"><?php esc_html_e('Test Connection', 'siteguardbackup'); ?></button>
							<span class="sgbackup-test-result"></span>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<a href="#" class="sgbackup-add-instance" data-method="openstack"><?php esc_html_e('+ Add another OpenStack account', 'siteguardbackup'); ?></a>
	</div>

	<h2><?php esc_html_e('Reporting', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Email recipients', 'siteguardbackup'); ?></th>
			<td>
				<?php
				// Support structured recipients (array of objects) or legacy string
				$email_recipients = $settings['email_recipients'];
				$structured_recipients = array();
				if (is_array($email_recipients) && !empty($email_recipients)) {
					if (isset($email_recipients[0]) && is_array($email_recipients[0])) {
						$structured_recipients = $email_recipients;
					} elseif (isset($email_recipients[0]) && is_string($email_recipients[0])) {
						// Handle flat array of email strings
						foreach ($email_recipients as $addr) {
							$addr = trim($addr);
							if (!empty($addr)) {
								$structured_recipients[] = array('address' => $addr, 'warnings_only' => 0, 'send_backup' => 0, 'send_db_only' => 0);
							}
						}
					}
				} elseif (is_string($email_recipients) && !empty($email_recipients)) {
					// Convert legacy comma-separated to structured
					$addrs = array_map('trim', explode(',', $email_recipients));
					foreach ($addrs as $addr) {
						if (!empty($addr)) {
							$structured_recipients[] = array('address' => $addr, 'warnings_only' => 0, 'send_backup' => 0, 'send_db_only' => 0);
						}
					}
				}
				// Ensure at least one empty row
				if (empty($structured_recipients)) {
					$structured_recipients[] = array('address' => '', 'warnings_only' => 0, 'send_backup' => 0, 'send_db_only' => 0);
				}
				?>
				<div id="sgbackup-email-recipients-list">
					<table class="widefat" style="max-width:700px;">
						<thead>
							<tr>
								<th><?php esc_html_e('Email Address', 'siteguardbackup'); ?></th>
								<th style="width:110px;text-align:center;"><?php esc_html_e('Warnings only', 'siteguardbackup'); ?></th>
								<th style="width:110px;text-align:center;"><?php esc_html_e('Attach backup', 'siteguardbackup'); ?></th>
								<th style="width:100px;text-align:center;"><?php esc_html_e('DB only', 'siteguardbackup'); ?></th>
								<th style="width:30px;"></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($structured_recipients as $ri => $recip): ?>
							<tr class="sgbackup-recipient-row">
								<td><input type="text" name="email_recipients[<?php echo $ri; ?>][address]" value="<?php echo esc_attr($recip['address']); ?>" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" /></td>
								<td style="text-align:center;"><input type="checkbox" name="email_recipients[<?php echo $ri; ?>][warnings_only]" value="1" <?php checked(!empty($recip['warnings_only'])); ?> /></td>
								<td style="text-align:center;"><input type="checkbox" name="email_recipients[<?php echo $ri; ?>][send_backup]" value="1" <?php checked(!empty($recip['send_backup'])); ?> /></td>
								<td style="text-align:center;"><input type="checkbox" name="email_recipients[<?php echo $ri; ?>][send_db_only]" value="1" <?php checked(!empty($recip['send_db_only'])); ?> /></td>
								<td><button type="button" class="button-link sgbackup-remove-recipient" title="<?php esc_attr_e('Remove', 'siteguardbackup'); ?>">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><a href="#" id="sgbackup-add-recipient" class="button"><?php esc_html_e('+ Add recipient', 'siteguardbackup'); ?></a></p>
				</div>
				<p class="description"><?php esc_html_e('"Warnings only" = skip this recipient when backup succeeds without issues. "Attach backup" = include backup files (up to 25MB). "DB only" = attach only the database backup file.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Email mode', 'siteguardbackup'); ?></th>
			<td>
				<label><input type="radio" name="email_mode" value="always" <?php checked($settings['email_mode'] ?? 'always', 'always'); ?> /> <?php esc_html_e('Always send report', 'siteguardbackup'); ?></label><br/>
				<label><input type="radio" name="email_mode" value="errors_only" <?php checked($settings['email_mode'] ?? 'always', 'errors_only'); ?> /> <?php esc_html_e('Only on errors/warnings', 'siteguardbackup'); ?></label><br/>
				<label><input type="radio" name="email_mode" value="never" <?php checked($settings['email_mode'] ?? 'always', 'never'); ?> /> <?php esc_html_e('Never send email', 'siteguardbackup'); ?></label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Syslog', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="syslog_enabled" value="1" <?php checked($settings['syslog_enabled'] ?? false); ?> />
					<?php esc_html_e('Write backup status to syslog', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e('Encryption', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Enable encryption', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="encryption_enabled" value="1" <?php checked($settings['encryption_enabled']); ?> />
					<?php esc_html_e('Encrypt all backup files (AES-256-GCM)', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Encryption key', 'siteguardbackup'); ?></th>
			<td>
				<?php
				$enc_key_stored = !empty($settings['encryption_key']);
				if ($enc_key_stored) {
					$enc_key_raw = $settings['encryption_key'];
					$enc_key_len = strlen($enc_key_raw);
					if ($enc_key_len > 8) {
						$enc_key_masked = substr($enc_key_raw, 0, 4) . str_repeat('*', $enc_key_len - 8) . substr($enc_key_raw, -4);
					} else {
						$enc_key_masked = str_repeat('*', $enc_key_len);
					}
				}
				?>
				<?php if ($enc_key_stored): ?>
					<p><code><?php echo esc_html($enc_key_masked); ?></code> - <?php esc_html_e('Encryption key is set.', 'siteguardbackup'); ?></p>
					<input type="password" name="encryption_key" value="" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e('Enter new key to change, or leave empty to keep', 'siteguardbackup'); ?>" />
				<?php else: ?>
					<input type="password" name="encryption_key" value="" class="regular-text" autocomplete="off" />
				<?php endif; ?>
				<p class="description"><?php esc_html_e('Encryption key for backup files. Leave empty to keep the current key (or to disable if none is set).', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>


	<h2><?php esc_html_e('Settings Lock', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Enable lock', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="lock_admin" value="1" <?php checked($settings['lock_admin']); ?> />
					<?php esc_html_e('Require password to access plugin settings', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Password', 'siteguardbackup'); ?></th>
			<td>
				<input type="password" name="lock_admin_password" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo !empty($settings['lock_admin_password']) ? esc_attr__('(password set - leave empty to keep)', 'siteguardbackup') : ''; ?>" />
				<p class="description"><?php esc_html_e('Set or change the settings lock password. Leave empty to keep the current password.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Session length', 'siteguardbackup'); ?></th>
			<td>
				<?php $session_length = isset($settings['lock_admin_session_length']) ? intval($settings['lock_admin_session_length']) : 3600; ?>
				<select name="lock_admin_session_length">
					<option value="3600" <?php selected($session_length, 3600); ?>><?php esc_html_e('1 hour', 'siteguardbackup'); ?></option>
					<option value="10800" <?php selected($session_length, 10800); ?>><?php esc_html_e('3 hours', 'siteguardbackup'); ?></option>
					<option value="86400" <?php selected($session_length, 86400); ?>><?php esc_html_e('24 hours', 'siteguardbackup'); ?></option>
					<option value="604800" <?php selected($session_length, 604800); ?>><?php esc_html_e('1 week', 'siteguardbackup'); ?></option>
					<option value="2419200" <?php selected($session_length, 2419200); ?>><?php esc_html_e('4 weeks', 'siteguardbackup'); ?></option>
					<option value="31449600" <?php selected($session_length, 31449600); ?>><?php esc_html_e('52 weeks', 'siteguardbackup'); ?></option>
				</select>
				<p class="description"><?php esc_html_e('How long the unlock session lasts before the password is required again.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Support URL / Email', 'siteguardbackup'); ?></th>
			<td>
				<input type="text" name="lock_admin_support_url" value="<?php echo esc_attr($settings['lock_admin_support_url'] ?? ''); ?>" class="regular-text" placeholder="<?php esc_attr_e('https://example.com/support or admin@example.com', 'siteguardbackup'); ?>" />
				<p class="description"><?php esc_html_e('A URL or email address shown on the lock screen so users know where to get help. Leave empty to hide.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e('Expert / Advanced Settings', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('SSL Verification', 'siteguardbackup'); ?></th>
			<td>
				<label style="display:block;margin-bottom:4px;">
					<input type="checkbox" name="ssl_disableverify" value="1" <?php checked(!empty($settings['ssl_disableverify'])); ?> />
					<?php esc_html_e('Disable SSL verification (not recommended)', 'siteguardbackup'); ?>
				</label>
				<label style="display:block;margin-bottom:4px;">
					<input type="checkbox" name="ssl_useservercerts" value="1" <?php checked(!empty($settings['ssl_useservercerts'])); ?> />
					<?php esc_html_e("Use server's SSL certificates", 'siteguardbackup'); ?>
				</label>
				<p class="description"><?php esc_html_e('These options affect how the plugin communicates with remote storage providers. Only change if you encounter SSL certificate errors.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Auto-Update', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="auto_update" value="1" <?php checked(!empty($settings['auto_update'])); ?> />
					<?php esc_html_e('Automatically update this plugin when updates are available', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Tables to back up without data (structure only)', 'siteguardbackup'); ?></th>
			<td>
				<input type="text" name="data_optional_tables" value="<?php echo esc_attr(isset($settings['data_optional_tables']) ? $settings['data_optional_tables'] : ''); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e('Comma-separated list of table name patterns. Matching tables will have only their CREATE TABLE statement backed up, without row data. Supports wildcards: e.g. wp_actionscheduler_*,wp_statistics_*', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('File extensions to store without compression', 'siteguardbackup'); ?></th>
			<td>
				<input type="text" name="zip_nocompress" value="<?php echo esc_attr(isset($settings['zip_nocompress']) ? $settings['zip_nocompress'] : ''); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e('Comma-separated list of extensions (e.g. .jpg,.png,.mp4). Leave empty to use the built-in default list. These file types are stored in zip archives without compression, saving CPU time.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e('Webhook Notifications', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Webhook URL', 'siteguardbackup'); ?></th>
			<td>
				<input type="url" name="webhook_url" value="<?php echo esc_attr(isset($settings['webhook_url']) ? $settings['webhook_url'] : ''); ?>" class="regular-text" placeholder="https://example.com/webhook" />
				<p class="description"><?php esc_html_e('URL to receive POST notifications with JSON payload: { event, site_url, timestamp, data }. Includes X-SiteGuard-Event and X-SiteGuard-Signature headers.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Signing Secret', 'siteguardbackup'); ?></th>
			<td>
				<?php
				$webhook_secret = SiteGuard_Backup_Options::instance()->get_option('sgbackup_webhook_secret', '');
				?>
				<input type="text" name="webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" readonly />
				<p class="description"><?php esc_html_e('Auto-generated secret used to sign webhook payloads (HMAC-SHA256). Share this with your webhook receiver to verify authenticity.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Events', 'siteguardbackup'); ?></th>
			<td>
				<?php
				$webhook_events = isset($settings['webhook_events']) ? (array) $settings['webhook_events'] : array();
				$event_labels = array(
					'backup_complete'  => __('Backup complete', 'siteguardbackup'),
					'backup_failed'    => __('Backup failed', 'siteguardbackup'),
					'restore_complete' => __('Restore complete', 'siteguardbackup'),
					'restore_failed'   => __('Restore failed', 'siteguardbackup'),
					'schedule_missed'  => __('Scheduled backup missed', 'siteguardbackup'),
				);
				foreach ($event_labels as $value => $label) : ?>
					<label style="display:block; margin-bottom:4px;">
						<input type="checkbox" name="webhook_events[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $webhook_events, true)); ?> />
						<?php echo esc_html($label); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Test', 'siteguardbackup'); ?></th>
			<td>
				<button type="button" class="button sgbackup-test-webhook"><?php esc_html_e('Send Test Webhook', 'siteguardbackup'); ?></button>
				<span class="sgbackup-test-result sgbackup-status"></span>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e('Debug', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Debug mode', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="debug_mode" value="1" <?php checked($settings['debug_mode']); ?> />
					<?php esc_html_e('Enable verbose logging', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
	</table>

	<p class="submit">
		<button type="button" class="button button-primary" id="sgbackup-save-settings"><?php esc_html_e('Save Changes', 'siteguardbackup'); ?></button>
		<span id="sgbackup-settings-status" class="sgbackup-status"></span>
	</p>
</form>
