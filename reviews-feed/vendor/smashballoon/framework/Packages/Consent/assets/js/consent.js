/**
 * SBC Consent — WP.org-compliance consent re-prompt modal (vanilla).
 *
 * Reads config from `window.sbcConsent` (localized by the package's
 * ConsentAssets enqueue) and operates on plain DOM elements by ID.
 *
 * Public API exposed on window.sbcConsent:
 *   - saveChoice(choice, extra)  Sends accept/skip choice to the AJAX endpoint.
 *   - activateModal()            Programmatically opens the re-prompt modal.
 *   - showRepromptModal          (config) — truthy = open on init.
 */
(function () {
    'use strict';

    var TRANSITION_FALLBACK_MS = 220;

    var saving = false;
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
        overlay.setAttribute('data-active', 'true');

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
            overlay.setAttribute('data-active', 'false');
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

    /**
     * Send the user's choice to the shared AJAX endpoint.
     *
     * @param {string} choice  'accept' or 'skip'
     * @param {object} [extra] Reserved for future per-flag granularity
     *                         (debug-tab two-checkbox UI). Currently
     *                         ignored by the server which infers both
     *                         flags from `choice`.
     */
    function saveChoice(choice, extra) {
        var cfg = getConfig();
        var body = new URLSearchParams();
        body.append('action', 'sbc_save_consent_choice');
        body.append('choice', choice);
        body.append('nonce', cfg.nonce || '');
        if (extra && typeof extra === 'object') {
            if (typeof extra.dsc !== 'undefined')   { body.append('dsc',   extra.dsc   ? '1' : '0'); }
            if (typeof extra.notif !== 'undefined') { body.append('notif', extra.notif ? '1' : '0'); }
        }

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Consent save failed: HTTP ' + response.status);
            }
            return response;
        });
    }

    function handleChoice(choice) {
        if (saving) {
            return;
        }
        saving = true;
        setButtonsDisabled(true);
        saveChoice(choice).then(
            function () {
                saving = false;
                deactivateModal();
            },
            function (err) {
                saving = false;
                setButtonsDisabled(false);
                if (window.console && console.error) {
                    console.error('[sbc-consent] saveChoice failed', err);
                }
            }
        );
    }

    function acceptChoice() { handleChoice('accept'); }
    function skipChoice()   { handleChoice('skip'); }

    function isModalActive() {
        return overlay && overlay.getAttribute('data-active') === 'true';
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

        // Truthy check (not strict ===) — defensive against wp_localize_script type coercion.
        if (getConfig().showRepromptModal) {
            activateModal();
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
