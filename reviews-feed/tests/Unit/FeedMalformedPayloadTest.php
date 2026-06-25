<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Feed as ProFeed;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\Util;

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

	/**
	 * SMASH-1587: a review whose 'provider' is a scalar slug (e.g. 'google')
	 * instead of ['name' => 'google'] crashed the front end — every
	 * SinglePostCache read of $post['provider']['name'] (resize_avatar:146,
	 * resize_image:88, store:293, …) is a "Cannot access offset of type string
	 * on string" fatal on PHP 8. Both SinglePostCache constructors now route
	 * post_data through normalize_review_shape(), coercing a string provider
	 * into the array shape the cache + display code expects. SMASH-1578 guarded
	 * the review + source shapes but not provider, so 2.6.3 still crashed.
	 *
	 * Single source of truth: Util::normalize_review_shape() — used by both
	 * SinglePostCache constructors AND every raw/DB-decoded read site
	 * (PostAggregator dedup, parse_single_review, duplicate_collection).
	 */
	private function normalizeProvider($input)
	{
		return Util::normalize_review_shape($input);
	}

	public function test_string_provider_is_coerced_to_array_shape(): void
	{
		$out = $this->normalizeProvider([
			'provider'  => 'google',
			'review_id' => 'abc',
			'reviewer'  => ['name' => 'Jane'],
		]);

		$this->assertSame(['name' => 'google'], $out['provider'], 'scalar provider slug wrapped as [name => slug]');
		// Now the previously-fatal read is a safe array access.
		$this->assertSame('google', $out['provider']['name']);
	}

	public function test_array_provider_is_left_untouched(): void
	{
		$provider = ['name' => 'yelp', 'id' => 'biz-123'];
		$out = $this->normalizeProvider(['provider' => $provider, 'review_id' => 'x']);

		$this->assertSame($provider, $out['provider'], 'already-correct array provider is not altered');
	}

	public function test_missing_or_nonstring_provider_becomes_empty_named_array(): void
	{
		$missing = $this->normalizeProvider(['review_id' => 'x']);
		$this->assertSame(['name' => ''], $missing['provider'], 'absent provider gets a safe [name => ""] shape');

		$numeric = $this->normalizeProvider(['provider' => 123, 'review_id' => 'x']);
		$this->assertSame(['name' => ''], $numeric['provider'], 'non-string scalar provider falls back to [name => ""]');
	}

	/**
	 * PR #484 Copilot review: an array provider that is missing 'name' (or has a
	 * non-string name) must still come out with a present string name — otherwise
	 * the $review['provider']['name'] reads hit "Undefined array key" notices and
	 * produce empty/incorrect dedup keys. Other provider keys are preserved.
	 */
	public function test_array_provider_missing_name_gets_empty_string_name(): void
	{
		$missingName = $this->normalizeProvider(['provider' => ['id' => 'biz-1'], 'review_id' => 'x']);
		$this->assertSame('', $missingName['provider']['name'], 'missing name filled with empty string');
		$this->assertSame('biz-1', $missingName['provider']['id'], 'other provider keys preserved');

		$nonStringName = $this->normalizeProvider(['provider' => ['name' => 123], 'review_id' => 'x']);
		$this->assertSame('', $nonStringName['provider']['name'], 'non-string name coerced to empty string');
	}

	public function test_non_array_post_data_is_passed_through_untouched(): void
	{
		// Scalar reviews are skipped by the Feed is_array() guards before
		// construction; normalize must not choke on them either.
		$this->assertSame('error-string', $this->normalizeProvider('error-string'));
		$this->assertNull($this->normalizeProvider(null));
	}

	/**
	 * WPSA-63160 follow-up: the dedup key build (remove_duplicated_posts_list,
	 * 'json' branch, every front-end render) reads source['id'] + reviewer['name']
	 * off the RAW post. A scalar reviewer/source — which the is_array($single_review)
	 * -only cache guard lets through to store() — would fatal there on PHP 8 just
	 * like the provider did. normalize_review_shape now coerces them too.
	 */
	public function test_scalar_reviewer_and_source_are_coerced_to_arrays(): void
	{
		$out = $this->normalizeProvider([
			'provider' => 'google',
			'reviewer' => 'Jane Doe',                 // scalar — would fatal at reviewer['name']
			'source'   => 'ChIJscalarSource',         // scalar — would fatal at source['id'] in the json branch
			'rating'   => 5,
		]);

		$this->assertIsArray($out['reviewer'], 'scalar reviewer coerced to array');
		$this->assertSame('', $out['reviewer']['name'], 'reviewer name read key present + empty');
		$this->assertSame('', $out['reviewer']['avatar'], 'reviewer avatar read key present');
		$this->assertIsArray($out['source'], 'scalar source coerced to array');
		$this->assertSame('', $out['source']['id'], 'source id read key present + empty');
		$this->assertSame('', $out['source']['url'], 'source url read key present');

		// The exact dedup key build that fatals pre-fix now runs clean.
		$key = $out['source']['id'] . '-' . $out['rating'] . '-' . $out['reviewer']['name'] . '-' . $out['provider']['name'];
		$this->assertSame('-5--google', $key);
	}

	public function test_healthy_reviewer_and_source_are_preserved(): void
	{
		$reviewer = ['name' => 'Jane', 'avatar' => 'https://x/a.png', 'first_name' => 'Jane'];
		$source   = ['id' => 'place-9', 'url' => 'https://example.test'];
		$out = $this->normalizeProvider([
			'provider' => ['name' => 'google'],
			'reviewer' => $reviewer,
			'source'   => $source,
		]);

		$this->assertSame('Jane', $out['reviewer']['name'], 'healthy reviewer name untouched');
		$this->assertSame('Jane', $out['reviewer']['first_name'], 'extra reviewer keys preserved');
		$this->assertSame('place-9', $out['source']['id'], 'healthy source id untouched');
		$this->assertSame('https://example.test', $out['source']['url'], 'healthy source url untouched');
	}

	public function test_array_reviewer_missing_name_gets_empty_string_name(): void
	{
		// reviewer present but missing the read keys (partial relay shape).
		$out = $this->normalizeProvider(['reviewer' => ['first_name' => 'Jo'], 'source' => ['id' => 's1']]);
		$this->assertSame('', $out['reviewer']['name'], 'missing reviewer name filled');
		$this->assertSame('', $out['reviewer']['avatar'], 'missing reviewer avatar filled');
		$this->assertSame('Jo', $out['reviewer']['first_name'], 'existing reviewer key preserved');
		$this->assertSame('s1', $out['source']['id'], 'existing source id preserved');
		$this->assertSame('', $out['source']['url'], 'missing source url filled');
	}

	/**
	 * Audit follow-up: the image containers (media / reviews_photos) are iterated
	 * by resize_images() + add_local_image_urls(). A scalar there fatals the
	 * foreach / string-offset write on PHP 8 (verified raw on 8.4). normalize now
	 * coerces a present non-array container to [] (element-level scalars are
	 * additionally guarded at the loop sites).
	 */
	public function test_scalar_media_and_reviews_photos_coerced_to_arrays(): void
	{
		$out = $this->normalizeProvider(['provider' => 'google', 'media' => 'oops', 'reviews_photos' => 'x']);
		$this->assertSame([], $out['media'], 'scalar media coerced to []');
		$this->assertSame([], $out['reviews_photos'], 'scalar reviews_photos coerced to []');
	}

	public function test_absent_image_containers_are_not_added(): void
	{
		// media/reviews_photos are optional — don't fabricate keys (would flip !empty checks).
		$out = $this->normalizeProvider(['provider' => 'google']);
		$this->assertArrayNotHasKey('media', $out, 'absent media stays absent');
		$this->assertArrayNotHasKey('reviews_photos', $out, 'absent reviews_photos stays absent');
	}

	public function test_healthy_media_array_preserved(): void
	{
		$media = [['type' => 'image', 'url' => 'https://x/1.jpg']];
		$out = $this->normalizeProvider(['media' => $media, 'provider' => ['name' => 'google']]);
		$this->assertSame($media, $out['media'], 'well-formed media array left intact');
	}

	/**
	 * PR #482 Copilot: a non-string source['id'] must coerce safely — cast a scalar
	 * (numeric id), but turn an array/object into '' rather than (string)-casting it
	 * (which would emit an "Array to string conversion" notice — the opposite of the
	 * normalizer's no-warning goal).
	 */
	public function test_non_string_source_id_coerced_without_array_to_string(): void
	{
		$arrayId = $this->normalizeProvider(['source' => ['id' => ['nested' => 'x'], 'url' => 'u']]);
		$this->assertSame('', $arrayId['source']['id'], 'array source id -> empty string (no Array-to-string)');

		$numericId = $this->normalizeProvider(['source' => ['id' => 12345]]);
		$this->assertSame('12345', $numericId['source']['id'], 'numeric source id cast to string');
	}

	/**
	 * End-to-end on the real Util::parse_single_review() — the store-path reader
	 * (PostAggregator::get_related_reviews, duplicate_collection). A scalar
	 * provider fatally crashed its $review['provider']['name'] read pre-fix;
	 * now it's normalized at the method entry.
	 */
	public function test_parse_single_review_survives_string_provider(): void
	{
		$review = [
			'time'     => '1700000000',
			'rating'   => 5,
			'text'     => 'Great service',
			'reviewer' => ['name' => 'Jane Doe', 'avatar' => ''],
			'provider' => 'google', // scalar slug — pre-fix this fatals at the provider read
			'source'   => ['id' => 'p1', 'url' => 'https://example.test'],
		];

		$out = Util::parse_single_review($review, 'p1', 'r1');

		$this->assertSame('google', $out['provider']['name'], 'string provider normalized + parsed, no fatal');
		$this->assertSame('Jane Doe', $out['reviewer']['name']);
	}
}
