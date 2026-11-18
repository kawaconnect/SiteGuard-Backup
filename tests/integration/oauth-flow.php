<?php
/**
 * Helper for the "oauthflow" case of storage.sh (run with wp eval-file).
 *
 *   setup <method>   store app credentials on instances s-0 (decoy) and s-1,
 *                    no tokens; print an admin login cookie and AJAX nonce
 *                    minted in that login session
 *   verify <method>  check s-1 holds a working sign-in and s-0 was untouched,
 *                    then save settings the way the settings form does (no
 *                    token field) and check the sign-in survives
 *   redirect         print the OAuth redirect URI
 */
if (!defined('ABSPATH') || !class_exists('SiteGuard_Backup')) { fwrite(STDERR, "run with wp eval-file\n"); exit(2); }

$cmd = isset($args[0]) ? $args[0] : '';
$method = isset($args[1]) ? $args[1] : '';
$creds = array(
	'googledrive' => array('client_id' => 'sg-gd-client.apps.googleusercontent.com', 'client_secret' => 'sg-gd-secret', 'folder_name' => 'SiteGuard Backups'),
	'dropbox'     => array('app_key' => 'sg-dbx-key', 'app_secret' => 'sg-dbx-secret', 'folder' => '/SiteGuard Backups'),
	'onedrive'    => array('client_id' => '11111111-2222-3333-4444-555555555555', 'client_secret' => 'sg-od-secret', 'tenant' => 'common', 'folder' => 'SiteGuard Backups'),
	'pcloud'      => array('client_id' => 'sg-pc-client', 'client_secret' => 'sg-pc-secret', 'folder_id' => 0, 'use_eu' => false),
);
require_once SGBACKUP_DIR . 'includes/class-oauth.php';
require_once SGBACKUP_DIR . 'includes/class-security.php';

if ('redirect' === $cmd) { echo SiteGuard_Backup_OAuth::redirect_uri(), "\n"; exit(0); }
if (!isset($creds[$method])) { fwrite(STDERR, "unknown method\n"); exit(2); }
$opts = SiteGuard_Backup_Options::instance();

if ('setup' === $cmd) {
	$decoy = array_merge($creds[$method], array('instance_label' => 'decoy'));
	$opts->update_option(SiteGuard_Backup_Options::method_option($method), array('version' => 1, 'settings' => array(
		's-0' => $decoy,
		's-1' => array_merge($creds[$method], array('instance_label' => 'second account')),
	)));
	$admin = get_users(array('role' => 'administrator', 'number' => 1));
	$uid = $admin[0]->ID;
	wp_set_current_user($uid);
	$exp = time() + 3600;
	$token = WP_Session_Tokens::get_instance($uid)->create($exp);
	$cookie = wp_generate_auth_cookie($uid, $exp, 'logged_in', $token);
	$_COOKIE[LOGGED_IN_COOKIE] = $cookie; // so the nonce belongs to this session
	echo 'COOKIE=', LOGGED_IN_COOKIE, '=', rawurlencode($cookie), "\n";
	echo 'NONCE=', wp_create_nonce('sgbackup-ajax'), "\n";
	exit(0);
}

if ('verify' === $cmd) {
	$fails = 0;
	$check = function ($name, $ok, $detail = '') use (&$fails, $method) {
		echo $ok ? "  ok   {$method}: {$name}\n" : "  FAIL {$method}: {$name}" . ('' !== $detail ? " ({$detail})" : '') . "\n";
		if (!$ok) $fails++;
	};
	$stored = $opts->get_option(SiteGuard_Backup_Options::method_option($method), array());
	$s0 = $stored['settings']['s-0'];
	$s1 = $stored['settings']['s-1'];
	$tok_of = function ($i) use ($method) {
		return 'pcloud' === $method ? (isset($i['access_token']) ? $i['access_token'] : '') : (isset($i['token']['access_token']) ? $i['token']['access_token'] : '');
	};
	$check('token stored on the instance that signed in (s-1)', '' !== $tok_of($s1));
	$check('other instance (s-0) untouched', '' === $tok_of($s0));
	if ('pcloud' !== $method) {
		$check('refresh token stored', !empty($s1['token']['refresh_token']));
	} else {
		$check('EU data region detected from the callback', !empty($s1['use_eu']));
	}

	// The "Test Connection" button for s-1, through the command the button calls.
	require_once SGBACKUP_DIR . 'includes/class-commands.php';
	$c = new SiteGuard_Backup_Commands();
	$r = $c->credentials_test(array('method' => $method, 'instance' => 's-1'));
	$check('Test Connection (instance s-1) succeeds', !is_wp_error($r), is_wp_error($r) ? $r->get_error_message() : '');
	$r0 = $c->credentials_test(array('method' => $method, 'instance' => 's-0'));
	$check('Test Connection (instance s-0, not signed in) fails', is_wp_error($r0));

	// Save settings the way the form does: the fields only, no token.
	$form = $creds[$method];
	foreach ($form as $k => $v) {
		if (false !== strpos($k, 'secret')) $form[$k] = SiteGuard_Backup_Security::SECRET_UNCHANGED;
	}
	if ('pcloud' === $method) $form['access_token'] = SiteGuard_Backup_Security::SECRET_UNCHANGED;
	$form['instance_label'] = 'renamed';
	$res = $c->savesettings(array('method_settings' => array($method => array('settings' => array('s-1' => $form)))));
	$check('settings save succeeded', !is_wp_error($res), is_wp_error($res) ? $res->get_error_message() : '');
	$after = $opts->get_option(SiteGuard_Backup_Options::method_option($method), array());
	$check('sign-in survives a settings save', $tok_of($after['settings']['s-1']) === $tok_of($s1) && '' !== $tok_of($s1));
	$check('settings save applied the form change', 'renamed' === $after['settings']['s-1']['instance_label']);
	exit($fails ? 1 : 0);
}
fwrite(STDERR, "usage: setup|verify <method> | redirect\n");
exit(2);
