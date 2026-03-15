<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$health_enabled = $opts->get_option('sgbackup_health_enabled', false);
$health_interval = $opts->get_option('sgbackup_health_interval', 'every4hours');
$health_timeout = intval($opts->get_option('sgbackup_health_timeout', 10));
$health_ssl_warn = intval($opts->get_option('sgbackup_health_ssl_warn_days', 14));
$rest_url = rest_url('sgbackup/v1/health');
$next_check = wp_next_scheduled('sgbackup_health_check');
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
					<input type="checkbox" id="sgbackup-health-enabled" value="1" <?php checked($health_enabled); ?> />
					<?php esc_html_e('Periodically check HTTP health and SSL certificate expiry for all sites', 'siteguardbackup'); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('Check Interval', 'siteguardbackup'); ?></th>
			<td>
				<select id="sgbackup-health-interval">
					<option value="every5min" <?php selected($health_interval, 'every5min'); ?>><?php esc_html_e('Every 5 minutes', 'siteguardbackup'); ?></option>
					<option value="every10min" <?php selected($health_interval, 'every10min'); ?>><?php esc_html_e('Every 10 minutes', 'siteguardbackup'); ?></option>
					<option value="every15min" <?php selected($health_interval, 'every15min'); ?>><?php esc_html_e('Every 15 minutes', 'siteguardbackup'); ?></option>
					<option value="every30min" <?php selected($health_interval, 'every30min'); ?>><?php esc_html_e('Every 30 minutes', 'siteguardbackup'); ?></option>
					<option value="everyhour" <?php selected($health_interval, 'everyhour'); ?>><?php esc_html_e('Every hour', 'siteguardbackup'); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('HTTP Timeout', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" id="sgbackup-health-timeout" value="<?php echo esc_attr($health_timeout); ?>" min="2" max="30" class="small-text" />
				<?php esc_html_e('seconds', 'siteguardbackup'); ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('SSL Warning Threshold', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" id="sgbackup-health-ssl-warn" value="<?php echo esc_attr($health_ssl_warn); ?>" min="1" max="90" class="small-text" />
				<?php esc_html_e('days before expiry', 'siteguardbackup'); ?>
			</td>
		</tr>
	</table>
	<p>
		<button type="button" class="button button-primary" id="sgbackup-health-save"><?php esc_html_e('Save Health Settings', 'siteguardbackup'); ?></button>
		<span id="sgbackup-health-save-status" style="margin-left:10px;"></span>
	</p>
	<?php if ($health_enabled && $next_check): ?>
	<p class="description"><?php printf(esc_html__('Next scheduled check: %s', 'siteguardbackup'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_check)); ?></p>
	<?php endif; ?>
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
				<div id="sgbackup-health-key-status"></div>
				<input type="text" id="sgbackup-health-api-key" value="" readonly class="regular-text" style="font-family:monospace;display:none;" />
				<button type="button" class="button" id="sgbackup-health-copy-key" style="display:none;"><?php esc_html_e('Copy', 'siteguardbackup'); ?></button>
				<button type="button" class="button button-secondary" id="sgbackup-health-regen-key"><?php esc_html_e('Generate New Key', 'siteguardbackup'); ?></button>
				<p class="description"><?php esc_html_e('The API key is shown only once when generated. Copy it immediately. It is stored hashed and cannot be retrieved later.', 'siteguardbackup'); ?></p>
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
		<p><?php esc_html_e('Click a button or switch to this tab to see activity in the log below.', 'siteguardbackup'); ?></p>
	</div>
</div>

<div class="sgbackup-section">
	<h2>
		<?php esc_html_e('Health Monitor Log', 'siteguardbackup'); ?>
		<button type="button" class="button button-small" id="sgbackup-health-log-clear" style="margin-left:8px;"><?php esc_html_e('Clear', 'siteguardbackup'); ?></button>
	</h2>
	<pre id="sgbackup-health-log-content" style="max-height:400px;overflow-y:auto;background:#1d2327;color:#c3c4c7;padding:12px;font-family:monospace;font-size:11px;border-radius:3px;word-break:break-all;line-height:1.6;white-space:pre-wrap;margin:0;"></pre>
</div>
