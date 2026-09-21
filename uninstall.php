<?php
/**
 * uninstall.php
 *
 * Läuft nur beim endgültigen Löschen des Plugins über das WordPress-Backend
 * (nicht bei Deaktivierung): entfernt die Option, alle Transients (Präfix
 * "hbch_") und den lokalen Logo-Cache im Uploads-Ordner.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'hbch_settings' );

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_hbch_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_hbch_' ) . '%'
	)
);

$upload_dir = wp_upload_dir();
$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'hbch-logo-cache';

if ( is_dir( $cache_dir ) ) {
	$files = glob( trailingslashit( $cache_dir ) . '*' );
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
		}
	}
	@rmdir( $cache_dir );
}
