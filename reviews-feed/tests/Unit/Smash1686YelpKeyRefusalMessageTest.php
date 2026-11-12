<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\RemoteRequest;

/**
 * SMASH-1686: a keyed Yelp source whose key Yelp refuses (93% of Yelp errors in 14 days were TRIAL_EXPIRED) showed
 * "couldn't fetch reviews (reviewsError)". sb-relay sends Yelp's code as `status`; the feed now names it in our words.
 * Drives the real api_request() with the relay body mocked at the HTTP shim. Each test names the edit that fails it.
 */
class Smash1686YelpKeyRefusalMessageTest extends TestCase
{
	/** sb-relay Reviews/Yelp.php reviewsError through CanRespond::respondWithError (HTTP 404). */
	private const YELP_REFUSAL = '{"message":"Error while retrieving reviews list.","success":false,"data":{"id":"reviewsError",'
		. '"type":"reviews","code":404,"message":"Error while retrieving reviews list.","provider":"yelp","status":"%s",'
		. '"apiMessage":"%s","success":false}}';

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
		$wp_options_mock     = is_array($wp_options_mock) ? $wp_options_mock : [];
		// The client's own key: FreeRetriever lets a keyed Yelp source through to the relay.
		$wp_options_mock['sbr_apikeys'] = [ 'yelp' => 'client-yelp-key' ];
		$wp_http_error_mock             = null;
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

	private function relay_answers(string $body, int $code = 404): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock;
		$wp_http_body_mock          = $body;
		$wp_http_response_code_mock = $code;
	}

	private function yelp_refuses(string $status, string $api_message = 'Your Trial has expired. Please upgrade to continue.'): void
	{
		$this->relay_answers(sprintf(self::YELP_REFUSAL, $status, $api_message));
	}

	/** @return array<string, mixed> */
	private function source(string $provider = 'yelp'): array
	{
		return [
			'provider'   => $provider,
			'account_id' => $provider === 'yelp' ? 'WavvLdfdP6g8aZTtbBQHTw' : 'acme.example',
			'name'       => 'Gary Danko',
			'business'   => 'acme.example',
			'info'       => [],
		];
	}

	private function feed(): CommonFeed
	{
		$feed = new class () extends CommonFeed {
			public function __construct()
			{
			}

			public function posts_from_db()
			{
				return [];
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
		$set('settings', [ 'sources' => [ $this->source() ], 'apiCallLanguage' => '' ]);
		$set('statuses', [ 'from_cache' => false, 'post_found_before_filter' => false, 'errors' => [] ]);
		$set('feed_id', 7);
		$set('providers_languages', [ 'facebook', 'google' ]);
		$set('providers_no_media', []);

		return $feed;
	}

	/** @return array<string, mixed> The one relay error the fetch recorded. */
	private function only_relay_error(CommonFeed $feed): array
	{
		$relay = $feed->get_relay_errors();
		$this->assertCount(1, $relay, 'The refusal is recorded once and shown.');

		return $relay[0];
	}

	/** The ticket's case. Fails with the TRIAL_EXPIRED row removed from provider_key_reason(). */
	public function test_an_expired_yelp_trial_says_so(): void
	{
		$this->yelp_refuses('TRIAL_EXPIRED');
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$error = $this->only_relay_error($feed);
		$this->assertSame('reviewsError', $error['id']);
		$this->assertStringContainsString('free trial on your Yelp API key has ended', $error['message']);
		$this->assertStringContainsString('Manage API Keys', $error['message']);
		$this->assertStringNotContainsString('(reviewsError)', $error['message'], 'Not the generic reason.');
		$this->assertStringContainsString('Gary Danko', $error['message'], 'Still names the source.');
		$this->assertStringContainsString('free trial on your Yelp API key has ended', $feed->get_errors()[0]['summary']);
	}

	/** A revoked key. Fails with the UNAUTHORIZED_ACCESS_TOKEN row removed. */
	public function test_a_revoked_yelp_key_says_to_create_a_new_one(): void
	{
		$this->yelp_refuses('UNAUTHORIZED_ACCESS_TOKEN', 'The access token provided is not valid.');
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$this->assertStringContainsString('Create a new key on Yelp', $this->only_relay_error($feed)['message']);
	}

	/** Yelp's own text never reaches the page: relay strings can echo request input. Fails if apiMessage is used. */
	public function test_the_relay_api_message_is_not_echoed(): void
	{
		$this->yelp_refuses('TRIAL_EXPIRED', '<img src=x onerror=alert(1)> Your Trial has expired.');
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$message = $this->only_relay_error($feed)['message'];
		$this->assertStringNotContainsString('onerror', $message);
		$this->assertStringNotContainsString('Your Trial has expired', $message);
	}

	/** BC: any other Yelp code keeps the generic reason. Fails when the lookup returns text for unknown codes. */
	public function test_another_yelp_code_keeps_the_generic_reason(): void
	{
		$this->yelp_refuses('BUSINESS_NOT_FOUND', 'Business not found.');
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$this->assertStringContainsString("couldn't fetch reviews for this source (reviewsError)", html_entity_decode($this->only_relay_error($feed)['message'], ENT_QUOTES));
	}

	/** The code is matched for Yelp only. Fails when the provider check is dropped from provider_key_reason(). */
	public function test_the_same_code_from_another_provider_keeps_the_generic_reason(): void
	{
		$this->yelp_refuses('TRIAL_EXPIRED');
		$feed = $this->feed();

		$feed->api_request([ $this->source('trustpilot') ]);

		$message = html_entity_decode($this->only_relay_error($feed)['message'], ENT_QUOTES);
		$this->assertStringContainsString('(reviewsError)', $message);
		$this->assertStringNotContainsString('Yelp API key', $message);
	}

	/** Only a reviewsError carries a provider code. Fails when the id check is dropped. */
	public function test_another_refusal_id_with_the_same_status_keeps_its_reason(): void
	{
		$this->relay_answers('{"success":false,"data":{"id":"weirdRefusal","provider":"yelp","status":"TRIAL_EXPIRED","success":false}}', 403);
		$feed = $this->feed();

		$feed->api_request([ $this->source() ]);

		$this->assertStringContainsString('(weirdRefusal)', $this->only_relay_error($feed)['message']);
	}
}
