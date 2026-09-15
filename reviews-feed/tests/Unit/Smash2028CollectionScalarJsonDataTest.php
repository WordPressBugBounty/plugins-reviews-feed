<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-2028 — the same non-review `json_data` row that took the feed builder
 * down in SMASH-2024 also reaches three collection handlers, which had no
 * array gate at all:
 *
 *   duplicate_collection()      reads the SAME table via get_source_all_posts()
 *   import_full_collection()    reads entries out of an uploaded file
 *   import_reviews_collection() same
 *
 * On PHP 8 a *bare read* of a string key on a string throws the same
 * `TypeError: Cannot access offset of type string on string` as a write — only
 * `??` rescues it — so `absint($review_json['time'])` was enough to kill the
 * request. The import pair threw one step earlier still: `unset($entry['id'])`
 * on a string is `Error: Cannot unset string offsets`.
 *
 * Every handler test here drives the real handler end to end against a $wpdb
 * double and asserts on the INSERTs it issued, so a fix that merely traded the
 * fatal for a warning does not pass either.
 */
final class Smash2028CollectionScalarJsonDataTest extends TestCase
{
	/** @var object|null */
	private $original_wpdb;

	/** @var list<string> */
	private $temp_files = [];

	/** The permissive globals this fixture sets, named once so the test below reads the same list. */
	public const AUTH_GLOBALS = array( 'sbr_test_nonce_ok', 'sbr_test_user_can' );

	/** @var array<string, array{set: bool, value: mixed}> Prior values of AUTH_GLOBALS. */
	private $original_auth_globals = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$_POST  = [];
		$_FILES = [];

		// Saved, because these are PERMISSIVE and process-wide: left set, any later
		// test expecting a nonce or capability check to FAIL would silently pass —
		// visible only under --order-by=random, and only sometimes.
		foreach (self::AUTH_GLOBALS as $key) {
			$this->original_auth_globals[ $key ] = array_key_exists($key, $GLOBALS)
				? array( 'set' => true, 'value' => $GLOBALS[ $key ] )
				: array( 'set' => false, 'value' => null );
		}

		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->original_wpdb;
		$_POST  = [];
		$_FILES = [];

		foreach ($this->original_auth_globals as $key => $prior) {
			if ($prior['set']) {
				$GLOBALS[ $key ] = $prior['value'];
			} else {
				unset($GLOBALS[ $key ]);
			}
		}

		$this->original_auth_globals = [];
		foreach ($this->temp_files as $path) {
			if (file_exists($path)) {
				unlink($path);
			}
		}
		$this->temp_files = [];
		parent::tearDown();
	}

	/**
	 * The list above is load-bearing for PermissiveTestGlobalsRestoredTest, which
	 * drives this fixture's setUp/tearDown pair and asserts the grant is handed
	 * back. Named here so both sides read one list.
	 *
	 * @return void
	 */
	public function test_the_permissive_auth_globals_are_declared(): void
	{
		$this->assertSame(array( 'sbr_test_nonce_ok', 'sbr_test_user_can' ), self::AUTH_GLOBALS);
	}

	/*
	|--------------------------------------------------------------------------
	| imported_collection_review() — the shared gate for an uploaded entry
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider nonArrayEntryProvider
	 * @param mixed $entry
	 */
	public function test_imported_entry_that_is_not_an_array_is_skipped($entry): void
	{
		$this->assertNull($this->withNoDiagnostics(static function () use ($entry) {
			return SBR_Feed_Saver_Manager::imported_collection_review($entry);
		}));
	}

	/**
	 * An entry of `reviewsList` need not be an object — it is file content. Each
	 * of these used to throw on `unset($entry['id'])` before json_data was read.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function nonArrayEntryProvider(): array
	{
		return [
			'string entry' => ['just a string'],
			'int entry'    => [42],
			'float entry'  => [4.5],
			'bool entry'   => [true],
			'null entry'   => [null],
			// The scalars above all survive without the is_array() guard, because
			// `$entry['json_data'] ?? null` yields null on a scalar offset read.
			// An object is the one shape `??` does not rescue — it raises
			// "Cannot use object of type stdClass as array" — so this case is what
			// makes the guard load-bearing rather than decorative.
			'object entry' => [new \stdClass()],
		];
	}

	/**
	 * @dataProvider scalarJsonDataProvider
	 */
	public function test_imported_entry_with_a_non_review_json_data_is_skipped(string $raw): void
	{
		$entry = ['id' => 7, 'json_data' => $raw];

		$this->assertNull($this->withNoDiagnostics(static function () use ($entry) {
			return SBR_Feed_Saver_Manager::imported_collection_review($entry);
		}));
	}

	public function test_imported_entry_with_no_json_data_key_is_skipped_without_a_notice(): void
	{
		$this->assertNull($this->withNoDiagnostics(static function () {
			return SBR_Feed_Saver_Manager::imported_collection_review(['id' => 7]);
		}));
	}

	public function test_imported_entry_with_a_real_review_is_returned_intact(): void
	{
		$review = $this->review('r1', 'Ada', 5);
		$entry  = ['id' => 7, 'json_data' => json_encode($review)];

		$decoded = $this->withNoDiagnostics(static function () use ($entry) {
			return SBR_Feed_Saver_Manager::imported_collection_review($entry);
		});

		$this->assertSame($review, $decoded);
	}

	/*
	|--------------------------------------------------------------------------
	| duplicate_collection() — the live-reproduced fatal, same table as 2024
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider scalarJsonDataProvider
	 */
	public function test_duplicate_collection_skips_a_row_whose_json_data_is_not_a_review(string $raw): void
	{
		$wpdb = $this->drive_duplicate_collection([
			$this->collection_row(1, $this->review('r1', 'Ada', 5)),
			$this->collection_row_with_raw_json(2, $raw),
			$this->collection_row(3, $this->review('r3', 'Grace', 4)),
		]);

		$inserted = $wpdb->inserted_post_ids();
		$this->assertCount(2, $inserted, 'the two real reviews were copied');
		$this->assertNotContains('r2', $inserted, 'the non-review row was not copied');
	}

	public function test_duplicate_collection_copies_every_row_when_none_are_corrupt(): void
	{
		$wpdb = $this->drive_duplicate_collection([
			$this->collection_row(1, $this->review('r1', 'Ada', 5)),
			$this->collection_row(2, $this->review('r2', 'Grace', 4)),
		]);

		$this->assertCount(2, $wpdb->inserted_post_ids(), 'unchanged for healthy data');
	}

	public function test_duplicate_collection_copies_nothing_when_every_row_is_corrupt(): void
	{
		$wpdb = $this->drive_duplicate_collection([
			$this->collection_row_with_raw_json(1, '"just a string"'),
			$this->collection_row_with_raw_json(2, '42'),
		]);

		$this->assertSame([], $wpdb->inserted_post_ids());
	}

	/*
	|--------------------------------------------------------------------------
	| import_full_collection() / import_reviews_collection()
	|--------------------------------------------------------------------------
	*/

	public function test_import_full_collection_skips_a_scalar_entry_and_imports_the_rest(): void
	{
		$wpdb = $this->drive_import('import_full_collection', [
			'just a string',
			['id' => 2, 'json_data' => json_encode($this->review('r2', 'Ada', 5))],
			['id' => 3, 'json_data' => '"just a string"'],
		]);

		$this->assertSame(['r2'], $wpdb->inserted_post_ids());
	}

	public function test_import_reviews_collection_skips_a_scalar_entry_and_imports_the_rest(): void
	{
		$wpdb = $this->drive_import('import_reviews_collection', [
			42,
			['id' => 2, 'json_data' => json_encode($this->review('r2', 'Ada', 5))],
			['id' => 3, 'json_data' => 'not json {'],
		]);

		$this->assertCount(1, $wpdb->inserted_post_ids(), 'only the real review was imported');
	}

	/**
	 * @dataProvider importHandlerProvider
	 */
	public function test_import_imports_nothing_when_every_entry_is_unusable(string $handler): void
	{
		$wpdb = $this->drive_import($handler, ['a string', 7, true, ['id' => 4, 'json_data' => '42']]);

		$this->assertSame([], $wpdb->inserted_post_ids());
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function importHandlerProvider(): array
	{
		return [
			'import_full_collection'    => ['import_full_collection'],
			'import_reviews_collection' => ['import_reviews_collection'],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| parse_collection_json() — a scalar reviewsList never reaches a foreach
	|--------------------------------------------------------------------------
	*/

	public function test_a_scalar_reviews_list_is_rejected_as_a_bad_import_file(): void
	{
		$_FILES = $this->uploaded_json(['account_id' => 'c1', 'name' => 'C', 'reviewsList' => 'oops']);

		$halt = null;
		$this->withNoDiagnostics(static function () use (&$halt) {
			try {
				SBR_Feed_Saver_Manager::parse_collection_json();
			} catch (\SBR_Test_Halt $e) {
				$halt = $e;
			}
		});

		$this->assertNotNull($halt, 'the handler rejected the file instead of foreach-ing a string');
		$this->assertSame('wp_send_json_error', $halt->getMessage());
	}

	public function test_a_real_reviews_list_still_parses(): void
	{
		$list   = [['id' => 1, 'json_data' => json_encode($this->review('r1', 'Ada', 5))]];
		$_FILES = $this->uploaded_json(['account_id' => 'c1', 'name' => 'C', 'reviewsList' => $list]);

		$parsed = $this->withNoDiagnostics(static function () {
			return SBR_Feed_Saver_Manager::parse_collection_json();
		});

		$this->assertSame($list, $parsed['reviewsList']);
	}

	/*
	|--------------------------------------------------------------------------
	| Fixtures + harness
	|--------------------------------------------------------------------------
	*/

	/**
	 * Column values that decode to something other than an array. Each one
	 * passed the old gate (or the absent gate) and reached an offset read.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function scalarJsonDataProvider(): array
	{
		return [
			'JSON string literal'          => ['"just a string"'],
			'double-encoded review object' => [json_encode(json_encode(['review_id' => 'x', 'rating' => 5]))],
			'JSON number'                  => ['42'],
			'JSON true'                    => ['true'],
			'invalid JSON'                 => ['not json {'],
			'empty string'                 => [''],
		];
	}

	/**
	 * A complete review. Every key the handlers read is present: they read
	 * `text`/`title`/`time`/`rating` bare, and a partial fixture would fail on
	 * an "Undefined array key" notice that has nothing to do with this fix.
	 *
	 * @return array<string,mixed>
	 */
	private function review(string $review_id, string $name, int $rating): array
	{
		return [
			'review_id' => $review_id,
			'text'      => 'Lovely place.',
			'title'     => 'Great',
			'rating'    => $rating,
			'time'      => 1756000000,
			'reviewer'  => ['name' => $name, 'avatar' => 'https://example.com/a.png'],
			'provider'  => ['name' => 'google'],
			'source'    => ['id' => 'collection-1', 'url' => 'https://example.com'],
		];
	}

	/**
	 * A stored collection review row, as `wpdb::get_results(ARRAY_A)` returns it.
	 *
	 * @param array<string,mixed> $review
	 * @return array<string,mixed>
	 */
	private function collection_row(int $id, array $review): array
	{
		return [
			'id'           => $id,
			'post_id'      => $review['review_id'],
			'provider'     => 'collection',
			'provider_id'  => 'collection-1',
			'post_content' => $review['text'],
			'json_data'    => json_encode($review),
			'images_done'  => 1,
			'lang'         => 'en',
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function collection_row_with_raw_json(int $id, string $raw_json_data): array
	{
		$row = $this->collection_row($id, $this->review('r' . $id, 'Someone', 5));
		$row['json_data'] = $raw_json_data;

		return $row;
	}

	/**
	 * Runs duplicate_collection() against the given cached rows and hands back
	 * the $wpdb double so the caller can read the INSERTs off it.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return object
	 */
	private function drive_duplicate_collection(array $rows)
	{
		$_POST['collection_id'] = 'collection-1';
		$wpdb = $this->wpdb_double($rows);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () {
			ob_start();
			try {
				SBR_Feed_Saver_Manager::duplicate_collection();
			} catch (\SBR_Test_Halt $e) {
				// wp_die() at the end of the handler.
			} finally {
				ob_end_clean();
			}
		});

		return $wpdb;
	}

	/**
	 * Runs one of the two import handlers over the given `reviewsList`.
	 *
	 * @param array<int,mixed> $reviews_list
	 * @return object
	 */
	private function drive_import(string $handler, array $reviews_list)
	{
		$_POST['collection_id'] = 'collection-1';
		$_FILES = $this->uploaded_json([
			'account_id'  => 'collection-1',
			'name'        => 'Imported',
			'info'        => json_encode(['rating' => 5, 'total_rating' => 2]),
			'reviewsList' => $reviews_list,
		]);
		$wpdb = $this->wpdb_double([]);
		$GLOBALS['wpdb'] = $wpdb;

		$this->withNoDiagnostics(static function () use ($handler) {
			ob_start();
			try {
				SBR_Feed_Saver_Manager::$handler();
			} catch (\SBR_Test_Halt $e) {
				// wp_die() at the end of the handler.
			} finally {
				ob_end_clean();
			}
		});

		return $wpdb;
	}

	/**
	 * A $_FILES entry backed by a real temp file, since parse_collection_json()
	 * reads it with file_get_contents().
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,array<string,string>>
	 */
	private function uploaded_json(array $payload): array
	{
		$path = tempnam(sys_get_temp_dir(), 'sbr2028') . '.json';
		file_put_contents($path, json_encode($payload));
		$this->temp_files[] = $path;

		return ['feedFile' => ['name' => 'collection.json', 'tmp_name' => $path]];
	}

	/**
	 * Runs $fn with an error handler that turns any PHP diagnostic into a
	 * failure, so a fix that trades the fatal for a warning does not pass.
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

	/**
	 * Minimal $wpdb stand-in. Serves the given review rows to the posts-table
	 * SELECT, a single collection source to the sources-table SELECT, and
	 * records every query so the caller can see which reviews were stored.
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

			/**
			 * The handlers issue two different SELECTs through the same double,
			 * so route on the table the SQL names.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function get_results($sql, $output = null)
			{
				if (strpos((string) $sql, 'sbr_sources') !== false) {
					return [[
						'id'           => 'collection-1',
						'account_id'   => 'collection-1',
						'name'         => 'A collection',
						'provider'     => 'collection',
						'info'         => json_encode(['rating' => 5, 'total_rating' => 2]),
						'used_in'      => 0,
					]];
				}

				return $this->rows;
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
				$this->queries[] = (string) $sql;

				return 1;
			}

			public function insert($table, $data, $format = null)
			{
				$this->queries[] = 'INSERT INTO ' . $table . ' ' . json_encode($data);

				return 1;
			}

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->queries[] = 'UPDATE ' . $table . ' ' . json_encode($data);

				return 1;
			}

			public function esc_like($text)
			{
				return $text;
			}

			/**
			 * Substitutes the placeholders, so assertions can see the values that
			 * were about to be written rather than a row of %s.
			 */
			public function prepare($query, ...$args)
			{
				if ($args === []) {
					return $query;
				}
				if (count($args) === 1 && is_array($args[0])) {
					$args = $args[0];
				}

				return (string) preg_replace_callback(
					'/%[sdf]/',
					static function ($m) use (&$args) {
						$value = array_shift($args);
						if ($m[0] === '%d') {
							return (string) (int) $value;
						}
						if ($m[0] === '%f') {
							return (string) (float) $value;
						}

						return "'" . (is_scalar($value) ? (string) $value : '') . "'";
					},
					(string) $query
				);
			}

			/**
			 * The `post_id` of every review actually written to the posts table.
			 *
			 * @return list<string>
			 */
			public function inserted_post_ids(): array
			{
				$ids = [];
				foreach ($this->queries as $sql) {
					if (strpos($sql, 'sbr_reviews_posts') === false) {
						continue;
					}
					if (strpos($sql, 'INSERT INTO') === false) {
						continue;
					}
					if (preg_match('/VALUES \(\'[^\']*\', \'([^\']*)\'/', $sql, $m) === 1) {
						$ids[] = $m[1];
					}
				}

				return $ids;
			}
		};
	}
}
