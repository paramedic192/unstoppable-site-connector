<?php
/**
 * Plugin Name: Unstoppable Site Connector
 * Plugin URI: https://unstoppablesolutions.app/plugins/unstoppable-site-connector
 * Description: Connects this site to your Unstoppable Site Manager dashboard for one-click updates, plugin management, health checks, sign-in and client reports. Replaces FM Connector.
 * Version: 0.8.0
 * Author: Unstoppable Solutions
 * Author URI: https://unstoppablesolutions.app
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: unstoppable-site-connector
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Unstoppable_Site_Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'USCN_VERSION', '0.8.0' );
define( 'USCN_API_VERSION', 1 );
define( 'USCN_FILE', __FILE__ );
define( 'USCN_DIR', plugin_dir_path( __FILE__ ) );

require_once USCN_DIR . 'includes/class-auth.php';
require_once USCN_DIR . 'includes/class-security.php';
require_once USCN_DIR . 'includes/class-activity.php';
require_once USCN_DIR . 'includes/class-status.php';
require_once USCN_DIR . 'includes/class-updater.php';
require_once USCN_DIR . 'includes/class-plugins.php';
require_once USCN_DIR . 'includes/class-reporter.php';
require_once USCN_DIR . 'includes/class-login.php';
require_once USCN_DIR . 'includes/class-rest.php';
require_once USCN_DIR . 'includes/class-admin.php';
require_once USCN_DIR . 'includes/class-migrate.php';
require_once USCN_DIR . 'includes/class-admin-runner.php';
require_once USCN_DIR . 'includes/class-enroll.php';
require_once USCN_DIR . 'includes/class-branding.php';

/**
 * Bootstrap.
 */
final class USCN_Plugin {

	const DB_VERSION = 1;

	public static function init() {
		add_action( 'rest_api_init', array( 'USCN_REST', 'register' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade_db' ) );

		USCN_Activity::hooks();
		USCN_Reporter::hooks();
		USCN_Login::hooks();
		USCN_Migrate::hooks();
		USCN_Admin_Runner::hooks();
		USCN_Enroll::hooks();
		USCN_Branding::hooks();

		if ( is_admin() ) {
			USCN_Admin::hooks();
		}
	}

	public static function activate() {
		USCN_Activity::install();
		USCN_Migrate::run(); // Take over from FM Connector, if it is here.
		USCN_Activity::snapshot();
		USCN_Reporter::schedule();
		update_option( 'uscn_db_version', self::DB_VERSION, false );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'uscn_push' );
		wp_clear_scheduled_hook( 'uscn_push_soon' );
	}

	public static function maybe_upgrade_db() {
		if ( (int) get_option( 'uscn_db_version' ) < self::DB_VERSION ) {
			USCN_Activity::install();
			update_option( 'uscn_db_version', self::DB_VERSION, false );
		}
	}
}

register_activation_hook( __FILE__, array( 'USCN_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'USCN_Plugin', 'deactivate' ) );

USCN_Plugin::init();
