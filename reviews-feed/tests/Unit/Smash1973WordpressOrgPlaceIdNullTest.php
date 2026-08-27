<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1973 — get_place_id_wordpressorg() raised two PHP 8 deprecations per
 * call when handed anything that was not a string.
 *
 * Both callers can do that:
 *
 *   - RemoteRequest.php:190 reads a stored `info['url']`, and `info` is an empty
 *     string on a source row that never completed a fetch, so the key is absent.
 *   - SBR_Feed_Saver_Manager::process_source_apikey() reads `$data['providerIdUrl']`,
 *     which update_api_key() never sets — it builds $data with provider + apiKey only.
 *
 * Measured before the fix on PHP 8.2: `trim(): Passing null` and
 * `strpos(): Passing null`, three diagnostics per refresh including the
 * "Undefined array key" from the read itself.
 *
 * The return SHAPE is deliberately unchanged and an unusable url still yields an
 * empty slug, so callers behave exactly as they did. That is what the BC cases
 * below pin: this removes diagnostics, not behaviour.
 */
final class Smash1973WordpressOrgPlaceIdNullTest extends TestCase
{
	/**
	 * The regression: a non-string must not raise a diagnostic.
	 *
	 * Deprecations are not exceptions, so asserting the return value alone would
	 * pass on the old code too. The error handler is what makes this bite.
	 *
	 * @dataProvider nonStringProvider
	 * @param mixed $input
	 */
	public function test_a_non_string_url_raises_no_php_diagnostic($input, string $label): void
	{
		$raised = [];
		set_error_handler(static function ($errno, $errstr) use (&$raised) {
			$raised[] = $errstr;

			return true;
		});

		try {
			$result = SBR_Feed_Saver_Manager::get_place_id_wordpressorg($input);
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $raised, "{$label} raised: " . implode(' | ', $raised));
		$this->assertIsArray($result, "{$label} must still return the array shape");
		$this->assertArrayHasKey('type', $result);
		$this->assertArrayHasKey('slug', $result);
	}

	/**
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function nonStringProvider(): array
	{
		return [
			// The two shapes the real callers actually produce.
			'null (absent info.url / providerIdUrl)' => [null, 'null'],
			'empty string (info decoded to [])'      => ['', 'empty string'],
			// Defensive: a malformed row could hold either of these.
			'int'                                    => [0, 'int'],
			'array'                                  => [[], 'array'],
			'false'                                  => [false, 'false'],
		];
	}

	/**
	 * An unusable url yields an empty slug, exactly as before the fix, so the
	 * relay request the callers build is byte-identical.
	 *
	 * @dataProvider unusableProvider
	 * @param mixed $input
	 */
	public function test_an_unusable_url_still_yields_an_empty_slug($input): void
	{
		$result = SBR_Feed_Saver_Manager::get_place_id_wordpressorg($input);

		$this->assertSame('', $result['slug']);
		$this->assertSame('plugin', $result['type'], 'type defaults to plugin when "theme" is absent');
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function unusableProvider(): array
	{
		return [
			'null'         => [null],
			'empty string' => [''],
		];
	}

	/**
	 * A url with no path at all: `parse_url()` returns null for the PHP_URL_PATH
	 * component, which the old explode() received directly. Same class of bug,
	 * different door, and reachable from a stored url that is just a host.
	 */
	public function test_a_url_with_no_path_raises_no_diagnostic(): void
	{
		$raised = [];
		set_error_handler(static function ($errno, $errstr) use (&$raised) {
			$raised[] = $errstr;

			return true;
		});

		try {
			$result = SBR_Feed_Saver_Manager::get_place_id_wordpressorg('https://wordpress.org');
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $raised, 'path-less url raised: ' . implode(' | ', $raised));
		$this->assertSame('', $result['slug']);
	}

	/**
	 * BACKWARDS COMPATIBILITY (Rule 6) — the shapes that have always worked must
	 * still resolve to the same slug and type. If this suite ever fails, the fix
	 * changed behaviour rather than only silencing diagnostics.
	 *
	 * @dataProvider realUrlProvider
	 */
	public function test_real_urls_are_unchanged(string $url, string $type, string $slug): void
	{
		$this->assertSame(
			['type' => $type, 'slug' => $slug],
			SBR_Feed_Saver_Manager::get_place_id_wordpressorg($url),
			"Regressed on: {$url}"
		);
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function realUrlProvider(): array
	{
		return [
			'plugin, trailing slash'    => ['https://wordpress.org/plugins/instagram-feed/', 'plugin', 'instagram-feed'],
			'plugin, no trailing slash' => ['https://wordpress.org/plugins/instagram-feed', 'plugin', 'instagram-feed'],
			'theme, trailing slash'     => ['https://wordpress.org/themes/twentytwentythree/', 'theme', 'twentytwentythree'],
			'theme, no trailing slash'  => ['https://wordpress.org/themes/twentytwentythree', 'theme', 'twentytwentythree'],
			'reviews sub-path'          => ['https://wordpress.org/plugins/reviews-feed/reviews/', 'plugin', 'reviews'],
			'scheme-less plugin'        => ['wordpress.org/plugins/instagram-feed/', 'plugin', 'instagram-feed'],
		];
	}
}
