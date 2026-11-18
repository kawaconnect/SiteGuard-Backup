<?php
/**
 * SiteGuard Backup - OAuth sign-in for Google Drive, Dropbox, OneDrive and pCloud.
 *
 * Flow: the settings page asks for an authorization URL (start()), opens it in
 * a popup, the provider sends the browser back to the REST route
 *
 *     /wp-json/sgbackup/v1/oauth/callback?code=...&state=...
 *
 * and handle_callback() exchanges the code for tokens and stores them on the
 * storage instance (account) the sign-in was started for.
 *
 * Why a REST route: the redirect URI must be registered with the provider and
 * match exactly. Microsoft does not allow query parameters in redirect URIs of
 * apps that sign in personal accounts (OneDrive), so admin-ajax.php?action=...
 * cannot be used. The previous redirect URI pointed at the AJAX dispatcher,
 * which only reads POST and answered "Missing subaction": no sign-in could
 * ever complete.
 *
 * The browser arrives without a REST nonce, so the route is public; the
 * `state` value is what authorizes it: 128 random bits, stored server-side for
 * 15 minutes, single use, bound to the admin who started the sign-in, the
 * provider and the instance.
 */

if (!defined('ABSPATH')) die('No direct access.');

class SiteGuard_Backup_OAuth {

	const PROVIDERS = array('googledrive', 'dropbox', 'onedrive', 'pcloud');
	const STATE_TTL = 900;

	public static function register_routes() {
		register_rest_route('sgbackup/v1', '/oauth/callback', array(
			'methods'             => 'GET',
			'callback'            => array(__CLASS__, 'handle_callback'),
			'permission_callback' => '__return_true', // authorized by the one-time state, see above
		));
	}

	/** The redirect URI to register with each provider. */
	public static function redirect_uri() {
		return (string) apply_filters('sgbackup_oauth_redirect_uri', rest_url('sgbackup/v1/oauth/callback'));
	}

	/** Query parameters in the redirect URI break OneDrive personal accounts (plain permalinks). */
	public static function redirect_uri_has_query() {
		return false !== strpos(self::redirect_uri(), '?');
	}

	private static function module($provider, $instance) {
		require_once SGBACKUP_DIR . 'methods/backup-module.php';
		require_once SGBACKUP_DIR . 'methods/' . $provider . '.php';
		$class = 'SiteGuard_Backup_Method_' . ucfirst($provider);
		$m = new $class();
		$m->set_instance_id($instance);
		return $m;
	}

	private static function valid_instance($instance) {
		return is_string($instance) && 1 === preg_match('/^[A-Za-z0-9_-]{1,40}$/', $instance);
	}

	/**
	 * Begin a sign-in for $provider on storage instance $instance.
	 *
	 * @return array|WP_Error array('auth_url' => ...)
	 */
	public static function start($provider, $instance, $return_url = '') {
		if (!in_array($provider, self::PROVIDERS, true)) {
			return new WP_Error('invalid_method', 'This storage method does not use OAuth');
		}
		if ('' === $instance || null === $instance) $instance = 's-0';
		if (!self::valid_instance($instance)) {
			return new WP_Error('invalid_instance', 'Invalid storage instance');
		}
		$m = self::module($provider, $instance);
		$opts = $m->get_options();
		$id_key = 'dropbox' === $provider ? 'app_key' : 'client_id';
		if (empty($opts[$id_key])) {
			return new WP_Error('oauth_not_configured', 'Save the app credentials (' . ('dropbox' === $provider ? 'App key and App secret' : 'Client ID and Client secret') . ') first, then sign in.');
		}

		$state = bin2hex(random_bytes(16));
		set_site_transient('sgbackup_oauth_' . $state, array(
			'user'     => get_current_user_id(),
			'provider' => $provider,
			'instance' => $instance,
			'return'   => wp_validate_redirect($return_url, admin_url('admin.php?page=siteguardbackup')),
			'blog'     => get_current_blog_id(),
			'created'  => time(),
		), self::STATE_TTL);

		return array('auth_url' => $m->get_auth_url($state, self::redirect_uri()), 'redirect_uri' => self::redirect_uri());
	}

	/**
	 * REST callback the provider redirects the browser to.
	 */
	public static function handle_callback($request) {
		$state = (string) $request->get_param('state');
		$code  = (string) $request->get_param('code');
		$error = (string) $request->get_param('error');

		if (!preg_match('/^[a-f0-9]{32}$/', $state)) {
			return self::page(false, 'Invalid or missing sign-in state. Start the sign-in again from the SiteGuard settings page.');
		}
		$key = 'sgbackup_oauth_' . $state;
		$ctx = get_site_transient($key);
		delete_site_transient($key); // single use, also on failure
		if (!is_array($ctx) || empty($ctx['provider']) || (time() - (int) $ctx['created']) > self::STATE_TTL) {
			return self::page(false, 'This sign-in link has expired or was already used. Start the sign-in again from the SiteGuard settings page.');
		}

		$user = (int) $ctx['user'];
		$can = is_multisite()
			? (user_can($user, 'manage_network_options') || user_can($user, 'manage_options'))
			: user_can($user, 'manage_options');
		if (!$user || !$can) {
			return self::page(false, 'The account that started this sign-in may no longer manage SiteGuard.');
		}

		if ('' !== $error) {
			$desc = (string) $request->get_param('error_description');
			return self::page(false, 'The provider refused the sign-in: ' . sanitize_text_field($error . ('' !== $desc ? ' (' . $desc . ')' : '')), $ctx['return']);
		}
		if ('' === $code) {
			return self::page(false, 'The provider returned no authorization code.', $ctx['return']);
		}

		$m = self::module($ctx['provider'], $ctx['instance']);
		if ('pcloud' === $ctx['provider']) {
			$result = $m->exchange_code(sanitize_text_field($code), self::redirect_uri(), array('hostname' => (string) $request->get_param('hostname')));
		} else {
			$result = $m->exchange_code(sanitize_text_field($code), self::redirect_uri());
		}
		if (is_wp_error($result)) {
			return self::page(false, 'Could not complete the sign-in: ' . $result->get_error_message(), $ctx['return']);
		}

		if (class_exists('SiteGuard_Backup_Audit_Log')) {
			SiteGuard_Backup_Audit_Log::instance()->log('oauth_connected', ucfirst($ctx['provider']) . ' account connected', array(
				'method' => $ctx['provider'], 'instance' => $ctx['instance'], 'user' => $user,
			));
		}
		return self::page(true, 'Signed in. You can close this window.', $ctx['return']);
	}

	/**
	 * Small HTML page: reloads the settings page that opened the popup and closes
	 * it, or links back when there is no opener.
	 */
	private static function page($ok, $message, $return = '') {
		if ('' === $return) $return = admin_url('admin.php?page=siteguardbackup');
		$html = '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>SiteGuard Backup</title></head><body style="font:16px/1.5 sans-serif;margin:3em auto;max-width:36em;padding:0 1em">'
			. '<h1 style="font-size:1.3em">' . ($ok ? 'Connected' : 'Sign-in failed') . '</h1>'
			. '<p id="sgmsg">' . esc_html($message) . '</p>'
			. '<p><a href="' . esc_url($return) . '">Back to SiteGuard Backup settings</a></p>'
			. ($ok ? '<script>try{if(window.opener&&!window.opener.closed){window.opener.location.reload();window.close();}}catch(e){}</script>' : '')
			. '</body></html>';
		$res = new WP_REST_Response(null, $ok ? 200 : 400);
		$res->header('Content-Type', 'text/html; charset=utf-8');
		$res->header('Cache-Control', 'no-store');
		$res->header('Referrer-Policy', 'no-referrer');
		$res->header('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'");
		$res->header('X-SiteGuard-OAuth', '1');
		// Serve the HTML as is instead of JSON-encoding it.
		add_filter('rest_pre_serve_request', function ($served, $result) use ($html) {
			if ($served || !($result instanceof WP_HTTP_Response)) return $served;
			$h = $result->get_headers();
			if (empty($h['X-SiteGuard-OAuth'])) return $served;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above
			return true;
		}, 10, 2);
		return $res;
	}
}
