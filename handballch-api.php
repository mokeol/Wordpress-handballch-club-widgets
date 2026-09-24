<?php
/**
 * Plugin Name:       Club-Widgets für handball.ch (inoffiziell)
 * Description:       Ranglisten, Spielpläne, Resultate, Countdown und ICS-Kalender auf Basis der clubapi.handball.ch-API, als Shortcodes und Gutenberg-Blöcke. Inoffizielles Plugin, nicht mit dem Schweizerischen Handballverband (SHV) verbunden.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Albis Foxes
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       handballch-api
 *
 * Versionshistorie: CHANGELOG.md. Nach der Aktivierung einmal unter
 * Einstellungen → Permalinks "Änderungen speichern", damit /spielplan.ics greift.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Muss immer identisch zur "Version:"-Zeile oben sein (Cache-Buster für CSS/JS).
define( 'HBCH_VERSION', '1.0.2' );
define( 'HBCH_PATH', plugin_dir_path( __FILE__ ) );
define( 'HBCH_URL', plugin_dir_url( __FILE__ ) );
define( 'HBCH_OPTION', 'hbch_settings' );

require_once HBCH_PATH . 'includes/settings.php';
require_once HBCH_PATH . 'includes/colors.php';
require_once HBCH_PATH . 'includes/api.php';
require_once HBCH_PATH . 'includes/rest.php';
require_once HBCH_PATH . 'includes/shortcodes.php';
require_once HBCH_PATH . 'includes/frontend.php';
require_once HBCH_PATH . 'includes/ics.php';
require_once HBCH_PATH . 'includes/blocks.php';

// Das Adminpanel wird nur im Backend geladen.
if ( is_admin() ) {
	require_once HBCH_PATH . 'includes/admin-settings.php';
}

function hbch_activate() {
	hbch_ics_register_rewrite_rule();
	flush_rewrite_rules();

	// Täglicher Aufräum-Job für abgelaufene lokale Logo-Kopien
	// (uploads/hbch-logo-cache/), siehe hbch_cleanup_logo_cache in api.php.
	if ( ! wp_next_scheduled( 'hbch_cleanup_logo_cache' ) ) {
		wp_schedule_event( time(), 'daily', 'hbch_cleanup_logo_cache' );
	}
}
register_activation_hook( __FILE__, 'hbch_activate' );

function hbch_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'hbch_cleanup_logo_cache' );
}
register_deactivation_hook( __FILE__, 'hbch_deactivate' );

// Bestehende Installationen: Beim Update läuft der Aktivierungs-Hook nicht,
// der Aufräum-Job würde sonst erst nach erneutem Aktivieren geplant.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'hbch_cleanup_logo_cache' ) ) {
		wp_schedule_event( time(), 'daily', 'hbch_cleanup_logo_cache' );
	}
} );
