<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div id="sgbackup-backupnow-modal" title="<?php esc_attr_e('Backup Now', 'siteguardbackup'); ?>" style="display:none;">
	<h3><?php esc_html_e('What to include in the backup:', 'siteguardbackup'); ?></h3>

	<p>
		<label><input type="checkbox" name="sgbackup_include_files" value="1" checked /> <?php esc_html_e('Include files', 'siteguardbackup'); ?></label>
	</p>

	<div id="sgbackup-entity-checkboxes" style="margin-left:20px;">
		<p><label><input type="checkbox" name="sgbackup_entity[]" value="plugins" checked /> <?php esc_html_e('Plugins', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_entity[]" value="themes" checked /> <?php esc_html_e('Themes', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_entity[]" value="uploads" checked /> <?php esc_html_e('Uploads', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_entity[]" value="others" checked /> <?php esc_html_e('Others', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_entity[]" value="wpcore" /> <?php esc_html_e('WordPress Core', 'siteguardbackup'); ?></label></p>
	</div>

	<p>
		<label><input type="checkbox" name="sgbackup_include_db" value="1" checked /> <?php esc_html_e('Include database', 'siteguardbackup'); ?></label>
	</p>

	<div id="sgbackup-table-selection" style="display:none;margin-left:20px;">
		<p><a href="#" id="sgbackup-select-tables-link"><?php esc_html_e('Select individual tables...', 'siteguardbackup'); ?></a></p>
		<div id="sgbackup-table-list" style="display:none;max-height:200px;overflow-y:auto;"></div>
		<p>
			<label><input type="checkbox" name="sgbackup_include_nonwp_tables" /> <?php esc_html_e('Include non-WordPress tables', 'siteguardbackup'); ?></label>
			<br /><span class="description"><?php esc_html_e('Include database tables that do not use the WordPress prefix. Useful if your database contains tables from other applications or third-party tools that share the same database.', 'siteguardbackup'); ?></span>
		</p>
	</div>

	<div id="sgbackup-db-anonymise-section" style="display:none;margin-left:20px;margin-top:8px;">
		<p>
			<label><input type="checkbox" name="sgbackup_anonymise" value="1" /> <?php esc_html_e('Anonymise personal data in this backup', 'siteguardbackup'); ?></label>
			<br /><span class="description"><?php esc_html_e('Replaces personally identifiable information (emails, display names, IP addresses) with randomised data in the database export. The anonymisation is permanent in the backup file and cannot be reversed. Your live database is not affected.', 'siteguardbackup'); ?></span>
		</p>
		<div id="sgbackup-anonymise-mode-options" style="display:none;margin-left:20px;">
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="all" checked /> <?php esc_html_e('All users (except your account)', 'siteguardbackup'); ?></label></p>
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="non_staff" /> <?php esc_html_e('Non-staff users only (keeps admin/editor accounts intact)', 'siteguardbackup'); ?></label></p>
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="wc_orders" /> <?php esc_html_e('WooCommerce orders only (customer names, addresses, phone numbers)', 'siteguardbackup'); ?></label></p>
		</div>
	</div>

	<hr />

	<p>
		<label><input type="checkbox" name="sgbackup_incremental" value="1" /> <?php esc_html_e('Incremental backup (files changed since last full backup only)', 'siteguardbackup'); ?></label>
		<br /><span class="description"><?php esc_html_e('Only backs up files modified since the last full backup. Does not include a database export. Requires a previous full backup as the base - incremental backups cannot be restored on their own.', 'siteguardbackup'); ?></span>
	</p>

	<p>
		<label><input type="checkbox" name="sgbackup_always_keep" value="1" /> <?php esc_html_e('Always keep this backup (exclude from retention limits)', 'siteguardbackup'); ?></label>
		<br /><span class="description"><?php esc_html_e('This backup will never be automatically deleted by the retention policy. Useful for milestone backups (e.g., before a major update or migration). You can still delete it manually.', 'siteguardbackup'); ?></span>
	</p>

	<p>
		<label><?php esc_html_e('Backup label (optional):', 'siteguardbackup'); ?> <input type="text" name="sgbackup_label" maxlength="40" class="regular-text" placeholder="<?php esc_attr_e('e.g., before-plugin-update', 'siteguardbackup'); ?>" /></label>
		<br /><span class="description"><?php esc_html_e('A short name to help you identify this backup later. Maximum 40 characters.', 'siteguardbackup'); ?></span>
	</p>

	<hr />

	<p>
		<label>
			<?php esc_html_e('Send to remote storage:', 'siteguardbackup'); ?>
			<select name="sgbackup_send_to" id="sgbackup-send-to-select">
				<option value=""><?php esc_html_e('(Use configured settings)', 'siteguardbackup'); ?></option>
				<option value="none"><?php esc_html_e('None - local only', 'siteguardbackup'); ?></option>
			</select>
		</label>
	</p>
</div>
