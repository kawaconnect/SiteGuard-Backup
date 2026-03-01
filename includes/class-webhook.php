<?php
/**
 * SiteGuard Backup - Webhook Notifications
 *
 * Dispatches JSON webhook notifications for backup/restore lifecycle events.
 * Configure via Settings > SiteGuard Backup > Webhook Notifications.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Webhook {

	/** @var string Option key for webhook URL */
	const URL_KEY = 'sgbackup_webhook_url';

	/** @var string Option key for enabled events */
	const EVENTS_KEY = 'sgbackup_webhook_events';

	/** @var SiteGuard_Backup_Webhook */
	private static $instance = null;

	/** @var string Cached URL */
	private $url = null;

	/** @var array Cached events list */
	private $events = null;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get the configured webhook URL.
	 *
	 * @return string
	 */
	private function get_url() {
		if (null === $this->url) {
			$opts = SiteGuard_Backup_Options::instance();
			$this->url = (string) $opts->get_option(self::URL_KEY, '');
		}
		return $this->url;
	}

	/**
	 * Get the list of enabled event types.
	 *
	 * @return array
	 */
	private function get_events() {
		if (null === $this->events) {
			$opts = SiteGuard_Backup_Options::instance();
			$this->events = (array) $opts->get_option(self::EVENTS_KEY, array());
		}
		return $this->events;
	}

	/**
	 * Fire a webhook event.
	 *
	 * Returns early if URL is empty or the event is not in the enabled list.
	 * Uses non-blocking HTTP to avoid delaying backup/restore operations.
	 *
	 * @param string $event Event name (e.g. 'backup_complete').
	 * @param array  $data  Optional event-specific data.
	 * @return bool True if dispatched, false if skipped.
	 */
	public static function fire($event, $data = array()) {
		$self = self::instance();
		$url = $self->get_url();

		if (empty($url)) {
			return false;
		}

		$events = $self->get_events();
		if (!in_array($event, $events, true)) {
			return false;
		}

		$payload = array(
			'event'    => $event,
			'site_url' => get_site_url(),
			'timestamp' => time(),
			'data'     => $data,
		);

		$body = wp_json_encode($payload);
		$signature = hash_hmac('sha256', $body, $url);

		wp_remote_post($url, array(
			'timeout'  => 15,
			'blocking' => false,
			'headers'  => array(
				'Content-Type'         => 'application/json',
				'X-SiteGuard-Event'    => $event,
				'X-SiteGuard-Signature' => $signature,
			),
			'body'     => $body,
		));

		return true;
	}

	/**
	 * Send a test webhook (blocking) and return result with HTTP status.
	 *
	 * @param string $url URL to send test to.
	 * @return array Array with 'success', 'message', and 'http_code' keys.
	 */
	public static function send_test($url) {
		if (empty($url)) {
			return array('success' => false, 'message' => 'No webhook URL provided.', 'http_code' => 0);
		}

		$url = esc_url_raw($url, array('https', 'http'));
		if (empty($url)) {
			return array('success' => false, 'message' => 'Invalid URL.', 'http_code' => 0);
		}

		$payload = array(
			'event'    => 'test',
			'site_url' => get_site_url(),
			'timestamp' => time(),
			'data'     => array('message' => 'This is a test webhook from SiteGuard Backup.'),
		);

		$body = wp_json_encode($payload);
		$signature = hash_hmac('sha256', $body, $url);

		$response = wp_remote_post($url, array(
			'timeout'  => 15,
			'blocking' => true,
			'headers'  => array(
				'Content-Type'         => 'application/json',
				'X-SiteGuard-Event'    => 'test',
				'X-SiteGuard-Signature' => $signature,
			),
			'body'     => $body,
		));

		if (is_wp_error($response)) {
			return array(
				'success'   => false,
				'message'   => $response->get_error_message(),
				'http_code' => 0,
			);
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$success = $http_code >= 200 && $http_code < 300;

		return array(
			'success'   => $success,
			'message'   => $success ? 'Webhook delivered successfully.' : 'Webhook returned HTTP ' . $http_code . '.',
			'http_code' => $http_code,
		);
	}
}
