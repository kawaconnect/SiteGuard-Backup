<?php
/**
 * SiteGuard Backup - Backblaze B2 Storage Method
 *
 * B2 is S3-compatible. This is a thin wrapper around the S3 method
 * that sets B2-specific defaults (endpoint, region, provider).
 * Uses Application Keys for S3-compatible access.
 *
 * @see https://www.backblaze.com/docs/cloud-storage-s3-compatible-api
 */

if (!defined('ABSPATH')) die('No direct access.');

if (!class_exists('SiteGuard_Backup_Method_S3')) {
	require_once dirname(__FILE__) . '/s3.php';
}

class SiteGuard_Backup_Method_B2 extends SiteGuard_Backup_Method_S3 {

	public function get_default_options() {
		return array(
			'access_key'             => '', // B2 Application Key ID (keyID)
			'secret_key'             => '', // B2 Application Key (applicationKey)
			'bucket'                 => '',
			'region'                 => 'us-west-004',
			'path'                   => '',
			'endpoint'               => '', // Auto-generated from region
			'provider'               => 'b2',
			'storage_class'          => 'STANDARD',
			'server_side_encryption' => false,
			'object_lock_days'       => 0,
		);
	}

	/**
	 * Override get_endpoint to build B2 S3-compatible endpoint from region.
	 *
	 * @return string B2 S3-compatible endpoint URL.
	 */
	protected function get_endpoint() {
		$options = $this->get_options();

		// Use custom endpoint if explicitly set
		if (!empty($options['endpoint'])) {
			$endpoint = rtrim($options['endpoint'], '/');
			// Enforce HTTPS to prevent credential exposure (same as parent S3 method)
			if (strpos($endpoint, 'https://') !== 0) {
				error_log('SiteGuard Backup: B2 custom endpoint must use HTTPS. Got: ' . $endpoint);
				$endpoint = str_replace('http://', 'https://', $endpoint);
			}
			return $endpoint;
		}

		$region = !empty($options['region']) ? $options['region'] : 'us-west-004';
		return "https://s3.{$region}.backblazeb2.com";
	}

	/**
	 * Override storage headers for B2-specific features.
	 */
	protected function get_storage_headers() {
		$opts = $this->get_options();
		$extra_headers = array();

		// B2 Object Lock (immutable backups)
		$lock_days = isset($opts['object_lock_days']) ? intval($opts['object_lock_days']) : 0;
		if ($lock_days > 0) {
			$retain_until = gmdate('Y-m-d\TH:i:s\Z', time() + ($lock_days * 86400));
			$extra_headers['x-amz-object-lock-mode'] = 'GOVERNANCE';
			$extra_headers['x-amz-object-lock-retain-until-date'] = $retain_until;
		}

		$sse = isset($opts['server_side_encryption']) ? $opts['server_side_encryption'] : false;
		if ($sse) {
			$extra_headers['x-amz-server-side-encryption'] = 'AES256';
		}

		return $extra_headers;
	}
}
