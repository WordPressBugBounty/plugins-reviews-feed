<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/set-header-button-link
 *
 * Coupled pair (AgDR-0504 finding A7 / requirement 2, canonical shape from
 * Twitter's set-feed-type-and-terms): `headerButtonLinkTo` and
 * `headerButtonExternalLink` are read-only in update-feed-settings /
 * create-feed and mutated only here, which refuses the incomplete pair
 * (`link_to: external` with no `external_link`) instead of letting it
 * validate and store a button with nowhere to go.
 *
 * @since SBR-499
 */
class SBR_Set_Header_Button_Link extends SBR_Ability
{
	public function slug()
	{
		return 'set-header-button-link';
	}

	public function args()
	{
		return array(
			'label'               => __('Set where the header button links to', 'reviews-feed'),
			'description'         => __('Sets the header "Write a review" button\'s destination: google (a connected Google source\'s review link) or external (a URL you provide via external_link). Requires AI write access.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id'       => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Feed id from list-feeds.',
					),
					'link_to'       => array(
						'type' => 'string',
						'enum' => array('google', 'external'),
					),
					'external_link' => array(
						'type'        => 'string',
						'maxLength'   => 500,
						'description' => 'Required when link_to is "external". Ignored otherwise.',
					),
				),
				'required'             => array('feed_id', 'link_to'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(
				array(
					'feed_id' => array('type' => 'integer'),
					'link_to' => array('type' => 'string'),
				)
			),
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
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBR_Ability_Response::invalid_feed_id();
		}
		$link_to = isset($input['link_to']) ? (string) $input['link_to'] : '';
		if (!in_array($link_to, array('google', 'external'), true)) {
			return SBR_Ability_Response::invalid_link_to();
		}
		$external_link = isset($input['external_link']) && is_string($input['external_link'])
			? sanitize_text_field($input['external_link'])
			: '';

		// Refuse the incomplete pair rather than storing a link_to with
		// nowhere to go (AgDR-0504 finding A7) — same posture as Twitter's
		// set-feed-type-and-terms refusing a coupled write it cannot complete.
		if ($link_to === 'external' && $external_link === '') {
			return SBR_Ability_Response::external_link_required();
		}

		$clean = array('headerButtonLinkTo' => $link_to);
		if ($link_to === 'external') {
			$clean['headerButtonExternalLink'] = $external_link;
		}

		$result = (new SBR_Feed_Settings_Service())->update_feed_settings($feed_id, $clean);
		if (is_wp_error($result)) {
			return $result;
		}

		return array_merge(
			array('feed_id' => $feed_id, 'link_to' => $link_to),
			$result,
			array('ignored' => (object) array(), 'warnings' => array())
		);
	}
}
