<?php
/**
 * SiteGuard Backup - Semaphore/Lock Management
 *
 * Prevents concurrent backup operations via database-backed locks.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Semaphore {

	private $lock_name;
	private $lock_timeout;
	private $locked = false;
	private $lock_value = '';

	/**
	 * @param string $lock_name    Unique identifier for the lock.
	 * @param int    $lock_timeout Seconds before lock auto-expires.
	 */
	public function __construct($lock_name = 'sgbackup_lock', $lock_timeout = 900) {
		// Namespace lock to prevent conflicts with other plugins
		if (strpos($lock_name, 'sgbackup_') !== 0) {
			$lock_name = 'sgbackup_' . $lock_name;
		}
		$this->lock_name = $lock_name;
		$this->lock_timeout = $lock_timeout;
	}

	/**
	 * Attempt to acquire the lock.
	 *
	 * Uses atomic INSERT ... ON DUPLICATE KEY UPDATE with a WHERE-style check
	 * on lock_value to eliminate TOCTOU race conditions. The UPDATE only succeeds
	 * if the existing lock value matches what we expect (expired or absent).
	 *
	 * @return bool True if lock acquired.
	 */
	public function lock() {
		global $wpdb;

		$now = time();
		$option_name = $this->lock_name;
		$lock_value = wp_generate_password(16, false);
		// Pipe-delimited format: lock_value|timestamp (MySQL 5.6 compatible, no JSON_EXTRACT)
		$new_data = $lock_value . '|' . $now;
		$expired_before = $now - $this->lock_timeout;

		// Use wp_options (which has a UNIQUE KEY on option_name) for the lock,
		// even on multisite. wp_sitemeta lacks a UNIQUE KEY on (meta_key, site_id),
		// so INSERT ON DUPLICATE KEY UPDATE never fires there.
		$table = $wpdb->options;
		$name_col = 'option_name';
		$value_col = 'option_value';

		$result = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$table} ({$name_col}, {$value_col}, autoload)
			 VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE
			   {$value_col} = IF(
			     CAST(SUBSTRING_INDEX({$value_col}, '|', -1) AS UNSIGNED) < %d,
			     VALUES({$value_col}),
			     {$value_col}
			   )",
			$option_name,
			$new_data,
			$expired_before
		));

		// Clear cache and verify we actually hold the lock
		wp_cache_delete($option_name, 'options');
		$check = get_option($option_name);

		// Parse pipe-delimited format: lock_value|timestamp
		$check_lock_value = (is_string($check) && strpos($check, '|') !== false)
			? substr($check, 0, strrpos($check, '|'))
			: '';

		if ($check_lock_value === $lock_value) {
			$this->locked = true;
			$this->lock_value = $lock_value;
			return true;
		}

		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @return bool
	 */
	public function unlock() {
		if (!$this->locked) return false;
		global $wpdb;
		// Always use wp_options for locks (see lock() comment)
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
			$this->lock_name,
			$wpdb->esc_like($this->lock_value) . '|%'
		));
		wp_cache_delete($this->lock_name, 'options');
		$this->locked = false;
		$this->lock_value = '';
		return true;
	}

	/**
	 * Check if we hold the lock.
	 *
	 * @return bool
	 */
	public function is_locked() {
		return $this->locked;
	}

	/**
	 * Force-release the lock regardless of who holds it.
	 */
	public function force_unlock() {
		global $wpdb;
		// Always use wp_options for locks (see lock() comment)
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s",
			$this->lock_name
		));
		wp_cache_delete($this->lock_name, 'options');
		$this->locked = false;
	}

	/**
	 * Check if the lock is currently free (not held by anyone).
	 *
	 * @return bool True if the lock is free.
	 */
	public function is_free() {
		$check = get_option($this->lock_name);
		if (empty($check)) return true;
		// Parse pipe-delimited format: lock_value|timestamp
		if (!is_string($check) || strpos($check, '|') === false) return true;
		$lock_time = intval(substr($check, strrpos($check, '|') + 1));
		if ($lock_time <= 0) return true;
		// Check if expired
		return (time() - $lock_time) > $this->lock_timeout;
	}

	public function __destruct() {
		if ($this->locked) {
			$this->unlock();
		}
	}
}
