<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/get-feed
 *
 * @since SBR-499
 */
class SBR_Get_Feed extends SBR_Ability
{
	public function slug()
	{
		return 'get-feed';
	}

	public function args()
	{
		return array(
			'label'               => __('Get a Reviews Feed', 'reviews-feed'),
			'description'         => __(
				'Returns one feed\'s customer-configurable settings (layout, columns, header, sorting, filtering) and the ids of its attached sources. Use list-sources to resolve those ids to a provider and business/place name.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Feed id from list-feeds.',
					),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'       => array('type' => 'integer'),
					'feed_name'     => array('type' => 'string'),
					'status'        => array('type' => 'string'),
					'last_modified' => array('type' => 'string'),
					'sources'       => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Source account ids attached to this feed (see list-sources).',
					),
					'warnings'      => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Notes about stored values this edition cannot set (for example after a Pro to Free downgrade). Empty when there is nothing to flag.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					),
					'settings'      => array(
						'type'       => 'object',
						'properties' => SBR_Settings_Schema::to_json_properties(SBR_Settings_Schema::feed_settings_read()),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * @param mixed $input
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBR_Ability_Response::invalid_feed_id();
		}
		$feed = (new SBR_Feed_Settings_Service())->get_feed($feed_id);
		return $feed === null ? SBR_Ability_Response::feed_not_found($feed_id) : $feed;
	}
}
