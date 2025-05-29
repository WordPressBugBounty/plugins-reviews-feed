<?php
/**
 * SB_Recommended_Blocks
 *
 * @since 2.1
 */

namespace SmashBalloon\Reviews\Common\Admin\Blocks;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\RecommendedBlocks;

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

class SB_Recommended_Blocks extends ServiceProvider
{

	/**
	 * Register Reviews Block
	 *
	 * @return void
	 */
	public function register()
	{
		add_action(
			'init',
			[
				$this,
				'register_blocks'
			]
		);
	}

	/**
	 * Register Recommended Blocks
	 *
	 * @return void
	 */
	function register_blocks()
	{
		$recommended_blocks = new RecommendedBlocks();
		$recommended_blocks->setup();
	}
}