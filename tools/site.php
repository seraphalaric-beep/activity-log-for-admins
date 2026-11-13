<?php
// CLI only. Use on a disposable WordPress installation.
if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}
[ , $root, $zip, $url, $password ] = $argv;
define( 'WP_INSTALLING', true );
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
    wp_install( 'Activity Log Pilot', 'pilotadmin', 'admin@example.test', false, '', $password );
    update_option( 'siteurl', $url );
    update_option( 'home', $url );
    update_option( 'timezone_string', 'Europe/Dublin' );
    update_option( 'permalink_structure', '' );
}
$archive = new ZipArchive();
if ( true !== $archive->open( $zip ) || ! $archive->extractTo( WP_PLUGIN_DIR ) ) {
    throw new RuntimeException( 'Unable to install the pilot ZIP.' );
}
$archive->close();
echo wp_get_wp_version() . ' / PHP ' . PHP_VERSION . PHP_EOL;
