<?php
/**
 * SiteGuard Backup - WP-CLI Commands
 *
 * Commands: backup, list, restore, status, verify, export, import, prefailover
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
            $entities = array_map('trim', explode(',', $assoc_args['include-files']));
            $valid = array('plugins', 'themes', 'uploads', 'others', 'wpcore');
            $options['entities'] = array_intersect($entities, $valid);
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
                'date'     => date('Y-m-d H:i', $timestamp),
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
            ($schedule['next_files'] ? ' (next: ' . date('Y-m-d H:i', $schedule['next_files']) . ')' : ''));
        WP_CLI::log("  Database:    {$schedule['interval_database']}" .
            ($schedule['next_database'] ? ' (next: ' . date('Y-m-d H:i', $schedule['next_database']) . ')' : ''));
        WP_CLI::log("  Incremental: {$schedule['interval_incremental']}" .
            ($schedule['next_incremental'] ? ' (next: ' . date('Y-m-d H:i', $schedule['next_incremental']) . ')' : ''));

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
                'date'       => date('Y-m-d H:i:s', $timestamp),
                'nonce'      => isset($latest['nonce']) ? $latest['nonce'] : '',
                'entities'   => $entities,
                'file_sizes' => $file_sizes,
                'total_size' => SiteGuard_Backup_Filesystem::format_bytes($total_size),
            );
            WP_CLI::log(json_encode($output, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::log("Latest backup:");
            WP_CLI::log("  Timestamp: {$timestamp}");
            WP_CLI::log("  Date:      " . date('Y-m-d H:i:s', $timestamp));
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
                WP_CLI::log("Discovered: {$nonce} (" . date('Y-m-d H:i', $ts) . ")");
            }
        } else {
            // Remote: iterate configured storage methods
            $sgbackup = SiteGuard_Backup::instance();
            $service = $opts->get_option('sgbackup_service', array('none'));
            if (!is_array($service)) $service = array($service);

            foreach ($service as $method_id) {
                if ($method_id === 'none') continue;

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
        WP_CLI::log("Modified: " . date('Y-m-d H:i:s', $latest_mtime));

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
}
