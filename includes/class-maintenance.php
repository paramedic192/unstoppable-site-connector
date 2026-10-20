<?php
/**
 * Maintenance mode, switched on and off from the connected dashboard.
 *
 * While it is on, visitors see a short "back soon" page with a 503 status and a
 * Retry-After header, so search engines know it is temporary. Anyone signed in
 * who can edit posts still sees the site normally. wp-admin, the login screen,
 * the REST API and WP-Cron are never blocked, so the site can always be managed
 * and the dashboard can always switch it off again.
 *
 * The page shows the site's name plus a heading and message from the dashboard:
 * plain text only, escaped when shown. The dashboard can set an end time, after
 * which maintenance mode switches itself off.
 *
 * An administrator of this site always stays in control: the admin bar says when
 * maintenance mode is on, Tools > Unstoppable Site Connector has a "Turn off now"
 * button, and switching off "Turn maintenance mode on and off" under Security
 * turns it off and stops the dashboard from using it. Disconnecting turns it off too.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Maintenance {

	const OPT         = 'uscn_maintenance';
	const MAX_TITLE   = 80;
	const MAX_MESSAGE = 500;
	const MAX_HOURS   = 168; // A week.

	public static function hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block' ), 0 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_action( 'admin_post_uscn_maintenance_off', array( __CLASS__, 'handle_off' ) );
	}

	/** @return array { on, title, message, until, since } */
	public static function get() {
		$m = get_option( self::OPT );
		$m = is_array( $m ) ? $m : array();
		$m = array(
			'on'      => ! empty( $m['on'] ),
			'title'   => isset( $m['title'] ) ? (string) $m['title'] : '',
			'message' => isset( $m['message'] ) ? (string) $m['message'] : '',
			'until'   => isset( $m['until'] ) ? (int) $m['until'] : 0,
			'since'   => isset( $m['since'] ) ? (int) $m['since'] : 0,
		);
		if ( $m['on'] && $m['until'] && $m['until'] <= time() ) {
			self::turn_off( 'maintenance_ended' ); // Its end time has passed.
			$m['on'] = false;
		}
		if ( $m['on'] && ! USCN_Security::allowed( 'maintenance' ) ) {
			$m['on'] = false; // Switched off on this site.
		}
		return $m;
	}

	public static function is_on() {
		return self::get()['on'];
	}

	/** For the status report. */
	public static function summary() {
		$m = self::get();
		return array(
			'on'    => $m['on'],
			'since' => $m['on'] ? $m['since'] : 0,
			'until' => $m['on'] ? $m['until'] : 0,
		);
	}

	/** @param string $why Activity action to log, or '' for none. */
	public static function turn_off( $why = '' ) {
		$was = get_option( self::OPT );
		delete_option( self::OPT );
		if ( $why && is_array( $was ) && ! empty( $was['on'] ) ) {
			USCN_Activity::log( 'connector', $why, home_url() );
		}
	}

	public static function default_title() {
		return __( 'Down for maintenance', 'unstoppable-site-connector' );
	}

	public static function default_message() {
		return __( 'We are making some improvements and will be back shortly. Thank you for your patience.', 'unstoppable-site-connector' );
	}

	/* ---------- visitors ---------- */

	public static function maybe_block() {
		if ( ! self::is_on() || current_user_can( 'edit_posts' ) ) {
			return;
		}
		$m     = self::get();
		$retry = $m['until'] ? max( 60, $m['until'] - time() ) : HOUR_IN_SECONDS;
		$title = '' !== $m['title'] ? $m['title'] : self::default_title();
		$msg   = '' !== $m['message'] ? $m['message'] : self::default_message();

		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: ' . (int) $retry );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( $title . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f3f5f8;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;padding:24px;box-sizing:border-box}
main{max-width:520px;background:#fff;border-radius:12px;padding:40px 36px;box-shadow:0 4px 24px rgba(0,0,0,.08);text-align:center}
.site{margin:0 0 8px;font-size:14px;letter-spacing:.04em;text-transform:uppercase;color:#646970}
h1{margin:0 0 12px;font-size:26px;line-height:1.25}
p{margin:0;color:#3c434a;white-space:pre-line}
</style>
</head>
<body>
<main>
	<p class="site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
	<h1><?php echo esc_html( $title ); ?></h1>
	<p><?php echo esc_html( $msg ); ?></p>
</main>
</body>
</html>
		<?php
		exit;
	}

	/* ---------- site owner ---------- */

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) || ! self::is_on() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'uscn-maintenance',
				'title' => esc_html__( 'Maintenance mode is on', 'unstoppable-site-connector' ),
				'href'  => current_user_can( 'manage_options' ) ? admin_url( 'tools.php?page=unstoppable-site-connector' ) : false,
				'meta'  => array( 'title' => __( 'Visitors see a "back soon" page. Signed-in editors see the site as usual.', 'unstoppable-site-connector' ) ),
			)
		);
	}

	/** "Turn off now" on Tools > Unstoppable Site Connector. */
	public static function handle_off() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'unstoppable-site-connector' ) );
		}
		check_admin_referer( 'uscn_maintenance_off' );
		self::turn_off( 'maintenance_off_here' );
		USCN_Reporter::schedule_soon();
		wp_safe_redirect( add_query_arg( 'uscn_msg', 'maintenance_off', admin_url( 'tools.php?page=unstoppable-site-connector' ) ) );
		exit;
	}

	/* ---------- dashboard ---------- */

	/** Signed route: { on, title, message, hours }. hours 0 = until switched off. */
	public static function rest( WP_REST_Request $request ) {
		$ok = USCN_Security::permit( 'maintenance' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$p = $request->get_json_params();
		$p = is_array( $p ) ? $p : array();
		USCN_Activity::$actor = 'dashboard';

		if ( empty( $p['on'] ) ) {
			self::turn_off( 'maintenance_off' );
		} else {
			$title = isset( $p['title'] ) ? sanitize_text_field( (string) $p['title'] ) : '';
			$msg   = isset( $p['message'] ) ? sanitize_textarea_field( (string) $p['message'] ) : '';
			$hours = isset( $p['hours'] ) ? min( self::MAX_HOURS, max( 0, (float) $p['hours'] ) ) : 0;
			$was   = self::get();
			update_option(
				self::OPT,
				array(
					'on'      => true,
					'title'   => self::cut( $title, self::MAX_TITLE ),
					'message' => self::cut( $msg, self::MAX_MESSAGE ),
					'until'   => $hours ? time() + (int) round( $hours * HOUR_IN_SECONDS ) : 0,
					'since'   => $was['on'] && $was['since'] ? $was['since'] : time(),
				),
				false
			);
			if ( ! $was['on'] ) {
				USCN_Activity::log( 'connector', 'maintenance_on', home_url() );
			}
		}
		return rest_ensure_response(
			array(
				'ok'          => true,
				'maintenance' => self::summary(),
				'status'      => USCN_Status::collect( false ),
			)
		);
	}

	private static function cut( $s, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}
}
