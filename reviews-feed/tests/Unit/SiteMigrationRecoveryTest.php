<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SiteUrlWatcher;
use SmashBalloon\Reviews\Common\Services\Upgrade\Routines\BackfillWebsiteUrlRoutine;
use SmashBalloon\Reviews\Common\Support\UrlNormalization;

/**
 * Covers the plugin-side recovery flow for sites that have been migrated
 * (DB copy, WP Engine staging→live push, domain rename). See SMASH-1281.
 *
 * - `check_token_validity()` expanded to react to the server-side
 *   `discriminator: "url_mismatch"` signal (SMASH-1274 relay fix) with a
 *   broader state wipe than the original access_token-only removal.
 *
 * - `detect_site_migration()` proactively compares `get_home_url()` against
 *   the URL stored at registration (`sbr_settings['website_url']`) and
 *   triggers the same cleanup without requiring a round-trip through the
 *   relay's 401 to discover the mismatch.
 *
 * The bootstrap (tests/bootstrap.php) stubs `get_option`, `update_option`,
 * `delete_option`, and `get_home_url` via the `$wp_options_mock` and
 * `$wp_home_url_mock` globals.
 */
class SiteMigrationRecoveryTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_options_mock    = [];
		$wp_home_url_mock   = 'https://example.com';
		$wp_transients_mock = [];
	}

	/*
	|--------------------------------------------------------------------------
	| UrlNormalization trait
	|--------------------------------------------------------------------------
	*/

	public function test_url_normalization_handles_common_variants(): void
	{
		$harness = new class {
			use UrlNormalization;
		};

		$this->assertSame('https://example.com', $harness->normalize_url('https://example.com/'));
		$this->assertSame('https://example.com', $harness->normalize_url('HTTPS://EXAMPLE.COM'));
		$this->assertSame('https://example.com/staging', $harness->normalize_url('https://example.com/staging/'));
		$this->assertSame('https://example.com:8443', $harness->normalize_url('HTTPS://Example.COM:8443/'));
		$this->assertSame('https://example.com?lang=en', $harness->normalize_url('https://example.com/?lang=en'));
		$this->assertSame('not-a-url', $harness->normalize_url('not-a-url'));
		$this->assertSame('', $harness->normalize_url(''));
	}

	/** Same domain with different schemes must compare equal. */
	public function test_url_normalization_scheme_agnostic_strips_http_and_https(): void
	{
		$harness = new class {
			use UrlNormalization;
		};

		$this->assertSame(
			$harness->normalize_url_scheme_agnostic('http://example.com'),
			$harness->normalize_url_scheme_agnostic('https://example.com')
		);
		$this->assertSame(
			$harness->normalize_url_scheme_agnostic('HTTP://Example.com/'),
			$harness->normalize_url_scheme_agnostic('https://example.com')
		);
		$this->assertSame(
			'example.com:8443',
			$harness->normalize_url_scheme_agnostic('HTTPS://Example.COM:8443/')
		);
		$this->assertNotSame(
			$harness->normalize_url_scheme_agnostic('https://foo.com'),
			$harness->normalize_url_scheme_agnostic('https://bar.com')
		);
		// Path variance is not collapsed — multisite/language subpaths stay distinct.
		$this->assertNotSame(
			$harness->normalize_url_scheme_agnostic('https://foo.com'),
			$harness->normalize_url_scheme_agnostic('https://foo.com/pt-br')
		);
	}

	/**
	 * Host-only form drops scheme, path, port, leading "www.", and trailing dot.
	 * Used for migration detection — same site identity on the same WordPress
	 * install should normalize to the same value regardless of reverse-proxy
	 * port injection or canonical-URL drift.
	 */
	public function test_url_normalization_host_only_drops_scheme_path_port_www_and_trailing_dot(): void
	{
		$harness = new class {
			use UrlNormalization;
		};

		// Baseline — scheme, case, path, query, fragment all dropped.
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('http://Example.COM/'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com/pt-br/'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com/?utm=x'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com/#anchor'));

		// Port stripped — covers the SMASH-1274 residual loop where a
		// reverse-proxy injects ":80"/":443" inconsistently and the same
		// site oscillates against itself. Non-default ports also folded.
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com:443'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('http://example.com:80'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com:8443/any/path'));

		// Leading www. stripped — covers canonical-URL drift on
		// non-canonical permalinks and multisite alias mapping where
		// get_home_url() can return either form across requests.
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://www.example.com'));
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://WWW.Example.COM/'));

		// Trailing dot stripped — FQDN form vs short form is the same
		// WordPress install.
		$this->assertSame('example.com', $harness->normalize_url_host_only('https://example.com.'));

		// All-together cocktail — every quirk at once still collapses.
		$this->assertSame(
			'example.com',
			$harness->normalize_url_host_only('https://www.example.com:443/blog/?lang=en')
		);

		// Genuinely different domains must NOT collapse — protects against
		// a too-eager normalizer accidentally flagging real migrations as
		// no-ops.
		$this->assertNotSame(
			$harness->normalize_url_host_only('https://foo.com'),
			$harness->normalize_url_host_only('https://bar.com')
		);
		$this->assertNotSame(
			$harness->normalize_url_host_only('https://example.com'),
			$harness->normalize_url_host_only('https://shop.example.com')
		);

		// Empty string preserved.
		$this->assertSame('', $harness->normalize_url_host_only(''));
	}

	/*
	|--------------------------------------------------------------------------
	| check_token_validity — reactive recovery
	|--------------------------------------------------------------------------
	*/

	public function test_check_token_validity_is_noop_when_response_is_not_invalid_token(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'tok-stay',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://example.com',
			'license_info'      => ['license' => 'valid'],
		];

		$relay = new SBRelay();
		$relay->check_token_validity(['success' => true, 'id' => 'somethingElse']);

		$this->assertSame('tok-stay', $wp_options_mock['sbr_settings']['access_token']);
	}

	public function test_check_token_validity_clears_only_access_token_when_no_discriminator(): void
	{
		// BC path: older relay responses (pre-SMASH-1274) have no discriminator.
		// Behavior must stay identical to the original implementation — only the
		// access_token is cleared; email / license / website_url remain intact.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'stale-token',
			'email'             => 'user@example.com',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://example.com',
			'license_info'      => ['license' => 'valid'],
			'license_status'    => 'valid',
			'license_key'       => 'ABC-123',
		];

		$relay = new SBRelay();
		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken']);

		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertSame('user@example.com', $wp_options_mock['sbr_settings']['email']);
		$this->assertSame('2026-01-01', $wp_options_mock['sbr_settings']['email_verified_at']);
		$this->assertSame('https://example.com', $wp_options_mock['sbr_settings']['website_url']);
		$this->assertArrayHasKey('license_info', $wp_options_mock['sbr_settings']);
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
	}

	public function test_check_token_validity_wipes_migration_state_when_discriminator_url_mismatch(): void
	{
		// Isolate the REACTIVE path: we align the mocked home URL with the stored
		// website_url so that `SBRelay`'s constructor-level `detect_site_migration()`
		// is a no-op for this fixture. Otherwise the proactive wipe would run
		// during `new SBRelay()` and the test would pass even if
		// `check_token_validity()` failed to handle the discriminator.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://old-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'stale-token',
			'email'             => 'user@example.com',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://old-site.com',
			'license_info'      => ['license' => 'valid'],
			'license_status'    => 'valid',
			'license_key'       => 'ABC-123',
		];
		// Also pre-populate the verification option so we can assert the reactive
		// path clears it (the load-bearing verification flag lives here, not in
		// sbr_settings.email_verified_at).
		$wp_options_mock['sbr_email_verification'] = ['email' => 'user@example.com', 'token' => 'tok'];

		$relay = new SBRelay();
		// Sanity: proactive path did NOT wipe, because URLs match.
		$this->assertSame('stale-token', $wp_options_mock['sbr_settings']['access_token']);

		$relay->check_token_validity([
			'success'       => false,
			'id'            => 'invalidToken',
			'discriminator' => 'url_mismatch',
		]);

		// Reactive path wiped: access_token, email_verified_at, website_url, license_info, license_status
		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('email_verified_at', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('website_url', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('license_info', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('license_status', $wp_options_mock['sbr_settings']);
		// Preserved: email + license_key (the user's credentials)
		$this->assertSame('user@example.com', $wp_options_mock['sbr_settings']['email']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
		// Verification option cleared — this is what actually forces re-verification.
		$this->assertArrayNotHasKey('sbr_email_verification', $wp_options_mock);
	}

	public function test_check_token_validity_accepts_full_body_with_data_subarray(): void
	{
		// The relay's outer `call()` today passes `$body['data']` — but external
		// callers may pass the whole body with `data` nested. Both must work.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://example.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$relay->check_token_validity([
			'message' => 'The token used with this website is not valid...',
			'success' => false,
			'data'    => [
				'id'            => 'invalidToken',
				'type'          => 'token',
				'code'          => 401,
				'discriminator' => 'url_mismatch',
				'success'       => false,
			],
		]);

		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('website_url', $wp_options_mock['sbr_settings']);
	}

	/*
	|--------------------------------------------------------------------------
	| detect_site_migration — proactive recovery
	|--------------------------------------------------------------------------
	*/

	public function test_detect_site_migration_returns_false_when_never_registered(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = []; // no access_token, no website_url

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
	}

	public function test_detect_site_migration_returns_false_when_urls_match(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://example.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	public function test_detect_site_migration_returns_false_when_only_casing_differs(): void
	{
		// A casing/trailing-slash variant is NOT a migration — it's WP filters
		// returning a different form of the same URL.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'HTTPS://Example.COM/';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	/** Scheme variance is not a migration — same site on http vs https. */
	public function test_detect_site_migration_returns_false_when_only_scheme_differs(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://example.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'http://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	public function test_detect_site_migration_returns_false_when_scheme_downgraded(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'http://example.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	/**
	 * Path variance is not a migration — WPML/Polylang on a single install
	 * can emit `get_home_url()` as either root or language subpath depending
	 * on request context. Host-only compare treats them as the same site.
	 */
	public function test_detect_site_migration_returns_false_when_only_language_path_differs(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://example.com/pt-br/';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	public function test_detect_site_migration_returns_false_when_path_and_scheme_both_differ(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'http://example.com/en/';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	/** Different domain still registers as migration — genuine site move. */
	public function test_detect_site_migration_wipes_state_on_different_host(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://new-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://old-site.com',
		];

		new SBRelay();
		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
	}

	/** Combined scheme + trailing slash + case variance — realistic WP edge case. */
	public function test_detect_site_migration_returns_false_when_scheme_and_slash_differ(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'HTTPS://Example.COM/';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'http://example.com',
		];

		$relay = new SBRelay();
		$this->assertFalse($relay->detect_site_migration());
		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
	}

	public function test_detect_site_migration_wipes_state_when_home_url_differs(): void
	{
		// Note: the SBRelay constructor itself runs detect_site_migration(),
		// so by the time `new SBRelay()` returns, the wipe has already happened.
		// We verify the post-construction state directly rather than calling
		// detect() a second time.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://migrated-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'stale-token',
			'email'             => 'user@example.com',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://original-site.com',
			'license_info'      => ['license' => 'valid'],
			'license_status'    => 'valid',
			'license_key'       => 'ABC-123',
		];

		new SBRelay(); // constructor triggers detect_site_migration

		$settings = $wp_options_mock['sbr_settings'];
		$this->assertArrayNotHasKey('access_token', $settings);
		$this->assertArrayNotHasKey('email_verified_at', $settings);
		$this->assertArrayNotHasKey('website_url', $settings);
		$this->assertArrayNotHasKey('license_info', $settings);
		$this->assertArrayNotHasKey('license_status', $settings);
		$this->assertSame('user@example.com', $settings['email']);
		$this->assertSame('ABC-123', $settings['license_key']);
	}

	public function test_sbrelay_constructor_leaves_instance_unconfigured_after_migration_wipe(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://migrated-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'stale-token',
			'website_url'  => 'https://original-site.com',
			'email_verified_at' => '2026-01-01',
		];

		// Instantiation alone should trigger detection + wipe — BEFORE any call.
		// After construction, the access_token is cleared (constructor reloads
		// settings after the wipe), so isConfigured() must return false.
		$relay = new SBRelay();

		$this->assertFalse($relay->isConfigured());
		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('email_verified_at', $wp_options_mock['sbr_settings']);
	}

	public function test_check_token_validity_preserves_non_array_sbr_settings_gracefully(): void
	{
		// Defensive: if sbr_settings is corrupted (non-array), the reset
		// must not explode — just initialize fresh.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = 'corrupted-string-value';

		$relay = new SBRelay();
		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken', 'discriminator' => 'url_mismatch']);

		$this->assertIsArray($wp_options_mock['sbr_settings']);
	}

	/*
	|--------------------------------------------------------------------------
	| BackfillWebsiteUrlRoutine — rescues the existing install base
	|--------------------------------------------------------------------------
	| (Seer BLOCK on code review — without this, every pre-patch customer
	| gets reactive-only protection because they never re-register.)
	|--------------------------------------------------------------------------
	*/

	public function test_backfill_website_url_routine_runs_for_existing_install_without_website_url(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://existing-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'pre-patch-token',
			// NO website_url — this install registered before SMASH-1281 shipped.
		];

		$routine = new BackfillWebsiteUrlRoutine();
		$routine->register();

		/** @var array<string,mixed> $settings_after */
		$settings_after = $wp_options_mock['sbr_settings'];
		$this->assertSame('https://existing-site.com', $settings_after['website_url'] ?? null);
	}

	public function test_backfill_routine_is_noop_for_unregistered_install(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = []; // no access_token

		$routine = new BackfillWebsiteUrlRoutine();
		$routine->register();

		// Don't clobber settings for fresh installs — let RegisterWebsiteRoutine run first.
		$this->assertArrayNotHasKey('website_url', $wp_options_mock['sbr_settings']);
	}

	public function test_backfill_routine_is_noop_when_website_url_already_present(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://current-site.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://original-site.com',  // don't overwrite
		];

		$routine = new BackfillWebsiteUrlRoutine();
		$routine->register();

		/** @var array<string,mixed> $settings_after */
		$settings_after = $wp_options_mock['sbr_settings'];
		$this->assertSame('https://original-site.com', $settings_after['website_url'] ?? null);
	}

	public function test_backfill_routine_is_self_terminating(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://site.com';
		$wp_options_mock['sbr_settings'] = ['access_token' => 'tok'];

		$routine = new BackfillWebsiteUrlRoutine();
		$routine->register();

		/** @var array<string,mixed> $settings_after */
		$settings_after = $wp_options_mock['sbr_settings'];
		$this->assertSame('https://site.com', $settings_after['website_url'] ?? null);

		// Change home URL and run again — routine should no-op because website_url is now set.
		$wp_home_url_mock = 'https://site-renamed.com';
		$routine->register();
		/** @var array<string,mixed> $settings_after_rerun */
		$settings_after_rerun = $wp_options_mock['sbr_settings'];
		$this->assertSame(
			'https://site.com',
			$settings_after_rerun['website_url'] ?? null,
			'Backfill routine should not overwrite an existing website_url (self-terminating).'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| SiteUrlWatcher — distinguishes legitimate URL changes from migrations
	|--------------------------------------------------------------------------
	| (Architect BLOCK on code review — without this, every HTTP→HTTPS rollout
	| or www/apex swap looks like a migration and triggers a false wipe.)
	|--------------------------------------------------------------------------
	*/

	public function test_site_url_watcher_syncs_website_url_on_legitimate_admin_change(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://www.example.com'; // admin just switched to www
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok',
			'website_url'  => 'https://example.com',  // what we had before the switch
		];

		$watcher = new SiteUrlWatcher();
		$watcher->on_site_url_changed('https://example.com', 'https://www.example.com', 'home');

		$this->assertSame(
			'https://www.example.com',
			$wp_options_mock['sbr_settings']['website_url']
		);
	}

	public function test_site_url_watcher_noop_on_unregistered_install(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://www.example.com';
		$wp_options_mock['sbr_settings'] = []; // never registered

		$watcher = new SiteUrlWatcher();
		$watcher->on_site_url_changed('https://example.com', 'https://www.example.com', 'home');

		// Don't pre-populate website_url for an install that hasn't registered yet.
		$this->assertArrayNotHasKey('website_url', $wp_options_mock['sbr_settings']);
	}

	public function test_legitimate_url_change_via_watcher_does_NOT_trigger_migration_wipe(): void
	{
		// End-to-end for the Architect BLOCK scenario:
		// admin enables HTTPS → home option changes → watcher syncs website_url →
		// next SBRelay instantiation's detect_site_migration() sees matching URLs → no wipe.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'http://example.com';
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'tok',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'http://example.com',
			'license_key'       => 'ABC-123',
		];

		// Admin enables HTTPS.
		$wp_home_url_mock = 'https://example.com';
		(new SiteUrlWatcher())->on_site_url_changed('http://example.com', 'https://example.com', 'home');

		// Next relay instantiation — must NOT wipe anything, because the watcher
		// already updated website_url to match.
		$relay = new SBRelay();

		$this->assertSame('tok', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('2026-01-01', $wp_options_mock['sbr_settings']['email_verified_at']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
		$this->assertTrue($relay->isConfigured());
	}

	public function test_db_copy_without_admin_url_change_DOES_trigger_migration_wipe(): void
	{
		// Complement to the test above — proves the watcher isn't masking the
		// actual migration case. A raw DB copy (no WP hook) must still trigger
		// the proactive wipe on next SBRelay instantiation.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://clone-site.com';  // get_home_url() at the new site
		$wp_options_mock['sbr_settings'] = [
			// These values arrived via DB copy — no WP hook ran.
			'access_token'      => 'stale-token',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://origin-site.com',
			'license_key'       => 'ABC-123',
		];

		new SBRelay();

		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('email_verified_at', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('website_url', $wp_options_mock['sbr_settings']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
	}

	/*
	|--------------------------------------------------------------------------
	| sbr_get_database_settings() defensive-array guard
	|--------------------------------------------------------------------------
	|
	| Catches the `array_merge(): Argument #2 must be of type array, string
	| given` fatal surfaced during SMASH-1281 QA when `sbr_settings` was a
	| corrupted non-array value (raw SQL edits, broken backup/restore,
	| migration tooling that mangled serialization). The SBRelay migration-
	| recovery flow above expects to run; this guard stops the admin from
	| dying before recovery gets a chance.
	*/

	public function test_get_database_settings_survives_string_sbr_settings(): void
	{
		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';

		global $wp_options_mock, $sbr_settings;
		$wp_options_mock['sbr_settings'] = 'this is not an array';
		$sbr_settings = null; // force re-read from get_option

		$result = sbr_get_database_settings();

		$this->assertIsArray($result, 'sbr_get_database_settings() must return an array even when the stored option is corrupted');
		$this->assertSame('default', $result['feedTemplate'], 'Defaults must still flow through');
	}

	public function test_get_database_settings_survives_bool_sbr_settings(): void
	{
		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';

		global $wp_options_mock, $sbr_settings;
		$wp_options_mock['sbr_settings'] = false;
		$sbr_settings = null;

		$result = sbr_get_database_settings();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('layout', $result);
	}

	public function test_get_database_settings_merges_array_normally(): void
	{
		// Regression guard — the new defensive check must not disturb the
		// normal array case.
		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';

		global $wp_options_mock, $sbr_settings;
		$wp_options_mock['sbr_settings'] = ['feedTemplate' => 'custom-theme'];
		$sbr_settings = null;

		$result = sbr_get_database_settings();

		$this->assertSame('custom-theme', $result['feedTemplate'], 'User-set override must still win over defaults');
		$this->assertArrayHasKey('layout', $result);
	}

	/*
	|--------------------------------------------------------------------------
	| Silent re-activation (Option 3 — SMASH-1281)
	|--------------------------------------------------------------------------
	|
	| Covers the `attempt_silent_reactivation()` flow added to
	| `SBRelay::reset_registration_state()`. Invoked via reflection because
	| the method is private (it's an internal recovery detail, not part of
	| the public SBRelay contract).
	|
	| The `call()` method is stubbed on a partial SBRelay mock so the tests
	| never make real HTTP requests. Each test asserts the post-state of
	| `$wp_options_mock['sbr_settings']` and `$wp_transients_mock`.
	*/

	/**
	 * Helper — invoke the private attempt_silent_reactivation method with
	 * a canned sequence of call() responses.
	 *
	 * @param array<int,mixed> $call_responses   Each entry stubbed in-order into $relay->call().
	 * @param string           $license_key
	 * @param string           $old_url
	 * @return \PHPUnit\Framework\MockObject\MockObject|SBRelay
	 */
	private function invoke_silent_reactivation_with(array $call_responses, string $license_key = 'ABC-123', string $old_url = 'https://origin-site.com')
	{
		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		if (count($call_responses) === 0) {
			$relay->expects($this->never())->method('call');
		} elseif (count($call_responses) === 1) {
			$relay->expects($this->once())->method('call')->willReturn($call_responses[0]);
		} else {
			$relay->expects($this->exactly(count($call_responses)))
				->method('call')
				->willReturnOnConsecutiveCalls(...$call_responses);
		}

		$method = new \ReflectionMethod(SBRelay::class, 'attempt_silent_reactivation');
		$method->setAccessible(true);
		$method->invoke($relay, $license_key, $old_url);

		return $relay;
	}

	public function test_silent_reactivation_happy_path_restores_license_and_sets_notice(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with(
			[
				// auth/register — fresh token issued
				['data' => ['token' => 'fresh-token-abc']],
				// auth/license activate — EDD validates, returns full api_data
				['data' => [
					'license'  => 'valid',
					'api_data' => [
						'license'          => 'valid',
						'item_id'          => SBR_PRODUCT_ID,
						'item_name'        => SBR_PLUGIN_NAME,
						'site_count'       => 7,
						'activations_left' => 3,
						'customer_email'   => 'test@example.com',
					],
				]],
			],
			'CUSTOMER-LICENSE-KEY',
			'https://old-site.example'
		);

		$this->assertSame('fresh-token-abc', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('https://new-site.example', $wp_options_mock['sbr_settings']['website_url']);
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
		$this->assertSame(7, $wp_options_mock['sbr_settings']['license_info']['site_count']);
		$this->assertSame('test@example.com', $wp_options_mock['sbr_settings']['license_info']['customer_email']);

		$this->assertArrayHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
		$this->assertSame('https://old-site.example', $wp_transients_mock['sbr_silent_reactivation_notice']['old_url']);
		$this->assertSame('https://new-site.example', $wp_transients_mock['sbr_silent_reactivation_notice']['new_url']);
		$this->assertArrayHasKey('timestamp', $wp_transients_mock['sbr_silent_reactivation_notice']);

		$this->assertArrayHasKey('sbr_silent_reactivate_last_attempt', $wp_transients_mock);
	}

	public function test_silent_reactivation_skipped_when_rate_limit_transient_is_set(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';
		$wp_transients_mock['sbr_silent_reactivate_last_attempt'] = time() - 3600; // 1h ago

		// No call() invocations expected — the rate-limit transient short-circuits.
		$this->invoke_silent_reactivation_with([], 'CUSTOMER-KEY', 'https://old.example');

		// sbr_settings stays untouched — no access_token, no license_info rehydrated.
		$this->assertArrayNotHasKey('sbr_settings', $wp_options_mock);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_bails_when_register_call_returns_no_token(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		// Register response is malformed — no token at any expected key.
		$this->invoke_silent_reactivation_with(
			[['data' => ['something_else' => 'oops']]]
		);

		$this->assertArrayNotHasKey('sbr_settings', $wp_options_mock);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
		// Rate-limit transient was still set first — so this attempt counts
		// against the 24h quota. Second invocation should no-op.
		$this->assertArrayHasKey('sbr_silent_reactivate_last_attempt', $wp_transients_mock);
	}

	public function test_silent_reactivation_persists_access_token_but_no_license_info_when_activate_fails(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with(
			[
				// Register succeeds
				['data' => ['token' => 'fresh-token']],
				// Activate returns invalid (expired / revoked / over-limit) —
				// any non-'valid' status is treated the same way.
				['data' => ['license' => 'expired']],
			]
		);

		// access_token did get persisted from the register step — but
		// license_info/license_status remain wiped, so the admin lands on
		// the manual Activate screen on their next page load.
		$this->assertSame('fresh-token', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertArrayNotHasKey('license_status', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('license_info', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_handles_over_limit_like_any_invalid_license(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with(
			[
				['data' => ['token' => 'fresh-token']],
				// Real EDD shape for over-limit: license=no_activations_left
				['data' => ['license' => 'no_activations_left']],
			]
		);

		$this->assertSame('fresh-token', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertArrayNotHasKey('license_status', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_survives_throwable_from_register(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		// Register throws — could be RelayResponseException or anything else.
		$relay->expects($this->once())
			->method('call')
			->willThrowException(new \RuntimeException('relay unreachable'));

		$method = new \ReflectionMethod(SBRelay::class, 'attempt_silent_reactivation');
		$method->setAccessible(true);
		// MUST NOT propagate — best-effort recovery.
		$method->invoke($relay, 'LICENSE-KEY', 'https://old.example');

		$this->assertArrayNotHasKey('sbr_settings', $wp_options_mock);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_survives_throwable_from_activate(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		$relay->expects($this->exactly(2))
			->method('call')
			->willReturnCallback(function ($endpoint) {
				if ($endpoint === 'auth/register') {
					return ['data' => ['token' => 'fresh-token']];
				}
				throw new \RuntimeException('EDD gateway timeout');
			});

		$method = new \ReflectionMethod(SBRelay::class, 'attempt_silent_reactivation');
		$method->setAccessible(true);
		$method->invoke($relay, 'LICENSE-KEY', 'https://old.example');

		// access_token was persisted from the register step — fine. But
		// the exception during activation must be swallowed, not propagated.
		$this->assertSame('fresh-token', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertArrayNotHasKey('license_status', $wp_options_mock['sbr_settings']);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_noop_when_home_url_is_empty(): void
	{
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = ''; // edge case — cron / misconfigured site

		$this->invoke_silent_reactivation_with([], 'LICENSE-KEY', 'https://old.example');

		$this->assertArrayNotHasKey('sbr_settings', $wp_options_mock);
		$this->assertArrayNotHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
		// Rate-limit transient was NOT set — we bailed before taking the quota.
		$this->assertArrayNotHasKey('sbr_silent_reactivate_last_attempt', $wp_transients_mock);
	}

	public function test_silent_reactivation_noop_when_license_key_is_empty(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with([], '', 'https://old.example');

		$this->assertArrayNotHasKey('sbr_settings', $wp_options_mock);
	}

	public function test_silent_reactivation_tolerates_flat_activate_response_without_data_wrapper(): void
	{
		// Some relay paths / older versions may return a flat
		//   ['license' => 'valid', 'api_data' => [...]]
		// instead of nested under `data`. Silent reactivation should still
		// recognize success.
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with(
			[
				['data' => ['token' => 'fresh-token']],
				// Flat shape, no `data` wrapper
				['license' => 'valid', 'api_data' => ['license' => 'valid', 'site_count' => 2]],
			]
		);

		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
		$this->assertSame(2, $wp_options_mock['sbr_settings']['license_info']['site_count']);
		$this->assertArrayHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
	}

	public function test_silent_reactivation_tolerates_token_at_flat_response_root_instead_of_data(): void
	{
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://new-site.example';

		$this->invoke_silent_reactivation_with(
			[
				// Token at root level — existing user re-registering. Same
				// tolerance as RegisterWebsiteRoutine itself.
				['token' => 'root-level-token'],
				['data' => ['license' => 'valid', 'api_data' => []]],
			]
		);

		$this->assertSame('root-level-token', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
	}

	public function test_silent_reactivation_end_to_end_via_detect_site_migration(): void
	{
		// Integration-style — triggers the full chain:
		//   detect_site_migration -> reset_registration_state -> attempt_silent_reactivation
		// using only public SBRelay API + globals.
		global $wp_options_mock, $wp_home_url_mock, $wp_transients_mock;
		$wp_home_url_mock = 'https://new-site.example';
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'stale-token-from-old-site',
			'email'             => 'user@example.com',
			'email_verified_at' => '2026-01-01',
			'website_url'       => 'https://old-site.example',
			'license_info'      => ['license' => 'valid', 'site_count' => 4],
			'license_status'    => 'valid',
			'license_key'       => 'ABC-123',
		];

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		$relay->expects($this->exactly(2))
			->method('call')
			->willReturnOnConsecutiveCalls(
				['data' => ['token' => 'new-fresh-token']],
				['data' => ['license' => 'valid', 'api_data' => ['license' => 'valid', 'site_count' => 5]]]
			);

		// Invoke the real detect_site_migration path (public method, safe to call directly).
		$result = $relay->detect_site_migration();

		$this->assertTrue($result, 'Migration must be detected when URLs differ');
		$this->assertSame('new-fresh-token', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('https://new-site.example', $wp_options_mock['sbr_settings']['website_url']);
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
		$this->assertSame(5, $wp_options_mock['sbr_settings']['license_info']['site_count']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key'], 'license_key must always be preserved');
		$this->assertSame('user@example.com', $wp_options_mock['sbr_settings']['email'], 'email must always be preserved');

		$this->assertArrayHasKey('sbr_silent_reactivation_notice', $wp_transients_mock);
		$this->assertSame('https://old-site.example', $wp_transients_mock['sbr_silent_reactivation_notice']['old_url']);
		$this->assertSame('https://new-site.example', $wp_transients_mock['sbr_silent_reactivation_notice']['new_url']);
	}

	public function test_silent_reactivation_not_triggered_on_non_migration_invalid_token_wipe(): void
	{
		// Generic invalidToken (no discriminator) — must NOT trigger silent
		// re-activation (the migration-only flow). Since the
		// reverify-before-wipe patch landed, this path now takes one extra
		// /auth/register round-trip first to confirm the 401 wasn't a false
		// positive. Here we make that reverify return an empty body, so the
		// caller falls back to the old wipe path; license_info stays
		// preserved (only access_token is cleared on the no-discriminator
		// branch — same as the original BC contract).
		global $wp_options_mock, $wp_home_url_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token'      => 'stale-token',
			'license_key'       => 'ABC-123',
			'license_info'      => ['license' => 'valid'],
			'website_url'       => 'https://example.com',
		];
		$wp_home_url_mock = 'https://example.com';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		// Reverify reaches the relay exactly once. We return an empty body
		// to force the wipe-fallback path. This is the only call we expect:
		// silent_reactivation (which would fire BOTH /auth/register AND
		// /auth/license) must NOT run because there's no migration.
		$relay->expects($this->once())
			->method('call')
			// SMASH-1585: reverify now sends the bearer (require_auth=true) so a
			// migration-aware relay can rebind instead of forking.
			->with('auth/register', $this->anything(), 'POST', true)
			->willReturn([]);

		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken']);

		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertSame(['license' => 'valid'], $wp_options_mock['sbr_settings']['license_info'], 'Generic invalidToken preserves license_info');
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key'], 'license_key preserved');
	}

	/*
	|--------------------------------------------------------------------------
	| reverify_token_via_register — false-positive 401 + silent rotation paths
	|--------------------------------------------------------------------------
	*/

	public function test_reverify_keeps_token_when_relay_returns_same_token(): void
	{
		// Simulates a transient 401 (WAF, deployment blip, parallel-call race).
		// The plugin's stored token is still the canonical one for this URL —
		// reverify confirms by calling /auth/register and getting the same
		// token back. No wipe, no rotation; subsequent calls in the same
		// request can keep using the token.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok-canonical',
			'license_key'  => 'ABC-123',
			'license_info' => ['license' => 'valid'],
			'website_url'  => 'https://example.com',
		];
		$wp_home_url_mock = 'https://example.com';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		// Seed the in-memory token to match the option (constructor was
		// disabled so we set it via reflection — same as production where
		// the constructor reads sbr_settings into $this->access_token).
		$ref = new \ReflectionClass(SBRelay::class);
		$prop = $ref->getProperty('access_token');
		$prop->setAccessible(true);
		$prop->setValue($relay, 'tok-canonical');

		$relay->expects($this->once())
			->method('call')
			->with('auth/register', ['url' => 'https://example.com'], 'POST', true) // SMASH-1585: reverify authenticates
			->willReturn(['data' => ['token' => 'tok-canonical']]);

		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken']);

		// Token preserved — reverify returned the same value.
		$this->assertSame('tok-canonical', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('tok-canonical', $prop->getValue($relay), 'in-memory token also preserved');
		// License state untouched.
		$this->assertSame(['license' => 'valid'], $wp_options_mock['sbr_settings']['license_info']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
	}

	public function test_reverify_silently_rotates_token_when_relay_returns_different_token(): void
	{
		// Server-side token rotation: relay's DB has a different token for
		// this URL than what the plugin's option holds (e.g. operator manually
		// re-issued, or a DB cleanup recreated rows). Reverify reads the new
		// canonical token and silently updates sbr_settings — no full reset,
		// no migration cascade, license/email state intact.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok-stale',
			'license_key'  => 'ABC-123',
			'license_info' => ['license' => 'valid'],
			'license_status' => 'valid',
			'website_url'  => 'https://example.com',
			'email'        => 'user@example.com',
		];
		$wp_home_url_mock = 'https://example.com';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		$ref = new \ReflectionClass(SBRelay::class);
		$prop = $ref->getProperty('access_token');
		$prop->setAccessible(true);
		$prop->setValue($relay, 'tok-stale');

		$relay->expects($this->once())
			->method('call')
			->with('auth/register', ['url' => 'https://example.com'], 'POST', true) // SMASH-1585: reverify authenticates
			->willReturn(['data' => ['token' => 'tok-rotated']]);

		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken']);

		// Token silently rotated to relay's canonical value.
		$this->assertSame('tok-rotated', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('tok-rotated', $prop->getValue($relay), 'in-memory token also rotated');
		// website_url updated to current home_url (kept consistent).
		$this->assertSame('https://example.com', $wp_options_mock['sbr_settings']['website_url']);
		// License + email + customer credentials untouched.
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
		$this->assertSame(['license' => 'valid'], $wp_options_mock['sbr_settings']['license_info']);
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
		$this->assertSame('user@example.com', $wp_options_mock['sbr_settings']['email']);
	}

	public function test_reverify_falls_back_to_wipe_when_call_throws(): void
	{
		// Reverify itself fails (network failure, RelayResponseException, etc.).
		// The one-shot guard inside reverify wraps the call in try-catch, so
		// the exception becomes a "reverify failed" signal and the caller
		// falls back to the original wipe-only path — preserving the
		// pre-patch contract for the case where we genuinely can't confirm.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_options_mock['sbr_settings'] = [
			'access_token' => 'tok-stale',
			'license_key'  => 'ABC-123',
			'license_info' => ['license' => 'valid'],
			'website_url'  => 'https://example.com',
		];
		$wp_home_url_mock = 'https://example.com';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call'])
			->getMock();

		$ref = new \ReflectionClass(SBRelay::class);
		$prop = $ref->getProperty('access_token');
		$prop->setAccessible(true);
		$prop->setValue($relay, 'tok-stale');

		$relay->expects($this->once())
			->method('call')
			->willThrowException(new \RuntimeException('network error'));

		$relay->check_token_validity(['success' => false, 'id' => 'invalidToken']);

		// Wipe fallback engaged (option + in-memory both cleared).
		$this->assertArrayNotHasKey('access_token', $wp_options_mock['sbr_settings']);
		$this->assertNull($prop->getValue($relay), 'in-memory token also cleared on wipe');
		// Customer credentials preserved (no migration → narrow wipe).
		$this->assertSame('ABC-123', $wp_options_mock['sbr_settings']['license_key']);
	}

	public function test_silent_reactivation_survives_non_array_settings_after_wipe(): void
	{
		// Rare but real: corrupted sbr_settings (string/bool) — our outer
		// defensive guards recover, but attempt_silent_reactivation still
		// needs to not fatal in that defensive arm.
		global $wp_options_mock, $wp_home_url_mock;
		$wp_home_url_mock = 'https://new-site.example';
		$wp_options_mock['sbr_settings'] = 'this is not an array';

		$this->invoke_silent_reactivation_with(
			[
				['data' => ['token' => 'fresh']],
				['data' => ['license' => 'valid', 'api_data' => []]],
			]
		);

		// Even with garbage starting state, the wipe + re-register + activate
		// sequence leaves a clean array.
		$this->assertIsArray($wp_options_mock['sbr_settings']);
		$this->assertSame('fresh', $wp_options_mock['sbr_settings']['access_token']);
		$this->assertSame('valid', $wp_options_mock['sbr_settings']['license_status']);
	}

	/*
	|--------------------------------------------------------------------------
	| refresh_access_token_from_settings — race fix for register-then-license
	|--------------------------------------------------------------------------
	| Issue 2 (LR3 register-then-license race): when admin_init runs both the
	| RegisterWebsiteRoutine and constructs the DI'd SBRelay used by license
	| handlers, ordering is undefined. If the DI'd SBRelay is constructed first
	| (empty token) and the routine writes a fresh token to sbr_settings AFTER,
	| the next authenticated call from license handlers sends an empty Bearer
	| → 401. The refresh method lets license handlers re-read the post-write
	| sbr_settings on demand. See SMASH-1274 LR3.
	*/

	/**
	 * Helper: build SBRelay without running the constructor. Lets us seed
	 * `access_token` directly (mimicking various pre-refresh in-memory states)
	 * without depending on the test-bootstrap-loaded SBR_RELAY_BASE_URL constant
	 * resolving in the namespaced class const.
	 */
	private function buildRelayWithToken(string $token): SBRelay
	{
		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();
		$ref = new \ReflectionProperty(SBRelay::class, 'access_token');
		$ref->setAccessible(true);
		$ref->setValue($relay, $token);
		return $relay;
	}

	public function test_refresh_access_token_from_settings_picks_up_post_construction_writes(): void
	{
		// Simulating "DI'd at admin_init before RegisterWebsiteRoutine ran" —
		// the in-memory token is empty even though sbr_settings now has one.
		global $wp_options_mock;
		$relay = $this->buildRelayWithToken('');
		$this->assertFalse($relay->isConfigured());

		// External code path (routine, silent_reactivation, cron) wrote a fresh
		// token AFTER this instance was constructed.
		$wp_options_mock['sbr_settings'] = ['access_token' => 'rotated-fresh-token'];

		// Refresh re-reads. The next require_auth call now sees the new token.
		$relay->refresh_access_token_from_settings();
		$this->assertTrue($relay->isConfigured(), 'refresh must promote the persisted token');
	}

	public function test_refresh_access_token_from_settings_is_a_noop_on_corrupted_settings(): void
	{
		// Corrupted sbr_settings (string/bool) must NOT wipe the in-memory token.
		// Other recovery paths re-write to a clean array; refresh stays defensive.
		global $wp_options_mock;
		$relay = $this->buildRelayWithToken('stable-token');
		$this->assertTrue($relay->isConfigured());

		$wp_options_mock['sbr_settings'] = 'this is not an array';
		$relay->refresh_access_token_from_settings();
		$this->assertTrue(
			$relay->isConfigured(),
			'corrupted sbr_settings must not wipe the existing in-memory token'
		);
	}

	public function test_refresh_access_token_from_settings_clears_when_settings_dropped_token(): void
	{
		// Inverse case: settings exist but no longer contain a token (post-reset).
		global $wp_options_mock;
		$relay = $this->buildRelayWithToken('before-reset');
		$this->assertTrue($relay->isConfigured());

		$wp_options_mock['sbr_settings'] = []; // post-reset shape
		$relay->refresh_access_token_from_settings();
		$this->assertFalse(
			$relay->isConfigured(),
			'refresh must reflect a missing token field as not-configured'
		);
	}

	public function test_refresh_access_token_from_settings_coerces_non_string_token_to_empty(): void
	{
		// Defensive: malformed sbr_settings with non-string access_token (array,
		// int, bool) shouldn't TypeError when concatenated into the Bearer header.
		global $wp_options_mock;
		$relay = $this->buildRelayWithToken('seed-token');
		$wp_options_mock['sbr_settings'] = ['access_token' => ['accidental-array']];
		$relay->refresh_access_token_from_settings();
		$this->assertFalse($relay->isConfigured());
	}

	/*
	|--------------------------------------------------------------------------
	| Issue 1: license_key preservation through deactivate
	|--------------------------------------------------------------------------
	| LicenseManagerService::ajax_deactivate_license used to explicitly wipe
	| license_key by passing 'license_key' => '' to SettingsManagerService.
	| The fix removes that key from the update array so the array_merge in
	| update_settings leaves the existing license_key untouched. The contract
	| this test locks: customer can re-Activate without re-pasting the key.
	*/

	public function test_deactivate_partial_update_preserves_license_key(): void
	{
		// Pre-state: license active with key persisted.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [
			'license_key'    => 'CUSTOMER-LICENSE-KEY-93976af1',
			'license_status' => 'valid',
			'license_info'   => ['expires' => '2027-01-01', 'price_id' => 4],
			'access_token'   => 'tok-canonical',
			'website_url'    => 'https://example.com',
		];

		// Mirror the post-fix update set from LicenseManagerService::ajax_deactivate_license.
		// license_key is INTENTIONALLY absent so array_merge keeps the existing value.
		$settings_service = new \SmashBalloon\Reviews\Common\Services\SettingsManagerService();
		$settings_service->update_settings([
			'license_status' => '',
			'license_info'   => '',
		]);

		$after = $wp_options_mock['sbr_settings'];
		$this->assertSame(
			'CUSTOMER-LICENSE-KEY-93976af1',
			$after['license_key'],
			'license_key MUST persist through deactivate so re-Activate is one-click'
		);
		$this->assertSame('', $after['license_status']);
		$this->assertSame('', $after['license_info']);
		$this->assertSame('tok-canonical', $after['access_token'], 'token unaffected by partial update');
	}

	public function test_deactivate_partial_update_with_pre_existing_empty_license_key_remains_empty(): void
	{
		// Edge: customer never had a key (Free plugin), deactivate flow shouldn't
		// magically populate a key field. array_merge keeps the empty string.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [
			'license_key'    => '',
			'license_status' => '',
		];
		$settings_service = new \SmashBalloon\Reviews\Common\Services\SettingsManagerService();
		$settings_service->update_settings([
			'license_status' => '',
			'license_info'   => '',
		]);
		$this->assertSame('', $wp_options_mock['sbr_settings']['license_key']);
	}
}
