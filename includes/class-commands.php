<?php
/**
 * SiteGuard Backup - AJAX Command Dispatcher
 *
 * Each public method corresponds to an AJAX command name.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Commands {

	/** @var array Allowed backup entities */
	private static $valid_entities = array('plugins', 'themes', 'uploads', 'others', 'wpcore');

	/** @var array Allowed storage methods */
	private static $valid_methods = array('email', 'ftp', 'sftp', 's3', 's3generic', 'googledrive', 'dropbox', 'onedrive', 'webdav', 'remotesend', 'azure', 'gcs', 'pcloud', 'openstack');

	/**
	 * Trigger a backup now.
	 */
	public function backupnow($params) {
		$sgbackup = SiteGuard_Backup::instance();

		$options = array();
		if (!empty($params['onlythisfileentity'])) {
			$entity = sanitize_text_field($params['onlythisfileentity']);
			if (in_array($entity, self::$valid_entities, true)) {
				$options['entities'] = array($entity);
			}
		} elseif (!empty($params['entities'])) {
			$options['entities'] = array_intersect(
				array_map('sanitize_text_field', (array) $params['entities']),
				self::$valid_entities
			);
		}
		if (!empty($params['nofiles'])) $options['nofiles'] = true;
		if (!empty($params['nodb'])) $options['nodb'] = true;
		if (!empty($params['always_keep'])) $options['always_keep'] = true;
		if (!empty($params['incremental'])) $options['job_type'] = 'incremental';
		if (!empty($params['service'])) {
			// Validate service array against whitelist
			// Allow "method/instance" format (e.g., "remotesend/0" for site-to-site migration)
			$svc = (array) $params['service'];
			$valid_with_none = array_merge(self::$valid_methods, array('none'));
			$svc = array_values(array_filter($svc, function($s) use ($valid_with_none) {
				if (in_array($s, $valid_with_none, true)) return true;
				// Check for "method/instance" format
				if (strpos($s, '/') !== false) {
					$method = explode('/', $s, 2)[0];
					return in_array($method, $valid_with_none, true);
				}
				return false;
			}));
			if (!empty($svc)) $options['service'] = $svc;
		}

		// H4: Backup label
		if (!empty($params['label'])) {
			$options['label'] = sanitize_text_field(substr($params['label'], 0, 40));
		}

		// H6: Per-table selection
		if (!empty($params['include_tables']) && is_array($params['include_tables'])) {
			$options['include_tables'] = array_values(array_filter(
				array_map(function($t) {
					// Sanitize table names: only allow valid MySQL identifier characters
					return preg_replace('/[^a-zA-Z0-9_$]/', '', sanitize_text_field($t));
				}, $params['include_tables']),
				function($t) { return !empty($t); }
			));
		}
		if (!empty($params['include_nonwp_tables'])) {
			$options['include_nonwp_tables'] = true;
		}

		// M5: Anonymisation options
		if (!empty($params['anonymise'])) {
			$options['db_anonymise'] = true;
			$valid_modes = array('all', 'non_staff', 'wc_orders');
			$mode = isset($params['anonymise_mode']) ? sanitize_text_field($params['anonymise_mode']) : 'all';
			$options['db_anonymise_mode'] = in_array($mode, $valid_modes, true) ? $mode : 'all';
		}

		// Per-subsite backup: only the current subsite's data
		if (!empty($params['subsite_backup']) && is_multisite() && get_current_blog_id() > 1) {
			$options['subsite_backup'] = true;
		}

		$sgbackup->backup_all($options);

		// Check if boot_backup failed (e.g., another job already running)
		if (!empty($sgbackup->boot_error)) {
			return new WP_Error('backup_blocked', $sgbackup->boot_error);
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$entities = isset($options['entities']) ? implode(', ', $options['entities']) : 'all';
		$audit->log('backup_started', 'Backup initiated: entities=' . $entities, array(
			'nonce' => $sgbackup->nonce,
			'type' => !empty($options['job_type']) ? $options['job_type'] : 'full',
		));

		$nonce = $sgbackup->nonce;

		// Close the browser connection immediately so the UI gets a response,
		// then run the first backup resumption in this same PHP process.
		// This is the standard close-the-browser-connection pattern and works
		// in DISABLE_WP_CRON environments where spawn_cron() does nothing.
		$response = json_encode(array('success' => true, 'data' => array('nonce' => $nonce)));
		$sgbackup->close_browser_connection($response);

		// Run backup directly - connection is already closed
		$sgbackup->backup_resume(0, $nonce);
		die;
	}

	/**
	 * Get active jobs list with progress.
	 */
	public function activejobs_list() {
		$jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
		$result = array();

		$sgbackup = SiteGuard_Backup::instance();
		foreach ($jobs as $nonce => $job_info) {
			$jobdata = $sgbackup->jobdata_getarray($nonce);

			// Build entity progress detail
			$entities = isset($jobdata['job_file_entities']) ? $jobdata['job_file_entities'] : array();
			$entity_progress = array();
			foreach ($entities as $entity => $info) {
				$status = isset($info['status']) ? $info['status'] : 'pending';
				$entity_progress[$entity] = $status;
			}

			// Determine current phase description
			$phase = 'Initializing';
			$files_done = !empty($jobdata['files_phase_complete']);
			$db_done = !empty($jobdata['db_phase_complete']);
			$upload_done = !empty($jobdata['upload_phase_complete']);
			if ($upload_done) {
				$phase = 'Finalizing';
			} elseif ($db_done) {
				$phase = 'Uploading to remote';
			} elseif ($files_done) {
				$phase = 'Backing up database';
			} elseif (!empty($entity_progress)) {
				foreach ($entity_progress as $ent => $st) {
					if ($st !== 'complete' && $st !== 'pending') {
						$phase = 'Backing up ' . $ent;
						break;
					}
				}
				if ($phase === 'Initializing' && array_filter($entity_progress, function($s) { return $s === 'complete'; })) {
					$phase = 'Backing up files';
				}
			}

			// Get last meaningful log message for this job
			$last_activity = isset($jobdata['last_activity']) ? $jobdata['last_activity'] : '';
			$last_log_line = '';
			$log_nonce = isset($jobdata['nonce']) ? $jobdata['nonce'] : $nonce;
			$logfile = $sgbackup->get_logfile_name($log_nonce);
			if (file_exists($logfile)) {
				// Read last 1024 bytes to find last meaningful log line
				$fh = @fopen($logfile, 'r');
				if ($fh) {
					clearstatcache(true, $logfile);
					$fsize = filesize($logfile);
					if ($fsize > 1024) fseek($fh, -1024, SEEK_END);
					$tail = fread($fh, 1024);
					fclose($fh);
					$lines = explode("\n", trim($tail));
					// Skip noise lines to find the last useful message
					for ($li = count($lines) - 1; $li >= 0; $li--) {
						$l = trim($lines[$li]);
						if (empty($l)) continue;
						if (strpos($l, '=== Log closed') !== false) continue;
						if (strpos($l, '=== Log opened') !== false) continue;
						if (strpos($l, '=== Resumption') !== false) continue;
						if (strpos($l, 'Memory:') !== false && strpos($l, 'MB') !== false && strlen($l) < 40) continue;
						if (strpos($l, 'Scheduled resumption') !== false) continue;
						$last_log_line = $l;
						break;
					}
				}
			}
			// Prefer jobdata last_activity over log tail (more current between resumptions)
			if (!empty($last_activity) && (empty($last_log_line) || strpos($last_log_line, 'Log closed') !== false)) {
				$last_log_line = $last_activity;
			}

			// Collect error messages from jobdata
			$job_errors = isset($jobdata['errors']) ? (array) $jobdata['errors'] : array();

			$result[$nonce] = array(
				'resumption'  => $job_info['resumption'],
				'next'        => $job_info['next_scheduled'],
				'jobstatus'   => isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : 'unknown',
				'backup_files' => isset($jobdata['backup_files']) ? $jobdata['backup_files'] : 'no',
				'backup_database' => isset($jobdata['backup_database']) ? $jobdata['backup_database'] : 'no',
				'files_phase_complete' => $files_done,
				'db_phase_complete' => $db_done,
				'upload_phase_complete' => $upload_done,
				'job_type'    => isset($jobdata['job_type']) ? $jobdata['job_type'] : 'backup',
				'phase'       => $phase,
				'entity_progress' => $entity_progress,
				'elapsed'     => isset($jobdata['backup_time']) ? (time() - $jobdata['backup_time']) : 0,
				'last_activity' => $last_activity,
				'last_log'    => $last_log_line,
				'files_added_count' => isset($jobdata['files_added_count']) ? $jobdata['files_added_count'] : 0,
				'files_total_count' => isset($jobdata['files_total_count']) ? $jobdata['files_total_count'] : 0,
				'db_tables_done'  => isset($jobdata['db_tables_done']) ? $jobdata['db_tables_done'] : 0,
				'db_tables_total' => isset($jobdata['db_tables_total']) ? $jobdata['db_tables_total'] : 0,
				'errors'      => $job_errors,
				'has_errors'  => !empty($job_errors),
			);
		}

		return $result;
	}

	/**
	 * Delete a backup set.
	 */
	public function delete_backup($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}

		$timestamp = intval($params['timestamp']);
		$delete_remote = !empty($params['delete_remote']);

		$deleted = SiteGuard_Backup_History::delete_backup_set($timestamp, $delete_remote);

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('delete_backup', 'Deleted backup set from ' . gmdate('Y-m-d H:i', $timestamp), array(
			'timestamp' => $timestamp,
			'files_deleted' => count($deleted),
			'remote_deleted' => $delete_remote ? 'yes' : 'no',
		));

		return array(
			'deleted'   => $deleted,
			'count'     => count($deleted),
			'timestamp' => $timestamp,
		);
	}

	/**
	 * Get backup log.
	 */
	public function get_log($params) {
		$nonce = isset($params['nonce']) ? sanitize_text_field($params['nonce']) : '';
		if (empty($nonce)) return new WP_Error('missing_param', 'Missing nonce');

		$sgbackup = SiteGuard_Backup::instance();
		$log = $sgbackup->get_log($nonce);

		// Defensive tail cap: never hand back more than 256KB even if the underlying
		// reader loaded a very large file. NOTE (coordinator): SiteGuard_Backup::get_log()
		// in class-siteguardbackup.php should also tail-cap at the source to avoid OOM there.
		$max_log_bytes = 262144;
		if (is_string($log) && strlen($log) > $max_log_bytes) {
			$log = "[log truncated]\n" . substr($log, -$max_log_bytes);
		}

		return array('log' => $log, 'nonce' => $nonce);
	}

	/**
	 * Get existing backups data.
	 */
	public function get_existing_backups() {
		$history = SiteGuard_Backup_History::get_history();
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$result = array();

		foreach ($history as $timestamp => $backup) {
			$entry = array(
				'timestamp' => $timestamp,
				'date'      => gmdate('Y-m-d H:i:s', $timestamp),
				'nonce'     => isset($backup['nonce']) ? $backup['nonce'] : '',
				'service'   => isset($backup['service']) ? $backup['service'] : array('none'),
				'always_keep' => !empty($backup['always_keep']),
				'label'     => isset($backup['label']) ? $backup['label'] : '',
			);

			// Add entity info
			foreach (array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db') as $entity) {
				if (!empty($backup[$entity])) {
					$files = is_array($backup[$entity]) ? $backup[$entity] : array($backup[$entity]);
					$size = 0;
					$exists = true;
					foreach ($files as $f) {
						$path = $backup_dir . $f;
						if (file_exists($path)) {
							$size += filesize($path);
						} else {
							$exists = false;
						}
					}
					$entry[$entity] = array(
						'files'  => $files,
						'size'   => $size,
						'exists' => $exists,
					);
				}
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Abort an active backup job.
	 */
	public function activejobs_delete($params) {
		$nonce = isset($params['job_id']) ? sanitize_text_field($params['job_id']) : '';
		if (empty($nonce)) return new WP_Error('missing_param', 'Missing job_id');

		SiteGuard_Backup_Job_Scheduler::request_abort($nonce);
		SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($nonce);

		// Mark jobdata as aborted so the UI stops showing it as running
		$sgbackup = SiteGuard_Backup::instance();
		$jobdata = $sgbackup->jobdata_getarray($nonce);
		if (!empty($jobdata)) {
			$jobdata['jobstatus'] = 'aborted';
			$sgbackup->jobdata_set($nonce, $jobdata);
		}

		return array('success' => true, 'nonce' => $nonce);
	}

	/**
	 * Force-resume a stuck backup job, bypassing cron.
	 */
	public function force_resume($params) {
		$nonce = isset($params['job_id']) ? sanitize_text_field($params['job_id']) : '';
		if (empty($nonce)) return new WP_Error('missing_param', 'Missing job_id');

		$sgbackup = SiteGuard_Backup::instance();
		$jobdata = $sgbackup->jobdata_getarray($nonce);
		if (empty($jobdata)) {
			return new WP_Error('not_found', 'No job data found for this nonce');
		}

		$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
		if (in_array($status, array('complete', 'error', 'aborted'), true)) {
			return new WP_Error('invalid_state', "Job is already in terminal state: {$status}");
		}

		$current_resumption = isset($jobdata['current_resumption']) ? intval($jobdata['current_resumption']) : 0;
		$next_resumption = $current_resumption + 1;

		// Clear existing scheduled events and run directly
		SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($nonce);

		$response = json_encode(array('success' => true, 'data' => array(
			'nonce' => $nonce,
			'resumption' => $next_resumption,
			'message' => "Force-resuming job at resumption {$next_resumption}",
		)));
		$sgbackup->close_browser_connection($response);

		$sgbackup->backup_resume($next_resumption, $nonce);
		die;
	}

	/**
	 * Toggle always_keep flag on a backup.
	 */
	public function always_keep_this_backup($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}

		$timestamp = intval($params['timestamp']);
		$always_keep = !empty($params['always_keep']);

		$history = SiteGuard_Backup_History::get_history_raw();
		if (isset($history[$timestamp])) {
			$history[$timestamp]['always_keep'] = $always_keep;
			SiteGuard_Backup_History::save_history($history);
			return array('success' => true);
		}

		return new WP_Error('not_found', 'Backup not found');
	}

	/**
	 * Save settings.
	 */
	public function savesettings($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$sgbackup = SiteGuard_Backup::instance();

		$settings_keys = array(
			'interval', 'interval_database', 'interval_incremental',
			'retain', 'retain_db', 'include_entities', 'exclude_files',
			'split_every', 'max_split', 'email', 'service', 'encryption_key',
			'autobackup_default', 'delete_local', 'debug_mode',
			'encryption_enabled', 'email_recipients', 'email_mode',
			'syslog_enabled', 'schedule_time',
			'schedule_day', 'schedule_day_db',
			'lock_admin', 'lock_admin_password', 'lock_admin_session_length',
			'lock_admin_support_url',
			'include_tables', 'exclude_tables',
			'backupdb_nonwp',
			'db_anonymise', 'db_anonymise_mode',
			'default_db_restore_mode',
			'default_subsite_mode',
			'default_keep_protocol',
			'extra_dirs', 'max_incrementals_before_full',
			'ssl_disableverify', 'ssl_useservercerts',
			'auto_update',
			'data_optional_tables',
			'zip_nocompress',
			'webhook_url',
			'webhook_events',
			'ip_allowlist',
			'encrypt_credentials',
			'failover_enabled',
			'failover_role',
			'failover_production_url',
			'health_enabled',
			'health_interval',
			'health_timeout',
			'health_ssl_warn_days',
			'low_memory_mode',
		);

		// Validate up-front, BEFORE any update_option, so a validation failure can't leave
		// settings half-applied. IP allowlist self-lockout guard must run before we commit
		// the password hash / recipients / encryption key / etc.
		if (isset($params['ip_allowlist']) && !empty(trim($params['ip_allowlist']))) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			if (!SiteGuard_Backup_Security::validate_allowlist_includes_current_ip($params['ip_allowlist'])) {
				return new WP_Error('lockout_prevention', 'Your current IP (' . SiteGuard_Backup_Security::get_client_ip() . ') is not in the allowlist. Add it to prevent locking yourself out.');
			}
		}

		// H5: Handle password protection settings specially before the main loop
		if (isset($params['lock_admin_password'])) {
			$password = $params['lock_admin_password'];
			if (!empty($password)) {
				// Hash and store the password using phpass
				if (!function_exists('wp_hash_password')) {
					require_once ABSPATH . WPINC . '/pluggable.php';
				}
				$opts->update_option('sgbackup_lock_admin_password', wp_hash_password($password));
			} elseif (isset($params['lock_admin']) && empty($params['lock_admin'])) {
				// Password is empty and lock_admin is being disabled: clear the hash
				$opts->update_option('sgbackup_lock_admin_password', '');
			}
			// Remove from params so the main loop doesn't overwrite with plaintext
			unset($params['lock_admin_password']);
		}

		// H5: Validate session length against allowed set
		if (isset($params['lock_admin_session_length'])) {
			$allowed_lengths = array(3600, 10800, 86400, 604800, 2419200, 31449600);
			$session_length = intval($params['lock_admin_session_length']);
			if (!in_array($session_length, $allowed_lengths, true)) {
				$session_length = 3600;
			}
			$params['lock_admin_session_length'] = $session_length;
		}

		// Handle structured email_recipients before the main loop
		if (isset($params['email_recipients'])) {
			$raw = $params['email_recipients'];
			if (is_array($raw)) {
				// Structured format: array of {address, warnings_only, send_backup, send_db_only}
				$sanitized_recipients = array();
				foreach ($raw as $entry) {
					if (!is_array($entry)) continue;
					$addr = isset($entry['address']) ? sanitize_email($entry['address']) : '';
					if (empty($addr) || !is_email($addr)) continue;
					$sanitized_recipients[] = array(
						'address'       => $addr,
						'warnings_only' => !empty($entry['warnings_only']) ? 1 : 0,
						'send_backup'   => !empty($entry['send_backup']) ? 1 : 0,
						'send_db_only'  => !empty($entry['send_db_only']) ? 1 : 0,
					);
				}
				$opts->update_option('sgbackup_email_recipients', $sanitized_recipients);
			} elseif (is_string($raw)) {
				// Legacy comma-separated string: convert to structured format
				$addrs = array_map('trim', explode(',', $raw));
				$addrs = array_filter($addrs, 'is_email');
				$sanitized_recipients = array();
				foreach ($addrs as $addr) {
					$sanitized_recipients[] = array(
						'address'       => sanitize_email($addr),
						'warnings_only' => 0,
						'send_backup'   => 0,
						'send_db_only'  => 0,
					);
				}
				$opts->update_option('sgbackup_email_recipients', $sanitized_recipients);
			}
			unset($params['email_recipients']);
		}

		// Handle encryption_key: empty means keep existing, non-empty means update
		if (isset($params['encryption_key'])) {
			$enc_key = $params['encryption_key'];
			if (!empty($enc_key)) {
				$opts->update_option('sgbackup_encryption_key', sanitize_text_field($enc_key));
			}
			// Empty = keep current key (don't clear it)
			unset($params['encryption_key']);
		}

		// Handle lock_admin_support_url: sanitize as URL or email
		if (isset($params['lock_admin_support_url'])) {
			$support_val = trim($params['lock_admin_support_url']);
			if (is_email($support_val)) {
				$support_val = sanitize_email($support_val);
			} else {
				$support_val = esc_url_raw($support_val);
			}
			$opts->update_option('sgbackup_lock_admin_support_url', $support_val);
			unset($params['lock_admin_support_url']);
		}

		// Handle webhook_url: sanitize as URL
		if (isset($params['webhook_url'])) {
			$webhook_url = trim($params['webhook_url']);
			if (!empty($webhook_url)) {
				$webhook_url = esc_url_raw($webhook_url, array('https', 'http'));
			}
			$opts->update_option('sgbackup_webhook_url', $webhook_url);
			unset($params['webhook_url']);
		}

		// Handle webhook_events: validate against known event list
		if (isset($params['webhook_events'])) {
			$valid_events = array('backup_complete', 'backup_failed', 'restore_complete', 'restore_failed', 'schedule_missed');
			$events = is_array($params['webhook_events']) ? $params['webhook_events'] : array();
			$events = array_values(array_intersect($events, $valid_events));
			$opts->update_option('sgbackup_webhook_events', $events);
			unset($params['webhook_events']);
		}

		// Failover role: validate against known roles
		if (isset($params['failover_role'])) {
			$valid_roles = array('standalone', 'production', 'standby');
			if (!in_array($params['failover_role'], $valid_roles, true)) {
				$params['failover_role'] = 'standalone';
			}
		}

		// Failover production URL: sanitize as URL
		if (isset($params['failover_production_url'])) {
			$params['failover_production_url'] = esc_url_raw(trim($params['failover_production_url']), array('https', 'http'));
		}

		// Handle credential encryption toggle: migrate existing credentials when first enabled
		if (isset($params['encrypt_credentials']) && intval($params['encrypt_credentials'])) {
			$was_enabled = $opts->get_option('sgbackup_encrypt_credentials', false);
			if (!$was_enabled) {
				require_once SGBACKUP_DIR . 'includes/class-security.php';
				// Encrypt existing plaintext credentials for all storage methods
				foreach (self::$valid_methods as $method_id) {
					$stored = $opts->get_option('sgbackup_' . $method_id, array());
					if (empty($stored) || !isset($stored['settings'])) continue;
					$changed = false;
					foreach ($stored['settings'] as $instance_id => &$instance_settings) {
						if (!is_array($instance_settings)) continue;
						$encrypted = SiteGuard_Backup_Security::encrypt_method_options($method_id, $instance_settings);
						if ($encrypted !== $instance_settings) {
							$instance_settings = $encrypted;
							$changed = true;
						}
					}
					unset($instance_settings);
					if ($changed) {
						$opts->update_option('sgbackup_' . $method_id, $stored);
					}
				}
			}
		}

		// Checkbox arrays: default to empty when absent from POST (unchecked = not submitted)
		if (!isset($params['include_entities'])) {
			$params['include_entities'] = array();
		}

		foreach ($settings_keys as $key) {
			// lock_admin_password is already handled above
			if ($key === 'lock_admin_password') continue;
			// M9: extra_dirs is handled separately with path validation below
			if ($key === 'extra_dirs') continue;
			// email_recipients is handled above with structured format
			if ($key === 'email_recipients') continue;
			// lock_admin_support_url is handled above
			if ($key === 'lock_admin_support_url') continue;
			// encryption_key is handled above (empty = keep existing)
			if ($key === 'encryption_key') continue;
			// webhook_url and webhook_events are handled above
			if ($key === 'webhook_url' || $key === 'webhook_events') continue;
			// failover_role and failover_production_url are sanitized above
			if ($key === 'failover_role' || $key === 'failover_production_url') continue;

			if (isset($params[$key])) {
				$value = $params[$key];
				// Sanitize
				if ($key === 'health_interval') {
					$valid_intervals = array('every5min', 'every10min', 'every15min', 'every30min', 'everyhour', 'every2hours', 'every4hours', 'every8hours', 'twicedaily', 'daily');
					$value = in_array($value, $valid_intervals, true) ? $value : 'every10min';
				} elseif ($key === 'low_memory_mode') {
					$value = in_array($value, array('auto', 'on', 'off'), true) ? $value : 'auto';
				} elseif (in_array($key, array('retain', 'retain_db', 'split_every', 'max_split', 'delete_local', 'debug_mode', 'encryption_enabled', 'syslog_enabled', 'lock_admin', 'lock_admin_session_length', 'backupdb_nonwp', 'db_anonymise', 'max_incrementals_before_full', 'ssl_disableverify', 'ssl_useservercerts', 'auto_update', 'encrypt_credentials', 'failover_enabled', 'default_db_restore_mode', 'default_subsite_mode', 'default_keep_protocol', 'health_enabled', 'health_timeout', 'health_ssl_warn_days'))) {
					$value = intval($value);
				} elseif ($key === 'email') {
					$value = sanitize_email($value);
				} elseif ($key === 'service') {
					// Validate service array against whitelist
					if (is_array($value)) {
						$value = array_values(array_filter($value, function($svc) {
							return in_array($svc, array_merge(self::$valid_methods, array('none')), true);
						}));
						if (empty($value)) $value = array('none');
					} else {
						$value = array('none');
					}
				} elseif (in_array($key, array('include_tables', 'exclude_tables'), true)) {
					// Sanitize table name arrays: only valid MySQL identifier characters
					if (is_array($value)) {
						$value = array_values(array_filter(
							array_map(function($t) {
								return preg_replace('/[^a-zA-Z0-9_$]/', '', sanitize_text_field($t));
							}, $value),
							function($t) { return !empty($t); }
						));
					} else {
						$value = array();
					}
				} elseif ($key === 'exclude_files') {
					// Textarea value: split newline-separated string into array
					if (is_string($value)) {
						$value = array_values(array_filter(array_map('trim', explode("\n", $value))));
					}
					if (!is_array($value)) $value = array();
					$value = array_map('sanitize_text_field', $value);
				} elseif ($key === 'ip_allowlist') {
					// Preserve newlines for textarea (one IP/CIDR per line)
					if (is_string($value)) {
						$lines = array_filter(array_map('trim', explode("\n", $value)));
						// Validate each line is a valid IP or CIDR
						$valid_lines = array();
						foreach ($lines as $line) {
							$line = sanitize_text_field($line);
							if (filter_var($line, FILTER_VALIDATE_IP)) {
								$valid_lines[] = $line;
							} elseif (preg_match('#^[0-9a-f.:]+/\d{1,3}$#i', $line)) {
								// CIDR notation
								$parts = explode('/', $line, 2);
								if (filter_var($parts[0], FILTER_VALIDATE_IP) && intval($parts[1]) >= 0 && intval($parts[1]) <= 128) {
									$valid_lines[] = $line;
								}
							}
						}
						$value = implode("\n", $valid_lines);
					}
				} elseif (is_string($value)) {
					$value = sanitize_text_field($value);
				}
				$opts->update_option('sgbackup_' . $key, $value);
			}
		}

		// Update schedules
		if (isset($params['interval'])) {
			$sgbackup->schedule_backup($params['interval']);
		}
		if (isset($params['interval_database'])) {
			$sgbackup->schedule_backup_database($params['interval_database']);
		}
		if (isset($params['interval_incremental'])) {
			$sgbackup->schedule_backup_increments($params['interval_incremental']);
		}

		// Health monitor schedule management
		if (isset($params['health_enabled'])) {
			$health_enabled = intval($params['health_enabled']);
			$health_interval = isset($params['health_interval']) ? sanitize_text_field($params['health_interval']) : $opts->get_option('sgbackup_health_interval', 'every10min');
			if ($health_enabled) {
				wp_clear_scheduled_hook('sgbackup_health_check');
				wp_schedule_event(time() + 60, $health_interval, 'sgbackup_health_check');
			} else {
				wp_clear_scheduled_hook('sgbackup_health_check');
			}
		}

		// Save storage method settings (whitelist method names)
		// Fields that may contain multi-line data (SSH keys, tokens) - use wp_kses
		// instead of sanitize_text_field which strips newlines
		$multiline_fields = array('key', 'pass', 'secret', 'token', 'private_key', 'client_secret', 'access_token', 'refresh_token');
		if (!empty($params['method_settings']) && is_array($params['method_settings'])) {
			foreach ($params['method_settings'] as $method => $method_data) {
				$method = sanitize_text_field($method);
				if (!in_array($method, self::$valid_methods, true)) continue;
				if (!is_array($method_data)) continue;

				$sanitize_method_value = function(&$val, $key) use ($multiline_fields) {
					if (!is_string($val)) return;
					if (in_array($key, $multiline_fields, true)) {
						// Preserve newlines but strip tags and null bytes
						$val = wp_check_invalid_utf8($val);
						$val = wp_strip_all_tags($val);
						$val = str_replace(chr(0), '', $val);
					} else {
						$val = sanitize_text_field($val);
					}
				};

				// Check if data uses multi-instance format (has 'settings' sub-key)
				if (isset($method_data['settings']) && is_array($method_data['settings'])) {
					$stored = $opts->get_option('sgbackup_' . $method, array());
					if (!isset($stored['version'])) {
						$stored = array('version' => 1, 'settings' => array());
					}
					foreach ($method_data['settings'] as $instance_id => $instance_settings) {
						$instance_id = sanitize_text_field($instance_id);
						if (!is_array($instance_settings)) continue;
						array_walk($instance_settings, $sanitize_method_value);
						// Encrypt credentials at rest if enabled
						if ($opts->get_option('sgbackup_encrypt_credentials', false)) {
							require_once SGBACKUP_DIR . 'includes/class-security.php';
							$instance_settings = SiteGuard_Backup_Security::encrypt_method_options($method, $instance_settings);
						}
						$stored['settings'][$instance_id] = $instance_settings;
					}
					$opts->update_option('sgbackup_' . $method, $stored);
				} else {
					// Legacy flat format
					array_walk($method_data, $sanitize_method_value);
					if ($opts->get_option('sgbackup_encrypt_credentials', false)) {
						require_once SGBACKUP_DIR . 'includes/class-security.php';
						$method_data = SiteGuard_Backup_Security::encrypt_method_options($method, $method_data);
					}
					$opts->update_option('sgbackup_' . $method, $method_data);
				}
			}
		}

		// Save tiered retention rules
		if (isset($params['retain_extrarules']) && is_array($params['retain_extrarules'])) {
			$allowed_periods = array(3600, 86400, 604800, 2592000);
			$sanitized_rules = array('files' => array(), 'db' => array());

			foreach (array('files', 'db') as $type) {
				if (!empty($params['retain_extrarules'][$type]) && is_array($params['retain_extrarules'][$type])) {
					foreach ($params['retain_extrarules'][$type] as $rule) {
						if (!is_array($rule)) continue;
						$after_count = isset($rule['after_count']) ? intval($rule['after_count']) : 0;
						$after_period = isset($rule['after_period']) ? intval($rule['after_period']) : 0;
						$keep_every = isset($rule['keep_every']) ? intval($rule['keep_every']) : 0;
						if ($after_count < 1) continue;
						if (!in_array($after_period, $allowed_periods, true)) continue;
						if (!in_array($keep_every, $allowed_periods, true)) continue;
						$sanitized_rules[$type][] = array(
							'after_count'  => $after_count,
							'after_period' => $after_period,
							'keep_every'   => $keep_every,
						);
					}
				}
			}
			$opts->update_option('sgbackup_retain_extrarules', $sanitized_rules);
		}

		// Save external databases
		if (isset($params['extradbs']) && is_array($params['extradbs'])) {
			$sanitized_extradbs = array();
			foreach ($params['extradbs'] as $extdb) {
				if (!is_array($extdb)) continue;
				$sanitized_extradbs[] = array(
					'host'   => isset($extdb['host']) ? sanitize_text_field($extdb['host']) : '',
					'port'   => isset($extdb['port']) ? intval($extdb['port']) : 3306,
					'user'   => isset($extdb['user']) ? sanitize_text_field($extdb['user']) : '',
					// Don't sanitize_text_field the DB password (strips spaces/newlines/`<`,
					// corrupting valid credentials); only strip null bytes.
					'pass'   => isset($extdb['pass']) ? str_replace(chr(0), '', (string) $extdb['pass']) : '',
					'name'   => isset($extdb['name']) ? sanitize_text_field($extdb['name']) : '',
					'prefix' => isset($extdb['prefix']) ? sanitize_text_field($extdb['prefix']) : '',
				);
			}
			$opts->update_option('sgbackup_extradbs', $sanitized_extradbs);
		}

		// M9: Save extra directories with path validation
		if (isset($params['extra_dirs']) && is_array($params['extra_dirs'])) {
			$sanitized_dirs = array();
			foreach ($params['extra_dirs'] as $dir) {
				if (!is_string($dir)) continue;
				$dir = sanitize_text_field(trim($dir));
				if (empty($dir)) continue;

				// Must be an absolute path
				if (strpos($dir, '/') !== 0 && strpos($dir, DIRECTORY_SEPARATOR) !== 0) continue;

				// Resolve to real path if it exists
				$real = realpath($dir);
				if (false !== $real && is_dir($real) && is_readable($real)) {
					$sanitized_dirs[] = $real;
				} else {
					// Allow saving paths that don't exist yet (will be validated at backup time)
					$sanitized_dirs[] = $dir;
				}
			}
			$opts->update_option('sgbackup_extra_dirs', $sanitized_dirs);
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$changed = array();
		if (isset($params['interval'])) $changed[] = 'schedule';
		if (isset($params['encryption_enabled'])) $changed[] = 'encryption';
		if (isset($params['lock_admin'])) $changed[] = 'admin_lock';
		if (isset($params['service'])) $changed[] = 'remote_storage';
		$audit->log('settings_saved', 'Settings updated: ' . (!empty($changed) ? implode(', ', $changed) : 'general'));

		return array('success' => true, 'saved' => $settings_keys);
	}

	/**
	 * Test an external database connection.
	 */
	public function test_extdb($params) {
		if (empty($params['host']) || empty($params['user']) || empty($params['name'])) {
			return new WP_Error('missing_param', 'Missing required database connection parameters (host, user, name).');
		}

		$host = sanitize_text_field($params['host']);
		$user = sanitize_text_field($params['user']);
		// Do NOT sanitize_text_field the DB password: it strips spaces/newlines/`<` and would
		// corrupt otherwise-valid credentials. Only strip null bytes.
		$pass = isset($params['pass']) ? str_replace(chr(0), '', (string) $params['pass']) : '';
		$name = sanitize_text_field($params['name']);

		// SSRF hardening: refuse cloud-metadata / link-local targets and audit the outbound attempt.
		$host_only = preg_replace('/:\d+$/', '', trim($host));
		$check_ip = filter_var($host_only, FILTER_VALIDATE_IP) ? $host_only : @gethostbyname($host_only);
		if (is_string($check_ip) && filter_var($check_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			// 169.254.0.0/16 = link-local, includes the 169.254.169.254 cloud metadata endpoint.
			if (strpos($check_ip, '169.254.') === 0) {
				return new WP_Error('db_host_blocked', 'Refusing to connect to a link-local/metadata address (' . $check_ip . ').');
			}
		}
		if (class_exists('SiteGuard_Backup_Audit_Log')) {
			SiteGuard_Backup_Audit_Log::instance()->log('extdb_test', 'External DB connection test to host: ' . $host_only);
		}

		$test_wpdb = new wpdb($user, $pass, $name, $host);

		if (!empty($test_wpdb->error)) {
			$error_msg = '';
			if (is_wp_error($test_wpdb->error)) {
				$error_msg = $test_wpdb->error->get_error_message();
			} else {
				$error_msg = 'Could not connect to database.';
			}
			return new WP_Error('db_connect_failed', $error_msg);
		}

		// Verify we can query
		$result = $test_wpdb->get_var("SELECT 1");
		if ($result === null && !empty($test_wpdb->last_error)) {
			return new WP_Error('db_query_failed', $test_wpdb->last_error);
		}

		$tables = $test_wpdb->get_col("SHOW TABLES");
		$table_count = is_array($tables) ? count($tables) : 0;

		return array('success' => true, 'message' => "Connected successfully. Found {$table_count} table(s).");
	}

	/**
	 * Get current settings.
	 */
	public function get_settings() {
		$opts = SiteGuard_Backup_Options::instance();
		$settings = $opts->get_all_settings();
		// Redact sensitive fields - use export_settings for full export
		if (!empty($settings['encryption_key'])) {
			$settings['encryption_key'] = '***';
		}
		if (!empty($settings['lock_admin_password'])) {
			$settings['lock_admin_password'] = '***';
		}
		return $settings;
	}

	/**
	 * Initiate a restore.
	 */
	public function restore_alldownloaded($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}

		$timestamp = intval($params['timestamp']);
		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			return new WP_Error('not_found', 'Backup not found');
		}

		// Parse restore options
		$restore_options = array();
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity) {
			$restore_options[$entity] = !empty($params['restore_' . $entity]);
		}
		if (!empty($params['encryption_key'])) {
			$restore_options['encryption_key'] = sanitize_text_field($params['encryption_key']);
		}
		if (isset($params['move_in_mode'])) {
			$restore_options['move_in_mode'] = min(3, max(0, intval($params['move_in_mode'])));
		}
		if (isset($params['db_restore_mode'])) {
			$restore_options['db_restore_mode'] = min(3, max(0, intval($params['db_restore_mode'])));
		}
		if (isset($params['subsite_mode'])) {
			$restore_options['subsite_mode'] = min(5, max(0, intval($params['subsite_mode'])));
		}
		if (!empty($params['subsite_map']) && is_array($params['subsite_map'])) {
			$restore_options['subsite_map'] = $params['subsite_map'];
		}
		if (!empty($params['batch_subsite_map']) && is_array($params['batch_subsite_map'])) {
			$restore_options['batch_subsite_map'] = $params['batch_subsite_map'];
		}
		if (!empty($params['target_subsite_domain'])) {
			$restore_options['target_subsite_domain'] = sanitize_text_field($params['target_subsite_domain']);
		}
		if (!empty($params['target_subsite_id'])) {
			$restore_options['target_subsite_id'] = intval($params['target_subsite_id']);
		}
		if (!empty($params['pre_restore_backup'])) {
			$restore_options['pre_restore_backup'] = true;
		}
		if (!empty($params['keep_protocol'])) {
			$restore_options['keep_protocol'] = true;
		}

		// Gap 25: Include-unspecified options
		if (isset($params['include_unspecified_tables'])) {
			$restore_options['include_unspecified_tables'] = !empty($params['include_unspecified_tables']);
		}
		if (isset($params['include_unspecified_plugins'])) {
			$restore_options['include_unspecified_plugins'] = !empty($params['include_unspecified_plugins']);
		}
		if (isset($params['include_unspecified_themes'])) {
			$restore_options['include_unspecified_themes'] = !empty($params['include_unspecified_themes']);
		}

		// Selective table restore
		if (!empty($params['restore_tables']) && is_array($params['restore_tables'])) {
			$restore_options['restore_tables'] = array_map('sanitize_text_field', $params['restore_tables']);
		}

		require_once SGBACKUP_DIR . 'restorer.php';
		$restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
		$result = $restorer->perform_restore();

		return $result;
	}

	/**
	 * Create a migration key.
	 */
	public function migrate_key_create($params) {
		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';

		$name = isset($params['name']) ? sanitize_text_field($params['name']) : 'default';
		$key_size = isset($params['key_size']) ? intval($params['key_size']) : 2048;

		$rpc = new SiteGuard_Backup_Remote_Communications();
		$result = $rpc->generate_key($name, $key_size);

		if (is_wp_error($result)) return $result;

		return array(
			'success' => true,
			'name'    => $name,
			'bundle'  => $result['bundle'],
		);
	}

	/**
	 * Add a new migration destination.
	 */
	public function migrate_newdestination($params) {
		if (empty($params['key_bundle'])) {
			return new WP_Error('missing_param', 'Missing key bundle');
		}

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';

		$rpc = new SiteGuard_Backup_Remote_Communications();
		$result = $rpc->decode_portable_bundle($params['key_bundle']);

		if (is_wp_error($result)) return $result;

		// Save destination
		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		$site_id = empty($sites) ? 0 : max(array_keys($sites)) + 1;
		$sites[$site_id] = $result;
		$opts->update_option('sgbackup_remotesites', $sites);

		return array('success' => true, 'site_id' => $site_id, 'name' => $result['name']);
	}

	/**
	 * Ping test a migration destination.
	 */
	public function ping_test($params) {
		if (!isset($params['site_id'])) {
			return new WP_Error('missing_param', 'Missing site_id');
		}

		require_once SGBACKUP_DIR . 'includes/class-remote-communications.php';

		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		$site_id = intval($params['site_id']);

		if (!isset($sites[$site_id])) {
			return new WP_Error('not_found', 'Destination not found');
		}

		$rpc = new SiteGuard_Backup_Remote_Communications();
		$result = $rpc->send_message($sites[$site_id], 'ping', array());

		if (is_wp_error($result)) return $result;

		return array('success' => true, 'response' => $result);
	}

	/**
	 * Send backup to a remote site.
	 */
	public function migrate_send($params) {
		if (!isset($params['site_id']) || !isset($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing site_id or timestamp');
		}

		$sgbackup = SiteGuard_Backup::instance();
		$timestamp = intval($params['timestamp']);
		$site_id = intval($params['site_id']);

		error_log("SiteGuard Backup: migrate_send called - timestamp={$timestamp}, site_id={$site_id}");

		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			error_log("SiteGuard Backup: migrate_send - backup not found for timestamp {$timestamp}");
			return new WP_Error('not_found', 'Backup not found');
		}

		// Start the send as a backup job (use remotesend method)
		// Pre-set all phase-complete flags and file arrays so they're included
		// in the initial jobdata BEFORE the cron resumption is scheduled.
		$backup_files = SiteGuard_Backup_History::get_all_files($backup_set);
		error_log("SiteGuard Backup: migrate_send - files to send: " . json_encode($backup_files));

		$options = array(
			'service'                => array("remotesend/{$site_id}"),
			'nofiles'                => true,
			'nodb'                   => true,
			'initial_jobdata_extra'  => array(
				'backup_files_array'        => $backup_files,
				'db_backup_files'           => array(),
				'files_phase_complete'      => true,
				'db_phase_complete'         => true,
				'encryption_phase_complete' => true,
				'manifest_phase_complete'   => true,
				'original_backup_timestamp' => $timestamp,
			),
		);

		if (!empty($params['label'])) {
			$options['label'] = sanitize_text_field(substr($params['label'], 0, 40));
		}

		$sgbackup->backup_all($options);

		$nonce = $sgbackup->nonce;
		error_log("SiteGuard Backup: migrate_send - job started with nonce: " . $nonce);

		// Close browser connection and run the send inline (same pattern as backupnow).
		// Required for DISABLE_WP_CRON environments where spawn_cron() does nothing.
		$response = json_encode(array('success' => true, 'data' => array('nonce' => $nonce)));
		$sgbackup->close_browser_connection($response);

		$sgbackup->backup_resume(0, $nonce);
		die;
	}

	/**
	 * Get system information for debugging.
	 */
	public function get_sysinfo() {
		global $wpdb;

		$opts = SiteGuard_Backup_Options::instance();

		$info = array(
			'plugin_version' => SGBACKUP_VERSION,
			'php_version'    => phpversion(),
			'wp_version'     => get_bloginfo('version'),
			'multisite'      => is_multisite(),
			'os'             => PHP_OS,
			'memory_limit'   => ini_get('memory_limit'),
			'system_memory'  => SiteGuard_Backup::system_memory_bytes() > 0 ? size_format(SiteGuard_Backup::system_memory_bytes()) : 'unknown',
			'low_memory_mode' => $opts->get_option('sgbackup_low_memory_mode', 'auto') . ' (' . (SiteGuard_Backup::instance()->is_low_memory() ? 'active' : 'inactive') . ')',
			'max_execution'  => ini_get('max_execution_time'),
			'upload_max'     => ini_get('upload_max_filesize'),
			'post_max'       => ini_get('post_max_size'),
			'db_version'     => $wpdb->db_version(),
			'zip_method'     => SiteGuard_Backup_Filesystem::get_zip_method(),
			'mysqldump'      => SiteGuard_Backup_Database_Utility::has_mysqldump(),
			'openssl'        => extension_loaded('openssl'),
			'gzip'           => function_exists('gzopen'),
			'disk_space'     => SiteGuard_Backup_Filesystem::check_disk_space(ABSPATH),
		);

		// Only expose backup_dir in debug mode
		if ($opts->get_option('sgbackup_debug_mode', false)) {
			$info['backup_dir'] = $opts->get_backup_dir();
		}

		return $info;
	}

	/**
	 * Download a backup file.
	 */
	public function download_file($params) {
		if (empty($params['file'])) {
			return new WP_Error('missing_param', 'Missing file');
		}

		$file = sanitize_file_name($params['file']);
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$path = $backup_dir . $file;

		if (!file_exists($path)) {
			return new WP_Error('not_found', 'File not found');
		}

		return array(
			'size' => filesize($path),
			'name' => $file,
		);
	}

	/**
	 * Clone site to a remote destination (create full backup + send in one operation).
	 */
	public function clone_to_site($params) {
		if (!isset($params['site_id'])) {
			return new WP_Error('missing_param', 'Missing site_id');
		}

		$site_id = intval($params['site_id']);

		// Verify the remote site exists
		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		if (!isset($sites[$site_id])) {
			return new WP_Error('not_found', 'Destination site not found');
		}

		// Start a full backup (all entities + DB) with remotesend as the service
		$sgbackup = SiteGuard_Backup::instance();
		$label = !empty($params['label'])
			? sanitize_text_field(substr($params['label'], 0, 40))
			: 'Migration clone ' . gmdate('Y-m-d H:i');
		$options = array(
			'service' => array("remotesend/{$site_id}"),
			'label'   => $label,
		);
		$sgbackup->backup_all($options);

		$nonce = $sgbackup->nonce;

		// Close browser connection and run inline (same pattern as backupnow).
		// Required for DISABLE_WP_CRON environments where spawn_cron() does nothing.
		$response = json_encode(array('success' => true, 'data' => array('nonce' => $nonce)));
		$sgbackup->close_browser_connection($response);

		$sgbackup->backup_resume(0, $nonce);
		die;
	}

	/**
	 * Get migration destinations.
	 */
	public function get_remotesites() {
		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		$result = array();

		foreach ($sites as $id => $site) {
			$result[$id] = array(
				'name' => isset($site['name']) ? $site['name'] : "Site #{$id}",
				'url'  => isset($site['url']) ? $site['url'] : '',
			);
		}

		return $result;
	}

	/**
	 * Delete a migration destination.
	 */
	public function delete_remotesite($params) {
		if (!isset($params['site_id'])) {
			return new WP_Error('missing_param', 'Missing site_id');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$sites = $opts->get_option('sgbackup_remotesites', array());
		$site_id = intval($params['site_id']);

		if (isset($sites[$site_id])) {
			unset($sites[$site_id]);
			$opts->update_option('sgbackup_remotesites', $sites);
			return array('success' => true);
		}

		return new WP_Error('not_found', 'Site not found');
	}

	/**
	 * Get migration keys.
	 */
	public function get_migrator_keys() {
		$opts = SiteGuard_Backup_Options::instance();
		$keys = $opts->get_option('sgbackup_migrator_localkeys', array());
		$result = array();

		foreach ($keys as $hash => $key_data) {
			$result[$hash] = array(
				'name'    => isset($key_data['name']) ? $key_data['name'] : 'unnamed',
				'created' => isset($key_data['created']) ? $key_data['created'] : 0,
			);
		}

		return $result;
	}

	/**
	 * Delete a migration key.
	 */
	public function delete_migrator_key($params) {
		if (empty($params['key_id'])) {
			return new WP_Error('missing_param', 'Missing key_id');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$keys = $opts->get_option('sgbackup_migrator_localkeys', array());
		$key_id = sanitize_text_field($params['key_id']);

		if (isset($keys[$key_id])) {
			unset($keys[$key_id]);
			$opts->update_option('sgbackup_migrator_localkeys', $keys);
			return array('success' => true);
		}

		return new WP_Error('not_found', 'Key not found');
	}

	/**
	 * Perform a database search-replace.
	 */
	public function searchreplace($params) {
		if (empty($params['search'])) {
			return new WP_Error('missing_param', 'Missing search string');
		}

		$search = array(sanitize_text_field($params['search']));
		$replace = array(sanitize_text_field(isset($params['replace']) ? $params['replace'] : ''));
		$dry_run = !empty($params['dry_run']);

		require_once SGBACKUP_DIR . 'includes/class-search-replace.php';

		global $wpdb;
		$tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);

		$sr = new SiteGuard_Backup_Search_Replace();
		$report = $sr->run($search, $replace, $tables, $dry_run);

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('search_replace', ($dry_run ? 'Dry-run' : 'Applied') . ' search-replace: "' . $params['search'] . '" -> "' . (isset($params['replace']) ? $params['replace'] : '') . '"', array(
			'dry_run' => $dry_run ? 'yes' : 'no',
		));

		return $report;
	}

	/**
	 * Wipe all plugin settings.
	 */
	public function wipe_settings() {
		// Audit log before wiping since the log setting and entries will be wiped
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('settings_wiped', 'All plugin settings reset to defaults.');

		$opts = SiteGuard_Backup_Options::instance();
		$defaults = $opts->get_defaults();

		foreach ($defaults as $key => $default) {
			$opts->delete_option('sgbackup_' . $key);
		}

		// Also clear schedules
		wp_clear_scheduled_hook('sgbackup_backup');
		wp_clear_scheduled_hook('sgbackup_backup_database');
		wp_clear_scheduled_hook('sgbackup_backup_incremental');

		return array('success' => true);
	}

	/**
	 * Handle Google Drive OAuth2 callback.
	 */
	public function googledrive_auth_callback($params) {
		if (empty($params['code'])) {
			// Return the auth URL for the user to visit
			require_once SGBACKUP_DIR . 'methods/backup-module.php';
			require_once SGBACKUP_DIR . 'methods/googledrive.php';

			$method = new SiteGuard_Backup_Method_Googledrive();
			return array('auth_url' => $method->get_auth_url());
		}

		// Verify state nonce
		$state = isset($params['state']) ? $params['state'] : '';
		if (!wp_verify_nonce($state, 'sgbackup-oauth-googledrive')) {
			return new WP_Error('invalid_state', 'Invalid OAuth state. Please try again.');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		require_once SGBACKUP_DIR . 'methods/googledrive.php';

		$method = new SiteGuard_Backup_Method_Googledrive();
		$result = $method->exchange_code(sanitize_text_field($params['code']));

		if (is_wp_error($result)) return $result;

		return array('success' => true, 'message' => 'Google Drive authenticated successfully.');
	}

	/**
	 * Handle Dropbox OAuth2 callback.
	 */
	public function dropbox_auth_callback($params) {
		if (empty($params['code'])) {
			// Return the auth URL for the user to visit
			require_once SGBACKUP_DIR . 'methods/backup-module.php';
			require_once SGBACKUP_DIR . 'methods/dropbox.php';

			$method = new SiteGuard_Backup_Method_Dropbox();
			return array('auth_url' => $method->get_auth_url());
		}

		// Verify state nonce
		$state = isset($params['state']) ? $params['state'] : '';
		if (!wp_verify_nonce($state, 'sgbackup-oauth-dropbox')) {
			return new WP_Error('invalid_state', 'Invalid OAuth state. Please try again.');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		require_once SGBACKUP_DIR . 'methods/dropbox.php';

		$method = new SiteGuard_Backup_Method_Dropbox();
		$result = $method->exchange_code(sanitize_text_field($params['code']));

		if (is_wp_error($result)) return $result;

		return array('success' => true, 'message' => 'Dropbox authenticated successfully.');
	}

	/**
	 * Handle OneDrive OAuth2 callback.
	 */
	public function onedrive_auth_callback($params) {
		if (empty($params['code'])) {
			// Return the auth URL for the user to visit
			require_once SGBACKUP_DIR . 'methods/backup-module.php';
			require_once SGBACKUP_DIR . 'methods/onedrive.php';

			$method = new SiteGuard_Backup_Method_Onedrive();
			return array('auth_url' => $method->get_auth_url());
		}

		// Verify state nonce
		$state = isset($params['state']) ? $params['state'] : '';
		if (!wp_verify_nonce($state, 'sgbackup-oauth-onedrive')) {
			return new WP_Error('invalid_state', 'Invalid OAuth state. Please try again.');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		require_once SGBACKUP_DIR . 'methods/onedrive.php';

		$method = new SiteGuard_Backup_Method_Onedrive();
		$result = $method->exchange_code(sanitize_text_field($params['code']));

		if (is_wp_error($result)) return $result;

		return array('success' => true, 'message' => 'OneDrive authenticated successfully.');
	}

	/**
	 * Get the contents of a backup set for selective restore.
	 * Returns lists of tables, plugins, and themes in the backup.
	 */
	public function get_backup_contents($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}

		$timestamp = intval($params['timestamp']);
		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			return new WP_Error('not_found', 'Backup not found');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$result = array('tables' => array(), 'plugins' => array(), 'themes' => array());

		// Gap 24: Include backup metadata for verification stage
		$result['timestamp'] = $timestamp;
		$result['current_siteurl'] = get_option('siteurl');
		$result['old_siteurl'] = '';

		// Include subsite backup metadata
		if (!empty($backup_set['is_subsite_backup'])) {
			$result['is_subsite_backup'] = true;
			$result['subsite_blog_id']   = isset($backup_set['subsite_blog_id']) ? intval($backup_set['subsite_blog_id']) : 0;
			$result['subsite_domain']    = isset($backup_set['subsite_domain']) ? $backup_set['subsite_domain'] : '';
			$result['subsite_path']      = isset($backup_set['subsite_path']) ? $backup_set['subsite_path'] : '/';
			$result['subsite_url']       = isset($backup_set['subsite_url']) ? $backup_set['subsite_url'] : '';
			$result['subsite_home_url']  = isset($backup_set['subsite_home_url']) ? $backup_set['subsite_home_url'] : '';
			$result['subsite_scheme']    = isset($backup_set['subsite_scheme']) ? $backup_set['subsite_scheme'] : '';
			$result['subsite_active_plugins']           = isset($backup_set['subsite_active_plugins']) && is_array($backup_set['subsite_active_plugins']) ? array_values($backup_set['subsite_active_plugins']) : array();
			$result['subsite_active_sitewide_plugins']  = isset($backup_set['subsite_active_sitewide_plugins']) && is_array($backup_set['subsite_active_sitewide_plugins']) ? array_values($backup_set['subsite_active_sitewide_plugins']) : array();
			$result['subsite_stylesheet']               = isset($backup_set['subsite_stylesheet']) ? $backup_set['subsite_stylesheet'] : '';
			$result['subsite_template']                 = isset($backup_set['subsite_template']) ? $backup_set['subsite_template'] : '';
		}

		// Calculate total backup size
		$total_size = 0;
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $ent) {
			if (empty($backup_set[$ent])) continue;
			$ent_files = is_array($backup_set[$ent]) ? $backup_set[$ent] : array($backup_set[$ent]);
			foreach ($ent_files as $f) {
				$fpath = $backup_dir . $f;
				if (file_exists($fpath)) {
					$total_size += filesize($fpath);
				}
			}
		}
		$result['size'] = size_format($total_size, 2);

		// Extract table names from DB backup
		if (!empty($backup_set['db'])) {
			$db_files = is_array($backup_set['db']) ? $backup_set['db'] : array($backup_set['db']);
			foreach ($db_files as $db_file) {
				$file_path = $backup_dir . $db_file;
				if (!file_exists($file_path)) continue;

				// Handle encrypted files
				if (preg_match('/\.crypt$/', $db_file)) {
					// Can't read encrypted contents without key
					$result['tables'][] = '(encrypted - provide key to list tables)';
					continue;
				}

				$is_gz = (substr($file_path, -3) === '.gz');
				$fh = $is_gz ? @gzopen($file_path, 'rb') : @fopen($file_path, 'r');
				if (!$fh) continue;

				$lines = 0;
				$in_blogs_insert = false;
				$in_options_insert = false;
				$in_main_options = false;
				$current_options_blog_id = 0;
				$multisite_blogs = array();
				$subsite_urls = array(); // fallback: blog_id => siteurl from wp_N_options
				$old_siteurl_from_main = false;
				$base_prefix = $GLOBALS['wpdb']->base_prefix;
				while (!($is_gz ? gzeof($fh) : feof($fh))) {
					$line = $is_gz ? gzgets($fh) : fgets($fh);
					if (false === $line) break;
					$lines++;
					$trimmed = trim($line);

					if (preg_match('/^CREATE TABLE\s+`([^`]+)`/i', $trimmed, $m)) {
						$result['tables'][] = $m[1];
						$in_blogs_insert = false;
						$in_options_insert = false;
					}

					// Track multi-line INSERT INTO *options* context.
					// Distinguishes main options (wp_options) from subsite (wp_N_options).
					if (preg_match('/^INSERT INTO\s+`([^`]*options)`/i', $trimmed, $ins_m)) {
						$in_options_insert = true;
						$in_main_options = ($ins_m[1] === $base_prefix . 'options');
						// Extract blog_id from subsite options table name (e.g. wp_23_options → 23)
						$current_options_blog_id = 0;
						if (preg_match('/^' . preg_quote($base_prefix, '/') . '(\d+)_options$/', $ins_m[1], $bid_m)) {
							$current_options_blog_id = (int) $bid_m[1];
						}
					}
					if ($in_options_insert && preg_match('/^(CREATE|DROP|INSERT INTO\s+`[^`]*(?<!options)`)/i', $trimmed)) {
						$in_options_insert = false;
						$in_main_options = false;
						$current_options_blog_id = 0;
					}
					if ($in_options_insert && $trimmed === ';') {
						$in_options_insert = false;
						$in_main_options = false;
						$current_options_blog_id = 0;
					}

					// Detect old siteurl from the SQL for migration detection.
					// Works for both single-line (mysqldump) and multi-line (PHP dump) formats.
					// In multisite backups, wp_N_options (subsite) may appear before wp_options
					// alphabetically. Prefer the main options table's siteurl.
					if ($in_options_insert || preg_match('/^INSERT INTO\s+`([^`]*options)`/i', $trimmed)) {
						$is_main = $in_main_options;
						if (preg_match("/'siteurl'\s*,\s*'([^']+)'/i", $trimmed, $url_m)) {
							if (empty($result['old_siteurl']) || ($is_main && !$old_siteurl_from_main)) {
								$result['old_siteurl'] = $url_m[1];
								if ($is_main) $old_siteurl_from_main = true;
							}
							// Capture per-subsite siteurl as fallback for when wp_blogs is missing
							if ($current_options_blog_id > 1 && !isset($subsite_urls[$current_options_blog_id])) {
								$subsite_urls[$current_options_blog_id] = $url_m[1];
							}
						}
					}

					// Detect multisite blog domains from wp_blogs INSERT statements.
					// Handles both single-line (mysqldump) and multi-line (PHP dump) formats
					// by tracking state until the statement-ending semicolon.
					if (preg_match("/INSERT INTO\s+`[^`]*_?blogs`/i", $line)) {
						$in_blogs_insert = true;
					}
					if ($in_blogs_insert) {
						// Try structured extraction: (blog_id, site_id, 'domain', 'path', ...)
						// PHP dump quotes integers ('26', '1'), mysqldump doesn't (26, 1)
						if (preg_match_all("/\(\s*'?(\d+)'?\s*,\s*'?\d+'?\s*,\s*'([a-zA-Z0-9._-]+\.[a-zA-Z]{2,})'\s*,\s*'([^']*)'/", $line, $tuple_matches, PREG_SET_ORDER)) {
							foreach ($tuple_matches as $tm) {
								$multisite_blogs[] = array(
									'blog_id' => (int) $tm[1],
									'domain'  => $tm[2],
									'path'    => $tm[3],
								);
							}
						} elseif (preg_match_all("/'([a-zA-Z0-9._-]+\.[a-zA-Z]{2,})'/", $line, $domain_matches)) {
							// Fallback: just extract domains (legacy format)
							foreach ($domain_matches[1] as $d) {
								$multisite_blogs[] = $d;
							}
						}
						// End of INSERT statement
						if (strpos($line, ';') !== false) {
							$in_blogs_insert = false;
						}
					}
				}
				$is_gz ? @gzclose($fh) : @fclose($fh);

				// Fallback: if wp_blogs table was missing from the dump, infer blogs
				// from subsite options tables (wp_N_options siteurl values) and table prefixes.
				if (empty($multisite_blogs) && !empty($subsite_urls)) {
					foreach ($subsite_urls as $blog_id => $siteurl) {
						$parsed = parse_url($siteurl);
						$domain = isset($parsed['host']) ? $parsed['host'] : '';
						$path = isset($parsed['path']) ? $parsed['path'] : '/';
						if (!empty($domain)) {
							$multisite_blogs[] = array(
								'blog_id' => $blog_id,
								'domain'  => $domain,
								'path'    => $path,
							);
						}
					}
					// Also add main site (blog_id=1) from old_siteurl if available
					if (!empty($result['old_siteurl'])) {
						$main_parsed = parse_url($result['old_siteurl']);
						$main_domain = isset($main_parsed['host']) ? $main_parsed['host'] : '';
						if (!empty($main_domain)) {
							array_unshift($multisite_blogs, array(
								'blog_id' => 1,
								'domain'  => $main_domain,
								'path'    => isset($main_parsed['path']) ? $main_parsed['path'] : '/',
							));
						}
					}
				}

				if (!empty($multisite_blogs)) {
					if (!isset($result['multisite_blogs'])) $result['multisite_blogs'] = array();
					$result['multisite_blogs'] = array_merge($result['multisite_blogs'], $multisite_blogs);
				}
			}
		}
		if (!empty($result['multisite_blogs'])) {
			// Normalize: deduplicate by domain, prefer structured entries over plain strings
			$seen = array();
			$normalized = array();
			foreach ($result['multisite_blogs'] as $entry) {
				$domain = is_array($entry) ? $entry['domain'] : $entry;
				if (!isset($seen[$domain])) {
					$seen[$domain] = true;
					$normalized[] = $entry;
				}
			}
			$result['multisite_blogs'] = $normalized;
		}

		// Auto-detect subsite backup from SQL tables when metadata is missing.
		// A subsite backup has wp_N_* tables but no wp_options or wp_blogs.
		if (empty($result['is_subsite_backup']) && !empty($result['tables'])) {
			global $wpdb;
			$base = $wpdb->base_prefix;
			$has_main_options = false;
			$subsite_id = 0;
			foreach ($result['tables'] as $tbl) {
				if ($tbl === $base . 'options' || $tbl === $base . 'blogs') {
					$has_main_options = true;
					break;
				}
				if (!$subsite_id && preg_match('/^' . preg_quote($base, '/') . '(\d+)_options$/', $tbl, $sm)) {
					$subsite_id = (int) $sm[1];
				}
			}
			if (!$has_main_options && $subsite_id > 0) {
				$result['is_subsite_backup'] = true;
				$result['subsite_blog_id'] = $subsite_id;
				if (empty($result['subsite_domain']) && !empty($result['old_siteurl'])) {
					$result['subsite_domain'] = parse_url($result['old_siteurl'], PHP_URL_HOST);
				}
				if (empty($result['subsite_url']) && !empty($result['old_siteurl'])) {
					$result['subsite_url']    = $result['old_siteurl'];
					$result['subsite_scheme'] = parse_url($result['old_siteurl'], PHP_URL_SCHEME);
					$p = parse_url($result['old_siteurl'], PHP_URL_PATH);
					$result['subsite_path']   = $p ? (rtrim($p, '/') . '/') : '/';
				}
			}
		}

		// M8: Add multisite blog grouping for tables
		if (is_multisite() && !empty($result['tables'])) {
			global $wpdb;
			$result['table_groups'] = $this->group_tables_by_blog($result['tables'], $wpdb->prefix);
		}

		// Return current site's multisite blogs for two-sided mapping UI
		if (is_multisite()) {
			$current_blogs = array();
			$sites = get_sites(array('number' => 500));
			foreach ($sites as $site) {
				$blog_details = get_blog_details($site->blog_id);
				$current_blogs[] = array(
					'blog_id'  => (int) $site->blog_id,
					'domain'   => $site->domain,
					'path'     => $site->path,
					'blogname' => $blog_details ? $blog_details->blogname : '',
				);
			}
			$result['current_multisite_blogs'] = $current_blogs;
		}

		// Extract plugin/theme names from zip files
		foreach (array('plugins', 'themes') as $entity) {
			if (empty($backup_set[$entity])) continue;
			$entity_files = is_array($backup_set[$entity]) ? $backup_set[$entity] : array($backup_set[$entity]);

			foreach ($entity_files as $zip_file) {
				$zip_path = $backup_dir . $zip_file;
				if (!file_exists($zip_path) || preg_match('/\.crypt$/', $zip_file)) continue;

				if (class_exists('ZipArchive')) {
					$zip = new ZipArchive();
					if (true === $zip->open($zip_path)) {
						$dirs = array();
						for ($i = 0; $i < $zip->numFiles; $i++) {
							$name = $zip->getNameIndex($i);
							$parts = explode('/', $name, 2);
							if (!empty($parts[0]) && !isset($dirs[$parts[0]])) {
								$dirs[$parts[0]] = true;
							}
						}
						$zip->close();
						$result[$entity] = array_merge($result[$entity], array_keys($dirs));
					}
				}
			}
			$result[$entity] = array_unique($result[$entity]);
			sort($result[$entity]);
		}

		return $result;
	}

	/**
	 * Group database tables by multisite blog ID.
	 *
	 * @param array  $tables List of table names.
	 * @param string $prefix WordPress table prefix.
	 * @return array Grouped structure: {blog_id: {blog_name: string, tables: array}}
	 */
	private function group_tables_by_blog($tables, $prefix) {
		$grouped = array();
		$prefix_escaped = preg_quote($prefix, '/');

		foreach ($tables as $table) {
			// Match tables like wp_2_options, wp_3_posts, etc.
			if (preg_match('/^' . $prefix_escaped . '(\d+)_(.+)$/', $table, $m)) {
				$blog_id = intval($m[1]);
				if (!isset($grouped[$blog_id])) {
					$blog_label = 'Blog #' . $blog_id;
					if (function_exists('get_blog_details')) {
						$details = get_blog_details($blog_id);
						if ($details) {
							$blog_label = $details->blogname . ' (Blog #' . $blog_id . ')';
						}
					}
					$grouped[$blog_id] = array(
						'label'  => $blog_label,
						'tables' => array(),
					);
				}
				$grouped[$blog_id]['tables'][] = $table;
			} else {
				// Main site tables (no blog ID prefix)
				if (!isset($grouped[1])) {
					$blog_label = 'Main Site';
					if (function_exists('get_blog_details')) {
						$details = get_blog_details(1);
						if ($details) {
							$blog_label = $details->blogname . ' (Main Site)';
						}
					}
					$grouped[1] = array(
						'label'  => $blog_label,
						'tables' => array(),
					);
				}
				$grouped[1]['tables'][] = $table;
			}
		}

		// Sort tables within each group
		foreach ($grouped as &$group) {
			sort($group['tables']);
		}
		unset($group);

		ksort($grouped);
		return $grouped;
	}

	/**
	 * Verify backup integrity using checksums.
	 */
	public function verify_backup($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}

		$timestamp = intval($params['timestamp']);
		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			return new WP_Error('not_found', 'Backup not found');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$nonce = isset($backup_set['nonce']) ? $backup_set['nonce'] : '';

		$result = array('verified' => 0, 'failed' => 0, 'missing' => 0, 'details' => array());

		// Find manifest
		$manifest_files = glob($backup_dir . '*' . $nonce . '-manifest.json');
		$manifest = null;
		if (!empty($manifest_files)) {
			$manifest = @json_decode(file_get_contents($manifest_files[0]), true);
		}

		$all_files = SiteGuard_Backup_History::get_all_files($backup_set);

		foreach ($all_files as $file) {
			$file_path = $backup_dir . $file;
			$entry = array('file' => $file, 'status' => 'ok');

			if (!file_exists($file_path)) {
				$entry['status'] = 'missing';
				$result['missing']++;
				$result['details'][] = $entry;
				continue;
			}

			// Check against manifest
			if ($manifest && !empty($manifest['files'])) {
				foreach ($manifest['files'] as $mf) {
					if ($mf['name'] === $file) {
						// Size check
						$actual_size = filesize($file_path);
						if (isset($mf['size']) && $mf['size'] > 0 && $actual_size !== $mf['size']) {
							$entry['status'] = 'size_mismatch';
							$entry['expected_size'] = $mf['size'];
							$entry['actual_size'] = $actual_size;
						}
						// SHA-256 check (preferred)
						if (!empty($mf['sha256'])) {
							$actual_sha = hash_file('sha256', $file_path);
							if ($actual_sha !== $mf['sha256']) {
								$entry['status'] = 'checksum_mismatch';
								$entry['expected_sha256'] = $mf['sha256'];
								$entry['actual_sha256'] = $actual_sha;
							}
						} elseif (!empty($mf['md5'])) {
							// Fallback to MD5
							$actual_md5 = md5_file($file_path);
							if ($actual_md5 !== $mf['md5']) {
								$entry['status'] = 'checksum_mismatch';
							}
						}
						break;
					}
				}
			}

			if ($entry['status'] === 'ok') {
				$result['verified']++;
				$entry['sha256'] = hash_file('sha256', $file_path);
				$entry['size'] = filesize($file_path);
			} else {
				$result['failed']++;
			}
			$result['details'][] = $entry;
		}

		$result['success'] = ($result['failed'] === 0 && $result['missing'] === 0);
		return $result;
	}

	/**
	 * Resume a failed restore from saved progress.
	 */
	public function resume_restore($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$progress = $opts->get_transient('sgbackup_restore_progress');

		if (empty($progress)) {
			return new WP_Error('no_progress', 'No restore in progress to resume');
		}

		$timestamp = isset($progress['timestamp']) ? $progress['timestamp'] : 0;
		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			return new WP_Error('not_found', 'Backup not found');
		}

		require_once SGBACKUP_DIR . 'restorer.php';
		$restore_options = isset($progress['options']) ? $progress['options'] : array();
		$restore_options['resume_from'] = $progress;

		$restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
		return $restorer->perform_restore();
	}

	/**
	 * Test remote storage credentials.
	 */
	public function credentials_test($params) {
		if (empty($params['method'])) {
			return new WP_Error('missing_param', 'Missing storage method');
		}

		$method_name = sanitize_text_field($params['method']);
		if (!in_array($method_name, self::$valid_methods, true)) {
			return new WP_Error('invalid_method', 'Invalid storage method');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		$method_file = SGBACKUP_DIR . 'methods/' . $method_name . '.php';
		if (!file_exists($method_file)) {
			return new WP_Error('not_found', 'Storage method not found');
		}

		require_once $method_file;
		$class = 'SiteGuard_Backup_Method_' . ucfirst($method_name);
		if (!class_exists($class)) {
			return new WP_Error('not_found', 'Storage method class not found');
		}

		$method = new $class();
		if (!method_exists($method, 'credentials_test')) {
			return new WP_Error('not_supported', 'This storage method does not support credential testing');
		}

		$result = $method->credentials_test();
		if (is_wp_error($result)) return $result;

		// Preserve backend-specific messages (e.g., SFTP fingerprint info)
		if (is_array($result) && !empty($result['message'])) {
			return array('success' => true, 'message' => $result['message']);
		}

		return array('success' => true, 'message' => ucfirst($method_name) . ' connection successful.');
	}

	/**
	 * Get OAuth authentication URL for a storage method.
	 */
	public function get_auth_url($params) {
		if (empty($params['method'])) {
			return new WP_Error('missing_param', 'Missing storage method');
		}

		$method_name = sanitize_text_field($params['method']);
		$valid_oauth = array('googledrive', 'dropbox', 'onedrive');
		if (!in_array($method_name, $valid_oauth, true)) {
			return new WP_Error('invalid_method', 'This method does not use OAuth');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		require_once SGBACKUP_DIR . 'methods/' . $method_name . '.php';

		$class = 'SiteGuard_Backup_Method_' . ucfirst($method_name);
		$method = new $class();

		if (!method_exists($method, 'get_auth_url')) {
			return new WP_Error('not_supported', 'Method does not support OAuth');
		}

		return array('auth_url' => $method->get_auth_url());
	}

	/**
	 * L7: Import a foreign (non-SiteGuard) backup file into the backup history.
	 *
	 * Supports 7+ plugin formats: UpdraftPlus, BackWPUp, BackupWordPress,
	 * Simple Backups, InfiniteWP, WP-DB-Backup, and generic SQL/ZIP.
	 *
	 * @param array $params 'file' (string or array of filenames), optional 'detect_only' (bool).
	 * @return array|WP_Error Result with success status, timestamp, and detected format.
	 */
	public function import_foreign_backup($params) {
		if (empty($params['file'])) {
			return new WP_Error('missing_param', 'Missing file parameter');
		}

		require_once SGBACKUP_DIR . 'includes/class-foreign-importer.php';

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$real_dir = realpath($backup_dir);

		// Support single file or array of files
		$files = is_array($params['file']) ? $params['file'] : array($params['file']);
		$filepaths = array();

		foreach ($files as $file) {
			$file = sanitize_file_name($file);
			$path = $backup_dir . $file;

			if (!file_exists($path)) {
				return new WP_Error('not_found', 'File not found in backup directory: ' . $file);
			}

			$real_path = realpath($path);
			// Compare against the directory WITH a trailing separator so a sibling like
			// /…/sgbackupEVIL is not accepted as a child of /…/sgbackup.
			$real_dir_sep = $real_dir ? rtrim($real_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
			if ($real_path === false || $real_dir_sep === '' || strpos($real_path, $real_dir_sep) !== 0) {
				return new WP_Error('invalid_path', 'Invalid file path');
			}

			$filepaths[] = $real_path;
		}

		// Detect-only mode: return format info without importing
		if (!empty($params['detect_only'])) {
			$detections = array();
			foreach ($filepaths as $fp) {
				$detection = SiteGuard_Backup_Foreign_Importer::detect($fp);
				$detections[] = array(
					'file'     => basename($fp),
					'detected' => $detection !== false,
					'format'   => $detection ? $detection['format'] : null,
					'label'    => $detection ? $detection['label'] : null,
					'entities' => $detection ? $detection['entities'] : array(),
				);
			}
			return array('success' => true, 'detections' => $detections);
		}

		// Import
		if (count($filepaths) === 1) {
			return SiteGuard_Backup_Foreign_Importer::import($filepaths[0]);
		}

		return SiteGuard_Backup_Foreign_Importer::import_set($filepaths);
	}

	/**
	 * H6: Get database tables list for per-table selection.
	 */
	public function get_database_tables($params) {
		global $wpdb;

		$table_prefix = $wpdb->prefix;
		$tables = SiteGuard_Backup_Database_Utility::get_tables($table_prefix);
		if (empty($tables)) {
			return array('tables' => array());
		}

		$result = array();
		foreach ($tables as $table) {
			$is_wp = (strpos($table, $table_prefix) === 0);
			$result[] = array(
				'name'  => $table,
				'is_wp' => $is_wp,
			);
		}

		return array('tables' => $result);
	}

	/**
	 * Gap 37: Upload an existing local backup to remote storage services.
	 */
	public function upload_to_remote($params) {
		if (empty($params['timestamp'])) {
			return new WP_Error('missing_param', 'Missing timestamp');
		}
		if (empty($params['services']) || !is_array($params['services'])) {
			return new WP_Error('missing_param', 'Missing or invalid services array');
		}

		$timestamp = intval($params['timestamp']);
		$history = SiteGuard_Backup_History::get_history();
		if (!isset($history[$timestamp])) {
			return new WP_Error('not_found', 'Backup not found');
		}

		$backup_set = $history[$timestamp];
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		$all_files = SiteGuard_Backup_History::get_all_files($backup_set);
		if (empty($all_files)) {
			return new WP_Error('no_files', 'No files found in this backup set');
		}

		require_once SGBACKUP_DIR . 'methods/backup-module.php';

		$results = array();
		$services = array_map('sanitize_text_field', $params['services']);

		foreach ($services as $service) {
			if (!in_array($service, self::$valid_methods, true)) {
				$results[$service] = array('success' => false, 'error' => 'Invalid storage method');
				continue;
			}

			$method_file = SGBACKUP_DIR . 'methods/' . $service . '.php';
			if (!file_exists($method_file)) {
				$results[$service] = array('success' => false, 'error' => 'Storage method file not found');
				continue;
			}

			require_once $method_file;
			$class = 'SiteGuard_Backup_Method_' . ucfirst($service);
			if (!class_exists($class)) {
				$results[$service] = array('success' => false, 'error' => 'Storage method class not found');
				continue;
			}

			$method = new $class();
			$uploaded = 0;
			$errors = array();

			foreach ($all_files as $file) {
				$file_path = $backup_dir . $file;
				if (!file_exists($file_path)) {
					$errors[] = 'File not found: ' . $file;
					continue;
				}
				$upload_result = $method->backup($file_path);
				if (is_wp_error($upload_result)) {
					$errors[] = $file . ': ' . $upload_result->get_error_message();
				} else {
					$uploaded++;
				}
			}

			$results[$service] = array(
				'success'  => empty($errors),
				'uploaded' => $uploaded,
				'errors'   => $errors,
			);
		}

		return array('results' => $results);
	}

	/**
	 * Gap 38: Rescan local backup directory for files not in history.
	 */
	public function rescan_local($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$history = SiteGuard_Backup_History::get_history_raw();

		$known_files = array();
		foreach ($history as $ts => $backup) {
			$files = SiteGuard_Backup_History::get_all_files($backup);
			foreach ($files as $f) {
				$known_files[$f] = true;
			}
		}

		$patterns = array('backup_*.zip', 'backup_*.gz', 'backup_*.sql', 'backup_*.crypt');
		$new_count = 0;

		foreach ($patterns as $pattern) {
			$found = glob($backup_dir . $pattern);
			if (empty($found)) continue;

			foreach ($found as $file_path) {
				$filename = basename($file_path);
				if (isset($known_files[$filename])) continue;

				if (!preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_[^_]+_([a-zA-Z0-9]+)-([a-zA-Z]+)/', $filename, $m)) {
					continue;
				}

				$date_str = $m[1];
				$nonce = $m[2];
				$entity = $m[3];

				$timestamp = strtotime(
					substr($date_str, 0, 10) . ' ' .
					substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2)
				);
				if (!$timestamp) continue;

				$valid_entities_with_db = array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db');
				if (!in_array($entity, $valid_entities_with_db, true)) continue;

				if (isset($history[$timestamp])) {
					if (!isset($history[$timestamp][$entity])) {
						$history[$timestamp][$entity] = array();
					}
					if (is_string($history[$timestamp][$entity])) {
						$history[$timestamp][$entity] = array($history[$timestamp][$entity]);
					}
					if (!in_array($filename, $history[$timestamp][$entity], true)) {
						$history[$timestamp][$entity][] = $filename;
						$new_count++;
					}
				} else {
					$history[$timestamp] = array(
						'nonce'   => $nonce,
						$entity   => array($filename),
						'service' => array('none'),
					);
					$new_count++;
				}

				$known_files[$filename] = true;
			}
		}

		if ($new_count > 0) {
			SiteGuard_Backup_History::save_history($history);
		}

		return array('success' => true, 'new_files' => $new_count);
	}

	/**
	 * Gap 38: Rescan remote storage for backup files not in local history.
	 */
	public function rescan_remote($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$services = $opts->get_option('sgbackup_service', array('none'));
		if (!is_array($services)) $services = array($services);

		require_once SGBACKUP_DIR . 'methods/backup-module.php';

		$results = array();

		foreach ($services as $service) {
			if ($service === 'none') continue;
			if (!in_array($service, self::$valid_methods, true)) continue;

			$method_file = SGBACKUP_DIR . 'methods/' . $service . '.php';
			if (!file_exists($method_file)) continue;

			require_once $method_file;
			$class = 'SiteGuard_Backup_Method_' . ucfirst($service);
			if (!class_exists($class)) continue;

			$method = new $class();
			$remote_files = $method->listfiles();
			if (!is_array($remote_files) || empty($remote_files)) {
				$results[$service] = 0;
				continue;
			}

			$history = SiteGuard_Backup_History::get_history_raw();
			$known_files = array();
			foreach ($history as $ts => $backup) {
				$files = SiteGuard_Backup_History::get_all_files($backup);
				foreach ($files as $f) {
					$known_files[$f] = true;
				}
			}

			$new_count = 0;
			foreach ($remote_files as $remote_file) {
				$filename = is_array($remote_file) ? (isset($remote_file['name']) ? $remote_file['name'] : '') : $remote_file;
				if (empty($filename) || isset($known_files[$filename])) continue;

				if (!preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_[^_]+_([a-zA-Z0-9]+)-([a-zA-Z]+)/', $filename, $m)) {
					continue;
				}

				$date_str = $m[1];
				$nonce = $m[2];
				$entity = $m[3];

				$timestamp = strtotime(
					substr($date_str, 0, 10) . ' ' .
					substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2)
				);
				if (!$timestamp) continue;

				$valid_entities_with_db = array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db');
				if (!in_array($entity, $valid_entities_with_db, true)) continue;

				if (isset($history[$timestamp])) {
					if (!isset($history[$timestamp][$entity])) {
						$history[$timestamp][$entity] = array();
					}
					if (is_string($history[$timestamp][$entity])) {
						$history[$timestamp][$entity] = array($history[$timestamp][$entity]);
					}
					if (!in_array($filename, $history[$timestamp][$entity], true)) {
						$history[$timestamp][$entity][] = $filename;
						$new_count++;
					}
				} else {
					$history[$timestamp] = array(
						'nonce'   => $nonce,
						$entity   => array($filename),
						'service' => array($service),
					);
					$new_count++;
				}

				$known_files[$filename] = true;
			}

			if ($new_count > 0) {
				SiteGuard_Backup_History::save_history($history);
			}
			$results[$service] = $new_count;
		}

		return array('success' => true, 'results' => $results);
	}

	/**
	 * Gap 39: Calculate site size per entity.
	 */
	public function get_site_size($params) {
		$sizes = array();
		$wp_content = WP_CONTENT_DIR;

		$start_time = time();
		$timeout = 30;

		$dir_size = function($path) use (&$dir_size, $start_time, $timeout) {
			if (!is_dir($path) || !is_readable($path)) return 0;
			if ((time() - $start_time) > $timeout) return -1;

			$size = 0;
			$handle = @opendir($path);
			if (!$handle) return 0;

			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') continue;
				$full = $path . DIRECTORY_SEPARATOR . $entry;
				if (is_file($full)) {
					$size += @filesize($full);
				} elseif (is_dir($full)) {
					$sub = $dir_size($full);
					if ($sub === -1) { closedir($handle); return -1; }
					$size += $sub;
				}
			}
			closedir($handle);
			return $size;
		};

		$sizes['plugins'] = $dir_size(WP_PLUGIN_DIR);
		$sizes['themes'] = $dir_size(get_theme_root());
		$upload_dir = wp_upload_dir();
		$sizes['uploads'] = $dir_size($upload_dir['basedir']);

		$others_size = 0;
		$exclude_dirs = array(
			basename(WP_PLUGIN_DIR),
			basename(get_theme_root()),
			basename($upload_dir['basedir']),
		);
		$handle = @opendir($wp_content);
		if ($handle) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') continue;
				$full = $wp_content . DIRECTORY_SEPARATOR . $entry;
				if (in_array($entry, $exclude_dirs, true)) continue;
				if (is_file($full)) {
					$others_size += @filesize($full);
				} elseif (is_dir($full)) {
					$sub = $dir_size($full);
					if ($sub !== -1) $others_size += $sub;
				}
			}
			closedir($handle);
		}
		$sizes['others'] = $others_size;

		$core_size = 0;
		$wp_content_basename = basename($wp_content);
		$handle = @opendir(ABSPATH);
		if ($handle) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') continue;
				$full = ABSPATH . $entry;
				if ($entry === $wp_content_basename) continue;
				if (is_file($full)) {
					$core_size += @filesize($full);
				} elseif (is_dir($full)) {
					$sub = $dir_size($full);
					if ($sub !== -1) $core_size += $sub;
				}
			}
			closedir($handle);
		}
		$sizes['wpcore'] = $core_size;

		global $wpdb;
		$db_size = 0;
		$tables = $wpdb->get_results("SELECT table_name, data_length + index_length AS size FROM information_schema.tables WHERE table_schema = DATABASE()", ARRAY_A);
		if ($tables) {
			foreach ($tables as $t) {
				$db_size += intval($t['size']);
			}
		}
		$sizes['database'] = $db_size;

		$total = 0;
		foreach ($sizes as $s) {
			if ($s > 0) $total += $s;
		}
		$sizes['total'] = $total;

		return $sizes;
	}

	/**
	 * Gap 40: Get database table sizes.
	 */
	public function get_db_size($params) {
		global $wpdb;

		$tables = $wpdb->get_results(
			"SELECT table_name, data_length, index_length, table_rows
			 FROM information_schema.tables
			 WHERE table_schema = DATABASE()
			 ORDER BY (data_length + index_length) DESC",
			ARRAY_A
		);

		if (!$tables) {
			return new WP_Error('db_error', 'Could not query table sizes');
		}

		$prefix = $wpdb->prefix;
		$wp_tables = array();
		$non_wp_tables = array();
		$wp_total = 0;
		$non_wp_total = 0;

		foreach ($tables as $t) {
			$name = $t['table_name'];
			$size = intval($t['data_length']) + intval($t['index_length']);
			$entry = array(
				'name'  => $name,
				'size'  => $size,
				'rows'  => intval($t['table_rows']),
				'data'  => intval($t['data_length']),
				'index' => intval($t['index_length']),
			);

			if (strpos($name, $prefix) === 0) {
				$wp_tables[] = $entry;
				$wp_total += $size;
			} else {
				$non_wp_tables[] = $entry;
				$non_wp_total += $size;
			}
		}

		return array(
			'wp_tables'     => $wp_tables,
			'non_wp_tables' => $non_wp_tables,
			'wp_total'      => $wp_total,
			'non_wp_total'  => $non_wp_total,
			'total'         => $wp_total + $non_wp_total,
		);
	}

	/**
	 * Gap 41: Get WordPress cron events.
	 */
	public function get_cron_events($params) {
		$crons = _get_cron_array();
		if (empty($crons)) {
			return array('events' => array());
		}

		$filter = isset($params['filter']) ? sanitize_text_field($params['filter']) : '';
		$schedules = wp_get_schedules();
		$events = array();

		foreach ($crons as $timestamp => $cron_hooks) {
			foreach ($cron_hooks as $hook => $hook_events) {
				if ($filter === 'sgbackup' && strpos($hook, 'sgbackup') === false) {
					continue;
				}

				foreach ($hook_events as $hash => $event) {
					$schedule = isset($event['schedule']) ? $event['schedule'] : false;
					$interval = '';
					if ($schedule && isset($schedules[$schedule])) {
						$interval = $schedules[$schedule]['display'];
					} elseif ($schedule) {
						$interval = $schedule;
					} else {
						$interval = 'One-time';
					}

					$events[] = array(
						'hook'      => $hook,
						'next_run'  => $timestamp,
						'next_run_date' => gmdate('Y-m-d H:i:s', $timestamp),
						'schedule'  => $schedule ? $schedule : 'none',
						'interval'  => $interval,
						'args'      => isset($event['args']) ? $event['args'] : array(),
					);
				}
			}
		}

		usort($events, function($a, $b) {
			return $a['next_run'] - $b['next_run'];
		});

		return array('events' => $events);
	}

	/**
	 * Gap 42: Export all plugin settings as JSON.
	 */
	public function export_settings($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$defaults = $opts->get_defaults();
		$settings = array();

		foreach ($defaults as $key => $default) {
			$settings[$key] = $opts->get_option('sgbackup_' . $key, $default);
		}

		foreach (self::$valid_methods as $method) {
			$method_settings = $opts->get_option('sgbackup_' . $method, array());
			if (!empty($method_settings)) {
				$settings['method_' . $method] = $method_settings;
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('settings_exported', 'Plugin settings exported.');

		return array(
			'settings' => $settings,
			'exported' => gmdate('Y-m-d H:i:s'),
			'site_url' => get_site_url(),
			'version'  => SGBACKUP_VERSION,
		);
	}

	/**
	 * Gap 42: Import plugin settings from JSON data.
	 */
	public function import_settings($params) {
		if (empty($params['settings']) || !is_array($params['settings'])) {
			return new WP_Error('missing_param', 'Missing or invalid settings data');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$defaults = $opts->get_defaults();
		$imported = 0;

		require_once SGBACKUP_DIR . 'includes/class-security.php';
		$encrypt_creds = (bool) $opts->get_option('sgbackup_encrypt_credentials', false);

		foreach ($params['settings'] as $key => $value) {
			// Sanitize key to alphanumeric and underscore only
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) continue;

			if (isset($defaults[$key])) {
				if ($key === 'lock_admin_password') continue;
				// Type-check against default value
				if (gettype($value) !== gettype($defaults[$key])) continue;
				// Tighten the type check: an array-typed option previously imported unchecked.
				// Recursively enforce scalar/array leaves (drops objects/resources); leaves
				// string content intact so multi-line settings aren't mangled.
				if (is_array($value)) {
					$value = $this->sanitize_imported_array($value);
				} elseif (!is_scalar($value) && !is_null($value)) {
					continue;
				}
				$opts->update_option('sgbackup_' . $key, $value);
				$imported++;
			} elseif (strpos($key, 'method_') === 0) {
				$method = substr($key, 7);
				if (!in_array($method, self::$valid_methods, true) || !is_array($value)) continue;

				// Multi-instance format: array('version'=>N, 'settings'=>array(id=>fields)).
				if (isset($value['settings']) && is_array($value['settings'])) {
					$clean = array(
						'version'  => isset($value['version']) ? intval($value['version']) : 1,
						'settings' => array(),
					);
					foreach ($value['settings'] as $instance_id => $instance_settings) {
						if (!is_array($instance_settings)) continue;
						$instance_settings = $this->sanitize_imported_method_fields($instance_settings);
						if ($encrypt_creds) {
							$instance_settings = SiteGuard_Backup_Security::encrypt_method_options($method, $instance_settings);
						}
						$clean['settings'][sanitize_text_field($instance_id)] = $instance_settings;
					}
					$opts->update_option('sgbackup_' . $method, $clean);
					$imported++;
				} elseif ($this->is_flat_method_settings($value)) {
					// Legacy flat format: array(field=>scalar).
					$clean = $this->sanitize_imported_method_fields($value);
					if ($encrypt_creds) {
						$clean = SiteGuard_Backup_Security::encrypt_method_options($method, $clean);
					}
					$opts->update_option('sgbackup_' . $method, $clean);
					$imported++;
				} else {
					// Unexpected structure: skip rather than persist an attacker-shaped blob.
					error_log('SiteGuard Backup: skipped import of "' . $key . '" (unexpected method settings structure).');
				}
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('settings_imported', 'Imported ' . $imported . ' settings.');

		return array('success' => true, 'imported' => $imported);
	}

	/**
	 * Recursively enforce that an imported array contains only scalar/array leaves.
	 * Drops objects/resources; preserves string content (no mangling of multi-line values).
	 *
	 * @param array $arr Imported array.
	 * @return array Cleaned array.
	 */
	private function sanitize_imported_array($arr) {
		$clean = array();
		foreach ($arr as $k => $v) {
			if (is_string($k)) $k = sanitize_text_field($k);
			if (is_array($v)) {
				$clean[$k] = $this->sanitize_imported_array($v);
			} elseif (is_scalar($v) || is_null($v)) {
				$clean[$k] = $v;
			}
			// objects / resources dropped
		}
		return $clean;
	}

	/**
	 * Sanitize a storage-method options array on import, mirroring savesettings():
	 * multi-line credential fields keep newlines (tags/null bytes stripped), everything
	 * else goes through sanitize_text_field. Nested arrays are dropped (fields are scalar).
	 *
	 * @param array $fields Method field map.
	 * @return array Sanitized field map.
	 */
	private function sanitize_imported_method_fields($fields) {
		$multiline_fields = array('key', 'pass', 'secret', 'token', 'private_key', 'client_secret', 'access_token', 'refresh_token');
		$clean = array();
		foreach ($fields as $fkey => $val) {
			$fkey = sanitize_text_field($fkey);
			if (is_array($val)) continue; // method fields are scalar
			if (!is_string($val)) {
				$clean[$fkey] = (is_scalar($val) || is_null($val)) ? $val : '';
				continue;
			}
			if (in_array($fkey, $multiline_fields, true)) {
				$val = wp_check_invalid_utf8($val);
				$val = wp_strip_all_tags($val);
				$val = str_replace(chr(0), '', $val);
			} else {
				$val = sanitize_text_field($val);
			}
			$clean[$fkey] = $val;
		}
		return $clean;
	}

	/**
	 * True if the value looks like a flat method-settings map (field=>scalar), i.e. an
	 * associative array with no nested-array values. Used to reject unexpected structures.
	 *
	 * @param array $value Candidate value.
	 * @return bool
	 */
	private function is_flat_method_settings($value) {
		if (!is_array($value) || empty($value)) return false;
		foreach ($value as $v) {
			if (is_array($v)) return false;
		}
		return true;
	}

	/**
	 * Get audit log entries for the admin UI.
	 */
	public function get_audit_log($params) {
		$audit = SiteGuard_Backup_Audit_Log::instance();

		$filters = array();
		if (!empty($params['action'])) {
			$filters['action'] = sanitize_key($params['action']);
		}
		if (!empty($params['limit'])) {
			$filters['limit'] = intval($params['limit']);
		} else {
			$filters['limit'] = 50;
		}

		$entries = $audit->get_entries($filters);

		// Add human-readable labels
		foreach ($entries as &$entry) {
			$entry['action_label'] = SiteGuard_Backup_Audit_Log::get_action_label($entry['action']);
			$entry['date'] = gmdate('Y-m-d H:i:s', $entry['timestamp']);
		}

		return array(
			'entries' => $entries,
			'total'   => $audit->count(),
			'enabled' => $audit->is_enabled(),
		);
	}

	/**
	 * Toggle audit log enabled state.
	 */
	public function toggle_audit_log($params) {
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$enable = !empty($params['enabled']);

		if (!$enable && $audit->is_enabled()) {
			$audit->log('audit_log_disabled', 'Security audit logging has been disabled.');
		}

		$audit->set_enabled($enable);

		return array('enabled' => $audit->is_enabled());
	}

	/**
	 * Clear audit log.
	 */
	public function clear_audit_log($params) {
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->clear();

		return array('success' => true);
	}

	/**
	 * Send a test webhook.
	 */
	public function test_webhook($params) {
		$url = '';
		if (!empty($params['url'])) {
			$url = trim($params['url']);
		}
		if (empty($url)) {
			$opts = SiteGuard_Backup_Options::instance();
			$url = $opts->get_option('sgbackup_webhook_url', '');
		}

		if (empty($url)) {
			return new WP_Error('missing_url', 'No webhook URL provided.');
		}

		return SiteGuard_Backup_Webhook::send_test($url);
	}

	// ===== Phase 4: Failover Admin AJAX Commands =====

	/**
	 * Get failover environment status (read-only).
	 *
	 * @return array Environment info, backup freshness, role status.
	 */
	public function failover_status($params = array()) {
		$opts = SiteGuard_Backup_Options::instance();

		$env = array(
			'is_docker'       => file_exists('/.dockerenv'),
			'is_azure'        => !empty(getenv('WEBSITE_INSTANCE_ID')),
			'is_multisite'    => is_multisite(),
			'has_cf_scripts'  => file_exists(dirname(ABSPATH) . '/scripts/cf-status.sh') || file_exists('/opt/docker/wordpressmultisite/scripts/cf-status.sh'),
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo('version'),
		);

		$role = $opts->get_option('sgbackup_failover_role', 'standalone');
		$production_url = $opts->get_option('sgbackup_failover_production_url', '');

		// Backup freshness
		$latest = SiteGuard_Backup_History::get_latest();
		$backup_info = array(
			'has_backup'    => !empty($latest),
			'last_backup'   => $latest ? gmdate('Y-m-d H:i:s', $latest['timestamp']) : null,
			'age_seconds'   => $latest ? (time() - $latest['timestamp']) : null,
			'age_human'     => $latest ? human_time_diff($latest['timestamp']) . ' ago' : 'Never',
		);

		// DNS check
		$dns_info = array();
		if (!empty($production_url)) {
			$host = wp_parse_url($production_url, PHP_URL_HOST);
			if ($host) {
				$dns_info['host'] = $host;
				$dns_info['a_records'] = array();
				$dns_info['cname_records'] = array();
				$ips = @dns_get_record($host, DNS_A);
				if (is_array($ips)) {
					foreach ($ips as $record) {
						if (isset($record['ip'])) $dns_info['a_records'][] = $record['ip'];
					}
				}
				$cnames = @dns_get_record($host, DNS_CNAME);
				if (is_array($cnames)) {
					foreach ($cnames as $record) {
						if (isset($record['target'])) $dns_info['cname_records'][] = $record['target'];
					}
				}
			}
		}

		// Database & disk
		global $wpdb;
		$db_ok = (bool) $wpdb->get_var('SELECT 1');
		$disk = SiteGuard_Backup_Filesystem::check_disk_space($opts->get_backup_dir());

		return array(
			'environment'    => $env,
			'role'           => $role,
			'production_url' => $production_url,
			'backup'         => $backup_info,
			'dns'            => $dns_info,
			'db_ok'          => $db_ok,
			'disk'           => $disk,
		);
	}

	/**
	 * Run a pre-failover backup (synchronous DB-only backup).
	 *
	 * @return array|WP_Error Result with backup file info.
	 */
	public function failover_prefailover($params = array()) {
		$sgbackup = SiteGuard_Backup::instance();

		$options = array(
			'nodb'     => false,
			'nofiles'  => true,
			'entities' => array(),
			'service'  => array('none'),
			'label'    => 'Pre-failover backup',
		);

		$sgbackup->backup_all($options);
		$nonce = $sgbackup->nonce;

		$max_resumptions = 20;
		for ($i = 0; $i < $max_resumptions; $i++) {
			$jobdata = $sgbackup->jobdata_getarray($nonce);
			$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
			if (in_array($status, array('complete', 'finished', 'warning', 'error', 'aborted', 'failed'), true)) break;
			$sgbackup->backup_resume($i, $nonce);
			$jobdata = $sgbackup->jobdata_getarray($nonce);
			$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
			if (in_array($status, array('complete', 'finished', 'warning', 'error', 'aborted', 'failed'), true)) break;
		}

		$jobdata = $sgbackup->jobdata_getarray($nonce);
		$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';

		// The real terminal success states set by backup_finish are complete/warning
		// ('finished' kept for backward compat); a 'warning' backup still succeeded.
		if ($status === 'complete' || $status === 'finished' || $status === 'warning') {
			return array(
				'success'  => true,
				'nonce'    => $nonce,
				'db_files' => isset($jobdata['db_backup_files']) ? $jobdata['db_backup_files'] : array(),
				'message'  => 'Pre-failover DB backup completed.',
			);
		}

		return new WP_Error('backup_failed', 'Pre-failover backup did not complete (status: ' . $status . ')');
	}

	/**
	 * Run post-failover cleanup (cache flush, validation).
	 *
	 * @return array Result with validation checks.
	 */
	public function failover_postfailover($params = array()) {
		$results = array();

		// Flush object cache
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
			$results['object_cache'] = 'flushed';
		}

		// Flush transients
		global $wpdb;
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%\_transient\_%'));
		if (is_multisite()) {
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", '%\_transient\_%'));
		}
		$results['transients'] = 'cleared';

		// Flush Redis if available
		if (class_exists('Redis')) {
			$redis_host = defined('WP_REDIS_HOST') ? WP_REDIS_HOST : 'redis';
			$redis_port = defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379;
			try {
				$redis = new Redis();
				if ($redis->connect($redis_host, $redis_port, 2)) {
					$redis->flushAll();
					$results['redis'] = 'flushed';
				}
			} catch (Exception $e) {
				$results['redis'] = 'not_available';
			}
		}

		// Flush rewrite rules
		flush_rewrite_rules();
		$results['rewrite_rules'] = 'flushed';

		// Verify DB
		$db_ok = (bool) $wpdb->get_var('SELECT 1');
		$results['db_connectivity'] = $db_ok ? 'ok' : 'failed';

		// Check theme
		$theme = wp_get_theme();
		$results['theme'] = array('name' => $theme->get('Name'), 'exists' => $theme->exists());

		// Check plugins
		$active_plugins = get_option('active_plugins', array());
		$missing_plugins = array();
		foreach ($active_plugins as $plugin) {
			if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
				$missing_plugins[] = $plugin;
			}
		}
		$results['plugins'] = array('active_count' => count($active_plugins), 'missing' => $missing_plugins);

		// Multisite
		if (is_multisite()) {
			$results['multisite'] = array('enabled' => true, 'site_count' => get_sites(array('count' => true)));
		} else {
			$results['multisite'] = array('enabled' => false);
		}

		// Search-replace if requested
		if (!empty($params['search_replace'])) {
			$sr_parts = explode('|', $params['search_replace'], 2);
			if (count($sr_parts) === 2 && !empty($sr_parts[0]) && !empty($sr_parts[1])) {
				require_once SGBACKUP_DIR . 'includes/class-search-replace.php';
				require_once SGBACKUP_DIR . 'includes/class-database-utility.php';
				$sr = new SiteGuard_Backup_Search_Replace();
				// run() signature is run($search, $replace, $tables, $dry_run=false). The 3rd
				// arg is the table list, NOT $dry_run: passing `false` made it a silent no-op.
				$sr_tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);
				$results['search_replace'] = $sr->run($sr_parts[0], $sr_parts[1], $sr_tables, false);
			}
		}

		return array('success' => true, 'results' => $results, 'message' => 'Post-failover cleanup completed.');
	}

	// ==========================================
	// RESTORE PRESETS
	// ==========================================

	/**
	 * Save a restore preset.
	 */
	public function save_restore_preset($params) {
		if (empty($params['name'])) {
			return new WP_Error('missing_param', 'Preset name is required.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$presets = $opts->get_option('sgbackup_restore_presets', array());

		$name = sanitize_text_field(substr($params['name'], 0, 80));

		// Build restore_options from params
		$restore_options = array();
		foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $entity) {
			$restore_options[$entity] = !empty($params[$entity]);
		}
		$restore_options['move_in_mode'] = isset($params['move_in_mode']) ? min(3, max(0, intval($params['move_in_mode']))) : 0;
		$restore_options['db_restore_mode'] = isset($params['db_restore_mode']) ? min(3, max(0, intval($params['db_restore_mode']))) : 0;
		$restore_options['subsite_mode'] = isset($params['subsite_mode']) ? min(5, max(0, intval($params['subsite_mode']))) : 0;
		if (!empty($params['subsite_map']) && is_array($params['subsite_map'])) {
			$restore_options['subsite_map'] = $params['subsite_map'];
		}
		if (!empty($params['batch_subsite_map']) && is_array($params['batch_subsite_map'])) {
			$restore_options['batch_subsite_map'] = $params['batch_subsite_map'];
		}
		$restore_options['pre_restore_backup'] = !empty($params['pre_restore_backup']);
		$restore_options['include_unspecified_tables'] = !empty($params['include_unspecified_tables']);
		$restore_options['include_unspecified_plugins'] = !empty($params['include_unspecified_plugins']);
		$restore_options['include_unspecified_themes'] = !empty($params['include_unspecified_themes']);

		// Auto-increment ID
		if (!empty($params['preset_id']) && isset($presets[intval($params['preset_id'])])) {
			$preset_id = intval($params['preset_id']);
		} else {
			$preset_id = empty($presets) ? 0 : max(array_keys($presets)) + 1;
		}

		$presets[$preset_id] = array(
			'name'            => $name,
			'created'         => time(),
			'restore_options' => $restore_options,
		);

		$opts->update_option('sgbackup_restore_presets', $presets);

		return array('success' => true, 'preset_id' => $preset_id, 'presets' => $presets);
	}

	/**
	 * Get all restore presets.
	 */
	public function get_restore_presets() {
		$opts = SiteGuard_Backup_Options::instance();
		return $opts->get_option('sgbackup_restore_presets', array());
	}

	/**
	 * Delete a restore preset.
	 */
	public function delete_restore_preset($params) {
		if (!isset($params['preset_id'])) {
			return new WP_Error('missing_param', 'Missing preset_id.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$presets = $opts->get_option('sgbackup_restore_presets', array());
		$preset_id = intval($params['preset_id']);

		if (!isset($presets[$preset_id])) {
			return new WP_Error('not_found', 'Preset not found.');
		}

		unset($presets[$preset_id]);
		$opts->update_option('sgbackup_restore_presets', $presets);

		return array('success' => true, 'presets' => $presets);
	}

	// ==========================================
	// SCHEDULED REMOTE SEND
	// ==========================================

	/**
	 * Save scheduled remote send settings.
	 */
	public function save_remotesend_schedule($params) {
		$opts = SiteGuard_Backup_Options::instance();
		$sgbackup = SiteGuard_Backup::instance();

		$valid_intervals = array('manual', 'everyhour', 'every2hours', 'every4hours', 'every8hours', 'twicedaily', 'daily', 'weekly', 'fortnightly', 'monthly');
		$interval = isset($params['interval']) ? sanitize_text_field($params['interval']) : 'manual';
		if (!in_array($interval, $valid_intervals, true)) {
			$interval = 'manual';
		}

		// Validate destinations
		$destinations = array();
		if (!empty($params['destinations']) && is_array($params['destinations'])) {
			$sites = $opts->get_option('sgbackup_remotesites', array());
			foreach ($params['destinations'] as $id) {
				$id = intval($id);
				if (isset($sites[$id])) {
					$destinations[] = $id;
				}
			}
		}

		// Validate entities
		$entities = array();
		if (!empty($params['entities']) && is_array($params['entities'])) {
			$entities = array_values(array_intersect(
				array_map('sanitize_text_field', $params['entities']),
				self::$valid_entities
			));
		}

		$retain = isset($params['retain']) ? max(1, intval($params['retain'])) : 5;
		$schedule_day = isset($params['schedule_day']) ? sanitize_text_field($params['schedule_day']) : '';

		$opts->update_option('sgbackup_remotesend_schedule', $interval);
		$opts->update_option('sgbackup_remotesend_destinations', $destinations);
		$opts->update_option('sgbackup_remotesend_entities', $entities);
		$opts->update_option('sgbackup_remotesend_retain', $retain);
		$opts->update_option('sgbackup_remotesend_schedule_day', $schedule_day);

		$sgbackup->schedule_backup_remotesend($interval);

		return array('success' => true, 'interval' => $interval, 'destinations' => $destinations);
	}

	/**
	 * Get scheduled remote send settings.
	 */
	public function get_remotesend_schedule() {
		$opts = SiteGuard_Backup_Options::instance();
		return array(
			'interval'     => $opts->get_option('sgbackup_remotesend_schedule', 'manual'),
			'destinations' => $opts->get_option('sgbackup_remotesend_destinations', array()),
			'entities'     => $opts->get_option('sgbackup_remotesend_entities', array()),
			'retain'       => $opts->get_option('sgbackup_remotesend_retain', 5),
			'schedule_day' => $opts->get_option('sgbackup_remotesend_schedule_day', ''),
			'next'         => wp_next_scheduled('sgbackup_backup_remotesend'),
		);
	}

	/**
	 * Receive a chunked file upload piece.
	 * Adapted from class-remote-send.php::cmd_send_chunk() - same DB-backed
	 * locking, sequential offset validation, .tmp staging, CIFS-safe ops.
	 */
	public function upload_chunk($params) {
		if (empty($params['filename']) || !isset($params['offset']) || !isset($params['total_size'])) {
			return new WP_Error('missing_params', 'Missing required parameters.');
		}

		// Read binary chunk from uploaded file
		if (empty($_FILES['chunk_data']) || empty($_FILES['chunk_data']['tmp_name'])) {
			return new WP_Error('no_chunk', 'No chunk data received.');
		}

		$chunk_data = file_get_contents($_FILES['chunk_data']['tmp_name']);
		if (false === $chunk_data || strlen($chunk_data) === 0) {
			return new WP_Error('read_failed', 'Failed to read chunk data.');
		}

		// Cap chunk size server-side
		$max_chunk = min(2 * 1024 * 1024, intval(0.9 * wp_max_upload_size()));
		if (strlen($chunk_data) > $max_chunk) {
			return new WP_Error('chunk_too_large', 'Chunk exceeds maximum size.');
		}

		$filename = sanitize_file_name(basename($params['filename']));
		$offset = intval($params['offset']);
		$total_size = intval($params['total_size']);
		$is_last = !empty($params['is_last']);

		// Validate filename
		if (strlen($filename) > 255) {
			return new WP_Error('filename_too_long', 'Filename exceeds maximum length.');
		}
		if (strpos($filename, '..') !== false) {
			return new WP_Error('invalid_filename', 'Invalid filename.');
		}
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, array('zip', 'gz', 'sql', 'crypt'), true)) {
			return new WP_Error('invalid_extension', 'Only .zip, .gz, .sql, .crypt files are allowed.');
		}
		if ($offset < 0) {
			return new WP_Error('invalid_offset', 'Offset must be non-negative.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		// Atomic database-backed lock (CIFS-safe, same pattern as cmd_send_chunk).
		// Release sets timestamp to 0 (expired) rather than deleting the row,
		// to avoid poisoning WordPress's 'notoptions' object cache.
		global $wpdb;
		$chunk_lock_key = 'sgbackup_chunk_' . md5($filename);
		$lock_value = wp_generate_password(12, false);
		$now = time();
		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, %s, 'no')
			 ON DUPLICATE KEY UPDATE option_value = IF(
			   CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d,
			   VALUES(option_value), option_value
			 )",
			$chunk_lock_key, $lock_value . '|' . $now, $now - 30
		));
		wp_cache_delete($chunk_lock_key, 'options');
		$notoptions = wp_cache_get('notoptions', 'options');
		if (is_array($notoptions) && isset($notoptions[$chunk_lock_key])) {
			unset($notoptions[$chunk_lock_key]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}
		$check = get_option($chunk_lock_key);
		$check_val = is_string($check) ? substr($check, 0, strrpos($check, '|')) : '';
		if ($check_val !== $lock_value) {
			return new WP_Error('file_lock', 'Cannot acquire chunk write lock. Another upload may be in progress.');
		}

		$fh = fopen($tmp_file, 'c+b');
		if (!$fh) {
			$this->release_chunk_lock($chunk_lock_key);
			return new WP_Error('file_open', 'Cannot open temp file for writing.');
		}

		// Sequential offset validation
		clearstatcache(true, $tmp_file);
		$expected_offset = filesize($tmp_file);
		if ($expected_offset === false) $expected_offset = 0;
		if ($offset !== $expected_offset) {
			fclose($fh);
			$this->release_chunk_lock($chunk_lock_key);
			return new WP_Error('out_of_sequence', sprintf('Out-of-sequence: expected offset %d but got %d.', $expected_offset, $offset), array('expected' => $expected_offset));
		}

		fseek($fh, $offset);
		$written = fwrite($fh, $chunk_data);
		fclose($fh);
		$this->release_chunk_lock($chunk_lock_key);

		if (false === $written) {
			return new WP_Error('file_write', 'Failed to write chunk data.');
		}

		clearstatcache(true, $tmp_file);
		$current_size = filesize($tmp_file);
		$result = array(
			'received' => strlen($chunk_data),
			'size'     => $current_size,
			'total'    => $total_size,
			'status'   => 0,
			'filename' => $filename,
		);

		// On last chunk, finalize the file
		if ($is_last) {
			// Validate total size
			if (abs($current_size - $total_size) > 1024) {
				@unlink($tmp_file);
				return new WP_Error('size_mismatch', sprintf('Size mismatch: received %d, expected %d.', $current_size, $total_size));
			}

			if (file_exists($final_file)) {
				@unlink($final_file);
			}
			// Rename with copy+unlink fallback for CIFS
			if (!@rename($tmp_file, $final_file)) {
				if (!@copy($tmp_file, $final_file) || !@unlink($tmp_file)) {
					return new WP_Error('rename_failed', 'Failed to finalize uploaded file.');
				}
			}

			$result['status'] = 1;
			clearstatcache(true, $final_file);
			$result['size'] = filesize($final_file);

			// Handle bundle zips - send response BEFORE extraction so the
			// client doesn't time out waiting (large bundles take minutes).
			if (strpos($filename, 'sgbackup_bundle_') === 0 && $ext === 'zip') {
				$result['bundle'] = true;
				$result['message'] = 'Bundle uploaded - extracting backup files...';

				// Send success response immediately, then extract in background
				$sgbackup = SiteGuard_Backup::instance();
				$sgbackup->close_browser_connection(json_encode(array('success' => true, 'data' => $result)));

				$admin = SiteGuard_Backup_Admin::instance();
				$extracted = $admin->extract_bundle($final_file, $backup_dir);
				@unlink($final_file);
				if (!is_wp_error($extracted)) {
					$audit = SiteGuard_Backup_Audit_Log::instance();
					$audit->log('upload_bundle', 'Chunked upload and extracted bundle: ' . $filename, array(
						'files' => count($extracted),
						'size' => $total_size,
					));
				}
				die; // Response already sent
			} else {
				// Register individual backup files in history
				if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_[^_]+_([a-zA-Z0-9]+)/', $filename, $m)) {
					$timestamp = strtotime(str_replace('-', '', substr($m[1], 0, 10)) . ' ' . substr($m[1], 11, 2) . ':' . substr($m[1], 13, 2));
					$nonce = $m[2];

					$entity = 'unknown';
					foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
						if (strpos($filename, "-{$e}") !== false) {
							$entity = $e;
							break;
						}
					}

					$backup_data = array(
						'nonce' => $nonce,
						$entity => array($filename),
						'service' => array('none'),
						'size' => $result['size'],
					);
					SiteGuard_Backup_History::add_backup($timestamp, $backup_data);
				}

				$audit = SiteGuard_Backup_Audit_Log::instance();
				$audit->log('upload_backup', 'Chunked upload complete: ' . $filename, array(
					'filename' => $filename,
					'size' => $result['size'],
				));
			}
		}

		return $result;
	}

	/**
	 * Check status of a chunked upload (complete, partial, or not started).
	 */
	public function upload_chunk_status($params) {
		if (empty($params['filename'])) {
			return new WP_Error('missing_params', 'Missing filename.');
		}

		$filename = sanitize_file_name(basename($params['filename']));
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, array('zip', 'gz', 'sql', 'crypt'), true)) {
			return new WP_Error('invalid_extension', 'Invalid file type.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$tmp_file = $backup_dir . $filename . '.tmp';
		$final_file = $backup_dir . $filename;

		clearstatcache(true, $final_file);
		if (file_exists($final_file) && !is_dir($final_file)) {
			return array('size' => filesize($final_file), 'status' => 1);
		}

		clearstatcache(true, $tmp_file);
		if (file_exists($tmp_file) && !is_dir($tmp_file)) {
			return array('size' => filesize($tmp_file), 'status' => 0);
		}

		return array('size' => 0, 'status' => -1);
	}

	/**
	 * Cancel a chunked upload - delete .tmp file and lock option.
	 */
	public function upload_chunk_cancel($params) {
		if (empty($params['filename'])) {
			return new WP_Error('missing_params', 'Missing filename.');
		}

		$filename = sanitize_file_name(basename($params['filename']));
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if (!in_array($ext, array('zip', 'gz', 'sql', 'crypt'), true)) {
			return new WP_Error('invalid_extension', 'Invalid file type.');
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$tmp_file = $backup_dir . $filename . '.tmp';

		clearstatcache(true, $tmp_file);
		if (file_exists($tmp_file)) {
			@unlink($tmp_file);
		}

		// Clean up lock option
		$this->release_chunk_lock('sgbackup_chunk_' . md5($filename));

		return array('cancelled' => true);
	}

	/**
	 * Release a chunk write lock by setting its timestamp to 0 (expired).
	 * Uses update_option instead of delete_option to avoid poisoning
	 * WordPress's 'notoptions' object cache (important with persistent
	 * object caches like Redis and within same-process calls).
	 */
	private function release_chunk_lock($lock_key) {
		global $wpdb;
		$wpdb->update(
			$wpdb->options,
			array('option_value' => 'released|0'),
			array('option_name' => $lock_key)
		);
		wp_cache_delete($lock_key, 'options');
	}

	/**
	 * Run health checks now and return results.
	 */
	public function health_check_now($params) {
		require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
		$monitor = SiteGuard_Backup_Health_Monitor::instance();
		return $monitor->run_checks();
	}

	/**
	 * Get cached health status. API key is never returned here (hashed at rest).
	 */
	public function get_health_status($params) {
		require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
		$monitor = SiteGuard_Backup_Health_Monitor::instance();
		return array(
			'overall'     => $monitor->get_overall_status(),
			'sites'       => $monitor->get_results(),
			'has_api_key' => $monitor->has_api_key(),
		);
	}

	/**
	 * Regenerate the health API key. Returns the new plaintext key ONCE.
	 * After this response, the key is only stored as a hash and cannot be retrieved.
	 */
	public function regenerate_health_api_key($params) {
		require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
		return array('api_key' => SiteGuard_Backup_Health_Monitor::instance()->regenerate_api_key());
	}
}
