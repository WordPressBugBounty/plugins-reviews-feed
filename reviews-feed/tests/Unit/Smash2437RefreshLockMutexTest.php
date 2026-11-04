<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;

/**
 * SMASH-2437: the refresh lock let two workers in. The take was add_option(), which upserts (INSERT … ON DUPLICATE KEY
 * UPDATE, wp-includes/option.php:1142), so a worker whose insert landed second still got true; the stale takeover was
 * an unconditional update_option(). The races are driven inside the statements, where a real one happens: the
 * bootstrap's add_option() shim refuses an existing row, so a row seeded beforehand would not tell the two apart.
 */
class Smash2437RefreshLockMutexTest extends TestCase
{
	private const LOCK = 'sbr_refresh_lock_posts_9';

	/** @var mixed */
	private $saved_options;

	/** @var mixed */
	private $saved_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$this->saved_options = $wp_options_mock;
		$this->saved_wpdb    = $GLOBALS['wpdb'];
		$wp_options_mock     = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$wp_options_mock = $this->saved_options;
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		parent::tearDown();
	}

	private function acquire(int $ttl = 135): bool
	{
		$feed = (new \ReflectionClass(CommonFeed::class))->newInstanceWithoutConstructor();
		$take = new \ReflectionMethod(CommonFeed::class, 'acquire_refresh_lock');
		$take->setAccessible(true);

		return (bool) $take->invoke($feed, self::LOCK, $ttl);
	}

	/**
	 * Another worker's write lands inside this worker's statement, the way a race does in MySQL: $on runs just before
	 * the statement whose SQL starts with $prefix, then the bootstrap double answers it.
	 */
	private function race(string $prefix, callable $on): void
	{
		$inner           = $this->saved_wpdb;
		$GLOBALS['wpdb'] = new class ($inner, $prefix, $on) {
			/** @var object */
			private $inner;
			/** @var string */
			private $prefix;
			/** @var callable */
			private $on;
			/** @var string */
			public $options;

			public function __construct(object $inner, string $prefix, callable $on)
			{
				$this->inner   = $inner;
				$this->prefix  = $prefix;
				$this->on      = $on;
				$this->options = $inner->options;
			}

			/** @param mixed ...$args */
			public function prepare(string $query, ...$args): string
			{
				return $this->inner->prepare($query, ...$args);
			}

			/** @return int|bool */
			public function query(string $sql)
			{
				if (strpos($sql, $this->prefix) === 0) {
					( $this->on )();
				}
				return $this->inner->query($sql);
			}

			/** @return string|null */
			public function get_var(string $sql)
			{
				return $this->inner->get_var($sql);
			}
		};
	}

	public function test_a_free_lock_is_taken_and_stamped(): void
	{
		global $wp_options_mock;

		$this->assertTrue($this->acquire());
		$this->assertEqualsWithDelta(time(), (int) $wp_options_mock[ self::LOCK ], 2);
	}

	public function test_a_worker_whose_insert_lands_second_does_not_get_the_lock(): void
	{
		global $wp_options_mock;
		$this->race('INSERT IGNORE', static function () use (&$wp_options_mock) {
			$wp_options_mock[ self::LOCK ] = (string) time(); // the other worker's insert, first by a hair
		});

		$this->assertFalse($this->acquire());
	}

	public function test_two_workers_on_one_stale_lock_only_the_first_takes_it_over(): void
	{
		global $wp_options_mock;
		$wp_options_mock[ self::LOCK ] = (string) ( time() - 600 );
		$this->race('UPDATE', static function () use (&$wp_options_mock) {
			$wp_options_mock[ self::LOCK ] = (string) ( time() - 1 ); // the other worker's takeover, first
		});

		$this->assertFalse($this->acquire());
		$this->assertSame((string) ( time() - 1 ), (string) $wp_options_mock[ self::LOCK ], 'the winner keeps its stamp');
	}

	/** BC: a crashed worker's lock is still taken over. */
	public function test_a_stale_lock_alone_is_taken_over(): void
	{
		global $wp_options_mock;
		$wp_options_mock[ self::LOCK ] = (string) ( time() - 600 );

		$this->assertTrue($this->acquire());
		$this->assertEqualsWithDelta(time(), (int) $wp_options_mock[ self::LOCK ], 2);
	}

	/** BC: a live lock is still respected for its TTL. */
	public function test_a_live_lock_is_respected(): void
	{
		global $wp_options_mock;
		$wp_options_mock[ self::LOCK ] = (string) ( time() - 100 );

		$this->assertFalse($this->acquire(135));
		$this->assertSame((string) ( time() - 100 ), (string) $wp_options_mock[ self::LOCK ]);
	}

	public function test_a_lock_released_between_the_two_statements_is_left_for_the_next_render(): void
	{
		global $wp_options_mock;
		$this->race('INSERT IGNORE', static function () use (&$wp_options_mock) {
			$wp_options_mock[ self::LOCK ] = (string) time();
		});
		$GLOBALS['wpdb'] = new class ($GLOBALS['wpdb']) {
			/** @var object */
			private $inner;
			/** @var string */
			public $options;

			public function __construct(object $inner)
			{
				$this->inner   = $inner;
				$this->options = $inner->options;
			}

			/** @param mixed ...$args */
			public function prepare(string $query, ...$args): string
			{
				return $this->inner->prepare($query, ...$args);
			}

			/** @return int|bool */
			public function query(string $sql)
			{
				return $this->inner->query($sql);
			}

			/** @return string|null The holder released it before the read. */
			public function get_var(string $sql)
			{
				return null;
			}
		};

		$this->assertFalse($this->acquire());
	}
}
