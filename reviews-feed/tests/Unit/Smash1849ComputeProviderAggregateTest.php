<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed;

/**
 * SMASH-1849 / SMASH-1412 — Feed::compute_provider_aggregate() null contract.
 *
 * compute_provider_aggregate() is now `public static`. It returns a deduped
 * {count,rating} aggregate for EDD / WooCommerce (over the union of download /
 * product IDs) and `null` for every other provider — where `null` is the
 * signal the caller (compute_feed_level_aggregate) relies on to mean "no dedup
 * available, sum per-source instead". These tests pin that null contract, which
 * is the part of the method that is unit-testable without a WordPress/DB
 * environment.
 *
 * NOT covered here, on purpose — the deduped-aggregate half of the method is
 * NOT unit-testable without a DB. Once any real download/product ID is present,
 * the method constructs the Pro provider (`\SmashBalloon\Reviews\Pro\
 * Integrations\Providers\EDD` / `WooCommerce`, both autoloaded in this repo)
 * and calls `get_multi_source_info()`, which runs `get_post()`, post-thumbnail
 * lookups, and a `$wpdb` comment/commentmeta stats query. So these behaviours
 * live in the WP integration suite, not here:
 *   - single-source passthrough of a real aggregate;
 *   - two sources sharing IDs deduped, not double-counted (SMASH-1412);
 *   - the `review_count` / `average_rating` ?? `rating` fallback keys on the
 *     provider's RETURN value.
 * Reproducing them as a unit test would mean simulating WP_Post objects, the
 * thumbnail API, and a $wpdb result set — i.e. heavy mocking — which the ticket
 * explicitly asked us not to do. The guard tested below (empty ID set → null,
 * BEFORE the provider is touched) is what keeps these tests DB-free.
 */
class Smash1849ComputeProviderAggregateTest extends TestCase
{
	/**
	 * Providers other than EDD / WooCommerce are independent businesses
	 * (Google, Yelp, Trustpilot, TripAdvisor, WP.org) — summing per source is
	 * correct there, so the method returns null to defer to the caller's sum.
	 *
	 * @dataProvider nonDedupProviders
	 */
	public function test_non_dedup_provider_returns_null(string $provider): void
	{
		$sources = [
			['info' => ['id' => 'A', 'rating' => 4.7, 'total_rating' => 219]],
			['info' => ['id' => 'B', 'rating' => 5.0, 'total_rating' => 12]],
		];

		$this->assertNull(Feed::compute_provider_aggregate($provider, $sources));
	}

	public static function nonDedupProviders(): array
	{
		return [
			'google'      => ['google'],
			'yelp'        => ['yelp'],
			'trustpilot'  => ['trustpilot'],
			'tripadvisor' => ['tripadvisor'],
			'wordpressorg' => ['wordpressorg'],
			'unknown'     => ['not-a-real-provider'],
		];
	}

	public function test_edd_with_no_sources_returns_null(): void
	{
		$this->assertNull(Feed::compute_provider_aggregate('edd', []));
	}

	public function test_woocommerce_with_no_sources_returns_null(): void
	{
		$this->assertNull(Feed::compute_provider_aggregate('woocommerce', []));
	}

	/**
	 * A source that carries no download IDs contributes nothing; with every
	 * source empty the ID set is empty and the method short-circuits to null
	 * before ever constructing the provider (this is the DB-free guard).
	 */
	public function test_edd_sources_without_download_ids_return_null(): void
	{
		$sources = [
			['info' => ['downloads' => []]],
			['info' => ['direct_downloads' => []]],
			['info' => []],
			['info' => ['id' => 'EDD1', 'rating' => 4.9]],
		];

		$this->assertNull(Feed::compute_provider_aggregate('edd', $sources));
	}

	/**
	 * Downloads present but every entry has a falsy id (`!empty($d['id'])`
	 * guard) — all skipped, so the ID set is empty and the result is null.
	 */
	public function test_edd_skips_downloads_with_empty_ids(): void
	{
		$sources = [
			['info' => ['downloads' => [['id' => 0], ['id' => ''], ['name' => 'no id key']]]],
		];

		$this->assertNull(Feed::compute_provider_aggregate('edd', $sources));
	}

	/**
	 * `info` may arrive as a JSON string (as stored in the sources table); it
	 * is json_decoded before the downloads are read. An empty/idless decode
	 * still resolves to null without touching the provider.
	 */
	public function test_edd_info_as_json_string_with_no_downloads_returns_null(): void
	{
		$sources = [
			['info' => json_encode(['downloads' => []])],
			['info' => 'not valid json'],
			['info' => json_encode(['downloads' => [['id' => 0]]])],
		];

		$this->assertNull(Feed::compute_provider_aggregate('edd', $sources));
	}

	/**
	 * WooCommerce reads product IDs from `products` ?? `direct_products` ??
	 * `downloads`; none carrying a usable id → empty set → null, DB untouched.
	 */
	public function test_woocommerce_product_key_fallbacks_with_no_ids_return_null(): void
	{
		$sources = [
			['info' => ['products' => []]],
			['info' => ['direct_products' => [['id' => 0]]]],
			['info' => ['downloads' => [['name' => 'no id']]]],
			['info' => json_encode(['products' => []])],
		];

		$this->assertNull(Feed::compute_provider_aggregate('woocommerce', $sources));
	}
}
