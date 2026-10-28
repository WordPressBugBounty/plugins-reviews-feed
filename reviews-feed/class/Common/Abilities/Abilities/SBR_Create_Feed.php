<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Source_Service;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/create-feed
 *
 * @since SBR-499
 */
class SBR_Create_Feed extends SBR_Ability
{
	public function slug()
	{
		return 'create-feed';
	}

	public function args()
	{
		$writable = SBR_Settings_Schema::writable(SBR_Settings_Schema::feed_settings());

		return array(
			'label'               => __('Create a Reviews Feed', 'reviews-feed'),
			'description'         => __('Creates a new feed from already-connected review sources (see list-sources), with optional quick settings. Returns the new feed id. Requires AI write access.', 'reviews-feed') . ' ' . $this->source_tier_note() . ' ' . SBR_Update_Feed_Settings::feed_style_note() . $this->pro_settings_note(), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_name' => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Optional name; defaults to "Reviews Feed". A name already in use gets a numeric suffix, like the Feed Builder does.',
					),
					'sources'   => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'minItems'    => 1,
						'uniqueItems' => true,
						'maxItems'    => 20,
						'description' => 'Source ids of ALREADY-CONNECTED review sources (see list-sources).',
					),
					'settings'  => array(
						'type'                 => 'object',
						'properties'           => SBR_Settings_Schema::to_json_properties($writable),
						'additionalProperties' => false,
						'description'          => 'Quick settings applied to the new feed.',
					),
				),
				'required'             => array('sources'),
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
			'meta'                => $this->write_meta(false, false),
		);
	}

	/**
	 * @param mixed $input
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input      = $this->normalize_input($input);
		$feed_name  = isset($input['feed_name']) && is_string($input['feed_name']) && $input['feed_name'] !== ''
			? $input['feed_name']
			: __('Reviews Feed', 'reviews-feed');
		$requested  = isset($input['sources']) && is_array($input['sources'])
			? array_map('strval', $input['sources'])
			: array();
		$settings_in = isset($input['settings']) ? $this->normalize_input($input['settings']) : array();

		// The Feed Builder's import path (SBR_Feed_Saver_Manager::import_feed())
		// refuses a feed with no source; match that contract rather than creating
		// a feed that can only ever render empty.
		if (empty($requested)) {
			return SBR_Ability_Response::sources_required();
		}

		// Feed-count cap (Hakim MEDIUM): deletion is deliberately one
		// confirmation per feed, so unbounded creation would be asymmetrically
		// expensive to undo. Checked BEFORE any write.
		//
		// `sbr_abilities_max_feeds` semantics: any positive integer is the
		// ceiling. A non-positive value (0, a negative number, or a filter
		// that returns something non-numeric) is NOT "unlimited" — it FAILS
		// CLOSED to the default of 50, the same way SBR_Write_Gate defaults
		// to disabled on anything but an explicit true. A site that genuinely
		// wants no practical ceiling should return a large explicit number
		// (e.g. PHP_INT_MAX) from the filter, not 0 or a negative value.
		//
		// Known limitation: this count-then-insert is not atomic, so two
		// simultaneous create-feed calls can both pass the check and overshoot the
		// ceiling by the number of concurrent requests. The ceiling is a guard
		// against runaway agents, not a hard quota, so no lock is taken.
		$max_feeds = (int) apply_filters('sbr_abilities_max_feeds', 50);
		if ($max_feeds <= 0) {
			$max_feeds = 50;
		}
		if (DB::feeds_list_count() >= $max_feeds) {
			return SBR_Ability_Response::too_many_feeds();
		}

		$schema   = SBR_Settings_Schema::feed_settings();
		$writable = SBR_Settings_Schema::writable($schema);
		$clean    = array();
		$ignored  = array();
		$warnings = array();
		foreach ($settings_in as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = SBR_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			$clean[$key] = $v;
		}
		// Keys the agent asked for, before the template adds its own: only these
		// warrant the Feed Builder warning (SBR-577).
		$requested_keys = array_keys($clean);

		// AgDR-0504 finding C2 / B4 (Rex round-3): create-feed used to
		// store \`feedTemplate\` and apply none of it, AND only applied a
		// template at all when one was explicitly requested. The native
		// new-feed path (SBR_Feed_Saver_Manager::builder_update() ->
		// get_feed_settings_by_feed_templates()) ALWAYS applies a template --
		// falling back to the "default" template when \`feedTemplate\` is
		// empty -- so a feed created here without one used to lack the
		// default-template keys (headerContent, postStroke, loadButtonFont, ...)
		// that a UI-created feed always has. Always call
		// apply_template_with_warning(); it already resolves an empty
		// feedTemplate to the default template via
		// get_feed_settings_by_feed_templates()'s own fallback.
		//
		// Free (SBR-577, AgDR-9005): there is no `feedTemplate` input (free
		// offers only `default`), so the template is the BASE and the agent's
		// validated keys are laid over it -- the agent's explicit values win,
		// and there is nothing to warn about. Pro keeps the template-last
		// behaviour above, unchanged.
		if (Util::sbr_is_pro()) {
			list($clean, $template_warning) = self::apply_template_with_warning($clean);
			if ($template_warning !== null) {
				$warnings[] = $template_warning;
			}
		} else {
			$clean = array_merge(SBR_Feed_Settings_Service::apply_feed_template(array()), $clean);
		}

		$resolved = (new SBR_Source_Service())->resolve_allowed_sources($requested);

		// Fail CLOSED before writing, same posture as set-feed-sources: a
		// request that names an unknown id is almost always a mistake, and one
		// where every id survives resolution as tier-locked leaves nothing to
		// create the feed from. (An empty request was refused above.)
		if (!empty($resolved['missing'])) {
			return SBR_Ability_Response::source_not_found($resolved['missing']);
		}
		if (empty($resolved['ids'])) {
			return SBR_Ability_Response::no_valid_sources();
		}

		foreach ($resolved['ignored'] as $id => $reason) {
			$ignored['source:' . $id] = $reason;
		}

		// Same naming helper as the UI's new-feed path: a duplicate name gets
		// " (N)" appended instead of two feeds sharing one name.
		$feed_name = SBR_Feed_Saver_Manager::create_feed_name(sanitize_text_field($feed_name));

		// Parity with SBR_Feed_Saver_Manager::builder_update()'s new-feed path
		// (class/Common/Builder/SBR_Feed_Saver_Manager.php:130-139): a Review
		// Collection among the sources unconditionally forces the min-length
		// filter off, because a collection's hand-picked reviews should never
		// be silently hidden by a character-count floor.
		// Collections are Pro-only, so this branch is unreachable on Free
		// (SBR_Source_Service never resolves a `collection` source there).
		if (Util::sbr_is_pro() && in_array('collection', $resolved['providers'], true)) {
			$clean['filterCharCountMin'] = 0;
		}

		$result = (new SBR_Feed_Settings_Service())->create_feed($feed_name, $resolved['ids'], $clean);
		if (is_wp_error($result)) {
			return $result;
		}

		// A new feed has no feed_style yet, so styling keys the agent set are
		// saved but inert until the feed is saved once in the Feed Builder.
		$warnings = array_merge(
			$warnings,
			SBR_Update_Feed_Settings::feed_style_warnings(isset($result['feed_id']) ? (int) $result['feed_id'] : 0, $requested_keys)
		);

		return array_merge(
			$result,
			array(
				'sources'  => $resolved['ids'],
				'updated'  => array_keys($clean),
				'ignored'  => (object) $ignored,
				'warnings' => $warnings,
			)
		);
	}

	/**
	 * Apply a feed template the way the Feed Builder's own new-feed path does
	 * (SBR_Feed_Saver_Manager::builder_update() -> get_feed_settings_by_feed_templates(),
	 * same LAST-among-settings position) and report which explicitly-requested
	 * keys it overwrote — a template forcibly sets its own layout/colour
	 * values, so a caller-requested value for one of those keys is silently
	 * discarded unless this warning surfaces it (AgDR-0504 finding C2 /
	 * requirement 3).
	 *
	 * @param array $clean Schema-validated settings, including `feedTemplate`.
	 * @return array{0: array, 1: string|null} [expanded settings, warning or null]
	 */
	private static function apply_template_with_warning(array $clean)
	{
		$before   = $clean;
		$expanded = SBR_Feed_Settings_Service::apply_feed_template($clean);

		$overridden = array();
		foreach ($before as $key => $value) {
			if ($key !== 'feedTemplate' && array_key_exists($key, $expanded) && $expanded[$key] !== $value) {
				$overridden[] = $key;
			}
		}

		if (empty($overridden)) {
			return array($expanded, null);
		}

		$template_slug = (isset($before['feedTemplate']) && $before['feedTemplate'] !== '')
			? $before['feedTemplate']
			: 'default';

		$warning = sprintf(
			/* translators: 1: template slug, 2: comma-separated setting keys */
			__(
				'Template "%1$s" applies its own values for: %2$s — the requested values for these keys were not used.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'reviews-feed'
			),
			$template_slug,
			implode(', ', $overridden)
		);

		return array($expanded, $warning);
	}
}
