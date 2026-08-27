<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SBRelay::$slow_endpoints raises the HTTP timeout from WP's 5s default to 120s.
 * TripAdvisor was missing from it, which was survivable while one request meant
 * one upstream call — and stopped being survivable when SMASH-1835 turned it into
 * a legacy -> Terra -> RapidAPI chain. Measured on staging 2026-08-21: a chained
 * reviews fetch died with `cURL error 28: Operation timed out after 5000
 * milliseconds with 0 bytes received`, while the calls that did land took 1.8s to
 * 4.4s — already at the ceiling.
 *
 * Source-level assertions: the plugin unit suite runs on plain PHPUnit with no WP
 * test framework, and SBRelay's constructor reads options, so the list is checked
 * where it is declared.
 */
final class RelaySlowEndpointsTest extends TestCase
{
	/** @return list<string> */
	private static function slowEndpoints(): array
	{
		$path = __DIR__ . '/../../class/Common/Integrations/SBRelay.php';
		self::assertFileExists($path, 'SBRelay.php not found at expected path');

		$src = (string) file_get_contents($path);

		$start = strpos($src, '$this->slow_endpoints = [');
		self::assertNotFalse($start, 'slow_endpoints must still be declared in SBRelay');

		$end = strpos($src, '];', $start);
		self::assertNotFalse($end, 'slow_endpoints declaration must be terminated');

		$block = substr($src, $start, $end - $start);

		// Strip comments first: an apostrophe in prose (`WP's`) would otherwise be
		// read as a string delimiter and desynchronise every match after it. Block
		// comments included, so a commented-out endpoint cannot count as present.
		$block = (string) preg_replace('#/\*.*?\*/#s', '', $block);
		$block = (string) preg_replace('#//[^\n]*#', '', $block);

		preg_match_all("/'([^']+)'/", $block, $matches);

		return $matches[1];
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
