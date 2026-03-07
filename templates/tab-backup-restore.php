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

<div class="sgbackup-section" id="sgbackup-log-panel" style="display:none;">
	<h2>
		<?php esc_html_e('Backup Log', 'siteguardbackup'); ?>
		<button type="button" class="button button-small" id="sgbackup-log-refresh" style="margin-left:8px;"><?php esc_html_e('Refresh', 'siteguardbackup'); ?></button>
		<button type="button" class="button button-small" id="sgbackup-log-close" style="margin-left:4px;"><?php esc_html_e('Close', 'siteguardbackup'); ?></button>
	</h2>
	<div id="sgbackup-log-content" style="max-height:400px;overflow-y:auto;background:#1d2327;color:#c3c4c7;padding:12px;font-family:monospace;font-size:11px;border-radius:3px;word-break:break-all;line-height:1.5;"></div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Existing Backups', 'siteguardbackup'); ?></h2>
	<div id="sgbackup-existing-backups">
		<?php include SGBACKUP_DIR . 'templates/existing-backups-table.php'; ?>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Upload Backup Files', 'siteguardbackup'); ?></h2>
	<p><?php esc_html_e('Upload backup files or a downloaded backup bundle (.zip) to restore on this site.', 'siteguardbackup'); ?></p>
	<div id="sgbackup-upload-area" class="sgbackup-upload-area">
		<input type="file" id="sgbackup-upload-input" accept=".zip,.gz,.sql,.crypt" />
		<button type="button" class="button" id="sgbackup-upload-btn"><?php esc_html_e('Upload', 'siteguardbackup'); ?></button>
		<button type="button" class="button sgbackup-upload-cancel-btn" id="sgbackup-upload-cancel-btn" style="display:none;"><?php esc_html_e('Cancel', 'siteguardbackup'); ?></button>
		<span id="sgbackup-upload-status" class="sgbackup-status"></span>
		<div id="sgbackup-upload-progress-wrap" class="sgbackup-upload-progress-wrap">
			<div class="sgbackup-upload-progress-track">
				<div id="sgbackup-upload-progress-bar" class="sgbackup-upload-progress-bar"></div>
			</div>
			<div id="sgbackup-upload-progress-text" class="sgbackup-upload-progress-text"></div>
		</div>
	</div>
</div>
