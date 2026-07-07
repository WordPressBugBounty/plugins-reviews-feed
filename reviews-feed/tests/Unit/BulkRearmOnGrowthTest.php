<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_Reviews_Update;

/**
 * SMASH-1634 — change-driven re-arm of the one-shot paginated backfill.
 *
 * The bug: after a source's bulk history completes (`is_done`), it never re-runs,
 * so review batches larger than the hourly incremental cap (Google/Yelp keyed
 * API returns only the 5 newest bodies) never load without a manual "reset bulk
 * history". `maybe_rearm_source()` re-opens ONE source's backfill when its
 * upstream review count grows.
 *
 * These pin the exact contract, including the cost guards (seed-on-first-sight,
 * key/provider gating) so the fix can't silently start a mass re-backfill.
 *
 * @group bulk-history
 * @group smash-1634
 */
class BulkRearmOnGrowthTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$wp_options_mock = [];
	}

	/** Keyed google/yelp needs an API key for the bulk (RapidAPI) path. */
	private function withApiKey(string $provider = 'google'): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_apikeys'] = [$provider => 'TEST_KEY'];
	}

	private function seedBulk(array $state): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = $state;
	}

	private function bulkState(string $account_id): array
	{
		global $wp_options_mock;
		return $wp_options_mock['sbr_bulk_sources'][$account_id] ?? [];
	}

	/**
	 * First time we observe a completed source, we only SEED the baseline —
	 * we must NOT re-arm. This is the cost guard that stops deploying the fix
	 * from re-backfilling every existing source at once.
	 */
	public function test_first_observation_seeds_baseline_without_rearm(): void
	{
		$this->withApiKey('google');
		$id = 'ChIJ_SEED';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => true, 'page' => 3]]);

		$rearmed = Bulk_Reviews_Update::maybe_rearm_source('google', $id, 233);

		$this->assertFalse($rearmed, 'First sight of a done source must not re-arm.');
		$state = $this->bulkState($id);
		$this->assertTrue($state['is_done'], 'is_done must stay true on the seed pass.');
		$this->assertSame(233, $state['last_total'], 'Baseline last_total must be seeded.');
	}

	/** No growth since the last backfill → no re-arm. */
	public function test_no_growth_does_not_rearm(): void
	{
		$this->withApiKey('google');
		$id = 'ChIJ_FLAT';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => true, 'page' => 3, 'last_total' => 233]]);

		$this->assertFalse(Bulk_Reviews_Update::maybe_rearm_source('google', $id, 233));
		$this->assertTrue($this->bulkState($id)['is_done'], 'Equal count must leave is_done untouched.');
	}

	/** THE FIX: upstream count grew → re-open the backfill for that source. */
	public function test_growth_rearms_source(): void
	{
		$this->withApiKey('google');
		$id = 'ChIJ_GROW';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => true, 'page' => 3, 'last_total' => 233]]);

		$rearmed = Bulk_Reviews_Update::maybe_rearm_source('google', $id, 246);

		$this->assertTrue($rearmed, 'Count growth must re-arm the source.');
		$state = $this->bulkState($id);
		$this->assertFalse($state['is_done'], 'Re-arm must clear is_done so the backfill re-runs.');
		$this->assertSame(1, $state['page'], 'Re-arm must reset pagination to page 1 (matches the proven 1-indexed fresh init).');
		$this->assertSame(246, $state['last_total'], 'Re-arm must record the new baseline (prevents re-loop).');
	}

	/** BC: a source still mid-backfill (is_done false) is left to the normal flow. */
	public function test_not_done_source_is_untouched(): void
	{
		$this->withApiKey('google');
		$id = 'ChIJ_RUNNING';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => false, 'page' => 1]]);

		$this->assertFalse(Bulk_Reviews_Update::maybe_rearm_source('google', $id, 999));
		$state = $this->bulkState($id);
		$this->assertFalse($state['is_done']);
		$this->assertArrayNotHasKey('last_total', $state, 'Running source must not be mutated.');
	}

	/** Cost guard: keyless (no API key) is not handled by this bulk service → never re-arm. */
	public function test_no_api_key_never_rearms(): void
	{
		// no withApiKey()
		$id = 'ChIJ_KEYLESS';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => true, 'page' => 3, 'last_total' => 100]]);

		$this->assertFalse(Bulk_Reviews_Update::maybe_rearm_source('google', $id, 500));
		$this->assertTrue($this->bulkState($id)['is_done'], 'Keyless source must be untouched.');
	}

	/** Guard: providers this bulk service doesn't own are ignored. */
	public function test_non_google_yelp_provider_ignored(): void
	{
		$this->withApiKey('google');
		$id = 'qa_booking';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'booking', 'is_done' => true, 'page' => 3, 'last_total' => 10]]);

		$this->assertFalse(Bulk_Reviews_Update::maybe_rearm_source('booking', $id, 50));
	}

	/** Guard: a zero/unknown current count is a no-op (don't act on missing data). */
	public function test_zero_current_total_is_noop(): void
	{
		$this->withApiKey('google');
		$id = 'ChIJ_ZERO';
		$this->seedBulk([$id => ['account_id' => $id, 'provider' => 'google', 'is_done' => true, 'page' => 3, 'last_total' => 5]]);

		$this->assertFalse(Bulk_Reviews_Update::maybe_rearm_source('google', $id, 0));
	}
}
