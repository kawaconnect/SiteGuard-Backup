<?php
/**
 * SiteGuard Backup - Remote Communications (RPC Protocol)
 *
 * RSA key generation, encrypted message sending/receiving for site-to-site migration.
 *
 * A migration key is created on the RECEIVING site. Its bundle (pasted into the
 * sending site) holds the receiver's URL, RSA public key and, since 1.7.0, a
 * random 256-bit secret. Every request carries an HMAC-SHA256 over the
 * encrypted message made with that secret, and every answer is signed with it
 * too, bound to the request's nonce:
 *
 *   - the public key alone no longer authorizes anything (it encrypts, it does
 *     not authenticate), and a bundle copied from the sender's database is
 *     useless without wp-config.php: the sender stores the secret encrypted;
 *   - a forged answer ("already uploaded") is detected by the sender;
 *   - a key can expire, is bound to the site (blog) it was made on, and each
 *     message nonce is accepted once.
 *
 * Bundles and keys made before 1.7.0 have no secret and are refused unless the
 * network option sgbackup_allow_legacy_migration_keys is set.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Remote_Communications {

	/** @var int Replay protection window in seconds */
	private $replay_window = 120;

	/**
	 * Generate a new RSA key pair for receiving migrations.
	 *
	 * @param string $name     Key name.
	 * @param int    $key_size RSA key size (2048 or 4096).
	 * @return array|WP_Error  ['bundle' => base64-encoded portable bundle, 'name' => name]
	 */
	/** HMAC-SHA256 over the parts, joined by newlines. */
	public static function mac($secret, array $parts) {
		return hash_hmac('sha256', implode("\n", $parts), (string) $secret);
	}

	/** A key's stored (encrypted) secret, in plain text; '' for a pre-1.7.0 key. */
	/**
	 * @return string|null The secret; '' for a pre-1.7.0 key without one; null
	 *                     when it is stored but cannot be decrypted (AUTH_KEY in
	 *                     wp-config.php changed).
	 */
	public static function key_secret($stored) {
		if (!is_string($stored) || '' === $stored) return '';
		require_once SGBACKUP_DIR . 'includes/class-security.php';
		$plain = SiteGuard_Backup_Security::decrypt_option($stored);
		return (is_string($plain) && '' !== $plain && !SiteGuard_Backup_Security::is_encrypted($plain)) ? $plain : null;
	}

	/** Are keys and bundles without a secret (made before 1.7.0) accepted? */
	public static function legacy_keys_allowed() {
		return (bool) get_site_option('sgbackup_allow_legacy_migration_keys', false);
	}

	/**
	 * Accept each message nonce once (atomic: INSERT IGNORE on the options
	 * table's unique key). The old get-then-set transient let two concurrent
	 * replays through.
	 */
	public static function claim_nonce($nonce, $window) {
		global $wpdb;
		$name = 'sgbackup_rpcn_' . md5((string) $nonce);
		$now = time();
		$inserted = $wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$name, (string) $now
		));
		if (1 !== (int) $inserted) {
			// Already there: a replay, unless it is a stale row past the window.
			$seen = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
			if ($seen && ($now - $seen) <= 2 * $window) return false;
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, $name, (string) $seen));
			if (1 !== (int) $wpdb->rows_affected) return false;
		}
		if (0 === wp_rand(0, 49)) {
			$wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like('sgbackup_rpcn_') . '%', $now - 2 * $window
			));
		}
		return true;
	}

	public function generate_key($name, $key_size = 2048, $expires_days = 0) {
		if (!function_exists('openssl_pkey_new')) {
			return new WP_Error('no_openssl', 'OpenSSL extension is required for key generation.');
		}

		$config = array(
			'private_key_bits' => $key_size,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$key = openssl_pkey_new($config);
		if (!$key) {
			return new WP_Error('key_gen_failed', 'Failed to generate RSA key pair: ' . openssl_error_string());
		}

		// Extract private key
		openssl_pkey_export($key, $private_key);

		// Extract public key
		$details = openssl_pkey_get_details($key);
		$public_key = $details['key'];

		// Create name hash for identification
		$name_hash = wp_generate_password(12, false);

		// Store locally. Encrypt the private key at rest if the credential
		// encryption helper is available (AUTH_KEY-derived AES-256-GCM). Old
		// keys stored before this change remain plaintext and are handled by the
		// graceful passthrough in decrypt_private_key() on read.
		$opts = SiteGuard_Backup_Options::instance();
		$local_keys = $opts->get_option('sgbackup_migrator_localkeys', array());

		$stored_private_key = $private_key;
		if (file_exists(SGBACKUP_DIR . 'includes/class-security.php')) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			if (class_exists('SiteGuard_Backup_Security')) {
				$stored_private_key = SiteGuard_Backup_Security::encrypt_option($private_key);
			}
		}

		// Shared secret: authenticates every message in both directions.
		$secret = bin2hex(random_bytes(32));
		require_once SGBACKUP_DIR . 'includes/class-security.php';
		$expires_days = max(0, min(3650, (int) $expires_days));

		$local_keys[$name_hash] = array(
			'name'        => $name,
			'private_key' => $stored_private_key,
			'public_key'  => $public_key,
			'secret'      => SiteGuard_Backup_Security::encrypt_option($secret),
			'blog_id'     => (int) get_current_blog_id(),
			'expires'     => $expires_days ? time() + $expires_days * DAY_IN_SECONDS : 0,
			'created'     => time(),
			'format'      => 2,
		);

		$opts->update_option('sgbackup_migrator_localkeys', $local_keys);

		// Create portable bundle (public key + site URL + name + secret)
		$bundle = $this->create_portable_bundle($name_hash, $name, $public_key, $secret);

		return array(
			'bundle'    => $bundle,
			'name'      => $name,
			'name_hash' => $name_hash,
		);
	}

	/**
	 * Create a portable key bundle for sharing with the sending site.
	 *
	 * @param string $name_hash
	 * @param string $name
	 * @param string $public_key
	 * @return string Base64-encoded bundle.
	 */
	public function create_portable_bundle($name_hash, $name, $public_key, $secret = '') {
		$data = array(
			'version'    => ('' !== $secret) ? 2 : 1,
			'name'       => $name,
			'name_hash'  => $name_hash,
			'url'        => site_url(),
			'public_key' => $public_key,
			'plugin'     => 'siteguardbackup',
		);
		if ('' !== $secret) $data['secret'] = $secret;

		return base64_encode(json_encode($data));
	}

	/**
	 * Decode a portable bundle from another site.
	 *
	 * @param string $bundle_str Base64-encoded bundle string.
	 * @return array|WP_Error    Decoded bundle data.
	 */
	public function decode_portable_bundle($bundle_str) {
		$decoded = @base64_decode(trim($bundle_str));
		if (!$decoded) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: could not decode.');
		}

		$data = @json_decode($decoded, true);
		if (!is_array($data) || empty($data['url']) || empty($data['public_key']) || !is_string($data['url']) || !is_string($data['public_key'])) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: missing required fields.');
		}
		if (!preg_match('#^https?://#i', $data['url'])) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: the site URL must start with http:// or https://.');
		}
		if (empty($data['name_hash']) || !is_string($data['name_hash']) || !preg_match('/^[A-Za-z0-9]{6,40}$/', $data['name_hash'])) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: missing key identifier.');
		}
		if (isset($data['secret']) && (!is_string($data['secret']) || !preg_match('/^[a-f0-9]{64}$/', $data['secret']))) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: malformed secret.');
		}
		if (empty($data['secret']) && !self::legacy_keys_allowed()) {
			return new WP_Error('legacy_bundle', 'This key bundle was made by a SiteGuard Backup version older than 1.7.0 and cannot authenticate its messages. Update the receiving site, create a new key there and paste the new bundle.');
		}
		$keep = array('version', 'name', 'name_hash', 'url', 'public_key', 'plugin', 'secret');
		return array_intersect_key($data, array_flip($keep));
	}

	/**
	 * Send an encrypted message to a remote site.
	 *
	 * @param array  $site    Remote site configuration (from decoded bundle).
	 * @param string $command Command name.
	 * @param array  $data    Command data.
	 * @return array|WP_Error Response data.
	 */
	public function send_message($site, $command, $data) {
		if (empty($site['url']) || empty($site['public_key'])) {
			return new WP_Error('invalid_site', 'Invalid site configuration.');
		}

		$payload = array(
			'command'   => $command,
			'data'      => $data,
			'timestamp' => time(),
			'nonce'     => wp_generate_password(16, false),
			'sender'    => site_url(),
		);

		$json_payload = json_encode($payload);

		// Encrypt with receiver's public key using AES + RSA hybrid
		$encrypted = $this->encrypt_message($json_payload, $site['public_key']);
		if (is_wp_error($encrypted)) {
			error_log("SiteGuard Backup RPC: Encryption failed for {$command}: " . $encrypted->get_error_message());
			return $encrypted;
		}

		// Determine endpoint
		$name_hash = isset($site['name_hash']) ? $site['name_hash'] : '';
		$endpoint = trailingslashit($site['url']) . 'wp-admin/admin-ajax.php';
		$secret = self::key_secret(isset($site['secret']) ? $site['secret'] : '');
		if (null === $secret) {
			return new WP_Error('secret_unreadable', 'The migration key secret of this destination cannot be decrypted (the AUTH_KEY in wp-config.php changed?). Create a new key on the receiving site and add the destination again.');
		}
		if ('' === $secret && !self::legacy_keys_allowed()) {
			return new WP_Error('legacy_bundle', 'This destination was added with a key bundle from before SiteGuard Backup 1.7.0, which cannot authenticate its messages. Create a new key on the receiving site and add the destination again.');
		}

		$body = array(
			'action'    => 'sgbackup_ajax',
			'subaction' => 'rpc_message',
			'nonce'     => 'rpc',
			'format'    => ('' !== $secret) ? 2 : 1,
			'name_hash' => $name_hash,
			'encrypted' => $encrypted['data'],
			'key'       => $encrypted['key'],
		);
		if ('' !== $secret) {
			$body['mac'] = self::mac($secret, array('request', $name_hash, $encrypted['data'], $encrypted['key']));
		}

		// Send via POST
		$response = wp_remote_post($endpoint, array(
			'body' => $body,
			'timeout' => 120,
			'headers' => array(
				'X-SGBackup-RPC'  => '1',
				'X-SGBackup-HMAC' => '1',
			),
		));

		if (is_wp_error($response)) {
			error_log("SiteGuard Backup RPC: HTTP error for {$command}: " . $response->get_error_message());
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		error_log("SiteGuard Backup RPC: Response for '{$command}': HTTP {$code}, " . strlen($body) . ' bytes');

		if ($code !== 200) {
			return new WP_Error('rpc_error', "RPC error (HTTP {$code}): " . substr($body, 0, 200));
		}

		$result = @json_decode($body, true);

		if (!is_array($result)) {
			error_log("SiteGuard Backup RPC: Unparseable response for '{$command}': " . substr($body, 0, 500));
			return new WP_Error('rpc_parse_error', 'Could not parse RPC response.');
		}

		if (isset($result['success']) && !$result['success']) {
			$msg = isset($result['data']['message']) ? $result['data']['message'] : 'RPC error';
			error_log("SiteGuard Backup RPC: Remote error for '{$command}': {$msg}");
			// The receiver's error code, so the sender can react (out_of_sequence
			// re-syncs the offset instead of burning its retries).
			$code = isset($result['data']['code']) && is_string($result['data']['code']) && preg_match('/^[a-z0-9_]{1,40}$/', $result['data']['code']) ? $result['data']['code'] : 'rpc_remote_error';
			return new WP_Error($code, $msg);
		}

		if ('' !== $secret) {
			// The answer must be signed with the key's secret and bound to this
			// request's nonce: anyone answering at that URL (a hijacked domain, a
			// man in the middle on http) could otherwise report "already uploaded".
			$d = isset($result['data']) && is_array($result['data']) ? $result['data'] : array();
			if (!isset($d['signed'], $d['mac']) || !is_string($d['signed']) || !is_string($d['mac'])
				|| !hash_equals(self::mac($secret, array('response', $payload['nonce'], $d['signed'])), $d['mac'])) {
				return new WP_Error('rpc_unsigned', 'The receiving site\'s answer is not signed with this migration key. Make sure it runs SiteGuard Backup 1.7.0 or later and that the key still exists there.');
			}
			$inner = json_decode($d['signed'], true);
			return is_array($inner) ? $inner : array();
		}

		return isset($result['data']) ? $result['data'] : $result;
	}

	/**
	 * Encrypt a message using AES-256-GCM + RSA-OAEP hybrid encryption.
	 * The AES key is encrypted with the receiver's RSA public key using OAEP padding.
	 *
	 * @param string $plaintext  Message to encrypt.
	 * @param string $public_key RSA public key PEM.
	 * @return array|WP_Error    ['data' => base64(IV + tag + AES-encrypted), 'key' => base64(RSA-encrypted AES key)]
	 */
	private function encrypt_message($plaintext, $public_key) {
		// Generate random AES key and IV (12 bytes for GCM)
		$aes_key = random_bytes(32);
		$iv = random_bytes(12);
		$tag = '';

		// Encrypt payload with AES-256-GCM (authenticated encryption)
		$encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
		if (false === $encrypted) {
			return new WP_Error('aes_encrypt', 'AES-GCM encryption failed.');
		}

		// Encrypt AES key with RSA public key using OAEP padding (Bleichenbacher-resistant)
		$pub_key_resource = openssl_pkey_get_public($public_key);
		if (!$pub_key_resource) {
			return new WP_Error('invalid_pubkey', 'Invalid public key.');
		}

		$rsa_encrypted_key = '';
		$result = openssl_public_encrypt($aes_key, $rsa_encrypted_key, $pub_key_resource, OPENSSL_PKCS1_OAEP_PADDING);
		if (!$result) {
			return new WP_Error('rsa_encrypt', 'RSA encryption failed: ' . openssl_error_string());
		}

		// Pack: IV (12) + tag (16) + ciphertext
		$packed = $iv . $tag . $encrypted;

		return array(
			'data' => base64_encode($packed),
			'key'  => base64_encode($rsa_encrypted_key),
		);
	}

	/**
	 * Decrypt an incoming RPC message.
	 *
	 * @param string $encrypted_data Base64-encoded AES-encrypted data.
	 * @param string $encrypted_key  Base64-encoded RSA-encrypted AES key.
	 * @param string $private_key    RSA private key PEM.
	 * @return array|WP_Error        Decoded message payload.
	 */
	public function decrypt_message($encrypted_data, $encrypted_key, $private_key) {
		// Decrypt AES key with RSA private key using OAEP padding
		$rsa_encrypted = base64_decode($encrypted_key);
		$priv_key_resource = openssl_pkey_get_private($private_key);
		if (!$priv_key_resource) {
			return new WP_Error('invalid_privkey', 'Invalid private key.');
		}

		$aes_key = '';
		// Try OAEP first (new format), fall back to PKCS#1 v1.5 (legacy)
		$result = openssl_private_decrypt($rsa_encrypted, $aes_key, $priv_key_resource, OPENSSL_PKCS1_OAEP_PADDING);
		if (!$result) {
			// Downgrade to PKCS#1 v1.5 is OFF by default: automatically accepting
			// it exposes the receiver to Bleichenbacher-style padding-oracle
			// attacks. Only try the legacy path if an operator has explicitly
			// opted in for a known legacy peer.
			if (!get_option('sgbackup_allow_legacy_rsa_padding', false)) {
				return new WP_Error('rsa_decrypt', 'RSA decryption failed (OAEP). Legacy PKCS#1 v1.5 padding is disabled.');
			}

			if (class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log('Warning: falling back to deprecated PKCS#1 v1.5 RSA padding (sgbackup_allow_legacy_rsa_padding is enabled). This is vulnerable to padding-oracle attacks; upgrade the remote peer and disable this option.', 'warning');
			}

			// Fallback for messages from older senders using PKCS#1 v1.5
			$decrypted_key_iv = '';
			$result = openssl_private_decrypt($rsa_encrypted, $decrypted_key_iv, $priv_key_resource);
			if (!$result) {
				return new WP_Error('rsa_decrypt', 'RSA decryption failed.');
			}
			// Legacy format: key (32) + IV (16 for CBC)
			$iv_length = openssl_cipher_iv_length('aes-256-cbc');
			$legacy_key = substr($decrypted_key_iv, 0, 32);
			$iv = substr($decrypted_key_iv, 32, $iv_length);
			$encrypted = base64_decode($encrypted_data);
			$plaintext = openssl_decrypt($encrypted, 'aes-256-cbc', $legacy_key, OPENSSL_RAW_DATA, $iv);
			if (false === $plaintext) {
				return new WP_Error('aes_decrypt', 'AES decryption failed.');
			}
			// Deprecation warning: CBC is only supported for legacy compatibility
			@trigger_error('SiteGuard Backup RPC: AES-256-CBC decryption is deprecated. The remote site should upgrade to a version that uses AES-256-GCM.', E_USER_DEPRECATED);
			if (class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log('Warning: Received RPC message using deprecated AES-256-CBC encryption. Remote site should be upgraded.');
			}
		} else {
			// New GCM format: packed data = IV (12) + tag (16) + ciphertext
			$packed = base64_decode($encrypted_data);
			if (strlen($packed) < 28) {
				return new WP_Error('invalid_data', 'Encrypted data too short.');
			}
			$iv = substr($packed, 0, 12);
			$tag = substr($packed, 12, 16);
			$encrypted = substr($packed, 28);
			$plaintext = openssl_decrypt($encrypted, 'aes-256-gcm', $aes_key, OPENSSL_RAW_DATA, $iv, $tag);
			if (false === $plaintext) {
				return new WP_Error('aes_decrypt', 'AES-GCM decryption failed (tampered or wrong key).');
			}
		}

		$payload = @json_decode($plaintext, true);
		if (!is_array($payload)) {
			return new WP_Error('invalid_payload', 'Could not parse decrypted payload.');
		}

		// Replay protection: every message has a timestamp and a nonce (all
		// SiteGuard senders always sent both; a message without them is refused).
		if (!isset($payload['timestamp']) || !is_numeric($payload['timestamp']) || empty($payload['nonce']) || !is_string($payload['nonce'])) {
			return new WP_Error('replay', 'Message has no timestamp or nonce.');
		}
		$age = time() - (int) $payload['timestamp'];
		if ($age < -10 || $age > $this->replay_window) {
			return new WP_Error('replay', "Message timestamp out of window ({$age}s). Check server time sync.");
		}
		if (!self::claim_nonce($payload['nonce'], $this->replay_window)) {
			return new WP_Error('replay', 'Duplicate message nonce detected.');
		}

		return $payload;
	}
}
