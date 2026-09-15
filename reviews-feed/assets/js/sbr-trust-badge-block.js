/**
 * Reviews Feed — Trust Badge block editor registration (SBR-163).
 *
 * A SAVED badge placed by id, and the only badge block there is: it has exactly one
 * setting, because everything else was decided in the customizer. An earlier inline
 * block took a shape plus a dozen styling attributes; it was removed, because two
 * badge blocks in the inserter is a choice nobody should have to make. The inline
 * `[reviews-badge]` shortcode and the widget still take those attributes.
 *
 * Buildless ES5 against the wp.* globals, so the plugin still needs no JS build step.
 *
 * Five states, from the design's gutenberg-block-states.html:
 *
 *   empty    no badges exist yet        -> offer to create one
 *   pick     badges exist, none chosen  -> offer to choose
 *   placed   a badge is chosen          -> render it, with our toolbar buttons
 *   loading  preview in flight          -> keep the last render, overlay a spinner
 *   deleted  the chosen badge is gone   -> say so, offer to choose another
 *
 * The deleted state is the one worth keeping: a badge can be removed from the
 * list screen while a page still references it, and silently rendering nothing
 * would leave an author wondering why their page changed.
 */
( function ( blocks, element, blockEditor, components, serverSideRender, i18n, data ) {
	'use strict';

	// Every global the body actually dereferences, not three of them. `components`
	// is read seven times immediately below (PanelBody, SelectControl, …) and
	// `i18n` twice, so a missing one threw a TypeError that took the whole editor
	// bundle down instead of this block degrading. `data` is guarded at its own
	// use sites already — placeWizardBadge() and the preview both test it — since
	// the block is still useful without the wizard hand-off.
	if ( ! blocks || ! element || ! blockEditor || ! components || ! i18n ) {
		return;
	}

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n && i18n.__ ? i18n.__ : function ( s ) { return s; };

	var InspectorControls = blockEditor.InspectorControls;
	var BlockControls = blockEditor.BlockControls;
	var useBlockProps = blockEditor.useBlockProps;

	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var Button = components.Button;
	var Placeholder = components.Placeholder;
	var Notice = components.Notice;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var ServerSideRender = serverSideRender;

	var CFG = window.sbrTrustBadgeBlock || {};
	var BADGES = CFG.badges || [];
	var LIST_URL = CFG.listURL || '';
	var EDIT_URL = CFG.editURL || '';

	/**
	 * Build an SVG element from path data.
	 *
	 * Every `d` below is copied byte-for-byte out of the design prototype rather
	 * than approximated from a dashicon: at 24px a rough substitute reads as a
	 * different control, which is how the accent Reset button ended up wrong.
	 *
	 * @param {string} viewBox
	 * @param {Array}  paths
	 * @param {Object} extra
	 * @return {Object} React element.
	 */
	function svg( viewBox, paths, extra ) {
		var props = { viewBox: viewBox, 'aria-hidden': 'true', focusable: 'false' };

		Object.keys( extra || {} ).forEach( function ( k ) { props[ k ] = extra[ k ]; } );

		return el( 'svg', props, paths.map( function ( d, i ) {
			return el( 'path', { d: d, key: i } );
		} ) );
	}

	/** The plugin mark, so the block carries the product's identity. */
	var ICON = svg(
		'0 0 42 42',
		[ 'M17.1348 0.840489C19.3001 -0.320849 21.764 -0.248101 23.9298 0.869786C25.1891 1.51979 26.7674 3.00536 27.1925 4.37955C27.3342 4.31028 27.472 4.2451 27.6261 4.19889C32.8975 2.61784 37.9166 6.89741 37.045 12.3659C36.9718 12.8251 36.8562 13.3799 36.6378 13.7926C38.2131 14.5003 39.4911 15.737 40.2511 17.2878C42.1091 21.1055 40.4322 25.3565 36.669 27.1725C36.7368 27.3124 36.7924 27.4579 36.836 27.6071C38.032 31.5715 35.9076 35.6928 31.9268 36.8473C30.6053 37.2306 28.3891 37.2551 27.1612 36.5934C26.4498 38.953 23.3581 40.8735 20.9688 40.9753C20.1138 41.1204 18.79 40.8073 17.9942 40.5036C16.1742 39.8091 14.6331 38.4206 13.8399 36.6286C12.4054 37.2744 10.3563 37.1733 8.86726 36.7057C7.11304 36.1734 5.64568 34.9586 4.79695 33.3337C3.72388 31.2767 3.68283 29.3313 4.3614 27.1608C3.24791 26.7407 1.82487 25.4159 1.21199 24.4088C0.201904 22.7486 -0.269499 20.9731 0.157303 19.0671C0.559551 17.2223 1.63275 15.5928 3.16805 14.4938C3.63231 14.1614 3.92905 14.0524 4.36531 13.7751C3.66912 11.7381 3.77522 10.1136 4.56258 8.09537C5.88901 4.69575 10.5384 2.87739 13.8848 4.35514C14.3704 2.88533 15.7972 1.55797 17.1348 0.840489ZM17.5362 22.7868L13.293 18.5446L10.4649 21.3727L17.5362 28.444L29.5567 16.4235L26.7286 13.5944L17.5362 22.7868Z' ],
		{ width: 24, height: 24 }
	);

	/** Sliders — the design's mark for "Edit badge". */
	var ICON_EDIT = svg( '0 0 24 24', [
		'm19 7.5h-7.628c-.3089-.87389-1.1423-1.5-2.122-1.5-.97966 0-1.81309.62611-2.12197 1.5h-2.12803v1.5h2.12803c.30888.87389 1.14231 1.5 2.12197 1.5.9797 0 1.8131-.62611 2.122-1.5h7.628z',
		'm19 15h-2.128c-.3089-.8739-1.1423-1.5-2.122-1.5s-1.8131.6261-2.122 1.5h-7.628v1.5h7.628c.3089.8739 1.1423 1.5 2.122 1.5s1.8131-.6261 2.122-1.5h2.128z'
	] );

	/** Two arrows chasing — the design's mark for "Refresh preview". */
	var ICON_REFRESH = svg( '0 0 24 24', [
		'm11.3 17.2-5-5c-.1-.1-.1-.3 0-.4l2.3-2.3-1.1-1-2.3 2.3c-.7.7-.7 1.8 0 2.5l5 5H7.5v1.5h5.3v-5.2h-1.5v2.6zm7.5-6.4-5-5h2.7V4.2h-5.2v5.2h1.5V6.8l5 5c.1.1.1.3 0 .4l-2.3 2.3 1.1 1.1 2.3-2.3c.6-.7.6-1.9-.1-2.5z'
	] );

	/** Box-with-arrow: marks the two actions that leave for another tab. */
	var ICON_EXT = svg( '0 0 24 24', [
		'M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z'
	], { className: 'sbr-tbb-ext' } );

	function badgeOptions() {
		var opts = [ { label: __( 'Select a badge', 'reviews-feed' ), value: 0 } ];

		BADGES.forEach( function ( b ) {
			opts.push( { label: b.name, value: b.id } );
		} );

		return opts;
	}

	function badgeById( id ) {
		for ( var i = 0; i < BADGES.length; i++ ) {
			if ( parseInt( BADGES[ i ].id, 10 ) === parseInt( id, 10 ) ) {
				return BADGES[ i ];
			}
		}

		return null;
	}

	/**
	 * Whether this badge is already on the page, at any depth.
	 *
	 * Recursive: a badge inside a Group or a Column is still on the page, and a
	 * top-level-only scan would insert a second copy on every reload of the
	 * wizard URL — which persists in the address bar.
	 *
	 * @param {Array}  list Blocks.
	 * @param {number} id   Badge id.
	 * @return {boolean}
	 */
	function containsBadge( list, id ) {
		return ( list || [] ).some( function ( block ) {
			if ( block.name === 'sbr/trust-badge' && parseInt( block.attributes.id, 10 ) === id ) {
				return true;
			}

			return containsBadge( block.innerBlocks, id );
		} );
	}

	/**
	 * Drop `sbr_badge_wizard` from the address bar.
	 *
	 * The hand-off is a one-shot instruction, but the URL that carries it
	 * survives every reload. Without this, an author who deletes the inserted
	 * badge and refreshes gets it back — and the post silently dirtied — with
	 * no action of their own. Stripping it is what makes the flag one-shot.
	 */
	function forgetWizardArg() {
		if ( ! window.history || ! window.history.replaceState || ! window.URL ) {
			return;
		}

		try {
			var url = new window.URL( window.location.href );

			if ( ! url.searchParams.has( 'sbr_badge_wizard' ) ) {
				return;
			}

			url.searchParams.delete( 'sbr_badge_wizard' );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			// An unparseable URL is not worth breaking the editor over.
		}
	}

	/**
	 * Has the block list been filled from the post yet?
	 *
	 * getCurrentPost().id turns truthy when the post ENTITY resolves, which is
	 * earlier than the editor's own resetBlocks. Latching on it alone means
	 * reading an empty block list on a post that has plenty — the duplicate
	 * guard sees nothing, and the insert that follows is then thrown away by
	 * the resetBlocks that arrives after it.
	 *
	 * So: a post with saved content is ready once it has blocks. A genuinely
	 * empty post has none to wait for, and any non-empty content yields at
	 * least a freeform block, so this cannot hang on real content.
	 *
	 * @param {Object} post             The current post.
	 * @param {Object} blockEditorStore The core/block-editor selectors.
	 *
	 * @return {boolean} True once the block list reflects the post.
	 */
	function blocksAreHydrated( post, blockEditorStore ) {
		var content = post.content;

		if ( content && typeof content === 'object' ) {
			content = content.raw;
		}

		if ( ! content || ! String( content ).trim() ) {
			return true;
		}

		return typeof blockEditorStore.getBlockCount === 'function'
			? blockEditorStore.getBlockCount() > 0
			: blockEditorStore.getBlocks().length > 0;
	}

	/**
	 * Insert the badge the embed modal was sent here to place.
	 *
	 * "Add and Go to Page" opens the editor with `sbr_badge_wizard=<id>`, which
	 * PHP hands over as `wizardBadge`. Its own flag rather than the feed
	 * wizard's, because that one carries the id of a FEED to insert — a badge
	 * sent through it opened the editor and inserted a feed.
	 *
	 * There are two separate races here, and only the first is obvious. On a
	 * post whose content is one classic block, the editor fired 47 store
	 * notifications with the post loaded and the block list still empty before
	 * the blocks appeared — so waiting on the post alone reads an empty list,
	 * which both blinds the duplicate guard and puts the insert in front of the
	 * editor's own reset. The second race outlives the first: even inserting on
	 * the tick where the blocks arrive, the badge was gone 1.2s later, because
	 * the dispatch lands inside the store's notification cycle. Hence the
	 * hydration gate AND the deferral below — either one alone leaves it broken.
	 */
	function placeWizardBadge() {
		var id = parseInt( CFG.wizardBadge, 10 );

		if ( ! id || ! data || ! data.select || ! data.dispatch || ! data.subscribe ) {
			return;
		}

		var done = false;

		// The teardown below only runs once hydration is reached. If it never is —
		// an editor error, a post entity that never resolves — the callback would
		// keep running on every store update for the session, so the wait is
		// bounded. 15s: the measured races here are ~1.2s, and past this the
		// editor has a bigger problem than a badge.
		var deadline = Date.now() + 15000;

		var unsubscribe = data.subscribe( function () {
			if ( done ) {
				return;
			}

			if ( Date.now() > deadline ) {
				done = true;

				if ( typeof unsubscribe === 'function' ) {
					unsubscribe();
				}

				// The wizard argument is deliberately LEFT in place: nothing was
				// inserted, so a reload should still try. forgetWizardArg() is for
				// the path that acted on it.
				return;
			}

			var editor = data.select( 'core/editor' );
			var blockEditorStore = data.select( 'core/block-editor' );

			var post = editor && editor.getCurrentPost && editor.getCurrentPost();

			if ( ! post || ! post.id || ! blockEditorStore ) {
				return;
			}

			if ( ! blocksAreHydrated( post, blockEditorStore ) ) {
				return;
			}

			done = true;

			if ( typeof unsubscribe === 'function' ) {
				unsubscribe();
			}

			// Before the insert, not after: whether or not this ends up placing
			// anything, the instruction has now been acted on once.
			forgetWizardArg();

			// Out of the listener before dispatching. Inserting from inside a
			// subscribe callback puts the dispatch in the middle of the store's
			// own notification cycle, and the block is gone again a moment
			// later — measured on a post whose content was already hydrated, so
			// this is not the hydration race above but a second one behind it.
			// One macrotask is the whole fix; the state is re-read there rather
			// than trusted from this tick.
			setTimeout( function () {
				var store = data.select( 'core/block-editor' );

				if ( containsBadge( store.getBlocks(), id ) ) {
					return;
				}

				data.dispatch( 'core/block-editor' ).insertBlocks(
					blocks.createBlock( 'sbr/trust-badge', { id: id } )
				);
			}, 0 );
		} );
	}

	blocks.registerBlockType( 'sbr/trust-badge', {
		// 3, matching the server registration and both sibling blocks. At 2 the
		// client value wins and opts the WHOLE post editor out of the iframed
		// canvas on WP 6.3+, degrading every other block on the page — and this
		// block's editor_style chain is exactly what the iframe path carries.
		apiVersion: 3,
		title: __( 'Trust Badge', 'reviews-feed' ),
		description: __( 'Show your aggregate rating.', 'reviews-feed' ),
		category: 'widgets',
		icon: ICON,
		keywords: [ __( 'badge', 'reviews-feed' ), __( 'rating', 'reviews-feed' ), __( 'reviews', 'reviews-feed' ), __( 'trust', 'reviews-feed' ) ],
		// Left/center/right only. The design's toolbar labels this control
		// "Align — free from block supports", so it is core's, not ours; the
		// three options are the ones its menu offers. Wide and full are excluded
		// deliberately — the badge has an intrinsic width, so stretching it
		// across a content column would only pad empty space around it.
		// Margin, and only margin. The design's Styles tab is a Dimensions panel with
		// one item, because everything else about how a badge looks belongs to the
		// customizer — offering padding or colour here would be a second place to
		// change one thing. WP renders the control itself from this declaration.
		supports: {
			html: false,
			align: [ 'left', 'center', 'right' ],
			spacing: { margin: true },
		},
		attributes: {
			id: { type: 'number', default: 0 },
			// Bumped to force ServerSideRender to refetch when nothing else
			// changed — the "Refresh preview" toolbar button.
			rev: { type: 'number', default: 0 }
		},

		edit: function ( props ) {
			var id = props.attributes.id;
			var chosen = badgeById( id );
			var blockProps = useBlockProps ? useBlockProps() : {};

			// A referenced badge that is no longer in the list has been deleted
			// since this block was placed.
			var isDeleted = id > 0 && ! chosen;

			var inspector = el(
				InspectorControls,
				{ key: 'inspector' },
				el(
					PanelBody,
					{ title: __( 'Badge', 'reviews-feed' ), initialOpen: true },
					BADGES.length === 0
						? el( 'p', { className: 'sb-empty' }, __( 'No badges yet. The panel appears once you have at least one badge to choose from.', 'reviews-feed' ) )
						: el(
							Fragment,
							null,
							el( SelectControl, {
								label: __( 'Badge', 'reviews-feed' ),
								value: id,
								options: badgeOptions(),
								onChange: function ( value ) {
									props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
								},
								// Everything else about how it looks lives in the
								// customizer. Saying so here stops the panel
								// reading as "styling coming soon".
								help: __( 'Style, theme and sources are set in the badge customizer.', 'reviews-feed' )
							} ),
							el(
								// Stacked and full-width, per the design's
								// `.sb-actions { flex-direction: column }`. Side by
								// side, "Manage All Badges" wrapped to two lines in
								// the 280px inspector.
								'div',
								{ className: 'sbr-tbb-actions' },
								chosen
									? el(
										Button,
										{
											variant: 'primary',
											href: EDIT_URL + chosen.id, target: '_blank', rel: 'noopener noreferrer'
										},
										__( 'Edit badge', 'reviews-feed' ),
										ICON_EXT
									)
									: null,
								el(
									Button,
									{
										variant: 'secondary',
										href: LIST_URL, target: '_blank', rel: 'noopener noreferrer'
									},
									__( 'Manage All Badges', 'reviews-feed' )
								)
							)
						)
				)
			);

			// Nothing chosen yet — or chosen and since deleted.
			if ( ! chosen ) {
				var noBadges = BADGES.length === 0;

				return el(
					'div',
					blockProps,
					inspector,
					isDeleted
						? el(
							Notice,
							{ status: 'warning', isDismissible: false },
							__( 'This badge no longer exists. Choose another one to keep showing your rating.', 'reviews-feed' )
						)
						: null,
					el(
						Placeholder,
						{
							icon: ICON,
							label: __( 'Trust Badge', 'reviews-feed' ),
							instructions: noBadges
								? __( 'Create your first Trust Badge to show your rating anywhere on your site.', 'reviews-feed' )
								: ( isDeleted
									? __( 'Nothing is showing on your page right now.', 'reviews-feed' )
									: __( 'Choose a badge to display.', 'reviews-feed' ) )
						},
						noBadges
							? el(
								Button,
								{ variant: 'primary', href: LIST_URL, target: '_blank', rel: 'noopener noreferrer' },
								__( 'Create a Trust Badge', 'reviews-feed' )
							)
							: el(
								Fragment,
								null,
								el( SelectControl, {
									value: id,
									options: badgeOptions(),
									onChange: function ( value ) {
										props.setAttributes( { id: parseInt( value, 10 ) || 0 } );
									}
								} ),
								el(
									Button,
									{ variant: 'link', href: LIST_URL, target: '_blank', rel: 'noopener noreferrer' },
									__( 'or create a new badge', 'reviews-feed' ),
									ICON_EXT
								)
							)
					)
				);
			}

			// Placed. Server-rendered, so the editor shows what the page will.
			return el(
				'div',
				blockProps,
				inspector,
				el(
					BlockControls,
					{ key: 'toolbar' },
					el(
						ToolbarGroup,
						null,
						el( ToolbarButton, {
							// The design puts both of these in the block toolbar
							// and marks them as ours. Editing a badge is a
							// different screen, so it opens in a new tab rather
							// than navigating away from an unsaved post — which
							// the tooltip says outright, so nobody loses a draft
							// finding out.
							icon: ICON_EDIT,
							label: __( 'Edit badge — opens the customizer in a new tab', 'reviews-feed' ),
							href: EDIT_URL + chosen.id, target: '_blank', rel: 'noopener noreferrer'
						} ),
						el( ToolbarButton, {
							// The preview is a cached server render; after editing
							// the badge in the other tab, this is how you pull the
							// new one in without reloading the whole editor.
							icon: ICON_REFRESH,
							label: __( 'Refresh preview', 'reviews-feed' ),
							onClick: function () {
								props.setAttributes( { rev: ( props.attributes.rev || 0 ) + 1 } );
							}
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, {
						block: 'sbr/trust-badge',
						attributes: { id: id, rev: props.attributes.rev },
						// An unresolvable feed renders nothing on purpose, so the
						// default "Block cannot be previewed" would be wrong. Say
						// what is actually true.
						EmptyResponsePlaceholder: function () {
							return el(
								Placeholder,
								{
									icon: ICON,
									label: __( 'Trust Badge', 'reviews-feed' ),
									instructions: __( 'This badge has no rating to show yet. Connect a source with reviews and it will appear here.', 'reviews-feed' )
								}
							);
						}
					} )
					: el( 'p', null, __( 'Preview unavailable in this editor.', 'reviews-feed' ) )
			);
		},

		// Server-rendered: nothing to serialise but the attributes.
		save: function () {
			return null;
		}
	} );

	// Subscribing straight away rather than waiting on domReady: the wait that
	// matters is the editor STORE hydrating, which placeWizardBadge() watches
	// for itself, and domReady is neither necessary nor sufficient for that.
	placeWizardBadge();
} )(
	// `window.wp || {}` at the call site: reading `.blocks` off an absent `wp` is
	// a TypeError before the guard above ever runs, which is the one failure the
	// guard cannot catch.
	( window.wp || {} ).blocks,
	( window.wp || {} ).element,
	( window.wp || {} ).blockEditor,
	( window.wp || {} ).components,
	( window.wp || {} ).serverSideRender,
	( window.wp || {} ).i18n,
	( window.wp || {} ).data
);
