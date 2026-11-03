<?php
/**
 * SiteGuard Backup - Job Scheduler
 *
 * Manages resumption/retry via WP-Cron for long-running backup operations.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Job_Scheduler {

	/** @var int Hard ceiling on the adaptive resume interval (seconds). */
	const MAX_RESUME_INTERVAL = 900;

	/** @var int Fixed activity-overlap window (seconds). Decoupled from the
	 *  resume_interval so backoff can't feed on its own ever-growing value. */
	const ACTIVITY_WINDOW = 300;

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

		// --- Adaptive resume interval (shortens the interval again after quick runs) ---
		// run_times records how long each completed resumption actually took. Without
		// this, the interval stays pinned at the 300s default forever, so a host that
		// finishes a batch in ~5s still idles ~5 min between batches (turning a short
		// backup into hours). Re-tune the next interval to ~2x the recent average,
		// bounded to [30, 300]s, and only ever LOWER it here (the activity-backoff path
		// in increase_resume_and_reschedule() still raises it under contention).
		//
		// This is environment self-tuning and safe on constrained hosts (e.g. Azure
		// App Service): slower runtimes -> larger interval automatically, and the host's
		// own wp-cron cadence still gates how soon a resumption can actually fire, so
		// this can never run faster than the host allows. Only batch *timing* changes -
		// batch size and execution behaviour are untouched.
		$run_times = array_filter((array) $sgbackup->jobdata_get('run_times', array()), 'is_numeric');
		if (count($run_times) >= 2) {
			$recent = array_slice($run_times, -5);
			$avg = array_sum($recent) / count($recent);
			$tuned = max(30, min(300, (int) ceil($avg * 2)));
			if ($tuned < $resume_interval) {
				$resume_interval = $tuned;
				$sgbackup->jobdata_set('resume_interval', $resume_interval);
				$sgbackup->log("Resume interval auto-tuned to {$resume_interval}s (avg runtime " . round($avg, 1) . "s)");
			}
		}

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
		// Cap the interval so repeated contention backoffs can't grow it without
		// bound (which would leave the job idling for many minutes per resumption).
		$new_interval = min($resume_interval + $increase_by, self::MAX_RESUME_INTERVAL);
		$sgbackup->jobdata_set('resume_interval', $new_interval);

		self::reschedule($new_interval);

		$sgbackup->log("Increased resume interval to {$new_interval}s (+{$increase_by}s, capped at " . self::MAX_RESUME_INTERVAL . "s)");
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

		// Use a FIXED window, not the ever-growing resume_interval. Basing the
		// trigger on resume_interval created a feedback loop: each backoff raised
		// resume_interval, which widened this window, which triggered more often,
		// which backed off further.
		$window = self::ACTIVITY_WINDOW;

		// If another resumption was active recently, back off
		if (($time_now - $last_activity) < $window) {
			$sgbackup->log("Terminating: {$reason} (last activity " . ($time_now - $last_activity) . "s ago, window={$window}s)");

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
	/**
	 * Is a resumption for this nonce actually queued in WP-Cron? Unlike
	 * get_active_jobs() this never reports the phantom "active job marker"
	 * entry, so callers can decide whether the chain needs re-queuing.
	 */
	public static function has_queued_resumption($nonce) {
		$crons = _get_cron_array();
		if (empty($crons)) return false;
		foreach ($crons as $time => $hooks) {
			if (!isset($hooks['sgbackup_backup_resume'])) continue;
			foreach ($hooks['sgbackup_backup_resume'] as $event) {
				if (isset($event['args'][1]) && (string) $event['args'][1] === (string) $nonce) return true;
			}
		}
		return false;
	}

	public static function get_active_jobs() {
		$crons = _get_cron_array();
		$jobs = array();

		if (!empty($crons)) {
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
		}

		// A scheduled resumption only means "running" if the job itself is still
		// running. A finished job can be left with a pending resumption event
		// (seen after "Backup Now": done in seconds, event due minutes later),
		// which reported a phantom running job and refused the next backup.
		if (!empty($jobs)) {
			if (!class_exists('SiteGuard_Backup')) {
				// Reached from REST/health paths that only loaded the helper classes.
				require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
			}
			$sgbackup = SiteGuard_Backup::instance();
			$terminal = array('complete', 'finished', 'error', 'aborted', 'failed', 'warning');
			foreach (array_keys($jobs) as $nonce) {
				$jobdata = $sgbackup->jobdata_getarray($nonce);
				$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
				if (empty($jobdata) || !empty($jobdata['finalized']) || in_array($status, $terminal, true)) {
					self::clear_scheduled_events($nonce);
					unset($jobs[$nonce]);
				}
			}
		}

		// Fallback: during the direct-chain execution, cron events are briefly
		// cleared and rescheduled between resumptions. If the poll reads the cron
		// during that window, it finds nothing. Check the active job marker set
		// by boot_backup() and verify the job hasn't finished.
		if (empty($jobs)) {
			$active_nonce = get_option('sgbackup_active_job_nonce');
			if (!empty($active_nonce)) {
				if (!class_exists('SiteGuard_Backup')) {
					// Reached from REST/health paths that only loaded the helper classes.
					require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
				}
				$sgbackup = SiteGuard_Backup::instance();
				$jobdata = $sgbackup->jobdata_getarray($active_nonce);
				if (!empty($jobdata)) {
					$status = isset($jobdata['jobstatus']) ? $jobdata['jobstatus'] : '';
					$terminal = array('complete', 'finished', 'error', 'aborted', 'failed', 'warning');
					if (!in_array($status, $terminal, true)) {
						$resumption = isset($jobdata['current_resumption']) ? (int) $jobdata['current_resumption'] : 0;
						$jobs[$active_nonce] = array(
							'resumption' => $resumption,
							'next_scheduled' => time() + 10,
							'nonce' => $active_nonce,
						);
					} else {
						// Job finished but option wasn't cleaned up - clean it now
						delete_option('sgbackup_active_job_nonce');
					}
				} else {
					// Stale option with no jobdata - clean up
					delete_option('sgbackup_active_job_nonce');
				}
			}
		}

		return $jobs;
	}
}
