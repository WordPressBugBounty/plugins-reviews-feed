<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SMASH-1583 front-end parity guard.
 *
 * The multi-source feed header must report the COMBINED review count across all
 * sources on the front-end, matching the Feed Builder (admin) preview. A bug
 * surfaced where a source whose fresh remote fetch was skipped by a rate limit
 * — `check_api_limit()` (API-key cap) or `limit_provider_api_calls()` (free-tier
 * per-provider call cap, e.g. a keyless Google source that already fetched) —
 * was dropped from the header entirely, because `Feed::api_request()`'s two
 * limit-skip `continue` paths did NOT fall back to the source's stored `info`
 * (unlike the no-data / error paths, which do). Result: the front-end header
 * under-counted (e.g. showed one source's 4342 instead of the combined 6091),
 * while the admin preview — which always aggregates every source's stored info
 * — was correct. The fix routes both limit-skip paths through
 * `push_stored_source_info()` so a rate-limited source is still counted.
 *
 * The plugin unit suite runs on plain PHPUnit (no WP / no full class tree), so
 * these are source-level guards that fail if the fallback is removed.
 */
final class Smash1583HeaderParityTest extends TestCase
{
	private static function feedSource(): string
	{
		$path = __DIR__ . '/../../class/Common/Feed.php';
		self::assertFileExists($path, 'Feed.php not found at expected path');

		return (string) file_get_contents($path);
	}

	/** Body of api_request(), so the skip-path assertions are scoped to it. */
	private static function apiRequestBody(): string
	{
		$src = self::feedSource();
		$start = strpos($src, 'function api_request');
		self::assertNotFalse($start, 'api_request() must exist');

		return substr($src, $start, 4000);
	}

	public function test_helper_exists(): void
	{
		$this->assertStringContainsString(
			'function push_stored_source_info',
			self::feedSource(),
			'The stored-info fallback helper must exist.'
		);
	}

	public function test_helper_is_scoped_to_sources_with_info(): void
	{
		$src = self::feedSource();
		$start = strpos($src, 'function push_stored_source_info');
		$this->assertNotFalse($start);
		$body = substr($src, $start, 500);

		$this->assertStringContainsString("\$type !== 'sources'", $body, 'Helper must no-op for review requests.');
		$this->assertStringContainsString("empty(\$request['info'])", $body, 'Helper must no-op when there is no stored info.');
		$this->assertStringContainsString("\$data[] = ['info' => \$info]", $body, 'Helper must push the stored info into the results.');
	}

	public function test_api_key_limit_skip_keeps_the_source(): void
	{
		$body = self::apiRequestBody();
		$pos = strpos($body, 'check_api_limit(');
		$this->assertNotFalse($pos, 'check_api_limit skip must exist');
		// The stored-info fallback must appear within the skip block (before its continue).
		$block = substr($body, $pos, 200);
		$this->assertStringContainsString(
			'push_stored_source_info',
			$block,
			'A source skipped by check_api_limit must still be counted via stored info.'
		);
	}

	public function test_provider_call_limit_skip_keeps_the_source(): void
	{
		$body = self::apiRequestBody();
		$pos = strpos($body, 'limit_provider_api_calls(');
		$this->assertNotFalse($pos, 'limit_provider_api_calls skip must exist');
		$block = substr($body, $pos, 200);
		$this->assertStringContainsString(
			'push_stored_source_info',
			$block,
			'A source skipped by the provider call limit must still be counted via stored info (the SMASH-1583 front-end drop).'
		);
	}
}
