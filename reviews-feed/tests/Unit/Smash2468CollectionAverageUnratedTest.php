<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RecalculateCollectionRatingsRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RecalculateUnratedCollectionRatingsRoutine;
use SmashBalloon\Reviews\Common\Services\Upgrade\RoutineManagerService;

/**
 * SMASH-2468: update_collection_ratings() added a review with no rating as 0 stars, so Nora (none),
 * Ivo 4 and Lea 5 gave a header of 3 instead of 4.5. Unrated reviews still count toward total_rating,
 * the way WooCommerce averages rated reviews only but counts every review.
 */
final class Smash2468CollectionAverageUnratedTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	/** @var array<string, mixed> */
	private $previous_options;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb    = $GLOBALS['wpdb'] ?? null;
		$this->previous_options = $GLOBALS['wp_options_mock'] ?? null;
		$GLOBALS['wp_options_mock'] = [];
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var list<array> */
			public $updates = [];
			/** @var array<string, list<array>> review rows per collection id */
			public $reviews = [];
			/** @var list<string> collection ids get_col() returns */
			public $collection_ids = [];
			/** @var string|null provider_id the last prepare() was asked for */
			private $last_arg;

			public function prepare($query, ...$args)
			{
				$this->last_arg = $args[0] ?? null;
				return $query . ' -- ' . wp_json_encode($args);
			}
			public function get_results($query, $output = null)
			{
				if (strpos($query, 'sbr_sources') !== false && strpos($query, 'used_in') !== false) {
					return [ [ 'id' => 1, 'account_id' => $this->last_arg, 'provider' => 'collection', 'name' => 'C', 'info' => '{"rating":0,"total_rating":0}', 'used_in' => 0 ] ];
				}
				if (strpos($query, 'reviews_posts') !== false) {
					return $this->reviews[ $this->last_arg ] ?? [];
				}
				return [];
			}
			public function get_col($query)
			{
				return $this->collection_ids;
			}
			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = compact('table', 'data', 'where');
				return 1;
			}
			public function esc_like($text)
			{
				return $text;
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		if ($this->previous_options === null) {
			unset($GLOBALS['wp_options_mock']);
		} else {
			$GLOBALS['wp_options_mock'] = $this->previous_options;
		}
		parent::tearDown();
	}

	/** @param list<string> $ratings the INT NOT NULL column as $wpdb returns it; no stars is '0' */
	private function seed(string $collection_id, array $ratings): void
	{
		$GLOBALS['wpdb']->reviews[ $collection_id ] = array_map(function ($r) {
			return [ 'rating' => $r ];
		}, $ratings);
	}

	/** @return array{rating: float|int, total_rating: int} */
	private function saved_info(string $collection_id): array
	{
		foreach (array_reverse($GLOBALS['wpdb']->updates) as $u) {
			if (( $u['where']['account_id'] ?? null ) === $collection_id && isset($u['data']['info'])) {
				return json_decode($u['data']['info'], true);
			}
		}
		$this->fail("no info saved for $collection_id");
	}

	public static function collections(): array
	{
		return [
			'the ticket: none, 4, 5'      => [ [ '0', '4', '5' ], 4.5, 3 ],
			'two unrated among rated'     => [ [ '0', '3', '0', '5' ], 4, 4 ],
			'all rated: unchanged (1685)' => [ [ '5', '5', '3', '5', '5' ], 4.6, 5 ],
			'a real 1 star still counts'  => [ [ '1', '5', '0' ], 3, 3 ],
			'only unrated reviews'        => [ [ '0', '0' ], 0, 2 ],
		];
	}

	/** @dataProvider collections */
	public function test_average_skips_unrated_but_count_keeps_them(array $ratings, $want_rating, int $want_total): void
	{
		$this->seed('collection-1', $ratings);
		SBR_Sources::update_collection_ratings('collection-1');
		$info = $this->saved_info('collection-1');
		$this->assertEquals($want_rating, $info['rating']);
		$this->assertSame($want_total, $info['total_rating'], '"Over N Reviews" still counts every review');
		// SMASH-2477: the count the average is over, for multi-source weighting.
		$this->assertSame(count(array_filter($ratings, function ($r) {
			return (int) $r > 0;
		})), $info['rated_count']);
	}

	public function test_upgrade_recalculates_collections_saved_before_the_fix(): void
	{
		$GLOBALS['wpdb']->collection_ids = [ 'collection-a' ];
		$this->seed('collection-a', [ '0', '4', '5' ]);

		( new RecalculateUnratedCollectionRatingsRoutine() )->register();

		$this->assertEquals(4.5, $this->saved_info('collection-a')['rating']);
		$this->assertNotEmpty(get_option(RecalculateUnratedCollectionRatingsRoutine::DONE_OPTION));
	}

	/** Sites already on 2.15.1 have the SMASH-1685 flag set, so that routine won't run again for them. */
	public function test_upgrade_runs_even_when_the_1685_recalculation_already_ran(): void
	{
		update_option(RecalculateCollectionRatingsRoutine::DONE_OPTION, 1);
		$GLOBALS['wpdb']->collection_ids = [ 'collection-a' ];
		$this->seed('collection-a', [ '0', '4', '5' ]);

		( new RecalculateCollectionRatingsRoutine() )->register();
		$this->assertSame([], $GLOBALS['wpdb']->updates, 'precondition: the 1685 routine is spent');

		( new RecalculateUnratedCollectionRatingsRoutine() )->register();
		$this->assertEquals(4.5, $this->saved_info('collection-a')['rating']);
	}

	/** A site jumping from before 2.15.1 runs both routines in one request; one pass is enough. */
	public function test_a_fresh_upgrade_recalculates_each_collection_once(): void
	{
		$GLOBALS['wpdb']->collection_ids = [ 'collection-a' ];
		$this->seed('collection-a', [ '0', '4', '5' ]);

		( new RecalculateCollectionRatingsRoutine() )->register();
		( new RecalculateUnratedCollectionRatingsRoutine() )->register();

		$this->assertEquals(4.5, $this->saved_info('collection-a')['rating']);
		$this->assertCount(1, $GLOBALS['wpdb']->updates, 'one recalculation, not two');
	}

	/** SMASH-2477: a site that ran the pass before rated_count existed runs it once more to store it. */
	public function test_a_site_that_ran_the_2468_only_pass_recalculates_for_rated_count(): void
	{
		update_option(RecalculateCollectionRatingsRoutine::DONE_OPTION, 1);
		update_option('sbr_collection_rating_2468_done', 1);
		$GLOBALS['wpdb']->collection_ids = [ 'collection-a' ];
		$this->seed('collection-a', [ '0', '4', '5' ]);

		( new RecalculateUnratedCollectionRatingsRoutine() )->register();

		$this->assertSame(2, $this->saved_info('collection-a')['rated_count']);
	}

	public function test_upgrade_runs_once(): void
	{
		update_option(RecalculateUnratedCollectionRatingsRoutine::DONE_OPTION, 1);
		$GLOBALS['wpdb']->collection_ids = [ 'collection-a' ];
		$this->seed('collection-a', [ '0', '4', '5' ]);

		( new RecalculateUnratedCollectionRatingsRoutine() )->register();

		$this->assertSame([], $GLOBALS['wpdb']->updates);
	}

	public function test_routine_is_registered_after_the_1685_one(): void
	{
		$routines = ( new \ReflectionProperty(RoutineManagerService::class, 'routines') );
		$routines->setAccessible(true);
		$list = $routines->getValue(new RoutineManagerService());

		$this->assertContains(RecalculateUnratedCollectionRatingsRoutine::class, $list);
		$this->assertGreaterThan(
			array_search(RecalculateCollectionRatingsRoutine::class, $list, true),
			array_search(RecalculateUnratedCollectionRatingsRoutine::class, $list, true)
		);
	}
}
