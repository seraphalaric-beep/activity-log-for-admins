<?php
/**
 * Plugin Name: Activity Log for Admins
 * Description: Investigate WordPress changes, with searchable history and CSV export.
 * Author: Bryan Mc Namara
 * Version: 1.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: activity-log-for-admins
 */

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/capture.php';
require_once __DIR__ . '/includes/sessions.php';
require_once __DIR__ . '/includes/developer-api.php';
require_once __DIR__ . '/includes/alerts.php';
require_once __DIR__ . '/includes/file-monitor.php';
require_once __DIR__ . '/includes/scheduled-reports.php';
require_once __DIR__ . '/includes/privacy.php';
require_once __DIR__ . '/includes/admin.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

function activate( bool $network_wide = false ): void {
    if ( $network_wide && is_multisite() ) {
        $offset = 0;
        do {
            $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 200, 'offset' => $offset, 'network_id' => get_current_network_id() ] );
            foreach ( $site_ids as $site_id ) {
                if ( ! switch_to_blog( (int) $site_id ) ) {
                    continue;
                }
                try {
                    if ( ! activate_site() ) {
                        wp_die( esc_html__( 'The activity log table could not be installed or verified for every site. Check database permissions and try again.', 'activity-log-for-admins' ), '', [ 'back_link' => true ] );
                    }
                } finally {
                    restore_current_blog();
                }
            }
            $offset += count( $site_ids );
        } while ( 200 === count( $site_ids ) );
        return;
    }

    if ( ! activate_site() ) {
        wp_die( esc_html__( 'The activity log table could not be installed or verified. Check the database permissions and try again.', 'activity-log-for-admins' ), '', [ 'back_link' => true ] );
    }
}

function activate_site(): bool {
    if ( ! Store::install() ) {
        return false;
    }
    Store::schedule();
    FileMonitor::ensure_schedule();
    ScheduledReports::ensure_schedule();
    return true;
}

function deactivate( bool $network_wide = false ): void {
    if ( $network_wide && is_multisite() ) {
        $offset = 0;
        do {
            $site_ids = get_sites( [ 'fields' => 'ids', 'number' => 200, 'offset' => $offset ] );
            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );
                wp_clear_scheduled_hook( Store::CLEANUP );
                wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
                wp_clear_scheduled_hook( Store::ALERT_DIGEST );
                wp_clear_scheduled_hook( FileMonitor::HOOK );
                Alerts::deactivate_site();
                ScheduledReports::deactivate_site();
                restore_current_blog();
            }
            $offset += count( $site_ids );
        } while ( 200 === count( $site_ids ) );
        return;
    }
    wp_clear_scheduled_hook( Store::CLEANUP );
    wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
    wp_clear_scheduled_hook( Store::ALERT_DIGEST );
    wp_clear_scheduled_hook( FileMonitor::HOOK );
    Alerts::deactivate_site();
    ScheduledReports::deactivate_site();
}

function initialize_new_site( \WP_Site $site ): void {
    if ( ! switch_to_blog( (int) $site->blog_id ) ) {
        return;
    }
    try {
        Store::install();
        Store::schedule();
        FileMonitor::ensure_schedule();
        ScheduledReports::ensure_schedule();
    } finally {
        restore_current_blog();
    }
}

add_action( 'plugins_loaded', [ Store::class, 'upgrade' ], 5 );
add_action( Store::CLEANUP, [ Store::class, 'cleanup' ] );
add_action( Store::CONTINUE_CLEANUP, [ Store::class, 'cleanup' ] );
add_action( Store::ALERT_DIGEST, [ Store::class, 'send_alert_digest' ] );
add_action( Alerts::HOOK, [ Alerts::class, 'process_queue' ] );
add_action( Alerts::RETRY_HOOK, [ Alerts::class, 'retry_event' ], 10, 3 );
add_action( ScheduledReports::TICK, [ ScheduledReports::class, 'tick' ] );
add_action( ScheduledReports::CONTINUE, [ ScheduledReports::class, 'tick' ] );
add_action( 'admin_post_alfa_report_download', [ ScheduledReports::class, 'download_report' ] );
add_action( 'init', [ ScheduledReports::class, 'ensure_schedule' ], 20 );
add_action( 'init', [ Alerts::class, 'schedule_pending' ], 21 );
add_filter( 'cron_schedules', [ ScheduledReports::class, 'cron_schedules' ] );
if ( is_multisite() ) {
    add_action( 'wp_initialize_site', __NAMESPACE__ . '\\initialize_new_site', 100, 1 );
}
Capture::register();
Sessions::register();
FileMonitor::register();
Privacy::register();
Admin::register( __FILE__ );
