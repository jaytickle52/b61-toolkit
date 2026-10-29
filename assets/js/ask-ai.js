/**
 * Ask AI panel (B61 Toolkit). Opened from the admin bar's "Ask" button or
 * the Breakdance editor's Ask button. Talks only to this site's REST route
 * (b61/v1/ask); the site token never reaches the browser.
 *
 * Answers are rendered from a safe subset of Markdown, built as DOM nodes
 * (never innerHTML): paragraphs, lists, bold and links. Admin links must
 * resolve under this site's wp-admin; other links only to the answer's
 * listed sources. History lives in sessionStorage for this browser session.
 */
(function () {
	'use strict';
	var cfg = window.b61AskAi;
	if ( ! cfg || window.b61AskAiLoaded ) {
		return;
	}
	window.b61AskAiLoaded = true;
	var t = cfg.l10n || {};
	var STORE = 'b61AskAi:' + cfg.siteKey;
	var MAX_STEPS = 7;
	var panel, log, live, form, input, sendBtn, opener, state, busy = false;

	function load() {
		try {
			var s = JSON.parse( window.sessionStorage.getItem( STORE ) || 'null' );
			if ( s && Array.isArray( s.items ) ) {
				return s;
			}
		} catch ( e ) {}
		return { conversationId: '', items: [] };
	}
	function save() {
		try {
			window.sessionStorage.setItem( STORE, JSON.stringify( state ) );
		} catch ( e ) {}
	}

	function el( tag, attrs, children ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( 'text' === k ) {
				n.textContent = attrs[ k ];
			} else if ( 'className' === k ) {
				n.className = attrs[ k ];
			} else if ( null !== attrs[ k ] && undefined !== attrs[ k ] ) {
				n.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( 'string' === typeof c ? document.createTextNode( c ) : c );
			}
		} );
		return n;
	}

	var ICONS = {
		close: 'M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z',
		up: 'M2 21h4V9H2v12zm20-11a2 2 0 0 0-2-2h-6.3l1-4.6v-.3a1.5 1.5 0 0 0-.4-1L13.2 1 6.6 7.6A2 2 0 0 0 6 9v10a2 2 0 0 0 2 2h9a2 2 0 0 0 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2z',
		down: 'M22 3h-4v12h4V3zM2 14a2 2 0 0 0 2 2h6.3l-1 4.6v.3c0 .4.2.8.4 1l1.1 1.1 6.6-6.6c.4-.4.6-.9.6-1.4V5a2 2 0 0 0-2-2H7a2 2 0 0 0-1.8 1.2l-3 7.1c-.1.2-.2.5-.2.7v2z',
	};

	/** A small inline icon (no Dashicons: the page builder may not load them). */
	function icon( name ) {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', ICONS[ name ] );
		svg.appendChild( path );
		return svg;
	}

	/* ---------------------------------------------------------------- */
	/* Safe Markdown                                                     */
	/* ---------------------------------------------------------------- */

	function normalizeUrl( u ) {
		return String( u || '' ).replace( /#.*$/, '' ).replace( /\/+$/, '' );
	}

	/** A link target we allow, or null. */
	function safeHref( url, sources ) {
		var admin = cfg.adminUrl;
		var abs;
		url = String( url || '' ).trim();
		if ( 0 === url.indexOf( '/wp-admin/' ) ) {
			url = admin + url.slice( '/wp-admin/'.length );
		} else if ( /^(admin\.php|edit\.php|post\.php|options-[a-z]+\.php|nav-menus\.php|plugins\.php|upload\.php|post-new\.php|edit-tags\.php|users\.php|themes\.php|tools\.php|profile\.php)/.test( url ) ) {
			url = admin + url;
		}
		try {
			abs = new URL( url, admin );
		} catch ( e ) {
			return null;
		}
		if ( 'https:' !== abs.protocol && 'http:' !== abs.protocol ) {
			return null;
		}
		var href = abs.href;
		// Admin screens on this site only (no "../" escapes: URL() resolved them).
		if ( 0 === href.indexOf( admin ) ) {
			return { href: href, external: false };
		}
		for ( var i = 0; i < sources.length; i++ ) {
			if ( normalizeUrl( sources[ i ].url ) === normalizeUrl( href ) ) {
				return { href: href, external: true };
			}
		}
		return null;
	}

	function inline( text, sources ) {
		var out = [];
		var re = /\*\*([^*]+)\*\*|\[([^\]]+)\]\(\s*<?([^)\s>]+)>?\s*\)/g;
		var last = 0, m;
		while ( ( m = re.exec( text ) ) ) {
			if ( m.index > last ) {
				out.push( document.createTextNode( text.slice( last, m.index ) ) );
			}
			if ( undefined !== m[ 1 ] ) {
				out.push( el( 'strong', {}, inline( m[ 1 ], sources ) ) );
			} else {
				var link = safeHref( m[ 3 ], sources );
				if ( link ) {
					var newTab = link.external || cfg.inBuilder;
					var a = el( 'a', { href: link.href }, inline( m[ 2 ], sources ) );
					if ( newTab ) {
						a.target = '_blank';
						a.rel = 'noopener noreferrer';
						a.appendChild( el( 'span', { className: 'screen-reader-text', text: ' ' + ( t.opensNewTab || '' ) } ) );
					}
					out.push( a );
				} else {
					out.push( document.createTextNode( m[ 2 ] ) );
				}
			}
			last = re.lastIndex;
		}
		if ( last < text.length ) {
			out.push( document.createTextNode( text.slice( last ) ) );
		}
		return out;
	}

	function markdown( text, sources ) {
		var frag = document.createDocumentFragment();
		var lines = String( text || '' ).replace( /\r\n?/g, '\n' ).split( '\n' );
		var list = null, listType = '', para = [];
		function flushPara() {
			if ( para.length ) {
				var p = el( 'p' );
				para.forEach( function ( line, i ) {
					if ( i ) {
						p.appendChild( el( 'br' ) );
					}
					inline( line, sources ).forEach( function ( n ) {
						p.appendChild( n );
					} );
				} );
				frag.appendChild( p );
				para = [];
			}
		}
		function flushList() {
			if ( list ) {
				frag.appendChild( list );
				list = null;
				listType = '';
			}
		}
		lines.forEach( function ( raw ) {
			var line = raw.replace( /^#{1,6}\s+/, '' ).trimEnd();
			var ul = /^\s*[-*•]\s+(.*)$/.exec( line );
			var ol = /^\s*(\d+)[.)]\s+(.*)$/.exec( line );
			if ( ul || ol ) {
				flushPara();
				var type = ul ? 'ul' : 'ol';
				if ( ! list || listType !== type ) {
					flushList();
					list = el( type );
					listType = type;
					if ( ol && '1' !== ol[ 1 ] ) {
						list.setAttribute( 'start', ol[ 1 ] );
					}
				}
				list.appendChild( el( 'li', {}, inline( ul ? ul[ 1 ] : ol[ 2 ], sources ) ) );
			} else if ( '' === line.trim() ) {
				flushPara();
				flushList();
			} else if ( list && /^\s{2,}\S/.test( raw ) && list.lastChild ) {
				// Indented continuation of a list item.
				list.lastChild.appendChild( document.createTextNode( ' ' ) );
				inline( line.trim(), sources ).forEach( function ( n ) {
					list.lastChild.appendChild( n );
				} );
			} else {
				flushList();
				para.push( line.replace( /^>\s?/, '' ) );
			}
		} );
		flushPara();
		flushList();
		return frag;
	}

	/* ---------------------------------------------------------------- */
	/* Talking to WordPress                                              */
	/* ---------------------------------------------------------------- */

	function post( path, body ) {
		return window
			.fetch( cfg.rest + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify( body ),
			} )
			.then( function ( r ) {
				return r.json().catch( function () {
					return { type: 'error', message: t.error };
				} );
			} )
			.then( function ( data ) {
				// REST errors (permissions, bad nonce) come as { code, message }.
				if ( data && ! data.type && data.message ) {
					return { type: 'error', message: data.message };
				}
				return data || { type: 'error', message: t.error };
			} )
			.catch( function () {
				return { type: 'error', message: t.error };
			} );
	}

	function ask( item ) {
		busy = true;
		setBusy( true );
		var steps = 0;
		function handle( res ) {
			if ( res.conversation_id ) {
				state.conversationId = res.conversation_id;
			}
			if ( 'working' === res.type && steps < MAX_STEPS ) {
				steps++;
				item.status = res.status || t.thinking;
				render();
				return post( '', { message_id: res.message_id } ).then( handle );
			}
			delete item.pending;
			delete item.status;
			if ( 'answer' === res.type ) {
				item.a = res.answer;
				item.sources = res.sources || [];
				item.messageId = res.message_id;
				announce( res.answer );
			} else {
				item.error = res.message || t.error;
				announce( item.error );
			}
			save();
			render();
		}
		return post( '', {
			question: item.q,
			conversation_id: state.conversationId || '',
			screen: cfg.screen || {},
		} )
			.then( handle )
			.then( function () {
				busy = false;
				setBusy( false );
				input.focus();
			} );
	}

	function rate( item, rating, buttons ) {
		item.rating = item.rating === rating ? '' : rating;
		save();
		buttons.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-rating' ) === item.rating ? 'true' : 'false' );
		} );
		if ( item.rating ) {
			announce( t.thanks );
		}
		post( '/rate', { message_id: item.messageId, rating: item.rating } );
	}

	/* ---------------------------------------------------------------- */
	/* UI                                                                */
	/* ---------------------------------------------------------------- */

	function announce( text ) {
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = String( text || '' ).replace( /\*\*|\[|\]\([^)]*\)/g, '' );
		}, 50 );
	}

	function setBusy( on ) {
		sendBtn.disabled = on;
		input.setAttribute( 'aria-busy', on ? 'true' : 'false' );
	}

	function render() {
		log.textContent = '';
		if ( ! state.items.length ) {
			log.appendChild( el( 'p', { className: 'b61-ask__empty', text: t.empty } ) );
			return;
		}
		state.items.forEach( function ( item ) {
			log.appendChild(
				el( 'div', { className: 'b61-ask__msg b61-ask__msg--q' }, [
					el( 'span', { className: 'screen-reader-text', text: ( t.you || '' ) + ': ' } ),
					item.q,
				] )
			);
			var box = el( 'div', { className: 'b61-ask__msg b61-ask__msg--a' } );
			box.appendChild( el( 'span', { className: 'screen-reader-text', text: ( t.assistant || '' ) + ': ' } ) );
			if ( item.pending ) {
				box.appendChild( el( 'p', { className: 'b61-ask__working' }, [ el( 'span', { className: 'b61-ask__dots', 'aria-hidden': 'true' } ), item.status || t.thinking ] ) );
			} else if ( item.error ) {
				box.className += ' b61-ask__msg--error';
				box.appendChild( el( 'p', { text: item.error } ) );
			} else {
				var sources = item.sources || [];
				box.appendChild( markdown( item.a, sources ) );
				if ( sources.length ) {
					var ul = el( 'ul', { className: 'b61-ask__sources' } );
					sources.forEach( function ( s ) {
						var link = safeHref( s.url, sources );
						if ( link ) {
							ul.appendChild( el( 'li', {}, [ el( 'a', { href: link.href, target: '_blank', rel: 'noopener noreferrer' }, [ s.title || s.url, el( 'span', { className: 'screen-reader-text', text: ' ' + ( t.opensNewTab || '' ) } ) ] ) ] ) );
						}
					} );
					box.appendChild( el( 'p', { className: 'b61-ask__sources-label', text: t.sources } ) );
					box.appendChild( ul );
				}
				if ( item.messageId ) {
					var up = el( 'button', { type: 'button', className: 'b61-ask__rate', 'data-rating': 'up', 'aria-pressed': 'up' === item.rating ? 'true' : 'false', 'aria-label': t.helpful, title: t.helpful }, [ icon( 'up' ) ] );
					var down = el( 'button', { type: 'button', className: 'b61-ask__rate', 'data-rating': 'down', 'aria-pressed': 'down' === item.rating ? 'true' : 'false', 'aria-label': t.notHelpful, title: t.notHelpful }, [ icon( 'down' ) ] );
					up.addEventListener( 'click', function () {
						rate( item, 'up', [ up, down ] );
					} );
					down.addEventListener( 'click', function () {
						rate( item, 'down', [ up, down ] );
					} );
					box.appendChild( el( 'div', { className: 'b61-ask__rating' }, [ up, down ] ) );
				}
			}
			log.appendChild( box );
		} );
		log.scrollTop = log.scrollHeight;
	}

	function focusables() {
		return Array.prototype.filter.call(
			panel.querySelectorAll( 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])' ),
			function ( n ) {
				return n.offsetParent !== null || n === document.activeElement;
			}
		);
	}

	function build() {
		state = load();
		// A question left mid-answer by a reload can't be finished.
		state.items.forEach( function ( item ) {
			if ( item.pending ) {
				delete item.pending;
				delete item.status;
				item.error = t.error;
			}
		} );
		var title = el( 'h2', { id: 'b61-ask-title', className: 'b61-ask__title', text: t.title } );
		var newBtn = el( 'button', { type: 'button', className: 'b61-ask__new' }, [ t.newChat ] );
		var closeBtn = el( 'button', { type: 'button', className: 'b61-ask__close', 'aria-label': t.close, title: t.close }, [ icon( 'close' ) ] );
		log = el( 'div', { className: 'b61-ask__log' } );
		live = el( 'div', { className: 'screen-reader-text', 'aria-live': 'polite', 'aria-atomic': 'true' } );
		input = el( 'textarea', { id: 'b61-ask-input', className: 'b61-ask__input', rows: '2', maxlength: '2000', placeholder: t.placeholder } );
		sendBtn = el( 'button', { type: 'submit', className: 'b61-ask__send' }, [ t.send ] );
		form = el( 'form', { className: 'b61-ask__form' }, [ el( 'label', { className: 'screen-reader-text', for: 'b61-ask-input', text: t.title } ), input, sendBtn ] );
		panel = el(
			'div',
			{ id: 'b61-ask-panel', className: 'b61-ask' + ( cfg.inBuilder ? ' b61-ask--builder' : '' ), role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'b61-ask-title', hidden: 'hidden' },
			[
				el( 'div', { className: 'b61-ask__head' }, [ title, newBtn, closeBtn ] ),
				el( 'p', { className: 'b61-ask__sees', text: t.sees } ),
				log,
				form,
				live,
			]
		);
		document.body.appendChild( panel );

		closeBtn.addEventListener( 'click', close );
		newBtn.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			state = { conversationId: '', items: [] };
			save();
			render();
			input.focus();
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var q = input.value.trim();
			if ( ! q || busy ) {
				return;
			}
			input.value = '';
			var item = { q: q, pending: true };
			state.items.push( item );
			save();
			render();
			ask( item );
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key && ! e.shiftKey && ! e.isComposing ) {
				e.preventDefault();
				form.requestSubmit ? form.requestSubmit() : sendBtn.click();
			}
		} );
		panel.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				close();
			} else if ( 'Tab' === e.key ) {
				var f = focusables();
				if ( ! f.length ) {
					return;
				}
				var first = f[ 0 ], last = f[ f.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
			// Keep keystrokes away from the page builder's shortcuts.
			e.stopPropagation();
		} );
		render();
	}

	function open( from ) {
		if ( ! panel ) {
			build();
		}
		opener = from || null;
		panel.hidden = false;
		document.documentElement.classList.add( 'b61-ask-open' );
		if ( opener ) {
			opener.setAttribute( 'aria-expanded', 'true' );
		}
		log.scrollTop = log.scrollHeight;
		input.focus();
	}

	function close() {
		if ( ! panel || panel.hidden ) {
			return;
		}
		panel.hidden = true;
		document.documentElement.classList.remove( 'b61-ask-open' );
		if ( opener ) {
			opener.setAttribute( 'aria-expanded', 'false' );
			opener.focus();
		}
	}

	function bind( button ) {
		if ( ! button || button.getAttribute( 'data-b61-ask' ) ) {
			return;
		}
		button.setAttribute( 'data-b61-ask', '1' );
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.setAttribute( 'aria-expanded', 'false' );
		button.setAttribute( 'role', 'button' );
		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			if ( panel && ! panel.hidden ) {
				close();
			} else {
				open( button );
			}
		} );
	}

	function init() {
		bind( document.querySelector( '#wp-admin-bar-b61-ask-ai > .ab-item' ) );
		bind( document.getElementById( 'b61-ask-bd-btn' ) );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	window.b61AskAiOpen = open;
} )();
