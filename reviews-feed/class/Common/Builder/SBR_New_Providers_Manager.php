<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, Generic.Metrics.CyclomaticComplexity
// Note: Complex provider management with direct DB queries for WooCommerce comment handling.

/**
 * New Providers Manager - Handles WooCommerce, Airbnb, Booking.com, and AliExpress
 *
 * @since 2.2.0
 */

namespace SmashBalloon\Reviews\Common\Builder;

if (! defined('ABSPATH')) {
	exit;
}

use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\SinglePostCache;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Pro\Integrations\Providers\AliExpress;
use SmashBalloon\Reviews\Pro\Integrations\Providers\Airbnb;
use SmashBalloon\Reviews\Pro\Integrations\Providers\BookingCom;
use SmashBalloon\Reviews\Pro\Integrations\Providers\WooCommerce;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_External_Reviews_Update;
use Smashballoon\Stubs\Services\ServiceProvider;

class SBR_New_Providers_Manager extends ServiceProvider
{
	/**
	 * Register AJAX hooks for new providers
	 */
	public function register()
	{
		add_action('wp_ajax_sbr_add_woocommerce_source', [self::class, 'add_woocommerce_source']);
		add_action('wp_ajax_sbr_add_airbnb_source', [self::class, 'add_airbnb_source']);
		add_action('wp_ajax_sbr_add_booking_source', [self::class, 'add_booking_source']);
		add_action('wp_ajax_sbr_add_aliexpress_source', [self::class, 'add_aliexpress_source']);
		add_action('wp_ajax_sbr_add_external_source', [self::class, 'add_external_source']);
		add_action('wp_ajax_sbr_get_woocommerce_products', [self::class, 'get_woocommerce_products']);
		add_action('wp_ajax_sbr_get_woocommerce_categories', [self::class, 'get_woocommerce_categories']);
		add_action('wp_ajax_sbr_get_woocommerce_tags', [self::class, 'get_woocommerce_tags']);
		add_action('wp_ajax_sbr_add_woocommerce_source_multi', [self::class, 'add_woocommerce_source_multi']);
		add_action('wp_ajax_sbr_update_woocommerce_source_multi', [self::class, 'update_woocommerce_source_multi']);
	}

	/**
	 * Check if a source already exists in the database
	 *
	 * @param string $source_id The source identifier (e.g., listing_id, hotel_id, product_id)
	 * @param string $provider The provider name (e.g., 'airbnb', 'booking', 'aliexpress', 'woocommerce')
	 * @return array|false Returns the existing source data if found, false otherwise
	 */
	private static function get_existing_source(string $source_id, string $provider)
	{
		$existing = SBR_Sources::get_single_source_info([
			'id' => $source_id,
			'provider' => $provider
		]);

		return !empty($existing) ? $existing : false;
	}

	/**
	 * Send response for existing source
	 *
	 * @param array $existing_source The existing source data
	 * @param string $provider_name Human-readable provider name for the message
	 * @return void
	 */
	private static function respond_with_existing_source(array $existing_source, string $provider_name)
	{
		wp_send_json([
			'success' => true,
			'message' => 'sourceExists',
			'existing' => true,
			'source' => $existing_source,
			'sourcesList' => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count(),
			'notice' => sprintf(
				'A %s source with this ID already exists: "%s". The existing source has been returned.',
				$provider_name,
				$existing_source['name'] ?? $existing_source['account_id']
			)
		]);
	}

	/**
	 * Check if the current request is from a Pro version
	 * Returns error response if not Pro and exits
	 *
	 * @param string $provider_name Human-readable provider name for error message
	 * @return bool True if Pro version, sends JSON error and exits if not
	 */
	private static function require_pro_version(string $provider_name): bool
	{
		if (!Util::sbr_is_pro()) {
			wp_send_json([
				'error' => 'pro_required',
				'message' => sprintf(
					'%s sources are only available in Reviews Feed Pro. Please upgrade to access this feature.',
					$provider_name
				)
			]);
			return false;
		}
		return true;
	}

	/**
	 * Add WooCommerce source
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function add_woocommerce_source()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce sources
		if (!self::require_pro_version('WooCommerce')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (!isset($_POST['product_id'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Product ID or URL is required']);
			return;
		}

		// Check if WooCommerce is active
		if (!class_exists('WooCommerce')) {
			wp_send_json(['error' => 'api_error', 'message' => 'WooCommerce is not active']);
			return;
		}

		$input = sanitize_text_field($_POST['product_id']);

		// Determine if input is a URL or ID
		if (filter_var($input, FILTER_VALIDATE_URL) || strpos($input, '/') !== false) {
			// It's a URL, extract product ID
			$product_id = WooCommerce::extract_product_id($input);
			if (!$product_id) {
				wp_send_json(['error' => 'api_error', 'message' => 'Could not find product from the provided URL. Please check the URL and try again.']);
				return;
			}
		} else {
			// It's already an ID
			$product_id = absint($input);
		}

		// Check if source already exists - return existing source without API call
		$existing_source = self::get_existing_source((string) $product_id, 'woocommerce');
		if ($existing_source) {
			self::respond_with_existing_source($existing_source, 'WooCommerce');
			return;
		}

		// Get product
		$product = wc_get_product($product_id);
		if (!$product) {
			wp_send_json(['error' => 'api_error', 'message' => 'Invalid product. The product may have been deleted or does not exist.']);
			return;
		}

		// Get reviews count
		$review_count = $product->get_review_count();
		if ($review_count === 0) {
			wp_send_json(['error' => 'api_error', 'message' => 'This product has no reviews yet. Please select a product with at least one review.']);
			return;
		}

		// Create source data
		$source_data = [
			'id' => (string) $product_id,  // Use product ID directly (no wc_ prefix)
			'provider' => 'woocommerce',
			'name' => $product->get_name(),
			'url' => get_permalink($product_id),
			'image' => wp_get_attachment_url($product->get_image_id()),
			'rating' => floatval($product->get_average_rating()),
			'review_count' => $review_count,
			'account_id' => (string) $product_id,
			'access_token' => '', // Not needed for WooCommerce
			'info' => json_encode([
				'id' => $product_id,
				'name' => $product->get_name(),
				'url' => get_permalink($product_id),
				'image' => wp_get_attachment_url($product->get_image_id()),
				'rating' => floatval($product->get_average_rating()),
				'total_rating' => $review_count,
				'review_count' => $review_count,
				'provider' => 'woocommerce',
				// Additional WooCommerce-specific data
				'type' => 'product',
				'sku' => $product->get_sku(),
				'price' => $product->get_price()
			]),
			'error' => '',
			'expires' => date('Y-m-d H:i:s', strtotime('+1 year')),
			'last_updated' => current_time('mysql'),
			'author' => get_current_user_id()
		];

		// Save source
		SBR_Sources::update_or_insert($source_data);

		// Fetch and cache initial reviews (page 1 with 20 reviews)
		$woocommerce = new WooCommerce();
		$reviews = $woocommerce->fetch_reviews($product_id, 1, 20); // Page 1, 20 per page
		$normalized_reviews = $woocommerce->normalize_reviews($reviews, $product);
		self::cache_reviews($normalized_reviews, 'woocommerce', (string) $product_id);

		// Schedule bulk update to fetch additional pages in background
		if ($review_count > 20) {
			$bulk_update = new \SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update();
			$bulk_update->schedule_task([
				'product_id' => (string) $product_id
			]);
		}

		// Return flat structure to match other providers
		wp_send_json([
			'success' => true,
			'message' => 'addedSource', // Must match frontend expectation
			'source' => $source_data,
			'sourcesList' => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count()
		]);
	}

	/**
	 * Add WooCommerce source with multiple products
	 *
	 * Creates a single source containing multiple WooCommerce products.
	 * The source stores product data in the info JSON and aggregates review counts.
	 *
	 * Expected POST parameters:
	 * - product_ids[]: Array of WooCommerce product IDs
	 * - source_name: User-defined name for the source
	 * - nonce: Security nonce
	 *
	 * @since 2.3.0
	 * @requires Pro version
	 */
	public static function add_woocommerce_source_multi()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce sources
		if (!self::require_pro_version('WooCommerce')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		// Maximum allowed items per selection type (prevent memory exhaustion)
		$max_items = 100;

		// Get product_ids, category_ids, and tag_ids (all optional but at least one required).
		$direct_product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('absint', $_POST['product_ids']) : [];
		$category_ids       = isset($_POST['category_ids']) && is_array($_POST['category_ids']) ? array_map('absint', $_POST['category_ids']) : [];
		$tag_ids            = isset($_POST['tag_ids']) && is_array($_POST['tag_ids']) ? array_map('absint', $_POST['tag_ids']) : [];

		// Enforce maximum limits to prevent memory exhaustion attacks
		if (count($direct_product_ids) > $max_items || count($category_ids) > $max_items || count($tag_ids) > $max_items) {
			wp_send_json([ 'error' => 'api_error', 'message' => sprintf('Maximum %d items allowed per selection type', $max_items) ]);
			return;
		}

		// Filter out zeros.
		$direct_product_ids = array_filter($direct_product_ids);
		$category_ids       = array_filter($category_ids);
		$tag_ids            = array_filter($tag_ids);

		// Validate at least one selection type is provided.
		if (empty($direct_product_ids) && empty($category_ids) && empty($tag_ids)) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'At least one product, category, or tag must be selected' ]);
			return;
		}

		// Validate source_name is provided
		if (!isset($_POST['source_name']) || empty(trim($_POST['source_name']))) {
			wp_send_json(['error' => 'api_error', 'message' => 'Source name is required']);
			return;
		}

		// Check if WooCommerce is active
		if (!class_exists('WooCommerce')) {
			wp_send_json(['error' => 'api_error', 'message' => 'WooCommerce is not active']);
			return;
		}

		$source_name = sanitize_text_field($_POST['source_name']);

		// Resolve category IDs to product IDs.
		$category_product_ids = ! empty($category_ids) ? self::get_products_by_categories($category_ids) : [];

		// Resolve tag IDs to product IDs.
		$tag_product_ids = ! empty($tag_ids) ? self::get_products_by_tags($tag_ids) : [];

		// Merge all product IDs, remove duplicates, and apply limit.
		$merge_result = self::merge_and_limit_product_ids($direct_product_ids, $category_product_ids, $tag_product_ids);
		$product_ids = $merge_result['product_ids'];

		// Ensure we have at least one product after resolution.
		if (empty($product_ids)) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'No products found for the selected categories/tags' ]);
			return;
		}

		// Generate a unique source ID for multi-product source
		$source_id = 'wc_multi_' . md5(implode('_', $product_ids) . '_' . time());

		// Validate all products and collect their data
		$products_info = [];
		$total_review_count = 0;
		$weighted_rating_sum = 0;

		foreach ($product_ids as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) {
				wp_send_json([
					'error' => 'api_error',
					'message' => sprintf('Product with ID %d not found', $product_id)
				]);
				return;
			}

			$review_count = $product->get_review_count();
			$average_rating = floatval($product->get_average_rating());
			$image_id = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';

			$products_info[] = [
				'id' => $product_id,
				'name' => $product->get_name(),
				'sku' => $product->get_sku(),
				'price' => $product->get_price(),
				'price_html' => $product->get_price_html(),
				'image' => $image_url,
				'url' => get_permalink($product_id),
				'review_count' => $review_count,
				'average_rating' => $average_rating
			];

			$total_review_count += $review_count;
			// Use weighted sum: rating * review_count for proper weighted average
			$weighted_rating_sum += $average_rating * $review_count;
		}

		// Calculate weighted average rating across all products
		$average_rating = $total_review_count > 0 ? round($weighted_rating_sum / $total_review_count, 1) : 0;

		// Use first product's image as the source image, or empty if none
		$source_image = !empty($products_info[0]['image']) ? $products_info[0]['image'] : '';

		// Build categories info for storage.
		$categories_info = [];
		if (! empty($category_ids)) {
			foreach ($category_ids as $cat_id) {
				$term = get_term($cat_id, 'product_cat');
				if ($term && ! is_wp_error($term)) {
					$categories_info[] = [
						'id'            => $term->term_id,
						'name'          => $term->name,
						'slug'          => $term->slug,
						'product_count' => $term->count,
					];
				}
			}
		}

		// Build tags info for storage.
		$tags_info = [];
		if (! empty($tag_ids)) {
			foreach ($tag_ids as $tag_id) {
				$term = get_term($tag_id, 'product_tag');
				if ($term && ! is_wp_error($term)) {
					$tags_info[] = [
						'id'            => $term->term_id,
						'name'          => $term->name,
						'slug'          => $term->slug,
						'product_count' => $term->count,
					];
				}
			}
		}

		// Create source data
		$source_data = [
			'id' => $source_id,
			'provider' => 'woocommerce',
			'name' => $source_name,
			'url' => '', // No single URL for multi-product source
			'image' => $source_image,
			'rating' => $average_rating,
			'review_count' => $total_review_count,
			'account_id' => $source_id,
			'access_token' => '', // Not needed for WooCommerce
			'info' => json_encode([
				'type' => 'multi_product',
				'source_name' => $source_name,
				'products' => $products_info,
				'product_ids' => array_values($product_ids), // Re-index array
				'direct_product_ids' => array_values($direct_product_ids), // Products selected directly
				'category_ids' => array_values($category_ids), // Categories selected
				'tag_ids' => array_values($tag_ids), // Tags selected
				'categories' => $categories_info, // Category details for display
				'tags' => $tags_info, // Tag details for display
				'direct_products' => array_values(array_filter($products_info, function ($p) use ($direct_product_ids) {
					return in_array($p['id'], $direct_product_ids);
				})), // Product details for directly selected products
				'product_count' => count($products_info),
				'total_rating' => $total_review_count,
				'review_count' => $total_review_count,
				'average_rating' => $average_rating,
				'provider' => 'woocommerce'
			]),
			'error' => '',
			'expires' => date('Y-m-d H:i:s', strtotime('+1 year')),
			'last_updated' => current_time('mysql'),
			'author' => get_current_user_id()
		];

		// Save source
		SBR_Sources::update_or_insert($source_data);

		// Fetch initial 20 reviews across all products in a single query
		// This is much faster than fetching from each product individually
		$woocommerce = new WooCommerce();
		$reviews = $woocommerce->fetch_reviews_multi($product_ids, 20, 0);
		$normalized_reviews = $woocommerce->normalize_reviews_multi($reviews);

		// Cache reviews under the multi-product source ID
		if (! empty($normalized_reviews)) {
			self::cache_reviews($normalized_reviews, 'woocommerce', $source_id);
		}

		// Schedule bulk update if there are more reviews to fetch (beyond initial 20)
		if ($total_review_count > 20) {
			$bulk_update = new \SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update();
			$bulk_update->schedule_task([
				'source_id'   => $source_id,
				'product_ids' => $product_ids,
				'type'        => 'multi_product',
			]);
		}

		// Return success response
		wp_send_json([
			'success' => true,
			'message' => 'addedSource',
			'source' => $source_data,
			'sourcesList' => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count()
		]);
	}

	/**
	 * Update an existing multi-product WooCommerce source
	 *
	 * Allows modifying the products included in a multi-product source.
	 * Updates source metadata and optionally fetches reviews for new products.
	 *
	 * Expected POST parameters:
	 * - source_id: The existing source ID (wc_multi_xxx)
	 * - product_ids[]: Array of WooCommerce product IDs
	 * - source_name: User-defined name for the source (optional, keeps existing if not provided)
	 * - nonce: Security nonce
	 *
	 * @since 2.3.0
	 * @requires Pro version
	 */
	public static function update_woocommerce_source_multi()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce sources
		if (!self::require_pro_version('WooCommerce')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		// Validate source_id
		if (!isset($_POST['source_id']) || empty($_POST['source_id'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Source ID is required']);
			return;
		}

		// Maximum allowed items per selection type (prevent memory exhaustion)
		$max_items = 100;

		// Get product_ids, category_ids, and tag_ids (all optional but at least one required).
		$direct_product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('absint', $_POST['product_ids']) : [];
		$category_ids       = isset($_POST['category_ids']) && is_array($_POST['category_ids']) ? array_map('absint', $_POST['category_ids']) : [];
		$tag_ids            = isset($_POST['tag_ids']) && is_array($_POST['tag_ids']) ? array_map('absint', $_POST['tag_ids']) : [];

		// Enforce maximum limits to prevent memory exhaustion attacks
		if (count($direct_product_ids) > $max_items || count($category_ids) > $max_items || count($tag_ids) > $max_items) {
			wp_send_json([ 'error' => 'api_error', 'message' => sprintf('Maximum %d items allowed per selection type', $max_items) ]);
			return;
		}

		// Filter out zeros.
		$direct_product_ids = array_filter($direct_product_ids);
		$category_ids       = array_filter($category_ids);
		$tag_ids            = array_filter($tag_ids);

		// Validate at least one selection type is provided.
		if (empty($direct_product_ids) && empty($category_ids) && empty($tag_ids)) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'At least one product, category, or tag must be selected' ]);
			return;
		}

		// Check if WooCommerce is active
		if (!class_exists('WooCommerce')) {
			wp_send_json(['error' => 'api_error', 'message' => 'WooCommerce is not active']);
			return;
		}

		$source_id = sanitize_text_field($_POST['source_id']);
		$source_name = isset($_POST['source_name']) && !empty(trim($_POST['source_name']))
			? sanitize_text_field($_POST['source_name'])
			: null;

		// Resolve category IDs to product IDs.
		$category_product_ids = ! empty($category_ids) ? self::get_products_by_categories($category_ids) : [];

		// Resolve tag IDs to product IDs.
		$tag_product_ids = ! empty($tag_ids) ? self::get_products_by_tags($tag_ids) : [];

		// Merge all product IDs, remove duplicates, and apply limit.
		$merge_result = self::merge_and_limit_product_ids($direct_product_ids, $category_product_ids, $tag_product_ids);
		$product_ids = $merge_result['product_ids'];

		// Ensure we have at least one product after resolution.
		if (empty($product_ids)) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'No products found for the selected categories/tags' ]);
			return;
		}

		// Get existing source
		$existing_source = SBR_Sources::get_single_source_info([
			'id' => $source_id,
			'provider' => 'woocommerce'
		]);

		if (!$existing_source) {
			wp_send_json(['error' => 'api_error', 'message' => 'Source not found']);
			return;
		}

		// Parse existing info to get old product_ids
		$existing_info = is_string($existing_source['info'])
			? json_decode($existing_source['info'], true)
			: $existing_source['info'];

		$old_product_ids = $existing_info['product_ids'] ?? [];
		$existing_name = $existing_info['source_name'] ?? $existing_source['name'];

		// Use existing name if not provided
		if ($source_name === null) {
			$source_name = $existing_name;
		}

		// Validate all products and collect their data
		$products_info = [];
		$total_review_count = 0;
		$weighted_rating_sum = 0;

		foreach ($product_ids as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) {
				wp_send_json([
					'error' => 'api_error',
					'message' => sprintf('Product with ID %d not found', $product_id)
				]);
				return;
			}

			$review_count = $product->get_review_count();
			$average_rating = floatval($product->get_average_rating());
			$image_id = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';

			$products_info[] = [
				'id' => $product_id,
				'name' => $product->get_name(),
				'sku' => $product->get_sku(),
				'price' => $product->get_price(),
				'price_html' => $product->get_price_html(),
				'image' => $image_url,
				'url' => get_permalink($product_id),
				'review_count' => $review_count,
				'average_rating' => $average_rating
			];

			$total_review_count += $review_count;
			// Use weighted sum: rating * review_count for proper weighted average
			$weighted_rating_sum += $average_rating * $review_count;
		}

		// Calculate weighted average rating across all products
		$average_rating = $total_review_count > 0 ? round($weighted_rating_sum / $total_review_count, 1) : 0;

		// Use first product's image as the source image, or empty if none
		$source_image = !empty($products_info[0]['image']) ? $products_info[0]['image'] : '';

		// Build categories info for storage.
		$categories_info = [];
		if (! empty($category_ids)) {
			foreach ($category_ids as $cat_id) {
				$term = get_term($cat_id, 'product_cat');
				if ($term && ! is_wp_error($term)) {
					$categories_info[] = [
						'id'            => $term->term_id,
						'name'          => $term->name,
						'slug'          => $term->slug,
						'product_count' => $term->count,
					];
				}
			}
		}

		// Build tags info for storage.
		$tags_info = [];
		if (! empty($tag_ids)) {
			foreach ($tag_ids as $tag_id_item) {
				$term = get_term($tag_id_item, 'product_tag');
				if ($term && ! is_wp_error($term)) {
					$tags_info[] = [
						'id'            => $term->term_id,
						'name'          => $term->name,
						'slug'          => $term->slug,
						'product_count' => $term->count,
					];
				}
			}
		}

		// Update source data
		$source_data = [
			'id' => $source_id,
			'provider' => 'woocommerce',
			'name' => $source_name,
			'url' => '',
			'image' => $source_image,
			'rating' => $average_rating,
			'review_count' => $total_review_count,
			'account_id' => $source_id,
			'access_token' => '',
			'info' => json_encode([
				'type' => 'multi_product',
				'source_name' => $source_name,
				'products' => $products_info,
				'product_ids' => array_values($product_ids), // Re-index array
				'direct_product_ids' => array_values($direct_product_ids), // Products selected directly
				'category_ids' => array_values($category_ids), // Categories selected
				'tag_ids' => array_values($tag_ids), // Tags selected
				'categories' => $categories_info, // Category details for display
				'tags' => $tags_info, // Tag details for display
				'direct_products' => array_values(array_filter($products_info, function ($p) use ($direct_product_ids) {
					return in_array($p['id'], $direct_product_ids);
				})), // Product details for directly selected products
				'product_count' => count($products_info),
				'total_rating' => $total_review_count,
				'review_count' => $total_review_count,
				'average_rating' => $average_rating,
				'provider' => 'woocommerce'
			]),
			'error' => '',
			'expires' => date('Y-m-d H:i:s', strtotime('+1 year')),
			'last_updated' => current_time('mysql'),
			'author' => get_current_user_id()
		];

		// Update source
		SBR_Sources::update_or_insert($source_data);

		// Find products that were removed from the source
		$removed_product_ids = array_diff($old_product_ids, $product_ids);

		// Delete reviews for removed products from the cache
		if (! empty($removed_product_ids)) {
			global $wpdb;
			$posts_table = $wpdb->prefix . SBR_POSTS_TABLE;

			// Get comment IDs (reviews) that belong to removed products and this source
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product_placeholders = implode(',', array_fill(0, count($removed_product_ids), '%d'));
			$reviews_to_delete = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.id FROM {$posts_table} p
					 INNER JOIN {$wpdb->comments} c ON p.post_id = c.comment_ID
					 WHERE p.provider_id = %s
					 AND p.provider = 'woocommerce'
					 AND c.comment_post_ID IN ($product_placeholders)",
					...array_merge([ $source_id ], array_values($removed_product_ids))
				)
			);

			// Delete the reviews
			if (! empty($reviews_to_delete)) {
				$delete_placeholders = implode(',', array_fill(0, count($reviews_to_delete), '%d'));
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$posts_table} WHERE id IN ($delete_placeholders)",
						...$reviews_to_delete
					)
				);
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Find new products that weren't in the old list
		$new_product_ids = array_diff($product_ids, $old_product_ids);

		// Fetch and cache reviews for new products only
		if (! empty($new_product_ids)) {
			$woocommerce = new WooCommerce();

			// Fetch up to 20 reviews from new products in a single query
			$reviews = $woocommerce->fetch_reviews_multi($new_product_ids, 20, 0);
			$normalized_reviews = $woocommerce->normalize_reviews_multi($reviews);

			// Cache new reviews under the multi-product source ID
			if (! empty($normalized_reviews)) {
				self::cache_reviews($normalized_reviews, 'woocommerce', $source_id);
			}

			// Schedule bulk update for all products if there are more reviews to fetch
			// We reset and refetch all products to ensure consistency
			if ($total_review_count > 20) {
				// Reset bulk update status for this source
				$accounts_list = get_option('sbr_bulk_woocommerce', []);
				if (isset($accounts_list[ $source_id ])) {
					$accounts_list[ $source_id ]['is_done']     = false;
					$accounts_list[ $source_id ]['offset']      = 20; // Start after initial 20
					$accounts_list[ $source_id ]['product_ids'] = $product_ids;
					update_option('sbr_bulk_woocommerce', $accounts_list);
				}

				$bulk_update = new \SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update();
				$bulk_update->schedule_task([
					'source_id'   => $source_id,
					'product_ids' => $product_ids,
					'type'        => 'multi_product',
				]);
			}
		}

		// Return success response
		wp_send_json([
			'success' => true,
			'message' => 'updatedSource',
			'source' => $source_data,
			'sourcesList' => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count()
		]);
	}

	/**
	 * Get WooCommerce products with reviews
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function get_woocommerce_products()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce
		if (!self::require_pro_version('WooCommerce')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (! class_exists('WooCommerce')) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'WooCommerce is not active' ]);
			return;
		}

		// Get search parameter if provided (for server-side product search)
		$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

		$woocommerce = new WooCommerce();
		$products    = $woocommerce->get_sb_woocommerce_products($search);

		wp_send_json_success([
			'products' => $products,
		]);
	}

	/**
	 * Get WooCommerce categories with product and review counts
	 *
	 * @since 2.3.0
	 * @requires Pro version
	 */
	public static function get_woocommerce_categories()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce.
		if (! self::require_pro_version('WooCommerce')) {
			return;
		}

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'Unauthorized access' ]);
			return;
		}

		if (! class_exists('WooCommerce')) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'WooCommerce is not active' ]);
			return;
		}

		$categories = self::get_woocommerce_categories_with_counts();

		wp_send_json_success([
			'categories' => $categories
		]);
	}

	/**
	 * Get WooCommerce tags with product and review counts
	 *
	 * @since 2.3.0
	 * @requires Pro version
	 */
	public static function get_woocommerce_tags()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for WooCommerce.
		if (! self::require_pro_version('WooCommerce')) {
			return;
		}

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'Unauthorized access' ]);
			return;
		}

		if (! class_exists('WooCommerce')) {
			wp_send_json([ 'error' => 'api_error', 'message' => 'WooCommerce is not active' ]);
			return;
		}

		$tags = self::get_woocommerce_tags_with_counts();

		wp_send_json_success([
			'tags' => $tags
		]);
	}

	/**
	 * Get WooCommerce product categories with product and review counts
	 *
	 * @since 2.3.0
	 * @return array Array of categories with id, name, slug, product_count, review_count
	 */
	private static function get_woocommerce_categories_with_counts()
	{
		global $wpdb;

		$categories = get_terms([
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]);

		if (is_wp_error($categories) || empty($categories)) {
			return [];
		}

		$result = [];

		foreach ($categories as $category) {
			// Get product count (WooCommerce stores this).
			$product_count = absint($category->count);

			// Get review count for all products in this category.
			$review_count = self::get_category_review_count($category->term_id);

			$result[] = [
				'id'            => $category->term_id,
				'name'          => $category->name,
				'slug'          => $category->slug,
				'product_count' => $product_count,
				'review_count'  => $review_count,
			];
		}

		return $result;
	}

	/**
	 * Get WooCommerce product tags with product and review counts
	 *
	 * @since 2.3.0
	 * @return array Array of tags with id, name, slug, product_count, review_count
	 */
	private static function get_woocommerce_tags_with_counts()
	{
		global $wpdb;

		$tags = get_terms([
			'taxonomy'   => 'product_tag',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]);

		if (is_wp_error($tags) || empty($tags)) {
			return [];
		}

		$result = [];

		foreach ($tags as $tag) {
			// Get product count (WooCommerce stores this).
			$product_count = absint($tag->count);

			// Get review count for all products with this tag.
			$review_count = self::get_tag_review_count($tag->term_id);

			$result[] = [
				'id'            => $tag->term_id,
				'name'          => $tag->name,
				'slug'          => $tag->slug,
				'product_count' => $product_count,
				'review_count'  => $review_count,
			];
		}

		return $result;
	}

	/**
	 * Get total review count for products in a category
	 *
	 * @since 2.3.0
	 * @param int $category_id Category term ID
	 * @return int Total review count
	 */
	private static function get_category_review_count($category_id)
	{
		global $wpdb;

		// Get all product IDs in this category.
		$product_ids = get_posts([
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_id,
				],
			],
		]);

		if (empty($product_ids)) {
			return 0;
		}

		// Count approved reviews for these products.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders dynamically generated for IN clause
		$placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments}
			WHERE comment_type = 'review'
			AND comment_approved = '1'
			AND comment_post_ID IN ($placeholders)",
			...$product_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return (int) $wpdb->get_var($query);
	}

	/**
	 * Get total review count for products with a tag
	 *
	 * @since 2.3.0
	 * @param int $tag_id Tag term ID
	 * @return int Total review count
	 */
	private static function get_tag_review_count($tag_id)
	{
		global $wpdb;

		// Get all product IDs with this tag.
		$product_ids = get_posts([
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'term_id',
					'terms'    => $tag_id,
				],
			],
		]);

		if (empty($product_ids)) {
			return 0;
		}

		// Count approved reviews for these products.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders dynamically generated for IN clause
		$placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->comments}
			WHERE comment_type = 'review'
			AND comment_approved = '1'
			AND comment_post_ID IN ($placeholders)",
			...$product_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return (int) $wpdb->get_var($query);
	}

	/**
	 * Get all product IDs in specified categories
	 *
	 * @since 2.3.0
	 * @param array $category_ids Array of category term IDs
	 * @return array Array of product IDs
	 */
	public static function get_products_by_categories($category_ids)
	{
		if (empty($category_ids)) {
			return [];
		}

		$category_ids = array_map('absint', $category_ids);
		$category_ids = array_filter($category_ids);

		if (empty($category_ids)) {
			return [];
		}

		$product_ids = get_posts([
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_ids,
				],
			],
		]);

		return $product_ids;
	}

	/**
	 * Get all product IDs with specified tags
	 *
	 * @since 2.3.0
	 * @param array $tag_ids Array of tag term IDs
	 * @return array Array of product IDs
	 */
	public static function get_products_by_tags($tag_ids)
	{
		if (empty($tag_ids)) {
			return [];
		}

		$tag_ids = array_map('absint', $tag_ids);
		$tag_ids = array_filter($tag_ids);

		if (empty($tag_ids)) {
			return [];
		}

		$product_ids = get_posts([
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => [
				[
					'taxonomy' => 'product_tag',
					'field'    => 'term_id',
					'terms'    => $tag_ids,
				],
			],
		]);

		return $product_ids;
	}

	/**
	 * Merge and limit product IDs to prevent resource exhaustion.
	 *
	 * Combines product IDs from direct selection, categories, and tags,
	 * then applies a configurable limit to prevent AJAX timeouts and
	 * memory exhaustion for stores with large product catalogs.
	 *
	 * @since 2.4.0
	 * @param array $direct_product_ids   Product IDs from direct selection.
	 * @param array $category_product_ids Product IDs resolved from categories.
	 * @param array $tag_product_ids      Product IDs resolved from tags.
	 * @return array {
	 *     @type array $product_ids Merged and limited product IDs.
	 *     @type bool  $truncated   Whether the list was truncated.
	 *     @type int   $total       Total count before truncation.
	 * }
	 */
	private static function merge_and_limit_product_ids($direct_product_ids, $category_product_ids, $tag_product_ids)
	{
		$product_ids = array_unique(array_merge(
			$direct_product_ids,
			$category_product_ids,
			$tag_product_ids
		));

		$total_count = count($product_ids);

		/**
		 * Filter the maximum number of products allowed in a multi-product WooCommerce source.
		 *
		 * @since 2.4.0
		 * @param int $max_products Maximum products allowed. Default 500.
		 */
		$max_products = apply_filters('sbr_woocommerce_max_products', 500);

		$truncated = $total_count > $max_products;

		if ($truncated) {
			$product_ids = array_slice($product_ids, 0, $max_products);
		}

		return [
			'product_ids' => $product_ids,
			'truncated'   => $truncated,
			'total'       => $total_count,
		];
	}

	/**
	 * Add Airbnb source
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function add_airbnb_source()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for Airbnb sources
		if (!self::require_pro_version('Airbnb')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (!isset($_POST['listing_url'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Listing URL is required']);
			return;
		}

		$listing_url = sanitize_text_field($_POST['listing_url']);
		$listing_id = Airbnb::extract_listing_id($listing_url);

		if (!$listing_id) {
			wp_send_json(['error' => 'api_error', 'message' => 'Invalid Airbnb listing URL. Please provide a valid Airbnb listing URL like: https://www.airbnb.com/rooms/123456789']);
			return;
		}

		// Check if source already exists - return existing source without API call
		$existing_source = self::get_existing_source($listing_id, 'airbnb');
		if ($existing_source) {
			self::respond_with_existing_source($existing_source, 'Airbnb');
			return;
		}

		try {
			// Use SBRelay to fetch normalized data from Relay API
			$relay = new SBRelay();

			// Fetch reviews from the listing via Relay API
			// Returns normalized data with 'reviews' and 'info' keys
			$response = $relay->callProvider('airbnb', [
				'propertyId' => $listing_id
			], 'reviews', 'GET');

			// Validate response structure - proxy returns { reviews: [], info: {} }
			if (!isset($response['reviews']) || !isset($response['info'])) {
				$error_message = 'Unable to fetch reviews. Unexpected API response format.';
				if (isset($response['message'])) {
					$error_message .= ' API Error: ' . $response['message'];
				}
				wp_send_json(['error' => 'api_error', 'message' => $error_message]);
				return;
			}

			// Use normalized data from proxy
			$normalized_reviews = $response['reviews'];
			$source_info = $response['info'];
			$review_count = count($normalized_reviews);

			$source_data = [
				'id' => $listing_id,
				'provider' => 'airbnb',
				'name' => $source_info['name'] ?? 'Airbnb Listing ' . $listing_id,
				'url' => $source_info['url'] ?? 'https://www.airbnb.com/rooms/' . $listing_id,
				'image' => $source_info['image'] ?? '',
				'rating' => $source_info['rating'] ?? 0,
				'review_count' => $source_info['review_count'] ?? $review_count,
				'account_id' => $listing_id,
				'access_token' => '', // No API key needed - handled by proxy
				'info' => json_encode([
					'id' => $listing_id,
					'name' => $source_info['name'] ?? 'Airbnb Listing ' . $listing_id,
					'url' => $source_info['url'] ?? 'https://www.airbnb.com/rooms/' . $listing_id,
					'image' => $source_info['image'] ?? '',
					'rating' => $source_info['rating'] ?? 0,
					'total_rating' => $source_info['review_count'] ?? $review_count,
					'review_count' => $source_info['review_count'] ?? $review_count,
					'provider' => 'airbnb'
				]),
				'error' => '',
				'expires' => date('Y-m-d H:i:s', strtotime('+30 days')),
				'last_updated' => current_time('mysql'),
				'author' => get_current_user_id()
			];

			SBR_Sources::update_or_insert($source_data);

			// Cache reviews - already normalized by proxy
			if (!empty($normalized_reviews)) {
				self::cache_reviews($normalized_reviews, 'airbnb', $listing_id);
			}

			// Schedule bulk update if there are more reviews to fetch
			// Airbnb typically returns ~50 reviews per page
			$total_reviews = $source_info['review_count'] ?? $review_count;
			self::maybe_schedule_bulk_update($listing_id, 'airbnb', $total_reviews, 50);

			wp_send_json([
				'success' => true,
				'message' => 'addedSource',
				'source' => $source_data,
				'sourcesList' => SBR_Sources::get_sources_list(),
				'sourcesCount' => SBR_Sources::get_sources_count()
			]);
		} catch (\Exception $e) {
			wp_send_json(['error' => 'api_error', 'message' => $e->getMessage()]);
		}
	}

	/**
	 * Add Booking.com source
	 *
	 * Supports two input types:
	 * 1. Numeric hotel_id directly or in URL query params
	 * 2. URL with hotel slug (will be resolved via API)
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function add_booking_source()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for Booking.com sources
		if (!self::require_pro_version('Booking.com')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (!isset($_POST['hotel_url'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Hotel URL is required']);
			return;
		}

		$hotel_url = sanitize_text_field($_POST['hotel_url']);
		$hotel_id = BookingCom::extract_hotel_id($hotel_url);

		// Initialize SBRelay early - we'll need it either way
		$relay = new SBRelay();

		// If no numeric hotel_id found, try to resolve from URL slug
		if (!$hotel_id && BookingCom::needsResolution($hotel_url)) {
			$url_components = BookingCom::extractUrlComponents($hotel_url);

			if (!$url_components) {
				wp_send_json(['error' => 'api_error', 'message' => 'Could not parse the Booking.com URL. Please check the URL format.']);
				return;
			}

			try {
				// Call resolve endpoint to get hotel_id from name/country
				$resolve_response = $relay->callProvider('booking', [
					'hotel_name' => $url_components['hotel_name'],
					'country' => $url_components['country'],
				], 'resolve', 'POST');

				// Check if resolution was successful
				if (isset($resolve_response['hotel_id'])) {
					$hotel_id = $resolve_response['hotel_id'];
				} else {
					$error_msg = 'Could not find hotel. ';
					if (isset($resolve_response['message'])) {
						$error_msg .= $resolve_response['message'];
					} else {
						$error_msg .= 'Please provide the numeric hotel_id directly.';
					}
					wp_send_json(['error' => 'api_error', 'message' => $error_msg]);
					return;
				}
			} catch (\Exception $e) {
				wp_send_json(['error' => 'api_error', 'message' => 'Failed to resolve hotel: ' . $e->getMessage()]);
				return;
			}
		}

		// Final validation - we must have a hotel_id at this point
		if (!$hotel_id) {
			wp_send_json(['error' => 'api_error', 'message' => 'Could not extract or resolve hotel ID. Please provide a valid Booking.com URL or the numeric hotel ID directly.']);
			return;
		}

		// Check if source already exists - return existing source without API call
		$existing_source = self::get_existing_source($hotel_id, 'booking');
		if ($existing_source) {
			self::respond_with_existing_source($existing_source, 'Booking.com');
			return;
		}

		try {
			// Fetch hotel info and reviews via Relay API (GET request)
			// Returns normalized data with 'reviews' and 'info' keys
			$response = $relay->callProvider('booking', [
				'hotel_id' => $hotel_id,
				'sort_type' => 'SORT_MOST_RELEVANT',
				'page_number' => 0,
				'locale' => 'en-gb'
			], 'reviews', 'GET');

			// Validate response structure - proxy returns { reviews: [], info: {} }
			if (!isset($response['reviews']) || !isset($response['info'])) {
				$error_msg = 'Unable to fetch reviews. Unexpected API response format.';
				if (isset($response['message'])) {
					$error_msg .= ' API Message: ' . $response['message'];
				}
				wp_send_json(['error' => 'api_error', 'message' => $error_msg]);
				return;
			}

			// Use normalized data from proxy
			$normalized_reviews = $response['reviews'];
			$source_info = $response['info'];
			$review_count = count($normalized_reviews);

			// Use hotel name from URL if available, otherwise use proxy info
			$hotel_name_from_url = BookingCom::extractHotelNameFromUrl($hotel_url);
			$hotel_name = $hotel_name_from_url ?: ($source_info['name'] ?? 'Booking.com Property ' . $hotel_id);

			$source_data = [
				'id' => $hotel_id,
				'provider' => 'booking',
				'name' => $hotel_name,
				'url' => $source_info['url'] ?? 'https://www.booking.com/hotel/index.html?hotel_id=' . $hotel_id,
				'image' => $source_info['image'] ?? '',
				'rating' => $source_info['rating'] ?? 0,
				'review_count' => $source_info['review_count'] ?? $review_count,
				'account_id' => $hotel_id,
				'access_token' => '', // No API key needed - handled by proxy
				'info' => json_encode([
					'id' => $hotel_id,
					'name' => $hotel_name,
					'url' => $source_info['url'] ?? 'https://www.booking.com/hotel/index.html?hotel_id=' . $hotel_id,
					'image' => $source_info['image'] ?? '',
					'rating' => $source_info['rating'] ?? 0,
					'total_rating' => $source_info['review_count'] ?? $review_count,
					'review_count' => $source_info['review_count'] ?? $review_count,
					'provider' => 'booking',
					'address' => $source_info['address'] ?? '',
					'city' => $source_info['city'] ?? '',
					'country' => $source_info['country'] ?? '',
				]),
				'error' => '',
				'expires' => date('Y-m-d H:i:s', strtotime('+30 days')),
				'last_updated' => current_time('mysql'),
				'author' => get_current_user_id()
			];

			SBR_Sources::update_or_insert($source_data);

			// Cache reviews - already normalized by proxy
			if (!empty($normalized_reviews)) {
				self::cache_reviews($normalized_reviews, 'booking', $hotel_id);
			}

			// Schedule bulk update if there are more reviews to fetch
			// Booking.com returns ~25 reviews per page
			$total_reviews = $source_info['review_count'] ?? $review_count;
			self::maybe_schedule_bulk_update($hotel_id, 'booking', $total_reviews, 25);

			// Return flat structure to match other providers
			wp_send_json([
				'success' => true,
				'message' => 'addedSource',
				'source' => $source_data,
				'sourcesList' => SBR_Sources::get_sources_list(),
				'sourcesCount' => SBR_Sources::get_sources_count()
			]);
		} catch (\Exception $e) {
			wp_send_json(['error' => 'api_error', 'message' => $e->getMessage()]);
		}
	}

	/**
	 * Add AliExpress source
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function add_aliexpress_source()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for AliExpress sources
		if (!self::require_pro_version('AliExpress')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (!isset($_POST['product_url'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Product URL is required']);
			return;
		}

		$product_url = sanitize_text_field($_POST['product_url']);
		$item_id = AliExpress::extract_item_id($product_url);

		if (!$item_id) {
			wp_send_json(['error' => 'api_error', 'message' => 'Invalid AliExpress product URL. Please provide a valid AliExpress product URL like: https://www.aliexpress.com/item/1005010027246941.html']);
			return;
		}

		// Check if source already exists - return existing source without API call
		$existing_source = self::get_existing_source($item_id, 'aliexpress');
		if ($existing_source) {
			self::respond_with_existing_source($existing_source, 'AliExpress');
			return;
		}

		try {
			// Use SBRelay to fetch normalized data from Relay API
			$relay = new SBRelay();

			// Fetch reviews from the product via Relay API
			// Returns normalized data with 'reviews' and 'info' keys
			$response = $relay->callProvider('aliexpress', [
				'itemId' => $item_id,
				'page' => 1,
				'filter' => 'allReviews'
			], 'reviews', 'GET');

			// Validate response structure - proxy returns { reviews: [], info: {} }
			if (!isset($response['reviews']) || !isset($response['info'])) {
				$error_msg = 'Unable to fetch reviews. Unexpected API response format.';
				if (isset($response['message'])) {
					$error_msg .= ' API Message: ' . $response['message'];
				}
				wp_send_json(['error' => 'api_error', 'message' => $error_msg]);
				return;
			}

			// Use normalized data from proxy
			$normalized_reviews = $response['reviews'];
			$source_info = $response['info'];
			$review_count = count($normalized_reviews);

			// Fetch product details to get name and image via SBRelay (source endpoint)
			$product_name = $source_info['name'] ?? 'AliExpress Product ' . $item_id;
			$product_image = $source_info['image'] ?? '';

			try {
				$details_response = $relay->callProvider('aliexpress', [
					'itemId' => $item_id
				], 'source', 'GET');

				// Use source info if available (has better product details)
				if (isset($details_response['info'])) {
					if (!empty($details_response['info']['name'])) {
						$product_name = $details_response['info']['name'];
					}
					if (!empty($details_response['info']['image'])) {
						$product_image = $details_response['info']['image'];
					}
				}
			} catch (\Exception $e) {
				// Continue with default values if product details fetch fails
			}

			$source_data = [
				'id' => $item_id,
				'provider' => 'aliexpress',
				'name' => $product_name,
				'url' => $source_info['url'] ?? 'https://www.aliexpress.com/item/' . $item_id . '.html',
				'image' => $product_image,
				'rating' => $source_info['rating'] ?? 0,
				'review_count' => $source_info['review_count'] ?? $review_count,
				'account_id' => $item_id,
				'access_token' => '', // No API key needed - handled by proxy
				'info' => json_encode([
					'id' => $item_id,
					'name' => $product_name,
					'url' => $source_info['url'] ?? 'https://www.aliexpress.com/item/' . $item_id . '.html',
					'image' => $product_image,
					'rating' => $source_info['rating'] ?? 0,
					'total_rating' => $source_info['review_count'] ?? $review_count,
					'review_count' => $source_info['review_count'] ?? $review_count,
					'provider' => 'aliexpress'
				]),
				'error' => '',
				'expires' => date('Y-m-d H:i:s', strtotime('+30 days')),
				'last_updated' => current_time('mysql'),
				'author' => get_current_user_id()
			];

			SBR_Sources::update_or_insert($source_data);

			// Cache reviews - already normalized by proxy
			if (!empty($normalized_reviews)) {
				self::cache_reviews($normalized_reviews, 'aliexpress', $item_id);
			}

			// Schedule bulk update if there are more reviews to fetch
			// AliExpress returns ~20 reviews per page
			$total_reviews = $source_info['review_count'] ?? $review_count;
			self::maybe_schedule_bulk_update($item_id, 'aliexpress', $total_reviews, 20);

			// Return flat structure to match other providers
			wp_send_json([
				'success' => true,
				'message' => 'addedSource',
				'source' => $source_data,
				'sourcesList' => SBR_Sources::get_sources_list(),
				'sourcesCount' => SBR_Sources::get_sources_count()
			]);
		} catch (\Exception $e) {
			wp_send_json(['error' => 'api_error', 'message' => $e->getMessage()]);
		}
	}

	/**
	 * Generic external source handler
	 *
	 * This method handles external providers (Airbnb, Booking, AliExpress)
	 * using the unified proxy architecture. The Laravel proxy returns normalized
	 * data so no client-side normalization is needed.
	 *
	 * @since 2.2.0
	 * @requires Pro version
	 */
	public static function add_external_source()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		// Pro version required for external providers (Airbnb, Booking, AliExpress)
		if (!self::require_pro_version('External')) {
			return;
		}

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		if (!isset($_POST['provider']) || !isset($_POST['source_url'])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Provider and source URL are required']);
			return;
		}

		$provider_name = sanitize_text_field($_POST['provider']);
		$source_url = sanitize_text_field($_POST['source_url']);

		// Map provider names to classes and API parameter names
		$provider_config = [
			'airbnb' => [
				'class' => Airbnb::class,
				'param_name' => 'propertyId',
				'friendly_name' => 'Airbnb'
			],
			'booking' => [
				'class' => BookingCom::class,
				'param_name' => 'hotel_id',
				'friendly_name' => 'Booking.com'
			],
			'aliexpress' => [
				'class' => AliExpress::class,
				'param_name' => 'itemId',
				'friendly_name' => 'AliExpress'
			]
		];

		if (!isset($provider_config[$provider_name])) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unsupported provider: ' . $provider_name]);
			return;
		}

		$config = $provider_config[$provider_name];
		$provider_class = $config['class'];

		try {
			// Extract source ID from URL
			$source_id = $provider_class::extractSourceId($source_url);

			if (!$source_id) {
				wp_send_json(['error' => 'api_error', 'message' => 'Invalid source URL for ' . $config['friendly_name']]);
				return;
			}

			// Check if source already exists
			$existing_source = self::get_existing_source($source_id, $provider_name);
			if ($existing_source) {
				self::respond_with_existing_source($existing_source, $config['friendly_name']);
				return;
			}

			// Use SBRelay to fetch normalized data from API
			$relay = new SBRelay();

			// Build API parameters based on provider
			$api_params = [$config['param_name'] => $source_id];

			// Fetch reviews from API via SBRelay
			// Returns normalized data with 'reviews' and 'info' keys
			$response = $relay->callProvider($provider_name, $api_params, 'reviews', 'GET');

			// Validate response structure - proxy returns { reviews: [], info: {} }
			if (!isset($response['reviews']) || !isset($response['info'])) {
				$error_message = 'Unable to fetch reviews. Unexpected API response format.';
				if (isset($response['message'])) {
					$error_message .= ' API Error: ' . $response['message'];
				}
				wp_send_json(['error' => 'api_error', 'message' => $error_message]);
				return;
			}

			// Use normalized data from proxy
			$normalized_reviews = $response['reviews'];
			$source_info = $response['info'];
			$review_count = count($normalized_reviews);

			// Prepare source data for database
			$source_data = [
				'id' => $source_id,
				'provider' => $provider_name,
				'name' => $source_info['name'] ?? $config['friendly_name'] . ' ' . $source_id,
				'url' => $source_info['url'] ?? '',
				'image' => $source_info['image'] ?? '',
				'rating' => $source_info['rating'] ?? 0,
				'review_count' => $source_info['review_count'] ?? $review_count,
				'account_id' => $source_id,
				'access_token' => '', // No API key needed - handled by proxy
				'info' => json_encode([
					'id' => $source_id,
					'name' => $source_info['name'] ?? $config['friendly_name'] . ' ' . $source_id,
					'url' => $source_info['url'] ?? '',
					'image' => $source_info['image'] ?? '',
					'rating' => $source_info['rating'] ?? 0,
					'total_rating' => $source_info['review_count'] ?? $review_count,
					'review_count' => $source_info['review_count'] ?? $review_count,
					'provider' => $provider_name
				]),
				'error' => '',
				'expires' => date('Y-m-d H:i:s', strtotime('+30 days')),
				'last_updated' => current_time('mysql'),
				'author' => get_current_user_id()
			];

			// Save source to database
			SBR_Sources::update_or_insert($source_data);

			// Cache reviews - already normalized by proxy
			if (!empty($normalized_reviews)) {
				self::cache_reviews($normalized_reviews, $provider_name, $source_id);
			}

			// Return flat structure to match other providers
			wp_send_json([
				'success' => true,
				'message' => 'addedSource',
				'source' => $source_data,
				'sourcesList' => SBR_Sources::get_sources_list(),
				'sourcesCount' => SBR_Sources::get_sources_count()
			]);
		} catch (\Exception $e) {
			wp_send_json(['error' => 'api_error', 'message' => $e->getMessage()]);
		}
	}

	/**
	 * Validate external provider API key
	 *
	 * Note: For Booking, Airbnb, and AliExpress providers, API keys
	 * are managed automatically. No user configuration required.
	 *
	 * @deprecated 2.3.0 External provider key validation is no longer required
	 */
	public static function validate_external_provider_key()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json(['error' => 'api_error', 'message' => 'Unauthorized access']);
			return;
		}

		// API keys for external providers are stored on the relay server
		// No need for users to enter or validate their own key
		wp_send_json_success([
			'message' => 'API key is managed by the relay server. No validation required.',
			'apiKeys' => get_option('sbr_apikeys', [])
		]);
	}

	/**
	 * Cache reviews using SinglePostCache
	 *
	 * @param array $reviews
	 * @param string $provider
	 * @param string $source_id
	 */
	private static function cache_reviews($reviews, $provider, $source_id)
	{
		foreach ($reviews as $review) {
			$cache = new SinglePostCache($review);
			$cache->set_provider_id($source_id);

			// Set images_done flag for providers without media
			$providers_no_media = sbr_get_no_media_providers();
			if (in_array($provider, $providers_no_media, true)) {
				$cache->set_storage_data('images_done', 1);
			}

			$cache->resize_avatar(150);

			if (!$cache->db_record_exists()) {
				$cache->store();
			} else {
				$cache->update_single();
			}
		}
	}

	/**
	 * Schedule bulk update for external providers if more reviews are available
	 *
	 * @param string $source_id Source identifier
	 * @param string $provider Provider name (airbnb, booking, aliexpress)
	 * @param int $total_reviews Total review count for the source
	 * @param int $per_page Reviews fetched per page
	 *
	 * @since 2.3.0
	 */
	private static function maybe_schedule_bulk_update(string $source_id, string $provider, int $total_reviews, int $per_page): void
	{
		// Only schedule if there are more reviews than what we fetched on page 1
		if ($total_reviews <= $per_page) {
			return;
		}

		// Check if Pro version is available (bulk update is a Pro feature)
		if (!Util::sbr_is_pro()) {
			return;
		}

		// Check if the bulk update class exists
		if (!class_exists(Bulk_External_Reviews_Update::class)) {
			return;
		}

		try {
			$bulk_update = new Bulk_External_Reviews_Update();

			// Only schedule if this source hasn't been processed before
			if ($bulk_update->check_source_needs_update($source_id, $provider)) {
				$bulk_update->schedule_task([
					'source_id' => $source_id,
					'provider' => $provider
				]);
			}
		} catch (\Exception $e) {
			SBR_Error_Handler::log_error([
				'type'     => 'bulk_update_schedule',
				'id'       => $source_id,
				'provider' => $provider,
				'message'  => $e->getMessage(),
			]);
		}
	}
}
