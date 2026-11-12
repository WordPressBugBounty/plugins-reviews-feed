<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\PostAggregator;
use SmashBalloon\Reviews\Common\RemoteRequest;

/**
 * SMASH-1161: a keyed Google reviews fetch sends `since`, the newest review of the source's first (keyless) import,
 * until the relay answers `gap_filled: true` or GAP_WINDOW_SECONDS pass, so the relay fills the reviews between that
 * import and Google's 5.
 */
class Smash1161GoogleKeyedSinceTest extends TestCase
{
	private const PLACE = 'ChIJ_PLACE';

	/** @var array<string, mixed> */
	private $saved = [];

	/** @var list<string> */
	public $queries = [];

	protected function setUp(): void
	{
		parent::setUp();
		foreach (['wpdb', 'wp_options_mock', 'wp_http_body_mock', 'wp_http_response_code_mock', 'wp_http_error_mock', 'sbr_test_http_gets'] as $global) {
			$this->saved[ $global ] = $GLOBALS[ $global ] ?? null;
		}
		$GLOBALS['wp_options_mock']    = ['sbr_apikeys' => ['google' => 'customer-key'], 'sbr_settings' => ['access_token' => 'x']];
		$GLOBALS['wp_http_error_mock'] = null;
		$GLOBALS['sbr_test_http_gets'] = [];
		RemoteRequest::flush_memo();
	}

	protected function tearDown(): void
	{
		foreach ($this->saved as $global => $value) {
			$GLOBALS[ $global ] = $value;
		}
		RemoteRequest::flush_memo();
		parent::tearDown();
	}

	/** A $wpdb answering the first-import query per provider_id from $newest, logging each query. */
	private function install_wpdb(array $newest): void
	{
		$test            = $this;
		$GLOBALS['wpdb'] = new class ($newest, $test) {
			public $prefix = 'wp_';
			private $newest;
			private $test;

			public function __construct(array $newest, $test)
			{
				$this->newest = $newest;
				$this->test   = $test;
			}

			public function prepare($query, ...$args)
			{
				foreach ($args as $arg) {
					$query = preg_replace('/%[sd]/', "'" . addslashes((string) $arg) . "'", $query, 1);
				}

				return $query;
			}

			public function get_var($sql)
			{
				$this->test->queries[] = $sql;
				foreach ($this->newest as $provider_id => $value) {
					if (strpos($sql, "provider_id = '" . $provider_id . "'") !== false) {
						return $value;
					}
				}

				return null;
			}

			public function query($sql)
			{
				$this->test->queries[] = $sql;

				return 1;
			}
		};
	}

	/** @return array<string, mixed> */
	private function sent_args(string $provider, array $args, string $endpoint = 'reviews'): array
	{
		$request = new RemoteRequest($provider, $args + ['business' => self::PLACE], $endpoint);
		$method  = new \ReflectionMethod(RemoteRequest::class, 'buildBaseArgs');
		$method->setAccessible(true);
		$sent = $method->invoke($request);
		$this->assertIsArray($sent);

		return $sent;
	}

	/** One keyed fetch through SBRelay::call(), the relay answering $data; returns the `since` the URL carried. */
	private function fetch_with_relay_answer(array $data, string $language = ''): ?string
	{
		RemoteRequest::flush_memo();
		$GLOBALS['wp_http_body_mock']          = (string) wp_json_encode(['success' => true, 'data' => $data]);
		$GLOBALS['wp_http_response_code_mock'] = 200;
		$before = count($GLOBALS['sbr_test_http_gets']);

		$args = ['business' => self::PLACE, 'account_id' => self::PLACE];
		if ($language !== '') {
			$args['language'] = $language;
		}
		(new RemoteRequest('google', $args))->fetch();

		$gets = array_slice($GLOBALS['sbr_test_http_gets'], $before);
		$this->assertCount(1, $gets);
		parse_str((string) parse_url($gets[0]['url'], PHP_URL_QUERY), $query);

		return isset($query['since']) && is_string($query['since']) ? $query['since'] : null;
	}

	/** @return array<string, int|string> */
	private function gaps(): array
	{
		$gaps = $GLOBALS['wp_options_mock'][ RemoteRequest::GAP_OPTION ] ?? [];
		$this->assertIsArray($gaps);

		return $gaps;
	}

	public function test_a_keyed_google_fetch_sends_the_first_imports_newest_review_time(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$sent = $this->sent_args('google', ['account_id' => self::PLACE]);

		$this->assertSame(gmmktime(10, 0, 0, 3, 1, 2024), $sent['since'] ?? null, 'time_stamp is UTC, so since is that instant');
		$this->assertCount(1, $this->queries);
		$this->assertStringContainsString('MAX(time_stamp)', $this->queries[0]);
		$this->assertStringContainsString("provider = 'google'", $this->queries[0]);
	}

	/** Chain BLOCK: the keyed 5 the site already stored are left out, or their newest hides the gap for good. */
	public function test_only_the_first_import_counts_not_the_keyed_rows_after_it(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$this->sent_args('google', ['account_id' => self::PLACE]);

		$this->assertStringContainsString('created_on IS NULL OR created_on <=', $this->queries[0]);
		$this->assertStringContainsString("DATE_ADD(MIN(created_on), INTERVAL '" . PostAggregator::FIRST_IMPORT_SECONDS . "' SECOND)", $this->queries[0]);
	}

	public function test_no_stored_review_sends_no_since(): void
	{
		$this->install_wpdb([]);

		$this->assertArrayNotHasKey('since', $this->sent_args('google', ['account_id' => self::PLACE]));
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: array<string, mixed>}> */
	public static function no_since_cases(): array
	{
		return [
			'keyless Google'              => ['google', [], 'reviews', ['account_id' => 'ChIJ_PLACE']],
			'keyed Google, header fetch'  => ['google', ['google' => 'customer-key'], 'sources', ['account_id' => 'ChIJ_PLACE']],
			'keyed Yelp'                  => ['yelp', ['yelp' => 'yelp-key'], 'reviews', ['account_id' => 'ChIJ_PLACE']],
			'keyed Google, no account id' => ['google', ['google' => 'customer-key'], 'reviews', []],
			'keyed Google, array id'      => ['google', ['google' => 'customer-key'], 'reviews', ['account_id' => ['ChIJ_PLACE']]],
		];
	}

	/**
	 * BC: every other request is sent exactly as before, and never reads the posts table for it.
	 *
	 * @dataProvider no_since_cases
	 */
	public function test_bc_other_requests_send_no_since(string $provider, array $keys, string $endpoint, array $args): void
	{
		$GLOBALS['wp_options_mock']['sbr_apikeys'] = $keys;
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$sent = $this->sent_args($provider, $args, $endpoint);

		$this->assertArrayNotHasKey('since', $sent);
		$this->assertSame([], $this->queries);
	}

	/** @return array<string, array{0: mixed}> */
	public static function unreadable_times(): array
	{
		return [
			'null'      => [null],
			'empty'     => [''],
			'zero date' => ['0000-00-00 00:00:00'],
			'garbage'   => ['not a date'],
		];
	}

	/** @dataProvider unreadable_times */
	public function test_an_unreadable_stored_time_is_no_since($value): void
	{
		$this->install_wpdb([self::PLACE => $value]);

		$this->assertNull(PostAggregator::first_import_newest_time(self::PLACE));
	}

	/** The relay's `gap_filled: true` closes the gap: later fetches send no `since` and read no table. */
	public function test_gap_filled_stops_since(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$this->assertNotNull($this->fetch_with_relay_answer(['reviews' => [], 'gap_filled' => true]));
		$this->assertSame(RemoteRequest::GAP_CLOSED, $this->gaps()[ self::PLACE ] ?? null);

		$this->queries = [];
		$this->assertNull($this->fetch_with_relay_answer(['reviews' => []]));
		$this->assertSame([], $this->queries);
	}

	/** An unfinished walk (or a relay without the backfill) keeps the same `since`, starting the window once. */
	public function test_an_unfinished_fill_keeps_sending_the_same_since(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$first = $this->fetch_with_relay_answer(['reviews' => [], 'gap_filled' => false]);
		$started = $this->gaps()[ self::PLACE ] ?? null;
		$this->assertIsInt($started);

		$GLOBALS['wp_options_mock'][ RemoteRequest::GAP_OPTION ][ self::PLACE ] = $started - 100;
		$this->assertSame($first, $this->fetch_with_relay_answer(['reviews' => []]), 'an older relay says nothing');
		$this->assertSame($started - 100, $this->gaps()[ self::PLACE ] ?? null, 'the window is never renewed');
	}

	/** A gap the relay never reports filled stops costing pages once the window is up. */
	public function test_since_stops_after_the_window(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);
		$GLOBALS['wp_options_mock'][ RemoteRequest::GAP_OPTION ] = [self::PLACE => time() - RemoteRequest::GAP_WINDOW_SECONDS];

		$this->assertArrayNotHasKey('since', $this->sent_args('google', ['account_id' => self::PLACE]));
		$this->assertSame([], $this->queries);
	}

	/** Removing a source forgets its gap, so the same place added again gets its own fill. */
	public function test_deleting_a_sources_reviews_forgets_its_gap(): void
	{
		$this->install_wpdb([]);
		$GLOBALS['wp_options_mock'][ RemoteRequest::GAP_OPTION ] = [
			self::PLACE         => RemoteRequest::GAP_CLOSED,
			self::PLACE . '|fr' => RemoteRequest::GAP_CLOSED,
			'ChIJ_OTHER'        => RemoteRequest::GAP_CLOSED,
			self::PLACE . 'X'   => RemoteRequest::GAP_CLOSED,
		];

		PostAggregator::delete_reviews_by_provide_id(self::PLACE);

		$this->assertSame(['ChIJ_OTHER' => RemoteRequest::GAP_CLOSED, self::PLACE . 'X' => RemoteRequest::GAP_CLOSED], $this->gaps(), 'every language of that source, and no other source');
	}

	/** Each feed language has its own gap: the default feed closing it leaves the French feed still asking. */
	public function test_each_language_has_its_own_gap(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		$this->fetch_with_relay_answer(['reviews' => [], 'gap_filled' => true]);

		$this->assertNotNull($this->fetch_with_relay_answer(['reviews' => []], 'fr'), 'fr still sends since');
		$this->assertNull($this->fetch_with_relay_answer(['reviews' => []]), 'the default language is closed');
		$this->assertIsInt($this->gaps()[ self::PLACE . '|fr' ] ?? null);
	}

	/** Review (Rex #600): a failed call never starts the 30-day window; the first real relay answer does. */
	public function test_a_failed_call_does_not_start_the_window(): void
	{
		$this->install_wpdb([self::PLACE => '2024-03-01 10:00:00']);

		RemoteRequest::flush_memo();
		$GLOBALS['wp_http_body_mock']          = '{"success":false,"data":{"id":"tooManyRequests"}}';
		$GLOBALS['wp_http_response_code_mock'] = 429;
		(new RemoteRequest('google', ['business' => self::PLACE, 'account_id' => self::PLACE]))->fetch();
		$this->assertArrayNotHasKey(self::PLACE, $this->gaps(), 'an error envelope leaves no state');

		RemoteRequest::flush_memo();
		$GLOBALS['wp_http_error_mock'] = new \WP_Error('http_request_failed', 'cURL error 7');
		(new RemoteRequest('google', ['business' => self::PLACE, 'account_id' => self::PLACE]))->fetch();
		$GLOBALS['wp_http_error_mock'] = null;
		$this->assertArrayNotHasKey(self::PLACE, $this->gaps(), 'an unreachable relay leaves no state');

		$this->assertNotNull($this->fetch_with_relay_answer(['reviews' => []]), 'since is still sent');
		$this->assertIsInt($this->gaps()[ self::PLACE ] ?? null, 'the first real answer starts the window');
	}

	/** Runs uninstall.php against the options mock; it defines WP_UNINSTALL_PLUGIN, so callers run in their own process. */
	private function run_uninstall(bool $preserve): void
	{
		$GLOBALS['wp_options_mock'] = [
			'sbr_settings'           => ['preserve_settings' => $preserve],
			RemoteRequest::GAP_OPTION => [self::PLACE => RemoteRequest::GAP_CLOSED],
		];
		$GLOBALS['wpdb']     = new class () {
			public $prefix  = 'wp_';
			public $options = 'wp_options';

			public function query($sql)
			{
				return 0;
			}
		};
		$GLOBALS['wp_roles'] = new class () {
			public function remove_cap($role, $cap): void
			{
			}
		};
		define('WP_UNINSTALL_PLUGIN', 'sb-reviews/sb-reviews.php');
		require __DIR__ . '/../../uninstall.php';
	}

	/**
	 * QA F1 (#600): a `closed` entry left by uninstall would stop the same place, added again after a reinstall, from getting its fill.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_deletes_the_gap_state(): void
	{
		$this->run_uninstall(false);
		$this->assertArrayNotHasKey(RemoteRequest::GAP_OPTION, $GLOBALS['wp_options_mock']);
	}

	/**
	 * Kept with "preserve settings", like the sources whose fills it tracks.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_with_preserve_keeps_the_gap_state(): void
	{
		$this->run_uninstall(true);
		$this->assertSame([self::PLACE => RemoteRequest::GAP_CLOSED], $GLOBALS['wp_options_mock'][ RemoteRequest::GAP_OPTION ] ?? null);
	}
}
