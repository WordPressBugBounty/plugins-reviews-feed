<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * SMASH-2411 (Fallback Sources, T5): the admin hears about a source the site itself has to fix (a config state, such
 * as an inactive WooCommerce), if a feed uses it. Provider failures (relay, RapidAPI) are recorded in `sources.error`
 * (SMASH-2408) but never named to the client: we monitor providers ourselves.
 */
class Smash2411FailingSourcesNoticeTest extends TestCase
{
	private const FAILED = '{"id":"providerError","scope":"config","attempts":0,"since":1}';

	/** @var mixed */
	private $saved_wpdb;

	/** @var mixed */
	private $saved_options;

	/** @var list<string> */
	public static $queries = [];

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->saved_options = $wp_options_mock;
		self::$queries = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$GLOBALS['wpdb'] = $this->saved_wpdb;
		$wp_options_mock = $this->saved_options;
		parent::tearDown();
	}

	/**
	 * @param list<array<string, string>> $sources Rows the sources query returns.
	 * @param list<string>                $feeds   Each feed's raw settings JSON.
	 */
	private function seed(array $sources, array $feeds): void
	{
		$GLOBALS['wpdb'] = new class ($sources, $feeds) {
			public $prefix = 'wp_';
			private $sources;
			private $feeds;

			public function __construct(array $sources, array $feeds)
			{
				$this->sources = $sources;
				$this->feeds = $feeds;
			}

			public function prepare($query, ...$args)
			{
				return vsprintf(str_replace('%s', "'%s'", $query), $args);
			}

			public function get_results($sql, $output = null)
			{
				Smash2411FailingSourcesNoticeTest::$queries[] = $sql;

				return $this->sources;
			}

			public function get_col($sql)
			{
				Smash2411FailingSourcesNoticeTest::$queries[] = $sql;

				return $this->feeds;
			}
		};
	}

	private static function source(string $id, string $name, string $error = self::FAILED): array
	{
		return ['account_id' => $id, 'provider' => 'trustpilot', 'name' => $name, 'error' => $error];
	}

	private static function feed(array $ids): string
	{
		return (string) json_encode(['feedName' => 'x', 'sources' => $ids]);
	}

	public function test_a_failing_source_a_feed_uses_is_named(): void
	{
		$this->seed([self::source('tp-1', 'Acme Store')], [self::feed(['tp-1'])]);

		$notice = Util::get_failing_sources_notice();

		$this->assertIsArray($notice);
		$this->assertSame('error', $notice['type']);
		$this->assertStringContainsString('Acme Store', $notice['description']);
		$this->assertStringNotContainsString('Hover the warning', $notice['description'], 'the Sources list shows no warning any more');
		$this->assertArrayNotHasKey('actions', $notice);
	}

	/** @dataProvider providerFailureProvider */
	public function test_a_provider_failure_is_never_named_to_the_client(string $error): void
	{
		$this->seed([self::source('tp-1', 'Acme Store', $error)], [self::feed(['tp-1'])]);

		$this->assertNull(Util::get_failing_sources_notice());
	}

	/** @return array<string, array{0: string}> */
	public static function providerFailureProvider(): array
	{
		return [
			'the relay could not create the source' => ['{"id":"reviewsSourceNotCreated","scope":"source","attempts":0,"since":1}'],
			'an upstream provider failed'           => ['{"id":"reviewsError","scope":"source","attempts":3,"since":1}'],
			'the relay was unreachable'             => ['{"id":"relayUnreachable","scope":"relay","attempts":0,"since":1}'],
		];
	}

	public function test_only_the_config_failure_is_named_beside_a_provider_failure(): void
	{
		$this->seed(
			[self::source('woo-1', 'Woo Mug'), self::source('tp-1', 'Acme Store', '{"id":"reviewsError","scope":"source","attempts":0,"since":1}')],
			[self::feed(['woo-1', 'tp-1'])]
		);

		$description = Util::get_failing_sources_notice()['description'];

		$this->assertStringContainsString('Woo Mug', $description);
		$this->assertStringNotContainsString('Acme Store', $description);
	}

	public function test_only_rows_holding_a_stored_state_are_read(): void
	{
		$this->seed([], []);

		Util::get_failing_sources_notice();

		$this->assertStringContainsString("error LIKE '{%'", self::$queries[0]);
	}

	public function test_nothing_when_no_source_failed(): void
	{
		$this->seed([], [self::feed(['tp-1'])]);

		$this->assertNull(Util::get_failing_sources_notice());
		$this->assertCount(1, self::$queries, 'the feeds are not read when nothing failed');
	}

	/** @dataProvider unusedProvider */
	public function test_nothing_when_no_feed_uses_the_failing_source(array $feeds): void
	{
		$this->seed([self::source('acme', 'Acme')], $feeds);

		$this->assertNull(Util::get_failing_sources_notice());
	}

	/** @return array<string, array{0: list<string>}> */
	public static function unusedProvider(): array
	{
		return [
			'no feeds'                                  => [[]],
			'another source'                            => [[self::feed(['other'])]],
			'an id that only CONTAINS it (LIKE would)'  => [[self::feed(['acme-shop'])]],
			'malformed settings'                        => [['{"sources":']],
			'settings without sources'                  => [[(string) json_encode(['feedName' => 'acme'])]],
			'sources not a list'                        => [[(string) json_encode(['sources' => 'acme'])]],
		];
	}

	public function test_a_value_this_version_did_not_write_is_not_a_failure(): void
	{
		$this->seed([self::source('tp-1', 'Acme', '{"legacy":true}')], [self::feed(['tp-1'])]);

		$this->assertNull(Util::get_failing_sources_notice());
	}

	/**
	 * What the admin sees: the description parsed the way PluginNotices' innerHTML parses it — no element, only
	 * text — for names as sanitize_text_field() stores them (SBR_New_Providers_Manager.php:2214) and hostile ones
	 * at any encoding depth.
	 *
	 * @dataProvider nameProvider
	 */
	public function test_the_admin_sees_the_name_as_text(string $stored, string $seen): void
	{
		$this->seed([self::source('tp-1', $stored)], [self::feed(['tp-1'])]);

		$dom = new \DOMDocument();
		$dom->loadHTML('<?xml encoding="utf-8"?><div id="n">' . Util::get_failing_sources_notice()['description'] . '</div>', LIBXML_NOERROR);
		$xpath = new \DOMXPath($dom);

		$this->assertSame(0, $xpath->query('//div[@id="n"]//*')->length, 'no element reaches the sink');
		$this->assertStringContainsString('Affected: ' . $seen . '.', $xpath->query('//div[@id="n"]')->item(0)->textContent);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function nameProvider(): array
	{
		return [
			'a lone < as stored'      => ['I &lt;3 Cafe', 'I <3 Cafe'],
			'ampersand and quotes'    => ['Tom &amp; Jerry &quot;Bistro&quot; &#039;s', 'Tom & Jerry "Bistro" \'s'],
			'a raw tag'               => ['<img src=x onerror=alert(1)>', '<img src=x onerror=alert(1)>'],
			'a single-encoded tag'    => ['&lt;img src=x onerror=alert(1)&gt;', '<img src=x onerror=alert(1)>'],
			'a double-encoded tag'    => ['&amp;lt;script&amp;gt;x&amp;lt;/script&amp;gt;', '<script>x</script>'],
		];
	}


	public function test_a_nameless_source_is_named_by_its_id(): void
	{
		$this->seed([self::source('tp-9', '')], [self::feed(['tp-9'])]);

		$this->assertStringContainsString('tp-9', Util::get_failing_sources_notice()['description']);
	}

	public function test_a_long_list_is_cut_with_a_count(): void
	{
		$rows = [];
		$ids = [];
		for ($i = 1; $i <= 7; $i++) {
			$rows[] = self::source("s$i", "Source $i");
			$ids[] = "s$i";
		}
		$this->seed($rows, [self::feed($ids)]);

		$description = Util::get_failing_sources_notice()['description'];

		$this->assertStringContainsString('Source 5 and 2 more', $description);
		$this->assertStringNotContainsString('Source 6', $description);
	}

	public function test_it_goes_after_every_other_notice(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_apikeys_limit'] = ['google', 'trustpilot'];
		$this->seed([self::source('tp-1', 'Acme')], [self::feed(['tp-1'])]);

		$notices = Util::get_plugin_notices();

		$this->assertCount(3, $notices);
		$this->assertStringContainsString('Acme', $notices[2]['description']);
	}

	public function test_get_plugin_notices_is_unchanged_without_a_failure(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_apikeys_limit'] = [];
		$this->seed([], []);

		$this->assertSame([], Util::get_plugin_notices());
	}
}
