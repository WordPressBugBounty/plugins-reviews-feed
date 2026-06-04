/**
 * Help Widget — Vanilla JS IIFE
 *
 * FAB trigger, 3-view panel (home / feature request / plugins cross-sell),
 * form validation, XHR submission, accessibility (focus trap, ARIA, keyboard).
 *
 * Attached to Shadow DOM for complete CSS isolation.
 * Mirrors the deactivation-modal.js architecture.
 */
( function() {
	'use strict';

	if ( typeof sbHelpWidgetData === 'undefined' ) {
		return;
	}

	var data = sbHelpWidgetData;

	// =========================================================
	// Shadow DOM Setup (mirrors deactivation pattern)
	// =========================================================
	var hosts     = document.querySelectorAll( '#sb-help-widget-host' );
	var templates = document.querySelectorAll( '#sb-help-widget-tpl' );

	// Deduplicate (multiple plugins may each render the template).
	if ( hosts.length > 1 ) {
		for ( var i = 1; i < hosts.length; i++ ) {
			hosts[ i ].parentNode.removeChild( hosts[ i ] );
		}
	}
	if ( templates.length > 1 ) {
		for ( var i = 1; i < templates.length; i++ ) {
			templates[ i ].parentNode.removeChild( templates[ i ] );
		}
	}

	var host     = hosts[0];
	var template = templates[0];

	if ( ! host || ! template ) {
		return;
	}

	var shadow = host.attachShadow( { mode: 'open' } );
	shadow.appendChild( template.content.cloneNode( true ) );

	// =========================================================
	// Element References
	// =========================================================
	var fab          = shadow.querySelector( '.sb-hw-fab' );
	var panel        = shadow.querySelector( '.sb-hw-panel' );
	var views        = shadow.querySelectorAll( '.sb-hw-view' );
	var homeCards    = shadow.querySelectorAll( '.sb-hw-home-card' );
	var backButtons  = shadow.querySelectorAll( '.sb-hw-back' );
	var liveRegion   = shadow.querySelector( '[aria-live="polite"]' );

	// Form elements.
	var form         = shadow.querySelector( '.sb-hw-form' );
	var descField    = shadow.querySelector( '#sb-hw-description' );
	var emailField   = shadow.querySelector( '#sb-hw-email' );
	var notifyField  = shadow.querySelector( '#sb-hw-notify' );
	var submitBtn    = shadow.querySelector( '.sb-hw-submit-btn' );
	var submitText   = shadow.querySelector( '.sb-hw-submit-text' );
	var charCounter  = shadow.querySelector( '.sb-hw-char-counter' );
	var successEl    = shadow.querySelector( '.sb-hw-success' );
	var errorStateEl = shadow.querySelector( '.sb-hw-error-state' );
	var resetBtn     = shadow.querySelector( '.sb-hw-reset-btn' );
	var retryBtn     = shadow.querySelector( '.sb-hw-retry-btn' );

	// Close button (on home view header).
	var closeBtn     = shadow.querySelector( '.sb-hw-close' );

	// Plugins view elements.
	var allAccessLink = shadow.querySelector( '.sb-hw-all-access' );
	var allAccessIcons = shadow.querySelector( '.sb-hw-all-access-icons' );
	var pluginsList   = shadow.querySelector( '.sb-hw-plugins-list' );
	var pluginIconsFan = shadow.querySelector( '.sb-hw-plugin-icons-fan' );

	// Help link.
	var helpLink = shadow.querySelector( '[data-action="help"]' );

	// =========================================================
	// State
	// =========================================================
	var isOpen       = false;
	var activeView   = 'home';
	var isSubmitting = false;
	var descTouched  = false;
	var emailTouched = false;

	var MAX_DESC     = 2000;
	var MIN_DESC     = 5;
	var COUNTER_THRESHOLD = 0.8; // Show counter at 80%.
	var EMAIL_REGEX  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	// SVG icon templates.
	var WARNING_ICON = '<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm-8,56a8,8,0,0,1,16,0v56a8,8,0,0,1-16,0Zm8,104a12,12,0,1,1,12-12A12,12,0,0,1,128,184Z"/></svg>';
	var ARROW_ICON   = '<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M200,64V168a8,8,0,0,1-16,0V83.31L69.66,197.66a8,8,0,0,1-11.32-11.32L172.69,72H88a8,8,0,0,1,0-16H192A8,8,0,0,1,200,64Z"/></svg>';

	// Brand icons for cross-sell (simple SVG paths).
	var BRAND_ICONS = {
		instagram: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C8.74 0 8.333.015 7.053.072 5.775.132 4.905.333 4.14.63c-.789.306-1.459.717-2.126 1.384S.935 3.35.63 4.14C.333 4.905.131 5.775.072 7.053.012 8.333 0 8.74 0 12s.015 3.667.072 4.947c.06 1.277.261 2.148.558 2.913.306.788.717 1.459 1.384 2.126.667.666 1.336 1.079 2.126 1.384.766.296 1.636.499 2.913.558C8.333 23.988 8.74 24 12 24s3.667-.015 4.947-.072c1.277-.06 2.148-.262 2.913-.558.788-.306 1.459-.718 2.126-1.384.666-.667 1.079-1.335 1.384-2.126.296-.765.499-1.636.558-2.913.06-1.28.072-1.687.072-4.947s-.015-3.667-.072-4.947c-.06-1.277-.262-2.149-.558-2.913-.306-.789-.718-1.459-1.384-2.126C21.319 1.347 20.651.935 19.86.63c-.765-.297-1.636-.499-2.913-.558C15.667.012 15.26 0 12 0zm0 2.16c3.203 0 3.585.016 4.85.071 1.17.055 1.805.249 2.227.415.562.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.057 1.266.07 1.646.07 4.85s-.015 3.585-.074 4.85c-.061 1.17-.256 1.805-.421 2.227-.224.562-.479.96-.899 1.382-.419.419-.824.679-1.38.896-.42.164-1.065.36-2.235.413-1.274.057-1.649.07-4.859.07-3.211 0-3.586-.015-4.859-.074-1.171-.061-1.816-.256-2.236-.421-.569-.224-.96-.479-1.379-.899-.421-.419-.69-.824-.9-1.38-.165-.42-.359-1.065-.42-2.235-.045-1.26-.061-1.649-.061-4.844 0-3.196.016-3.586.061-4.861.061-1.17.255-1.814.42-2.234.21-.57.479-.96.9-1.381.419-.419.81-.689 1.379-.898.42-.166 1.051-.361 2.221-.421 1.275-.045 1.65-.06 4.859-.06l.045.03zm0 3.678c-3.405 0-6.162 2.76-6.162 6.162 0 3.405 2.76 6.162 6.162 6.162 3.405 0 6.162-2.76 6.162-6.162 0-3.405-2.76-6.162-6.162-6.162zM12 16c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm7.846-10.405c0 .795-.646 1.44-1.44 1.44-.795 0-1.44-.646-1.44-1.44 0-.794.646-1.439 1.44-1.439.793-.001 1.44.645 1.44 1.439z"/></svg>',
		facebook: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>',
		twitter: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/></svg>',
		youtube: '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
		reviews: '<svg width="20" height="20" viewBox="0 0 48 48" fill="none"><path d="M41.47 4.625H7.76c-2.32 0-4.21 1.896-4.21 4.214v24.525c0 2.575 2.055 4.68 4.63 4.74l9.933.233 2.664 4.146c1.416 2.225 3.253 2.027 4.58.7l4.826-4.846H41.47c2.318 0 4.214-1.896 4.214-4.214V8.84c0-2.318-1.896-4.214-4.214-4.214z" fill="currentColor"/><path d="M23.91 13.13c.226-.65 1.146-.65 1.372 0l1.83 5.264a.27.27 0 00.247.098l5.572.113c.688.014.972.888.424 1.304l-4.441 3.367a.27.27 0 00-.052.158l1.614 5.335c.2.658-.544 1.199-1.11.806l-4.574-3.184a.27.27 0 00-.331 0l-4.575 3.184c-.565.393-1.309-.148-1.11-.806l1.614-5.335a.27.27 0 00-.051-.158l-4.441-3.367c-.549-.416-.265-1.29.424-1.304l5.572-.113a.27.27 0 00.247-.098l1.83-5.264z" fill="white"/></svg>',
		tiktok: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
		wpchat: '<svg width="20" height="20" viewBox="0 0 40 40" fill="none"><path d="M24.9044 5.6283C32.471 5.6283 38.6049 11.7622 38.6049 19.3288C38.6049 27.075 32.2379 33.1326 24.7467 33.0312L1.39551 34.2794L4.02656 26.2735C2.36831 24.0989 1.39574 21.3895 1.39574 18.4818C1.39574 11.383 7.15043 5.6283 14.2492 5.6283H24.9044Z" fill="currentColor"/><path fill-rule="evenodd" clip-rule="evenodd" d="M15.4225 18.6218V18.7695C15.4225 20.8897 17.1413 22.6085 19.2616 22.6085C21.3818 22.6085 23.1006 20.8897 23.1006 18.7695V18.6218H26.9397V18.7695C26.9397 23.01 23.5021 26.4476 19.2616 26.4476C15.0211 26.4476 11.5835 23.01 11.5835 18.7695V18.6218H15.4225Z" fill="white"/></svg>'
	};

	// =========================================================
	// i18n helper
	// =========================================================
	// Reads translated strings from the data blob (passed by PHP through
	// __()), with an English fallback for safety. WordPress doesn't ship
	// __() in JS for vanilla scripts; this avoids hardcoding English in
	// error paths so translators can localise via the data blob.
	function i18n( key, fallback ) {
		return ( data.i18n && data.i18n[ key ] ) ? data.i18n[ key ] : fallback;
	}

	// =========================================================
	// HTML escape helpers — used for any data-blob value interpolated
	// into innerHTML / attribute strings. Cheap defense-in-depth even
	// when the values are PHP-hardcoded today.
	// =========================================================
	var HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function( c ) { return HTML_ESCAPES[ c ]; } );
	}
	function escapeAttr( s ) {
		return escapeHtml( s );
	}

	// =========================================================
	// Init
	// =========================================================
	function init() {
		// Set up help link URL. Guard against non-http(s) schemes — values
		// originate from PHP `init()` config today, but defending against
		// a future `javascript:` URL is cheap.
		if ( helpLink && data.helpUrl && /^https?:\/\//i.test( data.helpUrl ) ) {
			helpLink.href = data.helpUrl;
		}

		// Set up all access link.
		if ( allAccessLink && data.allAccessUrl && /^https?:\/\//i.test( data.allAccessUrl ) ) {
			allAccessLink.href = data.allAccessUrl;
		}

		// Prefill email.
		if ( emailField && data.userEmail ) {
			emailField.value = data.userEmail;
		}

		renderCrossSellPlugins();
		bindFAB();
		bindCloseButton();
		bindHomeCards();
		bindBackButtons();
		bindForm();
		bindEscape();
		bindClickOutside();
	}

	// =========================================================
	// FAB Toggle
	// =========================================================
	function bindFAB() {
		fab.addEventListener( 'click', function() {
			if ( isOpen ) {
				closePanel();
			} else {
				openPanel();
			}
		});
	}

	function bindCloseButton() {
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function() {
				closePanel();
			});
		}
	}

	function openPanel() {
		isOpen = true;
		fab.classList.add( 'sb-hw-fab--open' );
		fab.setAttribute( 'aria-expanded', 'true' );
		fab.setAttribute( 'aria-label', 'Close help menu' );
		panel.classList.add( 'sb-hw-panel--open' );

		// Focus first interactive element in panel.
		var firstFocusable = panel.querySelector( 'button, a, input, textarea, [tabindex]' );
		if ( firstFocusable ) {
			setTimeout( function() { firstFocusable.focus(); }, 200 );
		}

		announce( 'Help menu opened' );
	}

	function closePanel() {
		isOpen = false;
		fab.classList.remove( 'sb-hw-fab--open' );
		fab.setAttribute( 'aria-expanded', 'false' );
		fab.setAttribute( 'aria-label', 'Open help menu' );
		panel.classList.remove( 'sb-hw-panel--open' );

		// Reset to home view on close.
		showView( 'home' );

		// Restore focus to FAB.
		fab.focus();

		announce( 'Help menu closed' );
	}

	// =========================================================
	// View Transitions
	// =========================================================
	function showView( view ) {
		activeView = view;

		for ( var i = 0; i < views.length; i++ ) {
			var v = views[ i ];
			if ( v.getAttribute( 'data-view' ) === view ) {
				v.classList.add( 'sb-hw-view--active' );
			} else {
				v.classList.remove( 'sb-hw-view--active' );
			}
		}

		// Toggle gradient height for sub-views.
		if ( view === 'home' ) {
			panel.classList.remove( 'sb-hw-panel--subview' );
		} else {
			panel.classList.add( 'sb-hw-panel--subview' );
		}

		// Move focus into the new view for screen reader users.
		setTimeout( function() {
			var activeViewEl = panel.querySelector( '.sb-hw-view--active' );
			if ( ! activeViewEl ) return;

			// Focus the back button on sub-views, or the first card on home.
			var target = activeViewEl.querySelector( '.sb-hw-back' )
				|| activeViewEl.querySelector( '.sb-hw-home-card' );
			if ( target ) target.focus();
		}, 160 ); // Matches CSS transition duration.
	}

	// =========================================================
	// Home Cards
	// =========================================================
	function bindHomeCards() {
		for ( var i = 0; i < homeCards.length; i++ ) {
			( function( card ) {
				var action = card.getAttribute( 'data-action' );
				if ( action === 'feature' ) {
					card.addEventListener( 'click', function( e ) {
						e.preventDefault();
						showView( 'feature' );
						announce( 'Feature request form' );
					});
				} else if ( action === 'plugins' ) {
					card.addEventListener( 'click', function( e ) {
						e.preventDefault();
						showView( 'plugins' );
						announce( 'Browse plugins' );
					});
				}
				// 'help' is an <a> tag — no JS needed, opens externally.
			})( homeCards[ i ] );
		}
	}

	// =========================================================
	// Back Buttons
	// =========================================================
	function bindBackButtons() {
		for ( var i = 0; i < backButtons.length; i++ ) {
			backButtons[ i ].addEventListener( 'click', function() {
				showView( 'home' );
				announce( 'Back to help menu' );
			});
		}
	}

	// =========================================================
	// Form — Validation, Submission, States
	// =========================================================
	function bindForm() {
		if ( ! form ) return;

		// Description input events.
		descField.addEventListener( 'input', function() {
			descTouched = true;
			updateCharCounter();
			validateDescription();
		});

		descField.addEventListener( 'blur', function() {
			descTouched = true;
			validateDescription();
		});

		// Email input events.
		emailField.addEventListener( 'input', function() {
			emailTouched = true;
			validateEmail();
		});

		emailField.addEventListener( 'blur', function() {
			emailTouched = true;
			validateEmail();
		});

		// Notify checkbox — makes email conditionally required.
		notifyField.addEventListener( 'change', function() {
			if ( notifyField.checked ) {
				emailTouched = true;
				emailField.setAttribute( 'aria-required', 'true' );
			} else {
				emailField.removeAttribute( 'aria-required' );
			}
			validateEmail();
		});

		// Submit.
		form.addEventListener( 'submit', function( e ) {
			e.preventDefault();
			handleSubmit();
		});

		// Reset (from success state).
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function() {
				resetForm();
			});
		}

		// Retry (from error state).
		if ( retryBtn ) {
			retryBtn.addEventListener( 'click', function() {
				errorStateEl.style.display = 'none';
				form.style.display = '';
				// Re-attempt submission.
				handleSubmit();
			});
		}
	}

	function updateCharCounter() {
		var len = descField.value.length;
		var threshold = Math.floor( MAX_DESC * COUNTER_THRESHOLD );
		var footerEl = descField.parentNode.querySelector( '.sb-hw-field-footer' );

		if ( len >= threshold ) {
			charCounter.textContent = len + '/' + MAX_DESC;
			charCounter.style.display = '';
			if ( footerEl ) footerEl.style.display = '';
			if ( len > MAX_DESC ) {
				charCounter.classList.add( 'sb-hw-char-counter--over' );
			} else {
				charCounter.classList.remove( 'sb-hw-char-counter--over' );
			}
		} else {
			charCounter.textContent = '';
			charCounter.style.display = 'none';
			// Only hide footer if no error is showing.
			var errorEl = footerEl ? footerEl.querySelector( '.sb-hw-field-error' ) : null;
			if ( footerEl && ( ! errorEl || ! errorEl.innerHTML ) ) {
				footerEl.style.display = 'none';
			}
		}
	}

	function validateDescription() {
		var val = descField.value.trim();
		var footerEl = descField.parentNode.querySelector( '.sb-hw-field-footer' );
		var errorEl = footerEl ? footerEl.querySelector( '.sb-hw-field-error' ) : null;

		if ( ! descTouched ) {
			if ( footerEl ) footerEl.style.display = 'none';
			if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
			descField.classList.remove( 'sb-hw-textarea--error' );
			return true;
		}

		if ( val.length === 0 ) {
			showFieldError( descField, footerEl, errorEl, "What's the idea? Drop it above." );
			return false;
		}

		if ( val.length < MIN_DESC ) {
			showFieldError( descField, footerEl, errorEl, i18n( 'descriptionTooShort', 'Too short. Try adding at least a sentence.' ) );
			return false;
		}

		if ( val.length > MAX_DESC ) {
			// Counter handles the visual — no text error, but still invalid.
			if ( footerEl ) footerEl.style.display = '';
			if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
			descField.classList.add( 'sb-hw-textarea--error' );
			return false;
		}

		if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
		descField.classList.remove( 'sb-hw-textarea--error' );
		// Show footer only if counter is visible.
		if ( footerEl && charCounter.textContent ) { footerEl.style.display = ''; }
		else if ( footerEl ) { footerEl.style.display = 'none'; }
		return true;
	}

	function showFieldError( field, footerEl, errorEl, message ) {
		if ( footerEl ) footerEl.style.display = '';
		if ( errorEl ) {
			errorEl.innerHTML = WARNING_ICON + ' ' + message;
			errorEl.style.display = '';
		}
		field.classList.add( field.tagName === 'TEXTAREA' ? 'sb-hw-textarea--error' : 'sb-hw-input--error' );
	}

	function validateEmail() {
		var errorEl = shadow.querySelector( '.sb-hw-email-error' );
		var val     = emailField.value.trim();

		// Email only required if notify is checked.
		if ( ! notifyField.checked ) {
			if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
			emailField.classList.remove( 'sb-hw-input--error' );
			return true;
		}

		if ( ! emailTouched ) {
			if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
			emailField.classList.remove( 'sb-hw-input--error' );
			return true;
		}

		if ( val.length === 0 ) {
			if ( errorEl ) { errorEl.innerHTML = WARNING_ICON + " To get notified, we'll need your email"; errorEl.style.display = ''; }
			emailField.classList.add( 'sb-hw-input--error' );
			return false;
		}

		if ( ! EMAIL_REGEX.test( val ) ) {
			if ( errorEl ) { errorEl.innerHTML = WARNING_ICON + ' Looks like a typo in the email'; errorEl.style.display = ''; }
			emailField.classList.add( 'sb-hw-input--error' );
			return false;
		}

		if ( errorEl ) { errorEl.innerHTML = ''; errorEl.style.display = 'none'; }
		emailField.classList.remove( 'sb-hw-input--error' );
		return true;
	}

	function handleSubmit() {
		// Force-touch all fields for validation display.
		descTouched = true;
		emailTouched = true;

		var descValid  = validateDescription();
		var emailValid = validateEmail();

		if ( ! descValid || ! emailValid ) {
			return;
		}

		if ( isSubmitting ) {
			return;
		}

		isSubmitting = true;
		submitBtn.disabled = true;
		submitText.textContent = 'Submitting...';

		// Build form data.
		var formData = new FormData();
		formData.append( 'action', 'sb_feature_suggestion' );
		formData.append( 'nonce', data.nonce );
		formData.append( 'plugin_slug', data.primaryPlugin || '' );
		formData.append( 'description', descField.value.trim() );
		formData.append( 'email', emailField.value.trim() );
		formData.append( 'notify_on_ship', notifyField.checked ? 'true' : 'false' );
		formData.append( 'current_page', data.primaryName || '' );

		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', data.ajaxUrl, true );
		// Match the server-side timeout in HelpWidget::handle_ajax (15s,
		// filterable via sb_feature_request_timeout). A shorter client
		// timeout would surface an error to the user on a request the
		// server may still complete successfully.
		xhr.timeout = 15000;

		var handled = false;
		function handleResponse() {
			if ( handled ) return;
			handled = true;

			isSubmitting = false;
			submitBtn.disabled = false;
			submitText.textContent = 'Submit Request';

			if ( xhr.status === 200 ) {
				var response;
				try {
					response = JSON.parse( xhr.responseText );
				} catch( e ) {
					showError();
					return;
				}

				if ( response && response.success ) {
					showSuccess();
				} else if ( response && response.data && response.data.message === 'rate_limit' ) {
					showError( 'rate_limit' );
				} else {
					showError();
				}
			} else if ( xhr.status === 429 ) {
				showError( 'rate_limit' );
			} else {
				showError();
			}
		}

		xhr.onreadystatechange = function() {
			if ( xhr.readyState === 4 ) {
				handleResponse();
			}
		};

		xhr.onerror = function() {
			handleResponse();
		};

		xhr.ontimeout = function() {
			handleResponse();
		};

		xhr.send( formData );
	}

	function showSuccess() {
		form.style.display = 'none';
		errorStateEl.style.display = 'none';
		successEl.style.display = '';
		announce( 'Feature request submitted successfully' );
	}

	function showError( type ) {
		form.style.display = 'none';
		successEl.style.display = 'none';
		errorStateEl.style.display = '';

		var titleEl = errorStateEl.querySelector( '.sb-hw-error-title' );
		var msgEl   = errorStateEl.querySelector( '.sb-hw-error-message' );

		if ( type === 'rate_limit' ) {
			titleEl.textContent = i18n( 'rateLimitTitle', 'Too many requests' );
			msgEl.textContent   = i18n( 'rateLimitMessage', 'Please wait a moment and try again.' );
		} else {
			titleEl.textContent = i18n( 'errorTitle', 'Something went wrong' );
			msgEl.innerHTML     = i18n( 'errorMessage', 'Please try again. If the problem persists, email us at <a href="mailto:support@smashballoon.com">support@smashballoon.com</a>' );
		}

		announce( 'Error submitting feature request' );
	}

	function resetForm() {
		descField.value = '';
		// Keep email prefilled.
		notifyField.checked = false;
		emailField.removeAttribute( 'aria-required' );
		descTouched = false;
		emailTouched = false;
		charCounter.textContent = '';
		charCounter.style.display = 'none';

		// Clear errors.
		var errors = shadow.querySelectorAll( '.sb-hw-field-error' );
		for ( var i = 0; i < errors.length; i++ ) {
			errors[ i ].innerHTML = '';
		}
		descField.classList.remove( 'sb-hw-textarea--error' );
		emailField.classList.remove( 'sb-hw-input--error' );

		successEl.style.display = 'none';
		errorStateEl.style.display = 'none';
		form.style.display = '';
	}

	// =========================================================
	// Cross-sell Plugins
	// =========================================================
	function renderCrossSellPlugins() {
		var plugins = data.crossSellPlugins || [];

		// Hide the "Explore our other plugins" entry on the home view when
		// there's nothing to cross-sell (e.g. the user already has every SB
		// plugin + WPChat active). Avoids surfacing a dead card that opens
		// an empty list.
		var homeCard = shadow.querySelector( '.sb-hw-home-card--plugins' );
		if ( homeCard ) {
			homeCard.style.display = plugins.length === 0 ? 'none' : '';
		}
		if ( plugins.length === 0 ) {
			return;
		}

		// Render All Access icons (fanned tiles, smaller than home card).
		// Exclude plugins not included in the All Access bundle (e.g. WPChat).
		if ( allAccessIcons ) {
			var iconsHtml = '';
			var aaPlugins = [];
			for ( var k = 0; k < plugins.length; k++ ) {
				if ( plugins[ k ].includedInAllAccess !== false ) {
					aaPlugins.push( plugins[ k ] );
				}
			}
			var aaCount = aaPlugins.length;
			var aaMax = ( aaCount - 1 ) / 2;
			for ( var i = 0; i < aaCount; i++ ) {
				var p = aaPlugins[ i ];
				var aaOffset = i - aaMax;
				var aaAngle = aaOffset * 1.2;
				var aaTranslateY = ( aaOffset * aaOffset - aaMax * aaMax ) * 0.6;
				var aaMl = i === 0 ? '0' : '-2px';
				var aaZ = aaCount - Math.abs( Math.round( aaOffset * 2 ) );
				iconsHtml += '<span style="'
					+ 'display:inline-flex;align-items:center;justify-content:center;'
					+ 'width:48px;height:48px;background:#fff;border-radius:8px;'
					+ 'box-shadow:0 7px 22px rgba(61,81,168,0.12),0 1px 4px rgba(61,81,168,0.10);'
					+ 'margin-left:' + aaMl + ';'
					+ 'z-index:' + aaZ + ';position:relative;'
					+ 'transform:rotate(' + aaAngle + 'deg) translate(0,' + aaTranslateY + 'px);'
					+ 'color:' + p.color + ';">'
					+ ( BRAND_ICONS[ p.icon ] || '' )
					+ '</span>';
			}
			allAccessIcons.innerHTML = iconsHtml;
		}

		// Render plugin icons fan on home card (fanned tiles like prototype).
		if ( pluginIconsFan ) {
			var fanHtml = '';
			var count = plugins.length;
			var maxOffset = ( count - 1 ) / 2;
			for ( var i = 0; i < count; i++ ) {
				var p = plugins[ i ];
				var offset = i - maxOffset;
				var angle = offset * 3;
				var translateY = ( offset * offset - maxOffset * maxOffset ) * 1.7;
				var ml = i === 0 ? '0' : '-10px';
				var z = count - Math.abs( Math.round( offset * 2 ) );
				// WPChat icon renders larger (27px) in the fan, matching the prototype.
				var fanIcon = BRAND_ICONS[ p.icon ] || '';
				if ( p.key === 'wpchat' ) {
					fanIcon = fanIcon.replace( 'width="20" height="20"', 'width="27" height="27"' );
				}
				fanHtml += '<span style="'
					+ 'display:inline-flex;align-items:center;justify-content:center;'
					+ 'width:56px;height:56px;background:#fff;border-radius:8px;'
					+ 'box-shadow:0 7px 22px rgba(61,81,168,0.12),0 1px 4px rgba(61,81,168,0.10);'
					+ 'margin-left:' + ml + ';'
					+ 'z-index:' + z + ';position:relative;'
					+ 'transform:rotate(' + angle + 'deg) translate(0,' + translateY + 'px);'
					+ 'color:' + p.color + ';">'
					+ fanIcon
					+ '</span>';
			}
			pluginIconsFan.innerHTML = fanHtml;
		}

		// Render plugin list (raw icons with brand color, no circle background).
		// CAUTION: `p.url`, `p.name`, and `p.color` are PHP-hardcoded in
		// HelpWidget::get_cross_sell_plugins(). If a filter is ever added
		// that lets sites override these, the values MUST be escaped /
		// validated before interpolation here.
		if ( pluginsList ) {
			var html = '';
			for ( var i = 0; i < plugins.length; i++ ) {
				var p = plugins[ i ];
				// Skip entries with a non-http(s) URL.
				if ( ! /^https?:\/\//i.test( p.url || '' ) ) {
					continue;
				}
				html += '<a href="' + escapeAttr( p.url ) + '" class="sb-hw-plugin-card" target="_blank" rel="noopener noreferrer">'
					+ '<span class="sb-hw-plugin-icon" style="color:' + escapeAttr( p.color ) + '">' + ( BRAND_ICONS[ p.icon ] || '' ) + '</span>'
					+ '<span class="sb-hw-plugin-name">' + escapeHtml( p.name ) + '</span>'
					+ '<span class="sb-hw-plugin-arrow">' + ARROW_ICON + '</span>'
					+ '</a>';
			}
			pluginsList.innerHTML = html;
		}
	}

	// =========================================================
	// Keyboard & Click-outside
	// =========================================================
	function bindEscape() {
		document.addEventListener( 'keydown', function( e ) {
			if ( isOpen && e.key === 'Escape' ) {
				closePanel();
			}
		});

		// Tab trap inside panel when open.
		shadow.addEventListener( 'keydown', function( e ) {
			if ( ! isOpen || e.key !== 'Tab' ) return;

			var focusable = panel.querySelectorAll(
				'button:not([disabled]), a[href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			);

			if ( focusable.length === 0 ) return;

			var first = focusable[0];
			var last  = focusable[ focusable.length - 1 ];

			if ( e.shiftKey ) {
				if ( shadow.activeElement === first || document.activeElement === host ) {
					e.preventDefault();
					last.focus();
				}
			} else {
				// Wrap forward from the last element back to the first,
				// and also catch the case where Tab enters the host with
				// no inner focus yet (document.activeElement === host).
				if ( shadow.activeElement === last || document.activeElement === host ) {
					e.preventDefault();
					first.focus();
				}
			}
		});
	}

	function bindClickOutside() {
		document.addEventListener( 'mousedown', function( e ) {
			if ( ! isOpen ) return;

			// Ignore clicks on the FAB (it has its own toggle).
			if ( host.contains( e.target ) || e.target === host ) return;

			// Check if click is outside the shadow host.
			closePanel();
		});
	}

	// =========================================================
	// Accessibility — Announcements
	// =========================================================
	function announce( message ) {
		if ( liveRegion ) {
			liveRegion.textContent = message;
		}
	}

	// =========================================================
	// Go
	// =========================================================
	init();
})();
