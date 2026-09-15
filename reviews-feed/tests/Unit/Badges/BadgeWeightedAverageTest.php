<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * The number on the badge is a count-weighted mean, and it has to be right.
 *
 * Every expectation here is computed independently in this file rather than
 * taken from combine() — an assertion that asks the function under test what
 * the answer should be cannot fail.
 *
 * The defect this was written for: a contributor with no reviews was weighted
 * as 1, and a source with no reviews has rating 0.0 — so it fed `0.0 x 1` into
 * the numerator and 1 into the divisor and pulled the mean toward zero. 4.0
 * from ten reviews beside one empty source published 3.6; 5.0 from five reviews
 * beside two empty ones published 3.6. A large sibling hides it, which is why
 * it survived: it shows on a new site, where the badge matters most.
 */
class BadgeWeightedAverageTest extends TestCase
{
	/**
	 * @param float $rating Rating.
	 * @param int   $count  Review count.
	 * @param int   $scale  5 or 10.
	 *
	 * @return array<string, mixed>
	 */
	private function contribution($rating, $count, $scale = 5)
	{
		return array(
			'rating'    => $rating,
			'count'     => $count,
			'resolved'  => true,
			'scale'     => $scale,
			'word'      => '',
			'providers' => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $contributions Contributions.
	 *
	 * @return array<string, mixed>
	 */
	private function combine(array $contributions)
	{
		$m = new \ReflectionMethod(SBR_Badge_Renderer::class, 'combine');
		$m->setAccessible(true);

		return $m->invoke(null, $contributions);
	}

	/**
	 * The mean a reader would compute by hand: only reviews that exist weigh.
	 *
	 * @param array<int, array<string, mixed>> $contributions Contributions.
	 *
	 * @return array{0: float, 1: int}
	 */
	private function byHand(array $contributions)
	{
		$tens = 0;

		foreach ($contributions as $c) {
			if ((int) $c['scale'] === 10) {
				$tens++;
			}
		}

		$all_ten = $contributions !== array() && $tens === count($contributions);

		$sum   = 0.0;
		$total = 0;

		foreach ($contributions as $c) {
			$rating = (float) $c['rating'];

			// A 0-10 source only keeps its own scale when every source shares it.
			if (! $all_ten && (int) $c['scale'] === 10) {
				$rating = $rating / 2;
			}

			$sum   += $rating * (int) $c['count'];
			$total += (int) $c['count'];
		}

		return array( $total > 0 ? round($sum / $total, 1) : 0.0, $total );
	}

	/**
	 * @return array<string, array{0: array<int, array<string, mixed>>}>
	 */
	public static function shapes()
	{
		$c = static function ($r, $n, $s = 5) {
			return array( 'rating' => $r, 'count' => $n, 'resolved' => true, 'scale' => $s, 'word' => '', 'providers' => array() );
		};

		return array(
			'one source'                  => array( array( $c(4.6, 100) ) ),
			'two equal counts'            => array( array( $c(5.0, 100), $c(3.0, 100) ) ),
			'a big one and a small one'   => array( array( $c(5.0, 1000), $c(1.0, 10) ) ),
			'mixed 0-10 and 0-5'          => array( array( $c(4.0, 100), $c(9.0, 100, 10) ) ),
			'every source 0-10'           => array( array( $c(9.4, 1444, 10), $c(9.5, 1080, 10) ) ),
			'one empty source alongside'  => array( array( $c(4.0, 10), $c(0.0, 0) ) ),
			'two empty, one real'         => array( array( $c(5.0, 5), $c(0.0, 0), $c(0.0, 0) ) ),
			'empty beside a large one'    => array( array( $c(4.6, 4775), $c(0.0, 0) ) ),
			'many sources'                => array( array( $c(4.6, 144047), $c(4.8, 3072), $c(4.9, 4775), $c(0.0, 0) ) ),
		);
	}

	/**
	 * @dataProvider shapes
	 *
	 * @param array<int, array<string, mixed>> $contributions Contributions.
	 *
	 * @return void
	 */
	public function test_the_mean_matches_one_computed_by_hand(array $contributions)
	{
		list( $expected_rating, $expected_count ) = $this->byHand($contributions);

		$got = $this->combine($contributions);

		$this->assertEqualsWithDelta(
			$expected_rating,
			(float) $got['rating'],
			0.05,
			'the published rating is not the count-weighted mean of its sources'
		);

		$this->assertSame(
			$expected_count,
			(int) $got['count'],
			'the published count is not the sum of its sources\' counts'
		);
	}

	/**
	 * The specific regression, stated as its own case so it cannot be lost.
	 *
	 * @return void
	 */
	public function test_an_empty_source_does_not_drag_the_average_down()
	{
		$with = $this->combine(array( $this->contribution(5.0, 5), $this->contribution(0.0, 0), $this->contribution(0.0, 0) ));
		$without = $this->combine(array( $this->contribution(5.0, 5) ));

		$this->assertSame(
			(float) $without['rating'],
			(float) $with['rating'],
			'sources with no reviews changed the average — they have nothing to average in'
		);

		$this->assertSame(5.0, (float) $with['rating'], 'every review is five stars, so the badge says five');
		$this->assertSame(5, (int) $with['count'], 'and counts only the reviews that exist');
	}

	/**
	 * A rating with no count is still worth carrying — that part was deliberate.
	 *
	 * @return void
	 */
	public function test_a_source_with_a_rating_but_no_count_still_counts_for_something()
	{
		// Numbers chosen so the effect survives rounding to one decimal: with
		// 4.5/0 beside 4.0/10 the mean is 4.045, which rounds to 4.0 and would
		// make this assertion pass whether or not the source was counted.
		$with    = $this->combine(array( $this->contribution(5.0, 0), $this->contribution(3.0, 4) ));
		$without = $this->combine(array( $this->contribution(3.0, 4) ));

		$this->assertSame(3.0, (float) $without['rating'], 'the sibling on its own');

		$this->assertGreaterThan(
			(float) $without['rating'],
			(float) $with['rating'],
			'a source carrying a real rating with no count must not be discarded'
		);

		$this->assertSame(4, (int) $with['count'], 'but it contributes no reviews to the count');
	}

	/**
	 * Nothing to report is 0, not a division by zero.
	 *
	 * @return void
	 */
	public function test_all_empty_reports_zero()
	{
		$got = $this->combine(array( $this->contribution(0.0, 0), $this->contribution(0.0, 0) ));

		$this->assertSame(0.0, (float) $got['rating']);
		$this->assertSame(0, (int) $got['count']);
	}
}
