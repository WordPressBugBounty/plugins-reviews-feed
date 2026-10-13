<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\DatabaseHealthService;

// bootstrap.php:94 defines it in the plugin; the test bootstrap doesn't.
if (!defined('SBR_FEED_LOCATOR')) {
	define('SBR_FEED_LOCATOR', 'sbr_feed_locator');
}

/**
 * SMASH-1606: missing sbr_* tables are recreated on admin load, and a healthy site is checked at most hourly.
 * The $wpdb double answers SHOW TABLES from a set; recreate_tables() is stubbed to "create" what the real
 * routines would (or nothing, for a host without CREATE privilege).
 */
final class Smash1606DatabaseHealthTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;
	/** @var mixed */
	private $previous_transients;
	/** @var array<string, mixed> */
	private $previous_flags = [];

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_transients_mock;
		$this->previous_transients = $wp_transients_mock;
		$wp_transients_mock = [];
		foreach ([ 'sbr_test_user_can', 'sbr_test_doing_ajax' ] as $flag) {
			$this->previous_flags[ $flag ] = $GLOBALS[ $flag ] ?? null;
		}
		$GLOBALS['sbr_test_user_can']   = true;
		$GLOBALS['sbr_test_doing_ajax'] = false;
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var array<string, true> */
			public $tables = [];
			/** @var int */
			public $show_tables = 0;
			/** @var list<array> */
			public $prepared = [];

			public function prepare($query, ...$args)
			{
				$this->prepared[] = [ 'query' => $query, 'args' => $args ];
				return [ 'query' => $query, 'args' => $args ];
			}
			public function esc_like($text)
			{
				return addcslashes($text, '_%\\');
			}
			public function get_var($prepared)
			{
				$this->show_tables++;
				$like = is_array($prepared) ? ( $prepared['args'][0] ?? '' ) : '';
				$name = stripcslashes($like);
				return isset($this->tables[ $name ]) ? $name : null;
			}
		};
	}

	protected function tearDown(): void
	{
		global $wp_transients_mock;
		$wp_transients_mock = $this->previous_transients;
		foreach ($this->previous_flags as $flag => $value) {
			$GLOBALS[ $flag ] = $value;
		}
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	/** @return list<string> the tables a healthy Free site has */
	private function all_tables(): array
	{
		return [ 'wp_sbr_feeds', 'wp_sbr_feed_caches', 'wp_sbr_sources', 'wp_sbr_reviews_posts', 'wp_sbr_feed_locator' ];
	}

	private function service(bool $creates = true): DatabaseHealthService
	{
		$all = $this->all_tables();
		return new class ($creates, $all) extends DatabaseHealthService {
			/** @var int */
			public $recreated = 0;
			/** @var list<string> */
			public $last_missing = [];
			/** @var bool */
			private $creates;
			/** @var list<string> */
			private $all;
			public function __construct(bool $creates, array $all)
			{
				$this->creates = $creates;
				$this->all     = $all;
			}
			protected function recreate_tables(array $missing)
			{
				$this->recreated++;
				$this->last_missing = $missing;
				if ($this->creates) {
					foreach ($this->all as $t) {
						$GLOBALS['wpdb']->tables[ $t ] = true;
					}
				}
			}
		};
	}

	private function have(array $tables): void
	{
		$GLOBALS['wpdb']->tables = array_fill_keys($tables, true);
	}

	public function test_a_healthy_site_recreates_nothing_and_is_cached_for_an_hour(): void
	{
		$this->have($this->all_tables());
		$svc = $this->service();
		$svc->maybe_repair_tables();
		$this->assertSame(0, $svc->recreated);
		$this->assertNotEmpty(get_transient(DatabaseHealthService::HEALTH_TRANSIENT));
	}

	public function test_a_cached_healthy_site_runs_no_query(): void
	{
		set_transient(DatabaseHealthService::HEALTH_TRANSIENT, 1, 3600);
		$svc = $this->service();
		$svc->maybe_repair_tables();
		$this->assertSame(0, $GLOBALS['wpdb']->show_tables);
		$this->assertSame(0, $svc->recreated);
	}

	public function test_a_dropped_table_is_recreated_and_then_cached(): void
	{
		$tables = $this->all_tables();
		$this->have(array_diff($tables, [ 'wp_sbr_sources' ]));
		$svc = $this->service();
		$this->assertSame([ 'wp_sbr_sources' ], $svc->missing_tables());
		$svc->maybe_repair_tables();
		$this->assertSame(1, $svc->recreated);
		$this->assertSame([], $svc->missing_tables());
		$this->assertNotEmpty(get_transient(DatabaseHealthService::HEALTH_TRANSIENT));
	}

	public function test_all_tables_dropped_are_recreated(): void
	{
		$this->have([]);
		$svc = $this->service();
		$this->assertCount(5, $svc->missing_tables());
		$svc->maybe_repair_tables();
		$this->assertSame([], $svc->missing_tables());
	}

	public function test_a_failed_create_is_not_cached_so_the_next_load_retries(): void
	{
		$this->have([]);
		$svc = $this->service(false);
		$svc->maybe_repair_tables();
		$this->assertSame(1, $svc->recreated);
		$this->assertFalse(get_transient(DatabaseHealthService::HEALTH_TRANSIENT));
		$svc->maybe_repair_tables();
		$this->assertSame(2, $svc->recreated, 'retried on the next admin load');
	}

	public function test_the_repair_is_told_which_tables_were_missing(): void
	{
		$this->have(array_diff($this->all_tables(), [ 'wp_sbr_reviews_posts' ]));
		$svc = $this->service();
		$svc->maybe_repair_tables();
		$this->assertSame([ 'wp_sbr_reviews_posts' ], $svc->last_missing, 'drives the post-rebuild schema routines');
	}

	public function test_admin_ajax_requests_never_check_or_repair(): void
	{
		$GLOBALS['sbr_test_doing_ajax'] = true;
		$this->have([]);
		$svc = $this->service();
		$svc->maybe_repair_tables();
		$this->assertSame(0, $GLOBALS['wpdb']->show_tables);
		$this->assertSame(0, $svc->recreated);
	}

	public function test_a_user_without_manage_options_never_checks_or_repairs(): void
	{
		$GLOBALS['sbr_test_user_can'] = false;
		$this->have([]);
		$svc = $this->service();
		$svc->maybe_repair_tables();
		$this->assertSame(0, $GLOBALS['wpdb']->show_tables);
		$this->assertSame(0, $svc->recreated);
	}

	public function test_table_names_are_escaped_for_like(): void
	{
		$this->have($this->all_tables());
		$this->service()->missing_tables();
		$this->assertSame('wp\\_sbr\\_feeds', $GLOBALS['wpdb']->prepared[0]['args'][0], '_ is a LIKE wildcard');
	}
}
