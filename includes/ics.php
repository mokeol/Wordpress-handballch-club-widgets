<?php
/**
 * includes/ics.php
 *
 * ICS-Kalender-Export (/spielplan.ics, optional ?team=slug) und der
 * Shortcode [hbch_ics] mit Abo-Dropdown. Das JavaScript des Dropdowns
 * (Öffnen, Link kopieren, Teilen) liegt in assets/js/public.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function hbch_ics_register_rewrite_rule() {
	add_rewrite_rule( '^spielplan\.ics$', 'index.php?hbch_ics_download=1', 'top' );
	add_rewrite_tag( '%hbch_ics_download%', '([^&]+)' );
}
add_action( 'init', 'hbch_ics_register_rewrite_rule' );

/**
 * Spiele des eigenen Vereins für den ICS-Export.
 */
function hbch_ics_fetch_games(): array {
	return hbch_fetch_club_games( hbch_club_games_url() );
}

function hbch_ics_escape( string $text ): string {
	$text = str_replace( [ "\\", ",", ";" ], [ "\\\\", "\\,", "\\;" ], $text );
	$text = preg_replace( '/\r\n|\r|\n/', '\\n', $text );
	return $text;
}

/**
 * Faltet eine Zeile auf 75 Oktette (RFC 5545), ohne UTF-8-Zeichen zu zerteilen.
 */
function hbch_ics_fold_line( string $line ): string {
	if ( strlen( $line ) <= 75 ) {
		return $line . "\r\n";
	}

	$folded = '';
	$first  = true;
	while ( strlen( $line ) > 0 ) {
		$chunkLen = $first ? 75 : 74;
		if ( $chunkLen < strlen( $line ) ) {
			while ( $chunkLen > 0 && ( ord( $line[ $chunkLen ] ) & 0xC0 ) === 0x80 ) {
				$chunkLen--;
			}
		}
		$chunk   = substr( $line, 0, $chunkLen );
		$line    = substr( $line, $chunkLen );
		$folded .= ( $first ? '' : ' ' ) . $chunk . "\r\n";
		$first   = false;
	}
	return $folded;
}

/**
 * Baut den Kalender. Zeiten werden in UTC ausgegeben (DTSTART:…Z): Das ist
 * ohne VTIMEZONE-Block gültig und wird von allen Kalender-Apps (auch Outlook)
 * korrekt in die lokale Zeit umgerechnet. Forfait-Spiele fehlen, wie in den
 * übrigen vereinsweiten Listen.
 */
function hbch_ics_build( array $games, string $calendar_name = '' ): string {
	if ( $calendar_name === '' ) {
		$calendar_name = hbch_get_setting( 'text_ics_calendar_name' );
	}
	$duration_minutes = hbch_get_setting( 'ics_game_duration_minutes' );
	$uid_host         = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

	$utc = new DateTimeZone( 'UTC' );
	$now = new DateTime( 'now', $utc );

	$ics  = "BEGIN:VCALENDAR\r\n";
	$ics .= "VERSION:2.0\r\n";
	$ics .= "PRODID:-//handballch-api//Spielplan//DE\r\n";
	$ics .= "CALSCALE:GREGORIAN\r\n";
	$ics .= "METHOD:PUBLISH\r\n";
	$ics .= hbch_ics_fold_line( "X-WR-CALNAME:" . hbch_ics_escape( $calendar_name ) );
	$ics .= hbch_ics_fold_line( "NAME:" . hbch_ics_escape( $calendar_name ) );
	$ics .= hbch_ics_fold_line( "X-WR-TIMEZONE:Europe/Zurich" );

	$show_round = (bool) hbch_get_setting( 'ics_show_round' );
	$show_type  = (bool) hbch_get_setting( 'ics_show_gametype' );
	$show_addr  = (bool) hbch_get_setting( 'ics_show_venue_address' );

	foreach ( $games as $game ) {
		if ( hbch_is_game_forfait( $game ) ) {
			continue;
		}

		// Ungültige oder fehlende Datumswerte überspringen.
		$start = hbch_game_datetime( $game );
		if ( ! $start ) {
			continue;
		}

		$end = ( clone $start )->modify( "+{$duration_minutes} minutes" );

		$start_utc = ( clone $start )->setTimezone( $utc );
		$end_utc   = ( clone $end )->setTimezone( $utc );

		$teamA  = $game['teamAName'] ?? '';
		$teamB  = $game['teamBName'] ?? '';
		$league = $game['leagueShort'] ?? ( $game['groupCupText'] ?? '' );

		$summary = trim( $teamA . ' – ' . $teamB );
		if ( $league !== '' ) {
			$summary .= ' (' . $league . ')';
		}

		$venue_parts = array_filter( [
			$game['venueAddress'] ?? '',
			trim( ( $game['venueZip'] ?? '' ) . ' ' . ( $game['venueCity'] ?? '' ) ),
		] );
		$venue = implode( ', ', $venue_parts );

		$venue_desc = $game['venue'] ?? '';
		if ( $show_addr && ! empty( $game['venueAddress'] ) ) {
			$venue_desc = trim( $venue_desc . ', ' . $game['venueAddress'] . ', ' . trim( ( $game['venueZip'] ?? '' ) . ' ' . ( $game['venueCity'] ?? '' ) ) );
		}

		$description_parts = array_filter( [
			$venue_desc,
			$game['leagueLong'] ?? '',
			$show_type ? ( $game['gameTypeLong'] ?? '' ) : '',
			$show_round && isset( $game['roundNr'] ) ? 'Runde ' . $game['roundNr'] : '',
		] );
		$description = implode( ' – ', $description_parts );

		if ( ! empty( $game['gameId'] ) ) {
			$uid = 'hbch-game-' . (int) $game['gameId'] . '@' . $uid_host;
		} else {
			$uid = md5( $game['gameDateTime'] . '|' . $teamA . '|' . $teamB ) . '@' . $uid_host;
		}

		$ics .= "BEGIN:VEVENT\r\n";
		$ics .= hbch_ics_fold_line( "UID:" . $uid );
		$ics .= hbch_ics_fold_line( "DTSTAMP:" . $now->format( 'Ymd\THis\Z' ) );
		$ics .= hbch_ics_fold_line( "DTSTART:" . $start_utc->format( 'Ymd\THis\Z' ) );
		$ics .= hbch_ics_fold_line( "DTEND:" . $end_utc->format( 'Ymd\THis\Z' ) );
		$ics .= hbch_ics_fold_line( "SUMMARY:" . hbch_ics_escape( $summary ) );
		if ( $venue !== '' ) {
			$ics .= hbch_ics_fold_line( "LOCATION:" . hbch_ics_escape( $venue ) );
		}
		if ( $description !== '' ) {
			$ics .= hbch_ics_fold_line( "DESCRIPTION:" . hbch_ics_escape( $description ) );
		}
		$ics .= "END:VEVENT\r\n";
	}

	$ics .= "END:VCALENDAR\r\n";

	return $ics;
}

/**
 * Erzeugt den Kalender (ganzer Verein oder ein Team). Beim Team-Kalender
 * wird, solange der Standardname gilt, "<Seitenname> – <Liga>" verwendet.
 */
function hbch_ics_generate( string $team_slug = '' ): string {
	$games         = hbch_ics_fetch_games();
	$calendar_name = hbch_get_setting( 'text_ics_calendar_name' );
	$default_name  = $calendar_name;

	if ( $team_slug !== '' ) {
		$team_id  = hbch_get_team_id( $team_slug );
		$filtered = [];

		foreach ( $games as $g ) {
			$aId = (int) ( $g['teamAId'] ?? 0 );
			$bId = (int) ( $g['teamBId'] ?? 0 );
			if ( $aId !== $team_id && $bId !== $team_id ) {
				continue;
			}
			$filtered[] = $g;

			if ( $calendar_name === $default_name ) {
				$league_name = $g['leagueLong'] ?? ( $g['leagueShort'] ?? '' );
				if ( $league_name !== '' ) {
					$calendar_name = get_bloginfo( 'name' ) . ' – ' . $league_name;
				}
			}
		}

		$games = $filtered;
	}

	return hbch_ics_build( $games, $calendar_name );
}

/**
 * Transient-Schlüssel eines Kalenders (mit Cache-Version, siehe api.php).
 */
function hbch_ics_cache_key( string $team_slug = '' ): string {
	return hbch_cache_key( $team_slug !== '' ? 'ics_team_' . sanitize_key( $team_slug ) : 'ics_club' );
}

function hbch_ics_get_cached( string $team_slug = '' ): string {
	$cache_key = hbch_ics_cache_key( $team_slug );

	$cached = get_transient( $cache_key );
	if ( $cached !== false ) {
		return $cached;
	}

	$ics = hbch_ics_generate( $team_slug );

	if ( strpos( $ics, 'BEGIN:VEVENT' ) !== false ) {
		set_transient( $cache_key, $ics, hbch_get_setting( 'cache_ics_hours' ) * HOUR_IN_SECONDS );
	}

	return $ics;
}

/**
 * Leert den ICS-Cache: ohne Argument alle Kalender, mit Team-Slug nur diesen.
 */
add_action( 'hbch_ics_clear_cache', function ( $team_slug = '' ) {
	if ( $team_slug !== '' ) {
		delete_transient( hbch_ics_cache_key( $team_slug ) );
		return;
	}
	delete_transient( hbch_ics_cache_key() );
	foreach ( array_keys( hbch_get_setting( 'teams' ) ) as $slug ) {
		delete_transient( hbch_ics_cache_key( $slug ) );
	}
} );

add_action( 'template_redirect', function () {
	if ( get_query_var( 'hbch_ics_download' ) != 1 ) {
		return;
	}

	$team_slug = isset( $_GET['team'] ) ? sanitize_key( wp_unslash( $_GET['team'] ) ) : '';

	// Unbekannter Team-Slug: normale 404-Seite statt eines leeren Kalenders.
	if ( $team_slug !== '' && ! hbch_get_team_id( $team_slug ) ) {
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		return;
	}

	$ics = hbch_ics_get_cached( $team_slug );

	$filename = $team_slug !== '' ? 'spielplan-' . $team_slug . '.ics' : 'spielplan.ics';

	// Cache-Control passend zur serverseitigen Cache-Dauer (mindestens 5 Minuten).
	$max_age = max( 300, (int) hbch_get_setting( 'cache_ics_hours' ) * HOUR_IN_SECONDS );
	header( 'Cache-Control: public, max-age=' . $max_age );
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="' . $filename . '"' );
	echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS-Text (text/calendar), Werte sind über hbch_ics_escape() maskiert.
	exit;
} );

/**
 * "Kalender abonnieren"-Button mit Dropdown. Ohne $team_slug gilt der ganze
 * Verein, ein leerer $label nimmt den Text aus den Einstellungen. Wird von
 * [hbch_ics] und dem Block "Kalender" direkt aufgerufen.
 */
function hbch_render_ics_button( $team_slug = '', $label = '' ) {
	$team_slug = sanitize_key( (string) $team_slug );
	$label     = trim( (string) $label );
	if ( $label === '' ) {
		$label = (string) hbch_get_setting( 'text_ics_button_label' );
	}

	$ics_url = home_url( '/spielplan.ics' );
	if ( $team_slug !== '' ) {
		$ics_url = add_query_arg( 'team', $team_slug, $ics_url );
	}
	$webcal_url          = 'webcal://' . preg_replace( '#^https?://#', '', $ics_url );
	$google_calendar_url = 'https://www.google.com/calendar/render?cid=' . rawurlencode( $webcal_url );

	$url_id = 'hbch-ics-url-' . ( $team_slug ?: 'verein' );

	ob_start();
	?>
	<div class="hbch-ics-dropdown">
		<button type="button" class="hbch-ics-dropdown-toggle" aria-expanded="false">
			<?php echo esc_html( $label ); ?>
		</button>

		<ul class="hbch-ics-dropdown-menu" hidden>
			<li>
				<a href="<?php echo esc_url( $webcal_url ); ?>" class="hbch-ics-dropdown-item">
				   <span class="hbch-ics-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg></span>  Direkt abonnieren (iPhone, Mac, Outlook)
				</a>
			</li>
			<li>
				<a href="<?php echo esc_url( $google_calendar_url ); ?>" class="hbch-ics-dropdown-item" target="_blank" rel="noopener">
				   <span class="hbch-ics-icon"><svg viewBox="0 0 488 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M488 261.8C488 403.3 391.1 504 248 504 110.8 504 0 393.2 0 256S110.8 8 248 8c66.8 0 123 24.5 166.3 64.9l-67.5 64.9C258.5 52.6 94.3 116.6 94.3 256c0 86.5 69.1 156.6 153.7 156.6 98.2 0 135-70.4 140.8-106.9H248v-85.3h236.1c2.3 12.7 3.9 24.9 3.9 41.4z"></path></svg></span>  Zu Google Kalender hinzufügen
				</a>
			</li>
			<li>
				<button type="button" class="hbch-ics-dropdown-item hbch-ics-copy-btn" data-target="<?php echo esc_attr( $url_id ); ?>">
					<span class="hbch-ics-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></span> <span class="hbch-ics-label">Kalenderlink kopieren (Android)</span>
				</button>
			</li>
			<li>
				<a href="<?php echo esc_url( $ics_url ); ?>" class="hbch-ics-dropdown-item" download>
				   <span class="hbch-ics-icon"><svg viewBox="0 0 512 512" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M216 0h80c13.3 0 24 10.7 24 24v168h87.7c17.8 0 26.7 21.5 14.1 34.1L269.7 378.3c-7.5 7.5-19.8 7.5-27.3 0L90.1 226.1c-12.6-12.6-3.7-34.1 14.1-34.1H192V24c0-13.3 10.7-24 24-24zm296 376v112c0 13.3-10.7 24-24 24H24c-13.3 0-24-10.7-24-24V376c0-13.3 10.7-24 24-24h146.7l49 49c20.1 20.1 52.5 20.1 72.6 0l49-49H488c13.3 0 24 10.7 24 24zm-124 88c0-11-9-20-20-20s-20 9-20 20 9 20 20 20 20-9 20-20zm64 0c0-11-9-20-20-20s-20 9-20 20 9 20 20 20 20-9 20-20z"></path></svg></span>  Kalenderdatei herunterladen
				</a>
			</li>
			<li>
				<?php /* hidden + display:none: die Klasse .hbch-ics-dropdown-item setzt display:block und würde das hidden-Attribut sonst überstimmen. public.js zeigt den Button, wenn der Browser navigator.share kann. */ ?>
				<button type="button" class="hbch-ics-dropdown-item hbch-ics-share-btn" hidden style="display:none" data-url="<?php echo esc_attr( $ics_url ); ?>" data-title="<?php echo esc_attr( hbch_get_setting( 'text_ics_calendar_name' ) ); ?>">
					<span class="hbch-ics-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg></span>  Kalenderlink teilen (WhatsApp, Mail, …)
				</button>
			</li>
		</ul>

		<span id="<?php echo esc_attr( $url_id ); ?>" class="hbch-ics-hidden-url"><?php echo esc_html( $ics_url ); ?></span>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * [hbch_ics team="slug" label="Text"] — "Kalender abonnieren"-Button mit
 * Dropdown. Ohne team gilt der ganze Verein.
 */
add_shortcode( 'hbch_ics', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '', 'label' => '' ], $atts, 'hbch_ics' );
	return hbch_render_ics_button( $atts['team'], $atts['label'] );
} );
