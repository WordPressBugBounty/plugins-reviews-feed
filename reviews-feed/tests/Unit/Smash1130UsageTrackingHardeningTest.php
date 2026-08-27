<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/UsageReporterWpdbDouble.php';

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use SmashBalloon\Reviews\Common\UsageTracking\Config;
use SmashBalloon\Reviews\Common\UsageTracking\Core\RegisterSite;
use SmashBalloon\Reviews\Common\UsageTracking\Core\Sender;
use SmashBalloon\Reviews\Common\UsageTracking\EventRecorder;
use SmashBalloon\Reviews\Common\UsageTracking\Reviews\ReviewsReporter;
use SmashBalloon\Reviews\Common\UsageTracking\SmashUsageTracking;
use SmashBalloon\Reviews\Tests\Unit\Doubles\UsageReporterWpdbDouble;

/**
 * Regression tests for the SMASH-1130 round-3 review findings.
 *
 * 1. Absent consent key: the default is per edition (Pro ON, Free OFF) — a
 *    signed-off decision. The legacy gate returned false on the absent key
 *    and only ever ran on Pro, so Free must never transmit without opt-in.
 * 2. The filterable API URL fronts an outbound POST carrying the full
 *    payload — a filtered value must be https on an allowlisted host.
 * 3. custom_css read `settings['feed_style']`, but feed_style is a table
 *    COLUMN; review_form read `showFooter`, which nothing writes. Both flags
 *    were hardwired false in every payload from every site.
 * 4. Opting out must delete collected data and the site token, not just
 *    unschedule the cron.
 * 5. Persistent send failures must degrade to a monthly cadence, and an
 *    unusable stored token must eventually be dropped and re-registered.
 *
 * @group SMASH-1130
 */
class Smash1130UsageTrackingHardeningTest extends TestCase
{
	/** @var object|null Previous $GLOBALS['wpdb'] — later test files in the suite rely on it persisting, so it is restored rather than unset. */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		global $wp_options_mock, $wp_filter_mock, $wp_transients_mock, $wp_home_url_mock, $wp_http_response_code_mock;
		$wp_options_mock                  = [];
		$wp_filter_mock                   = [];
		$wp_transients_mock               = [];
		$wp_home_url_mock                 = 'https://example.test';
		$wp_http_response_code_mock       = 0;
		$GLOBALS['sbr_test_http_posts']   = [];
		$GLOBALS['sbr_test_actions']      = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock, $wp_filter_mock;
		$wp_options_mock = [];
		$wp_filter_mock  = [];
		if (null === $this->previous_wpdb) {
			unset($GLOBALS['wpdb']);
		} else {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		}
		parent::tearDown();
	}

	// ── 1. Consent default ─────────────────────────────────────────────────

	public function testAbsentConsentKeyDefaultsPerEdition(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['someOtherKey' => true];

		$expected = defined('SBR_PRO') && SBR_PRO;
		$this->assertSame(
			$expected,
			Config::is_enabled(),
			'Absent consent key must default ON for Pro and OFF for Free — Free installs never transmitted before this feature.'
		);
	}

	public function testExplicitConsentAlwaysWins(): void
	{
		global $wp_options_mock;

		$wp_options_mock['sbr_settings'] = ['usagetracking' => false];
		$this->assertFalse(Config::is_enabled());

		$wp_options_mock['sbr_settings'] = ['usagetracking' => true];
		$this->assertTrue(Config::is_enabled());
	}

	public function testSettingsDefaultAgreesWithTheConsentGate(): void
	{
		// The Advanced-tab toggle renders from sbr_plugin_settings_defaults()
		// merged over sbr_settings, while transmission gates on
		// Config::is_enabled(). A static `true` default made the two disagree on
		// Free: the toggle showed ON, and because the whole settings object is
		// posted back, saving ANY setting persisted usagetracking => true and
		// scheduled the report from a site that never opted in.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$defaults = sbr_plugin_settings_defaults();

		$this->assertSame(
			Config::is_enabled(),
			(bool) $defaults['usagetracking'],
			'sbr_plugin_settings_defaults()[usagetracking] must match Config::is_enabled() on the absent key, or the toggle misreports consent and a save writes it.'
		);
	}

	/**
	 * The assertion above pins the two code paths to each other, which is the
	 * bug class — but both sides read Util::sbr_is_pro(), so on its own it would
	 * still pass if the per-edition POLICY were inverted. These two nail the
	 * policy to concrete values. SBR_PRO is a constant, so each edition needs
	 * its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFreeEditionDefaultsConsentOff(): void
	{
		$this->assertFalse(defined('SBR_PRO'), 'Guard: this test only means something in a Free process.');

		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$this->assertFalse(Config::is_enabled(), 'Free must default OFF — it never transmitted before SMASH-1130.');
		$this->assertFalse((bool) sbr_plugin_settings_defaults()['usagetracking'], 'Free toggle must render OFF.');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testProEditionDefaultsConsentOn(): void
	{
		define('SBR_PRO', true);

		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$this->assertTrue(Config::is_enabled(), 'Pro must default ON — the toggle has always displayed as ON there.');
		$this->assertTrue((bool) sbr_plugin_settings_defaults()['usagetracking'], 'Pro toggle must render ON.');

		// Pro is the edition that actually reaches Scheduler::schedule() on save.
		$from_builder = sbr_recursive_parse_args(
			get_option('sbr_settings', []),
			sbr_plugin_settings_defaults()
		);
		(new SettingsManagerService())->update_settings($from_builder);

		$this->assertTrue(Config::is_enabled(), 'A Pro save must not drop consent that defaulted ON.');
	}

	public function testSavingUnrelatedSettingDoesNotOptFreeIn(): void
	{
		// Reproduces the reachable path: admin opens Settings on a Free install
		// where consent was never set, changes something else, and saves the
		// object the builder handed the UI.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$from_builder = sbr_recursive_parse_args(
			get_option('sbr_settings', []),
			sbr_plugin_settings_defaults()
		);
		$from_builder['optimize_images'] = false;

		(new SettingsManagerService())->update_settings($from_builder);

		$this->assertSame(
			defined('SBR_PRO') && SBR_PRO,
			Config::is_enabled(),
			'Saving an unrelated setting must not flip usage-tracking consent for the edition.'
		);
	}

	public function testPayloadConsentFieldUsesTheHelper(): void
	{
		// The payload must never assert usagetracking:false while
		// transmitting — the field has to agree with the gate.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [];

		$reporter = new ReviewsReporter();
		$method   = new \ReflectionMethod($reporter, 'get_global_settings');
		$snapshot = $method->invoke($reporter);

		$this->assertSame(Config::is_enabled(), $snapshot['usagetracking']);
	}

	// ── 2. API URL validation ──────────────────────────────────────────────

	public function testFilteredApiUrlRejectsCleartextHttp(): void
	{
		global $wp_filter_mock;
		$wp_filter_mock['sbr_smash_usage_tracking_api_url'] = 'http://usage.smashballoon.com/api';

		$this->assertSame(SBR_SMASH_USAGE_TRACKING_API_URL, Config::get_api_url());
	}

	public function testFilteredApiUrlRejectsForeignHost(): void
	{
		global $wp_filter_mock;
		$wp_filter_mock['sbr_smash_usage_tracking_api_url'] = 'https://evil.example.com/api';

		$this->assertSame(SBR_SMASH_USAGE_TRACKING_API_URL, Config::get_api_url());
	}

	public function testFilteredApiUrlRejectsLookalikeHost(): void
	{
		global $wp_filter_mock;
		$wp_filter_mock['sbr_smash_usage_tracking_api_url'] = 'https://evilsmashballoon.com/api';

		$this->assertSame(SBR_SMASH_USAGE_TRACKING_API_URL, Config::get_api_url());
	}

	public function testFilteredApiUrlAllowsHttpsSmashballoonSubdomain(): void
	{
		global $wp_filter_mock;
		$wp_filter_mock['sbr_smash_usage_tracking_api_url'] = 'https://staging.smashballoon.com/api';

		$this->assertSame('https://staging.smashballoon.com/api', Config::get_api_url());
	}

	public function testEmptyFilteredUrlIsAKillSwitchAndSkipsTheRequest(): void
	{
		global $wp_filter_mock;
		$wp_filter_mock['sbr_smash_usage_tracking_api_url'] = '';

		$this->assertSame('', Config::get_api_url());

		$sender = new Sender();
		$this->assertSame(0, $sender->send(['any' => 'payload']));
		$this->assertSame([], $GLOBALS['sbr_test_http_posts'], 'Kill switch must prevent the HTTP request entirely.');
	}

	// ── 3. Feature flags ───────────────────────────────────────────────────

	public function testCustomCssFlagReadsTheFeedStyleColumn(): void
	{
		$wpdb                = new UsageReporterWpdbDouble();
		$wpdb->next_results  = [
			['feed_name' => 'Feed A', 'settings' => '{}', 'feed_style' => '.sbr { color: red; }'],
		];
		$GLOBALS['wpdb']     = $wpdb;

		$reporter = new ReviewsReporter();
		$data     = (new \ReflectionMethod($reporter, 'get_all_feed_data'))->invoke($reporter);
		$flags    = (new \ReflectionMethod($reporter, 'get_features_enabled'))->invoke($reporter, $data);

		$this->assertStringContainsString('feed_style', (string) $wpdb->last_get_results_sql, 'feed_style is a column, not a settings key — it must be selected.');
		$this->assertTrue($flags['custom_css']);
		$this->assertSame(strlen('.sbr { color: red; }'), $data[0]['custom_css_length'], 'Only the CSS length may be carried, never the CSS itself.');
	}

	/**
	 * The double used to return the probed table name for every get_var(), so a real
	 * COUNT(*) came back as (int) 0 and an assertion on connected_count would have
	 * passed against the wrong value. Asserting a non-zero count is what keeps the
	 * double honest.
	 */
	public function testSourcesSummaryReportsTheRealConnectedCount(): void
	{
		$wpdb              = new UsageReporterWpdbDouble();
		$wpdb->next_count  = 7;
		$wpdb->next_results = [
			['provider' => 'google', 'cnt' => 4],
			['provider' => 'yelp', 'cnt' => 3],
		];
		$GLOBALS['wpdb']   = $wpdb;

		$reporter = new ReviewsReporter();
		$summary  = (new \ReflectionMethod($reporter, 'get_sources_summary'))->invoke($reporter);

		$this->assertSame(7, $summary['connected_count'], 'A COUNT(*) must reach the reporter as a number, not the probed table name.');
	}

	public function testCustomCssFlagFalseWithoutCss(): void
	{
		$wpdb               = new UsageReporterWpdbDouble();
		$wpdb->next_results = [
			['feed_name' => 'Feed A', 'settings' => '{"layout":"masonry"}', 'feed_style' => ''],
		];
		$GLOBALS['wpdb']    = $wpdb;

		$reporter = new ReviewsReporter();
		$data     = (new \ReflectionMethod($reporter, 'get_all_feed_data'))->invoke($reporter);
		$flags    = (new \ReflectionMethod($reporter, 'get_features_enabled'))->invoke($reporter, $data);

		$this->assertFalse($flags['custom_css']);
		$this->assertTrue($flags['masonry_layout'], 'Control: feed-level flags from settings JSON must still work.');
	}

	public function testReviewFormFlagReadsConnectedFormsOption(): void
	{
		global $wp_options_mock;

		$reporter = new ReviewsReporter();
		$method   = new \ReflectionMethod($reporter, 'get_features_enabled');

		$flags = $method->invoke($reporter, []);
		$this->assertFalse($flags['review_form']);

		$wp_options_mock['sb_connected_forms'] = [17 => ['form_id' => 17, 'provider' => 'wpforms']];
		$flags                                 = $method->invoke($reporter, []);
		$this->assertTrue($flags['review_form'], 'review_form must key off the Forms-integration marker, not the unwritten showFooter setting.');
	}

	// ── 4. Opt-out purge ───────────────────────────────────────────────────

	public function testOptOutPurgesCollectedDataAndSiteToken(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings']                    = ['usagetracking' => true];
		$wp_options_mock[EventRecorder::OPTION_NAME]        = ['feed_saved' => ['count' => 3, 'last_date' => '2026-08-01']];
		$wp_options_mock[Config::OPTION_ACTIVE_DATES]       = ['2026-08-01'];
		$wp_options_mock[Config::OPTION_SESSION_DURATIONS]  = [120, 340];
		$wp_options_mock[Config::OPTION_SITE_TOKEN]         = 'token-1234567890';
		$wp_options_mock[Config::OPTION_TRACKING]           = ['last_send' => 1754000000];

		(new SettingsManagerService())->update_settings(['usagetracking' => false]);

		foreach (
			[
			EventRecorder::OPTION_NAME,
			Config::OPTION_ACTIVE_DATES,
			Config::OPTION_SESSION_DURATIONS,
			Config::OPTION_SITE_TOKEN,
			Config::OPTION_TRACKING,
			] as $key
		) {
			$this->assertArrayNotHasKey($key, $wp_options_mock, "Opt-out must delete {$key} — disabling cannot be weaker than uninstalling.");
		}
	}

	// ── 5. Token validation & failure backoff ─────────────────────────────

	public function testTokenValidatorRejectsOversizeAndJunk(): void
	{
		$this->assertTrue(RegisterSite::is_valid_token('tok_A1b2C3d4-e5.f6'));
		$this->assertFalse(RegisterSite::is_valid_token('short'), 'Under 8 chars.');
		$this->assertFalse(RegisterSite::is_valid_token(str_repeat('a', 192)), 'Over 191 chars — an oversize token inflates every payload past the size cap.');
		$this->assertFalse(RegisterSite::is_valid_token("abc\ndef123"), 'Control characters.');
		$this->assertFalse(RegisterSite::is_valid_token('abc def12345'), 'Whitespace.');
	}

	public function testBackoffSkipsTheNetworkAfterConsecutiveFailures(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings']          = ['usagetracking' => true];
		$wp_options_mock[Config::OPTION_TRACKING] = [
			'last_send'            => 0,
			'last_attempt'         => time() - 2 * DAY_IN_SECONDS,
			'last_status'          => 422,
			'consecutive_failures' => SmashUsageTracking::FAILURE_BACKOFF_THRESHOLD,
		];

		(new SmashUsageTracking())->send_checkin();

		$this->assertSame([], $GLOBALS['sbr_test_http_posts'], 'A permanently-rejected site must degrade to monthly, not hammer weekly.');
	}

	public function testBackoffStillRetriesAfterAMonth(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings']          = ['usagetracking' => true];
		$wp_options_mock[Config::OPTION_TRACKING] = [
			'last_send'            => 0,
			'last_attempt'         => time() - 28 * DAY_IN_SECONDS,
			'last_status'          => 422,
			'consecutive_failures' => SmashUsageTracking::FAILURE_BACKOFF_THRESHOLD,
		];

		(new SmashUsageTracking())->send_checkin();

		$this->assertCount(1, $GLOBALS['sbr_test_http_posts'], 'After the monthly window the site must attempt again (here: the register-site call).');
	}
}
