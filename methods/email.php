<?php
/**
 * SiteGuard Backup - Email Storage Method
 *
 * Sends backup files via wp_mail() as attachments.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Method_Email extends SiteGuard_Backup_Module {

	public function get_default_options() {
		return array('address' => get_option('admin_email'));
	}

	public function backup($file) {
		$options = $this->get_options();
		$address = !empty($options['address']) ? $options['address'] : get_option('admin_email');

		if (empty($address) || !is_email($address)) {
			return new WP_Error('no_email', 'No valid email address configured.');
		}

		$filesize = filesize($file);
		$filename = basename($file);

		// Warn if file is very large
		if ($filesize > SGBACKUP_WARN_EMAIL_SIZE) {
			SiteGuard_Backup::instance()->log(
				"File {$filename} is " . SiteGuard_Backup_Filesystem::format_bytes($filesize) .
				" - may exceed email limits", 'warning'
			);
		}

		$site_name = get_bloginfo('name');
		$subject = sprintf('[SiteGuard Backup] %s - %s', $site_name, $filename);
		$body = sprintf(
			"Backup file: %s\nSize: %s\nDate: %s\nSite: %s",
			$filename,
			SiteGuard_Backup_Filesystem::format_bytes($filesize),
			gmdate('Y-m-d H:i:s'),
			site_url()
		);

		$result = wp_mail($address, $subject, $body, array(), array($file));

		if (!$result) {
			return new WP_Error('email_failed', "Failed to send email with attachment: {$filename}");
		}

		return true;
	}

	public function download($file, $dest_dir) {
		return new WP_Error('not_supported', 'Cannot download from email storage.');
	}

	public function delete($files) {
		// Email attachments cannot be deleted
		return true;
	}

	public function credentials_test() {
		$options = $this->get_options();
		$address = !empty($options['address']) ? $options['address'] : get_option('admin_email');

		$result = wp_mail(
			$address,
			'[SiteGuard Backup] Test email',
			'This is a test email from SiteGuard Backup to verify email delivery is working.'
		);

		return $result ? true : new WP_Error('test_failed', 'Failed to send test email.');
	}
}
