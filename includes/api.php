<?php
/**
 * includes/api.php
 *
 * Helfer für die clubapi.handball.ch-API: Fetch, Caching (Transients mit
 * Versionszähler, "letzte gute Kopie" und kurzem Negativ-Cache), Sortierung,
 * Logos, Live-/Forfait-/Gespielt-Erkennung, Datumsformat, Strukturdaten.
 *
 * Der API-Token ist ein Base64-String "ClubID:Secret" und wird als
 * HTTP-Basic-Auth gesendet ("Authorization: Basic <Token>").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------------
 * Cache: Versionszähler, Leeren, API-Abruf mit Stale-Fallback
 * ---------------------------------------------------------------------- */

/**
 * Aktuelle Cache-Version. Sie ist Teil jedes Transient-Schlüssels
 * (hbch_cache_key()): Hochzählen macht alle alten Einträge unsichtbar, auch
 * bei externem Object-Cache (Redis/Memcached), wo ein SQL-Löschen nichts
 * bewirken würde. $reset = true liest den Wert neu aus der Datenbank.
 */
function hbch_cache_version( $reset = false ) {
	static $version = null;
	if ( $reset ) {
		$version = null;
	}
	if ( $version === null ) {
		$version = max( 1, (int) get_option( 'hbch_cache_version', 1 ) );
	}
	return $version;
}

/**
 * Transient-Schlüssel mit Präfix "hbch_" und Cache-Version.
 */
function hbch_cache_key( $name ) {
	return 'hbch_v' . hbch_cache_version() . '_' . $name;
}

/**
 * Leert den ganzen Plugin-Cache: zählt die Version hoch (wirkt überall) und
 * räumt zusätzlich die nun verwaisten Transients aus der Datenbank.
 * Gibt die Zahl der entfernten Datenbank-Einträge zurück.
 */
function hbch_flush_cache() {
	global $wpdb;

	update_option( 'hbch_cache_version', hbch_cache_version() + 1, true );
	hbch_cache_version( true );

	$rows = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_hbch_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_hbch_' ) . '%'
		)
	);

	return (int) ( (int) $rows / 2 );
}

// Beim Speichern der Einstellungen alles neu aufbauen lassen (Spalten, Felder,
// Club-ID usw. wirken sich auf Ausgabe und Abrufe aus).
add_action( 'update_option_hbch_settings', 'hbch_flush_cache' );
add_action( 'add_option_hbch_settings', 'hbch_flush_cache' );

/**
 * Wie lange die "letzte gute Kopie" einer API-Antwort aufgehoben wird, wenn die
 * API nicht antwortet (Default 7 Tage). Filter: hbch_stale_cache_seconds.
 */
function hbch_stale_seconds() {
	return max( HOUR_IN_SECONDS, (int) apply_filters( 'hbch_stale_cache_seconds', 7 * DAY_IN_SECONDS ) );
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
 * Ein authentifizierter GET auf die API. Liefert die dekodierte JSON-Antwort
 * (Array) oder null bei Fehler. Anfragen mit Authorization-Header folgen keinen
 * Weiterleitungen ("redirection" => 0), damit die Zugangsdaten nie an ein
 * anderes Ziel gehen.
 */
function hbch_api_request_json( $url, $timeout = 5 ) {
	$response = wp_remote_get( $url, [
		'timeout'     => $timeout,
		'sslverify'   => hbch_ssl_verify(),
		'redirection' => 0,
		'headers'     => hbch_api_auth_header(),
	] );

	if ( is_wp_error( $response ) ) {
		return null;
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * Gecachter API-Abruf mit Stale-Fallback und Negativ-Cache.
 *
 * - Eine Kopie pro Schlüssel (Transient, hbch_stale_seconds() lang) mit
 *   Zeitstempel. Jünger als $minutes = frisch, wird direkt geliefert.
 * - Sonst neuer Abruf. Klappt er, wird die Kopie ersetzt.
 * - Klappt er nicht, merkt sich ein Negativ-Marker 60 Sekunden lang den
 *   Fehler (kein blockierender Neuversuch bei jedem Seitenaufruf), und es wird
 *   die letzte gute Kopie geliefert, falls vorhanden.
 *
 * Rückgabe: Array oder null (keine Daten und kein Abruf möglich).
 */
function hbch_api_get_json( $url, $cache_name, $minutes, $timeout = 5 ) {
	static $request_cache = [];

	$key = hbch_cache_key( $cache_name );
	if ( array_key_exists( $key, $request_cache ) ) {
		return $request_cache[ $key ];
	}

	$stored = get_transient( $key );
	if ( ! is_array( $stored ) || ! isset( $stored['time'] ) || ! array_key_exists( 'data', $stored ) ) {
		$stored = null;
	}

	$ttl = max( 1, (int) $minutes ) * MINUTE_IN_SECONDS;
	if ( $stored && ( time() - (int) $stored['time'] ) < $ttl ) {
		return $request_cache[ $key ] = $stored['data'];
	}

	$fail_key = $key . '_fail';
	$data     = null;
	if ( get_transient( $fail_key ) === false ) {
		$data = hbch_api_request_json( $url, $timeout );
		if ( $data === null ) {
			set_transient( $fail_key, 1, MINUTE_IN_SECONDS );
		}
	}

	if ( $data !== null ) {
		set_transient( $key, [ 'time' => time(), 'data' => $data ], hbch_stale_seconds() );
		return $request_cache[ $key ] = $data;
	}

	return $request_cache[ $key ] = ( $stored ? $stored['data'] : null );
}

/* ------------------------------------------------------------------------
 * Spiel-Helfer: Status, Zeit, Datumsformat
 * ---------------------------------------------------------------------- */

/**
 * Forfait-Spiel: gameStatus enthält "Forfait". Solche Spiele werden in
 * vereinsweiten Listen und im Countdown ausgeblendet, im Team-Spielplan
 * gekennzeichnet und weder als "live" noch als SportsEvent ausgegeben.
 */
function hbch_is_game_forfait( $game ) {
	return stripos( (string) ( $game['gameStatus'] ?? '' ), 'Forfait' ) !== false;
}

/**
 * Gespielt: gameStatus enthält "Gespielt".
 */
function hbch_is_game_played( $game ) {
	return stripos( (string) ( $game['gameStatus'] ?? '' ), 'Gespielt' ) !== false;
}

/**
 * Anpfiff eines Spiels als DateTime (Europe/Zurich), oder null bei fehlendem
 * bzw. ungültigem Datum. gameDateTime ist naive Schweizer Ortszeit. Der
 * Helfer verhindert, dass ein einzelner kaputter API-Wert (Exception im
 * DateTime-Konstruktor) die ganze Seite lahmlegt.
 */
function hbch_game_datetime( $game ) {
	static $tz = null;
	if ( $tz === null ) {
		$tz = new DateTimeZone( 'Europe/Zurich' );
	}

	$raw = is_array( $game ) ? ( $game['gameDateTime'] ?? '' ) : '';
	if ( ! is_string( $raw ) || $raw === '' ) {
		return null;
	}

	try {
		return new DateTime( $raw, $tz );
	} catch ( Exception $e ) {
		return null;
	}
}

function hbch_weekday_name( DateTime $dt ) {
	$names = [ 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag' ];
	return $names[ (int) $dt->format( 'N' ) ];
}

function hbch_month_name( DateTime $dt ) {
	$names = [ 1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember' ];
	return $names[ (int) $dt->format( 'n' ) ];
}

/**
 * Spieldatum als Text (Schweizer Ortszeit, unabhängig von der Website-Sprache).
 * $style: 'short' = 24.09.26, 'numeric' = 24.9.2026,
 * 'long' = 24. September 2026, 'weekday' = Samstag, 26. September.
 * Leer bei fehlendem/ungültigem Datum. Die Ausgabe ist NICHT escaped.
 */
function hbch_format_game_date( $game, $style = 'short' ) {
	$dt = hbch_game_datetime( $game );
	if ( ! $dt ) {
		return '';
	}
	switch ( $style ) {
		case 'numeric':
			return $dt->format( 'j.n.Y' );
		case 'long':
			return $dt->format( 'j' ) . '. ' . hbch_month_name( $dt ) . ' ' . $dt->format( 'Y' );
		case 'weekday':
			return hbch_weekday_name( $dt ) . ', ' . $dt->format( 'j' ) . '. ' . hbch_month_name( $dt );
		default:
			return $dt->format( 'd.m.y' );
	}
}

/**
 * Anpfiffzeit als "HH:MM" (nicht escaped), leer bei ungültigem Datum.
 */
function hbch_format_game_time( $game ) {
	$dt = hbch_game_datetime( $game );
	return $dt ? $dt->format( 'H:i' ) : '';
}

/**
 * Läuft das Spiel gerade? Die API liefert keinen "läuft"-Status, deshalb
 * zeitbasiert: Anpfiff liegt in der Vergangenheit, aber innerhalb der
 * angenommenen Spieldauer (Default 90 Minuten), und der Status ist nicht
 * "Gespielt". Anpassbar per Filter: hbch_live_game_duration_minutes.
 */
function hbch_is_game_live( $game ) {
	if ( hbch_is_game_played( $game ) || hbch_is_game_forfait( $game ) ) {
		return false;
	}

	$kickoff = hbch_game_datetime( $game );
	if ( ! $kickoff ) {
		return false;
	}

	$now = new DateTime( 'now', $kickoff->getTimezone() );
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
	if ( hbch_is_game_forfait( $game ) ) {
		return null;
	}
	$start = hbch_game_datetime( $game );
	if ( ! $start ) {
		return null;
	}
	$now = new DateTime( 'now', $start->getTimezone() );

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
 * gameDateTime ist ein naiver ISO-String (immer Schweizer Ortszeit), daher
 * genügt der Zeichenkettenvergleich, ohne DateTime-Objekte.
 */
function hbch_sort_games_by_datetime( $games, $desc = false ) {
	$games = array_values( $games );
	usort( $games, function ( $a, $b ) use ( $desc ) {
		$cmp = strcmp( (string) ( $a['gameDateTime'] ?? '' ), (string) ( $b['gameDateTime'] ?? '' ) );
		return $desc ? -$cmp : $cmp;
	} );
	return $games;
}

/**
 * Gecachter Fetch der Vereins-Spielliste (Startseite, Countdown, ICS, REST).
 * Ohne Quelle (keine Club-ID gesetzt) kommt eine leere Liste zurück. Bei
 * API-Ausfall wird die letzte gute Kopie geliefert (siehe hbch_api_get_json()).
 */
function hbch_fetch_club_games( $source ) {
	if ( $source === '' ) {
		return [];
	}
	$games = hbch_api_get_json( $source, 'club_games_' . md5( $source ), hbch_get_setting( 'cache_club_games_minutes' ), 8 );
	return is_array( $games ) ? $games : [];
}

/**
 * Spiele-URL des eigenen Vereins, leer wenn noch keine Club-ID gesetzt ist.
 */
function hbch_club_games_url() {
	$club_id = (int) hbch_get_setting( 'club_id' );
	return $club_id > 0 ? 'https://clubapi.handball.ch/rest/v1/clubs/' . $club_id . '/games' : '';
}

/* ------------------------------------------------------------------------
 * Rangliste
 * ---------------------------------------------------------------------- */

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
 * Enthält $haystack den Text $needle (ohne Beachtung der Gross-/Kleinschreibung,
 * UTF-8-tauglich)? Leerer $needle = nie.
 */
function hbch_string_contains_ci( $haystack, $needle ) {
	$haystack = (string) $haystack;
	$needle   = (string) $needle;
	if ( $needle === '' ) {
		return false;
	}
	if ( function_exists( 'mb_stripos' ) ) {
		return mb_stripos( $haystack, $needle, 0, 'UTF-8' ) !== false;
	}
	return stripos( $haystack, $needle ) !== false;
}

/**
 * Ist das die eigene Mannschaft (Hervorhebung aktiv und Such-Text im Teamnamen)?
 * Die Hervorhebung passiert serverseitig, ohne JavaScript.
 */
function hbch_is_own_team_name( $team_name ) {
	if ( ! hbch_get_setting( 'highlight_own_team_enabled' ) ) {
		return false;
	}
	return hbch_string_contains_ci( $team_name, trim( (string) hbch_get_setting( 'highlight_own_team_text' ) ) );
}

/**
 * Zeilen (<tr>) der kompakten Rangliste für [hbch_ranking]. Die Rohdaten
 * kommen gecacht aus hbch_fetch_group_data(), das Markup wird pro Aufruf
 * gebaut (Änderungen an Spalten wirken sofort).
 */
function hbch_fetch_ranking_rows( $team_id ) {
	$columns = array_keys( hbch_ranking_enabled_columns( 'compact' ) );
	$colspan = count( $columns );

	if ( ! $team_id ) {
		return '<tr><td colspan="' . $colspan . '">' . esc_html( hbch_get_setting( 'text_ranking_unknown_team' ) ) . '</td></tr>';
	}

	$group = hbch_fetch_group_data( $team_id );
	if ( $group === null ) {
		return '<tr><td colspan="' . $colspan . '">' . esc_html( hbch_get_setting( 'text_ranking_unavailable' ) ) . '</td></tr>';
	}

	$rows = '';
	$data = $group['ranking'] ?? [];
	if ( ! empty( $data ) && is_array( $data ) ) {
		foreach ( $data as $t ) {
			$cells = '';
			foreach ( $columns as $key ) {
				$cells .= hbch_ranking_column_cell( $key, $t );
			}
			$class = 'hbch-row-divider' . ( hbch_is_own_team_name( $t['teamName'] ?? '' ) ? ' hbch-row-highlight-mini' : '' );
			$rows .= '<tr class="' . $class . '">' . $cells . '</tr>';
		}
	}

	return $rows;
}

/**
 * Liga-/Gruppendaten eines Teams (/teams/{id}/group), null bei Fehler (und
 * ohne letzte gute Kopie).
 */
function hbch_fetch_group_data( $team_id ) {
	if ( ! $team_id ) {
		return null;
	}
	$team_id = (int) $team_id;

	return hbch_api_get_json(
		'https://clubapi.handball.ch/rest/v1/teams/' . $team_id . '/group',
		'group_' . $team_id,
		hbch_get_setting( 'cache_ranking_minutes' ),
		5
	);
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
	$club_id = (int) $club_id;

	$response = wp_remote_get( 'https://clubapi.handball.ch/rest/v1/clubs/' . $club_id . '/teams', [
		'timeout'     => 8,
		'sslverify'   => hbch_ssl_verify(),
		'redirection' => 0,
		'headers'     => hbch_api_auth_header(),
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
 * hat). Die Rohdaten kommen gecacht aus hbch_fetch_group_data().
 */
function hbch_fetch_team_ranking_rows( $team_id, $show_zones = true ) {
	$columns = array_keys( hbch_ranking_enabled_columns( 'detailed' ) );

	if ( ! $team_id ) {
		return '<tr><td colspan="' . count( $columns ) . '">' . esc_html( hbch_get_setting( 'text_ranking_unknown_team' ) ) . '</td></tr>';
	}

	$group = hbch_fetch_group_data( $team_id );
	if ( $group === null ) {
		return '<tr><td colspan="' . count( $columns ) . '">' . esc_html( hbch_get_setting( 'text_ranking_unavailable' ) ) . '</td></tr>';
	}

	$dual_logo = (bool) hbch_get_setting( 'ranking_dual_logo_enabled' );
	$rows      = '';
	$data      = $group['ranking'] ?? [];
	if ( ! empty( $data ) && is_array( $data ) ) {
		foreach ( $data as $t ) {
			$zone_class = $show_zones ? hbch_rank_zone_class( $t['rank'] ?? 0, $group ) : '';
			$rank_cell  = sprintf( '<td class="hbch-rank-cell"><span class="hbch-rank-badge %s">%s</span></td>', esc_attr( $zone_class ), esc_html( $t['rank'] ?? '' ) );
			$team_cell  = sprintf(
				'<td>%s %s</td>',
				hbch_team_logo_markup( $t['teamName'] ?? '', $t['teamId'] ?? '', $t['clubId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ),
				esc_html( $t['teamName'] ?? '' )
			);

			$cells = '';
			foreach ( $columns as $key ) {
				$cells .= hbch_ranking_column_cell( $key, $t, $rank_cell, $team_cell );
			}
			$class = 'hbch-row-divider' . ( hbch_is_own_team_name( $t['teamName'] ?? '' ) ? ' hbch-row-highlight' : '' );
			$rows .= '<tr class="' . $class . '">' . $cells . '</tr>';
		}
	}

	return $rows;
}

/* ------------------------------------------------------------------------
 * Spielpläne
 * ---------------------------------------------------------------------- */

/**
 * Team-Spielplan (kommend = 'planned' / gespielt = 'played').
 */
function hbch_fetch_team_games( $team_id, $status ) {
	$games = hbch_fetch_team_games_raw( $team_id );

	$games = array_values( array_filter( $games, function ( $g ) use ( $status ) {
		$played = hbch_is_game_played( $g );
		return $status === 'played' ? $played : ! $played;
	} ) );

	return hbch_sort_games_by_datetime( $games, $status === 'played' );
}

/**
 * Komplette, ungefilterte Spielliste eines Teams (gecacht, mit letzter guter
 * Kopie bei API-Ausfall).
 */
function hbch_fetch_team_games_raw( $team_id ) {
	if ( ! $team_id ) {
		return [];
	}
	$team_id = (int) $team_id;

	$games = hbch_api_get_json(
		'https://clubapi.handball.ch/rest/v1/teams/' . $team_id . '/games',
		'team_games_' . $team_id,
		hbch_get_setting( 'cache_games_minutes' ),
		5
	);

	return is_array( $games ) ? $games : [];
}

/**
 * Nächstes anstehendes Spiel eines Teams (ohne Forfait/Gespielt).
 */
function hbch_fetch_next_game( $team_id ) {
	if ( ! $team_id ) {
		return null;
	}
	$team_id = (int) $team_id;

	$now       = new DateTime( 'now', new DateTimeZone( 'Europe/Zurich' ) );
	$cache_key = hbch_cache_key( 'next_game_' . $team_id );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && array_key_exists( 'game', $cached ) ) {
		$cached_game = $cached['game'];
		$kickoff     = $cached_game ? hbch_game_datetime( $cached_game ) : null;
		// Der Eintrag gilt, solange der gemerkte Anpfiff nicht vorbei ist.
		if ( ! $cached_game || ! $kickoff || $kickoff >= $now ) {
			return $cached_game;
		}
	}

	$games = hbch_fetch_club_games( hbch_club_games_url() );
	if ( ! $games ) {
		return null;
	}

	$games = array_filter( $games, function ( $g ) use ( $now, $team_id ) {
		// Ungültige oder fehlende Datumswerte der API überspringen (kein Fatal Error).
		$game_date = hbch_game_datetime( $g );
		if ( ! $game_date || $game_date < $now ) {
			return false;
		}
		if ( (int) ( $g['teamAId'] ?? 0 ) !== $team_id && (int) ( $g['teamBId'] ?? 0 ) !== $team_id ) {
			return false;
		}
		return ! hbch_is_game_played( $g ) && ! hbch_is_game_forfait( $g );
	} );

	$games  = hbch_sort_games_by_datetime( $games );
	$result = $games[0] ?? null;

	set_transient( $cache_key, [ 'game' => $result ], max( 1, (int) hbch_get_setting( 'cache_next_game_minutes' ) ) * MINUTE_IN_SECONDS );

	return $result;
}

/* ------------------------------------------------------------------------
 * Logos
 * ---------------------------------------------------------------------- */

/**
 * Transient-Schlüssel des Negativ-Markers für ein Logo, das sich nicht laden
 * liess (verhindert, dass bei jedem Seitenaufruf erneut ein Download geplant
 * wird).
 */
function hbch_logo_fail_key( $remote_url ) {
	return hbch_cache_key( 'logo_fail_' . md5( $remote_url ) );
}

/**
 * URL eines Team-/Vereinslogos. Beim ersten Aufruf kommt sofort die
 * Original-URL, ein einmaliger WP-Cron-Job lädt die Datei nach
 * uploads/hbch-logo-cache/. Danach wird die lokale Kopie ausgeliefert.
 * Pro Request wird jede URL nur einmal geprüft.
 */
function hbch_logo_url( $teamId, $clubId, $width = null ) {
	static $memo = [];

	// IDs kommen aus der API: als Zahlen erzwingen, bevor sie in die URL gehen.
	$teamId = (int) $teamId;
	$clubId = (int) $clubId;

	$remote_url = "https://handball.ch/images/logo/{$teamId}.png?fallbackType=club&fallbackId={$clubId}";
	if ( $width ) {
		$remote_url .= '&width=' . (int) $width;
	}

	if ( isset( $memo[ $remote_url ] ) ) {
		return $memo[ $remote_url ];
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
		return $memo[ $remote_url ] = esc_url( $file_url );
	}

	if ( get_transient( hbch_logo_fail_key( $remote_url ) ) === false && ! wp_next_scheduled( 'hbch_download_logo', [ $remote_url ] ) ) {
		wp_schedule_single_event( time(), 'hbch_download_logo', [ $remote_url ] );
	}

	return $memo[ $remote_url ] = esc_url( $remote_url );
}

/**
 * Ein Logo-<img> mit festen width/height (verhindert Layout-Sprünge, CLS).
 * Bewusst kein loading="lazy": zusammen mit JS-Lazy-Load-Plugins lädt
 * iOS Safari die Bilder teils gar nicht nach.
 */
function hbch_render_single_logo( $url, $css_class, $width = null, $alt = '', $height = null ) {
	$width  = (int) ( $width ?: 80 );
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
function hbch_team_logo_markup( $team_name, $team_id, $club_id, $css_class, $width = null, $dual_enabled = true, $height = null ) {
	$own_club_id = (int) hbch_get_setting( 'club_id' );
	$match_text  = trim( (string) hbch_get_setting( 'highlight_own_team_text' ) );

	$team_name_trimmed = trim( (string) $team_name );
	$alt                = $team_name_trimmed !== '' ? 'Logo ' . $team_name_trimmed : 'Team-Logo';

	$is_joint_team_with_us = $dual_enabled
		&& $match_text !== ''
		&& $own_club_id > 0
		&& hbch_string_contains_ci( $team_name, $match_text )
		&& (int) $club_id !== $own_club_id
		&& (int) $club_id !== 0;

	if ( ! $is_joint_team_with_us ) {
		return hbch_render_single_logo( hbch_logo_url( $team_id, $club_id, $width ), $css_class, $width, $alt, $height );
	}

	$partner_logo = hbch_render_single_logo( hbch_logo_url( $team_id, $club_id, $width ), $css_class . ' hbch-team-logo-dual', $width, $alt, $height );
	$own_logo     = hbch_render_single_logo( hbch_logo_url( 0, $own_club_id, $width ), $css_class . ' hbch-team-logo-dual', $width, $alt, $height );

	return '<span class="hbch-team-logo-group">' . $partner_logo . $own_logo . '</span>';
}

/**
 * Logo-Download im Hintergrund (WP-Cron, ausgelöst durch hbch_logo_url()).
 *
 * Gehärtet: nur https://handball.ch/… wird geladen, und die Datei wird nur
 * gespeichert, wenn der Inhalt wirklich ein Bild ist (PNG/JPEG/GIF/WebP).
 * Schlägt der Download fehl, verhindert ein Marker (1 Tag) neue Versuche.
 */
function hbch_download_logo_file( $remote_url ) {
	$parts = wp_parse_url( (string) $remote_url );
	if (
		empty( $parts['scheme'] ) || $parts['scheme'] !== 'https'
		|| empty( $parts['host'] ) || $parts['host'] !== 'handball.ch'
	) {
		return;
	}

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

	$saved = false;
	if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
		$body = wp_remote_retrieve_body( $response );
		$info = $body !== '' ? @getimagesizefromstring( $body ) : false;
		if ( $info && in_array( $info['mime'] ?? '', [ 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ], true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$saved = false !== file_put_contents( $file_path, $body );
		}
	}

	if ( $saved ) {
		delete_transient( hbch_logo_fail_key( $remote_url ) );
	} else {
		set_transient( hbch_logo_fail_key( $remote_url ), 1, DAY_IN_SECONDS );
	}
}
add_action( 'hbch_download_logo', 'hbch_download_logo_file' );

/**
 * Täglicher Aufräum-Job (geplant in handballch-api.php): löscht Logo-Kopien,
 * die älter als das Doppelte der Logo-Cache-Dauer sind (z. B. verwaiste
 * Dateien nach einem Team-ID-Wechsel pro Saison).
 */
add_action( 'hbch_cleanup_logo_cache', function () {
	$upload_dir = wp_upload_dir();
	$cache_dir  = $upload_dir['basedir'] . '/hbch-logo-cache';
	if ( ! is_dir( $cache_dir ) ) {
		return;
	}
	$max_age = max( 1, (int) hbch_get_setting( 'logo_cache_days' ) ) * 2 * DAY_IN_SECONDS;
	foreach ( glob( trailingslashit( $cache_dir ) . '*.png' ) ?: [] as $file ) {
		if ( is_file( $file ) && ( time() - filemtime( $file ) ) > $max_age ) {
			wp_delete_file( $file );
		}
	}
} );
