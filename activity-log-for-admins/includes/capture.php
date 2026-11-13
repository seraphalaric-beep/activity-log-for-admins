<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Capture {
    public const ACTIONS = [
        'content_created', 'content_edited', 'content_status', 'content_trashed', 'content_restored',
        'user_created', 'user_deleted', 'role_changed', 'signed_in', 'sign_in_failed',
        'plugin_installed', 'plugin_activated', 'plugin_deactivated', 'plugin_updated', 'plugin_deleted',
        'theme_installed', 'theme_switched', 'theme_updated', 'theme_deleted', 'setting_changed',
    ];
    public const SEVERITIES = [ 'info', 'notice', 'warning' ];
    private const OPTIONS = [
        'blog_public' => 'Site visibility',
        'default_role' => 'Default role',
        'permalink_structure' => 'Permalink structure',
        'home' => 'Site address',
        'siteurl' => 'WordPress address',
    ];
    private static array $creating_users = [];
    private static array $previous_roles = [];
    private static array $bulk_updates = [ 'plugin' => [], 'theme' => [] ];

    public static function register(): void {
        add_action( 'wp_after_insert_post', [ self::class, 'post' ], 30, 4 );
        add_filter( 'insert_custom_user_meta', [ self::class, 'mark_new_user' ], 10, 3 );
        add_action( 'user_register', [ self::class, 'user_created' ] );
        add_action( 'deleted_user', [ self::class, 'user_deleted' ], 10, 3 );
        add_action( 'update_user_meta', [ self::class, 'roles_before' ], 10, 3 );
        add_action( 'updated_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'added_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'wp_login', [ self::class, 'sign_in' ], 10, 2 );
        add_action( 'wp_login_failed', [ self::class, 'failed_sign_in' ] );
        add_action( 'activated_plugin', [ self::class, 'plugin_activated' ] );
        add_action( 'deactivated_plugin', [ self::class, 'plugin_deactivated' ] );
        add_action( 'deleted_plugin', [ self::class, 'plugin_deleted' ], 10, 2 );
        add_action( 'switch_theme', [ self::class, 'theme_switched' ], 10, 3 );
        add_action( 'deleted_theme', [ self::class, 'theme_deleted' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ self::class, 'upgrade' ], 10, 2 );
        add_filter( 'upgrader_post_install', [ self::class, 'collect_update' ], PHP_INT_MAX, 2 );
        foreach ( array_keys( self::OPTIONS ) as $option ) {
            add_action( 'update_option_' . $option, [ self::class, 'setting' ], 10, 3 );
        }
    }

    private static function text( string $value, int $length = 240 ): string {
        return mb_substr( sanitize_text_field( $value ), 0, $length, 'UTF-8' );
    }

    private static function actor( ?\WP_User $user = null ): array {
        $user = $user ?? wp_get_current_user();
        if ( $user->exists() ) {
            return [ 'user', $user->ID, self::text( $user->user_login, 191 ) ];
        }
        return wp_doing_cron() ? [ 'system', 0, '' ] : [ 'unknown', 0, '' ];
    }

    private static function record(
        string $action, string $kind, int|string $key, string $name, string $summary,
        array $details = [], string $severity = 'info', ?array $actor = null
    ): void {
        [ $actor_kind, $actor_id, $actor_name ] = $actor ?? self::actor();
        Store::add( [
            'actor_kind' => $actor_kind,
            'actor_id'   => $actor_id,
            'actor_name' => $actor_name,
            'item_kind'  => $kind,
            'item_key'   => mb_substr( (string) $key, 0, 191, 'UTF-8' ),
            'item_name'  => self::text( $name, 255 ),
            'action_key' => $action,
            'severity'   => $severity,
            'summary'    => self::text( $summary, 600 ),
        ], $details );
    }

    public static function post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true )
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }
        $name = self::text( $post->post_title ) ?: __( '(Untitled)', 'activity-log-for-admins' );
        $details = [];
        if ( ! $update || ! $before || 'auto-draft' === $before->post_status ) {
            /* translators: %s: item title. */
            self::record( 'content_created', $post->post_type, $id, $name, sprintf( __( 'Created “%s”', 'activity-log-for-admins' ), $name ), [ 'changes' => [ 'Status' => [ '', $post->post_status ] ] ] );
            return;
        }
        foreach ( [
            'post_title' => 'Title', 'post_status' => 'Status', 'post_name' => 'Slug',
            'post_parent' => 'Parent ID', 'menu_order' => 'Menu order', 'post_author' => 'Author ID',
            'comment_status' => 'Comments', 'ping_status' => 'Pingbacks',
        ] as $field => $label ) {
            if ( (string) $before->$field !== (string) $post->$field ) {
                $details['changes'][ $label ] = [ self::text( (string) $before->$field ), self::text( (string) $post->$field ) ];
            }
        }
        $body_changed = $before->post_content !== $post->post_content || $before->post_excerpt !== $post->post_excerpt;
        if ( $body_changed ) {
            $details['content_changed'] = true;
            $revisions = self::revision_pair( $id, $before, $post );
            if ( $revisions ) {
                $details['revisions'] = $revisions;
            }
        }
        if ( ! $details ) {
            return;
        }

        $action = 'content_edited';
        /* translators: %s: item title. */
        $summary = sprintf( __( 'Edited “%s”', 'activity-log-for-admins' ), $name );
        if ( 'trash' === $post->post_status && 'trash' !== $before->post_status ) {
            $action = 'content_trashed';
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Moved “%s” to Trash', 'activity-log-for-admins' ), $name );
        } elseif ( 'trash' === $before->post_status && 'trash' !== $post->post_status ) {
            $action = 'content_restored';
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Restored “%s” from Trash', 'activity-log-for-admins' ), $name );
        } elseif ( isset( $details['changes']['Status'] ) ) {
            $action = 'content_status';
            $old_label = get_post_status_object( $before->post_status )->label ?? $before->post_status;
            $new_label = get_post_status_object( $post->post_status )->label ?? $post->post_status;
            /* translators: 1: item title, 2: previous status, 3: new status. */
            $summary = sprintf( __( 'Changed “%1$s” from %2$s to %3$s', 'activity-log-for-admins' ), $name, $old_label, $new_label );
        } elseif ( isset( $details['changes']['Title'] ) && '' !== $details['changes']['Title'][0] ) {
            /* translators: 1: previous title, 2: new title. */
            $summary = sprintf( __( 'Renamed “%1$s” to “%2$s”', 'activity-log-for-admins' ), $details['changes']['Title'][0], $name );
        }
        self::record( $action, $post->post_type, $id, $name, $summary, $details, 'content_edited' === $action ? 'info' : 'notice' );
    }

    private static function revision_pair( int $id, \WP_Post $before, \WP_Post $after ): array {
        $new_id = 0;
        // Only link snapshots whose core revision fields match the actual change.
        foreach ( wp_get_post_revisions( $id, [ 'posts_per_page' => 10 ] ) as $revision ) {
            if ( wp_is_post_autosave( $revision ) ) {
                continue;
            }
            if ( ! $new_id && self::matches_revision( $revision, $after ) ) {
                $new_id = $revision->ID;
            } elseif ( $new_id && self::matches_revision( $revision, $before ) ) {
                return [ $revision->ID, $new_id ];
            }
        }
        return [];
    }

    private static function matches_revision( \WP_Post $revision, \WP_Post $snapshot ): bool {
        return $revision->post_title === $snapshot->post_title
            && $revision->post_content === $snapshot->post_content
            && $revision->post_excerpt === $snapshot->post_excerpt;
    }

    public static function mark_new_user( array $meta, \WP_User $user, bool $update ): array {
        if ( ! $update ) {
            self::$creating_users[ $user->ID ] = true;
        }
        return $meta;
    }

    public static function user_created( int $id ): void {
        unset( self::$creating_users[ $id ] );
        $user = get_userdata( $id );
        if ( $user ) {
            /* translators: %s: username. */
            self::record( 'user_created', 'user', $id, $user->user_login, sprintf( __( 'Created account “%s”', 'activity-log-for-admins' ), $user->user_login ), [ 'changes' => [ 'Roles' => [ '', implode( ', ', $user->roles ) ] ] ], 'notice' );
        }
    }

    public static function user_deleted( int $id, mixed $reassign, \WP_User $user ): void {
        /* translators: %s: username. */
        self::record( 'user_deleted', 'user', $id, $user->user_login, sprintf( __( 'Deleted account “%s”', 'activity-log-for-admins' ), $user->user_login ), [], 'warning' );
    }

    public static function roles_before( int $meta_id, int $id, string $key ): void {
        if ( self::is_roles_meta( $key, $id ) ) {
            self::$previous_roles[ $meta_id ] = self::role_names( get_user_meta( $id, $key, true ) );
        }
    }

    private static function is_roles_meta( string $key, int $id ): bool {
        global $wpdb;
        return $key === $wpdb->get_blog_prefix() . 'capabilities' && ! isset( self::$creating_users[ $id ] );
    }

    private static function role_names( mixed $capabilities ): array {
        $roles = array_keys( array_intersect_key( array_filter( (array) $capabilities ), wp_roles()->roles ) );
        sort( $roles );
        return $roles;
    }

    public static function roles_after( int $meta_id, int $id, string $key, mixed $capabilities ): void {
        if ( ! self::is_roles_meta( $key, $id ) ) {
            return;
        }
        $before = self::$previous_roles[ $meta_id ] ?? [];
        unset( self::$previous_roles[ $meta_id ] );
        $after = self::role_names( $capabilities );
        $user = get_userdata( $id );
        if ( $user && $before !== $after ) {
            /* translators: %s: username. */
            self::record( 'role_changed', 'user', $id, $user->user_login, sprintf( __( 'Changed roles for “%s”', 'activity-log-for-admins' ), $user->user_login ),
                [ 'changes' => [ 'Roles' => [ self::text( implode( ', ', $before ) ), self::text( implode( ', ', $after ) ) ] ] ], 'warning' );
        }
    }

    public static function sign_in( string $login, \WP_User $user ): void {
        self::record( 'signed_in', 'user', $user->ID, $user->user_login, __( 'Signed in to WordPress', 'activity-log-for-admins' ), [], 'info', self::actor( $user ) );
    }

    public static function failed_sign_in( string $attempt ): void {
        // An arbitrary login input may contain a password or other secret.
        $user = get_user_by( 'login', $attempt ) ?: get_user_by( 'email', $attempt );
        if ( $user ) {
            /* translators: %s: username. */
            self::record( 'sign_in_failed', 'user', $user->ID, $user->user_login, sprintf( __( 'Failed sign in for “%s”', 'activity-log-for-admins' ), $user->user_login ), [], 'warning', [ 'anonymous', 0, '' ] );
            return;
        }
        self::record( 'sign_in_failed', 'login', 'unknown', __( 'WordPress sign in', 'activity-log-for-admins' ), __( 'Failed sign in for an unrecognised account', 'activity-log-for-admins' ), [], 'warning', [ 'anonymous', 0, '' ] );
    }

    public static function plugin_activated( string $file ): void {
        /* translators: %s: plugin name. */
        self::component( 'plugin_activated', 'plugin', $file, __( 'Activated plugin “%s”', 'activity-log-for-admins' ) );
    }

    public static function plugin_deactivated( string $file ): void {
        /* translators: %s: plugin name. */
        self::component( 'plugin_deactivated', 'plugin', $file, __( 'Deactivated plugin “%s”', 'activity-log-for-admins' ) );
    }

    public static function plugin_deleted( string $file, bool $deleted ): void {
        if ( $deleted ) {
            /* translators: %s: plugin identifier. */
            self::component( 'plugin_deleted', 'plugin', $file, __( 'Deleted plugin “%s”', 'activity-log-for-admins' ) );
        }
    }

    public static function theme_switched( string $name, \WP_Theme $theme, \WP_Theme $old ): void {
        /* translators: %s: theme name. */
        self::component( 'theme_switched', 'theme', $theme->get_stylesheet(), __( 'Switched to theme “%s”', 'activity-log-for-admins' ),
            [ 'changes' => [ 'Theme' => [ self::text( $old->get( 'Name' ) ), self::text( $name ) ] ] ] );
    }

    public static function theme_deleted( string $slug, bool $deleted ): void {
        if ( $deleted ) {
            /* translators: %s: theme identifier. */
            self::component( 'theme_deleted', 'theme', $slug, __( 'Deleted theme “%s”', 'activity-log-for-admins' ) );
        }
    }

    public static function collect_update( mixed $result, array $options ): mixed {
        if ( true === $result && ! isset( $options['action'] ) && isset( $options['temp_backup'] ) ) {
            foreach ( [ 'plugin', 'theme' ] as $type ) {
                if ( isset( $options[ $type ] ) && is_string( $options[ $type ] ) ) {
                    self::$bulk_updates[ $type ][ $options[ $type ] ] = true;
                }
            }
        }
        return $result;
    }

    public static function upgrade( object $upgrader, array $options ): void {
        $type = $options['type'] ?? '';
        $action = $options['action'] ?? '';
        if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! in_array( $action, [ 'install', 'update' ], true ) ) {
            return;
        }
        if ( ! empty( $options['bulk'] ) && 'update' === $action ) {
            foreach ( (array) ( $options[ $type . 's' ] ?? [] ) as $key ) {
                if ( isset( self::$bulk_updates[ $type ][ $key ] ) ) {
                    self::upgraded_component( $type, $key, 'update' );
                    unset( self::$bulk_updates[ $type ][ $key ] );
                }
            }
            return;
        }
        if ( ! is_array( $upgrader->result ?? null ) ) {
            return;
        }
        $key = $options[ $type ] ?? ( 'plugin' === $type ? $upgrader->plugin_info() : ( $upgrader->result['destination_name'] ?? '' ) );
        if ( is_string( $key ) && '' !== $key ) {
            self::upgraded_component( $type, $key, $action );
        }
    }

    private static function upgraded_component( string $type, string $key, string $action ): void {
        $messages = [
            /* translators: %s: plugin or theme name. */
            'plugin_installed' => __( 'Installed plugin “%s”', 'activity-log-for-admins' ),
            /* translators: %s: plugin or theme name. */
            'plugin_updated'   => __( 'Updated plugin “%s”', 'activity-log-for-admins' ),
            /* translators: %s: plugin or theme name. */
            'theme_installed'  => __( 'Installed theme “%s”', 'activity-log-for-admins' ),
            /* translators: %s: plugin or theme name. */
            'theme_updated'    => __( 'Updated theme “%s”', 'activity-log-for-admins' ),
        ];
        $event = $type . ( 'install' === $action ? '_installed' : '_updated' );
        self::component( $event, $type, $key, $messages[ $event ] );
    }

    private static function component( string $action, string $kind, string $key, string $format, array $details = [] ): void {
        $name = $key;
        $version = '';
        if ( 'plugin' === $kind && 0 === validate_file( $key ) && is_file( WP_PLUGIN_DIR . '/' . $key ) ) {
            $headers = get_file_data( WP_PLUGIN_DIR . '/' . $key, [ 'name' => 'Plugin Name', 'version' => 'Version' ] );
            $name = $headers['name'] ?: $key;
            $version = $headers['version'];
        } elseif ( 'theme' === $kind ) {
            $theme = wp_get_theme( $key );
            $name = $theme->get( 'Name' ) ?: $key;
            $version = $theme->get( 'Version' );
        }
        if ( $version ) {
            $details['version'] = self::text( $version, 50 );
        }
        self::record( $action, $kind, $key, $name, sprintf( $format, self::text( $name ) ), $details, 'notice' );
    }

    public static function setting( mixed $old, mixed $new, string $key ): void {
        $name = self::OPTIONS[ $key ];
        $previous = self::setting_value( $key, $old );
        $next = self::setting_value( $key, $new );
        /* translators: %s: setting name. */
        self::record( 'setting_changed', 'setting', $key, $name, sprintf( __( 'Changed setting “%s”', 'activity-log-for-admins' ), $name ),
            [ 'changes' => [ $name => [ $previous, $next ] ] ], 'notice' );
    }

    private static function setting_value( string $key, mixed $value ): string {
        if ( 'blog_public' === $key ) {
            return (int) $value ? __( 'Search indexing allowed', 'activity-log-for-admins' ) : __( 'Search indexing discouraged', 'activity-log-for-admins' );
        }
        if ( in_array( $key, [ 'home', 'siteurl' ], true ) ) {
            $url = wp_parse_url( (string) $value );
            return isset( $url['host'], $url['scheme'] ) && in_array( $url['scheme'], [ 'http', 'https' ], true )
                ? self::text( $url['scheme'] . '://' . $url['host'] . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ) . ( $url['path'] ?? '' ) )
                : '';
        }
        return self::text( (string) $value );
    }
}
