<?php
/**
 * SiteGuard Backup - S3-Compatible Generic Storage Method
 *
 * A minimal wrapper around the S3 method for generic S3-compatible services.
 * Removes the provider field and makes endpoint required.
 * Adds signature_version option (v2/v4).
 */

if (!defined('ABSPATH')) die('No direct access.');

if (!class_exists('SiteGuard_Backup_Method_S3')) {
	require_once dirname(__FILE__) . '/s3.php';
}

class SiteGuard_Backup_Method_S3generic extends SiteGuard_Backup_Method_S3 {

	/** @var bool Whether the signature_version check has already run */
	private $sigv2_checked = false;

	public function get_id() {
		return 's3generic';
	}

	/**
	 * Warn once when the unsupported SigV2 signature version is configured.
	 *
	 * The 'v2' option value is accepted for backwards compatibility but only
	 * SigV4 signing is implemented, so make that explicit in the log rather
	 * than silently misbehaving.
	 */
	private function maybe_warn_sigv2() {
		if ($this->sigv2_checked) return;
		$this->sigv2_checked = true;

		$options = $this->get_options();
		if (isset($options['signature_version']) && 'v2' === $options['signature_version']) {
			SiteGuard_Backup::instance()->log(
				'S3-generic: SigV2 is not supported; signing with SigV4. Set signature_version to v4 to silence this warning.',
				'warning'
			);
		}
	}

	public function backup($file) {
		$this->maybe_warn_sigv2();
		return parent::backup($file);
	}

	public function credentials_test() {
		$this->maybe_warn_sigv2();
		return parent::credentials_test();
	}

	public function get_default_options() {
		return array(
			'access_key'             => '',
			'secret_key'             => '',
			'bucket'                 => '',
			'region'                 => 'us-east-1',
			'path'                   => '',
			'endpoint'               => '', // Required for generic S3-compatible services
			'storage_class'          => 'STANDARD',
			'server_side_encryption' => false,
			'signature_version'      => 'v4', // v2 or v4
		);
	}

	/**
	 * Override get_endpoint to require a custom endpoint.
	 *
	 * @return string Endpoint URL.
	 */
	protected function get_endpoint() {
		$options = $this->get_options();
		if (!empty($options['endpoint'])) {
			$endpoint = rtrim($options['endpoint'], '/');
			// Enforce HTTPS to prevent credential exposure
			// Case-insensitive ("HTTP://host" stayed cleartext), and a bare host
			// gets the scheme added instead of failing every request.
			if (0 !== stripos($endpoint, 'https://')) {
				$endpoint = 'https://' . preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $endpoint);
			}
			return $endpoint;
		}

		// Fallback: use region-based AWS endpoint, but this method expects a custom endpoint
		$region = $options['region'] ?: 'us-east-1';
		return "https://s3.{$region}.amazonaws.com";
	}
}
