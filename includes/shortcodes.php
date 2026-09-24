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
 * Tabelle "zu spielende Spiele" im Team-Spielplan-Format. Wird für die
 * Team-Ebene ([hbch_team_next_games] bzw. Block "Team – Spielplan") und die
 * Vereins-Ebene mit layout="table" verwendet (dann mit den Spielen des ganzen
 * Vereins). Die Felder kommen immer aus den Einstellungen "Team-Spielplan"
 * (games_fields). $table_id muss pro Seite eindeutig sein. $show_league
 * blendet eine zusätzliche Liga-Spalte nach Datum/Zeit ein (nur für die
 * Vereins-Ebene relevant, da dort mehrere Ligen gemischt vorkommen).
 */
function hbch_render_games_table_next( array $games, $table_id, $show_league = false ) {
	$f = hbch_get_setting( 'games_fields' );

	$show_round   = ! empty( $f['round']['enabled_next'] );
	$show_type    = ! empty( $f['gametype']['enabled_next'] );
	$show_addr    = ! empty( $f['venue_address']['enabled_next'] );
	$show_venue   = ! empty( $f['venue']['enabled_next'] );
	$show_link    = ! empty( $f['link']['enabled_next'] );
	$short_names  = ! empty( $f['short_names']['enabled_next'] );
	$show_spect   = ! empty( $f['spectators']['enabled_next'] );
	$dual_logo    = ! empty( $f['dual_logo']['enabled_next'] );
	$show_live    = ! empty( $f['live_badge']['enabled_next'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// Die Halle-Spalte fasst Halle/Runde/Spielart/Zuschauer zusammen und
	// erscheint nur, wenn mindestens eine dieser Optionen aktiv ist.
	$show_venue_col = $show_venue || $show_round || $show_type || $show_spect;
	$venue_td_class = 'hbch-text-small' . ( ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );
	$link_td_class  = 'hbch-text-small' . ( ! empty( $f['link']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html = ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );
		if ( $show_spect && isset( $g['spectators'] ) && $g['spectators'] > 0 ) {
			$venue_html .= ' <span class="hbch-extra-info">· ' . esc_html( $g['spectators'] ) . ' ' . esc_html( $spect_label ) . ' (erwartet)</span>';
		}
		$link_html = $show_link ? ' ' . hbch_matchcenter_link_markup( $g['gameId'] ?? '' ) : '';

		// LIVE-Badge und Forfait ersetzen Datum+Zeit durch EINE Zelle (colspan=2).
		$is_live    = $show_live && hbch_is_game_live( $g );
		$is_forfait = ! $is_live && hbch_is_game_forfait( $g );

		if ( $is_live ) {
			$datetime_cells = '<td class="hbch-text-small hbch-live-cell" colspan="2">' . hbch_live_badge_markup( $g ) . '</td>';
		} elseif ( $is_forfait ) {
			$datetime_cells = '<td class="hbch-text-small hbch-forfait-cell" colspan="2">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . ' <span class="hbch-forfait-badge">Forfait</span></td>';
		} else {
			$datetime_cells = '<td class="hbch-text-small">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . '</td>'
				. '<td class="hbch-text-small"><strong>' . esc_html( hbch_format_game_time( $g ) ) . '</strong></td>';
		}

		$row_class = 'hbch-game-row' . ( $is_live ? ' hbch-game-row-live' : '' ) . ( $is_forfait ? ' hbch-game-row-forfait' : '' );

		$rows .= '<tr class="' . esc_attr( $row_class ) . '">'
			. $datetime_cells
			. ( $show_league ? '<td class="hbch-text-small hbch-league-cell">' . esc_html( $g['leagueShort'] ?? '' ) . '</td>' : '' )
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-team-cell hbch-align-right">'
				. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamAName'] ?? '', $g['teamANameShort'] ?? '', $short_names )
			. '</td>'
			. '<td class="hbch-vs-cell hbch-priority-2"> : </td>'
			. '<td class="hbch-team-cell hbch-align-left">'
				. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamBName'] ?? '', $g['teamBNameShort'] ?? '', $short_names )
			. '</td>'
			. ( $show_venue_col ? '<td class="' . esc_attr( $venue_td_class ) . '">' . $venue_html . '</td>' : '' )
			. ( $show_link ? '<td class="' . esc_attr( $link_td_class ) . '">' . $link_html . '</td>' : '' )
			. '</tr>';
	}

	$venue_th  = $show_venue_col ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['venue']['label'] ) . '</th>' : '';
	$link_th   = $show_link      ? '<th class="hbch-game-row hbch-priority-2"></th>' : '';
	$league_th = $show_league    ? '<th class="hbch-game-row hbch-priority-2"">Liga</th>' : '';

	return '<table id="' . esc_attr( $table_id ) . '" class="hbch-table-responsive"><tbody>
		<tr><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['date']['label'] ) . '</th><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['time']['label'] ) . '</th>' . $league_th . '
		<th colspan="3" class="hbch-game-row hbch-priority-2">' . esc_html( $f['matchup']['label'] ) . '</th>' . $venue_th . $link_th . '</tr>'
		. $rows . '</tbody></table>';
}

/**
 * Tabelle "gespielte Spiele" im Team-Spielplan-Format, siehe
 * hbch_render_games_table_next(). $show_league siehe dort.
 */
function hbch_render_games_table_last( array $games, $table_id, $show_league = false ) {
	$f = hbch_get_setting( 'games_fields' );

	$show_round   = ! empty( $f['round']['enabled_last'] );
	$show_type    = ! empty( $f['gametype']['enabled_last'] );
	$show_addr    = ! empty( $f['venue_address']['enabled_last'] );
	$show_venue   = ! empty( $f['venue']['enabled_last'] );
	$show_link    = ! empty( $f['link']['enabled_last'] );
	$short_names  = ! empty( $f['short_names']['enabled_last'] );
	$show_spect   = ! empty( $f['spectators']['enabled_last'] );
	$dual_logo    = ! empty( $f['dual_logo']['enabled_last'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// Halle/Zuschauer/Details: jede Spalte (Kopf + Zelle) nur, wenn sie etwas anzeigen kann.
	$show_venue_col = $show_venue || $show_round || $show_type;
	$venue_td_class = 'hbch-text-small' . ( ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );
	$spect_td_class = 'hbch-text-small' . ( ! empty( $f['spectators']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );
	$link_td_class  = 'hbch-text-small' . ( ! empty( $f['link']['show_mobile'] ) ? '' : ' hbch-mobile-hide-field' );

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html     = ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );
		$spectators_txt = $show_spect ? esc_html( $g['spectators'] ?? '' ) . ' ' . esc_html( $spect_label ) : '';
		$link_html      = $show_link ? ' ' . hbch_matchcenter_link_markup( $g['gameId'] ?? '' ) : '';

		$score_ft = esc_html( $g['teamAScoreFT'] ?? '' ) . ' : ' . esc_html( $g['teamBScoreFT'] ?? '' ) . ' ';
		$score_ht = '(' . esc_html( $g['teamAScoreHT'] ?? '' ) . ':' . esc_html( $g['teamBScoreHT'] ?? '' ) . ')';
		$result   = '<span class="hbch-score-badge">' . $score_ft . '</span>' . $score_ht;

		$rows .= '<tr class="hbch-game-row">'
			. '<td class="hbch-text-small">' . esc_html( hbch_game_date_text( $g, 'short' ) ) . '</td>'
			. ( $show_league ? '<td class="hbch-text-small hbch-league-cell">' . esc_html( $g['leagueShort'] ?? '' ) . '</td>' : '' )
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-team-cell hbch-align-right">'
				. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamAName'] ?? '', $g['teamANameShort'] ?? '', $short_names )
			. '</td>'
			. '<td class="hbch-result-cell hbch-priority-2">' . $result . '</td>'
			. '<td class="hbch-team-cell hbch-align-left">'
				. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 )
				. ' ' . hbch_team_name_markup( $g['teamBName'] ?? '', $g['teamBNameShort'] ?? '', $short_names )
			. '</td>'
			. '<td class="hbch-result-cell hbch-priority-1">' . $result . '</td>'
			. ( $show_venue_col ? '<td class="' . esc_attr( $venue_td_class ) . '">' . $venue_html . '</td>' : '' )
			. ( $show_spect ? '<td class="' . esc_attr( $spect_td_class ) . '">' . $spectators_txt . '</td>' : '' )
			. ( $show_link ? '<td class="' . esc_attr( $link_td_class ) . '">' . $link_html . '</td>' : '' )
			. '</tr>';
	}

	$venue_th  = $show_venue_col ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['venue']['label'] ) . '</th>' : '';
	$spect_th  = $show_spect     ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['spectators']['label'] ) . '</th>' : '';
	$link_th   = $show_link      ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['details']['label'] ) . '</th>' : '';
	$league_th = $show_league    ? '<th class="hbch-game-row hbch-priority-2"">Liga</th>' : '';

	return '<table id="' . esc_attr( $table_id ) . '" class="hbch-table-responsive"><tbody>
		<tr><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['date']['label'] ) . '</th>' . $league_th . '<th colspan="3" class="hbch-game-row hbch-priority-2">' . esc_html( $f['matchup']['label'] ) . '</th>'
		. $venue_th . $spect_th . $link_th . '</tr>'
		. $rows . '</tbody></table>';
}

/**
 * Zu spielende Spiele eines Teams (#hbch-team-games-next), inkl. JSON-LD.
 */
function hbch_render_team_next_games( $team_id ) {
	$games = $team_id ? hbch_fetch_team_games( $team_id, 'planned' ) : [];
	return hbch_render_games_table_next( $games, 'hbch-team-games-next' ) . hbch_render_games_jsonld( $games );
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
	return hbch_render_games_table_last( $games, 'hbch-team-games-last' );
}

/**
 * [hbch_team_last_games team="slug"]
 */
add_shortcode( 'hbch_team_last_games', function ( $atts ) {
	$atts = shortcode_atts( [ 'team' => '' ], $atts );
	return hbch_render_team_last_games( hbch_get_team_id( $atts['team'] ) );
} );

/**
 * Kommende Spiele über alle Teams des Vereins, inkl. JSON-LD. Die Spiele
 * kommen aus hbch_get_next_games() (rest.php).
 *
 * $layout: "cards" (Default) = Kartenlook für die Startseite, gleiches Grid
 *   wie hbch_render_home_last_games() (Felder aus "Vereins-Spielplan").
 *   "table" = Tabellenlook wie beim Team-Spielplan inkl. Mobile-Ansicht,
 *   z. B. für die Gesamtspielplan-Seite (Felder aus "Team-Spielplan"), inkl.
 *   Liga-Spalte, da hier mehrere Ligen gemischt vorkommen.
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_next_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$table = ( $layout === 'table' );
	$f     = hbch_get_setting( $table ? 'games_fields' : 'home_fields' );

	$show_round   = ! empty( $f['round']['enabled_next'] );
	$show_type    = ! empty( $f['gametype']['enabled_next'] );
	$show_addr    = ! empty( $f['venue_address']['enabled_next'] );
	$show_venue   = ! empty( $f['venue']['enabled_next'] );
	$show_spect   = ! empty( $f['spectators']['enabled_next'] );
	$dual_logo    = ! empty( $f['dual_logo']['enabled_next'] );
	$show_live    = ! empty( $f['live_badge']['enabled_next'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// Mit LIVE-Badge bleibt ein laufendes Spiel in der Liste ("include_live").
	$games = hbch_get_next_games( $limit, $exclude, $show_live );

	if ( $table ) {
		return hbch_render_games_table_next( $games, 'hbch-club-games-next', true ) . hbch_render_games_jsonld( $games );
	}

	// "Auf Mobile anzeigen" bei Halle steuert den Meta-Block (Halle/Zuschauer/Runde/Spielart).
	$mobile_class = ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-hide-mobile';

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html = '<span class="hbch-venue">' . ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . '</span>' . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );
		if ( $show_spect && isset( $g['spectators'] ) && $g['spectators'] > 0 ) {
			$venue_html .= ' <span class="hbch-extra-info">· ' . esc_html( $g['spectators'] ) . ' ' . esc_html( $spect_label ) . ' (erwartet)</span>';
		}
		// Die Vereins-Spielliste liefert keine Kurznamen: voller Name, auf Mobile ausgeblendet.
		$name_a = '<span class="hbch-hide-mobile">' . esc_html( $g['teamAName'] ?? '' ) . '</span>';
		$name_b = '<span class="hbch-hide-mobile">' . esc_html( $g['teamBName'] ?? '' ) . '</span>';

		// Datum (untereinander mit der fetten Uhrzeit) oder, solange das Spiel läuft, das LIVE-Badge.
		if ( $show_live && hbch_is_game_live( $g ) ) {
			$datetime_display = hbch_live_badge_markup( $g );
		} else {
			$datetime_display = esc_html( hbch_game_date_text( $g, 'numeric' ) ) . '<br><strong>' . esc_html( hbch_format_game_time( $g ) ) . '</strong>';
		}

		// Gleiches Grid wie hbch_render_home_last_games(): Team A | Mitte | Team B.
		$rows .= '<tr>'
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-cell-padded">'
				. '<div class="hbch-home-next-game"><small><div class="hbch-home-result-grid">'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_a
					. '</div>'
					. '<div class="hbch-result-center">'
						. '<span class="hbch-league">' . esc_html( $g['leagueShort'] ?? '' ) . '</span>'
						. '<span class="hbch-game-datetime">' . $datetime_display . '</span>'
						. '<span class="hbch-result-meta' . $mobile_class . '">'
							. '<span class="hbch-result-venue">' . $venue_html . '</span>'
						. '</span>'
					. '</div>'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_b
					. '</div>'
				. '</div></small></div>'
			. '</td>'
			. '</tr>';
	}

	$thead = '<thead><tr>
			<th scope="col" class="screen-reader-text">Status</th>
			<th scope="col" class="screen-reader-text">Begegnung</th>
		</tr></thead>';

	return '<table class="hbch-home-games-next hbch-table-plain">' . $thead . '<tbody>' . $rows . '</tbody></table>' . hbch_render_games_jsonld( $games );
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
 * $layout: "cards" (Default) = Grid mit drei Spalten (Team A | Mitte | Team
 *   B). Die Mitte ist so breit wie ihr Inhalt, die beiden Team-Spalten
 *   teilen sich den Rest gleichmässig (siehe .hbch-home-result-grid in
 *   public.css). "table" = Tabellenlook wie beim Team-Spielplan (siehe
 *   hbch_render_home_next_games()), inkl. Liga-Spalte.
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_last_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$games = hbch_get_last_games( $limit, $exclude );

	if ( $layout === 'table' ) {
		return hbch_render_games_table_last( $games, 'hbch-club-games-last', true );
	}

	$f = hbch_get_setting( 'home_fields' );
	$show_round   = ! empty( $f['round']['enabled_last'] );
	$show_type    = ! empty( $f['gametype']['enabled_last'] );
	$show_addr    = ! empty( $f['venue_address']['enabled_last'] );
	$show_venue   = ! empty( $f['venue']['enabled_last'] );
	$show_spect   = ! empty( $f['spectators']['enabled_last'] );
	$dual_logo    = ! empty( $f['dual_logo']['enabled_last'] );
	$round_prefix = $f['round']['label'];
	$spect_label  = $f['spectators']['label'];

	// Datum/Zuschauer/Halle/Runde/Spielart teilen sich eine Hülle: "Auf Mobile
	// anzeigen" bei Halle steuert den ganzen Meta-Block.
	$mobile_class = ! empty( $f['venue']['show_mobile'] ) ? '' : ' hbch-hide-mobile';

	$rows = '';
	foreach ( $games as $g ) {
		$venue_html = '<span class="hbch-venue">' . ( $show_venue ? hbch_venue_display( $g, $show_addr ) : '' ) . '</span>' . hbch_extra_line( $g, $show_round, $show_type, $round_prefix );
		// Zuschauer stehen in derselben Zeile wie das Datum (Trennstrich per CSS).
		$spect_html = ( $show_spect && ! empty( $g['spectators'] ) )
			? '<span class="hbch-result-spectators"><strong>' . esc_html( $g['spectators'] ) . '</strong> ' . esc_html( $spect_label ) . '</span>'
			: '';
		$name_a = '<span class="hbch-hide-mobile">' . esc_html( $g['teamAName'] ?? '' ) . '</span>';
		$name_b = '<span class="hbch-hide-mobile">' . esc_html( $g['teamBName'] ?? '' ) . '</span>';

		$rows .= '<tr>'
			. '<td class="hbch-hidden-source">' . esc_html( $g['gameStatus'] ?? '' ) . '</td>'
			. '<td class="hbch-cell-padded">'
				. '<div class="hbch-home-last-game"><small><div class="hbch-home-result-grid">'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_a
					. '</div>'
					. '<div class="hbch-result-center">'
						. '<span class="hbch-league">' . esc_html( $g['leagueShort'] ?? '' ) . '</span>'
						. '<big><strong class="hbch-score-result">' . esc_html( $g['teamAScoreFT'] ?? '' ) . ':' . esc_html( $g['teamBScoreFT'] ?? '' ) . '</strong></big>'
						. '<span class="hbch-result-meta' . $mobile_class . '">'
							. '<span class="hbch-result-meta-line"><span class="hbch-game-date-text">' . esc_html( hbch_game_date_text( $g, 'long' ) ) . '</span>' . $spect_html . '</span>'
							. '<span class="hbch-result-venue">' . $venue_html . '</span>'
						. '</span>'
					. '</div>'
					. '<div class="hbch-result-team">'
						. hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo )
						. ' ' . $name_b
					. '</div>'
				. '</div></small></div>'
			. '</td>'
			. '</tr>';
	}

	return '<table class="hbch-home-games-last hbch-table-plain"><tbody>' . $rows . '</tbody></table>';
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
