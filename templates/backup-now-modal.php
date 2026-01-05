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
		<p><label><input type="checkbox" name="sgbackup_include_nonwp_tables" /> <?php esc_html_e('Include non-WordPress tables', 'siteguardbackup'); ?></label></p>
	</div>

	<div id="sgbackup-db-anonymise-section" style="display:none;margin-left:20px;margin-top:8px;">
		<p>
			<label><input type="checkbox" name="sgbackup_anonymise" value="1" /> <?php esc_html_e('Anonymise personal data in this backup', 'siteguardbackup'); ?></label>
		</p>
		<div id="sgbackup-anonymise-mode-options" style="display:none;margin-left:20px;">
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="all" checked /> <?php esc_html_e('All users (except your account)', 'siteguardbackup'); ?></label></p>
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="non_staff" /> <?php esc_html_e('Non-staff users only', 'siteguardbackup'); ?></label></p>
			<p><label><input type="radio" name="sgbackup_anonymise_mode" value="wc_orders" /> <?php esc_html_e('WooCommerce orders only', 'siteguardbackup'); ?></label></p>
		</div>
	</div>

	<hr />

	<p>
		<label><input type="checkbox" name="sgbackup_incremental" value="1" /> <?php esc_html_e('Incremental backup (files changed since last full backup only)', 'siteguardbackup'); ?></label>
	</p>

	<p>
		<label><input type="checkbox" name="sgbackup_always_keep" value="1" /> <?php esc_html_e('Always keep this backup (exclude from retention limits)', 'siteguardbackup'); ?></label>
	</p>

	<p><label><?php esc_html_e('Backup label (optional):', 'siteguardbackup'); ?> <input type="text" name="sgbackup_label" maxlength="40" class="regular-text" /></label></p>

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
