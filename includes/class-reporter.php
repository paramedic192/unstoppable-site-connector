<?php
/**
 * Push reporting: the site sends its own status out to the dashboard.
 *
 * Outbound requests from the site are rarely blocked by Wordfence, Cloudflare
 * or host firewalls, and the dashboard never has to crawl 70 sites in one go.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Reporter {

	public static function hooks() {
		add_action( 'uscn_push', array( __CLASS__, 'push_scheduled' ) );
		add_action( 'uscn_push_soon', array( __CLASS__, 'push' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( 'uscn_push' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'uscn_push' );
		}
	}

	/** Push about a minute after something changes (batches bursts of events). */
	public static function schedule_soon() {
		if ( USCN_Auth::is_paired() && ! wp_next_scheduled( 'uscn_push_soon' ) ) {
			wp_schedule_single_event( time() + 60, 'uscn_push_soon' );
		}
	}

	public static function push_scheduled() {
		USCN_Activity::diff_and_log(); // Catch changes made outside WordPress (FTP, WP-CLI).
		USCN_Activity::prune();
		self::push();
	}

	/**
	 * @return array Result summary, also stored in the uscn_last_push option.
	 */
	public static function push() {
		$conn = USCN_Auth::connection();
		if ( empty( $conn['dashboard_pubkey'] ) || empty( $conn['push_url'] ) ) {
			return array( 'skipped' => true );
		}

		$since    = (int) get_option( 'uscn_ack_id', 0 );
		$activity = USCN_Activity::since( $since, 200 );
		$payload  = array(
			'site_id'  => $conn['site_id'],
			'sent_at'  => time(),
			'status'   => USCN_Status::collect( false ),
			'activity' => $activity,
		);
		$body = wp_json_encode( $payload );

		$url   = $conn['push_url'];
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );

		$headers = USCN_Auth::sign_outgoing( 'POST', $path, $body ) + array(
			'Content-Type' => 'application/json',
			'X-FMC-Site'   => $conn['site_id'],
		);

		$response = wp_remote_post(
			$url,
			array(
				'body'    => $body,
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		$result = array( 'time' => time() );
		if ( is_wp_error( $response ) ) {
			$result['ok']    = false;
			$result['error'] = $response->get_error_message();
		} else {
			$code           = (int) wp_remote_retrieve_response_code( $response );
			$result['ok']   = $code >= 200 && $code < 300;
			$result['code'] = $code;

			// The dashboard acknowledges the newest activity row it stored.
			$reply = json_decode( wp_remote_retrieve_body( $response ), true );
			$max   = $activity ? (int) end( $activity )['id'] : $since;
			if ( $result['ok'] && isset( $reply['ack_activity_id'] ) ) {
				$ack = (int) $reply['ack_activity_id'];
				if ( $ack > $since && $ack <= $max ) {
					update_option( 'uscn_ack_id', $ack, false );
				}
			}
		}

		update_option( 'uscn_last_push', $result, false );
		return $result;
	}
}
