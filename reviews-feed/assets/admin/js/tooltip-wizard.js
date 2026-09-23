/**
 *Reviews Feed Tooltip Manager
 *
 * @since 4.0
 */
'use strict';

var SBRTooltipWizard = window.SBRTooltipWizard || ( function( document, window, $ ) {

	/**
	 * Selector for the Gutenberg "Toggle block inserter" button.
	 *
	 * Supports both:
	 *   - WP 6.9+       → `.editor-document-tools__inserter-toggle`
	 *   - WP 6.8-/older → `.edit-post-header-toolbar__inserter-toggle`
	 *
	 * Hoisted to module scope so `waitForInserterButton` and
	 * `initGutenbergTooltip` share a single source of truth
	 * (SMASH-1178 review feedback — Ahmed/Gemini suggestion).
	 *
	 * @type {string}
	 */
	var INSERTER_SELECTOR = '.editor-document-tools__inserter-toggle, .edit-post-header-toolbar__inserter-toggle';

	/**
	 * Locate the inserter button across WP 6.9 / WP 7.0 document contexts.
	 *
	 * WP 7.0 iframes the editor canvas (`iframe[name="editor-canvas"]` and
	 * variants). In some configurations the inserter button is rendered
	 * inside that iframe, which makes a plain `document.querySelector()`
	 * in the parent window miss it (SMASH-1178 critical review feedback
	 * from PR #428 — `document.querySelector` against parent DOM only).
	 *
	 * Strategy:
	 *   1. Search the parent document first — covers WP 6.9 + WP 7.0
	 *      configurations that keep the toolbar in the parent chrome.
	 *   2. If not found, walk the visible Gutenberg iframes and try each
	 *      one. Same-origin only (cross-origin iframes throw on
	 *      `.contentDocument` access; we swallow that defensively).
	 *
	 * Returns the resolved `{ button, doc }` pair so the caller can both
	 * attach DOM listeners and insert sibling nodes in the correct owning
	 * document.
	 *
	 * @returns {{ button: Element|null, doc: Document }}
	 */
	function findInserterButton() {
		var btn = document.querySelector( INSERTER_SELECTOR );
		if ( btn ) {
			return { button: btn, doc: document };
		}

		// Gutenberg iframe candidates across versions:
		//   `iframe[name="editor-canvas"]`                 — WP 6.9+ canonical
		//   `iframe.editor-canvas__iframe`                 — alt class
		//   `iframe.edit-post-visual-editor__content-area` — older variant
		//   `iframe.block-editor-iframe__iframe`           — block-editor variant
		var iframes = document.querySelectorAll(
			'iframe[name="editor-canvas"], iframe.editor-canvas__iframe, iframe.edit-post-visual-editor__content-area, iframe.block-editor-iframe__iframe'
		);

		for ( var i = 0; i < iframes.length; i++ ) {
			try {
				var iframeDoc = iframes[ i ].contentDocument;
				if ( ! iframeDoc ) {
					continue;
				}
				var iframeBtn = iframeDoc.querySelector( INSERTER_SELECTOR );
				if ( iframeBtn ) {
					return { button: iframeBtn, doc: iframeDoc };
				}
			} catch ( e ) {
				// Cross-origin iframe — `.contentDocument` access throws.
				// Skip; no way to reach this iframe's DOM from here.
			}
		}

		return { button: null, doc: document };
	}

	/**
	 * Public functions and properties.
	 *
	 * @since 4.0
	 *
	 * @type {object}
	 */
	var app = {

		/**
		 * Start the engine.
		 *
		 * @since 4.0
		 */
		init: function() {

			//$( app.ready );
			$( window ).on( 'load', function() {
				if ( $.isFunction( $.ready.then ) ) {
					$.ready.then( app.load );
				} else {
					app.load();
				}
			} );
		},


		/**
		 * Window load.
		 *
		 * @since 4.0
		 */
		load: function() {
			if ( sbr_admin_tooltip_wizard.sbr_wizard_gutenberg ) {
				app.waitForInserterButton( function( result ) {
					app.initGutenbergTooltip( result );
				} );
			}
		},

		/**
		 * Poll for the inserter button. Gutenberg renders asynchronously
		 * after our `window.load` handler fires, so a single one-shot
		 * `querySelector` would lose the race on slower machines / WP 7.0
		 * iframe boots. Up to 20 retries × 500ms = 10s total wait.
		 *
		 * Calls back with the `{ button, doc }` pair from
		 * `findInserterButton()`, so the consumer can attach DOM listeners
		 * and insert sibling nodes in the correct owning document
		 * (iframe.contentDocument on WP 7.0, parent document on WP 6.9).
		 *
		 * @param {function({button: Element, doc: Document}): void} callback
		 * @param {number} [attempts] Internal retry counter.
		 */
		waitForInserterButton: function( callback, attempts ) {
			attempts = attempts || 0;
			var maxAttempts = 20;
			var result = findInserterButton();

			if ( result.button ) {
				callback( result );
				return;
			}

			if ( attempts < maxAttempts ) {
				setTimeout( function() {
					app.waitForInserterButton( callback, attempts + 1 );
				}, 500 );
			} else {
				// One-line non-noisy log — surfaces in dev tools when a
				// real boot-order regression hits; harmless in production.
				console.error( 'SBRTooltipWizard: Inserter button not found after ' + maxAttempts + ' attempts.' );
			}
		},


		/**
		 * Initialize the tooltipster wizard anchored to the inserter button.
		 *
		 * @param {{button: Element, doc: Document}} result
		 *   Resolved inserter button + its owning document.
		 *   When `doc` is the parent document, jQuery operates as usual.
		 *   When `doc` is an iframe document (WP 7.0), the dot + click
		 *   handlers attach inside the iframe DOM so the tooltip renders
		 *   in the right context.
		 */
		initGutenbergTooltip: function( result ) {
			if ( typeof $.fn.tooltipster === 'undefined' || ! result || ! result.button ) {
				return;
			}

			var $inserterButton = $( result.button );

			if ( ! $inserterButton.length ) {
				return;
			}

			var $dot = $( '<span class="wpforms-admin-form-embed-wizard-dot">&nbsp;</span>' );

			var tooltipsterArgs = {
				content          : $( '#sbr-gutenberg-tooltip-content' ),
				trigger          : 'custom',
				interactive      : true,
				animationDuration: 0,
				delay            : 0,
				theme            : [ 'tooltipster-default', 'sbr-tooltip-wizard' ],
				side             : 'bottom',
				distance         : 3,
				functionReady    : function( instance, helper ) {
					instance._$tooltip.on( 'click', '.sbr-tlp-wizard-close', function() {
						instance.close();
					} );

					instance.reposition();
				},
			};

			$inserterButton.on( 'click', function() {
				$( '.sbr-tooltip-wizard.tooltipster-sidetip' ).hide();
			} );

			$dot.insertAfter( $inserterButton ).tooltipster( tooltipsterArgs ).tooltipster( 'open' );
		},

		/**
		 * Check if we're in Gutenberg editor.
		 *
		 * @since 4.0
		 *
		 * @returns {boolean} Is Gutenberg or not.
		 */
		isGutenberg: function() {

			return typeof wp !== 'undefined' && Object.prototype.hasOwnProperty.call( wp, 'blocks' );
		},
	}

	return app;
}( document, window, jQuery ) );

SBRTooltipWizard.init();
