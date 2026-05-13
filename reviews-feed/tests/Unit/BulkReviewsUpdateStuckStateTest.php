<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_Reviews_Update;

/**
 * Pins the bulk-history retry state machine and the new "give up cleanly"
 * branch that closes the customer-facing "Unable to retrieve reviews history"
 * stuck state.
 *
 * Pre-fix, `Bulk_Reviews_Update::get_bulk_reviews()` had only two branches —
 * happy-path (reviews returned) and first-failure (retry === false). When the
 * relay returned empty reviews on a SECOND consecutive call (retry already
 * true), neither branch fired and the option froze at
 * `{retry: true, is_done: false, page: 1}` indefinitely. No UI button or
 * license action in v2.5.4 / v2.5.5 ever cleared that state. The customer
 * saw a permanent "Unable to retrieve reviews history" warning.
 *
 * This test asserts the missing third branch now writes `is_done: true`,
 * which both ends the bulk loop AND collapses the customizer warning trigger
 * (`retry === true && is_done === false` → false once is_done flips).
 *
 * @group bulk-history
 * @group customer-bug-2026-05-08
 */
class BulkReviewsUpdateStuckStateTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Reset the test option store between tests. Cron-API stubs
		// (wp_schedule_single_event, wp_next_scheduled, wp_clear_scheduled_hook)
		// live in tests/bootstrap.php in the global namespace so the bulk-
		// update class can find them via PHP's namespace-fallback resolution.
		global $wp_options_mock;
		$wp_options_mock = [];
	}

	/**
	 * Regression pin for the test-harness wiring: the namespaced bulk-update
	 * class must be able to resolve `wp_schedule_single_event` via PHP's
	 * fallback lookup. Pre-fix the stub was eval'd inside the test namespace,
	 * which Bulk_Reviews_Update couldn't see — first-failure tests would
	 * fatal in environments without WordPress loaded.
	 */
	public function test_cron_stubs_visible_to_bulk_update_namespace(): void
	{
		// function_exists with unqualified name checks the global namespace
		// specifically — exactly the path PHP's fallback resolution uses.
		$this->assertTrue(function_exists('wp_schedule_single_event'));
		$this->assertTrue(function_exists('wp_next_scheduled'));
		$this->assertTrue(function_exists('wp_clear_scheduled_hook'));
	}

	/**
	 * THE FIX: retry already true + empty reviews response → is_done flips
	 * to true so the source no longer shows the warning indefinitely.
	 */
	public function test_second_consecutive_empty_response_marks_is_done(): void
	{
		$account_id = 'CHIJ_TEST_STUCK';

		// Pre-populate the stuck state: retry already true, page still 1.
		// This is the exact shape the customer's site was frozen in.
		$this->seed_bulk_sources([
			$account_id => [
				'account_id' => $account_id,
				'provider'   => 'google',
				'retry'      => true,
				'is_done'    => false,
				'page'       => 1,
			],
		]);

		$bulk = $this->makeBulkInstance($account_id, 'google');
		$bulk->relay = $this->stubRelayWithEmptyReviews();
		$bulk->endpoint = 'reviews/google';
		$bulk->settings = [];
		$bulk->provider = ['info' => '{"id":"' . $account_id . '"}'];

		// Run the same code path the cron tick fires.
		$bulk->get_bulk_reviews();

		$state = $this->getBulkSourcesState($account_id);
		$this->assertTrue(
			$state['is_done'],
			'After second consecutive empty response, is_done MUST be true so the warning clears.'
		);
		$this->assertTrue(
			$state['retry'],
			'Retry flag stays true (preserves audit trail); the loop terminator is is_done.'
		);
	}

	/**
	 * Regression pin: first-failure path STILL sets retry=true and reschedules.
	 * Pre-fix behavior must remain unchanged — only the new third branch was
	 * added.
	 */
	public function test_first_empty_response_sets_retry_true(): void
	{
		$account_id = 'CHIJ_FRESH_FAIL';

		$this->seed_bulk_sources([
			$account_id => [
				'account_id' => $account_id,
				'provider'   => 'google',
				'retry'      => false,
				'is_done'    => false,
				'page'       => 1,
			],
		]);

		$bulk = $this->makeBulkInstance($account_id, 'google');
		$bulk->relay = $this->stubRelayWithEmptyReviews();
		$bulk->endpoint = 'reviews/google';
		$bulk->settings = [];
		$bulk->provider = ['info' => '{"id":"' . $account_id . '"}'];

		$bulk->get_bulk_reviews();

		$state = $this->getBulkSourcesState($account_id);
		$this->assertTrue($state['retry'], 'First empty response must flip retry → true.');
		$this->assertFalse($state['is_done'], 'First empty response must NOT set is_done — only retry.');
	}

	/**
	 * Pin the should_make_call gate so a fresh-state account_info gets seeded
	 * and the get_bulk_reviews loop can run. Defensive check: ensures
	 * subsequent fixes don't accidentally short-circuit on a stuck state.
	 */
	public function test_should_make_call_returns_true_for_stuck_state(): void
	{
		$account_id = 'CHIJ_STUCK_ENTRY';

		$this->seed_bulk_sources([
			$account_id => [
				'account_id' => $account_id,
				'provider'   => 'yelp',
				'retry'      => true,
				'is_done'    => false,
				'page'       => 1,
			],
		]);

		$bulk = $this->makeBulkInstance($account_id, 'yelp');

		$this->assertTrue(
			$bulk->should_make_call(),
			'Stuck-state entries must allow another call so the new branch can flip is_done.'
		);
	}

	/**
	 * Pin the should_make_call gate when is_done is already true: subsequent
	 * cron ticks must short-circuit. Mirrors the existing contract — added
	 * here because the new fix RELIES on this short-circuit holding (after
	 * the new branch flips is_done, no further bulk calls fire).
	 */
	public function test_should_make_call_returns_false_when_is_done_true(): void
	{
		$account_id = 'CHIJ_ALREADY_DONE';

		$this->seed_bulk_sources([
			$account_id => [
				'account_id' => $account_id,
				'provider'   => 'google',
				'retry'      => false,
				'is_done'    => true,
				'page'       => 3,
			],
		]);

		$bulk = $this->makeBulkInstance($account_id, 'google');

		$this->assertFalse(
			$bulk->should_make_call(),
			'is_done=true must short-circuit further bulk-history calls.'
		);
	}

	/* --- helpers --- */

	private function seed_bulk_sources(array $state): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = $state;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getBulkSourcesState(string $account_id): array
	{
		global $wp_options_mock;
		return $wp_options_mock['sbr_bulk_sources'][$account_id] ?? [];
	}

	private function makeBulkInstance(string $account_id, string $provider): Bulk_Reviews_Update
	{
		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = $account_id;
		$bulk->account_provider = $provider;
		// Seed account_info so should_make_call's lookup matches what's in the option.
		$bulk->should_make_call();
		return $bulk;
	}

	/**
	 * Stub returning a relay-shaped envelope with empty reviews.
	 *
	 * @return mixed Anonymous-class instance — wider than \stdClass to keep
	 *               the phpcs PHP-7.1 floor happy without losing the duck.
	 */
	private function stubRelayWithEmptyReviews()
	{
		return new class {
			public function call($endpoint, $args, $method, $auth)
			{
				return ['data' => ['reviews' => []]];
			}
		};
	}

}
