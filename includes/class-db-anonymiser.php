<?php
/**
 * SiteGuard Backup - Database Anonymiser
 *
 * Anonymises personal data in database backups to comply with privacy
 * requirements when sharing or storing backups in less-trusted environments.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_DB_Anonymiser {

	/** @var string Anonymisation mode: 'all', 'non_staff', 'wc_orders' */
	private $mode;

	/** @var int Current user ID to exclude from anonymisation */
	private $current_user_id;

	/** @var array Roles considered as staff (excluded in 'non_staff' mode) */
	private $staff_roles = array('administrator', 'editor', 'shop_manager');

	/** @var array Cached staff user IDs for 'non_staff' mode */
	private $staff_user_ids = null;

	/** @var string HMAC key for deterministic anonymization */
	private $hmac_key;

	/**
	 * @param string $mode            Anonymisation mode: 'all', 'non_staff', 'wc_orders'.
	 * @param int    $current_user_id Current user ID to always exclude.
	 */
	public function __construct($mode, $current_user_id = 0) {
		$this->mode = $mode;
		$this->current_user_id = $current_user_id;
		$this->hmac_key = defined('AUTH_SALT') ? AUTH_SALT : 'sgbackup-anon';
	}

	/**
	 * Determine whether a given row should be anonymised.
	 *
	 * @param string $table The table name.
	 * @param array  $row   The row data as an associative array.
	 * @return bool True if the row should be anonymised.
	 */
	public function should_anonymise_row($table, $row) {
		// Match by table-name SUFFIX rather than exact current-prefix name (H3).
		// Subsite tables (wp_5_*) and external-DB tables carry a different prefix,
		// so an exact `$wpdb->prefix . 'users'` comparison never matched them and
		// their PII was dumped verbatim.
		$suffix = $this->match_suffix($table);
		if ('' === $suffix) return false;

		// WooCommerce orders mode: only anonymise order-related tables.
		if ($this->mode === 'wc_orders') {
			if ($suffix === '_postmeta') {
				$meta_key = isset($row['meta_key']) ? $row['meta_key'] : '';
				return $this->is_wc_pii_meta_key($meta_key);
			}
			return in_array($suffix, array('_wc_orders', '_wc_order_addresses'), true);
		}

		switch ($suffix) {
			case '_users':
				$user_id = isset($row['ID']) ? intval($row['ID']) : 0;
				if ($user_id === $this->current_user_id) return false;
				if ($this->mode === 'non_staff') return !$this->is_staff_user($user_id);
				return true; // 'all' mode: everyone except current user

			case '_usermeta':
				$user_id = isset($row['user_id']) ? intval($row['user_id']) : 0;
				if ($user_id === $this->current_user_id) return false;
				if ($this->mode === 'non_staff') return !$this->is_staff_user($user_id);
				return true;

			case '_postmeta':
				$meta_key = isset($row['meta_key']) ? $row['meta_key'] : '';
				return $this->is_wc_pii_meta_key($meta_key);

			case '_wc_orders':
			case '_wc_order_addresses':
				return true;

			case '_comments':
				return $this->mode === 'all';

			default:
				return false;
		}
	}

	/**
	 * Match a (possibly prefixed / external) table name against the anonymisable
	 * table suffixes. Returns the matched suffix (e.g. '_postmeta') or '' if none.
	 */
	private function match_suffix($table) {
		// Check longest suffixes first so '_wc_order_addresses' is not shadowed by
		// '_wc_orders' etc.
		$suffixes = $this->get_anonymisable_tables();
		usort($suffixes, function($a, $b) { return strlen($b) - strlen($a); });
		foreach ($suffixes as $suffix) {
			$slen = strlen($suffix);
			if ($slen > 0 && strlen($table) >= $slen && substr($table, -$slen) === $suffix) {
				return $suffix;
			}
		}
		return '';
	}

	/**
	 * Anonymise a whole row in a table-aware way and return the modified row.
	 *
	 * Dispatching on column name alone is wrong for postmeta / wc_order_addresses
	 * where PII lives in a value column keyed by another column (H2). Callers must
	 * gate with should_anonymise_row() first.
	 *
	 * @param string $table Table name.
	 * @param array  $row   Row as an associative array.
	 * @return array Modified row.
	 */
	public function anonymise_row($table, $row) {
		$suffix = $this->match_suffix($table);

		switch ($suffix) {
			case '_postmeta':
				// PII sits in meta_value, addressed by meta_key.
				$meta_key = isset($row['meta_key']) ? $row['meta_key'] : '';
				if ($this->is_wc_pii_meta_key($meta_key) && isset($row['meta_value']) && $row['meta_value'] !== null) {
					$row['meta_value'] = $this->anonymise_value($meta_key, $row['meta_value']);
				}
				return $row;

			case '_wc_order_addresses':
				// HPOS address columns are bare (first_name, email, address_1, …).
				foreach (array('first_name','last_name','company','email','phone','address_1','address_2','city','state','postcode') as $c) {
					if (isset($row[$c]) && $row[$c] !== null && $row[$c] !== '') {
						$row[$c] = $this->anonymise_wc_address_field($c, $row[$c]);
					}
				}
				return $row;

			default:
				// users / usermeta / wc_orders / comments: dispatch per column name.
				foreach ($row as $col => $val) {
					if ($val !== null) {
						$row[$col] = $this->anonymise_value($col, $val);
					}
				}
				return $row;
		}
	}

	/**
	 * Anonymise a bare WooCommerce HPOS address column (wc_order_addresses).
	 */
	private function anonymise_wc_address_field($col, $value) {
		switch ($col) {
			case 'email':      return $this->hash_email($value);
			case 'phone':      return '555-' . str_pad(abs(crc32($value)) % 10000, 4, '0', STR_PAD_LEFT);
			case 'first_name':
			case 'last_name':  return 'Anon_' . $this->anon_hash($value, 6);
			case 'company':    return 'Anon Company';
			case 'address_1':  return '123 Anonymous St';
			case 'address_2':  return '';
			case 'city':       return 'Anytown';
			case 'state':      return 'XX';
			case 'postcode':   return '00000';
			default:           return $value;
		}
	}

	/**
	 * Anonymise a single field value.
	 *
	 * @param string $field The column/meta_key name.
	 * @param string $value The original value.
	 * @return string The anonymised value.
	 */
	public function anonymise_value($field, $value) {
		switch ($field) {
			// WordPress user fields
			case 'user_email':
				return $this->hash_email($value);
			case 'user_login':
				return 'user_' . $this->anon_hash($value, 8);
			case 'display_name':
			case 'user_nicename':
				return 'Anonymous ' . $this->anon_hash($value, 6);
			case 'user_pass':
				// Deterministic (no microtime) so re-running anonymisation over the
				// same backup is stable (L6). The value is an unusable placeholder hash.
				return '$P$BAnonymised' . $this->anon_hash($value, 22);
			case 'user_url':
				return '';

			// Comment fields
			case 'comment_author':
				return 'Anonymous ' . $this->anon_hash($value, 6);
			case 'comment_author_email':
				return $this->hash_email($value);
			case 'comment_author_url':
				return '';
			case 'comment_author_IP':
				return '0.0.0.0';

			// WooCommerce billing fields
			case '_billing_email':
			case 'billing_email':
				return $this->hash_email($value);
			case '_billing_phone':
			case 'billing_phone':
				return '555-' . str_pad(abs(crc32($value)) % 10000, 4, '0', STR_PAD_LEFT);
			case '_billing_first_name':
			case '_shipping_first_name':
			case 'billing_first_name':
			case 'shipping_first_name':
				return 'Anon_' . $this->anon_hash($value, 6);
			case '_billing_last_name':
			case '_shipping_last_name':
			case 'billing_last_name':
			case 'shipping_last_name':
				return 'Anon_' . $this->anon_hash($value, 6);
			case '_billing_company':
			case '_shipping_company':
			case 'billing_company':
			case 'shipping_company':
				return 'Anon Company';
			case '_billing_address_1':
			case '_shipping_address_1':
			case 'billing_address_1':
			case 'shipping_address_1':
				return '123 Anonymous St';
			case '_billing_address_2':
			case '_shipping_address_2':
			case 'billing_address_2':
			case 'shipping_address_2':
				return '';
			case '_billing_city':
			case '_shipping_city':
			case 'billing_city':
			case 'shipping_city':
				return 'Anytown';
			case '_billing_postcode':
			case '_shipping_postcode':
			case 'billing_postcode':
			case 'shipping_postcode':
				return '00000';
			case '_billing_state':
			case '_shipping_state':
			case 'billing_state':
			case 'shipping_state':
				return 'XX';

			default:
				return $value;
		}
	}

	/**
	 * Get tables that contain anonymisable fields.
	 *
	 * @return array List of table name suffixes that may contain PII.
	 */
	public function get_anonymisable_tables() {
		return array(
			'_users',
			'_usermeta',
			'_comments',
			'_commentmeta',
			'_postmeta',
			'_wc_orders',
			'_wc_order_addresses',
		);
	}

	/**
	 * Check if a table is a WooCommerce table that should be anonymised.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function is_wc_anonymisable_table($table) {
		global $wpdb;
		$wc_tables = array(
			$wpdb->prefix . 'postmeta',
			$wpdb->prefix . 'wc_orders',
			$wpdb->prefix . 'wc_order_addresses',
		);
		return in_array($table, $wc_tables, true);
	}

	/**
	 * Check if a meta key is WooCommerce PII.
	 *
	 * @param string $meta_key The meta key.
	 * @return bool
	 */
	private function is_wc_pii_meta_key($meta_key) {
		$pii_keys = array(
			'_billing_first_name', '_billing_last_name', '_billing_company',
			'_billing_address_1', '_billing_address_2', '_billing_city',
			'_billing_state', '_billing_postcode', '_billing_email', '_billing_phone',
			'_shipping_first_name', '_shipping_last_name', '_shipping_company',
			'_shipping_address_1', '_shipping_address_2', '_shipping_city',
			'_shipping_state', '_shipping_postcode',
		);
		return in_array($meta_key, $pii_keys, true);
	}

	/**
	 * Check if a user ID belongs to a staff role.
	 *
	 * @param int $user_id The user ID to check.
	 * @return bool
	 */
	private function is_staff_user($user_id) {
		if ($this->staff_user_ids === null) {
			$this->staff_user_ids = array();
			foreach ($this->staff_roles as $role) {
				$users = get_users(array('role' => $role, 'fields' => 'ID'));
				if (!empty($users)) {
					$this->staff_user_ids = array_merge($this->staff_user_ids, array_map('intval', $users));
				}
			}
			$this->staff_user_ids = array_unique($this->staff_user_ids);
		}

		return in_array($user_id, $this->staff_user_ids, true);
	}

	/**
	 * Generate a deterministic HMAC-based hash for anonymization.
	 *
	 * @param string $value  The value to hash.
	 * @param int    $length Number of hex characters to return (default 12).
	 * @return string Truncated HMAC-SHA256 hex digest.
	 */
	private function anon_hash($value, $length = 12) {
		return substr(hash_hmac('sha256', $value, $this->hmac_key), 0, $length);
	}

	/**
	 * Generate a deterministic anonymised email.
	 *
	 * @param string $email The original email.
	 * @return string The anonymised email.
	 */
	private function hash_email($email) {
		return $this->anon_hash($email) . '@example.com';
	}
}
