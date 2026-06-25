<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\LicenseManagerService;

/**
 * SMASH-1585 — deactivate stale-state recovery.
 *
 * When a migrated site clicks Deactivate, EDD has no activation for the new
 * URL, so the relay returns errorId `licenseNotDeactivated` + edd_reason
 * `failed`. ajax_deactivate_license() must recognize that as "nothing to
 * deactivate, the local `valid` flag is stale" and clear local state so the
 * panel falls back to Activate — instead of dead-ending on an error the
 * customer cannot clear.
 *
 * These pin the detection predicate `is_nothing_to_deactivate()`, including the
 * load-bearing detail that it reads FLAT keys (`$response['id']` /
 * `$response['edd_reason']`) because SBRelay::call() unwraps the error envelope
 * — reading `$response['data'][...]` (as the original support report proposed)
 * would never fire.
 */
class LicenseDeactivateStaleStateTest extends TestCase
{
	private function predicate($response): bool
	{
		// The predicate only reads its argument — construct without deps.
		$svc = (new \ReflectionClass(LicenseManagerService::class))
			->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(LicenseManagerService::class, 'is_nothing_to_deactivate');
		$method->setAccessible(true);

		return $method->invoke($svc, $response);
	}

	public function test_fires_on_flat_licenseNotDeactivated_failed(): void
	{
		// The shape SBRelay::call() actually hands the plugin on this error
		// (envelope unwrapped → flat keys).
		$this->assertTrue($this->predicate([
			'id' => 'licenseNotDeactivated',
			'edd_reason' => 'failed',
		]));
	}

	public function test_does_not_fire_on_nested_shape(): void
	{
		// Proves we read FLAT keys. The original report's `$response['data']['id']`
		// read would match this shape but never the real (unwrapped) response —
		// so this MUST be false.
		$this->assertFalse($this->predicate([
			'data' => [
				'id' => 'licenseNotDeactivated',
				'edd_reason' => 'failed',
			],
		]));
	}

	public function test_does_not_fire_on_other_edd_reason(): void
	{
		// Scoped to `failed` — the "no activation for this URL" code. A transient
		// or different reason must not clear local state.
		$this->assertFalse($this->predicate([
			'id' => 'licenseNotDeactivated',
			'edd_reason' => 'expired',
		]));
	}

	public function test_does_not_fire_on_different_error_id(): void
	{
		$this->assertFalse($this->predicate([
			'id' => 'invalidToken',
			'edd_reason' => 'failed',
		]));
	}

	public function test_does_not_fire_on_success_response(): void
	{
		$this->assertFalse($this->predicate([
			'success' => true,
			'data' => ['license' => 'deactivated'],
		]));
	}

	public function test_does_not_fire_on_non_array(): void
	{
		$this->assertFalse($this->predicate(null));
		$this->assertFalse($this->predicate('licenseNotDeactivated'));
	}
}
