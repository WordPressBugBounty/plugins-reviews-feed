<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\Analytics\SB_Analytics;
use SmashBalloon\Reviews\Common\PostAggregator;

/**
 * SMASH-2476: get_source_posts_list() (the collection editor's list) mapped every non-numeric
 * Facebook rating to 5 unless it was exactly 'negative', so an empty rating showed — and, once the
 * review was saved from the editor, was stored — as 5 stars. Only the recommendation sentinels map.
 */
final class Smash2476FacebookEmptyRatingTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var list<array> rows get_results() returns */
			public $rows = [];

			public function prepare($query, ...$args)
			{
				return $query;
			}
			public function get_results($query, $output = null)
			{
				return $this->rows;
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	/** @param mixed $rating */
	private function listed_rating($rating, string $provider = 'facebook')
	{
		$json = $rating === 'MISSING' ? ['text' => 'Awesome service!'] : ['text' => 'Awesome service!', 'rating' => $rating];
		$GLOBALS['wpdb']->rows = [[
			'provider'     => $provider,
			'provider_id'  => 'collection-1',
			'post_content' => 'Awesome service!', // not valid base64: maybe_decrypt() would try it
			'json_data'    => wp_json_encode($json),
		]];

		$list = PostAggregator::get_source_posts_list('collection-1');
		$data = json_decode($list[0]['json_data'], true);

		return array_key_exists('rating', $data) ? $data['rating'] : 'MISSING';
	}

	public static function ratings(): array
	{
		return [
			'empty string stays unrated, not 5' => ['', ''],
			'unknown text is not a 5'           => ['recommends', 'recommends'],
			'positive is 5'                     => ['positive', 5],
			'negative is 1'                     => ['negative', 1],
			'numeric stars untouched'           => ['4', '4'],
			'no rating key untouched'           => ['MISSING', 'MISSING'],
		];
	}

	/**
	 * @dataProvider ratings
	 * @param mixed $stored
	 * @param mixed $want
	 */
	public function test_only_recommendations_map_to_stars($stored, $want): void
	{
		$this->assertSame($want, $this->listed_rating($stored));
	}

	public function test_other_providers_are_not_touched(): void
	{
		$this->assertSame('', $this->listed_rating('', 'google'));
	}

	public static function analytics_ratings(): array
	{
		return [
			'empty string is unrated, not 5' => ['', 0],
			'positive is 5'                  => ['positive', 5],
			'negative is 1'                  => ['negative', 1],
			'stored stars untouched'         => ['4', 4],
		];
	}

	/**
	 * The dashboard's recent-reviews row read the same "not negative => 5" rule.
	 *
	 * @dataProvider analytics_ratings
	 * @param mixed $stored
	 */
	public function test_recent_reviews_use_the_same_mapping($stored, int $want): void
	{
		$analytics = (new \ReflectionClass(SB_Analytics::class))->newInstanceWithoutConstructor();
		$shape     = new \ReflectionMethod(SB_Analytics::class, 'shape_recent_review_row');
		$shape->setAccessible(true);
		$row = ['provider' => 'google', 'rating' => $stored, 'json_data' => '{}', 'post_content' => 'Awesome service!'];

		$this->assertSame($want, $shape->invoke($analytics, $row, new \SmashBalloon\Reviews\Common\Helpers\Data_Encryption(), [])['rating']);
	}
}
