<?php
/**
 * includes/frontend.php
 *
 * Asset-Laden, Inline-CSS und Frontend-JavaScript.
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
 * JS-Rumpf (ohne <script>-Tags) für die Hervorhebung der eigenen Mannschaft
 * und das Entfernen fremdgesetzter title-Attribute an Team-Logos. Wird im
 * Frontend und nach jeder Live-Vorschau im Adminpanel ausgegeben.
 */
function hbch_get_highlight_js_body() {
	$enabled    = (bool) hbch_get_setting( 'highlight_own_team_enabled' );
	$match_text = (string) hbch_get_setting( 'highlight_own_team_text' );

	ob_start();
	?>
	<?php if ( $enabled && $match_text !== '' ) : ?>
	document.querySelectorAll('#hbch-ranking-team td').forEach(function (td) {
		if (td.textContent.includes('<?php echo esc_js( $match_text ); ?>')) {
			td.closest('tr').classList.add('hbch-row-highlight');
		}
	});
	document.querySelectorAll('#hbch-ranking-mini td').forEach(function (td) {
		if (td.textContent.includes('<?php echo esc_js( $match_text ); ?>')) {
			td.closest('tr').classList.add('hbch-row-highlight-mini');
		}
	});
	<?php endif; ?>
	document.querySelectorAll('img[class*="hbch-team-logo"][title]').forEach(function (img) {
		img.removeAttribute('title');
	});
	<?php
	return ob_get_clean();
}

/**
 * Entfernt title-Attribute von Team-Logo-<img> (Klasse "hbch-team-logo*") im
 * fertigen Seiteninhalt. Manche SEO-Plugins ergänzen sie automatisch; der
 * Filter läuft mit der spätestmöglichen Priorität. Andere Bilder bleiben
 * unangetastet. Arbeitet ein Plugin per Output-Buffer danach, hilft nur,
 * dessen Bild-Titel-Funktion abzuschalten (das JS oben ist ein Fallback).
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
 */
function hbch_content_uses_plugin( $content ) {
	if ( $content === '' ) {
		return false;
	}

	static $shortcodes = [
		'hbch_ranking', 'hbch_team_ranking', 'hbch_team_next_games', 'hbch_team_last_games',
		'hbch_home_next_games', 'hbch_home_last_games', 'hbch_next_game', 'hbch_ics',
	];
	static $blocks = [
		'handballch/ranking', 'handballch/team-ranking', 'handballch/team-next-games',
		'handballch/team-last-games', 'handballch/next-game', 'handballch/home-next-games',
		'handballch/home-last-games', 'handballch/ics-subscribe',
	];

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
} );

/**
 * Formatiert die vom Server ausgegebenen rohen ISO-Datumswerte im Browser.
 */
function hbch_frontend_date_format_js() {
	if ( ! hbch_page_uses_plugin() ) {
		return;
	}
	?>
	<script type="text/javascript">
		// Team-Spielpläne
		var y = document.getElementsByClassName("hbch-date-raw");
		var x = document.getElementsByClassName("hbch-time-raw");
		var i;
		for (i = 0; i < y.length; i++) {
			var date_str = y[i].innerHTML,
				options = { year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' },
				formatted = new Date(date_str + 'Z').toLocaleDateString('de-DE', options);
			if (formatted.includes(',')) {
				var date_parts1 = formatted.substring(0, formatted.indexOf(",")).split(" ").join(" ");
				var formatted_date1 = date_parts1;
				var formatted_date3 = formatted.substr(formatted.indexOf(",") + 2).bold();
				y[i].innerHTML = formatted_date1;
				x[i].innerHTML = formatted_date3;
			} else {
				var date_parts = formatted.substring(0, formatted.indexOf("um")).split(" ").join(" ");
				var formatted_date = date_parts;
				var formatted_date2 = formatted.substr(formatted.indexOf("um") + 2).bold();
				y[i].innerHTML = formatted_date;
				x[i].innerHTML = formatted_date2;
			}
		}

		// Startseiten-Widgets
		function hbchFormatStartDate(className, options, withTime) {
			var els = document.getElementsByClassName(className);
			for (var i = 0; i < els.length; i++) {
				if (els[i].querySelector('.hbch-live-badge')) {
					continue;
				}
				var date_str = els[i].innerHTML,
					formatted = new Date(date_str + 'Z').toLocaleDateString('de-DE', options),
					hasComma = formatted.includes(','),
					sepIndex = hasComma ? formatted.indexOf(',') : formatted.indexOf('um'),
					date_part = formatted.substring(0, sepIndex).split(" ").join(" ");
				if (withTime) {
					var time_part = formatted.substr(sepIndex + (hasComma ? 1 : 2)).bold();
					els[i].innerHTML = date_part + '<br>' + time_part;
				} else {
					els[i].innerHTML = date_part;
				}
			}
		}

		hbchFormatStartDate('hbch-game-datetime', { year: 'numeric', month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }, true);
		hbchFormatStartDate('hbch-game-date-text', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }, false);
	</script>
	<?php
}
add_action( 'wp_footer', 'hbch_frontend_date_format_js' );

/**
 * Hervorhebung der eigenen Mannschaft, Entfernen fremder Logo-Titel.
 */
function hbch_frontend_highlight_js() {
	if ( ! hbch_page_uses_plugin() ) {
		return;
	}
	?>
	<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function () {
			<?php echo hbch_get_highlight_js_body(); ?>
		});
	</script>
	<?php
}
add_action( 'wp_head', 'hbch_frontend_highlight_js' );
