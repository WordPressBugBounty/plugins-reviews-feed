<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;

/**
 * SBRelay::$slow_endpoints raises the HTTP timeout from WP's 5s default to 120s.
 * TripAdvisor was missing from it, which was survivable while one request meant
 * one upstream call — and stopped being survivable when SMASH-1835 turned it into
 * a legacy -> Terra -> RapidAPI chain. Measured on staging 2026-08-21: a chained
 * reviews fetch died with `cURL error 28: Operation timed out after 5000
 * milliseconds with 0 bytes received`, while the calls that did land took 1.8s to
 * 4.4s — already at the ceiling.
 *
 * The list is SBRelay::SLOW_ENDPOINTS, read without constructing SBRelay (whose
 * constructor reads options).
 */
final class RelaySlowEndpointsTest extends TestCase
{
	/** @return list<string> */
	private static function slowEndpoints(): array
	{
		// A public constant since SMASH-2434, so it's read as declared rather than parsed out of the source.
		return array_values(SBRelay::SLOW_ENDPOINTS);
	}

	public function test_tripadvisor_reviews_and_sources_get_the_longer_timeout(): void
	{
		$endpoints = self::slowEndpoints();

		$this->assertContains(
			'reviews/tripadvisor',
			$endpoints,
			'The fallback chain can cost three upstream hops; 5s cuts it off mid-chain.'
		);
		$this->assertContains(
			'sources/tripadvisor',
			$endpoints,
			'Source lookups walk the same chain as reviews.'
		);
	}

	/**
	 * The bug was not "TripAdvisor was forgotten" so much as "nothing said the
	 * pair had to move together". Every relay-proxied provider in the list needs
	 * both halves: a source that resolves but whose reviews time out is an empty
	 * feed with no error.
	 */
	public function test_every_listed_provider_has_both_halves(): void
	{
		$endpoints = self::slowEndpoints();

		$providers = [];
		foreach ($endpoints as $endpoint) {
			// booking/resolve is a third, provider-specific route; auth/license
			// is not a provider at all.
			if (substr_count($endpoint, '/') !== 1) {
				continue;
			}

			list($kind, $provider) = explode('/', $endpoint);

			if ($kind !== 'sources' && $kind !== 'reviews') {
				continue;
			}

			$providers[$provider][$kind] = true;
		}

		$this->assertNotEmpty($providers, 'Parsed no providers — the parser, not the list, is wrong.');

		foreach ($providers as $provider => $halves) {
			$this->assertArrayHasKey(
				'sources',
				$halves,
				sprintf('%s has reviews/ in slow_endpoints but not sources/.', $provider)
			);
			$this->assertArrayHasKey(
				'reviews',
				$halves,
				sprintf('%s has sources/ in slow_endpoints but not reviews/.', $provider)
			);
		}
	}
}
