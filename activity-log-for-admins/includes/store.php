<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Store {
    public const SETTINGS = 'alfa_settings';
    public const HEALTH = 'alfa_health';
    public const SCHEMA = 'alfa_schema';
    public const CLEANUP = 'alfa_cleanup';
    public const CONTINUE_CLEANUP = 'alfa_continue_cleanup';
    public const ALERT_DIGEST = 'alfa_alert_digest';
    public const PAGE_SIZE = 50;
    private const SCHEMA_VERSION = 2;
    private const CLEANUP_SIZE = 1000;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'alfa_events';
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
        $required_columns = [ 'id', 'occurred_at', 'actor_kind', 'actor_id', 'actor_name', 'item_kind', 'item_key', 'item_name', 'action_key', 'severity', 'summary', 'details' ];
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
            return;
        }
        if ( ! self::install() ) {
            self::record_failure( 'schema_error' );
            return;
        }
        self::schedule();
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
            'email_alerts' => in_array( $saved['email_alerts'] ?? false, [ true, 1, '1' ], true ),
        ];
        foreach ( [ 'content', 'accounts', 'comments', 'taxonomies', 'appearance', 'components', 'settings' ] as $category ) {
            $settings[ 'capture_' . $category ] = ! array_key_exists( 'capture_' . $category, $saved )
                || in_array( $saved[ 'capture_' . $category ], [ true, 1, '1' ], true );
        }
        return $settings;
    }

    public static function action_enabled( string $action ): bool {
        $settings = self::settings();
        $categories = [
            'content_created' => 'content', 'content_edited' => 'content', 'content_status' => 'content', 'content_trashed' => 'content', 'content_restored' => 'content',
            'user_created' => 'accounts', 'user_deleted' => 'accounts', 'user_removed_from_site' => 'accounts', 'role_changed' => 'accounts', 'signed_in' => 'accounts', 'signed_out' => 'accounts', 'sign_in_failed' => 'accounts', 'user_profile_changed' => 'accounts', 'password_changed' => 'accounts',
            'comment_created' => 'comments', 'comment_edited' => 'comments', 'comment_status' => 'comments', 'comment_deleted' => 'comments',
            'term_created' => 'taxonomies', 'term_edited' => 'taxonomies', 'term_deleted' => 'taxonomies', 'terms_changed' => 'taxonomies',
            'menu_created' => 'appearance', 'menu_updated' => 'appearance', 'menu_deleted' => 'appearance', 'menu_item_added' => 'appearance', 'menu_item_updated' => 'appearance', 'menu_item_deleted' => 'appearance', 'widget_changed' => 'appearance',
            'plugin_installed' => 'components', 'plugin_activated' => 'components', 'plugin_deactivated' => 'components', 'plugin_updated' => 'components', 'plugin_deleted' => 'components', 'theme_installed' => 'components', 'theme_switched' => 'components', 'theme_updated' => 'components', 'theme_deleted' => 'components',
            'setting_changed' => 'settings',
            'order_created' => 'woocommerce', 'order_status_changed' => 'woocommerce', 'order_refunded' => 'woocommerce',
            'redirect_updated' => 'redirection', 'redirect_deleted' => 'redirection', 'redirect_enabled' => 'redirection', 'redirect_disabled' => 'redirection',
        ];
        if ( ! isset( $categories[ $action ] ) ) {
            return true;
        }
        $category = $categories[ $action ];
        $key = in_array( $category, [ 'woocommerce', 'redirection' ], true ) ? $category : 'capture_' . $category;
        return (bool) $settings[ $key ];
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
        $table = self::table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::settings()['retention'] * DAY_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention deletes a bounded batch from the indexed event table.
        $deleted = $wpdb->query( $wpdb->prepare(
            'DELETE FROM %i WHERE occurred_at < %s ORDER BY occurred_at, id LIMIT %d',
            $table,
            $cutoff,
            self::CLEANUP_SIZE
        ) );
        if ( false === $deleted ) {
            self::record_failure( 'cleanup_error' );
            return;
        }

        $health = (array) get_option( self::HEALTH, [] );
        $health['last_cleanup'] = time();
        unset( $health['cleanup_error'] );
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

    public static function add( array $row, array $details ): void {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        try {
            $encoded = wp_json_encode( $details, JSON_INVALID_UTF8_SUBSTITUTE );
            $row['occurred_at'] = gmdate( 'Y-m-d H:i:s' );
            $row['details'] = $encoded && strlen( $encoded ) <= 12000 ? $encoded : '{}';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Store one event in the plugin owned table.
            if ( false === $wpdb->insert( self::table(), $row ) ) {
                self::record_failure( 'write_error' );
            } else {
                self::clear_failure( 'write_error' );
                if ( 'warning' === $row['severity'] && self::settings()['email_alerts'] ) {
                    self::schedule_alert_digest( (int) $wpdb->insert_id );
                }
            }
        } catch ( \Throwable $error ) {
            self::record_failure( 'write_error' );
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    public static function query( array $filters, int $limit, int $before = 0, int $ceiling = 0, bool $include_details = false ): array|\WP_Error {
        global $wpdb;
        $conditions = [ '1=1' ];
        $params = [ self::table() ];
        foreach ( [ 'action' => 'action_key', 'severity' => 'severity' ] as $key => $column ) {
            if ( ! empty( $filters[ $key ] ) ) {
                $conditions[] = "$column = %s";
                $params[] = $filters[ $key ];
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
        if ( $before ) {
            $conditions[] = 'id < %d';
            $params[] = $before;
        }
        if ( $ceiling ) {
            $conditions[] = 'id <= %d';
            $params[] = $ceiling;
        }
        $where = implode( ' AND ', $conditions );
        $params[] = $limit;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditions contain fixed columns and placeholders for every value.
        $columns = 'id, occurred_at, actor_kind, actor_id, actor_name, item_kind, item_key, item_name, action_key, severity, summary';
        if ( $include_details ) {
            $columns .= ', details';
        }
        $query = "SELECT $columns
            FROM %i WHERE $where ORDER BY id DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- The table is custom and indexed; condition values are prepared before the query runs.
        $rows = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );
        return $wpdb->last_error ? self::unavailable() : $rows;
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
        return $row;
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
        $health = (array) get_option( self::HEALTH, [] );
        return [
            'available'     => $available,
            'oldest'        => $oldest,
            'bytes'         => isset( $status['Data_length'], $status['Index_length'] ) ? (int) $status['Data_length'] + (int) $status['Index_length'] : null,
            'last_cleanup'  => $health['last_cleanup'] ?? null,
            'write_error'   => $health['write_error'] ?? null,
            'schema_error'  => $health['schema_error'] ?? null,
            'cleanup_error' => $health['cleanup_error'] ?? null,
            'schedule_error' => $health['schedule_error'] ?? null,
            'alert_error'   => $health['alert_error'] ?? null,
            'alert_schedule_error' => $health['alert_schedule_error'] ?? null,
            'next_cleanup'  => wp_next_scheduled( self::CLEANUP ) ?: null,
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
        if ( ! self::settings()['email_alerts'] ) {
            return;
        }
        $email = sanitize_email( (string) get_option( 'admin_email' ) );
        if ( ! is_email( $email ) ) {
            self::record_failure( 'alert_error' );
            return;
        }

        global $wpdb;
        $after_id = (int) get_option( 'alfa_alert_digest_after_id', 0 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read a bounded set of warning summaries for the administrator opted in alert digest.
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, occurred_at, summary FROM %i WHERE severity = %s AND id >= %d ORDER BY id DESC LIMIT 20',
            self::table(), 'warning', $after_id
        ), ARRAY_A );
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
        $body = __( 'Activity Log recorded warning events on your site:', 'activity-log-for-admins' ) . "\n\n"
            . implode( "\n", $lines ) . "\n\n"
            . __( 'This digest includes event summaries only. Review the Activity Log in WordPress for details.', 'activity-log-for-admins' );
        $site_name = sanitize_text_field( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
        $subject = sprintf( __( '[%s] Activity Log warning digest', 'activity-log-for-admins' ), $site_name );
        if ( wp_mail( $email, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] ) ) {
            update_option( 'alfa_alert_digest_after_id', (int) $rows[0]['id'] + 1, false );
            self::clear_failure( 'alert_error' );
        } else {
            self::record_failure( 'alert_error' );
            wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::ALERT_DIGEST );
        }
    }

    private static function unavailable(): \WP_Error {
        return new \WP_Error( 'alfa_storage', __( 'The activity log database is unavailable. Check Settings for diagnostics.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
    }
}
