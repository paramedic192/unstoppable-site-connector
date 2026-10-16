<?php
/**
 * Collects everything the dashboard's site card needs.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Status {

	public static function load_admin_includes() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . WPINC . '/update.php';
		}
	}

	/**
	 * Ask wordpress.org for fresh update data instead of trusting a stale cache.
	 * This is what keeps update counts accurate.
	 */
	public static function refresh_update_data() {
		self::load_admin_includes();
		$old_plugins = get_site_transient( 'update_plugins' );
		$old_themes  = get_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_update_plugins();
		wp_update_themes();
		wp_version_check( array(), true );
		if ( ! is_admin() ) {
			// Outside the admin area, plugins that only check for updates there (such as
			// Crocoblock's) didn't take part. Keep the updates they reported last time.
			self::keep_unanswered( 'update_plugins', $old_plugins, get_plugins(), 'Version' );
			self::keep_unanswered( 'update_themes', $old_themes, wp_get_themes(), 'Version' );
		}
	}

	/**
	 * Put back pending updates that the fresh check had no answer for (neither an
	 * update nor "up to date"), if the item is still installed at an older version.
	 */
	private static function keep_unanswered( $transient, $old, $installed, $version_key ) {
		$new = get_site_transient( $transient );
		if ( ! is_object( $old ) || empty( $old->response ) || ! is_object( $new ) ) {
			return;
		}
		$changed = false;
		foreach ( (array) $old->response as $id => $u ) {
			$u   = (array) $u;
			$cur = isset( $installed[ $id ] ) ? ( is_object( $installed[ $id ] ) ? $installed[ $id ]->get( $version_key ) : $installed[ $id ][ $version_key ] ) : '';
			if ( '' === $cur || isset( $new->response[ $id ] ) || isset( $new->no_update[ $id ] ) || empty( $u['new_version'] ) ) {
				continue;
			}
			if ( version_compare( $u['new_version'], $cur, '>' ) ) {
				$new->response[ $id ] = 'update_themes' === $transient ? $u : (object) $u;
				$changed              = true;
			}
		}
		if ( $changed ) {
			set_site_transient( $transient, $new );
		}
	}

	/**
	 * WordPress version read from disk, so it's correct right after a core update
	 * (the global $wp_version still holds the old value in that request).
	 */
	public static function core_version_on_disk() {
		// Read the version number from the file's text; the file itself is not loaded again.
		$text = file_get_contents( ABSPATH . WPINC . '/version.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local core file, read as text only.
		if ( is_string( $text ) && preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $text, $m ) ) {
			return $m[1];
		}
		return get_bloginfo( 'version' );
	}

	public static function collect( $refresh = false ) {
		self::load_admin_includes();
		if ( $refresh ) {
			self::refresh_update_data();
		}

		$plugins = self::plugins();
		$themes  = self::themes();
		$core    = self::core();

		$plugin_updates = count( array_filter( wp_list_pluck( $plugins, 'update' ) ) );
		$theme_updates  = count( array_filter( wp_list_pluck( $themes, 'update' ) ) );
		$ssl            = self::ssl_info();

		return array(
			'checked_at'  => time(),
			'site'        => array(
				'name'     => get_bloginfo( 'name' ),
				'home'     => home_url(),
				'admin'    => admin_url(),
				'language' => get_locale(),
				'timezone' => wp_timezone_string(),
			),
			'summary'     => array(
				'core_update'    => (bool) $core['update'],
				'plugin_updates' => $plugin_updates,
				'theme_updates'  => $theme_updates,
				'total_updates'  => $plugin_updates + $theme_updates + ( $core['update'] ? 1 : 0 ),
				'ssl_days_left'  => isset( $ssl['days_left'] ) ? $ssl['days_left'] : null,
			),
			'core'        => $core,
			'plugins'     => $plugins,
			'themes'      => $themes,
			'environment' => self::environment(),
			'ssl'         => $ssl,
			'integrations'=> self::integrations(),
			'connector'   => array(
				'version'     => USCN_VERSION,
				'api_version' => USCN_API_VERSION,
				'name'        => 'unstoppable-site-connector',
				'api'         => rest_url( USCN_REST::NS . '/' ),
				'admin_context' => is_admin() ? true : ( is_array( get_option( 'uscn_admin_context' ) ) ? (bool) get_option( 'uscn_admin_context' )['ok'] : null ),
				'last_push'   => get_option( 'uscn_last_push', null ),
				'activity_last_id' => USCN_Activity::last_id(),
				'branding'    => USCN_Branding::hash(),
			),
			'security'    => USCN_Security::summary(),
		);
	}

	private static function core() {
		$current = self::core_version_on_disk();
		$update  = null;
		$updates = get_core_updates( array( 'dismissed' => false ) );
		if ( is_array( $updates ) ) {
			foreach ( $updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response && version_compare( $u->current, $current, '>' ) ) {
					$update = $u->current;
					break;
				}
			}
		}
		return array(
			'version' => $current,
			'update'  => $update,
		);
	}

	private static function plugins() {
		$updates   = get_site_transient( 'update_plugins' );
		$available = ( is_object( $updates ) && ! empty( $updates->response ) ) ? $updates->response : array();
		$active    = (array) get_option( 'active_plugins', array() );
		$network   = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );

		$out = array();
		foreach ( get_plugins() as $file => $d ) {
			$out[] = array(
				'file'        => $file,
				'name'        => $d['Name'],
				'version'     => $d['Version'],
				'active'      => in_array( $file, $active, true ) || in_array( $file, $network, true ),
				'update'      => isset( $available[ $file ]->new_version ) ? $available[ $file ]->new_version : null,
				'auto_update' => in_array( $file, $auto, true ),
			);
		}
		return $out;
	}

	private static function themes() {
		$updates   = get_site_transient( 'update_themes' );
		$available = ( is_object( $updates ) && ! empty( $updates->response ) ) ? $updates->response : array();
		$active    = get_stylesheet();
		$parent    = get_template();

		$out = array();
		foreach ( wp_get_themes() as $slug => $t ) {
			$out[] = array(
				'slug'    => $slug,
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'active'  => $slug === $active,
				'parent'  => $slug === $parent && $parent !== $active,
				'update'  => isset( $available[ $slug ]['new_version'] ) ? $available[ $slug ]['new_version'] : null,
			);
		}
		return $out;
	}

	private static function environment() {
		global $wpdb;
		$users  = count_users();
		$admins = isset( $users['avail_roles']['administrator'] ) ? (int) $users['avail_roles']['administrator'] : 0;

		return array(
			'php'               => PHP_VERSION,
			'db'                => $wpdb->db_version(),
			'server'            => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'memory_limit'      => WP_MEMORY_LIMIT,
			'multisite'         => is_multisite(),
			'debug'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'https'             => 0 === strpos( home_url(), 'https://' ),
			'filesystem_method' => get_filesystem_method(),
			'admin_count'       => $admins,
			'user_count'        => (int) $users['total_users'],
			'cron_disabled'     => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	private static function integrations() {
		$out = array();

		if ( defined( 'WC_VERSION' ) && function_exists( 'wc_get_orders' ) ) {
			$wc = array( 'version' => WC_VERSION );
			try {
				$since              = '>' . ( time() - 30 * DAY_IN_SECONDS );
				$base               = array( 'date_created' => $since, 'limit' => 1, 'paginate' => true, 'return' => 'ids' );
				$wc['orders_30d']   = (int) wc_get_orders( $base )->total;
				$wc['failed_30d']   = (int) wc_get_orders( $base + array( 'status' => array( 'failed' ) ) )->total;
				$wc['pending_30d']  = (int) wc_get_orders( $base + array( 'status' => array( 'pending' ) ) )->total;
			} catch ( Throwable $e ) {
				$wc['error'] = $e->getMessage();
			}
			$out['woocommerce'] = $wc;
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$out['elementor'] = array(
				'version'     => ELEMENTOR_VERSION,
				'pro_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
			);
		}

		return $out;
	}

	/**
	 * Certificate expiry for this site's own domain, cached for 12 hours.
	 */
	public static function ssl_info() {
		if ( 0 !== strpos( home_url(), 'https://' ) ) {
			return array( 'enabled' => false );
		}
		$cached = get_transient( 'uscn_ssl' );
		if ( false !== $cached ) {
			return $cached;
		}
		if ( ! function_exists( 'openssl_x509_parse' ) || ! function_exists( 'stream_socket_client' ) ) {
			return array( 'enabled' => true, 'error' => 'OpenSSL is not available on this server.' );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$ctx  = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer'       => false, // We want the expiry even if the cert is broken.
					'verify_peer_name'  => false,
					'SNI_enabled'       => true,
					'peer_name'         => $host,
				),
			)
		);
		$errno  = 0;
		$errstr = '';
		$conn   = @stream_socket_client( "ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $conn ) {
			$info = array( 'enabled' => true, 'error' => $errstr ? $errstr : 'Could not connect on port 443.' );
		} else {
			$params = stream_context_get_params( $conn );
			$cert   = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
			fclose( $conn ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$to     = isset( $cert['validTo_time_t'] ) ? (int) $cert['validTo_time_t'] : 0;
			$issuer = isset( $cert['issuer']['O'] ) ? $cert['issuer']['O'] : ( isset( $cert['issuer']['CN'] ) ? $cert['issuer']['CN'] : '' );
			$info   = array(
				'enabled'   => true,
				'issuer'    => $issuer,
				'valid_to'  => $to,
				'days_left' => $to ? (int) floor( ( $to - time() ) / DAY_IN_SECONDS ) : null,
			);
		}

		set_transient( 'uscn_ssl', $info, 12 * HOUR_IN_SECONDS );
		return $info;
	}
}
