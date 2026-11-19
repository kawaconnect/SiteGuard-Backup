<?php
/**
 * SiteGuard Backup - Backup History Management
 *
 * Tracks backup sets in wp_options / wp_sitemeta.
 * History format: array keyed by unix timestamp, each containing nonce, file lists, sizes.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_History {

	/**
	 * Get backup history, optionally filtered to a specific timestamp.
	 *
	 * @param int|false $timestamp Specific timestamp or false for all.
	 * @return array
	 */
	public static function get_history($timestamp = false) {
		$opts = SiteGuard_Backup_Options::instance();
		$history = $opts->get_option('sgbackup_backup_history', array());

		if (!is_array($history)) $history = array();

		// Sort newest first
		krsort($history);

		// Merge incremental sets into a COPY for display purposes.
		// Do NOT merge in-place - callers that save_history() back would
		// persist the merged data, causing unbounded duplicate file entries.
		$merged = self::build_incremental_sets_copy($history);

		if (false !== $timestamp) {
			return isset($merged[$timestamp]) ? $merged[$timestamp] : array();
		}

		return $merged;
	}

	/**
	 * Get raw (unmerged) history for read-modify-write operations.
	 * Callers that intend to save_history() should use this method.
	 */
	public static function get_history_raw() {
		$opts = SiteGuard_Backup_Options::instance();
		$history = $opts->get_option('sgbackup_backup_history', array());
		if (!is_array($history)) $history = array();
		krsort($history);
		return $history;
	}

	/**
	 * Save backup history.
	 */
	public static function save_history($history) {
		$opts = SiteGuard_Backup_Options::instance();
		krsort($history);
		$opts->update_option('sgbackup_backup_history', $history, false);
	}

	/**
	 * Get a backup set by its nonce.
	 *
	 * @param string $nonce The backup job nonce.
	 * @return array|false Backup set with 'timestamp' key added, or false.
	 */
	public static function get_backup_set_by_nonce($nonce) {
		// Sanitize nonce to alphanumeric, underscore, hyphen only
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		$history = self::get_history();
		foreach ($history as $timestamp => $backup) {
			if (isset($backup['nonce']) && $backup['nonce'] === $nonce) {
				$backup['timestamp'] = $timestamp;
				return $backup;
			}
		}
		return false;
	}

	/**
	 * Add or update a backup set in history.
	 *
	 * @param int   $timestamp Unix timestamp.
	 * @param array $backup_data Backup set data.
	 */
	public static function add_backup($timestamp, $backup_data) {
		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
			// Flush cache to ensure fresh data inside the locked section
			wp_cache_delete('sgbackup_backup_history', 'options');
			wp_cache_delete('sgbackup_backup_history', 'site-options');
			$history = self::get_history_raw();
			if (isset($history[$timestamp])) {
				$history[$timestamp] = array_merge($history[$timestamp], $backup_data);
			} else {
				$history[$timestamp] = $backup_data;
			}
			self::save_history($history);
		} finally {
			$lock->unlock();
		}
	}

	/**
	 * Remove a backup set from history.
	 *
	 * @param int $timestamp
	 */
	public static function remove_backup($timestamp) {
		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
			// Flush cache to ensure fresh data inside the locked section
			wp_cache_delete('sgbackup_backup_history', 'options');
			wp_cache_delete('sgbackup_backup_history', 'site-options');
			$history = self::get_history_raw();
			if (isset($history[$timestamp])) {
				unset($history[$timestamp]);
				self::save_history($history);
			}
		} finally {
			$lock->unlock();
		}
	}

	/**
	 * Delete backup files from disk and optionally from remote storage.
	 *
	 * @param int  $timestamp
	 * @param bool $delete_remote Whether to delete from remote storage too.
	 * @return array List of deleted files.
	 */
	public static function delete_backup_set($timestamp, $delete_remote = false) {
		$history = self::get_history();
		if (!isset($history[$timestamp])) return array();

		$backup = $history[$timestamp];
		$deleted = array();
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Collect all files, minus any another history entry still uses (a crafted
		// or corrupt entry must never be able to delete someone else's archives).
		$files = array_values(array_diff(self::get_all_files($backup), self::files_referenced_elsewhere($timestamp)));

		foreach ($files as $file) {
			$path = $backup_dir . $file;
			if (file_exists($path) && true === SiteGuard_Backup_Filesystem::delete_file($path, $backup_dir)) {
				$deleted[] = $file;
			}
		}

		// Delete log file
		$nonce = isset($backup['nonce']) ? $backup['nonce'] : '';
		// Sanitize nonce to prevent path traversal or injection
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		if ($nonce) {
			$logfile = $backup_dir . 'log.' . $nonce . '.txt';
			if (file_exists($logfile)) {
				SiteGuard_Backup_Filesystem::delete_file($logfile, $backup_dir);
			}
		}

		// Delete from remote storage if requested
		if ($delete_remote && !empty($backup['service'])) {
			$services = is_array($backup['service']) ? $backup['service'] : array($backup['service']);
			foreach ($services as $service) {
				if ('none' === $service) continue;
				// Every account of the method (also disabled ones: the set may have
				// been uploaded while the account was on).
				foreach (self::service_instances($service) as $iid) {
					$method = self::get_storage_method($service, $iid);
					if (!$method || !method_exists($method, 'delete')) continue;
					try {
						$del = $method->delete($files);
						if (is_wp_error($del)) {
							SiteGuard_Backup::instance()->log('Could not delete backup ' . $timestamp . ' from ' . $service . (null !== $iid ? '/' . $iid : '') . ': ' . $del->get_error_message() . '. The files are still on that storage and are no longer tracked.', 'error');
						}
					} catch (\Throwable $e) {
						SiteGuard_Backup::instance()->log('Could not delete backup ' . $timestamp . ' from ' . $service . ': ' . $e->getMessage(), 'error');
					}
				}
			}
		}

		// Remove from history
		self::remove_backup($timestamp);

		// Clean up job data
		if ($nonce) {
			$opts->delete_option('sgbackup_jobdata_' . $nonce);
		}

		return $deleted;
	}

	/**
	 * Files referenced by any history entry other than $timestamp.
	 */
	public static function files_referenced_elsewhere($timestamp) {
		$in_use = array();
		foreach (self::get_history() as $ts => $other) {
			if ((string) $ts === (string) $timestamp) continue;
			foreach (self::get_all_files($other) as $f) {
				$in_use[$f] = true;
			}
		}
		return array_keys($in_use);
	}

	/**
	 * Get all files from a backup set.
	 */
	public static function get_all_files($backup) {
		$files = array();
		$entities = array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db');

		foreach ($entities as $entity) {
			if (!empty($backup[$entity])) {
				$entity_files = $backup[$entity];
				if (is_string($entity_files)) {
					$files[] = $entity_files;
				} elseif (is_array($entity_files)) {
					$files = array_merge($files, $entity_files);
				}
			}
		}

		// Include extradir_* entity files (extra directory backups)
		foreach ($backup as $key => $value) {
			if (strpos($key, 'extradir_') === 0 && !empty($value)) {
				$entity_files = is_string($value) ? array($value) : $value;
				if (is_array($entity_files)) {
					$files = array_merge($files, $entity_files);
				}
			}
		}

		// Include manifest file if tracked
		if (!empty($backup['manifest'])) {
			$files[] = $backup['manifest'];
		}

		// History file references are always bare filenames inside the backup
		// dir. Drop anything else so a poisoned entry can never be followed to
		// another directory by the download/delete/restore consumers.
		$files = array_filter($files, function($f) {
			return is_string($f) && $f !== '' && basename($f) === $f;
		});

		return array_unique($files);
	}

	/**
	 * Build incremental sets - merge incremental backups into a DEEP COPY of the parent.
	 * Returns a new array; the original $history is NOT modified.
	 */
	private static function build_incremental_sets_copy($history) {
		$merged = array();
		foreach ($history as $timestamp => $backup) {
			$merged[$timestamp] = $backup; // shallow copy of this entry
			if (empty($backup['incremental_sets'])) continue;

			foreach ($backup['incremental_sets'] as $inc_timestamp => $inc_data) {
				foreach (array('plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity) {
					if (!empty($inc_data[$entity])) {
						if (!isset($merged[$timestamp][$entity])) {
							$merged[$timestamp][$entity] = array();
						}
						if (is_string($merged[$timestamp][$entity])) {
							$merged[$timestamp][$entity] = array($merged[$timestamp][$entity]);
						}
						$inc_files = is_array($inc_data[$entity]) ? $inc_data[$entity] : array($inc_data[$entity]);
						$merged[$timestamp][$entity] = array_merge($merged[$timestamp][$entity], $inc_files);
					}
				}
			}
		}
		return $merged;
	}

	/**
	 * Merge an incremental backup into an existing backup set.
	 */
	public static function merge_incremental($parent_timestamp, $incremental_data) {
		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
			$history = self::get_history_raw();
			if (!isset($history[$parent_timestamp])) return false;

			$inc_timestamp = time();
			if (!isset($history[$parent_timestamp]['incremental_sets'])) {
				$history[$parent_timestamp]['incremental_sets'] = array();
			}

			$history[$parent_timestamp]['incremental_sets'][$inc_timestamp] = $incremental_data;
			self::save_history($history);

			return $inc_timestamp;
		} finally {
			$lock->unlock();
		}
	}

	/**
	 * Prune old backups based on retention settings.
	 *
	 * @param string $type 'full' or 'db'
	 */
	public static function prune($type = 'full') {
		$opts = SiteGuard_Backup_Options::instance();
		$retain = ($type === 'db')
			? (int) $opts->get_option('sgbackup_retain_db', 2)
			: (int) $opts->get_option('sgbackup_retain', 2);

		if ($retain < 1) return;

		$history = self::get_history_raw();
		krsort($history); // newest first - retention keeps the most recent N
		$count = 0;
		$seen = 0;
		$to_delete = array();

		foreach ($history as $timestamp => $backup) {
			// Skip manual "always keep" backups
			if (!empty($backup['always_keep'])) continue;

			// For DB-only pruning, only count sets that have DB
			if ($type === 'db' && empty($backup['db'])) continue;

			// For full pruning, only count sets that have files
			if ($type === 'full' && empty(self::file_entity_keys($backup))) continue;

			// An incomplete set (an entity failed, or tables could not be dumped) is
			// not one of the N copies to keep: two bad runs in a row used to push out
			// the last complete backup. It is still removed once N complete sets are
			// newer than it.
			// Only failures that matter for this type: a set with a good database
			// dump and a failed uploads archive is a complete database copy.
			$failed = array_map('strval', (array) (isset($backup['failed_entities']) ? $backup['failed_entities'] : array()));
			$db_failed = false;
			foreach ($failed as $f) { if (0 === strpos($f, 'database')) { $db_failed = true; break; } }
			$file_failed = count($failed) > ($db_failed ? 1 : 0);
			$incomplete = ($type === 'db') ? $db_failed : $file_failed;
			// N complete sets are kept. Incomplete sets are kept only while fewer
			// than N complete ones are newer, and at most N of them, so an entity
			// that fails on every run cannot stop retention; a complete set is
			// never deleted to make room for incomplete ones.
			if ($count >= $retain || ($incomplete && $seen >= $retain)) {
				$to_delete[] = $timestamp;
				continue;
			}
			if ($incomplete) {
				$seen++;
			} else {
				$count++;
			}
		}

		// Delete only the pruned TYPE's files. Deleting the whole set made the two
		// retention settings destroy each other's copies (retain=2, retain_db=10
		// still left only 2 DB dumps). The set is removed once nothing is left.
		foreach ($to_delete as $timestamp) {
			$entity_keys = ($type === 'db') ? array('db') : self::file_entity_keys($history[$timestamp]);
			self::delete_backup_entities($timestamp, $entity_keys, true);
		}
	}

	/**
	 * Keys of the file-archive entities present in a set.
	 */
	public static function file_entity_keys($backup) {
		$keys = array();
		if (!is_array($backup)) return $keys;
		foreach ($backup as $k => $v) {
			if (empty($v)) continue;
			if (in_array($k, array('plugins', 'themes', 'uploads', 'others', 'wpcore'), true) || strpos($k, 'extradir_') === 0) {
				$keys[] = $k;
			}
		}
		return $keys;
	}

	/**
	 * Delete only some entities of a set (local + remote). Removes the whole set
	 * (log, jobdata, manifest, history row) when no entity remains.
	 */
	public static function delete_backup_entities($timestamp, $entity_keys, $delete_remote = false) {
		$history = self::get_history_raw();
		if (!isset($history[$timestamp])) return array();
		$backup = $history[$timestamp];

		$remaining = array_diff(array_merge(self::file_entity_keys($backup), !empty($backup['db']) ? array('db') : array()), $entity_keys);
		if (empty($remaining)) {
			return self::delete_backup_set($timestamp, $delete_remote);
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$files = array();
		foreach ($entity_keys as $k) {
			if (empty($backup[$k])) continue;
			$list = is_array($backup[$k]) ? $backup[$k] : array($backup[$k]);
			foreach ($list as $f) {
				if (is_string($f) && $f !== '' && basename($f) === $f) $files[] = $f;
			}
		}
		$files = array_values(array_diff(array_unique($files), self::files_referenced_elsewhere($timestamp)));

		$deleted = array();
		foreach ($files as $file) {
			$path = $backup_dir . $file;
			if (file_exists($path) && true === SiteGuard_Backup_Filesystem::delete_file($path, $backup_dir)) {
				$deleted[] = $file;
			}
		}

		if ($delete_remote && !empty($backup['service']) && !empty($files)) {
			$services = is_array($backup['service']) ? $backup['service'] : array($backup['service']);
			foreach ($services as $service) {
				if ('none' === $service) continue;
				foreach (self::service_instances($service) as $iid) {
					$method = self::get_storage_method($service, $iid);
					if (!$method || !method_exists($method, 'delete')) continue;
					try {
						$del = $method->delete($files);
						if (is_wp_error($del)) {
							SiteGuard_Backup::instance()->log('Could not delete pruned files of backup ' . $timestamp . ' from ' . $service . ': ' . $del->get_error_message(), 'error');
						}
					} catch (\Throwable $e) {
						SiteGuard_Backup::instance()->log('Could not delete pruned files from ' . $service . ': ' . $e->getMessage(), 'error');
					}
				}
			}
		}

		$lock = new SiteGuard_Backup_Semaphore('history');
		$lock->lock_wait();
		try {
			$history = self::get_history_raw();
			if (isset($history[$timestamp])) {
				foreach ($entity_keys as $k) unset($history[$timestamp][$k]);
				if (isset($history[$timestamp]['size'])) {
					$size = 0;
					foreach (self::get_all_files($history[$timestamp]) as $f) {
						$p = $backup_dir . $f;
						if (file_exists($p)) $size += filesize($p);
					}
					$history[$timestamp]['size'] = $size;
				}
				self::save_history($history);
			}
		} finally {
			$lock->unlock();
		}

		return $deleted;
	}

	/**
	 * Get the most recent backup from history.
	 *
	 * @return array|false Backup set with 'timestamp' key, or false if no backups exist.
	 */
	public static function get_latest() {
		$history = self::get_history();
		if (empty($history)) return false;

		// History is sorted newest-first by krsort
		$timestamp = key($history);
		$backup = current($history);
		$backup['timestamp'] = $timestamp;

		return $backup;
	}

	/**
	 * Get total size of all backup files.
	 */
	public static function get_total_backup_size() {
		$history = self::get_history();
		$total = 0;

		foreach ($history as $backup) {
			if (!empty($backup['size'])) {
				$total += $backup['size'];
			}
		}

		return $total;
	}

	/**
	 * Get storage method instance.
	 */
	/** Accounts of a recorded service: "s3" is every account, "s3/s-1" or "remotesend/3" just that one. */
	private static function service_instances($service) {
		if (!is_string($service)) return array();
		if (false !== strpos($service, '/')) {
			$parts = explode('/', $service, 2);
			return array(preg_replace('/[^a-zA-Z0-9_-]/', '', $parts[1]));
		}
		if ('remotesend' === $service) return array(null);
		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		return SiteGuard_Backup_Module::instances_of(preg_replace('/[^a-zA-Z0-9_-]/', '', $service), true);
	}

	private static function get_storage_method($service, $instance = null) {
		// "method/instance" names the method before the slash.
		if (is_string($service) && false !== strpos($service, '/')) {
			$service = explode('/', $service, 2)[0];
		}
		// Sanitize service name to prevent path traversal
		$service = preg_replace('/[^a-zA-Z0-9_-]/', '', $service);

		// Validate against known methods whitelist
		if (!array_key_exists($service, SiteGuard_Backup::instance()->get_backup_methods())) {
			return false;
		}

		$base_file = SGBACKUP_DIR . 'methods/backup-module.php';
		$class_file = SGBACKUP_DIR . 'methods/' . $service . '.php';
		if (!file_exists($class_file)) return false;

		require_once $base_file;
		require_once $class_file;
		$class_name = 'SiteGuard_Backup_Method_' . ucfirst($service);
		if (!class_exists($class_name)) return false;

		$module = new $class_name();
		if (null !== $instance && '' !== $instance) $module->set_instance_id($instance);
		return $module;
	}
}
