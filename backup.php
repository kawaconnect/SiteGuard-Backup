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

			$this->sgbackup->log("Backing up entity: {$entity}");
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
				$backup_files_array = array_merge($backup_files_array, $result);
				$entities[$entity]['status'] = 'complete';
				$this->sgbackup->log("Entity {$entity} complete: " . count($result) . " zip file(s)");
			} elseif ($result === true) {
				$entities[$entity]['status'] = 'complete';
				$this->sgbackup->log("Entity {$entity} complete (no files to backup)");
			} else {
				$this->sgbackup->log("Entity {$entity} failed", 'error');
				$entities[$entity]['status'] = 'error';
			}

			$this->sgbackup->jobdata_set('job_file_entities', $entities);
			// Persist after each entity so files aren't lost on resumption
			$this->sgbackup->jobdata_set('backup_files_array', $backup_files_array);
			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		// Record enumeration time for incremental reference
		$this->sgbackup->jobdata_set('files_enumerated_at', time());
		$this->sgbackup->jobdata_set('backup_files_array', $backup_files_array);

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

		// Enumerate files
		$files = SiteGuard_Backup_Filesystem::enumerate_files($source_path, $excludes, $altered_since);
		$this->sgbackup->log("Enumerated " . count($files) . " files in {$entity}");

		if (empty($files)) return true;

		// Gap 18: Warn about large files
		foreach ($files as $file_info) {
			if (isset($file_info['size']) && $file_info['size'] > SGBACKUP_WARN_FILE_SIZE) {
				$this->sgbackup->log(
					"Warning: Large file detected: " . $file_info['path'] .
					" (" . SiteGuard_Backup_Filesystem::format_bytes($file_info['size']) . ")",
					'warning'
				);
			}
		}

		// Create zip(s)
		return $this->create_zips($files, $source_path, $entity);
	}

	/**
	 * Create zip file(s) from a list of files.
	 *
	 * @param array  $files       Array of file info arrays.
	 * @param string $source_path Base path to strip.
	 * @param string $entity      Entity name for the zip filename.
	 * @return array|string       Array of zip filenames or 'needs_resumption'.
	 */
	private function create_zips($files, $source_path, $entity) {
		$created_files = array();
		$maxbatch = $this->sgbackup->jobdata_get('maxzipbatch', 26214400);
		$maxbatch_ceiling = $this->sgbackup->jobdata_get('maxzipbatch_ceiling', 209715200);

		// Resume from last file index if partial
		$last_file_index = $this->sgbackup->jobdata_get("entity_{$entity}_last_file_index", 0);

		$zip_filename = $this->get_zip_filename($entity, $this->index);
		$zip_path = $this->backup_dir . $zip_filename;

		$this->sgbackup->log("Creating zip: {$zip_filename}");

		for ($i = $last_file_index; $i < count($files); $i++) {
			$file = $files[$i];

			// Check batch limits
			if ($this->zipfiles_batched >= $maxbatch || $this->zipfiles_batched >= $maxbatch_ceiling) {
				// Save progress and request resumption
				$this->sgbackup->jobdata_set("entity_{$entity}_last_file_index", $i);
				$this->sgbackup->log("Batch limit reached at file {$i}/" . count($files) .
					" (" . SiteGuard_Backup_Filesystem::format_bytes($this->zipfiles_batched) . ")");

				// Close current zip if we've written to it
				if ($this->zipfiles_added > 0) {
					$created_files[] = $zip_filename;
				}
				$this->sgbackup->jobdata_set('backup_files_array',
					array_merge($this->sgbackup->jobdata_get('backup_files_array', array()), $created_files));

				return 'needs_resumption';
			}

			// Check split threshold
			// For ZipArchive: only check after periodic flush (unflushed==0) to avoid O(n^2) reopens
			$should_check_split = ($this->zip_method !== 'ziparchive')
				|| ($this->zipfiles_added_unflushed === 0 && $this->zipfiles_added > 0);
			if ($should_check_split && file_exists($zip_path)) {
				clearstatcache(true, $zip_path);
				if (filesize($zip_path) >= $this->split_every) {
					$created_files[] = $zip_filename;
					$this->index++;
					$zip_filename = $this->get_zip_filename($entity, $this->index);
					$zip_path = $this->backup_dir . $zip_filename;
					$this->sgbackup->log("Split threshold reached, new zip: {$zip_filename}");
				}
			}

			// Add file to zip
			$result = $this->add_to_zip($zip_path, $file['full_path'], $file['path'], $file['size']);
			if ($result) {
				$this->zipfiles_added++;
				$this->zipfiles_batched += $file['size'];
			}

			// Periodic check-in
			if ($this->zipfiles_added > 0 && $this->zipfiles_added % 500 === 0) {
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
				SiteGuard_Backup_Job_Scheduler::record_still_alive();
			}
		}

		// Final zip
		if ($this->zipfiles_added > 0 || file_exists($zip_path)) {
			$created_files[] = $zip_filename;
		}

		// Clear progress tracker
		$this->sgbackup->jobdata_set("entity_{$entity}_last_file_index", 0);

		// Clean up binzip temp directory
		if ($this->zip_method === 'binzip') {
			$temp_base = $this->backup_dir . 'ziptemp_' . $this->sgbackup->nonce;
			if (is_dir($temp_base)) {
				$this->recursive_rmdir($temp_base);
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
		// Resolve symlinks to get the real file
		$resolved = is_link($file_path) ? realpath($file_path) : $file_path;
		if (!file_exists($resolved) || !is_readable($resolved)) return false;
		// Verify resolved symlink stays within ABSPATH to prevent directory escape
		$real_resolved = realpath($resolved);
		$real_abspath = realpath(ABSPATH);
		if ($real_resolved && $real_abspath && strpos($real_resolved, $real_abspath) !== 0) {
			return false;
		}
		$file_path = $resolved;

		switch ($this->zip_method) {
			case 'binzip':
				return $this->add_to_zip_binzip($zip_path, $file_path, $archive_name);
			case 'ziparchive':
				return $this->add_to_zip_ziparchive($zip_path, $file_path, $archive_name);
			case 'pclzip':
				return $this->add_to_zip_pclzip($zip_path, $file_path, $archive_name);
			default:
				return false;
		}
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
		$cmd = sprintf(
			'cd %s && zip %s %s %s 2>&1',
			escapeshellarg($temp_base),
			$store,
			escapeshellarg($zip_path),
			escapeshellarg($archive_name)
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
			$this->close_ziparchive();
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
			$this->close_ziparchive();
			$this->zipfiles_added_unflushed = 0;
		}

		return $result;
	}

	/**
	 * Close the persistent ZipArchive handle.
	 */
	public function close_ziparchive() {
		if ($this->ziparchive_handle !== null) {
			$this->ziparchive_handle->close();
			$this->ziparchive_handle = null;
			$this->ziparchive_path = null;
		}
	}

	private function recursive_rmdir($dir) {
		if (!is_dir($dir)) return;
		// Ensure directory is within backup_dir to prevent accidental deletion
		$real_dir = realpath($dir);
		$real_backup = realpath($this->backup_dir);
		if (!$real_dir || !$real_backup || strpos($real_dir, $real_backup) !== 0) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}
		@rmdir($dir);
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

		$table_prefix = $wpdb->prefix;

		// M4: Check if non-WordPress tables should be included
		$include_nonwp = $this->sgbackup->jobdata_get('backupdb_nonwp', false);
		$tables = $include_nonwp
			? SiteGuard_Backup_Database_Utility::get_all_tables()
			: SiteGuard_Backup_Database_Utility::get_tables($table_prefix);

		if (empty($tables)) {
			$this->sgbackup->log("No tables found" . ($include_nonwp ? '' : " with prefix {$table_prefix}"), 'warning');
			return true;
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
		$completed_tables = $this->sgbackup->jobdata_get('db_completed_tables', array());

		// Output file
		$db_filename = $this->zip_basename . '-db.gz';
		$db_path = $this->backup_dir . $db_filename;
		$temp_dir = $this->backup_dir . 'dbtemp_' . $this->sgbackup->nonce . '/';

		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
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
				$this->sgbackup->jobdata_set('db_completed_tables', $completed_tables);
				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
			} else {
				$this->sgbackup->log("Failed to backup table: {$table}", 'error');
			}

			// Check time limit
			$elapsed = microtime(true) - $this->sgbackup->job_time_ms;
			if ($elapsed > (SGBACKUP_SET_TIME_LIMIT * 0.7)) {
				$this->sgbackup->log("Approaching time limit, requesting resumption");
				return 'needs_resumption';
			}
		}

		// Dump views and stored routines
		$extras_file = $temp_dir . '_views_routines.sql';
		SiteGuard_Backup_Database_Utility::dump_views($table_prefix, $extras_file);
		SiteGuard_Backup_Database_Utility::dump_routines($extras_file);

		// Stitch per-table files into single .gz
		$this->sgbackup->log("Stitching database backup into {$db_filename}");
		$result = $this->stitch_db_files($temp_dir, $db_path, $tables);

		$db_backup_files = array();
		if ($result) {
			$db_backup_files[] = $db_filename;
			$size = file_exists($db_path) ? SiteGuard_Backup_Filesystem::format_bytes(filesize($db_path)) : '0 B';
			$this->sgbackup->log("Database backup complete: {$db_filename} ({$size})");
		} else {
			$this->sgbackup->log("Failed to stitch database files", 'error');
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

				foreach ($ext_tables as $ext_table) {
					$ext_table_file = $extdb_temp_dir . $ext_table . '.sql.gz';
					$ext_success = SiteGuard_Backup_Database_Utility::php_dump_table_with_connection($ext_table, $ext_table_file, $ext_wpdb);
					if (!$ext_success) {
						$this->sgbackup->log("External DB #{$extdb_index}: failed to backup table {$ext_table}", 'error');
					}
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();
				}

				// Stitch external DB into its own file
				$extdb_filename = $this->zip_basename . "-extdb{$extdb_index}.gz";
				$extdb_path = $this->backup_dir . $extdb_filename;
				$ext_result = $this->stitch_db_files($extdb_temp_dir, $extdb_path, $ext_tables);

				if ($ext_result) {
					$db_backup_files[] = $extdb_filename;
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
	 * Stitch per-table .sql.gz files into a single .gz file.
	 */
	private function stitch_db_files($temp_dir, $output_path, $tables) {
		$out = @gzopen($output_path, 'wb9');
		if (!$out) return false;

		// Header
		$header = "-- SiteGuard Backup database dump\n";
		$header .= "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
		$header .= "-- WordPress DB prefix: " . $GLOBALS['wpdb']->prefix . "\n";
		$header .= "-- Database: " . DB_NAME . "\n\n";
		$header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
		$header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
		gzwrite($out, $header);

		foreach ($tables as $table) {
			$table_file = $temp_dir . $table . '.sql.gz';
			if (!file_exists($table_file)) continue;

			$in = @gzopen($table_file, 'rb');
			if (!$in) continue;

			gzwrite($out, "\n-- Table: {$table}\n");

			while (!gzeof($in)) {
				$chunk = gzread($in, 65536);
				if (false === $chunk) break;
				gzwrite($out, $chunk);
			}

			gzclose($in);
			gzwrite($out, "\n");
		}

		// Append views and routines if they exist
		$extras_file = $temp_dir . '_views_routines.sql';
		if (file_exists($extras_file) && filesize($extras_file) > 0) {
			$efh = @fopen($extras_file, 'r');
			if ($efh) {
				while (!feof($efh)) {
					$chunk = fread($efh, 65536);
					if (false !== $chunk && strlen($chunk) > 0) {
						gzwrite($out, $chunk);
					}
				}
				fclose($efh);
			}
		}

		gzwrite($out, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
		gzclose($out);

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

		while (!feof($in)) {
			$chunk = fread($in, 65536);
			if (false === $chunk) break;
			gzwrite($out, $chunk);
		}

		fclose($in);
		gzclose($out);

		return true;
	}

	/**
	 * Remove a directory and all its contents.
	 */
	private function remove_dir($dir) {
		if (!is_dir($dir)) return;
		// Validate directory is within backup directory to prevent accidental deletion
		$real_dir = realpath($dir);
		$real_backup = realpath($this->backup_dir);
		if (!$real_dir || !$real_backup || strpos($real_dir, $real_backup) !== 0) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			if ($item->isDir()) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
	}
}
