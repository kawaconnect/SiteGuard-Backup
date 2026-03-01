<?php
/**
 * SiteGuard Backup - Remote Send (Migration Receiver)
 *
 * Listens for incoming backup chunks from remote sites.
 * Activated when sgbackup_migrator_localkeys contains keys.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Remote_Send {

	private static $instance = null;

	/** @var array PHP events collected during processing */
	private $php_events = array();

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Authenticated users: handle RPC via the main AJAX action
		add_action('wp_ajax_sgbackup_ajax', array($this, 'handle_rpc_message'), 5);
		// Unauthenticated cross-site RPC: must use the same action the sender POSTs to
		add_action('wp_ajax_nopriv_sgbackup_ajax', array($this, 'handle_rpc_message_nopriv'), 5);
	}

	/**
	 * Handle incoming RPC messages (runs before the main AJAX handler).
	 * Security: authentication is via RSA-encrypted messages, not WordPress nonces.
	 * The sender must possess the receiver's public key to encrypt, and only the
	 * receiver's private key can decrypt - this provides authentication.
	 */
	public function handle_rpc_message() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Insufficient permissions.'));
			return;
		}

		if (empty($_POST['subaction']) || $_POST['subaction'] !== 'rpc_message') return;

		// Verify required RPC header is present
		if (empty($_SERVER['HTTP_X_SGBACKUP_RPC'])) return;

		if (empty($_POST['encrypted']) || empty($_POST['key'])) return;

		// Rate limiting: max 60 requests per minute per IP
		// Use CF-Connecting-IP behind Cloudflare, fall back to REMOTE_ADDR
		$ip = isset($_SERVER['HTTP_CF_CONNECTING_IP'])
			? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'])
			: (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown');
		$rate_key = 'sgbackup_rpc_rate_' . md5($ip);
		$rate_count = (int) get_transient($rate_key);
		if ($rate_count >= 60) {
			wp_send_json_error(array('message' => 'Rate limit exceeded. Try again later.'));
			return;
		}
		set_transient($rate_key, $rate_count + 1, 60);

		$name_hash = isset($_POST['name_hash']) ? sanitize_text_field($_POST['name_hash']) : '';

		// Find the matching local key
		$opts = SiteGuard_Backup_Options::instance();
		$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());

		if (empty($name_hash) || !isset($local_keys[$name_hash])) {
			wp_send_json_error(array('message' => 'Unknown key identifier.'));
			return;
		}

		$key_data = $local_keys[$name_hash];

		// Validate key data structure before use
		if (empty($key_data['private_key']) || !is_string($key_data['private_key'])) {
			wp_send_json_error(array('message' => 'Migration key data is corrupted.'));
			return;
		}

		// Decrypt the message
		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$rpc = new SiteGuard_Backup_Remote_Communications();

		// Validate base64 format before passing to decryption
		$encrypted = isset($_POST['encrypted']) ? $_POST['encrypted'] : '';
		$enc_key = isset($_POST['key']) ? $_POST['key'] : '';
		if (!is_string($encrypted) || !is_string($enc_key) || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $encrypted) || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $enc_key)) {
			wp_send_json_error(array('message' => 'Invalid data format.'));
			return;
		}

		$payload = $rpc->decrypt_message(
			$encrypted,
			$enc_key,
			$key_data['private_key']
		);

		if (is_wp_error($payload)) {
			// Generic error to avoid leaking internal details to unauthenticated callers
			wp_send_json_error(array('message' => 'Authentication failed.'));
			return;
		}

		// Route command
		$command = isset($payload['command']) ? $payload['command'] : '';
		$data = isset($payload['data']) ? $payload['data'] : array();

		$result = $this->dispatch_command($command, $data, $name_hash, $key_data);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
			return;
		}

		wp_send_json_success($result);
	}

	/**
	 * Handle incoming RPC messages from unauthenticated (nopriv) requests.
	 * Validates that migration keys exist and checks for HMAC pre-check header
	 * BEFORE processing any data, to reject invalid requests early.
	 */
	public function handle_rpc_message_nopriv() {
		// Require HMAC pre-check header to be present
		if (empty($_SERVER['HTTP_X_SGBACKUP_HMAC'])) {
			wp_send_json_error(array('message' => 'Missing HMAC pre-check header.'));
			return;
		}

		// Validate that migration keys exist before processing anything
		$opts = SiteGuard_Backup_Options::instance();
		$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());
		if (empty($local_keys)) {
			wp_send_json_error(array('message' => 'No migration keys configured.'));
			return;
		}

		// Validate name_hash maps to a known key before decryption
		$name_hash = isset($_POST['name_hash']) ? sanitize_text_field($_POST['name_hash']) : '';
		if (empty($name_hash) || !isset($local_keys[$name_hash])) {
			wp_send_json_error(array('message' => 'Unknown key identifier.'));
			return;
		}

		// Process the RPC message directly (skip current_user_can check since
		// authentication is done via RSA key encryption, not WordPress sessions)
		if (empty($_POST['subaction']) || $_POST['subaction'] !== 'rpc_message') return;
		if (empty($_SERVER['HTTP_X_SGBACKUP_RPC'])) return;
		if (empty($_POST['encrypted']) || empty($_POST['key'])) return;

		// Rate limiting
		$ip = isset($_SERVER['HTTP_CF_CONNECTING_IP'])
			? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'])
			: (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown');
		$rate_key = 'sgbackup_rpc_rate_' . md5($ip);
		$rate_count = (int) get_transient($rate_key);
		if ($rate_count >= 60) {
			wp_send_json_error(array('message' => 'Rate limit exceeded. Try again later.'));
			return;
		}
		set_transient($rate_key, $rate_count + 1, 60);

		$key_data = $local_keys[$name_hash];

		if (empty($key_data['private_key']) || !is_string($key_data['private_key'])) {
			wp_send_json_error(array('message' => 'Migration key data is corrupted.'));
			return;
		}

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$rpc = new SiteGuard_Backup_Remote_Communications();

		$encrypted = isset($_POST['encrypted']) ? $_POST['encrypted'] : '';
		$enc_key = isset($_POST['key']) ? $_POST['key'] : '';
		if (!is_string($encrypted) || !is_string($enc_key) || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $encrypted) || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $enc_key)) {
			wp_send_json_error(array('message' => 'Invalid data format.'));
			return;
		}

		$payload = $rpc->decrypt_message($encrypted, $enc_key, $key_data['private_key']);

		if (is_wp_error($payload)) {
			wp_send_json_error(array('message' => 'Authentication failed.'));
			return;
		}

		$command = isset($payload['command']) ? $payload['command'] : '';
		$data = isset($payload['data']) ? $payload['data'] : array();

		$result = $this->dispatch_command($command, $data, $name_hash, $key_data);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
			return;
		}

		wp_send_json_success($result);
	}

	/**
	 * Dispatch an RPC command.
	 */
	private function dispatch_command($command, $data, $name_hash, $key_data) {
		switch ($command) {
			case 'ping':
				return $this->cmd_ping($data, $name_hash);

			case 'send_chunk':
				return $this->cmd_send_chunk($data, $name_hash);

			case 'get_file_status':
				return $this->cmd_get_file_status($data);

			case 'upload_complete':
				return $this->cmd_upload_complete($data);

			default:
				return new WP_Error('unknown_command', "Unknown RPC command: {$command}");
		}
	}

	/**
	 * Ping command - responds to connectivity test.
	 */
	private function cmd_ping($data, $name_hash) {
		return array(
			'status' => 'ok',
			'plugin' => 'siteguardbackup',
			'version' => SGBACKUP_VERSION,
			'wp_version' => get_bloginfo('version'),
			'site_url' => site_url(),
		);
	}

	/**
	 * Receive a file chunk.
	 */
	private function cmd_send_chunk($data, $name_hash) {
		if (empty($data['file']) || !isset($data['data']) || !isset($data['start'])) {
			return new WP_Error('invalid_chunk', 'Missing required chunk parameters.');
		}

		// Use basename() to strip any path components, then sanitize
		$filename = sanitize_file_name(basename($data['file']));

		// Estimate decoded size before base64_decode to prevent memory abuse
		$b64_len = is_string($data['data']) ? strlen($data['data']) : 0;
		if ($b64_len > 2796203) { // ~2MB decoded = ~2.67MB base64
			return new WP_Error('chunk_too_large', 'Chunk data exceeds maximum size.');
		}

		$chunk_data = base64_decode($data['data']);
		if (false === $chunk_data) {
			return new WP_Error('invalid_data', 'Invalid base64 chunk data.');
		}

		// Cap max chunk size to 2MB to prevent memory abuse
		if (strlen($chunk_data) > 2097152) {
			return new WP_Error('chunk_too_large', 'Chunk size exceeds maximum of 2MB.');
		}

		$start = intval($data['start']);

		// Validate start offset is non-negative
		if ($start < 0) {
			return new WP_Error('invalid_start', 'Start offset must be non-negative.');
		}
		$is_last = !empty($data['last_chunk']);

		// Enforce maximum filename length
		if (strlen($filename) > 255) {
			return new WP_Error('filename_too_long', 'Filename exceeds maximum length.');
		}

		// Validate filename pattern - strict whitelist
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
			return new WP_Error('invalid_filename', 'Invalid backup filename format.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Write to .tmp file during transfer
		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		$fh = fopen($tmp_file, 'c+b');
		if (!$fh) {
			return new WP_Error('file_open', 'Cannot open temp file for writing.');
		}

		// Exclusive lock to prevent concurrent chunk writes from corrupting the file
		if (!flock($fh, LOCK_EX)) {
			fclose($fh);
			return new WP_Error('file_lock', 'Cannot lock temp file for writing.');
		}

		// Track expected next offset AFTER acquiring lock to avoid TOCTOU race
		clearstatcache(true, $tmp_file);
		$expected_offset = filesize($tmp_file);
		if ($expected_offset === false) $expected_offset = 0;
		if ($start !== $expected_offset) {
			flock($fh, LOCK_UN);
			fclose($fh);
			return new WP_Error('out_of_sequence', sprintf('Out-of-sequence write: expected offset %d but got %d.', $expected_offset, $start));
		}

		fseek($fh, $start);
		$written = fwrite($fh, $chunk_data);
		flock($fh, LOCK_UN);
		fclose($fh);

		if (false === $written) {
			return new WP_Error('file_write', 'Failed to write chunk data.');
		}

		$result = array(
			'size'   => filesize($tmp_file),
			'status' => 0, // 0 = incomplete
		);

		// On last chunk, rename to final filename
		if ($is_last) {
			if (file_exists($final_file)) {
				@unlink($final_file);
			}
			if (!rename($tmp_file, $final_file)) {
				return new WP_Error('rename_failed', 'Failed to finalize uploaded file: rename from .tmp failed.');
			}

			$result['status'] = 1; // 1 = complete
			$result['size'] = filesize($final_file);

			// Register in backup history
			$this->register_received_file($filename);
		}

		return $result;
	}

	/**
	 * Get file status - check if a file exists or is partially uploaded.
	 */
	private function cmd_get_file_status($data) {
		if (empty($data['file'])) {
			return new WP_Error('missing_file', 'Missing file parameter.');
		}

		$filename = sanitize_file_name(basename($data['file']));

		// Validate filename pattern (same whitelist as cmd_send_chunk)
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
			return new WP_Error('invalid_filename', 'Invalid backup filename format.');
		}
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		if (file_exists($final_file)) {
			return array('size' => filesize($final_file), 'status' => 1);
		}

		if (file_exists($tmp_file)) {
			return array('size' => filesize($tmp_file), 'status' => 0);
		}

		return array('size' => 0, 'status' => 0);
	}

	/**
	 * Upload complete - acknowledge that all files for a backup have been received.
	 */
	private function cmd_upload_complete($data) {
		$filename = isset($data['file']) ? sanitize_file_name($data['file']) : '';
		return array('status' => 'acknowledged', 'file' => $filename);
	}

	/**
	 * Register a received file in backup history.
	 */
	private function register_received_file($filename) {
		// Parse filename: backup_{date}_{site}_{nonce}-{entity}.{ext}
		if (!preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_([^_]+)_([a-zA-Z0-9]+)/', $filename, $m)) {
			error_log("SiteGuard Backup: Failed to register received file (filename doesn't match pattern): {$filename}");
			return;
		}

		$date_str = $m[1]; // e.g., 2026-03-01-0300
		$nonce = $m[3];

		// Parse timestamp
		$timestamp = strtotime(
			substr($date_str, 0, 10) . ' ' .
			substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2) . ':00'
		);

		if (!$timestamp) $timestamp = time();

		// Determine entity type
		$entity = 'unknown';
		foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
			if (strpos($filename, "-{$e}") !== false) {
				$entity = $e;
				break;
			}
		}

		$history = SiteGuard_Backup_History::get_history_raw();

		if (!isset($history[$timestamp])) {
			$history[$timestamp] = array(
				'nonce'   => $nonce,
				'service' => array('remotesend'),
			);
		}

		if (!isset($history[$timestamp][$entity])) {
			$history[$timestamp][$entity] = array();
		}

		if (is_string($history[$timestamp][$entity])) {
			$history[$timestamp][$entity] = array($history[$timestamp][$entity]);
		}

		if (!in_array($filename, $history[$timestamp][$entity])) {
			$history[$timestamp][$entity][] = $filename;
		}

		SiteGuard_Backup_History::save_history($history);
	}
}
