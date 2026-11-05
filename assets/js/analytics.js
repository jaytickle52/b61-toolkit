/**
 * B61 Toolkit Analytics: fill each [data-b61-analytics] box (the Dashboard
 * card, the Website analytics page) after the page has loaded, so a slow
 * answer from Base Camp never holds the page up. The HTML comes from the
 * server, escaped there.
 */
( function () {
	'use strict';

	var cfg = window.b61Analytics;
	if ( ! cfg ) {
		return;
	}

	function fail( box ) {
		box.innerHTML = '';
		var p = document.createElement( 'p' );
		p.className = 'b61-an-empty';
		p.textContent = cfg.failed;
		box.appendChild( p );
		box.setAttribute( 'aria-busy', 'false' );
	}

	function load( box ) {
		var body = new FormData();
		body.append( 'action', cfg.action );
		body.append( '_ajax_nonce', cfg.nonce );
		body.append( 'view', box.getAttribute( 'data-b61-analytics' ) );
		body.append( 'range', box.getAttribute( 'data-range' ) || '30d' );
		if ( box.getAttribute( 'data-refresh' ) ) {
			body.append( 'refresh', '1' );
		}
		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res || ! res.success || ! res.data || typeof res.data.html !== 'string' ) {
					fail( box );
					return;
				}
				box.innerHTML = res.data.html;
				box.setAttribute( 'aria-busy', 'false' );
				// A refresh happens once; drop it from the address bar.
				if ( box.getAttribute( 'data-refresh' ) && window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					url.searchParams.delete( 'refresh' );
					window.history.replaceState( null, '', url.toString() );
				}
			} )
			.catch( function () {
				fail( box );
			} );
	}

	function start() {
		var boxes = document.querySelectorAll( '[data-b61-analytics]' );
		for ( var i = 0; i < boxes.length; i++ ) {
			load( boxes[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
