<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;
use SmashBalloon\Reviews\Common\Parser;

/**
 * Two selected sources pointing at one product must count it once.
 *
 * scope_to_source() dedups within one call, so the overlap survives in the
 * merge — and two Woo/EDD sources can carry the same product (SMASH-1412,
 * Feed.php:461-467). It also strips `feed_aggregated`, so nothing downstream
 * hides the double count.
 *
 * Two layers. `scope_to_source()` is driven through reflection — it is protected
 * static and takes plain arrays, so no database is involved, which is what the
 * first version of this docblock got wrong when it claimed the seam was
 * unreachable. Parser is then driven directly on the scoped result and on
 * hand-built rows, pinning the arithmetic property either side of the dedup.
 */
class BadgeMergeDedupTest extends TestCase
{
	/**
	 * One product, described twice, is one product.
	 *
	 * @return void
	 */
	public function test_the_same_product_twice_is_counted_once()
	{
		$product = array(
			'info' => array(
				'id'           => 'wc-product-77',
				'rating'       => 4.0,
				'total_rating' => 12,
			),
		);

		$parser = new Parser();

		$doubled = array( $product, $product );
		$deduped = array( $product );

		$this->assertSame(
			24,
			(int) $parser->get_num_ratings($doubled),
			'The hazard: merging the same row twice really does double the count.'
		);

		$this->assertSame(
			12,
			(int) $parser->get_num_ratings($deduped),
			'And collapsing it restores the true count.'
		);
	}

	/**
	 * Distinct products still add up.
	 *
	 * The dedup must key on identity, not collapse everything — a badge over
	 * two different products has to report both.
	 *
	 * @return void
	 */
	public function test_different_products_still_accumulate()
	{
		$parser = new Parser();

		$rows = array(
			array( 'info' => array( 'id' => 'wc-product-77', 'rating' => 4.0, 'total_rating' => 12 ) ),
			array( 'info' => array( 'id' => 'wc-product-91', 'rating' => 5.0, 'total_rating' => 8 ) ),
		);

		$this->assertSame(20, (int) $parser->get_num_ratings($rows));
	}

	/**
	 * The average is count-weighted, which is why the double count is not cosmetic.
	 *
	 * Counting a low-rated product twice drags the headline down; counting a
	 * high-rated one twice inflates it. Either way the published number is one
	 * nobody can reproduce from the reviews on the page.
	 *
	 * @return void
	 */
	public function test_a_double_counted_product_moves_the_average()
	{
		$parser = new Parser();

		$honest = array(
			array( 'info' => array( 'id' => 'a', 'rating' => 3.0, 'total_rating' => 100 ) ),
			array( 'info' => array( 'id' => 'b', 'rating' => 5.0, 'total_rating' => 100 ) ),
		);

		$doubled = array(
			$honest[0],
			$honest[0],
			$honest[1],
		);

		$this->assertSame(4.0, (float) $parser->get_average_rating($honest));

		$this->assertNotSame(
			4.0,
			(float) $parser->get_average_rating($doubled),
			'If this ever matches, the weighting stopped depending on counts and '
			. 'the dedup below it stopped mattering — check Parser, not this test.'
		);
	}

	/**
	 * The dedup itself, driven through the real seam.
	 *
	 * The review round found this file's docblock claiming coverage its assertions
	 * did not provide: every case drove Parser with arrays the test built pre- or
	 * post-dedup, so a regression that stopped deduplicating left the file green.
	 * The stated reason — "the renderer's path needs a database this suite has none
	 * of" — is not true of THIS method: `scope_to_source()` is protected static and
	 * takes plain arrays, so reflection reaches it with no WordPress at all.
	 *
	 * @return void
	 */
	public function test_scope_to_source_collapses_two_entries_for_one_product()
	{
		$entry = array(
			'info' => array(
				'id'                      => 'wc-product-77',
				'rating'                  => 4.0,
				'total_rating'            => 12,
				// Present on a real header entry, and stripped by the scope so
				// nothing downstream can answer from the feed-wide short circuit.
				'feed_aggregated'         => true,
				'feed_average_rating'     => 3.1,
				'feed_total_review_count' => 999,
			),
		);

		$scope = new \ReflectionMethod(SBR_Badge_Renderer::class, 'scope_to_source');
		$scope->setAccessible(true);

		// The same product described by two selected sources — the SMASH-1412 case.
		$scoped = $scope->invoke(null, array( $entry, $entry ), 'wc-product-77');

		$this->assertCount(1, $scoped, 'two entries for one product must collapse to one');

		$this->assertArrayNotHasKey('feed_aggregated', $scoped[0]['info']);
		$this->assertArrayNotHasKey('feed_average_rating', $scoped[0]['info']);
		$this->assertArrayNotHasKey('feed_total_review_count', $scoped[0]['info']);

		// And the count Parser then reads is the single product's, not doubled.
		$parser = new Parser();

		$this->assertSame(12, (int) $parser->get_num_ratings($scoped));
	}

	/**
	 * Distinct products are not collapsed by the same pass.
	 *
	 * The other half of the property: a dedup that returned one entry for
	 * everything would satisfy the test above.
	 *
	 * @return void
	 */
	public function test_scope_to_source_keeps_a_different_product_out()
	{
		$wanted = array( 'info' => array( 'id' => 'wc-product-77', 'rating' => 4.0, 'total_rating' => 12 ) );
		$other  = array( 'info' => array( 'id' => 'wc-product-88', 'rating' => 2.0, 'total_rating' => 40 ) );

		$scope = new \ReflectionMethod(SBR_Badge_Renderer::class, 'scope_to_source');
		$scope->setAccessible(true);

		$scoped = $scope->invoke(null, array( $wanted, $other ), 'wc-product-77');

		$this->assertCount(1, $scoped);
		$this->assertSame('wc-product-77', $scoped[0]['info']['id']);
	}
}
