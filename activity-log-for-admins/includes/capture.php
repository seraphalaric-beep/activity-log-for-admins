<?php

namespace ActivityLogForAdmins;

defined( 'ABSPATH' ) || exit;

final class Capture {
    public const ACTIONS = [
        'content_created', 'content_edited', 'content_status', 'content_trashed', 'content_restored',
        'user_created', 'user_deleted', 'user_removed_from_site', 'role_changed', 'signed_in', 'sign_in_failed',
        'signed_out', 'user_profile_changed', 'password_changed',
        'term_created', 'term_edited', 'term_deleted', 'terms_changed',
        'comment_created', 'comment_edited', 'comment_status', 'comment_deleted',
        'menu_created', 'menu_updated', 'menu_deleted', 'menu_item_added', 'menu_item_updated', 'menu_item_deleted', 'widget_changed',
        'order_created', 'order_status_changed', 'order_refunded',
        'redirect_updated', 'redirect_deleted', 'redirect_enabled', 'redirect_disabled',
        'plugin_settings_changed',
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
    private const POST_META = [
        '_wp_page_template' => 'Template',
        '_thumbnail_id'     => 'Featured image',
    ];
    private static array $creating_users = [];
    private static array $deleting_users = [];
    private static array $previous_roles = [];
    private static array $previous_deleted_roles = [];
    private static array $previous_post_meta = [];
    private static array $previous_menu_urls = [];
    private static array $previous_deleted_post_meta = [];
    private static array $deleting_posts = [];
    private static array $previous_terms = [];
    private static array $previous_comment_hashes = [];
    private static array $pending_menu_items = [];
    private static array $deleting_menu_items = [];
    private static array $previous_versions = [ 'plugin' => [], 'theme' => [] ];
    private static array $bulk_updates = [ 'plugin' => [], 'theme' => [] ];

    public static function register(): void {
        add_action( 'wp_after_insert_post', [ self::class, 'post' ], 30, 4 );
        add_action( 'before_delete_post', [ self::class, 'before_delete_post' ], 10, 2 );
        add_action( 'deleted_post', [ self::class, 'deleted_post' ], 10, 2 );
        add_action( 'update_postmeta', [ self::class, 'post_meta_before' ], 10, 4 );
        add_action( 'updated_post_meta', [ self::class, 'post_meta_updated' ], 10, 4 );
        add_action( 'added_post_meta', [ self::class, 'post_meta_added' ], 10, 4 );
        add_action( 'delete_post_meta', [ self::class, 'post_meta_before_delete' ], 10, 4 );
        add_action( 'deleted_post_meta', [ self::class, 'post_meta_deleted' ], 10, 4 );
        add_action( 'created_term', [ self::class, 'term_created' ], 10, 3 );
        add_action( 'edit_terms', [ self::class, 'term_before_edit' ], 10, 3 );
        add_action( 'edited_term', [ self::class, 'term_edited' ], 10, 3 );
        add_action( 'delete_term', [ self::class, 'term_deleted' ], 10, 5 );
        add_action( 'set_object_terms', [ self::class, 'terms_assigned' ], 10, 6 );
        add_action( 'comment_post', [ self::class, 'comment_created' ], 10, 3 );
        add_filter( 'wp_update_comment_data', [ self::class, 'comment_before_edit' ], 10, 3 );
        add_action( 'edit_comment', [ self::class, 'comment_edited' ] );
        add_action( 'transition_comment_status', [ self::class, 'comment_status_changed' ], 10, 3 );
        add_action( 'deleted_comment', [ self::class, 'comment_deleted' ], 10, 2 );
        add_action( 'wp_update_nav_menu_item', [ self::class, 'menu_item_saved' ], 10, 3 );
        add_action( 'updated_option', [ self::class, 'widget_option_updated' ], 10, 3 );
        add_filter( 'insert_custom_user_meta', [ self::class, 'mark_new_user' ], 10, 3 );
        add_action( 'user_register', [ self::class, 'user_created' ] );
        add_action( 'profile_update', [ self::class, 'user_profile_updated' ], 10, 2 );
        add_action( 'password_reset', [ self::class, 'password_reset' ], 10, 1 );
        add_action( 'delete_user', [ self::class, 'mark_user_deleting' ], PHP_INT_MIN );
        add_action( 'remove_user_from_blog', [ self::class, 'mark_user_deleting' ], PHP_INT_MIN, 1 );
        add_action( 'deleted_user', [ self::class, 'user_deleted' ], 10, 3 );
        add_action( 'update_user_meta', [ self::class, 'roles_before' ], 10, 3 );
        add_action( 'delete_user_meta', [ self::class, 'roles_before_delete' ], 10, 4 );
        add_action( 'updated_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'added_user_meta', [ self::class, 'roles_after' ], 10, 4 );
        add_action( 'deleted_user_meta', [ self::class, 'roles_after_delete' ], 10, 4 );
        add_action( 'wp_login', [ self::class, 'sign_in' ], 10, 2 );
        add_action( 'wp_login_failed', [ self::class, 'failed_sign_in' ] );
        add_action( 'wp_logout', [ self::class, 'sign_out' ] );
        add_action( 'update_option_' . Store::SETTINGS, [ self::class, 'plugin_settings_changed' ], 10, 2 );
        add_action( 'added_option', [ self::class, 'plugin_settings_added' ], 10, 2 );
        add_action( 'activated_plugin', [ self::class, 'plugin_activated' ] );
        add_action( 'deactivated_plugin', [ self::class, 'plugin_deactivated' ] );
        add_action( 'deleted_plugin', [ self::class, 'plugin_deleted' ], 10, 2 );
        add_action( 'switch_theme', [ self::class, 'theme_switched' ], 10, 3 );
        add_action( 'deleted_theme', [ self::class, 'theme_deleted' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ self::class, 'upgrade' ], 10, 2 );
        add_filter( 'upgrader_post_install', [ self::class, 'collect_update' ], PHP_INT_MAX, 2 );
        add_filter( 'upgrader_pre_install', [ self::class, 'remember_previous_version' ], 10, 2 );
        add_action( 'woocommerce_new_order', [ self::class, 'order_created' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed', [ self::class, 'order_status_changed' ], 10, 3 );
        add_action( 'woocommerce_order_refunded', [ self::class, 'order_refunded' ], 10, 2 );
        add_action( 'redirection_redirect_updated', [ self::class, 'redirect_updated' ], 10, 1 );
        add_action( 'redirection_redirect_deleted', [ self::class, 'redirect_deleted' ], 10, 1 );
        add_action( 'redirection_redirect_enabled', [ self::class, 'redirect_enabled' ], 10, 1 );
        add_action( 'redirection_redirect_disabled', [ self::class, 'redirect_disabled' ], 10, 1 );
        foreach ( array_keys( self::OPTIONS ) as $option ) {
            add_action( 'update_option_' . $option, [ self::class, 'setting' ], 10, 3 );
        }
    }

    private static function text( string $value, int $length = 240 ): string {
        return self::truncate( sanitize_text_field( $value ), $length );
    }

    public static function remember_previous_version( mixed $response, array $options ): mixed {
        $single_update = 'update' === ( $options['action'] ?? '' );
        $bulk_update = ! isset( $options['action'] ) && isset( $options['temp_backup'] );
        $type = $options['type'] ?? '';
        if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
            if ( isset( $options['plugin'] ) && ! isset( $options['theme'] ) ) {
                $type = 'plugin';
            } elseif ( isset( $options['theme'] ) && ! isset( $options['plugin'] ) ) {
                $type = 'theme';
            }
        }
        if ( is_wp_error( $response ) || ( ! $single_update && ! $bulk_update )
            || ! in_array( $type, [ 'plugin', 'theme' ], true ) ) {
            return $response;
        }
        $key = $options[ $type ] ?? '';
        if ( ! is_string( $key ) || '' === $key ) {
            return $response;
        }

        if ( 'plugin' === $type && 0 === validate_file( $key ) && is_file( WP_PLUGIN_DIR . '/' . $key ) ) {
            $headers = get_file_data( WP_PLUGIN_DIR . '/' . $key, [ 'version' => 'Version' ] );
            $version = $headers['version'] ?? '';
        } elseif ( 'theme' === $type ) {
            $theme = wp_get_theme( $key );
            $version = $theme->exists() ? $theme->get( 'Version' ) : '';
        } else {
            $version = '';
        }

        if ( '' !== $version ) {
            self::$previous_versions[ $type ][ $key ] = self::text( (string) $version, 50 );
        }
        return $response;
    }

    private static function truncate( string $value, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $value, 0, $length, 'UTF-8' );
        }
        if ( 1 === preg_match( '/\A.{0,' . max( 0, $length ) . '}/us', $value, $matches ) ) {
            return $matches[0];
        }
        return '';
    }

    private static function post_date_value( \WP_Post $post ): string {
        $date = get_post_datetime( $post, 'date', 'gmt' );
        if ( ! $date ) {
            return '';
        }

        $local = $date->setTimezone( wp_timezone() );
        return self::text(
            $local->format( 'Y-m-d H:i:s' ) . ' (' . $local->getTimezone()->getName() . ', UTC' . $local->format( 'P' ) . ')',
            120
        );
    }

    private static function actor( ?\WP_User $user = null ): array {
        $user = $user ?? wp_get_current_user();
        if ( $user->exists() ) {
            return [ 'user', $user->ID, self::text( $user->user_login, 191 ) ];
        }
        if ( wp_doing_cron() ) {
            return [ 'system', 0, '' ];
        }
        if ( defined( 'WP_CLI' ) && (bool) constant( 'WP_CLI' ) ) {
            return [ 'cli', 0, __( 'WP CLI', 'activity-log-for-admins' ) ];
        }
        return [ 'unknown', 0, '' ];
    }

    private static function supports_post_type( string $post_type ): bool {
        if ( in_array( $post_type, [ 'post', 'page' ], true ) ) {
            return true;
        }
        $object = get_post_type_object( $post_type );
        return $object instanceof \WP_Post_Type
            && $object->public
            && $object->publicly_queryable
            && $object->show_ui
            && ! in_array( $post_type, [ 'attachment', 'revision', 'nav_menu_item' ], true );
    }

    private static function post_type_label( string $post_type ): string {
        $object = get_post_type_object( $post_type );
        return $object instanceof \WP_Post_Type
            ? self::text( $object->labels->singular_name ?: $object->label, 80 )
            : self::text( $post_type, 80 );
    }

    private static function supports_taxonomy( string $taxonomy ): bool {
        $object = get_taxonomy( $taxonomy );
        return $object instanceof \WP_Taxonomy && $object->public && $object->show_ui && 'nav_menu' !== $taxonomy;
    }

    private static function record(
        string $action, string $kind, int|string $key, string $name, string $summary,
        array $details = [], string $severity = 'info', ?array $actor = null
    ): void {
        if ( ! Store::action_enabled( $action ) ) {
            return;
        }
        [ $actor_kind, $actor_id, $actor_name ] = $actor ?? self::actor();
        Store::add( [
            'actor_kind' => $actor_kind,
            'actor_id'   => $actor_id,
            'actor_name' => $actor_name,
            'item_kind'  => $kind,
            'item_key'   => self::truncate( (string) $key, 191 ),
            'item_name'  => self::text( $name, 255 ),
            'action_key' => $action,
            'severity'   => $severity,
            'summary'    => self::text( $summary, 600 ),
        ], $details );
    }

    public static function post( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( 'nav_menu_item' === $post->post_type ) {
            self::nav_menu_item_saved( $id, $post, $update, $before );
            return;
        }
        if ( ! self::supports_post_type( $post->post_type )
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $id ) ) {
            return;
        }
        $name = self::text( $post->post_title ) ?: __( '(Untitled)', 'activity-log-for-admins' );
        $details = [];
        if ( ! $update || ! $before || 'auto-draft' === $before->post_status ) {
            $changes = [
                'Title'  => [ '', $name ],
                'Status' => [ '', $post->post_status ],
            ];
            if ( 'future' === $post->post_status ) {
                $changes['Publish date'] = [ '', self::post_date_value( $post ) ];
            }
            /* translators: %s: item title. */
            self::record( 'content_created', $post->post_type, $id, $name, sprintf( __( 'Created “%s”', 'activity-log-for-admins' ), $name ), [ 'changes' => $changes ] );
            return;
        }
        foreach ( [
            'post_title' => 'Title', 'post_status' => 'Status', 'post_name' => 'Slug',
            'post_parent' => 'Parent item', 'menu_order' => 'Menu order', 'post_author' => 'Author',
            'comment_status' => 'Comments', 'ping_status' => 'Pingbacks',
        ] as $field => $label ) {
            if ( (string) $before->$field !== (string) $post->$field ) {
                $details['changes'][ $label ] = [
                    self::post_field_value( $field, (string) $before->$field ),
                    self::post_field_value( $field, (string) $post->$field ),
                ];
            }
        }
        if ( $before->post_date !== $post->post_date || $before->post_date_gmt !== $post->post_date_gmt ) {
            $previous_date = self::post_date_value( $before );
            $new_date = self::post_date_value( $post );
            if ( $previous_date !== $new_date ) {
                $details['changes']['Publish date'] = [ $previous_date, $new_date ];
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
        } elseif ( isset( $details['changes']['Publish date'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the publish date for “%s”', 'activity-log-for-admins' ), $name );
        } elseif ( isset( $details['changes']['Author'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the author for “%s”', 'activity-log-for-admins' ), $name );
        } elseif ( isset( $details['changes']['Parent item'] ) ) {
            /* translators: %s: item title. */
            $summary = sprintf( __( 'Changed the parent for “%s”', 'activity-log-for-admins' ), $name );
        } elseif ( isset( $details['changes']['Title'] ) && '' !== $details['changes']['Title'][0] ) {
            /* translators: 1: previous title, 2: new title. */
            $summary = sprintf( __( 'Renamed “%1$s” to “%2$s”', 'activity-log-for-admins' ), $details['changes']['Title'][0], $name );
        }
        if ( isset( $details['changes']['Title'] ) && '' !== $details['changes']['Title'][0] ) {
            /* translators: 1: previous title, 2: new title. */
            $rename = sprintf( __( 'Renamed “%1$s” to “%2$s”', 'activity-log-for-admins' ), $details['changes']['Title'][0], $name );
            if ( $summary !== $rename ) {
                $summary = $rename . '. ' . $summary;
            }
        }
        self::record( $action, $post->post_type, $id, $name, $summary, $details, 'content_edited' === $action ? 'info' : 'notice' );
    }

    private static function nav_menu_item_saved( int $id, \WP_Post $post, bool $update, ?\WP_Post $before ): void {
        if ( in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
            return;
        }
        $changes = [];
        if ( ! $update || ! $before || 'auto-draft' === $before->post_status ) {
            $changes['Menu item'] = [ '', self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' ) ];
        } else {
            foreach ( [ 'post_title' => 'Menu item', 'post_status' => 'Status', 'menu_order' => 'Position' ] as $field => $label ) {
                $old_value = (string) $before->$field;
                $new_value = (string) $post->$field;
                if ( $old_value !== $new_value ) {
                    $changes[ $label ] = [ self::text( $old_value, 180 ), self::text( $new_value, 180 ) ];
                }
            }
        }
        if ( ! $changes ) {
            return;
        }
        self::$pending_menu_items[ $id ] = [
            'action' => ( ! $update || ! $before || 'auto-draft' === $before->post_status ) ? 'menu_item_added' : 'menu_item_updated',
            'changes' => $changes,
            'title' => self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' ),
        ];
    }

    public static function menu_item_saved( int $menu_id, int $menu_item_id, array $args ): void {
        if ( empty( self::$pending_menu_items[ $menu_item_id ] ) || $menu_id < 1 ) {
            return;
        }
        $pending = self::$pending_menu_items[ $menu_item_id ];
        unset( self::$pending_menu_items[ $menu_item_id ] );
        $menu = wp_get_nav_menu_object( $menu_id );
        $menu_name = $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'activity-log-for-admins' );
        $summary = 'menu_item_added' === $pending['action']
            ? sprintf( __( 'Added “%1$s” to navigation menu “%2$s”', 'activity-log-for-admins' ), $pending['title'], $menu_name )
            : sprintf( __( 'Updated “%1$s” in navigation menu “%2$s”', 'activity-log-for-admins' ), $pending['title'], $menu_name );
        self::record( $pending['action'], 'menu', $menu_id, $menu_name, $summary,
            [ 'changes' => $pending['changes'] ], 'menu_item_added' === $pending['action'] ? 'notice' : 'info' );
    }

    private static function post_field_value( string $field, string $value ): string {
        if ( 'post_parent' === $field ) {
            $parent_id = absint( $value );
            if ( ! $parent_id ) {
                return __( 'None', 'activity-log-for-admins' );
            }
            $parent = get_post( $parent_id );
            if ( $parent instanceof \WP_Post ) {
                $title = self::text( $parent->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' );
                return $title . ' (item ID ' . $parent_id . ')';
            }
            /* translators: %d: affected item ID. */
            return sprintf( __( 'Item ID %d', 'activity-log-for-admins' ), $parent_id );
        }

        if ( 'post_author' === $field ) {
            $author_id = absint( $value );
            if ( ! $author_id ) {
                return __( 'None', 'activity-log-for-admins' );
            }
            $author = get_userdata( $author_id );
            if ( $author ) {
                $name = self::text( $author->display_name ?: $author->user_login, 160 );
                return $name . ' (user ID ' . $author_id . ')';
            }
            /* translators: %d: affected user ID. */
            return sprintf( __( 'User ID %d', 'activity-log-for-admins' ), $author_id );
        }

        return self::text( $value );
    }

    public static function before_delete_post( int $id, \WP_Post $post ): void {
        if ( 'nav_menu_item' === $post->post_type ) {
            $menus = wp_get_object_terms( $id, 'nav_menu', [ 'fields' => 'ids' ] );
            $menu_id = ! is_wp_error( $menus ) && $menus ? absint( $menus[0] ) : 0;
            $menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
            self::$deleting_menu_items[ $id ] = [
                'menu_id' => $menu_id,
                'menu_name' => $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'activity-log-for-admins' ),
                'title' => self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' ),
            ];
            return;
        }
        if ( self::supports_post_type( $post->post_type ) ) {
            self::$deleting_posts[ $id ] = true;
        }
    }

    public static function deleted_post( int $id, \WP_Post $post ): void {
        if ( 'nav_menu_item' !== $post->post_type || empty( self::$deleting_menu_items[ $id ] ) ) {
            return;
        }
        $item = self::$deleting_menu_items[ $id ];
        unset( self::$deleting_menu_items[ $id ] );
        $menu_id = $item['menu_id'] ?: $id;
        self::record( 'menu_item_deleted', 'menu', $menu_id, $item['menu_name'],
            sprintf( __( 'Removed “%1$s” from navigation menu “%2$s”', 'activity-log-for-admins' ), $item['title'], $item['menu_name'] ),
            [ 'changes' => [ 'Menu item' => [ $item['title'], '' ] ] ], 'warning' );
    }

    public static function post_meta_before( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        $post = get_post( $post_id );
        if ( '_menu_item_url' === $meta_key && $post instanceof \WP_Post && 'nav_menu_item' === $post->post_type ) {
            self::$previous_menu_urls[ $meta_id ] = (string) get_post_meta( $post_id, $meta_key, true );
            return;
        }
        if ( self::can_capture_post_meta( $post_id, $meta_key ) ) {
            self::$previous_post_meta[ $meta_id ] = get_post_meta( $post_id, $meta_key, true );
        }
    }

    public static function post_meta_updated( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        if ( array_key_exists( $meta_id, self::$previous_menu_urls ) ) {
            $previous = self::$previous_menu_urls[ $meta_id ];
            unset( self::$previous_menu_urls[ $meta_id ] );
            self::record_menu_destination_change( $post_id, $previous, (string) get_post_meta( $post_id, $meta_key, true ) );
            return;
        }
        if ( ! array_key_exists( $meta_id, self::$previous_post_meta ) ) {
            return;
        }
        $previous = self::$previous_post_meta[ $meta_id ];
        unset( self::$previous_post_meta[ $meta_id ] );
        self::record_post_meta_change( $post_id, $meta_key, $previous, $value );
    }

    public static function post_meta_added( int $meta_id, int $post_id, string $meta_key, mixed $value ): void {
        self::record_post_meta_change( $post_id, $meta_key, '', $value );
    }

    public static function post_meta_before_delete( array $meta_ids, int $post_id, string $meta_key, mixed $value ): void {
        if ( ! isset( self::POST_META[ $meta_key ] ) ) {
            return;
        }
        foreach ( $meta_ids as $meta_id ) {
            $meta = get_metadata_by_mid( 'post', absint( $meta_id ) );
            if ( ! $meta ) {
                continue;
            }
            $target_post_id = (int) $meta->post_id;
            if (
                ( $post_id && $target_post_id !== $post_id )
                || $meta->meta_key !== $meta_key
                || ! self::can_capture_post_meta( $target_post_id, $meta_key )
            ) {
                continue;
            }
            self::$previous_deleted_post_meta[ absint( $meta_id ) ] = [
                'post_id' => $target_post_id,
                'key' => $meta_key,
                'value' => $meta->meta_value,
            ];
        }
    }

    public static function post_meta_deleted( array $meta_ids, int $post_id, string $meta_key, mixed $value ): void {
        foreach ( $meta_ids as $meta_id ) {
            $meta_id = absint( $meta_id );
            if ( ! array_key_exists( $meta_id, self::$previous_deleted_post_meta ) ) {
                continue;
            }
            $snapshot = self::$previous_deleted_post_meta[ $meta_id ];
            unset( self::$previous_deleted_post_meta[ $meta_id ] );
            self::record_post_meta_change( $snapshot['post_id'], $snapshot['key'], $snapshot['value'], '' );
        }
    }

    private static function can_capture_post_meta( int $post_id, string $meta_key ): bool {
        if (
            ! isset( self::POST_META[ $meta_key ] )
            || isset( self::$deleting_posts[ $post_id ] )
            || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
            || wp_is_post_autosave( $post_id )
        ) {
            return false;
        }
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! self::supports_post_type( $post->post_type )
            || in_array( $post->post_status, [ 'auto-draft', 'inherit' ], true ) ) {
            return false;
        }
        if ( '_wp_page_template' === $meta_key ) {
            return 'page' === $post->post_type || post_type_supports( $post->post_type, 'page-attributes' );
        }
        return post_type_supports( $post->post_type, 'thumbnail' );
    }

    private static function record_menu_destination_change( int $item_id, string $previous, string $value ): void {
        if ( $previous === $value ) {
            return;
        }
        $post = get_post( $item_id );
        if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
            return;
        }
        $menus = wp_get_object_terms( $item_id, 'nav_menu', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $menus ) || ! $menus ) {
            return;
        }
        $menu_id = absint( $menus[0] );
        $menu = wp_get_nav_menu_object( $menu_id );
        $menu_name = $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'activity-log-for-admins' );
        $title = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' );
        self::record( 'menu_item_updated', 'menu', $menu_id, $menu_name,
            sprintf( __( 'Updated the destination for “%1$s” in navigation menu “%2$s”', 'activity-log-for-admins' ), $title, $menu_name ),
            [ 'changes' => [ 'Link destination' => [ __( 'Previous destination', 'activity-log-for-admins' ), __( 'Updated destination', 'activity-log-for-admins' ) ] ] ], 'info' );
    }

    private static function record_post_meta_change( int $post_id, string $meta_key, mixed $previous, mixed $value ): void {
        if ( ! self::can_capture_post_meta( $post_id, $meta_key ) ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) {
            return;
        }
        $label = self::POST_META[ $meta_key ];
        $before = self::post_meta_value( $meta_key, $previous );
        $after = self::post_meta_value( $meta_key, $value );
        if ( $before === $after ) {
            return;
        }
        $name = self::text( $post->post_title ) ?: __( '(Untitled)', 'activity-log-for-admins' );
        $summary = sprintf(
            /* translators: 1: changed field, 2: page or post title. */
            __( 'Changed %1$s for “%2$s”', 'activity-log-for-admins' ),
            $label,
            $name
        );
        self::record(
            'content_edited',
            $post->post_type,
            $post_id,
            $name,
            $summary,
            [ 'changes' => [ $label => [ $before, $after ] ] ]
        );
    }

    private static function post_meta_value( string $meta_key, mixed $value ): string {
        if ( '_wp_page_template' === $meta_key ) {
            $template = is_scalar( $value ) ? (string) $value : '';
            return '' === $template || 'default' === $template
                ? __( 'Default', 'activity-log-for-admins' )
                : self::text( $template );
        }
        $attachment_id = is_numeric( $value ) ? absint( $value ) : 0;
        if ( ! $attachment_id ) {
            return '';
        }
        $attachment = get_post( $attachment_id );
        $title = $attachment instanceof \WP_Post && 'attachment' === $attachment->post_type
            ? self::text( $attachment->post_title )
            : '';
        if ( $title ) {
            /* translators: 1: media item title, 2: media item ID. */
            return sprintf( __( '%1$s (media item %2$d)', 'activity-log-for-admins' ), $title, $attachment_id );
        }
        /* translators: %d: media item ID. */
        return sprintf( __( 'Media item %d', 'activity-log-for-admins' ), $attachment_id );
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

    private static function taxonomy_label( string $taxonomy ): string {
        $object = get_taxonomy( $taxonomy );
        return $object instanceof \WP_Taxonomy
            ? self::text( $object->labels->singular_name ?: $object->label, 80 )
            : self::text( $taxonomy, 80 );
    }

    private static function term_value( string $taxonomy, int $term_id ): string {
        if ( ! $term_id ) {
            return __( 'None', 'activity-log-for-admins' );
        }
        $term = get_term( $term_id, $taxonomy );
        if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
            return self::text( $term->name, 180 ) . ' (term ID ' . $term_id . ')';
        }
        /* translators: %d: taxonomy term ID. */
        return sprintf( __( 'Term ID %d', 'activity-log-for-admins' ), $term_id );
    }

    public static function term_created( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            if ( 'nav_menu' === $taxonomy ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                    $name = self::text( $term->name, 180 );
                    self::record( 'menu_created', 'menu', $term_id, $name,
                        sprintf( __( 'Created navigation menu “%s”', 'activity-log-for-admins' ), $name ),
                        [ 'changes' => [ 'Name' => [ '', $name ] ] ], 'notice' );
                }
            }
            return;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
            return;
        }
        $name = self::text( $term->name, 180 );
        self::record( 'term_created', 'term', $taxonomy . ':' . $term_id, $name,
            sprintf( __( 'Created %1$s “%2$s”', 'activity-log-for-admins' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ 'Name' => [ '', $name ], 'Slug' => [ '', self::text( $term->slug, 180 ) ] ] ], 'notice' );
    }

    public static function term_before_edit( int $term_id, string $taxonomy, array $args = [] ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $term = get_term( $term_id, $taxonomy );
            if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                self::$previous_terms[ $taxonomy . ':' . $term_id ] = [
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                    'parent' => 0,
                ];
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $term = get_term( $term_id, $taxonomy );
        if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
            self::$previous_terms[ $taxonomy . ':' . $term_id ] = [
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent' => (int) $term->parent,
            ];
        }
    }

    public static function term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $key = $taxonomy . ':' . $term_id;
            $before = self::$previous_terms[ $key ] ?? null;
            unset( self::$previous_terms[ $key ] );
            $term = get_term( $term_id, $taxonomy );
            if ( ! $before || ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
                return;
            }
            $changes = [];
            foreach ( [ 'name' => 'Name', 'slug' => 'Slug' ] as $field => $label ) {
                $old_value = self::text( (string) $before[ $field ], 180 );
                $new_value = self::text( (string) $term->$field, 180 );
                if ( $old_value !== $new_value ) {
                    $changes[ $label ] = [ $old_value, $new_value ];
                }
            }
            if ( $changes ) {
                $name = self::text( $term->name, 180 );
                self::record( 'menu_updated', 'menu', $term_id, $name,
                    sprintf( __( 'Updated navigation menu “%s”', 'activity-log-for-admins' ), $name ),
                    [ 'changes' => $changes ], 'notice' );
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $key = $taxonomy . ':' . $term_id;
        $before = self::$previous_terms[ $key ] ?? null;
        unset( self::$previous_terms[ $key ] );
        $term = get_term( $term_id, $taxonomy );
        if ( ! $before || ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
            return;
        }
        $changes = [];
        foreach ( [ 'name' => 'Name', 'slug' => 'Slug' ] as $field => $label ) {
            $old_value = self::text( (string) $before[ $field ], 180 );
            $new_value = self::text( (string) $term->$field, 180 );
            if ( $old_value !== $new_value ) {
                $changes[ $label ] = [ $old_value, $new_value ];
            }
        }
        if ( (int) $before['parent'] !== (int) $term->parent ) {
            $changes['Parent'] = [ self::term_value( $taxonomy, (int) $before['parent'] ), self::term_value( $taxonomy, (int) $term->parent ) ];
        }
        if ( ! $changes ) {
            return;
        }
        $name = self::text( $term->name, 180 );
        self::record( 'term_edited', 'term', $key, $name,
            sprintf( __( 'Updated %1$s “%2$s”', 'activity-log-for-admins' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => $changes ], 'notice' );
    }

    public static function term_deleted( int $term_id, int $tt_id, string $taxonomy, ?\WP_Term $deleted_term, array $object_ids = [] ): void {
        if ( 'nav_menu' === $taxonomy ) {
            if ( $deleted_term instanceof \WP_Term ) {
                $name = self::text( $deleted_term->name, 180 );
                self::record( 'menu_deleted', 'menu', $term_id, $name,
                    sprintf( __( 'Deleted navigation menu “%s”', 'activity-log-for-admins' ), $name ),
                    [ 'changes' => [ 'Name' => [ $name, '' ] ] ], 'warning' );
            }
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) || ! $deleted_term instanceof \WP_Term ) {
            return;
        }
        $name = self::text( $deleted_term->name, 180 );
        self::record( 'term_deleted', 'term', $taxonomy . ':' . $term_id, $name,
            sprintf( __( 'Deleted %1$s “%2$s”', 'activity-log-for-admins' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ 'Name' => [ $name, '' ], 'Slug' => [ self::text( $deleted_term->slug, 180 ), '' ] ] ], 'warning' );
    }

    private static function term_names_from_taxonomy_ids( array $ids, string $taxonomy ): array {
        $names = [];
        foreach ( $ids as $id ) {
            $term = get_term_by( 'term_taxonomy_id', absint( $id ), $taxonomy );
            if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
                $names[] = self::text( $term->name, 120 );
            }
        }
        sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
        return array_values( array_unique( $names ) );
    }

    public static function terms_assigned( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
        if ( 'nav_menu' === $taxonomy ) {
            $post = get_post( $object_id );
            if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type || isset( self::$pending_menu_items[ $object_id ] ) ) {
                return;
            }
            $before = self::term_names_from_taxonomy_ids( $old_tt_ids, $taxonomy );
            $after = self::term_names_from_taxonomy_ids( $tt_ids, $taxonomy );
            if ( $before === $after ) {
                return;
            }
            $new_menu = $tt_ids ? get_term_by( 'term_taxonomy_id', absint( reset( $tt_ids ) ), $taxonomy ) : null;
            $old_menu = $old_tt_ids ? get_term_by( 'term_taxonomy_id', absint( reset( $old_tt_ids ) ), $taxonomy ) : null;
            $menu = $new_menu instanceof \WP_Term ? $new_menu : $old_menu;
            $menu_id = $menu instanceof \WP_Term ? (int) $menu->term_id : $object_id;
            $menu_name = $menu instanceof \WP_Term ? self::text( $menu->name, 180 ) : __( 'Navigation menu', 'activity-log-for-admins' );
            $title = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' );
            self::record( 'menu_item_updated', 'menu', $menu_id, $menu_name,
                sprintf( __( 'Moved “%1$s” in navigation menu “%2$s”', 'activity-log-for-admins' ), $title, $menu_name ),
                [ 'changes' => [ 'Navigation menu' => [ implode( ', ', $before ), implode( ', ', $after ) ] ] ], 'notice' );
            return;
        }
        if ( ! self::supports_taxonomy( $taxonomy ) ) {
            return;
        }
        $post = get_post( $object_id );
        if ( ! $post instanceof \WP_Post || ! self::supports_post_type( $post->post_type ) ) {
            return;
        }
        $before = self::term_names_from_taxonomy_ids( $old_tt_ids, $taxonomy );
        $after = wp_get_object_terms( $object_id, $taxonomy, [ 'fields' => 'names' ] );
        if ( is_wp_error( $after ) || ! is_array( $after ) ) {
            return;
        }
        $after = array_map( static fn( $name ) => self::text( (string) $name, 120 ), $after );
        sort( $after, SORT_NATURAL | SORT_FLAG_CASE );
        $after = array_values( array_unique( $after ) );
        if ( $before === $after ) {
            return;
        }
        $name = self::text( $post->post_title, 180 ) ?: __( '(Untitled)', 'activity-log-for-admins' );
        self::record( 'terms_changed', $post->post_type, $object_id, $name,
            sprintf( __( 'Changed %1$s for “%2$s”', 'activity-log-for-admins' ), self::taxonomy_label( $taxonomy ), $name ),
            [ 'taxonomy' => $taxonomy, 'changes' => [ self::taxonomy_label( $taxonomy ) => [ implode( ', ', $before ), implode( ', ', $after ) ] ] ], 'notice' );
    }

    private static function comment_item_name( \WP_Comment $comment ): string {
        $post = get_post( (int) $comment->comment_post_ID );
        $title = $post instanceof \WP_Post ? self::text( $post->post_title, 160 ) : '';
        return $title
            ? sprintf( __( 'Comment on “%s”', 'activity-log-for-admins' ), $title )
            : sprintf( __( 'Comment %d', 'activity-log-for-admins' ), (int) $comment->comment_ID );
    }

    private static function comment_status_label( string $status ): string {
        $labels = [
            'approved' => __( 'Approved', 'activity-log-for-admins' ),
            'unapproved' => __( 'Pending', 'activity-log-for-admins' ),
            'spam' => __( 'Spam', 'activity-log-for-admins' ),
            'trash' => __( 'Trash', 'activity-log-for-admins' ),
            'post-trashed' => __( 'Parent item trashed', 'activity-log-for-admins' ),
            'deleted' => __( 'Deleted', 'activity-log-for-admins' ),
        ];
        return $labels[ $status ] ?? self::text( $status, 40 );
    }

    public static function comment_created( int $comment_id, int|string $approved, array $comment_data = [] ): void {
        $comment = get_comment( $comment_id );
        if ( ! $comment instanceof \WP_Comment ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_created', 'comment', $comment_id, $name,
            sprintf( __( 'Added a comment on %s', 'activity-log-for-admins' ), $name ),
            [ 'changes' => [ 'Status' => [ '', self::comment_status_label( (string) wp_get_comment_status( $comment ) ) ] ] ], 'notice' );
    }

    public static function comment_before_edit( array|\WP_Error $data, array $comment, array $commentarr ): array|\WP_Error {
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $comment_id = absint( $comment['comment_ID'] ?? 0 );
        if ( $comment_id ) {
            self::$previous_comment_hashes[ $comment_id ] = hash( 'sha256', (string) ( $comment['comment_content'] ?? '' ) );
        }
        return $data;
    }

    public static function comment_edited( int $comment_id ): void {
        if ( ! isset( self::$previous_comment_hashes[ $comment_id ] ) ) {
            return;
        }
        $previous_hash = self::$previous_comment_hashes[ $comment_id ];
        unset( self::$previous_comment_hashes[ $comment_id ] );
        $comment = get_comment( $comment_id );
        if ( ! $comment instanceof \WP_Comment || hash_equals( $previous_hash, hash( 'sha256', (string) $comment->comment_content ) ) ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_edited', 'comment', $comment_id, $name,
            sprintf( __( 'Edited a comment on %s', 'activity-log-for-admins' ), $name ),
            [ 'changes' => [ 'Comment content' => [ __( 'Previous text', 'activity-log-for-admins' ), __( 'Updated text', 'activity-log-for-admins' ) ] ] ], 'info' );
    }

    public static function comment_status_changed( string $new_status, string $old_status, \WP_Comment $comment ): void {
        if ( $new_status === $old_status || 'new' === $old_status ) {
            return;
        }
        $name = self::comment_item_name( $comment );
        self::record( 'comment_status', 'comment', (int) $comment->comment_ID, $name,
            sprintf( __( 'Changed comment status on %s', 'activity-log-for-admins' ), $name ),
            [ 'changes' => [ 'Status' => [ self::comment_status_label( $old_status ), self::comment_status_label( $new_status ) ] ] ], 'notice' );
    }

    public static function comment_deleted( int $comment_id, \WP_Comment $comment ): void {
        $name = self::comment_item_name( $comment );
        self::record( 'comment_deleted', 'comment', $comment_id, $name,
            sprintf( __( 'Deleted a comment on %s', 'activity-log-for-admins' ), $name ),
            [ 'changes' => [ 'Status' => [ self::comment_status_label( (string) wp_get_comment_status( $comment ) ), __( 'Deleted', 'activity-log-for-admins' ) ] ] ], 'warning' );
    }

    public static function widget_option_updated( string $option, mixed $old_value, mixed $new_value ): void {
        if ( $old_value === $new_value ) {
            return;
        }
        if ( str_starts_with( $option, 'theme_mods_' ) && is_array( $old_value ) && is_array( $new_value ) ) {
            $before = (array) ( $old_value['nav_menu_locations'] ?? [] );
            $after = (array) ( $new_value['nav_menu_locations'] ?? [] );
            if ( $before !== $after ) {
                $menu_names = static function ( array $locations ): string {
                    $names = [];
                    foreach ( $locations as $location => $menu_id ) {
                        $menu = wp_get_nav_menu_object( absint( $menu_id ) );
                        $names[] = self::text( (string) $location, 80 ) . ': ' . ( $menu && ! is_wp_error( $menu ) ? self::text( $menu->name, 120 ) : __( 'None', 'activity-log-for-admins' ) );
                    }
                    sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
                    return implode( ', ', $names );
                };
                self::record( 'menu_updated', 'menu', 'locations', __( 'Menu locations', 'activity-log-for-admins' ),
                    __( 'Changed navigation menu locations', 'activity-log-for-admins' ),
                    [ 'changes' => [ 'Locations' => [ $menu_names( $before ), $menu_names( $after ) ] ] ], 'notice' );
            }
            return;
        }
        if ( 'sidebars_widgets' === $option ) {
            $before = self::sidebar_assignments( $old_value );
            $after = self::sidebar_assignments( $new_value );
            if ( $before === $after ) {
                return;
            }
            self::record( 'widget_changed', 'widget', $option, __( 'Widget placement', 'activity-log-for-admins' ),
                __( 'Changed widget placement', 'activity-log-for-admins' ),
                [ 'changes' => [ 'Widget placement' => [ implode( '; ', $before ), implode( '; ', $after ) ] ] ], 'notice' );
            return;
        }
        if ( ! preg_match( '/\Awidget_([a-z0-9_]+)\z/', $option, $matches ) ) {
            return;
        }
        $widget_id = $matches[1];
        $name = self::text( $widget_id, 100 );
        $factory = $GLOBALS['wp_widget_factory'] ?? null;
        if ( $factory && isset( $factory->widgets[ $widget_id ]->name ) ) {
            $name = self::text( $factory->widgets[ $widget_id ]->name, 100 );
        }
        self::record( 'widget_changed', 'widget', $option, $name,
            sprintf( __( 'Updated %s settings', 'activity-log-for-admins' ), $name ), [], 'notice' );
    }

    private static function sidebar_assignments( mixed $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        $sidebars = (array) ( $GLOBALS['wp_registered_sidebars'] ?? [] );
        $widgets = (array) ( $GLOBALS['wp_registered_widgets'] ?? [] );
        $lines = [];
        foreach ( $value as $sidebar_id => $instances ) {
            if ( ! is_array( $instances ) || 'wp_inactive_widgets' === $sidebar_id || ! $instances ) {
                continue;
            }
            $sidebar_name = self::text( (string) ( $sidebars[ $sidebar_id ]['name'] ?? $sidebar_id ), 100 );
            $widget_names = [];
            foreach ( $instances as $instance ) {
                $widget_names[] = self::text( (string) ( $widgets[ $instance]['name'] ?? $instance ), 100 );
            }
            $lines[] = $sidebar_name . ': ' . implode( ', ', $widget_names );
        }
        sort( $lines, SORT_NATURAL | SORT_FLAG_CASE );
        return $lines;
    }

    public static function user_profile_updated( int $id, \WP_User $old_user ): void {
        $user = get_userdata( $id );
        if ( ! $user ) {
            return;
        }
        $changes = [];
        foreach ( [ 'display_name' => 'Display name' ] as $field => $label ) {
            $old_value = self::text( (string) $old_user->$field, 180 );
            $new_value = self::text( (string) $user->$field, 180 );
            if ( $old_value !== $new_value ) {
                $changes[ $label ] = [ $old_value, $new_value ];
            }
        }
        if ( (string) $old_user->user_email !== (string) $user->user_email ) {
            $changes['Email address'] = [ __( 'Previous address', 'activity-log-for-admins' ), __( 'Updated address', 'activity-log-for-admins' ) ];
        }
        if ( $changes ) {
            $name = self::text( $user->user_login, 180 );
            self::record( 'user_profile_changed', 'user', $id, $name,
                sprintf( __( 'Updated profile for account “%s”', 'activity-log-for-admins' ), $name ),
                [ 'changes' => $changes ], 'notice' );
        }
        $old_hash = (string) ( $old_user->data->user_pass ?? '' );
        $new_hash = (string) ( $user->data->user_pass ?? '' );
        if ( '' !== $old_hash && '' !== $new_hash && ! hash_equals( $old_hash, $new_hash ) ) {
            self::record( 'password_changed', 'user', $id, self::text( $user->user_login, 180 ),
                sprintf( __( 'Changed password for account “%s”', 'activity-log-for-admins' ), self::text( $user->user_login, 180 ) ), [], 'warning' );
        }
    }

    public static function password_reset( \WP_User $user ): void {
        $name = self::text( $user->user_login, 180 );
        self::record( 'password_changed', 'user', $user->ID, $name,
            sprintf( __( 'Reset password for account “%s”', 'activity-log-for-admins' ), $name ), [], 'warning' );
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
        if ( is_multisite() && get_userdata( $id ) instanceof \WP_User ) {
            /* translators: %s: username. */
            self::record( 'user_removed_from_site', 'user', $id, $user->user_login, sprintf( __( 'Removed account “%s” from this site', 'activity-log-for-admins' ), $user->user_login ), [], 'warning' );
        } else {
            /* translators: %s: username. */
            self::record( 'user_deleted', 'user', $id, $user->user_login, sprintf( __( 'Deleted account “%s”', 'activity-log-for-admins' ), $user->user_login ), [], 'warning' );
        }
        unset( self::$deleting_users[ $id ] );
    }

    public static function mark_user_deleting( int $id ): void {
        self::$deleting_users[ $id ] = true;
    }

    public static function roles_before( int $meta_id, int $id, string $key ): void {
        if ( self::is_roles_meta( $key, $id ) ) {
            self::$previous_roles[ $meta_id ] = self::role_names( get_user_meta( $id, $key, true ) );
        }
    }

    private static function is_roles_meta( string $key, int $id ): bool {
        global $wpdb;
        return $key === $wpdb->get_blog_prefix() . 'capabilities'
            && ! isset( self::$creating_users[ $id ] )
            && ! isset( self::$deleting_users[ $id ] );
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
        self::record_role_change( $id, $before, $after );
    }

    public static function roles_before_delete( array $meta_ids, int $id, string $key, mixed $value ): void {
        if ( self::is_roles_meta( $key, $id ) ) {
            self::$previous_deleted_roles[ $id ] = self::role_names( get_user_meta( $id, $key, true ) );
        }
    }

    public static function roles_after_delete( array $meta_ids, int $id, string $key, mixed $value ): void {
        if ( ! self::is_roles_meta( $key, $id ) ) {
            return;
        }
        $before = self::$previous_deleted_roles[ $id ] ?? [];
        unset( self::$previous_deleted_roles[ $id ] );
        $after = self::role_names( get_user_meta( $id, $key, true ) );
        self::record_role_change( $id, $before, $after );
    }

    private static function record_role_change( int $id, array $before, array $after ): void {
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

    public static function sign_out( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        self::record( 'signed_out', 'user', $user_id, $user->user_login, __( 'Signed out of WordPress', 'activity-log-for-admins' ), [], 'info', self::actor( $user ) );
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
            $current_version = self::text( $version, 50 );
            $previous_version = in_array( $action, [ 'plugin_updated', 'theme_updated' ], true )
                ? ( self::$previous_versions[ $kind ][ $key ] ?? '' )
                : '';
            if ( in_array( $action, [ 'plugin_updated', 'theme_updated' ], true ) ) {
                unset( self::$previous_versions[ $kind ][ $key ] );
            }
            if ( '' !== $previous_version && $previous_version !== $current_version ) {
                $details['changes']['Version'] = [ $previous_version, $current_version ];
            } else {
                $details['version'] = $current_version;
            }
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

    public static function plugin_settings_changed( array $old, array $new ): void {
        $before = Store::normalize_settings( $old );
        $after = Store::normalize_settings( $new );
        $labels = [
            'retention' => 'Retention period',
            'erase' => 'Erase data on deletion',
            'woocommerce' => 'WooCommerce recording',
            'redirection' => 'Redirection recording',
            'email_alerts' => 'Warning email digests',
            'capture_content' => 'Content recording',
            'capture_accounts' => 'Account recording',
            'capture_comments' => 'Comment recording',
            'capture_taxonomies' => 'Taxonomy recording',
            'capture_appearance' => 'Appearance recording',
            'capture_components' => 'Plugin and theme recording',
            'capture_settings' => 'Site setting recording',
        ];
        $changes = [];
        foreach ( $labels as $key => $label ) {
            if ( $before[ $key ] === $after[ $key ] ) {
                continue;
            }
            $old_value = 'retention' === $key ? $before[ $key ] . ' days' : ( $before[ $key ] ? __( 'On', 'activity-log-for-admins' ) : __( 'Off', 'activity-log-for-admins' ) );
            $new_value = 'retention' === $key ? $after[ $key ] . ' days' : ( $after[ $key ] ? __( 'On', 'activity-log-for-admins' ) : __( 'Off', 'activity-log-for-admins' ) );
            $changes[ $label ] = [ (string) $old_value, (string) $new_value ];
        }
        if ( $changes ) {
            self::record( 'plugin_settings_changed', 'setting', 'activity-log-settings', __( 'Activity Log settings', 'activity-log-for-admins' ),
                __( 'Changed Activity Log settings', 'activity-log-for-admins' ), [ 'changes' => $changes ], 'notice' );
        }
    }

    public static function plugin_settings_added( string $option, mixed $value ): void {
        if ( Store::SETTINGS === $option && is_array( $value ) ) {
            self::plugin_settings_changed( [], $value );
        }
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

    public static function order_created( int $order_id ): void {
        if ( $order_id < 1 ) {
            return;
        }
        $name = sprintf( __( 'Order #%d', 'activity-log-for-admins' ), $order_id );
        self::record( 'order_created', 'order', $order_id, $name,
            sprintf( __( 'Created WooCommerce order #%d', 'activity-log-for-admins' ), $order_id ), [], 'notice' );
    }

    public static function order_status_changed( int $order_id, string $from, string $to ): void {
        if ( $order_id < 1 || $from === $to ) {
            return;
        }
        $from_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( 'wc-' . sanitize_key( $from ) ) : sanitize_key( $from );
        $to_label = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( 'wc-' . sanitize_key( $to ) ) : sanitize_key( $to );
        $name = sprintf( __( 'Order #%d', 'activity-log-for-admins' ), $order_id );
        self::record( 'order_status_changed', 'order', $order_id, $name,
            sprintf( __( 'Changed WooCommerce order #%1$d from %2$s to %3$s', 'activity-log-for-admins' ), $order_id, $from_label, $to_label ),
            [ 'changes' => [ 'Status' => [ self::text( (string) $from_label, 80 ), self::text( (string) $to_label, 80 ) ] ] ], 'notice' );
    }

    public static function order_refunded( int $order_id, int $refund_id ): void {
        if ( $order_id < 1 ) {
            return;
        }
        $name = sprintf( __( 'Order #%d', 'activity-log-for-admins' ), $order_id );
        $details = $refund_id > 0 ? [ 'refund_id' => $refund_id ] : [];
        self::record( 'order_refunded', 'order', $order_id, $name,
            sprintf( __( 'Refunded WooCommerce order #%d', 'activity-log-for-admins' ), $order_id ), $details, 'warning' );
    }

    private static function redirect_id( mixed $redirect ): int {
        if ( is_numeric( $redirect ) ) {
            return absint( $redirect );
        }
        if ( is_object( $redirect ) ) {
            foreach ( [ 'id', 'ID' ] as $property ) {
                if ( isset( $redirect->$property ) && is_numeric( $redirect->$property ) ) {
                    return absint( $redirect->$property );
                }
            }
        }
        return 0;
    }

    private static function redirect_event( string $action, mixed $redirect ): void {
        $id = self::redirect_id( $redirect );
        $name = $id ? sprintf( __( 'Redirect #%d', 'activity-log-for-admins' ), $id ) : __( 'Redirect', 'activity-log-for-admins' );
        $messages = [
            'redirect_updated' => __( 'Created or updated a Redirection rule', 'activity-log-for-admins' ),
            'redirect_deleted' => __( 'Deleted a Redirection rule', 'activity-log-for-admins' ),
            'redirect_enabled' => __( 'Enabled a Redirection rule', 'activity-log-for-admins' ),
            'redirect_disabled' => __( 'Disabled a Redirection rule', 'activity-log-for-admins' ),
        ];
        self::record( $action, 'redirect', $id ?: 'unknown', $name,
            $messages[ $action ] . ( $id ? ' #' . $id : '' ), [], 'redirect_deleted' === $action ? 'warning' : 'notice' );
    }

    public static function redirect_updated( int $id ): void {
        self::redirect_event( 'redirect_updated', $id );
    }

    public static function redirect_deleted( mixed $redirect ): void {
        self::redirect_event( 'redirect_deleted', $redirect );
    }

    public static function redirect_enabled( mixed $redirect ): void {
        self::redirect_event( 'redirect_enabled', $redirect );
    }

    public static function redirect_disabled( mixed $redirect ): void {
        self::redirect_event( 'redirect_disabled', $redirect );
    }
}
