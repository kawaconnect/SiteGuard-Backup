<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div id="sgbackup-restore-modal" title="<?php esc_attr_e('Restore Backup', 'siteguardbackup'); ?>" style="display:none;">
	<div id="sgbackup-restore-options">
		<h3><?php esc_html_e('Select what to restore:', 'siteguardbackup'); ?></h3>
		<p><label><input type="checkbox" name="sgbackup_restore_db" value="1" checked /> <?php esc_html_e('Database', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_restore_plugins" value="1" checked /> <?php esc_html_e('Plugins', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_restore_themes" value="1" checked /> <?php esc_html_e('Themes', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_restore_uploads" value="1" checked /> <?php esc_html_e('Uploads', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_restore_others" value="1" checked /> <?php esc_html_e('Others', 'siteguardbackup'); ?></label></p>
		<p><label><input type="checkbox" name="sgbackup_restore_wpcore" value="1" checked /> <?php esc_html_e('WordPress Core', 'siteguardbackup'); ?></label></p>
		<hr />
		<p>
			<a href="#" id="sgbackup-selective-restore-link"><?php esc_html_e('Select individual items to restore...', 'siteguardbackup'); ?></a>
		</p>
		<div id="sgbackup-selective-items" class="sgbackup-selective-items" style="display:none;">
			<p><em><?php esc_html_e('Loading backup contents...', 'siteguardbackup'); ?></em></p>
		</div>

		<!-- Gap 25: Include-unspecified checkboxes -->
		<div id="sgbackup-include-unspecified" style="margin-top:8px;">
			<p><label><input type="checkbox" name="sgbackup_include_unspecified_tables" value="1" checked />
				<?php esc_html_e('Also restore tables not listed above', 'siteguardbackup'); ?>
			</label></p>
			<p><label><input type="checkbox" name="sgbackup_include_unspecified_plugins" value="1" checked />
				<?php esc_html_e('Also restore plugins not listed above', 'siteguardbackup'); ?>
			</label></p>
			<p><label><input type="checkbox" name="sgbackup_include_unspecified_themes" value="1" checked />
				<?php esc_html_e('Also restore themes not listed above', 'siteguardbackup'); ?>
			</label></p>
		</div>

		<hr />

		<!-- Gap 23: Move-in mode selection -->
		<p>
			<label><?php esc_html_e('File restore mode:', 'siteguardbackup'); ?>
				<select name="sgbackup_move_in_mode">
					<option value="0"><?php esc_html_e('Overwrite existing files (default)', 'siteguardbackup'); ?></option>
					<option value="1"><?php esc_html_e('Backup existing files before overwriting', 'siteguardbackup'); ?></option>
					<option value="2"><?php esc_html_e('Skip files that already exist', 'siteguardbackup'); ?></option>
					<option value="3"><?php esc_html_e('Merge into existing directories', 'siteguardbackup'); ?></option>
				</select>
			</label>
		</p>

		<hr />
		<p>
			<label><input type="checkbox" name="sgbackup_restore_pre_backup" value="1" checked />
				<?php esc_html_e('Create backup before restoring (recommended)', 'siteguardbackup'); ?>
			</label>
		</p>
		<p>
			<label><?php esc_html_e('Encryption key (if encrypted):', 'siteguardbackup'); ?>
				<input type="password" name="sgbackup_restore_enc_key" value="" class="regular-text" autocomplete="off" />
			</label>
		</p>
	</div>

	<!-- Gap 24: Verification stage -->
	<div id="sgbackup-restore-verify" style="display:none;">
		<h3><?php esc_html_e('Restore Verification', 'siteguardbackup'); ?></h3>
		<div id="sgbackup-verify-info" style="background:#f9f9f9;padding:12px;border:1px solid #ddd;margin-bottom:12px;">
			<p><em><?php esc_html_e('Analyzing backup...', 'siteguardbackup'); ?></em></p>
		</div>
		<div id="sgbackup-verify-warnings" style="display:none;background:#fff3cd;padding:10px;border:1px solid #ffc107;border-radius:4px;margin-bottom:12px;">
		</div>
		<p>
			<button type="button" class="button button-primary" id="sgbackup-verify-proceed">
				<?php esc_html_e('Proceed with restore', 'siteguardbackup'); ?>
			</button>
			<button type="button" class="button" id="sgbackup-verify-back">
				<?php esc_html_e('Back to options', 'siteguardbackup'); ?>
			</button>
		</p>
	</div>

	<div id="sgbackup-restore-progress" style="display:none;">
		<h3><?php esc_html_e('Restore Progress', 'siteguardbackup'); ?></h3>
		<div id="sgbackup-restore-log" style="height:300px;overflow-y:auto;background:#f1f1f1;padding:10px;font-family:monospace;font-size:12px;border:1px solid #ccc;"></div>
		<p id="sgbackup-restore-done" style="display:none;margin-top:12px;">
			<button type="button" class="button button-primary" id="sgbackup-restore-close">
				<?php esc_html_e('Close', 'siteguardbackup'); ?>
			</button>
		</p>
	</div>
</div>
