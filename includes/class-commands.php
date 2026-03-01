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
			$svc = (array) $params['service'];
			$svc = array_values(array_filter($svc, function($s) {
				return in_array($s, array_merge(self::$valid_methods, array('none')), true);
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

		$sgbackup->backup_all($options);

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$entities = isset($options['entities']) ? implode(', ', $options['entities']) : 'all';
		$audit->log('backup_started', 'Backup initiated: entities=' . $entities, array(
			'nonce' => $sgbackup->nonce,
			'type' => !empty($options['job_type']) ? $options['job_type'] : 'full',
		));

		return array('nonce' => $sgbackup->nonce);
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
			$result[$nonce] = array(
				'resumption'  => $job_info['resumption'],
				'next'        => $job_info['next_scheduled'],
				'jobstatus'   => isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : 'unknown',
				'backup_files' => isset($jobdata['backup_files']) ? $jobdata['backup_files'] : 'no',
				'backup_database' => isset($jobdata['backup_database']) ? $jobdata['backup_database'] : 'no',
				'files_phase_complete' => !empty($jobdata['files_phase_complete']),
				'db_phase_complete' => !empty($jobdata['db_phase_complete']),
				'upload_phase_complete' => !empty($jobdata['upload_phase_complete']),
				'job_type'    => isset($jobdata['job_type']) ? $jobdata['job_type'] : 'backup',
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

		return array('success' => true, 'nonce' => $nonce);
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

		$history = SiteGuard_Backup_History::get_history();
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
			'extra_dirs', 'max_incrementals_before_full',
			'ssl_disableverify', 'ssl_useservercerts',
			'auto_update',
			'data_optional_tables',
			'zip_nocompress',
		);

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

			if (isset($params[$key])) {
				$value = $params[$key];
				// Sanitize
				if (in_array($key, array('retain', 'retain_db', 'split_every', 'max_split', 'delete_local', 'debug_mode', 'encryption_enabled', 'syslog_enabled', 'lock_admin', 'lock_admin_session_length', 'backupdb_nonwp', 'db_anonymise', 'max_incrementals_before_full', 'ssl_disableverify', 'ssl_useservercerts', 'auto_update'))) {
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
						$stored['settings'][$instance_id] = $instance_settings;
					}
					$opts->update_option('sgbackup_' . $method, $stored);
				} else {
					// Legacy flat format
					array_walk($method_data, $sanitize_method_value);
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
					'user'   => isset($extdb['user']) ? sanitize_text_field($extdb['user']) : '',
					'pass'   => isset($extdb['pass']) ? sanitize_text_field($extdb['pass']) : '',
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
		$pass = isset($params['pass']) ? sanitize_text_field($params['pass']) : '';
		$name = sanitize_text_field($params['name']);

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
		return $opts->get_all_settings();
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
		$site_id = count($sites);
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

		$backup_set = SiteGuard_Backup_History::get_history($timestamp);
		if (empty($backup_set)) {
			return new WP_Error('not_found', 'Backup not found');
		}

		// Start the send as a backup job (use remotesend method)
		// Pre-set all phase-complete flags and file arrays so they're included
		// in the initial jobdata BEFORE the cron resumption is scheduled.
		$backup_files = SiteGuard_Backup_History::get_all_files($backup_set);
		$options = array(
			'service'                => array("remotesend/{$site_id}"),
			'nofiles'                => true,
			'nodb'                   => true,
			'initial_jobdata_extra'  => array(
				'backup_files_array'       => $backup_files,
				'db_backup_files'          => array(),
				'files_phase_complete'     => true,
				'db_phase_complete'        => true,
				'encryption_phase_complete' => true,
				'manifest_phase_complete'  => true,
			),
		);

		$sgbackup->backup_all($options);

		return array('success' => true, 'nonce' => $sgbackup->nonce);
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
		$options = array(
			'service' => array("remotesend/{$site_id}"),
			'label'   => 'Migration clone ' . gmdate('Y-m-d H:i'),
		);
		$sgbackup->backup_all($options);

		return array('success' => true, 'nonce' => $sgbackup->nonce);
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
		$opts = SiteGuard_Backup_Options::instance();
		$defaults = $opts->get_defaults();

		foreach ($defaults as $key => $default) {
			$opts->delete_option('sgbackup_' . $key);
		}

		// Audit log (log before wiping since the log setting itself will be wiped)
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('settings_wiped', 'All plugin settings reset to defaults.');

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
		if (!wp_verify_nonce($state, 'sgbackup-gdrive-auth')) {
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
		if (!wp_verify_nonce($state, 'sgbackup-dropbox-auth')) {
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
		if (!wp_verify_nonce($state, 'sgbackup-onedrive-auth')) {
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
				while (!($is_gz ? gzeof($fh) : feof($fh)) && $lines < 50000) {
					$line = $is_gz ? gzgets($fh) : fgets($fh);
					if (false === $line) break;
					$lines++;

					if (preg_match('/^CREATE TABLE\s+`([^`]+)`/i', trim($line), $m)) {
						$result['tables'][] = $m[1];
					}

					// Gap 24: Detect old siteurl from the SQL for migration detection
					if (empty($result['old_siteurl']) && preg_match("/INSERT INTO\s+`[^`]*options`.*'siteurl'\s*,\s*'([^']+)'/i", $line, $url_m)) {
						$result['old_siteurl'] = $url_m[1];
					}
				}
				$is_gz ? @gzclose($fh) : @fclose($fh);
			}
		}

		// M8: Add multisite blog grouping for tables
		if (is_multisite() && !empty($result['tables'])) {
			global $wpdb;
			$result['table_groups'] = $this->group_tables_by_blog($result['tables'], $wpdb->prefix);
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
	 * Accepts SQL dumps (.sql, .gz) and ZIP files. ZIP contents are inspected
	 * to determine whether the archive is a database or file backup.
	 *
	 * @param array $params Must contain 'file' key with the filename in the backup directory.
	 * @return array|WP_Error Result with success status and timestamp.
	 */
	public function import_foreign_backup($params) {
		if (empty($params['file'])) {
			return new WP_Error('missing_param', 'Missing file parameter');
		}

		$file = sanitize_file_name($params['file']);
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$path = $backup_dir . $file;

		if (!file_exists($path)) {
			return new WP_Error('not_found', 'File not found in backup directory');
		}

		// Verify the file is in the backup directory (prevent directory traversal)
		$real_path = realpath($path);
		$real_dir = realpath($backup_dir);
		if ($real_path === false || strpos($real_path, $real_dir) !== 0) {
			return new WP_Error('invalid_path', 'Invalid file path');
		}

		$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
		$timestamp = time();
		$nonce = substr(wp_generate_password(12, false), 0, 12);

		// Build backup data
		$backup_data = array(
			'nonce'    => $nonce,
			'service'  => array('none'),
			'imported' => true,
		);

		$new_name = '';

		if (in_array($ext, array('sql', 'gz'), true)) {
			// SQL dump - register as DB backup
			$new_name = 'backup_' . gmdate('Y-m-d-Hi') . '_imported_' . $nonce . '-db.' . $ext;
			if (!rename($path, $backup_dir . $new_name)) {
				return new WP_Error('rename_failed', 'Failed to rename file');
			}
			$backup_data['db'] = array($new_name);
		} elseif ($ext === 'zip') {
			// ZIP - try to detect contents (check for SQL files inside = db, otherwise = files)
			$entity = 'others';
			if (class_exists('ZipArchive')) {
				$zip = new ZipArchive();
				if ($zip->open($path) === true) {
					$has_sql = false;
					for ($i = 0; $i < $zip->numFiles; $i++) {
						if (preg_match('/\.sql(\.gz)?$/', $zip->getNameIndex($i))) {
							$has_sql = true;
							break;
						}
					}
					$zip->close();
					if ($has_sql) {
						$entity = 'db';
					}
				}
			}
			$new_name = 'backup_' . gmdate('Y-m-d-Hi') . '_imported_' . $nonce . '-' . $entity . '.zip';
			if (!rename($path, $backup_dir . $new_name)) {
				return new WP_Error('rename_failed', 'Failed to rename file');
			}
			$backup_data[$entity] = array($new_name);
		} else {
			return new WP_Error('unsupported_type', 'Unsupported file type: ' . $ext);
		}

		$backup_data['size'] = filesize($backup_dir . $new_name);
		SiteGuard_Backup_History::add_backup($timestamp, $backup_data);

		return array(
			'success'   => true,
			'timestamp' => $timestamp,
			'filename'  => $new_name,
		);
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
		$history = SiteGuard_Backup_History::get_history();

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

			$history = SiteGuard_Backup_History::get_history();
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

		foreach ($params['settings'] as $key => $value) {
			if (isset($defaults[$key])) {
				if ($key === 'lock_admin_password') continue;
				$opts->update_option('sgbackup_' . $key, $value);
				$imported++;
			}
			if (strpos($key, 'method_') === 0) {
				$method = substr($key, 7);
				if (in_array($method, self::$valid_methods, true) && is_array($value)) {
					$opts->update_option('sgbackup_' . $method, $value);
					$imported++;
				}
			}
		}

		// Audit log
		$audit = SiteGuard_Backup_Audit_Log::instance();
		$audit->log('settings_imported', 'Imported ' . $imported . ' settings.');

		return array('success' => true, 'imported' => $imported);
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
}
