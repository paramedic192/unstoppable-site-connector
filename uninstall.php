<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'uscn_activity' ); // phpcs:ignore WordPress.DB

foreach ( array( 'uscn_connection', 'uscn_keys', 'uscn_pairing', 'uscn_versions', 'uscn_ack_id', 'uscn_last_push', 'uscn_branding', 'uscn_db_version', 'uscn_login', 'uscn_migrated', 'uscn_admin_context', 'uscn_security', 'uscn_dashboard_seen', 'uscn_blocked', 'uscn_enroll' ) as $uscn_opt ) {
	delete_option( $uscn_opt );
}
delete_transient( 'uscn_ssl' );
delete_transient( 'uscn_pair_failures' );
wp_clear_scheduled_hook( 'uscn_push' );
wp_clear_scheduled_hook( 'uscn_push_soon' );
wp_clear_scheduled_hook( 'uscn_remove_legacy' );
wp_clear_scheduled_hook( 'uscn_enroll' );

// Temporary data (cached lookups, one-time tokens): every transient this plugin made.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_uscn_' ) . '%', $wpdb->esc_like( '_transient_timeout_uscn_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time cleanup on uninstall.
