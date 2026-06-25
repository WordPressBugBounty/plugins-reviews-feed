<?php

namespace SmashBalloon\Reviews\Common\Admin\Blocks;

use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Block;
use SmashBalloon\Reviews\Common\Customizer\DB;

class SBR_Modern_Feed_Block extends SB_Feed_Block {
	const EDITOR_ASSETS_PRIORITY = 25;

	protected function get_block_name()
	{
		return 'smashballoon/reviews-feed';
	}

	protected function get_shortcode_tag()
	{
		return 'reviews-feed';
	}

	protected function get_script_handle()
	{
		return 'sb-feed-blocks';
	}

	protected function get_text_domain()
	{
		return 'reviews-feed';
	}

	protected function get_plugin_dir()
	{
		return trailingslashit(SBR_PLUGIN_DIR);
	}

	protected function get_enqueue_scripts_action()
	{
		return 'sbr_enqueue_scripts';
	}

	protected function get_localize_var_name()
	{
		return 'sbReviewsFeedBlock';
	}

	protected function get_feed_block_id()
	{
		return 'reviews';
	}

	protected function get_init_function()
	{
		return 'sbr_init';
	}

	protected function get_block_dir()
	{
		return $this->get_plugin_dir() . 'vendor/smashballoon/framework/Packages/Blocks/dist/feed-blocks/reviews';
	}

	public function register_hooks()
	{
		add_action('sbr_enqueue_scripts', 'sbr_scripts_enqueue');

		parent::register_hooks();
		remove_action('enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ));
		add_action('enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ), self::EDITOR_ASSETS_PRIORITY);
	}

	protected function get_editor_localize_data()
	{
		// Skip the shortcode-location scan: the block editor feed picker only needs
		// each feed's id and name, never location/instance data. Without this the
		// editor runs an unindexed full-table LIKE over post_content on every load
		// and hangs on large sites. Mirrors SB_Reviews_Blocks::get_feed_list_options()
		// (SMASH-1052). See SMASH-1591.
		$feeds = DB::get_feeds_list(array(), true);

		return array(
			'feeds'    => ! empty($feeds) ? $feeds : array(),
			'feed_url' => admin_url('admin.php?page=sbr'),
			'nonce'    => wp_create_nonce('sbr-blocks'),
		);
	}
}
