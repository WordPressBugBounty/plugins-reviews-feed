<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * Pins Util::map_wpml_to_google_language() — maps a WPML/locale code to a code the
 * Google Places API actually accepts, so reviews come back translated (SMASH-1617,
 * WPSA #62626). The reported case: a WPML `es-mx` site sent an unsupported code and
 * Google returned reviews untranslated; this maps it to `es-419`.
 *
 * Pure static (only reads the in-code supported-language list), so it runs in the
 * plain-PHPUnit suite.
 */
final class WpmlLanguageMappingTest extends TestCase
{
	/**
	 * @dataProvider provideCodes
	 *
	 * @param string|null $input
	 * @param string|null $expected
	 */
	public function test_map_wpml_to_google_language($input, $expected): void
	{
		$this->assertSame($expected, Util::map_wpml_to_google_language($input));
	}

	/** @return array<string, array{0: string|null, 1: string|null}> */
	public static function provideCodes(): array
	{
		return [
			// --- The reported regression: every Latin-American Spanish variant -> es-419 ---
			'es-mx -> es-419'          => ['es-mx', 'es-419'],
			'es_MX underscore'         => ['es_MX', 'es-419'],
			'ES-MX uppercase'          => ['ES-MX', 'es-419'],
			'es-ar -> es-419'          => ['es-ar', 'es-419'],
			'es-cl -> es-419'          => ['es-cl', 'es-419'],
			'es-co -> es-419'          => ['es-co', 'es-419'],
			'es-pe -> es-419'          => ['es-pe', 'es-419'],
			'es-ve -> es-419'          => ['es-ve', 'es-419'],
			'es-uy -> es-419'          => ['es-uy', 'es-419'],
			'es-419 exact'             => ['es-419', 'es-419'],

			// --- Spain Spanish stays es ---
			'es -> es'                 => ['es', 'es'],
			'es-es -> es'              => ['es-es', 'es'],
			'es-ES -> es'              => ['es-ES', 'es'],
			'es_ES underscore -> es'   => ['es_ES', 'es'],

			// --- Already-supported codes pass through unchanged (BC) ---
			'en exact'                 => ['en', 'en'],
			'en-GB exact'              => ['en-GB', 'en-GB'],
			'en-AU exact'              => ['en-AU', 'en-AU'],
			'fr exact'                 => ['fr', 'fr'],
			'fr-CA exact'              => ['fr-CA', 'fr-CA'],
			'pt exact'                 => ['pt', 'pt'],
			'pt-BR exact'              => ['pt-BR', 'pt-BR'],
			'pt-PT exact'              => ['pt-PT', 'pt-PT'],
			'zh exact'                 => ['zh', 'zh'],
			'zh-CN exact'              => ['zh-CN', 'zh-CN'],
			'zh-TW exact'              => ['zh-TW', 'zh-TW'],
			'hy (Armenian) exact'      => ['hy', 'hy'],

			// --- Case-insensitive match for region variants Google lists ---
			'en-gb -> en-GB'           => ['en-gb', 'en-GB'],
			'en-au -> en-AU'           => ['en-au', 'en-AU'],
			'pt-br -> pt-BR'           => ['pt-br', 'pt-BR'],
			'pt_br underscore -> pt-BR' => ['pt_br', 'pt-BR'],
			'fr-ca -> fr-CA'           => ['fr-ca', 'fr-CA'],
			'zh-tw -> zh-TW'           => ['zh-tw', 'zh-TW'],
			'zh-hk -> zh-HK'           => ['zh-hk', 'zh-HK'],

			// --- Region strip to a supported base language ---
			'en-us -> en'              => ['en-us', 'en'],
			'fr-fr -> fr'              => ['fr-fr', 'fr'],
			'de-de -> de'              => ['de-de', 'de'],
			'de_DE underscore -> de'   => ['de_DE', 'de'],
			'it-it -> it'              => ['it-it', 'it'],
			'nl-nl -> nl'              => ['nl-nl', 'nl'],
			'ja-jp -> ja'              => ['ja-jp', 'ja'],
			'ru-ru -> ru'              => ['ru-ru', 'ru'],
			'EN uppercase base -> en'  => ['EN', 'en'],

			// --- Unmappable -> null so the caller falls back (never sends a bogus code) ---
			'literal wpml -> null'     => ['wpml', null],
			'unknown 2-letter -> null' => ['xx', null],
			'unknown region -> null'   => ['xx-yy', null],
			'gibberish -> null'        => ['zz-zz', null],
			'empty string -> null'     => ['', null],
			'null -> null'             => [null, null],
		];
	}
}
