<?php
/**
 * SiteGuard Backup - Encryption
 *
 * AES-256-GCM authenticated encryption with PBKDF2 key derivation.
 * Provides integrity protection via GCM authentication tags and
 * an HMAC-SHA256 trailer over the entire encrypted file.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Encryption {

	/** Current cipher - GCM provides authenticated encryption */
	private static $cipher = 'aes-256-gcm';
	private static $pbkdf2_iterations = 100000;

	/** GCM tag length */
	private static $tag_length = 16;

	/** Magic headers */
	private static $magic_v2 = 'SGK2'; // New GCM format
	private static $magic_v1 = 'SGBK'; // Legacy CBC format (read-only)

	/** Header version byte */
	private static $header_version = 2;

	/**
	 * Derive an encryption key from a passphrase using PBKDF2.
	 *
	 * @param string $passphrase User passphrase.
	 * @param string $salt       Random salt bytes.
	 * @return string 32-byte derived key.
	 */
	private static function derive_key($passphrase, $salt) {
		return hash_pbkdf2('sha256', $passphrase, $salt, self::$pbkdf2_iterations, 32, true);
	}

	/**
	 * Generate a counter-based IV for a specific chunk.
	 * Base IV (12 bytes for GCM) + chunk index to ensure uniqueness.
	 *
	 * @param string $base_iv  12-byte base IV.
	 * @param int    $chunk_index Chunk number.
	 * @return string 12-byte IV unique to this chunk.
	 */
	/**
	 * Write the full buffer to a stream, tolerating short writes.
	 *
	 * fwrite() may write fewer bytes than requested (e.g. when the disk is
	 * filling up). A single unchecked fwrite() can therefore silently produce
	 * a truncated file. This loops until everything is written and returns
	 * false the moment the stream stops accepting bytes.
	 *
	 * @param resource $fh   Open write stream.
	 * @param string   $data Bytes to write.
	 * @return bool True if every byte was written, false on any short/failed write.
	 */
	private static function fwrite_all($fh, $data) {
		$len = strlen($data);
		if ($len === 0) return true;
		$written_total = 0;
		while ($written_total < $len) {
			$w = fwrite($fh, substr($data, $written_total));
			if (false === $w || 0 === $w) return false;
			$written_total += $w;
		}
		return true;
	}

	private static function chunk_iv($base_iv, $chunk_index) {
		if ($chunk_index > 0xFFFFFFFF) {
			throw new \RuntimeException('Chunk index overflow: encryption IV would repeat');
		}
		// XOR the last 4 bytes with the chunk counter
		$iv = $base_iv;
		$counter = pack('N', $chunk_index);
		for ($i = 0; $i < 4; $i++) {
			$iv[8 + $i] = chr(ord($iv[8 + $i]) ^ ord($counter[$i]));
		}
		return $iv;
	}

	/**
	 * Encrypt a backup file using AES-256-GCM.
	 *
	 * File format (SGK2):
	 *   [4 bytes] Magic "SGK2"
	 *   [1 byte]  Version (2)
	 *   [32 bytes] Salt
	 *   [12 bytes] Base IV
	 *   For each chunk:
	 *     [4 bytes] Encrypted data length (N)
	 *     [16 bytes] GCM authentication tag
	 *     [N bytes] Encrypted data
	 *   [32 bytes] HMAC-SHA256 over header + all chunks
	 *
	 * @param string $file Full path to the file.
	 * @param string $key  Encryption key/passphrase.
	 * @return string|false Path to encrypted file, or false on failure.
	 */
	public static function encrypt($file, $key) {
		if (!function_exists('openssl_encrypt')) {
			SiteGuard_Backup::instance()->log("OpenSSL not available for encryption", 'error');
			return false;
		}

		if (!in_array(self::$cipher, openssl_get_cipher_methods())) {
			SiteGuard_Backup::instance()->log("AES-256-GCM not available, cannot encrypt", 'error');
			return false;
		}

		if (!file_exists($file) || !is_readable($file)) return false;

		$encrypted_file = $file . '.crypt';

		// Pre-flight disk-space check. A short write mid-encryption produces a
		// truncated .crypt that the caller would trust as success and then
		// unlink the plaintext original - destroying the only good copy. Bail
		// early if there is not enough room for the ciphertext (source size
		// plus GCM/HMAC/framing overhead, ~5%).
		$src_size = @filesize($file);
		if (false !== $src_size) {
			$needed = (int) ($src_size * 1.05) + 1024;
			$free = false;
			if (class_exists('SiteGuard_Backup_Filesystem') && method_exists('SiteGuard_Backup_Filesystem', 'check_disk_space')) {
				$ds = SiteGuard_Backup_Filesystem::check_disk_space(dirname($encrypted_file));
				if (is_array($ds) && isset($ds['free']) && false !== $ds['free']) {
					$free = $ds['free'];
				}
			} elseif (function_exists('disk_free_space')) {
				$free = @disk_free_space(dirname($encrypted_file));
			}
			if (false !== $free && $free < $needed) {
				SiteGuard_Backup::instance()->log("Encryption aborted: insufficient disk space (need ~{$needed} bytes, have {$free}) - keeping plaintext original", 'error');
				return false;
			}
		}

		// Generate IV (12 bytes for GCM) and salt
		$iv_length = 12; // GCM standard
		$base_iv = random_bytes($iv_length);
		$salt = random_bytes(32);

		// Derive key from passphrase using PBKDF2
		$derived_key = self::derive_key($key, $salt);

		// Derive separate HMAC key from same passphrase but different context
		$hmac_key = hash_pbkdf2('sha256', $key, $salt . 'hmac', self::$pbkdf2_iterations, 32, true);

		$in = fopen($file, 'rb');
		$out = fopen($encrypted_file, 'wb');

		if (!$in || !$out) {
			if ($in) fclose($in);
			if ($out) fclose($out);
			@unlink($encrypted_file);
			return false;
		}

		// Write header: magic + version + salt + IV
		$header = self::$magic_v2;          // 4 bytes
		$header .= chr(self::$header_version); // 1 byte
		$header .= $salt;                    // 32 bytes
		$header .= $base_iv;                 // 12 bytes
		if (!self::fwrite_all($out, $header)) {
			fclose($in);
			fclose($out);
			@unlink($encrypted_file);
			SiteGuard_Backup::instance()->log("Encryption failed: short write on header (disk full?) - keeping plaintext original", 'error');
			return false;
		}

		// Initialize HMAC context
		$hmac_ctx = hash_init('sha256', HASH_HMAC, $hmac_key);
		hash_update($hmac_ctx, $header);

		// Encrypt in chunks with counter-based IVs
		$buffer_size = 65536; // 64KB
		$chunk_index = 0;

		while (!feof($in)) {
			$plaintext = fread($in, $buffer_size);
			if (false === $plaintext || strlen($plaintext) === 0) break;

			$chunk_iv = self::chunk_iv($base_iv, $chunk_index);
			$tag = '';

			$encrypted = openssl_encrypt(
				$plaintext,
				self::$cipher,
				$derived_key,
				OPENSSL_RAW_DATA,
				$chunk_iv,
				$tag,
				'', // AAD
				self::$tag_length
			);

			if (false === $encrypted) {
				fclose($in);
				fclose($out);
				@unlink($encrypted_file);
				return false;
			}

			// Write: length prefix (4 bytes) + tag (16 bytes) + encrypted data
			$chunk_header = pack('N', strlen($encrypted)) . $tag;
			if (!self::fwrite_all($out, $chunk_header) || !self::fwrite_all($out, $encrypted)) {
				fclose($in);
				fclose($out);
				@unlink($encrypted_file);
				SiteGuard_Backup::instance()->log("Encryption failed: short write on chunk {$chunk_index} (disk full?) - keeping plaintext original", 'error');
				return false;
			}

			// Update HMAC
			hash_update($hmac_ctx, $chunk_header);
			hash_update($hmac_ctx, $encrypted);

			$chunk_index++;
		}

		// Write HMAC trailer
		$hmac = hash_final($hmac_ctx, true);
		if (!self::fwrite_all($out, $hmac)) {
			fclose($in);
			fclose($out);
			@unlink($encrypted_file);
			SiteGuard_Backup::instance()->log("Encryption failed: short write on HMAC trailer (disk full?) - keeping plaintext original", 'error');
			return false;
		}

		// Flush and verify the OS accepted all buffered bytes before we declare
		// success (the caller will unlink the plaintext original on success).
		if (false === fflush($out)) {
			fclose($in);
			fclose($out);
			@unlink($encrypted_file);
			SiteGuard_Backup::instance()->log("Encryption failed: fflush on output failed (disk full?) - keeping plaintext original", 'error');
			return false;
		}

		fclose($in);
		fclose($out);

		// Set restrictive permissions on encrypted file
		@chmod($encrypted_file, 0600);

		return $encrypted_file;
	}

	/**
	 * Decrypt a backup file.
	 * Supports both SGK2 (GCM) and legacy SGBK (CBC) formats.
	 *
	 * @param string $file Full path to the encrypted .crypt file.
	 * @param string $key  Encryption key/passphrase.
	 * @return string|false Path to decrypted file, or false on failure.
	 */
	public static function decrypt($file, $key) {
		if (!function_exists('openssl_decrypt')) {
			SiteGuard_Backup::instance()->log("OpenSSL not available for decryption", 'error');
			return false;
		}

		if (!file_exists($file)) return false;

		// Detect format by reading magic bytes
		$fh = fopen($file, 'rb');
		if (!$fh) return false;
		$magic = fread($fh, 4);
		fclose($fh);

		if ($magic === self::$magic_v2) {
			return self::decrypt_v2($file, $key);
		} elseif ($magic === self::$magic_v1) {
			return self::decrypt_v1_cbc($file, $key);
		} else {
			// Legacy format without magic header
			return self::decrypt_v1_cbc($file, $key);
		}
	}

	/**
	 * Decrypt SGK2 (AES-256-GCM) format.
	 */
	private static function decrypt_v2($file, $key) {
		// Output filename: strip .crypt extension, ensure output stays in same directory
		$dir = dirname($file) . '/';
		$base = basename($file);
		$decrypted_base = preg_replace('/\.crypt$/', '', $base);
		if ($decrypted_base === $base) {
			$decrypted_base = $base . '.decrypted';
		}
		$decrypted_file = $dir . $decrypted_base;

		// Write to temp file first, rename on success
		$tmp_file = $decrypted_file . '.tmp';

		$in = fopen($file, 'rb');
		$out = fopen($tmp_file, 'wb');

		if (!$in || !$out) {
			if ($in) fclose($in);
			if ($out) fclose($out);
			@unlink($tmp_file);
			return false;
		}

		// Read header
		$magic = fread($in, 4); // SGK2
		$version = ord(fread($in, 1));
		$salt = fread($in, 32);
		$base_iv = fread($in, 12);

		if (strlen($salt) < 32 || strlen($base_iv) < 12) {
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			return false;
		}

		$derived_key = self::derive_key($key, $salt);
		$hmac_key = hash_pbkdf2('sha256', $key, $salt . 'hmac', self::$pbkdf2_iterations, 32, true);

		// We need to verify HMAC before trusting decrypted data
		// First pass: collect all chunk data and compute HMAC
		clearstatcache(true, $file);
		$file_size = filesize($file);
		$hmac_size = 32;
		$data_end = $file_size - $hmac_size;

		// Read stored HMAC from end of file
		fseek($in, $data_end);
		$stored_hmac = fread($in, 32);

		// Compute HMAC over header + all chunks
		fseek($in, 0);
		$hmac_ctx = hash_init('sha256', HASH_HMAC, $hmac_key);
		$remaining = $data_end;
		while ($remaining > 0) {
			$read_size = min(65536, $remaining);
			$data = fread($in, $read_size);
			if (false === $data || strlen($data) === 0) break;
			hash_update($hmac_ctx, $data);
			$remaining -= strlen($data);
		}
		$computed_hmac = hash_final($hmac_ctx, true);

		if (!hash_equals($computed_hmac, $stored_hmac)) {
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			SiteGuard_Backup::instance()->log("HMAC verification failed - file may be tampered", 'error');
			return false;
		}

		// Second pass: decrypt chunks (HMAC verified, file is authentic)
		fseek($in, 4 + 1 + 32 + 12); // Skip header
		$chunk_index = 0;

		while (ftell($in) < $data_end) {
			// Read length prefix + tag
			$chunk_header = fread($in, 4 + self::$tag_length);
			if (false === $chunk_header || strlen($chunk_header) < 4 + self::$tag_length) break;

			$len = unpack('N', substr($chunk_header, 0, 4))[1];
			$tag = substr($chunk_header, 4, self::$tag_length);

			if ($len <= 0 || $len > 131072) break; // Max 128KB per chunk

			$encrypted = fread($in, $len);
			if (false === $encrypted || strlen($encrypted) < $len) break;

			$chunk_iv = self::chunk_iv($base_iv, $chunk_index);

			$decrypted = openssl_decrypt(
				$encrypted,
				self::$cipher,
				$derived_key,
				OPENSSL_RAW_DATA,
				$chunk_iv,
				$tag
			);

			if (false === $decrypted) {
				fclose($in);
				fclose($out);
				@unlink($tmp_file);
				SiteGuard_Backup::instance()->log("GCM decryption failed at chunk {$chunk_index} - wrong key or corrupted data", 'error');
				return false;
			}

			if (!self::fwrite_all($out, $decrypted)) {
				fclose($in);
				fclose($out);
				@unlink($tmp_file);
				SiteGuard_Backup::instance()->log("Decryption failed: short write to output at chunk {$chunk_index} (disk full?) - restored archive would be truncated", 'error');
				return false;
			}
			$chunk_index++;
		}

		if (false === fflush($out)) {
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			SiteGuard_Backup::instance()->log("Decryption failed: fflush on output failed (disk full?)", 'error');
			return false;
		}

		fclose($in);
		fclose($out);

		// Rename temp file to final path (with copy+unlink fallback for cross-mount)
		if (!@rename($tmp_file, $decrypted_file)) {
			if (!@copy($tmp_file, $decrypted_file)) {
				@unlink($tmp_file);
				return false;
			}
			@unlink($tmp_file);
		}

		return $decrypted_file;
	}

	/**
	 * Decrypt legacy SGBK (AES-256-CBC) format - backward compatibility only.
	 */
	private static function decrypt_v1_cbc($file, $key) {
		SiteGuard_Backup::instance()->log("WARNING: Decrypting legacy CBC-format file. Re-encrypt with current format for better security.", 'warning');
		$legacy_cipher = 'aes-256-cbc';

		// Output filename: strip .crypt extension, ensure output stays in same directory
		$dir = dirname($file) . '/';
		$base = basename($file);
		$decrypted_base = preg_replace('/\.crypt$/', '', $base);
		if ($decrypted_base === $base) {
			$decrypted_base = $base . '.decrypted';
		}
		$decrypted_file = $dir . $decrypted_base;

		// Write to temp file first, rename on success
		$tmp_file = $decrypted_file . '.tmp';

		$iv_length = openssl_cipher_iv_length($legacy_cipher);

		$in = fopen($file, 'rb');
		$out = fopen($tmp_file, 'wb');

		if (!$in || !$out) {
			if ($in) fclose($in);
			if ($out) fclose($out);
			@unlink($tmp_file);
			return false;
		}

		// Read header to detect format
		$magic = fread($in, 4);
		if ($magic === self::$magic_v1) {
			// SGBK format: magic + 32-byte salt + IV
			$salt = fread($in, 32);
			$iv = fread($in, $iv_length);
			$derived_key = self::derive_key($key, $salt);
		} else {
			// Very old format (no magic header) - insecure key derivation, reject
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			SiteGuard_Backup::instance()->log("Refusing to decrypt unknown format (no SGBK/SGK2 magic header)", 'error');
			return false;
		}

		if (strlen($iv) < $iv_length) {
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			return false;
		}

		// Decrypt chunks
		$current_iv = $iv;
		while (!feof($in)) {
			$len_data = fread($in, 4);
			if (false === $len_data || strlen($len_data) < 4) break;

			$len = unpack('N', $len_data)[1];
			if ($len <= 0 || $len > 131072) break;

			$encrypted = fread($in, $len);
			if (false === $encrypted || strlen($encrypted) < $len) break;

			$decrypted = openssl_decrypt($encrypted, $legacy_cipher, $derived_key, OPENSSL_RAW_DATA, $current_iv);
			if (false === $decrypted) {
				fclose($in);
				fclose($out);
				@unlink($tmp_file);
				SiteGuard_Backup::instance()->log("Legacy decryption failed - wrong key?", 'error');
				return false;
			}

			// Chain: next IV = last block of this ciphertext
			$block_size = openssl_cipher_iv_length($legacy_cipher);
			$current_iv = substr($encrypted, -$block_size);

			if (!self::fwrite_all($out, $decrypted)) {
				fclose($in);
				fclose($out);
				@unlink($tmp_file);
				SiteGuard_Backup::instance()->log("Legacy decryption failed: short write to output (disk full?) - restored archive would be truncated", 'error');
				return false;
			}
		}

		if (false === fflush($out)) {
			fclose($in);
			fclose($out);
			@unlink($tmp_file);
			SiteGuard_Backup::instance()->log("Legacy decryption failed: fflush on output failed (disk full?)", 'error');
			return false;
		}

		fclose($in);
		fclose($out);

		// Rename temp file to final path (with copy+unlink fallback for cross-mount)
		if (!@rename($tmp_file, $decrypted_file)) {
			if (!@copy($tmp_file, $decrypted_file)) {
				@unlink($tmp_file);
				return false;
			}
			@unlink($tmp_file);
		}

		return $decrypted_file;
	}

	/**
	 * Check if encryption is available.
	 */
	public static function is_available() {
		return function_exists('openssl_encrypt')
			&& function_exists('openssl_decrypt')
			&& in_array('aes-256-gcm', openssl_get_cipher_methods());
	}

	/**
	 * Detect if a file is encrypted.
	 *
	 * @param string $file Path to file.
	 * @return bool
	 */
	public static function is_encrypted($file) {
		if (!file_exists($file)) return false;
		if (preg_match('/\.crypt$/', $file)) return true;

		$fh = fopen($file, 'rb');
		if (!$fh) return false;
		$magic = fread($fh, 4);
		fclose($fh);

		return ($magic === self::$magic_v2 || $magic === self::$magic_v1);
	}
}
