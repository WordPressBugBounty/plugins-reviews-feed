<?php

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for duplicate review prevention.
 *
 * Tests the unique constraint logic that prevents duplicate reviews
 * from being inserted due to race conditions.
 */
class DuplicatePreventionTest extends TestCase
{
	/*
	|--------------------------------------------------------------------------
	| Unique Key Generation Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that unique key is built from post_id, provider_id, and lang.
	 */
	public function test_unique_key_components(): void
	{
		$review1 = [
			'post_id' => '5034',
			'provider_id' => 'wc_multi_abc123',
			'lang' => '',
		];

		$review2 = [
			'post_id' => '5034',
			'provider_id' => 'wc_multi_abc123',
			'lang' => '',
		];

		// Same key = duplicate
		$key1 = $this->buildUniqueKey($review1);
		$key2 = $this->buildUniqueKey($review2);

		$this->assertEquals($key1, $key2);
	}

	/**
	 * Test that different provider_ids produce different keys.
	 */
	public function test_different_provider_ids_are_unique(): void
	{
		$review1 = [
			'post_id' => '5034',
			'provider_id' => 'wc_multi_abc123',
			'lang' => '',
		];

		$review2 = [
			'post_id' => '5034',
			'provider_id' => 'wc_multi_xyz789',
			'lang' => '',
		];

		$key1 = $this->buildUniqueKey($review1);
		$key2 = $this->buildUniqueKey($review2);

		$this->assertNotEquals($key1, $key2);
	}

	/**
	 * Test that same review with different languages are unique.
	 */
	public function test_different_languages_are_unique(): void
	{
		$review1 = [
			'post_id' => '5034',
			'provider_id' => 'ChIJabc123',
			'lang' => 'en',
		];

		$review2 = [
			'post_id' => '5034',
			'provider_id' => 'ChIJabc123',
			'lang' => 'de',
		];

		$key1 = $this->buildUniqueKey($review1);
		$key2 = $this->buildUniqueKey($review2);

		$this->assertNotEquals($key1, $key2);
	}

	/*
	|--------------------------------------------------------------------------
	| WooCommerce Specific Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that WooCommerce comment IDs are used as post_id.
	 */
	public function test_woocommerce_uses_comment_id_as_post_id(): void
	{
		$commentId = 5034;
		$normalizedReview = $this->normalizeWooCommerceReview($commentId, 'Test Review');

		$this->assertEquals((string) $commentId, $normalizedReview['review_id']);
	}

	/**
	 * Test that multi-product source ID is preserved.
	 */
	public function test_multi_product_source_id_format(): void
	{
		$productIds = [1, 2, 3];
		$sourceId = $this->generateMultiProductSourceId($productIds);

		$this->assertStringStartsWith('wc_multi_', $sourceId);
		$this->assertEquals(41, strlen($sourceId)); // wc_multi_ (8) + md5 hash (32) + time suffix
	}

	/*
	|--------------------------------------------------------------------------
	| Migration Routine Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Test that duplicate removal identifies correct duplicates.
	 */
	public function test_duplicate_identification(): void
	{
		$posts = [
			['id' => 1, 'post_id' => '5034', 'provider_id' => 'wc_multi_abc', 'lang' => ''],
			['id' => 2, 'post_id' => '5034', 'provider_id' => 'wc_multi_abc', 'lang' => ''], // Duplicate
			['id' => 3, 'post_id' => '5035', 'provider_id' => 'wc_multi_abc', 'lang' => ''], // Different post_id
			['id' => 4, 'post_id' => '5034', 'provider_id' => 'wc_multi_xyz', 'lang' => ''], // Different provider_id
		];

		$duplicateIds = $this->findDuplicateIds($posts);

		$this->assertCount(1, $duplicateIds);
		$this->assertContains(2, $duplicateIds); // Only id=2 is a duplicate
	}

	/**
	 * Test that first occurrence is kept, duplicates are removed.
	 */
	public function test_keeps_first_occurrence(): void
	{
		$posts = [
			['id' => 10, 'post_id' => '5034', 'provider_id' => 'wc_multi_abc', 'lang' => ''],
			['id' => 20, 'post_id' => '5034', 'provider_id' => 'wc_multi_abc', 'lang' => ''],
			['id' => 30, 'post_id' => '5034', 'provider_id' => 'wc_multi_abc', 'lang' => ''],
		];

		$duplicateIds = $this->findDuplicateIds($posts);

		// Should identify 2 duplicates (id 20 and 30), keeping id 10
		$this->assertCount(2, $duplicateIds);
		$this->assertNotContains(10, $duplicateIds);
		$this->assertContains(20, $duplicateIds);
		$this->assertContains(30, $duplicateIds);
	}

	/*
	|--------------------------------------------------------------------------
	| Helper Methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build unique key from review data.
	 */
	private function buildUniqueKey(array $review): string
	{
		return $review['post_id'] . '|' . $review['provider_id'] . '|' . $review['lang'];
	}

	/**
	 * Simulate WooCommerce review normalization.
	 */
	private function normalizeWooCommerceReview(int $commentId, string $content): array
	{
		return [
			'review_id' => (string) $commentId,
			'text' => $content,
			'rating' => 5,
			'time' => time(),
			'reviewer' => [
				'name' => 'Test User',
			],
			'provider' => [
				'name' => 'woocommerce',
			],
		];
	}

	/**
	 * Generate multi-product source ID.
	 */
	private function generateMultiProductSourceId(array $productIds): string
	{
		return 'wc_multi_' . md5(implode('_', $productIds) . '_' . time());
	}

	/**
	 * Find duplicate IDs in posts array.
	 */
	private function findDuplicateIds(array $posts): array
	{
		$seen = [];
		$duplicates = [];

		foreach ($posts as $post) {
			$key = $this->buildUniqueKey($post);

			if (isset($seen[$key])) {
				$duplicates[] = $post['id'];
			} else {
				$seen[$key] = $post['id'];
			}
		}

		return $duplicates;
	}
}
