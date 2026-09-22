/**
 * Anonymous visitor language persistence, cookie-driven navigation, and the
 * browser/geo suggestion banner (ADR-0035).
 *
 * Every branch in this file only ever runs AFTER the (cached, identical-for-
 * everyone) HTML has already loaded — the server-rendered response never
 * depends on this cookie. `aiml_visitor_lang` is written here only via
 * `document.cookie`, never by PHP, on the anonymous side (see
 * VisitorLanguageCookie::class for the one narrow authenticated exception).
 */
(function () {
	'use strict';

	var COOKIE_MAX_AGE = 31536000; // 1 year, matches VisitorLanguageCookie.
	var DISMISS_KEY = 'aiml_visitor_lang_suggest_dismissed';
	var DISMISS_DAYS = 30;
	var GEO_TIMEOUT_MS = 1500;

	function cfg() {
		return window.aimlVisitorLanguage || null;
	}

	function cookieName() {
		var c = cfg();
		return (c && c.cookieName) || 'aiml_visitor_lang';
	}

	/** Reads a cookie value, or null. Never trusted without validation by the caller. */
	function readCookie( name ) {
		var match = document.cookie.match( new RegExp( '(?:^|; )' + name.replace( /[.$?*|{}()[\]\\/+^]/g, '\\$&' ) + '=([^;]*)' ) );
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	/**
	 * Writes the visitor-language cookie. Only ever called from an explicit
	 * action. `Secure` is requested unconditionally, not merely when the
	 * current page happens to be HTTPS — ADR-0035 freezes this cookie as
	 * Secure because both real deployments (DEV and PROD) are HTTPS-only;
	 * this is a declared architectural property, not something to infer
	 * per-request from `location.protocol`.
	 */
	function writeCookie( code ) {
		try {
			document.cookie = cookieName() + '=' + encodeURIComponent( code ) +
				'; Path=/; Max-Age=' + COOKIE_MAX_AGE + '; SameSite=Lax; Secure';
		} catch ( e ) {
			// Navigation/UX remains functional even if the cookie can't be written.
		}
	}

	/** Validates a raw code against the current page's known routable languages. */
	function validCode( code, links ) {
		if ( ! code ) {
			return null;
		}
		for ( var i = 0; i < links.length; i++ ) {
			if ( links[ i ].code === code ) {
				return code;
			}
		}
		return null;
	}

	function linkFor( code, links ) {
		for ( var i = 0; i < links.length; i++ ) {
			if ( links[ i ].code === code ) {
				return links[ i ];
			}
		}
		return null;
	}

	function storageGet( storage, key ) {
		try {
			return storage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function storageSet( storage, key, value ) {
		try {
			storage.setItem( key, value );
		} catch ( e ) {
			// Best-effort only; absence just means the banner may resurface sooner.
		}
	}

	/**
	 * Click-to-persist: any language link on the page (shortcode, nav menu, or
	 * the floating selector all share the `data-aiml-code` convention). Writes
	 * the cookie synchronously and lets the plain <a href> navigate normally —
	 * never blocks, never delays navigation.
	 */
	function wireClickPersist( c ) {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( '[data-aiml-code]' ) : null;
			if ( ! link ) {
				return;
			}
			var code = link.getAttribute( 'data-aiml-code' ) || '';
			if ( '' === code ) {
				return;
			}
			// Dismiss any pending suggestion — the visitor just made an
			// explicit choice — regardless of whether cookie persistence
			// itself is enabled; otherwise disabling persistence would also
			// silently disable "don't nag me again", which is a separate concern.
			storageSet( window.localStorage, DISMISS_KEY, String( Date.now() ) );
			if ( c.persistEnabled ) {
				writeCookie( code );
			}
		} );
	}

	/**
	 * Cookie-driven navigation. Reads only — never rewrites the cookie merely
	 * because navigation happened. Fires only when the current URL carries no
	 * explicit language prefix: an explicitly-prefixed URL is authoritative for
	 * its own request and is never fought by the cookie.
	 */
	/**
	 * Appends the current request's query string and hash to a canonical
	 * target URL, so an automatic redirect never silently drops search
	 * terms, pagination, filters, or one-time keys (e.g. a WooCommerce
	 * order-received `key=`) that a deliberate switcher click would also
	 * lose today, but which this *automatic* redirect must not.
	 *
	 * Preserved losslessly: canonical per-language URLs never carry their
	 * own query string (they are built from path only), so the current
	 * request's raw query string is copied through as-is rather than
	 * reconstructed via `URLSearchParams`/`.set()`, which would silently
	 * collapse repeated keys (`?filter=a&filter=b` becoming `?filter=b`).
	 * In the defensive case a target URL somehow does carry its own query
	 * already, both raw query strings are concatenated rather than merged
	 * key-by-key, so neither side's duplicate keys are ever dropped.
	 */
	function withCurrentQueryAndHash( targetUrl ) {
		try {
			var target = new URL( targetUrl, window.location.origin );
			var targetQuery = target.search ? target.search.slice( 1 ) : '';
			var currentQuery = window.location.search ? window.location.search.slice( 1 ) : '';
			var parts = [];
			if ( targetQuery ) {
				parts.push( targetQuery );
			}
			if ( currentQuery ) {
				parts.push( currentQuery );
			}
			target.search = parts.join( '&' );
			if ( window.location.hash ) {
				target.hash = window.location.hash;
			}
			return target.toString();
		} catch ( e ) {
			return targetUrl;
		}
	}

	/**
	 * Cookie-driven navigation, continued. No BLANKET "already redirected
	 * once" guard is kept — that is what previously (and incorrectly)
	 * suppressed a later, entirely legitimate redirect after the visitor
	 * explicitly changed language again in the same tab. Loop-freedom for
	 * the normal case rests on a structural invariant, not a flag: a
	 * redirect only fires while `code !== c.currentCode`, and landing on
	 * the target makes `c.currentCode` equal `code` on the next load, so
	 * this function cannot re-fire for that same visit regardless of
	 * `c.isDefaultUrl` (which tracks "current language is the site
	 * default", not literally "no URL prefix" — the two coincide today,
	 * but the real guarantee is the code-equality check). The same-URL
	 * check just below is a second, narrower defense for a pathological
	 * same-destination case. `LAST_REDIRECT_KEY` below is a third,
	 * destination-scoped (not blanket) last-resort cap — see its use.
	 */
	var LAST_REDIRECT_KEY = 'aiml_visitor_lang_last_redirect';

	function maybeRedirectFromCookie( c ) {
		if ( ! c.persistEnabled || ! c.isDefaultUrl ) {
			return;
		}

		var code = validCode( readCookie( cookieName() ), c.links );
		if ( ! code || code === c.currentCode ) {
			return;
		}

		var target = linkFor( code, c.links );
		if ( ! target || ! target.url ) {
			return;
		}

		var destination = withCurrentQueryAndHash( target.url );
		// Never replace the page with the URL already showing.
		if ( destination === window.location.href ) {
			return;
		}
		// Last-resort cap: never repeat the exact same automatic redirect
		// twice in a row in this tab. Keyed on the specific destination
		// (not a blanket "already redirected once" flag) so an entirely
		// different, later, legitimate redirect — e.g. after the visitor
		// explicitly changes language again — is never suppressed by this.
		// The primary loop-freedom argument is structural (redirecting
		// always lands on a page where `code === c.currentCode` becomes
		// true, so this function cannot fire again for that visit); this is
		// only a defensive net for an unexpected data inconsistency (e.g. a
		// relationship URL that round-trips back to the same effective page
		// after server-side canonicalization).
		if ( storageGet( window.sessionStorage, LAST_REDIRECT_KEY ) === destination ) {
			return;
		}

		storageSet( window.sessionStorage, LAST_REDIRECT_KEY, destination );
		window.location.replace( destination );
	}

	// -- Suggestion banner (browser/geo — automatic signals, suggestion only) --

	function normalizeLangTag( tag ) {
		return String( tag || '' ).toLowerCase();
	}

	function baseSubtag( tag ) {
		var i = tag.indexOf( '-' );
		return i > -1 ? tag.substring( 0, i ) : tag;
	}

	/** navigator.languages ordering is the closest available signal to Accept-Language
	 *  quality weighting client-side JS can read; no q-values are exposed to JS. */
	function browserMatch( c ) {
		var candidates = ( navigator.languages && navigator.languages.length )
			? navigator.languages
			: [ navigator.language ];

		for ( var i = 0; i < candidates.length; i++ ) {
			var tag = normalizeLangTag( candidates[ i ] );
			if ( ! tag ) {
				continue;
			}
			var exact = validCode( tag, c.links );
			if ( exact && exact !== c.currentCode ) {
				return exact;
			}
			var base = validCode( baseSubtag( tag ), c.links );
			if ( base && base !== c.currentCode ) {
				return base;
			}
		}
		return null;
	}

	function geoMatch( c, callback ) {
		if ( ! c.geoEnabled || ! c.geoRestUrl || typeof window.fetch !== 'function' ) {
			callback( null );
			return;
		}

		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = controller ? setTimeout( function () {
			controller.abort();
		}, GEO_TIMEOUT_MS ) : null;

		window.fetch( c.geoRestUrl, {
			credentials: 'omit',
			signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			return response.ok ? response.json() : null;
		} ).then( function ( data ) {
			if ( timer ) {
				clearTimeout( timer );
			}
			if ( ! data || ! data.country_code || ! c.geoLanguageMap ) {
				callback( null );
				return;
			}
			var mapped = c.geoLanguageMap[ String( data.country_code ).toUpperCase() ];
			var code = mapped ? validCode( normalizeLangTag( mapped ), c.links ) : null;
			callback( code && code !== c.currentCode ? code : null );
		} ).catch( function () {
			if ( timer ) {
				clearTimeout( timer );
			}
			callback( null ); // Fail-safe: absence/timeout/malformed response silently degrades.
		} );
	}

	function dismissedRecently() {
		var raw = storageGet( window.localStorage, DISMISS_KEY );
		if ( ! raw ) {
			return false;
		}
		var elapsedMs = Date.now() - parseInt( raw, 10 );
		return elapsedMs >= 0 && elapsedMs < DISMISS_DAYS * 24 * 60 * 60 * 1000;
	}

	function showBanner( c, code ) {
		var target = linkFor( code, c.links );
		if ( ! target ) {
			return;
		}

		var strings = window.aimlVisitorLanguageStrings || {};
		var label = target.label ? target.label : code;

		var bar = document.createElement( 'div' );
		bar.className = 'aiml-visitor-suggest';
		bar.setAttribute( 'role', 'region' );
		// Static, purpose-describing accessible name for the region itself
		// (not just the target language), plus aria-live so assistive tech
		// announces the banner's arrival without stealing keyboard focus.
		bar.setAttribute( 'aria-label', strings.regionLabel || 'Language suggestion' );
		bar.setAttribute( 'aria-live', 'polite' );

		var text = document.createElement( 'span' );
		text.className = 'aiml-visitor-suggest__text';
		text.textContent = ( strings.switchTo || 'Switch to {language}?' ).replace( '{language}', label );
		bar.appendChild( text );

		var accept = document.createElement( 'a' );
		accept.className = 'aiml-visitor-suggest__accept';
		accept.href = target.url;
		accept.setAttribute( 'data-aiml-code', code );
		accept.textContent = ( window.aimlVisitorLanguageStrings && window.aimlVisitorLanguageStrings.accept ) || 'Switch';
		bar.appendChild( accept );

		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'aiml-visitor-suggest__dismiss';
		dismiss.setAttribute( 'aria-label', ( window.aimlVisitorLanguageStrings && window.aimlVisitorLanguageStrings.dismiss ) || 'Dismiss' );
		dismiss.textContent = '×';
		dismiss.addEventListener( 'click', function () {
			storageSet( window.localStorage, DISMISS_KEY, String( Date.now() ) );
			bar.parentNode && bar.parentNode.removeChild( bar );
		} );
		bar.appendChild( dismiss );

		// Accepting is an explicit choice: the shared click-persist handler
		// (delegated on document) already writes the cookie for any
		// [data-aiml-code] element, this button included; navigation is a
		// plain <a href>, never blocked.

		document.body.appendChild( bar );
	}

	function maybeShowSuggestion( c ) {
		if ( ! c.browserEnabled && ! c.geoEnabled ) {
			return;
		}
		// An already-explicit cookie means the visitor already decided; never nag.
		if ( validCode( readCookie( cookieName() ), c.links ) ) {
			return;
		}
		if ( dismissedRecently() ) {
			return;
		}

		if ( c.browserEnabled ) {
			var browserCode = browserMatch( c );
			if ( browserCode ) {
				showBanner( c, browserCode );
				return;
			}
		}

		// Geo is lower priority than browser, and only consulted when browser
		// produced no usable match.
		geoMatch( c, function ( geoCode ) {
			if ( geoCode ) {
				showBanner( c, geoCode );
			}
		} );
	}

	function init() {
		var c = cfg();
		if ( ! c || ! c.links || c.links.length < 2 ) {
			return;
		}

		wireClickPersist( c );
		maybeRedirectFromCookie( c );
		maybeShowSuggestion( c );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
