/**
 * SBC Consent — WP.org-compliance consent re-prompt modal (vanilla).
 *
 * Reads config from `window.sbcConsent` (localized by the package's
 * ConsentAssets enqueue) and operates on plain DOM elements by ID.
 *
 * Public API exposed on window.sbcConsent:
 *   - saveChoice(choice, extra)  Saves accept/skip (admin-ajax, then page fallback).
 *                                Never rejects: Response when saved, null when not.
 *   - activateModal()            Programmatically opens the re-prompt modal.
 *   - showRepromptModal          (config) — truthy = open on init.
 */
(function () {
    'use strict';

    var TRANSITION_FALLBACK_MS = 220;

    var saving = false;
    var isOpen = false;
    var saveSeq = 0;
    var saveChain = Promise.resolve();
    var overlay = null;
    var closeBtn = null;
    var skipBtn = null;
    var acceptBtn = null;

    function getConfig() {
        return window.sbcConsent || {};
    }

    function setButtonsDisabled(disabled) {
        [closeBtn, skipBtn, acceptBtn].forEach(function (btn) {
            if (btn) {
                btn.disabled = disabled;
            }
        });
    }

    function focusOverlay() {
        if (!overlay) {
            return;
        }
        if (typeof overlay.focus === 'function') {
            overlay.focus();
        }
        if (document.activeElement !== overlay) {
            var firstBtn = overlay.querySelector('button:not([disabled])');
            if (firstBtn && typeof firstBtn.focus === 'function') {
                firstBtn.focus();
            }
        }
    }

    function activateModal() {
        if (!overlay) {
            return;
        }
        overlay.hidden = false;
        overlay.classList.remove('sbc-consent-modal-leave-active', 'sbc-consent-modal-leave-to');
        overlay.setAttribute('data-active', 'true');
        isOpen = true;

        overlay.classList.add('sbc-consent-modal-enter', 'sbc-consent-modal-enter-active');
        // Force reflow to commit the enter state before transitioning.
        // eslint-disable-next-line no-unused-expressions
        overlay.offsetHeight;
        overlay.classList.remove('sbc-consent-modal-enter');

        var cleanup = function () {
            overlay.classList.remove('sbc-consent-modal-enter-active');
            overlay.removeEventListener('transitionend', cleanup);
        };
        overlay.addEventListener('transitionend', cleanup);
        setTimeout(cleanup, TRANSITION_FALLBACK_MS);

        focusOverlay();
    }

    function deactivateModal() {
        if (!overlay) {
            return;
        }
        overlay.classList.add('sbc-consent-modal-leave-active', 'sbc-consent-modal-leave-to');

        var done = false;
        var finish = function () {
            if (done) {
                return;
            }
            done = true;
            overlay.removeEventListener('transitionend', finish);
            // Reopened before the close finished: leave it open.
            if (isOpen) {
                return;
            }
            overlay.setAttribute('data-active', 'false');
            overlay.hidden = true;
            overlay.classList.remove(
                'sbc-consent-modal-leave-active',
                'sbc-consent-modal-leave-to',
                'sbc-consent-modal-enter',
                'sbc-consent-modal-enter-active'
            );
        };
        overlay.addEventListener('transitionend', finish);
        setTimeout(finish, TRANSITION_FALLBACK_MS);
    }

    var SAVE_TIMEOUT_MS = 15000;
    // Shorter so a hung ajax save plus a hung fallback still closes in ~23s.
    var FALLBACK_TIMEOUT_MS = 8000;
    var RETRY_THROTTLE_MS = 60000;

    // The timer rejects on its own, so the limit holds even where
    // AbortController is missing; where it exists the request is also aborted.
    function fetchWithTimeout(url, options, ms) {
        var controller = typeof AbortController === 'undefined' ? null : new AbortController();
        var timer;
        if (controller) {
            options.signal = controller.signal;
        }
        var timeout = new Promise(function (resolve, reject) {
            timer = setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
                reject(new Error('Consent save failed: timed out'));
            }, ms);
        });
        return Promise.race([fetch(url, options), timeout]).then(
            function (response) { clearTimeout(timer); return response; },
            function (err) { clearTimeout(timer); throw err; }
        );
    }

    // True only when the body ends with WordPress's JSON reply and it says
    // success: true. Hosts that print PHP warnings put HTML in front of the
    // JSON, so the JSON may follow other output, but nothing may follow it:
    // a login page, an HTML error page or a -1/0 body never counts.
    function savedJson(text) {
        var body = String(text || '').trim();
        var start = body.lastIndexOf('{"success"');
        if (start === -1) {
            return false;
        }
        try {
            var data = JSON.parse(body.slice(start));
            return !!data && data.success === true;
        } catch (e) {
            return false;
        }
    }

    // Only a JSON {success: true} counts as saved. A login redirect, an HTML
    // error page or a -1/0 body from admin-ajax all reject.
    function postSave(url, body, ms) {
        return fetchWithTimeout(url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }, ms).then(function (response) {
            if (!response.ok) {
                throw new Error('Consent save failed: HTTP ' + response.status);
            }
            // Resolve with the Response itself, as saveChoice always has.
            return response.clone().text().then(function (text) {
                if (!savedJson(text)) {
                    throw new Error('Consent save failed: not saved');
                }
                return response;
            });
        });
    }

    function buildBody(choice, extra) {
        var cfg = getConfig();
        var body = new URLSearchParams();
        body.append('action', 'sbc_save_consent_choice');
        body.append('choice', choice);
        body.append('nonce', cfg.nonce || '');
        if (extra && typeof extra === 'object') {
            if (typeof extra.dsc !== 'undefined')   { body.append('dsc',   extra.dsc   ? '1' : '0'); }
            if (typeof extra.notif !== 'undefined') { body.append('notif', extra.notif ? '1' : '0'); }
        }
        return body;
    }

    // Always same-origin: an older copy of this package may have printed an
    // absolute ajaxUrl for another host (www vs non-www), which is sent
    // without cookies and blocked by CORS.
    function ajaxUrl() {
        var url = String(getConfig().ajaxUrl || '');
        try {
            var parsed = new URL(url, window.location.href);
            if (parsed.origin !== window.location.origin) {
                return parsed.pathname + parsed.search;
            }
        } catch (e) { /* keep url as given */ }
        return url;
    }

    function saveAjax(choice, extra) {
        return postSave(ajaxUrl(), buildBody(choice, extra), SAVE_TIMEOUT_MS);
    }

    // admin-ajax first, then the same body through a normal wp-admin page
    // request (handled by ConsentAjax::maybe_handle_page_fallback).
    function saveWithFallback(choice, extra) {
        return saveAjax(choice, extra).catch(function () {
            var body = buildBody(choice, extra);
            body.append('sbc_consent_fallback', '1');
            return postSave(fallbackUrl(), body, FALLBACK_TIMEOUT_MS);
        });
    }

    // Derived from ajaxUrl when an older copy of this package (no fallbackUrl)
    // printed the config last, e.g. two plugins on different versions.
    function fallbackUrl() {
        var cfg = getConfig();
        return cfg.fallbackUrl || ajaxUrl().replace(/admin-ajax\.php$/, 'index.php');
    }

    // Per browser, per host, per site: a Skip that no save path could store.
    // Cleared by a successful save, or on load once the server reports the
    // choice as resolved (dismissed or DSC on, e.g. from Settings).
    function pendingKey() {
        var path = fallbackUrl();
        try {
            path = new URL(path, window.location.href).pathname;
        } catch (e) { /* keep path as given */ }
        return 'sbcConsentSkipPending:' + path.replace(/wp-admin\/index\.php$/, '');
    }

    function getPending() {
        try { return window.localStorage.getItem(pendingKey()); } catch (e) { return null; }
    }

    function setPending() {
        try { window.localStorage.setItem(pendingKey(), String(Date.now())); } catch (e) { /* storage blocked */ }
    }

    function clearPending() {
        try { window.localStorage.removeItem(pendingKey()); } catch (e) { /* storage blocked */ }
    }

    function logError(err) {
        if (window.console && console.error) {
            console.error('[sbc-consent] saveChoice failed', err);
        }
    }

    // Never leaves the overlay up, even if the animation code throws.
    function closeModal() {
        isOpen = false;
        try {
            deactivateModal();
        } catch (e) {
            overlay.setAttribute('data-active', 'false');
            overlay.hidden = true;
        }
    }

    function savePromise(choice, extra) {
        try {
            return saveWithFallback(choice, extra);
        } catch (e) {
            return Promise.reject(e);
        }
    }

    /**
     * Public save for debug tabs and onboarding checkboxes, which ignore the
     * promise. Uses the same fallback as the modal and never rejects.
     *
     * @param {string} choice  'accept' or 'skip'
     * @param {object} [extra] Optional {dsc, notif} per-flag override.
     * @return {Promise} Resolves with the Response when saved, null when not.
     */
    function saveChoice(choice, extra) {
        return savePromise(choice, extra).then(null, function (err) {
            logError(err);
            return null;
        });
    }

    // One save at a time, and the last user action wins: a save queued
    // behind one in flight is dropped when a newer one is queued after it.
    // Resolves with {latest} so callers only act on the newest choice.
    function queueSave(choice, extra) {
        var mine = ++saveSeq;
        var run = saveChain.then(function () {
            return mine === saveSeq ? savePromise(choice, extra) : null;
        });
        saveChain = run.then(function () {}, function () {});
        return run.then(function (response) {
            return { latest: mine === saveSeq, saved: response !== null };
        });
    }

    // Skip, X and Esc close at once and never wait on the network. The Skip
    // is remembered in this browser until a save path stores it, so a failed
    // or interrupted save does not bring the popup back on the next page.
    function skipChoice() {
        if (!isModalActive()) {
            return;
        }
        setPending();
        closeModal();
        queueSave('skip').then(function (result) {
            if (result.latest && result.saved) {
                clearPending();
            }
        }, logError);
    }

    // A failed Accept is never remembered locally: consent must be stored on
    // the server. The popup stays open and every button works again.
    function acceptChoice() {
        if (saving) {
            return;
        }
        saving = true;
        if (acceptBtn) {
            acceptBtn.disabled = true;
        }
        queueSave('accept').then(function (result) {
            if (result.latest && result.saved) {
                clearPending();
                closeModal();
            }
        }, logError).then(function () {
            saving = false;
            setButtonsDisabled(false);
        });
    }

    // False as soon as a close starts, so a double click or Skip + Esc
    // during the leave animation does not send a second save.
    function isModalActive() {
        return !!overlay && isOpen;
    }

    function onKeydown(event) {
        if (event.key === 'Escape' && isModalActive()) {
            skipChoice();
        }
    }

    function init() {
        overlay   = document.getElementById('sbc-consent-reprompt-overlay');
        closeBtn  = document.getElementById('sbc-consent-reprompt-close');
        skipBtn   = document.getElementById('sbc-consent-reprompt-skip');
        acceptBtn = document.getElementById('sbc-consent-reprompt-accept');

        if (closeBtn)   { closeBtn.addEventListener('click', skipChoice); }
        if (skipBtn)    { skipBtn.addEventListener('click', skipChoice); }
        if (acceptBtn)  { acceptBtn.addEventListener('click', acceptChoice); }
        document.addEventListener('keydown', onKeydown);

        // Truthy check (not strict ===), defensive against wp_localize_script type coercion.
        if (!getConfig().showRepromptModal) {
            if (getConfig().consentResolved) {
                clearPending();
            }
            return;
        }

        var pending = getPending();
        if (pending === null) {
            activateModal();
            return;
        }
        // Skip already chosen in this browser but not stored yet: keep the
        // popup closed and retry in the background, at most once a minute.
        if (Date.now() - (parseInt(pending, 10) || 0) >= RETRY_THROTTLE_MS) {
            setPending();
            // dsc only: keeps a notifications value set since (e.g. in
            // Settings); with none set it stores the same as a normal Skip.
            queueSave('skip', { dsc: false }).then(function (result) {
                if (result.saved) {
                    clearPending();
                }
            }, function () {});
        }
    }

    // Expose for builder code / debug tab to call directly.
    window.sbcConsent = window.sbcConsent || {};
    window.sbcConsent.saveChoice    = saveChoice;
    window.sbcConsent.activateModal = activateModal;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
