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

			// Capture stderr separately so warnings (e.g. MariaDB deprecation
			// notices) don't pollute the SQL output file.
			// --hex-blob so binary/blob data round-trips safely; explicit
			// --default-character-set so multibyte text is emitted in the DB's
			// charset rather than the client default (M3).
			$charset = (defined('DB_CHARSET') && DB_CHARSET) ? DB_CHARSET : 'utf8mb4';
			$stderr_file = $opts_file . '.err';
			$cmd = sprintf(
				'mysqldump --defaults-extra-file=%s --no-tablespaces --single-transaction --quick --skip-lock-tables --skip-add-locks --hex-blob --default-character-set=%s %s %s %s %s > %s 2>%s',
				escapeshellarg($opts_file),
				escapeshellarg($charset),
				$port,
				$socket,
				escapeshellarg($db),
				escapeshellarg($table),
				escapeshellarg($outfile),
				escapeshellarg($stderr_file)
			);

			$output = array();
			$return = -1;
			exec($cmd, $output, $return);

			if (0 !== $return) {
				$stderr = file_exists($stderr_file) ? trim(file_get_contents($stderr_file)) : '';
				error_log('SiteGuard Backup: mysqldump failed (return code ' . $return . '): ' . $stderr);
			}
			@unlink($stderr_file);

			clearstatcache(true, $outfile);
			return (0 === $return && file_exists($outfile) && filesize($outfile) > 0);
		} finally {
			@unlink($opts_file);
		}
	}

	/**
	 * Get user IDs that have capabilities on a specific subsite.
	 *
	 * @param int $blog_id The subsite blog ID.
	 * @return array User IDs.
	 */
	public static function get_subsite_user_ids($blog_id) {
		global $wpdb;
		$cap_key = $wpdb->base_prefix . intval($blog_id) . '_capabilities';
		$user_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
			$cap_key
		));
		return array_map('intval', $user_ids);
	}

	/**
	 * Dump a table using PHP (fallback when mysqldump is unavailable).
	 *
	 * @param string $table          Table name.
	 * @param string $outfile        Output file path (.sql or .sql.gz).
	 * @param int    $batch_size     Rows per batch.
	 * @param bool   $structure_only If true, dump only CREATE TABLE (no INSERT statements).
	 * @param string $where_clause   Optional WHERE clause for filtered dumps (e.g. "ID IN (1,2,3)").
	 * @return bool True on success.
	 */
	public static function php_dump_table($table, $outfile, $batch_size = 1000, $structure_only = false, $where_clause = '', $anonymiser = null) {
		global $wpdb;

		$table = self::sanitize_table_name($table);
		if (!$table) return false;

		$use_gzip = (substr($outfile, -3) === '.gz');
		$fh = $use_gzip ? @gzopen($outfile, 'wb9') : @fopen($outfile, 'w');
		if (!$fh) return false;

		// Writer returns bytes written (or false). Every write MUST be verified
		// against the intended length - an unchecked short write silently
		// truncates the dump (C2).
		$write = function($data) use ($fh, $use_gzip) {
			return $use_gzip ? @gzwrite($fh, $data) : @fwrite($fh, $data);
		};
		// Abort: close, delete the partial output, return false so the table is
		// never treated as fully dumped.
		$abort = function($msg = '') use ($fh, $use_gzip, $outfile, $table) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			@unlink($outfile);
			if ($msg !== '') error_log("SiteGuard Backup: dump of {$table} aborted: {$msg}");
			return false;
		};
		$put = function($data) use ($write) {
			if ('' === $data) return true;
			$n = $write($data);
			return ($n !== false && $n === strlen($data));
		};
		$close_ok = function() use ($fh, $use_gzip, $outfile) {
			$ok = $use_gzip ? @gzclose($fh) : @fclose($fh);
			if (false === $ok) { @unlink($outfile); return false; }
			return true;
		};

		// Table structure
		$create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
		if (!$create || !isset($create[1])) {
			return $abort('SHOW CREATE TABLE failed');
		}

		if (!$put("DROP TABLE IF EXISTS `{$table}`;\n") || !$put($create[1] . ";\n\n")) {
			return $abort('short write on structure');
		}

		// Gap 21: Structure-only mode - skip data
		if ($structure_only) {
			if (!$put("-- Structure only: data excluded by data_optional_tables setting\n\n")) {
				return $abort('short write (structure-only)');
			}
			return $close_ok();
		}

		// Table data
		$where_sql = !empty($where_clause) ? " WHERE {$where_clause}" : '';
		$total_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`{$where_sql}");
		if ($total_rows === 0) {
			if (!$put("-- sgbackup:table-complete {$table} rows=0\n\n")) return $abort('short write (marker)');
			return $close_ok();
		}

		// Column metadata. Exclude STORED/VIRTUAL generated columns from BOTH the
		// SELECT and the INSERT column list - listing a generated column in INSERT
		// fails with err 3105 and loses the whole table (M1).
		$columns = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
		if (empty($columns)) return $abort('SHOW COLUMNS failed');

		$data_cols = array();
		$binary_cols = array();
		$all_pk_cols = array();
		foreach ($columns as $col) {
			if ($col['Key'] === 'PRI') $all_pk_cols[] = $col['Field'];
			if (isset($col['Extra']) && preg_match('/GENERATED/i', $col['Extra'])) {
				continue; // never dump generated column values
			}
			$data_cols[] = $col['Field'];
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}
		if (empty($data_cols)) return $abort('no dumpable columns');

		$col_list = implode(', ', array_map(function($f) { return '`' . $f . '`'; }, $data_cols));

		// Pagination strategy (C1):
		//  - exactly one PRI column (and no WHERE) -> single-column keyset (fast).
		//  - composite PK, no PK, or a WHERE clause -> deterministic full ORDER BY
		//    with LIMIT/OFFSET so no rows are dropped at tie boundaries.
		$single_keyset = (count($all_pk_cols) === 1 && empty($where_clause) && in_array($all_pk_cols[0], $data_cols, true));
		$pk = $single_keyset ? $all_pk_cols[0] : null;
		$order_cols = !empty($all_pk_cols) ? $all_pk_cols : $data_cols;
		$order_by = implode(', ', array_map(function($f) { return '`' . $f . '` ASC'; }, $order_cols));

		// Byte-budgeted INSERT emission (H1): accumulate rows until ~8MB (capped by
		// max_allowed_packet/2) regardless of row count, so wide page-builder rows
		// can't balloon a single statement / peak memory.
		$byte_budget = 8 * 1024 * 1024;
		$packet_row = $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value) && (int) $packet_row->Value > 0) {
			$byte_budget = (int) min($byte_budget, max(65536, ((int) $packet_row->Value) / 2));
		}

		$insert_header = "INSERT INTO `{$table}` ({$col_list}) VALUES\n";
		$pending = array();
		$pending_bytes = 0;
		$flush_insert = function() use (&$pending, &$pending_bytes, $insert_header, $put) {
			if (empty($pending)) return true;
			$ok = $put($insert_header . implode(",\n", $pending) . ";\n\n");
			$pending = array();
			$pending_bytes = 0;
			return $ok;
		};

		// Keep the SELECT itself modest - with unknown row width, start small and
		// let it adapt down under memory pressure.
		$fetch_limit = max(1, min(200, (int) $batch_size));

		$have_last = false;
		$last_pk = null;
		$rows_done = 0;
		$offset = 0;

		while ($rows_done < $total_rows) {
			if ($single_keyset) {
				if ($have_last) {
					$rows = $wpdb->get_results($wpdb->prepare(
						"SELECT {$col_list} FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` ASC LIMIT %d",
						$last_pk, $fetch_limit
					), ARRAY_A);
				} else {
					$rows = $wpdb->get_results($wpdb->prepare(
						"SELECT {$col_list} FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT %d",
						$fetch_limit
					), ARRAY_A);
				}
			} else {
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT {$col_list} FROM `{$table}`{$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
					$fetch_limit, $offset
				), ARRAY_A);
			}

			if (empty($rows)) {
				// Empty can mean genuine EOF OR a query error - get_results()
				// returns empty on both. Distinguish via last_error (C3).
				if ($wpdb->last_error) {
					return $abort('query error: ' . $wpdb->last_error);
				}
				break;
			}

			foreach ($rows as $row) {
				// Apply anonymisation if available (row-aware - see anonymise_row).
				// Kept null for normal backups so this path is a no-op there.
				if ($anonymiser !== null && $anonymiser->should_anonymise_row($table, $row)) {
					$row = $anonymiser->anonymise_row($table, $row);
				}
				$values = array();
				foreach ($row as $col_name => $value) {
					if (is_null($value)) {
						$values[] = 'NULL';
					} elseif (isset($binary_cols[$col_name])) {
						$values[] = strlen($value) > 0 ? '0x' . bin2hex($value) : "''";
					} else {
						// Strip the placeholder-escape token _real_escape() appends
						// so % survives the dump (Yoast %%sep%%, encoded URLs, …).
						$values[] = "'" . $wpdb->remove_placeholder_escape($wpdb->_real_escape($value)) . "'";
					}
				}
				$row_str = '(' . implode(', ', $values) . ')';

				if (!empty($pending) && ($pending_bytes + strlen($row_str) + 2) > $byte_budget) {
					if (!$flush_insert()) return $abort('short write (insert flush)');
				}
				$pending[] = $row_str;
				$pending_bytes += strlen($row_str) + 2;

				if ($single_keyset && isset($row[$pk])) {
					$last_pk = $row[$pk];
				}
			}

			$fetched = count($rows);
			$rows_done += $fetched;
			$offset += $fetched;
			$have_last = true;

			// Adaptive fetch sizing based on memory (secondary guard alongside the
			// byte-budgeted flush above).
			$mem_usage = memory_get_usage(true);
			$mem_limit = self::parse_size(ini_get('memory_limit'));
			if ($mem_limit > 0 && $mem_usage > ($mem_limit * 0.7)) {
				$fetch_limit = max(20, intval($fetch_limit / 2));
			}
		}

		if (!$flush_insert()) return $abort('short write (final insert)');

		// Completeness check (C3): a large shortfall means we lost data. A tiny one
		// can be legitimate (rows deleted concurrently) - tolerate <1%.
		if ($rows_done < $total_rows) {
			$ratio = $total_rows > 0 ? ($rows_done / $total_rows) : 1;
			if ($ratio < 0.99) {
				return $abort("truncated: only {$rows_done}/{$total_rows} rows emitted");
			}
			error_log("SiteGuard Backup: dump of {$table} short by " . ($total_rows - $rows_done) . " rows (within tolerance)");
		}

		if (!$put("-- sgbackup:table-complete {$table} rows={$rows_done}\n\n")) return $abort('short write (marker)');

		return $close_ok();
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
		if (!$table) return false;

		$use_gzip = (substr($outfile, -3) === '.gz');
		$fh = $use_gzip ? @gzopen($outfile, 'wb9') : @fopen($outfile, 'w');
		if (!$fh) return false;

		// See php_dump_table for the rationale behind the checked writer / abort.
		$write = function($data) use ($fh, $use_gzip) {
			return $use_gzip ? @gzwrite($fh, $data) : @fwrite($fh, $data);
		};
		$abort = function($msg = '') use ($fh, $use_gzip, $outfile, $table) {
			$use_gzip ? @gzclose($fh) : @fclose($fh);
			@unlink($outfile);
			if ($msg !== '') error_log("SiteGuard Backup: dump of {$table} aborted: {$msg}");
			return false;
		};
		$put = function($data) use ($write) {
			if ('' === $data) return true;
			$n = $write($data);
			return ($n !== false && $n === strlen($data));
		};
		$close_ok = function() use ($fh, $use_gzip, $outfile) {
			$ok = $use_gzip ? @gzclose($fh) : @fclose($fh);
			if (false === $ok) { @unlink($outfile); return false; }
			return true;
		};

		// Table structure
		$create = $wpdb_instance->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
		if (!$create || !isset($create[1])) {
			return $abort('SHOW CREATE TABLE failed');
		}

		if (!$put("DROP TABLE IF EXISTS `{$table}`;\n") || !$put($create[1] . ";\n\n")) {
			return $abort('short write on structure');
		}

		// Table data
		$total_rows = (int) $wpdb_instance->get_var("SELECT COUNT(*) FROM `{$table}`");
		if ($total_rows === 0) {
			if (!$put("-- sgbackup:table-complete {$table} rows=0\n\n")) return $abort('short write (marker)');
			return $close_ok();
		}

		// Column metadata - exclude generated columns from SELECT + INSERT (M1).
		$columns = $wpdb_instance->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
		if (empty($columns)) return $abort('SHOW COLUMNS failed');

		$data_cols = array();
		$binary_cols = array();
		$all_pk_cols = array();
		foreach ($columns as $col) {
			if ($col['Key'] === 'PRI') $all_pk_cols[] = $col['Field'];
			if (isset($col['Extra']) && preg_match('/GENERATED/i', $col['Extra'])) {
				continue;
			}
			$data_cols[] = $col['Field'];
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}
		if (empty($data_cols)) return $abort('no dumpable columns');

		$col_list = implode(', ', array_map(function($f) { return '`' . $f . '`'; }, $data_cols));

		// Pagination strategy (C1): single-column keyset when exactly one PRI col,
		// otherwise deterministic full ORDER BY + LIMIT/OFFSET.
		$single_keyset = (count($all_pk_cols) === 1 && in_array($all_pk_cols[0], $data_cols, true));
		$pk = $single_keyset ? $all_pk_cols[0] : null;
		$order_cols = !empty($all_pk_cols) ? $all_pk_cols : $data_cols;
		$order_by = implode(', ', array_map(function($f) { return '`' . $f . '` ASC'; }, $order_cols));

		// Byte-budgeted INSERT emission (H1).
		$byte_budget = 8 * 1024 * 1024;
		$packet_row = $wpdb_instance->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value) && (int) $packet_row->Value > 0) {
			$byte_budget = (int) min($byte_budget, max(65536, ((int) $packet_row->Value) / 2));
		}

		$insert_header = "INSERT INTO `{$table}` ({$col_list}) VALUES\n";
		$pending = array();
		$pending_bytes = 0;
		$flush_insert = function() use (&$pending, &$pending_bytes, $insert_header, $put) {
			if (empty($pending)) return true;
			$ok = $put($insert_header . implode(",\n", $pending) . ";\n\n");
			$pending = array();
			$pending_bytes = 0;
			return $ok;
		};

		$fetch_limit = max(1, min(200, (int) $batch_size));
		$have_last = false;
		$last_pk = null;
		$rows_done = 0;
		$offset = 0;

		while ($rows_done < $total_rows) {
			if ($single_keyset) {
				if ($have_last) {
					$rows = $wpdb_instance->get_results($wpdb_instance->prepare(
						"SELECT {$col_list} FROM `{$table}` WHERE `{$pk}` > %s ORDER BY `{$pk}` ASC LIMIT %d",
						$last_pk, $fetch_limit
					), ARRAY_A);
				} else {
					$rows = $wpdb_instance->get_results($wpdb_instance->prepare(
						"SELECT {$col_list} FROM `{$table}` ORDER BY `{$pk}` ASC LIMIT %d",
						$fetch_limit
					), ARRAY_A);
				}
			} else {
				$rows = $wpdb_instance->get_results($wpdb_instance->prepare(
					"SELECT {$col_list} FROM `{$table}` ORDER BY {$order_by} LIMIT %d OFFSET %d",
					$fetch_limit, $offset
				), ARRAY_A);
			}

			if (empty($rows)) {
				if ($wpdb_instance->last_error) {
					return $abort('query error: ' . $wpdb_instance->last_error);
				}
				break;
			}

			foreach ($rows as $row) {
				// Apply anonymisation if available (row-aware - see anonymise_row).
				if ($anonymiser !== null && $anonymiser->should_anonymise_row($table, $row)) {
					$row = $anonymiser->anonymise_row($table, $row);
				}

				$values = array();
				foreach ($row as $col_name => $value) {
					if (is_null($value)) {
						$values[] = 'NULL';
					} elseif (isset($binary_cols[$col_name])) {
						$values[] = strlen($value) > 0 ? '0x' . bin2hex($value) : "''";
					} else {
						$values[] = "'" . $wpdb_instance->remove_placeholder_escape($wpdb_instance->_real_escape($value)) . "'";
					}
				}
				$row_str = '(' . implode(', ', $values) . ')';

				if (!empty($pending) && ($pending_bytes + strlen($row_str) + 2) > $byte_budget) {
					if (!$flush_insert()) return $abort('short write (insert flush)');
				}
				$pending[] = $row_str;
				$pending_bytes += strlen($row_str) + 2;

				if ($single_keyset && isset($row[$pk])) {
					$last_pk = $row[$pk];
				}
			}

			$fetched = count($rows);
			$rows_done += $fetched;
			$offset += $fetched;
			$have_last = true;

			$mem_usage = memory_get_usage(true);
			$mem_limit = self::parse_size(ini_get('memory_limit'));
			if ($mem_limit > 0 && $mem_usage > ($mem_limit * 0.7)) {
				$fetch_limit = max(20, intval($fetch_limit / 2));
			}
		}

		if (!$flush_insert()) return $abort('short write (final insert)');

		if ($rows_done < $total_rows) {
			$ratio = $total_rows > 0 ? ($rows_done / $total_rows) : 1;
			if ($ratio < 0.99) {
				return $abort("truncated: only {$rows_done}/{$total_rows} rows emitted");
			}
			error_log("SiteGuard Backup: dump of {$table} short by " . ($total_rows - $rows_done) . " rows (within tolerance)");
		}

		if (!$put("-- sgbackup:table-complete {$table} rows={$rows_done}\n\n")) return $abort('short write (marker)');

		return $close_ok();
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
				$sql .= self::strip_definer($create[1]) . ";\n\n";
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

		// NOTE (M4 scoping): information_schema.ROUTINES is not prefix-filterable
		// and is scoped to the whole schema, so this includes ALL routines in the
		// database - including any belonging to other apps sharing the DB. We strip
		// DEFINER (the main restore breakage, err 1449) but do NOT attempt to scope
		// by prefix here. Callers doing prefix-filtered backups should be aware.
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
				$sql .= self::strip_definer($create[2]) . ";;\n\n";
			}
		}
		$sql .= "DELIMITER ;\n";

		return (bool) file_put_contents($outfile, $sql, FILE_APPEND);
	}

	/**
	 * Remove the `DEFINER=`user`@`host`` clause from a CREATE VIEW/PROCEDURE/
	 * FUNCTION/TRIGGER statement. Restoring a definer that references a user which
	 * does not exist on the target server fails with err 1449 (M4). Handles both
	 * backtick- and single-quote-quoted user/host forms.
	 */
	public static function strip_definer($sql) {
		$out = preg_replace(
			'/\sDEFINER\s*=\s*(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\')@(?:`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\')/i',
			'',
			$sql
		);
		return (null === $out) ? $sql : $out;
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
			case 't': $value *= 1099511627776; break;
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

				// Handle DELIMITER changes (for routines/views)
				if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $dm)) {
					$delimiter = $dm[1];
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
					} else {
						$skipping_table = false;
					}
				}
			}

			if ($skipping_table) {
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

			$delim_len = strlen($delimiter);
			if (!$in_string && substr($trimmed, -$delim_len) === $delimiter) {
				$query = trim($buffer);
				$buffer = '';

				if (!empty($query)) {
					$query = substr($query, 0, -$delim_len);
					$query = trim($query);

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
				if (self::is_dangerous_sql($query)) {
					$result['errors'][] = "Blocked dangerous SQL at end of file";
				} else {
					$res = $wpdb->query($query);
					if (false === $res) {
						$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
					}
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

		// Compute the temp table name, hash-truncating so it never exceeds MySQL's
		// 64-char identifier limit (M12) - otherwise CREATE fails with err 1059 and
		// the atomic swap loses the table's data.
		$make_temp = function($original) use ($temp_prefix) {
			$temp = $temp_prefix . $original;
			if (strlen($temp) > 64) {
				$keep = 64 - strlen($temp_prefix) - 9; // 8 hash chars + '_'
				if ($keep < 0) $keep = 0;
				$temp = $temp_prefix . substr(md5($original), 0, 8) . '_' . substr($original, 0, $keep);
			}
			return $temp;
		};

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
		$delimiter = ';';

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		// max_allowed_packet for INSERT splitting
		$max_packet = 16777216;
		$packet_row = $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value)) {
			$max_packet = (int) $packet_row->Value;
		}

		// Supported charsets and engines for cross-version compatibility
		$supported_charsets = array();
		$cr_rows = $wpdb->get_results("SHOW CHARACTER SET");
		if ($cr_rows) foreach ($cr_rows as $cr) $supported_charsets[strtolower($cr->Charset)] = true;
		$supported_engines = array();
		$er_rows = $wpdb->get_results("SHOW ENGINES");
		if ($er_rows) foreach ($er_rows as $er) {
			if (in_array($er->Support, array('DEFAULT', 'YES'))) $supported_engines[strtolower($er->Engine)] = true;
		}

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;
			$line_count++;
			$trimmed = trim($line);

			if (!$in_string) {
				if (empty($trimmed)) {
					continue;
				}
				// Skip comments, MariaDB sandbox header, and mysqldump noise
				if (empty($buffer) && (
					0 === strpos($trimmed, '--') ||
					0 === strpos($trimmed, '#') ||
					0 === strpos($trimmed, '/*') ||
					0 === strpos($trimmed, 'mysqldump:') ||
					0 === strpos($trimmed, 'mariadb-dump:')
				)) {
					continue;
				}
				// Handle DELIMITER changes (for routines/views)
				if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $dm)) {
					$delimiter = $dm[1];
					continue;
				}
				// Skip LOCK/UNLOCK TABLES - incompatible with atomic temp-table approach
				if (preg_match('/^(LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $trimmed)) {
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

			$delim_len = strlen($delimiter);
			if (!$in_string && substr($trimmed, -$delim_len) === $delimiter) {
				$query = trim(substr(trim($buffer), 0, -$delim_len));
				$buffer = '';

				if (empty($query)) continue;

				// MySQL conditional comment (/*!ver ... */). Strip the wrapper and vet
				// the inner statement BEFORE executing - otherwise CREATE TRIGGER/EVENT
				// etc. smuggled inside a version comment bypasses is_dangerous_sql() (M6).
				if (preg_match('/^\/\*!?\d*\s/i', $query)) {
					$inner = preg_replace('#^/\*!?\d*\s*#', '', $query);
					$inner = preg_replace('#\*/\s*$#', '', $inner);
					$inner = trim($inner);
					if (self::is_dangerous_sql($inner)) {
						$result['errors'][] = "Blocked dangerous SQL in conditional comment on line {$line_count}";
						continue;
					}
					$wpdb->query($query);
					continue;
				}

				// Skip LOCK/UNLOCK that may appear mid-buffer
				if (preg_match('/^(LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $query)) {
					continue;
				}

				// Handle SET NAMES: verify charset is supported
				if (preg_match('/^\s*(?:\/\*!\d+\s*)?SET\s+NAMES\s+[\'"]?(\w+)/i', $query, $charset_m)) {
					$req_charset = strtolower($charset_m[1]);
					if (!isset($supported_charsets[$req_charset])) {
						$fb = isset($supported_charsets['utf8mb4']) ? 'utf8mb4' : 'utf8';
						$query = preg_replace('/SET\s+NAMES\s+[\'"]?\w+[\'"]?/i', "SET NAMES {$fb}", $query);
					}
				}

				// Rewrite table names to use temp prefix
				if (preg_match('/^(DROP TABLE\s+IF\s+EXISTS\s+)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					$temp = $make_temp($original);
					$tables_created[$original] = $temp;
					$query = $m[1] . '`' . $temp . '`';
				} elseif (preg_match('/^(CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					$temp = $make_temp($original);
					$tables_created[$original] = $temp;
					$query = str_replace('`' . $original . '`', '`' . $temp . '`', $query);
					// Engine compatibility
					if (preg_match('/ENGINE\s*=\s*(\w+)/i', $query, $eng_m)) {
						if (!isset($supported_engines[strtolower($eng_m[1])])) {
							$fb_eng = isset($supported_engines['innodb']) ? 'InnoDB' : 'MyISAM';
							$query = preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=' . $fb_eng, $query);
						}
					}
				} elseif (preg_match('/^(INSERT\s+INTO\s+)`([^`]+)`/i', $query, $m)) {
					$original = $m[2];
					if (isset($tables_created[$original])) {
						$query = str_replace('`' . $original . '`', '`' . $tables_created[$original] . '`', $query);
					}
				}

				if (self::is_dangerous_sql($query)) {
					$result['errors'][] = "Blocked dangerous SQL on line {$line_count}";
					continue;
				}

				// Split oversized INSERT statements at row boundaries
				if (preg_match('/^INSERT\s+INTO/i', $query) && strlen($query) > ($max_packet - 1024)) {
					$insert_prefix = '';
					if (preg_match('/^(INSERT\s+INTO\s+`[^`]+`\s*(?:\([^)]+\))?\s*VALUES\s*)/is', $query, $pfx_m)) {
						$insert_prefix = $pfx_m[1];
					}
					if ($insert_prefix) {
						$values_part = substr($query, strlen($insert_prefix));
						$chunks = self::split_insert_values($values_part, $max_packet - strlen($insert_prefix) - 1024);
						foreach ($chunks as $chunk) {
							$sub_res = $wpdb->query($insert_prefix . $chunk);
							if (false === $sub_res) {
								$result['errors'][] = "SQL error (split INSERT) on line {$line_count}: " . $wpdb->last_error;
							}
						}
						if ($progress_callback && ($line_count % 500 === 0)) {
							call_user_func($progress_callback, $line_count, 0);
						}
						continue;
					}
				}

				// Reconnect if needed. If the connection object changed (a reconnect
				// happened), re-apply the session import state (M5).
				if (method_exists($wpdb, 'check_connection')) {
					$dbh_before = $wpdb->dbh;
					if ($wpdb->check_connection(false) && $wpdb->dbh !== $dbh_before) {
						self::apply_import_session_state();
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

		// Handle any remaining buffer (e.g. final statement without trailing delimiter)
		if (!empty(trim($buffer))) {
			$query = trim(rtrim($buffer, $delimiter));
			if (!empty($query) && !self::is_dangerous_sql($query)) {
				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
				}
			}
		}

		$is_gz ? @gzclose($fh) : @fclose($fh);

		// Atomic rename: swap temp tables with originals - ONLY when the import
		// produced no errors. Gating on empty(errors) (evaluated BEFORE the rename)
		// is what stops half-empty temp tables being renamed over live data while
		// the originals are dropped (C4). The previous gate used $result['success'],
		// which is not computed until after this block, so it was always true.
		if (!empty($tables_created) && empty($result['errors'])) {
			$old_tables = array();
			foreach ($tables_created as $original => $temp) {
				$original = self::sanitize_table_name($original);
				// Use hash to avoid name collisions on long table names
				$old_backup = '_sgb_old_' . substr(md5($original), 0, 8) . '_' . substr($original, 0, 40);
				$old_tables[] = $old_backup;
			}

			// Drop any leftover _sgb_old_ tables from previous crashed restores
			foreach ($old_tables as $old_tbl) {
				$wpdb->query("DROP TABLE IF EXISTS `{$old_tbl}`");
			}

			$rename_parts = array();
			$old_idx = 0;
			foreach ($tables_created as $original => $temp) {
				$original = self::sanitize_table_name($original);
				$temp = self::sanitize_table_name($temp);
				$old_backup = $old_tables[$old_idx++];
				$rename_parts[] = "`{$original}` TO `{$old_backup}`, `{$temp}` TO `{$original}`";
			}

			// Track which _sgb_old_ backups are safe to drop (i.e. their original was
			// actually superseded by a confirmed temp->live rename).
			$swapped_olds = array();

			if (!empty($rename_parts)) {
				$rename_sql = "RENAME TABLE " . implode(', ', $rename_parts);
				$rename_result = $wpdb->query($rename_sql);
				if (false === $rename_result) {
					// Fallback: two-step rename per table. Do NOT drop the _sgb_old_
					// backup until the temp->live rename is confirmed (C4). If temp->live
					// fails, roll the original back so live data is never lost.
					$old_idx = 0;
					foreach ($tables_created as $original => $temp) {
						$original = self::sanitize_table_name($original);
						$temp = self::sanitize_table_name($temp);
						$old_backup = $old_tables[$old_idx++];
						$wpdb->query("DROP TABLE IF EXISTS `{$old_backup}`");
						// original may not exist yet (first-ever restore of this table);
						// a failed move there is expected and fine.
						$r_orig = $wpdb->query("RENAME TABLE `{$original}` TO `{$old_backup}`");
						$r_temp = $wpdb->query("RENAME TABLE `{$temp}` TO `{$original}`");
						if (false === $r_temp) {
							$result['errors'][] = "Atomic rename fallback failed for `{$original}` - live table left intact";
							// Roll the original back if we had moved it aside.
							if (false !== $r_orig) {
								$wpdb->query("RENAME TABLE `{$old_backup}` TO `{$original}`");
							}
							// Leave the temp table for inspection; nothing to clean up here.
						} elseif (false !== $r_orig) {
							$swapped_olds[] = $old_backup;
						}
					}
				} else {
					$swapped_olds = $old_tables;
				}

				// Clean up only the backups whose originals were confirmed superseded.
				foreach ($swapped_olds as $old_tbl) {
					$wpdb->query("DROP TABLE IF EXISTS `{$old_tbl}`");
				}
			}
		} elseif (!empty($tables_created)) {
			// Import had errors - drop the temp tables and leave the live tables (and
			// any originals) completely untouched.
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

		// Query max_allowed_packet to split oversized INSERT statements
		$max_packet = 16777216; // 16MB default
		$packet_row = $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value)) {
			$max_packet = (int) $packet_row->Value;
		}

		// Cache supported charsets/engines for compatibility rewriting
		$supported_charsets = array();
		$charset_rows = $wpdb->get_results("SHOW CHARACTER SET");
		if ($charset_rows) {
			foreach ($charset_rows as $cr) {
				$supported_charsets[strtolower($cr->Charset)] = true;
			}
		}
		$supported_engines = array();
		$engine_rows = $wpdb->get_results("SHOW ENGINES");
		if ($engine_rows) {
			foreach ($engine_rows as $er) {
				if (in_array($er->Support, array('DEFAULT', 'YES'))) {
					$supported_engines[strtolower($er->Engine)] = true;
				}
			}
		}

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

					// Handle SET NAMES: verify charset is supported
					if (preg_match('/^\s*(?:\/\*!\d+\s*)?SET\s+NAMES\s+[\'"]?(\w+)/i', $query, $charset_m)) {
						$requested_charset = strtolower($charset_m[1]);
						if (!isset($supported_charsets[$requested_charset])) {
							// Fall back to utf8 if requested charset not supported
							$fallback = isset($supported_charsets['utf8mb4']) ? 'utf8mb4' : 'utf8';
							$query = preg_replace('/SET\s+NAMES\s+[\'"]?\w+[\'"]?/i', "SET NAMES {$fallback}", $query);
						}
					}

					// Engine compatibility: replace unsupported engines in CREATE TABLE
					if (preg_match('/^CREATE\s+TABLE/i', $query) && preg_match('/ENGINE\s*=\s*(\w+)/i', $query, $eng_m)) {
						$engine = strtolower($eng_m[1]);
						if (!isset($supported_engines[$engine])) {
							$fallback_engine = isset($supported_engines['innodb']) ? 'InnoDB' : 'MyISAM';
							$query = preg_replace('/ENGINE\s*=\s*\w+/i', 'ENGINE=' . $fallback_engine, $query);
						}
					}

					// Split oversized INSERT statements at row boundaries
					if (preg_match('/^INSERT\s+INTO/i', $query) && strlen($query) > ($max_packet - 1024)) {
						$insert_prefix = '';
						if (preg_match('/^(INSERT\s+INTO\s+`[^`]+`\s*(?:\([^)]+\))?\s*VALUES\s*)/is', $query, $pfx_m)) {
							$insert_prefix = $pfx_m[1];
						}
						if ($insert_prefix) {
							$values_part = substr($query, strlen($insert_prefix));
							$chunks = self::split_insert_values($values_part, $max_packet - strlen($insert_prefix) - 1024);
							foreach ($chunks as $chunk) {
								$sub_query = $insert_prefix . $chunk;
								$sub_res = $wpdb->query($sub_query);
								if (false === $sub_res) {
									$result['errors'][] = "SQL error (split INSERT) on line {$line_count}: " . $wpdb->last_error;
								}
							}
							// Skip the normal execution below since we handled it
							if (preg_match('/^CREATE\s+TABLE/i', $query)) {
								$result['tables_restored']++;
							}
							if ($progress_callback && ($line_count % 500 === 0)) {
								call_user_func($progress_callback, $line_count, 0);
							}
							continue;
						}
					}

					// Reconnect if MySQL connection was lost. Re-apply session import
					// state when a reconnect actually replaced the connection (M5).
					if (method_exists($wpdb, 'check_connection')) {
						$dbh_before = $wpdb->dbh;
						if ($wpdb->check_connection(false) && $wpdb->dbh !== $dbh_before) {
							self::apply_import_session_state();
						}
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
	 * Split INSERT VALUES clause into chunks that fit within max_allowed_packet.
	 *
	 * @param string $values_str The VALUES portion: "(row1),(row2),(row3)"
	 * @param int    $max_size   Maximum byte size per chunk.
	 * @return array Array of value strings, each small enough for one query.
	 */
	private static function split_insert_values($values_str, $max_size) {
		$chunks = array();
		$current = '';
		$depth = 0;
		$in_string = false;
		$string_char = '';
		$len = strlen($values_str);
		$row_start = 0;

		for ($i = 0; $i < $len; $i++) {
			$ch = $values_str[$i];

			if ($in_string) {
				if ($ch === '\\') { $i++; continue; }
				if ($ch === $string_char) { $in_string = false; }
				continue;
			}

			if ($ch === "'" || $ch === '"') {
				$in_string = true;
				$string_char = $ch;
			} elseif ($ch === '(') {
				if ($depth === 0) $row_start = $i;
				$depth++;
			} elseif ($ch === ')') {
				$depth--;
				if ($depth === 0) {
					// End of a row value group
					$row = substr($values_str, $row_start, $i - $row_start + 1);
					if (strlen($current) + strlen($row) + 2 > $max_size && !empty($current)) {
						$chunks[] = $current;
						$current = $row;
					} else {
						$current .= (empty($current) ? '' : ',') . $row;
					}
				}
			}
		}

		if (!empty($current)) {
			$chunks[] = $current;
		}

		return !empty($chunks) ? $chunks : array($values_str);
	}

	/**
	 * Re-apply the per-session import state (FK checks off, NO_AUTO_VALUE_ON_ZERO).
	 *
	 * wpdb::check_connection() may transparently reconnect mid-import, which resets
	 * all SESSION variables - losing the SET statements issued at the top of the
	 * import loop and corrupting AUTO_INCREMENT-zero rows / tripping FK errors (M5).
	 * Callers invoke this only when they detect the underlying connection changed.
	 *
	 * @param object|null $db Optional wpdb-like connection; defaults to global $wpdb.
	 */
	private static function apply_import_session_state($db = null) {
		global $wpdb;
		$conn = $db ? $db : $wpdb;
		$conn->query('SET FOREIGN_KEY_CHECKS = 0');
		$conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
	}

	/**
	 * Check if a SQL statement is potentially dangerous (should not be in a backup file).
	 *
	 * @param string $query The SQL statement.
	 * @return bool True if the statement should be blocked.
	 */
	private static function is_dangerous_sql($query) {
		// Data statements (INSERT/REPLACE) can never be a privilege or file
		// operation - OUTFILE/LOAD DATA/GRANT are not valid inside them - so the
		// patterns below must NOT be matched against their row data. Several
		// patterns are non-anchored (\bLOAD DATA\b, \bINTO OUTFILE\b, \bSET
		// GLOBAL\b, …) and would otherwise false-positive on ordinary content
		// that merely contains the phrase (e.g. Elementor JSON with the words
		// "Load data"), silently dropping a whole batch of legitimate rows.
		if (preg_match('/^\s*(?:INSERT|REPLACE)\b/i', $query)) {
			return false;
		}

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
		);

		foreach ($dangerous_patterns as $pattern) {
			if (preg_match($pattern, $query)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract table names from a SQL dump file without importing.
	 *
	 * @param string $sql_file Path to .sql or .sql.gz file.
	 * @return array List of table names found in CREATE TABLE statements.
	 */
	public static function get_tables_in_sql_file($sql_file) {
		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');
		if (!$fh) return array();

		$tables = array();
		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			if (preg_match('/^CREATE TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`([^`]+)`/i', trim($line), $m)) {
				$tables[] = $m[1];
			}
		}

		$is_gz ? gzclose($fh) : fclose($fh);
		return $tables;
	}

	/**
	 * Import a SQL file with table prefix remapping.
	 *
	 * Rewrites table names from source prefix to target prefix.
	 * Skips wp_users and wp_usermeta tables (handled by user merge).
	 *
	 * @param string $sql_file       Path to SQL file.
	 * @param string $source_prefix  Source table prefix (e.g. "wp_2_").
	 * @param string $target_prefix  Target table prefix (e.g. "wp_5_").
	 * @param string $base_prefix    WordPress base prefix (e.g. "wp_") for skipping global tables.
	 * @return array ['success' => bool, 'errors' => array, 'tables_restored' => int]
	 */
	public static function execute_sql_file_remap_prefix($sql_file, $source_prefix, $target_prefix, $base_prefix = '') {
		global $wpdb;

		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0);

		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');
		if (!$fh) {
			$result['success'] = false;
			$result['errors'][] = "Failed to open SQL file: {$sql_file}";
			return $result;
		}

		// Tables to skip (global multisite tables that shouldn't be remapped)
		$skip_tables = array();
		if (!empty($base_prefix)) {
			$skip_tables = array(
				$base_prefix . 'users',
				$base_prefix . 'usermeta',
			);
		}

		$buffer = '';
		$line_count = 0;
		$in_string = false;
		$string_char = '';
		$current_table = '';
		$skipping_table = false;

		// max_allowed_packet for splitting oversized INSERT statements
		$max_packet = 16777216;
		$packet_row = $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value) && (int) $packet_row->Value > 0) {
			$max_packet = (int) $packet_row->Value;
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$line_count++;
			$trimmed = trim($line);

			if (!$in_string) {
				if (empty($trimmed)) continue;
				if (empty($buffer) && (0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#') || 0 === strpos($trimmed, '/*'))) continue;
				if (preg_match('/^(LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $trimmed)) continue;

				// Track current table and decide whether to skip
				if (preg_match('/^(?:DROP TABLE|CREATE TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`([^`]+)`/i', $trimmed, $m)) {
					$current_table = $m[1];
					$skipping_table = in_array($current_table, $skip_tables);
				}
				if (preg_match('/^INSERT\s+INTO\s+`([^`]+)`/i', $trimmed, $m)) {
					$current_table = $m[1];
					$skipping_table = in_array($current_table, $skip_tables);
				}
			}

			if ($skipping_table) {
				// Track string state even when skipping
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

			// Track string state
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

				// If SAVEQUERIES is on, keep wpdb's statement log from accumulating the
				// whole dump (each multi-MB INSERT would otherwise be retained in RAM).
				if (!empty($wpdb->queries)) $wpdb->queries = array();

				// Remap only the leading table-name token. Anchoring avoids running a
				// PCRE over the entire (possibly multi-MB) VALUES blob - which both
				// copies the whole string and can hit pcre.backtrack_limit and return
				// NULL, silently dropping the statement (data loss).
				$query = self::remap_leading_table_prefix($query, $source_prefix, $target_prefix);

				if (self::is_dangerous_sql($query)) {
					$result['errors'][] = "Blocked dangerous SQL on line {$line_count}";
					continue;
				}

				$dbh_before = $wpdb->dbh;
				$wpdb->check_connection();
				if ($wpdb->dbh !== $dbh_before) self::apply_import_session_state();

				// Split oversized INSERTs at row boundaries to stay under
				// max_allowed_packet and keep peak memory bounded.
				if (strlen($query) > ($max_packet - 1024) && preg_match('/^INSERT\s+INTO/i', $query)) {
					$insert_prefix = '';
					if (preg_match('/^(INSERT\s+INTO\s+`[^`]+`\s*(?:\([^)]+\))?\s*VALUES\s*)/is', $query, $pfx_m)) {
						$insert_prefix = $pfx_m[1];
					}
					if ($insert_prefix) {
						$values_part = substr($query, strlen($insert_prefix));
						unset($query);
						$chunks = self::split_insert_values($values_part, $max_packet - strlen($insert_prefix) - 1024);
						unset($values_part);
						foreach ($chunks as $chunk) {
							$res = $wpdb->query($insert_prefix . $chunk);
							if (false === $res) {
								$result['errors'][] = "SQL error (split INSERT) on line {$line_count}: " . $wpdb->last_error;
							}
						}
						unset($chunks);
						continue;
					}
				}

				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error on line {$line_count}: " . $wpdb->last_error;
				}

				if (preg_match('/^CREATE\s+TABLE/i', $query)) {
					$result['tables_restored']++;
				}
			}
		}

		if (!empty(trim($buffer))) {
			$query = trim(rtrim($buffer, ';'));
			if (!empty($query) && !$skipping_table) {
				$query = self::remap_leading_table_prefix($query, $source_prefix, $target_prefix);
				if (!self::is_dangerous_sql($query)) {
					$dbh_before = $wpdb->dbh;
					$wpdb->check_connection();
					if ($wpdb->dbh !== $dbh_before) self::apply_import_session_state();
					$res = $wpdb->query($query);
					if (false === $res) {
						$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
					}
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
	 * Import a SQL file with multi-prefix remapping (batch subsite import).
	 *
	 * Single-pass streaming: routes each SQL statement to the correct target
	 * prefix based on $prefix_map, skips main-site and unmapped tables entirely.
	 *
	 * @param string   $sql_file          Path to SQL file (.sql or .sql.gz).
	 * @param array    $prefix_map        Source prefix => target prefix, e.g. ['wp_2_' => 'wp_5_', 'wp_3_' => 'wp_7_'].
	 * @param string   $base_prefix       WordPress base prefix (e.g. 'wp_') for identifying global/main-site tables.
	 * @param callable $progress_callback Optional callback($lines_done) for progress reporting.
	 * @return array ['success' => bool, 'errors' => array, 'tables_restored' => int]
	 */
	public static function execute_sql_file_multi_remap($sql_file, $prefix_map, $base_prefix, $progress_callback = null) {
		global $wpdb;

		$result = array('success' => true, 'errors' => array(), 'tables_restored' => 0);

		$is_gz = (substr($sql_file, -3) === '.gz');
		$fh = $is_gz ? @gzopen($sql_file, 'rb') : @fopen($sql_file, 'r');
		if (!$fh) {
			$result['success'] = false;
			$result['errors'][] = "Failed to open SQL file: {$sql_file}";
			return $result;
		}

		// Build set of source prefixes we're importing
		$source_prefixes = array_keys($prefix_map);

		// Tables to always skip (global multisite tables)
		$global_skip = array(
			$base_prefix . 'users',
			$base_prefix . 'usermeta',
		);

		$buffer = '';
		$line_count = 0;
		$in_string = false;
		$string_char = '';
		$current_table = '';
		$current_action = ''; // 'remap', 'skip'
		$current_remap_src = '';
		$current_remap_dst = '';

		// max_allowed_packet for splitting oversized INSERT statements
		$max_packet = 16777216;
		$packet_row = $wpdb->get_row("SHOW VARIABLES LIKE 'max_allowed_packet'");
		if ($packet_row && isset($packet_row->Value) && (int) $packet_row->Value > 0) {
			$max_packet = (int) $packet_row->Value;
		}

		$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
		$wpdb->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

		while (!($is_gz ? gzeof($fh) : feof($fh))) {
			$line = $is_gz ? gzgets($fh) : fgets($fh);
			if (false === $line) break;

			$line_count++;
			$trimmed = trim($line);

			if (!$in_string) {
				if (empty($trimmed)) continue;
				if (empty($buffer) && (0 === strpos($trimmed, '--') || 0 === strpos($trimmed, '#') || 0 === strpos($trimmed, '/*'))) continue;
				if (preg_match('/^(LOCK\s+TABLES|UNLOCK\s+TABLES)/i', $trimmed)) continue;

				// Detect table name from DDL/DML
				$detected_table = '';
				if (preg_match('/^(?:DROP TABLE|CREATE TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`([^`]+)`/i', $trimmed, $m)) {
					$detected_table = $m[1];
				} elseif (preg_match('/^INSERT\s+INTO\s+`([^`]+)`/i', $trimmed, $m)) {
					$detected_table = $m[1];
				}

				if (!empty($detected_table)) {
					$current_table = $detected_table;
					$current_action = 'skip'; // default: skip unknown tables
					$current_remap_src = '';
					$current_remap_dst = '';

					// Check if this table belongs to a mapped source prefix
					foreach ($source_prefixes as $src_pfx) {
						if (strpos($current_table, $src_pfx) === 0) {
							$current_action = 'remap';
							$current_remap_src = $src_pfx;
							$current_remap_dst = $prefix_map[$src_pfx];
							break;
						}
					}

					// Also skip global tables even if they somehow match
					if (in_array($current_table, $global_skip, true)) {
						$current_action = 'skip';
					}
				}
			}

			// Track string state (even when skipping, to stay in sync)
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

			// Progress callback - fires for ALL lines (including skipped) so
			// callers see progress even when most of the file is being skipped.
			if ($progress_callback && $line_count % 5000 === 0) {
				call_user_func($progress_callback, $line_count, 0);
			}

			if ($current_action === 'skip') {
				continue;
			}

			$buffer .= $line;

			if (!$in_string && substr($trimmed, -1) === ';') {
				$query = trim(rtrim($buffer, ';'));
				$buffer = '';

				if (empty($query)) continue;

				// Keep wpdb's statement log bounded if SAVEQUERIES is on.
				if (!empty($wpdb->queries)) $wpdb->queries = array();

				// Remap table prefix (anchored to the leading table name only - see
				// remap_leading_table_prefix: avoids copying/backtracking the whole
				// multi-MB VALUES blob, which could silently drop the statement).
				if (!empty($current_remap_src) && !empty($current_remap_dst)) {
					$query = self::remap_leading_table_prefix($query, $current_remap_src, $current_remap_dst);
				}

				if (self::is_dangerous_sql($query)) {
					$result['errors'][] = "Blocked dangerous SQL on line {$line_count}";
					continue;
				}

				$dbh_before = $wpdb->dbh;
				$wpdb->check_connection();
				if ($wpdb->dbh !== $dbh_before) self::apply_import_session_state();

				// Split oversized INSERTs at row boundaries to stay under
				// max_allowed_packet and keep peak memory bounded.
				if (strlen($query) > ($max_packet - 1024) && preg_match('/^INSERT\s+INTO/i', $query)) {
					$insert_prefix = '';
					if (preg_match('/^(INSERT\s+INTO\s+`[^`]+`\s*(?:\([^)]+\))?\s*VALUES\s*)/is', $query, $pfx_m)) {
						$insert_prefix = $pfx_m[1];
					}
					if ($insert_prefix) {
						$values_part = substr($query, strlen($insert_prefix));
						unset($query);
						$chunks = self::split_insert_values($values_part, $max_packet - strlen($insert_prefix) - 1024);
						unset($values_part);
						foreach ($chunks as $chunk) {
							$res = $wpdb->query($insert_prefix . $chunk);
							if (false === $res) {
								$result['errors'][] = "SQL error (split INSERT) on line {$line_count}: " . $wpdb->last_error;
							}
						}
						unset($chunks);
						continue;
					}
				}

				$res = $wpdb->query($query);
				if (false === $res) {
					$result['errors'][] = "SQL error on line {$line_count}: " . $wpdb->last_error;
				}

				if (preg_match('/^CREATE\s+TABLE/i', $query)) {
					$result['tables_restored']++;
				}
			}
		}

		// Flush remaining buffer
		if (!empty(trim($buffer)) && $current_action !== 'skip') {
			$query = trim(rtrim($buffer, ';'));
			if (!empty($query)) {
				if (!empty($current_remap_src) && !empty($current_remap_dst)) {
					$query = self::remap_leading_table_prefix($query, $current_remap_src, $current_remap_dst);
				}
				if (!self::is_dangerous_sql($query)) {
					$dbh_before = $wpdb->dbh;
					$wpdb->check_connection();
					if ($wpdb->dbh !== $dbh_before) self::apply_import_session_state();
					$res = $wpdb->query($query);
					if (false === $res) {
						$result['errors'][] = "SQL error at end: " . $wpdb->last_error;
					}
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

	private static function remap_table_prefix_in_query($query, $source_prefix, $target_prefix) {
		// Replace backtick-quoted table names
		$escaped_source = preg_quote($source_prefix, '/');
		return preg_replace('/`' . $escaped_source . '([^`]+)`/', '`' . $target_prefix . '$1`', $query);
	}

	/**
	 * Remap the prefix of ONLY the leading table-name token of a statement
	 * (DROP/CREATE/INSERT/REPLACE/ALTER/TRUNCATE/LOCK ... `prefix_table`).
	 *
	 * Anchored at the start of the statement so it never scans the (potentially
	 * multi-MB) VALUES blob of a large INSERT. This avoids both the full-string
	 * copy and the catastrophic PCRE backtracking that the unanchored
	 * remap_table_prefix_in_query() pattern (`[^`]+`) can hit on big statements -
	 * which returns NULL and silently drops the statement. WordPress dumps carry
	 * the prefix only in the leading table name (no inter-table foreign keys), so
	 * anchoring is sufficient.
	 *
	 * @param string $query         A single SQL statement.
	 * @param string $source_prefix Prefix to replace (e.g. 'wp_39_').
	 * @param string $target_prefix Replacement prefix (e.g. 'wp_11_').
	 * @return string
	 */
	private static function remap_leading_table_prefix($query, $source_prefix, $target_prefix) {
		$escaped_source = preg_quote($source_prefix, '/');
		$out = preg_replace(
			'/^((?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|LOCK\s+TABLES)\s+`)' . $escaped_source . '/i',
			'${1}' . $target_prefix,
			$query,
			1
		);
		// preg_replace returns NULL on PCRE failure; never propagate that as the query.
		return (null === $out) ? $query : $out;
	}
}
