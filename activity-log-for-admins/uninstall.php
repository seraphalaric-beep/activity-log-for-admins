<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$alfa_settings = (array) get_option( 'alfa_settings', [] );
$alfa_erase = in_array( $alfa_settings['erase'] ?? false, [ true, 1, '1' ], true );

if ( ! is_multisite() && $alfa_erase ) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove the plugin table only after the admin opted in to erase on deletion.
    if ( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'alfa_events' ) ) ) {
        delete_option( 'alfa_settings' );
        delete_option( 'alfa_health' );
        delete_option( 'alfa_schema' );
    }
}
wp_clear_scheduled_hook( 'alfa_cleanup' );
wp_clear_scheduled_hook( 'alfa_continue_cleanup' );
