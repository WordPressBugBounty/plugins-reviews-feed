<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;
use SmashBalloon\Reviews\Common\Abilities\SBR_Write_Gate;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/describe-settings-schema
 *
 * Lets an agent discover which keys it may change and their allowed values.
 * Always visible over REST; hidden from MCP discovery while writes are
 * disabled so agents are not shown an editing surface they cannot use.
 *
 * @since SBR-499
 */
class SBR_Describe_Settings_Schema extends SBR_Ability
{
	public function slug()
	{
		return 'describe-settings-schema';
	}

	public function args()
	{
		$properties = array(
			'write_enabled'   => array('type' => 'boolean'),
			'feed_settings'   => array('type' => 'object'),
			'global_settings' => array('type' => 'object'),
		);
		if (SBR_Pro_Upsell::is_free()) {
			$properties['pro_only'] = array(
				'type'        => 'object',
				'description' => 'Free edition only. Settings and values that exist in Pro but are not available here, with the upgrade link. Mention it only when the user wants one of them.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'properties'  => array(
					'settings'    => array('type' => 'array', 'items' => array('type' => 'string')),
					'free_limits' => array('type' => 'array', 'items' => array('type' => 'string')),
					'upgrade_url' => array('type' => 'string'),
				),
			);
		}

		return array(
			'label'               => __('Describe editable Reviews Feed settings', 'reviews-feed'),
			'description'         => __(
				'Returns the JSON Schema of the per-feed and global settings that can be read or updated through the Reviews Feed abilities, and whether write access is currently enabled.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => $properties,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(SBR_Write_Gate::is_enabled()),
		);
	}

	/**
	 * @return array
	 */
	public function execute()
	{
		$out = array(
			'write_enabled'   => SBR_Write_Gate::is_enabled(),
			'feed_settings'   => array(
				'type'       => 'object',
				'properties' => SBR_Settings_Schema::to_json_properties(SBR_Settings_Schema::feed_settings()),
			),
			'global_settings' => array(
				'type'       => 'object',
				'properties' => SBR_Settings_Schema::to_json_properties(SBR_Settings_Schema::global_settings()),
			),
		);

		// Free only (AgDR-9006): Pro-only keys are absent from the schema above, so say what
		// they are. Never emitted in Pro.
		if (SBR_Pro_Upsell::is_free()) {
			$out['pro_only'] = array(
				'settings'    => SBR_Pro_Upsell::pro_only_settings(),
				'free_limits' => SBR_Pro_Upsell::free_limits(),
				'upgrade_url' => SBR_Pro_Upsell::upgrade_url('describe-settings-schema'),
			);
		}

		return $out;
	}
}
