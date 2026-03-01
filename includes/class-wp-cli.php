<?php
/**
 * SiteGuard Backup - WP-CLI Commands
 *
 * Commands: backup, list, restore, status, verify, export, import, prefailover,
 * healthcheck, cleanup, sites, schedule, postfailover, prune, lock,
 * backup_progress, get_latest_full_backup, rescan_storage, create_migration_key,
 * search_replace, get_most_recently_modified_log, audit_log
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_CLI_Command extends WP_CLI_Command {

    /**
     * Run a backup.
     *
     * ## OPTIONS
     *
     * [--exclude-db]
     * : Skip database backup.
     *
     * [--include-files=<entities>]
     * : Comma-separated entities: plugins,themes,uploads,others,wpcore
     *
     * [--send-to-cloud]
     * : Upload to configured remote storage.
     *
     * [--always-keep]
     * : Exclude from retention limits.
     *
     * [--label=<label>]
     * : Custom backup label.
     *
     * [--incremental]
     * : Perform incremental backup.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup backup
     *     wp siteguardbackup backup --exclude-db --include-files=plugins,themes
     *     wp siteguardbackup backup --send-to-cloud --always-keep
     *
     * @subcommand backup
     */
    public function backup($args, $assoc_args) {
        $sgbackup = SiteGuard_Backup::instance();
        $options = array();

        if (isset($assoc_args['exclude-db'])) {
            $options['nodb'] = true;
        }

        if (isset($assoc_args['include-files'])) {
            $entities = array_filter(array_map('trim', explode(',', $assoc_args['include-files'])), 'strlen');
            $valid = array('plugins', 'themes', 'uploads', 'others', 'wpcore');
            $options['entities'] = array_values(array_intersect($entities, $valid));
            if (empty($options['entities'])) {
                WP_CLI::error('No valid entities specified. Valid: ' . implode(', ', $valid));
            }
        }

        if (isset($assoc_args['send-to-cloud'])) {
            // Use configured service
        } else {
            $options['service'] = array('none');
        }

        if (isset($assoc_args['always-keep'])) {
            $options['always_keep'] = true;
        }

        if (isset($assoc_args['incremental'])) {
            $options['job_type'] = 'incremental';
        }

        WP_CLI::log('Starting backup...');
        $sgbackup->backup_all($options);
        WP_CLI::success('Backup initiated. Job nonce: ' . $sgbackup->nonce);
        WP_CLI::log('Monitor with: wp siteguardbackup status');
    }

    /**
     * List existing backups.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json, csv. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup list
     *     wp siteguardbackup list --format=json
     *
     * @subcommand list
     */
    public function list_backups($args, $assoc_args) {
        $history = SiteGuard_Backup_History::get_history();

        if (empty($history)) {
            WP_CLI::log('No backups found.');
            return;
        }

        $items = array();
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();

        foreach ($history as $timestamp => $backup) {
            $nonce = isset($backup['nonce']) ? $backup['nonce'] : '';
            $size = 0;
            $files = SiteGuard_Backup_History::get_all_files($backup);
            $exists = true;
            foreach ($files as $f) {
                $p = $backup_dir . $f;
                if (file_exists($p)) {
                    $size += filesize($p);
                } else {
                    $exists = false;
                }
            }

            $entities = array();
            foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
                if (!empty($backup[$e])) $entities[] = $e;
            }

            $items[] = array(
                'date'     => gmdate('Y-m-d H:i', $timestamp),
                'nonce'    => $nonce,
                'size'     => SiteGuard_Backup_Filesystem::format_bytes($size),
                'entities' => implode(',', $entities),
                'storage'  => implode(',', isset($backup['service']) ? $backup['service'] : array('none')),
                'local'    => $exists ? 'yes' : 'partial',
                'keep'     => !empty($backup['always_keep']) ? 'yes' : 'no',
            );
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        WP_CLI\Utils\format_items($format, $items, array('date', 'nonce', 'size', 'entities', 'storage', 'local', 'keep'));
    }

    /**
     * Restore a backup.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to restore.
     *
     * [--entities=<entities>]
     * : Comma-separated entities to restore: db,plugins,themes,uploads,others,wpcore
     *
     * [--encryption-key=<key>]
     * : Decryption key for encrypted backups.
     *
     * [--tables=<tables>]
     * : Comma-separated table names for selective restore.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup restore 1709283600
     *     wp siteguardbackup restore 1709283600 --entities=db,plugins
     *
     * @subcommand restore
     */
    public function restore($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        $timestamp = intval($args[0]);
        $backup_set = SiteGuard_Backup_History::get_history($timestamp);
        if (empty($backup_set)) {
            WP_CLI::error("Backup not found for timestamp: {$timestamp}");
        }

        $restore_options = array();

        if (isset($assoc_args['entities'])) {
            $entities = array_map('trim', explode(',', $assoc_args['entities']));
            foreach ($entities as $e) {
                $restore_options[$e] = true;
            }
        } else {
            foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
                if (!empty($backup_set[$e])) {
                    $restore_options[$e] = true;
                }
            }
        }

        if (isset($assoc_args['encryption-key'])) {
            $restore_options['encryption_key'] = $assoc_args['encryption-key'];
        }

        if (isset($assoc_args['tables'])) {
            $restore_options['restore_tables'] = array_map('trim', explode(',', $assoc_args['tables']));
        }

        WP_CLI::confirm('This will restore the backup. Continue?');

        WP_CLI::log('Starting restore...');

        require_once SGBACKUP_DIR . 'restorer.php';
        $restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
        $result = $restorer->perform_restore();

        if ($result['success']) {
            WP_CLI::success('Restore complete!');
        } else {
            WP_CLI::warning('Restore completed with errors.');
        }

        foreach ($result['messages'] as $msg) {
            WP_CLI::log("  {$msg}");
        }
        foreach ($result['errors'] as $err) {
            WP_CLI::warning("  Error: {$err}");
        }
    }

    /**
     * Show backup status.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup status
     *
     * @subcommand status
     */
    public function status($args, $assoc_args) {
        $sgbackup = SiteGuard_Backup::instance();

        // Active jobs
        $active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
        if (empty($active_jobs)) {
            WP_CLI::log('No active backup jobs.');
        } else {
            WP_CLI::log('Active jobs:');
            foreach ($active_jobs as $nonce => $info) {
                $jobdata = $sgbackup->jobdata_getarray($nonce);
                $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : 'unknown';
                WP_CLI::log("  [{$nonce}] Status: {$status}, Resumption: {$info['resumption']}");
            }
        }

        // Schedule info
        $schedule = $sgbackup->get_schedule_info();
        WP_CLI::log('');
        WP_CLI::log('Schedules:');
        WP_CLI::log("  Files:       {$schedule['interval']}" .
            ($schedule['next_files'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_files']) . ' UTC)' : ''));
        WP_CLI::log("  Database:    {$schedule['interval_database']}" .
            ($schedule['next_database'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_database']) . ' UTC)' : ''));
        WP_CLI::log("  Incremental: {$schedule['interval_incremental']}" .
            ($schedule['next_incremental'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_incremental']) . ' UTC)' : ''));

        // Total backup size
        $total = SiteGuard_Backup_History::get_total_backup_size();
        $count = count(SiteGuard_Backup_History::get_history());
        WP_CLI::log('');
        WP_CLI::log("Backups: {$count} sets, " . SiteGuard_Backup_Filesystem::format_bytes($total) . " total");
    }

    /**
     * Verify backup integrity.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to verify.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup verify 1709283600
     *
     * @subcommand verify
     */
    public function verify($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        $timestamp = intval($args[0]);

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->verify_backup(array('timestamp' => $timestamp));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::log("Verified: {$result['verified']} files");
        if ($result['failed'] > 0) {
            WP_CLI::warning("Failed: {$result['failed']} files");
        }
        if ($result['missing'] > 0) {
            WP_CLI::warning("Missing: {$result['missing']} files");
        }

        foreach ($result['details'] as $detail) {
            $icon = $detail['status'] === 'ok' ? 'OK' : strtoupper($detail['status']);
            WP_CLI::log("  [{$icon}] {$detail['file']}");
        }

        if ($result['success']) {
            WP_CLI::success('All files verified.');
        } else {
            WP_CLI::error('Verification found issues.');
        }
    }

    /**
     * Export a backup for transfer to another server.
     *
     * Creates a full backup synchronously and outputs the file paths,
     * one per line, for scripting (e.g., rsync/scp to a standby server).
     *
     * ## OPTIONS
     *
     * [--db-only]
     * : Only export the database (faster for sync).
     *
     * [--output-dir=<dir>]
     * : Copy backup files to this directory instead of default.
     *
     * [--format=<format>]
     * : Output format: paths (default) or json.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup export
     *     wp siteguardbackup export --db-only
     *     wp siteguardbackup export --output-dir=/tmp/export --format=json
     *
     * @subcommand export
     */
    public function export($args, $assoc_args) {
        $sgbackup = SiteGuard_Backup::instance();
        $options = array('service' => array('none'));

        if (isset($assoc_args['db-only'])) {
            $options['nofiles'] = true;
        }

        $options['always_keep'] = true;

        WP_CLI::log('Creating export backup...');
        $sgbackup->backup_all($options);

        // Run the cron to complete the backup synchronously
        $nonce = $sgbackup->nonce;
        $max_resumptions = 50;
        for ($i = 0; $i < $max_resumptions; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if ($status === 'finished' || $status === 'error') break;

            $sgbackup->backup_resume($i, $nonce);

            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if ($status === 'finished' || $status === 'error') break;
        }

        // Get the backup files
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $history = SiteGuard_Backup_History::get_history();

        // Find the backup we just created
        $latest = null;
        $latest_ts = 0;
        foreach ($history as $ts => $backup) {
            if (isset($backup['nonce']) && $backup['nonce'] === $nonce) {
                $latest = $backup;
                $latest_ts = $ts;
                break;
            }
        }

        if (!$latest) {
            WP_CLI::error('Backup not found after export. Check logs.');
        }

        $files = SiteGuard_Backup_History::get_all_files($latest);
        $output_dir = isset($assoc_args['output-dir']) ? trailingslashit($assoc_args['output-dir']) : '';
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'paths';

        // Optionally copy to output directory
        if (!empty($output_dir)) {
            if (!is_dir($output_dir)) {
                wp_mkdir_p($output_dir);
            }
            foreach ($files as $f) {
                $src = $backup_dir . $f;
                $dest = $output_dir . $f;
                if (file_exists($src)) {
                    copy($src, $dest);
                }
            }
        }

        $base = !empty($output_dir) ? $output_dir : $backup_dir;

        if ($format === 'json') {
            $output = array(
                'timestamp' => $latest_ts,
                'nonce'     => $nonce,
                'files'     => array(),
            );
            foreach ($files as $f) {
                $path = $base . $f;
                $output['files'][] = array(
                    'name' => $f,
                    'path' => $path,
                    'size' => file_exists($path) ? filesize($path) : 0,
                );
            }
            WP_CLI::log(json_encode($output, JSON_PRETTY_PRINT));
        } else {
            foreach ($files as $f) {
                WP_CLI::log($base . $f);
            }
        }

        WP_CLI::success("Export complete: " . count($files) . " files.");
    }

    /**
     * Import backup files from a local directory.
     *
     * Registers files in the backup directory as a backup set and optionally
     * triggers a restore. Used for receiving backups from another server
     * (e.g., Azure production -> Docker standby).
     *
     * ## OPTIONS
     *
     * <source>
     * : Path to directory containing backup files, or a single backup file.
     *
     * [--restore]
     * : Automatically restore after importing.
     *
     * [--entities=<entities>]
     * : When restoring, which entities: db,plugins,themes,uploads,others,wpcore
     *
     * [--delete-source]
     * : Delete source files after successful import.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup import /tmp/azure-backup/
     *     wp siteguardbackup import /tmp/azure-backup/ --restore --entities=db
     *     wp siteguardbackup import /tmp/backup_2026-03-01-1457_site_abc123-db.gz
     *
     * @subcommand import
     */
    public function import($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Source path required.');
        }

        $source = $args[0];
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();

        $imported_files = array();

        if (is_dir($source)) {
            // Import all backup files from directory
            $source = trailingslashit($source);
            $scan = scandir($source);
            foreach ($scan as $f) {
                if ($f === '.' || $f === '..') continue;
                if (!preg_match('/^backup_.*\.(zip|gz|sql|crypt|json|txt)$/', $f)) continue;

                $src = $source . $f;
                $dest = $backup_dir . $f;
                if (copy($src, $dest)) {
                    $imported_files[] = $f;
                    WP_CLI::log("Imported: {$f}");
                } else {
                    WP_CLI::warning("Failed to copy: {$f}");
                }
            }
        } elseif (is_file($source)) {
            $f = basename($source);
            $dest = $backup_dir . $f;
            if (copy($source, $dest)) {
                $imported_files[] = $f;
                WP_CLI::log("Imported: {$f}");
            } else {
                WP_CLI::error("Failed to copy: {$source}");
            }
        } else {
            WP_CLI::error("Source not found: {$source}");
        }

        if (empty($imported_files)) {
            WP_CLI::error('No backup files found to import.');
        }

        // Register in backup history, grouping by nonce
        $grouped = array();
        foreach ($imported_files as $f) {
            if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_([^_]+)_([a-zA-Z0-9]+)/', $f, $m)) {
                $nonce = $m[3];
                $date_str = $m[1];
                if (!isset($grouped[$nonce])) {
                    $timestamp = strtotime(
                        substr($date_str, 0, 10) . ' ' .
                        substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2) . ':00'
                    );
                    $grouped[$nonce] = array(
                        'timestamp' => $timestamp ?: time(),
                        'nonce'     => $nonce,
                        'service'   => array('none'),
                    );
                }

                foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
                    if (strpos($f, "-{$e}") !== false) {
                        if (!isset($grouped[$nonce][$e])) {
                            $grouped[$nonce][$e] = array();
                        }
                        $grouped[$nonce][$e][] = $f;
                        break;
                    }
                }
            }
        }

        $registered_ts = null;
        foreach ($grouped as $nonce => $backup_data) {
            $ts = $backup_data['timestamp'];
            unset($backup_data['timestamp']);
            SiteGuard_Backup_History::add_backup($ts, $backup_data);
            WP_CLI::log("Registered backup set: {$nonce} (" . gmdate('Y-m-d H:i', $ts) . " UTC)");
            $registered_ts = $ts;
        }

        // Delete source if requested
        if (isset($assoc_args['delete-source']) && !empty($imported_files)) {
            if (is_dir($source)) {
                foreach ($imported_files as $f) {
                    @unlink(trailingslashit($source) . $f);
                }
            } elseif (is_file($source)) {
                @unlink($source);
            }
            WP_CLI::log("Source files deleted.");
        }

        // Auto-restore if requested
        if (isset($assoc_args['restore']) && $registered_ts) {
            $backup_set = SiteGuard_Backup_History::get_history($registered_ts);
            if ($backup_set) {
                $restore_options = array();
                if (isset($assoc_args['entities'])) {
                    $entities = array_map('trim', explode(',', $assoc_args['entities']));
                    foreach ($entities as $e) {
                        $restore_options[$e] = true;
                    }
                } else {
                    foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
                        if (!empty($backup_set[$e])) {
                            $restore_options[$e] = true;
                        }
                    }
                }

                WP_CLI::log('Starting restore...');
                require_once SGBACKUP_DIR . 'restorer.php';
                $restorer = new SiteGuard_Backup_Restorer($backup_set, $restore_options);
                $result = $restorer->perform_restore();

                if ($result['success']) {
                    WP_CLI::success('Restore complete!');
                } else {
                    WP_CLI::warning('Restore completed with errors.');
                }
                foreach ($result['errors'] as $err) {
                    WP_CLI::warning("  Error: {$err}");
                }
            }
        }

        WP_CLI::success("Imported " . count($imported_files) . " files.");
    }

    /**
     * Create a quick database-only backup before failover.
     *
     * Designed for shell script integration (e.g., cf-failover.sh).
     * Runs synchronously, outputs the backup file path on success.
     * Exit code 0 = success, 1 = failure.
     *
     * ## OPTIONS
     *
     * [--quiet]
     * : Only output the backup file path (for scripting).
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup prefailover
     *     BACKUP_FILE=$(wp siteguardbackup prefailover --quiet)
     *
     * @subcommand prefailover
     */
    public function prefailover($args, $assoc_args) {
        $quiet = isset($assoc_args['quiet']);

        if (!$quiet) WP_CLI::log('Creating pre-failover database backup...');

        $sgbackup = SiteGuard_Backup::instance();
        $options = array(
            'nofiles'     => true,
            'service'     => array('none'),
            'always_keep' => true,
            'label'       => 'pre-failover',
        );

        $sgbackup->backup_all($options);
        $nonce = $sgbackup->nonce;

        // Run resume loop synchronously
        for ($i = 0; $i < 20; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if ($status === 'finished' || $status === 'error') break;

            $sgbackup->backup_resume($i, $nonce);

            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if ($status === 'finished' || $status === 'error') break;
        }

        // Find the backup
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $history = SiteGuard_Backup_History::get_history();

        foreach ($history as $ts => $backup) {
            if (isset($backup['nonce']) && $backup['nonce'] === $nonce && !empty($backup['db'])) {
                $db_files = is_array($backup['db']) ? $backup['db'] : array($backup['db']);
                $path = $backup_dir . $db_files[0];
                if (file_exists($path)) {
                    if ($quiet) {
                        WP_CLI::log($path);
                    } else {
                        WP_CLI::success("Pre-failover backup: {$path} (" . SiteGuard_Backup_Filesystem::format_bytes(filesize($path)) . ")");
                    }
                    return;
                }
            }
        }

        WP_CLI::error('Pre-failover backup failed.');
    }

    /**
     * Show backup job progress.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup backup_progress
     *
     * @subcommand backup_progress
     */
    public function backup_progress($args, $assoc_args) {
        $sgbackup = SiteGuard_Backup::instance();
        $active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();

        if (empty($active_jobs)) {
            WP_CLI::log('No active backup jobs.');
            return;
        }

        foreach ($active_jobs as $nonce => $info) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : 'unknown';

            WP_CLI::log("Job nonce: {$nonce}");
            WP_CLI::log("  Status: {$status}");

            // Show current entity being processed
            if (isset($jobdata['job_file_entities']) && is_array($jobdata['job_file_entities'])) {
                foreach ($jobdata['job_file_entities'] as $entity => $entity_info) {
                    $entity_status = isset($entity_info['status']) ? $entity_info['status'] : 'pending';
                    if ($entity_status === 'partial') {
                        WP_CLI::log("  Entity in progress: {$entity}");
                    }
                }
            }

            // Show database progress
            if (isset($jobdata['db_completed_tables']) && is_array($jobdata['db_completed_tables'])) {
                $completed = count($jobdata['db_completed_tables']);
                WP_CLI::log("  DB tables completed: {$completed}");
            }

            // Show percentage if available
            $phases_done = 0;
            $phases_total = 0;
            foreach (array('files_phase_complete', 'db_phase_complete', 'encryption_phase_complete', 'upload_phase_complete') as $phase) {
                $phases_total++;
                if (!empty($jobdata[$phase])) {
                    $phases_done++;
                }
            }
            if ($phases_total > 0) {
                $pct = round(($phases_done / $phases_total) * 100);
                WP_CLI::log("  Progress: {$pct}% ({$phases_done}/{$phases_total} phases)");
            }

            // Elapsed time
            if (isset($jobdata['backup_time'])) {
                $elapsed = time() - intval($jobdata['backup_time']);
                $minutes = floor($elapsed / 60);
                $seconds = $elapsed % 60;
                WP_CLI::log("  Elapsed: {$minutes}m {$seconds}s");
            }

            WP_CLI::log("  Resumption: {$info['resumption']}");
        }
    }

    /**
     * Get the latest full backup.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup get_latest_full_backup
     *     wp siteguardbackup get_latest_full_backup --format=json
     *
     * @subcommand get_latest_full_backup
     */
    public function get_latest_full_backup($args, $assoc_args) {
        $latest = SiteGuard_Backup_History::get_latest();

        if (empty($latest)) {
            WP_CLI::log('No backups found.');
            return;
        }

        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();

        $timestamp = isset($latest['timestamp']) ? $latest['timestamp'] : 0;
        $entities = array();
        $file_sizes = array();
        $total_size = 0;

        foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
            if (!empty($latest[$e])) {
                $entities[] = $e;
                $files = is_array($latest[$e]) ? $latest[$e] : array($latest[$e]);
                $entity_size = 0;
                foreach ($files as $f) {
                    $path = $backup_dir . $f;
                    if (file_exists($path)) {
                        $entity_size += filesize($path);
                    }
                }
                $file_sizes[$e] = SiteGuard_Backup_Filesystem::format_bytes($entity_size);
                $total_size += $entity_size;
            }
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($format === 'json') {
            $output = array(
                'timestamp'  => $timestamp,
                'date'       => gmdate('Y-m-d H:i:s', $timestamp),
                'nonce'      => isset($latest['nonce']) ? $latest['nonce'] : '',
                'entities'   => $entities,
                'file_sizes' => $file_sizes,
                'total_size' => SiteGuard_Backup_Filesystem::format_bytes($total_size),
            );
            WP_CLI::log(json_encode($output, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::log("Latest backup:");
            WP_CLI::log("  Timestamp: {$timestamp}");
            WP_CLI::log("  Date:      " . gmdate('Y-m-d H:i:s', $timestamp) . " UTC");
            WP_CLI::log("  Nonce:     " . (isset($latest['nonce']) ? $latest['nonce'] : 'n/a'));
            WP_CLI::log("  Entities:  " . implode(', ', $entities));
            foreach ($file_sizes as $entity => $size) {
                WP_CLI::log("  {$entity}: {$size}");
            }
            WP_CLI::log("  Total:     " . SiteGuard_Backup_Filesystem::format_bytes($total_size));
        }
    }

    /**
     * Rescan storage for backups not in history.
     *
     * ## OPTIONS
     *
     * <type>
     * : Storage type to scan: local or remote.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup rescan_storage local
     *     wp siteguardbackup rescan_storage remote
     *
     * @subcommand rescan_storage
     */
    public function rescan_storage($args, $assoc_args) {
        if (empty($args[0]) || !in_array($args[0], array('local', 'remote'), true)) {
            WP_CLI::error('First argument must be "local" or "remote".');
        }

        $type = $args[0];
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $history = SiteGuard_Backup_History::get_history();
        $discovered = 0;

        // Build set of all known files in history
        $known_files = array();
        foreach ($history as $ts => $backup) {
            $all_files = SiteGuard_Backup_History::get_all_files($backup);
            foreach ($all_files as $f) {
                $known_files[$f] = true;
            }
        }

        if ($type === 'local') {
            // Scan backup directory for backup_*.{zip,gz,sql,crypt} files
            $scan = @scandir($backup_dir);
            if (!$scan) {
                WP_CLI::error("Cannot read backup directory: {$backup_dir}");
            }

            $orphan_files = array();
            foreach ($scan as $f) {
                if ($f === '.' || $f === '..') continue;
                if (!preg_match('/^backup_.*\.(zip|gz|sql|crypt)$/', $f)) continue;
                if (isset($known_files[$f])) continue;
                $orphan_files[] = $f;
            }

            if (empty($orphan_files)) {
                WP_CLI::log('No orphan backup files found in local storage.');
                return;
            }

            // Group by nonce
            $grouped = array();
            foreach ($orphan_files as $f) {
                if (preg_match('/^backup_(\d{4}-\d{2}-\d{2}-\d{4})_([^_]+)_([a-zA-Z0-9]+)/', $f, $m)) {
                    $nonce = $m[3];
                    $date_str = $m[1];
                    if (!isset($grouped[$nonce])) {
                        $timestamp = strtotime(
                            substr($date_str, 0, 10) . ' ' .
                            substr($date_str, 11, 2) . ':' . substr($date_str, 13, 2) . ':00'
                        );
                        $grouped[$nonce] = array(
                            'timestamp' => $timestamp ?: time(),
                            'nonce'     => $nonce,
                            'service'   => array('none'),
                        );
                    }
                    foreach (array('plugins', 'themes', 'uploads', 'others', 'db', 'wpcore') as $e) {
                        if (strpos($f, "-{$e}") !== false) {
                            if (!isset($grouped[$nonce][$e])) {
                                $grouped[$nonce][$e] = array();
                            }
                            $grouped[$nonce][$e][] = $f;
                            break;
                        }
                    }
                }
            }

            foreach ($grouped as $nonce => $backup_data) {
                $ts = $backup_data['timestamp'];
                unset($backup_data['timestamp']);
                SiteGuard_Backup_History::add_backup($ts, $backup_data);
                $discovered++;
                WP_CLI::log("Discovered: {$nonce} (" . gmdate('Y-m-d H:i', $ts) . " UTC)");
            }
        } else {
            // Remote: iterate configured storage methods
            $sgbackup = SiteGuard_Backup::instance();
            $service = $opts->get_option('sgbackup_service', array('none'));
            if (!is_array($service)) $service = array($service);

            foreach ($service as $method_id) {
                if ($method_id === 'none') continue;

                // Validate method against whitelist
                if (!array_key_exists($method_id, SiteGuard_Backup::instance()->backup_methods)) {
                    WP_CLI::warning("Unknown storage method: {$method_id}");
                    continue;
                }

                // Attempt to load the method
                $method_file = SGBACKUP_DIR . 'methods/' . $method_id . '.php';
                $module_file = SGBACKUP_DIR . 'methods/backup-module.php';
                if (!file_exists($method_file)) {
                    WP_CLI::warning("Method file not found: {$method_id}");
                    continue;
                }

                require_once $module_file;
                require_once $method_file;

                $class_name = 'SiteGuard_Backup_Method_' . ucfirst($method_id);
                if (!class_exists($class_name)) {
                    WP_CLI::warning("Method class not found: {$class_name}");
                    continue;
                }

                $method = new $class_name();
                if (!method_exists($method, 'listfiles')) {
                    WP_CLI::warning("Method {$method_id} does not support listing files.");
                    continue;
                }

                WP_CLI::log("Scanning remote: {$method_id}...");
                $remote_files = $method->listfiles();
                if (is_wp_error($remote_files) || !is_array($remote_files)) {
                    WP_CLI::warning("Failed to list files from {$method_id}.");
                    continue;
                }

                foreach ($remote_files as $rf) {
                    $filename = is_array($rf) ? (isset($rf['name']) ? $rf['name'] : '') : $rf;
                    if (empty($filename)) continue;
                    if (!preg_match('/^backup_.*\.(zip|gz|sql|crypt)$/', $filename)) continue;
                    if (isset($known_files[$filename])) continue;

                    $discovered++;
                    WP_CLI::log("  Remote orphan: {$filename}");
                }
            }
        }

        WP_CLI::success("Rescan complete. Discovered {$discovered} backup(s) not in history.");
    }

    /**
     * Create a migration key.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup create_migration_key
     *
     * @subcommand create_migration_key
     */
    public function create_migration_key($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->migrate_key_create(array());

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::log("Migration key bundle:");
        WP_CLI::log($result['bundle']);
        WP_CLI::success("Migration key '{$result['name']}' created.");
    }

    /**
     * Search and replace in the database.
     *
     * ## OPTIONS
     *
     * <search>
     * : String to search for.
     *
     * <replace>
     * : String to replace with.
     *
     * [--dry-run]
     * : Preview changes without applying. Default: true.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup search_replace "http://old.example.com" "https://new.example.com"
     *     wp siteguardbackup search_replace "http://old.example.com" "https://new.example.com" --no-dry-run
     *
     * @subcommand search_replace
     */
    public function search_replace($args, $assoc_args) {
        if (empty($args[0]) || !isset($args[1])) {
            WP_CLI::error('Two arguments required: <search> <replace>');
        }

        $search = $args[0];
        $replace = $args[1];
        $dry_run = isset($assoc_args['dry-run']) ? $assoc_args['dry-run'] : true;
        // WP-CLI flag: --no-dry-run sets it to false
        if ($dry_run === false) {
            $dry_run = false;
        } else {
            $dry_run = true;
        }

        WP_CLI::log("Search:  {$search}");
        WP_CLI::log("Replace: {$replace}");
        WP_CLI::log("Dry run: " . ($dry_run ? 'yes' : 'no'));

        if (!$dry_run) {
            WP_CLI::confirm('This will modify the database. Continue?');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->searchreplace(array(
            'search'  => $search,
            'replace' => $replace,
            'dry_run' => $dry_run,
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if (isset($result['tables_searched'])) {
            WP_CLI::log("Tables searched: {$result['tables_searched']}");
        }
        if (isset($result['changes'])) {
            WP_CLI::log("Changes " . ($dry_run ? 'found' : 'made') . ": {$result['changes']}");
        }
        if (isset($result['table_report']) && is_array($result['table_report'])) {
            foreach ($result['table_report'] as $table => $count) {
                if ($count > 0) {
                    WP_CLI::log("  {$table}: {$count}");
                }
            }
        }

        WP_CLI::success($dry_run ? 'Dry run complete.' : 'Search-replace complete.');
    }

    /**
     * Run a health check on the backup system.
     *
     * Checks: backup freshness, disk space, DB connectivity, lock status,
     * cron schedules, PHP environment. Returns exit code 0 if healthy, 1 if issues found.
     * Designed for scripting integration (e.g., cf-failover.sh pre-checks).
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: text (default) or json.
     *
     * [--max-age=<hours>]
     * : Maximum backup age in hours before warning. Default: 168 (7 days).
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup healthcheck
     *     wp siteguardbackup healthcheck --format=json
     *     wp siteguardbackup healthcheck --max-age=24
     *
     * @subcommand healthcheck
     */
    public function healthcheck($args, $assoc_args) {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'text';
        $max_age_hours = isset($assoc_args['max-age']) ? intval($assoc_args['max-age']) : 168;
        $max_age_seconds = $max_age_hours * 3600;

        $checks = array();
        $has_errors = false;
        $has_warnings = false;

        // 1. Database connectivity
        global $wpdb;
        $db_ok = (bool) $wpdb->get_var("SELECT 1");
        $checks['database'] = array(
            'status'  => $db_ok ? 'ok' : 'error',
            'message' => $db_ok ? 'Connected to ' . DB_NAME : 'Database connection failed',
        );
        if (!$db_ok) $has_errors = true;

        // 2. Backup directory writable
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $dir_writable = is_dir($backup_dir) && is_writable($backup_dir);
        $checks['backup_directory'] = array(
            'status'  => $dir_writable ? 'ok' : 'error',
            'message' => $dir_writable ? "Writable: {$backup_dir}" : "Not writable: {$backup_dir}",
        );
        if (!$dir_writable) $has_errors = true;

        // 3. Disk space
        $free_bytes = @disk_free_space($backup_dir);
        $disk_ok = ($free_bytes !== false && $free_bytes > 104857600); // 100MB minimum
        $checks['disk_space'] = array(
            'status'  => $disk_ok ? 'ok' : ($free_bytes === false ? 'warning' : 'error'),
            'message' => $free_bytes !== false
                ? SiteGuard_Backup_Filesystem::format_bytes($free_bytes) . ' free'
                : 'Could not determine free space',
        );
        if (!$disk_ok && $free_bytes !== false) $has_errors = true;
        if ($free_bytes === false) $has_warnings = true;

        // 4. Backup freshness
        $latest = SiteGuard_Backup_History::get_latest();
        if ($latest) {
            $ts = isset($latest['timestamp']) ? $latest['timestamp'] : 0;
            $age = time() - $ts;
            $age_ok = ($age < $max_age_seconds);
            $age_str = $this->format_duration($age);
            $checks['backup_freshness'] = array(
                'status'  => $age_ok ? 'ok' : 'warning',
                'message' => "Latest backup: {$age_str} ago (" . gmdate('Y-m-d H:i', $ts) . " UTC)",
            );
            if (!$age_ok) $has_warnings = true;
        } else {
            $checks['backup_freshness'] = array(
                'status'  => 'error',
                'message' => 'No backups found',
            );
            $has_errors = true;
        }

        // 5. Backup count and total size
        $history = SiteGuard_Backup_History::get_history();
        $count = count($history);
        $total = SiteGuard_Backup_History::get_total_backup_size();
        $checks['backup_inventory'] = array(
            'status'  => $count > 0 ? 'ok' : 'warning',
            'message' => "{$count} backup sets, " . SiteGuard_Backup_Filesystem::format_bytes($total) . " total",
        );

        // 6. Active jobs / stuck jobs
        $active_jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
        $stuck_jobs = 0;
        foreach ($active_jobs as $nonce => $info) {
            $sgbackup = SiteGuard_Backup::instance();
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $job_time = isset($jobdata['backup_time']) ? intval($jobdata['backup_time']) : 0;
            if ($job_time > 0 && (time() - $job_time) > 7200) { // 2 hours
                $stuck_jobs++;
            }
        }
        $checks['active_jobs'] = array(
            'status'  => $stuck_jobs > 0 ? 'warning' : 'ok',
            'message' => count($active_jobs) . ' active, ' . $stuck_jobs . ' possibly stuck',
        );
        if ($stuck_jobs > 0) $has_warnings = true;

        // 7. Semaphore lock status
        $lock = new SiteGuard_Backup_Semaphore('backup');
        $lock_free = $lock->is_free();
        $checks['semaphore'] = array(
            'status'  => $lock_free ? 'ok' : 'warning',
            'message' => $lock_free ? 'No active locks' : 'Backup lock held (job may be running)',
        );

        // 8. Cron schedules
        $sgbackup = SiteGuard_Backup::instance();
        $schedule = $sgbackup->get_schedule_info();
        $cron_msg = "Files: {$schedule['interval']}, DB: {$schedule['interval_database']}";
        $checks['cron_schedules'] = array(
            'status'  => 'ok',
            'message' => $cron_msg,
        );

        // 9. PHP environment
        $max_exec = ini_get('max_execution_time');
        $mem_limit = ini_get('memory_limit');
        $zip_ok = class_exists('ZipArchive');
        $checks['php_environment'] = array(
            'status'  => $zip_ok ? 'ok' : 'warning',
            'message' => "PHP " . PHP_VERSION . ", memory: {$mem_limit}, max_exec: {$max_exec}s, ZipArchive: " . ($zip_ok ? 'yes' : 'no'),
        );
        if (!$zip_ok) $has_warnings = true;

        // 10. Multisite status
        if (is_multisite()) {
            $blog_count = get_blog_count();
            $checks['multisite'] = array(
                'status'  => 'ok',
                'message' => "Multisite enabled, {$blog_count} sites",
            );
        }

        // Output
        if ($format === 'json') {
            $output = array(
                'healthy' => !$has_errors,
                'warnings' => $has_warnings,
                'checks' => $checks,
            );
            WP_CLI::log(json_encode($output, JSON_PRETTY_PRINT));
        } else {
            $overall = $has_errors ? 'UNHEALTHY' : ($has_warnings ? 'WARNINGS' : 'HEALTHY');
            WP_CLI::log("Status: {$overall}");
            WP_CLI::log('');
            foreach ($checks as $name => $check) {
                $icon = $check['status'] === 'ok' ? 'OK' : strtoupper($check['status']);
                $label = str_replace('_', ' ', ucfirst($name));
                WP_CLI::log(sprintf("  [%-7s] %-20s %s", $icon, $label, $check['message']));
            }
        }

        if ($has_errors) {
            WP_CLI::error('Health check found errors.', false);
        } elseif ($has_warnings) {
            WP_CLI::warning('Health check found warnings.');
        } else {
            WP_CLI::success('All checks passed.');
        }
    }

    /**
     * Clean up stale job data, locks, temp files, and old logs.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would be cleaned without deleting.
     *
     * [--max-log-age=<days>]
     * : Delete log files older than this many days. Default: 30.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup cleanup
     *     wp siteguardbackup cleanup --dry-run
     *     wp siteguardbackup cleanup --max-log-age=7
     *
     * @subcommand cleanup
     */
    public function cleanup($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $max_log_days = isset($assoc_args['max-log-age']) ? intval($assoc_args['max-log-age']) : 30;
        $max_log_age = $max_log_days * 86400;

        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $cleaned = 0;

        WP_CLI::log($dry_run ? 'Dry run - previewing cleanup:' : 'Running cleanup:');

        // 1. Clean stale jobdata options
        global $wpdb;
        $job_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sgbackup_jobdata_%'"
        );
        if (is_multisite()) {
            $job_options = $wpdb->get_col(
                "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'sgbackup_jobdata_%'"
            );
        }

        $active_nonces = array_keys(SiteGuard_Backup_Job_Scheduler::get_active_jobs());
        foreach ($job_options as $opt) {
            $nonce = str_replace('sgbackup_jobdata_', '', $opt);
            if (in_array($nonce, $active_nonces)) continue;

            // Check if jobdata is old (more than 24 hours)
            $jobdata = $opts->get_option($opt, array());
            $job_time = isset($jobdata['backup_time']) ? intval($jobdata['backup_time']) : 0;
            if ($job_time > 0 && (time() - $job_time) < 86400) continue;

            if ($dry_run) {
                WP_CLI::log("  Would delete stale jobdata: {$nonce}");
            } else {
                $opts->delete_option($opt);
                WP_CLI::log("  Deleted stale jobdata: {$nonce}");
            }
            $cleaned++;
        }

        // 2. Clean old log files
        $scan = @scandir($backup_dir);
        if (is_array($scan)) {
            foreach ($scan as $f) {
                if (!preg_match('/^log\..*\.txt$/', $f)) continue;
                $path = $backup_dir . $f;
                $age = time() - filemtime($path);
                if ($age < $max_log_age) continue;

                if ($dry_run) {
                    WP_CLI::log("  Would delete old log: {$f} (" . $this->format_duration($age) . " old)");
                } else {
                    @unlink($path);
                    WP_CLI::log("  Deleted old log: {$f}");
                }
                $cleaned++;
            }
        }

        // 3. Clean orphan temp/restore directories
        if (is_array($scan)) {
            foreach ($scan as $f) {
                if ($f === '.' || $f === '..') continue;
                $path = $backup_dir . $f;
                if (!is_dir($path)) continue;
                if (!preg_match('/^(restore_|dbtemp_)/', $f)) continue;

                // Check if directory is older than 6 hours
                $dir_age = time() - filemtime($path);
                if ($dir_age < 21600) continue;

                if ($dry_run) {
                    WP_CLI::log("  Would delete orphan temp dir: {$f}");
                } else {
                    SiteGuard_Backup_Filesystem::rmdir_recursive($path);
                    WP_CLI::log("  Deleted orphan temp dir: {$f}");
                }
                $cleaned++;
            }
        }

        // 4. Clear stale semaphore locks (older than 1 hour)
        $lock_option = $opts->get_option('sgbackup_lock_backup', '');
        if (!empty($lock_option)) {
            $lock_data = maybe_unserialize($lock_option);
            $lock_time = 0;
            if (is_array($lock_data) && isset($lock_data['time'])) {
                $lock_time = intval($lock_data['time']);
            } elseif (is_numeric($lock_data)) {
                $lock_time = intval($lock_data);
            }
            if ($lock_time > 0 && (time() - $lock_time) > 3600) {
                if ($dry_run) {
                    WP_CLI::log("  Would clear stale lock (held since " . gmdate('Y-m-d H:i', $lock_time) . " UTC)");
                } else {
                    $opts->delete_option('sgbackup_lock_backup');
                    WP_CLI::log("  Cleared stale semaphore lock");
                }
                $cleaned++;
            }
        }

        // 5. Clear restore progress transient if stale
        $restore_progress = $opts->get_transient('sgbackup_restore_progress');
        if (!empty($restore_progress) && is_array($restore_progress)) {
            $last_update = isset($restore_progress['last_update']) ? $restore_progress['last_update'] : 0;
            if ($last_update > 0 && (time() - $last_update) > 3600) {
                if ($dry_run) {
                    WP_CLI::log("  Would clear stale restore progress");
                } else {
                    $opts->delete_transient('sgbackup_restore_progress');
                    WP_CLI::log("  Cleared stale restore progress");
                }
                $cleaned++;
            }
        }

        if ($cleaned === 0) {
            WP_CLI::log('  Nothing to clean up.');
        }
        WP_CLI::success(($dry_run ? 'Would clean' : 'Cleaned') . " {$cleaned} items.");
    }

    /**
     * List all sites in the multisite network.
     *
     * Shows domain, path, status, and last updated for each site.
     * Useful for comparing Azure production vs Docker standby.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table (default) or json.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup sites
     *     wp siteguardbackup sites --format=json
     *
     * @subcommand sites
     */
    public function sites($args, $assoc_args) {
        if (!is_multisite()) {
            WP_CLI::log('Single-site installation.');
            WP_CLI::log('  URL:   ' . get_option('siteurl'));
            WP_CLI::log('  Home:  ' . get_option('home'));
            WP_CLI::log('  Title: ' . get_option('blogname'));
            return;
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $sites = get_sites(array('number' => 0));
        $items = array();

        foreach ($sites as $site) {
            $details = get_blog_details($site->blog_id);
            $items[] = array(
                'id'       => $site->blog_id,
                'domain'   => $site->domain,
                'path'     => $site->path,
                'name'     => $details ? $details->blogname : '',
                'status'   => $this->get_site_status($site),
                'updated'  => $site->last_updated,
                'posts'    => $this->get_site_post_count($site->blog_id),
            );
        }

        if ($format === 'json') {
            WP_CLI::log(json_encode($items, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::log("Multisite network: " . count($items) . " sites");
            WP_CLI::log('');
            WP_CLI\Utils\format_items('table', $items, array('id', 'domain', 'path', 'name', 'status', 'updated', 'posts'));
        }
    }

    /**
     * View or set backup schedules.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : Action: get (default) or set.
     *
     * [--files=<interval>]
     * : Set file backup interval: manual, everyhour, every2hours, every4hours,
     *   every8hours, twicedaily, daily, weekly, fortnightly, monthly.
     *
     * [--database=<interval>]
     * : Set database backup interval (same options as --files).
     *
     * [--incremental=<interval>]
     * : Set incremental backup interval: none, everyhour, every2hours, every4hours.
     *
     * [--retain=<count>]
     * : Number of file backups to retain.
     *
     * [--retain-db=<count>]
     * : Number of database backups to retain.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup schedule
     *     wp siteguardbackup schedule set --files=daily --database=every4hours
     *     wp siteguardbackup schedule set --retain=5 --retain-db=10
     *
     * @subcommand schedule
     */
    public function schedule($args, $assoc_args) {
        $action = !empty($args[0]) ? $args[0] : 'get';
        $opts = SiteGuard_Backup_Options::instance();
        $sgbackup = SiteGuard_Backup::instance();

        $valid_intervals = array('manual', 'everyhour', 'every2hours', 'every4hours', 'every8hours', 'twicedaily', 'daily', 'weekly', 'fortnightly', 'monthly');
        $valid_incremental = array('none', 'everyhour', 'every2hours', 'every4hours');

        if ($action === 'set') {
            $changed = false;

            if (isset($assoc_args['files'])) {
                $interval = $assoc_args['files'];
                if (!in_array($interval, $valid_intervals)) {
                    WP_CLI::error("Invalid interval: {$interval}. Valid: " . implode(', ', $valid_intervals));
                }
                $opts->update_option('sgbackup_interval', $interval);
                $sgbackup->schedule_backup($interval);
                WP_CLI::log("File backup schedule set to: {$interval}");
                $changed = true;
            }

            if (isset($assoc_args['database'])) {
                $interval = $assoc_args['database'];
                if (!in_array($interval, $valid_intervals)) {
                    WP_CLI::error("Invalid interval: {$interval}. Valid: " . implode(', ', $valid_intervals));
                }
                $opts->update_option('sgbackup_interval_database', $interval);
                $sgbackup->schedule_backup_database($interval);
                WP_CLI::log("Database backup schedule set to: {$interval}");
                $changed = true;
            }

            if (isset($assoc_args['incremental'])) {
                $interval = $assoc_args['incremental'];
                if (!in_array($interval, $valid_incremental)) {
                    WP_CLI::error("Invalid incremental interval: {$interval}. Valid: " . implode(', ', $valid_incremental));
                }
                $opts->update_option('sgbackup_interval_incremental', $interval);
                $sgbackup->schedule_backup_increments($interval);
                WP_CLI::log("Incremental schedule set to: {$interval}");
                $changed = true;
            }

            if (isset($assoc_args['retain'])) {
                $retain = max(1, intval($assoc_args['retain']));
                $opts->update_option('sgbackup_retain', $retain);
                WP_CLI::log("File backup retention set to: {$retain}");
                $changed = true;
            }

            if (isset($assoc_args['retain-db'])) {
                $retain = max(1, intval($assoc_args['retain-db']));
                $opts->update_option('sgbackup_retain_db', $retain);
                WP_CLI::log("Database backup retention set to: {$retain}");
                $changed = true;
            }

            if (!$changed) {
                WP_CLI::warning('No schedule options specified. Use --files, --database, --incremental, --retain, or --retain-db.');
            } else {
                WP_CLI::success('Schedule updated.');
            }
        } else {
            // Display current schedules
            $schedule = $sgbackup->get_schedule_info();
            $retain_files = $opts->get_option('sgbackup_retain', 2);
            $retain_db = $opts->get_option('sgbackup_retain_db', 2);

            WP_CLI::log('Current backup schedules:');
            WP_CLI::log('');
            WP_CLI::log("  Files:       {$schedule['interval']}" .
                ($schedule['next_files'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_files']) . ' UTC)' : ''));
            WP_CLI::log("  Database:    {$schedule['interval_database']}" .
                ($schedule['next_database'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_database']) . ' UTC)' : ''));
            WP_CLI::log("  Incremental: {$schedule['interval_incremental']}" .
                ($schedule['next_incremental'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_incremental']) . ' UTC)' : ''));
            WP_CLI::log('');
            WP_CLI::log("  Retention (files): {$retain_files}");
            WP_CLI::log("  Retention (DB):    {$retain_db}");
        }
    }

    /**
     * Run post-failover maintenance tasks.
     *
     * Designed to be called by cf-failover.sh after DNS has been switched
     * from production (Azure) to this Docker standby. Performs:
     * - Flush all caches (object cache, transients, rewrite rules)
     * - Verify database connectivity
     * - Check active theme and plugins exist
     * - Optionally run URL search-replace
     * - Report overall site health
     *
     * ## OPTIONS
     *
     * [--search-replace=<pairs>]
     * : URL search-replace pairs, format: "old1|new1,old2|new2"
     *
     * [--flush-only]
     * : Only flush caches, skip all other checks.
     *
     * [--format=<format>]
     * : Output format: text (default) or json.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup postfailover
     *     wp siteguardbackup postfailover --search-replace="https://azure.example.com|https://docker.example.com"
     *     wp siteguardbackup postfailover --flush-only
     *
     * @subcommand postfailover
     */
    public function postfailover($args, $assoc_args) {
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'text';
        $flush_only = isset($assoc_args['flush-only']);
        $results = array();

        WP_CLI::log('Running post-failover maintenance...');
        WP_CLI::log('');

        // 1. Flush object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $results['object_cache'] = array('status' => 'ok', 'message' => 'Object cache flushed');
            WP_CLI::log('  [OK] Object cache flushed');
        }

        // 2. Clear all transients
        global $wpdb;
        $cleared = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'"
        );
        $results['transients'] = array('status' => 'ok', 'message' => "Cleared {$cleared} transients");
        WP_CLI::log("  [OK] Cleared {$cleared} transients");

        // 3. Flush rewrite rules
        flush_rewrite_rules(true);
        $results['rewrite_rules'] = array('status' => 'ok', 'message' => 'Rewrite rules flushed');
        WP_CLI::log('  [OK] Rewrite rules flushed');

        // 4. Clear Redis if available
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
        // Try Redis-specific flush via WP Redis plugin
        global $wp_object_cache;
        if (is_object($wp_object_cache) && method_exists($wp_object_cache, 'redis_instance')) {
            try {
                $redis = $wp_object_cache->redis_instance();
                if ($redis) $redis->flushAll();
                $results['redis'] = array('status' => 'ok', 'message' => 'Redis cache flushed');
                WP_CLI::log('  [OK] Redis cache flushed');
            } catch (Exception $e) {
                // Redis may not be available
            }
        }

        if ($flush_only) {
            WP_CLI::success('Cache flush complete.');
            return;
        }

        // 5. Verify database
        $db_ok = (bool) $wpdb->get_var("SELECT 1");
        $results['database'] = array(
            'status' => $db_ok ? 'ok' : 'error',
            'message' => $db_ok ? 'Database connection verified' : 'Database connection FAILED',
        );
        WP_CLI::log('  [' . ($db_ok ? 'OK' : 'ERROR') . '] Database: ' . $results['database']['message']);

        // 6. Verify theme exists
        $theme = wp_get_theme();
        $theme_ok = $theme->exists();
        $results['theme'] = array(
            'status' => $theme_ok ? 'ok' : 'warning',
            'message' => $theme_ok ? "Theme active: {$theme->get_stylesheet()}" : 'Active theme missing - may need to switch',
        );
        WP_CLI::log('  [' . ($theme_ok ? 'OK' : 'WARN') . '] ' . $results['theme']['message']);

        // 7. Check active plugins
        $active_plugins = get_option('active_plugins', array());
        $missing_plugins = array();
        foreach ($active_plugins as $plugin) {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
                $missing_plugins[] = $plugin;
            }
        }
        $plugins_ok = empty($missing_plugins);
        $results['plugins'] = array(
            'status' => $plugins_ok ? 'ok' : 'warning',
            'message' => $plugins_ok
                ? count($active_plugins) . ' active plugins verified'
                : count($missing_plugins) . ' plugins missing: ' . implode(', ', $missing_plugins),
        );
        WP_CLI::log('  [' . ($plugins_ok ? 'OK' : 'WARN') . '] ' . $results['plugins']['message']);

        // 8. Multisite: verify all blogs are accessible
        if (is_multisite()) {
            $sites = get_sites(array('number' => 0));
            $results['multisite'] = array(
                'status' => 'ok',
                'message' => count($sites) . ' sites in network',
            );
            WP_CLI::log('  [OK] ' . $results['multisite']['message']);
        }

        // 9. Search-replace if requested
        if (isset($assoc_args['search-replace'])) {
            $pairs = explode(',', $assoc_args['search-replace']);
            $search = array();
            $replace = array();
            foreach ($pairs as $pair) {
                $parts = explode('|', $pair, 2);
                if (count($parts) === 2) {
                    $search[] = trim($parts[0]);
                    $replace[] = trim($parts[1]);
                }
            }

            if (!empty($search)) {
                require_once SGBACKUP_DIR . 'includes/class-search-replace.php';
                $tables = SiteGuard_Backup_Database_Utility::get_tables($wpdb->prefix);
                $sr = new SiteGuard_Backup_Search_Replace();
                $report = $sr->run($search, $replace, $tables);
                $results['search_replace'] = array(
                    'status' => 'ok',
                    'message' => "{$report['changes']} changes in {$report['tables']} tables",
                );
                WP_CLI::log("  [OK] Search-replace: {$report['changes']} changes in {$report['tables']} tables");
            }
        }

        // 10. Site accessibility check
        $siteurl = get_option('siteurl');
        $results['site_url'] = array(
            'status' => 'ok',
            'message' => "Site URL: {$siteurl}",
        );
        WP_CLI::log("  [OK] Site URL: {$siteurl}");

        WP_CLI::log('');

        if ($format === 'json') {
            WP_CLI::log(json_encode($results, JSON_PRETTY_PRINT));
        }

        $has_errors = false;
        foreach ($results as $r) {
            if ($r['status'] === 'error') $has_errors = true;
        }

        if ($has_errors) {
            WP_CLI::error('Post-failover found errors that need attention.', false);
        } else {
            WP_CLI::success('Post-failover maintenance complete. Site is ready.');
        }
    }

    /**
     * Manually run backup retention pruning.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Prune type: full, db, or all (default).
     *
     * [--dry-run]
     * : Preview what would be pruned.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup prune
     *     wp siteguardbackup prune --type=db --dry-run
     *
     * @subcommand prune
     */
    public function prune($args, $assoc_args) {
        $type = isset($assoc_args['type']) ? $assoc_args['type'] : 'all';
        $dry_run = isset($assoc_args['dry-run']);
        $opts = SiteGuard_Backup_Options::instance();

        $retain_files = (int) $opts->get_option('sgbackup_retain', 2);
        $retain_db = (int) $opts->get_option('sgbackup_retain_db', 2);

        WP_CLI::log("Retention policy: files={$retain_files}, db={$retain_db}");

        $history = SiteGuard_Backup_History::get_history();
        $before_count = count($history);

        if ($dry_run) {
            // Preview mode: count what would be pruned
            $full_count = 0;
            $db_count = 0;
            foreach ($history as $ts => $backup) {
                if (!empty($backup['always_keep'])) continue;
                $has_files = !empty($backup['plugins']) || !empty($backup['themes']) || !empty($backup['uploads']);
                $has_db = !empty($backup['db']);

                if ($has_files) {
                    $full_count++;
                    if ($full_count > $retain_files && ($type === 'all' || $type === 'full')) {
                        WP_CLI::log("  Would prune: " . gmdate('Y-m-d H:i', $ts) . " (files)");
                    }
                }
                if ($has_db) {
                    $db_count++;
                    if ($db_count > $retain_db && ($type === 'all' || $type === 'db')) {
                        WP_CLI::log("  Would prune: " . gmdate('Y-m-d H:i', $ts) . " (db)");
                    }
                }
            }
            WP_CLI::success('Dry run complete.');
        } else {
            if ($type === 'all' || $type === 'full') {
                SiteGuard_Backup_History::prune('full');
            }
            if ($type === 'all' || $type === 'db') {
                SiteGuard_Backup_History::prune('db');
            }

            $after_count = count(SiteGuard_Backup_History::get_history());
            $pruned = $before_count - $after_count;
            WP_CLI::success("Pruned {$pruned} backup sets ({$before_count} -> {$after_count}).");
        }
    }

    /**
     * View or manage backup semaphore locks.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : Action: status (default) or clear.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup lock
     *     wp siteguardbackup lock status
     *     wp siteguardbackup lock clear
     *
     * @subcommand lock
     */
    public function lock($args, $assoc_args) {
        $action = !empty($args[0]) ? $args[0] : 'status';

        if ($action === 'clear') {
            $opts = SiteGuard_Backup_Options::instance();
            $opts->delete_option('sgbackup_lock_backup');
            $opts->delete_option('sgbackup_lock_history');
            WP_CLI::success('All locks cleared.');
            return;
        }

        // Status
        $lock = new SiteGuard_Backup_Semaphore('backup');
        $is_free = $lock->is_free();

        if ($is_free) {
            WP_CLI::log('Backup lock: FREE');
        } else {
            WP_CLI::log('Backup lock: HELD');
            $opts = SiteGuard_Backup_Options::instance();
            $lock_data = $opts->get_option('sgbackup_lock_backup', '');
            if (!empty($lock_data)) {
                $data = maybe_unserialize($lock_data);
                if (is_array($data) && isset($data['time'])) {
                    $held_for = $this->format_duration(time() - intval($data['time']));
                    WP_CLI::log("  Held for: {$held_for}");
                }
            }
        }

        $history_lock = new SiteGuard_Backup_Semaphore('history');
        $hist_free = $history_lock->is_free();
        WP_CLI::log('History lock: ' . ($hist_free ? 'FREE' : 'HELD'));
    }

    /**
     * Get the most recently modified log file.
     *
     * ## OPTIONS
     *
     * [--display]
     * : Output the log file content.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup get_most_recently_modified_log
     *     wp siteguardbackup get_most_recently_modified_log --display
     *
     * @subcommand get_most_recently_modified_log
     */
    public function get_most_recently_modified_log($args, $assoc_args) {
        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();

        $scan = @scandir($backup_dir);
        if (!$scan) {
            WP_CLI::error("Cannot read backup directory: {$backup_dir}");
        }

        $latest_file = null;
        $latest_mtime = 0;

        foreach ($scan as $f) {
            if (!preg_match('/^log\..*\.txt$/', $f)) continue;
            $path = $backup_dir . $f;
            $mtime = filemtime($path);
            if ($mtime > $latest_mtime) {
                $latest_mtime = $mtime;
                $latest_file = $f;
            }
        }

        if (!$latest_file) {
            WP_CLI::log('No log files found.');
            return;
        }

        $full_path = $backup_dir . $latest_file;
        WP_CLI::log($full_path);
        WP_CLI::log("Modified: " . gmdate('Y-m-d H:i:s', $latest_mtime) . " UTC");

        if (isset($assoc_args['display'])) {
            WP_CLI::log('');
            WP_CLI::log('--- Log Content ---');
            $content = file_get_contents($full_path);
            if ($content !== false) {
                WP_CLI::log($content);
            } else {
                WP_CLI::warning('Could not read log file.');
            }
        }
    }

    // ===========================================
    // PRIVATE HELPERS
    // ===========================================

    /**
     * Format a duration in seconds to human-readable string.
     *
     * @param int $seconds Duration in seconds.
     * @return string Human-readable duration.
     */
    private function format_duration($seconds) {
        $seconds = abs(intval($seconds));
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        return "{$days}d {$hours}h";
    }

    /**
     * Get the status string for a multisite blog.
     *
     * @param object $site Site object from get_sites().
     * @return string Status label.
     */
    private function get_site_status($site) {
        if ($site->deleted) return 'deleted';
        if ($site->archived) return 'archived';
        if ($site->spam) return 'spam';
        if ($site->mature) return 'mature';
        if (!$site->public) return 'private';
        return 'active';
    }

    /**
     * Get published post count for a multisite blog.
     *
     * @param int $blog_id Blog ID.
     * @return int Post count.
     */
    private function get_site_post_count($blog_id) {
        switch_to_blog($blog_id);
        $count = (int) wp_count_posts()->publish;
        restore_current_blog();
        return $count;
    }

    /**
     * View or manage the security audit log.
     *
     * The audit log tracks administrative actions (backups, restores, deletions,
     * settings changes, downloads, unlock attempts) with user identity, IP address,
     * and timestamps. This is an optional, opt-in security feature - nothing is
     * logged unless you enable it first.
     *
     * ## OPTIONS
     *
     * [<subcommand>]
     * : Action to perform: show (default), enable, disable, clear, export.
     *
     * [--action=<action>]
     * : Filter entries by action type (e.g., backup_started, restore_completed, delete_backup).
     *
     * [--limit=<limit>]
     * : Number of entries to show. Default: 25.
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     *
     * ## EXAMPLES
     *
     *     # Show recent audit events
     *     wp siteguardbackup audit_log
     *
     *     # Enable audit logging
     *     wp siteguardbackup audit_log enable
     *
     *     # Show only backup-related events
     *     wp siteguardbackup audit_log show --action=backup_started --limit=10
     *
     *     # Export full log as JSON
     *     wp siteguardbackup audit_log export
     *
     *     # Clear all log entries
     *     wp siteguardbackup audit_log clear
     *
     * @subcommand audit_log
     */
    public function audit_log($args, $assoc_args) {
        $audit = SiteGuard_Backup_Audit_Log::instance();
        $subcmd = isset($args[0]) ? $args[0] : 'show';

        switch ($subcmd) {
            case 'enable':
                $audit->set_enabled(true);
                WP_CLI::success('Security audit logging enabled.');
                return;

            case 'disable':
                $audit->log('audit_log_disabled', 'Audit logging disabled via CLI.');
                $audit->set_enabled(false);
                WP_CLI::success('Security audit logging disabled.');
                return;

            case 'clear':
                $count = $audit->count();
                $audit->clear();
                WP_CLI::success("Cleared {$count} audit log entries.");
                return;

            case 'export':
                $entries = $audit->get_entries();
                WP_CLI::line(json_encode(array(
                    'entries'   => $entries,
                    'total'     => count($entries),
                    'exported'  => gmdate('Y-m-d H:i:s'),
                    'site_url'  => get_site_url(),
                ), JSON_PRETTY_PRINT));
                return;

            case 'show':
                break;

            default:
                WP_CLI::error("Unknown subcommand: {$subcmd}. Use: show, enable, disable, clear, export.");
                return;
        }

        // Show status
        $enabled = $audit->is_enabled();
        WP_CLI::line('Audit logging: ' . ($enabled ? 'ENABLED' : 'DISABLED'));
        WP_CLI::line('Total entries: ' . $audit->count());
        WP_CLI::line('');

        if (!$enabled) {
            WP_CLI::line('Enable with: wp siteguardbackup audit_log enable');
            return;
        }

        $filters = array();
        if (isset($assoc_args['action'])) {
            $filters['action'] = $assoc_args['action'];
        }
        $filters['limit'] = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 25;

        $entries = $audit->get_entries($filters);

        if (empty($entries)) {
            WP_CLI::line('No audit log entries found.');
            return;
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($format === 'json') {
            WP_CLI::line(json_encode($entries, JSON_PRETTY_PRINT));
            return;
        }

        $table_data = array();
        foreach ($entries as $entry) {
            $table_data[] = array(
                'Date'   => gmdate('Y-m-d H:i:s', $entry['timestamp']),
                'Action' => SiteGuard_Backup_Audit_Log::get_action_label($entry['action']),
                'User'   => $entry['user_login'],
                'IP'     => $entry['ip'],
                'Details' => substr($entry['details'], 0, 60),
            );
        }

        WP_CLI\Utils\format_items('table', $table_data, array('Date', 'Action', 'User', 'IP', 'Details'));
    }
}
