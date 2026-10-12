<?php
/**
 * REST API: /wp-json/uscn/v1/*
 *
 * All signed routes are POST with a JSON body, so the whole request
 * (including parameters) is covered by the signature.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_REST {

	const NS = 'uscn/v1';

	/**
	 * Namespace used by FM Connector, this plugin's former name. Dashboards paired before the
	 * rename still call it, so it stays registered (unless the old plugin itself is still active).
	 */
	const LEGACY_NS = 'fmc/v1';

	public static function register() {
		self::register_ns( self::NS );
		if ( ! class_exists( 'FMC_Plugin' ) ) {
			self::register_ns( self::LEGACY_NS );
		}
	}

	private static function register_ns( $ns ) {
		register_rest_route(
			$ns,
			'/pair',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'pair' ),
				'permission_callback' => '__return_true', // Protected by the one-time pairing code.
			)
		);

		$signed = array(
			'ping'     => 'ping',
			'status'   => 'status',
			'update'   => 'update',
			'plugins'  => 'plugins',
			'activity' => 'activity',
			'push'     => 'push_now',
			'unpair'   => 'unpair',
			'login'    => array( 'USCN_Login', 'issue' ),
			'security' => array( 'USCN_Security', 'rest' ),
		);
		foreach ( $signed as $route => $method ) {
			register_rest_route(
				$ns,
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => is_array( $method ) ? $method : array( __CLASS__, $method ),
					'permission_callback' => array( 'USCN_Auth', 'verify_rest_request' ),
				)
			);
		}
	}

	private static function params( WP_REST_Request $request ) {
		$p = $request->get_json_params();
		return is_array( $p ) ? $p : array();
	}

	public static function pair( WP_REST_Request $request ) {
		$failures = (int) get_transient( 'uscn_pair_failures' );
		if ( $failures >= 10 ) {
			return new WP_Error( 'uscn_locked', 'Too many failed pairing attempts. Try again in an hour.', array( 'status' => 429 ) );
		}

		$p = self::params( $request );

		// Validate the request first so a malformed call doesn't burn the one-time code.
		$pub = isset( $p['dashboard_pubkey'] ) ? base64_decode( (string) $p['dashboard_pubkey'], true ) : false;
		if ( false === $pub || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub ) ) {
			return new WP_Error( 'uscn_bad_key', 'dashboard_pubkey must be a base64 Ed25519 public key.', array( 'status' => 400 ) );
		}

		$push_url = '';
		if ( ! empty( $p['push_url'] ) ) {
			$push_url = esc_url_raw( (string) $p['push_url'], array( 'https', 'http' ) );
			if ( ! $push_url || ! wp_http_validate_url( $push_url ) ) {
				return new WP_Error( 'uscn_bad_push_url', 'push_url must be a public http(s) URL on port 80, 443 or 8080.', array( 'status' => 400 ) );
			}
		}

		if ( ! USCN_Auth::consume_pairing_code( isset( $p['code'] ) ? $p['code'] : '' ) ) {
			set_transient( 'uscn_pair_failures', $failures + 1, HOUR_IN_SECONDS );
			return new WP_Error( 'uscn_bad_code', 'Pairing code is invalid or expired. Generate a new one in Tools > Unstoppable Site Connector.', array( 'status' => 403 ) );
		}

		$keys    = USCN_Auth::reset_child_keys();
		$site_id = wp_generate_uuid4();

		update_option(
			USCN_Auth::OPT_CONN,
			array(
				'dashboard_pubkey' => base64_encode( $pub ),
				'dashboard_name'   => isset( $p['dashboard_name'] ) ? sanitize_text_field( $p['dashboard_name'] ) : 'Dashboard',
				'push_url'         => $push_url,
				'site_id'          => $site_id,
				'paired_at'        => time(),
			),
			false
		);
		delete_option( 'uscn_ack_id' );
		delete_transient( 'uscn_pair_failures' );

		USCN_Activity::$actor = 'dashboard';
		USCN_Activity::snapshot();
		USCN_Activity::log( 'connector', 'paired', home_url() );

		USCN_Security::$ip = USCN_Security::client_ip();
		USCN_Security::alert(
			'paired',
			__( 'This site was connected to a dashboard', 'unstoppable-site-connector' ),
			sprintf(
				/* translators: %s: dashboard name. */
				__( 'This site was connected to the dashboard "%s" using a connection key made in its wp-admin. The dashboard can now manage this site within the permissions set under Tools > Unstoppable Site Connector > Security.', 'unstoppable-site-connector' ),
				sanitize_text_field( isset( $p['dashboard_name'] ) ? $p['dashboard_name'] : 'Dashboard' )
			)
		);

		return rest_ensure_response(
			array(
				'site_id'      => $site_id,
				'child_pubkey' => $keys['public'],
				'api_version'  => USCN_API_VERSION,
				'status'       => USCN_Status::collect( false ),
			)
		);
	}

	public static function ping() {
		return rest_ensure_response(
			array(
				'ok'          => true,
				'time'        => time(),
				'version'     => USCN_VERSION,
				'api_version' => USCN_API_VERSION,
			)
		);
	}

	public static function status( WP_REST_Request $request ) {
		$p = self::params( $request );
		if ( empty( $p['refresh'] ) ) {
			return rest_ensure_response( USCN_Status::collect( false ) ); // Reads saved data only; no need for the admin area.
		}
		// A fresh update check runs in the admin area, where plugins such as Crocoblock's load their updaters.
		$result = USCN_Admin_Runner::run( 'status', array( 'refresh' => true ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( self::tag( $result ) );
	}

	/** Note on a status (or a result holding one) whether this job could use the admin area. */
	private static function tag( $result ) {
		if ( ! is_array( $result ) || null === USCN_Admin_Runner::$last_admin_context ) {
			return $result;
		}
		if ( isset( $result['connector'] ) ) {
			$result['connector']['admin_context'] = USCN_Admin_Runner::$last_admin_context;
		} elseif ( isset( $result['status']['connector'] ) ) {
			$result['status']['connector']['admin_context'] = USCN_Admin_Runner::$last_admin_context;
		}
		return $result;
	}

	public static function update( WP_REST_Request $request ) {
		$p     = self::params( $request );
		$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		if ( ! $items ) {
			return new WP_Error( 'uscn_no_items', 'Pass items: [{"type":"plugin","id":"akismet/akismet.php"}].', array( 'status' => 400 ) );
		}
		$ok = USCN_Security::permit( 'update' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = USCN_Admin_Runner::run( 'update', array( 'items' => $items ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( self::tag( $result ) );
	}

	public static function plugins( WP_REST_Request $request ) {
		$p     = self::params( $request );
		$op    = isset( $p['op'] ) ? sanitize_key( $p['op'] ) : '';
		$items = isset( $p['items'] ) && is_array( $p['items'] ) ? $p['items'] : array();
		$ok    = USCN_Security::permit_plugins(
			$op,
			$items,
			array(
				'activate'  => ! empty( $p['activate'] ),
				'overwrite' => ! empty( $p['overwrite'] ),
			)
		);
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = USCN_Admin_Runner::run(
			'plugins',
			array(
				'op'    => $op,
				'items' => $items,
				'args'  => array(
					'activate'  => ! empty( $p['activate'] ),
					'overwrite' => ! empty( $p['overwrite'] ),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		USCN_Security::after_plugins( $op, $result );
		return rest_ensure_response( self::tag( $result ) );
	}

	public static function activity( WP_REST_Request $request ) {
		$p = self::params( $request );
		return rest_ensure_response(
			array(
				'activity' => USCN_Activity::since(
					isset( $p['since_id'] ) ? (int) $p['since_id'] : 0,
					isset( $p['limit'] ) ? (int) $p['limit'] : 200
				),
			)
		);
	}

	public static function push_now() {
		return rest_ensure_response( USCN_Reporter::push() );
	}

	public static function unpair() {
		USCN_Activity::log( 'connector', 'unpaired', home_url() );
		USCN_Auth::disconnect();
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
