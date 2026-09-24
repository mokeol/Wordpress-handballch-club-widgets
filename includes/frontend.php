<?php
/**
 * includes/frontend.php
 *
 * Asset-Laden (CSS und assets/js/public.js), Inline-CSS und der Filter, der
 * fremde title-Attribute von Team-Logos entfernt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamisches Inline-CSS (Frontend und Adminpanel-Vorschau): die :root-Regel
 * mit allen Farb-Variablen, danach die freien css_*-Felder, damit sie die
 * Standardregeln überschreiben können.
 */
function hbch_get_dynamic_inline_css() {
	$css = hbch_colors_root_css();

	foreach ( [ 'css_ranking', 'css_games', 'css_home', 'css_nextgame', 'css_ics' ] as $key ) {
		$part = trim( (string) hbch_get_setting( $key ) );
		if ( $part !== '' ) {
			$css .= "\n" . $part;
		}
	}

	return $css;
}

/**
 * JS-Rumpf (ohne <script>-Tags), der title-Attribute von Team-Logos entfernt.
 * Nur noch für die Live-Vorschau im Adminpanel; im Frontend erledigt das
 * assets/js/public.js. Die Hervorhebung der eigenen Mannschaft passiert
 * serverseitig (hbch_is_own_team_name() in api.php). Der Funktionsname bleibt
 * aus Kompatibilitätsgründen erhalten.
 */
function hbch_get_highlight_js_body() {
	return "document.querySelectorAll('img[class*=\"hbch-team-logo\"][title]').forEach(function (img) { img.removeAttribute('title'); });";
}

/**
 * Entfernt title-Attribute von Team-Logo-<img> (Klasse "hbch-team-logo*") im
 * fertigen Seiteninhalt. Manche SEO-Plugins ergänzen sie automatisch; der
 * Filter läuft mit der spätestmöglichen Priorität. Andere Bilder bleiben
 * unangetastet. Arbeitet ein Plugin per Output-Buffer danach, hilft nur,
 * dessen Bild-Titel-Funktion abzuschalten (public.js ist ein Fallback).
 */
function hbch_strip_logo_title_attributes( $content ) {
	if ( strpos( $content, 'hbch-team-logo' ) === false ) {
		return $content;
	}

	return preg_replace_callback(
		'/<img\b[^>]*\bclass=(["\'])[^"\']*hbch-team-logo[^"\']*\1[^>]*>/i',
		function ( $m ) {
			return preg_replace( '/\s+title=(["\']).*?\1/is', '', $m[0] );
		},
		$content
	);
}
add_filter( 'the_content', 'hbch_strip_logo_title_attributes', PHP_INT_MAX );

/**
 * Enthält ein post_content-String einen Shortcode oder Block des Plugins?
 * Die Blocknamen müssen zu blocks.php passen (fünf Blöcke plus die alten,
 * inzwischen zusammengelegten Blocknamen aus hbch_legacy_block_map()).
 */
function hbch_content_uses_plugin( $content ) {
	if ( $content === '' ) {
		return false;
	}

	static $shortcodes = [
		'hbch_ranking', 'hbch_team_ranking', 'hbch_team_next_games', 'hbch_team_last_games',
		'hbch_home_next_games', 'hbch_home_last_games', 'hbch_next_game', 'hbch_ics',
	];
	$blocks = array_merge(
		[
			'handballch/ranking', 'handballch/team-games', 'handballch/next-game',
			'handballch/home-games', 'handballch/ics-subscribe',
		],
		array_keys( hbch_legacy_block_map() )
	);

	foreach ( $shortcodes as $sc ) {
		if ( has_shortcode( $content, $sc ) ) {
			return true;
		}
	}
	foreach ( $blocks as $b ) {
		if ( strpos( $content, 'wp:' . $b ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * Nutzt die aktuelle Seite das Plugin? Geprüft werden Seiteninhalt,
 * eingebundene wiederverwendbare Blöcke (wp:block-Referenzen) und Widgets.
 * Steht ein Shortcode woanders (Theme-Template, Page-Builder), erzwingt
 *   add_filter( 'hbch_force_assets', '__return_true' );
 * das Laden auf jeder Seite.
 */
function hbch_page_uses_plugin() {
	static $result = null;
	if ( $result !== null ) {
		return $result;
	}

	if ( apply_filters( 'hbch_force_assets', false ) ) {
		return $result = true;
	}

	if ( is_singular() ) {
		$post = get_post();
		if ( $post ) {
			if ( hbch_content_uses_plugin( $post->post_content ) ) {
				return $result = true;
			}

			if ( preg_match_all( '/<!--\s*wp:block\s+(\{[^}]*\})\s*\/?-->/', $post->post_content, $matches ) ) {
				foreach ( $matches[1] as $json ) {
					$attrs  = json_decode( $json, true );
					$ref_id = isset( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
					if ( ! $ref_id ) {
						continue;
					}
					$ref_post = get_post( $ref_id );
					if ( $ref_post && hbch_content_uses_plugin( $ref_post->post_content ) ) {
						return $result = true;
					}
				}
			}
		}
	}

	foreach ( [ 'widget_text', 'widget_custom_html', 'widget_block' ] as $widget_option ) {
		$instances = get_option( $widget_option );
		if ( ! is_array( $instances ) ) {
			continue;
		}
		foreach ( $instances as $instance ) {
			$content = is_array( $instance ) ? ( $instance['content'] ?? $instance['text'] ?? '' ) : '';
			if ( hbch_content_uses_plugin( $content ) ) {
				return $result = true;
			}
		}
	}

	return $result = false;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! hbch_page_uses_plugin() ) {
		return;
	}

	wp_enqueue_style( 'hbch-public', HBCH_URL . 'assets/css/public.css', [], HBCH_VERSION );
	wp_add_inline_style( 'hbch-public', hbch_get_dynamic_inline_css() );

	// Countdown, Kalender-Dropdown und Logo-title-Fallback (im Footer, ohne Abhängigkeiten).
	wp_enqueue_script( 'hbch-public', HBCH_URL . 'assets/js/public.js', [], HBCH_VERSION, true );
} );
