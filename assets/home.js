/* AddSynapse → Home: the welcome wizard (connection check, copy buttons, steps that turn green, provider → models). */
( function () {
	'use strict';
	var cfg = window.addsynapseHome || {};
	var t = cfg.i18n || {};
	var root = document.querySelector( '.adsa-home' );
	if ( ! root ) {
		return;
	}
	var apiFetch = window.wp && window.wp.apiFetch;
	/* v2 (1.3.1): older versions marked step 2 on a click of «Add to Claude» or «Copy» — forget that. */
	var STORE = 'addsynapseAssistantWizard2';

	function remember( key ) {
		try {
			var s = JSON.parse( window.localStorage.getItem( STORE ) || '{}' );
			s[ key ] = 1;
			window.localStorage.setItem( STORE, JSON.stringify( s ) );
		} catch ( e ) {}
	}

	function remembered( key ) {
		try {
			return !! JSON.parse( window.localStorage.getItem( STORE ) || '{}' )[ key ];
		} catch ( e ) {
			return false;
		}
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	/* Steps: a step and its dot in the progress bar share data-step; the first step not done is «current». */
	function refresh() {
		var items = root.querySelectorAll( '.adsa-progress__item' );
		var current = null;
		items.forEach( function ( li ) {
			var done = li.classList.contains( 'is-done' );
			if ( ! done && ! current ) {
				current = li.getAttribute( 'data-step' );
			}
		} );
		items.forEach( function ( li ) {
			var on = li.getAttribute( 'data-step' ) === current;
			li.classList.toggle( 'is-current', on );
			if ( on ) {
				li.setAttribute( 'aria-current', 'step' );
			} else {
				li.removeAttribute( 'aria-current' );
			}
			var sr = li.querySelector( '.adsa-progress__sr' );
			if ( sr ) {
				sr.textContent = li.classList.contains( 'is-done' ) ? '(' + ( t.done || 'Done' ) + ')' : '';
			}
		} );
		root.querySelectorAll( '.adsa-step' ).forEach( function ( s ) {
			s.classList.toggle( 'is-current', s.getAttribute( 'data-step' ) === current );
		} );
	}

	function setDone( name, done ) {
		root.querySelectorAll( '[data-step="' + name + '"]' ).forEach( function ( n ) {
			if ( n.classList.contains( 'adsa-step' ) || n.classList.contains( 'adsa-progress__item' ) ) {
				n.classList.toggle( 'is-done', !! done );
			}
		} );
		refresh();
	}

	function state( name, text, ok ) {
		var p = root.querySelector( '[data-state="' + name + '"]' );
		if ( ! p ) {
			return;
		}
		p.querySelector( '.adsa-state__text' ).textContent = text;
		p.classList.toggle( 'is-ok', !! ok );
		p.classList.toggle( 'is-waiting', ! ok );
	}

	/* «Mark» actions: «I've added it» (step 2), «It worked» (step 4) — explicit buttons only. */
	root.querySelectorAll( '[data-mark]' ).forEach( function ( n ) {
		n.addEventListener( 'click', function () {
			var name = n.getAttribute( 'data-mark' );
			remember( name );
			setDone( name, true );
		} );
	} );
	[ 'add', 'confirm' ].forEach( function ( name ) {
		if ( remembered( name ) && root.querySelector( '.adsa-step[data-step="' + name + '"]' ) ) {
			setDone( name, true );
		}
	} );

	/* Copy buttons. */
	root.querySelectorAll( '.adsa-copy__btn' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var src = document.getElementById( b.getAttribute( 'data-copy' ) );
			if ( ! src ) {
				return;
			}
			src.select();
			var old = b.textContent;
			var done = function () {
				b.textContent = t.copied || 'Copied';
				b.classList.add( 'is-copied' );
				setTimeout( function () {
					b.textContent = old;
					b.classList.remove( 'is-copied' );
				}, 1600 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( src.value ).then( done, function () {
					document.execCommand( 'copy' );
					done();
				} );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	} );

	/* Step 1: connectivity self-check (a loopback request of this site to itself). */
	var checkBtn = root.querySelector( '.adsa-check' );
	if ( checkBtn && apiFetch ) {
		checkBtn.addEventListener( 'click', function () {
			var out = root.querySelector( '.adsa-check-result' );
			out.textContent = t.checking || '…';
			checkBtn.disabled = true;
			apiFetch( { path: cfg.check, method: 'POST' } )
				.then( function ( r ) {
					out.textContent = '';
					out.appendChild( el( 'p', r.ok ? 'adsa-ok' : 'adsa-bad', r.ok ? t.passed : t.failed ) );
					var ul = el( 'ul', 'adsa-checks' );
					( r.checks || [] ).forEach( function ( c ) {
						var li = c.note ? el( 'li', 'adsa-note', 'ℹ ' + c.label ) : el( 'li', c.ok ? 'adsa-ok' : 'adsa-bad', ( c.ok ? '✓ ' : '✗ ' ) + c.label );
						if ( c.advice ) {
							li.appendChild( el( 'div', 'adsa-advice', c.advice ) );
						}
						ul.appendChild( li );
					} );
					out.appendChild( ul );
					setDone( 'check', !! r.ok );
				} )
				.catch( function ( e ) {
					out.textContent = ( e && e.message ) || t.error || 'Error';
				} )
				.then( function () {
					checkBtn.disabled = false;
				} );
		} );
	}

	/* Steps 3–4: poll this site's own state (an approved AddSynapse application password, its first use). */
	function poll() {
		apiFetch( { path: cfg.status } )
			.then( function ( s ) {
				if ( s.checked ) {
					setDone( 'check', true );
				}
				if ( s.ready ) {
					/* Step 2 turns green on its own only once AddSynapse confirmed (or used) the connection. */
					setDone( 'add', true );
				}
				if ( s.connected ) {
					setDone( 'connect', true );
					state( 'connect', ( t.approved || '%s' ).replace( '%s', s.detail.user ), true );
				}
				if ( s.ready ) {
					setDone( 'check', true );
				}
				if ( s.used || s.ready ) {
					setDone( 'confirm', true );
					state( 'confirm', s.used ? t.read : ( t.checked || t.read ), true );
					return;
				}
				setTimeout( poll, 5000 );
			} )
			.catch( function () {
				setTimeout( poll, 15000 );
			} );
	}
	if ( cfg.poll && apiFetch && root.querySelector( '.adsa-steps[data-path="mcp"]' ) ) {
		poll();
	}

	/* Key path: the provider picks the model list and the «Get a key» link. */
	var model = root.querySelector( '#addsynapse-assistant-ai-model' );
	var catalog = {};
	try {
		catalog = JSON.parse( ( model && model.getAttribute( 'data-addsynapse-assistant-models' ) ) || '{}' );
	} catch ( e ) {}
	root.querySelectorAll( 'input[name="addsynapse_assistant_ai_provider"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			if ( ! radio.checked ) {
				return;
			}
			setDone( 'provider', true );
			root.querySelectorAll( '.adsa-keylink' ).forEach( function ( a ) {
				a.hidden = a.getAttribute( 'data-provider' ) !== radio.value;
			} );
			if ( model && catalog[ radio.value ] ) {
				model.textContent = '';
				catalog[ radio.value ].forEach( function ( o ) {
					var opt = el( 'option', '', o.label );
					opt.value = o.id;
					model.appendChild( opt );
				} );
			}
		} );
	} );

	refresh();
}() );
