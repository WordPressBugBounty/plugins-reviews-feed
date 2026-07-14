<?php

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Providers\BookingCom;

/**
 * SMASH-782 — extract the Booking hotel id from real Booking.com URLs.
 *
 * Real Booking search-result / shared URLs are slug-only: no `?hotel_id=` and
 * no numeric segment in the `/hotel/<cc>/<slug>.html` path. Before this change
 * `extractSourceId()` returned null for them, so `add_booking_source()` fell
 * back to resolving the hotel by a slug-reconstructed NAME against the RapidAPI
 * `/v1/hotels/locations` search. That search is fuzzy and, when it misses,
 * silently returns the first result — so an OKKO URL resolved to a Mercure,
 * and hotels missing from the provider index (e.g. Classic Inn by Radacini)
 * failed outright.
 *
 * The numeric hotel id is present in the block params
 * (`all_sr_blocks` / `matching_block_id` / `highlighted_blocks`): the first
 * underscore-segment is the hotel id followed by a 2-digit block suffix.
 * Stripping the last two digits recovers the id. These expectations were
 * verified live against booking-com.p.rapidapi.com `/v1/hotels/data` — the
 * returned `url` slug matches the source URL for every case below.
 *
 * The expected ids are written as the raw block number with its 2-digit suffix
 * dropped, recomputed here rather than echoing the implementation.
 */
class Smash782BookingUrlHotelIdTest extends TestCase
{
	/**
	 * all_sr_blocks first segment -> hotel id (drop the 2-digit block suffix).
	 * Verified: /v1/hotels/data returns the matching Booking url slug.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function realUrlProvider(): array
	{
		return [
			// Classic Inn by Radacini (RO) — block 1532186901 -> 15321869
			'classic-inn (all_sr_blocks)' => [
				'https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html?aid=356980&all_sr_blocks=1532186901_424740064_2_1_0_1300458&dest_id=175&dest_type=country&matching_block_id=1532186901_424740064_2_1_0_1300458',
				'15321869',
			],
			// OKKO Hotels Paris Gare de l'Est (FR) — block 452357301 -> 4523573
			'okko (all_sr_blocks)' => [
				'https://www.booking.com/hotel/fr/okko-hotels-paris-gare-de-l-39-est.en-gb.html?all_sr_blocks=452357301_137263396_0_42_0_1087150&dest_id=-1456928&dest_type=city',
				'4523573',
			],
			// Heavens Edge (GR) — block 1141041904 -> 11410419
			'heavens-edge (all_sr_blocks)' => [
				'https://www.booking.com/hotel/gr/heavens-edge.en-gb.html?all_sr_blocks=1141041904_386953896_2_1_0&dest_id=1754883&dest_type=hotel',
				'11410419',
			],
			// matching_block_id alone must also work (some URLs drop all_sr_blocks)
			'matching_block_id only' => [
				'https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html?matching_block_id=1532186901_424740064_2_1_0',
				'15321869',
			],
			// highlighted_blocks alone
			'highlighted_blocks only' => [
				'https://www.booking.com/hotel/gr/heavens-edge.en-gb.html?highlighted_blocks=1141041904_386953896_2_1_0',
				'11410419',
			],
		];
	}

	/**
	 * @dataProvider realUrlProvider
	 */
	public function test_extracts_hotel_id_from_block_params(string $url, string $expected): void
	{
		$this->assertSame($expected, BookingCom::extractSourceId($url));
		// A URL we can extract from must NOT be sent through name-resolution.
		$this->assertFalse(BookingCom::needsResolution($url));
	}

	public function test_explicit_hotel_id_param_takes_priority_over_blocks(): void
	{
		$url = 'https://www.booking.com/hotel/us/x.html?hotel_id=99999&all_sr_blocks=1234567890_1_0';
		$this->assertSame('99999', BookingCom::extractSourceId($url));
	}

	public function test_numeric_path_segment_still_wins(): void
	{
		// Legacy shape /hotel/xx/name-123456.html — must keep working unchanged.
		$url = 'https://www.booking.com/hotel/us/some-hotel-123456.html?all_sr_blocks=9999999999_1';
		$this->assertSame('123456', BookingCom::extractSourceId($url));
	}

	public function test_pure_numeric_input_passthrough(): void
	{
		$this->assertSame('15321869', BookingCom::extractSourceId('15321869'));
	}

	public function test_slug_only_url_without_blocks_returns_null_and_needs_resolution(): void
	{
		// No hotel_id, no numeric path segment, no block params -> cannot extract;
		// falls back to the (name) resolution path unchanged.
		$url = 'https://www.booking.com/hotel/ro/some-hotel.en-gb.html?aid=123&dest_type=country';
		$this->assertNull(BookingCom::extractSourceId($url));
		$this->assertTrue(BookingCom::needsResolution($url));
	}

	public function test_block_segment_too_short_is_ignored(): void
	{
		// A <8-digit block first-segment would strip to <6 digits — not a plausible
		// hotel id, so we do not extract it (avoids inventing an id from junk).
		$url = 'https://www.booking.com/hotel/ro/x.en-gb.html?all_sr_blocks=123_456';
		$this->assertNull(BookingCom::extractSourceId($url));
	}

	/**
	 * Search-results / city URLs carry block params too, but the first block
	 * belongs to whatever hotel ranked first — and there is NO /hotel/<cc>/<slug>
	 * to verify against. Extracting there would silently pick a hotel the user
	 * never chose, so extraction is gated on the hotel-page path.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function nonHotelUrlProvider(): array
	{
		return [
			'searchresults URL' => ['https://www.booking.com/searchresults.html?dest_id=175&all_sr_blocks=1532186901_424740064_2_1_0&matching_block_id=1532186901_424740064_2_1_0'],
			'city URL'          => ['https://www.booking.com/city/ro/brasov.html?highlighted_blocks=1532186901_1_0'],
			'region URL'        => ['https://www.booking.com/region/ro/transylvania.html?all_sr_blocks=1532186901_1'],
		];
	}

	/**
	 * @dataProvider nonHotelUrlProvider
	 */
	public function test_block_params_on_non_hotel_url_do_not_extract(string $url): void
	{
		// No /hotel/<cc>/<slug> path -> must NOT invent an id from the block params.
		$this->assertNull(BookingCom::extractSourceId($url));
	}

	// --- Exact same-hotel guard (isSameHotelUrl) ---------------------------------

	public function test_same_hotel_url_matches_across_language_suffix(): void
	{
		// The user's URL (.en-gb.html, with query) and the relay's canonical URL
		// (.html, no query) are the SAME hotel — country + slug identical.
		$input     = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html?aid=1&all_sr_blocks=1532186901_1';
		$canonical = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.html';
		$this->assertTrue(BookingCom::isSameHotelUrl($input, $canonical));
	}

	public function test_different_slug_is_not_the_same_hotel(): void
	{
		// The real failure this guard exists for: an OKKO link resolving to a
		// Mercure must be rejected.
		$input     = 'https://www.booking.com/hotel/fr/okko-hotels-paris-gare-de-l-39-est.en-gb.html';
		$canonical = 'https://www.booking.com/hotel/fr/mercure-paris-gare-de-lyon-tgv.html';
		$this->assertFalse(BookingCom::isSameHotelUrl($input, $canonical));
	}

	public function test_same_slug_different_country_is_not_the_same_hotel(): void
	{
		$input     = 'https://www.booking.com/hotel/gr/heavens-edge.html';
		$canonical = 'https://www.booking.com/hotel/it/heavens-edge.html';
		$this->assertFalse(BookingCom::isSameHotelUrl($input, $canonical));
	}

	public function test_unparseable_url_fails_closed(): void
	{
		// A missing / non-hotel canonical URL must never be treated as a match.
		$input = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.html';
		$this->assertFalse(BookingCom::isSameHotelUrl($input, ''));
		$this->assertFalse(BookingCom::isSameHotelUrl($input, 'https://www.booking.com/searchresults.html'));
		$this->assertFalse(BookingCom::isSameHotelUrl('', $input));
	}

	public function test_slugs_differing_only_by_trailing_two_letters_are_distinct(): void
	{
		// Seer LOW-1: the old `-[a-z]{2}$` strip would collapse `base-xx` and
		// `base-yy` onto the same key. Identity must compare the untouched slug.
		$a = 'https://www.booking.com/hotel/ro/grand-hotel-bw.html';
		$b = 'https://www.booking.com/hotel/ro/grand-hotel-by.html';
		$this->assertFalse(BookingCom::isSameHotelUrl($a, $b));
		// ...but the same hotel across the .en-gb language form still matches.
		$this->assertTrue(BookingCom::isSameHotelUrl(
			'https://www.booking.com/hotel/ro/grand-hotel-bw.en-gb.html?aid=1',
			'https://www.booking.com/hotel/ro/grand-hotel-bw.html'
		));
	}

	// --- add_booking_source guard decision (sourceUrlHotelMismatch) --------------
	// Pins the live AJAX handler's fail-closed wiring without driving the whole
	// wp_ajax handler: numeric-ID skips, matching canonical proceeds, mismatched
	// or missing canonical rejects.

	public function test_guard_skips_bare_numeric_id_input(): void
	{
		// No URL to validate against — the user chose the id explicitly. Allow.
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch('15321869', ''));
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch('15321869', 'anything'));
	}

	public function test_guard_skips_non_hotel_url_input(): void
	{
		// A search/city URL never carries a validatable slug; extraction already
		// returns null for it, and the guard does not fire on it either.
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch('https://www.booking.com/searchresults.html?hotel_id=1', ''));
	}

	public function test_guard_allows_matching_hotel_url(): void
	{
		$input     = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html?all_sr_blocks=1532186901_1';
		$canonical = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.html';
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch($input, $canonical));
	}

	public function test_guard_rejects_mismatched_hotel_url(): void
	{
		// OKKO link, relay came back with a Mercure -> must reject.
		$input     = 'https://www.booking.com/hotel/fr/okko-hotels-paris-gare-de-l-39-est.en-gb.html';
		$canonical = 'https://www.booking.com/hotel/fr/mercure-paris-gare-de-lyon-tgv.html';
		$this->assertTrue(BookingCom::sourceUrlHotelMismatch($input, $canonical));
	}

	public function test_guard_allows_hotel_url_when_canonical_missing(): void
	{
		// Opportunistic: with no comparable canonical URL (e.g. an older relay not
		// yet emitting canonical_url) there is no PROVABLE mismatch, so we don't
		// hard-fail the import — validation activates once the relay provides it.
		$input = 'https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html';
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch($input, ''));
		$this->assertFalse(BookingCom::sourceUrlHotelMismatch($input, 'https://www.booking.com/hotel/index.html?hotel_id=15321869'));
	}

	// --- extractUrlComponents (name derivation) — pins behavior across the DRY --
	// refactor that routes it through parseHotelUrl().

	public function test_extract_url_components_derives_name_country_slug(): void
	{
		$c = BookingCom::extractUrlComponents('https://www.booking.com/hotel/ro/classic-inn-by-radacini.en-gb.html?aid=1');
		$this->assertSame('ro', $c['country']);
		$this->assertSame('classic-inn-by-radacini', $c['slug']);
		$this->assertSame('Classic Inn By Radacini', $c['hotel_name']);
	}

	public function test_extract_url_components_strips_trailing_language_suffix_for_name(): void
	{
		// A trailing hyphenated 2-letter segment is treated as a language marker
		// when reconstructing the NAME (distinct from identity — see parseHotelUrl).
		$c = BookingCom::extractUrlComponents('https://www.booking.com/hotel/gr/villa-le-gb.html');
		$this->assertSame('villa-le', $c['slug']);
		$this->assertSame('Villa Le', $c['hotel_name']);
	}

	public function test_extract_url_components_null_for_non_hotel_url(): void
	{
		$this->assertNull(BookingCom::extractUrlComponents('https://www.booking.com/searchresults.html?x=1'));
	}

	public function test_extract_url_components_pulls_dest_id_from_search_result_url(): void
	{
		// Search-result URLs carry the destination — forward it so the relay can
		// search that exact city + slug-match (resolves hotels name lookup can't place).
		$c = BookingCom::extractUrlComponents('https://www.booking.com/hotel/in/the-o.en-gb.html?aid=304142&dest_id=-2108361&dest_type=city&hpos=7');
		$this->assertSame('in', $c['country']);
		$this->assertSame('the-o', $c['slug']);
		$this->assertSame('-2108361', $c['dest_id']);
		$this->assertSame('city', $c['dest_type']);
	}

	public function test_extract_url_components_dest_id_empty_on_plain_hotel_url(): void
	{
		// A plain hotel-page share link has no dest_id — must not fabricate one.
		$c = BookingCom::extractUrlComponents('https://www.booking.com/hotel/in/arush-plaza.html?aid=304142&checkin=2026-07-14');
		$this->assertSame('', $c['dest_id']);
		$this->assertSame('', $c['dest_type']);
	}
}
