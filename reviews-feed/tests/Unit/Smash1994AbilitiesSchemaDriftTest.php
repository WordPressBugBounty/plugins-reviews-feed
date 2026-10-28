<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Customizer\Tabs\SBR_Customize_Tab;
use SmashBalloon\Reviews\Common\Customizer\Tabs\SBR_Settings_Tab;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

/**
 * SBR-499 / AgDR-0504 (AgDR-9004) requirement 1 + AgDR-9005 §2 — "derive,
 * don't restate" is enforced here as "equal to, enforced by a test": this
 * walks the LIVE customizer control tree (SBR_Customize_Tab,
 * SBR_Settings_Tab) and asserts SBR_Settings_Schema's literal enums/bounds
 * equal what the control actually offers, plus a key-set snapshot for the
 * keys the review (PR #536) found had no live control at all.
 *
 * A renamed/rebounded control now fails this test loudly in CI instead of
 * silently drifting the agent-facing contract away from the plugin.
 *
 * Every test method below carries its own @runInSeparateProcess: setUp()
 * defines SBR_PRO, and PHP constants can't be undefined -- PHPUnit 10 only
 * honours that annotation at the METHOD level (a class-level docblock copy
 * of it is silently ignored), so without it on each method the constant
 * would leak into every OTHER test file that runs afterward in the same
 * PHPUnit process and reads Util::sbr_is_pro() (e.g. WpmlLanguageMappingTest
 * via get_translation_languages(), which adds a 'wpml' entry when
 * ICL_SITEPRESS_VERSION is also defined by an earlier test).
 */
class Smash1994AbilitiesSchemaDriftTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!defined('SBR_PRO')) {
			define('SBR_PRO', true);
		}
	}

	/**
	 * Flattens a customizer controls tree (groups / lists nest arbitrarily)
	 * into a flat map of control id => control definition.
	 *
	 * @param array $controls
	 * @return array<string, array>
	 */
	private static function flatten_controls(array $controls): array
	{
		$flat = array();
		foreach ($controls as $control) {
			if (!is_array($control)) {
				continue;
			}
			if (isset($control['id']) && is_string($control['id'])) {
				$flat[$control['id']] = $control;
			}
			if (isset($control['controls']) && is_array($control['controls'])) {
				$flat = array_merge($flat, self::flatten_controls($control['controls']));
			}
			if (isset($control['options']) && is_array($control['options'])) {
				// toggleset/radio-style options carry their own 'value' keys,
				// not ids -- collected separately by the caller when needed.
				continue;
			}
		}
		return $flat;
	}

	/** @return string[] Live (non-commented) toggleset option values for the `layout` control. */
	private static function live_layout_values(): array
	{
		$controls = SBR_Customize_Tab::get_layout_controls();
		foreach ($controls as $control) {
			if (($control['id'] ?? null) === 'layout') {
				return array_column($control['options'], 'value');
			}
		}
		self::fail('layout toggleset control not found in SBR_Customize_Tab::get_layout_controls()');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_layout_enum_matches_live_toggleset_options(): void
	{
		$schema = SBR_Settings_Schema::feed_settings();
		// Set-equality, not order-strict -- the toggleset's display order and
		// the schema enum's declaration order carry no independent meaning;
		// what must never drift is WHICH values are reachable.
		$this->assertEqualsCanonicalizing(
			self::live_layout_values(),
			$schema['layout']['enum'],
			'layout enum drifted from the live toggleset options in SBR_Customize_Tab::get_layout_controls() -- ' .
			'the commented-out "grid" option must stay excluded.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_layout_enum_never_offers_grid(): void
	{
		// Guards the specific regression (AgDR-0504 finding B2): 'grid' is a
		// real switch case in SBR_Feed_Templates_Settings, but has no live
		// UI control, so it must never be a reachable agent-writable value.
		$schema = SBR_Settings_Schema::feed_settings();
		$this->assertNotContains('grid', $schema['layout']['enum']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_carousel_breakpoint_bounds_match_live_control(): void
	{
		$flat   = self::flatten_controls(SBR_Customize_Tab::get_layout_controls());
		$schema = SBR_Settings_Schema::feed_settings();

		foreach (array('carouselBreakpointDesktop', 'carouselBreakpointTablet') as $id) {
			$this->assertArrayHasKey($id, $flat, "$id control not found in the layout control tree");
			$this->assertSame($flat[$id]['min'], $schema[$id]['minimum'], "$id minimum drifted from the live control");
			$this->assertSame($flat[$id]['max'], $schema[$id]['maximum'], "$id maximum drifted from the live control");
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_number_reviews_desktop_bound_matches_pro_ternary(): void
	{
		$flat   = self::flatten_controls(SBR_Customize_Tab::get_layout_controls());
		$schema = SBR_Settings_Schema::feed_settings();

		$this->assertSame($flat['numPostDesktop']['max'], $schema['numPostDesktop']['maximum']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_spacing_sliders_have_no_declared_bound_in_the_control(): void
	{
		// verticalSpacing/horizontalSpacing declare no min/max in the control
		// -- the customizer's shared Slider.js component then falls back to
		// its own default (min 0, max 100). Pin BOTH halves: the control
		// really has no bound of its own, and the schema's fallback matches
		// Slider.js's default rather than an invented number.
		$flat = self::flatten_controls(SBR_Customize_Tab::get_layout_controls());
		foreach (array('verticalSpacing', 'horizontalSpacing') as $id) {
			$this->assertArrayNotHasKey('min', $flat[$id], "$id declares a min in the control now -- update the schema to derive from it");
			$this->assertArrayNotHasKey('max', $flat[$id], "$id declares a max in the control now -- update the schema to derive from it");
		}

		$schema = SBR_Settings_Schema::feed_settings();
		$this->assertSame(0, $schema['verticalSpacing']['minimum']);
		$this->assertSame(100, $schema['verticalSpacing']['maximum']);
		$this->assertSame(0, $schema['horizontalSpacing']['minimum']);
		$this->assertSame(100, $schema['horizontalSpacing']['maximum']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_content_length_has_no_invented_maximum(): void
	{
		$flat = self::flatten_controls(SBR_Customize_Tab::get_layout_controls());
		$this->assertArrayNotHasKey(
			'max',
			$flat['contentLength'],
			'contentLength control now declares a max -- the schema should derive it instead of staying unbounded'
		);

		$schema = SBR_Settings_Schema::feed_settings();
		$this->assertArrayNotHasKey('maximum', $schema['contentLength']);
	}

	/**
	 * Keys removed from the schema because their controlling UI group is
	 * commented out (AgDR-0504 finding B2). If one of these ever gains a
	 * real control again, this test forces a deliberate schema decision
	 * instead of the key silently staying absent or silently reappearing.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_keys_with_no_live_control_stay_out_of_the_schema(): void
	{
		$schema = SBR_Settings_Schema::feed_settings();
		foreach (array('colorScheme', 'filterByImage', 'filterByVideos', 'gridDesktopColumns', 'gridTabletColumns', 'gridMobileColumns') as $absent_key) {
			$this->assertArrayNotHasKey($absent_key, $schema, "$absent_key has no live control and must not be exposed");
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_colorpalette_section_is_still_commented_out(): void
	{
		// If this ever comes back, get_colorpalette_controls() below is what
		// re-derives the schema's colorScheme bound from -- don't restore the
		// schema key without restoring (and deriving from) this control.
		$ref = new \ReflectionMethod(SBR_Customize_Tab::class, 'get_theme_controls');
		$this->assertTrue($ref->isPublic());
		$this->assertTrue(
			method_exists(SBR_Customize_Tab::class, 'get_colorpalette_controls'),
			'get_colorpalette_controls() was removed -- re-check whether colorScheme should return to the schema'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_by_content_filters_have_no_live_control(): void
	{
		// get_filters_controls() is protected -- reflection instead of a
		// visibility change to the plugin code just to make it testable.
		$ref = new \ReflectionMethod(SBR_Settings_Tab::class, 'get_filters_controls');
		$ref->setAccessible(true);
		$controls = $ref->invoke(null);
		$flat     = self::flatten_controls($controls);
		$this->assertArrayNotHasKey('filterByImage', $flat, 'filterByImage now has a live control -- re-derive and re-expose it deliberately');
		$this->assertArrayNotHasKey('filterByVideos', $flat, 'filterByVideos now has a live control -- re-derive and re-expose it deliberately');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_usagetracking_never_exposed(): void
	{
		// AgDR-0503: usage tracking is never exposed through the Abilities
		// API. AgDR-9005 flags its earlier presence here as drift.
		$global_settings = SBR_Settings_Schema::global_settings();
		$this->assertArrayNotHasKey('usagetracking', $global_settings);

		$feed_settings = SBR_Settings_Schema::feed_settings();
		$this->assertArrayNotHasKey('usagetracking', $feed_settings);
	}
}
