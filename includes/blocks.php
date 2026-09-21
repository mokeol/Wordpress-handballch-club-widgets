<?php
/**
 * includes/blocks.php
 *
 * Gutenberg-Blöcke zu den Shortcodes. Jeder Block hat Farb-Attribute
 * (color_<rolle>), die als CSS-Variablen am Block-Wrapper gesetzt werden und
 * die globalen Farben nur für diesen Block überschreiben (Rollen pro Block:
 * hbch_block_color_roles() in colors.php). Die Shortcodes nutzen nur die
 * globalen Farben.
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

	$team_attr = [ 'team' => [ 'type' => 'string', 'default' => '' ] ];

	hbch_register_block(
		'handballch/ranking',
		'handball.ch: Rangliste (kompakt)',
		'Kompakte Rangliste (Platz, Team, Spiele, Punkte) für ein Team — entspricht [hbch_ranking].',
		'editor-ol',
		$team_attr,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/ranking', 'hbch_ranking', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	hbch_register_block(
		'handballch/team-ranking',
		'handball.ch: Rangliste (detailliert)',
		'Detaillierte Rangliste mit Logo, S/U/N, Toren und Auf-/Abstiegszonen — entspricht [hbch_team_ranking].',
		'chart-bar',
		$team_attr,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/team-ranking', 'hbch_team_ranking', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	hbch_register_block(
		'handballch/team-next-games',
		'handball.ch: Team-Spielplan – nächste Spiele',
		'Noch ausstehende Spiele eines Teams, aufsteigend sortiert — entspricht [hbch_team_next_games].',
		'calendar-alt',
		$team_attr,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/team-next-games', 'hbch_team_next_games', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	hbch_register_block(
		'handballch/team-last-games',
		'handball.ch: Team-Spielplan – letzte Resultate',
		'Bereits gespielte Spiele eines Teams mit Resultat, absteigend sortiert — entspricht [hbch_team_last_games].',
		'awards',
		$team_attr,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/team-last-games', 'hbch_team_last_games', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	hbch_register_block(
		'handballch/next-game',
		'handball.ch: Countdown (nächstes Spiel)',
		'Nächstes Spiel eines Teams mit live laufendem Countdown — entspricht [hbch_next_game]. Einstellungen im Reiter "Countdown".',
		'clock',
		$team_attr,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/next-game', 'hbch_next_game', $attributes, [ 'team' => $attributes['team'] ?? '' ] );
		}
	);

	$home_attrs = [
		'limit'   => [ 'type' => 'string', 'default' => '' ],
		'exclude' => [ 'type' => 'string', 'default' => '' ],
	];

	hbch_register_block(
		'handballch/home-next-games',
		'handball.ch: Vereinsweit – nächste Spiele',
		'Kommende Spiele über alle Teams — entspricht [hbch_home_next_games].',
		'calendar',
		$home_attrs,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/home-next-games', 'hbch_home_next_games', $attributes, [
				'limit'   => $attributes['limit'] ?? '',
				'exclude' => $attributes['exclude'] ?? '',
			] );
		}
	);

	hbch_register_block(
		'handballch/home-last-games',
		'handball.ch: Vereinsweit – letzte Resultate',
		'Letzte Resultate über alle Teams — entspricht [hbch_home_last_games].',
		'list-view',
		$home_attrs,
		function ( $attributes ) {
			return hbch_block_render( 'handballch/home-last-games', 'hbch_home_last_games', $attributes, [
				'limit'   => $attributes['limit'] ?? '',
				'exclude' => $attributes['exclude'] ?? '',
			] );
		}
	);

	hbch_register_block(
		'handballch/ics-subscribe',
		'handball.ch: Kalender abonnieren',
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
