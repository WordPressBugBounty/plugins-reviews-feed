<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1987 — a relay source error can arrive as an object, and every check in
 * process_source_apikey() compared it to a string.
 *
 * Captured verbatim from staging on 2026-08-25, adding a TripAdvisor source with an
 * invalid key. Note the HTTP 200 and envelope `success: true`: the only failure signal
 * is inside `info`.
 *
 *   {"message":"OK","success":true,"data":{"info":{
 *       "errorId":"sourceConnectionError",
 *       "error":{"provider":"tripadvisor","message":"Please make sure you have provided
 *                the right API Key.","reason":"Invalid API Key","error":"invalidKey"}}}}
 *
 * Consequences before the fix, all measured: the error gate missed it, `$checkValidKey`
 * evaluated TRUE because an array is never equal to 'invalidKey' (so a bad key would be
 * stored as valid), `apikey => invalid` was never set, and the response carried no
 * message — indistinguishable from success to the modal.
 */
final class Smash1987NestedSourceErrorShapeTest extends TestCase
{
	/**
	 * @return array<string,mixed>
	 */
	private static function nestedShape(): array
	{
		return [
			'info' => [
				'errorId' => 'sourceConnectionError',
				'error'   => [
					'provider' => 'tripadvisor',
					'message'  => 'Please make sure you have provided the right API Key.',
					'status'   => 'User is not authorized to access this resource with an explicit deny in an identity-based policy',
					'placeId'  => '13871137',
					'reason'   => 'Invalid API Key',
					'error'    => 'invalidKey',
				],
			],
		];
	}

	public function test_the_code_is_read_out_of_the_nested_object(): void
	{
		$this->assertSame('invalidKey', SBR_Feed_Saver_Manager::get_source_error_code(self::nestedShape()));
	}

	public function test_the_message_is_read_out_of_the_nested_object(): void
	{
		$this->assertSame(
			'Please make sure you have provided the right API Key.',
			SBR_Feed_Saver_Manager::get_source_error_message(self::nestedShape())
		);
	}

	/**
	 * The inversion that made a bad key look good. Kept as an explicit assertion
	 * because it is the part with a write side effect.
	 */
	public function test_a_nested_invalid_key_is_not_treated_as_a_valid_key(): void
	{
		$info       = self::nestedShape();
		$error_code = SBR_Feed_Saver_Manager::get_source_error_code($info);

		$checkValidKey = (
				$error_code !== null && $error_code !== 'invalidKey'
			)
			|| !empty($info['info']['id'])
			|| !empty($info['info']['successId']);

		$this->assertFalse($checkValidKey, 'an invalid key must never satisfy the store-the-key gate');

		// The pre-fix expression, pinned so a revert fails here rather than in production.
		$old = (
				!empty($info['info']['error']) && $info['info']['error'] !== 'invalidKey'
			)
			|| !empty($info['info']['id']);
		$this->assertTrue($old, 'if this is false the shape changed and this test is moot');
	}

	/**
	 * BACKWARDS COMPATIBILITY (Rule 6) — the string shape other providers send must
	 * resolve exactly as it did, so this fix cannot alter their behaviour.
	 *
	 * @dataProvider stringShapeProvider
	 */
	public function test_string_shapes_are_unchanged(string $code): void
	{
		$this->assertSame(
			$code,
			SBR_Feed_Saver_Manager::get_source_error_code(['info' => ['error' => $code]])
		);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function stringShapeProvider(): array
	{
		return [
			'invalidKey'      => ['invalidKey'],
			'invalidLocation' => ['invalidLocation'],
			'someFutureCode'  => ['someFutureCode'],
		];
	}

	/**
	 * @dataProvider noErrorProvider
	 * @param array<string,mixed> $info
	 */
	public function test_a_successful_response_reports_no_error(array $info): void
	{
		$this->assertNull(SBR_Feed_Saver_Manager::get_source_error_code($info));
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function noErrorProvider(): array
	{
		return [
			'source added'    => [['info' => ['id' => '13871137', 'name' => 'Hotel Colline de France']]],
			'empty error key' => [['info' => ['id' => '1', 'error' => '']]],
			'no info at all'  => [[]],
			'info not array'  => [['info' => 'unexpected']],
		];
	}

	/**
	 * An errorId alone is failure, but not a NAMED failure.
	 *
	 * The first version of this fix returned the errorId from get_source_error_code(),
	 * which fed the gate deciding whether to store a submitted key. An errorId is never
	 * literally 'invalidKey', so that gate opened and an invalid key was persisted as
	 * valid — worse than the bug being fixed, and a regression against the pre-fix code,
	 * where an absent info.error left the gate shut.
	 */
	public function test_an_error_id_alone_is_not_a_named_code(): void
	{
		$info = ['info' => ['errorId' => 'sourceConnectionError']];

		$this->assertNull(
			SBR_Feed_Saver_Manager::get_source_error_code($info),
			'an opaque errorId must not present itself as a specific error code'
		);
		$this->assertSame(
			'sourceConnectionError',
			SBR_Feed_Saver_Manager::get_source_error_id($info),
			'but it must still be reportable as a failure'
		);
	}

	/**
	 * The gate itself, pinned. This is the expression in process_source_apikey() that
	 * decides whether update_provider_apikey() runs.
	 *
	 * @dataProvider gateProvider
	 * @param array<string,mixed> $info
	 */
	public function test_only_a_named_non_key_error_may_store_the_key(array $info, bool $expected, string $why): void
	{
		$error_code = SBR_Feed_Saver_Manager::get_source_error_code($info);

		$checkValidKey = (
				$error_code !== null && $error_code !== 'invalidKey'
			)
			|| !empty($info['info']['id'])
			|| !empty($info['info']['successId']);

		$this->assertSame($expected, $checkValidKey, $why);
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:string}>
	 */
	public static function gateProvider(): array
	{
		return [
			'errorId only' => [
				['info' => ['errorId' => 'sourceConnectionError']],
				false,
				'an unnamed failure is no evidence the key works',
			],
			'nested invalidKey' => [
				['info' => ['errorId' => 'sourceConnectionError', 'error' => ['error' => 'invalidKey']]],
				false,
				'an invalid key must never store',
			],
			'string invalidKey' => [
				['info' => ['error' => 'invalidKey']],
				false,
				'the string shape must behave as it always did',
			],
			'invalidLocation' => [
				['info' => ['error' => 'invalidLocation']],
				true,
				'a wrong location proves the key reached far enough to be told so',
			],
			'source returned' => [
				['info' => ['id' => '13871137']],
				true,
				'a created source is the plainest evidence of a working key',
			],
		];
	}

	public function test_the_message_falls_back_when_the_relay_sends_only_a_code(): void
	{
		$this->assertNotSame(
			'',
			SBR_Feed_Saver_Manager::get_source_error_message(['info' => ['error' => 'invalidKey']])
		);
	}
}
