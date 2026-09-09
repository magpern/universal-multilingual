/* global aimlPromotionAdmin */
( function () {
	'use strict';

	const cfg = window.aimlPromotionAdmin || {};
	const apiFetch = window.wp && window.wp.apiFetch;
	const domReady = window.wp && window.wp.domReady;
	if ( ! apiFetch || ! domReady ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( cfg.nonce ) );

	const NS = '/' + ( cfg.restNamespace || 'aiml/v1' ) + '/promotion';
	const ACK_CATEGORIES = [ 'stale_source', 'conflict_target_modified', 'route_conflict' ];
	const SAFE_CATEGORIES = [ 'new', 'update' ];

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else if ( k === 'html' ) {
				node.innerHTML = attrs[ k ];
			} else if ( k === 'class' ) {
				node.className = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function notice( root, type, message ) {
		const box = el( 'div', { class: 'notice notice-' + type, html: '<p></p>' } );
		box.querySelector( 'p' ).textContent = message;
		root.prepend( box );
	}

	function downloadJson( filename, content ) {
		const blob = new Blob( [ content ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const a = el( 'a', { href: url, download: filename } );
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	// ---- Export ----

	function buildExport( container ) {
		const wrap = el( 'div', { class: 'aiml-promotion-card' } );
		wrap.appendChild( el( 'h2', { text: 'Export' } ) );

		const langFields = ( cfg.languages || [] ).map( function ( l ) {
			const id = 'aiml-pl-' + l.code;
			return el( 'label', { class: 'aiml-promotion-check', for: id }, [
				el( 'input', { type: 'checkbox', id: id, value: l.code } ),
				' ' + l.name + ' (' + l.locale + ')',
			] );
		} );
		wrap.appendChild( el( 'fieldset', {}, [ el( 'legend', { text: 'Target languages' } ) ].concat( langFields ) ) );

		const approved = el( 'input', { type: 'checkbox', id: 'aiml-approved-only' } );
		const published = el( 'input', { type: 'checkbox', id: 'aiml-published-only' } );
		const changed = el( 'input', { type: 'date', id: 'aiml-changed-since' } );
		wrap.appendChild( el( 'p', {}, [
			el( 'label', { for: 'aiml-approved-only' }, [ approved, ' Approved translations only' ] ),
			el( 'br' ),
			el( 'label', { for: 'aiml-published-only' }, [ published, ' Published translations only' ] ),
			el( 'br' ),
			el( 'label', { for: 'aiml-changed-since' }, [ 'Changed since ', changed ] ),
		] ) );

		const summary = el( 'p', { class: 'aiml-promotion-summary' } );
		const button = el( 'button', { class: 'button button-primary', type: 'button', text: 'Build package' } );
		button.addEventListener( 'click', function () {
			const languages = Array.prototype.slice
				.call( wrap.querySelectorAll( 'fieldset input:checked' ) )
				.map( function ( i ) { return i.value; } );
			if ( ! languages.length ) {
				summary.textContent = 'Select at least one language.';
				return;
			}
			button.disabled = true;
			summary.textContent = 'Building…';
			apiFetch( {
				path: NS + '/export',
				method: 'POST',
				data: {
					languages: languages,
					approved_only: approved.checked,
					published_only: published.checked,
					changed_since: changed.value || null,
				},
			} ).then( function ( res ) {
				downloadJson( res.filename, res.package );
				summary.textContent =
					'Package ' + res.package_id + ' — ' + res.counts.objects + ' objects, ' + res.counts.segments + ' segments. Checksum ' + res.package_checksum.slice( 0, 12 ) + '…';
			} ).catch( function ( err ) {
				summary.textContent = ( err && err.message ) || cfg.i18n.exportError;
			} ).finally( function () {
				button.disabled = false;
			} );
		} );

		wrap.appendChild( el( 'p', {}, [ button ] ) );
		wrap.appendChild( summary );
		container.appendChild( wrap );
	}

	// ---- Import ----

	function buildImport( container ) {
		const wrap = el( 'div', { class: 'aiml-promotion-card' } );
		wrap.appendChild( el( 'h2', { text: 'Import' } ) );

		const state = { raw: null, token: null, plan: null };

		const file = el( 'input', { type: 'file', accept: '.json,application/json' } );
		const validateBtn = el( 'button', { class: 'button', type: 'button', text: 'Validate & dry-run', disabled: 'disabled' } );
		const report = el( 'div', { class: 'aiml-promotion-report' } );

		file.addEventListener( 'change', function () {
			validateBtn.disabled = ! file.files.length;
			report.innerHTML = '';
			state.raw = null;
			state.token = null;
			state.plan = null;
		} );

		validateBtn.addEventListener( 'click', function () {
			const f = file.files[ 0 ];
			if ( ! f ) {
				return;
			}
			validateBtn.disabled = true;
			report.textContent = 'Reading…';
			f.text().then( function ( text ) {
				state.raw = text;
				return apiFetch( {
					path: NS + '/import/validate',
					method: 'POST',
					body: text,
					headers: { 'Content-Type': 'application/json' },
				} );
			} ).then( function ( res ) {
				state.token = res.review_token;
				state.plan = res.plan;
				renderPlan( report, state );
			} ).catch( function ( err ) {
				report.textContent = ( err && err.message ) || cfg.i18n.validateError;
			} ).finally( function () {
				validateBtn.disabled = false;
			} );
		} );

		wrap.appendChild( el( 'p', {}, [ file, ' ', validateBtn ] ) );
		wrap.appendChild( report );
		container.appendChild( wrap );
	}

	function renderPlan( report, state ) {
		report.innerHTML = '';
		const counts = state.plan.counts || {};

		const cards = el( 'div', { class: 'aiml-promotion-cards' } );
		Object.keys( counts ).forEach( function ( cat ) {
			cards.appendChild( el( 'span', { class: 'aiml-promotion-count aiml-promotion-count--' + cat, text: cat + ': ' + counts[ cat ] } ) );
		} );
		report.appendChild( cards );

		// Mode.
		const modeName = 'aiml-apply-mode';
		const modes = [ [ 'safe_only', 'Safe only (new + updates)' ], [ 'selective', 'Selective (choose rows)' ], [ 'force', 'Force (overwrite conflicts)' ] ];
		const modeBox = el( 'fieldset', {}, [ el( 'legend', { text: 'Apply mode' } ) ] );
		modes.forEach( function ( m, i ) {
			const input = el( 'input', { type: 'radio', name: modeName, value: m[ 0 ], id: modeName + '-' + m[ 0 ] } );
			if ( i === 0 ) {
				input.checked = true;
			}
			modeBox.appendChild( el( 'label', { for: modeName + '-' + m[ 0 ] }, [ input, ' ' + m[ 1 ] ] ) );
			modeBox.appendChild( el( 'br' ) );
		} );
		report.appendChild( modeBox );

		// Detail table.
		const table = el( 'table', { class: 'widefat striped aiml-promotion-table' } );
		table.appendChild( el( 'thead', { html: '<tr><th></th><th>Object</th><th>Segment</th><th>Language</th><th>Category</th><th>Reason</th></tr>' } ) );
		const tbody = el( 'tbody' );
		( state.plan.rows || [] ).forEach( function ( row ) {
			const tr = el( 'tr' );
			const pick = el( 'input', { type: 'checkbox', 'data-row': row.row_id, 'data-cat': row.category } );
			if ( SAFE_CATEGORIES.indexOf( row.category ) !== -1 ) {
				pick.checked = true;
			}
			tr.appendChild( el( 'td', {}, [ pick ] ) );
			tr.appendChild( el( 'td', { text: row.object_ref.natural_key } ) );
			tr.appendChild( el( 'td', { text: row.segment_ref.segment_key } ) );
			tr.appendChild( el( 'td', { text: row.segment_ref.language_code } ) );
			tr.appendChild( el( 'td', { text: row.category } ) );
			tr.appendChild( el( 'td', { text: row.reason_code } ) );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		report.appendChild( table );

		let trust = null;
		if ( cfg.canTrust ) {
			trust = el( 'input', { type: 'checkbox', id: 'aiml-trust-review' } );
			report.appendChild( el( 'p', {}, [ el( 'label', { for: 'aiml-trust-review' }, [ trust, ' Carry review / publication state (uses the existing services)' ] ) ] ) );
		}

		const applyBtn = el( 'button', { class: 'button button-primary', type: 'button', text: 'Apply' } );
		const result = el( 'div', { class: 'aiml-promotion-result' } );
		applyBtn.addEventListener( 'click', function () {
			const mode = report.querySelector( 'input[name="' + modeName + '"]:checked' ).value;
			const picked = Array.prototype.slice.call( tbody.querySelectorAll( 'input[type="checkbox"]:checked' ) );
			const allow = picked.map( function ( c ) { return c.getAttribute( 'data-row' ); } );
			const acks = picked
				.filter( function ( c ) { return ACK_CATEGORIES.indexOf( c.getAttribute( 'data-cat' ) ) !== -1; } )
				.map( function ( c ) { return c.getAttribute( 'data-row' ); } );

			applyBtn.disabled = true;
			result.textContent = 'Applying…';

			const query = new URLSearchParams( {
				review_token: state.token,
				mode: mode,
				plan: JSON.stringify( state.plan ),
				row_allowlist: JSON.stringify( allow ),
				acknowledgements: JSON.stringify( acks ),
				trust_review: trust && trust.checked ? '1' : '0',
			} ).toString();

			apiFetch( {
				path: NS + '/import/apply?' + query,
				method: 'POST',
				body: state.raw,
				headers: { 'Content-Type': 'application/json' },
			} )
				.then( function ( res ) {
					if ( res && res.refused_code ) {
						result.textContent = res.refused_code === 'plan_drifted' ? cfg.i18n.reReview : ( 'Refused: ' + res.refused_message );
						return;
					}
					result.textContent =
						'Applied ' + ( res.applied_count || 0 ) + '. Skipped ' +
						Object.values( res.skipped_by_category || {} ).reduce( function ( a, b ) { return a + b; }, 0 ) +
						'. Changed since dry-run: ' + ( res.changed_since_dry_run || [] ).length + '.';
				} )
				.catch( function ( err ) {
					result.textContent = ( err && err.message ) || cfg.i18n.applyError;
				} )
				.finally( function () {
					applyBtn.disabled = false;
				} );
		} );
		report.appendChild( el( 'p', {}, [ applyBtn ] ) );
		report.appendChild( result );
	}

	// ---- History ----

	function buildHistory( container ) {
		const wrap = el( 'div', { class: 'aiml-promotion-card' } );
		wrap.appendChild( el( 'h2', { text: 'History' } ) );
		const list = el( 'div', { text: 'Loading…' } );
		wrap.appendChild( list );
		container.appendChild( wrap );

		apiFetch( { path: NS + '/history?limit=25' } ).then( function ( res ) {
			list.innerHTML = '';
			if ( ! res.items || ! res.items.length ) {
				list.textContent = 'No promotions yet.';
				return;
			}
			const table = el( 'table', { class: 'widefat striped' } );
			table.appendChild( el( 'thead', { html: '<tr><th>When</th><th>Direction</th><th>Mode</th><th>Result</th><th>Package</th></tr>' } ) );
			const tbody = el( 'tbody' );
			res.items.forEach( function ( item ) {
				tbody.appendChild( el( 'tr', { html:
					'<td>' + item.created_at + '</td><td>' + item.direction + '</td><td>' + ( item.mode || '' ) +
					'</td><td>' + item.result + '</td><td><code>' + item.package_id.slice( 0, 12 ) + '…</code></td>' } ) );
			} );
			table.appendChild( tbody );
			list.appendChild( table );
		} ).catch( function () {
			list.textContent = 'Could not load history.';
		} );
	}

	domReady( function () {
		const root = document.getElementById( 'aiml-promotion-admin-root' );
		if ( ! root ) {
			return;
		}
		buildExport( root );
		buildImport( root );
		buildHistory( root );
	} );
} )();
