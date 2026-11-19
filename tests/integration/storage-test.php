<?php
/**
 * Exercise one remote storage method end to end against a test endpoint.
 * Run with WP-CLI inside a disposable instance (see storage.sh):
 *
 *   wp eval-file storage-test.php <method> '<json options>'
 *
 * Uploads a small and a large (multipart / block list) file, lists, downloads
 * and compares bytes, deletes, and checks the unsafe-name guard. Prints
 * "ok"/"FAIL" lines and exits non-zero on any failure.
 */

if (!defined('ABSPATH') || !class_exists('SiteGuard_Backup')) {
	fwrite(STDERR, "run with: wp eval-file storage-test.php <method> '<json options>'\n");
	exit(2);
}

$method_id = isset($args[0]) ? $args[0] : '';
$opts = isset($args[1]) ? json_decode($args[1], true) : null;
$all_methods = array('s3', 'azure', 's3generic', 'b2', 'gcs', 'webdav', 'sftp', 'ftp', 'openstack', 'dropbox', 'googledrive', 'onedrive', 'pcloud', 'email');
if (!in_array($method_id, $all_methods, true) || !is_array($opts)) {
	fwrite(STDERR, 'usage: <' . implode('|', $all_methods) . "> '<json options>'\n");
	exit(2);
}
$oauth = in_array($method_id, array('dropbox', 'googledrive', 'onedrive'), true);
$upload_only = ('email' === $method_id);

require_once SGBACKUP_DIR . 'methods/backup-module.php';
require_once SGBACKUP_DIR . 'methods/' . $method_id . '.php';

$create = !empty($opts['__create']);
unset($opts['__create']);

// Store the options as instance "sgtest" exactly as the settings screen would.
$iid = 'sgtest';
SiteGuard_Backup_Options::instance()->update_option(SiteGuard_Backup_Options::method_option($method_id), array(
	'version'  => 1,
	'settings' => array($iid => $opts),
));

$class = 'SiteGuard_Backup_Method_' . ucfirst($method_id);
$m = new $class();
$m->set_instance_id($iid);

// Create the bucket / container through the method's own signed request().
if ($create) {
	$req = new ReflectionMethod($m, 'request');
	$req->setAccessible(true);
	if ('azure' === $method_id) {
		$req->invoke($m, 'PUT', '/' . rawurlencode($opts['container']), '', array(), 'restype=container');
	} else { // s3, s3generic, b2, gcs
		// Outside us-east-1 S3 wants the region as LocationConstraint.
		$sr = new ReflectionMethod($m, 'get_signing_region');
		$sr->setAccessible(true);
		$region = $sr->invoke($m);
		$cfg = 'us-east-1' === $region ? '' : '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>' . $region . '</LocationConstraint></CreateBucketConfiguration>';
		$cr = $req->invoke($m, 'PUT', '', $cfg, array(), true);
		if (is_wp_error($cr)) echo "  info bucket create: " . $cr->get_error_message() . "\n";
	}
}

$fails = 0;
$check = function ($name, $cond, $detail = '') use (&$fails) {
	if ($cond) {
		echo "  ok   {$name}\n";
	} else {
		$fails++;
		echo "  FAIL {$name}" . ('' !== $detail ? " ({$detail})" : '') . "\n";
	}
};
$err = function ($r) {
	return is_wp_error($r) ? $r->get_error_code() . ': ' . $r->get_error_message() : '';
};

$work = rtrim(get_temp_dir(), '/') . '/sg-storage-' . wp_generate_password(6, false);
wp_mkdir_p($work . '/up');
wp_mkdir_p($work . '/down');

$r = $m->credentials_test();
$check("{$method_id}: credentials test", true === $r || (is_array($r) && !empty($r['success'])), $err($r));
if (isset($opts['url']) && 0 === stripos($opts['url'], 'http://')) {
	$check("{$method_id}: plain-http endpoint is flagged", is_array($r) && false !== stripos((string) ($r['message'] ?? ''), 'cleartext'));
}

$files = array(
	'backup_2026-01-01-0000_sgtest_small000000-db.gz' => 200 * 1024,
	'backup_2026-01-01-0000_sgtest_large000000-uploads.zip' => 70 * 1024 * 1024, // > single-put limits: multipart / block list
);
if ($upload_only) {
	// Email: the small archive goes out as an attachment (storage.sh checks it
	// arrived byte-identical); anything over the limit is refused, not mailed.
	$files['backup_2026-01-01-0000_sgtest_large000000-uploads.zip'] = 30 * 1024 * 1024;
}
foreach ($files as $name => $size) {
	$path = $work . '/up/' . $name;
	$fh = fopen($path, 'wb');
	$left = $size;
	while ($left > 0) {
		$n = min($left, 1048576);
		fwrite($fh, random_bytes($n));
		$left -= $n;
	}
	fclose($fh);

	$r = $m->backup($path);
	if ($upload_only) {
		if ($size > 25 * 1024 * 1024) {
			$check("{$method_id}: oversized attachment refused", is_wp_error($r) && 'email_attachment_too_large' === $r->get_error_code(), $err($r));
		} else {
			$check("{$method_id}: send {$name} (" . size_format($size) . ')', true === $r, $err($r));
			echo "  info sent-md5 " . md5_file($path) . "\n";
		}
		continue;
	}
	$check("{$method_id}: upload {$name} (" . size_format($size) . ')', true === $r, $err($r));

	$r = $m->download($name, $work . '/down/');
	$check("{$method_id}: download {$name}", true === $r, $err($r));
	$got = $work . '/down/' . $name;
	$check("{$method_id}: downloaded bytes identical", file_exists($got) && md5_file($got) === md5_file($path));
}

if ($upload_only) {
	$r = $m->delete(array('', '.', '..', 'x/../y'));
	$check("{$method_id}: delete of unsafe names is a no-op", true === $r, $err($r));
	echo $fails ? "{$fails} failure(s)\n" : "all passed\n";
	exit($fails ? 1 : 0);
}

if ($oauth) {
	// The test seeds an expired access token: the first call must have refreshed
	// it and saved the new one, keeping the refresh token.
	$saved = $m->get_options();
	$tok = isset($saved['token']) && is_array($saved['token']) ? $saved['token'] : array();
	$check("{$method_id}: expired access token was refreshed and saved",
		!empty($tok['access_token']) && 'expired-token' !== $tok['access_token'] && !empty($tok['refresh_token']) && !empty($tok['obtained_at']) && $tok['obtained_at'] > time() - 3600,
		json_encode(array_map(function ($v) { return is_string($v) ? substr($v, 0, 12) : $v; }, $tok)));
}

if (method_exists($m, 'listfiles')) {
	$list = $m->listfiles();
	$names = is_array($list) ? array_map(function ($f) { return is_array($f) ? (isset($f['name']) ? $f['name'] : '') : (string) $f; }, $list) : array();
	$check("{$method_id}: listing shows both files", count(array_intersect(array_keys($files), array_map('basename', $names))) === 2, is_wp_error($list) ? $err($list) : json_encode($names));
	// Anything else in the listing (multipart segments, folders) would be offered
	// as a backup by a remote rescan and counted by retention.
	$extra = array_values(array_diff(array_map('basename', $names), array_keys($files)));
	$check("{$method_id}: listing holds only the backup files", empty($extra), json_encode($extra));
}

// The unsafe-name guard must drop these without touching anything.
$r = $m->delete(array('', '.', '..', 'x/../y'));
$check("{$method_id}: delete of unsafe names is a no-op", true === $r, $err($r));
if (method_exists($m, 'listfiles')) {
	$list = $m->listfiles();
	$check("{$method_id}: files survive the unsafe delete", is_array($list) && count($list) >= 2);
}

$r = $m->delete(array_keys($files));
$check("{$method_id}: delete", true === $r, $err($r));
if (method_exists($m, 'listfiles')) {
	$list = $m->listfiles();
	$names = is_array($list) ? array_map(function ($f) { return is_array($f) ? (isset($f['name']) ? $f['name'] : '') : (string) $f; }, $list) : array();
	$check("{$method_id}: files gone after delete", 0 === count(array_intersect(array_keys($files), array_map('basename', $names))));
}

// S3 family: a multipart upload interrupted mid-way (the process killed)
// resumes from the parts already on S3 instead of starting again.
if ($m instanceof SiteGuard_Backup_Method_S3 && !$upload_only) {
	$rname = 'backup_2026-01-01-0000_sgtest_resume00000-uploads.zip';
	$rpath = $work . '/up/' . $rname;
	$fh = fopen($rpath, 'wb');
	for ($i = 0; $i < 21; $i++) fwrite($fh, random_bytes(1048576));
	fclose($fh);
	$ps = function () { return 5 * 1048576; };
	add_filter('sgbackup_s3_part_size', $ps);
	$puts = 0; $kill = true;
	$hook = function ($pre, $args, $url) use (&$puts, &$kill) {
		if (isset($args['method']) && 'PUT' === $args['method'] && false !== strpos($url, 'partNumber=')) {
			$puts++;
			if ($kill && 3 === $puts) throw new RuntimeException('simulated kill');
		}
		return $pre;
	};
	add_filter('pre_http_request', $hook, 10, 3);
	$interrupted = false;
	try { $m->backup($rpath); } catch (RuntimeException $e) { $interrupted = true; }
	$check("{$method_id}: upload interrupted after 2 of 5 parts (simulated kill)", $interrupted);
	$state = get_option(SiteGuard_Backup_Method_S3::MPU_STATE_OPTION, array());
	$check("{$method_id}: the unfinished upload is recorded for resume", is_array($state) && 1 === count($state));
	$kill = false; $puts = 0;
	$r = $m->backup($rpath);
	remove_filter('pre_http_request', $hook, 10);
	remove_filter('sgbackup_s3_part_size', $ps);
	$check("{$method_id}: the next attempt completes the upload", true === $r, $err($r));
	$check("{$method_id}: ...uploading only the 3 missing parts", 3 === $puts, "uploaded {$puts} part(s)");
	$check("{$method_id}: resume record removed after completion", array() === get_option(SiteGuard_Backup_Method_S3::MPU_STATE_OPTION, array()));
	$r = $m->download($rname, $work . '/down/');
	$check("{$method_id}: resumed object downloads byte-identical", true === $r && md5_file($work . '/down/' . $rname) === md5_file($rpath), $err($r));
	$m->delete(array($rname));
	foreach (array('up', 'down') as $d) { if (is_file("{$work}/{$d}/{$rname}")) unlink("{$work}/{$d}/{$rname}"); }
}

// Download of a missing object must fail cleanly and leave nothing behind.
$r = $m->download('backup_missing_sgtest-db.gz', $work . '/down/');
$check("{$method_id}: missing object download reports an error", is_wp_error($r));
$check("{$method_id}: no partial file left for a failed download", !file_exists($work . '/down/backup_missing_sgtest-db.gz'));

// Remove only the files this test created (non-recursive, known names).
foreach (array_keys($files) as $name) {
	foreach (array('up', 'down') as $d) {
		if (is_file("{$work}/{$d}/{$name}")) unlink("{$work}/{$d}/{$name}");
	}
}
// Remove the work tree, files included: leftovers in the container's small
// /tmp (a tmpfs) used to make the next run's large downloads come up short.
foreach (array('up', 'down') as $d) {
	foreach ((array) glob($work . '/' . $d . '/*') as $f) { if (is_file($f)) @unlink($f); }
	@rmdir($work . '/' . $d);
}
@rmdir($work);

echo $fails ? "{$fails} failure(s)\n" : "all passed\n";
exit($fails ? 1 : 0);
