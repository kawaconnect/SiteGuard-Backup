<?php
/**
 * SiteGuard Backup - Serialization-Aware Search & Replace
 *
 * Based on the interconnectit/Search-Replace-DB pattern.
 * Handles nested serialized data, JSON strings, and objects.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Search_Replace {

	/** @var int Maximum recursion depth */
	private $max_recursion = 20;

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
	public function run($search, $replace, $tables, $dry_run = false) {
		global $wpdb;

		$this->dry_run = $dry_run;
		$this->report = array(
			'tables'  => 0,
			'rows'    => 0,
			'changes' => 0,
			'updates' => 0,
			'errors'  => array(),
			'dry_run' => $dry_run,
		);

		// Accept strings as well as arrays
		if (is_string($search)) $search = array($search);
		if (is_string($replace)) $replace = array($replace);

		if (empty($search) || empty($replace) || count($search) !== count($replace)) {
			$this->report['errors'][] = 'Search and replace arrays must be non-empty and same length';
			return $this->report;
		}

		foreach ($tables as $table) {
			$this->process_table($table, $search, $replace);
		}

		return $this->report;
	}

	/**
	 * Process a single table.
	 */
	private function process_table($table, $search, $replace) {
		global $wpdb;

		// Sanitize table name and escape backticks for SQL interpolation
		$table = SiteGuard_Backup_Database_Utility::sanitize_table_name($table);
		$escaped_table = str_replace('`', '``', $table);

		// Get columns
		$columns = $wpdb->get_results("DESCRIBE `{$escaped_table}`", ARRAY_A);
		if (empty($columns)) return;

		// Find primary key and detect binary columns to skip
		$primary_key = null;
		$col_names = array();
		$binary_cols = array();
		foreach ($columns as $col) {
			$col_names[] = $col['Field'];
			if ($col['Key'] === 'PRI') {
				$primary_key = $col['Field'];
			}
			$type = strtolower($col['Type']);
			if (preg_match('/binary|blob|bit/i', $type)) {
				$binary_cols[$col['Field']] = true;
			}
		}

		if (!$primary_key) {
			// No primary key - skip this table (can't do reliable updates)
			$this->report['skipped_no_pk'][] = $table;
			return;
		}

		$this->report['tables']++;

		// Process in pages
		$offset = 0;
		$has_more = true;

		while ($has_more) {
			$rows = $wpdb->get_results(
				$wpdb->prepare("SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d", $this->page_size, $offset),
				ARRAY_A
			);

			if (empty($rows)) break;
			if (count($rows) < $this->page_size) $has_more = false;

			foreach ($rows as $row) {
				$this->process_row($table, $row, $search, $replace, $primary_key, $col_names, $binary_cols);
			}

			$offset += $this->page_size;
		}
	}

	/**
	 * Process a single row.
	 */
	private function process_row($table, $row, $search, $replace, $primary_key, $col_names, $binary_cols = array()) {
		global $wpdb;

		$this->report['rows']++;
		$this->object_hashes = array(); // Reset circular ref tracking per row

		// Escape backticks in table name for SQL interpolation
		$escaped_table = str_replace('`', '``', $table);
		$updates = array();
		$stripped_table = preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $table);

		foreach ($col_names as $col) {
			$old_value = $row[$col];

			// Skip NULL values
			if (is_null($old_value)) continue;

			// Skip empty strings
			if ('' === $old_value) continue;

			// Skip primary key column
			if ($col === $primary_key) continue;

			// Skip GUID column in posts table (WordPress best practice)
			if ($stripped_table === 'posts' && $col === 'guid') continue;

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
				$set_clauses = array();
				$values = array();

				foreach ($updates as $col => $val) {
					$escaped_col = str_replace('`', '``', $col);
					$set_clauses[] = "`{$escaped_col}` = %s";
					$values[] = $val;
				}

				$values[] = $row[$primary_key];
				$escaped_pk = str_replace('`', '``', $primary_key);
				$sql = "UPDATE `{$escaped_table}` SET " . implode(', ', $set_clauses) . " WHERE `{$escaped_pk}` = %s LIMIT 1";

				$result = $wpdb->query($wpdb->prepare($sql, $values));

				if (false === $result) {
					$this->report['errors'][] = "Error updating {$table}.{$primary_key}={$row[$primary_key]}: " . $wpdb->last_error;
				} else {
					$this->report['updates']++;
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
			return $data;
		}

		try {
			if (is_string($data)) {
				// Check if it's serialized
				if ($this->is_serialized($data)) {
					if (PHP_VERSION_ID >= 70000) {
						$unserialized = @unserialize($data, array('allowed_classes' => true));
					} else {
						$unserialized = @unserialize($data);
					}
					if (false !== $unserialized || $data === 'b:0;') {
						$replaced = $this->recursive_unserialize_replace($from, $to, $unserialized, true, $recursion_level + 1);
						$data = serialize($replaced);
						return $data;
					}
				}

				// Check if it's JSON
				if ($this->is_json($data)) {
					$decoded = @json_decode($data, true);
					if (is_array($decoded)) {
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
}
