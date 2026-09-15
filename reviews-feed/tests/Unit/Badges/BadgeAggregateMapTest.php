<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * The cross-feed arithmetic a multi-source badge publishes.
 *
 * These are the pure parts of get_aggregate_for_map() — combining
 * contributions, converting scales, expanding products, bounding a map. The
 * aggregate itself needs a database this suite has none of, so each helper is
 * driven directly through ReflectionMethod (same approach as
 * BadgeOptionParityTest).
 */
class BadgeAggregateMapTest extends TestCase
{
	/**
	 * Reach a protected static on the renderer.
	 *
	 * @param string $name Method name.
	 *
	 * @return ReflectionMethod
	 */
	private function method($name)
	{
		$method = new ReflectionMethod(SBR_Badge_Renderer::class, $name);
		$method->setAccessible(true);

		return $method;
	}

	/**
	 * One contribution per source, with a rating and a count.
	 *
	 * @param float  $rating Rating.
	 * @param int    $count  Review count.
	 * @param int    $scale  5 or 10.
	 * @param string $word   Booking qualifier.
	 *
	 * @return array
	 */
	private function contribution($rating, $count, $scale = 5, $word = '')
	{
		return array(
			'rating'    => (float) $rating,
			'count'     => (int) $count,
			'resolved'  => true,
			'scale'     => (int) $scale,
			'word'      => $word,
			'providers' => array(),
		);
	}

	/**
	 * The combined rating is weighted by review count, not a flat mean.
	 *
	 * @return void
	 */
	public function test_combine_weights_by_review_count()
	{
		$combined = $this->method('combine')->invoke(
			null,
			array(
				$this->contribution(4.7, 219),
				$this->contribution(5.0, 3),
			)
		);

		// sum(rating*count)/sum(count) = 4.704…, not the flat mean 4.9.
		$this->assertSame(4.7, $combined['rating']);
		$this->assertSame(222, $combined['count']);
		$this->assertSame(5, $combined['scale']);
		$this->assertTrue($combined['resolved']);
	}

	/**
	 * All-Booking keeps the native 0-10 score and the qualifier.
	 *
	 * The word comes from the biggest contributor — with two hotels the badge can
	 * only print one, and the one with the most reviews is the honest pick.
	 *
	 * @return void
	 */
	public function test_combine_keeps_the_ten_point_scale_when_every_source_is_booking()
	{
		$combined = $this->method('combine')->invoke(
			null,
			array(
				$this->contribution(9.4, 1446, 10, 'Exceptional'),
				$this->contribution(8.0, 12, 10, 'Very good'),
			)
		);

		$this->assertSame(10, $combined['scale']);
		$this->assertSame('Exceptional', $combined['word']);
		$this->assertGreaterThan(9.0, $combined['rating']);
	}

	/**
	 * A 0-10 source mixed with stars is halved before it is averaged.
	 *
	 * Without the conversion a 9.4 hotel would pull a 5-star badge above five.
	 *
	 * @return void
	 */
	public function test_combine_converts_a_ten_point_source_on_a_mixed_badge()
	{
		$combined = $this->method('combine')->invoke(
			null,
			array(
				$this->contribution(4.6, 100),
				$this->contribution(9.4, 100, 10, 'Exceptional'),
			)
		);

		// 9.4/10 is 4.7/5, so the pair averages 4.65 → 4.7 (round to 1dp).
		$this->assertSame(5, $combined['scale']);
		$this->assertSame(4.7, $combined['rating']);
		$this->assertLessThanOrEqual(5.0, $combined['rating']);
		$this->assertSame('', $combined['word'], 'A mixed badge has no single qualifier to print.');
	}

	/**
	 * A multi-product source becomes its products, so the dedup can see them.
	 *
	 * @return void
	 */
	public function test_expand_products_returns_the_nested_products()
	{
		$rows = $this->method('expand_products')->invoke(
			null,
			array(
				array(
					'info' => array(
						'id'       => 'woo-source',
						'products' => array(
							array( 'id' => 77, 'name' => 'Kettle', 'average_rating' => 4.5, 'review_count' => 12 ),
							array( 'id' => 91, 'name' => 'Mug', 'average_rating' => 5.0, 'review_count' => 8 ),
						),
					),
				),
			)
		);

		$this->assertCount(2, $rows);
		$this->assertSame('77', $rows[0]['info']['id']);
		$this->assertSame(12, $rows[0]['info']['review_count']);
	}

	/**
	 * A source with nothing nested passes through unchanged.
	 *
	 * Every Google, Yelp, Booking and Airbnb row takes this path.
	 *
	 * @return void
	 */
	public function test_expand_products_passes_a_plain_source_through()
	{
		$row  = array( 'info' => array( 'id' => 'place-abc', 'rating' => 4.8, 'total_rating' => 219 ) );
		$rows = $this->method('expand_products')->invoke(null, array( $row ));

		$this->assertSame(array( $row ), $rows);
	}

	/**
	 * The same product reached through two sources is merged once.
	 *
	 * @return void
	 */
	public function test_unseen_rows_collapses_an_id_already_merged()
	{
		$method = $this->method('unseen_rows');
		$seen   = array();

		$first = $method->invokeArgs(
			null,
			array( array( array( 'info' => array( 'id' => '77' ) ) ), &$seen )
		);

		$second = $method->invokeArgs(
			null,
			array( array( array( 'info' => array( 'id' => '77' ) ), array( 'info' => array( 'id' => '91' ) ) ), &$seen )
		);

		$this->assertCount(1, $first);
		$this->assertCount(1, $second, 'Product 77 was already merged; only 91 is new.');
		$this->assertSame('91', $second[0]['info']['id']);
	}

	/**
	 * A row with no id is kept rather than guessed at.
	 *
	 * @return void
	 */
	public function test_unseen_rows_keeps_rows_that_cannot_be_matched()
	{
		$method = $this->method('unseen_rows');
		$seen   = array();

		$rows = $method->invokeArgs(
			null,
			array( array( array( 'info' => array() ), array( 'info' => array() ) ), &$seen )
		);

		$this->assertCount(2, $rows);
	}

	/**
	 * A map is normalised to positive int keys and non-empty string ids.
	 *
	 * @return void
	 */
	public function test_sanitize_source_map_normalises_its_input()
	{
		$map = $this->method('sanitize_source_map')->invoke(
			null,
			array(
				'12'  => array( 'place-abc', 77, '', array( 'nope' ) ),
				'0'   => array( 'dropped' ),
				'-4'  => array( 'folded' ),
				'bad' => 'not-an-array',
			)
		);

		$this->assertSame(array( 'place-abc', '77' ), $map[12]);
		$this->assertArrayNotHasKey(0, $map, 'Feed 0 is not a feed.');
		$this->assertSame(array( 'folded' ), $map[4], 'absint folds -4 to 4.');
	}

	/**
	 * Anything but an array is no map.
	 *
	 * @return void
	 */
	public function test_sanitize_source_map_rejects_a_non_array()
	{
		$this->assertSame(array(), $this->method('sanitize_source_map')->invoke(null, 'source_map[]=1'));
	}

	/**
	 * Over the feed bound the map is dropped whole, never truncated.
	 *
	 * Truncating would hand get_aggregate_for_map() part of the selection, which
	 * it would then publish as `resolved` — a rating over a subset presented as
	 * the whole. The block passes unregistered attributes straight through, so
	 * this bound is reachable from a post.
	 *
	 * @return void
	 */
	public function test_sanitize_source_map_refuses_a_map_past_the_feed_bound()
	{
		$oversized = array();

		for ($feed_id = 1; $feed_id <= SBR_Badge_Renderer::SOURCE_MAP_MAX_FEEDS + 1; $feed_id++) {
			$oversized[ $feed_id ] = array( 'source-' . $feed_id );
		}

		$this->assertSame(array(), $this->method('sanitize_source_map')->invoke(null, $oversized));
	}

	/**
	 * Same for one feed listing more sources than the bound allows.
	 *
	 * @return void
	 */
	public function test_sanitize_source_map_refuses_a_feed_past_the_source_bound()
	{
		$sources = array();

		for ($i = 0; $i <= SBR_Badge_Renderer::SOURCE_MAP_MAX_SOURCES; $i++) {
			$sources[] = 'source-' . $i;
		}

		$this->assertSame(
			array(),
			$this->method('sanitize_source_map')->invoke(null, array( 12 => $sources ))
		);
	}

	/**
	 * A map exactly at the bound is still a map.
	 *
	 * The refusal is for exceeding the bound; landing on it must keep working, or
	 * the cap quietly becomes one lower than it says.
	 *
	 * @return void
	 */
	public function test_sanitize_source_map_accepts_a_map_at_the_bound()
	{
		$at_bound = array();

		for ($feed_id = 1; $feed_id <= SBR_Badge_Renderer::SOURCE_MAP_MAX_FEEDS; $feed_id++) {
			$at_bound[ $feed_id ] = array( 'source-' . $feed_id );
		}

		$this->assertCount(
			SBR_Badge_Renderer::SOURCE_MAP_MAX_FEEDS,
			$this->method('sanitize_source_map')->invoke(null, $at_bound)
		);
	}

	/**
	 * render() refuses a map it was offered and rejected.
	 *
	 * The alternative is falling through to the single feed named alongside it,
	 * which publishes one feed's rating as the whole badge — the bug the map
	 * exists to fix, reachable from a forged block attribute.
	 *
	 * @return void
	 */
	public function test_render_refuses_a_rejected_map_instead_of_using_one_feed()
	{
		$oversized = array();

		for ($feed_id = 1; $feed_id <= SBR_Badge_Renderer::SOURCE_MAP_MAX_FEEDS + 1; $feed_id++) {
			$oversized[ $feed_id ] = array( 'source-' . $feed_id );
		}

		$this->assertSame(
			'',
			SBR_Badge_Renderer::render(
				array( 'shape' => 'card', 'feed' => 12, 'source_map' => $oversized )
			)
		);
	}

	/**
	 * A contribution with reviews but no readable rating is not averaged as zero.
	 *
	 * The hazard combine() has to survive: a Booking source on Lite reports its
	 * count but no `info.rating` for Parser to read, so it arrives as rating 0.0
	 * with a large count. Weighted in, it drags a 4.8 badge toward one star. The
	 * caller drops it before combine() ever sees it; this pins why.
	 *
	 * @return void
	 */
	public function test_combine_would_be_dragged_down_by_an_unrated_contribution()
	{
		$combined = $this->method('combine')->invoke(
			null,
			array(
				$this->contribution(4.8, 200),
				$this->contribution(0.0, 500),
			)
		);

		$this->assertLessThan(
			2.0,
			$combined['rating'],
			'If this stops being true, combine() started ignoring zero ratings and the '
			. 'skip in get_aggregate_for_map() is no longer what protects the average.'
		);
	}

	/**
	 * A refusal is flagged, so a caller does not read it as a plain miss.
	 *
	 * @return void
	 */
	public function test_closed_flags_the_empty_aggregate()
	{
		$closed = $this->method('closed')->invoke(
			null,
			array(
				'rating'    => 0.0,
				'count'     => 0,
				'resolved'  => false,
				'scale'     => 5,
				'word'      => '',
				'providers' => array(),
			)
		);

		$this->assertTrue($closed['failed']);
		$this->assertFalse($closed['resolved']);
	}
}
