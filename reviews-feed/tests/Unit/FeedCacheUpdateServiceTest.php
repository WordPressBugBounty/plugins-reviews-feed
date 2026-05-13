<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/FeedCacheWpdbDouble.php';

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\FeedCacheUpdateService;
use SmashBalloon\Reviews\Tests\Unit\Doubles\FeedCacheWpdbDouble;

/**
 * Regression coverage for the cron feed-cache batch query.
 *
 * Pins the dedupe + oldest-first behavior introduced for SMASH-1360
 * (cron Google Places API cost overhead). The same `feed_caches_query()`
 * was previously a `SELECT * ... ORDER BY last_updated ASC LIMIT N` which
 * returned one row per `cache_type` per feed — so a feed with both `posts`
 * and `header` rows ≥12h-stale contributed two rows to the batch and got
 * fully re-fetched twice in a single cron tick. The fix collapses the
 * batch to one row per `feed_id` so the same `LIMIT N` budget now yields
 * N unique feeds (instead of as few as N/2 when both rows were stale).
 *
 * The query is exercised through a `wpdb` test double that captures the
 * prepared SQL + bound args without requiring a live database.
 */
class FeedCacheUpdateServiceTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wpdb;
		$wpdb = new FeedCacheWpdbDouble();
	}

	/** GROUP BY feed_id is present and on `feed_id` (not `cache_type`). */
	public function test_query_groups_by_feed_id(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;
		$sql = $wpdb->last_prepared_sql;

		$this->assertStringContainsString('GROUP BY feed_id', $sql);
		$this->assertStringNotContainsString('GROUP BY cache_type', $sql);
	}

	/** Oldest-stale-first prioritization is preserved through the GROUP BY. */
	public function test_query_orders_by_last_updated_ascending(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;
		$sql = $wpdb->last_prepared_sql;

		$this->assertStringContainsString('ORDER BY last_updated ASC', $sql);
	}

	/**
	 * MIN(id) / MIN(last_updated) keep the query strict-mode safe under
	 * `ONLY_FULL_GROUP_BY` (every non-aggregate column must appear in
	 * GROUP BY or be wrapped in an aggregate function).
	 */
	public function test_query_uses_aggregates_for_strict_sql_mode_safety(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;
		$sql = $wpdb->last_prepared_sql;

		$this->assertStringContainsString('MIN(id)', $sql);
		$this->assertStringContainsString('MIN(last_updated)', $sql);
	}

	/** SELECT * is gone — the previous shape is what produced the duplicates. */
	public function test_query_no_longer_uses_select_star(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;
		$sql = $wpdb->last_prepared_sql;

		$this->assertStringNotContainsString('SELECT *', $sql);
	}

	/** 12h staleness gate (`last_updated < NOW - 12h`) is still bound. */
	public function test_query_includes_twelve_hour_staleness_filter(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;

		$this->assertStringContainsString('last_updated < %s', $wpdb->last_prepared_sql);
		// First bound arg is the 12h-ago timestamp string (gmdate format).
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			(string) $wpdb->last_prepared_args[0]
		);
	}

	/** LIMIT is bound to RESULTS_PER_CRON_UPDATE — not a hard-coded literal. */
	public function test_query_limit_is_bound_to_results_per_cron_update(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;

		$this->assertStringContainsString('LIMIT %d', $wpdb->last_prepared_sql);
		$this->assertSame(
			FeedCacheUpdateService::RESULTS_PER_CRON_UPDATE,
			$wpdb->last_prepared_args[1]
		);
	}

	/** `cron_update = 'yes'` filter is preserved (already passing pre-fix, regression-pin). */
	public function test_query_filters_to_cron_update_yes_rows(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query(['cron_update' => true]);

		global $wpdb;

		$this->assertStringContainsString("cron_update = 'yes'", $wpdb->last_prepared_sql);
	}

	/**
	 * End-to-end behavior: when wpdb returns the post-GROUP-BY shape (one
	 * row per feed_id with MIN-aliased columns), the service hands the
	 * same array back unchanged. FeedCacheUpdater::do_updates only reads
	 * `feed_id` from each row, so the alias projection is invisible to it.
	 */
	public function test_query_returns_aggregated_rows_unchanged(): void
	{
		global $wpdb;
		$wpdb->next_results = [
			['id' => 17, 'feed_id' => 4, 'last_updated' => '2026-05-04 02:00:00'],
			['id' => 23, 'feed_id' => 7, 'last_updated' => '2026-05-04 03:30:00'],
		];

		$service = new FeedCacheUpdateService();
		$rows    = $service->feed_caches_query(['cron_update' => true]);

		$this->assertCount(2, $rows);
		$this->assertSame(4, $rows[0]['feed_id']);
		$this->assertSame(7, $rows[1]['feed_id']);
	}

	/** When `cron_update` arg is omitted the bare `SELECT *` is still issued (legacy shape). */
	public function test_query_without_cron_update_arg_keeps_select_star_legacy_shape(): void
	{
		$service = new FeedCacheUpdateService();
		$service->feed_caches_query([]);

		global $wpdb;

		// Legacy shape (no `cron_update` arg) does NOT go through prepare —
		// the test double records the raw SQL on `last_get_results_sql`.
		$this->assertStringContainsString('SELECT * FROM', $wpdb->last_get_results_sql);
	}
}
