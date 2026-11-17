<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Alerts {
    public const HOOK = 'alfa_alert_rule_dispatch';
    public const RETRY_HOOK = 'alfa_alert_event_retry';
    public const RULES = 'alfa_alert_rules';
    public const CHANNELS = 'alfa_alert_channels';
    private const QUEUE = 'alfa_alert_rule_queue';
    private const LOCK = 'alfa_alert_rule_queue_lock';
    private const RETRY_GENERATION = 'alfa_alert_retry_generation';
    private const PAUSED = 'alfa_alert_queue_paused';
    private const MAX_RULES = 20;
    private const MAX_QUEUE = 500;
    private const BATCH_SIZE = 20;
    private const MAX_DELIVERIES_PER_RUN = 2;
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAYS = [ 300, 900 ];
    private const CAPTURE_RETRY_DELAYS = [ 30, 300, 900 ];
    private const LOCK_SECONDS = 300;
    private const FIELDS = [
        'action_key' => 'Event type',
        'severity' => 'Severity',
        'actor_kind' => 'Actor type',
        'actor_name' => 'Actor name',
        'actor_role' => 'Actor role',
        'source' => 'Request source',
        'item_kind' => 'Item type',
        'item_name' => 'Item name',
        'summary' => 'Event summary',
    ];

    public static function presets(): array {
        return [
            [ 'id' => 'failed_sign_in', 'name' => __( 'Failed sign in', 'activity-log-for-admins' ), 'logic' => 'all', 'conditions' => [ [ 'field' => 'action_key', 'operator' => 'equals', 'value' => 'sign_in_failed' ] ] ],
            [ 'id' => 'account_role_change', 'name' => __( 'Account role change', 'activity-log-for-admins' ), 'logic' => 'all', 'conditions' => [ [ 'field' => 'action_key', 'operator' => 'equals', 'value' => 'role_changed' ] ] ],
            [ 'id' => 'session_policy_action', 'name' => __( 'Automatic session policy action', 'activity-log-for-admins' ), 'logic' => 'all', 'conditions' => [ [ 'field' => 'action_key', 'operator' => 'equals', 'value' => 'session_policy_ended' ] ] ],
            [ 'id' => 'plugin_install', 'name' => __( 'Plugin installed', 'activity-log-for-admins' ), 'logic' => 'all', 'conditions' => [ [ 'field' => 'action_key', 'operator' => 'equals', 'value' => 'plugin_installed' ] ] ],
            [ 'id' => 'warning_activity', 'name' => __( 'Warning activity', 'activity-log-for-admins' ), 'logic' => 'all', 'conditions' => [ [ 'field' => 'severity', 'operator' => 'equals', 'value' => 'warning' ] ] ],
        ];
    }

    public static function fields(): array {
        $fields = [];
        foreach ( self::FIELDS as $id => $label ) {
            $fields[] = [ 'id' => $id, 'label' => $label ];
        }
        return $fields;
    }

    public static function channel_summary(): array {
        $config = self::channel_config();
        return [
            'slack_configured' => '' !== $config['slack_webhook'],
            'sms_configured' => self::sms_configured( $config ),
            'sms_recipient_count' => count( $config['sms_recipients'] ),
            'sms_sender_masked' => self::mask_phone( $config['sms_from'] ),
            'needs_reentry' => $config['needs_reentry'],
            'delivery_paused' => self::is_paused(),
        ];
    }

    public static function save_channels( array $input ): array|\WP_Error {
        $lock = self::acquire_lock();
        if ( '' === $lock ) {
            return new \WP_Error( 'alfa_alert_channel_busy', __( 'Alert messages are being processed. Try saving again shortly.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        try {
            $current = self::channel_config();
            if ( $current['needs_reentry'] ) {
                $current = self::empty_channel_config();
            }
            $next = $current;
            foreach ( [ 'clear_slack', 'clear_sms' ] as $boolean_key ) {
                if ( array_key_exists( $boolean_key, $input ) && ! is_bool( $input[ $boolean_key ] ) ) {
                    return new \WP_Error( 'alfa_alert_channel_invalid', __( 'The channel settings are invalid.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
            }
            if ( ! empty( $input['clear_slack'] ) ) {
                $next['slack_webhook'] = '';
            } elseif ( isset( $input['slack_webhook'] ) ) {
                if ( ! is_string( $input['slack_webhook'] ) ) {
                    return new \WP_Error( 'alfa_alert_slack_url', __( 'Enter a valid Slack incoming webhook URL.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                $webhook = trim( $input['slack_webhook'] );
                if ( '' !== $webhook && ! self::valid_slack_webhook( $webhook ) ) {
                    return new \WP_Error( 'alfa_alert_slack_url', __( 'Enter a valid HTTPS Slack incoming webhook URL.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                }
                if ( '' !== $webhook ) {
                    $next['slack_webhook'] = $webhook;
                }
            }
            if ( ! empty( $input['clear_sms'] ) ) {
                $next['twilio_account_sid'] = '';
                $next['twilio_auth_token'] = '';
                $next['sms_from'] = '';
                $next['sms_recipients'] = [];
            } else {
                if ( isset( $input['twilio_account_sid'] ) && '' !== trim( (string) $input['twilio_account_sid'] ) ) {
                    $sid = strtoupper( sanitize_text_field( (string) $input['twilio_account_sid'] ) );
                    if ( ! preg_match( '/\\AAC[0-9A-F]{32}\\z/', $sid ) ) {
                        return new \WP_Error( 'alfa_alert_twilio_sid', __( 'Enter a valid Twilio account SID.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                    }
                    $next['twilio_account_sid'] = $sid;
                }
                if ( isset( $input['twilio_auth_token'] ) && '' !== trim( (string) $input['twilio_auth_token'] ) ) {
                    $token = trim( (string) $input['twilio_auth_token'] );
                    if ( strlen( $token ) < 16 || strlen( $token ) > 256 || preg_match( '/[\\r\\n]/', $token ) ) {
                        return new \WP_Error( 'alfa_alert_twilio_token', __( 'Enter a valid Twilio authentication token.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                    }
                    $next['twilio_auth_token'] = $token;
                }
                if ( isset( $input['sms_from'] ) && '' !== trim( (string) $input['sms_from'] ) ) {
                    $sender = strtoupper( sanitize_text_field( trim( (string) $input['sms_from'] ) ) );
                    if ( ! preg_match( '/\\A(?:\\+[1-9][0-9]{6,14}|MG[0-9A-F]{32})\\z/', $sender ) ) {
                        return new \WP_Error( 'alfa_alert_sms_sender', __( 'Enter an E.164 phone number or a Twilio Messaging Service SID.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                    }
                    $next['sms_from'] = $sender;
                }
                if ( array_key_exists( 'sms_recipients', $input ) ) {
                    if ( ! is_array( $input['sms_recipients'] ) || ! array_is_list( $input['sms_recipients'] ) || count( $input['sms_recipients'] ) > 10 ) {
                        return new \WP_Error( 'alfa_alert_sms_recipients', __( 'Enter up to ten E.164 phone numbers.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                    }
                    $recipients = [];
                    foreach ( $input['sms_recipients'] as $recipient ) {
                        $phone = is_string( $recipient ) ? trim( $recipient ) : '';
                        if ( ! preg_match( '/\\A\\+[1-9][0-9]{6,14}\\z/', $phone ) ) {
                            return new \WP_Error( 'alfa_alert_sms_recipients', __( 'Each SMS recipient must use E.164 format, for example +353123456789.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
                        }
                        $recipients[] = $phone;
                    }
                    $next['sms_recipients'] = array_values( array_unique( $recipients ) );
                }
            }
            $encrypted = self::encrypt_channels( $next );
            if ( false === $encrypted ) {
                return new \WP_Error( 'alfa_alert_channel_crypto', __( 'Channel credentials could not be protected on this server. Check that OpenSSL is available.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            if ( ! update_option( self::CHANNELS, $encrypted, false ) && $encrypted !== get_option( self::CHANNELS, '' ) ) {
                return new \WP_Error( 'alfa_alert_channel_storage', __( 'Channel settings could not be saved.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            return self::channel_summary();
        } finally {
            self::release_lock( $lock );
        }
    }

    public static function test_channel( string $channel ): array|\WP_Error {
        $config = self::channel_config();
        if ( 'slack' === $channel ) {
            if ( '' === $config['slack_webhook'] ) {
                return new \WP_Error( 'alfa_alert_channel_missing', __( 'Configure a Slack webhook before sending a test.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
            }
            $sent = self::send_slack( $config['slack_webhook'], __( 'Test message from Activity Log for Admins.', 'activity-log-for-admins' ) );
            return $sent
                ? [ 'sent' => true, 'channel' => 'slack' ]
                : new \WP_Error( 'alfa_alert_channel_delivery', __( 'Slack did not accept the test message. Check the webhook and try again.', 'activity-log-for-admins' ), [ 'status' => 502 ] );
        }
        if ( 'sms' === $channel ) {
            if ( ! self::sms_configured( $config ) ) {
                return new \WP_Error( 'alfa_alert_channel_missing', __( 'Complete the Twilio account, sender, and recipient settings before sending a test.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
            }
            $sent = self::send_sms( $config, $config['sms_recipients'][0], __( 'Test message from Activity Log for Admins.', 'activity-log-for-admins' ) );
            return $sent
                ? [ 'sent' => true, 'channel' => 'sms' ]
                : new \WP_Error( 'alfa_alert_channel_delivery', __( 'Twilio did not accept the test message. Check the account settings and try again.', 'activity-log-for-admins' ), [ 'status' => 502 ] );
        }
        return new \WP_Error( 'alfa_alert_channel_invalid', __( 'Choose a supported notification channel.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
    }

    public static function rules(): array {
        $stored = get_option( self::RULES, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }
        $rules = [];
        foreach ( array_slice( $stored, 0, self::MAX_RULES ) as $rule ) {
            if ( is_array( $rule ) ) {
                $normalized = self::normalize_rule( $rule );
                if ( $normalized ) {
                    $rules[] = $normalized;
                }
            }
        }
        return $rules;
    }

    public static function save( array $input, string $id = '' ): array|\WP_Error {
        $name = isset( $input['name'] ) && is_string( $input['name'] ) ? wp_html_excerpt( sanitize_text_field( $input['name'] ), 80, '' ) : '';
        $logic = isset( $input['logic'] ) && is_string( $input['logic'] ) ? sanitize_key( $input['logic'] ) : '';
        $conditions = $input['conditions'] ?? null;
        $channels = $input['channels'] ?? [ 'email' ];
        $recipients = $input['recipients'] ?? [];
        $enabled = $input['enabled'] ?? true;
        if ( '' === $name || ! in_array( $logic, [ 'all', 'any' ], true )
            || ! is_array( $conditions ) || ! array_is_list( $conditions ) || count( $conditions ) < 1 || count( $conditions ) > 8
            || ! is_array( $channels ) || ! array_is_list( $channels ) || ! $channels || count( $channels ) > 3
            || count( array_filter( $channels, static fn( $channel ): bool => ! is_string( $channel ) || ! in_array( $channel, [ 'email', 'slack', 'sms' ], true ) ) ) > 0
            || count( array_unique( $channels ) ) !== count( $channels )
            || ! is_array( $recipients ) || ! array_is_list( $recipients ) || count( $recipients ) > 10
            || ( in_array( 'email', $channels, true ) && ! $recipients )
            || ! is_bool( $enabled ) ) {
            return new \WP_Error( 'alfa_alert_rule_invalid', __( 'Check the alert name, match logic, conditions, channels, and recipients.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $channel_config = self::channel_config();
        if ( in_array( 'slack', $channels, true ) && '' === $channel_config['slack_webhook'] ) {
            return new \WP_Error( 'alfa_alert_channel_missing', __( 'Configure Slack before selecting it for an alert rule.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        if ( in_array( 'sms', $channels, true ) && ! self::sms_configured( $channel_config ) ) {
            return new \WP_Error( 'alfa_alert_channel_missing', __( 'Complete the Twilio settings before selecting SMS for an alert rule.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
        }
        $clean_conditions = [];
        foreach ( $conditions as $condition ) {
            if ( ! is_array( $condition ) || ! is_string( $condition['field'] ?? null )
                || ! array_key_exists( $condition['field'], self::FIELDS ) || ! is_string( $condition['operator'] ?? null )
                || ! in_array( $condition['operator'], [ 'equals', 'contains', 'starts_with' ], true )
                || ! is_string( $condition['value'] ?? null ) || '' === trim( $condition['value'] ) || strlen( $condition['value'] ) > 120 ) {
                return new \WP_Error( 'alfa_alert_rule_condition', __( 'Each condition needs a supported event field, comparison, and value of up to 120 characters.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $clean_conditions[] = [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => sanitize_text_field( $condition['value'] ),
            ];
        }
        $clean_recipients = [];
        foreach ( $recipients as $recipient ) {
            if ( ! is_string( $recipient ) || ! is_email( $recipient ) ) {
                return new \WP_Error( 'alfa_alert_rule_recipient', __( 'Enter valid email addresses for every alert recipient.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
            $clean_recipients[] = sanitize_email( $recipient );
        }
        $clean_recipients = array_values( array_unique( $clean_recipients ) );
        if ( in_array( 'email', $channels, true ) && ! $clean_recipients ) {
            return new \WP_Error( 'alfa_alert_rule_recipient', __( 'Enter at least one alert recipient.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $lock = self::acquire_lock();
        if ( '' === $lock ) {
            return new \WP_Error( 'alfa_alert_rule_busy', __( 'Alert rules are being processed. Try saving again shortly.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        try {
            $rules = self::rules();
            if ( '' !== $id && ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) ) {
                return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
            }
            $existing_index = null;
            foreach ( $rules as $index => $rule ) {
                if ( '' !== $id && hash_equals( $rule['id'], strtolower( $id ) ) ) {
                    $existing_index = $index;
                    break;
                }
            }
            if ( '' !== $id && null === $existing_index ) {
                return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
            }
            if ( null === $existing_index && count( $rules ) >= self::MAX_RULES ) {
                return new \WP_Error( 'alfa_alert_rule_limit', __( 'A site can keep up to 20 custom alert rules.', 'activity-log-for-admins' ), [ 'status' => 409 ] );
            }
            $rule = [
                'id' => null === $existing_index ? wp_generate_uuid4() : $rules[ $existing_index ]['id'],
                'name' => $name,
                'enabled' => $enabled,
                'logic' => $logic,
                'conditions' => $clean_conditions,
                'channels' => array_values( $channels ),
                'recipients' => $clean_recipients,
            ];
            if ( null === $existing_index ) {
                $rules[] = $rule;
            } else {
                $rules[ $existing_index ] = $rule;
            }
            if ( ! update_option( self::RULES, $rules, false ) && $rules !== get_option( self::RULES, [] ) ) {
                return new \WP_Error( 'alfa_alert_rule_storage', __( 'The alert rule could not be saved.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            return $rule;
        } finally {
            self::release_lock( $lock );
        }
    }

    public static function set_enabled( string $id, bool $enabled ): bool|\WP_Error {
        $id = strtolower( $id );
        $lock = self::acquire_lock();
        if ( '' === $lock ) {
            return new \WP_Error( 'alfa_alert_rule_busy', __( 'Alert rules are being processed. Try saving again shortly.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        try {
            $rules = self::rules();
            $found = false;
            foreach ( $rules as &$rule ) {
                if ( hash_equals( $rule['id'], $id ) ) {
                    $rule['enabled'] = $enabled;
                    $found = true;
                    break;
                }
            }
            unset( $rule );
            if ( ! $found ) {
                return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
            }
            if ( ! update_option( self::RULES, $rules, false ) && $rules !== get_option( self::RULES, [] ) ) {
                return new \WP_Error( 'alfa_alert_rule_storage', __( 'The alert rule could not be updated.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            return true;
        } finally {
            self::release_lock( $lock );
        }
    }

    public static function delete( string $id ): bool|\WP_Error {
        $id = strtolower( $id );
        $lock = self::acquire_lock();
        if ( '' === $lock ) {
            return new \WP_Error( 'alfa_alert_rule_busy', __( 'Alert rules are being processed. Try deleting again shortly.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        try {
            $rules = self::rules();
            $remaining = array_values( array_filter( $rules, static fn( array $rule ): bool => ! hash_equals( $rule['id'], $id ) ) );
            if ( count( $remaining ) === count( $rules ) ) {
                return new \WP_Error( 'alfa_alert_rule_missing', __( 'The alert rule was not found.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
            }
            if ( ! update_option( self::RULES, $remaining, false ) && $remaining !== get_option( self::RULES, [] ) ) {
                return new \WP_Error( 'alfa_alert_rule_storage', __( 'The alert rule could not be deleted.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            $queue = self::queue();
            $queue = array_values( array_filter( $queue, static fn( array $entry ): bool => ! hash_equals( (string) $entry['rule_id'], $id ) ) );
            if ( ! update_option( self::QUEUE, $queue, false ) && $queue !== get_option( self::QUEUE, [] ) ) {
                self::record_failure();
                self::schedule_pending( $queue );
            }
            if ( ! $queue ) {
                wp_clear_scheduled_hook( self::HOOK );
            }
            return true;
        } finally {
            self::release_lock( $lock );
        }
    }

    public static function queue_event( int $event_id, array $event, int $retry_attempt = 0, ?int $generation = null ): void {
        $generation = null === $generation ? self::retry_generation() : $generation;
        if ( self::is_paused() || $generation !== self::retry_generation() ) {
            return;
        }
        $rules = array_values( array_filter( self::rules(), static fn( array $rule ): bool => $rule['enabled'] ) );
        if ( ! $rules ) {
            return;
        }
        $token = self::acquire_lock();
        if ( '' === $token ) {
            self::record_failure();
            self::schedule_capture_retry( $event_id, $retry_attempt + 1, $generation );
            return;
        }
        try {
            if ( self::is_paused() || $generation !== self::retry_generation() ) {
                return;
            }
            $queue = self::queue();
            $channel_config = self::channel_config();
            foreach ( $rules as $rule ) {
                if ( ! self::channels_configured( $rule, $channel_config ) ) {
                    self::record_failure();
                    continue;
                }
                if ( ! self::matches( $rule, $event ) ) {
                    continue;
                }
                $exists = (bool) array_filter( $queue, static fn( array $entry ): bool => (int) $entry['event_id'] === $event_id && hash_equals( $entry['rule_id'], $rule['id'] ) );
                if ( $exists ) {
                    continue;
                }
                if ( count( $queue ) >= self::MAX_QUEUE ) {
                    self::record_failure();
                    self::schedule_capture_retry( $event_id, $retry_attempt + 1, $generation );
                    break;
                }
                $queue[] = [
                    'event_id' => $event_id,
                    'rule_id' => $rule['id'],
                    'attempts' => 0,
                    'created_at' => time(),
                    'retry_at' => time() + 30,
                    'delivered' => [],
                    'signature' => self::delivery_signature( $rule ),
                ];
            }
            if ( ! update_option( self::QUEUE, $queue, false ) && $queue !== get_option( self::QUEUE, [] ) ) {
                self::record_failure();
                self::schedule_capture_retry( $event_id, $retry_attempt + 1, $generation );
                return;
            }
            if ( $queue ) {
                self::schedule_pending( $queue );
            }
        } finally {
            self::release_lock( $token );
        }
    }

    public static function schedule_pending( mixed $queue = null ): void {
        $queue = is_array( $queue ) ? $queue : self::queue();
        if ( ! $queue ) {
            return;
        }
        $next = min( array_map( static fn( array $entry ): int => max( time() + 30, (int) $entry['retry_at'] ), $queue ) );
        $current = wp_next_scheduled( self::HOOK );
        if ( $current && $current <= $next ) {
            return;
        }
        if ( $current ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
        $scheduled = wp_schedule_single_event( $next, self::HOOK, [], true );
        if ( false === $scheduled || is_wp_error( $scheduled ) ) {
            self::record_failure();
        } else {
            self::clear_failure();
        }
    }

    public static function deactivate_site(): void {
        wp_clear_scheduled_hook( self::HOOK );
        wp_clear_scheduled_hook( self::RETRY_HOOK );
    }

    public static function retry_event( int $event_id, int $attempt = 0, ?int $generation = null ): void {
        $generation = null === $generation ? self::retry_generation() : $generation;
        if ( self::is_paused() || $generation !== self::retry_generation() ) {
            return;
        }
        $event = self::event_for_alert( $event_id );
        if ( is_wp_error( $event ) ) {
            self::record_failure();
            self::schedule_capture_retry( $event_id, $attempt + 1, $generation );
            return;
        }
        if ( is_array( $event ) ) {
            self::queue_event( $event_id, $event, $attempt, $generation );
        }
    }

    private static function schedule_capture_retry( int $event_id, int $attempt, ?int $generation = null ): void {
        if ( $event_id < 1 || $attempt > count( self::CAPTURE_RETRY_DELAYS ) ) {
            self::record_failure();
            return;
        }
        $delay = self::CAPTURE_RETRY_DELAYS[ $attempt - 1 ];
        $generation = null === $generation ? self::retry_generation() : $generation;
        $scheduled = wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, [ $event_id, $attempt, $generation ], true );
        if ( false === $scheduled || is_wp_error( $scheduled ) ) {
            self::record_failure();
        }
    }

    public static function process_queue(): void {
        if ( self::is_paused() ) {
            return;
        }
        $token = self::acquire_lock();
        if ( '' === $token ) {
            self::record_failure();
            self::schedule_pending();
            return;
        }
        $queue = self::queue();
        $remaining = [];
        $processed = 0;
        $delivery_budget = self::MAX_DELIVERIES_PER_RUN;
        try {
            foreach ( $queue as $entry ) {
                if ( $processed >= self::BATCH_SIZE ) {
                    $remaining[] = $entry;
                    continue;
                }
                if ( (int) $entry['retry_at'] > time() ) {
                    $remaining[] = $entry;
                    continue;
                }
                if ( $delivery_budget < 1 ) {
                    $remaining[] = $entry;
                    continue;
                }
                ++$processed;
                $rule = self::find_rule( $entry['rule_id'] );
                $event = self::event_for_alert( (int) $entry['event_id'] );
                if ( is_wp_error( $event ) ) {
                    $attempts = (int) $entry['attempts'] + 1;
                    if ( $attempts >= self::MAX_ATTEMPTS ) {
                        self::record_failure();
                        continue;
                    }
                    $delay = self::RETRY_DELAYS[ min( count( self::RETRY_DELAYS ) - 1, $attempts - 1 ) ];
                    $entry['attempts'] = $attempts;
                    $entry['retry_at'] = time() + $delay;
                    $remaining[] = $entry;
                    continue;
                }
                if ( ! $rule || ! $rule['enabled'] || false === $event || ! self::matches( $rule, $event )
                    || '' === $entry['signature'] || ! hash_equals( $entry['signature'], self::delivery_signature( $rule ) ) ) {
                    continue;
                }
                $delivery = self::send( $rule, $event, $entry['delivered'], $delivery_budget );
                $delivery_budget -= $delivery['used'];
                $entry['delivered'] = $delivery['delivered'];
                if ( $delivery['sent'] ) {
                    self::clear_failure();
                    continue;
                }
                if ( $delivery['deferred'] && ! $delivery['failed'] ) {
                    $entry['retry_at'] = time() + 30;
                    $remaining[] = $entry;
                    continue;
                }
                $attempts = (int) $entry['attempts'] + 1;
                if ( $attempts >= self::MAX_ATTEMPTS ) {
                    self::record_failure();
                    continue;
                }
                $delay = self::RETRY_DELAYS[ min( count( self::RETRY_DELAYS ) - 1, $attempts - 1 ) ];
                $entry['attempts'] = $attempts;
                $entry['retry_at'] = time() + $delay;
                $remaining[] = $entry;
            }
            if ( ! update_option( self::QUEUE, $remaining, false ) && $remaining !== get_option( self::QUEUE, [] ) ) {
                self::record_failure();
            }
        } finally {
            self::release_lock( $token );
        }
        if ( $remaining ) {
            self::schedule_pending( $remaining );
        } else {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    public static function export_personal_data( string $email, ?\WP_User $user ): array {
        $identifiers = self::identifiers( $email, $user );
        $items = [];
        foreach ( self::rules() as $rule ) {
            $recipient = in_array( strtolower( $email ), array_map( 'strtolower', $rule['recipients'] ), true );
            $references = [];
            foreach ( $rule['conditions'] as $condition ) {
                if ( self::contains_identifier( $condition['value'], $identifiers ) ) {
                    $references[] = self::FIELDS[ $condition['field'] ] . ': ' . $condition['value'];
                }
            }
            $name_match = self::contains_identifier( $rule['name'], $identifiers );
            if ( ! $recipient && ! $references && ! $name_match ) {
                continue;
            }
            $items[] = [
                'item_id' => 'activity-alert-rule-' . $rule['id'],
                'group_id' => 'activity-log-alert-rules',
                'item_data' => [
                    [ 'name' => __( 'Alert rule', 'activity-log-for-admins' ), 'value' => sanitize_text_field( $rule['name'] ) ],
                    [ 'name' => __( 'Matching logic', 'activity-log-for-admins' ), 'value' => 'all' === $rule['logic'] ? __( 'Match every condition', 'activity-log-for-admins' ) : __( 'Match any condition', 'activity-log-for-admins' ) ],
                    [ 'name' => __( 'Matching references', 'activity-log-for-admins' ), 'value' => implode( '; ', $references ) ],
                    ...( $recipient ? [ [ 'name' => __( 'Recipient email', 'activity-log-for-admins' ), 'value' => $email ] ] : [] ),
                ],
            ];
        }
        return $items;
    }

    public static function erase_personal_data( string $email, ?\WP_User $user ): array {
        $identifiers = self::identifiers( $email, $user );
        $token = self::acquire_lock();
        if ( '' === $token ) {
            return [ 'removed' => false, 'error' => __( 'Pending custom alerts could not be paused. Retry the privacy erasure.', 'activity-log-for-admins' ) ];
        }
        try {
            $generation = self::retry_generation() + 1;
            if ( ! update_option( self::RETRY_GENERATION, $generation, false ) && $generation !== self::retry_generation() ) {
                return [ 'removed' => false, 'error' => __( 'Pending custom alert retries could not be invalidated. Retry the privacy erasure.', 'activity-log-for-admins' ) ];
            }
            if ( ! update_option( self::PAUSED, true, false ) && ! self::is_paused() ) {
                return [ 'removed' => false, 'error' => __( 'Pending custom alerts could not be paused. Retry the privacy erasure.', 'activity-log-for-admins' ) ];
            }
            $queue = self::queue();
            if ( $queue && ! update_option( self::QUEUE, [], false ) && [] !== get_option( self::QUEUE, [] ) ) {
                return [ 'removed' => false, 'error' => __( 'Pending custom alerts could not be cleared. Retry the privacy erasure.', 'activity-log-for-admins' ) ];
            }
            wp_clear_scheduled_hook( self::HOOK );
            wp_clear_scheduled_hook( self::RETRY_HOOK );
            $current = self::rules();
            $remaining = [];
            $changed = (bool) $queue;
            foreach ( $current as $rule ) {
                $remove = self::contains_identifier( $rule['name'], $identifiers );
                foreach ( $rule['conditions'] as $condition ) {
                    $remove = $remove || self::contains_identifier( $condition['value'], $identifiers );
                }
                $recipients = array_values( array_filter( $rule['recipients'], static fn( string $recipient ): bool => strtolower( $recipient ) !== strtolower( $email ) ) );
                if ( $recipients !== $rule['recipients'] ) {
                    $changed = true;
                }
                if ( $remove || ( in_array( 'email', $rule['channels'], true ) && ! $recipients ) ) {
                    $changed = true;
                    continue;
                }
                $rule['recipients'] = $recipients;
                $remaining[] = $rule;
            }
            if ( $changed && ! update_option( self::RULES, $remaining, false ) && $remaining !== get_option( self::RULES, [] ) ) {
                return [ 'removed' => true, 'error' => __( 'Custom alert rules could not be updated. Retry the privacy erasure.', 'activity-log-for-admins' ) ];
            }
            return [ 'removed' => $changed, 'error' => '' ];
        } finally {
            self::release_lock( $token );
        }
    }

    public static function resume_after_privacy(): true|\WP_Error {
        $token = self::acquire_lock();
        if ( '' === $token ) {
            return new \WP_Error( 'alfa_alert_privacy_busy', __( 'Custom alert delivery is still processing. Retry the privacy erasure to resume notifications safely.', 'activity-log-for-admins' ) );
        }
        try {
            if ( self::queue() ) {
                return new \WP_Error( 'alfa_alert_privacy_queue', __( 'Custom alert delivery still has pending messages. Retry the privacy erasure before resuming notifications.', 'activity-log-for-admins' ) );
            }
            if ( ! update_option( self::PAUSED, false, false ) && self::is_paused() ) {
                return new \WP_Error( 'alfa_alert_privacy_resume', __( 'Custom alert delivery could not be resumed. Retry the privacy erasure.', 'activity-log-for-admins' ) );
            }
            return true;
        } finally {
            self::release_lock( $token );
        }
    }

    private static function normalize_rule( array $rule ): ?array {
        $id = strtolower( (string) ( $rule['id'] ?? '' ) );
        $name = isset( $rule['name'] ) && is_string( $rule['name'] ) ? wp_html_excerpt( sanitize_text_field( $rule['name'] ), 80, '' ) : '';
        $logic = sanitize_key( (string) ( $rule['logic'] ?? '' ) );
        $conditions = $rule['conditions'] ?? null;
        $channels = $rule['channels'] ?? [ 'email' ];
        $recipients = $rule['recipients'] ?? null;
        if ( ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $id ) || '' === $name
            || ! in_array( $logic, [ 'all', 'any' ], true ) || ! is_array( $conditions ) || ! array_is_list( $conditions )
            || ! is_array( $channels ) || ! array_is_list( $channels ) || ! $channels || count( $channels ) > 3
            || count( array_filter( $channels, static fn( $channel ): bool => ! is_string( $channel ) || ! in_array( $channel, [ 'email', 'slack', 'sms' ], true ) ) ) > 0
            || count( array_unique( $channels ) ) !== count( $channels )
            || ! is_array( $recipients ) || ! array_is_list( $recipients ) || count( $recipients ) > 10
            || ( in_array( 'email', $channels, true ) && ! $recipients ) ) {
            return null;
        }
        $clean_conditions = [];
        foreach ( array_slice( $conditions, 0, 8 ) as $condition ) {
            if ( ! is_array( $condition ) || ! is_string( $condition['field'] ?? null ) || ! array_key_exists( $condition['field'], self::FIELDS )
                || ! in_array( $condition['operator'] ?? '', [ 'equals', 'contains', 'starts_with' ], true )
                || ! is_string( $condition['value'] ?? null ) || '' === trim( $condition['value'] ) ) {
                return null;
            }
            $clean_conditions[] = [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                'value' => sanitize_text_field( $condition['value'] ),
            ];
        }
        $clean_recipients = [];
        foreach ( array_slice( $recipients, 0, 10 ) as $recipient ) {
            if ( is_string( $recipient ) && is_email( $recipient ) ) {
                $clean_recipients[] = sanitize_email( $recipient );
            }
        }
        if ( ! $clean_conditions || ( in_array( 'email', $channels, true ) && ! $clean_recipients ) ) {
            return null;
        }
        return [
            'id' => $id,
            'name' => $name,
            'enabled' => ! array_key_exists( 'enabled', $rule ) || in_array( $rule['enabled'], [ true, 1, '1' ], true ),
            'logic' => $logic,
            'conditions' => $clean_conditions,
            'channels' => array_values( $channels ),
            'recipients' => array_values( array_unique( $clean_recipients ) ),
        ];
    }

    private static function matches( array $rule, array $event ): bool {
        $results = [];
        foreach ( $rule['conditions'] as $condition ) {
            $actual = strtolower( (string) ( $event[ $condition['field'] ] ?? '' ) );
            $expected = strtolower( $condition['value'] );
            $results[] = match ( $condition['operator'] ) {
                'equals' => $actual === $expected,
                'contains' => str_contains( $actual, $expected ),
                'starts_with' => str_starts_with( $actual, $expected ),
                default => false,
            };
        }
        return 'all' === $rule['logic'] ? ! in_array( false, $results, true ) : in_array( true, $results, true );
    }

    private static function send( array $rule, array $event, array $delivered = [], int $budget = self::MAX_DELIVERIES_PER_RUN ): array {
        $timestamp = strtotime( (string) $event['occurred_at'] . ' UTC' );
        $site_name = sanitize_text_field( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) );
        // translators: %1$s is the site name; %2$s is the alert rule name.
        $subject = sprintf( __( '[%1$s] Activity alert: %2$s', 'activity-log-for-admins' ), $site_name, $rule['name'] );
        $lines = [
            // translators: %s is the alert rule name.
            sprintf( __( 'Alert rule: %s', 'activity-log-for-admins' ), $rule['name'] ),
            // translators: %s is the event time.
            sprintf( __( 'Time: %s', 'activity-log-for-admins' ), wp_date( 'j M Y, H:i:s T', $timestamp ) ),
            // translators: %s is the event summary.
            sprintf( __( 'Event: %s', 'activity-log-for-admins' ), sanitize_text_field( (string) $event['summary'] ) ),
            // translators: %s is the actor name.
            sprintf( __( 'Actor: %s', 'activity-log-for-admins' ), sanitize_text_field( (string) $event['actor_name'] ) ),
            // translators: %s is the actor role.
            sprintf( __( 'Actor role: %s', 'activity-log-for-admins' ), sanitize_text_field( (string) $event['actor_role'] ) ),
            // translators: %s is the affected item name.
            sprintf( __( 'Affected item: %s', 'activity-log-for-admins' ), sanitize_text_field( (string) $event['item_name'] ) ),
            // translators: %s is the severity label.
            sprintf( __( 'Severity: %s', 'activity-log-for-admins' ), sanitize_text_field( (string) $event['severity'] ) ),
            __( 'Review the full event in the Activity Log in WordPress.', 'activity-log-for-admins' ),
        ];
        $delivered = array_values( array_unique( $delivered ) );
        $message = implode( "\n", $lines );
        $used = 0;
        $failed = false;
        $deferred = false;
        if ( in_array( 'email', $rule['channels'], true ) && ! in_array( 'email', $delivered, true ) ) {
            if ( $used >= $budget ) {
                $deferred = true;
            } else {
                ++$used;
                try {
                    if ( (bool) wp_mail( $rule['recipients'], $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] ) ) {
                        $delivered[] = 'email';
                    } else {
                        $failed = true;
                    }
                } catch ( \Throwable $error ) {
                    $failed = true;
                }
            }
        }
        $config = self::channel_config();
        if ( in_array( 'slack', $rule['channels'], true ) && ! in_array( 'slack', $delivered, true ) ) {
            if ( $used >= $budget ) {
                $deferred = true;
            } else {
                ++$used;
                if ( '' !== $config['slack_webhook'] && self::send_slack( $config['slack_webhook'], $message ) ) {
                    $delivered[] = 'slack';
                } else {
                    $failed = true;
                }
            }
        }
        if ( in_array( 'sms', $rule['channels'], true ) && self::sms_configured( $config ) ) {
            $sms_message = wp_html_excerpt( wp_strip_all_tags( $message ), 320, '…' );
            foreach ( $config['sms_recipients'] as $index => $recipient ) {
                $delivery_key = 'sms' . $index;
                if ( in_array( $delivery_key, $delivered, true ) ) {
                    continue;
                }
                if ( $used >= $budget ) {
                    $deferred = true;
                    break;
                }
                ++$used;
                if ( self::send_sms( $config, $recipient, $sms_message ) ) {
                    $delivered[] = $delivery_key;
                } else {
                    $failed = true;
                }
            }
        }
        $expected = [];
        foreach ( $rule['channels'] as $channel ) {
            if ( 'email' === $channel ) {
                $expected[] = 'email';
            } elseif ( 'slack' === $channel ) {
                $expected[] = 'slack';
            } elseif ( 'sms' === $channel ) {
                foreach ( array_keys( $config['sms_recipients'] ) as $index ) {
                    $expected[] = 'sms' . $index;
                }
            }
        }
        $sent = ! array_diff( $expected, $delivered );
        return [ 'sent' => $sent, 'delivered' => $delivered, 'used' => $used, 'failed' => $failed, 'deferred' => ! $sent && $deferred ];
    }

    private static function event_for_alert( int $event_id ): array|false|\WP_Error {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read only allowlisted event fields to deliver a queued alert.
        $event = $wpdb->get_row( $wpdb->prepare(
            'SELECT occurred_at, action_key, severity, actor_kind, actor_name, actor_role, source, item_kind, item_name, summary FROM %i WHERE id = %d',
            Store::table(), $event_id
        ), ARRAY_A );
        if ( $wpdb->last_error ) {
            return new \WP_Error( 'alfa_alert_event_unavailable', __( 'The event needed for a custom alert is temporarily unavailable.', 'activity-log-for-admins' ) );
        }
        return is_array( $event ) ? $event : false;
    }

    private static function empty_channel_config(): array {
        return [
            'slack_webhook' => '',
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'sms_from' => '',
            'sms_recipients' => [],
            'needs_reentry' => false,
        ];
    }

    private static function channel_config(): array {
        $stored = get_option( self::CHANNELS, '' );
        if ( ! is_string( $stored ) || '' === $stored ) {
            return self::empty_channel_config();
        }
        $config = self::decrypt_channels( $stored );
        if ( ! is_array( $config ) ) {
            return array_merge( self::empty_channel_config(), [ 'needs_reentry' => true ] );
        }
        $result = self::empty_channel_config();
        $webhook = is_string( $config['slack_webhook'] ?? null ) ? $config['slack_webhook'] : '';
        $result['slack_webhook'] = self::valid_slack_webhook( $webhook ) ? $webhook : '';
        $sid = strtoupper( (string) ( $config['twilio_account_sid'] ?? '' ) );
        $result['twilio_account_sid'] = preg_match( '/\AAC[0-9A-F]{32}\z/', $sid ) ? $sid : '';
        $token = is_string( $config['twilio_auth_token'] ?? null ) ? $config['twilio_auth_token'] : '';
        $result['twilio_auth_token'] = strlen( $token ) >= 16 && strlen( $token ) <= 256 && ! preg_match( '/[\r\n]/', $token ) ? $token : '';
        $sender = strtoupper( (string) ( $config['sms_from'] ?? '' ) );
        $result['sms_from'] = preg_match( '/\A(?:\+[1-9][0-9]{6,14}|MG[0-9A-F]{32})\z/', $sender ) ? $sender : '';
        foreach ( array_slice( (array) ( $config['sms_recipients'] ?? [] ), 0, 10 ) as $recipient ) {
            if ( is_string( $recipient ) && preg_match( '/\A\+[1-9][0-9]{6,14}\z/', $recipient ) ) {
                $result['sms_recipients'][] = $recipient;
            }
        }
        $result['sms_recipients'] = array_values( array_unique( $result['sms_recipients'] ) );
        return $result;
    }

    private static function valid_slack_webhook( string $url ): bool {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
            || ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), [ 'hooks.slack.com', 'hooks.slack-gov.com' ], true )
            || isset( $parts['port'] ) && 443 !== (int) $parts['port']
            || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        return is_string( $parts['path'] ?? null ) && (bool) preg_match( '/\A\/services\/[A-Za-z0-9]+\/[A-Za-z0-9]+\/[A-Za-z0-9]+\z/', $parts['path'] );
    }

    private static function sms_configured( array $config ): bool {
        return '' !== $config['twilio_account_sid'] && '' !== $config['twilio_auth_token'] && '' !== $config['sms_from'] && ! empty( $config['sms_recipients'] );
    }

    private static function channels_configured( array $rule, array $config ): bool {
        if ( in_array( 'slack', $rule['channels'], true ) && '' === $config['slack_webhook'] ) {
            return false;
        }
        return ! in_array( 'sms', $rule['channels'], true ) || self::sms_configured( $config );
    }

    private static function mask_phone( string $phone ): string {
        if ( '' === $phone ) {
            return '';
        }
        return str_repeat( '•', max( 0, strlen( $phone ) - 4 ) ) . substr( $phone, -4 );
    }

    private static function channel_key(): string {
        return hash_hmac( 'sha256', 'activity-log-for-admins:alert-channels:v1', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
    }

    private static function encrypt_channels( array $config ): string|false {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return false;
        }
        try {
            $iv = random_bytes( 12 );
        } catch ( \Throwable $error ) {
            return false;
        }
        $tag = '';
        $json = wp_json_encode( $config );
        if ( ! is_string( $json ) ) {
            return false;
        }
        $ciphertext = openssl_encrypt( $json, 'aes-256-gcm', self::channel_key(), OPENSSL_RAW_DATA, $iv, $tag, 'alfa-alert-channels-v1', 16 );
        return false === $ciphertext || 16 !== strlen( $tag ) ? false : 'v1:' . base64_encode( $iv . $tag . $ciphertext );
    }

    private static function decrypt_channels( string $stored ): array|false {
        if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $stored, 'v1:' ) ) {
            return false;
        }
        $raw = base64_decode( substr( $stored, 3 ), true );
        if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) {
            return false;
        }
        $plain = openssl_decrypt(
            substr( $raw, 28 ),
            'aes-256-gcm',
            self::channel_key(),
            OPENSSL_RAW_DATA,
            substr( $raw, 0, 12 ),
            substr( $raw, 12, 16 ),
            'alfa-alert-channels-v1'
        );
        if ( ! is_string( $plain ) ) {
            return false;
        }
        $config = json_decode( $plain, true );
        return is_array( $config ) ? $config : false;
    }

    private static function send_slack( string $webhook, string $message ): bool {
        if ( ! self::valid_slack_webhook( $webhook ) ) {
            return false;
        }
        try {
            $response = wp_safe_remote_post( $webhook, [
                'timeout' => 8,
                'redirection' => 0,
                'sslverify' => true,
                'limit_response_size' => 1024,
                'headers' => [ 'Content-Type' => 'application/json; charset=utf-8' ],
                'body' => wp_json_encode( [ 'text' => $message, 'mrkdwn' => false, 'link_names' => false ] ),
            ] );
        } catch ( \Throwable $error ) {
            return false;
        }
        return ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response )
            && 'ok' === trim( (string) wp_remote_retrieve_body( $response ) );
    }

    private static function send_sms( array $config, string $recipient, string $message ): bool {
        if ( ! self::sms_configured( $config ) || ! preg_match( '/\A\+[1-9][0-9]{6,14}\z/', $recipient ) ) {
            return false;
        }
        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $config['twilio_account_sid'] ) . '/Messages.json';
        $body = [
            'To' => $recipient,
            'Body' => wp_html_excerpt( wp_strip_all_tags( $message ), 320, '…' ),
        ];
        if ( str_starts_with( $config['sms_from'], 'MG' ) ) {
            $body['MessagingServiceSid'] = $config['sms_from'];
        } else {
            $body['From'] = $config['sms_from'];
        }
        try {
            $response = wp_safe_remote_post( $endpoint, [
                'timeout' => 8,
                'redirection' => 0,
                'sslverify' => true,
                'limit_response_size' => 2048,
                'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $config['twilio_account_sid'] . ':' . $config['twilio_auth_token'] ) ],
                'body' => $body,
            ] );
        } catch ( \Throwable $error ) {
            return false;
        }
        return ! is_wp_error( $response ) && 201 === wp_remote_retrieve_response_code( $response );
    }

    private static function delivery_signature( array $rule ): string {
        $config = self::channel_config();
        $parts = [
            'channels' => $rule['channels'],
            'email_recipients' => in_array( 'email', $rule['channels'], true ) ? $rule['recipients'] : [],
            'slack' => in_array( 'slack', $rule['channels'], true ) ? hash( 'sha256', $config['slack_webhook'] ) : '',
            'sms' => in_array( 'sms', $rule['channels'], true ) ? [
                hash( 'sha256', $config['twilio_account_sid'] ),
                hash( 'sha256', $config['twilio_auth_token'] ),
                $config['sms_from'],
                array_map( static fn( string $phone ): string => hash( 'sha256', $phone ), $config['sms_recipients'] ),
            ] : [],
        ];
        return hash_hmac( 'sha256', (string) wp_json_encode( $parts ), wp_salt( 'auth' ) );
    }

    private static function find_rule( string $id ): ?array {
        foreach ( self::rules() as $rule ) {
            if ( hash_equals( $rule['id'], strtolower( $id ) ) ) {
                return $rule;
            }
        }
        return null;
    }

    private static function queue(): array {
        $queue = get_option( self::QUEUE, [] );
        if ( ! is_array( $queue ) ) {
            return [];
        }
        $clean = [];
        foreach ( array_slice( $queue, 0, self::MAX_QUEUE ) as $entry ) {
            if ( ! is_array( $entry ) || ! is_numeric( $entry['event_id'] ?? null ) || (int) $entry['event_id'] < 1
                || ! is_string( $entry['rule_id'] ?? null ) || ! preg_match( '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $entry['rule_id'] ) ) {
                continue;
            }
            $clean[] = [
                'event_id' => (int) $entry['event_id'],
                'rule_id' => strtolower( $entry['rule_id'] ),
                'attempts' => min( self::MAX_ATTEMPTS - 1, max( 0, (int) ( $entry['attempts'] ?? 0 ) ) ),
                'created_at' => max( 0, (int) ( $entry['created_at'] ?? time() ) ),
                'retry_at' => max( 0, (int) ( $entry['retry_at'] ?? time() ) ),
                'delivered' => array_values( array_intersect( array_map( 'sanitize_key', array_filter( (array) ( $entry['delivered'] ?? [] ), 'is_string' ) ), [ 'email', 'slack', 'sms0', 'sms1', 'sms2', 'sms3', 'sms4', 'sms5', 'sms6', 'sms7', 'sms8', 'sms9' ] ) ),
                'signature' => is_string( $entry['signature'] ?? null ) && preg_match( '/\A[a-f0-9]{64}\z/', $entry['signature'] ) ? $entry['signature'] : '',
            ];
        }
        return $clean;
    }

    private static function acquire_lock(): string {
        try {
            $token = bin2hex( random_bytes( 16 ) );
        } catch ( \Throwable $error ) {
            return '';
        }
        $current = get_option( self::LOCK, [] );
        if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) < time() ) {
            delete_option( self::LOCK );
        }
        if ( ! add_option( self::LOCK, [ 'token' => $token, 'expires' => time() + self::LOCK_SECONDS ], '', false ) ) {
            return '';
        }
        return $token;
    }

    private static function release_lock( string $token ): void {
        $lock = get_option( self::LOCK, [] );
        if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), $token ) ) {
            delete_option( self::LOCK );
        }
    }

    private static function retry_generation(): int {
        return max( 0, (int) get_option( self::RETRY_GENERATION, 0 ) );
    }

    private static function is_paused(): bool {
        return (bool) get_option( self::PAUSED, false );
    }

    private static function record_failure(): void {
        $health = (array) get_option( Store::HEALTH, [] );
        if ( time() - (int) ( $health['alert_rule_error'] ?? 0 ) >= MINUTE_IN_SECONDS ) {
            $health['alert_rule_error'] = time();
            update_option( Store::HEALTH, $health, false );
        }
    }

    private static function clear_failure(): void {
        $health = (array) get_option( Store::HEALTH, [] );
        if ( array_key_exists( 'alert_rule_error', $health ) ) {
            unset( $health['alert_rule_error'] );
            update_option( Store::HEALTH, $health, false );
        }
    }

    private static function identifiers( string $email, ?\WP_User $user ): array {
        $identifiers = [ strtolower( $email ) ];
        if ( $user instanceof \WP_User ) {
            $identifiers = array_merge( $identifiers, [ strtolower( $user->user_login ), strtolower( $user->display_name ) ] );
        }
        return array_values( array_filter( array_unique( $identifiers ) ) );
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
}
