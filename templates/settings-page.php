<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div class="wrap sgbackup-wrap">
	<h1><?php esc_html_e('SiteGuard Backup', 'siteguardbackup'); ?></h1>

	<nav class="nav-tab-wrapper sgbackup-tabs">
		<a href="#backup-restore" class="nav-tab nav-tab-active" data-tab="backup-restore"><?php esc_html_e('Backup / Restore', 'siteguardbackup'); ?></a>
		<a href="#migrate" class="nav-tab" data-tab="migrate"><?php esc_html_e('Migrate / Clone', 'siteguardbackup'); ?></a>
		<a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e('Settings', 'siteguardbackup'); ?></a>
		<a href="#advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e('Advanced Tools', 'siteguardbackup'); ?></a>
	</nav>

	<div class="sgbackup-tab-content" id="tab-backup-restore">
		<?php include SGBACKUP_DIR . 'templates/tab-backup-restore.php'; ?>
	</div>

	<div class="sgbackup-tab-content" id="tab-migrate" style="display:none;">
		<?php include SGBACKUP_DIR . 'templates/tab-migrate.php'; ?>
	</div>

	<div class="sgbackup-tab-content" id="tab-settings" style="display:none;">
		<?php include SGBACKUP_DIR . 'templates/tab-settings.php'; ?>
	</div>

	<div class="sgbackup-tab-content" id="tab-advanced" style="display:none;">
		<?php include SGBACKUP_DIR . 'templates/tab-advanced.php'; ?>
	</div>
</div>

<?php include SGBACKUP_DIR . 'templates/backup-now-modal.php'; ?>
<?php include SGBACKUP_DIR . 'templates/restore-progress.php'; ?>
