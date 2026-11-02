/**
 * Zapier settings surface behaviour.
 *
 * Captures the fragments rendered into #sbc-zapier-holder, removes the holder
 * (so its inputs never duplicate IDs), then injects each fragment into the
 * matching React-rendered section's empty input column.
 *
 * Re-entrant by design: the customizer unmounts and remounts a tab's sections
 * on every tab switch, destroying anything we moved into them. So the fragment
 * HTML and the live key state are kept in memory here and re-injected whenever
 * an empty target reappears. A plain move-once approach loses the node on the
 * first remount.
 *
 * Config arrives as window.sbcZapierSettings (see Zapier_Settings_Surface).
 */
(function () {
	'use strict';

	var CFG = window.sbcZapierSettings;
	if (!CFG) {
		return;
	}

	var TEXT = CFG.i18n || {};

	var templates = {};
	var state = {
		plainKey: null,
		key: CFG.key || null,
		enabled: !!CFG.enabled,
		busy: false
	};

	// Our own DOM writes would re-trigger the MutationObserver below, so they
	// are fenced: the observer ignores anything that happens while we are
	// injecting or applying state. Writes are also made only-if-changed so the
	// pass is idempotent and settles immediately.
	var applying = false;
	var observer = null;

	function setText(el, value) {
		if (el && el.textContent !== value) {
			el.textContent = value;
		}
	}

	function setHidden(el, value) {
		if (el && el.hidden !== value) {
			el.hidden = value;
		}
	}

	var holder = document.getElementById('sbc-zapier-holder');
	if (holder) {
		Object.keys(CFG.sectionTypes || {}).forEach(function (type) {
			var frag = holder.querySelector(
				'[data-sbc-zapier-fragment="' + CFG.sectionTypes[type] + '"]'
			);
			if (frag) {
				templates[type] = frag.outerHTML;
			}
		});
		if (holder.parentNode) {
			holder.parentNode.removeChild(holder);
		}
	}

	function post(action, done, extra) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', CFG.nonce);
		Object.keys(extra || {}).forEach(function (name) {
			body.append(name, extra[name]);
		});
		fetch(CFG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(done)
			.catch(function () { done(null); });
	}

	// Reflect current state onto whichever fragment copy is live right now.
	function applyState() {
		var toggle = document.querySelector('[data-sbc-zapier-toggle]');
		if (toggle) {
			if (toggle.checked !== state.enabled) {
				toggle.checked = state.enabled;
			}
		}
		setHidden(document.querySelector('[data-sbc-zapier-panel]'), !state.enabled);

		var root = document.querySelector('[data-sbc-zapier-fragment="connection"]');
		if (!root) {
			return;
		}

		var hasKey = !!(state.plainKey || state.key);

		setHidden(root.querySelector('[data-sbc-zapier-state="revealed"]'), !state.plainKey);
		setHidden(root.querySelector('[data-sbc-zapier-copy="api-key"]'), !state.plainKey);

		// Like the license key field: a saved key is a masked password field
		// with the valid style + check. The plain key shows once, right after
		// generating.
		var keyInput = root.querySelector('#sbc-zapier-api-key');
		if (keyInput) {
			var type = state.plainKey ? 'text' : 'password';
			var shown = state.plainKey || (state.key ? '********************************' : '');
			if (keyInput.type !== type) {
				keyInput.type = type;
			}
			if (keyInput.value !== shown) {
				keyInput.value = shown;
			}
			var wrap = keyInput.closest('.sbc-zapier-input-wrap');
			if (wrap && CFG.validClass && wrap.classList.contains(CFG.validClass) !== hasKey) {
				wrap.classList.toggle(CFG.validClass, hasKey);
			}
		}
		setHidden(root.querySelector('[data-sbc-zapier-valid]'), !hasKey);

		var gen = root.querySelector('[data-sbc-zapier-action="generate"]');
		if (gen) {
			setHidden(gen, hasKey);
			gen.disabled = state.busy;
		}

		var rev = root.querySelector('[data-sbc-zapier-action="revoke"]');
		if (rev) {
			setHidden(rev, !hasKey);
			rev.disabled = state.busy;
		}
	}

	function flashCopied(btn) {
		btn.textContent = btn.getAttribute('data-copied');
		setTimeout(function () {
			btn.textContent = btn.getAttribute('data-label');
		}, 2000);
	}

	// The async clipboard API needs a secure context, which plenty of local and
	// staging installs are not - fall back to a hidden textarea.
	function copy(value, btn) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(value)
				.then(function () { flashCopied(btn); })
				.catch(function () { copyFallback(value, btn); });
			return;
		}
		copyFallback(value, btn);
	}

	function copyFallback(value, btn) {
		var el = document.createElement('textarea');
		el.value = value;
		el.setAttribute('readonly', '');
		el.style.position = 'absolute';
		el.style.left = '-9999px';
		document.body.appendChild(el);
		el.select();
		try {
			document.execCommand('copy');
			flashCopied(btn);
		} catch (e) {
			// Nothing sensible to do - the field is selectable by hand.
		}
		document.body.removeChild(el);
	}

	function onClick(event) {
		var copyBtn = event.target.closest('[data-sbc-zapier-copy]');
		if (copyBtn) {
			event.preventDefault();
			var which = copyBtn.getAttribute('data-sbc-zapier-copy');
			var input = document.getElementById(
				which === 'api-key' ? 'sbc-zapier-api-key' : 'sbc-zapier-site-url'
			);
			if (input) {
				copy(input.value, copyBtn);
			}
			return;
		}

		var actionBtn = event.target.closest('[data-sbc-zapier-action]');
		if (!actionBtn || state.busy) {
			return;
		}
		event.preventDefault();

		var action = actionBtn.getAttribute('data-sbc-zapier-action');

		if (action === 'generate') {
			state.busy = true;
			applyState();
			post(CFG.actions.generate, function (res) {
				state.busy = false;
				if (res && res.success && res.data) {
					state.plainKey = res.data.key;
					state.key = res.data.display || null;
				} else {
					window.alert(TEXT.genericError);
				}
				applyState();
			});
			return;
		}

		if (action === 'revoke') {
			if (!window.confirm(TEXT.revokeConfirm)) {
				return;
			}
			state.busy = true;
			applyState();
			post(CFG.actions.revoke, function (res) {
				state.busy = false;
				if (res && res.success) {
					state.plainKey = null;
					state.key = null;
				} else {
					window.alert(TEXT.genericError);
				}
				applyState();
			});
		}
	}

	// Resolve the element a fragment should be injected into, for either host
	// layout (see Zapier_Settings_Surface):
	//   - Slot hosts (Vue / plain-PHP settings pages) place an empty
	//     `[data-sbc-zapier-slot="<type>"]` where the surface should appear;
	//     the fragment goes straight inside it.
	//   - Customizer "relocate" tabs render placeholder sections instead, so we
	//     fall back to the matching section's empty input column.
	function targetFor(type) {
		var slot = document.querySelector('[data-sbc-zapier-slot="' + type + '"]');
		if (slot) {
			return slot;
		}
		var sec = document.querySelector(
			'.sb-settings-section-ctn[data-type="' + type + '"]'
		);
		if (!sec) {
			return null;
		}
		return sec.querySelector('.sb-settings-section-input') || sec;
	}

	// Inject any missing fragment into its (empty) target. Fenced with
	// `applying` so the writes below never re-enter through the
	// MutationObserver (setting textContent is itself a childList mutation).
	function ensure() {
		if (applying) {
			return;
		}
		applying = true;
		try {
			Object.keys(templates).forEach(function (type) {
				var target = targetFor(type);
				if (!target) {
					return;
				}
				if (target.querySelector('[data-sbc-zapier-fragment]')) {
					return;
				}
				target.insertAdjacentHTML('beforeend', templates[type]);
			});
			applyState();
		} finally {
			// Release only after the observer has drained the records our own
			// writes queued, so they are dropped rather than re-entering.
			if (observer) {
				observer.takeRecords();
			}
			applying = false;
		}
	}

	// The switch flips instantly and saves in the background, like the host's
	// own toggles. Clicks during a save are queued: only the latest state is
	// sent next. On failure it snaps back to the last saved state.
	var saved = null;
	var saving = false;
	function save() {
		saving = true;
		var wanted = state.enabled;
		post(CFG.actions.enable, function (res) {
			saving = false;
			if (res && res.success && res.data) {
				saved = !!res.data.enabled;
				if (state.enabled !== wanted) {
					save();
					return;
				}
				state.enabled = saved;
			} else {
				state.enabled = saved === null ? !wanted : saved;
				window.alert(TEXT.genericError);
			}
			applyState();
		}, { enabled: wanted ? '1' : '0' });
	}

	function onChange(event) {
		var toggle = event.target.closest('[data-sbc-zapier-toggle]');
		if (!toggle) {
			return;
		}
		if (saved === null) {
			saved = !toggle.checked;
		}
		state.enabled = toggle.checked;
		applyState();
		if (!saving) {
			save();
		}
	}

	document.addEventListener('click', onClick);
	document.addEventListener('change', onChange);

	// React remounts the active tab's sections on tab switches, so watch the DOM
	// and re-inject whenever a target (re)appears empty.
	var app = document.getElementById(CFG.mount) || document.body;
	observer = new MutationObserver(ensure);
	observer.observe(app, { childList: true, subtree: true });

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', ensure);
	} else {
		ensure();
	}
})();
