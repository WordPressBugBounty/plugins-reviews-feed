<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * SMASH-2381: on Pro, Pro\Feed::init() calls set_wpml() on its cache on WPML sites; only Pro\FeedCache has it. Six
 * call sites built Pro\Feed over Common\FeedCache, which fataled: the hourly cache cron, the builder's save with
 * get_posts, fly preview and moderation preview, and Review Alerts (front end, and two service previews). They now take
 * Util::feed_cache_for_site().
 */
class Smash2381WpmlFeedCacheTest extends TestCase
{
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_on_pro_the_site_cache_has_set_wpml(): void
	{
		self::stubIsAdmin();
		define('SBR_PRO', true);

		$cache = Util::feed_cache_for_site('42', 300);

		$this->assertInstanceOf(\SmashBalloon\Reviews\Pro\FeedCache::class, $cache);
		$this->assertTrue(method_exists($cache, 'set_wpml'));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bc_on_free_it_is_the_common_cache(): void
	{
		self::stubIsAdmin();
		$cache = Util::feed_cache_for_site('42', 300);

		$this->assertSame(\SmashBalloon\Reviews\Common\FeedCache::class, get_class($cache));
	}

	/**
	 * A floor, not a proof: no file may build a Pro feed (directly or through a `$feed_class`) over a Common cache,
	 * inline `new FeedCache(` or held in a variable assigned up to 10 lines before. Names each offending line.
	 */
	public function test_no_pro_feed_is_built_over_an_inline_common_cache(): void
	{
		$offenders = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/class'));
		foreach ($files as $file) {
			if (! $file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}
			$lines = array_map('strval', (array) file($file->getPathname()));
			$held = [];
			foreach ($lines as $number => $line) {
				// A cache held in a variable: `$feed_cache = new FeedCache(...)`, used by a Pro feed a few lines on.
				if (preg_match('/(\$\w+)\s*=\s*new\s+FeedCache\s*\(/', $line, $m) === 1) {
					$held[$m[1]] = $number;
				}
				// Only the Pro branch of a `pro ? new Pro\Feed(...) : new Common\Feed(...)` ternary counts.
				$start = max((int) strpos($line, 'Pro\\Feed('), (int) strpos($line, 'new $feed_class('));
				$pro_part = $start > 0 ? explode(' : ', substr($line, $start))[0] : '';
				if ($pro_part === '') {
					continue;
				}
				$inline = preg_match('/new\s+FeedCache\s*\(/', $pro_part) === 1;
				$via_variable = false;
				foreach ($held as $variable => $at) {
					$via_variable = $via_variable || ($number - $at <= 10 && str_contains($pro_part, $variable));
				}
				if ($inline || $via_variable) {
					$offenders[] = basename($file->getPathname()) . ':' . ($number + 1);
				}
			}
		}

		$this->assertSame([], $offenders);
	}

	/** FeedCache's constructor asks is_admin(); these tests run without WordPress (as Smash2173 does). */
	private static function stubIsAdmin(): void
	{
		if (! function_exists('is_admin')) {
			eval('function is_admin() { return false; }');
		}
	}

	/** Every caller that built a Pro feed over a Common cache now uses the helper (the four named, plus two in the builder). */
	public function test_every_pro_feed_caller_uses_the_site_cache(): void
	{
		$root = dirname(__DIR__, 2) . '/class/Common/';
		foreach (
			[
			'FeedCacheUpdater.php' => 1,
			'Builder/SBR_Feed_Saver_Manager.php' => 3,
			'ReviewAlerts/SBR_Review_Alert_Frontend.php' => 1,
			'ReviewAlerts/SBR_Review_Alert_Service.php' => 2,
			] as $file => $count
		) {
			$this->assertSame($count, substr_count((string) file_get_contents($root . $file), 'Util::feed_cache_for_site('), $file);
		}
	}
}
