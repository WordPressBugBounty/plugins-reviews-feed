<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Customizer\DB;

/**
 * SMASH-1599 — the route the pager actually takes.
 *
 * The DB tests next door prove the query is right. They cannot prove anyone
 * calls it: the bug being fixed was precisely a screen that had a correct list
 * available and reached for the wrong one. These tests walk the AJAX action the
 * Pagination control fires, so a change that leaves the query perfect and stops
 * routing through it goes red.
 */
final class Smash1599CollectionsAjaxRouteTest extends TestCase
{
	/** @var object|null */
	private $original_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST = [];
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset($GLOBALS['sbr_test_nonce_ok'], $GLOBALS['sbr_test_user_can']);
		$_POST = [];
		parent::tearDown();
	}

	/** A wpdb double that answers a fixed number of collection rows. */
	private function wpdb_with(int $rows, int $total)
	{
		return new class ($rows, $total) {
			public $prefix = 'wp_';
			public $queries = [];
			private $rows;
			private $total;

			public function __construct($rows, $total)
			{
				$this->rows  = $rows;
				$this->total = $total;
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
				$out = [];
				for ($i = 0; $i < $this->rows; $i++) {
					$out[] = ['id' => $i + 1, 'account_id' => 'coll_' . $i, 'used_in' => 0];
				}
				return $out;
			}

			public function get_var($sql)
			{
				$this->queries[] = $sql;
				return $this->total;
			}
		};
	}

	/**
	 * Run the handler and return the decoded JSON it echoed.
	 *
	 * wp_die() is shimmed to throw SBR_Test_Halt, so the echo has to be caught
	 * out of the buffer rather than read from a return value.
	 */
	private function call_handler(): array
	{
		ob_start();
		try {
			SBR_Feed_Saver_Manager::load_more_collections();
		} catch (\SBR_Test_Halt $halt) {
			// wp_die() — the handler's normal end.
		} finally {
			$echoed = ob_get_clean();
		}

		$decoded = json_decode((string) $echoed, true);
		$this->assertIsArray($decoded, 'the handler did not echo a JSON object');

		return $decoded;
	}

	public function test_the_route_answers_the_keys_the_pager_reads(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(20, 53);
		$_POST['collections_page'] = 1;

		$payload = $this->call_handler();

		foreach (['collectionsList', 'collectionsCount', 'page', 'perPage', 'totalPages'] as $key) {
			$this->assertArrayHasKey($key, $payload);
		}
		$this->assertCount(20, $payload['collectionsList']);
		$this->assertSame(53, $payload['collectionsCount']);
		$this->assertSame(DB::COLLECTIONS_PER_PAGE, $payload['perPage']);
	}

	/**
	 * The route has to go through the PAGED query, not merely return collections.
	 *
	 * Without this the handler could call get_collections_list() — every
	 * collection, unpaged — and every other assertion here would still pass,
	 * because the double answers any query with the same rows. That is the bug
	 * one layer up: a correct query exists and the caller reaches past it.
	 */
	public function test_the_route_issues_the_paged_query_not_the_unpaged_one(): void
	{
		$GLOBALS['wpdb'] = $wpdb = $this->wpdb_with(20, 53);
		$_POST['collections_page'] = 2;

		$this->call_handler();

		$this->assertNotEmpty($wpdb->queries);
		$paged = $wpdb->queries[0];
		$this->assertStringContainsString('LIMIT ' . DB::COLLECTIONS_PER_PAGE, $paged);
		$this->assertStringContainsString('OFFSET ' . DB::COLLECTIONS_PER_PAGE, $paged, 'page 2 must skip the first page');
		$this->assertStringContainsString('ORDER BY', $paged);
	}

	/** 53 collections at 20 a page is 3 pages — the customer's shape. */
	public function test_the_page_count_covers_every_collection(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(13, 53);
		$_POST['collections_page'] = 3;

		$payload = $this->call_handler();

		$this->assertSame(3, $payload['totalPages']);
		$this->assertSame(3, $payload['page']);
	}

	/**
	 * An empty site must report ONE page, not zero. A zero divides through the
	 * front end's clamp and the pager renders against nothing.
	 */
	public function test_an_empty_site_still_reports_one_page(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(0, 0);

		$payload = $this->call_handler();

		$this->assertSame(1, $payload['totalPages']);
		$this->assertSame([], $payload['collectionsList']);
	}

	/**
	 * The echoed `page` is what highlights the active pager button, so it has to
	 * be the page actually served, not the one asked for.
	 */
	public function test_a_page_below_one_is_reported_as_page_one(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(20, 53);
		$_POST['collections_page'] = 0;

		$payload = $this->call_handler();

		$this->assertSame(1, $payload['page']);
	}

	/** A missing page argument is page one, not page zero. */
	public function test_a_missing_page_argument_serves_the_first_page(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(20, 53);

		$payload = $this->call_handler();

		$this->assertSame(1, $payload['page']);
	}

	/**
	 * The nonce twin of the capability test — asserted on the CALL.
	 *
	 * In production `check_ajax_referer()` dies by itself (third arg defaults
	 * true), so the handler ignores its return. The bootstrap shim returns a
	 * boolean instead of dying, which means a bad nonce cannot be observed in
	 * the handler's OUTPUT here: it carries on and answers normally. What is
	 * observable is that the guard ran, and against which action — the shim
	 * records that, and a route verifying the wrong action would be a real bug
	 * (the whole saver family shares `sbr-admin`).
	 */
	public function test_the_route_verifies_the_same_nonce_action_as_its_siblings(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(20, 53);
		$GLOBALS['sbr_test_nonce_actions_checked'] = [];

		$this->call_handler();

		$this->assertContains('sbr-admin', $GLOBALS['sbr_test_nonce_actions_checked']);
	}

	/** The route is capability-gated like every other saver action. */
	public function test_a_user_without_the_capability_is_refused(): void
	{
		$GLOBALS['wpdb'] = $this->wpdb_with(20, 53);
		$GLOBALS['sbr_test_user_can'] = false;

		$this->expectException(\SBR_Test_Halt::class);
		SBR_Feed_Saver_Manager::load_more_collections();
	}
}
