<?php
/**
 * Activity log: the record client reports are built from.
 *
 * Version changes are detected by comparing against a stored snapshot, so
 * updates are logged no matter how they happened: from the dashboard,
 * wp-admin, WordPress auto-updates, WP-CLI or a file upload over FTP.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Activity {

	const OPT_SNAPSHOT = 'uscn_versions';
	const KEEP_DAYS    = 180;

	/** Who is acting in this request: 'dashboard', a username, or 'system'. */
	public static $actor = null;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'uscn_activity';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				type varchar(20) NOT NULL,
				action varchar(40) NOT NULL,
				object varchar(191) NOT NULL DEFAULT '',
				actor varchar(60) NOT NULL DEFAULT '',
				details longtext NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	public static function hooks() {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'diff_and_log' ), 20, 0 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_activated' ), 10, 1 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_deactivated' ), 10, 1 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 1 );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 10, 1 );
		add_action( 'delete_user', array( __CLASS__, 'on_delete_user' ), 10, 1 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/* ---------- writing ---------- */

	public static function log( $type, $action, $object = '', $details = array() ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'type'       => substr( (string) $type, 0, 20 ),
				'action'     => substr( (string) $action, 0, 40 ),
				'object'     => substr( (string) $object, 0, 191 ),
				'actor'      => substr( self::actor(), 0, 60 ),
				'details'    => $details ? wp_json_encode( $details ) : null,
			)
		);
		USCN_Reporter::schedule_soon();
	}

	private static function actor() {
		if ( self::$actor ) {
			return self::$actor;
		}
		$u = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		return ( $u && $u->exists() ) ? $u->user_login : 'system';
	}

	/* ---------- version snapshot / diff ---------- */

	public static function current_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = array( $data['Name'], $data['Version'] );
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[ $slug ] = array( $theme->get( 'Name' ), $theme->get( 'Version' ) );
		}
		return array(
			'core'    => USCN_Status::core_version_on_disk(),
			'plugins' => $plugins,
			'themes'  => $themes,
		);
	}

	public static function snapshot() {
		update_option( self::OPT_SNAPSHOT, self::current_versions(), false );
	}

	public static function diff_and_log() {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}

		$old = get_option( self::OPT_SNAPSHOT );
		$new = self::current_versions();

		if ( ! is_array( $old ) ) {
			update_option( self::OPT_SNAPSHOT, $new, false );
			return;
		}

		if ( ! empty( $old['core'] ) && $old['core'] !== $new['core'] ) {
			self::log( 'core', 'updated', 'WordPress', array( 'from' => $old['core'], 'to' => $new['core'] ) );
		}

		foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $group => $type ) {
			$before = isset( $old[ $group ] ) ? $old[ $group ] : array();
			$after  = $new[ $group ];

			foreach ( $after as $id => $info ) {
				if ( ! isset( $before[ $id ] ) ) {
					self::log( $type, 'installed', $id, array( 'name' => $info[0], 'to' => $info[1] ) );
				} elseif ( $before[ $id ][1] !== $info[1] ) {
					self::log( $type, 'updated', $id, array( 'name' => $info[0], 'from' => $before[ $id ][1], 'to' => $info[1] ) );
				}
			}
			foreach ( $before as $id => $info ) {
				if ( ! isset( $after[ $id ] ) ) {
					self::log( $type, 'deleted', $id, array( 'name' => $info[0], 'from' => $info[1] ) );
				}
			}
		}

		update_option( self::OPT_SNAPSHOT, $new, false );
	}

	/* ---------- event hooks ---------- */

	private static function plugin_name( $plugin ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		return isset( $all[ $plugin ]['Name'] ) ? array( 'name' => $all[ $plugin ]['Name'] ) : array();
	}

	public static function on_activated( $plugin ) {
		self::log( 'plugin', 'activated', $plugin, self::plugin_name( $plugin ) );
	}

	public static function on_deactivated( $plugin ) {
		self::log( 'plugin', 'deactivated', $plugin, self::plugin_name( $plugin ) );
	}

	public static function on_switch_theme( $new_name ) {
		self::log( 'theme', 'switched', get_stylesheet(), array( 'name' => $new_name ) );
	}

	public static function on_user_register( $user_id ) {
		$u = get_userdata( $user_id );
		self::log( 'user', 'created', $u ? $u->user_login : (string) $user_id, array( 'roles' => $u ? $u->roles : array() ) );
	}

	public static function on_delete_user( $user_id ) {
		$u = get_userdata( $user_id );
		self::log( 'user', 'deleted', $u ? $u->user_login : (string) $user_id );
	}

	public static function on_login( $login, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'manage_options' ) ) {
			self::$actor = $login;
			self::log( 'user', 'admin_login', $login );
			self::$actor = null;
		}
	}

	/* ---------- reading / housekeeping ---------- */

	/** Newest activity row id, so the dashboard can skip asking when it already has everything. */
	public static function last_id() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- plugin's own table; name from $wpdb->prefix.
	}

	public static function since( $since_id = 0, $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', (int) $since_id, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as $i => $r ) {
			$rows[ $i ]['id']         = (int) $r['id'];
			$rows[ $i ]['created_at'] = str_replace( ' ', 'T', $r['created_at'] ) . 'Z';
			$rows[ $i ]['details']    = $r['details'] ? json_decode( $r['details'], true ) : null;
		}
		return $rows;
	}

	public static function prune() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
