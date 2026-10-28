<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/set-feed-sources
 *
 * Destructive by annotation: changing sources changes what a feed shows, so
 * MCP clients that honour annotations (WPVibe) show their approval panel.
 *
 * @since SBR-499
 */
class SBR_Set_Feed_Sources extends SBR_Ability
{
	public function slug()
	{
		return 'set-feed-sources';
	}

	public function args()
	{
		return array(
			'label'               => __('Set the sources a Reviews Feed shows', 'reviews-feed'),
			'description'         => __('Replaces a feed\'s source list with already-connected review sources (see list-sources; Reviews Feed has no agent-completable connect link for adding a NEW source — that always happens in wp-admin). Changes what the feed displays. Requires AI write access.', 'reviews-feed') . ' ' . $this->source_tier_note(), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
					'sources' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'minItems'    => 1,
						'uniqueItems' => true,
						'maxItems'    => 20,
						'description' => 'Source ids of already-connected review sources (see list-sources).',
					),
				),
				'required'             => array('feed_id', 'sources'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(
				array(
					'feed_id' => array('type' => 'integer'),
					'sources' => array('type' => 'array', 'items' => array('type' => 'string')),
				)
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(true, true),
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
		$requested = isset($input['sources']) && is_array($input['sources'])
			? array_map('strval', $input['sources'])
			: array();

		$resolved = (new SBR_Source_Service())->resolve_allowed_sources($requested);

		// Fail CLOSED, before any write: a request naming a source that does not
		// exist is almost always a mistake (a typo'd id, a source deleted since
		// list-sources was last called), and silently persisting whatever DID
		// resolve — including an empty array — would detach every existing
		// source from the feed on a partial match. Same posture as the
		// canonical Instagram Feed port's set-feed-sources.
		if (!empty($resolved['missing'])) {
			return SBR_Ability_Response::source_not_found($resolved['missing']);
		}
		if (empty($resolved['ids'])) {
			return SBR_Ability_Response::no_valid_sources();
		}

		$ignored = array();
		foreach ($resolved['ignored'] as $id => $reason) {
			$ignored[$id] = $reason;
		}

		$result = (new SBR_Feed_Settings_Service())->set_feed_sources($feed_id, $resolved['ids']);
		if (is_wp_error($result)) {
			return $result;
		}

		return array_merge(
			$result,
			array('feed_id' => $feed_id, 'ignored' => (object) $ignored, 'warnings' => array())
		);
	}
}
