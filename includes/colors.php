<?php
/**
 * includes/colors.php
 *
 * Farbrollen, CSS-Variablen (:root) und die Farb-Überschreibungen der Blöcke.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alle Farbrollen. Der Schlüssel ist die Rollen-ID (Block-Attribut
 * "color_<ID>"), "group" ist 'base' oder 'zone' (Rang-Badge nach Zone).
 */
function hbch_color_roles() {
	static $roles = null;
	if ( $roles !== null ) {
		return $roles;
	}

	$roles = [
		'accent' => [
			'label'   => 'Akzentfarbe',
			'setting' => 'color_accent',
			'var'     => '--hbch-color-accent',
			'default' => '#00b285',
			'group'   => 'base',
			'help'    => 'Eigene Mannschaft (Hervorhebung in der Rangliste), Liga- und Hallentext, Countdown-Zahlen und Titel.',
		],
		'inverse' => [
			'label'   => 'Schrift auf Farbflächen',
			'setting' => 'color_inverse',
			'var'     => '--hbch-color-inverse',
			'default' => '#ffffff',
			'group'   => 'base',
			'help'    => 'Schriftfarbe auf Akzent-, Grau-, Dunkel- und LIVE-Flächen (Hervorhebung, Rang-Badge, Ergebnis-Badge, Badges).',
		],
		'muted' => [
			'label'   => 'Grau (gedämpft)',
			'setting' => 'color_muted',
			'var'     => '--hbch-color-muted',
			'default' => '#888888',
			'group'   => 'base',
			'help'    => 'Kopfzeile der Rangliste, Labels im Countdown, Rang-Badge ohne Zone, Forfait-Badge.',
		],
		'dark' => [
			'label'   => 'Dunkelfläche',
			'setting' => 'color_dark',
			'var'     => '--hbch-color-dark',
			'default' => '#222222',
			'group'   => 'base',
			'help'    => 'Ergebnis-Badge in der mobilen Ansicht der Team-Spiele und Basis für den Schatten des Kalender-Menüs.',
		],
		'live' => [
			'label'   => 'LIVE-Badge',
			'setting' => 'color_live',
			'var'     => '--hbch-color-live',
			'default' => '#d32f2f',
			'group'   => 'base',
			'help'    => 'Hintergrund des LIVE-Badges (beim Hover automatisch dunkler).',
		],
		'line' => [
			'label'   => 'Linien & Rahmen',
			'setting' => 'color_line',
			'var'     => '--hbch-color-line',
			'default' => '#dddddd',
			'group'   => 'base',
			'help'    => 'Trennlinien der Rangliste und Rahmen des Kalender-Menüs.',
		],
		'surface_alt' => [
			'label'   => 'Abgesetzter Hintergrund',
			'setting' => 'color_surface_alt',
			'var'     => '--hbch-color-surface-alt',
			'default' => '#f5f5f5',
			'group'   => 'base',
			'help'    => 'Zebra-Zeilen der Team-Spielpläne und Hover im Kalender-Menü.',
		],
		'surface' => [
			'label'   => 'Hintergrund Kalender-Menü',
			'setting' => 'color_surface',
			'var'     => '--hbch-color-surface',
			'default' => '#ffffff',
			'group'   => 'base',
			'help'    => 'Hintergrund des Dropdown-Menüs beim "Kalender abonnieren"-Button.',
		],

		'zone_promotion_direct' => [
			'label'   => 'Direkter Aufstieg',
			'setting' => 'color_zone_promotion_direct',
			'var'     => '--hbch-zone-promotion-direct',
			'default' => '#2e7d32',
			'group'   => 'zone',
			'help'    => 'Rang-Badge der Teams auf direkten Aufstiegsplätzen.',
		],
		'zone_promotion_candidate' => [
			'label'   => 'Aufstiegskandidat',
			'setting' => 'color_zone_promotion_candidate',
			'var'     => '--hbch-zone-promotion-candidate',
			'default' => '#8bc34a',
			'group'   => 'zone',
			'help'    => 'Rang-Badge der Teams auf Aufstiegsspiel-Plätzen.',
		],
		'zone_relegation_candidate' => [
			'label'   => 'Abstiegskandidat',
			'setting' => 'color_zone_relegation_candidate',
			'var'     => '--hbch-zone-relegation-candidate',
			'default' => '#ff8a65',
			'group'   => 'zone',
			'help'    => 'Rang-Badge der Teams auf Abstiegsspiel-Plätzen.',
		],
		'zone_relegation_direct' => [
			'label'   => 'Direkter Abstieg',
			'setting' => 'color_zone_relegation_direct',
			'var'     => '--hbch-zone-relegation-direct',
			'default' => '#c62828',
			'group'   => 'zone',
			'help'    => 'Rang-Badge der Teams auf direkten Abstiegsplätzen.',
		],
	];

	return $roles;
}

/**
 * Aktueller Farbwert einer Rolle (ungültig/leer = Default).
 */
function hbch_color_value( array $role ) {
	$value = hbch_get_setting( $role['setting'] );
	$hex   = is_string( $value ) ? sanitize_hex_color( $value ) : null;
	return $hex ? $hex : $role['default'];
}

/**
 * :root-Regel mit allen CSS-Variablen. $pretty = true: mehrzeilig für das
 * Referenzfeld im Adminpanel (Zeilenzahl = Anzahl Rollen + 2).
 */
function hbch_colors_root_css( $pretty = false ) {
	$roles = hbch_color_roles();

	if ( $pretty ) {
		$css = ":root {\n";
		foreach ( $roles as $role ) {
			$css .= "\t" . $role['var'] . ': ' . hbch_color_value( $role ) . ";\n";
		}
		return $css . '}';
	}

	$css = ':root{';
	foreach ( $roles as $role ) {
		$css .= $role['var'] . ':' . hbch_color_value( $role ) . ';';
	}
	return $css . '}';
}

/**
 * Welche Farbrollen welcher Block anbietet. "handballch/ranking" bündelt
 * kompakte und detaillierte Variante (inkl. Zonenfarben, da die Zonen im
 * Block optional zuschaltbar sind); "handballch/team-games" und
 * "handballch/home-games" bündeln je die Rollen der früheren next-/last-
 * Blöcke (Union, ohne Duplikate).
 */
function hbch_block_color_roles() {
	$zones = [ 'zone_promotion_direct', 'zone_promotion_candidate', 'zone_relegation_candidate', 'zone_relegation_direct' ];

	return [
		'handballch/ranking'       => array_merge( [ 'accent', 'inverse', 'muted', 'line' ], $zones ),
		'handballch/team-games'    => [ 'surface_alt', 'muted', 'inverse', 'live', 'dark' ],
		'handballch/next-game'     => [ 'accent', 'muted' ],
		'handballch/home-games'    => [ 'accent', 'inverse', 'live' ],
		'handballch/ics-subscribe' => [ 'surface', 'line', 'surface_alt', 'dark' ],
	];
}

/**
 * Rollen (ID => Rolle) eines Blocks in der Reihenfolge aus hbch_block_color_roles().
 */
function hbch_block_roles_for( $block_name ) {
	$map   = hbch_block_color_roles();
	$roles = hbch_color_roles();
	$out   = [];
	foreach ( $map[ $block_name ] ?? [] as $role_id ) {
		if ( isset( $roles[ $role_id ] ) ) {
			$out[ $role_id ] = $roles[ $role_id ];
		}
	}
	return $out;
}

/**
 * Block-Attribute "color_<rolle>" (String, leer = globale Farbe).
 */
function hbch_block_color_attributes( $block_name ) {
	$attributes = [];
	foreach ( array_keys( hbch_block_roles_for( $block_name ) ) as $role_id ) {
		$attributes[ 'color_' . $role_id ] = [ 'type' => 'string', 'default' => '' ];
	}
	return $attributes;
}

/**
 * Inline-Style für den Block-Wrapper: nur die im Block gewählten Farben.
 */
function hbch_block_color_style( array $attributes, $block_name ) {
	$style = '';
	foreach ( hbch_block_roles_for( $block_name ) as $role_id => $role ) {
		$raw = $attributes[ 'color_' . $role_id ] ?? '';
		$hex = is_string( $raw ) ? sanitize_hex_color( trim( $raw ) ) : null;
		if ( $hex ) {
			$style .= $role['var'] . ':' . $hex . ';';
		}
	}
	return $style;
}

/**
 * Daten für blocks-editor.js: Blockname => Liste von { id, label }.
 */
function hbch_block_colors_for_js() {
	$out = [];
	foreach ( array_keys( hbch_block_color_roles() ) as $block_name ) {
		$list = [];
		foreach ( hbch_block_roles_for( $block_name ) as $role_id => $role ) {
			$list[] = [ 'id' => $role_id, 'label' => $role['label'] ];
		}
		$out[ $block_name ] = $list;
	}
	return $out;
}
