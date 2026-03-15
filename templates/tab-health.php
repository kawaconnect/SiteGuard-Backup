<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$health_enabled = $opts->get_option('sgbackup_health_enabled', false);
$health_interval = $opts->get_option('sgbackup_health_interval', 'every4hours');
$health_timeout = intval($opts->get_option('sgbackup_health_timeout', 10));
$health_ssl_warn = intval($opts->get_option('sgbackup_health_ssl_warn_days', 14));
$rest_url = rest_url('sgbackup/v1/health');
?>

<?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
<div class="notice notice-warning inline" style="margin:0 0 15px;">
	<p><strong><?php esc_html_e('WP-Cron is disabled.', 'siteguardbackup'); ?></strong>
	<?php esc_html_e('Scheduled health checks require an external cron trigger (e.g. system crontab, Azure WebJobs, or an uptime monitor hitting wp-cron.php). Without it, checks will only run when you click "Check Now".', 'siteguardbackup'); ?></p>
</div>
<?php endif; ?>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Health Monitor Configuration', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e('Enable Health Monitor', 'siteguardbackup'); ?></th>
			<td>
				<label>
					<input type="checkbox" name="health_enabled" id="sgbackup-health-enabled" value="1" <?php checked($health_enabled); ?> />
					<?php esc_html_e('Periodically check HTTP health and SSL certificate expiry for all sites', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('Check Interval', 'siteguardbackup'); ?></th>
			<td>
				<select name="health_interval" id="sgbackup-health-interval">
					<option value="everyhour" <?php selected($health_interval, 'everyhour'); ?>><?php esc_html_e('Every hour', 'siteguardbackup'); ?></option>
					<option value="every2hours" <?php selected($health_interval, 'every2hours'); ?>><?php esc_html_e('Every 2 hours', 'siteguardbackup'); ?></option>
					<option value="every4hours" <?php selected($health_interval, 'every4hours'); ?>><?php esc_html_e('Every 4 hours', 'siteguardbackup'); ?></option>
					<option value="every8hours" <?php selected($health_interval, 'every8hours'); ?>><?php esc_html_e('Every 8 hours', 'siteguardbackup'); ?></option>
					<option value="twicedaily" <?php selected($health_interval, 'twicedaily'); ?>><?php esc_html_e('Twice daily', 'siteguardbackup'); ?></option>
					<option value="daily" <?php selected($health_interval, 'daily'); ?>><?php esc_html_e('Daily', 'siteguardbackup'); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('HTTP Timeout', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" name="health_timeout" id="sgbackup-health-timeout" value="<?php echo esc_attr($health_timeout); ?>" min="2" max="30" class="small-text" />
				<?php esc_html_e('seconds', 'siteguardbackup'); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('SSL Warning Threshold', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" name="health_ssl_warn_days" id="sgbackup-health-ssl-warn" value="<?php echo esc_attr($health_ssl_warn); ?>" min="1" max="90" class="small-text" />
				<?php esc_html_e('days before expiry', 'siteguardbackup'); ?>
			</td>
		</tr>
	</table>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('API Access (for Discord Bot)', 'siteguardbackup'); ?></h2>
	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e('API Endpoint', 'siteguardbackup'); ?></th>
			<td>
				<code id="sgbackup-health-endpoint"><?php echo esc_html($rest_url); ?></code>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('API Key', 'siteguardbackup'); ?></th>
			<td>
				<input type="text" id="sgbackup-health-api-key" value="" readonly class="regular-text" style="font-family:monospace;" />
				<button type="button" class="button" id="sgbackup-health-copy-key"><?php esc_html_e('Copy', 'siteguardbackup'); ?></button>
				<button type="button" class="button" id="sgbackup-health-regen-key"><?php esc_html_e('Regenerate', 'siteguardbackup'); ?></button>
				<p class="description"><?php esc_html_e('Use this key in the Discord bot as SG_HEALTH_API_KEY. Send as: Authorization: Bearer {key}', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>
</div>

<div class="sgbackup-section">
	<h2>
		<?php esc_html_e('Current Status', 'siteguardbackup'); ?>
		<button type="button" class="button" id="sgbackup-health-check-now" style="margin-left:10px;"><?php esc_html_e('Check Now', 'siteguardbackup'); ?></button>
	</h2>
	<div id="sgbackup-health-results">
		<p><?php esc_html_e('Loading health status...', 'siteguardbackup'); ?></p>
	</div>
</div>
