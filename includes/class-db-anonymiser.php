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
		global $wpdb;

		// WooCommerce orders mode: only anonymise order meta and WC-specific tables
		if ($this->mode === 'wc_orders') {
			return $this->is_wc_anonymisable_table($table);
		}

		// Users table
		if ($table === $wpdb->prefix . 'users') {
			$user_id = isset($row['ID']) ? intval($row['ID']) : 0;

			// Never anonymise the current user
			if ($user_id === $this->current_user_id) {
				return false;
			}

			if ($this->mode === 'non_staff') {
				return !$this->is_staff_user($user_id);
			}

			// 'all' mode: anonymise everyone except current user
			return true;
		}

		// User meta table
		if ($table === $wpdb->prefix . 'usermeta') {
			$user_id = isset($row['user_id']) ? intval($row['user_id']) : 0;

			if ($user_id === $this->current_user_id) {
				return false;
			}

			if ($this->mode === 'non_staff') {
				return !$this->is_staff_user($user_id);
			}

			return true;
		}

		// WooCommerce order meta (postmeta for shop_order posts)
		if ($table === $wpdb->prefix . 'postmeta') {
			$meta_key = isset($row['meta_key']) ? $row['meta_key'] : '';
			return $this->is_wc_pii_meta_key($meta_key);
		}

		// WooCommerce HPOS orders table
		if ($table === $wpdb->prefix . 'wc_orders') {
			return true;
		}

		// WooCommerce HPOS order addresses
		if ($table === $wpdb->prefix . 'wc_order_addresses') {
			return true;
		}

		// Comments table (anonymise comment author info)
		if ($table === $wpdb->prefix . 'comments') {
			if ($this->mode === 'all') {
				return true;
			}
		}

		return false;
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
				return '$P$BAnonymised' . $this->anon_hash($value . microtime(), 22);
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
