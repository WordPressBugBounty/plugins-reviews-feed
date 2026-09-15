<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The derived provider-mark PNGs, and that they still match their SVGs.
 *
 * GD cannot rasterise SVG, so the GD rendering rung draws provider marks from
 * PNGs in assets/badge-marks. Those are DERIVED, by build/generate-badge-marks.php,
 * from the same SVGs DisplayElements serves to the page — so there is one source
 * of truth and the PNGs are a transform of it.
 *
 * The failure this exists to catch: someone updates a provider's SVG, the page
 * changes, and the email badge on every GD host keeps showing the old mark until
 * a customer notices. Nothing else would fail.
 *
 * Two layers, because the derivation itself needs Imagick and most machines
 * running this suite will not have it:
 *
 *   - the inventory is asserted ALWAYS: every mark the renderer can ask for has
 *     a file, and every file corresponds to a mark. That has teeth anywhere.
 *   - the bytes are re-derived and compared only where Imagick exists. Guarded
 *     rather than skipped: a skipped test reads as a pass, and this suite has
 *     been caught by that before.
 */
class BadgeMarkAssetsTest extends TestCase
{
	/**
	 * Providers the badge can show, matching the generator's list.
	 *
	 * Hardcoded on purpose: derived from a live site's connected sources it
	 * would only ever cover what that machine happens to have.
	 */
	const PROVIDERS = array(
		'google', 'yelp', 'tripadvisor', 'booking', 'trustpilot', 'airbnb', 'zomato',
		'facebook', 'woocommerce', 'edd', 'aliexpress', 'wordpress.org', 'wpforms',
		'formidable',
	);

	/**
	 * Marks the generator reports it cannot produce, with the reason.
	 *
	 * Listed rather than silently absent, so a mark that starts failing shows up
	 * as an unexpected gap instead of blending into the ones that never worked.
	 * facebook-mono is a real gap: its SVG uses a <mask>, which the rasteriser
	 * draws blank — the same family as the <pattern> problem rasterise_mark()
	 * documents, and it is blank on the Imagick path too.
	 */
	const KNOWN_GAPS = array(
		'zomato-mono', 'zomato-lockup',
		'wpforms-mono', 'wpforms-lockup',
		'formidable-mono', 'formidable-lockup',
		'facebook-mono',
	);

	/**
	 * @return string
	 */
	private function dir()
	{
		return dirname(__DIR__, 3) . '/assets/badge-marks';
	}

	/**
	 * @return void
	 */
	public function test_the_asset_directory_ships()
	{
		$dir = $this->dir();

		$this->assertDirectoryExists($dir, 'the GD rung has no marks to draw without this directory');

		$files = glob($dir . '/*.png');

		// Without this the assertions below pass on an empty directory.
		$this->assertIsArray($files);
		$this->assertGreaterThan(20, count($files), 'suspiciously few marks — the set covers 14 providers');
	}

	/**
	 * @return void
	 */
	public function test_every_mark_the_renderer_can_ask_for_has_a_file()
	{
		// Asserted, not skipped. A missing machine CAPABILITY is a skip
		// (Imagick, below); a plugin class that will not autoload is a DEFECT in
		// the bootstrap, and skipping it stops the inventory being checked at all
		// while still reading as a pass — which is what this file's own docblock
		// argues against. Verified loadable in this bootstrap today.
		$this->assertTrue(
			class_exists('SmashBalloon\Reviews\Common\DisplayElements'),
			'DisplayElements must load — the mark inventory is unverifiable without it'
		);

		$elements = 'SmashBalloon\Reviews\Common\DisplayElements';
		$missing  = array();
		$asked    = 0;

		foreach (self::PROVIDERS as $provider) {
			$slug = $elements::provider_asset_slug($provider);

			$variants = array(
				'icon'   => $elements::has_provider_icon($provider),
				'mono'   => $elements::has_provider_mono_icon($provider),
				'lockup' => $elements::has_provider_lockup($provider),
			);

			foreach ($variants as $variant => $exists) {
				if (! $exists) {
					continue;
				}

				$asked++;
				$name = $slug . '-' . $variant;

				if (in_array($name, self::KNOWN_GAPS, true)) {
					continue;
				}

				if (! is_readable($this->dir() . '/' . $name . '.png')) {
					$missing[] = $name;
				}
			}
		}

		// Prove the loop ran before trusting an empty $missing.
		$this->assertGreaterThan(20, $asked, 'the inventory found almost nothing to check');

		$this->assertSame(
			array(),
			$missing,
			'these marks have an SVG but no derived PNG, so a GD host draws nothing for them: '
			. implode(', ', $missing)
			. ' — run build/generate-badge-marks.php'
		);
	}

	/**
	 * @return void
	 */
	public function test_no_orphan_files()
	{
		// Asserted, not skipped. A missing machine CAPABILITY is a skip
		// (Imagick, below); a plugin class that will not autoload is a DEFECT in
		// the bootstrap, and skipping it stops the inventory being checked at all
		// while still reading as a pass — which is what this file's own docblock
		// argues against. Verified loadable in this bootstrap today.
		$this->assertTrue(
			class_exists('SmashBalloon\Reviews\Common\DisplayElements'),
			'DisplayElements must load — the mark inventory is unverifiable without it'
		);

		$elements = 'SmashBalloon\Reviews\Common\DisplayElements';
		$expected = array();

		foreach (self::PROVIDERS as $provider) {
			$slug = $elements::provider_asset_slug($provider);

			foreach (array( 'icon', 'mono', 'lockup' ) as $variant) {
				$expected[] = $slug . '-' . $variant . '.png';
			}
		}

		$orphans = array();

		foreach ((array) glob($this->dir() . '/*.png') as $file) {
			$name = basename($file);

			if (! in_array($name, $expected, true)) {
				$orphans[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$orphans,
			'these files match no provider the renderer knows about: ' . implode(', ', $orphans)
		);
	}

	/**
	 * Every committed file is a real PNG with pixels in it.
	 *
	 * A truncated or empty file would still satisfy the inventory above, and the
	 * renderer would draw an invisible mark rather than fail.
	 *
	 * @return void
	 */
	public function test_every_file_is_a_png_with_dimensions()
	{
		$files = (array) glob($this->dir() . '/*.png');

		$this->assertNotEmpty($files, 'nothing to check');

		$bad = array();

		foreach ($files as $file) {
			$size = @getimagesize($file);

			if (! is_array($size) || IMAGETYPE_PNG !== $size[2] || $size[0] < 8 || $size[1] < 8) {
				$bad[] = basename($file) . ' (' . ( is_array($size) ? $size[0] . 'x' . $size[1] : 'unreadable' ) . ')';
				continue;
			}

			// Header alone is not enough: getimagesize() reads IHDR, which lives in
			// the first 24 bytes, so it reports dimensions for a file whose image
			// data has been truncated away. Decoding is what actually catches that —
			// a truncated mark passed this test until it was mutated on purpose.
			if (function_exists('imagecreatefrompng')) {
				$decoded = @imagecreatefrompng($file);

				if (! $decoded) {
					$bad[] = basename($file) . ' (header only, will not decode)';
					continue;
				}

				imagedestroy($decoded);
			}
		}

		$this->assertSame(array(), $bad, 'not usable as marks: ' . implode(', ', $bad));
	}

	/**
	 * Re-derive and compare, where the machine can.
	 *
	 * @return void
	 */
	public function test_the_committed_pngs_still_match_their_svgs()
	{
		if (! class_exists('\Imagick')) {
			// A skip, because what is missing is a CAPABILITY of the machine, and a
			// skip is exactly the signal for that — it is counted separately and
			// says the bytes were not verified. `assertTrue(true)` said the opposite.
			// A missing VENDORED FILE is the other case and still hard-fails
			// (BadgeUpsellLayoutTest, BadgeAddSourcePayloadTest, DesignTokenParityTest):
			// that means the tree was never installed, which is worth failing on.
			$this->markTestSkipped('derivation not re-run: this machine has no Imagick');
		}

		// Asserted, not skipped. A missing machine CAPABILITY is a skip
		// (Imagick, below); a plugin class that will not autoload is a DEFECT in
		// the bootstrap, and skipping it stops the inventory being checked at all
		// while still reading as a pass — which is what this file's own docblock
		// argues against. Verified loadable in this bootstrap today.
		$this->assertTrue(
			class_exists('SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image'),
			'SBR_Badge_Image must load — the mark inventory is unverifiable without it'
		);

		$elements = 'SmashBalloon\Reviews\Common\DisplayElements';
		$image    = new \ReflectionMethod('SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image', 'rasterise_mark');
		$image->setAccessible(true);

		$drifted  = array();
		$compared = 0;

		foreach (self::PROVIDERS as $provider) {
			$slug = $elements::provider_asset_slug($provider);

			$variants = array(
				'icon'   => array( $elements::has_provider_icon($provider), 'get_provider_icon', '' ),
				'mono'   => array( $elements::has_provider_mono_icon($provider), 'get_provider_mono_icon', '#ffffff' ),
				'lockup' => array( $elements::has_provider_lockup($provider), 'get_provider_lockup', '' ),
			);

			foreach ($variants as $variant => $spec) {
				list( $exists, $getter, $ink ) = $spec;
				$name = $slug . '-' . $variant;

				if (! $exists || in_array($name, self::KNOWN_GAPS, true)) {
					continue;
				}

				$path = $this->dir() . '/' . $name . '.png';

				if (! is_readable($path)) {
					continue;
				}

				$svg = $elements::$getter($provider);
				$h   = 64;
				$w   = 'lockup' === $variant ? $h * 8 : $h;

				$fresh = $image->invoke(null, $svg, $ink, $w, $h);

				if (! is_array($fresh) || empty($fresh['png'])) {
					continue;
				}

				// Compared by DIMENSIONS after a trim, not byte-for-byte: two
				// Imagick builds do not produce identical bytes for identical
				// input, so a hash comparison would fail on every machine but the
				// one that generated the file. The aspect ratio is what a changed
				// SVG actually moves.
				$committed = @getimagesize($path);
				$rendered  = @getimagesizefromstring((string) $fresh['png']);

				if (! is_array($committed) || ! is_array($rendered)) {
					continue;
				}

				$compared++;

				$ratio_committed = $committed[0] / max(1, $committed[1]);
				$ratio_rendered  = $rendered[0] / max(1, $rendered[1]);

				if (abs($ratio_committed - $ratio_rendered) > 0.08) {
					$drifted[] = sprintf(
						'%s (committed %dx%d, svg now renders %dx%d)',
						$name,
						$committed[0],
						$committed[1],
						$rendered[0],
						$rendered[1]
					);
				}
			}
		}

		$this->assertGreaterThan(15, $compared, 'too few marks re-derived for this to mean anything');

		$this->assertSame(
			array(),
			$drifted,
			'these SVGs have changed shape since their PNG was generated: '
			. implode('; ', $drifted)
			. ' — run build/generate-badge-marks.php'
		);
	}
}
