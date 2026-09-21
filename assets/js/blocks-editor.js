/**
 * Editor-Ansicht der 8 Blöcke aus includes/blocks.php (ohne Build-Schritt).
 * Alle Blöcke sind dynamisch (kein save()); das Markup kommt per
 * Server-Side-Render, identisch zu den Shortcodes.
 *
 * Jeder Block hat ein Panel "Farben", das die globalen Farben nur für diesen
 * Block überschreibt. Die Farbliste kommt aus PHP
 * (handballchApiBlocksData.blockColors), damit Attribute und Panel nie
 * auseinanderlaufen.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var ColorPalette = blockEditor.ColorPalette || components.ColorPalette;
	var PanelBody = components.PanelBody;
	var BaseControl = components.BaseControl;
	var Button = components.Button;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;
	var Notice = components.Notice;
	var Placeholder = components.Placeholder;

	var data = window.handballchApiBlocksData || { teams: {}, icsDefaultLabel: '', settingsUrl: '#', colorsUrl: '#', blockColors: {} };
	var teamSlugs = Object.keys( data.teams || {} );

	function renderNoTeamsNotice() {
		return el( Notice, { status: 'warning', isDismissible: false },
			__( 'Noch keine Teams konfiguriert. ', 'handballch-api' ),
			el( 'a', { href: data.settingsUrl, target: '_blank', rel: 'noopener' }, __( 'Jetzt unter „Allgemein & API“ eintragen', 'handballch-api' ) )
		);
	}

	function teamOptions( includeEmptyLabel ) {
		var options = [ { label: includeEmptyLabel, value: '' } ];
		teamSlugs.forEach( function ( slug ) {
			options.push( { label: slug + ' (ID ' + data.teams[ slug ] + ')', value: slug } );
		} );
		return options;
	}

	// --- Farben ---------------------------------------------------------

	function colorRoles( blockName ) {
		return ( data.blockColors && data.blockColors[ blockName ] ) || [];
	}

	// Farb-Attribute "color_<rolle>" (leer = globale Farbe). Müssen zu den in
	// PHP registrierten Attributen passen (hbch_block_color_attributes()).
	function withColorAttributes( blockName, attributes ) {
		colorRoles( blockName ).forEach( function ( role ) {
			attributes[ 'color_' + role.id ] = { type: 'string', default: '' };
		} );
		return attributes;
	}

	function renderColorPanel( blockName, attributes, setAttributes ) {
		var roles = colorRoles( blockName );
		if ( ! roles.length || ! ColorPalette ) {
			return null;
		}

		var hasOverride = roles.some( function ( role ) {
			return !! attributes[ 'color_' + role.id ];
		} );

		return el( PanelBody, { title: __( 'Farben', 'handballch-api' ), initialOpen: false },
			el( 'p', { className: 'components-base-control__help' },
				__( 'Gilt nur für diesen Block. Nicht gesetzte Farben übernehmen den globalen Wert. ', 'handballch-api' ),
				el( 'a', { href: data.colorsUrl, target: '_blank', rel: 'noopener' }, __( 'Globale Farben bearbeiten', 'handballch-api' ) )
			),
			roles.map( function ( role ) {
				var attr = 'color_' + role.id;
				return el( BaseControl, { key: role.id, label: role.label, __nextHasNoMarginBottom: true },
					el( ColorPalette, {
						value: attributes[ attr ] || undefined,
						clearable: true,
						enableAlpha: false,
						__experimentalIsRenderedInSidebar: true,
						onChange: function ( color ) {
							var patch = {};
							patch[ attr ] = color || '';
							setAttributes( patch );
						},
					} )
				);
			} ),
			hasOverride
				? el( Button, {
					variant: 'secondary',
					isDestructive: true,
					onClick: function () {
						var patch = {};
						roles.forEach( function ( role ) { patch[ 'color_' + role.id ] = ''; } );
						setAttributes( patch );
					},
				}, __( 'Alle Blockfarben zurücksetzen', 'handballch-api' ) )
				: null
		);
	}

	// --- Blöcke -----------------------------------------------------------

	function registerTeamBlock( name, title, description, icon ) {
		blocks.registerBlockType( name, {
			title: title,
			description: description,
			category: 'handballch-api',
			icon: icon,
			attributes: withColorAttributes( name, { team: { type: 'string', default: '' } } ),
			edit: function ( props ) {
				var blockProps = useBlockProps();
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;

				if ( teamSlugs.length === 0 ) {
					return el( 'div', blockProps, el( Placeholder, { icon: icon, label: title }, renderNoTeamsNotice() ) );
				}

				return el( 'div', blockProps,
					el( InspectorControls, {},
						el( PanelBody, { title: __( 'Team', 'handballch-api' ) },
							el( SelectControl, {
								label: __( 'Team', 'handballch-api' ),
								value: attributes.team,
								options: teamOptions( __( '— bitte wählen —', 'handballch-api' ) ),
								onChange: function ( value ) { setAttributes( { team: value } ); },
								help: __( 'Weitere Teams: Einstellungen → handball.ch Club-Widgets → „Allgemein & API“.', 'handballch-api' ),
							} )
						),
						renderColorPanel( name, attributes, setAttributes )
					),
					attributes.team
						? el( serverSideRender, { block: name, attributes: attributes } )
						: el( Placeholder, { icon: icon, label: title }, __( 'Bitte in der Seitenleiste rechts ein Team auswählen.', 'handballch-api' ) )
				);
			},
			save: function () { return null; },
		} );
	}

	registerTeamBlock( 'handballch/ranking', __( 'handball.ch: Rangliste (kompakt)', 'handballch-api' ), __( 'Kompakte Rangliste für ein Team.', 'handballch-api' ), 'editor-ol' );
	registerTeamBlock( 'handballch/team-ranking', __( 'handball.ch: Rangliste (detailliert)', 'handballch-api' ), __( 'Detaillierte Rangliste mit Logo, S/U/N und Toren.', 'handballch-api' ), 'chart-bar' );
	registerTeamBlock( 'handballch/team-next-games', __( 'handball.ch: Team-Spielplan – nächste Spiele', 'handballch-api' ), __( 'Noch ausstehende Spiele eines Teams.', 'handballch-api' ), 'calendar-alt' );
	registerTeamBlock( 'handballch/team-last-games', __( 'handball.ch: Team-Spielplan – letzte Resultate', 'handballch-api' ), __( 'Bereits gespielte Spiele eines Teams mit Resultat.', 'handballch-api' ), 'awards' );
	registerTeamBlock( 'handballch/next-game', __( 'handball.ch: Countdown (nächstes Spiel)', 'handballch-api' ), __( 'Nächstes Spiel eines Teams mit Live-Countdown.', 'handballch-api' ), 'clock' );

	function registerHomeBlock( name, title, description, icon ) {
		blocks.registerBlockType( name, {
			title: title,
			description: description,
			category: 'handballch-api',
			icon: icon,
			attributes: withColorAttributes( name, {
				limit: { type: 'string', default: '' },
				exclude: { type: 'string', default: '' },
			} ),
			edit: function ( props ) {
				var blockProps = useBlockProps();
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;

				return el( 'div', blockProps,
					el( InspectorControls, {},
						el( PanelBody, { title: __( 'Einstellungen', 'handballch-api' ) },
							el( TextControl, {
								label: __( 'Anzahl Spiele', 'handballch-api' ),
								type: 'number',
								min: 1,
								value: attributes.limit,
								placeholder: String( data.defaultGamesLimit || 3 ),
								help: __( 'Leer lassen für die Standard-Anzahl aus den Einstellungen (Reiter „Vereins-Spielplan“).', 'handballch-api' ),
								onChange: function ( value ) { setAttributes( { limit: value } ); },
							} ),
							el( TextControl, {
								label: __( 'Ausschliessen (Freitext)', 'handballch-api' ),
								value: attributes.exclude,
								placeholder: 'z. B. U13',
								help: __( 'Spiele, bei denen Teamname, Liga oder Gruppentext diesen Text enthalten, werden ausgeblendet.', 'handballch-api' ),
								onChange: function ( value ) { setAttributes( { exclude: value } ); },
							} )
						),
						renderColorPanel( name, attributes, setAttributes )
					),
					el( serverSideRender, { block: name, attributes: attributes } )
				);
			},
			save: function () { return null; },
		} );
	}

	registerHomeBlock( 'handballch/home-next-games', __( 'handball.ch: Vereinsweit – nächste Spiele', 'handballch-api' ), __( 'Kommende Spiele über alle Teams.', 'handballch-api' ), 'calendar' );
	registerHomeBlock( 'handballch/home-last-games', __( 'handball.ch: Vereinsweit – letzte Resultate', 'handballch-api' ), __( 'Letzte Resultate über alle Teams.', 'handballch-api' ), 'list-view' );

	blocks.registerBlockType( 'handballch/ics-subscribe', {
		title: __( 'handball.ch: Kalender abonnieren', 'handballch-api' ),
		description: __( '„Kalender abonnieren“-Button mit Dropdown (ICS/webcal, Google Kalender, Link).', 'handballch-api' ),
		category: 'handballch-api',
		icon: 'download',
		attributes: withColorAttributes( 'handballch/ics-subscribe', {
			team: { type: 'string', default: '' },
			label: { type: 'string', default: '' },
		} ),
		edit: function ( props ) {
			var blockProps = useBlockProps();
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el( 'div', blockProps,
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Einstellungen', 'handballch-api' ) },
						el( SelectControl, {
							label: __( 'Team', 'handballch-api' ),
							value: attributes.team,
							options: teamOptions( __( '— ganzer Verein —', 'handballch-api' ) ),
							onChange: function ( value ) { setAttributes( { team: value } ); },
							help: teamSlugs.length === 0 ? undefined : __( 'Weitere Teams: Einstellungen → handball.ch Club-Widgets → „Allgemein & API“.', 'handballch-api' ),
						} ),
						el( TextControl, {
							label: __( 'Beschriftung', 'handballch-api' ),
							value: attributes.label,
							placeholder: data.icsDefaultLabel,
							help: __( 'Leer lassen für den Standardtext aus den Einstellungen (Reiter „ICS-Export“).', 'handballch-api' ),
							onChange: function ( value ) { setAttributes( { label: value } ); },
						} )
					),
					renderColorPanel( 'handballch/ics-subscribe', attributes, setAttributes )
				),
				teamSlugs.length === 0 ? renderNoTeamsNotice() : null,
				el( serverSideRender, { block: 'handballch/ics-subscribe', attributes: attributes } )
			);
		},
		save: function () { return null; },
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
