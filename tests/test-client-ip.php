<?php
/**
 * Unit tests for the client IP used by the IP allowlist, the rate limiters and
 * the audit log (SiteGuard_Backup_Security::get_client_ip / is_trusted_proxy),
 * and the health API's rate-limit bucket (IPv6 /64).
 *
 * Standalone (no WordPress): sanitize_text_field and apply_filters are stubbed.
 *
 *   php tests/test-client-ip.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir() . '/sg-client-ip-test/');
define('SGBACKUP_DIR', dirname(__DIR__) . '/');
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
$filters = array();
function apply_filters($tag, $value) {
	global $filters;
	return isset($filters[$tag]) ? call_user_func($filters[$tag], $value) : $value;
}
require __DIR__ . '/../includes/class-security.php';
require __DIR__ . '/../includes/class-health-monitor.php';
$S = 'SiteGuard_Backup_Security';
$M = 'SiteGuard_Backup_Health_Monitor';

$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
// The client IP for a request from $peer carrying CF-Connecting-IP $cf.
function ip_for($peer, $cf = null) {
	$_SERVER = array('REMOTE_ADDR' => $peer);
	if (null !== $cf) $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf;
	return SiteGuard_Backup_Security::get_client_ip();
}

// --- Default trust: Cloudflare and loopback only -----------------------------
check('Cloudflare IPv4 peer: CF-Connecting-IP honoured', '203.0.113.9' === ip_for('162.158.1.2', '203.0.113.9'));
check('Cloudflare IPv6 peer: CF-Connecting-IP honoured', '2001:db8::9' === ip_for('2606:4700:10::1', '2001:db8::9'));
check('loopback peer: honoured', '203.0.113.9' === ip_for('127.0.0.1', '203.0.113.9'));
check('::1 peer: honoured', '203.0.113.9' === ip_for('::1', '203.0.113.9'));
check('IPv4-mapped loopback peer: honoured', '203.0.113.9' === ip_for('::ffff:127.0.0.1', '203.0.113.9'));
check('direct hit, no header: REMOTE_ADDR', '198.51.100.7' === ip_for('198.51.100.7'));
check('public non-Cloudflare peer: header ignored', '198.51.100.7' === ip_for('198.51.100.7', '203.0.113.9'));
foreach (array('10.1.2.3', '172.19.0.4', '192.168.1.50', '169.254.1.1', 'fd00::5', 'fe80::1', '::ffff:10.0.0.1') as $peer) {
	check("private/link-local peer $peer: header ignored by default", $peer === ip_for($peer, '203.0.113.9'));
}
check('172.64/13 (Cloudflare, not RFC1918) still trusted', '203.0.113.9' === ip_for('172.64.1.1', '203.0.113.9'));
check('trusted peer, invalid header: REMOTE_ADDR', '162.158.1.2' === ip_for('162.158.1.2', 'not-an-ip'));
check('trusted peer, empty header: REMOTE_ADDR', '162.158.1.2' === ip_for('162.158.1.2', ''));
check('no REMOTE_ADDR: empty (header alone never trusted)', '' === ip_for('', '203.0.113.9'));
check('is_trusted_proxy: 10.0.0.1 false by default', !$S::is_trusted_proxy('10.0.0.1'));
check('is_trusted_proxy: garbage false', !$S::is_trusted_proxy('nope'));

// --- Parsing SGBACKUP_TRUSTED_PROXIES ----------------------------------------
check('parse: CIDRs, bare IPs, spaces and commas', array('172.19.0.0/16', '10.0.0.5/32', 'fd00::/8', '2001:db8::1/128')
	=== $S::parse_proxy_list(" 172.19.0.0/16, 10.0.0.5  fd00::/8,,2001:db8::1 "));
check('parse: malformed entries dropped', array('192.168.0.0/24') === $S::parse_proxy_list('bogus, 10.0.0.0/33, 192.168.0.0/24, 1.2.3.4/-1, fd00::/129, 1.2.3.4/x, /8'));
check('parse: array accepted', array('10.0.0.0/8') === $S::parse_proxy_list(array('10.0.0.0/8', 'x')));
check('parse: empty', array() === $S::parse_proxy_list(''));

// --- Filter still extends the list -------------------------------------------
$filters['sgbackup_trusted_proxy_cidrs'] = function ($c) { $c[] = '192.0.2.0/24'; return $c; };
check('filter-added CIDR honoured', '203.0.113.9' === ip_for('192.0.2.10', '203.0.113.9'));
unset($filters['sgbackup_trusted_proxy_cidrs']);
check('filter removed again: ignored', '192.0.2.10' === ip_for('192.0.2.10', '203.0.113.9'));

// --- The constant adds proxies -----------------------------------------------
define('SGBACKUP_TRUSTED_PROXIES', '172.19.0.0/16, 10.9.8.7, fd00:1::/64, junk');
check('constant: peer in listed CIDR honoured', '203.0.113.9' === ip_for('172.19.0.4', '203.0.113.9'));
check('constant: bare IP honoured', '203.0.113.9' === ip_for('10.9.8.7', '203.0.113.9'));
check('constant: IPv6 CIDR honoured', '2001:db8::9' === ip_for('fd00:1::abcd', '2001:db8::9'));
check('constant: other private peer still ignored', '172.20.0.4' === ip_for('172.20.0.4', '203.0.113.9'));
check('constant: neighbour of bare IP ignored', '10.9.8.6' === ip_for('10.9.8.6', '203.0.113.9'));
check('constant: IPv6 outside the /64 ignored', 'fd00:2::1' === ip_for('fd00:2::1', '203.0.113.9'));
check('constant: Cloudflare still trusted', '203.0.113.9' === ip_for('104.16.0.1', '203.0.113.9'));

// --- Rate-limit bucket ---------------------------------------------------------
check('bucket: IPv4 unchanged (existing counters keep their key)', '203.0.113.9' === $M::rate_limit_bucket('203.0.113.9'));
check('bucket: IPv6 /64', '2001:db8:1:2::/64' === $M::rate_limit_bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
check('bucket: same /64 same bucket', $M::rate_limit_bucket('2001:db8:1:2::1') === $M::rate_limit_bucket('2001:db8:1:2:ffff::9'));
check('bucket: different /64 different bucket', $M::rate_limit_bucket('2001:db8:1:2::1') !== $M::rate_limit_bucket('2001:db8:1:3::1'));
check('bucket: IPv4-mapped counts as IPv4', '192.0.2.1' === $M::rate_limit_bucket('::ffff:192.0.2.1'));
check('bucket: two IPv4-mapped addresses stay apart', $M::rate_limit_bucket('::ffff:192.0.2.1') !== $M::rate_limit_bucket('::ffff:192.0.2.2'));
check('bucket: fallback 0.0.0.0 unchanged', '0.0.0.0' === $M::rate_limit_bucket('0.0.0.0'));
check('bucket: garbage passed through', 'x' === $M::rate_limit_bucket('x'));

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
