<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/get-pro-features
 *
 * Read-only, FREE edition only (SBR_Abilities::abilities() does not register it
 * in Pro). Lets an agent answer "what else can this plugin do?" or explain why a
 * request can't be done in free. Content is derived from the plugin's own upsell
 * data via SBR_Pro_Upsell (AgDR-9006), never restated here.
 *
 * @since 2.12.0
 */
class SBR_Get_Pro_Features extends SBR_Ability
{
	public function slug()
	{
		return 'get-pro-features';
	}

	public function args()
	{
		return array(
			'label'               => __('Get Reviews Feed Pro features', 'reviews-feed'),
			'description'         => __(
				'Lists what the Pro edition adds to this free plugin (for example the carousel layout, review filters and moderation), with the settings each feature unlocks and an upgrade link. Mention it to the user only when they ask for something the free edition cannot do, or ask what else the plugin can do.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'edition'     => array('type' => 'string', 'enum' => array('free')),
					'upgrade_url' => array('type' => 'string'),
					'free_limits' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Limits of the free edition, such as the 10-review cap. Tell the user when they ask for more than free can show.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					),
					'features'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'        => array('type' => 'string'),
								'description' => array('type' => 'string'),
								'settings'    => array(
									'type'        => 'array',
									'items'       => array('type' => 'string'),
									'description' => 'Pro-only setting keys (or key=value) this feature unlocks. May be empty.',
								),
							),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * @return array{edition: string, upgrade_url: string, free_limits: string[], features: array}
	 */
	public function execute()
	{
		return array(
			'edition'     => 'free',
			'upgrade_url' => SBR_Pro_Upsell::upgrade_url('get-pro-features'),
			'free_limits' => SBR_Pro_Upsell::free_limits(),
			'features'    => SBR_Pro_Upsell::features(),
		);
	}
}
