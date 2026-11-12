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
				app.insertFeedBlock();
			}
		},

		/**
		 * Show the "Add a Block" tooltip once the inserter button renders.
		 */
		showTooltip: function() {
			app.waitForInserterButton( function( result ) {
				app.initGutenbergTooltip( result );
			} );
		},

		/**
		 * Insert the Reviews Feed block for the feed chosen in the Embed modal's
		 * "Add and Go to Page". Falls back to the "Add a Block" tooltip when the
		 * block cannot be inserted.
		 */
		insertFeedBlock: function() {
			// wp_localize_script turns 0 into "0", so parse it to fall back to the tooltip.
			var feedId = parseInt( sbr_admin_tooltip_wizard.sbr_wizard_feed_id, 10 ) || 0;
			// The modern block, like the other feed plugins. The legacy block only if the modern one is not registered.
			var name = 'smashballoon/reviews-feed';
			var attributes = { feedId: String( feedId ) };
			if ( app.isGutenberg() && wp.blocks && ! wp.blocks.getBlockType( name ) ) {
				name = 'sbr/sbr-feed-block';
				attributes = { shortcodeSettings: 'feed="' + feedId + '"' };
			}
			if ( ! feedId || ! app.isGutenberg() || ! wp.data || ! wp.blocks.getBlockType( name ) ) {
				app.showTooltip();
				return;
			}
			var editor = wp.data.select( 'core/editor' );
			var done = false;
			var unsubscribe;
			var blocksWaitOver = false;
			var waitTimer;
			var tryInsert = function() {
				// __unstableIsEditorReady exists since WP 5.3, older editors are set up before window load.
				if ( done || ( editor.__unstableIsEditorReady && ! editor.__unstableIsEditorReady() ) ) {
					return;
				}
				// Start the 5s cap when the editor first reports ready, so a slow editor still gets the wait.
				if ( ! waitTimer ) {
					waitTimer = setTimeout( function() {
						blocksWaitOver = true;
						tryInsert();
					}, 5000 );
				}
				// On WP 6.1 the editor reports ready before core loads the saved blocks, and that load
				// overwrites an earlier insert. So also wait until the block list holds the saved content,
				// for up to 5s once ready: saved content that parses to no blocks would otherwise hold the insert forever.
				var post = editor.getCurrentPost();
				var saved = post && post.content;
				saved = String( ( saved && saved.raw !== undefined ? saved.raw : saved ) || '' ).trim();
				if ( saved !== '' && ! blocksWaitOver && editor.getEditorBlocks && ! editor.getEditorBlocks().length ) {
					return;
				}
				done = true;
				if ( unsubscribe ) {
					unsubscribe();
				}
				try {
					var block = wp.blocks.createBlock( name, attributes );
					if ( wp.data.dispatch( 'core/editor' ).resetEditorBlocks ) {
						// WP 5.3+: write to the post itself, the block-editor store re-syncs from it after setup.
						var blocks = editor.getEditorBlocks();
						if ( ! app.hasFeedBlock( blocks, feedId ) ) {
							wp.data.dispatch( 'core/editor' ).resetEditorBlocks( blocks.concat( block ) );
						}
					} else {
						// core/block-editor exists since WP 5.2, core/editor holds the blocks on 5.0/5.1.
						var store = wp.data.select( 'core/block-editor' ) ? 'core/block-editor' : 'core/editor';
						if ( ! app.hasFeedBlock( wp.data.select( store ).getBlocks(), feedId ) ) {
							wp.data.dispatch( store ).insertBlocks( block );
						}
					}
				} catch ( e ) {
					app.showTooltip();
				}
				// Drop sbr_wizard so a reload does not insert again.
				try {
					var url = new URL( window.location.href );
					url.searchParams.delete( 'sbr_wizard' );
					window.history.replaceState( window.history.state, '', url.toString() );
				} catch ( e ) {}
			};
			tryInsert();
			if ( ! done ) {
				unsubscribe = wp.data.subscribe( tryInsert );
				// The editor never reported ready: stop waiting and point at the inserter instead.
				setTimeout( function() {
					if ( ! done && ! waitTimer ) {
						done = true;
						unsubscribe();
						app.showTooltip();
					}
				}, 10000 );
			}
		},

		/**
		 * Whether a Reviews Feed block (modern or legacy) for this feed is already on the page.
		 */
		hasFeedBlock: function( blocks, feedId ) {
			return blocks.some( function( b ) {
				// Modern feedId, or legacy `feed="N"` / the bare id once its select changes.
				var id = parseInt( String( b.attributes.feedId || b.attributes.shortcodeSettings || '' ).replace( /feed|=|"/g, '' ), 10 );
				return ( ( b.name === 'smashballoon/reviews-feed' || b.name === 'sbr/sbr-feed-block' ) && id === feedId )
					|| app.hasFeedBlock( b.innerBlocks || [], feedId );
			} );
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
