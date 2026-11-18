<?php
// Run against an isolated WordPress database with the candidate ZIP installed.
if ( PHP_SAPI !== 'cli' || count( $argv ) !== 5 ) {
    exit( 1 );
}
[ , $root, $legacy_zip, $step, $erase_target ] = $argv;
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! preg_match( '/^cvsj_(?:coexist|pilot)[a-z0-9_]*$/', DB_NAME ) || ! in_array( $erase_target, [ 'alfa', 'cvsj' ], true ) ) {
    throw new RuntimeException( 'Use a disposable coexistence database.' );
}
$plugins = [
    'alfa' => 'activity-log-for-admins',
    'cvsj' => 'change-vigil-site-journal',
];
$check = static function ( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
};
$base = static fn( string $slug ): string => $slug . '/' . $slug . '.php';
$event_count = static function ( string $prefix ) use ( $wpdb ): int {
    return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . $prefix . '_events' ) );
};
if ( 'setup' === $step ) {
    $archive = new ZipArchive();
    $check( true === $archive->open( $legacy_zip ), 'The legacy release ZIP must open.' );
    $check( $archive->extractTo( WP_PLUGIN_DIR ), 'The legacy release ZIP must install.' );
    $archive->close();
    foreach ( $plugins as $prefix => $slug ) {
        $result = activate_plugin( $base( $slug ) );
        $check( ! is_wp_error( $result ), 'Activation failed for ' . $slug );
        $settings = (array) get_option( $prefix . '_settings', [] );
        $settings['retention'] = 'alfa' === $prefix ? 46 : 79;
        $settings['erase'] = $erase_target === $prefix;
        update_option( $prefix . '_settings', $settings );
    }
} elseif ( 'verify' === $step ) {
    $user = get_user_by( 'login', 'pilotadmin' );
    $check( $user instanceof WP_User, 'Pilot administrator is missing.' );
    wp_set_current_user( $user->ID );
    $marker = 'Coexistence page ' . wp_generate_uuid4();
    $check( 0 < wp_insert_post( [ 'post_title' => $marker, 'post_status' => 'publish', 'post_type' => 'page' ] ), 'Could not create test page.' );
    foreach ( $plugins as $prefix => $slug ) {
        $check( is_plugin_active( $base( $slug ) ), 'Both plugins must be active.' );
        $check( false !== wp_next_scheduled( $prefix . '_cleanup' ), 'Each plugin needs its own cleanup job.' );
        $request = new WP_REST_Request( 'GET', '/' . $slug . '/v1/export' );
        $request->set_param( 'q', $marker );
        $export = rest_do_request( $request );
        $check( 200 === $export->get_status() && 1 === count( $export->get_data()['first'] ?? [] ), 'Each plugin must export its own recorded change.' );
        $settings = rest_do_request( '/' . $slug . '/v1/settings/export' )->get_data();
        $check( $slug === ( $settings['plugin'] ?? '' ) && ( 'alfa' === $prefix ? 46 : 79 ) === ( $settings['settings']['retention'] ?? null ), 'Settings export identity or retention crossed plugin boundaries.' );
    }
    $other = 'alfa' === $erase_target ? 'cvsj' : 'alfa';
    deactivate_plugins( $base( $plugins[ $erase_target ] ) );
    $before = $event_count( $other );
    $check( true === uninstall_plugin( $base( $plugins[ $erase_target ] ) ), 'Could not run uninstall.' );
    $check( false === get_option( $erase_target . '_settings' ), 'Uninstall did not remove the requested settings.' );
    $check( $before === $event_count( $other ) && false !== get_option( $other . '_settings' ) && false !== wp_next_scheduled( $other . '_cleanup' ), 'Uninstall affected the other plugin.' );
    echo 'Coexistence and isolated uninstall passed for ' . $erase_target . PHP_EOL;
} else {
    exit( 1 );
}
