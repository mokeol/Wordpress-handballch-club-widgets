<?php
/**
 * includes/blocks.php
 *
 * Gutenberg-Blöcke. Jeder Block hat Farb-Attribute (color_<rolle>), die als
 * CSS-Variablen am Block-Wrapper gesetzt werden und die globalen Farben nur
 * für diesen Block überschreiben (Rollen pro Block: hbch_block_color_roles()
 * in colors.php). Die Shortcodes nutzen nur die globalen Farben.
 *
 * "Rangliste", "Team – Spielplan" und "Verein – Spielplan" rufen die
 * Render-Funktionen aus shortcodes.php direkt auf (kein do_shortcode()/
 * Shortcode-Tag-Umweg) und bündeln mit Checkboxen, was frühere Einzelblöcke
 * getrennt abdeckten:
 *   - "Rangliste": kompakt ODER detailliert (eine Checkbox), bei detailliert
 *     zusätzlich optional Auf-/Abstiegszonen farbig markieren.
 *   - "Team – Spielplan" / "Verein – Spielplan": Nächste Spiele und/oder
 *     Resultate (zwei unabhängige Checkboxen).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function hbch_block_render_shortcode( $tag, array $atts ) {
	$parts = [ $tag ];
	foreach ( $atts as $key => $value ) {
		$value = is_string( $value ) ? trim( $value ) : $value;
		if ( $value === '' || $value === null ) {
			continue;
		}
		$parts[] = sprintf( '%s="%s"', $key, esc_attr( $value ) );
	}
	return do_shortcode( '[' . implode( ' ', $parts ) . ']' );
}

/**
 * Block-Wrapper mit den überschriebenen Farb-Variablen im style-Attribut.
 */
function hbch_block_wrap( $inner_html, $style = '' ) {
	$extra = $style !== '' ? [ 'style' => $style ] : [];
	return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( $extra ), $inner_html );
}

/**
 * Für "Countdown" und "Kalender abonnieren": einfache 1:1-Blöcke, die
 * weiterhin ihren jeweils einen Shortcode aufrufen.
 */
function hbch_block_render( $block_name, $shortcode, array $attributes, array $shortcode_atts ) {
	return hbch_block_wrap(
		hbch_block_render_shortcode( $shortcode, $shortcode_atts ),
		hbch_block_color_style( $attributes, $block_name )
	);
}

/**
 * Registriert einen Block samt Farb-Attributen. Die Farb-Attribute werden in
 * assets/js/blocks-editor.js aus denselben Daten automatisch mitregistriert.
 */
function hbch_register_block( $name, $title, $description, $icon, array $attributes, callable $render_callback ) {
	register_block_type( $name, [
		'title'           => $title,
		'description'     => $description,
		'category'        => 'handballch-api',
		'icon'            => $icon,
		'attributes'      => array_merge( $attributes, hbch_block_color_attributes( $name ) ),
		'editor_script'   => 'handballch-api-blocks-editor',
		'render_callback' => $render_callback,
	] );
}

add_action( 'init', function () {

	// "Rangliste": kompakt oder detailliert (Checkbox), bei detailliert
	// zusätzlich optional die Auf-/Abstiegszonen farbig markieren.
	hbch_register_block(
		'handballch/ranking',
		'Rangliste',
		'Kompakte oder detaillierte Rangliste für ein Team, bei der detaillierten Variante mit optionaler Auf-/Abstiegszonen-Färbung.',
		'chart-bar',
		[
			'team'       => [ 'type' => 'string',  'default' => '' ],
			'detailed'   => [ 'type' => 'boolean', 'default' => false ],
			'show_zones' => [ 'type' => 'boolean', 'default' => true ],
		],
		function ( $attributes ) {
			$team_id = hbch_get_team_id( $attributes['team'] ?? '' );
			$html    = ! empty( $attributes['detailed'] )
				? hbch_render_ranking_table_detailed( $team_id, ! empty( $attributes['show_zones'] ) )
				: hbch_render_ranking_table_compact( $team_id );
			return hbch_block_wrap( $html, hbch_block_color_style( $attributes, 'handballch/ranking' ) );
		}
	);

	// "Team – Spielplan / Resultate": Nächste Spiele und/oder Resultate eines Teams.
	hbch_register_block(
		'handballch/team-games',
		'Team – Spielplan / Resultate',
		'Nächste Spiele und/oder Resultate eines Teams. Auswahl per Checkbox.',
		'calendar-alt',
		[
			'team'      => [ 'type' => 'string',  'default' => '' ],
			'show_next' => [ 'type' => 'boolean', 'default' => true ],
			'show_last' => [ 'type' => 'boolean', 'default' => true ],
		],
		function ( $attributes ) {
			$team_id = hbch_get_team_id( $attributes['team'] ?? '' );
			$html    = '';
			if ( ! empty( $attributes['show_next'] ) ) {
				$html .= hbch_render_team_next_games( $team_id );
			}
			if ( ! empty( $attributes['show_last'] ) ) {
				$html .= hbch_render_team_last_games( $team_id );
			}
			return hbch_block_wrap( $html, hbch_block_color_style( $attributes, 'handballch/team-games' ) );
		}
	);

	hbch_register_block(
		'handballch/next-game',
		'Countdown (nächstes Spiel)',
		'Nächstes Spiel eines Teams mit live laufendem Countdown — entspricht [hbch_next_game]. Einstellungen im Reiter "Countdown".',
		'clock',
		[ 'team' => [ 'type' => 'string', 'default' => '' ] ],
		function ( $attributes ) {
			return hbch_block_render( 'handballch/next-game', 'hbch_next_game', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	// "Verein – Spielplan / Resultate": Nächste Spiele und/oder Resultate über alle
	// Teams. "layout": "cards" (Startseiten-Kartenlook, Default) oder
	// "table" (Team-Spielplan-Tabellenlook, z. B. für die Gesamtspielplan-Seite).
	hbch_register_block(
		'handballch/home-games',
		'Verein – Spielplan / Resultate',
		'Nächste Spiele und/oder Resultate über alle Teams. Auswahl per Checkbox, Layout wählbar: Karten (Startseite) oder Tabelle (wie Team-Spielplan).',
		'calendar',
		[
			'limit'     => [ 'type' => 'string',  'default' => '' ],
			'exclude'   => [ 'type' => 'string',  'default' => '' ],
			'layout'    => [ 'type' => 'string',  'default' => 'cards' ],
			'show_next' => [ 'type' => 'boolean', 'default' => true ],
			'show_last' => [ 'type' => 'boolean', 'default' => true ],
		],
		function ( $attributes ) {
			$limit   = $attributes['limit'] ?? '';
			$exclude = $attributes['exclude'] ?? '';
			$layout  = $attributes['layout'] ?? 'cards';
			$html    = '';
			if ( ! empty( $attributes['show_next'] ) ) {
				$html .= hbch_render_home_next_games( $limit, $exclude, $layout );
			}
			if ( ! empty( $attributes['show_last'] ) ) {
				$html .= hbch_render_home_last_games( $limit, $exclude, $layout );
			}
			return hbch_block_wrap( $html, hbch_block_color_style( $attributes, 'handballch/home-games' ) );
		}
	);

	hbch_register_block(
		'handballch/ics-subscribe',
		'Kalender',
		'"Kalender abonnieren"-Button mit Dropdown (ICS/webcal, Google Kalender, Link) — entspricht [hbch_ics].',
		'download',
		[
			'team'  => [ 'type' => 'string', 'default' => '' ],
			'label' => [ 'type' => 'string', 'default' => '' ],
		],
		function ( $attributes ) {
			return hbch_block_render( 'handballch/ics-subscribe', 'hbch_ics', $attributes, [
				'team'  => $attributes['team'] ?? '',
				'label' => $attributes['label'] ?? '',
			] );
		}
	);
} );

add_filter( 'block_categories_all', function ( $categories ) {
	array_unshift( $categories, [
		'slug'  => 'handballch-api',
		'title' => 'handball.ch Club-Widgets',
		'icon'  => 'sports',
	] );
	return $categories;
} );

add_action( 'enqueue_block_editor_assets', function () {
	wp_register_script(
		'handballch-api-blocks-editor',
		HBCH_URL . 'assets/js/blocks-editor.js',
		[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ],
		HBCH_VERSION,
		true
	);
	wp_enqueue_script( 'handballch-api-blocks-editor' );

	wp_localize_script( 'handballch-api-blocks-editor', 'handballchApiBlocksData', [
		'teams'             => hbch_get_setting( 'teams' ),
		'icsDefaultLabel'   => hbch_get_setting( 'text_ics_button_label' ),
		'defaultGamesLimit' => hbch_get_setting( 'default_games_limit' ),
		'settingsUrl'       => admin_url( 'options-general.php?page=hbch-settings&tab=allgemein' ),
		'colorsUrl'         => admin_url( 'options-general.php?page=hbch-settings&tab=farben' ),
		'blockColors'       => hbch_block_colors_for_js(),
	] );

	wp_enqueue_style( 'handballch-api-blocks-editor', HBCH_URL . 'assets/css/admin.css', [], HBCH_VERSION );
} );

add_action( 'enqueue_block_assets', function () {
	if ( ! is_admin() ) {
		return;
	}
	wp_enqueue_style( 'hbch-public', HBCH_URL . 'assets/css/public.css', [], HBCH_VERSION );
	wp_add_inline_style( 'hbch-public', hbch_get_dynamic_inline_css() );
} );
