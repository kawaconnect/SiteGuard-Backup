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
		// Pipe-delimited format: lock_value|timestamp|timeout (MySQL 5.6 compatible,
		// no JSON_EXTRACT). Storing the timeout lets any instance judge expiry using
		// the CREATOR's timeout rather than its own (see is_free()).
		$new_data = $lock_value . '|' . $now . '|' . $this->lock_timeout;

		// Use wp_options (which has a UNIQUE KEY on option_name) for the lock,
		// even on multisite. wp_sitemeta lacks a UNIQUE KEY on (meta_key, site_id),
		// so INSERT ON DUPLICATE KEY UPDATE never fires there.
		$table = $wpdb->options;
		$name_col = 'option_name';
		$value_col = 'option_value';

		// Overwrite only if the existing lock has expired, judged by its OWN stored
		// timestamp + timeout. Field layout: value|timestamp|timeout.
		//   stored_ts      = 2nd field = SUBSTRING_INDEX(SUBSTRING_INDEX(v,'|',-2),'|',1)
		//   stored_timeout = 3rd field = SUBSTRING_INDEX(v,'|',-1)
		// For legacy 2-field locks (value|timestamp) the nested extraction yields
		// the lock_value (CAST → 0) and the timestamp respectively, so they are
		// treated as immediately overwritable - acceptable for the brief upgrade
		// window since these locks are short-lived.
		$result = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$table} ({$name_col}, {$value_col}, autoload)
			 VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE
			   {$value_col} = IF(
			     CAST(SUBSTRING_INDEX(SUBSTRING_INDEX({$value_col}, '|', -2), '|', 1) AS UNSIGNED)
			       + CAST(SUBSTRING_INDEX({$value_col}, '|', -1) AS UNSIGNED) < %d,
			     VALUES({$value_col}),
			     {$value_col}
			   )",
			$option_name,
			$new_data,
			$now
		));

		// Verify we actually hold the lock. Read the row directly: get_option()
		// consults the 'notoptions' cache, which a previous miss (is_free() from
		// CLI, or a persistent object cache) can poison - the raw INSERT above
		// never clears it, so a successfully written lock read back as "absent".
		wp_cache_delete($option_name, 'options');
		$check = $wpdb->get_var($wpdb->prepare("SELECT {$value_col} FROM {$table} WHERE {$name_col} = %s", $option_name));
		$notoptions = wp_cache_get('notoptions', 'options');
		if (is_array($notoptions) && isset($notoptions[$option_name])) {
			unset($notoptions[$option_name]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}

		// Parse pipe-delimited format: lock_value is the FIRST field (works for
		// both value|timestamp and value|timestamp|timeout layouts).
		$check_lock_value = (is_string($check) && strpos($check, '|') !== false)
			? substr($check, 0, strpos($check, '|'))
			: '';

		if ($check_lock_value === $lock_value) {
			$this->locked = true;
			$this->lock_value = $lock_value;
			return true;
		}

		return false;
	}

	/**
	 * Try to acquire the lock for up to $max_seconds.
	 *
	 * The history lock is held only for a read-modify-write, so waiting a little
	 * is always better than writing without it (which silently lost entries).
	 *
	 * @return bool True if acquired. On false, callers log and continue as before.
	 */
	public function lock_wait($max_seconds = 15) {
		$deadline = microtime(true) + $max_seconds;
		while (!$this->lock()) {
			if (microtime(true) >= $deadline) {
				error_log("SiteGuard Backup: lock '{$this->lock_name}' still busy after {$max_seconds}s; continuing without it");
				return false;
			}
			usleep(250000);
		}
		return true;
	}

	/**
	 * Release the lock.
	 *
	 * @return bool
	 */
	/** When this lock's timestamp was last pushed forward (throttles refresh()). */
	private $refreshed_at = 0;

	/**
	 * Push the lock's timestamp forward while its holder is still working.
	 *
	 * The timestamp was only written when the lock was taken, so a job step that
	 * ran longer than the timeout (a large upload, encryption) looked abandoned:
	 * a second resumption took the "expired" lock and both processes worked on
	 * the same files. Only the holder (same lock value) can refresh. At most one
	 * query per minute.
	 *
	 * @return bool False when this process no longer holds the lock.
	 */
	public function refresh() {
		if (!$this->locked) return false;
		$now = time();
		if ($now - $this->refreshed_at < 60) return true;
		global $wpdb;
		$wpdb->query($wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
			$this->lock_value . '|' . $now . '|' . $this->lock_timeout,
			$this->lock_name,
			$wpdb->esc_like($this->lock_value) . '|%'
		));
		wp_cache_delete($this->lock_name, 'options');
		$this->refreshed_at = $now;
		return true;
	}

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
		global $wpdb;
		// Direct read: see lock() for why get_option() is unreliable here.
		$check = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name));
		if (empty($check)) return true;
		if (!is_string($check) || strpos($check, '|') === false) return true;
		// Parse pipe-delimited format: lock_value|timestamp[|timeout]
		$parts = explode('|', $check);
		$lock_time = isset($parts[1]) ? intval($parts[1]) : 0;
		if ($lock_time <= 0) return true;
		// Prefer the timeout stored by the lock's CREATOR; fall back to this
		// instance's timeout only for legacy 2-field locks.
		$timeout = isset($parts[2]) ? intval($parts[2]) : $this->lock_timeout;
		if ($timeout <= 0) $timeout = $this->lock_timeout;
		return (time() - $lock_time) > $timeout;
	}

	public function __destruct() {
		if ($this->locked) {
			$this->unlock();
		}
	}

	/**
	 * Enumerate every lock this plugin can hold, with age and expiry state.
	 *
	 * @return array name => ['held_since' => ts, 'timeout' => s, 'expired' => bool]
	 */
	public static function list_locks() {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE %s OR option_name IN ('sgbackup_boot', 'sgbackup_history', 'sgbackup_restore', 'sgbackup_lock')",
			$wpdb->esc_like('sgbackup_sem_') . '%'
		), ARRAY_A);
		$out = array();
		if (!is_array($rows)) return $out;
		foreach ($rows as $r) {
			$v = (string) $r['option_value'];
			if (strpos($v, '|') === false) continue;
			$parts = explode('|', $v);
			$ts = isset($parts[1]) ? (int) $parts[1] : 0;
			$timeout = isset($parts[2]) ? (int) $parts[2] : 900;
			if ($timeout <= 0) $timeout = 900;
			$out[$r['option_name']] = array(
				'held_since' => $ts,
				'timeout'    => $timeout,
				'expired'    => ($ts <= 0) || (time() - $ts) > $timeout,
			);
		}
		return $out;
	}

	/**
	 * Force-release every lock (all names). Returns the number removed.
	 */
	public static function clear_all_locks() {
		$count = 0;
		foreach (array_keys(self::list_locks()) as $name) {
			$sem = new self($name);
			$sem->force_unlock();
			$count++;
		}
		return $count;
	}
}
