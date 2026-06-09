<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Feed as ProFeed;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;

// SinglePostCache / MediaFinder reference this plugin constant at load time;
// the plugin defines it in bootstrap.php, which the unit-test bootstrap does
// not load. Define it so the classes autoload and the pre-fix run reaches the
// real `$single_review['source']` string-offset TypeError (not a load error).
if (!defined('SBR_POSTS_TABLE')) {
	define('SBR_POSTS_TABLE', 'sbr_reviews_posts');
}

/**
 * SMASH-1578 regression coverage.
 *
 * A customer on PHP 8.4 (poseidonpoolsandlandscape.ca, Reviews Feed Pro 2.6.2)
 * hit a front-end fatal:
 *
 *   Uncaught TypeError: Cannot access offset of type string on string
 *   in class/Pro/Feed.php:90
 *   #0 class/Common/Feed.php(252): ...->cache_single_posts_from_set(Array, 'ChIJ...')
 *
 * Root cause: cache_single_posts_from_set() / find_and_resize_media() assume
 * every element of the reviews payload is an array and immediately do
 * `new MediaFinder($single_review['source'])`. When the relay returns a
 * malformed / error-shaped reviews payload (the customer's debug log shows
 * reviewsSourceNotCreated 404 / invalidToken 401 / reviewsLicenseNotValid 403
 * for that place_id), an entry can be a scalar string. Accessing a string
 * offset by a string key is a hard TypeError on PHP 8.0+ — the customer's 8.4
 * stack just surfaced a latent crash, it is not 8.4-specific.
 *
 * These tests feed a malformed (scalar-only) payload through the real methods.
 * Pre-fix they throw TypeError on the first iteration. Post-fix every non-array
 * entry is skipped, so no SinglePostCache / MediaFinder is ever constructed and
 * the methods return cleanly without a fatal.
 */
class FeedMalformedPayloadTest extends TestCase
{
	/** @return list<mixed> A payload where every entry is a non-array scalar. */
	private function malformed_payload(): array
	{
		return ['ChIJJZ44LtE9O4gRzgkq8Gh6KWk', 12345, null, false, ''];
	}

	/**
	 * Array-shaped entries whose 'source' is missing or a scalar string. The
	 * first would emit an "Undefined array key" warning; the second is a real
	 * fatal in find_and_resize_media (direct read $single_review['source']['id']
	 * → string offset). Both must be skipped. (SMASH-1578 / PR #478 review.)
	 *
	 * @return list<array<string,mixed>>
	 */
	private function bad_source_payload(): array
	{
		return [
			['text' => 'no source key at all', 'rating' => 5],
			['text' => 'scalar source', 'source' => 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk'],
		];
	}

	/**
	 * Pro::cache_single_posts_from_set — the exact reported crash site
	 * (Pro/Feed.php:90, `new MediaFinder($single_review['source'])`).
	 */
	public function test_pro_cache_single_posts_from_set_skips_non_array_entries(): void
	{
		$feed = (new \ReflectionClass(ProFeed::class))->newInstanceWithoutConstructor();

		$feed->cache_single_posts_from_set($this->malformed_payload(), 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk');

		$this->assertTrue(true, 'malformed payload did not fatal in Pro::cache_single_posts_from_set');
	}

	/**
	 * Pro::find_and_resize_media — same `$single_review['source']` pattern
	 * (Pro/Feed.php:56-57), reachable from the media-finding cron path.
	 */
	public function test_pro_find_and_resize_media_skips_non_array_entries(): void
	{
		$feed = (new \ReflectionClass(ProFeed::class))->newInstanceWithoutConstructor();

		$result = $feed->find_and_resize_media($this->malformed_payload());

		$this->assertIsArray($result, 'malformed payload did not fatal in Pro::find_and_resize_media');
	}

	/**
	 * find_and_resize_media does a direct nested read $single_review['source']['id'],
	 * so it must skip array entries whose 'source' is missing or a scalar string
	 * (a string offset would fatal). cache_single_posts_from_set intentionally does
	 * NOT skip these — MediaFinder handles a scalar/missing source safely there, and
	 * skipping would drop otherwise-cacheable reviews (PR #478 review follow-up).
	 */
	public function test_pro_find_and_resize_media_skips_bad_source_entries(): void
	{
		$feed = (new \ReflectionClass(ProFeed::class))->newInstanceWithoutConstructor();

		$result = $feed->find_and_resize_media($this->bad_source_payload());

		$this->assertIsArray($result, 'missing/scalar source did not fatal in find_and_resize_media');
	}

	/**
	 * Common::add_source_to_post_set runs upstream (in api_request) and writes a
	 * 'source' offset onto each review entry. A string entry in a 0-indexed list
	 * would make that write a fatal string-offset assignment (SMASH-1578 / PR #478
	 * Sentry review). Non-array entries must be skipped; array entries still get
	 * their source stamped.
	 */
	public function test_add_source_to_post_set_skips_non_array_entries(): void
	{
		$feed = (new \ReflectionClass(CommonFeed::class))->newInstanceWithoutConstructor();

		$source = ['info' => ['id' => 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk', 'url' => 'https://example.test'], 'account_id' => 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk'];
		$post_set = ['data' => ['reviews' => [
			['text' => 'valid one'],
			'an error-shaped string entry',
			['text' => 'valid two'],
		]]];

		$result = $feed->add_source_to_post_set($source, $post_set);
		$reviews = $result['data']['reviews'];

		$this->assertIsArray($reviews[0]['source'], 'array entry should be stamped with source');
		$this->assertSame('an error-shaped string entry', $reviews[1], 'string entry left untouched, no fatal');
		$this->assertIsArray($reviews[2]['source'], 'later array entry still stamped');
	}

	/**
	 * The reviews CONTAINER itself can be a scalar on an error-shaped payload
	 * (e.g. 'reviews' => 'error message'). isset($reviews[0]) is fooled by
	 * string-offset semantics, so the loop must be guarded by an is_array check
	 * on the container or `foreach` emits a warning (this suite fails on
	 * warnings). Returns the post_set untouched. (PR #478 review follow-up.)
	 */
	public function test_add_source_to_post_set_handles_scalar_reviews_container(): void
	{
		$feed = (new \ReflectionClass(CommonFeed::class))->newInstanceWithoutConstructor();

		$source = ['info' => ['id' => 'ChIJ', 'url' => ''], 'account_id' => 'ChIJ'];
		$post_set = ['data' => ['reviews' => 'error message from the relay']];

		$result = $feed->add_source_to_post_set($source, $post_set);

		$this->assertSame('error message from the relay', $result['data']['reviews'], 'scalar reviews container returned untouched, no warning');
	}

	/**
	 * Common::cache_single_posts_from_set — the Free-side variant of the loop
	 * must be equally defensive.
	 */
	public function test_common_cache_single_posts_from_set_skips_non_array_entries(): void
	{
		$feed = (new \ReflectionClass(CommonFeed::class))->newInstanceWithoutConstructor();

		$feed->cache_single_posts_from_set($this->malformed_payload(), 'ChIJJZ44LtE9O4gRzgkq8Gh6KWk');

		$this->assertTrue(true, 'malformed payload did not fatal in Common::cache_single_posts_from_set');
	}
}
