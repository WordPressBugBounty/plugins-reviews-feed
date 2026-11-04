<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/RoutinesWpCacheDeleteDouble.php';
// The double records into HideCachedProviderFailuresRoutineTest::$deleted; load it when this file runs alone.
require_once __DIR__ . '/HideCachedProviderFailuresRoutineTest.php';

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RemoveBlankErrorReviewsRoutine;

/**
 * SMASH-2647: the blank review cards 2.16.1 saved from a relay error map are removed once, and nothing else is.
 */
class RemoveBlankErrorReviewsRoutineTest extends TestCase
{
	/** @var mixed */
	private $saved_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$wp_options_mock = [];
		HideCachedProviderFailuresRoutineTest::$deleted = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		$wp_options_mock = [];
		parent::tearDown();
	}

	/** The row QA saved on 2.16.1 from relay master's refused-key answer (#581, R1). */
	private static function blank_row_json(): string
	{
		return (string) json_encode([
			'provider' => 'google',
			'message'  => 'Please make sure you have provided the right API Key.',
			'status'   => 'INVALID_ARGUMENT',
			'reason'   => 'Invalid API Key',
			'error'    => 'invalidKey',
			'reviewer' => ['name' => ''],
			'source'   => ['id' => 'ChIJ', 'url' => ''],
		]);
	}

	/**
	 * @dataProvider rowProvider
	 * @param mixed $json
	 */
	public function test_only_the_saved_error_map_matches(string $post_id, $json, bool $blank): void
	{
		$this->assertSame($blank, RemoveBlankErrorReviewsRoutine::is_blank_error_review($post_id, $json));
	}

	/** @return array<string, array{0: string, 1: mixed, 2: bool}> */
	public static function rowProvider(): array
	{
		$blank = self::blank_row_json();
		// sb-relay Traits/Utils.php getInvalidPlaceIdError(): no `message`.
		$blank_place = (string) json_encode([
			'provider' => 'google',
			'status'   => 'INVALID_REQUEST',
			'placeId'  => 'ChIJ',
			'reason'   => 'Invalid Location and place id.',
			'error'    => 'invalidLocation',
			'reviewer' => ['name' => ''],
			'source'   => ['id' => 'ChIJ', 'url' => ''],
		]);

		return [
			'the 2.16.1 blank row'                  => ['', $blank, true],
			'the refused-place blank row'           => ['', $blank_place, true],
			'no post_id, error but no reason'       => ['', (string) json_encode(['error' => 'x', 'message' => 'x']), false],
			'same json but a real post_id'          => ['r1', $blank, false],
			'rating-0 review with an id'            => ['r2', (string) json_encode(['review_id' => 'r2', 'text' => '', 'rating' => 0]), false],
			'anonymous review, no post_id, has text' => ['', (string) json_encode(['text' => 'Great', 'reason' => 'x', 'error' => 'x']), false],
			'no post_id, review_id in json'         => ['', (string) json_encode(['review_id' => 'r3', 'reason' => 'x', 'error' => 'x']), false],
			'no post_id, no error key'              => ['', (string) json_encode(['reason' => 'x']), false],
			'encrypted Facebook json'               => ['', 'enc:AbCdEf==', false],
			'null json'                             => ['', null, false],
		];
	}

	public function test_run_removes_only_matching_rows_and_keeps_them_for_rollback(): void
	{
		$blank = self::blank_row_json();
		$GLOBALS['wpdb'] = new class ($blank) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var list<array<string, mixed>> */
			public $deleted = [];
			/** @var list<mixed> */
			public $prepared_args = [];
			/** @var list<array<string, mixed>> */
			private $rows;

			public function __construct(string $blank)
			{
				$this->rows = [
					['id' => '4', 'post_id' => '', 'json_data' => $blank],
					['id' => '9', 'post_id' => '', 'json_data' => (string) json_encode(['text' => 'Real one', 'rating' => 0])],
				];
			}

			public function prepare($query, ...$args)
			{
				$this->prepared_args[] = [$query, $args];

				return $query;
			}

			public function get_results($sql, $output = null)
			{
				$rows = $this->rows;
				$this->rows = [];

				return $rows;
			}

			public function delete($table, $where, $format = null)
			{
				$this->deleted[] = ['table' => $table, 'where' => $where];

				return 1;
			}
		};

		$count = (new RemoveBlankErrorReviewsRoutine())->run();

		$this->assertSame(1, $count);
		[$query, $args] = $GLOBALS['wpdb']->prepared_args[0];
		$this->assertStringContainsString('WHERE post_id = %s', $query, 'only rows with no review id are read');
		$this->assertSame('', $args[0], 'the post_id filter is the empty string');
		$this->assertSame([['table' => 'wp_' . SBR_POSTS_TABLE, 'where' => ['id' => 4]]], $GLOBALS['wpdb']->deleted, 'only the blank row is deleted');
		$this->assertSame([['id' => 4, 'json_data' => $blank]], get_option(RemoveBlankErrorReviewsRoutine::ROLLBACK_OPTION), 'the removed row is kept for rollback');
	}

	public function test_every_feeds_object_cache_entry_is_forgotten(): void
	{
		$GLOBALS['wpdb'] = new class {
			/** @var string */
			public $prefix = 'wp_';

			public function get_col($sql)
			{
				return ['7', '542_CUSTOMIZER'];
			}
		};

		(new RemoveBlankErrorReviewsRoutine())->forget_feed_object_caches();

		$this->assertSame(['sbr_feed_7_', 'sbr_feed_542_CUSTOMIZER_'], HideCachedProviderFailuresRoutineTest::$deleted);
	}

	public function test_nothing_to_remove_writes_no_rollback_option(): void
	{
		$GLOBALS['wpdb'] = new class {
			/** @var string */
			public $prefix = 'wp_';

			public function prepare($query, ...$args)
			{
				return $query;
			}

			public function get_results($sql, $output = null)
			{
				return [];
			}
		};

		$this->assertSame(0, (new RemoveBlankErrorReviewsRoutine())->run());
		$this->assertFalse(get_option(RemoveBlankErrorReviewsRoutine::ROLLBACK_OPTION));
	}

	public function test_a_done_site_never_runs_it_again(): void
	{
		global $wp_options_mock;
		$wp_options_mock[RemoveBlankErrorReviewsRoutine::DONE_OPTION] = 1700000000;
		$GLOBALS['wpdb'] = new class {
			public function get_results($sql, $output = null)
			{
				throw new \LogicException('must not query once done');
			}
		};

		(new RemoveBlankErrorReviewsRoutine())->register();

		$this->assertSame(1700000000, get_option(RemoveBlankErrorReviewsRoutine::DONE_OPTION));
	}
}
