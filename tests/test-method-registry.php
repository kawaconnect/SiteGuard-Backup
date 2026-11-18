<?php
/**
 * Static consistency checks for the storage method registry.
 *
 * A storage method is listed in several places; when one list missed a method
 * the feature broke silently (Backblaze B2 had a settings panel, but the save
 * handler's allow-list dropped its settings; OneDrive and WebDAV had no
 * settings panel at all). This test reads the source files, so it needs no
 * WordPress and changes nothing.
 *
 *   php tests/test-method-registry.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}

$root = dirname(__DIR__);
$failures = 0;
$count = 0;
function check($name, $cond, $detail = '') {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name" . ('' !== $detail ? " ($detail)" : '') . "\n"; }
}
function src($rel) {
	global $root;
	$s = file_get_contents($root . '/' . $rel);
	if (false === $s) { fwrite(STDERR, "cannot read $rel\n"); exit(2); }
	return $s;
}
/** Quoted strings inside the first "array(...)" after $marker. */
function list_after($source, $marker) {
	$i = strpos($source, $marker);
	if (false === $i) return null;
	$open = strpos($source, 'array(', $i);
	$depth = 0;
	for ($j = $open + 5; $j < strlen($source); $j++) {
		if ('(' === $source[$j]) $depth++;
		if (')' === $source[$j] && 0 === --$depth) break;
	}
	preg_match_all("/'([a-z0-9_]+)'/", substr($source, $open, $j - $open), $m);
	return $m[1];
}

// The registry: method id => label, in class-siteguardbackup.php.
$core = src('class-siteguardbackup.php');
preg_match('/private \$backup_methods = array\((.*?)\);/s', $core, $m);
preg_match_all("/'([a-z0-9]+)'\s*=>/", $m[1], $mm);
$methods = $mm[1];
check('registry lists the storage methods', count($methods) >= 10, implode(',', $methods));
$storage = array_values(array_diff($methods, array('remotesend'))); // remotesend is configured as remote sites

$valid      = list_after(src('includes/class-commands.php'), 'private static $valid_methods');
$configured = list_after(src('admin.php'), '$all_methods = array(');
$tpl        = src('templates/tab-settings.php');
$panels     = list_after($tpl, '$configurable_methods = array(');
$security   = src('includes/class-security.php');
preg_match('/private static \$sensitive_fields = array\((.*?)\n\t\);/s', $security, $sm);
preg_match_all("/'([a-z0-9]+)'\s*=>\s*array\(([^)]*)\)/", $sm[1], $sfm, PREG_SET_ORDER);
$sensitive = array();
foreach ($sfm as $row) {
	preg_match_all("/'([a-z_]+)'/", $row[2], $f);
	$sensitive[$row[1]] = $f[1];
}

foreach ($methods as $id) {
	$file = "methods/{$id}.php";
	$code = @file_get_contents($root . '/' . $file);
	check("$id: $file exists", false !== $code);
	if (false === $code) continue;
	check("$id: defines SiteGuard_Backup_Method_" . ucfirst($id), false !== strpos($code, 'class SiteGuard_Backup_Method_' . ucfirst($id) . ' '));
	check("$id: accepted by the settings save / CLI allow-list", in_array($id, $valid, true));
	if ('remotesend' === $id) continue;
	check("$id: counted as a configured method", in_array($id, $configured, true));
	check("$id: has a settings panel", in_array($id, $panels, true) && false !== strpos($tpl, 'id="sgbackup-config-' . $id . '"'));

	// Every secret-looking option must be encrypted at rest.
	if (preg_match('/function get_default_options\(\)\s*\{\s*return array\((.*?)\);/s', $code, $dm)) {
		preg_match_all("/'([a-z_]+)'\s*=>/", $dm[1], $keys);
		$secret_keys = array_values(array_filter($keys[1], function ($k) {
			return (bool) preg_match('/^(pass|password|secret_key|client_secret|app_secret|access_token|refresh_token|account_key|sas_token|api_key|key|token)$/', $k);
		}));
		$missing = array_diff($secret_keys, isset($sensitive[$id]) ? $sensitive[$id] : array());
		check("$id: secret options are encrypted at rest", empty($missing), 'missing: ' . implode(',', $missing));
	}
}
foreach ($valid as $id) {
	check("allow-list entry $id is a registered method", in_array($id, $methods, true));
}

// OAuth providers implement the sign-in contract used by includes/class-oauth.php.
preg_match("/const PROVIDERS = array\(([^)]*)\)/", src('includes/class-oauth.php'), $pm);
preg_match_all("/'([a-z0-9]+)'/", $pm[1], $providers);
foreach ($providers[1] as $p) {
	$code = src("methods/{$p}.php");
	check("$p: get_auth_url(\$state, \$redirect_uri)", (bool) preg_match('/function get_auth_url\(\$state,\s*\$redirect_uri\)/', $code));
	check("$p: exchange_code(\$code, \$redirect_uri...)", (bool) preg_match('/function exchange_code\(\$code,\s*\$redirect_uri/', $code));
}

echo "\n$count checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
