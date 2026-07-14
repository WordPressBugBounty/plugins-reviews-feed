<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * SMASH-782 follow-up (2026-07-08) — shared flag-emoji derivation.
 *
 * The AliExpress buyer-country flag was computed inline in two places with
 * byte-identical logic: the feed author element (post-elements/author.php) and
 * the Review Alerts popup (review-alerts/popup.php). Util::country_flag_emoji()
 * now owns that computation so the feed and the alert render the same glyph
 * from the same rules. These tests pin the extracted helper against the exact
 * behaviour the two call sites relied on (2-letter/alpha validation, uppercase
 * normalisation, the 0x1F1E6 regional-indicator codepoint math, and the safe
 * empty-string fallback for anything not derivable).
 */
final class Smash782CountryFlagEmojiTest extends TestCase
{
	/**
	 * The regional-indicator pair the old inline code would have produced for
	 * a valid 2-letter code — recomputed independently here so the test does
	 * not just echo the implementation.
	 *
	 * @param string $cc
	 * @return string
	 */
	private function expected($cc)
	{
		$up     = strtoupper($cc);
		$offset = 0x1F1E6 - ord('A');
		return mb_chr(ord($up[0]) + $offset, 'UTF-8') . mb_chr(ord($up[1]) + $offset, 'UTF-8');
	}

	public function test_valid_uppercase_code_maps_to_flag(): void
	{
		$this->assertSame($this->expected('US'), Util::country_flag_emoji('US'));
		$this->assertSame($this->expected('DE'), Util::country_flag_emoji('DE'));
	}

	public function test_lowercase_is_normalised_before_mapping(): void
	{
		$this->assertSame(Util::country_flag_emoji('US'), Util::country_flag_emoji('us'));
	}

	public function test_surrounding_whitespace_is_trimmed(): void
	{
		$this->assertSame(Util::country_flag_emoji('GB'), Util::country_flag_emoji('  gb  '));
	}

	/**
	 * @dataProvider nonDerivableProvider
	 * @param mixed $input
	 */
	public function test_non_derivable_input_returns_empty_string($input): void
	{
		$this->assertSame('', Util::country_flag_emoji($input));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonDerivableProvider(): array
	{
		return [
			'empty'         => [''],
			'one letter'    => ['U'],
			'three letters' => ['USA'],
			'has digit'     => ['U1'],
			'symbol'        => ['@!'],
			'null'          => [null],
		];
	}
}
