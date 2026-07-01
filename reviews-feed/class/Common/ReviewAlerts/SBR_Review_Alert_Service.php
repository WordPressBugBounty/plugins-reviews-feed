<?php

// phpcs:disable Generic.Metrics.CyclomaticComplexity
// Note: Comprehensive sanitization requires complexity.

/**
 * Review Alert Service
 *
 * Consolidated service class for review alerts feature.
 * Handles: registration, CRUD, AJAX handlers, settings, and tier checks.
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews\Common\ReviewAlerts
 */

namespace SmashBalloon\Reviews\Common\ReviewAlerts;

if (! defined('ABSPATH')) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\FeedCache;

/**
 * Class SBR_Review_Alert_Service
 *
 * @since 2.5.0
 */
class SBR_Review_Alert_Service extends ServiceProvider
{
	/**
	 * Custom post type name (max 20 characters)
	 */
	const POST_TYPE = 'sbr_review_alert';

	/**
	 * Shortcode-specified popup ID (takes priority over settings-based popups)
	 *
	 * @var int|null
	 */
	private static $shortcode_popup_id = null;

	/**
	 * Register hooks and actions
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function register(): void
	{
		add_action('init', [$this, 'register_post_type']);
		add_action('init', [$this, 'register_shortcode']);

		// AJAX handlers
		add_action('wp_ajax_sbr_review_alert_save', [__CLASS__, 'ajax_save']);
		add_action('wp_ajax_sbr_review_alert_delete', [__CLASS__, 'ajax_delete']);
		add_action('wp_ajax_sbr_review_alert_bulk_delete', [__CLASS__, 'ajax_bulk_delete']);
		add_action('wp_ajax_sbr_review_alert_list', [__CLASS__, 'ajax_list']);
		add_action('wp_ajax_sbr_review_alert_duplicate', [__CLASS__, 'ajax_duplicate']);
		add_action('wp_ajax_sbr_review_alert_preview_reviews', [__CLASS__, 'ajax_preview_reviews']);
		add_action('wp_ajax_sbr_review_alert_toggle_status', [__CLASS__, 'ajax_toggle_status']);
	}

	/**
	 * Register custom post type for storing review alerts
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function register_post_type(): void
	{
		$args = [
			'labels'              => [
				'name'          => __('Review Alerts', 'reviews-feed'),
				'singular_name' => __('Review Alert', 'reviews-feed'),
			],
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_admin_bar'   => false,
			'show_in_nav_menus'   => false,
			'can_export'          => true,
			'has_archive'         => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'capability_type'     => 'post',
			'supports'            => ['title'],
		];

		register_post_type(self::POST_TYPE, $args);
	}

	/**
	 * Register review alert shortcode
	 *
	 * Shortcode: [sbr-popup id="123"]
	 *
	 * When a shortcode is present on a page, it takes priority over
	 * settings-based popup display (configured via admin for "all pages"
	 * or specific page targeting).
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function register_shortcode(): void
	{
		add_shortcode('sbr-popup', [$this, 'render_shortcode']);
	}

	/**
	 * Shortcode callback for review alert
	 *
	 * Registers the popup ID for display in the footer. The actual popup
	 * rendering happens via SBR_Review_Alert_Frontend which checks
	 * for shortcode-specified popups before falling back to settings-based logic.
	 *
	 * Usage: [sbr-popup id="123"]
	 *
	 * @since 2.5.0
	 * @param array $atts Shortcode attributes
	 * @return string Empty string (popup renders in footer, not inline)
	 */
	public function render_shortcode($atts): string
	{
		// Parse shortcode attributes
		$atts = shortcode_atts([
			'id' => 0,
		], $atts, 'sbr-popup');

		$popup_id = absint($atts['id']);

		// Validate popup exists and is active
		if ($popup_id <= 0) {
			return '';
		}

		$popup = self::get_popup($popup_id);
		if (!$popup) {
			return '';
		}

		// Check if popup is active (published)
		if ($popup['status'] !== 'active') {
			return '';
		}

		// Store the shortcode-specified popup ID for priority rendering
		self::$shortcode_popup_id = $popup_id;

		// Return empty - popup renders in footer via Frontend class
		return '';
	}

	/**
	 * Get the shortcode-specified popup ID (if any)
	 *
	 * @since 2.5.0
	 * @return int|null Popup ID or null if no shortcode specified
	 */
	public static function get_shortcode_popup_id(): ?int
	{
		return self::$shortcode_popup_id;
	}

	/**
	 * Check if a shortcode-specified popup exists for current page
	 *
	 * @since 2.5.0
	 * @return bool True if shortcode specified a popup
	 */
	public static function has_shortcode_popup(): bool
	{
		return self::$shortcode_popup_id !== null;
	}

	/**
	 * Reset the shortcode popup ID
	 *
	 * Called at the start of each request to ensure static property
	 * doesn't leak between requests in persistent worker environments.
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function reset_shortcode_popup_id(): void
	{
		self::$shortcode_popup_id = null;
	}

	/**
	 * Get default settings for review alert
	 *
	 * @since 2.5.0
	 * @return array Default settings
	 */
	public static function get_defaults(): array
	{
		return [
			'theme'       => 'light',
			'variation'   => 'v1',
			'popup_type'  => 'aggregate', // 'aggregate' (summary view) or 'recent' (cycles through reviews)
			'accent_color' => '#175CE3',
			'accent_hue'  => '220', // Hue value (0-360) for HSL theming, corresponds to #175CE3
			'position'    => 'bottom-right',
			'timing'      => [
				'mode'               => 'fixed',  // 'fixed' or 'random' - controls review cycling interval
				'cycle_interval_min' => 3000,     // Min cycle interval for random mode (ms)
				'cycle_interval_max' => 5000,     // Cycle interval for fixed mode / max for random (ms)
				'display_duration'   => 5000,
			],
			'content'     => [
				'show_rating'        => true,
				'show_total_reviews' => true,
				'show_avatar'        => true,
				'show_platform'      => true,
				'show_reviewer_name' => true,
				'show_date'          => true,
				'show_review_text'   => true,
				'show_powered_by'    => true,
				'link_url'           => '#',  // URL for "View All Reviews" link
			],
			'sources'     => [],
			// Filters - aligned with Feed settings structure
			'filters'     => [
				'includedStarFilters' => [],       // Array of star ratings (1-5) to include
				'includeWords'        => '',       // Comma-separated words to include
				'excludeWords'        => '',       // Comma-separated words to exclude
				'filterCharCountMin'  => 0,        // Minimum character count
				'filterCharCountMax'  => '',       // Maximum character count (empty = no limit)
			],
			// Sorting - aligned with Feed settings structure
			'sort'        => [
				'sortByDateEnabled'   => true,     // Enable date sorting
				'sortByDate'          => 'latest', // 'latest' or 'oldest'
				'sortByRatingEnabled' => false,    // Enable rating sorting
				'sortByRating'        => '',       // 'highest' or 'lowest'
				'sortRandomEnabled'   => false,    // Randomize order
			],
			'visibility'  => [
				'display_on' => 'specific',
				'excluded'   => [
					'pages'             => [],
					'categories'        => [],
					'custom_post_types' => [],
				],
				'specific'   => [
					'pages'             => [],
					'categories'        => [],
					'custom_post_types' => [],
				],
			],
			// Review Feed (expanded popup) settings
			'review_feed' => [
				'show_heading'   => true,
				'heading_text'   => '',  // Empty = use default "See what our Customers say..."
				'show_button'    => true,
				'button_text'    => '',  // Empty = use default "Get Smash Balloon Feed Pro"
				'button_url'     => '',  // Empty = use default "#"
				'button_icon'    => null, // Icon ID: arrow-right, external-link, chevron-right, star, heart
				'show_stars'     => true,
				'show_title'     => true,
				'show_content'   => true,
				'show_author'    => true,
				'show_date'      => true,
				'show_powered_by' => true,
			],
			'status'      => 'inactive',
		];
	}

	/**
	 * Sanitize review alert settings
	 *
	 * @since 2.5.0
	 * @param array $settings Raw settings to sanitize
	 * @return array Sanitized settings
	 */
	public static function sanitize_settings(array $settings): array
	{
		$defaults = self::get_defaults();
		$sanitized = [];

		// Theme - must be 'light', 'dark', 'minimal', or 'minimal-dark'
		$valid_themes = ['light', 'dark', 'minimal', 'minimal-dark'];
		$sanitized['theme'] = isset($settings['theme']) && in_array($settings['theme'], $valid_themes, true)
			? $settings['theme']
			: $defaults['theme'];

		// Variation - must be 'v1', 'v2', or 'v3'
		$sanitized['variation'] = isset($settings['variation']) && in_array($settings['variation'], ['v1', 'v2', 'v3'], true)
			? $settings['variation']
			: $defaults['variation'];

		// Popup type - must be 'aggregate' or 'recent'
		$sanitized['popup_type'] = isset($settings['popup_type']) && in_array($settings['popup_type'], ['aggregate', 'recent'], true)
			? $settings['popup_type']
			: $defaults['popup_type'];

		// Accent color - must be valid hex color
		$sanitized['accent_color'] = isset($settings['accent_color']) && preg_match('/^#[a-fA-F0-9]{6}$/', $settings['accent_color'])
			? sanitize_hex_color($settings['accent_color'])
			: $defaults['accent_color'];

		// Accent hue - must be valid hue value (0-360) for HSL theming
		// This is sent from the React customizer alongside accent_color
		// Always save accent_hue (fallback to default if not provided)
		$sanitized['accent_hue'] = isset($settings['accent_hue'])
			? (string) min(360, max(0, absint($settings['accent_hue'])))
			: $defaults['accent_hue'];

		// Position - must be valid position
		$valid_positions = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];
		$sanitized['position'] = isset($settings['position']) && in_array($settings['position'], $valid_positions, true)
			? $settings['position']
			: $defaults['position'];

		// Timing - sanitize mode and cycle intervals
		$valid_timing_modes = ['fixed', 'random'];
		$sanitized['timing'] = [
			'mode'               => isset($settings['timing']['mode']) && in_array($settings['timing']['mode'], $valid_timing_modes, true)
				? $settings['timing']['mode']
				: $defaults['timing']['mode'],
			'cycle_interval_min' => isset($settings['timing']['cycle_interval_min'])
				? max(0, absint($settings['timing']['cycle_interval_min']))
				: $defaults['timing']['cycle_interval_min'],
			'cycle_interval_max' => isset($settings['timing']['cycle_interval_max'])
				? max(1000, absint($settings['timing']['cycle_interval_max']))
				: $defaults['timing']['cycle_interval_max'],
			'display_duration'   => isset($settings['timing']['display_duration'])
				? max(1000, absint($settings['timing']['display_duration']))
				: $defaults['timing']['display_duration'],
		];

		// Content - sanitize (booleans for show_* settings, URL for link_url)
		$sanitized['content'] = [];
		foreach ($defaults['content'] as $key => $default_value) {
			if ($key === 'link_url') {
				// Sanitize as URL
				$sanitized['content'][$key] = isset($settings['content'][$key])
					? esc_url_raw($settings['content'][$key])
					: $default_value;
			} else {
				// Sanitize as boolean
				$sanitized['content'][$key] = isset($settings['content'][$key])
					? (bool) $settings['content'][$key]
					: $default_value;
			}
		}

		// Sources - sanitize as array of integers (database IDs)
		// Uses database ID instead of account_id to avoid URL encoding issues with special characters
		// Following the source_id pattern from PR #418
		// Backward compatible: accepts both integer IDs (new) and string account_ids (old)
		$sanitized['sources'] = [];
		$legacy_account_ids = [];
		if (isset($settings['sources']) && is_array($settings['sources'])) {
			foreach ($settings['sources'] as $source) {
				if (is_numeric($source)) {
					// New format: database ID (integer)
					$sanitized['sources'][] = absint($source);
				} elseif (is_string($source) && !empty($source)) {
					// Old format: account_id string - collect for conversion
					$legacy_account_ids[] = $source;
				}
			}
		}
		// Convert legacy account_ids to database IDs
		if (!empty($legacy_account_ids)) {
			$converted_ids = self::convert_account_ids_to_db_ids($legacy_account_ids);
			$sanitized['sources'] = array_unique(array_merge($sanitized['sources'], $converted_ids));
		}
		// Filter out any invalid values (0s from failed conversions)
		$sanitized['sources'] = array_values(array_filter($sanitized['sources'], function ($id) {
			return $id > 0;
		}));

		// Visibility - sanitize page targeting
		// New clean structure: visibility.excluded/specific.pages/categories/custom_post_types
		// Default to 'specific' (matches get_defaults()) - safer default requiring explicit page selection
		$sanitized['visibility'] = [
			'display_on' => 'specific',
			'excluded'   => [
				'pages'             => [],
				'categories'        => [],
				'custom_post_types' => [],
			],
			'specific'   => [
				'pages'             => [],
				'categories'        => [],
				'custom_post_types' => [],
			],
		];

		// Check if UI sent 'visibility' structure
		if (isset($settings['visibility']) && is_array($settings['visibility'])) {
			$visibility = $settings['visibility'];

			// Display on: 'all' or 'specific'
			if (isset($visibility['display_on']) && in_array($visibility['display_on'], ['all', 'specific'], true)) {
				$sanitized['visibility']['display_on'] = $visibility['display_on'];
			}

			// Excluded pages/categories/custom_post_types (for 'all' mode)
			if (isset($visibility['excluded']) && is_array($visibility['excluded'])) {
				// Pages - array of objects {id, title, url} or IDs (backwards compat)
				if (isset($visibility['excluded']['pages']) && is_array($visibility['excluded']['pages'])) {
					$sanitized['visibility']['excluded']['pages'] = self::sanitize_visibility_pages($visibility['excluded']['pages']);
				}

				// Categories - array of objects {id, name, url} or IDs (backwards compat)
				if (isset($visibility['excluded']['categories']) && is_array($visibility['excluded']['categories'])) {
					$sanitized['visibility']['excluded']['categories'] = self::sanitize_visibility_categories($visibility['excluded']['categories']);
				}

				// Custom post types - array of objects {name, label} or slugs (backwards compat)
				if (isset($visibility['excluded']['custom_post_types']) && is_array($visibility['excluded']['custom_post_types'])) {
					$sanitized['visibility']['excluded']['custom_post_types'] = self::sanitize_visibility_post_types($visibility['excluded']['custom_post_types']);
				}
			}

			// Specific pages/categories/custom_post_types (for 'specific' mode)
			if (isset($visibility['specific']) && is_array($visibility['specific'])) {
				// Pages - array of objects {id, title, url} or IDs (backwards compat)
				if (isset($visibility['specific']['pages']) && is_array($visibility['specific']['pages'])) {
					$sanitized['visibility']['specific']['pages'] = self::sanitize_visibility_pages($visibility['specific']['pages']);
				}

				// Categories - array of objects {id, name, url} or IDs (backwards compat)
				if (isset($visibility['specific']['categories']) && is_array($visibility['specific']['categories'])) {
					$sanitized['visibility']['specific']['categories'] = self::sanitize_visibility_categories($visibility['specific']['categories']);
				}

				// Custom post types - array of objects {name, label} or slugs (backwards compat)
				if (isset($visibility['specific']['custom_post_types']) && is_array($visibility['specific']['custom_post_types'])) {
					$sanitized['visibility']['specific']['custom_post_types'] = self::sanitize_visibility_post_types($visibility['specific']['custom_post_types']);
				}
			}
		}

		// Filters - aligned with Feed settings (includedStarFilters, includeWords, etc.)
		$sanitized['filters'] = [
			'includedStarFilters' => [],
			'includeWords'        => '',
			'excludeWords'        => '',
			'filterCharCountMin'  => 0,
			'filterCharCountMax'  => '',
		];

		if (isset($settings['filters']) && is_array($settings['filters'])) {
			// Star filters - must be array of integers 1-5
			if (isset($settings['filters']['includedStarFilters']) && is_array($settings['filters']['includedStarFilters'])) {
				$sanitized['filters']['includedStarFilters'] = array_values(array_filter(
					array_map('absint', $settings['filters']['includedStarFilters']),
					function ($star) {
						return $star >= 1 && $star <= 5;
					}
				));
			}

			// Include words - sanitize as comma-separated text
			if (isset($settings['filters']['includeWords'])) {
				$sanitized['filters']['includeWords'] = sanitize_text_field($settings['filters']['includeWords']);
			}

			// Exclude words - sanitize as comma-separated text
			if (isset($settings['filters']['excludeWords'])) {
				$sanitized['filters']['excludeWords'] = sanitize_text_field($settings['filters']['excludeWords']);
			}

			// Min character count - must be non-negative integer
			if (isset($settings['filters']['filterCharCountMin'])) {
				$sanitized['filters']['filterCharCountMin'] = max(0, absint($settings['filters']['filterCharCountMin']));
			}

			// Max character count - sanitize as positive integer or empty string
			if (isset($settings['filters']['filterCharCountMax']) && $settings['filters']['filterCharCountMax'] !== '') {
				$sanitized['filters']['filterCharCountMax'] = max(1, absint($settings['filters']['filterCharCountMax']));
			}

			// Provider filter - array of provider names (e.g., ['google', 'facebook'])
			// Note: We explicitly check isset() to distinguish between:
			// - Not set (null) = no filter, show all providers
			// - Empty array [] = show no reviews (all providers deselected)
			// - Array with values = show only those providers
			if (isset($settings['filters']['providers'])) {
				if (is_array($settings['filters']['providers'])) {
					$valid_providers = ['google', 'facebook', 'yelp', 'tripadvisor', 'trustpilot', 'wordpress', 'woocommerce', 'edd'];
					$sanitized['filters']['providers'] = array_values(array_filter(
						array_map('sanitize_key', $settings['filters']['providers']),
						function ($provider) use ($valid_providers) {
							return in_array($provider, $valid_providers, true);
						}
					));
				} else {
					// If providers is set but not an array, treat as empty (show none)
					$sanitized['filters']['providers'] = [];
				}
			}
			// If providers key is not set, don't add it to sanitized - this means "no filter"
		}

		// Sort settings - aligned with Feed settings
		$sanitized['sort'] = $defaults['sort'];

		if (isset($settings['sort']) && is_array($settings['sort'])) {
			// Sort by date enabled
			if (isset($settings['sort']['sortByDateEnabled'])) {
				$sanitized['sort']['sortByDateEnabled'] = (bool) $settings['sort']['sortByDateEnabled'];
			}

			// Sort by date direction - must be 'latest' or 'oldest'
			if (isset($settings['sort']['sortByDate']) && in_array($settings['sort']['sortByDate'], ['latest', 'oldest'], true)) {
				$sanitized['sort']['sortByDate'] = $settings['sort']['sortByDate'];
			}

			// Sort by rating enabled
			if (isset($settings['sort']['sortByRatingEnabled'])) {
				$sanitized['sort']['sortByRatingEnabled'] = (bool) $settings['sort']['sortByRatingEnabled'];
			}

			// Sort by rating direction - must be 'highest' or 'lowest'
			if (isset($settings['sort']['sortByRating']) && in_array($settings['sort']['sortByRating'], ['highest', 'lowest'], true)) {
				$sanitized['sort']['sortByRating'] = $settings['sort']['sortByRating'];
			}

			// Random sort enabled
			if (isset($settings['sort']['sortRandomEnabled'])) {
				$sanitized['sort']['sortRandomEnabled'] = (bool) $settings['sort']['sortRandomEnabled'];
			}
		}

		// Review Feed (expanded popup) settings
		// Always initialize with defaults to prevent data loss on partial updates
		$sanitized['review_feed'] = $defaults['review_feed'];

		if (isset($settings['review_feed']) && is_array($settings['review_feed'])) {
			$review_feed = $settings['review_feed'];

			// Boolean visibility toggles
			$bool_keys = ['show_heading', 'show_button', 'show_stars', 'show_title', 'show_content', 'show_author', 'show_date', 'show_powered_by'];
			foreach ($bool_keys as $key) {
				if (isset($review_feed[$key])) {
					$sanitized['review_feed'][$key] = (bool) $review_feed[$key];
				}
			}

			// Text fields
			if (isset($review_feed['heading_text'])) {
				$sanitized['review_feed']['heading_text'] = sanitize_text_field($review_feed['heading_text']);
			}
			if (isset($review_feed['button_text'])) {
				$sanitized['review_feed']['button_text'] = sanitize_text_field($review_feed['button_text']);
			}
			if (isset($review_feed['button_url'])) {
				$sanitized['review_feed']['button_url'] = esc_url_raw($review_feed['button_url']);
			}

			// Button icon - must be a valid icon ID or null
			$valid_icons = ['arrow-right', 'external-link', 'chevron-right', 'star', 'heart'];
			if (isset($review_feed['button_icon']) && in_array($review_feed['button_icon'], $valid_icons, true)) {
				$sanitized['review_feed']['button_icon'] = $review_feed['button_icon'];
			} else {
				$sanitized['review_feed']['button_icon'] = null;
			}
		}

		// Status - must be 'active' or 'inactive'
		$sanitized['status'] = isset($settings['status']) && in_array($settings['status'], ['active', 'inactive'], true)
			? $settings['status']
			: $defaults['status'];

		return $sanitized;
	}

	/**
	 * Check if user can use a specific premium feature
	 *
	 * @since 2.5.0
	 * @internal Reserved for future feature-gating implementation. Currently unused but
	 *           provides the infrastructure for granular tier-based feature restrictions.
	 * @param string $feature Feature key to check
	 * @return bool Whether the feature is available
	 */
	public static function can_use_feature(string $feature): bool
	{
		// Determine tier: pro_plus > pro > free
		$tier = Util::sbr_is_pro_plus() ? 'pro_plus' : (Util::sbr_is_pro() ? 'pro' : 'free');

		// Feature requirements by tier
		$feature_tiers = [
			'variations_v2_v3'     => ['pro', 'pro_plus'],
			'dark_theme'           => ['pro', 'pro_plus'],
			'minimal_theme'        => ['pro', 'pro_plus'],
			'custom_accent_color'  => ['pro', 'pro_plus'],
			'recent_reviews'       => ['pro', 'pro_plus'],
			'multiple_popups'      => ['pro_plus'],
			'page_targeting'       => ['pro_plus'],
			'remove_branding'      => ['pro_plus'],
		];

		// Unknown features are available to all
		if (!isset($feature_tiers[$feature])) {
			return true;
		}

		return in_array($tier, $feature_tiers[$feature], true);
	}

	/**
	 * Get a single popup by ID
	 *
	 * @since 2.5.0
	 * @param int $id Popup post ID
	 * @return array|null Popup data or null if not found
	 */
	public static function get_popup(int $id): ?array
	{
		$post = get_post($id);

		if (!$post || $post->post_type !== self::POST_TYPE) {
			return null;
		}

		$settings = json_decode($post->post_content, true);
		if (!is_array($settings)) {
			$settings = [];
		}

		// Use array_replace_recursive for proper nested array merging
		// This ensures new nested defaults are applied to older saved popups
		$merged_settings = array_replace_recursive(self::get_defaults(), $settings);

		// Convert locations to visibility structure for React UI compatibility
		$merged_settings = self::convert_locations_to_visibility($merged_settings);

		return [
			'id'       => $post->ID,
			'name'     => $post->post_title,
			'settings' => $merged_settings,
			'status'   => $post->post_status === 'publish' ? 'active' : 'inactive',
			'created'  => $post->post_date,
			'modified' => $post->post_modified,
		];
	}

	/**
	 * Get list of popups
	 *
	 * @since 2.5.0
	 * @param array $args Query arguments
	 * @return array List of popups
	 */
	public static function get_popups(array $args = []): array
	{
		$defaults = [
			'posts_per_page' => 20,
			'paged'          => 1,
			'orderby'        => 'ID',  // Use ID for consistent ordering (newest first, never changes)
			'order'          => 'DESC',
			'post_status'    => ['publish', 'draft'],
		];

		$query_args = array_merge($defaults, $args, [
			'post_type' => self::POST_TYPE,
		]);

		$query = new \WP_Query($query_args);
		$popups = [];

		foreach ($query->posts as $post) {
			$popup = self::get_popup($post->ID);
			if ($popup) {
				$popups[] = $popup;
			}
		}

		return [
			'popups'      => $popups,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		];
	}

	/**
	 * Save (create or update) a popup
	 *
	 * @since 2.5.0
	 * @param array $data Popup data
	 * @return int|\WP_Error Post ID on success, WP_Error on failure
	 */
	public static function save_popup(array $data)
	{
		$id = isset($data['id']) ? absint($data['id']) : 0;
		$name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
		$settings = isset($data['settings']) && is_array($data['settings']) ? $data['settings'] : [];

		// For existing popups, ALWAYS preserve the current status
		// Status changes should only happen via the dedicated ajax_toggle_status endpoint
		// This prevents active popups from being accidentally set to inactive on save
		$existing_post = null;
		if ($id > 0) {
			$existing_post = get_post($id);
			if (!$existing_post || $existing_post->post_type !== self::POST_TYPE) {
				return new \WP_Error('invalid_popup', __('Popup not found.', 'reviews-feed'));
			}

			// Always use existing post status - ignore whatever the frontend sends
			$settings['status'] = $existing_post->post_status === 'publish' ? 'active' : 'inactive';
		} else {
			// New popups MUST start as inactive (draft)
			// This prevents bypassing the intended workflow via direct AJAX calls
			$settings['status'] = 'inactive';
		}

		// Sanitize settings
		$settings = self::sanitize_settings($settings);

		// Determine post status from settings
		$post_status = ($settings['status'] ?? 'active') === 'active' ? 'publish' : 'draft';

		$post_data = [
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name ?: __('Review Alert', 'reviews-feed'),
			'post_content' => wp_json_encode($settings),
			'post_status'  => $post_status,
		];

		if ($id > 0) {
			// Update existing popup
			$post_data['ID'] = $id;
			$result = wp_update_post($post_data, true);
		} else {
			// Create new popup
			$result = wp_insert_post($post_data, true);
		}

		return $result;
	}

	/**
	 * Delete a popup
	 *
	 * @since 2.5.0
	 * @param int $id Popup post ID
	 * @return bool True on success, false on failure
	 */
	public static function delete_popup(int $id): bool
	{
		$post = get_post($id);

		if (!$post || $post->post_type !== self::POST_TYPE) {
			return false;
		}

		$result = wp_delete_post($id, true);
		return $result !== false && $result !== null;
	}

	/**
	 * Duplicate a popup
	 *
	 * @since 2.5.0
	 * @param int $id Popup post ID to duplicate
	 * @return int|\WP_Error New post ID on success, WP_Error on failure
	 */
	public static function duplicate_popup(int $id)
	{
		$original = self::get_popup($id);

		if (!$original) {
			return new \WP_Error('invalid_popup', __('Popup not found.', 'reviews-feed'));
		}

		// Duplicate settings but force status to inactive (draft)
		// Duplicated popups should not go live immediately
		$duplicated_settings = $original['settings'];
		$duplicated_settings['status'] = 'inactive';

		return self::save_popup([
			'name'     => sprintf('%s %s', $original['name'], __('(copy)', 'reviews-feed')),
			'settings' => $duplicated_settings,
		]);
	}

	/**
	 * AJAX handler: Save popup
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_save(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;
		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
		$settings = isset($_POST['settings']) ? json_decode(wp_unslash($_POST['settings']), true) : [];

		if (!is_array($settings)) {
			wp_send_json_error(['message' => __('Invalid settings data.', 'reviews-feed')], 400);
		}

		// Enforce tier restrictions
		$is_pro = Util::sbr_is_pro();
		$is_pro_plus = Util::sbr_is_pro_plus();

		// Check popup limit for non-Pro Plus users (only 1 popup allowed)
		if ($id === 0 && !$is_pro_plus) {
			$existing = self::get_popups(['posts_per_page' => 1]);
			if ($existing['total'] >= 1) {
				wp_send_json_error([
					'message' => __('Upgrade to Pro Plus to create multiple review alerts.', 'reviews-feed'),
					'upsell_key' => 'reviewAlertMultiple',
				], 403);
			}
		}

		// Enforce Pro-only settings for free users
		if (!$is_pro) {
			// Free users can only use 'light' theme (dark theme is Pro)
			if (isset($settings['theme']) && $settings['theme'] !== 'light') {
				$settings['theme'] = 'light';
			}
			// Free users can only use 'aggregate' popup type
			if (isset($settings['popup_type']) && $settings['popup_type'] !== 'aggregate') {
				$settings['popup_type'] = 'aggregate';
			}
		}

		// Enforce Pro Plus-only settings
		if (!$is_pro_plus) {
			// Non-Pro Plus users cannot hide branding
			if (isset($settings['content']['show_powered_by'])) {
				$settings['content']['show_powered_by'] = true;
			}
			if (isset($settings['review_feed']['show_powered_by'])) {
				$settings['review_feed']['show_powered_by'] = true;
			}
		}

		$result = self::save_popup([
			'id'       => $id,
			'name'     => $name,
			'settings' => $settings,
		]);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		$popup = self::get_popup($result);

		wp_send_json_success([
			'popup'   => $popup,
			'message' => $id > 0 ? __('Popup updated.', 'reviews-feed') : __('Popup created.', 'reviews-feed'),
		]);
	}

	/**
	 * AJAX handler: Delete popup
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_delete(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;

		if ($id <= 0) {
			wp_send_json_error(['message' => __('Invalid popup ID.', 'reviews-feed')], 400);
		}

		$result = self::delete_popup($id);

		if (!$result) {
			wp_send_json_error(['message' => __('Failed to delete popup.', 'reviews-feed')], 400);
		}

		// Return updated list
		$popups = self::get_popups();

		wp_send_json_success([
			'popupsList'  => $popups['popups'],
			'popupsCount' => $popups['total'],
			'message'     => __('Popup deleted.', 'reviews-feed'),
		]);
	}

	/**
	 * AJAX handler: Bulk delete popups
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_bulk_delete(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		// Get array of IDs from POST
		// FormData.append converts arrays to comma-separated strings (e.g., "243" or "243,244")
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below
		// @phpstan-ignore-next-line (wp_unslash can return string|array depending on input)
		$ids_raw = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '';

		// Handle different formats:
		// 1. Comma-separated string from FormData: "243" or "243,244"
		// 2. JSON string: "[243, 244]"
		// 3. PHP array from standard form submission: ['243', '244']
		$ids = [];
		// @phpstan-ignore-next-line (wp_unslash can return array for $_POST['ids[]'] form fields)
		if (is_array($ids_raw)) {
			$ids = $ids_raw;
		} elseif (is_string($ids_raw)) {
			// Try JSON decode first
			$json_decoded = json_decode($ids_raw, true);
			if (is_array($json_decoded)) {
				$ids = $json_decoded;
			} else {
				// Fall back to comma-separated string
				$ids = array_filter(
					explode(',', $ids_raw),
					function ($val) {
						return strlen($val) > 0;
					}
				);
			}
		}

		if (empty($ids)) {
			wp_send_json_error(['message' => __('No popups selected.', 'reviews-feed')], 400);
		}

		// Sanitize all IDs
		$ids = array_map('absint', $ids);
		$ids = array_filter($ids, function ($id) {
			return $id > 0;
		});

		if (empty($ids)) {
			wp_send_json_error(['message' => __('Invalid popup IDs.', 'reviews-feed')], 400);
		}

		// Delete each popup
		$deleted_count = 0;
		foreach ($ids as $id) {
			if (self::delete_popup($id)) {
				$deleted_count++;
			}
		}

		// Return updated list
		$popups = self::get_popups();

		wp_send_json_success([
			'popupsList'   => $popups['popups'],
			'popupsCount'  => $popups['total'],
			'deletedCount' => $deleted_count,
			'message'      => sprintf(
				/* translators: %d: number of deleted popups */
				_n('%d popup deleted.', '%d popups deleted.', $deleted_count, 'reviews-feed'),
				$deleted_count
			),
		]);
	}

	/**
	 * AJAX handler: List popups
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_list(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		$page = isset($_POST['page']) ? absint($_POST['page']) : 1;

		$popups = self::get_popups([
			'paged' => $page,
		]);

		wp_send_json_success([
			'popupsList'  => $popups['popups'],
			'popupsCount' => $popups['total'],
			'totalPages'  => $popups['total_pages'],
		]);
	}

	/**
	 * AJAX handler: Duplicate popup
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_duplicate(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;

		if ($id <= 0) {
			wp_send_json_error(['message' => __('Invalid popup ID.', 'reviews-feed')], 400);
		}

		// Check popup limit for non-Pro Plus users (only 1 popup allowed)
		$is_pro_plus = Util::sbr_is_pro_plus();
		if (!$is_pro_plus) {
			$existing = self::get_popups(['posts_per_page' => 1]);
			if ($existing['total'] >= 1) {
				wp_send_json_error([
					'message' => __('Upgrade to Pro Plus to create multiple review alerts.', 'reviews-feed'),
					'upsell_key' => 'reviewAlertMultiple',
				], 403);
			}
		}

		$result = self::duplicate_popup($id);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		// Return updated list
		$popups = self::get_popups();

		wp_send_json_success([
			'popupsList'  => $popups['popups'],
			'popupsCount' => $popups['total'],
			'newPopupId'  => $result,
			'message'     => __('Popup duplicated.', 'reviews-feed'),
		]);
	}

	/**
	 * AJAX handler: Toggle popup status (active/inactive)
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_toggle_status(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		$id = isset($_POST['id']) ? absint($_POST['id']) : 0;

		if ($id <= 0) {
			wp_send_json_error(['message' => __('Invalid popup ID.', 'reviews-feed')], 400);
		}

		// Get current popup
		$popup = self::get_popup($id);
		if (!$popup) {
			wp_send_json_error(['message' => __('Popup not found.', 'reviews-feed')], 404);
		}

		// Toggle status
		$new_status = $popup['status'] === 'active' ? 'inactive' : 'active';
		$new_post_status = $new_status === 'active' ? 'publish' : 'draft';

		// Update post status
		$result = wp_update_post([
			'ID'          => $id,
			'post_status' => $new_post_status,
		], true);

		if (is_wp_error($result)) {
			wp_send_json_error(['message' => $result->get_error_message()], 400);
		}

		// Return updated list
		$popups = self::get_popups();

		wp_send_json_success([
			'popupsList'  => $popups['popups'],
			'popupsCount' => $popups['total'],
			'newStatus'   => $new_status,
			'message'     => $new_status === 'active'
				? __('Popup activated.', 'reviews-feed')
				: __('Popup deactivated.', 'reviews-feed'),
		]);
	}

	/**
	 * Get active popups for frontend display
	 *
	 * @since 2.5.0
	 * @return array List of active popups
	 */
	public static function get_active_popups(): array
	{
		$result = self::get_popups([
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		]);

		return $result['popups'];
	}

	/**
	 * AJAX handler: Get preview reviews for popup editor
	 *
	 * Fetches reviews based on popup settings (sources, filters, sort)
	 * for live preview in the customizer.
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public static function ajax_preview_reviews(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(['message' => __('Unauthorized access.', 'reviews-feed')], 403);
		}

		// Get settings from POST
		$settings = isset($_POST['settings']) ? json_decode(wp_unslash($_POST['settings']), true) : [];

		if (!is_array($settings)) {
			wp_send_json_error(['message' => __('Invalid settings data.', 'reviews-feed')], 400);
		}

		// Sanitize settings
		$settings = self::sanitize_settings($settings);

		// Get reviews using the same logic as frontend
		$result = self::get_preview_reviews($settings);

		wp_send_json_success([
			'reviews'         => $result['reviews'],
			'totalReviews'    => $result['totalReviews'],
			'unfilteredTotal' => $result['unfilteredTotal'],
			'averageRating'   => $result['averageRating'],
		]);
	}

	/**
	 * Get reviews for popup preview
	 *
	 * Uses the same logic as SBR_Review_Alert_Frontend::get_reviews_for_popup()
	 * but accessible as a static method for the AJAX handler.
	 *
	 * @since 2.5.0
	 * @param array $popup_settings Popup settings with sources, filters, sort
	 * @return array{reviews: array, totalReviews: int, unfilteredTotal: int, averageRating: float} Array containing reviews, filtered count, unfiltered total, and average rating
	 */
	public static function get_preview_reviews(array $popup_settings): array
	{
		$source_db_ids = $popup_settings['sources'] ?? [];

		// Filter out invalid values (0, empty strings, non-numeric)
		// This handles edge cases from failed conversions or corrupted data
		$source_db_ids = array_filter($source_db_ids, function ($id) {
			return is_numeric($id) && (int) $id > 0;
		});
		$source_db_ids = array_values($source_db_ids); // Re-index array

		// If no sources specified, return empty - no fallback to all sources
		// User must explicitly select sources for the popup
		if (empty($source_db_ids)) {
			return [
				'reviews'         => [],
				'totalReviews'    => 0,
				'unfilteredTotal' => 0,
				'averageRating'   => 0,
			];
		}

		// Convert database IDs to account_ids for Feed class compatibility
		// Following PR #418 pattern: store database IDs to avoid URL encoding issues
		$source_account_ids = self::convert_db_ids_to_account_ids($source_db_ids);

		if (empty($source_account_ids)) {
			return [
				'reviews'         => [],
				'totalReviews'    => 0,
				'unfilteredTotal' => 0,
				'averageRating'   => 0,
			];
		}

		// Get filter settings
		$filters = $popup_settings['filters'] ?? [];
		$sort = $popup_settings['sort'] ?? [];

		// Use Pro Feed if available, otherwise Common Feed
		$feed_class = Util::sbr_is_pro()
			? '\\SmashBalloon\\Reviews\\Pro\\Feed'
			: '\\SmashBalloon\\Reviews\\Common\\Feed';

		// First, fetch ALL reviews WITHOUT user filters to get unfiltered total
		// This gives us the total "complete" reviews before filtering
		$unfiltered_settings = array_merge(sbr_settings_defaults(), [
			'sources'             => $source_account_ids,
			'numPostDesktop'      => 500,
			'numPostTablet'       => 500,
			'numPostMobile'       => 500,
			// No filters applied - we want all reviews from sources
			'includedStarFilters' => [],
			'includeWords'        => '',
			'excludeWords'        => '',
			'filterCharCountMin'  => 0,
			'filterCharCountMax'  => '',
			'sortByDateEnabled'   => true,
			'sortByDate'          => 'latest',
			'sortByRatingEnabled' => false,
			'sortByRating'        => '',
			'sortRandomEnabled'   => false,
		]);

		$unfiltered_cache_id = 'popup_preview_unfiltered_' . md5(wp_json_encode(['sources' => $source_db_ids]));
		$unfiltered_feed = new $feed_class($unfiltered_settings, $unfiltered_cache_id, new FeedCache($unfiltered_cache_id, 300));
		$unfiltered_feed->init();
		$unfiltered_feed->get_set_cache();
		$unfiltered_reviews = $unfiltered_feed->get_post_set_page();

		if (isset($unfiltered_reviews['data'])) {
			$unfiltered_reviews = $unfiltered_reviews['data'];
		}

		// Count complete reviews (with rating, text, name) for unfiltered total
		$unfiltered_total = self::count_complete_reviews($unfiltered_reviews);

		// Now fetch filtered reviews with user's filter settings
		$feed_settings = array_merge(sbr_settings_defaults(), [
			'sources'             => $source_account_ids,
			'numPostDesktop'      => 500,
			'numPostTablet'       => 500,
			'numPostMobile'       => 500,
			'includedStarFilters' => $filters['includedStarFilters'] ?? [],
			'includeWords'        => $filters['includeWords'] ?? '',
			'excludeWords'        => $filters['excludeWords'] ?? '',
			'filterCharCountMin'  => $filters['filterCharCountMin'] ?? 0,
			'filterCharCountMax'  => $filters['filterCharCountMax'] ?? '',
			'sortByDateEnabled'   => $sort['sortByDateEnabled'] ?? true,
			'sortByDate'          => $sort['sortByDate'] ?? 'latest',
			'sortByRatingEnabled' => $sort['sortByRatingEnabled'] ?? false,
			'sortByRating'        => $sort['sortByRating'] ?? '',
			'sortRandomEnabled'   => $sort['sortRandomEnabled'] ?? false,
		]);

		// Create unique cache ID for preview (short TTL for admin preview)
		$cache_key = md5(wp_json_encode([
			'sources' => $source_db_ids,
			'filters' => $filters,
			'sort'    => $sort,
			'preview' => true,
		]));
		$cache_id = 'popup_preview_' . $cache_key;

		$feed = new $feed_class($feed_settings, $cache_id, new FeedCache($cache_id, 300)); // 5 min cache for preview

		$feed->init();
		$feed->get_set_cache();

		$all_reviews = $feed->get_post_set_page();

		// Handle nested data structure
		if (isset($all_reviews['data'])) {
			$all_reviews = $all_reviews['data'];
		}

		// Filter for complete reviews and format for preview
		// Uses same logic as SBR_Review_Alert_Frontend::filter_complete_reviews()
		$complete_reviews = [];
		$total_matching = 0;
		$total_rating = 0;

		// Get provider filter - if explicitly set, only show reviews from these providers
		// Note: null/not set = no filter (show all), empty array = show none (all deselected)
		$allowed_providers = $filters['providers'] ?? null;
		$has_provider_filter = isset($filters['providers']);

		foreach ($all_reviews as $review) {
			// Filter by provider if provider filter is explicitly set
			// Extract provider and reviewer safely (avoid PHP 8.0+ warnings on non-array access)
			$provider = $review['provider'] ?? '';
			$review_provider = is_array($provider) ? ($provider['name'] ?? '') : $provider;
			$reviewer = $review['reviewer'] ?? [];

			if ($has_provider_filter) {
				// If providers array is empty, no reviews should show (all providers deselected)
				if (empty($allowed_providers)) {
					continue;
				}
				if (!in_array($review_provider, $allowed_providers, true)) {
					continue;
				}
			}

			// Must have valid rating (1-5)
			$rating = isset($review['rating']) ? (int) $review['rating'] : 0;
			if ($rating < 1 || $rating > 5) {
				continue;
			}

			// Must have review text (non-empty)
			$text = trim($review['text'] ?? '');
			if (empty($text)) {
				continue;
			}

			// Must have reviewer name (not empty or "Anonymous")
			$reviewer_name = is_array($reviewer) ? trim($reviewer['name'] ?? '') : '';
			if (empty($reviewer_name) || strtolower($reviewer_name) === 'anonymous') {
				continue;
			}

			// Count all matching reviews for total and sum ratings
			$total_matching++;
			$total_rating += $rating;

			// Add to preview array up to the shared frontend cap.
			if (count($complete_reviews) < SBR_Review_Alert_Frontend::MAX_POPUP_REVIEWS) {
				// Decode HTML entities for special characters (e.g., &amp; -> &, &#039; -> ')
				$decoded_text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
				$decoded_name = html_entity_decode($reviewer_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

				$reviewer_avatar = is_array($reviewer) ? ($reviewer['avatar'] ?? '') : '';
				$complete_reviews[] = [
					'id'           => $review['review_id'] ?? $review['id'] ?? uniqid(),
					'reviewer'     => [
						'name'   => $decoded_name,
						'avatar' => $reviewer_avatar,
					],
					'rating'       => (int) $rating,
					'text'         => $decoded_text,
					'relativeDate' => self::get_relative_date($review['time'] ?? 0),
					'provider'     => $review_provider ?: 'unknown',
				];
			}
		}

		// Headline total + average from the feed-header metadata, via the shared
		// helper the frontend render path uses too, so the two can't drift. SMASH-1616.
		// Backfill from the FULL cached set (get_posts()), matching FeedDisplay and
		// the frontend path — not the page slice — so the preview headline can't
		// under-count providers with a zero API total.
		[$total_reviews, $average_rating] = SBR_Review_Alert_Frontend::resolve_header_totals(
			$feed,
			$feed->get_posts(),
			$total_matching,
			$total_rating
		);

		return [
			'reviews'         => $complete_reviews,
			'totalReviews'    => $total_reviews,
			'unfilteredTotal' => $unfiltered_total,
			'averageRating'   => $average_rating,
		];
	}

	/**
	 * Count complete reviews (have rating 1-5, non-empty text, valid reviewer name)
	 *
	 * @since 2.5.0
	 * @param array $reviews Array of reviews
	 * @return int Count of complete reviews
	 */
	private static function count_complete_reviews(array $reviews): int
	{
		$count = 0;

		foreach ($reviews as $review) {
			// Must have valid rating (1-5)
			$rating = isset($review['rating']) ? (int) $review['rating'] : 0;
			if ($rating < 1 || $rating > 5) {
				continue;
			}

			// Must have review text
			$text = trim($review['text'] ?? '');
			if (empty($text)) {
				continue;
			}

			// Must have reviewer name (not empty or "Anonymous")
			$reviewer = $review['reviewer'] ?? [];
			$reviewer_name = is_array($reviewer) ? trim($reviewer['name'] ?? '') : '';
			if (empty($reviewer_name) || strtolower($reviewer_name) === 'anonymous') {
				continue;
			}

			$count++;
		}

		return $count;
	}

	/**
	 * Convert database source IDs to account_ids for Feed class compatibility
	 *
	 * Review Alerts stores database IDs instead of account_ids to avoid URL encoding
	 * issues with special characters (Danish æ, ø, å). This follows PR #418 pattern.
	 *
	 * @since 2.5.0
	 * @param array $db_ids Array of database source IDs (integers)
	 * @return array Array of account_ids (strings)
	 */
	private static function convert_db_ids_to_account_ids(array $db_ids): array
	{
		if (empty($db_ids)) {
			return [];
		}

		global $wpdb;
		$sources_table = $wpdb->prefix . 'sbr_sources';

		// Convert to integers for safety
		$db_ids = array_map('absint', $db_ids);
		$placeholders = implode(',', array_fill(0, count($db_ids), '%d'));

		// Query account_ids for given database IDs
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and placeholders are safely generated
		$results = $wpdb->get_col($wpdb->prepare("SELECT account_id FROM {$sources_table} WHERE id IN ({$placeholders})", ...$db_ids));

		return $results ?: [];
	}

	/**
	 * Convert account_ids to database IDs for backward compatibility
	 *
	 * Existing popups may have account_id strings stored in settings.sources.
	 * This converts them to database IDs (integers) for the new format.
	 *
	 * @since 2.5.0
	 * @param array $account_ids Array of account_id strings
	 * @return array Array of database IDs (integers)
	 */
	private static function convert_account_ids_to_db_ids(array $account_ids): array
	{
		if (empty($account_ids)) {
			return [];
		}

		global $wpdb;
		$sources_table = $wpdb->prefix . 'sbr_sources';

		// Sanitize account_ids
		$account_ids = array_map('sanitize_text_field', $account_ids);
		$placeholders = implode(',', array_fill(0, count($account_ids), '%s'));

		// Query database IDs for given account_ids
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and placeholders are safely generated
		$results = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$sources_table} WHERE account_id IN ({$placeholders})", ...$account_ids));

		return array_map('absint', $results ?: []);
	}

	/**
	 * Convert old locations structure to new visibility structure for React UI
	 *
	 * Old format (locations):
	 *   - all_pages: bool
	 *   - exclude_pages: [{id, type}] or [id, id, ...]
	 *   - specific_pages: [{id, type}] or [id, id, ...]
	 *
	 * New format (visibility):
	 *   - display_on: 'all' | 'specific'
	 *   - excluded: {pages: [], categories: [], custom_post_types: []}
	 *   - specific: {pages: [], categories: [], custom_post_types: []}
	 *
	 * @since 2.5.0
	 * @param array $settings Popup settings
	 * @return array Settings with visibility structure
	 */
	private static function convert_locations_to_visibility(array $settings): array
	{
		// Check if old locations format has data that needs conversion
		$locations = $settings['locations'] ?? [];
		$has_old_data = !empty($locations['exclude_pages']) || !empty($locations['specific_pages']);

		// Only convert if there's old data - new data uses visibility structure directly
		if (!$has_old_data) {
			return $settings;
		}

		// Initialize new visibility structure
		$visibility = [
			'display_on' => !empty($locations['all_pages']) ? 'all' : 'specific',
			'excluded'   => [
				'pages'             => [],
				'categories'        => [],
				'custom_post_types' => [],
			],
			'specific'   => [
				'pages'             => [],
				'categories'        => [],
				'custom_post_types' => [],
			],
		];

		// Convert from old locations format
		if (is_array($locations)) {
			$locations = $settings['locations'];

			// Convert all_pages boolean to display_on string
			$visibility['display_on'] = !empty($locations['all_pages']) ? 'all' : 'specific';

			// Convert exclude_pages - group by type
			if (!empty($locations['exclude_pages']) && is_array($locations['exclude_pages'])) {
				foreach ($locations['exclude_pages'] as $item) {
					self::add_item_to_visibility_group($item, $visibility['excluded']);
				}
			}

			// Convert specific_pages - group by type
			if (!empty($locations['specific_pages']) && is_array($locations['specific_pages'])) {
				foreach ($locations['specific_pages'] as $item) {
					self::add_item_to_visibility_group($item, $visibility['specific']);
				}
			}
		}

		$settings['visibility'] = $visibility;
		return $settings;
	}

	/**
	 * Add item to visibility group (excluded or specific)
	 *
	 * @since 2.5.0
	 * @param int|array $item Item ID or {id, type} object
	 * @param array &$group Reference to visibility group (excluded or specific)
	 */
	private static function add_item_to_visibility_group($item, array &$group): void
	{
		// Handle legacy format: just an integer ID (assume it's a page)
		if (is_numeric($item)) {
			$id = absint($item);
			if ($id > 0 && !in_array($id, $group['pages'], true)) {
				$group['pages'][] = $id;
			}
			return;
		}

		// Handle new format: {id, type} object
		if (!is_array($item) || !isset($item['id'])) {
			return;
		}

		$type = $item['type'] ?? 'page';

		// Normalize 'post' to 'page' (WordPress posts are treated as pages in visibility)
		if ($type === 'post') {
			$type = 'page';
		}

		switch ($type) {
			case 'page':
				$id = absint($item['id']);
				if ($id > 0 && !in_array($id, $group['pages'], true)) {
					$group['pages'][] = $id;
				}
				break;

			case 'category':
				$id = absint($item['id']);
				if ($id > 0 && !in_array($id, $group['categories'], true)) {
					$group['categories'][] = $id;
				}
				break;

			case 'post_type':
				// For post types, the ID is actually the slug
				$slug = is_numeric($item['id']) ? '' : sanitize_key($item['id']);
				if (!empty($slug) && !in_array($slug, $group['custom_post_types'], true)) {
					$group['custom_post_types'][] = $slug;
				}
				break;
		}
	}

	/**
	 * Convert timestamp to relative date string
	 *
	 * @since 2.5.0
	 * @param int $timestamp Unix timestamp
	 * @return string Relative date (e.g., "3d ago", "1w ago")
	 */
	private static function get_relative_date(int $timestamp): string
	{
		if ($timestamp <= 0) {
			return '';
		}

		$diff = time() - $timestamp;

		if ($diff < 60) {
			return __('just now', 'reviews-feed');
		} elseif ($diff < 3600) {
			$mins = (int) floor($diff / 60);
			return sprintf(_n('%dm ago', '%dm ago', $mins, 'reviews-feed'), $mins);
		} elseif ($diff < 86400) {
			$hours = (int) floor($diff / 3600);
			return sprintf(_n('%dh ago', '%dh ago', $hours, 'reviews-feed'), $hours);
		} elseif ($diff < 604800) {
			$days = (int) floor($diff / 86400);
			return sprintf(_n('%dd ago', '%dd ago', $days, 'reviews-feed'), $days);
		} elseif ($diff < 2592000) {
			$weeks = (int) floor($diff / 604800);
			return sprintf(_n('%dw ago', '%dw ago', $weeks, 'reviews-feed'), $weeks);
		} elseif ($diff < 31536000) {
			$months = (int) floor($diff / 2592000);
			return sprintf(_n('%dmo ago', '%dmo ago', $months, 'reviews-feed'), $months);
		} else {
			$years = (int) floor($diff / 31536000);
			return sprintf(_n('%dy ago', '%dy ago', $years, 'reviews-feed'), $years);
		}
	}

	/**
	 * Sanitize visibility pages array
	 * Handles both old format (ID-only) and new format (objects with metadata)
	 *
	 * @since 2.5.0
	 * @param array $pages Array of pages (IDs or objects)
	 * @return array Sanitized pages array
	 */
	private static function sanitize_visibility_pages(array $pages): array
	{
		$sanitized = [];
		foreach ($pages as $page) {
			if (is_array($page)) {
				// New format: {id, title, url}
				$item = [
					'id' => isset($page['id']) ? absint($page['id']) : 0,
				];
				if (isset($page['title'])) {
					$item['title'] = sanitize_text_field($page['title']);
				}
				if (isset($page['url'])) {
					$item['url'] = esc_url_raw($page['url']);
				}
				// ID 0 is valid (homepage)
				if ($item['id'] >= 0) {
					$sanitized[] = $item;
				}
			} else {
				// Old format: just ID
				$id = absint($page);
				if ($id >= 0) {
					$sanitized[] = $id;
				}
			}
		}
		return array_values($sanitized);
	}

	/**
	 * Sanitize visibility categories array
	 * Handles both old format (ID-only) and new format (objects with metadata)
	 *
	 * @since 2.5.0
	 * @param array $categories Array of categories (IDs or objects)
	 * @return array Sanitized categories array
	 */
	private static function sanitize_visibility_categories(array $categories): array
	{
		$sanitized = [];
		foreach ($categories as $category) {
			if (is_array($category)) {
				// New format: {id, name, url}
				$id = isset($category['id']) ? absint($category['id']) : 0;
				if ($id > 0) {
					$item = ['id' => $id];
					if (isset($category['name'])) {
						$item['name'] = sanitize_text_field($category['name']);
					}
					if (isset($category['url'])) {
						$item['url'] = esc_url_raw($category['url']);
					}
					$sanitized[] = $item;
				}
			} else {
				// Old format: just ID
				$id = absint($category);
				if ($id > 0) {
					$sanitized[] = $id;
				}
			}
		}
		return array_values($sanitized);
	}

	/**
	 * Sanitize visibility custom post types array
	 * Handles both old format (slug-only) and new format (objects with metadata)
	 *
	 * @since 2.5.0
	 * @param array $post_types Array of post types (slugs or objects)
	 * @return array Sanitized post types array
	 */
	private static function sanitize_visibility_post_types(array $post_types): array
	{
		$sanitized = [];
		foreach ($post_types as $post_type) {
			if (is_array($post_type)) {
				// New format: {name (slug), label}
				$slug = isset($post_type['name']) ? sanitize_key($post_type['name']) : '';
				if (!empty($slug)) {
					$item = ['name' => $slug];
					if (isset($post_type['label'])) {
						$item['label'] = sanitize_text_field($post_type['label']);
					}
					$sanitized[] = $item;
				}
			} else {
				// Old format: just slug
				$slug = sanitize_key($post_type);
				if (!empty($slug)) {
					$sanitized[] = $slug;
				}
			}
		}
		return array_values($sanitized);
	}
}
