<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Forms\CollectionSubmissionReviews;
use SmashBalloon\Reviews\Pro\Integrations\Forms\SubmissionsManager;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update;

/**
 * SMASH-2041 — the two readers of `json_data` that SMASH-2024 / SMASH-2028 left
 * behind, plus the caller that never handled their "no review here" return.
 *
 *   Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews()
 *       decoded with no array gate. The two reads after it are `??`-rescued to
 *       '' and 0, so execution reached `$json_data['text'] = $new_text` — a
 *       WRITE to a string offset, which is a PHP 8 TypeError.
 *
 *   SubmissionsManager::transform_to_review()
 *       gated on empty() only, which a non-empty JSON string literal passes,
 *       and `$data['full_name']` is a bare read — same TypeError.
 *
 *   CollectionSubmissionReviews::transform_and_store_review()
 *       has always been able to receive false from the call above (an empty
 *       submission did it long before this ticket) and read
 *       `$review_store['source']` off it regardless.
 *
 * The Woo test drives the real static end to end against a $wpdb double and
 * asserts on the UPDATEs it issued, so trading the fatal for a silent no-op on
 * the healthy row would fail too.
 */
final class Smash2041RemainingJsonDataReadersTest extends TestCase
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
		// Restore the process-wide global even when the test failed, or the next
		// test in a random order inherits this double.
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * The shapes a `json_data` column can hold that are NOT a review object.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function nonReviewJsonProvider(): array
	{
		return [
			'JSON string literal' => ['"just a string"'],
			'JSON number'         => ['12345'],
			'JSON true'           => ['true'],
			'invalid JSON'        => ['not json at all'],
			'empty string'        => [''],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews()
	|--------------------------------------------------------------------------
	*/

	/**
	 * `refresh_existing_reviews()` returns immediately unless a `WooCommerce`
	 * class exists, and a PHP class cannot be un-declared once it is. Fifteen
	 * call sites across the plugin branch on `class_exists('WooCommerce')`, so
	 * declaring a stub in the shared bootstrap would silently flip behaviour for
	 * every other test in the process. Process isolation keeps it to this test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider nonReviewJsonProvider
	 */
	public function test_woo_refresh_skips_a_row_whose_json_data_is_not_a_review(string $raw): void
	{
		self::stub_woocommerce();
		$wpdb = $this->wpdb_double([
			['id' => 1, 'post_id' => 101, 'json_data' => $raw],
		]);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews();
		});

		$this->assertSame([], $wpdb->updates, 'A non-review row must not be written back.');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woo_refresh_still_updates_a_real_review_that_changed(): void
	{
		self::stub_woocommerce();
		$wpdb = $this->wpdb_double([
			['id' => 1, 'post_id' => 101, 'json_data' => json_encode(['text' => 'old text', 'rating' => 3])],
		]);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews();
		});

		$this->assertCount(1, $wpdb->updates);
		$this->assertSame('new text', $wpdb->updates[0]['data']['post_content']);
		$this->assertSame(5, $wpdb->updates[0]['data']['rating']);
		$decoded = json_decode($wpdb->updates[0]['data']['json_data'], true);
		$this->assertSame('new text', $decoded['text']);
		$this->assertSame(5, $decoded['rating']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woo_refresh_leaves_an_unchanged_review_alone(): void
	{
		self::stub_woocommerce();
		$wpdb = $this->wpdb_double([
			['id' => 1, 'post_id' => 101, 'json_data' => json_encode(['text' => 'new text', 'rating' => 5])],
		]);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews();
		});

		$this->assertSame([], $wpdb->updates, 'Identical content must still short-circuit.');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woo_refresh_updates_the_good_row_and_skips_the_corrupt_one(): void
	{
		self::stub_woocommerce();
		$wpdb = $this->wpdb_double([
			['id' => 1, 'post_id' => 101, 'json_data' => '"just a string"'],
			['id' => 2, 'post_id' => 102, 'json_data' => json_encode(['text' => 'old text', 'rating' => 1])],
		]);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			Bulk_WooCommerce_Reviews_Update::refresh_existing_reviews();
		});

		$this->assertCount(1, $wpdb->updates, 'One corrupt row must not stop the healthy one.');
		$this->assertSame(['id' => 2], $wpdb->updates[0]['where']);
	}

	/**
	 * Declared at runtime, inside the isolated process only — see the note on
	 * the first Woo test above.
	 */
	private static function stub_woocommerce(): void
	{
		// A required file, not eval: static analysis reads a real declaration, and
		// the global name still only comes into existence inside the isolated
		// process because this runs there. See tests/stubs/WooCommerce.php.
		require_once dirname(__DIR__) . '/stubs/WooCommerce.php';
	}

	/*
	|--------------------------------------------------------------------------
	| SubmissionsManager::transform_to_review()
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider nonReviewJsonProvider
	 */
	public function test_transform_to_review_returns_false_for_a_non_review(string $raw): void
	{
		$result = $this->withNoDiagnostics(static function () use ($raw) {
			return SubmissionsManager::transform_to_review(
				self::submission($raw),
				['account_id' => 'collection-1']
			);
		});

		$this->assertFalse($result);
	}

	public function test_transform_to_review_returns_false_when_json_data_is_missing(): void
	{
		$submission = self::submission('');
		unset($submission['json_data']);

		$result = $this->withNoDiagnostics(static function () use ($submission) {
			return SubmissionsManager::transform_to_review($submission, ['account_id' => 'collection-1']);
		});

		$this->assertFalse($result);
	}

	public function test_transform_to_review_still_builds_a_real_review(): void
	{
		$json = json_encode(['full_name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

		$review = $this->withNoDiagnostics(static function () use ($json) {
			return SubmissionsManager::transform_to_review(
				self::submission($json),
				['account_id' => 'collection-1']
			);
		});

		$this->assertIsArray($review);
		$this->assertSame('Ada Lovelace', $review['reviewer']['name']);
		$this->assertSame('Ada', $review['reviewer']['first_name']);
		$this->assertSame('Lovelace', $review['reviewer']['last_name']);
		$this->assertSame('collection-1', $review['source']['id']);
	}

	public function test_transform_to_review_accepts_an_already_decoded_array(): void
	{
		$submission = self::submission('');
		$submission['json_data'] = ['full_name' => 'Ada Lovelace', 'email' => 'ada@example.com'];

		$review = $this->withNoDiagnostics(static function () use ($submission) {
			return SubmissionsManager::transform_to_review($submission, ['account_id' => 'collection-1']);
		});

		$this->assertIsArray($review);
		$this->assertSame('Ada Lovelace', $review['reviewer']['name']);
	}

	/**
	 * A review object with no `full_name` must degrade, not fatal — the read was
	 * bare before and is `?? ''` now.
	 */
	public function test_transform_to_review_tolerates_a_review_without_full_name(): void
	{
		$review = $this->withNoDiagnostics(static function () {
			return SubmissionsManager::transform_to_review(
				self::submission(json_encode(['email' => 'ada@example.com'])),
				['account_id' => 'collection-1']
			);
		});

		$this->assertIsArray($review);
		$this->assertSame('', $review['reviewer']['name']);
	}

	/**
	 * Same for `email`. phpstan only surfaced this one once decode_review_json()
	 * gave `$data` a real type, which is the tell that the offset was always
	 * optional and the read was always bare.
	 */
	public function test_transform_to_review_tolerates_a_review_without_email(): void
	{
		$review = $this->withNoDiagnostics(static function () {
			return SubmissionsManager::transform_to_review(
				self::submission(json_encode(['full_name' => 'Ada Lovelace'])),
				['account_id' => 'collection-1']
			);
		});

		$this->assertIsArray($review);
		$this->assertSame('Ada Lovelace', $review['reviewer']['name']);
		$this->assertIsString($review['reviewer']['avatar']);
	}

	/*
	|--------------------------------------------------------------------------
	| CollectionSubmissionReviews::transform_and_store_review()
	|--------------------------------------------------------------------------
	*/

	/**
	 * The guard's whole job: return before `$review_store['source']` when the
	 * transform above declined. Without it this is an offset read on false, and
	 * then a MediaFinder built from null.
	 *
	 * @dataProvider nonReviewJsonProvider
	 */
	public function test_transform_and_store_returns_early_for_a_non_review(string $raw): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_double([]);

		$this->withNoDiagnostics(static function () use ($raw) {
			CollectionSubmissionReviews::transform_and_store_review(
				['account_id' => 'collection-1'],
				self::submission($raw)
			);
		});

		$this->assertTrue(true, 'Reached without a diagnostic or a fatal.');
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * @return array<string,mixed>
	 */
	private static function submission(string $json_data): array
	{
		return [
			'json_data'     => $json_data,
			'date'          => '1700000000',
			'rating'        => 5,
			'content'       => 'Some review content',
			'submission_id' => 'sub-1',
			'plugin'        => 'wpforms',
			'form_id'       => 7,
		];
	}

	/**
	 * Run $fn with every PHP diagnostic promoted to an exception, so a fix that
	 * only downgraded the TypeError to a warning still fails the test.
	 *
	 * @param  callable $fn
	 * @return mixed
	 */
	private function withNoDiagnostics(callable $fn)
	{
		set_error_handler(static function ($severity, $message, $file, $line) {
			throw new \ErrorException($message, 0, $severity, $file, $line);
		});

		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Routes the three SELECTs refresh_existing_reviews() issues and records
	 * every UPDATE so a test can assert which rows were written.
	 *
	 * @param  array<int,array<string,mixed>> $review_rows
	 * @return object
	 */
	private function wpdb_double(array $review_rows)
	{
		return new class ($review_rows) {
			public $prefix = 'wp_';
			public $comments = 'wp_comments';
			public $commentmeta = 'wp_commentmeta';
			/** @var list<array<string,mixed>> */
			public $updates = [];
			/** @var array<int,array<string,mixed>> */
			private $review_rows;

			public function __construct(array $review_rows)
			{
				$this->review_rows = $review_rows;
			}

			public function prepare($sql, ...$args)
			{
				return $sql;
			}

			/**
			 * @return array<mixed>
			 */
			public function get_results($sql, $output = null)
			{
				$sql = (string) $sql;

				if (strpos($sql, 'sbr_sources') !== false) {
					return [[
						'id'         => 'src-1',
						'account_id' => 'acct-1',
						'provider'   => 'woocommerce',
						'info'       => json_encode(['product_ids' => [55]]),
					]];
				}

				if (strpos($sql, 'sbr_reviews_posts') !== false) {
					return $this->review_rows;
				}

				// The WooCommerce comment join, keyed by comment_ID (OBJECT_K).
				$comments = [];
				foreach ($this->review_rows as $row) {
					$comment                    = new \stdClass();
					$comment->comment_ID        = $row['post_id'];
					$comment->comment_content   = 'new text';
					$comment->comment_date      = '2026-09-03 00:00:00';
					$comment->comment_author    = 'Ada';
					$comment->rating            = 5;
					$comments[ $row['post_id'] ] = $comment;
				}

				return $comments;
			}

			public function get_row($sql, $output = null)
			{
				return null;
			}

			public function get_var($sql)
			{
				return 0;
			}

			public function get_col($sql)
			{
				return [];
			}

			public function query($sql)
			{
				return 1;
			}

			public function insert($table, $data, $format = null)
			{
				return 1;
			}

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];

				return 1;
			}
		};
	}
}
