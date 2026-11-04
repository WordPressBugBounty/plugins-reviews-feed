<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/RoutinesWpCacheDeleteDouble.php';

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\HideCachedProviderFailuresRoutine;

/**
 * SBR-556: a feed's cached `errors`, written before provider failures were hidden, must not keep showing them after
 * the update. Only provider-failure entries go; the ones the client can act on stay.
 */
class HideCachedProviderFailuresRoutineTest extends TestCase
{
	/** @var list<string> */
	public static $deleted = [];

	/** @var mixed */
	private $saved_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		self::$deleted = [];
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		parent::tearDown();
	}

	private static function error(?string $id): array
	{
		return array_filter(['message' => 'm', 'directions' => 'd', 'error_id' => $id], static fn ($v) => $v !== null);
	}

	public function test_it_drops_provider_failures_and_keeps_the_rest_in_order(): void
	{
		$kept = HideCachedProviderFailuresRoutine::without_provider_failures([
			self::error('reviewsLicenseNotValid'),
			self::error('reviewsSourceNotCreated'),
			self::error(null), // "No Posts Found" carries no id
			self::error('relayUnreachable'), // the site's own HTTP failing: the owner's to fix, kept
			self::error('relayServerError'),
			self::error('sourceConnectionError'),
			self::error('reviewsError'),
		]);

		$this->assertSame(['reviewsLicenseNotValid', null, 'relayUnreachable', 'sourceConnectionError'], array_map(static fn ($e) => $e['error_id'] ?? null, $kept));
	}

	public function test_run_rewrites_only_the_rows_that_held_a_provider_failure(): void
	{
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** @var list<array{0: string, 1: array, 2: array}> */
			public $updates = [];

			public function prepare($query, ...$args)
			{
				return vsprintf(str_replace('%s', "'%s'", $query), $args);
			}

			public function get_results($sql, $output = null)
			{
				return [
					['id' => '1', 'feed_id' => '542_CUSTOMIZER', 'cache_value' => (string) json_encode([['message' => 'x', 'error_id' => 'reviewsSourceNotCreated'], ['message' => 'y', 'error_id' => 'reviewsLicenseNotValid']])],
					['id' => '2', 'feed_id' => '7', 'cache_value' => (string) json_encode([['message' => 'z', 'error_id' => 'reviewsLicenseNotValid']])],
					['id' => '3', 'feed_id' => '8', 'cache_value' => ''],
				];
			}

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = [$table, $data, $where];

				return 1;
			}
		};

		(new HideCachedProviderFailuresRoutine())->run();

		$updates = $GLOBALS['wpdb']->updates;
		$this->assertCount(1, $updates, 'rows without a provider failure, or not JSON, are left alone');
		$this->assertSame('wp_sbr_feed_caches', $updates[0][0]);
		$this->assertSame(['id' => 1], $updates[0][2]);
		$this->assertSame([['message' => 'y', 'error_id' => 'reviewsLicenseNotValid']], json_decode($updates[0][1]['cache_value'], true));
		$this->assertSame(['sbr_feed_542_CUSTOMIZER_'], self::$deleted, 'the object-cache copy of only the rewritten row goes too');
	}
}
