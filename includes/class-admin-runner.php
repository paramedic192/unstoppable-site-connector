<?php
/**
 * Runs dashboard jobs inside this site's admin area.
 *
 * Some plugins only switch on their updater inside wp-admin. Crocoblock's Jet
 * plugins are one example: their update checker loads only when is_admin() is
 * true. The dashboard's signed requests arrive through the REST API, where it
 * is false, so their updates were invisible and could not be installed.
 *
 * So the REST handler hands the job to this site's own admin-ajax.php (a
 * "loopback" request, the same thing Site Health uses), where is_admin() is
 * true and admin_init has run, as an administrator. The job is handed over with
 * a random one-time token that is stored hashed, expires in five minutes and
 * only ever comes from a request the dashboard signed.
 *
 * If the host blocks loopback requests, the job runs here instead, as before,
 * and the result says so (admin_context: false) so the dashboard can warn.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Admin_Runner {

	const ACTION = 'uscn_job';
	const TTL    = 300;

	/** Jobs that may be run this way, and how long each may take (seconds). */
	const JOBS = array(
		'status'  => 120,
		'update'  => 900,
		'plugins' => 900,
	);

	/** @var bool|null Whether the last run() used the admin area. */
	public static $last_admin_context = null;

	public static function hooks() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		// Before any plugin asks who the user is, so admin-only code sees an administrator.
		add_filter( 'determine_current_user', array( __CLASS__, 'as_admin' ), 30 );
	}

	/**
	 * @param string $job  status | update | plugins
	 * @param array  $args Job arguments.
	 * @return mixed|WP_Error The job's result.
	 */
	public static function run( $job, array $args = array() ) {
		if ( ! isset( self::JOBS[ $job ] ) ) {
			return new WP_Error( 'uscn_bad_job', 'Unknown job.' );
		}
		if ( is_admin() ) {
			self::$last_admin_context = true;
			return self::execute( $job, $args );
		}

		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::key( $token ),
			array(
				'job'     => $job,
				'args'    => $args,
				'expires' => time() + self::TTL,
			),
			self::TTL
		);

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => self::JOBS[ $job ],
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own loopback filter.
				'body'      => array(
					'action' => self::ACTION,
					'token'  => $token,
				),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		delete_transient( self::key( $token ) ); // Normally already used; never leave it behind.

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$data = $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

		if ( 200 === $code && is_array( $data ) && array_key_exists( 'success', $data ) ) {
			self::$last_admin_context = true;
			update_option( 'uscn_admin_context', array( 'ok' => true, 'time' => time() ), false );
			if ( ! $data['success'] ) {
				$e = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
				return new WP_Error(
					isset( $e['code'] ) ? (string) $e['code'] : 'uscn_job_failed',
					isset( $e['message'] ) ? (string) $e['message'] : 'The job failed.',
					isset( $e['status'] ) ? array( 'status' => (int) $e['status'] ) : array()
				);
			}
			return $data['data'];
		}

		// Loopback blocked or broken: run here, as before.
		self::$last_admin_context = false;
		update_option(
			'uscn_admin_context',
			array(
				'ok'    => false,
				'time'  => time(),
				'error' => is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code,
			),
			false
		);
		return self::execute( $job, $args );
	}

	private static function key( $token ) {
		return 'uscn_job_' . hash( 'sha256', $token );
	}

	/** The pending job for this request's token, without using it up. */
	private static function peek() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the one-time token is the credential.
		if ( ! wp_doing_ajax() || ! isset( $_POST['action'], $_POST['token'] ) || self::ACTION !== $_POST['action'] ) {
			return null;
		}
		$token = sanitize_text_field( wp_unslash( $_POST['token'] ) );
		// phpcs:enable
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$job = get_transient( self::key( $token ) );
		if ( ! is_array( $job ) || (int) $job['expires'] < time() ) {
			return null;
		}
		$job['token'] = $token;
		return $job;
	}

	/** Inside a valid job request, act as the administrator chosen for the dashboard. */
	public static function as_admin( $user_id ) {
		static $admin = null;
		if ( null === $admin ) {
			$admin = 0;
			if ( self::peek() ) {
				$u     = USCN_Login::login_user();
				$admin = $u ? (int) $u->ID : 0;
			}
		}
		return $admin ? $admin : $user_id;
	}

	/** admin-ajax.php handler: is_admin() is true here and admin_init has run. */
	public static function handle() {
		$job = self::peek();
		if ( ! $job ) {
			wp_send_json_error( array( 'code' => 'uscn_job_token', 'message' => 'Job token invalid or expired.' ), 403 );
		}
		delete_transient( self::key( $job['token'] ) ); // One use only.

		USCN_Activity::$actor = 'dashboard';
		$result = self::execute( $job['job'], is_array( $job['args'] ) ? $job['args'] : array() );
		if ( is_wp_error( $result ) ) {
			$err = $result->get_error_data();
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'status'  => is_array( $err ) && isset( $err['status'] ) ? (int) $err['status'] : 400,
				)
			);
		}
		wp_send_json_success( $result );
	}

	private static function execute( $job, array $args ) {
		switch ( $job ) {
			case 'status':
				return USCN_Status::collect( ! empty( $args['refresh'] ) );
			case 'update':
				return USCN_Updater::run( isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array() );
			case 'plugins':
				return USCN_Plugins::run(
					isset( $args['op'] ) ? (string) $args['op'] : '',
					isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array(),
					isset( $args['args'] ) && is_array( $args['args'] ) ? $args['args'] : array()
				);
		}
		return new WP_Error( 'uscn_bad_job', 'Unknown job.' );
	}
}
