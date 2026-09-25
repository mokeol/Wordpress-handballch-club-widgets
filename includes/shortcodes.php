<?php
/**
 * includes/shortcodes.php
 *
 * Alle Shortcodes ausser dem ICS-Button (siehe ics.php).
 *
 * Die eigentliche Render-Logik steckt in benannten hbch_render_*-Funktionen,
 * die sowohl die Shortcodes als auch die Gutenberg-Blöcke (blocks.php)
 * direkt aufrufen — ohne Umweg über do_shortcode()/Shortcode-Tags.
 *
 * Datum und Uhrzeit werden serverseitig formatiert (hbch_format_game_date()/
 * hbch_format_game_time() in api.php), es braucht dafür kein JavaScript.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kompakte Rangliste (#hbch-ranking-mini) für ein Team.
 */
function hbch_render_ranking_table_compact( $team_id ) {
	$rows = hbch_fetch_ranking_rows( $team_id );

	$header_cells = '';
	foreach ( hbch_ranking_enabled_columns( 'compact' ) as $key => $label ) {
		$class = ( $key === 'team' ) ? '' : ' class="hbch-text-center' . ( in_array( $key, [ 'wins', 'draws', 'losses', 'goals', 'diff', 'ppg' ], true ) ? ' hbch-col-wide' : '' ) . '"';
		$header_cells .= '<th' . $class . '>' . esc_html( $label ) . '</th>';
	}

	return '<table id="hbch-ranking-mini"><tbody>'
		. '<tr class="hbch-ranking-header-row">' . $header_cells . '</tr>'
		. $rows . '</tbody></table>';
}

/**
 * [hbch_ranking team="slug"]
 */
add_shortcode( 'hbch_ranking', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_ranking_table_compact( hbch_get_team_id( $atts['team'] ) );
} );

/**
 * Detaillierte Rangliste (#hbch-ranking-team) für ein Team. $show_zones =
 * false: Rang-Badge bleibt immer grau (keine Auf-/Abstiegsfarben).
 */
function hbch_render_ranking_table_detailed( $team_id, $show_zones = true ) {
	$rows = hbch_fetch_team_ranking_rows( $team_id, $show_zones );

	$header_cells = '';
	foreach ( hbch_ranking_enabled_columns( 'detailed' ) as $key => $label ) {
		if ( $key === 'rank' ) {
			$header_cells .= '<th class="hbch-text-center">' . esc_html( $label ) . '</th>';
		} elseif ( in_array( $key, [ 'wins', 'draws', 'losses', 'goals', 'diff', 'ppg' ], true ) ) {
			$header_cells .= '<th class="hbch-col-wide">' . esc_html( $label ) . '</th>';
		} else {
			$header_cells .= '<th>' . esc_html( $label ) . '</th>';
		}
	}

	return '<table id="hbch-ranking-team"><tbody>
		<tr class="hbch-row-divider-thick">' . $header_cells . '
		</tr>' . $rows . '</tbody></table>';
}

/**
 * [hbch_team_ranking team="slug"]
 */
add_shortcode( 'hbch_team_ranking', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_ranking_table_detailed( hbch_get_team_id( $atts['team'] ) );
} );

/**
 * Spieldatum als Text; fällt auf den Rohwert der API zurück, wenn er sich
 * nicht als Datum lesen lässt. Nicht escaped.
 */
function hbch_game_date_text( $game, $style = 'short' ) {
	$text = hbch_format_game_date( $game, $style );
	return $text !== '' ? $text : (string) ( $game['gameDateTime'] ?? '' );
}

/**
 * Tabelle im Team-Spielplan-Format: "zu spielende Spiele" ($variant =
 * 'next') oder "gespielte Spiele" ($variant = 'last'). Wird für die
 * Team-Ebene ([hbch_team_next_games]/[hbch_team_last_games] bzw. Block
 * "Team – Spielplan") und die Vereins-Ebene mit layout="table" verwendet
 * (dann mit den Spielen des ganzen Vereins). Die Felder kommen immer aus den
 * Einstellungen "Team-Spielplan" (games_fields). $table_id muss pro Seite
 * eindeutig sein. $show_league blendet eine zusätzliche Liga-Spalte nach
 * Datum/Zeit ein (nur für die Vereins-Ebene relevant, da dort mehrere Ligen
 * gemischt vorkommen).
 *
 * "next" und "last" teilen sich Kopfzeile, Team-/Logo-Zellen und die
 * Halle/Runde/Spielart/Link-Spalten und unterscheiden sich nur in der
 * Datums-/Ergebnis-Darstellung: "next" zeigt Datum+Zeit (oder ein LIVE-/
 * Forfait-Badge über zwei Zellen) und hat keine eigene Zuschauerspalte
 * (Zuschauerzahl steht dort in der Halle-Zelle); "last" zeigt nur das Datum,
 * dafür zweimal das Ergebnis (für Desktop und Mobile) und eine eigene
 * Zuschauerspalte.
 */
function hbch_render_games_table( array $games, $table_id, $variant, $show_league = false ) {
	$is_next = ( $variant === 'next' );
	$suffix  = $is_next ? 'enabled_next' : 'enabled_last';

	$f = hbch_get_setting( 'games_fields' );

	$show_round   = ! empty( $f['round'][ $suffix ] );
	$show_type    = ! empty( $f['gametype'][ $suffix ] );
	$show_addr    = ! empty( $f['venue_address'][ $suffix ] );
	$show_venue   = ! empty( $f['venue'][ $suffix ] );
	$show_link    = ! empty( $f['link'][ $suffix ] );
	$short_names  = ! empty( $f['short_names'][ $suffix ] );
	$show_spect   = ! empty( $f['spectators'][ $suffix ] );
	$dual_logo    = ! empty( $f['dual_logo'][ $suffix ] );
	$show_live    = $is_next && ! empty( $f['live_badge']['enabled_next'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// Die Halle-Spalte fasst Halle/Runde/Spielart (und bei "next" zusätzlich
	// die Zuschauerzahl) zusammen und erscheint nur, wenn mindestens eine
	// dieser Optionen aktiv ist.
	$show_venue_col = $show_venue || $show_round || $show_type || ( $is_next && $show_spect );
	$venue_td_class = 'hbch-text-small' . ( ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );
	$spect_td_class = 'hbch-text-small' . ( ! empty( $f['spectators']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );
	$link_td_class  = 'hbch-text-small' . ( ! empty( $f['link']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html = ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );
		if ( $is_next && $show_spect && isset( $g['spectators'] ) && $g['spectators'] > 0 ) {
			$venue_html .= ' <span class="hbch-extra-info">· ' . esc_html( $g['spectators'] ) . ' ' . esc_html( $spect_label ) . ' (erwartet)</span>';
		}
		$link_html = $show_link ? ' ' . hbch_matchcenter_link_markup( $g['gameId'] ?? '' ) : '';

		$row_class             = 'hbch-game-row';
		$result_cell_priority1 = '';

		if ( $is_next ) {
			// LIVE-Badge und Forfait ersetzen Datum+Zeit durch EINE Zelle (colspan=2).
			$is_live    = $show_live && hbch_is_game_live( $g );
			$is_forfait = ! $is_live && hbch_is_game_forfait( $g );

			if ( $is_live ) {
				$date_cells = '<td class="hbch-text-small hbch-live-cell" colspan="2">' . hbch_live_badge_markup( $g ) . '</td>';
			} elseif ( $is_forfait ) {
				$date_cells = '<td class="hbch-text-small hbch-forfait-cell" colspan="2">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . ' <span class="hbch-forfait-badge">Forfait</span></td>';
			} else {
				$date_cells = '<td class="hbch-text-small">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . '</td>'
					. '<td class="hbch-text-small"><strong>' . esc_html( hbch_format_game_time( $g ) ) . '</strong></td>';
			}
			$row_class  .= ( $is_live ? ' hbch-game-row-live' : '' ) . ( $is_forfait ? ' hbch-game-row-forfait' : '' );
			$middle_cell = '<td class="hbch-vs-cell hbch-priority-2"> : </td>';
		} else {
			$score_ft = esc_html( $g['teamAScoreFT'] ?? '' ) . ' : ' . esc_html( $g['teamBScoreFT'] ?? '' ) . ' ';
			$score_ht = '(' . esc_html( $g['teamAScoreHT'] ?? '' ) . ':' . esc_html( $g['teamBScoreHT'] ?? '' ) . ')';
			$result   = '<span class="hbch-score-badge">' . $score_ft . '</span>' . $score_ht;

			$date_cells             = '<td class="hbch-text-small">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . '</td>';
			$middle_cell            = '<td class="hbch-result-cell hbch-priority-2">' . $result . '</td>';
			$result_cell_priority1  = '<td class="hbch-result-cell hbch-priority-1">' . $result . '</td>';
		}

		$rows .= '<tr class="' . esc_attr( $row_class ) . '">'
			. $date_cells
			. ( $show_league ? '<td class="hbch-text-small hbch-league-cell">' . esc_html( $g['leagueShort'] ?? '' ) . '</td>' : '' )
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-team-cell hbch-align-right">'
				. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamAName'] ?? '', $g['teamANameShort'] ?? '', $short_names )
			. '</td>'
			. $middle_cell
			. '<td class="hbch-team-cell hbch-align-left">'
				. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamBName'] ?? '', $g['teamBNameShort'] ?? '', $short_names )
			. '</td>'
			. $result_cell_priority1
			. ( $show_venue_col ? '<td class="' . esc_attr( $venue_td_class ) . '">' . $venue_html . '</td>' : '' )
			. ( ! $is_next && $show_spect ? '<td class="' . esc_attr( $spect_td_class ) . '">' . esc_html( $g['spectators'] ?? '' ) . ' ' . esc_html( $spect_label ) . '</td>' : '' )
			. ( $show_link ? '<td class="' . esc_attr( $link_td_class ) . '">' . $link_html . '</td>' : '' )
			. '</tr>';
	}

	$time_th   = $is_next ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['time']['label'] ) . '</th>' : '';
	$league_th = $show_league ? '<th class="hbch-game-row hbch-priority-2">Liga</th>' : '';
	$venue_th  = $show_venue_col ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['venue']['label'] ) . '</th>' : '';
	$spect_th  = ( ! $is_next && $show_spect ) ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['spectators']['label'] ) . '</th>' : '';
	$link_th   = $show_link ? '<th class="hbch-game-row hbch-priority-2">' . ( $is_next ? '' : esc_html( $f['details']['label'] ) ) . '</th>' : '';

	return '<table id="' . esc_attr( $table_id ) . '" class="hbch-table-responsive"><tbody>
		<tr><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['date']['label'] ) . '</th>' . $time_th . $league_th . '
		<th colspan="3" class="hbch-game-row hbch-priority-2">' . esc_html( $f['matchup']['label'] ) . '</th>' . $venue_th . $spect_th . $link_th . '</tr>'
		. $rows . '</tbody></table>';
}

/**
 * Abwärtskompatible Wrapper der bis 1.0.5 getrennten Render-Funktionen
 * hbch_render_games_table_next()/_last() — falls ein Child-Theme oder
 * Snippet sie direkt aufruft. Neuer Code sollte hbch_render_games_table()
 * mit $variant 'next'/'last' verwenden.
 */
function hbch_render_games_table_next( array $games, $table_id, $show_league = false ) {
	return hbch_render_games_table( $games, $table_id, 'next', $show_league );
}
function hbch_render_games_table_last( array $games, $table_id, $show_league = false ) {
	return hbch_render_games_table( $games, $table_id, 'last', $show_league );
}

/**
 * Zu spielende Spiele eines Teams (#hbch-team-games-next), inkl. JSON-LD.
 */
function hbch_render_team_next_games( $team_id ) {
	$games = $team_id ? hbch_fetch_team_games( $team_id, 'planned' ) : [];
	return hbch_render_games_table( $games, 'hbch-team-games-next', 'next' ) . hbch_render_games_jsonld( $games );
}

/**
 * [hbch_team_next_games team="slug"]
 */
add_shortcode( 'hbch_team_next_games', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_team_next_games( hbch_get_team_id( $atts['team'] ) );
} );

/**
 * Gespielte Spiele eines Teams (#hbch-team-games-last).
 */
function hbch_render_team_last_games( $team_id ) {
	$games = $team_id ? hbch_fetch_team_games( $team_id, 'played' ) : [];
	return hbch_render_games_table( $games, 'hbch-team-games-last', 'last' );
}

/**
 * [hbch_team_last_games team="slug"]
 */
add_shortcode( 'hbch_team_last_games', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_team_last_games( hbch_get_team_id( $atts['team'] ) );
} );

/**
 * Vereinsweite "Karten"-Ansicht (layout="cards", Default) für kommende
 * Spiele ($variant = 'next') oder letzte Resultate ($variant = 'last').
 * Beide teilen sich dasselbe Grid (Team A | Mitte | Team B, siehe
 * .hbch-home-result-grid in public.css) und unterscheiden sich nur in der
 * Mitte: Datum/Zeit oder LIVE-Badge bei "next", Ergebnis bei "last". Die
 * Felder kommen aus den Einstellungen "Vereins-Spielplan" (home_fields).
 */
function hbch_render_home_games_cards( array $games, $variant ) {
	$is_next = ( $variant === 'next' );
	$suffix  = $is_next ? 'enabled_next' : 'enabled_last';

	$f = hbch_get_setting( 'home_fields' );

	$show_round   = ! empty( $f['round'][ $suffix ] );
	$show_type    = ! empty( $f['gametype'][ $suffix ] );
	$show_addr    = ! empty( $f['venue_address'][ $suffix ] );
	$show_venue   = ! empty( $f['venue'][ $suffix ] );
	$show_spect   = ! empty( $f['spectators'][ $suffix ] );
	$dual_logo    = ! empty( $f['dual_logo'][ $suffix ] );
	$show_live    = $is_next && ! empty( $f['live_badge']['enabled_next'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// "Auf Mobile anzeigen" bei Halle steuert in beiden Widgets denselben
	// Meta-Block (Halle/Zuschauer/Runde/Spielart bzw. Datum/Halle/Zuschauer).
	$mobile_class = ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-hide-mobile';

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html = '<span class="hbch-venue">' . ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . '</span>' . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );

		// Die Vereins-Spielliste liefert keine Kurznamen: voller Name, auf Mobile ausgeblendet.
		$name_a = '<span class="hbch-hide-mobile">' . esc_html( $g['teamAName'] ?? '' ) . '</span>';
		$name_b = '<span class="hbch-hide-mobile">' . esc_html( $g['teamBName'] ?? '' ) . '</span>';

		if ( $is_next ) {
			if ( $show_spect && isset( $g['spectators'] ) && $g['spectators'] > 0 ) {
				$venue_html .= ' <span class="hbch-extra-info">· ' . esc_html( $g['spectators'] ) . ' ' . esc_html( $spect_label ) . ' (erwartet)</span>';
			}

			// Datum (untereinander mit der fetten Uhrzeit) oder, solange das Spiel läuft, das LIVE-Badge.
			$center = ( $show_live && hbch_is_game_live( $g ) )
				? hbch_live_badge_markup( $g )
				: esc_html( hbch_game_date_text( $g, 'numeric' ) ) . '<br><strong>' . esc_html( hbch_format_game_time( $g ) ) . '</strong>';

			$wrapper_class = 'hbch-home-next-game';
			$center_html   = '<span class="hbch-game-datetime">' . $center . '</span>';
			$meta_html     = '<span class="hbch-result-venue">' . $venue_html . '</span>';
		} else {
			// Zuschauer stehen in derselben Zeile wie das Datum (Trennstrich per CSS).
			$spect_html = ( $show_spect && ! empty( $g['spectators'] ) )
				? '<span class="hbch-result-spectators"><strong>' . esc_html( $g['spectators'] ) . '</strong> ' . esc_html( $spect_label ) . '</span>'
				: '';

			$wrapper_class = 'hbch-home-last-game';
			$center_html   = '<big><strong class="hbch-score-result">' . esc_html( $g['teamAScoreFT'] ?? '' ) . ':' . esc_html( $g['teamBScoreFT'] ?? '' ) . '</strong></big>';
			$meta_html     = '<span class="hbch-result-meta-line"><span class="hbch-game-date-text">' . esc_html( hbch_game_date_text( $g, 'long' ) ) . '</span>' . $spect_html . '</span>'
				. '<span class="hbch-result-venue">' . $venue_html . '</span>';
		}

		// Gleiches Grid für "nächste Spiele" und "letzte Resultate": Team A | Mitte | Team B.
		$rows .= '<tr>'
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-cell-padded">'
				. '<div class="' . esc_attr( $wrapper_class ) . '"><small><div class="hbch-home-result-grid">'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_a
					. '</div>'
					. '<div class="hbch-result-center">'
						. '<span class="hbch-league">' . esc_html( $g['leagueShort'] ?? '' ) . '</span>'
						. $center_html
						. '<span class="hbch-result-meta' . $mobile_class . '">' . $meta_html . '</span>'
					. '</div>'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_b
					. '</div>'
				. '</div></small></div>'
			. '</td>'
			. '</tr>';
	}

	if ( $is_next ) {
		$thead = '<thead><tr>
				<th scope="col" class="screen-reader-text">Status</th>
				<th scope="col" class="screen-reader-text">Begegnung</th>
			</tr></thead>';
		return '<table class="hbch-home-games-next hbch-table-plain">' . $thead . '<tbody>' . $rows . '</tbody></table>' . hbch_render_games_jsonld( $games );
	}

	return '<table class="hbch-home-games-last hbch-table-plain"><tbody>' . $rows . '</tbody></table>';
}

/**
 * Kommende Spiele über alle Teams des Vereins, inkl. JSON-LD. Die Spiele
 * kommen aus hbch_get_next_games() (rest.php).
 *
 * $layout: "cards" (Default) = Kartenlook für die Startseite (Felder aus
 *   "Vereins-Spielplan"). "table" = Tabellenlook wie beim Team-Spielplan
 *   inkl. Mobile-Ansicht und Liga-Spalte (Felder aus "Team-Spielplan"), da
 *   hier mehrere Ligen gemischt vorkommen.
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_next_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$table = ( $layout === 'table' );
	$f     = hbch_get_setting( $table ? 'games_fields' : 'home_fields' );

	// Mit LIVE-Badge bleibt ein laufendes Spiel in der Liste ("include_live").
	$show_live = ! empty( $f['live_badge']['enabled_next'] );
	$games     = hbch_get_next_games( $limit, $exclude, $show_live );

	if ( $table ) {
		return hbch_render_games_table( $games, 'hbch-club-games-next', 'next', true ) . hbch_render_games_jsonld( $games );
	}

	return hbch_render_home_games_cards( $games, 'next' );
}

/**
 * [hbch_home_next_games limit="3" exclude="text" layout="cards|table"]
 */
add_shortcode( 'hbch_home_next_games', function ( $atts ) {
	$atts = shortcode_atts( [ 'limit' => '', 'exclude' => '', 'layout' => 'cards' ], $atts );
	return hbch_render_home_next_games( $atts['limit'], $atts['exclude'], $atts['layout'] );
} );

/**
 * Letzte Resultate über alle Teams.
 *
 * $layout: "cards" (Default) = Kartenlook (Felder aus "Vereins-Spielplan").
 *   "table" = Tabellenlook wie beim Team-Spielplan, inkl. Liga-Spalte
 *   (Felder aus "Team-Spielplan").
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_last_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$games = hbch_get_last_games( $limit, $exclude );

	if ( $layout === 'table' ) {
		return hbch_render_games_table( $games, 'hbch-club-games-last', 'last', true );
	}

	return hbch_render_home_games_cards( $games, 'last' );
}

/**
 * [hbch_home_last_games limit="3" exclude="text" layout="cards|table"]
 */
add_shortcode( 'hbch_home_last_games', function ( $atts ) {
	$atts = shortcode_atts( [ 'limit' => '', 'exclude' => '', 'layout' => 'cards' ], $atts );
	return hbch_render_home_last_games( $atts['limit'], $atts['exclude'], $atts['layout'] );
} );

/**
 * Nächstes Spiel eines Teams mit Countdown (#hbch-next-game-N). Datum, Halle
 * und die Startwerte des Countdowns kommen serverseitig; nur das Weiterzählen
 * übernimmt assets/js/public.js (liest data-kickoff, UTC).
 */
function hbch_render_next_game( $team_id ) {
	$none = '<table class="hbch-next-game-widget hbch-table-plain"><tbody><tr><td>' . esc_html( hbch_get_setting( 'text_next_game_none' ) ) . '</td></tr></tbody></table>';

	$game = hbch_fetch_next_game( $team_id );
	if ( ! $game ) {
		return $none;
	}

	// gameDateTime ist naive Schweizer Ortszeit: für den Countdown nach UTC umrechnen.
	$kickoff = hbch_game_datetime( $game );
	if ( ! $kickoff ) {
		return $none;
	}
	$kickoff_utc = clone $kickoff;
	$kickoff_utc->setTimezone( new DateTimeZone( 'UTC' ) );
	$kickoff_iso = $kickoff_utc->format( 'Y-m-d\TH:i:s\Z' );

	// Startwerte des Countdowns (ohne JavaScript sichtbar, das JS zählt weiter).
	$remaining = max( 0, $kickoff_utc->getTimestamp() - time() );
	$days      = (int) floor( $remaining / DAY_IN_SECONDS );
	$hours     = (int) floor( ( $remaining % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
	$mins      = (int) floor( ( $remaining % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

	// Eindeutige ID, falls das Widget mehrfach auf einer Seite steht.
	static $instance = 0;
	$instance++;
	$id = 'hbch-next-game-' . $instance;

	$team_a     = esc_html( $game['teamAName'] ?? '' );
	$team_b     = esc_html( $game['teamBName'] ?? '' );
	$cf         = hbch_get_setting( 'countdown_fields' );
	$show_venue = ! empty( $cf['venue']['enabled'] );
	$show_logos = ! empty( $cf['logos']['enabled'] );
	$dual_logo  = ! empty( $cf['dual_logo']['enabled'] );

	$logo_a = $show_logos ? hbch_team_logo_markup( $game['teamAName'] ?? '', $game['teamAId'] ?? '', $game['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ) . ' ' : '';
	$logo_b = $show_logos ? hbch_team_logo_markup( $game['teamBName'] ?? '', $game['teamBId'] ?? '', $game['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ) . ' ' : '';

	$date_text = hbch_format_game_date( $game, 'weekday' ) . ' • ' . hbch_format_game_time( $game ) . ' Uhr';
	$venue     = trim( (string) ( $game['venue'] ?? '' ) );
	if ( $show_venue && $venue !== '' ) {
		$date_text .= ' • ' . $venue;
	}

	ob_start();
	?>
	<table id="<?php echo esc_attr( $id ); ?>" class="hbch-next-game-widget hbch-table-plain" data-kickoff="<?php echo esc_attr( $kickoff_iso ); ?>">
		<tbody>
			<tr>
				<td colspan="7" class="hbch-next-game-title"><?php echo esc_html( hbch_get_setting( 'text_next_game_title' ) ); ?></td>
			</tr>
			<tr class="hbch-next-game-teams-row">
				<td class="hbch-next-game-team"><?php echo $logo_a . $team_a; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo-Markup und Namen sind bereits escaped. ?></td>
				<td class="hbch-next-game-vs"><?php echo esc_html( hbch_get_setting( 'text_next_game_vs' ) ); ?></td>
				<td class="hbch-next-game-team"><?php echo $logo_b . $team_b; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Logo-Markup und Namen sind bereits escaped. ?></td>
				<td class="hbch-next-game-spacer"></td>
				<td class="hbch-countdown-number hbch-next-game-days"><?php echo esc_html( sprintf( '%02d', $days ) ); ?></td>
				<td class="hbch-countdown-number hbch-next-game-hours"><?php echo esc_html( sprintf( '%02d', $hours ) ); ?></td>
				<td class="hbch-countdown-number hbch-next-game-mins"><?php echo esc_html( sprintf( '%02d', $mins ) ); ?></td>
			</tr>
			<tr class="hbch-next-game-labels-row">
				<td colspan="4" class="hbch-next-game-datetext"><?php echo esc_html( $date_text ); ?></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_days_label' ) ); ?></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_hours_label' ) ); ?></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_mins_label' ) ); ?></td>
			</tr>
		</tbody>
	</table>
	<?php
	return ob_get_clean() . hbch_render_games_jsonld( [ $game ] );
}

/**
 * [hbch_next_game team="slug"] — nächstes Spiel mit Live-Countdown.
 */
add_shortcode( 'hbch_next_game', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_next_game( hbch_get_team_id( $atts['team'] ) );
} );
