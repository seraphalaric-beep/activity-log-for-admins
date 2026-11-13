<?php
// Copy into a disposable local WordPress document root for the pilot suite.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
    http_response_code( 404 );
    exit;
}
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

use ActivityLogForAdmins\Store;

$operation = sanitize_key( $_POST['operation'] ?? '' );
if ( 'nonce' === $operation && is_user_logged_in() ) {
    wp_send_json( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
}
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( 'Pilot administrator required.', 403 );
}

const ALFA_PLUGIN = 'activity-log-for-admins/activity-log-for-admins.php';
global $wpdb;
if ( 'activate' === $operation ) {
    $result = activate_plugin( ALFA_PLUGIN );
    wp_send_json( [ 'active' => ! is_wp_error( $result ) && is_plugin_active( ALFA_PLUGIN ) ] );
}
$table = Store::table();

function fixture_check( mixed $result ): void {
    if ( is_wp_error( $result ) ) {
        throw new RuntimeException( $result->get_error_message() );
    }
    if ( false === $result ) {
        throw new RuntimeException( 'Fixture operation failed.' );
    }
}

function fixture_zip( string $kind, string $version ): string {
    $path = wp_tempnam( 'alfa-fixture-' . $kind . '.zip' );
    $zip = new ZipArchive();
    $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    if ( 'plugin' === $kind ) {
        $zip->addFromString( 'alfa-test-plugin/pilot.php', "<?php\n/* Plugin Name: Pilot fixture plugin\nVersion: $version\n*/" );
    } else {
        $zip->addFromString( 'alfa-test-theme/style.css', "/* Theme Name: Pilot fixture theme\nVersion: $version\n*/" );
        $zip->addFromString( 'alfa-test-theme/index.php', "<?php\n" );
    }
    $zip->close();
    return $path;
}

function fixture_component_events(): void {
    $plugin = 'alfa-test-plugin/pilot.php';
    $old_theme = get_stylesheet();
    $original = [];
    $version = [];
    foreach ( [ 'plugin', 'theme' ] as $type ) {
        $original[ $type ] = fixture_zip( $type, '1.0.0' );
        $version[ $type ] = fixture_zip( $type, '1.0.1' );
    }

    try {
        fixture_check( ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $original['plugin'] ) );
        fixture_check( activate_plugin( $plugin ) );
        $plugins = (object) [ 'response' => [
            $plugin => (object) [ 'package' => $version['plugin'], 'new_version' => '1.0.1' ],
            'absent/pilot.php' => (object) [ 'package' => WP_CONTENT_DIR . '/missing.zip', 'new_version' => '1.0.1' ],
        ] ];
        add_filter( 'pre_site_transient_update_plugins', static fn() => $plugins );
        $plugin_results = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->bulk_upgrade( [ $plugin, 'absent/pilot.php' ] );
        fixture_check( $plugin_results[ $plugin ] );
        deactivate_plugins( $plugin );
        fixture_check( delete_plugins( [ $plugin ] ) );

        fixture_check( ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $original['theme'] ) );
        switch_theme( 'alfa-test-theme' );
        switch_theme( $old_theme );
        $themes = (object) [ 'response' => [ 'alfa-test-theme' => [ 'package' => $version['theme'], 'new_version' => '1.0.1' ] ] ];
        add_filter( 'pre_site_transient_update_themes', static fn() => $themes );
        fixture_check( ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( 'alfa-test-theme' ) );
        fixture_check( delete_theme( 'alfa-test-theme' ) );
    } finally {
        foreach ( [ ...array_values( $original ), ...array_values( $version ) ] as $path ) {
            if ( is_file( $path ) ) {
                unlink( $path );
            }
        }
    }
}

function fixture_seed( int $count, string $name, string $summary, string $time ): void {
    global $wpdb;
    $table = Store::table();
    $ceiling = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . $table );
    for ( $i = 0; $i < $count; $i += 500 ) {
        $batch = min( 500, $count - $i );
        $values = [];
        for ( $j = 0; $j < $batch; ++$j ) {
            $key = $i + $j;
            $item = 'Routine seeded activity' === $name ? 'Routine seeded activity ' . ( $key % 9000 ) : $name;
            $values[] = $wpdb->prepare(
                "(%s,'user',1,'pilotadmin','page',%s,%s,'content_edited','info',%s,'{}')",
                $time, (string) ( $ceiling + $key + 1 ), $item, $summary
            );
        }
        fixture_check( $wpdb->query(
            "INSERT INTO $table (occurred_at,actor_kind,actor_id,actor_name,item_kind,item_key,item_name,action_key,severity,summary,details) VALUES "
            . implode( ',', $values )
        ) );
    }
}

try {
    switch ( $operation ) {
        case 'reset':
            fixture_check( $wpdb->query( 'TRUNCATE TABLE ' . $table ) );
            wp_send_json( [ 'ok' => true ] );

        case 'events':
            $previous_editor = get_user_by( 'login', 'piloteditor' );
            if ( $previous_editor ) {
                fixture_check( wp_delete_user( $previous_editor->ID ) );
            }
            $editor_password = wp_generate_password( 32, true, true );
            $editor = wp_insert_user( [ 'user_login' => 'piloteditor', 'user_email' => 'editor@example.test', 'user_pass' => $editor_password, 'role' => 'subscriber' ] );
            fixture_check( $editor );
            ( new WP_User( $editor ) )->set_role( 'editor' );
            $deleted = wp_insert_user( [ 'user_login' => 'pilotdelete', 'user_email' => 'delete@example.test', 'user_pass' => wp_generate_password() ] );
            fixture_check( $deleted );
            fixture_check( wp_delete_user( $deleted ) );
            for ( $i = 1; $i <= 8; ++$i ) {
                fixture_check( wp_insert_post( [ 'post_title' => "Routine page $i", 'post_type' => 'page', 'post_status' => 'publish' ], true ) );
            }
            $page = wp_insert_post( [ 'post_title' => 'Housing Guide', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => 'Original content' ], true );
            fixture_check( $page );
            fixture_check( wp_save_post_revision( $page ) );
            fixture_check( wp_update_post( [ 'ID' => $page, 'post_content' => 'New content' ], true ) );
            fixture_check( wp_update_post( [ 'ID' => $page, 'post_content' => 'New content' ], true ) );
            fixture_check( wp_update_post( [ 'ID' => $page, 'post_status' => 'draft' ], true ) );
            $trashed = wp_insert_post( [ 'post_title' => 'Trash pilot page', 'post_type' => 'page', 'post_status' => 'publish' ], true );
            fixture_check( $trashed );
            fixture_check( wp_trash_post( $trashed ) );
            fixture_check( wp_untrash_post( $trashed ) );
            $scheduled = wp_insert_post( [ 'post_title' => 'Scheduled pilot page', 'post_type' => 'page', 'post_status' => 'future', 'post_date' => date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ], true );
            fixture_check( $scheduled );
            wp_set_current_user( 0 );
            $cron = static fn(): bool => true;
            add_filter( 'wp_doing_cron', $cron );
            wp_publish_post( $scheduled );
            remove_filter( 'wp_doing_cron', $cron );
            wp_set_current_user( get_user_by( 'login', 'pilotadmin' )->ID );
            update_option( 'blog_public', (int) get_option( 'blog_public' ) ? '0' : '1' );
            fixture_component_events();
            $rest = wp_insert_post( [ 'post_title' => 'REST editor fixture', 'post_type' => 'page', 'post_status' => 'publish' ], true );
            fixture_check( $rest );
            wp_send_json( [ 'restPage' => $rest, 'scheduled' => $scheduled, 'editorPassword' => $editor_password ] );

        case 'xss':
            Store::add( [
                'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                'item_kind' => 'page', 'item_key' => 'security-probe', 'item_name' => 'Security probe',
                'action_key' => 'content_edited', 'severity' => 'warning',
                'summary' => 'XSS test probe <img src=x onerror=alert(1)>',
            ], [ 'changes' => [ 'Title' => [ '<svg onload=alert(1)>', '<script>alert(1)</script>' ] ] ] );
            wp_send_json( [ 'ok' => true ] );

        case 'seed':
            fixture_seed( 552, '=1+1', 'Export fixture', gmdate( 'Y-m-d H:i:s' ) );
            fixture_seed( 65, 'Routine seeded activity', 'Routine seeded activity', gmdate( 'Y-m-d H:i:s' ) );
            wp_send_json( [ 'ok' => true ] );

        case 'permissions':
            $namespace = '/activity-log-for-admins/v1';
            $matrix = [];
            foreach ( [ 'anonymous' => 0, 'editor' => get_user_by( 'login', 'piloteditor' )->ID ] as $label => $user ) {
                wp_set_current_user( $user );
                foreach ( [ [ 'GET', '/events' ], [ 'GET', '/events/1' ], [ 'GET', '/export' ], [ 'GET', '/settings' ], [ 'POST', '/settings' ] ] as [ $method, $route ] ) {
                    $request = new WP_REST_Request( $method, $namespace . $route );
                    $request->set_body_params( [ 'retention' => 90, 'erase' => false ] );
                    $matrix[ $label ][] = rest_do_request( $request )->get_status();
                }
            }
            wp_send_json( $matrix );

        case 'health':
            fixture_seed( 1001, 'Expired fixture', 'Expired fixture', '2024-01-01 00:00:00' );
            $count_expired = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE item_name='Expired fixture'" );
            Store::cleanup();
            $batch = 1 === $count_expired() && (bool) wp_next_scheduled( Store::CONTINUE_CLEANUP );
            Store::cleanup();
            $deleted = 0 === $count_expired();
            $wpdb->suppress_errors( true );
            fixture_check( $wpdb->query( "RENAME TABLE $table TO {$table}_test" ) );
            try {
                Store::cleanup();
                $primary = wp_insert_post( [ 'post_title' => 'Primary write with log unavailable', 'post_status' => 'publish' ], true );
            } finally {
                fixture_check( $wpdb->query( "RENAME TABLE {$table}_test TO $table" ) );
            }
            $health = Store::health();
            $before = Store::latest_id();
            deactivate_plugins( ALFA_PLUGIN );
            $unscheduled = ! wp_next_scheduled( Store::CLEANUP );
            fixture_check( activate_plugin( ALFA_PLUGIN ) );
            wp_send_json( [
                'batch' => $batch,
                'deleted' => $deleted,
                'cleanupFailure' => (bool) $health['cleanup_error'],
                'writeFailure' => (bool) $health['write_error'],
                'primaryWrite' => is_int( $primary ) && $primary > 0,
                'reactivated' => $unscheduled && Store::latest_id() >= $before && (bool) wp_next_scheduled( Store::CLEANUP ),
            ] );

        case 'benchmark':
            fixture_seed( 100000, 'Routine seeded activity', 'Bulk benchmark entry', gmdate( 'Y-m-d H:i:s' ) );
            $samples = [ 'first' => [], 'filtered' => [], 'capture' => [] ];
            $filters = [ 'q' => 'Routine seeded activity 8999', 'actor' => '1', 'action' => 'content_edited', 'severity' => 'info' ];
            $measure = static function( callable $run ): float {
                $start = hrtime( true );
                $value = $run();
                fixture_check( $value );
                return ( hrtime( true ) - $start ) / 1000000;
            };
            for ( $i = 0; $i < 25; ++$i ) {
                $samples['first'][] = $measure( static fn() => Store::query( [], 51 ) );
                $samples['filtered'][] = $measure( static fn() => Store::query( $filters, 51 ) );
                $samples['capture'][] = $measure( static fn() => wp_insert_post( [ 'post_title' => 'Capture timing', 'post_status' => 'publish' ], true ) );
            }
            remove_action( 'wp_after_insert_post', [ ActivityLogForAdmins\Capture::class, 'post' ], 30 );
            $baseline = [];
            for ( $i = 0; $i < 25; ++$i ) {
                $baseline[] = $measure( static fn() => wp_insert_post( [ 'post_title' => 'Capture timing', 'post_status' => 'publish' ], true ) );
            }
            $p95 = static function ( array $values ): float {
                sort( $values );
                return round( $values[ (int) ceil( count( $values ) * .95 ) - 1 ], 2 );
            };
            wp_send_json( [
                'seeded' => 100000, 'firstP95' => $p95( $samples['first'] ),
                'filteredP95' => $p95( $samples['filtered'] ), 'captureP95' => $p95( $samples['capture'] ),
                'captureDisabledP95' => $p95( $baseline ), 'database' => $wpdb->db_server_info(),
            ] );

        case 'deactivate':
            deactivate_plugins( ALFA_PLUGIN );
            wp_send_json( [ 'inactive' => ! is_plugin_active( ALFA_PLUGIN ) ] );

        case 'uninstall':
            define( 'WP_UNINSTALL_PLUGIN', ALFA_PLUGIN );
            $script = WP_PLUGIN_DIR . '/activity-log-for-admins/uninstall.php';
            $before = Store::latest_id();
            update_option( Store::SETTINGS, [ 'retention' => 60, 'erase' => false ] );
            require $script;
            $preserved = Store::latest_id() === $before;
            update_option( Store::SETTINGS, [ 'retention' => 60, 'erase' => true ] );
            require $script;
            $erased = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
                && false === get_option( Store::SETTINGS ) && false === get_option( Store::SCHEMA ) && false === get_option( Store::HEALTH );
            wp_send_json( compact( 'preserved', 'erased' ) );
    }
} catch ( Throwable $error ) {
    wp_send_json_error( $error->getMessage(), 500 );
}
wp_send_json_error( 'Unknown fixture.', 400 );
