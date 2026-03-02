<?php if (!defined('ABSPATH')) die('No direct access.');
$opts = SiteGuard_Backup_Options::instance();
$role = $opts->get_option('sgbackup_failover_role', 'standalone');
$production_url = $opts->get_option('sgbackup_failover_production_url', '');
?>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Environment', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('Auto-detected environment information for this WordPress installation.', 'siteguardbackup'); ?></p>
	<table class="widefat" style="max-width:600px;" id="sgbackup-failover-env-table">
		<tbody>
			<tr>
				<td style="font-weight:600;width:200px;"><?php esc_html_e('Docker', 'siteguardbackup'); ?></td>
				<td><?php echo file_exists('/.dockerenv') ? '<span class="sgbackup-failover-status-ok">Detected</span>' : '<span style="color:#666;">Not detected</span>'; ?></td>
			</tr>
			<tr>
				<td style="font-weight:600;"><?php esc_html_e('Azure', 'siteguardbackup'); ?></td>
				<td><?php echo !empty(getenv('WEBSITE_INSTANCE_ID')) ? '<span class="sgbackup-failover-status-ok">Detected</span>' : '<span style="color:#666;">Not detected</span>'; ?></td>
			</tr>
			<tr>
				<td style="font-weight:600;"><?php esc_html_e('Multisite', 'siteguardbackup'); ?></td>
				<td><?php echo is_multisite() ? '<span class="sgbackup-failover-status-ok">Active</span>' : '<span style="color:#666;">No</span>'; ?></td>
			</tr>
			<tr>
				<td style="font-weight:600;"><?php esc_html_e('Cloudflare Scripts', 'siteguardbackup'); ?></td>
				<td><?php
					$has_scripts = file_exists(dirname(ABSPATH) . '/scripts/cf-status.sh') || file_exists('/opt/docker/wordpressmultisite/scripts/cf-status.sh');
					echo $has_scripts ? '<span class="sgbackup-failover-status-ok">Found</span>' : '<span style="color:#666;">Not found</span>';
				?></td>
			</tr>
		</tbody>
	</table>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Failover Role', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('Set this installation\'s role in the failover topology. "Standalone" disables failover features. "Production" designates this as the primary server. "Standby" designates this as the failover target.', 'siteguardbackup'); ?></p>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e('Role', 'siteguardbackup'); ?></th>
			<td>
				<select name="failover_role" id="sgbackup-failover-role">
					<option value="standalone" <?php selected($role, 'standalone'); ?>><?php esc_html_e('Standalone (disabled)', 'siteguardbackup'); ?></option>
					<option value="production" <?php selected($role, 'production'); ?>><?php esc_html_e('Production', 'siteguardbackup'); ?></option>
					<option value="standby" <?php selected($role, 'standby'); ?>><?php esc_html_e('Standby', 'siteguardbackup'); ?></option>
				</select>
			</td>
		</tr>
		<tr id="sgbackup-failover-production-url-row" style="<?php echo ($role === 'standby') ? '' : 'display:none;'; ?>">
			<th><?php esc_html_e('Production URL', 'siteguardbackup'); ?></th>
			<td>
				<input type="url" name="failover_production_url" id="sgbackup-failover-production-url" class="regular-text" value="<?php echo esc_attr($production_url); ?>" placeholder="https://example.com" />
				<p class="description"><?php esc_html_e('URL of the production site. Used for DNS resolution checks and status monitoring.', 'siteguardbackup'); ?></p>
			</td>
		</tr>
	</table>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Status', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('Live status check of backup freshness, DNS resolution, and system health.', 'siteguardbackup'); ?></p>
	<button type="button" class="button" id="sgbackup-failover-status-btn"><?php esc_html_e('Check Status', 'siteguardbackup'); ?></button>
	<span id="sgbackup-failover-status-spinner" class="sgbackup-status"></span>
	<div id="sgbackup-failover-status-result" style="display:none;margin-top:15px;">
		<table class="widefat" style="max-width:600px;" id="sgbackup-failover-status-table">
			<tbody></tbody>
		</table>
	</div>
</div>

<div class="sgbackup-section">
	<h2><?php esc_html_e('Quick Actions', 'siteguardbackup'); ?></h2>
	<p class="description"><?php esc_html_e('These actions mirror the WP-CLI failover commands. Double confirmation is required for destructive operations.', 'siteguardbackup'); ?></p>

	<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
		<div style="flex:1;min-width:250px;border:1px solid #ccd0d4;padding:15px;border-radius:4px;">
			<h3 style="margin-top:0;"><?php esc_html_e('Pre-Failover Backup', 'siteguardbackup'); ?></h3>
			<p class="description"><?php esc_html_e('Create a quick database-only backup before switching DNS. Equivalent to: wp siteguardbackup prefailover', 'siteguardbackup'); ?></p>
			<button type="button" class="button button-primary" id="sgbackup-failover-prefailover-btn"><?php esc_html_e('Run Pre-Failover Backup', 'siteguardbackup'); ?></button>
			<span id="sgbackup-failover-prefailover-status" class="sgbackup-status"></span>
		</div>

		<div style="flex:1;min-width:250px;border:1px solid #ccd0d4;padding:15px;border-radius:4px;">
			<h3 style="margin-top:0;"><?php esc_html_e('Post-Failover Cleanup', 'siteguardbackup'); ?></h3>
			<p class="description"><?php esc_html_e('Flush all caches, verify database, check theme/plugins. Equivalent to: wp siteguardbackup postfailover', 'siteguardbackup'); ?></p>
			<button type="button" class="button" id="sgbackup-failover-postfailover-btn"><?php esc_html_e('Run Post-Failover Cleanup', 'siteguardbackup'); ?></button>
			<span id="sgbackup-failover-postfailover-status" class="sgbackup-status"></span>
		</div>

		<div style="flex:1;min-width:250px;border:1px solid #ccd0d4;padding:15px;border-radius:4px;">
			<h3 style="margin-top:0;"><?php esc_html_e('Healthcheck', 'siteguardbackup'); ?></h3>
			<p class="description"><?php esc_html_e('Full system health report: DB, disk, backups, locks, PHP. Equivalent to: wp siteguardbackup healthcheck', 'siteguardbackup'); ?></p>
			<button type="button" class="button" id="sgbackup-failover-healthcheck-btn"><?php esc_html_e('Run Healthcheck', 'siteguardbackup'); ?></button>
			<span id="sgbackup-failover-healthcheck-status" class="sgbackup-status"></span>
		</div>
	</div>

	<div id="sgbackup-failover-action-result" style="display:none;margin-top:15px;">
		<pre id="sgbackup-failover-action-output" style="background:#f0f0f1;border:1px solid #ccd0d4;padding:15px;max-height:400px;overflow-y:auto;font-size:12px;white-space:pre-wrap;"></pre>
	</div>
</div>
