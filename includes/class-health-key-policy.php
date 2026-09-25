<?php
/**
 * SiteGuard Backup - Health monitor key policy for restores
 *
 * Decides, once per database restore, whose health monitor key and settings the
 * restored site ends up with: this site's ("keep", the default) or the backup's
 * ("backup"). The key and its settings are one unit and are never mixed.
 *
 * Pure PHP with no WordPress calls, so the whole decision table is unit-tested
 * (tests/test-health-key-policy.php). Rows are passed as scope-prefixed names
 * ('options:<name>' for the main site's wp_options, 'sitemeta:<name>' for
 * wp_sitemeta) mapped to their raw stored values.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_Health_Key_Policy {

	const KEEP = 'keep';       // Keep this site's key (default)
	const BACKUP = 'backup';   // Use the backup's key (replaces this site's)

	const HASH = 'sgbackup_health_api_key_hash';
	const LEGACY = 'sgbackup_health_api_key';   // plaintext, stored before 1.3.13
	const META = 'sgbackup_health_api_key_meta';
	const LAST_RESTORE = 'sgbackup_health_last_restore';

	const CREDENTIALS = array(self::HASH, self::LEGACY, self::META);
	const SETTINGS = array('sgbackup_health_enabled', 'sgbackup_health_interval', 'sgbackup_health_timeout', 'sgbackup_health_ssl_warn_days');
	const UNIT = array(
		self::HASH, self::LEGACY, self::META,
		'sgbackup_health_enabled', 'sgbackup_health_interval', 'sgbackup_health_timeout', 'sgbackup_health_ssl_warn_days',
	);
	const SCOPES = array('options:', 'sitemeta:');

	/** Seconds a declined backup key can still be switched to from the Health tab. */
	const DECLINED_TTL = 604800;

	/**
	 * A valid policy value, or null. Nothing else is ever coerced to "backup".
	 */
	public static function sanitize($value) {
		if (!is_string($value)) return null;
		return in_array($value, array(self::KEEP, self::BACKUP), true) ? $value : null;
	}

	/**
	 * Whether a string looks like a password hash WordPress can check.
	 */
	public static function is_valid_hash($hash) {
		return is_string($hash) && strlen($hash) <= 255 && !preg_match('/\s/', $hash)
			&& (bool) preg_match('/^(\$wp\$2y\$|\$2y\$|\$P\$|\$argon2)/', $hash);
	}

	/**
	 * Whether a plaintext key is acceptable for --set: at least 32 letters and
	 * digits (regenerate makes 48).
	 */
	public static function is_valid_plain_key($key) {
		return is_string($key) && (bool) preg_match('/^[A-Za-z0-9]{32,256}$/', $key);
	}

	/**
	 * Public fingerprint of a plaintext key. The owner can compute it locally:
	 * printf %s "$KEY" | sha256sum | cut -c1-12
	 */
	public static function fingerprint($key) {
		return 'sgk-' . substr(hash('sha256', (string) $key), 0, 12);
	}

	public static function hint($key) {
		return substr((string) $key, -4);
	}

	/**
	 * Metadata stored next to a key hash (never the key or its hash).
	 */
	public static function build_meta($key, $source, $origin, $now) {
		return array(
			'hint'    => self::hint($key),
			'fp'      => self::fingerprint($key),
			'created' => (int) $now,
			'origin'  => (string) $origin,
			'source'  => (string) $source,
		);
	}

	/**
	 * Decode a stored meta row (raw serialized value or array) into its fields.
	 */
	public static function parse_meta($raw) {
		if (is_string($raw)) {
			$raw = ('' === $raw) ? null : @unserialize($raw, array('allowed_classes' => false));
		}
		if (!is_array($raw)) return null;
		$meta = array();
		foreach (array('hint', 'fp', 'origin', 'source') as $field) {
			$meta[$field] = (isset($raw[$field]) && is_scalar($raw[$field])) ? (string) $raw[$field] : '';
		}
		$meta['created'] = isset($raw['created']) ? (int) $raw['created'] : 0;
		if ('' !== $meta['fp'] && !preg_match('/^sgk-[0-9a-f]{12}$/', $meta['fp'])) $meta['fp'] = '';
		if (!preg_match('/^[A-Za-z0-9]{0,4}$/', $meta['hint'])) $meta['hint'] = '';
		return $meta;
	}

	/**
	 * Decide the health unit for a restore.
	 *
	 * @param string $policy   'keep' or 'backup' (anything else is 'keep').
	 * @param array  $dest     This site's unit rows before the import.
	 * @param array  $imported The unit rows the import brought in.
	 * @param array  $ctx      'hasher' => callable(plaintext): hash, 'origin' => the
	 *                         backup's host, 'site_origin' => this site's host,
	 *                         'backup_ts', 'now'.
	 * @return array source ('destination'|'backup'), final (the unit rows to end
	 *               with), write/delete (changes against $imported), outcome,
	 *               reason, report (hints and fingerprints only), declined_hash
	 *               (the backup's key hash, set only when it was not applied),
	 *               enabled_from_backup, effective_hash.
	 */
	public static function resolve($policy, array $dest, array $imported, array $ctx) {
		$policy = self::sanitize($policy);
		if (null === $policy) $policy = self::KEEP;
		$now = isset($ctx['now']) ? (int) $ctx['now'] : 0;
		$origin = isset($ctx['origin']) ? (string) $ctx['origin'] : '';
		$site_origin = isset($ctx['site_origin']) ? (string) $ctx['site_origin'] : '';

		$d = self::side($dest);
		$b = self::side($imported);

		$same_credentials = true;
		foreach (self::CREDENTIALS as $name) {
			foreach (self::SCOPES as $scope) {
				if (self::row($dest, $scope . $name) !== self::row($imported, $scope . $name)) {
					$same_credentials = false;
					break 2;
				}
			}
		}
		$same_hash = (null !== $d['hash'] && $d['hash'] === $b['hash']);

		$declined = null;
		if (!$b['has_key']) {
			$source = 'destination'; $reason = 'no_backup_key'; $outcome = 'no_backup_key';
		} elseif ($same_credentials || $same_hash) {
			$source = 'destination'; $reason = 'identical'; $outcome = 'unchanged';
		} elseif (self::BACKUP === $policy) {
			$source = 'backup'; $reason = 'policy_backup'; $outcome = 'took_backup';
		} elseif (!$d['has_key']) {
			$source = 'backup'; $reason = 'dest_has_no_key'; $outcome = 'adopted_backup';
		} else {
			$source = 'destination'; $reason = 'dest_has_key'; $outcome = 'kept_site';
			$declined = self::key_hash($b, $ctx);
		}

		// The winning side's rows. Credential rows only the other side has are
		// never kept (a backup's per-site row would hide this site's sitemeta
		// key). When this site wins, settings rows only the backup has survive,
		// as they always have, except a per-site row for a setting this site keeps
		// in wp_sitemeta: it would hide that setting the same way (a backup's
		// enabled=0 turned the kept key into 404 "disabled").
		$final = array();
		$winner = ('backup' === $source) ? $imported : $dest;
		$winner_side = ('backup' === $source) ? $b : $d;
		foreach (self::UNIT as $name) {
			foreach (self::SCOPES as $scope) {
				$v = self::row($winner, $scope . $name);
				if (null === $v && 'destination' === $source && in_array($name, self::SETTINGS, true)
					&& !('options:' === $scope && null !== self::effective($dest, $name))) {
					$v = self::row($imported, $scope . $name);
				}
				if (null !== $v && ('' !== $v || !in_array($name, self::CREDENTIALS, true))) {
					$final[$scope . $name] = $v;
				}
			}
		}

		// Legacy plaintext never survives a restore: hash it (when there is no
		// hash) and drop the plaintext row in both scopes.
		if (null !== $winner_side['legacy']) {
			if (null === $winner_side['hash']) {
				$final['options:' . self::HASH] = self::key_hash($winner_side, $ctx);
				if (null === $winner_side['meta']) {
					$final['options:' . self::META] = serialize(self::build_meta($winner_side['legacy'], 'legacy-migrated', 'backup' === $source ? $origin : $site_origin, $now));
				}
			}
			foreach (self::SCOPES as $scope) unset($final[$scope . self::LEGACY]);
		}

		$write = array();
		foreach ($final as $k => $v) {
			if (self::row($imported, $k) !== $v) $write[$k] = $v;
		}
		$delete = array();
		foreach (self::UNIT as $name) {
			foreach (self::SCOPES as $scope) {
				if (null !== self::row($imported, $scope . $name) && !isset($final[$scope . $name])) $delete[] = $scope . $name;
			}
		}

		$final_side = self::side($final);
		$enabled_from_backup = ('dest_has_no_key' === $reason)
			&& self::truthy(self::effective($final, 'sgbackup_health_enabled'))
			&& !self::truthy(self::effective($dest, 'sgbackup_health_enabled'));

		return array(
			'policy'              => $policy,
			'source'              => $source,
			'final'               => $final,
			'write'               => $write,
			'delete'              => $delete,
			'outcome'             => $outcome,
			'reason'              => $reason,
			'report'              => array(
				'site_hint'     => $d['hint'],
				'site_fp'       => $d['fp'],
				'backup_hint'   => $b['hint'],
				'backup_fp'     => $b['fp'],
				'backup_origin' => ('' !== $b['origin']) ? $b['origin'] : $origin,
			),
			'declined_hash'       => $declined,
			'enabled_from_backup' => $enabled_from_backup,
			'effective_hash'      => $final_side['hash'],
		);
	}

	/**
	 * The one line a restore reports about the monitor key (hints and
	 * fingerprints only, never a hash).
	 */
	public static function summary(array $plan) {
		$r = $plan['report'];
		$site = self::label($r['site_hint'], $r['site_fp']);
		$backup = self::label($r['backup_hint'], $r['backup_fp']);
		switch ($plan['outcome']) {
			case 'kept_site':
				$from = ('' !== $r['backup_origin']) ? ', from ' . $r['backup_origin'] : '';
				return "Health monitor key: kept this site's key ({$site}). The backup's key ({$backup}{$from}) was not applied. Health tab → \"Switch to the backup's key\" to use it.";
			case 'adopted_backup':
				return "Health monitor key: this site had no key; using the backup's key ({$backup})."
					. (!empty($plan['enabled_from_backup']) ? ' Monitor enabled from the backup (this site had no key).' : '');
			case 'took_backup':
				return "Health monitor key: using the backup's key ({$backup}) (policy: backup).";
			case 'unchanged':
				return "Health monitor key: the backup has this site's key ({$site}); unchanged.";
			default:
				return "Health monitor key: backup has no monitor key; kept this site's.";
		}
	}

	private static function label($hint, $fp) {
		if ('' === $hint && '' === $fp) return 'fingerprint unknown';
		return trim(('' !== $hint ? '…' . $hint : '') . ('' !== $fp ? ', ' . $fp : ''), ', ');
	}

	/**
	 * A row's raw value, or null when absent. Stored values are strings; anything
	 * else (a caller passing decoded values) is compared by its serialized form.
	 */
	private static function row(array $rows, $key) {
		if (!array_key_exists($key, $rows) || null === $rows[$key]) return null;
		$v = $rows[$key];
		return is_string($v) ? $v : (is_scalar($v) ? (string) $v : serialize($v));
	}

	/**
	 * The value the plugin would read: the main site's own row first, then the
	 * network (wp_sitemeta) fallback. An empty string counts as absent.
	 */
	private static function effective(array $rows, $name) {
		foreach (self::SCOPES as $scope) {
			$v = self::row($rows, $scope . $name);
			if (null !== $v && '' !== $v) return $v;
		}
		return null;
	}

	private static function truthy($v) {
		return null !== $v && '' !== $v && '0' !== $v && 'b:0;' !== $v;
	}

	/**
	 * One side's effective key. A hash wins over a legacy plaintext row (the
	 * monitor drops the plaintext when a hash exists).
	 */
	private static function side(array $rows) {
		$hash = self::effective($rows, self::HASH);
		$legacy = self::effective($rows, self::LEGACY);
		$meta = self::parse_meta(self::effective($rows, self::META));
		$hint = ''; $fp = ''; $origin = '';
		if (null === $hash && null !== $legacy) {
			$hint = self::hint($legacy);
			$fp = self::fingerprint($legacy);
		} elseif ($meta) {
			$hint = $meta['hint'];
			$fp = $meta['fp'];
		}
		if ($meta) $origin = $meta['origin'];
		return array(
			'hash'    => $hash,
			'legacy'  => $legacy,
			'meta'    => $meta,
			'has_key' => (null !== $hash || null !== $legacy),
			'hint'    => $hint,
			'fp'      => $fp,
			'origin'  => $origin,
		);
	}

	/**
	 * The hash that validates a side's key: its stored hash, else its legacy
	 * plaintext hashed in memory (the plaintext is never returned).
	 */
	private static function key_hash(array $side, array $ctx) {
		if (null !== $side['hash']) return $side['hash'];
		if (null === $side['legacy']) return null;
		return (string) call_user_func($ctx['hasher'], $side['legacy']);
	}
}
