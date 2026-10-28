<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Source_Service;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/list-sources
 *
 * @since SBR-499
 */
class SBR_List_Sources extends SBR_Ability
{
	public function slug()
	{
		return 'list-sources';
	}

	public function args()
	{
		// Free lists only the providers its edition can use (see the provider enum),
		// so the description and the `provider` enum are derived from the same
		// tier call the rest of the module uses rather than restated.
		$provider_schema = array('type' => 'string');
		if (!Util::sbr_is_pro()) {
			$provider_schema['enum'] = SBR_Source_Service::allowed_providers();
			$description             = __('Lists the review sources connected to this site (providers available in this edition only) — provider, business/place name, rating, review count, how many feeds use it. 20 per page; check `total` to know whether to page further. Never returns API keys or access tokens.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		} else {
			$description = __('Lists the review sources (Google, Yelp, TripAdvisor, Facebook, WooCommerce, …) connected to this site — provider, business/place name, rating, review count, how many feeds use it. 20 per page; check `total` to know whether to page further. Never returns API keys or access tokens.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		}

		return array(
			'label'               => __('List connected review sources', 'reviews-feed'),
			'description'         => $description,
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
					'sources' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'            => array('type' => 'string'),
								'provider'      => $provider_schema,
								'name'          => array('type' => 'string'),
								'rating'        => array('type' => 'number'),
								'review_count'  => array('type' => 'integer'),
								'has_error'     => array('type' => 'boolean'),
								'used_in_feeds' => array('type' => 'integer'),
							),
						),
					),
					'page'    => array('type' => 'integer'),
					'total'   => array('type' => 'integer', 'description' => 'Total connected sources across every page.'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * @param mixed $input
	 * @return array
	 */
	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$page    = isset($input['page']) ? (int) $input['page'] : 1;
		$service = new SBR_Source_Service();

		return array(
			'sources' => $service->list_sources($page),
			'page'    => max(1, $page),
			'total'   => $service->count_sources(),
		);
	}
}
