<?php
/**
 * Regression tests for SiteGuard_Backup_Filesystem::delete_tree()/delete_file().
 *
 * Standalone (no WordPress). Deliberately exercises the delete helpers with
 * empty, root and escaping paths and asserts they refuse.
 *
 * SAFETY: this file calls real delete functions. It refuses to run as root and
 * only works inside a scratch directory it creates itself. Run it as an
 * unprivileged user, ideally in a throwaway container; see tests/README.md.
 *
 *   php tests/test-delete-guard.php
 */

if ('cli' !== PHP_SAPI) {
	http_response_code(404);
	exit;
}
if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
	fwrite(STDERR, "Refusing to run as root. Run as an unprivileged user (see tests/README.md).\n");
	exit(2);
}
if (!function_exists('posix_geteuid') && '0' === trim((string) @shell_exec('id -u'))) {
	fwrite(STDERR, "Refusing to run as root.\n");
	exit(2);
}

// --- Minimal WordPress stand-ins -------------------------------------------
class WP_Error {
	private $code, $message;
	public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

$sandbox = rtrim(sys_get_temp_dir(), '/') . '/sgguard-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($sandbox, 0700) || !($sandbox = realpath($sandbox))) {
	fwrite(STDERR, "Cannot create sandbox\n");
	exit(2);
}
chdir($sandbox); // so realpath('') / relative paths resolve here, not '/'
define('ABSPATH', $sandbox . '/wp/');
mkdir(ABSPATH);
ini_set('error_log', $sandbox . '/error.log');

require __DIR__ . '/../includes/class-filesystem-functions.php';
$FS = 'SiteGuard_Backup_Filesystem';

// --- Tiny harness ------------------------------------------------------------
$failures = 0;
$count = 0;
function check($name, $cond) {
	global $failures, $count;
	$count++;
	if ($cond) { echo "ok   $name\n"; } else { $failures++; echo "FAIL $name\n"; }
}
function refused($r) { return is_wp_error($r) && 'sgbackup_delete_refused' === $r->get_error_code(); }
function mk($path, $content = 'x') {
	if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
	file_put_contents($path, $content);
}

$root    = "$sandbox/backups";
$outside = "$sandbox/outside";
mk("$root/keep.zip");
mk("$outside/precious.txt");

// 1. Empty / root / relative inputs are refused before any walk.
check('empty path and root refused',     refused($FS::delete_tree('', '')));
check('empty root refused',              refused($FS::delete_tree("$root/x", '')));
check('empty path refused',              refused($FS::delete_tree('', $root)));
check('"/" as root refused',             refused($FS::delete_tree('/usr', '/')));
check('"/" as target refused',           refused($FS::delete_tree('/', $root)));
check('relative path refused',           refused($FS::delete_tree('backups', $root)));
check('relative root refused',           refused($FS::delete_tree("$root/x", 'backups')));
check('NUL byte refused',                refused($FS::delete_tree("$root/x\0/y", $root)));
check('empty file path refused',         refused($FS::delete_file('', '')));
check('delete_file with "/" root refused', refused($FS::delete_file('/etc/hostname', '/')));

// 2. Unsafe roots.
foreach (array('/', '/tmp', '/usr', '/var', '/srv', '/opt', '/root', '/home', '/etc') as $bad) {
	check("unsafe root $bad", null !== $FS::unsafe_root_reason($bad));
}
check('root that contains ABSPATH is unsafe', null !== $FS::unsafe_root_reason($sandbox));
check('ABSPATH itself is unsafe as a root',  null !== $FS::unsafe_root_reason(rtrim(ABSPATH, '/')));
check('unresolved .. root is unsafe',         null !== $FS::unsafe_root_reason("$root/../.."));
check('Windows drive root is unsafe',         null !== $FS::unsafe_root_reason('C:\\'));
check('Windows shallow root is unsafe',       null !== $FS::unsafe_root_reason('C:\\inetpub'));
check('Windows deep root accepted',           null === $FS::unsafe_root_reason('C:\\inetpub\\site\\sgbackup'));
check('Windows path is absolute',             $FS::is_absolute_path('D:/x') && $FS::is_absolute_path('d:\\x'));
check('relative path is not absolute',        !$FS::is_absolute_path('x/y') && !$FS::is_absolute_path(''));
check('normalize_path',                       'C:/a/b' === $FS::normalize_path('c:\\a\\\\b\\') && '/' === $FS::normalize_path('//'));
check('sandbox backup root is safe',          null === $FS::unsafe_root_reason(realpath($root)));
check('/tmp as root refused', refused($FS::delete_tree($root, sys_get_temp_dir())));

// 3. Containment.
mk("$sandbox/backups2/victim.txt");
check('sibling with shared prefix refused', refused($FS::delete_tree("$sandbox/backups2", $root)));
check('sibling survived', file_exists("$sandbox/backups2/victim.txt"));
check('dot-dot escape refused', refused($FS::delete_tree("$root/../outside", $root)));
check('outside survived', file_exists("$outside/precious.txt"));
check('root itself refused', refused($FS::delete_tree($root, $root)));
check('root survived', file_exists("$root/keep.zip"));
check('delete_file on directory refused', refused($FS::delete_file("$root", $sandbox . '/backups')));

// 4. Symlinks are removed as links, never followed.
mk("$root/job1/a.txt");
symlink($outside, "$root/job1/link-to-outside");
check('tree with link deleted', true === $FS::delete_tree("$root/job1", $root));
check('tree gone', !file_exists("$root/job1"));
check('link target contents untouched', file_exists("$outside/precious.txt"));

symlink($outside, "$root/toplink");
check('link as target: link removed', true === $FS::delete_tree("$root/toplink", $root));
check('link as target: target untouched', file_exists("$outside/precious.txt") && !is_link("$root/toplink"));

symlink("$outside/precious.txt", "$root/filelink");
check('delete_file on link removes link', true === $FS::delete_file("$root/filelink", $root));
check('file link target untouched', file_exists("$outside/precious.txt"));

symlink($sandbox, "$root/escape");
check('path through escaping link refused', refused($FS::delete_tree("$root/escape/outside", $root)));
check('outside survived link escape', file_exists("$outside/precious.txt"));

// 5. keep_dir empties but keeps the root.
mk("$root/tmpjunk/b.txt");
check('keep_dir on root allowed', true === $FS::delete_tree($root, $root, true));
check('root kept and emptied (escape link removed, not followed)', is_dir($root) && array('.', '..') === scandir($root));
check('outside survived keep_dir walk', file_exists("$outside/precious.txt"));

// 6. Failures stop and are reported, not swallowed.
mk("$root/locked/sub/c.txt");
chmod("$root/locked/sub", 0500);
$r = $FS::delete_tree("$root/locked", $root);
check('undeletable file returns sgbackup_delete_failed', is_wp_error($r) && 'sgbackup_delete_failed' === $r->get_error_code());
chmod("$root/locked/sub", 0700);
check('retry after fix succeeds', true === $FS::delete_tree("$root/locked", $root));

// 7. Missing target is not an error for delete_tree.
check('missing dir is a no-op', true === $FS::delete_tree("$root/does-not-exist", $root));

echo "\n$count checks, $failures failures\n";
echo "sandbox: $sandbox (remove when done)\n";
exit($failures ? 1 : 0);
