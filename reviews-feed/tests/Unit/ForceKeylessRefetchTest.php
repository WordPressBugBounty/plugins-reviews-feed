<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Utils\FreeRetriever as CommonFreeRetriever;
use SmashBalloon\Reviews\Pro\Utils\FreeRetriever as ProFreeRetriever;

/**
 * Pins the keyless "force one-time refetch on Clear All Caches" behaviour.
 *
 * Background: a keyless Google/Yelp/TripAdvisor/Trustpilot source that already
 * has cached reviews is gated by the plugin's local "already fetched" belt
 * (FreeRetriever::limit_review_api_call -> SBR_Sources::already_fetched /
 * already_fetched_week). Clear All Caches resets the relay's SERVER-side weekly
 * window but keeps the cached rows, so before this fix the immediate refresh
 * still skipped the keyless relay call and the feed could never refetch without
 * manual row deletion.
 *
 * The fix is a short-lived, TTL-bounded transient flag the belt honours (a
 * time window, NOT a single-use token — it is not consumed per call; the
 * relay's own weekly window is the real per-source cap). It is NON-destructive
 * (cached reviews stay, so a failed refetch keeps the existing feed) and BC-safe
 * (when the flag is unset, the belt behaves exactly as before).
 *
 * Behaviour tests exercise should_force_refetch() directly (it only reads the
 * transient, so no $wpdb is needed). The full limit_review_api_call() path hits
 * SBR_Sources static DB queries that the plain-PHPUnit suite can't provide, so
 * the wiring of `&& ! self::should_force_refetch()` into both belts — and the
 * flag-set in clear_all_caches() — is protected by source-level guards, matching
 * the ClearCacheRelayResetTest convention.
 */
final class ForceKeylessRefetchTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Isolate the transient mock between cases.
		global $wp_transients_mock;
		$wp_transients_mock = [];
	}

	protected function tearDown(): void
	{
		global $wp_transients_mock;
		$wp_transients_mock = [];
		parent::tearDown();
	}

	private static function invokeShouldForceRefetch(string $class): bool
	{
		$m = new \ReflectionMethod($class, 'should_force_refetch');
		$m->setAccessible(true);

		return (bool) $m->invoke(null);
	}

	private static function commonSource(): string
	{
		$path = __DIR__ . '/../../class/Common/Utils/FreeRetriever.php';
		self::assertFileExists($path);

		return (string) file_get_contents($path);
	}

	private static function proSource(): string
	{
		$path = __DIR__ . '/../../class/Pro/Utils/FreeRetriever.php';
		self::assertFileExists($path);

		return (string) file_get_contents($path);
	}

	private static function saverSource(): string
	{
		$path = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		self::assertFileExists($path);

		return (string) file_get_contents($path);
	}

	// ---- Behaviour: should_force_refetch() reflects the transient ----

	public function test_should_force_refetch_is_false_when_flag_unset(): void
	{
		$this->assertFalse(
			self::invokeShouldForceRefetch(CommonFreeRetriever::class),
			'With no flag set the belt must stay closed (normal/BC behaviour).'
		);
	}

	public function test_should_force_refetch_is_true_when_flag_set(): void
	{
		set_transient(CommonFreeRetriever::FORCE_REFETCH_FLAG, 1, 300);

		$this->assertTrue(
			self::invokeShouldForceRefetch(CommonFreeRetriever::class),
			'Setting the flag must open the belt for a one-time refetch.'
		);
	}

	public function test_should_force_refetch_is_false_after_flag_deleted(): void
	{
		set_transient(CommonFreeRetriever::FORCE_REFETCH_FLAG, 1, 300);
		delete_transient(CommonFreeRetriever::FORCE_REFETCH_FLAG);

		$this->assertFalse(
			self::invokeShouldForceRefetch(CommonFreeRetriever::class),
			'Once the flag expires or is cleared the belt must close again — no permanent bypass.'
		);
	}

	public function test_flag_stays_open_for_multiple_reads_within_window(): void
	{
		// The flag is a TTL window, not a single-use token: it is NOT consumed on
		// read, so every source in a multi-source refresh within the window gets
		// the bypass. (Cost is bounded by the relay's per-source weekly re-close,
		// not by consuming this flag.) Pin that contract so a future "make it
		// one-shot" change can't silently break multi-source refetch.
		set_transient(CommonFreeRetriever::FORCE_REFETCH_FLAG, 1, 300);

		$this->assertTrue(self::invokeShouldForceRefetch(CommonFreeRetriever::class), 'first read');
		$this->assertTrue(self::invokeShouldForceRefetch(CommonFreeRetriever::class), 'second read');
		$this->assertTrue(self::invokeShouldForceRefetch(ProFreeRetriever::class), 'third read (Pro)');
	}

	public function test_pro_inherits_should_force_refetch_and_the_same_flag(): void
	{
		// Pro extends Common; self::should_force_refetch() / self::FORCE_REFETCH_FLAG
		// in the Pro belt must resolve to the inherited member, not a separate one.
		set_transient(CommonFreeRetriever::FORCE_REFETCH_FLAG, 1, 300);

		$this->assertSame(
			CommonFreeRetriever::FORCE_REFETCH_FLAG,
			ProFreeRetriever::FORCE_REFETCH_FLAG,
			'Pro must share the exact same flag constant as Common.'
		);
		$this->assertTrue(
			self::invokeShouldForceRefetch(ProFreeRetriever::class),
			'Pro must honour the same flag through the inherited helper.'
		);
	}

	// ---- Guard: the flag name survives clear_plugin_cache()'s transient purge ----

	public function test_flag_uses_sbreviews_prefix_to_survive_cache_purge(): void
	{
		// clear_plugin_cache() deletes `_transient_sbr_%`. A `sbr_`-prefixed flag
		// would be swept immediately; the `sbreviews_` prefix dodges that LIKE.
		$flag = CommonFreeRetriever::FORCE_REFETCH_FLAG;
		$this->assertStringStartsWith('sbreviews_', $flag);
		$this->assertFalse(
			str_starts_with($flag, 'sbr_'),
			'Flag must not match the `_transient_sbr_%` purge in clear_plugin_cache().'
		);
	}

	// ---- Guards: the belts honour the flag ----

	public function test_common_belt_honours_force_flag(): void
	{
		$this->assertMatchesRegularExpression(
			'/already_fetched\([^)]*\);\s*\n\s*if \(\$limit_current && ! self::should_force_refetch\(\)\)/',
			self::commonSource(),
			'Common limit_review_api_call() must bypass already_fetched when the flag is set.'
		);
	}

	public function test_pro_belt_honours_force_flag(): void
	{
		$this->assertMatchesRegularExpression(
			'/already_fetched_week\([^)]*\);\s*\n\s*if \(\$already_fetched && ! self::should_force_refetch\(\)\)/',
			self::proSource(),
			'Pro limit_review_api_call() must bypass already_fetched_week when the flag is set.'
		);
	}

	// ---- Guards: clear_all_caches() sets the flag, filtered, after the purge, bounded ----

	public function test_clear_all_caches_sets_the_force_flag(): void
	{
		$this->assertStringContainsString(
			'FreeRetriever::FORCE_REFETCH_FLAG',
			self::saverSource(),
			'Clear All Caches must set the keyless force-refetch flag.'
		);
	}

	public function test_force_flag_set_is_filterable(): void
	{
		$this->assertStringContainsString(
			"apply_filters('sbr_force_keyless_refetch_on_clear', true)",
			self::saverSource(),
			'The force-refetch flag must be filterable so support can disable it without a redeploy.'
		);
	}

	public function test_force_flag_is_set_after_clear_plugin_cache(): void
	{
		$src = self::saverSource();
		$purgePos = strpos($src, 'self::clear_plugin_cache()');
		$flagPos  = strpos($src, 'FreeRetriever::FORCE_REFETCH_FLAG');
		$this->assertNotFalse($purgePos);
		$this->assertNotFalse($flagPos);
		$this->assertLessThan(
			$flagPos,
			$purgePos,
			'The flag must be set AFTER clear_plugin_cache() so its transient purge does not remove it.'
		);
	}

	public function test_force_flag_has_a_bounded_short_ttl(): void
	{
		// A permanent (TTL 0 / no expiry) flag would keep the belt open and let
		// every render re-call the relay — a cost/loop risk. Pin a bounded TTL.
		$this->assertMatchesRegularExpression(
			'/set_transient\(\s*\\\\SmashBalloon\\\\Reviews\\\\Common\\\\Utils\\\\FreeRetriever::FORCE_REFETCH_FLAG,\s*1,\s*\d+\s*\*\s*MINUTE_IN_SECONDS\s*\)/',
			self::saverSource(),
			'The force-refetch flag must use a bounded MINUTE_IN_SECONDS TTL (one-shot, not permanent).'
		);
	}
}
