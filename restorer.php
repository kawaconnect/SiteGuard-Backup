<?php
/**
 * SiteGuard Backup - Restore Engine
 *
 * Handles per-entity unzip, database import, search-replace, and post-restore validation.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Restorer {

	/** Move-in mode constants */
	const MOVEIN_OVERWRITE = 0;        // Overwrite without backup (current behavior)
	const MOVEIN_BACKUP_EXISTING = 1;  // Make backup of existing before overwrite
	const MOVEIN_SKIP_EXISTING = 2;    // Do nothing if file already exists
	const MOVEIN_MERGE_CONTENTS = 3;   // Copy contents into existing directory

	/** @var SiteGuard_Backup */
	private $sgbackup;

	/** @var array Backup set data */
	private $backup_set;

	/** @var array Restore options */
	private $restore_options;

	/** @var array Old URLs from the backup source */
	private $old_siteurl = '';
	private $old_home = '';
	private $old_content = '';
	private $old_uploads = '';
	private $old_table_prefix = '';

	/** @var string Import table prefix */
	private $import_table_prefix;

	/** @var array Tables already dropped */
	private $tables_dropped = array();

	/** @var array Progress messages */
	private $progress = array();

	/** @var bool Whether this is a migration (URL change) */
	private $is_migration = false;

	/** @var int Move-in mode for file restore */
	private $move_in_mode = 0;

	/** @var bool Whether to include unspecified tables in restore */
	private $include_unspecified_tables = true;

	/** @var bool Whether to include unspecified plugins in restore */
	private $include_unspecified_plugins = true;

	/** @var bool Whether to include unspecified themes in restore */
	private $include_unspecified_themes = true;

	/** @var string Pre-import URLs saved before DB overwrite */
	private $pre_import_siteurl = '';
	private $pre_import_home = '';
	private $pre_import_content_url = '';
	private $pre_import_uploads_url = '';

	/**
	 * @param array $backup_set     Backup set from history.
	 * @param array $restore_options What to restore: ['db' => bool, 'plugins' => bool, etc.]
	 */
	public function __construct($backup_set, $restore_options = array()) {
		$this->sgbackup = SiteGuard_Backup::instance();
		$this->backup_set = $backup_set;
		$this->restore_options = $restore_options;
		$this->import_table_prefix = $GLOBALS['wpdb']->prefix;

		// Gap 23: Move-in mode
		if (isset($restore_options['move_in_mode'])) {
			$this->move_in_mode = intval($restore_options['move_in_mode']);
		}

		// Gap 25: Include-unspecified options
		if (isset($restore_options['include_unspecified_tables'])) {
			$this->include_unspecified_tables = (bool) $restore_options['include_unspecified_tables'];
		}
		if (isset($restore_options['include_unspecified_plugins'])) {
			$this->include_unspecified_plugins = (bool) $restore_options['include_unspecified_plugins'];
		}
		if (isset($restore_options['include_unspecified_themes'])) {
			$this->include_unspecified_themes = (bool) $restore_options['include_unspecified_themes'];
		}
	}

	/**
	 * Perform the restore.
	 *
	 * @return array ['success' => bool, 'messages' => array, 'errors' => array]
	 */
	public function perform_restore() {
		@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
		@ignore_user_abort(true);

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		$result = array('success' => true, 'messages' => array(), 'errors' => array());

		$this->progress("Starting restore");

		// Open log
		$nonce = isset($this->backup_set['nonce']) ? $this->backup_set['nonce'] : 'restore_' . time();
		$this->sgbackup->nonce = 'restore_' . substr(wp_generate_password(8, false), 0, 8);
		$this->sgbackup->logfile_open($this->sgbackup->nonce);

		// Phase 0: Create pre-restore backup if requested
		if (!empty($this->restore_options['pre_restore_backup'])) {
			$this->progress("Creating pre-restore backup");
			$this->create_pre_restore_backup();
		}

		// Phase 1: Verify all required files are present and validate integrity
		$entities_to_restore = $this->get_entities_to_restore();
		$files_result = $this->ensure_files_present($entities_to_restore, $backup_dir);
		if (is_wp_error($files_result)) {
			$result['success'] = false;
			$result['errors'][] = $files_result->get_error_message();
			$this->sgbackup->logfile_close();
			return $result;
		}

		// Validate file integrity against manifest if available
		$integrity_errors = $this->verify_backup_integrity($backup_dir);
		if (!empty($integrity_errors)) {
			foreach ($integrity_errors as $err) {
				$this->progress("Integrity error: {$err}");
			}
			if (empty($this->restore_options['skip_integrity_check'])) {
				$result['success'] = false;
				$result['errors'][] = count($integrity_errors) . ' file(s) failed integrity verification. Restore aborted. Use skip_integrity_check option to override.';
				$this->sgbackup->logfile_close();
				return $result;
			}
			$this->progress("Integrity check failures overridden by skip_integrity_check option");
		}

		// Phase 2: Restore each entity
		// Restore database first (if requested)
		if (!empty($this->restore_options['db']) && !empty($this->backup_set['db'])) {
			$this->progress("Restoring database");
			$db_result = $this->restore_database($backup_dir);
			if (!$db_result['success']) {
				$result['errors'] = array_merge($result['errors'], $db_result['errors']);
				$result['success'] = false;
			}
			$result['messages'][] = "Database: {$db_result['tables_restored']} tables restored";
		}

		// Restore file entities (standard + extra directories)
		$standard_entities = array('plugins', 'themes', 'uploads', 'others', 'wpcore');
		$all_entities = $standard_entities;

		// Include any extra directory entities from the backup set
		foreach (array_keys($this->backup_set) as $key) {
			if (strpos($key, 'extradir_') === 0 && !in_array($key, $all_entities, true)) {
				$all_entities[] = $key;
			}
		}

		foreach ($all_entities as $entity) {
			if (empty($this->restore_options[$entity])) continue;
			if (empty($this->backup_set[$entity])) continue;

			$this->progress("Restoring {$entity}");
			$entity_result = $this->restore_file_entity($entity, $backup_dir);
			if (!$entity_result) {
				$result['errors'][] = "Failed to restore {$entity}";
				$result['success'] = false;
			} else {
				$result['messages'][] = ucfirst($entity) . " restored";
			}
		}

		// Phase 3: Post-restore
		$this->post_restore_cleanup();

		$result['messages'] = array_merge($this->progress, $result['messages']);
		$this->sgbackup->logfile_close();

		return $result;
	}

	/**
	 * Determine which entities to restore based on options and available data.
	 */
	private function get_entities_to_restore() {
		$entities = array();
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity) {
			if (!empty($this->restore_options[$entity]) && !empty($this->backup_set[$entity])) {
				$entities[] = $entity;
			}
		}
		return $entities;
	}

	/**
	 * Verify all required backup files are present.
	 */
	private function ensure_files_present($entities, $backup_dir) {
		foreach ($entities as $entity) {
			$files = $this->backup_set[$entity];
			if (is_string($files)) $files = array($files);

			foreach ($files as $file) {
				// Prevent path traversal via tainted backup history
				if (basename($file) !== $file || strpos($file, '..') !== false) {
					return new WP_Error('invalid_file', "Unsafe filename rejected: {$file}");
				}
				$path = $backup_dir . $file;
				if (!file_exists($path)) {
					$this->sgbackup->log("Missing file: {$file}", 'error');
					// Try to download from remote
					$downloaded = $this->download_from_remote($file, $backup_dir);
					if (!$downloaded || is_wp_error($downloaded)) {
						return new WP_Error('missing_file', "Required backup file not found: {$file}");
					}
				}
			}
		}
		return true;
	}

	/**
	 * Download a backup file from remote storage.
	 */
	private function download_from_remote($file, $backup_dir) {
		if (empty($this->backup_set['service'])) return false;

		$services = is_array($this->backup_set['service']) ? $this->backup_set['service'] : array($this->backup_set['service']);

		foreach ($services as $service) {
			if ('none' === $service) continue;

			// Validate service against registered backup methods whitelist
			$registered_methods = SiteGuard_Backup::instance()->get_backup_methods();
			if (!array_key_exists($service, $registered_methods)) {
				return new WP_Error('invalid_remote_service', "Unknown remote storage service: {$service}");
			}

			$method_file = SGBACKUP_DIR . 'methods/' . $service . '.php';
			if (!file_exists($method_file)) continue;

			require_once SGBACKUP_DIR . 'methods/backup-module.php';
			require_once $method_file;

			$class = 'SiteGuard_Backup_Method_' . ucfirst($service);
			if (!class_exists($class)) continue;

			$method = new $class();
			if (!method_exists($method, 'download')) continue;

			$this->sgbackup->log("Downloading {$file} from {$service}");
			$result = $method->download($file, $backup_dir);
			if ($result && file_exists($backup_dir . $file)) {
				return true;
			}
		}

		return false;
	}

	// ==========================================
	// DATABASE RESTORE
	// ==========================================

	/**
	 * Restore the database from backup files.
	 */
	private function restore_database($backup_dir) {
		$db_files = $this->backup_set['db'];
		if (is_string($db_files)) $db_files = array($db_files);

		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0);

		// Save current site URLs BEFORE importing the DB, so search-replace
		// can compare old URLs against these destination URLs (not the
		// overwritten values that get_option would return after import).
		$this->pre_import_siteurl = get_option('siteurl');
		$this->pre_import_home = get_option('home');
		$this->pre_import_content_url = content_url();
		$uploads_info = wp_upload_dir();
		$this->pre_import_uploads_url = $uploads_info['baseurl'];

		// Save progress for resume support
		$this->save_restore_progress('db', 'started');

		// Check for selective table restore
		$selected_tables = null;
		if (!empty($this->restore_options['restore_tables']) && is_array($this->restore_options['restore_tables'])) {
			$selected_tables = array_map('sanitize_text_field', $this->restore_options['restore_tables']);
			$this->sgbackup->log("Selective table restore: " . count($selected_tables) . " tables selected");

			if ($this->include_unspecified_tables) {
				$this->sgbackup->log("Include unspecified tables: enabled - selected tables will be restored, others kept as-is");
			}
		}

		foreach ($db_files as $db_file) {
			$file_path = $backup_dir . $db_file;

			// Decrypt if needed
			if (preg_match('/\.crypt$/', $db_file)) {
				$file_path = $this->decrypt_file($file_path);
				if (!$file_path) {
					$result['errors'][] = "Failed to decrypt: {$db_file}";
					$result['success'] = false;
					continue;
				}
			}

			// Parse old URLs from the SQL before import
			$this->scan_db_for_old_urls($file_path);

			$this->sgbackup->log("Importing database: {$db_file}");

			// Use filtered method when selective tables are specified
			if ($selected_tables !== null) {
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$selected_tables,
					function($lines_done) {
						$this->progress("Database import: {$lines_done} lines processed");
					}
				);
			} else {
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file(
					$file_path,
					function($lines_done) {
						$this->progress("Database import: {$lines_done} lines processed");
					}
				);
			}

			$result['tables_restored'] += $import_result['tables_restored'];
			$result['errors'] = array_merge($result['errors'], $import_result['errors']);

			if (!$import_result['success']) {
				$result['success'] = false;
			}
		}

		// Perform search-replace if this is a migration
		if ($this->is_migration) {
			$this->progress("Performing search-replace for migration");
			$this->do_search_replace();
		}

		// Save progress for resume support
		$this->save_restore_progress('db', 'completed');

		return $result;
	}

	/**
	 * Scan database dump for old URLs to detect migration.
	 */
	private function scan_db_for_old_urls($file_path) {
		$is_gz = (substr($file_path, -3) === '.gz');
		$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
		if (!$fh) return;

		$lines_scanned = 0;
		$max_scan = 200000; // Scan enough lines to reach wp_options in large databases
		$found_siteurl = false;
		$found_home = false;

		while (!($is_gz ? gzeof($fh) : feof($fh)) && $lines_scanned < $max_scan) {
			// Stop early once we have both URLs
			if ($found_siteurl && $found_home) break;
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$lines_scanned++;

			// Look for siteurl/home in INSERT INTO wp_options
			if (preg_match("/INSERT INTO\s+`[^`]*options`.*'(siteurl|home)'\s*,\s*'([^']+)'/i", $line, $m)) {
				if ($m[1] === 'siteurl') {
					$this->old_siteurl = $m[2];
					$found_siteurl = true;
				} elseif ($m[1] === 'home') {
					$this->old_home = $m[2];
					$found_home = true;
				}
			}

			// Look for upload_path or upload_url_path
			if (preg_match("/INSERT INTO\s+`[^`]*options`.*'upload_url_path'\s*,\s*'([^']+)'/i", $line, $m)) {
				$this->old_uploads = $m[1];
			}

			// Detect table prefix from CREATE TABLE statements
			if (preg_match('/CREATE TABLE\s+`([a-zA-Z0-9_]+)options`/i', $line, $m)) {
				$detected_prefix = str_replace('options', '', $m[1]);
				// Validate prefix contains only safe characters
				if (!empty($detected_prefix) && preg_match('/^[a-zA-Z0-9_]+$/', $detected_prefix)) {
					$this->old_table_prefix = $detected_prefix;
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);

		// Detect migration
		$current_siteurl = get_option('siteurl');
		if (!empty($this->old_siteurl) && $this->old_siteurl !== $current_siteurl) {
			$this->is_migration = true;
			$this->sgbackup->log("Migration detected: {$this->old_siteurl} -> {$current_siteurl}");

			// Gap 27: HTTPS/HTTP transition warnings
			$this->check_protocol_transition($this->old_siteurl, $current_siteurl);
		}
	}

	/**
	 * Gap 27: Detect and warn about HTTP/HTTPS protocol transitions.
	 *
	 * @param string $old_url The URL from the backup.
	 * @param string $new_url The current site URL.
	 */
	private function check_protocol_transition($old_url, $new_url) {
		$old_scheme = parse_url($old_url, PHP_URL_SCHEME);
		$new_scheme = parse_url($new_url, PHP_URL_SCHEME);

		if ($old_scheme === $new_scheme) return;

		if ($old_scheme === 'http' && $new_scheme === 'https') {
			$this->progress("NOTICE: Protocol transition detected: HTTP -> HTTPS");
			$this->sgbackup->log("Protocol transition: HTTP -> HTTPS. Search-replace will update URLs.", 'warning');
			$this->progress("WARNING: Mixed content issues may occur. Check that all themes, plugins, and embedded content use HTTPS URLs.");
			$this->sgbackup->log("After restore, verify that images, scripts, and stylesheets load over HTTPS to avoid mixed content warnings.", 'warning');
		} elseif ($old_scheme === 'https' && $new_scheme === 'http') {
			$this->progress("WARNING: Security downgrade detected: HTTPS -> HTTP");
			$this->sgbackup->log("SECURITY WARNING: Protocol downgrade from HTTPS to HTTP detected.", 'warning');
			$this->progress("WARNING: Restoring from an HTTPS site to HTTP reduces security. Passwords, cookies, and user data will be transmitted unencrypted.");
			$this->sgbackup->log("Cookies set with the Secure flag on the old site will not work over HTTP. Users may need to log in again.", 'warning');
		}
	}

	/**
	 * Perform search-replace on the database for migration.
	 */
	private function do_search_replace() {
		require_once SGBACKUP_DIR . 'includes/class-search-replace.php';

		// Use pre-import URLs saved before the DB was overwritten.
		// After DB import, get_option('siteurl') returns the OLD site's URL,
		// making comparison useless. The pre-import values are the DESTINATION URLs.
		$current_siteurl = $this->pre_import_siteurl;
		$current_home = $this->pre_import_home;
		$current_content = $this->pre_import_content_url;
		$current_uploads = $this->pre_import_uploads_url;

		$search = array();
		$replace = array();

		if (!empty($this->old_siteurl) && $this->old_siteurl !== $current_siteurl) {
			$search[] = $this->old_siteurl;
			$replace[] = $current_siteurl;
		}

		if (!empty($this->old_home) && $this->old_home !== $current_home && $this->old_home !== $this->old_siteurl) {
			$search[] = $this->old_home;
			$replace[] = $current_home;
		}

		if (!empty($this->old_uploads) && $this->old_uploads !== $current_uploads) {
			$search[] = $this->old_uploads;
			$replace[] = $current_uploads;
		}

		if (empty($search)) {
			$this->sgbackup->log("No URL changes detected, skipping search-replace");
			return;
		}

		// First, restore the destination siteurl/home in the DB so the site
		// functions correctly after search-replace completes.
		update_option('siteurl', $current_siteurl);
		update_option('home', $current_home);
		wp_cache_delete('alloptions', 'options');

		$this->sgbackup->log("Search-replace: " . count($search) . " replacements");
		foreach ($search as $i => $s) {
			$this->sgbackup->log("  Replace: {$s} -> {$replace[$i]}");

			// Gap 27: Additional protocol transition warning during search-replace
			$old_scheme = parse_url($s, PHP_URL_SCHEME);
			$new_scheme = parse_url($replace[$i], PHP_URL_SCHEME);
			if ($old_scheme && $new_scheme && $old_scheme !== $new_scheme) {
				$this->sgbackup->log("  Protocol change in this replacement: {$old_scheme} -> {$new_scheme}", 'warning');
			}
		}

		global $wpdb;
		$tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);

		$sr = new SiteGuard_Backup_Search_Replace();
		$report = $sr->run($search, $replace, $tables);

		$this->sgbackup->log("Search-replace complete: {$report['changes']} changes in {$report['tables']} tables");
	}

	// ==========================================
	// FILE ENTITY RESTORE
	// ==========================================

	/**
	 * Restore a file entity (plugins, themes, uploads, others).
	 */
	private function restore_file_entity($entity, $backup_dir) {
		$files = $this->backup_set[$entity];
		if (is_string($files)) $files = array($files);

		$entities_info = SiteGuard_Backup::instance()->get_backup_entities_with_extras();
		if (!isset($entities_info[$entity])) {
			$entities_info = SiteGuard_Backup_Filesystem::get_backup_entities();
			if (!isset($entities_info[$entity])) return false;
		}

		$dest_dir = $entities_info[$entity]['path'];

		// Save progress for resume support
		$this->save_restore_progress($entity, 'started');

		// Check for selective item restore (e.g., restore_plugins_items, restore_themes_items)
		$selected_items = null;
		$items_key = 'restore_' . $entity . '_items';
		if (!empty($this->restore_options[$items_key]) && is_array($this->restore_options[$items_key])) {
			$selected_items = array_map('sanitize_file_name', $this->restore_options[$items_key]);
			$this->sgbackup->log("Selective {$entity} restore: " . count($selected_items) . " items selected");
		}

		foreach ($files as $zip_file) {
			$zip_path = $backup_dir . $zip_file;
			if (!file_exists($zip_path)) {
				$this->sgbackup->log("Zip file not found: {$zip_file}", 'error');
				return false;
			}

			// Decrypt if needed
			if (preg_match('/\.crypt$/', $zip_file)) {
				$zip_path = $this->decrypt_file($zip_path);
				if (!$zip_path) return false;
			}

			$this->sgbackup->log("Extracting {$zip_file} to {$dest_dir}");

			// Create temp extraction directory
			$temp_dir = $backup_dir . 'restore_' . md5($zip_file) . '/';
			if (!file_exists($temp_dir)) {
				wp_mkdir_p($temp_dir);
			}

			// Extract
			$extract_result = $this->unzip($zip_path, $temp_dir);
			if (!$extract_result) {
				$this->sgbackup->log("Failed to extract: {$zip_file}", 'error');
				return false;
			}

			// Filter extracted items if selective restore is requested
			if ($selected_items !== null) {
				$this->filter_extracted_items($temp_dir, $selected_items, $entity);
			}

			// Move files to destination
			$move_result = $this->move_files($temp_dir, $dest_dir, $entity);
			if (!$move_result) {
				$this->sgbackup->log("Failed to move files for: {$entity}", 'error');
			}

			// Clean up temp dir
			$this->remove_temp_dir($temp_dir);
		}

		// Save progress for resume support
		$this->save_restore_progress($entity, 'completed');

		return true;
	}

	/**
	 * Filter extracted items to only keep selected ones.
	 *
	 * If the corresponding include_unspecified property is true, items NOT present
	 * in $selected_items are also kept (only explicitly deselected items are removed).
	 *
	 * @param string $temp_dir      The temporary extraction directory.
	 * @param array  $selected_items Items (directory names) to keep.
	 * @param string $entity        The entity type (plugins, themes, etc.).
	 */
	private function filter_extracted_items($temp_dir, $selected_items, $entity) {
		$items = @scandir($temp_dir);
		if (!is_array($items)) return;

		// Gap 25: Determine if unspecified items should be included
		$include_unspecified = true;
		if ($entity === 'plugins') {
			$include_unspecified = $this->include_unspecified_plugins;
		} elseif ($entity === 'themes') {
			$include_unspecified = $this->include_unspecified_themes;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') continue;

			if (!in_array($item, $selected_items)) {
				// If include_unspecified is true, keep items not in the selection list
				if ($include_unspecified) {
					$this->sgbackup->log("Selective restore: keeping unspecified {$entity} item '{$item}' (include_unspecified=true)");
					continue;
				}

				$item_path = $temp_dir . $item;
				if (is_dir($item_path)) {
					$this->remove_temp_dir($item_path);
				} else {
					@unlink($item_path);
				}
				$this->sgbackup->log("Selective restore: skipped {$entity} item '{$item}'");
			}
		}
	}

	/**
	 * Save restore progress for resume support.
	 *
	 * @param string $entity  Current entity being restored.
	 * @param string $status  Status of the entity restore (started, completed).
	 */
	private function save_restore_progress($entity, $status) {
		$opts = SiteGuard_Backup_Options::instance();

		$timestamp = isset($this->backup_set['timestamp']) ? $this->backup_set['timestamp'] : 0;
		if (!$timestamp) {
			// Try to find timestamp from nonce
			$nonce = isset($this->backup_set['nonce']) ? $this->backup_set['nonce'] : '';
			$history = SiteGuard_Backup_History::get_history();
			foreach ($history as $ts => $set) {
				if (isset($set['nonce']) && $set['nonce'] === $nonce) {
					$timestamp = $ts;
					break;
				}
			}
		}

		$progress = $opts->get_transient('sgbackup_restore_progress');
		if (empty($progress) || !is_array($progress)) {
			$progress = array(
				'timestamp' => $timestamp,
				'options'   => $this->restore_options,
				'entities'  => array(),
				'started_at' => time(),
			);
		}

		$progress['entities'][$entity] = $status;
		$progress['last_update'] = time();

		// Save with 1-hour expiry
		$opts->set_transient('sgbackup_restore_progress', $progress, 3600);

		// Clear progress when all entities are completed
		$entities_to_restore = $this->get_entities_to_restore();
		$all_done = true;
		foreach ($entities_to_restore as $e) {
			if (!isset($progress['entities'][$e]) || $progress['entities'][$e] !== 'completed') {
				$all_done = false;
				break;
			}
		}
		if ($all_done) {
			$opts->delete_transient('sgbackup_restore_progress');
		}
	}

	/**
	 * Extract a zip file.
	 */
	private function unzip($zip_path, $dest) {
		$dest = trailingslashit(realpath($dest) ?: $dest);

		// Try ZipArchive first
		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if (true === $zip->open($zip_path)) {
				// Validate all entries for path traversal (Zip Slip prevention)
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$entry_name = $zip->getNameIndex($i);

					// Skip empty entry names
					if (empty($entry_name)) continue;

					// Reject null bytes (poison byte attack)
					if (strpos($entry_name, "\0") !== false) {
						$this->progress("SECURITY: Rejected zip entry with null byte: " . bin2hex($entry_name));
						$zip->close();
						return false;
					}

					// Reject absolute paths (Unix or Windows)
					if ($entry_name[0] === '/' || $entry_name[0] === '\\' || preg_match('/^[A-Za-z]:/', $entry_name)) {
						$this->progress("SECURITY: Rejected zip entry with absolute path: {$entry_name}");
						$zip->close();
						return false;
					}

					// Normalize the entry path: resolve .. components in pure string
					$parts = explode('/', str_replace('\\', '/', $entry_name));
					$normalized = array();
					foreach ($parts as $p) {
						if ($p === '..') {
							if (empty($normalized)) {
								// Traversal above root - reject
								$this->progress("SECURITY: Rejected zip entry with path traversal: {$entry_name}");
								$zip->close();
								return false;
							}
							array_pop($normalized);
						} elseif ($p !== '' && $p !== '.') {
							$normalized[] = $p;
						}
					}

					// Verify resolved path stays within destination using string comparison
					$resolved = rtrim($dest, '/') . '/' . implode('/', $normalized);
					$safe_dest = rtrim($dest, '/') . '/';
					if (strpos($resolved, $safe_dest) !== 0) {
						$this->progress("SECURITY: Zip entry escapes destination: {$entry_name}");
						$zip->close();
						return false;
					}
				}
				$result = $zip->extractTo($dest);
				$zip->close();
				return $result;
			}
		}

		// Try WP's built-in unzip (WP_Filesystem has its own path checks)
		WP_Filesystem();
		$result = unzip_file($zip_path, $dest);
		return !is_wp_error($result);
	}

	/**
	 * Move extracted files to their destination.
	 *
	 * Behaviour depends on $this->move_in_mode:
	 *   MOVEIN_OVERWRITE (0)        - overwrite without backup (default / legacy)
	 *   MOVEIN_BACKUP_EXISTING (1)  - rename existing to {name}.sgbackup-old-{timestamp}
	 *   MOVEIN_SKIP_EXISTING (2)    - skip if target already exists (log a notice)
	 *   MOVEIN_MERGE_CONTENTS (3)   - for dirs merge contents; for files overwrite
	 */
	private function move_files($source, $dest, $entity) {
		if (!is_dir($source)) return false;
		if (!is_dir($dest)) wp_mkdir_p($dest);

		$mode = $this->move_in_mode;

		// For MERGE mode at the top-level, we iterate individual items
		// For SKIP mode at the top-level directory items, check existence
		if ($mode === self::MOVEIN_SKIP_EXISTING || $mode === self::MOVEIN_BACKUP_EXISTING) {
			// Handle top-level items (plugins/themes are dirs, others may be files)
			$top_items = @scandir($source);
			if (is_array($top_items)) {
				foreach ($top_items as $item) {
					if ($item === '.' || $item === '..') continue;
					$source_item = $source . $item;
					$target_item = $dest . $item;

					if ($mode === self::MOVEIN_SKIP_EXISTING && (file_exists($target_item) || is_dir($target_item))) {
						$this->sgbackup->log("Move-in skip: '{$item}' already exists in {$entity}, skipping");
						// Remove from source so the general iterator below won't process it
						if (is_dir($source_item)) {
							$this->remove_temp_dir($source_item);
						} else {
							@unlink($source_item);
						}
						continue;
					}

					if ($mode === self::MOVEIN_BACKUP_EXISTING && (file_exists($target_item) || is_dir($target_item))) {
						$backup_name = $target_item . '.sgbackup-old-' . time();
						if (!@rename($target_item, $backup_name)) {
							$this->sgbackup->log("Move-in backup: failed to rename '{$item}' to '" . basename($backup_name) . "'", 'warning');
						} else {
							$this->sgbackup->log("Move-in backup: renamed existing '{$item}' to '" . basename($backup_name) . "'");
						}
					}
				}
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$relative = substr($item->getPathname(), strlen($source));
			$target = $dest . $relative;

			if ($item->isDir()) {
				if (!is_dir($target)) {
					wp_mkdir_p($target);
				}
			} else {
				$target_dir = dirname($target);
				if (!is_dir($target_dir)) {
					wp_mkdir_p($target_dir);
				}

				// Mode-specific file handling
				if ($mode === self::MOVEIN_SKIP_EXISTING && file_exists($target)) {
					$this->sgbackup->log("Move-in skip: file already exists: {$relative}");
					continue;
				}

				// Overwrite existing (modes 0, 1, 3 all overwrite individual files)
				if (file_exists($target)) {
					@unlink($target);
				}
				// rename() fails silently cross-filesystem; fall back to copy+unlink
				if (!@rename($item->getPathname(), $target)) {
					if (!@copy($item->getPathname(), $target)) {
						$this->sgbackup->log("Failed to move file: " . basename($item->getPathname()), 'error');
					} else {
						@unlink($item->getPathname());
					}
				}
			}
		}

		return true;
	}

	/**
	 * Remove a temporary directory.
	 */
	private function remove_temp_dir($dir) {
		if (!is_dir($dir)) return;

		// Boundary check: only allow removal within the backup directory
		$backup_dir = SiteGuard_Backup_Options::instance()->get_backup_dir();
		$real_dir = realpath($dir);
		$real_backup = realpath($backup_dir);
		if (false === $real_dir || false === $real_backup || strpos($real_dir, $real_backup) !== 0) {
			$this->sgbackup->log("SECURITY: Refusing to remove directory outside backup path: {$dir}", 'error');
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

	// ==========================================
	// POST-RESTORE
	// ==========================================

	/**
	 * Post-restore cleanup tasks.
	 */
	private function post_restore_cleanup() {
		$this->progress("Running post-restore cleanup");

		// Flush rewrite rules
		flush_rewrite_rules(true);

		// Clear all caches
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}

		// Clear object cache
		wp_cache_delete('alloptions', 'options');

		// Validate active theme exists
		$this->validate_theme();

		// Check active plugins exist
		$this->validate_plugins();

		// Disable known caching plugins if this is a migration
		if ($this->is_migration) {
			$this->disable_cache_plugins();
		}

		// Gap 26: Check .htaccess for migration issues
		$this->check_htaccess_migration();

		// Clear all transients
		global $wpdb;
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like('_transient_') . '%',
			$wpdb->esc_like('_site_transient_') . '%'
		));

		$this->progress("Post-restore cleanup complete");
	}

	/**
	 * Validate the active theme exists.
	 */
	private function validate_theme() {
		$theme = wp_get_theme();
		if (!$theme->exists()) {
			$this->sgbackup->log("Active theme does not exist, switching to default", 'warning');
			$default_themes = array('twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty');
			foreach ($default_themes as $dt) {
				$check = wp_get_theme($dt);
				if ($check->exists()) {
					switch_theme($dt);
					$this->sgbackup->log("Switched to theme: {$dt}");
					break;
				}
			}
		}
	}

	/**
	 * Deactivate plugins that don't exist on disk.
	 */
	private function validate_plugins() {
		$active_plugins = get_option('active_plugins', array());
		$deactivated = array();

		foreach ($active_plugins as $key => $plugin) {
			$plugin_file = WP_PLUGIN_DIR . '/' . $plugin;
			if (!file_exists($plugin_file)) {
				unset($active_plugins[$key]);
				$deactivated[] = $plugin;
			}
		}

		if (!empty($deactivated)) {
			update_option('active_plugins', array_values($active_plugins));
			$this->sgbackup->log("Deactivated missing plugins: " . implode(', ', $deactivated), 'warning');
		}
	}

	/**
	 * Disable caching plugins after migration.
	 */
	private function disable_cache_plugins() {
		$cache_plugins = array(
			'w3-total-cache/w3-total-cache.php',
			'wp-super-cache/wp-cache.php',
			'wp-fastest-cache/wpFastestCache.php',
			'litespeed-cache/litespeed-cache.php',
			'autoptimize/autoptimize.php',
		);

		$active_plugins = get_option('active_plugins', array());
		$disabled = array();

		foreach ($cache_plugins as $cp) {
			$key = array_search($cp, $active_plugins);
			if (false !== $key) {
				unset($active_plugins[$key]);
				$disabled[] = $cp;
			}
		}

		if (!empty($disabled)) {
			update_option('active_plugins', array_values($active_plugins));
			$this->sgbackup->log("Disabled cache plugins post-migration: " . implode(', ', $disabled));
		}
	}

	/**
	 * Decrypt an encrypted backup file.
	 */
	private function decrypt_file($file_path) {
		require_once SGBACKUP_DIR . 'includes/class-encryption.php';

		$opts = SiteGuard_Backup_Options::instance();
		$key = $opts->get_option('sgbackup_encryption_key', '');

		if (!empty($this->restore_options['encryption_key'])) {
			$key = $this->restore_options['encryption_key'];
		}

		if (empty($key)) {
			$this->sgbackup->log("No encryption key provided", 'error');
			return false;
		}

		$decrypted = SiteGuard_Backup_Encryption::decrypt($file_path, $key);
		return $decrypted;
	}

	/**
	 * Create a backup before restoring (safety net).
	 */
	private function create_pre_restore_backup() {
		try {
			$this->progress("Creating pre-restore database backup...");

			// Synchronous DB-only backup (async cron backup would be useless before restore)
			require_once SGBACKUP_DIR . 'includes/class-database-utility.php';
			$sgbackup = SiteGuard_Backup::instance();
			$backup_dir = $sgbackup->get_backup_dir();

			$nonce = substr(wp_generate_password(12, false), 0, 12);
			$timestamp = time();
			$db_file = "prerestore_{$nonce}-db.sql.gz";
			$db_path = $backup_dir . $db_file;

			global $wpdb;
			$tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);

			// Dump all tables directly into a single gz file
			$fh = @gzopen($db_path, 'wb9');
			if (!$fh) {
				$this->progress("Warning: Could not create pre-restore backup file");
				return;
			}

			gzwrite($fh, "-- SiteGuard Backup pre-restore backup\n");
			gzwrite($fh, "-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n");
			gzwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

			$temp_dir = $backup_dir . 'dbtemp_prerestore_' . $nonce . '/';
			if (!is_dir($temp_dir)) wp_mkdir_p($temp_dir);

			foreach ($tables as $table) {
				$table_file = $temp_dir . $table . '.sql.gz';
				$result = SiteGuard_Backup_Database_Utility::php_dump_table($table, $table_file);
				if (!$result || !file_exists($table_file) || filesize($table_file) === 0) {
					$this->sgbackup->log("Pre-restore backup: skipping table {$table} (dump failed)", 'warning');
					@unlink($table_file);
					continue;
				}

				$tfh = @gzopen($table_file, 'rb');
				if (!$tfh) {
					$this->sgbackup->log("Pre-restore backup: could not read dump for {$table}", 'warning');
					@unlink($table_file);
					continue;
				}
				while (!gzeof($tfh)) {
					$chunk = gzread($tfh, 65536);
					if (false === $chunk || strlen($chunk) === 0) break;
					gzwrite($fh, $chunk);
				}
				gzclose($tfh);
				@unlink($table_file);
			}

			gzwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
			gzclose($fh);

			// Clean up temp dir
			SiteGuard_Backup_Filesystem::rmdir_recursive($temp_dir);

			if (file_exists($db_path) && filesize($db_path) > 0) {
				$backup_data = array(
					'nonce'       => $nonce,
					'db'          => array($db_file),
					'service'     => array('none'),
					'always_keep' => true,
					'size'        => filesize($db_path),
				);
				SiteGuard_Backup_History::add_backup($timestamp, $backup_data);
				$this->progress("Pre-restore backup saved: {$db_file}");
			} else {
				$this->progress("Warning: Pre-restore backup empty - continuing");
			}
		} catch (Exception $e) {
			$this->progress("Warning: Pre-restore backup failed ({$e->getMessage()}) - continuing");
		}
	}

	/**
	 * Verify backup file integrity using the manifest file.
	 *
	 * @param string $backup_dir
	 * @return array List of integrity error messages (empty = OK).
	 */
	private function verify_backup_integrity($backup_dir) {
		$errors = array();
		$nonce = isset($this->backup_set['nonce']) ? $this->backup_set['nonce'] : '';
		if (empty($nonce)) return $errors;

		// Find manifest file
		$manifest_files = glob($backup_dir . '*' . $nonce . '-manifest.json');
		if (empty($manifest_files)) {
			$this->sgbackup->log("No manifest file found - skipping integrity check");
			return $errors;
		}

		$manifest_path = $manifest_files[0];
		$manifest = @json_decode(file_get_contents($manifest_path), true);
		if (!is_array($manifest) || empty($manifest['files'])) {
			$errors[] = "Could not parse manifest file";
			return $errors;
		}

		$this->progress("Verifying backup integrity against manifest");

		foreach ($manifest['files'] as $file_info) {
			$file_path = $backup_dir . $file_info['name'];
			if (!file_exists($file_path)) {
				// Already checked in ensure_files_present, skip
				continue;
			}

			// Size check
			if (isset($file_info['size']) && $file_info['size'] > 0) {
				$actual_size = filesize($file_path);
				if ($actual_size !== $file_info['size']) {
					$errors[] = "{$file_info['name']}: size mismatch (expected {$file_info['size']}, got {$actual_size})";
				}
			}

			// MD5 check
			if (!empty($file_info['md5'])) {
				$actual_md5 = md5_file($file_path);
				if ($actual_md5 !== $file_info['md5']) {
					$errors[] = "{$file_info['name']}: MD5 mismatch (file may be corrupted)";
				}
			}
		}

		if (empty($errors)) {
			$this->sgbackup->log("Integrity check passed: all files verified");
		} else {
			$this->sgbackup->log("Integrity check found " . count($errors) . " issues", 'warning');
		}

		return $errors;
	}

	/**
	 * Gap 26: Check .htaccess for migration issues after restore.
	 *
	 * If the site URL changed during restore, warn about .htaccess rules
	 * that may contain hardcoded URLs or RewriteBase directives.
	 */
	private function check_htaccess_migration() {
		$htaccess_path = ABSPATH . '.htaccess';
		if (!file_exists($htaccess_path)) return;

		$htaccess_content = @file_get_contents($htaccess_path);
		if (empty($htaccess_content)) return;

		// Check for RewriteBase rules
		if (preg_match('/RewriteBase\s+/i', $htaccess_content)) {
			$this->progress("WARNING: .htaccess contains RewriteBase rules that may need manual updates");
			$this->sgbackup->log(".htaccess contains RewriteBase directives - review and update if site path changed", 'warning');
		}

		// Check for hardcoded URLs from the old site
		if ($this->is_migration && !empty($this->old_siteurl)) {
			$old_host = parse_url($this->old_siteurl, PHP_URL_HOST);
			if ($old_host && stripos($htaccess_content, $old_host) !== false) {
				$this->progress("WARNING: .htaccess contains hardcoded references to the old domain '{$old_host}' - manual update required");
				$this->sgbackup->log(".htaccess contains hardcoded URLs referencing '{$old_host}' - these must be updated manually", 'warning');
			}

			// Check for old path references
			$old_path = parse_url($this->old_siteurl, PHP_URL_PATH);
			$current_path = parse_url(get_option('siteurl'), PHP_URL_PATH);
			if (!empty($old_path) && $old_path !== $current_path && strpos($htaccess_content, $old_path) !== false) {
				$this->progress("WARNING: .htaccess contains path references to '{$old_path}' which may need updating to '{$current_path}'");
				$this->sgbackup->log(".htaccess path mismatch: contains '{$old_path}', current site path is '" . ($current_path ?: '/') . "'", 'warning');
			}
		}
	}

	/**
	 * Add a progress message.
	 */
	private function progress($message) {
		$this->progress[] = $message;
		$this->sgbackup->log("[Restore] {$message}");

		// Output for streaming
		if (defined('DOING_AJAX') && DOING_AJAX) {
			echo 'RINFO:' . json_encode(array(
				'type' => 'progress',
				'message' => $message,
				'timestamp' => time(),
			)) . "\n";
			if (ob_get_level()) @ob_flush();
			@flush();
		}
	}
}
