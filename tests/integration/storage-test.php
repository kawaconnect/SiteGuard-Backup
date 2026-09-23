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
if (!in_array($method_id, array('s3', 'azure', 's3generic', 'b2'), true) || !is_array($opts)) {
	fwrite(STDERR, "usage: <s3|azure|s3generic|b2> '<json options>'\n");
	exit(2);
}

require_once SGBACKUP_DIR . 'methods/backup-module.php';
require_once SGBACKUP_DIR . 'methods/' . $method_id . '.php';

$create = !empty($opts['__create']);
unset($opts['__create']);

// Store the options as instance "sgtest" exactly as the settings screen would.
$iid = 'sgtest';
SiteGuard_Backup_Options::instance()->update_option('sgbackup_' . $method_id, array(
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
	} else {
		$req->invoke($m, 'PUT', '', '', array(), true);
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

$files = array(
	'backup_2026-01-01-0000_sgtest_small000000-db.gz' => 200 * 1024,
	'backup_2026-01-01-0000_sgtest_large000000-uploads.zip' => 70 * 1024 * 1024, // > single-put limits: multipart / block list
);
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
	$check("{$method_id}: upload {$name} (" . size_format($size) . ')', true === $r, $err($r));

	$r = $m->download($name, $work . '/down/');
	$check("{$method_id}: download {$name}", true === $r, $err($r));
	$got = $work . '/down/' . $name;
	$check("{$method_id}: downloaded bytes identical", file_exists($got) && md5_file($got) === md5_file($path));
}

if (method_exists($m, 'listfiles')) {
	$list = $m->listfiles();
	$names = is_array($list) ? array_map(function ($f) { return is_array($f) ? (isset($f['name']) ? $f['name'] : '') : (string) $f; }, $list) : array();
	$check("{$method_id}: listing shows both files", count(array_intersect(array_keys($files), array_map('basename', $names))) === 2, is_wp_error($list) ? $err($list) : json_encode($names));
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
foreach (array('up', 'down') as $d) @rmdir($work . '/' . $d);
@rmdir($work);

echo $fails ? "{$fails} failure(s)\n" : "all passed\n";
exit($fails ? 1 : 0);
