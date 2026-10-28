<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/list-feeds
 *
 * @since SBR-499
 */
class SBR_List_Feeds extends SBR_Ability
{
	public function slug()
	{
		return 'list-feeds';
	}

	public function args()
	{
		$summary = array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array('type' => 'integer', 'description' => 'The feed id (canonical key, same as the other Smash Balloon plugins).'),
				'feed_id'       => array('type' => 'integer', 'description' => 'Deprecated alias of `id`; same value. Use `id`.'),
				'feed_name'     => array('type' => 'string'),
				'status'        => array('type' => 'string'),
				'last_modified' => array('type' => 'string'),
				'source_count'  => array('type' => 'integer'),
			),
		);

		return array(
			'label'               => __('List Reviews Feeds', 'reviews-feed'),
			'description'         => __(
				'Lists the feeds created in the Feed Builder with their `id` (`feed_id` is a deprecated alias of it), name, status and how many sources are attached. 20 per page — check total_pages before assuming this is every feed. Newest-modified first; feeds created, deleted or saved while you page can shift the results, so re-check `total` after the last page.',
				'reviews-feed'
			),
			'input_schema'        => array(
				// null is accepted because a bare GET passes null input (all params are optional).
				'type'                 => array('object', 'null'),
				'properties'           => array(
					'page' => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feeds'       => array('type' => 'array', 'items' => $summary),
					'page'        => array('type' => 'integer'),
					'total'       => array('type' => 'integer', 'description' => 'Total feeds across all pages.'),
					'per_page'    => array('type' => 'integer'),
					'total_pages' => array('type' => 'integer'),
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
		$input  = $this->normalize_input($input);
		$page   = isset($input['page']) ? max(1, (int) $input['page']) : 1;
		$result = (new SBR_Feed_Settings_Service())->list_feeds($page);

		return array(
			'feeds'       => $result['feeds'],
			'page'        => $page,
			'total'       => $result['total'],
			'per_page'    => $result['per_page'],
			'total_pages' => $result['total_pages'],
		);
	}
}
