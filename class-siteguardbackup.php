<?php
/**
 * SiteGuard Backup - Core Class
 *
 * Handles scheduling, logging, job orchestration, and backup lifecycle management.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup {

	private static $instance = null;

	public $version = SGBACKUP_VERSION;
	public $nonce;
	public $file_nonce;
	public $backup_time;
	public $job_time_ms;
	public $current_resumption = 0;
	public $something_useful_happened = false;
	public $newresumption_scheduled = false;
	public $boot_error = '';

	private $jobdata = array();
	private $logfile_handle = false;
	private $logfile_name = '';
	private $opened_log_time;
	private $backup_dir;
	private $_process_start;

	/**
	 * Available backup storage methods.
	 */
	private $backup_methods = array(
		'dropbox'     => 'Dropbox',
		's3'          => 'Amazon S3 (or compatible)',
		's3generic'   => 'S3-Compatible (Generic)',
		'googledrive' => 'Google Drive',
		'onedrive'    => 'Microsoft OneDrive',
		'azure'       => 'Microsoft Azure',
		'gcs'         => 'Google Cloud Storage',
		'pcloud'      => 'pCloud',
		'ftp'         => 'FTP',
		'sftp'        => 'SFTP / SCP',
		'webdav'      => 'WebDAV',
		'openstack'   => 'OpenStack (Swift / Rackspace)',
		'email'       => 'Email',
		'remotesend'  => 'Remote Site (migration)',
	);

	/** Get registered backup methods (read-only). */
	public function get_backup_methods() {
		return $this->backup_methods;
	}

	/**
	 * Backup entities and their labels.
	 */
	public $backup_entities = array(
		'plugins' => 'Plugins',
		'themes'  => 'Themes',
		'uploads' => 'Uploads',
		'others'  => 'Others',
		'wpcore'  => 'WordPress Core',
	);

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_hooks();
	}

	private function __clone() {}
	public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }

	private function register_hooks() {
		// Note: cron action hooks are registered in siteguardbackup.php::sgbackup_register_cron_hooks()
		// via lightweight callbacks that load classes on demand. We do NOT re-register them here
		// to avoid double-firing. The lightweight callbacks call back into this class's methods.
	}

	// ==========================================
	// LOGGING
	// ==========================================

	/**
	 * Open the log file for a backup job.
	 */
	public function logfile_open($nonce) {
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		$this->logfile_name = $this->get_backup_dir() . 'log.' . $nonce . '.txt';
		$this->logfile_handle = fopen($this->logfile_name, 'a');
		$this->opened_log_time = time();
		@chmod($this->logfile_name, 0600);

		if ($this->logfile_handle) {
			$this->log("=== SiteGuard Backup v{$this->version} log opened ===");
			$this->log("PHP " . phpversion() . ", WP " . get_bloginfo('version') . ", " . PHP_OS);
			$this->log("Memory limit: " . ini_get('memory_limit') . ", max_execution_time: " . ini_get('max_execution_time'));
		}
	}

	/**
	 * Log a message.
	 *
	 * @param string $message
	 * @param string $level 'notice', 'warning', 'error'
	 * @param bool   $skip_dblog
	 */
	public function log($message, $level = 'notice', $skip_dblog = false) {
		$timestamp = gmdate('Y-m-d H:i:s');
		$prefix = ($level !== 'notice') ? strtoupper($level) . ': ' : '';
		$line = "[{$timestamp}] {$prefix}{$message}\n";

		if ($this->logfile_handle) {
			@fwrite($this->logfile_handle, $line);
		}

		if (!$skip_dblog && 'error' === $level) {
			$errors = $this->jobdata_get('errors', array());
			$errors[] = array('time' => time(), 'message' => $message, 'level' => $level);
			$this->jobdata_set('errors', $errors);
		}

		if ('error' === $level) {
			error_log("SiteGuard Backup: {$message}");
		}
	}

	/**
	 * Close the log file.
	 */
	public function logfile_close() {
		if ($this->logfile_handle) {
			$this->log("=== Log closed ===");
			fclose($this->logfile_handle);
			$this->logfile_handle = false;
		}
	}

	/**
	 * Close the browser connection while allowing PHP to continue running.
	 * Sends the provided text as the response body, then flushes and detaches.
	 *
	 * @param string $txt Response body to send before closing.
	 */
	public function close_browser_connection($txt = '') {
		// Close any sessions to prevent session locking
		if (function_exists('session_id') && session_id()) {
			session_write_close();
		}

		$txt = (string) $txt;
		header('Content-Length: ' . strlen($txt));
		header('Connection: close');
		header('Content-Type: application/json');

		echo $txt;

		// Flush all output buffers
		$ob_level = ob_get_level();
		while ($ob_level > 0) {
			ob_end_flush();
			$ob_level--;
		}
		flush();

		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
	}

	/**
	 * Get log file path for a nonce.
	 */
	public function get_logfile_name($nonce) {
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		return $this->get_backup_dir() . 'log.' . $nonce . '.txt';
	}

	/**
	 * Get log file contents.
	 */
	public function get_log($nonce) {
		$file = $this->get_logfile_name($nonce);
		if (file_exists($file)) {
			return file_get_contents($file);
		}
		return '';
	}

	// ==========================================
	// JOB DATA
	// ==========================================

	/**
	 * Set a job data value.
	 */
	public function jobdata_set($key, $value) {
		$this->jobdata[$key] = $value;
		$this->save_jobdata();
	}

	/**
	 * Set multiple job data values.
	 */
	public function jobdata_set_multi() {
		$args = func_get_args();
		if (count($args) % 2 !== 0) {
			error_log('SiteGuard Backup: jobdata_set_multi called with odd number of arguments');
			return; // Do not persist a partial update
		}
		for ($i = 0; $i < count($args); $i += 2) {
			if (array_key_exists($i + 1, $args)) {
				$this->jobdata[$args[$i]] = $args[$i + 1];
			}
		}
		$this->save_jobdata();
	}

	/**
	 * Get a job data value.
	 */
	public function jobdata_get($key, $default = null) {
		if (empty($this->jobdata) && $this->nonce) {
			$this->load_jobdata();
		}
		return isset($this->jobdata[$key]) ? $this->jobdata[$key] : $default;
	}

	/**
	 * Get all job data for a specific nonce.
	 */
	public function jobdata_getarray($nonce) {
		$opts = SiteGuard_Backup_Options::instance();
		$data = $opts->get_option('sgbackup_jobdata_' . $nonce, array());
		return is_array($data) ? $data : array();
	}

	/**
	 * Load job data from database.
	 */
	private function load_jobdata() {
		if (!$this->nonce) return;
		$this->jobdata = $this->jobdata_getarray($this->nonce);
	}

	/**
	 * Save current job data to database.
	 */
	private function save_jobdata() {
		if (!$this->nonce) return;
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_jobdata_' . $this->nonce, $this->jobdata, false);
	}

	/**
	 * Reset in-memory job data cache.
	 */
	public function jobdata_reset() {
		$this->jobdata = array();
	}

	/**
	 * Delete job data for a nonce.
	 */
	public function jobdata_delete($nonce = null) {
		if (null === $nonce) $nonce = $this->nonce;
		if (!$nonce) return;
		$opts = SiteGuard_Backup_Options::instance();
		$opts->delete_option('sgbackup_jobdata_' . $nonce);
	}

	// ==========================================
	// BACKUP DIRECTORY
	// ==========================================

	/**
	 * Get the backup directory, creating it if needed.
	 */
	public function get_backup_dir() {
		if (!empty($this->backup_dir)) return $this->backup_dir;
		$opts = SiteGuard_Backup_Options::instance();
		$this->backup_dir = $opts->get_backup_dir();
		return $this->backup_dir;
	}

	// ==========================================
	// SCHEDULING
	// ==========================================

	/**
	 * Set up the file backup schedule.
	 *
	 * @param string $interval Cron schedule name (e.g., 'daily', 'weekly').
	 */
	public function schedule_backup($interval) {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_interval', $interval);

		wp_clear_scheduled_hook('sgbackup_backup');

		if ('manual' !== $interval) {
			$schedule_day = $opts->get_option('sgbackup_schedule_day', '');
			$first_time = $this->get_first_scheduled_time($interval, $schedule_day);
			wp_schedule_event($first_time, $interval, 'sgbackup_backup');
			$this->log("Scheduled file backups: {$interval}, first at " . gmdate('Y-m-d H:i:s', $first_time));
		}
	}

	/**
	 * Set up the database backup schedule.
	 *
	 * @param string $interval Cron schedule name.
	 */
	public function schedule_backup_database($interval) {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_interval_database', $interval);

		wp_clear_scheduled_hook('sgbackup_backup_database');

		if ('manual' !== $interval) {
			$schedule_day_db = $opts->get_option('sgbackup_schedule_day_db', '');
			$first_time = $this->get_first_scheduled_time($interval, $schedule_day_db);
			wp_schedule_event($first_time, $interval, 'sgbackup_backup_database');
		}
	}

	/**
	 * Set up incremental backup schedule.
	 *
	 * @param string $interval Cron schedule name.
	 */
	public function schedule_backup_increments($interval) {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_interval_incremental', $interval);

		wp_clear_scheduled_hook('sgbackup_backup_incremental');

		if ('none' !== $interval && 'manual' !== $interval) {
			$first_time = $this->get_first_scheduled_time();
			wp_schedule_event($first_time, $interval, 'sgbackup_backup_incremental');
		}
	}

	/**
	 * Set up the scheduled remote send schedule.
	 *
	 * @param string $interval Cron schedule name.
	 */
	public function schedule_backup_remotesend($interval) {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_remotesend_schedule', $interval);

		wp_clear_scheduled_hook('sgbackup_backup_remotesend');

		if ('manual' !== $interval) {
			$schedule_day = $opts->get_option('sgbackup_remotesend_schedule_day', '');
			$first_time = $this->get_first_scheduled_time($interval, $schedule_day);
			wp_schedule_event($first_time, $interval, 'sgbackup_backup_remotesend');
			$this->log("Scheduled remote send: {$interval}, first at " . gmdate('Y-m-d H:i:s', $first_time));
		}
	}

	/**
	 * Calculate the first scheduled time for a backup, respecting preferred
	 * time-of-day and day-of-week/month settings.
	 *
	 * @param string $interval      The schedule interval (e.g., 'daily', 'weekly', 'monthly').
	 * @param string $schedule_day  The preferred day setting (0-6 for weekly, 1-28 for monthly, '' for any).
	 * @return int Unix timestamp for the first scheduled run.
	 */
	private function get_first_scheduled_time($interval = '', $schedule_day = '') {
		$opts = SiteGuard_Backup_Options::instance();
		$schedule_time = $opts->get_option('sgbackup_schedule_time', '');

		$now = time();

		// Use wp_timezone() for correct local time (handles DST and half-hour offsets)
		$tz = wp_timezone();
		$now_dt = new DateTime('@' . $now);
		$now_dt->setTimezone($tz);

		// Determine hour and minute
		$hour = null;
		$minute = null;
		if (!empty($schedule_time) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $schedule_time, $m)) {
			$hour = intval($m[1]);
			$minute = intval($m[2]);
		}
		if (null === $hour) {
			$hour = rand(1, 4);
			$minute = rand(0, 59);
		}

		// Calculate target day based on interval and schedule_day
		if (in_array($interval, array('weekly', 'fortnightly'), true) && $schedule_day !== '') {
			$target_dow = intval($schedule_day); // 0=Sunday .. 6=Saturday
			if ($target_dow < 0 || $target_dow > 6) {
				$target_dow = 0;
			}
			// Find the next occurrence of that day of week
			$current_dow = intval($now_dt->format('w'));
			$days_ahead = $target_dow - $current_dow;
			if ($days_ahead < 0) {
				$days_ahead += 7;
			}
			$target_dt = clone $now_dt;
			$target_dt->modify("+{$days_ahead} days");
			$target_dt->setTime($hour, $minute, 0);
			$scheduled = $target_dt->getTimestamp();
			// If this time already passed, advance by 7 days (use DateTime to avoid DST drift)
			if ($scheduled <= $now) {
				$target_dt->modify('+7 days');
				$scheduled = $target_dt->getTimestamp();
			}
			return $scheduled;
		}

		if ($interval === 'monthly' && $schedule_day !== '') {
			$target_dom = intval($schedule_day); // 1-28
			if ($target_dom < 1 || $target_dom > 28) {
				$target_dom = 1;
			}
			// Find the next occurrence of that day of month
			$local_year = intval($now_dt->format('Y'));
			$local_month = intval($now_dt->format('n'));

			// Try current month first
			$target_dt = new DateTime('now', $tz);
			$target_dt->setDate($local_year, $local_month, $target_dom);
			$target_dt->setTime($hour, $minute, 0);
			$scheduled = $target_dt->getTimestamp();
			if ($scheduled <= $now) {
				// Move to next month
				$local_month++;
				if ($local_month > 12) {
					$local_month = 1;
					$local_year++;
				}
				$target_dt->setDate($local_year, $local_month, $target_dom);
				$scheduled = $target_dt->getTimestamp();
			}
			return $scheduled;
		}

		// Default: use tomorrow at the specified time
		$target_dt = clone $now_dt;
		$target_dt->modify('+1 day');
		$target_dt->setTime($hour, $minute, 0);
		$scheduled = $target_dt->getTimestamp();
		if ($scheduled <= $now) {
			$target_dt->modify('+1 day');
			$scheduled = $target_dt->getTimestamp();
		}
		return $scheduled;
	}

	/**
	 * Get current schedule information.
	 */
	public function get_schedule_info() {
		$opts = SiteGuard_Backup_Options::instance();
		return array(
			'interval'             => $opts->get_option('sgbackup_interval', 'manual'),
			'interval_database'    => $opts->get_option('sgbackup_interval_database', 'manual'),
			'interval_incremental' => $opts->get_option('sgbackup_interval_incremental', 'none'),
			'interval_remotesend'  => $opts->get_option('sgbackup_remotesend_schedule', 'manual'),
			'next_files'           => wp_next_scheduled('sgbackup_backup'),
			'next_database'        => wp_next_scheduled('sgbackup_backup_database'),
			'next_incremental'     => wp_next_scheduled('sgbackup_backup_incremental'),
			'next_remotesend'      => wp_next_scheduled('sgbackup_backup_remotesend'),
		);
	}

	// ==========================================
	// BACKUP ENTRY POINTS
	// ==========================================

	/**
	 * Cron entry: backup files only.
	 */
	public function backup_files() {
		$this->boot_backup(true, false);
	}

	/**
	 * Cron entry: backup database only.
	 */
	public function backup_database() {
		$this->boot_backup(false, true);
	}

	/**
	 * Manual/cron entry: backup everything.
	 */
	public function backup_all($options = array()) {
		$backup_files = !empty($options['nofiles']) ? false : true;
		$backup_db = !empty($options['nodb']) ? false : true;
		$this->boot_backup($backup_files, $backup_db, $options);
	}

	/**
	 * Incremental backup entry point.
	 */
	public function backup_incremental() {
		$opts = SiteGuard_Backup_Options::instance();

		// Find the most recent full backup
		$history = SiteGuard_Backup_History::get_history();
		$parent_timestamp = null;

		foreach ($history as $timestamp => $backup) {
			if (!empty($backup['nonce']) && empty($backup['incremental_parent'])) {
				$parent_timestamp = $timestamp;
				break;
			}
		}

		if (!$parent_timestamp) {
			$this->log("No parent backup found for incremental - running full backup instead");
			$this->backup_all();
			return;
		}

		// M10: Check if incremental chain has exceeded threshold
		if ($this->should_force_full_backup()) {
			$this->log("Incremental threshold reached - running full backup instead");
			$this->backup_all();
			return;
		}

		$parent_backup = $history[$parent_timestamp];
		$options = array(
			'job_type'            => 'incremental',
			'incremental_parent'  => $parent_timestamp,
			'parent_nonce'        => $parent_backup['nonce'],
			'nodb'                => true, // No DB in incrementals
		);

		$this->boot_backup(true, false, $options);
	}

	/**
	 * Initialize a backup job. Does NOT execute the backup - schedules first resumption.
	 *
	 * @param bool  $backup_files    Whether to backup files.
	 * @param bool  $backup_database Whether to backup the database.
	 * @param array $options         Additional options.
	 */
	private function boot_backup($backup_files, $backup_database, $options = array()) {
		@set_time_limit(SGBACKUP_SET_TIME_LIMIT);

		// Check if another backup job is already running
		require_once SGBACKUP_DIR . 'includes/class-job-scheduler.php';
		$active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
		if (!empty($active_jobs)) {
			$existing_nonce = key($active_jobs);
			$existing = $active_jobs[$existing_nonce];
			$msg = "Cannot start backup: another job is already running (nonce: {$existing_nonce}, resumption: {$existing['resumption']})";
			error_log('SiteGuard Backup: ' . $msg);
			$this->boot_error = $msg;
			return;
		}

		// Prevent parallel job creation
		$sem = new SiteGuard_Backup_Semaphore('sgbackup_boot');
		if (!$sem->lock()) {
			$this->boot_error = 'Another backup is already being initialized. Please wait a moment and try again.';
			error_log('SiteGuard Backup: Another backup is already being initialized');
			return;
		}
		try {

		$opts = SiteGuard_Backup_Options::instance();

		// Generate unique nonce for this backup job
		$this->nonce = substr(wp_generate_password(12, false, false), 0, 12);
		$this->file_nonce = $this->nonce;
		$this->backup_time = time();
		$this->job_time_ms = microtime(true);

		// Open log
		$this->logfile_open($this->nonce);
		$this->log("Backup job starting. Nonce: {$this->nonce}");
		$this->log("Files: " . ($backup_files ? 'yes' : 'no') . ", Database: " . ($backup_database ? 'yes' : 'no'));

		// Determine which entities to backup
		$entities = array();
		if ($backup_files) {
			$include = $opts->get_option('sgbackup_include_entities', array('plugins', 'themes', 'uploads', 'others'));
			if (!empty($options['entities'])) {
				$include = $options['entities'];
			}
			foreach ($include as $entity) {
				$entities[$entity] = array('index' => 0, 'status' => 'pending');
			}
		}

		// Get services
		$services = $opts->get_option('sgbackup_service', array('none'));
		if (!empty($options['service'])) {
			$services = is_array($options['service']) ? $options['service'] : array($options['service']);
		}

		$job_type = isset($options['job_type']) ? $options['job_type'] : 'backup';
		$resume_interval = SiteGuard_Backup_Job_Scheduler::get_initial_resume_interval();

		// Build initial job data
		$initial_jobdata = array(
			'backup_time'      => $this->backup_time,
			'job_time_ms'      => $this->job_time_ms,
			'nonce'            => $this->nonce,
			'file_nonce'       => $this->file_nonce,
			'job_type'         => $job_type,
			'jobstatus'        => 'begun',
			'backup_files'     => $backup_files ? 'yes' : 'no',
			'backup_database'  => $backup_database ? 'yes' : 'no',
			'job_file_entities' => $entities,
			'service'          => $services,
			'resume_interval'  => $resume_interval,
			'split_every'      => $opts->get_option('sgbackup_split_every', SGBACKUP_DEFAULT_SPLIT) * 1048576,
			'maxzipbatch'      => 26214400, // 25MB initial
			'maxzipbatch_ceiling' => 209715200, // 200MB ceiling
			'useful_checkins'  => array(),
			'run_times'        => array(),
			'errors'           => array(),
			'always_keep'      => !empty($options['always_keep']),
			'backup_files_array'   => array(),
			'db_backup_files'      => array(),
			'db_completed_tables'  => array(),
		);

		// Incremental-specific data
		if ('incremental' === $job_type) {
			$initial_jobdata['incremental_parent'] = $options['incremental_parent'];
			$initial_jobdata['parent_nonce'] = $options['parent_nonce'];
		}

		// H4: Backup label
		if (!empty($options['label'])) {
			$initial_jobdata['label'] = $options['label'];
		}

		// H6: Per-table selection
		if (!empty($options['include_tables']) && is_array($options['include_tables'])) {
			$initial_jobdata['include_tables'] = $options['include_tables'];
		}
		if (!empty($options['exclude_tables']) && is_array($options['exclude_tables'])) {
			$initial_jobdata['exclude_tables'] = $options['exclude_tables'];
		}

		// M3: External databases
		$extradbs = $opts->get_option('sgbackup_extradbs', array());
		if (!empty($extradbs)) {
			$initial_jobdata['extradbs'] = $extradbs;
		}

		// M4: Non-WordPress tables
		$backupdb_nonwp = $opts->get_option('sgbackup_backupdb_nonwp', false);
		if ($backupdb_nonwp || !empty($options['include_nonwp_tables'])) {
			$initial_jobdata['backupdb_nonwp'] = true;
		}

		// M5: Database anonymisation
		if (!empty($options['db_anonymise'])) {
			$initial_jobdata['db_anonymise'] = true;
			$initial_jobdata['db_anonymise_mode'] = isset($options['db_anonymise_mode']) ? $options['db_anonymise_mode'] : 'all';
		}

		// Auto-backup data
		if (!empty($options['autobackup'])) {
			$initial_jobdata['is_autobackup'] = true;
		}

		// Per-subsite backup: detect if running from a subsite admin context
		if (is_multisite() && get_current_blog_id() > 1 && !empty($options['subsite_backup'])) {
			$blog_id = get_current_blog_id();
			$blog_details = get_blog_details($blog_id);
			$initial_jobdata['is_subsite_backup'] = true;
			$initial_jobdata['subsite_blog_id'] = $blog_id;
			$initial_jobdata['subsite_domain'] = $blog_details ? $blog_details->domain : '';
			$initial_jobdata['subsite_path'] = $blog_details ? $blog_details->path : '/';
		}

		// Merge any extra jobdata (e.g., from migrate_send pre-setting phase flags)
		if (!empty($options['initial_jobdata_extra']) && is_array($options['initial_jobdata_extra'])) {
			$initial_jobdata = array_merge($initial_jobdata, $options['initial_jobdata_extra']);
		}

		// Allow filtering
		$initial_jobdata = apply_filters('sgbackup_initial_jobdata', $initial_jobdata, $options);

		$this->jobdata = $initial_jobdata;
		$this->save_jobdata();

		} finally {
			$sem->unlock();
		}

		// Schedule first resumption nearly immediately (5s) so the backup starts fast.
		// The $resume_interval (300s) is stored in jobdata for subsequent resumptions as insurance.
		$initial_delay = 5;
		SiteGuard_Backup_Job_Scheduler::schedule_resumption(0, $this->nonce, $initial_delay);

		$this->log("Boot complete. First resumption scheduled in {$initial_delay}s.");
		$this->logfile_close();

		// Trigger WP-Cron to fire the scheduled event promptly
		spawn_cron();
	}

	// ==========================================
	// BACKUP RESUME (Main Orchestration)
	// ==========================================

	/**
	 * Resume a backup job. This is the main execution entry point, called by WP-Cron.
	 *
	 * @param int    $resumption_no Resumption counter.
	 * @param string $bnonce        Backup job nonce.
	 */
	public function backup_resume($resumption_no, $bnonce) {
		@set_time_limit(SGBACKUP_SET_TIME_LIMIT);
		@ignore_user_abort(true);

		// Track the wall-clock start of the entire PHP process (not per-resumption)
		// for the direct-chain time budget check at the end.
		if (!isset($this->_process_start)) {
			$this->_process_start = microtime(true);
		}

		$this->current_resumption = $resumption_no;
		$this->nonce = $bnonce;
		$this->something_useful_happened = false;
		$this->newresumption_scheduled = false;

		// Load job data
		$this->load_jobdata();
		if (empty($this->jobdata)) {
			error_log("SiteGuard Backup: no job data for nonce {$bnonce}");
			return;
		}

		// Don't run if job is already in a terminal state
		$jobstatus = $this->jobdata_get('jobstatus', '');
		$terminal = array('complete', 'finished', 'error', 'aborted', 'failed', 'warning');
		if (in_array($jobstatus, $terminal, true)) {
			return;
		}

		$this->backup_time = $this->jobdata_get('backup_time');
		$this->file_nonce = $this->jobdata_get('file_nonce', $bnonce);
		$this->job_time_ms = microtime(true);

		// Open log
		$this->logfile_open($this->nonce);
		$this->log("=== Resumption {$resumption_no} ===");
		$this->log("Memory: " . SiteGuard_Backup_Filesystem::format_bytes(memory_get_usage(true)));

		// Check for abort
		if (SiteGuard_Backup_Job_Scheduler::is_abort_requested($bnonce)) {
			$this->log("Abort requested - cleaning up");
			SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($bnonce);
			$this->backup_finish(true, false, true);
			return;
		}

		// Infinite loop prevention: if multiple consecutive resumptions
		// produced no useful work, abort to avoid looping forever.
		$useful_checkins = $this->jobdata_get('useful_checkins', array());
		$max_no_progress = apply_filters('sgbackup_max_resumptions_no_progress', 8);
		if ($resumption_no >= $max_no_progress) {
			$consecutive_idle = 0;
			for ($r = $resumption_no - 1; $r >= 0 && $r >= $resumption_no - $max_no_progress; $r--) {
				if (!isset($useful_checkins[$r])) {
					$consecutive_idle++;
				} else {
					break;
				}
			}
			if ($consecutive_idle >= $max_no_progress) {
				$this->log("Aborting: {$consecutive_idle} consecutive resumptions with no progress. " .
					"This indicates the backup is stuck.", 'error');
				$this->jobdata_set('jobstatus', 'error');
				$this->jobdata_set('fail_reason', 'no_progress_loop');
				SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($bnonce);
				SiteGuard_Backup_Webhook::fire('backup_failed', array(
					'nonce'   => $this->nonce,
					'message' => "Backup aborted after {$consecutive_idle} resumptions with no progress",
				));
				$this->logfile_close();
				return;
			}
		}

		// Semaphore - prevent overlapping runs
		$semaphore = new SiteGuard_Backup_Semaphore('sgbackup_sem_' . $bnonce);
		if (!$semaphore->lock()) {
			// Don't log or reschedule - the direct chain handles this.
			// Only reschedule if there's no other resumption in the cron queue.
			$active = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
			if (empty($active) || !isset($active[$bnonce])) {
				SiteGuard_Backup_Job_Scheduler::schedule_resumption($resumption_no, $bnonce, 120);
			}
			$this->logfile_close();
			return;
		}

		// Update job status
		$this->jobdata_set('jobstatus', 'running');
		$this->jobdata_set('current_resumption', $resumption_no);

		// Auto-adjust batch size if previous resumption didn't check in
		if ($resumption_no > 0 && !isset($useful_checkins[$resumption_no - 1])) {
			$maxzipbatch = $this->jobdata_get('maxzipbatch', 26214400);
			$new_batch = max(5242880, intval($maxzipbatch * 0.75)); // 75%, min 5MB
			if ($new_batch < $maxzipbatch) {
				$this->jobdata_set('maxzipbatch', $new_batch);
				$this->jobdata_set('maxzipbatch_ceiling', $new_batch);
				$this->log("Previous resumption had no checkin - reduced maxzipbatch + ceiling to " .
					SiteGuard_Backup_Filesystem::format_bytes($new_batch));
			}
		}

		try {
			$this->do_backup_work($resumption_no, $semaphore);
		} catch (\Throwable $e) {
			$this->jobdata_set('jobstatus', 'failed');
			$this->log("Exception during backup: " . $e->getMessage(), 'error');
			SiteGuard_Backup_Webhook::fire('backup_failed', array(
				'nonce'   => $this->nonce,
				'message' => $e->getMessage(),
			));
			SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($bnonce);
			$semaphore->unlock();
			$this->logfile_close();
			return;
		}

		// Runtime tracking for auto-tuning
		$run_time = microtime(true) - $this->job_time_ms;
		$run_times = $this->jobdata_get('run_times', array());
		$run_times[$resumption_no] = $run_time;
		$this->jobdata_set('run_times', $run_times);

		$semaphore->unlock();
		$this->logfile_close();

		// Direct chain: continue in this PHP process if time permits.
		// Skip in WP-CLI (has its own loop) and when job is done.
		$jobstatus = $this->jobdata_get('jobstatus', '');
		if (!defined('WP_CLI') && !in_array($jobstatus, $terminal, true)) {
			// Use process wall-clock time (not per-resumption) for the budget
			$process_elapsed = microtime(true) - $this->_process_start;
			$max_time = (int) ini_get('max_execution_time');
			if ($max_time <= 0) $max_time = SGBACKUP_SET_TIME_LIMIT;

			if ($process_elapsed < ($max_time * 0.7) && $resumption_no < 50) {
				// Clear stale cron events, schedule one safety net
				SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($bnonce);
				SiteGuard_Backup_Job_Scheduler::schedule_resumption(
					$resumption_no + 1, $bnonce, 300
				);
				$this->backup_resume($resumption_no + 1, $bnonce);
				return;
			}
		}
	}

	/**
	 * Perform the actual backup work for a resumption.
	 */
	private function do_backup_work($resumption_no, $semaphore) {
		global $wpdb;

		$backup_files = $this->jobdata_get('backup_files') === 'yes';
		$backup_database = $this->jobdata_get('backup_database') === 'yes';

		// Verify database connection is alive (can drop on Azure/long-running processes)
		if (method_exists($wpdb, 'check_connection') && !$wpdb->check_connection(false)) {
			$this->log("Database connection lost - will retry on next resumption", 'warning');
			// Don't try to schedule via DB (it's down). The safety-net cron event
			// from the direct-chain caller or a previously scheduled resumption will retry.
			return;
		}

		// Check disk space before starting (cPanel quota or disk_free_space)
		$available_bytes = $this->check_cpanel_quota();
		if (false !== $available_bytes) {
			if ($available_bytes < 20971520) { // < 20MB
				$this->log("Aborting backup: disk space critically low (" .
					SiteGuard_Backup_Filesystem::format_bytes($available_bytes) . " available). " .
					"Less than 20MB free - backup cannot proceed safely.", 'error');
				$this->jobdata_set('jobstatus', 'error');
				SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($this->nonce);
				return;
			} elseif ($available_bytes < 104857600) { // < 100MB
				$this->log("Warning: disk space is low (" .
					SiteGuard_Backup_Filesystem::format_bytes($available_bytes) . " available)", 'warning');
			}
		}

		// Phase 1: File backup
		if ($backup_files) {
			$files_status = $this->jobdata_get('files_phase_complete', false);
			if (!$files_status) {
				if ($resumption_no === 0) $this->log("Starting file backup phase");
				require_once SGBACKUP_DIR . 'backup.php';
				$engine = new SiteGuard_Backup_Engine($this);
				$result = $engine->backup_files();

				if ($result === true) {
					$this->jobdata_set('files_phase_complete', true);
					$this->log("File backup phase complete");
				} elseif ($result === 'needs_resumption') {
					$this->log("File backup needs another resumption");
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();
					return;
				} else {
					$this->log("File backup failed: " . print_r($result, true), 'error');
					$this->jobdata_set('jobstatus', 'error');
					return;
				}
			}
		}

		// Phase 2: Database backup
		if ($backup_database) {
			$db_status = $this->jobdata_get('db_phase_complete', false);
			if (!$db_status) {
				$this->log("Starting database backup phase");
				require_once SGBACKUP_DIR . 'backup.php';
				$engine = new SiteGuard_Backup_Engine($this);
				$result = $engine->backup_database();

				if ($result === true) {
					$this->jobdata_set('db_phase_complete', true);
					$this->log("Database backup phase complete");
				} elseif ($result === 'needs_resumption') {
					$this->log("Database backup needs another resumption");
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();
					return;
				} else {
					$this->log("Database backup failed: " . print_r($result, true), 'error');
					$this->jobdata_set('jobstatus', 'error');
					return;
				}
			}
		}

		// Phase 2.5: Encrypt backup files if enabled
		$encryption_done = $this->jobdata_get('encryption_phase_complete', false);
		if (!$encryption_done) {
			$opts_enc = SiteGuard_Backup_Options::instance();
			if ($opts_enc->get_option('sgbackup_encryption_enabled', false)) {
				$enc_key = $opts_enc->get_option('sgbackup_encryption_key', '');
				if (empty($enc_key)) {
					$this->log("WARNING: Encryption is enabled but no encryption key is set - backup will NOT be encrypted", 'warning');
				} elseif (!empty($enc_key)) {
					$this->log("Starting encryption phase");
					require_once SGBACKUP_DIR . 'includes/class-encryption.php';

					$backup_array = $this->jobdata_get('backup_files_array', array());
					$db_files = $this->jobdata_get('db_backup_files', array());
					$all_to_encrypt = array_merge($backup_array, $db_files);
					$encrypted_backup_array = array();
					$encrypted_db_files = array();

					foreach ($all_to_encrypt as $file) {
						$filepath = $this->get_backup_dir() . $file;
						if (!file_exists($filepath)) continue;

						$encrypted_path = SiteGuard_Backup_Encryption::encrypt($filepath, $enc_key);
						if ($encrypted_path) {
							$encrypted_name = basename($encrypted_path);
							if (in_array($file, $db_files)) {
								$encrypted_db_files[] = $encrypted_name;
							} else {
								$encrypted_backup_array[] = $encrypted_name;
							}
							@unlink($filepath);
							$this->log("Encrypted: {$file} -> {$encrypted_name}");
						} else {
							$this->log("Encryption failed for: {$file}", 'error');
							if (in_array($file, $db_files)) {
								$encrypted_db_files[] = $file;
							} else {
								$encrypted_backup_array[] = $file;
							}
						}
						SiteGuard_Backup_Job_Scheduler::something_useful_happened();
					}

					$this->jobdata_set('backup_files_array', $encrypted_backup_array);
					$this->jobdata_set('db_backup_files', $encrypted_db_files);
					$this->log("Encryption phase complete");
				}
			}
			$this->jobdata_set('encryption_phase_complete', true);
		}

		// Phase 2.75: Generate manifest before upload so it gets included
		$manifest_done = $this->jobdata_get('manifest_phase_complete', false);
		if (!$manifest_done) {
			$this->generate_manifest_early();
			$this->jobdata_set('manifest_phase_complete', true);
		}

		// Phase 3: Upload to remote storage
		$upload_status = $this->jobdata_get('upload_phase_complete', false);
		if (!$upload_status) {
			$services = $this->jobdata_get('service', array('none'));
			$has_remote = false;
			foreach ($services as $svc) {
				if ('none' !== $svc) {
					$has_remote = true;
					break;
				}
			}

			if ($has_remote) {
				$this->log("Starting upload phase");
				$result = $this->upload_to_remote();
				if ($result === 'needs_resumption') {
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();
					return;
				}
			}
			$this->jobdata_set('upload_phase_complete', true);
		}

		// Validate that the backup actually produced files
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		if (empty($backup_array) && empty($db_files)) {
			$wanted_files = ($this->jobdata_get('backup_files') === 'yes');
			$wanted_db = ($this->jobdata_get('backup_database') === 'yes');
			if ($wanted_files || $wanted_db) {
				$this->log("Backup produced no output files despite requesting " .
					($wanted_files ? 'files' : '') . ($wanted_files && $wanted_db ? '+' : '') .
					($wanted_db ? 'database' : '') . " - marking as failed", 'error');
				$this->jobdata_set('jobstatus', 'error');
				SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($this->nonce);
				return;
			}
		}

		// Check for failed entities - mark backup as having errors
		$failed_entities = $this->jobdata_get('failed_entities', array());
		$has_entity_failures = !empty($failed_entities);

		// All phases complete
		$this->backup_finish(true, true, false, $has_entity_failures);
	}

	/**
	 * Upload backup files to remote storage.
	 */
	private function upload_to_remote() {
		$services = $this->jobdata_get('service', array('none'));
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$all_files = array_values(array_unique(array_merge($backup_array, $db_files)));

		if (empty($all_files)) return true;

		$backup_dir = $this->get_backup_dir();
		$any_service_succeeded = false;

		foreach ($services as $service) {
			if ('none' === $service) continue;

			// Parse service name (handle remotesend/{site_id} format)
			$method_name = $service;
			$service_instance = null;
			if (strpos($service, '/') !== false) {
				list($method_name, $service_instance) = explode('/', $service, 2);
			}

			if (!array_key_exists($method_name, $this->backup_methods)) {
				$this->log("Unknown storage method: {$method_name}", 'warning');
				continue;
			}

			$method_file = SGBACKUP_DIR . 'methods/' . $method_name . '.php';
			if (!file_exists($method_file)) {
				$this->log("Storage method file not found: {$method_name}", 'warning');
				continue;
			}

			require_once SGBACKUP_DIR . 'methods/backup-module.php';
			require_once $method_file;

			$class = 'SiteGuard_Backup_Method_' . ucfirst($method_name);
			if (!class_exists($class)) {
				$this->log("Storage method class not found: {$class}", 'warning');
				continue;
			}

			$method = new $class();
			if (null !== $service_instance) {
				$method->set_instance_id($service_instance);
			}

			// L4: Evaluate per-instance conditional logic before uploading
			$instance_opts = $method->get_options();
			if (!$method->evaluate_conditional_logic($instance_opts)) {
				$this->log("Skipping upload to {$service}: conditional logic rules not met");
				continue;
			}

			$this->log("Uploading to {$service}");

			$service_all_ok = true;
			$upload_index = 0;
			$upload_total = count($all_files);
			foreach ($all_files as $file) {
				$upload_index++;
				$this->jobdata_set('last_activity', "Uploading {$upload_index}/{$upload_total}: {$file}");
				// Prevent path traversal via tainted jobdata filenames
				if (basename($file) !== $file || strpos($file, '..') !== false) {
					$this->log("Rejected unsafe filename in upload: {$file}", 'error');
					continue;
				}
				$filepath = $backup_dir . $file;
				if (!file_exists($filepath)) {
					$this->log("File not found for upload: {$file}", 'warning');
					$service_all_ok = false;
					continue;
				}

				try {
					$result = $method->backup($filepath);
					if (is_wp_error($result)) {
						$this->log("Upload error ({$service}): " . $result->get_error_message(), 'error');
						$service_all_ok = false;
					} else {
						$this->log("Uploaded {$file} to {$service}");
					}
				} catch (Exception $e) {
					$this->log("Upload exception ({$service}): " . $e->getMessage(), 'error');
					$service_all_ok = false;
				}

				SiteGuard_Backup_Job_Scheduler::something_useful_happened();
			}

			if ($service_all_ok) {
				$any_service_succeeded = true;
				// Send metadata (label, always_keep, source_url) after all files uploaded
				if ($method_name === 'remotesend') {
					$this->send_remotesend_metadata($service_instance);
				}
			}
		}

		$this->jobdata_set('remote_upload_succeeded', $any_service_succeeded);
		return true;
	}

	/**
	 * Send backup metadata to a remote site after all files have been uploaded.
	 *
	 * @param string|null $instance_id The remotesend instance (site_id).
	 */
	private function send_remotesend_metadata($instance_id) {
		$label = $this->jobdata_get('label', '');
		$always_keep = $this->jobdata_get('always_keep', false);
		$timestamp = $this->jobdata_get('original_backup_timestamp', $this->backup_time);
		$nonce = $this->jobdata_get('file_nonce', $this->nonce);

		$is_subsite_backup = $this->jobdata_get('is_subsite_backup', false);
		$subsite_blog_id = $this->jobdata_get('subsite_blog_id', 0);
		$subsite_domain = $this->jobdata_get('subsite_domain', '');

		// Nothing to send if no meaningful metadata
		if (empty($label) && !$always_keep && !$is_subsite_backup) return;

		$site_id = ($instance_id !== null) ? intval($instance_id) : 0;
		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		if (!isset($sites[$site_id])) {
			$this->log("RemoteSend metadata: site #{$site_id} not found, skipping");
			return;
		}

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';
		$rpc = new SiteGuard_Backup_Remote_Communications();

		$payload = array(
			'timestamp'  => intval($timestamp),
			'nonce'      => $nonce,
			'source_url' => site_url(),
		);
		if (!empty($label)) $payload['label'] = $label;
		if ($always_keep) $payload['always_keep'] = true;
		if ($is_subsite_backup) {
			$payload['is_subsite_backup'] = true;
			$payload['subsite_blog_id'] = intval($subsite_blog_id);
			$payload['subsite_domain'] = $subsite_domain;
		}

		$result = $rpc->send_message($sites[$site_id], 'set_backup_metadata', $payload);
		if (is_wp_error($result)) {
			$this->log("RemoteSend metadata: failed to send - " . $result->get_error_message(), 'warning');
		} else {
			$this->log("RemoteSend metadata: label/metadata sent to remote site");
		}
	}

	/**
	 * Finalize a backup job.
	 *
	 * @param bool $do_cleanup        Whether to clean up job data.
	 * @param bool $allow_email       Whether to send email report.
	 * @param bool $force_abort       Whether this is an abort.
	 * @param bool $has_entity_errors Whether any file entities failed.
	 */
	public function backup_finish($do_cleanup, $allow_email, $force_abort = false, $has_entity_errors = false) {
		if ($force_abort) {
			$this->jobdata_set('jobstatus', 'aborted');
			$this->log("Backup aborted");
		} else {
			if ($has_entity_errors) {
				$failed = $this->jobdata_get('failed_entities', array());
				$this->jobdata_set('jobstatus', 'warning');
				$this->log("Backup finished with ERRORS - missing entities: " . implode(', ', $failed), 'error');
			} else {
				$this->jobdata_set('jobstatus', 'complete');
				$this->log("Backup complete");
			}

			// Save to backup history (even with warnings, so partial backup is available)
			$this->save_to_history();

			SiteGuard_Backup_Webhook::fire('backup_complete', array(
				'nonce'    => $this->nonce,
				'job_type' => $this->jobdata_get('job_type', 'backup'),
				'entities' => array_keys($this->jobdata_get('job_file_entities', array())),
				'has_errors' => $has_entity_errors,
			));
		}

		// Clear scheduled resumptions
		SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($this->nonce);

		// Clean up abort flag
		$abort_file = $this->get_backup_dir() . 'deleteflag-' . $this->nonce . '.txt';
		if (file_exists($abort_file)) {
			@unlink($abort_file);
		}

		// Send email report
		if ($allow_email && !$force_abort) {
			$this->send_email_report();
		}

		// Prune old backups
		if (!$force_abort) {
			SiteGuard_Backup_History::prune('full');
			SiteGuard_Backup_History::prune('db');
			$this->prune_retained_backups();
		}

		// Clean up old log files (older than 30 days)
		$this->cleanup_old_logs(30);

		// M10: Log if incremental consolidation threshold is approaching
		if (!$force_abort && $this->jobdata_get('job_type') === 'incremental') {
			if ($this->consolidate_incrementals()) {
				$this->log("Next scheduled incremental will be promoted to a full backup");
			}
		}

		// Delete local copies only if configured AND remote upload actually succeeded
		$opts = SiteGuard_Backup_Options::instance();
		if ($opts->get_option('sgbackup_delete_local', 0) && !$force_abort) {
			$upload_ok = $this->jobdata_get('remote_upload_succeeded', false);
			if ($upload_ok) {
				$this->delete_local_copies();
			} elseif ($this->jobdata_get('service', array('none')) !== array('none')) {
				$this->log("Keeping local copies because remote upload did not fully succeed", 'warning');
			}
		}

		// Clean up temp files
		SiteGuard_Backup_Filesystem::clean_temp_files($this->get_backup_dir());

		if ($do_cleanup) {
			// Keep job data for 14 days for debugging
			$this->jobdata_set('cleanup_time', time() + (14 * DAY_IN_SECONDS));
		}

		// Remove external DB credentials from jobdata for security
		$extradbs = $this->jobdata_get('extradbs', array());
		if (!empty($extradbs)) {
			foreach ($extradbs as &$extdb) {
				unset($extdb['pass']);
			}
			$this->jobdata_set('extradbs', $extradbs);
		}

		$this->logfile_close();
	}

	/**
	 * Clean up old log files.
	 *
	 * @param int $days Delete log files older than this many days.
	 */
	private function cleanup_old_logs($days) {
		$backup_dir = $this->get_backup_dir();
		if (empty($backup_dir) || !is_dir($backup_dir)) return;

		$cutoff = time() - ($days * DAY_IN_SECONDS);
		$log_files = glob($backup_dir . 'log.*.txt');

		if (empty($log_files)) return;

		foreach ($log_files as $log_file) {
			if (filemtime($log_file) < $cutoff) {
				@unlink($log_file);
			}
		}
	}

	/**
	 * Save the completed backup to history.
	 */
	private function save_to_history() {
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$job_type = $this->jobdata_get('job_type', 'backup');

		$backup_data = array(
			'nonce'   => $this->nonce,
			'service' => $this->jobdata_get('service', array('none')),
		);

		// Add file entities
		$entities = $this->jobdata_get('job_file_entities', array());
		foreach ($entities as $entity => $info) {
			$entity_files = array();
			foreach ($backup_array as $file) {
				if (strpos($file, "-{$entity}") !== false) {
					$entity_files[] = $file;
				}
			}
			if (!empty($entity_files)) {
				$backup_data[$entity] = $entity_files;
			}
		}

		// Add DB files
		if (!empty($db_files)) {
			$backup_data['db'] = $db_files;
		}

		// Calculate total size - clear stat cache for accurate sizes on CIFS mounts
		$total_size = 0;
		$backup_dir = $this->get_backup_dir();
		$all_files = array_merge($backup_array, $db_files);
		clearstatcache();
		foreach ($all_files as $file) {
			$path = $backup_dir . $file;
			if (file_exists($path)) {
				$total_size += filesize($path);
			}
		}
		$backup_data['size'] = $total_size;

		// Per-entity file sizes
		$backup_dir_path = $this->get_backup_dir();
		foreach ($entities as $entity => $info) {
			$entity_size = 0;
			foreach ($backup_array as $f) {
				if (strpos($f, "-{$entity}") !== false) {
					$p = $backup_dir_path . $f;
					if (file_exists($p)) $entity_size += filesize($p);
				}
			}
			if ($entity_size > 0) {
				$backup_data[$entity . '_size'] = $entity_size;
			}
		}
		// DB size
		$db_size = 0;
		foreach ($db_files as $f) {
			$p = $backup_dir_path . $f;
			if (file_exists($p)) $db_size += filesize($p);
		}
		if ($db_size > 0) $backup_data['db_size'] = $db_size;

		// Mark always_keep
		if ($this->jobdata_get('always_keep', false)) {
			$backup_data['always_keep'] = true;
		}

		// H4: Include label if set
		$label = $this->jobdata_get('label', '');
		if (!empty($label)) {
			$backup_data['label'] = $label;
		}

		// Per-subsite backup metadata
		if ($this->jobdata_get('is_subsite_backup', false)) {
			$backup_data['is_subsite_backup'] = true;
			$backup_data['subsite_blog_id'] = $this->jobdata_get('subsite_blog_id', 0);
			$backup_data['subsite_domain'] = $this->jobdata_get('subsite_domain', '');
			$backup_data['subsite_path'] = $this->jobdata_get('subsite_path', '/');
		}

		// Handle incremental merging
		if ('incremental' === $job_type) {
			$parent_timestamp = $this->jobdata_get('incremental_parent');
			if ($parent_timestamp) {
				SiteGuard_Backup_History::merge_incremental($parent_timestamp, $backup_data);
				$this->log("Incremental merged into parent backup (timestamp: {$parent_timestamp})");
				return;
			}
		}

		// Add manifest reference if it was already generated in the manifest phase
		$manifest_filename = $this->jobdata_get('manifest_filename', '');
		if (!empty($manifest_filename)) {
			$backup_data['manifest'] = $manifest_filename;
		}

		// Record failed entities so UI can show warnings
		$failed_entities = $this->jobdata_get('failed_entities', array());
		if (!empty($failed_entities)) {
			$backup_data['failed_entities'] = $failed_entities;
		}

		SiteGuard_Backup_History::add_backup($this->backup_time, $backup_data);
		$status_suffix = !empty($failed_entities) ? ' (INCOMPLETE - missing: ' . implode(', ', $failed_entities) . ')' : '';
		$this->log("Saved to backup history. Size: " . SiteGuard_Backup_Filesystem::format_bytes($total_size) . $status_suffix);
	}

	/**
	 * M7: Send styled HTML email report about backup completion.
	 */
	private function send_email_report() {
		$opts = SiteGuard_Backup_Options::instance();

		// Syslog support
		$syslog_enabled = $opts->get_option('sgbackup_syslog_enabled', false);
		if ($syslog_enabled) {
			$this->write_syslog();
		}

		$email_mode = $opts->get_option('sgbackup_email_mode', 'always');
		if ($email_mode === 'never') return;

		$errors = $this->jobdata_get('errors', array());
		$warnings = $this->jobdata_get('warnings', array());
		$has_warnings_or_errors = !empty($errors) || !empty($warnings);
		if ($email_mode === 'errors_only' && !$has_warnings_or_errors) return;

		// Support structured recipients (array of objects) or legacy comma-separated string
		$recipients_raw = $opts->get_option('sgbackup_email_recipients', array());
		$recipients = array();

		if (is_array($recipients_raw) && !empty($recipients_raw)) {
			// Check if it's the new structured format (array of associative arrays)
			if (isset($recipients_raw[0]) && is_array($recipients_raw[0])) {
				foreach ($recipients_raw as $entry) {
					$addr = isset($entry['address']) ? $entry['address'] : '';
					if (!empty($addr) && is_email($addr)) {
						$recipients[] = array(
							'address'       => $addr,
							'warnings_only' => !empty($entry['warnings_only']),
							'send_backup'   => !empty($entry['send_backup']),
							'send_db_only'  => !empty($entry['send_db_only']),
						);
					}
				}
			}
		} elseif (is_string($recipients_raw) && !empty($recipients_raw)) {
			// Legacy comma-separated string
			$addrs = array_map('trim', explode(',', $recipients_raw));
			foreach ($addrs as $addr) {
				if (is_email($addr)) {
					$recipients[] = array(
						'address'       => $addr,
						'warnings_only' => false,
						'send_backup'   => false,
						'send_db_only'  => false,
					);
				}
			}
		}

		// Fallback to the admin email option
		if (empty($recipients)) {
			$fallback = $opts->get_option('sgbackup_email', '');
			if (!empty($fallback) && is_email($fallback)) {
				$recipients[] = array(
					'address'       => $fallback,
					'warnings_only' => false,
					'send_backup'   => false,
					'send_db_only'  => false,
				);
			}
		}

		if (empty($recipients)) return;

		// Determine status
		if (!empty($errors)) {
			$status = 'Failed';
			$status_color = '#dc3545';
		} elseif (!empty($warnings)) {
			$status = 'Warning';
			$status_color = '#ffc107';
		} else {
			$status = 'Success';
			$status_color = '#28a745';
		}

		$site_name = get_bloginfo('name');
		$site_url = site_url();
		$job_type = $this->jobdata_get('job_type', 'backup');
		$subject = "[SiteGuard Backup] {$status}: {$site_name}";

		/**
		 * Filter the email report subject line.
		 *
		 * @param string              $subject The email subject.
		 * @param string              $status  Backup status: 'Success', 'Warning', or 'Failed'.
		 * @param SiteGuard_Backup    $this    The backup instance.
		 */
		$subject = apply_filters('sgbackup_report_subject', $subject, $status, $this);

		// Collect file info
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$all_files = array_merge($backup_array, $db_files);
		$backup_dir = $this->get_backup_dir();

		// Build HTML body
		$body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#333;">';

		// Header with status color
		$body .= '<div style="background-color:' . $status_color . ';color:#fff;padding:20px 30px;text-align:center;">';
		$body .= '<h1 style="margin:0;font-size:24px;">Backup ' . esc_html($status) . '</h1>';
		$body .= '</div>';

		// Summary table
		$body .= '<div style="padding:20px 30px;">';
		$body .= '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';
		$body .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;width:160px;">Site</td>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;"><a href="' . esc_url($site_url) . '">' . esc_html($site_name) . '</a></td></tr>';
		$body .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">Backup Type</td>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . esc_html(ucfirst($job_type)) . '</td></tr>';
		$body .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">Date</td>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . esc_html(gmdate('Y-m-d H:i:s', $this->backup_time)) . '</td></tr>';
		$body .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">Job ID</td>';
		$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;"><code>' . esc_html($this->nonce) . '</code></td></tr>';

		// Remote services
		$services = $this->jobdata_get('service', array('none'));
		$remote_services = array_filter($services, function($s) { return $s !== 'none'; });
		if (!empty($remote_services)) {
			$body .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:bold;">Uploaded To</td>';
			$body .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . esc_html(implode(', ', $remote_services)) . '</td></tr>';
		}

		$body .= '</table>';

		// Files table with checksums
		if (!empty($all_files)) {
			$total_size = 0;
			$body .= '<h2 style="font-size:18px;margin:20px 0 10px;">Backup Files</h2>';
			$body .= '<table style="width:100%;border-collapse:collapse;border:1px solid #ddd;">';
			$body .= '<thead><tr style="background:#f7f7f7;">';
			$body .= '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">File</th>';
			$body .= '<th style="padding:8px 12px;text-align:right;border-bottom:2px solid #ddd;">Size</th>';
			$body .= '<th style="padding:8px 12px;text-align:left;border-bottom:2px solid #ddd;">SHA-256</th>';
			$body .= '</tr></thead><tbody>';

			foreach ($all_files as $file) {
				$fpath = $backup_dir . $file;
				clearstatcache(true, $fpath);
				$fsize = file_exists($fpath) ? filesize($fpath) : 0;
				$total_size += $fsize;
				$size_str = file_exists($fpath) ? SiteGuard_Backup_Filesystem::format_bytes($fsize) : '?';
				$sha256 = file_exists($fpath) ? hash_file('sha256', $fpath) : 'N/A';
				$sha256_short = (strlen($sha256) > 16) ? substr($sha256, 0, 16) . '...' : $sha256;

				$body .= '<tr>';
				$body .= '<td style="padding:6px 12px;border-bottom:1px solid #eee;font-family:monospace;font-size:12px;">' . esc_html($file) . '</td>';
				$body .= '<td style="padding:6px 12px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">' . esc_html($size_str) . '</td>';
				$body .= '<td style="padding:6px 12px;border-bottom:1px solid #eee;font-family:monospace;font-size:11px;" title="' . esc_attr($sha256) . '">' . esc_html($sha256_short) . '</td>';
				$body .= '</tr>';
			}

			$body .= '<tr style="background:#f7f7f7;font-weight:bold;">';
			$body .= '<td style="padding:8px 12px;">Total</td>';
			$body .= '<td style="padding:8px 12px;text-align:right;">' . esc_html(SiteGuard_Backup_Filesystem::format_bytes($total_size)) . '</td>';
			$body .= '<td style="padding:8px 12px;"></td>';
			$body .= '</tr></tbody></table>';
		}

		// Download links
		$delete_local = $opts->get_option('sgbackup_delete_local', 0);
		if (!$delete_local && !empty($all_files)) {
			$admin_url = admin_url('admin-ajax.php');
			$body .= '<h2 style="font-size:18px;margin:20px 0 10px;">Download Links</h2><ul style="margin:0;padding-left:20px;">';
			foreach ($all_files as $file) {
				$fpath = $backup_dir . $file;
				if (file_exists($fpath)) {
					$download_url = wp_nonce_url(
						$admin_url . '?action=sgbackup_download&file=' . urlencode(basename($file)),
						'sgbackup-download'
					);
					$body .= '<li style="margin-bottom:4px;"><a href="' . esc_url($download_url) . '">' . esc_html($file) . '</a></li>';
				}
			}
			$body .= '</ul>';
		}

		// Errors section
		if (!empty($errors)) {
			$body .= '<h2 style="font-size:18px;margin:20px 0 10px;color:#dc3545;">Errors</h2>';
			$body .= '<ul style="margin:0;padding-left:20px;color:#dc3545;">';
			foreach ($errors as $error) {
				$body .= '<li style="margin-bottom:4px;">' . esc_html($error['message']) . '</li>';
			}
			$body .= '</ul>';
		}

		// Warnings section
		if (!empty($warnings)) {
			$body .= '<h2 style="font-size:18px;margin:20px 0 10px;color:#856404;">Warnings</h2>';
			$body .= '<ul style="margin:0;padding-left:20px;color:#856404;">';
			foreach ($warnings as $warning) {
				$msg = is_array($warning) ? $warning['message'] : $warning;
				$body .= '<li style="margin-bottom:4px;">' . esc_html($msg) . '</li>';
			}
			$body .= '</ul>';
		}

		// Footer
		$body .= '<hr style="border:none;border-top:1px solid #eee;margin:30px 0 15px;">';
		$body .= '<p style="font-size:12px;color:#999;">SiteGuard Backup v' . esc_html(SGBACKUP_VERSION) . '</p>';
		$body .= '</div></body></html>';

		/**
		 * Filter the email report HTML body.
		 *
		 * @param string              $body      The HTML email body.
		 * @param string              $status    Backup status: 'Success', 'Warning', or 'Failed'.
		 * @param array               $all_files All backup filenames.
		 * @param SiteGuard_Backup    $this      The backup instance.
		 */
		$body = apply_filters('sgbackup_report_body', $body, $status, $all_files, $this);

		// Base attachments: log file when debug mode is on or errors occurred
		$base_attachments = array();
		$debug_mode = $opts->get_option('sgbackup_debug_mode', false);
		if ($debug_mode || !empty($errors)) {
			$logfile = $this->get_logfile_name($this->nonce);
			if (file_exists($logfile)) {
				$base_attachments[] = $logfile;
			}
		}

		/**
		 * Filter the base email attachments (before per-recipient attachments are added).
		 *
		 * @param array               $base_attachments File paths to attach.
		 * @param string              $status           Backup status: 'Success', 'Warning', or 'Failed'.
		 * @param SiteGuard_Backup    $this             The backup instance.
		 */
		$base_attachments = apply_filters('sgbackup_report_attachments', $base_attachments, $status, $this);

		// Attachment size limit (25MB total for email)
		$attachment_size_limit = 25 * 1048576;

		// Set content type to HTML
		add_filter('wp_mail_content_type', array($this, 'email_content_type_html'));

		foreach ($recipients as $recipient) {
			$email_addr = $recipient['address'];

			// Per-recipient: warnings_only - skip if no warnings/errors
			if (!empty($recipient['warnings_only']) && !$has_warnings_or_errors) {
				continue;
			}

			// Build per-recipient attachments
			$attachments = $base_attachments;

			if (!empty($recipient['send_backup']) || !empty($recipient['send_db_only'])) {
				$attach_files = array();
				if (!empty($recipient['send_db_only'])) {
					// Only attach DB backup files
					$attach_files = $db_files;
				} elseif (!empty($recipient['send_backup'])) {
					// Attach all backup files
					$attach_files = $all_files;
				}

				$cumulative_size = 0;
				foreach ($base_attachments as $ba) {
					if (file_exists($ba)) $cumulative_size += filesize($ba);
				}

				foreach ($attach_files as $afile) {
					$apath = $backup_dir . $afile;
					if (!file_exists($apath)) continue;
					$asize = filesize($apath);
					if (($cumulative_size + $asize) > $attachment_size_limit) {
						$this->log("Skipping attachment {$afile} for {$email_addr}: would exceed {$attachment_size_limit} byte limit", 'warning');
						break;
					}
					$attachments[] = $apath;
					$cumulative_size += $asize;
				}
			}

			wp_mail($email_addr, $subject, $body, '', $attachments);
		}

		remove_filter('wp_mail_content_type', array($this, 'email_content_type_html'));

		/**
		 * Fires after all email reports have been sent.
		 *
		 * @param string              $status     Backup status: 'Success', 'Warning', or 'Failed'.
		 * @param array               $recipients Array of recipient data sent to.
		 * @param SiteGuard_Backup    $this       The backup instance.
		 */
		do_action('sgbackup_report_finished', $status, $recipients, $this);
	}

	/**
	 * M7: Return HTML content type for wp_mail filter.
	 *
	 * @return string
	 */
	public function email_content_type_html() {
		return 'text/html';
	}

	/**
	 * Write backup status to syslog.
	 *
	 * @param string $extra_message Optional additional message to include.
	 */
	private function write_syslog($extra_message = '') {
		if (!function_exists('openlog')) return;

		$errors = $this->jobdata_get('errors', array());
		$status = empty($errors) ? 'SUCCESS' : 'ERRORS';
		$site_name = get_bloginfo('name');

		openlog('siteguardbackup', LOG_PID, LOG_USER);
		$msg = "Backup {$status} for {$site_name} (job: {$this->nonce})";
		if (!empty($extra_message)) {
			$msg .= ' - ' . $extra_message;
		}
		$priority = empty($errors) ? LOG_INFO : LOG_WARNING;
		syslog($priority, $msg);

		if (!empty($errors)) {
			foreach ($errors as $error) {
				syslog(LOG_WARNING, "Backup error: " . $error['message']);
			}
		}

		closelog();
	}

	/**
	 * Apply tiered retention rules to prune older backups.
	 *
	 * After basic count-based retention has run, this method applies extra rules
	 * that thin out older backups. For each rule, backups older than `after_count`
	 * sets are grouped, and within that group only 1 backup per `keep_every`
	 * seconds is retained. Backups flagged with `always_keep` are never deleted.
	 */
	private function prune_retained_backups() {
		$opts = SiteGuard_Backup_Options::instance();
		$rules = $opts->get_option('sgbackup_retain_extrarules', array());

		if (empty($rules) || !is_array($rules)) return;

		$history = SiteGuard_Backup_History::get_history();
		if (empty($history)) return;

		// Sort history by timestamp descending (newest first)
		krsort($history);

		foreach (array('files', 'db') as $type) {
			if (empty($rules[$type]) || !is_array($rules[$type])) continue;

			// Separate backups by type
			$type_backups = array();
			foreach ($history as $timestamp => $backup) {
				if ($type === 'db' && !empty($backup['db'])) {
					$type_backups[$timestamp] = $backup;
				} elseif ($type === 'files' && (!empty($backup['plugins']) || !empty($backup['themes']) || !empty($backup['uploads']) || !empty($backup['others']) || !empty($backup['wpcore']))) {
					$type_backups[$timestamp] = $backup;
				}
			}

			foreach ($rules[$type] as $rule) {
				$after_count = intval($rule['after_count']);
				$keep_every = intval($rule['keep_every']);

				if ($after_count < 1 || $keep_every < 1) continue;

				// Skip the first N backups (they are the most recent, protected by count)
				$candidates = array_slice($type_backups, $after_count, null, true);

				if (empty($candidates)) continue;

				// Group by time buckets (keep_every seconds)
				$kept_buckets = array();
				$to_delete = array();

				foreach ($candidates as $timestamp => $backup) {
					// Skip always_keep backups
					if (!empty($backup['always_keep'])) continue;

					$bucket = intval(floor($timestamp / $keep_every));

					if (isset($kept_buckets[$bucket])) {
						// Already have a backup in this bucket, mark for deletion
						$to_delete[$timestamp] = $backup;
					} else {
						// Keep this one (first in bucket)
						$kept_buckets[$bucket] = $timestamp;
					}
				}

				// Delete marked backups
				foreach ($to_delete as $timestamp => $backup) {
					$this->log("Retention rule pruning: removing backup at " . gmdate('Y-m-d H:i:s', $timestamp));
					SiteGuard_Backup_History::delete_backup_set($timestamp, true);
					// Also remove from history so subsequent rules see updated state
					unset($history[$timestamp]);
					unset($type_backups[$timestamp]);
				}
			}
		}
	}

	/**
	 * Delete local backup copies after successful remote upload.
	 */
	private function delete_local_copies() {
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$all_files = array_merge($backup_array, $db_files);
		$backup_dir = $this->get_backup_dir();

		foreach ($all_files as $file) {
			// Prevent path traversal - only allow basenames
			if (basename($file) !== $file || strpos($file, '..') !== false) {
				$this->log("Rejected unsafe filename in delete: {$file}", 'error');
				continue;
			}
			$path = $backup_dir . $file;
			if (file_exists($path)) {
				@unlink($path);
				$this->log("Deleted local copy: {$file}");
			}
		}
	}

	/**
	 * Generate manifest file early (before upload phase) so it gets uploaded to remote storage.
	 */
	private function generate_manifest_early() {
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$all_files = array_merge($backup_array, $db_files);

		$total_size = 0;
		$backup_dir = $this->get_backup_dir();
		clearstatcache();
		foreach ($all_files as $file) {
			$path = $backup_dir . $file;
			if (file_exists($path)) {
				$total_size += filesize($path);
			}
		}

		$manifest_filename = $this->write_manifest($all_files, array(), $total_size);
		if ($manifest_filename) {
			$backup_array[] = $manifest_filename;
			$this->jobdata_set('backup_files_array', $backup_array);
			$this->jobdata_set('manifest_filename', $manifest_filename);
			$this->log("Manifest generated for upload: {$manifest_filename}");
		}
	}

	// ==========================================
	// EXTRA DIRECTORIES (M9)
	// ==========================================

	/**
	 * M9: Get backup entities merged with user-defined extra directories.
	 *
	 * Extra directories are stored in sgbackup_extra_dirs option as an array
	 * of absolute paths. Each is added as an entity keyed "extradir_{index}".
	 *
	 * @return array Merged entities array suitable for backup_files().
	 */
	public function get_backup_entities_with_extras() {
		$entities = SiteGuard_Backup_Filesystem::get_backup_entities();
		$opts = SiteGuard_Backup_Options::instance();
		$extra_dirs = $opts->get_option('sgbackup_extra_dirs', array());

		if (!is_array($extra_dirs) || empty($extra_dirs)) {
			return $entities;
		}

		foreach ($extra_dirs as $index => $dir_path) {
			if (empty($dir_path) || !is_string($dir_path)) continue;

			$real = realpath($dir_path);
			if (false === $real || !is_dir($real) || !is_readable($real)) {
				$this->log("Extra dir skipped (not readable): {$dir_path}", 'warning');
				continue;
			}

			$key = 'extradir_' . intval($index);
			$entities[$key] = array(
				'path'  => trailingslashit($real),
				'label' => basename($real) . ' (extra)',
			);
		}

		return $entities;
	}

	// ==========================================
	// INCREMENTAL CONSOLIDATION (M10)
	// ==========================================

	/**
	 * Consolidate incremental backups when the chain exceeds the threshold.
	 * Called after backup_finish for incremental jobs.
	 *
	 * @return bool Whether a full backup was triggered.
	 */
	public function consolidate_incrementals() {
		$opts = SiteGuard_Backup_Options::instance();
		$max = intval($opts->get_option('sgbackup_max_incrementals_before_full', 10));
		if ($max < 1) return false;

		$history = SiteGuard_Backup_History::get_history();

		// Find the most recent full backup (non-incremental parent)
		foreach ($history as $timestamp => $backup) {
			if (empty($backup['incremental_parent']) && !empty($backup['nonce'])) {
				// Count incremental sets
				$inc_count = 0;
				if (!empty($backup['incremental_sets']) && is_array($backup['incremental_sets'])) {
					$inc_count = count($backup['incremental_sets']);
				}

				if ($inc_count >= $max) {
					$this->log("Incremental chain has {$inc_count} sets (threshold: {$max}) - scheduling full backup");
					return true;
				}

				// Only check the most recent full backup
				break;
			}
		}

		return false;
	}

	/**
	 * Check if an incremental backup should be replaced by a full backup
	 * based on the max_incrementals_before_full threshold.
	 *
	 * @return bool True if a full backup should run instead.
	 */
	public function should_force_full_backup() {
		$opts = SiteGuard_Backup_Options::instance();
		$max = intval($opts->get_option('sgbackup_max_incrementals_before_full', 10));
		if ($max < 1) return false;

		$history = SiteGuard_Backup_History::get_history();

		foreach ($history as $timestamp => $backup) {
			if (empty($backup['incremental_parent']) && !empty($backup['nonce'])) {
				$inc_count = 0;
				if (!empty($backup['incremental_sets']) && is_array($backup['incremental_sets'])) {
					$inc_count = count($backup['incremental_sets']);
				}
				return ($inc_count >= $max);
			}
		}

		return false;
	}

	// ==========================================
	// UTILITY
	// ==========================================

	/**
	 * Get a sanitized site name for use in filenames.
	 */
	public function get_site_name() {
		$site_name = get_bloginfo('name');
		$site_name = preg_replace('/[^a-zA-Z0-9]/', '', $site_name);
		if (empty($site_name)) $site_name = 'wordpress';
		return substr(strtolower($site_name), 0, 40);
	}

	/**
	 * Generate backup file basename.
	 *
	 * @return string e.g., "backup_2026-03-01-0300_sitename_abcdef123456"
	 */
	public function get_backup_file_basename() {
		$date = gmdate('Y-m-d-Hi', $this->backup_time);
		$site = $this->get_site_name();
		return "backup_{$date}_{$site}_{$this->file_nonce}";
	}

	/**
	 * Write a manifest file alongside the backup files.
	 */
	private function write_manifest($all_files, $backup_data, $total_size) {
		global $wpdb;
		$backup_dir = $this->get_backup_dir();
		$manifest_file = $backup_dir . $this->get_backup_file_basename() . '-manifest.json';

		$manifest = array(
			'plugin'         => 'siteguardbackup',
			'version'        => SGBACKUP_VERSION,
			'created'        => gmdate('c', $this->backup_time),
			'timestamp'      => $this->backup_time,
			'nonce'          => $this->nonce,
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'content_url'    => content_url(),
			'uploads_url'    => wp_upload_dir()['baseurl'],
			'abspath'        => ABSPATH,
			'table_prefix'   => $wpdb->prefix,
			'wp_version'     => get_bloginfo('version'),
			'php_version'    => phpversion(),
			'multisite'      => is_multisite(),
			'job_type'       => $this->jobdata_get('job_type', 'backup'),
			'total_size'     => $total_size,
			'files'          => array(),
		);

		foreach ($all_files as $file) {
			$path = $backup_dir . $file;
			clearstatcache(true, $path);
			$manifest['files'][] = array(
				'name' => $file,
				'size' => file_exists($path) ? filesize($path) : 0,
				'md5'  => file_exists($path) ? md5_file($path) : '',
				'sha256' => file_exists($path) ? hash_file('sha256', $path) : '',
			);
		}

		$written = file_put_contents($manifest_file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if ($written !== false) {
			@chmod($manifest_file, 0600);
			$this->log("Manifest written: " . basename($manifest_file));
			return basename($manifest_file);
		}
		$this->log("Failed to write manifest file", 'warning');
		return false;
	}

	/**
	 * Check if a backup is currently running.
	 */
	public function is_backup_running() {
		$active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
		return !empty($active_jobs);
	}

	/**
	 * Gap 12: Get SSL options array for wp_remote_request.
	 *
	 * @return array SSL-related args for wp_remote_request / wp_remote_get / wp_remote_post.
	 */
	public function get_ssl_options() {
		$opts = SiteGuard_Backup_Options::instance();
		$ssl_args = array();

		if ($opts->get_option('sgbackup_ssl_disableverify', false)) {
			$ssl_args['sslverify'] = false;
		}

		if ($opts->get_option('sgbackup_ssl_useservercerts', false)) {
			$ssl_args['sslcertificates'] = '';
		}

		return $ssl_args;
	}

	/**
	 * Gap 17: Check cPanel disk quota or general disk free space.
	 *
	 * Attempts to determine available disk space via:
	 * 1. cPanel quota command (if running on cPanel)
	 * 2. PHP's disk_free_space() on the backup directory
	 *
	 * @return int|false Available bytes, or false if unable to determine.
	 */
	public function check_cpanel_quota() {
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Method 1: Check if we're on cPanel and try quota command
		if (is_dir('/usr/local/cpanel')) {
			$quota_output = array();
			$return_code = -1;
			@exec('quota -g 2>/dev/null', $quota_output, $return_code);

			if (0 === $return_code && !empty($quota_output)) {
				// Parse quota output: typical format is
				// Disk quotas for group ... :
				//    Filesystem  blocks   quota   limit   grace   files   quota   limit   grace
				//    /dev/sdaN   123456   5000000  5000000          1234   0       0
				foreach ($quota_output as $line) {
					$line = trim($line);
					if (empty($line) || strpos($line, 'Disk') === 0 || strpos($line, 'Filesystem') === 0) {
						continue;
					}
					// Split on whitespace
					$parts = preg_split('/\s+/', $line);
					if (count($parts) >= 4) {
						$blocks_used = intval($parts[1]);
						$blocks_limit = intval($parts[3]); // hard limit
						if ($blocks_limit > 0) {
							// Blocks are in KB
							$available_kb = $blocks_limit - $blocks_used;
							$available_bytes = max(0, $available_kb * 1024);
							return $available_bytes;
						}
					}
				}
			}

			// Alternative: try repquota via /proc
			if (is_readable('/proc/repquota')) {
				$repquota = @file_get_contents('/proc/repquota');
				if (false !== $repquota) {
					$current_user = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : null;
					$username = $current_user ? $current_user['name'] : '';
					if (!empty($username) && preg_match('/^' . preg_quote($username, '/') . '\s+--\s+(\d+)\s+(\d+)\s+(\d+)/m', $repquota, $matches)) {
						$blocks_used = intval($matches[1]);
						$blocks_limit = intval($matches[3]); // hard limit
						if ($blocks_limit > 0) {
							$available_bytes = max(0, ($blocks_limit - $blocks_used) * 1024);
							return $available_bytes;
						}
					}
				}
			}
		}

		// Method 2: Fallback to disk_free_space()
		if (function_exists('disk_free_space') && !empty($backup_dir)) {
			$free = @disk_free_space($backup_dir);
			if (false !== $free) {
				return intval($free);
			}
		}

		return false;
	}
}
