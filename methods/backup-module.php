<?php
/**
 * SiteGuard Backup - Abstract Storage Module
 *
 * Base class for all remote storage backends.
 */

if (!defined('ABSPATH')) die('No direct access.');

abstract class SiteGuard_Backup_Module {

	/** @var string Method identifier */
	protected $method_id;

	/** @var string Instance ID for multi-instance support */
	protected $instance_id = '';

	/** @var array Cached options */
	protected $options = null;

	/** @var object Storage client object */
	protected $storage = null;

	public function __construct() {
		// Derive method_id from class name: SiteGuard_Backup_Method_S3 -> s3
		$class = get_class($this);
		$this->method_id = strtolower(str_replace('SiteGuard_Backup_Method_', '', $class));
	}

	/**
	 * Get the method identifier.
	 */
	public function get_id() {
		return $this->method_id;
	}

	/**
	 * Get the display name.
	 */
	public function get_description() {
		$methods = SiteGuard_Backup::instance()->get_backup_methods();
		return isset($methods[$this->method_id])
			? $methods[$this->method_id]
			: ucfirst($this->method_id);
	}

	/**
	 * Set instance ID for multi-instance configurations.
	 */
	public function set_instance_id($id) {
		$this->instance_id = $id;
		$this->options = null; // Reset cached options
	}

	/**
	 * Get method options from database.
	 * Automatically decrypts credential fields when credential encryption is enabled.
	 */
	public function get_options() {
		if (null !== $this->options) return $this->options;

		$opts = SiteGuard_Backup_Options::instance();
		$stored = $opts->get_option('sgbackup_' . $this->method_id, array());

		// Multi-instance format
		if (isset($stored['settings']) && is_array($stored['settings'])) {
			$instance = $this->instance_id ?: 's-0';
			$this->options = isset($stored['settings'][$instance]) && is_array($stored['settings'][$instance])
				? wp_parse_args($stored['settings'][$instance], $this->get_default_options())
				: $this->get_default_options();
		} else {
			$this->options = wp_parse_args($stored, $this->get_default_options());
		}

		// Transparently decrypt any encrypted credential fields
		if ($opts->get_option('sgbackup_encrypt_credentials', false)) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			$this->options = SiteGuard_Backup_Security::decrypt_method_options($this->method_id, $this->options);
		}

		return $this->options;
	}

	/**
	 * Save method options to database.
	 */
	public function save_options($options) {
		$opts = SiteGuard_Backup_Options::instance();
		$this->options = $options;

		// Store in multi-instance format
		$stored = $opts->get_option('sgbackup_' . $this->method_id, array());
		$instance = $this->instance_id ?: 's-0';

		if (!isset($stored['settings'])) {
			$stored = array('version' => 1, 'settings' => array());
		}
		// get_options() hands back DECRYPTED credentials; re-encrypt before storing
		// (token refreshes and the SFTP TOFU fingerprint save used to write them in
		// plaintext, silently defeating "encrypt credentials at rest").
		$to_store = $options;
		if ($opts->get_option('sgbackup_encrypt_credentials', false)) {
			require_once SGBACKUP_DIR . 'includes/class-security.php';
			$to_store = SiteGuard_Backup_Security::encrypt_method_options($this->method_id, $to_store);
		}
		$stored['settings'][$instance] = $to_store;
		$opts->update_option('sgbackup_' . $this->method_id, $stored);
	}

	/**
	 * Get default options. Override in child classes.
	 */
	public function get_default_options() {
		return array();
	}

	/**
	 * Get supported features.
	 */
	public function get_supported_features() {
		return array('multi_options');
	}

	/**
	 * Check if a feature is supported.
	 */
	public function supports_feature($feature) {
		return in_array($feature, $this->get_supported_features());
	}

	/**
	 * Set the storage client object.
	 */
	public function set_storage($storage) {
		$this->storage = $storage;
	}

	/**
	 * Get the storage client object.
	 */
	public function get_storage() {
		return $this->storage;
	}

	// ===== Abstract methods - must be implemented by child classes =====

	/**
	 * Upload a backup file.
	 *
	 * @param string $file Full path to the file to upload.
	 * @return bool|WP_Error
	 */
	abstract public function backup($file);

	/**
	 * Download a backup file from remote storage.
	 *
	 * @param string $file     Filename to download.
	 * @param string $dest_dir Local destination directory.
	 * @return bool|WP_Error
	 */
	abstract public function download($file, $dest_dir);

	/**
	 * Delete file(s) from remote storage.
	 *
	 * @param array|string $files Filename(s) to delete.
	 * @return bool|WP_Error
	 */
	abstract public function delete($files);

	/**
	 * Local path a download of remote file $name is streamed to.
	 */
	protected function local_download_path($dest_dir, $name) {
		return trailingslashit($dest_dir) . basename((string) $name);
	}

	/**
	 * Confirm a streamed download is complete. A transport error or proxy can end
	 * the stream early and leave a truncated archive that would later fail to
	 * restore; the partial file is removed.
	 *
	 * @param string   $path     Local file.
	 * @param int|null $expected Content-Length announced by the server, if any.
	 * @param string   $label    Provider name for messages.
	 * @return true|WP_Error
	 */
	protected function verify_download($path, $expected, $label) {
		clearstatcache(true, $path);
		$size = file_exists($path) ? filesize($path) : false;
		if (false === $size || 0 === $size) {
			if (file_exists($path)) @unlink($path);
			return new WP_Error('download_empty', "{$label} download produced no data");
		}
		if (null !== $expected && (int) $size !== (int) $expected) {
			@unlink($path);
			return new WP_Error('download_truncated', sprintf('%s download incomplete: got %d of %d bytes', $label, $size, $expected));
		}
		return true;
	}

	/**
	 * Only plain file names are ever deleted remotely.
	 *
	 * An empty name (or "." / "..") turns "<folder>/<name>" into the backup folder
	 * itself, and several providers delete folders recursively (OneDrive,
	 * Dropbox, WebDAV collections): one bad name would wipe every remote backup.
	 *
	 * @param mixed $files
	 * @return string[]
	 */
	protected function safe_remote_names($files) {
		$out = array();
		foreach ((array) $files as $f) {
			if (!is_string($f)) continue;
			$f = trim($f);
			if ('' === $f || '.' === $f || '..' === $f || basename($f) !== $f
				|| preg_match('/[\x00-\x1f\\\\]/', $f)) {
				error_log('SiteGuard Backup: refusing remote delete of unsafe name: ' . json_encode($f));
				continue;
			}
			$out[] = $f;
		}
		return $out;
	}

	// ===== Optional methods =====

	/**
	 * List files in remote storage.
	 *
	 * @return array List of filenames.
	 */
	public function listfiles() {
		return array();
	}

	/**
	 * Test credentials.
	 *
	 * @return bool|WP_Error
	 */
	public function credentials_test() {
		return new WP_Error('not_implemented', 'Credentials test not implemented for this method.');
	}

	/**
	 * Chunked upload helper for large files.
	 *
	 * @param string   $file       Full path to file.
	 * @param callable $chunk_send Callback that sends a chunk. Args: ($chunk_data, $offset, $total_size).
	 * @param int      $chunk_size Bytes per chunk.
	 * @return bool
	 */
	protected function chunked_upload($file, $chunk_send, $chunk_size = 2097152) {
		$total_size = filesize($file);
		$fh = fopen($file, 'rb');
		if (!$fh) return false;

		$offset = 0;
		$sgbackup = SiteGuard_Backup::instance();

		while ($offset < $total_size) {
			$chunk = fread($fh, $chunk_size);
			if (false === $chunk) {
				fclose($fh);
				return false;
			}

			$result = call_user_func($chunk_send, $chunk, $offset, $total_size);
			if (is_wp_error($result) || false === $result) {
				fclose($fh);
				return false;
			}

			$offset += strlen($chunk);
			$sgbackup->log("Uploaded " . SiteGuard_Backup_Filesystem::format_bytes($offset) . " / " .
				SiteGuard_Backup_Filesystem::format_bytes($total_size));

			SiteGuard_Backup_Job_Scheduler::something_useful_happened();
		}

		fclose($fh);
		return true;
	}

	/**
	 * Determine whether a chunk upload response warrants a retry.
	 *
	 * Retries on transport errors and HTTP 429/5xx. WP_Error values that were
	 * derived from an HTTP status embed the code in their message (e.g.
	 * "S3 error (HTTP 503): ..."); those are retried only for 429/5xx, while
	 * WP_Error values without an HTTP code (pure transport/cURL failures) are
	 * always retryable.
	 *
	 * @param array|WP_Error $response wp_remote_request() response or error.
	 * @return bool Whether the request should be retried.
	 */
	protected function should_retry_response($response) {
		if (is_wp_error($response)) {
			if (preg_match('/HTTP (\d{3})/', $response->get_error_message(), $m)) {
				$code = (int) $m[1];
				return (429 === $code || $code >= 500);
			}
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		return (429 === $code || $code >= 500);
	}

	/**
	 * Sleep before a retry attempt using exponential backoff (2s, 4s, 8s).
	 *
	 * @param int    $attempt Attempt number that just failed (1-based).
	 * @param string $context Description of the operation for the log entry.
	 * @param string $detail  Failure detail for the log entry.
	 */
	protected function retry_sleep($attempt, $context, $detail = '') {
		$delay = (int) pow(2, $attempt); // 2, 4, 8 seconds
		SiteGuard_Backup::instance()->log(
			"{$context}: attempt {$attempt} failed" . ('' !== $detail ? " ({$detail})" : '') .
			" - retrying in {$delay}s", 'warning'
		);

		if (class_exists('SiteGuard_Backup_Job_Scheduler')) {
			SiteGuard_Backup_Job_Scheduler::record_still_alive();
		}

		sleep($delay);
	}

	/**
	 * Execute a chunk upload request with per-chunk retries.
	 *
	 * Runs the callback up to 4 times, retrying only when
	 * should_retry_response() says the failure is transient (transport
	 * error or HTTP 429/5xx). Returns the final response (or WP_Error) so
	 * callers keep their existing error handling.
	 *
	 * @param callable $do_request Callback performing the request. No args.
	 * @param string   $context    Description of the operation for logging.
	 * @return array|WP_Error Final response or error.
	 */
	protected function request_with_retries($do_request, $context) {
		$max_attempts = 4;
		$response = null;

		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
			$response = call_user_func($do_request);

			if (!$this->should_retry_response($response) || $attempt === $max_attempts) {
				return $response;
			}

			$detail = is_wp_error($response)
				? $response->get_error_message()
				: 'HTTP ' . wp_remote_retrieve_response_code($response);
			$this->retry_sleep($attempt, $context, $detail);
		}

		return $response;
	}

	/**
	 * Get the instance label from options, defaulting to the method description.
	 *
	 * @return string
	 */
	public function get_instance_label() {
		$opts = SiteGuard_Backup_Options::instance();
		$stored = $opts->get_option('sgbackup_' . $this->method_id, array());
		$instance = $this->instance_id ?: 's-0';

		if (isset($stored['settings'][$instance]['instance_label'])) {
			$label = $stored['settings'][$instance]['instance_label'];
			if (!empty($label)) {
				return $label;
			}
		}

		return $this->get_description();
	}

	/**
	 * Evaluate conditional logic rules for a storage instance.
	 *
	 * Returns true (send) if no rules are defined, or if the rules pass.
	 * Supports 'any' (OR) and 'all' (AND) logic types.
	 *
	 * @param array $instance_opts Instance options containing conditional_logic.
	 * @return bool Whether the instance should receive the backup.
	 */
	public function evaluate_conditional_logic($instance_opts) {
		if (empty($instance_opts['conditional_logic']['rules'])) return true;

		$logic = $instance_opts['conditional_logic'];
		$logic_type = isset($logic['type']) ? $logic['type'] : 'any';

		$results = array();
		foreach ($logic['rules'] as $rule) {
			$result = $this->evaluate_rule($rule);
			$results[] = $result;
		}

		if (empty($results)) return true;

		return ($logic_type === 'all') ? !in_array(false, $results, true) : in_array(true, $results, true);
	}

	/**
	 * Evaluate a single conditional logic rule.
	 *
	 * Supported operands: day_of_week (0=Sunday), day_of_month (1-31).
	 *
	 * @param array $rule Rule with 'operand', 'operator', 'value' keys.
	 * @return bool Whether the rule passes.
	 */
	private function evaluate_rule($rule) {
		if (empty($rule['operand']) || !isset($rule['operator']) || !isset($rule['value'])) {
			return true;
		}

		$current_dow = (int) current_time('w'); // 0=Sunday
		$current_dom = (int) current_time('j'); // 1-31

		switch ($rule['operand']) {
			case 'day_of_week':
				return $this->compare($current_dow, $rule['operator'], (int) $rule['value']);
			case 'day_of_month':
				return $this->compare($current_dom, $rule['operator'], (int) $rule['value']);
			default:
				return true;
		}
	}

	/**
	 * Generic comparison helper.
	 *
	 * @param mixed  $a  Left operand.
	 * @param string $op Comparison operator.
	 * @param mixed  $b  Right operand.
	 * @return bool
	 */
	private function compare($a, $op, $b) {
		switch ($op) {
			case '==': return $a == $b;
			case '!=': return $a != $b;
			case '>':  return $a > $b;
			case '<':  return $a < $b;
			case '>=': return $a >= $b;
			case '<=': return $a <= $b;
			default: return true;
		}
	}

	/**
	 * Get HTML for settings form fields.
	 */
	public function get_configuration_template() {
		return '<p>' . esc_html__('No configuration available.', 'siteguardbackup') . '</p>';
	}

	/**
	 * Get HTML for test button.
	 */
	public function get_test_button_html() {
		return sprintf(
			'<button type="button" class="button sgbackup-test-method" data-method="%s">%s</button>',
			esc_attr($this->method_id),
			esc_html__('Test Connection', 'siteguardbackup')
		);
	}
}
