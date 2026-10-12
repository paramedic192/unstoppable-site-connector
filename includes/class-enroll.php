<?php
/**
 * Bulk connect: a connector ZIP downloaded from a dashboard's Add Site screen
 * carries an enroll.json file with that dashboard's address and a bulk-connect
 * pass. When such a copy is active and this site is not connected yet, it asks
 * that dashboard to connect it, so nobody has to copy a key by hand.
 *
 * The site makes its own one-time pairing code (as the Generate button does) and
 * sends it with the pass; the dashboard then pairs normally by calling this site
 * back. Runs in the background (WP-Cron), with a few retries, and only once.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Enroll {

	const HOOK  = 'uscn_enroll';
	const STATE = 'uscn_enroll';
	const TRIES = 8;

	public static function hooks() {
		add_action( self::HOOK, array( __CLASS__, 'attempt' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	private static function file() {
		return USCN_DIR . 'enroll.json';
	}

	/** The bulk-connect settings shipped in this copy, or null. */
	public static function config() {
		$f = self::file();
		if ( ! is_readable( $f ) ) {
			return null;
		}
		$c = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file in this plugin.
		if ( ! is_array( $c ) || empty( $c['enroll'] ) || empty( $c['pass'] ) || ! wp_http_validate_url( $c['enroll'] ) ) {
			return null;
		}
		return $c;
	}

	public static function state() {
		$s = get_option( self::STATE );
		return is_array( $s ) ? $s : array();
	}

	/** Schedule an attempt while this copy has a pass, the site isn't connected and we haven't given up. */
	public static function maybe_schedule() {
		$s = self::state();
		if ( ! empty( $s['done'] ) || ( isset( $s['tries'] ) && $s['tries'] >= self::TRIES ) ) {
			return;
		}
		if ( USCN_Auth::is_paired() || ! self::config() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$wait = empty( $s['tries'] ) ? 5 : min( DAY_IN_SECONDS, 300 * pow( 2, (int) $s['tries'] ) );
			wp_schedule_single_event( time() + $wait, self::HOOK );
		}
	}

	public static function attempt() {
		$c = self::config();
		$s = self::state();
		if ( ! $c || USCN_Auth::is_paired() || ! empty( $s['done'] ) ) {
			return;
		}
		$s['tries'] = ( isset( $s['tries'] ) ? (int) $s['tries'] : 0 ) + 1;
		$s['time']  = time();
		update_option( self::STATE, $s, false );

		$key      = USCN_Auth::connection_key( USCN_Auth::create_pairing_code() );
		$response = wp_safe_remote_post(
			$c['enroll'],
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'pass' => (string) $c['pass'],
						'key'  => $key,
					)
				),
			)
		);
		delete_option( USCN_Auth::OPT_PAIRING ); // Used by now, or no longer needed.

		// The dashboard paired with this site in a separate request: read the option fresh.
		wp_cache_delete( USCN_Auth::OPT_CONN, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body = $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( 200 === $code && USCN_Auth::is_paired() ) {
			$s['done']      = true;
			$s['dashboard'] = isset( $c['dashboard'] ) ? sanitize_text_field( $c['dashboard'] ) : '';
			$s['error']     = '';
			update_option( self::STATE, $s, false );
			USCN_Activity::log( 'connector', 'bulk_connected', isset( $c['home'] ) ? esc_url_raw( $c['home'] ) : '' );
			return;
		}
		$s['error'] = is_wp_error( $response ) ? $response->get_error_message() : ( is_array( $body ) && ! empty( $body['message'] ) ? sanitize_text_field( $body['message'] ) : 'HTTP ' . $code );
		// A pass the dashboard refused for good (wrong, ended): don't keep trying.
		if ( in_array( $code, array( 400, 403 ), true ) ) {
			$s['tries'] = self::TRIES;
		}
		update_option( self::STATE, $s, false );
	}
}
