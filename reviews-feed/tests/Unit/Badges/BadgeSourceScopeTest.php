<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * Per-source rating scope (SBR-163, pitch task 4).
 *
 * Pins the pitch's *"showing ratings both per-product and site-wide."* The pitch
 * left the key open ("Woo/EDD product ID, source?"); it is the SOURCE id, because
 * WooCommerce_Review_Cache_Handler normalises a review with
 * `source.id => (string) $product_id`.
 *
 * The Plus-gating half of this file went on 19 Aug. Styles and colours are not
 * gated by tier — decided then — so `apply_tier_limits()` and the three colour
 * args it blanked are gone. The attribution gate is a separate thing and lives in
 * BadgePoweredByGateTest and BadgeFooterPrecedenceTest.
 */
class BadgeSourceScopeTest extends TestCase
{
	/**
	 * @param array  $header
	 * @param string $source
	 * @return array
	 */
	private function scope($header, $source)
	{
		$m = new ReflectionMethod(SBR_Badge_Renderer::class, 'scope_to_source');
		$m->setAccessible(true);

		return (array) $m->invoke(null, $header, $source);
	}




	/**
	 * Scoping keeps only the requested source.
	 *
	 * @return void
	 */
	public function test_scope_keeps_only_the_requested_source()
	{
		$header = array(
			array( 'info' => array( 'id' => '101', 'rating' => 4.0, 'total_rating' => 10 ) ),
			array( 'info' => array( 'id' => '202', 'rating' => 5.0, 'total_rating' => 90 ) ),
		);

		$out = $this->scope($header, '202');

		$this->assertCount(1, $out);
		$this->assertSame('202', $out[0]['info']['id']);
	}

	/**
	 * The load-bearing one: SMASH-1412 stamps `feed_aggregated` on EVERY entry
	 * and Parser::get_average_rating() returns on the first entry carrying it
	 * (Parser.php:147). Filtering without stripping the flag would hand back the
	 * feed-wide average under a single product's name — the "badge showing the
	 * wrong number" the pitch calls worse than no badge.
	 *
	 * @return void
	 */
	public function test_scope_strips_the_feed_wide_aggregate_flags()
	{
		$header = array(
			array(
				'info' => array(
					'id'                      => '101',
					'rating'                  => 4.0,
					'total_rating'            => 10,
					'feed_aggregated'         => true,
					'feed_average_rating'     => 4.9,
					'feed_total_review_count' => 100,
				),
			),
		);

		$out = $this->scope($header, '101');

		$this->assertArrayNotHasKey(
			'feed_aggregated',
			$out[0]['info'],
			'The feed-wide short-circuit flag must not survive scoping.'
		);
		$this->assertArrayNotHasKey('feed_average_rating', $out[0]['info']);
		$this->assertArrayNotHasKey('feed_total_review_count', $out[0]['info']);
		$this->assertSame(4.0, $out[0]['info']['rating'], "The source's own rating stays.");
	}

	/**
	 * Ids compare as strings, strictly.
	 *
	 * The cases below are chosen because they actually discriminate on PHP 8.
	 * An obvious-looking test ('abc' vs '0') does NOT: PHP 8 compares a
	 * non-numeric string against a numeric one AS STRINGS, so `==` and `===`
	 * agree and the assertion passes whichever operator the code uses —
	 * verified by mutation. What still differs is two NUMERIC strings:
	 * '01' == '1' and '1e2' == '100' are both true loosely and false strictly.
	 * Product ids come out of the database as digits, so this is the shape that
	 * would really mis-match one product's rating onto another's badge.
	 *
	 * @return void
	 */
	public function test_scope_matches_ids_strictly()
	{
		$header = array(
			array( 'info' => array( 'id' => '01', 'rating' => 4.2 ) ),
			array( 'info' => array( 'id' => '1e2', 'rating' => 3.1 ) ),
		);

		$this->assertCount(1, $this->scope($header, '01'), 'Exact id still matches.');

		$this->assertSame(
			array(),
			$this->scope($header, '1'),
			"'1' must not match product '01' — a loose compare would serve the wrong rating."
		);
		$this->assertSame(
			array(),
			$this->scope($header, '100'),
			"'100' must not match product '1e2'."
		);
	}

	/**
	 * The case the commit chain caught: a WooCommerce MULTI-product source.
	 *
	 * Its `info.id` is the synthetic account id (`wc_multi_…`), not a product id
	 * — WooCommerce.php:393 returns `'id' => $account_id` with the per-product
	 * numbers nested under `products[]`. Matching on `info.id` alone therefore
	 * missed every product on exactly the feeds per-product badges exist for,
	 * and the badge rendered nothing.
	 *
	 * @return void
	 */
	public function test_scope_finds_a_product_inside_a_woo_multi_product_source()
	{
		$header = array(
			array(
				'info' => array(
					'id'           => 'wc_multi_abc123',
					'rating'       => 4.4,
					'total_rating' => 100,
					'products'     => array(
						array( 'id' => 55, 'name' => 'Kettle', 'average_rating' => 4.9, 'review_count' => 12 ),
						array( 'id' => 66, 'name' => 'Mug', 'average_rating' => 3.1, 'review_count' => 88 ),
					),
				),
			),
		);

		$out = $this->scope($header, '55');

		$this->assertCount(1, $out, 'The product must be found inside the multi-product source.');
		$this->assertSame('55', $out[0]['info']['id']);
		$this->assertSame(4.9, $out[0]['info']['average_rating'], "The PRODUCT's rating, not the source's 4.4.");
		$this->assertSame(12, $out[0]['info']['review_count'], "The PRODUCT's count, not the source's 100.");
	}

	/**
	 * Same for EDD, whose list is `downloads[]` (EDD.php:607-612).
	 *
	 * @return void
	 */
	public function test_scope_finds_a_download_inside_an_edd_multi_source()
	{
		$header = array(
			array(
				'info' => array(
					'id'        => 'edd_multi_xyz',
					'rating'    => 4.0,
					'downloads' => array(
						array( 'id' => 900, 'name' => 'Plugin', 'average_rating' => 5.0, 'review_count' => 7 ),
					),
				),
			),
		);

		$out = $this->scope($header, '900');

		$this->assertCount(1, $out);
		$this->assertSame(5.0, $out[0]['info']['average_rating']);
		$this->assertSame(7, $out[0]['info']['review_count']);
	}

	/**
	 * `direct_products` / `direct_downloads` preserve the original selection and
	 * are the fallback lists Feed.php:621 also consults.
	 *
	 * @dataProvider nestedListProvider
	 *
	 * @param string $list List key under info.
	 *
	 * @return void
	 */
	public function test_scope_searches_every_nested_product_list($list)
	{
		$header = array(
			array(
				'info' => array(
					'id'   => 'wc_multi_abc',
					$list  => array( array( 'id' => 42, 'average_rating' => 4.2, 'review_count' => 9 ) ),
				),
			),
		);

		$out = $this->scope($header, '42');

		$this->assertCount(1, $out, sprintf('Products nested under %s must be reachable.', $list));
		$this->assertSame(4.2, $out[0]['info']['average_rating']);
	}

	/**
	 * A product with no reviews yet must still resolve, so the badge can say
	 * "No reviews yet" for that product rather than disappearing.
	 *
	 * @return void
	 */
	public function test_scope_keeps_a_product_with_zero_reviews()
	{
		$header = array(
			array(
				'info' => array(
					'id'       => 'wc_multi_abc',
					'products' => array( array( 'id' => 7, 'average_rating' => 0, 'review_count' => 0 ) ),
				),
			),
		);

		$out = $this->scope($header, '7');

		$this->assertCount(1, $out);
		$this->assertSame(0.0, $out[0]['info']['average_rating']);
	}





	/**
	 * BC: get_aggregate() gained a second parameter. Existing one-argument
	 * callers must keep meaning "the whole feed".
	 *
	 * @return void
	 */
	public function test_get_aggregate_keeps_its_one_argument_contract()
	{
		$m = new \ReflectionMethod(SBR_Badge_Renderer::class, 'get_aggregate');

		$this->assertSame(1, $m->getNumberOfRequiredParameters(), 'Only $feed_id may be required.');

		$params = $m->getParameters();

		$this->assertSame('source_id', $params[1]->getName());
		$this->assertTrue($params[1]->isOptional(), 'The new parameter must be optional.');
		$this->assertSame('', $params[1]->getDefaultValue(), 'Default must mean "whole feed".');
	}

	/**
	 * A product carried by two overlapping sources must count once.
	 *
	 * Woo/EDD sources can overlap on the same product — the case SMASH-1412
	 * exists for (Feed.php:461-467) — and strip_feed_aggregate() deliberately
	 * removes the short-circuit that used to mask it. Without dedup, two
	 * identical entries make Parser::get_num_ratings() sum the same reviews
	 * twice and a 12-review product renders as 24.
	 *
	 * @return void
	 */
	public function test_scope_counts_an_overlapping_product_once()
	{
		$header = array(
			array( 'info' => array( 'id' => 'wc_multi_a', 'products' => array( array( 'id' => 55, 'average_rating' => 4.9, 'review_count' => 12 ) ) ) ),
			array( 'info' => array( 'id' => 'wc_multi_b', 'products' => array( array( 'id' => 55, 'average_rating' => 4.9, 'review_count' => 12 ) ) ) ),
		);

		$out = $this->scope($header, '55');

		$this->assertCount(1, $out, 'An overlapping product must appear once, not twice.');
		$this->assertSame(12, $out[0]['info']['review_count']);
	}

	/**
	 * The flattened-header path still resolves a product.
	 *
	 * Feed::update_header_cache_from_source() (Feed.php:426) runs whenever the
	 * feed cache is unexpired but has no `header` row, and it flattens each
	 * source to id/name/rating/total_rating/url — the nested product lists are
	 * gone. A multi-product source then shows only its `wc_multi_…` account id,
	 * so the badge would render nothing for a valid product id until that cache
	 * expired. The feed's saved sources still hold the lists.
	 *
	 * @return void
	 */
	public function test_scope_falls_back_to_the_feeds_saved_sources()
	{
		$flattened = array(
			array( 'info' => array( 'id' => 'wc_multi_a', 'name' => 'Products', 'rating' => 4.4, 'total_rating' => 100 ) ),
		);

		$this->assertSame(array(), $this->scope($flattened, '55'), 'Guard: the flattened header alone cannot resolve it.');

		// A bare account_id string is what the UNhydrated settings hold, and the
		// fallback must simply skip it rather than fatal.
		$mm = new ReflectionMethod(SBR_Badge_Renderer::class, 'scope_from_sources');
		$mm->setAccessible(true);
		$this->assertSame(
			array(),
			(array) $mm->invoke(null, array( 'ChIJFTrs68AOSUcRgsTW-sRzwZY' ), '55'),
			'Unhydrated string sources must be skipped, not fatal.'
		);

		$sources = array(
			array( 'info' => array( 'id' => 'wc_multi_a', 'products' => array( array( 'id' => 55, 'average_rating' => 4.9, 'review_count' => 12 ) ) ) ),
		);

		// Shaped like a REAL hydrated source row, verified on feed 194: after
		// Feed::hydrate_sources() each entry is the DB row
		// (id, account_id, provider, …, info) with `info` already an array.
		// get_settings_by_feed_id() alone returns bare account_id STRINGS, which
		// is why the fallback hydrates first — a fixture that skipped that step
		// would pass against a shape no caller produces.
		$sources[0]['account_id'] = 'wc_multi_a';
		$sources[0]['provider']   = 'woocommerce';

		$m = new ReflectionMethod(SBR_Badge_Renderer::class, 'scope_from_sources');
		$m->setAccessible(true);
		$out = (array) $m->invoke(null, $sources, '55');

		$this->assertCount(1, $out, 'The hydrated sources must rescue the lookup.');
		$this->assertSame(4.9, $out[0]['info']['average_rating']);
	}

	/**
	 * Source info is stored as JSON on the source row, so the fallback has to
	 * decode it the way Feed.php:435 does.
	 *
	 * @return void
	 */
	public function test_source_fallback_decodes_json_info()
	{
		$sources = array(
			array( 'info' => json_encode(array( 'id' => 'wc_multi_a', 'products' => array( array( 'id' => 7, 'average_rating' => 3.5, 'review_count' => 4 ) ) )) ),
		);

		$m = new ReflectionMethod(SBR_Badge_Renderer::class, 'scope_from_sources');
		$m->setAccessible(true);
		$out = (array) $m->invoke(null, $sources, '7');

		$this->assertCount(1, $out);
		$this->assertSame(3.5, $out[0]['info']['average_rating']);
	}

	/**
	 * Two products on one feed must not share a cache entry. A truncated digest
	 * would let ids colliding on their first hex characters pin one product's
	 * rating onto the other for the whole TTL.
	 *
	 * @return void
	 */
	public function test_cache_key_isolates_two_sources()
	{
		$m = new ReflectionMethod(SBR_Badge_Renderer::class, 'cache_key');
		$m->setAccessible(true);

		// A REAL collision, not two arbitrary ids: md5('25302') and md5('82945')
		// both begin '7b763dcb', so any digest truncated to 8 hex characters maps
		// these two products onto one transient — and both are entirely plausible
		// WooCommerce product ids. Whichever badge rendered first would then pin
		// its rating onto the other product for the whole TTL.
		$this->assertSame(
			substr(md5('25302'), 0, 8),
			substr(md5('82945'), 0, 8),
			'Guard: this pair must actually collide on a short prefix, or the test proves nothing.'
		);

		$feed = $m->invoke(null, 9, array(), '');
		$a    = $m->invoke(null, 9, array(), '25302');
		$b    = $m->invoke(null, 9, array(), '82945');

		$this->assertNotSame($a, $b, 'Two colliding-prefix products must still get separate keys.');
		$this->assertNotSame($feed, $a, 'A scoped badge must not reuse the feed-wide entry.');
		$this->assertLessThan(172, strlen($a), 'Must fit WP transient name limit.');
	}

	/**
	 * The fallback must NOT re-hydrate the feed.
	 *
	 * get_aggregate() calls Feed::init(), which already runs hydrate_sources()
	 * (Feed.php:85-87). That method is not idempotent: it ends with an
	 * unconditional `$this->settings['sources'] = $hydrated_sources`
	 * (Feed.php:1196) while its inner match requires `! is_array($single_source)`
	 * (:1174), so fed already-hydrated rows it matches nothing and assigns an
	 * empty array. Calling it a second time therefore wipes the very product
	 * lists the fallback then reads — measured on feed 194: 1 source before,
	 * 0 after — and every per-product badge on a flattened-header feed would
	 * negative-cache to blank for the whole TTL.
	 *
	 * Asserted against the source, because the destructive call sat on the
	 * integration path and the unit fixtures sailed straight past it.
	 *
	 * @return void
	 */
	public function test_the_fallback_does_not_rehydrate_the_feed()
	{
		$file = file_get_contents(
			(new \ReflectionClass(SBR_Badge_Renderer::class))->getFileName()
		);

		$this->assertNotFalse($file);
		$this->assertStringNotContainsString(
			'$feed->hydrate_sources()',
			$file,
			'init() already hydrated; a second hydrate_sources() empties settings["sources"].'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function nestedListProvider()
	{
		return array(
			'products'          => array( 'products' ),
			'direct_products'   => array( 'direct_products' ),
			'downloads'         => array( 'downloads' ),
			'direct_downloads'  => array( 'direct_downloads' ),
		);
	}

	/**
	 * An unknown source yields nothing, so render() can bail rather than fall
	 * back to the feed-wide average under a product name.
	 *
	 * @return void
	 */
	public function test_scope_returns_empty_for_an_unknown_source()
	{
		$header = array( array( 'info' => array( 'id' => '101' ) ) );

		$this->assertSame(array(), $this->scope($header, '999'));
	}

	/**
	 * Malformed entries are skipped, not fatal. Header data is cached JSON and
	 * an old cache can hold a shape this build never wrote.
	 *
	 * @return void
	 */
	public function test_scope_tolerates_malformed_entries()
	{
		$header = array(
			'not-an-array',
			array( 'no_info' => true ),
			array( 'info' => array( 'no_id' => true ) ),
			array( 'info' => array( 'id' => '101', 'rating' => 4.0 ) ),
		);

		$out = $this->scope($header, '101');

		$this->assertCount(1, $out);
		$this->assertSame('101', $out[0]['info']['id']);
	}
}
