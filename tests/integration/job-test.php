<?php
/**
 * Helper for the "jobs" case of storage.sh (run with wp eval-file).
 *
 *   configure '<json {method: options}>'
 *       save the destinations through the same command the settings page uses
 *       (savesettings: method settings + service list), as instance s-0
 *   legacy-email        reproduce the pre-1.7.0 option collision, check the migration
 *   mkbucket <method>   create the bucket of an S3-family destination
 *   seed-token <method> '<json token>'
 *       store an OAuth token on s-0 (stands in for a completed sign-in)
 *   verify
 *       for the newest backup set: every archive is listed on every configured
 *       destination and downloads byte-identical to the local copy
 */
if (!defined('ABSPATH') || !class_exists('SiteGuard_Backup')) { fwrite(STDERR, "run with wp eval-file\n"); exit(2); }
require_once SGBACKUP_DIR . 'includes/class-commands.php';
require_once SGBACKUP_DIR . 'methods/backup-module.php';

$cmd = isset($args[0]) ? $args[0] : '';
$opts = SiteGuard_Backup_Options::instance();

if ('configure' === $cmd) {
	$dest = json_decode($args[1], true);
	$ms = array();
	foreach ($dest as $m => $o) {
		$o['instance_enabled'] = '1';
		$ms[$m] = array('settings' => array('s-0' => $o));
	}
	$c = new SiteGuard_Backup_Commands();
	// Keep the local archives so verify can compare them with the uploads.
	// The settings form always sends the entity checkboxes; missing means unchecked.
	$r = $c->savesettings(array('method_settings' => $ms, 'service' => array_keys($dest), 'delete_local' => 0,
		'include_entities' => array('plugins', 'themes', 'uploads', 'others')));
	if (is_wp_error($r)) { echo "FAIL savesettings: ", $r->get_error_message(), "\n"; exit(1); }
	$saved = (array) $opts->get_option('sgbackup_service', array());
	sort($saved); $want = array_keys($dest); sort($want);
	echo $saved === $want ? "  ok   destinations saved through the settings command: " . implode(', ', $saved) . "\n"
		: "  FAIL service list after save: " . json_encode($saved) . "\n";
	foreach ($dest as $m => $o) {
		$st = $opts->get_option(SiteGuard_Backup_Options::method_option($m), array());
		if (empty($st['settings']['s-0'])) { echo "  FAIL $m settings were not stored\n"; exit(1); }
	}
	exit($saved === $want ? 0 : 1);
}

if ('legacy-email' === $cmd) {
	// What versions before 1.7.0 left behind: the Email destination's settings
	// stored as "sgbackup_email", the report address option.
	delete_option('sgbackup_email_destination');
	update_option('sgbackup_email', array('version' => 1, 'settings' => array('s-0' => array('address' => 'legacy@example.test', 'instance_enabled' => '1'))));
	$name = SiteGuard_Backup_Options::method_option('email');
	$report = $opts->get_option('sgbackup_email', null);
	$dest = $opts->get_option($name, array());
	$ok = 'sgbackup_email_destination' === $name && is_string($report)
		&& isset($dest['settings']['s-0']['address']) && 'legacy@example.test' === $dest['settings']['s-0']['address'];
	echo $ok ? "  ok   legacy Email destination settings moved off the report address option\n" : "  FAIL legacy email migration: " . json_encode(array($name, $report, $dest)) . "\n";
	exit($ok ? 0 : 1);
}

if ('mkbucket' === $cmd) { // S3-family destination: create its bucket through the module's signed request()
	$m = $args[1];
	require_once SGBACKUP_DIR . 'methods/' . $m . '.php';
	$class = 'SiteGuard_Backup_Method_' . ucfirst($m);
	$mod = new $class();
	$mod->set_instance_id('s-0');
	$sr = new ReflectionMethod($mod, 'get_signing_region'); $sr->setAccessible(true); $region = $sr->invoke($mod);
	$cfg = 'us-east-1' === $region ? '' : '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>' . $region . '</LocationConstraint></CreateBucketConfiguration>';
	$rq = new ReflectionMethod($mod, 'request'); $rq->setAccessible(true);
	$r = $rq->invoke($mod, 'PUT', '', $cfg, array(), true);
	if (is_wp_error($r)) { echo "  FAIL bucket: ", $r->get_error_message(), "\n"; exit(1); }
	exit(0);
}

if ('seed-token' === $cmd) {
	$m = $args[1];
	$st = $opts->get_option(SiteGuard_Backup_Options::method_option($m), array());
	$st['settings']['s-0']['token'] = json_decode($args[2], true);
	$opts->update_option(SiteGuard_Backup_Options::method_option($m), $st);
	exit(0);
}

if ('verify' === $cmd) {
	$fails = 0;
	$set = SiteGuard_Backup_History::get_latest();
	if (!$set) { echo "  FAIL no backup in history\n"; exit(1); }
	$status = isset($set['status']) ? $set['status'] : '';
	$files = array();
	foreach (array('db', 'plugins', 'themes', 'uploads', 'others', 'wpcore') as $e) {
		if (!empty($set[$e])) $files = array_merge($files, (array) $set[$e]);
	}
	$dir = trailingslashit($opts->get_backup_dir());
	echo "  -- backup set " . $set['timestamp'] . ": " . count($files) . " archive(s); recorded services: " . implode(',', (array) $set['service']) . "\n";
	$work = rtrim(get_temp_dir(), '/') . '/sg-job-' . wp_generate_password(6, false);
	wp_mkdir_p($work);
	foreach ((array) $opts->get_option('sgbackup_service', array()) as $m) {
		if ('email' === $m) continue; // upload-only; storage.sh checks the mailbox
		require_once SGBACKUP_DIR . 'methods/' . $m . '.php';
		$class = 'SiteGuard_Backup_Method_' . ucfirst($m);
		$mod = new $class();
		$mod->set_instance_id('s-0');
		$listed = array_map('basename', (array) $mod->listfiles());
		$missing = array_diff($files, $listed);
		if ($missing) { $fails++; echo "  FAIL $m: not on the destination: " . implode(', ', $missing) . "\n"; continue; }
		$bad = array();
		foreach ($files as $f) {
			$r = $mod->download($f, $work);
			if (true !== $r || !file_exists("$work/$f") || md5_file("$work/$f") !== md5_file($dir . $f)) $bad[] = $f;
			if (file_exists("$work/$f")) unlink("$work/$f");
		}
		if ($bad) { $fails++; echo "  FAIL $m: download differs or failed: " . implode(', ', $bad) . "\n"; }
		else echo "  ok   $m: all " . count($files) . " archives uploaded by the backup job, listed, and download byte-identical\n";
	}
	@rmdir($work);
	exit($fails ? 1 : 0);
}
fwrite(STDERR, "usage: configure '<json>' | seed-token <method> '<json>' | verify\n");
exit(2);
