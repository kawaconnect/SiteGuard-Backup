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
	 * Migration RSA keys = one identity per install; the resolved backup root is
	 * shared because subsites live in per-site subdirectories beneath it.
	 */
	private static $network_wide_keys = array(
		'sgbackup_migrator_localkeys',
		'sgbackup_resolved_root',
		'sgbackup_legacy_leftovers',
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
	 * Trees the webserver may serve, as absolute paths without trailing slash.
	 *
	 * Both the configured and the resolved form of ABSPATH / WP_CONTENT_DIR / the
	 * uploads dir are included, plus DOCUMENT_ROOT. On Azure App Service the docroot
	 * is switched from /home/site/wwwroot to /var/www/wordpress a few minutes after
	 * boot, so a path that is not served right now may be served after the next
	 * restart; every docroot the install can be reached through counts.
	 */
	private function web_served_trees() {
		$trees = array_merge($this->web_roots(), $this->resolved_forms(array(WP_CONTENT_DIR)));
		if (!empty($_SERVER['DOCUMENT_ROOT'])) {
			$trees = array_merge($trees, $this->resolved_forms(array((string) $_SERVER['DOCUMENT_ROOT'])));
		}
		return array_values(array_unique($trees));
	}

	/**
	 * Every directory that may be the web root: ABSPATH, the parent of wp-content and
	 * the grandparent of the uploads dir, each both as configured and after resolving
	 * symlinks (a symlinked wp-content points back at the persistent tree).
	 */
	private function web_roots() {
		$paths = array(dirname(WP_CONTENT_DIR));
		$content = realpath(WP_CONTENT_DIR);
		if ($content) {
			$paths[] = dirname($content);
		}
		if (defined('ABSPATH')) {
			$paths[] = ABSPATH;
		}
		$uploads = $this->uploads_basedir();
		if ($uploads) {
			$paths[] = dirname(dirname($uploads)); // <webroot>/wp-content/uploads -> <webroot>
			$real_uploads = realpath($uploads);
			if ($real_uploads) {
				$paths[] = dirname(dirname($real_uploads));
			}
		}
		return $this->resolved_forms($paths);
	}

	/**
	 * Normalised configured + realpath() forms of $paths, deduplicated, never "/".
	 */
	private function resolved_forms($paths) {
		$out = array();
		foreach ($paths as $p) {
			foreach (array($p, realpath($p)) as $t) {
				if (!$t) continue;
				$t = untrailingslashit(wp_normalize_path($t));
				if ('' === $t || '/' === $t || '.' === $t) continue;
				$out[$t] = true;
			}
		}
		return array_keys($out);
	}

	/**
	 * Uploads base directory without creating it (wp_upload_dir() would mkdir).
	 */
	private function uploads_basedir() {
		if (!function_exists('wp_upload_dir')) return '';
		$u = wp_upload_dir(null, false);
		return (is_array($u) && !empty($u['basedir'])) ? untrailingslashit($u['basedir']) : '';
	}

	/**
	 * Is $path inside a tree the webserver serves?
	 */
	public function is_web_served_path($path) {
		$candidates = array(untrailingslashit(wp_normalize_path($path)));
		$real = realpath($path);
		if ($real) {
			$candidates[] = untrailingslashit(wp_normalize_path($real));
		}

		foreach ($this->web_served_trees() as $t) {
			foreach ($candidates as $c) {
				if ($c === $t || 0 === strpos($c . '/', $t . '/')) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Candidate roots for backup storage, best (least exposed) first.
	 *
	 * Backups are archives of the whole site and its database; they must not live
	 * where the webserver can serve them. Nothing written into a directory protects
	 * it on nginx, so the only real protection is keeping archives outside the web
	 * root (downloads go through the capability-checked sgbackup_download endpoint).
	 *
	 * Several independent anchors are used so a single mis-derived path (e.g. a
	 * docroot that changes at runtime) does not leave nowhere to go.
	 */
	private function candidate_backup_roots() {
		$roots = array();

		// 1. Explicit host override in wp-config.php:
		//    define('SGBACKUP_BACKUP_DIR', '/home/site/sgbackup');
		//    (SGBACKUP_DIR is the plugin's own install directory, not a destination.)
		if (defined('SGBACKUP_BACKUP_DIR') && SGBACKUP_BACKUP_DIR) {
			$roots[] = (string) SGBACKUP_BACKUP_DIR;
		}

		// 2. One level above each web root the install can be reached through.
		//    /var/www/html/wp-content -> /var/www/sgbackup
		//    Azure App Service: /home/site/wwwroot/wp-content -> /home/site/sgbackup
		foreach ($this->web_roots() as $w) {
			$above = dirname($w);
			if ($above && $above !== $w && '/' !== $above && '.' !== $above) {
				$roots[] = $above . '/sgbackup';
			}
		}

		$clean = array();
		foreach ($roots as $r) {
			$r = untrailingslashit(wp_normalize_path($r));
			if ('' === $r || 0 !== strpos($r, '/') || false !== strpos($r, '../')) continue;
			$clean[$r] = true;
		}
		return array_keys($clean);
	}

	/**
	 * Is $dir usable as a backup root (outside the web root, exists or can be created, writable)?
	 */
	private function is_usable_backup_root($dir) {
		if ($this->is_web_served_path($dir)) return false;
		if (!file_exists($dir) && !wp_mkdir_p($dir)) return false;
		return is_dir($dir) && wp_is_writable($dir);
	}

	/**
	 * Pick a writable backup root that the webserver cannot serve.
	 *
	 * SGBACKUP_BACKUP_DIR always wins. Otherwise the first root found is remembered
	 * network-wide, so a later change in how the web root is derived (Azure's post-boot docroot switch) cannot silently move
	 * backups. The in-webroot legacy path is used only when nothing else works, and
	 * get_backup_dir() then reports it loudly.
	 */
	private function default_backup_root() {
		$explicit   = (defined('SGBACKUP_BACKUP_DIR') && SGBACKUP_BACKUP_DIR);
		$remembered = $this->get_option('sgbackup_resolved_root', '');
		if (!$explicit && is_string($remembered) && '' !== $remembered && $this->is_usable_backup_root($remembered)) {
			return $remembered;
		}

		foreach ($this->candidate_backup_roots() as $candidate) {
			if ($this->is_usable_backup_root($candidate)) {
				if ($candidate !== $remembered) {
					$this->update_option('sgbackup_resolved_root', $candidate);
				}
				return $candidate;
			}
		}

		return untrailingslashit(WP_CONTENT_DIR) . '/sgbackup';
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
		 * Return an absolute path outside the web root to pin the location, e.g. from
		 * an mu-plugin: add_filter('sgbackup_backup_dir', fn() => '/home/site/sgbackup');
		 */
		$dir = untrailingslashit(apply_filters('sgbackup_backup_dir', $dir));

		// Per-site subdirectory for subsites (blog_id > 1)
		if (is_multisite() && get_current_blog_id() > 1) {
			$dir .= '/site-' . get_current_blog_id();
		}

		if (!file_exists($dir)) {
			wp_mkdir_p($dir);
		}

		$exposed = $this->flag_dir_exposure($dir);
		if ($this->is_web_served_path($dir)) {
			// Only meaningful on Apache/IIS; see protect_backup_dir().
			$this->protect_backup_dir($dir);
		} else {
			$this->remove_protection_stubs($dir);
		}
		if (!$exposed) {
			$this->maybe_cleanup_legacy_dirs($dir);
		}

		return trailingslashit($dir);
	}

	/**
	 * Stub files dropped into an in-webroot backup directory, name => exact content.
	 *
	 * These help on Apache (.htaccess) and IIS (web.config), and index.* only answer a
	 * request for the directory itself. On nginx none of them stop a request for a
	 * named archive, which nginx serves straight off disk; there is no in-directory
	 * fix there, so an nginx snippet file is deliberately not shipped.
	 */
	private function protection_stubs() {
		return array(
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'.user.ini'  => "auto_prepend_file = none\nengine = off\n",
			'index.php'  => "<?php\n// Silence is golden.\ndie('Access denied.');\n",
			'index.html' => "<html><body><p>Forbidden</p></body></html>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n",
		);
	}

	/**
	 * Stub files earlier versions wrote that are no longer written anywhere.
	 */
	private function retired_stubs() {
		return array(
			'.nginx-deny' => "# Include this file in your Nginx server block for backup directory protection\n# location ~ /sgbackup { deny all; return 404; }\n",
		);
	}

	/**
	 * Write the Apache/IIS protection stubs into an in-webroot backup directory.
	 */
	private function protect_backup_dir($dir) {
		foreach ($this->protection_stubs() as $name => $content) {
			$path = $dir . '/' . $name;
			if (!file_exists($path)) {
				if (false === @file_put_contents($path, $content)) {
					error_log('SiteGuard Backup: Failed to write ' . $name . ' to ' . $dir);
					continue;
				}
			}
			if ('index.php' !== $name && 'index.html' !== $name) {
				@chmod($path, 0600);
			}
		}
		$this->remove_stub_files($dir, $this->retired_stubs());
	}

	/**
	 * Outside the web root the stubs protect nothing; remove any we wrote earlier.
	 */
	private function remove_protection_stubs($dir) {
		$this->remove_stub_files($dir, array_merge($this->protection_stubs(), $this->retired_stubs()));
	}

	/**
	 * Delete stub files whose content is byte-identical to what this plugin wrote,
	 * so a hand-made file with the same name is never touched.
	 */
	private function remove_stub_files($dir, $stubs) {
		foreach ($stubs as $name => $content) {
			$path = $dir . '/' . $name;
			if (is_file($path) && !is_link($path) && filesize($path) === strlen($content) && @file_get_contents($path) === $content) {
				@unlink($path);
			}
		}
	}

	/**
	 * Remove the stub-only directories older versions left in the web root
	 * (<wp-content>/sgbackup and its site-N children) once backups live elsewhere.
	 *
	 * Only directories that are empty after stub removal are deleted. Anything else
	 * (in particular real archives) is left in place and recorded in
	 * sgbackup_legacy_leftovers so the admin notice can point at it: those files are
	 * publicly downloadable and moving them is the admin's call.
	 */
	private function maybe_cleanup_legacy_dirs($current_dir) {
		if (get_site_transient('sgbackup_legacy_cleanup')) return;
		set_site_transient('sgbackup_legacy_cleanup', 1, HOUR_IN_SECONDS);

		$current = untrailingslashit(wp_normalize_path($current_dir));
		$legacy = array();
		foreach (array(WP_CONTENT_DIR, realpath(WP_CONTENT_DIR)) as $c) {
			if (!$c) continue;
			$legacy[untrailingslashit(wp_normalize_path($c)) . '/sgbackup'] = true;
		}

		$leftovers = array();
		foreach (array_keys($legacy) as $root) {
			if (!is_dir($root) || is_link($root)) continue;
			// Never clean the directory currently in use (e.g. a filter pointing there).
			if ($root === $current || 0 === strpos($current . '/', $root . '/')) continue;

			$subdirs = glob($root . '/site-*', GLOB_ONLYDIR);
			foreach (array_merge(is_array($subdirs) ? $subdirs : array(), array($root)) as $d) {
				if (is_link($d)) continue;
				$this->remove_protection_stubs($d);
				$remaining = array_diff((array) @scandir($d), array('.', '..'));
				if (empty($remaining)) {
					@rmdir($d);
				} else {
					$files = array_filter($remaining, function ($f) use ($d) { return is_file($d . '/' . $f); });
					if (!empty($files)) {
						$leftovers[$d] = count($files);
					}
				}
			}
		}

		if ($leftovers != $this->get_option('sgbackup_legacy_leftovers', array())) {
			$this->update_option('sgbackup_legacy_leftovers', $leftovers);
		}
	}

	/**
	 * Record whether the backup directory is actually reachable by the webserver.
	 *
	 * Stores sgbackup_dir_exposed (1/0) and sgbackup_dir_exposure details so the admin
	 * notice fires on the next admin page load, not only when a backup runs. The
	 * record lives in the database and the audit log because error_log() may go to a
	 * container's stdout and be lost. .user.ini keeps PHP from EXECUTING there, but
	 * static archives (.zip/.gz) are still served: a data-disclosure risk, not RCE.
	 *
	 * @return bool True if the directory is exposed.
	 */
	private function flag_dir_exposure($dir) {
		$server = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower((string) $_SERVER['SERVER_SOFTWARE']) : '';
		if ('' !== $server) {
			if ($server !== get_site_option('sgbackup_server_software', '')) {
				update_site_option('sgbackup_server_software', $server);
			}
		} else {
			// Cron/CLI: judge by the server last seen serving a web request.
			$server = (string) get_site_option('sgbackup_server_software', '');
		}
		$apache_like = (false !== strpos($server, 'apache') || false !== strpos($server, 'litespeed'));
		$exposed     = $this->is_web_served_path($dir) && !$apache_like;

		$previous = (int) $this->get_option('sgbackup_dir_exposed', 0);
		if ($exposed && !$previous) {
			$msg = 'SECURITY - backup directory ' . $dir . ' is inside the web root on a non-Apache server (' . ($server ? $server : 'unknown') . '); archives there may be publicly downloadable. Tried: ' . implode(', ', $this->candidate_backup_roots()) . '. Set define(\'SGBACKUP_BACKUP_DIR\', \'/path/outside/webroot\') in wp-config.php or use the sgbackup_backup_dir filter.';
			error_log('SiteGuard Backup: ' . $msg);
			if (class_exists('SiteGuard_Backup_Audit_Log')) {
				SiteGuard_Backup_Audit_Log::instance()->log('backup_dir_exposed', $msg);
			}
		}
		if ((int) $exposed !== $previous) {
			$this->update_option('sgbackup_dir_exposed', $exposed ? 1 : 0);
		}
		if ($exposed) {
			$info = array(
				'dir'    => $dir,
				'server' => $server,
				'tried'  => $this->candidate_backup_roots(),
			);
			$stored = $this->get_option('sgbackup_dir_exposure', array());
			$unchanged = is_array($stored) && isset($stored['dir'], $stored['server'], $stored['tried'])
				&& $stored['dir'] === $info['dir'] && $stored['server'] === $info['server'] && $stored['tried'] === $info['tried'];
			if (!$unchanged) {
				$info['time'] = time();
				$this->update_option('sgbackup_dir_exposure', $info);
			}
		} elseif ($previous) {
			$this->delete_option('sgbackup_dir_exposure');
		}
		return $exposed;
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
