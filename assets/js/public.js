/**
 * handball.ch Club-Widgets — Frontend-JavaScript (ohne Build-Schritt).
 *
 * Wird nur auf Seiten geladen, die einen Shortcode oder Block des Plugins
 * enthalten (siehe includes/frontend.php). Enthält:
 *   1. Countdown zum nächsten Spiel ([hbch_next_game], data-kickoff in UTC)
 *   2. Kalender-Dropdown ([hbch_ics]: öffnen, Link kopieren, teilen)
 *   3. Entfernen fremdgesetzter title-Attribute an Team-Logos
 *
 * Datum, Uhrzeit und Hervorhebung der eigenen Mannschaft kommen fertig vom
 * Server, dafür braucht es kein JavaScript.
 */
( function () {
	'use strict';

	// 1. Countdown ---------------------------------------------------------

	function pad( n ) {
		return String( n ).padStart( 2, '0' );
	}

	function initCountdown( widget ) {
		var kickoff = new Date( widget.getAttribute( 'data-kickoff' ) ).getTime();
		var elDays  = widget.querySelector( '.hbch-next-game-days' );
		var elHours = widget.querySelector( '.hbch-next-game-hours' );
		var elMins  = widget.querySelector( '.hbch-next-game-mins' );
		var timer   = null;

		if ( isNaN( kickoff ) || ! elDays || ! elHours || ! elMins ) {
			return;
		}

		function tick() {
			var diff = kickoff - Date.now();
			if ( diff <= 0 ) {
				elDays.textContent  = '00';
				elHours.textContent = '00';
				elMins.textContent  = '00';
				if ( timer ) {
					clearInterval( timer );
				}
				return;
			}
			elDays.textContent  = pad( Math.floor( diff / ( 1000 * 60 * 60 * 24 ) ) );
			elHours.textContent = pad( Math.floor( ( diff / ( 1000 * 60 * 60 ) ) % 24 ) );
			elMins.textContent  = pad( Math.floor( ( diff / ( 1000 * 60 ) ) % 60 ) );
		}

		tick();
		if ( kickoff > Date.now() ) {
			timer = setInterval( tick, 60000 );
		}
	}

	// 2. Kalender-Dropdown -------------------------------------------------

	// Zwischenablage: moderner Weg nur in sicheren Kontexten (https), sonst Fallback.
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( err ) {
				ok = false;
			}
			document.body.removeChild( ta );
			if ( ok ) {
				resolve();
			} else {
				reject();
			}
		} );
	}

	function onDocumentClick( e ) {
		var toggle = e.target.closest( '.hbch-ics-dropdown-toggle' );
		if ( toggle ) {
			var menu = toggle.nextElementSibling;
			var isOpen = ! menu.hidden;
			menu.hidden = isOpen;
			toggle.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
			return;
		}

		if ( ! e.target.closest( '.hbch-ics-dropdown' ) ) {
			document.querySelectorAll( '.hbch-ics-dropdown-menu' ).forEach( function ( m ) {
				m.hidden = true;
			} );
			document.querySelectorAll( '.hbch-ics-dropdown-toggle' ).forEach( function ( btn ) {
				btn.setAttribute( 'aria-expanded', 'false' );
			} );
			return;
		}

		// closest(): der Klick kann auf dem Icon oder Text im Button landen.
		var copyBtn = e.target.closest( '.hbch-ics-copy-btn' );
		if ( copyBtn ) {
			var source = document.getElementById( copyBtn.dataset.target );
			if ( ! source ) {
				return;
			}
			var label = copyBtn.querySelector( '.hbch-ics-label' );
			if ( label && ! label.dataset.original ) {
				label.dataset.original = label.textContent;
			}
			copyText( source.textContent.trim() ).then( function () {
				if ( ! label ) {
					return;
				}
				label.textContent = 'Kopiert!';
				setTimeout( function () {
					label.textContent = label.dataset.original;
				}, 1500 );
			} ).catch( function () {} );
			return;
		}

		var shareBtn = e.target.closest( '.hbch-ics-share-btn' );
		if ( shareBtn && navigator.share ) {
			navigator.share( {
				title: shareBtn.dataset.title,
				url: shareBtn.dataset.url
			} ).catch( function () {} );
		}
	}

	// 3. Logo-title entfernen ----------------------------------------------

	function stripLogoTitles() {
		document.querySelectorAll( 'img[class*="hbch-team-logo"][title]' ).forEach( function ( img ) {
			img.removeAttribute( 'title' );
		} );
	}

	// Start ----------------------------------------------------------------

	document.addEventListener( 'click', onDocumentClick );

	function init() {
		document.querySelectorAll( '.hbch-next-game-widget[data-kickoff]' ).forEach( initCountdown );

		// Teilen-Knopf nur zeigen, wenn der Browser navigator.share kann.
		if ( navigator.share ) {
			document.querySelectorAll( '.hbch-ics-share-btn' ).forEach( function ( btn ) {
				btn.hidden = false;
				btn.style.display = '';
			} );
		}

		stripLogoTitles();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
