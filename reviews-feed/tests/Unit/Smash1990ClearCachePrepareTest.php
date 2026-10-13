<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Clear_Cache;

/**
 * SMASH-1990: clear_feed_caches_by_id() passed a finished query to prepare() with no placeholder, so WordPress
 * logged "wpdb::prepare was called incorrectly" on every call, and an empty list still ran `feed_id IN ('')`.
 */
final class Smash1990ClearCachePrepareTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var list<array{query: string, args: array}> */
			public $prepared = [];
			/** @var list<mixed> */
			public $queries = [];

			public function prepare($query, ...$args)
			{
				$this->prepared[] = [ 'query' => $query, 'args' => $args ];
				return $query . ' -- ' . json_encode($args);
			}
			public function query($sql)
			{
				$this->queries[] = $sql;
				return 1;
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_the_ids_go_to_prepare_as_placeholders_and_arguments(): void
	{
		Clear_Cache::clear_feed_caches_by_id([ '7', '7_CUSTOMIZER' ]);
		$this->assertCount(1, $GLOBALS['wpdb']->prepared);
		$p = $GLOBALS['wpdb']->prepared[0];
		$this->assertStringContainsString('feed_id IN (%s, %s)', $p['query']);
		$this->assertSame([ '7', '7_CUSTOMIZER' ], $p['args'], 'was interpolated into the query with no args');
		$this->assertStringContainsString("NOT IN ( 'posts_backup', 'header_backup' )", $p['query'], 'backups untouched');
		$this->assertCount(1, $GLOBALS['wpdb']->queries);
	}

	public function test_integer_ids_are_passed_as_strings(): void
	{
		Clear_Cache::clear_feed_caches_by_id([ 7 ]);
		$this->assertSame([ '7' ], $GLOBALS['wpdb']->prepared[0]['args']);
	}

	public function test_an_empty_list_runs_no_query(): void
	{
		Clear_Cache::clear_feed_caches_by_id([]);
		Clear_Cache::clear_feed_caches_by_id([ '' ]);
		$this->assertSame([], $GLOBALS['wpdb']->prepared);
		$this->assertSame([], $GLOBALS['wpdb']->queries);
	}
}
