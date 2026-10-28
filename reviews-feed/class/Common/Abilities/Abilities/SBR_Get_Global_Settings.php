<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Global_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/get-global-settings
 *
 * @since SBR-499
 */
class SBR_Get_Global_Settings extends SBR_Ability
{
	public function slug()
	{
		return 'get-global-settings';
	}

	public function args()
	{
		return array(
			'label'               => __('Get Reviews Feed global settings', 'reviews-feed'),
			'description'         => __(
				'Returns the plugin-wide Reviews Feed settings (image optimization, script loading, review schema markup). Never returns provider API keys, source access tokens or license data.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => SBR_Settings_Schema::to_json_properties(SBR_Settings_Schema::global_settings()),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * @return array
	 */
	public function execute()
	{
		return (new SBR_Global_Settings_Service())->get();
	}
}
