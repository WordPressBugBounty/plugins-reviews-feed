<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\FeedDisplay;

/**
 * SMASH-782 follow-up (2026-07-08) — Booking feed-header rating.
 *
 * FeedDisplay::get_booking_header_rating() shows Booking's native 0-10 score
 * ONLY for a booking-only feed (every source carries a numeric review_score).
 * For a booking-only MULTI-source feed the aggregate must be COUNT-WEIGHTED by
 * each hotel's review volume (review_count ?? total_rating), kept on the 0-10
 * scale — a high-volume hotel dominates a low-volume one. Previously it was an
 * unweighted array_sum/count mean. Any non-Booking source disqualifies the
 * feed (is_booking_only=false) so a mixed collection falls back to the 0-5
 * star average elsewhere.
 *
 * Uses reflection (newInstanceWithoutConstructor) because the method reads only
 * its $businesses argument — no Feed/Parser/WP state — so the real constructor
 * (which needs Feed+Parser and get_option) is not required.
 */
final class Smash782BookingHeaderRatingTest extends TestCase
{
	private function fd(): FeedDisplay
	{
		return (new \ReflectionClass(FeedDisplay::class))->newInstanceWithoutConstructor();
	}

	/** @param array<int,array<string,mixed>> $sources */
	private function biz(array $sources): array
	{
		return array_map(static fn ($s) => ['info' => json_encode($s)], $sources);
	}

	public function test_booking_only_multi_source_score_is_count_weighted(): void
	{
		$r = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.5, 'total_rating' => 12312, 'review_score_word' => 'Very good'],
			['review_score' => 7.8, 'total_rating' => 1935,  'review_score_word' => 'Good'],
			['review_score' => 8.6, 'total_rating' => 372,   'review_score_word' => 'Fabulous'],
		]));
		$this->assertTrue($r['is_booking_only']);
		// (8.5*12312 + 7.8*1935 + 8.6*372) / 14619 = 8.41 -> 8.4  (unweighted mean would be 8.3)
		$this->assertSame(8.4, $r['score']);
	}

	public function test_single_source_score_unchanged_bc(): void
	{
		$r = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.5, 'total_rating' => 12312, 'review_score_word' => 'Very good'],
		]));
		$this->assertTrue($r['is_booking_only']);
		$this->assertSame(8.5, $r['score']);
		$this->assertSame('Very good', $r['word']);
	}

	public function test_no_counts_falls_back_to_unweighted_mean(): void
	{
		$r = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.5],
			['review_score' => 7.8],
			['review_score' => 8.6],
		]));
		$this->assertTrue($r['is_booking_only']);
		// no usable count on any source -> unweighted (8.5+7.8+8.6)/3 = 8.3
		$this->assertSame(8.3, $r['score']);
	}

	public function test_review_count_key_also_weights(): void
	{
		// some payloads carry review_count instead of total_rating
		$r = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 9.0, 'review_count' => 1000],
			['review_score' => 5.0, 'review_count' => 0],
		]));
		// 9.0*1000 + 5.0*0 = 9000 / 1000 = 9.0
		$this->assertSame(9.0, $r['score']);
	}

	public function test_mixed_feed_is_not_booking_only(): void
	{
		$r = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.5, 'total_rating' => 12312],
			['rating' => 4.6, 'review_count' => 300], // a non-Booking source: no review_score
		]));
		$this->assertFalse($r['is_booking_only']);
		$this->assertSame(0.0, $r['score']);
	}

	public function test_word_shown_only_when_uniform(): void
	{
		$same = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.0, 'total_rating' => 100, 'review_score_word' => 'Very good'],
			['review_score' => 8.4, 'total_rating' => 100, 'review_score_word' => 'Very good'],
		]));
		$this->assertSame('Very good', $same['word']);

		$diff = $this->fd()->get_booking_header_rating($this->biz([
			['review_score' => 8.0, 'total_rating' => 100, 'review_score_word' => 'Very good'],
			['review_score' => 9.2, 'total_rating' => 100, 'review_score_word' => 'Superb'],
		]));
		$this->assertSame('', $diff['word']);
	}
}
