/**
 * Alte Blöcke (vor der Zusammenlegung, siehe hbch_legacy_block_map() in
 * includes/blocks.php) im Block-Editor. Im Frontend werden sie serverseitig auf
 * die neuen Blöcke umgeleitet. Hier bleibt der alte Block beim Öffnen einer
 * Seite erhalten (nichts geht verloren) und bietet einen Knopf, der ihn durch
 * den neuen Block mit den passenden Einstellungen ersetzt. Die Blöcke sind
 * nicht im Inserter sichtbar. Ohne Build-Schritt.
 */
( function ( blocks, element, blockEditor, components, wpData, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;
	var Button = components.Button;

	var legacy = window.handballchApiLegacyBlocks || {};

	Object.keys( legacy ).forEach( function ( legacyName ) {
		var target = legacy[ legacyName ];

		blocks.registerBlockType( legacyName, {
			title: __( 'handball.ch (veraltet)', 'handballch-api' ) + ': ' + legacyName.replace( 'handballch/', '' ),
			description: __( 'Alter Block, ersetzt durch einen neuen Block. Bitte umwandeln.', 'handballch-api' ),
			category: 'handballch-api',
			icon: 'warning',
			supports: { inserter: false, html: false },
			attributes: {
				team: { type: 'string', default: '' },
				limit: { type: 'string', default: '' },
				exclude: { type: 'string', default: '' },
				layout: { type: 'string', default: 'cards' },
			},
			edit: function ( props ) {
				var blockProps = useBlockProps();

				function convert() {
					var attributes = Object.assign( {}, props.attributes, target.attrs );
					wpData.dispatch( 'core/block-editor' ).replaceBlock(
						props.clientId,
						blocks.createBlock( target.block, attributes )
					);
				}

				return el( 'div', blockProps,
					el( Placeholder, { icon: 'warning', label: __( 'Veralteter handball.ch-Block', 'handballch-api' ) },
						el( 'p', {}, __( 'Dieser Block wurde durch einen neuen Block ersetzt. Im Frontend wird er weiterhin angezeigt.', 'handballch-api' ) ),
						el( Button, { variant: 'primary', onClick: convert }, __( 'In neuen Block umwandeln', 'handballch-api' ) )
					)
				);
			},
			save: function () { return null; },
		} );
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.data, window.wp.i18n );
