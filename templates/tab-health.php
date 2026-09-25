<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$health_enabled = $opts->get_option('sgbackup_health_enabled', false);
$health_interval = $opts->get_option('sgbackup_health_interval', 'every10min');
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
			<th scope="row"><?php esc_html_e('Health Check Timeout', 'siteguardbackup'); ?></th>
			<td>
				<input type="number" id="sgbackup-health-timeout" value="<?php echo esc_attr($health_timeout); ?>" min="2" max="60" class="small-text" />
				<?php esc_html_e('seconds (applies to both HTTP and SSL probes)', 'siteguardbackup'); ?>
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
				<div id="sgbackup-health-key-info" style="margin-top:8px;"></div>
			</td>
		</tr>
		<tr id="sgbackup-health-last-restore-row" style="display:none;">
			<th scope="row"><?php esc_html_e('Last Restore', 'siteguardbackup'); ?></th>
			<td>
				<div id="sgbackup-health-last-restore"></div>
				<button type="button" class="button" id="sgbackup-health-adopt-key" style="display:none;margin-top:6px;"></button>
				<p class="description"><?php esc_html_e('Which monitor key the last database restore left in place. Restores keep this site\'s key unless the restore (or Settings → "Default Health Monitor Key on Restore") says to use the backup\'s.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>
</div>

<?php
// ---- Uptime Kuma push (network-level; network administrators only on multisite) ----
if (!is_multisite() || current_user_can('manage_network_options')):
	require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
	require_once SGBACKUP_DIR . 'includes/class-health-kuma.php';
	$kuma = SiteGuard_Backup_Health_Kuma::get_status();
	$kuma_interval = SiteGuard_Backup_Health_Monitor::instance()->get_interval_seconds();
	$kuma_heartbeat = SiteGuard_Backup_Health_Kuma::recommended_heartbeat($kuma_interval);
	$kuma_warnings = $kuma['configured'] ? SiteGuard_Backup_Health_Kuma::cron_warnings($kuma_interval, intval($opts->get_option('sgbackup_health_last_run', 0)), (bool) $health_enabled) : array();
?>
<div class="sgbackup-section" id="sgbackup-kuma-section">
	<h2><?php esc_html_e('Uptime Kuma Push', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('After every health run SiteGuard sends one heartbeat to an Uptime Kuma "Push" monitor: up when every site answers, down when any site is down, has an expired certificate or one expiring within 3 days. Warnings (slow sites, certificates expiring later, SSL probe failures) stay up and are named in the message. If heartbeats stop (PHP, database or WP-Cron dead), Kuma alerts too.', 'siteguardbackup'); ?></p>
	<?php foreach ($kuma_warnings as $kuma_warning): ?>
	<div class="notice notice-warning inline" style="margin:10px 0;"><p><?php echo esc_html($kuma_warning); ?></p></div>
	<?php endforeach; ?>
	<table class="form-table">
		<tr>
			<th scope="row"><label for="sgbackup-kuma-url"><?php esc_html_e('Push URL', 'siteguardbackup'); ?></label></th>
			<td>
				<div id="sgbackup-kuma-current" style="margin-bottom:6px;<?php echo $kuma['configured'] ? '' : 'display:none;'; ?>">
					<?php esc_html_e('Current:', 'siteguardbackup'); ?> <code id="sgbackup-kuma-masked"><?php echo esc_html($kuma['masked']); ?></code>
				</div>
				<input type="text" id="sgbackup-kuma-url" value="" class="regular-text" autocomplete="off" spellcheck="false" placeholder="https://kuma.example.com/api/push/..." />
				<button type="button" class="button button-primary" id="sgbackup-kuma-save"><?php esc_html_e('Save', 'siteguardbackup'); ?></button>
				<button type="button" class="button" id="sgbackup-kuma-test" <?php disabled(!$kuma['configured']); ?>><?php esc_html_e('Send test push', 'siteguardbackup'); ?></button>
				<button type="button" class="button button-link-delete" id="sgbackup-kuma-clear" <?php disabled(!$kuma['configured']); ?>><?php esc_html_e('Remove', 'siteguardbackup'); ?></button>
				<p id="sgbackup-kuma-result" style="margin:6px 0 0;"></p>
				<p class="description"><?php esc_html_e('Paste the Push URL from the Kuma monitor (the "?status=up&msg=OK&ping=" part is dropped). It contains the monitor\'s token, so it is shown masked after saving and never logged.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('Last Push', 'siteguardbackup'); ?></th>
			<td id="sgbackup-kuma-last"><?php
				$kl = $kuma['last'];
				if (!empty($kl['time'])) {
					echo esc_html(sprintf(
						/* translators: 1: OK/FAILED, 2: up/down, 3: HTTP code or error, 4: time ago, 5: message */
						__('%1$s, status=%2$s (%3$s) %4$s ago: %5$s', 'siteguardbackup'),
						!empty($kl['ok']) ? 'OK' : 'FAILED',
						$kl['status'],
						!empty($kl['error']) ? $kl['error'] : 'HTTP ' . intval($kl['code']),
						human_time_diff(intval($kl['time'])),
						$kl['msg']
					));
				} else {
					esc_html_e('None yet.', 'siteguardbackup');
				}
			?></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('Kuma Monitor Settings', 'siteguardbackup'); ?></th>
			<td><p class="description"><?php
				echo esc_html(sprintf(
					/* translators: 1: health check interval in seconds, 2: recommended heartbeat interval in seconds */
					__('Monitor Type "Push"; Heartbeat Interval %2$d seconds or more (health checks run every %1$d seconds, so this allows one late or missed run); Retries 0 or 1; attach your notification.', 'siteguardbackup'),
					$kuma_interval,
					$kuma_heartbeat
				));
			?></p></td>
		</tr>
	</table>
</div>
<?php endif; ?>

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
