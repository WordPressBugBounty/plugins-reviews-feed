<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins the SMASH-1614 follow-up: a user-initiated "Clear All Caches" tells the
 * relay to clear the per-source weekly fetch window, so the immediate refresh
 * that follows can actually refetch instead of being blocked by the once-per-week
 * Pro update cap (which would otherwise leave the feed empty for up to 7 days
 * after the local cache is wiped).
 *
 * These are source-level guards (the plugin unit suite runs on plain PHPUnit
 * without the WP test framework, so clear_all_caches() — which calls wp_die /
 * ajax / relay — can't be invoked directly). They protect the wiring,
 * ordering, endpoint, filter gate, and best-effort safety from regression.
 */
final class ClearCacheRelayResetTest extends TestCase
{
	private static function saverSource(): string
	{
		$path = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		self::assertFileExists($path, 'SBR_Feed_Saver_Manager.php not found at expected path');

		return (string) file_get_contents($path);
	}

	/** Just the body of clear_all_caches(), so ordering assertions are scoped. */
	private static function clearAllCachesBody(): string
	{
		$src = self::saverSource();
		$start = strpos($src, 'function clear_all_caches');
		self::assertNotFalse($start, 'clear_all_caches() must exist');
		// Slice a generous window that covers the method body.
		return substr($src, $start, 1600);
	}

	public function test_clear_all_caches_calls_relay_fetch_window_reset(): void
	{
		$this->assertStringContainsString(
			'self::reset_relay_fetch_window()',
			self::clearAllCachesBody(),
			'Clear All Caches must trigger the relay fetch-window reset.'
		);
	}

	public function test_reset_is_gated_by_a_filter(): void
	{
		$this->assertStringContainsString(
			"apply_filters('sbr_reset_relay_fetch_window_on_clear', true)",
			self::clearAllCachesBody(),
			'The relay reset must be filterable so support can disable it without a redeploy.'
		);
	}

	public function test_reset_runs_before_the_immediate_refresh(): void
	{
		// Compare the unique call sites in the full source (window-independent):
		// the reset must be invoked before the refresh, or the cron fetches the
		// refresh schedules still hit the weekly wall.
		$src = self::saverSource();
		$resetPos = strpos($src, 'self::reset_relay_fetch_window()');
		$refreshPos = strpos($src, 'self::trigger_immediate_refresh()');

		$this->assertNotFalse($resetPos, 'reset_relay_fetch_window() must be called');
		$this->assertNotFalse($refreshPos, 'trigger_immediate_refresh() must be called');
		$this->assertLessThan(
			$refreshPos,
			$resetPos,
			'The relay window reset must run BEFORE the immediate refresh.'
		);
	}

	public function test_helper_posts_to_the_correct_relay_endpoint(): void
	{
		$src = self::saverSource();
		$this->assertStringContainsString(
			"->call('source/reset-fetch-window', [], 'POST', true)",
			$src,
			'The reset must POST to source/reset-fetch-window with auth.'
		);
	}

	public function test_helper_is_best_effort_and_cannot_block_clear(): void
	{
		$src = self::saverSource();
		$start = strpos($src, 'function reset_relay_fetch_window');
		$this->assertNotFalse($start, 'reset_relay_fetch_window() helper must exist');
		$body = substr($src, $start, 500);

		$this->assertStringContainsString('try {', $body, 'The relay call must be wrapped in try/catch.');
		$this->assertStringContainsString('catch (\Throwable', $body, 'A relay failure must never block Clear All Caches.');
	}
}
