<?php
/**
 * Tests for SiteGuard_Backup_Health_Key_Policy (whose health monitor key a
 * restored site ends up with).
 *
 * Standalone (no WordPress), pure in-memory: it touches no files. Refuses to run
 * as root or over the web, like the other tests; see tests/README.md.
 *
 *   php tests/test-health-key-policy.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', '/nonexistent/');
require __DIR__ . '/../includes/class-health-key-policy.php';
$P = 'SiteGuard_Backup_Health_Key_Policy';

$failures = 0; $count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}

// Stub hasher: salted like bcrypt (two hashes of one key differ), checkable below.
function stub_hash($plain) {
	return '$2y$10$' . bin2hex(random_bytes(4)) . '$' . sha1($plain);
}
function stub_check($plain, $hash) {
	return is_string($hash) && substr($hash, -40) === sha1($plain);
}
$ctx = array('hasher' => 'stub_hash', 'origin' => 'prod.example.com', 'site_origin' => 'standby.example.com', 'backup_ts' => 1700000000, 'now' => 1800000000);

$H = $P::HASH; $L = $P::LEGACY; $M = $P::META;
$EN = 'sgbackup_health_enabled'; $IV = 'sgbackup_health_interval'; $TO = 'sgbackup_health_timeout';

$plainA = str_repeat('A', 44) . 'a1b2';   // this site's key
$plainB = str_repeat('B', 44) . '9f3c';   // the backup's key
$plainX = str_repeat('X', 44) . 'xxxx';   // a stale legacy plaintext next to a hash
$HA = stub_hash($plainA);
$HB = stub_hash($plainB);
$metaA = serialize($P::build_meta($plainA, 'generated', 'standby.example.com', 1));
$metaB = serialize($P::build_meta($plainB, 'generated', 'prod.example.com', 1));

// Destination cases: rows, the plaintext that should validate (null: no key).
$dests = array(
	'none'          => array(array(), null),
	'hash'          => array(array("options:$H" => $HA, "options:$M" => $metaA, "options:$EN" => '1', "options:$IV" => 'every5min'), $plainA),
	'legacy'        => array(array("options:$L" => $plainA, "options:$EN" => '1'), $plainA),
	'hash+legacy'   => array(array("options:$H" => $HA, "options:$L" => $plainX, "options:$EN" => '1'), $plainA),
	'enabled0'      => array(array("options:$EN" => '0', "options:$IV" => 'every5min'), null),
	'sitemeta-only' => array(array("sitemeta:$H" => $HA, "sitemeta:$M" => $metaA, "sitemeta:$EN" => '1'), $plainA),
	'empty-hash'    => array(array("options:$H" => '', "options:$EN" => '1'), null),
);
// Backup cases (identical-to-dest is built per destination).
$backups = array(
	'none'       => array(array("options:$EN" => '1', "options:$IV" => 'everyhour'), null),
	'hash'       => array(array("options:$H" => $HB, "options:$M" => $metaB, "options:$EN" => '1', "options:$IV" => 'everyhour', "options:$TO" => '5'), $plainB),
	'legacy'     => array(array("options:$L" => $plainB, "options:$EN" => '1', "options:$IV" => 'everyhour'), $plainB),
	'identical'  => null,
	'empty-hash' => array(array("options:$H" => '', "options:$EN" => '1'), null),
);

function effective_value(array $rows, $name) {
	foreach (array('options:', 'sitemeta:') as $s) {
		if (isset($rows[$s . $name]) && '' !== $rows[$s . $name]) return $rows[$s . $name];
	}
	return null;
}
function key_validates(array $rows, $plain) {
	global $H;
	$hash = effective_value($rows, $H);
	return null !== $plain && null !== $hash && stub_check($plain, $hash);
}
function apply_plan(array $imported, array $plan) {
	foreach ($plan['delete'] as $k) unset($imported[$k]);
	foreach ($plan['write'] as $k => $v) $imported[$k] = $v;
	ksort($imported);
	return $imported;
}

foreach (array('keep', 'backup') as $policy) {
	foreach ($dests as $dname => $dcase) {
		list($drows, $dplain) = $dcase;
		foreach ($backups as $bname => $bcase) {
			if ('identical' === $bname) {
				if (null === $dplain) continue;
				list($brows, $bplain) = array($drows, $dplain);
			} else {
				list($brows, $bplain) = $bcase;
			}
			$t = "$policy / dest=$dname / backup=$bname";
			$plan = $P::resolve($policy, $drows, $brows, $ctx);

			$bk = null !== $bplain;
			$dk = null !== $dplain;
			if (!$bk) { $exp = array('destination', 'no_backup_key', 'no_backup_key'); }
			elseif ('identical' === $bname) { $exp = array('destination', 'identical', 'unchanged'); }
			elseif ('backup' === $policy) { $exp = array('backup', 'policy_backup', 'took_backup'); }
			elseif (!$dk) { $exp = array('backup', 'dest_has_no_key', 'adopted_backup'); }
			else { $exp = array('destination', 'dest_has_key', 'kept_site'); }
			check("$t: source/reason/outcome", array($plan['source'], $plan['reason'], $plan['outcome']) === $exp);

			$want_plain = ('backup' === $exp[0]) ? $bplain : $dplain;
			$final = $plan['final'];
			if (null !== $want_plain) {
				check("$t: the chosen key validates", key_validates($final, $want_plain));
			} else {
				check("$t: no key", null === effective_value($final, $H));
			}
			$other = ('backup' === $exp[0]) ? $dplain : $bplain;
			if (null !== $other && $other !== $want_plain) {
				check("$t: the other key does not validate", !key_validates($final, $other));
			}
			check("$t: no legacy plaintext row", !isset($final["options:$L"]) && !isset($final["sitemeta:$L"]));

			// Settings travel with the key.
			if ('backup' === $exp[0]) {
				check("$t: enabled from the backup", effective_value($final, $EN) === effective_value($brows, $EN));
				check("$t: interval from the backup", effective_value($final, $IV) === effective_value($brows, $IV));
			} else {
				// This site's settings, else any the backup alone has.
				foreach (array('enabled' => $EN, 'interval' => $IV) as $label => $name) {
					$want = effective_value($drows, $name);
					if (null === $want) $want = effective_value($brows, $name);
					check("$t: $label kept", effective_value($final, $name) === $want);
				}
			}

			check("$t: declined hash only when the backup's key was not applied",
				('kept_site' === $plan['outcome']) === (null !== $plan['declined_hash']));
			if ('kept_site' === $plan['outcome']) {
				check("$t: declined hash is the backup's key", stub_check($bplain, $plan['declined_hash']));
			}

			$blob = json_encode($plan) . serialize($plan) . $P::summary($plan);
			$leak = false;
			foreach (array($plainA, $plainB, $plainX) as $plain) {
				if (false !== strpos($blob, $plain)) $leak = true;
			}
			check("$t: no plaintext in the output", !$leak);
			$summary = $P::summary($plan);
			check("$t: summary shows no hash", false === strpos($summary, '$2y$') && false === strpos($summary, '$wp$'));

			ksort($final);
			check("$t: write/delete turn the imported rows into the result", apply_plan($brows, $plan) === $final);

			$again = $P::resolve($policy, $plan['final'], $plan['final'], $ctx);
			check("$t: idempotent", in_array($again['outcome'], array('unchanged', 'no_backup_key'), true)
				&& array() === $again['write'] && array() === $again['delete'] && $again['final'] == $plan['final']);
		}
	}
}

// Specific cases from the spec.
$plan = $P::resolve('keep', $dests['sitemeta-only'][0], $backups['hash'][0], $ctx);
check('sitemeta-only destination key is not hidden by the backup\'s per-site row',
	!isset($plan['final']["options:$H"]) && $plan['final']["sitemeta:$H"] === $HA && in_array("options:$H", $plan['delete'], true));

// Settings kept only in wp_sitemeta are not hidden by the backup's per-site rows
// either: a backup's enabled=0 made the kept key answer 404 "disabled".
$shadow_dest = array("sitemeta:$H" => $HA, "sitemeta:$EN" => '1', "sitemeta:$IV" => 'every5min');
$shadow_backup = array("options:$H" => $HB, "options:$EN" => '0', "options:$IV" => 'everyhour', "options:$TO" => '5', "sitemeta:$EN" => '1');
$plan = $P::resolve('keep', $shadow_dest, $shadow_backup, $ctx);
check('sitemeta-only settings: this site\'s key kept', 'kept_site' === $plan['outcome'] && $plan['final']["sitemeta:$H"] === $HA);
check('sitemeta-only settings: the backup\'s per-site enabled=0 is dropped', !isset($plan['final']["options:$EN"]) && in_array("options:$EN", $plan['delete'], true)
	&& '1' === effective_value($plan['final'], $EN));
check('sitemeta-only settings: this site\'s interval is not hidden', 'every5min' === effective_value($plan['final'], $IV));
check('sitemeta-only settings: a setting this site lacks still comes from the backup', '5' === $plan['final']["options:$TO"]);
$again = $P::resolve('keep', $plan['final'], $plan['final'], $ctx);
check('sitemeta-only settings: idempotent', 'unchanged' === $again['outcome'] && array() === $again['write'] && array() === $again['delete']);

$plan = $P::resolve('keep', $dests['enabled0'][0], $backups['hash'][0], $ctx);
check('keyless standby with enabled=0 adopts the backup\'s unit', 'adopted_backup' === $plan['outcome'] && '1' === $plan['final']["options:$EN"]);
check('keyless standby: monitor enabled from the backup', true === $plan['enabled_from_backup']
	&& false !== strpos($P::summary($plan), 'Monitor enabled from the backup'));
check('keyless standby: backup timeout comes along', '5' === $plan['final']["options:$TO"]);

$plan = $P::resolve('keep', $dests['hash'][0], $backups['hash'][0], $ctx);
check('kept_site summary names both hints and the origin',
	false !== strpos($P::summary($plan), '…a1b2') && false !== strpos($P::summary($plan), '…9f3c') && false !== strpos($P::summary($plan), 'prod.example.com'));
check('kept_site keeps this site\'s interval', 'every5min' === $plan['final']["options:$IV"]);
check('kept_site: backup-only setting rows survive (as in 1.5.1)', '5' === $plan['final']["options:$TO"]);

// Subsite into single (fill_settings false): this site's unit wins alone; the
// subsite's settings do not switch on a monitor this site never enabled.
$nofill = $ctx + array('fill_settings' => false);
$plan = $P::resolve('keep', array("options:$H" => $HA), $backups['hash'][0], $nofill);
check('no fill: this site\'s key kept', 'kept_site' === $plan['outcome'] && $plan['final']["options:$H"] === $HA);
check('no fill: no settings row from the backup', array("options:$H" => $HA) === $plan['final']
	&& in_array("options:$EN", $plan['delete'], true) && in_array("options:$IV", $plan['delete'], true));
$plan = $P::resolve('keep', array("options:$H" => $HA, "options:$EN" => '0'), $backups['none'][0], $nofill);
check('no fill, backup without a key: this site\'s rows exactly', array("options:$H" => $HA, "options:$EN" => '0') === $plan['final']);
$plan = $P::resolve('keep', array(), $backups['hash'][0], $nofill);
check('no fill, keyless site: the backup\'s unit is still used', 'adopted_backup' === $plan['outcome'] && '1' === $plan['final']["options:$EN"] && 'everyhour' === $plan['final']["options:$IV"]);

// This site's legacy plaintext is kept in restore state only as a hash.
$rows = $P::hash_legacy(array("options:$L" => $plainA, "options:$EN" => '1'), 'stub_hash', 'standby.example.com', 5);
check('hash_legacy: no plaintext row, the key validates', !isset($rows["options:$L"]) && stub_check($plainA, $rows["options:$H"]) && '1' === $rows["options:$EN"]);
$meta = $P::parse_meta($rows["options:$M"]);
check('hash_legacy: meta with the key\'s fingerprint', $meta && $P::fingerprint($plainA) === $meta['fp'] && 'legacy-migrated' === $meta['source']);
check('hash_legacy: no plaintext anywhere', false === strpos(serialize($rows), $plainA));
$rows = $P::hash_legacy(array("options:$H" => $HA, "sitemeta:$L" => $plainX), 'stub_hash', 'x', 5);
check('hash_legacy: an existing hash wins, the stale plaintext is dropped', array("options:$H" => $HA) === $rows);
$rows = $P::hash_legacy($dests['hash'][0], 'stub_hash', 'x', 5);
check('hash_legacy: rows without plaintext are unchanged', $dests['hash'][0] === $rows);
$plan1 = $P::resolve('keep', $dests['legacy'][0], $backups['hash'][0], $ctx);
$plan2 = $P::resolve('keep', $P::hash_legacy($dests['legacy'][0], 'stub_hash', 'standby.example.com', 5), $backups['hash'][0], $ctx);
check('hash_legacy: a re-run decides the same', $plan1['outcome'] === $plan2['outcome'] && $plan1['report'] === $plan2['report']
	&& stub_check($plainA, $plan2['final']["options:$H"]));

$plan = $P::resolve('backup', $dests['hash'][0], $backups['legacy'][0], $ctx);
check('legacy backup key under "backup" is stored only as a hash', stub_check($plainB, $plan['final']["options:$H"]) && !isset($plan['final']["options:$L"]));
$meta = $P::parse_meta($plan['final']["options:$M"]);
check('legacy backup key gets meta with its fingerprint', $meta && $meta['fp'] === $P::fingerprint($plainB) && 'legacy-migrated' === $meta['source'] && 'prod.example.com' === $meta['origin']);

$plan = $P::resolve('keep', $dests['hash+legacy'][0], $backups['none'][0], $ctx);
check('a stale legacy row next to a hash is dropped, the hash kept', $plan['final']["options:$H"] === $HA && !isset($plan['final']["options:$L"]));

$plan = $P::resolve('keep', $dests['hash'][0], $backups['legacy'][0], $ctx);
check('legacy backup plaintext no longer hijacks this site\'s key', 'kept_site' === $plan['outcome'] && $plan['final']["options:$H"] === $HA && in_array("options:$L", $plan['delete'], true));

$plan = $P::resolve('bogus', $dests['hash'][0], $backups['hash'][0], $ctx);
check('an invalid policy is treated as keep', 'keep' === $plan['policy'] && 'kept_site' === $plan['outcome']);

$plan = $P::resolve('backup', array(), array(), $ctx);
check('nothing on either side is a no-op', 'no_backup_key' === $plan['outcome'] && array() === $plan['final']);

// Helpers.
check('sanitize accepts keep', 'keep' === $P::sanitize('keep'));
check('sanitize accepts backup', 'backup' === $P::sanitize('backup'));
foreach (array("''" => '', "'BACKUP'" => 'BACKUP', 'array' => array('backup'), 'null' => null, "' backup'" => ' backup', '1' => 1) as $label => $v) {
	check("sanitize rejects $label", null === $P::sanitize($v));
}
check('fingerprint format', 'sgk-ba7816bf8f01' === $P::fingerprint('abc'));
check('hint is the last four characters', '9f3c' === $P::hint($plainB));
$bcrypt = password_hash('x', PASSWORD_BCRYPT);
check('hash format: $wp$2y$', $P::is_valid_hash('$wp' . $bcrypt));
check('hash format: $2y$', $P::is_valid_hash($bcrypt));
check('hash format: $P$', $P::is_valid_hash('$P$Babcdefghijklmnopqrstuvwxyz0123'));
check('hash format: $argon2id', $P::is_valid_hash('$argon2id$v=19$m=65536,t=4,p=1$dmNxd2UwanFTbWVxYkg3QQ$1CMvGIfsvrbIww7HeOHjZXOjSrNhqGbrwxf4bsVCmX4'));
// What docker-compose leaves of a pasted pin ("$wp", "$abc..." read as variables).
foreach (array('$2y$10', '$wp$2y$10$', '$wp$2y$10$abcdefghijklmnopqrstuv', substr('$wp' . $bcrypt, 0, -1), '$P$', '$P$Babc', '$argon2', '$argon2id$v=19$m=65536,t=4,p=1$', $bcrypt . "\n") as $bad) {
	check('hash format rejects truncated ' . json_encode($bad), !$P::is_valid_hash($bad));
}
check('hash format rejects plaintext', !$P::is_valid_hash($plainA));
check('hash format rejects whitespace', !$P::is_valid_hash('$2y$10$abc def'));
check('hash format rejects arrays', !$P::is_valid_hash(array('$2y$10$abc')));
check('plain key: 32 alphanumerics accepted', $P::is_valid_plain_key(str_repeat('a', 32)));
check('plain key: 31 rejected', !$P::is_valid_plain_key(str_repeat('a', 31)));
check('plain key: punctuation rejected', !$P::is_valid_plain_key(str_repeat('a', 32) . '-'));
check('meta with a bad fingerprint is not trusted', '' === $P::parse_meta(serialize(array('fp' => '<script>', 'hint' => 'ab"c')))['fp']);
check('meta never unserializes objects', null === $P::parse_meta('O:8:"stdClass":0:{}') || !is_object($P::parse_meta('O:8:"stdClass":0:{}')));

echo "\n{$count} checks, {$failures} failed\n";
exit($failures ? 1 : 0);
