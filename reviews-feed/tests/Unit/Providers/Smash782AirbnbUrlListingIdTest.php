<?php

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Providers\Airbnb;

/**
 * SMASH-782 — extract the Airbnb listing id from real Airbnb URLs.
 *
 * Guests copy the listing URL from whatever Airbnb country domain they browse
 * on (airbnb.co.in, airbnb.co.uk, airbnb.com.au, airbnb.de, ...), not only
 * airbnb.com. The original `/(?:www\.)?airbnb\.com\/rooms\/(\d+)/` matched the
 * .com TLD only, so a `.co.in` URL returned null and the Add Source AJAX handler
 * rejected it as "Invalid Airbnb listing URL". The widened pattern accepts any
 * airbnb ccTLD; the numeric-passthrough branch and .com URLs are unchanged.
 */
class Smash782AirbnbUrlListingIdTest extends TestCase
{
	/**
	 * Country-domain URLs that must now resolve to their listing id.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function countryDomainUrlProvider(): array
	{
		return [
			// The reported case: India domain + a 19-digit new-format listing id.
			'co.in (19-digit id, full query)' => [
				'https://www.airbnb.co.in/rooms/1676850149364052671?search_mode=regular_search&adults=1&check_in=2026-07-19&review_page_entrypoint=pdp_header',
				'1676850149364052671',
			],
			'co.uk'          => ['https://www.airbnb.co.uk/rooms/555', '555'],
			'com.au'         => ['https://www.airbnb.com.au/rooms/777?x=1', '777'],
			'de'             => ['https://www.airbnb.de/rooms/888', '888'],
			'fr (no www)'    => ['https://airbnb.fr/rooms/111', '111'],
			'co.in bare host' => ['airbnb.co.in/rooms/222', '222'],
		];
	}

	/**
	 * @dataProvider countryDomainUrlProvider
	 */
	public function test_extracts_listing_id_from_country_domain(string $url, string $expected): void
	{
		$this->assertSame($expected, Airbnb::extractSourceId($url));
	}

	/**
	 * BC: the .com URL shapes documented before the change must keep working.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function dotComUrlProvider(): array
	{
		return [
			'www.airbnb.com'   => ['https://www.airbnb.com/rooms/12345678', '12345678'],
			'airbnb.com no www' => ['https://airbnb.com/rooms/999', '999'],
			'bare host'        => ['www.airbnb.com/rooms/424242', '424242'],
			'with query'       => ['https://www.airbnb.com/rooms/321?adults=2', '321'],
		];
	}

	/**
	 * @dataProvider dotComUrlProvider
	 */
	public function test_dot_com_urls_still_resolve(string $url, string $expected): void
	{
		$this->assertSame($expected, Airbnb::extractSourceId($url));
	}

	public function test_pure_numeric_input_passthrough(): void
	{
		$this->assertSame('1676850149364052671', Airbnb::extractSourceId('1676850149364052671'));
		$this->assertSame('12345678', Airbnb::extractSourceId('12345678'));
	}

	public function test_short_numeric_input_is_rejected(): void
	{
		// The direct-id branch requires > 5 digits; anything shorter is not an id.
		$this->assertNull(Airbnb::extractSourceId('12345'));
	}

	public function test_non_airbnb_url_returns_null(): void
	{
		$this->assertNull(Airbnb::extractSourceId('https://www.booking.com/rooms/12345678'));
		$this->assertNull(Airbnb::extractSourceId('not a url'));
		$this->assertNull(Airbnb::extractSourceId('https://www.airbnb.co.in/experiences/12345678'));
	}
}
