<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Helpers\SBR_WPML;

/**
 * Pins SBR_WPML::get_current_language() — the WPML side of SMASH-1617. The old code
 * returned the literal string `'wpml'` when WPML's language code wasn't an exact
 * match in Google's list, so Google received `language=wpml` and left reviews
 * untranslated. It must now map via Util::map_wpml_to_google_language() and fall
 * back to a real code (or 'default') — NEVER 'wpml'.
 *
 * The mapping itself is covered exhaustively by WpmlLanguageMappingTest; here we
 * pin the wrapper's branches (non-WPML passthrough + the WPML fallback chain).
 */
final class WpmlGetCurrentLanguageTest extends TestCase
{
	/**
	 * When WPML isn't active (ICL_SITEPRESS_VERSION undefined) or the localization
	 * isn't 'wpml', the value passes through unchanged.
	 */
	public function test_non_wpml_localization_passes_through(): void
	{
		$this->assertSame('es-419', SBR_WPML::get_current_language('es-419'));
		$this->assertSame('en', SBR_WPML::get_current_language('en'));
		$this->assertSame('default', SBR_WPML::get_current_language('default'));
	}

	/**
	 * With WPML active and localization 'wpml', an unresolvable WPML language must
	 * fall back to 'default' — the literal 'wpml' must never reach the API. Run in a
	 * separate process so defining ICL_SITEPRESS_VERSION doesn't leak to other tests.
	 *
	 * (The bootstrap's apply_filters stub returns the passed value, so
	 * wpml_current_language / wpml_default_language resolve to null here, exercising
	 * the final fallback.)
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wpml_fallback_never_returns_literal_wpml(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}

		$resolved = SBR_WPML::get_current_language('wpml');

		$this->assertSame('default', $resolved);
		$this->assertNotSame('wpml', $resolved);
	}
}
