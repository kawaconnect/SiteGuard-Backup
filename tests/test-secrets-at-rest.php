<?php
/**
 * Tests for secrets at rest (SiteGuard_Backup_Security): encryption of stored
 * credentials, the 1.7.1 upgrade migration, a changed or missing AUTH_KEY, and
 * the mysqldump option-file quoting (SiteGuard_Backup_Database_Utility).
 *
 * Standalone (no WordPress), pure in-memory: it touches no files. Refuses to run
 * as root or over the web, like the other tests; see tests/README.md.
 *
 *   php tests/test-secrets-at-rest.php
 *
 * It runs itself once more without AUTH_KEY (argument "nokey"), because a PHP
 * constant cannot be undefined inside one process.
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

$nokey = isset($argv[1]) && 'nokey' === $argv[1];

define('ABSPATH', '/nonexistent/');
define('SGBACKUP_DIR', dirname(__DIR__) . '/');
if (!$nokey) {
	define('AUTH_KEY', 'test auth key: a long random phrase from wp-config.php');
}

// --- Minimal stand-ins -------------------------------------------------------
class SiteGuard_Backup_Options {
	public $o = array();
	private static $i;
	public static function instance() { return self::$i ?: (self::$i = new self()); }
	public function get_option($k, $d = false) { return array_key_exists($k, $this->o) ? $this->o[$k] : $d; }
	public function update_option($k, $v) { $this->o[$k] = $v; return true; }
	public function delete_option($k) { unset($this->o[$k]); return true; }
	public static function method_option($m) { return 'sgbackup_' . $m; }
	public function get_sensitive_option($key, $default = false) {
		$v = $this->get_option('sgbackup_' . $key, $default);
		return is_string($v) ? SiteGuard_Backup_Security::decrypt_option($v) : $v;
	}
	public function update_sensitive_option($key, $value) {
		if (is_string($value) && '' !== $value && SiteGuard_Backup_Security::encryption_on()) {
			$value = SiteGuard_Backup_Security::encrypt_option($value);
		}
		return $this->update_option('sgbackup_' . $key, $value);
	}
}
class SiteGuard_Backup {
	public static $log = array();
	public static function instance() { return new self(); }
	public function log($msg, $level = 'info') { self::$log[] = "[{$level}] {$msg}"; }
}
function get_option($k, $d = false) { return SiteGuard_Backup_Options::instance()->get_option($k, $d); }
function update_option($k, $v, $autoload = null) { return SiteGuard_Backup_Options::instance()->update_option($k, $v); }
function delete_option($k) { return SiteGuard_Backup_Options::instance()->delete_option($k); }
$TRANSIENTS = array();
function get_transient($k) { global $TRANSIENTS; return isset($TRANSIENTS[$k]) ? $TRANSIENTS[$k] : false; }
function set_transient($k, $v, $t = 0) { global $TRANSIENTS; $TRANSIENTS[$k] = $v; return true; }
function delete_transient($k) { global $TRANSIENTS; unset($TRANSIENTS[$k]); return true; }
function wp_json_encode($v) { return json_encode($v); }
function apply_filters($tag, $v) { return $v; }

require __DIR__ . '/../includes/class-security.php';
require __DIR__ . '/../includes/class-database-utility.php';
$S = 'SiteGuard_Backup_Security';
$O = SiteGuard_Backup_Options::instance();

$failures = 0; $count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
function reset_store() {
	global $TRANSIENTS;
	SiteGuard_Backup_Options::instance()->o = array();
	$TRANSIENTS = array();
}
// Ciphertext in the plugin's format, made with some other AUTH_KEY.
function foreign_ciphertext($plain, $other_auth_key) {
	$key = hash('sha256', $other_auth_key . 'sgbackup_credential_encryption', true);
	$iv = random_bytes(12);
	$tag = '';
	$ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
	return 'sgenc:' . base64_encode($iv . $tag . $ct);
}

if ($nokey) {
	// ---- AUTH_KEY not defined ----------------------------------------------
	reset_store();
	check('nokey: cannot encrypt', !$S::can_encrypt());
	check('nokey: encrypt_option keeps plaintext', 'secret' === $S::encrypt_option('secret'));
	check('nokey: plaintext still reads', 'secret' === $S::decrypt_option('secret'));
	$O->o['sgbackup_s3'] = array('version' => 1, 'settings' => array('s-0' => array('accesskey' => 'AK', 'secret_key' => 'plain-sk')));
	$r = $S::maybe_migrate();
	check('nokey: migration does not run', false === $r['ran'] && 'no key' === $r['reason']);
	check('nokey: plaintext credential untouched', 'plain-sk' === $O->o['sgbackup_s3']['settings']['s-0']['secret_key']);
	check('nokey: migration not marked done (retried once a key exists)', !isset($O->o[$S::MIGRATION_OPTION]));
	// Ciphertext without a key: never handed out as the credential.
	$enc = foreign_ciphertext('sk', 'whatever');
	check('nokey: ciphertext decrypts to empty, not to itself', '' === $S::decrypt_option($enc));
	$flag = $O->get_option($S::UNREADABLE_OPTION);
	check('nokey: flagged as key_missing', is_array($flag) && 'key_missing' === $flag['reason']);
	echo "\n{$count} checks, {$failures} failed\n";
	exit($failures ? 1 : 0);
}

// ---- Round trip ---------------------------------------------------------------
reset_store();
check('can encrypt with AUTH_KEY', $S::can_encrypt());
$e = $S::encrypt_option('p@ss "word" #1');
check('encrypted value has prefix', 0 === strpos($e, 'sgenc:'));
check('round trip', 'p@ss "word" #1' === $S::decrypt_option($e));
check('no double encryption', $e === $S::encrypt_option($e));
check('plaintext passes through decrypt', 'legacy-plain' === $S::decrypt_option('legacy-plain'));
check('two encryptions differ (random IV)', $S::encrypt_option('x') !== $S::encrypt_option('x'));
check('encryption is on by default (option absent)', $S::encryption_on());
$O->o['sgbackup_encrypt_credentials'] = 0;
check('encryption can be turned off', !$S::encryption_on());
unset($O->o['sgbackup_encrypt_credentials']);

$tok = array('access_token' => 'at', 'refresh_token' => 'rt', 'expires' => 123);
$m = $S::encrypt_method_options('dropbox', array('token' => $tok, 'folder' => '/x'));
check('array token stored encrypted', is_string($m['token']) && $S::is_encrypted($m['token']));
check('non-secret field untouched', '/x' === $m['folder']);
$d = $S::decrypt_method_options('dropbox', $m);
check('array token round trip', $tok === $d['token']);

// ---- Migration ------------------------------------------------------------------
reset_store();
$O->o['sgbackup_s3'] = array('version' => 1, 'settings' => array(
	's-0' => array('accesskey' => 'AK', 'secret_key' => 'plain-sk', 'path' => 'bucket/x'),
	's-1' => array('accesskey' => 'AK2', 'secret_key' => ''),
));
$O->o['sgbackup_ftp'] = array('host' => 'ftp.example', 'user' => 'u', 'pass' => 'ftp-pass'); // legacy flat format
$O->o['sgbackup_encryption_key'] = 'my backup passphrase';
$O->o['sgbackup_webhook_secret'] = 'whsec-40-chars';
$r = $S::maybe_migrate();
check('migration ran', true === $r['ran']);
check('migration encrypted 4 secrets', 4 === $r['encrypted']);
check('encrypt_credentials switched on', 1 === $O->o['sgbackup_encrypt_credentials']);
check('migration marked done', 1 === $O->o[$S::MIGRATION_OPTION]);
check('key check stored', $S::key_fingerprint() === $O->o[$S::KEY_CHECK_OPTION] && 16 === strlen($S::key_fingerprint()));
$sk = $O->o['sgbackup_s3']['settings']['s-0']['secret_key'];
check('s3 secret encrypted', $S::is_encrypted($sk) && 'plain-sk' === $S::decrypt_option($sk));
check('s3 non-secret fields untouched', 'AK' === $O->o['sgbackup_s3']['settings']['s-0']['accesskey'] && 'bucket/x' === $O->o['sgbackup_s3']['settings']['s-0']['path']);
check('empty secret stays empty', '' === $O->o['sgbackup_s3']['settings']['s-1']['secret_key']);
check('legacy flat ftp pass encrypted', $S::is_encrypted($O->o['sgbackup_ftp']['pass']) && 'ftp-pass' === $S::decrypt_option($O->o['sgbackup_ftp']['pass']));
check('passphrase encrypted, readable', $S::is_encrypted($O->o['sgbackup_encryption_key']) && 'my backup passphrase' === $O->get_sensitive_option('encryption_key', ''));
check('webhook secret encrypted, readable', $S::is_encrypted($O->o['sgbackup_webhook_secret']) && 'whsec-40-chars' === $O->get_sensitive_option('webhook_secret', ''));
check('no unreadable flag after migration', !$O->get_option($S::UNREADABLE_OPTION));
$before = $O->o;
$r = $S::maybe_migrate();
check('second run does nothing', false === $r['ran'] && $before === $O->o);

// Admin turned encryption off before the upgrade: the migration turns it on once.
reset_store();
$O->o['sgbackup_encrypt_credentials'] = 0;
$O->o['sgbackup_webhook_secret'] = 'w';
$S::maybe_migrate();
check('migration turns encryption on', 1 === $O->o['sgbackup_encrypt_credentials'] && $S::is_encrypted($O->o['sgbackup_webhook_secret']));
// ...and an admin who turns it off afterwards keeps it off.
$O->o['sgbackup_encrypt_credentials'] = 0;
$S::maybe_migrate();
check('turned off after the upgrade stays off', 0 === $O->o['sgbackup_encrypt_credentials']);
$O->update_sensitive_option('encryption_key', 'new-pass');
check('with encryption off, new secrets are stored as typed', 'new-pass' === $O->o['sgbackup_encryption_key']);

// Busy lock: a second concurrent request does not run it.
reset_store();
set_transient('sgbackup_secrets_migrating', 1);
$r = $S::maybe_migrate();
check('concurrent run skipped', 'busy' === $r['reason'] && !isset($O->o[$S::MIGRATION_OPTION]));

// ---- AUTH_KEY changed -----------------------------------------------------------
reset_store();
$foreign = foreign_ciphertext('old-secret-key', 'the PREVIOUS auth key');
$O->o['sgbackup_s3'] = array('version' => 1, 'settings' => array('s-0' => array('accesskey' => 'AK', 'secret_key' => $foreign)));
$O->o['sgbackup_encryption_key'] = foreign_ciphertext('old passphrase', 'the PREVIOUS auth key');
$O->o[$S::KEY_CHECK_OPTION] = 'aaaaaaaaaaaaaaaa';
$O->o[$S::MIGRATION_OPTION] = 1;
check('foreign ciphertext is unreadable', $S::is_unreadable($foreign));
$S::check_key();
$flag = $O->get_option($S::UNREADABLE_OPTION);
check('changed key is noticed before use', is_array($flag) && 'key_changed' === $flag['reason']);
check('flag lists both secrets', is_array($flag) && in_array('sgbackup_encryption_key', $flag['items'], true) && in_array('s3/s-0/secret_key', $flag['items'], true));
check('unreadable credential reads as empty, never as the ciphertext', '' === $S::decrypt_option($foreign));
check('stored ciphertext is still there', $foreign === $O->o['sgbackup_s3']['settings']['s-0']['secret_key']);
// A save that did not re-enter it keeps the ciphertext (no silent loss).
$kept = $S::keep_unreadable('s3', array('accesskey' => 'AK', 'secret_key' => ''), $O->o['sgbackup_s3']['settings']['s-0']);
check('save without re-entry keeps the ciphertext', $foreign === $kept['secret_key']);
$kept = $S::keep_unreadable('s3', array('accesskey' => 'AK', 'secret_key' => $S::SECRET_UNCHANGED), $O->o['sgbackup_s3']['settings']['s-0']);
check('unchanged placeholder keeps the ciphertext', $foreign === $kept['secret_key']);
$kept = $S::keep_unreadable('s3', array('accesskey' => 'AK', 'secret_key' => 'typed-new'), $O->o['sgbackup_s3']['settings']['s-0']);
check('a re-entered credential replaces it', 'typed-new' === $kept['secret_key']);
// Re-entering everything clears the notice.
$O->o['sgbackup_s3']['settings']['s-0']['secret_key'] = $S::encrypt_option('typed-new');
$O->o['sgbackup_encryption_key'] = $S::encrypt_option('typed passphrase');
check('recheck finds nothing unreadable', array() === $S::recheck_secrets());
check('notice cleared', !$O->get_option($S::UNREADABLE_OPTION));
check('key check updated to the current key', $S::key_fingerprint() === $O->o[$S::KEY_CHECK_OPTION]);

// A database copied from another site with no secrets: no notice, key check follows.
reset_store();
$O->o[$S::KEY_CHECK_OPTION] = 'bbbbbbbbbbbbbbbb';
$O->o[$S::MIGRATION_OPTION] = 1;
$S::check_key();
check('no secrets: no notice', !$O->get_option($S::UNREADABLE_OPTION));
check('no secrets: key check adopted', $S::key_fingerprint() === $O->o[$S::KEY_CHECK_OPTION]);

// Old (1.7.0) flag value 1 is cleared once everything decrypts.
reset_store();
$O->o[$S::UNREADABLE_OPTION] = 1;
$O->o['sgbackup_webhook_secret'] = $S::encrypt_option('w');
$S::maybe_migrate();
check('stale 1.7.0 flag cleared by the migration', !$O->get_option($S::UNREADABLE_OPTION));

// ---- mysqldump option file values -----------------------------------------------
$U = 'SiteGuard_Backup_Database_Utility';
// Parse a value the way the MySQL/MariaDB client reads an option file line
// (mysys my_default.c: comment scan, trim, outer quote strip, escapes).
function client_reads($line_value) {
	$quote = ''; $escape = false; $cut = strlen($line_value);
	for ($i = 0; $i < strlen($line_value); $i++) {
		$c = $line_value[$i];
		if (("'" === $c || '"' === $c) && !$escape) {
			if ('' === $quote) $quote = $c; elseif ($quote === $c) $quote = '';
		}
		if ('' === $quote && '#' === $c) { $cut = $i; break; }
		$escape = ('' !== $quote && '\\' === $c && !$escape);
	}
	$v = trim(substr($line_value, 0, $cut));
	if (strlen($v) >= 2 && ("'" === $v[0] || '"' === $v[0]) && $v[strlen($v) - 1] === $v[0]) $v = substr($v, 1, -1);
	$out = '';
	for ($i = 0; $i < strlen($v); $i++) {
		if ('\\' === $v[$i] && $i < strlen($v) - 1) {
			$n = $v[++$i];
			$map = array('n' => "\n", 't' => "\t", 'r' => "\r", 'b' => "\x08", 's' => ' ', '\\' => '\\');
			$out .= isset($map[$n]) ? $map[$n] : '\\' . $n;
		} else {
			$out .= $v[$i];
		}
	}
	return $out;
}
foreach (array('plain', 'with#hash', 'a"quote', "it's", 'back\\slash', ' spaced ', "tab\there", 'x#"y', "x#'y", '\\"', 'end\\') as $pw) {
	$q = $U::option_file_value($pw);
	check('option file keeps ' . json_encode($pw), null !== $q && $pw === client_reads($q));
}
check('both quote kinds: no option-file form', null === $U::option_file_value('a"b\'c'));

echo "\n-- without AUTH_KEY --\n";
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' nokey', $sub_rc);
check('run without AUTH_KEY passed', 0 === $sub_rc);

echo "\n{$count} checks, {$failures} failed\n";
exit($failures ? 1 : 0);
