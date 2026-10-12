<?php
/**
 * One-click login from the dashboard.
 *
 * 1. The dashboard makes a signed POST to /uscn/v1/login.
 * 2. This site stores a random one-time token (hashed) for 60 seconds and
 *    returns a login URL containing it.
 * 3. The dashboard opens that URL in the user's browser. The first visit
 *    consumes the token, signs the browser in as the chosen administrator
 *    and redirects to wp-admin.
 *
 * No passwords are stored or sent. The token works once, for 60 seconds,
 * and only the paired dashboard (which holds the signing key) can ask for one.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Login {

	const OPT       = 'uscn_login';
	const PARAM     = 'uscn_login';
	const TOKEN_TTL = 60;

	public static function hooks() {
		// Early on init so the login happens before page caching or theme output.
		add_action( 'init', array( __CLASS__, 'maybe_login' ), 1 );
	}

	/* ---------- settings ---------- */

	public static function settings() {
		$s = get_option( self::OPT );
		$s = is_array( $s ) ? $s : array();
		return array(
			'enabled' => isset( $s['enabled'] ) ? (bool) $s['enabled'] : true,
			'user_id' => isset( $s['user_id'] ) ? (int) $s['user_id'] : 0,
		);
	}

	/**
	 * Administrators this site can sign the dashboard in as.
	 *
	 * @return WP_User[]
	 */
	public static function admins() {
		return get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 50,
			)
		);
	}

	/**
	 * The account the dashboard signs in as: the one picked in settings if it
	 * is still an administrator, otherwise the first administrator.
	 *
	 * @return WP_User|null
	 */
	public static function login_user() {
		$s = self::settings();
		if ( $s['user_id'] ) {
			$u = get_userdata( $s['user_id'] );
			if ( $u && user_can( $u, 'manage_options' ) ) {
				return $u;
			}
		}
		$admins = self::admins();
		foreach ( $admins as $u ) {
			if ( user_can( $u, 'manage_options' ) ) {
				return $u;
			}
		}
		return null;
	}

	/* ---------- REST: issue a token (signed route) ---------- */

	public static function issue( WP_REST_Request $request ) {
		$s = self::settings();
		if ( ! $s['enabled'] ) {
			return new WP_Error( 'uscn_login_off', __( 'One-click login is turned off on this site (Tools > connector settings).', 'unstoppable-site-connector' ), array( 'status' => 403 ) );
		}
		$user = self::login_user();
		if ( ! $user ) {
			return new WP_Error( 'uscn_login_no_admin', __( 'This site has no administrator account to sign in as.', 'unstoppable-site-connector' ), array( 'status' => 409 ) );
		}

		$p        = $request->get_json_params();
		$redirect = is_array( $p ) && isset( $p['redirect'] ) ? self::clean_redirect( $p['redirect'] ) : '';

		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::key( $token ),
			array(
				'user_id'  => $user->ID,
				'redirect' => $redirect,
				'expires'  => time() + self::TOKEN_TTL,
				'by'       => USCN_Security::$by,
			),
			self::TOKEN_TTL
		);

		return rest_ensure_response(
			array(
				// admin-post.php: never page-cached, and allowed by "hide login" plugins.
				// It is on the admin's own scheme, so the secure login cookie is accepted.
				'url'        => add_query_arg(
					array(
						'action'    => 'uscn_login',
						self::PARAM => $token,
					),
					admin_url( 'admin-post.php' )
				),
				'user'       => $user->user_login,
				'expires_in' => self::TOKEN_TTL,
			)
		);
	}

	/**
	 * Only a path inside wp-admin, e.g. "plugins.php" or "update-core.php".
	 */
	private static function clean_redirect( $path ) {
		$path = ltrim( trim( (string) $path ), '/' );
		if ( '' === $path || strlen( $path ) > 200 ) {
			return '';
		}
		if ( ! preg_match( '#^[A-Za-z0-9_\-./?=&%]+$#', $path ) || false !== strpos( $path, '..' ) || false !== strpos( $path, '//' ) ) {
			return '';
		}
		return $path;
	}

	private static function key( $token ) {
		return 'uscn_lt_' . hash( 'sha256', $token );
	}

	/* ---------- browser: consume the token ---------- */

	public static function maybe_login() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the one-time token is the credential.
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$data = preg_match( '/^[a-f0-9]{64}$/', $token ) ? get_transient( self::key( $token ) ) : false;
		if ( $data ) {
			// One use only: delete before doing anything else.
			delete_transient( self::key( $token ) );
		}

		$user = ( is_array( $data ) && ! empty( $data['user_id'] ) && (int) $data['expires'] >= time() )
			? get_userdata( (int) $data['user_id'] )
			: false;

		if ( ! $user || ! user_can( $user, 'manage_options' ) || ! self::settings()['enabled'] ) {
			wp_die(
				wp_kses_post(
					sprintf(
						/* translators: %s: URL of the normal login screen. */
						__( 'This login link has expired or was already used. Go back to your dashboard and click WP Admin again, or <a href="%s">log in normally</a>.', 'unstoppable-site-connector' ),
						esc_url( wp_login_url() )
					)
				),
				esc_html__( 'Login link expired', 'unstoppable-site-connector' ),
				array( 'response' => 403 )
			);
		}

		wp_set_current_user( $user->ID );
		// Secure when wp-admin runs on HTTPS, even if a proxy hides HTTPS from PHP;
		// otherwise wp-admin would look for a secure cookie that was never set.
		$secure = is_ssl() || 'https' === wp_parse_url( admin_url(), PHP_URL_SCHEME );
		wp_set_auth_cookie( $user->ID, false, $secure );

		USCN_Activity::$actor = 'dashboard';
		USCN_Activity::log( 'user', 'dashboard_login', $user->user_login );
		USCN_Activity::$actor = null;

		USCN_Security::$by = isset( $data['by'] ) ? (string) $data['by'] : '';
		USCN_Security::$ip = USCN_Security::client_ip(); // The browser that used the link.
		USCN_Security::alert(
			'login',
			__( 'The dashboard signed in', 'unstoppable-site-connector' ),
			/* translators: %s: username. */
			sprintf( __( 'Someone used one-click login from the dashboard and is now signed in to this site as %s.', 'unstoppable-site-connector' ), $user->user_login )
		);

		// Let other plugins (last-login trackers, audit logs) see a normal login,
		// without logging a second "admin login" entry ourselves.
		remove_action( 'wp_login', array( 'USCN_Activity', 'on_login' ), 10 );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core hook.

		wp_safe_redirect( admin_url( $data['redirect'] ) );
		exit;
	}
}
