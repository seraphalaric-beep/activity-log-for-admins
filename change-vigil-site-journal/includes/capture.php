<?php

namespace ChangeVigilSiteJournal;

defined( 'ABSPATH' ) || exit;

final class Capture {
    public const ACTIONS = [
        'content_created', 'content_edited', 'content_status', 'content_trashed', 'content_restored',
        'user_created', 'user_deleted', 'user_removed_from_site', 'role_changed', 'signed_in', 'sign_in_failed',
        'signed_out', 'user_profile_changed', 'password_changed',
        'session_ended', 'sessions_ended', 'session_policy_ended', 'session_policy_changed',
        'site_created', 'site_deleted', 'site_status_changed', 'network_setting_changed',
        'database_table_created', 'database_table_deleted',
        'file_created', 'file_modified', 'file_deleted', 'file_permissions_changed',
        'file_scan_started', 'file_scan_completed', 'file_scan_failed', 'file_scan_size_skipped',
        'admin_email_change_requested', 'admin_email_changed',
        'network_admin_email_change_requested', 'network_admin_email_changed',
        'term_created', 'term_edited', 'term_deleted', 'terms_changed',
        'comment_created', 'comment_edited', 'comment_status', 'comment_deleted',
        'menu_created', 'menu_updated', 'menu_deleted', 'menu_item_added', 'menu_item_updated', 'menu_item_deleted', 'widget_changed',
        'order_created', 'order_status_changed', 'order_refunded', 'order_trashed', 'order_restored', 'order_deleted',
        'product_created', 'product_edited', 'product_status_changed', 'product_trashed', 'product_restored', 'product_deleted',
        'coupon_created', 'coupon_edited', 'coupon_status_changed', 'coupon_trashed', 'coupon_restored', 'coupon_deleted',
        'woocommerce_setting_changed', 'acf_fields_changed',
        'yoast_seo_meta_changed', 'yoast_seo_settings_changed',
        'rank_math_seo_meta_changed', 'rank_math_seo_settings_changed',
        'membership_changed', 'membership_level_created', 'membership_level_updated', 'membership_level_deleted',
        'termly_connected', 'termly_disconnected', 'termly_banner_changed', 'termly_autoblocker_changed', 'termly_scan_schedule_changed', 'termly_robot_allowlist_changed',
        'mainwp_site_added', 'mainwp_site_updated', 'mainwp_site_deleted', 'mainwp_site_suspended', 'mainwp_site_resumed', 'mainwp_site_sync_succeeded', 'mainwp_site_sync_failed',
        'mainwp_client_created', 'mainwp_client_updated', 'mainwp_client_deleted', 'mainwp_client_suspended', 'mainwp_client_resumed',
        'infinitewp_backup_completed',
        'forum_created', 'forum_edited', 'forum_status_changed', 'forum_trashed', 'forum_restored', 'forum_deleted',
        'topic_created', 'topic_edited', 'topic_status_changed', 'topic_trashed', 'topic_restored', 'topic_deleted',
        'reply_created', 'reply_edited', 'reply_status_changed', 'reply_trashed', 'reply_restored', 'reply_deleted',
        'wpforms_form_created', 'wpforms_form_edited', 'wpforms_form_status_changed', 'wpforms_form_trashed', 'wpforms_form_restored', 'wpforms_form_deleted', 'wpforms_entry_submitted',
        'redirect_updated', 'redirect_deleted', 'redirect_enabled', 'redirect_disabled',
        'plugin_settings_changed', 'recording_rules_changed',
        'plugin_installed', 'plugin_activated', 'plugin_deactivated', 'plugin_updated', 'plugin_deleted',
        'theme_installed', 'theme_switched', 'theme_updated', 'theme_deleted', 'setting_changed',
    ];
    public const SEVERITIES = [ 'info', 'notice', 'warning' ];
    public const SOURCES = [ 'admin', 'rest', 'cron', 'cli', 'xmlrpc', 'frontend', 'infinitewp', 'unknown' ];

    public static function action_types(): array {
        return array_values( array_unique( array_merge( self::ACTIONS, array_keys( Store::action_categories() ) ) ) );
    }

    public static function external_event_context(): array {
        [ $actor_kind, $actor_id, $actor_name ] = self::actor();
        $actor_role = '';
        if ( 'user' === $actor_kind && $actor_id ) {
            $user = get_userdata( $actor_id );
            if ( $user instanceof \WP_User ) {
                $roles = (array) $user->roles;
                $actor_role = substr( sanitize_key( (string) reset( $roles ) ), 0, 64 );
            }
        }
        return [
            'actor_kind' => $actor_kind,
            'actor_id' => $actor_id,
            'actor_name' => $actor_name,
            'actor_role' => $actor_role,
            'source' => self::source(),
        ];
    }

    private const OPTIONS = [
        'blog_public' => 'Site visibility',
        'default_role' => 'Default role',
        'permalink_structure' => 'Permalink structure',
        'home' => 'Site address',
        'siteurl' => 'WordPress address',
    ];
    private const NETWORK_OPTIONS = [
        'registration' => 'New site registration',
        'registrationnotification' => 'Registration notifications',
        'add_new_users' => 'Add new users',
        'upload_space' => 'Network upload limit',
        'blog_upload_space' => 'Site upload limit',
        'fileupload_maxk' => 'File upload limit',
        'WPLANG' => 'Network language',
        'global_terms_enabled' => 'Global terms',
        'ms_files_rewriting' => 'Legacy media URL rewriting',
    ];
    private const WOOCOMMERCE_PRODUCT_META = [
        '_sku' => 'SKU',
        '_regular_price' => 'Regular price',
        '_sale_price' => 'Sale price',
        '_sale_price_dates_from' => 'Sale starts',
        '_sale_price_dates_to' => 'Sale ends',
        '_tax_status' => 'Tax status',
        '_tax_class' => 'Tax class',
        '_manage_stock' => 'Stock management',
        '_stock' => 'Stock quantity',
        '_stock_status' => 'Stock status',
        '_backorders' => 'Backorders',
        '_low_stock_amount' => 'Low stock threshold',
        '_sold_individually' => 'Sold individually',
        '_weight' => 'Weight',
        '_length' => 'Length',
        '_width' => 'Width',
        '_height' => 'Height',
        '_featured' => 'Featured product',
        '_virtual' => 'Virtual product',
        '_downloadable' => 'Downloadable product',
    ];
    private const WOOCOMMERCE_COUPON_META = [
        'discount_type' => 'Discount type',
        'coupon_amount' => 'Discount amount',
        'expiry_date' => 'Legacy expiry date',
        'date_expires' => 'Expiry date',
        'individual_use' => 'Individual use',
        'product_ids' => 'Included products',
        'exclude_product_ids' => 'Excluded products',
        'usage_limit' => 'Usage limit',
        'usage_limit_per_user' => 'Usage limit per user',
        'limit_usage_to_x_items' => 'Maximum discounted items',
        'free_shipping' => 'Free shipping',
        'product_categories' => 'Included categories',
        'exclude_product_categories' => 'Excluded categories',
        'exclude_sale_items' => 'Exclude sale items',
        'minimum_amount' => 'Minimum spend',
        'maximum_amount' => 'Maximum spend',
    ];
    private const WOOCOMMERCE_SETTING_OPTIONS = [
        'woocommerce_enable_coupons' => 'Coupons',
        'woocommerce_currency' => 'Store currency',
        'woocommerce_currency_pos' => 'Currency position',
        'woocommerce_price_thousand_sep' => 'Thousands separator',
        'woocommerce_price_decimal_sep' => 'Decimal separator',
        'woocommerce_price_num_decimals' => 'Price decimal places',
        'woocommerce_calc_taxes' => 'Tax calculation',
        'woocommerce_prices_include_tax' => 'Prices include tax',
        'woocommerce_tax_based_on' => 'Tax based on',
        'woocommerce_shipping_tax_class' => 'Shipping tax class',
        'woocommerce_tax_round_at_subtotal' => 'Round tax at subtotal',
        'woocommerce_allowed_countries' => 'Allowed countries mode',
        'woocommerce_all_except_countries' => 'Excluded countries',
        'woocommerce_specific_allowed_countries' => 'Allowed countries',
        'woocommerce_ship_to_countries' => 'Shipping destinations',
        'woocommerce_specific_ship_to_countries' => 'Specific shipping countries',
        'woocommerce_default_customer_address' => 'Default customer location',
        'woocommerce_manage_stock' => 'Stock management',
        'woocommerce_hold_stock_minutes' => 'Unpaid order hold time',
        'woocommerce_notify_low_stock' => 'Low stock notifications',
        'woocommerce_notify_no_stock' => 'Out of stock notifications',
        'woocommerce_notify_low_stock_amount' => 'Low stock threshold',
        'woocommerce_notify_no_stock_amount' => 'Out of stock threshold',
        'woocommerce_hide_out_of_stock_items' => 'Hide out of stock products',
        'woocommerce_stock_format' => 'Stock display format',
        'woocommerce_weight_unit' => 'Weight unit',
        'woocommerce_dimension_unit' => 'Dimension unit',
        'woocommerce_enable_reviews' => 'Product reviews',
        'woocommerce_enable_review_rating' => 'Product ratings',
        'woocommerce_review_rating_required' => 'Require a rating',
        'woocommerce_review_rating_verification_required' => 'Verified owner reviews only',
        'woocommerce_shop_page_id' => 'Shop page',
        'woocommerce_cart_page_id' => 'Cart page',
        'woocommerce_checkout_page_id' => 'Checkout page',
        'woocommerce_myaccount_page_id' => 'Account page',
        'woocommerce_terms_page_id' => 'Terms page',
    ];
    private const POST_META = [
        '_wp_page_template' => 'Template',
        '_thumbnail_id'     => 'Featured image',
    ];
    private const SEO_META_FIELDS = [
        'yoast' => [
            '_yoast_wpseo_title' => 'SEO title',
            '_yoast_wpseo_metadesc' => 'Meta description',
            '_yoast_wpseo_focuskw' => 'Focus keyphrase',
            '_yoast_wpseo_canonical' => 'Canonical URL',
            '_yoast_wpseo_meta-robots-noindex' => 'Indexing setting',
            '_yoast_wpseo_meta-robots-nofollow' => 'Link following setting',
            '_yoast_wpseo_meta-robots-adv' => 'Advanced robots setting',
            '_yoast_wpseo_is_cornerstone' => 'Cornerstone content setting',
            '_yoast_wpseo_opengraph-title' => 'Social title',
            '_yoast_wpseo_opengraph-description' => 'Social description',
            '_yoast_wpseo_opengraph-image' => 'Social image',
            '_yoast_wpseo_twitter-title' => 'X title',
            '_yoast_wpseo_twitter-description' => 'X description',
            '_yoast_wpseo_twitter-image' => 'X image',
        ],
        'rank_math' => [
            'rank_math_title' => 'SEO title',
            'rank_math_description' => 'Meta description',
            'rank_math_focus_keyword' => 'Focus keyphrase',
            'rank_math_canonical_url' => 'Canonical URL',
            'rank_math_robots' => 'Indexing setting',
            'rank_math_advanced_robots' => 'Advanced robots setting',
            'rank_math_pillar_content' => 'Pillar content setting',
            'rank_math_primary_category' => 'Primary category setting',
            'rank_math_facebook_title' => 'Social title',
            'rank_math_facebook_description' => 'Social description',
            'rank_math_facebook_image' => 'Social image',
            'rank_math_facebook_image_id' => 'Social image',
            'rank_math_twitter_title' => 'X title',
            'rank_math_twitter_description' => 'X description',
            'rank_math_twitter_image' => 'X image',
            'rank_math_twitter_image_id' => 'X image',
        ],
    ];
    private const SEO_SETTING_OPTIONS = [
        'yoast' => [
            'wpseo' => 'General settings',
            'wpseo_titles' => 'Titles and metadata settings',
            'wpseo_taxonomy_meta' => 'Taxonomy settings',
        ],
        'rank_math' => [
            'rank-math-options-general' => 'General settings',
            'rank-math-options-titles' => 'Titles and metadata settings',
            'rank-math-options-sitemap' => 'Sitemap settings',
        ],
    ];
    private const ACF_CHANGE_LIMIT = 40;
    private static array $creating_users = [];
    private static array $deleting_users = [];
    private static array $previous_roles = [];
    private static array $previous_deleted_roles = [];
    private static array $previous_post_meta = [];
    private static array $previous_menu_urls = [];
    private static array $previous_deleted_post_meta = [];
    private static array $deleting_posts = [];
    private static array $deleting_site_names = [];
    private static array $database_tables = [];
    private static bool $database_table_probe = false;
    private static array $previous_terms = [];
    private static array $previous_comment_hashes = [];
    private static array $pending_menu_items = [];
    private static array $deleting_menu_items = [];
    private static array $previous_versions = [ 'plugin' => [], 'theme' => [] ];
    private static array $bulk_updates = [ 'plugin' => [], 'theme' => [] ];
    private static array $woocommerce_changes = [];
    private static array $woocommerce_meta_before = [];
    private static array $woocommerce_meta_deleted = [];
    private static array $deleting_woocommerce_items = [];
    private static array $deleting_wpforms_forms = [];
    private static array $acf_changes = [];
    private static array $seo_meta_before = [];
    private static array $seo_meta_deleted = [];
    private static array $seo_changes = [];
    private static array $seo_settings_changes = [];
    private static array $pmpro_level_before = [];
    private static bool $termly_api_key_was_present_before_delete = false;
    private static array $deleting_bbpress_items = [];
    private static array $bbpress_forum_state_before = [];
    private static array $bbpress_topic_sticky_before = [];
    private static ?array $bbpress_post_types = null;

    public static function register(): void {
        add_action( 'wp_after_insert_post', [ self::class, 'post' ], 30, 4 );
        add_action( 'before_delete_post', [ self::class, 'before_delete_post' ], 10, 2 );
        add_action( 'deleted_post', [ self::class, 'deleted_post' ], 10, 2 );
        add_action( 'update_postmeta', [ self::class, 'post_meta_before' ], 10, 4 );
        add_action( 'updated_post_meta', [ self::class, 'post_meta_updated' ], 10, 4 );
        add_action( 'added_post_meta', [ self::class, 'post_meta_added' ], 10, 4 );
        add_action( 'delete_post_meta', [ self::class, 'post_meta_before_delete' ], 10, 4 );
        add_action( 'deleted_post_meta', [ self::class, 'post_meta_deleted' ], 10, 4 );
        add_action( 'created_term', [ self::class, 'term_created' ], 10, 3 );
        add_action( 'edit_terms', [ self::class, 'term_before_edit' ], 10, 3 );
        add_action( 'edited_term', [ self::class, 'term_edited' ], 10, 3 );
        add_action( 'delete_term', [ self::class, 'term_deleted' ], 10, 5 );
        add_action( 'set_object_terms', [ self::class, 'terms_assigned' ], 10, 6 );
        add_action( 'comment_post', [ self::class, 'comment_created' ], 10, 3 );
        add_filter( 'wp_update_comment_data', [ self::class, 'comment_before_edit' ], 10, 3 );
        add_action( 'edit_comment', [ self::class, 'comment_edited' ] );
        add_action( 'transition_comment_status', [ self::class, 'comment_status_changed' ], 10, 3 );
        add_action( 'deleted_comment', [ self::class, 'comment_deleted' ], 10, 2 );
        add_action( 'wp_update_nav_menu_item', [ self::class, 'menu_item_saved' ], 10, 3 );
        add_action( 'updated_option', [ self::class, 'widget_option_updated' ], 10, 3 );
        add_filter( 'insert_custom_user_meta', [ self::class, 'mark_new_user' ], 10, 3 );
        add_action( 'user_register', [ self::class, 'user_created' ] );
        add_action( 'profile_update', [ self::class, 'user_profile_updated' ], 10, 2 );
        add_action( 'password_reset', [ self::class, 'password_reset' ], 10, 1 );
        add_action( 'delete_user', [ self::class, 'mark_user_deleting' ], PHP_INT_MIN );
        add_action( 'remove_user_from_blog', [ self::class, 'mark_user_deleting' ], PHP_INT_MIN, 1 );
        add_action( 'deleted_user', [ self::class, 'user_deleted' ], 10, 3 );
        add_action( 'update_user_meta', [ self::class, 'roles_before' ], 10, 3 );
        add_action( 'delete_user_meta', [ self::class, 'roles_before_delete' ], 10, 4 );
        add_action( 'updated_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'added_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'deleted_user_meta', [ self::class, 'roles_after_delete' ], 10, 4 );
        add_action( 'wp_login', [ self::class, 'sign_in' ], 10, 2 );
        add_action( 'wp_login_failed', [ self::class, 'failed_sign_in' ] );
        add_action( 'wp_logout', [ self::class, 'sign_out' ] );
        add_action( 'update_option_' . Store::SETTINGS, [ self::class, 'plugin_settings_changed' ], 10, 2 );
        add_action( 'added_option', [ self::class, 'plugin_settings_added' ], 10, 2 );
        add_action( 'add_option_new_admin_email', [ self::class, 'site_email_request_added' ], 20, 2 );
        add_action( 'update_option_new_admin_email', [ self::class, 'site_email_request_updated' ], 20, 3 );
        add_action( 'update_option_admin_email', [ self::class, 'site_email_changed' ], 20, 3 );
        add_action( 'activated_plugin', [ self::class, 'plugin_activated' ] );
        add_action( 'deactivated_plugin', [ self::class, 'plugin_deactivated' ] );
        add_action( 'deleted_plugin', [ self::class, 'plugin_deleted' ], 10, 2 );
        add_action( 'switch_theme', [ self::class, 'theme_switched' ], 10, 3 );
        add_action( 'deleted_theme', [ self::class, 'theme_deleted' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ self::class, 'upgrade' ], 10, 2 );
        add_filter( 'upgrader_post_install', [ self::class, 'collect_update' ], PHP_INT_MAX, 2 );
        add_filter( 'upgrader_pre_install', [ self::class, 'remember_previous_version' ], 10, 2 );
        add_action( 'woocommerce_new_order', [ self::class, 'order_created' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed', [ self::class, 'order_status_changed' ], 10, 3 );
        add_action( 'woocommerce_order_refunded', [ self::class, 'order_refunded' ], 10, 2 );
        add_action( 'woocommerce_trash_order', [ self::class, 'order_trashed' ], 10, 1 );
        add_action( 'woocommerce_untrash_order', [ self::class, 'order_restored' ], 10, 1 );
        add_action( 'woocommerce_delete_order', [ self::class, 'order_deleted' ], 10, 1 );
        add_action( 'plugins_loaded', [ self::class, 'register_woocommerce_extended' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_acf' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_wpforms' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_seo_integrations' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_pmpro' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_termly' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_mainwp' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'register_infinitewp' ], 30 );
        add_action( 'plugins_loaded', [ self::class, 'cache_bbpress_post_types' ], 30 );
        add_action( 'bbp_close_forum', [ self::class, 'bbpress_forum_status_before' ], 10, 1 );
        add_action( 'bbp_open_forum', [ self::class, 'bbpress_forum_status_before' ], 10, 1 );
        add_action( 'bbp_closed_forum', [ self::class, 'bbpress_forum_closed' ], 10, 1 );
        add_action( 'bbp_opened_forum', [ self::class, 'bbpress_forum_opened' ], 10, 1 );
        add_action( 'bbp_categorize_forum', [ self::class, 'bbpress_forum_type_before' ], 10, 1 );
        add_action( 'bbp_normalize_forum', [ self::class, 'bbpress_forum_type_before' ], 10, 1 );
        add_action( 'bbp_categorized_forum', [ self::class, 'bbpress_forum_categorized' ], 10, 1 );
        add_action( 'bbp_normalized_forum', [ self::class, 'bbpress_forum_normalized' ], 10, 1 );
        add_action( 'bbp_unstick_topic', [ self::class, 'bbpress_topic_sticky_before' ], 10, 1 );
        add_action( 'bbp_stuck_topic', [ self::class, 'bbpress_topic_stuck' ], 10, 3 );
        add_action( 'bbp_unstuck_topic', [ self::class, 'bbpress_topic_unstuck' ], 10, 2 );
        add_action( 'redirection_redirect_updated', [ self::class, 'redirect_updated' ], 10, 1 );
        add_action( 'redirection_redirect_deleted', [ self::class, 'redirect_deleted' ], 10, 1 );
        add_action( 'redirection_redirect_enabled', [ self::class, 'redirect_enabled' ], 10, 1 );
        add_action( 'redirection_redirect_disabled', [ self::class, 'redirect_disabled' ], 10, 1 );
        foreach ( array_keys( self::OPTIONS ) as $option ) {
            add_action( 'update_option_' . $option, [ self::class, 'setting' ], 10, 3 );
        }
        if ( is_multisite() ) {
            add_action( 'wp_initialize_site', [ self::class, 'site_initialized' ], 20, 2 );
            add_action( 'wp_uninitialize_site', [ self::class, 'site_uninitializing' ], 5, 1 );
            add_action( 'wp_delete_site', [ self::class, 'site_deleted' ], 20, 1 );
            add_action( 'wp_update_site', [ self::class, 'site_updated' ], 20, 2 );
            foreach ( array_keys( self::NETWORK_OPTIONS ) as $option ) {
                add_action( 'add_site_option_' . $option, [ self::class, 'network_setting_added' ], 20, 3 );
                add_action( 'update_site_option_' . $option, [ self::class, 'network_setting_updated' ], 20, 4 );
            }
            add_action( 'add_site_option_new_admin_email', [ self::class, 'network_email_request_added' ], 20, 3 );
            add_action( 'update_site_option_new_admin_email', [ self::class, 'network_email_request_updated' ], 20, 4 );
            add_action( 'update_site_option_admin_email', [ self::class, 'network_email_changed' ], 20, 4 );
        }
        if ( Store::action_enabled( 'database_table_created' ) || Store::action_enabled( 'database_table_deleted' ) ) {
            add_filter( 'query', [ self::class, 'database_query' ], PHP_INT_MAX );
            add_action( 'shutdown', [ self::class, 'database_shutdown' ], PHP_INT_MAX );
        }
    }

    public static function register_woocommerce_extended(): void {
        if ( ! class_exists( 'WooCommerce' ) || ! Store::settings()['woocommerce'] ) {
            return;
        }
        add_action( 'woocommerce_new_product', [ self::class, 'woocommerce_item_created' ], 10, 2 );
        add_action( 'woocommerce_new_product_variation', [ self::class, 'woocommerce_item_created' ], 10, 2 );
        add_action( 'woocommerce_update_product', [ self::class, 'woocommerce_item_saved' ], 10, 2 );
        add_action( 'woocommerce_update_product_variation', [ self::class, 'woocommerce_item_saved' ], 10, 2 );
        add_action( 'woocommerce_new_coupon', [ self::class, 'woocommerce_item_created' ], 10, 2 );
        add_action( 'woocommerce_update_coupon', [ self::class, 'woocommerce_item_saved' ], 10, 2 );
        foreach ( array_keys( self::WOOCOMMERCE_SETTING_OPTIONS ) as $option ) {
            add_action( 'update_option_' . $option, [ self::class, 'woocommerce_setting_changed' ], 10, 3 );
            add_action( 'add_option_' . $option, [ self::class, 'woocommerce_setting_added' ], 10, 2 );
        }
        add_action( 'shutdown', [ self::class, 'flush_woocommerce_changes' ], PHP_INT_MAX - 1 );
    }

    public static function register_acf(): void {
        if ( ! function_exists( 'acf' ) || ! Store::settings()['acf'] ) {
            return;
        }
        add_filter( 'acf/update_value', [ self::class, 'acf_value_updated' ], 100, 4 );
        add_action( 'shutdown', [ self::class, 'flush_acf_changes' ], PHP_INT_MAX - 2 );
    }

    public static function register_wpforms(): void {
        if ( ! defined( 'WPFORMS_VERSION' ) || ! Store::settings()['wpforms'] ) {
            return;
        }
        add_action( 'wpforms_process_complete', [ self::class, 'wpforms_entry_submitted' ], 20, 4 );
    }

    public static function register_seo_integrations(): void {
        $settings = Store::settings();
        $enabled = ( $settings['yoast'] && self::seo_plugin_active( 'yoast' ) )
            || ( $settings['rank_math'] && self::seo_plugin_active( 'rank_math' ) );
        if ( ! $enabled ) {
            return;
        }
        add_action( 'updated_option', [ self::class, 'seo_option_updated' ], 20, 3 );
        add_action( 'shutdown', [ self::class, 'flush_seo_changes' ], PHP_INT_MAX - 3 );
    }

    public static function register_pmpro(): void {
        if ( ! Store::settings()['pmpro'] || ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            return;
        }

        add_action( 'pmpro_after_all_membership_level_changes', [ self::class, 'pmpro_membership_levels_changed' ], 10, 1 );
        add_action( 'pmpro_add_membership_level', [ self::class, 'pmpro_level_before_add' ], 10, 1 );
        add_action( 'pmpro_added_membership_level', [ self::class, 'pmpro_level_added' ], 10, 1 );
        add_action( 'pmpro_update_membership_level', [ self::class, 'pmpro_level_before_update' ], 10, 1 );
        add_action( 'pmpro_updated_membership_level', [ self::class, 'pmpro_level_updated' ], 10, 1 );
        add_action( 'pmpro_delete_membership_level', [ self::class, 'pmpro_level_deleted' ], 10, 1 );
    }

    public static function register_termly(): void {
        if ( ! defined( 'TERMLY_VERSION' ) || ! Store::settings()['termly'] ) {
            return;
        }

        add_action( 'update_option_termly_api_key', [ self::class, 'termly_api_key_updated' ], 10, 3 );
        add_action( 'added_option', [ self::class, 'termly_api_key_added' ], 10, 2 );
        add_action( 'delete_option', [ self::class, 'termly_api_key_before_delete' ], 10, 1 );
        add_action( 'deleted_option', [ self::class, 'termly_api_key_deleted' ], 10, 1 );
        add_action( 'update_option_termly_display_banner', [ self::class, 'termly_banner_updated' ], 10, 3 );
        add_action( 'update_option_termly_display_auto_blocker', [ self::class, 'termly_autoblocker_updated' ], 10, 3 );
        add_action( 'update_option_termly_site_scan', [ self::class, 'termly_site_scan_updated' ], 10, 3 );
    }

    private static function termly_connection_event( string $action, string $summary ): void {
        self::record( $action, 'setting', 'termly_connection', __( 'Termly connection', 'change-vigil-site-journal' ), $summary, [], 'termly_disconnected' === $action ? 'warning' : 'notice' );
    }

    public static function termly_api_key_added( string $option, mixed $value ): void {
        if ( 'termly_api_key' === $option && is_scalar( $value ) && '' !== (string) $value ) {
            self::termly_connection_event( 'termly_connected', __( 'Connected the site to Termly', 'change-vigil-site-journal' ) );
        }
    }

    public static function termly_api_key_updated( mixed $old_value, mixed $value, string $option ): void {
        if ( 'termly_api_key' !== $option || ! is_scalar( $old_value ) || ! is_scalar( $value ) ) {
            return;
        }
        $before = (string) $old_value;
        $after = (string) $value;
        if ( hash_equals( hash( 'sha256', $before ), hash( 'sha256', $after ) ) ) {
            return;
        }
        if ( '' === $after ) {
            self::termly_connection_event( 'termly_disconnected', __( 'Disconnected the site from Termly', 'change-vigil-site-journal' ) );
        } elseif ( '' === $before ) {
            self::termly_connection_event( 'termly_connected', __( 'Connected the site to Termly', 'change-vigil-site-journal' ) );
        } else {
            self::termly_connection_event( 'termly_connected', __( 'Changed the Termly connection', 'change-vigil-site-journal' ) );
        }
    }

    public static function termly_api_key_before_delete( string $option ): void {
        if ( 'termly_api_key' === $option ) {
            $value = get_option( $option, false );
            self::$termly_api_key_was_present_before_delete = is_scalar( $value ) && '' !== (string) $value;
        }
    }

    public static function termly_api_key_deleted( string $option ): void {
        if ( 'termly_api_key' === $option && self::$termly_api_key_was_present_before_delete ) {
            self::$termly_api_key_was_present_before_delete = false;
            self::termly_connection_event( 'termly_disconnected', __( 'Disconnected the site from Termly', 'change-vigil-site-journal' ) );
        }
    }

    private static function termly_toggle_value( mixed $value ): ?string {
        if ( is_bool( $value ) || is_numeric( $value ) ) {
            return (bool) $value ? __( 'On', 'change-vigil-site-journal' ) : __( 'Off', 'change-vigil-site-journal' );
        }
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( in_array( $value, [ '1', 'yes', 'on', 'true' ], true ) ) {
                return __( 'On', 'change-vigil-site-journal' );
            }
            if ( in_array( $value, [ '0', 'no', 'off', 'false', '' ], true ) ) {
                return __( 'Off', 'change-vigil-site-journal' );
            }
        }
        return null;
    }

    private static function termly_toggle_event( string $action, string $label, mixed $old, mixed $new ): void {
        $before = self::termly_toggle_value( $old );
        $after = self::termly_toggle_value( $new );
        if ( null === $before || null === $after || $before === $after ) {
            return;
        }
        self::record( $action, 'setting', 'termly_' . sanitize_key( $label ), 'Termly ' . $label,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Changed the status of the Termly %s', 'change-vigil-site-journal' ), strtolower( $label ) ),
            [ 'changes' => [ $label => [ $before, $after ] ] ], 'notice' );
    }

    public static function termly_banner_updated( mixed $old, mixed $new, string $option ): void {
        self::termly_toggle_event( 'termly_banner_changed', __( 'consent banner', 'change-vigil-site-journal' ), $old, $new );
    }

    public static function termly_autoblocker_updated( mixed $old, mixed $new, string $option ): void {
        self::termly_toggle_event( 'termly_autoblocker_changed', __( 'automatic blocker', 'change-vigil-site-journal' ), $old, $new );
    }

    private static function termly_site_scan_values( mixed $value ): array {
        if ( is_object( $value ) ) {
            $value = get_object_vars( $value );
        }
        if ( ! is_array( $value ) ) {
            return [ 'enabled' => null, 'frequency' => null, 'robots_txt' => null ];
        }
        $frequency = isset( $value['frequency'] ) && in_array( $value['frequency'], [ 'trimonthly', 'monthly', 'weekly' ], true ) ? $value['frequency'] : null;
        $labels = [ 'trimonthly' => __( 'Every three months', 'change-vigil-site-journal' ), 'monthly' => __( 'Monthly', 'change-vigil-site-journal' ), 'weekly' => __( 'Weekly', 'change-vigil-site-journal' ) ];
        return [
            'enabled' => self::termly_toggle_value( $value['enabled'] ?? false ),
            'frequency' => $frequency ? $labels[ $frequency ] : null,
            'robots_txt' => self::termly_toggle_value( $value['robots_txt'] ?? false ),
        ];
    }

    public static function termly_site_scan_updated( mixed $old, mixed $new, string $option ): void {
        $before = self::termly_site_scan_values( $old );
        $after = self::termly_site_scan_values( $new );
        $changes = [];
        foreach ( [ 'enabled' => __( 'Automatic scans', 'change-vigil-site-journal' ), 'frequency' => __( 'Scan frequency', 'change-vigil-site-journal' ) ] as $key => $label ) {
            if ( null !== $before[ $key ] && null !== $after[ $key ] && $before[ $key ] !== $after[ $key ] ) {
                $changes[ $label ] = [ $before[ $key ], $after[ $key ] ];
            }
        }
        if ( $changes ) {
            self::record( 'termly_scan_schedule_changed', 'setting', 'termly_scan_schedule', __( 'Termly scan schedule', 'change-vigil-site-journal' ),
                __( 'Changed Termly automatic scan settings', 'change-vigil-site-journal' ), [ 'changes' => $changes ], 'notice' );
        }
        if ( null !== $before['robots_txt'] && null !== $after['robots_txt'] && $before['robots_txt'] !== $after['robots_txt'] ) {
            self::termly_toggle_event( 'termly_robot_allowlist_changed', __( 'scanner allow list', 'change-vigil-site-journal' ), $before['robots_txt'], $after['robots_txt'] );
        }
    }

    public static function register_mainwp(): void {
        if ( ! Store::settings()['mainwp'] ) {
            return;
        }

        if ( defined( 'MAINWP_PLUGIN_FILE' ) ) {
            add_action( 'mainwp_added_new_site', [ self::class, 'mainwp_site_added' ], 10, 2 );
            add_action( 'mainwp_updated_site', [ self::class, 'mainwp_site_updated' ], 10, 2 );
            add_action( 'mainwp_site_deleted', [ self::class, 'mainwp_site_deleted' ], 10, 1 );
            add_action( 'mainwp_site_suspended', [ self::class, 'mainwp_site_suspended' ], 10, 2 );
            add_action( 'mainwp_site_sync', [ self::class, 'mainwp_site_sync' ], 10, 5 );
            add_action( 'mainwp_client_updated', [ self::class, 'mainwp_client_updated' ], 10, 2 );
            add_action( 'mainwp_client_deleted', [ self::class, 'mainwp_client_deleted' ], 10, 2 );
            add_action( 'mainwp_client_suspend', [ self::class, 'mainwp_client_suspend' ], 10, 2 );
            add_filter( 'mainwp_sync_others_data', [ self::class, 'mainwp_sync_request' ], 10, 2 );
        }

        if ( defined( 'MAINWP_CHILD_FILE' ) ) {
            add_filter( 'mainwp_site_sync_others_data', [ self::class, 'mainwp_sync_export' ], 10, 2 );
        }
    }

    public static function register_infinitewp(): void {
        if ( ! Store::settings()['infinitewp'] || ! defined( 'IWP_MMB_CLIENT_VERSION' ) ) {
            return;
        }

        add_action( 'added_post_meta', [ self::class, 'infinitewp_activity_added' ], 20, 4 );
    }

    public static function infinitewp_activity_added( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
        if ( ! Store::settings()['infinitewp'] || ! defined( 'IWP_MMB_CLIENT_VERSION' )
            || 'iwp_log_details' !== $meta_key || 'iwp_log' !== get_post_type( $post_id ) ) {
            return;
        }

        $type   = sanitize_key( (string) get_post_meta( $post_id, 'iwp_log_type', true ) );
        $action = sanitize_key( (string) get_post_meta( $post_id, 'iwp_log_action', true ) );
        $group  = sanitize_key( (string) get_post_meta( $post_id, 'iwp_log_actions', true ) );
        if ( ! in_array( $type, [ 'backup', 'schedulebackup' ], true )
            || ! in_array( $action, [ 'now', 'schedule', 'multicallnow' ], true )
            || 'backups' !== $group ) {
            return;
        }

        $post = get_post( $post_id );
        $user = $post instanceof \WP_Post ? get_userdata( (int) $post->post_author ) : false;
        $actor = $user instanceof \WP_User
            ? [ 'user', (int) $user->ID, self::text( $user->user_login, 191 ) ]
            : [ 'unknown', 0, '' ];
        $scope = is_array( $meta_value ) && isset( $meta_value['what'] ) && is_string( $meta_value['what'] )
            ? sanitize_key( $meta_value['what'] )
            : '';
        $scope_labels = [
            'full'  => __( 'Files and database', 'change-vigil-site-journal' ),
            'db'    => __( 'Database only', 'change-vigil-site-journal' ),
            'files' => __( 'Files only', 'change-vigil-site-journal' ),
        ];
        $details = isset( $scope_labels[ $scope ] )
            ? [ 'changes' => [ __( 'Backup scope', 'change-vigil-site-journal' ) => [ __( 'Not recorded', 'change-vigil-site-journal' ), $scope_labels[ $scope ] ] ] ]
            : [];

        self::record(
            'infinitewp_backup_completed',
            'backup',
            (string) $post_id,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'InfiniteWP backup #%d', 'change-vigil-site-journal' ), $post_id ),
            __( 'Completed an InfiniteWP backup', 'change-vigil-site-journal' ),
            $details,
            'notice',
            $actor,
            'infinitewp'
        );
    }

    private static function mainwp_cursor_option( int $site_id ): string {
        return 'cvsj_mainwp_cursor_' . absint( $site_id );
    }

    public static function mainwp_sync_request( mixed $other_data, mixed $site ): array {
        $other_data = is_array( $other_data ) ? $other_data : [];
        $site_id = self::mainwp_entity_id( $site, [ 'id', 'site_id', 'ID' ] );
        if ( $site_id < 1 ) {
            return $other_data;
        }
        $other_data['activity_log_for_admins'] = [
            'schema_version' => 1,
            'after_id' => absint( get_option( self::mainwp_cursor_option( $site_id ), 0 ) ),
            'limit' => 50,
        ];
        return $other_data;
    }

    public static function mainwp_sync_export( mixed $information, mixed $other_data ): array {
        $information = is_array( $information ) ? $information : [];
        $request = is_array( $other_data ) ? ( $other_data['activity_log_for_admins'] ?? null ) : null;
        if ( ! is_array( $request ) || 1 !== (int) ( $request['schema_version'] ?? 0 ) || ! Store::settings()['mainwp'] ) {
            return $information;
        }
        $after_id = absint( $request['after_id'] ?? 0 );
        $limit = min( 50, max( 1, absint( $request['limit'] ?? 50 ) ) );
        $events = Store::mainwp_export_batch( $after_id, $limit );
        if ( is_wp_error( $events ) ) {
            return $information;
        }
        $information['activity_log_for_admins'] = [ 'schema_version' => 1, 'events' => $events ];
        return $information;
    }

    private static function import_mainwp_events( int $site_id, mixed $information ): void {
        if ( $site_id < 1 || ! is_array( $information ) || 1 !== (int) ( $information['schema_version'] ?? 0 )
            || ! is_array( $information['events'] ?? null ) || count( $information['events'] ) > 50 ) {
            return;
        }
        $last_id = 0;
        foreach ( $information['events'] as $event ) {
            if ( ! is_array( $event ) || ! Store::import_mainwp_event( $site_id, $event ) ) {
                return;
            }
            $last_id = max( $last_id, absint( $event['id'] ?? 0 ) );
        }
        if ( $last_id > 0 ) {
            update_option( self::mainwp_cursor_option( $site_id ), $last_id, false );
        }
    }

    private static function mainwp_entity_id( mixed $entity, array $keys ): int {
        if ( is_numeric( $entity ) ) {
            return absint( $entity );
        }
        foreach ( $keys as $key ) {
            if ( is_object( $entity ) && isset( $entity->$key ) && is_numeric( $entity->$key ) ) {
                return absint( $entity->$key );
            }
            if ( is_array( $entity ) && isset( $entity[ $key ] ) && is_numeric( $entity[ $key ] ) ) {
                return absint( $entity[ $key ] );
            }
        }
        return 0;
    }

    private static function mainwp_site_event( string $action, mixed $site_id, string $format, string $severity = 'notice', array $details = [] ): void {
        $id = self::mainwp_entity_id( $site_id, [ 'id', 'site_id', 'ID' ] );
        if ( $id < 1 ) {
            return;
        }
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'MainWP child site #%d', 'change-vigil-site-journal' ), $id );
        self::record( $action, 'mainwp_site', $id, $name, sprintf( $format, $id ), $details, $severity );
    }

    private static function mainwp_client_event( string $action, mixed $client, string $format, string $severity = 'notice', array $details = [] ): void {
        $id = self::mainwp_entity_id( $client, [ 'client_id', 'id', 'ID' ] );
        if ( $id < 1 ) {
            return;
        }
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'MainWP client #%d', 'change-vigil-site-journal' ), $id );
        self::record( $action, 'mainwp_client', $id, $name, sprintf( $format, $id ), $details, $severity );
    }

    public static function mainwp_site_added( mixed $site_id, mixed $site ): void {
        // translators: %d is the ID of the affected item.
        self::mainwp_site_event( 'mainwp_site_added', $site_id, __( 'Added MainWP child site #%d', 'change-vigil-site-journal' ) );
    }

    public static function mainwp_site_updated( mixed $site_id, mixed $data ): void {
        // translators: %d is the ID of the affected item.
        self::mainwp_site_event( 'mainwp_site_updated', $site_id, __( 'Changed MainWP child site #%d', 'change-vigil-site-journal' ) );
    }

    public static function mainwp_site_deleted( mixed $site ): void {
        // translators: %d is the ID of the affected item.
        self::mainwp_site_event( 'mainwp_site_deleted', $site, __( 'Removed MainWP child site #%d', 'change-vigil-site-journal' ), 'warning' );
    }

    public static function mainwp_site_suspended( mixed $site, mixed $suspended ): void {
        $is_suspended = in_array( $suspended, [ true, 1, '1' ], true );
        $action = $is_suspended ? 'mainwp_site_suspended' : 'mainwp_site_resumed';
        // translators: %d is the ID of the affected item.
        $summary = $is_suspended ? __( 'Suspended MainWP child site #%d', 'change-vigil-site-journal' ) : __( 'Resumed MainWP child site #%d', 'change-vigil-site-journal' );
        self::mainwp_site_event( $action, $site, $summary, $is_suspended ? 'warning' : 'notice' );
    }

    public static function mainwp_site_sync( mixed $site, mixed $information, mixed $success, mixed $error, mixed $post_data ): void {
        $succeeded = in_array( $success, [ true, 1, '1' ], true );
        if ( $succeeded && is_array( $information ) ) {
            $site_id = self::mainwp_entity_id( $site, [ 'id', 'site_id', 'ID' ] );
            self::import_mainwp_events( $site_id, $information['activity_log_for_admins'] ?? null );
        }
        $action = $succeeded ? 'mainwp_site_sync_succeeded' : 'mainwp_site_sync_failed';
        // translators: %d is the ID of the affected item.
        $summary = $succeeded ? __( 'Synchronized MainWP child site #%d', 'change-vigil-site-journal' ) : __( 'MainWP child site #%d synchronization failed', 'change-vigil-site-journal' );
        $details = [ 'changes' => [ __( 'Synchronization', 'change-vigil-site-journal' ) => [ __( 'Not recorded', 'change-vigil-site-journal' ), $succeeded ? __( 'Completed', 'change-vigil-site-journal' ) : __( 'Failed', 'change-vigil-site-journal' ) ] ] ];
        self::mainwp_site_event( $action, $site, $summary, $succeeded ? 'info' : 'warning', $details );
    }

    public static function mainwp_client_updated( mixed $client, mixed $created ): void {
        $is_created = in_array( $created, [ true, 1, '1' ], true );
        $action = $is_created ? 'mainwp_client_created' : 'mainwp_client_updated';
        // translators: %d is the ID of the affected item.
        $summary = $is_created ? __( 'Created MainWP client #%d', 'change-vigil-site-journal' ) : __( 'Changed MainWP client #%d', 'change-vigil-site-journal' );
        self::mainwp_client_event( $action, $client, $summary );
    }

    public static function mainwp_client_deleted( mixed $client, mixed $snapshot_info = null ): void {
        // translators: %d is the ID of the affected item.
        self::mainwp_client_event( 'mainwp_client_deleted', $client, __( 'Deleted MainWP client #%d', 'change-vigil-site-journal' ), 'warning' );
    }

    public static function mainwp_client_suspend( mixed $client, mixed $suspended ): void {
        $is_suspended = in_array( $suspended, [ true, 1, '1' ], true );
        $action = $is_suspended ? 'mainwp_client_suspended' : 'mainwp_client_resumed';
        // translators: %d is the ID of the affected item.
        $summary = $is_suspended ? __( 'Suspended MainWP client #%d', 'change-vigil-site-journal' ) : __( 'Resumed MainWP client #%d', 'change-vigil-site-journal' );
        self::mainwp_client_event( $action, $client, $summary, $is_suspended ? 'warning' : 'notice' );
    }

    private static function pmpro_level_id( mixed $level ): int {
        if ( is_object( $level ) ) {
            return absint( $level->id ?? $level->ID ?? 0 );
        }
        if ( is_array( $level ) ) {
            return absint( $level['id'] ?? $level['ID'] ?? 0 );
        }
        return 0;
    }

    private static function pmpro_level_ids( mixed $levels ): ?array {
        if ( ! is_array( $levels ) ) {
            return null;
        }

        $ids = [];
        foreach ( $levels as $level ) {
            $id = self::pmpro_level_id( $level );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids, SORT_NUMERIC );
        return $ids;
    }

    public static function pmpro_membership_levels_changed( mixed $old_user_levels ): void {
        if ( ! Store::settings()['pmpro'] || ! function_exists( 'pmpro_getMembershipLevelsForUser' ) || ! is_array( $old_user_levels ) ) {
            return;
        }

        foreach ( $old_user_levels as $user_id => $old_levels ) {
            $user_id = absint( $user_id );
            if ( $user_id < 1 ) {
                continue;
            }

            $before = self::pmpro_level_ids( $old_levels );
            $current_levels = pmpro_getMembershipLevelsForUser( $user_id );
            $after = self::pmpro_level_ids( $current_levels );
            if ( null === $before || null === $after || $before === $after ) {
                continue;
            }

            $before_value = $before ? implode( ', ', $before ) : __( 'None', 'change-vigil-site-journal' );
            $after_value = $after ? implode( ', ', $after ) : __( 'None', 'change-vigil-site-journal' );
            self::record(
                'membership_changed',
                'user',
                $user_id,
                // translators: %d is the ID of the affected item.
                sprintf( __( 'Account #%d', 'change-vigil-site-journal' ), $user_id ),
                // translators: %d is the ID of the affected item.
                sprintf( __( 'Changed Paid Memberships Pro access for account #%d', 'change-vigil-site-journal' ), $user_id ),
                [ 'changes' => [ 'Membership level IDs' => [ (string) $before_value, (string) $after_value ] ] ],
                'warning'
            );
        }
    }

    private static function pmpro_level_database_snapshot( int $id ): ?string {
        global $wpdb;
        if ( $id < 1 || empty( $wpdb->pmpro_membership_levels ) ) {
            return null;
        }

        $table = $wpdb->pmpro_membership_levels;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, name, description, confirmation, initial_payment, billing_amount, cycle_number, cycle_period, billing_limit, trial_amount, trial_limit, expiration_number, expiration_period, allow_signups FROM %i WHERE id = %d',
            $table,
            $id
        ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return null;
        }

        $categories = [];
        if ( ! empty( $wpdb->pmpro_memberships_categories ) ) {
            $categories = $wpdb->get_col( $wpdb->prepare(
                'SELECT category_id FROM %i WHERE membership_id = %d ORDER BY category_id',
                $wpdb->pmpro_memberships_categories,
                $id
            ) );
            if ( ! is_array( $categories ) ) {
                return null;
            }
        }
        $row['categories'] = array_map( 'absint', $categories );
        $encoded = wp_json_encode( $row );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : null;
    }

    public static function pmpro_level_before_add( mixed $level ): void {
        $id = self::pmpro_level_id( $level );
        if ( $id > 0 ) {
            self::$pmpro_level_before[ $id ] = null;
        }
    }

    public static function pmpro_level_added( mixed $level ): void {
        $id = self::pmpro_level_id( $level );
        unset( self::$pmpro_level_before[ $id ] );
        if ( $id < 1 ) {
            return;
        }

        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Membership level #%d', 'change-vigil-site-journal' ), $id );
        self::record( 'membership_level_created', 'pmpro_level', $id, $name,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'Created Paid Memberships Pro level #%d', 'change-vigil-site-journal' ), $id ), [], 'notice' );
    }

    public static function pmpro_level_before_update( mixed $level ): void {
        $id = self::pmpro_level_id( $level );
        $snapshot = self::pmpro_level_database_snapshot( $id );
        if ( $id > 0 && null !== $snapshot ) {
            self::$pmpro_level_before[ $id ] = $snapshot;
        }
    }

    public static function pmpro_level_updated( mixed $level ): void {
        $id = self::pmpro_level_id( $level );
        $before = self::$pmpro_level_before[ $id ] ?? null;
        unset( self::$pmpro_level_before[ $id ] );
        $after = self::pmpro_level_database_snapshot( $id );
        if ( $id < 1 || null === $before || null === $after || hash_equals( $before, $after ) ) {
            return;
        }

        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Membership level #%d', 'change-vigil-site-journal' ), $id );
        self::record( 'membership_level_updated', 'pmpro_level', $id, $name,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'Changed Paid Memberships Pro level #%d', 'change-vigil-site-journal' ), $id ),
            [ 'changes' => [ 'Level configuration' => [ __( 'Not recorded', 'change-vigil-site-journal' ), __( 'Changed', 'change-vigil-site-journal' ) ] ] ], 'notice' );
    }

    public static function pmpro_level_deleted( mixed $level_id ): void {
        $id = absint( $level_id );
        if ( $id < 1 ) {
            return;
        }

        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Membership level #%d', 'change-vigil-site-journal' ), $id );
        self::record( 'membership_level_deleted', 'pmpro_level', $id, $name,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'Deleted Paid Memberships Pro level #%d', 'change-vigil-site-journal' ), $id ), [], 'warning' );
    }

    private static function seo_plugin_active( string $provider ): bool {
        return 'yoast' === $provider
            ? class_exists( 'WPSEO_Meta' ) || defined( 'WPSEO_VERSION' )
            : ( 'rank_math' === $provider && class_exists( 'RankMath' ) );
    }

    private static function seo_meta_field( string $meta_key ): ?array {
        if ( str_starts_with( $meta_key, '_yoast_wpseo_' ) ) {
            $provider = 'yoast';
        } elseif ( str_starts_with( $meta_key, 'rank_math_' ) ) {
            $provider = 'rank_math';
        } else {
            return null;
        }
        $label = self::SEO_META_FIELDS[ $provider ][ $meta_key ] ?? null;
        return is_string( $label ) && Store::settings()[ $provider ] && self::seo_plugin_active( $provider )
            ? [ 'provider' => $provider, 'label' => $label ]
            : null;
    }

    private static function seo_setting_field( string $option ): ?array {
        foreach ( self::SEO_SETTING_OPTIONS as $provider => $options ) {
            if ( isset( $options[ $option ] ) && Store::settings()[ $provider ] && self::seo_plugin_active( $provider ) ) {
                return [ 'provider' => $provider, 'label' => $options[ $option ] ];
            }
        }
        return null;
    }

    public static function seo_option_updated( string $option, mixed $old_value, mixed $new_value ): void {
        $field = self::seo_setting_field( $option );
        if ( ! $field ) {
            return;
        }
        $provider = $field['provider'];
        self::$seo_settings_changes[ $provider ][ $option ] = $field['label'];
    }

    private static function seo_value_fingerprint( mixed $value ): ?string {
        return self::acf_value_fingerprint( $value );
    }

    private static function seo_supported_post( int $post_id ): ?\WP_Post {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || isset( self::$deleting_posts[ $post_id ] ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return null;
        }
        $type = get_post_type_object( $post->post_type );
        return $type instanceof \WP_Post_Type && $type->public && $type->show_ui ? $post : null;
    }

    private static function queue_seo_meta_change( string $provider, int $post_id, string $meta_key, mixed $before, mixed $after ): void {
        $field = self::seo_meta_field( $meta_key );
        $post = self::seo_supported_post( $post_id );
        if ( ! $field || $field['provider'] !== $provider || ! $post ) {
            return;
        }
        $before_hash = self::seo_value_fingerprint( $before );
        $after_hash = self::seo_value_fingerprint( $after );
        if ( null === $before_hash || null === $after_hash || $before_hash === $after_hash ) {
            return;
        }
        $existing = self::$seo_changes[ $provider ][ $post_id ][ $meta_key ] ?? null;
        $initial_hash = is_array( $existing ) ? $existing['before'] : $before_hash;
        if ( $initial_hash === $after_hash ) {
            unset( self::$seo_changes[ $provider ][ $post_id ][ $meta_key ] );
            if ( empty( self::$seo_changes[ $provider ][ $post_id ] ) ) {
                unset( self::$seo_changes[ $provider ][ $post_id ] );
            }
            return;
        }
        self::$seo_changes[ $provider ][ $post_id ][ $meta_key ] = [
            'label' => $field['label'],
            'before' => $initial_hash,
            'after' => $after_hash,
        ];
    }

    public static function flush_seo_changes(): void {
        $not_recorded = __( 'Not recorded', 'change-vigil-site-journal' );
        $changed = __( 'Changed', 'change-vigil-site-journal' );
        foreach ( self::$seo_changes as $provider => $posts ) {
            if ( ! Store::settings()[ $provider ] || ! self::seo_plugin_active( $provider ) ) {
                continue;
            }
            $action = 'yoast' === $provider ? 'yoast_seo_meta_changed' : 'rank_math_seo_meta_changed';
            $plugin = 'yoast' === $provider ? 'Yoast SEO' : 'Rank Math';
            foreach ( $posts as $post_id => $fields ) {
                $post = self::seo_supported_post( (int) $post_id );
                if ( ! $post || ! $fields ) {
                    continue;
                }
                $changes = [];
                foreach ( $fields as $field ) {
                    $label = (string) $field['label'];
                    $base_label = $label;
                    $suffix = 2;
                    while ( isset( $changes[ $label ] ) ) {
                        $label = self::truncate( $base_label, 88 ) . ' (' . $suffix++ . ')';
                    }
                    $changes[ $label ] = [ $not_recorded, $changed ];
                }
                if ( ! $changes ) {
                    continue;
                }
                $name = self::redacted_text( $post->post_title, 220 );
                if ( '' === $name ) {
                    // translators: %1$s is the type of the affected item; %2$d is the ID of the affected item.
                    $name = sprintf( __( '%1$s #%2$d', 'change-vigil-site-journal' ), self::post_type_label( $post->post_type ), $post->ID );
                }
                $count = count( $changes );
                $summary = sprintf(
                    // translators: %1$d is the number of changed fields; %2$s is the field label; %3$s is the content type; %4$s is the content item title.
                    _n( 'Changed %1$d %2$s field on %3$s “%4$s”', 'Changed %1$d %2$s fields on %3$s “%4$s”', $count, 'change-vigil-site-journal' ),
                    $count,
                    $plugin,
                    self::post_type_label( $post->post_type ),
                    $name
                );
                self::record( $action, $post->post_type, (int) $post_id, $name, $summary, [ 'changes' => $changes ], 'notice' );
            }
        }
        self::$seo_changes = [];

        foreach ( self::$seo_settings_changes as $provider => $options ) {
            if ( ! Store::settings()[ $provider ] || ! self::seo_plugin_active( $provider ) || ! $options ) {
                continue;
            }
            $action = 'yoast' === $provider ? 'yoast_seo_settings_changed' : 'rank_math_seo_settings_changed';
            $plugin = 'yoast' === $provider ? 'Yoast SEO' : 'Rank Math';
            $changes = [];
            foreach ( array_values( array_unique( $options ) ) as $label ) {
                $changes[ $label ] = [ $not_recorded, $changed ];
            }
            self::record( $action, 'setting', $provider . '-seo', $plugin,
                // translators: %s is the plugin or component name.
                sprintf( __( 'Changed %s settings', 'change-vigil-site-journal' ), $plugin ), [ 'changes' => $changes ], 'notice' );
        }
        self::$seo_settings_changes = [];
    }

    private static function wpforms_post_type( string $post_type ): bool {
        return 'wpforms' === $post_type && defined( 'WPFORMS_VERSION' );
    }

    private static function wpforms_form_name( \WP_Post $post ): string {
        $title = self::redacted_text( $post->post_title, 220 );
        // translators: %d is the ID of the affected item.
        return $title ?: sprintf( __( 'Form #%d', 'change-vigil-site-journal' ), $post->ID );
    }

    private static function wpforms_actor(): array {
        if ( is_user_logged_in() || wp_doing_cron() || ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) ) ) {
            return self::actor();
        }
        return [ 'anonymous', 0, __( 'Anonymous visitor', 'change-vigil-site-journal' ) ];
    }

    private static function wpforms_form_post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( ! Store::settings()['wpforms']
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }
        $name = self::wpforms_form_name( $post );
        $created = ! $update || ! $before || 'auto-draft' === $before->post_status;
        if ( $created ) {
            self::record( 'wpforms_form_created', 'wpforms_form', $id, $name,
                // translators: %s is the form name.
                sprintf( __( 'Created WPForms form “%s”', 'change-vigil-site-journal' ), $name ),
                [ 'changes' => [ 'Title' => [ '', $name ], 'Status' => [ '', self::text( $post->post_status, 80 ) ] ] ], 'notice' );
            return;
        }
        if ( ! $before ) {
            return;
        }
        $changes = [];
        if ( $before->post_title !== $post->post_title ) {
            $changes['Title'] = [ self::redacted_text( $before->post_title, 220 ), self::redacted_text( $post->post_title, 220 ) ];
        }
        if ( $before->post_status !== $post->post_status ) {
            $changes['Status'] = [ self::text( $before->post_status, 80 ), self::text( $post->post_status, 80 ) ];
        }
        if ( $before->post_author !== $post->post_author ) {
            $changes['Author'] = [ self::post_field_value( 'post_author', (string) $before->post_author ), self::post_field_value( 'post_author', (string) $post->post_author ) ];
        }
        if ( $before->post_content !== $post->post_content || $before->post_excerpt !== $post->post_excerpt ) {
            $changes['Form definition'] = [ __( 'Not recorded', 'change-vigil-site-journal' ), __( 'Changed', 'change-vigil-site-journal' ) ];
        }
        if ( ! $changes ) {
            return;
        }
        $action = 'wpforms_form_edited';
        $severity = 'info';
        if ( 'trash' === $post->post_status && 'trash' !== $before->post_status ) {
            $action = 'wpforms_form_trashed';
            $severity = 'warning';
        } elseif ( 'trash' === $before->post_status && 'trash' !== $post->post_status ) {
            $action = 'wpforms_form_restored';
            $severity = 'notice';
        } elseif ( isset( $changes['Status'] ) ) {
            $action = 'wpforms_form_status_changed';
            $severity = 'notice';
        }
        if ( isset( $changes['Title'] ) && '' !== $changes['Title'][0] ) {
            // translators: %1$s is the previous item name; %2$s is the new item name.
            $summary = sprintf( __( 'Renamed WPForms form from “%1$s” to “%2$s”', 'change-vigil-site-journal' ), $changes['Title'][0], $changes['Title'][1] );
        } elseif ( 'wpforms_form_trashed' === $action ) {
            // translators: %s is the form name.
            $summary = sprintf( __( 'Moved WPForms form “%s” to Trash', 'change-vigil-site-journal' ), $name );
        } elseif ( 'wpforms_form_restored' === $action ) {
            // translators: %s is the form name.
            $summary = sprintf( __( 'Restored WPForms form “%s” from Trash', 'change-vigil-site-journal' ), $name );
        } elseif ( 'wpforms_form_status_changed' === $action ) {
            // translators: %s is the form name.
            $summary = sprintf( __( 'Changed the status of WPForms form “%s”', 'change-vigil-site-journal' ), $name );
        } else {
            // translators: %s is the form name.
            $summary = sprintf( __( 'Changed WPForms form “%s”', 'change-vigil-site-journal' ), $name );
        }
        self::record( $action, 'wpforms_form', $id, $name, $summary, [ 'changes' => $changes ], $severity );
    }

    public static function wpforms_entry_submitted( mixed $fields, mixed $entry, mixed $form_data, mixed $entry_id ): void {
        if ( ! Store::settings()['wpforms'] || ! is_array( $form_data )
            || ( ! is_int( $entry_id ) && ( ! is_string( $entry_id ) || ! ctype_digit( $entry_id ) ) )
            || absint( $entry_id ) < 1 ) {
            return;
        }
        $form_id_value = $form_data['id'] ?? 0;
        if ( ! is_int( $form_id_value ) && ( ! is_string( $form_id_value ) || ! ctype_digit( $form_id_value ) ) ) {
            return;
        }
        $form_id = absint( $form_id_value );
        $form = get_post( $form_id );
        if ( ! $form instanceof \WP_Post || ! self::wpforms_post_type( $form->post_type ) ) {
            return;
        }
        $name = self::wpforms_form_name( $form );
        self::record( 'wpforms_entry_submitted', 'wpforms_form', $form_id, $name,
            // translators: %s is the form name.
            sprintf( __( 'Received a submission for WPForms form “%s”', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ 'Submission' => [ '', __( 'Received', 'change-vigil-site-journal' ) ] ] ], 'info', self::wpforms_actor() );
    }

    public static function acf_value_updated( mixed $value, mixed $post_id, array $field, mixed $original ): mixed {
        if ( ! Store::settings()['acf'] || ( ! is_int( $post_id ) && ( ! is_string( $post_id ) || ! ctype_digit( $post_id ) ) ) ) {
            return $value;
        }

        $post_id = (int) $post_id;
        $post = get_post( $post_id );
        if ( $post_id < 1 || ! $post instanceof \WP_Post
            || ( ! self::supports_post_type( $post->post_type ) && ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) ) {
            return $value;
        }

        $field_name = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
        if ( '' === $field_name ) {
            return $value;
        }

        $previous = metadata_exists( 'post', $post_id, $field_name )
            ? get_post_meta( $post_id, $field_name, true )
            : null;
        $previous_hash = self::acf_value_fingerprint( $previous );
        $next_hash = self::acf_value_fingerprint( $value );
        if ( null === $previous_hash || null === $next_hash || $previous_hash === $next_hash ) {
            return $value;
        }

        $field_key = isset( $field['key'] ) && is_string( $field['key'] ) ? $field['key'] : $field_name;
        $field_id = hash( 'sha256', $field_key . "\0" . $field_name );
        $pending = self::$acf_changes[ $post_id ] ?? [ 'fields' => [] ];
        $existing = $pending['fields'][ $field_id ] ?? null;
        if ( ! is_array( $existing ) && count( $pending['fields'] ) >= self::ACF_CHANGE_LIMIT ) {
            return $value;
        }
        $before = is_array( $existing ) ? (string) $existing['before'] : $previous_hash;
        if ( $before === $next_hash ) {
            unset( $pending['fields'][ $field_id ] );
        } else {
            $pending['fields'][ $field_id ] = [
                'label' => self::acf_field_label( $field ),
                'before' => $before,
                'after' => $next_hash,
            ];
        }
        if ( $pending['fields'] ) {
            self::$acf_changes[ $post_id ] = $pending;
        } else {
            unset( self::$acf_changes[ $post_id ] );
        }

        return $value;
    }

    private static function acf_value_fingerprint( mixed $value ): ?string {
        $remaining = 5000;
        $normalized = self::normalize_acf_value( $value, 0, $remaining );
        if ( null === $normalized ) {
            return null;
        }
        $encoded = wp_json_encode( $normalized );
        return is_string( $encoded ) ? hash( 'sha256', $encoded ) : null;
    }

    private static function normalize_acf_value( mixed $value, int $depth, int &$remaining ): mixed {
        if ( $depth > 8 || --$remaining < 0 ) {
            return null;
        }
        if ( null === $value || is_scalar( $value ) ) {
            return [ get_debug_type( $value ), $value ];
        }
        if ( ! is_array( $value ) ) {
            return null;
        }

        $normalized = [];
        foreach ( $value as $key => $child ) {
            if ( ! is_int( $key ) && ! is_string( $key ) ) {
                return null;
            }
            $child_value = self::normalize_acf_value( $child, $depth + 1, $remaining );
            if ( null === $child_value ) {
                return null;
            }
            $normalized[] = [ get_debug_type( $key ), $key, $child_value ];
        }
        return [ 'array', $normalized ];
    }

    private static function acf_field_label( array $field ): string {
        $label = isset( $field['label'] ) && is_string( $field['label'] ) ? $field['label'] : '';
        $name = isset( $field['name'] ) && is_string( $field['name'] ) ? $field['name'] : '';
        $type = isset( $field['type'] ) && is_string( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
        $sensitive = '/(?:e[\s_-]?mail|password|passcode|secret|token|api[\s_-]?key|contact|phone|mobile|address|street|postal|postcode|zip|first[\s_-]?name|last[\s_-]?name|full[\s_-]?name|social[\s_-]?security|ssn|credit[\s_-]?card|bank|account|passport|national[\s_-]?insurance|pps|tax[\s_-]?(?:id|number)|health|medical|diagnosis|patient|religion|ethnicity|biometric|salary|(?:^|[^a-z0-9])(?:name|dob|birth)(?:$|[^a-z0-9]))/i';
        if ( in_array( $type, [ 'email', 'password' ], true ) || preg_match( $sensitive, $label . ' ' . $name ) ) {
            return __( 'Sensitive custom field', 'change-vigil-site-journal' );
        }

        $label = self::redacted_text( $label, 96 );
        return '' !== $label ? $label : __( 'Custom field', 'change-vigil-site-journal' );
    }

    public static function flush_acf_changes(): void {
        if ( ! Store::settings()['acf'] ) {
            self::$acf_changes = [];
            return;
        }

        $not_recorded = __( 'Not recorded', 'change-vigil-site-journal' );
        $changed = __( 'Changed', 'change-vigil-site-journal' );
        foreach ( self::$acf_changes as $post_id => $pending ) {
            $post = get_post( (int) $post_id );
            if ( ! $post instanceof \WP_Post || empty( $pending['fields'] ) ) {
                continue;
            }

            $changes = [];
            foreach ( $pending['fields'] as $field ) {
                $label = (string) $field['label'];
                $base_label = $label;
                $suffix = 2;
                while ( isset( $changes[ $label ] ) ) {
                    $label = self::truncate( $base_label, 88 ) . ' (' . $suffix++ . ')';
                }
                $changes[ $label ] = [ $not_recorded, $changed ];
            }
            if ( ! $changes ) {
                continue;
            }

            $name = self::text( get_the_title( $post ), 255 );
            if ( '' === $name ) {
                // translators: %1$s is the type of the affected item; %2$d is the ID of the affected item.
                $name = sprintf( __( '%1$s #%2$d', 'change-vigil-site-journal' ), self::post_type_label( $post->post_type ), $post->ID );
            }
            $kind = $post->post_type;
            $summary = sprintf(
                // translators: %1$d is the number of changed fields; %2$s is the content type; %3$s is the content item title.
                _n( 'Changed %1$d custom field on %2$s “%3$s”', 'Changed %1$d custom fields on %2$s “%3$s”', count( $changes ), 'change-vigil-site-journal' ),
                count( $changes ),
                self::post_type_label( $post->post_type ),
                $name
            );
            self::record( 'acf_fields_changed', $kind, $post->ID, $name, $summary, [ 'changes' => $changes ], 'notice' );
        }
        self::$acf_changes = [];
    }

    public static function database_query( string $query ): string {
        if ( self::$database_table_probe ) {
            return $query;
        }
        $trimmed = ltrim( $query );
        $prefix = strtoupper( substr( $trimmed, 0, 6 ) );
        $starts_create = 'CREATE' === $prefix;
        $starts_drop = 'DROP' === substr( $prefix, 0, 4 ) && isset( $trimmed[4] ) && ctype_space( $trimmed[4] );
        if ( ! $starts_create && ! $starts_drop ) {
            return $query;
        }
        self::$database_table_probe = true;
        try {
            $enabled = Store::action_enabled( 'database_table_created' ) || Store::action_enabled( 'database_table_deleted' );
        } finally {
            self::$database_table_probe = false;
        }
        if ( ! $enabled ) {
            return $query;
        }
        $tables = self::database_table_targets( $trimmed );
        if ( ! $tables ) {
            return $query;
        }

        global $wpdb;
        $prefixes = array_unique( array_filter( [ (string) $wpdb->prefix, (string) $wpdb->base_prefix ] ) );
        foreach ( $tables as $table ) {
            if ( 1 !== preg_match( '/\A[A-Za-z0-9_$]{1,64}\z/', $table )
                || preg_match( '/_(?:alfa|cvsj)_(?:events|event_notes|file_index)\z/i', $table ) ) {
                continue;
            }
            $belongs_to_site = false;
            foreach ( $prefixes as $site_prefix ) {
                if ( str_starts_with( $table, $site_prefix ) ) {
                    $belongs_to_site = true;
                    break;
                }
            }
            if ( ! $belongs_to_site ) {
                continue;
            }
            $key = strtolower( $table );
            if ( isset( self::$database_tables[ $key ] ) ) {
                continue;
            }
            $exists = self::database_table_exists( $table );
            if ( null === $exists ) {
                continue;
            }
            self::$database_tables[ $key ] = [
                'name' => $table,
                'exists' => $exists,
                'blog_id' => get_current_blog_id(),
                'network_id' => is_multisite() ? get_current_network_id() : 0,
            ];
        }
        return $query;
    }

    private static function database_table_targets( string $query ): array {
        $create_pattern = '/\ACREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`([A-Za-z0-9_$]+)`|([A-Za-z0-9_$]+))(?=\s*(?:\(|LIKE\b|AS\b|SELECT\b))/i';
        if ( 1 === preg_match( $create_pattern, $query, $matches ) ) {
            return [ $matches[1] ?: $matches[2] ];
        }
        $drop_pattern = '/\ADROP\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+EXISTS\s+)?(.+?)(?:\s+(?:RESTRICT|CASCADE))?\s*;?\z/is';
        if ( 1 !== preg_match( $drop_pattern, $query, $matches ) ) {
            return [];
        }
        $tables = [];
        foreach ( explode( ',', $matches[1] ) as $identifier ) {
            if ( 1 === preg_match( '/\A\s*(?:`([A-Za-z0-9_$]+)`|([A-Za-z0-9_$]+))\s*\z/', $identifier, $parts ) ) {
                $tables[] = $parts[1] ?: $parts[2];
            }
        }
        return $tables;
    }

    private static function database_table_exists( string $table ): ?bool {
        global $wpdb;
        self::$database_table_probe = true;
        try {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $wpdb->last_error ) {
                return null;
            }
            return is_string( $found ) && 0 === strcasecmp( $found, $table );
        } finally {
            self::$database_table_probe = false;
        }
    }

    public static function database_shutdown(): void {
        if ( ! self::$database_tables ) {
            return;
        }
        $pending = self::$database_tables;
        self::$database_tables = [];
        foreach ( $pending as $table ) {
            $exists = self::database_table_exists( $table['name'] );
            if ( null === $exists || $exists === $table['exists'] ) {
                continue;
            }
            $action = $exists ? 'database_table_created' : 'database_table_deleted';
            $blog_id = (int) $table['blog_id'];
            $switched = is_multisite() && $blog_id !== get_current_blog_id();
            if ( $switched && ! switch_to_blog( $blog_id ) ) {
                continue;
            }
            try {
                $verb = $exists ? __( 'Created', 'change-vigil-site-journal' ) : __( 'Deleted', 'change-vigil-site-journal' );
                // translators: %1$s is the database action; %2$s is the database table name.
                $summary = sprintf( __( '%1$s database table “%2$s”', 'change-vigil-site-journal' ), $verb, $table['name'] );
                $details = [ 'operation' => $exists ? 'created' : 'deleted' ];
                if ( $table['network_id'] ) {
                    $details['network_id'] = (int) $table['network_id'];
                    $details['site_id'] = $blog_id;
                }
                self::record( $action, 'table', $table['name'], $table['name'], $summary, $details, $exists ? 'notice' : 'warning' );
            } finally {
                if ( $switched ) {
                    restore_current_blog();
                }
            }
        }
    }

    public static function site_email_request_added( string $option, mixed $value ): void {
        self::record_email_change( 'admin_email_change_requested', 'setting', 'admin_email', __( 'Site administrator email', 'change-vigil-site-journal' ), false );
    }

    public static function site_email_request_updated( mixed $old_value, mixed $value, string $option ): void {
        self::record_email_change( 'admin_email_change_requested', 'setting', 'admin_email', __( 'Site administrator email', 'change-vigil-site-journal' ), false );
    }

    public static function site_email_changed( mixed $old_value, mixed $value, string $option ): void {
        if ( $old_value !== $value ) {
            self::record_email_change( 'admin_email_changed', 'setting', 'admin_email', __( 'Site administrator email', 'change-vigil-site-journal' ), false );
        }
    }

    public static function network_email_request_added( string $option, mixed $value, int $network_id ): void {
        self::record_email_change( 'network_admin_email_change_requested', 'setting', $network_id . ':admin_email', __( 'Network administrator email', 'change-vigil-site-journal' ), true );
    }

    public static function network_email_request_updated( string $option, mixed $value, mixed $old_value, int $network_id ): void {
        self::record_email_change( 'network_admin_email_change_requested', 'setting', $network_id . ':admin_email', __( 'Network administrator email', 'change-vigil-site-journal' ), true );
    }

    public static function network_email_changed( string $option, mixed $value, mixed $old_value, int $network_id ): void {
        if ( $old_value !== $value ) {
            self::record_email_change( 'network_admin_email_changed', 'setting', $network_id . ':admin_email', __( 'Network administrator email', 'change-vigil-site-journal' ), true );
        }
    }

    private static function record_email_change( string $action, string $kind, string $key, string $name, bool $network ): void {
        $verb = str_contains( $action, 'requested' ) ? __( 'Requested', 'change-vigil-site-journal' ) : __( 'Changed', 'change-vigil-site-journal' );
        $scope = $network ? __( 'network', 'change-vigil-site-journal' ) : __( 'site', 'change-vigil-site-journal' );
        // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
        self::record( $action, $kind, $key, $name, sprintf( __( '%1$s %2$s administrator email change', 'change-vigil-site-journal' ), $verb, $scope ), [ 'confirmation_required' => str_contains( $action, 'requested' ) ], 'notice' );
    }

    public static function network_setting_added( string $option, mixed $value, int $network_id ): void {
        self::record_network_setting( $option, '', $value, $network_id );
    }

    public static function network_setting_updated( string $option, mixed $value, mixed $old_value, int $network_id ): void {
        self::record_network_setting( $option, $old_value, $value, $network_id );
    }

    private static function record_network_setting( string $option, mixed $old_value, mixed $value, int $network_id ): void {
        if ( ! isset( self::NETWORK_OPTIONS[ $option ] ) ) {
            return;
        }
        $previous = self::safe_network_setting_value( $option, $old_value );
        $next = self::safe_network_setting_value( $option, $value );
        if ( $previous === $next ) {
            return;
        }
        $label = match ( $option ) {
            'registration' => __( 'New site registration', 'change-vigil-site-journal' ),
            'registrationnotification' => __( 'Registration notifications', 'change-vigil-site-journal' ),
            'add_new_users' => __( 'Add new users', 'change-vigil-site-journal' ),
            'upload_space' => __( 'Network upload limit', 'change-vigil-site-journal' ),
            'blog_upload_space' => __( 'Site upload limit', 'change-vigil-site-journal' ),
            'fileupload_maxk' => __( 'File upload limit', 'change-vigil-site-journal' ),
            'WPLANG' => __( 'Network language', 'change-vigil-site-journal' ),
            'global_terms_enabled' => __( 'Global terms', 'change-vigil-site-journal' ),
            'ms_files_rewriting' => __( 'Legacy media URL rewriting', 'change-vigil-site-journal' ),
            default => '',
        };
        self::record(
            'network_setting_changed',
            'setting',
            $network_id . ':' . $option,
            $label,
            // translators: %s is the network setting label.
            sprintf( __( 'Changed network setting “%s”', 'change-vigil-site-journal' ), $label ),
            [ 'changes' => [ $label => [ $previous, $next ] ], 'network_id' => $network_id ],
            'notice'
        );
    }

    private static function safe_network_setting_value( string $option, mixed $value ): string {
        if ( is_bool( $value ) || in_array( $option, [ 'registrationnotification', 'add_new_users', 'global_terms_enabled', 'ms_files_rewriting' ], true ) ) {
            if ( in_array( $value, [ true, 1, '1', 'yes' ], true ) ) {
                return __( 'Enabled', 'change-vigil-site-journal' );
            }
            return in_array( $value, [ false, 0, '0', 'no', '' ], true ) ? __( 'Disabled', 'change-vigil-site-journal' ) : __( 'Other', 'change-vigil-site-journal' );
        }
        if ( in_array( $option, [ 'upload_space', 'blog_upload_space', 'fileupload_maxk' ], true ) ) {
            return (string) absint( $value );
        }
        if ( 'registration' === $option ) {
            return in_array( $value, [ 'none', 'user', 'blog', 'all' ], true ) ? (string) $value : __( 'Other', 'change-vigil-site-journal' );
        }
        if ( 'WPLANG' === $option && ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z]{2,3}(?:_[A-Z]{2})?(?:@[a-z]+)?\z/', $value ) ) ) {
            return __( 'Other', 'change-vigil-site-journal' );
        }
        return self::text( is_scalar( $value ) ? (string) $value : '', 120 );
    }

    public static function site_initialized( \WP_Site $site, array $args ): void {
        if ( ! is_multisite() ) {
            return;
        }
        $site_id = (int) $site->blog_id;
        $network_id = (int) $site->site_id;
        $name = self::text( (string) ( $args['title'] ?? '' ), 200 ) ?: self::network_site_name( $site );
        self::record(
            'site_created',
            'site',
            $network_id . ':' . $site_id,
            $name,
            // translators: %s is the site name or label.
            sprintf( __( 'Created network site “%s”', 'change-vigil-site-journal' ), $name ),
            [ 'network_id' => $network_id, 'site_id' => $site_id ],
            'notice'
        );
    }

    public static function site_uninitializing( \WP_Site $site ): void {
        if ( is_multisite() ) {
            self::$deleting_site_names[ (int) $site->blog_id ] = self::network_site_name( $site );
        }
    }

    public static function site_deleted( \WP_Site $site ): void {
        if ( ! is_multisite() ) {
            return;
        }
        $site_id = (int) $site->blog_id;
        $network_id = (int) $site->site_id;
        // translators: %d is the site ID.
        $name = self::$deleting_site_names[ $site_id ] ?? sprintf( __( 'Site %d', 'change-vigil-site-journal' ), $site_id );
        unset( self::$deleting_site_names[ $site_id ] );
        self::record(
            'site_deleted',
            'site',
            $network_id . ':' . $site_id,
            $name,
            // translators: %s is the site name or label.
            sprintf( __( 'Deleted network site “%s”', 'change-vigil-site-journal' ), $name ),
            [ 'network_id' => $network_id, 'site_id' => $site_id ],
            'warning'
        );
    }

    public static function site_updated( \WP_Site $site, \WP_Site $previous ): void {
        if ( ! is_multisite() ) {
            return;
        }
        $changes = [];
        foreach ( [
            'public' => __( 'Visibility', 'change-vigil-site-journal' ),
            'archived' => __( 'Archived', 'change-vigil-site-journal' ),
            'mature' => __( 'Mature', 'change-vigil-site-journal' ),
            'spam' => __( 'Spam', 'change-vigil-site-journal' ),
            'deleted' => __( 'Marked for deletion', 'change-vigil-site-journal' ),
        ] as $property => $label ) {
            $old_value = (int) $previous->$property;
            $new_value = (int) $site->$property;
            if ( $old_value === $new_value ) {
                continue;
            }
            $changes[ $label ] = [
                'public' === $property ? ( $old_value ? __( 'Public', 'change-vigil-site-journal' ) : __( 'Private', 'change-vigil-site-journal' ) ) : ( $old_value ? __( 'Yes', 'change-vigil-site-journal' ) : __( 'No', 'change-vigil-site-journal' ) ),
                'public' === $property ? ( $new_value ? __( 'Public', 'change-vigil-site-journal' ) : __( 'Private', 'change-vigil-site-journal' ) ) : ( $new_value ? __( 'Yes', 'change-vigil-site-journal' ) : __( 'No', 'change-vigil-site-journal' ) ),
            ];
        }
        if ( ! $changes ) {
            return;
        }
        $site_id = (int) $site->blog_id;
        $network_id = (int) $site->site_id;
        $name = self::network_site_name( $site );
        $sensitive_status_change = isset( $changes[ __( 'Archived', 'change-vigil-site-journal' ) ] )
            || isset( $changes[ __( 'Mature', 'change-vigil-site-journal' ) ] )
            || isset( $changes[ __( 'Spam', 'change-vigil-site-journal' ) ] )
            || isset( $changes[ __( 'Marked for deletion', 'change-vigil-site-journal' ) ] );
        self::record(
            'site_status_changed',
            'site',
            $network_id . ':' . $site_id,
            $name,
            // translators: %s is the plugin or component name.
            sprintf( __( 'Changed network site settings for “%s”', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => $changes, 'network_id' => $network_id, 'site_id' => $site_id ],
            $sensitive_status_change ? 'notice' : 'info'
        );
    }

    private static function network_site_name( \WP_Site $site ): string {
        $name = get_blog_option( (int) $site->blog_id, 'blogname', '' );
        return self::text( is_scalar( $name ) ? (string) $name : '', 200 )
            // translators: %d is the site ID.
            ?: sprintf( __( 'Site %d', 'change-vigil-site-journal' ), (int) $site->blog_id );
    }

    private static function text( string $value, int $length = 240 ): string {
        return self::truncate( sanitize_text_field( $value ), $length );
    }

    public static function remember_previous_version( mixed $response, array $options ): mixed {
        $single_update = 'update' === ( $options['action'] ?? '' );
        $bulk_update = ! isset( $options['action'] ) && isset( $options['temp_backup'] );
        $type = $options['type'] ?? '';
        if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
            if ( isset( $options['plugin'] ) && ! isset( $options['theme'] ) ) {
                $type = 'plugin';
            } elseif ( isset( $options['theme'] ) && ! isset( $options['plugin'] ) ) {
                $type = 'theme';
            }
        }
        if ( is_wp_error( $response ) || ( ! $single_update && ! $bulk_update )
            || ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
            return $response;
        }
        $key = $options[ $type ] ?? '';
        if ( ! is_string( $key ) || '' === $key ) {
            return $response;
        }

        if ( 'plugin' === $type && 0 === validate_file( $key ) && is_file( WP_PLUGIN_DIR . '/' . $key ) ) {
            $headers = get_file_data( WP_PLUGIN_DIR . '/' . $key, [ 'version' => 'Version' ] );
            $version = $headers['version'] ?? '';
        } elseif ( 'theme' === $type ) {
            $theme = wp_get_theme( $key );
            $version = $theme->exists() ? $theme->get( 'Version' ) : '';
        } else {
            $version = '';
        }

        if ( '' !== $version ) {
            self::$previous_versions[ $type ][ $key ] = self::text( (string) $version, 50 );
        }
        return $response;
    }

    private static function truncate( string $value, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $length, 'UTF-8' );
        }
        if ( 1 === preg_match( '/\A.{0,' . max( 0, $length ) . '}/us', $value, $matches ) ) {
            return $matches[0];
        }
        return '';
    }

    private static function post_date_value( \WP_Post $post ): string {
        $date = get_post_datetime( $post, 'date', 'gmt' );
        if ( ! $date ) {
            return '';
        }

        $local = $date->setTimezone( wp_timezone() );
        return self::text(
            $local->format( 'Y-m-d H:i:s' ) . ' (' . $local->getTimezone()->getName() . ', UTC' . $local->format( 'P' ) . ')',
            120
        );
    }

    private static function actor( ?\WP_User $user = null ): array {
        $user = $user ?? wp_get_current_user();
        if ( $user->exists() ) {
            return [ 'user', $user->ID, self::text( $user->user_login, 191 ) ];
        }
        if ( wp_doing_cron() ) {
            return [ 'system', 0, '' ];
        }
        if ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) ) {
            return [ 'cli', 0, __( 'WP CLI', 'change-vigil-site-journal' ) ];
        }
        return [ 'unknown', 0, '' ];
    }

    private static function is_infinitewp_request(): bool {
        if ( ! defined( 'IWP_MMB_CLIENT_VERSION' ) || ! defined( 'IWP_AUTHORISED_CALL' ) || 1 !== (int) constant( 'IWP_AUTHORISED_CALL' ) ) {
            return false;
        }

        $core   = $GLOBALS['iwp_mmb_core'] ?? null;
        $params = is_object( $core ) && isset( $core->request_params ) && is_array( $core->request_params ) ? $core->request_params : [];
        $action = sanitize_key( (string) ( $params['iwp_action'] ?? '' ) );
        if ( ! in_array( $action, [ 'do_upgrade', 'edit_plugins_themes', 'add_user', 'edit_users', 'create_post', 'change_post_status', 'delete_post', 'delete_posts', 'delete_page', 'add_link', 'delete_link', 'delete_links', 'change_comment_status', 'action_comment', 'bulk_action_comments', 'replyto_comment', 'backup_clone', 'restore', 'scheduled_backup', 'new_scheduled_backup', 'run_task', 'new_run_task', 'delete_schedule_task', 'remote_backup_now' ], true ) ) {
            return false;
        }

        $username = $params['username'] ?? null;
        $user     = wp_get_current_user();
        return is_string( $username ) && $user instanceof \WP_User && $user->exists() && hash_equals( (string) $user->user_login, $username );
    }

    private static function bbpress_post_kind( string $post_type ): ?string {
        if ( null === self::$bbpress_post_types ) {
            if ( ! did_action( 'plugins_loaded' ) ) {
                return null;
            }
            self::cache_bbpress_post_types();
        }
        $kind = array_search( $post_type, self::$bbpress_post_types, true );
        return is_string( $kind ) ? $kind : null;
    }

    public static function cache_bbpress_post_types(): void {
        self::$bbpress_post_types = [];
        foreach ( [ 'forum' => 'bbp_get_forum_post_type', 'topic' => 'bbp_get_topic_post_type', 'reply' => 'bbp_get_reply_post_type' ] as $kind => $function ) {
            if ( function_exists( $function ) ) {
                $type = $function();
                if ( is_string( $type ) && '' !== $type ) {
                    self::$bbpress_post_types[ $kind ] = $type;
                }
            }
        }
    }

    public static function bbpress_forum_status_before( int $forum_id ): void {
        if ( Store::settings()['bbpress'] && function_exists( 'bbp_get_forum_status' ) ) {
            self::$bbpress_forum_state_before[ $forum_id ]['status'] = self::text( bbp_get_forum_status( $forum_id ), 40 );
        }
    }

    public static function bbpress_forum_type_before( int $forum_id ): void {
        if ( Store::settings()['bbpress'] ) {
            $type = get_post_meta( $forum_id, '_bbp_forum_type', true );
            self::$bbpress_forum_state_before[ $forum_id ]['type'] = self::text( $type ?: 'forum', 40 );
        }
    }

    private static function bbpress_forum_state_changed( int $forum_id, string $field, string $after ): void {
        $before = self::$bbpress_forum_state_before[ $forum_id ][ $field ] ?? null;
        unset( self::$bbpress_forum_state_before[ $forum_id ][ $field ] );
        if ( empty( self::$bbpress_forum_state_before[ $forum_id ] ) ) {
            unset( self::$bbpress_forum_state_before[ $forum_id ] );
        }
        if ( ! Store::settings()['bbpress'] || ! is_string( $before ) || $before === $after ) {
            return;
        }
        $forum = get_post( $forum_id );
        if ( ! $forum instanceof \WP_Post || 'forum' !== self::bbpress_post_kind( $forum->post_type ) ) {
            return;
        }
        $name = self::bbpress_item_name( $forum, 'forum' );
        $label = 'status' === $field ? __( 'Status', 'change-vigil-site-journal' ) : __( 'Type', 'change-vigil-site-journal' );
        $format = static function ( string $value ) use ( $field ): string {
            if ( 'status' === $field ) {
                return 'closed' === $value ? __( 'Closed', 'change-vigil-site-journal' ) : __( 'Open', 'change-vigil-site-journal' );
            }
            return 'category' === $value ? __( 'Category', 'change-vigil-site-journal' ) : __( 'Forum', 'change-vigil-site-journal' );
        };
        $action = 'status' === $field ? 'forum_status_changed' : 'forum_edited';
        $summary = 'status' === $field
            // translators: %s is the forum item name.
            ? sprintf( __( 'Changed the status of bbPress forum “%s”', 'change-vigil-site-journal' ), $name )
            // translators: %s is the forum item name.
            : sprintf( __( 'Changed the type of bbPress forum “%s”', 'change-vigil-site-journal' ), $name );
        self::record( $action, 'forum', $forum_id, $name, $summary,
            [ 'changes' => [ $label => [ $format( $before ), $format( $after ) ] ] ], 'notice' );
    }

    public static function bbpress_forum_closed( int $forum_id ): void {
        self::bbpress_forum_state_changed( $forum_id, 'status', 'closed' );
    }

    public static function bbpress_forum_opened( int $forum_id ): void {
        self::bbpress_forum_state_changed( $forum_id, 'status', 'open' );
    }

    public static function bbpress_forum_categorized( int $forum_id ): void {
        self::bbpress_forum_state_changed( $forum_id, 'type', 'category' );
    }

    public static function bbpress_forum_normalized( int $forum_id ): void {
        self::bbpress_forum_state_changed( $forum_id, 'type', 'forum' );
    }

    public static function bbpress_topic_sticky_before( int $topic_id ): void {
        if ( ! Store::settings()['bbpress'] || ! function_exists( 'bbp_is_topic_sticky' ) ) {
            return;
        }
        $super = function_exists( 'bbp_is_topic_super_sticky' ) && bbp_is_topic_super_sticky( $topic_id );
        self::$bbpress_topic_sticky_before[ $topic_id ] = [
            'sticky' => bbp_is_topic_sticky( $topic_id ),
            'label' => $super ? __( 'Super sticky', 'change-vigil-site-journal' ) : __( 'Sticky', 'change-vigil-site-journal' ),
        ];
    }

    public static function bbpress_topic_stuck( int $topic_id, bool $super, bool $success ): void {
        if ( ! $success || ! Store::settings()['bbpress'] ) {
            return;
        }
        self::bbpress_topic_sticky_changed( $topic_id, 'topic_stickied', __( 'Not sticky', 'change-vigil-site-journal' ), $super ? __( 'Super sticky', 'change-vigil-site-journal' ) : __( 'Sticky', 'change-vigil-site-journal' ) );
    }

    public static function bbpress_topic_unstuck( int $topic_id, bool $success ): void {
        $before = self::$bbpress_topic_sticky_before[ $topic_id ] ?? null;
        unset( self::$bbpress_topic_sticky_before[ $topic_id ] );
        if ( ! $success || ! is_array( $before ) || empty( $before['sticky'] ) || ! Store::settings()['bbpress'] ) {
            return;
        }
        self::bbpress_topic_sticky_changed( $topic_id, 'topic_unstickied', (string) $before['label'], __( 'Not sticky', 'change-vigil-site-journal' ) );
    }

    private static function bbpress_topic_sticky_changed( int $topic_id, string $action, string $before, string $after ): void {
        $topic = get_post( $topic_id );
        if ( ! $topic instanceof \WP_Post || 'topic' !== self::bbpress_post_kind( $topic->post_type ) ) {
            return;
        }
        $name = self::bbpress_item_name( $topic, 'topic' );
        $summary = 'topic_stickied' === $action
            // translators: %s is the forum item name.
            ? sprintf( __( 'Made bbPress topic “%s” sticky', 'change-vigil-site-journal' ), $name )
            // translators: %s is the forum item name.
            : sprintf( __( 'Removed sticky status from bbPress topic “%s”', 'change-vigil-site-journal' ), $name );
        self::record( $action, 'topic', $topic_id, $name, $summary,
            [ 'changes' => [ 'Sticky' => [ $before, $after ] ] ], 'notice' );
    }

    private static function bbpress_kind_label( string $kind ): string {
        return [
            'forum' => __( 'forum', 'change-vigil-site-journal' ),
            'topic' => __( 'topic', 'change-vigil-site-journal' ),
            'reply' => __( 'reply', 'change-vigil-site-journal' ),
        ][ $kind ] ?? __( 'forum item', 'change-vigil-site-journal' );
    }

    private static function bbpress_reference( int $id ): string {
        if ( $id < 1 ) {
            return __( 'None', 'change-vigil-site-journal' );
        }
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post ) {
            // translators: %d is the ID of the affected item.
            return sprintf( __( 'Item ID %d', 'change-vigil-site-journal' ), $id );
        }
        $title = self::redacted_text( $post->post_title, 140 ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
        // translators: %1$s is the name or label of the affected record; %2$d is the ID of the affected item.
        return sprintf( __( '%1$s (item ID %2$d)', 'change-vigil-site-journal' ), $title, $id );
    }

    private static function bbpress_item_name( \WP_Post $post, string $kind ): string {
        if ( 'reply' === $kind ) {
            // translators: %d is the ID of the affected item.
            return sprintf( __( 'Reply #%d', 'change-vigil-site-journal' ), $post->ID );
        }
        $title = self::redacted_text( $post->post_title, 220 );
        // translators: %1$s is the type of the affected item; %2$d is the ID of the affected item.
        return $title ?: sprintf( __( '%1$s #%2$d', 'change-vigil-site-journal' ), ucfirst( self::bbpress_kind_label( $kind ) ), $post->ID );
    }

    private static function bbpress_context_changes( \WP_Post $post, string $kind ): array {
        $changes = [];
        if ( 'forum' === $kind ) {
            if ( $post->post_parent ) {
                $changes['Parent forum'] = [ '', self::bbpress_reference( (int) $post->post_parent ) ];
            }
        } elseif ( 'topic' === $kind ) {
            $forum_id = function_exists( 'bbp_get_topic_forum_id' ) ? absint( bbp_get_topic_forum_id( $post->ID ) ) : 0;
            $forum_id = $forum_id ?: absint( $post->post_parent );
            if ( $forum_id ) {
                $changes['Forum'] = [ '', self::bbpress_reference( $forum_id ) ];
            }
        } elseif ( 'reply' === $kind ) {
            if ( function_exists( 'bbp_get_reply_topic_id' ) ) {
                $topic_id = absint( bbp_get_reply_topic_id( $post->ID ) ) ?: absint( $post->post_parent );
            } else {
                $topic_id = absint( $post->post_parent );
            }
            if ( $topic_id ) {
                $changes['Topic'] = [ '', self::bbpress_reference( $topic_id ) ];
            }
            if ( function_exists( 'bbp_get_reply_forum_id' ) ) {
                $forum_id = absint( bbp_get_reply_forum_id( $post->ID ) );
            } else {
                $forum_id = 0;
            }
            if ( ! $forum_id && $topic_id ) {
                $topic = get_post( $topic_id );
                $forum_id = $topic instanceof \WP_Post ? absint( $topic->post_parent ) : 0;
            }
            if ( $forum_id ) {
                $changes['Forum'] = [ '', self::bbpress_reference( $forum_id ) ];
            }
        }
        return $changes;
    }

    private static function bbpress_actor( \WP_Post $post, bool $created ): ?array {
        if ( ! $created || $post->post_author || is_user_logged_in() || wp_doing_cron()
            || ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) ) ) {
            return null;
        }
        return [ 'anonymous', 0, __( 'Anonymous visitor', 'change-vigil-site-journal' ) ];
    }

    private static function bbpress_post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( ! Store::settings()['bbpress']
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }
        $kind = self::bbpress_post_kind( $post->post_type );
        if ( ! $kind ) {
            return;
        }
        $name = self::bbpress_item_name( $post, $kind );
        $created = ! $update || ! $before || 'auto-draft' === $before->post_status;
        if ( $created ) {
            $changes = self::bbpress_context_changes( $post, $kind );
            if ( 'reply' !== $kind && '' !== $post->post_title ) {
                $changes = [ 'Title' => [ '', $name ], ...$changes ];
            }
            $changes['Status'] = [ '', self::text( $post->post_status, 80 ) ];
            $action = $kind . '_created';
            if ( 'reply' === $kind ) {
                $topic_id = function_exists( 'bbp_get_reply_topic_id' ) ? absint( bbp_get_reply_topic_id( $id ) ) : 0;
                $topic_id = $topic_id ?: absint( $post->post_parent );
                // translators: %1$d is the ID of the affected item; %2$s is the related topic name or ID.
                $summary = sprintf( __( 'Added bbPress reply #%1$d to %2$s', 'change-vigil-site-journal' ), $id, $topic_id ? self::bbpress_reference( $topic_id ) : __( 'a topic', 'change-vigil-site-journal' ) );
            } else {
                // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
                $summary = sprintf( __( 'Created bbPress %1$s “%2$s”', 'change-vigil-site-journal' ), self::bbpress_kind_label( $kind ), $name );
            }
            self::record( $action, $kind, $id, $name, $summary, [ 'changes' => $changes ], 'notice', self::bbpress_actor( $post, true ) );
            return;
        }

        if ( ! $before ) {
            return;
        }
        $changes = [];
        if ( 'reply' !== $kind && $before->post_title !== $post->post_title ) {
            $changes['Title'] = [ self::redacted_text( $before->post_title, 220 ), self::redacted_text( $post->post_title, 220 ) ];
        }
        if ( $before->post_status !== $post->post_status ) {
            $changes['Status'] = [ self::text( $before->post_status, 80 ), self::text( $post->post_status, 80 ) ];
        }
        if ( 'forum' === $kind && $before->post_parent !== $post->post_parent ) {
            $changes['Parent forum'] = [ self::bbpress_reference( (int) $before->post_parent ), self::bbpress_reference( (int) $post->post_parent ) ];
        }
        if ( $before->post_author !== $post->post_author ) {
            $changes['Author'] = [ self::post_field_value( 'post_author', (string) $before->post_author ), self::post_field_value( 'post_author', (string) $post->post_author ) ];
        }
        if ( $before->post_content !== $post->post_content || $before->post_excerpt !== $post->post_excerpt ) {
            $changes['Content'] = [ __( 'Not recorded', 'change-vigil-site-journal' ), __( 'Changed', 'change-vigil-site-journal' ) ];
        }
        if ( ! $changes ) {
            return;
        }

        $action = $kind . '_edited';
        $severity = 'info';
        if ( 'trash' === $post->post_status && 'trash' !== $before->post_status ) {
            $action = $kind . '_trashed';
            $severity = 'warning';
        } elseif ( 'trash' === $before->post_status && 'trash' !== $post->post_status ) {
            $action = $kind . '_restored';
            $severity = 'notice';
        } elseif ( isset( $changes['Status'] ) ) {
            $action = $kind . '_status_changed';
            $severity = 'notice';
        }
        if ( 'reply' === $kind ) {
            $topic_id = function_exists( 'bbp_get_reply_topic_id' ) ? absint( bbp_get_reply_topic_id( $id ) ) : 0;
            $topic_id = $topic_id ?: absint( $post->post_parent );
            // translators: %1$d is the ID of the affected item; %2$s is the related topic name or ID.
            $subject = sprintf( __( 'bbPress reply #%1$d in %2$s', 'change-vigil-site-journal' ), $id, $topic_id ? self::bbpress_reference( $topic_id ) : __( 'a topic', 'change-vigil-site-journal' ) );
        } else {
            // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
            $subject = sprintf( __( 'bbPress %1$s “%2$s”', 'change-vigil-site-journal' ), self::bbpress_kind_label( $kind ), $name );
        }
        if ( 'forum_trashed' === $action || 'topic_trashed' === $action || 'reply_trashed' === $action ) {
            // translators: %s is the name or label of the affected record.
            $summary = sprintf( __( 'Moved %s to Trash', 'change-vigil-site-journal' ), $subject );
        } elseif ( 'forum_restored' === $action || 'topic_restored' === $action || 'reply_restored' === $action ) {
            // translators: %s is the name or label of the affected record.
            $summary = sprintf( __( 'Restored %s from Trash', 'change-vigil-site-journal' ), $subject );
        } elseif ( str_ends_with( $action, '_status_changed' ) ) {
            // translators: %s is the name or label of the affected record.
            $summary = sprintf( __( 'Changed the status of %s', 'change-vigil-site-journal' ), $subject );
        } elseif ( isset( $changes['Title'] ) && '' !== $changes['Title'][0] ) {
            // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record; %3$s is the name or label of the affected record.
            $summary = sprintf( __( 'Renamed bbPress %1$s from “%2$s” to “%3$s”', 'change-vigil-site-journal' ), self::bbpress_kind_label( $kind ), $changes['Title'][0], $changes['Title'][1] );
        } else {
            // translators: %s is the name or label of the affected record.
            $summary = sprintf( __( 'Edited %s', 'change-vigil-site-journal' ), $subject );
        }
        self::record( $action, $kind, $id, $name, $summary, [ 'changes' => $changes ], $severity );
    }

    private static function supports_post_type( string $post_type ): bool {
        if ( self::wpforms_post_type( $post_type ) ) {
            return false;
        }
        if ( self::bbpress_post_kind( $post_type ) ) {
            return false;
        }
        if ( in_array( $post_type, [ 'post', 'page' ], true ) ) {
            return true;
        }
        $object = get_post_type_object( $post_type );
        return $object instanceof \WP_Post_Type
            && $object->public
            && $object->publicly_queryable
            && $object->show_ui
            && ! in_array( $post_type, [ 'attachment', 'revision', 'nav_menu_item', 'product', 'product_variation', 'shop_coupon', 'shop_order', 'shop_order_refund' ], true );
    }

    private static function post_type_label( string $post_type ): string {
        $object = get_post_type_object( $post_type );
        return $object instanceof \WP_Post_Type
            ? self::text( $object->labels->singular_name ?: $object->label, 80 )
            : self::text( $post_type, 80 );
    }

    private static function supports_taxonomy( string $taxonomy ): bool {
        if ( in_array( $taxonomy, [ 'topic-tag', 'bbp_topic_tag' ], true ) && ! Store::settings()['bbpress'] ) {
            return false;
        }
        $object = get_taxonomy( $taxonomy );
        return $object instanceof \WP_Taxonomy && $object->public && $object->show_ui && 'nav_menu' !== $taxonomy;
    }

    private static function record(
        string $action, string $kind, int|string $key, string $name, string $summary,
        array $details = [], string $severity = 'info', ?array $actor = null, ?string $source = null, ?array $settings_override = null
    ): void {
        if ( ! Store::action_enabled( $action, $settings_override ) ) {
            return;
        }
        [ $actor_kind, $actor_id, $actor_name ] = $actor ?? self::actor();
        if ( Store::should_exclude( $actor_kind, $actor_id, $kind, (string) $key, $details, $settings_override ) ) {
            return;
        }
        $actor_role = '';
        if ( 'user' === $actor_kind && $actor_id ) {
            $user = get_userdata( $actor_id );
            if ( $user instanceof \WP_User ) {
                $roles = (array) $user->roles;
                $actor_role = substr( sanitize_key( (string) reset( $roles ) ), 0, 64 );
            }
        }
        Store::add( [
            'actor_kind' => $actor_kind,
            'actor_id'   => $actor_id,
            'actor_name' => $actor_name,
            'actor_role' => $actor_role,
            'source'     => $source ?? self::source(),
            'item_kind'  => $kind,
            'item_key'   => self::truncate( (string) $key, 191 ),
            'item_name'  => self::text( $name, 255 ),
            'action_key' => $action,
            'severity'   => $severity,
            'summary'    => self::text( $summary, 600 ),
        ], $details );
    }

    public static function file_event( string $action, string $path, string $summary, array $details = [], string $severity = 'info' ): void {
        if ( ! in_array( $action, [ 'file_created', 'file_modified', 'file_deleted', 'file_permissions_changed', 'file_scan_started', 'file_scan_completed', 'file_scan_failed', 'file_scan_size_skipped' ], true ) ) {
            return;
        }
        $path = sanitize_text_field( wp_check_invalid_utf8( $path, true ) );
        $path = substr( str_replace( '\\', '/', $path ), 0, 500 );
        $key = hash( 'sha256', $path );
        self::record( $action, 'file', $key, '' === $path ? __( 'Site files', 'change-vigil-site-journal' ) : $path, $summary, $details, $severity );
    }

    public static function session_ended( \WP_User $user, int $count, bool $all ): void {
        if ( $count < 1 ) {
            return;
        }
        $action = $all ? 'sessions_ended' : 'session_ended';
        $summary = $all
            // translators: %1$d is the number of sessions; %2$s is the account name.
            ? sprintf( _n( 'Ended all %1$d session for “%2$s”', 'Ended all %1$d sessions for “%2$s”', $count, 'change-vigil-site-journal' ), $count, $user->user_login )
            // translators: %s is the name or label of the affected record.
            : sprintf( __( 'Ended a session for “%s”', 'change-vigil-site-journal' ), $user->user_login );
        self::record( $action, 'user', (int) $user->ID, $user->user_login, $summary, [ 'session_count' => $count ], 'warning' );
    }

    public static function session_policy_ended( \WP_User $user, int $count, string $policy ): void {
        if ( $count < 1 || ! in_array( $policy, [ 'concurrent', 'idle' ], true ) ) {
            return;
        }
        $reason = 'idle' === $policy ? __( 'idle expiry', 'change-vigil-site-journal' ) : __( 'session limit', 'change-vigil-site-journal' );
        // translators: %1$d is the number of sessions; %2$s is the name or label of the affected record.
        $summary = sprintf( __( 'Session policy ended %1$d session(s) for “%2$s”', 'change-vigil-site-journal' ), $count, $user->user_login );
        self::record( 'session_policy_ended', 'user', (int) $user->ID, $user->user_login, $summary, [ 'session_count' => $count, 'policy' => $reason ], 'warning', [ 'system', 0, __( 'WordPress session policy', 'change-vigil-site-journal' ) ] );
    }

    public static function session_settings_changed( array $settings ): void {
        self::record(
            'session_policy_changed',
            'setting',
            'session_management',
            __( 'Session management', 'change-vigil-site-journal' ),
            __( 'Changed session privacy and enforcement settings', 'change-vigil-site-journal' ),
            [
                'activity_tracking' => (bool) $settings['track_activity'],
                'browser_details' => (bool) $settings['show_browser'],
                'ip_display' => (bool) $settings['show_ip'],
                'concurrent_limit' => (bool) $settings['limit_enabled'],
                'max_sessions' => (int) $settings['max_sessions'],
                'idle_expiry' => (bool) $settings['idle_enabled'],
                'idle_minutes' => (int) $settings['idle_minutes'],
                'excluded_roles' => array_values( array_map( 'sanitize_key', $settings['excluded_roles'] ) ),
            ],
            'notice'
        );
    }

    private static function source(): string {
        if ( Store::settings()['infinitewp'] && self::is_infinitewp_request() ) {
            return 'infinitewp';
        }
        if ( wp_doing_cron() ) {
            return 'cron';
        }
        if ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) ) {
            return 'cli';
        }
        if ( defined( 'REST_REQUEST' ) && (bool) constant( 'REST_REQUEST' ) ) {
            return 'rest';
        }
        if ( defined( 'XMLRPC_REQUEST' ) && (bool) constant( 'XMLRPC_REQUEST' ) ) {
            return 'xmlrpc';
        }
        return is_admin() ? 'admin' : 'frontend';
    }

    private static function is_woocommerce_post_type( string $post_type ): bool {
        return in_array( $post_type, [ 'product', 'product_variation', 'shop_coupon' ], true );
    }

    private static function woocommerce_kind( string $post_type ): string {
        return 'shop_coupon' === $post_type ? 'coupon' : 'product';
    }

    private static function woocommerce_item_name( \WP_Post $post, string $kind ): string {
        if ( 'coupon' === $kind ) {
            // translators: %d is the ID of the affected item.
            return sprintf( __( 'Coupon #%d', 'change-vigil-site-journal' ), $post->ID );
        }
        if ( 'product_variation' === $post->post_type ) {
            // translators: %d is the ID of the affected item.
            return sprintf( __( 'Product variation #%d', 'change-vigil-site-journal' ), $post->ID );
        }
        // translators: %d is the ID of the affected item.
        return self::redacted_text( $post->post_title ) ?: sprintf( __( 'Product #%d', 'change-vigil-site-journal' ), $post->ID );
    }

    private static function redacted_text( string $value, int $length = 180 ): string {
        $value = self::text( $value, $length );
        $value = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', __( '[email redacted]', 'change-vigil-site-journal' ), $value ) ?? $value;
        return self::truncate( $value, $length );
    }

    private static function woocommerce_post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( ! Store::settings()['woocommerce']
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }

        $kind = self::woocommerce_kind( $post->post_type );
        $name = self::woocommerce_item_name( $post, $kind );
        $created = ! $update || ! $before || 'auto-draft' === $before->post_status;
        $changes = [];
        if ( $created ) {
            if ( 'product' === $kind && 'product_variation' !== $post->post_type ) {
                $changes['Title'] = [ '', self::redacted_text( $post->post_title, 180 ) ];
            }
            $changes['Status'] = [ '', self::text( $post->post_status, 80 ) ];
        } else {
            if ( $before->post_title !== $post->post_title ) {
                $changes[ 'coupon' === $kind ? 'Coupon code' : 'Title' ] = 'coupon' === $kind
                    ? [ __( 'Not recorded', 'change-vigil-site-journal' ), __( 'Changed', 'change-vigil-site-journal' ) ]
                    : [ self::redacted_text( $before->post_title, 180 ), self::redacted_text( $post->post_title, 180 ) ];
            }
            if ( $before->post_status !== $post->post_status ) {
                $changes['Status'] = [ self::text( $before->post_status, 80 ), self::text( $post->post_status, 80 ) ];
            }
            if ( $before->post_content !== $post->post_content || $before->post_excerpt !== $post->post_excerpt ) {
                $changes['Description'] = [ __( 'Not recorded', 'change-vigil-site-journal' ), __( 'Changed', 'change-vigil-site-journal' ) ];
            }
        }
        if ( ! $created && ! $changes ) {
            return;
        }

        if ( $created ) {
            self::$woocommerce_changes[ $id ] = [
                'kind' => $kind,
                'created' => true,
                'name' => $name,
                'changes' => $changes,
            ];
            return;
        }
        self::queue_woocommerce_changes( $id, $kind, $name, $changes );
    }

    private static function queue_woocommerce_changes( int $id, string $kind, string $name, array $changes ): void {
        if ( $id < 1 || ! $changes ) {
            return;
        }
        $pending = self::$woocommerce_changes[ $id ] ?? [
            'kind' => $kind,
            'created' => false,
            'name' => $name,
            'changes' => [],
        ];
        if ( ! empty( $pending['created'] ) ) {
            return;
        }
        $pending['kind'] = $kind;
        $pending['name'] = $name;
        foreach ( $changes as $label => $values ) {
            if ( ! is_array( $values ) || 2 !== count( $values ) ) {
                continue;
            }
            $previous = (string) $values[0];
            $next = (string) $values[1];
            if ( $previous === $next ) {
                continue;
            }
            if ( isset( $pending['changes'][ $label ] ) ) {
                $pending['changes'][ $label ][1] = $next;
                if ( $pending['changes'][ $label ][0] === $next ) {
                    unset( $pending['changes'][ $label ] );
                }
            } else {
                $pending['changes'][ $label ] = [ $previous, $next ];
            }
        }
        self::$woocommerce_changes[ $id ] = $pending;
    }

    private static function woocommerce_meta_label( int $post_id, string $meta_key ): ?string {
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return null;
        }
        if ( 'shop_coupon' === $post->post_type ) {
            return self::WOOCOMMERCE_COUPON_META[ $meta_key ] ?? null;
        }
        if ( in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            return self::WOOCOMMERCE_PRODUCT_META[ $meta_key ] ?? null;
        }
        return null;
    }

    private static function is_woocommerce_meta_key( string $meta_key ): bool {
        return isset( self::WOOCOMMERCE_PRODUCT_META[ $meta_key ] ) || isset( self::WOOCOMMERCE_COUPON_META[ $meta_key ] );
    }

    private static function woocommerce_meta_value( string $meta_key, mixed $value ): ?string {
        if ( is_array( $value ) && in_array( $meta_key, [ 'product_ids', 'exclude_product_ids', 'product_categories', 'exclude_product_categories' ], true ) ) {
            $ids = array_values( array_unique( array_filter( array_map( 'absint', array_filter( $value, 'is_scalar' ) ) ) ) );
            sort( $ids, SORT_NUMERIC );
            return implode( ', ', array_slice( $ids, 0, 60 ) ) ?: __( 'None', 'change-vigil-site-journal' );
        }
        if ( is_string( $value ) && in_array( $meta_key, [ 'product_ids', 'exclude_product_ids', 'product_categories', 'exclude_product_categories' ], true ) ) {
            $ids = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[,\s]+/', $value ) ?: [] ) ) ) );
            sort( $ids, SORT_NUMERIC );
            return implode( ', ', array_slice( $ids, 0, 60 ) ) ?: __( 'None', 'change-vigil-site-journal' );
        }

        if ( in_array( $meta_key, [ '_manage_stock', '_sold_individually', '_featured', '_virtual', '_downloadable', 'individual_use', 'free_shipping', 'exclude_sale_items' ], true ) ) {
            if ( is_bool( $value ) || is_numeric( $value ) || is_string( $value ) ) {
                $normalized = strtolower( trim( (string) $value ) );
                if ( in_array( $normalized, [ 'yes', 'true', '1', 'on' ], true ) ) {
                    return __( 'Enabled', 'change-vigil-site-journal' );
                }
                if ( in_array( $normalized, [ '', 'no', 'false', '0', 'off' ], true ) ) {
                    return __( 'Disabled', 'change-vigil-site-journal' );
                }
            }
            return null;
        }

        if ( null === $value || ! is_scalar( $value ) ) {
            return null;
        }
        $value = (string) $value;
        if ( '' === $value ) {
            return __( 'None', 'change-vigil-site-journal' );
        }
        if ( in_array( $meta_key, [ '_regular_price', '_sale_price', '_stock', '_low_stock_amount', '_weight', '_length', '_width', '_height', 'coupon_amount', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items', 'minimum_amount', 'maximum_amount' ], true ) ) {
            return is_numeric( $value ) ? self::text( $value, 100 ) : null;
        }
        if ( '_stock_status' === $meta_key ) {
            $value = sanitize_key( $value );
            return in_array( $value, [ 'instock', 'outofstock', 'onbackorder' ], true ) ? $value : null;
        }
        if ( '_backorders' === $meta_key ) {
            $value = sanitize_key( $value );
            return in_array( $value, [ 'no', 'notify', 'yes' ], true ) ? $value : null;
        }
        if ( 'discount_type' === $meta_key ) {
            $value = sanitize_key( $value );
            return in_array( $value, [ 'percent', 'fixed_cart', 'fixed_product' ], true ) ? $value : null;
        }
        return self::redacted_text( $value, 120 );
    }

    private static function queue_woocommerce_meta_change( int $post_id, string $meta_key, mixed $previous, mixed $value ): void {
        $label = self::woocommerce_meta_label( $post_id, $meta_key );
        $post = get_post( $post_id );
        if ( ! $label || ! $post instanceof \WP_Post || ! Store::settings()['woocommerce'] ) {
            return;
        }
        if ( ! empty( self::$woocommerce_changes[ $post_id ]['created'] ) ) {
            return;
        }
        $before = self::woocommerce_meta_value( $meta_key, $previous );
        $after = self::woocommerce_meta_value( $meta_key, $value );
        if ( null === $before || null === $after || $before === $after ) {
            return;
        }
        self::queue_woocommerce_changes( $post_id, self::woocommerce_kind( $post->post_type ), self::woocommerce_item_name( $post, self::woocommerce_kind( $post->post_type ) ), [ $label => [ $before, $after ] ] );
    }

    private static function woocommerce_snapshot( int $post_id, string $kind ): array {
        $meta_fields = 'coupon' === $kind ? self::WOOCOMMERCE_COUPON_META : self::WOOCOMMERCE_PRODUCT_META;
        $changes = [];
        foreach ( $meta_fields as $meta_key => $label ) {
            if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
                continue;
            }
            $value = self::woocommerce_meta_value( $meta_key, get_post_meta( $post_id, $meta_key, true ) );
            if ( null !== $value && '' !== $value ) {
                $changes[ $label ] = [ '', $value ];
            }
        }
        return $changes;
    }

    public static function woocommerce_item_created( int $id, mixed $object = null ): void {
        self::finish_woocommerce_item( $id, true );
    }

    public static function woocommerce_item_saved( int $id, mixed $object = null ): void {
        self::finish_woocommerce_item( $id );
    }

    public static function flush_woocommerce_changes(): void {
        foreach ( array_keys( self::$woocommerce_changes ) as $id ) {
            self::finish_woocommerce_item( (int) $id );
        }
    }

    private static function finish_woocommerce_item( int $id, bool $assume_created = false ): void {
        if ( ! isset( self::$woocommerce_changes[ $id ] ) ) {
            if ( ! $assume_created ) {
                return;
            }
            $post = get_post( $id );
            if ( ! $post instanceof \WP_Post || ! self::is_woocommerce_post_type( $post->post_type ) || ! Store::settings()['woocommerce'] ) {
                return;
            }
            $kind = self::woocommerce_kind( $post->post_type );
            self::$woocommerce_changes[ $id ] = [
                'kind' => $kind,
                'created' => true,
                'name' => self::woocommerce_item_name( $post, $kind ),
                'changes' => [ 'Status' => [ '', self::text( $post->post_status, 80 ) ] ],
            ];
        }
        $pending = self::$woocommerce_changes[ $id ];
        unset( self::$woocommerce_changes[ $id ] );
        $post = get_post( $id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        $kind = (string) $pending['kind'];
        $name = self::woocommerce_item_name( $post, $kind );
        $changes = (array) $pending['changes'];
        if ( ! empty( $pending['created'] ) ) {
            $changes = array_merge( $changes, self::woocommerce_snapshot( $id, $kind ) );
            $action = 'coupon' === $kind ? 'coupon_created' : 'product_created';
            $summary = 'coupon' === $kind
                // translators: %d is the ID of the affected item.
                ? sprintf( __( 'Created WooCommerce coupon #%d', 'change-vigil-site-journal' ), $id )
                // translators: %s is the product name.
                : sprintf( __( 'Created WooCommerce product “%s”', 'change-vigil-site-journal' ), $name );
            self::record( $action, $kind, $id, $name, $summary, $changes ? [ 'changes' => $changes ] : [], 'notice' );
            return;
        }
        if ( ! $changes ) {
            return;
        }

        $status = $changes['Status'] ?? null;
        $old_status = is_array( $status ) ? (string) ( $status[0] ?? '' ) : '';
        $new_status = is_array( $status ) ? (string) ( $status[1] ?? '' ) : '';
        $prefix = 'coupon' === $kind ? 'coupon' : 'product';
        if ( 'trash' === $new_status && 'trash' !== $old_status ) {
            $action = $prefix . '_trashed';
            $summary = 'coupon' === $kind
                // translators: %d is the ID of the affected item.
                ? sprintf( __( 'Moved WooCommerce coupon #%d to Trash', 'change-vigil-site-journal' ), $id )
                // translators: %s is the product name.
                : sprintf( __( 'Moved WooCommerce product “%s” to Trash', 'change-vigil-site-journal' ), $name );
            $severity = 'warning';
        } elseif ( 'trash' === $old_status && 'trash' !== $new_status ) {
            $action = $prefix . '_restored';
            $summary = 'coupon' === $kind
                // translators: %d is the ID of the affected item.
                ? sprintf( __( 'Restored WooCommerce coupon #%d from Trash', 'change-vigil-site-journal' ), $id )
                // translators: %s is the product name.
                : sprintf( __( 'Restored WooCommerce product “%s” from Trash', 'change-vigil-site-journal' ), $name );
            $severity = 'notice';
        } elseif ( is_array( $status ) ) {
            $action = $prefix . '_status_changed';
            $summary = 'coupon' === $kind
                // translators: %1$d is the ID of the affected item; %2$s is the previous status; %3$s is the new status.
                ? sprintf( __( 'Changed WooCommerce coupon #%1$d status from %2$s to %3$s', 'change-vigil-site-journal' ), $id, $old_status, $new_status )
                // translators: %1$s is the product name; %2$s is the previous status; %3$s is the new status.
                : sprintf( __( 'Changed WooCommerce product “%1$s” status from %2$s to %3$s', 'change-vigil-site-journal' ), $name, $old_status, $new_status );
            $severity = 'notice';
        } else {
            $action = $prefix . '_edited';
            if ( 'coupon' === $kind ) {
                // translators: %d is the ID of the affected item.
                $summary = sprintf( __( 'Updated WooCommerce coupon #%d', 'change-vigil-site-journal' ), $id );
            } elseif ( isset( $changes['Title'] ) ) {
                // translators: %s is the product name.
                $summary = sprintf( __( 'Renamed WooCommerce product to “%s”', 'change-vigil-site-journal' ), $name );
            } else {
                // translators: %s is the product name.
                $summary = sprintf( __( 'Updated WooCommerce product “%s”', 'change-vigil-site-journal' ), $name );
            }
            $severity = 'info';
        }
        self::record( $action, $kind, $id, $name, $summary, [ 'changes' => $changes ], $severity );
    }

    private static function woocommerce_setting_value( string $option, mixed $value ): ?string {
        $boolean_options = [
            'woocommerce_enable_coupons', 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax',
            'woocommerce_tax_round_at_subtotal', 'woocommerce_manage_stock', 'woocommerce_hide_out_of_stock_items',
            'woocommerce_enable_reviews', 'woocommerce_enable_review_rating', 'woocommerce_review_rating_required',
            'woocommerce_review_rating_verification_required',
        ];
        if ( in_array( $option, $boolean_options, true ) ) {
            if ( ! is_scalar( $value ) ) {
                return null;
            }
            $normalized = strtolower( trim( (string) $value ) );
            if ( in_array( $normalized, [ 'yes', 'true', '1', 'on' ], true ) ) {
                return __( 'Enabled', 'change-vigil-site-journal' );
            }
            if ( in_array( $normalized, [ '', 'no', 'false', '0', 'off' ], true ) ) {
                return __( 'Disabled', 'change-vigil-site-journal' );
            }
            return null;
        }
        if ( in_array( $option, [ 'woocommerce_notify_low_stock', 'woocommerce_notify_no_stock' ], true ) ) {
            return empty( $value ) ? __( 'Not configured', 'change-vigil-site-journal' ) : __( 'Configured', 'change-vigil-site-journal' );
        }
        if ( in_array( $option, [ 'woocommerce_all_except_countries', 'woocommerce_specific_allowed_countries', 'woocommerce_specific_ship_to_countries' ], true ) ) {
            if ( is_string( $value ) ) {
                $value = preg_split( '/[,\s]+/', $value ) ?: [];
            }
            if ( ! is_array( $value ) ) {
                return null;
            }
            $countries = [];
            foreach ( array_slice( $value, 0, 250 ) as $country ) {
                if ( ! is_scalar( $country ) ) {
                    continue;
                }
                $country = strtoupper( sanitize_key( (string) $country ) );
                if ( 1 === preg_match( '/\A[A-Z]{2}\z/', $country ) ) {
                    $countries[] = $country;
                }
            }
            $countries = array_values( array_unique( $countries ) );
            sort( $countries, SORT_STRING );
            return $countries ? implode( ', ', $countries ) : __( 'None', 'change-vigil-site-journal' );
        }
        if ( in_array( $option, [ 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id' ], true ) ) {
            return is_numeric( $value ) && absint( $value ) > 0
                // translators: %d is the ID or count described by the event summary.
                ? sprintf( __( 'Page ID %d', 'change-vigil-site-journal' ), absint( $value ) )
                : __( 'Not selected', 'change-vigil-site-journal' );
        }
        if ( in_array( $option, [ 'woocommerce_price_num_decimals', 'woocommerce_hold_stock_minutes', 'woocommerce_notify_low_stock_amount', 'woocommerce_notify_no_stock_amount' ], true ) ) {
            return is_numeric( $value ) && abs( (float) $value ) <= 1000000 ? self::text( (string) $value, 40 ) : null;
        }
        if ( 'woocommerce_currency' === $option ) {
            if ( ! is_scalar( $value ) ) {
                return null;
            }
            $currency = strtoupper( sanitize_key( (string) $value ) );
            return 1 === preg_match( '/\A[A-Z]{3}\z/', $currency ) ? $currency : null;
        }
        $enums = [
            'woocommerce_currency_pos' => [ 'left', 'right', 'left_space', 'right_space' ],
            'woocommerce_tax_based_on' => [ 'shipping', 'billing', 'base' ],
            'woocommerce_default_customer_address' => [ '', 'base', 'geolocation', 'geolocation_ajax' ],
            'woocommerce_weight_unit' => [ 'kg', 'g', 'lbs', 'oz' ],
            'woocommerce_dimension_unit' => [ 'm', 'cm', 'mm', 'in', 'yd' ],
            'woocommerce_stock_format' => [ 'no_amount', 'low_amount', 'low_amount_format' ],
            'woocommerce_allowed_countries' => [ 'all', 'all_except', 'specific' ],
            'woocommerce_ship_to_countries' => [ '', 'all', 'specific', 'disabled', 'billing' ],
        ];
        if ( isset( $enums[ $option ] ) ) {
            if ( ! is_scalar( $value ) ) {
                return null;
            }
            $normalized = sanitize_key( (string) $value );
            if ( ! in_array( $normalized, $enums[ $option ], true ) ) {
                return null;
            }
            if ( '' === $normalized ) {
                if ( 'woocommerce_ship_to_countries' === $option ) {
                    return __( 'Same as countries sold to', 'change-vigil-site-journal' );
                }
                if ( 'woocommerce_default_customer_address' === $option ) {
                    return __( 'No location by default', 'change-vigil-site-journal' );
                }
                return __( 'Not set', 'change-vigil-site-journal' );
            }
            return $normalized;
        }
        if ( in_array( $option, [ 'woocommerce_price_thousand_sep', 'woocommerce_price_decimal_sep' ], true ) ) {
            if ( ! is_scalar( $value ) ) {
                return null;
            }
            $value = self::redacted_text( (string) $value, 3 );
            return preg_match( '/[\p{L}\p{N}@]/u', $value ) ? null : $value;
        }
        if ( 'woocommerce_shipping_tax_class' === $option ) {
            if ( ! is_scalar( $value ) ) {
                return null;
            }
            $tax_class = sanitize_key( (string) $value );
            if ( in_array( $tax_class, [ '', 'inherit' ], true ) ) {
                return __( 'Inherit', 'change-vigil-site-journal' );
            }
            return 'standard' === $tax_class ? __( 'Standard', 'change-vigil-site-journal' ) : __( 'Custom tax class', 'change-vigil-site-journal' );
        }
        return null;
    }

    public static function woocommerce_setting_changed( mixed $old, mixed $new, string $option ): void {
        self::record_woocommerce_setting_change( $option, $old, $new );
    }

    public static function woocommerce_setting_added( string $option, mixed $value ): void {
        self::record_woocommerce_setting_change( $option, null, $value );
    }

    private static function record_woocommerce_setting_change( string $option, mixed $old, mixed $new ): void {
        if ( ! isset( self::WOOCOMMERCE_SETTING_OPTIONS[ $option ] ) ) {
            return;
        }
        $before = self::woocommerce_setting_value( $option, $old );
        $after = self::woocommerce_setting_value( $option, $new );
        if ( null === $before || null === $after || $before === $after ) {
            return;
        }
        $label = self::WOOCOMMERCE_SETTING_OPTIONS[ $option ];
        $name = __( 'WooCommerce', 'change-vigil-site-journal' ) . ': ' . $label;
        self::record( 'woocommerce_setting_changed', 'setting', $option, $name,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Changed WooCommerce setting “%s”', 'change-vigil-site-journal' ), $label ),
            [ 'changes' => [ $label => [ $before, $after ] ] ], 'notice' );
    }

    public static function post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( 'nav_menu_item' === $post->post_type ) {
            self::nav_menu_item_saved( $id, $post, $update, $before );
            return;
        }
        if ( self::is_woocommerce_post_type( $post->post_type ) ) {
            self::woocommerce_post( $id, $post, $update, $before );
            return;
        }
        if ( self::wpforms_post_type( $post->post_type ) ) {
            self::wpforms_form_post( $id, $post, $update, $before );
            return;
        }
        if ( self::bbpress_post_kind( $post->post_type ) ) {
            self::bbpress_post( $id, $post, $update, $before );
            return;
        }
        if ( ! self::supports_post_type( $post->post_type )
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }
        $name = self::text( $post->post_title ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
        $details = [];
        if ( ! $update || ! $before || 'auto-draft' === $before->post_status ) {
            $changes = [
                'Title'  => [ '', $name ],
                'Status' => [ '', $post->post_status ],
            ];
            if ( 'future' === $post->post_status ) {
                $changes['Publish date'] = [ '', self::post_date_value( $post ) ];
            }
            /* translators: %s: item title. */
            self::record( 'content_created', $post->post_type, $id, $name, sprintf( __( 'Created “%s”', 'change-vigil-site-journal' ), $name ), [ 'changes' => $changes ] );
            return;
        }
        foreach ( [
            'post_title' => 'Title', 'post_status' => 'Status', 'post_name' => 'Slug',
            'post_parent' => 'Parent item', 'menu_order' => 'Menu order', 'post_author' => 'Author',
            'comment_status' => 'Comments', 'ping_status' => 'Pingbacks',
        ] as $field => $label ) {
            if ( (string) $before->$field !== (string) $post->$field ) {
                $details['changes'][ $label ] = [
                    self::post_field_value( $field, (string) $before->$field ),
                    self::post_field_value( $field, (string) $post->$field ),
                ];
            }
        }
        if ( $before->post_date !== $post->post_date || $before->post_date_gmt !== $post->post_date_gmt ) {
            $previous_date = self::post_date_value( $before );
            $new_date = self::post_date_value( $post );
            if ( $previous_date !== $new_date ) {
                $details['changes']['Publish date'] = [ $previous_date, $new_date ];
            }
        }
        $body_changed = $before->post_content !== $post->post_content || $before->post_excerpt !== $post->post_excerpt;
        if ( $body_changed ) {
            $details['content_changed'] = true;
            $revisions = self::revision_pair( $id, $before, $post );
            if ( $revisions ) {
                $details['revisions'] = $revisions;
            }
        }
        if ( ! $details ) {
            return;
        }

        $action = 'content_edited';
        /* translators: %s: item title. */
        $summary = sprintf( __( 'Edited “%s”', 'change-vigil-site-journal' ), $name );
        if ( 'trash' === $post->post_status && 'trash' !== $before->post_status ) {
            $action = 'content_trashed';
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Moved “%s” to Trash', 'change-vigil-site-journal' ), $name );
        } elseif ( 'trash' === $before->post_status && 'trash' !== $post->post_status ) {
            $action = 'content_restored';
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Restored “%s” from Trash', 'change-vigil-site-journal' ), $name );
        } elseif ( isset( $details['changes']['Status'] ) ) {
            $action = 'content_status';
            $old_label = get_post_status_object( $before->post_status )->label ?? $before->post_status;
            $new_label = get_post_status_object( $post->post_status )->label ?? $post->post_status;
            /* translators: 1: item title, 2: previous status, 3: new status. */
            $summary = sprintf( __( 'Changed “%1$s” from %2$s to %3$s', 'change-vigil-site-journal' ), $name, $old_label, $new_label );
        } elseif ( isset( $details['changes']['Publish date'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the publish date for “%s”', 'change-vigil-site-journal' ), $name );
        } elseif ( isset( $details['changes']['Author'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the author for “%s”', 'change-vigil-site-journal' ), $name );
        } elseif ( isset( $details['changes']['Parent item'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the parent for “%s”', 'change-vigil-site-journal' ), $name );
        } elseif ( isset( $details['changes']['Title'] ) && '' !== $details['changes']['Title'][0] ) {
            /* translators: 1: previous title, 2: new title. */
            $summary = sprintf( __( 'Renamed “%1$s” to “%2$s”', 'change-vigil-site-journal' ), $details['changes']['Title'][0], $name );
        }
        if ( isset( $details['changes']['Title'] ) && '' !== $details['changes']['Title'][0] ) {
            /* translators: 1: previous title, 2: new title. */
            $rename = sprintf( __( 'Renamed “%1$s” to “%2$s”', 'change-vigil-site-journal' ), $details['changes']['Title'][0], $name );
            if ( $summary !== $rename ) {
                $summary = $rename . '. ' . $summary;
            }
        }
        self::record( $action, $post->post_type, $id, $name, $summary, $details, 'content_edited' === $action ? 'info' : 'notice' );
    }

    private static function nav_menu_item_saved( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
            return;
        }
        $changes = [];
        if ( ! $update || ! $before || 'auto-draft' === $before->post_status ) {
            $changes['Menu item'] = [ '', self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' ) ];
        } else {
            foreach ( [ 'post_title' => 'Menu item', 'post_status' => 'Status', 'menu_order' => 'Position' ] as $field => $label ) {
                $old_value = (string) $before->$field;
                $new_value = (string) $post->$field;
                if ( $old_value !== $new_value ) {
                    $changes[ $label ] = [ self::text( $old_value, 180 ), self::text( $new_value, 180 ) ];
                }
            }
        }
        if ( ! $changes ) {
            return;
        }
        self::$pending_menu_items[ $id ] = [
            'action' => ( ! $update || ! $before || 'auto-draft' === $before->post_status ) ? 'menu_item_added' : 'menu_item_updated',
            'changes' => $changes,
            'title' => self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' ),
        ];
    }

    public static function menu_item_saved( int $menu_id, int $menu_item_id, array $args ): void {
        if ( empty( self::$pending_menu_items[ $menu_item_id ] ) || $menu_id < 1 ) {
            return;
        }
        $pending = self::$pending_menu_items[ $menu_item_id ];
        unset( self::$pending_menu_items[ $menu_item_id ] );
        $menu = wp_get_nav_menu_object( $menu_id );
        $menu_name = $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'change-vigil-site-journal' );
        $summary = 'menu_item_added' === $pending['action']
            // translators: %1$s is the menu or menu item name; %2$s is the menu or menu item name.
            ? sprintf( __( 'Added “%1$s” to navigation menu “%2$s”', 'change-vigil-site-journal' ), $pending['title'], $menu_name )
            // translators: %1$s is the menu or menu item name; %2$s is the menu or menu item name.
            : sprintf( __( 'Updated “%1$s” in navigation menu “%2$s”', 'change-vigil-site-journal' ), $pending['title'], $menu_name );
        self::record( $pending['action'], 'menu', $menu_id, $menu_name, $summary,
            [ 'changes' => $pending['changes'] ], 'menu_item_added' === $pending['action'] ? 'notice' : 'info' );
    }

    private static function post_field_value( string $field, string $value ): string {
        if ( 'post_parent' === $field ) {
            $parent_id = absint( $value );
            if ( ! $parent_id ) {
                return __( 'None', 'change-vigil-site-journal' );
            }
            $parent = get_post( $parent_id );
            if ( $parent instanceof \WP_Post ) {
                $title = self::text( $parent->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
                return $title . ' (item ID ' . $parent_id . ')';
            }
            /* translators: %d: affected item ID. */
            return sprintf( __( 'Item ID %d', 'change-vigil-site-journal' ), $parent_id );
        }

        if ( 'post_author' === $field ) {
            $author_id = absint( $value );
            if ( ! $author_id ) {
                return __( 'None', 'change-vigil-site-journal' );
            }
            $author = get_userdata( $author_id );
            if ( $author ) {
                $name = self::text( $author->display_name ?: $author->user_login, 160 );
                return $name . ' (user ID ' . $author_id . ')';
            }
            /* translators: %d: affected user ID. */
            return sprintf( __( 'User ID %d', 'change-vigil-site-journal' ), $author_id );
        }

        return self::text( $value );
    }

    public static function before_delete_post( int $id, \WP_Post $post ): void {
        if ( 'nav_menu_item' === $post->post_type ) {
            $menus = wp_get_object_terms( $id, 'nav_menu', [ 'fields' => 'ids' ] );
            $menu_id = ! is_wp_error( $menus ) && $menus ? absint( $menus[0] ) : 0;
            $menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
            self::$deleting_menu_items[ $id ] = [
                'menu_id' => $menu_id,
                'menu_name' => $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'change-vigil-site-journal' ),
                'title' => self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' ),
            ];
            return;
        }
        if ( self::is_woocommerce_post_type( $post->post_type ) && Store::settings()['woocommerce'] ) {
            $kind = self::woocommerce_kind( $post->post_type );
            self::$deleting_woocommerce_items[ $id ] = [
                'kind' => $kind,
                'name' => self::woocommerce_item_name( $post, $kind ),
            ];
            unset( self::$woocommerce_changes[ $id ] );
            return;
        }
        if ( self::wpforms_post_type( $post->post_type ) ) {
            if ( Store::settings()['wpforms'] ) {
                self::$deleting_wpforms_forms[ $id ] = self::wpforms_form_name( $post );
            }
            return;
        }
        $bbpress_kind = self::bbpress_post_kind( $post->post_type );
        if ( $bbpress_kind ) {
            if ( Store::settings()['bbpress'] ) {
                self::$deleting_bbpress_items[ $id ] = [
                    'kind' => $bbpress_kind,
                    'name' => self::bbpress_item_name( $post, $bbpress_kind ),
                    'changes' => self::bbpress_context_changes( $post, $bbpress_kind ),
                ];
            }
            return;
        }
        if ( self::supports_post_type( $post->post_type ) ) {
            self::$deleting_posts[ $id ] = true;
        }
    }

    public static function deleted_post( int $id, \WP_Post $post ): void {
        if ( isset( self::$deleting_wpforms_forms[ $id ] ) ) {
            $name = self::$deleting_wpforms_forms[ $id ];
            unset( self::$deleting_wpforms_forms[ $id ] );
            self::record( 'wpforms_form_deleted', 'wpforms_form', $id, $name,
                // translators: %s is the form name.
                sprintf( __( 'Deleted WPForms form “%s”', 'change-vigil-site-journal' ), $name ),
                [ 'changes' => [ 'Item' => [ __( 'Existing', 'change-vigil-site-journal' ), __( 'Deleted', 'change-vigil-site-journal' ) ] ] ], 'warning' );
            return;
        }
        if ( isset( self::$deleting_bbpress_items[ $id ] ) ) {
            $item = self::$deleting_bbpress_items[ $id ];
            unset( self::$deleting_bbpress_items[ $id ] );
            $kind = (string) $item['kind'];
            $name = (string) $item['name'];
            $changes = (array) $item['changes'];
            $changes['Item'] = [ __( 'Existing', 'change-vigil-site-journal' ), __( 'Deleted', 'change-vigil-site-journal' ) ];
            self::record( $kind . '_deleted', $kind, $id, $name,
                // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
                sprintf( __( 'Deleted bbPress %1$s “%2$s”', 'change-vigil-site-journal' ), self::bbpress_kind_label( $kind ), $name ),
                [ 'changes' => $changes ], 'warning' );
            return;
        }
        if ( isset( self::$deleting_woocommerce_items[ $id ] ) ) {
            $item = self::$deleting_woocommerce_items[ $id ];
            unset( self::$deleting_woocommerce_items[ $id ] );
            $kind = (string) $item['kind'];
            $name = (string) $item['name'];
            $action = 'coupon' === $kind ? 'coupon_deleted' : 'product_deleted';
            $summary = 'coupon' === $kind
                // translators: %d is the ID of the affected item.
                ? sprintf( __( 'Deleted WooCommerce coupon #%d', 'change-vigil-site-journal' ), $id )
                // translators: %s is the product name.
                : sprintf( __( 'Deleted WooCommerce product “%s”', 'change-vigil-site-journal' ), $name );
            self::record( $action, $kind, $id, $name, $summary,
                [ 'changes' => [ 'Item' => [ __( 'Existing', 'change-vigil-site-journal' ), __( 'Deleted', 'change-vigil-site-journal' ) ] ] ], 'warning' );
            return;
        }
        if ( 'nav_menu_item' !== $post->post_type || empty( self::$deleting_menu_items[ $id ] ) ) {
            return;
        }
        $item = self::$deleting_menu_items[ $id ];
        unset( self::$deleting_menu_items[ $id ] );
        $menu_id = $item['menu_id'] ?: $id;
        self::record( 'menu_item_deleted', 'menu', $menu_id, $item['menu_name'],
            // translators: %1$s is the menu or menu item name; %2$s is the menu or menu item name.
            sprintf( __( 'Removed “%1$s” from navigation menu “%2$s”', 'change-vigil-site-journal' ), $item['title'], $item['menu_name'] ),
            [ 'changes' => [ 'Menu item' => [ $item['title'], '' ] ] ], 'warning' );
    }

    public static function post_meta_before( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        $post = get_post( $post_id );
        if ( $post instanceof \WP_Post && self::is_woocommerce_meta_key( $meta_key ) && null !== self::woocommerce_meta_label( $post_id, $meta_key ) ) {
            $meta = get_metadata_by_mid( 'post', $meta_id );
            self::$woocommerce_meta_before[ $meta_id ] = [
                'post_id' => $post_id,
                'key' => $meta_key,
                'value' => $meta ? $meta->meta_value : get_post_meta( $post_id, $meta_key, true ),
            ];
            return;
        }
        if ( '_menu_item_url' === $meta_key && $post instanceof \WP_Post && 'nav_menu_item' === $post->post_type ) {
            self::$previous_menu_urls[ $meta_id ] = (string) get_post_meta( $post_id, $meta_key, true );
            return;
        }
        $seo_field = self::seo_meta_field( $meta_key );
        if ( $seo_field && $post instanceof \WP_Post ) {
            $meta = get_metadata_by_mid( 'post', $meta_id );
            self::$seo_meta_before[ $meta_id ] = [
                'provider' => $seo_field['provider'],
                'post_id' => $post_id,
                'key' => $meta_key,
                'value' => $meta ? $meta->meta_value : get_post_meta( $post_id, $meta_key, true ),
            ];
            return;
        }
        if ( self::can_capture_post_meta( $post_id, $meta_key ) ) {
            self::$previous_post_meta[ $meta_id ] = get_post_meta( $post_id, $meta_key, true );
        }
    }

    public static function post_meta_updated( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        if ( isset( self::$woocommerce_meta_before[ $meta_id ] ) ) {
            $previous = self::$woocommerce_meta_before[ $meta_id ];
            unset( self::$woocommerce_meta_before[ $meta_id ] );
            self::queue_woocommerce_meta_change( $previous['post_id'], $previous['key'], $previous['value'], $value );
            return;
        }
        if ( array_key_exists( $meta_id, self::$previous_menu_urls ) ) {
            $previous = self::$previous_menu_urls[ $meta_id ];
            unset( self::$previous_menu_urls[ $meta_id ] );
            self::record_menu_destination_change( $post_id, $previous, (string) get_post_meta( $post_id, $meta_key, true ) );
            return;
        }
        if ( array_key_exists( $meta_id, self::$seo_meta_before ) ) {
            $previous = self::$seo_meta_before[ $meta_id ];
            unset( self::$seo_meta_before[ $meta_id ] );
            self::queue_seo_meta_change( $previous['provider'], $previous['post_id'], $previous['key'], $previous['value'], $value );
            return;
        }
        if ( ! array_key_exists( $meta_id, self::$previous_post_meta ) ) {
            return;
        }
        $previous = self::$previous_post_meta[ $meta_id ];
        unset( self::$previous_post_meta[ $meta_id ] );
        self::record_post_meta_change( $post_id, $meta_key, $previous, $value );
    }

    public static function post_meta_added( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        $seo_field = self::seo_meta_field( $meta_key );
        if ( $seo_field ) {
            self::queue_seo_meta_change( $seo_field['provider'], $post_id, $meta_key, null, $value );
            return;
        }
        if ( self::is_woocommerce_meta_key( $meta_key ) && null !== self::woocommerce_meta_label( $post_id, $meta_key ) ) {
            self::queue_woocommerce_meta_change( $post_id, $meta_key, '', $value );
            return;
        }
        self::record_post_meta_change( $post_id, $meta_key, '', $value );
    }

    public static function post_meta_before_delete( array $meta_ids, int $post_id, string $meta_key, mixed $value ): void {
        $seo_field = self::seo_meta_field( $meta_key );
        if ( ! isset( self::POST_META[ $meta_key ] ) && ! self::is_woocommerce_meta_key( $meta_key ) && ! $seo_field ) {
            return;
        }
        $woocommerce_meta = false;
        foreach ( $meta_ids as $meta_id ) {
            $meta = get_metadata_by_mid( 'post', absint( $meta_id ) );
            if ( ! $meta ) {
                continue;
            }
            $target_post_id = (int) $meta->post_id;
            if ( $seo_field && $meta->meta_key === $meta_key
                && ( ! $post_id || $target_post_id === $post_id ) ) {
                self::$seo_meta_deleted[ absint( $meta_id ) ] = [
                    'provider' => $seo_field['provider'],
                    'post_id' => $target_post_id,
                    'key' => $meta_key,
                    'value' => $meta->meta_value,
                ];
                continue;
            }
            if ( $meta->meta_key === $meta_key && null !== self::woocommerce_meta_label( $target_post_id, $meta_key ) ) {
                self::$woocommerce_meta_deleted[ absint( $meta_id ) ] = [
                    'post_id' => $target_post_id,
                    'key' => $meta_key,
                    'value' => $meta->meta_value,
                ];
                $woocommerce_meta = true;
                continue;
            }
            if ( ! isset( self::POST_META[ $meta_key ] ) ) {
                continue;
            }
            if (
                ( $post_id && $target_post_id !== $post_id )
                || $meta->meta_key !== $meta_key
                || ! self::can_capture_post_meta( $target_post_id, $meta_key )
            ) {
                continue;
            }
            self::$previous_deleted_post_meta[ absint( $meta_id ) ] = [
                'post_id' => $target_post_id,
                'key' => $meta_key,
                'value' => $meta->meta_value,
            ];
        }
        if ( $woocommerce_meta ) {
            return;
        }
    }

    public static function post_meta_deleted( array $meta_ids, int $post_id, string $meta_key, mixed $value ): void {
        foreach ( $meta_ids as $meta_id ) {
            $meta_id = absint( $meta_id );
            if ( array_key_exists( $meta_id, self::$seo_meta_deleted ) ) {
                $snapshot = self::$seo_meta_deleted[ $meta_id ];
                unset( self::$seo_meta_deleted[ $meta_id ] );
                self::queue_seo_meta_change( $snapshot['provider'], $snapshot['post_id'], $snapshot['key'], $snapshot['value'], null );
                continue;
            }
            if ( array_key_exists( $meta_id, self::$woocommerce_meta_deleted ) ) {
                $snapshot = self::$woocommerce_meta_deleted[ $meta_id ];
                unset( self::$woocommerce_meta_deleted[ $meta_id ] );
                self::queue_woocommerce_meta_change( $snapshot['post_id'], $snapshot['key'], $snapshot['value'], '' );
                continue;
            }
            if ( ! array_key_exists( $meta_id, self::$previous_deleted_post_meta ) ) {
                continue;
            }
            $snapshot = self::$previous_deleted_post_meta[ $meta_id ];
            unset( self::$previous_deleted_post_meta[ $meta_id ] );
            self::record_post_meta_change( $snapshot['post_id'], $snapshot['key'], $snapshot['value'], '' );
        }
    }

    private static function can_capture_post_meta( int $post_id, string $meta_key ): bool {
        if (
            ! isset( self::POST_META[ $meta_key ] )
            || isset( self::$deleting_posts[ $post_id ] )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $post_id )
        ) {
            return false;
        }
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! self::supports_post_type( $post->post_type )
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
            return false;
        }
        if ( '_wp_page_template' === $meta_key ) {
            return 'page' === $post->post_type || post_type_supports( $post->post_type, 'page-attributes' );
        }
        return post_type_supports( $post->post_type, 'thumbnail' );
    }

    private static function record_menu_destination_change( int $item_id, string $previous, string $value ): void {
        if ( $previous === $value ) {
            return;
        }
        $post = get_post( $item_id );
        if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
            return;
        }
        $menus = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $menus ) || ! $menus ) {
            return;
        }
        $menu_id = absint( $menus[0] );
        $menu = wp_get_nav_menu_object( $menu_id );
        $menu_name = $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'change-vigil-site-journal' );
        $title = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
        self::record( 'menu_item_updated', 'menu', $menu_id, $menu_name,
            // translators: %1$s is the menu or menu item name; %2$s is the menu or menu item name.
            sprintf( __( 'Updated the destination for “%1$s” in navigation menu “%2$s”', 'change-vigil-site-journal' ), $title, $menu_name ),
            [ 'changes' => [ 'Link destination' => [ __( 'Previous destination', 'change-vigil-site-journal' ), __( 'Updated destination', 'change-vigil-site-journal' ) ] ] ], 'info' );
    }

    private static function record_post_meta_change( int $post_id, string $meta_key, mixed $previous, mixed $value ): void {
        if ( ! self::can_capture_post_meta( $post_id, $meta_key ) ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        $label = self::POST_META[ $meta_key ];
        $before = self::post_meta_value( $meta_key, $previous );
        $after = self::post_meta_value( $meta_key, $value );
        if ( $before === $after ) {
            return;
        }
        $name = self::text( $post->post_title ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
        $summary = sprintf(
            /* translators: 1: changed field, 2: page or post title. */
            __( 'Changed %1$s for “%2$s”', 'change-vigil-site-journal' ),
            $label,
            $name
        );
        self::record(
            'content_edited',
            $post->post_type,
            $post_id,
            $name,
            $summary,
            [ 'changes' => [ $label => [ $before, $after ] ] ]
        );
    }

    private static function post_meta_value( string $meta_key, mixed $value ): string {
        if ( '_wp_page_template' === $meta_key ) {
            $template = is_scalar( $value ) ? (string) $value : '';
            return '' === $template || 'default' === $template
                ? __( 'Default', 'change-vigil-site-journal' )
                : self::text( $template );
        }
        $attachment_id = is_numeric( $value ) ? absint( $value ) : 0;
        if ( ! $attachment_id ) {
            return '';
        }
        $attachment = get_post( $attachment_id );
        $title = $attachment instanceof \WP_Post && 'attachment' === $attachment->post_type
            ? self::text( $attachment->post_title )
            : '';
        if ( $title ) {
            /* translators: 1: media item title, 2: media item ID. */
            return sprintf( __( '%1$s (media item %2$d)', 'change-vigil-site-journal' ), $title, $attachment_id );
        }
        /* translators: %d: media item ID. */
        return sprintf( __( 'Media item %d', 'change-vigil-site-journal' ), $attachment_id );
    }

    private static function revision_pair( int $id, \WP_Post $before, \WP_Post $after ): array {
        $new_id = 0;
        // Only link snapshots whose core revision fields match the actual change.
        foreach ( wp_get_post_revisions( $id, [ 'posts_per_page' => 10 ] ) as $revision ) {
            if ( wp_is_post_autosave( $revision ) ) {
                continue;
            }
            if ( ! $new_id && self::matches_revision( $revision, $after ) ) {
                $new_id = $revision->ID;
            } elseif ( $new_id && self::matches_revision( $revision, $before ) ) {
                return [ $revision->ID, $new_id ];
            }
        }
        return [];
    }

    private static function matches_revision( \WP_Post $revision, \WP_Post $snapshot ): bool {
        return $revision->post_title === $snapshot->post_title
            && $revision->post_content === $snapshot->post_content
            && $revision->post_excerpt === $snapshot->post_excerpt;
    }

    private static function taxonomy_label( string $taxonomy ): string {
        $object = get_taxonomy( $taxonomy );
        return $object instanceof \WP_Taxonomy
            ? self::text( $object->labels->singular_name ?: $object->label, 80 )
            : self::text( $taxonomy, 80 );
    }

    private static function term_value( string $taxonomy, int $term_id ): string {
        if ( ! $term_id ) {
            return __( 'None', 'change-vigil-site-journal' );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
            return self::text( $term->name, 180 ) . ' (term ID ' . $term_id . ')';
        }
        /* translators: %d: taxonomy term ID. */
        return sprintf( __( 'Term ID %d', 'change-vigil-site-journal' ), $term_id );
    }

    public static function term_created( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            if ( 'nav_menu' === $taxonomy ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                    $name = self::text( $term->name, 180 );
                    self::record( 'menu_created', 'menu', $term_id, $name,
                        // translators: %s is the menu or menu item name.
                        sprintf( __( 'Created navigation menu “%s”', 'change-vigil-site-journal' ), $name ),
                        [ 'changes' => [ 'Name' => [ '', $name ] ] ], 'notice' );
                }
            }
            return;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
            return;
        }
        $name = self::text( $term->name, 180 );
        self::record( 'term_created', 'term', $taxonomy . ':' . $term_id, $name,
            // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
            sprintf( __( 'Created %1$s “%2$s”', 'change-vigil-site-journal' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ 'Name' => [ '', $name ], 'Slug' => [ '', self::text( $term->slug, 180 ) ] ] ], 'notice' );
    }

    public static function term_before_edit( int $term_id, string $taxonomy, array $args = [] ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $term = get_term( $term_id, $taxonomy );
            if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                self::$previous_terms[ $taxonomy . ':' . $term_id ] = [
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                    'parent' => 0,
                ];
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
            self::$previous_terms[ $taxonomy . ':' . $term_id ] = [
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => (int) $term->parent,
            ];
        }
    }

    public static function term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $key = $taxonomy . ':' . $term_id;
            $before = self::$previous_terms[ $key ] ?? null;
            unset( self::$previous_terms[ $key ] );
            $term = get_term( $term_id, $taxonomy );
            if ( ! $before || ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
                return;
            }
            $changes = [];
            foreach ( [ 'name' => 'Name', 'slug' => 'Slug' ] as $field => $label ) {
                $old_value = self::text( (string) $before[ $field ], 180 );
                $new_value = self::text( (string) $term->$field, 180 );
                if ( $old_value !== $new_value ) {
                    $changes[ $label ] = [ $old_value, $new_value ];
                }
            }
            if ( $changes ) {
                $name = self::text( $term->name, 180 );
                self::record( 'menu_updated', 'menu', $term_id, $name,
                    // translators: %s is the menu or menu item name.
                    sprintf( __( 'Updated navigation menu “%s”', 'change-vigil-site-journal' ), $name ),
                    [ 'changes' => $changes ], 'notice' );
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $key = $taxonomy . ':' . $term_id;
        $before = self::$previous_terms[ $key ] ?? null;
        unset( self::$previous_terms[ $key ] );
        $term = get_term( $term_id, $taxonomy );
        if ( ! $before || ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
            return;
        }
        $changes = [];
        foreach ( [ 'name' => 'Name', 'slug' => 'Slug' ] as $field => $label ) {
            $old_value = self::text( (string) $before[ $field ], 180 );
            $new_value = self::text( (string) $term->$field, 180 );
            if ( $old_value !== $new_value ) {
                $changes[ $label ] = [ $old_value, $new_value ];
            }
        }
        if ( (int) $before['parent'] !== (int) $term->parent ) {
            $changes['Parent'] = [ self::term_value( $taxonomy, (int) $before['parent'] ), self::term_value( $taxonomy, (int) $term->parent ) ];
        }
        if ( ! $changes ) {
            return;
        }
        $name = self::text( $term->name, 180 );
        self::record( 'term_edited', 'term', $key, $name,
            // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
            sprintf( __( 'Updated %1$s “%2$s”', 'change-vigil-site-journal' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => $changes ], 'notice' );
    }

    public static function term_deleted( int $term_id, int $tt_id, string $taxonomy, ?\WP_Term $deleted_term, array $object_ids = [] ): void {
        if ( 'nav_menu' === $taxonomy ) {
            if ( $deleted_term instanceof \WP_Term ) {
                $name = self::text( $deleted_term->name, 180 );
                self::record( 'menu_deleted', 'menu', $term_id, $name,
                    // translators: %s is the menu or menu item name.
                    sprintf( __( 'Deleted navigation menu “%s”', 'change-vigil-site-journal' ), $name ),
                    [ 'changes' => [ 'Name' => [ $name, '' ] ] ], 'warning' );
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) || ! $deleted_term instanceof \WP_Term ) {
            return;
        }
        $name = self::text( $deleted_term->name, 180 );
        self::record( 'term_deleted', 'term', $taxonomy . ':' . $term_id, $name,
            // translators: %1$s is the name or label of the affected record; %2$s is the name or label of the affected record.
            sprintf( __( 'Deleted %1$s “%2$s”', 'change-vigil-site-journal' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ 'Name' => [ $name, '' ], 'Slug' => [ self::text( $deleted_term->slug, 180 ), '' ] ] ], 'warning' );
    }

    private static function term_names_from_taxonomy_ids( array $ids, string $taxonomy ): array {
        $names = [];
        foreach ( $ids as $id ) {
            $term = get_term_by( 'term_taxonomy_id', absint( $id ), $taxonomy );
            if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                $names[] = self::text( $term->name, 120 );
            }
        }
        sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
        return array_values( array_unique( $names ) );
    }

    public static function terms_assigned( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $post = get_post( $object_id );
            if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type || isset( self::$pending_menu_items[ $object_id ] ) ) {
                return;
            }
            $before = self::term_names_from_taxonomy_ids( $old_tt_ids, $taxonomy );
            $after = self::term_names_from_taxonomy_ids( $tt_ids, $taxonomy );
            if ( $before === $after ) {
                return;
            }
            $new_menu = $tt_ids ? get_term_by( 'term_taxonomy_id', absint( reset( $tt_ids ) ), $taxonomy ) : null;
            $old_menu = $old_tt_ids ? get_term_by( 'term_taxonomy_id', absint( reset( $old_tt_ids ) ), $taxonomy ) : null;
            $menu = $new_menu instanceof \WP_Term ? $new_menu : $old_menu;
            $menu_id = $menu instanceof \WP_Term ? (int) $menu->term_id : $object_id;
            $menu_name = $menu instanceof \WP_Term ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'change-vigil-site-journal' );
            $title = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
            self::record( 'menu_item_updated', 'menu', $menu_id, $menu_name,
                // translators: %1$s is the menu or menu item name; %2$s is the menu or menu item name.
                sprintf( __( 'Moved “%1$s” in navigation menu “%2$s”', 'change-vigil-site-journal' ), $title, $menu_name ),
                [ 'changes' => [ 'Navigation menu' => [ implode( ', ', $before ), implode( ', ', $after ) ] ] ], 'notice' );
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $post = get_post( $object_id );
        if ( ! $post instanceof \WP_Post || ! self::supports_post_type( $post->post_type ) ) {
            return;
        }
        $before = self::term_names_from_taxonomy_ids( $old_tt_ids, $taxonomy );
        $after = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'names' ] );
        if ( is_wp_error( $after ) || ! is_array( $after ) ) {
            return;
        }
        $after = array_map( static fn( $name ) => self::text( (string) $name, 120 ), $after );
        sort( $after, SORT_NATURAL | SORT_FLAG_CASE );
        $after = array_values( array_unique( $after ) );
        if ( $before === $after ) {
            return;
        }
        $name = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'change-vigil-site-journal' );
        self::record( 'terms_changed', $post->post_type, $object_id, $name,
            // translators: %1$s is the taxonomy name; %2$s is the affected item name.
            sprintf( __( 'Changed %1$s for “%2$s”', 'change-vigil-site-journal' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ self::taxonomy_label( $taxonomy ) => [ implode( ', ', $before ), implode( ', ', $after ) ] ] ], 'notice' );
    }

    private static function comment_item_name( \WP_Comment $comment ): string {
        $post = get_post( (int) $comment->comment_post_ID );
        $title = $post instanceof \WP_Post ? self::text( $post->post_title, 160 ) : '';
        return $title
            // translators: %s is the name or label of the affected record.
            ? sprintf( __( 'Comment on “%s”', 'change-vigil-site-journal' ), $title )
            // translators: %d is the ID or count described by the event summary.
            : sprintf( __( 'Comment %d', 'change-vigil-site-journal' ), (int) $comment->comment_ID );
    }

    private static function comment_status_label( string $status ): string {
        $labels = [
            'approved' => __( 'Approved', 'change-vigil-site-journal' ),
            'unapproved' => __( 'Pending', 'change-vigil-site-journal' ),
            'spam' => __( 'Spam', 'change-vigil-site-journal' ),
            'trash' => __( 'Trash', 'change-vigil-site-journal' ),
            'post-trashed' => __( 'Parent item trashed', 'change-vigil-site-journal' ),
            'deleted' => __( 'Deleted', 'change-vigil-site-journal' ),
        ];
        return $labels[ $status ] ?? self::text( $status, 40 );
    }

    public static function comment_created( int $comment_id, int|string $approved, array $comment_data = [] ): void {
        $comment = get_comment( $comment_id );
        if ( ! $comment instanceof \WP_Comment ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_created', 'comment', $comment_id, $name,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Added a comment on %s', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ 'Status' => [ '', self::comment_status_label( (string) wp_get_comment_status( $comment ) ) ] ] ], 'notice' );
    }

    public static function comment_before_edit( array|\WP_Error $data, array $comment, array $commentarr ): array|\WP_Error {
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $comment_id = absint( $comment['comment_ID'] ?? 0 );
        if ( $comment_id ) {
            self::$previous_comment_hashes[ $comment_id ] = hash( 'sha256', (string) ( $comment['comment_content'] ?? '' ) );
        }
        return $data;
    }

    public static function comment_edited( int $comment_id ): void {
        if ( ! isset( self::$previous_comment_hashes[ $comment_id ] ) ) {
            return;
        }
        $previous_hash = self::$previous_comment_hashes[ $comment_id ];
        unset( self::$previous_comment_hashes[ $comment_id ] );
        $comment = get_comment( $comment_id );
        if ( ! $comment instanceof \WP_Comment || hash_equals( $previous_hash, hash( 'sha256', (string) $comment->comment_content ) ) ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_edited', 'comment', $comment_id, $name,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Edited a comment on %s', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ 'Comment content' => [ __( 'Previous text', 'change-vigil-site-journal' ), __( 'Updated text', 'change-vigil-site-journal' ) ] ] ], 'info' );
    }

    public static function comment_status_changed( string $new_status, string $old_status, \WP_Comment $comment ): void {
        if ( $new_status === $old_status || 'new' === $old_status ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_status', 'comment', (int) $comment->comment_ID, $name,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Changed comment status on %s', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ 'Status' => [ self::comment_status_label( $old_status ), self::comment_status_label( $new_status ) ] ] ], 'notice' );
    }

    public static function comment_deleted( int $comment_id, \WP_Comment $comment ): void {
        $name = self::comment_item_name( $comment );
        self::record( 'comment_deleted', 'comment', $comment_id, $name,
            // translators: %s is the name or label of the affected record.
            sprintf( __( 'Deleted a comment on %s', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ 'Status' => [ self::comment_status_label( (string) wp_get_comment_status( $comment ) ), __( 'Deleted', 'change-vigil-site-journal' ) ] ] ], 'warning' );
    }

    public static function widget_option_updated( string $option, mixed $old_value, mixed $new_value ): void {
        if ( $old_value === $new_value ) {
            return;
        }
        if ( str_starts_with( $option, 'theme_mods_' ) && is_array( $old_value ) && is_array( $new_value ) ) {
            $before = (array) ( $old_value['nav_menu_locations'] ?? [] );
            $after = (array) ( $new_value['nav_menu_locations'] ?? [] );
            if ( $before !== $after ) {
                $menu_names = static function ( array $locations ): string {
                    $names = [];
                    foreach ( $locations as $location => $menu_id ) {
                        $menu = wp_get_nav_menu_object( absint( $menu_id ) );
                        $names[] = self::text( (string) $location, 80 ) . ': ' . ( $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 120 ) : __( 'None', 'change-vigil-site-journal' ) );
                    }
                    sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
                    return implode( ', ', $names );
                };
                self::record( 'menu_updated', 'menu', 'locations', __( 'Menu locations', 'change-vigil-site-journal' ),
                    __( 'Changed navigation menu locations', 'change-vigil-site-journal' ),
                    [ 'changes' => [ 'Locations' => [ $menu_names( $before ), $menu_names( $after ) ] ] ], 'notice' );
            }
            return;
        }
        if ( 'sidebars_widgets' === $option ) {
            $before = self::sidebar_assignments( $old_value );
            $after = self::sidebar_assignments( $new_value );
            if ( $before === $after ) {
                return;
            }
            self::record( 'widget_changed', 'widget', $option, __( 'Widget placement', 'change-vigil-site-journal' ),
                __( 'Changed widget placement', 'change-vigil-site-journal' ),
                [ 'changes' => [ 'Widget placement' => [ implode( '; ', $before ), implode( '; ', $after ) ] ] ], 'notice' );
            return;
        }
        if ( ! preg_match( '/\Awidget_([a-z0-9_]+)\z/', $option, $matches ) ) {
            return;
        }
        $widget_id = $matches[1];
        $name = self::text( $widget_id, 100 );
        $factory = $GLOBALS['wp_widget_factory'] ?? null;
        if ( $factory && isset( $factory->widgets[ $widget_id ]->name ) ) {
            $name = self::text( $factory->widgets[ $widget_id ]->name, 100 );
        }
        self::record( 'widget_changed', 'widget', $option, $name,
            // translators: %s is the plugin or component name.
            sprintf( __( 'Updated %s settings', 'change-vigil-site-journal' ), $name ), [], 'notice' );
    }

    private static function sidebar_assignments( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $sidebars = (array) ( $GLOBALS['wp_registered_sidebars'] ?? [] );
        $widgets = (array) ( $GLOBALS['wp_registered_widgets'] ?? [] );
        $lines = [];
        foreach ( $value as $sidebar_id => $instances ) {
            if ( ! is_array( $instances ) || 'wp_inactive_widgets' === $sidebar_id || ! $instances ) {
                continue;
            }
            $sidebar_name = self::text( (string) ( $sidebars[ $sidebar_id ]['name'] ?? $sidebar_id ), 100 );
            $widget_names = [];
            foreach ( $instances as $instance ) {
                $widget_names[] = self::text( (string) ( $widgets[ $instance]['name'] ?? $instance ), 100 );
            }
            $lines[] = $sidebar_name . ': ' . implode( ', ', $widget_names );
        }
        sort( $lines, SORT_NATURAL | SORT_FLAG_CASE );
        return $lines;
    }

    public static function user_profile_updated( int $id, \WP_User $old_user ): void {
        $user = get_userdata( $id );
        if ( ! $user ) {
            return;
        }
        $changes = [];
        foreach ( [ 'display_name' => 'Display name' ] as $field => $label ) {
            $old_value = self::text( (string) $old_user->$field, 180 );
            $new_value = self::text( (string) $user->$field, 180 );
            if ( $old_value !== $new_value ) {
                $changes[ $label ] = [ $old_value, $new_value ];
            }
        }
        if ( (string) $old_user->user_email !== (string) $user->user_email ) {
            $changes['Email address'] = [ __( 'Previous address', 'change-vigil-site-journal' ), __( 'Updated address', 'change-vigil-site-journal' ) ];
        }
        if ( $changes ) {
            $name = self::text( $user->user_login, 180 );
            self::record( 'user_profile_changed', 'user', $id, $name,
                // translators: %s is the file path.
                sprintf( __( 'Updated profile for account “%s”', 'change-vigil-site-journal' ), $name ),
                [ 'changes' => $changes ], 'notice' );
        }
        $old_hash = (string) ( $old_user->data->user_pass ?? '' );
        $new_hash = (string) ( $user->data->user_pass ?? '' );
        if ( '' !== $old_hash && '' !== $new_hash && ! hash_equals( $old_hash, $new_hash ) ) {
            self::record( 'password_changed', 'user', $id, self::text( $user->user_login, 180 ),
                // translators: %s is the account name.
                sprintf( __( 'Changed password for account “%s”', 'change-vigil-site-journal' ), self::text( $user->user_login, 180 ) ), [], 'warning' );
        }
    }

    public static function password_reset( \WP_User $user ): void {
        $name = self::text( $user->user_login, 180 );
        self::record( 'password_changed', 'user', $user->ID, $name,
            // translators: %s is the account name.
            sprintf( __( 'Reset password for account “%s”', 'change-vigil-site-journal' ), $name ), [], 'warning' );
    }

    public static function mark_new_user( array $meta, \WP_User $user, bool $update ): array {
        if ( ! $update ) {
            self::$creating_users[ $user->ID ] = true;
        }
        return $meta;
    }

    public static function user_created( int $id ): void {
        unset( self::$creating_users[ $id ] );
        $user = get_userdata( $id );
        if ( $user ) {
            /* translators: %s: username. */
            self::record( 'user_created', 'user', $id, $user->user_login, sprintf( __( 'Created account “%s”', 'change-vigil-site-journal' ), $user->user_login ), [ 'changes' => [ 'Roles' => [ '', implode( ', ', $user->roles ) ] ] ], 'notice' );
        }
    }

    public static function user_deleted( int $id, mixed $reassign, \WP_User $user ): void {
        if ( is_multisite() && get_userdata( $id ) instanceof \WP_User ) {
            /* translators: %s: username. */
            self::record( 'user_removed_from_site', 'user', $id, $user->user_login, sprintf( __( 'Removed account “%s” from this site', 'change-vigil-site-journal' ), $user->user_login ), [], 'warning' );
        } else {
            /* translators: %s: username. */
            self::record( 'user_deleted', 'user', $id, $user->user_login, sprintf( __( 'Deleted account “%s”', 'change-vigil-site-journal' ), $user->user_login ), [], 'warning' );
        }
        unset( self::$deleting_users[ $id ] );
    }

    public static function mark_user_deleting( int $id ): void {
        self::$deleting_users[ $id ] = true;
    }

    public static function roles_before( int $meta_id, int $id, string $key ): void {
        if ( self::is_roles_meta( $key, $id ) ) {
            self::$previous_roles[ $meta_id ] = self::role_names( get_user_meta( $id, $key, true ) );
        }
    }

    private static function is_roles_meta( string $key, int $id ): bool {
        global $wpdb;
        return $key === $wpdb->get_blog_prefix() . 'capabilities'
            && ! isset( self::$creating_users[ $id ] )
            && ! isset( self::$deleting_users[ $id ] );
    }

    private static function role_names( mixed $capabilities ): array {
        $roles = array_keys( array_intersect_key( array_filter( (array) $capabilities ), wp_roles()->roles ) );
        sort( $roles );
        return $roles;
    }

    public static function roles_after( int $meta_id, int $id, string $key, mixed $capabilities ): void {
        if ( ! self::is_roles_meta( $key, $id ) ) {
            return;
        }
        $before = self::$previous_roles[ $meta_id ] ?? [];
        unset( self::$previous_roles[ $meta_id ] );
        $after = self::role_names( $capabilities );
        self::record_role_change( $id, $before, $after );
    }

    public static function roles_before_delete( array $meta_ids, int $id, string $key, mixed $value ): void {
        if ( self::is_roles_meta( $key, $id ) ) {
            self::$previous_deleted_roles[ $id ] = self::role_names( get_user_meta( $id, $key, true ) );
        }
    }

    public static function roles_after_delete( array $meta_ids, int $id, string $key, mixed $value ): void {
        if ( ! self::is_roles_meta( $key, $id ) ) {
            return;
        }
        $before = self::$previous_deleted_roles[ $id ] ?? [];
        unset( self::$previous_deleted_roles[ $id ] );
        $after = self::role_names( get_user_meta( $id, $key, true ) );
        self::record_role_change( $id, $before, $after );
    }

    private static function record_role_change( int $id, array $before, array $after ): void {
        $user = get_userdata( $id );
        if ( $user && $before !== $after ) {
            /* translators: %s: username. */
            self::record( 'role_changed', 'user', $id, $user->user_login, sprintf( __( 'Changed roles for “%s”', 'change-vigil-site-journal' ), $user->user_login ),
                [ 'changes' => [ 'Roles' => [ self::text( implode( ', ', $before ) ), self::text( implode( ', ', $after ) ) ] ] ], 'warning' );
        }
    }

    public static function sign_in( string $login, \WP_User $user ): void {
        self::record( 'signed_in', 'user', $user->ID, $user->user_login, __( 'Signed in to WordPress', 'change-vigil-site-journal' ), [], 'info', self::actor( $user ) );
    }

    public static function sign_out( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        self::record( 'signed_out', 'user', $user_id, $user->user_login, __( 'Signed out of WordPress', 'change-vigil-site-journal' ), [], 'info', self::actor( $user ) );
    }

    public static function failed_sign_in( string $attempt ): void {
        // An arbitrary login input may contain a password or other secret.
        $user = get_user_by( 'login', $attempt ) ?: get_user_by( 'email', $attempt );
        if ( $user ) {
            /* translators: %s: username. */
            self::record( 'sign_in_failed', 'user', $user->ID, $user->user_login, sprintf( __( 'Failed sign in for “%s”', 'change-vigil-site-journal' ), $user->user_login ), [], 'warning', [ 'anonymous', 0, '' ] );
            return;
        }
        self::record( 'sign_in_failed', 'login', 'unknown', __( 'WordPress sign in', 'change-vigil-site-journal' ), __( 'Failed sign in for an unrecognised account', 'change-vigil-site-journal' ), [], 'warning', [ 'anonymous', 0, '' ] );
    }

    public static function plugin_activated( string $file ): void {
        /* translators: %s: plugin name. */
        self::component( 'plugin_activated', 'plugin', $file, __( 'Activated plugin “%s”', 'change-vigil-site-journal' ) );
    }

    public static function plugin_deactivated( string $file ): void {
        /* translators: %s: plugin name. */
        self::component( 'plugin_deactivated', 'plugin', $file, __( 'Deactivated plugin “%s”', 'change-vigil-site-journal' ) );
    }

    public static function plugin_deleted( string $file, bool $deleted ): void {
        if ( $deleted ) {
            /* translators: %s: plugin identifier. */
            self::component( 'plugin_deleted', 'plugin', $file, __( 'Deleted plugin “%s”', 'change-vigil-site-journal' ) );
        }
    }

    public static function theme_switched( string $name, \WP_Theme $theme, \WP_Theme $old ): void {
        /* translators: %s: theme name. */
        self::component( 'theme_switched', 'theme', $theme->get_stylesheet(), __( 'Switched to theme “%s”', 'change-vigil-site-journal' ),
            [ 'changes' => [ 'Theme' => [ self::text( $old->get( 'Name' ) ), self::text( $name ) ] ] ] );
    }

    public static function theme_deleted( string $slug, bool $deleted ): void {
        if ( $deleted ) {
            /* translators: %s: theme identifier. */
            self::component( 'theme_deleted', 'theme', $slug, __( 'Deleted theme “%s”', 'change-vigil-site-journal' ) );
        }
    }

    public static function collect_update( mixed $result, array $options ): mixed {
        if ( true === $result && ! isset( $options['action'] ) && isset( $options['temp_backup'] ) ) {
            foreach ( [ 'plugin', 'theme' ] as $type ) {
                if ( isset( $options[ $type ] ) && is_string( $options[ $type ] ) ) {
                    self::$bulk_updates[ $type ][ $options[ $type ] ] = true;
                }
            }
        }
        return $result;
    }

    public static function upgrade( object $upgrader, array $options ): void {
        $type = $options['type'] ?? '';
        $action = $options['action'] ?? '';
        if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! in_array( $action, [ 'install', 'update' ], true ) ) {
            return;
        }
        if ( ! empty( $options['bulk'] ) && 'update' === $action ) {
            foreach ( (array) ( $options[ $type . 's' ] ?? [] ) as $key ) {
                if ( isset( self::$bulk_updates[ $type ][ $key ] ) ) {
                    self::upgraded_component( $type, $key, 'update' );
                    unset( self::$bulk_updates[ $type ][ $key ] );
                }
            }
            return;
        }
        if ( ! is_array( $upgrader->result ?? null ) ) {
            return;
        }
        $key = $options[ $type ] ?? ( 'plugin' === $type ? $upgrader->plugin_info() : ( $upgrader->result['destination_name'] ?? '' ) );
        if ( is_string( $key ) && '' !== $key ) {
            self::upgraded_component( $type, $key, $action );
        }
    }

    private static function upgraded_component( string $type, string $key, string $action ): void {
        $messages = [
            /* translators: %s: plugin or theme name. */
            'plugin_installed' => __( 'Installed plugin “%s”', 'change-vigil-site-journal' ),
            /* translators: %s: plugin or theme name. */
            'plugin_updated'   => __( 'Updated plugin “%s”', 'change-vigil-site-journal' ),
            /* translators: %s: plugin or theme name. */
            'theme_installed'  => __( 'Installed theme “%s”', 'change-vigil-site-journal' ),
            /* translators: %s: plugin or theme name. */
            'theme_updated'    => __( 'Updated theme “%s”', 'change-vigil-site-journal' ),
        ];
        $event = $type . ( 'install' === $action ? '_installed' : '_updated' );
        self::component( $event, $type, $key, $messages[ $event ] );
    }

    private static function component( string $action, string $kind, string $key, string $format, array $details = [] ): void {
        $name = $key;
        $version = '';
        if ( 'plugin' === $kind && 0 === validate_file( $key ) && is_file( WP_PLUGIN_DIR . '/' . $key ) ) {
            $headers = get_file_data( WP_PLUGIN_DIR . '/' . $key, [ 'name' => 'Plugin Name', 'version' => 'Version' ] );
            $name = $headers['name'] ?: $key;
            $version = $headers['version'];
        } elseif ( 'theme' === $kind ) {
            $theme = wp_get_theme( $key );
            $name = $theme->get( 'Name' ) ?: $key;
            $version = $theme->get( 'Version' );
        }
        if ( $version ) {
            $current_version = self::text( $version, 50 );
            $previous_version = in_array( $action, [ 'plugin_updated', 'theme_updated' ], true )
                ? ( self::$previous_versions[ $kind ][ $key ] ?? '' )
                : '';
            if ( in_array( $action, [ 'plugin_updated', 'theme_updated' ], true ) ) {
                unset( self::$previous_versions[ $kind ][ $key ] );
            }
            if ( '' !== $previous_version && $previous_version !== $current_version ) {
                $details['changes']['Version'] = [ $previous_version, $current_version ];
            } else {
                $details['version'] = $current_version;
            }
        }
        self::record( $action, $kind, $key, $name, sprintf( $format, self::text( $name ) ), $details, 'notice' );
    }

    public static function setting( mixed $old, mixed $new, string $key ): void {
        $name = self::OPTIONS[ $key ];
        $previous = self::setting_value( $key, $old );
        $next = self::setting_value( $key, $new );
        /* translators: %s: setting name. */
        self::record( 'setting_changed', 'setting', $key, $name, sprintf( __( 'Changed setting “%s”', 'change-vigil-site-journal' ), $name ),
            [ 'changes' => [ $name => [ $previous, $next ] ] ], 'notice' );
    }

    public static function plugin_settings_changed( array $old, array $new ): void {
        $before = Store::normalize_settings( $old );
        $after = Store::normalize_settings( $new );
        $labels = [
            'retention' => 'Retention period',
            'erase' => 'Erase data on deletion',
            'woocommerce' => 'WooCommerce recording',
            'acf' => 'Advanced Custom Fields recording',
            'bbpress' => 'bbPress recording',
            'wpforms' => 'WPForms recording',
            'yoast' => 'Yoast SEO recording',
            'rank_math' => 'Rank Math recording',
            'pmpro' => 'Paid Memberships Pro recording',
            'termly' => 'Termly recording',
            'mainwp' => 'MainWP recording',
            'infinitewp' => 'InfiniteWP recording',
            'redirection' => 'Redirection recording',
            'email_alerts' => 'Warning email digests',
            'capture_content' => 'Content recording',
            'capture_accounts' => 'Account recording',
            'capture_comments' => 'Comment recording',
            'capture_taxonomies' => 'Taxonomy recording',
            'capture_appearance' => 'Appearance recording',
            'capture_components' => 'Plugin and theme recording',
            'capture_settings' => 'Site setting recording',
            'capture_files' => 'File monitoring',
            'monitor_uploads' => 'Upload monitoring',
            'file_max_size_mb' => 'Maximum file checksum size',
            'file_scan_interval' => 'File scan frequency',
            'file_excluded_paths' => 'File scan exclusions',
        ];
        $changes = [];
        foreach ( $labels as $key => $label ) {
            if ( $before[ $key ] === $after[ $key ] ) {
                continue;
            }
            if ( in_array( $key, [ 'retention', 'file_max_size_mb' ], true ) ) {
                $unit = 'retention' === $key ? ' days' : ' MB';
                $old_value = $before[ $key ] . $unit;
                $new_value = $after[ $key ] . $unit;
            } elseif ( 'file_scan_interval' === $key ) {
                $old_value = ucfirst( $before[ $key ] );
                $new_value = ucfirst( $after[ $key ] );
            } elseif ( 'file_excluded_paths' === $key ) {
                $old_value = count( $before[ $key ] ) . ' paths';
                $new_value = count( $after[ $key ] ) . ' paths';
            } else {
                $old_value = $before[ $key ] ? __( 'On', 'change-vigil-site-journal' ) : __( 'Off', 'change-vigil-site-journal' );
                $new_value = $after[ $key ] ? __( 'On', 'change-vigil-site-journal' ) : __( 'Off', 'change-vigil-site-journal' );
            }
            $changes[ $label ] = [ (string) $old_value, (string) $new_value ];
        }
        if ( $changes ) {
            self::record( 'plugin_settings_changed', 'setting', 'activity-log-settings', __( 'Change Vigil settings', 'change-vigil-site-journal' ),
                __( 'Changed Change Vigil settings', 'change-vigil-site-journal' ), [ 'changes' => $changes ], 'notice', null, null, $before );
        }
    }

    public static function recording_rules_changed( array $old, array $new ): void {
        $before = Store::normalize_settings( $old );
        $after = Store::normalize_settings( $new );
        $labels = [
            'disabled_actions' => __( 'Disabled event types', 'change-vigil-site-journal' ),
            'excluded_users' => __( 'Excluded accounts', 'change-vigil-site-journal' ),
            'excluded_roles' => __( 'Excluded roles', 'change-vigil-site-journal' ),
            'excluded_item_kinds' => __( 'Excluded item types', 'change-vigil-site-journal' ),
            'excluded_items' => __( 'Excluded items', 'change-vigil-site-journal' ),
            'excluded_post_statuses' => __( 'Excluded content statuses', 'change-vigil-site-journal' ),
        ];
        $changes = [];
        foreach ( $labels as $key => $label ) {
            if ( $before[ $key ] === $after[ $key ] ) {
                continue;
            }
            $changes[ $label ] = [ count( $before[ $key ] ) . ' selected', count( $after[ $key ] ) . ' selected' ];
        }
        if ( ! $changes ) {
            return;
        }
        self::record(
            'recording_rules_changed',
            'setting',
            'recording-rules',
            __( 'Recording rules', 'change-vigil-site-journal' ),
            __( 'Changed activity recording rules', 'change-vigil-site-journal' ),
            [ 'changes' => $changes ],
            'notice',
            null,
            null,
            $before
        );
    }

    public static function plugin_settings_added( string $option, mixed $value ): void {
        if ( Store::SETTINGS === $option && is_array( $value ) ) {
            self::plugin_settings_changed( [], $value );
        }
    }

    private static function setting_value( string $key, mixed $value ): string {
        if ( 'blog_public' === $key ) {
            return (int) $value ? __( 'Search indexing allowed', 'change-vigil-site-journal' ) : __( 'Search indexing discouraged', 'change-vigil-site-journal' );
        }
        if ( in_array( $key, [ 'home', 'siteurl' ], true ) ) {
            $url = wp_parse_url( (string) $value );
            return isset( $url['host'], $url['scheme'] ) && in_array( $url['scheme'], [ 'http', 'https' ], true )
                ? self::text( $url['scheme'] . '://' . $url['host'] . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ) . ( $url['path'] ?? '' ) )
                : '';
        }
        return self::text( (string) $value );
    }

    public static function order_created( int $order_id ): void {
        if ( $order_id < 1 ) {
            return;
        }
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Order #%d', 'change-vigil-site-journal' ), $order_id );
        self::record( 'order_created', 'order', $order_id, $name,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'Created WooCommerce order #%d', 'change-vigil-site-journal' ), $order_id ), [], 'notice' );
    }

    public static function order_status_changed( int $order_id, string $from, string $to ): void {
        if ( $order_id < 1 || $from === $to ) {
            return;
        }
        $from_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( 'wc-' . sanitize_key( $from ) ) : sanitize_key( $from );
        $to_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( 'wc-' . sanitize_key( $to ) ) : sanitize_key( $to );
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Order #%d', 'change-vigil-site-journal' ), $order_id );
        self::record( 'order_status_changed', 'order', $order_id, $name,
            // translators: %1$d is the ID of the affected item; %2$s is the name or label of the affected record; %3$s is the name or label of the affected record.
            sprintf( __( 'Changed WooCommerce order #%1$d from %2$s to %3$s', 'change-vigil-site-journal' ), $order_id, $from_label, $to_label ),
            [ 'changes' => [ 'Status' => [ self::text( (string) $from_label, 80 ), self::text( (string) $to_label, 80 ) ] ] ], 'notice' );
    }

    public static function order_refunded( int $order_id, int $refund_id ): void {
        if ( $order_id < 1 ) {
            return;
        }
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Order #%d', 'change-vigil-site-journal' ), $order_id );
        $details = $refund_id > 0 ? [ 'refund_id' => $refund_id ] : [];
        self::record( 'order_refunded', 'order', $order_id, $name,
            // translators: %d is the ID of the affected item.
            sprintf( __( 'Refunded WooCommerce order #%d', 'change-vigil-site-journal' ), $order_id ), $details, 'warning' );
    }

    public static function order_trashed( int $order_id ): void {
        // translators: %d is the ID of the affected item.
        self::order_lifecycle_event( $order_id, 'order_trashed', __( 'Moved WooCommerce order #%d to Trash', 'change-vigil-site-journal' ), 'warning' );
    }

    public static function order_restored( int $order_id ): void {
        // translators: %d is the ID of the affected item.
        self::order_lifecycle_event( $order_id, 'order_restored', __( 'Restored WooCommerce order #%d from Trash', 'change-vigil-site-journal' ), 'notice' );
    }

    public static function order_deleted( int $order_id ): void {
        // translators: %d is the ID of the affected item.
        self::order_lifecycle_event( $order_id, 'order_deleted', __( 'Deleted WooCommerce order #%d', 'change-vigil-site-journal' ), 'warning' );
    }

    private static function order_lifecycle_event( int $order_id, string $action, string $summary_template, string $severity ): void {
        if ( $order_id < 1 ) {
            return;
        }
        // translators: %d is the ID of the affected item.
        $name = sprintf( __( 'Order #%d', 'change-vigil-site-journal' ), $order_id );
        self::record( $action, 'order', $order_id, $name, sprintf( $summary_template, $order_id ), [], $severity );
    }

    private static function redirect_id( mixed $redirect ): int {
        if ( is_numeric( $redirect ) ) {
            return absint( $redirect );
        }
        if ( is_object( $redirect ) ) {
            foreach ( [ 'id', 'ID' ] as $property ) {
                if ( isset( $redirect->$property ) && is_numeric( $redirect->$property ) ) {
                    return absint( $redirect->$property );
                }
            }
        }
        return 0;
    }

    private static function redirect_event( string $action, mixed $redirect ): void {
        $id = self::redirect_id( $redirect );
        // translators: %d is the ID of the affected item.
        $name = $id ? sprintf( __( 'Redirect #%d', 'change-vigil-site-journal' ), $id ) : __( 'Redirect', 'change-vigil-site-journal' );
        $messages = [
            'redirect_updated' => __( 'Created or updated a Redirection rule', 'change-vigil-site-journal' ),
            'redirect_deleted' => __( 'Deleted a Redirection rule', 'change-vigil-site-journal' ),
            'redirect_enabled' => __( 'Enabled a Redirection rule', 'change-vigil-site-journal' ),
            'redirect_disabled' => __( 'Disabled a Redirection rule', 'change-vigil-site-journal' ),
        ];
        self::record( $action, 'redirect', $id ?: 'unknown', $name,
            $messages[ $action ] . ( $id ? ' #' . $id : '' ), [], 'redirect_deleted' === $action ? 'warning' : 'notice' );
    }

    public static function redirect_updated( int $id ): void {
        self::redirect_event( 'redirect_updated', $id );
    }

    public static function redirect_deleted( mixed $redirect ): void {
        self::redirect_event( 'redirect_deleted', $redirect );
    }

    public static function redirect_enabled( mixed $redirect ): void {
        self::redirect_event( 'redirect_enabled', $redirect );
    }

    public static function redirect_disabled( mixed $redirect ): void {
        self::redirect_event( 'redirect_disabled', $redirect );
    }
}
