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

	/** Option holding the destination's SiteGuard rows from the import until cleanup,
	 *  so a resumed or re-run restore writes back the same rows as an uninterrupted one. */
	const PRESERVED_OPTION = 'sgbackup_restore_preserved';

	/** Semaphore rows (SiteGuard_Backup_Semaphore) besides the sgbackup_sem_* ones.
	 *  They are live process state, never settings to save or write back. */
	const LOCK_ROWS = array('sgbackup_restore', 'sgbackup_lock', 'sgbackup_boot', 'sgbackup_history');

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

	/** @var float Epoch (seconds) when this request's restore run began; used to
	 *  derive the per-request search-replace deadline so a browser/proxy timeout
	 *  can't kill an un-checkpointed pass. */
	private $restore_started_at = 0;

	/** @var string Option name persisting the search-replace pairs + pre-SR state
	 *  so an interrupted migration search-replace resumes across requests. */
	private $sr_state_option = 'sgbackup_restore_sr_state';

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

	/** @var array blog id => that subsite's own SiteGuard option rows before a network import. */
	private $pre_import_subsite_sg = array();

	/** @var bool A whole-network database import ran (every subsite's tables came from the source). */
	private $network_import = false;

	/** @var array Blog domains as imported from the source (before identity fixes). */
	private $source_blog_domains = array();

	/** @var bool The filtered (skip-map) import ran, so skipped blog ids hold DESTINATION tables. */
	private $filtered_import_used = false;

	/** @var array Destination sgbackup_* options saved before DB import */
	private $pre_import_sgbackup_options = array();

	/** @var string Whose health monitor key the restored site keeps: 'keep' (this
	 *  site's) or 'backup'. See SiteGuard_Backup_Health_Key_Policy. */
	private $health_key_policy = 'keep';

	/** @var bool The health key policy was decided for this restore (a DB import
	 *  reached the main site), in this run or the interrupted one it resumes. */
	private $health_applied = false;

	/** @var string|null Outcome of that decision (kept_site, took_backup, ...). */
	private $health_outcome = null;

	/** @var array This site's health rows taken out of the snapshot (snapshot keys),
	 *  kept so a re-run of the import can decide again. */
	private $health_dest = array();

	/** @var bool Whether this backup is a per-subsite backup */
	private $is_subsite_backup = false;

	/** @var int Source blog ID from the subsite backup */
	private $source_blog_id = 0;

	/** @var string Target domain for subsite import */
	private $target_subsite_domain = '';

	/** @var int Target blog ID for subsite import (unambiguous for subdirectory installs) */
	private $target_subsite_id = 0;

	/** @var array Plaintext files produced by decrypt_file(), unlinked on cleanup */
	private $decrypted_temp_files = array();

	/** @var SiteGuard_Backup_Semaphore|null Restore concurrency lock */
	private $restore_lock = null;

	/** @var int Number of files move_files() failed to move on its last run */
	private $last_move_failures = 0;

	/** @var array Memoized get_tables_in_sql_file() results, keyed by resolved file path */
	private $tables_in_sql_cache = array();

	/** @var array Files already scanned by scan_db_for_old_urls(), keyed by path */
	private $scanned_url_files = array();

	/** @var bool Whether source user tables were actually skipped during DB import */
	private $user_tables_skipped = false;

	/** @var array entity => top-level names moved in from the backup (see move_files()). */
	private $restored_top_items = array();

	/** @var bool Uploads come from a subsite's sites/N/ tree and go to the uploads root. */
	private $subsite_to_single_uploads = false;

	/** @var array|null Source plugins not yet on disk when a subsite import set active_plugins. */
	private $subsite_plugin_intent = null;

	/** @var array How this plugin was activated before the restore (restored afterwards). */
	private $self_activation = array();

	/**
	 * @param array $backup_set     Backup set from history.
	 * @param array $restore_options What to restore: ['db' => bool, 'plugins' => bool, etc.]
	 */
	public function __construct($backup_set, $restore_options = array()) {
		if (!function_exists('is_plugin_active_for_network')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$self = plugin_basename(SGBACKUP_DIR . 'siteguardbackup.php');
		$this->self_activation = array(
			'basename' => $self,
			'network'  => is_multisite() && is_plugin_active_for_network($self),
			'site'     => in_array($self, (array) get_option('active_plugins', array()), true),
		);
		$this->sgbackup = SiteGuard_Backup::instance();
		$this->backup_set = $backup_set;
		$this->restore_options = $restore_options;
		$this->import_table_prefix = $GLOBALS['wpdb']->prefix;

		// History entries are keyed by timestamp and do not carry it. Pin it now,
		// while history is still this site's: after the DB import the nonce lookup
		// reads the backup's history, fails, and progress and the search-replace
		// checkpoint were keyed to 0 (which another backup's restore then reused).
		if (empty($this->backup_set['timestamp'])) {
			$ts = $this->restore_timestamp();
			if ($ts) $this->backup_set['timestamp'] = $ts;
		}

		// Health monitor key policy - per restore, else the site default, else keep.
		// Written back into the options so the progress record carries it and a
		// resume never re-reads the default from the (imported) database.
		require_once SGBACKUP_DIR . 'includes/class-health-key-policy.php';
		$policy = isset($restore_options['health_key_policy']) ? SiteGuard_Backup_Health_Key_Policy::sanitize($restore_options['health_key_policy']) : null;
		if (null === $policy) {
			$policy = SiteGuard_Backup_Health_Key_Policy::sanitize(SiteGuard_Backup_Options::instance()->get_option('sgbackup_default_health_key_policy', SiteGuard_Backup_Health_Key_Policy::KEEP));
		}
		$this->health_key_policy = $policy ? $policy : SiteGuard_Backup_Health_Key_Policy::KEEP;
		$this->restore_options['health_key_policy'] = $this->health_key_policy;

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
		$this->restore_started_at = time();
		// WP-CLI / cron restores run at php.ini's memory_limit; lift it the same
		// way wp-admin does (capped to half the host's RAM - see raise_memory_limit).
		$this->sgbackup->raise_memory_limit();

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		$result = array('success' => true, 'messages' => array(), 'errors' => array());

		$this->progress("Starting restore");

		// Open log
		$nonce = isset($this->backup_set['nonce']) ? $this->backup_set['nonce'] : 'restore_' . time();
		$this->sgbackup->nonce = 'restore_' . substr(wp_generate_password(8, false), 0, 8);
		$this->sgbackup->logfile_open($this->sgbackup->nonce);

		// Concurrency guard: only one restore may run at a time. A second restore
		// (e.g. the JS double-submit bug, or an impatient re-click) would race the
		// first over the same tables/files and corrupt the result. Hold the lock
		// for the whole run; it auto-expires so a crashed restore can be resumed.
		$this->restore_lock = new SiteGuard_Backup_Semaphore('restore', 3600);
		if (!$this->restore_lock->lock()) {
			$busy_msg = "Another restore is already running. Refusing to start a second restore concurrently. Wait for it to finish (or up to an hour for a stalled one to time out) before retrying.";
			$this->progress($busy_msg);
			$this->sgbackup->log("[Restore] {$busy_msg}", 'error');
			$this->restore_lock = null; // we do not hold it; nothing to release
			$result['success'] = false;
			$result['errors'][] = $busy_msg;
			$this->end_restore(); $this->sgbackup->logfile_close();
			return $result;
		}

		// Resume support: if a previous restore of THIS backup was interrupted
		// (browser/proxy timeout, crash), its progress transient still records which
		// entities finished. Skip those instead of re-running everything - the
		// re-run loop is what makes migration restores appear to "hang".
		$completed_phases = $this->get_completed_phases();
		if (!empty($completed_phases)) {
			$this->progress("Resuming restore - already completed: " . implode(', ', $completed_phases));
			$this->sgbackup->log("[Restore] Resume detected; skipping completed phases: " . implode(', ', $completed_phases));
			// The database was imported by the interrupted run: this site's own
			// SiteGuard rows now exist only in the snapshot it saved after the import.
			if (array_intersect(array('db', 'db_import'), $completed_phases) && $this->load_preserved_state()) {
				$this->sgbackup->log("[Restore] Loaded this site's SiteGuard settings saved by the interrupted run");
			}
		}

		// Phase 0: Create pre-restore backup if requested. It is the rollback point,
		// so it must be complete: if it fails, nothing is restored. On a resume the
		// first run already made it; a second one would snapshot the half-restored
		// site as another "always keep" set.
		if (!empty($this->restore_options['pre_restore_backup'])) {
			if (!empty($completed_phases)) {
				$this->progress("Pre-restore backup was taken by the interrupted run - not repeating it");
			} else {
				$this->progress("Creating pre-restore backup");
				$pre = $this->create_pre_restore_backup();
				if (true !== $pre) {
					$msg = 'Pre-restore backup failed (' . (is_wp_error($pre) ? $pre->get_error_message() : 'unknown error') . '); nothing was restored.';
					$this->progress($msg);
					$result['success'] = false;
					$result['errors'][] = $msg;
					$this->end_restore(); $this->sgbackup->logfile_close();
					return $result;
				}
			}
		}

		// Phase 1: Verify all required files are present and validate integrity
		$entities_to_restore = $this->get_entities_to_restore();
		$files_result = $this->ensure_files_present($entities_to_restore, $backup_dir);
		if (is_wp_error($files_result)) {
			$result['success'] = false;
			$result['errors'][] = $files_result->get_error_message();
			$this->end_restore(); $this->sgbackup->logfile_close();
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
				$this->end_restore(); $this->sgbackup->logfile_close();
				return $result;
			}
			$this->progress("Integrity check failures overridden by skip_integrity_check option");
		}

		// Phase 2: Restore each entity

		// Pre-scan SQL to detect subsite backup when metadata is missing.
		// Must run before the guard below to catch subsite backups early.
		if (!$this->is_subsite_backup && !empty($this->backup_set['db'])) {
			$db_files = $this->backup_set['db'];
			if (is_string($db_files)) $db_files = array($db_files);
			$first_file = $backup_dir . $db_files[0];
			if (!preg_match('/\.crypt$/', $first_file)) {
				$scan_tables = $this->cached_tables_in_sql_file($first_file);
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
			$this->end_restore(); $this->sgbackup->logfile_close();
			return $result;
		}

		// A multisite subsite's backup restored onto a SINGLE site: convert it into
		// that site (wp_N_* -> wp_*). The standard path refuses the prefix mismatch.
		if ($this->is_subsite_backup && !is_multisite()) {
			$this->progress("Restoring a multisite subsite backup into this single site");
			$conv = $this->restore_subsite_into_single($backup_dir);
			$result['messages'] = array_merge($result['messages'], $conv['messages']);
			if (!$conv['success']) {
				$result['errors'] = array_merge($result['errors'], $conv['errors']);
				$result['success'] = false;
				$this->end_restore(); $this->sgbackup->logfile_close();
				return $result;
			}
			// Database handled; files follow through the standard entity loop, with
			// uploads taken from the subsite's sites/N/ tree into the uploads root.
			$this->restore_options['db'] = false;
			$this->subsite_to_single_uploads = true;
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

			// Shared network entities (plugins/themes/others/wpcore) live at the
			// network level, not per-subsite. These are opt-in with a destructive
			// warning in the UI (default OFF) because a careless extract clobbers
			// what every other subsite on the network is running.
			//
			// Policy when opted in: force "skip existing" (MOVEIN_SKIP_EXISTING)
			// so we only add items from the backup that don't already exist on
			// the destination. That matches the common need ("restore subsite
			// also installs the plugins the subsite relied on") while preserving
			// destination versions of everything that's already there.
			//
			// wpcore is deliberately excluded even when opted in - restoring
			// WordPress core files over a live network is almost never what an
			// admin wants from a subsite import.
			$shared_entities   = array('plugins', 'themes', 'others');
			$original_mode     = $this->move_in_mode;
			$original_migration= $this->is_migration;
			foreach ($shared_entities as $entity) {
				if (empty($this->restore_options[$entity]) || empty($this->backup_set[$entity])) continue;

				// Clamp to skip-existing so we don't overwrite what's already
				// installed; admins can still pick merge/overwrite in non-subsite
				// restore paths.
				$this->move_in_mode = self::MOVEIN_SKIP_EXISTING;
				// Don't let restore_file_entity's migration-special-case logic
				// (which removes this plugin's own directory) fire here - this is
				// a subsite import, not a cross-domain migration of the host.
				$this->is_migration = false;

				$warn_msg = ucfirst($entity) . " restore in subsite-import mode: installing only items NOT already present on this network (mode=skip-existing). Destination versions of existing " . $entity . " are preserved.";
				$this->sgbackup->log("[Restore] {$warn_msg}");
				if (defined('DOING_AJAX') && DOING_AJAX) {
					echo 'RINFO:' . json_encode(array('type' => 'info', 'message' => $warn_msg)) . "\n";
					if (ob_get_level()) @ob_flush();
					@flush();
				}

				$ok = $this->restore_file_entity($entity, $backup_dir);
				if ($ok) {
					$result['messages'][] = ucfirst($entity) . " extracted (missing items only - existing preserved)";
				} else {
					$result['errors'][] = "Failed to extract {$entity}";
				}
			}
			$this->move_in_mode  = $original_mode;
			$this->is_migration  = $original_migration;
			$this->activate_extracted_subsite_plugins();

			// wpcore in subsite mode is always refused.
			if (!empty($this->restore_options['wpcore']) && !empty($this->backup_set['wpcore'])) {
				$skip_msg = "WordPress Core restore skipped: refusing to overwrite core files from a per-subsite backup. Run a full-network restore if that's intended.";
				$this->sgbackup->log("[Restore] {$skip_msg}", 'warning');
				if (defined('DOING_AJAX') && DOING_AJAX) {
					echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $skip_msg)) . "\n";
					if (ob_get_level()) @ob_flush();
					@flush();
				}
			}

			// Phase 3: Post-restore (shared across all restore paths - temp-dir
			// pruning and validation live in post_restore_cleanup so every
			// restore flow gets them).
			$this->post_restore_cleanup();
			$result['messages'] = array_merge($this->progress, $result['messages']);
			$this->end_restore(); $this->sgbackup->logfile_close();
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
			$this->end_restore(); $this->sgbackup->logfile_close();
			return $result;
		}

		// Restore database first (if requested)
		if (!empty($this->restore_options['db']) && !empty($this->backup_set['db']) && in_array('db', $completed_phases, true)) {
			$this->progress("Database already restored in a previous run - skipping (resume)");
			$this->sgbackup->log("[Restore] Skipping database import: already completed (resume)");
			$result['messages'][] = "Database: skipped (already restored in interrupted run)";
		} elseif (!empty($this->restore_options['db']) && !empty($this->backup_set['db'])) {
			@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
			$this->progress("Restoring database");
			$db_result = $this->restore_database($backup_dir);
			if (!$db_result['success']) {
				$result['errors'] = array_merge($result['errors'], $db_result['errors']);
				$result['success'] = false;
				// Do not go on to overwrite files for a database that was not restored:
				// that left a half-restored site that reported success.
				if ($this->db_restore_mode !== self::DB_RESTORE_SKIP_DB) {
					$this->progress("Database restore failed; files were not touched.");
					$this->end_restore(); $this->sgbackup->logfile_close();
					return $result;
				}
			}
			if ($this->db_restore_mode === self::DB_RESTORE_SKIP_DB) {
				$result['messages'][] = "Database: skipped (user selected skip-db mode)";
			} else {
				$result['messages'][] = "Database: {$db_result['tables_restored']} tables restored";
			}

			// Browser-mode search-replace resume: the DB was imported but the
			// migration search-replace ran out of this request's time budget and
			// checkpointed. Return an 'incomplete' result so the caller resumes
			// (continuing SR from the saved table cursor) instead of re-importing.
			// File entities are deferred until the DB phase fully completes so a
			// resume always continues exactly one phase.
			if (!empty($db_result['sr_incomplete'])) {
				$result['incomplete'] = true;
				$result['status'] = 'incomplete';
				$result['messages'][] = "Search-replace in progress; the restore will continue automatically.";
				$this->progress("Search-replace paused (time budget reached); resume to continue.");
				$this->end_restore();
				$this->sgbackup->logfile_close();
				return $result;
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

			// Resume: a file entity fully moved in a previous run is safe to skip.
			if (in_array($entity, $completed_phases, true)) {
				$this->progress(ucfirst($entity) . " already restored in a previous run - skipping (resume)");
				$this->sgbackup->log("[Restore] Skipping {$entity}: already completed (resume)");
				$result['messages'][] = ucfirst($entity) . " skipped (already restored in interrupted run)";
				continue;
			}

			@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
			$this->progress("Restoring {$entity}");
			if ('uploads' === $entity && $this->subsite_to_single_uploads) {
				$entity_result = $this->restore_subsite_uploads($backup_dir, (int) $this->source_blog_id, 1);
			} else {
				$entity_result = $this->restore_file_entity($entity, $backup_dir);
			}
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
		$result['status'] = 'complete';
		$this->end_restore(); $this->sgbackup->logfile_close();

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
	 * Read the restore-progress transient and return the list of entities/phases
	 * already marked 'completed' for THIS backup, so a re-run (after a browser or
	 * proxy timeout) can skip them instead of re-importing everything.
	 *
	 * Conservative: only returns completions whose transient timestamp matches
	 * this backup, so an intentional restore of a different backup never inherits
	 * a stale completion.
	 *
	 * @return array Entity keys already completed (e.g. ['db', 'plugins']).
	 */
	/**
	 * Resolve the timestamp identifying the backup being restored (from the set,
	 * else looked up by nonce in history). Used to scope resume state to this
	 * specific backup so a stale checkpoint from a different one is never reused.
	 */
	private function restore_timestamp() {
		$this_ts = isset($this->backup_set['timestamp']) ? $this->backup_set['timestamp'] : 0;
		if (!$this_ts) {
			$nonce = isset($this->backup_set['nonce']) ? $this->backup_set['nonce'] : '';
			if ($nonce) {
				$history = SiteGuard_Backup_History::get_history();
				foreach ($history as $ts => $set) {
					if (isset($set['nonce']) && $set['nonce'] === $nonce) { $this_ts = $ts; break; }
				}
			}
		}
		return $this_ts;
	}

	private function get_completed_phases() {
		$opts = SiteGuard_Backup_Options::instance();
		$progress = $opts->get_transient('sgbackup_restore_progress');
		if (empty($progress) || !is_array($progress) || empty($progress['entities'])) {
			return array();
		}

		// A record without a timestamp (written by 1.5.1 when the lookup failed)
		// cannot be matched to this backup: never let it skip phases here.
		$this_ts = $this->restore_timestamp();
		if ($this_ts && (empty($progress['timestamp']) || (string) $progress['timestamp'] !== (string) $this_ts)) {
			return array();
		}

		$completed = array();
		foreach ($progress['entities'] as $entity => $status) {
			if ($status === 'completed') $completed[] = $entity;
		}
		return $completed;
	}

	/**
	 * End-of-restore housekeeping run on EVERY exit path: unlink any plaintext
	 * files left by decrypt_file() and release the restore concurrency lock.
	 */
	private function end_restore() {
		$this->cleanup_decrypted_temp_files();
		$this->release_restore_lock();
	}

	/**
	 * Release the restore concurrency lock if we hold it. Idempotent.
	 */
	private function release_restore_lock() {
		if ($this->restore_lock) {
			$this->restore_lock->unlock();
			$this->restore_lock = null;
		}
	}

	/**
	 * Unlink plaintext files produced by decrypt_file(). These are sibling files
	 * written next to the encrypted .crypt archive; leaving them behind leaks the
	 * decrypted contents and fills the disk. Idempotent.
	 */
	private function cleanup_decrypted_temp_files() {
		if (empty($this->decrypted_temp_files)) return;
		foreach ($this->decrypted_temp_files as $f) {
			if (is_string($f) && $f !== '' && file_exists($f)) {
				@unlink($f);
				$this->sgbackup->log("Removed decrypted temp file: {$f}");
			}
		}
		$this->decrypted_temp_files = array();
	}

	/**
	 * Memoized wrapper around get_tables_in_sql_file(). The standard restore path
	 * can otherwise decompress the same .sql.gz up to six times (pre-scan,
	 * tables-only, filtered import, user-skip, prefix detection). Cache per
	 * resolved path for the life of this restore run only.
	 *
	 * @param string $file_path Resolved (already-decrypted) SQL file path.
	 * @return array Table names.
	 */
	private function cached_tables_in_sql_file($file_path) {
		if (isset($this->tables_in_sql_cache[$file_path])) {
			return $this->tables_in_sql_cache[$file_path];
		}
		$tables = SiteGuard_Backup_Database_Utility::get_tables_in_sql_file($file_path);
		$this->tables_in_sql_cache[$file_path] = $tables;
		return $tables;
	}

	/**
	 * Focused, uncapped scan for the SOURCE main-site siteurl.
	 *
	 * scan_db_for_old_urls() caps at 200k lines to bound cost; on comment-heavy
	 * dumps the wp_options INSERT can sit past that cap, so migration goes
	 * undetected and the user-table-skip decision wrongly imports source users
	 * (locking the admin out). This walks the whole file but does almost no work
	 * per line - only cheap context tracking plus one regex when a line clearly
	 * carries a siteurl value in the MAIN options table.
	 *
	 * @param string $file_path
	 * @return string Source siteurl, or '' if not found.
	 */
	private function find_source_main_siteurl($file_path) {
		$is_gz = (substr($file_path, -3) === '.gz');
		$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
		if (!$fh) return '';

		$bp = $GLOBALS['wpdb']->base_prefix;
		$in_main_options = false;
		$found = '';

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh, 1048576) : fgets($fh, 1048576);
			if (false === $line) break;
			$line_capped = (strlen($line) >= 1048575 && substr($line, -1) !== "\n");

			if (preg_match('/^\s*INSERT INTO\s+`([^`]*options)`/i', $line, $im)) {
				$in_main_options = ($im[1] === $bp . 'options');
			} elseif (preg_match('/^\s*(CREATE|DROP|INSERT INTO)\b/i', $line)) {
				// A new statement that is not a main-options INSERT ends the context.
				if (!preg_match('/^\s*INSERT INTO\s+`' . preg_quote($bp . 'options', '/') . '`/i', $line)) {
					$in_main_options = false;
				}
			}

			if (($in_main_options || stripos($line, $bp . 'options') !== false) && !$line_capped) {
				if (preg_match("/'siteurl'\s*,\s*'([^']+)'/i", $line, $m)) {
					$found = $m[1];
					break;
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);
		return $found;
	}

	/**
	 * Compute the total uncompressed size of a zip archive by summing per-entry
	 * sizes. Returns -1 when the size can't be determined (no ZipArchive / open
	 * failure) so callers can skip the free-space precheck rather than block.
	 *
	 * @param string $zip_path
	 * @return int Uncompressed bytes, or -1 if unknown.
	 */
	private function zip_uncompressed_size($zip_path) {
		if (!class_exists('ZipArchive')) return -1;
		$zip = new ZipArchive();
		if (true !== $zip->open($zip_path)) return -1;
		$total = 0;
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$st = $zip->statIndex($i);
			if (is_array($st) && isset($st['size'])) $total += (int) $st['size'];
		}
		$zip->close();
		return $total;
	}

	/**
	 * Move a directory tree into $dest, preferring rename() (instant, no extra
	 * disk) and falling back to copy+unlink across filesystems. Used for subsite
	 * upload extraction so we don't need a second full copy of multi-GB uploads.
	 */
	private function move_dir_recursive($source, $dest) {
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
				if (file_exists($target)) @unlink($target);
				if (!@rename($item->getPathname(), $target)) {
					if (@copy($item->getPathname(), $target)) {
						@unlink($item->getPathname());
					}
				}
			}
		}
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
			// A WP_Error object is truthy - it used to pass this check and a
			// truncated partial download was restored as if complete.
			if (is_wp_error($result)) {
				$this->sgbackup->log("Download of {$file} from {$service} failed: " . $result->get_error_message(), 'error');
				@unlink($backup_dir . $file);
				continue;
			}
			if ($result === true && file_exists($backup_dir . $file)) {
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

		// Resume: the DB was already imported in a prior request but the migration
		// search-replace was interrupted (checkpointed). Skip the whole import and
		// continue search-replace from its saved table cursor. Detected by the
		// 'db_import' sub-phase marker being set while 'db' is not yet complete.
		$completed_phases = $this->get_completed_phases();
		$sr_resumable = false;
		if (in_array('db_import', $completed_phases, true) && !in_array('db', $completed_phases, true)) {
			// Without this backup's checkpoint (the run died between the import and
			// the first search-replace pass) there is nothing to continue from: a
			// "resume" rebuilt the pairs from empty URLs and silently replaced
			// nothing. Import again instead; the destination snapshot is reused.
			$sr_state = SiteGuard_Backup_Options::instance()->get_option($this->sr_state_option, null);
			$sr_resumable = is_array($sr_state) && !empty($sr_state['search']) && !empty($sr_state['tables'])
				&& isset($sr_state['timestamp']) && (string) $sr_state['timestamp'] === (string) $this->restore_timestamp();
			if (!$sr_resumable) {
				$this->sgbackup->log("[Restore] No search-replace checkpoint from the interrupted run; importing the database again", 'warning');
				$this->progress("No search-replace checkpoint found; importing the database again");
			}
		}
		if ($sr_resumable) {
			$this->sgbackup->log("[Restore] Resuming search-replace (DB import already completed in a prior run)");
			$this->progress("Resuming search-replace from checkpoint");
			$sr_status = $this->do_search_replace();
			if ($sr_status === 'incomplete') {
				$result['sr_incomplete'] = true;
				return $result;
			}
			$this->save_restore_progress('db', 'completed');
			return $result;
		}

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

		// An earlier run of this backup died after its import: the SiteGuard rows in
		// the database now are the backup's, and this site's own are in the snapshot
		// that run saved. Re-reading them here would write the backup's rows back.
		$preserved = $this->read_preserved_state();
		if ($preserved && isset($preserved['status']) && 'in_progress' === $preserved['status']) {
			$this->load_preserved_state($preserved, true);
			$reuse_msg = "Reusing destination settings saved by the interrupted run at " . gmdate('Y-m-d H:i:s', (int) $preserved['saved_at']) . " UTC";
			$this->sgbackup->log("[Restore] {$reuse_msg}");
			$this->progress($reuse_msg);
		} else {
			// Any other snapshot belongs to a different or abandoned restore.
			SiteGuard_Backup_Options::instance()->delete_option(self::PRESERVED_OPTION);

			// Save destination's sgbackup_* options before DB import.
			// After import these get overwritten with source values; we restore them in post_restore_cleanup().
			$this->save_plugin_options_before_import();
			// Subsites keep their own SiteGuard state too (history, settings, scheduled
			// events); save_plugin_options_before_import() only covers the main site.
			if (is_multisite()) {
				global $wpdb;
				$this->network_import = true;
				foreach ((array) get_sites(array('fields' => 'ids', 'number' => 0)) as $bid) {
					if ((int) $bid === 1) continue;
					$this->pre_import_subsite_sg[(int) $bid] = $this->read_plugin_option_rows($wpdb->get_blog_prefix($bid) . 'options');
				}
			}
		}

		// Save progress for resume support
		$this->save_restore_progress('db', 'started');
		// Fresh import: discard any search-replace checkpoint/cursor from an earlier
		// run of this backup, otherwise a later restore "resumes" with a stale cursor
		// and skips tables that were just re-imported.
		SiteGuard_Backup_Options::instance()->delete_option($this->sr_state_option);
		delete_option('sgbackup_sr_progress');

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

			// Full-dump migration detection: scan_db_for_old_urls() caps its scan, so
			// on comment-heavy dumps the source siteurl (which lives in wp_options,
			// possibly far into the file) can be missed - leaving is_migration false
			// and causing the user-table-skip below to import source users and lock
			// the admin out. Do a focused, uncapped pass so the skip decision is
			// based on reality, not on where wp_options happened to land.
			if (!$this->is_migration && empty($this->old_siteurl)) {
				$full_siteurl = $this->find_source_main_siteurl($file_path);
				if (!empty($full_siteurl)) {
					$this->old_siteurl = $full_siteurl;
					$cmp_siteurl = !empty($this->pre_import_siteurl) ? $this->pre_import_siteurl : get_option('siteurl');
					if ($full_siteurl !== $cmp_siteurl) {
						$this->is_migration = true;
						$this->sgbackup->log("Full siteurl scan detected migration missed by capped scan: {$full_siteurl} -> {$cmp_siteurl}");
					}
				}
			}

			// Cross-prefix guard: a full-DB backup whose tables use a different base
			// prefix than this install can't be imported by the standard path - the
			// SQL recreates tables under the OLD prefix while WordPress keeps reading
			// the current prefix, so the site silently serves stale data. Only the
			// subsite-remap path (execute_sql_file_remap_prefix) rewrites prefixes,
			// and that path returns earlier in perform_restore().
			if (!empty($this->old_table_prefix) && $this->old_table_prefix !== $GLOBALS['wpdb']->base_prefix) {
				$pmsg = "Table prefix mismatch: backup uses '{$this->old_table_prefix}' but this site uses '{$GLOBALS['wpdb']->base_prefix}'. The standard restore path cannot remap table prefixes, so imported tables would not be served by WordPress. Restore aborted - restore into an install with a matching prefix, or use the subsite import path.";
				$this->sgbackup->log("[Restore] {$pmsg}", 'error');
				$this->progress($pmsg);
				$result['errors'][] = $pmsg;
				$result['success'] = false;
				return $result;
			}

			// Skip user tables during migrations to preserve destination login
			// credentials. The source backup's wp_users/wp_usermeta have different
			// password hashes, and importing them would lock out the admin.
			$skip_user_tables = ($this->is_migration && in_array($this->db_restore_mode, array(self::DB_RESTORE_MIGRATE, self::DB_RESTORE_OVERWRITE), true));

			$this->sgbackup->log("Importing database: {$db_file}");

			// Mode 2 (tables-only): use filtered import with only tables NOT in DB
			if ($this->db_restore_mode === self::DB_RESTORE_TABLES_ONLY && $selected_tables === null) {
				// Extract table names from SQL file, then filter to only new ones
				$backup_tables = $this->cached_tables_in_sql_file($file_path);
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
						@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
					}
				);
			} elseif ($selected_tables !== null) {
				// Use filtered method when selective tables are specified
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$selected_tables,
					function($lines_done) {
						@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
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
				$all_backup_tables = $this->cached_tables_in_sql_file($file_path);
				$tables_to_import = empty($source_blogs) ? $all_backup_tables : $this->compute_selective_tables($all_backup_tables, $source_blogs);
				if ($skip_user_tables) {
					// A migration with skipped subsites used to take this branch and
					// import the SOURCE wp_users/wp_usermeta, locking out the
					// destination admin. Preserve credentials here too.
					global $wpdb;
					$user_tables = array($wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta');
					$tables_to_import = array_values(array_diff($tables_to_import, $user_tables));
					$this->user_tables_skipped = true;
					$this->sgbackup->log("Migration: skipping user tables (" . implode(', ', $user_tables) . ") to preserve destination credentials");
				}
				$skipped_count = count($all_backup_tables) - count($tables_to_import);
				if ($skipped_count === 0) {
					// Nothing was actually excluded - use atomic import for crash safety
					$this->sgbackup->log("Filtered import: no tables excluded (source blogs not matched to skip map), using atomic import");
					$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_atomic(
						$file_path,
						function($lines_done) {
							@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
						}
					);
				} else {
					$this->filtered_import_used = true;
					$this->sgbackup->log("Filtered import: {$skipped_count} tables excluded for skipped subsites, importing " . count($tables_to_import) . " tables");
					$this->progress("Importing " . count($tables_to_import) . " tables (skipping {$skipped_count} subsite tables)");
					$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
						$file_path,
						$tables_to_import,
						function($lines_done) {
							@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
						}
					);
				}
			} elseif ($skip_user_tables) {
				// Migration: import all tables EXCEPT wp_users and wp_usermeta
				// to preserve destination login credentials.
				global $wpdb;
				$user_tables = array($wpdb->base_prefix . 'users', $wpdb->base_prefix . 'usermeta');
				$all_tables = $this->cached_tables_in_sql_file($file_path);
				$tables_to_import = array_diff($all_tables, $user_tables);
				$this->user_tables_skipped = true;
				$this->sgbackup->log("Migration: skipping user tables (" . implode(', ', $user_tables) . ") to preserve destination credentials");
				$this->progress("Importing " . count($tables_to_import) . " tables (preserving user credentials)");
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_filtered(
					$file_path,
					$tables_to_import,
					function($lines_done) {
						@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
					}
				);
			} else {
				// Use atomic restore (temp table → RENAME) for crash safety
				$import_result = SiteGuard_Backup_Database_Utility::execute_sql_file_atomic(
					$file_path,
					function($lines_done) {
						@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
					}
				);
			}

			$result['tables_restored'] += $import_result['tables_restored'];
			$result['errors'] = array_merge($result['errors'], $import_result['errors']);

			if (!$import_result['success']) {
				$result['success'] = false;
			}
		}

		// A failed import (wrong passphrase, SQL error, ...) must not be checkpointed
		// as done: the next attempt then took itself for a resume, skipped the
		// database and reported success with nothing restored.
		if (!$result['success']) {
			$this->save_restore_progress('db', 'failed');
			return $result;
		}

		// Flush WordPress object cache - the atomic RENAME TABLE makes all
		// cached option values stale (they still hold the pre-import data).
		wp_cache_flush();

		// Put this site's backup history back and persist the destination snapshot
		// now, before anything below can be interrupted.
		$this->preserve_after_import();

		// The SOURCE's blog domains, before the safety net below rewrites blog 1 to
		// the destination's domain. The mirror search-replace used to read them
		// afterwards, took the destination's own domain for a source "independent
		// domain" and remapped it onto itself (dst.test -> dst.dst.dst.test).
		if (is_multisite()) {
			global $wpdb;
			$bt = $wpdb->base_prefix . 'blogs';
			if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($bt))) === $bt) {
				$this->source_blog_domains = array_values(array_unique((array) $wpdb->get_col("SELECT domain FROM `{$bt}`")));
			}
		}

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
			// Checkpoint the import ONLY now that we've committed to the resumable
			// search-replace path. Marking it earlier would let a crash in the
			// (non-migration) tail below skip those steps on resume; confined here,
			// 'db_import' means exactly "import done, search-replace may need resuming".
			$this->save_restore_progress('db_import', 'completed');
			$this->progress("Performing search-replace for migration");
			$sr_status = $this->do_search_replace();
			if ($sr_status === 'incomplete') {
				// Search-replace checkpointed at the request deadline. Leave 'db'
				// unmarked so the resume path continues SR; do not fall through to
				// the completion checkpoint below.
				$result['sr_incomplete'] = true;
				return $result;
			}
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

		// Save progress for resume support (only a successful import is "done")
		$this->save_restore_progress('db', $result['success'] ? 'completed' : 'failed');

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

		// Resolve (decrypt) the DB archives BEFORE touching the target: a wrong
		// passphrase used to be discovered only after the target's tables had been
		// dropped, leaving an empty subsite and a "complete" message.
		$predecrypted = $this->predecrypt_db_files($backup_dir);
		if (is_wp_error($predecrypted)) {
			$result['success'] = false;
			$result['errors'][] = $predecrypted->get_error_message();
			return $result;
		}

		$source_label = $source_id > 0 ? "subsite blog #{$source_id}" : "single-site backup";
		$this->sgbackup->log("Subsite import: {$source_label} -> target domain {$target_domain}");
		$this->progress("Importing {$source_label} to {$target_domain}");
		if (!$target_blog) {
			$target_blog = get_blog_details(array('domain' => $target_domain), false);
		}
		$target_id = null;
		$preserved_sg_options = array();

		if ($target_blog) {
			$target_id = (int) $target_blog->blog_id;
			$target_path = $target_blog->path;
			$this->sgbackup->log("Target subsite exists: blog #{$target_id} ({$target_domain}{$target_path})");
			$this->progress("Target subsite already exists (blog #{$target_id}), will overwrite tables");

			// Drop existing subsite tables so we can import fresh
			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$preserved_sg_options = $this->read_plugin_option_rows($target_prefix . 'options');
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
					$all_tables = $this->cached_tables_in_sql_file($first_file);
					$prefix_pattern = '/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+)_/';
					$detected_ids = array();
					foreach ($all_tables as $table) {
						// A number is a blog id only if that blog has its own options
						// table; plugin tables like wp_404_to_301 on a single site
						// otherwise made the whole import use prefix wp_404_.
						if (preg_match($prefix_pattern, $table, $m)
							&& in_array($wpdb->base_prefix . $m[1] . '_options', $all_tables, true)) {
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
							if (preg_match($prefix_pattern, $table, $m) && isset($detected_ids[intval($m[1])])) {
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

				// Decrypt if needed (normally already done up front by predecrypt_db_files)
				if (preg_match('/\.crypt$/', $db_file)) {
					$file_path = isset($predecrypted[$db_file]) ? $predecrypted[$db_file] : $this->decrypt_file($file_path);
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
			$this->fix_prefixed_option_names($source_prefix, $target_prefix, $target_id);
			$this->replace_plugin_option_rows($target_prefix . 'options', $preserved_sg_options, $target_id);
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
		//
		// Source URL detection precedence (most specific wins):
		//   1. backup_set['subsite_url']     - full URL transmitted by sender
		//   2. backup_set['subsite_domain']  + path  - older senders transmitting only the host
		//   3. imported wp_N_options.siteurl - last-resort SQL scan after import
		$target_prefix_for_scan = $wpdb->base_prefix . $target_id . '_';
		$old_siteurl = '';
		$old_home_url = '';
		$old_domain = '';
		$old_path = '/';
		$old_scheme = '';

		if (!empty($this->backup_set['subsite_url'])) {
			$old_siteurl = rtrim($this->backup_set['subsite_url'], '/');
			$old_scheme = parse_url($old_siteurl, PHP_URL_SCHEME);
			$old_domain = parse_url($old_siteurl, PHP_URL_HOST);
			$p = parse_url($old_siteurl, PHP_URL_PATH);
			$old_path = $p ? (rtrim($p, '/') . '/') : '/';
			if (!empty($this->backup_set['subsite_home_url'])) {
				$old_home_url = rtrim($this->backup_set['subsite_home_url'], '/');
			}
			$this->sgbackup->log("Source URL from transmitted metadata: {$old_siteurl}");
		} elseif (!empty($this->backup_set['subsite_domain'])) {
			$old_domain = $this->backup_set['subsite_domain'];
			$old_path = !empty($this->backup_set['subsite_path']) ? $this->backup_set['subsite_path'] : '/';
			$old_scheme = !empty($this->backup_set['subsite_scheme']) ? $this->backup_set['subsite_scheme'] : '';
			$this->sgbackup->log("Source domain from metadata (no full URL): {$old_domain}{$old_path}");
		}

		// SQL fallback when nothing useful was transmitted
		if (empty($old_siteurl)) {
			$imported_siteurl = $wpdb->get_var("SELECT option_value FROM {$target_prefix_for_scan}options WHERE option_name = 'siteurl'");
			$imported_home    = $wpdb->get_var("SELECT option_value FROM {$target_prefix_for_scan}options WHERE option_name = 'home'");
			if ($imported_siteurl) {
				$old_siteurl = rtrim($imported_siteurl, '/');
				if (empty($old_domain)) $old_domain = parse_url($imported_siteurl, PHP_URL_HOST);
				if (empty($old_scheme)) $old_scheme = parse_url($imported_siteurl, PHP_URL_SCHEME);
				$p = parse_url($imported_siteurl, PHP_URL_PATH);
				if (empty($old_path) || $old_path === '/') {
					$old_path = $p ? (rtrim($p, '/') . '/') : '/';
				}
				$this->sgbackup->log("Source URL recovered from imported siteurl: {$imported_siteurl}");
			}
			if ($imported_home && empty($old_home_url)) {
				$old_home_url = rtrim($imported_home, '/');
			}
		}

		// Target scheme. Prefer a per-restore override if the user supplied one,
		// otherwise follow the destination network's main-site scheme (the
		// convention in every WP multisite install - subsites inherit it unless
		// someone manually forced a different one). Fall back to is_ssl() if
		// somehow the option isn't readable.
		$new_scheme = '';
		if (!empty($this->restore_options['target_scheme']) && in_array($this->restore_options['target_scheme'], array('http', 'https'), true)) {
			$new_scheme = $this->restore_options['target_scheme'];
		}
		if (empty($new_scheme)) {
			$network_siteurl = '';
			if (is_multisite()) {
				$network_siteurl = get_blog_option(get_main_site_id(), 'siteurl');
			}
			if (empty($network_siteurl)) $network_siteurl = get_option('siteurl');
			$new_scheme = $network_siteurl ? parse_url($network_siteurl, PHP_URL_SCHEME) : '';
		}
		if (empty($new_scheme)) {
			$new_scheme = is_ssl() ? 'https' : 'http';
		}

		$target_url_base = $new_scheme . '://' . $target_domain . rtrim($target_path, '/');
		$old_url_base = !empty($old_siteurl) ? $old_siteurl : (!empty($old_domain) ? (($old_scheme ?: 'https') . '://' . $old_domain . rtrim($old_path, '/')) : '');

		// Decide whether we need search-replace. Even if domain is identical,
		// a scheme or path change still requires rewriting.
		$needs_sr = !empty($old_domain) && $old_domain !== $target_domain;
		if (!$needs_sr && !empty($old_url_base) && $old_url_base !== $target_url_base) {
			$needs_sr = true;
			$this->sgbackup->log("Same domain but different path/scheme detected: {$old_url_base} -> {$target_url_base}");
		}
		if (!$needs_sr && empty($old_domain)) {
			$this->sgbackup->log("WARNING: search-replace skipped - could not determine source URL from metadata, backup_set, or imported options. URLs in the imported tables will retain their original host.", 'warning');
		}

		if ($needs_sr) {
			$this->progress("Search-replace: {$old_domain} -> {$target_domain}" . ($target_path !== '/' ? $target_path : ''));

			require_once SGBACKUP_DIR . 'includes/class-search-replace.php';

			$target_prefix = $wpdb->base_prefix . $target_id . '_';
			$tables = SiteGuard_Backup_Database_Utility::get_tables($target_prefix);

			$search = array();
			$replace = array();
			$seen = array();
			$add_pair = function($from, $to) use (&$search, &$replace, &$seen) {
				if ($from === '' || $from === $to) return;
				$key = $from . "\x1f" . $to;
				if (isset($seen[$key])) return;
				$seen[$key] = true;
				$search[] = $from;
				$replace[] = $to;
			};

			// Pair 0: host-provider %-escape tokens. WP Engine and some managed
			// hosts rewrite literal "%" characters to a fixed {64-hex} token in
			// mysqldump output to defeat format-specifier injection via
			// wpdb::prepare(). Left in place, these tokens corrupt inline CSS
			// ("width:100{…};") and any string that used % (URL-encoded
			// characters, percentage values, etc.). Unwrap before the URL SR
			// pairs so the SR engine's unserialize/reserialize path fixes the
			// length prefixes on serialized blobs at the same time.
			$known_tokens = apply_filters('sgbackup_known_escape_tokens', array(
				'{43ded7db8220f9320535a3aa7ca010fd0da6724531b8493a05e5c6388a06739f}', // WP Engine default
			));
			foreach ($known_tokens as $tok) {
				$add_pair($tok, '%');
			}

			// Pair 0.5: single site / main site -> subsite. The source kept uploads at
			// wp-content/uploads/, the subsite's files were restored to
			// uploads/sites/{target}/, so the uploads base URL has to change too;
			// a plain host swap left every image pointing at the main site (404).
			// Must precede the generic URL pairs.
			if ((int) $source_id <= 1 && (int) $target_id > 1 && !empty($old_url_base)) {
				$bare_old_up = preg_replace('#^https?://#i', '', $old_url_base) . '/wp-content/uploads/';
				$new_up = $target_url_base . '/wp-content/uploads/sites/' . (int) $target_id . '/';
				foreach (array('https://', 'http://', '//') as $sch) {
					$to = ('//' === $sch) ? preg_replace('#^https?:#i', '', $new_up) : $new_up;
					$add_pair($sch . $bare_old_up, $to);
					$add_pair(str_replace('/', '\/', $sch . $bare_old_up), str_replace('/', '\/', $to));
				}
			}

			// Pair 1-2: schemed full URLs. Cover both http:// and https:// because
			// the source DB may contain either, regardless of what scheme the
			// source was actually serving at backup time.
			if (!empty($old_url_base)) {
				$bare_old = preg_replace('#^https?://#i', '', $old_url_base);
				$add_pair('https://' . $bare_old, $target_url_base);
				$add_pair('http://' . $bare_old,  $target_url_base);
				// Protocol-relative (//host/path) - used by some themes/plugins
				$add_pair('//' . $bare_old, '//' . $target_domain . rtrim($target_path, '/'));
			}
			// Pair 3-4: home_url if it differs from siteurl (rare but legal)
			if (!empty($old_home_url) && $old_home_url !== $old_url_base) {
				$bare_home = preg_replace('#^https?://#i', '', $old_home_url);
				$add_pair('https://' . $bare_home, $target_url_base);
				$add_pair('http://' . $bare_home,  $target_url_base);
				$add_pair('//' . $bare_home, '//' . $target_domain . rtrim($target_path, '/'));
			}
			// Pair 5-6: domain-only (catches URLs in serialized blobs where the
			// scheme/path doesn't match what we computed, plus raw references
			// like "shop.example.com" in text fields)
			if ($old_domain !== $target_domain) {
				$add_pair($old_domain, $target_domain);
			}

			// Pair 7+: uploads path remap when the target blog_id differs from the
			// source. WP multisite stores uploads under wp-content/uploads/sites/{id}/
			// and those paths are baked into post_content + postmeta (Elementor, ACF,
			// page builders). Without this, every image in the restored subsite 404s.
			// Cover plain, escaped-slash (JSON), and no-leading-slash variants.
			if ($source_id > 0 && $target_id > 0 && (int) $source_id !== (int) $target_id) {
				$add_pair('/wp-content/uploads/sites/' . $source_id . '/', '/wp-content/uploads/sites/' . $target_id . '/');
				$add_pair('\/wp-content\/uploads\/sites\/' . $source_id . '\/', '\/wp-content\/uploads\/sites\/' . $target_id . '\/');
				$add_pair('wp-content/uploads/sites/' . $source_id . '/', 'wp-content/uploads/sites/' . $target_id . '/');
				// Defensive: bare sites/{n}/ segment without the wp-content prefix,
				// only when the ids are at least 2 digits (blog_id=1 is main site,
				// and a raw "sites/1/" substring would have countless false matches).
				if ((int) $source_id >= 10 && (int) $target_id >= 1) {
					$add_pair('/sites/' . $source_id . '/', '/sites/' . $target_id . '/');
				}
			}

			$this->sgbackup->log("Subsite search-replace pairs: " . count($search) . " - " . wp_json_encode(array_slice($search, 0, 10)) . " -> " . wp_json_encode(array_slice($replace, 0, 10)));

			// Capture email-bearing options on the target subsite before SR so the
			// bare-domain pair can't corrupt an address whose domain matches old_domain.
			$sub_email_keys = array('admin_email', 'new_admin_email', 'woocommerce_email_from_address');
			$preserved_sub_emails = array();
			foreach ($sub_email_keys as $eok) {
				$eval = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$target_prefix}options WHERE option_name = %s", $eok));
				if ($eval !== null) $preserved_sub_emails[$eok] = $eval;
			}

			$sr = new SiteGuard_Backup_Search_Replace();
			$report = $sr->run($search, $replace, $tables);

			$this->sgbackup->log("Subsite search-replace: {$report['changes']} changes across {$report['tables']} tables");
			$this->progress("Search-replace: {$report['changes']} changes in {$report['tables']} tables");

			$result['messages'][] = "Search-replace: {$report['changes']} changes ({$old_domain} -> {$target_domain}" . ($target_path !== '/' ? $target_path : '') . ")";

			// Re-fix any subsite email option the bare-domain replace corrupted.
			foreach ($preserved_sub_emails as $eok => $orig) {
				$now = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$target_prefix}options WHERE option_name = %s", $eok));
				if ($now !== null && $now !== $orig) {
					$wpdb->update($target_prefix . 'options', array('option_value' => $orig), array('option_name' => $eok));
					$this->sgbackup->log("Restored subsite option '{$eok}' after search-replace altered it ('{$now}' -> '{$orig}')");
				}
			}

			// Release the first pass's engine + report before any later passes.
			unset($sr, $report);
		}

		// Step 6: Fix subsite options (siteurl, home). Preserve any source-side
		// home-vs-siteurl divergence by reapplying the relative path.
		$target_prefix = $wpdb->base_prefix . $target_id . '_';
		$new_siteurl = $target_url_base;
		$new_home = $target_url_base;
		if (!empty($old_home_url) && !empty($old_siteurl) && $old_home_url !== $old_siteurl) {
			$old_home_path = parse_url($old_home_url, PHP_URL_PATH);
			$old_site_path = parse_url($old_siteurl, PHP_URL_PATH);
			$old_home_path = $old_home_path ? rtrim($old_home_path, '/') : '';
			$old_site_path = $old_site_path ? rtrim($old_site_path, '/') : '';
			if ($old_home_path !== $old_site_path) {
				$suffix = $old_home_path !== '' ? $old_home_path : '';
				$new_home = $new_scheme . '://' . $target_domain . rtrim($target_path, '/') . $suffix;
			}
		}

		$wpdb->update(
			$target_prefix . 'options',
			array('option_value' => $new_siteurl),
			array('option_name' => 'siteurl')
		);
		$wpdb->update(
			$target_prefix . 'options',
			array('option_value' => $new_home),
			array('option_name' => 'home')
		);

		// Also sync the multisite registry in case it got out of step (e.g. an
		// existing subsite whose wp_blogs.domain disagreed with what the admin
		// picked). This is cheap insurance.
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$wpdb->update(
			$blogs_table,
			array('domain' => $target_domain, 'path' => $target_path),
			array('blog_id' => $target_id)
		);

		$this->sgbackup->log("Fixed subsite siteurl={$new_siteurl} home={$new_home} wp_blogs(domain={$target_domain}, path={$target_path})");

		// Step 7: Residual escape-token sweep. The known-token SR pair above
		// catches the well-known WPE value, but other hosts use different hashes.
		// Scan the target's wp_N_options / postmeta / posts for lingering
		// {64-hex} tokens, collect unique ones, and run a second SR pass.
		$sweep_tables = array(
			$target_prefix . 'options',
			$target_prefix . 'posts',
			$target_prefix . 'postmeta',
		);
		$residual_tokens = $this->scan_for_escape_tokens($sweep_tables);
		if (!empty($residual_tokens)) {
			require_once SGBACKUP_DIR . 'includes/class-search-replace.php';
			$sr2 = new SiteGuard_Backup_Search_Replace();
			$pairs = array();
			foreach ($residual_tokens as $tok) {
				$pairs['search'][]  = $tok;
				$pairs['replace'][] = '%';
			}
			// Escape tokens only ever occur in the option_value / post_content /
			// meta_value columns we just scanned, so confine the second pass to those
			// tables instead of re-walking every table on the network (a full extra
			// pass over wp_N_* that found nothing but cost the most memory/time).
			$report2 = $sr2->run($pairs['search'], $pairs['replace'], $sweep_tables);
			$this->sgbackup->log("Residual escape-token sweep: " . count($residual_tokens) . " token(s) replaced across {$report2['tables']} tables ({$report2['changes']} changes). Tokens: " . wp_json_encode(array_values($residual_tokens)));
		}

		// Step 8: Transfer source subsite's active_plugins - intersect with what
		// is actually installed on the destination, and report the unmet subset
		// as a remediation item. The import wrote whatever was in the source's
		// wp_N_options, but on a fresh destination network many of those plugins
		// may not be installed yet.
		$remediation = $this->apply_subsite_active_plugins_policy($target_prefix, $target_id);
		if (!empty($remediation['unmet_plugins']) || !empty($remediation['unmet_theme'])) {
			$this->emit_remediation_notice($remediation, $target_domain);
			$result['messages'][] = "Remediation: " . count($remediation['unmet_plugins']) . " plugin(s) / " . (empty($remediation['unmet_theme']) ? 0 : 1) . " theme(s) on the source are not installed on this network.";
		}

		// Step 8.5: Serialized-length repair safety net. Any upstream corruption
		// that changed string byte lengths without updating the s:N:"…" prefix
		// (e.g. old v1.4.9 percent-doubling bug, or future bugs we haven't
		// discovered yet) breaks PHP's unserialize() and WordPress silently
		// substitutes defaults. Walk text columns on the target subsite tables,
		// repair where possible, report where not.
		$repair_report = $this->repair_serialized_lengths(array(
			$target_prefix . 'options',
			$target_prefix . 'postmeta',
			$target_prefix . 'posts',
		));
		if ($repair_report['broken'] > 0) {
			$this->sgbackup->log(sprintf(
				'Serialized-length repair: scanned %d rows, %d broken blobs found, %d fixed, %d still broken',
				$repair_report['scanned'], $repair_report['broken'], $repair_report['fixed'], $repair_report['broken'] - $repair_report['fixed']
			));
			if (!empty($repair_report['still_broken'])) {
				foreach ($repair_report['still_broken'] as $ref) {
					$this->sgbackup->log("Serialized-length repair: still broken after repair: {$ref}", 'warning');
				}
			}
		}

		// Step 9: Invalidate caches that are now stale. rewrite_rules holds
		// baked-in paths; Elementor caches CSS keyed by post/URL; object cache
		// may be serving pre-restore option_values. WordPress rebuilds all of
		// these on next request, so deletion is safe.
		$wpdb->delete($target_prefix . 'options', array('option_name' => 'rewrite_rules'));
		$this->sgbackup->log("Deleted {$target_prefix}options.rewrite_rules (will be rebuilt on first pretty-permalink request)");
		$this->clear_subsite_elementor_cache($target_id);
		if (function_exists('wp_cache_flush_group')) {
			wp_cache_flush_group('options');
		} elseif (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
		if (function_exists('clean_blog_cache')) {
			clean_blog_cache($target_id);
		}

		// Step 9.5: HTTP smoke test. Fetch the restored subsite's URL over
		// HTTP and assert: 200, no WordPress "critical error" string, no
		// residual host-escape token, no source domain leakage. Any failure
		// here is surfaced as a remediation warning (not a hard error - the
		// admin's server config might prevent a self-referential loopback).
		$smoke_errors = $this->http_smoke_test_subsite($target_url_base, $old_domain);
		foreach ($smoke_errors as $msg) {
			$this->sgbackup->log("[Smoke test] {$msg}", 'warning');
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $msg)) . "\n";
				if (ob_get_level()) @ob_flush();
				@flush();
			}
		}

		// Step 10: Validation sweep. Check for lingering tokens, lingering
		// /sites/{src}/ paths, and unserializable options. Surface failures as
		// errors so the caller can report "partial restore needs remediation"
		// rather than "success".
		$validation_errors = $this->validate_subsite_restore($target_prefix, $target_id, $source_id);
		if (!empty($validation_errors)) {
			$result['errors'] = array_merge($result['errors'], $validation_errors);
			foreach ($validation_errors as $ve) {
				$this->sgbackup->log("[Validation] {$ve}", 'warning');
				if (defined('DOING_AJAX') && DOING_AJAX) {
					echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $ve)) . "\n";
					if (ob_get_level()) @ob_flush();
					@flush();
				}
			}
		}

		$result['messages'][] = "Subsite import complete: blog #{$target_id} at {$target_domain}";

		return $result;
	}

	/**
	 * Scan a small set of text columns across $tables for {64-hex} escape
	 * tokens and return the unique set. Keeps the SELECT cheap by only peeking
	 * at likely-affected columns (options, postmeta, posts).
	 *
	 * @param array $tables Tables to scan.
	 * @return array Map of token => token (dedup-friendly).
	 */
	private function scan_for_escape_tokens($tables) {
		global $wpdb;
		$found = array();

		foreach ($tables as $table) {
			$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
			if (!$safe) continue;
			// Only proceed if the table actually exists
			$exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $safe));
			if (!$exists) continue;

			$cols = $wpdb->get_results("DESCRIBE `{$safe}`", ARRAY_A);
			foreach ($cols as $col) {
				$type = strtolower($col['Type']);
				if (!preg_match('/text|longtext|mediumtext|varchar/i', $type)) continue;
				$colname = str_replace('`', '``', $col['Field']);
				// MySQL REGEXP: match literal "{", 64 hex chars, "}"
				$rows = $wpdb->get_col("SELECT `{$colname}` FROM `{$safe}` WHERE `{$colname}` REGEXP '\\\\{[0-9a-f]{64}\\\\}' LIMIT 50");
				foreach ($rows as $val) {
					if (is_null($val)) continue;
					if (preg_match_all('/\{[0-9a-f]{64}\}/', $val, $matches)) {
						foreach ($matches[0] as $m) {
							$found[$m] = $m;
						}
					}
				}
				// Bail once we've found a few - typical host emits one token
				if (count($found) >= 5) return $found;
			}
		}
		return $found;
	}

	/**
	 * Activate, on the imported subsite, the source plugins that were not on disk
	 * when active_plugins was reconciled but have now been extracted from the backup.
	 */
	private function activate_extracted_subsite_plugins() {
		global $wpdb;
		if (empty($this->subsite_plugin_intent['plugins'])) return;
		$intent = $this->subsite_plugin_intent;
		$this->subsite_plugin_intent = null;

		$table = $intent['prefix'] . 'options';
		$current = @unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", 'active_plugins')));
		if (!is_array($current)) $current = array();
		$added = array();
		foreach ($intent['plugins'] as $p) {
			if (validate_file($p) === 0 && file_exists(WP_PLUGIN_DIR . '/' . $p) && !in_array($p, $current, true)) {
				$current[] = $p;
				$added[] = $p;
			}
		}
		if (!$added) return;
		$wpdb->update($table, array('option_value' => serialize(array_values($current))), array('option_name' => 'active_plugins'));
		if (function_exists('clean_blog_cache')) clean_blog_cache($intent['blog_id']);
		$this->sgbackup->log("Activated on blog #{$intent['blog_id']} after extraction: " . implode(', ', $added));
	}

	/**
	 * After a per-subsite restore, reconcile active_plugins / active theme with
	 * what's actually installed on the destination network. Sets the intersection,
	 * reports the unmet subset as remediation data.
	 *
	 * @param string $target_prefix e.g. wp_34_
	 * @param int    $target_id
	 * @return array ['unmet_plugins' => [...], 'unmet_theme' => string|'', 'kept_plugins' => [...]]
	 */
	private function apply_subsite_active_plugins_policy($target_prefix, $target_id) {
		global $wpdb;
		$out = array('unmet_plugins' => array(), 'unmet_theme' => '', 'kept_plugins' => array());

		// Pull the imported list. wp_N_options.active_plugins is a serialized array.
		$serialized = $wpdb->get_var($wpdb->prepare(
			"SELECT option_value FROM {$target_prefix}options WHERE option_name = %s",
			'active_plugins'
		));
		$source_plugins = array();
		if (!empty($serialized)) {
			$maybe = @unserialize($serialized);
			if (is_array($maybe)) $source_plugins = array_values(array_filter(array_map('strval', $maybe)));
		}

		// Also consider the source's active_sitewide_plugins reported in backup
		// metadata (if sender was updated to transmit it). These are
		// network-activated plugins the source relied on. On a per-subsite
		// import these WILL NOT be in the imported wp_N_options.active_plugins
		// (they live in wp_sitemeta on the source) - meaning the restored
		// subsite comes up with zero plugins enabled unless we explicitly
		// reconstruct the active set here.
		$source_network_plugins = array();
		if (!empty($this->backup_set['subsite_active_sitewide_plugins']) && is_array($this->backup_set['subsite_active_sitewide_plugins'])) {
			$source_network_plugins = array_values(array_map('strval', $this->backup_set['subsite_active_sitewide_plugins']));
		}

		// Build the set of plugins that the source subsite effectively had
		// running: its own active_plugins PLUS every network-active plugin.
		$all_source = array_values(array_unique(array_merge($source_plugins, $source_network_plugins)));

		// Destination's network-active set - we do NOT per-subsite-activate
		// plugins that are already network-active on the destination; they're
		// already running for every subsite.
		$destination_network = array();
		if (is_multisite() && function_exists('get_site_option')) {
			$dn = get_site_option('active_sitewide_plugins', array());
			if (is_array($dn)) $destination_network = array_keys($dn);
		}

		// Activation policy: for each source plugin, if it exists on disk and
		// is not network-active on destination, activate it per-subsite. Track
		// the unmet ones (not on disk and not network-active) for remediation.
		$to_activate_on_subsite = array();
		foreach ($all_source as $p) {
			$path = WP_PLUGIN_DIR . '/' . $p;
			$on_disk = file_exists($path);
			$already_netwide = in_array($p, $destination_network, true);
			if ($on_disk && !$already_netwide) {
				$to_activate_on_subsite[] = $p;
			} elseif (!$on_disk && !$already_netwide) {
				$out['unmet_plugins'][] = $p;
			}
		}
		$out['kept_plugins'] = $to_activate_on_subsite;
		// Plugins that only arrive with the backup are extracted AFTER this runs
		// (shared entities go last); remember the intent so they can be activated
		// once they are on disk (see activate_extracted_subsite_plugins()).
		$this->subsite_plugin_intent = array(
			'prefix'  => $target_prefix,
			'blog_id' => (int) $target_id,
			'plugins' => $out['unmet_plugins'],
		);

		// Write the reconstructed active_plugins list to the target subsite.
		// Do this unconditionally when we have a non-empty set - even if the
		// imported option already has values, those values came from the
		// source's subsite-level list which misses network-activated plugins.
		if (!empty($to_activate_on_subsite) || $source_plugins !== $to_activate_on_subsite) {
			$wpdb->update(
				$target_prefix . 'options',
				array('option_value' => serialize(array_values(array_unique($to_activate_on_subsite)))),
				array('option_name' => 'active_plugins')
			);
			$diff = array_values(array_diff($all_source, $to_activate_on_subsite, $destination_network));
			$this->sgbackup->log(sprintf(
				"active_plugins reconciled for blog #%d: activating %d per-subsite (%s); %d already network-active on destination; %d unmet (%s)",
				$target_id,
				count($to_activate_on_subsite),
				implode(', ', $to_activate_on_subsite) ?: '-',
				count(array_intersect($all_source, $destination_network)),
				count($out['unmet_plugins']),
				implode(', ', $out['unmet_plugins']) ?: '-'
			));
		}

		// Elementor 3.35+ has an upstream bug: the dashboard-widget module's
		// init calls the unnamespaced is_plugin_active() on frontend page
		// loads, but that function lives in wp-admin/includes/plugin.php which
		// isn't loaded on frontend by default. Sites that happen to have
		// *another* plugin require()ing that file at bootstrap paper over the
		// bug; a clean subsite with only Elementor active does not - it emits
		// a WSOD on every frontend request.
		//
		// Drop a 3-line mu-plugin that preloads the admin plugin API whenever
		// Elementor is being activated per-subsite or is network-active. The
		// shim is a no-op on admin requests (already loaded) and adds
		// microseconds to frontend boot. Idempotent: skipped if already
		// present.
		$has_elementor =
			in_array('elementor/elementor.php', $to_activate_on_subsite, true) ||
			in_array('elementor-pro/elementor-pro.php', $to_activate_on_subsite, true) ||
			in_array('elementor/elementor.php', $destination_network, true) ||
			in_array('elementor-pro/elementor-pro.php', $destination_network, true);
		if ($has_elementor) {
			$this->ensure_elementor_plugin_api_shim();
		}

		// Verify active theme exists; if not, record as unmet
		$stylesheet = $wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'stylesheet'");
		if (!empty($stylesheet)) {
			$theme_dir = defined('WP_CONTENT_DIR') ? (WP_CONTENT_DIR . '/themes/' . $stylesheet) : '';
			if ($theme_dir && !is_dir($theme_dir)) {
				$out['unmet_theme'] = $stylesheet;
			}
		}

		return $out;
	}

	/**
	 * Fetch the restored subsite URL and surface obvious trouble markers.
	 * Uses WP's HTTP API with sslverify=false so self-signed / staging certs
	 * don't derail the test. Short timeout so a hung request doesn't stall
	 * the restore. Returns an array of warning messages (empty = clean).
	 */
	private function http_smoke_test_subsite($target_url_base, $old_domain) {
		$msgs = array();
		$url = $target_url_base ? (rtrim($target_url_base, '/') . '/') : '';
		if (empty($url) || !function_exists('wp_remote_get')) return $msgs;

		$resp = wp_remote_get($url, array(
			'timeout'     => 12,
			'redirection' => 3,
			'sslverify'   => false,
			'headers'     => array('Cache-Control' => 'no-cache'),
			'user-agent'  => 'SiteGuardBackup/RestoreSmokeTest',
		));
		if (is_wp_error($resp)) {
			$msgs[] = sprintf('Smoke test could not fetch %s (%s). Verify locally after the restore completes.', $url, $resp->get_error_message());
			return $msgs;
		}
		$code = wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);

		if ($code < 200 || $code >= 400) {
			$msgs[] = sprintf('Smoke test: %s returned HTTP %d - restored subsite may not be reachable yet (DNS, SSL, or server config).', $url, $code);
		}
		if (stripos($body, 'There has been a critical error on this website') !== false) {
			$msgs[] = sprintf('Smoke test: %s rendered WordPress fatal error page. Check PHP error log for the restored subsite.', $url);
		}
		if (preg_match('/\{[0-9a-f]{64}\}/', $body)) {
			$msgs[] = sprintf('Smoke test: %s response still contains a host-escape token ({64-hex}). Token sweep likely missed a table.', $url);
		}
		// %% in HTML is suspicious - Yoast %%sep%% only appears inside <title>
		// before template rendering; after rendering there should be zero. We
		// allow a small threshold before flagging to avoid false positives on
		// plugins that legitimately emit inline <style> with escaped percents.
		$pct_pct_count = substr_count($body, '%%');
		if ($pct_pct_count > 3) {
			$msgs[] = sprintf('Smoke test: %s body contains %d instances of "%%%%" - likely the percent-doubling regression in text output.', $url, $pct_pct_count);
		}
		if (!empty($old_domain) && stripos($body, $old_domain) !== false) {
			$msgs[] = sprintf('Smoke test: %s body still references source domain %s - search-replace missed something.', $url, $old_domain);
		}
		if (empty($msgs)) {
			$this->sgbackup->log("Smoke test passed for {$url} (HTTP {$code}, no trouble markers)");
		}
		return $msgs;
	}

	/**
	 * Drop an mu-plugin that preloads wp-admin/includes/plugin.php so the
	 * unnamespaced is_plugin_active() / get_plugins() / is_plugin_active_for_network()
	 * helpers are available on frontend page loads. This works around an
	 * upstream Elementor 3.35+ bug that fatals on frontend when no other
	 * plugin happens to have require()'d the admin plugin API already.
	 *
	 * Idempotent: no-op if an mu-plugin with our marker is already present.
	 */
	private function ensure_elementor_plugin_api_shim() {
		if (!defined('WPMU_PLUGIN_DIR')) {
			$mu_dir = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/mu-plugins';
		} else {
			$mu_dir = WPMU_PLUGIN_DIR;
		}
		$target = $mu_dir . '/sgbackup-plugin-api-preload.php';
		if (file_exists($target)) {
			return; // already installed
		}
		if (!is_dir($mu_dir)) {
			if (!wp_mkdir_p($mu_dir)) {
				$this->sgbackup->log("Could not create mu-plugins dir at {$mu_dir}; Elementor is_plugin_active shim skipped.", 'warning');
				return;
			}
		}
		$body = "<?php\n"
			. "/*\n"
			. " * Plugin Name: SiteGuard Backup - plugin.php preload\n"
			. " * Description: Loads wp-admin/includes/plugin.php on every request so plugins that call is_plugin_active() / get_plugins() on the frontend (e.g. Elementor 3.35+) don't fatal. Dropped by SiteGuard Backup during subsite restore.\n"
			. " * Author: SiteGuard Backup\n"
			. " * Version: 1.0.0\n"
			. " */\n"
			. "if (!defined('ABSPATH')) { return; }\n"
			. "if (!function_exists('is_plugin_active')) {\n"
			. "    require_once ABSPATH . 'wp-admin/includes/plugin.php';\n"
			. "}\n";
		$bytes = @file_put_contents($target, $body);
		if ($bytes === false) {
			$this->sgbackup->log("Could not write Elementor plugin-api shim at {$target}", 'warning');
		} else {
			$this->sgbackup->log("Installed Elementor plugin-api shim at {$target} ({$bytes} bytes) - preloads wp-admin/includes/plugin.php on every request to work around Elementor 3.35+ frontend fatal.");
		}
	}

	private function emit_remediation_notice($remediation, $target_domain) {
		$lines = array();
		if (!empty($remediation['unmet_plugins'])) {
			$lines[] = 'Source plugins not installed on this network: ' . implode(', ', $remediation['unmet_plugins']) . '. Install + network-activate them (or copy from the source) before the subsite can use them.';
		}
		if (!empty($remediation['unmet_theme'])) {
			$lines[] = 'Active theme "' . $remediation['unmet_theme'] . '" is not installed on this network. Install it or switch the subsite to another theme.';
		}
		foreach ($lines as $msg) {
			$this->sgbackup->log("[Remediation] {$msg}", 'warning');
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $msg)) . "\n";
				if (ob_get_level()) @ob_flush();
				@flush();
			}
		}
	}

	/**
	 * Delete Elementor's cached CSS for a specific subsite.
	 *
	 * Elementor stores generated CSS under uploads/sites/{id}/elementor/css/ and
	 * caches a flag in postmeta per post. Deleting the files + flags forces
	 * regeneration with the post-restore URLs.
	 */
	private function clear_subsite_elementor_cache($target_id) {
		global $wpdb;
		// Files: uploads/sites/{id}/elementor/css/*
		$uploads_base = '';
		if (is_multisite()) {
			switch_to_blog(get_main_site_id());
			$uploads_base = SiteGuard_Backup_Filesystem::wp_uploads_dir();
			restore_current_blog();
		}
		if (!empty($uploads_base)) {
			$elementor_dir = rtrim($uploads_base, '/') . '/sites/' . intval($target_id) . '/elementor/css/';
			if (is_dir($elementor_dir)) {
				$this->remove_dir_recursive($elementor_dir, $uploads_base);
				$this->sgbackup->log("Cleared Elementor CSS cache at {$elementor_dir}");
			}
		}

		// Postmeta flag that tells Elementor "this post's CSS is fresh".
		$prefix = $wpdb->base_prefix . intval($target_id) . '_';
		$postmeta = $prefix . 'postmeta';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $postmeta))) {
			$wpdb->delete($postmeta, array('meta_key' => '_elementor_css'));
		}
	}

	/**
	 * Post-restore validation for a subsite import. Returns a list of human-readable
	 * warnings; empty array means the subsite looks clean.
	 */
	private function validate_subsite_restore($target_prefix, $target_id, $source_id) {
		global $wpdb;
		$errors = array();

		// 1. Any {64-hex} tokens still present in options/postmeta/posts?
		$lingering = $this->scan_for_escape_tokens(array(
			$target_prefix . 'options',
			$target_prefix . 'posts',
			$target_prefix . 'postmeta',
		));
		if (!empty($lingering)) {
			$errors[] = 'Host-provider escape tokens still present after restore (' . count($lingering) . ' unique): ' . implode(', ', array_slice(array_keys($lingering), 0, 3)) . (count($lingering) > 3 ? ', ...' : '') . '. Manually search-replace to "%".';
		}

		// 2. Any /sites/{src}/ references still present when ids differ?
		if ((int) $source_id > 0 && (int) $target_id > 0 && (int) $source_id !== (int) $target_id) {
			$src_path = '/sites/' . intval($source_id) . '/';
			$check_tables = array(
				$target_prefix . 'options',
				$target_prefix . 'posts',
				$target_prefix . 'postmeta',
			);
			foreach ($check_tables as $tbl) {
				$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($tbl);
				if (!$safe) continue;
				if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $safe))) continue;
				// Check each text column
				$cols = $wpdb->get_results("DESCRIBE `{$safe}`", ARRAY_A);
				foreach ($cols as $col) {
					$type = strtolower($col['Type']);
					if (!preg_match('/text|longtext|mediumtext|varchar/i', $type)) continue;
					$colname = str_replace('`', '``', $col['Field']);
					$n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$safe}` WHERE `{$colname}` LIKE %s", '%' . $wpdb->esc_like($src_path) . '%'));
					if ($n > 0) {
						$errors[] = "{$n} rows in {$tbl}.{$col['Field']} still reference {$src_path} after restore (target is blog_id={$target_id}).";
						break 2;
					}
				}
			}
		}

		// 3. siteurl / home sanity
		$siteurl = $wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'siteurl'");
		$home    = $wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'home'");
		if (empty($siteurl) || empty($home)) {
			$errors[] = "blog #{$target_id} is missing siteurl or home option after restore.";
		}

		// 4. Spot-check that the most common serialized options still unserialize.
		$check_opts = array('active_plugins', 'sidebars_widgets', 'theme_mods_' . ($wpdb->get_var("SELECT option_value FROM {$target_prefix}options WHERE option_name = 'stylesheet'")));
		foreach ($check_opts as $opt) {
			if (empty($opt)) continue;
			$val = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$target_prefix}options WHERE option_name = %s", $opt));
			if (empty($val)) continue;
			if (in_array(substr($val, 0, 2), array('a:', 's:', 'O:', 'i:', 'b:', 'd:'), true)) {
				$ok = @unserialize($val);
				if ($ok === false && $val !== 'b:0;') {
					$errors[] = "Option {$opt} on blog #{$target_id} failed to unserialize - the row is corrupt. Consider deleting it (WordPress will rebuild if safe).";
				}
			}
		}

		// 5. Percent-doubling check: if any row has `%%` in a place where it
		// almost certainly shouldn't (permalink_structure, known CSS options),
		// flag it. Yoast and a few plugins legitimately store `%%sep%%` style
		// placeholders, so we allowlist those - they're always `%%TOKEN%%`
		// with an alpha/underscore token between the pairs.
		$percent_fields_to_check = array(
			array('option_name' => 'permalink_structure', 'expect_no' => true),
		);
		foreach ($percent_fields_to_check as $check) {
			$v = $wpdb->get_var($wpdb->prepare(
				"SELECT option_value FROM {$target_prefix}options WHERE option_name = %s",
				$check['option_name']
			));
			if ($v !== null && $v !== '' && strpos($v, '%%') !== false && !empty($check['expect_no'])) {
				$errors[] = sprintf(
					'%soptions.%s contains "%%%%" (value: %s). This is the %%%%-doubling regression - run repair_serialized_lengths and a %%%%->%% sweep.',
					$target_prefix, $check['option_name'], $v
				);
			}
		}

		// 6. Sweep a sample of posts/postmeta/options for stray %% that is NOT
		// a Yoast-style %%token%% pair. The count is capped to avoid scanning
		// the entire DB; we just want a signal.
		$scan_tables = array(
			$target_prefix . 'options',
			$target_prefix . 'postmeta',
			$target_prefix . 'posts',
		);
		$stray_count = 0;
		foreach ($scan_tables as $tbl) {
			$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($tbl);
			if (!$safe) continue;
			if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $safe))) continue;
			$cols = $wpdb->get_results("DESCRIBE `{$safe}`", ARRAY_A);
			foreach ($cols as $col) {
				$type = strtolower($col['Type']);
				if (!preg_match('/text|longtext|mediumtext|varchar/i', $type)) continue;
				$colname = str_replace('`', '``', $col['Field']);
				$rows = $wpdb->get_col("SELECT `{$colname}` FROM `{$safe}` WHERE `{$colname}` LIKE '%\\%\\%%' LIMIT 20");
				foreach ($rows as $v) {
					if (!is_string($v)) continue;
					// Strip Yoast-style %%alpha_underscore%% tokens and see if %% remains.
					$stripped = preg_replace('/%%[a-z_]+(?:\([^)]*\))?%%/i', '', $v);
					if (strpos($stripped, '%%') !== false) {
						$stray_count++;
						if ($stray_count <= 3) {
							$errors[] = sprintf(
								'Stray "%%%%" found in %s.%s: %s (first 80 chars). Likely the percent-doubling regression.',
								$tbl, $col['Field'], substr($v, 0, 80)
							);
						}
					}
				}
				if ($stray_count > 20) break 2;
			}
		}
		if ($stray_count > 3) {
			$errors[] = sprintf('...and %d more stray %%%% occurrences across %s.', $stray_count - 3, implode(', ', $scan_tables));
		}

		return $errors;
	}

	/**
	 * Walk text columns on the provided tables, find serialized blobs whose
	 * `s:N:"..."` length prefix no longer matches the payload (a common fallout
	 * of upstream bugs that change byte lengths without re-serializing), and
	 * repair them. Returns a scanned/broken/fixed counts summary.
	 *
	 * Repair strategy is the "naive regex" approach: recompute N based on what
	 * the non-greedy regex matches as the string content. This correctly fixes
	 * the vast majority of cases (Elementor kits, plugin options, widget blobs,
	 * theme mods). It fails only when the string content itself contains the
	 * literal byte sequence `";` - those rows are reported as still-broken so
	 * the admin can inspect.
	 */
	private function repair_serialized_lengths($tables) {
		global $wpdb;
		$report = array('scanned' => 0, 'broken' => 0, 'fixed' => 0, 'still_broken' => array());

		foreach ($tables as $table) {
			$safe = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
			if (!$safe) continue;
			if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $safe))) continue;

			$cols = $wpdb->get_results("DESCRIBE `{$safe}`", ARRAY_A);
			$pk_col = null;
			foreach ($cols as $col) {
				if ($col['Key'] === 'PRI') { $pk_col = $col['Field']; break; }
			}
			if (!$pk_col) continue;

			foreach ($cols as $col) {
				$type = strtolower($col['Type']);
				if (!preg_match('/text|longtext|mediumtext/i', $type)) continue;
				$colname = $col['Field'];
				$esc_pk  = str_replace('`', '``', $pk_col);
				$esc_col = str_replace('`', '``', $colname);

				// Page by primary key rather than SELECTing every serialized blob at
				// once - on Elementor subsites the full result set is gigabytes and
				// OOMs PHP. Numeric PKs page cleanly; string PKs fall back to a single
				// bounded page (LIMIT still caps memory).
				$pk_type = '';
				foreach ($cols as $c2) { if ($c2['Field'] === $pk_col) { $pk_type = strtolower($c2['Type']); break; } }
				$pk_is_numeric = (bool) preg_match('/int|decimal|float|double|bit/i', $pk_type);

				$last_pk = 0;
				$page_size = 500;
				$max_blob = 8 * 1024 * 1024; // skip repair on blobs larger than this
				do {
					@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
					if ($pk_is_numeric) {
						$rows = $wpdb->get_results($wpdb->prepare(
							"SELECT `{$esc_pk}` AS pk, `{$esc_col}` AS val FROM `{$safe}`" .
							" WHERE (`{$esc_col}` LIKE 'a:%' OR `{$esc_col}` LIKE 's:%' OR `{$esc_col}` LIKE 'O:%')" .
							" AND `{$esc_pk}` > %d ORDER BY `{$esc_pk}` ASC LIMIT %d",
							$last_pk, $page_size
						), ARRAY_A);
					} else {
						// Non-numeric PK: one bounded page only (still memory-safe).
						$rows = $wpdb->get_results(
							"SELECT `{$esc_pk}` AS pk, `{$esc_col}` AS val FROM `{$safe}`" .
							" WHERE (`{$esc_col}` LIKE 'a:%' OR `{$esc_col}` LIKE 's:%' OR `{$esc_col}` LIKE 'O:%')" .
							" LIMIT {$page_size}",
							ARRAY_A
						);
					}
					if (empty($rows)) break;
					$row_count = count($rows);

					foreach ($rows as $row) {
						$last_pk = $pk_is_numeric ? (int) $row['pk'] : $last_pk;
						$report['scanned']++;
						$val = (string) $row['val'];
						if ($val === '') continue;
						// Must start with a serialization token
						if (!preg_match('/^[asOibdNrR]:/', $val)) continue;
						// Native unserialize first; if it passes, nothing to repair.
						$ok = @unserialize($val);
						if ($ok !== false || $val === 'b:0;') continue;

						$report['broken']++;
						// Guard the regex-based repair against pathological blob sizes.
						if (strlen($val) > $max_blob) {
							$report['still_broken'][] = "{$safe}#{$row['pk']}.{$colname} (skipped: >8MB)";
							continue;
						}
						$repaired = $this->repair_serialized_blob($val);
						if ($repaired !== false && $repaired !== $val && @unserialize($repaired) !== false) {
							$report['fixed']++;
							$wpdb->update($safe, array($colname => $repaired), array($pk_col => $row['pk']));
						} else {
							$report['still_broken'][] = "{$safe}#{$row['pk']}.{$colname}";
						}
					}
					unset($rows);
				} while ($pk_is_numeric && $row_count === $page_size);
			}
		}
		return $report;
	}

	/**
	 * Recompute s:N:"…" length prefixes based on the regex-matched string
	 * content. Not rigorous - fails on strings that contain the bytes `";` -
	 * but idempotent and safe: if the result fails to unserialize, the caller
	 * discards it and reports still_broken.
	 */
	private function repair_serialized_blob($blob) {
		if (!is_string($blob) || $blob === '') return false;
		$repaired = preg_replace_callback(
			'/s:\d+:"(.*?)";/s',
			function ($m) {
				return 's:' . strlen($m[1]) . ':"' . $m[1] . '";';
			},
			$blob
		);
		return $repaired;
	}

	/**
	 * Move processed backup archives + prune stale per-restore temp dirs out of
	 * the top level of wp-content/sgbackup/ so the directory doesn't fill up
	 * with orphaned files after successful restores.
	 *
	 * Archive policy:
	 *   - .zip / .sql.gz / .crypt files (the actual backup artefacts) are kept
	 *     in place - history still references them and the user can re-restore.
	 *   - temp_uploads_{ts}/ and restore_{md5}/ dirs get aggressively pruned;
	 *     anything older than 1 hour is removed. The top 3 by mtime are kept as
	 *     forensic samples in case the user needs to diagnose a bad restore.
	 *   - deleteflag-*.txt files older than 24h are consumed by the GC (they
	 *     were never being cleaned up before).
	 */
	private function archive_processed_backup_files($backup_dir) {
		$backup_dir = rtrim($backup_dir, '/\\') . '/';
		if (!is_dir($backup_dir)) return;

		$now = time();
		$keep_recent = 3;

		// Sweep temp_uploads_{ts}/ and restore_{md5}/ directories
		$candidates = array();
		$dh = @opendir($backup_dir);
		if ($dh) {
			while (false !== ($name = readdir($dh))) {
				if ($name === '.' || $name === '..') continue;
				$path = $backup_dir . $name;
				if (!is_dir($path)) continue;
				if (preg_match('/^(temp_uploads_|restore_)/', $name)) {
					$candidates[] = array('path' => $path, 'mtime' => @filemtime($path) ?: 0);
				}
			}
			closedir($dh);
		}
		// Sort newest first; keep $keep_recent, prune the rest if older than 1h
		usort($candidates, function($a, $b) { return $b['mtime'] <=> $a['mtime']; });
		$pruned = 0;
		foreach ($candidates as $i => $c) {
			if ($i < $keep_recent) continue;
			if (($now - $c['mtime']) < 3600) continue;
			$this->remove_dir_recursive($c['path'], $backup_dir);
			$pruned++;
		}
		if ($pruned > 0) {
			$this->sgbackup->log("Pruned {$pruned} stale temp/restore directories from sgbackup/");
		}

		// Consume stale deleteflag-*.txt markers
		$flag_files = glob($backup_dir . 'deleteflag-*.txt');
		if (is_array($flag_files)) {
			$flag_pruned = 0;
			foreach ($flag_files as $flag) {
				$age = $now - (@filemtime($flag) ?: $now);
				if ($age > 86400) {
					@unlink($flag);
					$flag_pruned++;
				}
			}
			if ($flag_pruned > 0) {
				$this->sgbackup->log("Consumed {$flag_pruned} stale deleteflag-*.txt markers (>24h old)");
			}
		}
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

		// Decrypt DB archives before any target tables are dropped (see restore_as_subsite).
		$predecrypted = $this->predecrypt_db_files($backup_dir);
		if (is_wp_error($predecrypted)) {
			$result['success'] = false;
			$result['errors'][] = $predecrypted->get_error_message();
			return $result;
		}

		$batch_preserved_sg = array(); // target blog id => its own SiteGuard option rows
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
				$batch_preserved_sg[$target_id] = $this->read_plugin_option_rows($target_prefix . 'options');
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

				// Decrypt if needed (normally already done up front by predecrypt_db_files)
				if (preg_match('/\.crypt$/', $db_file)) {
					$file_path = isset($predecrypted[$db_file]) ? $predecrypted[$db_file] : $this->decrypt_file($file_path);
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
						@set_time_limit(SGBACKUP_SET_TIME_LIMIT); $this->progress("Database import: {$lines_done} lines processed");
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
				$src_prefix = ((int) $source_id > 1) ? $base_prefix . $source_id . '_' : $base_prefix;
				$this->fix_prefixed_option_names($src_prefix, $base_prefix . $target_id . '_', $target_id);
				$this->replace_plugin_option_rows($base_prefix . $target_id . '_options',
					isset($batch_preserved_sg[$target_id]) ? $batch_preserved_sg[$target_id] : array(), $target_id);
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
					if ($old_domain !== $new_domain && strpos($new_domain, $old_domain) === false) {
						$search[] = $old_domain;
						$replace[] = $new_domain;
					}

					// Uploads path remap when the blog id changed (same pairs as the
					// single subsite import) - without it every image 404s after a
					// batch import onto different blog ids.
					if ((int) $source_id !== (int) $target_id) {
						foreach (array(
							array('/wp-content/uploads/sites/' . $source_id . '/', '/wp-content/uploads/sites/' . $target_id . '/'),
							array('\/wp-content\/uploads\/sites\/' . $source_id . '\/', '\/wp-content\/uploads\/sites\/' . $target_id . '\/'),
							array('wp-content/uploads/sites/' . $source_id . '/', 'wp-content/uploads/sites/' . $target_id . '/'),
						) as $pp) {
							if (!in_array($pp[0], $search, true)) { $search[] = $pp[0]; $replace[] = $pp[1]; }
						}
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
	 * Restore a multisite subsite's backup into this single site.
	 *
	 * Imports the subsite's wp_N_* tables as wp_*, keeps this site's URL, users,
	 * SiteGuard settings and scheduled events, merges the source's users (by
	 * email, created when missing, with their subsite roles), rewrites the
	 * subsite's URLs (including its path and its uploads/sites/N/ base) to this
	 * site, and keeps the plugins the subsite relied on active.
	 */
	private function restore_subsite_into_single($backup_dir) {
		global $wpdb;
		$out = array('success' => true, 'errors' => array(), 'messages' => array());
		$base = $wpdb->base_prefix;
		$src_id = (int) ($this->source_blog_id ? $this->source_blog_id : (isset($this->backup_set['subsite_blog_id']) ? $this->backup_set['subsite_blog_id'] : 0));
		if ($src_id < 2) {
			$out['success'] = false;
			$out['errors'][] = 'Could not determine which subsite this backup came from.';
			return $out;
		}
		if (empty($this->restore_options['db']) || empty($this->backup_set['db'])) {
			return $out; // files only
		}
		$src_prefix = $base . $src_id . '_';
		$dst_siteurl = untrailingslashit(get_option('siteurl'));
		$dst_home = untrailingslashit(get_option('home'));
		$preserved = $this->read_plugin_option_rows($base . 'options');

		$db_files = (array) $this->backup_set['db'];
		$predecrypted = $this->predecrypt_db_files($backup_dir);
		if (is_wp_error($predecrypted)) {
			$out['success'] = false;
			$out['errors'][] = $predecrypted->get_error_message();
			return $out;
		}
		$paths = array();
		foreach ($db_files as $db_file) {
			$paths[] = isset($predecrypted[$db_file]) ? $predecrypted[$db_file] : $backup_dir . $db_file;
		}

		$this->progress("Importing database: remapping {$src_prefix}* -> {$base}*");
		$tables = 0;
		foreach ($paths as $path) {
			$r = SiteGuard_Backup_Database_Utility::execute_sql_file_remap_prefix($path, $src_prefix, $base, $base);
			$tables += (int) $r['tables_restored'];
			if (!$r['success']) {
				$out['success'] = false;
				$out['errors'] = array_merge($out['errors'], (array) $r['errors']);
			}
		}
		if (!$out['success'] || 0 === $tables) {
			$out['success'] = false;
			if (0 === $tables) $out['errors'][] = "No {$src_prefix}* tables were found in the backup.";
			return $out;
		}
		$out['messages'][] = "Database: {$tables} tables imported from subsite #{$src_id}";
		wp_cache_flush();

		// The subsite's options table now holds the SOURCE's URL; remember it first.
		$old_siteurl = untrailingslashit((string) $wpdb->get_var("SELECT option_value FROM {$base}options WHERE option_name = 'siteurl'"));
		if (!empty($this->backup_set['subsite_url'])) {
			$old_siteurl = untrailingslashit($this->backup_set['subsite_url']);
		}

		$this->merge_subsite_users($src_id, 1, $backup_dir, $paths);
		$this->fix_prefixed_option_names($src_prefix, $base, 1);
		// The subsite's health unit, read before its SiteGuard rows are replaced by
		// this site's, then decided like any other restore: onto a site without a
		// key, the subsite's key is kept instead of always being dropped.
		$sub_autoload = array();
		$sub_health = $this->read_health_unit_rows($base . 'options', false, $sub_autoload);
		$this->replace_plugin_option_rows($base . 'options', $preserved, 1);
		$this->apply_health_key_policy_rows($base . 'options', $preserved, $sub_health, $sub_autoload, $old_siteurl, $dst_home);

		// URL rewrite: most specific first (the subsite's uploads base).
		$search = array();
		$replace = array();
		$add = function ($from, $to) use (&$search, &$replace) {
			if ('' === $from || $from === $to || in_array($from, $search, true)) return;
			$search[] = $from;
			$replace[] = $to;
		};
		if ($old_siteurl) {
			$bare_old = preg_replace('#^https?://#i', '', $old_siteurl);
			$old_host = parse_url($old_siteurl, PHP_URL_HOST);
			$network_root = $old_host; // uploads of a subdirectory subsite live under the network root too
			foreach (array_unique(array($bare_old, $network_root)) as $b) {
				foreach (array('https://', 'http://') as $sch) {
					$add($sch . $b . '/wp-content/uploads/sites/' . $src_id . '/', $dst_siteurl . '/wp-content/uploads/');
					$add(str_replace('/', '\\/', $sch . $b . '/wp-content/uploads/sites/' . $src_id . '/'), str_replace('/', '\\/', $dst_siteurl . '/wp-content/uploads/'));
				}
			}
			$add('/wp-content/uploads/sites/' . $src_id . '/', '/wp-content/uploads/');
			foreach (array('https://', 'http://') as $sch) {
				$add($sch . $bare_old, $dst_home);
				$add(str_replace('/', '\\/', $sch . $bare_old), str_replace('/', '\\/', $dst_home));
			}
			$add('//' . $bare_old, preg_replace('#^https?:#i', '', $dst_home));
			$new_host = parse_url($dst_home, PHP_URL_HOST);
			if ($old_host && $new_host && $old_host !== $new_host) {
				$add($old_host, $new_host);
			}
		}
		if ($search) {
			require_once SGBACKUP_DIR . 'includes/class-search-replace.php';
			$emails = array();
			foreach (array('admin_email', 'new_admin_email') as $ek) {
				$emails[$ek] = get_option($ek);
			}
			$sr_tables = array_values(array_diff(SiteGuard_Backup_Database_Utility::get_tables($base), array($base . 'users', $base . 'usermeta')));
			$sr = new SiteGuard_Backup_Search_Replace();
			$report = $sr->run($search, $replace, $sr_tables);
			$out['messages'][] = "Search-replace: {$report['changes']} changes ({$old_siteurl} -> {$dst_home})";
			$this->sgbackup->log("Subsite-to-single search-replace: " . wp_json_encode($search) . ' -> ' . wp_json_encode($replace) . ": {$report['changes']} changes");
			foreach ($emails as $ek => $ev) {
				if (false !== $ev) update_option($ek, $ev);
			}
		}
		// This site keeps its own address whatever the SR did.
		update_option('siteurl', $dst_siteurl);
		update_option('home', $dst_home);

		// Plugins the subsite ran: its own list plus what was network-active for it,
		// and always this plugin (it was network-active on the source, so it is not
		// in the subsite's own list, and dropping it would disable backups here).
		$active = (array) get_option('active_plugins', array());
		if (!empty($this->backup_set['subsite_active_sitewide_plugins']) && is_array($this->backup_set['subsite_active_sitewide_plugins'])) {
			$active = array_merge($active, $this->backup_set['subsite_active_sitewide_plugins']);
		}
		$active[] = plugin_basename(SGBACKUP_DIR . 'siteguardbackup.php');
		$active = array_values(array_unique(array_filter(array_map('strval', $active), function ($p) {
			return 0 === validate_file($p);
		})));
		update_option('active_plugins', $active);

		$this->is_migration = true;
		$this->old_siteurl = $old_siteurl;
		$this->save_restore_progress('db', 'completed');
		$this->sgbackup->log("Subsite #{$src_id} converted into this single site ({$old_siteurl} -> {$dst_home})");
		return $out;
	}

	/**
	 * This plugin's own option rows (settings, history, job state) in an options table.
	 */
	private function read_plugin_option_rows($table) {
		global $wpdb;
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return array();
		$rows = (array) $wpdb->get_results($wpdb->prepare(
			"SELECT option_name, option_value, autoload FROM `{$table}` WHERE option_name LIKE %s",
			$wpdb->esc_like('sgbackup_') . '%'
		), ARRAY_A);
		// This site's own scheduled SiteGuard events (kept under a pseudo row).
		$cron = @unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", 'cron')), array('allowed_classes' => false));
		$rows['__cron'] = is_array($cron) ? $this->plugin_cron_events($cron, true) : array();
		return $rows;
	}

	/**
	 * Keep ($only_plugin) or drop (!$only_plugin) this plugin's hooks in a cron array.
	 */
	private function plugin_cron_events($cron, $only_plugin) {
		$out = array();
		foreach ($cron as $time => $hooks) {
			if (!is_array($hooks)) {
				if (!$only_plugin) $out[$time] = $hooks; // 'version' => 2
				continue;
			}
			foreach ($hooks as $hook => $events) {
				if ((0 === strpos((string) $hook, 'sgbackup_')) === $only_plugin) {
					$out[$time][$hook] = $events;
				}
			}
		}
		return $out;
	}

	/**
	 * After importing another site's options table into a subsite, drop the SOURCE's
	 * SiteGuard rows and put back the target's own (none for a new subsite).
	 *
	 * The source's rows carried its in-flight job marker (the dump is taken while
	 * the backup job runs), so every backup on the imported subsite failed with
	 * "another job is already running"; they also carried its remote-storage
	 * credentials and retention settings into a different site.
	 */
	private function replace_plugin_option_rows($table, $preserved, $target_id) {
		global $wpdb;
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;
		$removed = $wpdb->query($wpdb->prepare(
			"DELETE FROM `{$table}` WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like('sgbackup_') . '%',
			$wpdb->esc_like('_transient_sgbackup_') . '%',
			$wpdb->esc_like('_transient_timeout_sgbackup_') . '%'
		));
		$own_cron = isset($preserved['__cron']) ? $preserved['__cron'] : array();
		unset($preserved['__cron']);
		// The imported cron array holds the SOURCE's scheduled SiteGuard events,
		// including the resumption of the very backup being dumped, which made the
		// job scheduler report a running job forever. Keep only other hooks, then
		// add back this site's own SiteGuard events.
		$cron = @unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", 'cron')), array('allowed_classes' => false));
		if (is_array($cron)) {
			$cron = $this->plugin_cron_events($cron, false);
			foreach ($own_cron as $time => $hooks) {
				foreach ($hooks as $hook => $events) {
					$cron[$time][$hook] = $events;
				}
			}
			ksort($cron);
			$wpdb->update($table, array('option_value' => serialize($cron)), array('option_name' => 'cron'));
		}
		foreach ((array) $preserved as $row) {
			$wpdb->insert($table, array(
				'option_name'  => $row['option_name'],
				'option_value' => $row['option_value'],
				'autoload'     => $row['autoload'],
			));
		}
		if (function_exists('clean_blog_cache')) clean_blog_cache((int) $target_id);
		$this->sgbackup->log("Blog #{$target_id}: replaced {$removed} SiteGuard option row(s) from the source with " . count((array) $preserved) . " of the target's own");
	}

	/**
	 * Rename options whose NAME carries the table prefix after a prefix-remapped import.
	 *
	 * Table names are remapped (wp_* -> wp_4_*) but option names inside them are
	 * not, so the imported roles stay under "wp_user_roles" while WordPress reads
	 * "wp_4_user_roles": the subsite ends up with no roles and nobody can
	 * administer it. Falls back to the default roles if none came across.
	 */
	private function fix_prefixed_option_names($source_prefix, $target_prefix, $target_id) {
		global $wpdb;
		if ($source_prefix === $target_prefix) return;
		$table = $target_prefix . 'options';
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return;

		$from = $source_prefix . 'user_roles';
		$to   = $target_prefix . 'user_roles';
		$has_from = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE option_name = %s", $from));
		if ($has_from) {
			$wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE option_name = %s", $to));
			$wpdb->query($wpdb->prepare("UPDATE `{$table}` SET option_name = %s WHERE option_name = %s", $to, $from));
			$this->sgbackup->log("Renamed option {$from} -> {$to} in blog #{$target_id}");
		}

		$has_roles = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE option_name = %s", $to));
		if (!$has_roles) {
			if (!function_exists('populate_roles')) {
				require_once ABSPATH . 'wp-admin/includes/schema.php';
			}
			if (is_multisite()) switch_to_blog((int) $target_id);
			populate_roles();
			if (is_multisite()) restore_current_blog();
			$this->sgbackup->log("No roles came across for blog #{$target_id}; installed the default roles", 'warning');
		}
		if (function_exists('clean_blog_cache')) {
			clean_blog_cache((int) $target_id);
		}
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
		// Main site / single site keep the bare prefix (wp_capabilities, wp_posts).
		$target_prefix = ((int) $target_id > 1) ? $base_prefix . $target_id . '_' : $base_prefix;
		$target_cap_key = $target_prefix . 'capabilities';

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
				// Cap the read so a pathologically long line (huge serialized blob)
				// can't exhaust memory. A capped fragment lacks a trailing newline;
				// we still do cheap context tracking but skip the heavy regexes on it.
				$line = $is_gz ? gzgets($fh, 1048576) : fgets($fh, 1048576);
				if (false === $line) break;
				$line_capped = (strlen($line) >= 1048575 && substr($line, -1) !== "\n");

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
					if (!$line_capped && preg_match_all("/\(\s*'?(\d+)'?\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*'([^']*)'/", $line, $matches, PREG_SET_ORDER)) {
						foreach ($matches as $m) {
							$backup_users[$m[5]] = array(
								'old_id'   => intval($m[1]),
								'login'    => stripslashes($m[2]),
								'pass'     => stripslashes($m[3]),
								'nicename' => stripslashes($m[4]),
								'email'    => stripslashes($m[5]),
							);
						}
					}
					// End of statement is a line ending in ");". A bare ';' also occurs
					// inside values (display names, serialized data).
					if (preg_match('/\)\s*;\s*$/', $line)) {
						$in_users_insert = false;
					}
				}

				if ($in_usermeta_insert) {
					// Extract capability rows: (..., user_id, 'wp_capabilities', 'a:1:{...}')
					// Only parse lines that contain our target capability key
					if (!$line_capped && (strpos($line, $escaped_cap_key) !== false || strpos($line, $source_cap_key) !== false)) {
						$cap_pattern = "/\(\s*'?\d+'?\s*,\s*'?(\d+)'?\s*,\s*'" . preg_quote($source_cap_key, '/') . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/";
						if (preg_match_all($cap_pattern, $line, $cap_matches, PREG_SET_ORDER)) {
							foreach ($cap_matches as $cm) {
								$backup_caps[intval($cm[1])] = stripslashes($cm[2]);
							}
						}
					}
					// Serialized capabilities always contain ';' (a:1:{s:6:"editor";b:1;}),
					// so ending the statement at the first ';' dropped every row after
					// the first capability entry and imported users as subscribers.
					if (preg_match('/\)\s*;\s*$/', $line)) {
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
		$created = array();
		foreach ($backup_users as $email => $user_data) {
			$existing = get_user_by('email', $email);
			if ($existing) {
				$id_map[$user_data['old_id']] = $existing->ID;
			} else {
				$existing_by_login = get_user_by('login', $user_data['login']);
				if ($existing_by_login) {
					$id_map[$user_data['old_id']] = $existing_by_login->ID;
					continue;
				}
				// Not on the destination at all: create the account (the source's
				// user tables are never imported, so otherwise every author,
				// editor and customer of the source site was silently dropped).
				$new_id = wp_insert_user(array(
					'user_login'    => $user_data['login'],
					'user_email'    => $email,
					'user_nicename' => $user_data['nicename'],
					'user_pass'     => wp_generate_password(32),
					'role'          => '',
				));
				if (is_wp_error($new_id)) {
					$this->sgbackup->log("User merge: could not create {$user_data['login']}: " . $new_id->get_error_message(), 'warning');
					continue;
				}
				if ('' !== $user_data['pass']) {
					// Keep the source password hash so the user's existing password works.
					$wpdb->update($wpdb->users, array('user_pass' => $user_data['pass']), array('ID' => $new_id));
					clean_user_cache($new_id);
				}
				if (is_multisite()) {
					// wp_insert_user() adds the user to the current blog; membership is
					// set from the source capabilities below.
					delete_user_meta($new_id, $wpdb->get_blog_prefix(get_current_blog_id()) . 'capabilities');
					delete_user_meta($new_id, $wpdb->get_blog_prefix(get_current_blog_id()) . 'user_level');
				}
				$id_map[$user_data['old_id']] = $new_id;
				$created[$new_id] = true;
				$this->sgbackup->log("User merge: created {$user_data['login']} (#{$new_id}, was #{$user_data['old_id']})");
			}
		}

		// Give every mapped user their source role on the target. An existing
		// destination user keeps any role they already have there.
		foreach ($id_map as $old_id => $new_id) {
			if (empty($created[$new_id]) && get_user_meta($new_id, $target_cap_key, true)) {
				continue;
			}
			$caps = isset($backup_caps[$old_id]) ? maybe_unserialize($backup_caps[$old_id]) : null;
			if (!is_array($caps) || !$caps) {
				$caps = array('subscriber' => true);
			}
			update_user_meta($new_id, $target_cap_key, $caps);
			$level = 0;
			foreach (array_keys($caps) as $role_name) {
				$role = get_role($role_name);
				if ($role) {
					foreach ($role->capabilities as $cap => $granted) {
						if ($granted && preg_match('/^level_(\d+)$/', $cap, $lm)) $level = max($level, (int) $lm[1]);
					}
				}
			}
			update_user_meta($new_id, $target_prefix . 'user_level', $level);
			clean_user_cache($new_id);
		}

		// Update user IDs in subsite tables (e.g., post_author, comment_user_id)
		if (!empty($id_map)) {
			$target_posts = $target_prefix . 'posts';
			$target_comments = $target_prefix . 'comments';

			// Two phases through an offset: remapping 2->3 and then 3->2 in place
			// merged both authors into one.
			$offset = 1000000000000;
			$has_posts = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target_posts));
			$has_comments = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $target_comments));
			foreach ($id_map as $old_id => $new_id) {
				if ((int) $old_id === (int) $new_id) continue;
				if ($has_posts) {
					$wpdb->query($wpdb->prepare("UPDATE `{$target_posts}` SET post_author = %d WHERE post_author = %d", $new_id + $offset, $old_id));
				}
				if ($has_comments) {
					$wpdb->query($wpdb->prepare("UPDATE `{$target_comments}` SET user_id = %d WHERE user_id = %d", $new_id + $offset, $old_id));
				}
			}
			if ($has_posts) {
				$wpdb->query($wpdb->prepare("UPDATE `{$target_posts}` SET post_author = post_author - %d WHERE post_author >= %d", $offset, $offset));
			}
			if ($has_comments) {
				$wpdb->query($wpdb->prepare("UPDATE `{$target_comments}` SET user_id = user_id - %d WHERE user_id >= %d", $offset, $offset));
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
		// Use the NETWORK uploads base, not the current site's upload dir.
		// wp_upload_dir() returns a site-specific path (e.g. uploads/sites/19/)
		// when running in a subsite context, which would double the sites/{id}/ prefix.
		if (is_multisite()) {
			switch_to_blog(get_main_site_id());
			$uploads_base = SiteGuard_Backup_Filesystem::wp_uploads_dir();
			restore_current_blog();
		} else {
			$uploads_base = SiteGuard_Backup_Filesystem::wp_uploads_dir();
		}
		// Blog 1 (a single site, or a network's main site) keeps uploads at the root.
		$target_dir = ((int) $target_id > 1)
			? trailingslashit($uploads_base) . 'sites/' . $target_id . '/'
			: trailingslashit($uploads_base);

		if (!file_exists($target_dir)) {
			wp_mkdir_p($target_dir);
		}

		$uploads_ok = true;
		foreach ($upload_files as $zip_file) {
			$zip_path = $backup_dir . $zip_file;
			if (!file_exists($zip_path)) { $uploads_ok = false; continue; }

			// Encrypted archive: decrypt first (was handed straight to unzip and
			// silently "restored").
			if (preg_match('/\.crypt$/', $zip_file)) {
				$zip_path = $this->decrypt_file($zip_path);
				if (!$zip_path) {
					$this->sgbackup->log("Failed to decrypt uploads archive: {$zip_file}", 'error');
					$uploads_ok = false;
					continue;
				}
			}

			// Extract to a temp directory first. uniqid() defends against two
			// upload archives extracted within the same second colliding.
			$temp_extract = $backup_dir . 'temp_uploads_' . time() . '_' . uniqid() . '/';
			wp_mkdir_p($temp_extract);

			// Disk-space precheck: uploads archives are the largest artefact in a
			// backup. Extracting onto a full disk corrupts the site; abort cleanly.
			$needed = $this->zip_uncompressed_size($zip_path);
			if ($needed > 0) {
				$free = @disk_free_space($backup_dir);
				if ($free !== false && $free < $needed * 1.1) {
					$this->sgbackup->log(sprintf(
						"[Restore] Not enough free disk space for uploads %s: need ~%s, only %s free. Skipping.",
						$zip_file, size_format($needed), size_format($free)
					), 'error');
					$this->progress("Uploads restore skipped: insufficient disk space for {$zip_file}");
					$this->remove_dir_recursive($temp_extract, $backup_dir);
					continue;
				}
			}

			$extract_ok = $this->unzip($zip_path, $temp_extract);
			if (!$extract_ok) {
				$this->sgbackup->log("Failed to extract uploads zip: {$zip_file}", 'error');
				$this->remove_dir_recursive($temp_extract, $backup_dir);
				$uploads_ok = false;
				continue;
			}

			// Two-phase move: multisite backups store uploads in sites/{id}/,
			// single-site backups store them at root level. Use rename-based moves
			// (fall back to copy across filesystems) so we don't need a second full
			// copy of multi-GB uploads sitting on disk at once.
			$source_subdir = $temp_extract . 'sites/' . $source_id . '/';
			if (is_dir($source_subdir)) {
				// Multisite subsite backup: move from sites/{source_id}/
				$this->move_dir_recursive($source_subdir, $target_dir);
			} else {
				// Single-site backup: copy root-level files, excluding sites/ dir
				// (which would contain OTHER subsites' uploads)
				$this->copy_dir_recursive_exclude($temp_extract, $target_dir, array('sites'));
			}

			$this->remove_dir_recursive($temp_extract, $backup_dir);
			$this->sgbackup->log("Restored uploads from {$zip_file} to /sites/{$target_id}/");
		}

		return $uploads_ok;
	}

	/**
	 * Decrypt every encrypted DB archive of the backup set up front.
	 *
	 * @return array|WP_Error Map of db filename => decrypted path (only .crypt
	 *                        entries), or WP_Error if any could not be decrypted.
	 */
	private function predecrypt_db_files($backup_dir) {
		$map = array();
		if (empty($this->restore_options['db']) || empty($this->backup_set['db'])) return $map;
		$db_files = $this->backup_set['db'];
		if (is_string($db_files)) $db_files = array($db_files);
		foreach ($db_files as $db_file) {
			if (!preg_match('/\.crypt$/', $db_file)) continue;
			$path = $backup_dir . $db_file;
			if (!file_exists($path)) continue; // reported by the caller's own checks
			$dec = $this->decrypt_file($path);
			if (!$dec) {
				return new WP_Error('decrypt_failed', "Failed to decrypt {$db_file} (wrong passphrase?) - aborting before any target tables are touched");
			}
			$map[$db_file] = $dec;
		}
		return $map;
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
	 * Recursively copy a directory, excluding specified top-level subdirectories.
	 *
	 * @param string $source Source directory path.
	 * @param string $dest   Destination directory path.
	 * @param array  $exclude Top-level directory names to skip (e.g., array('sites')).
	 */
	private function copy_dir_recursive_exclude($source, $dest, $exclude = array()) {
		if (!is_dir($source)) return;
		if (!is_dir($dest)) wp_mkdir_p($dest);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$source_len = strlen(rtrim($source, '/\\')) + 1;

		foreach ($iterator as $item) {
			$relative = substr($item->getPathname(), $source_len);
			// Skip excluded top-level directories
			foreach ($exclude as $ex) {
				if (strpos($relative, $ex . DIRECTORY_SEPARATOR) === 0 || $relative === $ex) {
					continue 2;
				}
			}
			$target = $dest . DIRECTORY_SEPARATOR . $relative;
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
	private function remove_dir_recursive($dir, $root = null) {
		if (null === $root) {
			$root = SiteGuard_Backup_Options::instance()->get_backup_dir();
		}
		$r = SiteGuard_Backup_Filesystem::delete_tree($dir, $root);
		if (is_wp_error($r)) {
			$this->sgbackup->log("Could not remove directory {$dir}: " . $r->get_error_message(), 'error');
		}
		return $r;
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
			// Cap the read to bound memory on huge single-line dumps. wp_blogs rows
			// are tiny, so a capped fragment (no trailing newline) can't hold a full
			// blogs INSERT worth parsing - skip the heavy regex on it.
			$line = $is_gz ? gzgets($fh, 1048576) : fgets($fh, 1048576);
			if (false === $line) break;
			$line_capped = (strlen($line) >= 1048575 && substr($line, -1) !== "\n");
			$trimmed = trim($line);

			// Detect INSERT INTO wp_blogs (or wp_N_blogs with a prefix)
			if (preg_match('/^INSERT INTO\s+`[^`]*blogs`/i', $trimmed)) {
				$in_blogs_insert = true;
			}

			// Extract (blog_id, site_id, domain, ...) value tuples
			if ($in_blogs_insert && !$line_capped) {
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

		// Only numbers that are real blog ids in the backup mark a subsite table:
		// main-site plugin tables such as wp_404_to_301 match wp_N_ too, and were
		// dropped from the import as "blog 404".
		$known_blog_ids = array_map('intval', array_keys($source_blogs));

		return array_values(array_filter($all_tables, function($table) use ($prefix_pattern, $import_prefixes, $main_site_skipped, $base_prefix, $multisite_infra, $known_blog_ids) {
			// Check if this is a blog-specific table (wp_N_*)
			if (preg_match($prefix_pattern, $table, $m) && in_array((int) $m[1], $known_blog_ids, true)) {
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
				if ($this->is_lock_row($row['meta_key'])) continue;
				if (in_array($row['meta_key'], array('sgbackup_restore_sr_state', 'sgbackup_sr_progress', self::PRESERVED_OPTION), true)) continue;
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
				if ($this->is_lock_row($row['option_name'])) continue;
				if (in_array($row['option_name'], array('sgbackup_restore_sr_state', 'sgbackup_sr_progress', self::PRESERVED_OPTION), true)) continue;
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
				if ($this->is_lock_row($row['option_name'])) continue;
				if (in_array($row['option_name'], array('sgbackup_restore_sr_state', 'sgbackup_sr_progress', self::PRESERVED_OPTION), true)) continue;
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
	 * Whether an sgbackup_* row is a lock. The snapshot must never carry one: once
	 * it became durable, a resumed or continued restore wrote the interrupted
	 * run's "sgbackup_restore" row back, its own unlock() did not match it, and
	 * every later restore was refused for up to an hour.
	 *
	 * @param string $name Row name, bare or 'options:'/'sitemeta:' prefixed.
	 */
	private function is_lock_row($name) {
		$name = preg_replace('/^(options|sitemeta):/', '', (string) $name);
		return strpos($name, 'sgbackup_sem_') === 0 || in_array($name, self::LOCK_ROWS, true);
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
		// Memoize per resolved path: this scanner is one of several full gz
		// decompressions per restore and its side effects (old_siteurl, is_migration)
		// persist on the instance, so a repeat call over the same file is wasted work.
		if (isset($this->scanned_url_files[$file_path])) return;
		$this->scanned_url_files[$file_path] = true;

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
			// Cap the read so a single giant serialized-option line can't OOM the
			// scanner. siteurl/home/prefix live in short rows, so a capped fragment
			// (no trailing newline) is safe to skip for the value regexes below.
			$line = $is_gz ? gzgets($fh, 1048576) : fgets($fh, 1048576);
			if (false === $line) break;
			$line_capped = (strlen($line) >= 1048575 && substr($line, -1) !== "\n");

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
			if (!$line_capped && ($in_options_insert || preg_match('/^INSERT INTO\s+`([^`]*options)`/i', $trimmed, $ins_m2))) {
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
	/**
	 * Run the migration search-replace.
	 *
	 * Resumable across requests: the computed pairs + the pre-SR captures
	 * (site_admins, email options, real destination URLs) are persisted once, and
	 * the SR engine checkpoints its per-table cursor. Under AJAX a per-request
	 * deadline makes it stop and persist before a proxy timeout; the resume path
	 * (restore_database) calls this again to continue from the cursor.
	 *
	 * @return string 'complete' when the search-replace fully finished (or was a
	 *                no-op), or 'incomplete' when it checkpointed at the deadline.
	 */
	private function do_search_replace() {
		require_once SGBACKUP_DIR . 'includes/class-search-replace.php';
		global $wpdb;
		$opts = SiteGuard_Backup_Options::instance();

		// Resume: a prior request already built the pairs and started SR but hit the
		// request deadline. Reload the EXACT pairs and pre-SR captures - recomputing
		// them now would read a DB that has already been partly rewritten (corrupting
		// the captured admin_email / site_admins). Presence of state == resuming, but
		// only if it belongs to THIS backup - a stale checkpoint from an abandoned
		// migration of a different backup must never be reused (it would rewrite with
		// the wrong URLs). On mismatch, discard it and rebuild from scratch.
		$this_ts = (string) $this->restore_timestamp();
		$state = $opts->get_option($this->sr_state_option, null);
		if (is_array($state) && isset($state['timestamp']) && (string) $state['timestamp'] !== $this_ts) {
			$this->sgbackup->log("Discarding stale search-replace checkpoint (belongs to backup {$state['timestamp']}, restoring {$this_ts})", 'warning');
			$opts->delete_option($this->sr_state_option);
			$state = null;
		}
		$resuming = (is_array($state) && !empty($state['search']) && !empty($state['tables']));
		$sr_checkpoint_saved = true;

		if ($resuming) {
			$search                  = $state['search'];
			$replace                 = $state['replace'];
			$tables                  = $state['tables'];
			$real_dest_siteurl       = isset($state['real_dest_siteurl']) ? $state['real_dest_siteurl'] : '';
			$real_dest_home          = isset($state['real_dest_home']) ? $state['real_dest_home'] : '';
			$saved_site_admins       = array_key_exists('saved_site_admins', $state) ? $state['saved_site_admins'] : null;
			$preserved_email_options = isset($state['preserved_email_options']) ? $state['preserved_email_options'] : array();
			if (!empty($state['instance_state']) && is_array($state['instance_state'])) {
				foreach ($state['instance_state'] as $prop => $val) {
					if (property_exists($this, $prop)) $this->$prop = $val;
				}
			}
			$this->sgbackup->log("Resuming search-replace: " . count($search) . " pairs across " . count($tables) . " tables (from checkpoint)");
		} else {
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
				return 'complete';
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

			$tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);

			// Exclude user tables from search-replace during migrations to preserve
			// destination user logins, emails, and credentials
			if ($this->is_migration) {
				$user_tables = array($wpdb->users, $wpdb->usermeta);
				$tables = array_values(array_diff($tables, $user_tables));
				$this->sgbackup->log("Search-replace: excluding user tables (" . implode(', ', $user_tables) . ") to preserve destination accounts");
			}

			// Save site_admins before search-replace - it contains user_login values
			// that must stay in sync with wp_users (which we preserve during migration)
			$saved_site_admins = null;
			if ($this->is_migration && is_multisite()) {
				$sitemeta_table = $wpdb->base_prefix . 'sitemeta';
				$saved_site_admins = $wpdb->get_var($wpdb->prepare(
					"SELECT meta_value FROM {$sitemeta_table} WHERE meta_key = %s", 'site_admins'
				));
			}

			// Email-bearing option values must never be rewritten by the bare-domain
			// pair: "info@olddomain.com" would become "info@newhost" and silently break
			// the site's mail. Capture the post-import values now and restore any that
			// search-replace touched afterward. These options never legitimately contain
			// a site URL, so restoring is always safe.
			$email_option_keys = array('admin_email', 'new_admin_email', 'woocommerce_email_from_address', 'mailserver_url', 'mailserver_login', 'mailserver_pass');
			$preserved_email_options = array();
			foreach ($email_option_keys as $eok) {
				$eval = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $eok));
				if ($eval !== null) $preserved_email_options[$eok] = $eval;
			}

			// Persist everything the resume path needs so a checkpointed run can
			// continue with the identical pairs and one-time captures.
			$sr_state_saved = $opts->update_option($this->sr_state_option, array(
				'timestamp'               => $this_ts,
				'search'                  => $search,
				'replace'                 => $replace,
				'tables'                  => $tables,
				'real_dest_siteurl'       => $real_dest_siteurl,
				'real_dest_home'          => $real_dest_home,
				'saved_site_admins'       => $saved_site_admins,
				'preserved_email_options' => $preserved_email_options,
				// Instance state a resumed run (fresh object) needs for the post-SR
				// fixups: without it restore_plugin_options() and
				// fix_multisite_sitemeta() silently no-op on resume.
				'instance_state'          => array(
					'pre_import_siteurl'          => $this->pre_import_siteurl,
					'pre_import_home'             => $this->pre_import_home,
					'pre_import_content_url'      => $this->pre_import_content_url,
					'pre_import_uploads_url'      => $this->pre_import_uploads_url,
					'pre_import_blogs'            => $this->pre_import_blogs,
					'pre_import_sgbackup_options' => $this->pre_import_sgbackup_options,
					'is_migration'                => $this->is_migration,
					'user_tables_skipped'         => $this->user_tables_skipped,
					'old_siteurl'                 => $this->old_siteurl,
					'old_home'                    => $this->old_home,
					// A resumed network migration still needs these for the
					// subsites' own SiteGuard rows (cleanup_imported_cron_events).
					'network_import'              => $this->network_import,
					'pre_import_subsite_sg'       => $this->pre_import_subsite_sg,
					// The key decision was made right after the import; a resume
					// must neither redo nor undo it.
					'health_applied'              => $this->health_applied,
					'health_key_policy'           => $this->health_key_policy,
					'health_outcome'              => $this->health_outcome,
				),
			), false);
			// Without a checkpoint (e.g. a row over max_allowed_packet) the browser's
			// continuation finds none and imports the database again, forever.
			// Finish search-replace in this request instead.
			if (!$sr_state_saved && !is_array($opts->get_option($this->sr_state_option, null))) {
				$sr_checkpoint_saved = false;
				$this->sgbackup->log("[Restore] Could not save the search-replace checkpoint; finishing search-replace in this request", 'warning');
			}
		}

		// Under AJAX/browser, bound this pass so it checkpoints before a proxy idle
		// timeout; WP-CLI (no DOING_AJAX) passes no deadline and runs to completion.
		$deadline = null;
		if (defined('DOING_AJAX') && DOING_AJAX && $sr_checkpoint_saved) {
			$budget = (int) apply_filters('sgbackup_restore_sr_budget_seconds', 45);
			$deadline = $this->restore_started_at + max(15, $budget);
		}

		$sr = new SiteGuard_Backup_Search_Replace();
		$report = $sr->run($search, $replace, $tables, false, array(
			'deadline'     => $deadline,
			'resume'       => $resuming,
			'progress_key' => 'sgbackup_sr_progress',
			'progress_cb'  => function ($done, $total, $table) {
				$this->emit_sr_progress($done, $total, $table);
			},
		));

		// Checkpointed at the deadline - keep the persisted state + cursor and let
		// the caller resume. The one-time post-SR fixups below must run exactly once,
		// AFTER search-replace fully completes, so we do not run them here.
		if (isset($report['status']) && $report['status'] === 'incomplete') {
			$next = isset($report['next_table']) ? $report['next_table'] : '?';
			$done = isset($report['completed_tables']) ? count($report['completed_tables']) : 0;
			$this->sgbackup->log("Search-replace incomplete (time budget) - checkpointed before table {$next}; {$done} tables done, will resume");
			$this->progress("Search-replace checkpointed ({$done} tables done); resuming");
			return 'incomplete';
		}

		// Restore site_admins after search-replace to preserve super-admin grants
		if ($saved_site_admins !== null) {
			$wpdb->update(
				$wpdb->base_prefix . 'sitemeta',
				array('meta_value' => $saved_site_admins),
				array('meta_key' => 'site_admins'),
				array('%s'),
				array('%s')
			);
			$this->sgbackup->log("Restored site_admins after search-replace to preserve super-admin logins");
		}

		// Re-fix any email-bearing option corrupted by the bare-domain replace.
		foreach ($preserved_email_options as $eok => $orig) {
			$now = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $eok));
			if ($now !== null && $now !== $orig) {
				$wpdb->update($wpdb->options, array('option_value' => $orig), array('option_name' => $eok));
				$this->sgbackup->log("Restored option '{$eok}' after search-replace altered it ('{$now}' -> '{$orig}')");
			}
		}
		wp_cache_delete('alloptions', 'options');

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

		// Search-replace fully done - drop the persisted pairs/state (the SR engine
		// already cleared its own per-table cursor on completion).
		$opts->delete_option($this->sr_state_option);

		return 'complete';
	}

	/**
	 * Streaming keepalive + progress line emitted after each search-replace table.
	 * Without it, search-replace produces no output for the whole pass and a proxy
	 * closes the idle connection mid-run.
	 */
	private function emit_sr_progress($done, $total, $table) {
		@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
		$msg = "Search-replace: {$done}/{$total} tables processed";
		$this->progress($msg);
		if (defined('DOING_AJAX') && DOING_AJAX) {
			echo 'RINFO:' . json_encode(array(
				'type'    => 'sr_progress',
				'message' => $msg,
				'done'    => (int) $done,
				'total'   => (int) $total,
			)) . "\n";
			if (ob_get_level()) @ob_flush();
			@flush();
		}
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
		// Use the FIRST dns label as the new subdomain. Popping only the last label
		// mangled multi-label TLDs (foo.co.uk -> "foo.co.old-host.example"); taking
		// the first label yields "foo.old-host.example" for both .com and .co.uk.
		$parts = explode('.', $domain);
		$first = isset($parts[0]) && $parts[0] !== '' ? $parts[0] : $domain;
		$new_host = $first . '.' . $target_host;
		$this->sgbackup->log("Independent domain mapped: {$domain} -> {$new_host} (first label '{$first}' prefixed to {$target_host})");
		return $new_host;
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
			if (!in_array($old_domain, $search, true) && strpos($new_domain, $old_domain) === false) {
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

		// The source's blog domains, captured before the destination identity was
		// written back (see restore_database()); wp_blogs as a fallback.
		$blogs_table = $wpdb->base_prefix . 'blogs';
		$blogs = !empty($this->source_blog_domains) ? $this->source_blog_domains : $wpdb->get_col("SELECT DISTINCT domain FROM {$blogs_table}");
		// Never treat the destination's own domain as a source domain.
		$blogs = array_values(array_filter((array) $blogs, function ($d) use ($new_domain, $old_domain) {
			return $d !== $new_domain || $d === $old_domain;
		}));
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

		// Bare domain catch-all (LAST so specific replacements run first). Pairs are
		// applied sequentially to the same value, so when the new host CONTAINS the
		// old one (example.com -> www.example.com / example.com.au) this pair would
		// re-match the URL pairs' output and yield www.www.example.com - including in
		// the main site's siteurl/home. Skip it in that case.
		if (!in_array($old_domain, $search, true)) {
			if (strpos($new_domain, $old_domain) === false) {
				$search[] = $old_domain;
				$replace[] = $new_domain;
				$this->sgbackup->log("  Bare domain catch-all: {$old_domain} -> {$new_domain}");
			} else {
				$this->sgbackup->log("  Bare domain catch-all skipped: {$new_domain} contains {$old_domain} (would double-rewrite)");
			}
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

					if ($this->filtered_import_used) {
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
		// Decided by the import that actually ran, not by whether the destination
		// happened to have subsites before it.
		$use_filtered_mode = $this->filtered_import_used;

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

		// extradir_N means "the Nth configured extra directory", and that list can
		// change: restore only into the directory the archive was taken from.
		if (0 === strpos($entity, 'extradir_')) {
			$recorded = isset($this->backup_set['extra_dir_sources'][$entity]) ? (string) $this->backup_set['extra_dir_sources'][$entity] : '';
			$current = realpath($dest_dir);
			if ('' === $recorded || false === $current || untrailingslashit(wp_normalize_path($current)) !== untrailingslashit(wp_normalize_path((string) (realpath($recorded) ?: $recorded)))) {
				$msg = "Skipped {$entity}: it was backed up from " . ('' !== $recorded ? $recorded : 'an unrecorded directory')
					. ", which is not the directory now configured at that position ({$dest_dir}). Extract that archive manually.";
				$this->sgbackup->log("[Restore] {$msg}", 'warning');
				$this->progress($msg);
				return false;
			}
		}

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

			// Disk-space precheck: extracting a multi-GB archive onto a nearly-full
			// disk half-writes files and corrupts the install. Abort this entity
			// cleanly instead. (needed*1.1 leaves headroom for the later move.)
			$needed = $this->zip_uncompressed_size($zip_path);
			if ($needed > 0) {
				$free = @disk_free_space($backup_dir);
				if ($free !== false && $free < $needed * 1.1) {
					$dmsg = sprintf(
						"Not enough free disk space to restore %s: need ~%s (uncompressed), only %s free. Aborting %s restore.",
						$zip_file, size_format($needed), size_format($free), $entity
					);
					$this->sgbackup->log("[Restore] {$dmsg}", 'error');
					$this->progress($dmsg);
					$this->remove_temp_dir($temp_dir);
					return false;
				}
			}

			// Extract
			$extract_result = $this->unzip($zip_path, $temp_dir);
			if (!$extract_result) {
				$this->sgbackup->log("Failed to extract: {$zip_file}", 'error');
				$this->remove_temp_dir($temp_dir);
				return false;
			}

			// Filter extracted items if selective restore is requested
			if ($selected_items !== null) {
				$this->filter_extracted_items($temp_dir, $selected_items, $entity);
			}

			// wp-config.php belongs to the destination install (DB credentials,
			// salts, table prefix). Local-only backups include it for disaster
			// recovery, but restoring it over a running site swaps in the SOURCE's
			// credentials and takes the site down. Keep the archive copy for manual use.
			if ($entity === 'wpcore' && file_exists($temp_dir . 'wp-config.php')) {
				@unlink($temp_dir . 'wp-config.php');
				$this->sgbackup->log("wpcore: not restoring wp-config.php over the destination's - the archive copy remains in the backup zip for manual recovery");
				$this->progress("Skipped wp-config.php (destination config preserved)");
			}

			// Never restore over this plugin's own directory: it is the code running
			// this restore (swapping it mid-request mixes versions, and an older
			// backup would bring back older, possibly unsafe code), and it holds the
			// destination's config. Match the real install folder, not a fixed name.
			if ($entity === 'plugins') {
				foreach (array_unique(array(basename(untrailingslashit(SGBACKUP_DIR)), 'siteguardbackup')) as $self_name) {
					$self_dir = $temp_dir . $self_name;
					if (is_dir($self_dir)) {
						$this->remove_temp_dir($self_dir);
						$this->sgbackup->log("Excluded {$self_name}/ (SiteGuard Backup itself) from plugin restore");
						$this->progress("Excluded SiteGuard Backup plugin from restore (keeping the running version)");
					}
				}
			}

			// Move files to destination
			$move_result = $this->move_files($temp_dir, $dest_dir, $entity);
			if (!$move_result) {
				$this->sgbackup->log("Failed to move {$this->last_move_failures} file(s) for: {$entity}", 'error');
				$this->progress(ucfirst($entity) . ": {$this->last_move_failures} file(s) failed to move - restore of this entity is incomplete");
				$move_failed = true;
			}

			// Clean up temp dir
			$this->remove_temp_dir($temp_dir);
		}

		// A partial move must not be reported as a clean success - bail before
		// marking the entity 'completed' so a retry/resume re-runs it.
		if (!empty($move_failed)) {
			return false;
		}

		// After plugins restore: detect and remove duplicate plugins (same Plugin Name,
		// different directories) to prevent fatal "Cannot redeclare" errors
		if ($entity === 'plugins') {
			$this->remove_duplicate_plugins($dest_dir);
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
	 * Detect and remove duplicate plugins (same Plugin Name, different directories).
	 *
	 * After restoring the plugins entity, the backup may contain a plugin in a
	 * directory named differently than the destination's copy (e.g. "elementorpro"
	 * vs "elementor-pro"). Both get loaded by WordPress, causing fatal
	 * "Cannot redeclare" errors. This method scans for duplicates and removes
	 * the older version.
	 *
	 * @param string $plugins_dir Path to the wp-content/plugins/ directory.
	 */
	private function remove_duplicate_plugins($plugins_dir) {
		// Nothing is deleted here any more. Matching on the "Plugin Name" header
		// deleted unrelated directories that happen to share a name (e.g.
		// elementor-pro/ and elementorpro/), and, with "back up existing" mode,
		// deleted the freshly restored plugin whenever the renamed safety copy
		// had a higher version. Two copies only break a site ("Cannot redeclare")
		// when both are ACTIVE, so that is the only case acted on: the copy that
		// did not come from the backup is deactivated.
		$plugins_dir = trailingslashit($plugins_dir);
		$restored = isset($this->restored_top_items['plugins']) ? $this->restored_top_items['plugins'] : array();
		$by_name = array(); // Plugin Name => list of "dir/file.php"

		$dirs = @scandir($plugins_dir);
		if (!is_array($dirs)) return;
		foreach ($dirs as $dir) {
			if ($dir === '.' || $dir === '..' || !is_dir($plugins_dir . $dir)) continue;
			if (false !== strpos($dir, '.sgbackup-old-')) continue; // our own safety copies
			$plugin_files = glob($plugins_dir . $dir . '/*.php');
			if (!$plugin_files) continue;
			foreach ($plugin_files as $file) {
				$header = $this->read_plugin_header($file);
				if (empty($header['name'])) continue;
				$by_name[$header['name']][] = $dir . '/' . basename($file);
				break;
			}
		}

		$active = (array) get_option('active_plugins', array());
		$network = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
		$changed = false;
		$is_active = function ($p) use ($active, $network) {
			return in_array($p, $active, true) || isset($network[$p]);
		};

		foreach ($by_name as $name => $copies) {
			if (count($copies) < 2) continue;
			$this->sgbackup->log("Plugin '{$name}' is installed more than once: " . implode(', ', $copies), 'warning');
			$active_copies = array_values(array_filter($copies, $is_active));
			if (count($active_copies) < 2) continue;
			foreach ($active_copies as $p) {
				$dir = strtok($p, '/');
				if (in_array($dir, $restored, true)) continue; // keep what the backup restored
				$active = array_values(array_diff($active, array($p)));
				unset($network[$p]);
				$changed = true;
				$this->sgbackup->log("Deactivated duplicate copy {$p} of '{$name}' (both copies were active)", 'warning');
				$this->progress("Deactivated duplicate plugin copy: {$p}");
			}
		}
		if ($changed) {
			update_option('active_plugins', $active);
			if (is_multisite()) update_site_option('active_sitewide_plugins', $network);
		}
	}

	/**
	 * Read Plugin Name and Version from a PHP plugin file header.
	 *
	 * @param string $file Path to the PHP file.
	 * @return array('name' => string|null, 'version' => string|null)
	 */
	private function read_plugin_header($file) {
		$result = array('name' => null, 'version' => null);
		$fp = @fopen($file, 'r');
		if (!$fp) return $result;

		// Read first 8KB (WordPress standard for plugin headers)
		$data = fread($fp, 8192);
		fclose($fp);

		if (preg_match('/Plugin\s*Name:\s*(.+)/i', $data, $m)) {
			$result['name'] = trim($m[1]);
		}
		if (preg_match('/Version:\s*([0-9][^\s]*)/i', $data, $m)) {
			$result['version'] = trim($m[1]);
		}

		return $result;
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
				// File lists and metadata only. A resume after the DB import finds
				// the backup's history in place of this site's, so the set is kept
				// here for the resume entry points to fall back on.
				'backup_set' => $this->backup_set,
			);
		}

		$progress['entities'][$entity] = $status;
		$progress['last_update'] = time();

		// Save with 24-hour expiry so an interrupted restore can still be resumed
		// long after a browser/proxy timeout (was 1 hour - too short for large sites).
		$opts->set_transient('sgbackup_restore_progress', $progress, 86400);

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
		$this->last_move_failures = 0;
		if (!is_dir($source)) return false;
		if (!is_dir($dest)) wp_mkdir_p($dest);

		$mode = $this->move_in_mode;
		$moved_count = 0;
		$this->restored_top_items[$entity] = array_values(array_diff((array) @scandir($source), array('.', '..')));

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

				// Overwrite existing (modes 0, 1, 3 all overwrite individual files).
				// Never delete the live file before its replacement is complete:
				// the old code unlinked first, so a failed copy (disk full,
				// permissions) destroyed the file it was restoring over.
				if (!$this->place_file($item->getPathname(), $target)) {
					$this->last_move_failures++;
					$this->sgbackup->log("Failed to move file: {$relative} (existing file left in place)", 'error');
				}

				// Refresh the execution-time budget periodically on large trees.
				if ((++$moved_count % 200) === 0) {
					@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
				}
			}
		}

		// Honest result: any file we couldn't place makes this a partial move.
		return $this->last_move_failures === 0;
	}

	/**
	 * Put $src at $target, replacing any existing file only once the new copy is complete.
	 */
	private function place_file($src, $target) {
		// Same filesystem: rename() replaces the target atomically.
		if (@rename($src, $target)) return true;

		// Cross-filesystem: copy next to the target, verify, then swap in.
		$tmp = $target . '.sgbackup-tmp-' . wp_generate_password(6, false);
		if (!@copy($src, $tmp)) {
			if (file_exists($tmp)) @unlink($tmp);
			return false;
		}
		clearstatcache(true, $tmp);
		if (@filesize($tmp) !== @filesize($src)) {
			@unlink($tmp);
			return false;
		}
		if (!@rename($tmp, $target)) {
			// Some platforms (Windows) refuse to rename over an existing file.
			if (!file_exists($target) || !@unlink($target) || !@rename($tmp, $target)) {
				@unlink($tmp);
				return false;
			}
		}
		@unlink($src);
		return true;
	}

	/**
	 * Remove a temporary directory.
	 */
	private function remove_temp_dir($dir) {
		// Bounded to the backup directory; delete_tree() refuses anything else.
		$this->remove_dir_recursive($dir, SiteGuard_Backup_Options::instance()->get_backup_dir());
	}

	// ==========================================
	// POST-RESTORE
	// ==========================================

	/**
	 * Post-restore cleanup tasks.
	 */
	private function ensure_self_active() {
		$self = isset($this->self_activation['basename']) ? $this->self_activation['basename'] : '';
		if ('' === $self) return;
		if (!empty($this->self_activation['network']) && is_multisite()) {
			$net = (array) get_site_option('active_sitewide_plugins', array());
			if (!isset($net[$self])) {
				$net[$self] = time();
				update_site_option('active_sitewide_plugins', $net);
				$this->sgbackup->log("Re-activated SiteGuard Backup network-wide after restore");
			}
		}
		if (!empty($this->self_activation['site']) || (empty($this->self_activation['network']))) {
			$active = (array) get_option('active_plugins', array());
			$net = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
			if (!in_array($self, $active, true) && !isset($net[$self])) {
				$active[] = $self;
				update_option('active_plugins', array_values($active));
				$this->sgbackup->log("Re-activated SiteGuard Backup after restore");
			}
		}
	}

	private function post_restore_cleanup() {
		$this->progress("Running post-restore cleanup");

		// A run that resumed after the import writes back the snapshot the
		// importing run saved, not the backup's rows now in the database.
		if (array_intersect(array('db', 'db_import'), $this->get_completed_phases())) {
			$this->load_preserved_state();
		}

		// The imported options/sitemeta come from the SOURCE, where this plugin may
		// have been activated differently (e.g. network-active there, so absent
		// from the main site's active_plugins). Restoring such a backup switched
		// SiteGuard itself off. Put its activation back as it was.
		$this->ensure_self_active();

		// Unlink any plaintext files left by decrypt_file() (confidentiality + disk).
		$this->cleanup_decrypted_temp_files();

		// Flush rewrite rules. $wp_rewrite was initialised when this request began,
		// with the permalink structure the site had BEFORE the restore; flushing
		// without re-reading it rebuilt the old rules, and every URL in the
		// restored site's structure (e.g. /%year%/%monthnum%/%postname%/) 404'd.
		global $wp_rewrite;
		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('permalink_structure', 'options');
		if ($wp_rewrite instanceof WP_Rewrite) {
			$wp_rewrite->init();
		}
		flush_rewrite_rules(true);
		// Every other blog keeps its own cached rules; drop them so each one is
		// rebuilt from its (restored) permalink structure on its next request.
		if (is_multisite()) {
			global $wpdb;
			foreach ((array) get_sites(array('fields' => 'ids', 'number' => 0)) as $bid) {
				if ((int) $bid === (int) get_current_blog_id()) continue;
				$wpdb->delete($wpdb->get_blog_prefix($bid) . 'options', array('option_name' => 'rewrite_rules'));
				if (function_exists('clean_blog_cache')) clean_blog_cache((int) $bid);
			}
		}

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

		// The health monitor key was decided right after the import; make the
		// scheduled check match the settings that came with it.
		if ($this->health_applied) {
			$this->reconcile_health_cron();
			foreach (array_merge(SiteGuard_Backup_Health_Key_Policy::UNIT, array(SiteGuard_Backup_Health_Key_Policy::LAST_RESTORE, 'sgbackup_backup_history')) as $name) {
				$this->invalidate_option_cache($name);
			}
		} else {
			$this->log_health_not_applicable();
		}

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

			// Only claim credentials were preserved when we ACTUALLY skipped the
			// source user tables. If detection failed earlier and source
			// wp_users/wp_usermeta were imported, saying "preserved" is a
			// dangerous lie - warn the admin their login may have changed instead.
			$old_host = parse_url($this->old_siteurl, PHP_URL_HOST);
			if ($this->user_tables_skipped) {
				// Destination accounts were preserved; bring the source's other users
				// across too (matched by email, created when missing) so authorship
				// and logins survive the move.
				$blog_ids = is_multisite() ? get_sites(array('fields' => 'ids', 'number' => 0)) : array(1);
				foreach ($blog_ids as $bid) {
					$this->merge_subsite_users((int) $bid, (int) $bid, SiteGuard_Backup_Options::instance()->get_backup_dir());
				}
			}
			if ($this->user_tables_skipped) {
				$auth_msg = "Database restored from {$old_host}. Your login credentials have been preserved.";
				$auth_type = 'info';
			} else {
				$auth_msg = "Database restored from {$old_host}, but the source user tables were imported (migration was not detected before import). Your login credentials may now match the SOURCE site. If you cannot log in, use the source site's admin username/password, or reset via WP-CLI.";
				$auth_type = 'warning';
			}
			$this->sgbackup->log("[Restore] {$auth_msg}", $auth_type === 'warning' ? 'warning' : 'info');
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array('type' => $auth_type, 'message' => $auth_msg)) . "\n";
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

		// Clear Elementor's cached CSS files and postmeta flags so it
		// regenerates CSS with correct (migrated) URLs on next page load
		if ($this->is_migration) {
			$this->clear_elementor_css_cache();
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

		// Prune stale temp/restore dirs and consume deleteflag-*.txt markers so
		// wp-content/sgbackup/ doesn't accumulate forever. Runs on every
		// restore path (subsite, batch, full network) at successful completion.
		$opts = SiteGuard_Backup_Options::instance();
		$this->archive_processed_backup_files($opts->get_backup_dir());

		// This site's rows are written back: the snapshot has served its purpose.
		$opts->delete_option(self::PRESERVED_OPTION);

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

		list($restored_sitemeta, $restored_options) = $this->upsert_preserved_rows($this->pre_import_sgbackup_options);

		if (is_multisite()) {
			$total = $restored_sitemeta + $restored_options;
			$this->sgbackup->log("Restored {$total} destination sgbackup_* options ({$restored_sitemeta} sitemeta + {$restored_options} wp_options)");
			$this->progress("Restored {$total} plugin config options (encryption keys, backup history, etc.)");
		} else {
			$this->sgbackup->log("Restored {$restored_options} destination sgbackup_* options after DB import");
			$this->progress("Restored {$restored_options} plugin config options (encryption keys, backup history, etc.)");
		}
	}

	/**
	 * Write saved destination rows back with raw UPDATE-or-INSERT, then drop their
	 * cached copies. Raw values are written as they were read: passing them through
	 * update_option() would serialize them a second time.
	 *
	 * @param array $rows Snapshot rows as save_plugin_options_before_import() keys
	 *                    them (bare names on a single site, 'sitemeta:'/'options:'
	 *                    prefixed on multisite).
	 * @return array [sitemeta rows written, wp_options rows written]
	 */
	private function upsert_preserved_rows(array $rows) {
		global $wpdb;
		$restored_sitemeta = 0;
		$restored_options = 0;

		foreach ($rows as $prefixed_key => $saved) {
			// Snapshots and search-replace checkpoints saved by an older build
			// still carry lock rows.
			if ($this->is_lock_row($prefixed_key)) continue;
			if (is_multisite() && strpos($prefixed_key, 'sitemeta:') === 0) {
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
				$this->invalidate_option_cache($key);
				$restored_sitemeta++;
				continue;
			}
			if (is_multisite()) {
				if (strpos($prefixed_key, 'options:') !== 0) continue;
				$key = substr($prefixed_key, 8); // Remove 'options:' prefix
			} else {
				$key = $prefixed_key;
			}
			if (!is_array($saved) || !array_key_exists('value', $saved)) continue;
			$opt_value = $saved['value'];
			$opt_autoload = isset($saved['autoload']) ? $saved['autoload'] : 'no';
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
			$this->invalidate_option_cache($key);
			$restored_options++;
		}

		return array($restored_sitemeta, $restored_options);
	}

	/**
	 * Drop every cached copy of an option after a raw write or delete: its own
	 * entry, the "does not exist" list, alloptions, and on multisite the network
	 * option cache. With a persistent object cache (Redis on the standby) a stale
	 * entry otherwise outlives the request.
	 */
	private function invalidate_option_cache($name) {
		wp_cache_delete($name, 'options');
		$notoptions = wp_cache_get('notoptions', 'options');
		if (is_array($notoptions) && isset($notoptions[$name])) {
			unset($notoptions[$name]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}
		wp_cache_delete('alloptions', 'options');
		if (is_multisite()) {
			$network_id = get_current_network_id();
			wp_cache_delete($network_id . ':' . $name, 'site-options');
			$notoptions_key = $network_id . ':notoptions';
			$site_notoptions = wp_cache_get($notoptions_key, 'site-options');
			if (is_array($site_notoptions) && isset($site_notoptions[$name])) {
				unset($site_notoptions[$name]);
				wp_cache_set($notoptions_key, $site_notoptions, 'site-options');
			}
		}
	}

	/**
	 * Right after a successful import: put this site's backup history back and
	 * persist the destination snapshot.
	 *
	 * The snapshot used to live only in this request's memory. A restore that was
	 * interrupted after the import (or continued by the browser after a
	 * search-replace checkpoint) lost it, kept the backup's sgbackup_* rows, and
	 * its resume failed with "Backup not found" because history was the backup's.
	 * Everything else still waits for post_restore_cleanup(): decrypt_file() and
	 * the file phases read settings such as the encryption key after the import.
	 */
	private function preserve_after_import() {
		// Decide the health monitor key now, in the request that imported the
		// database, so a bot polling with the other key is not refused until cleanup.
		$this->apply_health_key_policy();

		$history = array();
		foreach (array('sgbackup_backup_history', 'options:sgbackup_backup_history', 'sitemeta:sgbackup_backup_history') as $key) {
			if (isset($this->pre_import_sgbackup_options[$key])) {
				$history[$key] = $this->pre_import_sgbackup_options[$key];
			}
		}
		if (!empty($history)) {
			$this->upsert_preserved_rows($history);
			$this->sgbackup->log("[Restore] Put this site's backup history back right after the import");
		}

		$this->save_preserved_state();
	}

	/**
	 * Persist the destination snapshot (sgbackup_restore_preserved) for this restore.
	 */
	private function save_preserved_state() {
		SiteGuard_Backup_Options::instance()->update_option(self::PRESERVED_OPTION, array(
			'timestamp'             => (string) $this->restore_timestamp(),
			'saved_at'              => time(),
			'status'                => 'in_progress',
			'options'               => $this->pre_import_sgbackup_options,
			'network_import'        => $this->network_import,
			'pre_import_subsite_sg' => $this->pre_import_subsite_sg,
			// What a re-run of the import reads from the database first, which by
			// then holds the backup's (or the safety net's) values.
			'pre_import_urls'       => array(
				'siteurl' => $this->pre_import_siteurl,
				'home'    => $this->pre_import_home,
				'content' => $this->pre_import_content_url,
				'uploads' => $this->pre_import_uploads_url,
			),
			'pre_import_blogs'      => $this->pre_import_blogs,
			// The health key decision: made once, never re-made on a resume
			// against an already-imported database.
			'policy'                => $this->health_key_policy,
			'health_dest'           => $this->health_dest,
			'health_applied'        => $this->health_applied,
			'health_outcome'        => $this->health_outcome,
		), false);
	}

	/**
	 * The destination snapshot saved by a run of THIS backup less than a day ago,
	 * or null. A snapshot is never applied to a different backup's restore.
	 */
	private function read_preserved_state() {
		$row = SiteGuard_Backup_Options::instance()->get_option(self::PRESERVED_OPTION, null);
		if (!is_array($row) || !isset($row['timestamp'], $row['saved_at'], $row['options']) || !is_array($row['options'])) {
			return null;
		}
		$this_ts = (string) $this->restore_timestamp();
		if ('' === $this_ts || '0' === $this_ts || (string) $row['timestamp'] !== $this_ts) {
			return null;
		}
		if (time() - (int) $row['saved_at'] > DAY_IN_SECONDS) {
			return null;
		}
		return $row;
	}

	/**
	 * Load the destination snapshot into this run. It is the superset of what the
	 * search-replace checkpoint carries, so it wins over instance_state.
	 *
	 * @param array|null $row   Row from read_preserved_state(); read when null.
	 * @param bool       $rerun The import is about to run again: put this site's
	 *                          health rows back so the key is decided afresh.
	 * @return bool Whether a snapshot was loaded.
	 */
	private function load_preserved_state($row = null, $rerun = false) {
		if (null === $row) $row = $this->read_preserved_state();
		if (!$row) return false;
		$this->pre_import_sgbackup_options = $row['options'];
		foreach (array_keys($this->pre_import_sgbackup_options) as $key) {
			if ($this->is_lock_row($key)) unset($this->pre_import_sgbackup_options[$key]);
		}
		$this->health_dest = (isset($row['health_dest']) && is_array($row['health_dest'])) ? $row['health_dest'] : array();
		if ($rerun) {
			$this->pre_import_sgbackup_options = array_merge($this->pre_import_sgbackup_options, $this->health_dest);
			$this->health_applied = false;
			$this->health_outcome = null;
		} else {
			$this->health_applied = !empty($row['health_applied']);
			$this->health_outcome = isset($row['health_outcome']) ? $row['health_outcome'] : null;
		}
		$this->network_import = !empty($row['network_import']);
		$this->pre_import_subsite_sg = (isset($row['pre_import_subsite_sg']) && is_array($row['pre_import_subsite_sg'])) ? $row['pre_import_subsite_sg'] : array();
		if (!empty($row['pre_import_urls']['siteurl'])) {
			$this->pre_import_siteurl = $row['pre_import_urls']['siteurl'];
			$this->pre_import_home = $row['pre_import_urls']['home'];
			$this->pre_import_content_url = $row['pre_import_urls']['content'];
			$this->pre_import_uploads_url = $row['pre_import_urls']['uploads'];
		}
		if (isset($row['pre_import_blogs']) && is_array($row['pre_import_blogs'])) {
			$this->pre_import_blogs = $row['pre_import_blogs'];
		}
		return true;
	}

	/**
	 * Decide whose health monitor key and settings the main site keeps, and write
	 * them, right after a successful import (see SiteGuard_Backup_Health_Key_Policy).
	 *
	 * The unit is taken out of the destination snapshot first, so neither the
	 * cleanup write-back nor a resumed run can touch it again. That write-back is
	 * what made every restore put this site's old key over the backup's, which is
	 * right for storage credentials but broke the monitor on a failover server.
	 */
	private function apply_health_key_policy() {
		global $wpdb;
		$P = 'SiteGuard_Backup_Health_Key_Policy';

		// This site's rows, out of the snapshot (and its last-restore record, which
		// this restore replaces).
		$dest = array();
		$autoload = array();
		$this->health_dest = array();
		foreach (array_merge($P::UNIT, array($P::LAST_RESTORE)) as $name) {
			$snap_keys = is_multisite() ? array('options:' . $name, 'sitemeta:' . $name) : array($name);
			foreach ($snap_keys as $snap_key) {
				if (!array_key_exists($snap_key, $this->pre_import_sgbackup_options)) continue;
				$saved = $this->pre_import_sgbackup_options[$snap_key];
				$this->health_dest[$snap_key] = $saved;
				unset($this->pre_import_sgbackup_options[$snap_key]);
				if ($P::LAST_RESTORE === $name) continue;
				if (0 === strpos($snap_key, 'sitemeta:')) {
					$dest[$snap_key] = (string) $saved;
				} elseif (is_array($saved) && array_key_exists('value', $saved)) {
					$dest['options:' . $name] = (string) $saved['value'];
					$autoload['options:' . $name] = isset($saved['autoload']) ? $saved['autoload'] : 'no';
				}
			}
		}

		// A plaintext key from before 1.3.13 is kept in restore state only as its
		// hash: sgbackup_restore_preserved outlives a restore that is never
		// continued, and every later backup would dump it. The hash is all a re-run
		// needs to decide again.
		$site_origin = (string) parse_url($this->pre_import_siteurl ? $this->pre_import_siteurl : home_url(), PHP_URL_HOST);
		$hashed = $P::hash_legacy($dest, 'wp_hash_password', $site_origin, time());
		if ($hashed !== $dest) {
			$legacy_autoload = isset($autoload['options:' . $P::LEGACY]) ? $autoload['options:' . $P::LEGACY] : 'no';
			foreach (array($P::LEGACY, $P::HASH, $P::META) as $name) {
				foreach ($P::SCOPES as $scope) {
					if (!is_multisite() && 'options:' !== $scope) continue;
					$snap_key = is_multisite() ? $scope . $name : $name;
					if (!isset($hashed[$scope . $name])) {
						unset($this->health_dest[$snap_key], $autoload[$scope . $name]);
					} elseif ('sitemeta:' === $scope) {
						$this->health_dest[$snap_key] = $hashed[$scope . $name];
					} else {
						if (!isset($autoload[$scope . $name])) $autoload[$scope . $name] = $legacy_autoload;
						$this->health_dest[$snap_key] = array('value' => $hashed[$scope . $name], 'autoload' => $autoload[$scope . $name]);
					}
				}
			}
			$dest = $hashed;
		}

		$imported_autoload = array();
		$imported = $this->read_health_unit_rows($wpdb->options, is_multisite(), $imported_autoload);
		$origin = parse_url((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'siteurl')), PHP_URL_HOST);

		$plan = $P::resolve($this->health_key_policy, $dest, $imported, array(
			'hasher'      => 'wp_hash_password',
			'origin'      => $origin ? $origin : '',
			'site_origin' => $site_origin,
			'backup_ts'   => (int) $this->restore_timestamp(),
			'now'         => time(),
		));
		$row_autoload = ('backup' === $plan['source']) ? $imported_autoload + $autoload : $autoload + $imported_autoload;
		$this->write_health_unit_rows($wpdb->options, is_multisite(), $plan['final'], $row_autoload);
		$this->record_health_outcome($plan);
	}

	/**
	 * The health key decision for a subsite restored into this single site, after
	 * replace_plugin_option_rows() put this site's SiteGuard rows back.
	 *
	 * @param array $preserved This site's rows from read_plugin_option_rows().
	 * @param array $imported  The subsite's unit rows (read_health_unit_rows()).
	 */
	private function apply_health_key_policy_rows($table, array $preserved, array $imported, array $imported_autoload, $old_siteurl, $site_url) {
		$P = 'SiteGuard_Backup_Health_Key_Policy';
		$dest = array();
		$autoload = array();
		foreach ($preserved as $row) {
			if (!is_array($row) || !isset($row['option_name']) || !in_array($row['option_name'], $P::UNIT, true)) continue;
			$dest['options:' . $row['option_name']] = (string) $row['option_value'];
			$autoload['options:' . $row['option_name']] = $row['autoload'];
		}
		$origin = parse_url((string) $old_siteurl, PHP_URL_HOST);
		$plan = $P::resolve($this->health_key_policy, $dest, $imported, array(
			'hasher'      => 'wp_hash_password',
			'origin'      => $origin ? $origin : '',
			'site_origin' => (string) parse_url($site_url, PHP_URL_HOST),
			'backup_ts'   => (int) $this->restore_timestamp(),
			'now'         => time(),
			// This path always dropped the subsite's SiteGuard rows: when this
			// site's key wins, none of the subsite's health settings come along.
			'fill_settings' => false,
		));
		$row_autoload = ('backup' === $plan['source']) ? $imported_autoload + $autoload : $autoload + $imported_autoload;
		$this->write_health_unit_rows($table, false, $plan['final'], $row_autoload);
		$this->record_health_outcome($plan, (string) parse_url($site_url, PHP_URL_HOST));
	}

	/**
	 * The health unit rows in an options table (and wp_sitemeta), raw, keyed
	 * 'options:<name>' / 'sitemeta:<name>'.
	 */
	private function read_health_unit_rows($table, $with_sitemeta, &$autoload = array()) {
		global $wpdb;
		$names = SiteGuard_Backup_Health_Key_Policy::UNIT;
		$in = implode(', ', array_fill(0, count($names), '%s'));
		$rows = array();
		foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value, autoload FROM `{$table}` WHERE option_name IN ({$in})", $names), ARRAY_A) as $row) {
			$rows['options:' . $row['option_name']] = (string) $row['option_value'];
			$autoload['options:' . $row['option_name']] = $row['autoload'];
		}
		if ($with_sitemeta) {
			// Lowest meta_id last, so it wins, as get_site_option() reads it.
			foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key IN ({$in}) ORDER BY meta_id DESC", $names), ARRAY_A) as $row) {
				$rows['sitemeta:' . $row['meta_key']] = (string) $row['meta_value'];
			}
		}
		return $rows;
	}

	/**
	 * Make the health unit rows exactly $final: write what it holds, delete the
	 * rest, keeping each row's autoload. Raw values, then cache invalidation.
	 */
	private function write_health_unit_rows($table, $with_sitemeta, array $final, array $autoload) {
		global $wpdb;
		foreach (SiteGuard_Backup_Health_Key_Policy::UNIT as $name) {
			$key = 'options:' . $name;
			if (isset($final[$key])) {
				$exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE option_name = %s", $name));
				$row_autoload = isset($autoload[$key]) ? $autoload[$key] : 'no';
				if ($exists) {
					$wpdb->update($table, array('option_value' => $final[$key], 'autoload' => $row_autoload), array('option_name' => $name), array('%s', '%s'), array('%s'));
				} else {
					$wpdb->insert($table, array('option_name' => $name, 'option_value' => $final[$key], 'autoload' => $row_autoload), array('%s', '%s', '%s'));
				}
			} else {
				$wpdb->delete($table, array('option_name' => $name), array('%s'));
			}
			if ($with_sitemeta) {
				$key = 'sitemeta:' . $name;
				if (isset($final[$key])) {
					$exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key = %s", $name));
					if ($exists) {
						$wpdb->update($wpdb->sitemeta, array('meta_value' => $final[$key]), array('meta_key' => $name), array('%s'), array('%s'));
					} else {
						$wpdb->insert($wpdb->sitemeta, array('site_id' => 1, 'meta_key' => $name, 'meta_value' => $final[$key]), array('%d', '%s', '%s'));
					}
				} else {
					$wpdb->delete($wpdb->sitemeta, array('meta_key' => $name), array('%s'));
				}
			}
			$this->invalidate_option_cache($name);
		}
	}

	/**
	 * Record and report a health key decision: sgbackup_health_last_restore (the
	 * Health tab's "last restore" line and "Switch to the backup's key"), one
	 * result line, and warnings. Hints and fingerprints only; the declined hash
	 * is stored but never shown.
	 */
	private function record_health_outcome(array $plan, $site_host = null) {
		global $wp_version;
		$P = 'SiteGuard_Backup_Health_Key_Policy';
		$this->health_applied = true;
		$this->health_outcome = $plan['outcome'];
		$report = $plan['report'];

		$this->invalidate_option_cache($P::LAST_RESTORE);
		SiteGuard_Backup_Options::instance()->update_option($P::LAST_RESTORE, array(
			'at'            => time(),
			'backup_ts'     => (int) $this->restore_timestamp(),
			'policy'        => $plan['policy'],
			'outcome'       => $plan['outcome'],
			'reason'        => $plan['reason'],
			'site_hint'     => $report['site_hint'],
			'site_fp'       => $report['site_fp'],
			'backup_hint'   => $report['backup_hint'],
			'backup_fp'     => $report['backup_fp'],
			'backup_origin' => $report['backup_origin'],
			'declined_hash' => ('kept_site' === $plan['outcome'] && $P::is_valid_hash($plan['declined_hash'])) ? $plan['declined_hash'] : '',
		), false);

		// Failed-auth counters and cooldowns can live only in the object cache
		// (Redis on the standby); a bot refused before the switch must not stay
		// rate-limited after it. The database transients are cleared at cleanup.
		if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
			wp_cache_flush_group('transient');
		}

		$this->progress($P::summary($plan));

		if (null === $site_host) {
			$site_host = (string) parse_url($this->pre_import_siteurl ? $this->pre_import_siteurl : home_url(), PHP_URL_HOST);
		}
		if ('backup' === $plan['source'] && '' !== $report['backup_origin'] && $report['backup_origin'] === $site_host
			&& '' !== $report['backup_fp'] && '' !== $report['site_fp'] && $report['backup_fp'] !== $report['site_fp']) {
			$this->health_warning("This restores an older monitor key that was rotated ({$report['site_fp']} was replaced by {$report['backup_fp']}). Give the bot that key, or regenerate one.");
		}
		if (!empty($plan['effective_hash']) && 0 === strpos($plan['effective_hash'], '$wp') && version_compare($wp_version, '6.8', '<')) {
			$this->health_warning("The monitor key was hashed by WordPress 6.8 or later; WordPress {$wp_version} cannot check it and the bot will be refused. Update WordPress or regenerate the key.");
		}
		if (!empty($this->restore_options['wpcore']) && !empty($this->backup_set['wpcore'])) {
			$this->health_warning("WordPress core files are restored too: if the backup's core is older than 6.8, a monitor key hashed by 6.8 or later cannot be checked.");
		}
	}

	private function health_warning($message) {
		$this->progress[] = "WARNING: {$message}";
		$this->sgbackup->log("[Restore] WARNING: {$message}", 'warning');
		if (defined('DOING_AJAX') && DOING_AJAX) {
			echo 'RINFO:' . json_encode(array('type' => 'warning', 'message' => $message)) . "\n";
			if (ob_get_level()) @ob_flush();
			@flush();
		}
	}

	/**
	 * Make the health check cron match the restored settings. The imported cron
	 * array is the backup's: its event may be missing, or scheduled at the
	 * backup's interval, and sgbackup_ensure_health_cron() only adds a missing
	 * event, and only on admin, cron or CLI loads.
	 */
	private function reconcile_health_cron() {
		require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
		wp_cache_delete('cron', 'options');
		$hook = 'sgbackup_health_check';
		if (!SiteGuard_Backup_Health_Monitor::is_enabled()) {
			if (wp_next_scheduled($hook)) {
				wp_clear_scheduled_hook($hook);
				$this->sgbackup->log("[Restore] Health monitor is disabled: removed its scheduled check");
			}
			return;
		}
		$interval = SiteGuard_Backup_Options::instance()->get_option('sgbackup_health_interval', 'every10min');
		$schedules = wp_get_schedules();
		if (!is_string($interval) || !isset($schedules[$interval])) {
			$interval = 'every10min';
		}
		if (!wp_next_scheduled($hook) || wp_get_schedule($hook) !== $interval) {
			wp_clear_scheduled_hook($hook);
			wp_schedule_event(time() + 60, $interval, $hook);
			$this->sgbackup->log("[Restore] Scheduled the health check at this site's interval ({$interval})");
		}
	}

	/**
	 * Say why a restore did not decide the health monitor key.
	 */
	private function log_health_not_applicable() {
		if (in_array($this->subsite_mode, array(self::SUBSITE_MODE_IMPORT_AS, self::SUBSITE_MODE_BATCH), true) && is_multisite()) {
			$why = 'main site untouched';
		} elseif ($this->db_restore_mode === self::DB_RESTORE_SKIP_DB) {
			$why = 'database not restored (skip-db)';
		} else {
			$why = 'database not restored';
		}
		$this->progress("Health monitor key policy: not applicable ({$why}); this site's key is unchanged.");
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
		$terminal = array('complete', 'finished', 'error', 'aborted', 'failed', 'warning');
		$blog_ids = is_multisite() ? array_map('intval', (array) get_sites(array('fields' => 'ids', 'number' => 0))) : array(0);
		$events = 0;
		$jobs = 0;

		foreach ($blog_ids as $bid) {
			$table = $bid ? $wpdb->get_blog_prefix($bid) . 'options' : $wpdb->options;

			// Subsites of a WHOLE-NETWORK import: put back their own SiteGuard rows and
			// scheduled events (the main site is handled by restore_plugin_options()).
			// Never for other restore paths: a single-subsite import leaves every
			// other subsite's tables alone, and this would wipe their SiteGuard state.
			if ($bid > 1 && $this->network_import) {
				$this->replace_plugin_option_rows($table, isset($this->pre_import_subsite_sg[$bid]) ? $this->pre_import_subsite_sg[$bid] : array(), $bid);
			}

			// Resumptions of the SOURCE's jobs (the dump is taken while one runs):
			// they made the scheduler report a job that can never finish.
			$cron = @unserialize((string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `{$table}` WHERE option_name = %s", 'cron')), array('allowed_classes' => false));
			if (is_array($cron)) {
				$changed = false;
				foreach ($cron as $ts => $hooks) {
					if (is_array($hooks) && isset($hooks['sgbackup_backup_resume'])) {
						$events += count($hooks['sgbackup_backup_resume']);
						unset($cron[$ts]['sgbackup_backup_resume']);
						if (empty($cron[$ts])) unset($cron[$ts]);
						$changed = true;
					}
				}
				if ($changed) {
					$wpdb->update($table, array('option_value' => serialize($cron)), array('option_name' => 'cron'));
				}
			}

			// Imported jobs still marked as in progress can never resume here.
			// (The old string replace of "running" corrupted the serialized data and
			// missed "begun"/"resuming".)
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT option_name, option_value FROM `{$table}` WHERE option_name LIKE %s",
				$wpdb->esc_like('sgbackup_jobdata_') . '%'
			));
			foreach ((array) $rows as $row) {
				$data = @unserialize($row->option_value, array('allowed_classes' => false));
				if (!is_array($data)) continue;
				$status = isset($data['jobstatus']) ? $data['jobstatus'] : '';
				if (!empty($data['finalized']) || in_array($status, $terminal, true)) continue;
				$data['jobstatus'] = 'aborted';
				$data['finalized'] = 1;
				$wpdb->update($table, array('option_value' => serialize($data)), array('option_name' => $row->option_name));
				$jobs++;
			}

			// Semaphore locks from the imported DB.
			$wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE option_name LIKE %s", $wpdb->esc_like('sgbackup_sem_') . '%'));
			if ($bid && function_exists('clean_blog_cache')) clean_blog_cache($bid);
		}

		// A restore lock row the import brought in (a pre-restore backup is dumped
		// while its restore holds the lock) is not this run's: unlock() would not
		// match it and the next restore would be refused for up to an hour. The
		// write-back used to cover it with this run's own row; it no longer
		// carries lock rows. This run's row is never older than its start.
		if ($this->restore_lock && $this->restore_lock->is_locked() && $this->restore_started_at) {
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(option_value, '|', -2), '|', 1) AS UNSIGNED) < %d",
				'sgbackup_restore',
				$this->restore_started_at
			));
			$this->invalidate_option_cache('sgbackup_restore');
		}
		wp_cache_delete('cron', 'options');
		wp_cache_delete('alloptions', 'options');

		// Remove restore progress transients
		if (is_multisite()) {
			delete_site_option('sgbackup_restore_progress');
		} else {
			delete_option('sgbackup_restore_progress');
		}

		$this->sgbackup->log("Cleaned up imported job state: {$events} resumption event(s), {$jobs} unfinished job(s), semaphore locks");
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

		// Track the plaintext sibling so we can unlink it on every restore exit
		// path (see cleanup_decrypted_temp_files()). Leaving it behind leaks the
		// decrypted database/archive contents and fills the disk.
		if (is_string($decrypted) && $decrypted !== '' && $decrypted !== $file_path) {
			$this->decrypted_temp_files[] = $decrypted;
		}

		return $decrypted;
	}

	/**
	 * Dump the current database as an always-keep set before restoring over it.
	 *
	 * @return true|WP_Error Any table that cannot be dumped is an error: a partial
	 *                       rollback point is worse than a clear refusal.
	 */
	private function create_pre_restore_backup() {
		require_once SGBACKUP_DIR . 'includes/class-database-utility.php';
		$sgbackup = SiteGuard_Backup::instance();
		$backup_dir = $sgbackup->get_backup_dir();
		global $wpdb;

		$nonce = substr(wp_generate_password(12, false), 0, 12);
		$timestamp = time();
		$db_file = "prerestore_{$nonce}-db.sql.gz";
		$db_path = $backup_dir . $db_file;
		$temp_dir = $backup_dir . 'dbtemp_prerestore_' . $nonce . '/';
		$fail = function ($msg) use ($db_path, $temp_dir, $backup_dir) {
			if (file_exists($db_path)) SiteGuard_Backup_Filesystem::delete_file($db_path, $backup_dir);
			SiteGuard_Backup_Filesystem::delete_tree($temp_dir, $backup_dir);
			$this->sgbackup->log("Pre-restore backup failed: {$msg}", 'error');
			return new WP_Error('prerestore_failed', $msg);
		};

		try {
			$this->progress("Creating pre-restore database backup...");
			$tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);
			if (empty($tables)) return $fail('no tables found');

			$fh = @gzopen($db_path, 'wb9');
			if (!$fh) return $fail('could not create ' . $db_file);
			$put = function ($data) use ($fh) {
				return '' === $data || gzwrite($fh, $data) > 0;
			};
			if (!$put("-- SiteGuard Backup pre-restore backup\n-- Generated: " . gmdate('Y-m-d H:i:s') . " UTC\nSET FOREIGN_KEY_CHECKS = 0;\n\n")) {
				gzclose($fh);
				return $fail('write error (disk full?)');
			}
			if (!is_dir($temp_dir)) wp_mkdir_p($temp_dir);

			foreach ($tables as $table) {
				$table_file = $temp_dir . $table . '.sql.gz';
				$ok = SiteGuard_Backup_Database_Utility::php_dump_table($table, $table_file);
				clearstatcache(true, $table_file);
				if (!$ok || !file_exists($table_file) || filesize($table_file) === 0) {
					gzclose($fh);
					return $fail("could not dump table {$table}");
				}
				$tfh = @gzopen($table_file, 'rb');
				if (!$tfh) {
					gzclose($fh);
					return $fail("could not read the dump of {$table}");
				}
				while (!gzeof($tfh)) {
					$chunk = gzread($tfh, 65536);
					if (false === $chunk) { gzclose($tfh); gzclose($fh); return $fail("read error on {$table}"); }
					if ('' === $chunk) break;
					if (!$put($chunk)) { gzclose($tfh); gzclose($fh); return $fail('write error (disk full?)'); }
				}
				gzclose($tfh);
				SiteGuard_Backup_Filesystem::delete_file($table_file, $backup_dir);
			}

			if (!$put("\nSET FOREIGN_KEY_CHECKS = 1;\n") || !gzclose($fh)) {
				return $fail('write error while finishing the dump');
			}
			SiteGuard_Backup_Filesystem::delete_tree($temp_dir, $backup_dir);

			clearstatcache(true, $db_path);
			if (!file_exists($db_path) || filesize($db_path) === 0) {
				return $fail('the dump is empty');
			}
			SiteGuard_Backup_History::add_backup($timestamp, array(
				'nonce'       => $nonce,
				'db'          => array($db_file),
				'service'     => array('none'),
				'always_keep' => true,
				'label'       => 'Pre-restore',
				'size'        => filesize($db_path),
			));
			$this->progress("Pre-restore backup saved: {$db_file}");
			return true;
		} catch (Exception $e) {
			return $fail($e->getMessage());
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
				if ((int) $actual_size !== (int) $file_info['size']) {
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
	 * Clear Elementor's cached CSS files and database flags.
	 *
	 * Elementor caches per-post CSS files containing hardcoded URLs. After a
	 * migration these files reference the old domain, causing broken layouts.
	 * Clearing the cache forces Elementor to regenerate CSS on next page load.
	 */
	private function clear_elementor_css_cache() {
		global $wpdb;
		$cleared = 0;

		// 1. Clear postmeta cache flags across all sites
		$meta_keys = array('_elementor_css', '_elementor_document_cache', '_elementor_page_assets');
		$tables = array($wpdb->postmeta);

		if (is_multisite()) {
			$blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->base_prefix}blogs");
			foreach ($blog_ids as $bid) {
				$prefix = ($bid == 1) ? $wpdb->base_prefix : $wpdb->base_prefix . $bid . '_';
				$table = $prefix . 'postmeta';
				if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
					$tables[] = $table;
				}
			}
			$tables = array_unique($tables);
		}

		foreach ($tables as $table) {
			foreach ($meta_keys as $key) {
				$deleted = $wpdb->query($wpdb->prepare(
					"DELETE FROM `{$table}` WHERE meta_key = %s", $key
				));
				$cleared += (int) $deleted;
			}
		}

		// 2. Clear global Elementor options
		$option_keys = array('_elementor_global_css', 'elementor_frontend_css_updated_time');
		if (is_multisite()) {
			foreach ($blog_ids as $bid) {
				$prefix = ($bid == 1) ? $wpdb->base_prefix : $wpdb->base_prefix . $bid . '_';
				$options_table = $prefix . 'options';
				if ($wpdb->get_var("SHOW TABLES LIKE '{$options_table}'") === $options_table) {
					foreach ($option_keys as $key) {
						$wpdb->query($wpdb->prepare(
							"DELETE FROM `{$options_table}` WHERE option_name = %s", $key
						));
					}
				}
			}
		} else {
			foreach ($option_keys as $key) {
				delete_option($key);
			}
		}

		// 3. Delete cached CSS files from uploads/elementor/css/
		$upload_dir = wp_upload_dir();
		$base_upload = $upload_dir['basedir']; // wp-content/uploads (dirname() pointed at wp-content and deleted nothing)
		$css_dirs = array($base_upload . '/elementor/css');

		if (is_multisite()) {
			foreach ($blog_ids as $bid) {
				if ($bid == 1) continue;
				$css_dirs[] = $base_upload . '/sites/' . $bid . '/elementor/css';
			}
		}

		$files_deleted = 0;
		foreach ($css_dirs as $css_dir) {
			if (!is_dir($css_dir)) continue;
			$files = glob($css_dir . '/*.css');
			if (!is_array($files)) continue;
			foreach ($files as $file) {
				if (@unlink($file)) $files_deleted++;
			}
		}

		if ($cleared > 0 || $files_deleted > 0) {
			$msg = "Elementor CSS cache cleared: {$cleared} postmeta rows, {$files_deleted} CSS files deleted";
			$this->sgbackup->log("[Restore] {$msg}");
			if (defined('DOING_AJAX') && DOING_AJAX) {
				echo 'RINFO:' . json_encode(array('type' => 'info', 'message' => $msg)) . "\n";
				if (ob_get_level()) @ob_flush();
				@flush();
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
