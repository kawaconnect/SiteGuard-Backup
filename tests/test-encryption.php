<?php
/**
 * Tests for SiteGuard_Backup_Encryption (backup archive encryption).
 *
 * Standalone (no WordPress). Works only inside a scratch directory it creates
 * and refuses to run as root or over the web; see tests/README.md.
 *
 *   php tests/test-encryption.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

// --- Minimal stand-ins -------------------------------------------------------
define('ABSPATH', '/nonexistent/');
class SiteGuard_Backup {
	public static $log = array();
	public static function instance() { return new self(); }
	public function log($msg, $level = 'info') { self::$log[] = "[{$level}] {$msg}"; }
}

require __DIR__ . '/../includes/class-encryption.php';
$E = 'SiteGuard_Backup_Encryption';

$sandbox = rtrim(sys_get_temp_dir(), '/') . '/sgenc-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($sandbox, 0700)) { fwrite(STDERR, "Cannot create sandbox\n"); exit(2); }

$failures = 0; $count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
function write_random($path, $size) {
	$fh = fopen($path, 'wb');
	while ($size > 0) { $n = min($size, 65536); fwrite($fh, random_bytes($n)); $size -= $n; }
	fclose($fh);
}

$pass = 'correct horse battery staple';

foreach (array('empty' => 0, 'small' => 1000, 'exact-chunk' => 65536, 'multi-chunk' => 300000) as $label => $size) {
	$plain = "$sandbox/$label.zip";
	write_random($plain, $size);
	$orig_md5 = md5_file($plain);
	$crypt = $E::encrypt($plain, $pass);
	check("$label: encrypts", is_string($crypt) && file_exists($crypt));
	check("$label: detected as encrypted", $crypt && $E::is_encrypted($crypt));
	rename($plain, "$plain.orig"); // decrypt writes next to the .crypt
	$out = $crypt ? $E::decrypt($crypt, $pass) : false;
	check("$label: decrypts to identical bytes", is_string($out) && md5_file($out) === $orig_md5);
	if ($out) unlink($out);
}

// Tampering and wrong keys are refused and leave nothing behind.
$plain = "$sandbox/t.zip";
write_random($plain, 200000);
$crypt = $E::encrypt($plain, $pass);
unlink($plain);
$bytes = file_get_contents($crypt);

check('wrong passphrase refused', false === $E::decrypt($crypt, 'wrong'));
check('no output left after refusal', !file_exists($plain) && !file_exists("$plain.tmp"));

file_put_contents($crypt, substr($bytes, 0, strlen($bytes) - 70000)); // truncated upload/download
check('truncated file refused', false === $E::decrypt($crypt, $pass));
check('no partial output after truncation', !file_exists($plain) && !file_exists("$plain.tmp"));

$flipped = $bytes; $flipped[100000] = chr(ord($flipped[100000]) ^ 1);
file_put_contents($crypt, $flipped);
check('flipped byte refused', false === $E::decrypt($crypt, $pass));

file_put_contents($crypt, substr($bytes, 0, -32)); // trailer missing
check('missing authentication trailer refused', false === $E::decrypt($crypt, $pass));

file_put_contents($crypt, $bytes);
$out = $E::decrypt($crypt, $pass);
check('intact file still decrypts after the above', is_string($out) && filesize($out) === 200000);
if ($out) unlink($out);

// Encrypting a file that is not fully readable must fail, not produce a
// valid encryption of part of it (the plaintext is deleted after success).
check('unreadable source refused', false === @$E::encrypt("$sandbox/does-not-exist.zip", $pass));

// Cleanup: only files this test created.
foreach (glob("$sandbox/*") ?: array() as $f) unlink($f);
rmdir($sandbox);

echo "\n$count checks, $failures failures\n";
exit($failures ? 1 : 0);
