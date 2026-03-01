<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());
$remote_sites = $opts->get_option('sgbackup_remotesites', array());
?>

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
				<th><?php esc_html_e('Actions', 'siteguardbackup'); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($local_keys as $hash => $key_data): ?>
			<tr>
				<td><?php echo esc_html(isset($key_data['name']) ? $key_data['name'] : $hash); ?></td>
				<td><?php echo esc_html(isset($key_data['created']) ? date_i18n(get_option('date_format'), $key_data['created']) : '?'); ?></td>
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
		<button type="button" class="button button-primary" id="sgbackup-send-btn">
			<?php esc_html_e('Send', 'siteguardbackup'); ?>
		</button>
		<span id="sgbackup-send-status" class="sgbackup-status"></span>
		<div id="sgbackup-send-progress" class="sgbackup-send-progress" style="display:none;">
			<div class="sgbackup-progress-bar-wrap">
				<div class="sgbackup-progress-bar" style="width:0%;"></div>
				<span class="sgbackup-progress-text">Preparing...</span>
			</div>
		</div>
	</div>
</div>

<?php if (is_multisite()): ?>
<hr />
<div class="sgbackup-section">
	<h2><?php esc_html_e('Multisite Subsite Management', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('When restoring a multisite backup, you can choose which subsites to restore and remap their domains/paths.', 'siteguardbackup'); ?></p>

	<div id="sgbackup-multisite-restore-options">
		<h3><?php esc_html_e('Current Subsites', 'siteguardbackup'); ?></h3>
		<table class="widefat" id="sgbackup-current-subsites">
			<thead>
				<tr>
					<th><?php esc_html_e('Blog ID', 'siteguardbackup'); ?></th>
					<th><?php esc_html_e('Domain', 'siteguardbackup'); ?></th>
					<th><?php esc_html_e('Path', 'siteguardbackup'); ?></th>
					<th><?php esc_html_e('Name', 'siteguardbackup'); ?></th>
				</tr>
			</thead>
			<tbody id="sgbackup-current-subsites-body">
				<tr><td colspan="4"><?php esc_html_e('Loading...', 'siteguardbackup'); ?></td></tr>
			</tbody>
		</table>

		<h3 style="margin-top:20px;"><?php esc_html_e('Restore Subsite Selection', 'siteguardbackup'); ?></h3>
		<p><?php esc_html_e('Select a backup to see which subsites it contains. You can choose which to restore and remap their subdomains.', 'siteguardbackup'); ?></p>
		<p>
			<select id="sgbackup-subsite-backup-select">
				<option value=""><?php esc_html_e('Select a backup to inspect...', 'siteguardbackup'); ?></option>
			</select>
			<button type="button" class="button" id="sgbackup-inspect-subsites-btn"><?php esc_html_e('Inspect Subsites', 'siteguardbackup'); ?></button>
		</p>

		<div id="sgbackup-backup-subsites-panel" style="display:none;">
			<table class="widefat" id="sgbackup-backup-subsites">
				<thead>
					<tr>
						<th style="width:30px;"><input type="checkbox" id="sgbackup-select-all-subsites" checked /></th>
						<th><?php esc_html_e('Blog ID', 'siteguardbackup'); ?></th>
						<th><?php esc_html_e('Original Domain', 'siteguardbackup'); ?></th>
						<th><?php esc_html_e('New Subdomain / Domain', 'siteguardbackup'); ?></th>
					</tr>
				</thead>
				<tbody id="sgbackup-backup-subsites-body">
				</tbody>
			</table>
			<p style="margin-top:10px;">
				<em><?php esc_html_e('Leave "New Subdomain" empty to keep the original domain, or enter a new subdomain to remap.', 'siteguardbackup'); ?></em>
			</p>
		</div>
	</div>
</div>
<?php endif; ?>
