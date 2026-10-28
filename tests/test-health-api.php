<?php
/**
 * Unit tests for the health REST API (siteguardbackup.php): the key check runs
 * once per request although WordPress calls the permission callback twice
 * (dispatch, then the Allow header), a failed attempt counts once so the
 * limiter answers 429 on the 21st bad key, not the 11th, every health response
 * (errors too) is marked no-store, and SSL expiry is decided from the
 * certificate's notAfter (no off-by-one around day 0).
 *
 * Standalone: the plugin file is loaded with the few WordPress functions it
 * touches stubbed; options and transients live in memory.
 *
 *   php tests/test-health-api.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir() . '/sg-health-auth-test/');

// --- WordPress stubs -----------------------------------------------------------
$options = array();
$transients = array();
$bcrypt_calls = 0;
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.test/'; }
function plugin_basename($f) { return 'siteguardbackup/siteguardbackup.php'; }
function add_filter() {}
function add_action() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function is_multisite() { return false; }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function apply_filters($tag, $value) { return $value; }
function get_option($k, $d = false) { global $options; return array_key_exists($k, $options) ? $options[$k] : $d; }
function update_option($k, $v) { global $options; $options[$k] = $v; return true; }
function delete_option($k) { global $options; unset($options[$k]); return true; }
function get_transient($k) { global $transients; return isset($transients[$k]) ? $transients[$k] : false; }
function set_transient($k, $v, $ttl = 0) { global $transients; $transients[$k] = $v; return true; }
function wp_hash_password($p) { return password_hash($p, PASSWORD_BCRYPT, array('cost' => 4)); }
function wp_check_password($p, $h) { global $bcrypt_calls; $bcrypt_calls++; return password_verify($p, $h); }
function wp_get_schedules() { return array('every10min' => array('interval' => 600)); }
function get_site_url() { return 'https://example.test'; }
function rest_ensure_response($data) { return $data; }

class WP_Error {
	private $code; private $data;
	public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
class WP_HTTP_Response {
	public $headers = array();
	public function header($k, $v, $replace = true) { $this->headers[$k] = $v; }
	public function get_headers() { return $this->headers; }
}
class WP_REST_Response extends WP_HTTP_Response {}
class WP_REST_Request {
	private $headers; private $route;
	public function __construct($route, $headers = array()) { $this->route = $route; $this->headers = array_change_key_case($headers); }
	public function get_header($k) { $k = strtolower($k); return isset($this->headers[$k]) ? $this->headers[$k] : null; }
	public function get_route() { return $this->route; }
}

require __DIR__ . '/../siteguardbackup.php';

$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
function code_of($r) { return (true === $r) ? 'ok' : (is_object($r) ? $r->get_error_code() : var_export($r, true)); }
// One REST request as WordPress makes it: dispatch, then the Allow header pass.
function wp_request($key, $ip) {
	$_SERVER = array('REMOTE_ADDR' => $ip);
	$req = new WP_REST_Request('/sgbackup/v1/health/ping', null === $key ? array() : array('Authorization' => 'Bearer ' . $key));
	$first = sgbackup_health_api_auth($req);
	$second = sgbackup_health_api_auth($req);
	return array(code_of($first), code_of($second));
}
function attempts($ip) { return (int) get_transient('sgbackup_health_rl_' . md5(SiteGuard_Backup_Health_Monitor::rate_limit_bucket($ip))); }

$key = str_repeat('k', 24) . 'GoodKey1234567890123456';
$options['sgbackup_health_enabled'] = true;
$options['sgbackup_health_api_key_hash'] = wp_hash_password($key);
$bad = str_repeat('x', 48);

// --- Disabled monitor ----------------------------------------------------------
$options['sgbackup_health_enabled'] = false;
check('disabled: 404 code', array('disabled', 'disabled') === wp_request($key, '192.0.2.200'));
$options['sgbackup_health_enabled'] = true;

// --- One check per request -----------------------------------------------------
$bcrypt_calls = 0;
check('good key: ok on both passes', array('ok', 'ok') === wp_request($key, '192.0.2.1'));
check('good key: one bcrypt check for the request', 1 === $bcrypt_calls);

$bcrypt_calls = 0;
check('bad key: forbidden on both passes', array('forbidden', 'forbidden') === wp_request($bad, '192.0.2.2'));
check('bad key: one bcrypt check for the request', 1 === $bcrypt_calls);
check('bad key: counted once', 1 === attempts('192.0.2.2'));

check('no header: unauthorized on both passes', array('unauthorized', 'unauthorized') === wp_request(null, '192.0.2.3'));
check('no header: counted once', 1 === attempts('192.0.2.3'));

// A second request (new request object) is checked again.
$bcrypt_calls = 0;
wp_request($bad, '192.0.2.2');
check('next request checked again and counted', 2 === attempts('192.0.2.2') && 1 === $bcrypt_calls);

// Same request object, different Authorization header: not served from the memo.
$_SERVER = array('REMOTE_ADDR' => '192.0.2.4');
$req = new WP_REST_Request('/sgbackup/v1/health', array('Authorization' => 'Bearer ' . $bad));
$r1 = code_of(sgbackup_health_api_auth($req));
$req2 = new WP_REST_Request('/sgbackup/v1/health', array('Authorization' => 'Bearer ' . $key));
$r2 = code_of(sgbackup_health_api_auth($req2));
check('different header re-evaluated', 'forbidden' === $r1 && 'ok' === $r2);

// --- 20 bad keys, then 429 -------------------------------------------------------
$ip = '198.51.100.20';
$codes = array();
for ($i = 1; $i <= 21; $i++) {
	$codes[$i] = wp_request($bad, $ip);
}
check('bad keys 1-20 answer 403 (not 429 from the 11th)', 20 === count(array_filter(array_slice($codes, 0, 20), function ($c) { return array('forbidden', 'forbidden') === $c; })));
check('21st bad key answers 429', array('rate_limited', 'rate_limited') === $codes[21]);
check('20 attempts recorded', 20 === attempts($ip));
check('good key from the locked IP also 429', array('rate_limited', 'rate_limited') === wp_request($key, $ip));
check('other IP unaffected', array('ok', 'ok') === wp_request($key, '198.51.100.21'));

// --- IPv6 /64 --------------------------------------------------------------------
for ($i = 1; $i <= 20; $i++) {
	wp_request($bad, '2001:db8:aa:bb::' . dechex($i));
}
check('IPv6: 20 bad keys from one /64 lock the /64', array('rate_limited', 'rate_limited') === wp_request($key, '2001:db8:aa:bb:ffff::1'));
check('IPv6: next /64 unaffected', array('ok', 'ok') === wp_request($key, '2001:db8:aa:bc::1'));

// --- Spoofed CF-Connecting-IP from a private peer ------------------------------
// The locked-out IPv4 above cannot escape via a forged header from a LAN peer,
// and a LAN peer cannot put its failures on someone else's IP.
$_SERVER = array('REMOTE_ADDR' => '172.19.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.50');
$req = new WP_REST_Request('/sgbackup/v1/health', array('Authorization' => 'Bearer ' . $bad));
sgbackup_health_api_auth($req);
check('private peer + forged CF-Connecting-IP: counted against the peer', 1 === attempts('172.19.0.9') && 0 === attempts('203.0.113.50'));

// --- Response headers ------------------------------------------------------------
function headers_for($route) {
	$res = new WP_REST_Response();
	$out = sgbackup_health_api_no_store($res, null, new WP_REST_Request($route));
	return $out->get_headers();
}
$want = array('Cache-Control' => 'no-store, private, max-age=0', 'Vary' => 'Authorization');
foreach (array('/sgbackup/v1/health', '/sgbackup/v1/health/ping', '/sgbackup/v1/health/check', '/SGBACKUP/v1/Health/Ping', '/sgbackup/v1/health/') as $route) {
	check("headers on $route", $want === headers_for($route));
}
foreach (array('/sgbackup/v1', '/sgbackup/v1/healthz', '/wp/v2/posts', '/other/sgbackup/v1/health') as $route) {
	check("no headers on $route", array() === headers_for($route));
}
$err = new WP_Error('x');
check('non-response result passed through untouched', $err === sgbackup_health_api_no_store($err, null, new WP_REST_Request('/sgbackup/v1/health')));

// --- SSL expiry ------------------------------------------------------------------
$M = 'SiteGuard_Backup_Health_Monitor';
$now = time();
check('ssl_expired: notAfter 1 h ago (days -1) is expired', $M::ssl_expired($now - 3600, -1, $now));
check('ssl_expired: notAfter in 5 h (days 0) is not expired', !$M::ssl_expired($now + 5 * 3600, 0, $now));
check('ssl_expired: notAfter now is expired', $M::ssl_expired($now, 0, $now));
check('ssl_expired: no notAfter (rows from 1.5.3), days -3 is expired', $M::ssl_expired(0, -3, $now));
check('ssl_expired: no notAfter, days 0 is not expired', !$M::ssl_expired(0, 0, $now));

// The cURL probe: a certificate that expired an hour ago is read, not "unable to parse".
$parse = new ReflectionMethod($M, 'parse_curl_certinfo');
$parse->setAccessible(true);
$probe = function ($expire) use ($parse, $M) {
	return $parse->invoke($M::instance(), array(array('Expire date' => $expire, 'Subject' => 'CN = a.example')));
};
$r = $probe(gmdate('M j H:i:s Y', $now - 3600) . ' GMT');
check('curl probe: expired 1 h ago is ok, days -1, notAfter recorded', !empty($r['ok']) && -1 === $r['days'] && abs($r['expires_at'] - ($now - 3600)) <= 1);
$r = $probe(gmdate('M j H:i:s Y', $now + 5 * 3600) . ' GMT');
check('curl probe: 5 h left is ok, days 0', !empty($r['ok']) && 0 === $r['days'] && $r['expires_at'] > $now);
$r = $probe('not a date');
check('curl probe: unparseable expiry still fails', empty($r['ok']) && 'curl: unable to parse expiry' === $r['error']);

// REST summary and alerts from stored rows.
function ssl_row($id, $domain, $over) {
	global $now;
	return array_merge(array(
		'site_id' => $id, 'url' => 'https://' . $domain . '/', 'domain' => $domain, 'status' => 'operational',
		'http_code' => 200, 'response_time' => 0.1, 'ssl_expiry_days' => 60, 'ssl_expires_at' => $now + 60 * 86400 + 100,
		'ssl_issuer' => 'R11', 'ssl_ok' => true, 'ssl_error' => '', 'ssl_stale' => false, 'ssl_last_success' => $now,
		'ssl_checked_at' => $now, 'last_check' => $now, 'error' => '', 'check_method' => 'external', 'consecutive_failures' => 0,
	), $over);
}
$options['sgbackup_health_results'] = array(
	ssl_row(1, 'ok.test', array()),
	ssl_row(2, 'expired1h.test', array('status' => 'down', 'error' => 'SSL certificate expired', 'ssl_expiry_days' => -1, 'ssl_expires_at' => $now - 3600)),
	ssl_row(3, 'hours5.test', array('status' => 'degraded', 'ssl_expiry_days' => 0, 'ssl_expires_at' => $now + 5 * 3600)),
	ssl_row(4, 'legacy.test', array('status' => 'down', 'error' => 'SSL certificate expired', 'ssl_expiry_days' => -3, 'ssl_expires_at' => null)),
	ssl_row(5, 'unknown.test', array('ssl_ok' => false, 'ssl_expiry_days' => -1, 'ssl_expires_at' => 0, 'ssl_last_success' => 0, 'ssl_checked_at' => 0)),
	ssl_row(6, 'plain.test', array('url' => 'http://plain.test/', 'ssl_ok' => false, 'ssl_expiry_days' => -1, 'ssl_expires_at' => 0)),
	ssl_row(7, 'stale.test', array('status' => 'degraded', 'ssl_ok' => false, 'ssl_stale' => true, 'ssl_expiry_days' => 2, 'ssl_error' => 'SSL probe failed for stale.test: timeout')),
	ssl_row(8, 'week.test', array('status' => 'degraded', 'ssl_expiry_days' => 7, 'ssl_expires_at' => $now + 7 * 86400 + 100)),
);
unset($options['sgbackup_health_results'][3]['ssl_expires_at']);   // a row stored by 1.5.3
$payload = sgbackup_health_api_callback(new WP_REST_Request('/sgbackup/v1/health'));
$by = array();
foreach ($payload['alerts'] as $a) { $by[$a['domain']][] = $a['type'] . '/' . $a['severity']; }
$sum = $payload['summary'];
check('summary.ssl_expired counts both expired certs (1 h ago, legacy -3)', 2 === $sum['ssl_expired']);
check('summary.ssl_expiring_soon: expired x2 + 5 h + 7 d', 4 === $sum['ssl_expiring_soon']);
check('expired 1 h ago: ssl_expired alert', in_array('ssl_expired/critical', $by['expired1h.test'], true));
check('legacy row (no ssl_expires_at), days -3: ssl_expired alert', in_array('ssl_expired/critical', $by['legacy.test'], true));
check('5 h left: critical ssl_expiring, not expired', array('ssl_expiring/critical') === $by['hours5.test']);
check('7 d left: warning ssl_expiring', array('ssl_expiring/warning') === $by['week.test']);
check('never probed (-1, ssl_ok false): no expiry alert', !isset($by['unknown.test']));
check('http-only: no alert', !isset($by['plain.test']));
check('stale data: probe-failed alert only, no expiry alert', array('ssl_probe_failed/warning') === $by['stale.test']);
check('ok site: no alert', !isset($by['ok.test']));
check('payload still has api_version 2 and the rows as stored', 2 === $payload['api_version'] && 8 === count($payload['sites']) && !array_key_exists('ssl_expires_at', $payload['sites'][3]));

// --- Sync API key check: one verdict per request, never reused across requests ---
require_once SGBACKUP_DIR . 'includes/class-sync-api.php';
$sync = SiteGuard_Backup_Sync_API::instance();
$sync_key = 'sgs_' . str_repeat('ab', 24);
$options[SiteGuard_Backup_Sync_API::OPTION_HASH] = wp_hash_password($sync_key);
function sync_auth($req) { return code_of(SiteGuard_Backup_Sync_API::instance()->auth($req)); }
$_SERVER = array('REMOTE_ADDR' => '192.0.2.60');
$bcrypt_calls = 0;
$req = new WP_REST_Request('/sgbackup/v1/sync/ping', array('Authorization' => 'Bearer ' . $sync_key));
check('sync: good key ok on both passes', array('ok', 'ok') === array(sync_auth($req), sync_auth($req)));
check('sync: one bcrypt check for the request', 1 === $bcrypt_calls);
$req = new WP_REST_Request('/sgbackup/v1/sync/sets', array('Authorization' => 'Bearer ' . $bad));
check('sync: a later request with a bad key is refused, not served the earlier verdict', 'forbidden' === sync_auth($req));
check('sync: bad key counted once over both passes', 'forbidden' === sync_auth($req) && 1 === attempts('192.0.2.60'));
$req = new WP_REST_Request('/sgbackup/v1/sync/sets', array('Authorization' => 'Bearer ' . $sync_key));
check('sync: good key after a bad one is ok', 'ok' === sync_auth($req));

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
