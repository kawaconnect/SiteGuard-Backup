<?php
/**
 * Autoloader for the bundled phpseclib 2.x (namespace phpseclib\, PSR-4 rooted
 * at this directory). phpseclib 2 expects Composer's autoloader; without one,
 * requiring Net/SFTP.php fails with "Class phpseclib\Net\SSH2 not found", which
 * made SFTP/SCP a fatal error on every host without the ssh2 extension.
 *
 * Only registers for the phpseclib\ prefix, and only once. If another plugin
 * already loaded phpseclib 2 the classes exist and this loader is never asked.
 */

if (!defined('ABSPATH')) die('No direct access.');

if (!function_exists('sgbackup_phpseclib_autoload')) {
	function sgbackup_phpseclib_autoload($class) {
		$prefix = 'phpseclib\\';
		if (0 !== strncmp($class, $prefix, strlen($prefix))) return;
		$relative = substr($class, strlen($prefix));
		if ('' === $relative || false !== strpos($relative, '..')) return;
		$file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
		if (is_file($file)) require_once $file;
	}
	spl_autoload_register('sgbackup_phpseclib_autoload');
}
