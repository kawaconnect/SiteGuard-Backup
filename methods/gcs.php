<?php
/**
 * SiteGuard Backup - Google Cloud Storage Method
 *
 * GCS is S3-compatible. This is a thin wrapper around the S3 method
 * that sets GCS-specific defaults (endpoint, provider).
 * Uses HMAC keys (not OAuth) for S3-compatible access.
 *
 * @see https://cloud.google.com/storage/docs/interoperability
 */

if (!defined('ABSPATH')) die('No direct access.');

if (!class_exists('SiteGuard_Backup_Method_S3')) {
	require_once dirname(__FILE__) . '/s3.php';
}

class SiteGuard_Backup_Method_Gcs extends SiteGuard_Backup_Method_S3 {

	public function get_default_options() {
		return array(
			'access_key'             => '',
			'secret_key'             => '',
			'bucket'                 => '',
			'region'                 => 'auto',
			'path'                   => '',
			'endpoint'               => 'https://storage.googleapis.com',
			'provider'               => 'gcs',
			'storage_class'          => 'STANDARD', // STANDARD, NEARLINE, COLDLINE, ARCHIVE
			'server_side_encryption' => false,
			'project_id'             => '',
			'location'               => '',
		);
	}

	/**
	 * Override get_storage_headers to add GCS-specific storage class header.
	 *
	 * @return array Extra headers for storage operations.
	 */
	protected function get_storage_headers() {
		$opts = $this->get_options();
		$extra_headers = array();

		$storage_class = isset($opts['storage_class']) ? $opts['storage_class'] : 'STANDARD';
		if ($storage_class !== 'STANDARD') {
			$extra_headers['x-goog-storage-class'] = $storage_class;
		}

		$sse = isset($opts['server_side_encryption']) ? $opts['server_side_encryption'] : false;
		if ($sse) {
			$extra_headers['x-amz-server-side-encryption'] = 'AES256';
		}

		return $extra_headers;
	}

	/**
	 * Override get_endpoint to always return the GCS S3-compatible endpoint.
	 *
	 * @return string GCS S3-compatible endpoint URL.
	 */
	protected function get_endpoint() {
		return 'https://storage.googleapis.com';
	}
}
