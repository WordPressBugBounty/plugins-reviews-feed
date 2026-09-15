<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;
use SmashBalloon\Reviews\Tests\Doubles\BadgeImageWithBlindImagick;
use SmashBalloon\Reviews\Tests\Doubles\BadgeImageWithNoRasteriser;

/**
 * A mark Imagick cannot draw must come from GD, not cost the whole logo row.
 *
 * `render_png()` already falls back from Imagick to GD for the badge BODY when
 * Imagick is installed but cannot rasterise SVG — a policy.xml with the
 * SVG/MSVG coder disabled, which is standard hardening after CVE-2016-3714.
 * `mark_for()` did not: it branched on `class_exists('\Imagick')`, so on that
 * same host it asked Imagick for every mark, got null for the same reason, and
 * `logo_pngs()`'s all-or-nothing rule dropped the entire row. The badge
 * rendered, crediting no platform at all — on exactly the host the fallback
 * exists for.
 *
 * Rule 19: the trigger has to fire, and no test machine can be asked to have
 * Imagick installed and broken. So the two questions are separate seams —
 * `imagick_available()` and `rasterise_mark()` — and the double below forces
 * the combination that only exists on a hardened host.
 */
final class BadgeMarkRasteriserFallthroughTest extends TestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		if (! function_exists('imagecreatefrompng')) {
			$this->markTestSkipped('GD is not loadable here, so there is no fallback to observe');
		}
	}

	/**
	 * @param string $variant Mark variant.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	private function markThroughDouble($variant)
	{
		$method = new \ReflectionMethod(BadgeImageWithBlindImagick::class, 'mark_for');
		$method->setAccessible(true);

		return $method->invoke(null, 'google', $variant, '', 16, 16);
	}

	/**
	 * The case that was broken: Imagick present, Imagick cannot draw.
	 *
	 * @return void
	 */
	public function test_a_mark_falls_through_to_gd_when_imagick_cannot_draw(): void
	{
		BadgeImageWithBlindImagick::$rasterise_calls = 0;

		$mark = $this->markThroughDouble('icon');

		// Prove the Imagick branch was entered, or this passes for the wrong
		// reason on a host that simply has no Imagick.
		$this->assertSame(1, BadgeImageWithBlindImagick::$rasterise_calls, 'the Imagick branch must have been tried');

		$this->assertIsArray($mark, 'a mark Imagick cannot draw must come from GD, not be dropped');
		$this->assertNotSame('', $mark['png']);
		$this->assertGreaterThan(0, $mark['w']);
		$this->assertGreaterThan(0, $mark['h']);
	}

	/**
	 * And a lockup, which is the wide variant the row is measured from.
	 *
	 * @return void
	 */
	public function test_a_lockup_falls_through_too(): void
	{
		$mark = $this->markThroughDouble('lockup');

		$this->assertIsArray($mark, 'a lockup Imagick cannot draw must come from GD');
	}

	/**
	 * With no GD either, null is still the answer — nothing is invented.
	 *
	 * @return void
	 */
	public function test_no_rasteriser_at_all_still_returns_null(): void
	{
		$method = new \ReflectionMethod(BadgeImageWithNoRasteriser::class, 'mark_for');
		$method->setAccessible(true);

		$this->assertNull($method->invoke(null, 'google', 'icon', '', 16, 16));
	}

	/**
	 * The real class still asks the real question.
	 *
	 * Without this the seam could default to "no Imagick" and every host would
	 * quietly take the GD path.
	 *
	 * @return void
	 */
	public function test_the_real_class_reports_the_hosts_actual_imagick(): void
	{
		$method = new \ReflectionMethod(SBR_Badge_Image::class, 'imagick_available');
		$method->setAccessible(true);

		$this->assertSame(class_exists('\Imagick'), $method->invoke(null));
	}
}
