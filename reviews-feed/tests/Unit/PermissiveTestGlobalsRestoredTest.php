<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A fixture that grants itself a nonce and a capability must hand them back.
 *
 * `$GLOBALS['sbr_test_nonce_ok']` and `$GLOBALS['sbr_test_user_can']` are
 * PERMISSIVE: left set after a class finishes, any later test that expects a
 * nonce or capability check to FAIL passes for the wrong reason. The suite runs
 * in one process, so that is a real leak — and one that only shows up under
 * `--order-by=random`, sometimes.
 *
 * Driven by invoking the fixture's own lifecycle rather than by test ORDER: an
 * order-dependent probe passes vacuously whenever it happens to run first, which
 * is the kind of test that reads as coverage and is not.
 */
final class PermissiveTestGlobalsRestoredTest extends TestCase
{
	/**
	 * Classes that set those globals in setUp, with the case each one runs.
	 *
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function fixtureProvider(): array
	{
		return array(
			'Smash2028 collection scalar json_data' => array(
				Smash2028CollectionScalarJsonDataTest::class,
				'test_the_permissive_auth_globals_are_declared',
			),
		);
	}

	/**
	 * @dataProvider fixtureProvider
	 *
	 * @param string $class Fixture class name.
	 * @param string $case  Any case name; PHPUnit needs one to construct.
	 *
	 * @return void
	 */
	public function test_the_fixture_restores_what_it_granted($class, $case): void
	{
		$keys = $class::AUTH_GLOBALS;

		$this->assertNotEmpty($keys, 'the fixture must name the globals it sets');

		// Start from "not set", which is the state the rest of the suite expects.
		foreach ($keys as $key) {
			unset($GLOBALS[ $key ]);
		}

		$fixture  = new $class($case);
		$setUp    = new ReflectionMethod($fixture, 'setUp');
		$tearDown = new ReflectionMethod($fixture, 'tearDown');

		$setUp->setAccessible(true);
		$tearDown->setAccessible(true);

		$setUp->invoke($fixture);

		// Proves the fixture really does grant them — otherwise the assertion
		// after tearDown would pass without anything having been restored.
		foreach ($keys as $key) {
			$this->assertTrue($GLOBALS[ $key ] ?? false, $key . ' must be granted by setUp');
		}

		$tearDown->invoke($fixture);

		foreach ($keys as $key) {
			$this->assertArrayNotHasKey($key, $GLOBALS, $key . ' must not outlive the fixture');
		}
	}

	/**
	 * And a prior value is put back, not just unset.
	 *
	 * @return void
	 */
	public function test_a_prior_value_survives_the_fixture(): void
	{
		$GLOBALS['sbr_test_user_can'] = false;

		$fixture  = new Smash2028CollectionScalarJsonDataTest('test_the_permissive_auth_globals_are_declared');
		$setUp    = new ReflectionMethod($fixture, 'setUp');
		$tearDown = new ReflectionMethod($fixture, 'tearDown');

		$setUp->setAccessible(true);
		$tearDown->setAccessible(true);

		$setUp->invoke($fixture);
		$tearDown->invoke($fixture);

		$this->assertFalse($GLOBALS['sbr_test_user_can'], 'a prior false must come back false, not vanish');

		unset($GLOBALS['sbr_test_user_can']);
	}
}
