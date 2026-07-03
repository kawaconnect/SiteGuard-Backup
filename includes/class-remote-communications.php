<?php
/**
 * SiteGuard Backup - Remote Communications (RPC Protocol)
 *
 * RSA key generation, encrypted message sending/receiving for site-to-site migration.
 * Two message formats:
 *   Format 1: One-sided encryption (initial handshake) - sender encrypts with receiver's public key
 *   Format 2: Mutual authentication (after key exchange) - both sides have each other's public keys
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
	public function generate_key($name, $key_size = 2048) {
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

		$local_keys[$name_hash] = array(
			'name'        => $name,
			'private_key' => $stored_private_key,
			'public_key'  => $public_key,
			'created'     => time(),
			'format'      => 1, // Start with format 1 until sender upgrades
		);

		$opts->update_option('sgbackup_migrator_localkeys', $local_keys);

		// Create portable bundle (contains public key + site URL + name)
		$bundle = $this->create_portable_bundle($name_hash, $name, $public_key);

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
	public function create_portable_bundle($name_hash, $name, $public_key) {
		$data = array(
			'version'    => 1,
			'name'       => $name,
			'name_hash'  => $name_hash,
			'url'        => site_url(),
			'public_key' => $public_key,
			'plugin'     => 'siteguardbackup',
		);

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
		if (!is_array($data) || empty($data['url']) || empty($data['public_key'])) {
			return new WP_Error('invalid_bundle', 'Invalid key bundle: missing required fields.');
		}

		return $data;
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

		error_log("SiteGuard Backup RPC: Sending '{$command}' to {$endpoint} (name_hash={$name_hash})");

		// Send via POST
		$response = wp_remote_post($endpoint, array(
			'body' => array(
				'action'    => 'sgbackup_ajax',
				'subaction' => 'rpc_message',
				'nonce'     => 'rpc',
				'format'    => 1,
				'name_hash' => $name_hash,
				'encrypted' => $encrypted['data'],
				'key'       => $encrypted['key'],
			),
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

		error_log("SiteGuard Backup RPC: Response for '{$command}': HTTP {$code}, body=" . substr($body, 0, 500));

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
			return new WP_Error('rpc_remote_error', $msg);
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

		// Replay protection: timestamp check (reject past AND future messages)
		if (isset($payload['timestamp'])) {
			$age = time() - $payload['timestamp'];
			if ($age < -10 || $age > $this->replay_window) {
				return new WP_Error('replay', "Message timestamp out of window ({$age}s). Check server time sync.");
			}
		}

		// Replay protection: nonce uniqueness (prevents same message being replayed)
		if (!empty($payload['nonce'])) {
			$nonce_key = 'sgbackup_rpc_nonce_' . md5($payload['nonce']);
			if (get_transient($nonce_key)) {
				return new WP_Error('replay', 'Duplicate message nonce detected.');
			}
			set_transient($nonce_key, 1, $this->replay_window * 2);
		}

		return $payload;
	}
}
