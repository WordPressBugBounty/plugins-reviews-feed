<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\PostAggregator;

/**
 * SMASH-2024 — creating a Google Reviews feed on PHP 8 died with
 *
 *   PHP Fatal error: Uncaught TypeError: Cannot access offset of type string on string
 *   in class/Common/PostAggregator.php:102  (remove_duplicated_posts_list)
 *
 * Line 102 is `$post_json['source'] = [...]`. `$post_json` is the decoded
 * `json_data` column, gated only by `!empty()`. `json_decode()` returns
 * whatever the column's top-level JSON value is — so a column holding a JSON
 * string literal (a double-encoded write) decodes to a non-empty PHP string,
 * passes the gate, and the array write onto it is a TypeError on PHP 8.
 *
 * The same shape sat in remove_duplicated_posts_routine() (the upgrade routine
 * that DELETEs duplicates) and normalize_db_post_set() (the render path), so
 * all three now go through PostAggregator::decode_review_json(), which only
 * ever returns an array or null.
 *
 * Every test here throws TypeError against the un-fixed code and passes
 * against the fix. The valid-row assertions pin that real reviews still
 * dedupe and render exactly as before.
 */
final class Smash2024PostAggregatorScalarJsonDataTest extends TestCase
{
	/** @var object|null */
	private $original_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * A stored Google review row, as `wpdb::get_results(ARRAY_A)` returns it.
	 *
	 * @param array<string,mixed> $review
	 * @return array<string,mixed>
	 */
	private function google_row(int $id, array $review): array
	{
		return [
			'id'          => $id,
			'post_id'     => $review['review_id'] ?? ('rev-' . $id),
			'provider'    => 'google',
			'provider_id' => 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk',
			'post_content' => $review['text'] ?? '',
			'json_data'   => json_encode($review),
			'images_done' => 1,
			'lang'        => 'en',
		];
	}

	/**
	 * The same row, but with `json_data` set to a raw column value of our choosing.
	 *
	 * @return array<string,mixed>
	 */
	private function google_row_with_raw_json(int $id, string $raw_json_data): array
	{
		$row = $this->google_row($id, $this->review('placeholder', 'Someone', 5));
		$row['json_data'] = $raw_json_data;

		return $row;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function review(string $id, string $reviewer, int $rating, string $text = 'Great place'): array
	{
		return [
			'review_id' => $id,
			'text'      => $text,
			'rating'    => $rating,
			'time'      => 1725000000,
			'reviewer'  => ['name' => $reviewer, 'avatar' => ''],
			'provider'  => ['name' => 'google'],
			'source'    => ['id' => 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk', 'url' => ''],
		];
	}

	/**
	 * Column values that decode to something other than an array. Every one of
	 * these passed the old `!empty($post_json)` gate (or hit an offset write).
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function scalarJsonDataProvider(): array
	{
		return [
			// The reported shape: a JSON string literal — what a double-encoded
			// write (wp_json_encode of an already-encoded string) leaves behind.
			'JSON string literal'          => ['"just a string"'],
			'double-encoded review object' => [json_encode(json_encode(['review_id' => 'x', 'rating' => 5]))],
			'JSON number'                  => ['42'],
			'JSON true'                    => ['true'],
			// Not JSON at all — already skipped before, must stay skipped.
			'invalid JSON'                 => ['not json {'],
			'empty string'                 => [''],
		];
	}

	/**
	 * Runs $fn with an error handler that turns any PHP diagnostic into a failure,
	 * so a fix that trades the fatal for a warning does not pass.
	 *
	 * @return mixed
	 */
	private function withNoDiagnostics(callable $fn)
	{
		$raised = [];
		set_error_handler(static function ($errno, $errstr) use (&$raised) {
			$raised[] = $errstr;

			return true;
		});

		try {
			$result = $fn();
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $raised, 'PHP diagnostics raised: ' . implode(' | ', $raised));

		return $result;
	}

	/*
	|--------------------------------------------------------------------------
	| decode_review_json() — the shared gate
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider scalarJsonDataProvider
	 */
	public function test_decode_review_json_returns_null_for_anything_that_is_not_an_object(string $raw): void
	{
		$this->assertNull(PostAggregator::decode_review_json($raw));
	}

	public function test_decode_review_json_returns_the_array_for_a_review_object(): void
	{
		$review = $this->review('r1', 'Ada', 5);

		$this->assertSame($review, PostAggregator::decode_review_json(json_encode($review)));
		// An already-decoded review passes straight through.
		$this->assertSame($review, PostAggregator::decode_review_json($review));
	}

	public function test_decode_review_json_returns_null_for_non_string_scalars(): void
	{
		$this->assertNull(PostAggregator::decode_review_json(null));
		$this->assertNull(PostAggregator::decode_review_json(42));
		$this->assertNull(PostAggregator::decode_review_json(false));
	}

	/*
	|--------------------------------------------------------------------------
	| remove_duplicated_posts_list() — the reported crash site (line 102)
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider scalarJsonDataProvider
	 */
	public function test_db_list_skips_a_row_whose_json_data_is_not_a_review(string $raw): void
	{
		$rows = [
			$this->google_row(1, $this->review('r1', 'Ada', 5)),
			$this->google_row_with_raw_json(2, $raw),
			$this->google_row(3, $this->review('r3', 'Grace', 4)),
		];

		$result = $this->withNoDiagnostics(static function () use ($rows) {
			return PostAggregator::remove_duplicated_posts_list($rows, 'db');
		});

		$this->assertCount(2, $result, 'the two real reviews survive, the scalar row is dropped');
		$this->assertSame([1, 3], array_column($result, 'id'));
	}

	public function test_db_list_still_dedupes_real_reviews_the_same_way(): void
	{
		// Same source, rating, reviewer and text → a duplicate, keep the first.
		$rows = [
			$this->google_row(1, $this->review('r1', 'Ada', 5)),
			$this->google_row(2, $this->review('r1-again', 'Ada', 5)),
			$this->google_row(3, $this->review('r3', 'Ada', 5, 'Different words')),
		];

		$result = PostAggregator::remove_duplicated_posts_list($rows, 'db');

		$this->assertSame([1, 3], array_column($result, 'id'));
	}

	public function test_db_list_skips_a_row_that_is_not_an_array_at_all(): void
	{
		// `db_post_set()` always hands arrays, but the method also accepts the
		// decrypted-JSON path, where a bad entry can be a scalar or fail to decode.
		// (Entries start with `{` so Data_Encryption::maybe_decrypt() passes them
		// through untouched — its openssl warning on arbitrary strings is a
		// separate, pre-existing behaviour, not what this ticket is about.)
		$rows = [
			$this->google_row(1, $this->review('r1', 'Ada', 5)),
			'{not json',
			123,
			null,
			false,
		];

		$result = $this->withNoDiagnostics(static function () use ($rows) {
			return PostAggregator::remove_duplicated_posts_list($rows, 'db');
		});

		$this->assertSame([1], array_column($result, 'id'));
	}

	public function test_json_list_skips_scalar_entries_from_the_feed_cache(): void
	{
		// The cache-hit render path (Feed.php:172) passes decoded cache entries;
		// a non-array entry is the same non-review and must not fatal either.
		$posts = [
			$this->review('r1', 'Ada', 5),
			'{not json',
			123,
			null,
			false,
			$this->review('r2', 'Grace', 4),
		];

		$result = $this->withNoDiagnostics(static function () use ($posts) {
			return PostAggregator::remove_duplicated_posts_list($posts, 'json');
		});

		$this->assertSame(['r1', 'r2'], array_column($result, 'review_id'));
	}

	/*
	|--------------------------------------------------------------------------
	| remove_duplicated_posts_routine() — the upgrade routine that DELETEs
	|--------------------------------------------------------------------------
	*/

	public function test_routine_does_not_fatal_on_and_never_deletes_a_scalar_row(): void
	{
		$rows = [
			$this->google_row(1, $this->review('r1', 'Ada', 5)),
			$this->google_row_with_raw_json(2, '"just a string"'),
			$this->google_row(3, $this->review('r1-dup', 'Ada', 5)), // duplicate of row 1
		];
		$wpdb = $this->wpdb_double($rows);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			PostAggregator::remove_duplicated_posts_routine();
		});

		$this->assertCount(1, $wpdb->queries, 'exactly one DELETE was issued');
		$this->assertStringContainsString('WHERE id IN (3)', $wpdb->queries[0], 'only the real duplicate is deleted; the scalar row is left alone');
	}

	/*
	|--------------------------------------------------------------------------
	| normalize_db_post_set() — the render path
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider scalarJsonDataProvider
	 */
	public function test_normalize_db_post_set_drops_a_row_whose_json_data_is_not_a_review(string $raw): void
	{
		$rows = [
			$this->google_row(1, $this->review('r1', 'Ada', 5)),
			$this->google_row_with_raw_json(2, $raw),
		];
		$aggregator = new PostAggregator();

		$result = $this->withNoDiagnostics(static function () use ($aggregator, $rows) {
			return $aggregator->normalize_db_post_set($rows);
		});

		$this->assertCount(1, $result);
		$this->assertSame('r1', $result[0]['review_id']);
		$this->assertIsArray($result[0]['reviewer'], 'the real review is still shape-normalized');
	}

	/**
	 * Minimal $wpdb stand-in: serves the given rows and records every query().
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return object
	 */
	private function wpdb_double(array $rows)
	{
		return new class ($rows) {
			public $prefix = 'wp_';
			/** @var list<string> */
			public $queries = [];
			/** @var array<int,array<string,mixed>> */
			private $rows;

			public function __construct(array $rows)
			{
				$this->rows = $rows;
			}

			public function get_results($sql, $output = null)
			{
				return $this->rows;
			}

			public function query($sql)
			{
				$this->queries[] = $sql;

				return 1;
			}

			public function prepare($query, ...$args)
			{
				return $query;
			}
		};
	}
}
