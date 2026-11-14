<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Admin {
    private const ROUTE = 'activity-log-for-admins/v1';
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'activity-log-for-admins';
    private static string $plugin_file;
    private static string $screen = '';

    public static function register( string $plugin_file ): void {
        self::$plugin_file = $plugin_file;
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'dashboard_assets' ] );
        add_action( 'wp_dashboard_setup', [ self::class, 'dashboard_widget' ], 1 );
        add_action( 'rest_api_init', [ self::class, 'routes' ] );
        add_filter( 'rest_pre_serve_request', [ self::class, 'serve_csv' ], 10, 4 );
    }

    public static function menu(): void {
        self::$screen = add_menu_page(
            __( 'Activity Log', 'activity-log-for-admins' ),
            __( 'Activity Log', 'activity-log-for-admins' ),
            self::CAPABILITY, self::SLUG, [ self::class, 'render' ], 'dashicons-list-view', 3
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
                        default => __( 'Unknown actor', 'activity-log-for-admins' ),
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
        $metadata = get_file_data( self::$plugin_file, [ 'version' => 'Version' ] );
        $version = $metadata['version'];
        self::theme_assets( $version );
        wp_enqueue_style( 'alfa-admin', plugins_url( 'build/admin.css', self::$plugin_file ), [ 'alfa-theme' ], self::asset_version( 'admin.css', $version ) );
        wp_enqueue_script( 'alfa-admin', plugins_url( 'build/admin.js', self::$plugin_file ), [ 'react', 'react-dom', 'react-jsx-runtime' ], self::asset_version( 'admin.js', $version ), true );
        $config = [
            'base' => rest_url( self::ROUTE . '/' ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'version' => $version,
            'locale' => str_replace( '_', '-', determine_locale() ),
            'timezone' => wp_timezone_string(),
            'actions' => Capture::ACTIONS,
            'severities' => Capture::SEVERITIES,
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

    public static function allowed(): bool {
        return current_user_can( self::CAPABILITY );
    }

    public static function routes(): void {
        $read = [ 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => [ self::class, 'allowed' ] ];
        register_rest_route( self::ROUTE, '/events', $read + [ 'callback' => [ self::class, 'events' ] ] );
        register_rest_route( self::ROUTE, '/events/(?P<id>[1-9][0-9]*)', $read + [ 'callback' => [ self::class, 'event' ] ] );
        register_rest_route( self::ROUTE, '/export', $read + [ 'callback' => [ self::class, 'export' ] ] );
        register_rest_route( self::ROUTE, '/settings', [
            $read + [ 'callback' => [ self::class, 'settings' ] ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'permission_callback' => [ self::class, 'allowed' ],
                'callback' => [ self::class, 'save' ],
                'args' => [
                    'retention' => [ 'type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 3650 ],
                    'erase' => [ 'type' => 'boolean', 'required' => true ],
                ],
            ],
        ] );
    }

    private static function filters( \WP_REST_Request $request ): array|\WP_Error {
        $filters = [];
        foreach ( [ 'q', 'actor', 'item', 'action', 'severity', 'from', 'to' ] as $key ) {
            $value = $request->get_param( $key );
            if ( null === $value || '' === $value ) {
                continue;
            }
            if ( ! is_string( $value ) || mb_strlen( $value ) > 255 ) {
                return self::bad_filters();
            }
            $filters[ $key ] = sanitize_text_field( $value );
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
            || ( isset( $filters['action'] ) && ! in_array( $filters['action'], Capture::ACTIONS, true ) )
            || ( isset( $filters['severity'] ) && ! in_array( $filters['severity'], Capture::SEVERITIES, true ) ) ) {
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

    private static function present( array $row ): array {
        $row['id'] = (string) $row['id'];
        $row['actor_id'] = (string) $row['actor_id'];
        $timestamp = strtotime( $row['occurred_at'] . ' UTC' );
        $row['time_iso'] = gmdate( DATE_ATOM, $timestamp );
        $row['time_short'] = wp_date( 'j M Y, H:i', $timestamp );
        $row['time_exact'] = wp_date( 'j M Y, H:i:s T', $timestamp );
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

    public static function settings(): array {
        $health = Store::health();
        if ( $health['oldest'] ) {
            $health['oldest'] = wp_date( 'j M Y, H:i T', strtotime( $health['oldest'] . ' UTC' ) );
        }
        foreach ( [ 'last_cleanup', 'write_error', 'cleanup_error', 'next_cleanup' ] as $key ) {
            $health[ $key ] = $health[ $key ] ? wp_date( 'j M Y, H:i T', (int) $health[ $key ] ) : null;
        }
        $health['space'] = null === $health['bytes'] ? null : size_format( $health['bytes'], 1 );
        return [ 'settings' => Store::settings(), 'health' => $health ];
    }

    public static function save( \WP_REST_Request $request ): array|\WP_Error {
        $settings = [ 'retention' => (int) $request['retention'], 'erase' => rest_sanitize_boolean( $request['erase'] ) ];
        if ( $settings !== Store::settings() && ! update_option( Store::SETTINGS, $settings, false ) && $settings !== Store::settings() ) {
            return new \WP_Error( 'alfa_settings', __( 'Settings could not be saved. Try again.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        Store::schedule();
        return self::settings();
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
        $first = $ceiling ? Store::query( $filters, 500, 0, $ceiling ) : [];
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

    public static function serve_csv( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
        if ( $served || '/' . self::ROUTE . '/export' !== $request->get_route()
            || 'GET' !== $request->get_method() || 200 !== $response->get_status()
            || ! self::allowed() || ! isset( $response->get_headers()['Content-Type'] ) ) {
            return $served;
        }
        $payload = $response->get_data();
        if ( ! isset( $payload['first'], $payload['filters'], $payload['ceiling'] ) ) {
            return $served;
        }
        $out = fopen( 'php://output', 'wb' );
        if ( false === $out ) {
            return true;
        }
        // Include a UTF-8 marker for spreadsheet apps.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write the CSV marker to the REST response stream.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'Event ID', 'Time (UTC)', 'Actor type', 'Actor', 'Actor ID', 'Item type', 'Item', 'Item key', 'Action', 'Severity', 'Summary' ], ',', '"', '' );
        $rows = $payload['first'];
        while ( $rows ) {
            foreach ( $rows as $row ) {
                fputcsv( $out, array_map( [ self::class, 'csv_cell' ], [
                    $row['id'], $row['occurred_at'], $row['actor_kind'], $row['actor_name'], $row['actor_id'],
                    $row['item_kind'], $row['item_name'], $row['item_key'], $row['action_key'], $row['severity'], $row['summary'],
                ] ), ',', '"', '' );
            }
            if ( count( $rows ) < 500 || connection_aborted() ) {
                break;
            }
            $rows = Store::query( $payload['filters'], 500, (int) end( $rows )['id'], $payload['ceiling'] );
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
}
