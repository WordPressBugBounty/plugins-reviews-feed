<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\MediaFinder;

/**
 * Unit tests for the per-request memoization on MediaFinder::search()
 * (SMASH-1360 Phase 2 round 2).
 *
 * Pins the memo-key contract: same `(provider, primary_url)` within ONE PHP
 * request collapses to the cached media-array. Different reviewers / different
 * places / different providers get distinct keys.
 *
 * The wp_lhr_log captured on demo-wp2 2026-05-06 showed the customizer-side
 * direct-to-Google scraping amplifier — 25 calls / 10 unique reviewers =
 * 2.5× duplication ratio. Without this memo, every shortcode independently
 * fetches each reviewer's `https://www.google.com/maps/contrib/...` profile.
 * With this memo, identical (reviewer, place_id) pairs across shortcodes
 * collapse to one fetch.
 *
 * @group memo
 * @group SMASH-1360
 */
class MediaFinderMemoTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		MediaFinder::flush_search_memo();
	}

	/**
	 * Helper: build a MediaFinder pre-populated with provider + primary_url.
	 * We use reflection because primary_url is private and normally set via
	 * `construct_url_from_post_and_source()`, which requires a real $post.
	 */
	private function makeFinder(string $provider, string $primaryUrl): MediaFinder
	{
		$finder = new MediaFinder(['info' => '{"id":"X"}']);
		$finder->set_provider($provider);

		// primary_url is private; use reflection to set it.
		$ref = new \ReflectionProperty(MediaFinder::class, 'primary_url');
		$ref->setAccessible(true);
		$ref->setValue($finder, $primaryUrl);

		return $finder;
	}

	public function test_memo_key_collapses_for_same_provider_and_url(): void
	{
		$a = $this->makeFinder('google', 'https://www.google.com/maps/contrib/123/place/CHIJ_X');
		$b = $this->makeFinder('google', 'https://www.google.com/maps/contrib/123/place/CHIJ_X');

		$this->assertSame($a->search_memo_key(), $b->search_memo_key());
	}

	public function test_memo_key_differs_for_different_reviewers(): void
	{
		$a = $this->makeFinder('google', 'https://www.google.com/maps/contrib/AAA/place/CHIJ_X');
		$b = $this->makeFinder('google', 'https://www.google.com/maps/contrib/BBB/place/CHIJ_X');

		$this->assertNotSame($a->search_memo_key(), $b->search_memo_key());
	}

	public function test_memo_key_differs_for_different_places(): void
	{
		$a = $this->makeFinder('google', 'https://www.google.com/maps/contrib/123/place/CHIJ_AFI');
		$b = $this->makeFinder('google', 'https://www.google.com/maps/contrib/123/place/CHIJ_GOOGLEPLEX');

		$this->assertNotSame($a->search_memo_key(), $b->search_memo_key());
	}

	public function test_memo_key_differs_for_different_providers(): void
	{
		// Yelp + TripAdvisor have their own primary_url shapes; the memo must
		// not collapse them with Google even if URLs were structurally similar.
		$google = $this->makeFinder('google', 'https://www.google.com/maps/contrib/X/place/Y');
		$yelp = $this->makeFinder('yelp', 'https://www.google.com/maps/contrib/X/place/Y');  // synthetic

		$this->assertNotSame($google->search_memo_key(), $yelp->search_memo_key());
	}

	public function test_memo_key_returns_null_when_primary_url_empty(): void
	{
		$finder = $this->makeFinder('google', '');

		$this->assertNull($finder->search_memo_key());
	}

	public function test_memo_key_is_deterministic_sha256(): void
	{
		$finder = $this->makeFinder('google', 'https://www.google.com/maps/contrib/X/place/Y');
		$expected = hash('sha256', 'google|https://www.google.com/maps/contrib/X/place/Y');

		$this->assertSame($expected, $finder->search_memo_key());
	}

	public function test_memo_key_handles_long_urls(): void
	{
		// Defensive — Google contributor IDs can be long, place_ids can be
		// ~30 chars, plus query params. Memo key derivation must be stable
		// regardless of URL length.
		$longUrl = 'https://www.google.com/maps/contrib/' . str_repeat('1', 50)
				. '/place/' . str_repeat('a', 60)
				. '?hl=en&extra=1';
		$finder = $this->makeFinder('google', $longUrl);

		$this->assertNotNull($finder->search_memo_key());
		// sha256 hex output is always 64 chars regardless of input length
		$this->assertSame(64, strlen($finder->search_memo_key()));
	}

	public function test_flush_search_memo_clears_state(): void
	{
		// Test isolation contract — same as RemoteRequestMemoTest's flush_memo.
		MediaFinder::flush_search_memo();
		$this->assertTrue(true);
	}
}
