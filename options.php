<?php
/**
 * SiteGuard Backup - Options Abstraction
 *
 * Handles option storage differences between single-site and multisite.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Options {

	private static $instance = null;

	/**
	 * Keys that must remain network-wide (shared across all sites).
	 * Migration RSA keys = one identity per install.
	 */
	private static $network_wide_keys = array(
		'sgbackup_migrator_localkeys',
	);

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get an option value.
	 * On multisite: per-site first, falling back to network option for backward compat.
	 */
	public function get_option($key, $default = false) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return get_site_option($key, $default);
			}
			// Per-site first; fall back to network option for backward compat.
			// Use a unique sentinel because WP get_option() returns false for
			// missing options, which is indistinguishable from a stored false.
			$sentinel = '__sgbackup_undefined_' . $key;
			$value = get_option($key, $sentinel);
			if ($sentinel === $value) {
				// The legacy network fallback exists so the MAIN site keeps settings
				// stored before options went per-site. Job/history state must never
				// leak to a subsite: it would append its own backups to the main
				// site's history copy and then prune (and remotely delete) the main
				// site's sets against its own retention.
				if (!is_main_site() && self::is_site_scoped_state($key)) {
					return $default;
				}
				$network = get_site_option($key, $sentinel);
				return ($sentinel !== $network) ? $network : $default;
			}
			return $value;
		}
		return get_option($key, $default);
	}

	/**
	 * Keys holding per-site job/history state (never inherited across sites).
	 */
	private static function is_site_scoped_state($key) {
		if (in_array($key, array('sgbackup_backup_history', 'sgbackup_active_job_nonce', 'sgbackup_restore_sr_state', 'sgbackup_sr_progress', 'sgbackup_migrator_keys_used'), true)) {
			return true;
		}
		foreach (array('sgbackup_jobdata_', 'sgbackup_dbtables_', 'sgbackup_sem_', 'sgbackup_rl_') as $prefix) {
			if (strpos($key, $prefix) === 0) return true;
		}
		return false;
	}

	/**
	 * Update an option value.
	 * On multisite: always writes per-site except network-wide keys.
	 */
	public function update_option($key, $value, $autoload = false) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return update_site_option($key, $value);
			}
			return update_option($key, $value, $autoload);
		}
		return update_option($key, $value, $autoload);
	}

	/**
	 * Delete an option.
	 * On multisite: deletes per-site except network-wide keys.
	 */
	public function delete_option($key) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return delete_site_option($key);
			}
			return delete_option($key);
		}
		return delete_option($key);
	}

	/**
	 * Get a transient.
	 */
	public function get_transient($key) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return get_site_transient($key);
			}
			return get_transient($key);
		}
		return get_transient($key);
	}

	/**
	 * Set a transient.
	 */
	public function set_transient($key, $value, $expiration = 0) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return set_site_transient($key, $value, $expiration);
			}
			return set_transient($key, $value, $expiration);
		}
		return set_transient($key, $value, $expiration);
	}

	/**
	 * Delete a transient.
	 */
	public function delete_transient($key) {
		if (is_multisite()) {
			if (in_array($key, self::$network_wide_keys, true)) {
				return delete_site_transient($key);
			}
			return delete_transient($key);
		}
		return delete_transient($key);
	}

	/**
	 * Is $path inside a tree the webserver serves?
	 *
	 * Checks ABSPATH, the real path of WP_CONTENT_DIR, and its parent (the document
	 * root on most layouts, including Azure App Service where the served docroot
	 * symlinks wp-content back into /home/site/wwwroot).
	 */
	public function is_web_served_path($path) {
		$real = realpath($path);
		if (!$real) {
			$real = $path; // may not exist yet
		}
		$real = untrailingslashit($real);

		$trees = array();
		if (defined('ABSPATH')) {
			$a = realpath(ABSPATH);
			if ($a) {
				$trees[] = untrailingslashit($a);
			}
		}
		$c = realpath(WP_CONTENT_DIR);
		if ($c) {
			$trees[] = untrailingslashit($c);
			$trees[] = untrailingslashit(dirname($c)); // the web root itself
		}

		foreach ($trees as $t) {
			if ('' === $t || '/' === $t) {
				continue;
			}
			if ($real === $t || 0 === strpos($real . '/', $t . '/')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Candidate roots for backup storage, best (least exposed) first.
	 *
	 * Backups are archives of the whole site and its database; they must not live
	 * where the webserver can serve them. .htaccess does nothing on nginx, so
	 * "protected" in-webroot storage is not protection at all.
	 */
	private function candidate_backup_roots() {
		$roots = array();

		// 1. Explicit host override, e.g. define('SGBACKUP_DIR', '/home/site/sgbackup');
		if (defined('SGBACKUP_DIR') && SGBACKUP_DIR) {
			$roots[] = untrailingslashit(SGBACKUP_DIR);
		}

		// 2. One level above the web root. /var/www/html/wp-content -> /var/www/sgbackup
		//    Azure App Service: /home/site/wwwroot/wp-content -> /home/site/sgbackup
		$content = realpath(WP_CONTENT_DIR);
		if ($content) {
			$webroot = dirname($content);
			$above   = dirname($webroot);
			if ($above && $above !== $webroot && '/' !== $above && '.' !== $above) {
				$roots[] = untrailingslashit($above) . '/sgbackup';
			}
		}

		return $roots;
	}

	/**
	 * Pick a writable backup root that the webserver cannot serve.
	 * Falls back to the legacy in-webroot path only as a last resort, and records
	 * that fact so the exposure is visible rather than silent.
	 */
	private function default_backup_root() {
		foreach ($this->candidate_backup_roots() as $candidate) {
			if ($this->is_web_served_path($candidate)) {
				continue;
			}
			if (!file_exists($candidate) && !wp_mkdir_p($candidate)) {
				continue;
			}
			if (is_dir($candidate) && wp_is_writable($candidate)) {
				return $candidate;
			}
		}

		error_log('SiteGuard Backup: no writable directory outside the web root; falling back to ' . WP_CONTENT_DIR . '/sgbackup (archives may be publicly downloadable)');
		return WP_CONTENT_DIR . '/sgbackup';
	}

	/**
	 * Get the backup directory path.
	 */
	public function get_backup_dir() {
		$dir = $this->get_option('sgbackup_dir', '');

		// A custom directory OUTSIDE the WordPress install is the desired case, so it
		// is allowed. Only reject paths that are unusable or obviously unsafe.
		if (!empty($dir)) {
			$dir = untrailingslashit($dir);
			if (0 !== strpos($dir, '/') || false !== strpos($dir, '../')) {
				error_log('SiteGuard Backup: custom backup directory must be an absolute path without traversal; using default');
				$dir = '';
			} elseif (!file_exists($dir) && !wp_mkdir_p($dir)) {
				error_log('SiteGuard Backup: custom backup directory could not be created; using default');
				$dir = '';
			} elseif (!wp_is_writable($dir)) {
				error_log('SiteGuard Backup: custom backup directory is not writable; using default');
				$dir = '';
			}
		}

		if (empty($dir)) {
			$dir = $this->default_backup_root();
		}

		/**
		 * Filter the backup root before the per-site subdirectory is appended.
		 */
		$dir = untrailingslashit(apply_filters('sgbackup_backup_dir', $dir));

		// Per-site subdirectory for subsites (blog_id > 1)
		if (is_multisite() && get_current_blog_id() > 1) {
			$dir .= '/site-' . get_current_blog_id();
		}

		if (!file_exists($dir)) {
			if (wp_mkdir_p($dir)) {
				$this->protect_backup_dir($dir);
			}
		} else {
			$this->protect_backup_dir($dir);
		}

		return trailingslashit($dir);
	}

	/**
	 * Add .htaccess, .user.ini, index.php, index.html, and web.config to protect backup directory.
	 */
	private function protect_backup_dir($dir) {
		// .htaccess
		$htaccess = $dir . '/.htaccess';
		if (!file_exists($htaccess)) {
			$result = file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write .htaccess to ' . $dir);
			}
		}
		@chmod($htaccess, 0600);

		// .user.ini - disable PHP execution (CGI/FastCGI environments)
		$userini = $dir . '/.user.ini';
		if (!file_exists($userini)) {
			$result = file_put_contents($userini, "auto_prepend_file = none\nengine = off\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write .user.ini to ' . $dir);
			}
		}
		@chmod($userini, 0600);

		// index.php - block direct PHP access with die()
		$index_php = $dir . '/index.php';
		if (!file_exists($index_php)) {
			$result = file_put_contents($index_php, "<?php\n// Silence is golden.\ndie('Access denied.');\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write index.php to ' . $dir);
			}
		}

		// index.html
		$index = $dir . '/index.html';
		if (!file_exists($index)) {
			$result = file_put_contents($index, "<html><body><p>Forbidden</p></body></html>\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write index.html to ' . $dir);
			}
		}

		// web.config for IIS
		$webconfig = $dir . '/web.config';
		if (!file_exists($webconfig)) {
			$result = file_put_contents($webconfig, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write web.config to ' . $dir);
			}
		}
		@chmod($webconfig, 0600);

		// .nginx-deny - Nginx protection snippet
		$nginx_deny = $dir . '/.nginx-deny';
		if (!file_exists($nginx_deny)) {
			$result = file_put_contents($nginx_deny, "# Include this file in your Nginx server block for backup directory protection\n# location ~ /sgbackup { deny all; return 404; }\n");
			if (false === $result) {
				error_log('SiteGuard Backup: Failed to write .nginx-deny to ' . $dir);
			}
		}

		// None of the files above actually protect anything on nginx: .htaccess is
		// Apache-only, and nginx has no per-directory config, so .nginx-deny is inert
		// unless an admin includes it in the server block by hand. If the directory is
		// web-served on such a server, every archive is publicly downloadable. Record
		// it loudly instead of failing silently.
		$this->flag_dir_exposure($dir);
	}

	/**
	 * Record whether the backup directory is actually reachable by the webserver.
	 *
	 * Stores sgbackup_dir_exposed (1/0) so the admin UI can surface it, and logs on
	 * transition. .user.ini keeps PHP from EXECUTING there, but static archives
	 * (.zip/.gz) are still served, so this is a data-disclosure risk, not RCE.
	 */
	private function flag_dir_exposure($dir) {
		$server      = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
		$apache_like = (false !== strpos($server, 'apache') || false !== strpos($server, 'litespeed'));
		$exposed     = $this->is_web_served_path($dir) && !$apache_like;

		$previous = (int) $this->get_option('sgbackup_dir_exposed', 0);
		if ($exposed && !$previous) {
			error_log('SiteGuard Backup: SECURITY - backup directory ' . $dir . ' is inside the web root on a non-Apache server (' . ($server ? $server : 'unknown') . '); .htaccess is ignored there and archives may be publicly downloadable. Move backups outside the web root, e.g. define(\'SGBACKUP_DIR\', \'/path/outside/webroot\').');
		}
		if ((int) $exposed !== $previous) {
			$this->update_option('sgbackup_dir_exposed', $exposed ? 1 : 0);
		}
	}

	/**
	 * Get all SiteGuard Backup options as an array.
	 */
	public function get_all_settings() {
		$defaults = $this->get_defaults();
		$settings = array();
		foreach ($defaults as $key => $default) {
			$settings[$key] = $this->get_option('sgbackup_' . $key, $default);
		}
		return $settings;
	}

	/**
	 * Get default option values.
	 */
	public function get_defaults() {
		return array(
			'interval'           => 'manual',
			'interval_database'  => 'manual',
			'interval_incremental' => 'none',
			'retain'             => 2,
			'retain_db'          => 2,
			'retain_extrarules'  => array(),
			'include_entities'   => array('plugins', 'themes', 'uploads', 'others'),
			'include_tables'     => 'all',
			'exclude_tables'     => array(),
			'exclude_files'      => array(),
			'split_every'        => 400,
			'max_split'          => 0,
			'email'              => '',
			'service'            => array('none'),
			'encryption_key'     => '',
			'encryption_enabled' => false,
			'email_recipients'   => array(),
			'email_mode'         => 'always',
			'syslog_enabled'     => false,
			'schedule_time'      => '',
			'schedule_day'       => '',
			'schedule_day_db'    => '',
			'autobackup_default' => true,
			'delete_local'       => 0,
			'debug_mode'         => false,
			'lock_admin'         => 0,
			'lock_admin_password' => '',
			'lock_admin_session_length' => 3600,
			'lock_admin_support_url' => '',
			'extradbs'           => array(),
			'backupdb_nonwp'     => false,
			'db_anonymise'       => false,
			'db_anonymise_mode'  => 'all',
			'extra_dirs'         => array(),
			'max_incrementals_before_full' => 10,
			'ssl_disableverify'  => false,
			'ssl_useservercerts' => false,
			'auto_update'        => false,
			'data_optional_tables' => '',
			'zip_nocompress'     => '',
			'webhook_url'        => '',
			'webhook_events'     => array(),
			'ip_allowlist'       => '',
			'encrypt_credentials' => false,
			'failover_enabled'   => false,
			'failover_role'      => 'standalone',
			'failover_production_url' => '',
			'default_db_restore_mode' => 0,
			'default_subsite_mode' => 0,
			'default_keep_protocol' => 0,
			'low_memory_mode'    => 'auto',
		);
	}

	/**
	 * Get a sensitive option, decrypting if encrypted.
	 *
	 * @param string $key     Option key (without sgbackup_ prefix).
	 * @param mixed  $default Default value if not found.
	 * @return mixed Decrypted value or default.
	 */
	public function get_sensitive_option($key, $default = false) {
		$value = $this->get_option('sgbackup_' . $key, $default);
		if (is_string($value)) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			return SiteGuard_Backup_Security::decrypt_option($value);
		}
		return $value;
	}

	/**
	 * Update a sensitive option, encrypting before storage if credential encryption is enabled.
	 *
	 * @param string $key   Option key (without sgbackup_ prefix).
	 * @param mixed  $value Value to store.
	 * @return bool
	 */
	public function update_sensitive_option($key, $value) {
		if (is_string($value) && !empty($value) && $this->get_option('sgbackup_encrypt_credentials', false)) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			$value = SiteGuard_Backup_Security::encrypt_option($value);
		}
		return $this->update_option('sgbackup_' . $key, $value);
	}
}
