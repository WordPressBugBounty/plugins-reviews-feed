<?php

/**
 * SB_Reviews_Blocks
 *
 * @since 2.1
 */

namespace SmashBalloon\Reviews\Common\Admin\Blocks;

use SmashBalloon\Reviews\Common\Customizer\DB;
use Smashballoon\Stubs\Services\ServiceProvider;

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

class SB_Reviews_Blocks extends ServiceProvider
{
	/**
	 * Register Reviews Block
	 *
	 * @return void
	 */
	public function register()
	{
		if ($this->allow_load()) {
			$this->load();
		}
	}

	/**
	 * Indicates if current integration is allowed to load.
	 *
	 * @since 2.1
	 *
	 * @return bool
	 */
	public function allow_load()
	{
		return function_exists('register_block_type');
	}

	/**
	 * Loads an integration.
	 *
	 * @since 2.1
	 */
	public function load()
	{
		$this->hooks();

		$modern_block = new SBR_Modern_Feed_Block();
		$modern_block->register_hooks();
	}
	/**
	 * Integration hooks.
	 *
	 * @since 2.1
	 */
	protected function hooks()
	{
		add_action(
			'init',
			[
				$this,
				'register_block'
			]
		);

		add_action(
			'enqueue_block_editor_assets',
			[
				$this,
				'enqueue_block_editor_assets'
			],
			SBR_Modern_Feed_Block::EDITOR_ASSETS_PRIORITY
		);

		add_action(
			'enqueue_block_assets',
			array(
				$this,
				'enqueue_block_content_assets',
			)
		);

		add_filter( 'block_editor_settings_all', array( $this, 'inject_iframe_styles' ) );
	}

	/**
	 * Inject block UI and feed CSS into the WP 7.0+ iframed editor canvas.
	 *
	 * `block_editor_settings_all` exposes a `styles` array that WordPress renders
	 * inline inside the iframe `<head>`. wp_enqueue_style on the outer admin page
	 * does not propagate to the iframe for api_version 3 blocks, so we have to
	 * push the CSS contents through this filter for it to be visible inside the
	 * iframe.
	 *
	 * @param array $settings Block editor settings.
	 * @return array
	 */
	public function inject_iframe_styles( $settings ) {
		// Cache the CSS payload across the request lifecycle. block_editor_settings_all
		// fires on every block-editor request (post editor, site editor, widget editor)
		// and the CSS bytes on disk don't change between calls, so re-reading them is
		// wasteful disk I/O on the hottest path of the editor.
		// TODO: also scope this by screen so we only inject when the editor could host
		// this plugin's blocks. Scoping is intentionally skipped for now because
		// block_editor_settings_all fires in REST contexts where get_current_screen()
		// is unreliable, and over-scoping would re-break the iframe styling fix.
		static $cached = null;

		if ( null === $cached ) {
			$files = array(
				trailingslashit( SBR_PLUGIN_DIR ) . 'assets/css/sbr-styles.min.css',
			);

			$cached = array();
			foreach ( $files as $file ) {
				if ( ! file_exists( $file ) ) {
					continue;
				}
				$css = file_get_contents( $file );
				if ( false === $css ) {
					continue;
				}
				$cached[] = array( 'css' => $css );
			}
		}

		if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
			$settings['styles'] = array();
		}

		foreach ( $cached as $entry ) {
			$settings['styles'][] = $entry;
		}

		return $settings;
	}

	/**
	 * Register Reviews Feed Gutenberg block on the backend.
	 *
	 * @since 2.1
	 */
	public function register_block()
	{
		$attributes = array(
			'shortcodeSettings' => [
				'type' => 'string',
			],
			'noNewChanges' => [
				'type' => 'boolean',
			],
			'executed' => [
				'type' => 'boolean',
			]
		);

		// Register stylesheet for block editor iframe
		$min = !empty($_GET['sb_debug']) ? '' : '.min';
		wp_register_style(
			'sbr-block-styles',
			trailingslashit(SBR_PLUGIN_URL) . 'assets/css/sbr-styles' . $min . '.css',
			[],
			SBRVER
		);

		register_block_type(
			'sbr/sbr-feed-block',
			array(
				'api_version'     => 3,
				'attributes'      => $attributes,
				'render_callback' => [$this, 'get_feed_html'],
				'editor_style'    => 'sbr-block-styles',
				'supports'        => array( 'inserter' => false ),
			)
		);
	}

	/**
	 * Enqueue feed frontend assets so the legacy block preview renders inside
	 * the WP 6.7+ iframe block editor. Mirrors SB_Feed_Block::enqueue_block_content_assets().
	 *
	 * @since 2.5.1
	 */
	public function enqueue_block_content_assets() {
		if ( ! is_admin() ) {
			return;
		}
		sbr_scripts_enqueue( true );
	}

	/**
	 * Get Feeds List Options Array
	 *
	 * @return array
	 */
	public function get_feed_list_options()
	{
		$result = [
			[
				'value' => '',
				'label' => __('Select a Feed', 'reviews-feed'),
				'disabled' => true
			]
		];
		$feeds = DB::get_feeds_list([], true);
		if (is_array($feeds)) {
			foreach ($feeds as $feed) {
				array_push(
					$result,
					[
						'value'	=> $feed['id'],
						'label' => $feed['feed_name']
					]
				);
			}
		}
		return $result;
	}

	/**
	 * Load Reviews Feed Gutenberg block scripts.
	 *
	 * @since 2.1
	 */
	public function enqueue_block_editor_assets()
	{
		wp_enqueue_script(
			'sbr-feed-block',
			trailingslashit(SBR_PLUGIN_URL) . 'assets/js/sbr-blocks.js',
			array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor' ),
			SBRVER,
			true
		);

		$shortcodeSettings = '';
		$feeds_list_option = $this->get_feed_list_options();

		$is_script_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ! empty( $_GET['sb_debug'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only debug flag, no state change.

		$sbr_js_file = $is_script_debug
			? 'assets/js/sbr-feed.js'
			: 'assets/js/sbr-feed.min.js';

		$jquery_file = 'js/jquery/jquery' . ( $is_script_debug ? '' : '.min' ) . '.js';

		$sbr_options = array(
			'adminAjaxUrl' => admin_url( 'admin-ajax.php' ),
		);

		$i18n = array(
			'addSettings'         => esc_html__('Add Settings', 'reviews-feed'),
			'shortcodeSettings'   => esc_html__('Shortcode Settings', 'reviews-feed'),
			'example'             => esc_html__('Example', 'reviews-feed'),
			'preview'             => esc_html__('Apply Changes', 'reviews-feed'),
			'selectFeedLabel'     => esc_html__('Select a Feed', 'reviews-feed'),
		);

		if (!empty($_GET['sbr_wizard'])) {
			$shortcodeSettings = 'feed="' . (int) sanitize_text_field(wp_unslash($_GET['sbr_wizard'])) . '"';
		}

		wp_localize_script(
			'sbr-feed-block',
			'sbr_block_editor',
			[
				'wpnonce'  				=> wp_create_nonce('reviews-blocks'),
				'canShowFeed' 			=> true,
				'configureLink' 		=> admin_url('admin.php?page=sbr-settings'),
				'shortcodeSettings'    	=> $shortcodeSettings,
				'feedsListOption'    	=> $feeds_list_option,
				'i18n'     				=> $i18n,
				'iframeScriptUrl'   => trailingslashit( SBR_PLUGIN_URL ) . $sbr_js_file,
				'jqueryUrl'         => includes_url( $jquery_file ),
				'sbrOptions'        => $sbr_options,
			]
		);
	}

	/**
	 * Get form HTML to display in a Reviews Feed Gutenberg block.
	 *
	 * @param array $attr Attributes passed by Reviews Feed Gutenberg block.
	 *
	 * @since 2.1
	 *
	 * @return string
	 */
	public function get_feed_html($attr)
	{
		$return = '';

		$shortcode_settings = isset($attr['shortcodeSettings'])
			? $attr['shortcodeSettings']
			: '';


		if (
			empty($shortcode_settings) ||
			(
				strpos($shortcode_settings, 'feed=') === false &&
				! is_numeric($shortcode_settings)
			)
		) {
			$feeds = DB::get_feeds_list([], true);
			if (!empty($feeds[0]['id'])) {
				$shortcode_settings = 'feed="' . (int) $feeds[0]['id'] . '"';
			}
		} elseif (is_numeric($shortcode_settings)) {
			$shortcode_settings = 'feed="' . (int) $shortcode_settings . '"';
		}



		$shortcode_settings = str_replace(
			[
				'[reviews-feed',
				']'
			],
			' ',
			$shortcode_settings
		);

		$return .= do_shortcode('[reviews-feed ' . $shortcode_settings . ']');

		return $return;
	}

	/**
	 * Checking if is Gutenberg REST API call.
	 *
	 * @since 2.1
	 *
	 * @return bool True if is Gutenberg REST API call.
	 */
	public static function is_gb_editor()
	{
		return defined('REST_REQUEST') &&
			REST_REQUEST &&
			! empty($_REQUEST['context']) &&
			'edit' === $_REQUEST['context']; // phpcs:ignore
	}

}
