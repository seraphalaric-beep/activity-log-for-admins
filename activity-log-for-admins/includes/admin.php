<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Admin {
    private const ROUTE = 'activity-log-for-admins/v1';
    private const CAPABILITY = 'alfa_view_activity';
    private const SLUG = 'activity-log-for-admins';
    private const EXPORT_BATCH_SIZE = 250;
    private static string $plugin_file;
    private static string $screen = '';
    private static string $network_screen = '';

    public static function register( string $plugin_file ): void {
        self::$plugin_file = $plugin_file;
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'network_admin_menu', [ self::class, 'network_menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'network_assets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'dashboard_assets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'toolbar_assets' ] );
        add_action( 'admin_bar_menu', [ self::class, 'toolbar' ], 90 );
        add_action( 'wp_dashboard_setup', [ self::class, 'dashboard_widget' ], 1 );
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        add_filter( 'map_meta_cap', [ self::class, 'map_view_capability' ], 10, 4 );
        add_filter( 'rest_post_dispatch', [ self::class, 'private_rest_response' ], PHP_INT_MAX, 3 );
        add_filter( 'rest_pre_serve_request', [ self::class, 'serve_csv' ], 10, 4 );
        add_filter( 'rest_pre_serve_request', [ self::class, 'serve_html_report' ], 10, 4 );
        add_filter( 'rest_pre_serve_request', [ self::class, 'serve_statistics_download' ], 10, 4 );
        add_filter( 'rest_pre_serve_request', [ self::class, 'private_rest_headers' ], PHP_INT_MAX, 4 );
    }

    public static function menu(): void {
        self::$screen = add_menu_page(
            __( 'Activity Log', 'activity-log-for-admins' ),
            __( 'Activity Log', 'activity-log-for-admins' ),
            self::CAPABILITY, self::SLUG, [ self::class, 'render' ], 'dashicons-list-view', 3
        );
    }

    public static function network_menu(): void {
        if ( ! is_multisite() ) {
            return;
        }
        self::$network_screen = add_menu_page(
            __( 'Network Activity Log', 'activity-log-for-admins' ),
            __( 'Activity Log', 'activity-log-for-admins' ),
            'manage_network', 'activity-log-for-admins-network', [ self::class, 'render_network' ], 'dashicons-list-view', 3
        );
    }

    public static function dashboard_widget(): void {
        if ( ! self::allowed() ) {
            return;
        }

        wp_add_dashboard_widget(
            'alfa_activity_summary',
            __( 'Activity summary', 'activity-log-for-admins' ),
            [ self::class, 'render_dashboard_widget' ],
            null,
            null,
            'normal',
            'high'
        );
    }

    public static function dashboard_assets( string $screen ): void {
        if ( 'index.php' !== $screen || ! self::allowed() ) {
            return;
        }

        $metadata = get_file_data( self::$plugin_file, [ 'version' => 'Version' ] );
        self::theme_assets( $metadata['version'] );
        wp_enqueue_style( 'alfa-dashboard', plugins_url( 'build/dashboard.css', self::$plugin_file ), [ 'alfa-theme' ], self::asset_version( 'dashboard.css', $metadata['version'] ) );
    }

    public static function toolbar_assets(): void {
        if ( ! is_admin() || ! is_admin_bar_showing() || ! self::allowed() ) {
            return;
        }
        $metadata = get_file_data( self::$plugin_file, [ 'version' => 'Version' ] );
        wp_enqueue_style( 'alfa-toolbar', plugins_url( 'build/toolbar.css', self::$plugin_file ), [], self::asset_version( 'toolbar.css', $metadata['version'] ) );
        wp_enqueue_script( 'alfa-toolbar', plugins_url( 'build/toolbar.js', self::$plugin_file ), [], self::asset_version( 'toolbar.js', $metadata['version'] ), true );
        $config = [
            'endpoint' => rest_url( self::ROUTE . '/toolbar/events' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'activityUrl' => add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ),
        ];
        wp_add_inline_script( 'alfa-toolbar', 'window.alfaToolbar = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    }

    public static function toolbar( \WP_Admin_Bar $admin_bar ): void {
        if ( ! is_admin() || ! self::allowed() ) {
            return;
        }
        $activity_url = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
        $admin_bar->add_node( [
            'id' => 'alfa-live-activity',
            'title' => __( 'Activity', 'activity-log-for-admins' ),
            'href' => $activity_url,
            'meta' => [ 'class' => 'alfa-live-activity' ],
        ] );
        $admin_bar->add_node( [
            'id' => 'alfa-live-activity-feed',
            'parent' => 'alfa-live-activity',
            'title' => __( 'Loading recent activity', 'activity-log-for-admins' ),
            'href' => $activity_url,
            'meta' => [ 'class' => 'alfa-live-activity-feed' ],
        ] );
        $admin_bar->add_node( [
            'id' => 'alfa-live-activity-view-all',
            'parent' => 'alfa-live-activity',
            'title' => __( 'View all activity', 'activity-log-for-admins' ),
            'href' => $activity_url,
        ] );
    }

    public static function render_dashboard_widget(): void {
        if ( ! self::allowed() ) {
            return;
        }

        $summary = Store::dashboard_summary();
        if ( is_wp_error( $summary ) ) {
            echo '<div class="alfa-dashboard-widget"><p class="alfa-dashboard-error">' . esc_html__( 'Activity data is unavailable. Check Activity Log settings for diagnostics.', 'activity-log-for-admins' ) . '</p></div>';
            return;
        }

        $activity_url = add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
        echo '<div class="alfa-dashboard-widget">';
        echo '<div class="alfa-dashboard-header"><div><h3 class="alfa-dashboard-overview">' . esc_html__( 'At a glance', 'activity-log-for-admins' ) . '</h3><p class="alfa-dashboard-intro">' . esc_html__( 'Changes recorded across this site.', 'activity-log-for-admins' ) . '</p></div>';
        echo '<a class="button button-primary alfa-dashboard-action" href="' . esc_url( $activity_url ) . '">' . esc_html__( 'View activity', 'activity-log-for-admins' ) . '</a></div>';
        echo '<dl class="alfa-dashboard-metrics">';
        self::dashboard_metric( __( 'Last 24 hours', 'activity-log-for-admins' ), $summary['last_day'] );
        self::dashboard_metric( __( 'Last 7 days', 'activity-log-for-admins' ), $summary['last_week'] );
        self::dashboard_metric( __( 'Warnings in 7 days', 'activity-log-for-admins' ), $summary['warnings'], ! empty( $summary['warnings'] ) );
        echo '</dl>';

        echo '<div class="alfa-dashboard-recent"><h3>' . esc_html__( 'Recent changes', 'activity-log-for-admins' ) . '</h3>';
        if ( empty( $summary['events'] ) ) {
            echo '<p class="alfa-dashboard-empty">' . esc_html__( 'No activity has been recorded yet. New changes will appear here.', 'activity-log-for-admins' ) . '</p>';
        } else {
            echo '<ul>';
            foreach ( $summary['events'] as $event ) {
                $event_url = add_query_arg(
                    [ 'page' => self::SLUG, 'item' => $event['item_kind'] . ':' . $event['item_key'] ],
                    admin_url( 'admin.php' )
                );
                $actor = $event['actor_name'];
                if ( '' === $actor ) {
                    $actor = match ( $event['actor_kind'] ) {
                        'anonymous' => __( 'Unauthenticated', 'activity-log-for-admins' ),
                        'system' => __( 'WordPress background task', 'activity-log-for-admins' ),
                        default => __( 'Unknown', 'activity-log-for-admins' ),
                    };
                }
                $timestamp = strtotime( $event['occurred_at'] . ' UTC' );
                $time = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
                echo '<li><a class="alfa-dashboard-event" href="' . esc_url( $event_url ) . '">';
                echo '<span class="alfa-dashboard-event-summary">' . esc_html( $event['summary'] ) . '</span>';
                echo '<span class="alfa-dashboard-event-meta">' . esc_html( $event['item_name'] ) . ' <span aria-hidden="true">·</span> ' . esc_html( $actor ) . ' <span aria-hidden="true">·</span> <time datetime="' . esc_attr( gmdate( DATE_ATOM, $timestamp ) ) . '">' . esc_html( $time ) . '</time></span>';
                echo '</a></li>';
            }
            echo '</ul>';
        }
        echo '</div></div>';
    }

    private static function dashboard_metric( string $label, int $value, bool $warning = false ): void {
        echo '<div class="alfa-dashboard-metric' . ( $warning ? ' is-warning' : '' ) . '">';
        echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( number_format_i18n( $value ) ) . '</dd>';
        echo '</div>';
    }

    public static function assets( string $screen ): void {
        if ( self::$screen !== $screen ) {
            return;
        }
        self::enqueue_app_assets();
    }

    public static function network_assets( string $screen ): void {
        if ( self::$network_screen !== $screen || ! self::network_allowed() ) {
            return;
        }
        self::enqueue_app_assets( true );
    }

    private static function enqueue_app_assets( bool $network_mode = false ): void {
        $metadata = get_file_data( self::$plugin_file, [ 'version' => 'Version' ] );
        $version = $metadata['version'];
        self::theme_assets( $version );
        wp_enqueue_style( 'alfa-admin', plugins_url( 'build/admin.css', self::$plugin_file ), [ 'alfa-theme' ], self::asset_version( 'admin.css', $version ) );
        wp_enqueue_script( 'alfa-admin', plugins_url( 'build/admin.js', self::$plugin_file ), [ 'react', 'react-dom', 'react-jsx-runtime' ], self::asset_version( 'admin.js', $version ), true );
        $item_labels = [
            'post' => __( 'Post', 'activity-log-for-admins' ),
            'page' => __( 'Page', 'activity-log-for-admins' ),
            'user' => __( 'Account', 'activity-log-for-admins' ),
            'plugin' => __( 'Plugin', 'activity-log-for-admins' ),
            'theme' => __( 'Theme', 'activity-log-for-admins' ),
            'setting' => __( 'Setting', 'activity-log-for-admins' ),
            'login' => __( 'Sign in', 'activity-log-for-admins' ),
            'term' => __( 'Term', 'activity-log-for-admins' ),
            'comment' => __( 'Comment', 'activity-log-for-admins' ),
            'menu' => __( 'Navigation menu', 'activity-log-for-admins' ),
            'widget' => __( 'Widget', 'activity-log-for-admins' ),
            'order' => __( 'WooCommerce order', 'activity-log-for-admins' ),
            'product' => __( 'WooCommerce product', 'activity-log-for-admins' ),
            'coupon' => __( 'WooCommerce coupon', 'activity-log-for-admins' ),
            'forum' => __( 'bbPress forum', 'activity-log-for-admins' ),
            'topic' => __( 'bbPress topic', 'activity-log-for-admins' ),
            'reply' => __( 'bbPress reply', 'activity-log-for-admins' ),
            'redirect' => __( 'Redirect rule', 'activity-log-for-admins' ),
            'table' => __( 'Database table', 'activity-log-for-admins' ),
            'file' => __( 'File', 'activity-log-for-admins' ),
        ];
        if ( is_multisite() ) {
            $item_labels['site'] = __( 'Network site', 'activity-log-for-admins' );
        }
        $content_types = [];
        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type => $object ) {
            if ( in_array( $post_type, [ 'attachment', 'revision', 'nav_menu_item' ], true )
                || ! $object->publicly_queryable || ! $object->show_ui ) {
                continue;
            }
            $item_labels[ $post_type ] = $object->labels->singular_name ?: $object->label;
            if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
                $content_types[] = $post_type;
            }
        }
        $item_labels['product'] = __( 'WooCommerce product', 'activity-log-for-admins' );
        $item_labels['coupon'] = __( 'WooCommerce coupon', 'activity-log-for-admins' );
        $item_labels['forum'] = __( 'bbPress forum', 'activity-log-for-admins' );
        $item_labels['topic'] = __( 'bbPress topic', 'activity-log-for-admins' );
        $item_labels['reply'] = __( 'bbPress reply', 'activity-log-for-admins' );
        $item_labels['wpforms_form'] = __( 'WPForms form', 'activity-log-for-admins' );
        $item_labels['pmpro_level'] = __( 'Membership level', 'activity-log-for-admins' );
        $config = [
            'base' => rest_url( self::ROUTE . ( $network_mode ? '/network/' : '/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'version' => $version,
            'locale' => str_replace( '_', '-', determine_locale() ),
            'timezone' => wp_timezone_string(),
            'actions' => Capture::action_types(),
            'actionLabels' => Store::action_labels(),
            'actionGroups' => Store::event_groups(),
            'severities' => Capture::SEVERITIES,
            'itemLabels' => $item_labels,
            'itemKinds' => Store::available_item_kinds(),
            'contentTypes' => $content_types,
            'canManage' => $network_mode ? self::network_allowed() : self::can_manage(),
            'sessionsAllowed' => ! $network_mode && self::sessions_allowed(),
            'currentUserId' => get_current_user_id(),
            'loginUrl' => wp_login_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
            'roles' => wp_roles()->get_names(),
            'postStatuses' => Store::available_post_statuses(),
            'sources' => Capture::SOURCES,
            'isMultisite' => is_multisite(),
            'mainwpDashboard' => defined( 'MAINWP_PLUGIN_FILE' ),
            'networkMode' => $network_mode,
        ];
        wp_add_inline_script( 'alfa-admin', 'window.alfaSettings = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    }

    private static function theme_assets( string $version ): void {
        wp_enqueue_style( 'alfa-theme', plugins_url( 'build/theme.css', self::$plugin_file ), [], self::asset_version( 'theme.css', $version ) );
    }

    private static function asset_version( string $file, string $version ): string {
        $path = plugin_dir_path( self::$plugin_file ) . 'build/' . $file;
        return is_file( $path ) ? $version . '.' . filemtime( $path ) : $version;
    }

    public static function render(): void {
        if ( ! self::allowed() ) {
            return;
        }
        echo '<div id="alfa-root"><noscript>' . esc_html__( 'Enable JavaScript to use Activity Log.', 'activity-log-for-admins' ) . '</noscript></div>';
    }

    public static function render_network(): void {
        if ( ! self::network_allowed() ) {
            return;
        }
        echo '<div id="alfa-root"><noscript>' . esc_html__( 'Enable JavaScript to use Network Activity Log.', 'activity-log-for-admins' ) . '</noscript></div>';
    }

    public static function allowed(): bool {
        return self::can_view();
    }

    public static function manage_allowed(): bool {
        return self::can_manage();
    }

    public static function sessions_allowed(): bool {
        return self::can_manage() && ( ! is_multisite() || is_super_admin() );
    }

    public static function network_allowed(): bool {
        return is_multisite() && is_super_admin();
    }

    public static function map_view_capability( array $caps, string $cap, int $user_id, array $args ): array {
        if ( self::CAPABILITY === $cap ) {
            return self::can_view( $user_id ) ? [ 'read' ] : [ 'do_not_allow' ];
        }
        return $caps;
    }

    public static function can_view( int $user_id = 0 ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        if ( ! $user instanceof \WP_User || ! $user->exists() ) {
            return false;
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }
        $settings = Store::settings();
        return in_array( (int) $user->ID, $settings['viewer_users'], true )
            || in_array( (int) $user->ID, $settings['manager_users'], true )
            || (bool) array_intersect( $user->roles, array_merge( $settings['viewer_roles'], $settings['manager_roles'] ) );
    }

    public static function can_manage( int $user_id = 0 ): bool {
        $user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
        if ( ! $user instanceof \WP_User || ! $user->exists() ) {
            return false;
        }
        if ( user_can( $user, 'manage_options' ) ) {
            return true;
        }
        $settings = Store::settings();
        return in_array( (int) $user->ID, $settings['manager_users'], true )
            || (bool) array_intersect( $user->roles, $settings['manager_roles'] );
    }

    public static function routes(): void {
        $read = [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'allowed' ] ];
        $manage_read = [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'manage_allowed' ] ];
        register_rest_route( self::ROUTE, '/events', $read + [ 'callback' => [ self::class, 'events' ] ] );
        register_rest_route( self::ROUTE, '/toolbar/events', $read + [ 'callback' => [ self::class, 'toolbar_events' ] ] );
        register_rest_route( self::ROUTE, '/events/(?P<id>[1-9][0-9]*)', $read + [ 'callback' => [ self::class, 'event' ] ] );
        register_rest_route( self::ROUTE, '/events/(?P<id>[1-9][0-9]*)/notes', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'add_note' ],
            'args' => [
                'note' => [ 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 2000 ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/export', $read + [ 'callback' => [ self::class, 'export' ] ] );
        register_rest_route( self::ROUTE, '/report', $read + [
            'callback' => [ self::class, 'report' ],
            'args' => [
                'title' => [ 'type' => 'string', 'maxLength' => 120 ],
                'columns' => [ 'type' => 'string', 'maxLength' => 255 ],
                'branding' => [ 'type' => 'string', 'enum' => [ '0', '1' ], 'default' => '0' ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/reports/statistics', $read + [
            'callback' => [ self::class, 'statistics' ],
            'args' => [
                'from' => [ 'type' => 'string', 'format' => 'date', 'required' => true ],
                'to' => [ 'type' => 'string', 'format' => 'date', 'required' => true ],
                'group' => [ 'type' => 'string', 'enum' => [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ], 'default' => 'category' ],
                'interval' => [ 'type' => 'string', 'enum' => [ 'daily', 'weekly', 'monthly' ], 'default' => 'daily' ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/reports/saved', [
            [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'allowed' ], 'callback' => [ self::class, 'saved_reports' ] ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => [ self::class, 'save_report_template' ],
                'args' => [
                    'name' => [ 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 100 ],
                    'filters' => [ 'type' => 'object', 'required' => true ],
                    'group' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ] ],
                    'interval' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'daily', 'weekly', 'monthly' ] ],
                    'range_days' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 3650 ],
                    'columns' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => Store::REPORT_COLUMNS ] ],
                    'branding' => [ 'type' => 'boolean' ],
                    'tags' => [ 'type' => 'array', 'maxItems' => 5, 'items' => [ 'type' => 'string', 'maxLength' => 30 ] ],
                ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/reports/saved/(?P<id>[0-9a-f-]{36})/delete', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'delete_report_template' ],
        ] );
        register_rest_route( self::ROUTE, '/reports/saved/(?P<id>[0-9a-f-]{36})/statistics/download', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => [ self::class, 'allowed' ],
            'callback' => [ self::class, 'saved_statistics_download' ],
            'args' => [ 'format' => [ 'type' => 'string', 'enum' => [ 'csv', 'html' ], 'default' => 'csv' ] ],
        ] );
        register_rest_route( self::ROUTE, '/reports/schedules', [
            [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'manage_allowed' ], 'callback' => [ ScheduledReports::class, 'list_schedules' ] ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => [ self::class, 'create_report_schedule' ],
                'args' => [
                    'template_id' => [ 'type' => 'string', 'required' => true, 'maxLength' => 36 ],
                    'cadence' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'daily', 'weekly', 'monthly', 'quarterly' ] ],
                    'hour' => [ 'type' => 'integer', 'required' => true, 'minimum' => 0, 'maximum' => 23 ],
                    'format' => [ 'type' => 'string', 'enum' => [ 'csv', 'html' ], 'default' => 'csv' ],
                    'recipients' => [ 'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 10, 'items' => [ 'type' => 'string', 'format' => 'email', 'maxLength' => 254 ] ],
                ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/reports/schedules/(?P<id>[0-9a-f-]{36})/delete', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'delete_report_schedule' ],
        ] );
        register_rest_route( self::ROUTE, '/reports/history/(?P<id>[0-9a-f-]{36})/delete', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'delete_report_history' ],
        ] );
        register_rest_route( self::ROUTE, '/alerts/channels', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => static fn(): array => [ 'channels' => Alerts::channel_summary() ],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => [ self::class, 'save_alert_channels' ],
                'args' => [
                    'slack_webhook' => [ 'type' => 'string', 'maxLength' => 500 ],
                    'clear_slack' => [ 'type' => 'boolean' ],
                    'twilio_account_sid' => [ 'type' => 'string', 'maxLength' => 34 ],
                    'twilio_auth_token' => [ 'type' => 'string', 'maxLength' => 256 ],
                    'sms_from' => [ 'type' => 'string', 'maxLength' => 34 ],
                    'sms_recipients' => [ 'type' => 'array', 'maxItems' => 10, 'items' => [ 'type' => 'string', 'maxLength' => 16, 'pattern' => '^\\+[1-9][0-9]{6,14}$' ], 'uniqueItems' => true ],
                    'clear_sms' => [ 'type' => 'boolean' ],
                ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/alerts/channels/test', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'test_alert_channel' ],
            'args' => [ 'channel' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'slack', 'sms' ] ] ],
        ] );
        register_rest_route( self::ROUTE, '/alerts/rules', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => static fn(): array => [ 'rules' => Alerts::rules(), 'fields' => Alerts::fields(), 'presets' => Alerts::presets() ],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => [ self::class, 'save_alert_rule' ],
                'args' => self::alert_rule_args(),
            ],
        ] );
        register_rest_route( self::ROUTE, '/alerts/rules/(?P<id>[0-9a-f-]{36})/toggle', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'toggle_alert_rule' ],
            'args' => [ 'enabled' => [ 'type' => 'boolean', 'required' => true ] ],
        ] );
        register_rest_route( self::ROUTE, '/alerts/rules/(?P<id>[0-9a-f-]{36})/delete', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'delete_alert_rule' ],
        ] );
        register_rest_route( self::ROUTE, '/settings/export', $manage_read + [ 'callback' => [ self::class, 'export_settings' ] ] );
        register_rest_route( self::ROUTE, '/settings/import', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'manage_allowed' ],
            'callback' => [ self::class, 'import_settings' ],
        ] );
        register_rest_route( self::ROUTE, '/settings', [
            $manage_read + [ 'callback' => [ self::class, 'settings' ] ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'permission_callback' => [ self::class, 'manage_allowed' ],
                'callback' => [ self::class, 'save' ],
                'args' => [
                    'retention' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 3650 ],
                    'erase' => [ 'type' => 'boolean', 'required' => true ],
                    'woocommerce' => [ 'type' => 'boolean' ],
                    'redirection' => [ 'type' => 'boolean' ],
                    'acf' => [ 'type' => 'boolean' ],
                    'bbpress' => [ 'type' => 'boolean' ],
                    'wpforms' => [ 'type' => 'boolean' ],
                    'yoast' => [ 'type' => 'boolean' ],
                    'rank_math' => [ 'type' => 'boolean' ],
                    'pmpro' => [ 'type' => 'boolean' ],
                    'termly' => [ 'type' => 'boolean' ],
                    'mainwp' => [ 'type' => 'boolean' ],
                    'infinitewp' => [ 'type' => 'boolean' ],
                    'email_alerts' => [ 'type' => 'boolean' ],
                    'email_alert_include_events' => [ 'type' => 'boolean' ],
                    'email_alert_event_limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
                    'email_alert_recipients' => [ 'type' => 'array', 'maxItems' => 10, 'items' => [ 'type' => 'string', 'format' => 'email', 'maxLength' => 254 ], 'uniqueItems' => true ],
                    'email_alert_categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( Store::event_groups() ) ], 'uniqueItems' => true ],
                    'capture_content' => [ 'type' => 'boolean' ],
                    'capture_accounts' => [ 'type' => 'boolean' ],
                    'capture_comments' => [ 'type' => 'boolean' ],
                    'capture_taxonomies' => [ 'type' => 'boolean' ],
                    'capture_appearance' => [ 'type' => 'boolean' ],
                    'capture_components' => [ 'type' => 'boolean' ],
                    'capture_settings' => [ 'type' => 'boolean' ],
                    'capture_network' => [ 'type' => 'boolean' ],
                    'capture_database' => [ 'type' => 'boolean' ],
                    'capture_files' => [ 'type' => 'boolean' ],
                    'monitor_uploads' => [ 'type' => 'boolean' ],
                    'file_max_size_mb' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
                    'file_scan_interval' => [ 'type' => 'string', 'enum' => [ 'daily', 'weekly' ] ],
                    'file_excluded_paths' => [ 'type' => 'array', 'maxItems' => 25, 'items' => [ 'type' => 'string', 'maxLength' => 255 ], 'uniqueItems' => true ],
                    'viewer_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( wp_roles()->roles ) ], 'uniqueItems' => true ],
                    'manager_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( wp_roles()->roles ) ], 'uniqueItems' => true ],
                    'viewer_users' => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'uniqueItems' => true ],
                    'manager_users' => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'uniqueItems' => true ],
                    'exclude_system_activity' => [ 'type' => 'boolean' ],
                    'excluded_users' => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'uniqueItems' => true ],
                    'excluded_roles' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => array_keys( wp_roles()->roles ) ], 'uniqueItems' => true ],
                    'excluded_item_kinds' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => Store::available_item_kinds() ], 'uniqueItems' => true ],
                    'excluded_items' => [ 'type' => 'array', 'maxItems' => 100, 'items' => [ 'type' => 'string', 'maxLength' => 232 ], 'uniqueItems' => true ],
                    'excluded_post_statuses' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => Store::available_post_statuses() ], 'uniqueItems' => true ],
                    'disabled_actions' => [
                        'type' => 'array',
                        'items' => [ 'type' => 'string', 'enum' => Capture::action_types() ],
                        'uniqueItems' => true,
                    ],
                ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/users', $manage_read + [ 'callback' => [ self::class, 'user_options' ] ] );
        register_rest_route( self::ROUTE, '/sessions', [
            'methods' => \WP_REST_Server::READABLE,
            'permission_callback' => [ self::class, 'sessions_allowed' ],
            'callback' => [ Sessions::class, 'list' ],
            'args' => [
                'page' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                'search' => [ 'type' => 'string', 'maxLength' => 160 ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/sessions/settings', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'permission_callback' => [ self::class, 'sessions_allowed' ],
                'callback' => static fn(): array => [ 'settings' => Sessions::settings() ],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'permission_callback' => [ self::class, 'sessions_allowed' ],
                'callback' => [ Sessions::class, 'save_settings' ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/sessions/(?P<user_id>[1-9][0-9]*)/(?P<reference>[a-f0-9]{64})/revoke', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'sessions_allowed' ],
            'callback' => [ Sessions::class, 'revoke_one' ],
        ] );
        register_rest_route( self::ROUTE, '/sessions/(?P<user_id>[1-9][0-9]*)/revoke-all', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'sessions_allowed' ],
            'callback' => [ Sessions::class, 'revoke_all' ],
        ] );

        $network_read = [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'network_allowed' ] ];
        register_rest_route( self::ROUTE, '/network/events', $network_read + [ 'callback' => [ self::class, 'network_events' ] ] );
        register_rest_route( self::ROUTE, '/network/events/(?P<site_id>[1-9][0-9]*)/(?P<id>[1-9][0-9]*)', $network_read + [ 'callback' => [ self::class, 'network_event' ] ] );
        register_rest_route( self::ROUTE, '/network/events/(?P<site_id>[1-9][0-9]*)/(?P<id>[1-9][0-9]*)/notes', [
            'methods' => \WP_REST_Server::CREATABLE,
            'permission_callback' => [ self::class, 'network_allowed' ],
            'callback' => [ self::class, 'network_add_note' ],
            'args' => [ 'note' => [ 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 2000 ] ],
        ] );
        register_rest_route( self::ROUTE, '/network/export', $network_read + [ 'callback' => [ self::class, 'network_export' ] ] );
        register_rest_route( self::ROUTE, '/network/sites', $network_read + [
            'callback' => [ self::class, 'network_sites' ],
            'args' => [
                'page' => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
                'search' => [ 'type' => 'string', 'maxLength' => 160 ],
            ],
        ] );
        register_rest_route( self::ROUTE, '/network/sites/(?P<site_id>[1-9][0-9]*)/settings', [
            [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'network_allowed' ], 'callback' => [ self::class, 'network_site_settings' ] ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'permission_callback' => [ self::class, 'network_allowed' ],
                'callback' => [ self::class, 'save_network_site_settings' ],
                'args' => [
                    'retention' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 3650 ],
                    'categories' => [ 'type' => 'object' ],
                ],
            ],
        ] );
    }

    public static function user_options( \WP_REST_Request $request ): array|\WP_Error {
        $search = $request->get_param( 'search' );
        $include = $request->get_param( 'include' );
        if ( ( null !== $search && ( ! is_string( $search ) || strlen( $search ) > 160 ) )
            || ( null !== $include && ( ! is_string( $include ) || strlen( $include ) > 5000 || ! preg_match( '/\A[1-9][0-9]*(?:,[1-9][0-9]*){0,199}\z/', $include ) ) ) ) {
            return self::bad_filters();
        }
        $include_ids = null === $include || '' === $include ? [] : array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $include ) ) ) ) );
        if ( count( $include_ids ) > 200 ) {
            return self::bad_filters();
        }
        $selected = [];
        if ( $include_ids ) {
            $selected_query = new \WP_User_Query( [
                'include' => $include_ids,
                'number' => 200,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => [ 'ID', 'display_name', 'user_login' ],
            ] );
            $selected = array_map( [ self::class, 'present_user_option' ], $selected_query->get_results() );
        }
        $matches = [];
        $search = is_string( $search ) ? trim( str_replace( '*', '', sanitize_text_field( $search ) ) ) : '';
        if ( strlen( $search ) >= 2 ) {
            $match_query = new \WP_User_Query( [
                'search' => '*' . $search . '*',
                'search_columns' => [ 'user_login', 'display_name' ],
                'number' => 20,
                'orderby' => 'display_name',
                'order' => 'ASC',
                'fields' => [ 'ID', 'display_name', 'user_login' ],
            ] );
            $matches = array_map( [ self::class, 'present_user_option' ], $match_query->get_results() );
        }
        return [ 'selected' => $selected, 'matches' => $matches ];
    }

    private static function present_user_option( object $user ): array {
        return [
            'id' => (int) $user->ID,
            'name' => sanitize_text_field( $user->display_name ),
            'login' => sanitize_user( $user->user_login ),
        ];
    }

    private static function filters( \WP_REST_Request $request, bool $network = false ): array|\WP_Error {
        $filters = [];
        $keys = [ 'q', 'event_id', 'actor', 'actor_id', 'actor_kind', 'actor_role', 'source', 'item', 'item_kind', 'item_id', 'action', 'category', 'severity', 'from', 'to', 'site_id' ];
        if ( $network ) {
            $keys[] = 'network_site_id';
        }
        foreach ( $keys as $key ) {
            $value = $request->get_param( $key );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( ! is_string( $value ) || 1 !== preg_match( '/\A.{0,255}\z/us', $value ) ) {
                return self::bad_filters();
            }
            $filters[ $key ] = sanitize_text_field( $value );
        }
        if ( isset( $filters['actor_id'] ) ) {
            $normalized_id = ltrim( (string) (int) $filters['actor_id'], '0' );
            if ( ! ctype_digit( $filters['actor_id'] ) || (int) $filters['actor_id'] < 1
                || $normalized_id !== ltrim( $filters['actor_id'], '0' ) ) {
                return self::bad_filters();
            }
        }
        if ( isset( $filters['event_id'] ) ) {
            $normalized_id = (string) (int) $filters['event_id'];
            if ( ! ctype_digit( $filters['event_id'] ) || (int) $filters['event_id'] < 1
                || $normalized_id !== $filters['event_id'] ) {
                return self::bad_filters();
            }
        }
        foreach ( [ 'site_id', 'network_site_id' ] as $site_key ) {
            if ( ! isset( $filters[ $site_key ] ) ) {
                continue;
            }
            $normalized_id = ltrim( (string) (int) $filters[ $site_key ], '0' );
            if ( ! ctype_digit( $filters[ $site_key ] ) || (int) $filters[ $site_key ] < 1
                || $normalized_id !== ltrim( $filters[ $site_key ], '0' ) ) {
                return self::bad_filters();
            }
        }
        foreach ( [ 'from', 'to' ] as $key ) {
            if ( isset( $filters[ $key ] ) ) {
                $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters[ $key ], wp_timezone() );
                if ( ! $date || $date->format( 'Y-m-d' ) !== $filters[ $key ] ) {
                    return self::bad_filters();
                }
            }
        }
        if ( ( isset( $filters['from'], $filters['to'] ) && $filters['from'] > $filters['to'] )
            || ( isset( $filters['action'] ) && ( $network
                ? 1 !== preg_match( '/\A[a-z0-9_]{1,40}\z/', $filters['action'] )
                : ! in_array( $filters['action'], Capture::action_types(), true ) ) )
            || ( isset( $filters['severity'] ) && ! in_array( $filters['severity'], Capture::SEVERITIES, true ) )
            || ( isset( $filters['category'] ) && ( $network
                ? ! array_key_exists( $filters['category'], Store::event_groups() )
                : ! array_key_exists( $filters['category'], Store::event_groups() ) ) )
            || ( isset( $filters['actor_role'] ) && 1 !== preg_match( '/\A[a-z0-9_-]{1,64}\z/', $filters['actor_role'] ) )
            || ( isset( $filters['source'] ) && ! in_array( $filters['source'], Capture::SOURCES, true ) )
            || ( isset( $filters['item_kind'] ) && ! in_array( $filters['item_kind'], Store::available_item_kinds(), true ) )
            || ( isset( $filters['item_id'] ) && ( strlen( $filters['item_id'] ) > 191 || preg_match( '/[\x00-\x1f\x7f]/', $filters['item_id'] ) ) )
            || ( isset( $filters['actor_kind'] ) && ! in_array( $filters['actor_kind'], [ 'user', 'anonymous', 'system', 'cli', 'unknown' ], true ) )
            || ( isset( $filters['actor'], $filters['actor_kind'] ) && 'user' !== $filters['actor_kind'] )
            || ( isset( $filters['actor_id'], $filters['actor'] ) )
            || ( isset( $filters['actor_id'], $filters['actor_kind'] ) && 'user' !== $filters['actor_kind'] ) ) {
            return self::bad_filters();
        }
        return $filters;
    }

    private static function bad_filters(): \WP_Error {
        return new \WP_Error( 'alfa_filters', __( 'Check the search filters and date range.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
    }

    public static function events( \WP_REST_Request $request ): array|\WP_Error {
        $filters = self::filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $before = $request->get_param( 'before' );
        if ( null !== $before && ( ! is_scalar( $before ) || ! ctype_digit( (string) $before ) ) ) {
            return self::bad_filters();
        }
        $rows = Store::query( $filters, Store::PAGE_SIZE + 1, (int) $before );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $has_more = count( $rows ) > Store::PAGE_SIZE;
        if ( $has_more ) {
            array_pop( $rows );
        }
        return [
            'events' => array_map( [ self::class, 'present' ], $rows ),
            'next' => $has_more ? (string) end( $rows )['id'] : null,
        ];
    }

    public static function toolbar_events( \WP_REST_Request $request ): array|\WP_Error {
        $rows = Store::query( [], 6 );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $events = [];
        foreach ( array_slice( $rows, 0, 5 ) as $row ) {
            $timestamp = strtotime( (string) $row['occurred_at'] . ' UTC' );
            $actor = (string) $row['actor_name'];
            if ( '' === $actor ) {
                $actor = match ( $row['actor_kind'] ) {
                    'anonymous' => __( 'Unauthenticated', 'activity-log-for-admins' ),
                    'system' => __( 'WordPress background task', 'activity-log-for-admins' ),
                    'cli' => __( 'WP CLI command', 'activity-log-for-admins' ),
                    default => __( 'Unknown', 'activity-log-for-admins' ),
                };
            }
            $events[] = [
                'id' => (int) $row['id'],
                'summary' => sanitize_text_field( (string) $row['summary'] ),
                'item_name' => sanitize_text_field( (string) $row['item_name'] ),
                'actor_name' => sanitize_text_field( $actor ),
                'time' => wp_date( 'j M, H:i', $timestamp ),
                'url' => add_query_arg( [ 'page' => self::SLUG, 'event' => (int) $row['id'] ], admin_url( 'admin.php' ) ),
            ];
        }
        return [ 'events' => $events ];
    }

    public static function network_events( \WP_REST_Request $request ): array|\WP_Error {
        if ( ! self::network_allowed() ) {
            return new \WP_Error( 'alfa_network_forbidden', __( 'Network activity is available to network administrators only.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }
        $filters = self::filters( $request, true );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $cursor = null;
        $before = $request->get_param( 'before' );
        if ( null !== $before ) {
            $cursor = self::decode_network_cursor( $before );
            if ( ! $cursor ) {
                return self::bad_filters();
            }
        }
        $rows = Store::network_query( $filters, Store::PAGE_SIZE + 1, $cursor );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $has_more = count( $rows ) > Store::PAGE_SIZE;
        if ( $has_more ) {
            array_pop( $rows );
        }
        return [
            'events' => array_map( [ self::class, 'present' ], $rows ),
            'next' => $has_more ? self::encode_network_cursor( end( $rows ) ) : null,
        ];
    }

    public static function network_event( \WP_REST_Request $request ): array|\WP_Error {
        $site_id = absint( $request['site_id'] );
        if ( ! self::network_site_is_current( $site_id ) || ! switch_to_blog( $site_id ) ) {
            return new \WP_Error( 'alfa_not_found', __( 'This event is no longer available.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        try {
            $row = Store::event( absint( $request['id'] ) );
            if ( is_wp_error( $row ) ) {
                return $row;
            }
            if ( ! empty( $row['details']['revisions'] ) ) {
                [ $from, $to ] = $row['details']['revisions'];
                if ( (int) wp_is_post_revision( $from ) === (int) $row['item_key']
                    && (int) wp_is_post_revision( $to ) === (int) $row['item_key']
                    && current_user_can( 'edit_post', (int) $row['item_key'] ) ) {
                    $row['revision_url'] = add_query_arg( [ 'from' => $from, 'revision' => $to ], admin_url( 'revision.php' ) );
                }
                unset( $row['details']['revisions'] );
            }
            $row['network_site_id'] = (string) $site_id;
            $row['network_site_name'] = sanitize_text_field( get_bloginfo( 'name' ) );
            $row['network_site_timezone'] = wp_timezone_string() ?: 'UTC';
            return self::present( $row );
        } finally {
            restore_current_blog();
        }
    }

    public static function network_add_note( \WP_REST_Request $request ): array|\WP_Error {
        $site_id = absint( $request['site_id'] );
        if ( ! self::network_site_is_current( $site_id ) || ! switch_to_blog( $site_id ) ) {
            return new \WP_Error( 'alfa_not_found', __( 'This event is no longer available.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        try {
            $note = $request->get_param( 'note' );
            if ( ! is_string( $note ) ) {
                return new \WP_Error( 'alfa_note_invalid', __( 'Enter a note with 1 to 2000 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            return Store::add_note( absint( $request['id'] ), $note );
        } finally {
            restore_current_blog();
        }
    }

    public static function network_export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        if ( ! self::network_allowed() ) {
            return new \WP_Error( 'alfa_network_forbidden', __( 'Network activity is available to network administrators only.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }
        $filters = self::filters( $request, true );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $ceiling = gmdate( 'Y-m-d H:i:s' );
        $first = Store::network_query( $filters, self::EXPORT_BATCH_SIZE, null, true, $ceiling );
        if ( is_wp_error( $first ) ) {
            return $first;
        }
        $response = new \WP_REST_Response( [ 'network' => true, 'filters' => $filters, 'ceiling' => $ceiling, 'first' => $first ] );
        $response->header( 'Content-Type', 'text/csv; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="activity-log-network-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $response->header( 'Cache-Control', 'no-store, private' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function network_sites( \WP_REST_Request $request ): array|\WP_Error {
        if ( ! self::network_allowed() ) {
            return new \WP_Error( 'alfa_network_forbidden', __( 'Network settings are available to network administrators only.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }
        $page = absint( $request->get_param( 'page' ) ?: 1 );
        $search = sanitize_text_field( (string) $request->get_param( 'search' ) );
        if ( $page < 1 || $page > 100000 || strlen( $search ) > 160 ) {
            return self::bad_filters();
        }
        $args = [
            'network_id' => get_current_network_id(),
            'number' => 51,
            'offset' => ( $page - 1 ) * 50,
            'orderby' => 'id',
            'order' => 'ASC',
        ];
        if ( '' !== $search && ! ctype_digit( $search ) ) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = [ 'domain', 'path' ];
        }
        if ( ctype_digit( $search ) && '' !== $search ) {
            $site = get_site( absint( $search ) );
            $sites = self::network_site_is_current( absint( $search ) ) ? [ $site ] : [];
            $total = count( $sites );
            $page = 1;
        } else {
            $sites = get_sites( $args );
            $count_args = $args;
            unset( $count_args['number'], $count_args['offset'], $count_args['orderby'], $count_args['order'] );
            $count_args['count'] = true;
            $total = (int) get_sites( $count_args );
        }
        $has_more = count( $sites ) > 50;
        if ( $has_more ) {
            array_pop( $sites );
        }
        $results = [];
        foreach ( $sites as $site ) {
            $site_id = (int) $site->blog_id;
            $summary = self::network_site_summary( $site_id );
            if ( ! is_wp_error( $summary ) ) {
                $results[] = $summary;
            }
        }
        return [ 'sites' => $results, 'page' => $page, 'total' => $total, 'has_more' => $has_more ];
    }

    public static function network_site_settings( \WP_REST_Request $request ): array|\WP_Error {
        $site_id = absint( $request['site_id'] );
        if ( ! self::network_site_is_current( $site_id ) ) {
            return new \WP_Error( 'alfa_not_found', __( 'This network site was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        return self::network_site_summary( $site_id );
    }

    public static function save_network_site_settings( \WP_REST_Request $request ): array|\WP_Error {
        $site_id = absint( $request['site_id'] );
        if ( ! self::network_site_is_current( $site_id ) || ! switch_to_blog( $site_id ) ) {
            return new \WP_Error( 'alfa_not_found', __( 'This network site was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        try {
            $input = $request->get_json_params();
            if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'retention', 'categories' ] ) ) {
                return new \WP_Error( 'alfa_network_settings_invalid', __( 'Check the network site settings.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $table = Store::table();
            global $wpdb;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return new \WP_Error( 'alfa_storage_unavailable', __( 'Activity storage is not installed for this site.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            $settings = Store::settings();
            if ( array_key_exists( 'retention', $input ) ) {
                if ( ! is_int( $input['retention'] ) || $input['retention'] < 1 || $input['retention'] > 3650 ) {
                    return new \WP_Error( 'alfa_network_settings_invalid', __( 'Retention must be between 1 and 3650 days.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                $settings['retention'] = $input['retention'];
            }
            if ( array_key_exists( 'categories', $input ) ) {
                if ( ! is_array( $input['categories'] ) ) {
                    return new \WP_Error( 'alfa_network_settings_invalid', __( 'Choose valid event categories.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                $category_settings = self::network_category_settings();
                foreach ( $input['categories'] as $category => $enabled ) {
                    if ( ! is_string( $category ) || ! array_key_exists( $category, $category_settings ) || ! is_bool( $enabled ) ) {
                        return new \WP_Error( 'alfa_network_settings_invalid', __( 'Choose valid event categories.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                    }
                    $settings[ $category_settings[ $category ] ] = $enabled;
                }
            }
            $saved = self::persist_settings( $settings );
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            return self::network_site_summary( $site_id );
        } finally {
            restore_current_blog();
        }
    }

    private static function network_site_summary( int $site_id ): array|\WP_Error {
        if ( ! self::network_site_is_current( $site_id ) || ! switch_to_blog( $site_id ) ) {
            return new \WP_Error( 'alfa_not_found', __( 'This network site was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        try {
            global $wpdb;
            $table = Store::table();
            $available = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
            $settings = Store::settings();
            $health = Store::health();
            $categories = [];
            foreach ( self::network_category_settings() as $category => $setting ) {
                if ( array_key_exists( $category, Store::event_groups() ) ) {
                    $categories[ $category ] = [
                        'label' => self::network_category_labels()[ $category ] ?? $category,
                        'enabled' => (bool) $settings[ $setting ],
                    ];
                }
            }
            return [
                'id' => $site_id,
                'name' => sanitize_text_field( get_bloginfo( 'name' ) ),
                'address' => sanitize_text_field( (string) get_site_url() ),
                'dashboard_url' => esc_url_raw( get_admin_url( $site_id, 'admin.php?page=' . self::SLUG ) ),
                'retention' => (int) $settings['retention'],
                'categories' => $categories,
                'storage_available' => $available,
                'oldest_event' => $available ? $health['oldest'] : null,
                'last_cleanup' => $health['last_cleanup'] ? (int) $health['last_cleanup'] : null,
                'recording_failure' => (bool) $health['write_error'],
                'cleanup_failure' => (bool) $health['cleanup_error'],
            ];
        } finally {
            restore_current_blog();
        }
    }

    /** @return array<string, string> */
    private static function network_category_settings(): array {
        return [
            'content' => 'capture_content', 'accounts' => 'capture_accounts', 'comments' => 'capture_comments',
            'taxonomies' => 'capture_taxonomies', 'appearance' => 'capture_appearance', 'components' => 'capture_components',
            'settings' => 'capture_settings', 'woocommerce' => 'woocommerce', 'redirection' => 'redirection',
            'acf' => 'acf', 'bbpress' => 'bbpress', 'wpforms' => 'wpforms', 'yoast' => 'yoast', 'rank_math' => 'rank_math',
            'pmpro' => 'pmpro', 'termly' => 'termly', 'mainwp' => 'mainwp', 'infinitewp' => 'infinitewp',
            'network' => 'capture_network', 'database' => 'capture_database', 'files' => 'capture_files',
        ];
    }

    /** @return array<string, string> */
    private static function network_category_labels(): array {
        return [
            'content' => __( 'Posts and pages', 'activity-log-for-admins' ), 'accounts' => __( 'Accounts and sign ins', 'activity-log-for-admins' ),
            'comments' => __( 'Comments', 'activity-log-for-admins' ), 'taxonomies' => __( 'Categories and tags', 'activity-log-for-admins' ),
            'appearance' => __( 'Menus and widgets', 'activity-log-for-admins' ), 'components' => __( 'Plugins and themes', 'activity-log-for-admins' ),
            'settings' => __( 'Site settings', 'activity-log-for-admins' ), 'woocommerce' => __( 'WooCommerce', 'activity-log-for-admins' ),
            'redirection' => __( 'Redirection', 'activity-log-for-admins' ), 'acf' => __( 'Advanced Custom Fields', 'activity-log-for-admins' ),
            'bbpress' => __( 'bbPress', 'activity-log-for-admins' ), 'wpforms' => __( 'WPForms', 'activity-log-for-admins' ),
            'yoast' => __( 'Yoast SEO', 'activity-log-for-admins' ), 'rank_math' => __( 'Rank Math', 'activity-log-for-admins' ),
            'pmpro' => __( 'Paid Memberships Pro', 'activity-log-for-admins' ), 'termly' => __( 'Termly', 'activity-log-for-admins' ),
            'mainwp' => __( 'MainWP', 'activity-log-for-admins' ), 'infinitewp' => __( 'InfiniteWP', 'activity-log-for-admins' ),
            'network' => __( 'Network sites', 'activity-log-for-admins' ), 'database' => __( 'Database changes', 'activity-log-for-admins' ),
            'files' => __( 'File changes', 'activity-log-for-admins' ),
        ];
    }

    private static function network_site_is_current( int $site_id ): bool {
        if ( ! self::network_allowed() ) {
            return false;
        }
        $site = get_site( $site_id );
        return $site instanceof \WP_Site && (int) $site->site_id === get_current_network_id()
            && ! (int) $site->deleted && ! (int) $site->archived && ! (int) $site->spam;
    }

    private static function encode_network_cursor( array $row ): string {
        $json = wp_json_encode( [ 'time' => (string) $row['occurred_at'], 'site' => (int) $row['network_site_id'], 'id' => (int) $row['id'] ] );
        return rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
    }

    private static function decode_network_cursor( mixed $value ): ?array {
        if ( ! is_string( $value ) || strlen( $value ) > 160 || ! preg_match( '/\A[A-Za-z0-9_-]+\z/', $value ) ) {
            return null;
        }
        $json = base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
        $cursor = is_string( $json ) ? json_decode( $json, true ) : null;
        if ( ! is_array( $cursor ) || ! is_string( $cursor['time'] ?? null ) || ! preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $cursor['time'] )
            || ! is_int( $cursor['site'] ?? null ) || $cursor['site'] < 1 || ! is_int( $cursor['id'] ?? null ) || $cursor['id'] < 1 ) {
            return null;
        }
        return $cursor;
    }

    private static function present( array $row ): array {
        $row['id'] = (string) $row['id'];
        $row['actor_id'] = (string) $row['actor_id'];
        $row['origin_site_id'] = (string) ( $row['origin_site_id'] ?? 0 );
        $row['category'] = Store::action_category( (string) $row['action_key'] ) ?? 'other';
        $timestamp = strtotime( $row['occurred_at'] . ' UTC' );
        $row['time_iso'] = gmdate( DATE_ATOM, $timestamp );
        $timezone = isset( $row['network_site_timezone'] ) ? new \DateTimeZone( $row['network_site_timezone'] ) : wp_timezone();
        $row['time_short'] = wp_date( 'j M Y, H:i', $timestamp, $timezone );
        $row['time_exact'] = wp_date( 'j M Y, H:i:s T', $timestamp, $timezone );
        return $row;
    }

    public static function event( \WP_REST_Request $request ): array|\WP_Error {
        $row = Store::event( (int) $request['id'] );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        if ( ! empty( $row['details']['revisions'] ) ) {
            [ $from, $to ] = $row['details']['revisions'];
            if ( (int) wp_is_post_revision( $from ) === (int) $row['item_key']
                && (int) wp_is_post_revision( $to ) === (int) $row['item_key']
                && current_user_can( 'edit_post', (int) $row['item_key'] ) ) {
                $row['revision_url'] = add_query_arg( [ 'from' => $from, 'revision' => $to ], admin_url( 'revision.php' ) );
            }
            unset( $row['details']['revisions'] );
        }
        return self::present( $row );
    }

    public static function add_note( \WP_REST_Request $request ): array|\WP_Error {
        $note = $request->get_param( 'note' );
        if ( ! is_string( $note ) ) {
            return new \WP_Error( 'alfa_note_invalid', __( 'Enter a note with 1 to 2000 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        return Store::add_note( (int) $request['id'], $note );
    }

    public static function settings(): array {
        $health = Store::health();
        if ( $health['oldest'] ) {
            $health['oldest'] = wp_date( 'j M Y, H:i T', strtotime( $health['oldest'] . ' UTC' ) );
        }
        foreach ( [ 'last_cleanup', 'write_error', 'schema_error', 'cleanup_error', 'schedule_error', 'alert_error', 'alert_schedule_error', 'alert_rule_error', 'report_schedule_error', 'next_cleanup', 'file_scan_last', 'file_scan_error', 'file_schedule_error', 'next_file_scan' ] as $key ) {
            $health[ $key ] = $health[ $key ] ? wp_date( 'j M Y, H:i T', (int) $health[ $key ] ) : null;
        }
        $health['space'] = null === $health['bytes'] ? null : size_format( $health['bytes'], 1 );
        return [ 'settings' => Store::settings(), 'health' => $health ];
    }

    public static function save( \WP_REST_Request $request ): array|\WP_Error {
        $settings = Store::settings();
        $excluded_items = $request->get_param( 'excluded_items' );
        if ( null !== $excluded_items && ( ! is_array( $excluded_items ) || ! array_is_list( $excluded_items ) || count( $excluded_items ) > 100 || Store::normalize_excluded_items( $excluded_items ) !== $excluded_items ) ) {
            return new \WP_Error( 'alfa_excluded_items', __( 'Choose up to 100 valid item exclusions.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $settings['retention'] = (int) $request['retention'];
        $settings['erase'] = rest_sanitize_boolean( $request['erase'] );
        foreach ( [ 'woocommerce', 'redirection', 'acf', 'bbpress', 'wpforms', 'yoast', 'rank_math', 'pmpro', 'termly', 'mainwp', 'infinitewp', 'email_alerts', 'email_alert_include_events', 'capture_content', 'capture_accounts', 'capture_comments', 'capture_taxonomies', 'capture_appearance', 'capture_components', 'capture_settings', 'capture_network', 'capture_database', 'capture_files', 'monitor_uploads', 'exclude_system_activity' ] as $key ) {
            if ( null !== $request->get_param( $key ) ) {
                $settings[ $key ] = rest_sanitize_boolean( $request->get_param( $key ) );
            }
        }
        foreach ( [ 'file_max_size_mb', 'file_scan_interval', 'file_excluded_paths', 'email_alert_event_limit', 'email_alert_recipients', 'email_alert_categories' ] as $key ) {
            if ( null !== $request->get_param( $key ) ) {
                $settings[ $key ] = $request->get_param( $key );
            }
        }
        if ( null !== $request->get_param( 'disabled_actions' ) ) {
            $settings['disabled_actions'] = (array) $request->get_param( 'disabled_actions' );
        }
        foreach ( [ 'viewer_roles', 'manager_roles', 'viewer_users', 'manager_users', 'excluded_users', 'excluded_roles', 'excluded_item_kinds', 'excluded_items', 'excluded_post_statuses' ] as $key ) {
            if ( null !== $request->get_param( $key ) ) {
                $settings[ $key ] = (array) $request->get_param( $key );
            }
        }
        $saved = self::persist_settings( $settings );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        return self::settings();
    }

    public static function export_settings(): array {
        $metadata = get_file_data( self::$plugin_file, [ 'version' => 'Version' ] );
        return [
            'plugin' => 'activity-log-for-admins',
            'format' => 'activity-log-for-admins-settings',
            'schema_version' => 1,
            'plugin_version' => $metadata['version'],
            'exported_at' => gmdate( DATE_ATOM ),
            'settings' => Store::exportable_settings(),
        ];
    }

    public static function import_settings( \WP_REST_Request $request ): array|\WP_Error {
        $payload = $request->get_json_params();
        $root_keys = [ 'plugin', 'format', 'schema_version', 'plugin_version', 'exported_at', 'settings', 'confirm_prune', 'confirm_erase' ];
        if ( ! is_array( $payload ) || array_diff( array_keys( $payload ), $root_keys )
            || 'activity-log-for-admins' !== ( $payload['plugin'] ?? '' )
            || 'activity-log-for-admins-settings' !== ( $payload['format'] ?? '' )
            || 1 !== ( $payload['schema_version'] ?? null )
            || ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
            return new \WP_Error( 'alfa_settings_import', __( 'This file is not a supported Activity Log settings export.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }

        foreach ( [ 'confirm_prune', 'confirm_erase' ] as $confirmation ) {
            if ( isset( $payload[ $confirmation ] ) && ! is_bool( $payload[ $confirmation ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings confirmation is invalid.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
        }

        $settings = $payload['settings'];
        $allowed_settings = array_keys( Store::exportable_settings() );
        $file_defaults = [
            'pmpro' => false,
            'termly' => false,
            'mainwp' => false,
            'infinitewp' => false,
            'capture_files' => false,
            'monitor_uploads' => false,
            'file_max_size_mb' => 10,
            'file_scan_interval' => 'daily',
            'file_excluded_paths' => [],
            'email_alert_include_events' => true,
            'email_alert_event_limit' => 10,
            'email_alert_categories' => array_keys( Store::event_groups() ),
        ];
        foreach ( $file_defaults as $key => $default ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                $settings[ $key ] = $default;
            }
        }
        $required_settings = array_diff( $allowed_settings, array_keys( $file_defaults ) );
        if ( array_diff( array_keys( $settings ), $allowed_settings ) || array_diff( $required_settings, array_keys( $settings ) )
            || ! isset( $settings['retention'] ) || ! is_int( $settings['retention'] ) || $settings['retention'] < 1 || $settings['retention'] > 3650
            || ! isset( $settings['disabled_actions'] ) || ! is_array( $settings['disabled_actions'] ) || ! array_is_list( $settings['disabled_actions'] ) ) {
            return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains missing or unsupported values.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }

        foreach ( array_diff( $allowed_settings, [ 'retention', 'file_max_size_mb', 'file_scan_interval', 'file_excluded_paths', 'email_alert_event_limit', 'email_alert_categories', 'disabled_actions', 'viewer_roles', 'manager_roles', 'excluded_roles', 'excluded_item_kinds', 'excluded_post_statuses' ] ) as $key ) {
            if ( ! isset( $settings[ $key ] ) || ! is_bool( $settings[ $key ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains an invalid option.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
        }
        if ( ! isset( $settings['file_max_size_mb'] ) || ! is_int( $settings['file_max_size_mb'] ) || $settings['file_max_size_mb'] < 1 || $settings['file_max_size_mb'] > 50
            || ! isset( $settings['file_scan_interval'] ) || ! in_array( $settings['file_scan_interval'], [ 'daily', 'weekly' ], true )
            || ! isset( $settings['file_excluded_paths'] ) || ! is_array( $settings['file_excluded_paths'] ) || ! array_is_list( $settings['file_excluded_paths'] )
            || count( $settings['file_excluded_paths'] ) > 25 || Store::normalize_file_excluded_paths( $settings['file_excluded_paths'] ) !== $settings['file_excluded_paths'] ) {
            return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains invalid file monitoring options.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        if ( ! isset( $settings['email_alert_event_limit'] ) || ! is_int( $settings['email_alert_event_limit'] ) || $settings['email_alert_event_limit'] < 1 || $settings['email_alert_event_limit'] > 20
            || ! isset( $settings['email_alert_categories'] ) || ! is_array( $settings['email_alert_categories'] ) || ! array_is_list( $settings['email_alert_categories'] ) ) {
            return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains invalid notification options.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $seen_alert_categories = [];
        foreach ( $settings['email_alert_categories'] as $category ) {
            if ( ! is_string( $category ) || ! array_key_exists( $category, Store::event_groups() ) || isset( $seen_alert_categories[ $category ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains an unsupported notification category.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $seen_alert_categories[ $category ] = true;
        }
        $seen_actions = [];
        foreach ( $settings['disabled_actions'] as $action ) {
            if ( ! is_string( $action ) || ! in_array( $action, Capture::action_types(), true ) || isset( $seen_actions[ $action ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains an unsupported event type.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $seen_actions[ $action ] = true;
        }
        $available_roles = array_keys( wp_roles()->roles );
        foreach ( [ 'viewer_roles', 'manager_roles' ] as $key ) {
            if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) || ! array_is_list( $settings[ $key ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains invalid access roles.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $seen_roles = [];
            foreach ( $settings[ $key ] as $role ) {
                if ( ! is_string( $role ) || ! in_array( $role, $available_roles, true ) || isset( $seen_roles[ $role ] ) ) {
                    return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains an unsupported access role.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                $seen_roles[ $role ] = true;
            }
        }
        foreach ( [
            'excluded_roles' => array_keys( wp_roles()->roles ),
            'excluded_item_kinds' => Store::available_item_kinds(),
            'excluded_post_statuses' => Store::available_post_statuses(),
        ] as $key => $allowed_values ) {
            if ( ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) || ! array_is_list( $settings[ $key ] ) ) {
                return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains invalid exclusion rules.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $seen_values = [];
            foreach ( $settings[ $key ] as $value ) {
                if ( ! is_string( $value ) || ! in_array( $value, $allowed_values, true ) || isset( $seen_values[ $value ] ) ) {
                    return new \WP_Error( 'alfa_settings_import', __( 'The settings file contains an unsupported exclusion rule.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                $seen_values[ $value ] = true;
            }
        }

        $current = Store::settings();
        if ( $settings['retention'] < $current['retention'] && empty( $payload['confirm_prune'] ) ) {
            return new \WP_Error( 'alfa_settings_prune_confirmation', __( 'Confirm that the shorter retention period may delete older events before importing these settings.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        if ( ! $current['erase'] && $settings['erase'] && empty( $payload['confirm_erase'] ) ) {
            return new \WP_Error( 'alfa_settings_erase_confirmation', __( 'Confirm the uninstall erase option before importing these settings.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }

        $settings['excluded_users'] = Store::settings()['excluded_users'];
        $settings['excluded_items'] = Store::settings()['excluded_items'];
        $settings['viewer_users'] = Store::settings()['viewer_users'];
        $settings['manager_users'] = Store::settings()['manager_users'];
        $settings['email_alert_recipients'] = Store::settings()['email_alert_recipients'];
        $saved = self::persist_settings( $settings );
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        return self::settings();
    }

    private static function persist_settings( array $settings ): true|\WP_Error {
        $previous_settings = Store::settings();
        $settings = Store::normalize_settings( $settings );
        $changed = $settings !== $previous_settings;
        if ( $changed && ! update_option( Store::SETTINGS, $settings, false ) && $settings !== Store::settings() ) {
            return new \WP_Error( 'alfa_settings', __( 'Settings could not be saved. Try again.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        if ( $changed ) {
            Capture::recording_rules_changed( $previous_settings, $settings );
        }
        if ( $changed && $settings['retention'] < $previous_settings['retention'] ) {
            Store::cleanup();
        }
        if ( ! $settings['email_alerts'] ) {
            wp_clear_scheduled_hook( Store::ALERT_DIGEST );
        }
        Store::schedule();
        return true;
    }

    public static function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $filters = self::filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $ceiling = Store::latest_id();
        if ( is_wp_error( $ceiling ) ) {
            return $ceiling;
        }
        $first = $ceiling ? Store::query( $filters, self::EXPORT_BATCH_SIZE, 0, $ceiling, true ) : [];
        if ( is_wp_error( $first ) ) {
            return $first;
        }
        $response = new \WP_REST_Response( [ 'filters' => $filters, 'ceiling' => $ceiling, 'first' => $first ] );
        $response->header( 'Content-Type', 'text/csv; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="activity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
        $response->header( 'Cache-Control', 'no-store, private' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function report( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $filters = self::filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $columns = self::report_columns( $request );
        if ( is_wp_error( $columns ) ) {
            return $columns;
        }
        $title = sanitize_text_field( (string) $request->get_param( 'title' ) );
        $title = '' !== $title ? wp_html_excerpt( $title, 120, '' ) : __( 'Activity report', 'activity-log-for-admins' );
        $ceiling = Store::latest_id();
        if ( is_wp_error( $ceiling ) ) {
            return $ceiling;
        }
        $first = $ceiling ? Store::query( $filters, self::EXPORT_BATCH_SIZE, 0, $ceiling, true ) : [];
        if ( is_wp_error( $first ) ) {
            return $first;
        }
        $response = new \WP_REST_Response( [
            'filters' => $filters,
            'ceiling' => $ceiling,
            'first' => $first,
            'title' => $title,
            'columns' => $columns,
            'branding' => '1' === (string) $request->get_param( 'branding' ),
        ] );
        $response->header( 'Content-Type', 'text/html; charset=UTF-8' );
        $response->header( 'Content-Disposition', 'attachment; filename="activity-report-' . gmdate( 'Y-m-d' ) . '.html"' );
        $response->header( 'Cache-Control', 'no-store, private' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }

    public static function statistics( \WP_REST_Request $request ): array|\WP_Error {
        $filters = self::filters( $request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        if ( empty( $filters['from'] ) || empty( $filters['to'] ) ) {
            return self::bad_filters();
        }
        if ( $filters['to'] > wp_date( 'Y-m-d' ) ) {
            return self::bad_filters();
        }
        $start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters['from'], wp_timezone() );
        $end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters['to'], wp_timezone() )->modify( '+1 day' );
        $days = $start->diff( $end )->days;
        if ( false === $days || $days > Store::settings()['retention'] ) {
            return new \WP_Error( 'alfa_report_range', __( 'Choose a date range within the retention period configured for this site.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $group = sanitize_key( (string) $request->get_param( 'group' ) );
        $interval = sanitize_key( (string) $request->get_param( 'interval' ) );
        if ( ! in_array( $group, [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ], true )
            || ! in_array( $interval, [ 'daily', 'weekly', 'monthly' ], true ) ) {
            return self::bad_filters();
        }
        return Store::report_statistics( $filters, $group, $interval );
    }

    public static function saved_reports(): array {
        return [ 'reports' => Store::saved_reports() ];
    }

    private static function report_columns( \WP_REST_Request $request ): array|\WP_Error {
        $value = $request->get_param( 'columns' );
        if ( null === $value ) {
            return Store::REPORT_COLUMNS;
        }
        if ( ! is_string( $value ) || strlen( $value ) > 255 ) {
            return self::bad_filters();
        }
        $columns = explode( ',', $value );
        if ( ! $columns || count( $columns ) > count( Store::REPORT_COLUMNS ) ) {
            return self::bad_filters();
        }
        foreach ( $columns as $column ) {
            if ( '' === $column || ! in_array( $column, Store::REPORT_COLUMNS, true ) ) {
                return self::bad_filters();
            }
        }
        return array_values( array_unique( $columns ) );
    }

    public static function save_report_template( \WP_REST_Request $request ): array|\WP_Error {
        $name = sanitize_text_field( (string) $request->get_param( 'name' ) );
        $filter_values = $request->get_param( 'filters' );
        $group = sanitize_key( (string) $request->get_param( 'group' ) );
        $interval = sanitize_key( (string) $request->get_param( 'interval' ) );
        $range_days = (int) $request->get_param( 'range_days' );
        $columns = $request->get_param( 'columns' );
        $columns = null === $columns ? Store::REPORT_COLUMNS : $columns;
        $branding = $request->get_param( 'branding' );
        $tags = $request->get_param( 'tags' );
        $tags = null === $tags ? [] : $tags;
        $valid_keys = [ 'q', 'from', 'to', 'event_id', 'actor', 'actor_id', 'actor_kind', 'actor_role', 'source', 'item', 'item_kind', 'item_id', 'action', 'category', 'severity', 'site_id' ];
        if ( '' === $name || ! is_array( $filter_values ) || array_diff( array_keys( $filter_values ), $valid_keys )
            || ! in_array( $group, [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ], true )
            || ! in_array( $interval, [ 'daily', 'weekly', 'monthly' ], true )
            || ! is_array( $columns ) || ! $columns || array_diff( $columns, Store::REPORT_COLUMNS )
            || count( array_unique( $columns ) ) !== count( $columns )
            || ( null !== $branding && ! is_bool( $branding ) )
            || ! is_array( $tags ) || count( $tags ) > 5
            || $range_days < 1 || $range_days > Store::settings()['retention'] ) {
            return new \WP_Error( 'alfa_saved_report_invalid', __( 'Check the report name, filters, date interval, and site retention period.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $filter_request = new \WP_REST_Request( 'GET', '/' . self::ROUTE . '/events' );
        foreach ( $filter_values as $key => $value ) {
            if ( ! is_string( $value ) || strlen( $value ) > 255 ) {
                return new \WP_Error( 'alfa_saved_report_invalid', __( 'Report filters must be text values no longer than 255 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $filter_request->set_param( $key, $value );
        }
        $filters = self::filters( $filter_request );
        if ( is_wp_error( $filters ) ) {
            return $filters;
        }
        $clean_tags = [];
        foreach ( $tags as $tag ) {
            if ( ! is_string( $tag ) || strlen( $tag ) > 30 ) {
                return new \WP_Error( 'alfa_saved_report_invalid', __( 'Report tags must be text values no longer than 30 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $tag = trim( sanitize_text_field( $tag ) );
            if ( '' === $tag ) {
                return new \WP_Error( 'alfa_saved_report_invalid', __( 'Remove blank report tags.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            if ( ! in_array( strtolower( $tag ), array_map( 'strtolower', $clean_tags ), true ) ) {
                $clean_tags[] = wp_html_excerpt( $tag, 30, '' );
            }
        }
        $statistics = null;
        $has_from = isset( $filters['from'] );
        $has_to = isset( $filters['to'] );
        if ( $has_from !== $has_to ) {
            return new \WP_Error( 'alfa_saved_report_invalid', __( 'Choose both dates to save a statistics result.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        if ( $has_from && $has_to ) {
            $filter_request->set_param( 'group', $group );
            $filter_request->set_param( 'interval', $interval );
            $statistics = self::statistics( $filter_request );
            if ( is_wp_error( $statistics ) ) {
                return $statistics;
            }
            $statistics['from'] = $filters['from'];
            $statistics['to'] = $filters['to'];
            $statistics['generated_at'] = current_time( 'mysql', true );
        }
        unset( $filters['from'], $filters['to'] );
        $report = Store::save_report_template( [
            'name' => wp_html_excerpt( $name, 100, '' ),
            'filters' => $filters,
            'group' => $group,
            'interval' => $interval,
            'range_days' => $range_days,
            'columns' => $columns,
            'branding' => (bool) $branding,
            'tags' => $clean_tags,
            'statistics' => $statistics,
        ] );
        return is_wp_error( $report ) ? $report : [ 'report' => $report ];
    }

    public static function saved_statistics_download( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $id = strtolower( sanitize_text_field( (string) $request['id'] ) );
        $format = sanitize_key( (string) $request->get_param( 'format' ) );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) || ! in_array( $format, [ 'csv', 'html' ], true ) ) {
            return self::bad_filters();
        }
        foreach ( Store::saved_reports() as $report ) {
            if ( ! hash_equals( $report['id'], $id ) ) {
                continue;
            }
            if ( ! isset( $report['statistics'] ) ) {
                return new \WP_Error( 'alfa_statistics_snapshot_missing', __( 'The saved statistics result is no longer available.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
            }
            $response = new \WP_REST_Response( [ 'report' => $report, 'format' => $format ] );
            $response->header( 'Content-Type', 'csv' === $format ? 'text/csv; charset=UTF-8' : 'text/html; charset=UTF-8' );
            $response->header( 'Content-Disposition', 'attachment; filename="activity-statistics-' . gmdate( 'Y-m-d' ) . '-' . $id . '.' . $format . '"' );
            $response->header( 'Cache-Control', 'no-store, private' );
            $response->header( 'X-Content-Type-Options', 'nosniff' );
            if ( 'html' === $format ) {
                $response->header( 'Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'" );
            }
            return $response;
        }
        return new \WP_Error( 'alfa_saved_report_missing', __( 'The saved report was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
    }

    public static function delete_report_template( \WP_REST_Request $request ): array|\WP_Error {
        $deleted = Store::delete_report_template( strtolower( (string) $request['id'] ) );
        return is_wp_error( $deleted ) ? $deleted : [ 'deleted' => true ];
    }

    public static function create_report_schedule( \WP_REST_Request $request ): array|\WP_Error {
        $template_id = strtolower( sanitize_text_field( (string) $request->get_param( 'template_id' ) ) );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $template_id ) ) {
            return new \WP_Error( 'alfa_schedule_invalid', __( 'Choose a saved report before scheduling delivery.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $recipients = $request->get_param( 'recipients' );
        if ( ! is_array( $recipients ) ) {
            return new \WP_Error( 'alfa_schedule_invalid', __( 'Check the delivery frequency, time, and recipient list.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        return ScheduledReports::create(
            $template_id,
            sanitize_key( (string) $request->get_param( 'cadence' ) ),
            (int) $request->get_param( 'hour' ),
            $recipients,
            sanitize_key( (string) $request->get_param( 'format' ) )
        );
    }

    public static function delete_report_schedule( \WP_REST_Request $request ): array|\WP_Error {
        $id = strtolower( (string) $request['id'] );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
            return new \WP_Error( 'alfa_schedule_missing', __( 'The scheduled report was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $deleted = ScheduledReports::delete( $id );
        return is_wp_error( $deleted ) ? $deleted : [ 'deleted' => true ];
    }

    public static function delete_report_history( \WP_REST_Request $request ): array|\WP_Error {
        $id = strtolower( (string) $request['id'] );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
            return new \WP_Error( 'alfa_report_history_missing', __( 'The report history item was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $deleted = ScheduledReports::delete_history( $id );
        return is_wp_error( $deleted ) ? $deleted : [ 'deleted' => true ];
    }

    private static function alert_rule_args(): array {
        return [
            'name' => [ 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 120 ],
            'enabled' => [ 'type' => 'boolean', 'default' => true ],
            'logic' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'all', 'any' ] ],
            'conditions' => [
                'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 8,
                'items' => [
                    'type' => 'object', 'required' => [ 'field', 'operator', 'value' ],
                    'properties' => [
                        'field' => [ 'type' => 'string', 'enum' => array_column( Alerts::fields(), 'id' ) ],
                        'operator' => [ 'type' => 'string', 'enum' => [ 'equals', 'contains', 'starts_with' ] ],
                        'value' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 120 ],
                    ],
                ],
            ],
            'channels' => [ 'type' => 'array', 'default' => [ 'email' ], 'minItems' => 1, 'maxItems' => 3, 'items' => [ 'type' => 'string', 'enum' => [ 'email', 'slack', 'sms' ] ], 'uniqueItems' => true ],
            'recipients' => [ 'type' => 'array', 'default' => [], 'minItems' => 0, 'maxItems' => 10, 'items' => [ 'type' => 'string', 'format' => 'email', 'maxLength' => 254 ], 'uniqueItems' => true ],
        ];
    }

    public static function save_alert_channels( \WP_REST_Request $request ): array|\WP_Error {
        $input = $request->get_json_params();
        return is_array( $input ) ? Alerts::save_channels( $input ) : new \WP_Error( 'alfa_alert_channel_invalid', __( 'The notification settings are invalid.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
    }

    public static function test_alert_channel( \WP_REST_Request $request ): array|\WP_Error {
        return Alerts::test_channel( sanitize_key( (string) $request['channel'] ) );
    }

    public static function save_alert_rule( \WP_REST_Request $request ): array|\WP_Error {
        $input = $request->get_json_params();
        return is_array( $input ) ? Alerts::save( $input ) : new \WP_Error( 'alfa_alert_rule_invalid', __( 'The alert rule is invalid.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
    }

    public static function toggle_alert_rule( \WP_REST_Request $request ): array|\WP_Error {
        $id = strtolower( (string) $request['id'] );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
            return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $saved = Alerts::set_enabled( $id, rest_sanitize_boolean( $request['enabled'] ) );
        return is_wp_error( $saved ) ? $saved : [ 'updated' => true ];
    }

    public static function delete_alert_rule( \WP_REST_Request $request ): array|\WP_Error {
        $id = strtolower( (string) $request['id'] );
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
            return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $deleted = Alerts::delete( $id );
        return is_wp_error( $deleted ) ? $deleted : [ 'deleted' => true ];
    }

    private static function is_private_route( \WP_REST_Request $request ): bool {
        return str_starts_with( $request->get_route(), '/' . self::ROUTE . '/' );
    }

    public static function private_rest_response( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
        if ( ! self::is_private_route( $request ) ) {
            return $response;
        }

        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
        $headers = $response->get_headers();
        foreach ( array_keys( $headers ) as $header ) {
            if ( 'last-modified' === strtolower( $header ) ) {
                unset( $headers[ $header ] );
            }
        }
        $response->set_headers( $headers );

        return $response;
    }

    public static function private_rest_headers( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        if ( ! self::is_private_route( $request ) || headers_sent() ) {
            return $served;
        }

        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
        header( 'Pragma: no-cache', true );
        header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT', true );
        header_remove( 'Last-Modified' );

        return $served;
    }

    public static function serve_html_report( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        if ( $served || '/' . self::ROUTE . '/report' !== $request->get_route()
            || 'GET' !== $request->get_method() || 200 !== $response->get_status() || ! self::allowed() ) {
            return $served;
        }
        $payload = $response->get_data();
        if ( ! isset( $payload['first'], $payload['filters'], $payload['ceiling'], $payload['title'], $payload['columns'], $payload['branding'] ) ) {
            return $served;
        }
        echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( (string) $payload['title'] ) . '</title><style>body{font:14px/1.5 system-ui,sans-serif;color:#172033;margin:32px}h1{font-size:24px;margin:0 0 8px}p{color:#526078}.report-brand{font-weight:600;color:#172033}.report-meta{margin:24px 0;padding:16px;background:#f4f6fa;border-radius:8px}.report-meta dt{font-weight:700}.report-meta dd{margin:0 0 8px}.report-table{border-collapse:collapse;width:100%;font-size:12px}.report-table th,.report-table td{border:1px solid #d7dce5;padding:8px;text-align:left;vertical-align:top}.report-table th{background:#f4f6fa}.report-table tr:nth-child(even){background:#fafbfc}.report-details{white-space:pre-wrap;max-width:420px}</style></head><body><main>';
        if ( $payload['branding'] ) {
            echo '<p class="report-brand">' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        }
        echo '<h1>' . esc_html( (string) $payload['title'] ) . '</h1><p>' . esc_html__( 'Generated', 'activity-log-for-admins' ) . ': ' . esc_html( wp_date( 'j M Y, H:i:s T' ) ) . '</p><dl class="report-meta">';
        $filter_labels = [
            'q' => __( 'Search', 'activity-log-for-admins' ),
            'from' => __( 'From', 'activity-log-for-admins' ),
            'to' => __( 'Through', 'activity-log-for-admins' ),
            'actor' => __( 'Actor', 'activity-log-for-admins' ),
            'actor_id' => __( 'Actor ID', 'activity-log-for-admins' ),
            'actor_kind' => __( 'Actor type', 'activity-log-for-admins' ),
            'actor_role' => __( 'Actor role', 'activity-log-for-admins' ),
            'source' => __( 'Source', 'activity-log-for-admins' ),
            'item' => __( 'Item', 'activity-log-for-admins' ),
            'action' => __( 'Event type', 'activity-log-for-admins' ),
            'category' => __( 'Category', 'activity-log-for-admins' ),
            'severity' => __( 'Severity', 'activity-log-for-admins' ),
            'site_id' => __( 'MainWP site ID', 'activity-log-for-admins' ),
        ];
        if ( ! $payload['filters'] ) {
            echo '<dt>' . esc_html__( 'Filters', 'activity-log-for-admins' ) . '</dt><dd>' . esc_html__( 'All available activity', 'activity-log-for-admins' ) . '</dd>';
        } else {
            foreach ( $payload['filters'] as $key => $value ) {
                echo '<dt>' . esc_html( $filter_labels[ $key ] ?? $key ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>';
            }
        }
        echo '</dl><table class="report-table"><caption class="screen-reader-text">' . esc_html__( 'Activity events matching the selected filters', 'activity-log-for-admins' ) . '</caption><thead><tr>';
        $headings = [
            'event_id' => __( 'Event ID', 'activity-log-for-admins' ),
            'time' => __( 'Time', 'activity-log-for-admins' ),
            'actor' => __( 'Actor', 'activity-log-for-admins' ),
            'actor_role' => __( 'Role', 'activity-log-for-admins' ),
            'source' => __( 'Source', 'activity-log-for-admins' ),
            'item' => __( 'Item', 'activity-log-for-admins' ),
            'action' => __( 'Event type', 'activity-log-for-admins' ),
            'severity' => __( 'Severity', 'activity-log-for-admins' ),
            'summary' => __( 'Summary', 'activity-log-for-admins' ),
            'details' => __( 'Change details', 'activity-log-for-admins' ),
        ];
        foreach ( $payload['columns'] as $column ) {
            $heading = $headings[ $column ] ?? null;
            if ( null === $heading ) {
                continue;
            }
            echo '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $rows = $payload['first'];
        while ( $rows ) {
            foreach ( $rows as $row ) {
                [ $changes, $details ] = self::csv_detail_cells( (string) ( $row['details'] ?? '' ) );
                $timestamp = strtotime( $row['occurred_at'] . ' UTC' );
                $time = wp_date( 'j M Y, H:i:s T', $timestamp );
                $actor = '' !== $row['actor_name'] ? $row['actor_name'] : match ( $row['actor_kind'] ) {
                    'anonymous' => __( 'Unauthenticated', 'activity-log-for-admins' ),
                    'system' => __( 'WordPress background task', 'activity-log-for-admins' ),
                    'cli' => __( 'WP CLI', 'activity-log-for-admins' ),
                    default => __( 'Unknown', 'activity-log-for-admins' ),
                };
                $item = $row['item_name'] ?: ucwords( str_replace( '_', ' ', (string) $row['item_kind'] ) );
                $action = ucwords( str_replace( '_', ' ', (string) $row['action_key'] ) );
                $cells = [
                    'event_id' => (string) $row['id'],
                    'time' => $time,
                    'actor' => $actor,
                    'actor_role' => (string) $row['actor_role'],
                    'source' => (string) $row['source'],
                    'item' => $item,
                    'action' => $action,
                    'severity' => (string) $row['severity'],
                    'summary' => (string) $row['summary'],
                    'details' => trim( $changes . "\n" . $details ),
                ];
                echo '<tr>';
                foreach ( $payload['columns'] as $column ) {
                    if ( ! array_key_exists( $column, $cells ) ) {
                        continue;
                    }
                    echo 'details' === $column
                        ? '<td class="report-details">' . nl2br( esc_html( $cells[ $column ] ), false ) . '</td>'
                        : '<td>' . esc_html( $cells[ $column ] ) . '</td>';
                }
                echo '</tr>';
            }
            if ( count( $rows ) < self::EXPORT_BATCH_SIZE || connection_aborted() ) {
                break;
            }
            $rows = Store::query( $payload['filters'], self::EXPORT_BATCH_SIZE, (int) end( $rows )['id'], (int) $payload['ceiling'], true );
            if ( is_wp_error( $rows ) ) {
                echo '<tr><td colspan="' . esc_attr( (string) count( $payload['columns'] ) ) . '">' . esc_html__( 'Report stopped because activity storage became unavailable. Generate the report again.', 'activity-log-for-admins' ) . '</td></tr>';
                break;
            }
        }
        echo '</tbody></table></main></body></html>';
        return true;
    }

    public static function serve_statistics_download( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        $route_prefix = '/' . self::ROUTE . '/reports/saved/';
        if ( $served || ! str_starts_with( $request->get_route(), $route_prefix ) || ! str_ends_with( $request->get_route(), '/statistics/download' )
            || 'GET' !== $request->get_method() || 200 !== $response->get_status() || ! self::allowed() ) {
            return $served;
        }
        $payload = $response->get_data();
        $report = $payload['report'] ?? null;
        $statistics = is_array( $report ) ? ( $report['statistics'] ?? null ) : null;
        $format = $payload['format'] ?? '';
        if ( ! is_array( $report ) || ! is_array( $statistics ) || ! in_array( $format, [ 'csv', 'html' ], true ) ) {
            return $served;
        }
        if ( 'csv' === $format ) {
            $out = fopen( 'php://output', 'wb' );
            if ( false === $out ) {
                return true;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write a UTF-8 marker to the private CSV response.
            fwrite( $out, "\xEF\xBB\xBF" );
            $tags = implode( ', ', $report['tags'] ?? [] );
            $base = [ $report['name'], $tags, $statistics['from'], $statistics['to'] ];
            fputcsv( $out, [ 'Report', 'Tags', 'From', 'Through', 'Group', 'Events' ], ',', '"', '' );
            fputcsv( $out, array_map( [ self::class, 'csv_cell' ], [ ...$base, __( 'Total', 'activity-log-for-admins' ), $statistics['total'] ] ), ',', '"', '' );
            foreach ( $statistics['rows'] as $row ) {
                fputcsv( $out, array_map( [ self::class, 'csv_cell' ], [ ...$base, self::statistics_row_label( $statistics['group'], $row['label'] ), $row['total'] ] ), ',', '"', '' );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the private CSV response stream.
            fclose( $out );
            return true;
        }

        echo '<!doctype html><html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( (string) $report['name'] ) . '</title><style>body{font:14px/1.5 system-ui,sans-serif;color:#172033;margin:32px}h1{font-size:24px;margin:0 0 8px}p{color:#526078}.report-meta{margin:24px 0;padding:16px;background:#f4f6fa;border-radius:8px}.report-meta dt{font-weight:700}.report-meta dd{margin:0 0 8px}.report-table{border-collapse:collapse;width:100%;font-size:13px}.report-table th,.report-table td{border:1px solid #d7dce5;padding:8px;text-align:left}.report-table th{background:#f4f6fa}.report-table tr:nth-child(even){background:#fafbfc}</style></head><body><main>';
        echo '<h1>' . esc_html( (string) $report['name'] ) . '</h1><p>' . esc_html__( 'Generated', 'activity-log-for-admins' ) . ': ' . esc_html( wp_date( 'j M Y, H:i:s T' ) ) . '</p><dl class="report-meta">';
        echo '<dt>' . esc_html__( 'Date range', 'activity-log-for-admins' ) . '</dt><dd>' . esc_html( $statistics['from'] . ' to ' . $statistics['to'] ) . '</dd>';
        echo '<dt>' . esc_html__( 'Grouped by', 'activity-log-for-admins' ) . '</dt><dd>' . esc_html( self::statistics_group_label( $statistics['group'] ) ) . '</dd>';
        if ( $report['tags'] ) {
            echo '<dt>' . esc_html__( 'Tags', 'activity-log-for-admins' ) . '</dt><dd>' . esc_html( implode( ', ', $report['tags'] ) ) . '</dd>';
        }
        echo '<dt>' . esc_html__( 'Total events', 'activity-log-for-admins' ) . '</dt><dd>' . esc_html( number_format_i18n( $statistics['total'] ) ) . '</dd></dl><table class="report-table"><caption>' . esc_html__( 'Activity totals by group', 'activity-log-for-admins' ) . '</caption><thead><tr><th scope="col">' . esc_html( self::statistics_group_label( $statistics['group'] ) ) . '</th><th scope="col">' . esc_html__( 'Events', 'activity-log-for-admins' ) . '</th></tr></thead><tbody>';
        foreach ( $statistics['rows'] as $row ) {
            echo '<tr><th scope="row">' . esc_html( self::statistics_row_label( $statistics['group'], $row['label'] ) ) . '</th><td>' . esc_html( number_format_i18n( $row['total'] ) ) . '</td></tr>';
        }
        echo '</tbody></table></main></body></html>';
        return true;
    }

    private static function statistics_group_label( string $group ): string {
        return [
            'category' => __( 'Event category', 'activity-log-for-admins' ),
            'action' => __( 'Event type', 'activity-log-for-admins' ),
            'severity' => __( 'Severity', 'activity-log-for-admins' ),
            'actor_role' => __( 'Actor role', 'activity-log-for-admins' ),
            'source' => __( 'Request source', 'activity-log-for-admins' ),
            'day' => __( 'Date', 'activity-log-for-admins' ),
        ][ $group ] ?? __( 'Group', 'activity-log-for-admins' );
    }

    private static function statistics_row_label( string $group, string $label ): string {
        if ( 'category' === $group ) {
            return [
                'content' => __( 'Posts and pages', 'activity-log-for-admins' ),
                'accounts' => __( 'Accounts and sign ins', 'activity-log-for-admins' ),
                'comments' => __( 'Comments', 'activity-log-for-admins' ),
                'taxonomies' => __( 'Categories and tags', 'activity-log-for-admins' ),
                'appearance' => __( 'Menus and widgets', 'activity-log-for-admins' ),
                'components' => __( 'Plugins and themes', 'activity-log-for-admins' ),
                'settings' => __( 'Site settings', 'activity-log-for-admins' ),
                'network' => __( 'Network sites', 'activity-log-for-admins' ),
                'database' => __( 'Database', 'activity-log-for-admins' ),
                'files' => __( 'File changes', 'activity-log-for-admins' ),
                'woocommerce' => __( 'WooCommerce', 'activity-log-for-admins' ),
                'redirection' => __( 'Redirection', 'activity-log-for-admins' ),
                'acf' => __( 'Advanced Custom Fields', 'activity-log-for-admins' ),
                'bbpress' => __( 'bbPress', 'activity-log-for-admins' ),
                'wpforms' => __( 'WPForms', 'activity-log-for-admins' ),
                'yoast' => __( 'Yoast SEO', 'activity-log-for-admins' ),
                'rank_math' => __( 'Rank Math', 'activity-log-for-admins' ),
                'pmpro' => __( 'Paid Memberships Pro', 'activity-log-for-admins' ),
                'termly' => __( 'Termly', 'activity-log-for-admins' ),
                'mainwp' => __( 'MainWP', 'activity-log-for-admins' ),
                'infinitewp' => __( 'InfiniteWP', 'activity-log-for-admins' ),
            ][ $label ] ?? $label;
        }
        if ( 'actor_role' === $group ) {
            $roles = wp_roles()->get_names();
            return $roles[ $label ] ?? $label;
        }
        if ( 'source' === $group ) {
            return [ 'admin' => __( 'WordPress admin', 'activity-log-for-admins' ), 'rest' => __( 'REST API', 'activity-log-for-admins' ), 'cron' => __( 'WordPress cron', 'activity-log-for-admins' ), 'cli' => __( 'WP CLI', 'activity-log-for-admins' ), 'xmlrpc' => __( 'XML RPC', 'activity-log-for-admins' ), 'frontend' => __( 'Front end', 'activity-log-for-admins' ), 'infinitewp' => 'InfiniteWP', 'unknown' => __( 'Unknown', 'activity-log-for-admins' ) ][ $label ] ?? $label;
        }
        if ( 'severity' === $group ) {
            return [ 'info' => __( 'Info', 'activity-log-for-admins' ), 'notice' => __( 'Notice', 'activity-log-for-admins' ), 'warning' => __( 'Warning', 'activity-log-for-admins' ) ][ $label ] ?? $label;
        }
        if ( 'action' === $group ) {
            return ucwords( str_replace( '_', ' ', $label ) );
        }
        return $label;
    }

    public static function serve_csv( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        $network = '/' . self::ROUTE . '/network/export' === $request->get_route();
        if ( $served || ( ! $network && '/' . self::ROUTE . '/export' !== $request->get_route() )
            || 'GET' !== $request->get_method() || 200 !== $response->get_status()
            || ( $network ? ! self::network_allowed() : ! self::allowed() ) || ! isset( $response->get_headers()['Content-Type'] ) ) {
            return $served;
        }
        $payload = $response->get_data();
        if ( ! isset( $payload['first'], $payload['filters'], $payload['ceiling'] ) || $network !== ( $payload['network'] ?? false ) ) {
            return $served;
        }
        $out = fopen( 'php://output', 'wb' );
        if ( false === $out ) {
            return true;
        }
        // Include a UTF-8 marker for spreadsheet apps.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write the CSV marker to the REST response stream.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, $network
            ? [ 'Event ID', 'Network site ID', 'Network site', 'Time (site timezone)', 'Actor type', 'Actor', 'Actor ID', 'Actor role', 'Source', 'Item type', 'Item', 'Item key', 'Event category', 'Action', 'Severity', 'Summary', 'Previous and new values', 'Event details' ]
            : [ 'Event ID', 'Time (UTC)', 'MainWP site ID', 'Child event ID', 'Actor type', 'Actor', 'Actor ID', 'Actor role', 'Source', 'Item type', 'Item', 'Item key', 'Event category', 'Action', 'Severity', 'Summary', 'Previous and new values', 'Event details' ], ',', '"', '' );
        $rows = $payload['first'];
        while ( $rows ) {
            foreach ( $rows as $row ) {
                [ $changes, $details ] = self::csv_detail_cells( $row['details'] ?? '' );
                $event_time = $row['occurred_at'];
                if ( $network ) {
                    $timezone = new \DateTimeZone( (string) ( $row['network_site_timezone'] ?? 'UTC' ) );
                    $event_time = wp_date( 'Y-m-d H:i:s T', strtotime( $row['occurred_at'] . ' UTC' ), $timezone );
                }
                $values = $network
                    ? [ $row['id'], $row['network_site_id'], $row['network_site_name'], $event_time, $row['actor_kind'], $row['actor_name'], $row['actor_id'], $row['actor_role'], $row['source'], $row['item_kind'], $row['item_name'], $row['item_key'], Store::action_category( (string) $row['action_key'] ) ?? 'other', $row['action_key'], $row['severity'], $row['summary'], $changes, $details ]
                    : [ $row['id'], $event_time, $row['origin_site_id'] ?? 0, $row['origin_event_id'] ?? 0, $row['actor_kind'], $row['actor_name'], $row['actor_id'], $row['actor_role'], $row['source'], $row['item_kind'], $row['item_name'], $row['item_key'], Store::action_category( (string) $row['action_key'] ) ?? 'other', $row['action_key'], $row['severity'], $row['summary'], $changes, $details ];
                fputcsv( $out, array_map( [ self::class, 'csv_cell' ], $values ), ',', '"', '' );
            }
            if ( count( $rows ) < self::EXPORT_BATCH_SIZE || connection_aborted() ) {
                break;
            }
            if ( $network ) {
                $last = end( $rows );
                $cursor = [ 'time' => (string) $last['occurred_at'], 'site' => (int) $last['network_site_id'], 'id' => (int) $last['id'] ];
                $rows = Store::network_query( $payload['filters'], self::EXPORT_BATCH_SIZE, $cursor, true, $payload['ceiling'] );
            } else {
                $rows = Store::query( $payload['filters'], self::EXPORT_BATCH_SIZE, (int) end( $rows )['id'], $payload['ceiling'], true );
            }
            if ( is_wp_error( $rows ) ) {
                fputcsv( $out, [ 'Export interrupted. Retry to download the remaining events.' ], ',', '"', '' );
                break;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the REST response stream after the CSV is written.
        fclose( $out );
        return true;
    }

    private static function csv_cell( mixed $value ): string {
        $value = (string) $value;
        return preg_match( '/^(?:[\t\r\n]|[\p{Z}\s]*[=+@\x{002D}\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}])/u', $value ) ? "'" . $value : $value;
    }

    private static function csv_detail_cells( string $json ): array {
        $details = json_decode( $json, true );
        if ( ! is_array( $details ) ) {
            return [ '', '' ];
        }
        $changes = is_array( $details['changes'] ?? null ) ? $details['changes'] : [];
        $lines = [];
        foreach ( $changes as $field => $values ) {
            if ( ! is_array( $values ) || ! array_key_exists( 0, $values ) || ! array_key_exists( 1, $values ) ) {
                continue;
            }
            $lines[] = (string) $field . ': ' . (string) $values[0] . ' → ' . (string) $values[1];
        }
        if ( ! empty( $details['content_changed'] ) ) {
            $lines[] = __( 'Content changed; compare WordPress revisions when available.', 'activity-log-for-admins' );
        }
        return [
            implode( '; ', $lines ),
            wp_json_encode( $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '',
        ];
    }
}
