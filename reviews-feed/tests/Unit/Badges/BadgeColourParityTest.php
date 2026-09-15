<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The badge's oklch values live in three languages and must agree (SBR-163).
 *
 * The web badge derives its colours in CSS, the emailed PNG derives the same
 * colours in PHP because a rasteriser cannot read a custom property, and the
 * editor's readout derives them again in JS so the swatch matches the render.
 * Three copies of one design decision, in three files that cannot share a
 * literal.
 *
 * A review round on sb-customizer#192 called that out, and it was already true:
 * the tinted footer band was `oklch(0.42 0.12 h)` in PHP against
 * `oklch(0.41 0.13 h)` in CSS, so the same badge mailed out with a footer strip
 * a shade off the one on the page. Four of the five tinted values agreed; one
 * had drifted, silently, with nothing to catch it.
 *
 * So the fix is not deduplication — it is impossible across PHP, CSS and JS —
 * it is making the next drift fail here. Values are read out of the files
 * rather than restated, so this test cannot pass while they disagree.
 */
class BadgeColourParityTest extends TestCase
{
	/**
	 * @return string
	 */
	protected function css()
	{
		$path = dirname(__DIR__, 3) . '/assets/css/sbr-trust-badge.css';

		$this->assertFileExists($path, 'the badge stylesheet is the reference for every other copy');

		return (string) file_get_contents($path);
	}

	/**
	 * @return string
	 */
	protected function php_source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Image.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * Every `oklch(l c …)` a CSS custom property is set to, keyed by property.
	 *
	 * @param string $css Stylesheet source.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	protected function css_oklch($css)
	{
		preg_match_all(
			'/(--[a-z0-9-]+):\s*oklch\(\s*([0-9.]+)\s+([0-9.]+)\s/i',
			$css,
			$matches,
			PREG_SET_ORDER
		);

		$out = array();

		foreach ($matches as $match) {
			// First wins: the plain declaration precedes the @supports override,
			// and both carry the same numbers by design.
			if (! isset($out[$match[1]])) {
				$out[$match[1]] = array( $match[2], $match[3] );
			}
		}

		return $out;
	}

	/**
	 * The stylesheet must still declare the tinted family this test compares.
	 *
	 * @return void
	 */
	public function test_the_stylesheet_declares_the_tinted_oklch_family()
	{
		$found = $this->css_oklch($this->css());

		foreach (array( '--sbr-tbb-surface', '--sbr-tbb-muted', '--sbr-tbb-line', '--sbr-tbb-foot', '--sbr-tbb-star-base' ) as $property) {
			$this->assertArrayHasKey(
				$property,
				$found,
				"{$property} no longer derives from oklch in CSS — if that is deliberate, this test's PHP counterpart needs the same change"
			);
		}
	}

	/**
	 * The five tinted values, PHP against CSS. This is the pair that had drifted.
	 *
	 * @return void
	 */
	public function test_every_tinted_value_matches_the_stylesheet()
	{
		$css = $this->css_oklch($this->css());
		$php = $this->php_source();

		$roles = array(
			'--sbr-tbb-surface'   => 'surface',
			'--sbr-tbb-muted'     => 'muted',
			'--sbr-tbb-line'      => 'line',
			'--sbr-tbb-foot'      => 'foot',
			'--sbr-tbb-star-base' => 'starBase',
		);

		foreach ($roles as $property => $role) {
			list( $l, $c ) = $css[$property];

			$this->assertMatchesRegularExpression(
				'/oklch_to_hex\(\s*' . preg_quote($l, '/') . '\s*,\s*' . preg_quote($c, '/') . '\s*,\s*\$tint_hue\s*\)/',
				$php,
				"the emailed PNG must derive '{$role}' at the same lightness/chroma the page does ({$property}: {$l} {$c})"
			);
		}
	}

	/**
	 * The tinted star fill, which is the palette entry that is NOT a derivation.
	 *
	 * Found by looking at a real badge: the emailed PNG drew WHITE stars and the same
	 * badge on the page drew GOLD ones. The stylesheet's own header says "stars go
	 * white" and SBR_Badge_Image's tinted palette uses '#ffffff' — but the CSS never
	 * DECLARED `--sbr-tbb-star-fill`, so
	 * `var(--sbr-tb-star, var(--sbr-tbb-star-fill, var(--sb-color-yellow-500)))`
	 * fell through to the gold token on every tinted badge in a browser.
	 *
	 * The sibling test above could not catch it: it walks the tinted values that are
	 * `oklch_to_hex(l, c, $tint_hue)` derivations, and this is the one entry in that
	 * palette that is a flat colour — so it sat outside the shape the guard checks.
	 *
	 * @return void
	 */
	public function test_the_tinted_star_fill_is_declared_and_matches_php()
	{
		$css = $this->css();
		$php = $this->php_source();

		// The tinted block must declare it, or the cascade silently reaches gold.
		$this->assertMatchesRegularExpression(
			'/\[data-theme=tinted\][^}]*--sbr-tbb-star-fill\s*:/s',
			$css,
			'the tinted theme must DECLARE --sbr-tbb-star-fill; without it the star '
			. 'cascade falls through to --sb-color-yellow-500 and tinted badges render gold'
		);

		// And it must be white, matching the PNG's palette.
		$this->assertMatchesRegularExpression(
			'/--sbr-tbb-star-fill\s*:\s*var\(\s*--sb-color-white\s*\)/',
			$css,
			'tinted stars are white on the page, as they are in the PNG'
		);

		$this->assertMatchesRegularExpression(
			"/'star'\s*=>\s*'#ffffff'/",
			$php,
			'SBR_Badge_Image must still paint tinted stars white, or the page and the '
			. 'emailed image disagree again'
		);
	}

	/**
	 * The minified copy the FRONT END actually loads must carry it too.
	 *
	 * The unminified file is the reference, but `sbr-trust-badge.min.css` is what a
	 * visitor downloads — a fix applied only to the source would test green here and
	 * change nothing on a live page.
	 *
	 * @return void
	 */
	public function test_the_minified_stylesheet_mirrors_the_tinted_star_fill()
	{
		$path = dirname(__DIR__, 3) . '/assets/css/sbr-trust-badge.min.css';

		$this->assertFileExists($path);

		$this->assertStringContainsString(
			'--sbr-tbb-star-fill:var(--sb-color-white)',
			(string) file_get_contents($path),
			'the minified stylesheet is what the front end loads; it must mirror the fix'
		);
	}

	/**
	 * The accent star, which is a different lightness per theme from one hue.
	 *
	 * @return void
	 */
	public function test_the_accent_star_matches_per_theme()
	{
		$css = $this->css();
		$php = $this->php_source();

		preg_match('/has-accent\[data-theme=light\][^}]*oklch\(\s*([0-9.]+)\s+([0-9.]+)\s/i', $css, $light);
		preg_match('/has-accent\[data-theme=dark\][^}]*oklch\(\s*([0-9.]+)\s+([0-9.]+)\s/i', $css, $dark);

		$this->assertNotEmpty($light, 'the light accent must still derive from oklch in CSS');
		$this->assertNotEmpty($dark, 'the dark accent must still derive from oklch in CSS');

		$this->assertMatchesRegularExpression(
			'/oklch_to_hex\(\s*' . preg_quote($light[1], '/') . '\s*,\s*' . preg_quote($light[2], '/') . '\s*,\s*\$accent_hue\s*\)/',
			$php,
			'light-theme accent star: PHP and CSS must agree'
		);

		$this->assertMatchesRegularExpression(
			'/oklch_to_hex\(\s*' . preg_quote($dark[1], '/') . '\s*,\s*' . preg_quote($dark[2], '/') . '\s*,\s*\$accent_hue\s*\)/',
			$php,
			'dark-theme accent star: PHP and CSS must agree'
		);
	}

	/**
	 * The editor's own copy, in the vendored customizer.
	 *
	 * Only the light accent and the tinted surface are duplicated there — the JS
	 * exists to paint a swatch and read a hex back, not to render a badge — so
	 * that is all this compares. Skipped rather than failed when the symlink is
	 * absent, which is how a plain `composer install` without the private
	 * customizer looks.
	 *
	 * @return void
	 */
	public function test_the_editor_constants_match_the_stylesheet()
	{
		$path = dirname(__DIR__, 3) . '/vendor/smashballoon/customizer/sb-common/sb-customizer/src/Utils/OklchUtils.js';

		if (! is_readable($path)) {
			$this->markTestSkipped('vendored customizer not present — nothing to compare');
		}

		$js  = (string) file_get_contents($path);
		$css = $this->css();

		preg_match('/has-accent\[data-theme=light\][^}]*oklch\(\s*([0-9.]+)\s+([0-9.]+)\s/i', $css, $light);
		$this->assertNotEmpty($light);

		$this->assertMatchesRegularExpression(
			'/BADGE_ACCENT\s*=\s*\{\s*l:\s*' . preg_quote($light[1], '/') . '\s*,\s*c:\s*' . preg_quote($light[2], '/') . '\s*\}/',
			$js,
			'BADGE_ACCENT is the lightness/chroma the sidebar swatch paints; it must be the one the badge paints'
		);

		$tinted = $this->css_oklch($css);
		list( $l, $c ) = $tinted['--sbr-tbb-surface'];

		$this->assertMatchesRegularExpression(
			'/BADGE_TINT\s*=\s*\{\s*l:\s*' . preg_quote($l, '/') . '\s*,\s*c:\s*' . preg_quote($c, '/') . '\s*\}/',
			$js,
			'BADGE_TINT must match the tinted surface the stylesheet derives'
		);
	}
}
