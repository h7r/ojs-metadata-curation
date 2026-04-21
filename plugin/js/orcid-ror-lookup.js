/**
 * @file js/orcid-ror-lookup.js
 *
 * Copyright (c) 2026 Ne Varietur
 * Distributed under the GNU GPL v3.
 *
 * @brief ORCID and ROR autocomplete widgets for contributor metadata.
 *        Attaches to ORCID and affiliation fields in the submission form.
 *        All results require human validation (C1b).
 *
 *        Config injected via window.nvMetadataCuration by the plugin.
 */
(function () {
	'use strict';

	var config = window.nvMetadataCuration || {};
	var ORCID_URL = config.orcidUrl || '';
	var ROR_URL = config.rorUrl || '';
	var DEBOUNCE_MS = 400;

	if (!ORCID_URL && !ROR_URL) return;

	var debounceTimers = {};
	var activeDropdown = null;
	var activeInput = null;
	var activeIndex = -1;

	function debounce(key, fn, ms) {
		clearTimeout(debounceTimers[key]);
		debounceTimers[key] = setTimeout(fn, ms);
	}

	function postLookup(url, query, callback) {
		var params = new URLSearchParams({ q: query });
		var xhr = new XMLHttpRequest();
		xhr.open('POST', url);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.timeout = 4000;
		xhr.onload = function () {
			if (xhr.status === 200) {
				try { callback(null, JSON.parse(xhr.responseText)); }
				catch (e) { callback(e, null); }
			} else {
				callback(new Error('HTTP ' + xhr.status), null);
			}
		};
		xhr.onerror = function () { callback(new Error('Network error'), null); };
		xhr.ontimeout = function () { callback(new Error('Timeout'), null); };
		xhr.send(params.toString());
	}

	function hideDropdown() {
		if (activeDropdown && activeDropdown.parentNode) {
			activeDropdown.parentNode.removeChild(activeDropdown);
		}
		if (activeInput) {
			activeInput.setAttribute('aria-expanded', 'false');
			activeInput.removeAttribute('aria-activedescendant');
		}
		activeDropdown = null;
		activeInput = null;
		activeIndex = -1;
	}

	function navigateDropdown(input, direction) {
		if (!activeDropdown) return;
		var items = activeDropdown.querySelectorAll('[role="option"]');
		if (items.length === 0) return;

		activeIndex += direction;
		if (activeIndex < 0) activeIndex = items.length - 1;
		if (activeIndex >= items.length) activeIndex = 0;

		items.forEach(function (item) { item.classList.remove('nv-suggest-item--focused'); });
		items[activeIndex].classList.add('nv-suggest-item--focused');
		var itemId = 'nv-orcid-ror-option-' + activeIndex;
		items[activeIndex].setAttribute('id', itemId);
		input.setAttribute('aria-activedescendant', itemId);
	}

	function selectActiveItem() {
		if (!activeDropdown || activeIndex < 0) return false;
		var items = activeDropdown.querySelectorAll('[role="option"]');
		if (items[activeIndex]) {
			items[activeIndex].click();
			return true;
		}
		return false;
	}

	function handleKeydown(e) {
		if (!activeDropdown) return;
		if (e.key === 'ArrowDown') {
			e.preventDefault();
			navigateDropdown(e.target, 1);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			navigateDropdown(e.target, -1);
		} else if (e.key === 'Enter' && activeIndex >= 0) {
			e.preventDefault();
			selectActiveItem();
		} else if (e.key === 'Escape') {
			e.preventDefault();
			hideDropdown();
		}
	}

	function showOrcidDropdown(input, results) {
		hideDropdown();
		if (!results || results.length === 0) return;

		var dropdown = document.createElement('div');
		dropdown.className = 'nv-suggest-dropdown nv-orcid-dropdown';
		dropdown.setAttribute('role', 'listbox');
		dropdown.setAttribute('aria-label', 'ORCID results');

		results.forEach(function (item) {
			var row = document.createElement('div');
			row.className = 'nv-suggest-item';
			row.setAttribute('role', 'option');
			row.setAttribute('tabindex', '-1');

			var name = document.createElement('span');
			name.className = 'nv-suggest-label';
			name.textContent = item.display_name;
			row.appendChild(name);

			var orcid = document.createElement('span');
			orcid.className = 'nv-suggest-broader';
			orcid.textContent = item.orcid;
			row.appendChild(orcid);

			if (item.institutions && item.institutions.length > 0) {
				var inst = document.createElement('span');
				inst.className = 'nv-suggest-translation';
				inst.textContent = item.institutions.join(', ');
				row.appendChild(inst);
			}

			// Validation badge — requires human confirmation
			var badge = document.createElement('span');
			badge.className = 'nv-validation-badge nv-validation-pending';
			badge.textContent = '\u26A0';
			badge.setAttribute('aria-label', 'Requires validation');
			badge.title = 'Requires human validation';
			row.appendChild(badge);

			row.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				input.value = item.orcid_uri;
				input.dataset.nvOrcidValidated = 'false';
				input.dataset.nvOrcidName = item.display_name;
				addValidationChip(input, item.display_name, item.orcid_uri, 'orcid');
				hideDropdown();
			});

			dropdown.appendChild(row);
		});

		var rect = input.getBoundingClientRect();
		dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
		dropdown.style.left = (rect.left + window.scrollX) + 'px';
		dropdown.style.width = Math.max(rect.width, 320) + 'px';

		document.body.appendChild(dropdown);
		activeDropdown = dropdown;
		activeInput = input;
		activeIndex = -1;
		input.setAttribute('aria-expanded', 'true');
	}

	function showRorDropdown(input, results) {
		hideDropdown();
		if (!results || results.length === 0) return;

		var dropdown = document.createElement('div');
		dropdown.className = 'nv-suggest-dropdown nv-ror-dropdown';
		dropdown.setAttribute('role', 'listbox');
		dropdown.setAttribute('aria-label', 'ROR results');

		results.forEach(function (item) {
			var row = document.createElement('div');
			row.className = 'nv-suggest-item';
			row.setAttribute('role', 'option');
			row.setAttribute('tabindex', '-1');

			var name = document.createElement('span');
			name.className = 'nv-suggest-label';
			name.textContent = item.name;
			row.appendChild(name);

			if (item.country) {
				var country = document.createElement('span');
				country.className = 'nv-suggest-broader';
				country.textContent = item.country;
				row.appendChild(country);
			}

			var badge = document.createElement('span');
			badge.className = 'nv-validation-badge nv-validation-pending';
			badge.textContent = '\u26A0';
			badge.setAttribute('aria-label', 'Requires validation');
			badge.title = 'Requires human validation';
			row.appendChild(badge);

			row.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				input.value = item.name;
				input.dataset.nvRorId = item.ror_id;
				input.dataset.nvRorValidated = 'false';
				addValidationChip(input, item.name, item.ror_id, 'ror');
				hideDropdown();
			});

			dropdown.appendChild(row);
		});

		var rect = input.getBoundingClientRect();
		dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
		dropdown.style.left = (rect.left + window.scrollX) + 'px';
		dropdown.style.width = Math.max(rect.width, 320) + 'px';

		document.body.appendChild(dropdown);
		activeDropdown = dropdown;
		activeInput = input;
		activeIndex = -1;
		input.setAttribute('aria-expanded', 'true');
	}

	function addValidationChip(input, label, identifier, type) {
		var container = input.closest('.pkpFormField') || input.parentNode;
		var existing = container.querySelector('.nv-validation-chip[data-type="' + type + '"]');
		if (existing) existing.parentNode.removeChild(existing);

		var chip = document.createElement('span');
		chip.className = 'nv-tag-chip nv-validation-chip nv-validation-pending-chip';
		chip.dataset.type = type;
		chip.innerHTML = '<span class="nv-validation-icon">\u26A0</span> ' +
			'<span class="nv-validation-label">' + escapeHtml(label) + '</span>' +
			' <small>(' + escapeHtml(identifier) + ')</small>';

		var confirmBtn = document.createElement('button');
		confirmBtn.type = 'button';
		confirmBtn.className = 'nv-validation-confirm';
		confirmBtn.textContent = '\u2713';
		confirmBtn.title = 'Confirm';
		confirmBtn.setAttribute('aria-label', 'Confirm ' + type + ' for ' + label);
		confirmBtn.addEventListener('click', function () {
			chip.classList.remove('nv-validation-pending-chip');
			chip.classList.add('nv-validation-confirmed-chip');
			chip.querySelector('.nv-validation-icon').textContent = '\u2713';
			input.dataset['nv' + capitalize(type) + 'Validated'] = 'true';
		});

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'nv-tag-remove';
		removeBtn.textContent = '\u00D7';
		removeBtn.setAttribute('aria-label', 'Remove ' + type);
		removeBtn.addEventListener('click', function () {
			chip.parentNode.removeChild(chip);
			input.value = '';
			delete input.dataset['nv' + capitalize(type) + 'Validated'];
		});

		chip.appendChild(confirmBtn);
		chip.appendChild(removeBtn);
		container.appendChild(chip);
	}

	function capitalize(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(s));
		return d.innerHTML;
	}

	// ORCID field selectors
	var orcidSelectors = [
		'input[name*="orcid"]',
		'input[id*="orcid"]',
		'.pkpFormField--orcid input[type="text"]'
	];

	// Affiliation field selectors
	var affiliationSelectors = [
		'input[name*="affiliation"]',
		'input[id*="affiliation"]',
		'.pkpFormField--affiliation input[type="text"]',
		'textarea[name*="affiliation"]'
	];

	function initOrcidRor() {
		if (ORCID_URL) {
			var orcidInputs = queryAll(orcidSelectors);
			orcidInputs.forEach(function (input) {
				if (input._nvOrcidBound) return;
				input._nvOrcidBound = true;
				input.setAttribute('role', 'combobox');
				input.setAttribute('aria-autocomplete', 'list');
				input.setAttribute('aria-expanded', 'false');
				input.setAttribute('aria-haspopup', 'listbox');
				input.addEventListener('keydown', handleKeydown);
				input.addEventListener('input', function () {
					var val = input.value.trim();
					if (val.length < 3) { hideDropdown(); return; }
					// Skip if already an ORCID URI
					if (val.indexOf('orcid.org') !== -1) return;
					debounce('orcid', function () {
						postLookup(ORCID_URL, val, function (err, data) {
							if (err || !data) { hideDropdown(); return; }
							showOrcidDropdown(input, data.results || []);
						});
					}, DEBOUNCE_MS);
				});
			});
		}

		if (ROR_URL) {
			var affInputs = queryAll(affiliationSelectors);
			affInputs.forEach(function (input) {
				if (input._nvRorBound) return;
				input._nvRorBound = true;
				input.setAttribute('role', 'combobox');
				input.setAttribute('aria-autocomplete', 'list');
				input.setAttribute('aria-expanded', 'false');
				input.setAttribute('aria-haspopup', 'listbox');
				input.addEventListener('keydown', handleKeydown);
				input.addEventListener('input', function () {
					var val = (input.value || input.textContent || '').trim();
					if (val.length < 3) { hideDropdown(); return; }
					debounce('ror', function () {
						postLookup(ROR_URL, val, function (err, data) {
							if (err || !data) { hideDropdown(); return; }
							showRorDropdown(input, data.results || []);
						});
					}, DEBOUNCE_MS);
				});
			});
		}
	}

	function queryAll(selectors) {
		var results = [];
		selectors.forEach(function (sel) {
			var found = document.querySelectorAll(sel);
			for (var i = 0; i < found.length; i++) {
				if (results.indexOf(found[i]) === -1) results.push(found[i]);
			}
		});
		return results;
	}

	document.addEventListener('click', function (e) {
		if (activeDropdown && !activeDropdown.contains(e.target) && e.target !== activeInput) {
			hideDropdown();
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initOrcidRor);
	} else {
		initOrcidRor();
	}

	var observer = new MutationObserver(function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			if (mutations[i].addedNodes.length > 0) { initOrcidRor(); break; }
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
})();
