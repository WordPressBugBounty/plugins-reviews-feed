<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\RemoteRequest;

/**
 * SMASH-1610: a relay refusal (e.g. freeReviewsLimitExceeded, HTTP 429) reaches Feed::api_request() as the
 * bare error envelope SBRelay::call() returns, with no `data` key, and used to be dropped silently, so a new
 * feed rendered the generic "No Posts Found". Drives the real api_request()/update_posts_cache() with the
 * relay body mocked at the HTTP shim.
 */
class Smash1610RelayErrorAtFeedCreationTest extends TestCase
{
	/** Relay body captured on staging for a quota-exhausted Google fetch (ticket evidence). */
	private const LIMIT_BODY = '{"message":"Cannot fetch reviews, You have reached the maximum free reviews.","success":false,'
		. '"data":{"id":"freeReviewsLimitExceeded","type":"reviews","code":429,"source":"ChIJFTrs68AOSUcRgsTW-sRzwZY",'
		. '"provider":"google","success":false}}';

	private const DIRECTIONS = 'Troubleshoot by visiting <a href="https://smashballoon.com/doc/reviews-feed-error-message-reference/?reviews&utm_campaign=reviews-pro&utm_source=feed&utm_medium=apierror&utm_content=Error%20Message%20Reference" target="_blank" rel="noopener noreferrer">error message reference page</a>.';

	/** @var mixed */
	private $saved_body;
	/** @var mixed */
	private $saved_code;
	/** @var mixed */
	private $saved_error;
	/** @var mixed */
	private $saved_options;

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $wp_options_mock;
		$this->saved_body    = $wp_http_body_mock;
		$this->saved_code    = $wp_http_response_code_mock;
		$this->saved_error   = $wp_http_error_mock;
		$this->saved_options = $wp_options_mock;
		$this->reset_memo();
	}

	protected function tearDown(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $wp_options_mock;
		$wp_http_body_mock          = $this->saved_body;
		$wp_http_response_code_mock = $this->saved_code;
		$wp_http_error_mock         = $this->saved_error;
		$wp_options_mock            = $this->saved_options;
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
	private function source(string $business = 'acme.example'): array
	{
		return [
			'provider'   => 'trustpilot',
			'account_id' => 'tp-' . $business,
			'name'       => 'Acme <b>Ltd</b>',
			'business'   => $business,
			'info'       => [],
		];
	}

	/**
	 * A Feed with the private collaborators api_request() reads, without the constructor's DB work.
	 *
	 * @param array<int, array<string, mixed>> $db_posts   What posts_from_db() returns.
	 * @param array<int, array<string, mixed>> $sources    The feed's sources; one Trustpilot source by default.
	 * @param bool                             $first_only Only the first source reaches the relay; the rest return nothing.
	 */
	private function feed(array $db_posts = [], array $sources = [], bool $first_only = false): CommonFeed
	{
		$feed = new class ($db_posts, $first_only) extends CommonFeed {
			/** @var array<int, array<string, mixed>> */
			private $db_posts;
			/** @var bool */
			private $first_only;

			public function __construct(array $db_posts, bool $first_only)
			{
				$this->db_posts   = $db_posts;
				$this->first_only = $first_only;
			}

			public function get_remote_posts($settings)
			{
				return $this->first_only ? $this->api_request([ $settings['sources'][0] ]) : parent::get_remote_posts($settings);
			}

			public function posts_from_db()
			{
				return $this->db_posts;
			}

			public function update_cache($posts)
			{
			}

			public function maybe_encrypt_cached_posts($posts)
			{
				return $posts;
			}
		};

		$set = static function (string $name, $value) use ($feed): void {
			$prop = new \ReflectionProperty(CommonFeed::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($feed, $value);
		};
		$set('settings', [ 'sources' => $sources !== [] ? $sources : [ $this->source() ], 'apiCallLanguage' => '' ]);
		$set('statuses', [ 'from_cache' => false, 'post_found_before_filter' => false, 'errors' => [] ]);
		$set('feed_id', 7);
		$set('providers_languages', [ 'facebook', 'google' ]);
		$set('providers_no_media', []);

		return $feed;
	}

	public function test_a_quota_refusal_is_recorded_with_its_id_and_our_message(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed();

		$data = $feed->api_request([ $this->source() ]);

		$this->assertSame([], $data, 'A refused source contributes no review set.');
		$relay = $feed->get_relay_errors();
		$this->assertCount(1, $relay);
		$this->assertSame('freeReviewsLimitExceeded', $relay[0]['id']);
		$this->assertStringContainsString('reached the number of reviews', $relay[0]['message']);
		$this->assertStringNotContainsString('API key', $relay[0]['message'], 'No key advice: not every provider has a key field.');
		$this->assertStringContainsString('Acme Ltd', $relay[0]['message'], 'Names the source, markup stripped.');
		$this->assertStringNotContainsString('<', $relay[0]['message']);
		$this->assertArrayNotHasKey('actionUrl', $relay[0], 'No Settings link (SMASH-1610 option A).');
	}

	public function test_an_unreachable_relay_reuses_the_message_sbrelay_wrote(): void
	{
		global $wp_http_error_mock;
		$wp_http_error_mock = new \WP_Error('http_request_failed', 'cURL error 28');
		$feed = $this->feed();

		$feed->api_request([ $this->source('unreachable.example') ]);

		$relay = $feed->get_relay_errors();
		$this->assertCount(1, $relay);
		$this->assertSame('relayUnreachable', $relay[0]['id']);
		$this->assertStringContainsString("Couldn't reach the reviews server", $relay[0]['message']);
	}

	public function test_an_unknown_refusal_names_its_cleaned_id(): void
	{
		$this->relay_answers('{"success":false,"data":{"id":"weird Id!\"","success":false}}', 403);
		$feed = $this->feed();

		$feed->api_request([ $this->source('weird.example') ]);

		$relay = $feed->get_relay_errors();
		$this->assertCount(1, $relay);
		$this->assertSame('weirdId', $relay[0]['id']);
		$this->assertStringContainsString('(weirdId)', $relay[0]['message']);
	}

	public function test_an_id_with_nothing_usable_still_counts_as_a_refusal(): void
	{
		$this->relay_answers('{"success":false,"data":{"id":"!!!","success":false}}', 403);
		$feed = $this->feed();

		$feed->api_request([ $this->source('bang.example') ]);

		$relay = $feed->get_relay_errors();
		$this->assertCount(1, $relay);
		$this->assertSame('relayError', $relay[0]['id']);
	}

	/** An envelope that says it succeeded is not a refusal, even without `data`. */
	public function test_a_successful_envelope_without_data_is_not_a_refusal(): void
	{
		$this->relay_answers('{"success":true,"id":"oddButOk"}', 200);
		$feed = $this->feed();

		$feed->api_request([ $this->source('odd.example') ]);

		$this->assertSame([], $feed->get_relay_errors());
	}

	/** BC: a normal payload records nothing and is passed through as before. */
	public function test_a_successful_fetch_records_no_relay_error(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[{"text":"Great","rating":5}]}}', 200);
		$feed = $this->feed();

		$data = $feed->api_request([ $this->source('ok.example') ]);

		$this->assertSame([], $feed->get_relay_errors());
		$this->assertSame([], $feed->get_errors());
		$this->assertCount(1, $data);
		$this->assertSame('Great', $data[0]['data']['reviews'][0]['text']);
	}

	/** BC: the existing `data.error` path still reports, and is not mistaken for a relay refusal. */
	public function test_the_data_error_shape_keeps_its_old_error(): void
	{
		$this->relay_answers('{"success":true,"data":{"error":"Something upstream"}}', 200);
		$feed = $this->feed();

		$feed->api_request([ $this->source('legacy.example') ]);

		$this->assertSame([], $feed->get_relay_errors());
		$this->assertCount(1, $feed->get_errors());
		$this->assertArrayNotHasKey('error_id', $feed->get_errors()[0]);
	}

	/** BC: the `data.error` text and directions stay byte-identical now that both branches share the helpers. */
	public function test_the_data_error_text_is_unchanged(): void
	{
		$this->relay_answers('{"success":true,"data":{"error":"Something upstream"}}', 200);
		$feed = $this->feed();

		$feed->api_request([ $this->source('legacy.example') ]);

		$error = $feed->get_errors()[0];
		$this->assertSame(
			'An error has occurred when fetching new reviews<br><br>This is affecting the source Acme Ltd for trustpilot. New reviews will not be fetched until this is resolved.<br><br>',
			$error['message']
		);
		$this->assertSame(self::DIRECTIONS, $error['directions']);
	}

	public function test_a_refusal_uses_the_same_directions(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$this->assertSame(self::DIRECTIONS, $feed->get_errors()[0]['directions']);
	}

	public function test_an_empty_feed_after_a_refusal_does_not_claim_no_reviews(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed();

		$this->assertSame([], $feed->update_posts_cache());

		$messages = array_column($feed->get_errors(), 'message');
		$this->assertCount(1, $messages, 'Only the relay reason, no generic "No Posts Found".');
		$this->assertStringNotContainsString('No Posts Found', implode(' ', $messages));
	}

	/** Mixed feed: one source refused, the other empty. The refusal must not hide the other source's reason. */
	public function test_a_mixed_feed_keeps_no_posts_found_beside_the_refusal(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed([], [ $this->source('refused.example'), $this->source('empty.example') ], true);

		$feed->update_posts_cache();

		$this->assertCount(1, $feed->get_relay_errors(), 'Only the first source was refused.');
		$this->assertStringContainsString('No Posts Found', implode(' ', array_column($feed->get_errors(), 'message')));
	}

	/** Every source refused: only the refusals, no generic "No Posts Found". */
	public function test_a_feed_with_every_source_refused_does_not_claim_no_reviews(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed([], [ $this->source('one.example'), $this->source('two.example') ]);

		$feed->update_posts_cache();

		$this->assertCount(2, $feed->get_relay_errors());
		$this->assertStringNotContainsString('No Posts Found', implode(' ', array_column($feed->get_errors(), 'message')));
	}

	/** BC: a genuinely empty source still says "No Posts Found". */
	public function test_an_empty_feed_without_a_refusal_still_says_no_posts_found(): void
	{
		$this->relay_answers('{"success":true,"data":{"reviews":[]}}', 200);
		$feed = $this->feed();

		$feed->update_posts_cache();

		$this->assertSame([], $feed->get_relay_errors());
		$this->assertStringContainsString('No Posts Found', implode(' ', array_column($feed->get_errors(), 'message')));
	}

	/** The feed cache stores errors as JSON and set_errors() restores them; the relay error must survive that. */
	public function test_relay_errors_survive_the_feed_cache_round_trip(): void
	{
		$this->relay_answers(self::LIMIT_BODY, 429);
		$feed = $this->feed();
		$feed->api_request([ $this->source() ]);

		$cached = json_decode((string) json_encode($feed->get_errors()), true);
		$fresh  = $this->feed();
		$fresh->set_errors($cached);

		$this->assertSame($feed->get_relay_errors(), $fresh->get_relay_errors());
	}

	/**
	 * Every endpoint that hands the feed editor a fresh post set must hand it the relay errors too, or the
	 * editor's notice disagrees with the posts it just replaced (the editor stores [] when the key is absent).
	 * Source-level because these AJAX handlers echo and die; the behaviour is covered on the customizer side.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function editor_post_endpoints(): array
	{
		return [
			'initial editor data' => [ 'class/Common/Builder/SBR_Feed_Builder.php', 'customizer_feed_data' ],
			'fly preview'         => [ 'class/Common/Builder/SBR_Feed_Saver_Manager.php', 'feed_customizer_fly_preview' ],
			'save with posts'     => [ 'class/Common/Builder/SBR_Feed_Saver_Manager.php', 'builder_update' ],
		];
	}

	/** @dataProvider editor_post_endpoints */
	public function test_each_editor_post_endpoint_returns_relay_errors(string $file, string $method): void
	{
		$body = \SbrPhpSource::method_body((string) file_get_contents(__DIR__ . '/../../' . $file), $method);

		$this->assertNotSame('', $body, "{$method}() must exist");
		$this->assertStringContainsString("'relayErrors'", $body, "{$method}() returns posts to the editor, so it must return relayErrors too.");
		$this->assertStringContainsString('get_relay_errors()', $body);
	}
}
