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
	/** True while a resumption runs from the sgbackup_backup_resume cron hook (see sgbackup_cron_backup_resume). */
	public $via_cron = false;

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
		'b2'          => 'Backblaze B2',
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
			// Cap the errors kept in jobdata at 100 entries (first 20 + last 80)
			// so a failure loop cannot bloat the jobdata blob unboundedly.
			if (count($errors) > 100) {
				$errors = array_merge(array_slice($errors, 0, 20), array_slice($errors, -80));
				if (!$this->jobdata_get('errors_truncated', false)) {
					$this->jobdata_set('errors_truncated', true);
					if ($this->logfile_handle) {
						@fwrite($this->logfile_handle, "[" . gmdate('Y-m-d H:i:s') . "] Errors list in jobdata truncated to 100 entries (first 20 + last 80 kept); full detail remains in this log file\n");
					}
				}
			}
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
		if (!file_exists($file)) {
			return '';
		}
		// Only read the last 512KB - huge logs would otherwise be loaded whole
		$max_bytes = 524288;
		clearstatcache(true, $file);
		$size = filesize($file);
		if (false === $size || $size <= $max_bytes) {
			$contents = file_get_contents($file);
			return (false === $contents) ? '' : $contents;
		}
		$fh = @fopen($file, 'rb');
		if (!$fh) return '';
		fseek($fh, -$max_bytes, SEEK_END);
		$contents = stream_get_contents($fh);
		fclose($fh);
		return "[log truncated]\n" . ((false === $contents) ? '' : $contents);
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
		$opts->delete_option('sgbackup_dbtables_' . $nonce);
	}

	/**
	 * Get the completed-tables list for the current job.
	 *
	 * Stored in its own option (not the main jobdata blob) because it can grow
	 * to thousands of entries and the jobdata blob is rewritten constantly.
	 */
	public function dbtables_get() {
		if (!$this->nonce) return array();
		$opts = SiteGuard_Backup_Options::instance();
		$data = $opts->get_option('sgbackup_dbtables_' . $this->nonce, array());
		return is_array($data) ? $data : array();
	}

	/**
	 * Persist the completed-tables list for the current job (autoload off).
	 */
	public function dbtables_set($tables) {
		if (!$this->nonce) return;
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option('sgbackup_dbtables_' . $this->nonce, is_array($tables) ? $tables : array(), false);
	}

	/**
	 * Delete the completed-tables store for a nonce.
	 */
	public function dbtables_delete($nonce = null) {
		if (null === $nonce) $nonce = $this->nonce;
		if (!$nonce) return;
		$opts = SiteGuard_Backup_Options::instance();
		$opts->delete_option('sgbackup_dbtables_' . $nonce);
	}

	// ==========================================
	// BACKUP DIRECTORY
	// ==========================================

	/**
	 * Get the backup directory, creating it if needed.
	 */
	public function get_backup_dir() {
		// Cache per blog: the multisite cron dispatcher runs several subsites' jobs
		// through this singleton under switch_to_blog(), and a single cached path
		// sent every later subsite's zips/logs into the first subsite's directory.
		$blog_id = is_multisite() ? (int) get_current_blog_id() : 0;
		if (!empty($this->backup_dir) && $this->backup_dir_blog === $blog_id) return $this->backup_dir;
		$opts = SiteGuard_Backup_Options::instance();
		$this->backup_dir = $opts->get_backup_dir();
		$this->backup_dir_blog = $blog_id;
		return $this->backup_dir;
	}

	/** @var int|null Blog id the cached backup_dir belongs to */
	private $backup_dir_blog = null;

	// ==========================================
	// MEMORY (low-RAM hosts)
	// ==========================================

	/**
	 * PHP memory_limit in bytes. 0 means unlimited/unknown.
	 */
	public static function memory_limit_bytes() {
		$raw = trim((string) ini_get('memory_limit'));
		if ('' === $raw || '-1' === $raw) return 0;
		$unit = strtolower(substr($raw, -1));
		$val = (float) $raw;
		switch ($unit) {
			case 'g': $val *= 1024; // fall through
			case 'm': $val *= 1024; // fall through
			case 'k': $val *= 1024;
		}
		return (int) $val;
	}

	/**
	 * memory_limit as it was BEFORE raise_memory_limit() touched it (bytes, 0 = unlimited).
	 * Recorded on first call so the low-memory decision reflects the host's real
	 * php.ini value, not the limit this plugin raised it to.
	 */
	public static function initial_memory_limit() {
		static $initial = null;
		if (null === $initial) $initial = self::memory_limit_bytes();
		return $initial;
	}

	/**
	 * Total memory available to this host/container in bytes, or 0 if unknown.
	 *
	 * Takes the smallest of: cgroup v2 memory.max, cgroup v1 limit_in_bytes,
	 * and /proc/meminfo MemTotal. Containers routinely report the host's RAM
	 * in meminfo while the cgroup caps them far lower, hence the minimum.
	 */
	public static function system_memory_bytes() {
		static $cached = null;
		if (null !== $cached) return $cached;

		$candidates = array();
		foreach (array('/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory/memory.limit_in_bytes') as $cg) {
			if (!@is_readable($cg)) continue;
			$v = trim((string) @file_get_contents($cg));
			// "max" (v2) or a huge sentinel (v1) means "no cgroup limit"
			if ('' === $v || 'max' === $v || !ctype_digit($v) || (float) $v > 1.0e15) continue;
			$candidates[] = (int) $v;
		}
		if (@is_readable('/proc/meminfo')) {
			$fh = @fopen('/proc/meminfo', 'r');
			if ($fh) {
				while (($line = fgets($fh, 256)) !== false) {
					if (preg_match('/^MemTotal:\s+(\d+)\s*kB/i', $line, $m)) {
						$candidates[] = (int) $m[1] * 1024;
						break;
					}
				}
				fclose($fh);
			}
		}
		$cached = empty($candidates) ? 0 : min($candidates);
		return $cached;
	}

	/**
	 * Bytes left before memory_limit is hit. Returns a generous figure when the
	 * limit is unlimited/unknown so callers can treat the result as a budget.
	 */
	public static function memory_headroom() {
		$limit = self::memory_limit_bytes();
		if ($limit <= 0) return 1073741824; // 1 GB: "plenty"
		return max(0, $limit - memory_get_usage(true));
	}

	/**
	 * Whether the low-memory profile is in force.
	 *
	 * Setting sgbackup_low_memory_mode: 'on' | 'off' | 'auto' (default).
	 * Auto triggers when PHP's memory_limit is under 128 MB or the host/container
	 * has under 1 GB of RAM - the 512 MB VPS / small container case where the
	 * default batch sizes get the PHP worker OOM-killed before the first zip closes.
	 */
	public function is_low_memory() {
		static $cached = null;
		if (null !== $cached) return $cached;

		$opts = SiteGuard_Backup_Options::instance();
		$mode = (string) $opts->get_option('sgbackup_low_memory_mode', 'auto');
		if ('on' === $mode) {
			$cached = true;
		} elseif ('off' === $mode) {
			$cached = false;
		} else {
			$limit = self::initial_memory_limit();
			$sys = self::system_memory_bytes();
			$cached = ($limit > 0 && $limit < 134217728) || ($sys > 0 && $sys < 1073741824);
		}
		$cached = (bool) apply_filters('sgbackup_low_memory', $cached);
		return $cached;
	}

	/**
	 * Human-readable memory summary for logs and sysinfo.
	 */
	public static function memory_summary() {
		$limit = self::memory_limit_bytes();
		$sys = self::system_memory_bytes();
		return 'memory_limit=' . ($limit > 0 ? SiteGuard_Backup_Filesystem::format_bytes($limit) : 'unlimited')
			. ', system=' . ($sys > 0 ? SiteGuard_Backup_Filesystem::format_bytes($sys) : 'unknown')
			. ', in use=' . SiteGuard_Backup_Filesystem::format_bytes(memory_get_usage(true));
	}

	/**
	 * Raise PHP's memory_limit for a backup/restore run.
	 *
	 * wp-cron.php and WP-CLI requests run at php.ini's memory_limit (often 64-128 MB
	 * on small hosts); only wp-admin raises it. Lift it to WP_MAX_MEMORY_LIMIT like
	 * core does for admin screens, but never above half the host's RAM so a spike
	 * fails as a clean PHP fatal (resumable) instead of the kernel OOM-killer taking
	 * out the worker (and sometimes MySQL) on a 512 MB box. Only ever raises.
	 *
	 * @return bool True if the limit was changed.
	 */
	public function raise_memory_limit() {
		$current = self::memory_limit_bytes();
		self::initial_memory_limit(); // remember the pre-raise value for is_low_memory()
		if ($current <= 0) return false; // unlimited already

		$target = defined('WP_MAX_MEMORY_LIMIT') ? wp_convert_hr_to_bytes(WP_MAX_MEMORY_LIMIT) : 268435456;
		if ($target <= 0) $target = 268435456; // WP_MAX_MEMORY_LIMIT = -1 means "unlimited"; use core's default cap
		$sys = self::system_memory_bytes();
		if ($sys > 0) {
			$target = min($target, max(134217728, (int) ($sys / 2)));
		}
		$target = (int) apply_filters('sgbackup_memory_limit', $target, $current, $sys);

		if ($target <= $current) return false;
		if (false === @ini_set('memory_limit', (string) $target)) return false;
		$this->log("Raised memory_limit from " . SiteGuard_Backup_Filesystem::format_bytes($current)
			. " to " . SiteGuard_Backup_Filesystem::format_bytes($target)
			. ($sys > 0 ? " (host RAM " . SiteGuard_Backup_Filesystem::format_bytes($sys) . ")" : ''));
		return true;
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
			if (empty($backup['nonce']) || !empty($backup['incremental_parent'])) continue;
			if (!empty($backup['imported'])) continue;
			// The parent must contain file archives: a DB-only set has no base to
			// layer changed files onto, and its timestamp would hide every change
			// made between the last real file backup and that DB run.
			$has_files = false;
			foreach ($backup as $k => $v) {
				if (!empty($v) && (in_array($k, array('plugins', 'themes', 'uploads', 'others', 'wpcore'), true) || strpos($k, 'extradir_') === 0)) {
					$has_files = true;
					break;
				}
			}
			if (!$has_files) continue;
			$parent_timestamp = $timestamp;
			break;
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

		// TOCTOU guard: another request may have created a job between the
		// check above and acquiring the semaphore. Re-check now that we hold it.
		$active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
		if (!empty($active_jobs)) {
			$existing_nonce = key($active_jobs);
			$existing = $active_jobs[$existing_nonce];
			$msg = "Cannot start backup: another job is already running (nonce: {$existing_nonce}, resumption: {$existing['resumption']})";
			error_log('SiteGuard Backup: ' . $msg);
			$this->boot_error = $msg;
			return; // finally releases the semaphore
		}

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

		// Per-subsite backup: always flag a backup as subsite-scoped when running
		// from a subsite admin context (blog_id > 1), regardless of whether the
		// plugin is network-activated or per-site activated.
		//
		// Reasoning: in a subsite context, $wpdb->prefix is already wp_{blog_id}_
		// and wp_upload_dir() is already per-subsite. The generic backup_database()
		// path therefore dumps subsite-scoped tables no matter what - but without
		// the flag set it ALSO skips the filtered wp_users/wp_usermeta inclusion
		// that backup_subsite_database() does, producing an incomplete dump.
		// So in a subsite context the backup is effectively per-subsite either
		// way; we just need to mark it correctly.
		//
		// Full network backups are expected to run from blog_id=1 / network admin.
		$subsite_backup = !empty($options['subsite_backup'])
			|| (is_multisite() && get_current_blog_id() > 1);

		if (is_multisite() && get_current_blog_id() > 1 && $subsite_backup) {
			$blog_id = get_current_blog_id();
			$blog_details = get_blog_details($blog_id);
			$initial_jobdata['is_subsite_backup'] = true;
			$initial_jobdata['subsite_blog_id'] = $blog_id;
			$initial_jobdata['subsite_domain'] = $blog_details ? $blog_details->domain : '';
			$initial_jobdata['subsite_path'] = $blog_details ? $blog_details->path : '/';

			// Capture the subsite's full siteurl/home while we're guaranteed to be
			// in the subsite's context. Later stages (remote upload, history write)
			// can run on main-site cron context where site_url() would return the
			// wrong URL. Use get_blog_option() for an unambiguous per-blog read.
			$sub_siteurl = get_blog_option($blog_id, 'siteurl');
			$sub_home = get_blog_option($blog_id, 'home');
			$initial_jobdata['subsite_url'] = $sub_siteurl ? $sub_siteurl : '';
			$initial_jobdata['subsite_home_url'] = $sub_home ? $sub_home : '';
			$initial_jobdata['subsite_scheme'] = $sub_siteurl ? parse_url($sub_siteurl, PHP_URL_SCHEME) : '';

			// Capture plugin/theme state so the restorer can rebuild the active
			// set against whatever the destination network has installed. Without
			// this, Elementor (etc.) ends up inactive on the restored subsite and
			// the page renders as a pile of div markup with no CSS/JS.
			$sub_active_plugins = get_blog_option($blog_id, 'active_plugins', array());
			$initial_jobdata['subsite_active_plugins'] = is_array($sub_active_plugins) ? array_values(array_filter(array_map('strval', $sub_active_plugins))) : array();
			$initial_jobdata['subsite_stylesheet']     = (string) get_blog_option($blog_id, 'stylesheet', '');
			$initial_jobdata['subsite_template']       = (string) get_blog_option($blog_id, 'template', '');

			// Network-activated plugins: these live in wp_sitemeta and are NOT
			// in wp_N_options.active_plugins, so the per-subsite SQL dump doesn't
			// include them. Carry them as metadata so the restorer knows which
			// plugins the source relied on at the network level.
			if (function_exists('get_site_option')) {
				$netwide = get_site_option('active_sitewide_plugins', array());
				$initial_jobdata['subsite_active_sitewide_plugins'] = is_array($netwide) ? array_keys($netwide) : array();
			} else {
				$initial_jobdata['subsite_active_sitewide_plugins'] = array();
			}
		}

		// Merge any extra jobdata (e.g., from migrate_send pre-setting phase flags)
		if (!empty($options['initial_jobdata_extra']) && is_array($options['initial_jobdata_extra'])) {
			$initial_jobdata = array_merge($initial_jobdata, $options['initial_jobdata_extra']);
		}

		// Allow filtering
		$initial_jobdata = apply_filters('sgbackup_initial_jobdata', $initial_jobdata, $options);

		$this->jobdata = $initial_jobdata;
		$this->save_jobdata();

		// Mark this nonce as the active job so status polling can find it
		// even during the direct-chain window where cron events are being
		// cleared and rescheduled (race condition with activejobs_list).
		update_option('sgbackup_active_job_nonce', $this->nonce, false);

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
			// Self-heal an interrupted finalize. backup_finish() sets jobstatus to a
			// terminal value BEFORE it writes history and clears the active-job marker.
			// If the process dies in that window (timeout / fatal mid-finalize on a large
			// set), the backup is registered nowhere yet the active marker stays set, so
			// the UI shows it "in progress / uploading" forever and every later resumption
			// would return here and never recover. If a successful job is not yet flagged
			// finalized, complete the cleanup idempotently (save_to_history is keyed by
			// timestamp; clearing events/markers is safe to repeat).
			if (!$this->jobdata_get('finalized', false)
				&& in_array($jobstatus, array('complete', 'finished', 'warning'), true)) {
				$this->backup_time = $this->jobdata_get('backup_time');
				$this->logfile_open($this->nonce);
				$this->log("Self-healing interrupted finalize (status={$jobstatus})");
				$this->save_to_history();
				SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($bnonce);
				$active_nonce = get_option('sgbackup_active_job_nonce');
				if ($active_nonce === $bnonce) {
					delete_option('sgbackup_active_job_nonce');
				}
				$this->jobdata_set('finalized', 1);
				$this->log("Finalize recovered");
				$this->logfile_close();
			}
			return;
		}

		$this->backup_time = $this->jobdata_get('backup_time');
		$this->file_nonce = $this->jobdata_get('file_nonce', $bnonce);
		$this->job_time_ms = microtime(true);

		// Open log
		$this->logfile_open($this->nonce);
		$this->log("=== Resumption {$resumption_no} ===");
		$this->raise_memory_limit();
		$this->log("Memory: " . self::memory_summary() . ($this->is_low_memory() ? ' - LOW-MEMORY MODE' : ''));

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
				// Mark the batch as deliberately lowered so create_zips() doesn't
				// override it with the generous-execution-time raise.
				$this->jobdata_set('maxzipbatch_forced_low', true);
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
		if (defined('WP_CLI') && WP_CLI && $this->via_cron && !in_array($jobstatus, $terminal, true)) {
			// Cron run through WP-CLI (`wp cron event run`, e.g. a wp-cron container
			// with DISABLE_WP_CRON): there is no direct chain and no CLI loop, so
			// the next resumption must be queued here or the job stalls forever.
			$active = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
			if (empty($active[$bnonce])) {
				SiteGuard_Backup_Job_Scheduler::schedule_resumption($resumption_no + 1, $bnonce, 30);
			}
		}
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

					// Encrypt in place over the current jobdata arrays. Each file's
					// name swap is persisted BEFORE the plaintext original is
					// unlinked, so a crash between the two can never leave a file
					// that is neither recorded nor recoverable. Nothing is built in
					// a local array that could be lost on interruption.
					$backup_dir_enc = $this->get_backup_dir();
					foreach (array('backup_files_array', 'db_backup_files') as $enc_array_key) {
						$enc_file_list = $this->jobdata_get($enc_array_key, array());
						foreach ($enc_file_list as $enc_idx => $file) {
							if ('.crypt' === substr($file, -6)) continue; // already encrypted (resume)

							$filepath = $backup_dir_enc . $file;
							$crypt_path = $filepath . '.crypt';

							if (!file_exists($filepath)) {
								// Self-heal: a previous resumption encrypted this file but
								// crashed before persisting the swap - the .crypt on disk
								// is the surviving copy, so record it and continue.
								if (file_exists($crypt_path)) {
									$enc_file_list[$enc_idx] = basename($crypt_path);
									$this->jobdata_set($enc_array_key, $enc_file_list);
									$this->log("Encryption resume: {$file} was already encrypted - recorded " . basename($crypt_path));
								}
								continue;
							}

							$encrypted_path = SiteGuard_Backup_Encryption::encrypt($filepath, $enc_key);
							if ($encrypted_path) {
								$encrypted_name = basename($encrypted_path);
								// Persist the swap BEFORE unlinking the original
								$enc_file_list[$enc_idx] = $encrypted_name;
								$this->jobdata_set($enc_array_key, $enc_file_list);
								@unlink($filepath);
								$this->log("Encrypted: {$file} -> {$encrypted_name}");
							} else {
								// Keep the plaintext name in the array (file stays usable)
								$this->log("Encryption failed for: {$file}", 'error');
							}
							SiteGuard_Backup_Job_Scheduler::something_useful_happened();
						}
					}

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
			$services = (array) $this->jobdata_get('service', array('none'));
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

		// Validate that the backup actually produced files. The manifest is
		// appended unconditionally in the manifest phase, so exclude it -
		// otherwise this check can never fire.
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$manifest_filename = $this->jobdata_get('manifest_filename', '');
		if (!empty($manifest_filename)) {
			$backup_array = array_values(array_diff($backup_array, array($manifest_filename)));
		}
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

		// Check for failed entities - mark backup as having errors. Files that had
		// to be skipped (unzippable + too large for the PclZip fallback) count too:
		// the archive is missing data and must not be reported as fully complete.
		$failed_entities = $this->jobdata_get('failed_entities', array());
		$skipped_files = $this->jobdata_get('zip_skipped_files', array());
		$has_entity_failures = !empty($failed_entities) || !empty($skipped_files);
		if (!empty($skipped_files)) {
			$this->log(count($skipped_files) . " file(s) could not be added to any archive - see 'file skipped' errors above; backup will finish with warnings", 'warning');
		}

		// All phases complete
		$this->backup_finish(true, true, false, $has_entity_failures);
	}

	/**
	 * Upload backup files to remote storage.
	 */
	private function upload_to_remote() {
		$services = (array) $this->jobdata_get('service', array('none'));
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$all_files = array_values(array_unique(array_merge($backup_array, $db_files)));

		if (empty($all_files)) return true;

		$backup_dir = $this->get_backup_dir();
		$any_service_succeeded = false;

		// Per-service upload checkpoints: service => list of filenames already
		// uploaded, so a resumption doesn't re-upload completed files.
		$uploaded_files = $this->jobdata_get('uploaded_files', array());
		if (!is_array($uploaded_files)) $uploaded_files = array();

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

				// Skip files this service already received on a previous resumption
				if (isset($uploaded_files[$service]) && in_array($file, $uploaded_files[$service], true)) {
					continue;
				}

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
						// Checkpoint each successful upload immediately
						if (!isset($uploaded_files[$service])) $uploaded_files[$service] = array();
						$uploaded_files[$service][] = $file;
						$this->jobdata_set('uploaded_files', $uploaded_files);
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
		$subsite_path = $this->jobdata_get('subsite_path', '/');
		$subsite_url = $this->jobdata_get('subsite_url', '');
		$subsite_home_url = $this->jobdata_get('subsite_home_url', '');
		$subsite_scheme = $this->jobdata_get('subsite_scheme', '');
		$subsite_active_plugins = $this->jobdata_get('subsite_active_plugins', array());
		$subsite_active_sitewide_plugins = $this->jobdata_get('subsite_active_sitewide_plugins', array());
		$subsite_stylesheet = $this->jobdata_get('subsite_stylesheet', '');
		$subsite_template = $this->jobdata_get('subsite_template', '');

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

		// Prefer the captured subsite URL over site_url(): the metadata call can
		// run on a main-site cron context where site_url() returns the network
		// home, not the subsite we actually backed up.
		$source_url = $is_subsite_backup && !empty($subsite_url) ? $subsite_url : site_url();

		$payload = array(
			'timestamp'  => intval($timestamp),
			'nonce'      => $nonce,
			'source_url' => $source_url,
		);
		if (!empty($label)) $payload['label'] = $label;
		if ($always_keep) $payload['always_keep'] = true;
		if ($is_subsite_backup) {
			$payload['is_subsite_backup'] = true;
			$payload['subsite_blog_id'] = intval($subsite_blog_id);
			$payload['subsite_domain'] = $subsite_domain;
			$payload['subsite_path'] = $subsite_path;
			$payload['subsite_url'] = $subsite_url;
			$payload['subsite_home_url'] = $subsite_home_url;
			$payload['subsite_scheme'] = $subsite_scheme;
			$payload['subsite_active_plugins']          = is_array($subsite_active_plugins) ? array_values($subsite_active_plugins) : array();
			$payload['subsite_active_sitewide_plugins'] = is_array($subsite_active_sitewide_plugins) ? array_values($subsite_active_sitewide_plugins) : array();
			$payload['subsite_stylesheet']              = (string) $subsite_stylesheet;
			$payload['subsite_template']                = (string) $subsite_template;
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
			// Honest status: if remote storage was configured and every service
			// failed, this is not a plain 'complete' - local copies are kept
			// (see the remote_upload_succeeded gate on delete_local below).
			$services = $this->jobdata_get('service', array('none'));
			$has_remote_services = false;
			foreach ((array) $services as $svc) {
				if ('none' !== $svc && '' !== $svc && null !== $svc) {
					$has_remote_services = true;
					break;
				}
			}
			$remote_all_failed = $has_remote_services
				&& $this->jobdata_get('upload_phase_complete', false)
				&& !$this->jobdata_get('remote_upload_succeeded', false);

			if ($has_entity_errors) {
				$failed = $this->jobdata_get('failed_entities', array());
				$this->jobdata_set('jobstatus', 'warning');
				$this->log("Backup finished with ERRORS - missing entities: " . implode(', ', $failed), 'error');
			} elseif ($remote_all_failed) {
				$this->jobdata_set('jobstatus', 'warning');
				$this->log("Backup files were created, but ALL configured remote uploads failed (" .
					implode(', ', array_filter((array) $services, function($s) { return 'none' !== $s; })) .
					"). Local copies have been kept.", 'error');
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

		// Clear active job marker (set in boot_backup) so status polling
		// correctly reports no active jobs after the backup finishes.
		$active_nonce = get_option('sgbackup_active_job_nonce');
		if ($active_nonce === $this->nonce) {
			delete_option('sgbackup_active_job_nonce');
		}

		// Mark fully finalized: history written and active marker cleared. A later
		// resumption uses this to know finalization completed and must NOT self-heal.
		$this->jobdata_set('finalized', 1);

		// Clean up abort flag
		$abort_file = $this->get_backup_dir() . 'deleteflag-' . $this->nonce . '.txt';
		if (file_exists($abort_file)) {
			@unlink($abort_file);
		}

		// Send email report
		if ($allow_email && !$force_abort) {
			$this->send_email_report();
		}

		// Prune old backups (not after merely re-sending an existing set)
		if (!$force_abort && !$this->is_send_existing_job()) {
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

		// The completed-tables store is only needed while the DB phase can resume
		$this->dbtables_delete();

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
	 * A job that only uploads an EXISTING backup set somewhere (migrate_send,
	 * "upload to remote") creates no new archives.
	 */
	private function is_send_existing_job() {
		return (bool) $this->jobdata_get('original_backup_timestamp', false)
			&& 'yes' !== $this->jobdata_get('backup_files')
			&& 'yes' !== $this->jobdata_get('backup_database');
	}

	/**
	 * Save the completed backup to history.
	 */
	private function save_to_history() {
		// Sending an existing set must not register a second copy of it: the
		// phantom entry had no files of its own but counted toward retention, so
		// a few sends pushed real backups out.
		if ($this->is_send_existing_job()) {
			$this->log('Sent existing backup set ' . (int) $this->jobdata_get('original_backup_timestamp') . '; history unchanged');
			return;
		}
		$backup_array = $this->jobdata_get('backup_files_array', array());
		$db_files = $this->jobdata_get('db_backup_files', array());
		$job_type = $this->jobdata_get('job_type', 'backup');

		$backup_data = array(
			'nonce'   => $this->nonce,
			'service' => $this->jobdata_get('service', array('none')),
		);

		// Add file entities. Anchored match: "-{entity}" substring matching would
		// mis-attribute files across entities whose names share a prefix.
		$entities = $this->jobdata_get('job_file_entities', array());
		foreach ($entities as $entity => $info) {
			$entity_pattern = '/-' . preg_quote($entity, '/') . '(-\d+)?\.zip(\.crypt)?$/';
			$entity_files = array();
			foreach ($backup_array as $file) {
				if (preg_match($entity_pattern, $file)) {
					$entity_files[] = $file;
				}
			}
			if (!empty($entity_files)) {
				// Defense in depth: dedupe here too, so historical jobdata with
				// duplicates (from before the create_zips fix) still lands as a
				// clean history entry.
				$backup_data[$entity] = array_values(array_unique($entity_files));
				// Extra directories are keyed by their position in a list that can
				// change later; record where each one actually came from.
				if (0 === strpos($entity, 'extradir_') && is_array($info) && !empty($info['path'])) {
					$backup_data['extra_dir_sources'][$entity] = untrailingslashit($info['path']);
				}
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
			$entity_pattern = '/-' . preg_quote($entity, '/') . '(-\d+)?\.zip(\.crypt)?$/';
			$entity_size = 0;
			foreach ($backup_array as $f) {
				if (preg_match($entity_pattern, $f)) {
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
			$backup_data['subsite_url'] = $this->jobdata_get('subsite_url', '');
			$backup_data['subsite_home_url'] = $this->jobdata_get('subsite_home_url', '');
			$backup_data['subsite_scheme'] = $this->jobdata_get('subsite_scheme', '');
			$backup_data['subsite_active_plugins']           = $this->jobdata_get('subsite_active_plugins', array());
			$backup_data['subsite_active_sitewide_plugins']  = $this->jobdata_get('subsite_active_sitewide_plugins', array());
			$backup_data['subsite_stylesheet']               = $this->jobdata_get('subsite_stylesheet', '');
			$backup_data['subsite_template']                 = $this->jobdata_get('subsite_template', '');
		}

		// Handle incremental merging
		if ('incremental' === $job_type) {
			$parent_timestamp = $this->jobdata_get('incremental_parent');
			if ($parent_timestamp) {
				$merged_ts = SiteGuard_Backup_History::merge_incremental($parent_timestamp, $backup_data);
				if (false !== $merged_ts) {
					$this->log("Incremental merged into parent backup (timestamp: {$parent_timestamp})");
					return;
				}
				// Parent was pruned while this job ran: register the set standalone
				// rather than leaving its zips on disk with no history entry.
				$this->log("Parent backup {$parent_timestamp} no longer exists - registering incremental as a standalone set", 'warning');
				unset($backup_data['incremental_parent']);
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

			// Reuse hashes computed (and cached) during manifest generation
			$file_hashes = $this->jobdata_get('file_hashes', array());
			if (!is_array($file_hashes)) $file_hashes = array();
			foreach ($all_files as $file) {
				$fpath = $backup_dir . $file;
				clearstatcache(true, $fpath);
				$fsize = file_exists($fpath) ? filesize($fpath) : 0;
				$total_size += $fsize;
				$size_str = file_exists($fpath) ? SiteGuard_Backup_Filesystem::format_bytes($fsize) : '?';
				if (!empty($file_hashes[$file]['sha256'])) {
					$sha256 = $file_hashes[$file]['sha256'];
				} else {
					$sha256 = file_exists($fpath) ? self::hash_file_dual($fpath)['sha256'] : 'N/A';
					if ('' === $sha256) $sha256 = 'N/A';
				}
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
				} elseif ($type === 'files' && SiteGuard_Backup_History::file_entity_keys($backup)) {
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

				// Delete only this rule's type from each marked set. Deleting the whole
				// set let a db rule destroy file archives (and vice versa), locally and
				// remotely; History::prune() already works this way.
				foreach ($to_delete as $timestamp => $backup) {
					$entity_keys = ($type === 'db') ? array('db') : SiteGuard_Backup_History::file_entity_keys($backup);
					$this->log("Retention rule pruning: removing {$type} of backup at " . gmdate('Y-m-d H:i:s', $timestamp));
					SiteGuard_Backup_History::delete_backup_entities($timestamp, $entity_keys, true);
					unset($type_backups[$timestamp]);
				}
				// Later rules and the other type must see what is actually left.
				$history = SiteGuard_Backup_History::get_history();
				krsort($history);
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
			// A crash-resume can re-run this phase; don't record the manifest twice
			if (!in_array($manifest_filename, $backup_array, true)) {
				$backup_array[] = $manifest_filename;
				$this->jobdata_set('backup_files_array', $backup_array);
			}
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
	 * Compute MD5 and SHA-256 of a file in a single read pass.
	 *
	 * @param string $path Full path to the file.
	 * @return array array('md5' => ..., 'sha256' => ...); empty strings if unreadable.
	 */
	public static function hash_file_dual($path) {
		$fh = @fopen($path, 'rb');
		if (!$fh) return array('md5' => '', 'sha256' => '');
		$md5_ctx = hash_init('md5');
		$sha_ctx = hash_init('sha256');
		while (!feof($fh)) {
			$chunk = fread($fh, 1048576);
			if (false === $chunk || '' === $chunk) break;
			hash_update($md5_ctx, $chunk);
			hash_update($sha_ctx, $chunk);
		}
		fclose($fh);
		return array('md5' => hash_final($md5_ctx), 'sha256' => hash_final($sha_ctx));
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

		// Hash each file once (single read pass for md5+sha256) and cache the
		// results in jobdata so later consumers (email report) don't re-hash.
		$file_hashes = $this->jobdata_get('file_hashes', array());
		if (!is_array($file_hashes)) $file_hashes = array();
		foreach ($all_files as $file) {
			$path = $backup_dir . $file;
			clearstatcache(true, $path);
			$exists = file_exists($path);
			if ($exists && !isset($file_hashes[$file])) {
				$file_hashes[$file] = self::hash_file_dual($path);
			}
			$manifest['files'][] = array(
				'name' => $file,
				'size' => $exists ? filesize($path) : 0,
				'md5'  => ($exists && isset($file_hashes[$file])) ? $file_hashes[$file]['md5'] : '',
				'sha256' => ($exists && isset($file_hashes[$file])) ? $file_hashes[$file]['sha256'] : '',
			);
		}
		$this->jobdata_set('file_hashes', $file_hashes);

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
