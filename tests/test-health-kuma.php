<?php
/**
 * Unit tests for the Uptime Kuma push helpers (SiteGuard_Backup_Health_Kuma):
 * push URL validation, masking and scrubbing, the heartbeat URL, and the
 * status/message built from health results.
 *
 * Standalone (no WordPress): only the pure static helpers are exercised.
 *
 *   php tests/test-health-kuma.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir() . '/sg-kuma-test/');
require __DIR__ . '/../includes/class-health-kuma.php';
$K = 'SiteGuard_Backup_Health_Kuma';

$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
function site($domain, $over = array()) {
	return array_merge(array(
		'site_id' => 1, 'url' => 'https://' . $domain . '/', 'domain' => $domain, 'status' => 'operational',
		'http_code' => 200, 'response_time' => 0.2, 'ssl_expiry_days' => 60, 'ssl_ok' => true,
		'ssl_error' => '', 'ssl_stale' => false, 'error' => '', 'check_method' => 'localhost',
	), $over);
}

// --- URL validation ----------------------------------------------------------
$tok = 'AbCdEf0123456789';
check('valid push URL kept', $K::normalize_url("https://kuma.example.com/api/push/$tok") === "https://kuma.example.com/api/push/$tok");
check('query from Kuma copy button dropped', $K::normalize_url("https://kuma.example.com/api/push/$tok?status=up&msg=OK&ping=") === "https://kuma.example.com/api/push/$tok");
check('port and sub-path kept, host lowercased', $K::normalize_url("https://Kuma.Example.com:8443/status/api/push/$tok") === "https://kuma.example.com:8443/status/api/push/$tok");
check('surrounding whitespace trimmed', $K::normalize_url("  https://k.example/api/push/$tok\n") === "https://k.example/api/push/$tok");
check('http refused', false === $K::normalize_url("http://kuma.example.com/api/push/$tok"));
check('no /api/push/ refused', false === $K::normalize_url('https://kuma.example.com/dashboard/12'));
check('empty token refused', false === $K::normalize_url('https://kuma.example.com/api/push/'));
check('extra path after token refused', false === $K::normalize_url("https://kuma.example.com/api/push/$tok/extra"));
check('user info refused', false === $K::normalize_url("https://u:p@kuma.example.com/api/push/$tok"));
check('javascript: refused', false === $K::normalize_url('javascript:alert(1)//api/push/abcd'));
check('embedded newline refused', false === $K::normalize_url("https://kuma.example.com/api/push/ab\ncd"));
check('non-string refused', false === $K::normalize_url(array('x')));
check('token with odd chars refused', false === $K::normalize_url('https://kuma.example.com/api/push/ab<cd>'));

// --- Masking -----------------------------------------------------------------
$url = "https://kuma.example.com/api/push/$tok";
$masked = $K::mask_url($url);
check('mask keeps host and path', 0 === strpos($masked, 'https://kuma.example.com/api/push/****'));
check('mask shows at most the last 4 chars', '****6789' === substr($masked, -8) && false === strpos($masked, substr($tok, 0, 12)));
check('short token fully masked', 'https://k.example/api/push/****' === $K::mask_url('https://k.example/api/push/abcd12'));
check('scrub removes URL and token', false === strpos($K::scrub("cURL error for $url?status=up and $tok", $url), $tok));

// --- Heartbeat URL -----------------------------------------------------------
$push = $K::build_push_url($url, 'down', 'down: a.com (HTTP 503); ssl_expired: b.com', 1234);
parse_str((string) parse_url($push, PHP_URL_QUERY), $q);
check('push URL starts with the base', 0 === strpos($push, $url . '?'));
check('status/msg/ping round-trip', 'down' === $q['status'] && 'down: a.com (HTTP 503); ssl_expired: b.com' === $q['msg'] && '1234' === $q['ping']);
check('msg is urlencoded (no raw spaces or ;)', false === strpos($push, ' ') && false === strpos($push, ';'));
check('unknown status becomes down', false !== strpos($K::build_push_url($url, 'weird', '', 1), 'status=down'));

// --- Status and message ------------------------------------------------------
$m = $K::build_message(array(site('a.com'), site('b.com'), site('c.com')));
check('all operational: up', 'up' === $m['status'] && '3/3 sites operational' === $m['msg']);

$m = $K::build_message(array(site('a.com'), site('b.com', array('status' => 'down', 'http_code' => 503, 'error' => 'HTTP 503'))));
check('HTTP 503: down naming the site', 'down' === $m['status'] && '1/2 failing; down: b.com (HTTP 503)' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'down', 'http_code' => 0, 'error' => 'cURL error 28: Operation timed out after 10001 milliseconds'))));
check('timeout reason shortened', 'down' === $m['status'] && '1/1 failing; down: a.com (timeout)' === $m['msg']);

$m = $K::build_message(array(site('a.com'), site('foo.com', array('status' => 'down', 'ssl_expiry_days' => -2, 'error' => 'SSL certificate expired'))));
check('expired cert: down as ssl_expired', 'down' === $m['status'] && '1/2 failing; ssl_expired: foo.com' === $m['msg']);

$m = $K::build_message(array(site('a.com'), site('b.com', array('status' => 'degraded', 'ssl_expiry_days' => 9))));
check('cert expiring in 9d: up with a note', 'up' === $m['status'] && '1/2 sites operational; ssl_expiring: b.com (9d)' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'degraded', 'ssl_expiry_days' => 2))));
check('cert expiring in 2d (critical): down', 'down' === $m['status'] && '1/1 failing; ssl_expiring: a.com (2d)' === $m['msg']);

// Rows as the 1.5.4 monitor writes them around day 0 (days are floored).
$m = $K::build_message(array(site('a.com', array('status' => 'down', 'ssl_expiry_days' => -1, 'ssl_expires_at' => time() - 3600, 'error' => 'SSL certificate expired'))));
check('expired 1 h ago (days -1): down as ssl_expired', 'down' === $m['status'] && '1/1 failing; ssl_expired: a.com' === $m['msg']);
$m = $K::build_message(array(site('a.com', array('status' => 'degraded', 'ssl_expiry_days' => 0, 'ssl_expires_at' => time() + 5 * 3600))));
check('5 h left (days 0): down as expiring (0d), not expired', 'down' === $m['status'] && '1/1 failing; ssl_expiring: a.com (0d)' === $m['msg']);
$m = $K::build_message(array(site('a.com', array('ssl_ok' => false, 'ssl_expiry_days' => -1, 'ssl_expires_at' => 0))));
check('never probed (-1, ssl_ok false, no error): up, nothing flagged', 'up' === $m['status'] && '1/1 sites operational' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'degraded', 'ssl_expiry_days' => 2, 'ssl_stale' => true, 'ssl_ok' => false, 'ssl_error' => 'SSL probe failed for a.com: x'))));
check('stale cert data never pages', 'up' === $m['status'] && '0/1 sites operational; ssl_probe_failed: a.com' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'degraded', 'response_time' => 6.24))));
check('slow site: up with a note', 'up' === $m['status'] && '0/1 sites operational; slow: a.com (6.2s)' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'unknown', 'check_method' => 'skipped'))));
check('skipped by time budget: up with a note', 'up' === $m['status'] && '0/1 sites operational; skipped: a.com' === $m['msg']);

$m = $K::build_message(array(site('a.com', array('status' => 'operational', 'http_code' => 403, 'check_method' => 'localhost'))));
check('403 from localhost stays operational', 'up' === $m['status']);

$m = $K::build_message(array(
	site('a.com', array('status' => 'down', 'http_code' => 500, 'error' => 'HTTP 500')),
	site('b.com', array('status' => 'degraded', 'ssl_expiry_days' => 10)),
));
check('down lists critical first, then warnings', '1/2 failing; down: a.com (HTTP 500); ssl_expiring: b.com (10d)' === $m['msg']);

$m = $K::build_message(array(site('net.example'), site('net.example', array('url' => 'https://net.example/shop/', 'status' => 'down', 'http_code' => 503, 'error' => 'HTTP 503'))));
check('subdirectory site named with its path', '1/2 failing; down: net.example/shop (HTTP 503)' === $m['msg']);

check('no results: down', array('status' => 'down', 'msg' => 'no sites checked') === $K::build_message(array()));
check('null results: down', 'down' === $K::build_message(null)['status']);
$m = $K::build_message(null, 14, 'RuntimeException');
check('run error: down with the reason', 'down' === $m['status'] && 'health run failed: RuntimeException' === $m['msg']);

$many = array();
for ($i = 1; $i <= 40; $i++) {
	$many[] = site("client-site-number-$i.example.com", array('status' => 'down', 'http_code' => 502, 'error' => 'HTTP 502'));
}
$m = $K::build_message($many);
check('message capped at 200 chars', strlen($m['msg']) <= 200);
check('capped message says how many are left out', (bool) preg_match('/ \+\d+ more$/', $m['msg']));
check('capped message starts with the count', 0 === strpos($m['msg'], '40/40 failing; down: client-site-number-1.example.com (HTTP 502)'));

check('recommended heartbeat for 10 min checks is 1320 s', 1320 === $K::recommended_heartbeat(600));

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
