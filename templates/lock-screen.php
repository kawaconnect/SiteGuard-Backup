<?php if (!defined('ABSPATH')) die('No direct access.'); ?>
<div class="wrap">
	<h1><?php esc_html_e('SiteGuard Backup', 'siteguardbackup'); ?></h1>
	<div class="sgbackup-lock-screen">
		<span class="dashicons dashicons-lock" style="font-size:48px;display:block;text-align:center;margin:20px 0;"></span>
		<p><?php esc_html_e('Settings are password protected. Enter the password to continue.', 'siteguardbackup'); ?></p>
		<form id="sgbackup-unlock-form">
			<input type="password" name="sgbackup_password" class="regular-text" autofocus autocomplete="current-password" aria-label="<?php esc_attr_e('Settings password', 'siteguardbackup'); ?>" />
			<button type="submit" class="button button-primary"><?php esc_html_e('Unlock', 'siteguardbackup'); ?></button>
		</form>
		<div id="sgbackup-unlock-error" style="display:none;color:red;margin-top:10px;"></div>
		<?php
		$support_url = SiteGuard_Backup_Options::instance()->get_option('sgbackup_lock_admin_support_url', '');
		if (!empty($support_url)):
		?>
		<div class="sgbackup-lock-support" style="margin-top:15px;text-align:center;font-size:13px;color:#666;">
			<?php if (is_email($support_url)): ?>
				<p><?php echo esc_html__('Need help? Contact', 'siteguardbackup') . ' '; ?><a href="mailto:<?php echo esc_attr($support_url); ?>"><?php echo esc_html($support_url); ?></a></p>
			<?php else: ?>
				<p><?php echo esc_html__('Need help?', 'siteguardbackup') . ' '; ?><a href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Visit support', 'siteguardbackup'); ?></a></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
