<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\Integrations\Analytics\SB_Analytics;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Pro\Integrations\Providers\WooCommerce;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update;

/**
 * SMASH-2477: multi-source averages weighted each source by ALL its reviews, while each source's own
 * average covers only rated ones (a collection after SMASH-2468; WooCommerce's own average). A source
 * with many unrated reviews got too much weight: one 5-star + nine unrated (5.0 over 10) with Google
 * 4.0 over 10 read 4.5, while the rated reviews average 45/11 = 4.1. Every weighting now uses the
 * source's rated count when it reports one; "Over N reviews" still counts every review.
 */
final class Smash2477WeightByRatedReviewsTest extends TestCase
{
	/** @var mixed */
	private $previous_products;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_products = $GLOBALS['sbr_test_wc_products'] ?? null;
	}

	protected function tearDown(): void
	{
		if ($this->previous_products === null) {
			unset($GLOBALS['sbr_test_wc_products']);
		} else {
			$GLOBALS['sbr_test_wc_products'] = $this->previous_products;
		}
		parent::tearDown();
	}

	private function header(): array
	{
		return [
			['info' => ['rating' => 5.0, 'total_rating' => 10, 'rated_count' => 1]], // collection: 1 rated, 9 unrated
			['info' => ['rating' => 4.0, 'total_rating' => 10]],                    // Google
		];
	}

	public function test_the_header_average_weights_by_rated_reviews(): void
	{
		$this->assertSame(4.1, (new Parser())->get_average_rating($this->header()));
	}

	public function test_the_header_count_still_counts_every_review(): void
	{
		$this->assertSame(20, (new Parser())->get_num_ratings($this->header()));
	}

	public function test_a_source_without_a_rated_count_is_weighted_as_before(): void
	{
		$header = [['info' => ['rating' => 5.0, 'total_rating' => 10]], ['info' => ['rating' => 4.0, 'total_rating' => 10]]];
		$this->assertSame(4.5, (new Parser())->get_average_rating($header));
	}

	/** The four WC_Product getters get_multi_source_info() reads. */
	private function product(float $avg, int $reviews, int $rated): object
	{
		return new class ($avg, $reviews, $rated) {
			/** @var array{0:float,1:int,2:int} */
			private $d;

			public function __construct(float $avg, int $reviews, int $rated)
			{
				$this->d = [$avg, $reviews, $rated];
			}
			public function get_average_rating()
			{
				return $this->d[0];
			}
			public function get_review_count()
			{
				return $this->d[1];
			}
			public function get_rating_count()
			{
				return $this->d[2];
			}
			public function get_name()
			{
				return 'Product';
			}
		};
	}

	private function products(): void
	{
		// Process-wide names: required here because these tests run in their own process.
		require_once dirname(__DIR__) . '/stubs/wc_get_product.php';
		require_once dirname(__DIR__) . '/stubs/WooCommerce.php';
		$GLOBALS['sbr_test_wc_products'] = [
			11 => $this->product(5.0, 10, 1),
			12 => $this->product(1.0, 1, 1),
		];
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woocommerce_multi_product_average_weights_by_rating_count(): void
	{
		$this->products();
		$info = (new WooCommerce())->get_multi_source_info([11, 12], 'woo-multi', []);

		$this->assertSame(3.0, (float) $info['rating'], '(5x1 + 1x1) / 2; weighting by review count read 4.6');
		$this->assertSame(11, $info['review_count'], 'the count still includes unrated reviews');
		$this->assertSame(2, $info['rated_count']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_feed_level_aggregate_weights_by_rated_reviews_and_skips_unrated_sources(): void
	{
		$this->products();
		$aggregate = new \ReflectionMethod(Feed::class, 'compute_feed_level_aggregate');
		$aggregate->setAccessible(true);
		$feed = (new \ReflectionClass(Feed::class))->newInstanceWithoutConstructor();

		$sources = [
			['provider' => 'woocommerce', 'info' => ['products' => [['id' => 11], ['id' => 12]]]],
			['provider' => 'collection', 'info' => ['rating' => 0, 'total_rating' => 3, 'rated_count' => 0]],
			['provider' => 'collection', 'info' => ['rating' => 5.0, 'total_rating' => 10, 'rated_count' => 1]],
			['provider' => 'google', 'info' => ['rating' => 4.0, 'total_rating' => 4]],
		];
		$got = $aggregate->invoke($feed, $sources);

		// Woo 3.0 over 2 rated + collection 5.0 over 1 rated + Google 4.0 over 4: (6 + 5 + 16) / 7 = 3.9.
		// Weighting by every review gives 72/16 = 4.5; also counting the unrated collection, 72/19 = 3.8.
		$this->assertSame(3.9, $got['rating']);
		$this->assertSame(28, $got['count'], '11 + 3 + 10 + 4 reviews, rated or not');
		$this->assertSame(7, $got['rated'], 'stamped as feed_rated_count for the badge weight');
	}

	public function test_the_analytics_headline_weights_by_rated_reviews(): void
	{
		$analytics = (new \ReflectionClass(SB_Analytics::class))->newInstanceWithoutConstructor();
		$rows      = [
			['account_id' => 'c1', 'provider' => 'collection', 'name' => 'C', 'info' => json_encode(['rating' => 5.0, 'total_rating' => 10, 'rated_count' => 1])],
			['account_id' => 'g1', 'provider' => 'google', 'name' => 'G', 'info' => json_encode(['rating' => 4.0, 'total_rating' => 10])],
		];
		foreach (['source_rows' => $rows, 'provider_post_stats' => []] as $prop => $value) {
			$p = new \ReflectionProperty(SB_Analytics::class, $prop);
			$p->setAccessible(true);
			$p->setValue($analytics, $value);
		}
		$official = new \ReflectionMethod(SB_Analytics::class, 'get_sources_official_aggregate');
		$official->setAccessible(true);
		[$total, $sum, $weight] = $official->invoke($analytics);

		$this->assertSame(20, $total, 'the total still counts every review');
		$this->assertSame(4.1, round($sum / $weight, 1));
	}

	/**
	 * The bulk refresh ("Clear All Caches") rewrites a multi-product source's info; it weighted by
	 * review count and never wrote rated_count.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_bulk_refresh_weights_by_rating_count_and_stores_it(): void
	{
		$this->products();
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var list<array> */
			public $updates = [];
			public function prepare($query, ...$args)
			{
				return $query;
			}
			public function get_results($query, $output = null)
			{
				return [['id' => 1, 'account_id' => 'woo-multi', 'provider' => 'woocommerce', 'info' => json_encode(['products' => [['id' => 11], ['id' => 12]]])]];
			}
			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = $data;
				return 1;
			}
		};

		Bulk_WooCommerce_Reviews_Update::refresh_all_source_info();

		$info = json_decode($GLOBALS['wpdb']->updates[0]['info'] ?? '{}', true);
		$this->assertEquals(3.0, $info['average_rating'] ?? null, '(5x1 + 1x1) / 2; by review count it was 4.64');
		$this->assertSame(11, $info['review_count'] ?? null);
		$this->assertSame(2, $info['rated_count'] ?? null);
	}

	/**
	 * The builder's create/update handlers for multi-product sources (SBR_New_Providers_Manager) need
	 * WordPress and WooCommerce end to end, so their weighting is asserted on the source instead — the
	 * weaker kind of test, used because the alternative is none. Both handlers must weight by rating
	 * count and store rated_count.
	 */
	public function test_the_source_builder_weights_by_rating_count_in_both_handlers(): void
	{
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local class file
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/class/Common/Builder/SBR_New_Providers_Manager.php');
		$this->assertSame(2, substr_count($src, '$weighted_rating_sum += $average_rating * $rating_count;'), 'both Woo multi handlers');
		$this->assertSame(2, substr_count($src, "'rated_count' => \$total_rating_count,"));
		// The two EDD multi handlers still weight by $review_count (EDD stores no unrated review); nothing else may.
		$this->assertSame(2, substr_count($src, '$weighted_rating_sum += $average_rating * $review_count;'), 'only the two EDD handlers weight by review count');
	}

	/**
	 * A single-product source reports the count its average covers.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_single_product_source_reports_its_rating_count(): void
	{
		require_once dirname(__DIR__) . '/stubs/wp_permalink_and_attachment.php';
		$product = new class {
			public function get_id()
			{
				return 11;
			}
			public function get_name()
			{
				return 'Mug';
			}
			public function get_image_id()
			{
				return 0;
			}
			public function get_average_rating()
			{
				return '5.00';
			}
			public function get_review_count()
			{
				return 10;
			}
			public function get_rating_count()
			{
				return 1;
			}
		};

		$info = (new WooCommerce())->get_source_info($product);
		$this->assertSame(10, $info['review_count']);
		$this->assertSame(1, $info['rated_count']);
	}

	/**
	 * The header cache can also be rebuilt from the saved sources (no header row yet, or another request
	 * holds the refresh lock); that path must carry rated_count too, or the header flips between 4.5 and 4.1.
	 */
	public function test_the_header_rebuilt_from_saved_sources_keeps_rated_count(): void
	{
		$feed  = (new \ReflectionClass(Feed::class))->newInstanceWithoutConstructor();
		$cache = new class {
			/** @var array<string,string> */
			public $rows = [];
			public function update_or_insert($key, $value)
			{
				$this->rows[ $key ] = $value;
			}
		};
		foreach (
			['settings' => ['sources' => [
			['account_id' => 'c1', 'provider' => 'collection', 'info' => json_encode(['id' => 'c1', 'rating' => 5.0, 'total_rating' => 10, 'rated_count' => 1])],
			['account_id' => 'g1', 'provider' => 'google', 'info' => json_encode(['id' => 'g1', 'rating' => 4.0, 'total_rating' => 10])],
			]], 'feed_cache' => $cache] as $prop => $value
		) {
			$p = new \ReflectionProperty(Feed::class, $prop);
			$p->setAccessible(true);
			$p->setValue($feed, $value);
		}

		$header = $feed->update_header_cache_from_source();

		$this->assertSame(1, $header[0]['info']['rated_count'] ?? null);
		$this->assertArrayNotHasKey('rated_count', $header[1]['info'], 'a source without one is unchanged');
		$this->assertSame(4.1, (new Parser())->get_average_rating($header));
	}

	/**
	 * Every review unrated: the Reputation card still counts them, with no average.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_analytics_total_counts_reviews_even_when_none_is_rated(): void
	{
		require_once dirname(__DIR__) . '/stubs/wc_get_product.php';
		require_once dirname(__DIR__) . '/stubs/WooCommerce.php';
		$GLOBALS['sbr_test_wc_products'] = [11 => $this->product(0.0, 10, 0)];

		$analytics = (new \ReflectionClass(SB_Analytics::class))->newInstanceWithoutConstructor();
		$rows      = [['account_id' => 'woo-multi', 'provider' => 'woocommerce', 'name' => 'W', 'info' => json_encode(['products' => [['id' => 11]], 'review_count' => 10])]];
		foreach (['source_rows' => $rows, 'provider_post_stats' => []] as $prop => $value) {
			$p = new \ReflectionProperty(SB_Analytics::class, $prop);
			$p->setAccessible(true);
			$p->setValue($analytics, $value);
		}
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public function get_results($query, $output = null)
			{
				return [];
			}
			public function prepare($query, ...$args)
			{
				return $query;
			}
			public function get_row($query, $output = null)
			{
				return null;
			}
			public function get_var($query)
			{
				return null;
			}
		};

		$summary = $analytics->filter_reviews_summary([]);
		$this->assertSame(10, $summary['total'], 'ten reviews, none rated');
		$this->assertSame(0.0, (float) $summary['rating']);
	}

	/** @return mixed */
	private function badge(string $method, ...$args)
	{
		$m = new \ReflectionMethod(SBR_Badge_Renderer::class, $method);
		$m->setAccessible(true);
		return $m->invoke(null, ...$args);
	}

	public function test_a_badge_over_products_weights_each_by_its_rated_reviews(): void
	{
		$source = ['info' => ['id' => 'woo-multi', 'products' => [
			['id' => 11, 'name' => 'A', 'average_rating' => 5.0, 'review_count' => 10, 'rated_count' => 1],
			['id' => 12, 'name' => 'B', 'average_rating' => 4.0, 'review_count' => 10, 'rated_count' => 10],
		]]];
		$rows = $this->badge('expand_products', [$source]);
		$this->assertSame(1, $rows[0]['info']['rated_count'] ?? null, 'the product row keeps its rated count');

		$c = $this->badge('rows_to_contribution', $rows);
		$this->assertSame(4.1, $c['rating'], '(5x1 + 4x10) / 11; by review count it read 4.5');
		$this->assertSame(20, $c['count']);
		$this->assertSame(11, $c['rated']);

		$one = $this->badge('find_nested_product', $source['info'], '11');
		$this->assertSame(1, $one['rated_count'] ?? null, 'a single-product badge row keeps it too');
	}

	public function test_combining_feeds_weights_by_rated_reviews_when_reported(): void
	{
		$base = ['resolved' => true, 'scale' => 5, 'word' => '', 'providers' => []];
		$with = $this->badge('combine', [
			$base + ['rating' => 5.0, 'count' => 10, 'rated' => 1],
			$base + ['rating' => 4.0, 'count' => 10, 'rated' => 10],
		]);
		$this->assertSame(4.1, $with['rating']);
		$this->assertSame(20, $with['count'], 'the badge still counts every review');

		$without = $this->badge('combine', [
			$base + ['rating' => 5.0, 'count' => 10],
			$base + ['rating' => 4.0, 'count' => 10],
		]);
		$this->assertSame(4.5, $without['rating'], 'a cached contribution without rated is weighted as before');
	}

	public function test_the_rating_weight_follows_the_feed_aggregate_or_the_sources(): void
	{
		$parser = new Parser();
		$this->assertSame(11, $parser->get_rating_weight($this->header()), '1 + 10');
		$this->assertSame(7, $parser->get_rating_weight([['info' => ['feed_aggregated' => true, 'feed_rated_count' => 7, 'feed_total_review_count' => 30]]]));
		$this->assertSame(0, $parser->get_rating_weight('not an array'));
	}

	/** BC: a rated feed with no counts keeps the plain-mean weight, alone and beside others. */
	public function test_a_rated_feed_with_no_counts_still_weighs_one(): void
	{
		$rows = [['info' => ['id' => 'm1', 'rating' => 4.8]]];
		$this->assertSame(1, (new Parser())->get_rating_weight($rows));
		$this->assertSame(2, (new Parser())->get_rating_weight([['info' => ['rating' => 4.0]], ['info' => ['rating' => 5.0]], ['info' => ['rating' => 0]]]), 'one per rated source');

		$alone = $this->badge('rows_to_contribution', $rows);
		$this->assertSame(4.8, $this->badge('combine', [$alone])['rating'], 'a badge over that feed alone');

		$base   = ['resolved' => true, 'scale' => 5, 'word' => '', 'providers' => []];
		$mixed  = $this->badge('combine', [$alone, $base + ['rating' => 4.0, 'count' => 1, 'rated' => 1]]);
		$this->assertSame(4.4, $mixed['rating'], 'next to a one-review feed it counts as one');
		$this->assertSame(4.8, $this->badge('combine', [$base + ['rating' => 4.8, 'count' => 0, 'rated' => 0]])['rating'], 'a rated contribution with a zero weight still counts once');
	}

	/** BC: a header cached before 2.16.0 has feed_aggregated but no feed_rated_count. */
	public function test_an_aggregated_header_cached_before_the_change_weighs_by_its_total(): void
	{
		$old = [['info' => ['feed_aggregated' => true, 'feed_average_rating' => 4.2, 'feed_total_review_count' => 30]]];
		$this->assertSame(30, (new Parser())->get_rating_weight($old));
	}

	/** One badge over a one-source feed and a multi-source feed weighs both the same way. */
	public function test_a_single_feed_aggregate_and_a_multi_source_row_combine_by_rated_reviews(): void
	{
		$base   = ['resolved' => true, 'scale' => 5, 'word' => '', 'providers' => []];
		$single = $base + ['rating' => 5.0, 'count' => 10, 'rated' => (new Parser())->get_rating_weight([['info' => ['rating' => 5.0, 'total_rating' => 10, 'rated_count' => 1]]])];
		$multi  = $this->badge('rows_to_contribution', [['info' => ['rating' => 4.0, 'total_rating' => 10]]]);

		$both = $this->badge('combine', [$single, $multi]);
		$this->assertSame(4.1, $both['rating'], '(5x1 + 4x10) / 11');
		$this->assertSame(11, $both['rated'], 'a cached combined aggregate carries its weight');
	}

	/** BC: aggregates and cache entries written before `rated` existed weigh by their count. */
	public function test_older_aggregates_default_their_weight_to_the_count(): void
	{
		$norm = $this->badge('normalize_aggregate', ['rating' => 4.0, 'count' => 12, 'resolved' => true]);
		$this->assertSame(12, $norm['rated']);
		$this->assertSame(3, $this->badge('normalize_aggregate', ['rating' => 4.0, 'count' => 12, 'rated' => 3])['rated']);
	}

	/**
	 * A duplicated collection is recalculated from its rows, so it gets rated_count like the original.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_duplicated_collection_is_recalculated_with_its_rated_count(): void
	{
		$this->test_a_duplicated_collection_is_recalculated_with_its_rated_count_body([0, 4, 5], 2, 3, 4.5);
	}

	private function test_a_duplicated_collection_is_recalculated_with_its_rated_count_body(array $copied, int $rated, int $total, float $rating): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST = ['collection_id' => 'collection-1'];
		$review = function (string $id, $rating): array {
			return ['review_id' => $id, 'text' => 'Nice', 'title' => '', 'rating' => $rating, 'time' => 1756000000,
				'reviewer' => ['name' => 'Ana', 'avatar' => ''], 'provider' => ['name' => 'google'], 'source' => ['id' => 'collection-1', 'url' => '']];
		};
		$rows = [];
		$ratings = $GLOBALS['sbr_test_dup_ratings'] ?? [['r1', 0], ['r2', 4], ['r3', 5]];
		foreach ($ratings as $i => [$id, $stars]) {
			$rows[] = ['id' => $i + 1, 'post_id' => $id, 'provider' => 'collection', 'provider_id' => 'collection-1', 'rating' => $stars,
				'post_content' => 'Nice', 'json_data' => json_encode($review($id, $stars)), 'images_done' => 1, 'lang' => 'en'];
		}
		$GLOBALS['wpdb'] = new class ($rows) {
			public $prefix = 'wp_';
			public $insert_id = 0;
			/** @var list<array> */
			public $source_updates = [];
			/** @var list<array> */
			private $rows;
			public function __construct(array $rows)
			{
				$this->rows = $rows;
			}
			public function prepare($query, ...$args)
			{
				return $query . ' -- ' . json_encode($args);
			}
			public function get_results($query, $output = null)
			{
				if (strpos($query, 'sbr_sources') !== false) {
					return [['id' => 9, 'account_id' => 'collection-x', 'provider' => 'collection', 'name' => 'C', 'info' => '{"rating":4.5,"total_rating":3}', 'used_in' => 0]];
				}
				return strpos($query, 'reviews_posts') !== false ? $this->rows : [];
			}
			public function get_row($query, $output = null)
			{
				return null;
			}
			public function get_var($query)
			{
				return 0;
			}
			public function get_col($query)
			{
				return [];
			}
			/** @var list<int> the rating each copied review was INSERTed with */
			public $copied_ratings = [];
			public function query($sql)
			{
				// prepare() appends the args as JSON; SinglePostCache::store()'s 6th value is the rating.
				if (
					strpos($sql, 'INSERT INTO') !== false && strpos($sql, 'reviews_posts') !== false
					&& preg_match('/ -- (\\[.*\\])$/s', $sql, $m) === 1
				) {
					$args = json_decode($m[1], true);
					$this->copied_ratings[] = (int) ($args[5] ?? -1);
				}
				return 1;
			}
			public function insert($table, $data, $format = null)
			{
				return 1;
			}
			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				if (strpos($table, 'sbr_sources') !== false && isset($data['info'])) {
					$this->source_updates[] = json_decode($data['info'], true);
				}
				return 1;
			}
			public function esc_like($text)
			{
				return $text;
			}
		};

		ob_start();
		try {
			SBR_Feed_Saver_Manager::duplicate_collection();
		} catch (\SBR_Test_Halt $e) {
			// wp_die() at the end of the handler.
		} finally {
			ob_end_clean();
		}

		$saved = end($GLOBALS['wpdb']->source_updates) ?: [];
		$this->assertSame($copied, $GLOBALS['wpdb']->copied_ratings, 'the copies keep their stars');
		$this->assertSame($rated, $saved['rated_count'] ?? null);
		$this->assertSame($total, $saved['total_rating'] ?? null);
		$this->assertEquals($rating, $saved['rating'] ?? null);
	}

	/**
	 * Recommendations survive the copy and its recalculation (same rating mapping as SMASH-2480).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_duplicated_collection_of_recommendations_keeps_its_rating(): void
	{
		$GLOBALS['sbr_test_dup_ratings'] = [['r1', 'positive'], ['r2', 'positive'], ['r3', 'negative']];
		$this->test_a_duplicated_collection_is_recalculated_with_its_rated_count_body([5, 5, 1], 3, 3, 3.67);
	}
}
