<?php
/**
 * SiteGuard Backup - Security Hardening
 *
 * Provides credential encryption at rest (AES-256-GCM using AUTH_KEY)
 * and IP allowlist enforcement with CIDR support.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Security {

	/** @var string Prefix for encrypted option values */
	const ENCRYPTED_PREFIX = 'sgenc:';

	/**
	 * Credential field names per storage method that should be encrypted at rest.
	 *
	 * @var array
	 */
	private static $sensitive_fields = array(
		's3'         => array('secret_key'),
		's3generic'  => array('secret_key'),
		'azure'      => array('account_key'),
		'googledrive' => array('client_secret', 'token', 'access_token', 'refresh_token'),
		'dropbox'    => array('app_secret', 'token', 'access_token', 'refresh_token'),
		'onedrive'   => array('client_secret', 'token', 'access_token', 'refresh_token'),
		'ftp'        => array('pass'),
		'sftp'       => array('pass', 'key'),
		'webdav'     => array('pass'),
		'pcloud'     => array('access_token'),
		'gcs'        => array('secret_key'),
		'openstack'  => array('password', 'api_key'),
	);

	/**
	 * Encrypt a plaintext value using AES-256-GCM.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Encrypted value with sgenc: prefix, or original if encryption unavailable.
	 */
	public static function encrypt_option($plaintext) {
		if (empty($plaintext) || !is_string($plaintext)) {
			return $plaintext;
		}

		// Don't double-encrypt
		if (strpos($plaintext, self::ENCRYPTED_PREFIX) === 0) {
			return $plaintext;
		}

		if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY')) {
			// Storing plaintext because we cannot derive an encryption key.
			// Surface this rather than silently persisting a secret in the clear.
			if (!defined('AUTH_KEY') && class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log('Warning: storage credentials cannot be encrypted at rest because AUTH_KEY is not defined in wp-config.php. They will be stored in plaintext.', 'warning');
			}
			return $plaintext;
		}

		$key = hash('sha256', AUTH_KEY . 'sgbackup_credential_encryption', true);
		$iv = openssl_random_pseudo_bytes(12);
		if (false === $iv) {
			return $plaintext;
		}

		$tag = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if (false === $ciphertext) {
			return $plaintext;
		}

		return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
	}

	/**
	 * Decrypt a value encrypted with encrypt_option().
	 *
	 * @param string $encrypted The encrypted value (with sgenc: prefix).
	 * @return string Decrypted plaintext, or original value if not encrypted or decryption fails.
	 */
	public static function decrypt_option($encrypted) {
		if (empty($encrypted) || !is_string($encrypted)) {
			return $encrypted;
		}

		// Passthrough if not encrypted
		if (strpos($encrypted, self::ENCRYPTED_PREFIX) !== 0) {
			return $encrypted;
		}

		if (!function_exists('openssl_decrypt') || !defined('AUTH_KEY')) {
			return $encrypted;
		}

		$raw = base64_decode(substr($encrypted, strlen(self::ENCRYPTED_PREFIX)));
		if (false === $raw || strlen($raw) < 28) { // 12 IV + 16 tag = 28 minimum
			return $encrypted;
		}

		$key = hash('sha256', AUTH_KEY . 'sgbackup_credential_encryption', true);
		$iv = substr($raw, 0, 12);
		$tag = substr($raw, 12, 16);
		$ciphertext = substr($raw, 28);

		$plaintext = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if (false === $plaintext) {
			// The value IS marked encrypted (sgenc: prefix) but cannot be
			// decrypted. The overwhelmingly likely cause is that AUTH_KEY changed
			// after a migration/restore, so the derived key no longer matches.
			// Returning the raw ciphertext blob would let it be used as a literal
			// credential (guaranteed auth failures, or worse, silent misuse).
			// Return empty and flag the site so an admin re-enters the credential.
			self::flag_credentials_unreadable();
			return '';
		}

		return $plaintext;
	}

	/**
	 * Record that stored storage credentials could not be decrypted and notify.
	 * Idempotent: safe to call repeatedly (option/log are only written once per state).
	 */
	private static function flag_credentials_unreadable() {
		if (function_exists('get_option') && function_exists('update_option')) {
			if (!get_option('sgbackup_credentials_unreadable')) {
				update_option('sgbackup_credentials_unreadable', 1, false);
				$msg = 'Stored storage credentials are unreadable (likely AUTH_KEY changed after a migration or restore). Re-enter your remote storage credentials in SiteGuard Backup settings.';
				if (class_exists('SiteGuard_Backup')) {
					SiteGuard_Backup::instance()->log($msg, 'error');
				}
				if (class_exists('SiteGuard_Backup_Audit_Log')) {
					SiteGuard_Backup_Audit_Log::instance()->log('credentials_unreadable', $msg);
				}
			}
		}
	}

	/**
	 * Check if a value is encrypted.
	 *
	 * @param string $value The value to check.
	 * @return bool
	 */
	public static function is_encrypted($value) {
		return is_string($value) && strpos($value, self::ENCRYPTED_PREFIX) === 0;
	}

	/**
	 * Get the list of sensitive field names for a storage method.
	 *
	 * @param string $method_id Storage method identifier.
	 * @return array Field names to encrypt.
	 */
	public static function get_sensitive_fields($method_id) {
		return isset(self::$sensitive_fields[$method_id]) ? self::$sensitive_fields[$method_id] : array();
	}

	/**
	 * Encrypt sensitive fields in a storage method's options array.
	 *
	 * @param string $method_id Storage method identifier.
	 * @param array  $options   Method options.
	 * @return array Options with sensitive fields encrypted.
	 */
	public static function encrypt_method_options($method_id, $options) {
		$fields = self::get_sensitive_fields($method_id);
		if (empty($fields)) {
			return $options;
		}

		foreach ($fields as $field) {
			if (isset($options[$field]) && !empty($options[$field]) && !self::is_encrypted($options[$field])) {
				$options[$field] = self::encrypt_option($options[$field]);
			}
		}

		return $options;
	}

	/**
	 * Decrypt sensitive fields in a storage method's options array.
	 *
	 * @param string $method_id Storage method identifier.
	 * @param array  $options   Method options.
	 * @return array Options with sensitive fields decrypted.
	 */
	public static function decrypt_method_options($method_id, $options) {
		$fields = self::get_sensitive_fields($method_id);
		if (empty($fields)) {
			return $options;
		}

		foreach ($fields as $field) {
			if (isset($options[$field]) && self::is_encrypted($options[$field])) {
				$options[$field] = self::decrypt_option($options[$field]);
			}
		}

		return $options;
	}

	/**
	 * Check the client IP against the configured allowlist.
	 *
	 * @return bool True if IP is allowed (or no allowlist configured).
	 */
	public static function check_ip_allowlist() {
		$opts = SiteGuard_Backup_Options::instance();
		$allowlist_raw = $opts->get_option('sgbackup_ip_allowlist', '');

		if (empty($allowlist_raw)) {
			return true; // No allowlist = all IPs allowed
		}

		$entries = array_filter(array_map('trim', explode("\n", $allowlist_raw)));
		if (empty($entries)) {
			return true;
		}

		$client_ip = self::get_client_ip();
		if (empty($client_ip)) {
			return false; // Can't determine IP, deny
		}

		foreach ($entries as $entry) {
			if (strpos($entry, '/') !== false) {
				// CIDR notation
				if (self::ip_in_cidr($client_ip, $entry)) {
					return true;
				}
			} else {
				// Exact match
				if ($client_ip === $entry) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get the client's real IP address, respecting Cloudflare proxy.
	 *
	 * @return string Client IP address.
	 */
	public static function get_client_ip() {
		$remote = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';

		// CF-Connecting-IP is only meaningful when the TCP peer actually is a
		// proxy we trust (a Cloudflare edge, or a local reverse proxy on a
		// loopback/private address). Honouring it unconditionally let any client
		// pick its own "IP" and walk straight past the allowlist and the rate
		// limiters. When the peer is a public non-Cloudflare address, the header
		// is attacker-supplied and is ignored.
		if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && $remote !== '' && self::is_trusted_proxy($remote)) {
			$cf = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
			if (filter_var($cf, FILTER_VALIDATE_IP)) {
				return $cf;
			}
		}

		return $remote;
	}

	/**
	 * Is $ip a proxy whose forwarded-client headers we accept?
	 *
	 * Cloudflare's published ranges plus loopback/RFC1918/link-local (a reverse
	 * proxy or container gateway in front of PHP). Extend via the
	 * sgbackup_trusted_proxy_cidrs filter.
	 */
	public static function is_trusted_proxy($ip) {
		static $cidrs = null;
		if (null === $cidrs) {
			$cidrs = array(
				// Loopback / private / link-local: a local reverse proxy
				'127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16',
				'::1/128', 'fc00::/7', 'fe80::/10',
				// Cloudflare IPv4 (https://www.cloudflare.com/ips-v4)
				'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
				'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
				'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
				'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
				// Cloudflare IPv6 (https://www.cloudflare.com/ips-v6)
				'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
				'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
			);
			$cidrs = (array) apply_filters('sgbackup_trusted_proxy_cidrs', $cidrs);
		}
		foreach ($cidrs as $cidr) {
			if (self::ip_in_cidr($ip, $cidr)) return true;
		}
		return false;
	}

	/**
	 * Check if an IP address is within a CIDR range.
	 *
	 * Supports both IPv4 and IPv6.
	 *
	 * @param string $ip   IP address to check.
	 * @param string $cidr CIDR notation (e.g., '192.168.1.0/24').
	 * @return bool
	 */
	public static function ip_in_cidr($ip, $cidr) {
		$parts = explode('/', $cidr, 2);
		if (count($parts) !== 2) {
			return false;
		}

		list($subnet, $bits) = $parts;
		$bits = intval($bits);

		$ip_bin = inet_pton($ip);
		$subnet_bin = inet_pton($subnet);

		if (false === $ip_bin || false === $subnet_bin) {
			return false;
		}

		// Ensure same address family
		if (strlen($ip_bin) !== strlen($subnet_bin)) {
			return false;
		}

		// Build netmask
		$total_bits = strlen($ip_bin) * 8;
		if ($bits < 0 || $bits > $total_bits) {
			return false;
		}

		$mask = str_repeat("\xff", intval($bits / 8));
		if ($bits % 8) {
			$mask .= chr(0xff << (8 - ($bits % 8)));
		}
		$mask = str_pad($mask, strlen($ip_bin), "\x00");

		return ($ip_bin & $mask) === ($subnet_bin & $mask);
	}

	/**
	 * Validate that an IP allowlist includes the current user's IP.
	 * Used to prevent self-lockout when saving settings.
	 *
	 * @param string $allowlist_text Newline-separated IP/CIDR entries.
	 * @return bool True if current IP is included (or allowlist is empty).
	 */
	public static function validate_allowlist_includes_current_ip($allowlist_text) {
		if (empty(trim($allowlist_text))) {
			return true;
		}

		$entries = array_filter(array_map('trim', explode("\n", $allowlist_text)));
		if (empty($entries)) {
			return true;
		}

		$client_ip = self::get_client_ip();
		if (empty($client_ip)) {
			return false;
		}

		foreach ($entries as $entry) {
			if (strpos($entry, '/') !== false) {
				if (self::ip_in_cidr($client_ip, $entry)) {
					return true;
				}
			} else {
				if ($client_ip === $entry) {
					return true;
				}
			}
		}

		return false;
	}
}
