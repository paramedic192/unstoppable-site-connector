<?php
/**
 * Takes over from FM Connector, this plugin's former name.
 *
 * On activation (or the first load after it) the connection, keys, settings and
 * activity log are copied from FM Connector's options and table. FM Connector is
 * never deactivated or deleted automatically: this plugin's settings screen tells the
 * site owner it can be. The dashboard keeps working the whole time: the keys are the
 * same and the old REST namespace stays registered.
 *
 * Runs only when FM Connector's data exists and this plugin has none of its own.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Migrate {

	const LEGACY_FILE = 'fm-connector/fm-connector.php';
	const STATE       = 'uscn_migrated';

	/** Option names that move across: old => new. */
	const OPTIONS = array(
		'fmc_connection' => 'uscn_connection',
		'fmc_keys'       => 'uscn_keys',
		'fmc_branding'   => 'uscn_branding',
		'fmc_login'      => 'uscn_login',
		'fmc_ack_id'     => 'uscn_ack_id',
		'fmc_last_push'  => 'uscn_last_push',
		'fmc_versions'   => 'uscn_versions',
	);

	public static function hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_run' ), 5 );
	}

	public static function maybe_run() {
		if ( get_option( self::STATE ) || ! get_option( 'fmc_connection' ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Copy FM Connector's data, then switch FM Connector off.
	 */
	public static function run() {
		if ( get_option( self::STATE ) ) {
			return;
		}
		$had_legacy = (bool) get_option( 'fmc_connection' ) || (bool) get_option( 'fmc_keys' );

		if ( $had_legacy && ! get_option( 'uscn_connection' ) ) {
			foreach ( self::OPTIONS as $old => $new ) {
				$value = get_option( $old, null );
				if ( null !== $value && false === get_option( $new, false ) ) {
					update_option( $new, $value, false );
				}
			}
			self::copy_activity();
		}

		update_option(
			self::STATE,
			array(
				'time'     => time(),
				'migrated' => $had_legacy,
			),
			false
		);
		// FM Connector is left as it is: the site owner deactivates and deletes it (the settings screen says so).
	}

	/** FM Connector is still installed (switched off) after its connection came across. */
	public static function legacy_left() {
		$state = get_option( self::STATE );
		return is_array( $state ) && ! empty( $state['migrated'] ) && file_exists( WP_PLUGIN_DIR . '/' . self::LEGACY_FILE );
	}

	/** Activity log rows keep their IDs, so the dashboard's sync carries on where it left off. */
	private static function copy_activity() {
		global $wpdb;
		$old = $wpdb->prefix . 'fmc_activity';
		$new = USCN_Activity::table();
		USCN_Activity::install();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) !== $old ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" ) > 0 ) {
			return;
		}
		$cols = 'id, created_at, type, action, object, actor, details';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table names from $wpdb->prefix.
		$wpdb->query( "INSERT INTO {$new} ({$cols}) SELECT {$cols} FROM {$old}" );
	}
}
