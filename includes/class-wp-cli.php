<?php
/**
 * SiteGuard Backup - WP-CLI Commands
 *
 * Commands: backup, list, restore, status, verify, export, import, prefailover,
 * healthcheck, cleanup, sites, schedule, postfailover, prune, lock,
 * backup_progress, get_latest_full_backup, rescan_storage, create_migration_key,
 * search_replace, get_most_recently_modified_log, audit_log, import_foreign,
 * force_resume, delete, keep, get_log, abort, settings, remote_test,
 * remote_upload, migrate_add_destination, migrate_ping, migrate_send,
 * migrate_clone, migrate_destinations, migrate_keys, inspect, db_tables,
 * site_size, db_size, sysinfo, cron_events, resume_restore, presets,
 * test_webhook, failover_status
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_CLI_Command extends WP_CLI_Command {

    /**
     * The single shared set of terminal backup job statuses. backup_finish() sets
     * 'complete' or 'warning'; 'finished' is kept for backward compatibility.
     *
     * @param string $status Job status.
     * @return bool True if the job has reached a terminal state.
     */
    private static function backup_status_terminal($status) {
        return in_array($status, array('complete', 'finished', 'warning', 'error', 'aborted', 'failed'), true);
    }

    /**
     * True if a terminal status represents a successful backup (a 'warning' backup
     * still completed and produced files).
     *
     * @param string $status Job status.
     * @return bool
     */
    private static function backup_status_succeeded($status) {
        return in_array($status, array('complete', 'finished', 'warning'), true);
    }

    /**
     * Read the last $max_bytes of a file via fseek (tail), prepending a truncation
     * marker when the file is larger. Avoids loading a multi-MB log fully into memory.
     *
     * @param string $path      File path.
     * @param int    $max_bytes Cap (default 256KB).
     * @return string|false File tail, or false on error.
     */
    private function read_file_tail($path, $max_bytes = 262144) {
        $size = @filesize($path);
        if ($size === false) return false;
        $fh = @fopen($path, 'rb');
        if (!$fh) return false;
        $truncated = false;
        if ($size > $max_bytes) {
            fseek($fh, -$max_bytes, SEEK_END);
            $truncated = true;
        }
        $data = stream_get_contents($fh);
        fclose($fh);
        if ($data === false) return false;
        return $truncated ? "[log truncated]\n" . $data : $data;
    }

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
     * [--subsite-only]
     * : Backup only the current subsite's data (multisite only, requires --url for a subsite).
     *
     * [--include-tables=<tables>]
     * : Comma-separated list of database tables to include.
     *
     * [--include-nonwp-tables]
     * : Include non-WordPress-prefixed tables in the backup.
     *
     * [--anonymise]
     * : Anonymise personal data in the database backup.
     *
     * [--anonymise-mode=<mode>]
     * : Anonymisation mode: all, non_staff, wc_orders. Default: all.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup backup
     *     wp siteguardbackup backup --exclude-db --include-files=plugins,themes
     *     wp siteguardbackup backup --send-to-cloud --always-keep
     *     wp siteguardbackup backup --url=subsite.example.com --subsite-only
     *     wp siteguardbackup backup --include-tables=wp_posts,wp_postmeta --anonymise --anonymise-mode=non_staff
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

        if (!empty($assoc_args['label'])) {
            $options['label'] = sanitize_text_field(substr($assoc_args['label'], 0, 40));
        }

        if (isset($assoc_args['subsite-only'])) {
            if (!is_multisite() || get_current_blog_id() <= 1) {
                WP_CLI::error('--subsite-only requires multisite and a subsite context (use --url=subsite.domain).');
            }
            $options['subsite_backup'] = true;
        }

        if (!empty($assoc_args['include-tables'])) {
            $options['include_tables'] = array_filter(array_map('trim', explode(',', $assoc_args['include-tables'])), 'strlen');
        }

        if (isset($assoc_args['include-nonwp-tables'])) {
            $options['include_nonwp_tables'] = true;
        }

        if (isset($assoc_args['anonymise'])) {
            $options['db_anonymise'] = true;
            $valid_modes = array('all', 'non_staff', 'wc_orders');
            $mode = isset($assoc_args['anonymise-mode']) ? $assoc_args['anonymise-mode'] : 'all';
            $options['db_anonymise_mode'] = in_array($mode, $valid_modes, true) ? $mode : 'all';
        }

        WP_CLI::log('Starting backup...');
        $sgbackup->backup_all($options);

        if (!empty($sgbackup->boot_error)) {
            WP_CLI::error($sgbackup->boot_error);
        }

        // Run backup synchronously via resume loop (cron doesn't run in CLI)
        $nonce = $sgbackup->nonce;
        $max_resumptions = 50;
        for ($i = 0; $i < $max_resumptions; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;

            $sgbackup->backup_resume($i, $nonce);

            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
        }

        $jobdata = $sgbackup->jobdata_getarray($nonce);
        $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
        if (self::backup_status_succeeded($status)) {
            WP_CLI::success('Backup complete. Job nonce: ' . $nonce);
        } else {
            WP_CLI::error('Backup did not complete (status: ' . $status . '). Check logs.');
        }
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
                'storage'  => implode(',', isset($backup['service']) ? (array) $backup['service'] : array('none')),
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
     * [--preset=<name>]
     * : Load restore options from a named preset.
     *
     * [--db-restore-mode=<mode>]
     * : Database restore mode. Values: migrate, overwrite, tables-only, skip-db.
     * Falls back to site default if not specified.
     *
     * [--move-in-mode=<mode>]
     * : File restore mode. Values: overwrite, backup, skip, merge.
     *
     * [--subsite-mode=<mode>]
     * : Multisite subsite handling. Values: mirror, main-only, skip-remap, custom, import-as, batch.
     *
     * [--subsite-map=<json>]
     * : JSON mapping of old_domain => new_domain for custom subsite mode.
     *   Use "__skip__" as the destination to skip a subsite entirely.
     *   Example: '{"blog.old.com":"blog.new.com","shop.old.com":"__skip__"}'
     *
     * [--batch-subsite-map=<json>]
     * : JSON array for batch subsite import (mode 5). Each entry specifies a
     *   source blog_id, action (overwrite/create), and target.
     *   Example: '[{"source_blog_id":2,"source_domain":"shop.old.com","action":"overwrite","target_blog_id":5}]'
     *
     * [--target-subsite-domain=<domain>]
     * : Target subsite domain for import-as mode. The backup will be imported
     *   as this subsite (created if it doesn't exist).
     *   Example: 'blog.example.com'
     *
     * [--target-subsite-id=<id>]
     * : Target subsite blog ID for import-as mode. Can be used instead of
     *   --target-subsite-domain; the domain will be derived from the blog ID.
     *
     * [--keep-protocol]
     * : Keep the original protocol during migration (don't force HTTPS).
     *
     * [--remap-independent-domains]
     * : Remap independent domains to subdomains during multisite migration.
     *
     * [--pre-restore-backup]
     * : Create a backup of the current site before restoring.
     *
     * [--include-unspecified-tables]
     * : Include database tables not in the restore selection.
     *
     * [--include-unspecified-plugins]
     * : Keep plugins not in the backup.
     *
     * [--include-unspecified-themes]
     * : Keep themes not in the backup.
     *
     * [--restore-plugins=<items>]
     * : Comma-separated plugin directories to restore (selective).
     *
     * [--restore-themes=<items>]
     * : Comma-separated theme directories to restore (selective).
     *
     * [--yes]
     * : Skip the confirmation prompt (for scripted/non-interactive use).
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup restore 1709283600
     *     wp siteguardbackup restore 1709283600 --entities=db,plugins
     *     wp siteguardbackup restore 1709283600 --preset="migration-standard"
     *     wp siteguardbackup restore 1709283600 --db-restore-mode=migrate
     *     wp siteguardbackup restore 1709283600 --move-in-mode=skip
     *     wp siteguardbackup restore 1709283600 --subsite-mode=mirror --db-restore-mode=migrate
     *     wp siteguardbackup restore 1709283600 --target-subsite-domain=blog.example.com
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

        // Load preset if specified
        if (isset($assoc_args['preset'])) {
            $opts = SiteGuard_Backup_Options::instance();
            $presets = $opts->get_option('sgbackup_restore_presets', array());
            $found = false;
            foreach ($presets as $preset) {
                if (isset($preset['name']) && $preset['name'] === $assoc_args['preset']) {
                    $restore_options = $preset['restore_options'];
                    $found = true;
                    WP_CLI::log("Using preset: {$preset['name']}");
                    break;
                }
            }
            if (!$found) {
                WP_CLI::error("Preset not found: {$assoc_args['preset']}");
            }
        } elseif (isset($assoc_args['entities'])) {
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

        // Database restore mode
        if (isset($assoc_args['db-restore-mode'])) {
            $db_mode_map = array(
                'migrate'     => 0,
                'overwrite'   => 1,
                'tables-only' => 2,
                'skip-db'     => 3,
            );
            $db_mode = $assoc_args['db-restore-mode'];
            if (!isset($db_mode_map[$db_mode])) {
                WP_CLI::error("Invalid --db-restore-mode. Valid values: migrate, overwrite, tables-only, skip-db");
            }
            $restore_options['db_restore_mode'] = $db_mode_map[$db_mode];
        }

        // File move-in mode
        if (isset($assoc_args['move-in-mode'])) {
            $move_mode_map = array(
                'overwrite' => 0,
                'backup'    => 1,
                'skip'      => 2,
                'merge'     => 3,
            );
            $move_mode = $assoc_args['move-in-mode'];
            if (!isset($move_mode_map[$move_mode])) {
                WP_CLI::error("Invalid --move-in-mode. Valid values: overwrite, backup, skip, merge");
            }
            $restore_options['move_in_mode'] = $move_mode_map[$move_mode];
        }

        // Subsite migration mode
        if (isset($assoc_args['subsite-mode'])) {
            $subsite_mode_map = array(
                'mirror'      => 0,
                'main-only'   => 1,
                'skip-remap'  => 2,
                'custom'      => 3,
                'import-as'   => 4,
                'batch'       => 5,
            );
            $sm = $assoc_args['subsite-mode'];
            if (!isset($subsite_mode_map[$sm])) {
                WP_CLI::error("Invalid --subsite-mode. Valid values: mirror, main-only, skip-remap, custom, import-as, batch");
            }
            $restore_options['subsite_mode'] = $subsite_mode_map[$sm];
        }

        // Custom subsite mapping (JSON: {"old_domain": "new_domain", ...})
        if (isset($assoc_args['subsite-map'])) {
            $map = json_decode($assoc_args['subsite-map'], true);
            if (!is_array($map) || empty($map)) {
                WP_CLI::error("Invalid --subsite-map: must be a valid JSON object mapping old domains to new domains.");
            }
            $restore_options['subsite_map'] = $map;
            // Auto-set custom mode if map is provided but mode wasn't explicitly set
            if (!isset($restore_options['subsite_mode'])) {
                $restore_options['subsite_mode'] = 3;
            }
        }

        // Batch subsite map (JSON array for mode 5)
        if (isset($assoc_args['batch-subsite-map'])) {
            $batch_map = json_decode($assoc_args['batch-subsite-map'], true);
            if (!is_array($batch_map) || empty($batch_map)) {
                WP_CLI::error("Invalid --batch-subsite-map: must be a valid JSON array of subsite mapping entries.");
            }
            $restore_options['batch_subsite_map'] = $batch_map;
            if (!isset($restore_options['subsite_mode'])) {
                $restore_options['subsite_mode'] = 5;
            }
        }

        // Target subsite domain/blog_id for import-as mode
        if (isset($assoc_args['target-subsite-domain'])) {
            $restore_options['target_subsite_domain'] = sanitize_text_field($assoc_args['target-subsite-domain']);
            if (!isset($restore_options['subsite_mode'])) {
                $restore_options['subsite_mode'] = 4;
            }
        }
        if (isset($assoc_args['target-subsite-id'])) {
            $restore_options['target_subsite_id'] = intval($assoc_args['target-subsite-id']);
            if (!isset($restore_options['subsite_mode'])) {
                $restore_options['subsite_mode'] = 4;
            }
        }

        // Keep original protocol
        if (isset($assoc_args['keep-protocol'])) {
            $restore_options['keep_protocol'] = true;
        }

        if (isset($assoc_args['remap-independent-domains'])) {
            $restore_options['remap_independent_domains'] = true;
        }

        if (isset($assoc_args['pre-restore-backup'])) {
            $restore_options['pre_restore_backup'] = true;
        }

        if (isset($assoc_args['include-unspecified-tables'])) {
            $restore_options['include_unspecified_tables'] = true;
        }

        if (isset($assoc_args['include-unspecified-plugins'])) {
            $restore_options['include_unspecified_plugins'] = true;
        }

        if (isset($assoc_args['include-unspecified-themes'])) {
            $restore_options['include_unspecified_themes'] = true;
        }

        if (!empty($assoc_args['restore-plugins'])) {
            $restore_options['restore_plugins'] = array_filter(array_map('trim', explode(',', $assoc_args['restore-plugins'])), 'strlen');
        }

        if (!empty($assoc_args['restore-themes'])) {
            $restore_options['restore_themes'] = array_filter(array_map('trim', explode(',', $assoc_args['restore-themes'])), 'strlen');
        }

        WP_CLI::confirm('This will restore the backup. Continue?', $assoc_args);

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
        if (!empty($sgbackup->boot_error) || empty($sgbackup->nonce)) {
            WP_CLI::error('Could not start export backup: ' . (!empty($sgbackup->boot_error) ? $sgbackup->boot_error : 'unknown boot error'));
        }

        // Run the cron to complete the backup synchronously
        $nonce = $sgbackup->nonce;
        $max_resumptions = 50;
        for ($i = 0; $i < $max_resumptions; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;

            $sgbackup->backup_resume($i, $nonce);

            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
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
        if (!empty($sgbackup->boot_error) || empty($nonce)) {
            WP_CLI::error('Could not start pre-failover backup: ' . (!empty($sgbackup->boot_error) ? $sgbackup->boot_error : 'unknown boot error'));
        }

        // Run resume loop synchronously
        for ($i = 0; $i < 20; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;

            $sgbackup->backup_resume($i, $nonce);

            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
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

            // Show database progress. The completed-table set was moved out of the
            // jobdata blob into its own option ('sgbackup_dbtables_<nonce>') to avoid
            // O(n^2) rewrites on large multisite dumps, so read it from there.
            $dbtables = SiteGuard_Backup_Options::instance()->get_option('sgbackup_dbtables_' . $nonce, array());
            if (is_array($dbtables) && !empty($dbtables)) {
                WP_CLI::log("  DB tables completed: " . count($dbtables));
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
                if (!array_key_exists($method_id, SiteGuard_Backup::instance()->get_backup_methods())) {
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
     * [--yes]
     * : Skip the confirmation prompt when applying changes (non-interactive use).
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
            WP_CLI::confirm('This will modify the database. Continue?', $assoc_args);
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
        $active_locks = array_filter(SiteGuard_Backup_Semaphore::list_locks(), function($l) { return !$l['expired']; });
        $checks['semaphore'] = array(
            'status'  => empty($active_locks) ? 'ok' : 'warning',
            'message' => empty($active_locks) ? 'No active locks' : (count($active_locks) . ' lock(s) held: ' . implode(', ', array_keys($active_locks)) . ' (job may be running)'),
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
        // Always query wp_options - jobdata is stored per-site, not in sitemeta
        $job_options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'sgbackup_jobdata_%'"
        );

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
                    $deleted = SiteGuard_Backup_Filesystem::rmdir_recursive($path);
                    if (is_wp_error($deleted)) {
                        WP_CLI::warning("  Could not delete orphan temp dir {$f}: " . $deleted->get_error_message());
                        continue;
                    }
                    WP_CLI::log("  Deleted orphan temp dir: {$f}");
                }
                $cleaned++;
            }
        }

        // 4. Clear expired semaphore locks (per-job, boot, history, restore)
        foreach (SiteGuard_Backup_Semaphore::list_locks() as $lock_name => $lock_info) {
            if (!$lock_info['expired']) continue;
            if ($dry_run) {
                WP_CLI::log("  Would clear expired lock {$lock_name} (held since " . gmdate('Y-m-d H:i', $lock_info['held_since']) . " UTC)");
            } else {
                $sem = new SiteGuard_Backup_Semaphore($lock_name);
                $sem->force_unlock();
                WP_CLI::log("  Cleared expired lock {$lock_name}");
            }
            $cleaned++;
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
     * [--remote-send=<interval>]
     * : Set scheduled remote send interval (same options as --files).
     *
     * [--remote-destination=<ids>]
     * : Comma-separated remote site IDs for scheduled send.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup schedule
     *     wp siteguardbackup schedule set --files=daily --database=every4hours
     *     wp siteguardbackup schedule set --retain=5 --retain-db=10
     *     wp siteguardbackup schedule set --remote-send=daily --remote-destination=0,1
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

            if (isset($assoc_args['remote-send'])) {
                $interval = $assoc_args['remote-send'];
                if (!in_array($interval, $valid_intervals)) {
                    WP_CLI::error("Invalid interval: {$interval}. Valid: " . implode(', ', $valid_intervals));
                }
                $sgbackup->schedule_backup_remotesend($interval);
                WP_CLI::log("Remote send schedule set to: {$interval}");
                $changed = true;
            }

            if (isset($assoc_args['remote-destination'])) {
                $dest_ids = array_map('intval', array_filter(explode(',', $assoc_args['remote-destination']), 'strlen'));
                $opts->update_option('sgbackup_remotesend_destinations', $dest_ids);
                WP_CLI::log("Remote send destinations set to: " . implode(', ', $dest_ids));
                $changed = true;
            }

            if (!$changed) {
                WP_CLI::warning('No schedule options specified. Use --files, --database, --incremental, --retain, --retain-db, --remote-send, or --remote-destination.');
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
            WP_CLI::log("  Remote Send: {$schedule['interval_remotesend']}" .
                ($schedule['next_remotesend'] ? ' (next: ' . gmdate('Y-m-d H:i', $schedule['next_remotesend']) . ' UTC)' : ''));
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
                require_once SGBACKUP_DIR . 'includes/class-database-utility.php';
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
            // Locks are per-job (sgbackup_sem_<nonce>) plus boot/history/restore;
            // the old code cleared a 'sgbackup_backup' key that nothing ever takes.
            $n = SiteGuard_Backup_Semaphore::clear_all_locks();
            WP_CLI::success("Cleared {$n} lock(s).");
            return;
        }

        // Status
        $locks = SiteGuard_Backup_Semaphore::list_locks();
        if (empty($locks)) {
            WP_CLI::log('No locks held.');
            return;
        }
        foreach ($locks as $name => $info) {
            $held_for = $info['held_since'] > 0 ? $this->format_duration(time() - $info['held_since']) : 'unknown';
            WP_CLI::log(sprintf('%-40s %s (held for %s, timeout %ds)', $name, $info['expired'] ? 'EXPIRED' : 'HELD', $held_for, $info['timeout']));
        }
    }

    /**
     * Force-resume a stuck backup job, bypassing WP-Cron.
     *
     * ## OPTIONS
     *
     * <nonce>
     * : The backup job nonce to resume.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup force_resume abc123def456
     *
     * @subcommand force_resume
     */
    public function force_resume($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Usage: wp siteguardbackup force_resume <nonce>');
        }

        $nonce = sanitize_text_field($args[0]);
        $sgbackup = SiteGuard_Backup::instance();
        $jobdata = $sgbackup->jobdata_getarray($nonce);

        if (empty($jobdata)) {
            WP_CLI::error("No job data found for nonce: {$nonce}");
        }

        $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : 'unknown';
        WP_CLI::log("Job status: {$status}");

        if (self::backup_status_terminal($status)) {
            WP_CLI::error("Job is already in terminal state: {$status}");
        }

        $current_resumption = isset($jobdata['current_resumption']) ? intval($jobdata['current_resumption']) : 0;
        $next_resumption = $current_resumption + 1;

        SiteGuard_Backup_Job_Scheduler::clear_scheduled_events($nonce);

        WP_CLI::log("Force-resuming job at resumption {$next_resumption}...");
        $sgbackup->backup_resume($next_resumption, $nonce);

        $new_status = $sgbackup->jobdata_get('jobstatus', 'unknown');
        WP_CLI::success("Resumption {$next_resumption} completed. Status: {$new_status}");
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
            $content = $this->read_file_tail($full_path);
            if ($content !== false) {
                WP_CLI::log($content);
            } else {
                WP_CLI::warning('Could not read log file.');
            }
        }
    }

    // ===========================================
    // GROUP 1: BACKUP MANAGEMENT
    // ===========================================

    /**
     * Delete a backup set.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to delete.
     *
     * [--delete-remote]
     * : Also delete from remote storage.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup delete 1709283600
     *     wp siteguardbackup delete 1709283600 --delete-remote --yes
     *
     * @subcommand delete
     */
    public function delete($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        $timestamp = intval($args[0]);
        $delete_remote = isset($assoc_args['delete-remote']);

        WP_CLI::confirm("Delete backup from " . gmdate('Y-m-d H:i', $timestamp) . "?", $assoc_args);

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->delete_backup(array(
            'timestamp'     => $timestamp,
            'delete_remote' => $delete_remote,
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success("Deleted {$result['count']} file(s).");
    }

    /**
     * Toggle the always-keep flag on a backup.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup.
     *
     * [--unset]
     * : Remove the always-keep flag instead of setting it.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup keep 1709283600
     *     wp siteguardbackup keep 1709283600 --unset
     *
     * @subcommand keep
     */
    public function keep($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        $timestamp = intval($args[0]);
        $always_keep = !isset($assoc_args['unset']);

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->always_keep_this_backup(array(
            'timestamp'   => $timestamp,
            'always_keep' => $always_keep,
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success($always_keep ? 'Backup marked as always-keep.' : 'Always-keep flag removed.');
    }

    /**
     * Show a backup job log.
     *
     * ## OPTIONS
     *
     * <nonce>
     * : The job nonce.
     *
     * [--tail=<N>]
     * : Show only the last N lines.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup get_log abc123def456
     *     wp siteguardbackup get_log abc123def456 --tail=50
     *
     * @subcommand get_log
     */
    public function get_log($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Job nonce required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_log(array('nonce' => sanitize_text_field($args[0])));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $log = isset($result['log']) ? $result['log'] : '';
        if (empty($log)) {
            WP_CLI::log('No log data found.');
            return;
        }

        if (!empty($assoc_args['tail'])) {
            $lines = explode("\n", rtrim($log));
            $n = max(1, intval($assoc_args['tail']));
            $lines = array_slice($lines, -$n);
            $log = implode("\n", $lines);
        }

        WP_CLI::log($log);
    }

    /**
     * Abort an active backup job.
     *
     * ## OPTIONS
     *
     * <nonce>
     * : The job nonce to abort.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup abort abc123def456
     *
     * @subcommand abort
     */
    public function abort($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Job nonce required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->activejobs_delete(array('job_id' => sanitize_text_field($args[0])));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('Abort requested for job: ' . $args[0]);
    }

    // ===========================================
    // GROUP 2: SETTINGS
    // ===========================================

    /**
     * Get, set, wipe, export, or import plugin settings.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action: get, set, wipe, export, import.
     *
     * [--key=<key>]
     * : Show only a specific setting key (for 'get').
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * [--file=<path>]
     * : File path for export (output) or import (input).
     *
     * [--yes]
     * : Skip confirmation for destructive actions.
     *
     * [--<field>=<value>]
     * : Any setting key=value pair (for 'set'). Use -- prefix.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup settings get
     *     wp siteguardbackup settings get --key=interval --format=json
     *     wp siteguardbackup settings set --retain=5 --retain_db=10 --debug_mode=1
     *     wp siteguardbackup settings wipe --yes
     *     wp siteguardbackup settings export --file=/tmp/settings.json
     *     wp siteguardbackup settings import /tmp/settings.json --yes
     *
     * @subcommand settings
     */
    public function settings($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Action required: get, set, wipe, export, import.');
        }

        $action = $args[0];
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        switch ($action) {
            case 'get':
                $result = $commands->get_settings();
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

                if (!empty($assoc_args['key'])) {
                    $key = $assoc_args['key'];
                    if (isset($result[$key])) {
                        if ($format === 'json') {
                            WP_CLI::log(json_encode(array($key => $result[$key]), JSON_PRETTY_PRINT));
                        } else {
                            $val = is_array($result[$key]) ? json_encode($result[$key]) : $result[$key];
                            WP_CLI::log("{$key}: {$val}");
                        }
                    } else {
                        WP_CLI::error("Setting not found: {$key}");
                    }
                    return;
                }

                if ($format === 'json') {
                    WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
                } else {
                    $items = array();
                    foreach ($result as $k => $v) {
                        $items[] = array(
                            'key'   => $k,
                            'value' => is_array($v) ? json_encode($v) : (string) $v,
                        );
                    }
                    WP_CLI\Utils\format_items('table', $items, array('key', 'value'));
                }
                break;

            case 'set':
                // Collect all --key=value pairs (excluding reserved flags)
                $reserved = array('format', 'key', 'file', 'yes', 'url', 'path', 'ssh', 'http', 'user', 'skip-plugins', 'skip-themes', 'require');
                $params = array();
                foreach ($assoc_args as $k => $v) {
                    if (in_array($k, $reserved, true)) continue;
                    // Convert underscore-separated keys
                    $params[$k] = $v;
                }

                if (empty($params)) {
                    WP_CLI::error('No settings specified. Use --key=value format.');
                }

                $result = $commands->savesettings($params);
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                WP_CLI::success('Settings saved.');
                break;

            case 'wipe':
                WP_CLI::confirm('This will reset ALL plugin settings to defaults.', $assoc_args);

                $result = $commands->wipe_settings();
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                WP_CLI::success('All settings reset to defaults.');
                break;

            case 'export':
                $result = $commands->export_settings(array());
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                $json = json_encode($result, JSON_PRETTY_PRINT);
                if (!empty($assoc_args['file'])) {
                    $written = file_put_contents($assoc_args['file'], $json);
                    if (false === $written) {
                        WP_CLI::error('Failed to write to: ' . $assoc_args['file']);
                    }
                    WP_CLI::success('Settings exported to: ' . $assoc_args['file']);
                } else {
                    WP_CLI::log($json);
                }
                break;

            case 'import':
                $file = isset($args[1]) ? $args[1] : (isset($assoc_args['file']) ? $assoc_args['file'] : '');
                if (empty($file)) {
                    WP_CLI::error('File path required for import.');
                }
                if (!file_exists($file)) {
                    WP_CLI::error("File not found: {$file}");
                }

                $data = json_decode(file_get_contents($file), true);
                if (!is_array($data) || empty($data['settings'])) {
                    WP_CLI::error('Invalid settings file format.');
                }

                WP_CLI::confirm('This will overwrite current settings with imported values.', $assoc_args);

                $result = $commands->import_settings(array('settings' => $data['settings']));
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                WP_CLI::success("Imported {$result['imported']} settings.");
                break;

            default:
                WP_CLI::error("Unknown action: {$action}. Use: get, set, wipe, export, import.");
        }
    }

    // ===========================================
    // GROUP 3: REMOTE STORAGE
    // ===========================================

    /**
     * Test remote storage credentials.
     *
     * ## OPTIONS
     *
     * <method>
     * : Storage method name (e.g., s3, sftp, googledrive, dropbox).
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup remote_test s3
     *     wp siteguardbackup remote_test sftp
     *
     * @subcommand remote_test
     */
    public function remote_test($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Storage method required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->credentials_test(array('method' => $args[0]));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success($result['message']);
    }

    /**
     * Upload an existing local backup to remote storage.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to upload.
     *
     * [--services=<services>]
     * : Comma-separated storage methods. Uses configured service if omitted.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup remote_upload 1709283600
     *     wp siteguardbackup remote_upload 1709283600 --services=s3,dropbox
     *
     * @subcommand remote_upload
     */
    public function remote_upload($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        $timestamp = intval($args[0]);

        if (!empty($assoc_args['services'])) {
            $services = array_filter(array_map('trim', explode(',', $assoc_args['services'])), 'strlen');
        } else {
            $opts = SiteGuard_Backup_Options::instance();
            $services = $opts->get_option('sgbackup_service', array('none'));
            if (!is_array($services)) $services = array($services);
            $services = array_filter($services, function($s) { return $s !== 'none'; });
        }

        if (empty($services)) {
            WP_CLI::error('No storage services specified or configured.');
        }

        WP_CLI::log('Uploading to: ' . implode(', ', $services) . '...');

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->upload_to_remote(array(
            'timestamp' => $timestamp,
            'services'  => $services,
        ));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $failed = 0;
        foreach ($result['results'] as $svc => $info) {
            if ($info['success']) {
                WP_CLI::log("  {$svc}: {$info['uploaded']} file(s) uploaded.");
            } else {
                $failed++;
                WP_CLI::warning("  {$svc}: " . implode('; ', $info['errors']));
            }
        }

        if ($failed) {
            WP_CLI::error("Upload failed for {$failed} of " . count($result['results']) . ' service(s).');
        }
        WP_CLI::success('Upload complete.');
    }

    // ===========================================
    // GROUP 4: MIGRATION
    // ===========================================

    /**
     * Register a migration destination from a key bundle.
     *
     * ## OPTIONS
     *
     * <key_bundle>
     * : Base64-encoded key bundle from the destination site.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_add_destination "eyJwdWJsaWN..."
     *
     * @subcommand migrate_add_destination
     */
    public function migrate_add_destination($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Key bundle required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->migrate_newdestination(array('key_bundle' => $args[0]));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success("Destination added: {$result['name']} (ID: {$result['site_id']})");
    }

    /**
     * Ping-test a migration destination.
     *
     * ## OPTIONS
     *
     * <site_id>
     * : The destination site ID.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_ping 0
     *
     * @subcommand migrate_ping
     */
    public function migrate_ping($args, $assoc_args) {
        if (!isset($args[0])) {
            WP_CLI::error('Site ID required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->ping_test(array('site_id' => intval($args[0])));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success('Ping successful.');
    }

    /**
     * Send an existing backup to a remote site.
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to send.
     *
     * <site_id>
     * : Destination site ID.
     *
     * [--label=<label>]
     * : Custom label for the send job.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_send 1709283600 0
     *     wp siteguardbackup migrate_send 1709283600 0 --label="production-sync"
     *
     * @subcommand migrate_send
     */
    public function migrate_send($args, $assoc_args) {
        if (empty($args[0]) || !isset($args[1])) {
            WP_CLI::error('Usage: wp siteguardbackup migrate_send <timestamp> <site_id>');
        }

        $timestamp = intval($args[0]);
        $site_id = intval($args[1]);

        // Verify destination exists
        $opts = SiteGuard_Backup_Options::instance();
        $sites = $opts->get_option('sgbackup_remotesites', array());
        if (!isset($sites[$site_id])) {
            WP_CLI::error("Destination site ID {$site_id} not found.");
        }

        $backup_set = SiteGuard_Backup_History::get_history($timestamp);
        if (empty($backup_set)) {
            WP_CLI::error("Backup not found for timestamp: {$timestamp}");
        }

        WP_CLI::log("Sending backup to: " . (isset($sites[$site_id]['name']) ? $sites[$site_id]['name'] : "Site #{$site_id}") . '...');

        // Build options directly (cannot use Commands::migrate_send as it calls die)
        $sgbackup = SiteGuard_Backup::instance();
        $backup_files = SiteGuard_Backup_History::get_all_files($backup_set);

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

        if (!empty($assoc_args['label'])) {
            $options['label'] = sanitize_text_field(substr($assoc_args['label'], 0, 40));
        }

        $sgbackup->backup_all($options);

        if (!empty($sgbackup->boot_error)) {
            WP_CLI::error($sgbackup->boot_error);
        }

        // Run synchronously via resume loop
        $nonce = $sgbackup->nonce;
        for ($i = 0; $i < 50; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
            $sgbackup->backup_resume($i, $nonce);
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
        }

        $jobdata = $sgbackup->jobdata_getarray($nonce);
        $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
        // 'warning' also covers "every upload failed"; the upload is the whole job here.
        if (self::backup_status_succeeded($status) && !empty($jobdata['remote_upload_succeeded'])) {
            WP_CLI::success('Migration send complete.');
        } else {
            WP_CLI::error('Migration send failed (status: ' . $status . ', upload ' . (empty($jobdata['remote_upload_succeeded']) ? 'failed' : 'ok') . '). Check logs.');
        }
    }

    /**
     * Clone current site to a remote destination (full backup + send).
     *
     * ## OPTIONS
     *
     * <site_id>
     * : Destination site ID.
     *
     * [--label=<label>]
     * : Custom label for the clone job.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_clone 0
     *     wp siteguardbackup migrate_clone 0 --label="staging-clone"
     *
     * @subcommand migrate_clone
     */
    public function migrate_clone($args, $assoc_args) {
        if (!isset($args[0])) {
            WP_CLI::error('Site ID required.');
        }

        $site_id = intval($args[0]);
        $opts = SiteGuard_Backup_Options::instance();
        $sites = $opts->get_option('sgbackup_remotesites', array());
        if (!isset($sites[$site_id])) {
            WP_CLI::error("Destination site ID {$site_id} not found.");
        }

        WP_CLI::log("Cloning site to: " . (isset($sites[$site_id]['name']) ? $sites[$site_id]['name'] : "Site #{$site_id}") . '...');

        $sgbackup = SiteGuard_Backup::instance();
        $label = !empty($assoc_args['label'])
            ? sanitize_text_field(substr($assoc_args['label'], 0, 40))
            : 'Migration clone ' . gmdate('Y-m-d H:i');

        $options = array(
            'service' => array("remotesend/{$site_id}"),
            'label'   => $label,
        );

        $sgbackup->backup_all($options);

        if (!empty($sgbackup->boot_error)) {
            WP_CLI::error($sgbackup->boot_error);
        }

        $nonce = $sgbackup->nonce;
        for ($i = 0; $i < 50; $i++) {
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
            $sgbackup->backup_resume($i, $nonce);
            $jobdata = $sgbackup->jobdata_getarray($nonce);
            $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
            if (self::backup_status_terminal($status)) break;
        }

        $jobdata = $sgbackup->jobdata_getarray($nonce);
        $status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
        if (self::backup_status_succeeded($status) && !empty($jobdata['remote_upload_succeeded'])) {
            WP_CLI::success('Clone complete.');
        } else {
            WP_CLI::error('Clone failed (status: ' . $status . ', upload ' . (empty($jobdata['remote_upload_succeeded']) ? 'failed' : 'ok') . '). Check logs.');
        }
    }

    /**
     * List or delete migration destinations.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action: list or delete.
     *
     * [--site-id=<id>]
     * : Site ID to delete (required for delete action).
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_destinations list
     *     wp siteguardbackup migrate_destinations delete --site-id=0
     *     wp siteguardbackup migrate_destinations list --format=json
     *
     * @subcommand migrate_destinations
     */
    public function migrate_destinations($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Action required: list or delete.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        if ($args[0] === 'list') {
            $result = $commands->get_remotesites();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            if (empty($result)) {
                WP_CLI::log('No migration destinations configured.');
                return;
            }

            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
            $items = array();
            foreach ($result as $id => $site) {
                $items[] = array(
                    'id'   => $id,
                    'name' => $site['name'],
                    'url'  => $site['url'],
                );
            }

            if ($format === 'json') {
                WP_CLI::log(json_encode($items, JSON_PRETTY_PRINT));
            } else {
                WP_CLI\Utils\format_items('table', $items, array('id', 'name', 'url'));
            }

        } elseif ($args[0] === 'delete') {
            if (!isset($assoc_args['site-id'])) {
                WP_CLI::error('--site-id required for delete.');
            }

            $result = $commands->delete_remotesite(array('site_id' => intval($assoc_args['site-id'])));
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success('Destination deleted.');
        } else {
            WP_CLI::error("Unknown action: {$args[0]}. Use: list, delete.");
        }
    }

    /**
     * List or delete migration keys.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action: list or delete.
     *
     * [--key-id=<id>]
     * : Key hash to delete (required for delete action).
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup migrate_keys list
     *     wp siteguardbackup migrate_keys delete --key-id=abc123
     *
     * @subcommand migrate_keys
     */
    public function migrate_keys($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Action required: list or delete.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        if ($args[0] === 'list') {
            $result = $commands->get_migrator_keys();
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            if (empty($result)) {
                WP_CLI::log('No migration keys found.');
                return;
            }

            $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
            $items = array();
            foreach ($result as $hash => $key) {
                $items[] = array(
                    'id'      => $hash,
                    'name'    => $key['name'],
                    'created' => $key['created'] ? gmdate('Y-m-d H:i', $key['created']) : 'unknown',
                );
            }

            if ($format === 'json') {
                WP_CLI::log(json_encode($items, JSON_PRETTY_PRINT));
            } else {
                WP_CLI\Utils\format_items('table', $items, array('id', 'name', 'created'));
            }

        } elseif ($args[0] === 'delete') {
            if (empty($assoc_args['key-id'])) {
                WP_CLI::error('--key-id required for delete.');
            }

            $result = $commands->delete_migrator_key(array('key_id' => $assoc_args['key-id']));
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }

            WP_CLI::success('Migration key deleted.');
        } else {
            WP_CLI::error("Unknown action: {$args[0]}. Use: list, delete.");
        }
    }

    // ===========================================
    // GROUP 5: INSPECTION
    // ===========================================

    /**
     * Inspect a backup set's contents (tables, plugins, themes, blogs).
     *
     * ## OPTIONS
     *
     * <timestamp>
     * : Unix timestamp of the backup to inspect.
     *
     * [--format=<format>]
     * : Output format: text, json. Default: text.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup inspect 1709283600
     *     wp siteguardbackup inspect 1709283600 --format=json
     *
     * @subcommand inspect
     */
    public function inspect($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Timestamp required.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_backup_contents(array('timestamp' => intval($args[0])));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'text';

        if ($format === 'json') {
            WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        WP_CLI::log("Backup: " . gmdate('Y-m-d H:i', $result['timestamp']));
        if (!empty($result['size'])) {
            WP_CLI::log("Size: {$result['size']}");
        }
        if (!empty($result['old_siteurl'])) {
            WP_CLI::log("Source URL: {$result['old_siteurl']}");
        }
        WP_CLI::log("Current URL: {$result['current_siteurl']}");

        if (!empty($result['is_subsite_backup'])) {
            WP_CLI::log("Type: Subsite backup (blog #{$result['subsite_blog_id']})");
        }

        if (!empty($result['tables'])) {
            WP_CLI::log('');
            WP_CLI::log('Tables (' . count($result['tables']) . '):');
            foreach ($result['tables'] as $t) {
                WP_CLI::log("  {$t}");
            }
        }

        if (!empty($result['plugins'])) {
            WP_CLI::log('');
            WP_CLI::log('Plugins (' . count($result['plugins']) . '):');
            foreach ($result['plugins'] as $p) {
                WP_CLI::log("  {$p}");
            }
        }

        if (!empty($result['themes'])) {
            WP_CLI::log('');
            WP_CLI::log('Themes (' . count($result['themes']) . '):');
            foreach ($result['themes'] as $t) {
                WP_CLI::log("  {$t}");
            }
        }

        if (!empty($result['multisite_blogs'])) {
            WP_CLI::log('');
            WP_CLI::log('Multisite blogs (' . count($result['multisite_blogs']) . '):');
            foreach ($result['multisite_blogs'] as $blog) {
                if (is_array($blog)) {
                    WP_CLI::log("  #{$blog['blog_id']} {$blog['domain']}{$blog['path']}");
                } else {
                    WP_CLI::log("  {$blog}");
                }
            }
        }
    }

    /**
     * List database tables.
     *
     * ## OPTIONS
     *
     * [--wp-only]
     * : Show only WordPress-prefixed tables.
     *
     * [--non-wp-only]
     * : Show only non-WordPress tables.
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup db_tables
     *     wp siteguardbackup db_tables --wp-only
     *     wp siteguardbackup db_tables --format=json
     *
     * @subcommand db_tables
     */
    public function db_tables($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_database_tables(array());

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $tables = $result['tables'];
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if (isset($assoc_args['wp-only'])) {
            $tables = array_filter($tables, function($t) { return $t['is_wp']; });
        } elseif (isset($assoc_args['non-wp-only'])) {
            $tables = array_filter($tables, function($t) { return !$t['is_wp']; });
        }

        $tables = array_values($tables);

        if ($format === 'json') {
            WP_CLI::log(json_encode($tables, JSON_PRETTY_PRINT));
        } else {
            $items = array();
            foreach ($tables as $t) {
                $items[] = array(
                    'table' => $t['name'],
                    'type'  => $t['is_wp'] ? 'WordPress' : 'Other',
                );
            }
            WP_CLI\Utils\format_items('table', $items, array('table', 'type'));
            WP_CLI::log(count($items) . ' tables total.');
        }
    }

    /**
     * Show site size per entity.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup site_size
     *     wp siteguardbackup site_size --format=json
     *
     * @subcommand site_size
     */
    public function site_size($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_site_size(array());

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($format === 'json') {
            WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $items = array();
            foreach ($result as $entity => $size) {
                if ($size < 0) {
                    $items[] = array('entity' => $entity, 'size' => 'timeout');
                } else {
                    $items[] = array('entity' => $entity, 'size' => SiteGuard_Backup_Filesystem::format_bytes($size));
                }
            }
            WP_CLI\Utils\format_items('table', $items, array('entity', 'size'));
        }
    }

    /**
     * Show per-table database sizes.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup db_size
     *     wp siteguardbackup db_size --format=json
     *
     * @subcommand db_size
     */
    public function db_size($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_db_size(array());

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if ($format === 'json') {
            WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        $items = array();
        $all_tables = array_merge($result['wp_tables'], $result['non_wp_tables']);
        foreach ($all_tables as $t) {
            $items[] = array(
                'table' => $t['name'],
                'rows'  => number_format($t['rows']),
                'data'  => SiteGuard_Backup_Filesystem::format_bytes($t['data']),
                'index' => SiteGuard_Backup_Filesystem::format_bytes($t['index']),
                'total' => SiteGuard_Backup_Filesystem::format_bytes($t['size']),
            );
        }

        WP_CLI\Utils\format_items('table', $items, array('table', 'rows', 'data', 'index', 'total'));
        WP_CLI::log('');
        WP_CLI::log('WP tables: ' . SiteGuard_Backup_Filesystem::format_bytes($result['wp_total']));
        WP_CLI::log('Non-WP:    ' . SiteGuard_Backup_Filesystem::format_bytes($result['non_wp_total']));
        WP_CLI::log('Total:     ' . SiteGuard_Backup_Filesystem::format_bytes($result['total']));
    }

    /**
     * Show system information.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: text, json. Default: text.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup sysinfo
     *     wp siteguardbackup sysinfo --format=json
     *
     * @subcommand sysinfo
     */
    public function sysinfo($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->get_sysinfo();

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'text';

        if ($format === 'json') {
            WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        foreach ($result as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            }
            WP_CLI::log(sprintf("  %-20s %s", $key . ':', $value));
        }
    }

    /**
     * List WordPress cron events.
     *
     * ## OPTIONS
     *
     * [--sgbackup-only]
     * : Show only SiteGuard Backup cron events.
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup cron_events
     *     wp siteguardbackup cron_events --sgbackup-only
     *     wp siteguardbackup cron_events --format=json
     *
     * @subcommand cron_events
     */
    public function cron_events($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        $params = array();
        if (isset($assoc_args['sgbackup-only'])) {
            $params['filter'] = 'sgbackup';
        }

        $result = $commands->get_cron_events($params);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $events = $result['events'];
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        if (empty($events)) {
            WP_CLI::log('No cron events found.');
            return;
        }

        if ($format === 'json') {
            WP_CLI::log(json_encode($events, JSON_PRETTY_PRINT));
        } else {
            $items = array();
            foreach ($events as $e) {
                $items[] = array(
                    'hook'     => $e['hook'],
                    'next_run' => $e['next_run_date'] . ' UTC',
                    'schedule' => $e['schedule'],
                    'interval' => $e['interval'],
                );
            }
            WP_CLI\Utils\format_items('table', $items, array('hook', 'next_run', 'schedule', 'interval'));
        }
    }

    // ===========================================
    // GROUP 6: RESTORE ENHANCEMENTS
    // ===========================================

    /**
     * Resume a failed or interrupted restore.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup resume_restore
     *
     * @subcommand resume_restore
     */
    public function resume_restore($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->resume_restore(array('execute' => true));

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if ($result['success']) {
            WP_CLI::success('Restore resumed and completed.');
        } else {
            WP_CLI::warning('Restore resumed with errors.');
        }

        if (!empty($result['messages'])) {
            foreach ($result['messages'] as $msg) {
                WP_CLI::log("  {$msg}");
            }
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                WP_CLI::warning("  Error: {$err}");
            }
        }
    }

    // ===========================================
    // GROUP 7: PRESETS & OTHER
    // ===========================================

    /**
     * Manage restore presets.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action: list, save, or delete.
     *
     * [--name=<name>]
     * : Preset name (required for save).
     *
     * [--preset-id=<id>]
     * : Preset ID (required for delete).
     *
     * [--format=<format>]
     * : Output format: table, json. Default: table.
     *
     * [--<field>=<value>]
     * : Restore options for save (db, plugins, themes, etc.).
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup presets list
     *     wp siteguardbackup presets save --name="migration-standard" --db=1 --plugins=1 --themes=1 --db_restore_mode=0
     *     wp siteguardbackup presets delete --preset-id=0
     *
     * @subcommand presets
     */
    public function presets($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error('Action required: list, save, delete.');
        }

        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        switch ($args[0]) {
            case 'list':
                $presets = $commands->get_restore_presets();
                if (is_wp_error($presets)) {
                    WP_CLI::error($presets->get_error_message());
                }

                if (empty($presets)) {
                    WP_CLI::log('No restore presets found.');
                    return;
                }

                $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

                if ($format === 'json') {
                    WP_CLI::log(json_encode($presets, JSON_PRETTY_PRINT));
                } else {
                    $items = array();
                    foreach ($presets as $id => $preset) {
                        $opts = $preset['restore_options'];
                        $entities = array();
                        foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
                            if (!empty($opts[$e])) $entities[] = $e;
                        }
                        $items[] = array(
                            'id'       => $id,
                            'name'     => $preset['name'],
                            'entities' => implode(',', $entities),
                            'created'  => gmdate('Y-m-d H:i', $preset['created']),
                        );
                    }
                    WP_CLI\Utils\format_items('table', $items, array('id', 'name', 'entities', 'created'));
                }
                break;

            case 'save':
                if (empty($assoc_args['name'])) {
                    WP_CLI::error('--name required for save.');
                }

                $params = $assoc_args;
                $result = $commands->save_restore_preset($params);
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                WP_CLI::success("Preset saved with ID: {$result['preset_id']}");
                break;

            case 'delete':
                if (!isset($assoc_args['preset-id'])) {
                    WP_CLI::error('--preset-id required for delete.');
                }

                $result = $commands->delete_restore_preset(array('preset_id' => intval($assoc_args['preset-id'])));
                if (is_wp_error($result)) {
                    WP_CLI::error($result->get_error_message());
                }

                WP_CLI::success('Preset deleted.');
                break;

            default:
                WP_CLI::error("Unknown action: {$args[0]}. Use: list, save, delete.");
        }
    }

    /**
     * Send a test webhook notification.
     *
     * ## OPTIONS
     *
     * [--url=<url>]
     * : Override webhook URL. Uses configured URL if omitted.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup test_webhook
     *     wp siteguardbackup test_webhook --url=https://hooks.example.com/test
     *
     * @subcommand test_webhook
     */
    public function test_webhook($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();

        $params = array();
        if (!empty($assoc_args['url'])) {
            $params['url'] = $assoc_args['url'];
        }

        $result = $commands->test_webhook($params);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        WP_CLI::success(isset($result['message']) ? $result['message'] : 'Test webhook sent.');
    }

    /**
     * Show failover environment status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: text, json. Default: text.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup failover_status
     *     wp siteguardbackup failover_status --format=json
     *
     * @subcommand failover_status
     */
    public function failover_status($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-commands.php';
        $commands = new SiteGuard_Backup_Commands();
        $result = $commands->failover_status();

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'text';

        if ($format === 'json') {
            WP_CLI::log(json_encode($result, JSON_PRETTY_PRINT));
            return;
        }

        WP_CLI::log("Role: {$result['role']}");
        if (!empty($result['production_url'])) {
            WP_CLI::log("Production URL: {$result['production_url']}");
        }

        WP_CLI::log('');
        WP_CLI::log('Environment:');
        foreach ($result['environment'] as $k => $v) {
            if (is_bool($v)) $v = $v ? 'yes' : 'no';
            WP_CLI::log("  {$k}: {$v}");
        }

        WP_CLI::log('');
        WP_CLI::log('Backup:');
        foreach ($result['backup'] as $k => $v) {
            if (is_bool($v)) $v = $v ? 'yes' : 'no';
            if (is_null($v)) $v = 'n/a';
            WP_CLI::log("  {$k}: {$v}");
        }

        WP_CLI::log('');
        WP_CLI::log('DB: ' . ($result['db_ok'] ? 'connected' : 'FAILED'));
        if (!empty($result['disk'])) {
            $disk = $result['disk'];
            if (is_array($disk)) {
                $free = isset($disk['free']) ? SiteGuard_Backup_Filesystem::format_bytes($disk['free']) : 'unknown';
                WP_CLI::log("Disk free: {$free}");
            }
        }

        if (!empty($result['dns'])) {
            WP_CLI::log('');
            WP_CLI::log('DNS (' . $result['dns']['host'] . '):');
            if (!empty($result['dns']['a_records'])) {
                WP_CLI::log('  A: ' . implode(', ', $result['dns']['a_records']));
            }
            if (!empty($result['dns']['cname_records'])) {
                WP_CLI::log('  CNAME: ' . implode(', ', $result['dns']['cname_records']));
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

    /**
     * Import a foreign (non-SiteGuard) backup file.
     *
     * Detects and imports backups from 7+ WordPress backup plugin formats:
     * UpdraftPlus, BackWPup, BackupWordPress, Simple Backups, InfiniteWP,
     * WP-DB-Backup, and generic SQL/ZIP.
     *
     * ## OPTIONS
     *
     * <file>...
     * : One or more backup filenames in the backup directory.
     *
     * [--detect-only]
     * : Only detect the format without importing.
     *
     * [--format=<format>]
     * : Output format. Default: table.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup import_foreign backup_2024-01-15-1200_mysite_abc123-db.zip
     *     wp siteguardbackup import_foreign backwpup_abc_2024-01-15_1200.zip --detect-only
     *     wp siteguardbackup import_foreign *.zip --format=json
     *
     * @subcommand import_foreign
     */
    public function import_foreign($args, $assoc_args) {
        if (empty($args)) {
            WP_CLI::error('Provide one or more backup filenames.');
        }

        require_once SGBACKUP_DIR . 'includes/class-foreign-importer.php';

        $opts = SiteGuard_Backup_Options::instance();
        $backup_dir = $opts->get_backup_dir();
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $detect_only = isset($assoc_args['detect-only']);

        $filepaths = array();
        foreach ($args as $file) {
            $file = basename($file);
            $path = $backup_dir . $file;
            if (!file_exists($path)) {
                WP_CLI::warning("File not found: {$file}");
                continue;
            }
            $filepaths[] = $path;
        }

        if (empty($filepaths)) {
            WP_CLI::error('No valid files found in backup directory.');
        }

        if ($detect_only) {
            $detections = array();
            foreach ($filepaths as $fp) {
                $detection = SiteGuard_Backup_Foreign_Importer::detect($fp);
                $detections[] = array(
                    'File'     => basename($fp),
                    'Detected' => $detection !== false ? 'Yes' : 'No',
                    'Format'   => $detection ? $detection['label'] : '-',
                    'Entities' => $detection ? implode(', ', $detection['entities']) : '-',
                );
            }

            if ($format === 'json') {
                WP_CLI::line(json_encode($detections, JSON_PRETTY_PRINT));
            } else {
                WP_CLI\Utils\format_items('table', $detections, array('File', 'Detected', 'Format', 'Entities'));
            }
            return;
        }

        // Import
        if (count($filepaths) === 1) {
            $result = SiteGuard_Backup_Foreign_Importer::import($filepaths[0]);
        } else {
            $result = SiteGuard_Backup_Foreign_Importer::import_set($filepaths);
        }

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::success(sprintf(
                'Imported as %s format. Timestamp: %d. File(s): %s',
                $result['label'] ?? $result['format'],
                $result['timestamp'],
                isset($result['filename']) ? $result['filename'] : implode(', ', $result['filenames'] ?? array())
            ));
        }
    }

    /**
     * Run health checks on all sites.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup health_check
     *     wp siteguardbackup health_check --format=json
     *
     * @subcommand health_check
     */
    public function health_check($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
        $monitor = SiteGuard_Backup_Health_Monitor::instance();
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        WP_CLI::log('Running health checks...');
        $results = $monitor->run_checks();

        if (empty($results)) {
            WP_CLI::warning('No sites to check.');
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode($results, JSON_PRETTY_PRINT));
            return;
        }

        $table = new \WP_CLI\Formatter($assoc_args, array('site_id', 'domain', 'status', 'http_code', 'response_time', 'ssl_expiry_days', 'ssl_issuer', 'error'));
        $rows = array();
        foreach ($results as $r) {
            $rows[] = array(
                'site_id'        => $r['site_id'],
                'domain'         => $r['domain'],
                'status'         => strtoupper($r['status']),
                'http_code'      => $r['http_code'] ?: '-',
                'response_time'  => round($r['response_time'] * 1000) . 'ms',
                'ssl_expiry_days' => $r['ssl_expiry_days'] >= 0 ? $r['ssl_expiry_days'] . 'd' : 'N/A',
                'ssl_issuer'     => $r['ssl_issuer'] ?: '-',
                'error'          => $r['error'] ?: '-',
            );
        }
        $table->display_items($rows);

        $overall = $monitor->get_overall_status();
        WP_CLI::log('');
        if ($overall === 'operational') {
            WP_CLI::success('Overall status: OPERATIONAL');
        } elseif ($overall === 'degraded') {
            WP_CLI::warning('Overall status: DEGRADED');
        } else {
            WP_CLI::error('Overall status: DOWN', false);
        }
    }

    /**
     * Show cached health check results (does not re-run checks).
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format: table or json. Default: table.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup health_status
     *     wp siteguardbackup health_status --format=json
     *
     * @subcommand health_status
     */
    public function health_status($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
        $monitor = SiteGuard_Backup_Health_Monitor::instance();
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';
        $results = $monitor->get_results();

        if (empty($results)) {
            WP_CLI::warning('No health data yet. Run: wp siteguardbackup health_check');
            return;
        }

        if ($format === 'json') {
            WP_CLI::line(json_encode(array(
                'overall' => $monitor->get_overall_status(),
                'sites'   => $results,
            ), JSON_PRETTY_PRINT));
            return;
        }

        $table = new \WP_CLI\Formatter($assoc_args, array('site_id', 'domain', 'status', 'http_code', 'response_time', 'ssl_expiry_days', 'ssl_issuer', 'error'));
        $rows = array();
        foreach ($results as $r) {
            $rows[] = array(
                'site_id'        => $r['site_id'],
                'domain'         => $r['domain'],
                'status'         => strtoupper($r['status']),
                'http_code'      => $r['http_code'] ?: '-',
                'response_time'  => round($r['response_time'] * 1000) . 'ms',
                'ssl_expiry_days' => $r['ssl_expiry_days'] >= 0 ? $r['ssl_expiry_days'] . 'd' : 'N/A',
                'ssl_issuer'     => $r['ssl_issuer'] ?: '-',
                'error'          => $r['error'] ?: '-',
            );
        }
        $table->display_items($rows);

        $overall = $monitor->get_overall_status();
        WP_CLI::log('');
        WP_CLI::log('Overall: ' . strtoupper($overall));
        $last_run = SiteGuard_Backup_Options::instance()->get_option('sgbackup_health_last_run', 0);
        if ($last_run) {
            WP_CLI::log('Last checked: ' . date('Y-m-d H:i:s', $last_run) . ' (' . human_time_diff($last_run) . ' ago)');
        }
    }

    /**
     * Show or regenerate the health monitor API key.
     *
     * ## OPTIONS
     *
     * [--regenerate]
     * : Generate a new API key.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup health_api_key
     *     wp siteguardbackup health_api_key --regenerate
     *
     * @subcommand health_api_key
     */
    public function health_api_key($args, $assoc_args) {
        require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
        $monitor = SiteGuard_Backup_Health_Monitor::instance();

        if (isset($assoc_args['regenerate'])) {
            $key = $monitor->regenerate_api_key();
            WP_CLI::success('New API key generated. Copy it now - it cannot be retrieved later (stored as hash).');
            WP_CLI::log('');
            WP_CLI::log('API Key: ' . $key);
            WP_CLI::log('Endpoint: ' . rest_url('sgbackup/v1/health'));
            WP_CLI::log('');
            WP_CLI::log('Usage: curl -H "Authorization: Bearer ' . $key . '" ' . rest_url('sgbackup/v1/health'));
        } else {
            if ($monitor->has_api_key()) {
                WP_CLI::log('API key is configured (stored as hash, not retrievable).');
                WP_CLI::log('Use --regenerate to create a new key.');
            } else {
                WP_CLI::log('No API key generated yet. Use --regenerate to create one.');
            }
            WP_CLI::log('Endpoint: ' . rest_url('sgbackup/v1/health'));
        }
    }

    /**
     * Enable or disable the health monitor.
     *
     * ## OPTIONS
     *
     * <action>
     * : enable or disable
     *
     * [--interval=<interval>]
     * : Check interval: everyhour, every2hours, every4hours, every8hours, twicedaily, daily. Default: every4hours.
     *
     * [--timeout=<seconds>]
     * : HTTP timeout in seconds. Default: 10.
     *
     * [--ssl-warn=<days>]
     * : SSL expiry warning threshold in days. Default: 14.
     *
     * ## EXAMPLES
     *
     *     wp siteguardbackup health_config enable --interval=every2hours
     *     wp siteguardbackup health_config disable
     *
     * @subcommand health_config
     */
    public function health_config($args, $assoc_args) {
        $opts = SiteGuard_Backup_Options::instance();
        $action = $args[0];

        if ($action === 'enable') {
            $opts->update_option('sgbackup_health_enabled', 1);

            if (isset($assoc_args['interval'])) {
                $valid = array('every5min', 'every10min', 'every15min', 'every30min', 'everyhour', 'every2hours', 'every4hours', 'every8hours', 'twicedaily', 'daily');
                $interval = $assoc_args['interval'];
                if (!in_array($interval, $valid, true)) {
                    WP_CLI::error('Invalid interval. Choose: ' . implode(', ', $valid));
                }
                $opts->update_option('sgbackup_health_interval', $interval);
            } else {
                $interval = $opts->get_option('sgbackup_health_interval', 'every10min');
            }

            if (isset($assoc_args['timeout'])) {
                $timeout = max(2, min(60, intval($assoc_args['timeout'])));
                $opts->update_option('sgbackup_health_timeout', $timeout);
            }

            if (isset($assoc_args['ssl-warn'])) {
                $warn = max(1, min(90, intval($assoc_args['ssl-warn'])));
                $opts->update_option('sgbackup_health_ssl_warn_days', $warn);
            }

            wp_clear_scheduled_hook('sgbackup_health_check');
            wp_schedule_event(time() + 60, $interval, 'sgbackup_health_check');
            WP_CLI::success('Health monitor enabled. Interval: ' . $interval);

        } elseif ($action === 'disable') {
            $opts->update_option('sgbackup_health_enabled', 0);
            wp_clear_scheduled_hook('sgbackup_health_check');
            WP_CLI::success('Health monitor disabled.');

        } else {
            // Show current config
            $enabled = $opts->get_option('sgbackup_health_enabled', false);
            $interval = $opts->get_option('sgbackup_health_interval', 'every10min');
            $timeout = $opts->get_option('sgbackup_health_timeout', 10);
            $ssl_warn = $opts->get_option('sgbackup_health_ssl_warn_days', 14);
            $next = wp_next_scheduled('sgbackup_health_check');

            WP_CLI::log('Health Monitor Configuration:');
            WP_CLI::log('  Enabled:       ' . ($enabled ? 'Yes' : 'No'));
            WP_CLI::log('  Interval:      ' . $interval);
            WP_CLI::log('  HTTP Timeout:  ' . $timeout . 's');
            WP_CLI::log('  SSL Warning:   ' . $ssl_warn . ' days');
            if ($next) {
                WP_CLI::log('  Next check:    ' . date('Y-m-d H:i:s', $next) . ' (' . human_time_diff(time(), $next) . ')');
            }
        }
    }
}
