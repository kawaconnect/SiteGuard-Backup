<?php
/**
 * SiteGuard Backup - Admin UI
 *
 * Registers admin menu, enqueues scripts, handles AJAX requests.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Admin {

	private static $instance = null;

	/** @var SiteGuard_Backup_Commands */
	private $commands;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
	}

	private function register_hooks() {
		add_action('admin_menu', array($this, 'admin_menu'));
		add_action('network_admin_menu', array($this, 'admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('wp_ajax_sgbackup_ajax', array($this, 'ajax_handler'));
		add_action('wp_ajax_sgbackup_download', array($this, 'ajax_download'));
		add_action('wp_ajax_sgbackup_download_bundle', array($this, 'ajax_download_bundle'));
		add_action('wp_ajax_sgbackup_restore', array($this, 'ajax_restore'));
		add_action('wp_ajax_sgbackup_upload', array($this, 'ajax_upload'));
		add_action('wp_ajax_sgbackup_oauth_callback', array($this, 'ajax_oauth_callback'));
		add_action('wp_ajax_sgbackup_unlock', array($this, 'ajax_unlock'));
		add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 100);
		add_action('admin_head', array($this, 'admin_bar_css'));
		add_action('admin_head', array($this, 'maybe_send_csp_headers'));
		add_action('wp_head', array($this, 'admin_bar_css'));
		add_action('wp_dashboard_setup', array($this, 'dashboard_widget_setup'));
		add_action('admin_notices', array($this, 'backup_dir_notice'));
		add_action('network_admin_notices', array($this, 'backup_dir_notice'));
		if (is_multisite()) {
			add_action('wp_network_dashboard_setup', array($this, 'dashboard_widget_setup'));
		}
	}

	/**
	 * Register admin menu page.
	 */
	public function admin_menu() {
		$cap = is_network_admin() ? 'manage_network_options' : 'manage_options';

		add_menu_page(
			'SiteGuard Backup',
			'SiteGuard Backup',
			$cap,
			'siteguardbackup',
			array($this, 'render_page'),
			'dashicons-shield',
			76
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 */
	public function enqueue_scripts($hook) {
		if (strpos($hook, 'siteguardbackup') === false) return;

		wp_enqueue_style(
			'sgbackup-admin',
			SGBACKUP_URL . 'css/admin.css',
			array(),
			SGBACKUP_VERSION
		);

		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_style('wp-jquery-ui-dialog');

		wp_enqueue_script(
			'sgbackup-admin',
			SGBACKUP_URL . 'js/admin.js',
			array('jquery', 'jquery-ui-dialog'),
			SGBACKUP_VERSION,
			true
		);

		wp_enqueue_script(
			'sgbackup-restore',
			SGBACKUP_URL . 'js/restore.js',
			array('jquery'),
			SGBACKUP_VERSION,
			true
		);

		// Upload support
		wp_enqueue_script('plupload-all');

		$sgbackup = SiteGuard_Backup::instance();

		// Use path-only AJAX URL so the browser sends the request to the same
		// origin as the current page. This prevents cross-origin failures when
		// DOMAIN_CURRENT_SITE (wp-config) doesn't match the DB siteurl - e.g.,
		// after restoring a backup from a different domain.
		$ajax_path = parse_url(admin_url('admin-ajax.php'), PHP_URL_PATH);
		wp_localize_script('sgbackup-admin', 'sgbackup_settings', array(
			'ajax_url'  => $ajax_path ? $ajax_path : admin_url('admin-ajax.php'),
			'nonce'     => wp_create_nonce('sgbackup-ajax'),
			'nonces'    => array(
				'backupnow'         => wp_create_nonce('sgbackup-backupnow'),
				'restore'           => wp_create_nonce('sgbackup-restore'),
				'delete_backup'     => wp_create_nonce('sgbackup-delete_backup'),
				'savesettings'      => wp_create_nonce('sgbackup-savesettings'),
				'wipe_settings'     => wp_create_nonce('sgbackup-wipe_settings'),
				'upload'            => wp_create_nonce('sgbackup-upload'),
				'download'          => wp_create_nonce('sgbackup-download'),
				'unlock'            => wp_create_nonce('sgbackup-unlock'),
				'upload_to_remote'  => wp_create_nonce('sgbackup-upload_to_remote'),
				'import_settings'   => wp_create_nonce('sgbackup-import_settings'),
				'export_settings'   => wp_create_nonce('sgbackup-export_settings'),
				'migrate_send'      => wp_create_nonce('sgbackup-migrate_send'),
				'searchreplace'     => wp_create_nonce('sgbackup-searchreplace'),
				'test_webhook'      => wp_create_nonce('sgbackup-test_webhook'),
				'health_kuma'       => wp_create_nonce('sgbackup-health_kuma'),
				'failover_status'   => wp_create_nonce('sgbackup-failover_status'),
				'failover_prefailover'  => wp_create_nonce('sgbackup-failover_prefailover'),
				'failover_postfailover' => wp_create_nonce('sgbackup-failover_postfailover'),
				'save_restore_preset'   => wp_create_nonce('sgbackup-save_restore_preset'),
				'get_restore_presets'   => wp_create_nonce('sgbackup-get_restore_presets'),
				'delete_restore_preset' => wp_create_nonce('sgbackup-delete_restore_preset'),
				'save_remotesend_schedule' => wp_create_nonce('sgbackup-save_remotesend_schedule'),
				'get_remotesend_schedule'  => wp_create_nonce('sgbackup-get_remotesend_schedule'),
				'upload_chunk'             => wp_create_nonce('sgbackup-upload_chunk'),
				'upload_chunk_status'      => wp_create_nonce('sgbackup-upload_chunk_status'),
				'upload_chunk_cancel'      => wp_create_nonce('sgbackup-upload_chunk_cancel'),
				'health_check_now'         => wp_create_nonce('sgbackup-health_check_now'),
				'get_health_status'        => wp_create_nonce('sgbackup-get_health_status'),
				'regenerate_health_api_key' => wp_create_nonce('sgbackup-regenerate_health_api_key'),
				'health_adopt_restored_key' => wp_create_nonce('sgbackup-health_adopt_restored_key'),
			),
			'max_upload_size' => SiteGuard_Backup_Filesystem::max_backup_upload_size(),
			'chunk_size' => min(2 * 1024 * 1024, intval(0.9 * SiteGuard_Backup_Filesystem::max_backup_upload_size())),
			'is_multisite' => is_multisite(),
			'current_blog_id' => get_current_blog_id(),
			'is_single_site_mode' => function_exists('sgbackup_is_single_site_mode') ? sgbackup_is_single_site_mode() : false,
			'default_db_restore_mode' => intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_db_restore_mode', 0)),
			'default_subsite_mode' => intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_subsite_mode', 0)),
			'default_keep_protocol' => intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_keep_protocol', 0)),
			'default_health_key_policy' => ('backup' === SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_health_key_policy', 'keep')) ? 'backup' : 'keep',
			'configured_methods' => $this->get_configured_methods(),
			'strings'   => array(
				'backup_started'   => __('Backup started...', 'siteguardbackup'),
				'backup_complete'  => __('Backup complete!', 'siteguardbackup'),
				'restore_started'  => __('Restore started...', 'siteguardbackup'),
				'restore_complete' => __('Restore complete!', 'siteguardbackup'),
				'confirm_delete'   => __('Are you sure you want to delete this backup?', 'siteguardbackup'),
				'confirm_restore'  => __('Are you sure you want to restore this backup? This will overwrite existing data.', 'siteguardbackup'),
				'error'            => __('An error occurred.', 'siteguardbackup'),
				'downloading'      => __('Downloading...', 'siteguardbackup'),
				'uploading'        => __('Uploading...', 'siteguardbackup'),
				'processing'       => __('Processing...', 'siteguardbackup'),
			),
		));
	}

	/**
	 * Render the main admin page.
	 */
	public function render_page() {
		if (!$this->user_can_manage()) {
			wp_die(__('You do not have permission to access this page.', 'siteguardbackup'));
		}

		// H5: Check settings password protection
		if ($this->is_settings_locked()) {
			include SGBACKUP_DIR . 'templates/lock-screen.php';
			return;
		}

		include SGBACKUP_DIR . 'templates/settings-page.php';
	}

	/**
	 * H5: Check if settings page should show the lock screen.
	 *
	 * @return bool True if locked, false if unlocked or lock not enabled.
	 */
	private function is_settings_locked() {
		$opts = SiteGuard_Backup_Options::instance();
		$lock_admin = $opts->get_option('sgbackup_lock_admin', 0);
		$stored_hash = $opts->get_option('sgbackup_lock_admin_password', '');

		if (!$lock_admin || empty($stored_hash)) {
			return false;
		}

		// Check for valid session cookie
		$cookie_name = 'sgbackup_unlock';
		if (empty($_COOKIE[$cookie_name])) {
			return true;
		}

		$cookie_value = sanitize_text_field($_COOKIE[$cookie_name]);
		$parts = explode(':', $cookie_value, 3);
		if (count($parts) !== 3) {
			return true;
		}

		list($user_id, $timestamp, $hash) = $parts;
		$user_id = intval($user_id);
		$timestamp = intval($timestamp);

		// Check expiration
		$session_length = intval($opts->get_option('sgbackup_lock_admin_session_length', 3600));
		if (time() > ($timestamp + $session_length)) {
			return true;
		}

		// Verify the user is the current user
		if ($user_id !== get_current_user_id()) {
			return true;
		}

		// Verify hash
		$expected_hash = hash_hmac('sha256', $user_id . ':' . $timestamp, $stored_hash . wp_salt());
		if (!hash_equals($expected_hash, $hash)) {
			return true;
		}

		return false;
	}

	/**
	 * H5: AJAX handler for unlocking settings with password.
	 */
	public function ajax_unlock() {
		if (!$this->user_can_manage()) {
			wp_send_json_error(array('message' => 'Unauthorized'));
		}

		// Verify nonce (accept both per-action and generic)
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		if (!wp_verify_nonce($nonce, 'sgbackup-unlock') && !wp_verify_nonce($nonce, 'sgbackup-ajax')) {
			wp_send_json_error(array('message' => 'Invalid nonce'));
		}

		// Rate limiting: max 5 attempts per minute per user
		$user_id = get_current_user_id();
		$transient_key = 'sgbackup_unlock_attempts_' . $user_id;
		$attempts = (int) get_transient($transient_key);
		if ($attempts >= 5) {
			wp_send_json_error(array('message' => 'Too many unlock attempts. Please wait a minute.'));
		}
		set_transient($transient_key, $attempts + 1, 60);

		$password = isset($_POST['password']) ? $_POST['password'] : '';
		if (empty($password)) {
			wp_send_json_error(array('message' => 'Password is required'));
		}

		$opts = SiteGuard_Backup_Options::instance();
		$stored_hash = $opts->get_option('sgbackup_lock_admin_password', '');
		if (empty($stored_hash)) {
			wp_send_json_error(array('message' => 'No password configured'));
		}

		// Verify password using phpass
		if (!function_exists('wp_check_password')) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}
		if (!wp_check_password($password, $stored_hash)) {
			// Audit log failed attempt
			$audit = SiteGuard_Backup_Audit_Log::instance();
			$audit->log('unlock_failed', 'Failed unlock attempt (incorrect password).');
			wp_send_json_error(array('message' => 'Incorrect password'));
		}

		// Generate session cookie
		$user_id = get_current_user_id();
		$timestamp = time();
		$hash = hash_hmac('sha256', $user_id . ':' . $timestamp, $stored_hash . wp_salt());
		$cookie_value = $user_id . ':' . $timestamp . ':' . $hash;

		$session_length = intval($opts->get_option('sgbackup_lock_admin_session_length', 3600));

		setcookie('sgbackup_unlock', $cookie_value, time() + $session_length, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, is_ssl(), true);

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('unlock_success', 'Settings unlocked successfully.');

		wp_send_json_success(array('message' => 'Unlocked'));
	}

	/**
	 * Main AJAX handler - dispatches to command methods.
	 */
	public function ajax_handler() {
		if (!$this->user_can_manage()) {
			wp_send_json_error(array('message' => 'Unauthorized'));
		}

		$subaction = isset($_POST['subaction']) ? sanitize_text_field($_POST['subaction']) : '';
		if (empty($subaction)) {
			wp_send_json_error(array('message' => 'Missing subaction'));
		}

		// IP allowlist enforcement
		require_once SGBACKUP_DIR . 'includes/class-security.php';
		if (!SiteGuard_Backup_Security::check_ip_allowlist()) {
			wp_send_json_error(array('message' => 'Access denied: your IP is not in the allowlist.'));
		}

		// Per-action nonce verification: try action-specific nonce first, fall back to generic
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		$action_nonce_valid = wp_verify_nonce($nonce, 'sgbackup-' . $subaction);
		$generic_nonce_valid = wp_verify_nonce($nonce, 'sgbackup-ajax');
		if (!$action_nonce_valid && !$generic_nonce_valid) {
			wp_send_json_error(array('message' => 'Invalid security token. Please reload the page.'));
		}

		// On multisite these reach beyond the current subsite (network-wide tables,
		// options, migration receive keys, audit trail, arbitrary DB hosts), so a
		// subsite administrator must not run them; network administrators can.
		// Subsite admins restore their own site through ajax_restore, which scopes
		// the restore to their blog (restrict_restore_scope()).
		$network_admin_only = array(
			'restore_alldownloaded', 'searchreplace', 'wipe_settings', 'import_settings', 'health_kuma',
			'migrate_key_create', 'get_migrator_keys', 'delete_migrator_key',
			'failover_prefailover', 'failover_postfailover', 'clear_audit_log', 'toggle_audit_log',
			'regenerate_health_api_key', 'health_adopt_restored_key', 'test_extdb',
		);
		if (is_multisite() && !current_user_can('manage_network_options') && in_array($subaction, $network_admin_only, true)) {
			wp_send_json_error(array('message' => __('Only a network administrator can do this.', 'siteguardbackup')));
		}

		// Rate limiting on sensitive destructive/mutating endpoints
		$rate_limits = array(
			'wipe_settings'          => array('limit' => 2,  'window' => 60),   // 2 per minute
			'searchreplace'          => array('limit' => 5,  'window' => 60),   // 5 per minute
			'health_kuma'            => array('limit' => 10, 'window' => 60),   // 10 per minute (test pushes)
			'delete_backup'          => array('limit' => 15, 'window' => 60),   // 15 per minute
			'migrate_send'           => array('limit' => 5,  'window' => 60),   // 5 per minute
			'import_settings'        => array('limit' => 3,  'window' => 60),   // 3 per minute
			'clear_audit_log'        => array('limit' => 2,  'window' => 60),   // 2 per minute
			'backupnow'              => array('limit' => 10, 'window' => 60),   // 10 per minute
			'restore_alldownloaded'  => array('limit' => 3,  'window' => 60),   // 3 per minute
			'failover_prefailover'   => array('limit' => 3,  'window' => 60),   // 3 per minute
			'failover_postfailover'  => array('limit' => 3,  'window' => 60),   // 3 per minute
			'delete_restore_preset'  => array('limit' => 10, 'window' => 60),   // 10 per minute
			'save_remotesend_schedule' => array('limit' => 5, 'window' => 60),  // 5 per minute
			'health_check_now'         => array('limit' => 3, 'window' => 60),  // 3 per minute
			'health_adopt_restored_key' => array('limit' => 5, 'window' => 60), // 5 per minute
		);
		if (isset($rate_limits[$subaction])) {
			$rl = $rate_limits[$subaction];
			$user_id = get_current_user_id();
			$transient_key = 'sgbackup_rl_' . $subaction . '_' . $user_id;
			$attempts = (int) get_transient($transient_key);
			if ($attempts >= $rl['limit']) {
				// Audit log rate limit hits
				$audit = SiteGuard_Backup_Audit_Log::instance();
				$audit->log('rate_limit_hit', 'Rate limit exceeded for action: ' . $subaction);
				wp_send_json_error(array('message' => 'Too many requests. Please wait before trying again.'));
			}
			set_transient($transient_key, $attempts + 1, $rl['window']);
		}

		// Enforce settings lock on all mutating commands except activejobs_list (read-only status check)
		$read_only_commands = array('activejobs_list', 'get_existing_backups', 'get_log', 'get_settings', 'get_sysinfo', 'get_remotesites', 'get_migrator_keys', 'get_backup_contents', 'get_database_tables', 'get_audit_log', 'failover_status', 'get_restore_presets', 'get_remotesend_schedule', 'upload_chunk_status', 'get_health_status');
		if (!in_array($subaction, $read_only_commands, true) && $this->is_settings_locked()) {
			wp_send_json_error(array('message' => 'Settings are locked. Please unlock first.'));
		}

		if (null === $this->commands) {
			require_once SGBACKUP_DIR . 'includes/class-commands.php';
			$this->commands = new SiteGuard_Backup_Commands();
		}

		$allowed_commands = array(
			'backupnow',
			'activejobs_list',
			'delete_backup',
			'get_log',
			'get_existing_backups',
			'activejobs_delete',
			'always_keep_this_backup',
			'savesettings',
			'get_settings',
			'restore_alldownloaded',
			'migrate_key_create',
			'migrate_newdestination',
			'ping_test',
			'migrate_send',
			'get_sysinfo',
			'download_file',
			'get_remotesites',
			'delete_remotesite',
			'get_migrator_keys',
			'delete_migrator_key',
			'searchreplace',
			'wipe_settings',
			'credentials_test',
			'get_auth_url',
			'clone_to_site',
			'get_backup_contents',
			'verify_backup',
			'resume_restore',
			'get_database_tables',
			'test_extdb',
			'import_foreign_backup',
			'upload_to_remote',
			'rescan_local',
			'rescan_remote',
			'get_site_size',
			'get_db_size',
			'get_cron_events',
			'export_settings',
			'import_settings',
			'get_audit_log',
			'toggle_audit_log',
			'clear_audit_log',
			'test_webhook',
			'health_kuma',
			'failover_status',
			'failover_prefailover',
			'failover_postfailover',
			'save_restore_preset',
			'get_restore_presets',
			'delete_restore_preset',
			'save_remotesend_schedule',
			'get_remotesend_schedule',
			'upload_chunk',
			'upload_chunk_status',
			'upload_chunk_cancel',
			'health_check_now',
			'get_health_status',
			'regenerate_health_api_key',
			'health_adopt_restored_key',
		);

		if (!in_array($subaction, $allowed_commands, true)) {
			wp_send_json_error(array('message' => 'Unknown command: ' . $subaction));
		}

		$params = isset($_POST['data']) ? $_POST['data'] : array();
		if (is_string($params)) {
			$decoded = json_decode(stripslashes($params), true);
			if (is_array($decoded)) $params = $decoded;
		}

		// Unslash all string values; per-field sanitization is handled by each command handler.
		// Do NOT apply sanitize_text_field() here - it strips newlines needed by SSH keys,
		// file exclusion patterns, and other multiline fields.
		if (is_array($params)) {
			array_walk_recursive($params, function(&$val) {
				if (is_string($val)) {
					$val = wp_unslash($val);
				}
			});
		}

		$result = $this->commands->$subaction($params);

		if (is_wp_error($result)) {
			$error_response = array(
				'message'    => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
			);
			$error_data = $result->get_error_data();
			if (!empty($error_data)) {
				$error_response['error_data'] = $error_data;
			}
			wp_send_json_error($error_response);
		}

		wp_send_json_success($result);
	}

	/**
	 * Handle backup file download.
	 */
	public function ajax_download() {
		if (!$this->verify_ajax('GET', 'sgbackup-download')) wp_die();

		$file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';
		if (empty($file)) wp_die('Missing file parameter');

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$path = $backup_dir . $file;
		clearstatcache(true, $path);

		if (!file_exists($path)) wp_die('File not found');

		// Verify the file is in the backup directory (prevent directory traversal)
		$real_path = realpath($path);
		$real_dir = realpath($backup_dir);
		// Compare against the directory WITH a trailing separator so /…/sgbackupEVIL
		// is not treated as a child of /…/sgbackup.
		$real_dir_sep = $real_dir ? rtrim($real_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
		if (!$real_path || !$real_dir || strpos($real_path, $real_dir_sep) !== 0) wp_die('Invalid file path');

		// Only allow downloading actual backup files (not .htaccess, index.php, etc.)
		if (!preg_match('/\.(zip|gz|sql|crypt|json|txt)$/', $file)) wp_die('Invalid file type');

		// A file belonging to a network-wide backup is for network administrators only.
		foreach (SiteGuard_Backup_History::get_history() as $entry) {
			if (in_array($file, SiteGuard_Backup_History::get_all_files($entry), true) && !$this->may_download_backup($entry)) {
				wp_die(esc_html__('Only a network administrator can download a network-wide backup.', 'siteguardbackup'), 403);
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('download_backup', 'Downloaded backup file: ' . $file, array(
			'file' => $file,
			'size' => filesize($path),
		));

		// Clear output buffers for streaming
		@set_time_limit(0);
		while (ob_get_level()) ob_end_clean();

		$safe_filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($file));
		$encoded_filename = rawurlencode($safe_filename);
		header('Content-Type: application/octet-stream');
		header("Content-Disposition: attachment; filename=\"{$safe_filename}\"; filename*=UTF-8''{$encoded_filename}");
		header('Content-Length: ' . filesize($path));
		header('Cache-Control: no-cache, must-revalidate');

		// Chunked output to avoid memory issues with large files
		$fh = fopen($path, 'rb');
		if ($fh) {
			while (!feof($fh)) {
				echo fread($fh, 65536);
				flush();
			}
			fclose($fh);
		}
		exit;
	}

	/**
	 * Download all backup files for a timestamp as a single zip bundle.
	 */
	public function ajax_download_bundle() {
		if (!$this->verify_ajax('GET', 'sgbackup-download')) wp_die();

		$timestamp = isset($_GET['timestamp']) ? intval($_GET['timestamp']) : 0;
		if (!$timestamp) wp_die('Missing timestamp');

		$history = SiteGuard_Backup_History::get_history();
		if (!isset($history[$timestamp])) wp_die('Backup not found');

		$backup = $history[$timestamp];
		if (!$this->may_download_backup($backup)) wp_die(esc_html__('Only a network administrator can download a network-wide backup.', 'siteguardbackup'), 403);
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Collect all files for this backup
		$files = array();
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore', 'manifest') as $entity) {
			if (!empty($backup[$entity])) {
				$entity_files = is_array($backup[$entity]) ? $backup[$entity] : array($backup[$entity]);
				foreach ($entity_files as $f) {
					if (!is_string($f) || $f === '' || basename($f) !== $f) continue; // never follow a path
					$fpath = $backup_dir . $f;
					clearstatcache(true, $fpath);
					if (file_exists($fpath)) {
						$files[] = $f;
					}
				}
			}
		}

		if (empty($files)) wp_die('No backup files found');

		// ZIP64 guard: the streaming writer emits only classic (32-bit) ZIP records, so any
		// entry or total payload over 4 GiB (0xFFFFFFFF) would produce a structurally corrupt
		// archive. Refuse up front (before any headers) rather than hand the user a broken zip.
		$zip64_limit = 0xFFFFFFFF;
		$bundle_total = 0;
		foreach ($files as $f) {
			$fp = $backup_dir . $f;
			clearstatcache(true, $fp);
			$fsize = @filesize($fp);
			if ($fsize === false) continue;
			if ($fsize > $zip64_limit) {
				wp_die('One or more backup files exceeds 4 GB, which cannot be bundled into a single ZIP by this server. Please download the individual backup files instead.');
			}
			$bundle_total += $fsize;
		}
		if ($bundle_total > $zip64_limit) {
			wp_die('This backup exceeds 4 GB total, which cannot be streamed as a single ZIP by this server. Please download the individual backup files instead.');
		}

		$nonce = isset($backup['nonce']) ? preg_replace('/[^a-zA-Z0-9]/', '', $backup['nonce']) : 'unknown';
		$bundle_name = 'sgbackup_bundle_' . date('Y-m-d', $timestamp) . '_' . $nonce . '.zip';

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('download_bundle', 'Downloaded backup bundle: ' . $bundle_name, array(
			'timestamp' => $timestamp,
			'files' => count($files),
		));

		// Stream ZIP directly to browser - no temp file needed.
		// Uses STORE method (no compression) since backup files are already compressed.
		// This works for any size backup, even multi-GB, with minimal memory.
		@set_time_limit(0);
		while (ob_get_level()) ob_end_clean();

		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="' . $bundle_name . '"');
		header('Cache-Control: no-cache, must-revalidate');
		// Cannot send Content-Length with streaming zip - browser will handle chunked transfer

		$this->stream_zip_files($backup_dir, $files, $backup, $timestamp, $nonce);
		exit;
	}

	/**
	 * Stream a ZIP archive directly to output (no temp file).
	 * Uses STORE method (no compression) and data descriptors so each file
	 * is read only ONCE (CRC32 computed while streaming). Works for any size.
	 */
	private function stream_zip_files($backup_dir, $files, $backup, $timestamp, $nonce) {
		$manifest_json = wp_json_encode(array(
			'type' => 'sgbackup_bundle',
			'timestamp' => $timestamp,
			'nonce' => $nonce,
			'files' => $files,
			'backup' => $backup,
		));

		$entries = array();
		foreach ($files as $f) {
			$fpath = $backup_dir . $f;
			clearstatcache(true, $fpath);
			$entries[] = array(
				'name'       => $f,
				'size'       => filesize($fpath),
				'is_virtual' => false,
				'path'       => $fpath,
			);
		}
		$entries[] = array(
			'name'       => '_sgbackup_bundle.json',
			'size'       => strlen($manifest_json),
			'is_virtual' => true,
			'data'       => $manifest_json,
		);

		// Pass 1: local file headers + data + data descriptors
		// Flag bit 3 = data descriptor follows; CRC/sizes in header are 0.
		$offset = 0;
		foreach ($entries as &$entry) {
			$name = $entry['name'];
			$size = $entry['size'];
			$entry['local_offset'] = $offset;

			// Local file header - CRC/sizes set to 0, data descriptor follows
			$header = pack('V', 0x04034b50);        // local file header signature
			$header .= pack('v', 20);                // version needed (2.0)
			$header .= pack('v', 0x0008);            // flags: bit 3 = data descriptor
			$header .= pack('v', 0);                 // compression: STORE
			$header .= pack('v', 0) . pack('v', 0);  // mod time, mod date
			$header .= pack('V', 0);                 // crc32 (in data descriptor)
			$header .= pack('V', 0);                 // compressed size (in data descriptor)
			$header .= pack('V', 0);                 // uncompressed size (in data descriptor)
			$header .= pack('v', strlen($name));     // name length
			$header .= pack('v', 0);                 // extra field length
			$header .= $name;

			echo $header;
			$offset += strlen($header);

			// Stream file data, computing CRC32 simultaneously
			$ctx = hash_init('crc32b');
			$bytes_written = 0;

			if ($entry['is_virtual']) {
				echo $entry['data'];
				hash_update($ctx, $entry['data']);
				$bytes_written = $size;
			} else {
				$fh = fopen($entry['path'], 'rb');
				if ($fh) {
					while (!feof($fh)) {
						$chunk = fread($fh, 65536);
						if ($chunk === false || $chunk === '') break;
						echo $chunk;
						hash_update($ctx, $chunk);
						$bytes_written += strlen($chunk);
						flush();
					}
					fclose($fh);
				}
			}
			$offset += $bytes_written;

			$crc = hexdec(hash_final($ctx));
			$entry['crc32'] = $crc;
			$entry['actual_size'] = $bytes_written;

			// Data descriptor with signature (widely expected by zip tools)
			$dd = pack('V', 0x08074b50);  // data descriptor signature
			$dd .= pack('V', $crc);
			$dd .= pack('V', $bytes_written > 0xFFFFFFFF ? 0xFFFFFFFF : $bytes_written);
			$dd .= pack('V', $bytes_written > 0xFFFFFFFF ? 0xFFFFFFFF : $bytes_written);
			echo $dd;
			$offset += 16;

			flush();
		}
		unset($entry);

		// Pass 2: central directory
		$cd_offset = $offset;
		$cd_size = 0;
		foreach ($entries as $entry) {
			$name = $entry['name'];
			$asize = $entry['actual_size'];

			$cd = pack('V', 0x02014b50);             // central dir signature
			$cd .= pack('v', 20);                     // version made by
			$cd .= pack('v', 20);                     // version needed
			$cd .= pack('v', 0);                      // flags (no bit 3 in central dir)
			$cd .= pack('v', 0);                      // compression: STORE
			$cd .= pack('v', 0) . pack('v', 0);       // mod time, mod date
			$cd .= pack('V', $entry['crc32']);
			$cd .= pack('V', $asize > 0xFFFFFFFF ? 0xFFFFFFFF : $asize);
			$cd .= pack('V', $asize > 0xFFFFFFFF ? 0xFFFFFFFF : $asize);
			$cd .= pack('v', strlen($name));
			$cd .= pack('v', 0);                       // extra field length
			$cd .= pack('v', 0);                       // comment length
			$cd .= pack('v', 0);                       // disk number start
			$cd .= pack('v', 0);                       // internal attrs
			$cd .= pack('V', 0x20);                   // external attrs (archive)
			$cd .= pack('V', $entry['local_offset'] > 0xFFFFFFFF ? 0xFFFFFFFF : $entry['local_offset']);
			$cd .= $name;

			echo $cd;
			$cd_size += strlen($cd);
		}

		// End of central directory
		$eocd = pack('V', 0x06054b50);
		$eocd .= pack('v', 0);                        // disk number
		$eocd .= pack('v', 0);                        // disk with CD
		$eocd .= pack('v', count($entries));
		$eocd .= pack('v', count($entries));
		$eocd .= pack('V', $cd_size);
		$eocd .= pack('V', $cd_offset > 0xFFFFFFFF ? 0xFFFFFFFF : $cd_offset);
		$eocd .= pack('v', 0);

		echo $eocd;
		flush();
	}

	/**
	 * Handle streaming restore AJAX.
	 */
	public function ajax_restore() {
		if (!$this->verify_ajax('POST', 'sgbackup-restore')) wp_die();

		// Disable output buffering for streaming
		while (ob_get_level()) ob_end_clean();
		header('Content-Type: text/plain; charset=utf-8');
		header('X-Accel-Buffering: no');
		// Pad past nginx fastcgi_buffer_size to force streaming
		echo str_repeat(' ', 33 * 1024) . "\n";
		flush();

		$timestamp = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : 0;
		if (!$timestamp) {
			echo 'RINFO:' . json_encode(array('type' => 'error', 'message' => 'Missing timestamp')) . "\n";
			exit;
		}

		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		// Continuing a restore after its DB import: history is the backup's now,
		// so the set this restore started from is taken from its progress record.
		if (empty($backup_set) && !empty($_POST['resume'])) {
			$resume_progress = SiteGuard_Backup_Options::instance()->get_transient('sgbackup_restore_progress');
			if (is_array($resume_progress) && !empty($resume_progress['backup_set']) && is_array($resume_progress['backup_set'])
				&& (int) (isset($resume_progress['timestamp']) ? $resume_progress['timestamp'] : 0) === (int) $timestamp) {
				$backup_set = $resume_progress['backup_set'];
			}
		}
		if (empty($backup_set)) {
			echo 'RINFO:' . json_encode(array('type' => 'error', 'message' => 'Backup not found')) . "\n";
			exit;
		}
		$backup_set['timestamp'] = $timestamp;

		$restore_options = array();
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity) {
			$restore_options[$entity] = !empty($_POST['sgbackup_restore_' . $entity]) || !empty($_POST['restore_' . $entity]);
		}
		if (!empty($_POST['encryption_key'])) {
			// Don't use sanitize_text_field - it strips characters valid in passphrases
			$restore_options['encryption_key'] = wp_unslash($_POST['encryption_key']);
		}
		if (!empty($_POST['sgbackup_restore_pre_backup'])) {
			$restore_options['pre_restore_backup'] = true;
		}

		// Selective restore items
		if (!empty($_POST['restore_tables']) && is_array($_POST['restore_tables'])) {
			$restore_options['restore_tables'] = array_map('sanitize_text_field', $_POST['restore_tables']);
		}
		if (!empty($_POST['restore_plugins_items']) && is_array($_POST['restore_plugins_items'])) {
			$restore_options['restore_plugins_items'] = array_map('sanitize_text_field', $_POST['restore_plugins_items']);
		}
		if (!empty($_POST['restore_themes_items']) && is_array($_POST['restore_themes_items'])) {
			$restore_options['restore_themes_items'] = array_map('sanitize_text_field', $_POST['restore_themes_items']);
		}
		if (!empty($_POST['include_unspecified_tables'])) {
			$restore_options['include_unspecified_tables'] = true;
		}
		if (!empty($_POST['include_unspecified_plugins'])) {
			$restore_options['include_unspecified_plugins'] = true;
		}
		if (!empty($_POST['include_unspecified_themes'])) {
			$restore_options['include_unspecified_themes'] = true;
		}

		// Move-in mode (file restore behavior) - clamp to valid range
		$restore_options['move_in_mode'] = isset($_POST['move_in_mode']) ? min(3, max(0, intval($_POST['move_in_mode']))) : 0;

		// Database restore mode - only set if explicitly provided, so restorer can fall back to site default
		if (isset($_POST['db_restore_mode'])) {
			$restore_options['db_restore_mode'] = min(3, max(0, intval($_POST['db_restore_mode'])));
		}

		// Subsite migration mode
		if (isset($_POST['subsite_mode'])) {
			$restore_options['subsite_mode'] = min(5, max(0, intval($_POST['subsite_mode'])));
		}

		// Keep original protocol (skip HTTP/HTTPS conversion during search-replace)
		if (!empty($_POST['keep_protocol'])) {
			$restore_options['keep_protocol'] = true;
		}

		// Remap independent domains to subdomains of the new domain
		if (isset($_POST['remap_independent_domains'])) {
			$restore_options['remap_independent_domains'] = !empty($_POST['remap_independent_domains']);
		}

		// Health monitor key policy - only set if posted and valid, so the restorer
		// falls back to the site default. Never coerced to 'backup'.
		if (isset($_POST['health_key_policy']) && is_string($_POST['health_key_policy'])) {
			require_once SGBACKUP_DIR . 'includes/class-health-key-policy.php';
			$health_key_policy = SiteGuard_Backup_Health_Key_Policy::sanitize(wp_unslash($_POST['health_key_policy']));
			if (null !== $health_key_policy) {
				$restore_options['health_key_policy'] = $health_key_policy;
			}
		}

		// Target domain/blog_id for subsite import
		if (!empty($_POST['target_subsite_domain'])) {
			$restore_options['target_subsite_domain'] = sanitize_text_field(wp_unslash($_POST['target_subsite_domain']));
		}
		if (!empty($_POST['target_subsite_id'])) {
			$restore_options['target_subsite_id'] = intval($_POST['target_subsite_id']);
		}

		// Server-side validation: Import-as-subsite mode requires a target
		if (isset($restore_options['subsite_mode']) && (int) $restore_options['subsite_mode'] === 4
			&& empty($restore_options['target_subsite_domain']) && empty($restore_options['target_subsite_id'])) {
			echo 'RINFO:' . wp_json_encode(array('type' => 'error', 'message' => 'Please select a target subsite before restoring.')) . "\n";
			echo 'RINFO:' . wp_json_encode(array('type' => 'complete', 'success' => false, 'errors' => array('No target subsite selected. Please choose an existing subsite or create a new one.'))) . "\n";
			wp_die();
		}

		// Custom subsite mapping (JSON object: old_domain => new_domain)
		if (!empty($_POST['subsite_map'])) {
			$raw_map = wp_unslash($_POST['subsite_map']);
			if (is_string($raw_map)) {
				$raw_map = json_decode($raw_map, true);
			}
			if (is_array($raw_map)) {
				$restore_options['subsite_map'] = $raw_map;
			}
		}

		// Batch subsite map (mode 5: array of source_blog_id/action/target entries)
		if (!empty($_POST['batch_subsite_map'])) {
			$raw = wp_unslash($_POST['batch_subsite_map']);
			if (is_string($raw)) $raw = json_decode($raw, true);
			if (is_array($raw)) {
				$clean = array();
				foreach ($raw as $entry) {
					if (!is_array($entry)) continue;
					$clean[] = array(
						'source_blog_id' => isset($entry['source_blog_id']) ? intval($entry['source_blog_id']) : 0,
						'source_domain'  => isset($entry['source_domain']) && is_string($entry['source_domain']) ? sanitize_text_field($entry['source_domain']) : '',
						'action'         => isset($entry['action']) && in_array($entry['action'], array('overwrite', 'create', 'skip'), true) ? $entry['action'] : 'skip',
						'target_blog_id' => isset($entry['target_blog_id']) ? intval($entry['target_blog_id']) : 0,
						'target_domain'  => isset($entry['target_domain']) ? sanitize_text_field($entry['target_domain']) : '',
					);
				}
				$restore_options['batch_subsite_map'] = $clean;
			}
		}

		// Server-side validation: Batch subsite mode requires a non-empty map
		if (isset($restore_options['subsite_mode']) && (int) $restore_options['subsite_mode'] === 5) {
			$has_non_skip = false;
			if (!empty($restore_options['batch_subsite_map'])) {
				foreach ($restore_options['batch_subsite_map'] as $entry) {
					if ($entry['action'] !== 'skip') { $has_non_skip = true; break; }
				}
			}
			if (!$has_non_skip) {
				echo 'RINFO:' . wp_json_encode(array('type' => 'error', 'message' => 'No subsites selected for batch import.')) . "\n";
				echo 'RINFO:' . wp_json_encode(array('type' => 'complete', 'success' => false, 'errors' => array('No subsites selected for batch import. Please select at least one subsite to overwrite or create.'))) . "\n";
				wp_die();
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$restore_entities = array_keys(array_filter($restore_options, function($v, $k) {
			return $v && in_array($k, array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore'));
		}, ARRAY_FILTER_USE_BOTH));
		$audit->log('restore_started', 'Restore initiated from ' . gmdate('Y-m-d H:i', $timestamp) . ' backup', array(
			'timestamp' => $timestamp,
			'entities' => implode(', ', $restore_entities),
		));

		require_once SGBACKUP_DIR . 'restorer.php';
		// Continuation of a checkpointed restore ("resume=1"): the client only
		// re-posts the timestamp. Rebuilding options from that POST made every
		// entity false, so the DB phase (and its search-replace resume) was skipped
		// and the stream ended with "Restore complete!" on a half-migrated DB.
		// Reuse the options the original request saved with its progress.
		if (!empty($_POST['resume'])) {
			$resume_progress = SiteGuard_Backup_Options::instance()->get_transient('sgbackup_restore_progress');
			if (is_array($resume_progress) && !empty($resume_progress['options']) && is_array($resume_progress['options'])
				&& (int) (isset($resume_progress['timestamp']) ? $resume_progress['timestamp'] : 0) === (int) $timestamp) {
				$resumed = $resume_progress['options'];
				// The passphrase is not persisted with progress; keep the one just posted, if any.
				if (!empty($restore_options['encryption_key'])) $resumed['encryption_key'] = $restore_options['encryption_key'];
				$restore_options = $resumed;
				echo 'RINFO:' . wp_json_encode(array('type' => 'progress', 'message' => 'Resuming restore with the original options')) . "\n";
			}
		}

		$denied = $this->restrict_restore_scope($backup_set, $restore_options);
		if (null !== $denied) {
			echo 'RINFO:' . wp_json_encode(array('type' => 'error', 'message' => $denied)) . "\n";
			echo 'RINFO:' . wp_json_encode(array('type' => 'complete', 'success' => false, 'errors' => array($denied))) . "\n";
			exit;
		}

		$restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
		$result = $restorer->perform_restore();

		// Audit log restore completion
		$audit->log(
			$result['success'] ? 'restore_completed' : 'restore_failed',
			($result['success'] ? 'Restore completed successfully' : 'Restore failed') . ' from ' . gmdate('Y-m-d H:i', $timestamp),
			array('timestamp' => $timestamp)
		);

		// Webhook notification
		SiteGuard_Backup_Webhook::fire(
			$result['success'] ? 'restore_complete' : 'restore_failed',
			array('timestamp' => $timestamp, 'success' => $result['success'])
		);

		// A migration search-replace that checkpointed at the request deadline
		// returns 'incomplete'. Signal that distinctly so the client resumes the
		// same restore (continuing search-replace from its saved cursor) instead of
		// treating it as an error or a finished restore.
		if (!empty($result['incomplete'])) {
			echo 'RINFO:' . json_encode(array(
				'type'      => 'incomplete',
				'timestamp' => $timestamp,
				'messages'  => $result['messages'],
			)) . "\n";
			exit;
		}

		echo 'RINFO:' . json_encode(array(
			'type'     => 'complete',
			'success'  => $result['success'],
			'messages' => $result['messages'],
			'errors'   => $result['errors'],
		)) . "\n";

		exit;
	}

	/**
	 * Handle backup file upload.
	 */
	public function ajax_upload() {
		if (!$this->verify_ajax('POST', 'sgbackup-upload')) wp_die();

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		if (empty($_FILES['sgbackup_file'])) {
			wp_send_json_error(array('message' => 'No file uploaded'));
		}

		$file = $_FILES['sgbackup_file'];
		$filename = sanitize_file_name($file['name']);

		// Validate file extension - only allow known backup formats
		$allowed_extensions = array('zip', 'gz', 'sql', 'crypt');
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		if (!in_array(strtolower($ext), $allowed_extensions)) {
			wp_send_json_error(array('message' => 'Invalid file type'));
		}

		// Server-side file size check
		$max_upload = SiteGuard_Backup_Filesystem::max_backup_upload_size();
		if ($file['size'] > $max_upload) {
			wp_send_json_error(array('message' => 'File exceeds maximum upload size (' . size_format($max_upload) . ')'));
		}

		$dest = $backup_dir . $filename;
		if (file_exists($dest)) {
			// Never overwrite a backup file already on disk with an upload.
			if (filesize($dest) === (int) $file['size'] && md5_file($dest) === md5_file($file['tmp_name'])) {
				@unlink($file['tmp_name']);
				wp_send_json_success(array('filename' => $filename, 'message' => 'This file is already on the server; nothing to do.'));
			}
			wp_send_json_error(array('message' => sprintf('A different file named %s already exists in the backup directory; it was not overwritten.', $filename)));
		}
		if (!move_uploaded_file($file['tmp_name'], $dest)) {
			wp_send_json_error(array('message' => 'Failed to save uploaded file'));
		}
		clearstatcache(true, $dest);

		// Handle backup bundle / set zips. Recognise by CONTENTS, not just the filename,
		// so a bundle that has been renamed (e.g. site-backup.zip) - or a plain zip of the
		// raw backup_* files - still imports as a proper, restorable backup with its
		// db/uploads/... entities, instead of a "generic" item with no Restore action.
		if (strtolower($ext) === 'zip'
			&& (strpos($filename, 'sgbackup_bundle_') === 0 || $this->zip_contains_backup($dest))) {
			$extracted_files = $this->extract_bundle($dest, $backup_dir);
			if (is_wp_error($extracted_files)) {
				// Keep the uploaded bundle: it may be the only complete copy.
				wp_send_json_error(array('message' => $extracted_files->get_error_message() . ' The uploaded bundle was kept as ' . basename($dest) . '.'));
			}
			SiteGuard_Backup_Filesystem::delete_file($dest, $backup_dir); // extracted: bundle no longer needed

			$audit = SiteGuard_Backup_Audit_Log::instance();
			$audit->log('upload_bundle', 'Uploaded and extracted backup bundle: ' . $filename, array(
				'files' => count($extracted_files),
			));

			wp_send_json_success(array(
				'filename' => $filename,
				'bundle'   => true,
				'files'    => count($extracted_files),
				'message'  => sprintf('Bundle extracted: %d backup files imported.', count($extracted_files)),
			));
			return;
		}

		// Try to register in backup history if it looks like a full backup
		if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_[^_]+_([a-zA-Z0-9]+)/', $filename, $m)) {
			$timestamp = strtotime(str_replace('-', '', substr($m[1], 0, 10)) . ' ' . substr($m[1], 11, 2) . ':' . substr($m[1], 13, 2));
			$nonce = $m[2];

			// Determine entity type
			$entity = 'unknown';
			foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
				if (strpos($filename, "-{$e}") !== false) {
					$entity = $e;
					break;
				}
			}

			// An entity we cannot classify would create a history row that no
			// consumer reads (orphan row, file left behind on delete) - skip it.
			if ($entity !== 'unknown') {
				$backup_data = array(
					'nonce' => $nonce,
					$entity => array($filename),
					'service' => array('none'),
					'size' => filesize($dest),
				);
				SiteGuard_Backup_History::add_backup($timestamp, $backup_data);
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('upload_backup', 'Uploaded backup file: ' . $filename, array(
			'filename' => $filename,
			'size' => filesize($dest),
		));

		wp_send_json_success(array(
			'filename' => $filename,
			'size'     => filesize($dest),
		));
	}

	/**
	 * Handle OAuth callback redirect (GET from Google/Dropbox).
	 */
	public function ajax_oauth_callback() {
		if (!$this->user_can_manage()) wp_die('Unauthorized');

		$code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
		$state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
		$method = isset($_GET['method']) ? sanitize_text_field($_GET['method']) : '';

		if (empty($code) || empty($method)) {
			wp_die('Missing OAuth parameters.');
		}

		if (null === $this->commands) {
			require_once SGBACKUP_DIR . 'includes/class-commands.php';
			$this->commands = new SiteGuard_Backup_Commands();
		}

		// Whitelist valid OAuth methods to prevent arbitrary method invocation
		$valid_oauth_methods = array('googledrive', 'dropbox', 'onedrive');
		if (!in_array($method, $valid_oauth_methods, true)) {
			wp_die('Unknown OAuth method.');
		}

		// Validate state nonce to prevent CSRF on OAuth callback
		if (empty($state) || !wp_verify_nonce($state, 'sgbackup-oauth-' . $method)) {
			wp_die('Invalid OAuth state. Please try again.');
		}

		$callback_method = $method . '_auth_callback';

		$result = $this->commands->$callback_method(array('code' => $code, 'state' => $state));

		if (is_wp_error($result)) {
			wp_die('OAuth error: ' . esc_html($result->get_error_message()));
		}

		// Close the popup and notify the parent window
		header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'");
		echo '<html><body><script>
			if (window.opener) { window.opener.location.reload(); }
			window.close();
		</script><p>Authentication successful. You may close this window.</p></body></html>';
		exit;
	}

	/**
	 * L6: Admin bar quick link for SiteGuard Backup.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function admin_bar_menu($wp_admin_bar) {
		if (!$this->user_can_manage()) return;

		$page_url = (is_multisite() && is_network_admin()) ? network_admin_url('admin.php?page=siteguardbackup') : admin_url('admin.php?page=siteguardbackup');

		$wp_admin_bar->add_node(array(
			'id'    => 'sgbackup',
			'title' => '<span class="ab-icon dashicons dashicons-shield"></span> SiteGuard Backup',
			'href'  => $page_url,
		));

		$wp_admin_bar->add_node(array(
			'parent' => 'sgbackup',
			'id'     => 'sgbackup-backup-now',
			'title'  => 'Backup Now',
			'href'   => $page_url . '&action=backupnow',
		));

		// Show last backup status
		$last_backup = SiteGuard_Backup_History::get_latest();
		if ($last_backup) {
			$wp_admin_bar->add_node(array(
				'parent' => 'sgbackup',
				'id'     => 'sgbackup-last-backup',
				'title'  => 'Last: ' . human_time_diff($last_backup['timestamp']) . ' ago',
				'href'   => $page_url,
			));
		}
	}

	/**
	 * L6: Admin bar CSS for the SiteGuard Backup icon.
	 */
	public function admin_bar_css() {
		if (!$this->user_can_manage()) return;
		echo '<style>#wpadminbar #wp-admin-bar-sgbackup .ab-icon:before{content:"\f332";top:2px;}</style>';
	}

	/**
	 * Dashboard Widget: Register the backup health widget.
	 *
	 * Optional, opt-in widget that shows backup health at a glance on the
	 * WordPress admin dashboard. Shows: last backup time, backup count,
	 * disk usage, and any warnings (stale backups, low disk space).
	 *
	 * Only visible to users with manage_options (or manage_network_options
	 * on multisite). Does not run any backups or modify any data.
	 */
	public function dashboard_widget_setup() {
		if (!$this->user_can_manage()) return;

		wp_add_dashboard_widget(
			'sgbackup_dashboard_widget',
			__('SiteGuard Backup', 'siteguardbackup'),
			array($this, 'dashboard_widget_render')
		);
	}

	/**
	 * Dashboard Widget: Render the backup health summary.
	 */
	public function dashboard_widget_render() {
		$opts = SiteGuard_Backup_Options::instance();
		$history = SiteGuard_Backup_History::get_history();
		$backup_dir = $opts->get_backup_dir();
		$disk = SiteGuard_Backup_Filesystem::check_disk_space($backup_dir);

		// Last backup
		$latest = SiteGuard_Backup_History::get_latest();
		$warnings = array();

		echo '<div style="line-height:1.8;">';

		if ($latest) {
			$age = time() - $latest['timestamp'];
			$age_text = human_time_diff($latest['timestamp']);
			$age_color = ($age > 86400 * 7) ? '#d63638' : (($age > 86400 * 2) ? '#dba617' : '#00a32a');
			echo '<p><strong>' . esc_html__('Last Backup:', 'siteguardbackup') . '</strong> ';
			echo '<span style="color:' . $age_color . ';">' . esc_html($age_text) . ' ' . esc_html__('ago', 'siteguardbackup') . '</span>';
			echo ' <small>(' . esc_html(gmdate('M j, H:i', $latest['timestamp'])) . ' UTC)</small></p>';

			if ($age > 86400 * 7) {
				$warnings[] = __('Last backup is over 7 days old.', 'siteguardbackup');
			}
		} else {
			echo '<p><strong>' . esc_html__('Last Backup:', 'siteguardbackup') . '</strong> <span style="color:#d63638;">' . esc_html__('Never', 'siteguardbackup') . '</span></p>';
			$warnings[] = __('No backups found. Create your first backup.', 'siteguardbackup');
		}

		// Backup count & size
		$total_count = count($history);
		$total_size = 0;
		foreach ($history as $backup) {
			if (isset($backup['size'])) {
				$total_size += intval($backup['size']);
			}
		}
		echo '<p><strong>' . esc_html__('Backups:', 'siteguardbackup') . '</strong> ';
		echo esc_html($total_count) . ' ' . esc_html__('sets', 'siteguardbackup');
		if ($total_size > 0) {
			echo ' (' . esc_html(SiteGuard_Backup_Filesystem::format_bytes($total_size)) . ')';
		}
		echo '</p>';

		// Disk space
		if ($disk) {
			$free_pct = ($disk['total'] > 0) ? round(($disk['free'] / $disk['total']) * 100) : 0;
			$disk_color = ($free_pct < 5) ? '#d63638' : (($free_pct < 15) ? '#dba617' : '#00a32a');
			echo '<p><strong>' . esc_html__('Disk:', 'siteguardbackup') . '</strong> ';
			echo esc_html(SiteGuard_Backup_Filesystem::format_bytes($disk['free'])) . ' ' . esc_html__('free', 'siteguardbackup');
			echo ' <span style="color:' . $disk_color . ';">(' . $free_pct . '%)</span></p>';

			if ($free_pct < 5) {
				$warnings[] = __('Disk space critically low!', 'siteguardbackup');
			}
		}

		// Schedule info
		$file_interval = $opts->get_option('sgbackup_interval', 'manual');
		$db_interval = $opts->get_option('sgbackup_interval_database', 'manual');
		echo '<p><strong>' . esc_html__('Schedule:', 'siteguardbackup') . '</strong> ';
		echo esc_html__('Files', 'siteguardbackup') . ': ' . esc_html($file_interval);
		echo ' &middot; ' . esc_html__('DB', 'siteguardbackup') . ': ' . esc_html($db_interval);
		echo '</p>';

		// Next scheduled
		$next_file = wp_next_scheduled('sgbackup_backup');
		$next_db = wp_next_scheduled('sgbackup_backup_database');
		$next = false;
		if ($next_file && $next_db) {
			$next = min($next_file, $next_db);
		} elseif ($next_file) {
			$next = $next_file;
		} elseif ($next_db) {
			$next = $next_db;
		}
		if ($next) {
			echo '<p><strong>' . esc_html__('Next:', 'siteguardbackup') . '</strong> ';
			echo esc_html(human_time_diff(time(), $next)) . ' (' . esc_html(gmdate('M j, H:i', $next)) . ' UTC)</p>';
		}

		// Warnings
		if (!empty($warnings)) {
			echo '<div style="background:#fcf0f1;border-left:4px solid #d63638;padding:6px 10px;margin-top:8px;">';
			foreach ($warnings as $w) {
				echo '<p style="margin:2px 0;">' . esc_html($w) . '</p>';
			}
			echo '</div>';
		}

		// Failover role badge
		$failover_role = $opts->get_option('sgbackup_failover_role', 'standalone');
		if ($failover_role !== 'standalone') {
			$role_colors = array('production' => '#0073aa', 'standby' => '#826eb4');
			$role_color = isset($role_colors[$failover_role]) ? $role_colors[$failover_role] : '#666';
			echo '<p><strong>' . esc_html__('Failover Role:', 'siteguardbackup') . '</strong> ';
			echo '<span style="display:inline-block;background:' . $role_color . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:12px;">';
			echo esc_html(ucfirst($failover_role));
			echo '</span></p>';
		}

		echo '</div>';
		$page_url = (is_multisite() && is_network_admin()) ? network_admin_url('admin.php?page=siteguardbackup') : admin_url('admin.php?page=siteguardbackup');
		echo '<p style="margin-top:10px;"><a href="' . esc_url($page_url) . '" class="button">' . esc_html__('Open SiteGuard Backup', 'siteguardbackup') . '</a></p>';
	}

	/**
	 * Verify AJAX request with nonce.
	 *
	 * @param string $method     HTTP method (POST or GET).
	 * @param string $nonce_action Nonce action to verify against. Falls back to 'sgbackup-ajax'.
	 * @return bool
	 */
	private function verify_ajax($method = 'POST', $nonce_action = '') {
		if (!$this->user_can_manage()) {
			wp_send_json_error(array('message' => 'Unauthorized'));
			return false;
		}

		// Same perimeter as ajax_handler(): the IP allowlist and the settings
		// lock must also cover restore, upload, and download - otherwise a
		// locked UI still allowed a full restore with the generic page nonce.
		require_once SGBACKUP_DIR . 'includes/class-security.php';
		if (!SiteGuard_Backup_Security::check_ip_allowlist()) {
			wp_send_json_error(array('message' => 'Access denied: your IP is not in the allowlist.'));
			return false;
		}
		if ($this->is_settings_locked()) {
			wp_send_json_error(array('message' => 'Settings are locked. Please unlock first.'));
			return false;
		}

		$nonce_key = ($method === 'GET') ? '_wpnonce' : 'nonce';
		$nonce_source = ($method === 'GET') ? $_GET : $_POST;
		$nonce = isset($nonce_source[$nonce_key]) ? sanitize_text_field($nonce_source[$nonce_key]) : '';

		// Verify against specific action first, then fall back to generic
		if (!empty($nonce_action) && wp_verify_nonce($nonce, $nonce_action)) {
			return true;
		}
		if (wp_verify_nonce($nonce, 'sgbackup-ajax')) {
			return true;
		}

		wp_send_json_error(array('message' => 'Invalid security token. Please reload the page.'));
		return false;
	}

	/**
	 * Extract a backup bundle zip and register its contents in backup history.
	 *
	 * @param string $bundle_path Path to the bundle zip file.
	 * @param string $backup_dir  Backup directory to extract into.
	 * @return array|WP_Error     Array of extracted filenames or error.
	 */
	public function extract_bundle($bundle_path, $backup_dir) {
		$zip = new ZipArchive();
		if (true !== $zip->open($bundle_path)) {
			return new WP_Error('zip_open', 'Failed to open bundle zip.');
		}

		// Look for bundle manifest
		$manifest_json = $zip->getFromName('_sgbackup_bundle.json');
		$manifest = $manifest_json ? json_decode($manifest_json, true) : null;

		// In-zip extension allowlist and explicit protection-file denylist so a crafted
		// bundle cannot drop .php/.htaccess/.user.ini/etc into the backup dir.
		$allowed_ext = array('zip', 'gz', 'sql', 'crypt', 'json');
		$protected_names = array('.htaccess', '.user.ini', 'index.php', 'index.html', 'web.config', '.nginx-deny');

		$extracted = array();
		$errors = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = $zip->getNameIndex($i);
			if ($entry === '_sgbackup_bundle.json') continue;
			// Security: prevent path traversal and subdirectories
			if (strpos($entry, '..') !== false || strpos($entry, '/') !== false || strpos($entry, '\\') !== false) continue;
			$safe_name = sanitize_file_name($entry);
			if (empty($safe_name)) continue;

			// Reject protection/config files (case-insensitive), whether by raw or sanitized name.
			if (in_array(strtolower($entry), $protected_names, true) || in_array(strtolower($safe_name), $protected_names, true)) continue;

			// Enforce the in-zip extension allowlist.
			$ext = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
			if (!in_array($ext, $allowed_ext, true)) continue;

			// Never extract to the raw entry name: stream the entry into a unique temp file,
			// then move it to the sanitized target. This prevents the archive from creating
			// (even briefly) a file with an attacker-chosen name in the backup directory.
			$stream = $zip->getStream($entry);
			if (!$stream) { $errors[] = "{$safe_name}: cannot read from bundle"; continue; }
			$tmp_path = $backup_dir . 'sgbundle_tmp_' . wp_generate_password(16, false) . '.part';
			$out = @fopen($tmp_path, 'wb');
			if (!$out) { @fclose($stream); $errors[] = "{$safe_name}: cannot write temp file"; continue; }
			// Every read and write is checked and the result must match the entry's
			// size: a full disk used to produce a silently truncated archive that
			// was then registered as restorable.
			$stream_ok = true;
			while (!feof($stream)) {
				$buf = fread($stream, 65536);
				if (false === $buf) { $stream_ok = false; break; }
				if ('' !== $buf && fwrite($out, $buf) !== strlen($buf)) { $stream_ok = false; break; }
			}
			fclose($out);
			fclose($stream);
			clearstatcache(true, $tmp_path);
			$stat = $zip->statIndex($i);
			if (!$stream_ok || !is_array($stat) || filesize($tmp_path) !== (int) $stat['size']) {
				@unlink($tmp_path);
				$errors[] = "{$safe_name}: extraction incomplete (disk full?)";
				continue;
			}

			$target = $backup_dir . $safe_name;
			// Never replace an existing backup file: a byte-identical copy is reused,
			// anything else is refused rather than overwriting a good archive.
			if (file_exists($target)) {
				$same = (filesize($target) === filesize($tmp_path)) && (md5_file($target) === md5_file($tmp_path));
				@unlink($tmp_path);
				if ($same) {
					$extracted[] = $safe_name;
				} else {
					$errors[] = "{$safe_name}: a different file with this name already exists; not overwritten";
				}
				continue;
			}
			// Move temp into place (rename; copy+unlink fallback for CIFS).
			if (!@rename($tmp_path, $target)) {
				$copied = @copy($tmp_path, $target);
				@unlink($tmp_path);
				if (!$copied) {
					$errors[] = "{$safe_name}: could not move into place";
					continue;
				}
			}
			$extracted[] = $safe_name;
		}
		$zip->close();

		if (!empty($errors)) {
			return new WP_Error('bundle_incomplete', 'Bundle not imported: ' . implode('; ', $errors));
		}

		// Register in backup history using the bundle control file's metadata.
		$registered = false;
		if (is_array($manifest) && !empty($manifest['timestamp']) && !empty($manifest['backup']) && is_array($manifest['backup'])) {
			// The manifest is attacker-controllable (it came out of an uploaded zip).
			// Keep its scalar metadata, but every file reference must be a bare
			// filename that we actually extracted - otherwise a crafted manifest
			// could point history at ../../wp-config.php or another tenant's dir,
			// which download/delete would then happily follow.
			$backup_data = array();
			$file_keys = array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore', 'manifest');
			foreach ($manifest['backup'] as $k => $v) {
				if (!is_string($k)) continue;
				$is_file_key = in_array($k, $file_keys, true) || strpos($k, 'extradir_') === 0;
				if ($is_file_key) {
					$list = is_array($v) ? $v : array($v);
					$clean = array();
					foreach ($list as $fname) {
						if (!is_string($fname) || $fname === '' || basename($fname) !== $fname) continue;
						if (!in_array($fname, $extracted, true)) continue;
						$clean[] = $fname;
					}
					if (!empty($clean)) $backup_data[$k] = array_values(array_unique($clean));
				} elseif ('incremental_sets' === $k) {
					// Merged into the entry's file lists by get_history(), so delete and
					// restore act on them: keep only files that came out of THIS bundle.
					if (!is_array($v)) continue;
					$sets = array();
					foreach ($v as $set_ts => $set) {
						if (!is_array($set)) continue;
						foreach ($set as $ek => $ev) {
							$names = is_array($ev) ? $ev : array($ev);
							$names = array_values(array_filter($names, function ($n) use ($extracted) {
								return is_string($n) && basename($n) === $n && in_array($n, $extracted, true);
							}));
							if ($names) $sets[(int) $set_ts][$ek] = $names;
						}
					}
					if ($sets) $backup_data[$k] = $sets;
				} elseif (is_scalar($v) || is_array($v)) {
					$backup_data[$k] = $v;
				}
			}
			if (isset($backup_data['nonce'])) {
				$backup_data['nonce'] = is_string($backup_data['nonce']) ? preg_replace('/[^a-zA-Z0-9]/', '', $backup_data['nonce']) : '';
			}
			$total_size = 0;
			foreach ($extracted as $f) {
				$p = $backup_dir . $f;
				clearstatcache(true, $p);
				if (file_exists($p)) $total_size += filesize($p);
			}
			$backup_data['size'] = $total_size;
			$backup_data['service'] = array('none');
			$has_files = false;
			foreach ($backup_data as $k => $v) {
				if ((in_array($k, $file_keys, true) || strpos($k, 'extradir_') === 0) && !empty($v)) { $has_files = true; break; }
			}
			if ($has_files) {
				SiteGuard_Backup_History::add_backup(intval($manifest['timestamp']), $backup_data);
				$registered = true;
			}
		}

		// Fallback: no (or invalid) _sgbackup_bundle.json - e.g. someone re-zipped the
		// raw backup_* files under a custom name. Reconstruct the history entry from the
		// extracted entity filenames so the upload still registers as a restorable backup
		// (with its db/uploads/... parts) instead of leaving loose, unregistered files.
		if (!$registered) {
			$this->register_backup_from_files($extracted, $backup_dir);
		}

		return $extracted;
	}

	/**
	 * Does this zip contain a SiteGuard backup? True if it has the bundle control file
	 * (_sgbackup_bundle.json) OR any backup_* entity file at the root - regardless of the
	 * archive's own name. Lets renamed bundles / hand-zipped backup sets import correctly.
	 */
	public function zip_contains_backup($zip_path) {
		if (!class_exists('ZipArchive')) return false;
		$zip = new ZipArchive();
		if (true !== $zip->open($zip_path)) return false;
		$found = false;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = $zip->getNameIndex($i);
			if ($entry === false) continue;
			// root-level entries only
			if (strpos($entry, '/') !== false || strpos($entry, '\\') !== false) continue;
			if ($entry === '_sgbackup_bundle.json') { $found = true; break; }
			if (preg_match('/^backup_\d{4}-\d{2}-\d{2}-\d{4}_.+-(db|uploads|plugins|themes|others|wpcore)\b/i', $entry)) {
				$found = true; break;
			}
		}
		$zip->close();
		return $found;
	}

	/**
	 * Reconstruct and register a backup-history entry purely from extracted entity
	 * filenames (backup_<Y-m-d-Hi>_<site>_<nonce>-<entity>.<ext>), for bundles that
	 * lack a _sgbackup_bundle.json control file. Groups split entities (uploads-1, …),
	 * picks up the per-backup -manifest.json, and writes one history entry.
	 *
	 * @return bool True if a backup was registered.
	 */
	private function register_backup_from_files($files, $backup_dir) {
		$entities = array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore');
		$nonce = '';
		$timestamp = 0;
		$data = array();
		foreach ((array) $files as $f) {
			if (!preg_match('/^backup_(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})_[^_]+_([a-zA-Z0-9]+)-/', $f, $m)) continue;
			if ($nonce === '') {
				$nonce = $m[4];
				$timestamp = strtotime($m[1] . ' ' . $m[2] . ':' . $m[3]);
			}
			if (preg_match('/-manifest\.json$/i', $f)) { $data['manifest'] = $f; continue; }
			foreach ($entities as $e) {
				// match -db. , -uploads. , and split parts like -uploads-1.
				if (preg_match('/-' . preg_quote($e, '/') . '(\.|-\d+\.)/i', $f)) {
					$data[$e][] = $f;
					break;
				}
			}
		}
		if ($nonce === '' || !$timestamp) return false;

		$total_size = 0;
		foreach ((array) $files as $f) {
			$p = $backup_dir . $f;
			clearstatcache(true, $p);
			if (file_exists($p)) $total_size += filesize($p);
		}
		foreach ($entities as $e) {
			if (isset($data[$e])) $data[$e] = array_values(array_unique($data[$e]));
		}
		$data['nonce']   = $nonce;
		$data['service'] = array('none');
		$data['size']    = $total_size;
		SiteGuard_Backup_History::add_backup($timestamp, $data);
		return true;
	}

	/**
	 * Get list of storage methods that have been configured (have saved settings).
	 */
	private function get_configured_methods() {
		$opts = SiteGuard_Backup_Options::instance();
		$all_methods = array('s3', 's3generic', 'googledrive', 'dropbox', 'onedrive', 'azure', 'gcs', 'pcloud', 'ftp', 'sftp', 'webdav', 'openstack', 'email');
		$configured = array();
		foreach ($all_methods as $method) {
			$settings = $opts->get_option('sgbackup_' . $method, array());
			if (!empty($settings)) {
				$configured[] = $method;
			}
		}
		// RemoteSend is configured separately via remote sites
		$remote_sites = $opts->get_option('sgbackup_remotesites', array());
		if (!empty($remote_sites)) {
			$configured[] = 'remotesend';
		}
		return $configured;
	}

	/**
	 * Warn when backups are (or were) stored where the webserver can serve them.
	 *
	 * Resolving the backup directory here means the check runs on every admin page
	 * load, so a fallback into the web root surfaces immediately rather than only
	 * after the next backup.
	 */
	public function backup_dir_notice() {
		if (!$this->user_can_manage()) return;

		$opts = SiteGuard_Backup_Options::instance();
		$opts->get_backup_dir();

		if ((int) $opts->get_option('sgbackup_dir_exposed', 0)) {
			$info = $opts->get_option('sgbackup_dir_exposure', array());
			$dir  = (is_array($info) && !empty($info['dir'])) ? $info['dir'] : '';
			echo '<div class="notice notice-error"><p><strong>SiteGuard Backup: backups are stored inside the web root.</strong> ';
			echo 'Files in <code>' . esc_html($dir) . '</code> can be downloaded by anyone who guesses their name: this server ';
			echo '(' . esc_html(!empty($info['server']) ? $info['server'] : 'unknown') . ') ignores .htaccess, and no file placed in that directory can block access.</p>';
			if (!empty($info['tried'])) {
				echo '<p>No writable directory outside the web root was found. Tried: <code>' . esc_html(implode('</code>, <code>', (array) $info['tried'])) . '</code></p>';
			}
			echo '<p>Fix: create a directory outside the web root that PHP can write to, then add <code>define(\'SGBACKUP_BACKUP_DIR\', \'/path/outside/webroot\');</code> to wp-config.php ';
			echo '(or return that path from the <code>sgbackup_backup_dir</code> filter in an mu-plugin). Then move or delete any existing archives in the old directory.</p></div>';
		}

		// The legacy scan covers every subsite's directory; only network-level admins see it.
		$network_admin = is_multisite() ? current_user_can('manage_network_options') : current_user_can('manage_options');
		$leftovers = $network_admin ? $opts->get_option('sgbackup_legacy_leftovers', array()) : array();
		if (is_array($leftovers) && !empty($leftovers)) {
			echo '<div class="notice notice-warning"><p><strong>SiteGuard Backup:</strong> files remain in old backup directories inside the web root and may be publicly downloadable. Move them into the current backup directory (';
			echo '<code>' . esc_html($opts->get_backup_dir()) . '</code>) or delete them:</p><ul>';
			foreach ($leftovers as $d => $count) {
				echo '<li><code>' . esc_html($d) . '</code>: ' . (int) $count . ' file(s)</li>';
			}
			echo '</ul></div>';
		}
	}

	/**
	 * On multisite, a subsite administrator (not a super admin) may only restore a
	 * per-subsite backup into their OWN subsite, and only its database and uploads.
	 *
	 * Without this, target_subsite_id / batch maps let them drop and replace any
	 * other subsite's tables, a network backup replaced the whole network, and
	 * plugins/themes/core/others (shared by every site) let them plant PHP that
	 * runs network-wide.
	 *
	 * @return string|null Error message, or null when allowed. Normalises $options.
	 */
	private function restrict_restore_scope($backup_set, &$options) {
		if (!is_multisite() || current_user_can('manage_network_options')) {
			return null;
		}
		$blog_id = (int) get_current_blog_id();
		if (empty($backup_set['is_subsite_backup'])) {
			return __('Only a network administrator can restore a network-wide backup.', 'siteguardbackup');
		}
		foreach (array('plugins', 'themes', 'others', 'wpcore') as $shared) {
			if (!empty($options[$shared])) {
				return sprintf(__('Only a network administrator can restore %s: they are shared by every site in the network.', 'siteguardbackup'), $shared);
			}
		}
		if (!empty($options['batch_subsite_map']) || !empty($options['subsite_map']) || !empty($options['include_unspecified_tables'])) {
			return __('Only a network administrator can restore into other subsites.', 'siteguardbackup');
		}
		if (!empty($options['target_subsite_id']) && (int) $options['target_subsite_id'] !== $blog_id) {
			return __('You can only restore into your own site.', 'siteguardbackup');
		}
		if (!empty($options['target_subsite_domain'])) {
			$details = get_blog_details($blog_id);
			$own = $details ? untrailingslashit($details->domain . $details->path) : '';
			if (untrailingslashit($options['target_subsite_domain']) !== $own) {
				return __('You can only restore into your own site.', 'siteguardbackup');
			}
		}
		// Always import into the current blog, whatever blog the backup came from.
		// The network's health monitor key is not theirs to replace.
		$options['health_key_policy'] = 'keep';
		$options['subsite_mode'] = SiteGuard_Backup_Restorer::SUBSITE_MODE_IMPORT_AS;
		$options['target_subsite_id'] = $blog_id;
		unset($options['target_subsite_domain']);
		return null;
	}

	/**
	 * On multisite, a network-wide backup holds every subsite's database: only a
	 * network administrator may download it. (A main-site administrator who is
	 * not one sees those backups in the main site's history.)
	 */
	private function may_download_backup($entry) {
		if (!is_multisite() || current_user_can('manage_network_options')) return true;
		return !empty($entry['is_subsite_backup']) && (int) ($entry['subsite_blog_id'] ?? 0) === (int) get_current_blog_id();
	}

	/**
	 * Check if current user can manage backups.
	 */
	private function user_can_manage() {
		if (is_multisite()) {
			if (current_user_can('manage_network_options')) return true;
			if (!is_network_admin() && current_user_can('manage_options')) return true;
			return false;
		}
		return current_user_can('manage_options');
	}

	/**
	 * Send Content-Security-Policy header on SiteGuard admin pages.
	 */
	public function maybe_send_csp_headers() {
		$screen = get_current_screen();
		if ($screen && strpos($screen->id, 'siteguardbackup') !== false && !headers_sent()) {
			header("Content-Security-Policy: frame-ancestors 'self'");
		}
	}

	/**
	 * Include a template file.
	 *
	 * @param string $template Template filename (without path).
	 * @param array  $vars     Variables to extract into template scope.
	 * @param bool   $return   Whether to return output instead of echoing.
	 * @return string|void
	 */
	public function include_template($template, $vars = array(), $return = false) {
		$path = SGBACKUP_DIR . 'templates/' . $template;
		if (!file_exists($path)) return '';

		if ($return) ob_start();
		// Make vars available to templates as $sgb_vars array instead of extract()
		$sgb_vars = $vars;
		include $path;
		if ($return) return ob_get_clean();
	}
}
