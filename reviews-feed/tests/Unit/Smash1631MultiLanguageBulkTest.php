<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_Reviews_Update;
use SmashBalloon\Reviews\Pro\Helpers\SBR_WPML;

/**
 * SMASH-1631 Option B — full multilingual bulk history.
 *
 * The background bulk cron has no front-end request, so "Automatically by WPML"
 * collapses to the WPML default language (e.g. English). Every secondary language
 * (es-419, ...) was left with only the first on-demand page translated. The fix:
 * the bulk job fetches EVERY active WPML language (config-based, available in cron)
 * and tags each batch with its own language, so each language gets a complete set.
 *
 * @group bulk-history
 * @group customer-bug-2026-07-02
 */
class Smash1631MultiLanguageBulkTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock, $wp_filter_mock;
		$wp_options_mock = [];
		$wp_filter_mock = [];
	}

	protected function tearDown(): void
	{
		global $wp_filter_mock, $sitepress;
		$wp_filter_mock = [];
		$sitepress = null;
		parent::tearDown();
	}

	/**
	 * Pure mapper: WPML's active-languages array -> deduped Google codes.
	 * es-mx and es-ar both collapse to es-419; unmappable codes are dropped.
	 */
	public function test_map_active_languages_to_google(): void
	{
		$active = [
			'en'    => ['code' => 'en', 'language_code' => 'en'],
			'es-mx' => ['code' => 'es-mx', 'language_code' => 'es-mx'],
			'es-ar' => ['code' => 'es-ar', 'language_code' => 'es-ar'],
			'pt-br' => ['code' => 'pt-br', 'language_code' => 'pt-br'],
			'zz'    => ['code' => 'zz', 'language_code' => 'zz'], // unmappable -> dropped
		];

		$codes = SBR_WPML::map_active_languages_to_google($active);

		$this->assertContains('en', $codes);
		$this->assertContains('es-419', $codes);
		$this->assertContains('pt-BR', $codes);
		$this->assertNotContains('zz', $codes);
		// es-mx + es-ar dedupe to a single es-419
		$this->assertSame(1, count(array_keys($codes, 'es-419')), 'es-419 must be deduped to one entry.');
	}

	public function test_map_active_languages_handles_non_array(): void
	{
		$this->assertSame([], SBR_WPML::map_active_languages_to_google(null));
		$this->assertSame([], SBR_WPML::map_active_languages_to_google('en'));
	}

	/**
	 * In WP-Cron the 'wpml_active_languages' filter can return empty — WPML's own
	 * docs say it "can only work when the global $wp_query object has been
	 * instantiated ... after the 'wp' action", which cron never fires. When it's
	 * empty, get_active_google_languages() must fall back to the SitePress object's
	 * get_active_languages() (a direct icl_languages read that works headless) so
	 * the bulk job still fetches every configured language instead of collapsing to
	 * a single English pass. SMASH-1631.
	 */
	public function test_get_active_google_languages_falls_back_to_sitepress_when_filter_empty(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock, $sitepress;
		// Simulate cron: the $wp_query-dependent filter yields nothing.
		$wp_filter_mock['wpml_active_languages'] = [];
		// SitePress::get_active_languages() shape: keyed by code, each entry an
		// array carrying at least 'code' (matches the real icl_languages SELECT).
		$sitepress = new class {
			public function get_active_languages()
			{
				return [
					'en'    => ['code' => 'en', 'english_name' => 'English', 'active' => 1],
					'es-mx' => ['code' => 'es-mx', 'english_name' => 'Spanish (Mexico)', 'active' => 1],
				];
			}
		};

		$codes = SBR_WPML::get_active_google_languages();

		$this->assertContains('en', $codes, 'Default language must survive the SitePress fallback.');
		$this->assertContains('es-419', $codes, 'es-mx must map to es-419 via the SitePress fallback in cron.');
	}

	/**
	 * When both the filter AND the SitePress object are unavailable, the helper
	 * returns [] — resolve_bulk_languages() then floors to a single no-language
	 * pass (original/English), so the bulk job never wedges. SMASH-1631.
	 */
	public function test_get_active_google_languages_empty_when_no_source_available(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock, $sitepress;
		$wp_filter_mock['wpml_active_languages'] = [];
		$sitepress = null;

		$this->assertSame([], SBR_WPML::get_active_google_languages());
	}

	/**
	 * Load More runs through admin-ajax, which often executes without the `wp`
	 * action — so the wpml_active_languages filter can be empty there too.
	 * switch_to_url_language() must fall back to SitePress like
	 * get_active_google_languages() does, or the Load More language regression
	 * persists (SMASH-1631, Copilot #493).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_switch_to_url_language_falls_back_to_sitepress_when_filter_empty(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock, $sitepress;
		$wp_filter_mock['wpml_active_languages'] = []; // admin-ajax: filter empty
		$sitepress = new class {
			public function get_active_languages()
			{
				return ['en' => ['code' => 'en'], 'es-mx' => ['code' => 'es-mx']];
			}
		};

		$this->assertSame('es-mx', SBR_WPML::switch_to_url_language('https://x.com/es-mx/mujeres-reales/'));
	}

	/**
	 * Pure resolver matrix — the config cases that matter (global vs per-feed,
	 * wpml vs fixed, mixed). This is the heart of the config-agnostic fix.
	 */
	public function test_resolve_bulk_languages_matrix(): void
	{
		$active = ['en', 'es-419'];
		$sort = function ($a) {
			sort($a);
			return $a;
		};

		// global wpml, no per-feed -> all active
		$this->assertSame(['en', 'es-419'], $sort(Bulk_Reviews_Update::resolve_bulk_languages('wpml', [], $active)));
		// global empty, a feed set to wpml -> all active (THE per-feed fix)
		$this->assertSame(['en', 'es-419'], $sort(Bulk_Reviews_Update::resolve_bulk_languages('', ['wpml'], $active)));
		// global empty, a feed fixed to es-419 -> just es-419 (per-feed fixed fix)
		$this->assertSame(['es-419'], Bulk_Reviews_Update::resolve_bulk_languages('', ['es-419'], $active));
		// global fixed es-419 -> es-419
		$this->assertSame(['es-419'], Bulk_Reviews_Update::resolve_bulk_languages('es-419', [], $active));
		// global default + feed default -> a single no-language ('') fetch (untranslated)
		$this->assertSame([''], Bulk_Reviews_Update::resolve_bulk_languages('default', ['default'], $active));
		// source only in a fixed-'en' feed -> just en (global wpml does NOT apply — no feed inherits it)
		$this->assertSame(['en'], Bulk_Reviews_Update::resolve_bulk_languages('wpml', ['en'], $active));
		// dedupe across global + feed
		$this->assertSame(['es-419'], Bulk_Reviews_Update::resolve_bulk_languages('es-419', ['es-419'], $active));
		// SHARED SOURCE — no-language feed + es-419 feed: BOTH get extended (no-lang '' + es-419)
		$this->assertSame(['es-419', ''], Bulk_Reviews_Update::resolve_bulk_languages('', ['default', 'es-419'], $active));
		// feed on default INHERITS the global fixed language
		$this->assertSame(['es-419'], Bulk_Reviews_Update::resolve_bulk_languages('es-419', ['default'], $active));
		// feed on default inherits global wpml -> all active
		$this->assertSame(['en', 'es-419'], $sort(Bulk_Reviews_Update::resolve_bulk_languages('wpml', ['default'], $active)));
		// FLOOR: 'wpml' sentinel but WPML deactivated (no active languages) -> single no-language fetch
		$this->assertSame([''], Bulk_Reviews_Update::resolve_bulk_languages('wpml', [], []));
		$this->assertSame([''], Bulk_Reviews_Update::resolve_bulk_languages('', ['wpml'], []));
	}

	/**
	 * DISPLAY fix: Load More runs via admin-ajax (no language in the URL), so the
	 * handler maps the originating page URL (`location`) to a WPML language and
	 * switches to it. Pins the URL->language matching (subdir format).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_switch_to_url_language_maps_page_url_to_language(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock;
		$wp_filter_mock['wpml_active_languages'] = [
			'en'    => ['code' => 'en', 'language_code' => 'en'],
			'es-mx' => ['code' => 'es-mx', 'language_code' => 'es-mx'],
		];

		$this->assertSame('es-mx', SBR_WPML::switch_to_url_language('https://x.com/es-mx/mujeres-reales/'));
		$this->assertSame('en', SBR_WPML::switch_to_url_language('https://x.com/en/reviews/'));
		$this->assertNull(SBR_WPML::switch_to_url_language('https://x.com/fr/page/'), 'A non-active language must not match.');
		$this->assertNull(SBR_WPML::switch_to_url_language('https://x.com/'), 'Default language (no prefix) leaves resolution alone.');
		$this->assertNull(SBR_WPML::switch_to_url_language(''), 'Empty URL is a no-op.');
	}

	public function test_switch_to_url_language_noop_without_wpml(): void
	{
		// ICL_SITEPRESS_VERSION not defined in this (non-isolated) process.
		$this->assertNull(SBR_WPML::switch_to_url_language('https://x.com/es-mx/page/'));
	}

	/**
	 * Non-WPML localization: a single explicit language passes through as one entry.
	 */
	public function test_bulk_languages_single_for_explicit_localization(): void
	{
		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = 'CHIJ_SINGLE';
		$bulk->account_provider = 'google';
		$bulk->settings = ['localization' => 'es-419'];

		$this->assertSame(['es-419'], $bulk->bulk_languages());
	}

	/**
	 * THE FIX: with "Automatically by WPML" and WPML active, the bulk job fetches
	 * every active WPML language mapped to a Google code — not just the default.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bulk_languages_returns_all_active_wpml_languages(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock;
		$wp_filter_mock['wpml_active_languages'] = [
			'en'    => ['code' => 'en', 'language_code' => 'en'],
			'es-mx' => ['code' => 'es-mx', 'language_code' => 'es-mx'],
		];

		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = 'CHIJ_WPML';
		$bulk->account_provider = 'google';
		$bulk->settings = ['localization' => 'wpml'];

		$langs = $bulk->bulk_languages();
		sort($langs);
		$this->assertSame(['en', 'es-419'], $langs, 'Bulk must fetch every active WPML language, mapped.');
	}

	/**
	 * THE PER-FEED FIX: global Language is default/empty, but a feed that uses this
	 * source is set to "Automatically by WPML" — the bulk must still fetch every
	 * active WPML language (the bulk otherwise only sees global settings).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bulk_languages_picks_up_per_feed_wpml_with_empty_global(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock, $wpdb;
		$wp_filter_mock['wpml_active_languages'] = [
			'en'    => ['code' => 'en', 'language_code' => 'en'],
			'es-mx' => ['code' => 'es-mx', 'language_code' => 'es-mx'],
		];
		// A feed row whose sources include our source and whose language is wpml.
		$wpdb = new class {
			public $prefix = 'wp_';
			public function esc_like($text)
			{
				return addcslashes((string) $text, '_%\\');
			}
			public function prepare($query, ...$args)
			{
				return $query;
			}
			public function get_results($sql, $output = null)
			{
				return [['settings' => '{"sources":["CHIJ_PERFEED"],"localization":"wpml"}']];
			}
		};

		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = 'CHIJ_PERFEED';
		$bulk->account_provider = 'google';
		$bulk->settings = ['localization' => 'default']; // global NOT wpml

		$langs = $bulk->bulk_languages();
		sort($langs);
		$this->assertSame(['en', 'es-419'], $langs, 'Per-feed wpml with an empty global must still fetch all active languages.');
	}

	/**
	 * End-to-end of the loop: get_bulk_reviews issues one relay call per active
	 * language, each carrying that language — so es-419 reviews get fetched even
	 * though the cron default is English.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_bulk_reviews_calls_relay_once_per_language(): void
	{
		if (! defined('ICL_SITEPRESS_VERSION')) {
			define('ICL_SITEPRESS_VERSION', '4.9.5');
		}
		global $wp_filter_mock;
		$wp_filter_mock['wpml_active_languages'] = [
			'en'    => ['code' => 'en', 'language_code' => 'en'],
			'es-mx' => ['code' => 'es-mx', 'language_code' => 'es-mx'],
		];

		$account_id = 'CHIJ_MULTILANG';
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = [
			$account_id => [
				'account_id' => $account_id,
				'provider'   => 'google',
				'retry'      => false,
				'is_done'    => false,
				'page'       => 1,
			],
		];

		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = $account_id;
		$bulk->account_provider = 'google';
		$bulk->should_make_call();
		$bulk->endpoint = 'reviews/google';
		$bulk->settings = ['localization' => 'wpml'];
		$bulk->provider = ['info' => '{"id":"' . $account_id . '"}'];

		$relay = $this->makeCapturingRelay();
		$bulk->relay = $relay;

		$bulk->get_bulk_reviews();

		$sent = array_map(function ($a) {
			return $a['language'] ?? '(none)';
		}, $relay->calls);
		sort($sent);

		$this->assertSame(['en', 'es-419'], $sent, 'One relay call per active WPML language, each carrying its own code — never the wpml sentinel.');
		$this->assertNotContains('wpml', $sent);
	}

	/**
	 * SIDE-EFFECT GUARD (Copilot PR #493): when one language returns reviews for a
	 * page but another comes back empty (transient), the page must NOT advance —
	 * otherwise the empty language loses that page permanently. It retries instead.
	 */
	public function test_partial_language_failure_retries_without_advancing_page(): void
	{
		$bulk = $this->makeBulkAtPage1('CHIJ_PARTIAL');

		// 1 of 2 languages returned this page — transient failure for the other.
		$bulk->advance_bulk_pagination(1, 2);

		$state = $this->state('CHIJ_PARTIAL');
		$this->assertSame(1, $state['page'], 'Partial page must NOT advance — the empty language would lose this page.');
		$this->assertFalse($state['is_done'], 'Partial page must not finish the source.');
		$this->assertTrue($state['retry'], 'Partial page must schedule a retry.');
	}

	/**
	 * After one retry, a still-partial page advances (bounded) so a persistently
	 * thin language can't wedge the source forever.
	 */
	public function test_partial_after_retry_advances_bounded(): void
	{
		$bulk = $this->makeBulkAtPage1('CHIJ_PARTIAL2', ['retry' => true]);

		$bulk->advance_bulk_pagination(1, 2);

		$state = $this->state('CHIJ_PARTIAL2');
		$this->assertSame(2, $state['page'], 'Still-partial after a retry advances to avoid a stuck source.');
		$this->assertFalse($state['retry'], 'Retry flag cleared after the bounded advance.');
	}

	/**
	 * When every language returns the page, the source advances normally.
	 */
	public function test_all_languages_returning_advances_page(): void
	{
		$bulk = $this->makeBulkAtPage1('CHIJ_FULLPAGE');

		$bulk->advance_bulk_pagination(2, 2);

		$this->assertSame(2, $this->state('CHIJ_FULLPAGE')['page'], 'A full page in every language advances to the next page.');
	}

	/**
	 * No language returned reviews — genuine end-of-history: retry once, then done.
	 */
	public function test_no_reviews_ends_after_one_retry(): void
	{
		$bulk = $this->makeBulkAtPage1('CHIJ_EMPTY', ['retry' => true]);

		$bulk->advance_bulk_pagination(0, 2);

		$this->assertTrue($this->state('CHIJ_EMPTY')['is_done'], 'Empty page after a retry ends the source cleanly.');
	}

	/**
	 * First empty response (retry not yet used) reschedules with retry=true — it
	 * must NOT finish the source on the first empty page.
	 */
	public function test_no_reviews_first_time_retries_not_done(): void
	{
		$bulk = $this->makeBulkAtPage1('CHIJ_EMPTY_FIRST'); // retry defaults to false

		$bulk->advance_bulk_pagination(0, 2);

		$state = $this->state('CHIJ_EMPTY_FIRST');
		$this->assertTrue($state['retry'], 'First empty page flips retry -> true.');
		$this->assertFalse($state['is_done'], 'First empty page must not finish the source.');
	}

	/**
	 * Non-Google providers (e.g. Yelp) never loop languages — bulk_languages() short
	 * -circuits to a single no-language fetch regardless of settings.
	 */
	public function test_bulk_languages_non_google_is_single_no_language(): void
	{
		$bulk = new Bulk_Reviews_Update();
		$bulk->account_provider = 'yelp';
		$bulk->settings = ['localization' => 'wpml'];

		$this->assertSame([''], $bulk->bulk_languages());
	}

	/**
	 * End-to-end BC: a feed fixed to an explicit code forwards exactly that code to
	 * the relay (one call), never the wpml sentinel.
	 */
	public function test_get_bulk_reviews_forwards_fixed_language(): void
	{
		$account_id = 'CHIJ_FIXED';
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = [
			$account_id => ['account_id' => $account_id, 'provider' => 'google', 'retry' => false, 'is_done' => false, 'page' => 1],
		];

		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = $account_id;
		$bulk->account_provider = 'google';
		$bulk->should_make_call();
		$bulk->endpoint = 'reviews/google';
		$bulk->settings = ['localization' => 'es-419'];
		$bulk->provider = ['info' => '{"id":"' . $account_id . '"}'];
		$relay = $this->makeCapturingRelay();
		$bulk->relay = $relay;

		$bulk->get_bulk_reviews();

		$sent = array_map(fn($a) => $a['language'] ?? '(none)', $relay->calls);
		$this->assertSame(['es-419'], $sent, 'A fixed-language feed forwards exactly that code — one call.');
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function makeBulkAtPage1(string $account_id, array $overrides = []): Bulk_Reviews_Update
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_bulk_sources'] = [
			$account_id => array_merge(
				['account_id' => $account_id, 'provider' => 'google', 'retry' => false, 'is_done' => false, 'page' => 1],
				$overrides
			),
		];
		$bulk = new Bulk_Reviews_Update();
		$bulk->account_id = $account_id;
		$bulk->account_provider = 'google';
		$bulk->should_make_call();
		return $bulk;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function state(string $account_id): array
	{
		global $wp_options_mock;
		return $wp_options_mock['sbr_bulk_sources'][$account_id] ?? [];
	}

	/**
	 * Records the args of every relay call and returns empty reviews.
	 *
	 * @return mixed Anonymous-class instance — wider than \stdClass so the typed
	 *               Bulk_Reviews_Update::$relay property accepts it under phpstan.
	 */
	private function makeCapturingRelay()
	{
		return new class {
			/** @var array<int,array<string,mixed>> */
			public $calls = [];
			public function call($endpoint, $args, $method, $auth)
			{
				$this->calls[] = $args;
				return ['data' => ['reviews' => []]];
			}
		};
	}

}
