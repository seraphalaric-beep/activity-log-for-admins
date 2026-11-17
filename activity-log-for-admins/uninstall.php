<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/scheduled-reports.php';
require_once __DIR__ . '/includes/sessions.php';

$alfa_clear_site_data = static function (): void {
    global $wpdb;
    $alfa_settings = (array) get_option( 'alfa_settings', [] );
    $alfa_erase = in_array( $alfa_settings['erase'] ?? false, [ true, 1, '1' ], true );
    if ( $alfa_erase ) {
        \ActivityLogForAdmins\Sessions::erase_all_activity_for_site();
        if ( ! \ActivityLogForAdmins\ScheduledReports::erase_history_files() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Leave a server side diagnostic if explicit uninstall cannot remove private report copies.
            error_log( 'Activity Log for Admins could not remove every private report copy during uninstall.' );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove this site's plugin tables only after its admin opted in.
        if ( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i, %i', $wpdb->prefix . 'alfa_events', $wpdb->prefix . 'alfa_event_notes', $wpdb->prefix . 'alfa_file_index' ) ) ) {
            delete_option( 'alfa_settings' );
            delete_option( 'alfa_health' );
            delete_option( 'alfa_schema' );
            delete_option( 'alfa_last_alert_sent' );
            delete_option( 'alfa_file_scan_state' );
            delete_option( 'alfa_saved_reports' );
            delete_option( 'alfa_alert_rules' );
            delete_option( 'alfa_alert_channels' );
            delete_option( 'alfa_alert_rule_queue' );
            delete_option( 'alfa_alert_rule_queue_lock' );
            delete_option( 'alfa_alert_retry_generation' );
            delete_option( 'alfa_alert_queue_paused' );
            delete_option( \ActivityLogForAdmins\ScheduledReports::SCHEDULES );
            delete_option( \ActivityLogForAdmins\ScheduledReports::HISTORY );
        }
    }
    wp_clear_scheduled_hook( 'alfa_cleanup' );
    wp_clear_scheduled_hook( 'alfa_continue_cleanup' );
    wp_clear_scheduled_hook( 'alfa_alert_digest' );
    wp_clear_scheduled_hook( 'alfa_file_scan' );
    wp_clear_scheduled_hook( 'alfa_alert_rule_dispatch' );
    wp_clear_scheduled_hook( 'alfa_alert_event_retry' );
    \ActivityLogForAdmins\ScheduledReports::deactivate_site();
    delete_option( 'alfa_privacy_erase_state' );
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
