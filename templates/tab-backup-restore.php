<?php if (!defined('ABSPATH')) die('No direct access.');
$sgbackup = SiteGuard_Backup::instance();
$schedule = $sgbackup->get_schedule_info();
$opts = SiteGuard_Backup_Options::instance();
?>

<div id="sgbackup-notices" class="sgbackup-notices"></div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Next Scheduled Backups', 'siteguardbackup'); ?></h2>
	<table class="widefat sgbackup-schedule-table">
		<tbody>
			<tr>
				<td><strong><?php esc_html_e('Files:', 'siteguardbackup'); ?></strong></td>
				<td>
					<?php if ($schedule['interval'] !== 'manual' && $schedule['next_files']): ?>
						<?php echo esc_html($schedule['interval']); ?> -
						<?php esc_html_e('Next:', 'siteguardbackup'); ?>
						<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['next_files'])); ?>
					<?php else: ?>
						<?php esc_html_e('Manual only', 'siteguardbackup'); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e('Database:', 'siteguardbackup'); ?></strong></td>
				<td>
					<?php if ($schedule['interval_database'] !== 'manual' && $schedule['next_database']): ?>
						<?php echo esc_html($schedule['interval_database']); ?> -
						<?php esc_html_e('Next:', 'siteguardbackup'); ?>
						<?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule['next_database'])); ?>
					<?php else: ?>
						<?php esc_html_e('Manual only', 'siteguardbackup'); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Backup Now', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Create a backup immediately.', 'siteguardbackup'); ?></p>
	<button type="button" class="button button-primary" id="sgbackup-backupnow-btn">
		<?php esc_html_e('Backup Now', 'siteguardbackup'); ?>
	</button>
	<span id="sgbackup-backup-status" class="sgbackup-status"></span>
</div>

<div class="sgbackup-section" id="sgbackup-active-jobs" style="display:none;">
	<h2><?php esc_html_e('Active Jobs', 'siteguardbackup'); ?></h2>
	<div id="sgbackup-active-jobs-list"></div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Existing Backups', 'siteguardbackup'); ?></h2>
	<div id="sgbackup-existing-backups">
		<?php include SGBACKUP_DIR . 'templates/existing-backups-table.php'; ?>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Upload Backup Files', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Upload backup files to the backup directory.', 'siteguardbackup'); ?></p>
	<div id="sgbackup-upload-area" class="sgbackup-upload-area">
		<input type="file" id="sgbackup-upload-input" accept=".zip,.gz,.sql,.crypt" multiple />
		<button type="button" class="button" id="sgbackup-upload-btn"><?php esc_html_e('Upload', 'siteguardbackup'); ?></button>
		<span id="sgbackup-upload-status" class="sgbackup-status"></span>
	</div>
</div>
