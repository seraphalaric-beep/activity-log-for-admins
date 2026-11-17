<?php

function alfa_privacy_fixture(): array {
    global $wpdb;
    $suffix = strtolower( wp_generate_password( 8, false, false ) );
    $email = 'privacy-' . $suffix . '@example.test';
    $login = 'alfa_privacy_' . $suffix;
    $marker = 'privacy-fixture-' . $suffix;
    $secret = 'PRIVACY_SECRET_' . strtoupper( $suffix );
    $admin_id = (int) get_current_user_id();
    $email_event_key = 'privacy-email-' . $suffix;
    $settings_snapshot = get_option( ActivityLogForAdmins\Store::SETTINGS, false );
    $reports_snapshot = get_option( ActivityLogForAdmins\Store::SAVED_REPORTS, false );
    $schedules_snapshot = get_option( ActivityLogForAdmins\ScheduledReports::SCHEDULES, false );
    $job_snapshot = get_option( ActivityLogForAdmins\ScheduledReports::JOB, false );
    $history_snapshot = get_option( ActivityLogForAdmins\ScheduledReports::HISTORY, false );
    $alert_rules_snapshot = get_option( ActivityLogForAdmins\Alerts::RULES, false );
    $alert_queue_snapshot = get_option( 'alfa_alert_rule_queue', false );
    $alert_retry_generation_snapshot = get_option( 'alfa_alert_retry_generation', false );
    $alert_paused_snapshot = get_option( 'alfa_alert_queue_paused', false );
    $user_id = 0;
    $editor_id = 0;
    $schedule_id = wp_generate_uuid4();
    $job_directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'activity-log-for-admins-' . substr( hash( 'sha256', (string) get_option( 'siteurl' ) . (string) $wpdb->prefix . (string) get_current_blog_id() ), 0, 16 );
    $job_path = $job_directory . DIRECTORY_SEPARATOR . 'report-' . bin2hex( random_bytes( 16 ) ) . '.html';
    $archive_directory = $job_directory . DIRECTORY_SEPARATOR . 'history';
    $archive_path = $archive_directory . DIRECTORY_SEPARATOR . 'report-' . bin2hex( random_bytes( 16 ) ) . '.html';
    $event_ids = [];
    $editor_deleted_callback = [ ActivityLogForAdmins\Capture::class, 'user_deleted' ];

    try {
        update_option( ActivityLogForAdmins\Alerts::RULES, [], false );
        update_option( 'alfa_alert_rule_queue', [], false );
        update_option( 'alfa_alert_queue_paused', false, false );
        wp_clear_scheduled_hook( ActivityLogForAdmins\Alerts::HOOK );
        wp_clear_scheduled_hook( ActivityLogForAdmins\Alerts::RETRY_HOOK );
        $user_id = (int) wp_insert_user( [
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => wp_generate_password( 32, true, true ),
            'display_name' => 'Privacy fixture ' . $suffix,
            'role' => 'subscriber',
        ] );
        fixture_check( $user_id );
        $target = get_user_by( 'id', $user_id );
        fixture_check( $target );
        fixture_check( ! is_link( $job_directory ) && ( is_dir( $job_directory ) || wp_mkdir_p( $job_directory ) ) );
        fixture_check( ! is_link( $archive_directory ) && ( is_dir( $archive_directory ) || wp_mkdir_p( $archive_directory ) ) );
        fixture_check( false !== file_put_contents( $job_path, '<!doctype html><html><body>Privacy fixture</body></html>' ) );
        fixture_check( false !== file_put_contents( $archive_path, '<!doctype html><html><body>' . esc_html( $email ) . '</body></html>' ) );

        wp_set_current_user( $user_id );
        for ( $index = 1; $index <= 251; ++$index ) {
            ActivityLogForAdmins\Store::add( [
                'actor_kind' => 'user', 'actor_id' => $user_id, 'actor_name' => $target->display_name, 'actor_role' => 'subscriber',
                'source' => 'admin', 'item_kind' => 'page', 'item_key' => $marker, 'item_name' => 'Privacy fixture page',
                'action_key' => 'content_edited', 'severity' => 'info', 'summary' => 'Private activity for ' . $email . ' ' . $index,
            ], [ 'changes' => [ 'Private field' => [ $email, $secret ] ] ] );
            $event_ids[] = ActivityLogForAdmins\Store::latest_id();
        }
        $first_event_id = (int) end( $event_ids );
        fixture_check( ActivityLogForAdmins\Store::add_note( $first_event_id, 'Private note written by ' . $email ) );

        wp_set_current_user( $admin_id );
        ActivityLogForAdmins\Store::add( [
            'actor_kind' => 'user', 'actor_id' => $admin_id, 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
            'source' => 'admin', 'item_kind' => 'user', 'item_key' => (string) $user_id, 'item_name' => $target->display_name . ' ' . $email,
            'action_key' => 'role_changed', 'severity' => 'notice', 'summary' => 'Changed account for ' . $email,
        ], [ 'changes' => [ 'Account email' => [ $email, $email ] ] ] );
        $affected_event_id = ActivityLogForAdmins\Store::latest_id();
        $event_ids[] = $affected_event_id;
        fixture_check( ActivityLogForAdmins\Store::add_note( $affected_event_id, 'Private account note for ' . $email ) );
        ActivityLogForAdmins\Store::add( [
            'actor_kind' => 'user', 'actor_id' => $admin_id, 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
            'source' => 'admin', 'item_kind' => 'setting', 'item_key' => $email_event_key, 'item_name' => 'Unrelated setting fixture',
            'action_key' => 'setting_changed', 'severity' => 'info', 'summary' => 'Unrelated event mentions ' . $email,
        ], [ 'changes' => [ 'Configured address' => [ $email, 'privacy-replacement@example.test' ] ] ] );
        $email_event_id = ActivityLogForAdmins\Store::latest_id();
        $event_ids[] = $email_event_id;
        fixture_check( ActivityLogForAdmins\Store::add_note( $email_event_id, 'Private note mentioning ' . $email ) );

        $alert_rule = ActivityLogForAdmins\Alerts::save( [
            'name' => 'Privacy alert recipient fixture',
            'enabled' => true,
            'logic' => 'all',
            'conditions' => [ [ 'field' => 'action_key', 'operator' => 'equals', 'value' => 'privacy_alert_fixture' ] ],
            'recipients' => [ $email, 'privacy-alert-keep@example.test' ],
        ] );
        fixture_check( $alert_rule );
        ActivityLogForAdmins\Store::add( [
            'actor_kind' => 'user', 'actor_id' => $admin_id, 'actor_name' => 'pilotadmin', 'actor_role' => 'administrator',
            'source' => 'admin', 'item_kind' => 'setting', 'item_key' => 'privacy-alert-' . $suffix, 'item_name' => 'Privacy alert fixture',
            'action_key' => 'privacy_alert_fixture', 'severity' => 'info', 'summary' => 'Alert data for ' . $email,
        ], [] );
        $alert_event_id = ActivityLogForAdmins\Store::latest_id();
        $event_ids[] = $alert_event_id;
        $alert_queue = (array) get_option( 'alfa_alert_rule_queue', [] );
        $alert_was_queued = (bool) array_filter( $alert_queue, static fn( $entry ): bool => is_array( $entry ) && (int) ( $entry['event_id'] ?? 0 ) === $alert_event_id );
        fixture_check( $alert_was_queued );

        $personal_ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM %i WHERE actor_id = %d OR (item_kind = %s AND item_key = %s)',
            ActivityLogForAdmins\Store::table(), $user_id, 'user', (string) $user_id
        ) );
        $event_ids = array_values( array_unique( array_merge( $event_ids, array_map( 'intval', (array) $personal_ids ) ) ) );

        $test_settings = ActivityLogForAdmins\Store::settings();
        $test_settings['email_alerts'] = true;
        $test_settings['email_alert_recipients'] = [ $email, 'privacy-keep@example.test' ];
        $test_settings['viewer_users'][] = $user_id;
        $test_settings['manager_users'][] = $user_id;
        $test_settings['excluded_users'][] = $user_id;
        update_option( ActivityLogForAdmins\Store::SETTINGS, $test_settings, false );

        update_option( ActivityLogForAdmins\Store::SAVED_REPORTS, [ [
            'id' => wp_generate_uuid4(), 'name' => 'Report for ' . $email,
            'filters' => [ 'actor_id' => (string) $user_id, 'actor' => $login, 'actor_kind' => 'user' ],
            'group' => 'category', 'interval' => 'daily', 'range_days' => 1,
            'columns' => [ 'time', 'actor', 'summary' ], 'branding' => false,
            'tags' => [ 'privacy', $email ], 'created_at' => gmdate( 'Y-m-d H:i:s' ),
            'statistics' => [
                'from' => wp_date( 'Y-m-d' ), 'to' => wp_date( 'Y-m-d' ), 'generated_at' => gmdate( 'Y-m-d H:i:s' ),
                'group' => 'actor_role', 'interval' => 'daily', 'total' => 1,
                'rows' => [ [ 'label' => 'subscriber', 'total' => 1 ] ],
            ],
        ] ], false );

        update_option( ActivityLogForAdmins\ScheduledReports::SCHEDULES, [ [
            'id' => $schedule_id, 'name' => 'Report for ' . $email, 'cadence' => 'daily', 'format' => 'html', 'hour' => 9,
            'recipients' => [ $email, 'privacy-keep@example.test' ],
            'filters' => [ 'actor_id' => (string) $user_id, 'actor' => $login, 'actor_kind' => 'user' ],
            'range_days' => 1, 'columns' => [ 'time', 'actor', 'summary' ], 'branding' => false,
            'next_run' => time() + DAY_IN_SECONDS, 'last_run' => 0, 'last_status' => '', 'last_error' => '',
        ] ], false );
        $history_fixture = [
            'id' => wp_generate_uuid4(), 'name' => 'Report for ' . $email, 'cadence' => 'daily', 'format' => 'html',
            'occurred_at' => time(), 'status' => 'sent', 'event_count' => 1, 'message' => '', 'file' => basename( $archive_path ),
        ];
        update_option( ActivityLogForAdmins\ScheduledReports::HISTORY, array_merge( [ $history_fixture ], is_array( $history_snapshot ) ? $history_snapshot : [] ), false );
        update_option( ActivityLogForAdmins\ScheduledReports::JOB, [
            'schedule_id' => $schedule_id, 'name' => 'Report for ' . $email, 'format' => 'html',
            'recipients' => [ $email, 'privacy-keep@example.test' ],
            'filters' => [ 'actor_id' => (string) $user_id, 'actor' => $login, 'actor_kind' => 'user' ],
            'path' => $job_path, 'cursor' => 0, 'ceiling' => (int) ActivityLogForAdmins\Store::latest_id(),
            'rows' => 0, 'attempts' => 0, 'started_at' => time(),
        ], false );

        $exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
        $erasers = apply_filters( 'wp_privacy_personal_data_erasers', [] );
        $export_registered = isset( $exporters['activity-log-for-admins']['callback'] );
        $erase_registered = isset( $erasers['activity-log-for-admins']['callback'] );
        $editor = wp_insert_user( [
            'user_login' => 'alfa_privacy_editor_' . $suffix,
            'user_email' => 'privacy-editor-' . $suffix . '@example.test',
            'user_pass' => wp_generate_password( 32, true, true ),
            'role' => 'editor',
        ] );
        fixture_check( $editor );
        $editor_id = (int) $editor;
        $editor_event_ids = $wpdb->get_col( $wpdb->prepare(
            'SELECT id FROM %i WHERE item_kind = %s AND item_key = %s',
            ActivityLogForAdmins\Store::table(), 'user', (string) $editor_id
        ) );
        $event_ids = array_merge( $event_ids, array_map( 'intval', (array) $editor_event_ids ) );
        wp_set_current_user( $editor_id );
        $denied_export = ActivityLogForAdmins\Privacy::export_personal_data( $email, 1 );
        $denied_erase = ActivityLogForAdmins\Privacy::erase_personal_data( $email, 1 );
        $access_denied = ! empty( $denied_export['messages'] ) && ! empty( $denied_erase['messages'] )
            && false === $denied_erase['items_removed'];

        wp_set_current_user( $admin_id );
        $export = [];
        $export_pages = 0;
        do {
            $page = ActivityLogForAdmins\Privacy::export_personal_data( $email, ++$export_pages );
            $export = array_merge( $export, (array) ( $page['data'] ?? [] ) );
            if ( ! empty( $page['messages'] ) ) {
                throw new RuntimeException( implode( ' ', $page['messages'] ) );
            }
            if ( $export_pages > 20 ) {
                throw new RuntimeException( 'The privacy export exceeded its page limit.' );
            }
        } while ( empty( $page['done'] ) );
        $event_count = count( array_filter( $export, static fn( array $item ): bool => 'activity-log-events' === ( $item['group_id'] ?? '' ) ) );
        $note_count = count( array_filter( $export, static fn( array $item ): bool => 'activity-log-notes' === ( $item['group_id'] ?? '' ) ) );
        $report_history_count = count( array_filter( $export, static fn( array $item ): bool => 'activity-log-report-history' === ( $item['group_id'] ?? '' ) ) );
        $statistics_snapshot_exported = str_contains( (string) wp_json_encode( $export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), '1 event,' );
        $alert_rule_count = count( array_filter( $export, static fn( array $item ): bool => 'activity-log-alert-rules' === ( $item['group_id'] ?? '' ) ) );
        $export_text = wp_json_encode( $export, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        $retry_generation_before_erase = (int) get_option( 'alfa_alert_retry_generation', 0 );
        $erase_pages = 0;
        $erase_result = [];
        do {
            $erase_result = ActivityLogForAdmins\Privacy::erase_personal_data( $email, ++$erase_pages );
            if ( $erase_pages > 20 ) {
                throw new RuntimeException( 'The privacy erasure exceeded its page limit.' );
            }
        } while ( empty( $erase_result['done'] ) && $erase_pages < 20 );
        $retry_generation_advanced = (int) get_option( 'alfa_alert_retry_generation', 0 ) > $retry_generation_before_erase;
        ActivityLogForAdmins\Alerts::retry_event( $alert_event_id, 1, $retry_generation_before_erase );
        $stale_retry_ignored = [] === (array) get_option( 'alfa_alert_rule_queue', [] );
        $alert_delivery_resumed = ! ActivityLogForAdmins\Alerts::channel_summary()['delivery_paused'];

        $settings_after = ActivityLogForAdmins\Store::settings();
        $reports_after = get_option( ActivityLogForAdmins\Store::SAVED_REPORTS, [] );
        $schedules_after = get_option( ActivityLogForAdmins\ScheduledReports::SCHEDULES, [] );
        $job_after = get_option( ActivityLogForAdmins\ScheduledReports::JOB, [] );
        $job_file_removed = ! file_exists( $job_path );
        $archive_file_removed = ! file_exists( $archive_path );
        $history_after = (array) get_option( ActivityLogForAdmins\ScheduledReports::HISTORY, [] );
        $archive_references_removed = ! array_filter( $history_after, static fn( $entry ): bool => is_array( $entry ) && isset( $entry['file'] ) );
        $alert_rules_after = ActivityLogForAdmins\Alerts::rules();
        $privacy_alert_rule = array_values( array_filter( $alert_rules_after, static fn( array $rule ): bool => 'Privacy alert recipient fixture' === $rule['name'] ) )[0] ?? [];
        $alert_recipient_removed = [ 'privacy-alert-keep@example.test' ] === ( $privacy_alert_rule['recipients'] ?? [] );
        $pending_custom_alerts_cleared = [] === (array) get_option( 'alfa_alert_rule_queue', [] );
        $personal_actor_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE actor_id = %d', ActivityLogForAdmins\Store::table(), $user_id ) );
        $personal_item_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE item_kind = %s AND item_key = %s', ActivityLogForAdmins\Store::table(), 'user', (string) $user_id ) );
        $notes_by_user = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', ActivityLogForAdmins\Store::notes_table(), $user_id ) );
        $linked_notes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', ActivityLogForAdmins\Store::notes_table(), $affected_event_id ) );
        $email_linked_notes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_id = %d', ActivityLogForAdmins\Store::notes_table(), $email_event_id ) );
        $actor_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT actor_id, actor_name, summary, details FROM %i WHERE item_key = %s', ActivityLogForAdmins\Store::table(), $marker ), ARRAY_A );
        $affected = $wpdb->get_row( $wpdb->prepare( 'SELECT actor_id, actor_name, item_key, item_name, summary, details FROM %i WHERE id = %d', ActivityLogForAdmins\Store::table(), $affected_event_id ), ARRAY_A );
        $email_event = $wpdb->get_row( $wpdb->prepare( 'SELECT actor_id, actor_name, summary, details FROM %i WHERE id = %d', ActivityLogForAdmins\Store::table(), $email_event_id ), ARRAY_A );
        $schedule = is_array( $schedules_after ) ? ( $schedules_after[0] ?? [] ) : [];
        $report = is_array( $reports_after ) ? ( $reports_after[0] ?? [] ) : [];
        $job_cancelled = empty( $job_after ) || ( is_array( $job_after ) && ! empty( $job_after['cancelled'] ) );
        $settings_clean = ! in_array( $user_id, $settings_after['viewer_users'], true )
            && ! in_array( $user_id, $settings_after['manager_users'], true )
            && ! in_array( $user_id, $settings_after['excluded_users'], true )
            && ! in_array( strtolower( $email ), array_map( 'strtolower', $settings_after['email_alert_recipients'] ), true )
            && in_array( 'privacy-keep@example.test', $settings_after['email_alert_recipients'], true );
        $report_clean = is_array( $report ) && ! isset( $report['filters']['actor_id'] ) && ! isset( $report['filters']['actor'] )
            && 'Saved report' === ( $report['name'] ?? '' ) && ! in_array( $email, (array) ( $report['tags'] ?? [] ), true )
            && ! isset( $report['statistics'] );
        $schedule_clean = is_array( $schedule ) && ! in_array( $email, (array) ( $schedule['recipients'] ?? [] ), true )
            && in_array( 'privacy-keep@example.test', (array) ( $schedule['recipients'] ?? [] ), true )
            && ! isset( $schedule['filters']['actor_id'] ) && ! isset( $schedule['filters']['actor'] )
            && 'Scheduled report' === ( $schedule['name'] ?? '' );
        $actors_clean = 251 === count( $actor_rows ) && array_reduce( $actor_rows, static fn( bool $valid, array $row ): bool => $valid
            && 0 === (int) $row['actor_id'] && 'Deleted user' === $row['actor_name']
            && '{}' === $row['details'] && ! str_contains( $row['summary'], $email ), true );
        $affected_clean = is_array( $affected ) && $admin_id === (int) $affected['actor_id'] && '' === $affected['item_key']
            && 'WordPress account' === $affected['item_name'] && '{}' === $affected['details']
            && ! str_contains( $affected['summary'], $email );
        $email_event_clean = is_array( $email_event ) && $admin_id === (int) $email_event['actor_id']
            && 'pilotadmin' === $email_event['actor_name'] && '{}' === $email_event['details']
            && ! str_contains( $email_event['summary'], $email );
        $privacy_ok = $export_registered && $erase_registered && $access_denied && $event_count >= 254 && $note_count >= 2 && $report_history_count >= 1
            && $alert_rule_count >= 1 && $alert_was_queued && $alert_recipient_removed && $pending_custom_alerts_cleared
            && $retry_generation_advanced && $stale_retry_ignored && $alert_delivery_resumed
            && is_string( $export_text ) && str_contains( $export_text, $email ) && str_contains( $export_text, $secret )
            && ! empty( $erase_result['done'] ) && ! empty( $erase_result['items_retained'] )
            && $settings_clean && $report_clean && $schedule_clean && $job_cancelled && $job_file_removed && $archive_file_removed && $archive_references_removed
            && 0 === $personal_actor_count && 0 === $personal_item_count && 0 === $notes_by_user && 0 === $linked_notes && 0 === $email_linked_notes
            && $actors_clean && $affected_clean && $email_event_clean;

        ActivityLogForAdmins\ScheduledReports::tick();
        $job_cleared = false === get_option( ActivityLogForAdmins\ScheduledReports::JOB, false );
        if ( ! $privacy_ok || ! $job_cleared ) {
            throw new RuntimeException( 'Privacy export or erasure verification failed: ' . wp_json_encode( [
                'export_registered' => $export_registered, 'erase_registered' => $erase_registered,
                'access_denied' => $access_denied, 'event_count' => $event_count, 'note_count' => $note_count,
                'export_pages' => $export_pages, 'erase_pages' => $erase_pages, 'report_history_count' => $report_history_count,
                'alert_rule_count' => $alert_rule_count, 'alert_was_queued' => $alert_was_queued,
                'alert_recipient_removed' => $alert_recipient_removed, 'pending_custom_alerts_cleared' => $pending_custom_alerts_cleared,
                'retry_generation_advanced' => $retry_generation_advanced, 'stale_retry_ignored' => $stale_retry_ignored,
                'alert_delivery_resumed' => $alert_delivery_resumed,
                'erase_result' => $erase_result, 'settings_clean' => $settings_clean,
                'report_clean' => $report_clean, 'schedule_clean' => $schedule_clean,
                'job_cancelled' => $job_cancelled, 'job_file_removed' => $job_file_removed,
                'archive_file_removed' => $archive_file_removed, 'archive_references_removed' => $archive_references_removed,
                'personal_actor_count' => $personal_actor_count,
                'personal_item_count' => $personal_item_count, 'notes_by_user' => $notes_by_user,
                'linked_notes' => $linked_notes, 'email_linked_notes' => $email_linked_notes, 'actor_rows' => count( $actor_rows ),
                'actors_clean' => $actors_clean, 'affected_clean' => $affected_clean, 'email_event_clean' => $email_event_clean,
                'job_cleared' => $job_cleared,
            ] ) );
        }
        return [
            'ok' => true, 'exporterRegistered' => $export_registered, 'eraserRegistered' => $erase_registered,
            'editorDenied' => $access_denied, 'exportedEvents' => $event_count, 'exportedNotes' => $note_count,
            'exportPages' => $export_pages, 'erasePages' => $erase_pages, 'eventsAnonymized' => count( $actor_rows ),
            'reportHistoryExported' => $report_history_count,
            'statisticsSnapshotExported' => $statistics_snapshot_exported,
            'alertRuleExported' => $alert_rule_count,
            'alertRecipientRemoved' => $alert_recipient_removed,
            'pendingCustomAlertsCleared' => $pending_custom_alerts_cleared,
            'retryGenerationAdvanced' => $retry_generation_advanced,
            'staleRetryIgnored' => $stale_retry_ignored,
            'alertDeliveryResumed' => $alert_delivery_resumed,
            'actorSettingsRemoved' => $settings_clean, 'savedReportCleaned' => $report_clean,
            'scheduledRecipientsRemoved' => $schedule_clean, 'activeJobCancelled' => $job_cancelled,
            'activeHtmlAttachmentRemoved' => $job_file_removed,
            'archivedReportCopyRemoved' => $archive_file_removed && $archive_references_removed,
            'eventNotesRemoved' => 0 === $linked_notes && 0 === $email_linked_notes && 0 === $notes_by_user,
            'emailReferencesRedacted' => $email_event_clean, 'pendingJobCleared' => $job_cleared,
        ];
    } finally {
        wp_delete_file( $job_path );
        wp_delete_file( $job_path . '.lock' );
        wp_delete_file( $archive_path );
        wp_set_current_user( $admin_id );
        if ( $user_id ) {
            remove_action( 'deleted_user', $editor_deleted_callback, 10 );
            wp_delete_user( $user_id );
            add_action( 'deleted_user', $editor_deleted_callback, 10, 3 );
        }
        if ( $editor_id ) {
            remove_action( 'deleted_user', $editor_deleted_callback, 10 );
            wp_delete_user( $editor_id );
            add_action( 'deleted_user', $editor_deleted_callback, 10, 3 );
        }
        if ( is_array( get_option( ActivityLogForAdmins\ScheduledReports::JOB, false ) ) ) {
            ActivityLogForAdmins\ScheduledReports::tick();
        }
        if ( false === $settings_snapshot ) {
            delete_option( ActivityLogForAdmins\Store::SETTINGS );
        } else {
            update_option( ActivityLogForAdmins\Store::SETTINGS, $settings_snapshot, false );
        }
        foreach ( [
            ActivityLogForAdmins\Store::SAVED_REPORTS => $reports_snapshot,
            ActivityLogForAdmins\ScheduledReports::SCHEDULES => $schedules_snapshot,
            ActivityLogForAdmins\ScheduledReports::JOB => $job_snapshot,
            ActivityLogForAdmins\ScheduledReports::HISTORY => $history_snapshot,
        ] as $option => $snapshot ) {
            if ( false === $snapshot ) {
                delete_option( $option );
            } else {
                update_option( $option, $snapshot, false );
            }
        }
        wp_clear_scheduled_hook( ActivityLogForAdmins\Alerts::HOOK );
        wp_clear_scheduled_hook( ActivityLogForAdmins\Alerts::RETRY_HOOK );
        foreach ( [
            ActivityLogForAdmins\Alerts::RULES => $alert_rules_snapshot,
            'alfa_alert_rule_queue' => $alert_queue_snapshot,
            'alfa_alert_retry_generation' => $alert_retry_generation_snapshot,
            'alfa_alert_queue_paused' => $alert_paused_snapshot,
        ] as $option => $snapshot ) {
            if ( false === $snapshot ) {
                delete_option( $option );
            } else {
                update_option( $option, $snapshot, false );
            }
        }
        ActivityLogForAdmins\Alerts::schedule_pending();
        $fixture_user_ids = array_filter( [ $user_id, $editor_id ] );
        if ( $fixture_user_ids ) {
            $user_placeholders = implode( ', ', array_fill( 0, count( $fixture_user_ids ), '%s' ) );
            $user_ids_as_strings = array_map( 'strval', $fixture_user_ids );
            $user_event_ids = $wpdb->get_col( $wpdb->prepare(
                'SELECT id FROM %i WHERE item_kind = %s AND item_key IN (' . $user_placeholders . ')',
                ActivityLogForAdmins\Store::table(), 'user', ...$user_ids_as_strings
            ) );
            $event_ids = array_merge( $event_ids, array_map( 'intval', (array) $user_event_ids ) );
        }
        $marker_event_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE item_key = %s', ActivityLogForAdmins\Store::table(), $marker ) );
        $event_ids = array_merge( $event_ids, array_map( 'intval', (array) $marker_event_ids ) );
        $event_ids = array_values( array_unique( array_map( 'intval', $event_ids ) ) );
        if ( $event_ids ) {
            $placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE event_id IN (' . $placeholders . ')', ActivityLogForAdmins\Store::notes_table(), ...$event_ids ) );
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (' . $placeholders . ')', ActivityLogForAdmins\Store::table(), ...$event_ids ) );
        }
        $wpdb->delete( ActivityLogForAdmins\Store::table(), [ 'item_kind' => 'page', 'item_key' => $marker ], [ '%s', '%s' ] );
    }
}
