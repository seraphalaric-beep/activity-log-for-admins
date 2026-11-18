<?php
// Copy into a disposable local WordPress document root for the pilot suite.
if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '127.0.0.1' !== ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) {
    http_response_code( 404 );
    exit;
}
require __DIR__ . '/wp-load.php';
if ( is_file( __DIR__ . '/privacy-fixture.php' ) ) {
    require_once __DIR__ . '/privacy-fixture.php';
}
foreach ( [ 'admin.php', 'nav-menu.php', 'comment.php', 'class-wp-upgrader.php' ] as $cvsj_include ) {
    $cvsj_path = ABSPATH . 'wp-admin/includes/' . $cvsj_include;
    if ( is_file( $cvsj_path ) ) {
        require_once $cvsj_path;
    }
}

use ChangeVigilSiteJournal\Store;
use ChangeVigilSiteJournal\Capture;
use ChangeVigilSiteJournal\FileMonitor;
use ChangeVigilSiteJournal\ScheduledReports;
use ChangeVigilSiteJournal\Alerts;
use ChangeVigilSiteJournal\DeveloperAPI;

$operation = sanitize_key( $_POST['operation'] ?? '' );
if ( 'nonce' === $operation && is_user_logged_in() ) {
	wp_send_json( [ 'nonce' => wp_create_nonce( 'wp_rest' ), 'restBase' => rest_url( 'change-vigil-site-journal/v1/' ) ] );
}
if ( 'session_probe' === $operation ) {
	$user_id = get_current_user_id();
	$state = [ 'user_id' => $user_id, 'idle_enabled' => false, 'elapsed' => null, 'session_found' => false, 'filter_registered' => false ];
	$stored = (array) get_option( ChangeVigilSiteJournal\Sessions::SETTINGS, [] );
	$state['idle_enabled'] = ! empty( $stored['idle_enabled'] );
	$state['filter_registered'] = false !== has_filter( 'determine_current_user', [ ChangeVigilSiteJournal\Sessions::class, 'enforce_idle_limit' ] );
	if ( $user_id && function_exists( 'wp_get_session_token' ) ) {
		$token = wp_get_session_token();
		$session = '' === $token ? null : WP_Session_Tokens::get_instance( $user_id )->get( $token );
		$reference = '' === $token ? '' : hash_hmac( 'sha256', hash( 'sha256', $token ), wp_salt( 'auth' ) );
		$activity = get_transient( 'cvsj_session_activity_' . $user_id );
		$state['session_found'] = is_array( $session );
		$state['elapsed'] = time() - max( (int) ( $session['login'] ?? 0 ), (int) ( $stored['idle_enabled_at'] ?? 0 ), (int) ( is_array( $activity ) ? ( $activity[ $reference ] ?? 0 ) : 0 ) );
	}
	wp_send_json( $state );
}
if ( ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( 'Pilot administrator required.', 403 );
}

const CVSJ_PLUGIN = 'change-vigil-site-journal/change-vigil-site-journal.php';
global $wpdb;
if ( 'activate' === $operation ) {
    $result = activate_plugin( CVSJ_PLUGIN );
    wp_send_json( [ 'active' => ! is_wp_error( $result ) && is_plugin_active( CVSJ_PLUGIN ) ] );
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

function fixture_zip( string $kind, string $version, string $theme_slug = 'cvsj-test-theme' ): string {
    $path = wp_tempnam( 'cvsj-fixture-' . $kind . '.zip' );
    $zip = new ZipArchive();
    $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
    if ( 'plugin' === $kind ) {
        $zip->addFromString( 'cvsj-test-plugin/pilot.php', "<?php\n/* Plugin Name: Pilot fixture plugin\nVersion: $version\n*/" );
    } else {
        $zip->addFromString( $theme_slug . '/style.css', "/* Theme Name: Pilot fixture theme\nVersion: $version\n*/" );
        $zip->addFromString( $theme_slug . '/index.php', "<?php\n" );
    }
    $zip->close();
    return $path;
}

function fixture_component_events(): array {
    $plugin = 'cvsj-test-plugin/pilot.php';
    $theme_slug = 'cvsj-test-theme-' . strtolower( wp_generate_password( 8, false, false ) );
    $old_theme = get_stylesheet();
    $original = [];
    $version = [];
    $theme_upgrades = [];
    $observe_theme_upgrade = static function ( object $upgrader, array $options ) use ( &$theme_upgrades ): void {
        if ( 'theme' !== ( $options['type'] ?? '' ) ) {
            return;
        }
        $result = $upgrader->result ?? null;
        $theme_upgrades[] = [
            'action' => sanitize_key( (string) ( $options['action'] ?? '' ) ),
            'resultType' => get_debug_type( $result ),
            'resultKeys' => is_array( $result ) ? array_keys( $result ) : [],
            'destinationName' => is_array( $result ) ? (string) ( $result['destination_name'] ?? '' ) : '',
        ];
    };
    add_action( 'upgrader_process_complete', $observe_theme_upgrade, 9, 2 );
    foreach ( [ 'plugin', 'theme' ] as $type ) {
        $original[ $type ] = fixture_zip( $type, '1.0.0', $theme_slug );
        $version[ $type ] = fixture_zip( $type, '1.0.1', $theme_slug );
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

        $theme_install_result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->install( $original['theme'] );
        if ( true !== $theme_install_result ) {
            throw new RuntimeException( 'The theme fixture install did not complete successfully.' );
        }
        switch_theme( $theme_slug );
        switch_theme( $old_theme );
        $themes = (object) [ 'response' => [ $theme_slug => [ 'package' => $version['theme'], 'new_version' => '1.0.1' ] ] ];
        add_filter( 'pre_site_transient_update_themes', static fn() => $themes );
        fixture_check( ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $theme_slug ) );
        fixture_check( delete_theme( $theme_slug ) );
    } finally {
        remove_action( 'upgrader_process_complete', $observe_theme_upgrade, 9 );
        foreach ( [ ...array_values( $original ), ...array_values( $version ) ] as $path ) {
            if ( is_file( $path ) ) {
                unlink( $path );
            }
        }
    }

    $theme_events = Store::query( [ 'item' => 'theme:' . $theme_slug ], 50, 0, 0, true );
    fixture_check( $theme_events );

    return [
        'themeSlug' => $theme_slug,
        'themeUpgraderHooks' => $theme_upgrades,
        'themeEventActions' => array_values( array_unique( array_column( $theme_events, 'action_key' ) ) ),
    ];
}

function fixture_extended_events( int $editor_id ): array {
	$suffix = 'cvsj-coverage-' . strtolower( wp_generate_password( 6, false, false ) );
	$title = 'Pilot book ' . $suffix;
	$book = wp_insert_post( [
		'post_title' => $title,
		'post_type' => 'cvsj_book',
		'post_status' => 'publish',
		'post_content' => 'Original book content',
		'comment_status' => 'open',
	], true );
	fixture_check( $book );
	fixture_check( wp_update_post( [ 'ID' => $book, 'post_title' => 'Updated ' . $title, 'post_content' => 'Updated book content' ], true ) );

	$genre = wp_insert_term( 'Pilot genre ' . $suffix, 'cvsj_genre' );
	fixture_check( $genre );
	$genre_id = (int) $genre['term_id'];
	fixture_check( wp_set_object_terms( $book, [ $genre_id ], 'cvsj_genre' ) );
	fixture_check( wp_set_object_terms( $book, [], 'cvsj_genre' ) );
	fixture_check( wp_update_term( $genre_id, 'cvsj_genre', [ 'name' => 'Updated pilot genre ' . $suffix ] ) );
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
	$widget_option = 'widget_cvsj_pilot_' . str_replace( '-', '_', $suffix );
	add_option( $widget_option, [ '2' => [ 'text' => 'old widget value' ], '_multiwidget' => 1 ], '', false );
	update_option( $widget_option, [ '2' => [ 'text' => $widget_secret ], '_multiwidget' => 1 ], false );
	delete_option( $widget_option );

	$events = \ChangeVigilSiteJournal\Store::query( [ 'q' => $suffix ], 200, 0, 0, true );
	fixture_check( $events );
	$encoded = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$privacy_ok = ! str_contains( $encoded, $comment_secret )
		&& ! str_contains( $encoded, 'PRIVATE_COMMENT_EDITED_' . $suffix )
		&& ! str_contains( $encoded, $password_secret )
		&& ! str_contains( $encoded, 'PRIVATE_RESET_PASSWORD_' . $suffix )
		&& ! str_contains( $encoded, 'updated.' . $suffix . '@example.test' )
		&& ! str_contains( $encoded, $url_secret )
		&& ! str_contains( $encoded, $widget_secret );
	$profile_event = \ChangeVigilSiteJournal\Store::query( [ 'action' => 'user_profile_changed', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $profile_event );
	$password_event = \ChangeVigilSiteJournal\Store::query( [ 'action' => 'password_changed', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $password_event );
	$sign_out_event = \ChangeVigilSiteJournal\Store::query( [ 'action' => 'signed_out', 'item' => 'user:' . $editor_id ], 10, 0, 0, true );
	fixture_check( $sign_out_event );
	$menu_events = \ChangeVigilSiteJournal\Store::query( [ 'action' => 'menu_item_updated', 'q' => $suffix ], 20, 0, 0, true );
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

function fixture_woocommerce_disabled(): array {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return [ 'supported' => false ];
	}
	$original_settings = Store::settings();
	update_option( Store::SETTINGS, array_merge( $original_settings, [ 'woocommerce' => false ] ), false );
	$product = new WC_Product_Simple();
	$product->set_name( 'Suppressed WooCommerce product ' . strtolower( wp_generate_password( 8, false, false ) ) );
	$product->set_status( 'draft' );
	$product_id = (int) $product->save();
	fixture_check( $product_id );
	\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
	$events = Store::query( [ 'item' => 'product:' . $product_id ], 20, 0, 0, true );
	fixture_check( is_array( $events ) );
	wp_delete_post( $product_id, true );
	update_option( Store::SETTINGS, $original_settings, false );
	return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
}

function fixture_woocommerce_events(): array {
	if ( ! class_exists( 'WooCommerce' ) || ! Store::settings()['woocommerce'] ) {
		throw new RuntimeException( 'Enable WooCommerce recording and install WooCommerce before running this fixture.' );
	}
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$product_name = 'Change Vigil product ' . $suffix;
	$updated_product_name = 'Updated ' . $product_name;
	$product_id = 0;
	$coupon_id = 0;
	$coupon_code = 'PRIVATE-COUPON-' . strtoupper( $suffix );
	$updated_coupon_code = 'PRIVATE-COUPON-UPDATED-' . strtoupper( $suffix );
	$product_sku_email = 'sku-' . $suffix . '@example.invalid';
	$customer_email = 'private-' . $suffix . '@example.invalid';
	$updated_customer_email = 'updated-' . $suffix . '@example.invalid';
	$old_settings = Store::settings();
	$old_tax = get_option( 'woocommerce_calc_taxes', false );
	$old_notify = get_option( 'woocommerce_notify_low_stock', false );
	$old_countries = get_option( 'woocommerce_specific_allowed_countries', false );
	try {
		$product = new WC_Product_Simple();
		$product->set_name( $product_name );
		$product->set_status( 'draft' );
		$product->set_sku( $product_sku_email );
		$product->set_regular_price( '15.00' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 3 );
		$product_id = (int) $product->save();
		fixture_check( $product_id );

		$product = wc_get_product( $product_id );
		fixture_check( $product );
		$product->set_name( $updated_product_name );
		$product->set_regular_price( '18.50' );
		$product->set_stock_quantity( 7 );
		$product->save();
		fixture_check( true );
		$product = wc_get_product( $product_id );
		$product->set_status( 'publish' );
		$product->save();
		fixture_check( true );

		$coupon = new WC_Coupon();
		$coupon->set_code( $coupon_code );
		$coupon->set_status( 'draft' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->set_usage_limit( 25 );
		$coupon->set_email_restrictions( [ $customer_email ] );
		$coupon_id = (int) $coupon->save();
		fixture_check( $coupon_id );

		$coupon = new WC_Coupon( $coupon_id );
		fixture_check( $coupon );
		$coupon->set_code( $updated_coupon_code );
		$coupon->set_amount( 15 );
		$coupon->set_email_restrictions( [ $updated_customer_email ] );
		$coupon->save();
		fixture_check( true );
		$coupon = new WC_Coupon( $coupon_id );
		$coupon->set_status( 'publish' );
		$coupon->save();
		fixture_check( true );

		wp_trash_post( $product_id );
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
		wp_untrash_post( $product_id );
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
		wp_delete_post( $product_id, true );
		wp_trash_post( $coupon_id );
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
		wp_untrash_post( $coupon_id );
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
		wp_delete_post( $coupon_id, true );
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();

		$tax_value = 'yes' === strtolower( (string) $old_tax ) ? 'no' : 'yes';
		update_option( 'woocommerce_calc_taxes', $tax_value, false );
		update_option( 'woocommerce_notify_low_stock', $customer_email, false );
		update_option( 'woocommerce_specific_allowed_countries', [ 'US', 'IE' ], false );

		$order_id = 800000 + random_int( 1000, 8999 );
		do_action( 'woocommerce_trash_order', $order_id );
		do_action( 'woocommerce_untrash_order', $order_id );
		do_action( 'woocommerce_delete_order', $order_id );

		$product_events = Store::query( [ 'item' => 'product:' . $product_id ], 100, 0, 0, true );
		$coupon_events = Store::query( [ 'item' => 'coupon:' . $coupon_id ], 100, 0, 0, true );
		$tax_events = Store::query( [ 'item' => 'setting:woocommerce_calc_taxes' ], 20, 0, 0, true );
		$email_events = Store::query( [ 'item' => 'setting:woocommerce_notify_low_stock' ], 20, 0, 0, true );
		$country_events = Store::query( [ 'item' => 'setting:woocommerce_specific_allowed_countries' ], 20, 0, 0, true );
		$order_events = Store::query( [ 'item' => 'order:' . $order_id ], 20, 0, 0, true );
		foreach ( [ $product_events, $coupon_events, $tax_events, $email_events, $country_events, $order_events ] as $events ) {
			fixture_check( is_array( $events ) );
		}
		$all_events = [ $product_events, $coupon_events, $tax_events, $email_events, $country_events, $order_events ];
		$encoded = wp_json_encode( $all_events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$product_actions = array_column( $product_events, 'action_key' );
		$coupon_actions = array_column( $coupon_events, 'action_key' );
		$order_actions = array_column( $order_events, 'action_key' );
		$product_details = wp_json_encode( array_map( static fn( array $event ): array => json_decode( (string) $event['details'], true ) ?: [], $product_events ) );
		$coupon_details = wp_json_encode( array_map( static fn( array $event ): array => json_decode( (string) $event['details'], true ) ?: [], $coupon_events ) );
		return [
			'supported' => true,
			'productId' => $product_id,
			'couponId' => $coupon_id,
			'orderId' => $order_id,
		'productName' => $updated_product_name,
		'couponName' => sprintf( 'Coupon #%d', $coupon_id ),
			'productActions' => $product_actions,
			'couponActions' => $coupon_actions,
			'orderActions' => $order_actions,
			'productPriceCaptured' => str_contains( (string) $product_details, 'Regular price' ) && str_contains( (string) $product_details, '18.50' ),
			'couponAmountCaptured' => str_contains( (string) $coupon_details, 'Discount amount' ) && str_contains( (string) $coupon_details, '15' ),
			'taxSettingCaptured' => (bool) $tax_events,
			'countryListCaptured' => str_contains( wp_json_encode( $country_events ), 'IE, US' ),
			'notificationAddressRedacted' => ! str_contains( wp_json_encode( $email_events ), $customer_email ),
			'privateValuesRedacted' => ! str_contains( (string) $encoded, $coupon_code )
				&& ! str_contains( (string) $encoded, $updated_coupon_code )
				&& ! str_contains( (string) $encoded, $product_sku_email )
				&& ! str_contains( (string) $encoded, $customer_email )
				&& ! str_contains( (string) $encoded, $updated_customer_email ),
		];
	} finally {
		if ( $product_id && get_post( $product_id ) instanceof WP_Post ) {
			wp_delete_post( $product_id, true );
		}
		if ( $coupon_id && get_post( $coupon_id ) instanceof WP_Post ) {
			wp_delete_post( $coupon_id, true );
		}
		\ChangeVigilSiteJournal\Capture::flush_woocommerce_changes();
		update_option( Store::SETTINGS, $old_settings, false );
		foreach ( [ 'woocommerce_calc_taxes' => $old_tax, 'woocommerce_notify_low_stock' => $old_notify, 'woocommerce_specific_allowed_countries' => $old_countries ] as $option => $value ) {
			if ( false === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
	}
}

function fixture_acf_events( bool $enabled ): array {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'update_field' ) ) {
		return [ 'supported' => false ];
	}
	$settings = Store::settings();
	if ( $enabled !== $settings['acf'] ) {
		throw new RuntimeException( 'Set the Advanced Custom Fields recording option before running this fixture.' );
	}
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$marker_key = 'field_cvsj_marker_' . $suffix;
	$email_key = 'field_cvsj_contact_' . $suffix;
	$marker_name = 'pilot_marker_' . $suffix;
	$email_name = 'pilot_contact_' . $suffix;
	$before_email = 'before-' . $suffix . '@example.invalid';
	$after_email = 'after-' . $suffix . '@example.invalid';
	acf_add_local_field_group( [
		'key' => 'group_cvsj_' . $suffix,
		'title' => 'Change Vigil ACF fixture',
		'fields' => [
			[ 'key' => $marker_key, 'label' => 'Editorial marker', 'name' => $marker_name, 'type' => 'text' ],
			[ 'key' => $email_key, 'label' => 'Contact email', 'name' => $email_name, 'type' => 'email' ],
		],
		'location' => [
			[ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ],
			[ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'product' ] ],
		],
	] );
	$post_id = wp_insert_post( [
		'post_title' => 'Change Vigil ACF fixture ' . $suffix,
		'post_type' => 'page',
		'post_status' => 'publish',
	], true );
	fixture_check( $post_id );
	update_post_meta( (int) $post_id, $marker_name, 'Before marker' );
	update_post_meta( (int) $post_id, $email_name, $before_email );
	if ( $enabled ) {
		update_field( $marker_key, 'After marker', (int) $post_id );
		update_field( $email_key, $after_email, (int) $post_id );
		\ChangeVigilSiteJournal\Capture::flush_acf_changes();
		update_field( $marker_key, 'After marker', (int) $post_id );
		\ChangeVigilSiteJournal\Capture::flush_acf_changes();
	}
	$events = Store::query( [ 'item' => 'page:' . (int) $post_id, 'action' => 'acf_fields_changed' ], 20, 0, 0, true );
	fixture_check( is_array( $events ) );
	$encoded = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$details = $events ? json_decode( (string) $events[0]['details'], true ) : [];
	$changes = (array) ( $details['changes'] ?? [] );
	$product_captured = null;
	$product_supported = $enabled && post_type_exists( 'product' );
	if ( $product_supported ) {
		$product_id = wp_insert_post( [
			'post_title' => 'Change Vigil ACF product ' . $suffix,
			'post_type' => 'product',
			'post_status' => 'publish',
		], true );
		fixture_check( $product_id );
		update_post_meta( (int) $product_id, $marker_name, 'Before product marker' );
		update_field( $marker_key, 'After product marker', (int) $product_id );
		\ChangeVigilSiteJournal\Capture::flush_acf_changes();
		$product_events = Store::query( [ 'item' => 'product:' . (int) $product_id, 'action' => 'acf_fields_changed' ], 10, 0, 0, true );
		fixture_check( is_array( $product_events ) );
		$product_details = $product_events ? json_decode( (string) $product_events[0]['details'], true ) : [];
		$product_captured = isset( $product_details['changes']['Editorial marker'] )
			&& ! str_contains( wp_json_encode( $product_events ), 'Before product marker' )
			&& ! str_contains( wp_json_encode( $product_events ), 'After product marker' );
		wp_delete_post( (int) $product_id, true );
	}
	$result = [
		'supported' => true,
		'hookRegistered' => false !== has_filter( 'acf/update_value', [ ChangeVigilSiteJournal\Capture::class, 'acf_value_updated' ] ),
		'postId' => (int) $post_id,
		'postName' => get_the_title( $post_id ),
		'eventCount' => count( $events ),
		'productSupported' => $product_supported,
		'productCaptured' => $product_captured,
		'changedFields' => array_keys( $changes ),
		'valuesRedacted' => ! str_contains( (string) $encoded, $before_email )
			&& ! str_contains( (string) $encoded, $after_email )
			&& ! str_contains( (string) $encoded, 'Before marker' )
			&& ! str_contains( (string) $encoded, 'After marker' )
			&& ! str_contains( (string) $encoded, $marker_key )
			&& ! str_contains( (string) $encoded, $email_key )
			&& ! str_contains( (string) $encoded, $marker_name )
			&& ! str_contains( (string) $encoded, $email_name ),
		'sensitiveLabelGeneric' => isset( $changes['Sensitive custom field'] ),
		'ordinaryLabelVisible' => isset( $changes['Editorial marker'] ),
	];
	wp_delete_post( (int) $post_id, true );
	return $result;
}

function fixture_bbpress_items( string $suffix ): array {
	$forum_id = bbp_insert_forum( [
		'post_title' => 'Change Vigil forum ' . $suffix,
		'post_content' => 'PRIVATE_BBPRESS_FORUM_BODY_' . $suffix,
		'post_status' => 'publish',
		'post_author' => get_current_user_id(),
	] );
	fixture_check( $forum_id );
	$topic_id = bbp_insert_topic( [
		'post_parent' => (int) $forum_id,
		'post_title' => 'Change Vigil topic ' . $suffix,
		'post_content' => 'PRIVATE_BBPRESS_TOPIC_BODY_' . $suffix,
		'post_status' => 'publish',
		'post_author' => get_current_user_id(),
	], [ 'forum_id' => (int) $forum_id ] );
	fixture_check( $topic_id );
	$reply_id = bbp_insert_reply( [
		'post_parent' => (int) $topic_id,
		'post_content' => 'PRIVATE_BBPRESS_REPLY_BODY_' . $suffix,
		'post_status' => 'publish',
		'post_author' => get_current_user_id(),
	], [ 'topic_id' => (int) $topic_id, 'forum_id' => (int) $forum_id ] );
	fixture_check( $reply_id );
	return [ (int) $forum_id, (int) $topic_id, (int) $reply_id ];
}

function fixture_bbpress_disabled(): array {
	if ( ! function_exists( 'bbp_insert_forum' ) || ! function_exists( 'bbp_insert_topic' ) || ! function_exists( 'bbp_insert_reply' ) ) {
		return [ 'supported' => false ];
	}
	$settings = Store::settings();
	update_option( Store::SETTINGS, array_merge( $settings, [ 'bbpress' => false ] ), false );
	$ids = [];
	try {
		$ids = fixture_bbpress_items( strtolower( wp_generate_password( 8, false, false ) ) );
		$events = [];
		foreach ( [ 'forum', 'topic', 'reply' ] as $index => $kind ) {
			$events = array_merge( $events, Store::query( [ 'item' => $kind . ':' . $ids[ $index ] ], 20, 0, 0, true ) );
		}
		return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
	} finally {
		foreach ( array_reverse( $ids ) as $id ) {
			wp_delete_post( $id, true );
		}
		update_option( Store::SETTINGS, $settings, false );
	}
}

function fixture_bbpress_events(): array {
	if ( ! function_exists( 'bbp_insert_forum' ) || ! function_exists( 'bbp_insert_topic' ) || ! function_exists( 'bbp_insert_reply' ) || ! Store::settings()['bbpress'] ) {
		throw new RuntimeException( 'Enable bbPress recording and install bbPress before running this fixture.' );
	}
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$guest_email = 'guest-' . $suffix . '@example.invalid';
	$guest_ip = '198.51.100.42';
	$ids = [];
	$anonymous_id = 0;
	$original_user = get_current_user_id();
	try {
		$ids = fixture_bbpress_items( $suffix );
		[ $forum_id, $topic_id, $reply_id ] = $ids;
		bbp_close_forum( $forum_id );
		bbp_open_forum( $forum_id );
		bbp_categorize_forum( $forum_id );
		bbp_normalize_forum( $forum_id );
		fixture_check( bbp_close_topic( $topic_id ) );
		fixture_check( bbp_open_topic( $topic_id ) );
		fixture_check( bbp_stick_topic( $topic_id ) );
		fixture_check( bbp_unstick_topic( $topic_id ) );
		fixture_check( bbp_spam_reply( $reply_id ) );
		fixture_check( bbp_unspam_reply( $reply_id ) );
		$reply = get_post( $reply_id );
		fixture_check( $reply instanceof WP_Post );
		fixture_check( wp_update_post( [
			'ID' => $reply_id,
			'post_content' => 'PRIVATE_BBPRESS_REPLY_EDIT_' . $suffix,
		], true ) );
		$reply_events = Store::query( [ 'item' => 'reply:' . $reply_id ], 50, 0, 0, true );
		fixture_check( is_array( $reply_events ) );
		$reply_edit_count = count( array_filter( $reply_events, static fn( array $event ): bool => 'reply_edited' === $event['action_key'] ) );
		fixture_check( wp_update_post( [ 'ID' => $reply_id, 'post_content' => 'PRIVATE_BBPRESS_REPLY_EDIT_' . $suffix ], true ) );
		$reply_events_after_same_save = Store::query( [ 'item' => 'reply:' . $reply_id ], 50, 0, 0, true );
		$unchanged_ignored = $reply_edit_count === count( array_filter( $reply_events_after_same_save, static fn( array $event ): bool => 'reply_edited' === $event['action_key'] ) );
		wp_set_current_user( 0 );
		$anonymous_id = (int) bbp_insert_reply( [
			'post_parent' => $topic_id,
			'post_content' => 'PRIVATE_BBPRESS_GUEST_REPLY_' . $suffix,
			'post_status' => 'publish',
			'post_author' => 0,
		], [ 'topic_id' => $topic_id, 'forum_id' => $forum_id, 'author_ip' => $guest_ip, 'author_email' => $guest_email ] );
		fixture_check( $anonymous_id );
		wp_set_current_user( $original_user );

		wp_trash_post( $reply_id );
		wp_untrash_post( $reply_id );
		fixture_check( wp_update_post( [ 'ID' => $reply_id, 'post_status' => 'draft' ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $reply_id, 'post_status' => 'publish' ], true ) );
		wp_delete_post( $reply_id, true );

		$topic = get_post( $topic_id );
		fixture_check( $topic instanceof WP_Post );
		fixture_check( wp_update_post( [ 'ID' => $topic_id, 'post_title' => 'Updated bbPress topic ' . $suffix, 'post_content' => 'PRIVATE_BBPRESS_TOPIC_EDIT_' . $suffix ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $topic_id, 'post_status' => 'draft' ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $topic_id, 'post_status' => 'publish' ], true ) );
		wp_trash_post( $topic_id );
		wp_untrash_post( $topic_id );
		wp_delete_post( $topic_id, true );

		$forum = get_post( $forum_id );
		fixture_check( $forum instanceof WP_Post );
		fixture_check( wp_update_post( [ 'ID' => $forum_id, 'post_title' => 'Updated bbPress forum ' . $suffix, 'post_content' => 'PRIVATE_BBPRESS_FORUM_EDIT_' . $suffix ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $forum_id, 'post_status' => 'draft' ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $forum_id, 'post_status' => 'publish' ], true ) );
		wp_trash_post( $forum_id );
		wp_untrash_post( $forum_id );
		wp_delete_post( $forum_id, true );

		$all_events = [];
		$event_sets = [];
		foreach ( [ 'forum', 'topic', 'reply' ] as $index => $kind ) {
			$event_sets[ $kind ] = Store::query( [ 'item' => $kind . ':' . $ids[ $index ] ], 100, 0, 0, true );
			fixture_check( is_array( $event_sets[ $kind ] ) );
			$all_events = array_merge( $all_events, $event_sets[ $kind ] );
		}
		$guest_events = Store::query( [ 'item' => 'reply:' . $anonymous_id ], 20, 0, 0, true );
		fixture_check( is_array( $guest_events ) );
		$all_events = array_merge( $all_events, $guest_events );
		$encoded = wp_json_encode( $all_events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$anonymous = array_filter( $guest_events, static fn( array $event ): bool => 'anonymous' === $event['actor_kind'] && 'Anonymous visitor' === $event['actor_name'] );
		return [
			'supported' => true,
			'forumId' => $forum_id,
			'topicId' => $topic_id,
			'replyId' => $reply_id,
			'anonymousId' => $anonymous_id,
			'forumActions' => array_column( $event_sets['forum'], 'action_key' ),
			'topicActions' => array_column( $event_sets['topic'], 'action_key' ),
			'replyActions' => array_column( $event_sets['reply'], 'action_key' ),
			'unchangedIgnored' => $unchanged_ignored,
			'anonymousActorCaptured' => (bool) $anonymous,
			'privateValuesRedacted' => ! str_contains( (string) $encoded, 'PRIVATE_BBPRESS_' )
				&& ! str_contains( (string) $encoded, $guest_email )
				&& ! str_contains( (string) $encoded, $guest_ip ),
		];
	} finally {
		wp_set_current_user( $original_user );
		foreach ( array_reverse( $ids ) as $id ) {
			if ( get_post( $id ) instanceof WP_Post ) {
				wp_delete_post( $id, true );
			}
		}
		if ( $anonymous_id && get_post( $anonymous_id ) instanceof WP_Post ) {
			wp_delete_post( $anonymous_id, true );
		}
	}
}

function fixture_wpforms_form( string $suffix ): int {
	$form_id = wp_insert_post( [
		'post_title' => 'Change Vigil WPForms ' . $suffix,
		'post_type' => 'wpforms',
		'post_status' => 'publish',
		'post_author' => get_current_user_id(),
		'post_content' => 'PRIVATE_WPFORMS_FORM_' . $suffix,
	], true );
	fixture_check( $form_id );
	return (int) $form_id;
}

function fixture_wpforms_disabled(): array {
	if ( ! defined( 'WPFORMS_VERSION' ) || ! post_type_exists( 'wpforms' ) ) {
		return [ 'supported' => false ];
	}
	$settings = Store::settings();
	update_option( Store::SETTINGS, array_merge( $settings, [ 'wpforms' => false ] ), false );
	$form_id = 0;
	try {
		$form_id = fixture_wpforms_form( strtolower( wp_generate_password( 8, false, false ) ) );
		$events = Store::query( [ 'item' => 'wpforms_form:' . $form_id ], 20, 0, 0, true );
		fixture_check( is_array( $events ) );
		return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
	} finally {
		if ( $form_id && get_post( $form_id ) instanceof WP_Post ) {
			wp_delete_post( $form_id, true );
		}
		update_option( Store::SETTINGS, $settings, false );
	}
}

function fixture_wpforms_events(): array {
	if ( ! defined( 'WPFORMS_VERSION' ) || ! post_type_exists( 'wpforms' ) || ! Store::settings()['wpforms'] ) {
		throw new RuntimeException( 'Enable WPForms recording and install WPForms Lite before running this fixture.' );
	}
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$form_id = 0;
	try {
		$form_id = fixture_wpforms_form( $suffix );
		fixture_check( wp_update_post( [
			'ID' => $form_id,
			'post_title' => 'Updated Change Vigil WPForms ' . $suffix,
			'post_content' => 'PRIVATE_WPFORMS_FORM_EDIT_' . $suffix,
		], true ) );
		$form_events = Store::query( [ 'item' => 'wpforms_form:' . $form_id ], 50, 0, 0, true );
		fixture_check( is_array( $form_events ) );
		$edit_count = count( array_filter( $form_events, static fn( array $event ): bool => 'wpforms_form_edited' === $event['action_key'] ) );
		fixture_check( wp_update_post( [ 'ID' => $form_id, 'post_content' => 'PRIVATE_WPFORMS_FORM_EDIT_' . $suffix ], true ) );
		$same_form_events = Store::query( [ 'item' => 'wpforms_form:' . $form_id ], 50, 0, 0, true );
		$unchanged_ignored = $edit_count === count( array_filter( $same_form_events, static fn( array $event ): bool => 'wpforms_form_edited' === $event['action_key'] ) );

		$before_zero_id = Store::query( [ 'item' => 'wpforms_form:' . $form_id, 'action' => 'wpforms_entry_submitted' ], 10, 0, 0, true );
		do_action( 'wpforms_process_complete', [ 'value' => 'PRIVATE_WPFORMS_ANSWER_ZERO' ], [ 'email' => 'private-zero@example.invalid' ], [ 'id' => $form_id ], 0 );
		$after_zero_id = Store::query( [ 'item' => 'wpforms_form:' . $form_id, 'action' => 'wpforms_entry_submitted' ], 10, 0, 0, true );
		$zero_id_ignored = is_array( $before_zero_id ) && is_array( $after_zero_id ) && count( $before_zero_id ) === count( $after_zero_id );
		do_action( 'wpforms_process_complete', [ 'value' => 'PRIVATE_WPFORMS_ANSWER_SAVED' ], [ 'email' => 'private@example.invalid', 'entry_id' => 'PRIVATE_WPFORMS_ENTRY_87654321' ], [ 'id' => $form_id ], 87654321 );
		$submission_events = Store::query( [ 'item' => 'wpforms_form:' . $form_id, 'action' => 'wpforms_entry_submitted' ], 10, 0, 0, true );
		fixture_check( is_array( $submission_events ) );
		$submission = $submission_events[0] ?? [];
		wp_trash_post( $form_id );
		wp_untrash_post( $form_id );
		fixture_check( wp_update_post( [ 'ID' => $form_id, 'post_status' => 'draft' ], true ) );
		fixture_check( wp_update_post( [ 'ID' => $form_id, 'post_status' => 'publish' ], true ) );
		wp_delete_post( $form_id, true );

		$events = Store::query( [ 'item' => 'wpforms_form:' . $form_id ], 100, 0, 0, true );
		fixture_check( is_array( $events ) );
		$encoded = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return [
			'supported' => true,
			'version' => WPFORMS_VERSION,
			'formId' => $form_id,
			'formName' => 'Updated Change Vigil WPForms ' . $suffix,
			'actions' => array_column( $events, 'action_key' ),
			'unchangedIgnored' => $unchanged_ignored,
			'zeroEntryIdIgnored' => $zero_id_ignored,
			'submissionCapturedByForm' => 'wpforms_entry_submitted' === ( $submission['action_key'] ?? '' ),
			'privacyRedacted' => ! str_contains( (string) $encoded, 'PRIVATE_WPFORMS_' )
				&& ! str_contains( (string) $encoded, 'private@example.invalid' )
				&& ! str_contains( (string) $encoded, 'private-zero@example.invalid' )
				&& ! str_contains( (string) $encoded, '87654321' ),
		];
	} finally {
		if ( $form_id && get_post( $form_id ) instanceof WP_Post ) {
			wp_delete_post( $form_id, true );
		}
	}
}

function fixture_seo_plugin_loaded( string $provider ): bool {
	return 'yoast' === $provider
		? class_exists( 'WPSEO_Meta' ) || defined( 'WPSEO_VERSION' )
		: ( 'rank_math' === $provider && class_exists( 'RankMath' ) );
}

function fixture_seo_events( string $provider ): array {
	if ( ! in_array( $provider, [ 'yoast', 'rank_math' ], true ) || ! fixture_seo_plugin_loaded( $provider ) || ! Store::settings()[ $provider ] ) {
		throw new RuntimeException( 'Enable the SEO integration and install its matching plugin before running this fixture.' );
	}
	$meta = 'yoast' === $provider
		? [ '_yoast_wpseo_title' => 'SEO title', '_yoast_wpseo_metadesc' => 'Meta description' ]
		: [ 'rank_math_title' => 'SEO title', 'rank_math_description' => 'Meta description' ];
	$option = 'yoast' === $provider ? 'wpseo_titles' : 'rank-math-options-titles';
	$option_field = 'yoast' === $provider ? 'title-home-wpseo' : 'homepage_title';
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$post_id = 0;
	try {
		$post_id = wp_insert_post( [
			'post_title' => ucfirst( str_replace( '_', ' ', $provider ) ) . ' SEO Fixture ' . $suffix,
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		], true );
		fixture_check( $post_id );
		$keys = array_keys( $meta );
		$private_title = 'PRIVATE_SEO_TITLE_' . $suffix . ' private@example.invalid https://private.invalid/path?token=' . $suffix;
		$private_description = 'PRIVATE_SEO_DESCRIPTION_' . $suffix . ' private@example.invalid https://private.invalid/description?secret=' . $suffix;
		fixture_check( add_post_meta( $post_id, $keys[0], $private_title, true ) );
		fixture_check( update_post_meta( $post_id, $keys[0], $private_title . ' edited' ) );
		fixture_check( add_post_meta( $post_id, $keys[1], $private_description, true ) );
		Capture::flush_seo_changes();
		$meta_events = Store::query( [ 'item' => 'page:' . (int) $post_id, 'action' => $provider . '_seo_meta_changed' ], 10, 0, 0, true );
		fixture_check( is_array( $meta_events ) );
		$meta_count = count( $meta_events );
		update_post_meta( $post_id, $keys[1], $private_description );
		Capture::flush_seo_changes();
		$unchanged_events = Store::query( [ 'item' => 'page:' . (int) $post_id, 'action' => $provider . '_seo_meta_changed' ], 10, 0, 0, true );
		fixture_check( is_array( $unchanged_events ) );

		$missing = new stdClass();
		$old_option = get_option( $option, $missing );
		$had_option = $old_option !== $missing;
		$new_option = is_array( $old_option ) ? $old_option : [];
		$new_option[ $option_field ] = 'PRIVATE_SEO_OPTION_' . $suffix;
		update_option( $option, $new_option, false );
		if ( $had_option ) {
			update_option( $option, $old_option, false );
		} else {
			delete_option( $option );
		}
		Capture::flush_seo_changes();
		$settings_events = Store::query( [ 'item' => 'setting:' . $provider . '-seo', 'action' => $provider . '_seo_settings_changed' ], 10, 0, 0, true );
		fixture_check( is_array( $settings_events ) );
		$all_events = array_merge( $unchanged_events, $settings_events );
		$meta_event = $all_events[0] ?? [];
		foreach ( $unchanged_events as $event ) {
			if ( $provider . '_seo_meta_changed' === ( $event['action_key'] ?? '' ) ) {
				$meta_event = $event;
				break;
			}
		}
		$meta_details = is_array( $meta_event['details'] ?? null )
			? $meta_event['details']
			: ( json_decode( (string) ( $meta_event['details'] ?? '' ), true ) ?: [] );
		$encoded = wp_json_encode( [ $unchanged_events, $settings_events ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return [
			'supported' => true,
			'provider' => $provider,
			'pluginVersion' => 'yoast' === $provider ? ( defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : 'loaded' ) : ( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'loaded' ),
			'postId' => (int) $post_id,
			'postName' => get_the_title( $post_id ),
			'metaEventCount' => $meta_count,
			'metaEventsAfterUnchangedSave' => count( array_filter( $unchanged_events, static fn( array $event ): bool => $provider . '_seo_meta_changed' === ( $event['action_key'] ?? '' ) ) ),
			'settingsEventCount' => count( $settings_events ),
			'metaLabels' => array_keys( $meta_details['changes'] ?? [] ),
			'privacyRedacted' => ! str_contains( (string) $encoded, 'PRIVATE_SEO_' )
				&& ! str_contains( (string) $encoded, 'private@example.invalid' )
				&& ! str_contains( (string) $encoded, 'https://private.invalid' ),
		];
	} finally {
		if ( $post_id && get_post( $post_id ) instanceof WP_Post ) {
			wp_delete_post( $post_id, true );
		}
	}
}

function fixture_seo_hook_state(): array {
	return [
		'yoastLoaded' => fixture_seo_plugin_loaded( 'yoast' ),
		'rankMathLoaded' => fixture_seo_plugin_loaded( 'rank_math' ),
		'optionHookRegistered' => false !== has_action( 'updated_option', [ ChangeVigilSiteJournal\Capture::class, 'seo_option_updated' ] ),
	];
}

function fixture_seo_disabled( string $provider ): array {
	if ( ! in_array( $provider, [ 'yoast', 'rank_math' ], true ) || ! fixture_seo_plugin_loaded( $provider ) ) {
		return [ 'supported' => false ];
	}
	$settings = Store::settings();
	update_option( Store::SETTINGS, array_merge( $settings, [ $provider => false ] ), false );
	$post_id = 0;
	try {
		$post_id = wp_insert_post( [ 'post_title' => 'SEO disabled fixture', 'post_type' => 'page', 'post_status' => 'publish' ], true );
		fixture_check( $post_id );
		$key = 'yoast' === $provider ? '_yoast_wpseo_title' : 'rank_math_title';
		fixture_check( add_post_meta( $post_id, $key, 'PRIVATE_DISABLED_SEO_VALUE', true ) );
		Capture::flush_seo_changes();
		$events = Store::query( [ 'item' => 'page:' . (int) $post_id, 'action' => $provider . '_seo_meta_changed' ], 10, 0, 0, true );
		fixture_check( is_array( $events ) );
		return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
	} finally {
		if ( $post_id && get_post( $post_id ) instanceof WP_Post ) {
			wp_delete_post( $post_id, true );
		}
		update_option( Store::SETTINGS, $settings, false );
	}
}

function fixture_pmpro_loaded(): bool {
	return function_exists( 'pmpro_getMembershipLevelsForUser' )
		&& class_exists( 'PMPro_Membership_Level' );
}

function fixture_pmpro_hook_state(): array {
	return [
		'loaded' => fixture_pmpro_loaded(),
		'membershipHook' => false !== has_action( 'pmpro_after_all_membership_level_changes', [ ChangeVigilSiteJournal\Capture::class, 'pmpro_membership_levels_changed' ] ),
		'levelCreateHook' => false !== has_action( 'pmpro_added_membership_level', [ ChangeVigilSiteJournal\Capture::class, 'pmpro_level_added' ] ),
		'levelBeforeUpdateHook' => false !== has_action( 'pmpro_update_membership_level', [ ChangeVigilSiteJournal\Capture::class, 'pmpro_level_before_update' ] ),
		'levelUpdateHook' => false !== has_action( 'pmpro_updated_membership_level', [ ChangeVigilSiteJournal\Capture::class, 'pmpro_level_updated' ] ),
		'levelDeleteHook' => false !== has_action( 'pmpro_delete_membership_level', [ ChangeVigilSiteJournal\Capture::class, 'pmpro_level_deleted' ] ),
	];
}

function fixture_pmpro_disabled(): array {
	if ( ! fixture_pmpro_loaded() ) {
		return [ 'supported' => false ];
	}
	if ( Store::settings()['pmpro'] ) {
		throw new RuntimeException( 'Turn off Paid Memberships Pro recording before running its disabled check.' );
	}
	Capture::pmpro_membership_levels_changed( [ 987654321 => [ (object) [ 'id' => 1 ] ] ] );
	$events = Store::query( [ 'category' => 'pmpro' ], 20, 0, 0, true );
	fixture_check( is_array( $events ) );
	return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
}

function fixture_pmpro_make_level( string $suffix ): int {
	$level = new PMPro_Membership_Level();
	$level->name = 'PRIVATE_PMPRO_LEVEL_' . $suffix;
	$level->description = 'PRIVATE_PMPRO_DESCRIPTION_' . $suffix;
	$level->confirmation = 'PRIVATE_PMPRO_CONFIRMATION_' . $suffix;
	$level->initial_payment = 0;
	$level->billing_amount = 0;
	$level->cycle_number = 0;
	$level->cycle_period = '';
	$level->billing_limit = 0;
	$level->trial_amount = 0;
	$level->trial_limit = 0;
	$level->expiration_number = 0;
	$level->expiration_period = '';
	$level->allow_signups = 1;
	$level->categories = [];
	$level->save();
	$id = absint( $level->id );
	fixture_check( $id > 0 );
	return $id;
}

function fixture_pmpro_events(): array {
	global $wpdb;
	if ( ! fixture_pmpro_loaded() || ! Store::settings()['pmpro'] ) {
		throw new RuntimeException( 'Enable the Paid Memberships Pro integration and activate its plugin before running this fixture.' );
	}
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$username = 'pmprofixture' . $suffix;
	$user_id = 0;
	$level_ids = [];
	try {
		$user_id = wp_insert_user( [
			'user_login' => $username,
			'user_pass' => wp_generate_password( 24, true, true ),
			'user_email' => $username . '@example.invalid',
			'role' => 'subscriber',
		] );
		fixture_check( $user_id );
		$level_ids[] = fixture_pmpro_make_level( $suffix . '_FIRST' );
		$level_ids[] = fixture_pmpro_make_level( $suffix . '_SECOND' );

		$created_levels = [];
		foreach ( $level_ids as $level_id ) {
			$created_levels = array_merge( $created_levels, Store::query( [ 'item' => 'pmpro_level:' . $level_id, 'action' => 'membership_level_created' ], 10, 0, 0, true ) );
		}
		fixture_check( count( $created_levels ) === count( $level_ids ) );

		$update_hook_trace = [];
		$trace_before = static function ( mixed $level ) use ( &$update_hook_trace ): void {
			$update_hook_trace[] = [ 'before', is_object( $level ) ? absint( $level->id ?? 0 ) : 0 ];
		};
		$trace_after = static function ( mixed $level ) use ( &$update_hook_trace ): void {
			$update_hook_trace[] = [ 'after', is_object( $level ) ? absint( $level->id ?? 0 ) : 0 ];
		};
		add_action( 'pmpro_update_membership_level', $trace_before, 1, 1 );
		add_action( 'pmpro_updated_membership_level', $trace_after, 20, 1 );
		$level = new PMPro_Membership_Level( $level_ids[0] );
		$level->description = 'PRIVATE_PMPRO_UPDATED_DESCRIPTION_' . $suffix;
		$level->save();
		$level->save();
		remove_action( 'pmpro_update_membership_level', $trace_before, 1 );
		remove_action( 'pmpro_updated_membership_level', $trace_after, 20 );
		$updated_levels = Store::query( [ 'item' => 'pmpro_level:' . $level_ids[0], 'action' => 'membership_level_updated' ], 10, 0, 0, true );
		fixture_check( is_array( $updated_levels ) );

		fixture_check( pmpro_changeMembershipLevel( $level_ids[0], (int) $user_id ) );
		pmpro_do_action_after_all_membership_level_changes();
		fixture_check( pmpro_changeMembershipLevel( $level_ids[1], (int) $user_id ) );
		pmpro_do_action_after_all_membership_level_changes();
		fixture_check( pmpro_cancelMembershipLevel( $level_ids[1], (int) $user_id ) );
		pmpro_do_action_after_all_membership_level_changes();
		$membership_events = Store::query( [ 'item' => 'user:' . (int) $user_id, 'action' => 'membership_changed' ], 20, 0, 0, true );
		fixture_check( is_array( $membership_events ) );

		do_action( 'pmpro_delete_membership_level', $level_ids[1] );
		$deleted_levels = Store::query( [ 'item' => 'pmpro_level:' . $level_ids[1], 'action' => 'membership_level_deleted' ], 10, 0, 0, true );
		fixture_check( is_array( $deleted_levels ) );

		$encoded = wp_json_encode( [ $created_levels, $updated_levels, $membership_events, $deleted_levels ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$first_membership = $membership_events[0] ?? [];
		$membership_details = is_array( $first_membership['details'] ?? null )
			? $first_membership['details']
			: ( json_decode( (string) ( $first_membership['details'] ?? '' ), true ) ?: [] );
		return [
			'supported' => true,
			'pluginVersion' => defined( 'PMPRO_VERSION' ) ? PMPRO_VERSION : 'loaded',
			'userId' => (int) $user_id,
			'levelIds' => $level_ids,
			'createdLevelCount' => count( $created_levels ),
			'updatedLevelCount' => count( $updated_levels ),
			'updateHookTrace' => $update_hook_trace,
			'membershipEventCount' => count( $membership_events ),
			'deletedLevelCount' => count( $deleted_levels ),
			'membershipChanges' => $membership_details['changes']['Membership level IDs'] ?? [],
			'privacyRedacted' => ! str_contains( (string) $encoded, 'PRIVATE_PMPRO_' )
				&& ! str_contains( (string) $encoded, $username )
				&& ! str_contains( (string) $encoded, '@example.invalid' ),
		];
	} finally {
		if ( $user_id ) {
			foreach ( [ $wpdb->pmpro_memberships_users, $wpdb->pmpro_membership_orders ] as $table_name ) {
				if ( isset( $table_name ) && is_string( $table_name ) ) {
					$wpdb->delete( $table_name, [ 'user_id' => (int) $user_id ], [ '%d' ] );
				}
			}
			wp_delete_user( (int) $user_id );
		}
		foreach ( $level_ids as $level_id ) {
			if ( isset( $wpdb->pmpro_memberships_categories ) ) {
				$wpdb->delete( $wpdb->pmpro_memberships_categories, [ 'membership_id' => (int) $level_id ], [ '%d' ] );
			}
			if ( isset( $wpdb->pmpro_membership_levels ) ) {
				$wpdb->delete( $wpdb->pmpro_membership_levels, [ 'id' => (int) $level_id ], [ '%d' ] );
			}
		}
	}
}

function fixture_termly_loaded(): bool {
	return defined( 'TERMLY_VERSION' );
}

function fixture_termly_hook_state(): array {
	return [
		'loaded' => fixture_termly_loaded(),
		'bannerHook' => false !== has_action( 'update_option_termly_display_banner', [ ChangeVigilSiteJournal\Capture::class, 'termly_banner_updated' ] ),
		'autoblockerHook' => false !== has_action( 'update_option_termly_display_auto_blocker', [ ChangeVigilSiteJournal\Capture::class, 'termly_autoblocker_updated' ] ),
		'scanHook' => false !== has_action( 'update_option_termly_site_scan', [ ChangeVigilSiteJournal\Capture::class, 'termly_site_scan_updated' ] ),
		'keyDeleteHook' => false !== has_action( 'delete_option', [ ChangeVigilSiteJournal\Capture::class, 'termly_api_key_before_delete' ] ),
	];
}

function fixture_termly_events(): array {
	global $wpdb;
	if ( ! fixture_termly_loaded() ) {
		return [ 'supported' => false ];
	}
	if ( ! Store::settings()['termly'] ) {
		Capture::termly_banner_updated( 'no', 'yes', 'termly_display_banner' );
		$events = Store::query( [ 'category' => 'termly' ], 20, 0, 0, true );
		fixture_check( is_array( $events ) );
		return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
	}

	$option_names = [ 'termly_api_key', 'termly_display_banner', 'termly_display_auto_blocker', 'termly_site_scan' ];
	$original = [];
	foreach ( $option_names as $name ) {
		$original[ $name ] = [ 'exists' => false !== get_option( $name, false ), 'value' => get_option( $name, false ) ];
	}
	$actions = [
		'added_option' => [ ChangeVigilSiteJournal\Capture::class, 'termly_api_key_added' ],
		'update_option_termly_api_key' => [ ChangeVigilSiteJournal\Capture::class, 'termly_api_key_updated' ],
		'delete_option' => [ ChangeVigilSiteJournal\Capture::class, 'termly_api_key_before_delete' ],
		'deleted_option' => [ ChangeVigilSiteJournal\Capture::class, 'termly_api_key_deleted' ],
		'update_option_termly_display_banner' => [ ChangeVigilSiteJournal\Capture::class, 'termly_banner_updated' ],
		'update_option_termly_display_auto_blocker' => [ ChangeVigilSiteJournal\Capture::class, 'termly_autoblocker_updated' ],
		'update_option_termly_site_scan' => [ ChangeVigilSiteJournal\Capture::class, 'termly_site_scan_updated' ],
	];
	$last_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Store::table() ) );

	try {
		foreach ( $actions as $hook => $callback ) {
			remove_action( $hook, $callback, 10 );
		}
		foreach ( $option_names as $name ) {
			delete_option( $name );
		}
		add_option( 'termly_display_banner', 'no' );
		add_option( 'termly_display_auto_blocker', 'off' );
		add_option( 'termly_site_scan', [ 'enabled' => 0, 'frequency' => 'trimonthly', 'robots_txt' => 0 ] );
		foreach ( $actions as $hook => $callback ) {
			add_action( $hook, $callback, 10, 3 );
		}
		$unchanged_scan_result = update_option( 'termly_site_scan', [ 'enabled' => 0, 'frequency' => 'trimonthly', 'robots_txt' => 0 ] );
		update_option( 'termly_display_banner', 'yes' );
		update_option( 'termly_display_auto_blocker', 'on' );
		update_option( 'termly_site_scan', [ 'enabled' => 1, 'frequency' => 'weekly', 'robots_txt' => 1 ] );
		add_option( 'termly_api_key', 'TERMLY_SECRET_FIXTURE_DO_NOT_STORE' );
		update_option( 'termly_api_key', 'TERMLY_SECRET_ROTATED_FIXTURE_DO_NOT_STORE' );
		delete_option( 'termly_api_key' );
		$events = Store::query( [ 'category' => 'termly' ], 50, 0, 0, true );
		fixture_check( is_array( $events ) );
		$new_events = array_values( array_filter( $events, static fn( array $event ): bool => (int) ( $event['id'] ?? 0 ) > $last_id ) );
		$raw = wp_json_encode( $new_events );
		$actions_found = array_values( array_unique( array_column( $new_events, 'action_key' ) ) );
		return [
			'supported' => true,
			'pluginVersion' => TERMLY_VERSION,
			'eventCount' => count( $new_events ),
			'actions' => $actions_found,
			'unchangedScanIgnored' => false === $unchanged_scan_result,
			'privacyRedacted' => is_string( $raw ) && ! str_contains( $raw, 'TERMLY_SECRET_FIXTURE' ) && ! str_contains( $raw, 'ROTATED_FIXTURE' ),
			'events' => $events,
		];
	} finally {
		foreach ( $actions as $hook => $callback ) {
			remove_action( $hook, $callback, 10 );
		}
		foreach ( $original as $name => $snapshot ) {
			delete_option( $name );
			if ( $snapshot['exists'] ) {
				add_option( $name, $snapshot['value'] );
			}
		}
	}
}

function fixture_mainwp_loaded(): bool {
	return defined( 'MAINWP_PLUGIN_FILE' );
}

function fixture_mainwp_hook_state(): array {
	return [
		'loaded' => fixture_mainwp_loaded(),
		'siteAddHook' => false !== has_action( 'mainwp_added_new_site', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_site_added' ] ),
		'siteDeleteHook' => false !== has_action( 'mainwp_site_deleted', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_site_deleted' ] ),
		'siteSyncHook' => false !== has_action( 'mainwp_site_sync', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_site_sync' ] ),
		'syncRequestFilter' => false !== has_filter( 'mainwp_sync_others_data', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_sync_request' ] ),
		'clientHook' => false !== has_action( 'mainwp_client_updated', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_client_updated' ] ),
		'clientDeleteHook' => false !== has_action( 'mainwp_client_deleted', [ ChangeVigilSiteJournal\Capture::class, 'mainwp_client_deleted' ] ),
	];
}

function fixture_mainwp_transfer(): array {
	$site_id = 907;
	$cursor_option = 'cvsj_mainwp_cursor_' . $site_id;
	$original_cursor = get_option( $cursor_option, null );
	delete_option( $cursor_option );
	$event = [
		'id' => 450,
		'occurred_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		'actor_kind' => 'user',
		'actor_name' => 'remote-editor',
		'actor_role' => 'editor',
		'source' => 'rest',
		'item_kind' => 'page',
		'item_key' => '55',
		'item_name' => 'Privacy policy page',
		'action_key' => 'content_edited',
		'severity' => 'notice',
		'summary' => 'Edited Privacy policy page',
		'details' => [ 'changes' => [ 'Title' => [ 'Previous title', 'New title' ] ], 'untrusted' => '<img src=x onerror=alert(1)>' ],
	];
	$request = ChangeVigilSiteJournal\Capture::mainwp_sync_request( [], (object) [ 'id' => $site_id ] );
	$before = ChangeVigilSiteJournal\Store::query( [ 'site_id' => (string) $site_id ], 10, 0, 0, true );
	fixture_check( is_array( $before ) );
	do_action( 'mainwp_site_sync', (object) [ 'id' => $site_id ], [ 'activity_log_for_admins' => [ 'schema_version' => 1, 'events' => [ $event ] ] ], true, '', [] );
	do_action( 'mainwp_site_sync', (object) [ 'id' => $site_id ], [ 'activity_log_for_admins' => [ 'schema_version' => 1, 'events' => [ $event ] ] ], true, '', [] );
	$rows = ChangeVigilSiteJournal\Store::query( [ 'site_id' => (string) $site_id ], 10, 0, 0, true );
	fixture_check( is_array( $rows ) );
	$request_after = ChangeVigilSiteJournal\Capture::mainwp_sync_request( [], (object) [ 'id' => $site_id ] );
	$export = ChangeVigilSiteJournal\Capture::mainwp_sync_export( [], [ 'activity_log_for_admins' => [ 'schema_version' => 1, 'after_id' => 0, 'limit' => 50 ] ] );
	$export_ids = array_map( 'intval', array_column( $export['activity_log_for_admins']['events'] ?? [], 'id' ) );
	$raw = wp_json_encode( $rows );
	$result = [
		'siteId' => $site_id,
		'eventCount' => count( $rows ),
		'event' => $rows[0] ?? null,
		'cursorBefore' => $request['activity_log_for_admins']['after_id'] ?? null,
		'cursorAfter' => $request_after['activity_log_for_admins']['after_id'] ?? null,
		'exportCount' => count( $export['activity_log_for_admins']['events'] ?? [] ),
		'exportHasImported' => isset( $rows[0]['id'] ) && in_array( (int) $rows[0]['id'], $export_ids, true ),
		'privacySafe' => is_string( $raw ) && ! str_contains( $raw, '<img' ) && ! str_contains( $raw, 'onerror=' ),
	];
	if ( null === $original_cursor ) {
		delete_option( $cursor_option );
	} else {
		update_option( $cursor_option, $original_cursor, false );
	}
	return $result;
}

function fixture_mainwp_events(): array {
	global $wpdb;
	if ( ! fixture_mainwp_loaded() ) {
		return [ 'supported' => false ];
	}
	if ( ! Store::settings()['mainwp'] ) {
		Capture::mainwp_site_added( 987654, (object) [ 'url' => 'https://private-mainwp.example.invalid' ] );
		$events = Store::query( [ 'category' => 'mainwp' ], 20, 0, 0, true );
		fixture_check( is_array( $events ) );
		return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
	}

	$last_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', Store::table() ) );
	$site = (object) [ 'id' => 701, 'url' => 'https://PRIVATE_MAINWP_SITE.example.invalid', 'name' => 'PRIVATE_MAINWP_SITE_NAME' ];
	$client = (object) [ 'client_id' => 801, 'name' => 'PRIVATE_MAINWP_CLIENT_NAME', 'email' => 'private-mainwp@example.invalid' ];

	do_action( 'mainwp_added_new_site', 701, $site );
	do_action( 'mainwp_updated_site', 701, [ 'url' => $site->url, 'name' => $site->name, 'admin_password' => 'MAINWP_SECRET_FIXTURE' ] );
	do_action( 'mainwp_site_suspended', $site, 1 );
	do_action( 'mainwp_site_suspended', $site, 0 );
	do_action( 'mainwp_site_sync', $site, [ 'custom_payload' => 'PRIVATE_MAINWP_SYNC_PAYLOAD' ], true, 'PRIVATE_MAINWP_ERROR', [ 'token' => 'MAINWP_SECRET_FIXTURE' ] );
	do_action( 'mainwp_site_sync', $site, [ 'custom_payload' => 'PRIVATE_MAINWP_SYNC_PAYLOAD' ], false, 'PRIVATE_MAINWP_ERROR', [ 'token' => 'MAINWP_SECRET_FIXTURE' ] );
	do_action( 'mainwp_site_deleted', $site );
	do_action( 'mainwp_client_updated', $client, true );
	do_action( 'mainwp_client_updated', $client, false );
	do_action( 'mainwp_client_suspend', $client, true );
	do_action( 'mainwp_client_suspend', $client, false );
	do_action( 'mainwp_client_deleted', $client, [ 'contact' => 'private-mainwp@example.invalid' ] );

	$events = Store::query( [ 'category' => 'mainwp' ], 50, 0, 0, true );
	fixture_check( is_array( $events ) );
	$new_events = array_values( array_filter( $events, static fn( array $event ): bool => (int) ( $event['id'] ?? 0 ) > $last_id ) );
	$raw = wp_json_encode( $new_events );
	return [
		'supported' => true,
		'pluginVersion' => get_file_data( MAINWP_PLUGIN_FILE, [ 'version' => 'Version' ] )['version'] ?? '',
		'eventCount' => count( $new_events ),
		'actions' => array_values( array_unique( array_column( $new_events, 'action_key' ) ) ),
		'privacyRedacted' => is_string( $raw )
			&& ! str_contains( $raw, 'PRIVATE_MAINWP_' )
			&& ! str_contains( $raw, 'MAINWP_SECRET_FIXTURE' )
			&& ! str_contains( $raw, 'private-mainwp@example.invalid' )
			&& ! str_contains( $raw, 'example.invalid' ),
		'events' => $new_events,
	];
}

function fixture_infinitewp_loaded(): bool {
	return defined( 'IWP_MMB_CLIENT_VERSION' ) && class_exists( 'IWP_MMB_Activities_log' );
}

function fixture_infinitewp_hook_state(): array {
	return [
		'loaded' => fixture_infinitewp_loaded(),
		'backupHook' => false !== has_action( 'added_post_meta', [ ChangeVigilSiteJournal\Capture::class, 'infinitewp_activity_added' ] ),
	];
}

function fixture_infinitewp_disabled(): array {
	global $iwp_mmb_activities_log;
	if ( ! fixture_infinitewp_loaded() || ! is_object( $iwp_mmb_activities_log ) ) {
		return [ 'supported' => false ];
	}

	$original_log_setting = get_option( 'is_save_activity_log', null );
	update_option( 'is_save_activity_log', true, false );
	try {
		$iwp_mmb_activities_log->iwp_mmb_save_iwp_activities(
			'backup',
			'multiCallNow',
			'direct',
			(object) [ 'what' => 'full' ],
			get_current_user_id()
		);
	} finally {
		if ( null === $original_log_setting ) {
			delete_option( 'is_save_activity_log' );
		} else {
			update_option( 'is_save_activity_log', $original_log_setting, false );
		}
	}
	$events = ChangeVigilSiteJournal\Store::query( [ 'category' => 'infinitewp' ], 10, 0, 0, true );
	fixture_check( is_array( $events ) );
	return [ 'supported' => true, 'capturedWhileDisabled' => (bool) $events ];
}

function fixture_infinitewp_events(): array {
	global $iwp_mmb_activities_log, $iwp_mmb_core, $wpdb;
	if ( ! fixture_infinitewp_loaded() || ! is_object( $iwp_mmb_activities_log ) || ! is_object( $iwp_mmb_core ) ) {
		return [ 'supported' => false ];
	}

	$user = wp_get_current_user();
	fixture_check( $user instanceof WP_User && $user->exists() );
	$last_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', ChangeVigilSiteJournal\Store::table() ) );
	if ( ! defined( 'IWP_AUTHORISED_CALL' ) ) {
		define( 'IWP_AUTHORISED_CALL', 1 );
	}
	$previous_params = isset( $iwp_mmb_core->request_params ) ? $iwp_mmb_core->request_params : null;
	$original_log_setting = get_option( 'is_save_activity_log', null );
	update_option( 'is_save_activity_log', true, false );

	try {
		$iwp_mmb_core->request_params = [ 'iwp_action' => 'create_post', 'username' => (string) $user->user_login ];
		$source_probe = wp_insert_post( [
			'post_title' => 'InfiniteWP source attribution probe',
			'post_type' => 'page',
			'post_status' => 'publish',
		], true );
		fixture_check( $source_probe );

		$iwp_mmb_core->request_params = [ 'iwp_action' => 'create_post', 'username' => 'different-account' ];
		$mismatch_probe = wp_insert_post( [
			'post_title' => 'InfiniteWP actor mismatch probe',
			'post_type' => 'page',
			'post_status' => 'publish',
		], true );
		fixture_check( $mismatch_probe );

		$iwp_mmb_core->request_params = null;
		$iwp_mmb_activities_log->iwp_mmb_save_iwp_activities(
			'backup',
			'multiCallNow',
			'direct',
			(object) [
				'what' => 'full',
				'backup_name' => 'PRIVATE_IWP_BACKUP_NAME',
				'include' => 'PRIVATE_IWP_PATH',
				'backup_repo_type' => 'PRIVATE_IWP_STORAGE',
				'api_key' => 'PRIVATE_IWP_SECRET',
			],
			(int) $user->ID
		);
		$iwp_mmb_activities_log->iwp_mmb_save_iwp_activities(
			'plugins',
			'update',
			'direct',
			(object) [ 'name' => 'PRIVATE_IWP_PLUGIN_NAME', 'slug' => 'private-plugin/private.php', 'old_version' => '1.0', 'updated_version' => '2.0' ],
			(int) $user->ID
		);
	} finally {
		$iwp_mmb_core->request_params = $previous_params;
		if ( null === $original_log_setting ) {
			delete_option( 'is_save_activity_log' );
		} else {
			update_option( 'is_save_activity_log', $original_log_setting, false );
		}
	}

	$events = ChangeVigilSiteJournal\Store::query( [ 'category' => 'infinitewp' ], 20, 0, 0, true );
	fixture_check( is_array( $events ) );
	$new_events = array_values( array_filter( $events, static fn( array $event ): bool => (int) ( $event['id'] ?? 0 ) > $last_id ) );
	$backup_events = array_values( array_filter( $new_events, static fn( array $event ): bool => 'infinitewp_backup_completed' === ( $event['action_key'] ?? '' ) ) );
	$source_events = ChangeVigilSiteJournal\Store::query( [ 'action' => 'content_created', 'item' => 'page:' . (int) $source_probe ], 10, 0, 0, true );
	$mismatch_events = ChangeVigilSiteJournal\Store::query( [ 'action' => 'content_created', 'item' => 'page:' . (int) $mismatch_probe ], 10, 0, 0, true );
	fixture_check( is_array( $source_events ) && is_array( $mismatch_events ) );
	$backup = $backup_events[0] ?? null;
	$details = is_array( $backup ) ? json_decode( (string) ( $backup['details'] ?? '' ), true ) ?: [] : [];
	$encoded = wp_json_encode( $new_events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return [
		'supported' => true,
		'eventCount' => count( $new_events ),
		'backupCount' => count( $backup_events ),
		'backupAction' => $backup['action_key'] ?? '',
		'backupSource' => $backup['source'] ?? '',
		'backupActor' => $backup['actor_name'] ?? '',
		'backupScope' => $details['changes']['Backup scope'][1] ?? '',
		'validRequestSource' => $source_events[0]['source'] ?? '',
		'mismatchedActorSource' => $mismatch_events[0]['source'] ?? '',
		'privacySafe' => is_string( $encoded )
			&& ! str_contains( $encoded, 'PRIVATE_IWP_' )
			&& ! str_contains( $encoded, 'private-plugin' ),
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

function fixture_file_scan_to_completion(): int {
    for ( $attempt = 0; $attempt < 30; ++$attempt ) {
        FileMonitor::scan();
        if ( ! get_option( Store::FILE_SCAN_STATE ) ) {
            return $attempt + 1;
        }
    }
    throw new RuntimeException( 'The bounded file scan did not finish in the fixture limit.' );
}

try {
    switch ( $operation ) {
        case 'reset':
            fixture_check( $wpdb->query( 'TRUNCATE TABLE ' . $table ) );
            fixture_check( $wpdb->query( 'TRUNCATE TABLE ' . Store::notes_table() ) );
            fixture_check( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Store::file_index_table() ) ) );
            update_option( Store::SETTINGS, [], false );
            delete_option( Store::HEALTH );
            delete_option( Store::FILE_SCAN_STATE );
            delete_option( 'cvsj_alert_digest_after_id' );
            wp_clear_scheduled_hook( Store::CLEANUP );
            wp_clear_scheduled_hook( Store::CONTINUE_CLEANUP );
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
            wp_clear_scheduled_hook( FileMonitor::HOOK );
            Store::schedule();
            wp_send_json( [ 'ok' => true ] );

        case 'integrations':
            wp_send_json( fixture_integration_events() );

        case 'woocommerce_disabled':
            wp_send_json( fixture_woocommerce_disabled() );

        case 'woocommerce':
            wp_send_json( fixture_woocommerce_events() );

        case 'acf_disabled':
            wp_send_json( fixture_acf_events( false ) );

        case 'acf':
            wp_send_json( fixture_acf_events( true ) );

        case 'acf_hook_state':
            wp_send_json( [
                'loaded' => function_exists( 'acf' ),
                'registered' => false !== has_filter( 'acf/update_value', [ ChangeVigilSiteJournal\Capture::class, 'acf_value_updated' ] ),
            ] );

        case 'bbpress_disabled':
            wp_send_json( fixture_bbpress_disabled() );

        case 'bbpress':
            wp_send_json( fixture_bbpress_events() );

        case 'wpforms_disabled':
            wp_send_json( fixture_wpforms_disabled() );

        case 'wpforms':
            wp_send_json( fixture_wpforms_events() );

        case 'wpforms_hook_state':
            wp_send_json( [
                'loaded' => defined( 'WPFORMS_VERSION' ),
                'registered' => false !== has_action( 'wpforms_process_complete', [ ChangeVigilSiteJournal\Capture::class, 'wpforms_entry_submitted' ] ),
            ] );

        case 'seo_hook_state':
            wp_send_json( fixture_seo_hook_state() );

        case 'seo_disabled':
            wp_send_json( fixture_seo_disabled( sanitize_key( $_POST['provider'] ?? '' ) ) );

        case 'seo':
            wp_send_json( fixture_seo_events( sanitize_key( $_POST['provider'] ?? '' ) ) );

        case 'pmpro_hook_state':
            wp_send_json( fixture_pmpro_hook_state() );

        case 'pmpro_disabled':
            wp_send_json( fixture_pmpro_disabled() );

        case 'pmpro':
            wp_send_json( fixture_pmpro_events() );
		case 'termly_hook_state':
			wp_send_json( fixture_termly_hook_state() );
		case 'termly':
			wp_send_json( fixture_termly_events() );
		case 'mainwp_hook_state':
			wp_send_json( fixture_mainwp_hook_state() );
		case 'mainwp':
			wp_send_json( fixture_mainwp_events() );
		case 'mainwp_transfer':
			wp_send_json( fixture_mainwp_transfer() );
		case 'infinitewp_hook_state':
			wp_send_json( fixture_infinitewp_hook_state() );
		case 'infinitewp_disabled':
			wp_send_json( fixture_infinitewp_disabled() );
		case 'infinitewp':
			wp_send_json( fixture_infinitewp_events() );

        case 'event_toggle':
            $probe_id = wp_insert_post( [
                'post_title' => 'Individual event control probe',
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_content' => 'Before the event control probe',
            ], true );
            fixture_check( $probe_id );
            fixture_check( wp_update_post( [
                'ID' => (int) $probe_id,
                'post_content' => 'After the event control probe',
            ], true ) );
            $edited_events = Store::query( [ 'item' => 'page:' . (int) $probe_id, 'action' => 'content_edited' ], 10 );
            fixture_check( is_array( $edited_events ) );
            wp_send_json( [ 'editedEvents' => count( $edited_events ) ] );

        case 'access_user':
            $user = get_user_by( 'login', 'piloteditor' );
            $password = wp_generate_password( 32, true, true );
            if ( ! $user ) {
                $user_id = wp_insert_user( [
                    'user_login' => 'piloteditor',
                    'user_email' => 'editor@example.test',
                    'user_pass' => $password,
                    'role' => 'editor',
                ] );
                fixture_check( $user_id );
                $user = get_user_by( 'id', $user_id );
            } else {
                wp_set_password( $password, (int) $user->ID );
                ( new WP_User( $user->ID ) )->set_role( 'editor' );
            }
            wp_send_json( [ 'userId' => (int) $user->ID, 'editorPassword' => $password ] );

        case 'exclusions':
            $admin_id = get_current_user_id();
            $editor = get_user_by( 'login', 'piloteditor' );
            fixture_check( $editor );
            $editor_id = (int) $editor->ID;
            $original_settings = Store::settings();
            $probe = static function( string $title, string $status = 'publish' ): int {
                $post_id = wp_insert_post( [ 'post_title' => $title, 'post_type' => 'page', 'post_status' => $status ], true );
                fixture_check( $post_id );
                $events = Store::query( [ 'q' => $title ], 10, 0, 0, true );
                fixture_check( $events );
                return count( $events );
            };
            $results = [];
            try {
                $settings = $original_settings;
                $settings['excluded_users'] = [ $editor_id ];
                $settings['excluded_roles'] = [];
                $settings['excluded_item_kinds'] = [];
                $settings['excluded_post_statuses'] = [];
                $settings['exclude_system_activity'] = false;
                update_option( Store::SETTINGS, $settings, false );
                wp_set_current_user( $editor_id );
                $results['user'] = 0 === $probe( 'Excluded by selected user' );

                $settings['excluded_users'] = [];
                $settings['excluded_roles'] = [ 'editor' ];
                update_option( Store::SETTINGS, $settings, false );
                $results['role'] = 0 === $probe( 'Excluded by selected role' );

                wp_set_current_user( $admin_id );
                $settings['excluded_roles'] = [];
                $settings['excluded_item_kinds'] = [ 'page' ];
                update_option( Store::SETTINGS, $settings, false );
                $results['item_kind'] = 0 === $probe( 'Excluded by item type' );

                $settings['excluded_item_kinds'] = [];
                $settings['excluded_post_statuses'] = [ 'draft' ];
                update_option( Store::SETTINGS, $settings, false );
                $results['post_status'] = 0 === $probe( 'Excluded by post status', 'draft' );

                $settings['excluded_post_statuses'] = [];
                $settings['exclude_system_activity'] = true;
                update_option( Store::SETTINGS, $settings, false );
                wp_set_current_user( 0 );
                $cron = static fn(): bool => true;
                add_filter( 'wp_doing_cron', $cron );
                try {
                    $results['system'] = 0 === $probe( 'Excluded by background task' );
                } finally {
                    remove_filter( 'wp_doing_cron', $cron );
                }

                wp_set_current_user( $admin_id );
                $settings['exclude_system_activity'] = false;
                update_option( Store::SETTINGS, $settings, false );
                $results['control'] = 1 === $probe( 'Allowed exclusion control' );
            } finally {
                wp_set_current_user( $admin_id );
                update_option( Store::SETTINGS, $original_settings, false );
            }
            wp_send_json( $results );

        case 'network_center':
            if ( ! is_multisite() || ! is_super_admin() ) {
                wp_send_json_error( 'A Multisite network administrator is required.', 403 );
            }
            $marker = 'Network center probe ' . strtolower( wp_generate_password( 8, false, false ) );
            $network = get_network();
            $site_id = wp_insert_site( [
                'domain' => (string) $network->domain,
                'path' => '/cvsj-network-' . strtolower( wp_generate_password( 8, false, false ) ) . '/',
                'title' => 'Network center test site',
                'user_id' => get_current_user_id(),
            ] );
            fixture_check( $site_id );
            $append_event = static function( string $summary, string $item_key, string $severity = 'info' ) use ( $marker ): int {
                global $wpdb;
                fixture_check( Store::add( [
                    'actor_kind' => 'user',
                    'actor_id' => get_current_user_id(),
                    'actor_name' => wp_get_current_user()->user_login,
                    'actor_role' => 'administrator',
                    'source' => 'admin',
                    'item_kind' => 'page',
                    'item_key' => $item_key,
                    'item_name' => $marker,
                    'action_key' => 'content_edited',
                    'severity' => $severity,
                    'summary' => $summary,
                ], [ 'changes' => [ 'Title' => [ 'Previous title', 'Updated title' ] ] ] ) );
                return (int) $wpdb->insert_id;
            };
            $child_event_id = 0;
            if ( ! switch_to_blog( (int) $site_id ) ) {
                fixture_check( false );
            }
            try {
                for ( $event_index = 1; $event_index <= 51; ++$event_index ) {
                    $child_event_id = $append_event( $marker . ' child event ' . $event_index, $marker . '-child-' . $event_index, 1 === $event_index ? 'warning' : 'info' );
                }
            } finally {
                restore_current_blog();
            }
            $main_event_id = $append_event( $marker . ' main event', $marker . '-main' );
            $second_main_event_id = $append_event( $marker . ' second main event', $marker . '-main-2' );
            wp_send_json( [
                'marker' => $marker,
                'siteId' => (int) $site_id,
                'childEventId' => $child_event_id,
                'mainEventId' => $main_event_id,
                'secondMainEventId' => $second_main_event_id,
            ] );

        case 'network_center_cleanup':
            if ( ! is_multisite() || ! is_super_admin() ) {
                wp_send_json_error( 'A Multisite network administrator is required.', 403 );
            }
            $marker = sanitize_text_field( wp_unslash( $_POST['marker'] ?? '' ) );
            $site_id = absint( $_POST['site_id'] ?? 0 );
            if ( ! preg_match( '/\ANetwork center probe [a-z0-9]{8}\z/', $marker ) || ! get_site( $site_id ) ) {
                wp_send_json_error( 'The network test fixture is invalid.', 400 );
            }
            if ( switch_to_blog( $site_id ) ) {
                try {
                    $event_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE item_name = %s', Store::table(), $marker ) );
                    foreach ( $event_ids as $event_id ) {
                        $wpdb->delete( Store::notes_table(), [ 'event_id' => (int) $event_id ], [ '%d' ] );
                    }
                    $wpdb->delete( Store::table(), [ 'item_name' => $marker ], [ '%s' ] );
                } finally {
                    restore_current_blog();
                }
            }
            $main_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE item_name = %s', Store::table(), $marker ) );
            foreach ( $main_ids as $event_id ) {
                $wpdb->delete( Store::notes_table(), [ 'event_id' => (int) $event_id ], [ '%d' ] );
            }
            $wpdb->delete( Store::table(), [ 'item_name' => $marker ], [ '%s' ] );
            $deleted = wp_delete_site( $site_id );
            fixture_check( $deleted );
            wp_send_json( [ 'cleaned' => true ] );

        case 'multisite':
            if ( ! is_multisite() ) {
                wp_send_json( [ 'supported' => false ] );
            }
            $multisite_path = '/cvsj-pilot-' . strtolower( wp_generate_password( 8, false, false ) ) . '/';
            $multisite_network = get_network();
            $multisite_site = wp_insert_site( [
                'domain' => (string) $multisite_network->domain,
                'path' => $multisite_path,
                'title' => 'Change Vigil pilot ' . $multisite_path,
                'user_id' => get_current_user_id(),
            ] );
            fixture_check( $multisite_site );
            $main_retention = Store::settings()['retention'];
            $multisite_results = [];
            $site_key = (int) $multisite_network->id . ':' . (int) $multisite_site;
            fixture_check( wp_update_site( (int) $multisite_site, [ 'archived' => 1 ] ) );
            fixture_check( wp_update_site( (int) $multisite_site, [ 'archived' => 0 ] ) );
            $lifecycle_events = Store::query( [ 'item' => 'site:' . $site_key ], 20, 0, 0, true );
            fixture_check( is_array( $lifecycle_events ) );
            $multisite_results['siteCreatedCaptured'] = count( array_filter( $lifecycle_events, static fn( array $event ): bool => 'site_created' === $event['action_key'] ) ) === 1;
            $status_events = array_values( array_filter( $lifecycle_events, static fn( array $event ): bool => 'site_status_changed' === $event['action_key'] ) );
            $status_changes = array_map( static fn( array $event ): array => json_decode( $event['details'], true )['changes']['Archived'] ?? [], $status_events );
            $multisite_results['siteStatusCaptured'] = 2 === count( $status_changes )
                && in_array( [ 'No', 'Yes' ], $status_changes, true )
                && in_array( [ 'Yes', 'No' ], $status_changes, true );
            $original_network_settings = Store::settings();
            update_option( Store::SETTINGS, array_merge( $original_network_settings, [ 'capture_network' => false ] ), false );
            fixture_check( wp_update_site( (int) $multisite_site, [ 'mature' => 1 ] ) );
            fixture_check( wp_update_site( (int) $multisite_site, [ 'mature' => 0 ] ) );
            update_option( Store::SETTINGS, array_merge( $original_network_settings, [ 'excluded_item_kinds' => [ 'site' ] ] ), false );
            fixture_check( wp_update_site( (int) $multisite_site, [ 'public' => 0 ] ) );
            fixture_check( wp_update_site( (int) $multisite_site, [ 'public' => 1 ] ) );
            update_option( Store::SETTINGS, $original_network_settings, false );
            $lifecycle_after_exclusions = Store::query( [ 'item' => 'site:' . $site_key ], 20, 0, 0, true );
            fixture_check( is_array( $lifecycle_after_exclusions ) );
            $multisite_results['networkExclusionsApplied'] = count( array_filter( $lifecycle_after_exclusions, static fn( array $event ): bool => 'site_status_changed' === $event['action_key'] ) ) === 2;
            switch_to_blog( (int) $multisite_site );
            try {
                $site_table = Store::table();
                $multisite_results['tableCreatedOnNewSite'] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $site_table ) ) ) === $site_table;
                $multisite_results['schemaInitializedOnNewSite'] = (int) get_option( Store::SCHEMA ) >= 6;
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
            fixture_check( wp_delete_site( (int) $multisite_site ) );
            $deleted_site_events = Store::query( [ 'item' => 'site:' . $site_key, 'action' => 'site_deleted' ], 10, 0, 0, true );
            fixture_check( is_array( $deleted_site_events ) );
            $multisite_results['siteDeletedCaptured'] = count( $deleted_site_events ) === 1;
            $multisite_results['mainRetention'] = Store::settings()['retention'];
            $main_events = Store::query( [ 'q' => 'Multisite isolation probe' ], 10, 0, 0, true );
            $multisite_results['eventIsolatedFromMainSite'] = is_array( $main_events ) && ! $main_events;
            $multisite_results['retentionIsolatedFromMainSite'] = $main_retention === $multisite_results['mainRetention'];
            $multisite_results['siteId'] = (int) $multisite_site;
            wp_send_json( [ 'supported' => true ] + $multisite_results );

        case 'network_options_email':
            $token = strtolower( wp_generate_password( 10, false, false ) );
            $privacy_email = 'privacy-' . $token . '@example.invalid';
            $site_email = (string) get_option( 'admin_email', '' );
            $pending_site_email = get_option( 'new_admin_email', false );
            $settings_before = Store::settings();
            $settings_test = array_merge( $settings_before, [
                'capture_network' => true,
                'capture_settings' => true,
                'disabled_actions' => array_values( array_diff( $settings_before['disabled_actions'], [
                    'network_setting_changed', 'admin_email_change_requested', 'admin_email_changed',
                    'network_admin_email_change_requested', 'network_admin_email_changed',
                ] ) ),
            ] );
            update_option( Store::SETTINGS, $settings_test, false );
            try {
                $site_event_baseline = Store::query( [], 1, 0, 0, false );
                fixture_check( is_array( $site_event_baseline ) );
                $site_event_baseline_id = isset( $site_event_baseline[0]['id'] ) ? (int) $site_event_baseline[0]['id'] : 0;
                update_option( 'new_admin_email', $privacy_email, false );
                update_option( 'admin_email', $privacy_email, false );
                update_option( 'admin_email', $site_email, false );
                if ( false === $pending_site_email ) {
                    delete_option( 'new_admin_email' );
                } else {
                    update_option( 'new_admin_email', $pending_site_email, false );
                }
                $site_email_events = Store::query( [ 'item' => 'setting:admin_email' ], 100, 0, 0, true );
                fixture_check( is_array( $site_email_events ) );
                $site_email_events = array_values( array_filter( $site_email_events, static fn( array $event ): bool => (int) $event['id'] > $site_event_baseline_id ) );
                $site_email_actions = array_column( $site_email_events, 'action_key' );
                $email_results = [
                    'siteEmailRequestCaptured' => in_array( 'admin_email_change_requested', $site_email_actions, true ),
                    'siteEmailChangeCaptured' => in_array( 'admin_email_changed', $site_email_actions, true ),
                    'siteEmailValuesRedacted' => ! str_contains( wp_json_encode( $site_email_events ), $privacy_email ),
                ];
                if ( is_multisite() ) {
                    $network_id = get_current_network_id();
                    $network_email = (string) get_site_option( 'admin_email', '' );
                    $pending_network_email = get_site_option( 'new_admin_email', false );
                    $old_registration = get_site_option( 'registration', 'none' );
                    $new_registration = 'all' === $old_registration ? 'none' : 'all';
                    $network_event_baseline = Store::query( [], 1, 0, 0, false );
                    fixture_check( is_array( $network_event_baseline ) );
                    $network_event_baseline_id = isset( $network_event_baseline[0]['id'] ) ? (int) $network_event_baseline[0]['id'] : 0;
                    try {
                        update_site_option( 'new_admin_email', $privacy_email );
                        update_site_option( 'admin_email', $privacy_email );
                        update_site_option( 'admin_email', $network_email );
                        if ( false === $pending_network_email ) {
                            delete_site_option( 'new_admin_email' );
                        } else {
                            update_site_option( 'new_admin_email', $pending_network_email );
                        }
                        update_site_option( 'registration', $new_registration );
                        update_site_option( 'registration', $old_registration );
                        $network_email_events = Store::query( [ 'item' => 'setting:' . $network_id . ':admin_email' ], 100, 0, 0, true );
                        $network_setting_events = Store::query( [ 'item' => 'setting:' . $network_id . ':registration' ], 100, 0, 0, true );
                        fixture_check( is_array( $network_email_events ) && is_array( $network_setting_events ) );
                        $network_email_events = array_values( array_filter( $network_email_events, static fn( array $event ): bool => (int) $event['id'] > $network_event_baseline_id ) );
                        $network_setting_events = array_values( array_filter( $network_setting_events, static fn( array $event ): bool => (int) $event['id'] > $network_event_baseline_id ) );
                        $email_results['networkEmailRequestCaptured'] = in_array( 'network_admin_email_change_requested', array_column( $network_email_events, 'action_key' ), true );
                        $email_results['networkEmailChangeCaptured'] = in_array( 'network_admin_email_changed', array_column( $network_email_events, 'action_key' ), true );
                        $email_results['networkEmailValuesRedacted'] = ! str_contains( wp_json_encode( $network_email_events ), $privacy_email );
                        $network_changes = array_values( array_filter( $network_setting_events, static fn( array $event ): bool => 'network_setting_changed' === $event['action_key'] ) );
                        $email_results['networkSettingValuesCaptured'] = 2 === count( $network_changes )
                            && ! str_contains( wp_json_encode( $network_changes ), $privacy_email )
                            && isset( json_decode( $network_changes[0]['details'], true )['changes']['New site registration'] );
                    } finally {
                        update_site_option( 'admin_email', $network_email );
                        if ( false === $pending_network_email ) {
                            delete_site_option( 'new_admin_email' );
                        } else {
                            update_site_option( 'new_admin_email', $pending_network_email );
                        }
                        update_site_option( 'registration', $old_registration );
                    }
                }
            } finally {
                update_option( 'admin_email', $site_email, false );
                if ( false === $pending_site_email ) {
                    delete_option( 'new_admin_email' );
                } else {
                    update_option( 'new_admin_email', $pending_site_email, false );
                }
                update_option( Store::SETTINGS, $settings_before, false );
            }
            wp_send_json( $email_results );

        case 'database_create':
            $database_table = $wpdb->prefix . 'cvsj_fixture_table_' . strtolower( wp_generate_password( 10, false, false ) );
            $create_result = $wpdb->query( "CREATE TABLE `$database_table` (id bigint unsigned NOT NULL) " . $wpdb->get_charset_collate() );
            fixture_check( $create_result );
            $found_table = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $database_table ) ) );
            fixture_check( $found_table === $database_table );
            update_option( 'cvsj_fixture_database_table', $database_table, false );
            wp_send_json( [ 'table' => $database_table, 'created' => true ] );

        case 'database_drop':
            $database_table = get_option( 'cvsj_fixture_database_table', '' );
            fixture_check( is_string( $database_table ) && str_starts_with( $database_table, $wpdb->prefix )
                && 1 === preg_match( '/\A[A-Za-z0-9_$]{1,64}\z/', $database_table ) );
            $drop_result = $wpdb->query( "DROP TABLE IF EXISTS `$database_table`" );
            fixture_check( $drop_result );
            delete_option( 'cvsj_fixture_database_table' );
            wp_send_json( [ 'table' => $database_table, 'deleted' => true ] );

        case 'file_monitor':
            $settings_before = Store::settings();
            $fixture_directory = ABSPATH . 'cvsj-file-monitor-fixture';
            $batch_directory = $fixture_directory . DIRECTORY_SEPARATOR . 'batch';
            $batch_files = [];
            $relative_path = 'cvsj-file-monitor-fixture/probe-' . strtolower( wp_generate_password( 8, false, false ) ) . '.txt';
            $fixture_path = ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
            $result = [];
            wp_mkdir_p( $fixture_directory );
            try {
                $settings = $settings_before;
                $settings['capture_files'] = true;
                $settings['monitor_uploads'] = false;
                $settings['file_excluded_paths'] = [ 'wp-admin', 'wp-includes', 'wp-content' ];
                update_option( Store::SETTINGS, $settings, false );
                fixture_file_scan_to_completion();

                wp_mkdir_p( $batch_directory );
                for ( $index = 0; $index < 105; ++$index ) {
                    $batch_path = $batch_directory . DIRECTORY_SEPARATOR . sprintf( 'batch-%03d.txt', $index );
                    fixture_check( false !== file_put_contents( $batch_path, 'batch fixture' ) );
                    $batch_files[] = $batch_path;
                }
                $batch_count = fixture_file_scan_to_completion();

                fixture_check( false !== file_put_contents( $fixture_path, 'PRIVATE_FILE_CONTENT_INITIAL' ) );
                fixture_file_scan_to_completion();
                fixture_check( false !== file_put_contents( $fixture_path, 'PRIVATE_FILE_CONTENT_CHANGED' ) );
                touch( $fixture_path, time() + 2 );
                fixture_file_scan_to_completion();
                $mode_before = fileperms( $fixture_path ) & 0777;
                chmod( $fixture_path, 0600 );
                clearstatcache( true, $fixture_path );
                $mode_after = fileperms( $fixture_path ) & 0777;
                if ( $mode_before === $mode_after ) {
                    chmod( $fixture_path, 0640 );
                    clearstatcache( true, $fixture_path );
                    $mode_after = fileperms( $fixture_path ) & 0777;
                }
                $permission_supported = $mode_before !== $mode_after;
                fixture_file_scan_to_completion();
                fixture_check( unlink( $fixture_path ) );
                fixture_file_scan_to_completion();

                $item_key = hash( 'sha256', $relative_path );
                $events = Store::query( [ 'item' => 'file:' . $item_key ], 50, 0, 0, true );
                fixture_check( $events );
                $actions = array_column( $events, 'action_key' );
                $encoded = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                $result = [
                    'actions' => $actions,
                    'created' => in_array( 'file_created', $actions, true ),
                    'modified' => in_array( 'file_modified', $actions, true ),
                    'permissionsSupported' => $permission_supported,
                    'permissionsChanged' => in_array( 'file_permissions_changed', $actions, true ),
                    'deleted' => in_array( 'file_deleted', $actions, true ),
                    'boundedBatchContinuation' => $batch_count >= 2,
                    'contentRedacted' => ! str_contains( (string) $encoded, 'PRIVATE_FILE_CONTENT' ),
                    'absolutePathHidden' => ! str_contains( (string) $encoded, str_replace( '\\', '/', untrailingslashit( ABSPATH ) ) ),
                    'relativePathRecorded' => str_contains( (string) $encoded, $relative_path ),
                    'relativePath' => $relative_path,
                ];
            } finally {
                if ( is_file( $fixture_path ) ) {
                    unlink( $fixture_path );
                }
                foreach ( $batch_files as $batch_path ) {
                    if ( is_file( $batch_path ) ) {
                        unlink( $batch_path );
                    }
                }
                if ( is_dir( $batch_directory ) ) {
                    rmdir( $batch_directory );
                }
                if ( is_dir( $fixture_directory ) ) {
                    rmdir( $fixture_directory );
                }
                update_option( Store::SETTINGS, $settings_before, false );
            }
            $disabled_settings = $settings_before;
            $disabled_settings['capture_files'] = false;
            update_option( Store::SETTINGS, $disabled_settings, false );
            $result['baselineClearedWhenDisabled'] = 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Store::file_index_table() ) );
            update_option( Store::SETTINGS, $settings_before, false );
            wp_send_json( $result );

        case 'comment_events':
            wp_send_json( [ 'commentId' => fixture_comment_events() ] );

        case 'warning':
            Store::add( [
                'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                'item_kind' => 'setting', 'item_key' => 'warning-fixture', 'item_name' => 'Warning fixture',
                'action_key' => 'setting_changed', 'severity' => 'warning', 'summary' => 'Warning digest scheduling fixture',
            ], [ 'note' => 'PRIVATE_DIGEST_DETAIL' ] );
            wp_send_json( [ 'scheduled' => (bool) wp_next_scheduled( Store::ALERT_DIGEST ) ] );

        case 'warning_content':
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
            delete_option( 'cvsj_alert_digest_after_id' );
            Store::add( [
                'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                'item_kind' => 'page', 'item_key' => 'warning-content-fixture', 'item_name' => 'Content warning fixture',
                'action_key' => 'content_edited', 'severity' => 'warning', 'summary' => 'Unselected category warning fixture',
            ], [ 'note' => 'PRIVATE_CONTENT_WARNING_DETAIL' ] );
            wp_send_json( [ 'scheduled' => (bool) wp_next_scheduled( Store::ALERT_DIGEST ) ] );

        case 'warning_batch':
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
            delete_option( 'cvsj_alert_digest_after_id' );
            for ( $index = 1; $index <= 3; ++$index ) {
                Store::add( [
                    'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin',
                    'item_kind' => 'setting', 'item_key' => 'warning-batch-' . $index, 'item_name' => 'Warning batch fixture',
                    'action_key' => 'setting_changed', 'severity' => 'warning', 'summary' => 'Warning digest batch ' . $index,
                ], [ 'note' => 'PRIVATE_BATCH_DETAIL_' . $index ] );
            }
            wp_send_json( [ 'scheduled' => (bool) wp_next_scheduled( Store::ALERT_DIGEST ) ] );

        case 'alert_cleanup':
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
            delete_option( 'cvsj_alert_digest_after_id' );
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
            $mail_recipients = is_array( $captured_mail ) ? (array) ( $captured_mail['to'] ?? [] ) : [];
            wp_send_json( [
                'sent' => is_array( $captured_mail ) && str_contains( $mail_body, 'Change Vigil recorded warning events' ),
                'containsKnownSummary' => str_contains( $mail_body, 'Warning digest scheduling fixture' ),
                'plainText' => in_array( 'Content-Type: text/plain; charset=UTF-8', $mail_headers, true ),
                'summaryOnly' => ! str_contains( $mail_body, 'PRIVATE_DIGEST_DETAIL' ),
                'safeSubject' => is_array( $captured_mail ) && ! preg_match( '/[\r\n]/', (string) ( $captured_mail['subject'] ?? '' ) ),
                'recipients' => array_values( array_map( 'sanitize_email', $mail_recipients ) ),
                'body' => $mail_body,
            ] );

        case 'custom_alert_begin':
            $snapshot = [
                'rules' => get_option( Alerts::RULES, false ),
                'queue' => get_option( 'cvsj_alert_rule_queue', false ),
                'channels' => get_option( Alerts::CHANNELS, false ),
                'retry_generation' => get_option( 'cvsj_alert_retry_generation', false ),
                'paused' => get_option( 'cvsj_alert_queue_paused', false ),
            ];
            fixture_check( update_option( 'cvsj_fixture_alert_snapshot', $snapshot, false ) || $snapshot === get_option( 'cvsj_fixture_alert_snapshot', false ) );
            update_option( Alerts::RULES, [], false );
            update_option( 'cvsj_alert_rule_queue', [], false );
            update_option( 'cvsj_alert_queue_paused', false, false );
            wp_clear_scheduled_hook( Alerts::HOOK );
            wp_clear_scheduled_hook( Alerts::RETRY_HOOK );
            wp_send_json( [ 'ready' => true ] );

        case 'custom_alert_event':
            $marker = strtoupper( sanitize_text_field( (string) ( $_POST['marker'] ?? '' ) ) );
            $variant = sanitize_key( (string) ( $_POST['variant'] ?? '' ) );
            $action = sanitize_key( (string) ( $_POST['action_key'] ?? 'setting_changed' ) );
            if ( ! preg_match( '/\AALERT_FIXTURE_[A-Z0-9]{8}\z/', $marker )
                || ! in_array( $variant, [ 'match', 'wrong_item', 'wrong_action', 'critical', 'plugin' ], true )
                || ! in_array( $action, [ 'setting_changed', 'theme_switched', 'plugin_installed' ], true ) ) {
                wp_send_json_error( 'The custom alert fixture is invalid.', 400 );
            }
            $item_name = match ( $variant ) {
                'match', 'wrong_action', 'plugin', 'critical' => 'Custom alert ' . $marker,
                default => 'Unrelated alert fixture',
            };
            $event_action = match ( $variant ) {
                'wrong_action' => 'theme_switched',
                'plugin' => 'plugin_installed',
                default => $action,
            };
            $severity = 'critical' === $variant ? 'critical' : 'info';
            $item_key = 'alert-fixture-' . strtolower( $marker ) . '-' . $variant;
            Store::add( [
                'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
                'source' => 'admin', 'item_kind' => 'setting', 'item_key' => $item_key, 'item_name' => $item_name,
                'action_key' => $event_action, 'severity' => $severity, 'summary' => 'Custom alert test event ' . $marker,
            ], [] );
            $event_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE item_key = %s ORDER BY id DESC LIMIT 1', Store::table(), $item_key ) );
            fixture_check( $event_id > 0 );
            $queue = (array) get_option( 'cvsj_alert_rule_queue', [] );
            $queued = (bool) array_filter( $queue, static fn( $entry ): bool => is_array( $entry ) && (int) ( $entry['event_id'] ?? 0 ) === $event_id );
            wp_send_json( [ 'eventId' => $event_id, 'queued' => $queued ] );

        case 'custom_alert_channel_probe':
            $expected_sid = strtoupper( sanitize_text_field( (string) ( $_POST['sid'] ?? '' ) ) );
            $expected_token = (string) ( $_POST['token'] ?? '' );
            $requests = [];
            $mock_http = static function( mixed $preempt, array $args, string $url ) use ( &$requests ): mixed {
                if ( false !== $preempt ) {
                    return $preempt;
                }
                $requests[] = [ 'url' => $url, 'args' => $args ];
                $twilio = str_contains( $url, 'api.twilio.com' );
                return [
                    'headers' => [],
                    'body' => $twilio ? '{"sid":"SM00000000000000000000000000000000"}' : 'ok',
                    'response' => [ 'code' => $twilio ? 201 : 200, 'message' => 'OK' ],
                    'cookies' => [],
                    'filename' => null,
                ];
            };
            add_filter( 'pre_http_request', $mock_http, 10, 3 );
            try {
                $slack_test = Alerts::test_channel( 'slack' );
                $sms_test = Alerts::test_channel( 'sms' );
            } finally {
                remove_filter( 'pre_http_request', $mock_http, 10 );
            }
            $slack_request = $requests[0] ?? [];
            $slack_payload = json_decode( (string) ( $slack_request['args']['body'] ?? '' ), true );
            $sms_request = $requests[1] ?? [];
            $sms_body = (array) ( $sms_request['args']['body'] ?? [] );
            if ( is_string( $sms_request['args']['body'] ?? null ) ) {
                parse_str( $sms_request['args']['body'], $sms_body );
            }
            $sms_headers = array_change_key_case( (array) ( $sms_request['args']['headers'] ?? [] ), CASE_LOWER );
            $authorization = (string) ( $sms_headers['authorization'] ?? '' );
            $encrypted_option = (string) get_option( Alerts::CHANNELS, '' );
            wp_send_json( [
                'slackAccepted' => ! is_wp_error( $slack_test ) && ! empty( $slack_test['sent'] ),
                'slackEndpoint' => 'https://hooks.slack.com/services/T00000000/B00000000/SAFEWEBHOOKTOKEN' === ( $slack_request['url'] ?? '' ),
                'slackPayload' => is_array( $slack_payload ) && isset( $slack_payload['text'] ) && false === $slack_payload['mrkdwn'] && false === $slack_payload['link_names'],
                'transportPolicy' => count( $requests ) === 2 && 8 === (int) ( $slack_request['args']['timeout'] ?? 0 )
                    && 0 === (int) ( $slack_request['args']['redirection'] ?? -1 )
                    && true === ( $slack_request['args']['sslverify'] ?? false ),
                'smsAccepted' => ! is_wp_error( $sms_test ) && ! empty( $sms_test['sent'] ),
                'twilioEndpoint' => 'https://api.twilio.com/2010-04-01/Accounts/' . $expected_sid . '/Messages.json' === ( $sms_request['url'] ?? '' ),
                'twilioAuth' => hash_equals( 'Basic ' . base64_encode( $expected_sid . ':' . $expected_token ), $authorization ),
                'smsRecipient' => '+353987654321' === ( $sms_body['To'] ?? '' ),
                'secretStoredEncrypted' => str_starts_with( $encrypted_option, 'v1:' )
                    && ! str_contains( $encrypted_option, $expected_token ) && ! str_contains( $encrypted_option, 'SAFEWEBHOOKTOKEN' ),
                'responseContainsNoSecrets' => ! str_contains( wp_json_encode( [ $slack_test, $sms_test, $slack_payload, $sms_body ] ), $expected_token )
                    && ! str_contains( wp_json_encode( [ $slack_test, $sms_test, $slack_payload, $sms_body ] ), 'SAFEWEBHOOKTOKEN' ),
            ] );

        case 'custom_alert_channel_delivery':
            $expected_sid = strtoupper( sanitize_text_field( (string) ( $_POST['sid'] ?? '' ) ) );
            $expected_token = (string) ( $_POST['token'] ?? '' );
            $mail_attempts = 0;
            $slack_attempts = 0;
            $sms_attempts = 0;
            $slack_failed_once = false;
            $slack_payload_safe = false;
            $twilio_request_safe = true;
            $capture_mail = static function( mixed $preempt, array $mail ) use ( &$mail_attempts ): bool {
                ++$mail_attempts;
                return true;
            };
            $mock_http = static function( mixed $preempt, array $args, string $url ) use ( &$slack_attempts, &$sms_attempts, &$slack_failed_once, &$slack_payload_safe, &$twilio_request_safe, $expected_sid, $expected_token ): mixed {
                if ( false !== $preempt ) {
                    return $preempt;
                }
                if ( str_contains( $url, 'hooks.slack.com' ) ) {
                    ++$slack_attempts;
                    $payload = json_decode( (string) ( $args['body'] ?? '' ), true );
                    $slack_payload_safe = is_array( $payload ) && str_contains( (string) ( $payload['text'] ?? '' ), 'ALERT_FIXTURE_' )
                        && false === ( $payload['mrkdwn'] ?? true );
                    if ( ! $slack_failed_once ) {
                        $slack_failed_once = true;
                        return [ 'headers' => [], 'body' => 'temporary_error', 'response' => [ 'code' => 503, 'message' => 'Unavailable' ], 'cookies' => [], 'filename' => null ];
                    }
                    return [ 'headers' => [], 'body' => 'ok', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
                }
                if ( str_contains( $url, 'api.twilio.com' ) ) {
                    ++$sms_attempts;
                    $headers = array_change_key_case( (array) ( $args['headers'] ?? [] ), CASE_LOWER );
                    $body = (array) ( $args['body'] ?? [] );
                    if ( is_string( $args['body'] ?? null ) ) {
                        parse_str( $args['body'], $body );
                    }
                    $twilio_request_safe = $twilio_request_safe
                        && hash_equals( 'Basic ' . base64_encode( $expected_sid . ':' . $expected_token ), (string) ( $headers['authorization'] ?? '' ) )
                        && in_array( (string) ( $body['To'] ?? '' ), [ '+353987654321', '+353123123123', '+442071838750' ], true )
                        && ! str_contains( wp_json_encode( $body ), $expected_token );
                    return [ 'headers' => [], 'body' => '{"sid":"SM00000000000000000000000000000000"}', 'response' => [ 'code' => 201, 'message' => 'Created' ], 'cookies' => [], 'filename' => null ];
                }
                return new WP_Error( 'cvsj_fixture_unexpected_host', 'The notification request used an unapproved destination.' );
            };
            add_filter( 'pre_wp_mail', $capture_mail, 10, 2 );
            add_filter( 'pre_http_request', $mock_http, 10, 3 );
            try {
                for ( $run = 0; $run < 4; ++$run ) {
                    $queue = (array) get_option( 'cvsj_alert_rule_queue', [] );
                    if ( ! $queue ) {
                        break;
                    }
                    foreach ( $queue as &$entry ) {
                        $entry['retry_at'] = 0;
                    }
                    unset( $entry );
                    update_option( 'cvsj_alert_rule_queue', $queue, false );
                    do_action( Alerts::HOOK );
                }
            } finally {
                remove_filter( 'pre_wp_mail', $capture_mail, 10 );
                remove_filter( 'pre_http_request', $mock_http, 10 );
            }
            wp_send_json( [
                'mailAttempts' => $mail_attempts,
                'slackAttempts' => $slack_attempts,
                'smsAttempts' => $sms_attempts,
                'queueRemaining' => count( (array) get_option( 'cvsj_alert_rule_queue', [] ) ),
                'slackPayloadSafe' => $slack_payload_safe,
                'twilioRequestSafe' => $twilio_request_safe,
            ] );

        case 'custom_alert_deliver':
            $failures_remaining = min( 2, max( 0, (int) ( $_POST['fail_attempts'] ?? 0 ) ) );
            $attempts = 0;
            $retry_delays = [];
            $captured = [];
            $capture = static function( mixed $result, array $mail ) use ( &$failures_remaining, &$attempts, &$captured ): bool {
                ++$attempts;
                $captured[] = [
                    'to' => array_values( array_map( 'sanitize_email', (array) ( $mail['to'] ?? [] ) ) ),
                    'subject' => (string) ( $mail['subject'] ?? '' ),
                    'body' => (string) ( $mail['message'] ?? '' ),
                    'headers' => (array) ( $mail['headers'] ?? [] ),
                ];
                if ( $failures_remaining > 0 ) {
                    --$failures_remaining;
                    return false;
                }
                return true;
            };
            add_filter( 'pre_wp_mail', $capture, 10, 2 );
            try {
                for ( $run = 0; $run < 4; ++$run ) {
                    $queue = (array) get_option( 'cvsj_alert_rule_queue', [] );
                    if ( ! $queue ) {
                        break;
                    }
                    foreach ( $queue as &$entry ) {
                        $entry['retry_at'] = 0;
                    }
                    unset( $entry );
                    update_option( 'cvsj_alert_rule_queue', $queue, false );
                    do_action( Alerts::HOOK );
                    $queue = (array) get_option( 'cvsj_alert_rule_queue', [] );
                    if ( $queue ) {
                        $retry_delays[] = max( 0, (int) $queue[0]['retry_at'] - time() );
                    }
                }
            } finally {
                remove_filter( 'pre_wp_mail', $capture, 10 );
            }
            wp_send_json( [
                'attempts' => $attempts,
                'retryDelays' => $retry_delays,
                'messages' => $captured,
                'queueRemaining' => count( (array) get_option( 'cvsj_alert_rule_queue', [] ) ),
            ] );

        case 'custom_alert_permissions':
            $editor = get_user_by( 'login', 'piloteditor' );
            fixture_check( $editor );
            $previous_user = get_current_user_id();
            wp_set_current_user( (int) $editor->ID );
            $read = rest_do_request( new WP_REST_Request( 'GET', '/change-vigil-site-journal/v1/alerts/rules' ) );
            $channel_read = rest_do_request( new WP_REST_Request( 'GET', '/change-vigil-site-journal/v1/alerts/channels' ) );
            $channel_write_request = new WP_REST_Request( 'POST', '/change-vigil-site-journal/v1/alerts/channels' );
            $channel_write_request->set_body_params( [] );
            $channel_write = rest_do_request( $channel_write_request );
            $channel_test_request = new WP_REST_Request( 'POST', '/change-vigil-site-journal/v1/alerts/channels/test' );
            $channel_test_request->set_body_params( [ 'channel' => 'slack' ] );
            $channel_test = rest_do_request( $channel_test_request );
            $write_request = new WP_REST_Request( 'POST', '/change-vigil-site-journal/v1/alerts/rules' );
            $write_request->set_body_params( [
                'name' => 'Unauthorized rule', 'enabled' => true, 'logic' => 'all',
                'conditions' => [ [ 'field' => 'severity', 'operator' => 'equals', 'value' => 'warning' ] ],
                'recipients' => [ 'pilot@example.test' ],
            ] );
            $write = rest_do_request( $write_request );
            wp_set_current_user( $previous_user );
            wp_send_json( [
                'read' => $read->get_status(),
                'write' => $write->get_status(),
                'channelRead' => $channel_read->get_status(),
                'channelWrite' => $channel_write->get_status(),
                'channelTest' => $channel_test->get_status(),
            ] );

        case 'custom_alert_finish':
            $marker = strtoupper( sanitize_text_field( (string) ( $_POST['marker'] ?? '' ) ) );
            if ( ! preg_match( '/\AALERT_FIXTURE_[A-Z0-9]{8}\z/', $marker ) ) {
                wp_send_json_error( 'The custom alert fixture identifier is invalid.', 400 );
            }
            update_option( Alerts::RULES, [], false );
            update_option( 'cvsj_alert_rule_queue', [], false );
            update_option( 'cvsj_alert_queue_paused', false, false );
            wp_clear_scheduled_hook( Alerts::HOOK );
            wp_clear_scheduled_hook( Alerts::RETRY_HOOK );
            $wpdb->query( $wpdb->prepare(
                'DELETE FROM %i WHERE item_key LIKE %s',
                Store::table(), $wpdb->esc_like( 'alert-fixture-' . strtolower( $marker ) . '-' ) . '%'
            ) );
            $snapshot = get_option( 'cvsj_fixture_alert_snapshot', false );
            if ( is_array( $snapshot ) ) {
                foreach ( [ Alerts::RULES => $snapshot['rules'] ?? false, 'cvsj_alert_rule_queue' => $snapshot['queue'] ?? false, Alerts::CHANNELS => $snapshot['channels'] ?? false, 'cvsj_alert_retry_generation' => $snapshot['retry_generation'] ?? false, 'cvsj_alert_queue_paused' => $snapshot['paused'] ?? false ] as $option => $value ) {
                    if ( false === $value ) {
                        delete_option( $option );
                    } else {
                        update_option( $option, $value, false );
                    }
                }
                delete_option( 'cvsj_fixture_alert_snapshot' );
                Alerts::schedule_pending();
            }
            wp_send_json( [ 'restored' => true ] );

        case 'developer_api':
            $marker = strtoupper( sanitize_text_field( (string) ( $_POST['marker'] ?? '' ) ) );
            if ( ! preg_match( '/\AAPI_FIXTURE_[A-F0-9]{8}\z/', $marker ) ) {
                wp_send_json_error( 'The developer API fixture identifier is invalid.', 400 );
            }
            $settings_snapshot = get_option( Store::SETTINGS, false );
            $type = 'cvsj_fixture_event_changed';
            $type_filter = static function ( array $types ): array {
                $types['cvsj_fixture_event_changed'] = [ 'category' => 'components', 'label' => 'Fixture record changed' ];
                return $types;
            };
            add_filter( 'cvsj_activity_log_external_event_types', $type_filter );
            try {
                $registry_available = in_array( $type, Capture::action_types(), true )
                    && 'Fixture record changed' === ( Store::action_labels()[ $type ] ?? '' )
                    && 'components' === Store::action_category( $type );
                $test_settings = Store::settings();
                $test_settings['capture_components'] = true;
                $test_settings['disabled_actions'] = array_values( array_diff( $test_settings['disabled_actions'], [ $type ] ) );
                update_option( Store::SETTINGS, $test_settings, false );
                $event = [
                    'event_type' => $type,
                    'item_kind' => 'plugin',
                    'item_id' => $marker,
                    'item_name' => 'Fixture extension ' . $marker,
                    'summary' => 'Changed plan for person@example.test at https://example.test/account from 192.0.2.10 +353861234567, api_key=PRIVATE_TEST_TOKEN',
                    'severity' => 'notice',
                    'details' => [ 'changes' => [ 'Plan' => [ 'Basic', 'Pro' ], 'API token' => [ 'PRIVATE_OLD', 'PRIVATE_NEW' ] ] ],
                ];
                $stored = DeveloperAPI::record_event( $event );
                $invalid_type_rejected = ! DeveloperAPI::record_event( array_merge( $event, [ 'event_type' => 'unregistered_fixture_event' ] ) );
                $unknown_field_rejected = ! DeveloperAPI::record_event( array_merge( $event, [ 'actor_id' => get_current_user_id() ] ) );
                $current_settings = Store::settings();
                $disabled_settings = $current_settings;
                $disabled_settings['disabled_actions'][] = $type;
                update_option( Store::SETTINGS, $disabled_settings, false );
                $disabled_action_suppressed = ! DeveloperAPI::record_event( array_merge( $event, [ 'item_id' => $marker . '_disabled' ] ) );
                $disabled_settings = Store::settings();
                $disabled_settings['disabled_actions'] = array_values( array_diff( $disabled_settings['disabled_actions'], [ $type ] ) );
                $disabled_settings['capture_components'] = false;
                update_option( Store::SETTINGS, $disabled_settings, false );
                $disabled_category_suppressed = ! DeveloperAPI::record_event( array_merge( $event, [ 'item_id' => $marker . '_category_off' ] ) );
                $row = $wpdb->get_row( $wpdb->prepare( 'SELECT actor_id, actor_kind, actor_role, source, item_key, action_key, summary, details FROM %i WHERE item_key = %s ORDER BY id DESC LIMIT 1', Store::table(), $marker ), ARRAY_A );
                $details = is_array( $row ) ? json_decode( (string) $row['details'], true ) : null;
                $saved = is_array( $row ) && $stored && $type === $row['action_key'] && $marker === $row['item_key'];
                $actor_attributed = $saved && (int) $row['actor_id'] === get_current_user_id() && 'user' === $row['actor_kind'] && 'administrator' === $row['actor_role'];
                $source_captured = $saved && in_array( $row['source'], Capture::SOURCES, true );
                $safe_details = $saved && is_array( $details ) && [ 'Basic', 'Pro' ] === ( $details['changes']['Plan'] ?? null ) && ! isset( $details['changes']['API token'] );
                $redacted = $saved && ! preg_match( '/person@example\.test|https:\/\/example\.test|192\.0\.2\.10|\+353861234567|PRIVATE_TEST_TOKEN/', $row['summary'] );
                $result = [
                    'registryAvailable' => $registry_available,
                    'eventSaved' => $saved,
                    'actorAttributed' => $actor_attributed,
                    'sourceCaptured' => $source_captured,
                    'sensitiveDetailsDropped' => $safe_details,
                    'personalValuesRedacted' => $redacted,
                    'unregisteredTypeRejected' => $invalid_type_rejected,
                    'unknownFieldRejected' => $unknown_field_rejected,
                    'disabledActionSuppressed' => $disabled_action_suppressed,
                    'disabledCategorySuppressed' => $disabled_category_suppressed,
                ];
                $fixture_passed = ! in_array( false, $result, true );
            } finally {
                remove_filter( 'cvsj_activity_log_external_event_types', $type_filter );
                if ( false === $settings_snapshot ) {
                    delete_option( Store::SETTINGS );
                } else {
                    $settings_snapshot['capture_components'] = true;
                    $settings_snapshot['disabled_actions'] = array_values( array_diff( (array) ( $settings_snapshot['disabled_actions'] ?? [] ), [ $type ] ) );
                    update_option( Store::SETTINGS, $settings_snapshot, false );
                }
                $wpdb->delete( Store::table(), [ 'item_key' => $marker ], [ '%s' ] );
                $wpdb->delete( Store::table(), [ 'item_key' => $marker . '_disabled' ], [ '%s' ] );
                $wpdb->delete( Store::table(), [ 'item_key' => $marker . '_category_off' ], [ '%s' ] );
            }
            if ( ! $fixture_passed ) {
                wp_send_json_error( $result, 500 );
            }
            wp_send_json( $result );

        case 'component_lifecycle':
            wp_send_json( fixture_component_events() );

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
            $component_events = fixture_component_events();
            $rest = wp_insert_post( [ 'post_title' => 'REST editor fixture', 'post_type' => 'page', 'post_status' => 'publish' ], true );
            fixture_check( $rest );
            $coverage = fixture_extended_events( (int) $editor );
            wp_set_password( $editor_password, (int) $editor );
            wp_send_json( [ 'restPage' => $rest, 'eventId' => (int) $page, 'scheduled' => $scheduled, 'deletedUser' => (int) $deleted, 'removedUser' => (int) $removed, 'siteRemovalCaptured' => $site_removal_captured, 'editorPassword' => $editor_password, 'coverage' => $coverage, 'componentEvents' => $component_events ] );

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

        case 'report_html':
            $item_key = strtolower( wp_generate_password( 12, false, false ) );
            for ( $index = 1; $index <= 251; ++$index ) {
                Store::add( [
                    'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
                    'source' => 'admin', 'origin_site_id' => 0, 'origin_event_id' => 0,
                    'item_kind' => 'setting', 'item_key' => $item_key, 'item_name' => 'Report <img src=x onerror=alert(1)>',
                    'action_key' => 'setting_changed', 'severity' => 'info', 'summary' => 'REPORT_HTML_ROW_' . $index . ' <script>alert(1)</script>',
                ], [ 'changes' => [ 'Title' => [ '<svg onload=alert(1)>', '<script>new value</script>' ] ] ] );
            }
            wp_send_json( [ 'item' => 'setting:' . $item_key, 'count' => 251 ] );

        case 'report_html_cleanup':
            $item = sanitize_text_field( (string) ( $_POST['item'] ?? '' ) );
            if ( ! preg_match( '/\Asetting:[a-z0-9]{12}\z/', $item ) ) {
                wp_send_json_error( 'The report fixture identifier is invalid.', 400 );
            }
            [ , $item_key ] = explode( ':', $item, 2 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only the uniquely identified report smoke fixture rows.
            $deleted = $wpdb->delete( Store::table(), [ 'item_kind' => 'setting', 'item_key' => $item_key, 'item_name' => 'Report <img src=x onerror=alert(1)>', 'action_key' => 'setting_changed' ], [ '%s', '%s', '%s', '%s' ] );
            fixture_check( false !== $deleted );
            wp_send_json( [ 'deleted' => $deleted ] );

        case 'report_html_cleanup_all':
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove only rows using the unique report HTML smoke marker.
            $deleted = $wpdb->delete( Store::table(), [ 'item_kind' => 'setting', 'item_name' => 'Report <img src=x onerror=alert(1)>', 'action_key' => 'setting_changed' ], [ '%s', '%s', '%s' ] );
            fixture_check( false !== $deleted );
            wp_send_json( [ 'deleted' => $deleted ] );

        case 'scheduled_report_seed':
            $item_key = strtolower( wp_generate_password( 12, false, false ) );
            $item_name = 'SCHEDULE_REPORT_FIXTURE_' . $item_key;
            for ( $index = 1; $index <= 1001; ++$index ) {
                Store::add( [
                    'actor_kind' => 'user', 'actor_id' => get_current_user_id(), 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
                    'source' => 'admin', 'origin_site_id' => 0, 'origin_event_id' => 0,
                    'item_kind' => 'setting', 'item_key' => $item_key, 'item_name' => $item_name,
                    'action_key' => 'setting_changed', 'severity' => 'info',
                    'summary' => 1 === $index ? '=1+1' : ( 2 === $index ? '<script>alert(1)</script>' : 'SCHEDULED_REPORT_ROW_' . $index ),
                ], [] );
            }
            wp_send_json( [ 'item' => $item_name, 'item_key' => $item_key, 'count' => 1001 ] );

        case 'privacy_roundtrip':
            wp_send_json( cvsj_privacy_fixture() );

        case 'scheduled_report_due':
            $id = strtolower( sanitize_text_field( (string) ( $_POST['id'] ?? '' ) ) );
            $schedules = (array) get_option( ScheduledReports::SCHEDULES, [] );
            $found = false;
            foreach ( $schedules as &$schedule ) {
                if ( is_array( $schedule ) && hash_equals( (string) ( $schedule['id'] ?? '' ), $id ) ) {
                    $schedule['next_run'] = 1;
                    $found = true;
                    break;
                }
            }
            unset( $schedule );
            fixture_check( $found && update_option( ScheduledReports::SCHEDULES, $schedules, false ) );
            wp_send_json( [ 'due' => true ] );

        case 'scheduled_report_run':
            $captured = null;
            $failures_remaining = min( 2, max( 0, (int) ( $_POST['fail_attempts'] ?? 0 ) ) );
            $attempts = 0;
            $retry_delays = [];
            add_filter( 'pre_wp_mail', static function ( $result, array $mail ) use ( &$captured, &$failures_remaining, &$attempts ) {
                ++$attempts;
                $attachment = (array) ( $mail['attachments'] ?? [] );
                $path = $attachment[0] ?? '';
                $captured = [
                    'to' => (array) ( $mail['to'] ?? [] ),
                    'subject' => (string) ( $mail['subject'] ?? '' ),
                    'body' => (string) ( $mail['message'] ?? '' ),
                    'attachment' => is_string( $path ) && is_file( $path ) ? file_get_contents( $path ) : '',
                    'path' => is_string( $path ) ? $path : '',
                    'inside_wordpress' => is_string( $path ) && str_starts_with( wp_normalize_path( $path ), wp_normalize_path( ABSPATH ) ),
                ];
                if ( $failures_remaining > 0 ) {
                    --$failures_remaining;
                    return false;
                }
                return true;
            }, 10, 2 );
            for ( $attempt = 0; $attempt < 120 && get_option( ScheduledReports::JOB, false ); ++$attempt ) {
                do_action( ScheduledReports::CONTINUE );
                $current_job = get_option( ScheduledReports::JOB, [] );
                if ( is_array( $current_job ) && ! empty( $current_job['ready_to_deliver'] ) && (int) ( $current_job['retry_at'] ?? 0 ) > time() ) {
                    $retry_delays[] = (int) $current_job['retry_at'] - time();
                    $current_job['retry_at'] = 0;
                    update_option( ScheduledReports::JOB, $current_job, false );
                }
            }
            if ( ! get_option( ScheduledReports::JOB, false ) ) {
                do_action( ScheduledReports::TICK );
                for ( $attempt = 0; $attempt < 120 && get_option( ScheduledReports::JOB, false ); ++$attempt ) {
                    do_action( ScheduledReports::CONTINUE );
                    $current_job = get_option( ScheduledReports::JOB, [] );
                    if ( is_array( $current_job ) && ! empty( $current_job['ready_to_deliver'] ) && (int) ( $current_job['retry_at'] ?? 0 ) > time() ) {
                        $retry_delays[] = (int) $current_job['retry_at'] - time();
                        $current_job['retry_at'] = 0;
                        update_option( ScheduledReports::JOB, $current_job, false );
                    }
                }
            }
            wp_send_json( [
                'mail' => $captured,
                'mail_attempts' => $attempts,
                'retry_delays' => $retry_delays,
                'path_exists_after' => is_array( $captured ) && '' !== $captured['path'] && file_exists( $captured['path'] ),
                'job_remaining' => (bool) get_option( ScheduledReports::JOB, false ),
            ] );

        case 'scheduled_report_cleanup':
            $item_key = sanitize_key( (string) ( $_POST['item_key'] ?? '' ) );
            if ( ! preg_match( '/\A[a-z0-9]{12}\z/', $item_key ) ) {
                wp_send_json_error( 'The scheduled report fixture identifier is invalid.', 400 );
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only uniquely identified scheduled report smoke fixture rows.
            $deleted = $wpdb->delete( Store::table(), [ 'item_kind' => 'setting', 'item_key' => $item_key, 'item_name' => 'SCHEDULE_REPORT_FIXTURE_' . $item_key, 'action_key' => 'setting_changed' ], [ '%s', '%s', '%s', '%s' ] );
            fixture_check( false !== $deleted );
            wp_send_json( [ 'deleted' => $deleted ] );

        case 'scheduled_report_expire_history':
            $history_id = strtolower( sanitize_text_field( (string) ( $_POST['id'] ?? '' ) ) );
            if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $history_id ) ) {
                wp_send_json_error( 'The report history identifier is invalid.', 400 );
            }
            $history = (array) get_option( ScheduledReports::HISTORY, [] );
            $archive_name = '';
            foreach ( $history as &$entry ) {
                if ( is_array( $entry ) && hash_equals( strtolower( (string) ( $entry['id'] ?? '' ) ), $history_id ) ) {
                    $archive_name = basename( (string) ( $entry['file'] ?? '' ) );
                    $entry['occurred_at'] = time() - ( Store::settings()['retention'] + 1 ) * DAY_IN_SECONDS;
                    break;
                }
            }
            unset( $entry );
            if ( ! preg_match( '/\Areport-[a-f0-9]{32}\.(?:csv|html)\z/', $archive_name ) ) {
                wp_send_json_error( 'The report history copy was not found.', 404 );
            }
            $archive_directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'change-vigil-site-journal-' . substr( hash( 'sha256', (string) get_option( 'siteurl' ) . (string) $wpdb->prefix . (string) get_current_blog_id() ), 0, 16 ) . DIRECTORY_SEPARATOR . 'history';
            $archive_path = $archive_directory . DIRECTORY_SEPARATOR . $archive_name;
            fixture_check( file_exists( $archive_path ) && update_option( ScheduledReports::HISTORY, $history, false ) );
            ScheduledReports::tick();
            $remaining_history = (array) get_option( ScheduledReports::HISTORY, [] );
            $still_listed = (bool) array_filter( $remaining_history, static fn( $entry ): bool => is_array( $entry ) && hash_equals( strtolower( (string) ( $entry['id'] ?? '' ) ), $history_id ) );
            wp_send_json( [ 'expired' => ! $still_listed && ! file_exists( $archive_path ) ] );

        case 'age_seed':
            fixture_seed( 1001, 'Expired retention probe', 'Expired retention probe', gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) );
            wp_send_json( [ 'seeded' => 1001 ] );

        case 'expired_count':
            $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count only disposable retention fixture rows in the custom test table.
            $count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE item_name = %s AND occurred_at < %s', $table, 'Expired retention probe', $cutoff ) );
            wp_send_json( [ 'count' => (int) $count ] );

        case 'permissions':
            $namespace = '/change-vigil-site-journal/v1';
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

        case 'session_seed':
            $suffix = strtolower( wp_generate_password( 8, false, false ) );
            $editor_login = 'cvsj_session_' . $suffix;
            $editor_password = wp_generate_password( 32, true, true );
            $editor_id = wp_create_user( $editor_login, $editor_password, $editor_login . '@example.test' );
            fixture_check( $editor_id );
            ( new WP_User( $editor_id ) )->set_role( 'editor' );
            $site_admin_login = 'cvsj_siteadmin_' . $suffix;
            $site_admin_password = wp_generate_password( 32, true, true );
            $site_admin_id = wp_create_user( $site_admin_login, $site_admin_password, $site_admin_login . '@example.test' );
            fixture_check( $site_admin_id );
            ( new WP_User( $site_admin_id ) )->set_role( 'administrator' );
            $session_manager = WP_Session_Tokens::get_instance( (int) $editor_id );
            $session_manager->destroy_all();
            $editor_refs = [];
            foreach ( [ DAY_IN_SECONDS, 2 * DAY_IN_SECONDS ] as $lifetime ) {
                $token = $session_manager->create( time() + $lifetime );
                $editor_refs[] = hash_hmac( 'sha256', hash( 'sha256', $token ), wp_salt( 'auth' ) );
            }
            wp_send_json( [
                'editorId' => (int) $editor_id,
                'editorLogin' => $editor_login,
                'editorPassword' => $editor_password,
                'editorRefs' => $editor_refs,
                'siteAdminId' => (int) $site_admin_id,
                'siteAdminLogin' => $site_admin_login,
                'siteAdminPassword' => $site_admin_password,
            ] );

        case 'toolbar_seed':
            fixture_seed( 1, 'Toolbar check', 'Toolbar event summary', gmdate( 'Y-m-d H:i:s' ) );
            wp_send_json( [ 'seeded' => true ] );

        case 'bulk_seed':
            $baseline = Store::latest_id();
            $suffix = strtolower( wp_generate_password( 8, false, false ) );
            $login = 'cvsj_bulk_' . $suffix;
            $user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), $login . '@example.test' );
            fixture_check( $user_id );
            ( new WP_User( $user_id ) )->set_role( 'editor' );
            $actor = get_userdata( $user_id );
            $event_ids = [];
            foreach ( [ 1, 2, 3 ] as $number ) {
                $item_key = (string) ( 8800 + $number );
                fixture_check( Store::add( [
                    'actor_kind' => 'user',
                    'actor_id' => (int) $user_id,
                    'actor_name' => $actor->user_login,
                    'actor_role' => 'editor',
                    'source' => 'admin',
                    'item_kind' => 'page',
                    'item_key' => $item_key,
                    'item_name' => 'Bulk action fixture ' . $suffix . ' ' . $number,
                    'action_key' => 'content_edited',
                    'severity' => 'info',
                    'summary' => 'Bulk action fixture event ' . $number,
                ], [ 'changes' => [ 'Title' => [ 'Before', 'After' ] ] ] ) );
                $event_ids[] = (int) $wpdb->insert_id;
            }
            wp_send_json( [ 'baseline' => (int) $baseline, 'marker' => $suffix, 'userId' => (int) $user_id, 'managerId' => get_current_user_id(), 'eventIds' => $event_ids ] );

        case 'investigation_filters_seed':
            $baseline = Store::latest_id();
            $suffix = strtolower( wp_generate_password( 8, false, false ) );
            $events = [];
            foreach ( [
                [ 'page', 'content_edited', 'page-' . $suffix ],
                [ 'plugin', 'plugin_activated', 'plugin-' . $suffix ],
                [ 'page', 'content_edited', 'page-other-' . $suffix ],
            ] as [ $item_kind, $action_key, $item_key ] ) {
                fixture_check( Store::add( [
                    'actor_kind' => 'user',
                    'actor_id' => get_current_user_id(),
                    'actor_name' => wp_get_current_user()->user_login,
                    'actor_role' => 'administrator',
                    'source' => 'admin',
                    'item_kind' => $item_kind,
                    'item_key' => $item_key,
                    'item_name' => 'Filter fixture ' . $suffix . ' ' . $item_key,
                    'action_key' => $action_key,
                    'severity' => 'info',
                    'summary' => 'Investigation filter fixture',
                ], [] ) );
                $events[] = [ 'id' => (int) $wpdb->insert_id, 'kind' => $item_kind, 'item_id' => $item_key, 'item_name' => 'Filter fixture ' . $suffix . ' ' . $item_key ];
            }
            wp_send_json( [ 'baseline' => (int) $baseline, 'marker' => $suffix, 'events' => $events ] );

        case 'investigation_filters_cleanup':
            $marker = sanitize_text_field( wp_unslash( $_POST['marker'] ?? '' ) );
            if ( ! preg_match( '/\A[a-z0-9]{8}\z/', $marker ) ) {
                wp_send_json_error( 'The investigation filter fixture is invalid.', 400 );
            }
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE item_name LIKE %s', $table, 'Filter fixture ' . $marker . ' %' ) );
            wp_send_json( [ 'cleaned' => true ] );

        case 'bulk_exclusion_state':
            $user_id = absint( $_POST['user_id'] ?? 0 );
            $item_key = sanitize_text_field( wp_unslash( $_POST['item_key'] ?? '' ) );
            $settings = Store::settings();
            wp_send_json( [
                'accountExcluded' => in_array( $user_id, $settings['excluded_users'], true ),
                'itemExcluded' => in_array( 'page:' . $item_key, $settings['excluded_items'], true ),
                'actionDisabled' => in_array( 'content_edited', $settings['disabled_actions'], true ),
                'accountSuppressed' => Store::should_exclude( 'user', $user_id, 'page', $item_key, [] ),
                'itemSuppressed' => Store::should_exclude( 'user', 999999, 'page', $item_key, [] ),
                'actionEnabled' => Store::action_enabled( 'content_edited' ),
            ] );

        case 'bulk_cleanup':
            $baseline = absint( $_POST['baseline'] ?? 0 );
            $user_id = absint( $_POST['user_id'] ?? 0 );
            if ( $user_id && get_userdata( $user_id ) instanceof WP_User ) {
                fixture_delete_account( $user_id );
            }
            $fixture_ids = $wpdb->get_col( $wpdb->prepare(
                'SELECT id FROM %i WHERE id > %d AND ( ( action_key = %s AND item_key = %s ) OR item_name LIKE %s OR ( item_kind = %s AND item_key = %s ) )',
                $table,
                $baseline,
                'recording_rules_changed',
                'recording-rules',
                'Bulk action fixture %',
                'user',
                (string) $user_id
            ) );
            $fixture_ids = array_map( 'intval', $fixture_ids );
            if ( $fixture_ids ) {
                $placeholders = implode( ', ', array_fill( 0, count( $fixture_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id IN (' . $placeholders . ')', Store::notes_table(), ...$fixture_ids ) );
                fixture_check( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', $table, ...$fixture_ids ) ) );
            }
            wp_send_json( [ 'deletedEvents' => count( $fixture_ids ), 'deletedAccount' => $user_id > 0 ] );

        case 'session_add':
            $user_id = absint( $_POST['user_id'] ?? 0 );
            $count = min( 20, max( 1, absint( $_POST['count'] ?? 1 ) ) );
            $session_user = get_userdata( $user_id );
            if ( ! $session_user instanceof WP_User ) {
                wp_send_json_error( 'Session fixture account missing.', 404 );
            }
            $session_manager = WP_Session_Tokens::get_instance( $user_id );
            $added = 0;
            for ( $index = 0; $index < $count; ++$index ) {
                if ( $session_manager->create( time() + DAY_IN_SECONDS ) ) {
                    ++$added;
                }
            }
            wp_send_json( [ 'added' => $added ] );

        case 'session_prepare_idle_expiry':
            $user_id = absint( $_POST['user_id'] ?? 0 );
            $age = min( 30 * DAY_IN_SECONDS, max( 0, absint( $_POST['age'] ?? 0 ) ) );
            $policy_age = min( 30 * DAY_IN_SECONDS, max( 0, absint( $_POST['policy_age'] ?? $age ) ) );
            $access = new ChangeVigilSiteJournal\SessionAccess( $user_id );
            $activity = [];
            $settings = (array) get_option( ChangeVigilSiteJournal\Sessions::SETTINGS, [] );
            $settings['idle_enabled_at'] = time() - $policy_age;
            update_option( ChangeVigilSiteJournal\Sessions::SETTINGS, $settings, false );
            $sessions = $access->indexed_sessions();
            foreach ( $sessions as $verifier => $session ) {
                $reference = hash_hmac( 'sha256', (string) $verifier, wp_salt( 'auth' ) );
                $activity[ $reference ] = time() - $age;
                $session['login'] = time() - $age;
                $sessions[ $verifier ] = $session;
            }
            update_user_meta( $user_id, 'session_tokens', $sessions );
            set_transient( 'cvsj_session_activity_' . $user_id, $activity, 30 * DAY_IN_SECONDS );
            wp_send_json( [ 'marked' => count( $activity ), 'marked_at' => time() - $age, 'policy_age' => time() - (int) ( $settings['idle_enabled_at'] ?? 0 ) ] );

        case 'session_cleanup':
            $user_ids = array_filter( [ absint( $_POST['editor_id'] ?? 0 ), absint( $_POST['site_admin_id'] ?? 0 ) ] );
            ChangeVigilSiteJournal\Sessions::erase_activity_for_user( get_current_user_id() );
            foreach ( $user_ids as $user_id ) {
                ChangeVigilSiteJournal\Sessions::erase_activity_for_user( (int) $user_id );
                $session_events = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE item_kind = %s AND item_key = %s', $table, 'user', (string) $user_id ) );
                if ( $session_events ) {
                    $ids = array_map( 'intval', $session_events );
                    $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
                    $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id IN (' . $placeholders . ')', Store::notes_table(), ...$ids ) );
                    $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', $table, ...$ids ) );
                }
                fixture_delete_account( (int) $user_id );
            }
            $toolbar_event_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE item_name = %s', $table, 'Toolbar check' ) );
            if ( $toolbar_event_ids ) {
                $ids = array_map( 'intval', $toolbar_event_ids );
                $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id IN (' . $placeholders . ')', Store::notes_table(), ...$ids ) );
                $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', $table, ...$ids ) );
            }
            update_option( ChangeVigilSiteJournal\Sessions::SETTINGS, [ 'track_activity' => false, 'show_browser' => false, 'show_ip' => false ], false );
            wp_clear_scheduled_hook( ChangeVigilSiteJournal\Sessions::CLEAR_ACTIVITY );
            delete_option( 'cvsj_session_clear_cursor' );
            wp_send_json( [ 'deleted' => count( $user_ids ) ] );

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
            deactivate_plugins( CVSJ_PLUGIN );
            $unscheduled = ! wp_next_scheduled( Store::CLEANUP );
            fixture_check( activate_plugin( CVSJ_PLUGIN ) );
            $latest_after_reactivation = Store::latest_id();
            $scheduled_after_reactivation = wp_next_scheduled( Store::CLEANUP );
            $reactivated = $unscheduled && $latest_after_reactivation >= $before && (bool) $scheduled_after_reactivation;
            fixture_check( wp_clear_scheduled_hook( Store::CLEANUP ) );
            Store::upgrade();
            $upgrade_rescheduled = (bool) wp_next_scheduled( Store::CLEANUP );
            fixture_check( wp_clear_scheduled_hook( Store::CLEANUP ) );
            $reject_cleanup_schedule = static function ( mixed $pre, object $event ): mixed {
                return Store::CLEANUP === $event->hook
                    ? new WP_Error( 'cvsj_fixture_schedule_failure', 'Intentional pilot schedule failure.' )
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
            remove_action( 'wp_after_insert_post', [ ChangeVigilSiteJournal\Capture::class, 'post' ], 30 );
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
            deactivate_plugins( CVSJ_PLUGIN );
            wp_send_json( [ 'inactive' => ! is_plugin_active( CVSJ_PLUGIN ) ] );

        case 'uninstall':
            define( 'WP_UNINSTALL_PLUGIN', CVSJ_PLUGIN );
            $script = WP_PLUGIN_DIR . '/change-vigil-site-journal/uninstall.php';
            $before = Store::latest_id();
            update_option( Store::SAVED_REPORTS, [ [
                'id' => wp_generate_uuid4(), 'name' => 'Uninstall preservation fixture', 'filters' => [],
                'group' => 'category', 'interval' => 'daily', 'range_days' => 30, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
            ] ], false );
            update_option( 'cvsj_privacy_erase_state', [ 'fixture' => [ 'cursor' => 7, 'updated_at' => time() ] ], false );
            update_option( Store::SETTINGS, [ 'retention' => 60, 'erase' => false ] );
            require $script;
            $preserved = Store::latest_id() === $before && is_array( get_option( Store::SAVED_REPORTS ) ) && false === get_option( 'cvsj_privacy_erase_state' );
            update_option( Store::SETTINGS, [ 'retention' => 60, 'erase' => true ] );
            update_option( 'cvsj_privacy_erase_state', [ 'fixture' => [ 'cursor' => 7, 'updated_at' => time() ] ], false );
            require $script;
            $erased = null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) )
                && false === get_option( Store::SETTINGS ) && false === get_option( Store::SCHEMA ) && false === get_option( Store::HEALTH )
                && false === get_option( Store::SAVED_REPORTS ) && false === get_option( Alerts::RULES )
                && false === get_option( Alerts::CHANNELS ) && false === get_option( 'cvsj_privacy_erase_state' );
            wp_send_json( compact( 'preserved', 'erased' ) );
    }
} catch ( Throwable $error ) {
    wp_send_json_error( $error->getMessage(), 500 );
}
wp_send_json_error( 'Unknown fixture.', 400 );
