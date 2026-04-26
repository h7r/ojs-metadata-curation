/**
 * @file js/keyword-lookup.js
 *
 * @brief Autocomplete widget for SPARQL keyword lookup.
 *        Attaches to OJS keyword input fields, queries the /suggest
 *        endpoint, and displays matching thesaurus concepts.
 *
 *        Config injected via window.nvMetadataCuration by the plugin.
 */
(function () {
	'use strict';

	var config = window.nvMetadataCuration || {};
	var i18n = config.i18n || {};
	var SUGGEST_URL = config.suggestUrl || '';
	var THESAURUS = config.thesaurus || 'unesco';
	var MIN_CHARS = config.minChars || 3;
	var DEBOUNCE_MS = 300;

	if (!SUGGEST_URL) {
		return;
	}

	var SAVE_URL = config.saveUrl || '';
	var CSRF_TOKEN = config.csrfToken || '';
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
				callback(new Error((i18n.errorHttp || 'HTTP {status}').replace('{status}', xhr.status)), null);
			}
		};
		xhr.onerror = function () { callback(new Error(i18n.errorNetwork || 'Network error'), null); };
		xhr.ontimeout = function () { callback(new Error(i18n.errorTimeout || 'Timeout'), null); };

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
				broader.textContent = ' ← ' + item.broader_label;
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

		// Position fixed relative to viewport (stable on scroll)
		var rect = input.getBoundingClientRect();
		dropdown.style.top = rect.bottom + 'px';
		dropdown.style.left = rect.left + 'px';
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
	 * Commit a keyword value into the OJS native keyword field.
	 * Sets the input value and dispatches Enter so the Vue.js
	 * FieldControlledVocab component captures the keyword in its own state.
	 */
	function commitToOjsField(input, value) {
		_injectingKeyword = true;
		// Two try-blocks: the flag must survive the 50 ms async gap, so the
		// sync path resets only on throw, and the timeout reliably resets
		// after the Enter dispatch — including if a listener throws.
		try {
			input.value = value;
			input.dispatchEvent(new Event('input', { bubbles: true }));
		} catch (e) {
			_injectingKeyword = false;
			throw e;
		}
		setTimeout(function () {
			try {
				input.dispatchEvent(new KeyboardEvent('keydown', {
					key: 'Enter', code: 'Enter', keyCode: 13, which: 13,
					bubbles: true, cancelable: true
				}));
				input.dispatchEvent(new KeyboardEvent('keyup', {
					key: 'Enter', code: 'Enter', keyCode: 13, which: 13,
					bubbles: true
				}));
			} finally {
				_injectingKeyword = false;
			}
		}, 50);
	}

	function warnNoSubmissionId() {
		console.warn('[nv] could not resolve submission id on URL:', window.location.href,
		             '— curation NOT saved to submission_settings');
	}

	/**
	 * Auto-save NV keyword metadata to the server immediately.
	 */
	function autoSave() {
		var subId = getSubmissionId();
		if (subId) saveKeywords(subId);
		else warnNoSubmissionId();
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
		removeBtn.textContent = '×';
		removeBtn.setAttribute('aria-label', (i18n.removeKeyword || 'Remove {value}').replace('{value}', kwdEntry.kwd_value));
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

		var params = new URLSearchParams({
			submissionId: submissionId,
			keywords: JSON.stringify(selectedKeywords)
		});

		var xhr = new XMLHttpRequest();
		xhr.open('POST', SAVE_URL);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		if (CSRF_TOKEN) xhr.setRequestHeader('X-Csrf-Token', CSRF_TOKEN);
		xhr.timeout = 5000;

		xhr.onload = function () {
			if (xhr.status === 200) {
				showSaveStatus('success');
			} else {
				showSaveStatus('error');
			}
		};
		xhr.onerror = function () { showSaveStatus('error'); };
		xhr.ontimeout = function () { showSaveStatus('error'); };

		xhr.send(params.toString());
	}

	/**
	 * Display a brief save-status toast next to the keyword tag zone.
	 * Auto-removes after 4 seconds. Accessible via role="status".
	 */
	function showSaveStatus(type) {
		var tagZone = document.querySelector('.nvKeywordTagZone');
		if (!tagZone) return;
		var existing = tagZone.querySelector('.nv-save-status');
		if (existing) existing.remove();
		var msg = document.createElement('span');
		msg.className = 'nv-save-status nv-save-' + type;
		msg.setAttribute('role', 'status');
		msg.setAttribute('aria-live', 'polite');
		msg.textContent = type === 'success'
			? '✓ ' + (i18n.saveSuccess || 'Keywords saved')
			: '✗ ' + (i18n.saveFailed || 'Save failed — please retry');
		tagZone.appendChild(msg);
		setTimeout(function () { if (msg.parentNode) msg.remove(); }, 4000);
	}

	/**
	 * Resolve submissionId from OJS 3.4 URL.
	 * Wizard: /submission/{id}/...  |  Editorial: /workflow/(index|access)/{id}/{stageId}
	 */
	function getSubmissionId() {
		var path = window.location.pathname;
		var match = path.match(/\/(?:submission|workflow\/(?:index|access))\/(\d+)\b/);
		if (match) return match[1];
		// Some OJS themes keep the workflow path inside the hash — safety net.
		var hashMatch = window.location.hash.match(/\/workflow\/(?:index|access)\/(\d+)\b/);
		if (hashMatch) return hashMatch[1];
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
		// OJS 3.4 Vue.js FieldControlledVocab renders via Autosuggest:
		//   <div class="pkpFormField pkpAutosuggest">
		//     <input class="pkpAutosuggest__input" id="{group}-keywords-control">
		// No name attribute, no .pkpFormField--keywords class.
		var selectors = [
			// OJS 3.4 Autosuggest rendered input (primary)
			'input.pkpAutosuggest__input',
			'.pkpAutosuggest input[type="text"]',
			// By id pattern (OJS generates "metadata-keywords-control" etc.)
			'input[id*="keyword"]',
			'[id*="keyword"] input',
			// Legacy / fallback selectors
			'input[name="keywords"]',
			'input[name*="keyword"]',
			'.pkpFormField--keywords input[type="text"]',
			'.pkpFormField--keywords input',
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
				// Skip handler for programmatic keyword injection (commitToOjsField)
				if (_injectingKeyword) return;
				handleKeydown(e);
			});
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
				if (subId) {
					saveKeywords(subId);
				} else if (selectedKeywords.length > 0) {
					warnNoSubmissionId();
				}
			});
		});
	}

	// Close dropdown on outside click
	document.addEventListener('click', function (e) {
		if (activeDropdown && !activeDropdown.contains(e.target) && e.target !== activeInput) {
			hideDropdown();
		}
	});

	// Close dropdown on scroll (position: fixed doesn't track scroll)
	window.addEventListener('scroll', function () {
		if (activeDropdown) hideDropdown();
	}, true);

	// Init when DOM is ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	// Re-init on dynamic form loads (OJS SPA-like navigation).
	// Debounce: Vue.js components finish rendering asynchronously
	// after the wrapper node is added to the DOM.
	var initTimer = null;
	var observer = new MutationObserver(function () {
		clearTimeout(initTimer);
		initTimer = setTimeout(init, 150);
	});
	var target = document.body || document.documentElement;
	observer.observe(target, { childList: true, subtree: true });
})();
