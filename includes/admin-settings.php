<?php
/**
 * includes/admin-settings.php
 *
 * Das komplette Adminpanel (Reiter, Feldtabellen, Live-Vorschau, Diagnose,
 * Anleitung/Changelog mit Markdown-Renderer, Sanitize-Logik).
 * Wird nur geladen, wenn is_admin() true ist.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function hbch_settings_tabs() {
	return [
		'allgemein'  => [ 'label' => 'Allgemein & API',  'page' => 'hbch-tab-allgemein' ],
		'rangliste'  => [ 'label' => 'Rangliste',         'page' => 'hbch-tab-rangliste' ],
		'teamspiele' => [ 'label' => 'Team-Spielplan',    'page' => 'hbch-tab-teamspiele' ],
		'startseite' => [ 'label' => 'Vereins-Spielplan', 'page' => 'hbch-tab-startseite' ],
		'countdown'  => [ 'label' => 'Countdown',         'page' => 'hbch-tab-countdown' ],
		'ics'        => [ 'label' => 'ICS-Export',        'page' => 'hbch-tab-ics' ],
		'farben'     => [ 'label' => 'Farben',            'page' => 'hbch-tab-farben' ],
		'diagnose'   => [ 'label' => 'Diagnose',          'page' => null ],
		'anleitung'  => [ 'label' => 'Anleitung',         'page' => null ], // README.md
		'changelog'  => [ 'label' => 'Changelog',         'page' => null ], // CHANGELOG.md
	];
}

function hbch_settings_url( $tab = '' ) {
	$url = 'options-general.php?page=hbch-settings';
	return admin_url( $tab !== '' ? $url . '&tab=' . $tab : $url );
}

function hbch_get_preview_team_slug() {
	$teams = hbch_get_setting( 'teams' );
	if ( empty( $teams ) ) {
		return '';
	}
	$requested = isset( $_GET['hbch_preview_team'] ) ? sanitize_key( wp_unslash( $_GET['hbch_preview_team'] ) ) : '';
	if ( $requested !== '' && isset( $teams[ $requested ] ) ) {
		return $requested;
	}
	return (string) array_key_first( $teams );
}

function hbch_render_preview_team_selector( $tab ) {
	$teams = hbch_get_setting( 'teams' );
	if ( empty( $teams ) ) {
		echo '<p><em>Kein Team konfiguriert (Reiter "Allgemein & API").</em></p>';
		return;
	}
	$selected = hbch_get_preview_team_slug();
	$base_url = admin_url( "options-general.php?page=hbch-settings&tab={$tab}&hbch_preview_team=" );
	$onchange = 'window.location.href=' . wp_json_encode( $base_url ) . '+encodeURIComponent(this.value)';
	printf( '<p><label>Vorschau-Team: <select onchange="%s">', esc_attr( $onchange ) );
	foreach ( $teams as $slug => $id ) {
		printf( '<option value="%1$s" %2$s>%1$s (ID %3$s)</option>', esc_attr( $slug ), selected( $selected, $slug, false ), esc_html( $id ) );
	}
	echo '</select></label></p>';
}

/**
 * Live-Vorschau. $extra_class hängt eine CSS-Klasse an den Kasten
 * (z. B. "hbch-admin-preview--open-menu", siehe admin.css).
 */
function hbch_render_shortcode_preview( $html, $extra_class = '' ) {
	printf(
		'<div class="hbch-admin-preview%1$s">%2$s</div>
		<script>(function () { %3$s })();</script>',
		$extra_class !== '' ? ' ' . esc_attr( $extra_class ) : '',
		$html,
		hbch_get_highlight_js_body()
	);
}

/**
 * Farbfeld einer Farbrolle. data-hbch-var verknüpft das Feld mit der
 * CSS-Variable für die Sofort-Vorschau.
 */
function hbch_render_color_field( $role_id ) {
	$roles = hbch_color_roles();
	if ( ! isset( $roles[ $role_id ] ) ) {
		return;
	}
	$role = $roles[ $role_id ];
	printf(
		'<span class="hbch-color-field"><input type="color" name="%1$s[%2$s]" value="%3$s" data-hbch-var="%4$s"><code>%4$s</code></span><p class="description">%5$s</p>',
		HBCH_OPTION,
		esc_attr( $role['setting'] ),
		esc_attr( hbch_color_value( $role ) ),
		esc_attr( $role['var'] ),
		esc_html( $role['help'] )
	);
}

add_action( 'admin_menu', function () {
	add_options_page(
		'handball.ch Club-Widgets',
		'handball.ch Club-Widgets',
		'manage_options',
		'hbch-settings',
		'hbch_render_settings_page'
	);
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( $hook !== 'settings_page_hbch-settings' ) {
		return;
	}
	wp_enqueue_style( 'hbch-public', HBCH_URL . 'assets/css/public.css', [], HBCH_VERSION );
	wp_add_inline_style( 'hbch-public', hbch_get_dynamic_inline_css() );
	wp_enqueue_style( 'hbch-admin', HBCH_URL . 'assets/css/admin.css', [ 'hbch-public' ], HBCH_VERSION );
} );

/**
 * Reiter "Anleitung" (README.md) und "Changelog" (CHANGELOG.md): rendert die
 * Markdown-Datei aus dem Plugin-Ordner. Der Dateiname muss exakt stimmen
 * (Linux unterscheidet Gross-/Kleinschreibung).
 */
function hbch_render_markdown_file_tab( $filename ) {
	$path = HBCH_PATH . $filename;

	if ( ! file_exists( $path ) ) {
		echo '<p>' . esc_html( $filename ) . ' wurde im Plugin-Ordner nicht gefunden.</p>';
		return;
	}

	echo '<div class="hbch-docs-readme hbch-admin-card">' . hbch_markdown_to_html( file_get_contents( $path ) ) . '</div>';
}

function hbch_render_diagnose_tab() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['hbch_diagnose_clear_cache'] ) && check_admin_referer( 'hbch_diagnose_clear_cache_action', 'hbch_diagnose_nonce' ) ) {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_hbch_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_hbch_' ) . '%'
			)
		);
		printf( '<div class="notice notice-success"><p>Cache geleert (%d Einträge entfernt, inkl. Zeitstempel-Zeilen).</p></div>', (int) ( $deleted / 2 ) );
	}

	$raw_json      = null;
	$raw_error     = null;
	$selected_team = '';
	$selected_kind = 'games';
	if ( isset( $_POST['hbch_diagnose_fetch'] ) && check_admin_referer( 'hbch_diagnose_fetch_action', 'hbch_diagnose_nonce' ) ) {
		$teams         = hbch_get_setting( 'teams' );
		$selected_team = isset( $_POST['hbch_diagnose_team'] ) ? sanitize_key( wp_unslash( $_POST['hbch_diagnose_team'] ) ) : '';
		$selected_kind = isset( $_POST['hbch_diagnose_kind'] ) ? sanitize_key( wp_unslash( $_POST['hbch_diagnose_kind'] ) ) : 'games';
		$team_id       = $teams[ $selected_team ] ?? 0;

		if ( ! $team_id ) {
			$raw_error = 'Unbekanntes Team ausgewählt.';
		} else {
			$endpoint = $selected_kind === 'group'
				? "https://clubapi.handball.ch/rest/v1/teams/{$team_id}/group"
				: "https://clubapi.handball.ch/rest/v1/teams/{$team_id}/games";

			$response = wp_remote_get( $endpoint, [
				'timeout'   => 8,
				'sslverify' => hbch_ssl_verify(),
				'headers'   => hbch_api_auth_header(),
			] );

			if ( is_wp_error( $response ) ) {
				$raw_error = $response->get_error_message();
			} else {
				$code     = wp_remote_retrieve_response_code( $response );
				$body     = wp_remote_retrieve_body( $response );
				$data     = json_decode( $body, true );
				$raw_json = "HTTP {$code} — {$endpoint}\n\n" . ( $data !== null ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $body );
			}
		}
	}

	$team_check_results = null;
	if ( isset( $_POST['hbch_diagnose_check_teams'] ) && check_admin_referer( 'hbch_diagnose_check_teams_action', 'hbch_diagnose_nonce' ) ) {
		$team_check_results = hbch_validate_all_teams();
	}

	$teams = hbch_get_setting( 'teams' );
	?>
	<div class="hbch-admin-card">
		<h2>Cache leeren</h2>
		<p>Löscht sofort alle zwischengespeicherten API-Antworten dieses Plugins (Rangliste, Spielpläne, nächstes Spiel, ICS-Kalender). Nützlich, wenn handball.ch neue Daten hat, aber der Cache noch läuft. Logo-Dateien im Uploads-Ordner sind davon nicht betroffen.</p>
		<form method="post">
			<?php wp_nonce_field( 'hbch_diagnose_clear_cache_action', 'hbch_diagnose_nonce' ); ?>
			<?php submit_button( 'Cache jetzt leeren', 'secondary', 'hbch_diagnose_clear_cache', false ); ?>
		</form>
	</div>

	<div class="hbch-admin-card">
		<h2>Rohdaten eines Teams live abrufen</h2>
		<p>Ruft den gewählten Endpunkt direkt bei handball.ch ab — <strong>ohne</strong> den Plugin-Cache zu nutzen oder zu füllen — und zeigt die rohe JSON-Antwort. Endpunkt "Spiele" liefert die Daten für <code>[hbch_team_next_games]</code>/<code>[hbch_team_last_games]</code>, "Gruppe/Rangliste" die für <code>[hbch_ranking]</code>/<code>[hbch_team_ranking]</code>.</p>
		<form method="post">
			<?php wp_nonce_field( 'hbch_diagnose_fetch_action', 'hbch_diagnose_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="hbch_diagnose_team">Team</label></th>
					<td>
						<select name="hbch_diagnose_team" id="hbch_diagnose_team">
							<?php foreach ( $teams as $slug => $id ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected_team, $slug ); ?>><?php echo esc_html( $slug ); ?> (ID <?php echo esc_html( $id ); ?>)</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th>Endpunkt</th>
					<td>
						<label><input type="radio" name="hbch_diagnose_kind" value="games" <?php checked( $selected_kind, 'games' ); ?>> Spiele (<code>/teams/{id}/games</code>)</label><br>
						<label><input type="radio" name="hbch_diagnose_kind" value="group" <?php checked( $selected_kind, 'group' ); ?>> Gruppe/Rangliste (<code>/teams/{id}/group</code>)</label>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Live abrufen', 'primary', 'hbch_diagnose_fetch', false ); ?>
		</form>

		<?php if ( $raw_error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $raw_error ); ?></p></div>
		<?php elseif ( $raw_json ) : ?>
			<p><strong>Antwort:</strong></p>
			<textarea readonly rows="24" class="large-text code" onclick="this.select()"><?php echo esc_textarea( $raw_json ); ?></textarea>
		<?php endif; ?>
	</div>

	<div class="hbch-admin-card">
		<h2>Alle Team-IDs prüfen</h2>
		<p>Prüft alle im Reiter "Allgemein &amp; API" konfigurierten Teams gegen die API und zeigt pro Team, ob die ID (noch) gültig ist, samt dem von handball.ch gemeldeten Teamnamen. Nutzt denselben Cache wie die Rangliste — bei Bedarf vorher oben "Cache jetzt leeren" klicken.</p>
		<form method="post">
			<?php wp_nonce_field( 'hbch_diagnose_check_teams_action', 'hbch_diagnose_nonce' ); ?>
			<?php submit_button( 'Alle Team-IDs jetzt prüfen', 'primary', 'hbch_diagnose_check_teams', false ); ?>
		</form>

		<?php if ( $team_check_results !== null ) : ?>
			<?php hbch_render_team_check_table( $team_check_results, true ); ?>
		<?php endif; ?>
	</div>
	<?php
}

function hbch_render_team_check_table( array $results, bool $show_team_id_column = true ) {
	?>
	<table class="widefat striped" style="margin-top:1em;max-width:760px;">
		<thead>
			<tr>
				<th>Slug</th>
				<?php if ( $show_team_id_column ) : ?><th>Team-ID</th><?php endif; ?>
				<th>Status</th>
				<th>Teamname laut API</th>
				<th>Liga</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $results as $slug => $result ) : ?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<?php if ( $show_team_id_column ) : ?><td><?php echo esc_html( hbch_get_team_id( $slug ) ); ?></td><?php endif; ?>
					<td>
						<?php if ( $result['valid'] ) : ?>
							<span style="color:#2e7d32;font-weight:600;">✓ gültig</span>
						<?php else : ?>
							<span style="color:#c62828;font-weight:600;">✗ <?php echo esc_html( $result['error'] ?? 'ungültig' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $result['team_name'] ?? '—' ); ?></td>
					<td><?php echo esc_html( $result['league'] ?? '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

/**
 * Anker-ID einer Markdown-Überschrift im GitHub-Stil (Kleinbuchstaben,
 * Satzzeichen weg, Leerzeichen -> "-", Umlaute bleiben).
 */
function hbch_markdown_heading_slug( $text ) {
	$text = str_replace( [ '`', '**' ], '', $text );
	$text = mb_strtolower( $text, 'UTF-8' );
	$text = preg_replace( '/[^\p{L}\p{N}\s-]/u', '', $text );
	$text = preg_replace( '/\s/u', '-', trim( $text ) );
	return $text;
}

/**
 * Minimaler Markdown-Renderer für README.md und CHANGELOG.md: Überschriften
 * mit Anker-IDs (Präfix "hbch-doc-"), Listen (auch nummeriert und eingerückt),
 * Tabellen, Codeblöcke, `code`, **fett**, [Link](url), ---. Eingerückte
 * Fortsetzungszeilen werden an den Listenpunkt angehängt. Links auf
 * CHANGELOG.md/README.md zeigen auf den jeweiligen Reiter. Aufzählungszeichen
 * werden inline gesetzt, weil das WordPress-Admin-CSS sie sonst ausblendet.
 */
function hbch_markdown_to_html( $markdown ) {
	$lines      = preg_split( '/\r\n|\r|\n/', $markdown );
	$html       = '';
	$list_type  = ''; // '', 'ul', 'ul-nested' oder 'ol'
	$in_table   = false;
	$in_code    = false;
	$code_lines = [];
	$table_rows = [];

	$list_styles = [
		'ul'        => 'list-style:disc;margin:0 0 1em 1.5em;',
		'ul-nested' => 'list-style:circle;margin:0 0 1em 3em;',
	];

	$flush_list = function () use ( &$list_type, &$html ) {
		if ( $list_type !== '' ) {
			$html      .= $list_type === 'ol' ? "</ol>\n" : "</ul>\n";
			$list_type  = '';
		}
	};

	$open_list = function ( $type, $start = 1 ) use ( &$list_type, &$html, $list_styles, $flush_list ) {
		if ( $list_type === $type ) {
			return;
		}
		$flush_list();
		if ( $type === 'ol' ) {
			$html .= '<ol start="' . (int) $start . '" style="list-style:decimal;margin:0 0 1em 1.5em;">' . "\n";
		} else {
			$html .= '<ul style="' . $list_styles[ $type ] . '">' . "\n";
		}
		$list_type = $type;
	};

	$inline = function ( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace_callback( '/\[([^\]]+)\]\(([^)]+)\)/', function ( $m ) {
			$url = $m[2];
			if ( $url !== '' && $url[0] === '#' ) {
				$href = '#hbch-doc-' . ltrim( $url, '#' );
			} elseif ( $url === 'CHANGELOG.md' ) {
				$href = hbch_settings_url( 'changelog' );
			} elseif ( $url === 'README.md' ) {
				$href = hbch_settings_url( 'anleitung' );
			} else {
				$href = esc_url( $url );
			}
			return '<a href="' . esc_url( $href ) . '">' . $m[1] . '</a>';
		}, $text );
		return $text;
	};

	$flush_table = function () use ( &$in_table, &$table_rows, &$html ) {
		if ( ! $in_table ) {
			return;
		}
		if ( count( $table_rows ) >= 1 ) {
			$html .= "<table class=\"widefat hbch-docs-table\">\n<thead><tr>";
			foreach ( $table_rows[0] as $cell ) {
				$html .= '<th>' . $cell . '</th>';
			}
			$html .= "</tr></thead>\n<tbody>\n";
			for ( $i = 2; $i < count( $table_rows ); $i++ ) {
				$html .= '<tr>';
				foreach ( $table_rows[ $i ] as $cell ) {
					$html .= '<td>' . $cell . '</td>';
				}
				$html .= "</tr>\n";
			}
			$html .= "</tbody></table>\n";
		}
		$table_rows = [];
		$in_table   = false;
	};

	$code_block = function ( $code_lines ) {
		return '<pre style="background:#f0f0f1;padding:10px 14px;overflow:auto;"><code>' . esc_html( implode( "\n", $code_lines ) ) . "</code></pre>\n";
	};

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		// Codeblock (```): bis zur schliessenden Zeile unverändert ausgeben.
		if ( strpos( $trimmed, '```' ) === 0 ) {
			if ( $in_code ) {
				$html      .= $code_block( $code_lines );
				$code_lines = [];
				$in_code    = false;
			} else {
				$flush_list();
				$flush_table();
				$in_code = true;
			}
			continue;
		}
		if ( $in_code ) {
			$code_lines[] = $line;
			continue;
		}

		if ( preg_match( '/^\|(.+)\|$/', $trimmed ) ) {
			$flush_list();
			$in_table     = true;
			$cells        = array_map( 'trim', explode( '|', trim( $trimmed, '|' ) ) );
			$table_rows[] = array_map( $inline, $cells );
			continue;
		}
		$flush_table();

		if ( $trimmed === '' ) {
			$flush_list();
			continue;
		}

		if ( preg_match( '/^---+$/', $trimmed ) ) {
			$flush_list();
			$html .= "<hr>\n";
			continue;
		}

		if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
			$flush_list();
			$level = strlen( $m[1] );
			$id    = hbch_markdown_heading_slug( $m[2] );
			$html .= "<h{$level} id=\"" . esc_attr( 'hbch-doc-' . $id ) . "\">" . $inline( $m[2] ) . "</h{$level}>\n";
			continue;
		}

		// Nummerierte Liste; die Startnummer bleibt erhalten.
		if ( preg_match( '/^(\d+)\.\s+(.*)$/', $trimmed, $m ) ) {
			$open_list( 'ol', (int) $m[1] );
			$html .= '<li>' . $inline( $m[2] ) . "</li>\n";
			continue;
		}

		// Aufzählung; eingerückt (>= 2 Leerzeichen) nach einer Liste = Unterpunkt.
		if ( preg_match( '/^[-*]\s+(.*)$/', $trimmed, $m ) ) {
			$indent = strlen( $line ) - strlen( ltrim( $line ) );
			$type   = ( $indent >= 2 && $list_type !== '' ) ? 'ul-nested' : 'ul';
			$open_list( $type );
			$html .= '<li>' . $inline( $m[1] ) . "</li>\n";
			continue;
		}

		// Eingerückte Fortsetzungszeile: ans letzte <li> anhängen (per substr(),
		// damit "$1" oder "\" im Text nicht als Rückverweis gelten). 6 = strlen( "</li>\n" ).
		if ( $list_type !== '' && preg_match( '/^\s+\S/', $line ) ) {
			$html = substr( $html, 0, -6 ) . ' ' . $inline( $trimmed ) . "</li>\n";
			continue;
		}

		$flush_list();
		$html .= '<p>' . $inline( $trimmed ) . "</p>\n";
	}

	if ( $in_code && $code_lines ) {
		$html .= $code_block( $code_lines );
	}
	$flush_list();
	$flush_table();

	return $html;
}

function hbch_field_checkbox( $key, $label_after ) {
	printf(
		'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
		HBCH_OPTION,
		esc_attr( $key ),
		checked( (bool) hbch_get_setting( $key ), true, false ),
		esc_html( $label_after )
	);
}

function hbch_render_field_table( $field_key, array $row_labels, array $columns, array $applicable = [], $show_text_column = true, array $mobile_applicable = [] ) {
	$fields         = hbch_get_setting( $field_key );
	$single_column  = count( $columns ) === 1;
	$has_mobile_col = in_array( true, $mobile_applicable, true );

	echo '<table class="widefat" style="max-width:680px;"><thead><tr>';
	foreach ( $columns as $header ) {
		echo '<th style="width:110px;">' . $header . '</th>';
	}
	echo '<th>Feld</th>';
	if ( $has_mobile_col ) {
		echo '<th style="width:90px;">Auf Mobile anzeigen</th>';
	}
	if ( $show_text_column ) {
		echo '<th>Text</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $fields as $row_key => $row ) {
		if ( ! isset( $row_labels[ $row_key ] ) ) {
			continue;
		}

		echo '<tr>';
		foreach ( $columns as $side => $header ) {
			$applies = $applicable[ $row_key ][ $side ] ?? true;
			if ( ! $applies ) {
				echo '<td></td>';
				continue;
			}
			$enabled_key = $single_column ? 'enabled' : 'enabled_' . $side;
			printf(
				'<td><input type="checkbox" name="%1$s[%2$s][%3$s][%4$s]" value="1" %5$s></td>',
				HBCH_OPTION,
				esc_attr( $field_key ),
				esc_attr( $row_key ),
				esc_attr( $enabled_key ),
				checked( ! empty( $row[ $enabled_key ] ), true, false )
			);
		}

		echo '<td>' . esc_html( $row_labels[ $row_key ] ) . '</td>';

		if ( $has_mobile_col ) {
			if ( ! empty( $mobile_applicable[ $row_key ] ) ) {
				printf(
					'<td style="text-align:center;"><input type="checkbox" name="%1$s[%2$s][%3$s][show_mobile]" value="1" %4$s></td>',
					HBCH_OPTION,
					esc_attr( $field_key ),
					esc_attr( $row_key ),
					checked( ! empty( $row['show_mobile'] ), true, false )
				);
			} else {
				echo '<td></td>';
			}
		}

		if ( $show_text_column ) {
			$placeholder = ( $row['label'] === '' ) ? '(kein eigener Anzeigetext für dieses Feld)' : '';
			printf(
				'<td><input type="text" name="%1$s[%2$s][%3$s][label]" value="%4$s" class="regular-text" placeholder="%5$s"></td>',
				HBCH_OPTION,
				esc_attr( $field_key ),
				esc_attr( $row_key ),
				esc_attr( $row['label'] ),
				esc_attr( $placeholder )
			);
		}

		echo '</tr>';
	}
	echo '</tbody></table>';
}

function hbch_field_css_textarea( $key, $hint ) {
	printf(
		'<textarea name="%1$s[%2$s]" rows="10" cols="60" class="large-text code" placeholder="/* z. B.\n.hbch-league { color: #d00; }\n*/">%3$s</textarea><p class="description">%4$s</p>',
		HBCH_OPTION,
		esc_attr( $key ),
		esc_textarea( hbch_get_setting( $key ) ),
		esc_html( $hint )
	);
}

/**
 * Standard-CSS eines Abschnitts aus public.css (zwischen den HBCH-TAB-Markern).
 */
function hbch_get_default_css_section( $section ) {
	static $file_content = null;
	if ( $file_content === null ) {
		$path         = HBCH_PATH . 'assets/css/public.css';
		$file_content = file_exists( $path ) ? file_get_contents( $path ) : '';
	}

	$pattern = '/\/\* HBCH-TAB:' . preg_quote( $section, '/' ) . ':START \*\/(.*?)\/\* HBCH-TAB:' . preg_quote( $section, '/' ) . ':END \*\//s';
	if ( preg_match( $pattern, $file_content, $matches ) ) {
		return trim( $matches[1] );
	}
	return '';
}

function hbch_field_default_css_display( $sections ) {
	$blocks = [];
	foreach ( (array) $sections as $section ) {
		$css = hbch_get_default_css_section( $section );
		if ( $css !== '' ) {
			$blocks[] = $css;
		}
	}

	printf(
		'<textarea readonly rows="14" cols="60" class="large-text code" style="background:#f0f0f1;color:#555;" onclick="this.select()">%s</textarea><p class="description">Das mitgelieferte Standard-CSS dieses Widgets (nur zur Referenz). Änderungen ins Feld "Eigenes CSS" unten eintragen: es wird danach geladen und kann diese Regeln überschreiben.</p>',
		esc_textarea( implode( "\n\n", $blocks ) )
	);
}

add_action( 'admin_init', function () {
	register_setting( 'hbch_settings_group', HBCH_OPTION, [
		'sanitize_callback' => 'hbch_sanitize_settings',
	] );

	// =================================================================
	// Reiter: Allgemein & API
	// =================================================================
	add_settings_section( 'hbch_section_api', 'API-Zugang (handball.ch)', function () {
		echo '<p>Zugangsdaten für die clubapi.handball.ch. Club-ID und Passwort stellt der SHV aus. Das Plugin setzt daraus automatisch den Base64-kodierten "ClubID:Secret"-String für die Basic-Auth zusammen — du musst nichts selbst kodieren.</p>';
	}, 'hbch-tab-allgemein' );

	add_settings_field( 'club_id', 'Club-ID', function () {
		printf( '<input type="text" name="%s[club_id]" value="%s" class="regular-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'club_id' ) ?: '' ) );
	}, 'hbch-tab-allgemein', 'hbch_section_api' );

	add_settings_field( 'api_secret', 'API-Passwort', function () {
		printf(
			'<input type="password" name="%s[api_secret]" value="%s" class="regular-text" autocomplete="off"><p class="description">Das Passwort (Secret), das der SHV zusammen mit der Club-ID ausstellt.</p>',
			HBCH_OPTION,
			esc_attr( hbch_get_setting( 'api_secret' ) )
		);
	}, 'hbch-tab-allgemein', 'hbch_section_api' );

	add_settings_section( 'hbch_section_teams', 'Team-Zuordnungen', function () {
		echo '<p>Ein Team pro Zeile, Format <code>slug=handball.ch-Team-ID</code>, z. B. <code>team1=12345</code>. Der Slug ist der Wert, den man in Shortcodes wie <code>[hbch_ranking team="team1"]</code> nach <code>team=</code> schreibt. Die Team-ID steht in der URL des Teams auf handball.ch.</p>';
	}, 'hbch-tab-allgemein' );

	add_settings_field( 'teams', 'Teams', function () {
		$teams = hbch_get_setting( 'teams' );
		$lines = [];
		foreach ( $teams as $slug => $id ) {
			$lines[] = $slug . '=' . $id;
		}
		printf(
			'<textarea name="%s[teams_raw]" rows="9" cols="40" class="large-text code">%s</textarea>',
			HBCH_OPTION,
			esc_textarea( implode( "\n", $lines ) )
		);

		if ( ! empty( $teams ) ) {
			if ( isset( $_GET['hbch_check_teams'] ) ) {
				hbch_render_team_check_table( hbch_validate_all_teams(), true );
				printf(
					'<p class="description">Ausführlicher (inkl. Neu-Abruf ohne Cache) im Reiter "Diagnose". <a href="%s">Prüfung wieder ausblenden</a>.</p>',
					esc_url( remove_query_arg( 'hbch_check_teams' ) )
				);
			} else {
				printf(
					'<p class="description" style="margin-top:0.75em;"><a href="%s" class="button button-secondary">Alle Team-IDs jetzt prüfen</a> — ruft alle eingetragenen Teams gegen die API ab (bei kaltem Cache kann das ein paar Sekunden dauern). Ausführlicher im Reiter "Diagnose".</p>',
					esc_url( add_query_arg( 'hbch_check_teams', '1' ) )
				);
			}
		}
	}, 'hbch-tab-allgemein', 'hbch_section_teams' );

	add_settings_section( 'hbch_section_technical', 'Technische Werte (gemeinsam genutzt)', function () {
		echo '<p>Diese Werte werden von mehreren Widgets gemeinsam genutzt. Im Zweifel unverändert lassen.</p>';
	}, 'hbch-tab-allgemein' );

	$general_number_fields = [
		'cache_club_games_minutes' => [ 'Vereins-Spielplan — Cache-Dauer (Minuten) — genutzt von Startseite, Countdown, ICS', 1 ],
		'logo_cache_days'          => [ 'Team-Logos — lokale Cache-Dauer (Tage)', 1 ],
	];
	foreach ( $general_number_fields as $key => $meta ) {
		add_settings_field( $key, $meta[0], function () use ( $key, $meta ) {
			printf(
				'<input type="number" min="%d" name="%s[%s]" value="%s" class="small-text">',
				$meta[1],
				HBCH_OPTION,
				esc_attr( $key ),
				esc_attr( hbch_get_setting( $key ) )
			);
		}, 'hbch-tab-allgemein', 'hbch_section_technical' );
	}

	add_settings_field( 'schema_jsonld_enabled', 'Strukturierte Daten (SEO)', function () {
		hbch_field_checkbox( 'schema_jsonld_enabled', 'Pro angezeigtem Spiel zusätzlich ein schema.org-"SportsEvent" (Datum, Ort, Gegner, Bild, Beschreibung, Eintritt, Veranstalter) als unsichtbares JSON-LD ausgeben — betrifft [hbch_team_next_games], [hbch_home_next_games] und [hbch_next_game]. Ändert am sichtbaren Frontend nichts. Die geschätzte Spieldauer fürs "endDate"-Feld ist dieselbe Einstellung wie bei der ICS-Endzeit (Reiter "ICS-Export").' );
	}, 'hbch-tab-allgemein', 'hbch_section_technical' );

	add_settings_field( 'schema_offers_enabled', 'Strukturierte Daten — Eintritt', function () {
		hbch_field_checkbox( 'schema_offers_enabled', 'Feld "offers" mit ausgeben (Eintrittspreis unten).' );
		printf(
			'<br><label style="display:inline-block;margin-top:6px;">Preis: <input type="text" inputmode="decimal" name="%1$s[schema_offers_price]" value="%2$s" class="small-text"> CHF (0 = kostenloser Eintritt)</label>',
			HBCH_OPTION,
			esc_attr( hbch_get_setting( 'schema_offers_price' ) )
		);
	}, 'hbch-tab-allgemein', 'hbch_section_technical' );

	// =================================================================
	// Reiter: Rangliste ([hbch_ranking], [hbch_team_ranking])
	// =================================================================
	add_settings_section( 'hbch_rangliste_vorschau', 'Live-Vorschau', function () {
		hbch_render_preview_team_selector( 'rangliste' );
		$slug = hbch_get_preview_team_slug();
		if ( $slug !== '' ) {
			echo '<p><strong>[hbch_ranking]</strong> (kompakt):</p>';
			hbch_render_shortcode_preview( do_shortcode( '[hbch_ranking team="' . esc_attr( $slug ) . '"]' ) );
			echo '<p style="margin-top:1.5em;"><strong>[hbch_team_ranking]</strong> (detailliert, mit Auf-/Abstiegszonen):</p>';
			hbch_render_shortcode_preview( do_shortcode( '[hbch_team_ranking team="' . esc_attr( $slug ) . '"]' ) );
		}
	}, 'hbch-tab-rangliste' );

	add_settings_section( 'hbch_rangliste_spalten', 'Spalten', function () {
		$row_labels = [ 'rank' => 'rank (#)', 'team' => 'team', 'games' => 'games (Sp)', 'wins' => 'wins (S)', 'draws' => 'draws (U)', 'losses' => 'losses (N)', 'goals' => 'goals (Tore)', 'diff' => 'diff (+/-)', 'points' => 'points (Pkt)', 'ppg' => 'ppg (Pkt/Spiel)' ];
		echo '<p>Die Reihenfolge der Spalten ist fest, jede kann für Kompakt und Detailliert unabhängig ein-/ausgeblendet werden. Die Kopfzeile ("Text") gilt für beide Varianten. Pro Variante muss mindestens eine Spalte aktiv bleiben.</p>';
		hbch_render_field_table(
			'ranking_columns',
			$row_labels,
			[
				'compact'  => 'Kompakt<br><span style="font-weight:normal;">[hbch_ranking]</span>',
				'detailed' => 'Detailliert<br><span style="font-weight:normal;">[hbch_team_ranking]</span>',
			]
		);
		echo '<p style="margin-top:1em;">';
		hbch_field_checkbox( 'ranking_dual_logo_enabled', 'Team-Logos: bei Spielgemeinschaften (SG) über zwei Vereine zusätzlich das eigene Vereinslogo daneben anzeigen (nur detaillierte Rangliste, dort werden Logos angezeigt). Erfordert den Such-Text unter "Eigene Mannschaft hervorheben".' );
		echo '</p>';
	}, 'hbch-tab-rangliste' );

	add_settings_section( 'hbch_rangliste_zonen', 'Auf-/Abstiegszonen', function () {
		printf(
			'<p>Das Rang-Badge (#) in der detaillierten Rangliste <code>[hbch_team_ranking]</code> wird eingefärbt, je nachdem ob ein Team laut handball.ch (<code>/teams/{id}/group</code>) in einer Auf- oder Abstiegszone steht. Die vier Zonen-Farben stellst du im Reiter <a href="%s">Farben</a> ein.</p>',
			esc_url( hbch_settings_url( 'farben' ) )
		);
	}, 'hbch-tab-rangliste' );

	add_settings_section( 'hbch_rangliste_eigene', 'Eigene Mannschaft hervorheben', function () {
		printf(
			'<p>Hebt in beiden Ranglisten die Zeile hervor, deren Teamname den unten stehenden Text enthält (z. B. der Vereinsname). Die Farbe der Zeile ist die Akzentfarbe (Reiter <a href="%s">Farben</a>), die Schrift darauf "Schrift auf Farbflächen". Der Text dient ausserdem der Erkennung von Spielgemeinschaften (Doppel-Logo). Leer = keine Hervorhebung.</p>',
			esc_url( hbch_settings_url( 'farben' ) )
		);
	}, 'hbch-tab-rangliste' );
	add_settings_field( 'highlight_own_team_enabled', 'Aktiv', function () {
		hbch_field_checkbox( 'highlight_own_team_enabled', 'Eigene Mannschaft in der Rangliste hervorheben' );
	}, 'hbch-tab-rangliste', 'hbch_rangliste_eigene' );
	add_settings_field( 'highlight_own_team_text', 'Such-Text (im Teamnamen)', function () {
		printf( '<input type="text" name="%s[highlight_own_team_text]" value="%s" class="regular-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'highlight_own_team_text' ) ) );
	}, 'hbch-tab-rangliste', 'hbch_rangliste_eigene' );

	add_settings_section( 'hbch_rangliste_texte', 'Texte', function () {}, 'hbch-tab-rangliste' );
	foreach ( [ 'text_ranking_unavailable' => 'Text wenn Rangliste nicht erreichbar ist', 'text_ranking_unknown_team' => 'Text bei unbekanntem Team-Slug' ] as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key ) {
			printf( '<input type="text" name="%s[%s]" value="%s" class="regular-text">', HBCH_OPTION, esc_attr( $key ), esc_attr( hbch_get_setting( $key ) ) );
		}, 'hbch-tab-rangliste', 'hbch_rangliste_texte' );
	}

	add_settings_section( 'hbch_rangliste_cache', 'Cache-Dauer', function () {}, 'hbch-tab-rangliste' );
	add_settings_field( 'cache_ranking_minutes', 'Rangliste — Cache-Dauer (Minuten)', function () {
		printf( '<input type="number" min="1" name="%s[cache_ranking_minutes]" value="%s" class="small-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'cache_ranking_minutes' ) ) );
	}, 'hbch-tab-rangliste', 'hbch_rangliste_cache' );

	add_settings_section( 'hbch_rangliste_css', 'Standard- & eigenes CSS', function () {
		echo '<p>Oben das mitgelieferte Standard-CSS (nur Referenz), darunter das freie Feld, das NACH dem Standard-CSS geladen wird. Farben besser im Reiter "Farben" einstellen. Betrifft die Klassen <code>.hbch-ranking-*</code>, <code>.hbch-rank-*</code>, <code>.hbch-row-highlight*</code>, <code>.hbch-col-*</code>, <code>.hbch-zone-*</code>, <code>.hbch-team-logo-group/-dual</code>.</p>';
	}, 'hbch-tab-rangliste' );
	add_settings_field( 'css_ranking_default', 'Standard-CSS (Referenz)', function () {
		hbch_field_default_css_display( [ 'rangliste', 'shared' ] );
	}, 'hbch-tab-rangliste', 'hbch_rangliste_css' );
	add_settings_field( 'css_ranking', 'Eigenes CSS', function () {
		hbch_field_css_textarea( 'css_ranking', 'Nur die Rangliste betreffend.' );
	}, 'hbch-tab-rangliste', 'hbch_rangliste_css' );

	// =================================================================
	// Reiter: Team-Spielplan ([hbch_team_next_games], [hbch_team_last_games])
	// =================================================================
	add_settings_section( 'hbch_games_vorschau', 'Live-Vorschau', function () {
		hbch_render_preview_team_selector( 'teamspiele' );
		$slug = hbch_get_preview_team_slug();
		if ( $slug !== '' ) {
			echo '<p><strong>[hbch_team_next_games]</strong> (zu spielende Spiele):</p>';
			hbch_render_shortcode_preview( do_shortcode( '[hbch_team_next_games team="' . esc_attr( $slug ) . '"]' ) );
			echo '<p style="margin-top:1.5em;"><strong>[hbch_team_last_games]</strong> (gespielte Spiele — teilt sich alle Einstellungen dieses Reiters mit [hbch_team_next_games]):</p>';
			hbch_render_shortcode_preview( do_shortcode( '[hbch_team_last_games team="' . esc_attr( $slug ) . '"]' ) );
		}
	}, 'hbch-tab-teamspiele' );

	add_settings_section( 'hbch_games_felder', 'Felder', function () {
		echo '<p>Diese Felder liefert die handball.ch-API pro Spiel; sie sind pro Shortcode unabhängig ein-/ausblendbar. "Zeit" gibt es nur bei [hbch_team_next_games], "Details" (Spaltenkopf über dem Matchcenter-Link) nur bei [hbch_team_last_games]; die jeweils andere Checkbox fehlt deshalb absichtlich. Zeilen ohne eigenen Anzeigetext (Halladresse, Spielart, Link, Kurzname mobil, Doppel-Logo) brauchen kein Textfeld. "Auf Mobile anzeigen" gibt es nur bei Halle, Zuschauer und Matchcenter-Link, den Feldern mit eigener Spalte. Ist keine Halle/Runde/Spielart/Zuschauer-Angabe aktiv bzw. der Link aus, verschwindet die Spalte ganz. "LIVE" (nur [hbch_team_next_games]) ersetzt Datum+Zeit durch einen verlinkten Live-Badge. Da handball.ch keinen "läuft"-Status liefert, gilt ein Spiel 90 Minuten ab Anpfiff als live (per Filter <code>hbch_live_game_duration_minutes</code> anpassbar).</p>';
		hbch_render_field_table(
			'games_fields',
			[
				'date'          => 'Datum',
				'time'          => 'Zeit',
				'matchup'       => 'Begegnungen',
				'venue'         => 'Halle/Ort',
				'venue_address' => '↳ Halladresse statt nur Ortsname',
				'round'         => 'Runde',
				'gametype'      => 'Spielart (Text kommt von der API)',
				'spectators'    => 'Zuschauerzahl',
				'link'          => 'Matchcenter-Link 🔗',
				'short_names'   => 'Kurzname statt Ausblenden auf Mobile',
				'dual_logo'     => 'SG-Teams: zweites Vereinslogo',
				'details'       => 'Details',
				'live_badge'    => 'LIVE-Badge statt Datum/Zeit',
			],
			[
				'next' => 'Nächste Spiele<br><span style="font-weight:normal;">[hbch_team_next_games]</span>',
				'last' => 'Letzte Spiele<br><span style="font-weight:normal;">[hbch_team_last_games]</span>',
			],
			[
				'time'       => [ 'last' => false ],
				'details'    => [ 'next' => false ],
				'live_badge' => [ 'last' => false ],
			],
			true,
			[
				'venue'      => true,
				'spectators' => true,
				'link'       => true,
			]
		);
	}, 'hbch-tab-teamspiele' );

	add_settings_section( 'hbch_games_cache', 'Cache-Dauer', function () {}, 'hbch-tab-teamspiele' );
	add_settings_field( 'cache_games_minutes', 'Team-Spielplan — Cache-Dauer (Minuten)', function () {
		printf( '<input type="number" min="1" name="%s[cache_games_minutes]" value="%s" class="small-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'cache_games_minutes' ) ) );
	}, 'hbch-tab-teamspiele', 'hbch_games_cache' );

	add_settings_section( 'hbch_games_css', 'Standard- & eigenes CSS', function () {
		echo '<p>Oben das mitgelieferte Standard-CSS (nur Referenz), darunter das freie Feld, das NACH dem Standard-CSS geladen wird. Farben besser im Reiter "Farben" einstellen. Betrifft die Klassen <code>.hbch-game-row</code>, <code>.hbch-team-cell</code>, <code>.hbch-result-cell</code>, <code>.hbch-score-badge</code>, <code>.hbch-priority-*</code>, <code>.hbch-col-*</code> in <code>[hbch_team_next_games]</code>/<code>[hbch_team_last_games]</code>.</p>';
	}, 'hbch-tab-teamspiele' );
	add_settings_field( 'css_games_default', 'Standard-CSS (Referenz)', function () {
		hbch_field_default_css_display( [ 'teamspiele', 'shared' ] );
	}, 'hbch-tab-teamspiele', 'hbch_games_css' );
	add_settings_field( 'css_games', 'Eigenes CSS', function () {
		hbch_field_css_textarea( 'css_games', 'Nur die Team-Spielplan-Tabellen betreffend.' );
	}, 'hbch-tab-teamspiele', 'hbch_games_css' );

	// =================================================================
	// Reiter: Vereins-Spielplan ([hbch_home_next_games], [hbch_home_last_games])
	// =================================================================
	add_settings_section( 'hbch_home_vorschau', 'Live-Vorschau', function () {
		echo '<p><strong>[hbch_home_next_games limit="2"]</strong>:</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_home_next_games limit="2"]' ) );
		echo '<p style="margin-top:1.5em;"><strong>[hbch_home_last_games limit="2"]</strong>:</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_home_last_games limit="2"]' ) );
	}, 'hbch-tab-startseite' );

	add_settings_section( 'hbch_home_felder', 'Felder', function () {
		echo '<p>Dieselben Felder wie bei "Team-Spielplan" (ausser Datum/Zeit/Link/Details), ebenfalls pro Shortcode unabhängig ein-/ausblendbar. Kurznamen gibt es hier nicht: die Vereins-Spielliste liefert keine, Teamnamen werden auf Mobile deshalb ausgeblendet statt gekürzt. "Auf Mobile anzeigen" bei Halle steuert in beiden Widgets dieselbe Zusatzzeile (Datum/Halle/Zuschauer/Runde/Spielart), Default aus. "LIVE" (nur [hbch_home_next_games]) ersetzt Datum+Zeit durch einen verlinkten Live-Badge, sobald ein Spiel läuft (90 Minuten ab Anpfiff, per Filter <code>hbch_live_game_duration_minutes</code> anpassbar).</p>';
		hbch_render_field_table(
			'home_fields',
			[
				'venue'         => 'Halle/Ort',
				'venue_address' => '↳ Halladresse statt nur Ortsname',
				'round'         => 'Runde',
				'gametype'      => 'Spielart (Text kommt von der API)',
				'spectators'    => 'Zuschauerzahl',
				'dual_logo'     => 'SG-Teams: zweites Vereinslogo',
				'live_badge'    => 'LIVE-Badge statt Datum/Zeit',
			],
			[
				'next' => 'Nächste Spiele<br><span style="font-weight:normal;">[hbch_home_next_games]</span>',
				'last' => 'Letzte Spiele<br><span style="font-weight:normal;">[hbch_home_last_games]</span>',
			],
			[
				'live_badge' => [ 'last' => false ],
			],
			true,
			[
				'venue' => true,
			]
		);
	}, 'hbch-tab-startseite' );

	add_settings_section( 'hbch_home_limit', 'Anzahl Spiele', function () {}, 'hbch-tab-startseite' );
	add_settings_field( 'default_games_limit', 'Standard-Anzahl Spiele (ohne limit-Angabe im Shortcode)', function () {
		printf( '<input type="number" min="1" name="%s[default_games_limit]" value="%s" class="small-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'default_games_limit' ) ) );
	}, 'hbch-tab-startseite', 'hbch_home_limit' );

	add_settings_section( 'hbch_home_css', 'Standard- & eigenes CSS', function () {
		echo '<p>Oben das mitgelieferte Standard-CSS (nur Referenz), darunter das freie Feld, das NACH dem Standard-CSS geladen wird. Farben besser im Reiter "Farben" einstellen. Betrifft die Klassen <code>.hbch-home-game-grid</code>, <code>.hbch-home-result-grid</code>, <code>.hbch-league*</code>, <code>.hbch-venue*</code>, <code>.hbch-team-logo-preview</code>, <code>.hbch-score-result</code> in <code>[hbch_home_next_games]</code>/<code>[hbch_home_last_games]</code>.</p>';
	}, 'hbch-tab-startseite' );
	add_settings_field( 'css_home_default', 'Standard-CSS (Referenz)', function () {
		hbch_field_default_css_display( [ 'startseite', 'shared' ] );
	}, 'hbch-tab-startseite', 'hbch_home_css' );
	add_settings_field( 'css_home', 'Eigenes CSS', function () {
		hbch_field_css_textarea( 'css_home', 'Nur die Startseiten-Widgets betreffend.' );
	}, 'hbch-tab-startseite', 'hbch_home_css' );

	// =================================================================
	// Reiter: Countdown ([hbch_next_game])
	// =================================================================
	add_settings_section( 'hbch_countdown_vorschau', 'Live-Vorschau', function () {
		hbch_render_preview_team_selector( 'countdown' );
		$slug = hbch_get_preview_team_slug();
		if ( $slug !== '' ) {
			hbch_render_shortcode_preview( do_shortcode( '[hbch_next_game team="' . esc_attr( $slug ) . '"]' ) );
		}
	}, 'hbch-tab-countdown' );

	add_settings_section( 'hbch_countdown_texte', 'Texte', function () {}, 'hbch-tab-countdown' );
	$countdown_text_fields = [
		'text_next_game_title' => 'Titel',
		'text_next_game_none'  => 'Text wenn kein Spiel bekannt ist',
		'text_next_game_vs'    => 'Text zwischen den Teams (z. B. "vs")',
		'text_days_label'      => 'Countdown-Label "Tage"',
		'text_hours_label'     => 'Countdown-Label "Stunden"',
		'text_mins_label'      => 'Countdown-Label "Minuten"',
	];
	foreach ( $countdown_text_fields as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key ) {
			printf( '<input type="text" name="%s[%s]" value="%s" class="regular-text">', HBCH_OPTION, esc_attr( $key ), esc_attr( hbch_get_setting( $key ) ) );
		}, 'hbch-tab-countdown', 'hbch_countdown_texte' );
	}

	add_settings_section( 'hbch_countdown_anzeige', 'Felder', function () {
		echo '<p>Es gibt nur einen Shortcode ([hbch_next_game]), deshalb nur eine Häkchen-Spalte; keines der Felder hat einen eigenen Anzeigetext.</p>';
		hbch_render_field_table(
			'countdown_fields',
			[
				'venue'     => 'Halle/Ort in der Datumszeile',
				'logos'     => 'Team-Logos neben den Teamnamen',
				'dual_logo' => 'SG-Teams: zweites Vereinslogo (wirkt nur, wenn "Team-Logos" aktiv ist)',
			],
			[ 'enabled' => '[hbch_next_game]' ],
			[],
			false
		);
	}, 'hbch-tab-countdown' );

	add_settings_section( 'hbch_countdown_cache', 'Cache-Dauer', function () {}, 'hbch-tab-countdown' );
	add_settings_field( 'cache_next_game_minutes', 'Countdown — Cache-Dauer (Minuten)', function () {
		printf( '<input type="number" min="1" name="%s[cache_next_game_minutes]" value="%s" class="small-text">', HBCH_OPTION, esc_attr( hbch_get_setting( 'cache_next_game_minutes' ) ) );
	}, 'hbch-tab-countdown', 'hbch_countdown_cache' );

	add_settings_section( 'hbch_countdown_css', 'Standard- & eigenes CSS', function () {
		echo '<p>Oben das mitgelieferte Standard-CSS (nur Referenz), darunter das freie Feld, das NACH dem Standard-CSS geladen wird. Farben besser im Reiter "Farben" oder direkt am Block einstellen (z. B. eine andere Akzentfarbe für die Countdown-Zahlen einer bestimmten Seite). Betrifft die Klassen <code>.hbch-next-game-*</code>, <code>.hbch-countdown-number</code> in <code>[hbch_next_game]</code>.</p>';
	}, 'hbch-tab-countdown' );
	add_settings_field( 'css_nextgame_default', 'Standard-CSS (Referenz)', function () {
		hbch_field_default_css_display( 'countdown' );
	}, 'hbch-tab-countdown', 'hbch_countdown_css' );
	add_settings_field( 'css_nextgame', 'Eigenes CSS', function () {
		hbch_field_css_textarea( 'css_nextgame', 'Nur das Countdown-Widget betreffend.' );
	}, 'hbch-tab-countdown', 'hbch_countdown_css' );

	// =================================================================
	// Reiter: ICS-Export ([hbch_ics])
	// =================================================================
	add_settings_section( 'hbch_ics_vorschau', 'Live-Vorschau', function () {
		echo '<p><strong>[hbch_ics]</strong> (ganzer Verein):</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_ics]' ) );

		hbch_render_preview_team_selector( 'ics' );
		$slug = hbch_get_preview_team_slug();
		if ( $slug !== '' ) {
			echo '<p style="margin-top:1.5em;"><strong>[hbch_ics team="' . esc_html( $slug ) . '"]</strong>:</p>';
			hbch_render_shortcode_preview( do_shortcode( '[hbch_ics team="' . esc_attr( $slug ) . '"]' ) );
		}
	}, 'hbch-tab-ics' );

	add_settings_section( 'hbch_ics_texte', 'Texte', function () {}, 'hbch-tab-ics' );
	foreach ( [ 'text_ics_calendar_name' => 'Kalender-Name (ICS-Titel in Kalender-Apps)', 'text_ics_button_label' => '"Kalender abonnieren"-Button-Text' ] as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key ) {
			printf( '<input type="text" name="%s[%s]" value="%s" class="regular-text">', HBCH_OPTION, esc_attr( $key ), esc_attr( hbch_get_setting( $key ) ) );
		}, 'hbch-tab-ics', 'hbch_ics_texte' );
	}

	add_settings_section( 'hbch_ics_anzeige', 'Anzeige im Kalendereintrag (Beschreibung)', function () {}, 'hbch-tab-ics' );
	$ics_toggles = [
		'ics_show_round'         => 'Runde in die Beschreibung aufnehmen (roundNr)',
		'ics_show_gametype'      => 'Spielart in die Beschreibung aufnehmen (gameTypeLong)',
		'ics_show_venue_address' => 'Halladresse statt nur Ortsname in die Beschreibung',
	];
	foreach ( $ics_toggles as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key, $label ) {
			hbch_field_checkbox( $key, $label );
		}, 'hbch-tab-ics', 'hbch_ics_anzeige' );
	}

	add_settings_section( 'hbch_ics_technical', 'Technische Werte', function () {}, 'hbch-tab-ics' );
	$ics_number_fields = [
		'cache_ics_hours'           => 'ICS-Kalender — Cache-Dauer (Stunden)',
		'ics_game_duration_minutes' => 'Angenommene Spieldauer (Minuten) — für ICS-Endzeit UND das "endDate"-Feld der SportsEvent-Strukturdaten',
	];
	foreach ( $ics_number_fields as $key => $label ) {
		add_settings_field( $key, $label, function () use ( $key ) {
			printf( '<input type="number" min="1" name="%s[%s]" value="%s" class="small-text">', HBCH_OPTION, esc_attr( $key ), esc_attr( hbch_get_setting( $key ) ) );
		}, 'hbch-tab-ics', 'hbch_ics_technical' );
	}

	add_settings_section( 'hbch_ics_css', 'Standard- & eigenes CSS (Dropdown-Button)', function () {
		echo '<p>Oben das mitgelieferte Standard-CSS (nur Referenz), darunter das freie Feld, das NACH dem Standard-CSS geladen wird. Farben besser im Reiter "Farben" einstellen. Betrifft die Klassen <code>.hbch-ics-dropdown*</code> des "Kalender abonnieren"-Buttons.</p>';
	}, 'hbch-tab-ics' );
	add_settings_field( 'css_ics_default', 'Standard-CSS (Referenz)', function () {
		hbch_field_default_css_display( 'ics' );
	}, 'hbch-tab-ics', 'hbch_ics_css' );
	add_settings_field( 'css_ics', 'Eigenes CSS', function () {
		hbch_field_css_textarea( 'css_ics', 'Nur den ICS-Dropdown-Button betreffend.' );
	}, 'hbch-tab-ics', 'hbch_ics_css' );

	// =================================================================
	// Reiter: Farben — Rollen und Block-Zuordnung: includes/colors.php
	// =================================================================
	add_settings_section( 'hbch_farben_basis', 'Grundfarben', function () {
		echo '<p>Alle Farben des Plugins an einer Stelle. Jede Farbe gilt für alle Widgets, die sie verwenden (siehe Beschreibung unter dem Farbfeld). In den Gutenberg-Blöcken lassen sich die Farben in der Seitenleiste unter "Farben" pro Block überschreiben; leer gelassene Farben übernehmen den Wert von hier.</p>';
	}, 'hbch-tab-farben' );
	foreach ( hbch_color_roles() as $role_id => $role ) {
		if ( $role['group'] !== 'base' ) {
			continue;
		}
		add_settings_field( $role['setting'], $role['label'], function () use ( $role_id ) {
			hbch_render_color_field( $role_id );
		}, 'hbch-tab-farben', 'hbch_farben_basis' );
	}

	add_settings_section( 'hbch_farben_zonen', 'Auf-/Abstiegszonen (Rang-Badge)', function () {
		echo '<p>Färbt das Rang-Badge (#) in der detaillierten Rangliste <code>[hbch_team_ranking]</code> ein, je nachdem ob ein Team laut handball.ch (<code>/teams/{id}/group</code>) in einer Auf- oder Abstiegszone steht. Betrifft nur das Badge, nicht die ganze Zeile. Ohne Zone gilt das "Grau (gedämpft)" oben.</p>';
	}, 'hbch-tab-farben' );
	foreach ( hbch_color_roles() as $role_id => $role ) {
		if ( $role['group'] !== 'zone' ) {
			continue;
		}
		add_settings_field( $role['setting'], $role['label'], function () use ( $role_id ) {
			hbch_render_color_field( $role_id );
		}, 'hbch-tab-farben', 'hbch_farben_zonen' );
	}

	add_settings_section( 'hbch_farben_reset', 'Zurücksetzen', function () {}, 'hbch-tab-farben' );
	add_settings_field( 'colors_reset', 'Standardfarben', function () {
		printf(
			'<label><input type="checkbox" name="%s[colors_reset]" value="1"> Beim Speichern alle Farben auf die Standardwerte zurücksetzen</label>',
			HBCH_OPTION
		);
	}, 'hbch-tab-farben', 'hbch_farben_reset' );

	add_settings_section( 'hbch_farben_vorschau', 'Live-Vorschau', function () {
		echo '<p>Die Farbfelder oben wirken sofort auf diese Vorschau, gespeichert wird aber erst mit "Änderungen speichern". Ein Wechsel des Vorschau-Teams lädt die Seite neu, ungespeicherte Farben gehen dabei verloren. Die Vorschau zeigt die globalen Farben; Block-Überschreibungen erscheinen nur im Block-Editor und im Frontend.</p>';
		hbch_render_preview_team_selector( 'farben' );
		$slug = hbch_get_preview_team_slug();
		if ( $slug !== '' ) {
			foreach ( [ 'hbch_ranking', 'hbch_team_ranking', 'hbch_team_next_games', 'hbch_team_last_games', 'hbch_next_game' ] as $tag ) {
				echo '<p style="margin-top:1.5em;"><strong>[' . esc_html( $tag ) . ']</strong>:</p>';
				hbch_render_shortcode_preview( do_shortcode( '[' . $tag . ' team="' . esc_attr( $slug ) . '"]' ) );
			}
		}
		echo '<p style="margin-top:1.5em;"><strong>[hbch_home_next_games limit="1"]</strong>:</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_home_next_games limit="1"]' ) );
		echo '<p style="margin-top:1.5em;"><strong>[hbch_home_last_games limit="1"]</strong>:</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_home_last_games limit="1"]' ) );
		echo '<p style="margin-top:1.5em;"><strong>[hbch_ics]</strong> (Menü hier dauerhaft geöffnet):</p>';
		hbch_render_shortcode_preview( do_shortcode( '[hbch_ics]' ), 'hbch-admin-preview--open-menu' );

		// Sofort-Vorschau: setzt die CSS-Variable am <html>-Element, sobald ein
		// Farbfeld geändert wird (Inline-Werte schlagen die :root-Regel).
		echo '<script>(function () {
			document.querySelectorAll("input[type=color][data-hbch-var]").forEach(function (input) {
				input.addEventListener("input", function () {
					document.documentElement.style.setProperty(input.getAttribute("data-hbch-var"), input.value);
				});
			});
		})();</script>';
	}, 'hbch-tab-farben' );

	add_settings_section( 'hbch_farben_referenz', 'CSS-Variablen (Referenz)', function () {
		echo '<p>So heissen die Variablen und so sind sie aktuell belegt. Sie lassen sich in "Eigenes CSS" oder im Theme für einzelne Seiten überschreiben, z. B. <code>.page-id-123 { --hbch-color-accent: #004ABD; }</code>. Auch ein Dark-Mode im Theme kann sie einfach neu setzen.</p>';
	}, 'hbch-tab-farben' );
	add_settings_field( 'css_vars_reference', 'Variablen (Referenz)', function () {
		printf(
			'<textarea readonly rows="%d" cols="60" class="large-text code" style="background:#f0f0f1;color:#555;" onclick="this.select()">%s</textarea>',
			count( hbch_color_roles() ) + 2,
			esc_textarea( hbch_colors_root_css( true ) )
		);
	}, 'hbch-tab-farben', 'hbch_farben_referenz' );
} );

function hbch_sanitize_settings( $input ) {
	$defaults = hbch_settings_defaults();
	$current  = get_option( HBCH_OPTION, [] );
	$current  = is_array( $current ) ? array_merge( $defaults, $current ) : $defaults;
	$clean    = $current;

	if ( isset( $input['club_id'] ) ) {
		// Positive Ganzzahl, sonst 0 (= noch nicht konfiguriert).
		$clean['club_id'] = max( 0, intval( $input['club_id'] ) );
	}
	if ( isset( $input['api_secret'] ) ) {
		$clean['api_secret'] = trim( sanitize_text_field( $input['api_secret'] ) );
	}

	if ( isset( $input['teams_raw'] ) ) {
		$teams = [];
		$lines = preg_split( '/\r\n|\r|\n/', $input['teams_raw'] );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '=' ) === false ) {
				continue;
			}
			list( $slug, $id ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$slug = sanitize_title( $slug );
			$id   = intval( $id );
			if ( $slug !== '' && $id > 0 ) {
				$teams[ $slug ] = $id;
			}
		}
		$clean['teams'] = $teams;
	}

	$text_keys = [
		'text_ics_calendar_name', 'text_ics_button_label', 'text_next_game_title',
		'text_next_game_none', 'text_days_label', 'text_hours_label', 'text_mins_label',
		'text_ranking_unavailable', 'text_ranking_unknown_team',
		'text_next_game_vs', 'schema_offers_price',
	];
	foreach ( $text_keys as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$clean[ $key ] = $input[ $key ] !== '' ? sanitize_text_field( $input[ $key ] ) : $defaults[ $key ];
		}
	}
	// Leer erlaubt (= Hervorhebung/SG-Erkennung aus).
	if ( isset( $input['highlight_own_team_text'] ) ) {
		$clean['highlight_own_team_text'] = sanitize_text_field( $input['highlight_own_team_text'] );
	}

	foreach ( [ 'games_fields', 'home_fields' ] as $field_key ) {
		if ( ! isset( $input[ $field_key ] ) || ! is_array( $input[ $field_key ] ) ) {
			continue;
		}
		$fields = $defaults[ $field_key ];
		foreach ( array_keys( $fields ) as $row_key ) {
			if ( isset( $input[ $field_key ][ $row_key ]['label'] ) ) {
				$label = sanitize_text_field( $input[ $field_key ][ $row_key ]['label'] );
				$fields[ $row_key ]['label'] = $label !== '' ? $label : $defaults[ $field_key ][ $row_key ]['label'];
			}
			$fields[ $row_key ]['enabled_next'] = ! empty( $input[ $field_key ][ $row_key ]['enabled_next'] );
			$fields[ $row_key ]['enabled_last'] = ! empty( $input[ $field_key ][ $row_key ]['enabled_last'] );
			if ( array_key_exists( 'show_mobile', $defaults[ $field_key ][ $row_key ] ) ) {
				$fields[ $row_key ]['show_mobile'] = ! empty( $input[ $field_key ][ $row_key ]['show_mobile'] );
			}
		}
		$clean[ $field_key ] = $fields;
	}

	if ( isset( $input['countdown_fields'] ) && is_array( $input['countdown_fields'] ) ) {
		$fields = $defaults['countdown_fields'];
		foreach ( array_keys( $fields ) as $row_key ) {
			$fields[ $row_key ]['enabled'] = ! empty( $input['countdown_fields'][ $row_key ]['enabled'] );
		}
		$clean['countdown_fields'] = $fields;
	}

	if ( isset( $input['ranking_columns'] ) && is_array( $input['ranking_columns'] ) ) {
		$columns = $defaults['ranking_columns'];
		foreach ( array_keys( $columns ) as $col_key ) {
			if ( isset( $input['ranking_columns'][ $col_key ]['label'] ) ) {
				$label = sanitize_text_field( $input['ranking_columns'][ $col_key ]['label'] );
				$columns[ $col_key ]['label'] = $label !== '' ? $label : $defaults['ranking_columns'][ $col_key ]['label'];
			}
			$columns[ $col_key ]['enabled_compact']  = ! empty( $input['ranking_columns'][ $col_key ]['enabled_compact'] );
			$columns[ $col_key ]['enabled_detailed'] = ! empty( $input['ranking_columns'][ $col_key ]['enabled_detailed'] );
		}
		// Pro Variante mindestens eine Spalte: sonst zurück auf die Defaults.
		foreach ( [ 'enabled_compact', 'enabled_detailed' ] as $variant_flag ) {
			if ( count( array_filter( $columns, fn( $c ) => $c[ $variant_flag ] ) ) === 0 ) {
				foreach ( array_keys( $columns ) as $col_key ) {
					$columns[ $col_key ][ $variant_flag ] = $defaults['ranking_columns'][ $col_key ][ $variant_flag ];
				}
			}
		}
		$clean['ranking_columns'] = $columns;
	}

	$number_keys = [
		'cache_ranking_minutes', 'cache_games_minutes', 'cache_club_games_minutes',
		'cache_next_game_minutes', 'cache_ics_hours', 'logo_cache_days',
		'ics_game_duration_minutes', 'default_games_limit',
	];
	foreach ( $number_keys as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$clean[ $key ] = max( 1, intval( $input[ $key ] ) );
		}
	}

	// Farben: ungültige Werte fallen auf den Default zurück, nur abgeschickte
	// Felder werden angefasst.
	foreach ( hbch_color_roles() as $role ) {
		$key = $role['setting'];
		if ( isset( $input[ $key ] ) ) {
			$hex           = sanitize_hex_color( $input[ $key ] );
			$clean[ $key ] = $hex ?: $defaults[ $key ];
		}
	}
	if ( ! empty( $input['colors_reset'] ) ) {
		foreach ( hbch_color_roles() as $role ) {
			$clean[ $role['setting'] ] = $defaults[ $role['setting'] ];
		}
	}

	// Checkboxen des abgeschickten Reiters (nicht angehakt = nicht im POST).
	if ( isset( $input['hbch_tab_marker'] ) ) {
		foreach ( array_keys( $defaults ) as $key ) {
			if ( is_bool( $defaults[ $key ] ) && hbch_current_tab_has_field( $input['hbch_tab_marker'], $key ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
			}
		}
	}

	foreach ( [ 'css_ranking', 'css_games', 'css_home', 'css_nextgame', 'css_ics' ] as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$clean[ $key ] = wp_strip_all_tags( $input[ $key ] );
		}
	}

	return $clean;
}

/**
 * Welche einfachen Checkboxen gehören zu welchem Reiter? Neue Checkboxen hier
 * eintragen, sonst lassen sie sich nicht abschalten.
 */
function hbch_current_tab_has_field( $tab, $key ) {
	$map = [
		'rangliste' => [ 'ranking_dual_logo_enabled', 'highlight_own_team_enabled' ],
		'allgemein' => [ 'schema_jsonld_enabled', 'schema_offers_enabled' ],
		'ics'       => [ 'ics_show_round', 'ics_show_gametype', 'ics_show_venue_address' ],
	];
	return isset( $map[ $tab ] ) && in_array( $key, $map[ $tab ], true );
}

function hbch_render_settings_page() {
	$tabs       = hbch_settings_tabs();
	$requested  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'allgemein';
	$active_tab = isset( $tabs[ $requested ] ) ? $requested : 'allgemein';
	?>
	<div class="wrap">
		<h1>handball.ch Club-Widgets</h1>

		<?php if ( ! (int) hbch_get_setting( 'club_id' ) || hbch_get_api_token() === '' ) : ?>
			<div class="notice notice-warning"><p>Bitte zuerst Club-ID und API-Passwort im Reiter <a href="<?php echo esc_url( hbch_settings_url( 'allgemein' ) ); ?>">Allgemein &amp; API</a> eintragen. Beides stellt der SHV aus.</p></div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $meta ) : ?>
				<a href="<?php echo esc_url( hbch_settings_url( $slug ) ); ?>"
					class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $meta['label'] ); ?></a>
			<?php endforeach; ?>
		</h2>

		<?php if ( $active_tab === 'changelog' ) : ?>
			<?php hbch_render_markdown_file_tab( 'CHANGELOG.md' ); ?>
		<?php elseif ( $active_tab === 'anleitung' ) : ?>
			<?php hbch_render_markdown_file_tab( 'README.md' ); ?>
		<?php elseif ( $active_tab === 'diagnose' ) : ?>
			<?php hbch_render_diagnose_tab(); ?>
		<?php else : ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'hbch_settings_group' );
				printf( '<input type="hidden" name="%s[hbch_tab_marker]" value="%s">', HBCH_OPTION, esc_attr( $active_tab ) );
				do_settings_sections( $tabs[ $active_tab ]['page'] );
				submit_button();
				?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
