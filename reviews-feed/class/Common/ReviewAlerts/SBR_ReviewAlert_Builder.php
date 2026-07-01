<?php

/**
 * Review Alert Builder
 *
 * Registers the "Review Alerts" admin menu item and enqueues
 * the customizer React app with the 'reviewAlerts' screen.
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews
 */

namespace SmashBalloon\Reviews\Common\ReviewAlerts;

if (!defined('ABSPATH')) {
	exit;
}

use Smashballoon\Customizer\V2\SB_Utils;
use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Builder\Config\Proxy;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Util;

/**
 * Class SBR_ReviewAlert_Builder
 *
 * Handles admin menu registration and asset loading for the
 * Review Alerts management page.
 */
class SBR_ReviewAlert_Builder extends ServiceProvider
{
	/**
	 * Built-in / system post types that are never targetable in the visibility UI
	 * (neither as individual pages nor as a Custom Post Types toggle). One source
	 * of truth shared by get_wordpress_pages() and get_wordpress_post_types().
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache'];

	/**
	 * Menu configuration
	 *
	 * @var array
	 */
	protected $menu;

	/**
	 * Config proxy
	 *
	 * @var Proxy
	 */
	protected $config_proxy;

	/**
	 * Whether to add to menu
	 *
	 * @var bool
	 */
	protected $add_to_menu;

	/**
	 * Constructor
	 *
	 * @param Proxy $config_proxy Configuration proxy.
	 */
	public function __construct(Proxy $config_proxy)
	{
		$this->config_proxy = $config_proxy;
	}

	/**
	 * Initialize menu configuration
	 *
	 * @return void
	 */
	public function init_menu(): void
	{
		if (!Util::sbr_is_pro_plus()) {
			$menu_title = __('Review Alerts', 'reviews-feed') . '<span class="sb-men-pro">PRO</span>';
		} else {
			$menu_title = __('Review Alerts', 'reviews-feed');
		}

		$this->menu = [
			'parent_menu_slug' => 'sbr',
			'page_title'       => __('Review Alerts', 'reviews-feed'),
			'menu_title'       => $menu_title,
			'menu_slug'        => 'sbr-review-alerts',
		];
	}

	/**
	 * Register service
	 *
	 * @return void
	 */
	public function register(): void
	{
		// Initialize menu config and license check in register() to avoid constructor side effects
		$this->add_to_menu = Util::sbr_is_pro_plus() ? check_license_valid() : true;

		add_action('init', [$this, 'init_menu']);

		if (is_admin() && $this->add_to_menu) {
			add_action('admin_menu', [$this, 'register_menu']);
		}
	}

	/**
	 * Register admin menu
	 *
	 * @return void
	 */
	public function register_menu(): void
	{
		$cap = current_user_can('manage_reviews_feed_options')
			? 'manage_reviews_feed_options'
			: 'manage_options';
		$cap = apply_filters('sbr_settings_pages_capability', $cap);

		$page = add_submenu_page(
			$this->menu['parent_menu_slug'],
			$this->menu['page_title'],
			$this->menu['menu_title'],
			$cap,
			$this->menu['menu_slug'],
			[$this, 'page_output'],
			2
		);

		add_action('load-' . $page, [$this, 'enqueue_admin_scripts']);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @return void
	 */
	public function enqueue_admin_scripts(): void
	{
		// For non-Pro Plus users, only load the CSS for the upsell page styling
		if (!Util::sbr_is_pro_plus()) {
			wp_enqueue_style(
				'sb-customizer-style',
				SB_CUSTOMIZER_ASSETS . '/build/static/css/main.css',
				[],
				false
			);
			return;
		}

		$popup_id = isset($_GET['popup_id']) ? absint($_GET['popup_id']) : 0;
		$is_popup_editor = $popup_id > 0;

		$builder_data = [
			'ajaxHandler'        => admin_url('admin-ajax.php'),
			'adminPostURL'       => admin_url('post.php'),
			'widgetsPageURL'     => admin_url('widgets.php'),
			'adminHomeURL'       => admin_url('admin.php'),
			'iconsList'          => SB_Utils::get_icons(),
			'reactScreen'        => $is_popup_editor ? 'reviewAlertEditor' : 'reviewAlerts',
			'reviewAlerts' => $this->get_popups_data(),
		];

		// Add popup editor data when editing
		if ($is_popup_editor) {
			$builder_data['isPopupEditor'] = true;
			$builder_data['popupEditor'] = [
				'defaultTab' => 'sb-popup-tab',
			];
			$builder_data['popupData'] = $this->get_popup_editor_data($popup_id);
		}

		$builder_data = array_merge($builder_data, $this->custom_builder_data());

		$js_file = SB_CUSTOMIZER_ASSETS . '/build/static/js/main.js';

		if (!SB_Utils::is_production()) {
			$js_file = 'http://localhost:3000/static/js/main.js';
		} else {
			wp_enqueue_style(
				'sb-customizer-style',
				SB_CUSTOMIZER_ASSETS . '/build/static/css/main.css',
				[],
				false
			);
		}

		wp_enqueue_script(
			'sb-customizer-app',
			$js_file,
			['wp-i18n', 'jquery'],
			false,
			true
		);

		wp_localize_script(
			'sb-customizer-app',
			'sb_customizer',
			$builder_data
		);

		wp_enqueue_media();
		wp_set_script_translations('sb-customizer-app', 'reviews-feed', SBR_PLUGIN_DIR . 'languages/');
	}

	/**
	 * Get popup data for editor
	 *
	 * @param int $popup_id Popup ID.
	 * @return array
	 */
	public function get_popup_editor_data(int $popup_id): array
	{
		$service = new SBR_Review_Alert_Service();
		$popup = $service->get_popup($popup_id);

		if (!$popup) {
			return [
				'popup_info' => [
					'id'     => 0,
					'name'   => __('New Review Alert', 'reviews-feed'),
					'status' => 'inactive', // New popups start as draft
				],
				'settings'   => SBR_Review_Alert_Service::get_defaults(),
			];
		}

		return [
			'popup_info' => [
				'id'     => $popup['id'],
				'name'   => $popup['name'],
				'status' => $popup['status'],
			],
			'settings'   => $popup['settings'],
		];
	}

	/**
	 * Get custom builder data
	 *
	 * @return array
	 */
	public function custom_builder_data(): array
	{
		// Determine upsell content based on license tier:
		// - Pro Plus users: no upsells needed
		// - Pro users: show Pro Plus upgrade upsells (e.g., multiple popups)
		// - Free users: show full upsell content (upgrade to Pro)
		if (Util::sbr_is_pro_plus()) {
			$upsell_content = [];
		} elseif (Util::sbr_is_pro()) {
			$upsell_content = Util::get_pro_plus_upsell_content();
		} else {
			$upsell_content = Util::upsell_modal_content();
		}

		return [
			'nonce'            => wp_create_nonce('sbr-admin'),
			'assetsURL'        => SB_COMMON_ASSETS,
			'isPro'            => Util::sbr_is_pro(),
			'isProPlus'        => Util::sbr_is_pro_plus(),
			'upsellContent'    => $upsell_content,
			'builderUrl'       => admin_url('admin.php?page=sbr-review-alerts'),
			'popupsPageUrl'    => admin_url('admin.php?page=sbr-review-alerts'),
			// Required by HomeScreen initialization
			'apiKeys'          => get_option('sbr_apikeys', []),
			'apiKeyLimits'     => get_option('sbr_apikeys_limit', []),
			'sourcesList'      => SBR_Sources::get_sources_list(),
			'sourcesCount'     => SBR_Sources::get_sources_count(),
			'providers'        => Util::get_providers(),
			'pluginStatus'     => (new AuthorizationStatusCheck())->get_statuses(),
			'connectFBUrls'    => sbr_get_fb_connection_urls(),
			'freeRetrieverData' => Util::get_free_retriever_data(),
			'feedData'         => [],
			'feedsList'        => [],
			'feedsCount'       => 0,
			'pluginSettings'   => is_array($sbr_settings_raw = get_option('sbr_settings', [])) ? $sbr_settings_raw : [],
			// WordPress data for visibility settings
			'wordpressPageLists'   => $this->get_wordpress_pages(),
			'wordpressCategories'  => $this->get_wordpress_categories(),
			'wordpressPostTypes'   => $this->get_wordpress_post_types(),
		];
	}

	/**
	 * Get WordPress pages for visibility settings
	 *
	 * @since 2.5.0
	 * @return array Array of pages with id, title, and url
	 */
	private function get_wordpress_pages(): array
	{
		// List published entries of `page` PLUS every public custom post type
		// (page-builder landing pages register their own CPT — SeedProd,
		// Elementor, Beaver, Brizy, etc.; WooCommerce `product` included too), so
		// they can be targeted individually. `get_pages()` only ever returned the
		// `page` type, which is why those landing pages were missing from the
		// dropdown (SMASH-1616). Blog `post` stays out (built-in; targeted via
		// categories). Single products already resolve to a `page`-type location
		// by ID in the frontend matcher, so listing them here works with the
		// existing matcher and coexists with the Custom Post Types toggle.
		$custom_types = get_post_types(['public' => true, '_builtin' => false], 'names');
		$page_types   = array_values(array_diff(array_merge(['page'], array_values($custom_types)), self::EXCLUDED_POST_TYPES));
		$page_types   = (array) apply_filters('sbr_review_alert_page_post_types', $page_types);

		// Bound the query: floor an invalid/unbounded filter value (<= 0, e.g. -1 =
		// "all") to the 300 default and cap at a hard ceiling, so a stray filter
		// can't trigger a huge query. Only id/title/url are used downstream, so skip
		// the meta/term caches and the found-rows count for a cheaper query.
		$pages_limit = (int) apply_filters('sbr_review_alert_pages_limit', 300);
		$pages_limit = $pages_limit > 0 ? min($pages_limit, 500) : 300;

		$pages_list = empty($page_types) ? [] : get_posts([
			'post_type'              => $page_types,
			'post_status'            => 'publish',
			'posts_per_page'         => $pages_limit,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'suppress_filters'       => false,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);
		$pages_result = [];

		// Add homepage option
		$pages_result[] = [
			'id'    => 0,
			'title' => __('Homepage', 'reviews-feed'),
			'url'   => '/',
		];

		if (is_array($pages_list)) {
			foreach ($pages_list as $page) {
				$pages_result[] = [
					'id'    => $page->ID,
					'title' => $page->post_title,
					'url'   => get_permalink($page->ID),
				];
			}
		}

		// Add WooCommerce Shop page if WooCommerce is active and shop page exists
		if (function_exists('wc_get_page_id')) {
			$shop_page_id = wc_get_page_id('shop');
			if ($shop_page_id > 0) {
				// Check if shop page is not already in the list
				$shop_exists = false;
				foreach ($pages_result as $page) {
					if ($page['id'] === $shop_page_id) {
						$shop_exists = true;
						break;
					}
				}
				if (!$shop_exists) {
					$pages_result[] = [
						'id'    => $shop_page_id,
						'title' => __('Shop', 'reviews-feed'),
						'url'   => get_permalink($shop_page_id),
					];
				}
			}
		}

		return $pages_result;
	}

	/**
	 * Get WordPress categories for visibility settings
	 *
	 * @since 2.5.0
	 * @return array Array of categories with id, name, and url
	 */
	private function get_wordpress_categories(): array
	{
		$categories_list = get_categories(['hide_empty' => false]);
		$categories_result = [];

		if (is_array($categories_list)) {
			foreach ($categories_list as $category) {
				$categories_result[] = [
					'id'   => $category->term_id,
					'name' => $category->name,
					'url'  => get_category_link($category->term_id),
				];
			}
		}

		// Add WooCommerce product categories if WooCommerce is active
		if (function_exists('get_terms') && taxonomy_exists('product_cat')) {
			$product_cats = get_terms([
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]);
			// @phpstan-ignore-next-line (get_terms can return WP_Error in edge cases)
			if (is_array($product_cats) && !is_wp_error($product_cats)) {
				foreach ($product_cats as $cat) {
					$categories_result[] = [
						'id'   => $cat->term_id,
						'name' => sprintf(__('Product: %s', 'reviews-feed'), $cat->name),
						'url'  => get_term_link($cat->term_id, 'product_cat'),
					];
				}
			}
		}

		return $categories_result;
	}

	/**
	 * Get WordPress custom post types for visibility settings
	 *
	 * @since 2.5.0
	 * @return array Array of post types with name (slug) and label
	 */
	private function get_wordpress_post_types(): array
	{
		$post_types = get_post_types(['public' => true], 'objects');
		$post_types_result = [];

		foreach ($post_types as $post_type) {
			if (in_array($post_type->name, self::EXCLUDED_POST_TYPES, true)) {
				continue;
			}
			$post_types_result[] = [
				'name'  => $post_type->name,
				'label' => $post_type->label,
			];
		}

		return $post_types_result;
	}

	/**
	 * Get review alerts data for React
	 *
	 * @return array
	 */
	public function get_popups_data(): array
	{
		$service = new SBR_Review_Alert_Service();
		$popups = $service->get_popups();

		return [
			'list'     => $popups['popups'],
			'count'    => $popups['total'],
			'defaults' => SBR_Review_Alert_Service::get_defaults(),
		];
	}

	/**
	 * Page output - renders the React app container
	 *
	 * @return void
	 */
	public function page_output(): void
	{
		// Show upsell for non-Pro Plus users - Notification Popup is a Pro Plus/Elite feature
		if (!Util::sbr_is_pro_plus()) {
			$this->render_pro_upsell();
			return;
		}
		?>
		<div id="sb-app" class="sb-fs"></div>
		<?php
	}

	/**
	 * Render Pro Plus upsell page for Free and Pro Basic users
	 * Uses the same layout as the Collections upsell page for consistency
	 *
	 * @since 2.5.0
	 * @return void
	 */
	private function render_pro_upsell(): void
	{
		$upgrade_url = 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=review-alert&utm_medium=admin-page&utm_content=UpgradeToProPlus';
		$assets_url  = SB_COMMON_ASSETS;
		?>
		<div id="sb-app" class="sb-fs">
			<!-- Header matching Collections page -->
			<section class="sb-dashboard-header sb-header sb-fs">
				<div class="sb-header-content sb-fs">
					<div class="sb-dashboard-header-logo">
						<svg width="180" height="36" viewBox="0 0 536 107" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M134.266 34.3858C128.863 30.3682 123.737 29.8141 120.551 29.8141C116.256 29.8141 112.377 30.7146 109.121 34.0394C106.351 36.8794 105.104 40.3428 105.104 44.4296C105.104 46.6462 105.45 49.9018 107.875 52.4647C109.676 54.4042 112.169 55.3739 114.316 56.1359L118.126 57.4519C119.442 57.9368 122.282 58.9758 123.529 60.0149C124.499 60.8461 125.122 61.8158 125.122 63.3397C125.122 65.0714 124.36 66.3182 123.46 67.0802C121.936 68.3963 119.996 68.6733 118.611 68.6733C116.464 68.6733 114.594 68.1192 112.793 67.0109C111.546 66.249 109.676 64.6558 108.498 63.4782L102.957 71.0977C104.688 72.8294 107.39 74.9767 109.745 76.1543C112.654 77.6089 115.563 78.0938 118.888 78.0938C121.936 78.0938 127.893 77.6782 131.98 73.3835C134.404 70.8899 136.067 66.7338 136.067 61.9544C136.067 59.2529 135.374 55.9281 132.672 53.3652C130.871 51.6335 128.378 50.5944 126.369 49.8325L122.906 48.5164C119.789 47.3388 118.195 46.9232 117.087 45.8842C116.394 45.2608 116.048 44.4296 116.048 43.3213C116.048 42.1438 116.533 41.1047 117.226 40.4121C118.473 39.0267 120.273 38.8189 121.728 38.8189C123.044 38.8189 126.023 39.0267 129.486 42.1438L134.266 34.3858Z" fill="#141B38"/>
							<path d="M140.486 77.0547H150.461V61.2617C150.461 60.2227 150.53 56.7593 152.4 54.9583C153.37 54.0578 154.548 53.7115 155.864 53.7115C156.903 53.7115 158.149 53.9193 159.188 54.9583C160.712 56.4822 160.782 58.9066 160.782 60.9846V77.0547H170.756V61.6773C170.756 59.6685 170.964 57.2441 172.28 55.5817C173.111 54.4734 174.497 53.7115 176.159 53.7115C177.614 53.7115 178.93 54.3349 179.761 55.3739C181.077 57.0363 181.077 59.807 181.077 61.4002V77.0547H191.052V57.5212C191.052 55.3046 190.844 51.1486 187.865 48.3086C185.995 46.5076 182.947 45.3993 179.207 45.3993C176.783 45.3993 174.774 45.815 172.626 47.131C170.618 48.3779 169.44 49.8325 168.678 51.0793C167.847 49.2091 166.531 47.6852 164.938 46.7847C162.86 45.5379 160.435 45.3993 159.119 45.3993C156.764 45.3993 153.024 45.8842 150.461 49.7632V46.1613H140.486V77.0547Z" fill="#141B38"/>
							<path d="M220.475 49.6247C217.635 45.7457 213.409 45.1223 210.777 45.1223C206.483 45.1223 202.742 46.6462 200.041 49.3476C197.201 52.1876 195.261 56.6207 195.261 61.8158C195.261 65.9026 196.508 69.9202 199.764 73.3835C203.158 76.9855 206.898 78.0938 211.331 78.0938C213.894 78.0938 217.773 77.4703 220.475 73.3143V77.0547H230.449V46.1613H220.475V49.6247ZM213.271 53.7115C215.072 53.7115 217.288 54.4042 218.812 55.8588C220.267 57.2441 221.098 59.3222 221.098 61.5387C221.098 64.1709 219.99 66.1104 218.674 67.3572C217.358 68.6733 215.487 69.5045 213.479 69.5045C211.124 69.5045 208.976 68.5348 207.591 67.0802C206.69 66.1104 205.513 64.3095 205.513 61.5387C205.513 58.768 206.76 56.9671 207.799 55.9281C209.115 54.612 211.124 53.7115 213.271 53.7115Z" fill="#141B38"/>
							<path d="M258.964 47.7545C256.47 46.2998 253.284 45.1223 248.712 45.1223C245.872 45.1223 242.132 45.6764 239.361 48.2393C237.56 49.9018 236.383 52.3954 236.383 55.3739C236.383 57.729 237.145 59.4607 238.599 60.9846C239.915 62.3007 241.785 63.2704 243.586 63.8246L246.08 64.5865C247.535 65.0021 248.435 65.2792 249.128 65.6948C250.028 66.249 250.236 66.9416 250.236 67.4958C250.236 68.2577 249.821 69.0197 249.197 69.5045C248.297 70.1972 246.703 70.1972 246.08 70.1972C244.764 70.1972 243.309 69.9202 241.924 69.2275C240.885 68.7426 239.5 67.7729 238.53 66.9416L234.305 73.6606C238.322 77.1933 242.824 78.0938 246.981 78.0938C250.236 78.0938 253.977 77.6089 257.024 74.5611C258.41 73.1757 260.211 70.5436 260.211 66.4568C260.211 64.1017 259.587 62.2314 257.786 60.569C256.193 59.1144 254.392 58.4217 252.661 57.8676L250.028 57.0363C248.781 56.6207 247.742 56.4129 247.05 55.9973C246.565 55.7203 246.08 55.3046 246.08 54.612C246.08 54.1271 246.357 53.573 246.703 53.2266C247.327 52.6032 248.504 52.3261 249.543 52.3261C251.483 52.3261 253.492 53.1573 255.016 54.0578L258.964 47.7545Z" fill="#141B38"/>
							<path d="M264.339 77.0547H274.313V61.608C274.313 60.0841 274.382 57.1056 276.391 55.1661C276.945 54.612 278.192 53.6422 280.339 53.6422C281.794 53.6422 283.318 54.1271 284.218 55.0276C285.812 56.5515 285.881 59.0451 285.881 61.1924V77.0547H295.855V57.4519C295.855 55.0968 295.648 51.2871 292.808 48.4471C290.106 45.6764 286.504 45.3301 284.08 45.3301C281.863 45.3301 280.132 45.5379 278.054 46.6462C276.876 47.2696 275.56 48.3086 274.313 49.8325V26.5585H264.339V77.0547Z" fill="#141B38"/>
							<path d="M316.822 30.8531V77.0547H334.208C337.048 77.0547 342.59 76.7777 346.399 73.1065C348.2 71.3055 350.001 68.3963 350.001 63.7553C350.001 59.6685 348.547 57.0363 347.023 55.5124C345.36 53.85 342.867 52.7417 340.65 52.3954C341.758 51.9105 343.352 50.9408 344.529 49.0013C345.776 46.9925 346.053 44.9145 346.053 43.1135C346.053 41.1047 345.707 37.2257 342.728 34.3858C339.126 30.9916 333.446 30.8531 330.953 30.8531H316.822ZM327.351 39.1652H329.013C330.953 39.1652 333.031 39.1652 334.555 40.3428C335.386 40.9662 336.355 42.213 336.355 44.2218C336.355 46.2306 335.455 47.6159 334.485 48.3086C332.961 49.4169 330.537 49.6247 329.082 49.6247H327.351V39.1652ZM327.351 57.5212H330.26C332.407 57.5212 336.009 57.5212 337.879 59.2529C338.572 59.8763 339.334 61.1231 339.334 62.9934C339.334 64.6558 338.78 65.9719 337.81 66.8724C335.871 68.6733 332.615 68.7426 329.914 68.7426H327.351V57.5212Z" fill="#141B38"/>
							<path d="M378.384 49.6247C375.544 45.7457 371.318 45.1223 368.686 45.1223C364.392 45.1223 360.651 46.6462 357.95 49.3476C355.11 52.1876 353.17 56.6207 353.17 61.8158C353.17 65.9026 354.417 69.9202 357.673 73.3835C361.067 76.9855 364.807 78.0938 369.24 78.0938C371.803 78.0938 375.682 77.4703 378.384 73.3143V77.0547H388.358V46.1613H378.384V49.6247ZM371.18 53.7115C372.981 53.7115 375.197 54.4042 376.721 55.8588C378.176 57.2441 379.007 59.3222 379.007 61.5387C379.007 64.1709 377.899 66.1104 376.583 67.3572C375.267 68.6733 373.396 69.5045 371.388 69.5045C369.033 69.5045 366.885 68.5348 365.5 67.0802C364.599 66.1104 363.422 64.3095 363.422 61.5387C363.422 58.768 364.669 56.9671 365.708 55.9281C367.024 54.612 369.033 53.7115 371.18 53.7115Z" fill="#141B38"/>
							<path d="M394.292 26.5585V77.0547H404.266V26.5585H394.292Z" fill="#141B38"/>
							<path d="M410.478 26.5585V77.0547H420.452V26.5585H410.478Z" fill="#141B38"/>
							<path d="M461.159 61.608C461.159 57.6597 459.635 53.2266 456.518 50.1096C453.747 47.3388 449.037 45.1223 443.011 45.1223C436.984 45.1223 432.274 47.3388 429.503 50.1096C426.386 53.2266 424.862 57.6597 424.862 61.608C424.862 65.5563 426.386 69.9894 429.503 73.1065C432.274 75.8772 436.984 78.0938 443.011 78.0938C449.037 78.0938 453.747 75.8772 456.518 73.1065C459.635 69.9894 461.159 65.5563 461.159 61.608ZM443.011 53.5729C445.296 53.5729 447.167 54.3349 448.621 55.7895C450.076 57.2441 450.907 59.1144 450.907 61.608C450.907 64.1017 450.076 65.9719 448.621 67.4265C447.167 68.8811 445.296 69.6431 443.08 69.6431C440.448 69.6431 438.647 68.6733 437.4 67.4265C436.222 66.249 435.114 64.448 435.114 61.608C435.114 59.1144 435.945 57.2441 437.4 55.7895C438.855 54.3349 440.725 53.5729 443.011 53.5729Z" fill="#141B38"/>
							<path d="M500.073 61.608C500.073 57.6597 498.549 53.2266 495.432 50.1096C492.661 47.3388 487.951 45.1223 481.925 45.1223C475.899 45.1223 471.189 47.3388 468.418 50.1096C465.301 53.2266 463.777 57.6597 463.777 61.608C463.777 65.5563 465.301 69.9894 468.418 73.1065C471.189 75.8772 475.899 78.0938 481.925 78.0938C487.951 78.0938 492.661 75.8772 495.432 73.1065C498.549 69.9894 500.073 65.5563 500.073 61.608ZM481.925 53.5729C484.211 53.5729 486.081 54.3349 487.536 55.7895C488.99 57.2441 489.822 59.1144 489.822 61.608C489.822 64.1017 488.99 65.9719 487.536 67.4265C486.081 68.8811 484.211 69.6431 481.994 69.6431C479.362 69.6431 477.561 68.6733 476.314 67.4265C475.137 66.249 474.028 64.448 474.028 61.608C474.028 59.1144 474.86 57.2441 476.314 55.7895C477.769 54.3349 479.639 53.5729 481.925 53.5729Z" fill="#141B38"/>
							<path d="M504.215 77.0547H514.19V61.1924C514.19 59.1836 514.467 56.9671 516.129 55.3046C516.891 54.4734 518.207 53.6422 520.285 53.6422C522.086 53.6422 523.333 54.2656 524.095 55.0276C525.688 56.6207 525.758 59.1836 525.758 61.1924V77.0547H535.732V57.5212C535.732 55.0276 535.524 51.3564 532.615 48.4471C529.983 45.815 526.45 45.3301 523.749 45.3301C520.839 45.3301 517.238 45.9535 514.19 49.8325V46.1613H504.215V77.0547Z" fill="#141B38"/>
							<path fill-rule="evenodd" clip-rule="evenodd" d="M80.3445 49.8911C80.3445 22.6311 62.4761 0.532379 40.4263 0.532379C18.3766 0.532379 0.5 22.6311 0.5 49.8911C0.5 76.0241 16.874 97.347 37.6089 99.1355L35.4039 106.118L49.2299 104.942L44.379 99.013C64.5667 96.563 80.3445 75.5096 80.3445 49.8911Z" fill="#FE544F"/>
							<path fill-rule="evenodd" clip-rule="evenodd" d="M49.9446 17.8853L51.8657 37.7078L71.7713 38.2796L57.372 51.6681L68.745 68.1175L49.5854 64.516L43.7769 83.676L34.9576 66.5183L17.1501 74.6779L24.0004 56.2844L6.633 47.5245L25.2243 41.5455L20.0942 23.2308L37.6829 33.2583L49.9446 17.8853Z" fill="white"/>
						</svg>
						<span class="sb-dashboard-header-haeding sb-text-small"> / <?php esc_html_e('Review Alerts', 'reviews-feed'); ?> </span>
					</div>
				</div>
			</section>

			<!-- Main content area -->
			<section class="sb-full-wrapper sb-fs">
				<section class="sb-small-wrapper sbr-review-alerts-upsell">
					<!-- Two-column layout matching Collections -->
					<div class="sb-single-collection-empty sb-fs">
						<div class="sb-single-collection-icon sb-fs">
							<img src="<?php echo esc_url($assets_url . 'sb-customizer/assets/images/popup-splash.png'); ?>" alt="<?php esc_attr_e('Review Alerts', 'reviews-feed'); ?>">
						</div>
						<div class="sb-single-collection-empty-text">
							<span class="sbr-pro-feature-label"><?php esc_html_e('Pro Feature', 'reviews-feed'); ?></span>
							<h3 class="sb-h3 sb-fs"><?php esc_html_e('Show review popups that turn visitors into customers', 'reviews-feed'); ?></h3>
							<p class="sb-small-p sb-light-text sb-fs"><?php esc_html_e('Animated notifications cycle through your best reviews in the corner of every page — like a live testimonial feed your visitors can\'t miss.', 'reviews-feed'); ?></p>
							<div class="sb-single-collection-utm-btns sb-fs">
								<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="sb-btn sb-btn-primary sb-btn-medium" style="text-decoration: none; color: #fff;">
									<span><?php esc_html_e('Upgrade to Pro', 'reviews-feed'); ?></span>
									<span class="sb-btn-icon" style="margin-left: 8px; display: flex; align-items: center;">
										<svg width="8" height="10" viewBox="0 0 7 10" xmlns="http://www.w3.org/2000/svg" style="width: 8px; height: 10px;">
											<path d="M1.5 1L5.5 5L1.5 9" style="fill: none;" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</span>
								</a>
								<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" class="sb-notice sb-notice-default sb-upgradecollection-lite-banner-btn" style="text-decoration: none;">
									<span class="sb-notice-icon">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
											<path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/>
										</svg>
									</span>
									<span class="sb-text-tiny">
										<strong class="sb-fs"><?php esc_html_e('Lite Plugin Users get 50% OFF.', 'reviews-feed'); ?></strong>
										<span class="sb-fs"><?php esc_html_e('(auto-applied at checkout)', 'reviews-feed'); ?></span>
									</span>
								</a>
							</div>
						</div>
					</div>

					<!-- How it works section -->
					<div class="sb-single-collection-empty-works sb-fs">
						<h4 class="sb-h4 sb-fs"><?php esc_html_e('How it works', 'reviews-feed'); ?></h4>
						<div class="sb-single-collection-steps sb-fs">
							<div class="sb-single-collection-step-item">
								<div class="sb-single-collection-step-item-icon">
									<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
										<mask id="mask0_6505_86256" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="40" height="40">
											<rect width="40" height="40" fill="#D9D9D9"/>
										</mask>
										<g mask="url(#mask0_6505_86256)">
											<path d="M20 24.125L23.4721 26.2363C23.7221 26.3935 23.9721 26.3818 24.2221 26.2012C24.4721 26.0207 24.5647 25.7776 24.5 25.4721L23.5833 21.5417L26.6667 18.8613C26.9075 18.6482 26.9769 18.4004 26.875 18.1179C26.7731 17.8357 26.5647 17.6807 26.25 17.6529L22.2083 17.3333L20.6388 13.6112C20.521 13.3335 20.3086 13.1946 20.0017 13.1946C19.695 13.1946 19.4815 13.3335 19.3613 13.6112L17.7917 17.3333L13.75 17.6529C13.4353 17.6807 13.2269 17.8357 13.125 18.1179C13.0231 18.4004 13.0925 18.6482 13.3333 18.8613L16.4167 21.5417L15.5 25.4721C15.4353 25.7776 15.5279 26.0207 15.7779 26.2012C16.0279 26.3818 16.2779 26.3935 16.5279 26.2363L20 24.125ZM14.4212 33.3333H9.44458C8.68069 33.3333 8.02667 33.0614 7.4825 32.5175C6.93861 31.9733 6.66667 31.3193 6.66667 30.5554V25.595L3.04167 21.9446C2.77306 21.6668 2.57403 21.3606 2.44458 21.0258C2.31486 20.6914 2.25 20.3511 2.25 20.005C2.25 19.6592 2.31486 19.3172 2.44458 18.9792C2.57403 18.6411 2.77306 18.3332 3.04167 18.0554L6.66667 14.405V9.44458C6.66667 8.68069 6.93861 8.02667 7.4825 7.4825C8.02667 6.93861 8.68069 6.66667 9.44458 6.66667H14.405L18.0554 3.04167C18.3332 2.77306 18.6457 2.57403 18.9929 2.44458C19.3401 2.31486 19.6869 2.25 20.0333 2.25C20.3794 2.25 20.7199 2.32125 21.0546 2.46375C21.3893 2.60625 21.6951 2.80819 21.9721 3.06958L25.5833 6.66667H30.5554C31.3193 6.66667 31.9733 6.93861 32.5175 7.4825C33.0614 8.02667 33.3333 8.68069 33.3333 9.44458V14.405L36.9583 18.0554C37.2269 18.3332 37.426 18.6394 37.5554 18.9742C37.6851 19.3086 37.75 19.6489 37.75 19.995C37.75 20.3408 37.6851 20.6828 37.5554 21.0208C37.426 21.3589 37.2269 21.6668 36.9583 21.9446L33.3333 25.595V30.5554C33.3333 31.3193 33.0614 31.9733 32.5175 32.5175C31.9733 33.0614 31.3193 33.3333 30.5554 33.3333H25.5833L21.9721 36.9029C21.6951 37.1551 21.3893 37.3501 21.0546 37.4879C20.7199 37.6257 20.3794 37.6946 20.0333 37.6946C19.6869 37.6946 19.3451 37.6257 19.0079 37.4879C18.6707 37.3501 18.3625 37.1551 18.0833 36.9029L14.4212 33.3333ZM15.5554 30.5554L20.0279 34.9167L24.4142 30.5554H30.5554V24.4167L34.9721 20L30.5554 15.5833V9.44458H24.4167L20.0279 5.02792L15.5833 9.44458H9.44458V15.5833L5.02792 20L9.44458 24.4167V30.5554H15.5554Z" fill="#0068A0"/>
										</g>
									</svg>
								</div>
								<div class="sb-single-collection-step-item-text">
									<strong class="sb-fs"><?php esc_html_e('Animated social proof', 'reviews-feed'); ?></strong>
									<p class="sb-small-p"><?php esc_html_e('Review popups cycle automatically in the corner of any page — a live testimonial feed visitors can\'t ignore.', 'reviews-feed'); ?></p>
								</div>
							</div>

							<div class="sb-single-collection-step-item">
								<div class="sb-single-collection-step-item-icon">
									<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
										<mask id="mask0_6505_86263" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="40" height="40">
											<rect width="40" height="40" fill="#D9D9D9"/>
										</mask>
										<g mask="url(#mask0_6505_86263)">
											<path d="M12.2221 27.3887C11.4721 27.3887 10.8217 27.1133 10.2708 26.5625C9.71999 26.0117 9.44457 25.3613 9.44457 24.6113V7.77792C9.44457 7.02792 9.71999 6.37736 10.2708 5.82625C10.8217 5.27542 11.4721 5 12.2221 5H33.8887C34.6387 5 35.2893 5.27542 35.8404 5.82625C36.3912 6.37736 36.6667 7.02792 36.6667 7.77792V24.6113C36.6667 25.3613 36.3912 26.0117 35.8404 26.5625C35.2893 27.1133 34.6387 27.3887 33.8887 27.3887H12.2221ZM12.2221 24.6113H33.8887V7.77792H12.2221V24.6113ZM28.3333 14.7779C28.713 14.7779 29.0394 14.6447 29.3125 14.3783C29.5855 14.1122 29.7221 13.7824 29.7221 13.3888C29.7221 13.0093 29.5855 12.6829 29.3125 12.4096C29.0394 12.1365 28.713 12 28.3333 12H17.7362C17.3565 12 17.0371 12.1365 16.7779 12.4096C16.5185 12.6829 16.3887 13.014 16.3887 13.4029C16.3887 13.7918 16.5219 14.1182 16.7883 14.3821C17.0544 14.646 17.3843 14.7779 17.7779 14.7779H28.3333ZM23.3054 20.3333C23.6851 20.3333 24.0115 20.2003 24.2846 19.9342C24.5579 19.6678 24.6946 19.3379 24.6946 18.9446C24.6946 18.5649 24.5579 18.2385 24.2846 17.9654C24.0115 17.6921 23.6851 17.5554 23.3054 17.5554H17.7362C17.3565 17.5554 17.0371 17.6921 16.7779 17.9654C16.5185 18.2385 16.3887 18.5694 16.3887 18.9583C16.3887 19.3472 16.5219 19.6736 16.7883 19.9375C17.0544 20.2014 17.3843 20.3333 17.7779 20.3333H23.3054ZM9.47207 35.0417C8.72207 35.1436 8.04513 34.9624 7.44124 34.4979C6.83735 34.0338 6.47735 33.4187 6.36124 32.6529L3.9029 14.6804C3.84735 14.301 3.93985 13.9585 4.1804 13.6529C4.42124 13.3474 4.73151 13.1668 5.11124 13.1112C5.48151 13.074 5.81485 13.1689 6.11124 13.3958C6.40735 13.6228 6.58318 13.926 6.63874 14.3054L9.19457 32.2779L21.9146 30.6771L29.5279 29.7083C29.9168 29.6436 30.264 29.7362 30.5696 29.9862C30.8751 30.2362 31.0464 30.5696 31.0833 30.9862C31.1203 31.3474 31.023 31.6737 30.7917 31.9654C30.5603 32.2571 30.2547 32.4307 29.875 32.4862L9.47207 35.0417Z" fill="#0068A0"/>
										</g>
									</svg>
								</div>
								<div class="sb-single-collection-step-item-text">
									<strong class="sb-fs"><?php esc_html_e('Show more reviews on click', 'reviews-feed'); ?></strong>
									<p class="sb-small-p"><?php esc_html_e('A user can click on the testimonial and more reviews open in a small window inline without breaking their flow.', 'reviews-feed'); ?></p>
								</div>
							</div>

							<div class="sb-single-collection-step-item">
								<div class="sb-single-collection-step-item-icon">
									<svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
										<mask id="mask0_6505_86270" style="mask-type:alpha" maskUnits="userSpaceOnUse" x="0" y="0" width="40" height="40">
											<rect width="40" height="40" fill="#D9D9D9"/>
										</mask>
										<g mask="url(#mask0_6505_86270)">
											<path d="M14.4721 34.2221L4.11123 23.8612C3.85179 23.5996 3.65734 23.3119 3.5279 22.9983C3.39817 22.6847 3.33331 22.3593 3.33331 22.0221C3.33331 21.6851 3.39817 21.3611 3.5279 21.05C3.65734 20.7389 3.85179 20.4537 4.11123 20.1946L14.25 10.0696L10.625 6.44456C10.338 6.15734 10.1922 5.81929 10.1875 5.4304C10.1828 5.04151 10.324 4.69901 10.6112 4.4029C10.8982 4.10651 11.2447 3.95831 11.6508 3.95831C12.0566 3.95831 12.4091 4.10651 12.7083 4.4029L28.5 20.1946C28.768 20.4537 28.9623 20.7389 29.0829 21.05C29.2035 21.3611 29.2637 21.6851 29.2637 22.0221C29.2637 22.3593 29.2035 22.6847 29.0829 22.9983C28.9623 23.3119 28.768 23.5996 28.5 23.8612L18.1387 34.2221C17.8796 34.4815 17.5944 34.676 17.2833 34.8054C16.9722 34.9351 16.6482 35 16.3112 35C15.974 35 15.6486 34.9351 15.335 34.8054C15.0214 34.676 14.7337 34.4815 14.4721 34.2221ZM16.3196 12.1387L6.4029 22.0554H26.2362L16.3196 12.1387ZM33.2125 35C32.2616 35 31.4561 34.6673 30.7958 34.0021C30.1355 33.3365 29.8054 32.521 29.8054 31.5554C29.8054 30.8187 29.9605 30.123 30.2708 29.4683C30.5811 28.8136 30.963 28.1946 31.4166 27.6112L32.4029 26.3333C32.6157 26.0647 32.8911 25.9282 33.2292 25.9237C33.5672 25.919 33.8426 26.051 34.0554 26.3196L35.0554 27.6112C35.4815 28.1946 35.8565 28.8136 36.1804 29.4683C36.5046 30.123 36.6666 30.8187 36.6666 31.5554C36.6666 32.521 36.3287 33.3365 35.6529 34.0021C34.9768 34.6673 34.1633 35 33.2125 35Z" fill="#0068A0"/>
										</g>
									</svg>
								</div>
								<div class="sb-single-collection-step-item-text">
									<strong class="sb-fs"><?php esc_html_e('Complete customisation', 'reviews-feed'); ?></strong>
									<p class="sb-small-p"><?php esc_html_e('Choose from 4 themes, any sources and filter reviews by words, stars or date. And more.', 'reviews-feed'); ?></p>
								</div>
							</div>
						</div>
					</div>
				</section>
			</section>
		</div>
		<?php
	}
}
