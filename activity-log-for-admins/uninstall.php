<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$alfa_clear_site_data = static function (): void {
    global $wpdb;
    $alfa_settings = (array) get_option( 'alfa_settings', [] );
    $alfa_erase = in_array( $alfa_settings['erase'] ?? false, [ true, 1, '1' ], true );
    if ( $alfa_erase ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove this site's plugin table only after its admin opted in.
        if ( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'alfa_events' ) ) ) {
            delete_option( 'alfa_settings' );
            delete_option( 'alfa_health' );
            delete_option( 'alfa_schema' );
            delete_option( 'alfa_last_alert_sent' );
        }
    }
    wp_clear_scheduled_hook( 'alfa_cleanup' );
    wp_clear_scheduled_hook( 'alfa_continue_cleanup' );
    wp_clear_scheduled_hook( 'alfa_alert_digest' );
};

if ( is_multisite() ) {
    $alfa_offset = 0;
    do {
        $alfa_site_ids = get_sites( [ 'fields' => 'ids', 'number' => 200, 'offset' => $alfa_offset ] );
        foreach ( $alfa_site_ids as $alfa_site_id ) {
            switch_to_blog( (int) $alfa_site_id );
            $alfa_clear_site_data();
            restore_current_blog();
        }
        $alfa_offset += count( $alfa_site_ids );
    } while ( 200 === count( $alfa_site_ids ) );
} else {
    $alfa_clear_site_data();
}
