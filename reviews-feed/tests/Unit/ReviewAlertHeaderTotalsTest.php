<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Frontend;

/**
 * Pins SBR_Review_Alert_Frontend::resolve_header_totals() — the shared helper that
 * gives a Review Alert the SAME headline total + average as the feed header
 * (SMASH-1616). Used by both the frontend render path and the customizer preview
 * (SBR_Review_Alert_Service::get_preview_reviews), so this one test guards both.
 *
 * It's a pure static seam (the only WP touch is the $feed's get_header_data(),
 * which we stub), so it runs in the plain-PHPUnit suite.
 */
final class ReviewAlertHeaderTotalsTest extends TestCase
{
	/** A minimal Feed stand-in exposing get_header_data(). */
	private static function feedWith(array $businesses): object
	{
		return new class ($businesses) {
			private $businesses;
			public function __construct(array $b)
			{
				$this->businesses = $b;
			}
			public function get_header_data(): array
			{
				return $this->businesses;
			}
		};
	}

	public function test_uses_source_metadata_total_and_average(): void
	{
		// Keyless Google: ~9 cached, but metadata carries 211 / 4.7.
		$feed = self::feedWith([['info' => ['rating' => 4.7, 'total_rating' => 211]]]);
		[$total, $average] = SBR_Review_Alert_Frontend::resolve_header_totals($feed, [], 9, 45);
		$this->assertSame(211, $total);
		$this->assertSame(4.7, $average);
	}

	public function test_collection_sums_total_and_weights_average(): void
	{
		// Google (4.7/211) + Yelp The Lombard (3.4/25) → 236 total, weighted 4.6 (not 4.05).
		$feed = self::feedWith([
			['info' => ['rating' => 4.7, 'total_rating' => 211]],
			['info' => ['rating' => 3.4, 'total_rating' => 25]],
		]);
		[$total, $average] = SBR_Review_Alert_Frontend::resolve_header_totals($feed, [], 9, 45);
		$this->assertSame(236, $total);
		$this->assertSame(4.6, $average);
	}

	public function test_falls_back_to_cached_when_no_metadata(): void
	{
		// Manual feed / provider with no count → use the cached complete-review set.
		$feed = self::feedWith([]);
		[$total, $average] = SBR_Review_Alert_Frontend::resolve_header_totals($feed, [], 8, 36);
		$this->assertSame(8, $total);
		$this->assertSame(4.5, $average); // 36 / 8
	}

	public function test_zero_cached_no_metadata_defaults_to_five(): void
	{
		$feed = self::feedWith([]);
		[$total, $average] = SBR_Review_Alert_Frontend::resolve_header_totals($feed, [], 0, 0);
		$this->assertSame(0, $total);
		$this->assertSame(5.0, $average);
	}
}
