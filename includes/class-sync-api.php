<?php
/**
 * Sync API: lets a trusted puller (the wpops node agent) create a backup set
 * here, watch it finish, list sets and download their files.
 *
 * Routes (all under /wp-json/sgbackup/v1/sync, bearer sync key):
 *   GET    /ping                       auth smoke test, plugin version, mode
 *   POST   /backup  {label, entities?} start a full backup (service: none); returns the job nonce
 *   GET    /jobs/<nonce>               job status; nudges WP-Cron while the job is running
 *   GET    /sets[?hash=1]              complete full sets with their files (sizes; sha256 when ?hash=1)
 *   GET    /sets/<nonce>/files/<name>  stream a file (HTTP Range supported, so slow links can resume)
 *   DELETE /sets/<nonce>               remove a set after it was copied (optional)
 *
 * The sync key is separate from the health key, stored hashed
 * (sgbackup_sync_api_key_hash) and managed with `wp siteguardbackup sync_api_key`.
 * Failed authentications share the health monitor's per-IP limiter. On
 * multisite only the main site answers (a network backup includes every blog).
 */

if (!defined('ABSPATH')) {
	exit;
}

class SiteGuard_Backup_Sync_API {

	const OPTION_HASH = 'sgbackup_sync_api_key_hash';
	const OPTION_META = 'sgbackup_sync_api_key_meta';
	const NAMESPACE_ = 'sgbackup/v1';
	const STREAM_CHUNK = 1048576; // 1 MiB

	/** @var SiteGuard_Backup_Sync_API|null */
	private static $instance = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ------------------------------------------------------------------ keys

	/**
	 * A key hash can also be pinned in wp-config.php:
	 *   define('SGBACKUP_SYNC_API_KEY_HASH', '$2y$...');   // password_hash($key, PASSWORD_BCRYPT)
	 * for hosts without WP-CLI access (e.g. an App Service managed over FTPS).
	 */
	private function pinned_hash() {
		return defined('SGBACKUP_SYNC_API_KEY_HASH') ? (string) SGBACKUP_SYNC_API_KEY_HASH : '';
	}

	public function has_key() {
		return '' !== $this->pinned_hash()
			|| '' !== (string) SiteGuard_Backup_Options::instance()->get_option(self::OPTION_HASH, '');
	}

	public function regenerate_key() {
		$key = 'sgs_' . bin2hex(random_bytes(24));
		$opts = SiteGuard_Backup_Options::instance();
		$opts->update_option(self::OPTION_HASH, wp_hash_password($key));
		$opts->update_option(self::OPTION_META, array(
			'created'     => time(),
			'fingerprint' => substr(hash('sha256', $key), 0, 12),
			'host'        => (string) wp_parse_url(home_url(), PHP_URL_HOST),
		));
		return $key;
	}

	public function delete_key() {
		$opts = SiteGuard_Backup_Options::instance();
		$opts->delete_option(self::OPTION_HASH);
		$opts->delete_option(self::OPTION_META);
	}

	public function key_meta() {
		$meta = SiteGuard_Backup_Options::instance()->get_option(self::OPTION_META, array());
		return is_array($meta) ? $meta : array();
	}

	public function validate_key($key) {
		$key = (string) $key;
		if ('' === $key || strlen($key) > 200) {
			return false;
		}
		$pinned = $this->pinned_hash();
		if ('' !== $pinned && password_verify($key, $pinned)) {
			return true;
		}
		$hash = (string) SiteGuard_Backup_Options::instance()->get_option(self::OPTION_HASH, '');
		if ('' === $hash) {
			return false;
		}
		return wp_check_password($key, $hash);
	}

	// ---------------------------------------------------------------- routes

	public function register_routes() {
		$ns = self::NAMESPACE_;
		$auth = array($this, 'auth');
		register_rest_route($ns, '/sync/ping', array(
			'methods' => 'GET', 'callback' => array($this, 'ping'), 'permission_callback' => $auth,
		));
		register_rest_route($ns, '/sync/backup', array(
			'methods' => 'POST', 'callback' => array($this, 'start_backup'), 'permission_callback' => $auth,
		));
		register_rest_route($ns, '/sync/jobs/(?P<nonce>[A-Za-z0-9]{4,40})', array(
			'methods' => 'GET', 'callback' => array($this, 'job'), 'permission_callback' => $auth,
		));
		register_rest_route($ns, '/sync/sets', array(
			'methods' => 'GET', 'callback' => array($this, 'sets'), 'permission_callback' => $auth,
		));
		register_rest_route($ns, '/sync/sets/(?P<nonce>[A-Za-z0-9]{4,40})', array(
			'methods' => 'DELETE', 'callback' => array($this, 'delete_set'), 'permission_callback' => $auth,
		));
		register_rest_route($ns, '/sync/sets/(?P<nonce>[A-Za-z0-9]{4,40})/files/(?P<name>backup_[A-Za-z0-9._-]+)', array(
			'methods' => 'GET', 'callback' => array($this, 'file'), 'permission_callback' => $auth,
		));
	}

	public static function no_store($result, $server, $request) {
		$route = strtolower((string) $request->get_route());
		if (0 === strpos($route, '/' . self::NAMESPACE_ . '/sync') && $result instanceof WP_HTTP_Response) {
			$result->header('Cache-Control', 'no-store, private, max-age=0');
			$result->header('Vary', 'Authorization');
		}
		return $result;
	}

	public function auth(WP_REST_Request $request) {
		if (is_multisite() && !is_main_site()) {
			return new WP_Error('wrong_site', 'The sync API answers on the network main site only', array('status' => 404));
		}
		if (!$this->has_key()) {
			return new WP_Error('disabled', 'Sync API has no key (wp siteguardbackup sync_api_key --regenerate)', array('status' => 404));
		}
		require_once SGBACKUP_DIR . 'includes/class-health-monitor.php';
		$monitor = SiteGuard_Backup_Health_Monitor::instance();
		$ip = SiteGuard_Backup_Health_Monitor::get_client_ip();
		if ($monitor->is_rate_limited($ip)) {
			return new WP_Error('rate_limited', 'Too many failed attempts. Try again later.', array('status' => 429));
		}
		$auth = $request->get_header('Authorization');
		if (!$auth || 0 !== strpos($auth, 'Bearer ')) {
			$monitor->record_failed_auth($ip);
			return new WP_Error('unauthorized', 'Missing or invalid Authorization header', array('status' => 401));
		}
		// WordPress calls the permission callback twice per request (once for the
		// Allow header); cache the verdict for this request so bcrypt runs once.
		static $verdict = null;
		if (null === $verdict) {
			$verdict = $this->validate_key(substr($auth, 7));
			if (!$verdict) {
				$monitor->record_failed_auth($ip);
			}
		}
		return $verdict ? true : new WP_Error('forbidden', 'Invalid sync key', array('status' => 403));
	}

	// ------------------------------------------------------------- callbacks

	public function ping(WP_REST_Request $request) {
		return rest_ensure_response(array(
			'ok'          => true,
			'site_url'    => get_site_url(),
			'version'     => SGBACKUP_VERSION,
			'api_version' => 1,
			'mode'        => is_multisite() ? 'multisite' : 'single',
			'sites'       => count($this->site_list()),
			'time'        => time(),
			'active_jobs' => array_keys($this->active_jobs()),
		));
	}

	public function start_backup(WP_REST_Request $request) {
		$this->boot_core();
		$active = $this->active_jobs();
		if (!empty($active)) {
			$nonce = key($active);
			return new WP_Error('busy', 'A backup job is already running: ' . $nonce, array('status' => 409, 'nonce' => $nonce));
		}
		$label = sanitize_text_field(substr((string) $request->get_param('label'), 0, 40));
		$options = array('service' => array('none'), 'label' => '' !== $label ? $label : 'sync', 'sync_api' => true);
		$entities = $request->get_param('entities');
		if (is_array($entities) && !empty($entities)) {
			$valid = array('plugins', 'themes', 'uploads', 'others', 'wpcore');
			$entities = array_values(array_intersect(array_map('strval', $entities), $valid));
			if (!empty($entities)) {
				$options['entities'] = $entities;
			}
		}
		if (false === $request->get_param('db')) {
			$options['nodb'] = true;
		}
		$sg = SiteGuard_Backup::instance();
		$sg->backup_all($options);
		if (!empty($sg->boot_error)) {
			return new WP_Error('boot_failed', (string) $sg->boot_error, array('status' => 500));
		}
		$nonce = (string) $sg->nonce;
		if (function_exists('spawn_cron')) {
			spawn_cron();
		}
		SiteGuard_Backup_Audit_Log::instance()->log('sync_backup_started', 'Sync API started backup ' . $nonce, array('nonce' => $nonce, 'label' => $options['label']));
		return rest_ensure_response(array('nonce' => $nonce, 'started' => time(), 'label' => $options['label']));
	}

	public function job(WP_REST_Request $request) {
		$this->boot_core();
		$nonce = (string) $request['nonce'];
		$sg = SiteGuard_Backup::instance();
		$jobdata = $sg->jobdata_getarray($nonce);
		if (empty($jobdata)) {
			// Finished jobs drop their jobdata once history is written: look there.
			$set = SiteGuard_Backup_History::get_backup_set_by_nonce($nonce);
			if ($set) {
				return rest_ensure_response(array(
					'nonce' => $nonce, 'status' => 'finished', 'terminal' => true, 'succeeded' => true,
					'timestamp' => isset($set['timestamp']) ? (int) $set['timestamp'] : null,
				));
			}
			return new WP_Error('not_found', 'No such job', array('status' => 404));
		}
		$status = isset($jobdata['jobstatus']) ? (string) $jobdata['jobstatus'] : '';
		$terminal = in_array($status, array('complete', 'finished', 'warning', 'error', 'aborted', 'failed'), true);
		$succeeded = in_array($status, array('complete', 'finished', 'warning'), true);
		if (!$terminal && function_exists('spawn_cron')) {
			spawn_cron();
		}
		return rest_ensure_response(array(
			'nonce'      => $nonce,
			'status'     => $status,
			'terminal'   => $terminal,
			'succeeded'  => $succeeded,
			'timestamp'  => isset($jobdata['backup_time']) ? (int) $jobdata['backup_time'] : null,
			'progress'   => isset($jobdata['jobstatus']) ? (string) $jobdata['jobstatus'] : '',
			'label'      => isset($jobdata['label']) ? (string) $jobdata['label'] : '',
		));
	}

	public function sets(WP_REST_Request $request) {
		sgbackup_load_classes();
		$with_hash = (bool) $request->get_param('hash');
		$opts = SiteGuard_Backup_Options::instance();
		$dir = trailingslashit($opts->get_backup_dir());
		$out = array();
		foreach (SiteGuard_Backup_History::get_history() as $timestamp => $backup) {
			if (empty($backup['nonce']) || !empty($backup['incremental_parent'])) {
				continue;
			}
			$files = array();
			$complete = true;
			foreach (SiteGuard_Backup_History::get_all_files($backup) as $name) {
				$path = $dir . $name;
				if (!is_file($path)) {
					$complete = false;
					continue;
				}
				$entry = array('name' => $name, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path));
				if ($with_hash) {
					$entry['sha256'] = $this->cached_sha256($path, $entry['size'], $entry['mtime']);
				}
				$files[] = $entry;
			}
			$entities = array();
			foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
				if (!empty($backup[$e])) {
					$entities[] = $e;
				}
			}
			$out[] = array(
				'timestamp' => (int) $timestamp,
				'nonce'     => (string) $backup['nonce'],
				'label'     => isset($backup['label']) ? (string) $backup['label'] : '',
				'entities'  => $entities,
				'full'      => $complete && in_array('db', $entities, true) && count($entities) > 1,
				'complete'  => $complete,
				'files'     => $files,
				'size'      => array_sum(array_column($files, 'size')),
				'imported'  => !empty($backup['imported']),
			);
		}
		return rest_ensure_response(array(
			'site_url' => get_site_url(),
			'mode'     => is_multisite() ? 'multisite' : 'single',
			'sites'    => $this->site_list(),
			'sets'     => $out,
		));
	}

	public function delete_set(WP_REST_Request $request) {
		$this->boot_core();
		$nonce = (string) $request['nonce'];
		$set = SiteGuard_Backup_History::get_backup_set_by_nonce($nonce);
		if (!$set || empty($set['timestamp'])) {
			return new WP_Error('not_found', 'No such set', array('status' => 404));
		}
		if (!empty($set['always_keep'])) {
			return new WP_Error('kept', 'Set is marked always-keep', array('status' => 409));
		}
		SiteGuard_Backup_History::delete_backup_set((int) $set['timestamp'], false);
		SiteGuard_Backup_Audit_Log::instance()->log('sync_set_deleted', 'Sync API deleted set ' . $nonce, array('nonce' => $nonce));
		return rest_ensure_response(array('deleted' => $nonce));
	}

	/**
	 * Stream one backup file. Bypasses the REST JSON envelope: headers and body
	 * are written directly, then the request ends.
	 */
	public function file(WP_REST_Request $request) {
		sgbackup_load_classes();
		$nonce = (string) $request['nonce'];
		$name = (string) $request['name'];
		$set = SiteGuard_Backup_History::get_backup_set_by_nonce($nonce);
		if (!$set) {
			return new WP_Error('not_found', 'No such set', array('status' => 404));
		}
		if (!in_array($name, SiteGuard_Backup_History::get_all_files($set), true)) {
			return new WP_Error('not_found', 'File is not part of this set', array('status' => 404));
		}
		$path = trailingslashit(SiteGuard_Backup_Options::instance()->get_backup_dir()) . $name;
		if (!is_file($path) || !is_readable($path)) {
			return new WP_Error('not_found', 'File missing on disk', array('status' => 404));
		}
		$size = (int) filesize($path);
		$start = 0;
		$end = $size - 1;
		$status = 200;
		$range = (string) $request->get_header('Range');
		if ('' !== $range && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
			if ('' !== $m[1]) {
				$start = (int) $m[1];
				$end = ('' !== $m[2]) ? min((int) $m[2], $size - 1) : $size - 1;
			} elseif ('' !== $m[2]) {
				$start = max(0, $size - (int) $m[2]);
			}
			if ($start > $end || $start >= $size) {
				status_header(416);
				header('Content-Range: bytes */' . $size);
				exit;
			}
			$status = 206;
		}
		@set_time_limit(0);
		ignore_user_abort(false);
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		nocache_headers();
		status_header($status);
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
		header('Accept-Ranges: bytes');
		header('Content-Length: ' . ($end - $start + 1));
		header('X-Content-Type-Options: nosniff');
		if (206 === $status) {
			header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
		}
		$fh = fopen($path, 'rb');
		if (!$fh) {
			exit;
		}
		fseek($fh, $start);
		$left = $end - $start + 1;
		while ($left > 0 && !feof($fh) && !connection_aborted()) {
			$chunk = fread($fh, (int) min(self::STREAM_CHUNK, $left));
			if (false === $chunk || '' === $chunk) {
				break;
			}
			echo $chunk;
			flush();
			$left -= strlen($chunk);
		}
		fclose($fh);
		exit;
	}

	// --------------------------------------------------------------- helpers

	private function boot_core() {
		sgbackup_load_classes();
		require_once SGBACKUP_DIR . 'class-siteguardbackup.php';
	}

	private function active_jobs() {
		sgbackup_load_classes();
		require_once SGBACKUP_DIR . 'includes/class-job-scheduler.php';
		$jobs = SiteGuard_Backup_Job_Scheduler::get_active_jobs();
		return is_array($jobs) ? $jobs : array();
	}

	/** @return array<int,array{blog_id:int,domain:string,path:string,public:int}> */
	public function site_list() {
		if (!is_multisite()) {
			$home = home_url();
			return array(array(
				'blog_id' => 1,
				'domain'  => (string) wp_parse_url($home, PHP_URL_HOST),
				'path'    => rtrim((string) wp_parse_url($home, PHP_URL_PATH), '/') . '/',
				'public'  => (int) get_option('blog_public', 1),
			));
		}
		$out = array();
		foreach (get_sites(array('number' => 0, 'deleted' => 0, 'archived' => 0)) as $site) {
			$out[] = array(
				'blog_id' => (int) $site->blog_id,
				'domain'  => (string) $site->domain,
				'path'    => (string) $site->path,
				'public'  => (int) $site->public,
			);
		}
		return $out;
	}

	private function cached_sha256($path, $size, $mtime) {
		$key = 'sgbackup_sync_sha_' . md5($path . '|' . $size . '|' . $mtime);
		$hash = get_transient($key);
		if (is_string($hash) && 64 === strlen($hash)) {
			return $hash;
		}
		@set_time_limit(0);
		$hash = hash_file('sha256', $path);
		if (is_string($hash)) {
			set_transient($key, $hash, 30 * DAY_IN_SECONDS);
		}
		return $hash ?: '';
	}
}
