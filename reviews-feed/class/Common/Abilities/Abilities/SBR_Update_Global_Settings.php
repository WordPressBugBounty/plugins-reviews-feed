<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Global_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/update-global-settings
 *
 * Gated by SBR_Write_Gate (off by default), then the same capability as the
 * Settings page. Unknown keys are rejected by core (additionalProperties:false);
 * read-only keys are reported in `ignored`.
 *
 * @since SBR-499
 */
class SBR_Update_Global_Settings extends SBR_Ability
{
	public function slug()
	{
		return 'update-global-settings';
	}

	public function args()
	{
		$writable = SBR_Settings_Schema::writable(SBR_Settings_Schema::global_settings());

		return array(
			'label'               => __('Update Reviews Feed global settings', 'reviews-feed'),
			'description'         => __('Updates one or more plugin-wide Reviews Feed settings (image optimization, script loading, review schema markup). Requires AI write access to be enabled in Reviews Feed → Settings → General. Returns the keys that were updated and the keys that were ignored.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => SBR_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
		);
	}

	/**
	 * @param mixed $input
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		return (new SBR_Global_Settings_Service())->update($this->normalize_input($input));
	}
}
