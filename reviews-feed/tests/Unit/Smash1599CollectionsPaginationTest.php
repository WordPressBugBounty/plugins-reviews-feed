<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Customizer\DB;

/**
 * SMASH-1599 — the Collections screen showed a subset of collections with no
 * way to reach the rest.
 *
 * A collection is a row in `sbr_sources` with `provider = 'collection'`, in the
 * same table as Google/Yelp/etc. The screen used to derive its list by taking
 * `get_sources_list()` — one page of SOURCES_PER_PAGE rows across EVERY
 * provider — and filtering it to `provider === 'collection'` in React. So a
 * site saw however many of its first 20 sources happened to be collections. The
 * reporting site has 20+ sources and 50+ collections and saw 15, which is that
 * arithmetic and not a limit anyone chose.
 *
 * These tests are about the SQL, because the SQL is where the bug is: a page of
 * collections has to be scoped to collections, ordered so its boundaries hold,
 * and counted against the same grouping the page uses.
 */
final class Smash1599CollectionsPaginationTest extends TestCase
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
	 * A wpdb double that records the SQL it is handed.
	 *
	 * `prepare()` substitutes for real rather than returning a marker: the
	 * assertions below are about the values that reach the query (the provider,
	 * the limit, the offset), and a double that swallowed them could not tell a
	 * correct offset from a wrong one.
	 */
	private function recording_wpdb(array $rows = [], $var = 0)
	{
		return new class ($rows, $var) {
			public $prefix = 'wp_';
			/** @var string[] */
			public $queries = [];
			private $rows;
			private $var;

			public function __construct($rows, $var)
			{
				$this->rows = $rows;
				$this->var  = $var;
			}

			/**
			 * Real wpdb has this (wp-db.php) and DB.php now calls it before the
			 * LIKE CONCAT bindings, so the double has to carry it or the test
			 * fails on the shim rather than on the code (SMASH-1033).
			 */
			public function esc_like($text)
			{
				return addcslashes((string) $text, '_%\\');
			}
			public function prepare($sql, ...$args)
			{
				if (isset($args[0]) && is_array($args[0])) {
					$args = $args[0];
				}
				foreach ($args as $arg) {
					$replacement = is_int($arg) ? (string) $arg : "'" . $arg . "'";
					$sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
				}
				return $sql;
			}

			public function get_results($sql, $output = null)
			{
				$this->queries[] = $sql;
				return $this->rows;
			}

			public function get_var($sql)
			{
				$this->queries[] = $sql;
				return $this->var;
			}
		};
	}

	/** The whole point: the page is a page OF COLLECTIONS. */
	public function test_the_page_query_is_scoped_to_the_collection_provider(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb();

		DB::get_collections_page(1);

		$this->assertNotEmpty($wpdb->queries);
		$this->assertStringContainsString(
			"WHERE s.provider = 'collection'",
			$wpdb->queries[0],
			'without this predicate the page is a page of ALL sources, which is the bug'
		);
	}

	/**
	 * AC3 — page boundaries are stable. LIMIT/OFFSET over an unordered result
	 * may skip or repeat a row between pages, and the customer would never know
	 * which collection went missing.
	 */
	public function test_the_page_query_is_ordered_so_boundaries_are_stable(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb();

		DB::get_collections_page(1);

		$this->assertMatchesRegularExpression(
			'/ORDER BY\s+s\.id\s+ASC/i',
			$wpdb->queries[0],
			'no ORDER BY means LIMIT/OFFSET pages are not reproducible'
		);
	}

	/** @dataProvider page_offsets */
	public function test_the_offset_follows_the_page($page, int $expected_offset): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb();

		DB::get_collections_page($page);

		$this->assertStringContainsString(
			'LIMIT ' . DB::COLLECTIONS_PER_PAGE . ' OFFSET ' . $expected_offset,
			$wpdb->queries[0]
		);
	}

	/**
	 * Page 0 and negatives are floored to page 1 rather than producing a
	 * negative OFFSET, which is a SQL error on MySQL — a hand-edited URL or a
	 * pager bug would take the screen down rather than show page one.
	 */
	public static function page_offsets(): array
	{
		$per = DB::COLLECTIONS_PER_PAGE;

		return [
			'first page'        => [1, 0],
			'second page'       => [2, $per],
			'third page'        => [3, $per * 2],
			'page zero floors'  => [0, 0],
			'negative floors'   => [-5, 0],
			'numeric string'    => ['2', $per],
		];
	}

	/**
	 * The count has to be grouped the way the page is. The page does
	 * GROUP BY account_id, so a plain COUNT(*) over duplicate account_ids would
	 * claim more collections than any page can show — the last page renders
	 * short while the pager still offers a next one.
	 */
	public function test_the_count_is_grouped_the_way_the_page_is(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb([], 57);

		$count = DB::get_collections_count();

		$this->assertSame(57, $count);
		$this->assertStringContainsString('count(DISTINCT account_id)', $wpdb->queries[0]);
		$this->assertStringContainsString("provider = 'collection'", $wpdb->queries[0]);
	}

	/** `get_var` answers a numeric string; the pager divides by this. */
	public function test_the_count_is_an_integer_not_a_string(): void
	{
		$GLOBALS['wpdb'] = $this->recording_wpdb([], '57');

		$this->assertSame(57, DB::get_collections_count());
	}

	/** An empty table must not reach the per-row instance loop. */
	public function test_no_collections_returns_an_empty_array(): void
	{
		$GLOBALS['wpdb'] = $this->recording_wpdb([]);

		$this->assertSame([], DB::get_collections_page(1));
	}

	/**
	 * The delete dialog names the feeds a collection is used in, so a row with
	 * `used_in > 0` has to carry `instances`. A row used nowhere must NOT pay
	 * for a second query.
	 */
	public function test_instances_are_fetched_only_for_collections_in_use(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb([
			['id' => 1, 'account_id' => 'coll_a', 'used_in' => 0],
			['id' => 2, 'account_id' => 'coll_b', 'used_in' => 2],
		]);

		$results = DB::get_collections_page(1);

		$this->assertArrayNotHasKey('instances', $results[0]);
		$this->assertArrayHasKey('instances', $results[1]);
		$this->assertCount(2, $wpdb->queries, 'one page query plus one instance query, not one per row');
	}

	/**
	 * BC (Rule 6). get_collections_list() answers "every collection" and the
	 * Settings-screen export depends on exactly that. Adding pagination beside
	 * it must not have put a LIMIT on it.
	 */
	public function test_the_unpaged_list_still_returns_every_collection(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->recording_wpdb([['id' => 1]]);

		DB::get_collections_list();

		$this->assertStringNotContainsStringIgnoringCase(
			'LIMIT',
			$wpdb->queries[0],
			'the export caller reads this and needs all of them'
		);
	}
}
