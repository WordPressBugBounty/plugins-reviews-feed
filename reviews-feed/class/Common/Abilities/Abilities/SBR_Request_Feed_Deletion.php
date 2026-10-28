<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\SBR_State_Token;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/request-feed-deletion
 *
 * Never deletes anything itself: it returns a browser confirmation link. The
 * deletion happens only when the logged-in admin clicks Confirm on the
 * wp-admin screen behind that link (see SBR_Delete_Confirmation).
 *
 * @since SBR-499
 */
class SBR_Request_Feed_Deletion extends SBR_Ability
{
	public function slug()
	{
		return 'request-feed-deletion';
	}

	public function args()
	{
		return array(
			'label'               => __('Request deletion of a Reviews Feed', 'reviews-feed'),
			'description'         => __('Starts deletion of ONE feed. Returns a wp-admin confirmation link; the feed is deleted only after the site admin opens the link in their browser and clicks Confirm. The link is single-use, expires in 15 minutes, and requesting another deletion replaces any pending one — only one feed can ever be awaiting confirmation.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'     => array('type' => 'integer'),
					'feed_name'   => array('type' => 'string'),
					'review_url'  => array(
						'type'        => 'string',
						'description' => 'Opens this exact feed in the Feed Builder, to inspect before confirming.',
					),
					'confirm_url' => array('type' => 'string'),
					'expires_in'  => array('type' => 'integer'),
					'note'        => array('type' => 'string'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			// Deliberately NOT annotated destructive: this ability only mints a
			// confirmation link — the destructive act happens on the wp-admin page
			// behind it, after the human clicks Delete. Annotating it destructive
			// makes MCP clients add a second, earlier approval that duplicates the
			// browser confirmation and doubles the friction (AgDR-0503 precedent).
			'meta'                => $this->write_meta(false, false),
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
		$db   = new DB();
		$rows = $db->feeds_query(array('id' => $feed_id));
		if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0]) || !isset($rows[0]['feed_name'])) {
			return SBR_Ability_Response::feed_not_found($feed_id);
		}

		// One pending deletion per user: this invalidates any earlier, unconfirmed link.
		$token = SBR_State_Token::create_exclusive('delete_feed_' . $feed_id, 'delete');
		if (is_wp_error($token)) {
			return $token;
		}

		return array(
			'feed_id'     => $feed_id,
			'feed_name'   => (string) $rows[0]['feed_name'],
			'review_url'  => admin_url('admin.php?page=sbr&feed_id=' . $feed_id),
			'confirm_url' => add_query_arg(
				array(
					'page'                         => 'sbr',
					'sbr_abilities_confirm_delete' => $feed_id,
					'sbr_abilities_state'          => $token,
				),
				admin_url('admin.php')
			),
			'expires_in'  => SBR_State_Token::TTL,
			'note'        => __('Nothing has been deleted yet. Ask the site admin to open the confirmation link in their browser and click "Delete feed".', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		);
	}
}
