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
		'b2'         => array('secret_key'),
		'azure'      => array('account_key', 'sas_token'),
		'googledrive' => array('client_secret', 'token', 'access_token', 'refresh_token'),
		'dropbox'    => array('app_secret', 'token', 'access_token', 'refresh_token'),
		'onedrive'   => array('client_secret', 'token', 'access_token', 'refresh_token'),
		'ftp'        => array('pass'),
		'sftp'       => array('pass', 'key'),
		'webdav'     => array('pass'),
		'pcloud'     => array('access_token', 'client_secret'),
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
	 * Placeholder sent to the browser instead of a stored secret. Saving it back
	 * means "keep the stored value"; the secret itself never reaches the page.
	 */
	const SECRET_UNCHANGED = '__sgbackup_secret_unchanged__';

	/**
	 * What to put in a password field's value attribute for a stored secret.
	 */
	public static function mask_secret($value) {
		return (is_string($value) && '' !== $value) ? self::SECRET_UNCHANGED : '';
	}

	/**
	 * Replace SECRET_UNCHANGED placeholders in $new with the matching values of $previous.
	 */
	public static function keep_unchanged_secrets($new, $previous) {
		if (!is_array($new)) return $new;
		foreach ($new as $k => $v) {
			if (self::SECRET_UNCHANGED === $v) {
				$new[$k] = (is_array($previous) && isset($previous[$k])) ? $previous[$k] : '';
			}
		}
		return $new;
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
		// proxy we trust: a Cloudflare edge, loopback, or a proxy named in
		// SGBACKUP_TRUSTED_PROXIES. Honouring it from any other peer let a client
		// pick its own "IP" and walk past the allowlist and the rate limiters (a
		// LAN or container neighbour could even lock out another client's IP).
		// X-Forwarded-For is never used.
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
	 * By default only Cloudflare's published ranges and loopback. Private and
	 * link-local ranges are NOT trusted: on a shared LAN or Docker network any
	 * neighbour could otherwise forge CF-Connecting-IP. A site whose PHP sees a
	 * local reverse proxy or tunnel (cloudflared, nginx in another container)
	 * as REMOTE_ADDR lists that proxy in wp-config.php:
	 *
	 *   define('SGBACKUP_TRUSTED_PROXIES', '172.18.0.0/16');   // comma-separated IPs/CIDRs
	 *
	 * Developers can also extend the list via the sgbackup_trusted_proxy_cidrs filter.
	 */
	public static function is_trusted_proxy($ip) {
		$ip = self::unmap_ipv4($ip);
		foreach (self::trusted_proxy_cidrs() as $cidr) {
			if (self::ip_in_cidr($ip, $cidr)) return true;
		}
		return false;
	}

	/**
	 * The CIDRs whose CF-Connecting-IP header is honoured: loopback, Cloudflare,
	 * and valid entries of SGBACKUP_TRUSTED_PROXIES, then the filter.
	 *
	 * @return array
	 */
	public static function trusted_proxy_cidrs() {
		$cidrs = array(
			// Loopback: a reverse proxy on the same host
			'127.0.0.0/8', '::1/128',
			// Cloudflare IPv4 (https://www.cloudflare.com/ips-v4)
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
			// Cloudflare IPv6 (https://www.cloudflare.com/ips-v6)
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
			'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		);
		if (defined('SGBACKUP_TRUSTED_PROXIES')) {
			$cidrs = array_merge($cidrs, self::parse_proxy_list(SGBACKUP_TRUSTED_PROXIES));
		}
		return (array) apply_filters('sgbackup_trusted_proxy_cidrs', $cidrs);
	}

	/**
	 * Parse a comma/space-separated list of IPs and CIDRs (SGBACKUP_TRUSTED_PROXIES).
	 * A bare IP becomes a /32 or /128; malformed entries are dropped.
	 *
	 * @param mixed $raw String, or an array of entries.
	 * @return array Valid CIDRs.
	 */
	public static function parse_proxy_list($raw) {
		$entries = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
		$out = array();
		foreach ($entries as $entry) {
			$entry = trim((string) $entry);
			$parts = explode('/', $entry, 2);
			if (!filter_var($parts[0], FILTER_VALIDATE_IP)) continue;
			$max = (false !== strpos($parts[0], ':')) ? 128 : 32;
			if (1 === count($parts)) {
				$out[] = $parts[0] . '/' . $max;
			} elseif (ctype_digit($parts[1]) && (int) $parts[1] <= $max) {
				$out[] = $parts[0] . '/' . (int) $parts[1];
			}
		}
		return $out;
	}

	/**
	 * An IPv4-mapped IPv6 address (::ffff:192.0.2.1, what a dual-stack socket
	 * reports for an IPv4 client) as plain IPv4; anything else unchanged.
	 */
	public static function unmap_ipv4($ip) {
		$ip = (string) $ip;
		if (false !== strpos($ip, ':') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$bin = inet_pton($ip);
			if (false !== $bin && 0 === strpos($bin, str_repeat("\0", 10) . "\xff\xff")) {
				return inet_ntop(substr($bin, 12));
			}
		}
		return $ip;
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
