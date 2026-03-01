<?php
/**
 * SiteGuard Backup - Job Scheduler
 *
 * Manages resumption/retry via WP-Cron for long-running backup operations.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Job_Scheduler {

	/**
	 * Record that something useful happened during this resumption.
	 * Schedules the next resumption if needed.
	 */
	public static function something_useful_happened() {
		$sgbackup = SiteGuard_Backup::instance();
		$sgbackup->something_useful_happened = true;

		$resumption = $sgbackup->current_resumption;
		$nonce = $sgbackup->nonce;
		$resume_interval = $sgbackup->jobdata_get('resume_interval', 300);

		// Track this resumption's check-in
		$useful_checkins = $sgbackup->jobdata_get('useful_checkins', array());
		$useful_checkins[$resumption] = time();
		if (count($useful_checkins) > 20) {
			$useful_checkins = array_slice($useful_checkins, -20, 20, true);
		}
		$sgbackup->jobdata_set('useful_checkins', $useful_checkins);

		// For resumptions >= 9, ensure next one is scheduled
		if ($resumption >= 9 && !$sgbackup->newresumption_scheduled) {
			self::schedule_resumption($resumption + 1, $nonce, $resume_interval);
			$sgbackup->newresumption_scheduled = true;
		}
	}

	/**
	 * Record that the backup is still alive (heartbeat).
	 */
	public static function record_still_alive() {
		$sgbackup = SiteGuard_Backup::instance();
		$resumption = $sgbackup->current_resumption;

		$run_times = $sgbackup->jobdata_get('run_times', array());
		$run_times[$resumption] = microtime(true) - $sgbackup->job_time_ms;
		$sgbackup->jobdata_set('run_times', $run_times);
	}

	/**
	 * Schedule the next resumption.
	 *
	 * @param int    $resumption_no The resumption number to schedule.
	 * @param string $nonce         The backup job nonce.
	 * @param int    $interval      Seconds from now.
	 */
	public static function schedule_resumption($resumption_no, $nonce, $interval) {
		$interval = max(1, intval($interval));
		$time = time() + $interval;

		// Clear any existing scheduled resumption for this resumption_no
		wp_clear_scheduled_hook('sgbackup_backup_resume', array($resumption_no, $nonce));

		wp_schedule_single_event($time, 'sgbackup_backup_resume', array($resumption_no, $nonce));

		$sgbackup = SiteGuard_Backup::instance();
		$sgbackup->log("Scheduled resumption {$resumption_no} in {$interval}s");
	}

	/**
	 * Reschedule the next resumption with a new interval.
	 *
	 * @param int $how_far_ahead Seconds from now.
	 */
	public static function reschedule($how_far_ahead) {
		$sgbackup = SiteGuard_Backup::instance();
		$nonce = $sgbackup->nonce;
		$next_resumption = $sgbackup->current_resumption + 1;

		$how_far_ahead = max(60, $how_far_ahead);

		// Clear existing
		wp_clear_scheduled_hook('sgbackup_backup_resume', array($next_resumption, $nonce));

		// Schedule new
		wp_schedule_single_event(time() + $how_far_ahead, 'sgbackup_backup_resume', array($next_resumption, $nonce));

		$sgbackup->newresumption_scheduled = true;
		$sgbackup->log("Rescheduled resumption {$next_resumption} in {$how_far_ahead}s");
	}

	/**
	 * Increase the resume interval and reschedule.
	 *
	 * @param int $increase_by Seconds to add to the interval.
	 */
	public static function increase_resume_and_reschedule($increase_by) {
		$sgbackup = SiteGuard_Backup::instance();
		$resume_interval = $sgbackup->jobdata_get('resume_interval', 300);
		$new_interval = $resume_interval + $increase_by;
		$sgbackup->jobdata_set('resume_interval', $new_interval);

		self::reschedule($new_interval);

		$sgbackup->log("Increased resume interval to {$new_interval}s (+{$increase_by}s)");
	}

	/**
	 * Check if we should terminate due to overlapping activity.
	 *
	 * @param string $reason
	 * @param int    $time_now
	 * @param int    $last_activity
	 * @return bool True if should terminate.
	 */
	public static function terminate_due_to_activity($reason, $time_now, $last_activity) {
		$sgbackup = SiteGuard_Backup::instance();
		$resume_interval = $sgbackup->jobdata_get('resume_interval', 300);

		// If another resumption was active recently, back off
		if (($time_now - $last_activity) < $resume_interval) {
			$sgbackup->log("Terminating: {$reason} (last activity " . ($time_now - $last_activity) . "s ago, interval={$resume_interval}s)");

			// Increase interval to reduce conflicts
			self::increase_resume_and_reschedule(120);
			return true;
		}

		return false;
	}

	/**
	 * Reschedule if the next resumption is too close.
	 */
	public static function reschedule_if_needed() {
		$sgbackup = SiteGuard_Backup::instance();
		$nonce = $sgbackup->nonce;
		$next_resumption = $sgbackup->current_resumption + 1;

		$next_scheduled = wp_next_scheduled('sgbackup_backup_resume', array($next_resumption, $nonce));
		if ($next_scheduled && ($next_scheduled - time()) < 45) {
			self::reschedule(45);
		}
	}

	/**
	 * Get the initial resume interval for a new backup job.
	 */
	public static function get_initial_resume_interval() {
		// Default: 300 seconds (5 minutes)
		// Reduced on fast servers, increased on slow ones
		$interval = 300;

		// If WP_CRON_LOCK_TIMEOUT is set, use it as a hint
		if (defined('WP_CRON_LOCK_TIMEOUT') && WP_CRON_LOCK_TIMEOUT > 0) {
			$interval = max($interval, WP_CRON_LOCK_TIMEOUT + 30);
		}

		return apply_filters('sgbackup_initial_resume_interval', $interval);
	}

	/**
	 * Clear all scheduled events for a backup job.
	 */
	public static function clear_scheduled_events($nonce) {
		// Directly scan cron array and remove ALL events matching this nonce,
		// regardless of resumption number. This avoids type mismatch issues
		// and the consecutive-miss heuristic that leaves orphan events.
		$crons = _get_cron_array();
		if (empty($crons)) return;

		$modified = false;
		foreach ($crons as $time => $hooks) {
			if (!isset($hooks['sgbackup_backup_resume'])) continue;
			foreach ($hooks['sgbackup_backup_resume'] as $hash => $event) {
				if (isset($event['args'][1]) && (string) $event['args'][1] === (string) $nonce) {
					unset($crons[$time]['sgbackup_backup_resume'][$hash]);
					$modified = true;
				}
			}
			// Clean up empty hook arrays
			if (isset($crons[$time]['sgbackup_backup_resume']) && empty($crons[$time]['sgbackup_backup_resume'])) {
				unset($crons[$time]['sgbackup_backup_resume']);
			}
			if (empty($crons[$time])) {
				unset($crons[$time]);
			}
		}

		if ($modified) {
			_set_cron_array($crons);
		}
	}

	/**
	 * Check if an abort has been requested for the current job.
	 *
	 * @param string $nonce
	 * @return bool
	 */
	public static function is_abort_requested($nonce) {
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$flag_file = $backup_dir . 'deleteflag-' . $nonce . '.txt';
		return file_exists($flag_file);
	}

	/**
	 * Request abort for a backup job.
	 *
	 * @param string $nonce
	 */
	public static function request_abort($nonce) {
		// Sanitize nonce to prevent path traversal
		$nonce = preg_replace('/[^a-zA-Z0-9_-]/', '', $nonce);
		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$flag_file = $backup_dir . 'deleteflag-' . $nonce . '.txt';
		$result = file_put_contents($flag_file, time());
		if ($result === false) {
			$sgbackup = SiteGuard_Backup::instance();
			$sgbackup->log("Failed to write abort flag file: {$flag_file}", 'error');
		}
	}

	/**
	 * Get the list of currently active backup jobs.
	 *
	 * @return array
	 */
	public static function get_active_jobs() {
		$crons = _get_cron_array();
		$jobs = array();

		if (empty($crons)) return $jobs;

		foreach ($crons as $time => $hooks) {
			if (isset($hooks['sgbackup_backup_resume'])) {
				foreach ($hooks['sgbackup_backup_resume'] as $hash => $event) {
					$args = $event['args'];
					if (count($args) >= 2) {
						$resumption_no = $args[0];
						$nonce = $args[1];
						// Keep the earliest (soonest) scheduled resumption per nonce
						if (!isset($jobs[$nonce]) || $time < $jobs[$nonce]['next_scheduled']) {
							$jobs[$nonce] = array(
								'resumption' => $resumption_no,
								'next_scheduled' => $time,
								'nonce' => $nonce,
							);
						}
					}
				}
			}
		}

		return $jobs;
	}
}
