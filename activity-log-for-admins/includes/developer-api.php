<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class DeveloperAPI {
    public const API_VERSION = 1;
    private const SENSITIVE_LABEL = '/(?:password|passphrase|secret|token|auth|e.?mail|phone|mobile|address|payment|card|cookie|request|response|content|comment|message|body|url|ip|session|credential|customer)/i';

    public static function record_event( array $event ): bool {
        try {
            $allowed_keys = [ 'event_type', 'item_kind', 'item_id', 'item_name', 'summary', 'severity', 'details' ];
            if ( array_diff( array_keys( $event ), $allowed_keys ) ) {
                return false;
            }
            $action = isset( $event['event_type'] ) && is_string( $event['event_type'] ) ? sanitize_key( $event['event_type'] ) : '';
            $registered = Store::external_event_types();
            if ( '' === $action || ! isset( $registered[ $action ] ) || ! Store::action_enabled( $action ) ) {
                return false;
            }
            $kind = isset( $event['item_kind'] ) && is_string( $event['item_kind'] ) ? sanitize_key( $event['item_kind'] ) : '';
            if ( ! in_array( $kind, Store::available_item_kinds(), true ) ) {
                return false;
            }
            $severity = isset( $event['severity'] ) && is_string( $event['severity'] ) ? sanitize_key( $event['severity'] ) : 'info';
            if ( ! in_array( $severity, Capture::SEVERITIES, true ) ) {
                return false;
            }
            $item_id = $event['item_id'] ?? '';
            if ( ! is_string( $item_id ) && ! is_int( $item_id ) ) {
                return false;
            }
            $item_name = isset( $event['item_name'] ) && is_string( $event['item_name'] )
                ? self::safe_text( $event['item_name'], 255 )
                : $registered[ $action ]['label'];
            $summary = isset( $event['summary'] ) && is_string( $event['summary'] ) ? self::safe_text( $event['summary'], 600 ) : '';
            if ( '' === $summary ) {
                return false;
            }
            $details = self::safe_details( $event['details'] ?? [] );
            if ( null === $details ) {
                return false;
            }
            $context = Capture::external_event_context();
            if ( Store::should_exclude( $context['actor_kind'], $context['actor_id'], $kind, (string) $item_id, $details ) ) {
                return false;
            }
            return Store::add( [
                ...$context,
                'item_kind' => $kind,
                'item_key' => self::safe_text( (string) $item_id, 191 ),
                'item_name' => $item_name,
                'action_key' => $action,
                'severity' => $severity,
                'summary' => $summary,
            ], $details );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    private static function safe_details( mixed $details ): ?array {
        if ( ! is_array( $details ) || array_diff( array_keys( $details ), [ 'changes' ] ) ) {
            return null;
        }
        if ( ! isset( $details['changes'] ) ) {
            return [];
        }
        if ( ! is_array( $details['changes'] ) || count( $details['changes'] ) > 20 ) {
            return null;
        }
        $changes = [];
        foreach ( $details['changes'] as $label => $values ) {
            if ( ! is_string( $label ) || ! is_array( $values ) || ! array_is_list( $values ) || 2 !== count( $values )
                || preg_match( self::SENSITIVE_LABEL, $label ) ) {
                continue;
            }
            if ( ! is_scalar( $values[0] ) && null !== $values[0] || ! is_scalar( $values[1] ) && null !== $values[1] ) {
                continue;
            }
            $safe_label = self::safe_text( $label, 80 );
            if ( '' === $safe_label ) {
                continue;
            }
            $changes[ $safe_label ] = [
                self::safe_text( (string) ( $values[0] ?? '' ), 160 ),
                self::safe_text( (string) ( $values[1] ?? '' ), 160 ),
            ];
        }
        return $changes ? [ 'changes' => $changes ] : [];
    }

    private static function safe_text( string $value, int $limit ): string {
        $value = sanitize_text_field( wp_check_invalid_utf8( $value, true ) );
        $value = preg_replace( '/\b(?:https?:\/\/|www\.)[^\s<>"\']+/i', '[redacted address]', $value );
        $value = preg_replace( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[redacted email]', $value );
        $value = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted network address]', $value );
        $value = preg_replace( '/(?<![A-Z0-9])\+[1-9][0-9]{6,14}\b/i', '[redacted phone]', $value );
        $value = preg_replace( '/\b(password|passphrase|token|secret|api[_ -]?key)\b(?:\s+|\s*[:=]\s*)[^\s,;]+/i', '$1 [redacted]', $value );
        return wp_html_excerpt( trim( (string) $value ), $limit, '' );
    }
}
