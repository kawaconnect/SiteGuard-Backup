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
	/** Marks an encrypted value that decrypts to a JSON array (OAuth token sets). */
	const JSON_PREFIX = "sgjson:";

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
	 * Options whose value is a secret stored through encrypt_option(): the backup
	 * encryption passphrase and the webhook signing secret. Storage method
	 * credentials are listed per method in $sensitive_fields.
	 */
	const SECRET_OPTIONS = array('sgbackup_encryption_key', 'sgbackup_webhook_secret');

	/** Option: version of the secrets-at-rest migration this site has run. */
	const MIGRATION_OPTION = 'sgbackup_secrets_at_rest';
	/** Option: fingerprint of the key the stored secrets were encrypted with. */
	const KEY_CHECK_OPTION = 'sgbackup_secrets_key_check';
	/** Option: set while stored secrets cannot be decrypted (array: reason, time, items). */
	const UNREADABLE_OPTION = 'sgbackup_credentials_unreadable';

	/**
	 * The AES key derived from AUTH_KEY, or null when no key can be derived
	 * (AUTH_KEY not defined, or no OpenSSL).
	 *
	 * @return string|null 32 raw bytes.
	 */
	private static function derived_key() {
		if (!function_exists('openssl_encrypt') || !defined('AUTH_KEY') || '' === (string) AUTH_KEY) {
			return null;
		}
		return hash('sha256', AUTH_KEY . 'sgbackup_credential_encryption', true);
	}

	/**
	 * True when secrets can be encrypted at rest on this site.
	 */
	public static function can_encrypt() {
		return null !== self::derived_key();
	}

	/**
	 * Is "encrypt credentials at rest" on? On by default since 1.7.1; an admin
	 * can still turn it off in Advanced settings.
	 */
	public static function encryption_on() {
		if (!class_exists('SiteGuard_Backup_Options')) return true;
		return (bool) SiteGuard_Backup_Options::instance()->get_option('sgbackup_encrypt_credentials', true);
	}

	/**
	 * Fingerprint of the current key (not reversible), stored next to the
	 * secrets so a changed or missing AUTH_KEY is noticed before anything
	 * tries to use them. '' when no key can be derived.
	 */
	public static function key_fingerprint() {
		$key = self::derived_key();
		return null === $key ? '' : substr(hash_hmac('sha256', 'sgbackup-secrets-key-check', $key), 0, 16);
	}

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

		$key = self::derived_key();
		if (null === $key) {
			// Storing plaintext because we cannot derive an encryption key.
			// Surface this rather than silently persisting a secret in the clear.
			if (!defined('AUTH_KEY') && class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log('Warning: storage credentials cannot be encrypted at rest because AUTH_KEY is not defined in wp-config.php. They will be stored in plaintext.', 'warning');
			}
			return $plaintext;
		}

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

		$encrypted = self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ciphertext);
		// Never store something that does not decrypt back to the original.
		if (self::try_decrypt($encrypted) !== $plaintext) {
			return $plaintext;
		}
		return $encrypted;
	}

	/**
	 * Decrypt an sgenc: value without side effects.
	 *
	 * @return string|null Plaintext, or null when it cannot be decrypted with the current key.
	 */
	public static function try_decrypt($encrypted) {
		if (!is_string($encrypted) || strpos($encrypted, self::ENCRYPTED_PREFIX) !== 0) {
			return null;
		}
		$key = self::derived_key();
		if (null === $key) {
			return null;
		}
		$raw = base64_decode(substr($encrypted, strlen(self::ENCRYPTED_PREFIX)), true);
		if (false === $raw || strlen($raw) < 28) { // 12 IV + 16 tag = 28 minimum
			return null;
		}
		$plaintext = openssl_decrypt(
			substr($raw, 28),
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			substr($raw, 0, 12),
			substr($raw, 12, 16)
		);
		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Decrypt a value encrypted with encrypt_option().
	 *
	 * Plaintext (values stored before encryption was on) is returned as it is.
	 *
	 * @param string $encrypted The encrypted value (with sgenc: prefix).
	 * @return string Decrypted plaintext, the original value if not encrypted, or '' if it cannot be decrypted.
	 */
	public static function decrypt_option($encrypted) {
		if (empty($encrypted) || !is_string($encrypted)) {
			return $encrypted;
		}

		// Passthrough if not encrypted
		if (strpos($encrypted, self::ENCRYPTED_PREFIX) !== 0) {
			return $encrypted;
		}

		$plaintext = self::try_decrypt($encrypted);
		if (null === $plaintext) {
			// The value IS marked encrypted (sgenc: prefix) but cannot be
			// decrypted: AUTH_KEY changed (migration, restore, new salts) or is
			// missing. Returning the ciphertext would hand it to a provider as
			// the credential. Return empty and flag the site so an admin is told;
			// the stored ciphertext itself is left in place (see keep_unreadable()).
			self::flag_credentials_unreadable(defined('AUTH_KEY') ? 'key_changed' : 'key_missing');
			return '';
		}

		return $plaintext;
	}

	/**
	 * Record that stored secrets could not be decrypted, once per state.
	 */
	private static function flag_credentials_unreadable($reason = 'key_changed', $items = array()) {
		if (!function_exists('get_option') || !function_exists('update_option')) return;
		$current = get_option(self::UNREADABLE_OPTION);
		if (!empty($current) && (empty($items) || (is_array($current) && !empty($current['items'])))) return;
		update_option(self::UNREADABLE_OPTION, array('reason' => $reason, 'time' => time(), 'items' => array_values((array) $items)), false);
		if (!empty($current)) return;
		$msg = 'Stored SiteGuard Backup secrets (storage credentials, backup encryption passphrase or webhook secret) cannot be decrypted: '
			. ('key_missing' === $reason ? 'AUTH_KEY is not defined in wp-config.php.' : 'AUTH_KEY in wp-config.php changed since they were saved.')
			. ' Nothing was deleted. Restore the previous AUTH_KEY, or re-enter them in SiteGuard Backup settings.';
		if (class_exists('SiteGuard_Backup')) {
			SiteGuard_Backup::instance()->log($msg, 'error');
		}
		if (class_exists('SiteGuard_Backup_Audit_Log')) {
			SiteGuard_Backup_Audit_Log::instance()->log('credentials_unreadable', $msg);
		}
	}

	/**
	 * Every stored secret of this site: [label => raw stored value]. Read raw
	 * (still encrypted) so callers can test or migrate them.
	 *
	 * @return array
	 */
	public static function stored_secrets() {
		$out = array();
		if (!class_exists('SiteGuard_Backup_Options')) return $out;
		$opts = SiteGuard_Backup_Options::instance();
		foreach (self::SECRET_OPTIONS as $name) {
			$v = $opts->get_option($name, '');
			if (is_string($v) && '' !== $v) $out[$name] = $v;
		}
		foreach (array_keys(self::$sensitive_fields) as $method_id) {
			$stored = $opts->get_option(SiteGuard_Backup_Options::method_option($method_id), array());
			if (!is_array($stored) || empty($stored)) continue;
			$instances = (isset($stored['settings']) && is_array($stored['settings'])) ? $stored['settings'] : array('flat' => $stored);
			foreach ($instances as $instance_id => $fields) {
				if (!is_array($fields)) continue;
				foreach (self::get_sensitive_fields($method_id) as $field) {
					if (isset($fields[$field]) && is_string($fields[$field]) && '' !== $fields[$field]) {
						$out[$method_id . '/' . $instance_id . '/' . $field] = $fields[$field];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Check every stored encrypted secret against the current key. Sets the
	 * unreadable flag (admin notice) when any fails, clears it when all decrypt.
	 *
	 * @return array Labels of the secrets that cannot be decrypted.
	 */
	public static function recheck_secrets() {
		$bad = array();
		foreach (self::stored_secrets() as $label => $value) {
			if (!self::is_encrypted($value)) continue;
			if (null === self::try_decrypt($value)) $bad[] = $label;
		}
		if (!function_exists('get_option')) return $bad;
		if ($bad) {
			self::flag_credentials_unreadable(self::can_encrypt() ? 'key_changed' : 'key_missing', $bad);
		} else {
			if (get_option(self::UNREADABLE_OPTION)) delete_option(self::UNREADABLE_OPTION);
			// Nothing unreadable: remember the current key (also when nothing is
			// encrypted, e.g. a database copied from another site without secrets).
			if (self::can_encrypt() && class_exists('SiteGuard_Backup_Options')) {
				$opts = SiteGuard_Backup_Options::instance();
				if ($opts->get_option(self::KEY_CHECK_OPTION, '') !== self::key_fingerprint()) {
					$opts->update_option(self::KEY_CHECK_OPTION, self::key_fingerprint());
				}
			}
		}
		return $bad;
	}

	/**
	 * Cheap per-request check (admin, cron, WP-CLI): when the key fingerprint
	 * no longer matches the one stored with the secrets, run the full check.
	 */
	public static function check_key() {
		if (!class_exists('SiteGuard_Backup_Options')) return;
		$opts = SiteGuard_Backup_Options::instance();
		$stored = (string) $opts->get_option(self::KEY_CHECK_OPTION, '');
		if ('' === $stored) return;
		if ($stored !== self::key_fingerprint()) {
			self::recheck_secrets();
		}
	}

	/**
	 * One-time upgrade (1.7.1): turn credential encryption on and encrypt the
	 * secrets that were stored in plaintext: storage method credentials, the
	 * backup passphrase and the webhook secret.
	 *
	 * Safe by construction: a value is only replaced by ciphertext that was
	 * decrypted back to the same value first; without a usable key (AUTH_KEY
	 * missing) nothing changes and the migration is tried again later; reads
	 * keep accepting plaintext either way.
	 *
	 * @param bool $force Run again (an admin turned encryption back on).
	 * @return array Summary: ran (bool), encrypted (int), reason (string).
	 */
	public static function maybe_migrate($force = false) {
		if (!class_exists('SiteGuard_Backup_Options')) return array('ran' => false, 'encrypted' => 0, 'reason' => 'no options');
		$opts = SiteGuard_Backup_Options::instance();
		if (!$force && (int) $opts->get_option(self::MIGRATION_OPTION, 0) >= 1) {
			self::check_key();
			return array('ran' => false, 'encrypted' => 0, 'reason' => 'done');
		}
		if (!self::can_encrypt()) {
			return array('ran' => false, 'encrypted' => 0, 'reason' => 'no key');
		}
		// One request at a time; a second one simply runs it again later
		// (encrypting is idempotent: encrypted values are skipped).
		if (function_exists('get_transient')) {
			if (get_transient('sgbackup_secrets_migrating')) return array('ran' => false, 'encrypted' => 0, 'reason' => 'busy');
			set_transient('sgbackup_secrets_migrating', 1, 120);
		}

		// Existing ciphertext that does not decrypt is left alone and reported.
		$unreadable = self::recheck_secrets();

		if (!$opts->get_option('sgbackup_encrypt_credentials', false)) {
			$opts->update_option('sgbackup_encrypt_credentials', 1);
		}
		$count = 0;

		foreach (self::SECRET_OPTIONS as $name) {
			$v = $opts->get_option($name, '');
			if (!is_string($v) || '' === $v || self::is_encrypted($v)) continue;
			$enc = self::encrypt_option($v);
			if (self::is_encrypted($enc)) {
				$opts->update_option($name, $enc);
				$count++;
			}
		}

		foreach (array_keys(self::$sensitive_fields) as $method_id) {
			$option = SiteGuard_Backup_Options::method_option($method_id);
			$stored = $opts->get_option($option, array());
			if (!is_array($stored) || empty($stored)) continue;
			$changed = false;
			if (isset($stored['settings']) && is_array($stored['settings'])) {
				foreach ($stored['settings'] as $instance_id => $fields) {
					if (!is_array($fields)) continue;
					$enc = self::encrypt_method_options_verified($method_id, $fields, $count);
					if ($enc !== $fields) { $stored['settings'][$instance_id] = $enc; $changed = true; }
				}
			} else {
				$enc = self::encrypt_method_options_verified($method_id, $stored, $count);
				if ($enc !== $stored) { $stored = $enc; $changed = true; }
			}
			if ($changed) $opts->update_option($option, $stored);
		}

		$opts->update_option(self::KEY_CHECK_OPTION, self::key_fingerprint());
		$opts->update_option(self::MIGRATION_OPTION, 1);
		if (function_exists('delete_transient')) delete_transient('sgbackup_secrets_migrating');
		if ($count && class_exists('SiteGuard_Backup')) {
			SiteGuard_Backup::instance()->log("Encrypted {$count} stored secret(s) at rest (storage credentials, passphrase, webhook secret)");
		}
		if ($count && class_exists('SiteGuard_Backup_Audit_Log')) {
			SiteGuard_Backup_Audit_Log::instance()->log('credentials_encrypted', "Encrypted {$count} stored secret(s) at rest.");
		}
		return array('ran' => true, 'encrypted' => $count, 'reason' => $unreadable ? 'unreadable: ' . implode(', ', $unreadable) : '');
	}

	/**
	 * encrypt_method_options(), keeping any field whose ciphertext does not
	 * decrypt back to the original. Adds the number of encrypted fields to $count.
	 */
	private static function encrypt_method_options_verified($method_id, $fields, &$count) {
		$enc = self::encrypt_method_options($method_id, $fields);
		$check = self::decrypt_method_options($method_id, $enc);
		foreach (self::get_sensitive_fields($method_id) as $field) {
			if (!isset($fields[$field]) || $enc[$field] === $fields[$field]) continue;
			if (!array_key_exists($field, $check) || $check[$field] !== $fields[$field]) {
				$enc[$field] = $fields[$field];
			} else {
				$count++;
			}
		}
		return $enc;
	}

	/**
	 * When saving method options: a credential that is stored encrypted but
	 * cannot be decrypted (AUTH_KEY changed) was handed out as ''. If the new
	 * value is still '' (nobody typed a new one), keep the stored ciphertext so
	 * it comes back once the old AUTH_KEY is restored, instead of overwriting
	 * it with nothing.
	 *
	 * @param string $method_id Storage method.
	 * @param array  $new       Options about to be stored (plaintext).
	 * @param array  $stored    The same instance as currently stored (raw).
	 * @return array
	 */
	public static function keep_unreadable($method_id, $new, $stored) {
		if (!is_array($new) || !is_array($stored)) return $new;
		foreach (self::get_sensitive_fields($method_id) as $field) {
			if (!isset($stored[$field]) || !self::is_encrypted($stored[$field])) continue;
			$incoming = isset($new[$field]) ? $new[$field] : '';
			if (('' === $incoming || self::SECRET_UNCHANGED === $incoming) && null === self::try_decrypt($stored[$field])) {
				$new[$field] = $stored[$field];
			}
		}
		return $new;
	}

	/**
	 * Is $value encrypted with a key other than the current one (or with no key available)?
	 */
	public static function is_unreadable($value) {
		return self::is_encrypted($value) && null === self::try_decrypt($value);
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
			if (!isset($options[$field]) || empty($options[$field])) continue;
			// OAuth tokens are arrays (access_token, refresh_token, expiry): they
			// used to pass through unencrypted. Stored as encrypted JSON.
			if (is_array($options[$field])) {
				$enc = self::encrypt_option(self::JSON_PREFIX . wp_json_encode($options[$field]));
				if (self::is_encrypted($enc)) $options[$field] = $enc;
				continue;
			}
			if (!self::is_encrypted($options[$field])) {
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
			if (isset($options[$field]) && is_string($options[$field]) && self::is_encrypted($options[$field])) {
				$plain = self::decrypt_option($options[$field]);
				if (is_string($plain) && 0 === strpos($plain, self::JSON_PREFIX)) {
					$decoded = json_decode(substr($plain, strlen(self::JSON_PREFIX)), true);
					$plain = is_array($decoded) ? $decoded : array();
				}
				$options[$field] = $plain;
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
