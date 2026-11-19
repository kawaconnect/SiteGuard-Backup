<?php
/**
 * SiteGuard Backup - Backup Engine
 *
 * Handles zip creation for file entities and database export.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Engine {

	/** @var SiteGuard_Backup */
	private $sgbackup;

	/** @var string Backup directory path */
	private $backup_dir;

	/** @var string Base filename for zips */
	private $zip_basename;

	/** @var int Split zip threshold in bytes */
	private $split_every;

	/** @var string Zip method: 'binzip', 'ziparchive', 'pclzip' */
	private $zip_method;

	/** @var array Files that should not be compressed */
	private $nocompress_extensions;

	/** @var int Current zip file index */
	private $index = 0;

	/** @var int Total files added in this batch */
	private $zipfiles_added = 0;

	/** @var int Total bytes batched in this session */
	private $zipfiles_batched = 0;

	/** @var ZipArchive|null Persistent ZipArchive handle for batch operations */
	private $ziparchive_handle = null;

	/** @var string|null Path of the currently open ZipArchive */
	private $ziparchive_path = null;

	/** @var int Files added since last ZipArchive flush */
	private $zipfiles_added_unflushed = 0;

	/** @var bool Whether we've already logged a fallback warning */
	private $zip_fallback_warned = false;

	/** @var bool Set when a periodic ZipArchive::close() failed (queued entries lost) */
	private $zip_close_failed = false;

	public function __construct($sgbackup) {
		$this->sgbackup = $sgbackup;
		$this->backup_dir = $sgbackup->get_backup_dir();
		$this->zip_basename = $sgbackup->get_backup_file_basename();
		$this->split_every = $sgbackup->jobdata_get('split_every', SGBACKUP_DEFAULT_SPLIT * 1048576);
		$this->zip_method = SiteGuard_Backup_Filesystem::get_zip_method();

		// Gap 22: User-configurable no-compress extensions
		$opts = SiteGuard_Backup_Options::instance();
		$custom_nocompress = $opts->get_option('sgbackup_zip_nocompress', '');
		if (!empty($custom_nocompress)) {
			$this->nocompress_extensions = array_map('trim', explode(',', $custom_nocompress));
		} else {
			$this->nocompress_extensions = array_map('trim', explode(',', SGBACKUP_ZIP_NOCOMPRESS));
		}

		$this->sgbackup->log("Zip method: {$this->zip_method}");

		// Gap 19: Detect OpenVZ and adjust batch size for memory constraints
		$this->detect_openvz_adjust_batch();

		// Low-RAM hosts (512 MB VPS, small containers, memory_limit < 128M): shrink
		// the per-resumption zip batch so each cron/AJAX hit does a bounded amount
		// of work and ZipArchive::close() never has hundreds of MB of pending
		// entries to flush at once. Marked forced_low so create_zips() does not
		// re-raise it on hosts that also happen to have a long max_execution_time.
		if ($this->sgbackup->is_low_memory()) {
			$low_batch = 10485760; // 10MB
			$current_batch = $this->sgbackup->jobdata_get('maxzipbatch', 26214400);
			if ($low_batch < $current_batch) {
				$this->sgbackup->jobdata_set('maxzipbatch', $low_batch);
				$this->sgbackup->jobdata_set('maxzipbatch_forced_low', true);
			}
			if (!$this->sgbackup->jobdata_get('low_memory_logged', false)) {
				$this->sgbackup->jobdata_set('low_memory_logged', true);
				$this->sgbackup->log("Low-memory mode active (" . SiteGuard_Backup::memory_summary() . "): "
					. "streaming file enumeration, 10MB zip batches, byte-bounded DB fetches, 1MB remote-send chunks");
			}
		}
	}

	/**
	 * Gap 19: Detect OpenVZ environment and reduce batch size if needed.
	 *
	 * OpenVZ containers have strict memory limits that can cause zip
	 * operations to be killed. This method detects OpenVZ by checking
	 * /proc/user_beancounters or /proc/vz/veinfo, reads the memory
	 * limit from privvmpages, and adjusts the zip batch size accordingly.
	 */
	private function detect_openvz_adjust_batch() {
		$is_openvz = false;
		$memory_limit_bytes = 0;

		// Detection method 1: /proc/user_beancounters (most reliable)
		if (is_readable('/proc/user_beancounters')) {
			$is_openvz = true;

			$bc_content = @file_get_contents('/proc/user_beancounters');
			if (false !== $bc_content) {
				// Parse privvmpages line: resource  held  maxheld  barrier  limit  failcnt
				if (preg_match('/privvmpages\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $bc_content, $matches)) {
					$held = intval($matches[1]);
					$limit = intval($matches[4]); // barrier value (soft limit)
					if ($limit > 0) {
						// privvmpages are in 4KB pages
						$memory_limit_bytes = $limit * 4096;
					}
				}
			}
		}

		// Detection method 2: /proc/vz/veinfo
		if (!$is_openvz && is_readable('/proc/vz/veinfo')) {
			$is_openvz = true;
		}

		if (!$is_openvz) return;

		$this->sgbackup->log("OpenVZ environment detected");

		if ($memory_limit_bytes > 0) {
			$this->sgbackup->log("OpenVZ memory limit (privvmpages): " .
				SiteGuard_Backup_Filesystem::format_bytes($memory_limit_bytes));

			// Set batch size to 1/8 of memory limit, capped between 5MB and 25MB
			$suggested_batch = intval($memory_limit_bytes / 8);
			$min_batch = 5242880;   // 5MB
			$max_batch = 26214400;  // 25MB

			$adjusted_batch = max($min_batch, min($max_batch, $suggested_batch));

			$current_batch = $this->sgbackup->jobdata_get('maxzipbatch', 26214400);
			if ($adjusted_batch < $current_batch) {
				$this->sgbackup->jobdata_set('maxzipbatch', $adjusted_batch);
				$this->sgbackup->jobdata_set('maxzipbatch_forced_low', true);
				$this->sgbackup->log("Reduced zip batch size to " .
					SiteGuard_Backup_Filesystem::format_bytes($adjusted_batch) .
					" for OpenVZ memory constraints");
			}
		} else {
			// Cannot determine memory limit, use conservative default
			$current_batch = $this->sgbackup->jobdata_get('maxzipbatch', 26214400);
			$conservative_batch = 10485760; // 10MB
			if ($conservative_batch < $current_batch) {
				$this->sgbackup->jobdata_set('maxzipbatch', $conservative_batch);
				$this->sgbackup->jobdata_set('maxzipbatch_forced_low', true);
				$this->sgbackup->log("Reduced zip batch size to " .
					SiteGuard_Backup_Filesystem::format_bytes($conservative_batch) .
					" (OpenVZ detected, memory limit unknown)");
			}
		}
	}

	// ==========================================
	// FILE BACKUP
	// ==========================================

	/**
	 * Backup all file entities.
	 *
	 * @return true|string|array True on complete, 'needs_resumption' if more work needed, error array on failure.
	 */
	public function backup_files() {
		$entities = $this->sgbackup->jobdata_get('job_file_entities', array());
		$job_type = $this->sgbackup->jobdata_get('job_type', 'backup');
		$altered_since = null;

		// For incremental backups, get the mtime cutoff
		if ('incremental' === $job_type) {
			$parent_nonce = $this->sgbackup->jobdata_get('parent_nonce');
			if ($parent_nonce) {
				$parent_jobdata = $this->sgbackup->jobdata_getarray($parent_nonce);
				if (isset($parent_jobdata['files_enumerated_at'])) {
					$altered_since = $parent_jobdata['files_enumerated_at'];
				} else {
					// Fallback: use the parent backup's timestamp from history
					$parent_timestamp = $this->sgbackup->jobdata_get('incremental_parent');
					$altered_since = $parent_timestamp ? intval($parent_timestamp) : null;
				}
			}
			$altered_since = apply_filters('sgbackup_files_altered_since', $altered_since, $job_type);
		}

		$backup_files_array = $this->sgbackup->jobdata_get('backup_files_array', array());
		$all_complete = true;

		foreach ($entities as $entity => $info) {
			if (isset($info['status']) && $info['status'] === 'complete') continue;

			$is_continuation = isset($info['status']) && $info['status'] === 'partial';
			if (!$is_continuation) {
				$this->sgbackup->log("Backing up entity: {$entity}");
			}
			$this->index = isset($info['index']) ? $info['index'] : 0;
			$this->zipfiles_added = 0;
			$this->zipfiles_batched = 0;

			$result = $this->backup_entity($entity, $altered_since);

			if ($result === 'needs_resumption') {
				// Save progress. Re-read backup_files_array from jobdata because
				// create_zips() may have already appended split files to it.
				$backup_files_array = $this->sgbackup->jobdata_get('backup_files_array', array());
				$entities[$entity]['index'] = $this->index;
				$entities[$entity]['status'] = 'partial';
				$this->sgbackup->jobdata_set('job_file_entities', $entities);
				return 'needs_resumption';
			}

			if (is_array($result) && !empty($result)) {
				// Deduplicate: if the entity went through mid-entity resumption,
				// the same zip filename was already persisted to jobdata during
				// the partial pass, and backup_entity() returns it again on the
				// completing pass. Merging naively would list the file twice.
				$backup_files_array = array_values(array_unique(array_merge($backup_files_array, $result)));
				$entities[$entity]['status'] = 'complete';
				$this->sgbackup->log("Entity {$entity} complete: " . count($result) . " zip file(s)");
			} elseif ($result === true) {
				$entities[$entity]['status'] = 'complete';
				$this->sgbackup->log("Entity {$entity} complete (no files to backup)");
			} else {
				$this->sgbackup->log("Entity {$entity} FAILED - backup will be incomplete!", 'error');
				$entities[$entity]['status'] = 'error';
			}

			$this->sgbackup->jobdata_set('job_file_entities', $entities);
			// Persist after each entity so files aren't lost on resumption
			$this->sgbackup->jobdata_set('backup_files_array', $backup_files_array);
			// Only now clear the per-entity checkpoint (status is already durable)
			$this->sgbackup->jobdata_set("entity_{$entity}_last_file_index", 0);
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		// Check for any failed entities
		$failed_entities = array();
		foreach ($entities as $ent_name => $ent_info) {
			if (isset($ent_info['status']) && $ent_info['status'] === 'error') {
				$failed_entities[] = $ent_name;
			}
		}
		if (!empty($failed_entities)) {
			$this->sgbackup->log("WARNING: " . count($failed_entities) . " entity/entities failed: " .
				implode(', ', $failed_entities) . " - backup is INCOMPLETE", 'error');
		}

		// Fallback only: files_enumerated_at is normally stamped just before the
		// first enumerate_files() call (see backup_entity), which closes the
		// incremental blind-spot window between enumeration and completion.
		if (null === $this->sgbackup->jobdata_get('files_enumerated_at', null)) {
			$this->sgbackup->jobdata_set('files_enumerated_at', time());
		}
		$this->sgbackup->jobdata_set('backup_files_array', $backup_files_array);

		// Store failed entities in jobdata so backup_finish can report them
		if (!empty($failed_entities)) {
			$this->sgbackup->jobdata_set('failed_entities', $failed_entities);
		}

		return true;
	}

	/**
	 * Backup a single entity (plugins, themes, uploads, others).
	 *
	 * @param string   $entity        Entity name.
	 * @param int|null $altered_since  Only include files modified after this timestamp.
	 * @return array|string|true      Array of created files, 'needs_resumption', or true if no files.
	 */
	private function backup_entity($entity, $altered_since = null) {
		// M9: Support extra directory entities (extradir_0, extradir_1, etc.)
		$entities_info = $this->sgbackup->get_backup_entities_with_extras();
		if (!isset($entities_info[$entity])) {
			$this->sgbackup->log("Unknown entity: {$entity}", 'error');
			return false;
		}

		$source_path = $entities_info[$entity]['path'];

		if (!is_dir($source_path)) {
			$this->sgbackup->log("Source directory not found: {$source_path}", 'warning');
			return true;
		}

		// Build exclusion list
		$opts = SiteGuard_Backup_Options::instance();
		$excludes = $opts->get_option('sgbackup_exclude_files', array());
		if ($entity === 'others') {
			$excludes = array_merge($excludes, SiteGuard_Backup_Filesystem::wp_others_excludes());
		}
		// Apply entity-level excludes declared in get_backup_entities()
		// (e.g. wpcore excludes wp-content - plugins/themes/uploads/others
		// have their own dedicated entities, so don't duplicate them here).
		if (!empty($entities_info[$entity]['excludes']) && is_array($entities_info[$entity]['excludes'])) {
			$excludes = array_merge($excludes, $entities_info[$entity]['excludes']);
		}

		// Context-aware wp-config.php handling for wpcore.
		//
		//   - Local-only backup (service == ['none']) → INCLUDE wp-config.php.
		//     Operator is downloading the archive themselves and needs the DB
		//     credentials, salts, and custom constants for disaster recovery.
		//
		//   - Remote upload (any configured service) → EXCLUDE wp-config.php.
		//     The archive is leaving this server to land on S3, Dropbox,
		//     Google Drive, FTP, etc. Third-party storage shouldn't hold
		//     DB credentials or auth salts in cleartext.
		//
		// Operators who want the opposite behavior can filter the excludes
		// list via sgbackup_exclude_files or use the sgbackup_backup_excludes
		// filter below.
		if ($entity === 'wpcore') {
			$services = $this->sgbackup->jobdata_get('service', array('none'));
			if (!is_array($services)) $services = array($services);
			$has_remote = false;
			foreach ($services as $svc) {
				if ($svc !== 'none' && $svc !== '' && $svc !== null) {
					$has_remote = true;
					break;
				}
			}
			if ($has_remote) {
				$excludes[] = '/wp-config.php';
				$this->sgbackup->log('wpcore: excluding wp-config.php - backup is destined for remote upload (' . implode(', ', $services) . '), will not ship credentials off-server');
			} else {
				$this->sgbackup->log('wpcore: including wp-config.php - local-only backup (disaster-recovery ready). Store the archive in a private location.');
			}
		}

		// Enumerate files - cache the list in a temp file to avoid re-scanning
		// the entire directory tree on every resumption (saves ~2s per cycle on CIFS).
		// The cache is newline-delimited JSON (one entry per line) so resumptions
		// can stream it lazily instead of loading the whole list into memory.
		$last_index = $this->sgbackup->jobdata_get("entity_{$entity}_last_file_index", 0);
		$cache_file = $this->backup_dir . 'filecache_' . $this->sgbackup->nonce . '_' . $entity . '.list';

		$files = null;
		if ($last_index > 0 && file_exists($cache_file)) {
			$cached_count = (int) $this->sgbackup->jobdata_get("entity_{$entity}_file_count", 0);
			$files = SiteGuard_Backup_FileList::open($cache_file, $cached_count);
			if (!$files) {
				// Unreadable/empty/legacy cache - delete and re-enumerate below
				@unlink($cache_file);
				$files = null;
			}
		}
		if (null === $files && $last_index > 0) {
			// Checkpoint without its list: the index is meaningless against a new walk.
			$this->restart_entity($entity);
			$last_index = 0;
		}

		if (null === $files) {
			// Stamp enumeration start (only once per job) so incrementals measure
			// from BEFORE the scan, not after the whole files phase completed.
			if (null === $this->sgbackup->jobdata_get('files_enumerated_at', null)) {
				$this->sgbackup->jobdata_set('files_enumerated_at', time());
			}

			// Stream the directory walk straight into the JSONL cache, one entry per
			// line, and then read it back through the forward-only reader. The whole
			// file list is never held in PHP memory - previously a 100k-file uploads
			// tree cost ~50-80MB here on the first pass, enough to OOM a 64-128M
			// memory_limit before a single file was zipped (and, because the cache
			// was only written after a *complete* scan, every resumption repeated
			// the same fatal until the no-progress guard aborted the job).
			$scan = $this->enumerate_to_cache($source_path, $excludes, $altered_since, $cache_file, $entity);

			if (false === $scan) {
				// Cache file unwritable (disk full / permissions): fall back to the
				// in-memory list so the backup still proceeds on hosts with room.
				$this->sgbackup->log("Could not write file-list cache for {$entity} - falling back to in-memory enumeration", 'warning');
				$files = SiteGuard_Backup_Filesystem::enumerate_files($source_path, $excludes, $altered_since);
				$this->sgbackup->log("Enumerated " . count($files) . " files in {$entity}");
				$this->sgbackup->jobdata_set("entity_{$entity}_file_count", count($files));
				if (empty($files)) return true;
				foreach ($files as $file_info) {
					if (isset($file_info['size']) && $file_info['size'] > SGBACKUP_WARN_FILE_SIZE) {
						$this->sgbackup->log("Warning: Large file detected: " . $file_info['path'] .
							" (" . SiteGuard_Backup_Filesystem::format_bytes($file_info['size']) . ")", 'warning');
					}
				}
			} else {
				$this->sgbackup->log("Enumerated {$scan} files in {$entity}");
				$this->sgbackup->jobdata_set("entity_{$entity}_file_count", $scan);
				if (0 === $scan) {
					@unlink($cache_file);
					return true;
				}
				$files = SiteGuard_Backup_FileList::open($cache_file, $scan);
				if (!$files) {
					$this->sgbackup->log("File-list cache for {$entity} could not be reopened after writing - will re-enumerate", 'error');
					@unlink($cache_file);
					return 'needs_resumption';
				}
			}
		}

		// Create zip(s)
		$result = $this->create_zips($files, $source_path, $entity);

		// Corrupt file-list cache detected mid-zip: delete it and resume - the
		// next resumption will re-enumerate from scratch (checkpoint is kept).
		if ($result === 'cache_corrupt') {
			@unlink($cache_file);
			return 'needs_resumption';
		}

		// Clean up file cache when entity is complete (close the reader first so
		// the unlink also succeeds on filesystems that refuse to delete open files)
		if (is_object($files)) $files->close();
		if (is_array($result) && file_exists($cache_file)) {
			@unlink($cache_file);
		}

		return $result;
	}

	/**
	 * Walk an entity directory and write the file list to a JSONL cache without
	 * ever holding the list in memory.
	 *
	 * Large-file warnings are emitted during the walk (capped) since there is no
	 * array to loop over afterwards. Filenames that are not valid UTF-8 cannot be
	 * json_encode()d directly; they are stored base64-wrapped and transparently
	 * decoded by SiteGuard_Backup_FileList so no file is silently dropped.
	 *
	 * @return int|false Number of entries written, or false if the cache file
	 *                   could not be opened/written (caller falls back).
	 */
	private function enumerate_to_cache($source_path, $excludes, $altered_since, $cache_file, $entity) {
		// Write to a .partial and rename on success so a walk killed mid-way can
		// never be mistaken for a complete cache by the next resumption.
		$partial = $cache_file . '.partial';
		$cfh = @fopen($partial, 'wb');
		if (!$cfh) return false;

		$count = 0;
		$large_warned = 0;
		$ok = true;
		foreach (SiteGuard_Backup_Filesystem::enumerate_files_stream($source_path, $excludes, $altered_since) as $file_entry) {
			if (isset($file_entry['size']) && $file_entry['size'] > SGBACKUP_WARN_FILE_SIZE && $large_warned < 20) {
				$large_warned++;
				$this->sgbackup->log("Warning: Large file detected: " . $file_entry['path'] .
					" (" . SiteGuard_Backup_Filesystem::format_bytes($file_entry['size']) . ")", 'warning');
			}

			$encoded = json_encode($file_entry);
			if (false === $encoded) {
				// Non-UTF-8 filename: wrap the two path strings in base64.
				$encoded = json_encode(array(
					'path_b64'      => base64_encode($file_entry['path']),
					'full_path_b64' => base64_encode($file_entry['full_path']),
					'size'          => $file_entry['size'],
					'mtime'         => $file_entry['mtime'],
				));
				if (false === $encoded) { $ok = false; break; }
			}
			$line = $encoded . "\n";
			$w = fwrite($cfh, $line);
			if (false === $w || $w !== strlen($line)) { $ok = false; break; } // short write = disk full
			$count++;

			// Long walks on slow disks (CIFS, tiny VPS): keep the job's heartbeat
			// fresh so a concurrent status poll does not misreport it as stalled.
			if (0 === ($count % 5000)) {
				$this->sgbackup->jobdata_set('last_activity', "{$entity}: scanning… {$count} files found");
			}
		}
		if (false === fflush($cfh)) $ok = false;
		fclose($cfh);

		if (!$ok || !@rename($partial, $cache_file)) {
			@unlink($partial);
			@unlink($cache_file);
			return false;
		}
		return $count;
	}

	/**
	 * Restart an entity from scratch after its file-list cache was lost mid-way.
	 *
	 * The checkpoint index refers to the ORDER of the previous enumeration; once
	 * that list is gone a fresh walk of a changed tree would resume at the wrong
	 * file (skipping some, duplicating others) or never seek far enough and loop
	 * forever. Dropping this entity's zips and restarting is the only safe move.
	 */
	private function restart_entity($entity) {
		$this->sgbackup->log("File-list cache for {$entity} was lost mid-entity - restarting the entity from the first file", 'warning');
		$existing = $this->sgbackup->jobdata_get('backup_files_array', array());
		$pattern = '/^' . preg_quote($this->zip_basename . '-' . $entity, '/') . '(-\d+)?\.zip$/';
		$kept = array();
		foreach ((array) $existing as $f) {
			if (preg_match($pattern, $f)) {
				@unlink($this->backup_dir . $f);
				continue;
			}
			$kept[] = $f;
		}
		$this->sgbackup->jobdata_set('backup_files_array', array_values($kept));
		$this->sgbackup->jobdata_set("entity_{$entity}_last_file_index", 0);
		$this->index = 0;
		$this->zipfiles_added = 0;
		$this->zipfiles_batched = 0;
	}

	/**
	 * Create zip file(s) from a list of files.
	 *
	 * @param array|SiteGuard_Backup_FileList $files Array of file info arrays,
	 *                            or a lazy JSONL reader on resumption.
	 * @param string $source_path Base path to strip.
	 * @param string $entity      Entity name for the zip filename.
	 * @return array|string       Array of zip filenames, 'needs_resumption', or 'cache_corrupt'.
	 */
	private function create_zips($files, $source_path, $entity) {
		$created_files = array();
		$maxbatch = $this->sgbackup->jobdata_get('maxzipbatch', 26214400);
		$maxbatch_ceiling = $this->sgbackup->jobdata_get('maxzipbatch_ceiling', 209715200);

		// On hosts with generous execution time (>= 300s), increase batch limits.
		// The default 25MB was designed for shared hosting with 30s timeouts.
		// ZipArchive stores paths (not data), so memory is not the concern -
		// only disk I/O time during close() matters.
		// Skip the raise entirely when the batch was deliberately lowered
		// (OpenVZ detection, missed-checkin backoff) - see maxzipbatch_forced_low.
		$max_exec = (int) ini_get('max_execution_time');
		if (($max_exec <= 0 || $max_exec >= 300)
			&& !$this->sgbackup->jobdata_get('maxzipbatch_forced_low', false)) {
			$maxbatch = max($maxbatch, 104857600);        // 100MB min
			$maxbatch_ceiling = max($maxbatch_ceiling, 209715200); // 200MB ceiling
		}

		// Resume from last file index if partial
		$last_file_index = $this->sgbackup->jobdata_get("entity_{$entity}_last_file_index", 0);

		$zip_filename = $this->get_zip_filename($entity, $this->index);
		$zip_path = $this->backup_dir . $zip_filename;

		// Detect if another process is actively writing temp parts for this zip.
		// libzip names its temp files {zip}.XXXXXX (not {zip}.tmp), so scan any
		// {zip}.* sibling and back off if one was touched in the last 30s.
		$temp_candidates = glob($zip_path . '.*');
		if (!empty($temp_candidates)) {
			$time_now = time();
			foreach ($temp_candidates as $tmp_path) {
				clearstatcache(true, $tmp_path);
				$tmp_mtime = @filemtime($tmp_path);
				if ($tmp_mtime > 100 && ($time_now - $tmp_mtime) < 30) {
					$this->sgbackup->log("Zip temp file " . basename($tmp_path) . " was modified " .
						($time_now - $tmp_mtime) . "s ago - another process may be active. Backing off.");
					SiteGuard_Backup_Job_Scheduler::terminate_due_to_activity(
						"active zip temp file: " . basename($tmp_path), $time_now, $tmp_mtime
					);
					return 'needs_resumption';
				}
			}
		}

		// Restart-at-zero: a leftover zip from a run killed before the first
		// checkpoint would get the same files re-added, duplicating entries.
		if ($last_file_index === 0 && file_exists($zip_path)) {
			clearstatcache(true, $zip_path);
			if (filesize($zip_path) > 0) {
				$this->sgbackup->log("Deleting leftover zip {$zip_filename} from an interrupted run (no checkpoint recorded) to avoid duplicate entries");
				@unlink($zip_path);
			}
		}

		// $files is either a plain array (first pass, right after enumeration) or
		// a forward-only SiteGuard_Backup_FileList reader (resumption from the
		// JSONL cache). Abstract the two behind $total_files + $get_entry.
		$is_reader = is_object($files);
		$total_files = $is_reader ? $files->count() : count($files);
		if ($is_reader && !$files->seek_to($last_file_index)) {
			$this->sgbackup->log("File-list cache is corrupt (could not seek to entry {$last_file_index}) - will re-enumerate", 'error');
			return 'cache_corrupt';
		}

		if ($last_file_index === 0) {
			$this->sgbackup->log("Creating zip: {$zip_filename}");
		}

		// Set progress immediately so the UI shows counts from the start
		$this->sgbackup->jobdata_set('files_added_count', $last_file_index);
		$this->sgbackup->jobdata_set('files_total_count', $total_files);
		$pct = $total_files > 0 ? round($last_file_index / $total_files * 100) : 0;
		$this->sgbackup->jobdata_set('last_activity',
			"{$entity}: {$pct}% ({$last_file_index}/{$total_files} files)");

		for ($i = $last_file_index; $i < $total_files; $i++) {
			if ($is_reader) {
				$file = $files->next_entry();
				if (false === $file) {
					$this->sgbackup->log("File-list cache is corrupt or short at entry {$i}/{$total_files} - will re-enumerate", 'error');
					$this->close_ziparchive();
					return 'cache_corrupt';
				}
			} else {
				$file = $files[$i];
			}

			// Check batch limits
			if ($this->zipfiles_batched >= $maxbatch || $this->zipfiles_batched >= $maxbatch_ceiling) {
				// Close the current zip FIRST. If the close fails the archive is
				// suspect, so do NOT persist the new checkpoint or record the zip -
				// otherwise the resumed job would trust a corrupt file and skip
				// the batch that was never actually flushed.
				if (!$this->close_ziparchive()) {
					$this->sgbackup->log("Failed to finalize zip {$zip_filename} at batch limit - not checkpointing; marking entity as failed", 'error');
					return false;
				}

				// Close succeeded: save progress and request resumption
				$pct = round($i / $total_files * 100);
				$this->sgbackup->jobdata_set("entity_{$entity}_last_file_index", $i);
				$this->sgbackup->jobdata_set('files_added_count', $i);
				$this->sgbackup->jobdata_set('files_total_count', $total_files);
				$this->sgbackup->jobdata_set('last_activity',
					"{$entity}: {$pct}% ({$i}/{$total_files} files, " .
					SiteGuard_Backup_Filesystem::format_bytes($this->zipfiles_batched) . " batched)");
				$this->sgbackup->log("Batch limit reached at file {$i}/{$total_files} " .
					"({$pct}%, " . SiteGuard_Backup_Filesystem::format_bytes($this->zipfiles_batched) . ")");

				if ($this->zipfiles_added > 0) {
					$created_files[] = $zip_filename;
				}
				// Deduplicate: the same zip filename is appended on every resumption
				$existing = $this->sgbackup->jobdata_get('backup_files_array', array());
				$merged = array_values(array_unique(array_merge($existing, $created_files)));
				$this->sgbackup->jobdata_set('backup_files_array', $merged);

				return 'needs_resumption';
			}

			// Check split threshold
			// For ZipArchive: only check after periodic flush (unflushed==0) to avoid O(n^2) reopens
			$should_check_split = ($this->zip_method !== 'ziparchive')
				|| ($this->zipfiles_added_unflushed === 0 && $this->zipfiles_added > 0);
			if ($should_check_split && file_exists($zip_path)) {
				clearstatcache(true, $zip_path);
				if (filesize($zip_path) >= $this->split_every) {
					// Finalize and verify the full archive BEFORE recording it; a failed
					// close here used to be silently ignored and the short zip recorded.
					if (!$this->close_ziparchive() || !$this->verify_zip($zip_path)) {
						$this->sgbackup->log("Failed to finalize split zip {$zip_filename} - marking entity as failed", 'error');
						return false;
					}
					$created_files[] = $zip_filename;
					$this->index++;
					$zip_filename = $this->get_zip_filename($entity, $this->index);
					$zip_path = $this->backup_dir . $zip_filename;
					$this->sgbackup->log("Split threshold reached, new zip: {$zip_filename}");
				}
			}

			// Add file to zip
			$result = $this->add_to_zip($zip_path, $file['full_path'], $file['path'], $file['size']);
			if ($this->zip_close_failed) {
				$this->sgbackup->log("ZipArchive flush failed for {$zip_filename}; queued files were not written - marking entity as failed", 'error');
				return false;
			}
			if ($result) {
				$this->zipfiles_added++;
				$this->zipfiles_batched += $file['size'];
			}

			// Progress update - write to jobdata so the polling UI can show real-time progress.
			if ($this->zipfiles_added === 1 || $this->zipfiles_added % 20 === 0) {
				$this->sgbackup->jobdata_set('files_added_count', $i + 1);
				$this->sgbackup->jobdata_set('files_total_count', $total_files);
				$pct = round(($i + 1) / $total_files * 100);
				$this->sgbackup->jobdata_set('last_activity',
					"{$entity}: {$pct}% ({$i}/{$total_files} files, " .
					SiteGuard_Backup_Filesystem::format_bytes($this->zipfiles_batched) . " batched)");
			}
			if ($this->zipfiles_added > 0 && $this->zipfiles_added % 500 === 0) {
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
				SiteGuard_Backup_Job_Scheduler::record_still_alive();
			}
		}

		// Finalize the archive BEFORE anything downstream (encryption, manifest
		// hashing, upload) reads it. Without this, a files-only backup leaves the
		// last ZipArchive handle open (up to 199 unflushed entries) until object
		// destruction, so the manifest hashes - and the upload ships - a
		// truncated zip.
		if (!$this->close_ziparchive()) {
			$this->sgbackup->log("Failed to finalize zip {$zip_filename} - marking entity as failed", 'error');
			return false;
		}

		// Final zip - verify integrity before recording it as a backup file
		if ($this->zipfiles_added > 0 || file_exists($zip_path)) {
			if (!$this->verify_zip($zip_path)) {
				$this->sgbackup->log("Zip failed verification, not recording it: {$zip_filename} - marking entity as failed", 'error');
				return false;
			}
			$created_files[] = $zip_filename;
		}

		if ($this->zipfiles_added === 0 && $total_files > 0) {
			$this->sgbackup->log("WARNING: {$entity} had " . $total_files .
				" files enumerated but 0 were added to zip. Check file permissions and " .
				"whether the files resolve within ABSPATH (" . ABSPATH . ")", 'warning');
		}

		// (The entity_*_last_file_index checkpoint is reset by backup_files() only
		// after the entity's 'complete' status has been persisted, so a crash in
		// between cannot re-zip the whole entity.)

		// Clean up binzip temp directory
		if ($this->zip_method === 'binzip') {
			$temp_base = $this->backup_dir . 'ziptemp_' . $this->sgbackup->nonce;
			if (is_dir($temp_base)) {
				$this->recursive_rmdir($temp_base);
			}
		}

		// Clean up stale zip temp fragments (libzip {zip}.XXXXXX files left by
		// killed runs). Only this job's basename can match, and only files
		// untouched for over an hour are removed.
		$stale_temps = glob($this->backup_dir . "{$this->zip_basename}-{$entity}*.zip.*");
		if (!empty($stale_temps)) {
			$time_now = time();
			foreach ($stale_temps as $stale) {
				clearstatcache(true, $stale);
				$stale_mtime = @filemtime($stale);
				if ($stale_mtime && ($time_now - $stale_mtime) > 3600) {
					@unlink($stale);
					$this->sgbackup->log("Removed stale zip temp file: " . basename($stale));
				}
			}
		}

		return $created_files;
	}

	/**
	 * Generate zip filename for an entity and index.
	 */
	private function get_zip_filename($entity, $index) {
		$suffix = ($index > 0) ? "-{$index}" : '';
		return "{$this->zip_basename}-{$entity}{$suffix}.zip";
	}

	/**
	 * Add a file to a zip archive.
	 *
	 * @param string $zip_path     Full path to the zip file.
	 * @param string $file_path    Full path to the source file.
	 * @param string $archive_name Name inside the zip.
	 * @param int    $file_size    File size in bytes.
	 * @return bool
	 */
	private function add_to_zip($zip_path, $file_path, $archive_name, $file_size) {
		// Guard against directories misreported as files (CIFS/SMB mounts)
		if (is_dir($file_path)) return false;

		$is_symlink = is_link($file_path);
		$resolved = $is_symlink ? realpath($file_path) : $file_path;
		if (false === $resolved || !file_exists($resolved) || !is_readable($resolved)) {
			if (!isset($this->_zip_skip_logged)) $this->_zip_skip_logged = 0;
			if ($this->_zip_skip_logged < 3) {
				$this->sgbackup->log("Zip skip: file not found/readable: {$file_path}", 'warning');
				$this->_zip_skip_logged++;
			}
			return false;
		}
		// Only check ABSPATH containment for actual symlinks - regular files inside
		// symlinked parent directories (e.g. uploads on a separate mount) are safe
		// because they were found by iterating from a known WordPress entity path.
		if ($is_symlink) {
			$real_resolved = realpath($resolved);
			$real_abspath = realpath(ABSPATH);
			if ($real_resolved && $real_abspath && $real_resolved !== $real_abspath
				&& strpos($real_resolved, rtrim($real_abspath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
				if (!isset($this->_zip_abspath_logged)) $this->_zip_abspath_logged = 0;
				if ($this->_zip_abspath_logged < 3) {
					$this->sgbackup->log("Zip skip: symlink target outside ABSPATH: {$real_resolved} (ABSPATH={$real_abspath})", 'warning');
					$this->_zip_abspath_logged++;
				}
				return false;
			}
		}
		$file_path = $resolved;

		$result = false;
		switch ($this->zip_method) {
			case 'binzip':
				$result = $this->add_to_zip_binzip($zip_path, $file_path, $archive_name);
				break;
			case 'ziparchive':
				$result = $this->add_to_zip_ziparchive($zip_path, $file_path, $archive_name);
				break;
			case 'pclzip':
				$result = $this->add_to_zip_pclzip($zip_path, $file_path, $archive_name);
				break;
		}

		// Fallback: if primary method failed, try PclZip (unless already PclZip)
		if (!$result && $this->zip_method !== 'pclzip') {
			// A stale ZipArchive handle is a common transient cause - retry the
			// add once with a fresh handle before falling back to PclZip.
			if ($this->zip_method === 'ziparchive') {
				$this->close_ziparchive();
				$result = $this->add_to_zip_ziparchive($zip_path, $file_path, $archive_name);
			}

			// PclZip loads whole files into memory (plus a copy while deflating), so
			// cap the fallback at 50MB or a quarter of the remaining headroom,
			// whichever is smaller - on a 64M memory_limit that is ~10MB, not 50.
			$pclzip_max = (int) min(52428800, max(1048576, SiteGuard_Backup::memory_headroom() / 4));
			if (!$result && $file_size > $pclzip_max) {
				// Skip it and record the loss instead of risking an OOM kill.
				$this->sgbackup->log("file skipped: primary zip method failed and file too large for PclZip fallback: {$archive_name} (" .
					SiteGuard_Backup_Filesystem::format_bytes($file_size) . ")", 'error');
				$skipped = $this->sgbackup->jobdata_get('zip_skipped_files', array());
				if (!is_array($skipped)) $skipped = array();
				if (count($skipped) < 200) {
					$skipped[] = $archive_name;
					$this->sgbackup->jobdata_set('zip_skipped_files', $skipped);
				}
				return false;
			}

			if (!$result) {
				if (!$this->zip_fallback_warned) {
					$this->sgbackup->log("Primary zip method '{$this->zip_method}' failed for {$archive_name}, falling back to PclZip", 'warning');
					$this->zip_fallback_warned = true;
				}
				$this->close_ziparchive(); // Close any open ZipArchive handle
				$result = $this->add_to_zip_pclzip($zip_path, $file_path, $archive_name);
			}
		}

		return $result;
	}

	/**
	 * Add file using system zip binary.
	 */
	private function add_to_zip_binzip($zip_path, $file_path, $archive_name) {
		// Reject path traversal in archive names
		if (strpos($archive_name, '..') !== false) return false;

		$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		$store = in_array('.' . $ext, $this->nocompress_extensions) ? '-0' : '';

		// Create a temporary symlink structure to preserve archive_name paths in the zip.
		// binzip (command-line zip) needs the file at the correct relative path.
		$temp_base = $this->backup_dir . 'ziptemp_' . $this->sgbackup->nonce;
		$temp_path = $temp_base . '/' . $archive_name;
		$temp_dir = dirname($temp_path);

		if (!is_dir($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}

		// Create a hard link (or copy if cross-device) to avoid doubling disk usage
		if (!@link($file_path, $temp_path)) {
			if (!@copy($file_path, $temp_path)) {
				return false;
			}
		}

		// cd into temp base and zip the relative path
		// './' prefix: a root-level name starting with '-' would otherwise be
		// parsed by zip as an option (escapeshellarg protects the shell, not zip).
		// zip strips the leading './' from stored names.
		$cmd = sprintf(
			'cd %s && zip %s %s %s 2>&1',
			escapeshellarg($temp_base),
			$store,
			escapeshellarg($zip_path),
			escapeshellarg('./' . $archive_name)
		);

		$output = array();
		$return = -1;
		exec($cmd, $output, $return);
		if (0 !== $return && 12 !== $return) {
			SiteGuard_Backup::instance()->log("binzip exec failed (return code {$return}): " . implode("\n", $output), 'warning');
		}

		// Clean up temp file (but leave directory structure for reuse within same job)
		@unlink($temp_path);

		return (0 === $return || 12 === $return); // 12 = "nothing to do" (already added)
	}

	/**
	 * Add file using ZipArchive.
	 */
	private function add_to_zip_ziparchive($zip_path, $file_path, $archive_name) {
		// Reuse open ZipArchive handle for same zip path (avoids O(n^2) central directory rewrites)
		if ($this->ziparchive_handle === null || $this->ziparchive_path !== $zip_path) {
			if (!$this->close_ziparchive()) {
				$this->zip_close_failed = true;
			}
			$zip = new ZipArchive();
			if (true !== $zip->open($zip_path, ZipArchive::CREATE)) {
				$this->sgbackup->log("ZipArchive: failed to open {$zip_path}", 'warning');
				return false;
			}
			$this->ziparchive_handle = $zip;
			$this->ziparchive_path = $zip_path;
		}

		$zip = $this->ziparchive_handle;
		$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		$compress = !in_array('.' . $ext, $this->nocompress_extensions);

		$result = $zip->addFile($file_path, $archive_name);
		if ($result && !$compress) {
			$zip->setCompressionName($archive_name, ZipArchive::CM_STORE);
		}

		// Flush periodically (every 200 files) to limit data loss on interruption
		// Note: zipfiles_added is incremented by the caller (create_zips), not here
		$this->zipfiles_added_unflushed++;
		if ($this->zipfiles_added_unflushed >= 200) {
			// addFile() only queues paths; libzip reads them at close(). A failed
			// close drops every queued entry, so it must fail the entity rather than
			// be swallowed (the previous behaviour marked such backups complete).
			if (!$this->close_ziparchive()) {
				$this->zip_close_failed = true;
			}
			$this->zipfiles_added_unflushed = 0;
		}

		return $result;
	}

	/**
	 * Close the persistent ZipArchive handle.
	 *
	 * @return bool True if close succeeded or no handle was open.
	 */
	public function close_ziparchive() {
		if ($this->ziparchive_handle !== null) {
			$result = $this->ziparchive_handle->close();
			$path = $this->ziparchive_path;
			$this->ziparchive_handle = null;
			$this->ziparchive_path = null;
			if (!$result) {
				$this->sgbackup->log("ZipArchive::close() failed for {$path} - zip may be corrupt", 'error');
				return false;
			}
		}
		return true;
	}

	/**
	 * Verify a finished zip's consistency before recording it as a backup file.
	 *
	 * @param string $path Full path to the zip.
	 * @return bool True if the zip passes ZipArchive::CHECKCONS (or ZipArchive
	 *              is unavailable, which is logged and treated as a pass).
	 */
	private function verify_zip($path) {
		if (!class_exists('ZipArchive')) {
			$this->sgbackup->log("Zip verification skipped (ZipArchive unavailable): " . basename($path));
			return true;
		}
		$zip = new ZipArchive();
		$open_result = $zip->open($path, ZipArchive::CHECKCONS);
		if (true !== $open_result) {
			$this->sgbackup->log("Zip verification FAILED (code {$open_result}): " . basename($path), 'error');
			return false;
		}
		$zip->close();
		return true;
	}

	private function recursive_rmdir($dir) {
		$r = SiteGuard_Backup_Filesystem::delete_tree($dir, $this->backup_dir);
		if (is_wp_error($r)) {
			$this->sgbackup->log("Could not remove directory {$dir}: " . $r->get_error_message(), 'warning');
		}
	}

	public function __destruct() {
		$this->close_ziparchive();
	}

	/**
	 * Add file using PclZip.
	 */
	private function add_to_zip_pclzip($zip_path, $file_path, $archive_name) {
		if (!defined('PCLZIP_TEMPORARY_DIR')) {
			define('PCLZIP_TEMPORARY_DIR', $this->backup_dir);
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$zip = new PclZip($zip_path);

		// Remove the full directory path and add back the archive_name directory prefix
		// so files are stored with correct relative paths inside the zip.
		$remove_path = dirname($file_path);
		$add_path = dirname($archive_name);

		// If archive_name has no directory component, just strip the full dir
		if ($add_path === '.' || $add_path === '') {
			$result = $zip->add($file_path, PCLZIP_OPT_REMOVE_PATH, $remove_path);
		} else {
			$result = $zip->add($file_path, PCLZIP_OPT_REMOVE_PATH, $remove_path, PCLZIP_OPT_ADD_PATH, $add_path);
		}

		return ($result !== 0);
	}

	// ==========================================
	// DATABASE BACKUP
	// ==========================================

	/**
	 * Backup the database.
	 *
	 * @return true|string True on complete, 'needs_resumption' if more work.
	 */
	public function backup_database() {
		global $wpdb;

		// Per-subsite backup: delegate to subsite-scoped method
		if ($this->sgbackup->jobdata_get('is_subsite_backup', false)) {
			$blog_id = $this->sgbackup->jobdata_get('subsite_blog_id', 0);
			if ($blog_id > 1) {
				return $this->backup_subsite_database($blog_id);
			}
		}

		$table_prefix = $wpdb->prefix;

		// M4: Check if non-WordPress tables should be included
		$include_nonwp = $this->sgbackup->jobdata_get('backupdb_nonwp', false);
		$tables = $include_nonwp
			? SiteGuard_Backup_Database_Utility::get_all_tables()
			: SiteGuard_Backup_Database_Utility::get_tables($table_prefix);

		if (empty($tables)) {
			// A WordPress site always has tables: an empty list is a failed query,
			// not a database with nothing in it. Returning true here finished the
			// job as a success without any database dump.
			$this->sgbackup->log("No tables found" . ($include_nonwp ? '' : " with prefix {$table_prefix}") . ": the database was not backed up", 'error');
			return false;
		}

		// H6: Filter tables based on include/exclude selections
		$include_tables = $this->sgbackup->jobdata_get('include_tables', 'all');
		$exclude_tables = $this->sgbackup->jobdata_get('exclude_tables', array());
		if (is_array($include_tables)) {
			$tables = array_intersect($tables, $include_tables);
		}
		if (!empty($exclude_tables)) {
			$tables = array_diff($tables, $exclude_tables);
		}
		$tables = array_values($tables);

		// Validate table names to prevent SQL injection
		$tables = array_filter($tables, function($t) {
			return preg_match('/^[a-zA-Z0-9_]+$/', $t);
		});

		$this->sgbackup->log("Found " . count($tables) . " tables to backup" . ($include_nonwp ? ' (including non-WP tables)' : ''));

		// Gap 18: Warn about large tables
		foreach ($tables as $table) {
			$row_count = SiteGuard_Backup_Database_Utility::get_table_row_count($table);
			if ($row_count > SGBACKUP_WARN_DB_ROWS) {
				$this->sgbackup->log(
					"Warning: Large table detected: {$table} ({$row_count} rows)",
					'warning'
				);
			}
		}

		// Gap 21: Data-optional tables (structure only)
		$opts_instance = SiteGuard_Backup_Options::instance();
		$data_optional_raw = $opts_instance->get_option('sgbackup_data_optional_tables', '');
		$data_optional_patterns = array();
		if (!empty($data_optional_raw)) {
			$data_optional_patterns = array_map('trim', explode(',', $data_optional_raw));
			$data_optional_patterns = array_filter($data_optional_patterns);
		}

		// M5: Initialize anonymiser if enabled
		$anonymiser = null;
		$db_anonymise = $this->sgbackup->jobdata_get('db_anonymise', false);
		if ($db_anonymise) {
			$anonymise_mode = $this->sgbackup->jobdata_get('db_anonymise_mode', 'all');
			require_once SGBACKUP_DIR . 'includes/class-db-anonymiser.php';
			$current_user_id = get_current_user_id();
			$anonymiser = new SiteGuard_Backup_DB_Anonymiser($anonymise_mode, $current_user_id);
			$this->sgbackup->log("Database anonymisation enabled (mode: {$anonymise_mode})");
		}

		// Check which tables are already done
		$completed_tables = $this->sgbackup->dbtables_get();
		$failed_tables = $this->sgbackup->jobdata_get('db_failed_tables', array());

		// Output file
		$db_filename = $this->zip_basename . '-db.gz';
		$db_path = $this->backup_dir . $db_filename;
		$temp_dir = $this->backup_dir . 'dbtemp_' . $this->sgbackup->nonce . '/';

		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}

		// Verify completed table dump files still exist on disk.
		// On Azure CIFS or after container restarts, temp files can vanish.
		// Remove orphaned entries so those tables get re-dumped.
		if (!empty($completed_tables)) {
			$valid_completed = array();
			$orphaned = 0;
			foreach ($completed_tables as $ct) {
				// External-DB markers (ext{N}_{table}, ext{N}__stitched) live in their
				// own dbtemp_ext{N}_ dir - they must not be treated as orphans here,
				// or every resumption re-dumps the external DBs from scratch.
				if (preg_match('/^ext\d+_/', $ct)) {
					$valid_completed[] = $ct;
					continue;
				}
				$ct_file = $temp_dir . $ct . '.sql.gz';
				clearstatcache(true, $ct_file);
				if (file_exists($ct_file) && filesize($ct_file) > 0) {
					$valid_completed[] = $ct;
				} else {
					$orphaned++;
				}
			}
			if ($orphaned > 0) {
				$this->sgbackup->log("Found {$orphaned} completed tables with missing dump files - will re-dump them");
				// Identify which tables lost their dump files so we can clear
				// any stale failed_tables entries for them (gives a fresh attempt)
				$orphaned_names = array_diff($completed_tables, $valid_completed);
				$completed_tables = $valid_completed;
				$this->sgbackup->dbtables_set($completed_tables);
				if (!empty($failed_tables) && !empty($orphaned_names)) {
					$failed_tables = array_diff($failed_tables, $orphaned_names);
					$this->sgbackup->jobdata_set('db_failed_tables', $failed_tables);
				}
			}
		}

		// Try mysqldump first (only when anonymisation is not enabled, since mysqldump
		// cannot apply per-row anonymisation)
		$use_mysqldump = (!$anonymiser) && SiteGuard_Backup_Database_Utility::has_mysqldump();
		if ($use_mysqldump) {
			$this->sgbackup->log("Using mysqldump binary");
		} else {
			$this->sgbackup->log("Using PHP database export" . ($anonymiser ? ' (required for anonymisation)' : ' (mysqldump not available)'));
		}

		foreach ($tables as $table) {
			if (in_array($table, $completed_tables)) continue;
			if (in_array($table, $failed_tables)) continue;

			// Gap 21: Check if this table is structure-only
			$structure_only = false;
			if (!empty($data_optional_patterns)) {
				foreach ($data_optional_patterns as $pattern) {
					if (fnmatch($pattern, $table)) {
						$structure_only = true;
						$this->sgbackup->log("Table {$table} matched data-optional pattern '{$pattern}', dumping structure only");
						break;
					}
				}
			}

			$this->sgbackup->log("Backing up table: {$table}" . ($structure_only ? ' (structure only)' : ''));

			$table_file = $temp_dir . $table . '.sql.gz';
			$success = false;

			if ($structure_only) {
				// Gap 21: Dump only CREATE TABLE, no INSERT statements
				$success = SiteGuard_Backup_Database_Utility::php_dump_table($table, $table_file, 1000, true);
			} elseif ($use_mysqldump) {
				// Dump to plain SQL first, then gzip
				$plain_file = $temp_dir . $table . '.sql';
				$success = SiteGuard_Backup_Database_Utility::mysqldump_table($table, $plain_file);
				if ($success) {
					// Gzip the file
					$success = $this->gzip_file($plain_file, $table_file);
					@unlink($plain_file);
				}
			}

			if (!$success && !$structure_only) {
				// PHP fallback (also used when anonymiser is active)
				if ($anonymiser) {
					$success = SiteGuard_Backup_Database_Utility::php_dump_table_with_connection($table, $table_file, $wpdb, 1000, $anonymiser);
				} else {
					$success = SiteGuard_Backup_Database_Utility::php_dump_table($table, $table_file);
				}
			}

			if ($success) {
				$completed_tables[] = $table;
				$this->sgbackup->dbtables_set($completed_tables);
				$this->sgbackup->jobdata_set('db_tables_done', count($completed_tables));
				$this->sgbackup->jobdata_set('db_tables_total', count($tables));
				$this->sgbackup->jobdata_set('last_activity', "DB: {$table} (" . count($completed_tables) . "/" . count($tables) . ")");
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
			} else {
				$this->sgbackup->log("Failed to backup table: {$table}", 'error');
				// Track permanently failed tables to prevent infinite retry loops.
				// A table that fails both mysqldump and PHP fallback likely has a
				// real issue (dropped, permissions, corruption) - don't retry.
				if (!in_array($table, $failed_tables)) {
					$failed_tables[] = $table;
					$this->sgbackup->jobdata_set('db_failed_tables', $failed_tables);
				}
			}

			// Check time limit
			$elapsed = microtime(true) - $this->sgbackup->job_time_ms;
			if ($elapsed > (SGBACKUP_SET_TIME_LIMIT * 0.7)) {
				$this->sgbackup->log("Approaching time limit, requesting resumption");
				return 'needs_resumption';
			}
		}

		// Dump views and stored routines (once only - skip if already done on a prior resumption)
		$extras_file = $temp_dir . '_views_routines.sql';
		if (!file_exists($extras_file)) {
			SiteGuard_Backup_Database_Utility::dump_views($table_prefix, $extras_file);
			SiteGuard_Backup_Database_Utility::dump_routines($extras_file);
		}

		// Pre-stitch verification: ensure every table has a dump file or is known-failed.
		// This catches dump files that vanished between resumptions (Azure CIFS, container
		// restarts). Tables that permanently failed to dump are accepted as losses.
		$failed_tables = $this->sgbackup->jobdata_get('db_failed_tables', array());
		$total_dump_size = 0;
		$dump_count = 0;
		$unattempted = 0;
		$stitch_tables = array();
		foreach ($tables as $t) {
			$tf = $temp_dir . $t . '.sql.gz';
			clearstatcache(true, $tf);
			if (file_exists($tf) && filesize($tf) > 0) {
				$total_dump_size += filesize($tf);
				$dump_count++;
				$stitch_tables[] = $t;
			} elseif (!in_array($t, $failed_tables)) {
				$unattempted++;
			}
		}
		if ($unattempted > 0) {
			$this->sgbackup->log("WARNING: {$unattempted}/" . count($tables) . " tables have no dump file and haven't failed - re-dumping");
			$this->sgbackup->dbtables_set($stitch_tables);
			return 'needs_resumption';
		}
		if (!empty($failed_tables)) {
			$relevant_failed = array_intersect($failed_tables, $tables);
			if (!empty($relevant_failed)) {
				$this->sgbackup->log("WARNING: " . count($relevant_failed) . " tables could not be dumped: " .
					implode(', ', array_slice($relevant_failed, 0, 20)) .
					(count($relevant_failed) > 20 ? '...' : ''), 'warning');
			}
		}

		$this->sgbackup->log("Stitching {$dump_count}/" . count($tables) . " table dumps (" .
			SiteGuard_Backup_Filesystem::format_bytes($total_dump_size) . " compressed) into {$db_filename}");

		$free_space = @disk_free_space($this->backup_dir);
		if (false !== $free_space && $free_space < $total_dump_size * 2) {
			$this->sgbackup->log("WARNING: Low disk space for stitch - " .
				SiteGuard_Backup_Filesystem::format_bytes($free_space) . " free, " .
				SiteGuard_Backup_Filesystem::format_bytes($total_dump_size) . " in dumps", 'warning');
		}

		$result = $this->stitch_db_files($temp_dir, $db_path, $stitch_tables);

		$db_backup_files = array();
		if ($result) {
			$db_backup_files[] = $db_filename;
			$size = file_exists($db_path) ? SiteGuard_Backup_Filesystem::format_bytes(filesize($db_path)) : '0 B';
			$this->sgbackup->log("Database backup complete: {$db_filename} ({$size})");
		} else {
			// Do NOT remove the temp dir: the per-table dumps are the only copy
			// of the data and a stitch failure is often transient (disk pressure).
			$stitch_failures = intval($this->sgbackup->jobdata_get('db_stitch_failures', 0)) + 1;
			$this->sgbackup->jobdata_set('db_stitch_failures', $stitch_failures);
			if ($stitch_failures < 3) {
				$this->sgbackup->log("Failed to stitch database files (attempt {$stitch_failures}/3) - keeping per-table dumps, will retry on next resumption", 'error');
				return 'needs_resumption';
			}
			$this->sgbackup->log("Failed to stitch database files after {$stitch_failures} attempts - keeping per-table dumps in {$temp_dir} for manual salvage", 'error');
			$this->sgbackup->jobdata_set('db_backup_files', $db_backup_files);
			return false;
		}

		// M3: Backup external databases
		$extradbs = $this->sgbackup->jobdata_get('extradbs', array());
		if (!empty($extradbs) && is_array($extradbs)) {
			$extdb_index = 0;
			foreach ($extradbs as $extdb) {
				$extdb_index++;
				if (empty($extdb['host']) || empty($extdb['name'])) {
					$this->sgbackup->log("External DB #{$extdb_index}: missing host or name, skipping", 'warning');
					continue;
				}

				// Already stitched on a previous resumption (its temp dir is gone,
				// so re-running would overwrite the good file with an empty one)
				$extdb_filename = $this->zip_basename . "-extdb{$extdb_index}.gz";
				$extdb_path = $this->backup_dir . $extdb_filename;
				if (in_array("ext{$extdb_index}__stitched", $this->sgbackup->dbtables_get())) {
					if (file_exists($extdb_path) && filesize($extdb_path) > 0) {
						$db_backup_files[] = $extdb_filename;
					}
					$this->sgbackup->log("External DB #{$extdb_index}: already backed up on a previous resumption - skipping");
					continue;
				}

				$this->sgbackup->log("Backing up external database #{$extdb_index}: {$extdb['name']}@{$extdb['host']}");

				$ext_wpdb = new wpdb(
					isset($extdb['user']) ? $extdb['user'] : '',
					isset($extdb['pass']) ? $extdb['pass'] : '',
					$extdb['name'],
					$extdb['host']
				);

				if (!empty($ext_wpdb->error)) {
					$this->sgbackup->log("External DB #{$extdb_index}: connection failed", 'error');
					continue;
				}

				// Get tables from external DB (filtered by prefix if set)
				$ext_prefix = isset($extdb['prefix']) ? $extdb['prefix'] : '';
				if (!empty($ext_prefix)) {
					$ext_tables = $ext_wpdb->get_col($ext_wpdb->prepare("SHOW TABLES LIKE %s", $ext_wpdb->esc_like($ext_prefix) . '%'));
				} else {
					$ext_tables = $ext_wpdb->get_col("SHOW TABLES");
				}

				if (empty($ext_tables)) {
					$this->sgbackup->log("External DB #{$extdb_index}: no tables found", 'warning');
					continue;
				}

				$this->sgbackup->log("External DB #{$extdb_index}: found " . count($ext_tables) . " tables");

				$extdb_temp_dir = $this->backup_dir . 'dbtemp_ext' . $extdb_index . '_' . $this->sgbackup->nonce . '/';
				if (!file_exists($extdb_temp_dir)) {
					wp_mkdir_p($extdb_temp_dir);
				}

				$completed_tables = $this->sgbackup->dbtables_get();
				foreach ($ext_tables as $ext_table) {
					$safe_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $ext_table);
					if (empty($safe_table_name) || $safe_table_name !== $ext_table) {
						$this->sgbackup->log("External DB #{$extdb_index}: skipping table with unsafe name: {$ext_table}", 'warning');
						continue;
					}
					// Track external tables in the completed store with an ext{i}_
					// prefix so a resumption doesn't re-dump them.
					$ext_completed_key = "ext{$extdb_index}_{$safe_table_name}";
					if (in_array($ext_completed_key, $completed_tables)) continue;

					$ext_table_file = $extdb_temp_dir . $safe_table_name . '.sql.gz';
					$ext_success = SiteGuard_Backup_Database_Utility::php_dump_table_with_connection($ext_table, $ext_table_file, $ext_wpdb);
					if (!$ext_success) {
						$this->sgbackup->log("External DB #{$extdb_index}: failed to backup table {$ext_table}", 'error');
					} else {
						$completed_tables[] = $ext_completed_key;
						$this->sgbackup->dbtables_set($completed_tables);
					}
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();

					$elapsed = microtime(true) - $this->sgbackup->job_time_ms;
					if ($elapsed > (SGBACKUP_SET_TIME_LIMIT * 0.7)) {
						$this->sgbackup->log("Approaching time limit during external DB backup, requesting resumption");
						return 'needs_resumption';
					}
				}

				// Stitch external DB into its own file
				$ext_result = $this->stitch_db_files($extdb_temp_dir, $extdb_path, $ext_tables);

				if ($ext_result) {
					$db_backup_files[] = $extdb_filename;
					$completed_tables = $this->sgbackup->dbtables_get();
					$completed_tables[] = "ext{$extdb_index}__stitched";
					$this->sgbackup->dbtables_set($completed_tables);
					$ext_size = file_exists($extdb_path) ? SiteGuard_Backup_Filesystem::format_bytes(filesize($extdb_path)) : '0 B';
					$this->sgbackup->log("External DB #{$extdb_index} backup complete: {$extdb_filename} ({$ext_size})");
				} else {
					$this->sgbackup->log("External DB #{$extdb_index}: failed to stitch files", 'error');
				}

				$this->remove_dir($extdb_temp_dir);
			}
		}

		// Clean up main temp dir
		$this->remove_dir($temp_dir);

		$this->sgbackup->jobdata_set('db_backup_files', $db_backup_files);

		if (empty($db_backup_files)) {
			return false;
		}

		return true;
	}

	/**
	 * Backup only the tables and users belonging to a specific subsite.
	 *
	 * Exports: wp_{blog_id}_* tables + filtered wp_users/wp_usermeta rows.
	 *
	 * @param int $blog_id The subsite blog ID.
	 * @return true|string|false True on success, 'needs_resumption', or false on failure.
	 */
	private function backup_subsite_database($blog_id) {
		global $wpdb;

		$base_prefix = $wpdb->base_prefix;
		$subsite_prefix = $base_prefix . $blog_id . '_';

		// Get subsite-specific tables
		$subsite_tables = SiteGuard_Backup_Database_Utility::get_tables($subsite_prefix);
		if (empty($subsite_tables)) {
			$this->sgbackup->log("No tables found with prefix {$subsite_prefix}", 'warning');
			return true;
		}

		$this->sgbackup->log("Subsite backup (blog #{$blog_id}): " . count($subsite_tables) . " tables with prefix {$subsite_prefix}");

		// Initialise the anonymiser if enabled. Subsite dumps previously ignored
		// db_anonymise entirely, so per-subsite PII shipped in cleartext despite
		// the "anonymisation enabled" log. Route dumps through the anonymiser too.
		$anonymiser = null;
		if ($this->sgbackup->jobdata_get('db_anonymise', false)) {
			$anonymise_mode = $this->sgbackup->jobdata_get('db_anonymise_mode', 'all');
			require_once SGBACKUP_DIR . 'includes/class-db-anonymiser.php';
			$anonymiser = new SiteGuard_Backup_DB_Anonymiser($anonymise_mode, get_current_user_id());
			$this->sgbackup->log("Subsite backup: anonymisation enabled (mode: {$anonymise_mode})");
		}

		// Get user IDs associated with this subsite
		$user_ids = SiteGuard_Backup_Database_Utility::get_subsite_user_ids($blog_id);
		$this->sgbackup->log("Subsite backup: " . count($user_ids) . " users associated with blog #{$blog_id}");

		// Output file
		$db_filename = $this->zip_basename . '-db.gz';
		$db_path = $this->backup_dir . $db_filename;
		$temp_dir = $this->backup_dir . 'dbtemp_' . $this->sgbackup->nonce . '/';

		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}

		$completed_tables = $this->sgbackup->dbtables_get();
		$failed_tables = $this->sgbackup->jobdata_get('db_failed_tables', array());
		$all_tables = $subsite_tables;

		// Verify completed table dump files still exist on disk
		if (!empty($completed_tables)) {
			$valid_completed = array();
			$orphaned = 0;
			foreach ($completed_tables as $ct) {
				$ct_file = $temp_dir . $ct . '.sql.gz';
				clearstatcache(true, $ct_file);
				if (file_exists($ct_file) && filesize($ct_file) > 0) {
					$valid_completed[] = $ct;
				} else {
					$orphaned++;
				}
			}
			if ($orphaned > 0) {
				$this->sgbackup->log("Found {$orphaned} completed tables with missing dump files - will re-dump them");
				$completed_tables = $valid_completed;
				$this->sgbackup->dbtables_set($completed_tables);
			}
		}

		// Dump subsite tables
		foreach ($subsite_tables as $table) {
			if (in_array($table, $completed_tables)) continue;
			if (in_array($table, $failed_tables)) continue;

			$this->sgbackup->log("Backing up table: {$table}");
			$table_file = $temp_dir . $table . '.sql.gz';

			$success = SiteGuard_Backup_Database_Utility::php_dump_table($table, $table_file, 1000, false, '', $anonymiser);

			if ($success) {
				$completed_tables[] = $table;
				$this->sgbackup->dbtables_set($completed_tables);
				$this->sgbackup->jobdata_set('db_tables_done', count($completed_tables));
				$this->sgbackup->jobdata_set('db_tables_total', count($all_tables) + 2); // +2 for users tables
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
			} else {
				$this->sgbackup->log("Failed to backup table: {$table}", 'error');
				if (!in_array($table, $failed_tables)) {
					$failed_tables[] = $table;
					$this->sgbackup->jobdata_set('db_failed_tables', $failed_tables);
				}
			}

			$elapsed = microtime(true) - $this->sgbackup->job_time_ms;
			if ($elapsed > (SGBACKUP_SET_TIME_LIMIT * 0.7)) {
				return 'needs_resumption';
			}
		}

		// Dump filtered wp_users and wp_usermeta for this subsite's users
		if (!empty($user_ids)) {
			$users_table = $base_prefix . 'users';
			$usermeta_table = $base_prefix . 'usermeta';

			if (!in_array($users_table, $completed_tables) && !in_array($users_table, $failed_tables)) {
				$users_file = $temp_dir . $users_table . '.sql.gz';
				$id_list = implode(',', array_map('intval', $user_ids));
				$success = SiteGuard_Backup_Database_Utility::php_dump_table($users_table, $users_file, 1000, false, "ID IN ({$id_list})", $anonymiser);
				if ($success) {
					$completed_tables[] = $users_table;
					$this->sgbackup->dbtables_set($completed_tables);
					$this->sgbackup->log("Backed up {$users_table} (filtered: " . count($user_ids) . " users)");
				} else {
					$this->sgbackup->log("Failed to backup table: {$users_table}", 'error');
					if (!in_array($users_table, $failed_tables)) {
						$failed_tables[] = $users_table;
						$this->sgbackup->jobdata_set('db_failed_tables', $failed_tables);
					}
				}
			}

			if (!in_array($usermeta_table, $completed_tables) && !in_array($usermeta_table, $failed_tables)) {
				$usermeta_file = $temp_dir . $usermeta_table . '.sql.gz';
				$id_list = implode(',', array_map('intval', $user_ids));
				$success = SiteGuard_Backup_Database_Utility::php_dump_table($usermeta_table, $usermeta_file, 1000, false, "user_id IN ({$id_list})", $anonymiser);
				if ($success) {
					$completed_tables[] = $usermeta_table;
					$this->sgbackup->dbtables_set($completed_tables);
					$this->sgbackup->log("Backed up {$usermeta_table} (filtered: " . count($user_ids) . " users)");
				} else {
					$this->sgbackup->log("Failed to backup table: {$usermeta_table}", 'error');
					if (!in_array($usermeta_table, $failed_tables)) {
						$failed_tables[] = $usermeta_table;
						$this->sgbackup->jobdata_set('db_failed_tables', $failed_tables);
					}
				}
			}
		}

		// Stitch all per-table files into single .gz
		$all_dump_tables = array_merge($subsite_tables, !empty($user_ids) ? array($base_prefix . 'users', $base_prefix . 'usermeta') : array());

		// Pre-stitch verification: ensure all dump files exist
		$dump_count = 0;
		$unattempted = 0;
		$valid_completed = array();
		foreach ($all_dump_tables as $t) {
			$tf = $temp_dir . $t . '.sql.gz';
			if (file_exists($tf) && filesize($tf) > 0) {
				$dump_count++;
				$valid_completed[] = $t;
			} elseif (!in_array($t, $failed_tables)) {
				$unattempted++;
			}
		}
		if ($unattempted > 0) {
			$this->sgbackup->log("WARNING: {$unattempted}/" . count($all_dump_tables) . " subsite tables have no dump file - re-dumping");
			$this->sgbackup->dbtables_set($valid_completed);
			return 'needs_resumption';
		}

		$this->sgbackup->log("Stitching subsite database backup into {$db_filename}");
		$result = $this->stitch_db_files($temp_dir, $db_path, $all_dump_tables);

		$db_backup_files = array();
		if ($result) {
			$db_backup_files[] = $db_filename;
			$size = file_exists($db_path) ? SiteGuard_Backup_Filesystem::format_bytes(filesize($db_path)) : '0 B';
			$this->sgbackup->log("Subsite database backup complete: {$db_filename} ({$size})");
		} else {
			$this->sgbackup->log("Failed to stitch subsite database files", 'error');
		}

		$this->remove_dir($temp_dir);
		$this->sgbackup->jobdata_set('db_backup_files', $db_backup_files);

		return !empty($db_backup_files);
	}

	/**
	 * Stitch per-table .sql.gz files into a single .gz file.
	 */
	private function stitch_db_files($temp_dir, $output_path, $tables) {
		$out = @gzopen($output_path, 'wb9');
		if (!$out) {
			$this->sgbackup->log("stitch_db_files: failed to open output file: {$output_path}", 'error');
			return false;
		}

		$tables_stitched = 0;
		$tables_skipped = 0;
		$bytes_written = 0;
		$write_error = false;

		// Header with charset declaration for cross-server restore compatibility
		$db_charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
		$header = "-- SiteGuard Backup database dump\n";
		$header .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
		$header .= "-- WordPress DB prefix: " . $GLOBALS['wpdb']->prefix . "\n";
		$header .= "-- Database: " . DB_NAME . "\n\n";
		$header .= "/*!40101 SET NAMES {$db_charset} */;\n";
		$header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
		$header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
		$w = gzwrite($out, $header);
		if (false === $w) { $write_error = true; }
		else { $bytes_written += $w; }

		foreach ($tables as $table) {
			if ($write_error) break;

			$table_file = $temp_dir . $table . '.sql.gz';
			clearstatcache(true, $table_file);
			if (!file_exists($table_file)) {
				$tables_skipped++;
				continue;
			}

			$in = @gzopen($table_file, 'rb');
			if (!$in) {
				$this->sgbackup->log("stitch_db_files: cannot open table file: {$table}", 'warning');
				$tables_skipped++;
				continue;
			}

			$w = gzwrite($out, "\n-- Table: {$table}\n");
			if (false === $w) { $write_error = true; gzclose($in); break; }
			$bytes_written += $w;

			while (!gzeof($in)) {
				$chunk = gzread($in, 262144);
				if (false === $chunk || strlen($chunk) === 0) break;
				$w = gzwrite($out, $chunk);
				if (false === $w) { $write_error = true; break; }
				$bytes_written += $w;
			}

			gzclose($in);
			if ($write_error) break;

			$w = gzwrite($out, "\n");
			if (false === $w) { $write_error = true; break; }
			$bytes_written += $w;

			$tables_stitched++;

			// Reset time limit per table to prevent timeout during large stitches
			@set_time_limit(SGBACKUP_SET_TIME_LIMIT);

			// Log progress every 50 tables and signal activity
			if ($tables_stitched % 50 === 0) {
				$this->sgbackup->log("Stitch progress: {$tables_stitched} tables, " .
					SiteGuard_Backup_Filesystem::format_bytes($bytes_written) . " uncompressed written");
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
			}
		}

		if ($write_error) {
			$this->sgbackup->log("stitch_db_files: gzwrite failed after {$tables_stitched} tables and " .
				SiteGuard_Backup_Filesystem::format_bytes($bytes_written) . " written. " .
				"Possible causes: disk full, memory exhausted, or gzip stream error.", 'error');
			gzclose($out);
			@unlink($output_path);
			return false;
		}

		// Append views and routines if they exist
		$extras_file = $temp_dir . '_views_routines.sql';
		if (file_exists($extras_file) && filesize($extras_file) > 0) {
			$efh = @fopen($extras_file, 'r');
			if ($efh) {
				while (!feof($efh)) {
					$chunk = fread($efh, 262144);
					if (false !== $chunk && strlen($chunk) > 0) {
						$w = gzwrite($out, $chunk);
						if (false === $w) {
							$this->sgbackup->log("stitch_db_files: gzwrite failed during views/routines", 'error');
							$write_error = true;
							break;
						}
						$bytes_written += $w;
					}
				}
				fclose($efh);
			}
		}

		$w = gzwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
		if (false === $w) { $write_error = true; }
		if (!gzclose($out)) {
			$this->sgbackup->log("stitch_db_files: gzclose() failed for output file {$output_path} - output may be truncated", 'error');
			@unlink($output_path);
			return false;
		}

		if ($write_error) {
			@unlink($output_path);
			return false;
		}

		$this->sgbackup->log("Stitch complete: {$tables_stitched} tables, {$tables_skipped} skipped, " .
			SiteGuard_Backup_Filesystem::format_bytes($bytes_written) . " uncompressed data");

		clearstatcache(true, $output_path);
		return file_exists($output_path) && filesize($output_path) > 0;
	}

	/**
	 * Gzip a file.
	 */
	private function gzip_file($source, $dest) {
		$in = @fopen($source, 'rb');
		if (!$in) return false;

		$out = @gzopen($dest, 'wb9');
		if (!$out) {
			fclose($in);
			return false;
		}

		$write_error = false;
		while (!feof($in)) {
			$chunk = fread($in, 262144);
			if (false === $chunk) break;
			$w = gzwrite($out, $chunk);
			if (false === $w) { $write_error = true; break; }
		}

		fclose($in);
		if (!gzclose($out)) {
			$this->sgbackup->log("gzip_file: gzclose() failed for {$dest} - output may be truncated", 'error');
			@unlink($dest);
			return false;
		}

		if ($write_error) {
			$this->sgbackup->log("gzip_file: write failed for {$source}", 'error');
			@unlink($dest);
			return false;
		}

		return true;
	}

	/**
	 * Remove a directory and all its contents.
	 */
	private function remove_dir($dir) {
		$r = SiteGuard_Backup_Filesystem::delete_tree($dir, $this->backup_dir);
		if (is_wp_error($r)) {
			$this->sgbackup->log("Could not remove directory {$dir}: " . $r->get_error_message(), 'warning');
		}
	}
}

/**
 * Forward-only reader over a JSONL (newline-delimited JSON) file-list cache.
 *
 * Lets create_zips() resume a large entity without unserializing the entire
 * enumerated file list into memory: entries are read one line at a time with
 * fgets(). create_zips() only needs the total count and sequential access
 * starting from the resume checkpoint, so a forward-only reader suffices.
 */
class SiteGuard_Backup_FileList {

	/** @var resource Open read handle on the JSONL cache */
	private $fh;

	/** @var int Total entries, as recorded in jobdata at enumeration time */
	private $count;

	/** @var int Index of the entry the next next_entry() call returns */
	private $pos = 0;

	private function __construct($fh, $count) {
		$this->fh = $fh;
		$this->count = $count;
	}

	/**
	 * Open a cache file for reading.
	 *
	 * @param string $path  Cache file path.
	 * @param int    $count Expected entry count (from jobdata).
	 * @return SiteGuard_Backup_FileList|false False if unusable (caller should re-enumerate).
	 */
	public static function open($path, $count) {
		if ($count <= 0) return false;
		$fh = @fopen($path, 'rb');
		if (!$fh) return false;
		return new self($fh, $count);
	}

	/** @return int Total number of entries. */
	public function count() {
		return $this->count;
	}

	/**
	 * Skip forward so the next next_entry() call returns entry $index.
	 *
	 * @param int $index Target entry index.
	 * @return bool False if the file ended before reaching $index (corrupt/short cache).
	 */
	public function seek_to($index) {
		while ($this->pos < $index) {
			$line = fgets($this->fh, 65536);
			if (false === $line) return false;
			$this->pos++;
		}
		return true;
	}

	/**
	 * Read the next entry.
	 *
	 * @return array|false Decoded entry, or false on EOF / undecodable line
	 *                     (caller should treat the cache as corrupt).
	 */
	public function next_entry() {
		$line = fgets($this->fh, 65536);
		if (false === $line) return false;
		$this->pos++;
		$entry = json_decode($line, true);
		if (!is_array($entry)) return false;
		// Non-UTF-8 filenames are stored base64-wrapped (see enumerate_to_cache)
		if (isset($entry['path_b64'])) {
			$entry['path'] = base64_decode($entry['path_b64']);
			$entry['full_path'] = isset($entry['full_path_b64']) ? base64_decode($entry['full_path_b64']) : $entry['path'];
			unset($entry['path_b64'], $entry['full_path_b64']);
		}
		return $entry;
	}

	public function close() {
		if ($this->fh) {
			fclose($this->fh);
			$this->fh = null;
		}
	}

	public function __destruct() {
		$this->close();
	}
}
