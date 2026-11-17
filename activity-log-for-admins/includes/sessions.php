<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class SessionAccess extends \WP_User_Meta_Session_Tokens {
    public function __construct( int $user_id ) {
        parent::__construct( $user_id );
    }

    public function indexed_sessions(): array {
        return $this->get_sessions();
    }

    public function revoke_verifier( string $verifier ): void {
        $this->update_session( $verifier, null );
    }
}

final class Sessions {
    public const SETTINGS = 'alfa_session_settings';
    public const CLEAR_ACTIVITY = 'alfa_session_clear_activity';
    private const CLEAR_CURSOR = 'alfa_session_clear_cursor';
    private const IDLE_STARTED = 'idle_enabled_at';
    private const POLICY_ERROR = 'alfa_session_policy_error';
    private const ACTIVITY_PREFIX = 'alfa_session_activity_';
    private const ACTIVITY_TTL = 30 * DAY_IN_SECONDS;
    private const ACTIVITY_INTERVAL = 5 * MINUTE_IN_SECONDS;
    private const PAGE_SIZE = 25;
    private const CLEANUP_BATCH = 100;
    private static array $issued_tokens = [];

    public static function register(): void {
        add_action( 'init', [ self::class, 'track_current_activity' ], 1 );
        add_action( 'set_auth_cookie', [ self::class, 'capture_issued_token' ], PHP_INT_MAX, 6 );
        add_action( 'wp_login', [ self::class, 'enforce_concurrent_limit' ], 20, 2 );
        add_filter( 'determine_current_user', [ self::class, 'enforce_idle_limit' ], 30 );
        add_action( self::CLEAR_ACTIVITY, [ self::class, 'clear_activity_batch' ] );
    }

    public static function settings(): array {
        $saved = get_option( self::SETTINGS, [] );
        $saved = is_array( $saved ) ? $saved : [];
        return [
            'track_activity' => in_array( $saved['track_activity'] ?? false, [ true, 1, '1' ], true ),
            'show_browser' => in_array( $saved['show_browser'] ?? false, [ true, 1, '1' ], true ),
            'show_ip' => in_array( $saved['show_ip'] ?? false, [ true, 1, '1' ], true ),
            'limit_enabled' => in_array( $saved['limit_enabled'] ?? false, [ true, 1, '1' ], true ),
            'max_sessions' => min( 20, max( 1, (int) ( $saved['max_sessions'] ?? 5 ) ) ),
            'idle_enabled' => in_array( $saved['idle_enabled'] ?? false, [ true, 1, '1' ], true ),
            'idle_minutes' => min( 10080, max( 5, (int) ( $saved['idle_minutes'] ?? 60 ) ) ),
            'excluded_roles' => self::normalize_roles( $saved['excluded_roles'] ?? [] ),
        ];
    }

    public static function save_settings( \WP_REST_Request $request ): array|\WP_Error {
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || array_diff( array_keys( $input ), [ 'track_activity', 'show_browser', 'show_ip', 'limit_enabled', 'max_sessions', 'idle_enabled', 'idle_minutes', 'excluded_roles' ] ) ) {
            return new \WP_Error( 'alfa_session_settings_invalid', __( 'The session settings are invalid.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        foreach ( [ 'track_activity', 'show_browser', 'show_ip', 'limit_enabled', 'idle_enabled' ] as $key ) {
            if ( ! isset( $input[ $key ] ) || ! is_bool( $input[ $key ] ) ) {
                return new \WP_Error( 'alfa_session_settings_invalid', __( 'Choose each session privacy setting before saving.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
            }
        }
        if ( ! isset( $input['max_sessions'] ) || ! is_int( $input['max_sessions'] ) || $input['max_sessions'] < 1 || $input['max_sessions'] > 20
            || ! isset( $input['idle_minutes'] ) || ! is_int( $input['idle_minutes'] ) || $input['idle_minutes'] < 5 || $input['idle_minutes'] > 10080
            || ! isset( $input['excluded_roles'] ) || ! is_array( $input['excluded_roles'] ) || ! array_is_list( $input['excluded_roles'] )
            || count( $input['excluded_roles'] ) > 20 || count( self::normalize_roles( $input['excluded_roles'] ) ) !== count( $input['excluded_roles'] ) ) {
            return new \WP_Error( 'alfa_session_settings_invalid', __( 'Check the session limits and excluded roles.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        if ( $input['idle_enabled'] && ! $input['track_activity'] ) {
            return new \WP_Error( 'alfa_session_tracking_required', __( 'Enable last activity tracking before enabling idle session expiry.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $previous = self::settings();
        $settings = [
            'track_activity' => $input['track_activity'],
            'show_browser' => $input['show_browser'],
            'show_ip' => $input['show_ip'],
            'limit_enabled' => $input['limit_enabled'],
            'max_sessions' => $input['max_sessions'],
            'idle_enabled' => $input['idle_enabled'],
            'idle_minutes' => $input['idle_minutes'],
            'excluded_roles' => self::normalize_roles( $input['excluded_roles'] ),
        ];
        $saved = $settings;
        $previous_saved = (array) get_option( self::SETTINGS, [] );
        if ( $settings['idle_enabled'] ) {
            $saved[ self::IDLE_STARTED ] = $previous['idle_enabled'] ? max( 0, (int) ( $previous_saved[ self::IDLE_STARTED ] ?? time() ) ) : time();
        }
        if ( $settings !== $previous && ! update_option( self::SETTINGS, $saved, false ) && self::settings() !== $settings ) {
            return new \WP_Error( 'alfa_session_settings_save_failed', __( 'Session settings could not be saved. Try again.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        if ( $previous['track_activity'] && ! $settings['track_activity'] ) {
            self::schedule_activity_clear();
        } elseif ( $settings['track_activity'] ) {
            wp_clear_scheduled_hook( self::CLEAR_ACTIVITY );
            delete_option( self::CLEAR_CURSOR );
        }
        if ( $settings !== $previous ) {
            Capture::session_settings_changed( $settings );
        }
        return [ 'settings' => self::settings() ];
    }

    public static function list( \WP_REST_Request $request ): array|\WP_Error {
        $page = max( 1, (int) $request->get_param( 'page' ) );
        $search = $request->get_param( 'search' );
        if ( $page > 100000 || ( null !== $search && ( ! is_string( $search ) || strlen( $search ) > 160 ) ) ) {
            return new \WP_Error( 'alfa_sessions_filter', __( 'Check the session search and page number.', 'activity-log-for-admins' ), [ 'status' => 400 ] );
        }
        $args = [
            'blog_id' => get_current_blog_id(),
            'fields' => [ 'ID', 'user_login', 'display_name' ],
            'number' => self::PAGE_SIZE,
            'offset' => ( $page - 1 ) * self::PAGE_SIZE,
            'orderby' => 'ID',
            'order' => 'ASC',
            'count_total' => true,
        ];
        if ( is_string( $search ) && '' !== trim( $search ) ) {
            $args['search'] = '*' . trim( $search ) . '*';
            $args['search_columns'] = [ 'user_login', 'display_name' ];
        }
        $query = new \WP_User_Query( $args );
        $settings = self::settings();
        $users = [];
        foreach ( $query->get_results() as $user_row ) {
            $user = get_userdata( (int) $user_row->ID );
            if ( ! $user instanceof \WP_User ) {
                continue;
            }
            $access = self::access( (int) $user->ID );
            if ( is_wp_error( $access ) ) {
                return $access;
            }
            $sessions = $access->indexed_sessions();
            $current_token = ( (int) $user->ID === get_current_user_id() && function_exists( 'wp_get_session_token' ) ) ? wp_get_session_token() : '';
            $current_verifier = '' !== $current_token ? hash( 'sha256', $current_token ) : '';
            $activity = self::activity_map( (int) $user->ID );
            $items = [];
            foreach ( $sessions as $verifier => $session ) {
                if ( ! is_array( $session ) || empty( $session['expiration'] ) ) {
                    continue;
                }
                $reference = self::reference( (string) $verifier );
                $items[] = [
                    'reference' => $reference,
                    'login' => self::format_time( (int) ( $session['login'] ?? 0 ) ),
                    'login_timestamp' => (int) ( $session['login'] ?? 0 ),
                    'expiration' => self::format_time( (int) $session['expiration'] ),
                    'last_activity' => $settings['track_activity'] ? self::format_time( (int) ( $activity[ $reference ] ?? 0 ) ) : '',
                    'browser' => $settings['show_browser'] ? self::browser_label( (string) ( $session['ua'] ?? '' ) ) : '',
                    'ip' => $settings['show_ip'] ? self::valid_ip( (string) ( $session['ip'] ?? '' ) ) : '',
                    'current' => '' !== $current_verifier && hash_equals( $current_verifier, (string) $verifier ),
                    'can_revoke' => self::can_revoke_user( (int) $user->ID ),
                ];
            }
            usort( $items, static fn( array $left, array $right ): int => $right['login_timestamp'] <=> $left['login_timestamp'] );
            $roles = array_map( static fn( string $role ): string => translate_user_role( wp_roles()->roles[ $role ]['name'] ?? $role ), (array) $user->roles );
            $users[] = [
                'id' => (int) $user->ID,
                'name' => sanitize_text_field( $user->display_name ?: $user->user_login ),
                'login' => sanitize_user( $user->user_login, true ),
                'role' => implode( ', ', $roles ),
                'sessions' => $items,
            ];
        }
        $total = (int) $query->get_total();
        return [
            'users' => $users,
            'page' => $page,
            'total_users' => $total,
            'has_more' => $page * self::PAGE_SIZE < $total,
            'settings' => $settings,
            'multisite_account_scope' => is_multisite(),
            'policy_issue' => get_option( self::POLICY_ERROR ) ? __( 'A session policy could not update a WordPress session. Check the configured session store.', 'activity-log-for-admins' ) : '',
        ];
    }

    public static function revoke_one( \WP_REST_Request $request ): array|\WP_Error {
        $user_id = absint( $request['user_id'] );
        $reference = strtolower( (string) $request['reference'] );
        if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $reference ) ) {
            return new \WP_Error( 'alfa_session_missing', __( 'The session was not found. Refresh the list and try again.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User || ! self::can_revoke_user( $user_id ) ) {
            return new \WP_Error( 'alfa_session_user_forbidden', __( 'You cannot manage sessions for this account.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }
        $access = self::access( $user_id );
        if ( is_wp_error( $access ) ) {
            return $access;
        }
        $verifier = self::find_verifier( $access->indexed_sessions(), $reference );
        if ( '' === $verifier ) {
            return new \WP_Error( 'alfa_session_missing', __( 'The session was not found. Refresh the list and try again.', 'activity-log-for-admins' ), [ 'status' => 404 ] );
        }
        $access->revoke_verifier( $verifier );
        if ( isset( $access->indexed_sessions()[ $verifier ] ) ) {
            return new \WP_Error( 'alfa_session_revoke_failed', __( 'WordPress could not end this session. Try again.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
        }
        self::delete_activity_reference( $user_id, $reference );
        Capture::session_ended( $user, 1, false );
        return [ 'ended' => 1 ];
    }

    public static function revoke_all( \WP_REST_Request $request ): array|\WP_Error {
        $user_id = absint( $request['user_id'] );
        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User || ! self::can_revoke_user( $user_id ) ) {
            return new \WP_Error( 'alfa_session_user_forbidden', __( 'You cannot manage sessions for this account.', 'activity-log-for-admins' ), [ 'status' => 403 ] );
        }
        $access = self::access( $user_id );
        if ( is_wp_error( $access ) ) {
            return $access;
        }
        $count = count( $access->indexed_sessions() );
        if ( $count > 0 ) {
            $manager = \WP_Session_Tokens::get_instance( $user_id );
            $manager->destroy_all();
            if ( $access->indexed_sessions() ) {
                return new \WP_Error( 'alfa_sessions_revoke_failed', __( 'WordPress could not end every session. Try again.', 'activity-log-for-admins' ), [ 'status' => 503 ] );
            }
            Capture::session_ended( $user, $count, true );
        }
        self::delete_activity_for_user( $user_id );
        return [ 'ended' => $count ];
    }

    public static function track_current_activity(): void {
        if ( ! self::settings()['track_activity'] || ! is_user_logged_in() || ! function_exists( 'wp_get_session_token' ) ) {
            return;
        }
        $user_id = get_current_user_id();
        $token = wp_get_session_token();
        if ( ! $user_id || '' === $token ) {
            return;
        }
        $verifier = hash( 'sha256', $token );
        $reference = self::reference( $verifier );
        $activity = self::activity_map( $user_id );
        $now = time();
        if ( $now - (int) ( $activity[ $reference ] ?? 0 ) < self::activity_interval() ) {
            return;
        }
        $activity[ $reference ] = $now;
        arsort( $activity, SORT_NUMERIC );
        $activity = array_slice( $activity, 0, 50, true );
        set_transient( self::activity_key( $user_id ), $activity, self::ACTIVITY_TTL );
    }

    public static function enforce_concurrent_limit( string $username, \WP_User $user ): void {
        $settings = self::settings();
        if ( ! $settings['limit_enabled'] || self::policy_exempt( $user ) ) {
            return;
        }
        $token = self::$issued_tokens[ (int) $user->ID ] ?? ( function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '' );
        unset( self::$issued_tokens[ (int) $user->ID ] );
        if ( '' === $token ) {
            return;
        }
        $current_verifier = hash( 'sha256', $token );
        $access = self::access( (int) $user->ID );
        if ( is_wp_error( $access ) ) {
            self::set_policy_issue();
            return;
        }
        $sessions = $access->indexed_sessions();
        if ( ! isset( $sessions[ $current_verifier ] ) || count( $sessions ) <= $settings['max_sessions'] ) {
            return;
        }
        uasort( $sessions, static function ( array $left, array $right ): int {
            $login = (int) ( $right['login'] ?? 0 ) <=> (int) ( $left['login'] ?? 0 );
            return 0 !== $login ? $login : ( (int) ( $right['expiration'] ?? 0 ) <=> (int) ( $left['expiration'] ?? 0 ) );
        } );
        $keep = [ $current_verifier => true ];
        foreach ( array_keys( $sessions ) as $verifier ) {
            if ( count( $keep ) >= $settings['max_sessions'] ) {
                break;
            }
            $keep[ (string) $verifier ] = true;
        }
        $ended = 0;
        foreach ( array_keys( $sessions ) as $verifier ) {
            if ( isset( $keep[ $verifier ] ) ) {
                continue;
            }
            $access->revoke_verifier( (string) $verifier );
            if ( ! isset( $access->indexed_sessions()[ $verifier ] ) ) {
                self::delete_activity_reference( (int) $user->ID, self::reference( (string) $verifier ) );
                ++$ended;
            }
        }
        if ( $ended > 0 ) {
            Capture::session_policy_ended( $user, $ended, 'concurrent' );
            delete_option( self::POLICY_ERROR );
        } elseif ( count( $access->indexed_sessions() ) > $settings['max_sessions'] ) {
            self::set_policy_issue();
        }
    }

    public static function capture_issued_token( string $cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void {
        if ( $user_id > 0 && 20 <= strlen( $token ) && 100 >= strlen( $token ) ) {
            self::$issued_tokens[ $user_id ] = $token;
        }
    }

    public static function enforce_idle_limit( mixed $user_id ): mixed {
        $settings = self::settings();
        if ( ! $settings['idle_enabled'] || ! is_numeric( $user_id ) || (int) $user_id < 1 ) {
            return $user_id;
        }
        $user = get_userdata( (int) $user_id );
        if ( ! $user instanceof \WP_User || self::policy_exempt( $user ) ) {
            return $user_id;
        }
        $token = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
        if ( '' === $token ) {
            return $user_id;
        }
        $verifier = hash( 'sha256', $token );
        $access = self::access( (int) $user_id );
        if ( is_wp_error( $access ) ) {
            self::set_policy_issue();
            return $user_id;
        }
        $sessions = $access->indexed_sessions();
        $session = $sessions[ $verifier ] ?? null;
        if ( ! is_array( $session ) ) {
            return $user_id;
        }
        $reference = self::reference( $verifier );
        $activity = self::activity_map( (int) $user_id );
        $stored = (array) get_option( self::SETTINGS, [] );
        $last_activity = max( (int) ( $session['login'] ?? 0 ), (int) ( $stored[ self::IDLE_STARTED ] ?? 0 ), (int) ( $activity[ $reference ] ?? 0 ) );
        $grace = self::activity_interval();
        if ( time() - $last_activity <= ( $settings['idle_minutes'] * MINUTE_IN_SECONDS ) + $grace ) {
            return $user_id;
        }
        $access->revoke_verifier( $verifier );
        if ( isset( $access->indexed_sessions()[ $verifier ] ) ) {
            self::set_policy_issue();
            return $user_id;
        }
        self::delete_activity_reference( (int) $user_id, $reference );
        Capture::session_policy_ended( $user, 1, 'idle' );
        delete_option( self::POLICY_ERROR );
        return 0;
    }

    public static function clear_activity_batch(): void {
        if ( self::settings()['track_activity'] ) {
            delete_option( self::CLEAR_CURSOR );
            return;
        }
        $offset = max( 0, (int) get_option( self::CLEAR_CURSOR, 0 ) );
        $user_ids = get_users( [
            'blog_id' => get_current_blog_id(),
            'fields' => 'ID',
            'number' => self::CLEANUP_BATCH,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC',
        ] );
        foreach ( $user_ids as $user_id ) {
            self::delete_activity_for_user( (int) $user_id );
        }
        if ( self::CLEANUP_BATCH === count( $user_ids ) ) {
            update_option( self::CLEAR_CURSOR, $offset + count( $user_ids ), false );
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CLEAR_ACTIVITY );
            return;
        }
        delete_option( self::CLEAR_CURSOR );
    }

    public static function activity_for_user( int $user_id ): int {
        return max( array_map( 'intval', array_values( self::activity_map( $user_id ) ) ) ?: [ 0 ] );
    }

    public static function erase_activity_for_user( int $user_id ): bool {
        return self::delete_activity_for_user( $user_id );
    }

    public static function erase_all_activity_for_site(): void {
        $offset = 0;
        do {
            $user_ids = get_users( [
                'blog_id' => get_current_blog_id(),
                'fields' => 'ID',
                'number' => 200,
                'offset' => $offset,
                'orderby' => 'ID',
                'order' => 'ASC',
            ] );
            foreach ( $user_ids as $user_id ) {
                self::delete_activity_for_user( (int) $user_id );
            }
            $offset += count( $user_ids );
        } while ( 200 === count( $user_ids ) );
        delete_option( self::SETTINGS );
        delete_option( self::CLEAR_CURSOR );
        wp_clear_scheduled_hook( self::CLEAR_ACTIVITY );
    }

    private static function schedule_activity_clear(): void {
        update_option( self::CLEAR_CURSOR, 0, false );
        if ( ! wp_next_scheduled( self::CLEAR_ACTIVITY ) ) {
            wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CLEAR_ACTIVITY );
        }
    }

    private static function delete_activity_for_user( int $user_id ): bool {
        return delete_transient( self::activity_key( $user_id ) );
    }

    private static function delete_activity_reference( int $user_id, string $reference ): void {
        $activity = self::activity_map( $user_id );
        unset( $activity[ $reference ] );
        if ( $activity ) {
            set_transient( self::activity_key( $user_id ), $activity, self::ACTIVITY_TTL );
            return;
        }
        self::delete_activity_for_user( $user_id );
    }

    private static function activity_map( int $user_id ): array {
        $activity = get_transient( self::activity_key( $user_id ) );
        return is_array( $activity ) ? $activity : [];
    }

    private static function activity_key( int $user_id ): string {
        return self::ACTIVITY_PREFIX . $user_id;
    }

    private static function access( int $user_id ): SessionAccess|\WP_Error {
        $manager = \WP_Session_Tokens::get_instance( $user_id );
        if ( get_class( $manager ) !== \WP_User_Meta_Session_Tokens::class ) {
            return new \WP_Error( 'alfa_sessions_manager_unsupported', __( 'This site uses a custom WordPress session store. Activity Log cannot safely manage its sessions.', 'activity-log-for-admins' ), [ 'status' => 501 ] );
        }
        return new SessionAccess( $user_id );
    }

    private static function policy_exempt( \WP_User $user ): bool {
        if ( user_can( $user, 'manage_options' ) || ( is_multisite() && is_super_admin( (int) $user->ID ) ) ) {
            return true;
        }
        $plugin_settings = Store::settings();
        if ( in_array( (int) $user->ID, $plugin_settings['manager_users'], true )
            || array_intersect( $user->roles, $plugin_settings['manager_roles'] ) ) {
            return true;
        }
        return (bool) array_intersect( $user->roles, self::settings()['excluded_roles'] );
    }

    private static function normalize_roles( mixed $roles ): array {
        if ( ! is_array( $roles ) ) {
            return [];
        }
        $available = array_keys( wp_roles()->roles );
        $roles = array_values( array_unique( array_map( 'sanitize_key', array_filter( $roles, 'is_string' ) ) ) );
        sort( $roles );
        $available_roles = $available;
        sort( $available_roles );
        return array_values( array_intersect( $available_roles, $roles ) );
    }

    private static function activity_interval(): int {
        $settings = self::settings();
        if ( ! $settings['idle_enabled'] ) {
            return self::ACTIVITY_INTERVAL;
        }
        return max( 30, min( MINUTE_IN_SECONDS, (int) floor( $settings['idle_minutes'] * MINUTE_IN_SECONDS / 4 ) ) );
    }

    private static function set_policy_issue(): void {
        update_option( self::POLICY_ERROR, time(), false );
    }

    private static function can_revoke_user( int $user_id ): bool {
        if ( is_multisite() && is_super_admin( $user_id ) && ! is_super_admin() ) {
            return false;
        }
        return current_user_can( 'edit_user', $user_id ) || ( is_multisite() && is_super_admin() );
    }

    private static function find_verifier( array $sessions, string $reference ): string {
        foreach ( $sessions as $verifier => $session ) {
            if ( is_string( $verifier ) && hash_equals( self::reference( $verifier ), $reference ) ) {
                return $verifier;
            }
        }
        return '';
    }

    private static function reference( string $verifier ): string {
        return hash_hmac( 'sha256', $verifier, wp_salt( 'auth' ) );
    }

    private static function format_time( int $timestamp ): string {
        return $timestamp > 0 ? wp_date( 'j M Y, H:i T', $timestamp ) : '';
    }

    private static function valid_ip( string $ip ): string {
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    private static function browser_label( string $user_agent ): string {
        $browser = 'Unknown browser';
        foreach ( [ '/Edg\//i' => 'Edge', '/OPR\//i' => 'Opera', '/Firefox\//i' => 'Firefox', '/Chrome\//i' => 'Chrome', '/Safari\//i' => 'Safari' ] as $pattern => $label ) {
            if ( preg_match( $pattern, $user_agent ) ) {
                $browser = $label;
                break;
            }
        }
        $platform = 'Unknown device';
        foreach ( [ '/iPad|iPhone|iPod/i' => 'iOS', '/Android/i' => 'Android', '/Windows/i' => 'Windows', '/Macintosh|Mac OS/i' => 'macOS', '/Linux/i' => 'Linux' ] as $pattern => $label ) {
            if ( preg_match( $pattern, $user_agent ) ) {
                $platform = $label;
                break;
            }
        }
        return $browser . ' on ' . $platform;
    }
}
