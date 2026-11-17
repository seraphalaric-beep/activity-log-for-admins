<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Privacy {
    private const PAGE_SIZE = 50;
    private const ERASE_BATCH = 250;
    private const ERASE_STATE = 'alfa_privacy_erase_state';

    public static function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
    }

    public static function exporters( array $exporters ): array {
        $exporters['activity-log-for-admins'] = [
            'exporter_friendly_name' => __( 'Activity Log for Admins', 'activity-log-for-admins' ),
            'callback' => [ self::class, 'export_personal_data' ],
        ];
        return $exporters;
    }

    public static function erasers( array $erasers ): array {
        $erasers['activity-log-for-admins'] = [
            'eraser_friendly_name' => __( 'Activity Log for Admins', 'activity-log-for-admins' ),
            'callback' => [ self::class, 'erase_personal_data' ],
        ];
        return $erasers;
    }

    public static function export_personal_data( string $email_address, int $page = 1 ): array {
        global $wpdb;
        if ( ! self::can_manage_privacy() ) {
            return [ 'data' => [], 'done' => true, 'messages' => [ __( 'You are not allowed to export this activity data.', 'activity-log-for-admins' ) ] ];
        }
        $email = sanitize_email( $email_address );
        if ( ! is_email( $email ) ) {
            return [ 'data' => [], 'done' => true ];
        }
        $page = max( 1, $page );
        $offset = ( $page - 1 ) * self::PAGE_SIZE;
        $user = get_user_by( 'email', $email );
        $user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
        $state_key = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
        $states = get_option( self::ERASE_STATE, [] );
        if ( ! is_array( $states ) ) {
            $states = [];
        }
        $states = array_filter( $states, static fn( $state ): bool => is_array( $state ) && (int) ( $state['updated_at'] ?? 0 ) > time() - WEEK_IN_SECONDS );
        $cursor = max( 0, (int) ( $states[ $state_key ]['cursor'] ?? 0 ) );
        $data = [];
        if ( 1 === $page ) {
            array_push( $data, ...self::email_data( $email, $user ) );
            array_push( $data, ...self::saved_report_data( $email, $user ) );
            if ( $user_id ) {
                array_push( $data, ...self::access_data( $user_id ) );
                $last_activity = Sessions::activity_for_user( $user_id );
                if ( $last_activity ) {
                    $data[] = [
                        'item_id' => 'session-activity-' . $user_id,
                        'group_id' => 'activity-log-session-activity',
                        'item_data' => [
                            [ 'name' => __( 'Session activity', 'activity-log-for-admins' ), 'value' => __( 'Last observed request', 'activity-log-for-admins' ) ],
                            [ 'name' => __( 'Time', 'activity-log-for-admins' ), 'value' => wp_date( 'j M Y, H:i:s T', $last_activity ) ],
                        ],
                    ];
                }
            }
        }

        $events = [];
        $notes = [];
        $email_pattern = '%' . $wpdb->esc_like( $email ) . '%';
        if ( $user_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export only events linked to the account or containing its email address.
            $events = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, occurred_at, actor_id, actor_name, item_kind, item_key, item_name, action_key, summary, details FROM %i WHERE (actor_id = %d OR (item_kind = %s AND item_key = %s) OR actor_name LIKE %s OR item_key LIKE %s OR item_name LIKE %s OR summary LIKE %s OR details LIKE %s) ORDER BY id ASC LIMIT %d OFFSET %d',
                Store::table(), $user_id, 'user', (string) $user_id, $email_pattern, $email_pattern, $email_pattern, $email_pattern, $email_pattern, self::PAGE_SIZE, $offset
            ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export events containing the email address.
            $events = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, occurred_at, actor_id, actor_name, item_kind, item_key, item_name, action_key, summary, details FROM %i WHERE (actor_name LIKE %s OR item_key LIKE %s OR item_name LIKE %s OR summary LIKE %s OR details LIKE %s) ORDER BY id ASC LIMIT %d OFFSET %d',
                Store::table(), $email_pattern, $email_pattern, $email_pattern, $email_pattern, $email_pattern, self::PAGE_SIZE, $offset
            ), ARRAY_A );
        }
        if ( $user_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export notes authored by the account or notes containing its email address.
            $notes = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, event_id, created_at, note FROM %i WHERE (user_id = %d OR note LIKE %s) ORDER BY id ASC LIMIT %d OFFSET %d',
                Store::notes_table(), $user_id, $email_pattern, self::PAGE_SIZE, $offset
            ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Export notes containing the email address.
            $notes = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, event_id, created_at, note FROM %i WHERE note LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d',
                Store::notes_table(), $email_pattern, self::PAGE_SIZE, $offset
            ), ARRAY_A );
        }

        foreach ( is_array( $events ) ? $events : [] as $event ) {
            $details = json_decode( (string) $event['details'], true );
            $data[] = [
                'item_id' => 'event-' . (int) $event['id'],
                'group_id' => 'activity-log-events',
                'item_data' => [
                    [ 'name' => __( 'Time', 'activity-log-for-admins' ), 'value' => wp_date( 'j M Y, H:i:s T', strtotime( $event['occurred_at'] . ' UTC' ) ) ],
                    [ 'name' => __( 'Action', 'activity-log-for-admins' ), 'value' => sanitize_text_field( (string) $event['action_key'] ) ],
                    [ 'name' => __( 'Actor', 'activity-log-for-admins' ), 'value' => ( ( $user_id && (int) $event['actor_id'] === $user_id ) || str_contains( strtolower( (string) $event['actor_name'] ), strtolower( $email ) ) ) ? sanitize_text_field( (string) $event['actor_name'] ) : __( 'Another site user', 'activity-log-for-admins' ) ],
                    [ 'name' => __( 'Affected item', 'activity-log-for-admins' ), 'value' => ( 'user' === $event['item_kind'] && (string) $user_id === (string) $event['item_key'] ) ? __( 'WordPress account', 'activity-log-for-admins' ) : sanitize_text_field( (string) $event['item_name'] ) ],
                    [ 'name' => __( 'Summary', 'activity-log-for-admins' ), 'value' => sanitize_text_field( (string) $event['summary'] ) ],
                    [ 'name' => __( 'Event details', 'activity-log-for-admins' ), 'value' => is_array( $details ) ? wp_json_encode( $details, JSON_INVALID_UTF8_SUBSTITUTE ) : '' ],
                ],
            ];
        }
        foreach ( is_array( $notes ) ? $notes : [] as $note ) {
            $data[] = [
                'item_id' => 'note-' . (int) $note['id'],
                'group_id' => 'activity-log-notes',
                'item_data' => [
                    [ 'name' => __( 'Event ID', 'activity-log-for-admins' ), 'value' => (string) $note['event_id'] ],
                    [ 'name' => __( 'Time', 'activity-log-for-admins' ), 'value' => wp_date( 'j M Y, H:i:s T', strtotime( $note['created_at'] . ' UTC' ) ) ],
                    [ 'name' => __( 'Note', 'activity-log-for-admins' ), 'value' => sanitize_textarea_field( (string) $note['note'] ) ],
                ],
            ];
        }
        if ( $wpdb->last_error ) {
            return [
                'data' => $data,
                'done' => true,
                'messages' => [ __( 'Activity data could not be fully read. Check the Activity Log database table before completing this export.', 'activity-log-for-admins' ) ],
            ];
        }
        return [ 'data' => $data, 'done' => count( (array) $events ) < self::PAGE_SIZE && count( (array) $notes ) < self::PAGE_SIZE ];
    }

    private static function email_data( string $email, ?\WP_User $user ): array {
        $items = [];
        array_push( $items, ...Alerts::export_personal_data( $email, $user ) );
        $settings = Store::settings();
        $alert_recipients = $settings['email_alert_recipients'];
        if ( in_array( strtolower( $email ), array_map( 'strtolower', $alert_recipients ), true )
            || ( $settings['email_alerts'] && ! $alert_recipients && strtolower( $email ) === strtolower( (string) get_option( 'admin_email' ) ) ) ) {
            $items[] = self::email_item( 'warning digest recipient', $email );
        }
        $identifiers = [ strtolower( $email ) ];
        if ( $user instanceof \WP_User ) {
            $identifiers = array_merge( $identifiers, [ strtolower( $user->user_login ), strtolower( $user->display_name ) ] );
        }
        $schedules = get_option( ScheduledReports::SCHEDULES, [] );
        $linked_schedule_ids = [];
        foreach ( is_array( $schedules ) ? $schedules : [] as $schedule ) {
            if ( ! is_array( $schedule ) ) {
                continue;
            }
            $is_recipient = in_array( strtolower( $email ), array_map( 'strtolower', array_filter( (array) ( $schedule['recipients'] ?? [] ), 'is_string' ) ), true );
            $filters = (array) ( $schedule['filters'] ?? [] );
            $matches_user = $user instanceof \WP_User && (string) ( $filters['actor_id'] ?? '' ) === (string) $user->ID;
            $matching_filters = [];
            foreach ( $filters as $key => $value ) {
                if ( is_string( $value ) && self::contains_identifier( $value, $identifiers ) ) {
                    $matching_filters[] = sanitize_key( (string) $key ) . ': ' . sanitize_text_field( $value );
                }
            }
            if ( $matches_user ) {
                $matching_filters[] = __( 'Actor: this account', 'activity-log-for-admins' );
            }
            $schedule_name = (string) ( $schedule['name'] ?? '' );
            $name_matches = self::contains_identifier( $schedule_name, $identifiers );
            if ( $name_matches ) {
                $matching_filters[] = __( 'Report name', 'activity-log-for-admins' ) . ': ' . sanitize_text_field( $schedule_name );
            }
            if ( ! $is_recipient && ! $matching_filters && ! $name_matches ) {
                continue;
            }
            if ( is_string( $schedule['id'] ?? null ) ) {
                $linked_schedule_ids[] = strtolower( $schedule['id'] );
            }
            $items[] = [
                'item_id' => 'scheduled-report-' . sanitize_key( (string) ( $schedule['id'] ?? '' ) ),
                'group_id' => 'activity-log-report-recipients',
                'item_data' => [
                    [ 'name' => __( 'Report', 'activity-log-for-admins' ), 'value' => sanitize_text_field( $schedule_name ) ],
                    [ 'name' => __( 'Frequency', 'activity-log-for-admins' ), 'value' => sanitize_key( (string) ( $schedule['cadence'] ?? '' ) ) ],
                    [ 'name' => __( 'Format', 'activity-log-for-admins' ), 'value' => sanitize_key( (string) ( $schedule['format'] ?? 'csv' ) ) ],
                    [ 'name' => __( 'Why this report is linked', 'activity-log-for-admins' ), 'value' => $is_recipient ? __( 'Recipient', 'activity-log-for-admins' ) : implode( '; ', $matching_filters ) ],
                    ...( $is_recipient ? [ [ 'name' => __( 'Recipient email', 'activity-log-for-admins' ), 'value' => $email ] ] : [] ),
                ],
            ];
        }
        $history = get_option( ScheduledReports::HISTORY, [] );
        foreach ( is_array( $history ) ? $history : [] as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $schedule_id = strtolower( (string) ( $entry['schedule_id'] ?? '' ) );
            $schedule_matches = '' !== $schedule_id && in_array( $schedule_id, $linked_schedule_ids, true );
            $name_matches = self::contains_identifier( (string) ( $entry['name'] ?? '' ), $identifiers );
            if ( ! $schedule_matches && ! $name_matches ) {
                continue;
            }
            $items[] = [
                'item_id' => 'activity-log-report-history-' . sanitize_key( (string) ( $entry['id'] ?? '' ) ),
                'group_id' => 'activity-log-report-history',
                'item_data' => [
                    [ 'name' => __( 'Report', 'activity-log-for-admins' ), 'value' => sanitize_text_field( (string) ( $entry['name'] ?? '' ) ) ],
                    [ 'name' => __( 'Format', 'activity-log-for-admins' ), 'value' => sanitize_key( (string) ( $entry['format'] ?? '' ) ) ],
                    [ 'name' => __( 'Frequency', 'activity-log-for-admins' ), 'value' => sanitize_key( (string) ( $entry['cadence'] ?? '' ) ) ],
                    [ 'name' => __( 'Delivery time', 'activity-log-for-admins' ), 'value' => wp_date( 'j M Y, H:i:s T', max( 0, (int) ( $entry['occurred_at'] ?? 0 ) ) ) ],
                    [ 'name' => __( 'Delivery status', 'activity-log-for-admins' ), 'value' => sanitize_key( (string) ( $entry['status'] ?? '' ) ) ],
                    [ 'name' => __( 'Matching events', 'activity-log-for-admins' ), 'value' => (string) max( 0, (int) ( $entry['event_count'] ?? 0 ) ) ],
                    ...( ! empty( $entry['file'] ) ? [ [ 'name' => __( 'Private report copy', 'activity-log-for-admins' ), 'value' => __( 'A copy was retained in report history at the time of delivery.', 'activity-log-for-admins' ) ] ] : [] ),
                ],
            ];
        }
        return $items;
    }

    private static function saved_report_data( string $email, ?\WP_User $user ): array {
        $items = [];
        $identifiers = [ strtolower( $email ) ];
        if ( $user instanceof \WP_User ) {
            $identifiers = array_merge( $identifiers, [ strtolower( $user->user_login ), strtolower( $user->display_name ) ] );
        }
        foreach ( Store::saved_reports() as $report ) {
            $filters = (array) $report['filters'];
            $matches_user = $user instanceof \WP_User && (string) ( $filters['actor_id'] ?? '' ) === (string) $user->ID;
            $matches_value = false;
            $matched_filter_identifier = false;
            $references = [];
            foreach ( $filters as $value ) {
                if ( is_string( $value ) && self::contains_identifier( $value, $identifiers ) ) {
                    $matches_value = true;
                    $matched_filter_identifier = true;
                    $references[] = sanitize_text_field( $value );
                }
            }
            $matches_name = self::contains_identifier( (string) $report['name'], $identifiers );
            if ( $matches_user ) {
                $references[] = __( 'Actor: this account', 'activity-log-for-admins' );
            }
            if ( $matches_name ) {
                $references[] = __( 'Report name', 'activity-log-for-admins' ) . ': ' . sanitize_text_field( $report['name'] );
            }
            foreach ( (array) ( $report['tags'] ?? [] ) as $tag ) {
                if ( is_string( $tag ) && self::contains_identifier( $tag, $identifiers ) ) {
                    $matches_name = true;
                    $references[] = __( 'Tag', 'activity-log-for-admins' ) . ': ' . sanitize_text_field( $tag );
                }
            }
            if ( $matches_user || $matches_value || $matches_name ) {
                $item_data = [
                    [ 'name' => __( 'Saved report', 'activity-log-for-admins' ), 'value' => sanitize_text_field( $report['name'] ) ],
                    [ 'name' => __( 'Matching reference', 'activity-log-for-admins' ), 'value' => implode( '; ', array_values( array_unique( $references ) ) ) ],
                ];
                if ( ( $matches_user || $matched_filter_identifier ) && isset( $report['statistics'] ) ) {
                    $statistics = $report['statistics'];
                    $item_data[] = [
                        'name' => __( 'Saved statistics result', 'activity-log-for-admins' ),
                        // translators: %1$d is the number of matching events; %2$s is the start of the date range; %3$s is the end of the date range.
                        'value' => sprintf( _n( '%1$d event, %2$s through %3$s', '%1$d events, %2$s through %3$s', $statistics['total'], 'activity-log-for-admins' ), $statistics['total'], $statistics['from'], $statistics['to'] ),
                    ];
                }
                $items[] = [
                    'item_id' => 'saved-report-' . $report['id'],
                    'group_id' => 'activity-log-reports',
                    'item_data' => $item_data,
                ];
            }
        }
        return $items;
    }

    private static function access_data( int $user_id ): array {
        $items = [];
        $settings = Store::settings();
        foreach ( [
            'viewer_users' => __( 'Can view activity', 'activity-log-for-admins' ),
            'manager_users' => __( 'Can manage Activity Log', 'activity-log-for-admins' ),
            'excluded_users' => __( 'Excluded from activity recording', 'activity-log-for-admins' ),
        ] as $key => $label ) {
            if ( in_array( $user_id, $settings[ $key ], true ) ) {
                $items[] = [
                    'item_id' => 'activity-log-access-' . $key,
                    'group_id' => 'activity-log-settings',
                    'item_data' => [ [ 'name' => __( 'Access setting', 'activity-log-for-admins' ), 'value' => $label ] ],
                ];
            }
        }
        if ( in_array( 'user:' . $user_id, $settings['excluded_items'], true ) ) {
            $items[] = [
                'item_id' => 'activity-log-excluded-user-item',
                'group_id' => 'activity-log-settings',
                'item_data' => [ [ 'name' => __( 'Item exclusion', 'activity-log-for-admins' ), 'value' => __( 'This account item is excluded from recording', 'activity-log-for-admins' ) ] ],
            ];
        }
        return $items;
    }

    private static function email_item( string $purpose, string $email ): array {
        return [
            'item_id' => 'activity-log-email-' . sanitize_key( $purpose ),
            'group_id' => 'activity-log-email-settings',
            'item_data' => [
                [ 'name' => __( 'Purpose', 'activity-log-for-admins' ), 'value' => $purpose ],
                [ 'name' => __( 'Email address', 'activity-log-for-admins' ), 'value' => $email ],
            ],
        ];
    }

    public static function erase_personal_data( string $email_address, int $page = 1 ): array {
        global $wpdb;
        if ( ! self::can_manage_privacy() ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [ __( 'You are not allowed to erase this activity data.', 'activity-log-for-admins' ) ], 'done' => true ];
        }
        $email = sanitize_email( $email_address );
        if ( ! is_email( $email ) ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
        $user = get_user_by( 'email', $email );
        $user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
        $state_key = hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'auth' ) );
        $states = get_option( self::ERASE_STATE, [] );
        if ( ! is_array( $states ) ) {
            $states = [];
        }
        $states = array_filter( $states, static fn( $state ): bool => is_array( $state ) && (int) ( $state['updated_at'] ?? 0 ) > time() - WEEK_IN_SECONDS );
        $cursor = max( 0, (int) ( $states[ $state_key ]['cursor'] ?? 0 ) );
        $items_removed = false;
        $items_retained = false;
        $messages = [];
        $has_error = false;

        $settings = self::erase_settings( $email, $user );
        $items_removed = $settings['removed'] || $items_removed;
        if ( $settings['error'] ) {
            $messages[] = $settings['error'];
            $has_error = true;
        }
        $reports = self::erase_saved_reports( $email, $user );
        $items_removed = $reports['removed'] || $items_removed;
        if ( $reports['error'] ) {
            $messages[] = $reports['error'];
            $has_error = true;
        }
        $delivery = ScheduledReports::erase_personal_data( $email, $user );
        $items_removed = $delivery['removed'] || $items_removed;
        if ( $delivery['errors'] ) {
            array_push( $messages, ...$delivery['errors'] );
            $has_error = true;
        }
        $alerts = Alerts::erase_personal_data( $email, $user );
        $items_removed = $alerts['removed'] || $items_removed;
        if ( '' !== $alerts['error'] ) {
            $messages[] = $alerts['error'];
            $has_error = true;
        }

        $notes_more = false;
        $events_more = false;
        $events_safe = '' === $settings['error'] && '' === $alerts['error'] && ! empty( $delivery['events_safe'] );
        if ( ! $events_safe ) {
            $messages[] = __( 'Activity entries will be processed after privacy related email settings, pending alerts, and active reports are secured.', 'activity-log-for-admins' );
            $has_error = true;
        }
        if ( $events_safe ) {
            $email_pattern = '%' . $wpdb->esc_like( $email ) . '%';
            if ( $user_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Find authored notes and notes naming this email in a bounded batch.
                $note_ids = $wpdb->get_col( $wpdb->prepare(
                    'SELECT id FROM %i WHERE (user_id = %d OR note LIKE %s) ORDER BY id ASC LIMIT %d',
                    Store::notes_table(), $user_id, $email_pattern, self::ERASE_BATCH + 1
                ) );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Find notes naming this email in a bounded batch.
                $note_ids = $wpdb->get_col( $wpdb->prepare(
                    'SELECT id FROM %i WHERE note LIKE %s ORDER BY id ASC LIMIT %d',
                    Store::notes_table(), $email_pattern, self::ERASE_BATCH + 1
                ) );
            }
            if ( $wpdb->last_error || ! is_array( $note_ids ) ) {
                $messages[] = __( 'Personal notes could not be processed. Check the Activity Log database table and retry the erasure.', 'activity-log-for-admins' );
                $has_error = true;
            } elseif ( $note_ids ) {
                $notes_more = count( $note_ids ) > self::ERASE_BATCH;
                $note_ids = array_slice( array_map( 'intval', $note_ids ), 0, self::ERASE_BATCH );
                foreach ( $note_ids as $note_id ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove a note selected for privacy erasure by its integer primary key.
                    $deleted = $wpdb->delete( Store::notes_table(), [ 'id' => $note_id ], [ '%d' ] );
                    if ( false === $deleted ) {
                        $messages[] = __( 'Personal notes could not be deleted. Retry the erasure request.', 'activity-log-for-admins' );
                        $has_error = true;
                        break;
                    }
                    $items_removed = $items_removed || $deleted > 0;
                }
            }

            if ( $user_id ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Find events linked to this account or containing its email in a bounded batch.
                $events = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, actor_id, actor_name, actor_role, item_kind, item_key, item_name, summary, details FROM %i WHERE (actor_id = %d OR (item_kind = %s AND item_key = %s) OR actor_name LIKE %s OR item_key LIKE %s OR item_name LIKE %s OR summary LIKE %s OR details LIKE %s) AND id > %d ORDER BY id ASC LIMIT %d',
                    Store::table(), $user_id, 'user', (string) $user_id, $email_pattern, $email_pattern, $email_pattern, $email_pattern, $email_pattern, $cursor, self::ERASE_BATCH + 1
                ), ARRAY_A );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Find events containing this email in a bounded batch.
                $events = $wpdb->get_results( $wpdb->prepare(
                    'SELECT id, actor_id, actor_name, actor_role, item_kind, item_key, item_name, summary, details FROM %i WHERE (actor_name LIKE %s OR item_key LIKE %s OR item_name LIKE %s OR summary LIKE %s OR details LIKE %s) AND id > %d ORDER BY id ASC LIMIT %d',
                    Store::table(), $email_pattern, $email_pattern, $email_pattern, $email_pattern, $email_pattern, $cursor, self::ERASE_BATCH + 1
                ), ARRAY_A );
            }
            if ( $wpdb->last_error || ! is_array( $events ) ) {
                $messages[] = __( 'Activity data could not be processed. Check the Activity Log database table and retry the erasure.', 'activity-log-for-admins' );
                $has_error = true;
            } elseif ( $events ) {
                $events_more = count( $events ) > self::ERASE_BATCH;
                $events = array_slice( $events, 0, self::ERASE_BATCH );
                $note_deletion_failed = false;
                foreach ( $events as $event ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Remove notes linked to an event selected for privacy erasure by its integer key.
                    if ( false === $wpdb->delete( Store::notes_table(), [ 'event_id' => (int) $event['id'] ], [ '%d' ] ) ) {
                        $note_deletion_failed = true;
                        break;
                    }
                }
                if ( $note_deletion_failed ) {
                    $messages[] = __( 'Notes linked to activity could not be deleted. Retry the erasure request.', 'activity-log-for-admins' );
                    $has_error = true;
                } else {
                    $actor_name = __( 'Deleted user', 'activity-log-for-admins' );
                    $actor_summary = __( 'Activity retained, details removed during privacy erasure', 'activity-log-for-admins' );
                    $account_name = __( 'WordPress account', 'activity-log-for-admins' );
                    $account_summary = __( 'Account activity with personal details removed', 'activity-log-for-admins' );
                    $update_failed = false;
                    foreach ( $events as $event ) {
                        $id = (int) $event['id'];
                        $actor_matches = $user_id && (int) $event['actor_id'] === $user_id;
                        $account_matches = $user_id && 'user' === $event['item_kind'] && (string) $user_id === (string) $event['item_key'];
                        $actor_email_matches = false !== stripos( (string) $event['actor_name'], $email );
                        $key_email_matches = false !== stripos( (string) $event['item_key'], $email );
                        $item_email_matches = false !== stripos( (string) $event['item_name'], $email );
                        $email_in_event = $actor_email_matches || $key_email_matches || $item_email_matches
                            || false !== stripos( (string) $event['summary'], $email )
                            || false !== stripos( (string) $event['details'], $email );
                        $changes = [];
                        if ( $actor_matches ) {
                            $changes['actor_id'] = 0;
                            $changes['actor_name'] = $actor_name;
                            $changes['actor_role'] = '';
                            $changes['summary'] = $actor_summary;
                            $changes['details'] = '{}';
                        }
                        if ( $account_matches ) {
                            $changes['item_name'] = $account_name;
                            $changes['item_key'] = '';
                            $changes['summary'] = $account_summary;
                            $changes['details'] = '{}';
                        }
                        if ( $email_in_event ) {
                            if ( $actor_email_matches ) {
                                $changes['actor_id'] = 0;
                                $changes['actor_name'] = $actor_name;
                            }
                            if ( $key_email_matches ) {
                                $changes['item_key'] = '';
                            }
                            if ( $item_email_matches && ! $account_matches ) {
                                $changes['item_name'] = __( 'Activity item', 'activity-log-for-admins' );
                            }
                            $changes['summary'] = $actor_summary;
                            $changes['details'] = '{}';
                        }
                        if ( ! $changes ) {
                            continue;
                        }
                        $formats = array_map( static fn( string $key ): string => 'actor_id' === $key ? '%d' : '%s', array_keys( $changes ) );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Retain the audit row while replacing personal fields by its integer primary key.
                        $updated = $wpdb->update( Store::table(), $changes, [ 'id' => $id ], $formats, [ '%d' ] );
                        if ( false === $updated ) {
                            $update_failed = true;
                        } else {
                            $items_retained = $items_retained || $updated > 0;
                        }
                    }
                    if ( $update_failed ) {
                        $messages[] = __( 'Activity entries could not be anonymized. Retry the erasure request.', 'activity-log-for-admins' );
                        $has_error = true;
                    } else {
                        $event_ids = array_map( static fn( array $event ): int => (int) $event['id'], $events );
                        $cursor = max( $cursor, max( $event_ids ) );
                    }
                }
            }
        }

        if ( $items_retained ) {
            $messages[] = __( 'Activity entries were retained for site administration. Account names and identifiers were removed, and personal change details were cleared.', 'activity-log-for-admins' );
        }
        $done = ! $has_error && ! $events_more && ! $notes_more;
        if ( $done ) {
            unset( $states[ $state_key ] );
        } elseif ( $user_id || $cursor > 0 ) {
            $states[ $state_key ] = [ 'cursor' => $cursor, 'updated_at' => time() ];
        }
        if ( $states ) {
            $saved_state = update_option( self::ERASE_STATE, $states, false );
            if ( ! $saved_state && $states !== get_option( self::ERASE_STATE, [] ) ) {
                $messages[] = __( 'Privacy erasure progress could not be saved. Retry the erasure request.', 'activity-log-for-admins' );
                $done = false;
            }
        } else {
            delete_option( self::ERASE_STATE );
        }
        if ( $done ) {
            $resumed = Alerts::resume_after_privacy();
            if ( is_wp_error( $resumed ) ) {
                $messages[] = $resumed->get_error_message();
                $done = false;
            }
        }
        return [ 'items_removed' => $items_removed, 'items_retained' => $items_retained, 'messages' => array_values( array_unique( $messages ) ), 'done' => $done ];
    }

    private static function erase_settings( string $email, ?\WP_User $user ): array {
        $settings = Store::settings();
        $user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
        $removed = false;
        if ( $user instanceof \WP_User ) {
            $removed = Sessions::erase_activity_for_user( (int) $user->ID ) || $removed;
        }
        foreach ( [ 'viewer_users', 'manager_users', 'excluded_users' ] as $key ) {
            $remaining = array_values( array_diff( $settings[ $key ], $user_id ? [ $user_id ] : [] ) );
            if ( $remaining !== $settings[ $key ] ) {
                $settings[ $key ] = $remaining;
                $removed = true;
            }
        }
        $excluded_user_item = 'user:' . $user_id;
        if ( $user_id && in_array( $excluded_user_item, $settings['excluded_items'], true ) ) {
            $settings['excluded_items'] = array_values( array_diff( $settings['excluded_items'], [ $excluded_user_item ] ) );
            $removed = true;
        }
        $recipients = array_values( array_filter( $settings['email_alert_recipients'], static fn( string $recipient ): bool => strtolower( $recipient ) !== strtolower( $email ) ) );
        if ( $recipients !== $settings['email_alert_recipients'] ) {
            $settings['email_alert_recipients'] = $recipients;
            $removed = true;
        }
        if ( $settings['email_alerts'] && ! $recipients && strtolower( $email ) === strtolower( (string) get_option( 'admin_email' ) ) ) {
            $settings['email_alerts'] = false;
            delete_option( 'alfa_alert_digest_after_id' );
            $removed = true;
        }
        $stored = Store::settings();
        if ( $settings !== $stored ) {
            update_option( Store::SETTINGS, $settings, false );
        }
        $error = Store::settings() !== $settings ? __( 'Activity Log privacy settings could not be updated. Retry the erasure request.', 'activity-log-for-admins' ) : '';
        return [ 'removed' => $removed, 'error' => $error ];
    }

    private static function erase_saved_reports( string $email, ?\WP_User $user ): array {
        $reports = get_option( Store::SAVED_REPORTS, [] );
        if ( ! is_array( $reports ) ) {
            return [ 'removed' => false, 'error' => '' ];
        }
        $identifiers = [ strtolower( $email ) ];
        if ( $user instanceof \WP_User ) {
            $identifiers = array_merge( $identifiers, [ strtolower( $user->user_login ), strtolower( $user->display_name ) ] );
        }
        $changed = false;
        foreach ( $reports as &$report ) {
            if ( ! is_array( $report ) ) {
                continue;
            }
            $filters = (array) ( $report['filters'] ?? [] );
            $original_filters = $filters;
            $matches_user = $user instanceof \WP_User && (string) ( $filters['actor_id'] ?? '' ) === (string) $user->ID;
            $matched_filter_identifier = false;
            foreach ( $filters as $key => $value ) {
                if ( is_string( $value ) && self::contains_identifier( $value, $identifiers ) ) {
                    unset( $filters[ $key ] );
                    $changed = true;
                    $matched_filter_identifier = true;
                }
            }
            if ( $matches_user ) {
                unset( $filters['actor_id'], $filters['actor_kind'] );
            }
            if ( $filters !== $original_filters ) {
                $report['filters'] = $filters;
                $changed = true;
            }
            if ( ( $matches_user || $matched_filter_identifier ) && array_key_exists( 'statistics', $report ) ) {
                unset( $report['statistics'] );
                $changed = true;
            }
            if ( isset( $report['name'] ) && is_string( $report['name'] ) && self::contains_identifier( $report['name'], $identifiers ) ) {
                $report['name'] = __( 'Saved report', 'activity-log-for-admins' );
                $changed = true;
            }
            if ( is_array( $report['tags'] ?? null ) ) {
                $tags = array_values( array_filter( $report['tags'], static fn( $tag ): bool => ! is_string( $tag ) || ! self::contains_identifier( $tag, $identifiers ) ) );
                if ( $tags !== $report['tags'] ) {
                    $report['tags'] = $tags;
                    $changed = true;
                }
            }
        }
        unset( $report );
        if ( $changed ) {
            update_option( Store::SAVED_REPORTS, $reports, false );
        }
        $error = $changed && get_option( Store::SAVED_REPORTS, [] ) !== $reports ? __( 'Saved report privacy data could not be updated. Retry the erasure request.', 'activity-log-for-admins' ) : '';
        return [ 'removed' => $changed && '' === $error, 'error' => $error ];
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

    private static function can_manage_privacy(): bool {
        return current_user_can( 'manage_privacy_options' ) || current_user_can( 'manage_options' );
    }
}
