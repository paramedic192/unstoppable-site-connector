<?php
/**
 * Runs updates using WordPress's own upgrader classes, then re-checks
 * so the dashboard learns what is still outstanding (no "update, refresh,
 * update again" loop).
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Updater {

	/**
	 * @param array $items Each item: ['type' => 'plugin'|'theme'|'core', 'id' => plugin file or theme slug].
	 * @return array|WP_Error
	 */
	public static function run( array $items ) {
		USCN_Status::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error(
				'uscn_filesystem',
				'This server needs FTP credentials for updates (FS_METHOD is not "direct"), so remote updates are not possible.',
				array( 'status' => 409 )
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );

		// Make sure the upgrader sees the newest packages.
		USCN_Status::refresh_update_data();

		$plugins = array();
		$themes  = array();
		$core    = false;
		foreach ( $items as $item ) {
			$type = isset( $item['type'] ) ? $item['type'] : '';
			$id   = isset( $item['id'] ) ? (string) $item['id'] : '';
			if ( 'plugin' === $type && '' !== $id ) {
				$plugins[] = $id;
			} elseif ( 'theme' === $type && '' !== $id ) {
				$themes[] = $id;
			} elseif ( 'core' === $type ) {
				$core = true;
			}
		}

		$results = array();
		if ( $plugins ) {
			$results = array_merge( $results, self::update_plugins( array_unique( $plugins ) ) );
		}
		if ( $themes ) {
			$results = array_merge( $results, self::update_themes( array_unique( $themes ) ) );
		}
		if ( $core ) {
			$results[] = self::update_core();
		}

		// Log what changed, then re-check with wordpress.org.
		USCN_Activity::diff_and_log();
		$status = USCN_Status::collect( true );

		return array(
			'results' => $results,
			'status'  => $status,
		);
	}

	private static function update_plugins( array $files ) {
		$installed    = get_plugins();
		$before       = array();
		$was_active   = array();
		$out          = array();
		$to_update    = array();

		foreach ( $files as $file ) {
			if ( ! isset( $installed[ $file ] ) ) {
				$out[] = array( 'type' => 'plugin', 'id' => $file, 'result' => 'failed', 'error' => 'Plugin is not installed.' );
				continue;
			}
			$before[ $file ]     = $installed[ $file ]['Version'];
			$was_active[ $file ] = is_plugin_active( $file );
			$to_update[]         = $file;
		}
		if ( ! $to_update ) {
			return $out;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$raw      = $upgrader->bulk_upgrade( $to_update );
		$errors   = self::skin_errors( $skin );

		wp_clean_plugins_cache( true );
		$after = get_plugins();

		foreach ( $to_update as $file ) {
			$new_version = isset( $after[ $file ] ) ? $after[ $file ]['Version'] : null;
			$r           = is_array( $raw ) && array_key_exists( $file, $raw ) ? $raw[ $file ] : null;

			if ( $new_version && version_compare( $new_version, $before[ $file ], '>' ) ) {
				$result = 'updated';
			} elseif ( true === $r ) {
				$result = 'up_to_date';
			} else {
				$result = 'failed';
			}

			// Upgrades should leave active plugins active; make sure of it.
			if ( 'updated' === $result && $was_active[ $file ] && ! is_plugin_active( $file ) ) {
				activate_plugin( $file, '', is_multisite() && is_plugin_active_for_network( $file ), true );
			}

			$entry = array(
				'type'   => 'plugin',
				'id'     => $file,
				'from'   => $before[ $file ],
				'to'     => $new_version,
				'result' => $result,
			);
			if ( 'failed' === $result ) {
				$entry['error'] = is_wp_error( $r ) ? $r->get_error_message() : ( $errors ? implode( ' ', $errors ) : 'Update failed.' );
			}
			$out[] = $entry;
		}
		return $out;
	}

	private static function update_themes( array $slugs ) {
		$out       = array();
		$before    = array();
		$to_update = array();

		foreach ( $slugs as $slug ) {
			$t = wp_get_theme( $slug );
			if ( ! $t->exists() ) {
				$out[] = array( 'type' => 'theme', 'id' => $slug, 'result' => 'failed', 'error' => 'Theme is not installed.' );
				continue;
			}
			$before[ $slug ] = $t->get( 'Version' );
			$to_update[]     = $slug;
		}
		if ( ! $to_update ) {
			return $out;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$raw      = $upgrader->bulk_upgrade( $to_update );
		$errors   = self::skin_errors( $skin );

		wp_clean_themes_cache( true );

		foreach ( $to_update as $slug ) {
			$new_version = wp_get_theme( $slug )->get( 'Version' );
			$r           = is_array( $raw ) && array_key_exists( $slug, $raw ) ? $raw[ $slug ] : null;

			if ( $new_version && version_compare( $new_version, $before[ $slug ], '>' ) ) {
				$result = 'updated';
			} elseif ( true === $r ) {
				$result = 'up_to_date';
			} else {
				$result = 'failed';
			}

			$entry = array(
				'type'   => 'theme',
				'id'     => $slug,
				'from'   => $before[ $slug ],
				'to'     => $new_version,
				'result' => $result,
			);
			if ( 'failed' === $result ) {
				$entry['error'] = is_wp_error( $r ) ? $r->get_error_message() : ( $errors ? implode( ' ', $errors ) : 'Update failed.' );
			}
			$out[] = $entry;
		}
		return $out;
	}

	private static function update_core() {
		$from   = USCN_Status::core_version_on_disk();
		$update = null;
		foreach ( (array) get_core_updates() as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$update = $u;
				break;
			}
		}
		if ( ! $update ) {
			return array( 'type' => 'core', 'id' => 'wordpress', 'from' => $from, 'to' => $from, 'result' => 'up_to_date' );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$r        = $upgrader->upgrade( $update, array( 'attempt_rollback' => true ) );
		$to       = USCN_Status::core_version_on_disk();

		if ( is_wp_error( $r ) ) {
			return array( 'type' => 'core', 'id' => 'wordpress', 'from' => $from, 'to' => $to, 'result' => 'failed', 'error' => $r->get_error_message() );
		}
		return array(
			'type'   => 'core',
			'id'     => 'wordpress',
			'from'   => $from,
			'to'     => $to,
			'result' => version_compare( $to, $from, '>' ) ? 'updated' : 'failed',
		);
	}

	private static function skin_errors( $skin ) {
		$errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
		return ( $errors instanceof WP_Error ) ? $errors->get_error_messages() : array();
	}
}
