<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome;

/**
 * SMASH-2382, plugin side. The relay's keyed Google refusal is a reviewsError WITH an errorCode (invalidKey,
 * invalidLocation), which the classifier filed as an upstream failure and hid from the client; it's theirs to fix.
 * And SBRelay::call() put the request URL, the client's api_key included, into the error entry (QA on sb-relay #167).
 */
class Smash2382KeyedGoogleErrorTest extends TestCase
{
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
		$wp_options_mock['sbr_settings'] = ['access_token' => 'x'];
		unset($wp_options_mock['sbr_errors']);
	}

	protected function tearDown(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $wp_options_mock;
		$wp_http_body_mock          = $this->saved_body;
		$wp_http_response_code_mock = $this->saved_code;
		$wp_http_error_mock         = $this->saved_error;
		$wp_options_mock            = $this->saved_options;
		parent::tearDown();
	}

	/** @return array<string, array{0: string}> */
	public static function clientCodes(): array
	{
		return [
			'invalid key'   => ['invalidKey'],
			'invalid place' => ['invalidLocation'],
		];
	}

	/** @dataProvider clientCodes */
	public function test_a_keyed_refusal_is_the_clients_to_fix(string $code): void
	{
		$outcome = SourceFetchOutcome::classify(['id' => 'reviewsError', 'status' => 'INVALID_ARGUMENT', 'errorCode' => $code]);

		$this->assertSame(['outcome' => SourceFetchOutcome::FAILED, 'id' => 'reviewsError', 'scope' => SourceFetchOutcome::SCOPE_SOURCE], $outcome);
		$this->assertFalse(SourceFetchOutcome::is_provider_failure($outcome));
	}

	/** BC: any other errorCode (Booking/Airbnb/AliExpress, WordPress.org, the relay's proxy_error) stays an upstream failure. */
	public function test_other_error_codes_stay_upstream_failures(): void
	{
		foreach (['proxy_error', 'unknown_error', 'upstream_unavailable'] as $code) {
			$outcome = SourceFetchOutcome::classify(['id' => 'reviewsError', 'errorCode' => $code]);

			$this->assertSame(SourceFetchOutcome::ID_UPSTREAM_ERROR, $outcome['id'], $code);
			$this->assertTrue(SourceFetchOutcome::is_provider_failure($outcome), $code);
		}
	}

	public function test_the_clients_key_never_reaches_the_error_entry(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $wp_options_mock;
		$wp_http_body_mock          = '{"success":false,"data":{"id":"reviewsError","errorCode":"invalidKey"}}';
		$wp_http_response_code_mock = 404;
		$wp_http_error_mock         = null;

		$out = (new SBRelay())->call('reviews/google', ['place_id' => 'ChIJx', 'api_key' => 'AIzaCLIENTSECRET'], 'GET', true);

		$this->assertStringContainsString('api_key=REDACTED', (string) $out['endpoint']);
		$this->assertStringContainsString('place_id=ChIJx', (string) $out['endpoint'], 'the rest of the URL stays for support');
		$this->assertStringNotContainsString('AIzaCLIENTSECRET', (string) wp_json_encode($out));
		$this->assertStringNotContainsString('AIzaCLIENTSECRET', (string) wp_json_encode($wp_options_mock['sbr_errors'] ?? []));
		$this->assertNotEmpty($wp_options_mock['sbr_errors'] ?? [], 'the entry is still logged');
	}

	/** The two shapes with no envelope log the URL too. */
	public function test_the_key_is_masked_when_the_relay_is_unreachable_or_down(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $wp_options_mock;
		foreach ([[new \WP_Error('http_request_failed', 'cURL error 6'), 0], [null, 503]] as [$error, $code]) {
			unset($wp_options_mock['sbr_errors']);
			$wp_http_body_mock          = '<html>down</html>';
			$wp_http_response_code_mock = $code;
			$wp_http_error_mock         = $error;

			(new SBRelay())->call('reviews/google', ['place_id' => 'ChIJx', 'api_key' => 'AIzaCLIENTSECRET'], 'GET', true);

			$logged = (string) wp_json_encode($wp_options_mock['sbr_errors'] ?? []);
			$this->assertStringContainsString('REDACTED', $logged, (string) $code);
			$this->assertStringNotContainsString('AIzaCLIENTSECRET', $logged, (string) $code);
		}
	}
}
