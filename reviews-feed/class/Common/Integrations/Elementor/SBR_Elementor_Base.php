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
		add_action('elementor/preview/enqueue_styles', array( $this, 'enqueue_badge_styles_for_preview' ));
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

		// SMASH-2173: editor data for the "Select a Feed" placeholder, which only renders in
		// edit/preview mode (SB_Elementor_Feed_Widget::render). Public pages get none of it.
		if (self::is_elementor_editor_request()) {
			wp_localize_script('sbr_scripts', 'sbrElementorData', self::editor_data());
		}

		SB_Feed_Blocks_Registry::instance()->enqueue_elementor_assets();
	}

	/**
	 * Payload for sb-elementor-editor.js, which reads only feeds[].id, feeds[].feed_name,
	 * feed_url and is_pro_active. `nonce` has no reader in that bundle; kept for BC.
	 *
	 * @param mixed $feeds Feed rows; null reads them from the DB.
	 *
	 * @return array<string, mixed>
	 */
	public static function editor_data($feeds = null)
	{
		if (null === $feeds) {
			// SMASH-1052: skip the per-feed shortcode-location scans.
			$feeds = DB::get_feeds_list(array(), true);
		}

		return array(
			'feeds'         => self::editor_feeds($feeds),
			'feed_url'      => admin_url('admin.php?page=sbr'),
			'is_pro_active' => function_exists('sbr_is_pro') && sbr_is_pro(),
			'nonce'         => wp_create_nonce('sbr-blocks'),
		);
	}

	/**
	 * Reduce feed rows to what the placeholder reads: settings, sources and CSS stay server-side.
	 *
	 * @param mixed $feeds Rows from DB::get_feeds_list().
	 *
	 * @return array<int, array{id: mixed, feed_name: mixed}>
	 */
	public static function editor_feeds($feeds)
	{
		$feeds_list = array();
		foreach (is_array($feeds) ? $feeds : array() as $feed) {
			if (is_array($feed) && isset($feed['id'])) {
				$feeds_list[] = array(
					'id'        => $feed['id'],
					'feed_name' => isset($feed['feed_name']) ? $feed['feed_name'] : '',
				);
			}
		}

		return $feeds_list;
	}

	/**
	 * Same gate as the placeholder it feeds (SB_Elementor_Feed_Widget::render).
	 *
	 * @return bool
	 */
	public static function is_elementor_editor_request()
	{
		if (! class_exists('\Elementor\Plugin') || empty(\Elementor\Plugin::$instance)) {
			return false;
		}
		$elementor = \Elementor\Plugin::$instance;

		return is_admin()
			|| (! empty($elementor->editor) && $elementor->editor->is_edit_mode())
			|| (! empty($elementor->preview) && $elementor->preview->is_preview_mode());
	}

	/**
	 * Badge styles for the Elementor editor preview.
	 *
	 * A badge placed through Elementor's Shortcode element enqueues its styles
	 * while the shortcode runs, which on the front end still reaches the footer.
	 * The preview iframe drops it, so the author was shown an unstyled badge —
	 * a 6475px-tall star — over a front end that was correct all along. Preview
	 * only: on the front end the render-time enqueue already works, and loading
	 * this on every Elementor page would be payload nobody asked for.
	 *
	 * @return void
	 */
	public function enqueue_badge_styles_for_preview()
	{
		sbr_trust_badge_enqueue_styles();
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
