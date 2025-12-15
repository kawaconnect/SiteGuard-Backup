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
		add_action('wp_head', array($this, 'admin_bar_css'));
	}

	/**
	 * Register admin menu page.
	 */
	public function admin_menu() {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';

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

		wp_localize_script('sgbackup-admin', 'sgbackup_settings', array(
			'ajax_url'  => admin_url('admin-ajax.php'),
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
			),
			'max_upload_size' => wp_max_upload_size(),
			'is_multisite' => is_multisite(),
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
			wp_send_json_error(array('message' => 'Incorrect password'));
		}

		// Generate session cookie
		$user_id = get_current_user_id();
		$timestamp = time();
		$hash = hash_hmac('sha256', $user_id . ':' . $timestamp, $stored_hash . wp_salt());
		$cookie_value = $user_id . ':' . $timestamp . ':' . $hash;

		$session_length = intval($opts->get_option('sgbackup_lock_admin_session_length', 3600));

		setcookie('sgbackup_unlock', $cookie_value, time() + $session_length, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, is_ssl(), true);

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

		// Per-action nonce verification: try action-specific nonce first, fall back to generic
		$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
		$action_nonce_valid = wp_verify_nonce($nonce, 'sgbackup-' . $subaction);
		$generic_nonce_valid = wp_verify_nonce($nonce, 'sgbackup-ajax');
		if (!$action_nonce_valid && !$generic_nonce_valid) {
			wp_send_json_error(array('message' => 'Invalid security token. Please reload the page.'));
		}

		// Enforce settings lock on all mutating commands except activejobs_list (read-only status check)
		$read_only_commands = array('activejobs_list', 'get_existing_backups', 'get_log', 'get_settings', 'get_sysinfo', 'get_remotesites', 'get_migrator_keys', 'get_backup_contents', 'get_database_tables');
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
		);

		if (!in_array($subaction, $allowed_commands, true)) {
			wp_send_json_error(array('message' => 'Unknown command: ' . $subaction));
		}

		$params = isset($_POST['data']) ? $_POST['data'] : array();
		if (is_string($params)) {
			$decoded = json_decode(stripslashes($params), true);
			if (is_array($decoded)) $params = $decoded;
		}

		// Defense-in-depth: wp_unslash and sanitize all string values in the params array
		if (is_array($params)) {
			array_walk_recursive($params, function(&$val) {
				if (is_string($val)) {
					$val = wp_unslash($val);
					$val = sanitize_text_field($val);
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
			$restore_options[$entity] = !empty($_POST['restore_' . $entity]);
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
			$restore_options['selective_tables'] = array_map('sanitize_text_field', $_POST['restore_tables']);
		}
		if (!empty($_POST['restore_plugins_items']) && is_array($_POST['restore_plugins_items'])) {
			$restore_options['selective_plugins'] = array_map('sanitize_text_field', $_POST['restore_plugins_items']);
		}
		if (!empty($_POST['restore_themes_items']) && is_array($_POST['restore_themes_items'])) {
			$restore_options['selective_themes'] = array_map('sanitize_text_field', $_POST['restore_themes_items']);
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

		require_once SGBACKUP_DIR . 'restorer.php';
		$restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
		$result = $restorer->perform_restore();

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

			$history = SiteGuard_Backup_History::get_history();
			if (!isset($history[$timestamp])) {
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
				SiteGuard_Backup_History::add_backup($timestamp, $backup_data);
			} else {
				// Add to existing backup set
				foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
					if (strpos($filename, "-{$e}") !== false) {
						if (!isset($history[$timestamp][$e])) {
							$history[$timestamp][$e] = array();
						}
						if (is_string($history[$timestamp][$e])) {
							$history[$timestamp][$e] = array($history[$timestamp][$e]);
						}
						if (!in_array($filename, $history[$timestamp][$e])) {
							$history[$timestamp][$e][] = $filename;
						}
						break;
					}
				}
				SiteGuard_Backup_History::save_history($history);
			}
		}

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
		$valid_oauth_methods = array('googledrive', 'dropbox', 'onedrive', 'pcloud');
		if (!in_array($method, $valid_oauth_methods, true)) {
			wp_die('Unknown OAuth method.');
		}

		// Validate state nonce to prevent CSRF on OAuth callback
		if (!empty($state) && !wp_verify_nonce($state, 'sgbackup-oauth-' . $method)) {
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

		$wp_admin_bar->add_node(array(
			'id'    => 'sgbackup',
			'title' => '<span class="ab-icon dashicons dashicons-shield"></span> SiteGuard Backup',
			'href'  => admin_url('admin.php?page=siteguardbackup'),
		));

		$wp_admin_bar->add_node(array(
			'parent' => 'sgbackup',
			'id'     => 'sgbackup-backup-now',
			'title'  => 'Backup Now',
			'href'   => admin_url('admin.php?page=siteguardbackup&action=backupnow'),
		));

		// Show last backup status
		$last_backup = SiteGuard_Backup_History::get_latest();
		if ($last_backup) {
			$wp_admin_bar->add_node(array(
				'parent' => 'sgbackup',
				'id'     => 'sgbackup-last-backup',
				'title'  => 'Last: ' . human_time_diff($last_backup['timestamp']) . ' ago',
				'href'   => admin_url('admin.php?page=siteguardbackup'),
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
			return current_user_can('manage_network_options');
		}
		return current_user_can('manage_options');
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
