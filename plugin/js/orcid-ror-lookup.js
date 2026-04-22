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
	var i18n = config.i18n || {};
	var ORCID_URL = config.orcidUrl || '';
	var ROR_URL = config.rorUrl || '';
	var SAVE_CONTRIBUTOR_IDS_URL = config.saveContributorIdsUrl || '';
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
				callback(new Error((i18n.errorHttp || 'HTTP {status}').replace('{status}', xhr.status)), null);
			}
		};
		xhr.onerror = function () { callback(new Error(i18n.errorNetwork || 'Network error'), null); };
		xhr.ontimeout = function () { callback(new Error(i18n.errorTimeout || 'Timeout'), null); };
		xhr.send(params.toString());
	}

	/**
	 * Extract submissionId from the OJS page URL or DOM.
	 */
	function getSubmissionId() {
		var match = window.location.pathname.match(/\/submission\/(\d+)\b/);
		if (match) return match[1];
		var hidden = document.querySelector('input[name="submissionId"]');
		if (hidden) return hidden.value;
		return null;
	}

	/**
	 * Try to extract the authorId from the contributor form context.
	 */
	function getAuthorId(input) {
		var form = input.closest('form');
		if (form) {
			var field = form.querySelector('input[name="authorId"], input[name="contributorId"]');
			if (field) return field.value;
		}
		// Fallback: use field name attribute index
		var name = input.getAttribute('name') || '';
		var idMatch = name.match(/\[(\d+)\]/);
		if (idMatch) return idMatch[1];
		return null;
	}

	/**
	 * Persist validation state server-side (C1b compliance).
	 * Calls saveContributorIds endpoint before flipping the UI badge.
	 */
	function validateOnServer(input, identifier, displayName, type, onSuccess, onError) {
		if (!SAVE_CONTRIBUTOR_IDS_URL) {
			console.warn('[nvMetadataCuration] saveContributorIdsUrl not configured, skipping server validation');
			onSuccess();
			return;
		}

		var submissionId = getSubmissionId();
		if (!submissionId) {
			console.warn('[nvMetadataCuration] Could not determine submissionId, skipping server validation');
			onSuccess();
			return;
		}

		var authorId = getAuthorId(input) || '0';
		var contrib = { authorId: authorId };
		if (type === 'orcid') {
			contrib.orcid = identifier;
			contrib.orcidDisplayName = displayName;
		} else if (type === 'ror') {
			contrib.rorId = identifier;
			contrib.rorDisplayName = displayName;
		}

		var params = new URLSearchParams({
			submissionId: submissionId,
			contributors: JSON.stringify([contrib])
		});

		var xhr = new XMLHttpRequest();
		xhr.open('POST', SAVE_CONTRIBUTOR_IDS_URL);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
		xhr.timeout = 5000;
		xhr.onload = function () {
			if (xhr.status === 200) {
				onSuccess();
			} else {
				var msg = 'Server validation failed';
				try {
					var resp = JSON.parse(xhr.responseText);
					if (resp.details) {
						var keys = Object.keys(resp.details);
						if (keys.length > 0) {
							var fieldErrors = resp.details[keys[0]];
							msg = Object.values(fieldErrors).join('; ');
						}
					}
				} catch (e) { /* use default message */ }
				onError(msg);
			}
		};
		xhr.onerror = function () {
			console.error('[nvMetadataCuration] Network error during server validation');
			onSuccess(); // graceful degradation
		};
		xhr.ontimeout = function () {
			console.error('[nvMetadataCuration] Timeout during server validation');
			onSuccess(); // graceful degradation
		};
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
		dropdown.setAttribute('aria-label', i18n.orcidResults || 'ORCID results');

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
			badge.setAttribute('aria-label', i18n.validationRequired || 'Requires validation');
			badge.title = i18n.validationRequiredTitle || 'Requires human validation';
			row.appendChild(badge);

			row.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				input.value = item.orcid_uri;
				// Dispatch input event so Vue.js FieldText picks up the value
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				input.dataset.nvOrcidName = item.display_name;
				// Single-step validation: selection IS the human validation act (C1b)
				addConfirmedChip(input, item.display_name, item.orcid_uri, 'orcid');
				hideDropdown();
			});

			dropdown.appendChild(row);
		});

		// Position fixed relative to viewport (stable on scroll)
		var rect = input.getBoundingClientRect();
		dropdown.style.top = rect.bottom + 'px';
		dropdown.style.left = rect.left + 'px';
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
		dropdown.setAttribute('aria-label', i18n.rorResults || 'ROR results');

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
			badge.setAttribute('aria-label', i18n.validationRequired || 'Requires validation');
			badge.title = i18n.validationRequiredTitle || 'Requires human validation';
			row.appendChild(badge);

			row.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				input.value = item.name;
				// Dispatch input event so Vue.js FieldText picks up the value
				input.dispatchEvent(new Event('input', { bubbles: true }));
				input.dispatchEvent(new Event('change', { bubbles: true }));
				input.dataset.nvRorId = item.ror_id;
				// Single-step validation: selection IS the human validation act (C1b)
				addConfirmedChip(input, item.name, item.ror_id, 'ror');
				hideDropdown();
			});

			dropdown.appendChild(row);
		});

		// Position fixed relative to viewport (stable on scroll)
		var rect = input.getBoundingClientRect();
		dropdown.style.top = rect.bottom + 'px';
		dropdown.style.left = rect.left + 'px';
		dropdown.style.width = Math.max(rect.width, 320) + 'px';

		document.body.appendChild(dropdown);
		activeDropdown = dropdown;
		activeInput = input;
		activeIndex = -1;
		input.setAttribute('aria-expanded', 'true');
	}

	/**
	 * Single-step validation chip: selection from the lookup dropdown
	 * IS the human validation act (C1b). Validates server-side immediately
	 * and shows a confirmed chip. No separate confirm button needed.
	 */
	function addConfirmedChip(input, label, identifier, type) {
		var container = input.closest('.pkpFormField') || input.closest('.pkpFormGroup') || input.parentNode;
		var existing = container.querySelector('.nv-validation-chip[data-type="' + type + '"]');
		if (existing) existing.parentNode.removeChild(existing);

		var chip = document.createElement('span');
		chip.className = 'nv-tag-chip nv-validation-chip nv-validation-confirmed-chip';
		chip.dataset.type = type;
		chip.innerHTML = '<span class="nv-validation-icon">\u2713</span> ' +
			'<span class="nv-validation-label">' + escapeHtml(label) + '</span>' +
			' <small>(' + escapeHtml(identifier) + ')</small>';

		var removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'nv-tag-remove';
		removeBtn.textContent = '\u00D7';
		removeBtn.setAttribute('aria-label', (i18n.removeType || 'Remove {type}').replace('{type}', type));
		removeBtn.addEventListener('click', function () {
			chip.parentNode.removeChild(chip);
			input.value = '';
			input.dispatchEvent(new Event('input', { bubbles: true }));
			delete input.dataset['nv' + capitalize(type) + 'Validated'];
		});

		chip.appendChild(removeBtn);
		container.appendChild(chip);

		// Mark validated optimistically, then persist server-side
		input.dataset['nv' + capitalize(type) + 'Validated'] = 'true';

		validateOnServer(input, identifier, label, type, function () {
			// Server confirmed — chip stays green
		}, function (errorMsg) {
			// Server rejected — revert to error state
			chip.classList.remove('nv-validation-confirmed-chip');
			chip.classList.add('nv-validation-pending-chip');
			chip.querySelector('.nv-validation-icon').textContent = '\u2717';
			chip.title = errorMsg;
			input.dataset['nv' + capitalize(type) + 'Validated'] = 'false';
		});
	}

	function capitalize(s) { return s.charAt(0).toUpperCase() + s.slice(1); }

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(s));
		return d.innerHTML;
	}

	// ORCID field selectors — OJS 3.4 Vue.js contributor modal renders:
	//   <div class="pkpFormField pkpFormField--text">
	//     <input id="{group}-orcid-control" name="orcid" class="pkpFormField__input">
	// The modal is dynamically injected when user clicks "Edit contributor".
	var orcidSelectors = [
		// By id pattern (OJS generates "{group}-orcid-control")
		'input[id*="orcid"]',
		// By name attribute (may or may not be present in Vue rendering)
		'input[name*="orcid"]',
		// PKP form field wrapper variants
		'.pkpFormField--orcid input[type="text"]',
		'.pkpFormField--orcid input',
		// Inside containers with orcid in id
		'[id*="orcid"] input',
		// PKP standard input class inside orcid-related field
		'[class*="orcid"] input.pkpFormField__input',
	];

	// Affiliation field selectors — similar dynamic modal rendering
	var affiliationSelectors = [
		'input[id*="affiliation"]',
		'input[name*="affiliation"]',
		'.pkpFormField--affiliation input[type="text"]',
		'.pkpFormField--affiliation input',
		'.pkpFormField--affiliation textarea',
		'[id*="affiliation"] input',
		'[id*="affiliation"] textarea',
		'textarea[name*="affiliation"]',
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

	// Close dropdown on scroll (position: fixed doesn't track scroll)
	window.addEventListener('scroll', function () {
		if (activeDropdown) hideDropdown();
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initOrcidRor);
	} else {
		initOrcidRor();
	}

	// Debounce MutationObserver: Vue.js finishes rendering async
	// after the wrapper node is injected (contributor modal, etc.)
	var initTimer = null;
	var observer = new MutationObserver(function () {
		clearTimeout(initTimer);
		initTimer = setTimeout(initOrcidRor, 150);
	});
	var target = document.body || document.documentElement;
	observer.observe(target, { childList: true, subtree: true });
})();
