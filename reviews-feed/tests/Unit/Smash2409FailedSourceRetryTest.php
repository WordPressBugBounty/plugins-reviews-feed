<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/FailedSourceRetryWpdbDouble.php';
require_once __DIR__ . '/Doubles/FeedCacheWpdbDouble.php';

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\FailedSourceRetry;
use SmashBalloon\Reviews\Common\Services\FeedCacheUpdateService;
use SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome;
use SmashBalloon\Reviews\Tests\Unit\Doubles\FailedSourceRetryWpdbDouble;

/**
 * SMASH-2409 (Fallback Sources T6b): a feed holding a failed source is refetched by the hourly cron after
 * min(2^attempts, 12) hours, not at the 12-hour refresh. Config failures never; relay failures once per site.
 */
class Smash2409FailedSourceRetryTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	/** @var mixed */
	private $previous_options;

	/** @var FailedSourceRetryWpdbDouble */
	private $db;

	protected function setUp(): void
	{
		parent::setUp();
		global $wpdb, $wp_options_mock;
		$this->previous_wpdb    = $wpdb;
		$this->previous_options = $wp_options_mock;
		$this->db               = new FailedSourceRetryWpdbDouble();
		$wpdb                   = $this->db;
		$wp_options_mock        = array();
	}

	protected function tearDown(): void
	{
		global $wpdb, $wp_options_mock;
		$wpdb            = $this->previous_wpdb;
		$wp_options_mock = $this->previous_options;
		parent::tearDown();
	}

	private function failedSource(string $account, string $scope = SourceFetchOutcome::SCOPE_SOURCE, int $attempts = 0): void
	{
		$this->db->sources[] = array(
			'account_id' => $account,
			'provider'   => 'google',
			'error'      => (string) json_encode(array('id' => 'providerError', 'scope' => $scope, 'attempts' => $attempts, 'since' => 1700000000)),
		);
	}

	private function healthySource(string $account): void
	{
		$this->db->sources[] = array('account_id' => $account, 'provider' => 'google', 'error' => '');
	}

	/** @param list<string>|string $sources */
	private function feed(int $id, $sources): void
	{
		$this->db->feeds[] = array('id' => (string) $id, 'settings' => (string) json_encode(array('sources' => $sources)));
	}

	private function postsWritten(string $feedKey, int $hoursAgo): void
	{
		$this->db->caches[] = array('feed_id' => $feedKey, 'cache_key' => 'posts', 'last_updated' => gmdate('Y-m-d H:i:s', time() - $hoursAgo * 3600 - 60));
	}

	/** @return list<int> */
	private function due(array $alreadyDue = array()): array
	{
		return (new FailedSourceRetry())->schedule_retries($alreadyDue);
	}

	public function test_a_failed_source_is_retried_after_its_backoff_and_counted(): void
	{
		$this->failedSource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1', 1);

		$this->assertSame(array(1), $this->due());

		$this->assertCount(1, $this->db->updates);
		$written = json_decode((string) $this->db->updates[0]['data']['error'], true);
		$this->assertSame(array('id' => 'providerError', 'scope' => 'source', 'attempts' => 1, 'since' => 1700000000), $written);
		$this->assertSame(array('account_id' => 'A', 'provider' => 'google'), $this->db->updates[0]['where']);
	}

	/** @return array<string, array{0: int, 1: int, 2: bool}> */
	public static function backoffs(): array
	{
		return array(
			'attempt 0, 1h: before'   => array(0, 0, false),
			'attempt 2, 4h: before'   => array(2, 3, false),
			'attempt 2, 4h: after'    => array(2, 4, true),
			'attempt 3, 8h: before'   => array(3, 7, false),
			'capped at 12h: before'   => array(10, 11, false),
			'capped at 12h: after'    => array(10, 12, true),
		);
	}

	/** @dataProvider backoffs */
	public function test_the_backoff_grows_and_caps_at_twelve_hours(int $attempts, int $hoursAgo, bool $due): void
	{
		$this->failedSource('A', SourceFetchOutcome::SCOPE_SOURCE, $attempts);
		$this->feed(1, array('A'));
		$this->postsWritten('1', $hoursAgo);

		$this->assertSame($due ? array(1) : array(), $this->due());
	}

	public function test_backoff_seconds(): void
	{
		$this->assertSame(array(1, 2, 4, 8, 12, 12), array_map(fn ($a) => FailedSourceRetry::backoff_seconds($a) / 3600, array(0, 1, 2, 3, 4, 99)));
	}

	public function test_a_config_failure_is_never_retried(): void
	{
		$this->failedSource('W', SourceFetchOutcome::SCOPE_CONFIG);
		$this->feed(1, array('W'));
		$this->postsWritten('1', 24);

		$this->assertSame(array(), $this->due());
		$this->assertSame(array(), $this->db->updates);
	}

	public function test_a_relay_failure_retries_one_feed_per_site(): void
	{
		$this->failedSource('A', SourceFetchOutcome::SCOPE_RELAY);
		$this->failedSource('B', SourceFetchOutcome::SCOPE_RELAY);
		$this->feed(1, array('A'));
		$this->feed(2, array('B'));
		$this->postsWritten('1', 5);
		$this->postsWritten('2', 5);

		$this->assertSame(array(1), $this->due());
		$this->assertSame(array(), $this->due(), 'the window is closed for the rest of the backoff');
	}

	public function test_a_feed_with_a_source_failure_is_not_held_back_by_the_relay_window(): void
	{
		update_option(FailedSourceRetry::RELAY_RETRY_OPTION, time() + 3600);
		$this->failedSource('A', SourceFetchOutcome::SCOPE_RELAY);
		$this->failedSource('B', SourceFetchOutcome::SCOPE_SOURCE);
		$this->feed(1, array('A', 'B'));
		$this->postsWritten('1', 5);

		$this->assertSame(array(1), $this->due());
	}

	public function test_sources_match_by_exact_id_not_substring(): void
	{
		$this->failedSource('42');
		$this->feed(1, array('142'));
		$this->feed(2, '7,42');
		$this->postsWritten('1', 24);
		$this->postsWritten('2', 24);

		$this->assertSame(array(2), $this->due());
	}

	public function test_a_healthy_feed_is_never_added(): void
	{
		$this->healthySource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1', 24);

		$this->assertSame(array(), $this->due());
	}

	public function test_a_feed_already_in_the_twelve_hour_batch_is_not_added_twice(): void
	{
		$this->failedSource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1', 24);

		$this->assertSame(array(), $this->due(array(1)));
		$this->assertSame(array(), $this->db->updates, 'the 12-hour refresh is not a retry');
	}

	public function test_the_wpml_posts_row_counts_and_a_customizer_row_does_not(): void
	{
		$this->failedSource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1_en', 0);
		$this->postsWritten('1_CUSTOMIZER', 24);

		$this->assertSame(array(), $this->due(), 'the live WPML row was written just now');
	}

	/**
	 * A WPML row is named by the language codes Util lists, including `es-419` (digits) and `fil` (three letters), or
	 * `default` when WPML's language has no Google code (SBR_WPML::get_current_language()):
	 * a row written just now counts for every one of them, so the backoff holds.
	 *
	 * @dataProvider wpml_codes
	 */
	public function test_every_wpml_language_suffix_counts_as_the_live_row(string $code): void
	{
		$this->failedSource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1_' . $code, 0);

		$this->assertSame(array(), $this->due(), "the 1_{$code} row was written just now");
	}

	/** @return array<string, array{0: string}> */
	public static function wpml_codes(): array
	{
		return array('es-419' => array('es-419'), 'fil' => array('fil'), 'fr-CA' => array('fr-CA'), 'en-GB' => array('en-GB'), 'de' => array('de'), 'default (no Google mapping)' => array('default'));
	}

	public function test_the_cron_batch_adds_due_retries_after_the_twelve_hour_rows(): void
	{
		$this->failedSource('A');
		$this->feed(7, array('A'));
		$this->postsWritten('7', 24);

		$service = (new \ReflectionClass(FeedCacheUpdateService::class))->newInstanceWithoutConstructor();
		$batch   = $service->batch_with_retries(array(array('id' => '3', 'feed_id' => '3')), new FailedSourceRetry());

		$this->assertSame(array(array('id' => '3', 'feed_id' => '3'), array('feed_id' => 7)), $batch);
	}

	/** A retry the relay refuses on the weekly gate is a site refusal: the source is not marked failed for it. */
	public function test_a_weekly_gate_refusal_of_the_retry_is_not_a_source_failure(): void
	{
		$this->assertSame(SourceFetchOutcome::SITE_REFUSAL, SourceFetchOutcome::classify(array('id' => 'proWeeklyUpdateLimit'), 'google')['outcome']);
	}

	/** The live row is what counts: a fresh customizer preview does not postpone the retry. */
	public function test_a_fresh_customizer_row_does_not_postpone_the_retry(): void
	{
		$this->failedSource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1', 24);
		$this->postsWritten('1_CUSTOMIZER', 0);

		$this->assertSame(array(1), $this->due());
	}

	/** The hourly cron hands the updater the 12-hour rows plus the due retries. */
	public function test_the_cron_run_updates_the_retries_too(): void
	{
		$this->failedSource('A');
		$this->feed(7, array('A'));
		$this->postsWritten('7', 24);

		$service = new class extends FeedCacheUpdateService {
			/** @var array<int, array<string, mixed>> */
			public $handed = array();

			public function __construct()
			{
			}

			public function get_caches()
			{
				return array();
			}

			protected function updater(array $batch)
			{
				$this->handed = $batch;

				return new class (array()) extends \SmashBalloon\Reviews\Common\FeedCacheUpdater {
					public function do_updates()
					{
						return array();
					}
				};
			}
		};

		$service->do_updates();

		$this->assertSame(array(array('feed_id' => 7)), $service->handed);
	}

	public function test_a_wpml_suffixed_twelve_hour_row_is_the_same_feed(): void
	{
		$this->failedSource('A');
		$this->feed(5, array('A'));
		$this->postsWritten('5', 24);

		$service = (new \ReflectionClass(FeedCacheUpdateService::class))->newInstanceWithoutConstructor();
		$batch   = $service->batch_with_retries(array(array('id' => '9', 'feed_id' => '5_fr')), new FailedSourceRetry());

		$this->assertSame(array(array('id' => '9', 'feed_id' => '5_fr')), $batch);
	}

	/** One failing source shared by 12 feeds: one retry this run, and only it counts an attempt. */
	public function test_a_shared_failing_source_is_retried_once_per_run(): void
	{
		$this->failedSource('A');
		foreach (range(1, 12) as $id) {
			$this->feed($id, array('A'));
			$this->postsWritten((string) $id, 24);
		}

		$this->assertSame(array(1), $this->due());
		$this->assertCount(1, $this->db->updates);
	}

	/** Retries stay within what the 12-hour batch left of the per-tick budget, and never starve. */
	public function test_retries_stay_within_the_per_tick_budget(): void
	{
		foreach (range(1, 15) as $id) {
			$this->failedSource('S' . $id);
			$this->feed($id, array('S' . $id));
			$this->postsWritten((string) $id, 24);
		}
		$service = (new \ReflectionClass(FeedCacheUpdateService::class))->newInstanceWithoutConstructor();

		$stale = array_map(fn ($id) => array('feed_id' => (string) (100 + $id)), range(1, 7));
		$this->assertCount(7 + 3, $service->batch_with_retries($stale, new FailedSourceRetry()));

		$full = array_map(fn ($id) => array('feed_id' => (string) (200 + $id)), range(1, 10));
		$this->assertCount(10 + 1, $service->batch_with_retries($full, new FailedSourceRetry()), 'at least one retry');
	}

	public function test_a_fresh_wpml_customizer_row_does_not_postpone_the_retry(): void
	{
		$this->failedSource('A');
		$this->feed(5, array('A'));
		$this->postsWritten('5_fr', 24);
		$this->postsWritten('5_fr_CUSTOMIZER', 0);

		$this->assertSame(array(5), $this->due());
	}

	/** BC: with no failed source, the hourly run hands the updater exactly get_caches()'s rows, in order. */
	public function test_do_updates_on_a_healthy_site_hands_the_updater_get_caches_unchanged(): void
	{
		$this->healthySource('A');
		$this->feed(1, array('A'));
		$this->postsWritten('1', 24);
		$rows = array(array('id' => '4', 'feed_id' => '12', 'last_updated' => '2026-10-01 00:00:00'), array('id' => '2', 'feed_id' => '3_fr', 'last_updated' => '2026-10-02 00:00:00'));

		$service = new class ($rows) extends FeedCacheUpdateService {
			/** @var array<int, array<string, mixed>> */
			public $handed = array();

			/** @var array<int, array<string, mixed>> */
			private $rows;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct(array $rows)
			{
				$this->rows = $rows;
			}

			public function get_caches()
			{
				return $this->rows;
			}

			protected function updater(array $batch)
			{
				$this->handed = $batch;

				return new class (array()) extends \SmashBalloon\Reviews\Common\FeedCacheUpdater {
					public function do_updates()
					{
						return array();
					}
				};
			}
		};

		$service->do_updates();

		$this->assertSame($rows, $service->handed);
	}

	/**
	 * A retry really refetches: the cron builds its cache with cache_time 0 (FeedCacheUpdater), so any `posts` row
	 * not written in the future reads as expired and get_set_cache() calls update_posts_cache().
	 */
	public function test_the_cron_cache_treats_an_hour_old_row_as_expired(): void
	{
		global $wpdb;
		foreach (array('is_admin' => 'return false;', 'wp_cache_get' => 'return false;', 'wp_cache_set' => 'return true;') as $function => $body) {
			if (! function_exists($function)) {
				eval('function ' . $function . '(...$args) { ' . $body . ' }');
			}
		}
		$wpdb = new \SmashBalloon\Reviews\Tests\Unit\Doubles\FeedCacheWpdbDouble();
		$wpdb->next_results = array(array('cache_key' => 'posts', 'cache_value' => '[{"review_id":"1"}]', 'last_updated' => gmdate('Y-m-d H:i:s', time() - 3600)));

		$cron_cache = new \SmashBalloon\Reviews\Common\FeedCache('7', 0);
		$cron_cache->retrieve_and_set();
		$this->assertTrue($cron_cache->is_expired());

		$render_cache = new \SmashBalloon\Reviews\Common\FeedCache('7', 2 * 86400);
		$render_cache->retrieve_and_set();
		$this->assertFalse($render_cache->is_expired(), 'the same row is fresh for a 2-day render cache');
		$updater = (string) file_get_contents(dirname(__DIR__, 2) . '/class/Common/FeedCacheUpdater.php');
		$this->assertStringContainsString('feed_cache_for_site((string) $feed_id, 0)', $updater, 'the cron builds its cache with cache_time 0');
	}
}
