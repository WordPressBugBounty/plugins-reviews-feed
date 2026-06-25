<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for CVE-2026-10724 / SMASH-1607.
 *
 * Review data imported from connected sources is rendered inside the dynamic
 * `sbr/sbr-feed-block`. WordPress runs `do_blocks()` on `the_content` at
 * priority 9 and `do_shortcode()` at priority 11, so any shortcode bracket left
 * in the rendered block markup is expanded server-side — even one an
 * unauthenticated visitor planted in a public review. `sbr_neutralize_shortcodes()`
 * encodes the `[` / `]` characters so `do_shortcode()` can never match them,
 * while preserving the literal text for the visitor.
 *
 * The security invariant under test has two parts: (1) after neutralization, NO
 * literal `[` or `]` survives in the output; and (2) the output must STILL be
 * bracket-free after WordPress core's `unescape_invalid_shortcodes()` runs over
 * it (`do_shortcode()` calls it on every processed content). That second part is
 * the one the original decimal implementation failed: core reverses `&#91;` /
 * `&#93;` back to `[` / `]`, re-arming the shortcode. Hex entities (`&#x5B;` /
 * `&#x5D;`) are not reversed, so they hold. `do_shortcode()` requires a literal
 * `[` to match, so an output with none — before AND after core's unescape — is
 * unreachable by the shortcode parser regardless of registered shortcodes.
 */
final class ShortcodeNeutralizationTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		// `esc_html()` is the WordPress escaper used at the reviewer-name / title
		// sinks. It intentionally leaves `[` and `]` untouched (it only encodes
		// `< > & " '`), which is exactly why the neutralizer is needed on top of
		// it. The stub mirrors that behaviour so the ordering test is faithful.
		if (! function_exists('esc_html')) {
			function esc_html($text)
			{
				return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
			}
		}

		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';
	}

	/**
	 * The exact proof-of-concept payload from the security report must be
	 * rendered inert: no literal brackets survive, so `do_shortcode()` cannot
	 * expand it.
	 */
	public function testReportProofOfConceptIsNeutralized(): void
	{
		$payload = 'Great service! [gallery ids=1]';

		$out = \sbr_neutralize_shortcodes($payload);

		$this->assertStringNotContainsString('[', $out);
		$this->assertStringNotContainsString(']', $out);
		$this->assertStringNotContainsString('[gallery', $out);
	}

	/**
	 * The literal text the reviewer typed is preserved for the visitor — the
	 * browser decodes the numeric entities back to the original characters.
	 * This is why bracket-encoding is preferred over `strip_shortcodes()`,
	 * which would silently delete the reviewer's content.
	 */
	public function testVisibleTextIsPreserved(): void
	{
		$payload = 'Great service! [gallery ids=1]';

		$decoded = html_entity_decode(
			\sbr_neutralize_shortcodes($payload),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		$this->assertSame($payload, $decoded);
	}

	/**
	 * Opening tags, self-closing tags, closing tags and multiple shortcodes in
	 * one string are all covered — the parser never sees a `[` it can latch on.
	 */
	public function testAllShortcodeShapesAreNeutralized(): void
	{
		$payloads = array(
			'[gallery]',
			'[gallery ids=1]',
			'[caption]x[/caption]',
			'nested [a][b]c[/b][/a] text',
			'[wp_head]',
			'lots ] of ] brackets [ and [ more',
		);

		foreach ($payloads as $payload) {
			$out = \sbr_neutralize_shortcodes($payload);
			$this->assertStringNotContainsString('[', $out, "Left bracket survived for: {$payload}");
			$this->assertStringNotContainsString(']', $out, "Right bracket survived for: {$payload}");
		}
	}

	/**
	 * The reviewer NAME is the most attacker-controlled field (an
	 * unauthenticated reviewer fully controls their display name) and is only
	 * `esc_html()`-escaped at the author-template sinks. Applying the
	 * neutralizer as the outermost wrapper on the escaped output must strip the
	 * vector without double-encoding the entities esc_html already produced.
	 */
	public function testReviewerNameSinkOrderingIsSafe(): void
	{
		$evilName = 'A & B "Co" [gallery ids=1]';

		// Mirrors the template: sbr_neutralize_shortcodes( esc_html( $name ) ).
		$out = \sbr_neutralize_shortcodes(esc_html($evilName));

		// Shortcode parser can't match — no literal brackets.
		$this->assertStringNotContainsString('[', $out);
		$this->assertStringNotContainsString(']', $out);

		// No double-encoding of esc_html's entities (no `&amp;amp;`).
		$this->assertStringNotContainsString('&amp;amp;', $out);

		// Browser-visible text is faithful to the reviewer's input.
		$decoded = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$this->assertSame($evilName, $decoded);
	}

	/**
	 * Output that already passed through `wp_kses_post()` (review text / pros /
	 * cons) still carries literal brackets; the neutralizer is applied
	 * outermost. Simulated here by feeding bracket-bearing markup directly.
	 */
	public function testKsesStyleOutputIsNeutralizedOutermost(): void
	{
		$ksesOutput = 'Loved it<br />[contact-form-7 id="42"]';

		$out = \sbr_neutralize_shortcodes($ksesOutput);

		$this->assertStringNotContainsString('[', $out);
		$this->assertStringNotContainsString(']', $out);
		// Surrounding safe markup is untouched.
		$this->assertStringContainsString('<br />', $out);
	}

	/**
	 * Plain content without brackets is returned byte-for-byte unchanged.
	 */
	public function testPlainTextIsUnchanged(): void
	{
		$text = 'Just a normal, friendly review. 5 stars!';
		$this->assertSame($text, \sbr_neutralize_shortcodes($text));
	}

	/**
	 * Empty string and non-string inputs are handled defensively.
	 */
	public function testEdgeCaseInputs(): void
	{
		$this->assertSame('', \sbr_neutralize_shortcodes(''));
		$this->assertNull(\sbr_neutralize_shortcodes(null));
	}

	/**
	 * THE regression for CVE-2026-10724: the neutralized output must survive
	 * WordPress core's `unescape_invalid_shortcodes()`, which `do_shortcode()`
	 * runs over every processed content. Core reverses the DECIMAL entities
	 * `&#91;` / `&#93;` back to raw `[` / `]` — silently undoing a decimal-based
	 * neutralizer and re-arming the planted shortcode. This test replicates that
	 * core function verbatim and asserts no bracket reappears; it FAILS against
	 * the original `&#91;` / `&#93;` implementation and passes with hex.
	 */
	public function testSurvivesWordPressUnescapeInvalidShortcodes(): void
	{
		$payload = 'Great service! [gallery ids=1]';

		$out = \sbr_neutralize_shortcodes(esc_html($payload));

		// Verbatim from wp-includes/shortcodes.php::unescape_invalid_shortcodes().
		$afterCore = str_replace(array( '&#91;', '&#93;' ), array( '[', ']' ), $out);

		$this->assertStringNotContainsString('[', $afterCore, 'Literal "[" reappeared after WP core unescape_invalid_shortcodes() — the neutralizer must use hex entities, not decimal.');
		$this->assertStringNotContainsString(']', $afterCore);
		$this->assertStringNotContainsString('[gallery', $afterCore);
	}

	/**
	 * Pins the encoding to HEX entities. Decimal `&#91;` / `&#93;` is forbidden
	 * because WP core unescapes it (see test above); this guards against a
	 * regression back to decimal.
	 */
	public function testUsesHexEntitiesNotDecimal(): void
	{
		$out = \sbr_neutralize_shortcodes('[x]');

		$this->assertStringContainsString('&#x5B;', $out);
		$this->assertStringContainsString('&#x5D;', $out);
		$this->assertStringNotContainsString('&#91;', $out);
		$this->assertStringNotContainsString('&#93;', $out);
	}
}
