<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * Pins Util::is_tripadvisor_terra_key() — the discriminator that decides whether
 * a site sees the Content API sunset notice (SMASH-1835).
 *
 * TripAdvisor deprecates every legacy Content API key on 2026-08-31. Legacy keys
 * are 32 hex characters, Terra issues UUIDs, and the two sets cannot overlap,
 * which is what lets both the relay route per key and the plugin warn only the
 * sites that actually have to act. Get this wrong in either direction and the
 * cost is real: a false positive nags someone who already migrated, a false
 * negative leaves a site silently heading into a dead API.
 *
 * The shipped contract is a denylist, not an allowlist: the notice fires for any
 * stored key that is NOT a Terra UUID, rather than only for a 32-hex one. Those
 * agree today, because 32-hex and UUIDs are the only two shapes in the field.
 * They diverge if TripAdvisor ever issues a Terra credential in a third shape,
 * and it errs toward warning — deliberately, since a missed warning ends in a
 * dead feed while a spurious one is a support conversation.
 *
 * Mirror of TripAdvisor::looksLikeTerraKey() in sb-relay — if one moves, so does
 * the other.
 *
 * Pure static, so it runs in the plain-PHPUnit suite with no WordPress.
 */
final class Smash1835TripAdvisorKeyShapeTest extends TestCase
{
	/**
	 * @dataProvider provideKeys
	 *
	 * @param string $key
	 * @param bool   $expected
	 */
	public function test_is_tripadvisor_terra_key($key, $expected): void
	{
		$this->assertSame($expected, Util::is_tripadvisor_terra_key($key));
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function provideKeys(): array
	{
		return [
			// --- Terra: UUID layout. Shape taken from a real Terra key. ---
			'uuid lowercase' => ['3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0d47', true],
			'uuid uppercase' => ['3F1C9A52-7B64-4D0E-9C31-8A5F2E6B0D47', true],
			'uuid mixed case' => ['3f1C9a52-7B64-4d0E-9c31-8A5f2E6b0D47', true],

			// --- Legacy: 32 hex, no dashes. Must never read as Terra, or the
			// site loses the notice and walks into the sunset unwarned. ---
			'legacy 32 hex' => ['a3f19c527b644d0e9c318a5f2e6b0d47', false],
			'legacy 32 hex uppercase' => ['A3F19C527B644D0E9C318A5F2E6B0D47', false],

			// --- Near misses. A UUID-ish string that is not one must not pass. ---
			'uuid minus one char' => ['3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0d4', false],
			'uuid plus one char' => ['3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0d477', false],
			'uuid with a non-hex letter' => ['3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0dZZ', false],
			'dashes in the wrong places' => ['3f1c9a5-27b644-d0e9c31-8a5f2e6b0d47', false],
			'32 hex with dashes stripped from a uuid' => ['3f1c9a527b644d0e9c318a5f2e6b0d47', false],

			// --- Junk. None of this should be treated as migrated. ---
			'empty' => ['', false],
			'whitespace only' => ['   ', false],
			'uuid with surrounding whitespace' => [' 3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0d47 ', false],
			'arbitrary word' => ['not-a-key', false],
		];
	}

	/**
	 * The helper takes whatever is in the options row, which is not guaranteed to
	 * be a string. A non-string must be "not migrated" rather than a TypeError on
	 * preg_match().
	 *
	 * @dataProvider provideNonStrings
	 *
	 * @param mixed $key
	 */
	public function test_non_string_is_not_a_terra_key($key): void
	{
		$this->assertFalse(Util::is_tripadvisor_terra_key($key));
	}

	/** @return array<string, array{0: mixed}> */
	public static function provideNonStrings(): array
	{
		return [
			'null' => [null],
			'int' => [12345],
			'array' => [['3f1c9a52-7b64-4d0e-9c31-8a5f2e6b0d47']],
			'bool' => [false],
		];
	}
}
