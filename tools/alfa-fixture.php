<?php
// Copy into a disposable local WordPress document root for the pilot suite.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
    http_response_code( 404 );
    exit;
}
require __DIR__ . '/wp-load.php';
foreach ( [ 'admin.php', 'nav-menu.php', 'comment.php', 'class-wp-upgrader.php' ] as $alfa_include ) {
    $alfa_path = ABSPATH . 'wp-admin/includes/' . $alfa_include;
    if ( is_file( $alfa_path ) ) {
        require_once $alfa_path;
    }
}

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

function fixture_delete_account( int $user_id ): bool {
    if ( is_multisite() && function_exists( 'wpmu_delete_user' ) ) {
        return wpmu_delete_user( $user_id );
    }
    return wp_delete_user( $user_id );
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

function fixture_extended_events( int $editor_id ): array {
	$suffix = 'alfa-coverage-' . strtolower( wp_generate_password( 6, false, false ) );
	$title = 'Pilot book ' . $suffix;
	$book = wp_insert_post( [
		'post_title' => $title,
		'post_type' => 'alfa_book',
		'post_status' => 'publish',
		'post_content' => 'Original book content',
		'comment_status' => 'open',
	], true );
	fixture_check( $book );
	fixture_check( wp_update_post( [ 'ID' => $book, 'post_title' => 'Updated ' . $title, 'post_content' => 'Updated book content' ], true ) );

	$genre = wp_insert_term( 'Pilot genre ' . $suffix, 'alfa_genre' );
	fixture_check( $genre );
	$genre_id = (int) $genre['term_id'];
	fixture_check( wp_set_object_terms( $book, [ $genre_id ], 'alfa_genre' ) );
	fixture_check( wp_set_object_terms( $book, [], 'alfa_genre' ) );
	fixture_check( wp_update_term( $genre_id, 'alfa_genre', [ 'name' => 'Updated pilot genre ' . $suffix ] ) );
	$category = wp_insert_term( 'Pilot category ' . $suffix, 'category' );
	$tag = wp_insert_term( 'Pilot tag ' . $suffix, 'post_tag' );
	fixture_check( $category );
	fixture_check( $tag );
	$post = wp_insert_post( [ 'post_title' => 'Tagged post ' . $suffix, 'post_type' => 'post', 'post_status' => 'publish' ], true );
	fixture_check( $post );
	fixture_check( wp_set_object_terms( $post, [ (int) $category['term_id'] ], 'category' ) );
	fixture_check( wp_set_object_terms( $post, [ (int) $tag['term_id'] ], 'post_tag' ) );

	$comment_secret = 'PRIVATE_COMMENT_' . $suffix;
	$comment_id = wp_new_comment( [
		'comment_post_ID' => $book,
		'comment_author' => 'Pilot visitor',
		'comment_author_email' => 'visitor@example.test',
		'comment_content' => $comment_secret,
		'comment_type' => 'comment',
		'comment_approved' => 0,
		'user_id' => 0,
	], true );
	fixture_check( $comment_id );
	fixture_check( wp_update_comment( [ 'comment_ID' => $comment_id, 'comment_content' => 'PRIVATE_COMMENT_EDITED_' . $suffix ], true ) );
	fixture_check( wp_set_comment_status( $comment_id, 'approve' ) );
	fixture_check( wp_delete_comment( $comment_id, true ) );

	fixture_check( wp_update_user( [
		'ID' => $editor_id,
		'user_email' => 'updated.' . $suffix . '@example.test',
		'display_name' => 'Pilot editor ' . $suffix,
	] ) );
	$profile_user = get_userdata( $editor_id );
	$password_secret = 'PRIVATE_PASSWORD_' . $suffix;
	fixture_check( wp_update_user( [ 'ID' => $editor_id, 'user_pass' => $password_secret ] ) );
	do_action( 'password_reset', get_userdata( $editor_id ), 'PRIVATE_RESET_PASSWORD_' . $suffix );
	do_action( 'wp_logout', $editor_id );

	$menu = wp_create_nav_menu( 'Pilot menu ' . $suffix );
	fixture_check( $menu );
	$url_secret = 'PRIVATE_MENU_TOKEN_' . $suffix;
	$menu_item = wp_update_nav_menu_item( $menu, 0, [
		'menu-item-title' => 'Pilot link ' . $suffix,
		'menu-item-url' => 'https://example.test/?token=' . rawurlencode( $url_secret ),
		'menu-item-status' => 'publish',
		'menu-item-type' => 'custom',
	] );
	fixture_check( $menu_item );
	fixture_check( wp_update_nav_menu_item( $menu, $menu_item, [
		'menu-item-title' => 'Updated pilot link ' . $suffix,
		'menu-item-url' => 'https://example.test/updated?token=' . rawurlencode( $url_secret ),
		'menu-item-status' => 'publish',
		'menu-item-type' => 'custom',
	] ) );
	fixture_check( wp_delete_nav_menu( $menu ) );

	$widget_secret = 'PRIVATE_WIDGET_TEXT_' . $suffix;
	$widget_option = 'widget_alfa_pilot_' . str_replace( '-', '_', $suffix );
	add_option( $widget_option, [ '2' => [ 'text' => 'old widget value' ], '_multiwidget' => 1 ], '', false );
	update_option( $widget_option, [ '2' => [ 'text' => $widget_secret ], '_multiwidget' => 1 ], false );
	delete_option( $widget_option );

	$events = \ActivityLogForAdmins\Store::query( [ 'q' => $suffix ], 200, 0, 0, true );
	fixture_check( $events );
	$encoded = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$privacy_ok = ! str_contains( $encoded, $comment_secret )
		&& ! str_contains( $encoded, 'PRIVATE_COMMENT_EDITED_' . $suffix )
		&& ! str_contains( $encoded, $password_secret )
		&& ! str_contains( $encoded, 'PRIVATE_RESET_PASSWORD_' . $suffix )
		&& ! str_contains( $encoded, 'updated.' . $suffix . '@example.test' )
		&& ! str_contains( $encoded, $url_secret )
		&& ! str_contains( $encoded, $widget_secret );
	$profile_event = \ActivityLogForAdmins\Store::query( [ 'action' => 'user_profile_changed', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $profile_event );
	$password_event = \ActivityLogForAdmins\Store::query( [ 'action' => 'password_changed', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $password_event );
	$sign_out_event = \ActivityLogForAdmins\Store::query( [ 'action' => 'signed_out', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $sign_out_event );
	$menu_events = \ActivityLogForAdmins\Store::query( [ 'action' => 'menu_item_updated', 'q' => $suffix ], 20, 0, 0, true );
	fixture_check( $menu_events );
	$menu_destination_captured = false;
	foreach ( $menu_events as $menu_event ) {
		$menu_details = json_decode( (string) $menu_event['details'], true ) ?: [];
		if ( isset( $menu_details['changes']['Link destination'] ) ) {
			$menu_destination_captured = true;
		}
	}
	return [
		'suffix' => $suffix,
		'book' => (int) $book,
		'genre' => $genre_id,
		'comment' => (int) $comment_id,
		'profileUser' => $editor_id,
		'profileCaptured' => ! empty( $profile_event ),
		'profileValuesCaptured' => str_contains( wp_json_encode( $profile_event ), 'Pilot editor ' . $suffix ),
		'profileEmailRedacted' => ! str_contains( wp_json_encode( $profile_event ), 'updated.' . $suffix . '@example.test' ),
		'menuDestinationCaptured' => $menu_destination_captured,
		'passwordCaptured' => ! empty( $password_event ),
		'signedOutCaptured' => ! empty( $sign_out_event ),
		'privacyOk' => $privacy_ok,
	];
}

function fixture_integration_events(): array {
	$id = 700000 + random_int( 1000, 8999 );
	$redirect_id = $id + 10000;
	$secret = 'PRIVATE_REDIRECT_TARGET_' . wp_generate_password( 10, false, false );
	$redirect = (object) [ 'id' => $redirect_id, 'source_url' => '/private-source', 'target_url' => 'https://example.test/?token=' . $secret ];
	do_action( 'woocommerce_new_order', $id, null );
	do_action( 'woocommerce_order_status_changed', $id, 'pending', 'processing', null );
	do_action( 'woocommerce_order_refunded', $id, $id + 1 );
	do_action( 'redirection_redirect_updated', $redirect_id, $redirect );
	do_action( 'redirection_redirect_deleted', $redirect );
	do_action( 'redirection_redirect_enabled', $redirect_id );
	do_action( 'redirection_redirect_disabled', $redirect_id );
	$orders = Store::query( [ 'item' => 'order:' . $id ], 20, 0, 0, true );
	$redirects = Store::query( [ 'item' => 'redirect:' . $redirect_id ], 20, 0, 0, true );
	fixture_check( $orders );
	fixture_check( $redirects );
	$encoded = wp_json_encode( [ $orders, $redirects ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return [
		'orderId' => $id,
		'redirectId' => $redirect_id,
		'orderActions' => array_column( $orders, 'action_key' ),
		'redirectActions' => array_column( $redirects, 'action_key' ),
		'privacyOk' => ! str_contains( $encoded, $secret ) && ! str_contains( $encoded, 'source_url' ) && ! str_contains( $encoded, 'target_url' ),
	];
}

function fixture_comment_events(): int {
	$post_id = wp_insert_post( [ 'post_title' => 'Comment recording check', 'post_type' => 'page', 'post_status' => 'publish', 'comment_status' => 'open' ], true );
	fixture_check( $post_id );
	$comment_id = wp_new_comment( [
		'comment_post_ID' => $post_id,
		'comment_author' => 'Pilot visitor',
		'comment_author_email' => 'visitor@example.test',
		'comment_content' => 'Comment recording category check',
		'comment_type' => 'comment',
		'comment_approved' => 1,
		'user_id' => 0,
	], true );
	fixture_check( $comment_id );
	fixture_check( wp_delete_comment( $comment_id, true ) );
	return (int) $comment_id;
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
            update_option( Store::SETTINGS, [], false );
            delete_option( Store::HEALTH );
            delete_option( 'alfa_alert_digest_after_id' );
            wp_clear_scheduled_hook( Store::CLEANUP );
            wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
            Store::schedule();
            wp_send_json( [ 'ok' => true ] );

        case 'integrations':
            wp_send_json( fixture_integration_events() );

        case 'multisite':
            if ( ! is_multisite() ) {
                wp_send_json( [ 'supported' => false ] );
            }
            $multisite_path = '/alfa-pilot-' . strtolower( wp_generate_password( 8, false, false ) ) . '/';
            $multisite_network = get_network();
            $multisite_site = wp_insert_site( [
                'domain' => (string) $multisite_network->domain,
                'path' => $multisite_path,
                'title' => 'Activity Log pilot ' . $multisite_path,
                'user_id' => get_current_user_id(),
            ] );
            fixture_check( $multisite_site );
            $main_retention = Store::settings()['retention'];
            $multisite_results = [];
            switch_to_blog( (int) $multisite_site );
            try {
                $site_table = Store::table();
                $multisite_results['tableCreatedOnNewSite'] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $site_table ) ) ) === $site_table;
                $multisite_results['schemaInitializedOnNewSite'] = (int) get_option( Store::SCHEMA ) === 2;
                $multisite_results['scheduledOnNewSite'] = (bool) wp_next_scheduled( Store::CLEANUP );
                update_option( Store::SETTINGS, [ 'retention' => 7 ], false );
                Store::add( [
                    'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                    'item_kind' => 'page', 'item_key' => 'multisite-pilot', 'item_name' => 'Multisite isolation probe',
                    'action_key' => 'content_edited', 'severity' => 'info', 'summary' => 'Multisite isolation probe',
                ], [] );
                $multisite_events = Store::query( [ 'q' => 'Multisite isolation probe' ], 10, 0, 0, true );
                $multisite_results['eventStoredOnNewSite'] = is_array( $multisite_events ) && 1 === count( $multisite_events );
                $multisite_results['siteRetention'] = Store::settings()['retention'];
                wp_clear_scheduled_hook( Store::CLEANUP );
                Store::upgrade();
                $multisite_results['cleanupRescheduledOnUpgrade'] = (bool) wp_next_scheduled( Store::CLEANUP );
            } finally {
                restore_current_blog();
            }
            $multisite_results['mainRetention'] = Store::settings()['retention'];
            $main_events = Store::query( [ 'q' => 'Multisite isolation probe' ], 10, 0, 0, true );
            $multisite_results['eventIsolatedFromMainSite'] = is_array( $main_events ) && ! $main_events;
            $multisite_results['retentionIsolatedFromMainSite'] = $main_retention === $multisite_results['mainRetention'];
            $multisite_results['siteId'] = (int) $multisite_site;
            wp_send_json( [ 'supported' => true ] + $multisite_results );

        case 'comment_events':
            wp_send_json( [ 'commentId' => fixture_comment_events() ] );

        case 'warning':
            Store::add( [
                'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                'item_kind' => 'setting', 'item_key' => 'warning-fixture', 'item_name' => 'Warning fixture',
                'action_key' => 'setting_changed', 'severity' => 'warning', 'summary' => 'Warning digest scheduling fixture',
            ], [ 'note' => 'PRIVATE_DIGEST_DETAIL' ] );
            wp_send_json( [ 'scheduled' => (bool) wp_next_scheduled( Store::ALERT_DIGEST ) ] );

        case 'alert_delivery':
            $captured_mail = null;
            $capture_mail = static function( mixed $return, array $attributes ) use ( &$captured_mail ): bool {
                $captured_mail = $attributes;
                return true;
            };
            add_filter( 'pre_wp_mail', $capture_mail, 10, 2 );
            try {
                Store::send_alert_digest();
            } finally {
                remove_filter( 'pre_wp_mail', $capture_mail, 10 );
            }
            $mail_body = is_array( $captured_mail ) ? (string) ( $captured_mail['message'] ?? '' ) : '';
            $mail_headers = is_array( $captured_mail ) ? (array) ( $captured_mail['headers'] ?? [] ) : [];
            wp_send_json( [
                'sent' => is_array( $captured_mail ) && str_contains( $mail_body, 'Warning digest scheduling fixture' ),
                'plainText' => in_array( 'Content-Type: text/plain; charset=UTF-8', $mail_headers, true ),
                'summaryOnly' => ! str_contains( $mail_body, 'PRIVATE_DIGEST_DETAIL' ),
                'safeSubject' => is_array( $captured_mail ) && ! preg_match( '/[\r\n]/', (string) ( $captured_mail['subject'] ?? '' ) ),
            ] );

        case 'events':
            $previous_editor = get_user_by( 'login', 'piloteditor' );
            if ( $previous_editor ) {
                fixture_check( fixture_delete_account( (int) $previous_editor->ID ) );
            }
            $previous_deleted = get_user_by( 'login', 'pilotdelete' );
            if ( $previous_deleted ) {
                fixture_check( fixture_delete_account( (int) $previous_deleted->ID ) );
            }
            $previous_removed = get_user_by( 'login', 'pilotremove' );
            if ( $previous_removed ) {
                fixture_check( fixture_delete_account( (int) $previous_removed->ID ) );
            }
            $editor_password = wp_generate_password( 32, true, true );
            $editor = wp_insert_user( [ 'user_login' => 'piloteditor', 'user_email' => 'editor@example.test', 'user_pass' => $editor_password, 'role' => 'subscriber' ] );
            fixture_check( $editor );
            ( new WP_User( $editor ) )->set_role( 'editor' );
            $deleted = wp_insert_user( [ 'user_login' => 'pilotdelete', 'user_email' => 'delete@example.test', 'user_pass' => wp_generate_password() ] );
            fixture_check( $deleted );
            fixture_check( fixture_delete_account( (int) $deleted ) );
            $removed = 0;
            $site_removal_captured = false;
            if ( is_multisite() ) {
                $removed = wp_insert_user( [ 'user_login' => 'pilotremove', 'user_email' => 'remove@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
                fixture_check( $removed );
                fixture_check( wp_delete_user( (int) $removed ) );
                $removal_events = Store::query( [ 'action' => 'user_removed_from_site', 'item' => 'user:' . (int) $removed ], 10, 0, 0, true );
                fixture_check( $removal_events );
                $site_removal_captured = ! empty( $removal_events );
            }
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
            $coverage = fixture_extended_events( (int) $editor );
            wp_set_password( $editor_password, (int) $editor );
            wp_send_json( [ 'restPage' => $rest, 'scheduled' => $scheduled, 'deletedUser' => (int) $deleted, 'removedUser' => (int) $removed, 'siteRemovalCaptured' => $site_removal_captured, 'editorPassword' => $editor_password, 'coverage' => $coverage ] );

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

        case 'age_seed':
            fixture_seed( 1001, 'Expired retention probe', 'Expired retention probe', gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) );
            wp_send_json( [ 'seeded' => 1001 ] );

        case 'expired_count':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count only disposable retention fixture rows in the custom test table.
            $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE item_name = %s AND occurred_at < %s', $table, 'Expired retention probe', $cutoff ) );
            wp_send_json( [ 'count' => (int) $count ] );

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
            $latest_after_reactivation = Store::latest_id();
            $scheduled_after_reactivation = wp_next_scheduled( Store::CLEANUP );
            $reactivated = $unscheduled && $latest_after_reactivation >= $before && (bool) $scheduled_after_reactivation;
            fixture_check( wp_clear_scheduled_hook( Store::CLEANUP ) );
            Store::upgrade();
            $upgrade_rescheduled = (bool) wp_next_scheduled( Store::CLEANUP );
            fixture_check( wp_clear_scheduled_hook( Store::CLEANUP ) );
            $reject_cleanup_schedule = static function ( mixed $pre, object $event ): mixed {
                return Store::CLEANUP === $event->hook
                    ? new WP_Error( 'alfa_fixture_schedule_failure', 'Intentional pilot schedule failure.' )
                    : $pre;
            };
            add_filter( 'pre_schedule_event', $reject_cleanup_schedule, 10, 2 );
            Store::schedule();
            remove_filter( 'pre_schedule_event', $reject_cleanup_schedule, 10 );
            $schedule_health = Store::health();
            wp_send_json( [
                'batch' => $batch,
                'deleted' => $deleted,
                'cleanupFailure' => (bool) $health['cleanup_error'],
                'writeFailure' => (bool) $health['write_error'],
                'primaryWrite' => is_int( $primary ) && $primary > 0,
                'reactivated' => $reactivated,
                'upgradeRescheduled' => $upgrade_rescheduled,
                'scheduleFailure' => (bool) $schedule_health['schedule_error'] && ! wp_next_scheduled( Store::CLEANUP ),
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
