<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$local_keys = array_filter((array) $opts->get_option('sgbackup_migrator_localkeys', array()), function ($k) {
	// Keys are stored network-wide: show this site's own (and pre-1.7.0 keys).
	return is_array($k) && (!isset($k['blog_id']) || (int) $k['blog_id'] === (int) get_current_blog_id());
});
$remote_sites = $opts->get_option('sgbackup_remotesites', array());
?>

<div id="sgbackup-notices-migrate" class="sgbackup-notices"></div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Receive a Backup (This Site)', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Generate a key on this site, then paste it on the sending site to allow backup transfers.', 'siteguardbackup'); ?></p>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Key Name', 'siteguardbackup'); ?></th>
			<td>
				<input type="text" id="sgbackup-migrate-keyname" value="" placeholder="<?php esc_attr_e('e.g. my-production-site', 'siteguardbackup'); ?>" class="regular-text" />
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Key Size', 'siteguardbackup'); ?></th>
			<td>
				<select id="sgbackup-migrate-keysize">
					<option value="2048">2048-bit (recommended)</option>
					<option value="4096">4096-bit (slower, more secure)</option>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Expires', 'siteguardbackup'); ?></th>
			<td>
				<select id="sgbackup-migrate-keyexpires">
					<option value="0"><?php esc_html_e('Never (scheduled sends)', 'siteguardbackup'); ?></option>
					<option value="1"><?php esc_html_e('After 1 day', 'siteguardbackup'); ?></option>
					<option value="7" selected><?php esc_html_e('After 7 days (one-off migration)', 'siteguardbackup'); ?></option>
					<option value="30"><?php esc_html_e('After 30 days', 'siteguardbackup'); ?></option>
					<option value="365"><?php esc_html_e('After 1 year', 'siteguardbackup'); ?></option>
				</select>
				<p class="description"><?php esc_html_e('The bundle holds a secret: anyone who has it can send backups to this site until the key expires or is deleted.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<button type="button" class="button button-primary" id="sgbackup-create-key-btn">
		<?php esc_html_e('Create Key', 'siteguardbackup'); ?>
	</button>
	<span id="sgbackup-key-status" class="sgbackup-status"></span>

	<div id="sgbackup-key-result" style="display:none;margin-top:15px;">
		<p><strong><?php esc_html_e('Copy this key bundle and paste it on the sending site:', 'siteguardbackup'); ?></strong></p>
		<textarea id="sgbackup-key-bundle" rows="4" cols="80" readonly class="widefat" style="font-family:monospace;font-size:12px;"></textarea>
		<button type="button" class="button" id="sgbackup-copy-key"><?php esc_html_e('Copy to Clipboard', 'siteguardbackup'); ?></button>
	</div>

	<?php if (!empty($local_keys)): ?>
	<h3><?php esc_html_e('Existing Receiver Keys', 'siteguardbackup'); ?></h3>
	<table class="widefat">
		<thead>
			<tr>
				<th><?php esc_html_e('Name', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Created', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Expires', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Actions', 'siteguardbackup'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($local_keys as $hash => $key_data): ?>
			<tr>
				<td><?php echo esc_html(isset($key_data['name']) ? $key_data['name'] : $hash); ?></td>
				<td><?php echo esc_html(isset($key_data['created']) ? date_i18n(get_option('date_format'), $key_data['created']) : '?'); ?></td>
				<td><?php
					if (empty($key_data['secret'])) {
						esc_html_e('Made before 1.7.0: no longer accepted. Delete it and create a new key.', 'siteguardbackup');
					} elseif (!empty($key_data['expires'])) {
						echo esc_html(((int) $key_data['expires'] < time() ? __('Expired ', 'siteguardbackup') : '') . date_i18n(get_option('date_format'), (int) $key_data['expires']));
					} else {
						esc_html_e('Never', 'siteguardbackup');
					}
				?></td>
				<td>
					<button type="button" class="button button-small sgbackup-delete-key" data-key-id="<?php echo esc_attr($hash); ?>">
						<?php esc_html_e('Delete', 'siteguardbackup'); ?>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>

<hr />

<div class="sgbackup-section">
	<h2><?php esc_html_e('Send a Backup (To Another Site)', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Paste a key generated on the receiving site to add it as a destination.', 'siteguardbackup'); ?></p>

	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Paste Key', 'siteguardbackup'); ?></th>
			<td>
				<textarea id="sgbackup-paste-key" rows="3" cols="80" class="widefat" style="font-family:monospace;font-size:12px;" placeholder="<?php esc_attr_e('Paste the key bundle from the receiving site here...', 'siteguardbackup'); ?>"></textarea>
			</td>
		</tr>
	</table>

	<button type="button" class="button" id="sgbackup-add-site-btn">
		<?php esc_html_e('Add Site', 'siteguardbackup'); ?>
	</button>
	<span id="sgbackup-addsite-status" class="sgbackup-status"></span>

	<?php if (!empty($remote_sites)): ?>
	<h3><?php esc_html_e('Configured Destinations', 'siteguardbackup'); ?></h3>
	<table class="widefat" id="sgbackup-remotesites-table">
		<thead>
			<tr>
				<th><?php esc_html_e('Name', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('URL', 'siteguardbackup'); ?></th>
				<th><?php esc_html_e('Actions', 'siteguardbackup'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($remote_sites as $id => $site): ?>
			<tr>
				<td><?php echo esc_html(isset($site['name']) ? $site['name'] : "Site #{$id}"); ?></td>
				<td><?php echo esc_html(isset($site['url']) ? $site['url'] : ''); ?></td>
				<td>
					<button type="button" class="button button-small sgbackup-ping-site" data-site-id="<?php echo esc_attr($id); ?>">
						<?php esc_html_e('Test', 'siteguardbackup'); ?>
					</button>
					<button type="button" class="button button-small sgbackup-send-site" data-site-id="<?php echo esc_attr($id); ?>">
						<?php esc_html_e('Send Backup', 'siteguardbackup'); ?>
					</button>
					<button type="button" class="button button-small button-primary sgbackup-clone-site" data-site-id="<?php echo esc_attr($id); ?>">
						<?php esc_html_e('Clone to Site', 'siteguardbackup'); ?>
					</button>
					<button type="button" class="button button-small sgbackup-delete-site" data-site-id="<?php echo esc_attr($id); ?>">
						<?php esc_html_e('Delete', 'siteguardbackup'); ?>
					</button>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<div id="sgbackup-send-options" style="display:none;margin-top:15px;">
		<h3><?php esc_html_e('Send Options', 'siteguardbackup'); ?></h3>
		<p>
			<label>
				<input type="radio" name="sgbackup_send_type" value="new" checked />
				<?php esc_html_e('Create new backup and send', 'siteguardbackup'); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="radio" name="sgbackup_send_type" value="existing" />
				<?php esc_html_e('Send existing backup:', 'siteguardbackup'); ?>
			</label>
			<select id="sgbackup-send-existing-select">
				<option value=""><?php esc_html_e('Select backup...', 'siteguardbackup'); ?></option>
			</select>
		</p>
		<p>
			<label><?php esc_html_e('Backup label:', 'siteguardbackup'); ?>
				<input type="text" id="sgbackup-send-label" maxlength="40" placeholder="<?php esc_attr_e('e.g., pre-migration-2024', 'siteguardbackup'); ?>" class="regular-text" />
			</label>
		</p>
		<button type="button" class="button button-primary" id="sgbackup-send-btn">
			<?php esc_html_e('Send', 'siteguardbackup'); ?>
		</button>
		<span id="sgbackup-send-status" class="sgbackup-status"></span>
	</div>
</div>

<hr />

<div class="sgbackup-section">
	<h2><?php esc_html_e('Scheduled Remote Send', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Automatically create backups and send them to remote destinations on a schedule.', 'siteguardbackup'); ?></p>

	<table class="form-table" id="sgbackup-remotesend-schedule-form">
		<tr>
			<th><?php esc_html_e('Interval', 'siteguardbackup'); ?></th>
			<td>
				<select id="sgbackup-remotesend-interval">
					<option value="manual"><?php esc_html_e('Manual only', 'siteguardbackup'); ?></option>
					<option value="everyhour"><?php esc_html_e('Every hour', 'siteguardbackup'); ?></option>
					<option value="every2hours"><?php esc_html_e('Every 2 hours', 'siteguardbackup'); ?></option>
					<option value="every4hours"><?php esc_html_e('Every 4 hours', 'siteguardbackup'); ?></option>
					<option value="every8hours"><?php esc_html_e('Every 8 hours', 'siteguardbackup'); ?></option>
					<option value="twicedaily"><?php esc_html_e('Twice daily', 'siteguardbackup'); ?></option>
					<option value="daily"><?php esc_html_e('Daily', 'siteguardbackup'); ?></option>
					<option value="weekly"><?php esc_html_e('Weekly', 'siteguardbackup'); ?></option>
					<option value="fortnightly"><?php esc_html_e('Fortnightly', 'siteguardbackup'); ?></option>
					<option value="monthly"><?php esc_html_e('Monthly', 'siteguardbackup'); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Destinations', 'siteguardbackup'); ?></th>
			<td id="sgbackup-remotesend-destinations">
				<?php if (!empty($remote_sites)): ?>
					<?php foreach ($remote_sites as $id => $site): ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox" name="sgbackup_remotesend_dest[]" value="<?php echo esc_attr($id); ?>" />
						<?php echo esc_html(isset($site['name']) ? $site['name'] : "Site #{$id}"); ?>
						<small>(<?php echo esc_html(isset($site['url']) ? $site['url'] : ''); ?>)</small>
					</label>
					<?php endforeach; ?>
				<?php else: ?>
					<em><?php esc_html_e('No remote sites configured. Add a destination above first.', 'siteguardbackup'); ?></em>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Entities', 'siteguardbackup'); ?></th>
			<td>
				<label><input type="checkbox" name="sgbackup_remotesend_entity[]" value="plugins" /> <?php esc_html_e('Plugins', 'siteguardbackup'); ?></label>
				<label><input type="checkbox" name="sgbackup_remotesend_entity[]" value="themes" /> <?php esc_html_e('Themes', 'siteguardbackup'); ?></label>
				<label><input type="checkbox" name="sgbackup_remotesend_entity[]" value="uploads" /> <?php esc_html_e('Uploads', 'siteguardbackup'); ?></label>
				<label><input type="checkbox" name="sgbackup_remotesend_entity[]" value="others" /> <?php esc_html_e('Others', 'siteguardbackup'); ?></label>
				<label><input type="checkbox" name="sgbackup_remotesend_entity[]" value="wpcore" /> <?php esc_html_e('WP Core', 'siteguardbackup'); ?></label>
				<p class="description"><?php esc_html_e('Database is always included. Leave all unchecked to send all file entities.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e('Local Retention', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" id="sgbackup-remotesend-retain" min="1" max="100" value="5" style="width:60px;" />
				<p class="description"><?php esc_html_e('Number of local backup copies to keep from scheduled remote sends.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>

	<button type="button" class="button button-primary" id="sgbackup-save-remotesend-schedule">
		<?php esc_html_e('Save Schedule', 'siteguardbackup'); ?>
	</button>
	<span id="sgbackup-remotesend-schedule-status" class="sgbackup-status"></span>
	<span id="sgbackup-remotesend-next" style="margin-left:12px;font-style:italic;color:#666;"></span>
</div>
