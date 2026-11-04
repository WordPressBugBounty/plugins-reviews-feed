<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\RemoteRequest;
use SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome;

/**
 * SMASH-2408 (Fallback Sources): the plugin remembers which source failed, and stops missing the failures it
 * could not see. Rows of the classification table one by one, then the real Feed::api_request() with the relay
 * body mocked at the HTTP shim, recording every UPDATE the state writer makes.
 */
class Smash2408SourceFetchStateTest extends TestCase
{
	/** @var mixed */
	private $saved_wpdb;
	/** @var mixed */
	private $saved_body;
	/** @var mixed */
	private $saved_code;
	/** @var mixed */
	private $saved_error;

	/** @var list<array{data: array<string, mixed>, where: array<string, mixed>}> */
	public $updates = [];

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock;
		$this->saved_wpdb  = $GLOBALS['wpdb'] ?? null;
		$this->saved_body  = $wp_http_body_mock;
		$this->saved_code  = $wp_http_response_code_mock;
		$this->saved_error = $wp_http_error_mock;
		$this->reset_memo();
		$test = $this;
		$GLOBALS['wpdb'] = new class ($test) {
			public $prefix = 'wp_';
			/** @var Smash2408SourceFetchStateTest */
			private $test;

			public function __construct($test)
			{
				$this->test = $test;
			}

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->test->updates[] = ['data' => $data, 'where' => $where];

				return 1;
			}

			public function query($sql)
			{
				return 0;
			}

			public function get_results($sql, $output = null)
			{
				// SBR_Sources::exists_in_database(): the re-added source exists (row shape as DB::get_single_source).
				return [['id' => 1, 'account_id' => 'tp-acme.example', 'provider' => 'trustpilot', 'used_in' => 0]];
			}

			public function get_var($sql)
			{
				return null;
			}

			public function get_row($sql, $output = null)
			{
				return null;
			}

			public function prepare($query, ...$args)
			{
				return $query;
			}
		};
	}

	protected function tearDown(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock;
		$GLOBALS['wpdb']            = $this->saved_wpdb;
		$wp_http_body_mock          = $this->saved_body;
		$wp_http_response_code_mock = $this->saved_code;
		$wp_http_error_mock         = $this->saved_error;
		$this->reset_memo();
		parent::tearDown();
	}

	private function reset_memo(): void
	{
		$memo = new \ReflectionProperty(RemoteRequest::class, 'memo');
		$memo->setAccessible(true);
		$memo->setValue(null, []);
	}

	private function relay_answers(string $body, int $code): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock;
		$wp_http_body_mock          = $body;
		$wp_http_response_code_mock = $code;
		$wp_http_error_mock         = null;
	}

	/** Trustpilot: no API-key cap and no free-retriever gate, so the request reaches the relay. */
	private function source(string $error = ''): array
	{
		return [
			'id'         => 77,
			'provider'   => 'trustpilot',
			'account_id' => 'tp-acme.example',
			'name'       => 'Acme',
			'business'   => 'acme.example',
			'info'       => [],
			'error'      => $error,
		];
	}

	/** The same Feed double as Smash1610RelayErrorAtFeedCreationTest: real api_request(), set by reflection. */
	private function feed(): CommonFeed
	{
		$feed = new class extends CommonFeed {
			public function __construct()
			{
			}
		};
		$set = static function (string $name, $value) use ($feed): void {
			$prop = new \ReflectionProperty(CommonFeed::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($feed, $value);
		};
		$set('settings', ['sources' => [$this->source()], 'apiCallLanguage' => '']);
		$set('statuses', ['from_cache' => false, 'post_found_before_filter' => false, 'errors' => []]);
		$set('feed_id', 7);
		$set('providers_languages', ['facebook', 'google']);
		$set('providers_no_media', []);

		return $feed;
	}

	/** @return list<array<string, mixed>> */
	private function state_writes(): array
	{
		return array_values(array_filter($this->updates, static fn ($u) => array_key_exists('error', $u['data'])));
	}

	/*
	|--------------------------------------------------------------------------
	| The table, row by row
	|--------------------------------------------------------------------------
	*/

	/**
	 * @dataProvider rowProvider
	 * @param mixed $response
	 */
	public function test_each_answer_is_classified_by_the_table($response, string $outcome, string $id, string $scope): void
	{
		$this->assertSame(['outcome' => $outcome, 'id' => $id, 'scope' => $scope], SourceFetchOutcome::classify($response));
	}

	/** @return array<string, array{0: mixed, 1: string, 2: string, 3: string}> */
	public static function rowProvider(): array
	{
		$review = ['review_id' => '1', 'text' => 't', 'rating' => 5];

		return [
			'a list of reviews'                     => [['success' => true, 'data' => ['reviews' => [$review]]], 'fetched', '', 'source'],
			'an empty list (WordPress.org, Fusion)' => [['success' => true, 'data' => ['reviews' => []]], 'fetched', '', 'source'],
			'a keyed map of reviews (still reviews)' => [['success' => true, 'data' => ['reviews' => ['a' => $review]]], 'fetched', '', 'source'],
			'noReviewsFound (TripAdvisor)'          => [['id' => 'noReviewsFound', 'success' => false, 'http_status' => 404], 'fetched', '', 'source'],
			'relay unreachable'                     => [['id' => 'relayUnreachable', 'success' => false, 'http_status' => 0], 'failed', 'relayUnreachable', 'relay'],
			'relay 5xx, HTML body'                  => [['id' => 'relayServerError', 'success' => false, 'http_status' => 503], 'failed', 'relayServerError', 'relay'],
			'empty or non-JSON 200'                 => [['endpoint' => 'https://relay/x', 'http_status' => 200], 'failed', 'emptyResponse', 'relay'],
			'429 with an HTML body'                 => [['id' => 'relayServerError', 'success' => false, 'http_status' => 429], 'site_refusal', 'rateLimited', 'source'],
			'429 with no envelope (throttle)'       => [['endpoint' => 'https://relay/x', 'http_status' => 429], 'site_refusal', 'rateLimited', 'source'],
			'429 envelope (weekly limit)'           => [['id' => 'proWeeklyUpdateLimit', 'http_status' => 429], 'site_refusal', 'rateLimited', 'source'],
			'licence refusal'                       => [['id' => 'reviewsLicenseNotValid', 'http_status' => 403], 'site_refusal', 'reviewsLicenseNotValid', 'source'],
			'data with no reviews key'              => [['success' => true, 'data' => []], 'failed', 'invalidResponse', 'source'],
			'Facebook WP_Error (error => null)'     => [['data' => ['error' => null]], 'failed', 'invalidResponse', 'source'],
			'WordPress.org scrape error as reviews' => [['success' => true, 'data' => ['reviews' => ['error' => 'scrapingException', 'message' => 'x']]], 'failed', 'invalidResponse', 'source'],
			'Google keyed error as reviews'         => [['success' => true, 'data' => ['reviews' => ['errorId' => 'sourceConnectionError']]], 'failed', 'invalidResponse', 'source'],
			'data.error (Graph, Woo, EDD)'          => [['data' => ['error' => 'Product deleted']], 'failed', 'providerError', 'source'],
			'empty business'                        => ['', 'failed', 'invalidResponse', 'source'],
			'reviewsError with errorCode'           => [['id' => 'reviewsError', 'errorCode' => 'connection_error', 'status' => 503, 'http_status' => 404], 'failed', 'reviewsUpstreamError', 'source'],
			'reviewsError, string status'           => [['id' => 'reviewsError', 'status' => 'OVER_QUERY_LIMIT', 'http_status' => 404], 'failed', 'reviewsError', 'source'],
			'reviewsError 404, empty list'          => [['id' => 'reviewsError', 'status' => 404, 'http_status' => 404], 'ambiguous', 'reviewsError', 'source'],
			'customer key rejected'                 => [['id' => 'sourceConnectionError', 'status' => 'Invalid API Key', 'http_status' => 404], 'failed', 'sourceConnectionError', 'source'],
			'missing relay credential'              => [['id' => 'sourceConnectionError', 'status' => null, 'http_status' => 404], 'ambiguous', 'sourceConnectionError', 'source'],
			'relay lost the source'                 => [['id' => 'reviewsSourceNotCreated', 'http_status' => 404], 'failed', 'reviewsSourceNotCreated', 'source'],
			'unknownError'                          => [['id' => 'unknownError', 'http_status' => 400], 'ambiguous', 'unknownError', 'source'],
			'an id nobody listed'                   => [['id' => 'brandNewError', 'http_status' => 400], 'unknown', 'brandNewError', 'source'],
		];
	}

	/** @dataProvider configScopeProvider */
	public function test_a_setup_state_is_scoped_config(string $provider, bool $is_pro, string $scope): void
	{
		$this->assertSame($scope, SourceFetchOutcome::classify(['data' => ['error' => 'x']], $provider, $is_pro)['scope']);
	}

	/** @return array<string, array{0: string, 1: bool, 2: string}> */
	public static function configScopeProvider(): array
	{
		return [
			'WooCommerce inactive or product deleted' => ['woocommerce', true, 'config'],
			'EDD inactive'                            => ['edd', true, 'config'],
			'Facebook without Pro'                    => ['facebook', false, 'config'],
			'Facebook Graph error on Pro'             => ['facebook', true, 'source'],
			'any other provider'                      => ['trustpilot', true, 'source'],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Through the real api_request()
	|--------------------------------------------------------------------------
	*/

	public function test_a_failure_is_stored_on_the_source_by_account_id_and_provider(): void
	{
		$this->relay_answers('<html>Bad gateway</html>', 502);

		$this->feed()->api_request([$this->source()]);

		$writes = $this->state_writes();
		$this->assertCount(1, $writes);
		$this->assertSame(['account_id' => 'tp-acme.example', 'provider' => 'trustpilot'], $writes[0]['where'], 'never the hydrated primary key');
		$state = SourceFetchOutcome::decode_state($writes[0]['data']['error']);
		$this->assertNotNull($state);
		$this->assertSame('relayServerError', $state['id']);
		$this->assertSame('relay', $state['scope']);
		$this->assertSame(0, $state['attempts']);
		$this->assertGreaterThan(0, $state['since']);
	}

	public function test_no_relay_text_is_stored(): void
	{
		$this->relay_answers('{"success":false,"message":"<script>x</script>","data":{"id":"reviewsSourceNotCreated","message":"<script>y</script>"}}', 404);

		$this->feed()->api_request([$this->source()]);

		$this->assertSame(['id', 'scope', 'attempts', 'since'], array_keys((array) json_decode($this->state_writes()[0]['data']['error'], true)));
	}

	/**
	 * SMASH-2382 (QA on #581, F1): relay master answers a refused key with HTTP 200 and its error map in data.reviews.
	 * It was classified invalidResponse but still returned for caching, so the map's entries were saved as a blank review.
	 */
	public function test_an_error_map_in_data_reviews_is_never_returned_for_caching(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":{"errorId":"sourceConnectionError","error":{"provider":"google","message":"Please make sure you have provided the right API Key.","status":"INVALID_ARGUMENT","reason":"Invalid API Key","error":"invalidKey"}}}}', 200);

		$out = $this->feed()->api_request([$this->source()]);

		$this->assertSame([], $out);
		$state = SourceFetchOutcome::decode_state($this->state_writes()[0]['data']['error']);
		$this->assertNotNull($state);
		$this->assertSame(SourceFetchOutcome::ID_INVALID_RESPONSE, $state['id'], 'still recorded as the source failure');
	}

	/** #581 review: relay master's WordPress.org scrape exception (`CanDoScrapping`) is a `{error, message}` map in data.reviews. */
	public function test_a_scrape_error_map_in_data_reviews_is_never_returned_for_caching(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":{"error":"scrapingException","message":"cURL error 28"}}}', 200);

		$out = $this->feed()->api_request([$this->source()]);

		$this->assertSame([], $out);
		$state = SourceFetchOutcome::decode_state($this->state_writes()[0]['data']['error']);
		$this->assertNotNull($state);
		$this->assertSame(SourceFetchOutcome::ID_INVALID_RESPONSE, $state['id'], 'still recorded as the source failure');
	}

	/** BC (SMASH-1578): a list whose integer keys have a gap is still a list, so its valid reviews cache. */
	public function test_bc_a_list_with_gapped_integer_keys_is_still_returned_for_caching(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":{"0":{"text":"Great","rating":5},"2":null}}}', 200);

		$out = $this->feed()->api_request([$this->source()]);

		$this->assertCount(1, $out);
		$this->assertSame('Great', $out[0]['data']['reviews'][0]['text']);
	}

	/**
	 * @dataProvider keyedMapProvider
	 * @param mixed $reviews
	 */
	public function test_is_keyed_map_is_true_only_for_a_named_key($reviews, bool $keyed): void
	{
		$this->assertSame($keyed, SourceFetchOutcome::is_keyed_map($reviews));
	}

	/** @return array<string, array{0: mixed, 1: bool}> */
	public static function keyedMapProvider(): array
	{
		return [
			'refused-key map'      => [['errorId' => 'sourceConnectionError', 'error' => []], true],
			'scrape error map'     => [['error' => 'scrapingException', 'message' => 'x'], true],
			'one named key'        => [[0 => [], 'error' => 'x'], true],
			'a list'               => [[['text' => 'a'], ['text' => 'b']], false],
			'gapped integer keys'  => [[0 => ['text' => 'a'], 2 => null], false],
			'empty list'           => [[], false],
			'not an array'         => ['reviews', false],
			'null'                 => [null, false],
		];
	}

	/**
	 * SMASH-2646: the one rule Feed::api_request() and the Pro bulk import share.
	 *
	 * @dataProvider errorMapProvider
	 * @param mixed $response
	 */
	public function test_is_error_map_matches_only_an_invalid_response_map($response, bool $error_map): void
	{
		$this->assertSame($error_map, SourceFetchOutcome::is_error_map($response));
	}

	/** @return array<string, array{0: mixed, 1: bool}> */
	public static function errorMapProvider(): array
	{
		$review = ['review_id' => '1', 'text' => 't', 'rating' => 5];

		return [
			'refused-key map'                 => [['success' => true, 'data' => ['reviews' => ['errorId' => 'sourceConnectionError', 'error' => []]]], true],
			'scrape error map'                => [['success' => true, 'data' => ['reviews' => ['error' => 'scrapingException', 'message' => 'x']]], true],
			'keyed map of reviews (BC)'       => [['success' => true, 'data' => ['reviews' => ['a' => $review]]], false],
			'list with a scalar entry (1578)' => [['success' => true, 'data' => ['reviews' => [$review, null]]], false],
			'list of reviews'                 => [['success' => true, 'data' => ['reviews' => [$review]]], false],
			'relay envelope'                  => [['id' => 'reviewsError', 'http_status' => 404], false],
			'not an array'                    => ['', false],
		];
	}

	/** BC: a real review list is still returned for caching. */
	public function test_bc_a_review_list_is_still_returned_for_caching(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[{"text":"Great","rating":5}]}}', 200);

		$out = $this->feed()->api_request([$this->source()]);

		$this->assertCount(1, $out);
		$this->assertSame('tp-acme.example', $out[0]['provider_id']);
		$this->assertCount(1, $out[0]['data']['reviews']);
		$this->assertSame('Great', $out[0]['data']['reviews'][0]['text']);
	}

	/** BC (SMASH-1578): a list with one scalar entry is still returned, so its valid reviews cache. */
	public function test_bc_a_list_with_a_scalar_entry_is_still_returned_for_caching(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[{"text":"Great","rating":5},null]}}', 200);

		$out = $this->feed()->api_request([$this->source()]);

		$this->assertCount(1, $out);
		$this->assertSame('Great', $out[0]['data']['reviews'][0]['text']);
	}

	public function test_a_clean_fetch_clears_a_stored_failure(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[{"text":"Great","rating":5}]}}', 200);

		$this->feed()->api_request([$this->source('{"id":"relayServerError","scope":"relay","attempts":2,"since":100}')]);

		$this->assertSame([['data' => ['error' => ''], 'where' => ['account_id' => 'tp-acme.example', 'provider' => 'trustpilot']]], $this->state_writes());
	}

	public function test_a_clean_fetch_with_nothing_stored_writes_nothing(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[{"text":"Great","rating":5}]}}', 200);

		$this->feed()->api_request([$this->source()]);

		$this->assertSame([], $this->state_writes());
	}

	public function test_the_same_failure_again_writes_nothing_and_keeps_since(): void
	{
		$this->relay_answers('<html>Bad gateway</html>', 502);

		$this->feed()->api_request([$this->source('{"id":"relayServerError","scope":"relay","attempts":1,"since":100}')]);

		$this->assertSame([], $this->state_writes());
	}

	public function test_a_different_failure_keeps_since_and_attempts(): void
	{
		$this->relay_answers('{"success":false,"data":{"id":"reviewsSourceNotCreated"}}', 404);

		$this->feed()->api_request([$this->source('{"id":"relayServerError","scope":"relay","attempts":3,"since":100}')]);

		$state = SourceFetchOutcome::decode_state($this->state_writes()[0]['data']['error']);
		$this->assertSame(['id' => 'reviewsSourceNotCreated', 'scope' => 'source', 'attempts' => 3, 'since' => 100], $state);
	}

	public function test_a_site_wide_refusal_never_touches_the_source(): void
	{
		$this->relay_answers('{"message":"Too Many Attempts."}', 429);

		$this->feed()->api_request([$this->source('{"id":"relayServerError","scope":"relay","attempts":1,"since":100}')]);

		$this->assertSame([], $this->state_writes(), 'neither badged nor cleared');
	}

	public function test_the_header_path_never_writes(): void
	{
		$this->relay_answers('<html>Bad gateway</html>', 502);

		// No stored state: a header call that recorded anything would show up as a write here.
		$this->feed()->api_request([$this->source()], 'sources');

		$this->assertSame([], $this->state_writes());
	}

	public function test_a_silent_failure_is_recorded_but_never_shown(): void
	{
		// An empty 200 used to be skipped without a word (PHASE-0 0.3a). It is now stored for the retry, but a provider
		// failure is ours to monitor, so the client sees no error for it.
		$this->relay_answers('', 200);
		$feed = $this->feed();

		$feed->api_request([$this->source()]);

		$this->assertSame([], $feed->get_errors());
		$this->assertStringContainsString('emptyResponse', (string) ($this->state_writes()[0]['data']['error'] ?? ''));
	}

	/**
	 * @dataProvider hiddenEnvelopeProvider
	 */
	public function test_a_provider_failure_envelope_is_recorded_but_never_shown(string $body, string $id): void
	{
		$this->relay_answers($body, 404);
		$feed = $this->feed();

		$feed->api_request([$this->source()]);

		$this->assertSame([], $feed->get_errors(), 'nothing for the admin box or the editor notice');
		$this->assertStringContainsString($id, (string) ($this->state_writes()[0]['data']['error'] ?? ''));
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function hiddenEnvelopeProvider(): array
	{
		return [
			'the relay could not create the source' => ['{"success":false,"data":{"id":"reviewsSourceNotCreated"}}', 'reviewsSourceNotCreated'],
			'every upstream provider failed'        => ['{"success":false,"data":{"id":"reviewsError","errorCode":"upstream_error"}}', 'reviewsUpstreamError'],
		];
	}

	/**
	 * What the client can act on still reaches them, once: their own API key, and a licence refusal.
	 *
	 * @dataProvider shownEnvelopeProvider
	 */
	public function test_an_error_the_client_can_fix_is_still_shown_once(string $body, string $id): void
	{
		$this->relay_answers($body, 404);
		$feed = $this->feed();

		$feed->api_request([$this->source()]);

		$this->assertCount(1, $feed->get_errors());
		$this->assertSame($id, $feed->get_relay_errors()[0]['id'] ?? null);
	}

	/** A remote provider's data.error (a Facebook Graph or token error) is the client's to fix: shown, never hidden. */
	public function test_a_remote_provider_error_the_client_can_fix_is_still_shown(): void
	{
		$this->relay_answers('{"success":true,"data":{"error":{"code":"190","message":"token expired"}}}', 200);
		$feed = $this->feed();

		$feed->api_request([$this->source()]);

		$this->assertCount(1, $feed->get_errors());
		$this->assertTrue(\SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome::is_provider_failure(['outcome' => 'failed', 'id' => 'providerError', 'scope' => 'source']) === false);
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function shownEnvelopeProvider(): array
	{
		return [
			'their own API key is invalid' => ['{"success":false,"data":{"id":"sourceConnectionError","status":"Invalid API Key"}}', 'sourceConnectionError'],
			'their own keyed API refused'  => ['{"success":false,"data":{"id":"reviewsError","status":"OVER_QUERY_LIMIT"}}', 'reviewsError'],
			'the licence is not valid'     => ['{"success":false,"data":{"id":"reviewsLicenseNotValid"}}', 'reviewsLicenseNotValid'],
		];
	}

	/**
	 * BC: SBRelay::call()'s three error shapes keep every key they had; http_status is the only addition.
	 *
	 * @dataProvider relayShapeProvider
	 * @param list<string> $keys_before
	 */
	public function test_relay_error_shapes_only_gain_http_status(?string $body, int $code, bool $wp_error, array $keys_before, int $status): void
	{
		global $wp_http_error_mock, $wp_options_mock;
		$saved_options = $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['access_token' => 'x'];
		try {
			$this->relay_answers((string) $body, $code);
			$wp_http_error_mock = $wp_error ? new \WP_Error('http_request_failed', 'cURL error 6') : null;

			$out = (new SBRelay())->call('reviews/trustpilot', ['place_id' => 'x'], 'GET', true);

			$this->assertSame(array_merge($keys_before, ['http_status']), array_keys($out));
			$this->assertSame($status, $out['http_status']);
		} finally {
			$wp_options_mock = $saved_options;
		}
	}

	/** @return array<string, array{0: ?string, 1: int, 2: bool, 3: list<string>, 4: int}> */
	public static function relayShapeProvider(): array
	{
		return [
			'unreachable'    => [null, 0, true, ['id', 'success', 'message', 'apiMessage'], 0],
			'HTML 503'       => ['<html>down</html>', 503, false, ['id', 'success', 'message', 'apiMessage'], 503],
			'error envelope' => ['{"success":false,"data":{"id":"reviewsSourceNotCreated"}}', 404, false, ['id', 'endpoint'], 404],
		];
	}

	/**
	 * The trust-badge editor turns `errored` into "Reconnect them": only for what reconnecting fixes (the client's own
	 * key, a Facebook token). A provider failure reads as "not counted yet" instead.
	 *
	 * @dataProvider badgeErrorProvider
	 */
	public function test_the_badge_editor_asks_for_a_reconnect_only_for_a_source_failure(string $error, bool $errored): void
	{
		$row = \SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder::unreported_row('tp-1', ['name' => 'Acme', 'provider' => 'trustpilot', 'error' => $error]);

		$this->assertSame($errored, $row['errored']);
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function badgeErrorProvider(): array
	{
		return [
			'no error'                   => ['', false],
			'upstream provider failure'  => ['{"id":"reviewsUpstreamError","scope":"source","attempts":0,"since":1}', false],
			'their own keyed API'        => ['{"id":"reviewsError","scope":"source","attempts":0,"since":1}', true],
			'relay could not create it'  => ['{"id":"reviewsSourceNotCreated","scope":"source","attempts":0,"since":1}', false],
			'their own API key'          => ['{"id":"sourceConnectionError","scope":"source","attempts":0,"since":1}', true],
			'a Facebook token error'     => ['{"id":"providerError","scope":"source","attempts":0,"since":1}', true],
			'relay outage'               => ['{"id":"relayServerError","scope":"relay","attempts":0,"since":1}', false],
			'inactive WooCommerce'       => ['{"id":"providerError","scope":"config","attempts":0,"since":1}', false],
			'a value this version did not write (BC)' => ['Something legacy', true],
		];
	}

	public function test_the_feed_passes_the_provider_so_a_setup_state_is_scoped_config(): void
	{
		// EDD answers with data.error before any request (Pro missing, or EDD/EDD Reviews inactive in tests).
		$source = ['id' => 9, 'provider' => 'edd', 'account_id' => '42', 'name' => 'Download', 'business' => '42', 'info' => [], 'error' => ''];

		$this->feed()->api_request([$source]);

		$state = SourceFetchOutcome::decode_state($this->state_writes()[0]['data']['error'] ?? '');
		$this->assertNotNull($state);
		$this->assertSame('config', $state['scope']);
	}

	public function test_a_stored_state_of_the_wrong_shape_is_not_trusted(): void
	{
		$this->assertNull(SourceFetchOutcome::decode_state('{"id":"x","scope":["source"]}'));
		$this->assertNull(SourceFetchOutcome::decode_state('{"id":5,"scope":"source"}'));
		$this->assertNull(SourceFetchOutcome::decode_state('not json'));
	}

	public function test_re_adding_a_source_clears_its_stored_failure(): void
	{
		SBR_Sources::update_or_insert(['id' => 'tp-acme.example', 'provider' => 'trustpilot', 'name' => 'Acme', 'info' => '{}']);

		$this->assertContains(
			['data' => ['error' => ''], 'where' => ['account_id' => 'tp-acme.example', 'provider' => 'trustpilot']],
			$this->state_writes()
		);
	}
}
