<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Store {
    public const SETTINGS = 'alfa_settings';
    public const HEALTH = 'alfa_health';
    public const SCHEMA = 'alfa_schema';
    public const CLEANUP = 'alfa_cleanup';
    public const CONTINUE_CLEANUP = 'alfa_continue_cleanup';
    public const PAGE_SIZE = 50;
    private const SCHEMA_VERSION = 1;
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
            KEY item (item_kind,item_key,id),
            KEY action_key (action_key,id),
            KEY severity (severity,id)
        ) $collation;" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the custom table exists after dbDelta runs.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
            return false;
        }
        update_option( self::SCHEMA, self::SCHEMA_VERSION, true );
        return true;
    }

    public static function upgrade(): void {
        if ( (int) get_option( self::SCHEMA ) !== self::SCHEMA_VERSION && ! self::install() ) {
            self::record_failure( 'write_error' );
        }
    }

    public static function settings(): array {
        $saved = (array) get_option( self::SETTINGS, [] );
        return [
            'retention' => min( 3650, max( 1, (int) ( $saved['retention'] ?? 90 ) ) ),
            'erase'     => ! empty( $saved['erase'] ),
        ];
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CLEANUP ) ) {
            $result = wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP, [], true );
            if ( is_wp_error( $result ) ) {
                self::record_failure( 'cleanup_error' );
            }
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
            if ( is_wp_error( $scheduled ) && 'duplicate_event' !== $scheduled->get_error_code() ) {
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
            }
        } catch ( \Throwable $error ) {
            self::record_failure( 'write_error' );
        } finally {
            $wpdb->suppress_errors( $previous );
        }
    }

    public static function query( array $filters, int $limit, int $before = 0, int $ceiling = 0 ): array|\WP_Error {
        global $wpdb;
        $conditions = [ '1=1' ];
        $params = [ self::table() ];
        foreach ( [ 'action' => 'action_key', 'severity' => 'severity' ] as $key => $column ) {
            if ( ! empty( $filters[ $key ] ) ) {
                $conditions[] = "$column = %s";
                $params[] = $filters[ $key ];
            }
        }
        if ( ! empty( $filters['actor'] ) ) {
            $actor = $filters['actor'];
            if ( ctype_digit( $actor ) ) {
                $conditions[] = "actor_kind = 'user' AND actor_id = %d";
                $params[] = (int) $actor;
            } elseif ( in_array( $actor, [ 'anonymous', 'system', 'unknown' ], true ) ) {
                $conditions[] = 'actor_kind = %s';
                $params[] = $actor;
            } else {
                $conditions[] = 'actor_name LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $actor ) . '%';
            }
        }
        if ( ! empty( $filters['item'] ) ) {
            $item = $filters['item'];
            if ( str_contains( $item, ':' ) && preg_match( '/^(post|page|user|plugin|theme|setting|login):(.+)$/u', $item, $parts ) ) {
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
        $query = "SELECT id, occurred_at, actor_kind, actor_id, actor_name, item_kind, item_key, item_name, action_key, severity, summary
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
        $query = 'SELECT COUNT(*) AS week_count,
                COALESCE(SUM(occurred_at >= %s), 0) AS day_count,
                COALESCE(SUM(severity = %s), 0) AS warning_count
            FROM %i WHERE occurred_at >= %s';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Summarize the indexed custom event table for the administrator dashboard.
        $counts = $wpdb->get_row( $wpdb->prepare( $query, $since_day, 'warning', $table, $since_week ), ARRAY_A );
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
            'cleanup_error' => $health['cleanup_error'] ?? null,
            'next_cleanup'  => wp_next_scheduled( self::CLEANUP ) ?: null,
        ];
    }

    private static function unavailable(): \WP_Error {
        return new \WP_Error( 'alfa_storage', __( 'The activity log database is unavailable. Check Settings for diagnostics.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
    }
}
