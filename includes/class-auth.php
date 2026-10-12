<?php
/**
 * Pairing and request signing.
 *
 * Every dashboard -> site request is signed with the dashboard's Ed25519 key.
 * Every site -> dashboard push is signed with this site's own Ed25519 key.
 * No WordPress usernames or passwords are ever involved, so renaming admins,
 * changing passwords or security plugins that rename logins can't break it.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Auth {

	const OPT_CONN    = 'uscn_connection';
	const OPT_KEYS    = 'uscn_keys';
	const OPT_PAIRING = 'uscn_pairing';
	const MAX_SKEW    = 300; // seconds a signed request stays valid.
	const PAIR_TTL    = 900; // pairing codes expire after 15 minutes.

	/* ---------- connection state ---------- */

	public static function connection() {
		$c = get_option( self::OPT_CONN );
		return is_array( $c ) ? $c : array();
	}

	public static function is_paired() {
		$c = self::connection();
		return ! empty( $c['dashboard_pubkey'] );
	}

	public static function disconnect() {
		delete_option( self::OPT_CONN );
		delete_option( self::OPT_KEYS );
		delete_option( 'uscn_ack_id' );
		delete_option( 'uscn_last_push' );
	}

	/* ---------- signing ---------- */

	/**
	 * The exact string both sides sign. Keep this identical in every client.
	 */
	public static function canonical_string( $method, $path, $timestamp, $nonce, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( (string) $method ),
				(string) $path,
				(string) $timestamp,
				(string) $nonce,
				hash( 'sha256', (string) $body ),
			)
		);
	}

	/**
	 * Permission callback for every signed REST route.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_rest_request( WP_REST_Request $request ) {
		if ( ! self::is_paired() ) {
			return new WP_Error( 'uscn_not_paired', 'This site is not connected to a dashboard.', array( 'status' => 403 ) );
		}

		$ts    = (string) $request->get_header( 'x-fmc-timestamp' );
		$nonce = (string) $request->get_header( 'x-fmc-nonce' );
		$sig   = (string) $request->get_header( 'x-fmc-signature' );

		if ( '' === $ts || '' === $nonce || '' === $sig ) {
			return new WP_Error( 'uscn_unsigned', 'Missing signature headers.', array( 'status' => 401 ) );
		}
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return new WP_Error( 'uscn_clock', 'Request timestamp is outside the allowed window. Check the server clocks.', array( 'status' => 401 ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return new WP_Error( 'uscn_nonce', 'Malformed nonce.', array( 'status' => 401 ) );
		}

		$conn = self::connection();
		$pub  = base64_decode( $conn['dashboard_pubkey'], true );
		$raw  = base64_decode( $sig, true );

		if ( false === $pub || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $pub )
			|| false === $raw || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) ) {
			return new WP_Error( 'uscn_bad_key', 'Malformed key or signature.', array( 'status' => 401 ) );
		}

		$message = self::canonical_string( $request->get_method(), $request->get_route(), $ts, $nonce, $request->get_body() );

		try {
			$ok = sodium_crypto_sign_verify_detached( $raw, $message, $pub );
		} catch ( Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			return new WP_Error( 'uscn_bad_signature', 'Signature check failed.', array( 'status' => 401 ) );
		}

		// Replay protection: a nonce can only be used once inside the window.
		$key = 'uscn_nonce_' . md5( $nonce );
		if ( get_transient( $key ) ) {
			return new WP_Error( 'uscn_replay', 'This request was already used.', array( 'status' => 401 ) );
		}
		set_transient( $key, 1, self::MAX_SKEW * 2 + 60 );

		// This site's own rules: allowed dashboard addresses.
		$allowed = USCN_Security::check_request( $request );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		USCN_Activity::$actor = 'dashboard';
		return true;
	}

	/* ---------- this site's own keypair (for pushes) ---------- */

	public static function reset_child_keys() {
		$pair = sodium_crypto_sign_keypair();
		$keys = array(
			'public' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
			'secret' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
		);
		update_option( self::OPT_KEYS, $keys, false );
		return $keys;
	}

	public static function child_keys() {
		$k = get_option( self::OPT_KEYS );
		return ( is_array( $k ) && ! empty( $k['secret'] ) ) ? $k : self::reset_child_keys();
	}

	/**
	 * Headers for an outgoing signed request to the dashboard.
	 */
	public static function sign_outgoing( $method, $path, $body ) {
		$keys  = self::child_keys();
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 16 ) );
		$sig   = sodium_crypto_sign_detached(
			self::canonical_string( $method, $path, $ts, $nonce, $body ),
			base64_decode( $keys['secret'] )
		);
		return array(
			'X-FMC-Timestamp' => $ts,
			'X-FMC-Nonce'     => $nonce,
			'X-FMC-Signature' => base64_encode( $sig ),
		);
	}

	/* ---------- pairing codes ---------- */

	public static function create_pairing_code() {
		$code = wp_generate_password( 32, false, false );
		update_option(
			self::OPT_PAIRING,
			array(
				'hash'    => hash( 'sha256', $code ),
				'expires' => time() + self::PAIR_TTL,
			),
			false
		);
		return $code;
	}

	public static function consume_pairing_code( $code ) {
		$p = get_option( self::OPT_PAIRING );
		if ( ! is_array( $p ) || empty( $p['hash'] ) || time() > (int) $p['expires'] ) {
			return false;
		}
		if ( ! hash_equals( $p['hash'], hash( 'sha256', (string) $code ) ) ) {
			return false;
		}
		delete_option( self::OPT_PAIRING );
		return true;
	}

	/**
	 * One string the admin copies into the dashboard: endpoint + one-time code.
	 */
	public static function connection_key( $code ) {
		return base64_encode(
			wp_json_encode(
				array(
					'v'    => USCN_API_VERSION,
					'site' => home_url(),
					'api'  => rest_url( 'uscn/v1/' ),
					'code' => $code,
				)
			)
		);
	}
}
