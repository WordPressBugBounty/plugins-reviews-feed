<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_External_Reviews_Update;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_Reviews_Update;

/**
 * SMASH-2525 — Delete Source must forget the source's bulk-history state.
 *
 * Delete Source wiped the stored reviews but left sbr_bulk_sources[<id>] at
 * is_done = true, so the add-source schedule_task() on a re-add ran into
 * should_make_call() === false and the history was never fetched again.
 *
 * @group bulk-history
 * @group smash-2525
 */
class Smash2525ForgetBulkOnDeleteTest extends TestCase
{
	private const ID = 'ChIJ_2525';

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_cleared_hooks_mock;
		$wp_options_mock = [];
		$wp_cleared_hooks_mock = [];
	}

	private function seedBulk(array $state): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = $state;
	}

	private function bulkSources(): array
	{
		global $wp_options_mock;
		return $wp_options_mock['sbr_bulk_sources'] ?? [];
	}

	private function doneEntry(string $id, string $provider = 'google'): array
	{
		return ['account_id' => $id, 'provider' => $provider, 'retry' => false, 'is_done' => true, 'page' => 3, 'last_total' => 233];
	}

	private function bulkTaskFor(string $id): Bulk_Reviews_Update
	{
		$task = new Bulk_Reviews_Update();
		$task->account_id = $id;
		$task->account_provider = 'google';

		return $task;
	}

	public function test_a_done_source_blocks_the_bulk_task_before_the_fix(): void
	{
		// The customer's state after delete + re-add: the reason nothing was fetched.
		$this->seedBulk([self::ID => $this->doneEntry(self::ID)]);

		$this->assertFalse($this->bulkTaskFor(self::ID)->should_make_call());
	}

	public function test_forgetting_a_source_lets_the_next_bulk_task_run_from_page_one(): void
	{
		$this->seedBulk([self::ID => $this->doneEntry(self::ID)]);

		Bulk_Reviews_Update::forget_source(self::ID, 'google');

		$this->assertArrayNotHasKey(self::ID, $this->bulkSources());
		$task = $this->bulkTaskFor(self::ID);
		$this->assertTrue($task->should_make_call(), 'A re-added source imports its history again.');
		$this->assertSame(1, $task->account_info['page']);
		$this->assertFalse($task->account_info['is_done']);
	}

	public function test_forgetting_a_source_leaves_every_other_source_alone(): void
	{
		$other = 'ChIJ_OTHER';
		$this->seedBulk([
			self::ID => $this->doneEntry(self::ID),
			$other => $this->doneEntry($other, 'yelp'),
		]);

		Bulk_Reviews_Update::forget_source(self::ID, 'google');

		$this->assertSame([$other => $this->doneEntry($other, 'yelp')], $this->bulkSources());
	}

	public function test_forgetting_clears_only_this_sources_pending_bulk_event(): void
	{
		Bulk_Reviews_Update::forget_source(self::ID, 'google');

		global $wp_cleared_hooks_mock;
		// Same args, same key order as schedule_task() callers, or WP matches nothing.
		$this->assertSame(
			[['hook' => 'sbr_reviews_bulk_cron', 'args' => [['source_account_id' => self::ID, 'source_provider' => 'google']]]],
			$wp_cleared_hooks_mock
		);
	}

	public function test_forgetting_an_unknown_source_writes_nothing(): void
	{
		global $wp_options_mock;
		Bulk_Reviews_Update::forget_source(self::ID, 'google');

		$this->assertArrayNotHasKey('sbr_bulk_sources', $wp_options_mock);
	}

	public function test_an_empty_account_id_is_a_no_op(): void
	{
		$this->seedBulk([self::ID => $this->doneEntry(self::ID)]);

		Bulk_Reviews_Update::forget_source('', 'google');

		global $wp_cleared_hooks_mock;
		$this->assertArrayHasKey(self::ID, $this->bulkSources());
		$this->assertSame([], $wp_cleared_hooks_mock, 'No args means every bulk event, so never clear on an empty id.');
	}

	public function test_forgetting_an_external_source_lets_the_add_path_schedule_its_history_again(): void
	{
		global $wp_options_mock, $wp_cleared_hooks_mock;
		$id = '1005011811621235';
		$wp_options_mock['sbr_bulk_external'] = [
			'aliexpress_' . $id => ['source_id' => $id, 'provider' => 'aliexpress', 'page' => 3, 'is_done' => true],
			'airbnb_42' => ['source_id' => '42', 'provider' => 'airbnb', 'page' => 2, 'is_done' => true],
		];
		$this->assertFalse((new Bulk_External_Reviews_Update())->check_source_needs_update($id, 'aliexpress'));

		Bulk_External_Reviews_Update::forget_source($id, 'aliexpress');

		$this->assertTrue((new Bulk_External_Reviews_Update())->check_source_needs_update($id, 'aliexpress'));
		$this->assertArrayHasKey('airbnb_42', $wp_options_mock['sbr_bulk_external'], 'Other sources keep their state.');
		$this->assertSame(
			[['hook' => 'sbr_external_reviews_bulk_cron', 'args' => [['source_id' => $id, 'provider' => 'aliexpress']]]],
			$wp_cleared_hooks_mock
		);
	}

	public function test_forgetting_an_external_source_with_an_empty_id_is_a_no_op(): void
	{
		global $wp_cleared_hooks_mock;

		Bulk_External_Reviews_Update::forget_source('', 'aliexpress');

		$this->assertSame([], $wp_cleared_hooks_mock);
	}

	public function test_delete_source_forgets_the_external_bulk_state_too(): void
	{
		$file = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		$body = \SbrPhpSource::squeeze(\SbrPhpSource::method_body((string) file_get_contents($file), 'delete_source'));

		$gate = strpos($body, 'self::relay_source_removed($relay_response)');
		$forget = strpos($body, 'Bulk_External_Reviews_Update::forget_source($source_account_id,$provider)');

		$this->assertNotFalse($forget, 'Booking / Airbnb / AliExpress keep their history state in sbr_bulk_external.');
		$this->assertGreaterThan($gate, $forget);
	}

	public function test_delete_source_forgets_the_bulk_state_after_the_relay_remove_succeeds(): void
	{
		// delete_source() exits through wp_die(); no runtime seam yet (SMASH-2074), so pin the order.
		$file = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		$body = \SbrPhpSource::squeeze(\SbrPhpSource::method_body((string) file_get_contents($file), 'delete_source'));

		$gate = strpos($body, 'self::relay_source_removed($relay_response)');
		$forget = strpos($body, 'Bulk_Reviews_Update::forget_source($source_account_id,$provider)');
		$collection = strpos($body, "isset(\$_POST['isCollection'])");

		$this->assertNotFalse($forget, 'Delete Source must forget the bulk-history state.');
		$this->assertNotFalse($gate);
		$this->assertGreaterThan($gate, $forget, 'Only once the relay remove succeeded — a failed delete keeps the source.');
		$this->assertGreaterThan($collection, $forget);
		$this->assertSame(1, substr_count($body, 'Bulk_Reviews_Update::forget_source('), 'Collections have no bulk history; only the relay branch forgets.');
	}
}
