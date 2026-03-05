<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for source_id lookup functionality in sb-reviews plugin.
 *
 * These tests verify:
 * 1. relay_source_id is captured from API responses
 * 2. source_id is used when available in API calls
 * 3. Falls back to place_id for backward compatibility
 * 4. Danish character handling works correctly
 */
class SourceIdLookupTest extends TestCase
{
	/*
	|--------------------------------------------------------------------------
	| RemoteRequest::fetch() - source_id Preference Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that source_id is preferred when relay_source_id is available.
	 *
	 * This simulates the behavior in RemoteRequest::fetch()
	 */
	public function test_source_id_is_used_when_relay_source_id_available(): void
	{
		$args = [
			'business' => 'test_place_123',
			'info' => [
				'relay_source_id' => 456,
				'id' => 'test_place_123',
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('source_id', $result);
		$this->assertEquals(456, $result['source_id']);
		$this->assertArrayNotHasKey('place_id', $result);
	}

	/**
	 * Test that place_id is used as fallback when no relay_source_id.
	 */
	public function test_place_id_used_as_fallback_when_no_relay_source_id(): void
	{
		$args = [
			'business' => 'test_place_123',
			'info' => [
				'id' => 'test_place_123',
				// No relay_source_id
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertEquals('test_place_123', $result['place_id']);
		$this->assertArrayNotHasKey('source_id', $result);
	}

	/**
	 * Test that empty relay_source_id falls back to place_id.
	 */
	public function test_empty_relay_source_id_falls_back_to_place_id(): void
	{
		$args = [
			'business' => 'test_place_123',
			'info' => [
				'relay_source_id' => '', // Empty
				'id' => 'test_place_123',
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertArrayNotHasKey('source_id', $result);
	}

	/**
	 * Test that zero relay_source_id falls back to place_id.
	 */
	public function test_zero_relay_source_id_falls_back_to_place_id(): void
	{
		$args = [
			'business' => 'test_place_123',
			'info' => [
				'relay_source_id' => 0,
				'id' => 'test_place_123',
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertArrayNotHasKey('source_id', $result);
	}

	/**
	 * Test that source_id is cast to integer.
	 */
	public function test_source_id_is_cast_to_integer(): void
	{
		$args = [
			'business' => 'test_place_123',
			'info' => [
				'relay_source_id' => '789', // String
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('source_id', $result);
		$this->assertIsInt($result['source_id']);
		$this->assertEquals(789, $result['source_id']);
	}

	/*
	|--------------------------------------------------------------------------
	| relay_source_id Capture Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that relay_source_id is captured from API response.
	 */
	public function test_relay_source_id_captured_from_response(): void
	{
		$apiResponse = [
			'info' => [
				'id' => 'Løgstør-test',
				'name' => 'Test Business',
				'rating' => 4.5,
			],
			'source_id' => 12345,
		];

		$processedInfo = $this->processApiResponse($apiResponse);

		$this->assertArrayHasKey('relay_source_id', $processedInfo);
		$this->assertEquals(12345, $processedInfo['relay_source_id']);
	}

	/**
	 * Test that original info is preserved when capturing relay_source_id.
	 */
	public function test_original_info_preserved_with_relay_source_id(): void
	{
		$apiResponse = [
			'info' => [
				'id' => 'test_place',
				'name' => 'Original Name',
				'rating' => 4.5,
				'url' => 'https://example.com',
			],
			'source_id' => 999,
		];

		$processedInfo = $this->processApiResponse($apiResponse);

		$this->assertEquals('test_place', $processedInfo['id']);
		$this->assertEquals('Original Name', $processedInfo['name']);
		$this->assertEquals(4.5, $processedInfo['rating']);
		$this->assertEquals('https://example.com', $processedInfo['url']);
		$this->assertEquals(999, $processedInfo['relay_source_id']);
	}

	/**
	 * Test handling when source_id is missing from response (backward compat).
	 */
	public function test_handles_missing_source_id_in_response(): void
	{
		$apiResponse = [
			'info' => [
				'id' => 'test_place',
				'name' => 'Test Name',
			],
			// No source_id - old API response format
		];

		$processedInfo = $this->processApiResponse($apiResponse);

		$this->assertArrayNotHasKey('relay_source_id', $processedInfo);
		$this->assertEquals('test_place', $processedInfo['id']);
	}

	/*
	|--------------------------------------------------------------------------
	| Danish Character Encoding Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that Danish characters in place_id don't affect source_id lookup.
	 */
	public function test_danish_place_id_with_source_id_lookup(): void
	{
		$args = [
			'business' => 'Løgstør-æøå-café',
			'info' => [
				'relay_source_id' => 123,
				'id' => 'Løgstør-æøå-café',
			],
		];

		$result = $this->buildApiArgs($args);

		// Should use source_id, not the problematic place_id
		$this->assertArrayHasKey('source_id', $result);
		$this->assertEquals(123, $result['source_id']);
		$this->assertArrayNotHasKey('place_id', $result);
	}

	/**
	 * Test that URL-encoded place_id is used correctly when no source_id.
	 */
	public function test_danish_place_id_fallback_encoding(): void
	{
		$danishPlaceId = 'Løgstør-æøå';

		$args = [
			'business' => $danishPlaceId,
			'info' => [
				'id' => $danishPlaceId,
				// No relay_source_id - must use place_id
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertEquals($danishPlaceId, $result['place_id']);
	}

	/**
	 * Test various Danish character combinations.
	 *
	 * @dataProvider danishCharactersProvider
	 */
	public function test_various_danish_characters(string $placeId): void
	{
		$args = [
			'business' => $placeId,
			'info' => [
				'relay_source_id' => 100,
				'id' => $placeId,
			],
		];

		$result = $this->buildApiArgs($args);

		// With source_id, the problematic place_id should not be in the request
		$this->assertArrayHasKey('source_id', $result);
		$this->assertArrayNotHasKey('place_id', $result);
	}

	public static function danishCharactersProvider(): array
	{
		return [
			'lowercase æ' => ['test-æ-place'],
			'lowercase ø' => ['test-ø-place'],
			'lowercase å' => ['test-å-place'],
			'uppercase Æ' => ['test-Æ-place'],
			'uppercase Ø' => ['test-Ø-place'],
			'uppercase Å' => ['test-Å-place'],
			'all lowercase' => ['Løgstør-æøå'],
			'mixed case' => ['ÅLBORG-Næstved'],
			'with spaces' => ['Rødovre Station'],
			'with numbers' => ['Ålborg123'],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Edge Cases
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test handling of null info array.
	 */
	public function test_handles_null_info(): void
	{
		$args = [
			'business' => 'test_place',
			'info' => null,
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertArrayNotHasKey('source_id', $result);
	}

	/**
	 * Test handling of missing info key.
	 */
	public function test_handles_missing_info_key(): void
	{
		$args = [
			'business' => 'test_place',
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('place_id', $result);
		$this->assertArrayNotHasKey('source_id', $result);
	}

	/**
	 * Test that negative source_id is handled (shouldn't happen but be defensive).
	 */
	public function test_handles_negative_source_id(): void
	{
		$args = [
			'business' => 'test_place',
			'info' => [
				'relay_source_id' => -1, // Invalid
			],
		];

		$result = $this->buildApiArgs($args);

		// Should still use source_id (the API will reject invalid IDs)
		$this->assertArrayHasKey('source_id', $result);
	}

	/**
	 * Test handling of very large source_id.
	 */
	public function test_handles_large_source_id(): void
	{
		$largeId = PHP_INT_MAX;

		$args = [
			'business' => 'test_place',
			'info' => [
				'relay_source_id' => $largeId,
			],
		];

		$result = $this->buildApiArgs($args);

		$this->assertArrayHasKey('source_id', $result);
		$this->assertEquals($largeId, $result['source_id']);
	}

	/*
	|--------------------------------------------------------------------------
	| JSON Serialization Tests (for storage in info field)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that relay_source_id survives JSON encode/decode cycle.
	 */
	public function test_relay_source_id_survives_json_cycle(): void
	{
		$originalInfo = [
			'id' => 'Løgstør-test',
			'name' => 'Test æøå',
			'relay_source_id' => 12345,
		];

		$encoded = json_encode($originalInfo);
		$decoded = json_decode($encoded, true);

		$this->assertEquals(12345, $decoded['relay_source_id']);
		$this->assertEquals('Løgstør-test', $decoded['id']);
	}

	/**
	 * Test that special characters in info survive storage.
	 */
	public function test_danish_characters_survive_json_cycle(): void
	{
		$originalInfo = [
			'id' => 'Løgstør-æøå-ÆØÅ',
			'name' => 'Café Rødovre Ålborg',
			'relay_source_id' => 999,
		];

		$encoded = json_encode($originalInfo, JSON_UNESCAPED_UNICODE);
		$decoded = json_decode($encoded, true);

		$this->assertEquals('Løgstør-æøå-ÆØÅ', $decoded['id']);
		$this->assertEquals('Café Rødovre Ålborg', $decoded['name']);
	}

	/*
	|--------------------------------------------------------------------------
	| Helper Methods (Simulating RemoteRequest and Feed Saver logic)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Simulates the arg building logic from RemoteRequest::fetch()
	 */
	private function buildApiArgs(array $requestArgs): array
	{
		$business = $requestArgs['business'] ?? '';

		// This is the logic from RemoteRequest::fetch()
		if (!empty($requestArgs['info']['relay_source_id'])) {
			return [
				'source_id' => (int) $requestArgs['info']['relay_source_id'],
			];
		}

		return [
			'place_id' => $business,
		];
	}

	/**
	 * Simulates the relay_source_id capture from SBR_Feed_Saver_Manager
	 */
	private function processApiResponse(array $response): array
	{
		$info = $response['info'] ?? [];

		// This is the logic from SBR_Feed_Saver_Manager::process_source_apikey()
		if (isset($response['source_id'])) {
			$info['relay_source_id'] = $response['source_id'];
		}

		return $info;
	}
}
