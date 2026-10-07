<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1901 D3 — the keyless source-limit remedy never fired.
 *
 * SBRelay::call() hands back the relay's error envelope unwrapped:
 * `{id: maxSourceLimitExceeded, code: 429, success: false, ...}`. The add-source path
 * only matched `error === 'sourceLimitExceeded'`, so `sbr_apikeys_limit` was never
 * written, the admin notice never rendered, and the modal never offered the API key step.
 * This restores the modal remedy only; the flag stays unwritten (see the last test).
 */
final class Smash1901SourceLimitRemedyTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		delete_option('sbr_apikeys_limit');
	}

	protected function tearDown(): void
	{
		delete_option('sbr_apikeys_limit');
		parent::tearDown();
	}

	/**
	 * The envelope the relay actually sends (sb-relay SourceLimit middleware →
	 * respondWithError('maxSourceLimitExceeded', ['provider' => ...]), after SBRelay::call()).
	 *
	 * @return array<string, mixed>
	 */
	private static function relayLimitEnvelope(): array
	{
		return [
			'id'       => 'maxSourceLimitExceeded',
			'type'     => 'sources',
			'code'     => 429,
			'message'  => 'You have reached the maximum sources limit.',
			'provider' => 'google',
			'success'  => false,
			'endpoint' => 'https://example.test/api/v1.0/sources/google',
		];
	}

	public function test_relay_limit_envelope_is_recognised(): void
	{
		$this->assertTrue(SBR_Feed_Saver_Manager::is_source_limit_error(self::relayLimitEnvelope()));
	}

	/** BC: the plugin's own pre-flight shape still counts. */
	public function test_preflight_shape_is_still_recognised(): void
	{
		$this->assertTrue(SBR_Feed_Saver_Manager::is_source_limit_error(['error' => 'sourceLimitExceeded']));
	}

	/**
	 * @dataProvider otherErrors
	 *
	 * @param array<string, mixed> $info
	 */
	public function test_other_errors_are_not_a_limit(array $info): void
	{
		$this->assertFalse(SBR_Feed_Saver_Manager::is_source_limit_error($info));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function otherErrors(): array
	{
		return [
			'source connection error' => [['id' => 'sourceConnectionError', 'code' => 404, 'success' => false]],
			'invalid key string'      => [['error' => 'invalidKey']],
			'nested error object'     => [['error' => ['error' => 'maxSourceLimitExceeded']]],
			'source added'            => [['info' => ['id' => 'ChIJ123']]],
			'empty'                   => [[]],
		];
	}

	/**
	 * The add path hands the modal its remedy key for the relay's limit shape, and does NOT
	 * write `sbr_apikeys_limit` for it: that flag also skips live fetches for every source of
	 * the provider (Feed.php check_api_limit), so writing it on a refused add would freeze a
	 * customer's working feeds. Whether it should is a product call (SMASH-1901).
	 */
	public function test_add_path_normalises_the_error_without_writing_the_flag(): void
	{
		$src = (string) file_get_contents(__DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php');

		$this->assertMatchesRegularExpression(
			"/if \\(self::is_source_limit_error\\(\\\$info\\)\\) \\{\\s*\\\$info\\['error'\\] = 'sourceLimitExceeded';\\s*\\}/",
			$src
		);
		$this->assertSame(
			1,
			preg_match_all("/update_api_limit\\(\\\$provider, 'add'\\)/", $src),
			'only the legacy pre-flight shape may write the flag'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/SBR_Sources::delete_source\\(\\\$source_id\\);\\s*(\\/\\/[^\\n]*\\n\\s*)?self::update_api_limit/",
			$src
		);
	}
}
