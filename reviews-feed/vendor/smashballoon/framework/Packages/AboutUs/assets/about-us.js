/**
 * About Us Page - Vanilla JS for plugin install/activate actions.
 *
 * @package AboutUs
 */
(function () {
	'use strict';

	var config = window.sb_about_us || {};
	var ajaxUrl = config.ajax_url || '';
	var nonce = config.nonce || '';
	var i18n = config.i18n || {};

	/**
	 * Initialize event listeners on all action buttons.
	 */
	function init() {
		var buttons = document.querySelectorAll('.sbc-about-us-action-btn');

		buttons.forEach(function (btn) {
			btn.addEventListener('click', handleAction);
		});
	}

	/**
	 * Handle button click action.
	 *
	 * @param {Event} e Click event.
	 */
	function handleAction(e) {
		e.preventDefault();

		var btn = e.currentTarget;
		var action = btn.getAttribute('data-action');
		var plugin = btn.getAttribute('data-plugin');

		if (!action || !plugin) {
			return;
		}

		var ajaxAction = '';
		var loadingText = '';

		switch (action) {
			case 'install':
				ajaxAction = 'sb_about_install_plugin';
				loadingText = i18n.installing || 'Installing...';
				break;
			case 'activate':
				ajaxAction = 'sb_about_activate_plugin';
				loadingText = i18n.activating || 'Activating...';
				break;
			default:
				return;
		}

		setButtonState(btn, 'loading', loadingText);

		var formData = new FormData();
		formData.append('action', ajaxAction);
		formData.append('nonce', nonce);
		formData.append('plugin', plugin);

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data.success) {
					if (action === 'install' && !(data.data && data.data.is_activated)) {
						// Installed but activation failed: offer Activate so the admin can retry.
						setButtonState(btn, 'idle', i18n.activate || 'Activate');
						btn.setAttribute('data-action', 'activate');
						btn.setAttribute('data-plugin', data.data && data.data.basename ? data.data.basename : '');
					} else if (action === 'install') {
						setButtonState(btn, 'success', i18n.activated || 'Activated');
						btn.disabled = true;
						btn.classList.remove('sbc-about-us-action-btn');
					} else if (action === 'activate') {
						setButtonState(btn, 'success', i18n.active || 'Active');
						btn.disabled = true;
						btn.classList.remove('sbc-about-us-action-btn');
					}
				} else {
					var errorMsg =
						(typeof data.data === 'string' ? data.data : '') ||
						i18n.failed ||
						'Failed';
					setButtonState(btn, 'error', errorMsg);

					// Reset to original state after 3 seconds.
					setTimeout(function () {
						resetButton(btn, action);
					}, 3000);
				}
			})
			.catch(function () {
				setButtonState(btn, 'error', i18n.failed || 'Failed');
				setTimeout(function () {
					resetButton(btn, action);
				}, 3000);
			});
	}

	/**
	 * Set button visual state.
	 *
	 * @param {HTMLElement} btn   Button element.
	 * @param {string}      state State: loading, success, error, idle.
	 * @param {string}      text  Button text.
	 */
	function setButtonState(btn, state, text) {
		btn.classList.remove(
			'sbc-about-us-btn-loading',
			'sbc-about-us-btn-success',
			'sbc-about-us-btn-error'
		);

		switch (state) {
			case 'loading':
				btn.classList.add('sbc-about-us-btn-loading');
				btn.disabled = true;
				btn.innerHTML =
					'<span class="sbc-about-us-spinner"></span>' + escapeHtml(text);
				break;
			case 'success':
				btn.classList.add('sbc-about-us-btn-success');
				btn.textContent = text;
				break;
			case 'error':
				btn.classList.add('sbc-about-us-btn-error');
				btn.disabled = false;
				btn.textContent = text;
				break;
			case 'idle':
			default:
				btn.disabled = false;
				btn.textContent = text;
				break;
		}
	}

	/**
	 * Reset button to its original state.
	 *
	 * @param {HTMLElement} btn    Button element.
	 * @param {string}      action Original action.
	 */
	function resetButton(btn, action) {
		btn.classList.remove('sbc-about-us-btn-error');

		if (action === 'install') {
			setButtonState(btn, 'idle', i18n.install || 'Install');
		} else if (action === 'activate') {
			setButtonState(btn, 'idle', i18n.activate || 'Activate');
		}
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str String to escape.
	 * @return {string}
	 */
	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Initialize when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
