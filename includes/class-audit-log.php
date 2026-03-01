<?php
/**
 * SiteGuard Backup - Security Audit Log
 *
 * Optional, opt-in security feature that records administrative actions
 * (backup, restore, delete, settings changes, downloads) with user identity,
 * IP address, and timestamps. Stored in wp_options/sitemeta with automatic
 * truncation to prevent unbounded growth.
 *
 * Enable via: Settings > SiteGuard Backup > Advanced > Security Audit Log toggle,
 * or WP-CLI: wp option update sgbackup_audit_log_enabled 1
 *
 * This feature does NOT run automatically, does NOT modify backups, and does NOT
 * affect any existing functionality. It only passively records events when enabled.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Audit_Log {

	/** @var int Maximum log entries before auto-truncation */
	const MAX_ENTRIES = 500;

	/** @var string Option key for the log data */
	const LOG_KEY = 'sgbackup_audit_log';

	/** @var string Option key for enabled state */
	const ENABLED_KEY = 'sgbackup_audit_log_enabled';

	/** @var SiteGuard_Backup_Audit_Log */
	private static $instance = null;

	/** @var bool Cached enabled state */
	private $enabled = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if audit logging is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		if (null === $this->enabled) {
			$opts = SiteGuard_Backup_Options::instance();
			$this->enabled = (bool) $opts->get_option(self::ENABLED_KEY, false);
		}
		return $this->enabled;
	}

	/**
	 * Enable or disable audit logging.
	 *
	 * @param bool $enabled
	 */
	public function set_enabled($enabled) {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option(self::ENABLED_KEY, (bool) $enabled);
		$this->enabled = (bool) $enabled;

		if ($enabled) {
			$this->log('audit_log_enabled', 'Security audit logging has been enabled.');
		}
	}

	/**
	 * Record an audit event.
	 *
	 * @param string $action  Action identifier (e.g., 'backup_started', 'restore_completed').
	 * @param string $details Human-readable description of what happened.
	 * @param array  $extra   Optional key-value metadata (e.g., backup timestamp, file name).
	 * @return bool True if logged, false if disabled or failed.
	 */
	public function log($action, $details = '', $extra = array()) {
		if (!$this->is_enabled()) {
			return false;
		}

		$user_id = 0;
		$user_login = 'system';

		if (function_exists('get_current_user_id')) {
			$user_id = get_current_user_id();
			if ($user_id > 0) {
				$user = get_userdata($user_id);
				$user_login = $user ? $user->user_login : 'unknown';
			}
		}

		// CLI context
		if (defined('WP_CLI') && WP_CLI && $user_login === 'system') {
			$user_login = 'wp-cli';
		}

		$entry = array(
			'timestamp'  => time(),
			'action'     => sanitize_key($action),
			'details'    => substr($details, 0, 500), // Cap detail length
			'user_id'    => $user_id,
			'user_login' => $user_login,
			'ip'         => $this->get_client_ip(),
		);

		if (!empty($extra)) {
			// Sanitize and cap extra metadata
			$clean_extra = array();
			foreach ($extra as $k => $v) {
				$k = sanitize_key($k);
				if (is_scalar($v)) {
					$clean_extra[$k] = substr((string) $v, 0, 200);
				}
			}
			$entry['extra'] = $clean_extra;
		}

		return $this->append_entry($entry);
	}

	/**
	 * Get audit log entries.
	 *
	 * @param array $filters Optional filters: 'action', 'user_id', 'since' (unix timestamp), 'limit'.
	 * @return array Log entries, newest first.
	 */
	public function get_entries($filters = array()) {
		$opts = SiteGuard_Backup_Options::instance();
		$log = $opts->get_option(self::LOG_KEY, array());
		if (!is_array($log)) {
			$log = array();
		}

		// Apply filters
		if (!empty($filters['action'])) {
			$action = sanitize_key($filters['action']);
			$log = array_filter($log, function($entry) use ($action) {
				return isset($entry['action']) && $entry['action'] === $action;
			});
		}

		if (!empty($filters['user_id'])) {
			$uid = intval($filters['user_id']);
			$log = array_filter($log, function($entry) use ($uid) {
				return isset($entry['user_id']) && intval($entry['user_id']) === $uid;
			});
		}

		if (!empty($filters['since'])) {
			$since = intval($filters['since']);
			$log = array_filter($log, function($entry) use ($since) {
				return isset($entry['timestamp']) && intval($entry['timestamp']) >= $since;
			});
		}

		// Sort newest first
		usort($log, function($a, $b) {
			return intval($b['timestamp']) - intval($a['timestamp']);
		});

		// Apply limit
		if (!empty($filters['limit'])) {
			$log = array_slice($log, 0, intval($filters['limit']));
		}

		return $log;
	}

	/**
	 * Clear all audit log entries.
	 *
	 * @return bool
	 */
	public function clear() {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option(self::LOG_KEY, array());
		return $this->log('audit_log_cleared', 'Audit log was cleared.');
	}

	/**
	 * Get the total number of log entries.
	 *
	 * @return int
	 */
	public function count() {
		$opts = SiteGuard_Backup_Options::instance();
		$log = $opts->get_option(self::LOG_KEY, array());
		return is_array($log) ? count($log) : 0;
	}

	/**
	 * Append an entry and auto-truncate if over MAX_ENTRIES.
	 *
	 * @param array $entry
	 * @return bool
	 */
	private function append_entry($entry) {
		$opts = SiteGuard_Backup_Options::instance();
		$log = $opts->get_option(self::LOG_KEY, array());
		if (!is_array($log)) {
			$log = array();
		}

		$log[] = $entry;

		// Truncate oldest entries if over limit
		if (count($log) > self::MAX_ENTRIES) {
			// Sort by timestamp ascending, keep newest MAX_ENTRIES
			usort($log, function($a, $b) {
				return intval($a['timestamp']) - intval($b['timestamp']);
			});
			$log = array_slice($log, -self::MAX_ENTRIES);
		}

		return $opts->update_option(self::LOG_KEY, $log);
	}

	/**
	 * Get client IP address with proxy awareness.
	 *
	 * @return string Sanitized IP address.
	 */
	private function get_client_ip() {
		$ip = '0.0.0.0';

		// Cloudflare
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Take the first (client) IP from the chain
			$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
			$ip = trim($parts[0]);
		} elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
			$ip = $_SERVER['HTTP_X_REAL_IP'];
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}

		// Validate it's actually an IP
		$ip = filter_var($ip, FILTER_VALIDATE_IP);
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Get human-readable label for an action.
	 *
	 * @param string $action
	 * @return string
	 */
	public static function get_action_label($action) {
		$labels = array(
			'backup_started'       => 'Backup Started',
			'backup_completed'     => 'Backup Completed',
			'backup_failed'        => 'Backup Failed',
			'restore_started'      => 'Restore Started',
			'restore_completed'    => 'Restore Completed',
			'restore_failed'       => 'Restore Failed',
			'delete_backup'        => 'Backup Deleted',
			'settings_saved'       => 'Settings Saved',
			'settings_wiped'       => 'Settings Wiped',
			'download_backup'      => 'Backup Downloaded',
			'upload_backup'        => 'Backup Uploaded',
			'upload_to_remote'     => 'Uploaded to Remote',
			'migration_sent'       => 'Migration Sent',
			'migration_received'   => 'Migration Received',
			'unlock_attempt'       => 'Unlock Attempt',
			'unlock_success'       => 'Unlock Success',
			'unlock_failed'        => 'Unlock Failed',
			'encryption_key_changed' => 'Encryption Key Changed',
			'settings_imported'    => 'Settings Imported',
			'settings_exported'    => 'Settings Exported',
			'search_replace'       => 'Search & Replace',
			'audit_log_enabled'    => 'Audit Log Enabled',
			'audit_log_disabled'   => 'Audit Log Disabled',
			'audit_log_cleared'    => 'Audit Log Cleared',
			'rate_limit_hit'       => 'Rate Limit Triggered',
		);

		return isset($labels[$action]) ? $labels[$action] : ucwords(str_replace('_', ' ', $action));
	}
}
