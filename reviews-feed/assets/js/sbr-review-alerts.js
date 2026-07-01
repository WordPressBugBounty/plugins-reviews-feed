/**
 * Review Alert Controller
 *
 * Vanilla JS class for review alert behavior:
 * - Initial delay before showing
 * - Review cycling with animations
 * - Expand/collapse functionality (click to expand, show all reviews)
 * - Dismiss with localStorage persistence
 * - Keyboard accessibility (Escape to close, Enter/Space to expand)
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews
 */

(function() {
	'use strict';

	/**
	 * SBR Notification Popup Controller
	 *
	 * @class SBRReviewAlert
	 */
	class SBRReviewAlert {
		/**
		 * Constructor
		 *
		 * @param {Object} config Configuration from localized script
		 * @param {number} config.popupId - Unique popup identifier
		 * @param {string} config.theme - Theme name ('light', 'dark', 'minimal', 'minimal-dark')
		 * @param {string} config.popupType - Popup type ('aggregate' or 'recent')
		 * @param {string} config.accentColor - Hex color for accent
		 * @param {string} config.accentHue - HSL hue value (0-360)
		 * @param {string} config.position - Position ('bottom-right', 'bottom-left', 'top-right', 'top-left')
		 * @param {Object} config.timing - Timing configuration
		 * @param {Object} config.content - Content visibility settings
		 * @param {Array} config.reviews - Array of review objects
		 */
		constructor(config) {
			this.config = this.mergeDefaults(config);
			this.popup = null;
			// Start with a random review so users see different reviews on each page load
			const reviewCount = this.config.reviews?.length || 0;
			this.currentReviewIndex = reviewCount > 0 ? Math.floor(Math.random() * reviewCount) : 0;
			this.cycleInterval = null;
			this.isVisible = false;
			this.isExpanded = false;
			this.isAnimating = false;
			this.isDismissed = this.checkDismissed();
			this.collapsedContent = null; // Store collapsed content for restoration
			this.animationTimeouts = []; // Track animation timeouts for cleanup
			this.cycleTimeouts = []; // Track cycling timeouts for cleanup
			this.initialDelayTimeout = null; // Track initial delay timeout for cleanup

			// Compact mode threshold (px from bottom)
			this.compactThreshold = 200;
			this.isCompact = false;

			// Number of reviews to show initially in expanded view
			this.initialExpandedReviewCount = 10;

			// Bind methods
			this.handleClose = this.handleClose.bind(this);
			this.handleKeydown = this.handleKeydown.bind(this);
			this.cycleReview = this.cycleReview.bind(this);
			this.handlePopupClick = this.handlePopupClick.bind(this);
			this.handleExpandKeydown = this.handleExpandKeydown.bind(this);
			this.handleCloseExpanded = this.handleCloseExpanded.bind(this);
			this.handleScroll = this.handleScroll.bind(this);
			this.handleSeeAllClick = this.handleSeeAllClick.bind(this);
			this.handleExpandedClick = this.handleExpandedClick.bind(this);

			// Initialize if not dismissed
			if (!this.isDismissed && this.config.reviews.length > 0) {
				this.init();
			}
		}

		/**
		 * Merge user config with defaults
		 *
		 * @param {Object} config User configuration
		 * @returns {Object} Merged configuration
		 */
		mergeDefaults(config) {
			const defaults = {
				popupId: 0,
				theme: 'light', // Must match PHP default: 'light', 'dark', 'minimal', 'minimal-dark'
				variation: 'v1',
				popupType: 'aggregate', // 'aggregate' (summary) or 'recent' (cycles through reviews)
				accentColor: '#175CE3',
				accentHue: '220',
				position: 'bottom-right',
				linkUrl: '#',
				timing: {
					mode: 'fixed',            // 'fixed' or 'random' - controls review cycling interval
					cycleIntervalMin: 3000,   // Min cycle interval for random mode (ms)
					cycleIntervalMax: 5000,   // Cycle interval for fixed mode / max for random (ms)
					displayDuration: 5000
				},
				content: {
					showRating: true,
					showTotalReviews: true,
					showAvatar: true,
					showReviewerName: true,
					showPoweredBy: true
				},
				reviewFeed: {
					showHeading: true,
					headingText: '',
					showButton: true,
					buttonText: '',
					buttonUrl: '',
					buttonIcon: null,
					showStars: true,
					showTitle: true,
					showText: true,
					showAuthor: true,
					showDate: true,
					showPoweredBy: true
				},
				i18n: {
					reviewerHeadingTemplate: '%s left us a review'
				},
				reviews: [],
				totalReviews: 0
			};

			return {
				...defaults,
				...config,
				timing: { ...defaults.timing, ...(config.timing || {}) },
				content: { ...defaults.content, ...(config.content || {}) },
				reviewFeed: { ...defaults.reviewFeed, ...(config.reviewFeed || {}) },
				i18n: { ...defaults.i18n, ...(config.i18n || {}) }
			};
		}

		/**
		 * Calculate the initial delay for popup appearance
		 *
		 * Always returns 3 seconds - popup appears after 3s on page load.
		 * Timing settings control review cycling, not initial appearance.
		 *
		 * @returns {number} Delay in milliseconds (always 3000ms)
		 */
		calculateInitialDelay() {
			return 3000; // Always show popup after 3 seconds
		}

		/**
		 * Calculate the cycle interval based on timing mode
		 *
		 * For 'recent' popup type, controls how long each review is shown
		 * before cycling to the next one.
		 *
		 * @returns {number} Cycle interval in milliseconds
		 */
		calculateCycleInterval() {
			const { mode, cycleIntervalMin, cycleIntervalMax } = this.config.timing;

			if (mode === 'fixed') {
				// Fixed mode: use the fixed cycle interval
				return cycleIntervalMax || 5000;
			}

			// Random mode: random value between min and max
			const min = cycleIntervalMin || 3000;
			const max = cycleIntervalMax || 8000;
			return Math.floor(Math.random() * (max - min + 1)) + min;
		}

		/**
		 * Convert hex color to HSL hue value (0-360)
		 *
		 * Extracts hue from hex color for HSL-based theming.
		 * Hue values match standard color wheel (0=red, 60=yellow, 120=green, etc.)
		 *
		 * @param {string} hex Hex color string (e.g., '#175CE3' or '175CE3')
		 * @returns {number} HSL hue value (0-360)
		 */
		hexToHue(hex) {
			// Remove # if present
			hex = hex.replace(/^#/, '');

			// Parse RGB values (0-1 range)
			const r = parseInt(hex.substring(0, 2), 16) / 255;
			const g = parseInt(hex.substring(2, 4), 16) / 255;
			const b = parseInt(hex.substring(4, 6), 16) / 255;

			const max = Math.max(r, g, b);
			const min = Math.min(r, g, b);
			const delta = max - min;

			let h = 0;

			if (delta === 0) {
				h = 0; // Achromatic (gray)
			} else if (max === r) {
				h = 60 * (((g - b) / delta) % 6);
			} else if (max === g) {
				h = 60 * (((b - r) / delta) + 2);
			} else {
				h = 60 * (((r - g) / delta) + 4);
			}

			if (h < 0) h += 360;

			return Math.round(h);
		}

		/**
		 * Apply accent color as CSS custom property
		 */
		applyAccentColor() {
			if (!this.popup) {
				return;
			}

			// Get accent color from config or use default (must match PHP default)
			let accentColor = this.config.accentColor || '#175CE3';

			// Ensure color has # prefix
			if (!accentColor.startsWith('#')) {
				accentColor = '#' + accentColor;
			}

			// Set accent color as CSS custom property (hex value)
			// IMPORTANT: Must use --sbr-popup-accent-fallback to match CSS selectors
			this.popup.style.setProperty('--sbr-popup-accent-fallback', accentColor);

			// Set hue for HSL-based CSS theming
			let hue;
			if (this.config.accentHue) {
				hue = parseInt(this.config.accentHue, 10);
			} else {
				hue = this.hexToHue(accentColor);
			}
			this.popup.style.setProperty('--sbr-popup-accent-hue', hue);
		}

		/**
		 * Initialize the popup
		 */
		init() {
			// Find popup element in DOM
			this.popup = document.querySelector('.sbr-review-alert');

			if (!this.popup) {
				console.warn('SBR Review Alert: Popup element not found');
				return;
			}

			// Apply accent color from config
			this.applyAccentColor();

			// Set up event listeners
			this.setupEventListeners();

			// Calculate delay based on timing mode (random or fixed)
			const delay = this.calculateInitialDelay();

			// Show popup after calculated delay (track timeout for cleanup)
			this.initialDelayTimeout = setTimeout(() => {
				this.show();
			}, delay);
		}

		/**
		 * Set up event listeners
		 */
		setupEventListeners() {
			// Close button (for dismiss)
			const closeBtn = this.popup.querySelector('.sbr-review-alert__close');
			if (closeBtn) {
				closeBtn.addEventListener('click', this.handleClose);
			}

			// Keyboard events (Escape to close/collapse)
			document.addEventListener('keydown', this.handleKeydown);

			// Click on popup to expand (when collapsed)
			const inner = this.popup.querySelector('.sbr-review-alert__inner');
			if (inner) {
				inner.addEventListener('click', this.handlePopupClick);
			}

			// Keyboard expand (Enter/Space when focused)
			this.popup.addEventListener('keydown', this.handleExpandKeydown);

			// Make popup focusable for keyboard users
			this.popup.setAttribute('tabindex', '0');
			this.popup.setAttribute('role', 'button');
			this.popup.setAttribute('aria-expanded', 'false');

			// Scroll listener for compact mode near footer
			window.addEventListener('scroll', this.handleScroll, { passive: true });
			// Initial check
			this.handleScroll();
		}

		/**
		 * Remove event listeners
		 */
		removeEventListeners() {
			const closeBtn = this.popup.querySelector('.sbr-review-alert__close');
			if (closeBtn) {
				closeBtn.removeEventListener('click', this.handleClose);
			}

			document.removeEventListener('keydown', this.handleKeydown);

			// Remove expand listeners
			const inner = this.popup.querySelector('.sbr-review-alert__inner');
			if (inner) {
				inner.removeEventListener('click', this.handlePopupClick);
			}
			this.popup.removeEventListener('keydown', this.handleExpandKeydown);

			// Remove expanded close button listener
			const expandedCloseBtn = this.popup.querySelector('.sbr-review-alert__close-btn');
			if (expandedCloseBtn) {
				expandedCloseBtn.removeEventListener('click', this.handleCloseExpanded);
			}

			// Remove expanded content click delegation listener
			const expandedContent = this.popup.querySelector('.sbr-review-alert__expanded');
			if (expandedContent) {
				expandedContent.removeEventListener('click', this.handleExpandedClick);
			}

			// Remove scroll listener
			window.removeEventListener('scroll', this.handleScroll);
		}

		/**
		 * Handle close button click
		 *
		 * @param {Event} event Click event
		 */
		handleClose(event) {
			event.preventDefault();
			this.dismiss();
		}

		/**
		 * Handle keyboard events
		 *
		 * @param {KeyboardEvent} event Keyboard event
		 */
		handleKeydown(event) {
			if (event.key === 'Escape' && this.isVisible) {
				if (this.isExpanded) {
					// Collapse expanded view first
					this.collapse();
				} else {
					// Dismiss popup entirely
					this.dismiss();
				}
			}
		}

		/**
		 * Handle click on popup to expand
		 *
		 * @param {Event} event Click event
		 */
		handlePopupClick(event) {
			// Don't expand if already expanded or animating
			if (this.isExpanded || this.isAnimating) return;

			// Check if clicking on the "View All Reviews" link - should trigger expand
			const viewAllLink = event.target.closest('.sbr-review-alert__view-all-link');
			if (viewAllLink) {
				event.preventDefault();
				event.stopPropagation();
				this.expand();
				return;
			}

			// Don't expand if clicking on other links (e.g., powered by)
			if (event.target.closest('a')) return;

			event.preventDefault();
			this.expand();
		}

		/**
		 * Handle keyboard events for expand (Enter/Space)
		 *
		 * @param {KeyboardEvent} event Keyboard event
		 */
		handleExpandKeydown(event) {
			if (this.isExpanded || this.isAnimating) return;

			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				this.expand();
			}
		}

		/**
		 * Handle close button click in expanded view
		 *
		 * @param {Event} event Click event
		 */
		handleCloseExpanded(event) {
			event.preventDefault();
			event.stopPropagation();
			this.collapse();
		}

		/**
		 * Handle clicks within expanded view using event delegation
		 * This is more robust than direct binding as it handles timing issues
		 *
		 * @param {Event} event Click event
		 */
		handleExpandedClick(event) {
			// Check if click was on "See all" button or its children
			const seeAllBtn = event.target.closest('.sbr-review-alert__expanded-see-all-btn');
			if (seeAllBtn) {
				this.handleSeeAllClick(event);
			}
		}

		/**
		 * Handle "See all X Reviews" button click in expanded view
		 * Renders remaining reviews and hides the button
		 *
		 * @param {Event} event Click event
		 */
		handleSeeAllClick(event) {
			event.preventDefault();
			event.stopPropagation();

			const reviewsContainer = this.popup.querySelector('.sbr-review-alert__expanded-reviews');
			const seeAllContainer = this.popup.querySelector('.sbr-review-alert__expanded-see-all');
			if (!reviewsContainer || !seeAllContainer) return;

			const reviewFeed = this.config.reviewFeed || {};

			// Get remaining reviews (after initial display)
			const remainingReviews = this.config.reviews.slice(this.initialExpandedReviewCount);

			// Render remaining reviews and insert before the "See all" container
			remainingReviews.forEach(review => {
				const reviewHTML = this.renderExpandedReview(review, reviewFeed);
				seeAllContainer.insertAdjacentHTML('beforebegin', reviewHTML);
			});

			// Hide the "See all" button container
			seeAllContainer.remove();
		}

		/**
		 * Handle scroll events for compact mode near footer
		 * Switches to compact layout when within threshold of page bottom
		 */
		handleScroll() {
			if (!this.popup || !this.isVisible || this.isExpanded) return;
			this.checkCompactMode();
		}

		/**
		 * Update text content for compact mode
		 * Compact: "Name" only (truncated)
		 * Normal: "Name left us a review"
		 *
		 * @param {boolean} isCompact Whether entering compact mode
		 */
		updateCompactModeText(isCompact) {
			// Only for recent reviews layout (not aggregate)
			if (this.config.popupType === 'aggregate') return;

			const nameElement = this.popup.querySelector('.sbr-review-alert__reviewer-name');
			if (!nameElement) return;

			const currentReview = this.config.reviews[this.currentReviewIndex];
			if (!currentReview || !currentReview.reviewer) return;

			const reviewerName = currentReview.reviewer.name || 'Someone';

			if (isCompact) {
				// Compact: Just truncated name (stars are shown inline via CSS)
				const truncatedName = reviewerName.length > 15
					? reviewerName.substring(0, 15).trim() + '...'
					: reviewerName;
				nameElement.textContent = truncatedName;
			} else {
				// Normal: Full text with localized template
				nameElement.textContent = this.formatReviewerHeading(reviewerName);
			}
		}

		/**
		 * Format reviewer heading text with localized template
		 *
		 * @param {string} reviewerName Reviewer display name
		 * @returns {string} Formatted heading text
		 */
		formatReviewerHeading(reviewerName) {
			const template = this.config.i18n?.reviewerHeadingTemplate || '%s left us a review';
			return template.replace(/%s/, reviewerName);
		}

		/**
		 * Show the popup
		 */
		show() {
			if (!this.popup || this.isDismissed) return;

			// Check compact mode BEFORE showing (so it loads with correct state)
			this.isVisible = true; // Set early so handleScroll doesn't bail out
			this.checkCompactMode();

			this.popup.classList.add('sbr-review-alert--visible');

			// Start review cycling if more than one review
			if (this.config.reviews.length > 1) {
				this.startCycling();
			}

			// Announce to screen readers
			this.popup.setAttribute('aria-live', 'polite');
		}

		/**
		 * Check and apply compact mode based on scroll position
		 * Called on initial show and on scroll events
		 */
		checkCompactMode() {
			if (!this.popup || this.isExpanded) return;

			// Skip compact mode for aggregate view when rating is hidden
			// (only "Total Reviews" link visible - already compact enough)
			if (this.config.popupType === 'aggregate' && !this.config.content.showRating) {
				return;
			}

			// Calculate distance from bottom of viewport to bottom of document
			const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
			const windowHeight = window.innerHeight;
			const documentHeight = Math.max(
				document.body.scrollHeight,
				document.documentElement.scrollHeight
			);

			const distanceFromBottom = documentHeight - (scrollTop + windowHeight);
			const shouldBeCompact = distanceFromBottom <= this.compactThreshold;

			if (shouldBeCompact !== this.isCompact) {
				this.isCompact = shouldBeCompact;

				if (this.isCompact) {
					this.popup.classList.add('sbr-review-alert--compact');
					this.updateCompactModeText(true);
				} else {
					this.popup.classList.remove('sbr-review-alert--compact');
					this.updateCompactModeText(false);
				}
			}
		}

		/**
		 * Hide the popup
		 */
		hide() {
			if (!this.popup) return;

			this.popup.classList.remove('sbr-review-alert--visible');
			this.isVisible = false;

			// Stop cycling
			this.stopCycling();
		}

		/**
		 * Dismiss the popup and remember in localStorage
		 */
		dismiss() {
			this.hide();
			this.saveDismissed();
			this.removeEventListeners();
			this.isDismissed = true;
		}

		/**
		 * Expand the popup to show all reviews
		 */
		expand() {
			if (!this.popup || this.isExpanded || this.isAnimating) return;

			this.isAnimating = true;
			this.isExpanded = true;

			// Remove compact mode when expanding (expanded view is always full)
			this.popup.classList.remove('sbr-review-alert--compact');
			this.isCompact = false; // Reset so checkCompactMode re-applies after collapse

			// Stop review cycling while expanded
			this.stopCycling();

			// Store the collapsed content for restoration
			const wrapper = this.popup.querySelector('.sbr-review-alert__wrapper');
			if (wrapper) {
				this.collapsedContent = wrapper.cloneNode(true);
			}

			// Add expanded state class
			this.popup.classList.add('sbr-review-alert--expanded-state');
			this.popup.setAttribute('aria-expanded', 'true');
			this.popup.removeAttribute('role');
			this.popup.removeAttribute('tabindex');

			// Replace content with expanded view
			this.renderExpandedContent();

			// End animation state (track timeout for cleanup)
			const animTimeout = setTimeout(() => {
				this.isAnimating = false;
			}, 300);
			this.animationTimeouts.push(animTimeout);
		}

		/**
		 * Collapse back to single review view
		 */
		collapse() {
			if (!this.popup || !this.isExpanded || this.isAnimating) return;

			this.isAnimating = true;

			// Add collapsing animation class
			this.popup.classList.add('sbr-review-alert--collapsing');

			// Wait for animation, then switch content (track timeout for cleanup)
			const collapseTimeout = setTimeout(() => {
				this.isExpanded = false;
				this.popup.classList.remove('sbr-review-alert--expanded-state');
				this.popup.classList.remove('sbr-review-alert--collapsing');
				this.popup.setAttribute('aria-expanded', 'false');
				this.popup.setAttribute('role', 'button');
				this.popup.setAttribute('tabindex', '0');

				// Restore collapsed content
				if (this.collapsedContent) {
					const expandedContent = this.popup.querySelector('.sbr-review-alert__expanded');
					const closeRow = this.popup.querySelector('.sbr-review-alert__close-row');
					if (expandedContent) {
						expandedContent.remove();
					}
					if (closeRow) {
						closeRow.remove();
					}
					this.popup.appendChild(this.collapsedContent.cloneNode(true));

					// Re-attach click listener to new inner element
					const inner = this.popup.querySelector('.sbr-review-alert__inner');
					if (inner) {
						inner.addEventListener('click', this.handlePopupClick);
					}

					// Re-attach close button listener (cloneNode doesn't copy event listeners)
					const closeBtn = this.popup.querySelector('.sbr-review-alert__close');
					if (closeBtn) {
						closeBtn.addEventListener('click', this.handleClose);
					}
				}

				// Restart cycling
				if (this.config.reviews.length > 1) {
					this.startCycling();
				}

				this.isAnimating = false;

				// Re-check compact mode after collapse
				this.handleScroll();
			}, 300);
			this.animationTimeouts.push(collapseTimeout);
		}

		/**
		 * Render expanded content with all reviews
		 */
		renderExpandedContent() {
			// Remove existing wrapper
			const wrapper = this.popup.querySelector('.sbr-review-alert__wrapper');
			if (wrapper) {
				wrapper.remove();
			}

			const reviewFeed = this.config.reviewFeed || {};
			const showPoweredBy = reviewFeed.showPoweredBy !== false;
			const showHeading = reviewFeed.showHeading !== false;
			const showButton = reviewFeed.showButton !== false;
			const headingText = reviewFeed.headingText || 'See what our Customers say about Smash Balloon feeds!';
			const buttonText = reviewFeed.buttonText || 'Get Smash Balloon Feed Pro';
			const buttonUrl = reviewFeed.buttonUrl || '#';
			const buttonIcon = reviewFeed.buttonIcon || null;
			const totalReviews = this.config.totalReviews || this.config.reviews.length;

			// Build header section
			const hasHeader = showHeading || showButton;
			const headerClass = showButton ? 'sbr-review-alert__expanded-header--with-cta' : 'sbr-review-alert__expanded-header--no-cta';

			let headerHTML = '';
			if (hasHeader) {
				headerHTML = `
					<div class="sbr-review-alert__expanded-header ${headerClass}">
						${showHeading ? `
							<div class="sbr-review-alert__expanded-heading ${showButton ? 'sbr-review-alert__expanded-heading--with-cta' : ''}">
								${this.escapeHTML(headingText)}
							</div>
						` : ''}
						${showButton ? `
							<a href="${this.escapeHTML(buttonUrl)}" class="sbr-review-alert__expanded-cta ${buttonIcon ? 'sbr-review-alert__expanded-cta--has-icon' : ''}" target="_blank" rel="noopener noreferrer">
								${this.renderButtonIcon(buttonIcon)}
								<span>${this.escapeHTML(buttonText)}</span>
							</a>
						` : ''}
					</div>
				`;
			}

			// Show only first N reviews initially, rest loaded on "See all" click
			const initialReviews = this.config.reviews.slice(0, this.initialExpandedReviewCount);
			const hasMoreReviews = this.config.reviews.length > this.initialExpandedReviewCount;
			const remainingCount = totalReviews - this.initialExpandedReviewCount;

			// Build expanded HTML
			const reviewsClass = hasHeader ? 'sbr-review-alert__expanded-reviews' : 'sbr-review-alert__expanded-reviews sbr-review-alert__expanded-reviews--no-header';
			const expandedHTML = `
				<div class="sbr-review-alert__expanded">
					<div class="sbr-review-alert__expanded-card">
						${headerHTML}
						<div class="${reviewsClass}">
							${initialReviews.map(review => this.renderExpandedReview(review, reviewFeed)).join('')}
							${hasMoreReviews ? `
								<div class="sbr-review-alert__expanded-see-all">
									<button type="button" class="sbr-review-alert__expanded-see-all-btn">
										<span>See all ${totalReviews} Reviews</span>
										<span class="sbr-review-alert__chevron">
											<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
												<polyline points="9 18 15 12 9 6"></polyline>
											</svg>
										</span>
									</button>
								</div>
							` : ''}
							${showPoweredBy ? this.renderExpandedPoweredBy() : ''}
						</div>
					</div>
					<div class="sbr-review-alert__close-row">
						<button class="sbr-review-alert__close-btn" aria-label="Close">
							<span class="sbr-review-alert__close-icon">
								<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
								</svg>
							</span>
						</button>
					</div>
				</div>
			`;

			this.popup.insertAdjacentHTML('beforeend', expandedHTML);

			// Attach close button listener
			const closeBtn = this.popup.querySelector('.sbr-review-alert__close-btn');
			if (closeBtn) {
				closeBtn.addEventListener('click', this.handleCloseExpanded);
			}

			// Use event delegation on expanded content for "See all" button
			// This is more robust than direct binding and handles timing edge cases
			const expandedContent = this.popup.querySelector('.sbr-review-alert__expanded');
			if (expandedContent) {
				expandedContent.addEventListener('click', this.handleExpandedClick);
			}
		}

		/**
		 * Render button icon for expanded popup CTA
		 *
		 * @param {string|null} iconId Icon identifier
		 * @returns {string} HTML string
		 */
		renderButtonIcon(iconId) {
			if (!iconId) return '';

			const icons = {
				'arrow-right': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="sbr-review-alert__expanded-cta-icon"><path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69L8.22 4.03a.75.75 0 0 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 0 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd" /></svg>',
				'external-link': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="sbr-review-alert__expanded-cta-icon"><path d="M6.22 8.72a.75.75 0 0 0 1.06 1.06l5.22-5.22v1.69a.75.75 0 0 0 1.5 0v-3.5a.75.75 0 0 0-.75-.75h-3.5a.75.75 0 0 0 0 1.5h1.69L6.22 8.72Z" /><path d="M3.5 6.75c0-.69.56-1.25 1.25-1.25H7A.75.75 0 0 0 7 4H4.75A2.75 2.75 0 0 0 2 6.75v4.5A2.75 2.75 0 0 0 4.75 14h4.5A2.75 2.75 0 0 0 12 11.25V9a.75.75 0 0 0-1.5 0v2.25c0 .69-.56 1.25-1.25 1.25h-4.5c-.69 0-1.25-.56-1.25-1.25v-4.5Z" /></svg>',
				'chevron-right': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="sbr-review-alert__expanded-cta-icon"><path fill-rule="evenodd" d="M6.22 4.22a.75.75 0 0 1 1.06 0l3.25 3.25a.75.75 0 0 1 0 1.06l-3.25 3.25a.75.75 0 0 1-1.06-1.06L8.94 8 6.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>',
				'star': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="sbr-review-alert__expanded-cta-icon"><path fill-rule="evenodd" d="M8 1.75a.75.75 0 0 1 .692.462l1.41 3.393 3.664.293a.75.75 0 0 1 .428 1.317l-2.791 2.39.853 3.575a.75.75 0 0 1-1.12.814L7.998 12.08l-3.135 1.915a.75.75 0 0 1-1.12-.814l.852-3.574-2.79-2.39a.75.75 0 0 1 .427-1.318l3.663-.293 1.41-3.393A.75.75 0 0 1 8 1.75Z" clip-rule="evenodd" /></svg>',
				'heart': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" class="sbr-review-alert__expanded-cta-icon"><path d="M2 6.342a3.375 3.375 0 0 1 6-2.088 3.375 3.375 0 0 1 5.997 2.26c-.063 2.134-1.618 3.76-2.955 4.784a14.437 14.437 0 0 1-2.676 1.61c-.02.01-.038.017-.053.022l-.018.007-.004.002h-.002a.75.75 0 0 1-.592 0h-.001l-.005-.002-.018-.007a5.386 5.386 0 0 1-.053-.022 14.437 14.437 0 0 1-2.676-1.61C3.618 10.102 2.063 8.476 2 6.342Z" /></svg>'
			};

			return icons[iconId] || '';
		}

		/**
		 * Render a single review in expanded view
		 *
		 * @param {Object} review Review data
		 * @param {Object} settings ReviewFeed visibility settings
		 * @returns {string} HTML string
		 */
		renderExpandedReview(review, settings) {
			const showStars = settings.showStars !== false;
			const showTitle = settings.showTitle !== false;
			const showText = settings.showText !== false;
			const showAuthor = settings.showAuthor !== false;
			const showDate = settings.showDate !== false;

			const rating = review.rating || 5;
			const starsHTML = showStars ? `
				<div class="sbr-review-alert__expanded-stars">
					${this.renderStarsHTML(rating)}
				</div>
			` : '';

			const title = review.text ? (review.text.length > 50 ? review.text.substring(0, 50) + '...' : review.text) : '';
			const titleHTML = showTitle && title ? `<div class="sbr-review-alert__expanded-review-title">${this.escapeHTML(title)}</div>` : '';
			const textHTML = showText ? `<p class="sbr-review-alert__expanded-review-text">${this.escapeHTML(review.text || '')}</p>` : '';

			// Build meta section only if author or date is shown
			let metaHTML = '';
			if (showAuthor || showDate) {
				const authorHTML = showAuthor ? `<span class="sbr-review-alert__expanded-review-author">${this.escapeHTML(review.reviewer?.name || 'Anonymous')}</span>` : '';
				const dateHTML = showDate ? `<span class="sbr-review-alert__expanded-review-date">${this.escapeHTML(this.formatDate(review.time))}</span>` : '';
				metaHTML = `<div class="sbr-review-alert__expanded-review-meta">${authorHTML}${dateHTML}</div>`;
			}

			return `
				<div class="sbr-review-alert__expanded-review">
					${starsHTML}
					${titleHTML}
					${textHTML}
					${metaHTML}
				</div>
			`;
		}

		/**
		 * Render stars HTML
		 *
		 * @param {number} rating Rating 1-5
		 * @returns {string} HTML string
		 */
		renderStarsHTML(rating) {
			let html = '';
			for (let i = 1; i <= 5; i++) {
				const isEmpty = i > rating;
				// Use same SVG as admin (popup-star.svg) for consistent appearance
				html += `
					<span class="sbr-review-alert__expanded-star ${isEmpty ? 'sbr-review-alert__expanded-star--empty' : ''}">
						<svg viewBox="0 0 24 24" fill="currentColor">
							<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
						</svg>
					</span>
				`;
			}
			return html;
		}

		/**
		 * Render powered by section for expanded view
		 *
		 * @returns {string} HTML string
		 */
		renderExpandedPoweredBy() {
			return `
				<div class="sbr-review-alert__expanded-powered-wrapper">
					<a href="https://smashballoon.com/rf/notification-popup/powered-by?utm_source=reviews-feed-free&utm_medium=reviews-plugin-notification-popup&utm_campaign=powered-by&utm_content=powered-by-link" target="_blank" rel="noopener noreferrer nofollow" class="sbr-review-alert__expanded-powered-pill">
						<span class="sbr-review-alert__expanded-powered-text">Powered by</span>
						<span class="sbr-review-alert__expanded-powered-logo">
							<svg viewBox="0 0 84 18" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20.8937 5.66226C20.0529 5.03706 19.2553 4.95083 18.7594 4.95083C18.0911 4.95083 17.4875 5.09096 16.9808 5.60836C16.5497 6.05031 16.3556 6.58928 16.3556 7.22526C16.3556 7.57019 16.4095 8.07682 16.7868 8.47565C17.0671 8.77747 17.4551 8.92838 17.7893 9.04695L18.3821 9.25176C18.587 9.32722 19.0289 9.48891 19.2229 9.65059C19.3738 9.77995 19.4709 9.93086 19.4709 10.168C19.4709 10.4375 19.3523 10.6315 19.2122 10.7501C18.975 10.9549 18.6732 10.998 18.4576 10.998C18.1234 10.998 17.8324 10.9118 17.5521 10.7393C17.3581 10.6207 17.0671 10.3728 16.8838 10.1896L16.0215 11.3753C16.291 11.6448 16.7114 11.9789 17.0779 12.1622C17.5306 12.3885 17.9833 12.464 18.5007 12.464C18.975 12.464 19.902 12.3993 20.538 11.731C20.9153 11.3429 21.174 10.6962 21.174 9.95241C21.174 9.53202 21.0662 9.01462 20.6458 8.61578C20.3655 8.3463 19.9775 8.18461 19.6649 8.06604L19.1259 7.86123C18.6409 7.67799 18.3929 7.61331 18.2205 7.45162C18.1127 7.35461 18.0588 7.22525 18.0588 7.05279C18.0588 6.86954 18.1342 6.70785 18.242 6.60006C18.436 6.38447 18.7163 6.35213 18.9427 6.35213C19.1475 6.35213 19.611 6.38447 20.15 6.86954L20.8937 5.66226Z" fill="currentColor"/>
								<path d="M21.8618 12.3023H23.414V9.84462C23.414 9.68293 23.4247 9.14397 23.7158 8.86371C23.8667 8.72358 24.0499 8.66968 24.2548 8.66968C24.4164 8.66968 24.6105 8.70202 24.7722 8.86371C25.0093 9.10085 25.0201 9.47813 25.0201 9.8015V12.3023H26.5723V9.9093C26.5723 9.5967 26.6046 9.21942 26.8094 8.96072C26.9388 8.78825 27.1544 8.66968 27.4131 8.66968C27.6395 8.66968 27.8443 8.76669 27.9736 8.92838C28.1784 9.18709 28.1784 9.61826 28.1784 9.86618V12.3023H29.7306V9.26254C29.7306 8.9176 29.6983 8.27085 29.2348 7.8289C28.9437 7.54863 28.4695 7.37616 27.8874 7.37616C27.5101 7.37616 27.1975 7.44084 26.8633 7.64565C26.5507 7.83967 26.3675 8.06604 26.2489 8.26007C26.1196 7.96903 25.9148 7.73188 25.6668 7.59175C25.3435 7.39772 24.9662 7.37616 24.7614 7.37616C24.3949 7.37616 23.8128 7.45162 23.414 8.05526V7.49474H21.8618V12.3023Z" fill="currentColor"/>
								<path d="M34.3094 8.0337C33.8674 7.43006 33.2099 7.33305 32.8003 7.33305C32.132 7.33305 31.5499 7.57019 31.1295 7.99058C30.6875 8.43253 30.3857 9.12241 30.3857 9.93086C30.3857 10.5668 30.5798 11.192 31.0864 11.731C31.6146 12.2915 32.1966 12.464 32.8865 12.464C33.2854 12.464 33.889 12.367 34.3094 11.7202V12.3023H35.8616V7.49474H34.3094V8.0337ZM33.1883 8.66968C33.4686 8.66968 33.8135 8.77747 34.0507 9.00384C34.277 9.21942 34.4064 9.5428 34.4064 9.88774C34.4064 10.2974 34.2339 10.5992 34.0291 10.7932C33.8243 10.998 33.5333 11.1274 33.2207 11.1274C32.8542 11.1274 32.52 10.9764 32.3044 10.7501C32.1643 10.5992 31.9811 10.3189 31.9811 9.88774C31.9811 9.45657 32.1751 9.17631 32.3368 9.01462C32.5416 8.80981 32.8542 8.66968 33.1883 8.66968Z" fill="currentColor"/>
								<path d="M40.299 7.74266C39.9109 7.5163 39.4151 7.33305 38.7036 7.33305C38.2617 7.33305 37.6796 7.41928 37.2484 7.81812C36.9682 8.07682 36.7849 8.46487 36.7849 8.92838C36.7849 9.29488 36.9035 9.56436 37.1299 9.8015C37.3347 10.0063 37.6257 10.1572 37.906 10.2435L38.294 10.362C38.5204 10.4267 38.6605 10.4698 38.7683 10.5345C38.9084 10.6207 38.9408 10.7285 38.9408 10.8148C38.9408 10.9333 38.8761 11.0519 38.7791 11.1274C38.639 11.2352 38.391 11.2351 38.294 11.2351C38.0892 11.2351 37.8628 11.192 37.6473 11.0842C37.4856 11.0088 37.27 10.8579 37.1191 10.7285L36.4615 11.7741C37.0867 12.3239 37.7874 12.464 38.4342 12.464C38.9408 12.464 39.5229 12.3885 39.9971 11.9142C40.2127 11.6987 40.493 11.289 40.493 10.6531C40.493 10.2866 40.396 9.99553 40.1157 9.73683C39.8678 9.51046 39.5875 9.40267 39.3181 9.31644L38.9084 9.18708C38.7144 9.12241 38.5527 9.09007 38.4449 9.0254C38.3695 8.98228 38.294 8.9176 38.294 8.80981C38.294 8.73435 38.3371 8.64812 38.391 8.59422C38.488 8.49721 38.6713 8.45409 38.833 8.45409C39.1348 8.45409 39.4474 8.58345 39.6845 8.72358L40.299 7.74266Z" fill="currentColor"/>
								<path d="M41.1354 12.3023H42.6876V9.89852C42.6876 9.66137 42.6984 9.19787 43.011 8.89604C43.0972 8.80981 43.2912 8.6589 43.6254 8.6589C43.8518 8.6589 44.0889 8.73435 44.229 8.87449C44.477 9.12241 44.4877 9.52124 44.4877 9.83384V12.3023H46.0399V9.25176C46.0399 8.87449 46.0076 8.30318 45.5657 7.85045C45.1453 7.41928 44.5847 7.36539 44.2075 7.36539C43.8625 7.36539 43.593 7.39772 43.2697 7.57019C43.0864 7.66721 42.8816 7.8289 42.6876 8.06604V4.4442H41.1354V12.3023Z" fill="currentColor"/>
								<path d="M49.3027 5.11251V12.3023H52.0083C52.4503 12.3023 53.3126 12.2592 53.9055 11.6879C54.1857 11.4076 54.466 10.9549 54.466 10.2327C54.466 9.5967 54.2396 9.18709 54.0025 8.94994C53.7438 8.69124 53.3557 8.51877 53.0108 8.46487C53.1832 8.38942 53.4312 8.23851 53.6144 7.93669C53.8084 7.62409 53.8516 7.30071 53.8516 7.02045C53.8516 6.70785 53.7977 6.10421 53.3342 5.66226C52.7736 5.13407 51.8897 5.11251 51.5017 5.11251H49.3027ZM50.9412 6.40603H51.1999C51.5017 6.40603 51.8251 6.40603 52.0622 6.58928C52.1916 6.68629 52.3425 6.88032 52.3425 7.19292C52.3425 7.50552 52.2023 7.7211 52.0514 7.8289C51.8143 8.00136 51.437 8.0337 51.2106 8.0337H50.9412V6.40603ZM50.9412 9.26254H51.3939C51.728 9.26254 52.2886 9.26254 52.5796 9.53202C52.6874 9.62904 52.806 9.82306 52.806 10.1141C52.806 10.3728 52.7197 10.5776 52.5688 10.7177C52.267 10.998 51.7604 11.0088 51.34 11.0088H50.9412V9.26254Z" fill="currentColor"/>
								<path d="M58.8828 8.0337C58.4408 7.43006 57.7833 7.33305 57.3737 7.33305C56.7054 7.33305 56.1233 7.57019 55.7029 7.99058C55.261 8.43253 54.9591 9.12241 54.9591 9.93086C54.9591 10.5668 55.1532 11.192 55.6598 11.731C56.188 12.2915 56.7701 12.464 57.4599 12.464C57.8588 12.464 58.4624 12.367 58.8828 11.7202V12.3023H60.435V7.49474H58.8828V8.0337ZM57.7617 8.66968C58.042 8.66968 58.3869 8.77747 58.6241 9.00384C58.8505 9.21942 58.9798 9.5428 58.9798 9.88774C58.9798 10.2974 58.8073 10.5992 58.6025 10.7932C58.3977 10.998 58.1067 11.1274 57.7941 11.1274C57.4276 11.1274 57.0934 10.9764 56.8778 10.7501C56.7377 10.5992 56.5545 10.3189 56.5545 9.88774C56.5545 9.45657 56.7485 9.17631 56.9102 9.01462C57.115 8.80981 57.4276 8.66968 57.7617 8.66968Z" fill="currentColor"/>
								<path d="M61.3583 4.4442V12.3023H62.9105V4.4442H61.3583Z" fill="currentColor"/>
								<path d="M63.8771 4.4442V12.3023H65.4294V4.4442H63.8771Z" fill="currentColor"/>
								<path d="M71.764 9.89852C71.764 9.2841 71.5269 8.59422 71.0418 8.10916C70.6107 7.67798 69.8777 7.33305 68.9399 7.33305C68.0021 7.33305 67.2691 7.67798 66.8379 8.10916C66.3528 8.59422 66.1157 9.2841 66.1157 9.89852C66.1157 10.5129 66.3528 11.2028 66.8379 11.6879C67.2691 12.1191 68.0021 12.464 68.9399 12.464C69.8777 12.464 70.6107 12.1191 71.0418 11.6879C71.5269 11.2028 71.764 10.5129 71.764 9.89852ZM68.9399 8.64812C69.2956 8.64812 69.5866 8.76669 69.813 8.99306C70.0394 9.21942 70.1687 9.51046 70.1687 9.89852C70.1687 10.2866 70.0394 10.5776 69.813 10.804C69.5866 11.0303 69.2956 11.1489 68.9506 11.1489C68.541 11.1489 68.2608 10.998 68.0668 10.804C67.8835 10.6207 67.711 10.3405 67.711 9.89852C67.711 9.51046 67.8404 9.21942 68.0668 8.99306C68.2931 8.76669 68.5842 8.64812 68.9399 8.64812Z" fill="currentColor"/>
								<path d="M77.8198 9.89852C77.8198 9.2841 77.5827 8.59422 77.0976 8.10916C76.6664 7.67798 75.9334 7.33305 74.9956 7.33305C74.0578 7.33305 73.3248 7.67798 72.8937 8.10916C72.4086 8.59422 72.1715 9.2841 72.1715 9.89852C72.1715 10.5129 72.4086 11.2028 72.8937 11.6879C73.3248 12.1191 74.0578 12.464 74.9956 12.464C75.9334 12.464 76.6664 12.1191 77.0976 11.6879C77.5827 11.2028 77.8198 10.5129 77.8198 9.89852ZM74.9956 8.64812C75.3513 8.64812 75.6424 8.76669 75.8688 8.99306C76.0951 9.21942 76.2245 9.51046 76.2245 9.89852C76.2245 10.2866 76.0951 10.5776 75.8688 10.804C75.6424 11.0303 75.3514 11.1489 75.0064 11.1489C74.5968 11.1489 74.3165 10.998 74.1225 10.804C73.9393 10.6207 73.7668 10.3405 73.7668 9.89852C73.7668 9.51046 73.8961 9.21942 74.1225 8.99306C74.3489 8.76669 74.6399 8.64812 74.9956 8.64812Z" fill="currentColor"/>
								<path d="M78.4644 12.3023H80.0166V9.83384C80.0166 9.52124 80.0597 9.17631 80.3184 8.9176C80.437 8.78825 80.6418 8.6589 80.9652 8.6589C81.2454 8.6589 81.4395 8.75591 81.558 8.87449C81.806 9.12241 81.8167 9.52124 81.8167 9.83384V12.3023H83.369V9.26254C83.369 8.87449 83.3366 8.30318 82.8839 7.85045C82.4743 7.44084 81.9245 7.36539 81.5041 7.36539C81.0514 7.36539 80.4909 7.4624 80.0166 8.06604V7.49474H78.4644V12.3023Z" fill="currentColor"/>
								<path fill-rule="evenodd" clip-rule="evenodd" d="M12.5028 8.07516C12.5028 3.83303 9.7222 0.394081 6.29088 0.394081C2.85955 0.394081 0.0776367 3.83303 0.0776367 8.07516C0.0776367 12.1419 2.62571 15.4601 5.85243 15.7385L5.5093 16.825L7.66086 16.642L6.90597 15.7194C10.0475 15.3381 12.5028 12.0619 12.5028 8.07516Z" fill="#FE544F"/>
								<path fill-rule="evenodd" clip-rule="evenodd" d="M7.77227 3.0945L8.07123 6.17923L11.1689 6.26821L8.9281 8.3517L10.6979 10.9115L7.71637 10.3511L6.81247 13.3327L5.44003 10.6627L2.66888 11.9324L3.7349 9.07008L1.03223 7.70689L3.92536 6.77644L3.12703 3.92636L5.86414 5.48681L7.77227 3.0945Z" fill="white"/>
							</svg>
						</span>
					</a>
				</div>
			`;
		}

		/**
		 * Escape HTML entities
		 *
		 * @param {string} str String to escape
		 * @returns {string} Escaped string
		 */
		escapeHTML(str) {
			if (!str) return '';
			const div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}

		/**
		 * Cache for fetched provider SVG icons
		 * @type {Object.<string, string>}
		 */
		static providerIconCache = {};

		/**
		 * Fetch provider icon SVG content
		 *
		 * Fetches the SVG file and caches it for reuse.
		 * Uses inline SVG for better CSS control (color can be changed via CSS).
		 *
		 * @param {string} providerName Provider name (e.g., 'google', 'yelp', 'edd')
		 * @returns {Promise<string>} SVG content or empty string on error
		 */
		async fetchProviderIcon(providerName) {
			if (!providerName) return '';

			// Check cache first
			if (SBRReviewAlert.providerIconCache[providerName]) {
				return SBRReviewAlert.providerIconCache[providerName];
			}

			const iconUrl = this.config.pluginUrl + 'assets/icons/' + providerName + '-provider.svg';

			try {
				const response = await fetch(iconUrl);
				if (!response.ok) {
					console.warn('SBR Review Alert: Failed to fetch provider icon:', providerName);
					return '';
				}
				const svgContent = await response.text();
				// Cache the result
				SBRReviewAlert.providerIconCache[providerName] = svgContent;
				return svgContent;
			} catch (error) {
				console.warn('SBR Review Alert: Error fetching provider icon:', error);
				return '';
			}
		}

		/**
		 * Format a date/timestamp for display as relative time
		 *
		 * @param {string|number} time Unix timestamp or date string
		 * @returns {string} Formatted relative date string (e.g., "3d ago", "1w ago")
		 */
		formatDate(time) {
			if (!time) return '';

			// If it's a numeric timestamp, convert to relative date
			if (!isNaN(time) && !isNaN(parseInt(time))) {
				const timestamp = parseInt(time);
				// Unix timestamps are in seconds, JS uses milliseconds
				const date = new Date(timestamp * 1000);
				const now = new Date();
				const diffMs = now - date;
				const diffSecs = Math.floor(diffMs / 1000);
				const diffMins = Math.floor(diffSecs / 60);
				const diffHours = Math.floor(diffMins / 60);
				const diffDays = Math.floor(diffHours / 24);
				const diffWeeks = Math.floor(diffDays / 7);
				const diffMonths = Math.floor(diffDays / 30);
				const diffYears = Math.floor(diffDays / 365);

				if (diffYears > 0) {
					return diffYears === 1 ? '1y ago' : `${diffYears}y ago`;
				}
				if (diffMonths > 0) {
					return diffMonths === 1 ? '1mo ago' : `${diffMonths}mo ago`;
				}
				if (diffWeeks > 0) {
					return diffWeeks === 1 ? '1w ago' : `${diffWeeks}w ago`;
				}
				if (diffDays > 0) {
					return diffDays === 1 ? '1d ago' : `${diffDays}d ago`;
				}
				if (diffHours > 0) {
					return diffHours === 1 ? '1h ago' : `${diffHours}h ago`;
				}
				if (diffMins > 0) {
					return diffMins === 1 ? '1m ago' : `${diffMins}m ago`;
				}
				return 'Just now';
			}

			// If already a string, return as-is
			return String(time);
		}

		/**
		 * Start cycling through reviews
		 * Only cycles for 'recent' popup type with multiple reviews
		 *
		 * Timing mode controls how reviews cycle:
		 * - 'fixed': Reviews cycle at the fixed interval (cycleIntervalMax)
		 * - 'random': Reviews cycle at random intervals between min and max
		 */
		startCycling() {
			// Don't cycle for aggregate type - it shows summary only
			if (this.config.popupType === 'aggregate') {
				return;
			}

			// Don't cycle if only one review
			if (this.config.reviews.length <= 1) {
				return;
			}

			// Clear any existing cycle
			this.stopCycling();

			// Use setTimeout instead of setInterval for random mode support
			const scheduleNextCycle = () => {
				const interval = this.calculateCycleInterval();
				this.cycleInterval = setTimeout(() => {
					this.cycleReview();
					scheduleNextCycle(); // Schedule next cycle with potentially different interval
				}, interval);
			};

			scheduleNextCycle();
		}

		/**
		 * Stop cycling through reviews
		 */
		stopCycling() {
			if (this.cycleInterval) {
				clearTimeout(this.cycleInterval);
				this.cycleInterval = null;
			}
		}

		/**
		 * Cycle to the next review
		 */
		cycleReview() {
			if (!this.popup || this.config.reviews.length <= 1) return;

			const reviewElement = this.popup.querySelector('.sbr-review-alert__review');
			if (!reviewElement) return;

			// Add cycling animation class
			reviewElement.classList.add('sbr-review-alert__review--cycling');

			// Wait for animation midpoint to update content (track timeout for cleanup)
			const midpointTimeout = setTimeout(() => {
				// Move to next review
				this.currentReviewIndex = (this.currentReviewIndex + 1) % this.config.reviews.length;

				// Update review content
				this.updateReviewContent(reviewElement);
			}, 250); // Half of the 0.5s animation
			this.cycleTimeouts.push(midpointTimeout);

			// Remove animation class after animation completes (track timeout for cleanup)
			const completeTimeout = setTimeout(() => {
				reviewElement.classList.remove('sbr-review-alert__review--cycling');
			}, 500);
			this.cycleTimeouts.push(completeTimeout);
		}

		/**
		 * Update review content in the DOM
		 *
		 * @param {HTMLElement} reviewElement Review container element
		 */
		updateReviewContent(reviewElement) {
			const review = this.config.reviews[this.currentReviewIndex];
			if (!review) return;

			// Update aria-label for screen reader announcement
			if (this.popup && review.reviewer && review.reviewer.name) {
				const rating = review.rating || 5;
				const ariaLabel = `Review from ${review.reviewer.name}, ${rating} out of 5 stars`;
				this.popup.setAttribute('aria-label', ariaLabel);
			}

			// Update avatar
			if (this.config.content.showAvatar) {
				const avatar = reviewElement.querySelector('.sbr-review-alert__avatar');
				if (avatar) {
					if (review.reviewer && review.reviewer.avatar) {
						avatar.src = review.reviewer.avatar;
						avatar.alt = review.reviewer.name || 'Reviewer';
					} else {
						// Use placeholder when no avatar available to prevent stale data
						avatar.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56"><rect fill="%23e4e4e7" width="56" height="56"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="%2371717a">?</text></svg>';
						avatar.alt = review.reviewer?.name || 'Reviewer';
					}
				}
			}

			// Update review text
			const textElement = reviewElement.querySelector('.sbr-review-alert__review-text');
			if (textElement) {
				textElement.textContent = this.truncateText(review.text, 80);
			}

			// Update reviewer name (respect compact mode)
			if (this.config.content.showReviewerName) {
				const nameElement = reviewElement.querySelector('.sbr-review-alert__reviewer-name');
				if (nameElement && review.reviewer) {
					const reviewerName = review.reviewer.name || 'Someone';
					if (this.isCompact) {
						// Compact: Truncated name only
						const truncatedName = reviewerName.length > 15
							? reviewerName.substring(0, 15).trim() + '...'
							: reviewerName;
						nameElement.textContent = truncatedName;
					} else {
						// Normal: Full text with localized template
						nameElement.textContent = this.formatReviewerHeading(reviewerName);
					}
				}
			}

			// Update date
			if (this.config.content.showDate) {
				const dateElement = reviewElement.querySelector('.sbr-review-alert__date');
				if (dateElement && review.time) {
					dateElement.textContent = this.formatDate(review.time);
				}
			}

			// Update star ratings
			if (this.config.content.showRating) {
				const starsContainer = reviewElement.querySelector('.sbr-review-alert__stars');
				if (starsContainer) {
					const stars = starsContainer.querySelectorAll('.sbr-review-alert__star');
					const rating = review.rating || 5;
					stars.forEach((star, index) => {
						if (index < rating) {
							star.classList.remove('sbr-review-alert__star--empty');
						} else {
							star.classList.add('sbr-review-alert__star--empty');
						}
					});
				}
			}

			// Update provider badge (using inline SVG to match admin preview)
			if (this.config.content.showPlatform) {
				const providerBadge = reviewElement.querySelector('.sbr-review-alert__provider-badge');
				if (providerBadge && review.provider) {
					const providerName = typeof review.provider === 'object' ? review.provider.name : review.provider;
					if (providerName) {
						// Update data-provider attribute for CSS styling (e.g., EDD white background)
						providerBadge.setAttribute('data-provider', providerName);
						// Fetch and update inline SVG
						this.fetchProviderIcon(providerName).then(svgContent => {
							if (svgContent) {
								providerBadge.innerHTML = svgContent;
							}
						});
					}
				}
			}
		}

		/**
		 * Truncate text to specified length
		 *
		 * @param {string} text Text to truncate
		 * @param {number} maxLength Maximum length
		 * @returns {string} Truncated text
		 */
		truncateText(text, maxLength) {
			if (!text || text.length <= maxLength) return text;
			return text.substring(0, maxLength).trim() + '...';
		}

		/**
		 * Check if popup has been dismissed (session-only)
		 *
		 * Uses sessionStorage so dismissal only persists for current browser session.
		 * When user closes the tab/window, the popup will appear again on next visit.
		 *
		 * @returns {boolean} True if dismissed
		 */
		checkDismissed() {
			try {
				const dismissedData = sessionStorage.getItem('sbr_review_alert_dismissed');
				if (!dismissedData) return false;

				const data = JSON.parse(dismissedData);
				const popupKey = 'popup_' + this.config.popupId;

				return !!data[popupKey];
			} catch (e) {
				// sessionStorage not available or error parsing
				return false;
			}
		}

		/**
		 * Save dismissed state to sessionStorage (session-only)
		 *
		 * Dismissal persists only for current browser session.
		 */
		saveDismissed() {
			try {
				let data = {};
				const existing = sessionStorage.getItem('sbr_review_alert_dismissed');

				if (existing) {
					data = JSON.parse(existing);
				}

				const popupKey = 'popup_' + this.config.popupId;
				data[popupKey] = true;

				sessionStorage.setItem('sbr_review_alert_dismissed', JSON.stringify(data));
			} catch (e) {
				// sessionStorage not available, fail silently
				console.warn('SBR Review Alert: Unable to save dismissed state');
			}
		}

		/**
		 * Clear dismissed state (useful for testing)
		 */
		clearDismissed() {
			try {
				const existing = sessionStorage.getItem('sbr_review_alert_dismissed');
				if (existing) {
					const data = JSON.parse(existing);
					const popupKey = 'popup_' + this.config.popupId;
					delete data[popupKey];
					sessionStorage.setItem('sbr_review_alert_dismissed', JSON.stringify(data));
				}
				this.isDismissed = false;
			} catch (e) {
				// Fail silently
			}
		}

		/**
		 * Destroy the popup instance
		 * Properly cleans up all resources to prevent memory leaks
		 */
		destroy() {
			// Stop any cycling intervals first
			this.stopCycling();

			// Clear initial delay timeout
			if (this.initialDelayTimeout) {
				clearTimeout(this.initialDelayTimeout);
				this.initialDelayTimeout = null;
			}

			// Clear all animation timeouts
			if (this.animationTimeouts && this.animationTimeouts.length > 0) {
				this.animationTimeouts.forEach(timeout => clearTimeout(timeout));
				this.animationTimeouts = [];
			}

			// Clear all cycling timeouts
			if (this.cycleTimeouts && this.cycleTimeouts.length > 0) {
				this.cycleTimeouts.forEach(timeout => clearTimeout(timeout));
				this.cycleTimeouts = [];
			}

			// Hide the popup
			this.hide();

			// Remove all event listeners
			this.removeEventListeners();

			// Clear stored content
			this.collapsedContent = null;

			// Clear reference to DOM element
			this.popup = null;

			// Reset state
			this.isVisible = false;
			this.isExpanded = false;
			this.isAnimating = false;
			this.currentReviewIndex = 0;
		}
	}

	/**
	 * Initialize when DOM is ready
	 */
	function initSBRReviewAlert() {
		// Check if config is available
		if (typeof window.sbrReviewAlertConfig === 'undefined') {
			return;
		}

		// Create popup instance
		window.sbrReviewAlertInstance = new SBRReviewAlert(
			window.sbrReviewAlertConfig
		);
	}

	// Initialize on DOMContentLoaded
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initSBRReviewAlert);
	} else {
		// DOM already ready
		initSBRReviewAlert();
	}

	// Expose class globally for external use
	window.SBRReviewAlert = SBRReviewAlert;

})();
