<?php

/**
 * Class Feeds Settings Tab
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\Settings\Tabs;

use Smashballoon\Customizer\V2\SB_SettingsPage_Tab;
use SmashBalloon\Reviews\Common\Integrations\SBR_GDPR;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SBR_Feeds_Tab extends SB_SettingsPage_Tab {
	/**
	 * Get the Settings Tab info
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id' => 'sb-feeds-tab',
			'name' => __('Feeds', 'reviews-feed')
		];
	}

	/**
	* Get the Settings Tab Section
	*
	* @since 1.0
	*
	* @return array
	*/
	protected function tab_sections()
	{
		return [
			'caching_section' => [
				'type' => 'caching',
				'heading' => __('Caching', 'reviews-feed'),
			],
			'gdpr_section' => [
				'id' => 'gdpr',
				'type' => 'gdpr',
				'gdpr_plugins' => SBR_GDPR::gdpr_plugins_active(),
				'heading' => __('GDPR', 'reviews-feed'),
				'options' => [
					'auto' => __('Automatic', 'reviews-feed'),
					'yes' => __('Yes', 'reviews-feed'),
					'no' => __('No', 'reviews-feed')
				],
				'separator' => true
			],
		];
	}
}
