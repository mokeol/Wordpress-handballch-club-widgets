<?php
/**
 * includes/settings.php
 *
 * Alle Einstellungen liegen in EINER Option (HBCH_OPTION). hbch_get_setting()
 * ist die einzige Stelle, die sie liest.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function hbch_settings_defaults() {
	$defaults = [
		'club_id'                   => 0,
		'api_secret'                => '',
		'teams'                     => [],
		'cache_ranking_minutes'     => 20,
		'cache_games_minutes'       => 20,
		'cache_club_games_minutes'  => 20,
		'cache_next_game_minutes'   => 20,
		'cache_ics_hours'           => 6,
		'logo_cache_days'           => 30,
		'ics_game_duration_minutes' => 90,
		'default_games_limit'       => 3,
		'schema_jsonld_enabled'     => true,
		'schema_offers_enabled'     => true,
		'schema_offers_price'       => '0',
		'text_ics_calendar_name'    => 'Spielplan',
		'text_ics_button_label'     => '📆 Kalender abonnieren ▾',
		'text_next_game_title'      => 'Nächstes Spiel',
		'text_next_game_none'       => 'Kein anstehendes Spiel gefunden.',
		'text_next_game_vs'         => 'vs',
		'text_days_label'           => 'Tage',
		'text_hours_label'          => 'Stunden',
		'text_mins_label'           => 'Minuten',
		'text_ranking_unavailable'  => 'Rangliste momentan nicht verfügbar.',
		'text_ranking_unknown_team' => 'Unbekanntes Team.',

		// Rangliste: feste Spaltenreihenfolge, gemeinsame Beschriftung, je ein
		// Schalter für die kompakte und die detaillierte Variante.
		'ranking_columns' => [
			'rank'   => [ 'label' => '#',         'enabled_compact' => true,  'enabled_detailed' => true ],
			'team'   => [ 'label' => 'Team',      'enabled_compact' => true,  'enabled_detailed' => true ],
			'games'  => [ 'label' => 'Sp',        'enabled_compact' => true,  'enabled_detailed' => true ],
			'wins'   => [ 'label' => 'S',         'enabled_compact' => false, 'enabled_detailed' => true ],
			'draws'  => [ 'label' => 'U',         'enabled_compact' => false, 'enabled_detailed' => true ],
			'losses' => [ 'label' => 'N',         'enabled_compact' => false, 'enabled_detailed' => true ],
			'goals'  => [ 'label' => 'Tore',      'enabled_compact' => false, 'enabled_detailed' => true ],
			'diff'   => [ 'label' => '+/-',       'enabled_compact' => false, 'enabled_detailed' => true ],
			'points' => [ 'label' => 'Pkt',       'enabled_compact' => true,  'enabled_detailed' => true ],
			'ppg'    => [ 'label' => 'Pkt/Spiel', 'enabled_compact' => false, 'enabled_detailed' => false ],
		],
		'ranking_dual_logo_enabled'  => true,
		'highlight_own_team_enabled' => true,
		'highlight_own_team_text'    => '',
		'css_ranking'                => '',

		// Team-Spielplan: enabled_next gilt für [hbch_team_next_games],
		// enabled_last für [hbch_team_last_games]. "time" gibt es nur bei next,
		// "details" nur bei last. "show_mobile": Spalte auf schmalen Bildschirmen
		// (<= 950px) sichtbar. Felder mit label '' haben keinen eigenen Text.
		'games_fields' => [
			'date'          => [ 'label' => 'Datum',       'enabled_next' => true,  'enabled_last' => true ],
			'time'          => [ 'label' => 'Zeit',        'enabled_next' => true,  'enabled_last' => false ],
			'matchup'       => [ 'label' => 'Begegnungen', 'enabled_next' => true,  'enabled_last' => true ],
			'venue'         => [ 'label' => 'Halle',       'enabled_next' => true,  'enabled_last' => true,  'show_mobile' => true ],
			'venue_address' => [ 'label' => '',            'enabled_next' => false, 'enabled_last' => false ],
			'round'         => [ 'label' => 'Runde',       'enabled_next' => false, 'enabled_last' => false ],
			'gametype'      => [ 'label' => '',            'enabled_next' => false, 'enabled_last' => false ],
			'spectators'    => [ 'label' => 'Zuschauer',   'enabled_next' => true,  'enabled_last' => true,  'show_mobile' => true ],
			'link'          => [ 'label' => '',            'enabled_next' => true,  'enabled_last' => true,  'show_mobile' => true ],
			'short_names'   => [ 'label' => '',            'enabled_next' => false, 'enabled_last' => false ],
			'dual_logo'     => [ 'label' => '',            'enabled_next' => true,  'enabled_last' => true ],
			'details'       => [ 'label' => 'Details',     'enabled_next' => false, 'enabled_last' => true ],
			'live_badge'    => [ 'label' => 'LIVE',        'enabled_next' => false, 'enabled_last' => false ],
		],
		'css_games' => '',

		// Vereins-Spielplan (Startseiten-Widgets), gleiche Logik wie oben.
		'home_fields' => [
			'venue'         => [ 'label' => 'Halle',     'enabled_next' => true,  'enabled_last' => true, 'show_mobile' => false ],
			'venue_address' => [ 'label' => '',          'enabled_next' => false, 'enabled_last' => false ],
			'round'         => [ 'label' => 'Runde',     'enabled_next' => false, 'enabled_last' => false ],
			'gametype'      => [ 'label' => '',          'enabled_next' => false, 'enabled_last' => false ],
			'spectators'    => [ 'label' => 'Zuschauer', 'enabled_next' => true,  'enabled_last' => true ],
			'dual_logo'     => [ 'label' => '',          'enabled_next' => true,  'enabled_last' => true ],
			'live_badge'    => [ 'label' => 'LIVE',      'enabled_next' => false, 'enabled_last' => false ],
		],
		'css_home' => '',

		'countdown_fields' => [
			'venue'     => [ 'enabled' => true ],
			'logos'     => [ 'enabled' => true ],
			'dual_logo' => [ 'enabled' => true ], // wirkt nur, wenn "logos" aktiv ist
		],
		'css_nextgame' => '',

		'ics_show_round'         => true,
		'ics_show_gametype'      => false,
		'ics_show_venue_address' => false,
		'css_ics'                => '',
	];

	// Farb-Defaults: je eine Farbrolle in hbch_color_roles() (includes/colors.php).
	foreach ( hbch_color_roles() as $role ) {
		$defaults[ $role['setting'] ] = $role['default'];
	}

	return $defaults;
}

/**
 * Liefert einen Einstellungswert (mit Default-Fallback).
 */
function hbch_get_setting( $key ) {
	static $settings = null;
	if ( $settings === null ) {
		$defaults = hbch_settings_defaults();
		$saved    = get_option( HBCH_OPTION, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$settings = array_merge( $defaults, $saved );

		foreach ( [ 'games_fields', 'home_fields', 'ranking_columns', 'countdown_fields' ] as $table_key ) {
			if ( isset( $saved[ $table_key ] ) && is_array( $saved[ $table_key ] ) ) {
				$settings[ $table_key ] = array_replace_recursive( $defaults[ $table_key ], $saved[ $table_key ] );
			}
		}
	}
	return $settings[ $key ] ?? null;
}

/**
 * handball.ch-Team-ID zu einem Slug, 0 wenn unbekannt.
 */
function hbch_get_team_id( $slug ) {
	$teams = hbch_get_setting( 'teams' );
	return isset( $teams[ $slug ] ) ? (int) $teams[ $slug ] : 0;
}

/**
 * API-Token (Base64-String "ClubID:Secret") für den Authorization-Header.
 *
 * Wird aus der Club-ID und dem im Adminpanel hinterlegten Passwort (Secret)
 * automatisch zusammengesetzt — niemand muss selbst Base64 kodieren.
 *
 * Rückwärtskompatibilität: Ist noch ein fertiger Token aus einer älteren
 * Plugin-Version gespeichert (Feld "api_token", vor der Umstellung auf
 * Club-ID + Passwort) und wurde das Passwort-Feld noch nicht neu ausgefüllt,
 * wird dieser alte Token weiterverwendet.
 */
function hbch_get_api_token() {
	$club_id = (int) hbch_get_setting( 'club_id' );
	$secret  = trim( (string) hbch_get_setting( 'api_secret' ) );

	if ( $club_id > 0 && $secret !== '' ) {
		return base64_encode( $club_id . ':' . $secret );
	}

	return trim( (string) hbch_get_setting( 'api_token' ) );
}
