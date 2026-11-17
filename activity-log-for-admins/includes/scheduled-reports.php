<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class ScheduledReports {
    public const TICK = 'alfa_scheduled_reports_tick';
    public const CONTINUE = 'alfa_scheduled_report_continue';
    public const SCHEDULES = 'alfa_report_schedules';
    public const JOB = 'alfa_report_job';
    private const CANCELLED_JOB = 'alfa_report_job_cancelled';
    private const REPORT_LOCK_PREFIX = 'alfa_report_';
    public const HISTORY = 'alfa_report_history';
    private const MAX_SCHEDULES = 20;
    private const MAX_RECIPIENTS = 10;
    private const BATCH_SIZE = 250;
    private const BATCHES_PER_RUN = 4;
    private const MAX_REPORT_BYTES = 10485760;
    private const HISTORY_LIMIT = 100;
    private const ARCHIVE_LIMIT = 10;
    private const MAX_DELIVERY_ATTEMPTS = 3;
    private const RETRY_DELAYS = [ 300, 900 ];

    public static function cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['alfa_quarter_hour'] ) ) {
            $schedules['alfa_quarter_hour'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every fifteen minutes', 'activity-log-for-admins' ) ];
        }
        return $schedules;
    }

    public static function ensure_schedule(): void {
        if ( wp_next_scheduled( self::TICK ) && 'alfa_quarter_hour' === wp_get_schedule( self::TICK ) ) {
            self::schedule_health( false );
            return;
        }
        wp_clear_scheduled_hook( self::TICK );
        $scheduled = wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'alfa_quarter_hour', self::TICK, [], true );
        self::schedule_health( false === $scheduled || is_wp_error( $scheduled ) );
    }

    public static function deactivate_site(): void {
        wp_clear_scheduled_hook( self::TICK );
        wp_clear_scheduled_hook( self::CONTINUE );
        $job = get_option( self::JOB, [] );
        if ( is_array( $job ) && ! empty( $job['schedule_id'] ) ) {
            $schedule = self::find_schedule( (string) $job['schedule_id'] );
            if ( $schedule ) {
                self::record_result( $schedule, 'failed', (int) ( $job['rows'] ?? 0 ), __( 'The report stopped because the plugin was deactivated.', 'activity-log-for-admins' ) );
            }
        }
        self::remove_job_file( $job );
        delete_option( self::JOB );
        delete_option( self::CANCELLED_JOB );
        self::cleanup_orphan_files();
    }

    public static function erase_history_files(): bool {
        return '' === self::erase_report_copies()['error'];
    }

    public static function list_schedules(): array {
        return [
            'schedules' => self::schedules(),
            'history' => self::history_for_display(),
        ];
    }

    private static function schedules(): array {
        $stored = get_option( self::SCHEDULES, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $schedules = [];
        foreach ( array_slice( $stored, 0, self::MAX_SCHEDULES ) as $schedule ) {
            if ( ! is_array( $schedule ) || ! is_string( $schedule['id'] ?? null )
                || ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $schedule['id'] ) ) {
                continue;
            }
            $recipients = [];
            foreach ( array_slice( (array) ( $schedule['recipients'] ?? [] ), 0, self::MAX_RECIPIENTS ) as $recipient ) {
                if ( is_string( $recipient ) && is_email( $recipient ) ) {
                    $recipients[] = sanitize_email( $recipient );
                }
            }
            $cadence = sanitize_key( (string) ( $schedule['cadence'] ?? '' ) );
            if ( ! in_array( $cadence, [ 'daily', 'weekly', 'monthly', 'quarterly' ], true ) || ! $recipients ) {
                continue;
            }
            $columns = [];
            foreach ( (array) ( $schedule['columns'] ?? Store::REPORT_COLUMNS ) as $column ) {
                if ( is_string( $column ) && in_array( $column, Store::REPORT_COLUMNS, true ) ) {
                    $columns[] = $column;
                }
            }
            $schedules[] = [
                'id' => strtolower( $schedule['id'] ),
                'name' => wp_html_excerpt( sanitize_text_field( (string) ( $schedule['name'] ?? '' ) ), 100, '' ),
                'cadence' => $cadence,
                'format' => in_array( $schedule['format'] ?? 'csv', [ 'csv', 'html' ], true ) ? ( $schedule['format'] ?? 'csv' ) : 'csv',
                'hour' => min( 23, max( 0, (int) ( $schedule['hour'] ?? 9 ) ) ),
                'recipients' => array_values( array_unique( $recipients ) ),
                'filters' => self::clean_filters( (array) ( $schedule['filters'] ?? [] ) ),
                'range_days' => min( Store::settings()['retention'], max( 1, (int) ( $schedule['range_days'] ?? 30 ) ) ),
                'columns' => $columns ?: Store::REPORT_COLUMNS,
                'branding' => ! empty( $schedule['branding'] ),
                'next_run' => max( 0, (int) ( $schedule['next_run'] ?? 0 ) ),
                'last_run' => max( 0, (int) ( $schedule['last_run'] ?? 0 ) ),
                'last_status' => in_array( $schedule['last_status'] ?? '', [ 'pending', 'sent', 'failed' ], true ) ? $schedule['last_status'] : '',
                'last_error' => sanitize_text_field( (string) ( $schedule['last_error'] ?? '' ) ),
            ];
        }
        return $schedules;
    }

    private static function clean_filters( array $filters ): array {
        $allowed = [ 'q', 'actor', 'actor_id', 'actor_kind', 'actor_role', 'source', 'item', 'action', 'category', 'severity', 'site_id' ];
        $clean = [];
        foreach ( $filters as $key => $value ) {
            if ( in_array( $key, $allowed, true ) && is_string( $value ) && '' !== $value ) {
                $clean[ $key ] = sanitize_text_field( $value );
            }
        }
        return $clean;
    }

    private static function history(): array {
        $history = get_option( self::HISTORY, [] );
        if ( ! is_array( $history ) ) {
            return [];
        }
        return array_values( array_filter( array_slice( $history, 0, self::HISTORY_LIMIT ), static fn( $item ): bool => is_array( $item ) ) );
    }

    private static function history_for_display(): array {
        $history = self::history();
        foreach ( $history as &$entry ) {
            $id = strtolower( (string) ( $entry['id'] ?? '' ) );
            $path = self::valid_archive_file( (string) ( $entry['file'] ?? '' ), (string) ( $entry['format'] ?? '' ) );
            unset( $entry['file'], $entry['schedule_id'] );
            if ( false === $path || ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
                continue;
            }
            $url = add_query_arg( [ 'action' => 'alfa_report_download', 'id' => $id ], admin_url( 'admin-post.php' ) );
            $entry['download_url'] = add_query_arg( '_wpnonce', wp_create_nonce( 'alfa_report_download_' . $id ), $url );
        }
        unset( $entry );
        return $history;
    }

    public static function delete_history( string $id ): bool|\WP_Error {
        $id = strtolower( $id );
        $history = self::history();
        $entry = null;
        foreach ( $history as $candidate ) {
            if ( is_string( $candidate['id'] ?? null ) && hash_equals( strtolower( $candidate['id'] ), $id ) ) {
                $entry = $candidate;
                break;
            }
        }
        if ( ! $entry ) {
            return new \WP_Error( 'alfa_report_history_missing', __( 'The report history item was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $remaining = array_values( array_filter( $history, static fn( array $item ): bool => ! hash_equals( strtolower( (string) ( $item['id'] ?? '' ) ), $id ) ) );
        if ( ! update_option( self::HISTORY, $remaining, false ) && $remaining !== get_option( self::HISTORY, [] ) ) {
            return new \WP_Error( 'alfa_report_history_storage', __( 'The report history could not be deleted.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        self::delete_archive_file( (string) ( $entry['file'] ?? '' ) );
        return true;
    }

    public static function download_report(): void {
        $id = strtolower( sanitize_text_field( wp_unslash( (string) ( $_GET['id'] ?? '' ) ) ) );
        if ( ! Admin::can_manage() || ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) ( $_GET['_wpnonce'] ?? '' ) ) ), 'alfa_report_download_' . $id ) ) {
            wp_die( esc_html__( 'You are not allowed to download this report.', 'activity-log-for-admins' ), '', [ 'response' => 403 ] );
        }
        foreach ( self::history() as $entry ) {
            if ( ! is_string( $entry['id'] ?? null ) || ! hash_equals( strtolower( $entry['id'] ), $id ) ) {
                continue;
            }
            $format = in_array( $entry['format'] ?? '', [ 'csv', 'html' ], true ) ? $entry['format'] : '';
            $path = self::valid_archive_file( (string) ( $entry['file'] ?? '' ), $format );
            $filesystem = self::filesystem();
            $contents = false === $path || is_wp_error( $filesystem ) ? false : $filesystem->get_contents( $path );
            if ( false === $path || ! is_string( $contents ) ) {
                break;
            }
            $extension = 'html' === $format ? 'html' : 'csv';
            $filename = sanitize_file_name( 'activity-report-' . gmdate( 'Y-m-d-His', max( 0, (int) ( $entry['occurred_at'] ?? time() ) ) ) . '.' . $extension );
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            nocache_headers();
            header( 'Content-Type: ' . ( 'html' === $format ? 'text/html' : 'text/csv' ) . '; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Length: ' . (string) strlen( $contents ) );
            header( 'X-Content-Type-Options: nosniff' );
            if ( 'html' === $format ) {
                header( "Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'" );
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This plugin creates the report, escapes HTML cells, and protects CSV values before writing the file.
            echo $contents;
            exit;
        }
        wp_die( esc_html__( 'The report copy is no longer available.', 'activity-log-for-admins' ), '', [ 'response' => 404 ] );
    }

    public static function create( string $template_id, string $cadence, int $hour, array $recipients, string $format = 'csv' ): array|\WP_Error {
        $templates = Store::saved_reports();
        $template = null;
        foreach ( $templates as $candidate ) {
            if ( hash_equals( $candidate['id'], strtolower( $template_id ) ) ) {
                $template = $candidate;
                break;
            }
        }
        if ( ! $template ) {
            return new \WP_Error( 'alfa_schedule_template_missing', __( 'Choose a saved report before scheduling delivery.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        if ( ! in_array( $cadence, [ 'daily', 'weekly', 'monthly', 'quarterly' ], true ) || ! in_array( $format, [ 'csv', 'html' ], true ) || $hour < 0 || $hour > 23
            || ! $recipients || count( $recipients ) > self::MAX_RECIPIENTS ) {
            return new \WP_Error( 'alfa_schedule_invalid', __( 'Check the delivery frequency, time, and recipient list.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $clean_recipients = [];
        foreach ( $recipients as $recipient ) {
            if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) {
                return new \WP_Error( 'alfa_schedule_invalid_email', __( 'Enter valid email addresses for every recipient.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $email = sanitize_email( $recipient );
            if ( ! in_array( strtolower( $email ), array_map( 'strtolower', $clean_recipients ), true ) ) {
                $clean_recipients[] = $email;
            }
        }
        if ( ! $clean_recipients ) {
            return new \WP_Error( 'alfa_schedule_invalid_email', __( 'Enter at least one valid recipient.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $schedules = self::schedules();
        if ( count( $schedules ) >= self::MAX_SCHEDULES ) {
            return new \WP_Error( 'alfa_schedule_limit', __( 'Delete a scheduled report before adding another. A site can keep up to 20 schedules.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        $schedule = [
            'id' => wp_generate_uuid4(),
            'name' => $template['name'],
            'cadence' => $cadence,
            'format' => $format,
            'hour' => $hour,
            'recipients' => $clean_recipients,
            'filters' => $template['filters'],
            'range_days' => min( Store::settings()['retention'], $template['range_days'] ),
            'columns' => $template['columns'],
            'branding' => $template['branding'],
            'next_run' => self::next_run( $cadence, $hour ),
            'last_run' => 0,
            'last_status' => '',
            'last_error' => '',
        ];
        $schedules[] = $schedule;
        if ( ! update_option( self::SCHEDULES, $schedules, false ) && $schedules !== get_option( self::SCHEDULES, [] ) ) {
            return new \WP_Error( 'alfa_schedule_storage', __( 'The scheduled report could not be saved.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        return $schedule;
    }

    public static function delete( string $id ): bool|\WP_Error {
        $schedules = self::schedules();
        $remaining = array_values( array_filter( $schedules, static fn( array $schedule ): bool => ! hash_equals( $schedule['id'], strtolower( $id ) ) ) );
        if ( count( $remaining ) === count( $schedules ) ) {
            return new \WP_Error( 'alfa_schedule_missing', __( 'The scheduled report was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $job = get_option( self::JOB, [] );
        if ( is_array( $job ) && hash_equals( (string) ( $job['schedule_id'] ?? '' ), strtolower( $id ) ) ) {
            self::remove_job_file( $job );
            self::clear_cancellation( $job );
            delete_option( self::JOB );
            wp_clear_scheduled_hook( self::CONTINUE );
        }
        if ( ! update_option( self::SCHEDULES, $remaining, false ) && $remaining !== get_option( self::SCHEDULES, [] ) ) {
            return new \WP_Error( 'alfa_schedule_storage', __( 'The scheduled report could not be deleted.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        return true;
    }

    /** Remove a person's saved report delivery data before an erasure request completes. */
    public static function erase_personal_data( string $email, ?\WP_User $user ): array {
        $email = strtolower( sanitize_email( $email ) );
        $identifiers = [ $email ];
        if ( $user instanceof \WP_User ) {
            $identifiers = array_merge( $identifiers, [ strtolower( $user->user_login ), strtolower( $user->display_name ) ] );
        }
        $identifiers = array_values( array_filter( array_unique( $identifiers ) ) );
        $archives = self::erase_report_copies();
        $removed = $archives['removed'];
        $errors = [];
        if ( '' !== $archives['error'] ) {
            $errors[] = $archives['error'];
        }
        $cancelled_job = false;
        $job = get_option( self::JOB, [] );
        if ( is_array( $job ) && ! empty( $job['schedule_id'] ) ) {
            // A report already being assembled may contain pre erasure activity.
            if ( ! self::request_job_cancellation( $job ) ) {
                $errors[] = __( 'An active scheduled report could not be stopped. Retry the privacy erasure.', 'activity-log-for-admins' );
            } else {
                $removed = true;
                $cancelled_job = true;
            }
        }

        $schedules = get_option( self::SCHEDULES, [] );
        if ( is_array( $schedules ) ) {
            $remaining = [];
            foreach ( $schedules as $schedule ) {
                if ( ! is_array( $schedule ) ) {
                    $remaining[] = $schedule;
                    continue;
                }
                $original = $schedule;
                $recipients = (array) ( $schedule['recipients'] ?? [] );
                $schedule['recipients'] = array_values( array_filter( $recipients, static fn( $recipient ): bool => ! is_string( $recipient ) || strtolower( $recipient ) !== $email ) );
                $filters = (array) ( $schedule['filters'] ?? [] );
                $matches_user = $user instanceof \WP_User && (string) ( $filters['actor_id'] ?? '' ) === (string) $user->ID;
                foreach ( $filters as $key => $value ) {
                    if ( is_string( $value ) && self::contains_identifier( $value, $identifiers ) ) {
                        unset( $filters[ $key ] );
                    }
                }
                if ( $matches_user ) {
                    unset( $filters['actor_id'], $filters['actor_kind'] );
                }
                if ( $filters !== (array) ( $schedule['filters'] ?? [] ) ) {
                    $schedule['filters'] = $filters;
                }
                if ( isset( $schedule['name'] ) && is_string( $schedule['name'] ) && self::contains_identifier( $schedule['name'], $identifiers ) ) {
                    $schedule['name'] = __( 'Scheduled report', 'activity-log-for-admins' );
                }
                if ( $original !== $schedule ) {
                    $removed = true;
                }
                if ( $schedule['recipients'] ) {
                    $remaining[] = $schedule;
                } else {
                    $removed = true;
                }
            }
            if ( $remaining !== $schedules ) {
                $saved = update_option( self::SCHEDULES, $remaining, false );
                if ( ! $saved && $remaining !== get_option( self::SCHEDULES, [] ) ) {
                    $errors[] = __( 'Scheduled report recipients or filters could not be updated. Retry the privacy erasure.', 'activity-log-for-admins' );
                }
            }
        }

        $job = get_option( self::JOB, [] );
        if ( is_array( $job ) && ! empty( $job['schedule_id'] ) ) {
            $job_changed = false;
            $recipients = (array) ( $job['recipients'] ?? [] );
            $matches_recipient = in_array( $email, array_map( 'strtolower', array_filter( $recipients, 'is_string' ) ), true );
            $job['recipients'] = array_values( array_filter( $recipients, static fn( $recipient ): bool => ! is_string( $recipient ) || strtolower( $recipient ) !== $email ) );
            $job_changed = $job['recipients'] !== $recipients;
            $filters = (array) ( $job['filters'] ?? [] );
            $actor_filter = strtolower( (string) ( $filters['actor'] ?? '' ) );
            $matches_user = $user instanceof \WP_User && (string) ( $filters['actor_id'] ?? '' ) === (string) $user->ID;
            foreach ( $filters as $key => $value ) {
                if ( is_string( $value ) && self::contains_identifier( $value, $identifiers ) ) {
                    unset( $filters[ $key ] );
                }
            }
            if ( $matches_user ) {
                unset( $filters['actor_id'], $filters['actor_kind'] );
            }
            if ( $filters !== (array) ( $job['filters'] ?? [] ) ) {
                $job['filters'] = $filters;
                $job_changed = true;
            }
            if ( isset( $job['name'] ) && is_string( $job['name'] ) && self::contains_identifier( $job['name'], $identifiers ) ) {
                $job['name'] = __( 'Scheduled report', 'activity-log-for-admins' );
                $job_changed = true;
            }
            $active_schedule = self::find_schedule( (string) $job['schedule_id'] );
            if ( ! $job['recipients'] || ! $active_schedule ) {
                $job_changed = true;
            }
            if ( $user instanceof \WP_User || $matches_recipient || $matches_user || in_array( $actor_filter, $identifiers, true ) || ! $job['recipients'] || ! $active_schedule ) {
                if ( ! self::request_job_cancellation( $job ) ) {
                    $errors[] = __( 'An active scheduled report could not be updated. Retry the privacy erasure.', 'activity-log-for-admins' );
                } else {
                    $removed = true;
                    $cancelled_job = true;
                }
                $job_changed = false;
            }
            if ( $job_changed ) {
                if ( ! self::save_job( $job ) ) {
                    $errors[] = __( 'An active scheduled report could not be updated. Retry the privacy erasure.', 'activity-log-for-admins' );
                } else {
                    $removed = true;
                }
            }
        }

        if ( $cancelled_job ) {
            self::tick();
        }
        $history = self::history();
        $history_changed = false;
        foreach ( $history as &$entry ) {
            foreach ( [ 'name', 'message' ] as $key ) {
                if ( isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && self::contains_identifier( $entry[ $key ], $identifiers ) ) {
                    $entry[ $key ] = __( 'Scheduled report', 'activity-log-for-admins' );
                    $history_changed = true;
                }
            }
        }
        unset( $entry );
        if ( $history_changed ) {
            $saved = update_option( self::HISTORY, $history, false );
            if ( ! $saved && $history !== get_option( self::HISTORY, [] ) ) {
                $errors[] = __( 'Scheduled report history could not be updated. Retry the privacy erasure.', 'activity-log-for-admins' );
            } else {
                $removed = true;
            }
        }

        return [ 'removed' => $removed, 'errors' => $errors, 'events_safe' => ! $errors ];
    }

    private static function next_run( string $cadence, int $hour, ?int $after = null ): int {
        $now = $after ?? time();
        $local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
        for ( $offset = 0; $offset <= 370; ++$offset ) {
            $date = $local->modify( '+' . $offset . ' days' );
            if ( 'weekly' === $cadence && 1 !== (int) $date->format( 'N' ) ) {
                continue;
            }
            if ( in_array( $cadence, [ 'monthly', 'quarterly' ], true ) && '01' !== $date->format( 'd' ) ) {
                continue;
            }
            if ( 'quarterly' === $cadence && ! in_array( (int) $date->format( 'n' ), [ 1, 4, 7, 10 ], true ) ) {
                continue;
            }
            $candidate = $date->setTime( $hour, 0, 0 );
            if ( $candidate->getTimestamp() > $now ) {
                return $candidate->getTimestamp();
            }
        }
        return $now + DAY_IN_SECONDS;
    }

    public static function tick(): void {
        self::prune_history();
        self::cleanup_orphan_files();
        $job = get_option( self::JOB, [] );
        if ( is_array( $job ) && ! empty( $job['schedule_id'] ) ) {
            self::advance_job();
            return;
        }
        foreach ( self::schedules() as $schedule ) {
            if ( $schedule['next_run'] > time() ) {
                continue;
            }
            self::start_job( $schedule );
            return;
        }
    }

    private static function start_job( array $schedule ): void {
        $directory = self::temp_directory();
        $ceiling = Store::latest_id();
        if ( is_wp_error( $directory ) || is_wp_error( $ceiling ) ) {
            self::record_result( $schedule, 'failed', 0, is_wp_error( $directory ) ? $directory->get_error_message() : __( 'Activity data is unavailable.', 'activity-log-for-admins' ) );
            return;
        }
        try {
            $format = in_array( $schedule['format'] ?? 'csv', [ 'csv', 'html' ], true ) ? $schedule['format'] : 'csv';
            $path = $directory . DIRECTORY_SEPARATOR . 'report-' . bin2hex( random_bytes( 16 ) ) . '.' . $format;
        } catch ( \Throwable $error ) {
            self::record_result( $schedule, 'failed', 0, __( 'A private temporary report file could not be created.', 'activity-log-for-admins' ) );
            return;
        }
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) || $filesystem->exists( $path ) ) {
            self::record_result( $schedule, 'failed', 0, __( 'A private temporary report file could not be created.', 'activity-log-for-admins' ) );
            return;
        }
        $today = new \DateTimeImmutable( 'today', wp_timezone() );
        $from = $today->modify( '-' . ( $schedule['range_days'] - 1 ) . ' days' )->format( 'Y-m-d' );
        $to = $today->format( 'Y-m-d' );
        $header_rows = [ [ 'Activity report', $schedule['name'] ] ];
        if ( $schedule['branding'] ) {
            $header_rows[] = [ 'Site', get_bloginfo( 'name' ) ];
        }
        $header_rows[] = [ 'Date range', $from, $to ];
        $header_rows[] = [ 'Generated', wp_date( 'j M Y, H:i:s T' ) ];
        $header_rows[] = array_map( [ self::class, 'column_label' ], $schedule['columns'] );
        if ( 'csv' === $format ) {
            $contents = "\xEF\xBB\xBF";
            foreach ( $header_rows as $header_row ) {
                $contents .= self::csv_row( $header_row );
            }
        } else {
            $contents = self::html_report_start( $schedule, $from, $to );
        }
        if ( strlen( $contents ) > self::MAX_REPORT_BYTES
            || ! $filesystem->put_contents( $path, $contents, 0600 )
            || ! self::set_private_permissions( $path, 0600 ) ) {
            $filesystem->delete( $path );
            self::record_result( $schedule, 'failed', 0, __( 'The report could not be written to private temporary storage.', 'activity-log-for-admins' ) );
            return;
        }

        $now = time();
        $job = [
            'schedule_id' => $schedule['id'],
            'name' => $schedule['name'],
            'format' => $format,
            'recipients' => $schedule['recipients'],
            'filters' => array_merge( $schedule['filters'], [ 'from' => $from, 'to' => $to ] ),
            'columns' => $schedule['columns'],
            'branding' => $schedule['branding'],
            'path' => $path,
            'cursor' => 0,
            'ceiling' => (int) $ceiling,
            'rows' => 0,
            'attempts' => 0,
            'started_at' => $now,
        ];
        if ( ! self::save_job( $job ) ) {
            self::remove_job_file( $job );
            self::record_result( $schedule, 'failed', 0, __( 'Scheduled report state could not be saved.', 'activity-log-for-admins' ) );
            return;
        }
        $stored = self::schedules();
        foreach ( $stored as &$current ) {
            if ( hash_equals( $current['id'], $schedule['id'] ) ) {
                $current['last_run'] = $now;
                $current['last_status'] = 'pending';
                $current['last_error'] = '';
                $current['next_run'] = self::next_run( $current['cadence'], $current['hour'], $now );
                break;
            }
        }
        unset( $current );
        update_option( self::SCHEDULES, $stored, false );
        self::schedule_continue();
    }

    private static function advance_job(): void {
        $job = get_option( self::JOB, [] );
        if ( ! is_array( $job ) || empty( $job['schedule_id'] ) ) {
            delete_option( self::JOB );
            return;
        }
        if ( ! self::valid_job_file( $job ) ) {
            $schedule = self::find_schedule( (string) $job['schedule_id'] );
            if ( $schedule ) {
                self::record_result( $schedule, 'failed', (int) ( $job['rows'] ?? 0 ), __( 'The private temporary report file failed its location check.', 'activity-log-for-admins' ) );
            }
            self::clear_cancellation( $job );
            delete_option( self::JOB );
            wp_clear_scheduled_hook( self::CONTINUE );
            return;
        }
        $lock_name = self::acquire_report_lock();
        if ( is_wp_error( $lock_name ) ) {
            self::fail_job( $job, $lock_name->get_error_message() );
            self::remove_job_file( $job );
            self::clear_cancellation( $job );
            delete_option( self::JOB );
            wp_clear_scheduled_hook( self::CONTINUE );
            return;
        }
        if ( false === $lock_name ) {
            return;
        }
        $finished = false;
        try {
            $latest_job = get_option( self::JOB, [] );
            if ( ! is_array( $latest_job )
                || ! hash_equals( (string) ( $job['schedule_id'] ?? '' ), (string) ( $latest_job['schedule_id'] ?? '' ) )
                || ! hash_equals( (string) ( $job['path'] ?? '' ), (string) ( $latest_job['path'] ?? '' ) ) ) {
                return;
            }
            $job = $latest_job;
            if ( self::cancellation_requested( $job ) ) {
                self::fail_job( $job, sanitize_text_field( (string) ( $job['cancel_message'] ?? __( 'The report was cancelled.', 'activity-log-for-admins' ) ) ) );
                $finished = true;
                return;
            }
            if ( ! empty( $job['ready_to_deliver'] ) ) {
                if ( (int) ( $job['retry_at'] ?? 0 ) > time() ) {
                    return;
                }
                $finished = self::process_delivery_attempt( $job );
                return;
            }
            $filesystem = self::filesystem();
            $contents = is_wp_error( $filesystem ) ? false : $filesystem->get_contents( $job['path'] );
            if ( ! is_string( $contents ) ) {
                self::fail_job( $job, __( 'The temporary report file is unavailable.', 'activity-log-for-admins' ) );
                $finished = true;
                return;
            }
            $append = '';
            $batch_count = 0;
            $done = false;
            while ( $batch_count < self::BATCHES_PER_RUN ) {
                if ( 0 === (int) $job['ceiling'] ) {
                    $done = true;
                    break;
                }
                $rows = Store::query( $job['filters'], self::BATCH_SIZE, (int) $job['cursor'], (int) $job['ceiling'], true );
                if ( is_wp_error( $rows ) ) {
                    self::fail_job( $job, __( 'Activity data became unavailable while the report was being prepared.', 'activity-log-for-admins' ) );
                    $finished = true;
                    return;
                }
                foreach ( $rows as $row ) {
                    $cells = [];
                    foreach ( $job['columns'] as $column ) {
                        $cells[] = self::event_value( $column, $row );
                    }
                    $append .= 'html' === ( $job['format'] ?? 'csv' )
                        ? self::html_row( $cells )
                        : self::csv_row( $cells );
                    ++$job['rows'];
                }
                if ( strlen( $contents ) + strlen( $append ) > self::MAX_REPORT_BYTES ) {
                    self::fail_job( $job, __( 'The report exceeds the 10 MB email attachment limit. Narrow the date range or choose fewer fields.', 'activity-log-for-admins' ) );
                    $finished = true;
                    return;
                }
                if ( ! $rows || count( $rows ) < self::BATCH_SIZE ) {
                    $done = true;
                    break;
                }
                $job['cursor'] = (int) end( $rows )['id'];
                ++$batch_count;
            }
            if ( self::cancellation_requested( $job ) ) {
                self::fail_job( $job, sanitize_text_field( (string) ( $job['cancel_message'] ?? __( 'The report was cancelled.', 'activity-log-for-admins' ) ) ) );
                $finished = true;
                return;
            }
            if ( $done && 'html' === ( $job['format'] ?? 'csv' ) ) {
                $append .= "</tbody></table></main></body></html>";
            }
            if ( strlen( $contents ) + strlen( $append ) > self::MAX_REPORT_BYTES ) {
                self::fail_job( $job, __( 'The report exceeds the 10 MB email attachment limit. Narrow the date range or choose fewer fields.', 'activity-log-for-admins' ) );
                $finished = true;
                return;
            }
            if ( ! $filesystem->put_contents( $job['path'], $contents . $append, 0600 )
                || ! self::set_private_permissions( $job['path'], 0600 ) ) {
                self::fail_job( $job, __( 'The report could not be written to private temporary storage.', 'activity-log-for-admins' ) );
                $finished = true;
                return;
            }
            ++$job['attempts'];
            if ( $done ) {
                $job['ready_to_deliver'] = true;
                $job['delivery_attempts'] = 0;
                $job['retry_at'] = 0;
            }
            if ( ! self::save_job( $job ) ) {
                self::fail_job( $job, __( 'Scheduled report progress could not be saved.', 'activity-log-for-admins' ) );
                $finished = true;
                return;
            }
            if ( $done ) {
                $finished = self::process_delivery_attempt( $job );
                return;
            }
            if ( $job['attempts'] >= 100 ) {
                self::fail_job( $job, __( 'The report exceeded its maximum number of background batches.', 'activity-log-for-admins' ) );
                $finished = true;
                return;
            }
            self::schedule_continue();
        } finally {
            self::release_report_lock( $lock_name );
            if ( $finished ) {
                self::remove_job_file( $job );
                delete_option( self::JOB );
                self::clear_cancellation( $job );
                wp_clear_scheduled_hook( self::CONTINUE );
            }
        }
    }

    private static function process_delivery_attempt( array &$job ): bool {
        $schedule = self::find_schedule( (string) ( $job['schedule_id'] ?? '' ) );
        if ( ! $schedule ) {
            return true;
        }
        if ( self::cancellation_requested( $job ) ) {
            self::fail_job( $job, sanitize_text_field( (string) ( $job['cancel_message'] ?? __( 'The report was cancelled.', 'activity-log-for-admins' ) ) ) );
            return true;
        }
        if ( self::deliver_job( $job ) ) {
            $archive_file = self::archive_job( $job );
            $message = '' === $archive_file ? __( 'The email was sent, but the private report copy could not be saved.', 'activity-log-for-admins' ) : '';
            self::record_result( $schedule, 'sent', (int) $job['rows'], $message, $archive_file );
            return true;
        }

        $attempt = (int) ( $job['delivery_attempts'] ?? 0 ) + 1;
        $message = __( 'WordPress could not send the scheduled report. Check the site mail configuration.', 'activity-log-for-admins' );
        if ( $attempt >= self::MAX_DELIVERY_ATTEMPTS ) {
            $archive_file = self::archive_job( $job );
            if ( '' === $archive_file ) {
                $message .= ' ' . __( 'The private report copy could not be saved.', 'activity-log-for-admins' );
            }
            self::record_result( $schedule, 'failed', (int) $job['rows'], $message, $archive_file );
            return true;
        }

        $delay = self::RETRY_DELAYS[ min( count( self::RETRY_DELAYS ) - 1, $attempt - 1 ) ];
        $job['delivery_attempts'] = $attempt;
        $job['retry_at'] = time() + $delay;
        if ( ! self::save_job( $job ) ) {
            self::record_result( $schedule, 'failed', (int) $job['rows'], __( 'The email failed and retry progress could not be saved.', 'activity-log-for-admins' ) );
            return true;
        }
        self::mark_retry_pending( $schedule, $attempt, $delay );
        self::schedule_continue( $delay );
        return false;
    }

    private static function mark_retry_pending( array $schedule, int $attempt, int $delay ): void {
        $schedules = self::schedules();
        foreach ( $schedules as &$current ) {
            if ( hash_equals( $current['id'], $schedule['id'] ) ) {
                $current['last_status'] = 'pending';
                $current['last_error'] = sprintf(
                    // translators: %1$d is the current retry number; %2$d is the maximum retry number; %3$d is the wait time in minutes.
                    __( 'Email delivery failed. Retry %1$d of %2$d is queued in about %3$d minutes.', 'activity-log-for-admins' ),
                    $attempt,
                    self::MAX_DELIVERY_ATTEMPTS,
                    (int) ceil( $delay / MINUTE_IN_SECONDS )
                );
                break;
            }
        }
        unset( $current );
        update_option( self::SCHEDULES, $schedules, false );
    }

    private static function deliver_job( array $job ): bool {
        $latest_job = get_option( self::JOB, [] );
        $schedule = self::find_schedule( (string) ( $job['schedule_id'] ?? '' ) );
        if ( ! is_array( $latest_job ) || self::cancellation_requested( $latest_job ) || ! $schedule
            || ! hash_equals( (string) ( $job['path'] ?? '' ), (string) ( $latest_job['path'] ?? '' ) ) ) {
            return false;
        }
        $recipients = array_values( array_filter(
            (array) ( $latest_job['recipients'] ?? [] ),
            static fn( $recipient ): bool => is_string( $recipient )
                && in_array( strtolower( $recipient ), array_map( 'strtolower', (array) $schedule['recipients'] ), true )
        ) );
        if ( ! $recipients ) {
            return false;
        }
        // translators: %1$s is the site name; %2$s is the scheduled report name.
        $subject = sprintf( __( '[%1$s] Scheduled activity report: %2$s', 'activity-log-for-admins' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $job['name'] );
        $format_label = 'html' === ( $job['format'] ?? 'csv' ) ? 'HTML' : 'CSV';
        $body = sprintf(
            // translators: %1$s is the report file format; %2$d is the number of matching events.
            __( 'Your scheduled activity report is attached as a %1$s file. It contains %2$d matching events for the selected date range. Manage report recipients and delivery in the Activity Log Reports page.', 'activity-log-for-admins' ),
            $format_label,
            (int) $job['rows']
        );
        try {
            $sent = wp_mail( $recipients, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $job['path'] ] );
        } catch ( \Throwable $error ) {
            $sent = false;
        }
        return (bool) $sent;
    }

    private static function find_schedule( string $id ): ?array {
        foreach ( self::schedules() as $schedule ) {
            if ( hash_equals( $schedule['id'], $id ) ) {
                return $schedule;
            }
        }
        return null;
    }

    private static function fail_job( array $job, string $message ): void {
        $schedule = self::find_schedule( (string) ( $job['schedule_id'] ?? '' ) );
        if ( $schedule ) {
            self::record_result( $schedule, 'failed', (int) ( $job['rows'] ?? 0 ), $message );
        }
    }

    private static function record_result( array $schedule, string $status, int $rows, string $message, string $archive_file = '' ): void {
        $schedules = self::schedules();
        foreach ( $schedules as &$current ) {
            if ( hash_equals( $current['id'], $schedule['id'] ) ) {
                $current['last_run'] = time();
                $current['last_status'] = $status;
                $current['last_error'] = $message;
                if ( $current['next_run'] <= time() ) {
                    $current['next_run'] = self::next_run( $current['cadence'], $current['hour'] );
                }
                break;
            }
        }
        unset( $current );
        update_option( self::SCHEDULES, $schedules, false );
        $history = self::history();
        $entry = [
            'id' => wp_generate_uuid4(),
            'schedule_id' => $schedule['id'],
            'name' => $schedule['name'],
            'cadence' => $schedule['cadence'],
            'format' => $schedule['format'] ?? 'csv',
            'occurred_at' => time(),
            'status' => $status,
            'event_count' => max( 0, $rows ),
            'message' => sanitize_text_field( $message ),
        ];
        if ( '' !== $archive_file && false !== self::valid_archive_file( $archive_file, (string) ( $schedule['format'] ?? 'csv' ) ) ) {
            $entry['file'] = $archive_file;
        }
        array_unshift( $history, $entry );
        $retained = array_slice( $history, 0, self::HISTORY_LIMIT );
        foreach ( array_slice( $history, self::HISTORY_LIMIT ) as $expired ) {
            self::delete_archive_file( (string) ( $expired['file'] ?? '' ) );
        }
        update_option( self::HISTORY, $retained, false );
    }

    private static function prune_history(): void {
        $cutoff = time() - Store::settings()['retention'] * DAY_IN_SECONDS;
        $current = self::history();
        $history = array_values( array_filter( $current, static fn( array $entry ): bool => (int) ( $entry['occurred_at'] ?? 0 ) >= $cutoff ) );
        if ( $history !== $current ) {
            $kept_ids = array_fill_keys( array_map( static fn( array $entry ): string => (string) ( $entry['id'] ?? '' ), $history ), true );
            foreach ( $current as $entry ) {
                if ( ! isset( $kept_ids[ (string) ( $entry['id'] ?? '' ) ] ) ) {
                    self::delete_archive_file( (string) ( $entry['file'] ?? '' ) );
                }
            }
            update_option( self::HISTORY, $history, false );
        }
    }

    private static function cleanup_orphan_files(): void {
        $last = (int) get_option( 'alfa_report_temp_cleanup_at', 0 );
        if ( time() - $last < DAY_IN_SECONDS ) {
            return;
        }
        $directory = self::temp_directory();
        if ( is_wp_error( $directory ) ) {
            return;
        }
        $job = get_option( self::JOB, [] );
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) ) {
            return;
        }
        $temporary_files = self::directory_files( $directory, $filesystem );
        if ( is_wp_error( $temporary_files ) ) {
            return;
        }
        foreach ( $temporary_files as $path ) {
            if ( ! preg_match( '/\Areport-[a-f0-9]{32}\.(?:csv|html)\z/', basename( $path ) ) || is_link( $path ) ) {
                continue;
            }
            if ( is_array( $job ) && isset( $job['path'] ) && hash_equals( (string) $job['path'], $path ) ) {
                continue;
            }
            $modified = $filesystem->mtime( $path );
            if ( $modified && $modified < time() - DAY_IN_SECONDS ) {
                $filesystem->delete( $path );
                $filesystem->delete( $path . '.lock' );
            }
        }
        $archive_directory = self::archive_directory();
        if ( ! is_wp_error( $archive_directory ) ) {
            $archive_files = self::directory_files( $archive_directory, $filesystem );
            if ( is_wp_error( $archive_files ) ) {
                return;
            }
            $known_files = [];
            foreach ( self::history() as $entry ) {
                $filename = (string) ( $entry['file'] ?? '' );
                if ( false !== self::valid_archive_file( $filename, (string) ( $entry['format'] ?? '' ) ) ) {
                    $known_files[ $filename ] = true;
                }
            }
            $archive_cutoff = time() - self::retention_days() * DAY_IN_SECONDS;
            foreach ( $archive_files as $path ) {
                $filename = basename( $path );
                if ( ! preg_match( '/\Areport-[a-f0-9]{32}\.(?:csv|html)\z/', $filename ) || is_link( $path ) ) {
                    continue;
                }
                $modified = $filesystem->mtime( $path );
                if ( ! isset( $known_files[ $filename ] ) || ( false !== $modified && $modified < $archive_cutoff ) ) {
                    $filesystem->delete( $path );
                }
            }
        }
        update_option( 'alfa_report_temp_cleanup_at', time(), false );
    }

    private static function retention_days(): int {
        $settings = get_option( 'alfa_settings', [] );
        $retention = is_array( $settings ) ? (int) ( $settings['retention'] ?? 90 ) : 90;
        return min( 3650, max( 1, $retention ) );
    }

    private static function schedule_health( bool $failed ): void {
        $health = (array) get_option( Store::HEALTH, [] );
        if ( $failed ) {
            if ( time() - (int) ( $health['report_schedule_error'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
                $health['report_schedule_error'] = time();
                update_option( Store::HEALTH, $health, false );
            }
        } elseif ( array_key_exists( 'report_schedule_error', $health ) ) {
            unset( $health['report_schedule_error'] );
            update_option( Store::HEALTH, $health, false );
        }
    }

    private static function filesystem(): \WP_Filesystem_Base|\WP_Error {
        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'WP_Filesystem' ) || ( ! $wp_filesystem && ! WP_Filesystem() )
            || ! ( $wp_filesystem instanceof \WP_Filesystem_Base ) ) {
            return new \WP_Error( 'alfa_report_filesystem', __( 'WordPress could not initialize private report storage.', 'activity-log-for-admins' ) );
        }
        return $wp_filesystem;
    }

    private static function directory_files( string $directory, \WP_Filesystem_Base $filesystem ): array|\WP_Error {
        $entries = $filesystem->dirlist( $directory, false );
        if ( ! is_array( $entries ) ) {
            return new \WP_Error( 'alfa_report_filesystem_list', __( 'WordPress could not inspect private report storage.', 'activity-log-for-admins' ) );
        }
        $paths = [];
        foreach ( $entries as $entry ) {
            if ( is_array( $entry ) && 'f' === ( $entry['type'] ?? '' ) && is_string( $entry['name'] ?? null ) ) {
                $paths[] = trailingslashit( $directory ) . $entry['name'];
            }
        }
        return $paths;
    }

    private static function acquire_report_lock(): string|false|\WP_Error {
        global $wpdb;
        $site_key = substr( hash( 'sha256', (string) get_option( 'siteurl' ) . (string) $wpdb->prefix . (string) get_current_blog_id() ), 0, 32 );
        $lock_name = self::REPORT_LOCK_PREFIX . $site_key;
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
        if ( '1' === (string) $acquired ) {
            return $lock_name;
        }
        if ( null === $acquired ) {
            return new \WP_Error( 'alfa_report_lock', __( 'The database could not secure the scheduled report job.', 'activity-log-for-admins' ) );
        }
        return false;
    }

    private static function release_report_lock( string $lock_name ): void {
        global $wpdb;
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }

    private static function temp_directory(): string|\WP_Error {
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }
        $root = realpath( sys_get_temp_dir() );
        $wordpress = realpath( ABSPATH );
        if ( false === $root || ! $filesystem->is_writable( $root ) ) {
            return new \WP_Error( 'alfa_report_temp', __( 'The server does not provide a private writable temporary directory.', 'activity-log-for-admins' ) );
        }
        $root_normal = trailingslashit( wp_normalize_path( $root ) );
        $wordpress_normal = false === $wordpress ? '' : trailingslashit( wp_normalize_path( $wordpress ) );
        if ( '' !== $wordpress_normal && str_starts_with( strtolower( $root_normal ), strtolower( $wordpress_normal ) ) ) {
            return new \WP_Error( 'alfa_report_temp', __( 'The server temporary directory is inside the public WordPress directory.', 'activity-log-for-admins' ) );
        }
        global $wpdb;
        $site_hash = substr( hash( 'sha256', (string) get_option( 'siteurl' ) . (string) $wpdb->prefix . (string) get_current_blog_id() ), 0, 16 );
        $directory = $root . DIRECTORY_SEPARATOR . 'activity-log-for-admins-' . $site_hash;
        if ( is_link( $directory ) || ( ! $filesystem->is_dir( $directory ) && ! $filesystem->mkdir( $directory, 0700 ) ) ) {
            return new \WP_Error( 'alfa_report_temp', __( 'The private report directory could not be created.', 'activity-log-for-admins' ) );
        }
        if ( ! self::set_private_permissions( $directory, 0700 ) ) {
            return new \WP_Error( 'alfa_report_temp', __( 'Private report directory permissions could not be secured.', 'activity-log-for-admins' ) );
        }
        $resolved = realpath( $directory );
        if ( false === $resolved || ! $filesystem->is_writable( $resolved ) || ! str_starts_with( strtolower( trailingslashit( wp_normalize_path( $resolved ) ) ), strtolower( $root_normal ) ) ) {
            return new \WP_Error( 'alfa_report_temp', __( 'The private report directory failed its location check.', 'activity-log-for-admins' ) );
        }
        return $resolved;
    }

    private static function archive_directory(): string|\WP_Error {
        $temporary = self::temp_directory();
        if ( is_wp_error( $temporary ) ) {
            return $temporary;
        }
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) ) {
            return $filesystem;
        }
        $directory = $temporary . DIRECTORY_SEPARATOR . 'history';
        if ( is_link( $directory ) || ( ! $filesystem->is_dir( $directory ) && ! $filesystem->mkdir( $directory, 0700 ) ) ) {
            return new \WP_Error( 'alfa_report_archive', __( 'The private report history directory could not be created.', 'activity-log-for-admins' ) );
        }
        if ( ! self::set_private_permissions( $directory, 0700 ) ) {
            return new \WP_Error( 'alfa_report_archive', __( 'Private report history permissions could not be secured.', 'activity-log-for-admins' ) );
        }
        $resolved = realpath( $directory );
        if ( false === $resolved || ! $filesystem->is_writable( $resolved )
            || strtolower( dirname( wp_normalize_path( $resolved ) ) ) !== strtolower( wp_normalize_path( $temporary ) ) ) {
            return new \WP_Error( 'alfa_report_archive', __( 'The private report history directory failed its location check.', 'activity-log-for-admins' ) );
        }
        return $resolved;
    }

    private static function archive_job( array $job ): string {
        $format = in_array( $job['format'] ?? '', [ 'csv', 'html' ], true ) ? $job['format'] : '';
        if ( '' === $format || ! self::valid_job_file( $job ) || ! self::make_archive_room() ) {
            return '';
        }
        $directory = self::archive_directory();
        if ( is_wp_error( $directory ) ) {
            return '';
        }
        try {
            $filename = 'report-' . bin2hex( random_bytes( 16 ) ) . '.' . $format;
        } catch ( \Throwable $error ) {
            return '';
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) ) {
            return '';
        }
        $contents = $filesystem->get_contents( $job['path'] );
        if ( ! is_string( $contents ) || strlen( $contents ) > self::MAX_REPORT_BYTES || $filesystem->exists( $path )
            || ! $filesystem->put_contents( $path, $contents, 0600 ) || ! self::set_private_permissions( $path, 0600 ) ) {
            $filesystem->delete( $path );
            return '';
        }
        return $filename;
    }

    private static function make_archive_room(): bool {
        $history = self::history();
        $files = [];
        foreach ( $history as $index => $entry ) {
            if ( false !== self::valid_archive_file( (string) ( $entry['file'] ?? '' ), (string) ( $entry['format'] ?? '' ) ) ) {
                $files[ $index ] = $entry['file'];
            }
        }
        $changed = false;
        while ( count( $files ) >= self::ARCHIVE_LIMIT ) {
            $oldest_index = array_key_last( $files );
            if ( null === $oldest_index ) {
                break;
            }
            self::delete_archive_file( (string) $files[ $oldest_index ] );
            unset( $history[ $oldest_index ]['file'], $files[ $oldest_index ] );
            $changed = true;
        }
        if ( $changed ) {
            $history = array_values( $history );
            if ( ! update_option( self::HISTORY, $history, false ) && $history !== get_option( self::HISTORY, [] ) ) {
                return false;
            }
        }
        return count( $files ) < self::ARCHIVE_LIMIT;
    }

    private static function valid_archive_file( string $filename, string $format = '' ): string|false {
        if ( ! preg_match( '/\Areport-[a-f0-9]{32}\.(csv|html)\z/', $filename, $matches )
            || ( '' !== $format && $format !== $matches[1] ) ) {
            return false;
        }
        $directory = self::archive_directory();
        if ( is_wp_error( $directory ) ) {
            return false;
        }
        $candidate = $directory . DIRECTORY_SEPARATOR . $filename;
        $path = realpath( $candidate );
        return false !== $path && ! is_link( $candidate )
            && strtolower( dirname( wp_normalize_path( $path ) ) ) === strtolower( wp_normalize_path( $directory ) )
                ? $path
                : false;
    }

    private static function delete_archive_file( string $filename ): void {
        $path = self::valid_archive_file( $filename );
        $filesystem = self::filesystem();
        if ( false !== $path && ! is_wp_error( $filesystem ) ) {
            $filesystem->delete( $path );
        }
    }

    private static function erase_report_copies(): array {
        $directory = self::archive_directory();
        if ( is_wp_error( $directory ) ) {
            return [ 'removed' => false, 'error' => __( 'Private report copies could not be checked during data erasure.', 'activity-log-for-admins' ) ];
        }
        $removed = false;
        $failed = false;
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) ) {
            return [ 'removed' => false, 'error' => __( 'Private report copies could not be checked during data erasure.', 'activity-log-for-admins' ) ];
        }
        $files = self::directory_files( $directory, $filesystem );
        if ( is_wp_error( $files ) ) {
            return [ 'removed' => false, 'error' => __( 'Private report copies could not be checked during data erasure.', 'activity-log-for-admins' ) ];
        }
        foreach ( $files as $path ) {
            if ( ! preg_match( '/\Areport-[a-f0-9]{32}\.(?:csv|html)\z/', basename( $path ) ) ) {
                continue;
            }
            $filesystem->delete( $path );
            if ( $filesystem->exists( $path ) || is_link( $path ) ) {
                $failed = true;
            } else {
                $removed = true;
            }
        }
        $history = self::history();
        foreach ( $history as &$entry ) {
            unset( $entry['file'] );
        }
        unset( $entry );
        if ( ! update_option( self::HISTORY, $history, false ) && $history !== get_option( self::HISTORY, [] ) ) {
            $failed = true;
        }
        return [
            'removed' => $removed,
            'error' => $failed ? __( 'Private report copies could not be fully removed. Retry the privacy erasure.', 'activity-log-for-admins' ) : '',
        ];
    }

    private static function set_private_permissions( string $path, int $mode ): bool {
        if ( '\\' === DIRECTORY_SEPARATOR ) {
            return true;
        }
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) || ! $filesystem->chmod( $path, $mode ) ) {
            return false;
        }
        $permissions = octdec( $filesystem->getchmod( $path ) );
        return $permissions > 0 && 0 === ( ( $permissions & 0777 ) & ~$mode );
    }

    private static function contains_identifier( string $value, array $identifiers ): bool {
        $value = strtolower( $value );
        foreach ( $identifiers as $identifier ) {
            if ( '' !== $identifier && ( str_contains( $identifier, '@' ) ? str_contains( $value, $identifier ) : $value === $identifier ) ) {
                return true;
            }
        }
        return false;
    }

    private static function valid_job_file( array $job ): bool {
        if ( ! is_string( $job['path'] ?? null ) || ! preg_match( '/\Areport-[a-f0-9]{32}\.(?:csv|html)\z/', basename( $job['path'] ) ) ) {
            return false;
        }
        $directory = self::temp_directory();
        if ( is_wp_error( $directory ) ) {
            return false;
        }
        $filesystem = self::filesystem();
        if ( is_wp_error( $filesystem ) || ! $filesystem->is_file( $job['path'] ) ) {
            return false;
        }
        $path = realpath( $job['path'] );
        return false !== $path && ! is_link( $job['path'] )
            && strtolower( dirname( wp_normalize_path( $path ) ) ) === strtolower( wp_normalize_path( $directory ) );
    }

    private static function remove_job_file( mixed $job ): void {
        if ( ! is_array( $job ) || ! is_string( $job['path'] ?? null ) ) {
            return;
        }
        $directory = self::temp_directory();
        if ( is_wp_error( $directory ) || ! self::valid_job_file( $job ) ) {
            return;
        }
        $filesystem = self::filesystem();
        if ( ! is_wp_error( $filesystem ) ) {
            $filesystem->delete( $job['path'] );
            $filesystem->delete( $job['path'] . '.lock' );
        }
    }

    private static function save_job( array $job ): bool {
        $saved = update_option( self::JOB, $job, false );
        return $saved || $job === get_option( self::JOB, [] );
    }

    private static function request_job_cancellation( array $job ): bool {
        $job['cancelled'] = true;
        $job['cancel_message'] = __( 'The report stopped while activity data was being erased.', 'activity-log-for-admins' );
        $token = [ 'path' => (string) ( $job['path'] ?? '' ), 'schedule_id' => (string) ( $job['schedule_id'] ?? '' ) ];
        $saved_token = update_option( self::CANCELLED_JOB, $token, false );
        if ( ! $saved_token && $token !== get_option( self::CANCELLED_JOB, [] ) ) {
            return false;
        }
        return self::save_job( $job );
    }

    private static function cancellation_requested( array $job ): bool {
        if ( ! empty( $job['cancelled'] ) ) {
            return true;
        }
        $token = get_option( self::CANCELLED_JOB, [] );
        return is_array( $token )
            && hash_equals( (string) ( $job['path'] ?? '' ), (string) ( $token['path'] ?? '' ) )
            && hash_equals( (string) ( $job['schedule_id'] ?? '' ), (string) ( $token['schedule_id'] ?? '' ) );
    }

    private static function clear_cancellation( array $job ): void {
        $token = get_option( self::CANCELLED_JOB, [] );
        if ( is_array( $token )
            && hash_equals( (string) ( $job['path'] ?? '' ), (string) ( $token['path'] ?? '' ) )
            && hash_equals( (string) ( $job['schedule_id'] ?? '' ), (string) ( $token['schedule_id'] ?? '' ) ) ) {
            delete_option( self::CANCELLED_JOB );
        }
    }

    private static function schedule_continue( int $delay = MINUTE_IN_SECONDS ): void {
        if ( wp_next_scheduled( self::CONTINUE ) ) {
            return;
        }
        wp_schedule_single_event( time() + max( MINUTE_IN_SECONDS, $delay ), self::CONTINUE, [], true );
    }

    private static function csv_row( array $values ): string {
        $safe = array_map( static fn( $value ): string => self::safe_csv_cell( (string) $value ), $values );
        $quoted = array_map( static fn( string $value ): string => '"' . str_replace( '"', '""', $value ) . '"', $safe );
        return implode( ',', $quoted ) . "\r\n";
    }

    private static function html_report_start( array $schedule, string $from, string $to ): string {
        $title = esc_html( (string) $schedule['name'] );
        $metadata = '<p><strong>' . esc_html__( 'Date range', 'activity-log-for-admins' ) . ':</strong> ' . esc_html( $from . ' to ' . $to ) . '</p>';
        $metadata .= '<p><strong>' . esc_html__( 'Generated', 'activity-log-for-admins' ) . ':</strong> ' . esc_html( wp_date( 'j M Y, H:i:s T' ) ) . '</p>';
        if ( ! empty( $schedule['branding'] ) ) {
            $metadata .= '<p><strong>' . esc_html__( 'Site', 'activity-log-for-admins' ) . ':</strong> ' . esc_html( get_bloginfo( 'name' ) ) . '</p>';
        }
        $headers = '';
        foreach ( $schedule['columns'] as $column ) {
            $headers .= '<th scope="col">' . esc_html( self::column_label( $column ) ) . '</th>';
        }
        return '<!doctype html><html lang="' . esc_attr( determine_locale() ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . $title . '</title><style>body{font:16px/1.5 system-ui,sans-serif;color:#17212b;margin:2rem}main{max-width:1200px;margin:auto}table{border-collapse:collapse;width:100%}th,td{border:1px solid #c8d1dc;padding:.55rem;text-align:left;vertical-align:top}th{background:#f0f4f8}td{white-space:pre-wrap;overflow-wrap:anywhere}tr:nth-child(even){background:#f8fafc}@media print{body{margin:0}tr{break-inside:avoid}}</style></head><body><main><header><h1>'
            . $title . '</h1>' . $metadata . '</header><table><thead><tr>' . $headers . '</tr></thead><tbody>';
    }

    private static function html_row( array $values ): string {
        $cells = '';
        foreach ( $values as $value ) {
            $cells .= '<td>' . esc_html( (string) $value ) . '</td>';
        }
        return '<tr>' . $cells . "</tr>\n";
    }

    private static function safe_csv_cell( string $value ): string {
        if ( preg_match( '/\A[=+@\-\t\r]/', $value ) ) {
            return "'" . $value;
        }
        return $value;
    }

    private static function column_label( string $column ): string {
        return match ( $column ) {
            'event_id' => __( 'Event ID', 'activity-log-for-admins' ),
            'time' => __( 'Time', 'activity-log-for-admins' ),
            'actor' => __( 'Actor', 'activity-log-for-admins' ),
            'actor_role' => __( 'Actor role', 'activity-log-for-admins' ),
            'source' => __( 'Request source', 'activity-log-for-admins' ),
            'item' => __( 'Affected item', 'activity-log-for-admins' ),
            'action' => __( 'Event type', 'activity-log-for-admins' ),
            'severity' => __( 'Severity', 'activity-log-for-admins' ),
            'summary' => __( 'Summary', 'activity-log-for-admins' ),
            'details' => __( 'Change details', 'activity-log-for-admins' ),
            default => '',
        };
    }

    private static function event_value( string $column, array $row ): string {
        $timestamp = strtotime( (string) $row['occurred_at'] . ' UTC' );
        $actor = (string) $row['actor_name'];
        if ( '' === $actor ) {
            $actor = match ( $row['actor_kind'] ) {
                'anonymous' => __( 'Unauthenticated', 'activity-log-for-admins' ),
                'system' => __( 'WordPress background task', 'activity-log-for-admins' ),
                'cli' => __( 'WP CLI', 'activity-log-for-admins' ),
                default => __( 'Unknown', 'activity-log-for-admins' ),
            };
        }
        $details = json_decode( (string) $row['details'], true );
        if ( ! is_array( $details ) ) {
            $details = [];
        }
        $change_text = '';
        foreach ( (array) ( $details['changes'] ?? [] ) as $label => $change ) {
            if ( is_array( $change ) && 2 === count( $change ) ) {
                $change_text .= ( '' !== $change_text ? "\n" : '' ) . sanitize_text_field( (string) $label ) . ': ' . wp_json_encode( array_values( $change ), JSON_INVALID_UTF8_SUBSTITUTE );
            }
        }
        unset( $details['changes'] );
        $detail_text = '' === $change_text && ! $details ? '' : trim( $change_text . ( $details ? "\n" . wp_json_encode( $details, JSON_INVALID_UTF8_SUBSTITUTE ) : '' ) );
        $item = '' !== (string) $row['item_name'] ? (string) $row['item_name'] : ucwords( str_replace( '_', ' ', (string) $row['item_kind'] ) );
        return match ( $column ) {
            'event_id' => (string) $row['id'],
            'time' => wp_date( 'j M Y, H:i:s T', $timestamp ),
            'actor' => $actor,
            'actor_role' => (string) $row['actor_role'],
            'source' => (string) $row['source'],
            'item' => $item,
            'action' => ucwords( str_replace( '_', ' ', (string) $row['action_key'] ) ),
            'severity' => (string) $row['severity'],
            'summary' => (string) $row['summary'],
            'details' => $detail_text,
            default => '',
        };
    }
}
