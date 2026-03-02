<?php
/**
 * SiteGuard Backup - Foreign Backup Importer
 *
 * Detects and imports backups from 7+ WordPress backup plugin formats:
 * UpdraftPlus, BackWPUp, BackupWordPress, Simple Backups, InfiniteWP,
 * WP-DB-Backup, and generic SQL/ZIP.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Foreign_Importer {

	/** @var array Known format definitions */
	private static $formats = array(
		'updraftplus' => array(
			'label'   => 'UpdraftPlus',
			'pattern' => '/^backup_\d{4}-\d{2}-\d{2}-\d{4}_[^_]+_[a-f0-9]+-/',
		),
		'backwpup' => array(
			'label'   => 'BackWPup',
			'pattern' => '/^backwpup_[a-f0-9]+_\d{4}-\d{2}-\d{2}_\d{4}\.(tar|zip|gz|tar\.gz)$/i',
		),
		'backupwordpress' => array(
			'label'   => 'BackupWordPress',
			'pattern' => '/^[a-f0-9]+-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/i',
		),
		'simple_backups' => array(
			'label'   => 'Simple Backups',
			'pattern' => '/^simple_backups?_\d{4}-\d{2}-\d{2}_\d{4}\.zip$/i',
		),
		'infinitewp' => array(
			'label'   => 'InfiniteWP',
			'pattern' => '/^[a-zA-Z0-9._-]+_iwp_.*\.zip$/i',
		),
		'wpdbbackup' => array(
			'label'   => 'WP-DB-Backup',
			'pattern' => '/\.sql(\.gz)?$/i',
		),
	);

	/**
	 * Detect the format of a backup file.
	 *
	 * @param string $filepath Full path to the backup file.
	 * @return array|false Detection result with 'format', 'label', 'entities', 'timestamp', or false if unrecognised.
	 */
	public static function detect($filepath) {
		if (!file_exists($filepath) || !is_readable($filepath)) {
			return false;
		}

		$filename = basename($filepath);
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		// Try filename-based detection first
		foreach (self::$formats as $format_id => $format) {
			if (preg_match($format['pattern'], $filename)) {
				$result = array(
					'format'    => $format_id,
					'label'     => $format['label'],
					'entities'  => array(),
					'timestamp' => filemtime($filepath),
				);

				// Format-specific detection
				switch ($format_id) {
					case 'updraftplus':
						$result = self::detect_updraftplus($filepath, $filename, $result);
						break;
					case 'backwpup':
						$result = self::detect_backwpup($filepath, $filename, $result);
						break;
					case 'backupwordpress':
						$result = self::detect_backupwordpress($filepath, $filename, $result);
						break;
					case 'simple_backups':
						$result['entities'] = self::detect_zip_entities($filepath);
						break;
					case 'infinitewp':
						$result = self::detect_infinitewp($filepath, $filename, $result);
						break;
					case 'wpdbbackup':
						$result['entities'] = array('db');
						$result = self::detect_wpdbbackup($filepath, $result);
						break;
				}

				return $result;
			}
		}

		// Fallback: generic detection based on content inspection
		return self::detect_generic($filepath, $filename, $ext);
	}

	/**
	 * Import a single foreign backup file into SiteGuard history.
	 *
	 * @param string $filepath Full path to the backup file.
	 * @return array|WP_Error Result with 'success', 'timestamp', 'filename', 'format'.
	 */
	public static function import($filepath) {
		$detection = self::detect($filepath);
		if (false === $detection) {
			return new WP_Error('unrecognised', 'Could not detect backup format for: ' . basename($filepath));
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();

		// Verify file is in the backup directory
		$real_path = realpath($filepath);
		$real_dir = realpath($backup_dir);
		if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0) {
			return new WP_Error('invalid_path', 'File must be in the backup directory');
		}

		$timestamp = time();
		$nonce = substr(wp_generate_password(12, false), 0, 12);
		$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

		// Determine entity from detection
		$entities = $detection['entities'];
		$entity = 'others';
		if (in_array('db', $entities, true) && count($entities) === 1) {
			$entity = 'db';
		} elseif (in_array('db', $entities, true)) {
			$entity = 'others'; // Mixed: contains both files and DB
		}

		// For pure SQL files, use the db entity
		if (in_array($ext, array('sql', 'gz'), true)) {
			$entity = 'db';
		}

		$new_name = 'backup_' . gmdate('Y-m-d-Hi') . '_imported_' . $nonce . '-' . $entity . '.' . $ext;
		$new_path = $backup_dir . $new_name;

		if (!rename($filepath, $new_path)) {
			return new WP_Error('rename_failed', 'Failed to rename file');
		}

		$backup_data = array(
			'nonce'           => $nonce,
			'service'         => array('none'),
			'imported'        => true,
			'imported_format' => $detection['format'],
			$entity           => array($new_name),
			'size'            => filesize($new_path),
		);

		SiteGuard_Backup_History::add_backup($timestamp, $backup_data);

		return array(
			'success'   => true,
			'timestamp' => $timestamp,
			'filename'  => $new_name,
			'format'    => $detection['format'],
			'label'     => $detection['label'],
			'entities'  => $detection['entities'],
		);
	}

	/**
	 * Import a set of related backup files (e.g. UpdraftPlus multi-entity splits).
	 *
	 * @param array $filepaths Array of full paths to backup files.
	 * @return array|WP_Error Result with 'success', 'timestamp', 'filenames', 'format'.
	 */
	public static function import_set($filepaths) {
		if (empty($filepaths) || !is_array($filepaths)) {
			return new WP_Error('no_files', 'No files provided');
		}

		// If single file, delegate to import()
		if (count($filepaths) === 1) {
			return self::import(reset($filepaths));
		}

		$opts = SiteGuard_Backup_Options::instance();
		$backup_dir = $opts->get_backup_dir();
		$real_dir = realpath($backup_dir);

		$timestamp = time();
		$nonce = substr(wp_generate_password(12, false), 0, 12);
		$backup_data = array(
			'nonce'    => $nonce,
			'service'  => array('none'),
			'imported' => true,
		);

		$total_size = 0;
		$filenames = array();
		$detected_format = null;

		foreach ($filepaths as $filepath) {
			if (!file_exists($filepath)) continue;

			$real_path = realpath($filepath);
			if (!$real_path || !$real_dir || strpos($real_path, $real_dir) !== 0) {
				continue;
			}

			$detection = self::detect($filepath);
			if (false === $detection) continue;

			if (null === $detected_format) {
				$detected_format = $detection;
				$backup_data['imported_format'] = $detection['format'];
			}

			$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

			// Determine entity
			$entity = self::guess_entity_from_file($filepath, $detection);

			$new_name = 'backup_' . gmdate('Y-m-d-Hi') . '_imported_' . $nonce . '-' . $entity . '.' . $ext;
			$new_path = $backup_dir . $new_name;

			if (rename($filepath, $new_path)) {
				if (!isset($backup_data[$entity])) {
					$backup_data[$entity] = array();
				}
				$backup_data[$entity][] = $new_name;
				$filenames[] = $new_name;
				$total_size += filesize($new_path);
			}
		}

		if (empty($filenames)) {
			return new WP_Error('import_failed', 'No files were successfully imported');
		}

		$backup_data['size'] = $total_size;
		SiteGuard_Backup_History::add_backup($timestamp, $backup_data);

		return array(
			'success'   => true,
			'timestamp' => $timestamp,
			'filenames' => $filenames,
			'format'    => $detected_format ? $detected_format['format'] : 'generic',
			'label'     => $detected_format ? $detected_format['label'] : 'Generic',
		);
	}

	// ===== Private detection helpers =====

	/**
	 * Detect UpdraftPlus backup format and extract entity info.
	 */
	private static function detect_updraftplus($filepath, $filename, $result) {
		// UpdraftPlus naming: backup_YYYY-MM-DD-HHMM_sitename_NONCE-entity.zip
		if (preg_match('/^backup_(\d{4}-\d{2}-\d{2})-(\d{4})_/', $filename, $m)) {
			$result['timestamp'] = strtotime($m[1] . ' ' . substr($m[2], 0, 2) . ':' . substr($m[2], 2, 2));
		}

		// Detect entity from filename
		foreach (array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db') as $entity) {
			if (preg_match('/-' . $entity . '[\.-]/', $filename) || preg_match('/-' . $entity . '$/', pathinfo($filename, PATHINFO_FILENAME))) {
				$result['entities'][] = $entity;
			}
		}

		// Also check for manifest.json inside zip
		if (pathinfo($filename, PATHINFO_EXTENSION) === 'zip' && class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if ($zip->open($filepath) === true) {
				if ($zip->locateName('manifest.json') !== false) {
					$result['has_manifest'] = true;
				}
				$zip->close();
			}
		}

		if (empty($result['entities'])) {
			$result['entities'] = self::detect_zip_entities($filepath);
		}

		return $result;
	}

	/**
	 * Detect BackWPup backup format.
	 */
	private static function detect_backwpup($filepath, $filename, $result) {
		if (preg_match('/backwpup_[a-f0-9]+_(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})/', $filename, $m)) {
			$result['timestamp'] = strtotime($m[1] . ' ' . $m[2] . ':' . $m[3]);
		}

		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		if ($ext === 'zip' && class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if ($zip->open($filepath) === true) {
				// BackWPup includes backwpup_readme.txt
				if ($zip->locateName('backwpup_readme.txt') !== false) {
					$result['confirmed'] = true;
				}
				// Check for SQL files
				$has_sql = false;
				$has_files = false;
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = $zip->getNameIndex($i);
					if (preg_match('/\.sql(\.gz)?$/', $name)) {
						$has_sql = true;
					} elseif (!preg_match('/^backwpup_/', basename($name))) {
						$has_files = true;
					}
				}
				if ($has_sql) $result['entities'][] = 'db';
				if ($has_files) $result['entities'][] = 'others';
				$zip->close();
			}
		} else {
			$result['entities'] = array('others');
		}

		return $result;
	}

	/**
	 * Detect BackupWordPress format.
	 */
	private static function detect_backupwordpress($filepath, $filename, $result) {
		if (preg_match('/(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})(\d{2})/', $filename, $m)) {
			$result['timestamp'] = strtotime($m[1] . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4]);
		}

		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if ($zip->open($filepath) === true) {
				$has_sql = false;
				$has_files = false;
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = $zip->getNameIndex($i);
					if (preg_match('/database-backup\.sql/', $name) || preg_match('/\.sql(\.gz)?$/', $name)) {
						$has_sql = true;
					} else {
						$has_files = true;
					}
				}
				if ($has_sql) $result['entities'][] = 'db';
				if ($has_files) $result['entities'][] = 'others';
				$zip->close();
			}
		}

		if (empty($result['entities'])) {
			$result['entities'] = array('others');
		}

		return $result;
	}

	/**
	 * Detect InfiniteWP format.
	 */
	private static function detect_infinitewp($filepath, $filename, $result) {
		if (class_exists('ZipArchive')) {
			$zip = new ZipArchive();
			if ($zip->open($filepath) === true) {
				// IWP includes package.json with IWP-specific keys
				$pkg = $zip->getFromName('package.json');
				if (false !== $pkg) {
					$data = json_decode($pkg, true);
					if (is_array($data) && (isset($data['iwp_version']) || isset($data['type']))) {
						$result['confirmed'] = true;
					}
				}

				$has_sql = false;
				$has_files = false;
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = $zip->getNameIndex($i);
					if (preg_match('/\.sql(\.gz)?$/', $name)) {
						$has_sql = true;
					} else {
						$has_files = true;
					}
				}
				if ($has_sql) $result['entities'][] = 'db';
				if ($has_files) $result['entities'][] = 'others';
				$zip->close();
			}
		}

		if (empty($result['entities'])) {
			$result['entities'] = array('others');
		}

		return $result;
	}

	/**
	 * Detect WP-DB-Backup format (plain SQL files with WordPress header comments).
	 */
	private static function detect_wpdbbackup($filepath, $result) {
		$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
		$result['entities'] = array('db');

		// Read first 2KB to check for WordPress-style header comments
		if ($ext === 'sql') {
			$fh = fopen($filepath, 'rb');
			if ($fh) {
				$header = fread($fh, 2048);
				fclose($fh);
				if ($header && (stripos($header, 'WordPress') !== false || stripos($header, 'wp_') !== false)) {
					$result['confirmed'] = true;
				}
			}
		} elseif ($ext === 'gz') {
			$fh = gzopen($filepath, 'rb');
			if ($fh) {
				$header = gzread($fh, 2048);
				gzclose($fh);
				if ($header && (stripos($header, 'WordPress') !== false || stripos($header, 'wp_') !== false)) {
					$result['confirmed'] = true;
				}
			}
		}

		return $result;
	}

	/**
	 * Generic fallback detection based on file content inspection.
	 */
	private static function detect_generic($filepath, $filename, $ext) {
		$result = array(
			'format'    => 'generic',
			'label'     => 'Generic',
			'entities'  => array(),
			'timestamp' => filemtime($filepath),
		);

		if (in_array($ext, array('sql', 'gz'), true)) {
			$result['entities'] = array('db');
			return $result;
		}

		if ($ext === 'zip') {
			$result['entities'] = self::detect_zip_entities($filepath);
			if (!empty($result['entities'])) {
				return $result;
			}
			// If we couldn't determine, assume files
			$result['entities'] = array('others');
			return $result;
		}

		// Tar/tar.gz
		if ($ext === 'tar' || (strlen($filename) > 7 && substr($filename, -7) === '.tar.gz')) {
			$result['entities'] = array('others');
			return $result;
		}

		return false;
	}

	/**
	 * Inspect a ZIP file to determine which entities it contains.
	 *
	 * @param string $filepath Full path to the ZIP file.
	 * @return array Entity list (e.g. ['db'], ['others'], ['db', 'others']).
	 */
	private static function detect_zip_entities($filepath) {
		$entities = array();

		if (!class_exists('ZipArchive')) {
			return array('others');
		}

		$zip = new ZipArchive();
		if ($zip->open($filepath) !== true) {
			return array('others');
		}

		$has_sql = false;
		$has_files = false;

		for ($i = 0; $i < min($zip->numFiles, 500); $i++) {
			$name = $zip->getNameIndex($i);
			if (preg_match('/\.sql(\.gz)?$/', $name)) {
				$has_sql = true;
			} else {
				$has_files = true;
			}
		}

		$zip->close();

		if ($has_sql) $entities[] = 'db';
		if ($has_files) $entities[] = 'others';

		return $entities;
	}

	/**
	 * Guess the backup entity type from a file and its detection result.
	 *
	 * @param string $filepath  Full path to the file.
	 * @param array  $detection Detection result from detect().
	 * @return string Entity name.
	 */
	private static function guess_entity_from_file($filepath, $detection) {
		$filename = basename($filepath);
		$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		// SQL files are always DB
		if (in_array($ext, array('sql', 'gz'), true)) {
			return 'db';
		}

		// Check UpdraftPlus-style entity suffix
		foreach (array('plugins', 'themes', 'uploads', 'others', 'wpcore', 'db') as $entity) {
			if (preg_match('/-' . $entity . '[\.-]/', $filename)) {
				return $entity;
			}
		}

		// Use detection entities
		if (!empty($detection['entities'])) {
			if (in_array('db', $detection['entities'], true) && count($detection['entities']) === 1) {
				return 'db';
			}
		}

		return 'others';
	}
}
