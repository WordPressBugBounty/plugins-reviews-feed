<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;
use SmashBalloon\Reviews\Common\Abilities\SBR_Write_Gate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base class for one Reviews Feed ability.
 *
 * Subclasses return the wp_register_ability() args; the shared pieces
 * (namespace, capability check, input normalisation, read-only meta) live
 * here so every ability fences authorization the same way.
 *
 * Mirrors InstagramFeed\Abilities\Abilities\SBI_Ability (AgDR-0503).
 *
 * @since SBR-499
 */
abstract class SBR_Ability
{
	const NAMESPACE_PREFIX = 'reviews-feed';
	const CATEGORY         = 'reviews-feed';

	/**
	 * @return string Slug after the namespace, e.g. `get-feed`.
	 */
	abstract public function slug();

	/**
	 * @return array Args for wp_register_ability() (without `category`).
	 */
	abstract public function args();

	/**
	 * @return string Fully-qualified ability name.
	 */
	public function name()
	{
		return self::NAMESPACE_PREFIX . '/' . $this->slug();
	}

	/**
	 * @return void
	 */
	public function register()
	{
		$args             = $this->args();
		$args['category'] = self::CATEGORY;
		wp_register_ability($this->name(), $args);
	}

	/**
	 * Same gate as the Feed Builder and Settings pages, including the
	 * `sbr_settings_pages_capability` filter contract.
	 *
	 * @return true|\WP_Error
	 */
	public function can_manage()
	{
		return sbr_current_user_can('manage_reviews_feed_options') ? true : SBR_Ability_Response::forbidden();
	}

	/**
	 * Core hands abilities null|array|object depending on transport.
	 *
	 * @param mixed $input
	 * @return array
	 */
	protected function normalize_input($input)
	{
		if (is_object($input)) {
			$input = get_object_vars($input);
		}
		return is_array($input) ? $input : array();
	}

	/**
	 * Edition-accurate sentence on how sources outside the license tier are handled.
	 * Free resolves only its own providers, so any other id fails as source_not_found;
	 * Pro reports tier-locked providers in `ignored`.
	 *
	 * @return string
	 */
	protected function source_tier_note()
	{
		if (SBR_Pro_Upsell::is_free()) {
			return __('Only sources from providers available in this edition can be used; any other source id is rejected as `source_not_found`.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		}
		return __('Sources whose provider is not included in the current license tier are skipped and reported in `ignored`.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
	}

	/**
	 * Sentence appended to the settings-writing abilities' descriptions in the
	 * free edition (AgDR-9006): a key the schema does not list may be Pro-only,
	 * and the two upsell abilities say which. Empty in Pro, where neither exists.
	 *
	 * @return string Leading space included, or ''.
	 */
	protected function pro_settings_note()
	{
		if (!SBR_Pro_Upsell::is_free()) {
			return '';
		}
		return ' ' . __('A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
	}

	/**
	 * @param bool $mcp_public
	 * @return array
	 */
	protected function read_meta($mcp_public = true)
	{
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array('public' => (bool) $mcp_public),
		);
	}

	/**
	 * Capability first, then the write gate.
	 *
	 * Deliberately the reverse of WPForms' order: the gate's error says "AI write
	 * access is disabled", which tells a caller without the capability something
	 * about the site's settings. Non-admins always get `sbr_forbidden`, whatever
	 * the toggle is.
	 *
	 * @return true|\WP_Error
	 */
	public function can_write()
	{
		$can = $this->can_manage();
		if (is_wp_error($can)) {
			return $can;
		}
		return SBR_Write_Gate::check();
	}

	/**
	 * @param bool $destructive
	 * @param bool $idempotent
	 * @return array
	 */
	protected function write_meta($destructive, $idempotent)
	{
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
			'show_in_rest' => true,
			// Hidden from MCP discovery while writes are disabled (agents should not
			// be shown a tool they cannot use); REST stays capability-gated.
			'mcp'          => array('public' => SBR_Write_Gate::is_enabled()),
		);
	}

	/**
	 * @param array $extra Extra top-level output properties.
	 * @return array
	 */
	protected function write_output_schema(array $extra = array())
	{
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				$extra,
				array(
					'updated'  => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Keys that were written.',
					),
					'ignored'  => array(
						'type'        => 'object',
						'description' => 'Keys not written, with the reason: '
							. 'read_only, feature_unavailable or unknown_setting.',
					),
					// AgDR-0504 requirement 3: signal partial success. Populated
					// whenever a requested change was accepted but inert, a
					// coupled precondition is unmet, or a value fell to a
					// default — so an agent gets a signal instead of a false
					// "success" that changed nothing (or changed something it
					// didn't ask for, e.g. create-feed's template override).
					'warnings' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Human-readable warnings about accepted-but-inert or '
							. 'unexpected effects of this write. Empty when there are none.',
					),
				)
			),
		);
	}
}
