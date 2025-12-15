<?php
/**
 * SiteGuard Backup - Options Abstraction
 *
 * Handles option storage differences between single-site and multisite.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Options {

	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get an option value.
	 */
	public function get_option($key, $default = false) {
		if (is_multisite()) {
			return get_site_option($key, $default);
		}
		return get_option($key, $default);
	}

	/**
	 * Update an option value.
	 */
	public function update_option($key, $value, $use_cache = true) {
		if (is_multisite()) {
			return update_site_option($key, $value);
		}
		return update_option($key, $value, $use_cache ? 'yes' : 'no');
	}

	/**
	 * Delete an option.
	 */
	public function delete_option($key) {
		if (is_multisite()) {
			return delete_site_option($key);
		}
		return delete_option($key);
	}

	/**
	 * Get a transient.
	 */
	public function get_transient($key) {
		if (is_multisite()) {
			return get_site_transient($key);
		}
		return get_transient($key);
	}

	/**
	 * Set a transient.
	 */
	public function set_transient($key, $value, $expiration = 0) {
		if (is_multisite()) {
			return set_site_transient($key, $value, $expiration);
		}
		return set_transient($key, $value, $expiration);
	}

	/**
	 * Delete a transient.
	 */
	public function delete_transient($key) {
		if (is_multisite()) {
			return delete_site_transient($key);
		}
		return delete_transient($key);
	}

	/**
	 * Get the backup directory path.
	 */
	public function get_backup_dir() {
		$dir = $this->get_option('sgbackup_dir', '');
		if (empty($dir)) {
			$dir = WP_CONTENT_DIR . '/sgbackup';
		}

		if (!file_exists($dir)) {
			$result = wp_mkdir_p($dir);
			if ($result) {
				// Protect directory
				$this->protect_backup_dir($dir);
			}
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
			@file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
		}
		@chmod($htaccess, 0600);

		// .user.ini - disable PHP execution (CGI/FastCGI environments)
		$userini = $dir . '/.user.ini';
		if (!file_exists($userini)) {
			@file_put_contents($userini, "auto_prepend_file = none\nengine = off\n");
		}
		@chmod($userini, 0600);

		// index.php - block direct PHP access with die()
		$index_php = $dir . '/index.php';
		if (!file_exists($index_php)) {
			@file_put_contents($index_php, "<?php\n// Silence is golden.\ndie('Access denied.');\n");
		}

		// index.html
		$index = $dir . '/index.html';
		if (!file_exists($index)) {
			@file_put_contents($index, "<html><body><p>Forbidden</p></body></html>\n");
		}

		// web.config for IIS
		$webconfig = $dir . '/web.config';
		if (!file_exists($webconfig)) {
			@file_put_contents($webconfig, "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<configuration>\n<system.webServer>\n<authorization>\n<deny users=\"*\" />\n</authorization>\n</system.webServer>\n</configuration>\n");
		}
		@chmod($webconfig, 0600);
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
		);
	}
}
