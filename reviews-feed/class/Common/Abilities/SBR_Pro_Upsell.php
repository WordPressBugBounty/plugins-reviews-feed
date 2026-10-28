<?php

namespace SmashBalloon\Reviews\Common\Abilities;

use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pro upsell surfaces for the FREE edition's Abilities API (AgDR-9006).
 *
 * One place that knows three things, so every surface stays consistent and the
 * copy never drifts from the rest of the plugin:
 *   1. the upgrade URL + UTM convention  -> upgrade_url()
 *   2. which Pro features exist, and which feed settings they unlock
 *                                         -> features() / pro_only_settings()
 *   3. the one-line "this is Pro" hint for a Pro-caused refusal
 *                                         -> hint() / hint_for_setting()
 *
 * Nothing is restated here. Feature ids, names and descriptions are read from
 * Util::upsell_modal_content() (the same data the customizer's upsell modals
 * use), and the Pro-only setting keys are read from the `tier` / `tier_values`
 * tags on SBR_Settings_Schema's curated table (see
 * SBR_Settings_Schema::pro_only_by_tier()), keyed by those same modal ids. A
 * copy change in the modal data, or a new tagged key, flows through on its own.
 *
 * UTM convention (one rule, so every port matches):
 *   - utm_source = `abilities` for API responses, `settings` for the admin card;
 *   - utm_medium = the feature id, i.e. the upsell-data key in kebab-case
 *     (`carouselModal` -> `carousel-modal`, see feature_medium()), for any link
 *     tied to one feature;
 *   - utm_medium = `ai-agents-card` for the settings banner, `get-pro-features`
 *     for that ability's top-level upgrade_url, `describe-settings-schema` for
 *     the `pro_only` block's upgrade_url.
 *
 * Free edition only. Every public method is inert in a Pro build.
 *
 * @since 2.12.0
 */
class SBR_Pro_Upsell
{
	/** The plugin's existing lite-upgrade landing page (same one the customizer's upsell modals use). */
	const BASE_URL = 'https://smashballoon.com/reviews-feed/reviews-lite-upgrade/';

	/** Matches the `utm_campaign` every other free-edition upgrade link already carries. */
	const UTM_CAMPAIGN = 'reviews-free';

	/**
	 * Upsell-data keys of the features the AI Agents card banner names. Picked by
	 * key (not array position) so reordering the upsell data cannot change the banner.
	 *
	 * @var string[]
	 */
	const BANNER_FEATURES = array('carouselModal', 'filtersModal', 'moderationModal');

	/**
	 * Only the free edition promotes Pro. The same edition signal the schema
	 * filter uses (the SBR_PRO constant, never the licence tier).
	 *
	 * @return bool
	 */
	public static function is_free()
	{
		return !Util::sbr_is_pro();
	}

	/**
	 * Upgrade link with the plugin's standard UTM set.
	 *
	 * @param string $medium What was shown (e.g. `get-pro-features`, `carousel-modal`, `ai-agents-card`).
	 * @param string $source Surface: `abilities` for API responses, `settings` for the admin card.
	 * @return string
	 */
	public static function upgrade_url($medium, $source = 'abilities')
	{
		return self::BASE_URL
			. '?utm_campaign=' . self::UTM_CAMPAIGN
			. '&utm_source=' . rawurlencode((string) $source)
			. '&utm_medium=' . rawurlencode((string) $medium)
			. '&utm_content=Upgrade';
	}

	/**
	 * Pro features as `[name, description, settings[]]`, one per upsell-data entry.
	 *
	 * Name + description come from Util::upsell_modal_content(); `settings` from the
	 * schema's tier tags. Empty outside the free edition.
	 *
	 * @return array<int, array{name: string, description: string, settings: string[]}>
	 */
	public static function features()
	{
		if (!self::is_free()) {
			return array();
		}
		$by_tier = SBR_Settings_Schema::pro_only_by_tier();
		$out     = array();
		foreach (Util::upsell_modal_content() as $id => $item) {
			if (!is_array($item) || empty($item['heading'])) {
				continue;
			}
			$out[] = array(
				'name'        => self::feature_name((string) $item['heading']),
				'description' => isset($item['description']) ? (string) $item['description'] : '',
				'settings'    => isset($by_tier[$id]) ? $by_tier[$id] : array(),
			);
		}
		return $out;
	}

	/**
	 * Limits of the free edition that an agent should tell the user about when
	 * they ask for more than free can show (SBR-577, QA 3). Plain sentences, one
	 * per limit. Empty outside the free edition.
	 *
	 * The review cap is the one the plugin's own listing states: without an API
	 * key the free edition shows up to 10 of the latest Google or Yelp reviews
	 * for a single business (readme-free.txt).
	 *
	 * @return string[]
	 */
	public static function free_limits()
	{
		if (!self::is_free()) {
			return array();
		}
		return array(
			__('The free edition shows up to 10 of the latest reviews for a single business (Google or Yelp). Showing more reviews, or reviews from more sources, needs Pro.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		);
	}

	/**
	 * Every Pro-only setting key / `key=value`, flattened. Drives the `pro_only`
	 * block of describe-settings-schema.
	 *
	 * @return string[]
	 */
	public static function pro_only_settings()
	{
		if (!self::is_free()) {
			return array();
		}
		$all = array();
		foreach (SBR_Settings_Schema::pro_only_by_tier() as $settings) {
			$all = array_merge($all, $settings);
		}
		return array_values(array_unique($all));
	}

	/**
	 * utm_medium for a feature: its upsell-data key in kebab-case
	 * (`carouselModal` -> `carousel-modal`).
	 *
	 * @param string $feature_id Upsell-data key.
	 * @return string
	 */
	public static function feature_medium($feature_id)
	{
		$kebab = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', (string) $feature_id);
		return strtolower(is_string($kebab) ? $kebab : (string) $feature_id);
	}

	/**
	 * Names of the BANNER_FEATURES, in banner order, from the upsell data.
	 * Keys missing from the data are skipped.
	 *
	 * @return string[]
	 */
	public static function banner_feature_names()
	{
		if (!self::is_free()) {
			return array();
		}
		$content = Util::upsell_modal_content();
		$names   = array();
		foreach (self::BANNER_FEATURES as $id) {
			if (isset($content[$id]['heading'])) {
				$names[] = self::feature_name((string) $content[$id]['heading']);
			}
		}
		return $names;
	}

	/**
	 * One-line hint for a refusal caused by a Pro-only feature.
	 *
	 * @internal Not wired into any ability in this plugin. Core JSON-schema
	 *           validation (additionalProperties:false, narrower enums) rejects
	 *           Pro-only keys/values with a 400 before plugin code runs, so a hint
	 *           there is unreachable, and the plugin's own refusals are not
	 *           Pro-caused (the feed cap applies in Pro too; a provider outside
	 *           the edition is deliberately reported as `source_not_found`, as
	 *           list-sources never showed it). Kept for a future plugin-side refusal.
	 *
	 * @param string $feature_label Human name of the Pro feature.
	 * @param string $medium        UTM medium: the feature id via feature_medium().
	 * @return string Empty outside the free edition.
	 */
	public static function hint($feature_label, $medium)
	{
		if (!self::is_free()) {
			return '';
		}
		return sprintf(
			/* translators: 1: Pro feature name, 2: upgrade URL */
			__('%1$s is a Pro feature. Upgrade: %2$s', 'reviews-feed'),
			$feature_label,
			self::upgrade_url($medium)
		);
	}

	/**
	 * Hint for a refused setting key (and optionally value), or '' when the
	 * refusal is not Pro-caused (a typo'd key gets no upsell).
	 *
	 * @internal See hint(): for plugin-side refusals only; unreachable through the
	 *           free schema-validated abilities.
	 *
	 * @param string $key   Setting key the caller sent.
	 * @param mixed  $value Value the caller sent (only used for `key=value` Pro values).
	 * @return string
	 */
	public static function hint_for_setting($key, $value = null)
	{
		if (!self::is_free()) {
			return '';
		}
		$content = Util::upsell_modal_content();
		foreach (SBR_Settings_Schema::pro_only_by_tier() as $tier => $settings) {
			$hit = in_array($key, $settings, true)
				|| (is_scalar($value) && in_array($key . '=' . $value, $settings, true));
			if ($hit && isset($content[$tier]['heading'])) {
				return self::hint(self::feature_name((string) $content[$tier]['heading']), self::feature_medium($tier));
			}
		}
		return '';
	}

	/**
	 * Feature name from an upsell heading: drops the "Upgrade to Pro (Plus) to/for "
	 * lead-in so "Upgrade to Pro to get Carousel layout" reads "Get Carousel layout".
	 * A heading that does not match (another locale, "Remove Smash Balloon Branding")
	 * is used as is.
	 *
	 * @param string $heading Upsell heading.
	 * @return string
	 */
	private static function feature_name($heading)
	{
		$name = preg_replace('/^Upgrade to Pro( Plus)?\s+(to|for)\s+/i', '', $heading);
		$name = is_string($name) && $name !== '' ? $name : $heading;
		return ucfirst($name);
	}
}
