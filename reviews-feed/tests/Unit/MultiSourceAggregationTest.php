<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Parser;

/**
 * SMASH-1583 — multi-source feed header aggregation.
 *
 * Covers the combined review count + weighted average rating shown in the
 * feed header when a feed mixes sources from more than one provider.
 *
 * Two stacked bugs are exercised here:
 *
 *   Bug 1 — Facebook persists `total_rating: 0` (legacy `rating_count` is
 *           deprecated for recommendation Pages), so the source contributed 0
 *           to the combined count even though its reviews are cached. Fixed by
 *           Parser::backfill_review_counts(), which fills an empty count from
 *           the actual cached reviews keyed by source.id.
 *
 *   Bug 2 — Parser::get_average_rating() averaged source ratings as equal
 *           peers, so 3 reviews @5.0 swung the headline as hard as 219 @4.7.
 *           Fixed by weighting each source's rating by its review count.
 *
 * The matrix deliberately goes beyond the reported Google+Facebook pair:
 * Google+Yelp+Trustpilot, single-source, equal-count, all-zero-count (BC
 * fallback), WooCommerce keys, and the SMASH-1412 feed_aggregated short-circuit.
 *
 * Assertions run through the public aggregation API (get_average_rating /
 * get_num_ratings) on backfilled header data, which is exactly what the feed
 * header template does at render time.
 */
class MultiSourceAggregationTest extends TestCase
{
	private function parser(): Parser
	{
		return new Parser();
	}

	/**
	 * Build a header-data source entry.
	 */
	private function source(array $info): array
	{
		return ['info' => $info];
	}

	/**
	 * Build N normalized cached review posts for a given source id.
	 */
	private function posts(string $source_id, int $n): array
	{
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$out[] = [
				'source'   => ['id' => $source_id, 'url' => ''],
				'rating'   => 5,
				'reviewer' => ['name' => 'Reviewer ' . $i],
				'provider' => ['name' => 'facebook'],
			];
		}
		return $out;
	}

	/*
	|--------------------------------------------------------------------------
	| The reported scenario: Google (219 @ 4.7) + Facebook (3 @ 5.0)
	|--------------------------------------------------------------------------
	*/

	public function test_google_plus_facebook_reports_weighted_average_after_backfill(): void
	{
		$header = [
			$this->source(['id' => 'GOOG', 'rating' => 4.7, 'total_rating' => 219]),
			$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 0]),
		];
		// Cache holds all 3 FB recommendations + a capped page of Google reviews.
		$posts = array_merge($this->posts('FB1', 3), $this->posts('GOOG', 20));

		$header = $this->parser()->backfill_review_counts($header, $posts);

		// Average is the honest weighted mean, not round((4.7+5.0)/2,1)=4.9.
		$this->assertSame(4.7, $this->parser()->get_average_rating($header));
		// Combined count is 219 + 3 = 222 (NOT 219, and NOT 219 + 20 cached).
		$this->assertSame(222, $this->parser()->get_num_ratings($header));
	}

	public function test_average_is_already_correct_even_if_facebook_count_stays_zero(): void
	{
		// Without backfill the weighted mean drops the 0-count FB source, which
		// still yields the correct 4.7 (Google only). The count is the part that
		// needs the backfill, not the average.
		$header = [
			$this->source(['id' => 'GOOG', 'rating' => 4.7, 'total_rating' => 219]),
			$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 0]),
		];
		$this->assertSame(4.7, $this->parser()->get_average_rating($header));
		$this->assertSame(219, $this->parser()->get_num_ratings($header));
	}

	/*
	|--------------------------------------------------------------------------
	| backfill_review_counts() behaviour (asserted via the public count API)
	|--------------------------------------------------------------------------
	*/

	public function test_backfill_fills_empty_count_from_cached_reviews(): void
	{
		$header = [$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 0])];
		$out = $this->parser()->backfill_review_counts($header, $this->posts('FB1', 3));
		$this->assertSame(3, $this->parser()->get_num_ratings($out));
	}

	public function test_backfill_does_not_lower_a_higher_reported_count(): void
	{
		// Google reports 219 but only 20 are cached — counting cached posts
		// would UNDER-report it, so max(219, 20) must keep 219.
		$header = [$this->source(['id' => 'GOOG', 'rating' => 4.7, 'total_rating' => 219])];
		$out = $this->parser()->backfill_review_counts($header, $this->posts('GOOG', 20));
		$this->assertSame(219, $this->parser()->get_num_ratings($out));
	}

	public function test_backfill_corrects_a_stale_low_reported_count(): void
	{
		// SMASH-1583 live case (InstaQA): Facebook stored a stale total_rating: 1
		// (deprecated rating_count) but 2 recommendations are cached and shown.
		// max(1, 2) must correct the header up to 2, not leave it at 1.
		$header = [$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 1])];
		$out = $this->parser()->backfill_review_counts($header, $this->posts('FB1', 2));
		$this->assertSame(2, $this->parser()->get_num_ratings($out));
	}

	public function test_backfill_respects_review_count_key_and_does_not_override(): void
	{
		// WooCommerce-style source reports `review_count`, not `total_rating`.
		// get_num_ratings reads total_rating ?? review_count, so a correct
		// backfill (no override) yields 100, while a wrong one would yield 5.
		$header = [$this->source(['id' => 'WOO', 'rating' => 4.0, 'review_count' => 100])];
		$out = $this->parser()->backfill_review_counts($header, $this->posts('WOO', 5));
		$this->assertSame(100, $this->parser()->get_num_ratings($out));
	}

	public function test_backfill_is_noop_with_no_posts(): void
	{
		$header = [$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 0])];
		$this->assertSame($header, $this->parser()->backfill_review_counts($header, []));
	}

	public function test_backfill_skips_posts_without_source_id(): void
	{
		$header = [$this->source(['id' => 'FB1', 'rating' => 5.0, 'total_rating' => 0])];
		$posts = [
			['rating' => 5, 'provider' => ['name' => 'facebook']], // no source key
			['source' => ['id' => ''], 'rating' => 5],             // empty source id
			['source' => 'not-an-array', 'rating' => 5],           // malformed source
		];
		$out = $this->parser()->backfill_review_counts($header, $posts);
		// Nothing matched FB1 → count stays 0.
		$this->assertSame(0, $this->parser()->get_num_ratings($out));
	}

	/*
	|--------------------------------------------------------------------------
	| get_average_rating() — weighting across more provider combinations
	|--------------------------------------------------------------------------
	*/

	public function test_weighted_average_diverges_from_unweighted_when_volumes_differ(): void
	{
		// 100 @ 4.0 + 1 @ 5.0 → weighted (400+5)/101 = 4.0 (unweighted would be 4.5).
		$header = [
			$this->source(['id' => 'A', 'rating' => 4.0, 'total_rating' => 100]),
			$this->source(['id' => 'B', 'rating' => 5.0, 'total_rating' => 1]),
		];
		$this->assertSame(4.0, $this->parser()->get_average_rating($header));
	}

	public function test_weighted_equals_unweighted_when_volumes_match(): void
	{
		$header = [
			$this->source(['id' => 'A', 'rating' => 4.0, 'total_rating' => 100]),
			$this->source(['id' => 'B', 'rating' => 5.0, 'total_rating' => 100]),
		];
		$this->assertSame(4.5, $this->parser()->get_average_rating($header));
	}

	public function test_three_source_google_yelp_trustpilot_weighted(): void
	{
		// (4.7*219 + 4.0*50 + 3.0*10) / 279 = 1259.3/279 = 4.51… → 4.5
		$header = [
			$this->source(['id' => 'GOOG', 'rating' => 4.7, 'total_rating' => 219]),
			$this->source(['id' => 'YELP', 'rating' => 4.0, 'total_rating' => 50]),
			$this->source(['id' => 'TRIP', 'rating' => 3.0, 'total_rating' => 10]),
		];
		$this->assertSame(4.5, $this->parser()->get_average_rating($header));
		$this->assertSame(279, $this->parser()->get_num_ratings($header));
	}

	public function test_woocommerce_average_rating_key_is_weighted_by_review_count(): void
	{
		// Woo sources expose `average_rating` + `review_count` (not rating/total_rating).
		// (4.0*100 + 5.0*1)/101 = 405/101 = 4.0099 → 4.0
		$header = [
			$this->source(['id' => 'W1', 'average_rating' => 4.0, 'review_count' => 100]),
			$this->source(['id' => 'W2', 'average_rating' => 5.0, 'review_count' => 1]),
		];
		$this->assertSame(4.0, $this->parser()->get_average_rating($header));
		$this->assertSame(101, $this->parser()->get_num_ratings($header));
	}

	public function test_single_source_returns_its_own_rating_and_count(): void
	{
		$header = [$this->source(['id' => 'GOOG', 'rating' => 4.7, 'total_rating' => 219])];
		$this->assertSame(4.7, $this->parser()->get_average_rating($header));
		$this->assertSame(219, $this->parser()->get_num_ratings($header));
	}

	/*
	|--------------------------------------------------------------------------
	| Backwards-compatibility fallbacks
	|--------------------------------------------------------------------------
	*/

	public function test_all_zero_counts_falls_back_to_unweighted_mean(): void
	{
		// No source reports a usable count and nothing is backfilled → preserve
		// the legacy unweighted mean instead of dividing by zero / showing 0.0.
		$header = [
			$this->source(['id' => 'A', 'rating' => 4.0, 'total_rating' => 0]),
			$this->source(['id' => 'B', 'rating' => 5.0, 'total_rating' => 0]),
		];
		$this->assertSame(4.5, $this->parser()->get_average_rating($header));
	}

	public function test_feed_aggregated_short_circuit_is_preserved(): void
	{
		// SMASH-1412 EDD/Woo dedup path must still win and is untouched by 1583.
		$header = [
			$this->source([
				'id' => 'EDD1',
				'rating' => 4.9,
				'total_rating' => 999,
				'feed_aggregated' => true,
				'feed_average_rating' => 4.3,
				'feed_total_review_count' => 500,
			]),
			$this->source(['id' => 'EDD2', 'rating' => 1.0, 'total_rating' => 1]),
		];
		$this->assertSame(4.3, $this->parser()->get_average_rating($header));
		$this->assertSame(500, $this->parser()->get_num_ratings($header));
	}

	public function test_non_array_input_returns_empty_string(): void
	{
		$this->assertSame('', $this->parser()->get_average_rating('nope'));
		$this->assertSame('', $this->parser()->get_num_ratings(null));
	}

	public function test_empty_feed_does_not_divide_by_zero(): void
	{
		$this->assertSame(0.0, $this->parser()->get_average_rating([]));
		$this->assertSame(0, $this->parser()->get_num_ratings([]));
	}

	/*
	|--------------------------------------------------------------------------
	| Exhaustive 2-by-2 provider matrix
	|--------------------------------------------------------------------------
	|
	| Every unordered pair of the real provider key-shapes. Aggregation is
	| provider-agnostic (it reads rating ?? average_rating and total_rating ??
	| review_count), so this proves the combined count + weighted average are
	| correct for Google+Yelp, Yelp+Trustpilot, Woo+EDD, WP.org+TripAdvisor,
	| Facebook+anything (count backfilled from cache), etc. — not just the one
	| pair in the ticket. Facebook is left to live QA; here it stands in for any
	| provider that reports no count and is backfilled from cached reviews.
	|
	| Expected count + average are computed independently from the profile
	| inputs (straight Σrating×count / Σcount), so a regression back to the
	| unweighted mean — or a dropped source — fails the assertion.
	*/

	/**
	 * Provider profiles keyed by name. `cached` = reviews present in the feed
	 * cache (only meaningful for providers that report no count, e.g. Facebook
	 * recommendations whose total_rating persists as 0).
	 *
	 * @return array<string,array{info:array<string,mixed>,cached:int}>
	 */
	private static function providerProfiles(): array
	{
		return [
			'google'       => ['info' => ['id' => 'google',       'rating' => 4.7,         'total_rating' => 219],  'cached' => 0],
			'yelp'         => ['info' => ['id' => 'yelp',         'rating' => 4.0,         'total_rating' => 88],   'cached' => 0],
			'trustpilot'   => ['info' => ['id' => 'trustpilot',   'rating' => 3.5,         'total_rating' => 1240], 'cached' => 0],
			'tripadvisor'  => ['info' => ['id' => 'tripadvisor',  'rating' => 4.2,         'total_rating' => 51],   'cached' => 0],
			'woocommerce'  => ['info' => ['id' => 'woocommerce',  'average_rating' => 4.6, 'review_count' => 37],   'cached' => 0],
			'edd'          => ['info' => ['id' => 'edd',          'average_rating' => 4.9, 'review_count' => 12],   'cached' => 0],
			'wordpressorg' => ['info' => ['id' => 'wordpressorg', 'rating' => 4.8,         'total_rating' => 300],  'cached' => 0],
			'facebook'     => ['info' => ['id' => 'facebook',     'rating' => 5.0,         'total_rating' => 0],    'cached' => 3],
		];
	}

	private static function effectiveRating(array $info): float
	{
		return (float) ($info['rating'] ?? $info['average_rating'] ?? 0);
	}

	private static function effectiveCount(array $profile): int
	{
		$info     = $profile['info'];
		$reported = (int) ($info['total_rating'] ?? $info['review_count'] ?? 0);
		return $reported > 0 ? $reported : (int) $profile['cached'];
	}

	/**
	 * One case per unordered provider pair.
	 *
	 * @return array<string,array{0:string,1:array,2:array,3:int,4:float}>
	 */
	public static function providerPairProvider(): array
	{
		$profiles = self::providerProfiles();
		$names    = array_keys($profiles);
		$cases    = [];

		for ($i = 0; $i < count($names); $i++) {
			for ($j = $i + 1; $j < count($names); $j++) {
				$a = $profiles[$names[$i]];
				$b = $profiles[$names[$j]];

				$header = [['info' => $a['info']], ['info' => $b['info']]];

				// Synthesize cached reviews for any profile that relies on backfill.
				$posts = [];
				foreach ([$a, $b] as $p) {
					for ($k = 0; $k < (int) $p['cached']; $k++) {
						$posts[] = ['source' => ['id' => $p['info']['id']], 'rating' => 5];
					}
				}

				$ecA = self::effectiveCount($a);
				$ecB = self::effectiveCount($b);
				$expectedCount = $ecA + $ecB;
				$expectedAvg   = $expectedCount > 0
					? round((self::effectiveRating($a['info']) * $ecA + self::effectiveRating($b['info']) * $ecB) / $expectedCount, 1)
					: 0.0;

				$label = $names[$i] . '+' . $names[$j];
				$cases[$label] = [$label, $header, $posts, $expectedCount, $expectedAvg];
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider providerPairProvider
	 */
	public function test_every_provider_pair_reports_weighted_average_and_summed_count(
		string $label,
		array $header,
		array $posts,
		int $expectedCount,
		float $expectedAvg
	): void {
		$header = $this->parser()->backfill_review_counts($header, $posts);

		$this->assertSame(
			$expectedCount,
			$this->parser()->get_num_ratings($header),
			"Combined count wrong for {$label}"
		);
		$this->assertEqualsWithDelta(
			$expectedAvg,
			$this->parser()->get_average_rating($header),
			0.001,
			"Weighted average wrong for {$label}"
		);
	}
}
