<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_External_Reviews_Update;

/**
 * SMASH-782 — recurring "grow the feed" refresh for external providers.
 *
 * External providers (Airbnb, Booking, AliExpress) store reviews in the DB via a
 * ONE-SHOT bulk cron and were then frozen until a manual Clear Cache — unlike keyed
 * providers, which pick up new reviews through the hourly sbr_feed_update ->
 * Feed::get_set_cache() API re-fetch. This recurring event periodically re-arms the
 * bulk fetch so new reviews keep flowing in.
 *
 * These pin the scheduling contract (weekly default, idempotency, filterable
 * cadence, safe fallback) and that the handler re-arms sources without deleting
 * reviews.
 *
 * @group cron
 * @group smash-782
 */
class Smash782ExternalRefreshCronTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_scheduled_events_mock, $wp_filter_mock;
		$wp_options_mock = [];
		$wp_scheduled_events_mock = [];
		$wp_filter_mock = [];
	}

	private function scheduled(): ?array
	{
		global $wp_scheduled_events_mock;
		return $wp_scheduled_events_mock[Bulk_External_Reviews_Update::REFRESH_CRON_NAME] ?? null;
	}

	private function setTier(int $tier): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_statuses'] = ['license_tier' => $tier];
	}

	// --- scheduling ---------------------------------------------------------------

	public function test_default_cadence_is_daily_below_elite(): void
	{
		// Manage Caching UI promises "daily" for non-Elite tiers.
		$this->setTier(2);
		$this->assertFalse(wp_next_scheduled(Bulk_External_Reviews_Update::REFRESH_CRON_NAME));

		Bulk_External_Reviews_Update::schedule_refresh_cron();

		$event = $this->scheduled();
		$this->assertIsArray($event, 'A recurring event must be scheduled.');
		$this->assertSame('daily', $event['recurrence']);
		// First run staggered ~1 hour out so it never piles onto the add-source bulk.
		$this->assertGreaterThanOrEqual(time() + HOUR_IN_SECONDS - 5, $event['timestamp']);
		$this->assertLessThanOrEqual(time() + HOUR_IN_SECONDS + 5, $event['timestamp']);
	}

	public function test_default_cadence_is_twicedaily_on_elite(): void
	{
		// Manage Caching UI promises "twice daily for external sources" on Elite (tier 3).
		$this->setTier(3);

		Bulk_External_Reviews_Update::schedule_refresh_cron();

		$this->assertSame('twicedaily', $this->scheduled()['recurrence']);
	}

	public function test_scheduling_is_idempotent(): void
	{
		Bulk_External_Reviews_Update::schedule_refresh_cron();
		$first = $this->scheduled();

		// A second admin_init hit must NOT enqueue a duplicate / reschedule.
		Bulk_External_Reviews_Update::schedule_refresh_cron();
		$second = $this->scheduled();

		$this->assertSame($first['timestamp'], $second['timestamp']);
	}

	public function test_recurrence_is_filterable(): void
	{
		global $wp_filter_mock;
		// Override to a value distinct from either tier default to prove the filter wins.
		$wp_filter_mock['sbr_external_reviews_refresh_recurrence'] = 'weekly';

		Bulk_External_Reviews_Update::schedule_refresh_cron();

		$this->assertSame('weekly', $this->scheduled()['recurrence']);
	}

	public function test_invalid_filter_value_falls_back_to_tier_default(): void
	{
		global $wp_filter_mock;
		// Tier default (non-Elite) is 'daily'; a misconfigured filter returning an
		// empty / non-string value must not schedule a garbage recurrence.
		$this->setTier(0);
		$wp_filter_mock['sbr_external_reviews_refresh_recurrence'] = '';

		Bulk_External_Reviews_Update::schedule_refresh_cron();

		$this->assertSame('daily', $this->scheduled()['recurrence']);
	}

	// --- handler re-arms sources --------------------------------------------------

	public function test_periodic_refresh_rearms_completed_sources(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_external'] = [
			'booking_15321869' => [
				'source_id'       => '15321869',
				'provider'        => 'booking',
				'page'            => 3,
				'is_done'         => true,
				'retry'           => true,
				'reviews_fetched' => 50,
			],
		];

		(new Bulk_External_Reviews_Update())->run_periodic_refresh();

		$state = $wp_options_mock['sbr_bulk_external']['booking_15321869'];
		$this->assertFalse($state['is_done'], 'Refresh must re-open the completed source.');
		$this->assertSame(1, $state['page'], 'Refresh must re-fetch from the first page.');
		$this->assertFalse($state['retry'], 'Retry flag must be cleared on re-arm.');
		// Re-arm must never wipe the already-fetched tally (reviews are not deleted).
		$this->assertSame(50, $state['reviews_fetched']);
	}
}
