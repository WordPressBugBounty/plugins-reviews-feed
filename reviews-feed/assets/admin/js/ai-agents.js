/**
 * AI Agents card -- WPVibe install / activate action.
 *
 * The card's markup (assembled server-side in SBR_General_Tab::ai_agents_info_html())
 * is injected into the settings React app through the customizer's 'info' field, which
 * only supports raw HTML -- it is not a React-managed component, so there is no client
 * state to flip in place the way Instagram Feed Pro's Vue component does. This script
 * is a small progressive-enhancement layer next to that markup: it reuses the plugin's
 * existing sbr_install_plugin / sbr_activate_plugin AJAX actions (already used by the
 * "About" screen's recommended-plugins cards) and reloads the page on success so the
 * next server render picks up the new WPVibe status.
 *
 * @since 2.12.0
 */
'use strict';

( function ( document, window ) {

	/**
	 * Read the shared customizer bootstrap data (ajaxHandler + nonce) that the
	 * settings React app itself localizes as window.sb_customizer.
	 *
	 * @since 2.12.0
	 *
	 * @return {?{ajaxHandler: string, nonce: string}}
	 */
	function getSettings() {
		var sbCustomizer = window.sb_customizer;
		if ( ! sbCustomizer || ! sbCustomizer.ajaxHandler || ! sbCustomizer.nonce ) {
			return null;
		}
		return sbCustomizer;
	}

	/**
	 * Show the card's inline error message next to a given action button.
	 *
	 * @since 2.12.0
	 *
	 * @param {HTMLElement} button
	 */
	function showError( button ) {
		var card = button.closest( '.sb-settings-section-content' );
		var error = card ? card.querySelector( '.sbr-ai-agents-error' ) : null;
		if ( error ) {
			error.hidden = false;
		}
	}

	/**
	 * Handle a click on the card's install / activate button.
	 *
	 * @since 2.12.0
	 *
	 * @param {MouseEvent} event
	 */
	function onClick( event ) {
		var button = event.target.closest( '[data-sbr-wpvibe-action]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();

		if ( button.disabled ) {
			return;
		}

		var settings = getSettings();
		if ( ! settings ) {
			showError( button );
			return;
		}

		var action = 'install' === button.getAttribute( 'data-sbr-wpvibe-action' )
			? 'sbr_install_plugin'
			: 'sbr_activate_plugin';
		var plugin = button.getAttribute( 'data-sbr-wpvibe-plugin' ) || '';

		var originalLabel = button.textContent;
		button.disabled = true;
		button.textContent = 'sbr_install_plugin' === action
			? ( window.sbrAiAgentsL10n && window.sbrAiAgentsL10n.installing ) || originalLabel
			: ( window.sbrAiAgentsL10n && window.sbrAiAgentsL10n.activating ) || originalLabel;

		var formData = new FormData();
		formData.append( 'action', action );
		formData.append( 'nonce', settings.nonce );
		formData.append( 'plugin', plugin );

		fetch( settings.ajaxHandler, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) {
				if ( ! response || true !== response.success ) {
					button.disabled = false;
					button.textContent = originalLabel;
					showError( button );
					return;
				}
				// No in-place state to flip to (the markup is server-rendered, not
				// React-managed) -- reload so the next render reflects the new
				// WPVibe status.
				window.location.reload();
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = originalLabel;
				showError( button );
			} );
	}

	document.addEventListener( 'click', onClick );

}( document, window ) );
