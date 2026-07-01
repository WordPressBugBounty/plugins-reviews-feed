<?php

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, Generic.Metrics.CyclomaticComplexity
// Note: JSON output required for localized script configuration. Config formatting requires complexity.

/**
 * Review Alert Frontend
 *
 * Handles frontend display of review alerts on public pages.
 * Conditionally loads assets and renders popup based on page targeting.
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews\Common\ReviewAlerts
 */

namespace SmashBalloon\Reviews\Common\ReviewAlerts;

if (! defined('ABSPATH')) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\Reviews\Common\FeedCache;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Common\TemplateRenderer;
use SmashBalloon\Reviews\Common\Util;

/**
 * Class SBR_Review_Alert_Frontend
 *
 * @since 2.5.0
 */
class SBR_Review_Alert_Frontend extends ServiceProvider
{
	/**
	 * Max reviews handed to the popup JS (it shows a first batch, then "See all"
	 * loads the rest). Shared with SBR_Review_Alert_Service::get_preview_reviews so
	 * the frontend and the customizer preview cap identically.
	 *
	 * @var int
	 */
	public const MAX_POPUP_REVIEWS = 150;

	/**
	 * Active popup for current page (cached after first check)
	 *
	 * @var array|null|false
	 */
	private $active_popup = false;

	/**
	 * Register hooks for frontend display
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function register(): void
	{
		// Use template_redirect which fires early enough for wp_enqueue_scripts
		add_action('template_redirect', [$this, 'setup_popup_display']);
	}

	/**
	 * Setup popup display - check conditions and enqueue assets
	 *
	 * Note: We don't determine which popup to display here because shortcodes
	 * haven't been processed yet. The actual popup selection happens in
	 * render_popup() at wp_footer, after content (and shortcodes) are processed.
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function setup_popup_display(): void
	{
		// Reset shortcode popup ID at start of each request to prevent leakage
		// in persistent worker environments (PHP-FPM with opcache, Swoole, etc.)
		SBR_Review_Alert_Service::reset_shortcode_popup_id();

		// Don't show in admin, login, or REST API requests
		if (is_admin() || wp_doing_ajax() || defined('REST_REQUEST')) {
			return;
		}

		// Notification popup is a Pro Plus/Elite feature (matches admin UI gating)
		if (!Util::sbr_is_pro_plus()) {
			return;
		}

		// Check if there are any active popups configured
		// Note: This doesn't account for shortcodes yet, but we need to enqueue assets
		// if ANY popup might display (either via settings or shortcode)
		$has_active_popups = !empty(SBR_Review_Alert_Service::get_active_popups());

		// Only proceed if there are active popups in the system
		// (shortcode can only reference active popups, so this check is sufficient)
		if (!$has_active_popups) {
			return;
		}

		// Enqueue CSS and JS assets
		add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 100);

		// Render popup HTML in footer (popup selection happens here, after shortcodes processed)
		// Priority 10 ensures config is output BEFORE scripts execute at priority 20
		add_action('wp_footer', [$this, 'render_popup'], 10);
	}

	/**
	 * Render the active popup (callback for wp_footer)
	 *
	 * Determines which popup to display at render time, after shortcodes
	 * have been processed. This allows shortcode-specified popups to take
	 * priority over settings-based popup targeting.
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function render_popup(): void
	{
		// Determine active popup now (after shortcodes have been processed)
		// This allows shortcode to take priority over settings-based popups
		$this->active_popup = $this->get_active_popup_for_page();

		if ($this->active_popup) {
			$this->render($this->active_popup);
		}
	}

	/**
	 * Get the active popup for the current page
	 *
	 * Returns the highest priority popup that should display on this page.
	 * Priority order (highest to lowest):
	 * 1. Shortcode-specified popup (takes absolute priority)
	 * 2. Settings-based: specific pages targeting
	 * 3. Settings-based: all pages (with exclusions)
	 *
	 * @since 2.5.0
	 * @return array|null Popup data or null if no popup should display
	 */
	public function get_active_popup_for_page(): ?array
	{
		// Priority 1: Check for shortcode-specified popup
		// Shortcode takes absolute priority over all settings-based popups
		$shortcode_popup_id = SBR_Review_Alert_Service::get_shortcode_popup_id();
		if ($shortcode_popup_id !== null) {
			$shortcode_popup = SBR_Review_Alert_Service::get_popup($shortcode_popup_id);
			if ($shortcode_popup && $shortcode_popup['status'] === 'active') {
				return $shortcode_popup;
			}
		}

		// Priority 2 & 3: Settings-based popup matching
		$active_popups = SBR_Review_Alert_Service::get_active_popups();

		if (empty($active_popups)) {
			return null;
		}

		$page_id = $this->get_current_page_id();
		$matching_popups = [];

		foreach ($active_popups as $popup) {
			if ($this->should_display_on_page($popup, $page_id)) {
				$matching_popups[] = $popup;
			}
		}

		if (empty($matching_popups)) {
			return null;
		}

		// Sort by specificity: specific pages first, then by ID (newest first)
		usort($matching_popups, function ($a, $b) {
			// Use visibility.display_on (new structure) - 'specific' = more specific than 'all'
			$a_specific = ($a['settings']['visibility']['display_on'] ?? 'all') === 'specific';
			$b_specific = ($b['settings']['visibility']['display_on'] ?? 'all') === 'specific';

			if ($a_specific !== $b_specific) {
				return $b_specific - $a_specific; // Specific pages have higher priority
			}

			return $b['id'] - $a['id']; // Newer popups have higher priority
		});

		return $matching_popups[0];
	}

	/**
	 * Get current page ID
	 *
	 * @since 2.5.0
	 * @return int Page ID or 0
	 */
	private function get_current_page_id(): int
	{
		// Try queried object first (works for pages, posts, CPTs)
		$queried_object = get_queried_object();
		if ($queried_object && isset($queried_object->ID)) {
			return (int) $queried_object->ID;
		}

		// Fallback to global post
		global $post;
		if ($post && isset($post->ID)) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * Check if popup should display on the current page
	 *
	 * @since 2.5.0
	 * @param array $popup   Popup data with settings
	 * @param int   $page_id Current page ID
	 * @return bool Whether to display on this page
	 */
	public function should_display_on_page(array $popup, int $page_id): bool
	{
		$visibility = $popup['settings']['visibility'] ?? [];
		$display_on = $visibility['display_on'] ?? 'all';

		// Get current location type and identifier
		$location = $this->get_current_location($page_id);

		if ($display_on === 'all') {
			// Show on all pages EXCEPT those in excluded list
			$excluded = $visibility['excluded'] ?? [];
			return !$this->is_location_in_list($location, $excluded);
		}

		// Specific mode: show ONLY on pages in specific list
		$specific = $visibility['specific'] ?? [];
		return $this->is_location_in_list($location, $specific);
	}

	/**
	 * Check if current page is the homepage
	 *
	 * Returns true if either:
	 * - is_front_page() - the site's front page (static or posts)
	 * - is_home() - the blog posts index
	 *
	 * This allows users to include/exclude "/" in visibility settings.
	 *
	 * @since 2.5.0
	 * @return bool True if on homepage
	 */
	private function is_homepage(): bool
	{
		return is_front_page() || is_home();
	}

	/**
	 * Get current location type and identifier
	 *
	 * Detection order (most specific to least specific):
	 * 1. Homepage → type: 'page', id: 0
	 * 2. WooCommerce pages (shop, product, product_cat) → handled specially
	 * 3. Category archive → type: 'category', id: term_id
	 * 4. Custom post type archive → type: 'custom_post_type', id: slug
	 * 5. Single page/post → type: 'page'/'post', id: post_id
	 * 6. Custom post type single → type: 'custom_post_type', id: slug
	 *
	 * @since 2.5.0
	 * @param int $page_id Current page ID
	 * @return array{type: string, id: int|string} Location type and identifier
	 */
	private function get_current_location(int $page_id): array
	{
		// 0. Check if we're on the homepage
		if ($this->is_homepage()) {
			return ['type' => 'page', 'id' => 0];
		}

		// 1. WooCommerce: Shop page (main shop archive)
		// Treat as a page so it shows with "All Pages" mode
		if (function_exists('is_shop') && is_shop()) {
			$shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
			return [
				'type' => 'page',
				'id'   => $shop_page_id > 0 ? $shop_page_id : $page_id,
			];
		}

		// 2. WooCommerce: Product category archive
		if (function_exists('is_product_category') && is_product_category()) {
			$term = get_queried_object();
			return [
				'type' => 'category',
				'id'   => $term ? $term->term_id : 0,
			];
		}

		// 3. WooCommerce: Single product page
		// Treat as a page (with the product's ID) so it shows with "All Pages" mode
		if (function_exists('is_product') && is_product()) {
			return [
				'type' => 'page',
				'id'   => $page_id,
			];
		}

		// 4. Check if we're on a category archive (WordPress)
		if (is_category()) {
			$category = get_queried_object();
			return [
				'type' => 'category',
				'id'   => $category ? $category->term_id : 0,
			];
		}

		// 5. Check if we're on a custom post type archive
		if (is_post_type_archive()) {
			return [
				'type' => 'custom_post_type',
				'id'   => get_query_var('post_type'),
			];
		}

		// 6. Get the post object for single pages/posts
		$post = get_post($page_id);
		if (!$post) {
			return ['type' => 'page', 'id' => $page_id];
		}

		// 7. Check if it's a custom post type (not page or post)
		if (!in_array($post->post_type, ['page', 'post'], true)) {
			return [
				'type'    => 'custom_post_type',
				'id'      => $post->post_type, // slug (whole-type targeting)
				'post_id' => $page_id,         // concrete id (individual targeting, e.g. a landing page)
			];
		}

		// 8. It's a page or post
		return [
			'type' => $post->post_type, // 'page' or 'post'
			'id'   => $page_id,
		];
	}

	/**
	 * Extract IDs from visibility array (handles both old ID-only and new object formats)
	 *
	 * @since 2.5.0
	 * @param array  $items Array of items (IDs or objects with 'id' key)
	 * @param string $key   Key to extract ('id' for pages/categories, 'name' for post types)
	 * @return array Array of IDs/slugs
	 */
	private function extract_visibility_ids(array $items, string $key = 'id'): array
	{
		$ids = [];
		foreach ($items as $item) {
			if (is_array($item)) {
				// New format: object with id/name key
				if (isset($item[$key])) {
					$ids[] = $key === 'name' ? (string) $item[$key] : (int) $item[$key];
				}
			} else {
				// Old format: just ID or slug
				$ids[] = $key === 'name' ? (string) $item : (int) $item;
			}
		}
		return $ids;
	}

	/**
	 * Check if location matches any item in visibility list
	 *
	 * @since 2.5.0
	 * @param array $location Current location {type, id}
	 * @param array $list     Visibility list with pages/categories/custom_post_types arrays
	 * @return bool Whether location is in the list
	 */
	private function is_location_in_list(array $location, array $list): bool
	{
		$type = $location['type'];
		$id = $location['id'];

		switch ($type) {
			case 'custom_post_type':
				// Whole-type match: slug against custom_post_types (handles both formats)
				$cpts = $this->extract_visibility_ids($list['custom_post_types'] ?? [], 'name');
				if (in_array($id, $cpts, true)) {
					return true;
				}
				// Individual match: a specific CPT entry (e.g. a landing page) picked
				// in the Pages list is stored under `pages` by its post ID. (SMASH-1616)
				$post_id = $location['post_id'] ?? 0;
				if ($post_id) {
					$pages = $this->extract_visibility_ids($list['pages'] ?? [], 'id');
					if (in_array((int) $post_id, $pages, true)) {
						return true;
					}
				}
				return false;

			case 'category':
				// Check term ID against categories array (handles both formats)
				$categories = $this->extract_visibility_ids($list['categories'] ?? [], 'id');
				return in_array((int) $id, $categories, true);

			case 'page':
				// Pages: check post ID against pages array (handles both formats)
				$pages = $this->extract_visibility_ids($list['pages'] ?? [], 'id');
				if (in_array((int) $id, $pages, true)) {
					return true;
				}

				// WooCommerce: Also check if current page is a product and 'product' CPT is in list
				// Products are returned as 'page' type by get_current_location() for "All Pages" compatibility,
				// but the exclusion list stores Products CPT as 'custom_post_types' => ['product']
				if (function_exists('is_product') && is_product()) {
					$cpts = $this->extract_visibility_ids($list['custom_post_types'] ?? [], 'name');
					if (in_array('product', $cpts, true)) {
						return true;
					}
				}

				// WooCommerce: Also check if current page is the shop page and 'product' CPT is in list
				if (function_exists('is_shop') && is_shop()) {
					$cpts = $this->extract_visibility_ids($list['custom_post_types'] ?? [], 'name');
					if (in_array('product', $cpts, true)) {
						return true;
					}
				}

				return false;

			case 'post':
				// Posts: check post ID against pages array (handles both formats)
				$pages = $this->extract_visibility_ids($list['pages'] ?? [], 'id');
				if (in_array((int) $id, $pages, true)) {
					return true;
				}

				// Also check if post belongs to any of the selected categories
				$categories = $this->extract_visibility_ids($list['categories'] ?? [], 'id');
				if (!empty($categories)) {
					$post_categories = wp_get_post_categories($id, ['fields' => 'ids']);
					if (is_array($post_categories)) {
						foreach ($post_categories as $cat_id) {
							if (in_array((int) $cat_id, $categories, true)) {
								return true;
							}
						}
					}
				}

				return false;

			default:
				return false;
		}
	}

	/**
	 * Enqueue popup CSS and JS assets
	 *
	 * @since 2.5.0
	 * @return void
	 */
	public function enqueue_assets(): void
	{
		// Theme CSS paths - check in order of priority
		$customizer_src_path   = 'vendor/smashballoon/customizer/sb-common/sb-customizer/src/assets/css/review-alert-themes.css';
		$customizer_build_path = 'vendor/smashballoon/customizer/sb-common/sb-customizer/assets/css/review-alert-themes.css';

		// Local dev: src/ exists (symlink), Production: assets/ exists (Makefile copies it)
		if (file_exists(SBR_PLUGIN_DIR . $customizer_src_path)) {
			$css_path = $customizer_src_path;
		} else {
			$css_path = $customizer_build_path;
		}

		wp_enqueue_style(
			'sbr-review-alert',
			SBR_PLUGIN_URL . $css_path,
			[],
			SBRVER
		);

		// Enqueue frontend-specific CSS (positioning, visibility, responsive)
		wp_enqueue_style(
			'sbr-review-alert-frontend',
			SBR_PLUGIN_URL . 'assets/css/sbr-review-alerts-frontend.css',
			['sbr-review-alert'],
			SBRVER
		);

		// Enqueue JS
		wp_enqueue_script(
			'sbr-review-alert',
			SBR_PLUGIN_URL . 'assets/js/sbr-review-alerts.js',
			[],
			SBRVER,
			true // Load in footer
		);
	}

	/**
	 * Render the review alert
	 *
	 * @since 2.5.0
	 * @param array $popup Review alert data with settings
	 * @return void
	 */
	private function render(array $popup): void
	{
		$settings = $popup['settings'];

		// Get reviews using existing Feed class with filter/sort settings
		$result = $this->get_reviews_for_popup($settings);
		$reviews = $result['reviews'];
		$total_reviews = $result['totalReviews'];
		$average_rating = $result['averageRating'];

		// Don't render if no reviews available
		if (empty($reviews)) {
			return;
		}

		// Prepare frontend configuration
		$config = $this->get_frontend_config($popup, $reviews, $total_reviews, $average_rating);

		// Output config as inline script (wp_localize_script doesn't work in footer after script was enqueued in head)
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoding handles escaping
		printf(
			'<script id="sbr-review-alert-config">var sbrReviewAlertConfig = %s;</script>',
			wp_json_encode($config)
		);

		// Render template
		TemplateRenderer::render('review-alerts/popup', [
			'popup'   => $popup,
			'reviews' => $reviews,
			'config'  => $config,
		]);
	}

	/**
	 * Get reviews for the popup using existing Feed class
	 *
	 * Applies the same filtering and sorting logic as the Reviews Feed:
	 * - Star rating filter (includedStarFilters)
	 * - Word filters (includeWords, excludeWords)
	 * - Character count filters (filterCharCountMin, filterCharCountMax)
	 * - Sorting by date, rating, or random
	 *
	 * @since 2.5.0
	 * @param array $popup_settings Full popup settings including sources, filters, and sort
	 * @return array{reviews: array, totalReviews: int, averageRating: float} Array containing reviews (up to MAX_POPUP_REVIEWS), the header total count, and the header average rating
	 */
	private function get_reviews_for_popup(array $popup_settings): array
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
				'reviews'       => [],
				'totalReviews'  => 0,
				'averageRating' => 0,
			];
		}

		// Convert database IDs to account_ids for Feed class compatibility
		// Following PR #418 pattern: store database IDs to avoid URL encoding issues
		$source_ids = $this->convert_db_ids_to_account_ids($source_db_ids);

		if (empty($source_ids)) {
			return [
				'reviews'       => [],
				'totalReviews'  => 0,
				'averageRating' => 0,
			];
		}

		// Get filter settings (aligned with Feed settings structure)
		$filters = $popup_settings['filters'] ?? [];
		$sort = $popup_settings['sort'] ?? [];

		// Build settings for Feed class - merge popup filters/sort with defaults
		$feed_settings = array_merge(sbr_settings_defaults(), [
			'sources'        => $source_ids,
			'numPostDesktop' => 500, // Fetch more to allow filtering
			'numPostTablet'  => 500,
			'numPostMobile'  => 500,
			// Filter settings - use popup settings if available
			'includedStarFilters' => $filters['includedStarFilters'] ?? [],
			'includeWords'        => $filters['includeWords'] ?? '',
			'excludeWords'        => $filters['excludeWords'] ?? '',
			'filterCharCountMin'  => $filters['filterCharCountMin'] ?? 0,
			'filterCharCountMax'  => $filters['filterCharCountMax'] ?? '',
			// Sort settings - use popup settings if available
			'sortByDateEnabled'   => $sort['sortByDateEnabled'] ?? true,
			'sortByDate'          => $sort['sortByDate'] ?? 'latest',
			'sortByRatingEnabled' => $sort['sortByRatingEnabled'] ?? false,
			'sortByRating'        => $sort['sortByRating'] ?? '',
			'sortRandomEnabled'   => $sort['sortRandomEnabled'] ?? false,
		]);

		// Create cache ID including filter/sort settings for unique caching
		$cache_key = md5(wp_json_encode([
			'sources' => $source_ids,
			'filters' => $filters,
			'sort'    => $sort,
		]));
		$cache_id = 'review_alert_' . $cache_key;

		// Use Pro Feed if available for WPML support and media optimization
		$feed_class = Util::sbr_is_pro()
			? '\\SmashBalloon\\Reviews\\Pro\\Feed'
			: '\\SmashBalloon\\Reviews\\Common\\Feed';

		$feed = new $feed_class($feed_settings, $cache_id, new FeedCache($cache_id, DAY_IN_SECONDS));

		$feed->init();
		$feed->get_set_cache();

		// get_post_set_page() returns filtered posts (Feed::filter_posts is applied internally)
		$all_reviews = $feed->get_post_set_page();

		// Additional filter to only include "complete" reviews suitable for popup
		// Pass provider filter if explicitly set (null = no filter, empty array = show none)
		$allowed_providers = isset($filters['providers']) ? $filters['providers'] : null;
		$complete_reviews = $this->filter_complete_reviews($all_reviews, $allowed_providers);
		$cached_count = count($complete_reviews);
		$cached_sum = 0;
		foreach ($complete_reviews as $review) {
			$cached_sum += isset($review['rating']) ? (int) $review['rating'] : 5;
		}

		// Headline total + average from the feed-header metadata (shared with the
		// customizer preview path so the two can't drift). SMASH-1616.
		// Backfill from the FULL cached set (get_posts()), exactly like
		// FeedDisplay::backfill_review_counts — not the page slice — so providers
		// whose API returned a zero count aren't under-counted vs the feed header.
		[$total_reviews, $average_rating] = self::resolve_header_totals($feed, $feed->get_posts(), $cached_count, $cached_sum);

		// Pass up to MAX_POPUP_REVIEWS - JS shows a first batch, then loads the rest on "See all".
		return [
			'reviews'       => array_slice($complete_reviews, 0, self::MAX_POPUP_REVIEWS),
			'totalReviews'  => $total_reviews,
			'averageRating' => $average_rating,
		];
	}

	/**
	 * Resolve a Review Alert's headline total + average from the feed-header
	 * metadata (the same numbers the published feed header shows), so the popup
	 * agrees with the feed. Falls back to the cached complete-review set when no
	 * source metadata is available. Shared by this frontend render path AND the
	 * customizer preview (SBR_Review_Alert_Service::get_preview_reviews) so the
	 * two can never drift. SMASH-1616.
	 *
	 * @param object $feed           The built Feed (after get_set_cache()).
	 * @param array  $cached_reviews Cached review rows (for count backfill).
	 * @param int    $cached_count   Count of cached complete reviews (fallback total).
	 * @param int    $cached_sum     Sum of cached complete-review ratings (fallback avg).
	 * @return array{0: int, 1: float} [total_reviews, average_rating]
	 */
	public static function resolve_header_totals($feed, array $cached_reviews, int $cached_count, int $cached_sum): array
	{
		$businesses = method_exists($feed, 'get_header_data') ? $feed->get_header_data() : [];
		$parser = new Parser();
		if (is_array($businesses) && ! empty($businesses)) {
			$businesses = $parser->backfill_review_counts($businesses, $cached_reviews);
		}
		$meta_total   = (int) $parser->get_num_ratings($businesses);
		$meta_average = (float) $parser->get_average_rating($businesses);

		$total = $meta_total > 0 ? $meta_total : $cached_count;
		if ($meta_average > 0) {
			$average = round($meta_average, 1);
		} else {
			$average = $cached_count > 0 ? round($cached_sum / $cached_count, 1) : 5.0;
		}

		return [$total, $average];
	}

	/**
	 * Decompose an average rating into per-star fill states, matching the feed
	 * header (4.7 -> full,full,full,full,half). The single source of truth for
	 * star rendering: the popup template consumes this, and the customizer's
	 * React `starFillStates()` in ReviewAlertPreview.js mirrors this exact
	 * formula so the admin preview and the live frontend never diverge (the bug
	 * was the preview pre-rounding with Math.round()). SMASH-1616.
	 *
	 * @param float $average Average rating (raw, NOT pre-rounded).
	 * @param int   $count   Number of stars (default 5).
	 * @return string[] One of 'full' | 'half' | 'empty' per star, length $count.
	 */
	public static function star_fill_states(float $average, int $count = 5): array
	{
		$states = [];
		for ($i = 1; $i <= $count; $i++) {
			if ($average >= $i) {
				$states[] = 'full';
			} elseif ($average >= $i - 0.5) {
				$states[] = 'half';
			} else {
				$states[] = 'empty';
			}
		}

		return $states;
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
	private function convert_db_ids_to_account_ids(array $db_ids): array
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
	 * Filter reviews to only include "complete" ones suitable for popup display
	 *
	 * Complete reviews must have:
	 * - Valid rating (1-5 stars)
	 * - Review text (non-empty)
	 * - Reviewer name (non-empty, not "Anonymous")
	 * - Avatar (preferred, but reviews without are still included if other criteria met)
	 *
	 * Reviews with avatars are prioritized over those without.
	 *
	 * @since 2.5.0
	 * @param array $reviews           Raw reviews array
	 * @param array $allowed_providers Optional array of provider names to filter by
	 * @return array Filtered reviews sorted by completeness
	 */
	private function filter_complete_reviews(array $reviews, ?array $allowed_providers = null): array
	{
		$with_avatar = [];
		$without_avatar = [];

		foreach ($reviews as $review) {
			// Filter by provider if provider filter is explicitly set
			// null = no filter (show all), empty array = show none (all deselected)
			if ($allowed_providers !== null) {
				// If providers array is empty, no reviews should show
				if (empty($allowed_providers)) {
					continue;
				}
				$provider = $review['provider'] ?? '';
				$review_provider = is_array($provider) ? ($provider['name'] ?? '') : $provider;
				if (!in_array($review_provider, $allowed_providers, true)) {
					continue;
				}
			}

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
			$name = is_array($reviewer) ? trim($reviewer['name'] ?? '') : '';
			if (empty($name) || strtolower($name) === 'anonymous') {
				continue;
			}

			// Check for avatar
			$avatar = is_array($reviewer) ? trim($reviewer['avatar'] ?? '') : '';
			if (!empty($avatar)) {
				$with_avatar[] = $review;
			} else {
				$without_avatar[] = $review;
			}
		}

		// Prioritize reviews with avatars, then those without
		return array_merge($with_avatar, $without_avatar);
	}

	/**
	 * Prepare frontend configuration object
	 *
	 * @since 2.5.0
	 * @param array $popup          Popup data
	 * @param array $reviews        Reviews array (up to MAX_POPUP_REVIEWS for display)
	 * @param int   $total_reviews  Total matching reviews count (before slicing)
	 * @param float $average_rating Average rating from all matching reviews
	 * @return array Configuration for frontend JS
	 */
	private function get_frontend_config(array $popup, array $reviews, int $total_reviews, float $average_rating): array
	{
		$settings = $popup['settings'];
		$review_feed = $settings['review_feed'] ?? [];

		return [
			'popupId'       => $popup['id'],
			'pluginUrl'     => trailingslashit(SBR_PLUGIN_URL),
			'theme'         => $settings['theme'] ?? 'default',
			'variation'     => $settings['variation'] ?? 'v1',
			'popupType'     => $settings['popup_type'] ?? 'aggregate', // 'aggregate' or 'recent'
			'accentColor'   => $settings['accent_color'] ?? '#175CE3',
			'accentHue'     => $settings['accent_hue'] ?? '220',
			'position'      => $settings['position'] ?? 'bottom-right',
			'linkUrl'       => $settings['content']['link_url'] ?? '#',
			'timing'        => [
				'mode'             => $settings['timing']['mode'] ?? 'fixed',
				'cycleIntervalMin' => $settings['timing']['cycle_interval_min'] ?? 3000,
				'cycleIntervalMax' => $settings['timing']['cycle_interval_max'] ?? 5000,
				'displayDuration'  => $settings['timing']['display_duration'] ?? 5000,
			],
			'content'       => [
				'showRating'       => $settings['content']['show_rating'] ?? true,
				'showTotalReviews' => $settings['content']['show_total_reviews'] ?? true,
				'showAvatar'       => $settings['content']['show_avatar'] ?? true,
				'showReviewerName' => $settings['content']['show_reviewer_name'] ?? true,
				'showDate'         => $settings['content']['show_date'] ?? true,
				'showPlatform'     => $settings['content']['show_platform'] ?? true,
				'showReviewText'   => $settings['content']['show_review_text'] ?? true,
				'showPoweredBy'    => $settings['content']['show_powered_by'] ?? true,
			],
			'reviewFeed'    => [
				'showHeading'  => $review_feed['show_heading'] ?? true,
				'headingText'  => $review_feed['heading_text'] ?? '',
				'showButton'   => $review_feed['show_button'] ?? true,
				'buttonText'   => $review_feed['button_text'] ?? '',
				'buttonUrl'    => $review_feed['button_url'] ?? '',
				'buttonIcon'   => $review_feed['button_icon'] ?? null,
				'showStars'    => $review_feed['show_stars'] ?? true,
				'showTitle'    => $review_feed['show_title'] ?? true,
				'showText'     => $review_feed['show_content'] ?? true,
				'showAuthor'   => $review_feed['show_author'] ?? true,
				'showDate'     => $review_feed['show_date'] ?? true,
				'showPoweredBy' => $review_feed['show_powered_by'] ?? true,
			],
			'i18n'          => [
				/* translators: %s: reviewer name */
				'reviewerHeadingTemplate' => __('%s left us a review', 'reviews-feed'),
			],
			'reviews'       => $this->format_reviews_for_frontend($reviews),
			'totalReviews'  => $total_reviews,
			'averageRating' => $average_rating,
		];
	}

	/**
	 * Format reviews for frontend consumption
	 *
	 * Decodes HTML entities in text fields (review text, reviewer name) to ensure
	 * special characters like Danish æ, ø, å display correctly on the frontend.
	 *
	 * @since 2.5.0
	 * @param array $reviews Raw reviews array
	 * @return array Formatted reviews
	 */
	private function format_reviews_for_frontend(array $reviews): array
	{
		$formatted = [];

		foreach ($reviews as $review) {
			// Safely access nested arrays to avoid PHP 8.0+ warnings
			$reviewer = $review['reviewer'] ?? [];
			$provider = $review['provider'] ?? '';

			// Extract reviewer fields with defensive is_array() checks
			$raw_reviewer_name = is_array($reviewer) ? ($reviewer['name'] ?? '') : '';
			$reviewer_avatar   = is_array($reviewer) ? ($reviewer['avatar'] ?? '') : '';

			// Extract provider name with defensive is_array() check
			$provider_name = is_array($provider) ? ($provider['name'] ?? 'google') : ($provider ?: 'google');

			// Decode HTML entities for proper character display (e.g., &#248; → ø)
			$text = html_entity_decode($review['text'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$reviewer_name = html_entity_decode(
				$raw_reviewer_name ?: __('Anonymous', 'reviews-feed'),
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);

			$formatted[] = [
				'id'          => $review['review_id'] ?? uniqid(),
				'text'        => $text,
				'rating'      => (int) ($review['rating'] ?? 5),
				'time'        => $review['time'] ?? '',
				'reviewer'    => [
					'name'   => $reviewer_name,
					'avatar' => $reviewer_avatar,
				],
				'provider'    => [
					'name' => $provider_name,
				],
			];
		}

		return $formatted;
	}

}
