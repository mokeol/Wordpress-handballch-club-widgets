<?php
/**
 * includes/api.php
 *
 * Helfer für die clubapi.handball.ch-API: Fetch, Caching (Transients),
 * Sortierung, Logos, Live-/Forfait-Erkennung, Strukturdaten.
 *
 * Der API-Token ist ein Base64-String "ClubID:Secret" und wird als
 * HTTP-Basic-Auth gesendet ("Authorization: Basic <Token>").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function hbch_api_auth_header() {
	return [ 'Authorization' => 'Basic ' . hbch_get_api_token() ];
}

/**
 * TLS-Prüfung für API-Anfragen (Default: an). Nur im Notfall abschaltbar:
 *   add_filter( 'hbch_api_sslverify', '__return_false' );
 */
function hbch_ssl_verify() {
	return (bool) apply_filters( 'hbch_api_sslverify', true );
}

/**
 * Forfait-Spiel: gameStatus enthält "Forfait". Solche Spiele werden in
 * vereinsweiten Listen und im Countdown ausgeblendet, im Team-Spielplan
 * gekennzeichnet und weder als "live" noch als SportsEvent ausgegeben.
 */
function hbch_is_game_forfait( $game ) {
	return stripos( (string) ( $game['gameStatus'] ?? '' ), 'Forfait' ) !== false;
}

/**
 * Läuft das Spiel gerade? Die API liefert keinen "läuft"-Status, deshalb
 * zeitbasiert: Anpfiff liegt in der Vergangenheit, aber innerhalb der
 * angenommenen Spieldauer (Default 90 Minuten), und der Status ist nicht
 * "Gespielt". Anpassbar per Filter: hbch_live_game_duration_minutes.
 */
function hbch_is_game_live( $game ) {
	static $tz = null;
	if ( $tz === null ) {
		$tz = new DateTimeZone( 'Europe/Zurich' );
	}

	if ( stripos( $game['gameStatus'] ?? '', 'Gespielt' ) !== false || hbch_is_game_forfait( $game ) ) {
		return false;
	}
	if ( empty( $game['gameDateTime'] ) ) {
		return false;
	}

	try {
		$kickoff = new DateTime( $game['gameDateTime'], $tz );
	} catch ( Exception $e ) {
		return false;
	}
	$now = new DateTime( 'now', $tz );

	if ( $now < $kickoff ) {
		return false;
	}

	$duration_minutes = (int) apply_filters( 'hbch_live_game_duration_minutes', 90 );
	$ends_at          = ( clone $kickoff )->modify( "+{$duration_minutes} minutes" );

	return $now <= $ends_at;
}

/**
 * URL der Spielseite im handball.ch-Matchcenter.
 */
function hbch_matchcenter_url( $game_id ) {
	return 'https://www.handball.ch/de/matchcenter/spiele/' . rawurlencode( (string) $game_id );
}

/**
 * Matchcenter-Link mit Icon. Das Icon steht pro Request nur einmal als
 * <symbol> im Markup, jede Zeile verweist per <use> darauf (keine doppelten IDs).
 */
function hbch_matchcenter_link_markup( $game_id ) {
	static $symbol_printed = false;

	$symbol = '';
	if ( ! $symbol_printed ) {
		$symbol_printed = true;
		$symbol = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><symbol id="hbch-icon-external" viewBox="0 0 24 24"><path d="M10.0002 5H8.2002C7.08009 5 6.51962 5 6.0918 5.21799C5.71547 5.40973 5.40973 5.71547 5.21799 6.0918C5 6.51962 5 7.08009 5 8.2002V15.8002C5 16.9203 5 17.4801 5.21799 17.9079C5.40973 18.2842 5.71547 18.5905 6.0918 18.7822C6.5192 19 7.07899 19 8.19691 19H15.8031C16.921 19 17.48 19 17.9074 18.7822C18.2837 18.5905 18.5905 18.2839 18.7822 17.9076C19 17.4802 19 16.921 19 15.8031V14M20 9V4M20 4H15M20 4L13 11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></symbol></svg>';
	}

	return $symbol
		. '<a class="hbch-matchcenter-link" href="' . esc_url( hbch_matchcenter_url( $game_id ) ) . '" target="_blank" rel="noopener" aria-label="Zum Matchcenter (öffnet in neuem Tab)">'
		. '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#hbch-icon-external"></use></svg></a>';
}

/**
 * schema.org-SportsEvent (JSON-LD) für ein Spiel, oder null.
 *
 * - gameDateTime ist naive Schweizer Ortszeit, daher Europe/Zurich.
 * - Ohne Halle kein JSON-LD ("location" ist bei Google Pflichtfeld).
 * - "abgesagt" wird zu EventCancelled, alles andere zu EventScheduled
 *   (EventRescheduled bräuchte previousStartDate, das die API nicht liefert).
 */
function hbch_game_jsonld( $game ) {
	if ( empty( $game['gameDateTime'] ) || hbch_is_game_forfait( $game ) ) {
		return null;
	}
	try {
		$start = new DateTime( $game['gameDateTime'], new DateTimeZone( 'Europe/Zurich' ) );
		$now   = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );
	} catch ( Exception $e ) {
		return null;
	}

	$team_a = trim( (string) ( $game['teamAName'] ?? '' ) );
	$team_b = trim( (string) ( $game['teamBName'] ?? '' ) );
	if ( $team_a === '' || $team_b === '' ) {
		return null;
	}

	$venue = trim( (string) ( $game['venue'] ?? '' ) );
	if ( $venue === '' ) {
		return null;
	}

	$event_status = stripos( (string) ( $game['gameStatus'] ?? '' ), 'abgesagt' ) !== false
		? 'https://schema.org/EventCancelled'
		: 'https://schema.org/EventScheduled';

	$end = clone $start;
	$end->modify( '+' . max( 1, (int) hbch_get_setting( 'ics_game_duration_minutes' ) ) . ' minutes' );

	$league = trim( (string) ( $game['leagueShort'] ?? '' ) );

	$home_team = [ '@type' => 'SportsTeam', 'name' => $team_a ];
	$away_team = [ '@type' => 'SportsTeam', 'name' => $team_b ];

	$data = [
		'@type'               => 'SportsEvent',
		'name'                => $team_a . ' - ' . $team_b,
		'description'         => trim( sprintf(
			'Handballspiel%s: %s gegen %s am %s in %s.',
			$league !== '' ? ' (' . $league . ')' : '',
			$team_a,
			$team_b,
			$start->format( 'd.m.Y' ) . ', ' . $start->format( 'H:i' ) . ' Uhr',
			$venue
		) ),
		'startDate'           => $start->format( DateTime::ATOM ),
		'endDate'             => $end->format( DateTime::ATOM ),
		'eventStatus'         => $event_status,
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'homeTeam'            => $home_team,
		'awayTeam'            => $away_team,
		'performer'           => [ $home_team, $away_team ],
		'organizer'           => [
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		],
		'url'                 => hbch_matchcenter_url( $game['gameId'] ?? '' ),
	];

	$own_club_id = (int) hbch_get_setting( 'club_id' );
	if ( $own_club_id > 0 ) {
		$data['image'] = [ hbch_logo_url( 0, $own_club_id, 512 ) ];
	}

	if ( hbch_get_setting( 'schema_offers_enabled' ) ) {
		$price_raw = (string) hbch_get_setting( 'schema_offers_price' );
		$data['offers'] = [
			'@type'         => 'Offer',
			'price'         => $price_raw,
			'priceCurrency' => 'CHF',
			'availability'  => 'https://schema.org/InStock',
			'validFrom'     => $now->format( DateTime::ATOM ),
			'url'           => $data['url'],
		];
		$data['isAccessibleForFree'] = ( (float) str_replace( ',', '.', $price_raw ) === 0.0 );
	}

	$place  = [ '@type' => 'Place', 'name' => $venue ];
	$street = trim( (string) ( $game['venueAddress'] ?? '' ) );
	$zip    = trim( (string) ( $game['venueZip'] ?? '' ) );
	$city   = trim( (string) ( $game['venueCity'] ?? '' ) );
	if ( $street !== '' || $zip !== '' || $city !== '' ) {
		$place['address'] = array_filter( [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $street ?: null,
			'postalCode'      => $zip ?: null,
			'addressLocality' => $city ?: null,
			'addressCountry'  => 'CH',
		] );
	}
	$data['location'] = $place;

	return $data;
}

/**
 * JSON-LD-<script> für eine Spieleliste (ein Objekt bei einem Spiel, sonst
 * "@graph"). Leer, wenn deaktiviert oder kein Spiel gültige Daten liefert.
 */
function hbch_render_games_jsonld( $games ) {
	if ( ! hbch_get_setting( 'schema_jsonld_enabled' ) ) {
		return '';
	}

	$events = [];
	foreach ( $games as $g ) {
		$event = hbch_game_jsonld( $g );
		if ( $event ) {
			$events[] = $event;
		}
	}
	if ( ! $events ) {
		return '';
	}

	$payload = ( count( $events ) === 1 )
		? array_merge( [ '@context' => 'https://schema.org' ], $events[0] )
		: [ '@context' => 'https://schema.org', '@graph' => $events ];

	$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( ! $json ) {
		return '';
	}
	$json = str_replace( '</', '<\/', $json );

	return '<script type="application/ld+json">' . $json . '</script>';
}

/**
 * LIVE-Badge (ersetzt Datum/Zeit), verlinkt auf das Matchcenter.
 */
function hbch_live_badge_markup( $game ) {
	$url = hbch_matchcenter_url( $game['gameId'] ?? '' );
	return '<a class="hbch-live-badge" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" aria-label="Spiel läuft live — zum Matchcenter">
		<span class="hbch-live-dot"></span>LIVE
	</a>';
}

/**
 * Optionaler Zusatztext (Runde/Spielart), wird an eine bestehende Zelle
 * angehängt, damit sich die Spaltenzahl nicht ändert.
 */
function hbch_extra_line( $game, $show_round, $show_gametype, $round_prefix = 'Runde' ) {
	$parts = [];
	if ( $show_round && ! empty( $game['roundNr'] ) ) {
		$parts[] = trim( esc_html( $round_prefix ) . ' ' . esc_html( $game['roundNr'] ) );
	}
	if ( $show_gametype && ! empty( $game['gameTypeLong'] ) ) {
		$parts[] = esc_html( $game['gameTypeLong'] );
	}
	if ( empty( $parts ) ) {
		return '';
	}
	return ' <span class="hbch-extra-info">(' . implode( ' · ', $parts ) . ')</span>';
}

/**
 * Ort: Hallenname oder (optional) volle Adresse.
 */
function hbch_venue_display( $game, $show_address ) {
	$venue = esc_html( $game['venue'] ?? '' );
	if ( ! $show_address || empty( $game['venueAddress'] ) ) {
		return $venue;
	}
	$address = esc_html( $game['venueAddress'] );
	$zip     = esc_html( $game['venueZip'] ?? '' );
	$city    = esc_html( $game['venueCity'] ?? '' );
	return $venue . '<br><small class="hbch-extra-info">' . trim( $address . ', ' . trim( $zip . ' ' . $city ) ) . '</small>';
}

/**
 * Teamname: voller Name, oder Namenspaar (voll/kurz), das per CSS auf
 * schmalen Bildschirmen zum Kurznamen wechselt.
 */
function hbch_team_name_markup( $full, $short, $use_short_on_mobile ) {
	$full = esc_html( $full ?? '' );
	if ( ! $use_short_on_mobile || empty( $short ) ) {
		return $full;
	}
	return '<span class="hbch-full-name">' . $full . '</span><span class="hbch-short-name">' . esc_html( $short ) . '</span>';
}

/**
 * Sortiert Spiele nach Datum und Uhrzeit. $desc = true: neueste zuerst.
 */
function hbch_sort_games_by_datetime( $games, $desc = false ) {
	static $tz = null;
	if ( $tz === null ) {
		$tz = new DateTimeZone( 'Europe/Zurich' );
	}

	$decorated = [];
	foreach ( $games as $game ) {
		$raw = $game['gameDateTime'] ?? '';
		try {
			$ts = $raw !== '' ? ( new DateTime( $raw, $tz ) )->getTimestamp() : 0;
		} catch ( Exception $e ) {
			$ts = 0;
		}
		$decorated[] = [ $ts, $game ];
	}
	usort( $decorated, function ( $a, $b ) use ( $desc ) {
		$cmp = $a[0] <=> $b[0];
		return $desc ? -$cmp : $cmp;
	} );
	return array_map( function ( $d ) { return $d[1]; }, $decorated );
}

/**
 * Gecachter Fetch der Vereins-Spielliste (Startseite, Countdown, ICS, REST).
 * Ohne Quelle (keine Club-ID gesetzt) kommt eine leere Liste zurück.
 */
function hbch_fetch_club_games( $source ) {
	static $request_cache = [];
	if ( $source === '' ) {
		return [];
	}
	if ( array_key_exists( $source, $request_cache ) ) {
		return $request_cache[ $source ];
	}

	$cache_key = 'hbch_club_games_' . md5( $source );
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $request_cache[ $source ] = $cached;
	}

	$response = wp_remote_get( $source, [
		'timeout'   => 8,
		'sslverify' => hbch_ssl_verify(),
		'headers'   => hbch_api_auth_header(),
	] );

	if ( is_wp_error( $response ) ) {
		return $request_cache[ $source ] = [];
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return $request_cache[ $source ] = [];
	}

	$games = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $games ) ) {
		return $request_cache[ $source ] = [];
	}

	set_transient( $cache_key, $games, hbch_get_setting( 'cache_club_games_minutes' ) * MINUTE_IN_SECONDS );
	return $request_cache[ $source ] = $games;
}

/**
 * Spiele-URL des eigenen Vereins, leer wenn noch keine Club-ID gesetzt ist.
 */
function hbch_club_games_url() {
	$club_id = (int) hbch_get_setting( 'club_id' );
	return $club_id > 0 ? 'https://clubapi.handball.ch/rest/v1/clubs/' . $club_id . '/games' : '';
}

function hbch_ranking_column_order() {
	return [ 'rank', 'team', 'games', 'wins', 'draws', 'losses', 'goals', 'diff', 'points', 'ppg' ];
}

/**
 * Aktive Spalten (Key => Label) in fester Reihenfolge.
 */
function hbch_ranking_enabled_columns( $variant = 'compact' ) {
	$columns    = hbch_get_setting( 'ranking_columns' );
	$enable_key = $variant === 'detailed' ? 'enabled_detailed' : 'enabled_compact';
	$enabled    = [];
	foreach ( hbch_ranking_column_order() as $key ) {
		if ( ! empty( $columns[ $key ][ $enable_key ] ) ) {
			$enabled[ $key ] = $columns[ $key ]['label'];
		}
	}
	if ( empty( $enabled ) ) {
		$enabled['team'] = $columns['team']['label'] ?? 'Team';
	}
	return $enabled;
}

/**
 * Eine <td> für eine Ranglisten-Spalte aus den Rohdaten eines Teams.
 */
function hbch_ranking_column_cell( $key, array $t, $rank_cell_html = null, $team_cell_html = null ) {
	$wide = in_array( $key, [ 'wins', 'draws', 'losses', 'goals', 'diff', 'ppg' ], true ) ? ' class="hbch-col-wide"' : '';
	switch ( $key ) {
		case 'rank':
			return $rank_cell_html ?? ( '<td class="hbch-text-center">' . esc_html( $t['rank'] ?? '' ) . '</td>' );
		case 'team':
			return $team_cell_html ?? ( '<td>' . esc_html( $t['teamName'] ?? '' ) . '</td>' );
		case 'games':
			return '<td class="hbch-text-center">' . esc_html( $t['totalGames'] ?? '' ) . '</td>';
		case 'wins':
			return '<td' . $wide . '>' . esc_html( $t['totalWins'] ?? '' ) . '</td>';
		case 'draws':
			return '<td' . $wide . '>' . esc_html( $t['totalDraws'] ?? '' ) . '</td>';
		case 'losses':
			return '<td' . $wide . '>' . esc_html( $t['totalLoss'] ?? '' ) . '</td>';
		case 'goals':
			return '<td' . $wide . '>' . esc_html( $t['totalScoresPlus'] ?? '' ) . ':' . esc_html( $t['totalScoresMinus'] ?? '' ) . '</td>';
		case 'diff':
			return '<td' . $wide . '>' . esc_html( $t['totalScoresDiff'] ?? '' ) . '</td>';
		case 'points':
			return '<td class="hbch-text-center">' . esc_html( $t['totalPoints'] ?? '' ) . '</td>';
		case 'ppg':
			return '<td' . $wide . '>' . esc_html( $t['totalPointsPerGame'] ?? '' ) . '</td>';
		default:
			return '';
	}
}

/**
 * Zeilen (<tr>) der kompakten Rangliste für [hbch_ranking].
 */
function hbch_fetch_ranking_rows( $team_id ) {
	$colspan = count( hbch_ranking_enabled_columns( 'compact' ) );
	if ( ! $team_id ) {
		return '<tr><td colspan="' . $colspan . '">' . esc_html( hbch_get_setting( 'text_ranking_unknown_team' ) ) . '</td></tr>';
	}

	$cache_key = 'hbch_ranking_' . $team_id;
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$group = hbch_fetch_group_data( $team_id );

	if ( $group === null ) {
		return '<tr><td colspan="' . $colspan . '">' . esc_html( hbch_get_setting( 'text_ranking_unavailable' ) ) . '</td></tr>';
	}

	$data    = $group['ranking'] ?? [];
	$columns = array_keys( hbch_ranking_enabled_columns( 'compact' ) );
	$rows    = '';
	if ( ! empty( $data ) && is_array( $data ) ) {
		foreach ( $data as $t ) {
			$cells = '';
			foreach ( $columns as $key ) {
				$cells .= hbch_ranking_column_cell( $key, $t );
			}
			$rows .= '<tr class="hbch-row-divider">' . $cells . '</tr>';
		}
	}

	set_transient( $cache_key, $rows, hbch_get_setting( 'cache_ranking_minutes' ) * MINUTE_IN_SECONDS );
	return $rows;
}

/**
 * Liga-/Gruppendaten eines Teams (/teams/{id}/group), null bei Fehler.
 */
function hbch_fetch_group_data( $team_id ) {
	static $request_cache = [];
	if ( ! $team_id ) {
		return null;
	}
	if ( array_key_exists( (string) $team_id, $request_cache ) ) {
		return $request_cache[ (string) $team_id ];
	}

	$cache_key = 'hbch_group_' . $team_id;
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $request_cache[ (string) $team_id ] = $cached;
	}

	$response = wp_remote_get( "https://clubapi.handball.ch/rest/v1/teams/{$team_id}/group", [
		'timeout'   => 5,
		'sslverify' => hbch_ssl_verify(),
		'headers'   => hbch_api_auth_header(),
	] );

	if ( is_wp_error( $response ) ) {
		return $request_cache[ (string) $team_id ] = null;
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return $request_cache[ (string) $team_id ] = null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return $request_cache[ (string) $team_id ] = null;
	}

	set_transient( $cache_key, $data, hbch_get_setting( 'cache_ranking_minutes' ) * MINUTE_IN_SECONDS );
	return $request_cache[ (string) $team_id ] = $data;
}

/**
 * Liga-Bezeichnung eines Teams aus einer geladenen Vereins-Spielliste.
 */
function hbch_lookup_team_league( $team_id, $club_games ) {
	foreach ( $club_games as $g ) {
		if ( (int) ( $g['teamAId'] ?? 0 ) === (int) $team_id || (int) ( $g['teamBId'] ?? 0 ) === (int) $team_id ) {
			$league = $g['leagueLong'] ?? ( $g['leagueShort'] ?? ( $g['groupCupText'] ?? '' ) );
			return $league !== '' ? $league : null;
		}
	}
	return null;
}

/**
 * Prüft eine Team-ID gegen die API.
 */
function hbch_validate_team_id( $team_id, $club_games = null ) {
	if ( ! $team_id ) {
		return [ 'valid' => false, 'team_name' => null, 'league' => null, 'error' => 'Keine Team-ID hinterlegt.' ];
	}

	$group = hbch_fetch_group_data( $team_id );

	if ( $group === null ) {
		return [ 'valid' => false, 'team_name' => null, 'league' => null, 'error' => 'API nicht erreichbar oder Team-ID unbekannt.' ];
	}

	$ranking = $group['ranking'] ?? [];
	if ( ! is_array( $ranking ) ) {
		return [ 'valid' => false, 'team_name' => null, 'league' => null, 'error' => 'Antwort enthält keine Ranglisten-Daten.' ];
	}

	$team_name = null;
	$found     = false;
	foreach ( $ranking as $row ) {
		if ( (int) ( $row['teamId'] ?? 0 ) === (int) $team_id ) {
			$found     = true;
			$team_name = $row['teamName'] ?? null;
			break;
		}
	}

	if ( ! $found ) {
		return [ 'valid' => false, 'team_name' => null, 'league' => null, 'error' => 'Team-ID nicht in der eigenen Gruppe gefunden (evtl. Saisonwechsel — neue ID auf handball.ch nachschauen).' ];
	}

	if ( $club_games === null ) {
		$club_games = hbch_fetch_club_games( hbch_club_games_url() );
	}

	return [ 'valid' => true, 'team_name' => $team_name, 'league' => hbch_lookup_team_league( $team_id, $club_games ), 'error' => null ];
}

/**
 * Prüft alle konfigurierten Teams.
 */
function hbch_validate_all_teams() {
	$teams      = hbch_get_setting( 'teams' );
	$club_games = hbch_fetch_club_games( hbch_club_games_url() );
	$results    = [];
	foreach ( $teams as $slug => $team_id ) {
		$results[ $slug ] = hbch_validate_team_id( $team_id, $club_games );
	}
	return $results;
}
/**
 * Alle Teams des Vereins live von handball.ch (kein Cache — nur für die
 * einmalige "Teams laden"-Funktion im Adminpanel). Die API liefert jedes
 * Team teils mehrfach (z. B. pro Turnierrunde/Gruppe), daher Deduplizierung
 * nach teamId.
 */
function hbch_fetch_club_teams_live( $club_id ) {
	if ( ! $club_id ) {
		return [ 'error' => 'Keine Club-ID gesetzt.' ];
	}

	$response = wp_remote_get( "https://clubapi.handball.ch/rest/v1/clubs/{$club_id}/teams", [
		'timeout'   => 8,
		'sslverify' => hbch_ssl_verify(),
		'headers'   => hbch_api_auth_header(),
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'error' => $response->get_error_message() ];
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return [ 'error' => "HTTP {$code} — Club-ID oder API-Zugangsdaten prüfen." ];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return [ 'error' => 'Ungültige Antwort der API.' ];
	}

	$teams = [];
	foreach ( $data as $t ) {
		$id = (int) ( $t['teamId'] ?? 0 );
		if ( $id && ! isset( $teams[ $id ] ) ) {
			$teams[ $id ] = $t;
		}
	}

	return [ 'teams' => $teams ];
}

/**
 * Slug-Vorschlag aus dem Liga-Kürzel eines Teams (z. B. "M4" -> "m4",
 * "MU15P S2" -> "mu15p-s2"). Fällt auf den Teamnamen zurück, falls kein
 * Liga-Kürzel vorhanden ist.
 */
function hbch_suggest_team_slug( array $team ) {
	$base = sanitize_title( $team['leagueShort'] ?? '' );
	if ( $base === '' ) {
		$base = sanitize_title( $team['teamName'] ?? '' );
	}
	return $base !== '' ? $base : 'team';
}
/**
 * CSS-Klasse fürs Rang-Badge nach Auf-/Abstiegszone (Daten aus /teams/{id}/group).
 */
function hbch_rank_zone_class( $rank, $group ) {
	if ( ! $group || ! isset( $group['totalTeams'] ) ) {
		return '';
	}

	$rank   = (int) $rank;
	$total  = (int) $group['totalTeams'];
	$dPromo = (int) ( $group['directPromotion'] ?? 0 );
	$cPromo = (int) ( $group['promotionCandidate'] ?? 0 );
	$dReleg = (int) ( $group['directRelegation'] ?? 0 );
	$cReleg = (int) ( $group['relegationCandidate'] ?? 0 );

	if ( $rank > 0 && $rank <= $dPromo ) {
		return 'hbch-zone-promotion-direct';
	}
	if ( $rank > 0 && $rank <= $dPromo + $cPromo ) {
		return 'hbch-zone-promotion-candidate';
	}
	if ( $rank > $total - $dReleg ) {
		return 'hbch-zone-relegation-direct';
	}
	if ( $rank > $total - $dReleg - $cReleg ) {
		return 'hbch-zone-relegation-candidate';
	}
	return '';
}

/**
 * Zeilen der detaillierten Rangliste für [hbch_team_ranking] (mit Logos).
 *
 * $show_zones = false: Rang-Badge bleibt immer grau, ohne Auf-/Abstiegsfarbe
 * (z. B. wenn der Block "Auf-/Abstiegszonen farbig markieren" abgewählt
 * hat). Eigener Cache-Eintrag pro Variante, damit beide unabhängig
 * zwischengespeichert werden.
 */
function hbch_fetch_team_ranking_rows( $team_id, $show_zones = true ) {
	$cache_key = 'hbch_team_ranking_' . $team_id . ( $show_zones ? '' : '_nozones' );
	$rows      = get_transient( $cache_key );

	if ( $rows !== false ) {
		return $rows;
	}

	$rows      = '';
	$api_error = false;
	$columns   = array_keys( hbch_ranking_enabled_columns( 'detailed' ) );
	$dual_logo = (bool) hbch_get_setting( 'ranking_dual_logo_enabled' );
	if ( $team_id ) {
		$group = hbch_fetch_group_data( $team_id );

		if ( $group === null ) {
			$api_error = true;
		} else {
			$data = $group['ranking'] ?? [];
			if ( ! empty( $data ) && is_array( $data ) ) {
				foreach ( $data as $t ) {
					$zone_class = $show_zones ? hbch_rank_zone_class( $t['rank'] ?? 0, $group ) : '';
					$rank_cell  = sprintf( '<td class="hbch-rank-cell"><span class="hbch-rank-badge %s">%s</span></td>', esc_attr( $zone_class ), esc_html( $t['rank'] ?? '' ) );
					$team_cell  = sprintf(
						'<td>%s %s</td>',
						hbch_team_logo_markup( $t['teamName'] ?? '', $t['teamId'] ?? '', $t['clubId'] ?? '', 'hbch-team-logo-sm', 'eager', 60, $dual_logo, 50 ),
						esc_html( $t['teamName'] ?? '' )
					);

					$cells = '';
					foreach ( $columns as $key ) {
						$cells .= hbch_ranking_column_cell( $key, $t, $rank_cell, $team_cell );
					}
					$rows .= '<tr class="hbch-row-divider">' . $cells . '</tr>';
				}
			}
		}
	}

	if ( $api_error ) {
		return '<tr><td colspan="' . count( $columns ) . '">' . esc_html( hbch_get_setting( 'text_ranking_unavailable' ) ) . '</td></tr>';
	}

	set_transient( $cache_key, $rows, hbch_get_setting( 'cache_ranking_minutes' ) * MINUTE_IN_SECONDS );
	return $rows;
}

/**
 * Team-Spielplan (kommend = 'planned' / gespielt = 'played').
 */
function hbch_fetch_team_games( $team_id, $status ) {
	$games = hbch_fetch_team_games_raw( $team_id );

	$games = array_values( array_filter( $games, function ( $g ) use ( $status ) {
		$played = stripos( $g['gameStatus'] ?? '', 'Gespielt' ) !== false;
		return $status === 'played' ? $played : ! $played;
	} ) );

	return hbch_sort_games_by_datetime( $games, $status === 'played' );
}

/**
 * Komplette, ungefilterte Spielliste eines Teams.
 */
function hbch_fetch_team_games_raw( $team_id ) {
	static $request_cache = [];
	if ( array_key_exists( (string) $team_id, $request_cache ) ) {
		return $request_cache[ (string) $team_id ];
	}

	$cache_key = 'hbch_team_games_' . $team_id;
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $request_cache[ (string) $team_id ] = $cached;
	}

	$response = wp_remote_get( "https://clubapi.handball.ch/rest/v1/teams/{$team_id}/games", [
		'timeout'   => 5,
		'sslverify' => hbch_ssl_verify(),
		'headers'   => hbch_api_auth_header(),
	] );

	if ( is_wp_error( $response ) ) {
		return $request_cache[ (string) $team_id ] = [];
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return $request_cache[ (string) $team_id ] = [];
	}

	$games = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $games ) ) {
		return $request_cache[ (string) $team_id ] = [];
	}

	set_transient( $cache_key, $games, hbch_get_setting( 'cache_games_minutes' ) * MINUTE_IN_SECONDS );
	return $request_cache[ (string) $team_id ] = $games;
}

/**
 * Nächstes anstehendes Spiel eines Teams (ohne Forfait/Gespielt).
 */
function hbch_fetch_next_game( $team_id ) {
	if ( ! $team_id ) {
		return null;
	}

	$cache_key = 'hbch_next_game_' . $team_id;
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		$cache_still_valid = true;
		if ( $cached && ! empty( $cached['gameDateTime'] ) ) {
			$cached_kickoff = new DateTime( $cached['gameDateTime'], new DateTimeZone( 'Europe/Zurich' ) );
			if ( $cached_kickoff < new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) ) ) {
				$cache_still_valid = false;
			}
		}
		if ( $cache_still_valid ) {
			return $cached;
		}
	}

	$games = hbch_fetch_club_games( hbch_club_games_url() );
	if ( ! $games ) {
		return null;
	}

	$tz  = new DateTimeZone( 'Europe/Zurich' );
	$now = new DateTime( 'now', $tz );

	$games = array_filter( $games, function ( $g ) use ( $now, $tz, $team_id ) {
		if ( empty( $g['gameDateTime'] ) ) {
			return false;
		}
		if ( (int) ( $g['teamAId'] ?? 0 ) !== $team_id && (int) ( $g['teamBId'] ?? 0 ) !== $team_id ) {
			return false;
		}
		$gameDate = new DateTime( $g['gameDateTime'], $tz );
		if ( $gameDate < $now ) {
			return false;
		}
		if ( stripos( $g['gameStatus'] ?? '', 'Gespielt' ) !== false || hbch_is_game_forfait( $g ) ) {
			return false;
		}
		return true;
	} );

	$games  = hbch_sort_games_by_datetime( $games );
	$result = $games[0] ?? null;

	set_transient( $cache_key, $result, hbch_get_setting( 'cache_next_game_minutes' ) * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * URL eines Team-/Vereinslogos. Beim ersten Aufruf kommt sofort die
 * Original-URL, ein einmaliger WP-Cron-Job lädt die Datei nach
 * uploads/hbch-logo-cache/. Danach wird die lokale Kopie ausgeliefert.
 */
function hbch_logo_url( $teamId, $clubId, $width = null ) {
	$remote_url = "https://handball.ch/images/logo/{$teamId}.png?fallbackType=club&fallbackId={$clubId}";
	if ( $width ) {
		$remote_url .= "&width={$width}";
	}

	static $upload_dir = null;
	if ( $upload_dir === null ) {
		$upload_dir = wp_upload_dir();
	}
	$cache_dir = $upload_dir['basedir'] . '/hbch-logo-cache';
	$cache_url = $upload_dir['baseurl'] . '/hbch-logo-cache';

	$filename  = 'logo-' . md5( $remote_url ) . '.png';
	$file_path = $cache_dir . '/' . $filename;
	$file_url  = $cache_url . '/' . $filename;

	$cache_days = hbch_get_setting( 'logo_cache_days' );

	if ( file_exists( $file_path ) && ( time() - filemtime( $file_path ) ) < $cache_days * DAY_IN_SECONDS ) {
		return esc_url( $file_url );
	}

	if ( ! wp_next_scheduled( 'hbch_download_logo', [ $remote_url ] ) ) {
		wp_schedule_single_event( time(), 'hbch_download_logo', [ $remote_url ] );
	}

	return esc_url( $remote_url );
}

/**
 * Ein Logo-<img> mit festen width/height (verhindert Layout-Sprünge, CLS).
 * Kein loading="lazy": zusammen mit JS-Lazy-Load-Plugins lädt iOS Safari
 * die Bilder teils gar nicht nach. $mode bleibt als Parameter erhalten.
 */
function hbch_render_single_logo( $url, $css_class, $mode = 'eager', $width = null, $alt = '', $height = null ) {
	$width  = (int) ( $width ?: 90 );
	$height = (int) ( $height ?: $width );
	return sprintf(
		'<img class="%1$s" src="%2$s" alt="%3$s" width="%4$d" height="%5$d">',
		esc_attr( $css_class ),
		esc_url( $url ),
		esc_attr( $alt ),
		$width,
		$height
	);
}

/**
 * Logo-Markup für ein Team. Bei einer Spielgemeinschaft (SG) über zwei
 * Vereine liefert handball.ch nur die Club-ID eines Vereins; steht der
 * eigene Vereinstext (Einstellung "Eigene Mannschaft") im Teamnamen, wird
 * zusätzlich das eigene Logo daneben gezeigt.
 *
 * $width/$height: Boxgrösse in Pixeln, $width bestimmt auch die bei
 * handball.ch angeforderte Bildgrösse. $dual_enabled schaltet die
 * Doppel-Logo-Logik pro Widget.
 */
function hbch_team_logo_markup( $team_name, $team_id, $club_id, $css_class, $mode = 'eager', $width = null, $dual_enabled = true, $height = null ) {
	$own_club_id = (int) hbch_get_setting( 'club_id' );
	$match_text  = trim( (string) hbch_get_setting( 'highlight_own_team_text' ) );

	$team_name_trimmed = trim( (string) $team_name );
	$alt                = $team_name_trimmed !== '' ? 'Logo ' . $team_name_trimmed : 'Team-Logo';

	$is_joint_team_with_us = $dual_enabled
		&& $match_text !== ''
		&& $own_club_id > 0
		&& stripos( (string) $team_name, $match_text ) !== false
		&& (int) $club_id !== $own_club_id
		&& (int) $club_id !== 0;

	if ( ! $is_joint_team_with_us ) {
		return hbch_render_single_logo( hbch_logo_url( $team_id, $club_id, $width ), $css_class, $mode, $width, $alt, $height );
	}

	$partner_logo = hbch_render_single_logo( hbch_logo_url( $team_id, $club_id, $width ), $css_class . ' hbch-team-logo-dual', $mode, $width, $alt, $height );
	$own_logo     = hbch_render_single_logo( hbch_logo_url( 0, $own_club_id, $width ), $css_class . ' hbch-team-logo-dual', $mode, $width, $alt, $height );

	return '<span class="hbch-team-logo-group">' . $partner_logo . $own_logo . '</span>';
}

/**
 * Logo-Download im Hintergrund (WP-Cron, ausgelöst durch hbch_logo_url()).
 */
function hbch_download_logo_file( $remote_url ) {
	$upload_dir = wp_upload_dir();
	$cache_dir  = $upload_dir['basedir'] . '/hbch-logo-cache';

	if ( ! file_exists( $cache_dir ) ) {
		wp_mkdir_p( $cache_dir );
	}

	$filename  = 'logo-' . md5( $remote_url ) . '.png';
	$file_path = $cache_dir . '/' . $filename;

	$response = wp_remote_get( $remote_url, [
		'timeout'   => 10,
		'sslverify' => hbch_ssl_verify(),
	] );

	if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
		$body = wp_remote_retrieve_body( $response );
		if ( $body ) {
			file_put_contents( $file_path, $body );
		}
	}
}
add_action( 'hbch_download_logo', 'hbch_download_logo_file' );
// includes/api.php, am Ende der Datei
add_action( 'hbch_cleanup_logo_cache', function () {
	$upload_dir = wp_upload_dir();
	$cache_dir  = $upload_dir['basedir'] . '/hbch-logo-cache';
	if ( ! is_dir( $cache_dir ) ) {
		return;
	}
	$max_age = max( 1, (int) hbch_get_setting( 'logo_cache_days' ) ) * 2 * DAY_IN_SECONDS;
	foreach ( glob( trailingslashit( $cache_dir ) . '*.png' ) ?: [] as $file ) {
		if ( is_file( $file ) && ( time() - filemtime( $file ) ) > $max_age ) {
			@unlink( $file );
		}
	}
} );
// handballch-api.php
function hbch_activate() {
	hbch_ics_register_rewrite_rule();
	flush_rewrite_rules();
	if ( ! wp_next_scheduled( 'hbch_cleanup_logo_cache' ) ) {
		wp_schedule_event( time(), 'daily', 'hbch_cleanup_logo_cache' );
	}
}
function hbch_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'hbch_cleanup_logo_cache' );
}
