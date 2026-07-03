<?php
/**
 * SiteGuard Backup - Serialization-Aware Search & Replace
 *
 * Based on the interconnectit/Search-Replace-DB pattern.
 * Handles nested serialized data, JSON strings, and objects.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Search_Replace {

	/** @var int Maximum recursion depth. Memory is bounded by data size, not depth,
	 *  so this is set generously - deeply nested Elementor/serialized structures that
	 *  hit the old cap of 20 were silently left unreplaced (M8). */
	private $max_recursion = 50;

	/** @var int Page size for batched processing (kept conservative for tables
	 *  with wide rows like wp_posts with page builder content on memory-limited hosts) */
	private $page_size = 1000;

	/** @var array Report statistics */
	private $report;

	/** @var bool Dry run mode - count changes without writing */
	private $dry_run = false;

	/** @var array Object hash tracking for circular reference detection */
	private $object_hashes = array();

	/**
	 * Run search-replace on specified tables.
	 *
	 * @param array $search  Array of search strings.
	 * @param array $replace Array of replacement strings (same indices as $search).
	 * @param array $tables  Table names to process.
	 * @return array Report: ['tables' => int, 'rows' => int, 'changes' => int, 'updates' => int, 'errors' => array]
	 */
	public function run($search, $replace, $tables, $dry_run = false, $options = array()) {
		global $wpdb;

		// Resumption/deadline options (all optional - a bare run() with no options
		// keeps the original single blocking pass, which is what WP-CLI wants):
		//   'deadline'     => epoch seconds; when reached, stop between/within tables,
		//                     persist progress, and return status 'incomplete' so the
		//                     caller can reschedule (fixes the migration-hang: a single
		//                     un-resumable pass over serialization-aware Elementor JSON
		//                     that outlives the request/proxy timeout and restarts from
		//                     scratch forever - H4).
		//   'resume'       => bool; load the saved cursor and skip already-completed
		//                     tables from a prior partial pass.
		//   'progress_key' => option name used to persist the cursor.
		//   'progress_cb'  => callable($done, $total, $table); invoked after each
		//                     table completes so a streaming caller can emit a
		//                     keepalive line and progress, since search-replace is
		//                     otherwise silent for the whole pass.
		$defaults = array(
			'deadline'     => null,
			'resume'       => false,
			'progress_key' => 'sgbackup_sr_progress',
			'progress_cb'  => null,
		);
		$options = array_merge($defaults, is_array($options) ? $options : array());
		$total_tables = is_array($tables) ? count($tables) : 0;
		$done_tables = 0;

		$this->dry_run = $dry_run;
		$this->report = array(
			'tables'           => 0,
			'rows'             => 0,
			'changes'          => 0,
			'updates'          => 0,
			'errors'           => array(),
			'warnings'         => array(),
			'dry_run'          => $dry_run,
			'status'           => 'complete',   // 'complete' | 'incomplete'
			'completed_tables' => array(),
		);

		// Accept strings as well as arrays
		if (is_string($search)) $search = array($search);
		if (is_string($replace)) $replace = array($replace);

		if (empty($search) || empty($replace) || count($search) !== count($replace)) {
			$this->report['errors'][] = 'Search and replace arrays must be non-empty and same length';
			return $this->report;
		}

		if (!is_array($tables)) {
			$this->report['errors'][] = 'Tables argument must be an array';
			return $this->report;
		}

		// Load resume cursor: the set of tables already fully processed.
		$completed = array();
		if ($options['resume']) {
			$saved = get_option($options['progress_key']);
			if (is_array($saved) && !empty($saved['completed_tables']) && is_array($saved['completed_tables'])) {
				$completed = array_flip($saved['completed_tables']);
			}
		}
		$done_tables = count($completed);

		foreach ($tables as $table) {
			if (isset($completed[$table])) {
				continue; // done in an earlier pass
			}

			// Stop cleanly if we're already out of time before starting a new table.
			if ($options['deadline'] !== null && time() >= $options['deadline']) {
				$this->report['status'] = 'incomplete';
				$this->report['next_table'] = $table;
				$this->persist_progress($options['progress_key'], $completed);
				return $this->report;
			}

			// process_table returns false if it hit the deadline mid-table. That table
			// is NOT marked complete, so a resumed pass restarts it from the top -
			// which is safe because search-replace is idempotent (already-rewritten
			// rows no longer contain the search needle and are skipped on the fast path).
			$finished = $this->process_table($table, $search, $replace, $options['deadline']);

			if (!$finished) {
				$this->report['status'] = 'incomplete';
				$this->report['next_table'] = $table;
				$this->persist_progress($options['progress_key'], $completed);
				return $this->report;
			}

			$completed[$table] = true;
			$this->report['completed_tables'][] = $table;
			$this->persist_progress($options['progress_key'], $completed);
			$done_tables++;

			// Let a streaming caller emit a keepalive + progress line. Without this
			// search-replace produces no output for the whole pass, so a proxy sees
			// an idle connection and times out mid-run.
			if (is_callable($options['progress_cb'])) {
				call_user_func($options['progress_cb'], $done_tables, $total_tables, $table);
			}

			// Heartbeat so the job-scheduler watchdog doesn't kill a legitimately
			// progressing restore.
			if (class_exists('SiteGuard_Backup_Job_Scheduler')) {
				if (method_exists('SiteGuard_Backup_Job_Scheduler', 'record_still_alive')) {
					SiteGuard_Backup_Job_Scheduler::record_still_alive();
				}
				if (method_exists('SiteGuard_Backup_Job_Scheduler', 'something_useful_happened')) {
					SiteGuard_Backup_Job_Scheduler::something_useful_happened();
				}
			}
		}

		// Fully complete - clear the persisted cursor.
		$this->report['status'] = 'complete';
		$this->clear_progress($options['progress_key']);

		return $this->report;
	}

	/**
	 * Persist the resume cursor (set of completed tables) so an interrupted
	 * search-replace can be resumed on the next scheduled run.
	 */
	private function persist_progress($key, $completed) {
		if ($this->dry_run) return;
		update_option($key, array(
			'completed_tables' => array_keys($completed),
			'updated'          => time(),
		), false);
	}

	/**
	 * Clear the persisted resume cursor after a fully-completed pass.
	 */
	private function clear_progress($key) {
		if ($this->dry_run) return;
		delete_option($key);
	}

	/**
	 * Process a single table.
	 *
	 * @param string   $table    Table name.
	 * @param array    $search   Search strings.
	 * @param array    $replace  Replacement strings.
	 * @param int|null $deadline Epoch seconds; stop and return false if exceeded.
	 * @return bool True if the whole table was processed, false if it stopped early
	 *              due to the deadline (table NOT complete - safe to restart).
	 */
	private function process_table($table, $search, $replace, $deadline = null) {
		global $wpdb;

		// Sanitize table name and escape backticks for SQL interpolation
		$table = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
		$escaped_table = str_replace('`', '``', $table);

		// Get columns
		$columns = $wpdb->get_results("DESCRIBE `{$escaped_table}`", ARRAY_A);
		if (empty($columns)) return true;

		// Collect ALL primary-key columns (composite PKs need every one) and detect
		// binary columns to skip.
		$pk_cols = array();
		$col_names = array();
		$binary_cols = array();
		foreach ($columns as $col) {
			$col_names[] = $col['Field'];
			if ($col['Key'] === 'PRI') {
				$pk_cols[] = $col['Field'];
			}
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}

		if (empty($pk_cols)) {
			// No primary key - skip this table (can't do reliable per-row updates)
			$this->report['skipped_no_pk'][] = $table;
			return true;
		}

		$this->report['tables']++;

		$single_pk = (count($pk_cols) === 1) ? $pk_cols[0] : null;

		// Build the column list to fetch: skip binary/blob columns (never replaced)
		// so their bytes never enter PHP memory. Always keep the primary-key columns.
		$fetch_cols = array();
		foreach ($col_names as $c) {
			if (isset($binary_cols[$c]) && !in_array($c, $pk_cols, true)) continue;
			$fetch_cols[] = '`' . str_replace('`', '``', $c) . '`';
		}
		$select_list = implode(', ', $fetch_cols);

		// Deterministic ORDER BY over the full primary key. The previous LIMIT/OFFSET
		// with NO ORDER BY (H5) let MySQL return rows in an unstable order, so rows were
		// skipped or processed twice across pages.
		$order_by = implode(', ', array_map(function($c) {
			return '`' . str_replace('`', '``', $c) . '` ASC';
		}, $pk_cols));

		// Adaptive paging. A fixed 1000-row page balloons on page-builder tables where a
		// single Elementor JSON/serialized blob runs 1-2MB. Start at the configured size
		// and halve when process memory crosses ~70% of the limit.
		$page_size = $this->page_size;
		$mem_limit = $this->get_memory_limit_bytes();

		$offset = 0;
		$have_last = false;
		$last_pk = null;
		$esc_single = ($single_pk !== null) ? str_replace('`', '``', $single_pk) : '';

		while (true) {
			if ($single_pk !== null) {
				// Keyset pagination on the single-column PK: stable and O(n).
				if ($have_last) {
					$rows = $wpdb->get_results($wpdb->prepare(
						"SELECT {$select_list} FROM `{$escaped_table}` WHERE `{$esc_single}` > %s ORDER BY `{$esc_single}` ASC LIMIT %d",
						$last_pk, $page_size
					), ARRAY_A);
				} else {
					$rows = $wpdb->get_results($wpdb->prepare(
						"SELECT {$select_list} FROM `{$escaped_table}` ORDER BY `{$esc_single}` ASC LIMIT %d",
						$page_size
					), ARRAY_A);
				}
			} else {
				// Composite PK: full ORDER BY with OFFSET (accept O(n^2) for correctness).
				$rows = $wpdb->get_results($wpdb->prepare(
					"SELECT {$select_list} FROM `{$escaped_table}` ORDER BY {$order_by} LIMIT %d OFFSET %d",
					$page_size, $offset
				), ARRAY_A);
			}

			if (empty($rows)) break;
			$fetched = count($rows);

			foreach ($rows as $row) {
				$this->process_row($table, $row, $search, $replace, $pk_cols, $col_names, $binary_cols);
				if ($single_pk !== null && isset($row[$single_pk])) {
					$last_pk = $row[$single_pk];
				}
			}

			$offset += $fetched;
			$have_last = true;

			// Release the page and wpdb's internal result copy before fetching the next.
			unset($rows);
			$wpdb->flush();
			// If SAVEQUERIES is enabled, wpdb appends every statement to an in-memory
			// log that would otherwise hold the entire restore in RAM. Drop it.
			if (!empty($wpdb->queries)) $wpdb->queries = array();

			// Watchdog heartbeat so a long table doesn't look stalled.
			if (class_exists('SiteGuard_Backup_Job_Scheduler') && method_exists('SiteGuard_Backup_Job_Scheduler', 'record_still_alive')) {
				SiteGuard_Backup_Job_Scheduler::record_still_alive();
			}

			// Deadline check between pages - bail without marking the table complete.
			if ($deadline !== null && time() >= $deadline) {
				return false;
			}

			if ($fetched < $page_size) break;

			// Throttle: shrink the page (and collect cycles) when memory runs high.
			if ($mem_limit > 0 && memory_get_usage(true) > 0.7 * $mem_limit) {
				$page_size = max(20, (int) floor($page_size / 2));
				gc_collect_cycles();
			}
		}

		return true;
	}

	/**
	 * Resolve the PHP memory_limit to bytes. Returns 0 when unlimited (-1).
	 */
	private function get_memory_limit_bytes() {
		$limit = trim((string) ini_get('memory_limit'));
		if ('' === $limit || '-1' === $limit) return 0;
		$unit = strtolower(substr($limit, -1));
		$value = (int) $limit;
		switch ($unit) {
			case 'g': $value *= 1024;
			case 'm': $value *= 1024;
			case 'k': $value *= 1024;
		}
		return $value;
	}

	/**
	 * Process a single row.
	 */
	private function process_row($table, $row, $search, $replace, $pk_cols, $col_names, $binary_cols = array()) {
		global $wpdb;

		$this->report['rows']++;
		$this->object_hashes = array(); // Reset circular ref tracking per row

		// Escape backticks in table name for SQL interpolation
		$escaped_table = str_replace('`', '``', $table);
		$updates = array();
		// Suffix-aware posts detection: under network context the table is e.g.
		// 'wp_5_posts', and stripping only $wpdb->prefix ('wp_') leaves '5_posts',
		// so the guid skip misfired (L4). Match the 'posts' suffix directly.
		$is_posts_table = (bool) preg_match('/(^|_)posts$/', $table);
		$pk_lookup = array_flip($pk_cols);

		foreach ($col_names as $col) {
			// Binary/blob columns are not fetched (process_table omits them), so they
			// won't be present in $row. Skip anything not selected.
			if (!array_key_exists($col, $row)) continue;

			$old_value = $row[$col];

			// Skip NULL values
			if (is_null($old_value)) continue;

			// Skip empty strings
			if ('' === $old_value) continue;

			// Skip primary key columns (all of them, for composite PKs)
			if (isset($pk_lookup[$col])) continue;

			// Skip GUID column in posts table (WordPress best practice)
			if ($is_posts_table && $col === 'guid') continue;

			// Skip binary/blob columns
			if (isset($binary_cols[$col])) continue;

			// Perform search-replace
			$new_value = $old_value;
			for ($i = 0; $i < count($search); $i++) {
				$new_value = $this->recursive_unserialize_replace($search[$i], $replace[$i], $new_value);
			}

			if ($new_value !== $old_value) {
				$updates[$col] = $new_value;
				$this->report['changes']++;
			}
		}

		if (!empty($updates)) {
			if ($this->dry_run) {
				// In dry-run mode, count what would change without writing
				$this->report['updates']++;
			} else {
				// Use wpdb::update() with raw values. DO NOT pre-escape %.
				//
				// History: earlier versions did str_replace('%', '%%', $v) here
				// on the theory that wpdb::prepare() would interpret % in value
				// arguments as format specifiers. It does not - vsprintf leaves
				// % in the substituted value verbatim, and prepare()'s
				// placeholder-escape round-trip preserves it. Pre-escaping here
				// caused every % in every touched cell to double on write
				// (`100%` → `100%%`, `/%postname%/` → `/%%postname%%/`,
				// Yoast `%%sep%%` → `%%%%sep%%%%`). Entire tables of CSS
				// percentages, permalinks, and Yoast title formats were corrupted
				// at once. See 2026-04-16 postmortem for blast radius.
				// Build the WHERE from EVERY primary-key column. A single-column WHERE
				// on a composite-PK table matches sibling rows and overwrites them (H6).
				$where = array();
				$where_ok = true;
				foreach ($pk_cols as $pk) {
					if (!array_key_exists($pk, $row)) { $where_ok = false; break; }
					$where[$pk] = $row[$pk];
				}
				if (!$where_ok) {
					// Cannot uniquely address this row - skip rather than risk corruption.
					$this->report['skipped_no_pk'][] = $table . ' (row missing PK value)';
				} else {
					$result = $wpdb->update($table, $updates, $where);

					if (false === $result) {
						$pk_desc = array();
						foreach ($where as $k => $v) { $pk_desc[] = $k . '=' . $v; }
						$this->report['errors'][] = "Error updating {$table} [" . implode(',', $pk_desc) . "]: " . $wpdb->last_error;
					} else {
						$this->report['updates']++;
					}
				}
			}
		}
	}

	/**
	 * Recursively search-replace in a value, handling serialized data.
	 *
	 * @param string $from            Search string.
	 * @param string $to              Replace string.
	 * @param mixed  $data            Data to process.
	 * @param bool   $serialised      Whether the data was originally serialized.
	 * @param int    $recursion_level Current recursion depth.
	 * @return mixed Processed data.
	 */
	private function recursive_unserialize_replace($from, $to, $data, $serialised = false, $recursion_level = 0) {
		if ($recursion_level > $this->max_recursion) {
			// Surface the fact that deeply-nested data was left unreplaced instead of
			// silently returning it (M8).
			if (!empty($this->report)) {
				$this->report['warnings'][] = "Recursion depth {$recursion_level} exceeded max ({$this->max_recursion}); deep data left unreplaced";
				$this->report['depth_exceeded'] = true;
			}
			return $data;
		}

		try {
			if (is_string($data)) {
				// Fast path: if the search term cannot occur anywhere in this string -
				// neither verbatim nor JSON slash-escaped ("https:\/\/old") - there is
				// nothing to replace. Skipping here avoids the expensive
				// unserialize/json_decode -> recurse -> re-encode round-trip for the
				// overwhelming majority of (often multi-MB) page-builder blobs that do
				// not reference the old domain at all. Serialized and plain strings store
				// values verbatim; JSON only escapes forward slashes for our URL needles,
				// so checking both forms is sufficient and safe.
				if ('' !== $from && false === strpos($data, $from)) {
					$from_json = str_replace('/', '\\/', $from);
					if ($from_json === $from || false === strpos($data, $from_json)) {
						return $data;
					}
				}

				// Check if it's serialized
				if ($this->is_serialized($data)) {
					// Object-injection guard (M7): never instantiate classes from
					// untrusted serialized data.
					if (PHP_VERSION_ID >= 70000) {
						$unserialized = @unserialize($data, array('allowed_classes' => false));
					} else {
						$unserialized = @unserialize($data);
					}
					if (false !== $unserialized || $data === 'b:0;') {
						// With class instantiation disabled, any `O:` blob comes back as
						// __PHP_Incomplete_Class, which cannot be re-serialized without
						// corrupting it. Leave such blobs untouched (and flag it) rather
						// than mangle legitimate serialized objects. In practice WP data
						// is overwhelmingly serialized arrays/scalars, which are safe.
						if ($this->contains_incomplete_class($unserialized)) {
							if (!empty($this->report)) {
								$this->report['warnings'][] = 'Skipped replace inside serialized object (object-injection guard)';
								$this->report['skipped_serialized_object'] = true;
							}
							return $data;
						}
						$replaced = $this->recursive_unserialize_replace($from, $to, $unserialized, true, $recursion_level + 1);
						$data = serialize($replaced);
						return $data;
					}
				}

				// Check if it's JSON. Decode exactly once (validate + capture value
				// together) instead of validating in is_json() then re-decoding (H5).
				$ltrimmed = ltrim($data);
				if ('' !== $ltrimmed && ('{' === $ltrimmed[0] || '[' === $ltrimmed[0])) {
					$decoded = json_decode($data, true);
					if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
						$replaced = $this->recursive_unserialize_replace($from, $to, $decoded, false, $recursion_level + 1);
						$re_encoded = json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
						if (false !== $re_encoded) {
							$data = $re_encoded;
						}
						return $data;
					}
				}

				// Plain string replacement - always case-sensitive
				// Case-insensitive replacement can corrupt serialized string lengths
				// and cause data integrity issues
				$data = str_replace($from, $to, $data);

			} elseif (is_array($data)) {
				$new_data = array();
				foreach ($data as $key => $value) {
					// Process both key and value
					$new_key = $this->recursive_unserialize_replace($from, $to, $key, false, $recursion_level + 1);
					$new_data[$new_key] = $this->recursive_unserialize_replace($from, $to, $value, false, $recursion_level + 1);
				}
				$data = $new_data;

			} elseif (is_object($data)) {
				// Circular reference detection via object hash
				$hash = spl_object_hash($data);
				if (isset($this->object_hashes[$hash])) {
					return $data; // Already processing this object
				}
				$this->object_hashes[$hash] = true;

				$props = get_object_vars($data);
				$new_data = clone $data;
				foreach ($props as $key => $value) {
					$new_data->$key = $this->recursive_unserialize_replace($from, $to, $value, false, $recursion_level + 1);
				}

				unset($this->object_hashes[$hash]);
				$data = $new_data;
			}
		} catch (\Exception $e) {
			// Return data unchanged if processing fails
			if (!empty($this->report)) {
				$this->report['errors'][] = "Search-replace exception at depth {$recursion_level}: " . $e->getMessage();
			}
		} catch (\Error $e) {
			// Catch PHP 7+ fatal errors (e.g., unserialize failures)
			if (!empty($this->report)) {
				$this->report['errors'][] = "Search-replace error at depth {$recursion_level}: " . $e->getMessage();
			}
		}

		return $data;
	}

	/**
	 * Check if a string is serialized.
	 */
	private function is_serialized($data) {
		if (!is_string($data)) return false;
		$data = trim($data);
		if (empty($data)) return false;

		if ('N;' === $data) return true;
		if ('b:0;' === $data || 'b:1;' === $data) return true;

		$first = $data[0];
		$last = substr($data, -1);

		if ('s:' === substr($data, 0, 2) && $last === ';') return true;
		if ('a:' === substr($data, 0, 2) && $last === '}') return true;
		if ('O:' === substr($data, 0, 2) && $last === '}') return true;
		if ('i:' === substr($data, 0, 2) && $last === ';') return true;
		if ('d:' === substr($data, 0, 2) && $last === ';') return true;

		return false;
	}

	/**
	 * Check if a string is valid JSON (object or array).
	 */
	private function is_json($data) {
		if (!is_string($data)) return false;
		$first = substr(ltrim($data), 0, 1);
		if ('{' !== $first && '[' !== $first) return false;
		@json_decode($data);
		return (json_last_error() === JSON_ERROR_NONE);
	}

	/**
	 * Check if a string looks like a URL.
	 */
	private function is_url($str) {
		return (bool) preg_match('#^https?://#i', $str);
	}

	/**
	 * Detect whether an unserialized structure contains a __PHP_Incomplete_Class
	 * (produced when unserialize() runs with allowed_classes=>false over an object).
	 * Such structures cannot be safely re-serialized, so callers leave them untouched.
	 *
	 * @param mixed $data  Value to inspect.
	 * @param int   $depth Guard against pathological nesting.
	 * @return bool
	 */
	private function contains_incomplete_class($data, $depth = 0) {
		if ($depth > $this->max_recursion) return false;
		if ($data instanceof \__PHP_Incomplete_Class) return true;
		if (is_array($data)) {
			foreach ($data as $v) {
				if ($this->contains_incomplete_class($v, $depth + 1)) return true;
			}
		} elseif (is_object($data)) {
			foreach (get_object_vars($data) as $v) {
				if ($this->contains_incomplete_class($v, $depth + 1)) return true;
			}
		}
		return false;
	}
}
