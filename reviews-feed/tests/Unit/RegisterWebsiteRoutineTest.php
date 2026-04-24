<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\RegisterWebsiteRoutine;

/**
 * Regression coverage for the rate-limit transient that bounds /auth/register
 * traffic per site to 1 call / 5 minutes regardless of upstream loop behaviour.
 *
 * @see SMASH-1274 — the earlier 2.5.3 version of this routine cleared the
 *      transient on successful registration, which defeated the limiter during
 *      the migration-detect loop (token wipe + successful re-register +
 *      transient clear repeated every request). 2.5.5 keeps the transient
 *      active regardless of outcome.
 */
class RegisterWebsiteRoutineTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_transients_mock, $wp_home_url_mock;
		$wp_options_mock    = [];
		$wp_transients_mock = [];
		$wp_home_url_mock   = 'https://example.com';
	}

	/** Exposes protected will_run() for direct testing. */
	private function will_run(RegisterWebsiteRoutine $routine): bool
	{
		$reflection = new \ReflectionClass($routine);
		$method = $reflection->getMethod('will_run');
		$method->setAccessible(true);
		return (bool) $method->invoke($routine);
	}

	public function test_will_run_returns_true_when_no_token_and_no_cooldown(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$this->assertTrue($this->will_run(new RegisterWebsiteRoutine()));
	}

	public function test_will_run_returns_false_when_token_is_present(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['access_token' => 'tok-abc'];

		$this->assertFalse($this->will_run(new RegisterWebsiteRoutine()));
	}

	/**
	 * Core regression for the 2.5.5 fix — when the cooldown transient is set,
	 * will_run() must return false even though the access_token is empty. If
	 * it doesn't, the migration-detect loop (wipe → re-register → repeat)
	 * will hammer /auth/register regardless of the rate-limit intent.
	 */
	public function test_will_run_returns_false_when_cooldown_transient_is_set(): void
	{
		global $wp_options_mock, $wp_transients_mock;
		$wp_options_mock['sbr_settings'] = []; // no access_token
		$wp_transients_mock['sbr_register_retry_cooldown'] = time();

		$this->assertFalse($this->will_run(new RegisterWebsiteRoutine()));
	}

	public function test_will_run_returns_true_on_corrupted_settings(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = 'not-an-array'; // simulate corruption

		$this->assertTrue($this->will_run(new RegisterWebsiteRoutine()));
	}
}
