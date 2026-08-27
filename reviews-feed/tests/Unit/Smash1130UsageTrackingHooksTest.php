<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\UsageTracking\Config;
use SmashBalloon\Reviews\Common\UsageTracking\EventRecorder;
use SmashBalloon\Reviews\Common\UsageTracking\SmashUsageTracking;

/**
 * Regression tests for SMASH-1130 review findings.
 *
 * 1. The usage-tracking cron callback must be attached whenever the service
 *    container registers the tracker. On Pro, Pro\ServiceContainer::register()
 *    overrode Common's without calling parent::register() — the only place
 *    SmashUsageTracking is registered — so no Pro site ever attached
 *    Config::CRON_HOOK and zero reports were sent, while the settings toggle
 *    still scheduled a cron event with no callback. These tests pin
 *    register()'s hook wiring; the container test asserts the parent call.
 *
 * 2. The event listeners run at wp_ajax priority 5 — BEFORE the primary
 *    handler's nonce/capability checks — so they must replicate those checks
 *    (non-dying) before writing options, or any logged-in user can inflate
 *    event counters via bare admin-ajax POSTs.
 *
 * 3. Provider-specific source_connected events derive the provider from the
 *    hook name: most add-source endpoints never send $_POST['provider'].
 *
 * @group SMASH-1130
 * @covers \SmashBalloon\Reviews\Common\UsageTracking\SmashUsageTracking
 */
class Smash1130UsageTrackingHooksTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$wp_options_mock                             = ['sbr_settings' => ['usagetracking' => true]];
		$GLOBALS['sbr_test_actions']                 = [];
		$GLOBALS['sbr_test_nonce_ok']                = false;
		$GLOBALS['sbr_test_user_can']                = false;
		$GLOBALS['sbr_test_current_action']          = '';
		$GLOBALS['sbr_test_nonce_actions_checked']   = [];
		$GLOBALS['sbr_test_caps_checked']            = [];
		$_POST                                       = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$wp_options_mock = [];
		$GLOBALS['sbr_test_actions'] = [];
		$_POST = [];
		parent::tearDown();
	}

	public function testRegisterAttachesCronCallback(): void
	{
		(new SmashUsageTracking())->register();

		$this->assertArrayHasKey(
			Config::CRON_HOOK,
			$GLOBALS['sbr_test_actions'],
			'Cron hook must have a callback attached, or scheduled events fire into the void.'
		);
		$this->assertSame('send_checkin', $GLOBALS['sbr_test_actions'][Config::CRON_HOOK][0]['callback'][1]);
	}

	public function testSourceListenersAttachAtPriorityFive(): void
	{
		// The whole guard design depends on the listeners running BEFORE the
		// primary handler (priority 10) — pin the priority explicitly.
		(new SmashUsageTracking())->register();

		$reg = $GLOBALS['sbr_test_actions']['wp_ajax_sbr_feed_saver_manager_add_source'][0];
		$this->assertSame(5, $reg['priority']);
		$this->assertSame('on_source_connected', $reg['callback'][1]);
	}

	public function testGuardChecksTheSbrAdminNonceAction(): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = 1;
		$GLOBALS['sbr_test_user_can'] = true;

		$tracking = new SmashUsageTracking();
		$tracking->on_feed_saved();

		$this->assertContains(
			'sbr-admin',
			$GLOBALS['sbr_test_nonce_actions_checked'],
			'The guard must verify the same nonce action the primary handlers use.'
		);
	}

	public function testRegisterAttachesAllSourceConnectionHooks(): void
	{
		(new SmashUsageTracking())->register();

		$expected = [
			'wp_ajax_sbr_feed_saver_manager_add_source',
			'wp_ajax_sbr_feed_saver_manager_add_facebook_source',
			'wp_ajax_sbr_feed_saver_manager_add_facebook_souce',
			'wp_ajax_sbr_feed_saver_manager_connect_manual_facebook',
			'wp_ajax_sbr_add_woocommerce_source',
			'wp_ajax_sbr_add_woocommerce_source_multi',
			'wp_ajax_sbr_add_edd_source',
			'wp_ajax_sbr_add_edd_source_multi',
			'wp_ajax_sbr_add_airbnb_source',
			'wp_ajax_sbr_add_booking_source',
			'wp_ajax_sbr_add_aliexpress_source',
			'wp_ajax_sbr_add_external_source',
		];
		foreach ($expected as $hook) {
			$this->assertArrayHasKey($hook, $GLOBALS['sbr_test_actions'], "Missing listener for {$hook}");
		}
	}

	public function testProServiceContainerCallsParentRegister(): void
	{
		// Pro\ServiceContainer::register() must reach Common's register(),
		// whose service loop registers SmashUsageTracking. Asserted structurally
		// (source inspection) because registering the full container would
		// boot ~40 services with un-stubbed WP dependencies.
		$method = new \ReflectionMethod(\SmashBalloon\Reviews\Pro\ServiceContainer::class, 'register');
		$file   = $method->getFileName();
		$lines  = array_slice(
			file($file),
			$method->getStartLine() - 1,
			$method->getEndLine() - $method->getStartLine() + 1
		);
		// Strip comments so a commented-out call cannot satisfy the assertion. Block
		// comments go first and across lines: stripping `//` alone left
		// `/* parent::register(); */` matching, which was measured passing while the
		// real call was commented out.
		$body = (string) preg_replace('#/\*.*?\*/#s', '', implode('', $lines));
		$body = implode('', array_map(static function ($line) {
			return preg_replace('/(\/\/|#).*$/', '', $line);
		}, explode("\n", $body)));

		$this->assertMatchesRegularExpression(
			'/parent::register\(\)\s*;/',
			$body,
			'Pro\ServiceContainer::register() must call parent::register(); it is the only path that registers SmashUsageTracking.'
		);
	}

	public function testListenerRejectsRequestWithoutValidNonce(): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = false;
		$GLOBALS['sbr_test_user_can'] = true;

		$tracking = new SmashUsageTracking();
		$tracking->on_feed_saved();
		$tracking->on_source_connected();

		global $wp_options_mock;
		$this->assertArrayNotHasKey(
			EventRecorder::OPTION_NAME,
			$wp_options_mock,
			'A request failing the nonce check must not write event options.'
		);
	}

	public function testListenerRejectsRequestWithoutCapability(): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = 1;
		$GLOBALS['sbr_test_user_can'] = false;

		$tracking = new SmashUsageTracking();
		$tracking->on_feed_saved();

		global $wp_options_mock;
		$this->assertArrayNotHasKey(EventRecorder::OPTION_NAME, $wp_options_mock);
	}

	public function testVerifiedRequestRecordsFeedSaved(): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = 1;
		$GLOBALS['sbr_test_user_can'] = true;

		$tracking = new SmashUsageTracking();
		$tracking->on_feed_saved();

		global $wp_options_mock;
		$this->assertSame(1, $wp_options_mock[EventRecorder::OPTION_NAME]['feed_saved']['count']);
	}

	public function testProviderDerivedFromHookNameWithoutPostField(): void
	{
		$GLOBALS['sbr_test_nonce_ok']       = 1;
		$GLOBALS['sbr_test_user_can']       = true;
		$GLOBALS['sbr_test_current_action'] = 'wp_ajax_sbr_add_airbnb_source';

		$tracking = new SmashUsageTracking();
		$tracking->on_source_connected();

		global $wp_options_mock;
		$events = $wp_options_mock[EventRecorder::OPTION_NAME];
		$this->assertSame(1, $events['source_connected']['count']);
		$this->assertSame(1, $events['source_connected_airbnb']['count'], 'Airbnb sends no $_POST[provider]; the hook name must supply it.');
	}

	public function testResetAfterSendPreservesSessionsRecordedDuringSendWindow(): void
	{
		global $wp_options_mock;
		// [10,20,30] existed when the payload was built; 99 arrived while the
		// request was in flight. Only the reported three may be dropped.
		$wp_options_mock[Config::OPTION_SESSION_DURATIONS] = [10, 20, 30, 99];

		$tracking = new SmashUsageTracking();
		$method   = new \ReflectionMethod($tracking, 'reset_events_after_send');
		$method->invoke($tracking, [], [10, 20, 30]);

		$this->assertSame(
			[99],
			$wp_options_mock[Config::OPTION_SESSION_DURATIONS],
			'Sessions recorded during the send window must roll over, not be wiped.'
		);
	}

	public function testResetAfterSendSurvivesTheTenEntryCap(): void
	{
		global $wp_options_mock;
		// 10 durations were reported; 3 more arrived during the send and the
		// store's keep-last-10 cap displaced the 3 oldest reported entries.
		// A count-based slice would wrongly delete the 3 new sessions here.
		$reported                                          = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
		$wp_options_mock[Config::OPTION_SESSION_DURATIONS] = [4, 5, 6, 7, 8, 9, 10, 101, 102, 103];

		$tracking = new SmashUsageTracking();
		$method   = new \ReflectionMethod($tracking, 'reset_events_after_send');
		$method->invoke($tracking, [], $reported);

		$this->assertSame(
			[101, 102, 103],
			$wp_options_mock[Config::OPTION_SESSION_DURATIONS],
			'The multiset diff must keep unreported sessions even when the cap displaced reported ones.'
		);
	}

	public function testGenericHookFallsBackToVerifiedPostProvider(): void
	{
		$GLOBALS['sbr_test_nonce_ok']       = 1;
		$GLOBALS['sbr_test_user_can']       = true;
		$GLOBALS['sbr_test_current_action'] = 'wp_ajax_sbr_feed_saver_manager_add_source';
		$_POST['provider']                  = 'google';

		$tracking = new SmashUsageTracking();
		$tracking->on_source_connected();

		global $wp_options_mock;
		$this->assertSame(1, $wp_options_mock[EventRecorder::OPTION_NAME]['source_connected_google']['count']);
	}
}
