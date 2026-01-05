<?php if (!defined('ABSPATH')) die('No direct access.');
$history = SiteGuard_Backup_History::get_history();
$opts = SiteGuard_Backup_Options::instance();
$backup_dir = $opts->get_backup_dir();
$total_backups = count($history);
$page_size = 20;

if (empty($history)): ?>
	<p><em><?php esc_html_e('No backups found.', 'siteguardbackup'); ?></em></p>
<?php return; endif; ?>

<table class="widefat sgbackup-backups-table">
	<thead>
		<tr>
			<th class="sgbackup-check-col"><input type="checkbox" id="sgbackup-select-all" title="<?php esc_attr_e('Select all', 'siteguardbackup'); ?>" /></th>
			<th><?php esc_html_e('Date', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Database', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Plugins', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Themes', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Uploads', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Others', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('WP Core', 'siteguardbackup'); ?></th>
			<th><?php esc_html_e('Actions', 'siteguardbackup'); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php $row_index = 0; foreach ($history as $timestamp => $backup):
			$nonce = isset($backup['nonce']) ? $backup['nonce'] : '';
			$date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
			$hidden_class = ($row_index >= $page_size) ? ' sgbackup-row-hidden' : '';

			/* Detect if any files in this backup are encrypted (.crypt suffix) */
			$is_encrypted = false;
			foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $_ent) {
				if (!empty($backup[$_ent])) {
					$_files = is_array($backup[$_ent]) ? $backup[$_ent] : array($backup[$_ent]);
					foreach ($_files as $_f) {
						if (substr($_f, -6) === '.crypt') { $is_encrypted = true; break 2; }
					}
				}
			}
		?>
		<tr data-timestamp="<?php echo esc_attr($timestamp); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" class="sgbackup-backup-row<?php echo esc_attr($hidden_class); ?>">
			<td class="sgbackup-check-col"><input type="checkbox" class="sgbackup-row-check" value="<?php echo esc_attr($timestamp); ?>" /></td>
			<td>
				<?php echo esc_html($date); ?>
				<?php if (!empty($backup['label'])): ?>
					<span class="sgbackup-backup-label"><?php echo esc_html($backup['label']); ?></span>
				<?php endif; ?>
				<?php if (!empty($backup['always_keep'])): ?>
					<span class="sgbackup-badge sgbackup-badge-keep" title="<?php esc_attr_e('Always keep', 'siteguardbackup'); ?>">&#9733;</span>
				<?php endif; ?>
				<?php if ($is_encrypted): ?>
					<span class="sgbackup-badge sgbackup-badge-encrypted" title="<?php esc_attr_e('Encrypted', 'siteguardbackup'); ?>">&#128274;</span>
				<?php endif; ?>
			</td>
			<?php foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity): ?>
			<td>
				<?php if (!empty($backup[$entity])):
					$files = is_array($backup[$entity]) ? $backup[$entity] : array($backup[$entity]);
					$total_size = 0;
					$all_exist = true;
					foreach ($files as $f) {
						$fpath = $backup_dir . $f;
						if (file_exists($fpath)) {
							$total_size += filesize($fpath);
						} else {
							$all_exist = false;
						}
					}
				?>
					<span class="sgbackup-entity-info <?php echo $all_exist ? 'sgbackup-exists' : 'sgbackup-missing'; ?>">
						<?php echo esc_html(SiteGuard_Backup_Filesystem::format_bytes($total_size)); ?>
						(<?php echo count($files); ?>)
					</span>
					<?php foreach ($files as $f): ?>
						<?php if (file_exists($backup_dir . $f)): ?>
							<a href="<?php echo esc_url(admin_url('admin-ajax.php') . '?action=sgbackup_download&file=' . urlencode($f) . '&_wpnonce=' . wp_create_nonce('sgbackup-ajax')); ?>"
							   class="sgbackup-download-link" title="<?php esc_attr_e('Download', 'siteguardbackup'); ?>">&#8615;</a>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php else: ?>
					<span class="sgbackup-entity-none">-</span>
				<?php endif; ?>
			</td>
			<?php endforeach; ?>
			<td class="sgbackup-actions">
				<button type="button" class="button button-small sgbackup-restore-btn" data-timestamp="<?php echo esc_attr($timestamp); ?>">
					<?php esc_html_e('Restore', 'siteguardbackup'); ?>
				</button>
				<button type="button" class="button button-small sgbackup-delete-btn" data-timestamp="<?php echo esc_attr($timestamp); ?>">
					<?php esc_html_e('Delete', 'siteguardbackup'); ?>
				</button>
				<button type="button" class="button button-small sgbackup-log-btn" data-nonce="<?php echo esc_attr($nonce); ?>">
					<?php esc_html_e('Log', 'siteguardbackup'); ?>
				</button>
				<button type="button" class="button button-small sgbackup-keep-btn" data-timestamp="<?php echo esc_attr($timestamp); ?>" data-keep="<?php echo !empty($backup['always_keep']) ? '1' : '0'; ?>">
					<?php echo !empty($backup['always_keep']) ? esc_html__('Unkeep', 'siteguardbackup') : esc_html__('Keep', 'siteguardbackup'); ?>
				</button>
				<button type="button" class="button button-small sgbackup-verify-btn" data-timestamp="<?php echo esc_attr($timestamp); ?>">
					<?php esc_html_e('Verify', 'siteguardbackup'); ?>
				</button>
				<button type="button" class="button button-small sgbackup-upload-remote-btn" data-timestamp="<?php echo esc_attr($timestamp); ?>">
					<?php esc_html_e('Upload to Remote', 'siteguardbackup'); ?>
				</button>
			</td>
		</tr>
		<?php $row_index++; endforeach; ?>
	</tbody>
</table>

<div class="sgbackup-table-actions" style="margin-top:10px;">
	<span class="sgbackup-mass-actions">
		<a href="#" id="sgbackup-select-all-link"><?php esc_html_e('Select All', 'siteguardbackup'); ?></a> |
		<a href="#" id="sgbackup-deselect-all-link"><?php esc_html_e('Deselect All', 'siteguardbackup'); ?></a>
		&nbsp;
		<button type="button" class="button button-small" id="sgbackup-delete-selected"><?php esc_html_e('Delete Selected', 'siteguardbackup'); ?></button>
	</span>

	<?php if ($total_backups > $page_size): ?>
	<span class="sgbackup-pagination" style="float:right;">
		<a href="#" id="sgbackup-show-more" data-page-size="<?php echo esc_attr($page_size); ?>"><?php
			$hidden_count = $total_backups - $page_size;
			printf(esc_html__('Show more backups... (%d hidden)', 'siteguardbackup'), $hidden_count);
		?></a>
		&nbsp;|&nbsp;
		<a href="#" id="sgbackup-show-all"><?php esc_html_e('Show all backups', 'siteguardbackup'); ?></a>
	</span>
	<?php endif; ?>
</div>

<div class="sgbackup-rescan-buttons" style="margin-top:10px;">
	<button type="button" class="button button-small" id="sgbackup-rescan-local"><?php esc_html_e('Rescan Local', 'siteguardbackup'); ?></button>
	<button type="button" class="button button-small" id="sgbackup-rescan-remote"><?php esc_html_e('Rescan Remote', 'siteguardbackup'); ?></button>
	<span id="sgbackup-rescan-status" class="sgbackup-status"></span>
</div>
