<?php
/**
 * Unit tests for backup exclusion matching
 * (SiteGuard_Backup_Filesystem::path_matches_exclude) and the built-in
 * "others" exclusion list. No WordPress, no files touched.
 *
 * The old test was "path starts with the exclude, or contains /exclude": it
 * silently dropped languages/plugins/*.mo, mu-plugins/x/cache-manager.php and
 * any nested cache directory from the "others" archive.
 *
 *   php tests/test-exclude-match.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}

define('ABSPATH', sys_get_temp_dir() . '/sg-exclude-test/');
define('SGBACKUP_DIR', dirname(__DIR__) . '/');
require __DIR__ . '/../includes/class-filesystem-functions.php';
$F = 'SiteGuard_Backup_Filesystem';

$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
$hit = function ($path, $exclude) use ($F) { return $F::path_matches_exclude($path, $exclude); };
$others = function ($path) use ($F) {
	foreach ($F::wp_others_excludes() as $ex) {
		if ($F::path_matches_exclude($path, $ex)) return true;
	}
	return false;
};

// Whole segments, at any depth, for an admin's own exclude.
check('cache matches cache/x', $hit('cache/x.php', 'cache'));
check('cache matches a/cache/x', $hit('a/cache/x.php', 'cache'));
check('cache matches a/cache (the directory itself)', $hit('a/cache', 'cache'));
check('cache does not match cache-manager.php', !$hit('cache-manager.php', 'cache'));
check('cache does not match a/cache-manager.php', !$hit('a/cache-manager.php', 'cache'));
check('cache does not match a/mycache/x', !$hit('a/mycache/x.php', 'cache'));
check('upgrade does not match a/upgrade.php', !$hit('a/upgrade.php', 'upgrade'));
check('a/b matches a/b/c', $hit('a/b/c.txt', 'a/b'));
check('a/b matches x/a/b/c', $hit('x/a/b/c.txt', 'a/b'));
check('a file name matches exactly', $hit('debug.log', 'debug.log'));
check('a file name matches in a subdirectory', $hit('x/debug.log', 'debug.log'));
check('a file name does not match a longer name', !$hit('x/debug.log.1', 'debug.log'));
check('leading and trailing slashes on the path are ignored', $hit('/cache/x.php', 'cache'));
check('Windows separators', $hit('a\\cache\\x.php', 'cache'));
check('empty exclude matches nothing', !$hit('a/b', ''));

// Anchored at the entity root with a leading slash.
check('/wp-content matches wp-content/x', $hit('wp-content/x.php', '/wp-content'));
check('/wp-content does not match a/wp-content/x', !$hit('a/wp-content/x.php', '/wp-content'));
check('/wp-config.php matches wp-config.php', $hit('wp-config.php', '/wp-config.php'));
check('/wp-config.php does not match sub/wp-config.php', !$hit('sub/wp-config.php', '/wp-config.php'));

// The built-in list for "others" (wp-content without plugins/themes/uploads).
foreach (array('plugins/akismet/akismet.php', 'themes/x/style.css', 'uploads/2026/a.png', 'upgrade/x.zip', 'cache/page.html', 'sgbackup/backup_x.zip', 'updraft/b.zip', 'wflogs/config.php') as $p) {
	check("others leaves out $p", $others($p));
}
foreach (array('languages/plugins/akismet-de_DE.mo', 'languages/themes/x-de_DE.mo', 'mu-plugins/x/cache-manager.php', 'mu-plugins/x/upgrade.php', 'mu-plugins/x/cache/data.php', 'mu-plugins/loader.php', 'object-cache.php', 'advanced-cache.php', 'fonts/uploads/a.woff2') as $p) {
	check("others keeps $p", !$others($p));
}

echo "\n$count checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
