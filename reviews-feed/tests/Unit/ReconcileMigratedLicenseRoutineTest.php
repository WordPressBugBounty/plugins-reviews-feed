<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\ReconcileMigratedLicenseRoutine;

/**
 * SMASH-1585 — one-shot recovery for sites wedged by a pre-2.6.3 migration fork.
 *
 * Verifies the routine: (1) runs once, only for installs that think they are
 * licensed; (2) treats only an explicit relay license_active:false (with a
 * healthy pong) as the wedge; (3) on a confirmed wedge flips local state to
 * surface Activate while preserving license_key; (4) never clears a healthy or
 * unverifiable license; (5) is loop-safe (marks done before any work).
 */
class ReconcileMigratedLicenseRoutineTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_options_mock    = [];
		$wp_home_url_mock   = 'https://example.com';
		$wp_transients_mock = [];
	}

	private function invokeWillRun(ReconcileMigratedLicenseRoutine $r): bool
	{
		$m = new \ReflectionMethod($r, 'will_run');
		$m->setAccessible(true);

		return (bool) $m->invoke($r);
	}

	private function invokeIsWedged(ReconcileMigratedLicenseRoutine $r, $ping): bool
	{
		$m = new \ReflectionMethod($r, 'is_wedged');
		$m->setAccessible(true);

		return (bool) $m->invoke($r, $ping);
	}

	/** Routine with a stubbed ping so run() can be tested without HTTP. */
	private function routineWithPing($ping): ReconcileMigratedLicenseRoutine
	{
		return new class ($ping) extends ReconcileMigratedLicenseRoutine {
			private $stub;

			public function __construct($stub)
			{
				$this->stub = $stub;
			}

			protected function probe_ping()
			{
				return $this->stub;
			}
		};
	}

	private function licensedSettings(): array
	{
		return [
			'access_token'   => 'tok-forked',
			'license_key'    => 'ABC-123',
			'license_status' => 'valid',
			'license_info'   => ['license' => 'valid'],
			'website_url'    => 'https://example.com',
		];
	}

	// ---- will_run ----------------------------------------------------------

	public function test_will_run_true_for_licensed_install_not_yet_reconciled(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();

		$this->assertTrue($this->invokeWillRun(new ReconcileMigratedLicenseRoutine()));
	}

	public function test_will_run_false_once_done_flag_set(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();
		$wp_options_mock['sbr_license_reconcile_1585_done'] = time();

		$this->assertFalse($this->invokeWillRun(new ReconcileMigratedLicenseRoutine()));
	}

	public function test_will_run_false_when_status_not_valid(): void
	{
		global $wp_options_mock;
		$s = $this->licensedSettings();
		$s['license_status'] = '';
		$wp_options_mock['sbr_settings'] = $s;

		$this->assertFalse($this->invokeWillRun(new ReconcileMigratedLicenseRoutine()));
	}

	public function test_will_run_false_without_key_or_token(): void
	{
		global $wp_options_mock;
		$noKey = $this->licensedSettings();
		$noKey['license_key'] = '';
		$wp_options_mock['sbr_settings'] = $noKey;
		$this->assertFalse($this->invokeWillRun(new ReconcileMigratedLicenseRoutine()));

		$noToken = $this->licensedSettings();
		$noToken['access_token'] = '';
		$wp_options_mock['sbr_settings'] = $noToken;
		$this->assertFalse($this->invokeWillRun(new ReconcileMigratedLicenseRoutine()));
	}

	// ---- is_wedged ---------------------------------------------------------

	public function test_is_wedged_true_only_for_explicit_license_inactive_with_pong(): void
	{
		$r = new ReconcileMigratedLicenseRoutine();
		$this->assertTrue($this->invokeIsWedged($r, [
			'success' => true, 'pong' => true, 'license_active' => false,
		]));
	}

	public function test_is_wedged_false_for_healthy_or_unverifiable_signals(): void
	{
		$r = new ReconcileMigratedLicenseRoutine();
		// Healthy.
		$this->assertFalse($this->invokeIsWedged($r, ['success' => true, 'pong' => true, 'license_active' => true]));
		// Flag missing (older relay) — must not clear.
		$this->assertFalse($this->invokeIsWedged($r, ['success' => true, 'pong' => true]));
		// Not reachable / no pong.
		$this->assertFalse($this->invokeIsWedged($r, ['success' => true, 'license_active' => false]));
		// Error / unreachable.
		$this->assertFalse($this->invokeIsWedged($r, null));
		$this->assertFalse($this->invokeIsWedged($r, ['success' => false, 'license_active' => false]));
		// Dead/invalid token after migration — relay rejects it, so the call
		// returns an error envelope (success false, no pong). Must NOT be treated
		// as a wedge (the register/reverify path handles a dead token, not this).
		$this->assertFalse($this->invokeIsWedged($r, ['success' => false, 'apiMessage' => 'invalid token']));
		// Malformed relay response: license_active present but null (not a strict
		// false) — must not clear on an ambiguous value.
		$this->assertFalse($this->invokeIsWedged($r, ['success' => true, 'pong' => true, 'license_active' => null]));
		// Garbage / wrong-type payloads must not throw or clear.
		$this->assertFalse($this->invokeIsWedged($r, 'unexpected-string'));
		$this->assertFalse($this->invokeIsWedged($r, ['success' => true, 'pong' => true, 'license_active' => 'false']));
	}

	// ---- run ---------------------------------------------------------------

	public function test_run_flips_to_activate_on_confirmed_wedge_preserving_key(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();
		$wp_options_mock['sbr_statuses'] = ['license_tier' => 3, 'license_info' => 'Pro Elite'];

		$routine = $this->routineWithPing(['success' => true, 'pong' => true, 'license_active' => false]);
		$routine->run();

		$s = $wp_options_mock['sbr_settings'];
		$this->assertSame('', $s['license_status'], 'license_status cleared -> panel surfaces Activate');
		$this->assertSame('', $s['license_info']);
		$this->assertSame('ABC-123', $s['license_key'], 'license_key preserved for one-click re-link');
		$this->assertNotEmpty($wp_options_mock['sbr_license_reconcile_1585_done'], 'done flag set');
		// #482 review: tier must also be cleared in sbr_statuses, else feature
		// gating keeps treating the install as paid while Activate is surfaced.
		$this->assertSame(0, $wp_options_mock['sbr_statuses']['license_tier'], 'license_tier reset so gating drops to free');
	}

	public function test_run_leaves_healthy_license_untouched(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();

		$routine = $this->routineWithPing(['success' => true, 'pong' => true, 'license_active' => true]);
		$routine->run();

		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status'], 'healthy license untouched');
		$this->assertNotEmpty($wp_options_mock['sbr_license_reconcile_1585_done'], 'still one-shot: done flag set');
	}

	public function test_run_does_nothing_on_inconclusive_ping_but_still_marks_done(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();

		$routine = $this->routineWithPing(null); // unreachable / error
		$routine->run();

		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status'], 'never clear on a soft signal');
		$this->assertNotEmpty($wp_options_mock['sbr_license_reconcile_1585_done'], 'done flag set so we do not retry forever');
	}

	/**
	 * Real-world migration case: the forked token was later deleted/revoked on
	 * the relay, so the ping is a 401 error envelope (success false, no pong).
	 * The routine must NOT clear a still-"valid" local license on a relay auth
	 * error — that would log the customer out on a transient/relay-side problem.
	 * A dead token is the register/reverify path's job, not this one.
	 */
	public function test_run_does_not_clear_on_dead_or_revoked_token_error(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = $this->licensedSettings();
		$wp_options_mock['sbr_statuses'] = ['license_tier' => 3];

		$routine = $this->routineWithPing(['success' => false, 'errorId' => 'invalidToken', 'apiMessage' => 'Token is invalid']);
		$routine->run();

		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status'], 'dead-token error must not clear a valid local license');
		$this->assertSame(3, $wp_options_mock['sbr_statuses']['license_tier'], 'tier untouched on a relay auth error');
		$this->assertNotEmpty($wp_options_mock['sbr_license_reconcile_1585_done'], 'still one-shot');
	}

	/**
	 * Real-world case: a backup/migration tool corrupted sbr_settings so it is
	 * no longer an array. Even on a confirmed wedge ping, run() must guard and
	 * no-op rather than fatal on string offset access.
	 */
	public function test_run_survives_corrupted_non_array_settings_on_wedge(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = 'corrupted-not-an-array';

		$routine = $this->routineWithPing(['success' => true, 'pong' => true, 'license_active' => false]);
		$routine->run(); // must not throw

		$this->assertSame('corrupted-not-an-array', $wp_options_mock['sbr_settings'], 'left untouched, no fatal');
		$this->assertNotEmpty($wp_options_mock['sbr_license_reconcile_1585_done'], 'still marked done');
	}
}
