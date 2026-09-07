/**
 * Progressive disclosure for the floating language selector.
 *
 * Language navigation is ordinary <a href>. Preference persistence is
 * best-effort keepalive fetch and never delays or replaces navigation.
 */
(function () {
	'use strict';

	function cfg() {
		return window.aimlFloatingSelector || {};
	}

	function persistPreference(code) {
		var options = cfg();
		if (!options.persist || !options.ajaxUrl || !options.nonce || !code) {
			return;
		}
		if (typeof window.fetch !== 'function') {
			return;
		}
		try {
			var body = 'action=' + encodeURIComponent(options.action || '') +
				'&_ajax_nonce=' + encodeURIComponent(options.nonce) +
				'&code=' + encodeURIComponent(code);
			window.fetch(options.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body
			});
		} catch (e) {
			// Navigation remains authoritative.
		}
	}

	function enhance(root) {
		if (!root || root.getAttribute('data-aiml-enhanced') === '1') {
			return;
		}

		var toggle = root.querySelector('.aiml-floating-selector__toggle');
		var panel = root.querySelector('.aiml-floating-selector__panel');
		if (!toggle || !panel) {
			return;
		}

		function setOpen(open) {
			root.setAttribute('data-aiml-open', open ? '1' : '0');
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		}

		function close() {
			setOpen(false);
		}

		function toggleOpen() {
			setOpen(root.getAttribute('data-aiml-open') !== '1');
		}

		root.setAttribute('data-aiml-enhanced', '1');
		toggle.hidden = false;
		setOpen(false);

		toggle.addEventListener('click', function () {
			toggleOpen();
		});

		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') {
				return;
			}
			if (root.getAttribute('data-aiml-open') !== '1') {
				return;
			}
			event.preventDefault();
			close();
			toggle.focus();
		});

		document.addEventListener('click', function (event) {
			if (!root.contains(event.target)) {
				close();
			}
		});

		panel.querySelectorAll('a[data-aiml-code]').forEach(function (link) {
			link.addEventListener('click', function (event) {
				if (event.button && event.button !== 0) {
					return;
				}
				if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
					return;
				}
				persistPreference(link.getAttribute('data-aiml-code') || '');
			});
		});
	}

	function init() {
		document.querySelectorAll('.aiml-floating-selector').forEach(enhance);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	window.aimlFloatingSelectorInit = init;
})();
