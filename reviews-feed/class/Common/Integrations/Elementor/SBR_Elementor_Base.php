<?php

namespace SmashBalloon\Reviews\Common\Integrations\Elementor;

use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\RecommendedElementorWidgets;
use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Blocks_Registry;
use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Block_Utils;
use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Editor_Assets;
use SmashBalloon\Reviews\Common\Customizer\DB;

class SBR_Elementor_Base {
	private static $instance;

	public static function register()
	{
		if (null === self::$instance) {
			self::$instance = new SBR_Elementor_Base();
			self::$instance->apply_hooks();
		}
		return self::$instance;
	}

	private function apply_hooks()
	{
		add_action('init', array( $this, 'init_elementor_integration' ), 4);
	}

	public function init_elementor_integration()
	{
		if (! did_action('elementor/loaded')) {
			return;
		}

		$recommended = new RecommendedElementorWidgets('reviews');
		$recommended->setup();

		$registry = SB_Feed_Blocks_Registry::instance();
		$registry->register_elementor_widget(
			array(
				'blockId'    => 'reviews',
				'widgetName' => 'sb-reviews-feed',
				'globalVar'  => 'sbrElementorData',
				'feedInitFn' => 'sbr_init',
			)
		);

		add_action('elementor/widgets/register', array( $this, 'register_widgets' ));
		add_action('elementor/frontend/after_register_scripts', array( $this, 'enqueue_frontend_assets' ));
		add_action('elementor/elements/categories_registered', array( $this, 'add_smashballoon_categories' ));

		add_action(
			'elementor/editor/after_enqueue_scripts',
			function () {
				SB_Elementor_Editor_Assets::enqueue_shared_elementor_styles(SBRVER);
			}
		);
	}

	public function register_widgets($widgets_manager)
	{
		$widgets_manager->register(new SBR_Modern_Elementor_Widget());
	}

	public function enqueue_frontend_assets()
	{
		sbr_scripts_enqueue();

		// SMASH-1052: skip the per-feed shortcode-location scans on this
		// sitewide front-end hook (every Elementor page). The localized
		// sbrElementorData.feeds payload only needs id + feed_name; the
		// instance_count / location_summary fields are never read here.
		$feeds = DB::get_feeds_list(array(), true);

		$data = array(
			'feeds'         => ! empty($feeds) ? $feeds : array(),
			'feed_url'      => admin_url('admin.php?page=sbr'),
			'is_pro_active' => function_exists('sbr_is_pro') && sbr_is_pro(),
			'nonce'         => wp_create_nonce('sbr-blocks'),
		);

		wp_localize_script('sbr_scripts', 'sbrElementorData', $data);

		SB_Feed_Blocks_Registry::instance()->enqueue_elementor_assets();
	}

	public function add_smashballoon_categories($elements_manager)
	{
		$elements_manager->add_category(
			SB_Block_Utils::CATEGORY_SLUG,
			array(
				'title' => esc_html__('Smash Balloon', 'reviews-feed'),
				'icon'  => 'fa fa-plug',
			)
		);
	}
}
