<?php
/**
 * SiteGuard Backup - Database Utility
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Database_Utility {

	/**
	 * Get all database tables sorted by foreign key dependencies.
	 *
	 * @param string $prefix Optional table prefix filter.
	 * @return array Table names.
	 */
	public static function get_tables($prefix = '', $include_non_wp = false) {
		global $wpdb;

		if ($include_non_wp) {
			return self::get_all_tables();
		}

		if (empty($prefix)) {
			$prefix = $wpdb->prefix;
		}

		$tables = $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($prefix) . '%'));
		if (empty($tables)) return array();

		return self::sort_tables($tables);
	}

	/**
	 * Get ALL tables in the database, regardless of prefix.
	 *
	 * @return array Table names.
	 */
	public static function get_all_tables() {
		global $wpdb;

		$tables = $wpdb->get_col("SHOW TABLES");
		if (empty($tables)) return array();

		return self::sort_tables($tables);
	}

	/**
	 * Sort tables to handle foreign key dependencies.
	 * Tables with no FK dependencies come first.
	 */
	public static function sort_tables($tables) {
		global $wpdb;

		// For most WordPress installs, simple alphabetical is fine
		// since WP doesn't use foreign keys. But we handle the case.
		$deps = array();
		$no_deps = array();

		foreach ($tables as $table) {
			$table = self::sanitize_table_name($table);
			if (empty($table)) continue;
			$create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
			if (!$create || !isset($create[1])) {
				$no_deps[] = $table;
				continue;
			}

			$create_sql = $create[1];
			$references = array();
			if (preg_match_all('/REFERENCES\s+`([^`]+)`/i', $create_sql, $matches)) {
				$references = $matches[1];
			}

			if (empty($references)) {
				$no_deps[] = $table;
			} else {
				$deps[$table] = $references;
			}
		}

		// Simple topological sort
		$sorted = $no_deps;
		$remaining = $deps;
		$max_iterations = count($remaining) * 2;
		$i = 0;

		while (!empty($remaining) && $i < $max_iterations) {
			foreach ($remaining as $table => $references) {
				$all_resolved = true;
				foreach ($references as $ref) {
					if (isset($remaining[$ref])) {
						$all_resolved = false;
						break;
					}
				}
				if ($all_resolved) {
					$sorted[] = $table;
					unset($remaining[$table]);
				}
			}
			$i++;
		}

		// Add any remaining (circular deps)
		foreach ($remaining as $table => $refs) {
			$sorted[] = $table;
		}

		return $sorted;
	}

	/**
	 * Get table row count.
	 */
	public static function get_table_row_count($table) {
		global $wpdb;
		$table = self::sanitize_table_name($table);
		return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
	}

	/**
	 * Sanitize a table name to prevent SQL injection.
	 * Only allows alphanumeric, underscore, and dollar sign (MySQL standard).
	 */
	public static function sanitize_table_name($table) {
		$cleaned = preg_replace('/[^a-zA-Z0-9_$]/', '', $table);
		return (strlen($cleaned) > 0) ? $cleaned : false;
	}

	/**
	 * Get table size in bytes.
	 */
	public static function get_table_size($table) {
		global $wpdb;
		$db_name = DB_NAME;
		$row = $wpdb->get_row($wpdb->prepare(
			"SELECT (data_length + index_length) AS size
			 FROM information_schema.TABLES
			 WHERE table_schema = %s AND table_name = %s",
			$db_name, $table
		));
		return $row ? (int) $row->size : 0;
	}

	/**
	 * Check if mysqldump binary is available.
	 */
	public static function has_mysqldump() {
		if (!function_exists('exec')) return false;
		$output = array();
		$return = -1;
		@exec('which mysqldump 2>/dev/null', $output, $return);
		return (0 === $return && !empty($output));
	}

	/**
	 * Dump a single table using mysqldump.
	 *
	 * @param string $table    Table name.
	 * @param string $outfile  Output file path.
	 * @return bool True on success.
	 */
	public static function mysqldump_table($table, $outfile) {
		if (!function_exists('exec')) return false;

		$host = DB_HOST;
		$user = DB_USER;
		$pass = DB_PASSWORD;
		$db = DB_NAME;

		// Parse host:port
		$port = '';
		$socket = '';
		if (strpos($host, ':') !== false) {
			$parts = explode(':', $host, 2);
			$host = $parts[0];
			if (is_numeric($parts[1])) {
				$port = '--port=' . intval($parts[1]);
			} else {
				$socket = '--socket=' . escapeshellarg($parts[1]);
			}
		}

		// Write credentials to a temp file to avoid exposing password in process list
		$opts_file = tempnam(sys_get_temp_dir(), 'sgbk_');
		if (!$opts_file) return false;

		// Restrict permissions immediately before writing sensitive content
		chmod($opts_file, 0600);

		try {
			$opts_content = "[client]\n";
			$opts_content .= "user=" . addcslashes($user, "\\\"'\n") . "\n";
			if (!empty($pass)) {
				$opts_content .= "password=" . addcslashes($pass, "\\\"'\n") . "\n";
			}
			$opts_content .= "host=" . addcslashes($host, "\\\"'\n") . "\n";
			file_put_contents($opts_file, $opts_content);

			$cmd = sprintf(
				'mysqldump --defaults-extra-file=%s --no-tablespaces --single-transaction --quick %s %s %s %s > %s 2>&1',
				escapeshellarg($opts_file),
				$port,
				$socket,
				escapeshellarg($db),
				escapeshellarg($table),
				escapeshellarg($outfile)
			);

			$output = array();
			$return = -1;
			@exec($cmd, $output, $return);

			return (0 === $return && file_exists($outfile) && filesize($outfile) > 0);
		} finally {
			@unlink($opts_file);
		}
	}

	/**
	 * Dump a table using PHP (fallback when mysqldump is unavailable).
	 *
	 * @param string $table          Table name.
	 * @param string $outfile        Output file path (.sql or .sql.gz).
	 * @param int    $batch_size     Rows per batch.
	 * @param bool   $structure_only If true, dump only CREATE TABLE (no INSERT statements).
	 * @return bool True on success.
	 */
	public static function php_dump_table($table, $outfile, $batch_size = 1000, $structure_only = false) {
		global $wpdb;

		$table = self::sanitize_table_name($table);

		$use_gzip = (substr($outfile, -3) === '.gz');
		$fh = $use_gzip ? @gzopen($outfile, 'wb9') : @fopen($outfile, 'w');
		if (!$fh) return false;

		$write = function($data) use ($fh, $use_gzip) {
			return $use_gzip ? @gzwrite($fh, $data) : @fwrite($fh, $data);
		};

		// Table structure
		$create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
		if (!$create) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			return false;
		}

		$write("DROP TABLE IF EXISTS `{$table}`;\n");
		$write($create[1] . ";\n\n");

		// Gap 21: Structure-only mode - skip data
		if ($structure_only) {
			$write("-- Structure only: data excluded by data_optional_tables setting\n\n");
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			return true;
		}

		// Table data
		$total_rows = self::get_table_row_count($table);
		if ($total_rows === 0) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			return true;
		}

		// Get columns for INSERT statement with type info
		$columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
		$col_names = array_map(function($c) { return '`' . $c['Field'] . '`'; }, $columns);
		$col_list = implode(', ', $col_names);

		// Detect binary/blob columns
		$binary_cols = array();
		foreach ($columns as $i => $col) {
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}

		$offset = 0;
		while ($offset < $total_rows) {
			$rows = $wpdb->get_results(
				$wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset),
				ARRAY_A
			);

			if (empty($rows)) break;

			$write("INSERT INTO `{$table}` ({$col_list}) VALUES\n");

			$row_strings = array();
			foreach ($rows as $row) {
				$values = array();
				foreach ($row as $col_name => $value) {
					if (is_null($value)) {
						$values[] = 'NULL';
					} elseif (isset($binary_cols[$col_name])) {
						$values[] = strlen($value) > 0 ? '0x' . bin2hex($value) : "''";
					} else {
						$values[] = "'" . $wpdb->_real_escape($value) . "'";
					}
				}
				$row_strings[] = '(' . implode(', ', $values) . ')';
			}
			$write(implode(",\n", $row_strings) . ";\n\n");

			$offset += $batch_size;

			// Adaptive batch sizing based on memory
			$mem_usage = memory_get_usage(true);
			$mem_limit = self::parse_size(ini_get('memory_limit'));
			if ($mem_limit > 0 && $mem_usage > ($mem_limit * 0.7)) {
				$batch_size = max(100, intval($batch_size / 2));
			}
		}

		$use_gzip ? @gzclose($fh) : @fclose($fh);
		return true;
	}

	/**
	 * Dump a table using a specific wpdb connection (for external databases).
	 *
	 * @param string $table          Table name.
	 * @param string $outfile        Output file path (.sql or .sql.gz).
	 * @param wpdb   $wpdb_instance  The wpdb instance to use.
	 * @param int    $batch_size     Rows per batch.
	 * @param SiteGuard_Backup_DB_Anonymiser|null $anonymiser Optional anonymiser instance.
	 * @return bool True on success.
	 */
	public static function php_dump_table_with_connection($table, $outfile, $wpdb_instance, $batch_size = 1000, $anonymiser = null) {
		$table = self::sanitize_table_name($table);

		$use_gzip = (substr($outfile, -3) === '.gz');
		$fh = $use_gzip ? @gzopen($outfile, 'wb9') : @fopen($outfile, 'w');
		if (!$fh) return false;

		$write = function($data) use ($fh, $use_gzip) {
			return $use_gzip ? @gzwrite($fh, $data) : @fwrite($fh, $data);
		};

		// Table structure
		$create = $wpdb_instance->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
		if (!$create) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			return false;
		}

		$write("DROP TABLE IF EXISTS `{$table}`;\n");
		$write($create[1] . ";\n\n");

		// Table data
		$total_rows = (int) $wpdb_instance->get_var("SELECT COUNT(*) FROM `{$table}`");
		if ($total_rows === 0) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			return true;
		}

		// Get columns for INSERT statement with type info
		$columns = $wpdb_instance->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
		$col_names = array_map(function($c) { return '`' . $c['Field'] . '`'; }, $columns);
		$col_list = implode(', ', $col_names);

		// Detect binary/blob columns
		$binary_cols = array();
		foreach ($columns as $i => $col) {
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}

		$offset = 0;
		while ($offset < $total_rows) {
			$rows = $wpdb_instance->get_results(
				$wpdb_instance->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset),
				ARRAY_A
			);

			if (empty($rows)) break;

			$write("INSERT INTO `{$table}` ({$col_list}) VALUES\n");

			$row_strings = array();
			foreach ($rows as $row) {
				// Apply anonymisation if available
				if ($anonymiser !== null && $anonymiser->should_anonymise_row($table, $row)) {
					foreach ($row as $col_name => &$value) {
						if ($value !== null) {
							$value = $anonymiser->anonymise_value($col_name, $value);
						}
					}
					unset($value);
				}

				$values = array();
				foreach ($row as $col_name => $value) {
					if (is_null($value)) {
						$values[] = 'NULL';
					} elseif (isset($binary_cols[$col_name])) {
						$values[] = strlen($value) > 0 ? '0x' . bin2hex($value) : "''";
					} else {
						$values[] = "'" . $wpdb_instance->_real_escape($value) . "'";
					}
				}
				$row_strings[] = '(' . implode(', ', $values) . ')';
			}
			$write(implode(",\n", $row_strings) . ";\n\n");

			$offset += $batch_size;

			// Adaptive batch sizing based on memory
			$mem_usage = memory_get_usage(true);
			$mem_limit = self::parse_size(ini_get('memory_limit'));
			if ($mem_limit > 0 && $mem_usage > ($mem_limit * 0.7)) {
				$batch_size = max(100, intval($batch_size / 2));
			}
		}

		$use_gzip ? @gzclose($fh) : @fclose($fh);
		return true;
	}

	/**
	 * Dump database views.
	 *
	 * @param string $prefix  Table prefix filter.
	 * @param string $outfile Output file path.
	 * @return bool
	 */
	public static function dump_views($prefix, $outfile) {
		global $wpdb;

		$views = $wpdb->get_results($wpdb->prepare(
			"SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
			DB_NAME, $wpdb->esc_like($prefix) . '%'
		), ARRAY_A);

		if (empty($views)) return true;

		$sql = "\n-- Views\n";
		foreach ($views as $view) {
			$name = self::sanitize_table_name($view['TABLE_NAME']);
			$create = $wpdb->get_row("SHOW CREATE VIEW `{$name}`", ARRAY_N);
			if ($create && isset($create[1])) {
				$sql .= "DROP VIEW IF EXISTS `{$name}`;\n";
				$sql .= $create[1] . ";\n\n";
			}
		}

		return (bool) file_put_contents($outfile, $sql, FILE_APPEND);
	}

	/**
	 * Dump stored routines (procedures and functions).
	 *
	 * @param string $outfile Output file path.
	 * @return bool
	 */
	public static function dump_routines($outfile) {
		global $wpdb;

		$routines = $wpdb->get_results($wpdb->prepare(
			"SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = %s",
			DB_NAME
		), ARRAY_A);

		if (empty($routines)) return true;

		$sql = "\n-- Routines\nDELIMITER ;;\n";
		foreach ($routines as $routine) {
			$name = self::sanitize_table_name($routine['ROUTINE_NAME']);
			$type = ($routine['ROUTINE_TYPE'] === 'PROCEDURE') ? 'PROCEDURE' : 'FUNCTION';
			$create = $wpdb->get_row("SHOW CREATE {$type} `{$name}`", ARRAY_N);
			if ($create && isset($create[2])) {
				$sql .= "DROP {$type} IF EXISTS `{$name}`;;\n";
				$sql .= $create[2] . ";;\n\n";
			}
		}
		$sql .= "DELIMITER ;\n";

		return (bool) file_put_contents($outfile, $sql, FILE_APPEND);
	}

	/**
	 * Parse PHP size string to bytes.
	 */
	public static function parse_size($size_str) {
		$size_str = trim($size_str);
		if (empty($size_str) || $size_str === '-1') return 0;

		$unit = strtolower(substr($size_str, -1));
		$value = intval($size_str);

		switch ($unit) {
			case 'g': $value *= 1024 * 1024 * 1024; break;
			case 'm': $value *= 1024 * 1024; break;
			case 'k': $value *= 1024; break;
		}

		return $value;
	}

	/**
	 * Execute SQL file with optional table filtering.
	 */
	public static function execute_sql_file_filtered($sql_file, $selected_tables = null, $progress_callback = null) {
		global $wpdb;

		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0, 'tables_skipped' => 0);

		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');
		if (!$fh) {
			$result['success'] = false;
			$result['errors'][] = "Failed to open SQL file: {$sql_file}";
			return $result;
		}

		$buffer = '';
		$line_count = 0;
		$delimiter = ';';
		$current_table = '';
		$skipping_table = false;
		$in_string = false;
		$string_char = '';

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$line_count++;
			$trimmed = trim($line);

			if (!$in_string) {
				if (empty($trimmed)) {
					continue;
				}
				if (empty($buffer) && (0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#') || 0 === strpos($trimmed, '/*'))) {
					continue;
				}

				// Track current table for filtering
				if (preg_match('/^(?:DROP TABLE|CREATE TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`([^`]+)`/i', $trimmed, $m)) {
					$current_table = $m[1];
					if ($selected_tables !== null && !in_array($current_table, $selected_tables)) {
						$skipping_table = true;
						$result['tables_skipped']++;
					} else {
						$skipping_table = false;
					}
				}

				if (preg_match('/^INSERT\s+INTO\s+`([^`]+)`/i', $trimmed, $m)) {
					$current_table = $m[1];
					if ($selected_tables !== null && !in_array($current_table, $selected_tables)) {
						$skipping_table = true;
					}
				}
			}

			if ($skipping_table && !$in_string) {
				// Track string state even when skipping, to handle multi-line values
				$len = strlen($trimmed);
				for ($ci = 0; $ci < $len; $ci++) {
					$ch = $trimmed[$ci];
					if ($in_string) {
						if ($ch === '\\') { $ci++; }
						elseif ($ch === $string_char) {
							if ($ci + 1 < $len && $trimmed[$ci + 1] === $string_char) { $ci++; }
							else { $in_string = false; }
						}
					} else {
						if ($ch === "'" || $ch === '"') { $in_string = true; $string_char = $ch; }
					}
				}
				continue;
			}

			$buffer .= $line;

			// Track quoted string state
			$len = strlen($trimmed);
			for ($ci = 0; $ci < $len; $ci++) {
				$ch = $trimmed[$ci];
				if ($in_string) {
					if ($ch === '\\') { $ci++; }
					elseif ($ch === $string_char) {
						if ($ci + 1 < $len && $trimmed[$ci + 1] === $string_char) { $ci++; }
						else { $in_string = false; }
					}
				} else {
					if ($ch === "'" || $ch === '"') { $in_string = true; $string_char = $ch; }
				}
			}

			if (!$in_string && substr($trimmed, -1) === $delimiter) {
				$query = trim($buffer);
				$buffer = '';

				if (!empty($query)) {
					$query = rtrim($query, ';');

					if (self::is_dangerous_sql($query)) {
						$result['errors'][] = "Blocked dangerous SQL on line {$line_count}";
					} else {
						$res = $wpdb->query($query);

						if (false === $res) {
							$result['errors'][] = "SQL error on line {$line_count}: " . $wpdb->last_error;
						}

						if (preg_match('/^CREATE\s+TABLE/i', $query)) {
							$result['tables_restored']++;
						}
					}
				}

				if ($progress_callback && ($line_count % 500 === 0)) {
					call_user_func($progress_callback, $line_count, 0);
				}
			}
		}

		if (!empty(trim($buffer))) {
			$query = trim(rtrim($buffer, ';'));
			if (!empty($query) && !$skipping_table) {
				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
				}
			}
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
		$is_gz ? @gzclose($fh) : @fclose($fh);

		if (!empty($result['errors'])) {
			$result['success'] = false;
		}

		return $result;
	}

	/**
	 * Atomic table restore: create tables with temp prefix, then RENAME atomically.
	 */
	public static function execute_sql_file_atomic($sql_file, $progress_callback = null) {
		global $wpdb;

		$temp_prefix = '_sgb_tmp_' . substr(md5(time()), 0, 6) . '_';

		// Read the SQL file and replace table names with temp prefix
		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');
		if (!$fh) {
			return self::execute_sql_file($sql_file, $progress_callback);
		}

		$buffer = '';
		$line_count = 0;
		$tables_created = array(); // original_name => temp_name
		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0);
		$in_string = false;
		$string_char = '';

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;
			$line_count++;
			$trimmed = trim($line);

			if (!$in_string) {
				if (empty($trimmed)) {
					continue;
				}
				if (empty($buffer) && (0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#') || 0 === strpos($trimmed, '/*'))) {
					continue;
				}
			}

			$buffer .= $line;

			// Track quoted string state
			$len = strlen($trimmed);
			for ($ci = 0; $ci < $len; $ci++) {
				$ch = $trimmed[$ci];
				if ($in_string) {
					if ($ch === '\\') { $ci++; }
					elseif ($ch === $string_char) {
						if ($ci + 1 < $len && $trimmed[$ci + 1] === $string_char) { $ci++; }
						else { $in_string = false; }
					}
				} else {
					if ($ch === "'" || $ch === '"') { $in_string = true; $string_char = $ch; }
				}
			}

			if (!$in_string && substr($trimmed, -1) === ';') {
				$query = trim(rtrim($buffer, ';'));
				$buffer = '';

				if (empty($query)) continue;

				// Rewrite table names to use temp prefix
				if (preg_match('/^(DROP TABLE\s+IF\s+EXISTS\s+)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					$temp = $temp_prefix . $original;
					$tables_created[$original] = $temp;
					$query = $m[1] . '`' . $temp . '`';
				} elseif (preg_match('/^(CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					$temp = $temp_prefix . $original;
					$tables_created[$original] = $temp;
					$query = str_replace('`' . $original . '`', '`' . $temp . '`', $query);
				} elseif (preg_match('/^(INSERT\s+INTO\s+)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					if (isset($tables_created[$original])) {
						$query = str_replace('`' . $original . '`', '`' . $tables_created[$original] . '`', $query);
					}
				}

				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error on line {$line_count}: " . $wpdb->last_error;
				}

				if (preg_match('/^CREATE\s+TABLE/i', $query)) {
					$result['tables_restored']++;
				}

				if ($progress_callback && ($line_count % 500 === 0)) {
					call_user_func($progress_callback, $line_count, 0);
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);

		// Atomic rename: swap temp tables with originals
		if (!empty($tables_created) && $result['success']) {
			$rename_parts = array();
			foreach ($tables_created as $original => $temp) {
				$original = self::sanitize_table_name($original);
				$temp = self::sanitize_table_name($temp);
				// Drop original, rename temp to original
				$wpdb->query("DROP TABLE IF EXISTS `{$original}`");
				$rename_parts[] = "`{$temp}` TO `{$original}`";
			}

			if (!empty($rename_parts)) {
				$rename_sql = "RENAME TABLE " . implode(', ', $rename_parts);
				$rename_result = $wpdb->query($rename_sql);
				if (false === $rename_result) {
					// Fallback: rename one by one
					foreach ($tables_created as $original => $temp) {
						$original = self::sanitize_table_name($original);
						$temp = self::sanitize_table_name($temp);
						$wpdb->query("DROP TABLE IF EXISTS `{$original}`");
						$wpdb->query("RENAME TABLE `{$temp}` TO `{$original}`");
					}
				}
			}
		} elseif (!empty($tables_created)) {
			// Clean up temp tables on failure
			foreach ($tables_created as $original => $temp) {
				$temp = self::sanitize_table_name($temp);
				$wpdb->query("DROP TABLE IF EXISTS `{$temp}`");
			}
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

		if (!empty($result['errors'])) {
			$result['success'] = false;
		}

		return $result;
	}

	/**
	 * Execute a SQL file for restoration.
	 *
	 * @param string $sql_file Path to SQL file.
	 * @param callable|null $progress_callback Called with ($lines_done, $total_lines).
	 * @return array ['success' => bool, 'errors' => array, 'tables_restored' => int]
	 */
	public static function execute_sql_file($sql_file, $progress_callback = null) {
		global $wpdb;

		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0);

		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');

		if (!$fh) {
			$result['success'] = false;
			$result['errors'][] = "Failed to open SQL file: {$sql_file}";
			return $result;
		}

		$buffer = '';
		$line_count = 0;
		$delimiter = ';';
		$in_string = false;  // Track if we're inside a quoted string
		$string_char = '';   // The quote character (' or ")

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$line_count++;
			$trimmed = trim($line);

			// Skip empty lines and comments only when not inside a quoted string
			// and only skip comment lines when the SQL buffer is empty (not mid-statement)
			if (!$in_string) {
				if (empty($trimmed)) {
					continue;
				}
				if (empty($buffer) && (0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#') || 0 === strpos($trimmed, '/*'))) {
					continue;
				}

				// Handle DELIMITER changes (for views/routines)
				if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $dm)) {
					$delimiter = $dm[1];
					continue;
				}
			}

			$buffer .= $line;

			// Track quoted string state to avoid false delimiter matches inside values
			$len = strlen($trimmed);
			for ($ci = 0; $ci < $len; $ci++) {
				$ch = $trimmed[$ci];
				if ($in_string) {
					if ($ch === '\\') {
						$ci++; // Skip escaped character
					} elseif ($ch === $string_char) {
						// Check for doubled quote escape ('')
						if ($ci + 1 < $len && $trimmed[$ci + 1] === $string_char) {
							$ci++;
						} else {
							$in_string = false;
						}
					}
				} else {
					if ($ch === "'" || $ch === '"') {
						$in_string = true;
						$string_char = $ch;
					}
				}
			}

			// Only match delimiter when we're not inside a quoted string
			$delim_len = strlen($delimiter);
			if (!$in_string && substr($trimmed, -$delim_len) === $delimiter) {
				$query = trim($buffer);
				$buffer = '';

				if (!empty($query)) {
					// Remove trailing delimiter
					$query = substr($query, 0, -$delim_len);
					$query = trim($query);
					if (empty($query)) continue;

					// Safety check: reject dangerous SQL operations
					if (self::is_dangerous_sql($query)) {
						$result['errors'][] = "Blocked dangerous SQL on line {$line_count}";
						continue;
					}

					$res = $wpdb->query($query);

					if (false === $res) {
						$result['errors'][] = "SQL error on line {$line_count}: " . $wpdb->last_error;
					}

					if (preg_match('/^CREATE\s+TABLE/i', $query)) {
						$result['tables_restored']++;
					}
				}

				if ($progress_callback && ($line_count % 500 === 0)) {
					call_user_func($progress_callback, $line_count, 0);
				}
			}
		}

		// Handle any remaining buffer
		if (!empty(trim($buffer))) {
			$query = trim(rtrim($buffer, ';'));
			if (!empty($query) && !self::is_dangerous_sql($query)) {
				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
				}
			}
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

		$is_gz ? @gzclose($fh) : @fclose($fh);

		if (!empty($result['errors'])) {
			$result['success'] = false;
		}

		return $result;
	}

	/**
	 * Check if a SQL statement is potentially dangerous (should not be in a backup file).
	 *
	 * @param string $query The SQL statement.
	 * @return bool True if the statement should be blocked.
	 */
	private static function is_dangerous_sql($query) {
		$dangerous_patterns = array(
			'/^\s*GRANT\s/i',
			'/^\s*REVOKE\s/i',
			'/^\s*CREATE\s+USER\s/i',
			'/^\s*DROP\s+USER\s/i',
			'/^\s*ALTER\s+USER\s/i',
			'/^\s*RENAME\s+USER\s/i',
			'/^\s*FLUSH\s+PRIVILEGES/i',
			'/\bINTO\s+OUTFILE\b/i',
			'/\bINTO\s+DUMPFILE\b/i',
			'/\bLOAD_FILE\s*\(/i',
			'/\bLOAD\s+DATA\b/i',
			'/^\s*SOURCE\s/i',
			'/^\s*SYSTEM\s/i',
			'/^\s*CREATE\s+TRIGGER\b/i',
			'/^\s*CREATE\s+EVENT\b/i',
			'/\bSET\s+GLOBAL\b/i',
			'/^\s*CALL\s/i',
			'/^\s*EXECUTE\s/i',
			'/^\s*CREATE\s+PROCEDURE\b/i',
			'/^\s*CREATE\s+FUNCTION\b/i',
		);

		foreach ($dangerous_patterns as $pattern) {
			if (preg_match($pattern, $query)) {
				return true;
			}
		}
		return false;
	}
}
