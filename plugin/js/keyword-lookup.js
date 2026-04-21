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
	var INTERACTION_MODE = config.interactionMode || 'suggestion';
	var MIN_CHARS = config.minChars || 3;
	var DEBOUNCE_MS = 300;

	if (!SUGGEST_URL) {
		return;
	}

	var SAVE_URL = config.saveUrl || '';
	var debounceTimer = null;
	var activeDropdown = null;
	var activeInput = null;
	var activeIndex = -1; // keyboard navigation index
	var activeResults = []; // current result set for keyboard selection
	var _injectingKeyword = false; // guard: programmatic Enter for OJS field injection

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

		activeResults = results;
		activeIndex = -1;

		var dropdown = document.createElement('div');
		dropdown.className = 'nv-suggest-dropdown';
		dropdown.setAttribute('role', 'listbox');

		results.forEach(function (item, idx) {
			var row = document.createElement('div');
			row.className = 'nv-suggest-item';
			row.setAttribute('role', 'option');
			row.setAttribute('tabindex', '-1');
			row.setAttribute('aria-selected', 'false');
			row.id = 'nv-suggest-option-' + idx;
			row.dataset.index = idx;

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
		input.setAttribute('aria-expanded', 'true');
	}

	/**
	 * Update visual highlight on the active dropdown item.
	 */
	function updateHighlight() {
		if (!activeDropdown) return;
		var items = activeDropdown.querySelectorAll('.nv-suggest-item');
		for (var i = 0; i < items.length; i++) {
			items[i].classList.toggle('nv-suggest-item--active', i === activeIndex);
			items[i].setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
		}
		// Scroll active item into view and update aria-activedescendant
		if (activeIndex >= 0 && items[activeIndex]) {
			items[activeIndex].scrollIntoView({ block: 'nearest' });
			if (activeInput) {
				activeInput.setAttribute('aria-activedescendant', items[activeIndex].id);
			}
		} else if (activeInput) {
			activeInput.removeAttribute('aria-activedescendant');
		}
	}

	/**
	 * Handle keyboard navigation in the dropdown.
	 * ArrowDown/ArrowUp to move, Enter to select, Escape to close.
	 */
	function handleKeydown(e) {
		if (!activeDropdown || !activeResults.length) return;

		if (e.key === 'ArrowDown') {
			e.preventDefault();
			activeIndex = (activeIndex + 1) % activeResults.length;
			updateHighlight();
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			activeIndex = activeIndex <= 0 ? activeResults.length - 1 : activeIndex - 1;
			updateHighlight();
		} else if (e.key === 'Enter') {
			if (activeIndex >= 0 && activeIndex < activeResults.length) {
				e.preventDefault();
				selectItem(activeInput, activeResults[activeIndex]);
			}
		} else if (e.key === 'Escape') {
			hideDropdown();
		}
	}

	/**
	 * Hide the active dropdown.
	 */
	function hideDropdown() {
		if (activeInput) {
			activeInput.setAttribute('aria-expanded', 'false');
			activeInput.removeAttribute('aria-activedescendant');
		}
		if (activeDropdown && activeDropdown.parentNode) {
			activeDropdown.parentNode.removeChild(activeDropdown);
		}
		activeDropdown = null;
		activeInput = null;
		activeIndex = -1;
		activeResults = [];
	}

	/**
	 * Handle selection of a thesaurus concept.
	 * Injects the keyword into the OJS native field and persists NV metadata.
	 */
	function selectItem(input, item) {
		hideDropdown();
		clearBypassWarning(input);

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

		// Inject into OJS native keyword field (Publication::keywords)
		// so keywords appear in OAI-PMH, Crossref, and the public view.
		commitToOjsField(input, item.label_primary);

		// Persist NV metadata to server
		autoSave();
	}

	/**
	 * Handle free-text keyword entry (bypass mode).
	 * Injects into OJS native field and shows an unvalidated warning.
	 */
	function addFreeTextKeyword(input) {
		var value = input.value.trim();
		if (!value) return;

		hideDropdown();

		var kwdEntry = {
			kwd_value: value,
			kwd_uri: '',
			kwd_lang: detectLang(),
			kwd_thesaurus: '',
			kwd_validated: false
		};

		var exists = selectedKeywords.some(function (k) {
			return k.kwd_value === kwdEntry.kwd_value && !k.kwd_uri;
		});
		if (!exists) {
			selectedKeywords.push(kwdEntry);
		}

		showBypassWarning(input);
		// Inject into OJS native keyword field
		commitToOjsField(input, value);
		// Persist NV metadata to server
		autoSave();
	}

	/**
	 * Commit a keyword value into the OJS native keyword field.
	 * Sets the input value and dispatches Enter so the Vue.js
	 * FieldControlledVocab component captures the keyword in its own state.
	 */
	function commitToOjsField(input, value) {
		_injectingKeyword = true;
		input.value = value;
		input.dispatchEvent(new Event('input', { bubbles: true }));
		setTimeout(function () {
			input.dispatchEvent(new KeyboardEvent('keydown', {
				key: 'Enter', code: 'Enter', keyCode: 13, which: 13,
				bubbles: true, cancelable: true
			}));
			input.dispatchEvent(new KeyboardEvent('keyup', {
				key: 'Enter', code: 'Enter', keyCode: 13, which: 13,
				bubbles: true
			}));
			_injectingKeyword = false;
		}, 50);
	}

	/**
	 * Auto-save NV keyword metadata to the server immediately.
	 */
	function autoSave() {
		var subId = getSubmissionId();
		if (subId) saveKeywords(subId);
	}

	/**
	 * Show bypass warning below the input.
	 */
	function showBypassWarning(input) {
		var container = input.closest('.pkpFormField') || input.parentNode;
		if (container.querySelector('.nv-bypass-warning')) return;
		var warning = document.createElement('span');
		warning.className = 'nv-bypass-warning';
		warning.setAttribute('role', 'alert');
		warning.textContent = 'Mot-cl\u00e9 libre \u2014 non valid\u00e9 par un th\u00e9saurus contr\u00f4l\u00e9.';
		container.appendChild(warning);
	}

	/**
	 * Clear bypass warning.
	 */
	function clearBypassWarning(input) {
		var container = input.closest('.pkpFormField') || input.parentNode;
		var warning = container.querySelector('.nv-bypass-warning');
		if (warning) warning.parentNode.removeChild(warning);
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
		removeBtn.setAttribute('aria-label', 'Remove ' + kwdEntry.kwd_value);
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
		if (selectedKeywords.length === 0 || !submissionId || !SAVE_URL) {
			return;
		}

		var saveUrl = SAVE_URL;
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
			input.setAttribute('role', 'combobox');
			input.setAttribute('aria-autocomplete', 'list');
			input.setAttribute('aria-expanded', 'false');
			input.setAttribute('aria-haspopup', 'listbox');
			input.addEventListener('input', onInput);
			input.addEventListener('keydown', function (e) {
				// Skip mode enforcement for programmatic keyword injection
				if (_injectingKeyword) return;
				// Mode-dependent Enter behavior (when no dropdown item is active)
				if (e.key === 'Enter' && (!activeDropdown || activeIndex < 0)) {
					if (INTERACTION_MODE === 'choice') {
						// Block free text — must select from dropdown
						e.preventDefault();
						return;
					}
					if (INTERACTION_MODE === 'bypass' && input.value.trim()) {
						e.preventDefault();
						addFreeTextKeyword(input);
						return;
					}
				}
				handleKeydown(e);
			});
			input.addEventListener('blur', function () {
				// Delay to allow click on dropdown items
				setTimeout(function () {
					if (_injectingKeyword) return;
					hideDropdown();
					// In bypass mode, commit free text on blur
					if (INTERACTION_MODE === 'bypass' && input.value.trim()) {
						addFreeTextKeyword(input);
					}
				}, 200);
			});

			// Mode indicator: CSS class + mode-specific UI
			var modeContainer = input.closest('.pkpFormField') || input.parentNode;
			modeContainer.classList.add('nv-mode-' + INTERACTION_MODE);
			input.dataset.nvMode = INTERACTION_MODE;

			if (INTERACTION_MODE === 'choice') {
				if (!input.getAttribute('placeholder')) {
					input.setAttribute('placeholder', 'S\u00e9lectionnez un terme du th\u00e9saurus\u2026');
				}
				// Block paste of free text that bypasses autocomplete
				input.addEventListener('paste', function (pe) {
					// Allow paste for search triggering, but visually signal restriction
					var container = input.closest('.pkpFormField') || input.parentNode;
					if (!container.querySelector('.nv-choice-hint')) {
						var hint = document.createElement('span');
						hint.className = 'nv-choice-hint';
						hint.setAttribute('role', 'status');
						hint.textContent = 'S\u00e9lection obligatoire depuis le th\u00e9saurus.';
						container.appendChild(hint);
						setTimeout(function () {
							if (hint.parentNode) hint.parentNode.removeChild(hint);
						}, 3000);
					}
				});
			}

			if (INTERACTION_MODE === 'bypass') {
				if (!modeContainer.querySelector('.nv-bypass-notice')) {
					var notice = document.createElement('span');
					notice.className = 'nv-bypass-notice';
					notice.textContent = 'Mode libre \u2014 les termes non valid\u00e9s seront signal\u00e9s.';
					modeContainer.appendChild(notice);
				}
			}
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
