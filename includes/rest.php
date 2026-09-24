<?php
/**
 * includes/rest.php
 *
 * Abfragen der Vereins-Spiele (hbch_get_next_games() / hbch_get_last_games(),
 * reine Funktionen, von den Shortcodes und Blöcken direkt genutzt) und die
 * öffentlichen, nur lesenden REST-Endpunkte darauf
 * (/wp-json/handballch/v1/next-games und /last-games).
 *
 * Die Quelle ist immer die Spielliste des eigenen Vereins. Einen frei
 * wählbaren "source"-Parameter gibt es bewusst nicht mehr: er erlaubte
 * beliebig viele authentifizierte Anfragen und Cache-Einträge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	$common_args = [
		'limit'   => [
			'description'       => 'Anzahl Spiele (1–50). Ohne Angabe gilt die Standard-Anzahl aus den Einstellungen.',
			'sanitize_callback' => 'hbch_normalize_games_limit',
		],
		'exclude' => [
			'description'       => 'Freitext: Spiele, bei denen Teamname, Liga oder Gruppentext diesen Text enthalten, werden ausgeblendet.',
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		],
	];

	register_rest_route( 'handballch/v1', '/next-games', [
		'methods'             => 'GET',
		'callback'            => 'hbch_rest_next_games',
		'permission_callback' => '__return_true',
		'args'                => array_merge( $common_args, [
			'include_live' => [
				'description'       => 'Ein gerade laufendes Spiel in der Liste behalten.',
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			],
		] ),
	] );
	register_rest_route( 'handballch/v1', '/last-games', [
		'methods'             => 'GET',
		'callback'            => 'hbch_rest_last_games',
		'permission_callback' => '__return_true',
		'args'                => $common_args,
	] );
} );

function hbch_normalize_games_limit( $value ) {
	$default = max( 1, (int) hbch_get_setting( 'default_games_limit' ) );
	$limit   = is_numeric( $value ) ? (int) $value : $default;
	return min( 50, max( 1, $limit ) );
}

/**
 * Sekunden für "Cache-Control: max-age" (mindestens 60), passend zur
 * serverseitigen Cache-Dauer der Vereins-Spielliste.
 */
function hbch_rest_cache_seconds() {
	return max( 60, (int) hbch_get_setting( 'cache_club_games_minutes' ) * MINUTE_IN_SECONDS );
}

/**
 * Filter für den Freitext-Parameter "exclude" (Teamname, Liga, Gruppentext).
 */
function hbch_game_matches_exclude( $game, $exclude ) {
	if ( ! $exclude ) {
		return false;
	}
	$haystack = ( $game['teamAName'] ?? '' ) . ' ' . ( $game['teamBName'] ?? '' ) . ' ' . ( $game['leagueShort'] ?? '' ) . ' ' . ( $game['groupCupText'] ?? '' );
	return stripos( $haystack, $exclude ) !== false;
}

/**
 * Künftige Spiele des Vereins, aufsteigend sortiert. Forfait-Spiele werden
 * nicht geliefert. $include_live behält ein gerade laufendes Spiel.
 * $limit: leer/ungültig = Standard-Anzahl aus den Einstellungen (1–50).
 * Die Werte werden hier bereinigt, die Funktion ist also direkt aufrufbar.
 */
function hbch_get_next_games( $limit = '', $exclude = '', $include_live = false ) {
	$limit        = hbch_normalize_games_limit( $limit );
	$exclude      = sanitize_text_field( (string) $exclude );
	$include_live = rest_sanitize_boolean( $include_live );

	$games = hbch_fetch_club_games( hbch_club_games_url() );

	$now = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );

	$games = array_filter( $games, function ( $g ) use ( $now, $exclude, $include_live ) {
		// Ungültige oder fehlende Datumswerte der API überspringen (kein Fatal Error).
		$game_date = hbch_game_datetime( $g );
		if ( ! $game_date ) {
			return false;
		}
		if ( hbch_is_game_played( $g ) || hbch_is_game_forfait( $g ) ) {
			return false;
		}
		if ( $game_date < $now && ! ( $include_live && hbch_is_game_live( $g ) ) ) {
			return false;
		}
		return ! hbch_game_matches_exclude( $g, $exclude );
	} );

	$games = hbch_sort_games_by_datetime( $games );

	return array_slice( $games, 0, $limit );
}

/**
 * Gespielte Spiele des Vereins, neueste zuerst (ohne Forfait).
 */
function hbch_get_last_games( $limit = '', $exclude = '' ) {
	$limit   = hbch_normalize_games_limit( $limit );
	$exclude = sanitize_text_field( (string) $exclude );

	$games = hbch_fetch_club_games( hbch_club_games_url() );

	$games = array_filter( $games, function ( $g ) use ( $exclude ) {
		if ( empty( $g['gameDateTime'] ) ) {
			return false;
		}
		if ( ! hbch_is_game_played( $g ) || hbch_is_game_forfait( $g ) ) {
			return false;
		}
		return ! hbch_game_matches_exclude( $g, $exclude );
	} );

	$games = hbch_sort_games_by_datetime( $games, true );

	return array_slice( $games, 0, $limit );
}

/**
 * Antwort mit Cache-Control-Header passend zur serverseitigen Cache-Dauer.
 */
function hbch_rest_games_response( array $games ) {
	$response = new WP_REST_Response( $games, 200 );
	$response->header( 'Cache-Control', 'public, max-age=' . hbch_rest_cache_seconds() );
	return $response;
}

/**
 * GET /next-games
 */
function hbch_rest_next_games( WP_REST_Request $request ) {
	return hbch_rest_games_response( hbch_get_next_games(
		$request->get_param( 'limit' ),
		(string) $request->get_param( 'exclude' ),
		$request->get_param( 'include_live' )
	) );
}

/**
 * GET /last-games
 */
function hbch_rest_last_games( WP_REST_Request $request ) {
	return hbch_rest_games_response( hbch_get_last_games(
		$request->get_param( 'limit' ),
		(string) $request->get_param( 'exclude' )
	) );
}
