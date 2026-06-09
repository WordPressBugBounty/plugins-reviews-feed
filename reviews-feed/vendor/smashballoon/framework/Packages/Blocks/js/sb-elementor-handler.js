'use strict';

/**
 * Smash Balloon Elementor Handler
 *
 * Handles click/drag behaviour for faux SB widgets in the Elementor editor:
 * - Disables drag on inactive (uninstalled) SB widgets
 * - Shows install popup modal on click
 * - Calls `am_recommended_block_install` AJAX action to install plugins
 */

let SbElementorHandler = window.SbElementorHandler || ( function( _document, window, $ ) {

	const smashBalloonPlugins = sbHandler.smashPlugins;

	let app = {

		_observer: null,
		_debounceTimer: null,

		init: function() {
			app.events();
		},

		events: function() {
			app.waitForPanel( function() {
				app.disableInactiveSmashWidgets();
				app.observePanelChanges();
			});
		},

		/**
		 * Poll until window.parent.elementor.panel exists, then invoke callback.
		 */
		waitForPanel: function( callback ) {
			var check = function() {
				if ( window.parent.elementor && window.parent.elementor.panel && window.parent.elementor.panel.$el ) {
					callback();
				} else {
					setTimeout( check, 200 );
				}
			};
			check();
		},

		/**
		 * Attach a MutationObserver on the panel element to detect re-renders.
		 * When Elementor destroys/re-creates the panel DOM (e.g. navigating
		 * between widget list and widget settings), re-apply disable logic.
		 */
		observePanelChanges: function() {
			if ( app._observer ) {
				app._observer.disconnect();
			}

			var panelEl = window.parent.elementor.panel.$el[0];
			if ( ! panelEl ) {
				return;
			}

			app._observer = new window.parent.MutationObserver( function() {
				clearTimeout( app._debounceTimer );
				app._debounceTimer = setTimeout( function() {
					app.disableInactiveSmashWidgets();
				}, 100 );
			});

			app._observer.observe( panelEl, {
				childList: true,
				subtree: true
			});
		},

		disableInactiveSmashWidgets: function() {
			var panel$ = window.parent.elementor.panel.$el;
			if ( ! panel$ || ! panel$.length ) {
				return;
			}

			// Scan the entire panel rather than scoping to
			// `#elementor-panel-category-smashballoon`. Elementor renders the
			// search-results widget list (and lazy-loaded scrolled tiles)
			// outside that category container, so a category-scoped query
			// misses faux widgets shown there.
			for ( const pluginName in smashBalloonPlugins ) {
				panel$
					.find( '.sb-elem-inactive.sb-elem-' + pluginName )
					.each( function() {
						var pluginWrapper = $( this ).closest( '.elementor-element-wrapper' );
						if ( ! pluginWrapper.length ) {
							return;
						}

						// Skip if already processed
						if ( pluginWrapper.find( '.sb-click-overlay' ).length ) {
							return;
						}

						var pluginWidget = pluginWrapper.find( '.elementor-element' );

						// Block all pointer events on the actual draggable element
						pluginWidget.css( 'pointer-events', 'none' );

						// Add a click-capturing overlay
						pluginWrapper.css( 'position', 'relative' );
						var $overlay = $( '<div class="sb-click-overlay"></div>' ).css({
							position: 'absolute',
							top: 0,
							left: 0,
							width: '100%',
							height: '100%',
							zIndex: 10,
							cursor: 'pointer'
						});

						// Use closure to capture pluginName
						(function( name ) {
							// Suppress mousedown so Elementor's native Pro
							// upsell dialog (bound on mousedown for Pro
							// widgets) does not fire on top of our SB
							// upsell modal.
							$overlay.on( 'mousedown', function( e ) {
								e.stopPropagation();
								e.preventDefault();
							});
							$overlay.on( 'click', function( e ) {
								e.stopPropagation();
								e.preventDefault();
								app.createUpsellPopup( name );
							});
						})( pluginName );

						pluginWrapper.append( $overlay );
					});
			}
		},

		createUpsellPopup: function( pluginName ) {
			let plugin = smashBalloonPlugins[ pluginName ],
				spinnerIcon = '<svg x="0px" y="0px" width="20px" height="20px" viewBox="0 0 50 50" style="enable-background:new 0 0 50 50;" xml:space="preserve"><path fill="#fff" d="M43.935,25.145c0-10.318-8.364-18.683-18.683-18.683c-10.318,0-18.683,8.365-18.683,18.683h4.068c0-8.071,6.543-14.615,14.615-14.615c8.072,0,14.615,6.543,14.615,14.615H43.935z"><animateTransform attributeType="xml" attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.6s" repeatCount="indefinite"/></path></svg>',
				upsellPopupOutput =
				'<div class="sb-source-ctn sb-fs-boss sb-center-boss">\
					<div class="sb-source-popup sb-popup-inside sb-install-plugin-modal">\
						<div class="sb-popup-cls">\
							<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">\
								<path d="M14 1.41L12.59 0L7 5.59L1.41 0L0 1.41L5.59 7L0 12.59L1.41 14L7 8.41L12.59 14L14 12.59L8.41 7L14 1.41Z" fill="#141B38"/>\
							</svg>\
						</div>\
						<div class="sb-install-plugin-body sb-fs">\
							<div class="sb-install-plugin-header">\
								<div class="sb-plugin-image">' + plugin["svgIcon"] + '\
								<svg class="sb-plugin-cta-logo" width="26" height="33" viewBox="0 0 26 33" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M25.5608 15.2708C25.5608 6.86286 20.0495 0.046814 13.2486 0.046814C6.44763 0.046814 0.933838 6.86286 0.933838 15.2708C0.933838 23.3312 5.98416 29.9079 12.3795 30.4596L11.6995 32.6132L15.9639 32.2505L14.4677 30.4218C20.6943 29.6661 25.5608 23.1725 25.5608 15.2708Z" fill="#FE544F"/><path fill-rule="evenodd" clip-rule="evenodd" d="M16.1843 5.39911L16.7768 11.5131L22.9165 11.6894L18.4752 15.8189L21.983 20.8926L16.0735 19.7817L14.282 25.6913L11.5618 20.3993L6.0693 22.916L8.18218 17.2428L2.82544 14.5409L8.55968 12.6968L6.97737 7.04787L12.4024 10.1407L16.1843 5.39911Z" fill="white"/></svg>\
								</div>\
								<div class="sb-plugin-name">\
									<strong>Requires</strong>\
									<h3>\
										' + plugin["displayName"] + '\
										<span>Free</span>\
									</h3>\
								</div>\
							</div>\
							<div class="sb-install-plugin-content">\
								<p>' + plugin["description"] + '</p>\
								<button class="sb-install-plugin-btn sb-btn-orange sb-plugin-btn" data-plugin="' + plugin['download_plugin'] + '">\
									<span class="sb-install-plugin-spinner" style="display:none">' + spinnerIcon + '</span>\
									' + (plugin["pluginInstalled"] ? 'Activate' : 'Install') + '\
								</button>\
								<button class="sb-install-refresh-btn sb-btn-blue sb-plugin-btn" style="display:none">\
									Refresh The Page\
								</button>\
							</div>\
						</div>\
					</div>\
				</div>';

			if ( $( window.parent.document.body ).find( '.sb-center-boss' ).length === 0 ) {
				$( window.parent.document.body ).append( upsellPopupOutput );

				$( window.parent.document.body ).find( '.sb-install-plugin-btn' ).on( 'click', function() {
					let downloadPlugin = $( this ).attr( 'data-plugin' );
					$( this ).find( '.sb-install-plugin-spinner' ).show();
					app.installPlugin( downloadPlugin, pluginName );
				});

				$( window.parent.document.body ).find( '.sb-install-refresh-btn' ).on( 'click', function() {
					window.parent.location.reload();
				});

				$( window.parent.document.body ).find( '.sb-popup-cls' ).on( 'click', function() {
					app.closeUpsellPopup();
				});

				$( window.parent.document.body ).find( '.sb-center-boss' ).on( 'click', function( e ) {
					if ( e.target === this ) {
						app.closeUpsellPopup();
					}
				});
			}
		},

		closeUpsellPopup: function() {
			$( window.parent.document.body ).find( '.sb-center-boss' ).remove();
		},

		installPlugin: function( downloadPlugin, pluginName ) {
			let data = new FormData();
			data.append( 'action', 'am_recommended_block_install' );
			data.append( 'nonce', sbHandler.nonce );
			data.append( 'plugin', downloadPlugin );

			let $btn = $( window.parent.document.body ).find( '.sb-install-plugin-btn' );

			fetch( sbHandler.ajax_handler, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			})
			.then( function( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Server error' );
				}
				return response.json();
			} )
			.then( function( data ) {
				if ( data.success === true ) {
					smashBalloonPlugins[ pluginName ].pluginInstalled = true;
					$btn.hide();
					$( window.parent.document.body ).find( '.sb-install-refresh-btn' ).show();
				} else {
					$btn.find( '.sb-install-plugin-spinner' ).hide();
					$btn.text( 'Error. Please try again.' );
				}
			})
			.catch( function( err ) {
				console.error( '[SB] Plugin install error:', err );
				$btn.find( '.sb-install-plugin-spinner' ).hide();
				$btn.text( 'Error. Please try again.' );
			});
		}

	};

	return app;

}( document, window, jQuery ) );

SbElementorHandler.init();
