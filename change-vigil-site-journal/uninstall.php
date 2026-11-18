<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/scheduled-reports.php';
require_once __DIR__ . '/includes/sessions.php';

$cvsj_clear_site_data = static function (): void {
    global $wpdb;
    $cvsj_settings = (array) get_option( 'cvsj_settings', [] );
    $cvsj_erase = in_array( $cvsj_settings['erase'] ?? false, [ true, 1, '1' ], true );
    if ( $cvsj_erase ) {
        \ChangeVigilSiteJournal\Sessions::erase_all_activity_for_site();
        if ( ! \ChangeVigilSiteJournal\ScheduledReports::erase_history_files() ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Leave a server side diagnostic if explicit uninstall cannot remove private report copies.
            error_log( 'Change Vigil Site Journal could not remove every private report copy during uninstall.' );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove this site's plugin tables only after its admin opted in.
        if ( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i, %i', $wpdb->prefix . 'cvsj_events', $wpdb->prefix . 'cvsj_event_notes', $wpdb->prefix . 'cvsj_file_index' ) ) ) {
            delete_option( 'cvsj_settings' );
            delete_option( 'cvsj_health' );
            delete_option( 'cvsj_schema' );
            delete_option( 'cvsj_last_alert_sent' );
            delete_option( 'cvsj_file_scan_state' );
            delete_option( 'cvsj_saved_reports' );
            delete_option( 'cvsj_alert_rules' );
            delete_option( 'cvsj_alert_channels' );
            delete_option( 'cvsj_alert_rule_queue' );
            delete_option( 'cvsj_alert_rule_queue_lock' );
            delete_option( 'cvsj_alert_retry_generation' );
            delete_option( 'cvsj_alert_queue_paused' );
            delete_option( \ChangeVigilSiteJournal\ScheduledReports::SCHEDULES );
            delete_option( \ChangeVigilSiteJournal\ScheduledReports::HISTORY );
        }
    }
    wp_clear_scheduled_hook( 'cvsj_cleanup' );
    wp_clear_scheduled_hook( 'cvsj_continue_cleanup' );
    wp_clear_scheduled_hook( 'cvsj_alert_digest' );
    wp_clear_scheduled_hook( 'cvsj_file_scan' );
    wp_clear_scheduled_hook( 'cvsj_alert_rule_dispatch' );
    wp_clear_scheduled_hook( 'cvsj_alert_event_retry' );
    \ChangeVigilSiteJournal\ScheduledReports::deactivate_site();
    delete_option( 'cvsj_privacy_erase_state' );
};

if ( is_multisite() ) {
    $cvsj_offset = 0;
    do {
        $cvsj_site_ids = get_sites( [ 'fields' => 'ids', 'number' => 200, 'offset' => $cvsj_offset ] );
        foreach ( $cvsj_site_ids as $cvsj_site_id ) {
            switch_to_blog( (int) $cvsj_site_id );
            $cvsj_clear_site_data();
            restore_current_blog();
        }
        $cvsj_offset += count( $cvsj_site_ids );
    } while ( 200 === count( $cvsj_site_ids ) );
} else {
    $cvsj_clear_site_data();
}
