<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Utils\RefreshLockTtl;
use SmashBalloon\Reviews\Pro\Integrations\Providers\Facebook;

/**
 * SMASH-2434: both refresh locks held a fixed 75s while api_request() calls every source in turn, each relay call up
 * to 120s, so a second worker took over a live lock and fetched the same feed again. The TTL is now sized to the feed.
 */
class Smash2434RefreshLockTtlTest extends TestCase
{
	/** @var mixed */
	private $saved_options;

	/** @var mixed */
	private $saved_gets;

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$this->saved_options = $wp_options_mock;
		$this->saved_gets    = $GLOBALS['sbr_test_http_gets'] ?? null;
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$wp_options_mock                = $this->saved_options;
		$GLOBALS['sbr_test_http_gets'] = $this->saved_gets;
		parent::tearDown();
	}

	/** @return array<string, string> */
	private static function source(string $provider): array
	{
		return ['provider' => $provider, 'account_id' => $provider . '-1'];
	}

	public function test_each_relay_source_counts_its_own_call_timeout(): void
	{
		$two = [self::source('trustpilot'), self::source('google')];

		$this->assertSame(2 * SBRelay::SLOW_TIMEOUT + 15, RefreshLockTtl::for_sources($two, 'reviews'));
		$this->assertSame(2 * SBRelay::SLOW_TIMEOUT + 15, RefreshLockTtl::for_sources($two, 'sources'));
	}

	public function test_one_relay_source_already_outlasts_the_old_75s(): void
	{
		$this->assertSame(SBRelay::SLOW_TIMEOUT + 15, RefreshLockTtl::for_sources([self::source('yelp')], 'reviews'));
	}

	/** BC: a feed with no outbound call keeps the old fixed TTL. */
	public function test_local_and_empty_feeds_keep_the_75s_floor(): void
	{
		$local = [self::source('woocommerce'), self::source('edd'), self::source('collection')];

		$this->assertSame(75, RefreshLockTtl::for_sources($local, 'reviews'));
		$this->assertSame(75, RefreshLockTtl::for_sources([], 'reviews'));
		$this->assertSame(75, RefreshLockTtl::for_sources(['not-a-source', null], 'reviews'));
	}

	public function test_local_sources_add_nothing_beside_a_relay_source(): void
	{
		$mixed = [self::source('trustpilot'), self::source('woocommerce'), self::source('edd'), self::source('collection')];

		$this->assertSame(SBRelay::SLOW_TIMEOUT + 15, RefreshLockTtl::for_sources($mixed, 'reviews'));
	}

	public function test_facebook_counts_its_graph_timeout_not_the_relays(): void
	{
		$this->assertSame(2 * Facebook::TIMEOUT + 15, RefreshLockTtl::for_sources([self::source('facebook'), self::source('facebook')], 'reviews'));
	}

	public function test_an_endpoint_off_the_slow_list_counts_wps_default(): void
	{
		$this->assertSame(5, SBRelay::timeout_for('reviews/not-a-provider'));
		$this->assertSame(SBRelay::SLOW_TIMEOUT, SBRelay::timeout_for('reviews/google'));
		$this->assertSame(SBRelay::SLOW_TIMEOUT, SBRelay::timeout_for('sources/booking/resolve'));
	}

	public function test_a_huge_feed_is_capped(): void
	{
		$many = array_fill(0, 40, self::source('trustpilot'));

		$this->assertSame(RefreshLockTtl::CAP, RefreshLockTtl::for_sources($many, 'reviews'));
	}

	/** BC: call() still sends 120s on a slow endpoint and leaves WP's default on the others. */
	public function test_call_keeps_its_timeouts(): void
	{
		$GLOBALS['sbr_test_http_gets'] = [];
		$relay                         = new SBRelay();
		$relay->call('reviews/trustpilot', [], 'GET');
		$relay->call('email/check-status', [], 'GET');

		$gets = $GLOBALS['sbr_test_http_gets'];
		$this->assertCount(2, $gets);
		$this->assertSame(120, $gets[0]['args']['timeout'] ?? null);
		$this->assertArrayNotHasKey('timeout', $gets[1]['args']);
	}

	/**
	 * A Feed whose fetch only records that it ran, around the real update_posts_cache()/update_header_cache().
	 *
	 * @param array<int, array<string, string>> $sources
	 */
	private function feed(array $sources, \ArrayObject $fetched): CommonFeed
	{
		$feed = new class ($fetched) extends CommonFeed {
			/** @var \ArrayObject<int, string> */
			private $fetched;

			public function __construct(\ArrayObject $fetched)
			{
				$this->fetched = $fetched;
			}

			public function get_remote_posts($settings)
			{
				$this->fetched[] = 'posts';
				return [];
			}

			public function get_remote_header_data($settings)
			{
				$this->fetched[] = 'header';
				return [];
			}

			public function posts_from_db()
			{
				return [];
			}

			public function update_cache($posts)
			{
			}

			public function maybe_encrypt_cached_posts($posts)
			{
				return $posts;
			}

			public function update_header_cache_from_source()
			{
				return [];
			}
		};

		$set = static function (string $name, $value) use ($feed): void {
			$prop = new \ReflectionProperty(CommonFeed::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($feed, $value);
		};
		$set('settings', ['sources' => $sources, 'apiCallLanguage' => '']);
		$set('statuses', ['from_cache' => false, 'post_found_before_filter' => false, 'errors' => []]);
		$set('feed_id', 9);

		return $feed;
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function locks(): array
	{
		return [
			'posts lock'  => ['sbr_refresh_lock_posts_9', 'update_posts_cache'],
			'header lock' => ['sbr_refresh_lock_header_9', 'update_header_cache'],
		];
	}

	/**
	 * A lock taken 100s ago on a two-source feed is still live (TTL 255s), so the second worker serves what's stored
	 * instead of fetching again. With the old 75s it was taken over.
	 *
	 * @dataProvider locks
	 */
	public function test_a_live_lock_on_a_slow_feed_is_not_taken_over(string $lock, string $method): void
	{
		global $wp_options_mock;
		$wp_options_mock[$lock] = time() - 100;
		$fetched                = new \ArrayObject();

		$this->feed([self::source('trustpilot'), self::source('google')], $fetched)->$method();

		$this->assertSame([], $fetched->getArrayCopy());
	}

	/**
	 * BC: a lock older than the feed's TTL is still a crashed worker's, and is taken over.
	 *
	 * @dataProvider locks
	 */
	public function test_a_lock_past_the_feeds_ttl_is_still_taken_over(string $lock, string $method): void
	{
		global $wp_options_mock;
		$wp_options_mock[$lock] = time() - (SBRelay::SLOW_TIMEOUT + 15 + 5);
		$fetched                = new \ArrayObject();

		$this->feed([self::source('trustpilot')], $fetched)->$method();

		$this->assertCount(1, $fetched);
		$this->assertArrayNotHasKey($lock, $wp_options_mock, 'the taker releases the lock when it is done');
	}
}
