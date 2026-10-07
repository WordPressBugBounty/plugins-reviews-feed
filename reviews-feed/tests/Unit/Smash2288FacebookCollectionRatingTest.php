<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SinglePostCache as CommonSinglePostCache;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RepairFacebookCollectionRatingsRoutine;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Pro\MediaFinder;
use SmashBalloon\Reviews\Pro\SinglePostCache as ProSinglePostCache;

/**
 * SMASH-2288: a manual Facebook review in a collection, set to 5 stars, was stored with rating 1
 * because store()/update_single() mapped every value that wasn't 'positive' to 1. The feed average
 * reads that column, so 107 Google reviews at 5.0 plus 5 such reviews showed 4.8.
 */
final class Smash2288FacebookCollectionRatingTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public $insert_id = 0;
			/** @var list<array> */
			public $prepared = [];
			/** @var list<array> */
			public $updates = [];
			/** @var list<array> rows get_results() returns */
			public $rows = [];

			public function prepare($query, ...$args)
			{
				$this->prepared[] = ['query' => $query, 'args' => $args];
				return $query;
			}
			public function query($sql)
			{
				return 1;
			}
			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = compact('table', 'data', 'where');
				return 1;
			}
			public function get_results($query, $output = null)
			{
				// Only the routine's posts query gets the seeded rows; the collection lookup that follows gets none.
				return strpos($query, 'reviews_posts') !== false ? $this->rows : [];
			}
			public function get_row($query, $output = null)
			{
				return null;
			}
			public function esc_like($text)
			{
				return addcslashes($text, '_%\\');
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	private function facebook_collection_review($rating): array
	{
		return [
			'review_id'   => 'collection-1' . 'R' . (string) $rating,
			'provider_id' => 'collection-17913104214002587020',
			// Not valid base64: Data_Encryption::maybe_encrypt() first tries to decrypt the plain text,
			// and a string that decodes (e.g. "Awesome service", 10 bytes) trips an openssl short-IV warning.
			'text'        => 'Awesome service!',
			'title'       => 'Awesome!',
			'rating'      => $rating,
			'time'        => 1759700000,
			'reviewer'    => ['name' => 'Joe'],
			'provider'    => ['name' => 'facebook'],
			'source'      => ['id' => 'collection-17913104214002587020', 'url' => ''],
		];
	}

	/** The rating value store() hands to the INSERT: the 6th placeholder, after post_content. */
	private function stored_rating(): int
	{
		$insert = null;
		foreach ($GLOBALS['wpdb']->prepared as $p) {
			if (strpos($p['query'], 'INSERT INTO') !== false) {
				$insert = $p;
			}
		}
		$this->assertNotNull($insert, 'store() must issue the INSERT');
		return (int) $insert['args'][5];
	}

	/** @return array<string, array{0:mixed,1:mixed}> */
	public static function ratings(): array
	{
		return [
			'manual 5 stars'      => [5, 5],
			'manual 3 stars'      => [3, 3],
			'manual "4" string'   => ['4', '4'],
			'recommendation yes'  => ['positive', 5],
			'recommendation no'   => ['negative', 1],
		];
	}

	/** @dataProvider ratings */
	public function test_helper_maps_only_the_recommendation_sentinels($in, $want): void
	{
		$this->assertSame($want, Util::facebook_collection_rating(['rating' => $in]));
	}

	public function test_pro_store_keeps_a_manual_five_star_facebook_review_at_five(): void
	{
		$review = $this->facebook_collection_review(5);
		$cache  = new ProSinglePostCache($review, new MediaFinder($review['source']));
		$cache->set_provider_id($review['provider_id']);
		$cache->store();
		$this->assertSame(5, $this->stored_rating(), 'was 1 before SMASH-2288');
	}

	public function test_pro_update_single_keeps_a_manual_five_star_facebook_review_at_five(): void
	{
		$review = $this->facebook_collection_review(5);
		$cache  = new ProSinglePostCache($review, new MediaFinder($review['source']));
		$cache->set_provider_id($review['provider_id']);
		$cache->update_single();
		$this->assertSame(5, $GLOBALS['wpdb']->updates[0]['data']['rating'] ?? null, 'was 1 before SMASH-2288');
	}

	public function test_common_store_keeps_a_manual_five_star_facebook_review_at_five(): void
	{
		$review = $this->facebook_collection_review(5);
		$cache  = new CommonSinglePostCache($review);
		$cache->set_provider_id($review['provider_id']);
		$cache->store();
		$this->assertSame(5, $this->stored_rating());
	}

	public function test_common_update_single_keeps_a_manual_five_star_facebook_review_at_five(): void
	{
		$review = $this->facebook_collection_review(5);
		$cache  = new CommonSinglePostCache($review);
		$cache->set_provider_id($review['provider_id']);
		$cache->update_single();
		$this->assertSame(5, $GLOBALS['wpdb']->updates[0]['data']['rating'] ?? null);
	}

	/** Backwards compatibility: an imported Facebook recommendation still stores as 5 / 1. */
	public function test_recommendation_sentinels_still_store_as_five_and_one(): void
	{
		foreach (['positive' => 5, 'negative' => 1] as $sentinel => $want) {
			$GLOBALS['wpdb']->prepared = [];
			$review = $this->facebook_collection_review($sentinel);
			$cache  = new ProSinglePostCache($review, new MediaFinder($review['source']));
			$cache->set_provider_id($review['provider_id']);
			$cache->store();
			$this->assertSame($want, $this->stored_rating(), $sentinel);
		}
	}

	public function test_routine_copies_the_stars_back_from_json_data(): void
	{
		$GLOBALS['wpdb']->rows = [
			// the customer's row: column 1, stars 5
			['id' => '11', 'provider_id' => 'collection-A', 'rating' => '1', 'json_data' => wp_json_encode(['rating' => 5])],
			// an imported recommendation: already right, must not be touched
			['id' => '12', 'provider_id' => 'collection-A', 'rating' => '5', 'json_data' => wp_json_encode(['rating' => 'positive'])],
			// already consistent
			['id' => '13', 'provider_id' => 'collection-B', 'rating' => '4', 'json_data' => wp_json_encode(['rating' => 4])],
			// not a review row
			['id' => '14', 'provider_id' => 'collection-B', 'rating' => '1', 'json_data' => '"not an object"'],
		];
		(new RepairFacebookCollectionRatingsRoutine())->run();

		$review_updates = array_values(array_filter($GLOBALS['wpdb']->updates, function ($u) {
			return strpos($u['table'], 'reviews_posts') !== false;
		}));
		$this->assertCount(1, $review_updates, 'only the 1-vs-5 row is rewritten');
		$this->assertSame(['rating' => 5], $review_updates[0]['data']);
		$this->assertSame(['id' => 11], $review_updates[0]['where']);
	}
}
