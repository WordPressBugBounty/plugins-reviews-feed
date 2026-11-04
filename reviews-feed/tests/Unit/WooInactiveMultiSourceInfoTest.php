<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Providers\WooCommerce;

/**
 * A multi-product WooCommerce source with WooCommerce inactive: get_multi_source_info() called wc_get_product()
 * unguarded, so every header or posts refresh of that feed fataled (found by the SBR-556 senior QA pass, already
 * in 2.15.1). The stored counts must stand instead.
 */
class WooInactiveMultiSourceInfoTest extends TestCase
{
	public function test_it_keeps_the_stored_counts_instead_of_fataling(): void
	{
		$this->assertFalse(function_exists('wc_get_product'), 'the suite runs without WooCommerce');

		$stored = [['id' => 11, 'name' => 'Mug', 'review_count' => 2, 'average_rating' => 5.0], ['id' => 12, 'name' => 'Cup', 'review_count' => 1, 'average_rating' => 3.5]];
		$info = (new WooCommerce())->get_multi_source_info([11, 12], 'wc_multi_1', ['name' => 'QA Mugs', 'rating' => 4.5, 'review_count' => 3, 'products' => $stored]);

		$this->assertSame('wc_multi_1', $info['id']);
		$this->assertSame('QA Mugs', $info['name']);
		$this->assertSame(3, $info['review_count']);
		$this->assertSame(4.5, $info['rating']);
		$this->assertSame(3, $info['rated_count'], 'no stored rated_count: falls back to the review count, never 0');

		$rated = (new WooCommerce())->get_multi_source_info([11], 'wc_multi_1', ['rating' => 4.5, 'review_count' => 3, 'rated_count' => 2]);
		$this->assertSame(2, $rated['rated_count'], 'the stored rated_count stands (SMASH-2477 weighting)');
	}

	/**
	 * The header refresh saves this over the source row, and the product list is how the source finds its products
	 * once WooCommerce is back (Feed.php reads info['products']), so it must survive the inactive refresh.
	 */
	public function test_the_stored_products_survive_so_reactivating_woocommerce_finds_them(): void
	{
		$stored = [['id' => 11, 'name' => 'Mug', 'review_count' => 2, 'average_rating' => 5.0], ['id' => 12, 'name' => 'Cup', 'review_count' => 1, 'average_rating' => 3.5]];

		$info = (new WooCommerce())->get_multi_source_info([11, 12], 'wc_multi_1', ['name' => 'QA Mugs', 'products' => $stored, 'direct_products' => [11, 12]]);

		$this->assertSame($stored, $info['products']);
		$this->assertSame(2, $info['product_count']);
		$this->assertSame([11, 12], $info['direct_products']);
	}

	/** No aggregate at all, so the public header keeps the stored per-source sums instead of "0 reviews". */
	public function test_the_feed_aggregate_steps_aside_instead_of_reporting_zero(): void
	{
		$sources = [['provider' => 'woocommerce', 'info' => ['products' => [['id' => 11], ['id' => 12]]]]];

		$this->assertNull(\SmashBalloon\Reviews\Common\Feed::compute_provider_aggregate('woocommerce', $sources));
	}
}
