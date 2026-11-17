<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class FileMonitor {
    public const HOOK = 'alfa_file_scan';
    private const CONTINUE_DELAY = MINUTE_IN_SECONDS;
    private const BATCH_SIZE = 100;
    private const BATCH_SECONDS = 4;
    private static bool $running = false;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'scan' ] );
        add_action( 'update_option_' . Store::SETTINGS, [ self::class, 'settings_changed' ], 20, 2 );
        add_action( 'plugins_loaded', [ self::class, 'ensure_schedule' ], 20 );
    }

    public static function ensure_schedule(): void {
        if ( ! Store::settings()['capture_files'] ) {
            wp_clear_scheduled_hook( self::HOOK );
            return;
        }

        if ( wp_next_scheduled( self::HOOK ) ) {
            return;
        }

        $interval = 'weekly' === Store::settings()['file_scan_interval'] ? 'weekly' : 'daily';
        $scheduled = wp_schedule_event( time() + self::CONTINUE_DELAY, $interval, self::HOOK, [], true );
        if ( false === $scheduled || is_wp_error( $scheduled ) ) {
            self::record_failure( 'file_schedule_error' );
        } else {
            self::clear_failure( 'file_schedule_error' );
        }
    }

    public static function settings_changed( array $old, array $new ): void {
        $before = Store::normalize_settings( $old );
        $after = Store::normalize_settings( $new );
        if ( ! $after['capture_files'] ) {
            wp_clear_scheduled_hook( self::HOOK );
            self::clear_baseline();
            return;
        }

        $baseline_changed = ! $before['capture_files']
            || $before['monitor_uploads'] !== $after['monitor_uploads']
            || $before['file_max_size_mb'] !== $after['file_max_size_mb']
            || $before['file_excluded_paths'] !== $after['file_excluded_paths'];
        if ( $baseline_changed ) {
            delete_option( Store::FILE_SCAN_STATE );
            self::clear_baseline();
            wp_clear_scheduled_hook( self::HOOK );
        }
        if ( $before['file_scan_interval'] !== $after['file_scan_interval'] ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
        self::ensure_schedule();
    }

    public static function next_scan(): ?int {
        $timestamp = wp_next_scheduled( self::HOOK );
        return $timestamp ? (int) $timestamp : null;
    }

    public static function scan(): void {
        if ( self::$running || ! Store::settings()['capture_files'] ) {
            return;
        }

        self::$running = true;
        try {
            self::run_batch();
        } catch ( \Throwable $error ) {
            self::record_failure( 'file_scan_error' );
            Capture::file_event( 'file_scan_failed', 'Site files', __( 'File scan failed', 'activity-log-for-admins' ), [ 'reason' => 'The scan stopped unexpectedly.' ], 'warning' );
            self::continue_later();
        } finally {
            self::$running = false;
        }
    }

    private static function run_batch(): void {
        global $wpdb;
        $state = (array) get_option( Store::FILE_SCAN_STATE, [] );
        if ( empty( $state['generation'] ) || ! isset( $state['phase'] ) ) {
            $table = Store::file_index_table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Determine whether this is a first baseline on the plugin owned file index.
            $existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i LIMIT 1', $table ) );
            if ( $wpdb->last_error ) {
                self::record_failure( 'file_scan_error' );
                Capture::file_event( 'file_scan_failed', 'Site files', __( 'File scan failed', 'activity-log-for-admins' ), [ 'reason' => 'The file index is unavailable.' ], 'warning' );
                return;
            }
            $health = (array) get_option( Store::HEALTH, [] );
            $roots = self::roots();
            $state = [
                'generation' => (int) ( $health['file_generation'] ?? 0 ) + 1,
                'phase' => 'scan',
                'stack' => array_map( static fn( array $root ): array => [ 'key' => $root['key'], 'relative' => $root['key'], 'after' => '' ], array_reverse( $roots ) ),
                'initial' => null === $existing,
                'unreadable' => [],
                'scanned' => 0,
                'size_skipped' => 0,
                'errors' => 0,
                'started_at' => time(),
            ];
            update_option( Store::FILE_SCAN_STATE, $state, false );
            Capture::file_event( 'file_scan_started', 'Site files', __( 'Started a file scan', 'activity-log-for-admins' ), [ 'baseline' => $state['initial'] ] );
            $health['file_scan_started'] = time();
            update_option( Store::HEALTH, $health, false );
        }

        $deadline = microtime( true ) + self::BATCH_SECONDS;
        $processed = 0;
        if ( 'scan' === $state['phase'] ) {
            $roots_by_key = self::roots_by_key();
            while ( ! empty( $state['stack'] ) && $processed < self::BATCH_SIZE && microtime( true ) < $deadline ) {
                $stack_index = count( $state['stack'] ) - 1;
                $directory = $state['stack'][ $stack_index ];
                $root = $roots_by_key[ $directory['key'] ] ?? null;
                if ( ! $root ) {
                    array_pop( $state['stack'] );
                    continue;
                }
                $tail = '' === $directory['key'] ? $directory['relative'] : ltrim( substr( $directory['relative'], strlen( $directory['key'] ) ), '/' );
                $absolute = $root['path'] . ( '' === $tail ? '' : DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $tail ) );
                if ( is_link( $absolute ) || ! is_dir( $absolute ) ) {
                    array_pop( $state['stack'] );
                    continue;
                }
                $real_directory = realpath( $absolute );
                if ( false === $real_directory || ! self::inside_root( $real_directory, $root['path'] ) ) {
                    array_pop( $state['stack'] );
                    continue;
                }
                $entries = @scandir( $real_directory, SCANDIR_SORT_ASCENDING );
                if ( false === $entries ) {
                    $state['unreadable'][] = $directory['relative'];
                    ++$state['errors'];
                    array_pop( $state['stack'] );
                    continue;
                }

                $found_child = false;
                foreach ( $entries as $entry ) {
                    if ( '.' === $entry || '..' === $entry || $entry <= $directory['after'] ) {
                        continue;
                    }
                    $state['stack'][ $stack_index ]['after'] = $entry;
                    $relative = '' === $directory['relative'] ? $entry : $directory['relative'] . '/' . $entry;
                    ++$processed;
                    if ( self::excluded( $relative ) || strlen( $relative ) > 500 || ! wp_check_invalid_utf8( $relative, true ) ) {
                        if ( $processed >= self::BATCH_SIZE || microtime( true ) >= $deadline ) {
                            break;
                        }
                        continue;
                    }
                    $candidate = $real_directory . DIRECTORY_SEPARATOR . $entry;
                    if ( is_link( $candidate ) ) {
                        continue;
                    }
                    if ( is_dir( $candidate ) ) {
                        $state['stack'][] = [ 'key' => $directory['key'], 'relative' => $relative, 'after' => '' ];
                        $found_child = true;
                        break;
                    }
                    if ( is_file( $candidate ) ) {
                        if ( self::track_file( $candidate, $relative, (int) $state['generation'], (bool) $state['initial'], $state ) ) {
                            ++$state['scanned'];
                        }
                    }
                    if ( $processed >= self::BATCH_SIZE || microtime( true ) >= $deadline ) {
                        break;
                    }
                }
                if ( $found_child ) {
                    continue;
                }
                $last_name = (string) $state['stack'][ $stack_index ]['after'];
                $has_more = false;
                foreach ( $entries as $entry ) {
                    if ( '.' !== $entry && '..' !== $entry && $entry > $last_name ) {
                        $has_more = true;
                        break;
                    }
                }
                if ( ! $has_more ) {
                    array_pop( $state['stack'] );
                }
            }
            if ( empty( $state['stack'] ) ) {
                $state['phase'] = 'reconcile';
            }
        }

        if ( 'reconcile' === $state['phase'] && $processed < self::BATCH_SIZE && microtime( true ) < $deadline ) {
            self::reconcile( $state, self::BATCH_SIZE - $processed, $deadline );
            if ( 'complete' === $state['phase'] ) {
                self::finish( $state );
                return;
            }
        }

        update_option( Store::FILE_SCAN_STATE, $state, false );
        self::continue_later();
    }

    private static function reconcile( array &$state, int $limit, float $deadline ): void {
        global $wpdb;
        $table = Store::file_index_table();
        $generation = (int) $state['generation'];
        $processed = 0;
        while ( $processed < $limit && microtime( true ) < $deadline ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read a bounded set of stale baseline entries for deletion reconciliation.
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, path_hash, file_path FROM %i WHERE generation <> %d ORDER BY id LIMIT %d', $table, $generation, min( 50, $limit - $processed ) ), ARRAY_A );
            if ( $wpdb->last_error || ! is_array( $rows ) ) {
                ++$state['errors'];
                self::record_failure( 'file_scan_error' );
                return;
            }
            if ( ! $rows ) {
                $state['phase'] = 'complete';
                return;
            }
            foreach ( $rows as $row ) {
                $path = (string) $row['file_path'];
                if ( self::under_unreadable_path( $path, (array) $state['unreadable'] ) ) {
                    self::mark_generation( (int) $row['id'], $generation );
                    ++$processed;
                    continue;
                }
                $absolute = self::absolute_path( $path );
                if ( null === $absolute ) {
                    self::mark_generation( (int) $row['id'], $generation );
                    ++$processed;
                    continue;
                }
                if ( is_file( $absolute ) && ! is_link( $absolute ) ) {
                    if ( ! self::track_file( $absolute, $path, $generation, false, $state ) ) {
                        self::mark_generation( (int) $row['id'], $generation );
                    }
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove one confirmed missing or replaced baseline entry.
                    if ( false === $wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] ) ) {
                        ++$state['errors'];
                        self::record_failure( 'file_scan_error' );
                        return;
                    }
                    if ( ! $state['initial'] ) {
                        Capture::file_event( 'file_deleted', $path, sprintf( __( 'Deleted file %s', 'activity-log-for-admins' ), $path ), [], 'warning' );
                    }
                }
                ++$processed;
            }
        }
    }

    private static function track_file( string $absolute, string $relative, int $generation, bool $initial, array &$state ): bool {
        global $wpdb;
        if ( strlen( $relative ) > 500 || ! wp_check_invalid_utf8( $relative, true ) ) {
            return false;
        }
        $stat = @stat( $absolute );
        if ( false === $stat || ! isset( $stat['size'], $stat['mtime'], $stat['mode'] ) ) {
            ++$state['errors'];
            return false;
        }
        $size = (int) $stat['size'];
        $mtime = (int) $stat['mtime'];
        $mode = (int) $stat['mode'] & 0777;
        $threshold = Store::settings()['file_max_size_mb'] * MB_IN_BYTES;
        $hash_kind = 'content';
        if ( $size > $threshold ) {
            $hash_kind = 'metadata';
            ++$state['size_skipped'];
            $fingerprint = hash( 'sha256', $size . ':' . $mtime . ':' . $mode );
        } else {
            $fingerprint = @hash_file( 'sha256', $absolute );
            if ( false === $fingerprint ) {
                ++$state['errors'];
                $hash_kind = 'metadata';
                $fingerprint = hash( 'sha256', $size . ':' . $mtime . ':' . $mode );
            }
        }
        $path_hash = hash( 'sha256', $relative );
        $table = Store::file_index_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Look up one baseline entry by its non reversible path hash.
        $before = $wpdb->get_row( $wpdb->prepare( 'SELECT id, fingerprint, hash_kind, file_size, file_mtime, file_mode FROM %i WHERE path_hash = %s', $table, $path_hash ), ARRAY_A );
        if ( $wpdb->last_error ) {
            ++$state['errors'];
            self::record_failure( 'file_scan_error' );
            return false;
        }
        $was_known = is_array( $before );
        if ( $was_known && 'metadata' === $hash_kind && 'content' === $before['hash_kind'] && $size <= $threshold ) {
            $fingerprint = (string) $before['fingerprint'];
            $hash_kind = 'content';
        }
        $data = [
            'path_hash' => $path_hash,
            'file_path' => $relative,
            'fingerprint' => $fingerprint,
            'hash_kind' => $hash_kind,
            'file_size' => $size,
            'file_mtime' => $mtime,
            'file_mode' => $mode,
            'generation' => $generation,
        ];
        if ( $was_known ) {
            $update_data = array_diff_key( $data, [ 'path_hash' => true ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Update the existing plugin owned file baseline without replacing its row.
            $write_result = $wpdb->update( $table, $update_data, [ 'path_hash' => $path_hash ], [ '%s', '%s', '%s', '%d', '%d', '%d', '%d' ], [ '%s' ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert one new plugin owned file baseline entry.
            $write_result = $wpdb->insert( $table, $data, [ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ] );
        }
        if ( false === $write_result ) {
            ++$state['errors'];
            self::record_failure( 'file_scan_error' );
            return false;
        }
        if ( $initial ) {
            return true;
        }
        $path = $relative;
        $size_change = $was_known && (int) $before['file_size'] !== $size;
        $mtime_change = $was_known && (int) $before['file_mtime'] !== $mtime;
        $mode_change = $was_known && (int) $before['file_mode'] !== $mode;
        $fingerprint_change = $was_known && (string) $before['fingerprint'] !== $fingerprint;
        if ( ! $was_known ) {
            Capture::file_event( 'file_created', $path, sprintf( __( 'Created file %s', 'activity-log-for-admins' ), $path ), [ 'size' => $size, 'permissions' => self::mode_label( $mode ) ], 'notice' );
        } elseif ( $fingerprint_change || $size_change || $mtime_change ) {
            Capture::file_event( 'file_modified', $path, sprintf( __( 'Changed file %s', 'activity-log-for-admins' ), $path ), [
                'changes' => [
                    'Size' => [ (int) $before['file_size'], $size ],
                    'Modified' => [ (int) $before['file_mtime'], $mtime ],
                    'Permissions' => [ self::mode_label( (int) $before['file_mode'] ), self::mode_label( $mode ) ],
                    'Content checksum changed' => [ 'No', $fingerprint_change ? 'Yes' : 'No' ],
                ],
                'checksum_type' => $hash_kind,
            ], 'notice' );
        }
        if ( $mode_change ) {
            Capture::file_event( 'file_permissions_changed', $path, sprintf( __( 'Changed permissions for %s', 'activity-log-for-admins' ), $path ), [
                'changes' => [ 'Permissions' => [ self::mode_label( (int) $before['file_mode'] ), self::mode_label( $mode ) ] ],
            ], 'warning' );
        }
        return true;
    }

    private static function finish( array $state ): void {
        $health = (array) get_option( Store::HEALTH, [] );
        $health['file_generation'] = (int) $state['generation'];
        $health['file_scan_last'] = time();
        if ( $state['errors'] ) {
            $health['file_scan_error'] = time();
            Capture::file_event( 'file_scan_failed', 'Site files', __( 'File scan completed with skipped locations', 'activity-log-for-admins' ), [ 'skipped_locations' => (int) $state['errors'] ], 'warning' );
        } else {
            unset( $health['file_scan_error'] );
        }
        update_option( Store::HEALTH, $health, false );
        if ( $state['size_skipped'] ) {
            Capture::file_event( 'file_scan_size_skipped', 'Site files', sprintf( __( 'Content checksums skipped for %d large files', 'activity-log-for-admins' ), (int) $state['size_skipped'] ), [ 'count' => (int) $state['size_skipped'], 'maximum_mb' => Store::settings()['file_max_size_mb'] ] );
        }
        Capture::file_event( 'file_scan_completed', 'Site files', __( 'Completed a file scan', 'activity-log-for-admins' ), [
            'files_scanned' => (int) $state['scanned'],
            'large_files' => (int) $state['size_skipped'],
            'skipped_locations' => (int) $state['errors'],
            'baseline' => (bool) $state['initial'],
        ] );
        delete_option( Store::FILE_SCAN_STATE );
    }

    private static function roots(): array {
        $roots = [];
        $site_root = realpath( ABSPATH );
        if ( false !== $site_root ) {
            $roots[] = [ 'key' => '', 'path' => untrailingslashit( $site_root ) ];
        }
        $content_root = realpath( WP_CONTENT_DIR );
        if ( false !== $content_root && ( ! self::inside_root( $content_root, $site_root ?: '' ) || is_link( WP_CONTENT_DIR ) ) ) {
            $roots[] = [ 'key' => 'wp-content', 'path' => untrailingslashit( $content_root ) ];
        }
        $settings = Store::settings();
        if ( $settings['monitor_uploads'] && function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir( null, false );
            $upload_root = empty( $uploads['error'] ) && isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
            $inside_known_root = false;
            foreach ( $roots as $root ) {
                if ( false !== $upload_root && self::inside_root( $upload_root, $root['path'] ) ) {
                    $inside_known_root = true;
                    break;
                }
            }
            if ( false !== $upload_root && ( ! $inside_known_root || is_link( $uploads['basedir'] ) ) ) {
                $roots[] = [ 'key' => 'wp-content/uploads', 'path' => untrailingslashit( $upload_root ) ];
            }
        }
        return $roots;
    }

    private static function roots_by_key(): array {
        $roots = [];
        foreach ( self::roots() as $root ) {
            $roots[ $root['key'] ] = $root;
        }
        return $roots;
    }

    private static function inside_root( string $path, string $root ): bool {
        if ( '' === $root ) {
            return false;
        }
        $root = untrailingslashit( $root );
        $path = untrailingslashit( $path );
        return $path === $root || str_starts_with( $path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR );
    }

    private static function excluded( string $relative ): bool {
        $path = trim( str_replace( '\\', '/', $relative ), '/' );
        $defaults = [ '.git', '.svn', '.hg', 'node_modules', 'vendor', 'cache', 'caches', 'upgrade', 'backups', 'backup', 'logs', 'wflogs', 'wp-content/ai1wm-backups' ];
        foreach ( [ ...$defaults, ...Store::settings()['file_excluded_paths'] ] as $prefix ) {
            if ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) ) {
                return true;
            }
        }
        if ( ! Store::settings()['monitor_uploads'] && ( 'wp-content/uploads' === $path || str_starts_with( $path, 'wp-content/uploads/' ) ) ) {
            return true;
        }
        return false;
    }

    private static function absolute_path( string $relative ): ?string {
        if ( str_contains( $relative, "\0" ) || in_array( '..', explode( '/', $relative ), true ) || in_array( '.', explode( '/', $relative ), true ) ) {
            return null;
        }
        $roots = self::roots();
        usort( $roots, static fn( array $left, array $right ): int => strlen( $right['key'] ) <=> strlen( $left['key'] ) );
        foreach ( $roots as $root ) {
            if ( '' !== $root['key'] && $relative !== $root['key'] && ! str_starts_with( $relative, $root['key'] . '/' ) ) {
                continue;
            }
            $tail = '' === $root['key'] ? $relative : ltrim( substr( $relative, strlen( $root['key'] ) ), '/' );
            $candidate = $root['path'] . ( '' === $tail ? '' : DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $tail ) );
            if ( is_link( $candidate ) ) {
                return $candidate;
            }
            $parent = realpath( dirname( $candidate ) );
            if ( false === $parent && ! file_exists( dirname( $candidate ) ) ) {
                return $candidate;
            }
            if ( false === $parent || ! self::inside_root( $parent, $root['path'] ) ) {
                return null;
            }
            return $candidate;
        }
        return null;
    }

    private static function under_unreadable_path( string $path, array $unreadable ): bool {
        foreach ( $unreadable as $prefix ) {
            if ( $path === $prefix || str_starts_with( $path, rtrim( $prefix, '/' ) . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    private static function mark_generation( int $id, int $generation ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Preserve a baseline under an inaccessible path without generating a false deletion.
        if ( false === $wpdb->update( Store::file_index_table(), [ 'generation' => $generation ], [ 'id' => $id ], [ '%d' ], [ '%d' ] ) ) {
            self::record_failure( 'file_scan_error' );
        }
    }

    private static function clear_baseline(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The admin disabled monitoring or changed its scan scope, so discard the previous local baseline.
        if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Store::file_index_table() ) ) ) {
            self::record_failure( 'file_scan_error' );
        }
        delete_option( Store::FILE_SCAN_STATE );
    }

    private static function continue_later(): void {
        if ( wp_next_scheduled( self::HOOK ) ) {
            return;
        }
        $result = wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::HOOK, [], true );
        if ( false === $result || ( is_wp_error( $result ) && 'duplicate_event' !== $result->get_error_code() ) ) {
            self::record_failure( 'file_schedule_error' );
        }
    }

    private static function mode_label( int $mode ): string {
        return sprintf( '%04o', $mode & 0777 );
    }

    private static function record_failure( string $key ): void {
        $health = (array) get_option( Store::HEALTH, [] );
        $health[ $key ] = time();
        update_option( Store::HEALTH, $health, false );
    }

    private static function clear_failure( string $key ): void {
        $health = (array) get_option( Store::HEALTH, [] );
        if ( isset( $health[ $key ] ) ) {
            unset( $health[ $key ] );
            update_option( Store::HEALTH, $health, false );
        }
    }
}
