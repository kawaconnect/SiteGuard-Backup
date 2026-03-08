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
	const SUBSITE_MODE_IMPORT_AS = 4;  // Import a subsite backup into a multisite as a new/existing subsite
	const SUBSITE_MODE_BATCH = 5;      // Batch import: selected subsites only, main site untouched

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

	/** @var bool Keep original protocol (skip HTTP/HTTPS conversion) */
	private $keep_protocol = false;

	/** @var bool Remap independent domains to subdomains of the new domain */
	private $remap_independent_domains = true;

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

	/** @var array Destination wp_blogs entries saved before import (for filtered import with skips) */
	private $pre_import_blogs = array();

	/** @var array Destination sgbackup_* options saved before DB import */
	private $pre_import_sgbackup_options = array();

	/** @var bool Whether this backup is a per-subsite backup */
	private $is_subsite_backup = false;

	/** @var int Source blog ID from the subsite backup */
	private $source_blog_id = 0;

	/** @var string Target domain for subsite import */
	private $target_subsite_domain = '';

	/** @var int Target blog ID for subsite import (unambiguous for subdirectory installs) */
	private $target_subsite_id = 0;

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
			$this->subsite_mode = min(5, max(0, intval($restore_options['subsite_mode'])));
		} else {
			$this->subsite_mode = min(5, max(0, intval(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_subsite_mode', 0))));
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

		// Keep original protocol (skip HTTP/HTTPS conversion) - per-restore or saved default
		if (isset($restore_options['keep_protocol'])) {
			$this->keep_protocol = !empty($restore_options['keep_protocol']);
		} else {
			$this->keep_protocol = !empty(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_keep_protocol', 0));
		}

		// Remap independent domains - per-restore or saved default
		if (isset($restore_options['remap_independent_domains'])) {
			$this->remap_independent_domains = !empty($restore_options['remap_independent_domains']);
		} else {
			$this->remap_independent_domains = !empty(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_remap_independent_domains', 1));
		}

		// Detect subsite backup from metadata
		if (!empty($backup_set['is_subsite_backup'])) {
			$this->is_subsite_backup = true;
			$this->source_blog_id = intval($backup_set['subsite_blog_id']);
		}

		// Target domain/blog_id for subsite import mode
		if (!empty($restore_options['target_subsite_id'])) {
			$this->target_subsite_id = intval($restore_options['target_subsite_id']);
		}
		if (!empty($restore_options['target_subsite_domain'])) {
			$this->target_subsite_domain = sanitize_text_field($restore_options['target_subsite_domain']);
			if (is_multisite()) {
				$this->subsite_mode = self::SUBSITE_MODE_IMPORT_AS;
			}
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

		// Pre-scan SQL to detect subsite backup when metadata is missing.
		// Must run before the guard below to catch subsite backups early.
		if (!$this->is_subsite_backup && is_multisite() && !empty($this->backup_set['db'])) {
			$db_files = $this->backup_set['db'];
			if (is_string($db_files)) $db_files = array($db_files);
			$first_file = $backup_dir . $db_files[0];
			if (!preg_match('/\.crypt$/', $first_file)) {
				$scan_tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($first_file);
				$base = $GLOBALS['wpdb']->base_prefix;
				$has_main_options = false;
				$detected_id = 0;
				foreach ($scan_tables as $tbl) {
					if ($tbl === $base . 'options' || $tbl === $base . 'blogs') {
						$has_main_options = true;
						break;
					}
					if (!$detected_id && preg_match('/^' . preg_quote($base, '/') . '(\d+)_options$/', $tbl, $sm)) {
						$detected_id = (int) $sm[1];
					}
				}
				if (!$has_main_options && $detected_id > 0) {
					$this->is_subsite_backup = true;
					$this->source_blog_id = $detected_id;
					$this->sgbackup->log("Pre-scan detected subsite backup: blog #{$detected_id} (no main options table)");
				}
			}
		}

		// Guard: subsite backups on multisite MUST use IMPORT_AS mode.
		// The standard restore path can't handle subsite-only SQL files correctly
		// (no wp_options → no migration detection, no wp_blogs → no subsite entry).
		if ($this->is_subsite_backup && is_multisite() && $this->subsite_mode !== self::SUBSITE_MODE_IMPORT_AS) {
			$src_domain = !empty($this->backup_set['subsite_domain']) ? $this->backup_set['subsite_domain'] : 'unknown';
			$src_id = !empty($this->source_blog_id) ? $this->source_blog_id : (!empty($this->backup_set['subsite_blog_id']) ? $this->backup_set['subsite_blog_id'] : '?');
			$this->progress("ERROR: This is a per-subsite backup (blog #{$src_id}, {$src_domain}). It must be restored using 'Import as subsite' mode with a target domain.");
			$this->sgbackup->log("Subsite backup restore blocked: subsite_mode={$this->subsite_mode} but IMPORT_AS (4) required", 'error');
			$result['success'] = false;
			$result['errors'][] = "This is a per-subsite backup from {$src_domain}. To restore it, use 'Import as subsite' mode and provide a target domain. Standard migrate/overwrite modes cannot handle subsite-only backups.";
			$this->sgbackup->logfile_close();
			return $result;
		}

		// Subsite import mode: use dedicated restore path (works for both
		// per-subsite backups AND standard single-site/full-site backups).
		if ($this->subsite_mode === self::SUBSITE_MODE_IMPORT_AS && is_multisite()) {
			$target_label = $this->target_subsite_domain ? $this->target_subsite_domain : ($this->target_subsite_id > 0 ? "blog #{$this->target_subsite_id}" : '(pending selection)');
			$this->progress("Restoring backup as subsite: {$target_label}");
			$subsite_result = $this->restore_as_subsite($backup_dir);
			if (!$subsite_result['success']) {
				$result['errors'] = array_merge($result['errors'], $subsite_result['errors']);
				$result['success'] = false;
			}
			$result['messages'] = array_merge($result['messages'], $subsite_result['messages']);

			// Phase 3: Post-restore
			$this->post_restore_cleanup();
			$result['messages'] = array_merge($this->progress, $result['messages']);
			$this->sgbackup->logfile_close();
			return $result;
		}

		// Batch subsite import: import only selected subsites, main site untouched
		if ($this->subsite_mode === self::SUBSITE_MODE_BATCH && is_multisite()) {
			$this->progress("Batch subsite import: importing selected subsites");
			$batch_result = $this->restore_batch_subsites($backup_dir);
			if (!$batch_result['success']) {
				$result['errors'] = array_merge($result['errors'], $batch_result['errors']);
				$result['success'] = false;
			}
			$result['messages'] = array_merge($result['messages'], $batch_result['messages']);

			$this->post_restore_cleanup();
			$result['messages'] = array_merge($this->progress, $result['messages']);
			$this->sgbackup->logfile_close();
			return $result;
		}

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

		// Save destination's wp_blogs entries before import, so we can re-insert
		// them if the import uses filtered mode (custom/mirror with skips).
		if (is_multisite()) {
			global $wpdb;
			$blogs_table = $wpdb->base_prefix . 'blogs';
			$dest_blogs = $wpdb->get_results("SELECT * FROM {$blogs_table} WHERE blog_id > 1", ARRAY_A);
			if (!empty($dest_blogs)) {
				$this->pre_import_blogs = $dest_blogs;
				$this->sgbackup->log("Saved " . count($dest_blogs) . " destination wp_blogs entries before import");
			}
		}

		// Save destination's sgbackup_* options before DB import.
		// After import these get overwritten with source values; we restore them in post_restore_cleanup().
		$this->save_plugin_options_before_import();

		// Save progress for resume support
		$this->save_restore_progress('db', 'started');

		// Detect whether custom/mirror mode has __skip__ entries - if so we must
		// use filtered import to avoid destroying destination subsite tables.
		$has_skips = false;
		if (is_multisite() && !empty($this->subsite_map)) {
			foreach ($this->subsite_map as $domain => $target) {
				if ($target === self::SUBSITE_SKIP) {
					$has_skips = true;
					break;
				}
			}
			if ($has_skips) {
				$this->sgbackup->log("Custom/mirror map has __skip__ entries - will use filtered import to preserve destination subsites");
			}
		}

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

			// Skip user tables during migrations to preserve destination login
			// credentials. The source backup's wp_users/wp_usermeta have different
			// password hashes, and importing them would lock out the admin.
			$skip_user_tables = ($this->is_migration && in_array($this->db_restore_mode, array(self::DB_RESTORE_MIGRATE, self::DB_RESTORE_OVERWRITE), true));

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
			} elseif ($has_skips) {
				// Custom/mirror mode with __skip__ entries: use filtered import to
				// avoid destroying destination subsite tables at overlapping blog_ids.
				$source_blogs = $this->parse_blogs_from_sql($file_path);
				if (empty($source_blogs)) {
					// Safety: if we couldn't parse blog entries, fall back to atomic import
					// rather than risk silently excluding all subsite tables.
					$this->sgbackup->log("Warning: could not parse blogs from SQL file - falling back to atomic import for safety");
				}
				$all_backup_tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($file_path);
				$tables_to_import = empty($source_blogs) ? $all_backup_tables : $this->compute_selective_tables($all_backup_tables, $source_blogs);
				$skipped_count = count($all_backup_tables) - count($tables_to_import);
				if ($skipped_count === 0) {
					// Nothing was actually excluded - use atomic import for crash safety
					$this->sgbackup->log("Filtered import: no tables excluded (source blogs not matched to skip map), using atomic import");
					$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_atomic(
						$file_path,
						function($lines_done) {
							$this->progress("Database import: {$lines_done} lines processed");
						}
					);
				} else {
					$this->sgbackup->log("Filtered import: {$skipped_count} tables excluded for skipped subsites, importing " . count($tables_to_import) . " tables");
					$this->progress("Importing " . count($tables_to_import) . " tables (skipping {$skipped_count} subsite tables)");
					$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
						$file_path,
						$tables_to_import,
						function($lines_done) {
							$this->progress("Database import: {$lines_done} lines processed");
						}
					);
				}
			} elseif ($skip_user_tables) {
				// Migration: import all tables EXCEPT wp_users and wp_usermeta
				// to preserve destination login credentials.
				global $wpdb;
				$user_tables = array($wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta');
				$all_tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($file_path);
				$tables_to_import = array_diff($all_tables, $user_tables);
				$this->sgbackup->log("Migration: skipping user tables (" . implode(', ', $user_tables) . ") to preserve destination credentials");
				$this->progress("Importing " . count($tables_to_import) . " tables (preserving user credentials)");
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$tables_to_import,
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

		// Capture imported siteurl/home BEFORE overwriting - needed for migration
		// detection below. After the safety-net write, get_option returns destination.
		$imported_siteurl = get_option('siteurl');
		$imported_home = get_option('home');

		// Safety net: immediately restore the destination's siteurl/home after
		// DB import. The import overwrites wp_options with source values, making
		// the site unreachable if search-replace fails, times out, or doesn't
		// apply. This ensures the site always stays loadable.
		if (!empty($this->pre_import_siteurl) && $imported_siteurl !== $this->pre_import_siteurl) {
			update_option('siteurl', $this->pre_import_siteurl);
			update_option('home', $this->pre_import_home);
			$this->sgbackup->log("Safety: restored destination siteurl/home ({$this->pre_import_siteurl}) after DB import");
			if (is_multisite()) {
				global $wpdb;
				$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
				$new_scheme = parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);
				if ($new_domain) {
					$wpdb->update($wpdb->base_prefix . 'sitemeta', array('meta_value' => $new_scheme . '://' . $new_domain . '/'), array('meta_key' => 'siteurl'), array('%s'), array('%s'));
					$wpdb->update($wpdb->base_prefix . 'site', array('domain' => $new_domain), array('id' => 1), array('%s'), array('%d'));
					$wpdb->update($wpdb->base_prefix . 'blogs', array('domain' => $new_domain), array('blog_id' => 1), array('%s'), array('%d'));
					$this->sgbackup->log("Safety: restored multisite identity tables (domain={$new_domain})");
				}
			}
			wp_cache_flush();
		}

		// AUTHORITATIVE migration detection from the actual imported database.
		// Uses $imported_siteurl captured BEFORE the safety-net write above.
		if ($this->db_restore_mode === self::DB_RESTORE_MIGRATE) {
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

		// Handle subsite operations on same-site restores (non-migration).
		// fix_multisite_sitemeta handles this during migration, but for same-site
		// restores is_migration=false so it never runs.
		if (!$this->is_migration && is_multisite()) {
			if ($this->subsite_mode === self::SUBSITE_MODE_MAIN_ONLY) {
				$this->apply_main_only_mode();
			} elseif (!empty($this->subsite_map)) {
				$this->apply_subsite_skips();
			}
		}

		// Save progress for resume support
		$this->save_restore_progress('db', 'completed');

		return $result;
	}

	/**
	 * Import a subsite backup into this multisite as a new or existing subsite.
	 *
	 * Steps:
	 * 1. Create or find the target subsite in wp_blogs
	 * 2. Import SQL with prefix remapping (wp_{source_id}_ -> wp_{target_id}_)
	 * 3. Merge users by email (reuse existing or insert new)
	 * 4. Move uploaded files from /sites/{source}/ to /sites/{target}/
	 * 5. Search-replace old domain -> new domain in subsite tables
	 * 6. Fix wp_{target_id}_options siteurl/home
	 *
	 * @param string $backup_dir Path to backup directory.
	 * @return array ['success' => bool, 'messages' => array, 'errors' => array]
	 */
	private function restore_as_subsite($backup_dir) {
		global $wpdb;

		$result = array('success' => true, 'messages' => array(), 'errors' => array());
		$source_id = $this->source_blog_id; // 0 for single-site backup, N for per-subsite backup
		$target_domain = $this->target_subsite_domain;

		// Step 1: Find or create the target subsite
		// Use blog_id for unambiguous lookup (required for subdirectory installs
		// where multiple subsites share the same domain). Do this BEFORE the
		// empty-domain check so we can derive the domain from the blog_id.
		$target_blog = null;
		$target_path = '/';
		if ($this->target_subsite_id > 0) {
			$target_blog = get_blog_details($this->target_subsite_id, false);
			if ($target_blog) {
				$target_domain = $target_blog->domain;
				$target_path = $target_blog->path;
				$this->sgbackup->log("Looked up target by blog_id #{$this->target_subsite_id}: domain={$target_domain}, path={$target_path}");
			}
		}

		if (empty($target_domain)) {
			$result['success'] = false;
			$result['errors'][] = 'Missing target domain for subsite import. Please select a target subsite from the dropdown or create a new one.';
			return $result;
		}

		$source_label = $source_id > 0 ? "subsite blog #{$source_id}" : "single-site backup";
		$this->sgbackup->log("Subsite import: {$source_label} -> target domain {$target_domain}");
		$this->progress("Importing {$source_label} to {$target_domain}");
		if (!$target_blog) {
			$target_blog = get_blog_details(array('domain' => $target_domain), false);
		}
		$target_id = null;

		if ($target_blog) {
			$target_id = (int) $target_blog->blog_id;
			$target_path = $target_blog->path;
			$this->sgbackup->log("Target subsite exists: blog #{$target_id} ({$target_domain}{$target_path})");
			$this->progress("Target subsite already exists (blog #{$target_id}), will overwrite tables");

			// Drop existing subsite tables so we can import fresh
			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$existing_tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);
			if (!empty($existing_tables)) {
				$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
				foreach ($existing_tables as $table) {
					$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
					if ($safe) {
						$wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
					}
				}
				$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
				$this->sgbackup->log("Dropped " . count($existing_tables) . " existing tables for blog #{$target_id}");
			}
		} else {
			// Create new subsite
			$network = get_network();
			$path = '/';

			// Determine if this is a subdomain or subdirectory install
			if (is_subdomain_install()) {
				$path = '/';
			} else {
				// Subdirectory: derive path from domain
				$path = '/' . trim(str_replace($network->domain, '', $target_domain), './ ') . '/';
				if ($path === '//') $path = '/';
				$target_domain = $network->domain;
			}

			$target_id = wpmu_create_blog($target_domain, $path, 'Imported subsite', get_current_user_id(), array('public' => 1), $network->id);
			if (is_wp_error($target_id)) {
				$result['success'] = false;
				$result['errors'][] = 'Failed to create subsite: ' . $target_id->get_error_message();
				return $result;
			}

			$target_path = $path;
			$this->sgbackup->log("Created new subsite: blog #{$target_id} ({$target_domain}{$target_path})");
			$this->progress("Created new subsite blog #{$target_id}");

			// Drop the auto-created tables (we'll import from backup)
			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$auto_tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);
			if (!empty($auto_tables)) {
				$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
				foreach ($auto_tables as $table) {
					$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
					if ($safe) {
						$wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
					}
				}
				$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
			}
		}

		// Step 2: Import SQL with prefix remapping
		if (!empty($this->restore_options['db']) && !empty($this->backup_set['db'])) {
			$db_files = $this->backup_set['db'];
			if (is_string($db_files)) $db_files = array($db_files);

			// Detect source prefix from SQL: subsite backups have wp_N_* tables,
			// single-site backups have wp_* (base prefix only).
			$source_prefix = $wpdb->base_prefix; // default: single-site
			if ($source_id > 1) {
				$source_prefix = $wpdb->base_prefix . $source_id . '_';
			} else {
				// Auto-detect: scan first SQL file for table names to find the actual prefix
				$first_file = $backup_dir . $db_files[0];
				if (preg_match('/\.crypt$/', $first_file)) {
					// Can't scan encrypted files here; rely on source_id
				} else {
					$all_tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($first_file);
					$prefix_pattern = '/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+)_/';
					$detected_ids = array();
					foreach ($all_tables as $table) {
						if (preg_match($prefix_pattern, $table, $m)) {
							$detected_ids[intval($m[1])] = true;
						}
					}
					if (count($detected_ids) === 1) {
						$detected_id = key($detected_ids);
						$source_prefix = $wpdb->base_prefix . $detected_id . '_';
						$source_id = $detected_id;
						$this->sgbackup->log("Auto-detected subsite prefix: {$source_prefix} (blog #{$detected_id})");
					} elseif (count($detected_ids) > 1) {
						// Multiple blog_ids - pick the most common one
						$id_counts = array();
						foreach ($all_tables as $table) {
							if (preg_match($prefix_pattern, $table, $m)) {
								$id = intval($m[1]);
								$id_counts[$id] = ($id_counts[$id] ?? 0) + 1;
							}
						}
						arsort($id_counts);
						$detected_id = key($id_counts);
						$source_prefix = $wpdb->base_prefix . $detected_id . '_';
						$source_id = $detected_id;
						$this->sgbackup->log("Auto-detected subsite prefix from multiple blog_ids: {$source_prefix} (blog #{$detected_id})");
					}
				}
			}
			$target_prefix = $wpdb->base_prefix . $target_id . '_';

			$this->progress("Importing database: remapping {$source_prefix}* -> {$target_prefix}*");

			$resolved_db_paths = array();
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

				$resolved_db_paths[] = $file_path;

				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_remap_prefix(
					$file_path,
					$source_prefix,
					$target_prefix,
					$wpdb->base_prefix
				);

				$result['messages'][] = "Database: {$import_result['tables_restored']} tables imported (remapped to blog #{$target_id})";
				if (!empty($import_result['errors'])) {
					$result['errors'] = array_merge($result['errors'], $import_result['errors']);
				}
				if (!$import_result['success']) {
					$result['success'] = false;
				}
			}

			// Step 3: Merge users - match by email
			$this->merge_subsite_users($source_id, $target_id, $backup_dir, $resolved_db_paths);
		}

		// Step 4: Move uploaded files
		if (!empty($this->restore_options['uploads']) && !empty($this->backup_set['uploads'])) {
			$this->progress("Restoring uploads for subsite #{$target_id}");
			$uploads_result = $this->restore_subsite_uploads($backup_dir, $source_id, $target_id);
			if ($uploads_result) {
				$result['messages'][] = "Uploads: restored to /sites/{$target_id}/";
			} else {
				$result['errors'][] = "Failed to restore uploads";
			}
		}

		// Step 5: Search-replace old domain -> new domain in subsite tables only
		$old_domain = isset($this->backup_set['subsite_domain']) ? $this->backup_set['subsite_domain'] : '';
		$old_url_base = ''; // Full old URL base for path-aware comparison
		// For single-site backups, subsite_domain won't be set - read from the imported options table
		if (empty($old_domain)) {
			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$imported_siteurl = $wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'siteurl'");
			if ($imported_siteurl) {
				$old_domain = parse_url($imported_siteurl, PHP_URL_HOST);
				$old_url_base = rtrim($imported_siteurl, '/');
				$this->sgbackup->log("Detected source domain from imported siteurl: {$old_domain}");
			}
		}
		// Build the target URL: domain + path (handles subdirectory installs)
		$new_scheme = parse_url(get_option('siteurl'), PHP_URL_SCHEME);
		$target_url_base = $new_scheme . '://' . $target_domain . rtrim($target_path, '/');

		// Compare full URL bases to catch subdirectory-only path changes
		// (e.g., example.com/shop -> example.com/store where domain is the same)
		$needs_sr = !empty($old_domain) && $old_domain !== $target_domain;
		if (!$needs_sr && !empty($old_url_base) && $old_url_base !== $target_url_base) {
			$needs_sr = true;
			$this->sgbackup->log("Same domain but different path/scheme detected: {$old_url_base} -> {$target_url_base}");
		}

		if ($needs_sr) {
			$this->progress("Search-replace: {$old_domain} -> {$target_domain}" . ($target_path !== '/' ? $target_path : ''));

			require_once SGBACKUP_DIR . 'includes/class-search-replace.php';

			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);

			$search = array();
			$replace = array();

			// If we have the full old URL base, use it for precise path replacement
			if (!empty($old_url_base)) {
				$old_url_https = preg_replace('/^https?/', 'https', $old_url_base);
				$old_url_http = preg_replace('/^https?/', 'http', $old_url_base);
				$search[] = $old_url_https;
				$replace[] = $target_url_base;
				$search[] = $old_url_http;
				$replace[] = $target_url_base;
			} else {
				// Fallback: domain-only replacement (both protocols)
				$search[] = 'https://' . $old_domain;
				$replace[] = $target_url_base;
				$search[] = 'http://' . $old_domain;
				$replace[] = $target_url_base;
			}
			// Bare domain catch-all (domain-only, no path - safer for non-URL contexts)
			if ($old_domain !== $target_domain) {
				$search[] = $old_domain;
				$replace[] = $target_domain;
			}

			$sr = new SiteGuard_Backup_Search_Replace();
			$report = $sr->run($search, $replace, $tables);

			$this->sgbackup->log("Subsite search-replace: {$report['changes']} changes across {$report['tables']} tables");
			$this->progress("Search-replace: {$report['changes']} changes in {$report['tables']} tables");

			$result['messages'][] = "Search-replace: {$report['changes']} changes ({$old_domain} -> {$target_domain}" . ($target_path !== '/' ? $target_path : '') . ")";
		}

		// Step 6: Fix subsite options (siteurl, home)
		$target_prefix = $wpdb->base_prefix . $target_id . '_';
		$new_siteurl = $target_url_base;

		$wpdb->update(
			$target_prefix . 'options',
			array('option_value' => $new_siteurl),
			array('option_name' => 'siteurl')
		);
		$wpdb->update(
			$target_prefix . 'options',
			array('option_value' => $new_siteurl),
			array('option_name' => 'home')
		);

		$this->sgbackup->log("Fixed subsite siteurl/home -> {$new_siteurl}");
		$result['messages'][] = "Subsite import complete: blog #{$target_id} at {$target_domain}";

		return $result;
	}

	/**
	 * Batch import selected subsites from a full-site backup.
	 *
	 * Streams through the SQL once, importing only mapped subsite tables with
	 * prefix remapping. Main-site tables are never touched.
	 *
	 * @param string $backup_dir Path to backup directory.
	 * @return array ['success' => bool, 'messages' => array, 'errors' => array]
	 */
	private function restore_batch_subsites($backup_dir) {
		global $wpdb;

		$result = array('success' => true, 'messages' => array(), 'errors' => array());
		$batch_map = isset($this->restore_options['batch_subsite_map']) ? $this->restore_options['batch_subsite_map'] : array();

		// Filter out skip entries
		$batch_map = array_filter($batch_map, function($entry) {
			return isset($entry['action']) && $entry['action'] !== 'skip';
		});

		if (empty($batch_map)) {
			$result['success'] = false;
			$result['errors'][] = 'No subsites selected for batch import.';
			return $result;
		}

		$base_prefix = $wpdb->base_prefix;

		// Step 1: Prepare targets and build prefix map
		$prefix_map = array();
		$target_map = array(); // source_blog_id => target_blog_id
		$source_domains = array(); // source_blog_id => source_domain

		foreach ($batch_map as $entry) {
			$source_id = intval($entry['source_blog_id']);
			if ($source_id <= 1) {
				$this->sgbackup->log("Batch: skipping blog #{$source_id} - main site cannot be batch-imported");
				continue;
			}
			$source_domain = isset($entry['source_domain']) ? $entry['source_domain'] : '';
			$action = $entry['action'];
			$source_prefix = $base_prefix . $source_id . '_';

			$source_domains[$source_id] = $source_domain;

			if ($action === 'overwrite') {
				$target_id = intval($entry['target_blog_id']);
				$target_blog = get_blog_details($target_id, false);
				if (!$target_blog) {
					$result['errors'][] = "Target blog #{$target_id} not found for source #{$source_id}";
					continue;
				}

				// Drop existing subsite tables
				$target_prefix = $base_prefix . $target_id . '_';
				$existing_tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);
				if (!empty($existing_tables)) {
					$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
					foreach ($existing_tables as $table) {
						$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
						if ($safe) {
							$wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
						}
					}
					$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
					$this->sgbackup->log("Batch: dropped " . count($existing_tables) . " existing tables for target blog #{$target_id}");
				}

				$prefix_map[$source_prefix] = $target_prefix;
				$target_map[$source_id] = $target_id;
				$this->progress("Subsite #{$source_id} ({$source_domain}) -> overwrite blog #{$target_id}");

			} elseif ($action === 'create') {
				$target_domain = isset($entry['target_domain']) ? $entry['target_domain'] : '';
				if (empty($target_domain)) {
					$result['errors'][] = "Missing target domain for source #{$source_id}";
					continue;
				}

				$network = get_network();
				$path = '/';
				$create_domain = $target_domain;

				if (is_subdomain_install()) {
					$path = '/';
				} else {
					$path = '/' . trim(str_replace($network->domain, '', $target_domain), './ ') . '/';
					if ($path === '//') $path = '/';
					$create_domain = $network->domain;
				}

				$target_id = wpmu_create_blog($create_domain, $path, 'Imported subsite', get_current_user_id(), array('public' => 1), $network->id);
				if (is_wp_error($target_id)) {
					$result['errors'][] = "Failed to create subsite for {$target_domain}: " . $target_id->get_error_message();
					continue;
				}
				$this->sgbackup->log("Batch: created new subsite blog #{$target_id} ({$target_domain})");

				// Drop auto-created tables
				$target_prefix = $base_prefix . $target_id . '_';
				$auto_tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);
				if (!empty($auto_tables)) {
					$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
					foreach ($auto_tables as $table) {
						$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
						if ($safe) {
							$wpdb->query("DROP TABLE IF EXISTS `{$safe}`");
						}
					}
					$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
				}

				$prefix_map[$source_prefix] = $target_prefix;
				$target_map[$source_id] = $target_id;
				$this->progress("Subsite #{$source_id} ({$source_domain}) -> new blog #{$target_id} ({$target_domain})");
			}
		}

		if (empty($prefix_map)) {
			$result['success'] = false;
			$result['errors'][] = 'No valid subsite mappings could be prepared.';
			return $result;
		}

		// Step 2: SQL import with multi-prefix remapping
		if (!empty($this->restore_options['db']) && !empty($this->backup_set['db'])) {
			$db_files = $this->backup_set['db'];
			if (is_string($db_files)) $db_files = array($db_files);

			$this->progress("Importing database: " . count($prefix_map) . " subsite prefix mappings");
			$this->sgbackup->log("Batch prefix map: " . json_encode($prefix_map));

			$resolved_db_paths = array();
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

				$resolved_db_paths[] = $file_path;

				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_multi_remap(
					$file_path,
					$prefix_map,
					$base_prefix,
					function($lines_done) {
						$this->progress("Database import: {$lines_done} lines processed");
					}
				);

				$result['messages'][] = "Database: {$import_result['tables_restored']} tables imported across " . count($prefix_map) . " subsites";
				if (!empty($import_result['errors'])) {
					$result['errors'] = array_merge($result['errors'], $import_result['errors']);
				}
				if (!$import_result['success']) {
					$result['success'] = false;
				}
			}

			// Step 3: User merge for each subsite
			foreach ($target_map as $source_id => $target_id) {
				$this->progress("Merging users for subsite #{$source_id} -> #{$target_id}");
				$this->merge_subsite_users($source_id, $target_id, $backup_dir, $resolved_db_paths);
			}

			// Step 4: Per-subsite search-replace
			require_once SGBACKUP_DIR . 'includes/class-search-replace.php';

			foreach ($target_map as $source_id => $target_id) {
				$target_prefix = $base_prefix . $target_id . '_';
				$old_domain = isset($source_domains[$source_id]) ? $source_domains[$source_id] : '';
				$old_url_base = '';

				// If no source domain provided, read from imported options
				if (empty($old_domain)) {
					$imported_siteurl = $wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'siteurl'");
					if ($imported_siteurl) {
						$old_domain = parse_url($imported_siteurl, PHP_URL_HOST);
						$old_url_base = rtrim($imported_siteurl, '/');
					}
				}

				$target_blog = get_blog_details($target_id, false);
				if (!$target_blog) continue;

				$new_domain = $target_blog->domain;
				$target_path = $target_blog->path;
				$new_scheme = parse_url(get_option('siteurl'), PHP_URL_SCHEME);
				$target_url_base = $new_scheme . '://' . $new_domain . rtrim($target_path, '/');

				// Compare full URL bases to catch subdirectory-only path changes
				$needs_sr = !empty($old_domain) && $old_domain !== $new_domain;
				if (!$needs_sr && !empty($old_url_base) && $old_url_base !== $target_url_base) {
					$needs_sr = true;
					$this->sgbackup->log("Batch blog #{$target_id}: same domain but different path/scheme: {$old_url_base} -> {$target_url_base}");
				}

				if ($needs_sr) {
					$this->progress("Search-replace for blog #{$target_id}: {$old_domain} -> {$new_domain}" . ($target_path !== '/' ? $target_path : ''));

					$tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);
					$search = array();
					$replace = array();

					if (!empty($old_url_base)) {
						$old_url_https = preg_replace('/^https?/', 'https', $old_url_base);
						$old_url_http = preg_replace('/^https?/', 'http', $old_url_base);
						$search[] = $old_url_https;
						$replace[] = $target_url_base;
						$search[] = $old_url_http;
						$replace[] = $target_url_base;
					} else {
						$search[] = 'https://' . $old_domain;
						$replace[] = $target_url_base;
						$search[] = 'http://' . $old_domain;
						$replace[] = $target_url_base;
					}
					if ($old_domain !== $new_domain) {
						$search[] = $old_domain;
						$replace[] = $new_domain;
					}

					$sr = new SiteGuard_Backup_Search_Replace();
					$report = $sr->run($search, $replace, $tables);

					$this->sgbackup->log("Batch SR blog #{$target_id}: {$report['changes']} changes across {$report['tables']} tables");
					$this->progress("Search-replace blog #{$target_id}: {$report['changes']} changes");
					$result['messages'][] = "Search-replace blog #{$target_id}: {$report['changes']} changes ({$old_domain} -> {$new_domain}" . ($target_path !== '/' ? $target_path : '') . ")";
				}

				// Fix subsite options (siteurl, home)
				$new_siteurl = $target_url_base;
				$wpdb->update($target_prefix . 'options', array('option_value' => $new_siteurl), array('option_name' => 'siteurl'));
				$wpdb->update($target_prefix . 'options', array('option_value' => $new_siteurl), array('option_name' => 'home'));
				$this->sgbackup->log("Batch: fixed siteurl/home for blog #{$target_id} -> {$new_siteurl}");
			}
		}

		// Step 5: Upload files for each mapped subsite
		if (!empty($this->restore_options['uploads']) && !empty($this->backup_set['uploads'])) {
			foreach ($target_map as $source_id => $target_id) {
				$this->progress("Restoring uploads for subsite #{$source_id} -> #{$target_id}");
				$this->restore_subsite_uploads($backup_dir, $source_id, $target_id);
			}
			$result['messages'][] = "Uploads restored for " . count($target_map) . " subsites";
		}

		$result['messages'][] = "Batch subsite import complete: " . count($target_map) . " subsites imported";
		return $result;
	}

	/**
	 * Merge users from a subsite backup into the existing multisite user base.
	 * Matches by email - reuses existing users, inserts new ones.
	 *
	 * Capabilities are parsed from the SQL dump (wp_usermeta is skipped during
	 * remap import so they won't be in the live DB). For per-subsite backups
	 * where wp_usermeta is absent, users default to subscriber role.
	 *
	 * @param int    $source_id      Source blog ID.
	 * @param int    $target_id      Target blog ID.
	 * @param string $backup_dir     Path to backup directory.
	 * @param array  $resolved_paths Optional pre-resolved file paths (already decrypted). If empty, reads from backup_set['db'].
	 */
	private function merge_subsite_users($source_id, $target_id, $backup_dir, $resolved_paths = array()) {
		global $wpdb;

		// Use pre-resolved paths if provided (handles encrypted files that were already decrypted)
		if (!empty($resolved_paths)) {
			$file_paths = $resolved_paths;
		} else {
			$db_files = $this->backup_set['db'];
			if (is_string($db_files)) $db_files = array($db_files);
			$file_paths = array();
			foreach ($db_files as $db_file) {
				$file_path = $backup_dir . $db_file;
				if (preg_match('/\.crypt$/', $db_file)) {
					$decrypted = $this->decrypt_file($file_path);
					if ($decrypted) {
						$file_paths[] = $decrypted;
					} else {
						$this->sgbackup->log("User merge: failed to decrypt {$db_file}, skipping");
					}
				} else {
					$file_paths[] = $file_path;
				}
			}
		}

		$base_prefix = $wpdb->base_prefix;
		$users_table = $base_prefix . 'users';
		$usermeta_table = $base_prefix . 'usermeta';
		// blog_id 1 and single-site (0) both use wp_capabilities (no numeric prefix)
		$source_cap_key = ($source_id > 1) ? $base_prefix . $source_id . '_capabilities' : $base_prefix . 'capabilities';
		$target_cap_key = $base_prefix . $target_id . '_capabilities';

		// Read users and capabilities from the SQL dump.
		// wp_usermeta is skipped during remap import, so we parse caps directly
		// from the SQL file instead of querying the live database.
		$backup_users = array(); // email => array of user data
		$backup_caps = array();  // old_user_id => serialized capabilities string
		$escaped_cap_key = addslashes($source_cap_key);

		foreach ($file_paths as $file_path) {

			$is_gz = (substr($file_path, -3) === '.gz');
			$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
			if (!$fh) continue;

			$in_users_insert = false;
			$in_usermeta_insert = false;
			while (!($is_gz ? gzeof($fh) : feof($fh))) {
				$line = $is_gz ? gzgets($fh) : fgets($fh);
				if (false === $line) break;

				// Detect INSERT INTO wp_users
				if (preg_match("/INSERT INTO\s+`{$users_table}`/i", $line)) {
					$in_users_insert = true;
					$in_usermeta_insert = false;
				}
				// Detect INSERT INTO wp_usermeta
				if (preg_match("/INSERT INTO\s+`{$usermeta_table}`/i", $line)) {
					$in_usermeta_insert = true;
					$in_users_insert = false;
				}

				if ($in_users_insert) {
					// Extract user rows: (ID, login, pass, nicename, email, ...)
					// PHP dump quotes integers ('1', ...), mysqldump doesn't (1, ...)
					if (preg_match_all("/\(\s*'?(\d+)'?\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'/", $line, $matches, PREG_SET_ORDER)) {
						foreach ($matches as $m) {
							$backup_users[$m[5]] = array(
								'old_id' => intval($m[1]),
								'login'  => $m[2],
								'email'  => $m[5],
							);
						}
					}
					if (strpos($line, ';') !== false) {
						$in_users_insert = false;
					}
				}

				if ($in_usermeta_insert) {
					// Extract capability rows: (..., user_id, 'wp_capabilities', 'a:1:{...}')
					// Only parse lines that contain our target capability key
					if (strpos($line, $escaped_cap_key) !== false || strpos($line, $source_cap_key) !== false) {
						$cap_pattern = "/\(\s*'?\d+'?\s*,\s*'?(\d+)'?\s*,\s*'" . preg_quote($source_cap_key, '/') . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/";
						if (preg_match_all($cap_pattern, $line, $cap_matches, PREG_SET_ORDER)) {
							foreach ($cap_matches as $cm) {
								$backup_caps[intval($cm[1])] = stripslashes($cm[2]);
							}
						}
					}
					if (strpos($line, ';') !== false) {
						$in_usermeta_insert = false;
					}
				}
			}
			$is_gz ? @gzclose($fh) : @fclose($fh);
		}

		if (empty($backup_users)) {
			$this->sgbackup->log("Subsite user merge: no users found in backup");
			return;
		}

		$caps_found = count($backup_caps);
		$this->sgbackup->log("Subsite user merge: " . count($backup_users) . " users, {$caps_found} capability entries (key: {$source_cap_key})");
		if ($caps_found === 0) {
			$this->sgbackup->log("Subsite user merge: no capabilities found in SQL dump (per-subsite backups exclude wp_usermeta). Users will be added as subscribers.");
		}

		// Map old user IDs to new (existing or matching) user IDs
		$id_map = array();
		foreach ($backup_users as $email => $user_data) {
			$existing = get_user_by('email', $email);
			if ($existing) {
				$id_map[$user_data['old_id']] = $existing->ID;
			} else {
				$existing_by_login = get_user_by('login', $user_data['login']);
				if ($existing_by_login) {
					$id_map[$user_data['old_id']] = $existing_by_login->ID;
				}
				// If no match, user data was imported with the subsite tables;
				// we leave user references as-is (they may need manual resolution)
			}
		}

		// For users that exist, add them to the target subsite with their capabilities
		$target_prefix = $base_prefix . $target_id . '_';
		foreach ($id_map as $old_id => $new_id) {
			// Read capabilities from the parsed SQL dump data
			if (isset($backup_caps[$old_id])) {
				$caps = maybe_unserialize($backup_caps[$old_id]);
				if (is_array($caps)) {
					update_user_meta($new_id, $target_cap_key, $caps);
				} else {
					add_user_to_blog($target_id, $new_id, 'subscriber');
				}
			} else {
				// No capability data available - default to subscriber
				add_user_to_blog($target_id, $new_id, 'subscriber');
			}
		}

		// Update user IDs in subsite tables (e.g., post_author, comment_user_id)
		if (!empty($id_map)) {
			$target_posts = $target_prefix . 'posts';
			$target_comments = $target_prefix . 'comments';

			foreach ($id_map as $old_id => $new_id) {
				if ($old_id === $new_id) continue;

				// Check tables exist before updating
				if ($wpdb->get_var("SHOW TABLES LIKE '{$target_posts}'")) {
					$wpdb->update($target_posts, array('post_author' => $new_id), array('post_author' => $old_id));
				}
				if ($wpdb->get_var("SHOW TABLES LIKE '{$target_comments}'")) {
					$wpdb->update($target_comments, array('user_id' => $new_id), array('user_id' => $old_id));
				}
			}
			$this->sgbackup->log("Subsite user merge: remapped " . count($id_map) . " user IDs in subsite tables");
		}
	}

	/**
	 * Restore uploads for a subsite import, moving from /sites/{source}/ to /sites/{target}/.
	 */
	private function restore_subsite_uploads($backup_dir, $source_id, $target_id) {
		$upload_files = $this->backup_set['uploads'];
		if (is_string($upload_files)) $upload_files = array($upload_files);

		$opts = SiteGuard_Backup_Options::instance();
		$uploads_base = SiteGuard_Backup_Filesystem::wp_uploads_dir();
		$target_dir = trailingslashit($uploads_base) . 'sites/' . $target_id . '/';

		if (!file_exists($target_dir)) {
			wp_mkdir_p($target_dir);
		}

		foreach ($upload_files as $zip_file) {
			$zip_path = $backup_dir . $zip_file;
			if (!file_exists($zip_path)) continue;

			// Extract to a temp directory first
			$temp_extract = $backup_dir . 'temp_uploads_' . time() . '/';
			wp_mkdir_p($temp_extract);

			$extract_ok = $this->unzip($zip_path, $temp_extract);
			if (!$extract_ok) {
				$this->sgbackup->log("Failed to extract uploads zip: {$zip_file}", 'error');
				$this->remove_dir_recursive($temp_extract);
				continue;
			}

			// Find the actual files - might be in sites/{source_id}/ or root
			$source_subdir = $temp_extract . 'sites/' . $source_id . '/';
			$copy_from = is_dir($source_subdir) ? $source_subdir : $temp_extract;

			// Copy files to target directory
			$this->copy_dir_recursive($copy_from, $target_dir);

			$this->remove_dir_recursive($temp_extract);
			$this->sgbackup->log("Restored uploads from {$zip_file} to /sites/{$target_id}/");
		}

		return true;
	}

	/**
	 * Recursively copy a directory.
	 */
	private function copy_dir_recursive($source, $dest) {
		if (!is_dir($source)) return;
		if (!is_dir($dest)) wp_mkdir_p($dest);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($iterator as $item) {
			$target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
			if ($item->isDir() || is_dir($item->getPathname())) {
				if (!is_dir($target)) wp_mkdir_p($target);
			} else {
				@copy($item->getPathname(), $target);
			}
		}
	}

	/**
	 * Recursively remove a directory.
	 */
	private function remove_dir_recursive($dir) {
		if (!is_dir($dir)) return;
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($files as $file) {
			($file->isDir() || is_dir($file->getPathname())) ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($dir);
	}

	/**
	 * Parse wp_blogs INSERT statements from a SQL file.
	 *
	 * Returns an associative array of blog_id => domain for all subsites
	 * found in the SQL dump. Used to determine which tables belong to
	 * which subsites for filtered import.
	 *
	 * @param string $file_path Path to SQL file (may be .gz).
	 * @return array blog_id => domain
	 */
	private function parse_blogs_from_sql($file_path) {
		$is_gz = (substr($file_path, -3) === '.gz');
		$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
		if (!$fh) return array();

		$blogs = array();
		$in_blogs_insert = false;

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;
			$trimmed = trim($line);

			// Detect INSERT INTO wp_blogs (or wp_N_blogs with a prefix)
			if (preg_match('/^INSERT INTO\s+`[^`]*blogs`/i', $trimmed)) {
				$in_blogs_insert = true;
			}

			// Extract (blog_id, site_id, domain, ...) value tuples
			if ($in_blogs_insert) {
				// Match value rows like (2, 1, 'sub.example.com', ...) or ('2', '1', 'sub.example.com', ...)
				// PHP dump format quotes integers, mysqldump doesn't - handle both.
				if (preg_match_all('/\(\s*\'?(\d+)\'?\s*,\s*\'?\d+\'?\s*,\s*\'([^\']+)\'/', $trimmed, $matches, PREG_SET_ORDER)) {
					foreach ($matches as $m) {
						$blog_id = intval($m[1]);
						if ($blog_id > 1) {
							$blogs[$blog_id] = $m[2];
						}
					}
				}
				// End of INSERT statement
				if (substr($trimmed, -1) === ';') {
					$in_blogs_insert = false;
				}
			}
		}

		$is_gz ? gzclose($fh) : fclose($fh);
		$this->sgbackup->log("Parsed " . count($blogs) . " subsite blogs from SQL: " . implode(', ', array_map(function($id, $d) { return "#{$id}={$d}"; }, array_keys($blogs), $blogs)));
		return $blogs;
	}

	/**
	 * Compute which tables to import using a whitelist approach.
	 *
	 * Only imports tables for non-skipped subsites. When the main site
	 * (blog_id=1) is skipped, only multisite infrastructure tables (wp_blogs,
	 * wp_site, wp_sitemeta) are imported from the non-prefixed set - main site
	 * data (wp_options, wp_posts, etc.) and global user tables are excluded to
	 * prevent obliterating the destination's existing data.
	 *
	 * Also excludes orphaned wp_N_* tables from the source whose blog_ids
	 * aren't in wp_blogs - preventing them from overwriting destination data.
	 *
	 * @param array $all_tables   All table names from the SQL file.
	 * @param array $source_blogs blog_id => domain from the source SQL.
	 * @return array Table names to import.
	 */
	private function compute_selective_tables($all_tables, $source_blogs) {
		global $wpdb;
		$base_prefix = $wpdb->base_prefix;
		$prefix_pattern = '/^' . preg_quote($base_prefix, '/') . '(\d+)_/';

		// Build whitelist of blog_ids to IMPORT (non-skipped source subsites)
		$import_blog_ids = array();
		$skip_blog_ids = array();
		foreach ($source_blogs as $blog_id => $domain) {
			if (isset($this->subsite_map[$domain]) && $this->subsite_map[$domain] === self::SUBSITE_SKIP) {
				$skip_blog_ids[] = $blog_id;
			} else {
				$import_blog_ids[] = $blog_id;
			}
		}

		// Build prefix whitelist for imported blogs
		$import_prefixes = array();
		foreach ($import_blog_ids as $bid) {
			$import_prefixes[] = $base_prefix . $bid . '_';
		}

		// Determine if the main site (blog_id=1) is being skipped.
		// When skipped, we must NOT import main-site per-blog tables
		// (wp_options, wp_posts, etc.) or global user tables - only
		// multisite infrastructure tables needed for domain fixups.
		$main_site_skipped = false;
		if (!empty($this->old_siteurl)) {
			$old_domain = parse_url($this->old_siteurl, PHP_URL_HOST);
			if ($old_domain && isset($this->subsite_map[$old_domain]) && $this->subsite_map[$old_domain] === self::SUBSITE_SKIP) {
				$main_site_skipped = true;
			}
		}

		// Multisite infrastructure tables (without prefix) that must be
		// imported even when the main site is skipped - fix_multisite_sitemeta()
		// reads from these to perform domain remapping and blog re-insertion.
		$multisite_infra = array('blogs', 'blogmeta', 'site', 'sitemeta');

		$this->sgbackup->log("Selective import: importing blog_ids [" . implode(',', $import_blog_ids) . "], skipping blog_ids [" . implode(',', $skip_blog_ids) . "], main site skipped: " . ($main_site_skipped ? 'yes' : 'no'));

		return array_values(array_filter($all_tables, function($table) use ($prefix_pattern, $import_prefixes, $main_site_skipped, $base_prefix, $multisite_infra) {
			// Check if this is a blog-specific table (wp_N_*)
			if (preg_match($prefix_pattern, $table, $m)) {
				// Only include if this blog_id is in the import whitelist
				$table_prefix = $GLOBALS['wpdb']->base_prefix . $m[1] . '_';
				return in_array($table_prefix, $import_prefixes, true);
			}

			// Non-prefixed table (global or blog_id=1 per-site table)
			if ($main_site_skipped) {
				// Main site is skipped - only import multisite infrastructure
				// tables needed for fixups. Exclude main site data (wp_options,
				// wp_posts, etc.) and global tables (wp_users, wp_usermeta) to
				// prevent overwriting destination data.
				$base_name = substr($table, strlen($base_prefix));
				return in_array($base_name, $multisite_infra, true);
			}

			// Main site is being imported - include all non-prefixed tables
			return true;
		}));
	}

	/**
	 * Save destination's sgbackup_* options before DB import.
	 *
	 * On multisite, plugin options are split between wp_sitemeta (network-wide
	 * keys like sgbackup_migrator_localkeys) and wp_options (per-site options
	 * like sgbackup_backup_history, sgbackup_remotesites, etc.). Both must be
	 * saved to prevent the source's values from overwriting the destination's.
	 */
	private function save_plugin_options_before_import() {
		global $wpdb;

		if (is_multisite()) {
			// Save network-wide options from wp_sitemeta
			$sitemeta_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like('sgbackup_') . '%'
			), ARRAY_A);
			$sitemeta_count = 0;
			foreach ($sitemeta_rows as $row) {
				if (strpos($row['meta_key'], 'sgbackup_jobdata_') === 0) continue;
				if (strpos($row['meta_key'], 'sgbackup_sem_') === 0) continue;
				$this->pre_import_sgbackup_options['sitemeta:' . $row['meta_key']] = $row['meta_value'];
				$sitemeta_count++;
			}

			// Save per-site options from wp_options (main site)
			$options_rows = $wpdb->get_results($wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like('sgbackup_') . '%'
			), ARRAY_A);
			$options_count = 0;
			foreach ($options_rows as $row) {
				if (strpos($row['option_name'], 'sgbackup_jobdata_') === 0) continue;
				if (strpos($row['option_name'], 'sgbackup_sem_') === 0) continue;
				$this->pre_import_sgbackup_options['options:' . $row['option_name']] = array(
					'value' => $row['option_value'],
					'autoload' => $row['autoload'],
				);
				$options_count++;
			}

			if ($sitemeta_count > 0 || $options_count > 0) {
				$this->sgbackup->log("Saved {$sitemeta_count} wp_sitemeta + {$options_count} wp_options sgbackup_* options before import");
			}
		} else {
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like('sgbackup_') . '%'
			), ARRAY_A);
			foreach ($rows as $row) {
				if (strpos($row['option_name'], 'sgbackup_jobdata_') === 0) continue;
				if (strpos($row['option_name'], 'sgbackup_sem_') === 0) continue;
				$this->pre_import_sgbackup_options[$row['option_name']] = array(
					'value' => $row['option_value'],
					'autoload' => $row['autoload'],
				);
			}
			if (!empty($this->pre_import_sgbackup_options)) {
				$this->sgbackup->log("Saved " . count($this->pre_import_sgbackup_options) . " destination sgbackup_* options before import");
			}
		}
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
		$siteurl_from_main = false; // Whether siteurl came from the MAIN options table
		$home_from_main = false;    // Whether home came from the MAIN options table
		$in_options_insert = false; // Track multi-line INSERT INTO *options* context
		$in_main_options = false;   // Whether the current INSERT is for the main options table
		$has_main_options = false;
		$detected_subsite_id = 0;
		$bp = $GLOBALS['wpdb']->base_prefix;

		while (!($is_gz ? gzeof($fh) : feof($fh)) && $lines_scanned < $max_scan) {
			// Stop early once we have both URLs from the MAIN options table.
			// If we only have subsite values, keep scanning to find wp_options.
			if ($found_siteurl && $found_home && $siteurl_from_main && $home_from_main) break;
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$lines_scanned++;
			$trimmed = trim($line);

			// Track INSERT INTO *options* context for multi-line format.
			// Distinguish between main options (wp_options) and subsite options (wp_N_options).
			if (preg_match('/^INSERT INTO\s+`([^`]*options)`/i', $trimmed, $ins_m)) {
				$in_options_insert = true;
				$in_main_options = ($ins_m[1] === $bp . 'options');
			}
			// New CREATE/INSERT/DROP statement ends the multi-line context
			if ($in_options_insert && preg_match('/^(CREATE|DROP|INSERT INTO\s+`[^`]*(?<!options)`)/i', $trimmed)) {
				$in_options_insert = false;
				$in_main_options = false;
			}
			// Statement terminator on its own line ends context
			if ($in_options_insert && $trimmed === ';') {
				$in_options_insert = false;
				$in_main_options = false;
			}

			// Match siteurl/home: works for single-line mysqldump format AND
			// multi-line PHP dump format (value rows within options INSERT).
			// Prefer values from wp_options (main site) over wp_N_options (subsite).
			if ($in_options_insert || preg_match('/^INSERT INTO\s+`([^`]*options)`/i', $trimmed, $ins_m2)) {
				// Re-check if this specific INSERT line is for main options
				$is_main = $in_main_options;
				if (!$in_options_insert && isset($ins_m2[1])) {
					$is_main = ($ins_m2[1] === $bp . 'options');
				}

				if (preg_match("/'(siteurl|home)'\s*,\s*'([^']+)'/i", $trimmed, $m)) {
					// Accept value if: (a) not yet found, or (b) overriding subsite value with main
					if ($m[1] === 'siteurl' && (!$found_siteurl || ($is_main && !$siteurl_from_main))) {
						$this->old_siteurl = $m[2];
						$found_siteurl = true;
						if ($is_main) $siteurl_from_main = true;
					} elseif ($m[1] === 'home' && (!$found_home || ($is_main && !$home_from_main))) {
						$this->old_home = $m[2];
						$found_home = true;
						if ($is_main) $home_from_main = true;
					}
				}

				// Look for upload_url_path in same context
				if (preg_match("/'upload_url_path'\s*,\s*'([^']+)'/i", $trimmed, $m)) {
					$this->old_uploads = $m[1];
				}
			}

			// Detect table prefix from CREATE TABLE statements.
			// Prefer the main options table prefix over subsite prefixes.
			if (preg_match('/CREATE TABLE\s+`([a-zA-Z0-9_]+)options`/i', $trimmed, $m)) {
				$detected_prefix = str_replace('options', '', $m[1]);
				if (!empty($detected_prefix) && preg_match('/^[a-zA-Z0-9_]+$/', $detected_prefix)) {
					// Only set if it's the main options table or we haven't set yet.
					// $m[1] is just the prefix (e.g. 'wp_'), $bp is 'wp_'.
					$is_main_prefix = ($detected_prefix === $bp);
					if ($is_main_prefix || empty($this->old_table_prefix)) {
						$this->old_table_prefix = $detected_prefix;
					}
				}
			}

			// Track whether this is a subsite-only backup (no main options/blogs table)
			if (preg_match('/^CREATE TABLE\s+`([^`]+)`/i', $trimmed, $ctm)) {
				$tbl_name = $ctm[1];
				$bp = $GLOBALS['wpdb']->base_prefix;
				if ($tbl_name === $bp . 'options' || $tbl_name === $bp . 'blogs') {
					$has_main_options = true;
				} elseif (!$detected_subsite_id && preg_match('/^' . preg_quote($bp, '/') . '(\d+)_options$/', $tbl_name, $sid)) {
					$detected_subsite_id = (int) $sid[1];
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);

		// Auto-detect subsite backup when metadata is missing
		if (!$this->is_subsite_backup && !$has_main_options && $detected_subsite_id > 0) {
			$this->is_subsite_backup = true;
			$this->source_blog_id = $detected_subsite_id;
			$this->sgbackup->log("Auto-detected subsite backup: blog #{$detected_subsite_id} (no main options table in SQL)");
		}

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

		// Save the REAL destination URLs before any keep-protocol rewrite.
		// These are used for writing to wp_options - the site must respond
		// on its actual protocol regardless of keep_protocol.
		$real_dest_siteurl = $current_siteurl;
		$real_dest_home = $current_home;

		// Keep original protocol: rewrite destination URLs to use the source scheme
		// for SEARCH-REPLACE PAIRS ONLY. Does not affect DB identity writes.
		if ($this->keep_protocol && !empty($this->old_siteurl)) {
			$old_scheme = parse_url($this->old_siteurl, PHP_URL_SCHEME);
			$new_scheme = parse_url($current_siteurl, PHP_URL_SCHEME);
			if ($old_scheme && $new_scheme && $old_scheme !== $new_scheme) {
				$this->sgbackup->log("Keep-protocol: rewriting destination URLs from {$new_scheme} to {$old_scheme}");
				$current_siteurl = preg_replace('/^' . preg_quote($new_scheme, '/') . '/', $old_scheme, $current_siteurl);
				$current_home = preg_replace('/^' . preg_quote($new_scheme, '/') . '/', $old_scheme, $current_home);
				if ($current_content) $current_content = preg_replace('/^' . preg_quote($new_scheme, '/') . '/', $old_scheme, $current_content);
				if ($current_uploads) $current_uploads = preg_replace('/^' . preg_quote($new_scheme, '/') . '/', $old_scheme, $current_uploads);
			}
		}

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
			$sr_scheme = $this->keep_protocol ? parse_url($current_siteurl, PHP_URL_SCHEME) : null;
			$this->build_multisite_replacements($search, $replace, $sr_scheme);
		}

		// Write the REAL destination siteurl/home (before any keep-protocol rewrite).
		// The site must respond on its actual protocol regardless of keep_protocol.
		update_option('siteurl', $real_dest_siteurl);
		update_option('home', $real_dest_home);
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

		// Exclude user tables from search-replace during migrations to preserve
		// destination user logins, emails, and credentials
		if ($this->is_migration) {
			$user_tables = array($wpdb->users, $wpdb->usermeta);
			$tables = array_values(array_diff($tables, $user_tables));
			$this->sgbackup->log("Search-replace: excluding user tables (" . implode(', ', $user_tables) . ") to preserve destination accounts");
		}

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
	 * Map an independent domain to a subdomain of the target host.
	 *
	 * E.g. example.com → example.old-host.example
	 *      client-b.example → client-b.old-host.example
	 *
	 * @param string $domain The independent domain to remap
	 * @param string $target_host The target host (e.g. old-host.example)
	 * @return string The new subdomain
	 */
	private function map_independent_domain($domain, $target_host) {
		$parts = explode('.', $domain);
		array_pop($parts); // Remove TLD (.com, .net, etc.)
		$subdomain = implode('.', $parts);
		return $subdomain . '.' . $target_host;
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
	private function build_multisite_replacements(&$search, &$replace, $scheme_override = null) {
		global $wpdb;

		// Skip remap mode: don't modify search-replace pairs for subsites
		if ($this->subsite_mode === self::SUBSITE_MODE_SKIP_REMAP) {
			$this->sgbackup->log("Multisite migration: skip-remap mode, not building subsite replacements");
			return;
		}

		$old_domain = parse_url($this->old_siteurl, PHP_URL_HOST);
		$new_domain = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
		if (!$old_domain || !$new_domain || $old_domain === $new_domain) return;

		$new_scheme = $scheme_override ?: parse_url($this->pre_import_siteurl, PHP_URL_SCHEME);

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
			// Compute new blog domain: custom map lookup, skip check, mirror, or independent remap
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
			} elseif (strpos($blog_domain, $old_domain) !== false) {
				// Domain contains old domain - standard str_replace
				$new_blog_domain = str_replace($old_domain, $new_domain, $blog_domain);
			} elseif ($this->remap_independent_domains) {
				// Independent domain (e.g. kawaconnect.com) - map to subdomain of new domain
				$new_blog_domain = $this->map_independent_domain($blog_domain, $new_domain);
				$this->sgbackup->log("  Independent domain remap: {$blog_domain} -> {$new_blog_domain}");
			} else {
				continue; // Independent domain remapping disabled
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
					// Remove the source's skipped blog entry from wp_blogs
					$wpdb->delete($blogs_table, array('blog_id' => $blog->blog_id), array('%d'));

					if (!empty($this->pre_import_blogs)) {
						// Filtered import was used - tables at this blog_id belong to
						// the destination, NOT the source. Do NOT drop them.
						$this->sgbackup->log("  Skipped blog #{$blog->blog_id} ({$blog->domain}): deleted from wp_blogs (destination tables preserved)");
						$this->progress("Skipped subsite: {$blog->domain} (destination tables preserved)");
					} else {
						// Full atomic import was used - tables are the source's data.
						// Drop them since the user chose to skip this subsite.
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
					}
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
			} elseif ($this->remap_independent_domains) {
				// Skip if search-replace already remapped this domain (it's already a subdomain of new_domain)
				if ($blog->domain === $new_domain || substr($blog->domain, -(strlen($new_domain) + 1)) === '.' . $new_domain) {
					continue;
				}
				// Independent domain - map to subdomain of new domain
				$new_blog_domain = $this->map_independent_domain($blog->domain, $new_domain);
				$this->sgbackup->log("  Independent domain remap: {$blog->domain} -> {$new_blog_domain}");
			} else {
				continue; // Independent domain remapping disabled
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
					} elseif ($this->remap_independent_domains) {
						// Independent domain in option value - replace with new subdomain
						$new_val = str_replace($blog->domain, $new_blog_domain, $old_val);
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

		// Re-insert preserved destination wp_blogs entries whose blog_ids
		// are no longer present (they weren't in the source backup at all).
		if (!empty($this->pre_import_blogs)) {
			$existing_blog_ids = $wpdb->get_col("SELECT blog_id FROM {$blogs_table}");
			$reinserted = 0;
			foreach ($this->pre_import_blogs as $saved_blog) {
				if (in_array((string) $saved_blog['blog_id'], $existing_blog_ids, true)) continue;
				$inserted = $wpdb->insert($blogs_table, $saved_blog);
				if ($inserted) {
					$reinserted++;
					$this->sgbackup->log("  Re-inserted destination blog #{$saved_blog['blog_id']} ({$saved_blog['domain']})");
				} else {
					$this->sgbackup->log("  Could not re-insert blog #{$saved_blog['blog_id']} ({$saved_blog['domain']}): already exists or DB error");
				}
			}
			if ($reinserted > 0) {
				$this->sgbackup->log("Re-inserted {$reinserted} destination wp_blogs entries that were not in the source backup");
				$this->progress("Preserved {$reinserted} existing destination subsites");
			}
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
		if (!is_multisite()) return;
		global $wpdb;
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$removed = 0;
		$use_filtered_mode = !empty($this->pre_import_blogs);

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

			if ($use_filtered_mode) {
				// Filtered import was used - tables at this blog_id belong to
				// the destination, NOT the source. Do NOT drop them.
				$this->sgbackup->log("Skipped subsite #{$blog->blog_id} ({$domain}): removed from wp_blogs (destination tables preserved)");
				$this->progress("Skipped subsite: {$domain} (destination tables preserved)");
			} else {
				// Full atomic import - tables are the source's data. Drop them.
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
			}
			$removed++;
		}

		if ($removed > 0) {
			$this->sgbackup->log("apply_subsite_skips: removed {$removed} subsites");
		}

		// Re-insert preserved destination wp_blogs entries whose blog_ids
		// are no longer present (they weren't in the source backup at all,
		// or were deleted above because they matched a skip domain).
		if ($use_filtered_mode) {
			$existing_blog_ids = $wpdb->get_col("SELECT blog_id FROM {$blogs_table}");
			$reinserted = 0;
			foreach ($this->pre_import_blogs as $saved_blog) {
				if (in_array((string) $saved_blog['blog_id'], $existing_blog_ids, true)) continue;
				$inserted = $wpdb->insert($blogs_table, $saved_blog);
				if ($inserted) {
					$reinserted++;
					$this->sgbackup->log("  Re-inserted destination blog #{$saved_blog['blog_id']} ({$saved_blog['domain']})");
				}
			}
			if ($reinserted > 0) {
				$this->sgbackup->log("Re-inserted {$reinserted} preserved destination blog(s)");
				$this->progress("Preserved {$reinserted} destination subsite(s)");
			}
		}
	}

	/**
	 * Remove all subsites, keeping only the main site (blog_id=1).
	 *
	 * Called for same-site restores in SUBSITE_MODE_MAIN_ONLY when
	 * is_migration is false. During migrations, fix_multisite_sitemeta()
	 * handles this via its DELETE WHERE blog_id > 1 path.
	 */
	private function apply_main_only_mode() {
		if (!is_multisite()) return;
		global $wpdb;
		$blogs_table = $wpdb->base_prefix . 'blogs';

		// Get all subsite blog_ids before deleting
		$subsites = $wpdb->get_results("SELECT blog_id, domain FROM {$blogs_table} WHERE blog_id > 1");
		if (empty($subsites)) {
			$this->sgbackup->log("Main-only mode: no subsites to remove");
			return;
		}

		// Remove from wp_blogs
		$removed = $wpdb->query("DELETE FROM {$blogs_table} WHERE blog_id > 1");
		$this->sgbackup->log("Main-only mode: removed {$removed} subsite entries from wp_blogs");

		// Drop each subsite's tables
		$total_tables = 0;
		foreach ($subsites as $blog) {
			$blog_prefix = $wpdb->base_prefix . $blog->blog_id . '_';
			$subsite_tables = $wpdb->get_col($wpdb->prepare(
				"SHOW TABLES LIKE %s", $wpdb->esc_like($blog_prefix) . '%'
			));
			foreach ($subsite_tables as $table) {
				$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
			}
			$total_tables += count($subsite_tables);
		}

		$this->sgbackup->log("Main-only mode: dropped {$total_tables} subsite tables");
		$this->progress("Main-only mode: removed {$removed} subsites ({$total_tables} tables dropped)");
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

			// Exclude our own plugin directory during migration to prevent
			// overwriting destination's encryption keys, config, and version.
			if ($entity === 'plugins' && $this->is_migration) {
				$self_dir = $temp_dir . 'siteguardbackup';
				if (is_dir($self_dir)) {
					$this->remove_temp_dir($self_dir);
					$this->sgbackup->log("Migration: excluded siteguardbackup/ from plugin restore to preserve destination config");
					$this->progress("Excluded SiteGuard Backup plugin from restore (preserving destination config)");
				}
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

			if ($item->isDir() || is_dir($item->getPathname())) {
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
			if ($item->isDir() || is_dir($item->getPathname())) {
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

		// Restore destination's sgbackup_* options that were overwritten by the
		// source DB import (encryption keys, migration keys, backup history, etc.)
		$this->restore_plugin_options();

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
					$dcs_msg = "wp-config.php DOMAIN_CURRENT_SITE is '" . DOMAIN_CURRENT_SITE . "' but should be '{$new_domain}'. Update wp-config.php to fix network admin access.";
					$this->sgbackup->log("WARNING: {$dcs_msg}", 'warning');
					if (defined('DOING_AJAX') && DOING_AJAX) {
						echo 'RINFO:' . json_encode(array('type' => 'error', 'message' => $dcs_msg)) . "\n";
						if (ob_get_level()) @ob_flush();
						@flush();
					}
				}
			}

			// User tables are now preserved during migrations (wp_users/wp_usermeta
			// skipped in import), so destination credentials remain valid.
			$old_host = parse_url($this->old_siteurl, PHP_URL_HOST);
			$auth_msg = "Database restored from {$old_host}. Your login credentials have been preserved.";
			$this->sgbackup->log("[Restore] {$auth_msg}");
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array('type' => 'info', 'message' => $auth_msg)) . "\n";
				if (ob_get_level()) @ob_flush();
				@flush();
			}

			// Multisite subsite DNS/infrastructure warning
			if (is_multisite()) {
				global $wpdb;
				$blog_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->base_prefix}blogs WHERE blog_id > 1");
				if ($blog_count > 0) {
					$new_host = parse_url($this->pre_import_siteurl, PHP_URL_HOST);
					$dns_msg = "{$blog_count} subsites were migrated. They require wildcard DNS (*.{$new_host}) and web server configuration to accept subdomains before they can be accessed.";
					$this->sgbackup->log("[Restore] DNS NOTE: {$dns_msg}");
					if (defined('DOING_AJAX') && DOING_AJAX) {
						echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $dns_msg)) . "\n";
						if (ob_get_level()) @ob_flush();
						@flush();
					}
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
	 * Restore destination's sgbackup_* options that were saved before DB import.
	 *
	 * After a DB import from a different site, encryption keys, migration keys,
	 * backup history, remote storage credentials, and other plugin config from
	 * the source site overwrite the destination's values. This method writes
	 * the destination's original values back.
	 */
	private function restore_plugin_options() {
		if (empty($this->pre_import_sgbackup_options)) return;

		global $wpdb;
		$restored_sitemeta = 0;
		$restored_options = 0;

		if (is_multisite()) {
			// On multisite, keys are prefixed with 'sitemeta:' or 'options:'
			// to distinguish which table they came from.
			foreach ($this->pre_import_sgbackup_options as $prefixed_key => $saved) {
				if (strpos($prefixed_key, 'sitemeta:') === 0) {
					$key = substr($prefixed_key, 9); // Remove 'sitemeta:' prefix
					$exists = $wpdb->get_var($wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key = %s", $key
					));
					if ($exists) {
						$wpdb->update(
							$wpdb->sitemeta,
							array('meta_value' => $saved),
							array('meta_key' => $key),
							array('%s'),
							array('%s')
						);
					} else {
						$wpdb->insert(
							$wpdb->sitemeta,
							array('site_id' => 1, 'meta_key' => $key, 'meta_value' => $saved),
							array('%d', '%s', '%s')
						);
					}
					$restored_sitemeta++;
				} elseif (strpos($prefixed_key, 'options:') === 0) {
					$key = substr($prefixed_key, 8); // Remove 'options:' prefix
					$opt_value = $saved['value'];
					$opt_autoload = $saved['autoload'];
					$exists = $wpdb->get_var($wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $key
					));
					if ($exists) {
						$wpdb->update(
							$wpdb->options,
							array('option_value' => $opt_value, 'autoload' => $opt_autoload),
							array('option_name' => $key),
							array('%s', '%s'),
							array('%s')
						);
					} else {
						$wpdb->insert(
							$wpdb->options,
							array('option_name' => $key, 'option_value' => $opt_value, 'autoload' => $opt_autoload),
							array('%s', '%s', '%s')
						);
					}
					$restored_options++;
				}
			}
			$total = $restored_sitemeta + $restored_options;
			$this->sgbackup->log("Restored {$total} destination sgbackup_* options ({$restored_sitemeta} sitemeta + {$restored_options} wp_options)");
			$this->progress("Restored {$total} plugin config options (encryption keys, backup history, etc.)");
		} else {
			foreach ($this->pre_import_sgbackup_options as $key => $saved) {
				$opt_value = $saved['value'];
				$opt_autoload = $saved['autoload'];
				$exists = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $key
				));
				if ($exists) {
					$wpdb->update(
						$wpdb->options,
						array('option_value' => $opt_value, 'autoload' => $opt_autoload),
						array('option_name' => $key),
						array('%s', '%s'),
						array('%s')
					);
				} else {
					$wpdb->insert(
						$wpdb->options,
						array('option_name' => $key, 'option_value' => $opt_value, 'autoload' => $opt_autoload),
						array('%s', '%s', '%s')
					);
				}
				$restored_options++;
			}
			$this->sgbackup->log("Restored {$restored_options} destination sgbackup_* options after DB import");
			$this->progress("Restored {$restored_options} plugin config options (encryption keys, backup history, etc.)");
		}
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
				clearstatcache(true, $table_file);
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

			clearstatcache(true, $db_path);
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
