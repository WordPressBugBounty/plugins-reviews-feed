<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1706 — TripAdvisor "Add Source" sent the full listing URL to the relay
 * as `place_id` instead of the numeric location id, so the relay's
 * `location/{placeid}/details` call 404'd (sourceConnectionError).
 *
 * Root cause: get_place_id_tripadvisor() only extracted the id when a URL
 * segment contained ".html" AND matched `/-d+\d{0,10}-/` (a trailing dash).
 * TripAdvisor's short attraction URL (no ".html"), the ".ca"/".co.uk" domains,
 * and the bare location id all fell through and returned the full URL unchanged.
 *
 * The location id is the `-d<digits>` token, present in every TripAdvisor
 * listing URL form. This suite pins extraction across all of them, and keeps the
 * long ".html" form (the shape that historically worked) passing — that is the
 * backwards-compatibility guard.
 */
final class Smash1706TripAdvisorPlaceIdTest extends TestCase
{
	/**
	 * @dataProvider urlFormProvider
	 */
	public function test_extracts_location_id_from_every_url_form(string $input, string $expected): void
	{
		$this->assertSame(
			$expected,
			SBR_Feed_Saver_Manager::get_place_id_tripadvisor($input),
			"Failed extracting location id from: {$input}"
		);
	}

	public static function urlFormProvider(): array
	{
		return [
			// The customer's exact input forms (WPSA 69629) — all must yield 2422991.
			'full .com (customer)'        => ['https://www.tripadvisor.com/Attraction_Review-g154948-d2422991', '2422991'],
			'short .com'                  => ['https://tripadvisor.com/Attraction_Review-g154948-d2422991', '2422991'],
			'.ca domain'                  => ['https://tripadvisor.ca/Attraction_Review-g154948-d2422991', '2422991'],
			'bare location id'            => ['2422991', '2422991'],

			// Scheme-less pastes (FILTER_VALIDATE_URL rejects these) must still resolve.
			'scheme-less .com'            => ['tripadvisor.com/Attraction_Review-g154948-d2422991', '2422991'],
			'www. no scheme'              => ['www.tripadvisor.com/Attraction_Review-g154948-d2422991', '2422991'],

			// Backwards-compat: the long ".html" review URL that has always worked.
			'long .html (BC)'             => ['https://www.tripadvisor.com/Attraction_Review-g154948-d2422991-Reviews-Whistler_Mountain-Whistler_British_Columbia.html', '2422991'],

			// Other listing types / TLDs TripAdvisor surfaces.
			'restaurant .html'            => ['https://www.tripadvisor.com/Restaurant_Review-g60763-d477942-Reviews-Katz_s_Delicatessen-New_York_City.html', '477942'],
			'hotel .co.uk .html'          => ['https://www.tripadvisor.co.uk/Hotel_Review-g186338-d193089-Reviews-The_Savoy.html', '193089'],
		];
	}
}
