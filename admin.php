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
		add_action('wp_ajax_sgbackup_restore', array($this, 'ajax_restore'));
		add_action('wp_ajax_sgbackup_upload', array($this, 'ajax_upload'));
		add_action('wp_ajax_sgbackup_oauth_callback', array($this, 'ajax_oauth_callback'));
		add_action('wp_ajax_sgbackup_unlock', array($this, 'ajax_unlock'));
		add_action('admin_bar_menu', array($this, 'admin_bar_menu'), 100);
		add_action('admin_head', array($this, 'admin_bar_css'));
		add_action('admin_head', array($this, 'maybe_send_csp_headers'));
		add_action('wp_head', array($this, 'admin_bar_css'));
		add_action('wp_dashboard_setup', array($this, 'dashboard_widget_setup'));
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
				'failover_status'   => wp_create_nonce('sgbackup-failover_status'),
				'failover_prefailover'  => wp_create_nonce('sgbackup-failover_prefailover'),
				'failover_postfailover' => wp_create_nonce('sgbackup-failover_postfailover'),
				'save_restore_preset'   => wp_create_nonce('sgbackup-save_restore_preset'),
				'get_restore_presets'   => wp_create_nonce('sgbackup-get_restore_presets'),
				'delete_restore_preset' => wp_create_nonce('sgbackup-delete_restore_preset'),
				'save_remotesend_schedule' => wp_create_nonce('sgbackup-save_remotesend_schedule'),
				'get_remotesend_schedule'  => wp_create_nonce('sgbackup-get_remotesend_schedule'),
			),
			'max_upload_size' => wp_max_upload_size(),
			'is_multisite' => is_multisite(),
			'current_blog_id' => get_current_blog_id(),
			'default_db_restore_mode' => intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_db_restore_mode', 0)),
			'default_subsite_mode' => intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_subsite_mode', 0)),
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

		// Rate limiting on sensitive destructive/mutating endpoints
		$rate_limits = array(
			'wipe_settings'          => array('limit' => 2,  'window' => 60),   // 2 per minute
			'searchreplace'          => array('limit' => 5,  'window' => 60),   // 5 per minute
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
		$read_only_commands = array('activejobs_list', 'get_existing_backups', 'get_log', 'get_settings', 'get_sysinfo', 'get_remotesites', 'get_migrator_keys', 'get_backup_contents', 'get_database_tables', 'get_audit_log', 'failover_status', 'get_restore_presets', 'get_remotesend_schedule');
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
			'failover_status',
			'failover_prefailover',
			'failover_postfailover',
			'save_restore_preset',
			'get_restore_presets',
			'delete_restore_preset',
			'save_remotesend_schedule',
			'get_remotesend_schedule',
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
			wp_send_json_error(array(
				'message'    => $result->get_error_message(),
				'error_code' => $result->get_error_code(),
			));
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

		if (!file_exists($path)) wp_die('File not found');

		// Verify the file is in the backup directory (prevent directory traversal)
		$real_path = realpath($path);
		$real_dir = realpath($backup_dir);
		if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0) wp_die('Invalid file path');

		// Only allow downloading actual backup files (not .htaccess, index.php, etc.)
		if (!preg_match('/\.(zip|gz|sql|crypt|json|txt)$/', $file)) wp_die('Invalid file type');

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('download_backup', 'Downloaded backup file: ' . $file, array(
			'file' => $file,
			'size' => filesize($path),
		));

		// Clear output buffers for streaming
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
	 * Handle streaming restore AJAX.
	 */
	public function ajax_restore() {
		if (!$this->verify_ajax('POST', 'sgbackup-restore')) wp_die();

		// Disable output buffering for streaming
		while (ob_get_level()) ob_end_clean();
		header('Content-Type: text/plain; charset=utf-8');
		header('X-Accel-Buffering: no');

		$timestamp = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : 0;
		if (!$timestamp) {
			echo 'RINFO:' . json_encode(array('type' => 'error', 'message' => 'Missing timestamp')) . "\n";
			exit;
		}

		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			echo 'RINFO:' . json_encode(array('type' => 'error', 'message' => 'Backup not found')) . "\n";
			exit;
		}

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
			$restore_options['subsite_mode'] = min(4, max(0, intval($_POST['subsite_mode'])));
		}

		// Target domain for subsite import
		if (!empty($_POST['target_subsite_domain'])) {
			$restore_options['target_subsite_domain'] = sanitize_text_field(wp_unslash($_POST['target_subsite_domain']));
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
		$max_upload = wp_max_upload_size();
		if ($file['size'] > $max_upload) {
			wp_send_json_error(array('message' => 'File exceeds maximum upload size (' . size_format($max_upload) . ')'));
		}

		$dest = $backup_dir . $filename;
		if (!move_uploaded_file($file['tmp_name'], $dest)) {
			wp_send_json_error(array('message' => 'Failed to save uploaded file'));
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

			$backup_data = array(
				'nonce' => $nonce,
				$entity => array($filename),
				'service' => array('none'),
				'size' => filesize($dest),
			);
			// Use add_backup() which handles locking, merging, and raw history correctly
			SiteGuard_Backup_History::add_backup($timestamp, $backup_data);
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
