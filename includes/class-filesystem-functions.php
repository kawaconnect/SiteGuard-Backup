<?php
/**
 * SiteGuard Backup - Filesystem Utility Functions
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Filesystem {

	/**
	 * Get the WordPress content directory.
	 */
	public static function wp_content_dir() {
		return trailingslashit(WP_CONTENT_DIR);
	}

	/**
	 * Get the WordPress plugins directory.
	 */
	public static function wp_plugins_dir() {
		return trailingslashit(WP_PLUGIN_DIR);
	}

	/**
	 * Get the WordPress themes directory.
	 */
	public static function wp_themes_dir() {
		return trailingslashit(get_theme_root());
	}

	/**
	 * Get the uploads directory.
	 */
	public static function wp_uploads_dir() {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['basedir']);
	}

	/**
	 * Get "others" directory - wp-content excluding plugins, themes, uploads, and cache dirs.
	 */
	public static function wp_others_excludes() {
		// Leading "/": only at the top of wp-content. Unanchored, "plugins" also
		// dropped languages/plugins/*.mo, and "cache" any nested cache directory.
		return array('/plugins', '/themes', '/uploads', '/upgrade', '/cache', '/sgbackup', '/updraft', '/wflogs');
	}

	/**
	 * Does $relative (path below the entity root) fall under $exclude?
	 *
	 * Whole path segments only: "cache" matches cache/x and a/cache/x, not
	 * cache-manager.php (the old prefix/substring test dropped such files
	 * silently). A leading "/" anchors the exclude at the entity root.
	 */
	public static function path_matches_exclude($relative, $exclude) {
		$relative = trim(str_replace('\\', '/', (string) $relative), '/');
		$exclude = str_replace('\\', '/', (string) $exclude);
		$anchored = ('' !== $exclude && '/' === $exclude[0]);
		$exclude = trim($exclude, '/');
		if ('' === $exclude || '' === $relative) return false;
		if ($relative === $exclude || 0 === strpos($relative, $exclude . '/')) return true;
		if ($anchored) return false;
		$needle = '/' . $exclude;
		$pos = strpos($relative, $needle . '/');
		if (false !== $pos) return true;
		return substr($relative, -strlen($needle)) === $needle;
	}

	/**
	 * Get all backup entities and their paths.
	 */
	public static function get_backup_entities() {
		return array(
			'plugins' => array(
				'path'  => self::wp_plugins_dir(),
				'label' => __('Plugins', 'siteguardbackup'),
			),
			'themes' => array(
				'path'  => self::wp_themes_dir(),
				'label' => __('Themes', 'siteguardbackup'),
			),
			'uploads' => array(
				'path'  => self::wp_uploads_dir(),
				'label' => __('Uploads', 'siteguardbackup'),
			),
			'others' => array(
				'path'  => self::wp_content_dir(),
				'label' => __('Others (wp-content excl. plugins/themes/uploads)', 'siteguardbackup'),
			),
			'wpcore' => array(
				'path'  => ABSPATH,
				'label' => __('WordPress Core', 'siteguardbackup'),
				// wp-content is handled by the plugins/themes/uploads/others entities - don't duplicate.
				// wp-config.php IS included by design: for full disaster recovery you need the DB
				// credentials, salts, and any custom constants it defines. Operators must treat
				// the backup archive as sensitive and secure its storage location accordingly.
				'excludes' => array('/wp-content'),
			),
		);
	}

	/**
	 * Get the total size of a directory.
	 */
	public static function directory_size($path) {
		$size = 0;
		if (!is_dir($path)) return $size;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::LEAVES_ONLY,
				// A permission-denied child dir would otherwise throw
				// UnexpectedValueException and abort the whole size scan.
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);

			foreach ($iterator as $file) {
				if ($file->isFile() && !is_dir($file->getPathname())) {
					$size += $file->getSize();
				}
			}
		} catch (Exception $e) {
			// Permission denied or vanished entry mid-scan - return what we have.
		}

		return $size;
	}

	/**
	 * Enumerate files in a directory, optionally filtering by mtime.
	 *
	 * @param string   $path           Directory to scan.
	 * @param array    $excludes       Relative paths/names to exclude.
	 * @param int|null $altered_since   Only include files modified after this timestamp.
	 * @param string   $strip_prefix   Prefix to strip from paths in results.
	 * @return array   Array of ['path' => relative_path, 'size' => bytes, 'mtime' => timestamp].
	 */
	public static function enumerate_files($path, $excludes = array(), $altered_since = null, $strip_prefix = '') {
		// Thin wrapper over the streaming generator so the two can never diverge.
		// Prefer enumerate_files_stream() directly when the caller can consume
		// entries one at a time - this variant holds the whole tree in memory.
		$files = array();
		foreach (self::enumerate_files_stream($path, $excludes, $altered_since, $strip_prefix) as $entry) {
			$files[] = $entry;
		}
		return $files;
	}

	/**
	 * Streaming generator variant of enumerate_files().
	 *
	 * Yields one entry at a time instead of building the entire file tree in
	 * memory, avoiding OOM on very large sites. Each yielded value has the same
	 * shape as an element of enumerate_files()'s return array:
	 *   ['path' => relative_path, 'size' => bytes, 'mtime' => timestamp, 'full_path' => resolved_path]
	 *
	 * backup.php streams this straight into the JSONL file-list cache so a
	 * 100k-file uploads tree costs O(1) memory to enumerate.
	 *
	 * @param string   $path          Directory to scan.
	 * @param array    $excludes      Relative paths/names to exclude.
	 * @param int|null $altered_since Only include files modified after this timestamp.
	 * @param string   $strip_prefix  Prefix to strip from paths in results.
	 * @return \Generator
	 */
	public static function enumerate_files_stream($path, $excludes = array(), $altered_since = null, $strip_prefix = '') {
		if (!is_dir($path)) return;

		$path = trailingslashit($path);
		$abspath_real = defined('ABSPATH') ? realpath(ABSPATH) : false;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | 0),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			$iterator->setMaxDepth(32);

			foreach ($iterator as $item) {
				if ($item->isDir() || is_dir($item->getPathname())) continue;
				if (!$item->isFile() && !$item->isLink()) continue;

				$full_path = $item->getPathname();
				$original_path = $full_path;

				if ($item->isLink()) {
					$real = realpath($full_path);
					if (false === $real) continue;
					if ($abspath_real && strpos($real, $abspath_real . DIRECTORY_SEPARATOR) !== 0 && $real !== $abspath_real) {
						continue;
					}
					if (!is_file($real)) continue;
					$full_path = $real;
				}

				if (!$item->isFile() && !is_file($full_path)) continue;
				$relative = str_replace($path, '', $original_path);

				$skip = false;
				foreach ($excludes as $exclude) {
					if ('' === $exclude) continue;
					if (strpbrk($exclude, '*?[') !== false) {
						if (fnmatch($exclude, $relative, FNM_PATHNAME) || fnmatch($exclude, basename($relative))) {
							$skip = true;
							break;
						}
					} elseif (self::path_matches_exclude($relative, $exclude)) {
						$skip = true;
						break;
					}
				}
				if ($skip) continue;

				// A file removed between the directory read and the stat throws
				// RuntimeException; skip that one file instead of ending the whole
				// walk (which would record a truncated list as complete).
				try {
					$mtime = $item->getMTime();
					$size = $item->getSize();
				} catch (\Throwable $stat_err) {
					continue;
				}
				if (null !== $altered_since && $mtime <= $altered_since) continue;

				$file_path = $relative;
				if (!empty($strip_prefix)) {
					$file_path = str_replace($strip_prefix, '', $relative);
				}

				yield array(
					'path'      => $file_path,
					'size'      => $size,
					'mtime'     => $mtime,
					'full_path' => $full_path,
				);
			}
		} catch (Exception $e) {
			// Permission denied or similar - stop early with what we've yielded.
		}
	}

	/**
	 * Clean up old temporary files in the backup directory.
	 *
	 * @param string $backup_dir The backup directory path.
	 * @param int    $max_age    Max age in seconds (default 12 hours).
	 */
	public static function clean_temp_files($backup_dir, $max_age = 43200) {
		if (!is_dir($backup_dir)) return;

		$now = time();
		$iterator = new DirectoryIterator($backup_dir);

		foreach ($iterator as $file) {
			if ($file->isDot()) continue;
			$name = $file->getFilename();

			// Clean temp directories (ziptemp_*, dbtemp_*)
			$is_directory = $file->isDir() || is_dir($file->getPathname());
			if ($is_directory && preg_match('/^(ziptemp_|dbtemp_)/', $name)) {
				if (($now - $file->getMTime()) > $max_age) {
					self::delete_tree($file->getPathname(), $backup_dir);
				}
				continue;
			}

			if ($is_directory) continue;

			// Only clean temp files
			if (preg_match('/\.(tmp|partial|gz\.tmp)$/', $name)) {
				if (($now - $file->getMTime()) > $max_age) {
					self::delete_file($file->getPathname(), $backup_dir);
				}
			}
		}
	}

	/**
	 * Recursively remove a directory that lives inside the backup directory.
	 *
	 * Kept for existing callers; bounded to the backup root. Use delete_tree()
	 * with an explicit root for anything else.
	 *
	 * @return true|WP_Error
	 */
	public static function rmdir_recursive($dir) {
		$root = SiteGuard_Backup_Options::instance()->get_backup_dir();
		return self::delete_tree($dir, $root);
	}

	// ==========================================
	// GUARDED DELETION
	//
	// Every recursive or file delete in the plugin must go through
	// delete_tree() / delete_file(). They refuse to act unless the target
	// resolves strictly inside an explicit, sane root, never follow symlinks
	// while walking, never suppress errors, and stop at the first failure.
	// ==========================================

	/** Minimum number of path segments an allowed root must have (/a/b). */
	const MIN_ROOT_DEPTH = 2;

	/**
	 * System directories that may never be a deletion root, however they were reached.
	 */
	private static $forbidden_roots = array(
		'/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib32', '/lib64', '/libx32',
		'/media', '/mnt', '/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/tmp',
		'/usr', '/usr/bin', '/usr/lib', '/usr/local', '/usr/sbin', '/usr/share',
		'/var', '/var/lib', '/var/log', '/var/www', '/var/tmp',
	);

	/**
	 * Forward slashes, no duplicate or trailing slash (except a bare root).
	 */
	public static function normalize_path($path) {
		$path = preg_replace('#/+#', '/', str_replace('\\', '/', (string) $path));
		if (preg_match('#^[A-Za-z]:/?$#', $path)) {
			return strtoupper($path[0]) . ':/';
		}
		if (preg_match('#^[A-Za-z]:/#', $path)) {
			$path = strtoupper($path[0]) . substr($path, 1);
		}
		return ('/' === $path) ? $path : rtrim($path, '/');
	}

	/**
	 * Absolute on POSIX ("/x") or Windows ("C:/x", "C:\\x").
	 */
	public static function is_absolute_path($path) {
		$path = str_replace('\\', '/', (string) $path);
		return '' !== $path && ('/' === $path[0] || (bool) preg_match('#^[A-Za-z]:/#', $path));
	}

	/**
	 * Why a directory cannot serve as a deletion root, or null if it can.
	 *
	 * @param string $real_root Already-resolved absolute path.
	 * @return string|null
	 */
	public static function unsafe_root_reason($real_root) {
		if (!is_string($real_root) || !self::is_absolute_path($real_root)) {
			return 'root is empty or not absolute';
		}
		$root = self::normalize_path($real_root);
		$segments = array_values(array_filter(explode('/', $root), 'strlen'));
		if (isset($segments[0]) && preg_match('#^[A-Z]:$#', $segments[0])) {
			array_shift($segments); // drive letter is not a level
		}
		if (!$segments) {
			return 'root is the filesystem root';
		}
		if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
			return 'root is not a resolved path';
		}
		if (in_array($root, self::$forbidden_roots, true)) {
			return 'root is a system directory';
		}
		if (count($segments) < self::MIN_ROOT_DEPTH) {
			return 'root is too shallow';
		}
		// A root that is, or contains, the WordPress install would let a bad
		// relative name delete WordPress itself.
		if (defined('ABSPATH')) {
			$abspath = realpath(ABSPATH);
			$abspath = self::normalize_path($abspath ? $abspath : ABSPATH);
			if ($abspath === $root || 0 === strpos($abspath . '/', $root . '/')) {
				return 'root is or contains the WordPress install';
			}
		}
		return null;
	}

	/**
	 * Resolve $path and verify it lies strictly inside $root.
	 *
	 * A symlink target is judged by where the link itself sits, not where it
	 * points, so deleting a link can never reach outside the root.
	 *
	 * @param string $path
	 * @param string $root
	 * @param bool   $allow_root_itself Whether $path may equal $root.
	 * @return array|WP_Error array(real_path, real_root)
	 */
	public static function resolve_contained($path, $root, $allow_root_itself = false) {
		if (!is_string($path) || !is_string($root) || '' === trim($path) || '' === trim($root)) {
			return new WP_Error('sgbackup_delete_refused', 'Refusing to delete: empty path or root');
		}
		if (false !== strpos($path, "\0") || false !== strpos($root, "\0")) {
			return new WP_Error('sgbackup_delete_refused', 'Refusing to delete: NUL byte in path');
		}
		if (!self::is_absolute_path($path) || !self::is_absolute_path($root)) {
			// realpath('') and relative paths resolve against the CWD, which may be '/'.
			return new WP_Error('sgbackup_delete_refused', "Refusing to delete relative path {$path} (root {$root})");
		}

		$real_root = realpath($root);
		if (false === $real_root) {
			return new WP_Error('sgbackup_delete_refused', "Refusing to delete: root does not exist: {$root}");
		}
		$real_root = self::normalize_path($real_root);
		$reason = self::unsafe_root_reason($real_root);
		if (null !== $reason) {
			return new WP_Error('sgbackup_delete_refused', "Refusing to delete under {$real_root}: {$reason}");
		}

		if (is_link($path)) {
			$parent = realpath(dirname($path));
			$real_path = (false === $parent) ? false : rtrim(self::normalize_path($parent), '/') . '/' . basename($path);
		} else {
			$real_path = realpath($path);
		}
		if (false === $real_path) {
			return new WP_Error('sgbackup_delete_missing', "Path does not exist: {$path}");
		}
		$real_path = self::normalize_path($real_path);

		if ($real_path === $real_root) {
			if (!$allow_root_itself) {
				return new WP_Error('sgbackup_delete_refused', "Refusing to delete the root itself: {$real_root}");
			}
		} elseif (0 !== strpos($real_path, rtrim($real_root, '/') . '/')) {
			return new WP_Error('sgbackup_delete_refused', "Refusing to delete outside {$real_root}: {$path}");
		}

		return array($real_path, $real_root);
	}

	/**
	 * Delete one file (or symlink) that must lie inside $root.
	 *
	 * @return true|WP_Error A missing file is reported as sgbackup_delete_missing.
	 */
	public static function delete_file($path, $root) {
		$resolved = self::resolve_contained($path, $root);
		if (is_wp_error($resolved)) {
			self::report_refusal($resolved);
			return $resolved;
		}
		list($real_path) = $resolved;
		if (is_dir($real_path) && !is_link($real_path)) {
			return new WP_Error('sgbackup_delete_refused', "Refusing to delete a directory as a file: {$real_path}");
		}
		return self::checked_fs_call('unlink', $real_path);
	}

	/**
	 * Recursively delete a directory that must lie inside $root.
	 *
	 * Symlinks inside the tree are removed as links; their targets are never
	 * entered. Stops and returns the first error instead of carrying on.
	 *
	 * @param string $dir
	 * @param string $root
	 * @param bool   $keep_dir Empty $dir but leave it in place (then $dir may equal $root).
	 * @return true|WP_Error
	 */
	public static function delete_tree($dir, $root, $keep_dir = false) {
		$resolved = self::resolve_contained($dir, $root, $keep_dir);
		if (is_wp_error($resolved)) {
			if ('sgbackup_delete_missing' === $resolved->get_error_code()) return true;
			self::report_refusal($resolved);
			return $resolved;
		}
		list($real_dir) = $resolved;

		if (is_link($dir)) {
			// A link to a directory: remove the link, never what it points at.
			return $keep_dir ? true : self::checked_fs_call('unlink', $real_dir);
		}
		if (!is_dir($real_dir)) {
			return new WP_Error('sgbackup_delete_refused', "Not a directory: {$real_dir}");
		}

		$prefix = rtrim($real_dir, '/') . '/';
		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($real_dir, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($items as $item) {
				$p = self::normalize_path($item->getPathname());
				if (0 !== strpos($p, $prefix)) {
					return new WP_Error('sgbackup_delete_refused', "Walk escaped {$real_dir}: {$p}");
				}
				// isLink() first: isDir() follows links.
				if ($item->isLink() || !$item->isDir()) {
					$r = self::checked_fs_call('unlink', $p);
				} else {
					$r = self::checked_fs_call('rmdir', $p);
				}
				if (is_wp_error($r)) return $r;
			}
		} catch (Exception $e) {
			return new WP_Error('sgbackup_delete_failed', "Could not walk {$real_dir}: " . $e->getMessage());
		}

		return $keep_dir ? true : self::checked_fs_call('rmdir', $real_dir);
	}

	/**
	 * Run unlink()/rmdir() without '@', turning a warning into a WP_Error.
	 */
	private static function checked_fs_call($fn, $path) {
		$message = '';
		set_error_handler(function ($errno, $errstr) use (&$message) {
			$message = $errstr;
			return true;
		});
		try {
			$ok = ('rmdir' === $fn) ? rmdir($path) : unlink($path);
		} finally {
			restore_error_handler();
		}
		clearstatcache(true, $path);
		if (!$ok) {
			$err = new WP_Error('sgbackup_delete_failed', "{$fn}({$path}) failed" . ('' !== $message ? ": {$message}" : ''));
			error_log('SiteGuard Backup: ' . $err->get_error_message());
			return $err;
		}
		return true;
	}

	/**
	 * A refused delete is always a bug or misconfiguration; make it loud.
	 */
	private static function report_refusal($error) {
		if ('sgbackup_delete_missing' === $error->get_error_code()) return;
		error_log('SiteGuard Backup: SECURITY: ' . $error->get_error_message());
	}

	/**
	 * Largest backup file PHP will accept in one request.
	 *
	 * wp_max_upload_size() is the MEDIA limit: on multisite it is capped by the
	 * network's "Max upload file size" (1-1.5 MB by default), which made backup
	 * uploads fail outright and forced sub-1 MB chunks. Backup archives are not
	 * media; only PHP's own limits apply.
	 */
	public static function max_backup_upload_size() {
		$limits = array();
		foreach (array('upload_max_filesize', 'post_max_size') as $ini) {
			$v = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get($ini)) : (int) ini_get($ini);
			if ($v > 0) $limits[] = $v;
		}
		return $limits ? min($limits) : wp_max_upload_size();
	}

	/**
	 * Get a safe temporary filename in the backup dir.
	 */
	public static function temp_file($backup_dir, $prefix = 'sgbackup_', $ext = '.tmp') {
		return $backup_dir . $prefix . wp_generate_password(12, false) . $ext;
	}

	/**
	 * Determine available zip method.
	 *
	 * @return string 'binzip', 'ziparchive', or 'pclzip'
	 */
	public static function get_zip_method() {
		// Check for system zip binary
		if (self::has_binary_zip()) {
			return 'binzip';
		}

		// Check for ZipArchive
		if (class_exists('ZipArchive')) {
			return 'ziparchive';
		}

		// Fall back to PclZip (bundled with WordPress)
		return 'pclzip';
	}

	/**
	 * Check if the zip binary is available.
	 */
	public static function has_binary_zip() {
		if (!function_exists('exec')) return false;
		$output = array();
		$return = -1;
		@exec('which zip 2>/dev/null', $output, $return);
		return (0 === $return && !empty($output));
	}

	/**
	 * Format bytes into human-readable string.
	 */
	public static function format_bytes($bytes, $precision = 2) {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= pow(1024, $pow);
		return round($bytes, $precision) . ' ' . $units[$pow];
	}

	/**
	 * Check disk space availability.
	 *
	 * @return array ['free' => bytes, 'total' => bytes] or false.
	 */
	public static function check_disk_space($path) {
		if (!function_exists('disk_free_space')) return false;
		$free = @disk_free_space($path);
		$total = @disk_total_space($path);
		if (false === $free) return false;
		return array('free' => $free, 'total' => (false === $total) ? 0 : $total);
	}
}
