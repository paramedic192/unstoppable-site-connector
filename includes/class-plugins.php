<?php
/**
 * Plugin management requested by the dashboard: install, activate,
 * deactivate and delete. Uses WordPress's own Plugin_Upgrader and plugin
 * functions, so it behaves exactly like doing it in wp-admin.
 */

defined( 'ABSPATH' ) || exit;

final class USCN_Plugins {

	const MAX_ITEMS = 25;

	/** Never let the dashboard switch off or remove the connector itself. */
	private static function is_self( $file ) {
		return plugin_basename( USCN_FILE ) === $file;
	}

	/** Is this package address on the connected dashboard's own site? */
	private static function from_dashboard( $url ) {
		$conn = USCN_Auth::connection();
		$dash = ! empty( $conn['push_url'] ) ? strtolower( (string) wp_parse_url( $conn['push_url'], PHP_URL_HOST ) ) : '';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return '' !== $dash && $host === $dash;
	}

	private static function prepare() {
		USCN_Status::load_admin_includes();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );
	}

	/**
	 * @param string $op    activate|deactivate|delete|install
	 * @param array  $items For install: [{source: 'wporg', slug} | {source: 'url', url}]. Otherwise plugin files.
	 * @param array  $args  activate (bool), overwrite (bool) for install.
	 * @return array|WP_Error
	 */
	public static function run( $op, array $items, array $args = array() ) {
		if ( ! in_array( $op, array( 'activate', 'deactivate', 'delete', 'install' ), true ) ) {
			return new WP_Error( 'uscn_bad_op', 'Unknown plugin action.', array( 'status' => 400 ) );
		}
		if ( ! $items || count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error( 'uscn_bad_items', 'Send between 1 and ' . self::MAX_ITEMS . ' plugins.', array( 'status' => 400 ) );
		}
		self::prepare();

		if ( 'install' === $op ) {
			if ( 'direct' !== get_filesystem_method() ) {
				return new WP_Error( 'uscn_filesystem', 'This server needs FTP credentials to install plugins, so remote installs are not possible.', array( 'status' => 409 ) );
			}
			$results = array();
			foreach ( $items as $item ) {
				$results[] = self::install( is_array( $item ) ? $item : array(), ! empty( $args['activate'] ), ! empty( $args['overwrite'] ) );
			}
		} else {
			$installed = get_plugins();
			$files     = array();
			$results   = array();
			foreach ( $items as $file ) {
				$file = is_string( $file ) ? $file : '';
				if ( ! isset( $installed[ $file ] ) ) {
					$results[] = array( 'id' => $file, 'result' => 'skipped', 'error' => 'Not installed on this site.' );
				} elseif ( self::is_self( $file ) && 'activate' !== $op ) {
					$results[] = array( 'id' => $file, 'result' => 'skipped', 'error' => 'The connector cannot ' . $op . ' itself.' );
				} else {
					$files[] = $file;
				}
			}
			if ( $files ) {
				$results = array_merge( $results, call_user_func( array( __CLASS__, $op ), $files, $installed ) );
			}
		}

		USCN_Activity::diff_and_log(); // Installs and deletes show up in the activity log.
		return array(
			'results' => $results,
			'status'  => USCN_Status::collect( false ),
		);
	}

	private static function activate( array $files, array $installed ) {
		$out = array();
		foreach ( $files as $file ) {
			if ( is_plugin_active( $file ) ) {
				$out[] = array( 'id' => $file, 'name' => $installed[ $file ]['Name'], 'result' => 'unchanged' );
				continue;
			}
			$r     = activate_plugin( $file, '', false, false );
			$out[] = is_wp_error( $r )
				? array( 'id' => $file, 'name' => $installed[ $file ]['Name'], 'result' => 'failed', 'error' => $r->get_error_message() )
				: array( 'id' => $file, 'name' => $installed[ $file ]['Name'], 'result' => 'activated' );
		}
		return $out;
	}

	private static function deactivate( array $files, array $installed ) {
		$out    = array();
		$active = array_filter( $files, 'is_plugin_active' );
		deactivate_plugins( $active, false, false );
		foreach ( $files as $file ) {
			$was   = in_array( $file, $active, true );
			$out[] = array(
				'id'     => $file,
				'name'   => $installed[ $file ]['Name'],
				'result' => ! $was ? 'unchanged' : ( is_plugin_active( $file ) ? 'failed' : 'deactivated' ),
			);
		}
		return $out;
	}

	private static function delete( array $files, array $installed ) {
		deactivate_plugins( array_filter( $files, 'is_plugin_active' ), false, false );
		$r = delete_plugins( $files );
		wp_clean_plugins_cache( false );
		$after = get_plugins();
		$out   = array();
		foreach ( $files as $file ) {
			$gone  = ! isset( $after[ $file ] );
			$entry = array( 'id' => $file, 'name' => $installed[ $file ]['Name'], 'result' => $gone ? 'deleted' : 'failed' );
			if ( ! $gone ) {
				$entry['error'] = is_wp_error( $r ) ? $r->get_error_message() : 'Could not delete the plugin files.';
			}
			$out[] = $entry;
		}
		return $out;
	}

	private static function install( array $item, $activate, $overwrite ) {
		$source = isset( $item['source'] ) ? $item['source'] : '';
		$label  = '';
		$package = '';

		if ( 'wporg' === $source ) {
			$slug  = isset( $item['slug'] ) ? sanitize_key( $item['slug'] ) : '';
			$label = $slug;
			if ( '' === $slug ) {
				return array( 'id' => '', 'result' => 'failed', 'error' => 'Missing plugin slug.' );
			}
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			$info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $info ) || empty( $info->download_link ) ) {
				return array( 'id' => $slug, 'result' => 'failed', 'error' => is_wp_error( $info ) ? $info->get_error_message() : 'Not found in the WordPress plugin directory.' );
			}
			$package = $info->download_link;
			$label   = $info->name;
		} elseif ( 'url' === $source ) {
			$package = isset( $item['url'] ) ? esc_url_raw( $item['url'], array( 'https', 'http' ) ) : '';
			$label   = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : basename( (string) wp_parse_url( $package, PHP_URL_PATH ) );
			if ( ! $package || ! wp_http_validate_url( $package ) ) {
				return array( 'id' => $label, 'result' => 'failed', 'error' => 'The package URL is not a valid public address.' );
			}
			// Only from the connected dashboard itself, never from any other address.
			if ( ! self::from_dashboard( $package ) ) {
				return array( 'id' => $label, 'result' => 'failed', 'error' => 'Plugin files can only be installed from WordPress.org or from the connected dashboard.' );
			}
		} else {
			return array( 'id' => '', 'result' => 'failed', 'error' => 'Unknown install source.' );
		}

		$before   = array_keys( get_plugins() );
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$r        = $upgrader->install( $package, array( 'overwrite_package' => (bool) $overwrite ) );
		wp_clean_plugins_cache( false );

		if ( is_wp_error( $r ) || ! $r ) {
			$errors  = $skin->get_errors();
			$message = is_wp_error( $r ) ? $r->get_error_message() : ( $errors instanceof WP_Error && $errors->has_errors() ? implode( ' ', $errors->get_error_messages() ) : 'Install failed.' );
			$exists  = ( is_wp_error( $r ) && 'folder_exists' === $r->get_error_code() ) || ( $errors instanceof WP_Error && in_array( 'folder_exists', $errors->get_error_codes(), true ) );
			return array(
				'id'     => $label,
				'name'   => $label,
				'result' => $exists ? 'exists' : 'failed',
				'error'  => $exists ? 'Already installed. Tick "Replace if already installed" to overwrite it.' : $message,
			);
		}

		$file = $upgrader->plugin_info();
		if ( ! $file ) {
			$new  = array_values( array_diff( array_keys( get_plugins() ), $before ) );
			$file = $new ? $new[0] : '';
		}
		$all   = get_plugins();
		$entry = array(
			'id'      => $file,
			'name'    => isset( $all[ $file ]['Name'] ) ? $all[ $file ]['Name'] : $label,
			'version' => isset( $all[ $file ]['Version'] ) ? $all[ $file ]['Version'] : '',
			'result'  => 'installed',
		);
		if ( $activate && $file && ! is_plugin_active( $file ) ) {
			$a = activate_plugin( $file, '', false, false );
			if ( is_wp_error( $a ) ) {
				$entry['error'] = 'Installed, but activation failed: ' . $a->get_error_message();
			} else {
				$entry['activated'] = true;
			}
		}
		return $entry;
	}
}
