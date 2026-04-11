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
		return array('plugins', 'themes', 'uploads', 'upgrade', 'cache', 'sgbackup', 'updraft', 'wflogs');
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
				'excludes' => array('wp-content'),
			),
		);
	}

	/**
	 * Get the total size of a directory.
	 */
	public static function directory_size($path) {
		$size = 0;
		if (!is_dir($path)) return $size;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($iterator as $file) {
			if ($file->isFile() && !is_dir($file->getPathname())) {
				$size += $file->getSize();
			}
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
		$files = array();
		if (!is_dir($path)) return $files;

		$path = trailingslashit($path);

		$abspath_real = defined('ABSPATH') ? realpath(ABSPATH) : false;

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | 0),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth(32); // Prevent infinite loops from circular symlinks

			foreach ($iterator as $item) {
				// Skip directories - use is_dir() as primary check because
				// SplFileInfo::isFile() incorrectly returns true for directories
				// on CIFS/SMB mounts (Azure App Service, NAS storage, etc.)
				if ($item->isDir() || is_dir($item->getPathname())) continue;
				if (!$item->isFile() && !$item->isLink()) continue;

				$full_path = $item->getPathname();
				$original_path = $full_path;

				// Resolve symlinks and verify they stay within ABSPATH
				if ($item->isLink()) {
					$real = realpath($full_path);
					if (false === $real) continue; // Broken symlink
					if ($abspath_real && strpos($real, $abspath_real . DIRECTORY_SEPARATOR) !== 0 && $real !== $abspath_real) {
						continue; // Symlink target outside ABSPATH boundary
					}
					if (!is_file($real)) continue; // Symlink to directory - skip, we only want files
					$full_path = $real;
				}

				if (!$item->isFile() && !is_file($full_path)) continue;
				// Use original (pre-symlink-resolution) path for relative path computation
				$relative = str_replace($path, '', $original_path);

				// Check exclusions (supports exact prefix match and glob-style wildcards via fnmatch)
				$skip = false;
				foreach ($excludes as $exclude) {
					if ('' === $exclude) continue;
					// Support glob-style wildcards (e.g., *.log, cache/*)
					if (strpbrk($exclude, '*?[') !== false) {
						if (fnmatch($exclude, $relative, FNM_PATHNAME) || fnmatch($exclude, basename($relative))) {
							$skip = true;
							break;
						}
					} elseif (0 === strpos($relative, $exclude) || false !== strpos($relative, DIRECTORY_SEPARATOR . $exclude)) {
						$skip = true;
						break;
					}
				}
				if ($skip) continue;

				$mtime = $item->getMTime();

				// Filter by mtime if incremental
				if (null !== $altered_since && $mtime <= $altered_since) continue;

				$file_path = $relative;
				if (!empty($strip_prefix)) {
					$file_path = str_replace($strip_prefix, '', $relative);
				}

				$files[] = array(
					'path'  => $file_path,
					'size'  => $item->getSize(),
					'mtime' => $mtime,
					'full_path' => $full_path,
				);
			}
		} catch (Exception $e) {
			// Permission denied or similar - skip
		}

		return $files;
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
					self::rmdir_recursive($file->getPathname());
				}
				continue;
			}

			if ($is_directory) continue;

			// Only clean temp files
			if (preg_match('/\.(tmp|partial|gz\.tmp)$/', $name)) {
				if (($now - $file->getMTime()) > $max_age) {
					@unlink($file->getPathname());
				}
			}
		}
	}

	/**
	 * Recursively remove a directory.
	 */
	public static function rmdir_recursive($dir) {
		if (!is_dir($dir)) return;

		// Validate real path exists and is within allowed boundaries
		$real_dir = realpath($dir);
		if (false === $real_dir) return;

		if (defined('ABSPATH')) {
			$abspath_real = realpath(ABSPATH);
			$wpcontent_real = defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : false;
			$within_abspath = ($abspath_real && strpos($real_dir, $abspath_real . DIRECTORY_SEPARATOR) === 0);
			$within_wpcontent = ($wpcontent_real && strpos($real_dir, $wpcontent_real . DIRECTORY_SEPARATOR) === 0);
			if (!$within_abspath && !$within_wpcontent) {
				return; // Refuse to delete outside ABSPATH and WP_CONTENT_DIR
			}
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			if ($item->isDir() || is_dir($item->getPathname())) {
				@rmdir($item->getPathname());
			} else {
				@unlink($item->getPathname());
			}
		}
		@rmdir($dir);
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
