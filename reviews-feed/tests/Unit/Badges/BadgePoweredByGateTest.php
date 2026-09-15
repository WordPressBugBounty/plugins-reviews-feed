<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;
use SmashBalloon\Reviews\Common\Util;

/**
 * Badge "Powered by Smash Balloon" tier gate (SBR-163).
 *
 * Branding removal is a Pro Plus capability, matching
 * SBR_Review_Alert_Service::save_popup_ajax(), which forces
 * `show_powered_by = true` for anyone below Pro Plus
 * (class/Common/ReviewAlerts/SBR_Review_Alert_Service.php:837-845).
 *
 * The tier is passed in rather than read from the licence, because `SBR_PRO` is
 * a constant defined by the Pro build and a test cannot toggle it. Reading the
 * licence directly would leave the Pro Plus branch unreachable, and a gate
 * wrongly written against sbr_is_pro() would pass every assertion here — the
 * exact regression this file exists to catch.
 *
 * Matrix (tier x requested value):
 *
 *   below Pro Plus | false   -> ON  (forced)
 *   below Pro Plus | true    -> ON
 *   below Pro Plus | absent  -> ON
 *   Pro Plus       | false   -> OFF (choice honoured)
 *   Pro Plus       | true    -> ON
 *   Pro Plus       | absent  -> OFF (default is off, and must not warn)
 */
class BadgePoweredByGateTest extends TestCase
{
	/** @var mixed The option store as it was before this class emptied it. */
	private $saved_options = null;

	protected function setUp(): void
	{
		parent::setUp();

		global $wp_options_mock;

		// Saved, not just emptied: this is a process-wide store, and a test that
		// relies on something the bootstrap or an earlier class put there would
		// get an empty one purely because it ran after this file.
		$this->saved_options = $wp_options_mock;

		$wp_options_mock = array();
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;

		$wp_options_mock = $this->saved_options;

		parent::tearDown();
	}

	/**
	 * Invoke the protected gate with an explicit tier.
	 *
	 * @param array     $args        Sanitised argument array.
	 * @param bool|null $is_pro_plus Tier override.
	 *
	 * @return bool
	 */
	private function gate($args, $is_pro_plus = null)
	{
		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'show_powered_by');
		$method->setAccessible(true);

		return (bool) $method->invoke(null, $args, $is_pro_plus);
	}

	/**
	 * Below Pro Plus the attribution is not negotiable, whatever is asked for.
	 *
	 * @dataProvider requestedValueProvider
	 *
	 * @param array $args Argument array under test.
	 *
	 * @return void
	 */
	public function test_below_pro_plus_always_shows_attribution($args)
	{
		$this->assertTrue(
			$this->gate($args, false),
			'Below Pro Plus the "Powered by" attribution must always render.'
		);
	}

	/**
	 * Pro Plus may hide it, so every requested value must survive intact.
	 *
	 * @dataProvider proPlusExpectationProvider
	 *
	 * @param array $args     Argument array under test.
	 * @param bool  $expected Expected gate result.
	 *
	 * @return void
	 */
	public function test_pro_plus_honours_the_requested_value($args, $expected)
	{
		$this->assertSame(
			$expected,
			$this->gate($args, true),
			'Pro Plus must get exactly the attribution setting it asked for.'
		);
	}

	/**
	 * @return array<string, array{0: array<string, bool>}>
	 */
	public static function requestedValueProvider()
	{
		return array(
			'explicitly disabled' => array( array( 'powered_by' => false ) ),
			'explicitly enabled'  => array( array( 'powered_by' => true ) ),
			'key absent entirely' => array( array() ),
		);
	}

	/**
	 * @return array<string, array{0: array<string, bool>, 1: bool}>
	 */
	public static function proPlusExpectationProvider()
	{
		return array(
			'disabled stays disabled' => array( array( 'powered_by' => false ), false ),
			'enabled stays enabled'   => array( array( 'powered_by' => true ), true ),
			// A widget instance saved before this option existed has no key at
			// all. It must default to off WITHOUT emitting an "Undefined array
			// key" warning — phpunit.xml sets failOnWarning, so a regression
			// here fails the suite rather than passing quietly.
			'key absent defaults off' => array( array(), false ),
		);
	}

	/**
	 * Pro Basic (tier 1) must behave like Free. Gating on sbr_is_pro() instead
	 * of sbr_is_pro_plus() would let a Pro Basic site strip badge attribution
	 * while review alerts still force it — the bug this pins.
	 *
	 * @return void
	 */
	public function test_pro_basic_does_not_satisfy_the_pro_plus_check()
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_statuses'] = array( 'license_tier' => 1 );

		$this->assertFalse(
			Util::sbr_is_pro_plus(),
			'Tier 1 is Pro Basic and must not satisfy the Pro Plus check.'
		);

		// ...and the gate fed that answer keeps the attribution.
		$this->assertTrue(
			$this->gate(array( 'powered_by' => false ), Util::sbr_is_pro_plus()),
			'Pro Basic must still carry the attribution.'
		);
	}

	/**
	 * The discriminating test: with a real Pro licence at tier 1 (Pro Basic),
	 * the gate must STILL force the attribution.
	 *
	 * Runs in a separate process so `SBR_PRO` can be defined without leaking
	 * into the rest of the suite. Without that constant both sbr_is_pro() and
	 * sbr_is_pro_plus() return false, the two are indistinguishable, and a gate
	 * wrongly written against sbr_is_pro() passes every other assertion in this
	 * file — verified by mutation: swapping the call kept the suite green until
	 * this test existed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_pro_basic_with_a_real_licence_still_shows_attribution()
	{
		if (! defined('SBR_PRO')) {
			define('SBR_PRO', true);
		}

		global $wp_options_mock;
		$wp_options_mock['sbr_statuses'] = array( 'license_tier' => 1 );

		$this->assertTrue(
			Util::sbr_is_pro(),
			'Guard: this test needs the Pro build path active.'
		);
		$this->assertFalse(
			Util::sbr_is_pro_plus(),
			'Tier 1 is Pro Basic and must not satisfy the Pro Plus check.'
		);

		// No tier argument: exercises the production default, so a gate written
		// against sbr_is_pro() would hide the attribution here and fail.
		$this->assertTrue(
			$this->gate(array( 'powered_by' => false )),
			'Pro Basic asked to hide the attribution and must be refused.'
		);
	}

	/**
	 * The complement: at tier 2 (Pro Plus) the same call must honour the choice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function test_pro_plus_with_a_real_licence_can_hide_attribution()
	{
		if (! defined('SBR_PRO')) {
			define('SBR_PRO', true);
		}

		global $wp_options_mock;
		$wp_options_mock['sbr_statuses'] = array( 'license_tier' => 2 );

		$this->assertTrue(Util::sbr_is_pro_plus(), 'Guard: tier 2 is Pro Plus.');

		$this->assertFalse(
			$this->gate(array( 'powered_by' => false )),
			'Pro Plus asked to hide the attribution and must be allowed.'
		);
	}

	/**
	 * The production default must resolve the tier from the licence rather than
	 * silently defaulting to "allowed". Omitting the argument on a build with no
	 * Pro Plus licence has to keep the attribution on.
	 *
	 * @return void
	 */
	public function test_default_argument_resolves_the_real_licence()
	{
		$this->assertSame(
			! Util::sbr_is_pro_plus(),
			$this->gate(array( 'powered_by' => false )),
			'With no tier passed the gate must follow Util::sbr_is_pro_plus().'
		);
	}
}
