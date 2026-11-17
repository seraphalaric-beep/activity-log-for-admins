<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Store {
    public const REPORT_COLUMNS = [ 'event_id', 'time', 'actor', 'actor_role', 'source', 'item', 'action', 'severity', 'summary', 'details' ];
    public const SETTINGS = 'alfa_settings';
    public const HEALTH = 'alfa_health';
    public const SCHEMA = 'alfa_schema';
    public const CLEANUP = 'alfa_cleanup';
    public const CONTINUE_CLEANUP = 'alfa_continue_cleanup';
    public const ALERT_DIGEST = 'alfa_alert_digest';
    public const FILE_SCAN_STATE = 'alfa_file_scan_state';
    public const SAVED_REPORTS = 'alfa_saved_reports';
    public const PAGE_SIZE = 50;
    private const SCHEMA_VERSION = 6;
    private const CLEANUP_SIZE = 1000;
    private const MAX_EVENT_NOTES = 50;
    private const EXPORTABLE_SETTINGS = [
        'retention', 'erase', 'woocommerce', 'redirection', 'acf', 'bbpress', 'wpforms', 'yoast', 'rank_math', 'pmpro', 'termly', 'mainwp', 'infinitewp', 'email_alerts', 'email_alert_include_events', 'email_alert_event_limit', 'email_alert_categories',
        'capture_content', 'capture_accounts', 'capture_comments', 'capture_taxonomies',
        'capture_appearance', 'capture_components', 'capture_settings', 'disabled_actions',
        'viewer_roles', 'manager_roles', 'exclude_system_activity', 'excluded_roles',
        'excluded_item_kinds', 'excluded_post_statuses', 'capture_network', 'capture_database',
        'capture_files', 'monitor_uploads', 'file_max_size_mb', 'file_scan_interval', 'file_excluded_paths',
    ];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'alfa_events';
    }

    public static function notes_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'alfa_event_notes';
    }

    public static function file_index_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'alfa_file_index';
    }

    public static function install(): bool {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collation = $wpdb->get_charset_collate();

        dbDelta( "CREATE TABLE $table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            occurred_at datetime NOT NULL,
            actor_kind varchar(16) NOT NULL,
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            actor_name varchar(191) NOT NULL DEFAULT '',
            actor_role varchar(64) NOT NULL DEFAULT '',
            source varchar(16) NOT NULL DEFAULT '',
            origin_site_id bigint unsigned NOT NULL DEFAULT 0,
            origin_event_id bigint unsigned NOT NULL DEFAULT 0,
            item_kind varchar(24) NOT NULL,
            item_key varchar(191) NOT NULL,
            item_name varchar(255) NOT NULL,
            action_key varchar(40) NOT NULL,
            severity varchar(12) NOT NULL,
            summary text NOT NULL,
            details text NOT NULL,
            PRIMARY KEY  (id),
            KEY occurred (occurred_at,id),
            KEY actor (actor_kind,actor_id,id),
            KEY actor_role (actor_role,id),
            KEY source (source,id),
            KEY origin (origin_site_id,origin_event_id),
            KEY item (item_kind,item_key(120),id),
            KEY action_key (action_key,id),
            KEY severity (severity,id)
        ) $collation;" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the custom table exists after dbDelta runs.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the required event columns.
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
        $required_columns = [ 'id', 'occurred_at', 'actor_kind', 'actor_id', 'actor_name', 'actor_role', 'source', 'origin_site_id', 'origin_event_id', 'item_kind', 'item_key', 'item_name', 'action_key', 'severity', 'summary', 'details' ];
        if ( $wpdb->last_error || array_diff( $required_columns, (array) $columns ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the indexes required for event queries.
        $index_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ) );
        if ( $wpdb->last_error || ! is_array( $index_rows ) ) {
            return false;
        }
        $actual_indexes = [];
        foreach ( $index_rows as $index_row ) {
            $actual_indexes[ (string) $index_row->Key_name ][ (int) $index_row->Seq_in_index ] = (string) $index_row->Column_name;
        }
        $required_indexes = [
            'PRIMARY'   => [ 'id' ],
            'occurred'  => [ 'occurred_at', 'id' ],
            'actor'     => [ 'actor_kind', 'actor_id', 'id' ],
            'actor_role' => [ 'actor_role', 'id' ],
            'source' => [ 'source', 'id' ],
            'origin' => [ 'origin_site_id', 'origin_event_id' ],
            'item'      => [ 'item_kind', 'item_key', 'id' ],
            'action_key' => [ 'action_key', 'id' ],
            'severity'  => [ 'severity', 'id' ],
        ];
        foreach ( $required_indexes as $name => $columns ) {
            if ( ! isset( $actual_indexes[ $name ] ) ) {
                return false;
            }
            ksort( $actual_indexes[ $name ] );
            if ( array_values( $actual_indexes[ $name ] ) !== $columns ) {
                return false;
            }
        }

        $notes_table = self::notes_table();
        dbDelta( "CREATE TABLE $notes_table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            note text NOT NULL,
            PRIMARY KEY  (id),
            KEY event_created (event_id,created_at,id),
            KEY author (user_id,id)
        ) $collation;" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the plugin owned notes table exists after dbDelta runs.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $notes_table ) ) ) !== $notes_table ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the required note columns.
        $note_columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $notes_table ) );
        if ( $wpdb->last_error || array_diff( [ 'id', 'event_id', 'user_id', 'created_at', 'note' ], (array) $note_columns ) ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the required note indexes.
        $note_index_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $notes_table ) );
        if ( $wpdb->last_error || ! is_array( $note_index_rows ) ) {
            return false;
        }
        $note_indexes = [];
        foreach ( $note_index_rows as $index_row ) {
            $note_indexes[ (string) $index_row->Key_name ][ (int) $index_row->Seq_in_index ] = (string) $index_row->Column_name;
        }
        foreach ( [ 'PRIMARY' => [ 'id' ], 'event_created' => [ 'event_id', 'created_at', 'id' ], 'author' => [ 'user_id', 'id' ] ] as $name => $columns ) {
            if ( ! isset( $note_indexes[ $name ] ) ) {
                return false;
            }
            ksort( $note_indexes[ $name ] );
            if ( array_values( $note_indexes[ $name ] ) !== $columns ) {
                return false;
            }
        }

        $file_index_table = self::file_index_table();
        dbDelta( "CREATE TABLE $file_index_table (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            path_hash char(64) NOT NULL,
            file_path varchar(512) NOT NULL,
            fingerprint char(64) NOT NULL,
            hash_kind varchar(16) NOT NULL DEFAULT 'content',
            file_size bigint unsigned NOT NULL DEFAULT 0,
            file_mtime bigint unsigned NOT NULL DEFAULT 0,
            file_mode int unsigned NOT NULL DEFAULT 0,
            generation bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash (path_hash),
            KEY generation (generation,id)
        ) $collation;" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the file baseline table exists after dbDelta runs.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $file_index_table ) ) ) !== $file_index_table ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the required file index columns.
        $file_columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $file_index_table ) );
        if ( $wpdb->last_error || array_diff( [ 'id', 'path_hash', 'file_path', 'fingerprint', 'hash_kind', 'file_size', 'file_mtime', 'file_mode', 'generation' ], (array) $file_columns ) ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm dbDelta applied the required file index indexes.
        $file_index_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $file_index_table ) );
        if ( $wpdb->last_error || ! is_array( $file_index_rows ) ) {
            return false;
        }
        $file_indexes = [];
        foreach ( $file_index_rows as $index_row ) {
            $file_indexes[ (string) $index_row->Key_name ][ (int) $index_row->Seq_in_index ] = (string) $index_row->Column_name;
        }
        foreach ( [ 'PRIMARY' => [ 'id' ], 'path_hash' => [ 'path_hash' ], 'generation' => [ 'generation', 'id' ] ] as $name => $columns ) {
            if ( ! isset( $file_indexes[ $name ] ) ) {
                return false;
            }
            ksort( $file_indexes[ $name ] );
            if ( array_values( $file_indexes[ $name ] ) !== $columns ) {
                return false;
            }
        }

        update_option( self::SCHEMA, self::SCHEMA_VERSION, true );
        if ( (int) get_option( self::SCHEMA ) !== self::SCHEMA_VERSION ) {
            return false;
        }
        self::clear_failure( 'schema_error' );
        return true;
    }

    public static function upgrade(): void {
        if ( (int) get_option( self::SCHEMA ) === self::SCHEMA_VERSION ) {
            self::clear_failure( 'schema_error' );
            $health = (array) get_option( self::HEALTH, [] );
            if ( empty( $health['schedule_error'] ) && ! wp_next_scheduled( self::CLEANUP ) ) {
                self::schedule();
            }
            if ( class_exists( FileMonitor::class ) ) {
                FileMonitor::ensure_schedule();
            }
            return;
        }
        if ( ! self::install() ) {
            self::record_failure( 'schema_error' );
            return;
        }
        self::schedule();
        if ( class_exists( FileMonitor::class ) ) {
            FileMonitor::ensure_schedule();
        }
    }

    public static function settings(): array {
        $saved = (array) get_option( self::SETTINGS, [] );
        return self::normalize_settings( $saved );
    }

    public static function normalize_settings( array $saved ): array {
        $settings = [
            'retention' => min( 3650, max( 1, (int) ( $saved['retention'] ?? 90 ) ) ),
            'erase'     => in_array( $saved['erase'] ?? false, [ true, 1, '1' ], true ),
            'woocommerce' => in_array( $saved['woocommerce'] ?? false, [ true, 1, '1' ], true ),
            'redirection' => in_array( $saved['redirection'] ?? false, [ true, 1, '1' ], true ),
            'acf' => in_array( $saved['acf'] ?? false, [ true, 1, '1' ], true ),
            'bbpress' => in_array( $saved['bbpress'] ?? false, [ true, 1, '1' ], true ),
            'wpforms' => in_array( $saved['wpforms'] ?? false, [ true, 1, '1' ], true ),
            'yoast' => in_array( $saved['yoast'] ?? false, [ true, 1, '1' ], true ),
            'rank_math' => in_array( $saved['rank_math'] ?? false, [ true, 1, '1' ], true ),
            'pmpro' => in_array( $saved['pmpro'] ?? false, [ true, 1, '1' ], true ),
            'termly' => in_array( $saved['termly'] ?? false, [ true, 1, '1' ], true ),
            'mainwp' => in_array( $saved['mainwp'] ?? false, [ true, 1, '1' ], true ),
            'infinitewp' => in_array( $saved['infinitewp'] ?? false, [ true, 1, '1' ], true ),
            'email_alerts' => in_array( $saved['email_alerts'] ?? false, [ true, 1, '1' ], true ),
            'email_alert_recipients' => self::normalize_email_recipients( $saved['email_alert_recipients'] ?? [] ),
            'email_alert_include_events' => ! array_key_exists( 'email_alert_include_events', $saved ) || in_array( $saved['email_alert_include_events'], [ true, 1, '1' ], true ),
            'email_alert_event_limit' => min( 20, max( 1, (int) ( $saved['email_alert_event_limit'] ?? 10 ) ) ),
            'email_alert_categories' => self::normalized_values( $saved['email_alert_categories'] ?? array_keys( self::event_groups() ), array_keys( self::event_groups() ) ),
            'exclude_system_activity' => in_array( $saved['exclude_system_activity'] ?? false, [ true, 1, '1' ], true ),
            'capture_network' => ! array_key_exists( 'capture_network', $saved ) || in_array( $saved['capture_network'], [ true, 1, '1' ], true ),
            'capture_files' => in_array( $saved['capture_files'] ?? false, [ true, 1, '1' ], true ),
            'monitor_uploads' => in_array( $saved['monitor_uploads'] ?? false, [ true, 1, '1' ], true ),
            'file_max_size_mb' => min( 50, max( 1, (int) ( $saved['file_max_size_mb'] ?? 10 ) ) ),
            'file_scan_interval' => in_array( $saved['file_scan_interval'] ?? 'daily', [ 'daily', 'weekly' ], true ) ? ( $saved['file_scan_interval'] ?? 'daily' ) : 'daily',
        ];
        foreach ( [ 'content', 'accounts', 'comments', 'taxonomies', 'appearance', 'components', 'settings', 'network', 'database' ] as $category ) {
            $settings[ 'capture_' . $category ] = ! array_key_exists( 'capture_' . $category, $saved )
                || in_array( $saved[ 'capture_' . $category ], [ true, 1, '1' ], true );
        }
        $disabled_actions = isset( $saved['disabled_actions'] ) && is_array( $saved['disabled_actions'] )
            ? array_map( 'sanitize_key', array_filter( $saved['disabled_actions'], 'is_string' ) )
            : [];
        $settings['disabled_actions'] = array_values( array_unique( array_intersect( Capture::action_types(), $disabled_actions ) ) );
        $available_roles = array_keys( wp_roles()->roles );
        foreach ( [ 'viewer_roles', 'manager_roles' ] as $key ) {
            $roles = isset( $saved[ $key ] ) && is_array( $saved[ $key ] )
                ? array_map( 'sanitize_key', array_filter( $saved[ $key ], 'is_string' ) )
                : [];
            $settings[ $key ] = array_values( array_unique( array_intersect( $available_roles, $roles ) ) );
        }
        foreach ( [ 'viewer_users', 'manager_users', 'excluded_users' ] as $key ) {
            $ids = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? array_map( 'absint', $saved[ $key ] ) : [];
            $settings[ $key ] = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 100 );
        }
        $settings['excluded_roles'] = self::normalized_values( $saved['excluded_roles'] ?? [], $available_roles );
        $settings['excluded_item_kinds'] = self::normalized_values( $saved['excluded_item_kinds'] ?? [], self::available_item_kinds() );
        $settings['excluded_items'] = self::normalize_excluded_items( $saved['excluded_items'] ?? [] );
        $settings['excluded_post_statuses'] = self::normalized_values( $saved['excluded_post_statuses'] ?? [], self::available_post_statuses() );
        $settings['file_excluded_paths'] = self::normalize_file_excluded_paths( $saved['file_excluded_paths'] ?? [] );
        return $settings;
    }

    public static function normalize_file_excluded_paths( mixed $paths ): array {
        if ( ! is_array( $paths ) ) {
            return [];
        }
        $normalized = [];
        foreach ( $paths as $path ) {
            if ( ! is_string( $path ) ) {
                continue;
            }
            $path = str_replace( '\\', '/', sanitize_text_field( $path ) );
            if ( str_starts_with( $path, '/' ) || preg_match( '/\A[A-Za-z]:/', $path ) ) {
                continue;
            }
            $path = trim( $path, '/' );
            $path = preg_replace( '#/+#', '/', $path );
            if ( '' === $path || strlen( $path ) > 255 || str_contains( $path, '*' ) || str_contains( $path, '?' )
                || str_contains( $path, "\0" )
                || in_array( '..', explode( '/', $path ), true ) || in_array( '.', explode( '/', $path ), true ) ) {
                continue;
            }
            $normalized[] = $path;
        }
        return array_slice( array_values( array_unique( $normalized ) ), 0, 25 );
    }

    private static function normalized_values( mixed $values, array $allowed ): array {
        if ( ! is_array( $values ) ) {
            return [];
        }
        $values = array_map( 'sanitize_key', array_filter( $values, 'is_string' ) );
        return array_values( array_unique( array_intersect( $allowed, $values ) ) );
    }

    public static function normalize_excluded_items( mixed $items ): array {
        if ( ! is_array( $items ) ) {
            return [];
        }
        $allowed_kinds = array_fill_keys( self::available_item_kinds(), true );
        $normalized = [];
        foreach ( array_slice( $items, 0, 100 ) as $item ) {
            if ( ! is_string( $item ) || strlen( $item ) > 232 || ! str_contains( $item, ':' ) ) {
                continue;
            }
            [ $kind, $key ] = explode( ':', $item, 2 );
            if ( ! isset( $allowed_kinds[ $kind ] ) || '' === $key || strlen( $key ) > 191
                || sanitize_text_field( $key ) !== $key || preg_match( '/[\x00-\x1f\x7f]/', $key ) ) {
                continue;
            }
            $normalized[] = $kind . ':' . $key;
        }
        return array_values( array_unique( $normalized ) );
    }

    public static function available_item_kinds(): array {
        $content_types = get_post_types( [ 'public' => true ], 'names' );
        $content_types = array_diff( $content_types, [ 'attachment', 'revision', 'nav_menu_item' ] );
        $item_kinds = array_merge(
            [ 'post', 'page', 'user', 'plugin', 'theme', 'setting', 'login', 'term', 'comment', 'menu', 'widget', 'order', 'product', 'coupon', 'forum', 'topic', 'reply', 'redirect', 'site', 'mainwp_site', 'mainwp_client', 'backup', 'table', 'file' ],
            $content_types
        );
        $filtered = apply_filters( 'alfa_activity_log_item_kinds', $item_kinds );
        if ( ! is_array( $filtered ) ) {
            return array_values( array_unique( $item_kinds ) );
        }
        foreach ( $filtered as $kind ) {
            if ( is_string( $kind ) && preg_match( '/\A[a-z][a-z0-9_]{0,39}\z/', $kind ) && ! in_array( $kind, $item_kinds, true ) ) {
                $item_kinds[] = $kind;
            }
        }
        return array_values( array_unique( $item_kinds ) );
    }

    public static function available_post_statuses(): array {
        return [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ];
    }

    public static function should_exclude( string $actor_kind, int $actor_id, string $item_kind, string $item_key, array $details, ?array $settings = null ): bool {
        $settings = $settings ?? self::settings();
        if ( 'system' === $actor_kind && $settings['exclude_system_activity'] ) {
            return true;
        }
        if ( $actor_id && in_array( $actor_id, $settings['excluded_users'], true ) ) {
            return true;
        }
        if ( $actor_id && $settings['excluded_roles'] ) {
            $user = get_userdata( $actor_id );
            if ( $user instanceof \WP_User && array_intersect( $settings['excluded_roles'], $user->roles ) ) {
                return true;
            }
        }
        if ( in_array( $item_kind, $settings['excluded_item_kinds'], true ) ) {
            return true;
        }
        if ( in_array( $item_kind . ':' . sanitize_text_field( $item_key ), $settings['excluded_items'], true ) ) {
            return true;
        }
        if ( $settings['excluded_post_statuses'] ) {
            $post_types = get_post_types( [], 'names' );
            if ( in_array( $item_kind, $post_types, true ) ) {
                $status = '';
                $post = get_post( absint( $item_key ) );
                if ( $post instanceof \WP_Post ) {
                    $status = $post->post_status;
                }
                if ( isset( $details['changes']['Status'][1] ) ) {
                    $status = sanitize_key( (string) $details['changes']['Status'][1] );
                }
                if ( in_array( $status, $settings['excluded_post_statuses'], true ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function exportable_settings(): array {
        return array_intersect_key( self::settings(), array_fill_keys( self::EXPORTABLE_SETTINGS, true ) );
    }

    public static function event_groups(): array {
        $groups = [];
        foreach ( self::action_categories() as $action => $category ) {
            $groups[ $category ][] = $action;
        }
        return $groups;
    }

    public static function action_category( string $action ): ?string {
        $categories = self::action_categories();
        return $categories[ $action ] ?? null;
    }

    public static function action_enabled( string $action, ?array $settings = null ): bool {
        $settings = $settings ?? self::settings();
        if ( in_array( $action, $settings['disabled_actions'], true ) ) {
            return false;
        }
        $category = self::action_category( $action );
        if ( null === $category ) {
            return true;
        }
        $key = in_array( $category, [ 'woocommerce', 'redirection', 'acf', 'bbpress', 'wpforms', 'yoast', 'rank_math', 'pmpro', 'termly', 'mainwp', 'infinitewp' ], true ) ? $category : 'capture_' . $category;
        return (bool) $settings[ $key ];
    }

    public static function action_categories( bool $include_external = true ): array {
        $categories = [
            'content_created' => 'content', 'content_edited' => 'content', 'content_status' => 'content', 'content_trashed' => 'content', 'content_restored' => 'content',
            'user_created' => 'accounts', 'user_deleted' => 'accounts', 'user_removed_from_site' => 'accounts', 'role_changed' => 'accounts', 'signed_in' => 'accounts', 'signed_out' => 'accounts', 'sign_in_failed' => 'accounts', 'user_profile_changed' => 'accounts', 'password_changed' => 'accounts', 'session_ended' => 'accounts', 'sessions_ended' => 'accounts', 'session_policy_ended' => 'accounts', 'session_policy_changed' => 'accounts',
            'comment_created' => 'comments', 'comment_edited' => 'comments', 'comment_status' => 'comments', 'comment_deleted' => 'comments',
            'term_created' => 'taxonomies', 'term_edited' => 'taxonomies', 'term_deleted' => 'taxonomies', 'terms_changed' => 'taxonomies',
            'menu_created' => 'appearance', 'menu_updated' => 'appearance', 'menu_deleted' => 'appearance', 'menu_item_added' => 'appearance', 'menu_item_updated' => 'appearance', 'menu_item_deleted' => 'appearance', 'widget_changed' => 'appearance',
            'plugin_installed' => 'components', 'plugin_activated' => 'components', 'plugin_deactivated' => 'components', 'plugin_updated' => 'components', 'plugin_deleted' => 'components', 'theme_installed' => 'components', 'theme_switched' => 'components', 'theme_updated' => 'components', 'theme_deleted' => 'components',
            'plugin_settings_changed' => 'components', 'setting_changed' => 'settings', 'recording_rules_changed' => 'settings',
            'admin_email_change_requested' => 'settings', 'admin_email_changed' => 'settings',
            'site_created' => 'network', 'site_deleted' => 'network', 'site_status_changed' => 'network',
            'network_setting_changed' => 'network', 'network_admin_email_change_requested' => 'network', 'network_admin_email_changed' => 'network',
            'database_table_created' => 'database', 'database_table_deleted' => 'database',
            'file_created' => 'files', 'file_modified' => 'files', 'file_deleted' => 'files', 'file_permissions_changed' => 'files',
            'file_scan_started' => 'files', 'file_scan_completed' => 'files', 'file_scan_failed' => 'files', 'file_scan_size_skipped' => 'files',
            'order_created' => 'woocommerce', 'order_status_changed' => 'woocommerce', 'order_refunded' => 'woocommerce',
            'order_trashed' => 'woocommerce', 'order_restored' => 'woocommerce', 'order_deleted' => 'woocommerce',
            'product_created' => 'woocommerce', 'product_edited' => 'woocommerce', 'product_status_changed' => 'woocommerce',
            'product_trashed' => 'woocommerce', 'product_restored' => 'woocommerce', 'product_deleted' => 'woocommerce',
            'coupon_created' => 'woocommerce', 'coupon_edited' => 'woocommerce', 'coupon_status_changed' => 'woocommerce',
            'coupon_trashed' => 'woocommerce', 'coupon_restored' => 'woocommerce', 'coupon_deleted' => 'woocommerce',
            'woocommerce_setting_changed' => 'woocommerce',
            'acf_fields_changed' => 'acf',
            'forum_created' => 'bbpress', 'forum_edited' => 'bbpress', 'forum_status_changed' => 'bbpress', 'forum_trashed' => 'bbpress', 'forum_restored' => 'bbpress', 'forum_deleted' => 'bbpress',
            'topic_created' => 'bbpress', 'topic_edited' => 'bbpress', 'topic_status_changed' => 'bbpress', 'topic_stickied' => 'bbpress', 'topic_unstickied' => 'bbpress', 'topic_trashed' => 'bbpress', 'topic_restored' => 'bbpress', 'topic_deleted' => 'bbpress',
            'reply_created' => 'bbpress', 'reply_edited' => 'bbpress', 'reply_status_changed' => 'bbpress', 'reply_trashed' => 'bbpress', 'reply_restored' => 'bbpress', 'reply_deleted' => 'bbpress',
            'wpforms_form_created' => 'wpforms', 'wpforms_form_edited' => 'wpforms', 'wpforms_form_status_changed' => 'wpforms', 'wpforms_form_trashed' => 'wpforms', 'wpforms_form_restored' => 'wpforms', 'wpforms_form_deleted' => 'wpforms', 'wpforms_entry_submitted' => 'wpforms',
            'yoast_seo_meta_changed' => 'yoast', 'yoast_seo_settings_changed' => 'yoast',
            'rank_math_seo_meta_changed' => 'rank_math', 'rank_math_seo_settings_changed' => 'rank_math',
            'membership_changed' => 'pmpro', 'membership_level_created' => 'pmpro', 'membership_level_updated' => 'pmpro', 'membership_level_deleted' => 'pmpro',
            'termly_connected' => 'termly', 'termly_disconnected' => 'termly', 'termly_banner_changed' => 'termly',
            'termly_autoblocker_changed' => 'termly', 'termly_scan_schedule_changed' => 'termly', 'termly_robot_allowlist_changed' => 'termly',
            'mainwp_site_added' => 'mainwp', 'mainwp_site_updated' => 'mainwp', 'mainwp_site_deleted' => 'mainwp',
            'mainwp_site_suspended' => 'mainwp', 'mainwp_site_resumed' => 'mainwp', 'mainwp_site_sync_succeeded' => 'mainwp', 'mainwp_site_sync_failed' => 'mainwp',
            'mainwp_client_created' => 'mainwp', 'mainwp_client_updated' => 'mainwp', 'mainwp_client_deleted' => 'mainwp', 'mainwp_client_suspended' => 'mainwp', 'mainwp_client_resumed' => 'mainwp',
            'infinitewp_backup_completed' => 'infinitewp',
            'redirect_updated' => 'redirection', 'redirect_deleted' => 'redirection', 'redirect_enabled' => 'redirection', 'redirect_disabled' => 'redirection',
        ];
        if ( $include_external ) {
            foreach ( self::external_event_types() as $action => $definition ) {
                if ( ! isset( $categories[ $action ] ) ) {
                    $categories[ $action ] = $definition['category'];
                }
            }
        }
        return $categories;
    }

    public static function external_event_types(): array {
        $registered = apply_filters( 'alfa_activity_log_external_event_types', [] );
        if ( ! is_array( $registered ) ) {
            return [];
        }
        $reserved = self::action_categories( false );
        $allowed_categories = [ 'content', 'accounts', 'comments', 'taxonomies', 'appearance', 'components', 'settings', 'network', 'database', 'files', 'woocommerce', 'redirection', 'acf', 'bbpress', 'wpforms', 'yoast', 'rank_math', 'pmpro', 'termly', 'mainwp', 'infinitewp' ];
        $types = [];
        foreach ( array_slice( $registered, 0, 100, true ) as $action => $definition ) {
            if ( ! is_string( $action ) || ! preg_match( '/\A[a-z][a-z0-9_]{0,39}\z/', $action ) || isset( $reserved[ $action ] ) || ! is_array( $definition ) ) {
                continue;
            }
            $category = isset( $definition['category'] ) && is_string( $definition['category'] ) ? sanitize_key( $definition['category'] ) : '';
            $label = isset( $definition['label'] ) && is_string( $definition['label'] ) ? wp_html_excerpt( sanitize_text_field( $definition['label'] ), 80, '' ) : '';
            if ( ! in_array( $category, $allowed_categories, true ) || '' === $label ) {
                continue;
            }
            $types[ $action ] = [ 'category' => $category, 'label' => $label ];
        }
        return $types;
    }

    public static function action_labels(): array {
        $labels = [];
        foreach ( self::external_event_types() as $action => $definition ) {
            $labels[ $action ] = $definition['label'];
        }
        return $labels;
    }

    public static function schedule(): void {
        if ( wp_next_scheduled( self::CLEANUP ) ) {
            self::clear_failure( 'schedule_error' );
            return;
        }

        $result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP, [], true );
        if ( false === $result || is_wp_error( $result ) ) {
            self::record_failure( 'schedule_error' );
        } else {
            self::clear_failure( 'schedule_error' );
        }
    }

    public static function cleanup(): void {
        global $wpdb;
        $statistics_outputs_clean = self::cleanup_statistics_outputs();
        $table = self::table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::settings()['retention'] * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Select a bounded batch of expired events before deleting related notes.
        $expired_ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM %i WHERE occurred_at < %s ORDER BY occurred_at, id LIMIT %d',
            $table,
            $cutoff,
            self::CLEANUP_SIZE
        ) );
        if ( $wpdb->last_error || ! is_array( $expired_ids ) ) {
            self::record_failure( 'cleanup_error' );
            return;
        }

        $deleted = 0;
        if ( $expired_ids ) {
            $ids = array_map( 'intval', $expired_ids );
            $placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- Every dynamic event ID has its own integer placeholder.
            $notes_deleted = $wpdb->query( $wpdb->prepare(
                'DELETE FROM %i WHERE event_id IN (' . $placeholders . ')',
                self::notes_table(),
                ...$ids
            ) );
            if ( false === $notes_deleted ) {
                self::record_failure( 'cleanup_error' );
                return;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- Every dynamic event ID has its own integer placeholder.
            $deleted = $wpdb->query( $wpdb->prepare(
                'DELETE FROM %i WHERE id IN (' . $placeholders . ')',
                $table,
                ...$ids
            ) );
            if ( false === $deleted ) {
                self::record_failure( 'cleanup_error' );
                return;
            }
        }

        $health = (array) get_option( self::HEALTH, [] );
        $health['last_cleanup'] = time();
        if ( $statistics_outputs_clean ) {
            unset( $health['cleanup_error'] );
        } else {
            $health['cleanup_error'] = time();
        }
        update_option( self::HEALTH, $health, false );
        if ( self::CLEANUP_SIZE === $deleted && ! wp_next_scheduled( self::CONTINUE_CLEANUP ) ) {
            $scheduled = wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_CLEANUP, [], true );
            if ( false === $scheduled || ( is_wp_error( $scheduled ) && 'duplicate_event' !== $scheduled->get_error_code() ) ) {
                self::record_failure( 'cleanup_error' );
            }
        }
    }

    private static function record_failure( string $key ): void {
        $health = (array) get_option( self::HEALTH, [] );
        if ( time() - (int) ( $health[ $key ] ?? 0 ) >= MINUTE_IN_SECONDS ) {
            $health[ $key ] = time();
            update_option( self::HEALTH, $health, false );
        }
    }

    private static function clear_failure( string $key ): void {
        $health = (array) get_option( self::HEALTH, [] );
        if ( array_key_exists( $key, $health ) ) {
            unset( $health[ $key ] );
            update_option( self::HEALTH, $health, false );
        }
    }

    public static function add( array $row, array $details ): bool {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        $stored = false;
        try {
            $encoded = wp_json_encode( $details, JSON_INVALID_UTF8_SUBSTITUTE );
            $row['occurred_at'] = gmdate( 'Y-m-d H:i:s' );
            $row['details'] = $encoded && strlen( $encoded ) <= 12000 ? $encoded : '{}';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Store one event in the plugin owned table.
            if ( false === $wpdb->insert( self::table(), $row ) ) {
                self::record_failure( 'write_error' );
                return false;
            } else {
                $stored = true;
                self::clear_failure( 'write_error' );
            }
        } catch ( \Throwable $error ) {
            self::record_failure( 'write_error' );
            return $stored;
        } finally {
            $wpdb->suppress_errors( $previous );
        }
        try {
            $settings = self::settings();
            $category = self::action_category( (string) $row['action_key'] );
            if ( 'warning' === $row['severity'] && $settings['email_alerts'] && in_array( $category, $settings['email_alert_categories'], true ) ) {
                self::schedule_alert_digest( (int) $wpdb->insert_id );
            }
            if ( class_exists( Alerts::class ) ) {
                Alerts::queue_event( (int) $wpdb->insert_id, $row );
            }
        } catch ( \Throwable $error ) {
            self::record_failure( 'alert_rule_error' );
        }
        return true;
    }

    public static function mainwp_export_batch( int $after_id, int $limit = 50 ): array|\WP_Error {
        global $wpdb;
        $limit = min( 50, max( 1, $limit ) );
        $query = $wpdb->prepare(
            'SELECT id, occurred_at, actor_kind, actor_name, actor_role, source, item_kind, item_key, item_name, action_key, severity, summary, details
            FROM %i WHERE id > %d AND origin_site_id = 0 ORDER BY id ASC LIMIT %d',
            self::table(),
            max( 0, $after_id ),
            $limit
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The export is a bounded, indexed read from the plugin table.
        $rows = $wpdb->get_results( $query, ARRAY_A );
        if ( $wpdb->last_error || ! is_array( $rows ) ) {
            return self::unavailable();
        }
        foreach ( $rows as &$row ) {
            $details = json_decode( (string) $row['details'], true );
            $row['details'] = is_array( $details ) ? $details : [];
            $row['id'] = (int) $row['id'];
        }
        unset( $row );
        return $rows;
    }

    public static function import_mainwp_event( int $site_id, array $event ): bool {
        global $wpdb;
        $event_id = absint( $event['id'] ?? 0 );
        $action = isset( $event['action_key'] ) && is_string( $event['action_key'] ) ? sanitize_key( $event['action_key'] ) : '';
        $item_kind = isset( $event['item_kind'] ) && is_string( $event['item_kind'] ) ? sanitize_key( $event['item_kind'] ) : '';
        $occurred_at = isset( $event['occurred_at'] ) && is_string( $event['occurred_at'] ) ? $event['occurred_at'] : '';
        $date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $occurred_at, new \DateTimeZone( 'UTC' ) );
        if ( $site_id < 1 || $event_id < 1 || ! in_array( $action, Capture::action_types(), true )
            || ! in_array( $item_kind, self::available_item_kinds(), true )
            || ! in_array( $event['severity'] ?? '', Capture::SEVERITIES, true )
            || ! in_array( $event['actor_kind'] ?? '', [ 'user', 'anonymous', 'system', 'cli', 'unknown' ], true )
            || ! in_array( $event['source'] ?? '', Capture::SOURCES, true )
            || ! $date || $date->format( 'Y-m-d H:i:s' ) !== $occurred_at
            || ! is_array( $event['details'] ?? null ) ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevent duplicate imports when a MainWP sync is retried.
        $existing = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE origin_site_id = %d AND origin_event_id = %d LIMIT 1',
            self::table(),
            $site_id,
            $event_id
        ) );
        if ( $wpdb->last_error ) {
            return false;
        }
        if ( null !== $existing ) {
            return true;
        }

        $details = self::sanitize_mainwp_value( $event['details'] );
        if ( ! is_array( $details ) ) {
            $details = [];
        }
        $encoded = wp_json_encode( $details, JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! $encoded || strlen( $encoded ) > 12000 ) {
            $encoded = '{}';
        }
        $row = [
            'occurred_at' => $occurred_at,
            'actor_kind' => $event['actor_kind'],
            'actor_id' => 0,
            'actor_name' => isset( $event['actor_name'] ) && is_string( $event['actor_name'] ) ? wp_html_excerpt( sanitize_text_field( $event['actor_name'] ), 191, '' ) : '',
            'actor_role' => isset( $event['actor_role'] ) && is_string( $event['actor_role'] ) ? substr( sanitize_key( $event['actor_role'] ), 0, 64 ) : '',
            'source' => $event['source'],
            'origin_site_id' => $site_id,
            'origin_event_id' => $event_id,
            'item_kind' => $item_kind,
            'item_key' => isset( $event['item_key'] ) && is_scalar( $event['item_key'] ) ? wp_html_excerpt( sanitize_text_field( (string) $event['item_key'] ), 191, '' ) : '',
            'item_name' => isset( $event['item_name'] ) && is_string( $event['item_name'] ) ? wp_html_excerpt( sanitize_text_field( $event['item_name'] ), 255, '' ) : '',
            'action_key' => $action,
            'severity' => $event['severity'],
            'summary' => isset( $event['summary'] ) && is_string( $event['summary'] ) ? sanitize_text_field( $event['summary'] ) : '',
            'details' => $encoded,
        ];
        $previous = $wpdb->suppress_errors( true );
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Store a validated event received over MainWP's authenticated child connection.
            $inserted = $wpdb->insert( self::table(), $row );
            if ( false === $inserted ) {
                self::record_failure( 'write_error' );
                return false;
            }
            self::clear_failure( 'write_error' );
            return true;
        } catch ( \Throwable $error ) {
            self::record_failure( 'write_error' );
            return false;
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    private static function sanitize_mainwp_value( mixed $value, int $depth = 0 ): mixed {
        if ( $depth > 6 ) {
            return null;
        }
        if ( is_string( $value ) ) {
            return wp_html_excerpt( sanitize_text_field( $value ), 2000, '' );
        }
        if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
            return $value;
        }
        if ( ! is_array( $value ) ) {
            return null;
        }
        $clean = [];
        foreach ( array_slice( $value, 0, 50, true ) as $key => $child ) {
            $clean_key = is_int( $key ) ? $key : wp_html_excerpt( sanitize_text_field( (string) $key ), 128, '' );
            if ( '' === $clean_key ) {
                continue;
            }
            $clean[ $clean_key ] = self::sanitize_mainwp_value( $child, $depth + 1 );
        }
        return $clean;
    }

    private static function filter_where( array $filters, ?array $event_groups = null ): array {
        global $wpdb;
        $conditions = [ '1=1' ];
        $params = [];
        foreach ( [ 'action' => 'action_key', 'severity' => 'severity', 'actor_role' => 'actor_role', 'source' => 'source', 'item_kind' => 'item_kind', 'item_id' => 'item_key' ] as $key => $column ) {
            if ( ! empty( $filters[ $key ] ) ) {
                $conditions[] = "$column = %s";
                $params[] = $filters[ $key ];
            }
        }
        if ( ! empty( $filters['event_id'] ) ) {
            $conditions[] = 'id = %d';
            $params[] = (int) $filters['event_id'];
        }
        if ( ! empty( $filters['site_id'] ) ) {
            $conditions[] = 'origin_site_id = %d';
            $params[] = (int) $filters['site_id'];
        }
        if ( ! empty( $filters['category'] ) ) {
            $actions = ( $event_groups ?? self::event_groups() )[ $filters['category'] ] ?? [];
            if ( $actions ) {
                $conditions[] = 'action_key IN (' . implode( ', ', array_fill( 0, count( $actions ), '%s' ) ) . ')';
                array_push( $params, ...$actions );
            }
        }
        if ( ! empty( $filters['actor_kind'] ) && empty( $filters['actor_id'] ) ) {
            $conditions[] = 'actor_kind = %s';
            $params[] = $filters['actor_kind'];
        }
        if ( ! empty( $filters['actor_id'] ) ) {
            $conditions[] = "actor_kind = 'user' AND actor_id = %d";
            $params[] = (int) $filters['actor_id'];
        } elseif ( ! empty( $filters['actor'] ) ) {
            $actor = $filters['actor'];
            if ( ctype_digit( $actor ) ) {
                $user = get_user_by( 'login', $actor );
                if ( $user ) {
                    $conditions[] = "actor_kind = 'user' AND actor_id = %d";
                    $params[] = (int) $user->ID;
                } else {
                    $conditions[] = "actor_kind = 'user' AND actor_id = %d";
                    $params[] = (int) $actor;
                }
            } elseif ( in_array( $actor, [ 'anonymous', 'system', 'unknown' ], true ) && empty( $filters['actor_kind'] ) ) {
                $user = get_user_by( 'login', $actor );
                if ( $user ) {
                    $conditions[] = "actor_kind = 'user' AND actor_id = %d";
                    $params[] = (int) $user->ID;
                } else {
                    $conditions[] = 'actor_kind = %s';
                    $params[] = $actor;
                }
            } else {
                $conditions[] = 'actor_name LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $actor ) . '%';
            }
        }
        if ( ! empty( $filters['item'] ) ) {
            $item = $filters['item'];
            if ( preg_match( '/\A([a-z0-9_-]{1,24}):(.+)\z/u', $item, $parts ) ) {
                $conditions[] = 'item_kind = %s AND item_key = %s';
                $params[] = $parts[1];
                $params[] = $parts[2];
            } else {
                $conditions[] = 'item_name LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $item ) . '%';
            }
        }
        if ( ! empty( $filters['q'] ) ) {
            $pattern = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
            $conditions[] = '(item_name LIKE %s OR actor_name LIKE %s OR summary LIKE %s)';
            array_push( $params, $pattern, $pattern, $pattern );
        }
        foreach ( [ 'from' => '>=', 'to' => '<' ] as $key => $operator ) {
            if ( ! empty( $filters[ $key ] ) ) {
                $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters[ $key ], wp_timezone() );
                if ( 'to' === $key ) {
                    $date = $date->modify( '+1 day' );
                }
                $conditions[] = "occurred_at $operator %s";
                $params[] = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            }
        }
        return [ implode( ' AND ', $conditions ), $params ];
    }

    public static function query( array $filters, int $limit, int $before = 0, int $ceiling = 0, bool $include_details = false ): array|\WP_Error {
        global $wpdb;
        [ $where, $params ] = self::filter_where( $filters );
        $params = array_merge( [ self::table() ], $params );
        if ( $before ) {
            $where .= ' AND id < %d';
            $params[] = $before;
        }
        if ( $ceiling ) {
            $where .= ' AND id <= %d';
            $params[] = $ceiling;
        }
        $params[] = $limit;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditions contain fixed columns and placeholders for every value.
        $columns = 'id, occurred_at, actor_kind, actor_id, actor_name, actor_role, source, origin_site_id, origin_event_id, item_kind, item_key, item_name, action_key, severity, summary';
        if ( $include_details ) {
            $columns .= ', details';
        }
        $query = "SELECT $columns
            FROM %i WHERE $where ORDER BY id DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- The table is custom and indexed; condition values are prepared before the query runs.
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
        return $wpdb->last_error ? self::unavailable() : $rows;
    }

    /**
     * Query recent activity across sites belonging to the current network.
     * This is intentionally available only to network administrators through
     * the network REST routes in Admin.
     *
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>|\WP_Error
     */
    public static function network_query( array $filters, int $limit, ?array $cursor = null, bool $include_details = false, ?string $ceiling = null ): array|\WP_Error {
        global $wpdb;
        if ( ! is_multisite() || ! is_super_admin() ) {
            return new \WP_Error( 'alfa_network_forbidden', __( 'Network activity is available to network administrators only.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }

        $network_groups = self::event_groups();
        if ( isset( $filters['category'] ) && ! array_key_exists( $filters['category'], $network_groups ) ) {
            return [];
        }
        $selected_site = isset( $filters['network_site_id'] ) ? (int) $filters['network_site_id'] : 0;
        if ( $selected_site ) {
            $site = get_site( $selected_site );
            if ( ! $site instanceof \WP_Site || (int) $site->site_id !== get_current_network_id()
                || (int) $site->deleted || (int) $site->archived || (int) $site->spam ) {
                return new \WP_Error( 'alfa_network_site', __( 'Choose a site in this network.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $site_ids = [ $selected_site ];
        } else {
            $site_ids = self::network_site_ids( get_current_network_id() );
            if ( count( $site_ids ) > 1000 ) {
                return new \WP_Error( 'alfa_network_size', __( 'This network has more than 1000 sites. Filter by a site ID to search its activity.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
        }

        unset( $filters['network_site_id'] );
        $parts = [];
        $params = [];
        $site_labels = [];
        foreach ( $site_ids as $site_id ) {
            if ( ! switch_to_blog( $site_id ) ) {
                continue;
            }
            try {
                $table = self::table();
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                    continue;
                }
                [ $where, $where_params ] = self::filter_where( $filters, $network_groups );
                if ( $cursor ) {
                    $where .= ' AND (occurred_at < %s OR (occurred_at = %s AND (%d < %d OR (%d = %d AND id < %d))))';
                    array_push( $where_params, $cursor['time'], $cursor['time'], $site_id, $cursor['site'], $site_id, $cursor['site'], $cursor['id'] );
                }
                if ( $ceiling ) {
                    $where .= ' AND occurred_at <= %s';
                    $where_params[] = $ceiling;
                }
                $columns = 'e.id, e.occurred_at, e.actor_kind, e.actor_id, e.actor_name, e.actor_role, e.source, e.origin_site_id, e.origin_event_id, e.item_kind, e.item_key, e.item_name, e.action_key, e.severity, e.summary';
                if ( $include_details ) {
                    $columns .= ', e.details';
                }
                $parts[] = "(SELECT %d AS network_site_id, $columns FROM %i AS e WHERE $where ORDER BY e.occurred_at DESC, e.id DESC LIMIT %d)";
                $params = array_merge( $params, [ $site_id, $table ], $where_params, [ $limit ] );
                $site = get_blog_details( $site_id );
                $site_labels[ $site_id ] = [
                    'name' => $site instanceof \WP_Site ? sanitize_text_field( $site->blogname ) : sprintf( __( 'Site %d', 'activity-log-for-admins' ), $site_id ),
                    'timezone' => wp_timezone_string() ?: 'UTC',
                ];
            } finally {
                restore_current_blog();
            }
        }

        if ( ! $parts ) {
            return [];
        }
        $params[] = $limit;
        $query = implode( ' UNION ALL ', $parts ) . ' ORDER BY occurred_at DESC, network_site_id DESC, id DESC LIMIT %d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- Site tables are generated by the plugin and all values and table identifiers use wpdb placeholders.
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
        if ( $wpdb->last_error || ! is_array( $rows ) ) {
            return self::unavailable();
        }
        foreach ( $rows as &$row ) {
            $site_id = (int) $row['network_site_id'];
            $row['network_site_id'] = (string) $site_id;
            $row['network_site_name'] = $site_labels[ $site_id ]['name'] ?? sprintf( __( 'Site %d', 'activity-log-for-admins' ), $site_id );
            $row['network_site_timezone'] = $site_labels[ $site_id ]['timezone'] ?? 'UTC';
        }
        unset( $row );
        return $rows;
    }

    /** @return array<int, int> */
    public static function network_site_ids( int $network_id ): array {
        $ids = [];
        $offset = 0;
        do {
            $batch = get_sites( [
                'network_id' => $network_id,
                'fields' => 'ids',
                'number' => 200,
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ] );
            foreach ( $batch as $site_id ) {
                $ids[] = (int) $site_id;
            }
            $offset += count( $batch );
        } while ( 200 === count( $batch ) );
        return $ids;
    }

    public static function report_statistics( array $filters, string $group, string $interval ): array|\WP_Error {
        global $wpdb;
        $group_expression = match ( $group ) {
            'action', 'category' => 'action_key',
            'severity' => 'severity',
            'actor_role' => "COALESCE(NULLIF(actor_role, ''), 'unknown')",
            'source' => "COALESCE(NULLIF(source, ''), 'unknown')",
            'day' => match ( $interval ) {
                'weekly' => 'DATE_SUB(DATE(occurred_at), INTERVAL WEEKDAY(occurred_at) DAY)',
                'monthly' => "CONCAT(YEAR(occurred_at), '-', LPAD(MONTH(occurred_at), 2, '0'), '-01')",
                default => 'DATE(occurred_at)',
            },
            default => 'action_key',
        };
        [ $where, $filter_params ] = self::filter_where( $filters );
        $params = array_merge( [ self::table() ], $filter_params );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- The grouping expression is selected from a fixed allowlist and the filter values are prepared.
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", ...$params ) );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }
        $direction = 'day' === $group ? 'ASC' : 'DESC';
        $row_limit = 'day' === $group ? 3650 : 500;
        $query = "SELECT $group_expression AS label, COUNT(*) AS total FROM %i WHERE $where GROUP BY label ORDER BY total $direction, label ASC LIMIT %d";
        $query_params = array_merge( $params, [ $row_limit ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- The grouping expression is selected from a fixed allowlist and the filter values are prepared.
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$query_params ), ARRAY_A );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }
        if ( 'category' === $group ) {
            $categories = [];
            foreach ( $rows as $row ) {
                $category = self::action_category( (string) $row['label'] ) ?? 'other';
                $categories[ $category ] = ( $categories[ $category ] ?? 0 ) + (int) $row['total'];
            }
            $rows = [];
            foreach ( $categories as $category => $count ) {
                $rows[] = [ 'label' => $category, 'total' => $count ];
            }
            usort( $rows, static fn( array $left, array $right ): int => $right['total'] <=> $left['total'] ?: strcmp( $left['label'], $right['label'] ) );
        }
        return [
            'group' => $group,
            'interval' => $interval,
            'total' => (int) $total,
            'rows' => array_map( static fn( array $row ): array => [ 'label' => (string) $row['label'], 'total' => (int) $row['total'] ], $rows ),
        ];
    }

    public static function saved_reports(): array {
        $saved = get_option( self::SAVED_REPORTS, [] );
        if ( ! is_array( $saved ) ) {
            return [];
        }
        $valid_filters = [ 'q', 'event_id', 'actor', 'actor_id', 'actor_kind', 'actor_role', 'source', 'item', 'item_kind', 'item_id', 'action', 'category', 'severity', 'site_id' ];
        $retention = self::settings()['retention'];
        $reports = [];
        foreach ( array_slice( $saved, 0, 20 ) as $report ) {
            if ( ! is_array( $report ) || ! is_string( $report['id'] ?? null )
                || ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $report['id'] ) ) {
                continue;
            }
            $filters = [];
            foreach ( (array) ( $report['filters'] ?? [] ) as $key => $value ) {
                if ( in_array( $key, $valid_filters, true ) && is_string( $value ) && '' !== $value ) {
                    $filters[ $key ] = sanitize_text_field( $value );
                }
            }
            $group = sanitize_key( (string) ( $report['group'] ?? 'category' ) );
            $interval = sanitize_key( (string) ( $report['interval'] ?? 'daily' ) );
            if ( ! in_array( $group, [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ], true )
                || ! in_array( $interval, [ 'daily', 'weekly', 'monthly' ], true ) ) {
                continue;
            }
            $stored_columns = [];
            foreach ( (array) ( $report['columns'] ?? self::REPORT_COLUMNS ) as $column ) {
                if ( is_string( $column ) && in_array( $column, self::REPORT_COLUMNS, true ) ) {
                    $stored_columns[] = $column;
                }
            }
            $columns = array_values( array_intersect( self::REPORT_COLUMNS, array_unique( $stored_columns ) ) );
            if ( ! $columns ) {
                $columns = self::REPORT_COLUMNS;
            }
            $tags = [];
            foreach ( array_slice( (array) ( $report['tags'] ?? [] ), 0, 5 ) as $tag ) {
                if ( ! is_string( $tag ) ) {
                    continue;
                }
                $tag = wp_html_excerpt( sanitize_text_field( $tag ), 30, '' );
                if ( '' !== $tag && ! in_array( strtolower( $tag ), array_map( 'strtolower', $tags ), true ) ) {
                    $tags[] = $tag;
                }
            }
            $normalized_report = [
                'id' => strtolower( $report['id'] ),
                'name' => wp_html_excerpt( sanitize_text_field( (string) ( $report['name'] ?? '' ) ), 100, '' ),
                'filters' => $filters,
                'group' => $group,
                'interval' => $interval,
                'range_days' => min( $retention, max( 1, (int) ( $report['range_days'] ?? 30 ) ) ),
                'columns' => $columns,
                'branding' => ! empty( $report['branding'] ),
                'tags' => $tags,
                'created_at' => preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) ( $report['created_at'] ?? '' ) ) ? (string) $report['created_at'] : '',
            ];
            $statistics = self::normalize_statistics_snapshot( $report['statistics'] ?? null );
            if ( null !== $statistics ) {
                $normalized_report['statistics'] = $statistics;
            }
            $reports[] = $normalized_report;
        }
        return $reports;
    }

    private static function normalize_statistics_snapshot( mixed $snapshot ): ?array {
        if ( ! is_array( $snapshot ) || ! is_string( $snapshot['from'] ?? null ) || ! is_string( $snapshot['to'] ?? null )
            || ! is_string( $snapshot['generated_at'] ?? null ) || ! in_array( $snapshot['group'] ?? '', [ 'category', 'action', 'severity', 'actor_role', 'source', 'day' ], true )
            || ! in_array( $snapshot['interval'] ?? '', [ 'daily', 'weekly', 'monthly' ], true )
            || ! is_int( $snapshot['total'] ?? null ) || $snapshot['total'] < 0 || ! is_array( $snapshot['rows'] ?? null )
            || ! array_is_list( $snapshot['rows'] ) || count( $snapshot['rows'] ) > 3700 ) {
            return null;
        }
        foreach ( [ $snapshot['from'], $snapshot['to'] ] as $date ) {
            if ( ! preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $date ) ) {
                return null;
            }
            $parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
            if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
                return null;
            }
        }
        if ( $snapshot['from'] > $snapshot['to'] || $snapshot['to'] > wp_date( 'Y-m-d' ) ) {
            return null;
        }
        $generated = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $snapshot['generated_at'], new \DateTimeZone( 'UTC' ) );
        if ( ! $generated || $generated->format( 'Y-m-d H:i:s' ) !== $snapshot['generated_at'] ) {
            return null;
        }
        $generated_at = $generated->getTimestamp();
        if ( $generated_at > time() + MINUTE_IN_SECONDS || $generated_at < time() - self::settings()['retention'] * DAY_IN_SECONDS ) {
            return null;
        }
        $from = \DateTimeImmutable::createFromFormat( '!Y-m-d', $snapshot['from'], wp_timezone() );
        $to = \DateTimeImmutable::createFromFormat( '!Y-m-d', $snapshot['to'], wp_timezone() );
        $days = $from && $to ? $from->diff( $to )->days + 1 : 0;
        if ( $days < 1 || $days > self::settings()['retention'] ) {
            return null;
        }
        $rows = [];
        $sum = 0;
        foreach ( $snapshot['rows'] as $row ) {
            if ( ! is_array( $row ) || ! is_string( $row['label'] ?? null ) || strlen( $row['label'] ) > 191
                || ! is_int( $row['total'] ?? null ) || $row['total'] < 0 ) {
                return null;
            }
            $rows[] = [ 'label' => sanitize_text_field( $row['label'] ), 'total' => $row['total'] ];
            $sum += $row['total'];
        }
        if ( $sum !== $snapshot['total'] ) {
            return null;
        }
        return [
            'from' => $snapshot['from'],
            'to' => $snapshot['to'],
            'generated_at' => $snapshot['generated_at'],
            'group' => $snapshot['group'],
            'interval' => $snapshot['interval'],
            'total' => $snapshot['total'],
            'rows' => $rows,
        ];
    }

    private static function cleanup_statistics_outputs(): bool {
        $reports = get_option( self::SAVED_REPORTS, [] );
        if ( ! is_array( $reports ) ) {
            return true;
        }
        $changed = false;
        foreach ( $reports as &$report ) {
            if ( ! is_array( $report ) || ! array_key_exists( 'statistics', $report ) ) {
                continue;
            }
            if ( null === self::normalize_statistics_snapshot( $report['statistics'] ) ) {
                unset( $report['statistics'] );
                $changed = true;
            }
        }
        unset( $report );
        if ( ! $changed ) {
            return true;
        }
        return update_option( self::SAVED_REPORTS, $reports, false ) || $reports === get_option( self::SAVED_REPORTS, [] );
    }

    public static function save_report_template( array $report ): array|\WP_Error {
        $reports = self::saved_reports();
        if ( count( $reports ) >= 20 ) {
            return new \WP_Error( 'alfa_saved_report_limit', __( 'Delete a saved report before adding another. A site can keep up to 20 report templates.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        $saved = [
            'id' => wp_generate_uuid4(),
            'name' => wp_html_excerpt( sanitize_text_field( (string) $report['name'] ), 100, '' ),
            'filters' => $report['filters'],
            'group' => $report['group'],
            'interval' => $report['interval'],
            'range_days' => (int) $report['range_days'],
            'columns' => array_values( array_intersect( self::REPORT_COLUMNS, (array) ( $report['columns'] ?? self::REPORT_COLUMNS ) ) ),
            'branding' => ! empty( $report['branding'] ),
            'tags' => array_values( array_unique( array_map( static fn( string $tag ): string => wp_html_excerpt( sanitize_text_field( $tag ), 30, '' ), array_filter( (array) ( $report['tags'] ?? [] ), 'is_string' ) ) ) ),
            'created_at' => current_time( 'mysql', true ),
        ];
        $statistics = self::normalize_statistics_snapshot( $report['statistics'] ?? null );
        if ( null !== $statistics ) {
            $saved['statistics'] = $statistics;
        }
        $reports[] = $saved;
        if ( ! update_option( self::SAVED_REPORTS, $reports, false ) && $reports !== self::saved_reports() ) {
            return new \WP_Error( 'alfa_saved_report_storage', __( 'The report template could not be saved.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        return $saved;
    }

    public static function delete_report_template( string $id ): bool|\WP_Error {
        $reports = self::saved_reports();
        $remaining = array_values( array_filter( $reports, static fn( array $report ): bool => ! hash_equals( $report['id'], $id ) ) );
        if ( count( $remaining ) === count( $reports ) ) {
            return new \WP_Error( 'alfa_saved_report_missing', __( 'The saved report was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        if ( ! update_option( self::SAVED_REPORTS, $remaining, false ) && $remaining !== self::saved_reports() ) {
            return new \WP_Error( 'alfa_saved_report_storage', __( 'The saved report could not be deleted.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        return true;
    }

    public static function dashboard_summary(): array|\WP_Error {
        global $wpdb;

        $table = self::table();
        $since_day = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $since_week = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Summarize the indexed custom event table for the administrator dashboard.
        $counts = $wpdb->get_row( $wpdb->prepare(
            'SELECT COUNT(*) AS week_count,
                COALESCE(SUM(occurred_at >= %s), 0) AS day_count,
                COALESCE(SUM(severity = %s), 0) AS warning_count
                FROM %i WHERE occurred_at >= %s',
            $since_day,
            'warning',
            $table,
            $since_week
        ), ARRAY_A );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }

        $events = self::query( [], 5 );
        if ( is_wp_error( $events ) ) {
            return $events;
        }

        return [
            'last_day' => (int) ( $counts['day_count'] ?? 0 ),
            'last_week' => (int) ( $counts['week_count'] ?? 0 ),
            'warnings' => (int) ( $counts['warning_count'] ?? 0 ),
            'events' => $events,
        ];
    }

    public static function event( int $id ): array|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read one event from the plugin owned table by primary key.
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }
        if ( ! $row ) {
            return new \WP_Error( 'alfa_not_found', __( 'This event is no longer available.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $row['details'] = json_decode( $row['details'], true ) ?: [];
        $row['notes'] = self::notes_for_event( $id );
        if ( is_wp_error( $row['notes'] ) ) {
            return $row['notes'];
        }
        return $row;
    }

    public static function notes_for_event( int $event_id ): array|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the notes for one authorized event from the plugin owned table.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, user_id, created_at, note FROM %i WHERE event_id = %d ORDER BY id ASC',
            self::notes_table(),
            $event_id
        ), ARRAY_A );
        if ( $wpdb->last_error || ! is_array( $rows ) ) {
            return self::unavailable();
        }
        foreach ( $rows as &$row ) {
            $user = get_userdata( (int) $row['user_id'] );
            $row['id'] = (string) $row['id'];
            $row['user_id'] = (string) $row['user_id'];
            $row['author_name'] = $user instanceof \WP_User ? $user->display_name : __( 'Deleted user', 'activity-log-for-admins' );
            $timestamp = strtotime( $row['created_at'] . ' UTC' );
            $row['created_iso'] = gmdate( DATE_ATOM, $timestamp );
            $row['created_label'] = wp_date( 'j M Y, H:i:s T', $timestamp );
        }
        unset( $row );
        return $rows;
    }

    public static function add_note( int $event_id, string $note ): array|\WP_Error {
        global $wpdb;
        $event = self::event_exists( $event_id );
        if ( is_wp_error( $event ) ) {
            return $event;
        }
        $note = trim( sanitize_textarea_field( $note ) );
        if ( function_exists( 'mb_strlen' ) ) {
            $length = mb_strlen( $note, 'UTF-8' );
        } else {
            $length = preg_match_all( '/./us', $note, $characters );
            $length = false === $length ? PHP_INT_MAX : $length;
        }
        if ( '' === $note || $length > 2000 ) {
            return new \WP_Error( 'alfa_note_invalid', __( 'Enter a note with 1 to 2000 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Keep event notes bounded for predictable detail reads and cleanup.
        $note_count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', self::notes_table(), $event_id ) );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }
        if ( (int) $note_count >= self::MAX_EVENT_NOTES ) {
            return new \WP_Error( 'alfa_note_limit', __( 'This event has reached its 50 note limit.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Store an administrator note for one plugin event.
        if ( false === $wpdb->insert( self::notes_table(), [
            'event_id' => $event_id,
            'user_id' => get_current_user_id(),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'note' => $note,
        ], [ '%d', '%d', '%s', '%s' ] ) ) {
            return self::unavailable();
        }
        $notes = self::notes_for_event( $event_id );
        if ( is_wp_error( $notes ) ) {
            return $notes;
        }
        return $notes ? end( $notes ) : new \WP_Error( 'alfa_note_storage', __( 'The note was saved, but could not be loaded.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
    }

    private static function event_exists( int $event_id ): bool|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the event exists before storing its note.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d', self::table(), $event_id ) );
        if ( $wpdb->last_error ) {
            return self::unavailable();
        }
        if ( ! $exists ) {
            return new \WP_Error( 'alfa_not_found', __( 'This event is no longer available.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        return true;
    }

    public static function latest_id(): int|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current export boundary from the plugin owned table.
        $id = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', self::table() ) );
        return $wpdb->last_error ? self::unavailable() : (int) $id;
    }

    public static function health(): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read live health details from the plugin owned table.
        $oldest = $wpdb->get_var( $wpdb->prepare( 'SELECT occurred_at FROM %i ORDER BY occurred_at, id LIMIT 1', $table ) );
        $available = ! $wpdb->last_error;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLE STATUS reports the live allocated size of the custom table.
        $status = $available ? $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A ) : null;
        $notes_status = $available ? $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( self::notes_table() ) ), ARRAY_A ) : null;
        $file_status = $available ? $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( self::file_index_table() ) ), ARRAY_A ) : null;
        $health = (array) get_option( self::HEALTH, [] );
        $file_state = (array) get_option( self::FILE_SCAN_STATE, [] );
        $byte_tables = [ $status, $notes_status, $file_status ];
        $bytes = 0;
        $bytes_known = true;
        foreach ( $byte_tables as $byte_table ) {
            if ( ! is_array( $byte_table ) || ! isset( $byte_table['Data_length'], $byte_table['Index_length'] ) ) {
                $bytes_known = false;
                break;
            }
            $bytes += (int) $byte_table['Data_length'] + (int) $byte_table['Index_length'];
        }
        return [
            'available'     => $available,
            'oldest'        => $oldest,
            'bytes'         => $bytes_known ? $bytes : null,
            'last_cleanup'  => $health['last_cleanup'] ?? null,
            'write_error'   => $health['write_error'] ?? null,
            'schema_error'  => $health['schema_error'] ?? null,
            'cleanup_error' => $health['cleanup_error'] ?? null,
            'schedule_error' => $health['schedule_error'] ?? null,
            'alert_error'   => $health['alert_error'] ?? null,
            'alert_schedule_error' => $health['alert_schedule_error'] ?? null,
            'alert_rule_error' => $health['alert_rule_error'] ?? null,
            'report_schedule_error' => $health['report_schedule_error'] ?? null,
            'next_cleanup'  => wp_next_scheduled( self::CLEANUP ) ?: null,
            'file_scan_last' => $health['file_scan_last'] ?? null,
            'file_scan_error' => $health['file_scan_error'] ?? null,
            'file_schedule_error' => $health['file_schedule_error'] ?? null,
            'file_scan_running' => ! empty( $file_state['generation'] ),
            'file_scan_phase' => sanitize_key( (string) ( $file_state['phase'] ?? '' ) ),
            'next_file_scan' => class_exists( FileMonitor::class ) ? FileMonitor::next_scan() : null,
        ];
    }

    private static function schedule_alert_digest( int $first_event_id ): void {
        if ( wp_next_scheduled( self::ALERT_DIGEST ) ) {
            return;
        }
        update_option( 'alfa_alert_digest_after_id', max( 0, $first_event_id ), false );
        $scheduled = wp_schedule_single_event( time() + 900, self::ALERT_DIGEST, [], true );
        if ( false === $scheduled || ( is_wp_error( $scheduled ) && 'duplicate_event' !== $scheduled->get_error_code() ) ) {
            self::record_failure( 'alert_schedule_error' );
        } else {
            self::clear_failure( 'alert_schedule_error' );
        }
    }

    public static function send_alert_digest(): void {
        $settings = self::settings();
        if ( ! $settings['email_alerts'] ) {
            return;
        }
        $recipients = $settings['email_alert_recipients'];
        if ( ! $recipients ) {
            $recipients = [ sanitize_email( (string) get_option( 'admin_email' ) ) ];
        }
        $recipients = array_values( array_filter( $recipients, 'is_email' ) );
        if ( ! $recipients ) {
            self::record_failure( 'alert_error' );
            return;
        }

        $actions = [];
        foreach ( $settings['email_alert_categories'] as $category ) {
            array_push( $actions, ...( self::event_groups()[ $category ] ?? [] ) );
        }
        $actions = array_values( array_unique( $actions ) );
        if ( ! $actions ) {
            delete_option( 'alfa_alert_digest_after_id' );
            return;
        }

        global $wpdb;
        $after_id = (int) get_option( 'alfa_alert_digest_after_id', 0 );
        $placeholders = implode( ', ', array_fill( 0, count( $actions ), '%s' ) );
        $query = 'SELECT id, occurred_at, summary FROM %i WHERE severity = %s AND id >= %d AND action_key IN (' . $placeholders . ') ORDER BY id DESC LIMIT %d';
        $params = array_merge( [ self::table(), 'warning', $after_id ], $actions, [ $settings['email_alert_event_limit'] ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- Read a bounded set of selected warning summaries using a fixed placeholder list.
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
        if ( $wpdb->last_error ) {
            self::record_failure( 'alert_error' );
            return;
        }
        if ( ! $rows ) {
            return;
        }

        $lines = array_map(
            static function ( array $row ): string {
                $timestamp = strtotime( $row['occurred_at'] . ' UTC' );
                return wp_date( 'j M Y, H:i T', $timestamp ) . '  ' . sanitize_text_field( $row['summary'] );
            },
            $rows
        );
        $body = __( 'Activity Log recorded warning events on your site.', 'activity-log-for-admins' ) . "\n\n";
        if ( $settings['email_alert_include_events'] ) {
            $body .= implode( "\n", $lines ) . "\n\n";
        } else {
            $body .= __( 'Event summaries were omitted by your notification settings.', 'activity-log-for-admins' ) . "\n\n";
        }
        $body .= __( 'This digest includes up to the configured number of event summaries. Review the Activity Log in WordPress for details.', 'activity-log-for-admins' );
        $site_name = sanitize_text_field( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
        $subject = sprintf( __( '[%s] Activity Log warning digest', 'activity-log-for-admins' ), $site_name );
        if ( wp_mail( $recipients, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] ) ) {
            update_option( 'alfa_alert_digest_after_id', (int) $rows[0]['id'] + 1, false );
            self::clear_failure( 'alert_error' );
        } else {
            self::record_failure( 'alert_error' );
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::ALERT_DIGEST );
        }
    }

    private static function normalize_email_recipients( mixed $recipients ): array {
        if ( ! is_array( $recipients ) ) {
            return [];
        }
        $valid = [];
        foreach ( array_slice( $recipients, 0, 10 ) as $recipient ) {
            if ( ! is_string( $recipient ) ) {
                continue;
            }
            $email = sanitize_email( trim( $recipient ) );
            if ( is_email( $email ) ) {
                $valid[] = strtolower( $email );
            }
        }
        return array_values( array_unique( $valid ) );
    }

    private static function unavailable(): \WP_Error {
        return new \WP_Error( 'alfa_storage', __( 'The activity log database is unavailable. Check Settings for diagnostics.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
    }
}
