<?php
/**
 * SiteGuard Backup - Migration Orchestration
 *
 * Handles URL rewriting during migration restores, single-to-multisite import,
 * and post-restore cache plugin deactivation.
 */

if (!defined('ABSPATH')) die('No direct access.');

/**
 * ⚠ DEAD CODE - DO NOT USE WITHOUT FIXING FIRST.
 *
 * SiteGuard_Backup_Migrator is not instantiated anywhere in the plugin (confirmed
 * by grep 2026-07). The live migration URL-rewriting path is restorer.php's
 * do_search_replace(); this class has diverged from it and carries latent bugs:
 *
 *   1. get_search_replace_arrays() does NOT order the search/replace pairs
 *      longest-needle-first. Because siteurl/home/content/uploads URLs are often
 *      prefixes of one another (https://x.com  vs  https://x.com/wp-content), a
 *      shorter needle can fire first and leave a mangled, partially-rewritten URL
 *      that the longer needle then no longer matches.
 *   2. The protocol-variant logic only adds the opposite-scheme variant of the
 *      siteurl and can emit a same-scheme (http->http / https->https) no-op pair,
 *      and does not apply the same variant handling to home/content/uploads.
 *
 * If this class is ever wired up, fix both (sort pairs by descending strlen of the
 * search needle; skip pairs whose search === replace) and re-verify against
 * restorer.php before relying on it.
 */
class SiteGuard_Backup_Migrator {

	/** @var bool Whether this restore is a migration (URL change detected) */
	private $is_migration = false;

	/** @var string Source site URL */
	private $old_siteurl = '';
	private $old_home = '';
	private $old_content = '';
	private $old_uploads = '';
	private $old_abspath = '';
	private $old_table_prefix = '';

	/** @var int New blog ID for single-to-multisite import */
	private $new_blogid = 0;

	/**
	 * Record old URLs from the backup source.
	 */
	public function record_old_urls($old_siteurl, $old_home = '', $old_content = '', $old_uploads = '', $old_abspath = '') {
		$this->old_siteurl = esc_url_raw($old_siteurl);
		$this->old_home = $old_home ? esc_url_raw($old_home) : $this->old_siteurl;
		$this->old_content = $old_content ? esc_url_raw($old_content) : '';
		$this->old_uploads = $old_uploads ? esc_url_raw($old_uploads) : '';
		$this->old_abspath = $old_abspath;

		// Detect migration
		$current_siteurl = get_option('siteurl');
		if (!empty($this->old_siteurl) && $this->old_siteurl !== $current_siteurl) {
			$this->is_migration = true;
		}
	}

	/**
	 * Check if this restore involves a site migration.
	 */
	public function is_migration() {
		return $this->is_migration;
	}

	/**
	 * Get the search-replace arrays for migration URL rewriting.
	 *
	 * @return array ['search' => [...], 'replace' => [...]]
	 */
	public function get_search_replace_arrays() {
		$search = array();
		$replace = array();

		$current_siteurl = get_option('siteurl');
		$current_home = get_option('home');
		$current_content = content_url();
		$upload_dir = wp_upload_dir();
		$current_uploads = $upload_dir['baseurl'];

		// Siteurl
		if (!empty($this->old_siteurl) && $this->old_siteurl !== $current_siteurl) {
			$search[] = $this->old_siteurl;
			$replace[] = $current_siteurl;

			// Also handle protocol variants
			$old_parsed = wp_parse_url($this->old_siteurl);
			$new_parsed = wp_parse_url($current_siteurl);
			if ($old_parsed && $new_parsed && $old_parsed['scheme'] !== $new_parsed['scheme']) {
				// Add opposite protocol variant
				$alt_old = str_replace($old_parsed['scheme'] . '://', $new_parsed['scheme'] . '://', $this->old_siteurl);
				if ($alt_old !== $current_siteurl && !in_array($alt_old, $search)) {
					$search[] = $alt_old;
					$replace[] = $current_siteurl;
				}
			}
		}

		// Home URL (if different from siteurl)
		if (!empty($this->old_home) && $this->old_home !== $current_home && $this->old_home !== $this->old_siteurl) {
			$search[] = $this->old_home;
			$replace[] = $current_home;
		}

		// Content URL
		if (!empty($this->old_content) && $this->old_content !== $current_content) {
			$search[] = $this->old_content;
			$replace[] = $current_content;
		}

		// Uploads URL
		if (!empty($this->old_uploads) && $this->old_uploads !== $current_uploads) {
			$search[] = $this->old_uploads;
			$replace[] = $current_uploads;
		}

		// File paths (ABSPATH)
		if (!empty($this->old_abspath) && $this->old_abspath !== ABSPATH) {
			$search[] = $this->old_abspath;
			$replace[] = ABSPATH;
		}

		return array('search' => $search, 'replace' => $replace);
	}

	/**
	 * Import a single-site backup into a multisite as a new blog.
	 *
	 * @param string $domain  Domain for the new blog.
	 * @param string $path    Path for the new blog (default '/').
	 * @param string $title   Blog title.
	 * @return int|WP_Error   New blog ID.
	 */
	public function create_blog_for_import($domain, $path = '/', $title = '') {
		if (!is_multisite()) {
			return new WP_Error('not_multisite', 'Can only create blogs on multisite installations.');
		}

		if (!function_exists('wpmu_create_blog')) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			$admin_user = get_user_by('id', 1);
			if ($admin_user && user_can($admin_user, 'manage_options')) {
				$user_id = 1;
			} else {
				// Find first admin user
				$admins = get_users(array('role' => 'administrator', 'number' => 1));
				$user_id = !empty($admins) ? $admins[0]->ID : 0;
			}
		}

		$blog_id = wpmu_create_blog($domain, $path, $title, $user_id);

		if (is_wp_error($blog_id)) {
			return $blog_id;
		}

		$this->new_blogid = $blog_id;
		return $blog_id;
	}

	/**
	 * Adjust table prefix for a multisite blog import.
	 *
	 * @param string $old_prefix The original table prefix.
	 * @param int    $blog_id    The target blog ID.
	 * @return string The new table prefix.
	 */
	public function get_blog_table_prefix($old_prefix, $blog_id) {
		global $wpdb;

		if ($blog_id <= 1) {
			return $wpdb->prefix;
		}

		return $wpdb->base_prefix . $blog_id . '_';
	}

	/**
	 * Post-migration: network-activate themes used by imported site.
	 *
	 * @param string $theme_name Theme stylesheet name.
	 */
	public function network_enable_theme($theme_name) {
		if (!is_multisite()) return;

		$allowed_themes = get_site_option('allowedthemes', array());
		if (!isset($allowed_themes[$theme_name])) {
			$allowed_themes[$theme_name] = true;
			update_site_option('allowedthemes', $allowed_themes);
		}
	}

	/**
	 * Post-migration: disable caching plugins that may cause issues.
	 */
	public function disable_cache_plugins() {
		$cache_plugins = array(
			'w3-total-cache/w3-total-cache.php',
			'wp-super-cache/wp-cache.php',
			'wp-fastest-cache/wpFastestCache.php',
			'litespeed-cache/litespeed-cache.php',
			'autoptimize/autoptimize.php',
			'hummingbird-performance/wp-hummingbird.php',
			'sg-cachepress/sg-cachepress.php',
			'breeze/breeze.php',
		);

		if (!function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = get_option('active_plugins', array());
		$to_deactivate = array();

		foreach ($cache_plugins as $cp) {
			if (in_array($cp, $active_plugins, true)) {
				$to_deactivate[] = $cp;
			}
		}

		if (!empty($to_deactivate)) {
			deactivate_plugins($to_deactivate, true);
			SiteGuard_Backup::instance()->log("Disabled cache plugins post-migration: " . implode(', ', $to_deactivate));
		}

		// Also remove advanced-cache.php if it exists
		$advanced_cache = WP_CONTENT_DIR . '/advanced-cache.php';
		if (file_exists($advanced_cache) && $this->is_migration) {
			@rename($advanced_cache, $advanced_cache . '.disabled');
		}
	}

	/**
	 * Update siteurl and home to specified values after migration.
	 *
	 * After a DB import, site_url()/home_url() return the OLD values from
	 * the imported backup. The caller MUST pass the desired new URLs.
	 *
	 * @param string $new_siteurl The desired siteurl (pass value saved before DB import).
	 * @param string $new_home    The desired home URL (pass value saved before DB import).
	 */
	public function fix_site_options($new_siteurl = '', $new_home = '') {
		// Fallback: use the old_siteurl recorded before import if caller doesn't pass values.
		// But warn - the caller should always pass explicit values.
		if (empty($new_siteurl)) {
			error_log('SiteGuard Backup: fix_site_options called without new_siteurl - cannot fix options from overwritten DB');
			return;
		}
		if (empty($new_home)) {
			$new_home = $new_siteurl;
		}

		update_option('siteurl', $new_siteurl);
		update_option('home', $new_home);

		// Clear any cached values
		wp_cache_delete('alloptions', 'options');
	}
}
