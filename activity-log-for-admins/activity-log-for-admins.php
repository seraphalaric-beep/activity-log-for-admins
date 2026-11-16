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
require_once __DIR__ . '/includes/admin.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

function activate( bool $network_wide = false ): void {
    if ( ! Store::install() ) {
        wp_die( esc_html__( 'The activity log table could not be installed or verified. Check the database permissions and try again.', 'activity-log-for-admins' ), '', [ 'back_link' => true ] );
    }
    Store::schedule();
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
                restore_current_blog();
            }
            $offset += count( $site_ids );
        } while ( 200 === count( $site_ids ) );
        return;
    }
    wp_clear_scheduled_hook( Store::CLEANUP );
    wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
    wp_clear_scheduled_hook( Store::ALERT_DIGEST );
}

function initialize_new_site( \WP_Site $site ): void {
    if ( ! switch_to_blog( (int) $site->blog_id ) ) {
        return;
    }
    try {
        Store::install();
        Store::schedule();
    } finally {
        restore_current_blog();
    }
}

add_action( 'plugins_loaded', [ Store::class, 'upgrade' ], 5 );
add_action( Store::CLEANUP, [ Store::class, 'cleanup' ] );
add_action( Store::CONTINUE_CLEANUP, [ Store::class, 'cleanup' ] );
add_action( Store::ALERT_DIGEST, [ Store::class, 'send_alert_digest' ] );
if ( is_multisite() ) {
    add_action( 'wp_initialize_site', __NAMESPACE__ . '\\initialize_new_site', 100, 1 );
}
Capture::register();
Admin::register( __FILE__ );
