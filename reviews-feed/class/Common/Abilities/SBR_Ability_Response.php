<?php

namespace SmashBalloon\Reviews\Common\Abilities;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WP_Error factories shared by every Reviews Feed ability.
 *
 * Every error carries an HTTP status in `data.status` so the core Abilities
 * REST controller and the MCP adapter surface the right code.
 *
 * Mirrors InstagramFeed\Abilities\SBI_Ability_Response (AgDR-0503).
 *
 * @since SBR-499
 */
class SBR_Ability_Response
{
	/**
	 * @return \WP_Error
	 */
	public static function forbidden()
	{
		return new WP_Error(
			'sbr_forbidden',
			__('You do not have permission to manage Reviews Feed settings.', 'reviews-feed'),
			array('status' => 403)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function writes_disabled()
	{
		return new WP_Error(
			'sbr_writes_disabled',
			__(
				'AI write access is disabled. Enable "MCP Write Access" under Reviews Feed → Settings → General.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			array('status' => 403)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function invalid_feed_id()
	{
		return new WP_Error(
			'sbr_invalid_feed_id',
			__('A valid feed_id is required.', 'reviews-feed'),
			array('status' => 400)
		);
	}

	/**
	 * @param int $feed_id
	 * @return \WP_Error
	 */
	public static function feed_not_found($feed_id)
	{
		return new WP_Error(
			'sbr_feed_not_found',
			sprintf(
				/* translators: %d: feed id */
				__('Feed %d was not found.', 'reviews-feed'),
				(int) $feed_id
			),
			array('status' => 404)
		);
	}

	/**
	 * @param string[] $ids The requested source ids that do not exist. Callers
	 *                      are expected to have already dropped empty-string
	 *                      entries (SBR_Source_Service::resolve_allowed_sources()
	 *                      does) — this is a second, defensive filter so a
	 *                      malformed caller can never render as "missing: , .".
	 * @return \WP_Error
	 */
	public static function source_not_found(array $ids)
	{
		// Defence in depth: sanitize every id before it reaches sprintf(), and
		// drop anything empty after sanitizing/trimming so a stray blank entry
		// can't render as a bare comma in the message.
		$clean = array();
		foreach ($ids as $id) {
			$id = trim(sanitize_text_field((string) $id));
			if ($id !== '') {
				$clean[] = $id;
			}
		}

		$total = count($clean);
		$shown = array_slice($clean, 0, 5);
		$list  = implode(', ', $shown);
		if ($total > 5) {
			$list .= sprintf(
				/* translators: %d: number of additional, un-listed source ids */
				__(' and %d more', 'reviews-feed'),
				$total - 5
			);
		}

		return new WP_Error(
			'sbr_source_not_found',
			sprintf(
				/* translators: %s: comma-separated source ids (capped at 5, with a count of any remainder) */
				__('These sources were not found: %s. Use list-sources to see connected sources.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				$list
			),
			array('status' => 404)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function sources_required()
	{
		return new WP_Error(
			'sbr_sources_required',
			__('A feed needs at least one source. Pass connected source ids from list-sources.', 'reviews-feed'),
			array('status' => 400)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function no_valid_sources()
	{
		return new WP_Error(
			'sbr_no_valid_sources',
			__('None of the requested sources can be used (unavailable in the current license tier).', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			array('status' => 400)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function too_many_feeds()
	{
		return new WP_Error(
			'sbr_too_many_feeds',
			__('This site already has the maximum number of feeds an AI assistant may create. Delete an existing feed first, or ask a site admin to raise the sbr_abilities_max_feeds filter.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			array('status' => 400)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function invalid_link_to()
	{
		return new WP_Error(
			'sbr_invalid_link_to',
			__('link_to must be "google" or "external".', 'reviews-feed'),
			array('status' => 400)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function external_link_required()
	{
		return new WP_Error(
			'sbr_external_link_required',
			__('external_link is required when link_to is "external".', 'reviews-feed'),
			array('status' => 400)
		);
	}

	/**
	 * @return \WP_Error
	 */
	public static function moderation_type_required()
	{
		return new WP_Error(
			'sbr_moderation_type_required',
			__('type ("allow" or "block") is required when enabling moderation.', 'reviews-feed'),
			array('status' => 400)
		);
	}
}
