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
		if (empty($_POST['subaction']) || $_POST['subaction'] !== 'rpc_message') return;

		error_log("SiteGuard Backup RPC Receiver: handle_rpc_message entered (authenticated path)");

		if (!current_user_can('manage_options')) {
			error_log("SiteGuard Backup RPC Receiver: rejected - insufficient permissions");
			wp_send_json_error(array('message' => 'Insufficient permissions.'));
			return;
		}

		// Verify required RPC header is present
		if (empty($_SERVER['HTTP_X_SGBACKUP_RPC'])) {
			error_log("SiteGuard Backup RPC Receiver: rejected - missing X-SGBackup-RPC header");
			return;
		}

		if (empty($_POST['encrypted']) || empty($_POST['key'])) {
			error_log("SiteGuard Backup RPC Receiver: rejected - missing encrypted/key POST data");
			return;
		}

		// Rate limiting: max 600 requests per 60-second sliding window per IP.
		// Uses a stored window start time so the counter resets properly even
		// during sustained transfers (set_transient alone resets TTL on every
		// call, which would make the counter never expire).
		$ip = isset($_SERVER['HTTP_CF_CONNECTING_IP'])
			? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'])
			: (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown');
		$rate_key = 'sgbackup_rpc_rate_' . md5($ip);
		$rate_data = get_transient($rate_key);
		if (!is_array($rate_data) || !isset($rate_data['t']) || (time() - $rate_data['t']) >= 60) {
			$rate_data = array('c' => 0, 't' => time());
		}
		$rate_data['c']++;
		if ($rate_data['c'] > 600) {
			wp_send_json_error(array('message' => 'Rate limit exceeded. Try again later.'));
			return;
		}
		set_transient($rate_key, $rate_data, 120);

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

		// Rate limiting (shares counter with authenticated path above)
		$ip = isset($_SERVER['HTTP_CF_CONNECTING_IP'])
			? sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'])
			: (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown');
		$rate_key = 'sgbackup_rpc_rate_' . md5($ip);
		$rate_data = get_transient($rate_key);
		if (!is_array($rate_data) || !isset($rate_data['t']) || (time() - $rate_data['t']) >= 60) {
			$rate_data = array('c' => 0, 't' => time());
		}
		$rate_data['c']++;
		if ($rate_data['c'] > 600) {
			wp_send_json_error(array('message' => 'Rate limit exceeded. Try again later.'));
			return;
		}
		set_transient($rate_key, $rate_data, 120);

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
		error_log("SiteGuard Backup RPC Receiver: command='{$command}' name_hash={$name_hash}");

		switch ($command) {
			case 'ping':
				return $this->cmd_ping($data, $name_hash);

			case 'send_chunk':
				return $this->cmd_send_chunk($data, $name_hash);

			case 'get_file_status':
				return $this->cmd_get_file_status($data);

			case 'upload_complete':
				return $this->cmd_upload_complete($data);

			case 'set_backup_metadata':
				return $this->cmd_set_backup_metadata($data);

			default:
				error_log("SiteGuard Backup RPC Receiver: unknown command '{$command}'");
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
			error_log("SiteGuard Backup RPC Receiver: send_chunk missing params - file=" . (isset($data['file']) ? 'yes' : 'no') . " data=" . (isset($data['data']) ? 'yes' : 'no') . " start=" . (isset($data['start']) ? 'yes' : 'no'));
			return new WP_Error('invalid_chunk', 'Missing required chunk parameters.');
		}

		// Use basename() to strip any path components, then sanitize
		$filename = sanitize_file_name(basename($data['file']));
		error_log("SiteGuard Backup RPC Receiver: send_chunk file={$filename} start={$data['start']} last=" . (!empty($data['last_chunk']) ? 'yes' : 'no'));

		// Estimate decoded size before base64_decode to prevent memory abuse.
		// 2MB decoded = ceil(2097152 * 4/3) = 2796204 bytes base64 (with padding).
		$b64_len = is_string($data['data']) ? strlen($data['data']) : 0;
		if ($b64_len > 2796204) {
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
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|json|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
			error_log("SiteGuard Backup RPC Receiver: rejected filename '{$filename}' - does not match whitelist pattern");
			return new WP_Error('invalid_filename', 'Invalid backup filename format.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Write to .tmp file during transfer
		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		// Use database-backed lock instead of flock() - CIFS/SMB mounts
		// (Azure App Service, NAS) don't support POSIX file locking.
		$chunk_lock_key = 'sgbackup_chunk_' . md5($filename);
		$lock_acquired = false;
		for ($attempt = 0; $attempt < 5; $attempt++) {
			if (false === get_transient($chunk_lock_key)) {
				set_transient($chunk_lock_key, time(), 30);
				$lock_acquired = true;
				break;
			}
			usleep(200000); // 200ms
		}
		if (!$lock_acquired) {
			return new WP_Error('file_lock', 'Cannot acquire chunk write lock.');
		}

		$fh = fopen($tmp_file, 'c+b');
		if (!$fh) {
			delete_transient($chunk_lock_key);
			return new WP_Error('file_open', 'Cannot open temp file for writing.');
		}

		// Track expected next offset
		clearstatcache(true, $tmp_file);
		$expected_offset = filesize($tmp_file);
		if ($expected_offset === false) $expected_offset = 0;
		if ($start !== $expected_offset) {
			fclose($fh);
			delete_transient($chunk_lock_key);
			return new WP_Error('out_of_sequence', sprintf('Out-of-sequence write: expected offset %d but got %d.', $expected_offset, $start));
		}

		fseek($fh, $start);
		$written = fwrite($fh, $chunk_data);
		fclose($fh);
		delete_transient($chunk_lock_key);

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
			if (!@rename($tmp_file, $final_file)) {
				// rename fails across mount points (e.g. /tmp to /home on Azure)
				if (!@copy($tmp_file, $final_file) || !@unlink($tmp_file)) {
					return new WP_Error('rename_failed', 'Failed to finalize uploaded file.');
				}
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
		if (!preg_match('/^backup_[\w\-]+\.(zip|gz|json|sql|sql\.gz|sql\.bz2|tar|tar\.gz|tar\.bz2|txt)$/', $filename)) {
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
	 * Set metadata (label, always_keep, source_url) on a received backup.
	 */
	private function cmd_set_backup_metadata($data) {
		$timestamp = isset($data['timestamp']) ? intval($data['timestamp']) : 0;
		$nonce = isset($data['nonce']) ? sanitize_text_field($data['nonce']) : '';

		if (empty($timestamp) && empty($nonce)) {
			return new WP_Error('missing_param', 'Missing timestamp or nonce');
		}

		$history = SiteGuard_Backup_History::get_history_raw();
		$target_ts = null;

		// Try exact timestamp match first
		if ($timestamp && isset($history[$timestamp])) {
			$target_ts = $timestamp;
		}

		// Fall back to nonce match
		if (!$target_ts && !empty($nonce)) {
			foreach ($history as $ts => $entry) {
				if (isset($entry['nonce']) && $entry['nonce'] === $nonce) {
					$target_ts = $ts;
					break;
				}
			}
		}

		if (!$target_ts) {
			return new WP_Error('not_found', 'No matching backup found in history');
		}

		$changed = false;
		if (!empty($data['label'])) {
			$history[$target_ts]['label'] = sanitize_text_field(substr($data['label'], 0, 40));
			$changed = true;
		}
		if (isset($data['always_keep'])) {
			$history[$target_ts]['always_keep'] = (bool) $data['always_keep'];
			$changed = true;
		}
		if (!empty($data['source_url'])) {
			$history[$target_ts]['source_url'] = esc_url_raw($data['source_url']);
			$changed = true;
		}
		if (!empty($data['is_subsite_backup'])) {
			$history[$target_ts]['is_subsite_backup'] = true;
			$history[$target_ts]['subsite_blog_id'] = isset($data['subsite_blog_id']) ? intval($data['subsite_blog_id']) : 0;
			$history[$target_ts]['subsite_domain'] = isset($data['subsite_domain']) ? sanitize_text_field($data['subsite_domain']) : '';
			$changed = true;
		}

		if ($changed) {
			SiteGuard_Backup_History::save_history($history);
		}

		return array('status' => 'ok', 'timestamp' => $target_ts);
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
