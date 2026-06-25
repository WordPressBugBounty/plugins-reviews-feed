<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * Pins SBR_Feed_Saver_Manager::relay_source_removed() — the relay/remove verdict
 * used by delete_souce() (PR #482 Copilot review, C5).
 *
 * SBRelay::call() returns the full body on success (`success: true`) and the
 * UNWRAPPED error envelope on failure (`{ id, code, success: false }`, NO `error`
 * key). The original check gated on `$relay_response['error']`, which never
 * matched a real failure, so a failed `source/remove` read as success and the
 * source was deleted locally anyway — orphaning the relay-side source, which then
 * keeps counting against the per-license source cap.
 *
 * These exercise the verdict directly across every envelope shape, so a relay
 * rename (e.g. `sourceNotFound`) or a change to how `call()` unwraps errors can't
 * silently re-orphan sources or start blocking legitimate deletes.
 */
final class DeleteSourceRelayFailureTest extends TestCase
{
	private static function removed($relay_response): bool
	{
		$m = new \ReflectionMethod(SBR_Feed_Saver_Manager::class, 'relay_source_removed');
		$m->setAccessible(true);

		return (bool) $m->invoke(null, $relay_response);
	}

	public function test_success_response_allows_local_delete(): void
	{
		// What `respondWithSuccess([], 'Source removed.')` looks like after call().
		$this->assertTrue(self::removed(['success' => true, 'message' => 'Source removed.']));
	}

	public function test_generic_failure_blocks_local_delete(): void
	{
		// Unwrapped error envelope — the case the old `['error']` check missed.
		$this->assertFalse(self::removed(['id' => 'unknownError', 'code' => 400, 'success' => false]));
	}

	public function test_auth_failure_blocks_local_delete(): void
	{
		$this->assertFalse(self::removed(['id' => 'invalidToken', 'code' => 401, 'success' => false]));
	}

	public function test_source_not_found_counts_as_removed(): void
	{
		$this->assertTrue(self::removed(['id' => 'sourceNotFound', 'code' => 404, 'success' => false]));
	}

	public function test_http_404_without_id_counts_as_removed(): void
	{
		$this->assertTrue(self::removed(['code' => 404, 'success' => false]));
	}

	public function test_unreachable_relay_fails_open(): void
	{
		// WP_Error → empty decoded body → proceed (prior, documented behaviour).
		$this->assertTrue(self::removed([]));
	}

	public function test_malformed_id_or_code_does_not_fatal(): void
	{
		// Pathological shapes must not throw — just fall through to "not removed".
		$this->assertFalse(self::removed(['id' => ['x'], 'code' => ['y'], 'success' => false]));
	}

	public function test_source_info_json_decode_is_array_guarded(): void
	{
		// C4: delete_souce() must coerce a malformed json_decode() result to an
		// array before reading relay_source_id (PHP 8 offset-on-null guard).
		$path = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		$this->assertStringContainsString(
			'if (!is_array($source_info))',
			(string) file_get_contents($path)
		);
	}
}
