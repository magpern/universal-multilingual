/**
 * Universal Multilingual — Languages admin screen behaviour.
 *
 * Progressive enhancement over a real form:
 *  - the language <select> is upgraded to a searchable WordPress
 *    ComboboxControl; with JavaScript off it stays a native <select> that
 *    submits normally;
 *  - the regional-variant <select> is shown only when the chosen language has
 *    more than one locale;
 *  - a read-only summary previews the URL prefix / locale / native name /
 *    direction. The server re-derives all of that authoritatively on submit;
 *    this is display only.
 *
 * All canonical values are derived server-side. Nothing here is trusted input.
 */
( function () {
	'use strict';

	var data = window.aimlLanguagesAdmin || {};
	var strings = data.i18n || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function optionsFromSelect( select ) {
		return Array.prototype.map.call( select.options, function ( opt ) {
			return { value: opt.value, label: opt.textContent };
		} ).filter( function ( o ) {
			return o.value !== '';
		} );
	}

	function mountCombobox( wp, select ) {
		var mount = select.parentNode.querySelector( '.aiml-language-combobox' );
		if ( ! mount || ! wp || ! wp.element || ! wp.components || ! wp.components.ComboboxControl ) {
			return;
		}

		var el = wp.element.createElement;
		var options = optionsFromSelect( select );

		function Control() {
			var state = wp.element.useState( select.value || '' );
			var value = state[ 0 ];
			var setValue = state[ 1 ];

			return el( wp.components.ComboboxControl, {
				label: strings.languageLabel || 'Language',
				hideLabelFromVision: true,
				options: options,
				value: value,
				allowReset: false,
				onChange: function ( next ) {
					var chosen = next || '';
					setValue( chosen );
					select.value = chosen;
					select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				},
			} );
		}

		var render = wp.element.render || ( wp.element.createRoot && function ( node, target ) {
			wp.element.createRoot( target ).render( node );
		} );

		render( el( Control ), mount );
		mount.classList.add( 'aiml-language-combobox--enhanced' );
		select.classList.add( 'aiml-language-select-native' );
	}

	function directionLabel( direction ) {
		if ( direction === 'rtl' ) {
			return strings.rtl || 'Right to left';
		}
		return strings.ltr || 'Left to right';
	}

	/**
	 * Best-effort client preview of the URL code. The server is authoritative.
	 */
	function previewCode( meta, usedCodes ) {
		if ( ! meta ) {
			return '';
		}
		var lang = meta.language_code;
		var region = meta.region || '';
		var variant = meta.variant || '';

		if ( variant && region ) {
			return lang + '-' + region + '-' + variant;
		}

		if ( usedCodes.indexOf( lang ) === -1 ) {
			return lang;
		}
		if ( region ) {
			return lang + '-' + region;
		}
		return lang;
	}

	function wireDerivedUi( groupSelect ) {
		var regionField = document.querySelector( '[data-aiml-region-field]' );
		var regionSelect = document.getElementById( 'aiml-region-select' );
		var summary = document.querySelector( '[data-aiml-summary]' );

		if ( ! data.groups || ! data.locales ) {
			return;
		}

		var usedCodes = ( data.existing || [] ).map( function ( row ) {
			return row.code;
		} );

		function currentLocale() {
			var group = data.groups[ groupSelect.value ];
			if ( ! group ) {
				return '';
			}
			if ( regionSelect && regionField && ! regionField.classList.contains( 'aiml-ui-field--hidden' ) ) {
				return regionSelect.value;
			}
			return group.locales[ 0 ];
		}

		function repaintRegion() {
			var group = data.groups[ groupSelect.value ];
			if ( ! regionField || ! regionSelect ) {
				return;
			}
			if ( ! group || group.locales.length < 2 ) {
				regionField.classList.add( 'aiml-ui-field--hidden' );
				regionSelect.innerHTML = '';
				return;
			}
			regionField.classList.remove( 'aiml-ui-field--hidden' );
			regionSelect.innerHTML = '';
			group.locales.forEach( function ( locale ) {
				var meta = data.locales[ locale ];
				var used = usedCodes.length && ( data.existing || [] ).some( function ( r ) {
					return r.locale === locale;
				} );
				var opt = document.createElement( 'option' );
				opt.value = locale;
				opt.textContent = ( meta ? meta.english_name : locale ) + ( used ? ' (' + ( strings.alreadyAdded || 'already added' ) + ')' : '' );
				opt.disabled = !! used;
				regionSelect.appendChild( opt );
			} );
			var firstEnabled = regionSelect.querySelector( 'option:not([disabled])' );
			if ( firstEnabled ) {
				regionSelect.value = firstEnabled.value;
			}
		}

		function repaintSummary() {
			if ( ! summary ) {
				return;
			}
			var locale = currentLocale();
			var meta = data.locales[ locale ];
			if ( ! meta ) {
				summary.hidden = true;
				return;
			}
			summary.hidden = false;
			var code = previewCode( meta, usedCodes );
			summary.querySelector( '[data-aiml-summary-url]' ).textContent = '/' + code + '/';
			summary.querySelector( '[data-aiml-summary-locale]' ).textContent = locale;
			summary.querySelector( '[data-aiml-summary-native]' ).textContent = meta.native_name;
			summary.querySelector( '[data-aiml-summary-direction]' ).textContent = directionLabel( meta.direction );
		}

		function repaint() {
			repaintRegion();
			repaintSummary();
		}

		groupSelect.addEventListener( 'change', repaint );
		if ( regionSelect ) {
			regionSelect.addEventListener( 'change', repaintSummary );
		}
		repaint();
	}

	ready( function () {
		var groupSelect = document.getElementById( 'aiml-language-select' );
		if ( ! groupSelect ) {
			return;
		}

		mountCombobox( window.wp, groupSelect );
		wireDerivedUi( groupSelect );
	} );
} )();
