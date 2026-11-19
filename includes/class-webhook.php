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

	/** @var string Option key for webhook signing secret */
	const SECRET_KEY = 'sgbackup_webhook_secret';

	/** @var string Option key for enabled events */
	const EVENTS_KEY = 'sgbackup_webhook_events';

	/** @var SiteGuard_Backup_Webhook */
	private static $instance = null;

	/** @var string Cached URL */
	private $url = null;

	/** @var string Cached signing secret */
	private $secret = null;

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
	 * Get the webhook signing secret. Auto-generates one if not set.
	 *
	 * @return string
	 */
	private function get_secret() {
		if (null === $this->secret) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			$opts = SiteGuard_Backup_Options::instance();
			$stored = (string) $opts->get_option(self::SECRET_KEY, '');
			if (SiteGuard_Backup_Security::is_unreadable($stored)) {
				// Encrypted with another AUTH_KEY. Do not replace it with a new
				// secret (receivers would reject every payload and the old one
				// would be lost); signing is impossible until it is readable or
				// regenerated in the settings.
				SiteGuard_Backup_Security::decrypt_option($stored); // flags the admin notice
				$this->secret = '';
				return $this->secret;
			}
			$this->secret = (string) SiteGuard_Backup_Security::decrypt_option($stored);
			if ('' === $this->secret) {
				$this->secret = wp_generate_password(40, false);
				$opts->update_sensitive_option('webhook_secret', $this->secret);
			}
		}
		return $this->secret;
	}

	/**
	 * The signing secret for the settings page (shown on request only).
	 *
	 * @return string '' when it cannot be decrypted.
	 */
	public static function reveal_secret() {
		return self::instance()->get_secret();
	}

	/**
	 * Replace the signing secret with a new random one and return it.
	 *
	 * @return string
	 */
	public static function regenerate_secret() {
		$self = self::instance();
		$self->secret = wp_generate_password(40, false);
		SiteGuard_Backup_Options::instance()->update_sensitive_option('webhook_secret', $self->secret);
		return $self->secret;
	}

	/**
	 * The webhook URL with its path and query hidden (they often carry a token).
	 *
	 * @param string $url
	 * @return string e.g. "https://hooks.example.com/****", '' for no URL.
	 */
	public static function mask_url($url) {
		$url = (string) $url;
		if ('' === $url) return '';
		$parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
		if (!is_array($parts) || empty($parts['host'])) return '****';
		$scheme = !empty($parts['scheme']) ? $parts['scheme'] . '://' : '';
		$port = !empty($parts['port']) ? ':' . (int) $parts['port'] : '';
		return $scheme . $parts['host'] . $port . '/****';
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
		$secret = $self->get_secret();
		if ('' === $secret) {
			if (class_exists('SiteGuard_Backup')) {
				SiteGuard_Backup::instance()->log('Webhook not sent: its signing secret cannot be decrypted (AUTH_KEY changed). Regenerate it in SiteGuard Backup settings.', 'warning');
			}
			return false;
		}
		$signature = hash_hmac('sha256', $body, $secret);

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
		$self = self::instance();

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
		$secret = $self->get_secret();
		if ('' === $secret) {
			return array('success' => false, 'message' => 'The signing secret cannot be decrypted (AUTH_KEY changed). Regenerate it first.', 'http_code' => 0);
		}
		$signature = hash_hmac('sha256', $body, $secret);

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
