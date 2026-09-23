<?php
/**
 * includes/shortcodes.php
 *
 * Alle Shortcodes ausser dem ICS-Button (siehe ics.php).
 *
 * Die eigentliche Render-Logik steckt in benannten hbch_render_*-Funktionen,
 * die sowohl die Shortcodes als auch die Gutenberg-Blöcke (blocks.php)
 * direkt aufrufen — ohne Umweg über do_shortcode()/Shortcode-Tags.
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
 * Tabelle "zu spielende Spiele" im Team-Spielplan-Format. Wird für die
 * Team-Ebene ([hbch_team_next_games] bzw. Block "Team – Spielplan") und die
 * Vereins-Ebene mit layout="table" verwendet (dann mit den Spielen des ganzen
 * Vereins). Die Felder kommen immer aus den Einstellungen "Team-Spielplan"
 * (games_fields). $table_id muss pro Seite eindeutig sein.
 */
function hbch_render_games_table_next( array $games, $table_id ) {
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
		// Diese Zellen tragen absichtlich nicht die Klassen hbch-date-raw/-time-raw,
		// damit das Datums-JS sie nicht überschreibt.
		$is_live    = $show_live && hbch_is_game_live( $g );
		$is_forfait = ! $is_live && hbch_is_game_forfait( $g );

		if ( $is_live ) {
			$datetime_cells = '<td class="hbch-text-small hbch-live-cell" colspan="2">' . hbch_live_badge_markup( $g ) . '</td>';
		} elseif ( $is_forfait ) {
			$forfait_date = '';
			try {
				$forfait_date = ( new DateTime( $g['gameDateTime'] ?? 'now', new DateTimeZone( 'Europe/Zurich' ) ) )->format( 'd.m.y' );
			} catch ( Exception $e ) {
				$forfait_date = '';
			}
			// Kein "%" im Text: die Zelle wird Teil des sprintf-Formatstrings.
			$datetime_cells = '<td class="hbch-text-small hbch-forfait-cell" colspan="2">' . esc_html( $forfait_date ) . ' <span class="hbch-forfait-badge">Forfait</span></td>';
		} else {
			$datetime_cells = '<td class="hbch-text-small hbch-date-raw">%1$s</td><td class="hbch-text-small hbch-time-raw"></td>';
		}

		$row_class = 'hbch-game-row' . ( $is_live ? ' hbch-game-row-live' : '' ) . ( $is_forfait ? ' hbch-game-row-forfait' : '' );

		$rows .= sprintf(
			'<tr class="' . $row_class . '">'
				. $datetime_cells . '
				<td class="hbch-hidden-source">%2$s</td>
				<td class="hbch-team-cell hbch-align-right">%3$s %4$s</td>
				<td class="hbch-vs-cell hbch-priority-2"> : </td>
				<td class="hbch-team-cell hbch-align-left">%5$s %6$s</td>'
				. ( $show_venue_col ? '<td class="' . esc_attr( $venue_td_class ) . '">%7$s</td>' : '' )
				. ( $show_link      ? '<td class="' . esc_attr( $link_td_class )  . '">%8$s</td>' : '' ) . '
			</tr>',
			esc_html( $g['gameDateTime'] ?? '' ),
			esc_html( $g['gameStatus'] ?? '' ),
			hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ),
			hbch_team_name_markup( $g['teamAName'] ?? '', $g['teamANameShort'] ?? '', $short_names ),
			hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ),
			hbch_team_name_markup( $g['teamBName'] ?? '', $g['teamBNameShort'] ?? '', $short_names ),
			$venue_html,
			$link_html
		);
	}

	$venue_th = $show_venue_col ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['venue']['label'] ) . '</th>' : '';
	$link_th  = $show_link      ? '<th class="hbch-game-row hbch-priority-2"></th>' : '';

	return '<table id="' . esc_attr( $table_id ) . '" class="hbch-table-responsive"><tbody>
		<tr><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['date']['label'] ) . '</th><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['time']['label'] ) . '</th>
		<th colspan="3" class="hbch-game-row hbch-priority-2">' . esc_html( $f['matchup']['label'] ) . '</th>' . $venue_th . $link_th . '</tr>'
		. $rows . '</tbody></table>';
}

/**
 * Tabelle "gespielte Spiele" im Team-Spielplan-Format, siehe
 * hbch_render_games_table_next().
 */
function hbch_render_games_table_last( array $games, $table_id ) {
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
		$rows .= sprintf(
			'<tr class="hbch-game-row">
				<td class="hbch-text-small hbch-date-raw">%1$s</td>
				<td class="hbch-hidden-source hbch-time-raw"></td>
				<td class="hbch-hidden-source">%2$s</td>
				<td class="hbch-team-cell hbch-align-right">%3$s %4$s</td>
				<td class="hbch-result-cell hbch-priority-2"><span class="hbch-score-badge">%5$s : %6$s </span>(%7$s:%8$s)</td>
				<td class="hbch-team-cell hbch-align-left">%9$s %10$s</td>
				<td class="hbch-result-cell hbch-priority-1"><span class="hbch-score-badge">%5$s : %6$s </span>(%7$s:%8$s)</td>'
				. ( $show_venue_col ? '<td class="' . esc_attr( $venue_td_class ) . '">%11$s</td>' : '' )
				. ( $show_spect     ? '<td class="' . esc_attr( $spect_td_class ) . '">%12$s</td>' : '' )
				. ( $show_link      ? '<td class="' . esc_attr( $link_td_class )  . '">%13$s</td>' : '' ) . '
			</tr>',
			esc_html( $g['gameDateTime'] ?? '' ),
			esc_html( $g['gameStatus'] ?? '' ),
			hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ),
			hbch_team_name_markup( $g['teamAName'] ?? '', $g['teamANameShort'] ?? '', $short_names ),
			esc_html( $g['teamAScoreFT'] ?? '' ),
			esc_html( $g['teamBScoreFT'] ?? '' ),
			esc_html( $g['teamAScoreHT'] ?? '' ),
			esc_html( $g['teamBScoreHT'] ?? '' ),
			hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ),
			hbch_team_name_markup( $g['teamBName'] ?? '', $g['teamBNameShort'] ?? '', $short_names ),
			$venue_html,
			$spectators_txt,
			$link_html
		);
	}

	$venue_th = $show_venue_col ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['venue']['label'] ) . '</th>' : '';
	$spect_th = $show_spect     ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['spectators']['label'] ) . '</th>' : '';
	$link_th  = $show_link      ? '<th class="hbch-game-row hbch-priority-2">' . esc_html( $f['details']['label'] ) . '</th>' : '';

	return '<table id="' . esc_attr( $table_id ) . '" class="hbch-table-responsive"><tbody>
		<tr><th class="hbch-game-row hbch-priority-2">' . esc_html( $f['date']['label'] ) . '</th><th colspan="3" class="hbch-game-row hbch-priority-2">' . esc_html( $f['matchup']['label'] ) . '</th>'
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
 * Kommende Spiele über alle Teams des Vereins, inkl. JSON-LD. Ruft die
 * REST-Callback-Funktion direkt auf.
 *
 * $layout: "cards" (Default) = Kartenlook für die Startseite, gleiches Grid
 *   wie hbch_render_home_last_games() (Felder aus "Vereins-Spielplan").
 *   "table" = Tabellenlook wie beim Team-Spielplan inkl. Mobile-Ansicht,
 *   z. B. für die Gesamtspielplan-Seite (Felder aus "Team-Spielplan").
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_next_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$limit = ( $limit !== '' && $limit !== null ) ? $limit : hbch_get_setting( 'default_games_limit' );
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
	$req = new WP_REST_Request( 'GET', '/handballch/v1/next-games' );
	$req->set_param( 'limit', intval( $limit ) );
	$req->set_param( 'exclude', $exclude );
	if ( $show_live ) {
		$req->set_param( 'include_live', true );
	}
	$games = hbch_next_games( $req )->get_data();

	if ( $table ) {
		return hbch_render_games_table_next( $games, 'hbch-club-games-next' ) . hbch_render_games_jsonld( $games );
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

		// Das LIVE-Badge ersetzt nur die sichtbare Anzeige, die versteckte
		// hbch-date-raw-Zelle behält den Rohwert.
		$is_live          = $show_live && hbch_is_game_live( $g );
		$datetime_display = $is_live ? hbch_live_badge_markup( $g ) : '%1$s';

		// Gleiches Grid wie hbch_render_home_last_games(): Team A | Mitte | Team B.
		$rows .= sprintf(
			'<tr>
				<td class="hbch-text-small hbch-date-raw hbch-hidden-source">%1$s</td>
				<td class="hbch-text-small hbch-time-raw hbch-hidden-source"></td>
				<td class="hbch-hidden-source">%2$s</td>
				<td class="hbch-cell-padded">
					<div class="hbch-home-next-game"><small><div class="hbch-home-result-grid">
						<div class="hbch-result-team">%3$s %4$s</div>
						<div class="hbch-result-center">
							<span class="hbch-league">%5$s</span>
							<span class="hbch-game-datetime">' . $datetime_display . '</span>
							<span class="hbch-result-meta%9$s">
								<span class="hbch-result-venue">%6$s</span>
							</span>
						</div>
						<div class="hbch-result-team">%7$s %8$s</div>
					</div></small></div>
				</td>
			</tr>',
			esc_html( $g['gameDateTime'] ?? '' ),
			esc_html( $g['gameStatus'] ?? '' ),
			hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo ),
			$name_a,
			esc_html( $g['leagueShort'] ?? '' ),
			$venue_html,
			hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo ),
			$name_b,
			$mobile_class
		);
	}

	$thead = '<thead><tr>
			<th scope="col" class="screen-reader-text">Datum</th>
			<th scope="col" class="screen-reader-text">Zeit</th>
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
 *   hbch_render_home_next_games()).
 * $limit: leer = Standard-Anzahl aus den Einstellungen.
 */
function hbch_render_home_last_games( $limit = '', $exclude = '', $layout = 'cards' ) {
	$limit = ( $limit !== '' && $limit !== null ) ? $limit : hbch_get_setting( 'default_games_limit' );

	$req = new WP_REST_Request( 'GET', '/handballch/v1/last-games' );
	$req->set_param( 'limit', intval( $limit ) );
	$req->set_param( 'exclude', $exclude );
	$games = hbch_last_games( $req )->get_data();

	if ( $layout === 'table' ) {
		return hbch_render_games_table_last( $games, 'hbch-club-games-last' );
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

		$rows .= sprintf(
			'<tr>
				<td class="hbch-text-small hbch-date-raw hbch-hidden-source">%1$s</td>
				<td class="hbch-text-small hbch-time-raw hbch-hidden-source"></td>
				<td class="hbch-hidden-source">%2$s</td>
				<td class="hbch-cell-padded">
					<div class="hbch-home-last-game"><small><div class="hbch-home-result-grid">
						<div class="hbch-result-team">%3$s %4$s</div>
						<div class="hbch-result-center">
							<span class="hbch-league">%5$s</span>
							<big><strong class="hbch-score-result">%6$s:%7$s</strong></big>
							<span class="hbch-result-meta%12$s">
								<span class="hbch-result-meta-line"><span class="hbch-game-date-text">%1$s</span>%8$s</span>
								<span class="hbch-result-venue">%9$s</span>
							</span>
						</div>
						<div class="hbch-result-team">%10$s %11$s</div>
					</div></small></div>
				</td>
			</tr>',
			esc_html( $g['gameDateTime'] ?? '' ),
			esc_html( $g['gameStatus'] ?? '' ),
			hbch_team_logo_markup( $g['teamAName'] ?? '', $g['teamAId'] ?? '', $g['clubTeamAId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo ),
			$name_a,
			esc_html( $g['leagueShort'] ?? '' ),
			esc_html( $g['teamAScoreFT'] ?? '' ),
			esc_html( $g['teamBScoreFT'] ?? '' ),
			$spect_html,
			$venue_html,
			hbch_team_logo_markup( $g['teamBName'] ?? '', $g['teamBId'] ?? '', $g['clubTeamBId'] ?? '', 'hbch-team-logo-score', 90, $dual_logo ),
			$name_b,
			$mobile_class
		);
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
 * [hbch_next_game team="slug"] — nächstes Spiel mit Live-Countdown.
 * Die Daten kommen serverseitig, nur der Countdown läuft im Browser.
 */
add_shortcode( 'hbch_next_game', function ( $atts ) {
	$atts    = shortcode_atts( [ 'team' => '' ], $atts );
	$team_id = hbch_get_team_id( $atts['team'] );
	$game    = hbch_fetch_next_game( $team_id );

	if ( ! $game || empty( $game['gameDateTime'] ) ) {
		return '<table class="hbch-next-game-widget hbch-table-plain"><tbody><tr><td>' . esc_html( hbch_get_setting( 'text_next_game_none' ) ) . '</td></tr></tbody></table>';
	}

	// Eindeutige ID, falls der Shortcode mehrfach auf einer Seite steht.
	static $instance = 0;
	$instance++;
	$id = 'hbch-next-game-' . $instance;

	$team_a     = esc_html( $game['teamAName'] ?? '' );
	$team_b     = esc_html( $game['teamBName'] ?? '' );
	$venue      = esc_html( $game['venue'] ?? '' );
	$cf         = hbch_get_setting( 'countdown_fields' );
	$show_venue = ! empty( $cf['venue']['enabled'] );
	$show_logos = ! empty( $cf['logos']['enabled'] );
	$dual_logo  = ! empty( $cf['dual_logo']['enabled'] );

	$logo_a = $show_logos ? hbch_team_logo_markup( $game['teamAName'] ?? '', $game['teamAId'] ?? '', $game['clubTeamAId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ) . ' ' : '';
	$logo_b = $show_logos ? hbch_team_logo_markup( $game['teamBName'] ?? '', $game['teamBId'] ?? '', $game['clubTeamBId'] ?? '', 'hbch-team-logo-sm', 60, $dual_logo, 50 ) . ' ' : '';

	// gameDateTime ist naive Schweizer Ortszeit: serverseitig nach UTC umrechnen,
	// sonst läuft der Countdown im Sommer 2h, im Winter 1h falsch.
	try {
		$kickoff_utc = new DateTime( $game['gameDateTime'], new DateTimeZone( 'Europe/Zurich' ) );
		$kickoff_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		$game_utc_iso = esc_js( $kickoff_utc->format( 'Y-m-d\TH:i:s' ) );
	} catch ( Exception $e ) {
		$game_utc_iso = esc_js( $game['gameDateTime'] );
	}

	ob_start();
	?>
	<table id="<?php echo esc_attr( $id ); ?>" class="hbch-next-game-widget hbch-table-plain">
		<tbody>
			<tr>
				<td colspan="7" class="hbch-next-game-title"><?php echo esc_html( hbch_get_setting( 'text_next_game_title' ) ); ?></td>
			</tr>
			<tr class="hbch-next-game-teams-row">
				<td class="hbch-next-game-team"><?php echo $logo_a . $team_a; ?></td>
				<td class="hbch-next-game-vs"><?php echo esc_html( hbch_get_setting( 'text_next_game_vs' ) ); ?></td>
				<td class="hbch-next-game-team"><?php echo $logo_b . $team_b; ?></td>
				<td class="hbch-next-game-spacer"></td>
				<td class="hbch-countdown-number hbch-next-game-days">00</td>
				<td class="hbch-countdown-number hbch-next-game-hours">00</td>
				<td class="hbch-countdown-number hbch-next-game-mins">00</td>
			</tr>
			<tr class="hbch-next-game-labels-row">
				<td colspan="4" class="hbch-next-game-datetext"></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_days_label' ) ); ?></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_hours_label' ) ); ?></td>
				<td class="hbch-next-game-label"><?php echo esc_html( hbch_get_setting( 'text_mins_label' ) ); ?></td>
			</tr>
		</tbody>
	</table>
	<script>
	(function () {
		var el = document.getElementById('<?php echo esc_js( $id ); ?>');
		if ( ! el ) { return; }
		// Echter UTC-Zeitpunkt (siehe PHP oben), Anzeige zurück in Europe/Zurich.
		var dt = new Date('<?php echo $game_utc_iso; ?>Z');

		var dateStr = dt.toLocaleDateString('de-CH', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'Europe/Zurich' });
		var timeStr = dt.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Zurich' });
		el.querySelector('.hbch-next-game-datetext').textContent = dateStr + ' \u2022 ' + timeStr + ' Uhr'<?php echo $show_venue ? " + ' \\u2022 ' + '" . esc_js( $venue ) . "'" : ''; ?>;

		var elDays  = el.querySelector('.hbch-next-game-days');
		var elHours = el.querySelector('.hbch-next-game-hours');
		var elMins  = el.querySelector('.hbch-next-game-mins');

		function tick() {
			var diff = dt.getTime() - Date.now();
			if (diff <= 0) {
				elDays.textContent = '00';
				elHours.textContent = '00';
				elMins.textContent = '00';
				clearInterval(timer);
				return;
			}
			var days  = Math.floor(diff / (1000 * 60 * 60 * 24));
			var hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
			var mins  = Math.floor((diff / (1000 * 60)) % 60);
			elDays.textContent  = String(days).padStart(2, '0');
			elHours.textContent = String(hours).padStart(2, '0');
			elMins.textContent  = String(mins).padStart(2, '0');
		}

		tick();
		var timer = setInterval(tick, 60000);
	})();
	</script>
	<?php
	return ob_get_clean() . hbch_render_games_jsonld( [ $game ] );
} );
