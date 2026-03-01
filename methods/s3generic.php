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

	public function get_id() {
		return 's3generic';
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
			if (strpos($endpoint, 'https://') !== 0) {
				error_log('SiteGuard Backup: S3-generic endpoint must use HTTPS. Got: ' . $endpoint);
				$endpoint = str_replace('http://', 'https://', $endpoint);
			}
			return $endpoint;
		}

		// Fallback: use region-based AWS endpoint, but this method expects a custom endpoint
		$region = $options['region'] ?: 'us-east-1';
		return "https://s3.{$region}.amazonaws.com";
	}
}
