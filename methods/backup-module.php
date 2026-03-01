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
	 */
	public function get_options() {
		if (null !== $this->options) return $this->options;

		$opts = SiteGuard_Backup_Options::instance();
		$stored = $opts->get_option('sgbackup_' . $this->method_id, array());

		// Multi-instance format
		if (isset($stored['settings']) && is_array($stored['settings'])) {
			$instance = $this->instance_id ?: 's-0';
			$this->options = isset($stored['settings'][$instance])
				? $stored['settings'][$instance]
				: $this->get_default_options();
		} else {
			$this->options = wp_parse_args($stored, $this->get_default_options());
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
		$stored['settings'][$instance] = $options;
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
