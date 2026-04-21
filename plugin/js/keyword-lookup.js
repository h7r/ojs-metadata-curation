/**
 * @file js/keyword-lookup.js
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3.
 *
 * @brief Autocomplete widget for SKOS keyword lookup.
 *        Attaches to OJS keyword input fields, queries the /suggest
 *        endpoint, and displays matching thesaurus concepts.
 *
 *        Config injected via window.nvMetadataCuration by the plugin.
 */
(function () {
	'use strict';

	var config = window.nvMetadataCuration || {};
	var SUGGEST_URL = config.suggestUrl || '';
	var THESAURUS = config.thesaurus || 'unesco';
	var MIN_CHARS = config.minChars || 3;
	var DEBOUNCE_MS = 300;

	if (!SUGGEST_URL) {
		return;
	}

	var debounceTimer = null;
	var activeDropdown = null;
	var activeInput = null;

	// Accumulate selected keywords per submission for batch save
	var selectedKeywords = [];

	/**
	 * Debounce helper.
	 */
	function debounce(fn, ms) {
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(function () {
				fn.apply(ctx, args);
			}, ms);
		};
	}

	/**
	 * Fetch suggestions from the plugin endpoint.
	 */
	function fetchSuggestions(query, lang, callback) {
		var params = new URLSearchParams({
			q: query,
			lang: lang,
			thesaurus: THESAURUS
		});

		var xhr = new XMLHttpRequest();
		xhr.open('POST', SUGGEST_URL);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.timeout = 3000;

		xhr.onload = function () {
			if (xhr.status === 200) {
				try {
					var data = JSON.parse(xhr.responseText);
					callback(null, data);
				} catch (e) {
					callback(e, null);
				}
			} else {
				callback(new Error('HTTP ' + xhr.status), null);
			}
		};
		xhr.onerror = function () { callback(new Error('Network error'), null); };
		xhr.ontimeout = function () { callback(new Error('Timeout'), null); };

		xhr.send(params.toString());
	}

	/**
	 * Create and position the dropdown under the input.
	 */
	function showDropdown(input, results) {
		hideDropdown();

		if (!results || results.length === 0) {
			return;
		}

		var dropdown = document.createElement('div');
		dropdown.className = 'nv-suggest-dropdown';

		results.forEach(function (item) {
			var row = document.createElement('div');
			row.className = 'nv-suggest-item';

			var label = document.createElement('span');
			label.className = 'nv-suggest-label';
			label.textContent = item.label_primary;

			row.appendChild(label);

			if (item.broader_label) {
				var broader = document.createElement('span');
				broader.className = 'nv-suggest-broader';
				broader.textContent = ' \u2190 ' + item.broader_label;
				row.appendChild(broader);
			}

			if (item.label_translation && item.label_translation !== item.label_primary) {
				var trans = document.createElement('span');
				trans.className = 'nv-suggest-translation';
				trans.textContent = '(' + item.label_translation + ')';
				row.appendChild(trans);
			}

			row.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				selectItem(input, item);
			});

			dropdown.appendChild(row);
		});

		// Position relative to input
		var rect = input.getBoundingClientRect();
		dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
		dropdown.style.left = (rect.left + window.scrollX) + 'px';
		dropdown.style.width = rect.width + 'px';

		document.body.appendChild(dropdown);
		activeDropdown = dropdown;
		activeInput = input;
	}

	/**
	 * Hide the active dropdown.
	 */
	function hideDropdown() {
		if (activeDropdown && activeDropdown.parentNode) {
			activeDropdown.parentNode.removeChild(activeDropdown);
		}
		activeDropdown = null;
		activeInput = null;
	}

	/**
	 * Handle selection of a thesaurus concept.
	 * Adds the keyword to the selection list and persists via /save endpoint.
	 */
	function selectItem(input, item) {
		input.value = item.label_primary;
		hideDropdown();

		var kwdEntry = {
			kwd_value: item.label_primary,
			kwd_uri: item.uri,
			kwd_lang: item.lang_primary,
			kwd_thesaurus: THESAURUS,
			kwd_validated: true
		};

		// Avoid duplicates by URI
		var exists = selectedKeywords.some(function (k) {
			return k.kwd_uri === kwdEntry.kwd_uri;
		});
		if (!exists) {
			selectedKeywords.push(kwdEntry);
		}

		// Visual feedback: add a tag chip next to the input
		addTagChip(input, kwdEntry);

		// Clear the input for next keyword entry
		input.value = '';
	}

	/**
	 * Add a visible tag chip showing the selected keyword.
	 */
	function addTagChip(input, kwdEntry) {
		var container = input.closest('.pkpFormField') || input.parentNode;
		var tagZone = container.querySelector('.nv-tag-zone');
		if (!tagZone) {
			tagZone = document.createElement('div');
			tagZone.className = 'nv-tag-zone';
			container.appendChild(tagZone);
		}

		var chip = document.createElement('span');
		chip.className = 'nv-tag-chip';
		chip.textContent = kwdEntry.kwd_value;

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'nv-tag-remove';
		removeBtn.textContent = '\u00d7';
		removeBtn.addEventListener('click', function () {
			selectedKeywords = selectedKeywords.filter(function (k) {
				return k.kwd_uri !== kwdEntry.kwd_uri;
			});
			chip.parentNode.removeChild(chip);
		});

		chip.appendChild(removeBtn);
		tagZone.appendChild(chip);
	}

	/**
	 * Save accumulated keywords to submission_settings via the plugin endpoint.
	 * Called when the user saves the metadata form.
	 */
	function saveKeywords(submissionId) {
		if (selectedKeywords.length === 0 || !submissionId) {
			return;
		}

		var saveUrl = SUGGEST_URL.replace('/suggest', '/save');
		var params = new URLSearchParams({
			submissionId: submissionId,
			keywords: JSON.stringify(selectedKeywords)
		});

		var xhr = new XMLHttpRequest();
		xhr.open('POST', saveUrl);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.timeout = 5000;
		xhr.send(params.toString());
	}

	/**
	 * Try to extract submissionId from the current OJS page URL or form data.
	 */
	function getSubmissionId() {
		// OJS 3.4.x URL pattern: /index.php/{journal}/submission/{id}/...
		var match = window.location.pathname.match(/\/submission\/(\d+)/);
		if (match) return match[1];
		// Fallback: look for a hidden input
		var el = document.querySelector('input[name="submissionId"]');
		return el ? el.value : null;
	}

	/**
	 * Detect the current form language from OJS locale or default to 'es'.
	 */
	function detectLang() {
		var html = document.documentElement;
		var locale = html.getAttribute('lang') || '';
		if (locale.indexOf('fr') === 0) return 'fr';
		if (locale.indexOf('en') === 0) return 'en';
		return 'es';
	}

	/**
	 * Attach autocomplete behaviour to keyword input fields.
	 */
	function init() {
		// OJS keyword fields: look for inputs within keyword-related containers
		var selectors = [
			'input[name="keywords"]',
			'input[name*="keyword"]',
			'.pkpFormField--keywords input[type="text"]',
			'[id*="keyword"] input[type="text"]'
		];

		var inputs = [];
		selectors.forEach(function (sel) {
			var found = document.querySelectorAll(sel);
			for (var i = 0; i < found.length; i++) {
				if (inputs.indexOf(found[i]) === -1) {
					inputs.push(found[i]);
				}
			}
		});

		var lang = detectLang();

		var onInput = debounce(function (e) {
			var value = e.target.value.trim();
			if (value.length < MIN_CHARS) {
				hideDropdown();
				return;
			}
			fetchSuggestions(value, lang, function (err, data) {
				if (err || !data) {
					hideDropdown();
					return;
				}
				showDropdown(e.target, data.results || []);
			});
		}, DEBOUNCE_MS);

		inputs.forEach(function (input) {
			if (input._nvBound) return; // Prevent double-binding on re-init
			input._nvBound = true;
			input.addEventListener('input', onInput);
			input.addEventListener('blur', function () {
				// Delay to allow click on dropdown items
				setTimeout(hideDropdown, 200);
			});
		});

		// Hook into OJS form save to persist keywords
		var saveButtons = document.querySelectorAll(
			'button[type="submit"], .pkpFormField--keywords button, [id*="submitFormButton"]'
		);
		saveButtons.forEach(function (btn) {
			if (btn._nvSaveBound) return;
			btn._nvSaveBound = true;
			btn.addEventListener('click', function () {
				var subId = getSubmissionId();
				if (subId) saveKeywords(subId);
			});
		});
	}

	// Close dropdown on outside click
	document.addEventListener('click', function (e) {
		if (activeDropdown && !activeDropdown.contains(e.target) && e.target !== activeInput) {
			hideDropdown();
		}
	});

	// Init when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-init on dynamic form loads (OJS SPA-like navigation)
	var observer = new MutationObserver(function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			if (mutations[i].addedNodes.length > 0) {
				init();
				break;
			}
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
})();
