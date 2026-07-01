<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Frontend;

/**
 * Pins SBR_Review_Alert_Frontend::star_fill_states() — the one formula that
 * turns an average rating into per-star full/half/empty glyphs (SMASH-1616).
 *
 * This is the AUTHORITATIVE side of a cross-language contract: the popup
 * template (templates/review-alerts/popup.php) consumes this, and the
 * customizer's React starFillStates() in ReviewAlertPreview.js mirrors this
 * exact formula. The original bug was the preview pre-rounding the average
 * (Math.round(4.7) -> 5 solid stars) while the frontend drew 4 + a half — so
 * "4.7 -> 4 full + 1 half" is the case that must hold on both sides.
 */
final class ReviewAlertStarFillTest extends TestCase
{
	/**
	 * @dataProvider provideRatings
	 *
	 * @param string[] $expected
	 */
	public function test_star_fill_states(float $average, array $expected): void
	{
		$this->assertSame($expected, SBR_Review_Alert_Frontend::star_fill_states($average));
	}

	/** @return array<string, array{0: float, 1: string[]}> */
	public static function provideRatings(): array
	{
		$f = 'full';
		$h = 'half';
		$e = 'empty';

		return [
			// The headline regression case: Cosmetic source 4.7 (Google).
			'4.7 -> 4 full + 1 half'  => [4.7, [$f, $f, $f, $f, $h]],
			// Collection Cosmetic+Lombard weighted 4.6.
			'4.6 -> 4 full + 1 half'  => [4.6, [$f, $f, $f, $f, $h]],
			// Exact .5 boundary rounds toward half, not full.
			'4.5 -> 4 full + 1 half'  => [4.5, [$f, $f, $f, $f, $h]],
			// Just under .5 stays empty (no premature half).
			'4.4 -> 4 full + 1 empty' => [4.4, [$f, $f, $f, $f, $e]],
			// Whole number: no half.
			'4.0 -> 4 full + 1 empty' => [4.0, [$f, $f, $f, $f, $e]],
			// Lombard source 3.4 (Yelp): 3.4 < 3.5 so the 4th star is empty.
			'3.4 -> 3 full + 2 empty' => [3.4, [$f, $f, $f, $e, $e]],
			// Perfect score: all full, no overflow.
			'5.0 -> 5 full'           => [5.0, [$f, $f, $f, $f, $f]],
			// Zero / missing metadata: all empty.
			'0.0 -> 5 empty'          => [0.0, [$e, $e, $e, $e, $e]],
		];
	}
}
