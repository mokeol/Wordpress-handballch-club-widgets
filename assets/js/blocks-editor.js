/**
 * Editor-Ansicht der Blöcke aus includes/blocks.php (ohne Build-Schritt).
 * Alle Blöcke sind dynamisch (kein save()); das Markup kommt per
 * Server-Side-Render, gebaut von denselben Render-Funktionen wie die
 * Shortcodes (siehe shortcodes.php).
 *
 * "Rangliste" hat eine Checkbox "Detailliert" (kompakt/detailliert) und,
 * wenn detailliert aktiv ist, zusätzlich "Auf-/Abstiegszonen farbig
 * markieren". "Team – Spielplan" und "Verein – Spielplan" haben je zwei
 * unabhängige Checkboxen ("Nächste Spiele" / "Resultate").
 *
 * Jeder Block hat ausserdem ein Panel "Farben", das die globalen Farben nur
 * für diesen Block überschreibt. Die Farbliste kommt aus PHP
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
	var CheckboxControl = components.CheckboxControl;
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

	// "cards" = Startseiten-Kartenlook (Default), "table" = Team-Spielplan-
	// Tabellenlook (z. B. für die Gesamtspielplan-Seite). Siehe layout-Attribut
	// bei handballch/home-games.
	var layoutOptions = [
		{ label: __( 'Karten (wie Startseite)', 'handballch-api' ), value: 'cards' },
		{ label: __( 'Tabelle (wie Team-Spielplan)', 'handballch-api' ), value: 'table' },
	];

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

	// Panel "Anzeige" für Team-/Vereins-Spielplan: zwei unabhängige
	// Checkboxen ("Nächste Spiele" / "Resultate").
	function renderGamesShowPanel( attributes, setAttributes ) {
		return el( PanelBody, { title: __( 'Anzeige', 'handballch-api' ) },
			el( CheckboxControl, {
				label: __( 'Nächste Spiele', 'handballch-api' ),
				checked: !! attributes.show_next,
				onChange: function ( value ) { setAttributes( { show_next: value } ); },
			} ),
			el( CheckboxControl, {
				label: __( 'Resultate', 'handballch-api' ),
				checked: !! attributes.show_last,
				onChange: function ( value ) { setAttributes( { show_last: value } ); },
			} ),
			( ! attributes.show_next && ! attributes.show_last )
				? el( Notice, { status: 'warning', isDismissible: false }, __( 'Nichts ausgewählt — der Block bleibt leer.', 'handballch-api' ) )
				: null
		);
	}

	// --- Blöcke -----------------------------------------------------------

	// "Rangliste": Team-Auswahl, Checkbox "Detailliert" und (nur wenn
	// detailliert aktiv) Checkbox "Auf-/Abstiegszonen farbig markieren".
	( function () {
		var name = 'handballch/ranking';
		var title = __( 'handball.ch: Rangliste', 'handballch-api' );
		var icon = 'chart-bar';

		blocks.registerBlockType( name, {
			title: title,
			description: __( 'Kompakte oder detaillierte Rangliste für ein Team, mit optionaler Zonenfärbung.', 'handballch-api' ),
			category: 'handballch-api',
			icon: icon,
			attributes: withColorAttributes( name, {
				team: { type: 'string', default: '' },
				detailed: { type: 'boolean', default: false },
				show_zones: { type: 'boolean', default: true },
			} ),
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
						el( PanelBody, { title: __( 'Anzeige', 'handballch-api' ) },
							el( CheckboxControl, {
								label: __( 'Detaillierte Rangliste (Logo, S/U/N, Tore)', 'handballch-api' ),
								checked: !! attributes.detailed,
								onChange: function ( value ) { setAttributes( { detailed: value } ); },
								help: __( 'Unbestimmt = kompakte Rangliste (Platz, Team, Spiele, Punkte).', 'handballch-api' ),
							} ),
							attributes.detailed
								? el( CheckboxControl, {
									label: __( 'Auf-/Abstiegszonen farbig markieren', 'handballch-api' ),
									checked: !! attributes.show_zones,
									onChange: function ( value ) { setAttributes( { show_zones: value } ); },
									help: __( 'Färbt das Rang-Badge nach Auf-/Abstiegszone (Farben im Reiter „Farben“).', 'handballch-api' ),
								} )
								: null
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
	} )();

	// "Team – Spielplan": Team-Auswahl + Checkboxen "Nächste Spiele"/"Resultate".
	( function () {
		var name = 'handballch/team-games';
		var title = __( 'handball.ch: Team – Spielplan', 'handballch-api' );
		var icon = 'calendar-alt';

		blocks.registerBlockType( name, {
			title: title,
			description: __( 'Nächste Spiele und/oder Resultate eines Teams, per Checkbox wählbar.', 'handballch-api' ),
			category: 'handballch-api',
			icon: icon,
			attributes: withColorAttributes( name, {
				team: { type: 'string', default: '' },
				show_next: { type: 'boolean', default: true },
				show_last: { type: 'boolean', default: true },
			} ),
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
						renderGamesShowPanel( attributes, setAttributes ),
						renderColorPanel( name, attributes, setAttributes )
					),
					attributes.team
						? el( serverSideRender, { block: name, attributes: attributes } )
						: el( Placeholder, { icon: icon, label: title }, __( 'Bitte in der Seitenleiste rechts ein Team auswählen.', 'handballch-api' ) )
				);
			},
			save: function () { return null; },
		} );
	} )();

	// "Countdown (nächstes Spiel)": nur Team-Auswahl, keine Anzeige-Checkboxen.
	( function () {
		var name = 'handballch/next-game';
		var title = __( 'handball.ch: Countdown (nächstes Spiel)', 'handballch-api' );
		var icon = 'clock';

		blocks.registerBlockType( name, {
			title: title,
			description: __( 'Nächstes Spiel eines Teams mit Live-Countdown.', 'handballch-api' ),
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
	} )();

	// "Verein – Spielplan": Layout, Anzahl, Ausschluss + Checkboxen.
	( function () {
		var name = 'handballch/home-games';

		blocks.registerBlockType( name, {
			title: __( 'handball.ch: Verein – Spielplan', 'handballch-api' ),
			description: __( 'Nächste Spiele und/oder Resultate über alle Teams, per Checkbox wählbar.', 'handballch-api' ),
			category: 'handballch-api',
			icon: 'calendar',
			attributes: withColorAttributes( name, {
				limit: { type: 'string', default: '' },
				exclude: { type: 'string', default: '' },
				layout: { type: 'string', default: 'cards' },
				show_next: { type: 'boolean', default: true },
				show_last: { type: 'boolean', default: true },
			} ),
			edit: function ( props ) {
				var blockProps = useBlockProps();
				var attributes = props.attributes;
				var setAttributes = props.setAttributes;

				return el( 'div', blockProps,
					el( InspectorControls, {},
						el( PanelBody, { title: __( 'Einstellungen', 'handballch-api' ) },
							el( SelectControl, {
								label: __( 'Layout', 'handballch-api' ),
								value: attributes.layout || 'cards',
								options: layoutOptions,
								onChange: function ( value ) { setAttributes( { layout: value } ); },
								help: __( 'Karten = aktuelles Startseiten-Aussehen. Tabelle = gleiches Format wie beim Team-Spielplan (z. B. für die Gesamtspielplan-Seite).', 'handballch-api' ),
							} ),
							el( TextControl, {
								label: __( 'Anzahl Spiele', 'handballch-api' ),
								type: 'number',
								min: 1,
								value: attributes.limit,
								placeholder: String( data.defaultGamesLimit || 3 ),
								help: __( 'Leer lassen für die Standard-Anzahl aus den Einstellungen (Reiter „Vereins-Spielplan“). Gilt für „Nächste Spiele“ und „Resultate“ gleichermassen.', 'handballch-api' ),
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
						renderGamesShowPanel( attributes, setAttributes ),
						renderColorPanel( name, attributes, setAttributes )
					),
					el( serverSideRender, { block: name, attributes: attributes } )
				);
			},
			save: function () { return null; },
		} );
	} )();

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
