<?php
/**
 * Plugin Name: Activity Log for Admins
 * Description: Investigate WordPress changes, with searchable history and CSV export.
 * Version: 1.0.0
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

function activate(): void {
    if ( is_multisite() ) {
        wp_die( esc_html__( 'Activity Log for Admins supports single WordPress sites in this release.', 'activity-log-for-admins' ), '', [ 'back_link' => true ] );
    }
    if ( ! Store::install() ) {
        wp_die( esc_html__( 'The activity log table could not be created. Check the database permissions and try again.', 'activity-log-for-admins' ), '', [ 'back_link' => true ] );
    }
    Store::schedule();
}

function deactivate(): void {
    wp_clear_scheduled_hook( Store::CLEANUP );
    wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
}

if ( ! is_multisite() ) {
    add_action( 'plugins_loaded', [ Store::class, 'upgrade' ], 5 );
    add_action( Store::CLEANUP, [ Store::class, 'cleanup' ] );
    add_action( Store::CONTINUE_CLEANUP, [ Store::class, 'cleanup' ] );
    Capture::register();
    Admin::register( __FILE__ );
}
