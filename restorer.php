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

	/** Database restore mode constants */
	const DB_RESTORE_MIGRATE = 0;      // Full overwrite + auto search-replace + multisite fixes (default)
	const DB_RESTORE_OVERWRITE = 1;    // Full overwrite, NO search-replace (same-domain restore)
	const DB_RESTORE_TABLES_ONLY = 2;  // Only import tables not already present in DB
	const DB_RESTORE_SKIP_DB = 3;      // Skip database entirely (restore files only)

	/** Subsite migration mode constants */
	const SUBSITE_MODE_MIRROR = 0;     // Map all subsites to equivalent subdomains on target (default)
	const SUBSITE_MODE_MAIN_ONLY = 1;  // Only restore main site, remove subsite blog entries
	const SUBSITE_MODE_SKIP_REMAP = 2; // Import subsite data but don't remap domains
	const SUBSITE_MODE_CUSTOM = 3;     // Custom per-subsite domain mapping

	const SUBSITE_SKIP = '__skip__';   // Sentinel: skip this subsite in custom mapping

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

	/** @var array|null Search-replace report for restore UI */
	private $search_replace_report = null;

	/** @var int Move-in mode for file restore */
	private $move_in_mode = 0;

	/** @var int Database restore mode */
	private $db_restore_mode = 0;

	/** @var int Subsite migration mode */
	private $subsite_mode = 0;

	/** @var array Custom subsite domain mapping: old_domain => new_domain */
	private $subsite_map = array();

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

		// Gap 23: Move-in mode - clamp to valid range [0, 3]
		if (isset($restore_options['move_in_mode'])) {
			$this->move_in_mode = min(3, max(0, intval($restore_options['move_in_mode'])));
		}

		// Database restore mode - fall back to site-wide default, clamp to [0, 3]
		if (isset($restore_options['db_restore_mode'])) {
			$this->db_restore_mode = min(3, max(0, intval($restore_options['db_restore_mode'])));
		} else {
			$this->db_restore_mode = min(3, max(0, intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_db_restore_mode', 0))));
		}

		// Subsite migration mode - fall back to site-wide default, clamp to valid range
		if (isset($restore_options['subsite_mode'])) {
			$this->subsite_mode = min(3, max(0, intval($restore_options['subsite_mode'])));
		} else {
			$this->subsite_mode = min(3, max(0, intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_subsite_mode', 0))));
		}

		// Custom subsite mapping: array of old_domain => new_full_domain
		if (!empty($restore_options['subsite_map']) && is_array($restore_options['subsite_map'])) {
			foreach ($restore_options['subsite_map'] as $old => $new) {
				$old = sanitize_text_field($old);
				$new = sanitize_text_field($new);
				if (!empty($old) && !empty($new)) {
					$this->subsite_map[$old] = $new;
				}
			}
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
			if ($this->db_restore_mode === self::DB_RESTORE_SKIP_DB) {
				$result['messages'][] = "Database: skipped (user selected skip-db mode)";
			} else {
				$result['messages'][] = "Database: {$db_result['tables_restored']} tables restored";
			}
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

		$mode_labels = array(
			self::DB_RESTORE_MIGRATE     => 'migrate (full overwrite + search-replace)',
			self::DB_RESTORE_OVERWRITE   => 'overwrite (no search-replace)',
			self::DB_RESTORE_TABLES_ONLY => 'tables-only (new tables only)',
			self::DB_RESTORE_SKIP_DB     => 'skip-db (no database import)',
		);
		$this->sgbackup->log("Database restore mode: " . ($mode_labels[$this->db_restore_mode] ?? 'unknown'));
		$this->progress("Database restore mode: " . ($mode_labels[$this->db_restore_mode] ?? 'unknown'));

		// Mode 3: Skip DB entirely
		if ($this->db_restore_mode === self::DB_RESTORE_SKIP_DB) {
			$this->sgbackup->log("Skipping database restore (mode: skip-db)");
			$this->progress("Database restore skipped by user selection");
			return $result;
		}

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

		// Mode 2: Tables-only - build list of tables NOT already in DB
		$existing_tables = array();
		if ($this->db_restore_mode === self::DB_RESTORE_TABLES_ONLY && $selected_tables === null) {
			global $wpdb;
			$existing_tables = $wpdb->get_col("SHOW TABLES");
			$this->sgbackup->log("Tables-only mode: " . count($existing_tables) . " existing tables will be skipped");
			$this->progress("Tables-only mode: skipping " . count($existing_tables) . " existing tables");
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

			// Mode 2 (tables-only): use filtered import with only tables NOT in DB
			if ($this->db_restore_mode === self::DB_RESTORE_TABLES_ONLY && $selected_tables === null) {
				// Extract table names from SQL file, then filter to only new ones
				$backup_tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($file_path);
				$new_tables = array_diff($backup_tables, $existing_tables);
				if (empty($new_tables)) {
					$this->sgbackup->log("Tables-only mode: no new tables to import from {$db_file}");
					$this->progress("No new tables found in {$db_file}");
					continue;
				}
				$this->sgbackup->log("Tables-only mode: importing " . count($new_tables) . " new tables: " . implode(', ', $new_tables));
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$new_tables,
					function($lines_done) {
						$this->progress("Database import: {$lines_done} lines processed");
					}
				);
			} elseif ($selected_tables !== null) {
				// Use filtered method when selective tables are specified
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$selected_tables,
					function($lines_done) {
						$this->progress("Database import: {$lines_done} lines processed");
					}
				);
			} else {
				// Use atomic restore (temp table → RENAME) for crash safety
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_atomic(
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

		// Flush WordPress object cache - the atomic RENAME TABLE makes all
		// cached option values stale (they still hold the pre-import data).
		wp_cache_flush();

		// AUTHORITATIVE migration detection from the actual imported database.
		// The SQL scan may have picked up a subsite's siteurl (e.g.,
		// wp_10_options appears before wp_options alphabetically), giving us
		// the wrong base domain. Reading from wp_options after import gives
		// the correct main site URL.
		if ($this->db_restore_mode === self::DB_RESTORE_MIGRATE) {
			$imported_siteurl = get_option('siteurl');
			$imported_home = get_option('home');
			if (!empty($imported_siteurl) && !empty($this->pre_import_siteurl) && $imported_siteurl !== $this->pre_import_siteurl) {
				if ($this->is_migration && $this->old_siteurl !== $imported_siteurl) {
					$this->sgbackup->log("Correcting old_siteurl from SQL scan ({$this->old_siteurl}) to actual imported value ({$imported_siteurl})");
				}
				$this->is_migration = true;
				$this->old_siteurl = $imported_siteurl;
				$this->old_home = $imported_home;
				$this->sgbackup->log("Migration confirmed (post-import): {$imported_siteurl} -> {$this->pre_import_siteurl}");
				$this->progress("Migration detected: {$imported_siteurl} -> {$this->pre_import_siteurl}");
				$this->check_protocol_transition($imported_siteurl, $this->pre_import_siteurl);
			} elseif (empty($imported_siteurl) || $imported_siteurl === $this->pre_import_siteurl) {
				// URLs match - this is a same-site restore, not a migration
				if ($this->is_migration) {
					$this->sgbackup->log("SQL scan detected migration but imported siteurl matches current site - not a migration");
					$this->is_migration = false;
				}
			}
		}

		// Perform search-replace if this is a migration (mode 0 only)
		if ($this->is_migration && $this->db_restore_mode === self::DB_RESTORE_MIGRATE) {
			$this->progress("Performing search-replace for migration");
			$this->do_search_replace();
		} elseif ($this->is_migration && $this->db_restore_mode === self::DB_RESTORE_OVERWRITE && is_multisite()) {
			// Overwrite mode skips general search-replace, but multisite identity
			// tables (wp_sitemeta.siteurl, wp_site.domain, wp_blogs blog_id=1)
			// must still be updated or the site won't load at all.
			$this->sgbackup->log("Migration detected in overwrite mode - fixing multisite identity tables only");
			$this->progress("Fixing multisite identity tables for migration");
			$this->fix_multisite_sitemeta();
		} elseif ($this->is_migration && $this->db_restore_mode !== self::DB_RESTORE_MIGRATE) {
			$this->sgbackup->log("Migration detected but search-replace skipped (db_restore_mode=" . $this->db_restore_mode . ")");
			$this->progress("Search-replace skipped (not in migrate mode)");
		}

		// Handle subsite skips even on same-site restores (non-migration).
		// fix_multisite_sitemeta handles this when called during migration, but
		// for same-site restores is_migration=false so it never runs. We need
		// a separate pass to remove skipped subsites from wp_blogs + drop tables.
		if (!$this->is_migration && is_multisite() && !empty($this->subsite_map)) {
			$this->apply_subsite_skips();
		}

		// Save progress for resume support
		$this->save_restore_progress('db', 'completed');

		return $result;
	}

	/**
	 * Scan database dump for old URLs to detect migration.
	 *
	 * Handles both mysqldump format (all values on one line) and the PHP dump
	 * format where INSERT INTO and VALUES are on separate lines:
	 *   INSERT INTO `wp_options` (col, ...) VALUES
	 *   (1, 'siteurl', 'https://example.com', 'yes'),
	 *   (2, 'home', 'https://example.com', 'yes');
	 */
	private function scan_db_for_old_urls($file_path) {
		$is_gz = (substr($file_path, -3) === '.gz');
		$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
		if (!$fh) return;

		$lines_scanned = 0;
		$max_scan = 200000; // Scan enough lines to reach wp_options in large databases
		$found_siteurl = false;
		$found_home = false;
		$in_options_insert = false; // Track multi-line INSERT INTO *options* context

		while (!($is_gz ? gzeof($fh) : feof($fh)) && $lines_scanned < $max_scan) {
			// Stop early once we have both URLs
			if ($found_siteurl && $found_home) break;
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$lines_scanned++;
			$trimmed = trim($line);

			// Track INSERT INTO *options* context for multi-line format
			if (preg_match('/^INSERT INTO\s+`[^`]*options`/i', $trimmed)) {
				$in_options_insert = true;
			}
			// New CREATE/INSERT/DROP statement ends the multi-line context
			if ($in_options_insert && preg_match('/^(CREATE|DROP|INSERT INTO\s+`[^`]*(?<!options)`)/i', $trimmed)) {
				$in_options_insert = false;
			}
			// Statement terminator on its own line ends context
			if ($in_options_insert && $trimmed === ';') {
				$in_options_insert = false;
			}

			// Match siteurl/home: works for single-line mysqldump format AND
			// multi-line PHP dump format (value rows within options INSERT)
			if ($in_options_insert || preg_match('/^INSERT INTO\s+`[^`]*options`/i', $trimmed)) {
				if (preg_match("/'(siteurl|home)'\s*,\s*'([^']+)'/i", $trimmed, $m)) {
					if ($m[1] === 'siteurl' && !$found_siteurl) {
						$this->old_siteurl = $m[2];
						$found_siteurl = true;
					} elseif ($m[1] === 'home' && !$found_home) {
						$this->old_home = $m[2];
						$found_home = true;
					}
				}

				// Look for upload_url_path in same context
				if (preg_match("/'upload_url_path'\s*,\s*'([^']+)'/i", $trimmed, $m)) {
					$this->old_uploads = $m[1];
				}
			}

			// Detect table prefix from CREATE TABLE statements
			if (preg_match('/CREATE TABLE\s+`([a-zA-Z0-9_]+)options`/i', $trimmed, $m)) {
				$detected_prefix = str_replace('options', '', $m[1]);
				// Validate prefix contains only safe characters
				if (!empty($detected_prefix) && preg_match('/^[a-zA-Z0-9_]+$/', $detected_prefix)) {
					$this->old_table_prefix = $detected_prefix;
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);

		// Detect migration using pre-import URL (stable reference, not affected by
		// prior DB file imports in multi-file restores or WordPress object cache)
		$current_siteurl = !empty($this->pre_import_siteurl) ? $this->pre_import_siteurl : get_option('siteurl');
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

			// Also add the opposite-protocol variant of old_siteurl in case
			// some DB values use http:// while old_siteurl was detected as https://
			$old_scheme = parse_url($this->old_siteurl, PHP_URL_SCHEME);
			$alt_scheme = ($old_scheme === 'https') ? 'http' : 'https';
			$alt_old_siteurl = preg_replace('/^' . preg_quote($old_scheme, '/') . '/', $alt_scheme, $this->old_siteurl);
			if ($alt_old_siteurl !== $current_siteurl && !in_array($alt_old_siteurl, $search, true)) {
				$search[] = $alt_old_siteurl;
				$replace[] = $current_siteurl;
			}
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

		// Multisite: enumerate all blogs and build per-blog replacements
		if (is_multisite()) {
			$this->build_multisite_replacements($search, $replace);
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

		$this->sgbackup->log("Search-replace complete: {$report['changes']} changes across {$report['tables']} tables, {$report['updates']} rows updated");
		if (!empty($report['errors'])) {
			$this->sgbackup->log("Search-replace warnings: " . count($report['errors']), 'warning');
		}

		// Store report for inclusion in final progress message
		$this->search_replace_report = array(
			'tables'  => $report['tables'],
			'changes' => $report['changes'],
			'updates' => $report['updates'],
			'pairs'   => array_combine($search, $replace),
			'errors'  => isset($report['errors']) ? $report['errors'] : array(),
		);

		// Emit search-replace report via RINFO for the JS to display
		if ($this->search_replace_report) {
			$sr_report = $this->search_replace_report;
			$this->progress("Migration search-replace: {$sr_report['changes']} changes across {$sr_report['tables']} tables");
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array(
					'type' => 'search_replace',
					'tables' => $sr_report['tables'],
					'changes' => $sr_report['changes'],
					'updates' => $sr_report['updates'],
					'pairs' => $sr_report['pairs'],
				)) . "\n";
				if (ob_get_level()) @ob_flush();
				@flush();
			}
		}

		// Fix multisite-specific tables that string replacement can't handle correctly
		$this->fix_multisite_sitemeta();
	}

	/**
	 * Build search-replace pairs for all multisite blogs.
	 *
	 * For each blog in wp_blogs whose domain contains the old site's domain,
	 * compute the new domain and add protocol-aware replacement pairs.
	 * Both http:// and https:// variants are added for each blog to handle
	 * cases where subsites use a different protocol than the main site.
	 * Specific (longer) replacements are added first; bare domain goes last.
	 */
	private function build_multisite_replacements(&$search, &$replace) {
		global $wpdb;

		// Skip remap mode: don't modify search-replace pairs for subsites
		if ($this->subsite_mode === self::SUBSITE_MODE_SKIP_REMAP) {
			$this->sgbackup->log("Multisite migration: skip-remap mode, not building subsite replacements");
			return;
		}

		$old_domain = parse_url($this->old_siteurl, PHP_URL_HOST);
		$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
		if (!$old_domain || !$new_domain || $old_domain === $new_domain) return;

		$new_scheme = parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);

		// Main-only mode: only build main site URL pairs, skip subsite pairs
		if ($this->subsite_mode === self::SUBSITE_MODE_MAIN_ONLY) {
			$this->sgbackup->log("Multisite migration: main-only mode, skipping subsite replacement pairs");
			// Still add main site domain pairs (bare domain catch-all + protocol variants)
			$old_main_https = 'https://' . $old_domain;
			$old_main_http = 'http://' . $old_domain;
			$new_main_url = $new_scheme . '://' . $new_domain;
			if (!in_array($old_main_https, $search, true)) {
				$search[] = $old_main_https;
				$replace[] = $new_main_url;
			}
			if (!in_array($old_main_http, $search, true)) {
				$search[] = $old_main_http;
				$replace[] = $new_main_url;
			}
			if (!in_array($old_domain, $search, true)) {
				$search[] = $old_domain;
				$replace[] = $new_domain;
			}
			if ($new_scheme === 'https') {
				$http_new = 'http://' . $new_domain;
				$https_new = 'https://' . $new_domain;
				if (!in_array($http_new, $search, true)) {
					$search[] = $http_new;
					$replace[] = $https_new;
				}
			}
			return;
		}

		// Mirror/Custom mode: build per-blog URL pairs
		$is_custom = ($this->subsite_mode === self::SUBSITE_MODE_CUSTOM && !empty($this->subsite_map));
		$is_selective_mirror = ($this->subsite_mode === self::SUBSITE_MODE_MIRROR && !empty($this->subsite_map));
		$has_skip_map = ($is_custom || $is_selective_mirror);

		// Read all blog domains from wp_blogs
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$blogs = $wpdb->get_col("SELECT DISTINCT domain FROM {$blogs_table}");
		if (empty($blogs)) return;

		$mode_label = $is_custom ? 'custom-map' : ($is_selective_mirror ? 'selective-mirror' : 'mirror');
		$this->sgbackup->log("Multisite migration ({$mode_label}): found " . count($blogs) . " blog domains");

		$added = array();

		foreach ($blogs as $blog_domain) {
			// Skip if this blog domain doesn't contain the old domain
			if (strpos($blog_domain, $old_domain) === false) continue;

			// Compute new blog domain: custom map lookup, skip check, or mirror (str_replace)
			if ($has_skip_map && isset($this->subsite_map[$blog_domain])) {
				if ($this->subsite_map[$blog_domain] === self::SUBSITE_SKIP) {
					$this->sgbackup->log("  {$mode_label}: skipping blog domain {$blog_domain}");
					continue;
				}
				// For custom mode, use the mapped domain; for selective mirror, fall through to str_replace
				if ($is_custom) {
					$new_blog_domain = $this->subsite_map[$blog_domain];
				} else {
					$new_blog_domain = str_replace($old_domain, $new_domain, $blog_domain);
				}
			} else {
				$new_blog_domain = str_replace($old_domain, $new_domain, $blog_domain);
			}

			// Skip if already covered by existing pairs or is the bare domain
			if ($blog_domain === $old_domain) continue;
			if (in_array($blog_domain, $added, true)) continue;

			$new_url = $new_scheme . '://' . $new_blog_domain;

			// Add BOTH http:// and https:// search variants for this blog.
			// Subsites often use a different protocol than the main site
			// (e.g., main site https:// but subsites http://).
			$old_url_https = 'https://' . $blog_domain;
			$old_url_http = 'http://' . $blog_domain;

			if (!in_array($old_url_https, $search, true)) {
				$search[] = $old_url_https;
				$replace[] = $new_url;
				$this->sgbackup->log("  Blog: {$old_url_https} -> {$new_url}");
			}
			if (!in_array($old_url_http, $search, true)) {
				$search[] = $old_url_http;
				$replace[] = $new_url;
				$this->sgbackup->log("  Blog: {$old_url_http} -> {$new_url}");
			}

			// Domain-only replacement for this blog (no protocol)
			if (!in_array($blog_domain, $search, true) && $blog_domain !== $new_blog_domain) {
				$search[] = $blog_domain;
				$replace[] = $new_blog_domain;
			}

			$added[] = $blog_domain;
		}

		// Also add both protocol variants for the main site URL to catch
		// any references using the opposite protocol
		$old_main_https = 'https://' . $old_domain;
		$old_main_http = 'http://' . $old_domain;
		$new_main_url = $new_scheme . '://' . $new_domain;

		if (!in_array($old_main_https, $search, true)) {
			$search[] = $old_main_https;
			$replace[] = $new_main_url;
		}
		if (!in_array($old_main_http, $search, true)) {
			$search[] = $old_main_http;
			$replace[] = $new_main_url;
		}

		// Bare domain catch-all (LAST so specific replacements run first)
		if (!in_array($old_domain, $search, true)) {
			$search[] = $old_domain;
			$replace[] = $new_domain;
			$this->sgbackup->log("  Bare domain catch-all: {$old_domain} -> {$new_domain}");
		}

		// Protocol normalization for the new domain: if destination uses https,
		// ensure any http:// references to the new domain also get upgraded.
		// This catches cases where bare domain replacement leaves http:// intact.
		if ($new_scheme === 'https') {
			$http_new = 'http://' . $new_domain;
			$https_new = 'https://' . $new_domain;
			if (!in_array($http_new, $search, true)) {
				$search[] = $http_new;
				$replace[] = $https_new;
				$this->sgbackup->log("  Protocol fix: {$http_new} -> {$https_new}");
			}
		}
	}

	/**
	 * Directly fix wp_sitemeta and wp_site after multisite migration.
	 *
	 * String replacement can produce wrong results for wp_sitemeta.siteurl
	 * (e.g., wp.old-host.example -> wp.new-host.example instead of new-host.example).
	 * This method sets the correct values directly via SQL.
	 */
	private function fix_multisite_sitemeta() {
		if (!is_multisite()) return;

		// Skip remap mode: don't modify any multisite tables
		if ($this->subsite_mode === self::SUBSITE_MODE_SKIP_REMAP) {
			$this->sgbackup->log("Multisite fix: skip-remap mode, not modifying sitemeta/blogs tables");
			return;
		}

		global $wpdb;
		$old_domain = parse_url($this->old_siteurl, PHP_URL_HOST);
		$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
		$new_scheme = parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);
		if (!$new_domain || !$old_domain) return;

		$correct_siteurl = $new_scheme . '://' . $new_domain . '/';

		// Fix wp_sitemeta
		$sitemeta_table = $wpdb->base_prefix . 'sitemeta';
		$wpdb->update(
			$sitemeta_table,
			array('meta_value' => $correct_siteurl),
			array('meta_key' => 'siteurl'),
			array('%s'),
			array('%s')
		);
		$this->sgbackup->log("Fixed wp_sitemeta siteurl -> {$correct_siteurl}");

		// Fix wp_site.domain
		$site_table = $wpdb->base_prefix . 'site';
		$wpdb->update(
			$site_table,
			array('domain' => $new_domain),
			array('id' => 1),
			array('%s'),
			array('%d')
		);
		$this->sgbackup->log("Fixed wp_site.domain -> {$new_domain}");

		// Fix wp_blogs for the main site (blog_id = 1)
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$wpdb->update(
			$blogs_table,
			array('domain' => $new_domain),
			array('blog_id' => 1),
			array('%s'),
			array('%d')
		);
		$this->sgbackup->log("Fixed wp_blogs main site domain -> {$new_domain}");

		// Main-only mode: remove all subsites from wp_blogs
		if ($this->subsite_mode === self::SUBSITE_MODE_MAIN_ONLY) {
			$removed = $wpdb->query("DELETE FROM {$blogs_table} WHERE blog_id > 1");
			$this->sgbackup->log("Main-only mode: removed {$removed} subsite entries from wp_blogs");
			$this->progress("Main-only mode: removed {$removed} subsites");
			return;
		}

		// Mirror/Custom mode: fix ALL subsite blogs
		$is_custom = ($this->subsite_mode === self::SUBSITE_MODE_CUSTOM && !empty($this->subsite_map));
		$is_selective_mirror = ($this->subsite_mode === self::SUBSITE_MODE_MIRROR && !empty($this->subsite_map));
		$has_skip_map = ($is_custom || $is_selective_mirror);
		$mode_label = $is_custom ? 'custom-map' : ($is_selective_mirror ? 'selective-mirror' : 'mirror');
		$all_blogs = $wpdb->get_results("SELECT blog_id, domain, path FROM {$blogs_table}");
		$fixed_count = 0;
		foreach ($all_blogs as $blog) {
			if ($blog->blog_id == 1) continue; // Already fixed above

			// Determine new domain: custom/selective map lookup or mirror (str_replace)
			if ($has_skip_map && isset($this->subsite_map[$blog->domain])) {
				if ($this->subsite_map[$blog->domain] === self::SUBSITE_SKIP) {
					// A6: Properly remove skipped subsites - delete from wp_blogs and drop their tables
					$wpdb->delete($blogs_table, array('blog_id' => $blog->blog_id), array('%d'));
					$blog_prefix = $wpdb->base_prefix . $blog->blog_id . '_';
					$subsite_tables = $wpdb->get_col($wpdb->prepare(
						"SHOW TABLES LIKE %s", $wpdb->esc_like($blog_prefix) . '%'
					));
					foreach ($subsite_tables as $table) {
						$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
					}
					$table_count = count($subsite_tables);
					$this->sgbackup->log("  Skipped blog #{$blog->blog_id} ({$blog->domain}): deleted from wp_blogs, dropped {$table_count} tables");
					$this->progress("Removed skipped subsite: {$blog->domain} ({$table_count} tables dropped)");
					continue;
				}
				// For custom mode, use the mapped domain; for selective mirror, fall through to str_replace
				if ($is_custom) {
					$new_blog_domain = $this->subsite_map[$blog->domain];
				} else {
					$new_blog_domain = str_replace($old_domain, $new_domain, $blog->domain);
				}
			} elseif (strpos($blog->domain, $old_domain) !== false) {
				$new_blog_domain = str_replace($old_domain, $new_domain, $blog->domain);
			} else {
				continue; // Domain doesn't match old domain and no custom mapping
			}

			if ($new_blog_domain === $blog->domain) continue; // No change needed

			$wpdb->update(
				$blogs_table,
				array('domain' => $new_blog_domain),
				array('blog_id' => $blog->blog_id),
				array('%s'),
				array('%d')
			);

			// Also directly fix wp_N_options siteurl and home
			$blog_prefix = ($blog->blog_id > 1) ? $wpdb->base_prefix . $blog->blog_id . '_' : $wpdb->base_prefix;
			$blog_options_table = $blog_prefix . 'options';

			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $blog_options_table)) === $blog_options_table) {
				foreach (array('siteurl', 'home') as $opt) {
					$old_val = $wpdb->get_var($wpdb->prepare(
						"SELECT option_value FROM {$blog_options_table} WHERE option_name = %s", $opt
					));
					if (!$old_val) continue;

					// For custom mapping, replace the old blog domain with the new one
					if ($is_custom && isset($this->subsite_map[$blog->domain])) {
						$new_val = str_replace($blog->domain, $new_blog_domain, $old_val);
					} elseif (strpos($old_val, $old_domain) !== false) {
						$new_val = str_replace($old_domain, $new_domain, $old_val);
					} else {
						continue;
					}

					// Also fix protocol if needed
					if ($new_scheme === 'https' && strpos($new_val, 'http://') === 0) {
						$new_val = 'https://' . substr($new_val, 7);
					}
					$wpdb->update(
						$blog_options_table,
						array('option_value' => $new_val),
						array('option_name' => $opt),
						array('%s'),
						array('%s')
					);
				}
			}
			$fixed_count++;
			if ($is_custom || $is_selective_mirror) {
				$this->sgbackup->log("  {$mode_label}: blog #{$blog->blog_id} {$blog->domain} -> {$new_blog_domain}");
			}
		}
		$this->sgbackup->log("Fixed {$fixed_count} subsite blog domains in wp_blogs + per-site options ({$mode_label})");
		if ($fixed_count > 0) {
			$this->progress("Fixed {$fixed_count} subsite domains for migration ({$mode_label})");
		}
	}

	/**
	 * Verify all subsites have correct domains after migration.
	 * Called from post_restore_cleanup() for multisite migrations.
	 */
	private function verify_subsites() {
		if (!is_multisite() || !$this->is_migration) return;

		// Skip-remap mode: domains were intentionally NOT remapped, don't verify against new domain
		if ($this->subsite_mode === self::SUBSITE_MODE_SKIP_REMAP) {
			$this->sgbackup->log("Subsite verification: skip-remap mode, skipping domain checks");
			$this->progress("Subsite domains were not remapped (skip-remap mode)");
			return;
		}

		global $wpdb;
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$blogs = $wpdb->get_results("SELECT blog_id, domain, path FROM {$blogs_table} ORDER BY blog_id");

		$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
		if (!$new_domain) return;

		// Main-only mode: only blog_id=1 should exist
		if ($this->subsite_mode === self::SUBSITE_MODE_MAIN_ONLY) {
			$count = count($blogs);
			if ($count === 1) {
				$this->sgbackup->log("Subsite verification: main-only mode, 1 blog remaining (subsites removed)");
				$this->progress("Main-only mode: subsites removed, 1 blog remaining");
			} else {
				$this->sgbackup->log("Subsite verification: main-only mode, expected 1 blog but found {$count}", 'warning');
				$this->progress("WARNING: main-only mode but {$count} blogs found");
			}
			return;
		}

		// Mirror/custom mode: verify all blogs have correct domains
		$issues = array();

		// In custom or selective-mirror mode, collect domains that were intentionally skipped
		$skipped_domains = array();
		if (($this->subsite_mode === self::SUBSITE_MODE_CUSTOM || $this->subsite_mode === self::SUBSITE_MODE_MIRROR) && !empty($this->subsite_map)) {
			$skipped_domains = array_keys(array_filter($this->subsite_map, function($v) {
				return $v === self::SUBSITE_SKIP;
			}));
		}

		foreach ($blogs as $blog) {
			// Skip verification for blogs intentionally skipped in custom map
			if (!empty($skipped_domains) && in_array($blog->domain, $skipped_domains, true)) continue;

			// Check domain ends with new domain (suffix match, not substring)
			$domain_matches = ($blog->domain === $new_domain)
				|| (substr($blog->domain, -(strlen($new_domain) + 1)) === '.' . $new_domain);
			if (!$domain_matches) {
				$issues[] = "Blog #{$blog->blog_id} has domain '{$blog->domain}' which doesn't match target domain '{$new_domain}'";
			}

			// Check per-site options table exists
			$blog_prefix = ($blog->blog_id > 1) ? $wpdb->base_prefix . $blog->blog_id . '_' : $wpdb->base_prefix;
			$opts_table = $blog_prefix . 'options';
			if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $opts_table)) !== $opts_table) {
				$issues[] = "Blog #{$blog->blog_id} ({$blog->domain}) is missing its options table ({$opts_table})";
			}
		}

		if (empty($issues)) {
			$this->sgbackup->log("Subsite verification: all " . count($blogs) . " blogs verified OK");
			$this->progress("All " . count($blogs) . " subsites verified successfully");
		} else {
			foreach ($issues as $issue) {
				$this->sgbackup->log("Subsite issue: {$issue}", 'warning');
			}
			$this->progress("WARNING: " . count($issues) . " subsite issues detected - check logs");
		}

		// Infrastructure warnings
		if (count($blogs) > 1) {
			$this->progress("NOTE: Subsites require wildcard DNS (*." . $new_domain . ") and web server config to accept subdomains");
		}
	}

	/**
	 * Remove skipped subsites from wp_blogs and drop their tables.
	 *
	 * Called for same-site restores (non-migration) when the user has
	 * unchecked subsites in mirror mode or marked them as __skip__ in
	 * custom mode. During migrations, fix_multisite_sitemeta() handles
	 * this as part of its domain-remapping loop.
	 */
	private function apply_subsite_skips() {
		global $wpdb;
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$removed = 0;

		foreach ($this->subsite_map as $domain => $action) {
			if ($action !== self::SUBSITE_SKIP) continue;

			$blog = $wpdb->get_row($wpdb->prepare(
				"SELECT blog_id FROM {$blogs_table} WHERE domain = %s AND blog_id > 1",
				$domain
			));
			if (!$blog) {
				$this->sgbackup->log("Subsite skip: domain {$domain} not found in wp_blogs, skipping");
				continue;
			}

			$wpdb->delete($blogs_table, array('blog_id' => $blog->blog_id), array('%d'));
			$blog_prefix = $wpdb->base_prefix . $blog->blog_id . '_';
			$subsite_tables = $wpdb->get_col($wpdb->prepare(
				"SHOW TABLES LIKE %s", $wpdb->esc_like($blog_prefix) . '%'
			));
			foreach ($subsite_tables as $table) {
				$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
			}
			$table_count = count($subsite_tables);
			$this->sgbackup->log("Removed skipped subsite #{$blog->blog_id} ({$domain}): dropped {$table_count} tables");
			$this->progress("Removed skipped subsite: {$domain} ({$table_count} tables dropped)");
			$removed++;
		}

		if ($removed > 0) {
			$this->sgbackup->log("apply_subsite_skips: removed {$removed} subsites");
		}
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

		// Clean up imported cron events and job state from the source site.
		// After a DB import, the cron table contains events from the source
		// site (e.g., sgbackup_backup_resume) that would fire on page loads
		// and attempt to resume old backup/restore jobs, potentially causing
		// repeated DB overwrites or other failures.
		$this->cleanup_imported_cron_events();

		// Validate active theme exists
		$this->validate_theme();

		// Check active plugins exist
		$this->validate_plugins();

		// Verify subsites after migration
		$this->verify_subsites();

		// Flush multisite blog caches aggressively
		if (is_multisite()) {
			global $wpdb;
			$all_blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->base_prefix}blogs");
			foreach ($all_blog_ids as $bid) {
				if (function_exists('clean_blog_cache')) {
					clean_blog_cache(intval($bid));
				}
			}
			wp_cache_delete('site-options-0', 'site-options');
			wp_cache_delete('1:active_sitewide_plugins', 'site-options');
		}

		// Disable known caching plugins if this is a migration
		if ($this->is_migration) {
			$this->disable_cache_plugins();

			// Warn about DOMAIN_CURRENT_SITE mismatch in wp-config.php.
			// After a cross-domain restore, this constant still points to the
			// old domain, causing network admin redirects and AJAX failures.
			if (is_multisite() && defined('DOMAIN_CURRENT_SITE')) {
				$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
				if ($new_domain && DOMAIN_CURRENT_SITE !== $new_domain) {
					$this->sgbackup->log("WARNING: DOMAIN_CURRENT_SITE in wp-config.php is '" . DOMAIN_CURRENT_SITE . "' but should be '{$new_domain}'. Update wp-config.php to fix network admin access.", 'warning');
					$this->progress("WARNING: wp-config.php DOMAIN_CURRENT_SITE needs manual update to '{$new_domain}'");
				}
			}
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
	 * Clean up imported cron events and plugin job state from the source site.
	 *
	 * After a DB restore, the imported cron table may contain stale
	 * sgbackup_backup_resume events from the source site. If not cleaned up,
	 * these fire on every page load and attempt to resume old jobs.
	 */
	private function cleanup_imported_cron_events() {
		global $wpdb;

		// Remove all sgbackup_backup_resume cron events
		$crons = _get_cron_array();
		if (is_array($crons)) {
			$cleaned = 0;
			foreach ($crons as $timestamp => $hooks) {
				if (isset($hooks['sgbackup_backup_resume'])) {
					foreach ($hooks['sgbackup_backup_resume'] as $key => $event) {
						wp_unschedule_event($timestamp, 'sgbackup_backup_resume', $event['args']);
						$cleaned++;
					}
				}
			}
			if ($cleaned > 0) {
				$this->sgbackup->log("Cleaned up {$cleaned} imported sgbackup_backup_resume cron events");
			}
		}

		// Mark any imported jobs as complete so they don't try to resume
		if (is_multisite()) {
			$job_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like('sgbackup_jobdata_') . '%'
			));
			foreach ($job_rows as $row) {
				if (strpos($row->meta_value, '"running"') !== false) {
					$wpdb->update(
						$wpdb->sitemeta,
						array('meta_value' => str_replace('"running"', '"complete"', $row->meta_value)),
						array('meta_key' => $row->meta_key),
						array('%s'),
						array('%s')
					);
				}
			}
		} else {
			$job_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like('sgbackup_jobdata_') . '%'
			));
			foreach ($job_rows as $row) {
				if (strpos($row->option_value, '"running"') !== false) {
					$wpdb->update(
						$wpdb->options,
						array('option_value' => str_replace('"running"', '"complete"', $row->option_value)),
						array('option_name' => $row->option_name),
						array('%s'),
						array('%s')
					);
				}
			}
		}

		// Remove semaphore locks from the imported DB
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like('sgbackup_sem_') . '%'
		));

		// Remove restore progress transients
		if (is_multisite()) {
			delete_site_option('sgbackup_restore_progress');
		} else {
			delete_option('sgbackup_restore_progress');
		}

		$this->sgbackup->log("Cleaned up imported job state and semaphore locks");
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
