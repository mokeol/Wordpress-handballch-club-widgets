<?php
/**
 * includes/rest.php
 *
 * Öffentliche, nur lesende REST-Endpunkte für Vereins-Spiele
 * (/wp-json/handballch/v1/next-games und /last-games).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'handballch/v1', '/next-games', [
		'methods'             => 'GET',
		'callback'            => 'hbch_next_games',
		'permission_callback' => '__return_true',
	] );
	register_rest_route( 'handballch/v1', '/last-games', [
		'methods'             => 'GET',
		'callback'            => 'hbch_last_games',
		'permission_callback' => '__return_true',
	] );
} );

/**
 * Optionaler source-Parameter: nur https://clubapi.handball.ch/… ist erlaubt,
 * alles andere fällt auf die Vereins-URL zurück.
 */
function hbch_sanitize_games_source( $source ) {
	$source = (string) $source;
	if ( ! $source ) {
		return hbch_club_games_url();
	}
	$scheme = wp_parse_url( $source, PHP_URL_SCHEME );
	$host   = wp_parse_url( $source, PHP_URL_HOST );
	if ( $scheme !== 'https' || $host !== 'clubapi.handball.ch' ) {
		return hbch_club_games_url();
	}
	return $source;
}

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
 * GET /next-games: künftige Spiele, aufsteigend sortiert. Forfait-Spiele
 * werden nicht ausgeliefert. "include_live" behält ein laufendes Spiel.
 */
function hbch_next_games( WP_REST_Request $request ) {
	$source       = hbch_sanitize_games_source( $request->get_param( 'source' ) );
	$limit        = hbch_normalize_games_limit( $request->get_param( 'limit' ) );
	$exclude      = sanitize_text_field( (string) $request->get_param( 'exclude' ) );
	$include_live = (bool) $request->get_param( 'include_live' );

	$games = hbch_fetch_club_games( $source );

	$tz  = new DateTimeZone( 'Europe/Zurich' );
	$now = new DateTime( 'now', $tz );

	$games = array_filter( $games, function ( $g ) use ( $now, $tz, $exclude, $include_live ) {
		if ( empty( $g['gameDateTime'] ) ) {
			return false;
		}
		if ( stripos( $g['gameStatus'] ?? '', 'Gespielt' ) !== false || hbch_is_game_forfait( $g ) ) {
			return false;
		}
		$gameDate = new DateTime( $g['gameDateTime'], $tz );
		if ( $gameDate < $now && ! ( $include_live && hbch_is_game_live( $g ) ) ) {
			return false;
		}
		return ! hbch_game_matches_exclude( $g, $exclude );
	} );

	$games = hbch_sort_games_by_datetime( $games );

	$response = new WP_REST_Response( array_slice( $games, 0, $limit ), 200 );
	$response->header( 'Cache-Control', 'public, max-age=' . hbch_rest_cache_seconds() );
	return $response;
}

/**
 * GET /last-games: gespielte Spiele, neueste zuerst (ohne Forfait).
 */
function hbch_last_games( WP_REST_Request $request ) {
	$source  = hbch_sanitize_games_source( $request->get_param( 'source' ) );
	$limit   = hbch_normalize_games_limit( $request->get_param( 'limit' ) );
	$exclude = sanitize_text_field( (string) $request->get_param( 'exclude' ) );

	$games = hbch_fetch_club_games( $source );

	$games = array_filter( $games, function ( $g ) use ( $exclude ) {
		if ( empty( $g['gameDateTime'] ) ) {
			return false;
		}
		if ( stripos( $g['gameStatus'] ?? '', 'Gespielt' ) === false || hbch_is_game_forfait( $g ) ) {
			return false;
		}
		return ! hbch_game_matches_exclude( $g, $exclude );
	} );

	$games = hbch_sort_games_by_datetime( $games, true );

	$response = new WP_REST_Response( array_slice( $games, 0, $limit ), 200 );
	$response->header( 'Cache-Control', 'public, max-age=' . hbch_rest_cache_seconds() );
	return $response;
}
