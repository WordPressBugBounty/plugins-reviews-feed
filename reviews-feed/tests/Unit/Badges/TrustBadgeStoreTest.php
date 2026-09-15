<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * Settings sanitising for saved Trust Badges (SBR-163, design rock SBR-78).
 *
 * Only sanitize_settings() and defaults() are exercised here: the rest of the
 * store talks to WP_Query / wp_insert_post, which belongs in an integration
 * test rather than a shim. Sanitising is where the untrusted data lands, so it
 * is the half worth pinning at this level.
 */
class TrustBadgeStoreTest extends TestCase
{
	/**
	 * Every enumerated field is an allowlist, not a passthrough — an unknown
	 * style must not reach a template path and an unknown theme must not reach
	 * a class name.
	 *
	 * @dataProvider enumProvider
	 *
	 * @param string $key      Settings key.
	 * @param string $evil     Value that must be refused.
	 * @param string $fallback Expected result.
	 *
	 * @return void
	 */
	public function test_enumerated_fields_reject_unknown_values($key, $evil, $fallback)
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(array( $key => $evil ));

		$this->assertSame($fallback, $out[ $key ]);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function enumProvider()
	{
		return array(
			'style path traversal' => array( 'style', '../../etc/passwd', 'card' ),
			'style unknown'        => array( 'style', 'sparkle', 'card' ),
			'theme unknown'        => array( 'theme', 'neon', 'light' ),
			'logo_style unknown'   => array( 'logo_style', 'giant', 'full' ),
			'logo_color unknown'   => array( 'logo_color', 'rainbow', 'auto' ),
		);
	}

	/**
	 * ...and accepts the real ones.
	 *
	 * @return void
	 */
	public function test_enumerated_fields_accept_known_values()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(
			array( 'style' => 'medal', 'theme' => 'tinted', 'logo_style' => 'full', 'logo_color' => 'mono' )
		);

		$this->assertSame('medal', $out['style']);
		$this->assertSame('tinted', $out['theme']);
		$this->assertSame('full', $out['logo_style']);
		$this->assertSame('mono', $out['logo_color']);
	}

	/**
	 * The hue wraps a 0-359 dial, so a slider that overshoots should clamp
	 * rather than blank the field.
	 *
	 * @return void
	 */
	public function test_tint_hue_clamps_instead_of_rejecting()
	{
		$this->assertSame(0, SBR_Trust_Badge_Store::sanitize_settings(array( 'tint_hue' => -40 ))['tint_hue']);
		// 359, not 360. The wheel wraps, and SBR_Badge_Renderer::to_hue() reads
		// 360 as out of range and returns "unset" — so a badge clamped there
		// lost its tint on the page while the email PNG applied it. One bound,
		// both sides.
		$this->assertSame(359, SBR_Trust_Badge_Store::sanitize_settings(array( 'tint_hue' => 999 ))['tint_hue']);
		$this->assertSame(210, SBR_Trust_Badge_Store::sanitize_settings(array( 'tint_hue' => 210 ))['tint_hue']);
	}

	/**
	 * An array-valued accent is a PHP 8 TypeError inside sanitize_hex_color(),
	 * which takes its argument straight to preg_match(). Same guard the widget
	 * and the renderer already carry.
	 *
	 * @return void
	 */
	public function test_accent_survives_an_array_post()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(array( 'accent' => array( 'evil' ) ));

		$this->assertSame('', $out['accent']);
	}

	/**
	 * Accent accepts what WP accepts — 3 and 6 digit hex — and refuses the rest.
	 *
	 * @return void
	 */
	public function test_accent_is_a_hue_not_a_hex()
	{
		$accent = static function ($value) {
			return SBR_Trust_Badge_Store::sanitize_settings(array( 'accent' => $value ))['accent'];
		};

		// A HUE on the 0-359 wheel. This was sanitize_hex_color() when the store
		// was written, before the three themes existed — and the sidebar sends a
		// hue, so every accent an author picked came back empty and the badge
		// kept gold stars. Each theme derives its own star lightness from the
		// hue, which is why a resolved hex cannot be what is stored: it would
		// freeze the badge to whichever theme was active when the colour was
		// chosen.
		$this->assertSame('200', $accent('200'));
		$this->assertSame('200', $accent(200));

		// '0' is RED and a real choice; '' means "no accent, keep the default
		// gold". Collapsing the two makes red unpickable.
		$this->assertSame('0', $accent('0'));
		$this->assertSame('', $accent(''));

		// Outside the wheel is a caller bug. Rejected rather than wrapped, so
		// 400 does not silently become 40.
		$this->assertSame('', $accent('400'));
		$this->assertSame('', $accent('-1'));

		// A hex is not a hue, and must not survive as one.
		$this->assertSame('', $accent('#ED4944'));
	}

	/**
	 * Sources are a list of ids; duplicates collapse and non-scalars are
	 * dropped rather than serialised into the settings blob.
	 *
	 * @return void
	 */
	public function test_sources_are_deduped_and_scalar_only()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(
			array( 'sources' => array( 'abc', 'abc', array( 'nested' ), '', 'def' ) )
		);

		$this->assertSame(array( 'abc', 'def' ), $out['sources']);
	}

	/**
	 * A settings blob saved before a new option existed must read back with
	 * that option at its default, not missing — otherwise every consumer needs
	 * its own isset() dance.
	 *
	 * @return void
	 */
	public function test_sanitising_always_returns_the_full_shape()
	{
		$this->assertSame(
			array_keys(SBR_Trust_Badge_Store::defaults()),
			array_keys(SBR_Trust_Badge_Store::sanitize_settings(array()))
		);
	}

	/**
	 * Booleans come off a JSON POST as anything; only truthiness should decide.
	 *
	 * @return void
	 */
	public function test_flags_are_coerced_to_booleans()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(
			array( 'show_stars' => '1', 'show_count' => 0, 'show_logos' => 'false' )
		);

		$this->assertTrue($out['show_stars']);
		$this->assertFalse($out['show_count']);
		// 'false' is a non-empty string, so it is truthy — the client must send
		// a real false, which is what JSON does.
		$this->assertTrue($out['show_logos']);
	}

	/**
	 * An ABSENT flag keeps its default; it does not resolve to false.
	 *
	 * All six flags default to true, so `! empty($raw[$flag])` alone read a
	 * partial or legacy blob back with every element switched off — a blank
	 * badge, and the attribution silently dropped. That directly contradicts the
	 * forward-compat promise get() makes, and the shape test above cannot catch
	 * it because the keys are all present, just wrong.
	 *
	 * @return void
	 */
	public function test_absent_flags_keep_their_defaults()
	{
		$out      = SBR_Trust_Badge_Store::sanitize_settings(array( 'style' => 'chip' ));
		$defaults = SBR_Trust_Badge_Store::defaults();

		foreach (array( 'show_rating', 'show_stars', 'show_count', 'show_logos', 'show_footer', 'powered_by' ) as $flag) {
			$this->assertSame(
				$defaults[ $flag ],
				$out[ $flag ],
				sprintf('An absent %s must keep its default, not become false.', $flag)
			);
		}
	}

	/**
	 * An explicit false is still honoured — otherwise nothing could be hidden.
	 *
	 * @return void
	 */
	public function test_an_explicit_false_still_turns_a_flag_off()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(array( 'show_stars' => false, 'powered_by' => false ));

		$this->assertFalse($out['show_stars']);
		$this->assertFalse($out['powered_by']);
	}

	/**
	 * Every string field guards against a non-scalar, not just the hex.
	 *
	 * assertIsString alone does NOT catch this, which is why the first version of
	 * this test passed with the guard removed: `(string) array` is a warning on
	 * PHP 8, not a fatal, and it yields the literal 'Array' — still a string.
	 * The harm is the VALUE, so that is what is asserted. `footer_text` is the
	 * visible one: without the guard a badge footer renders the word "Array".
	 * (`accent` is the genuinely fatal one — covered separately, since
	 * sanitize_hex_color() reaches preg_match(), whose subject is typed string.)
	 *
	 * @dataProvider stringFieldProvider
	 *
	 * @param string $key Settings key.
	 *
	 * @return void
	 */
	public function test_string_fields_survive_a_non_scalar_post($key)
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(array( $key => array( 'evil' => true ) ));

		$this->assertIsString($out[ $key ], sprintf('%s must stay a string.', $key));
		$this->assertNotSame(
			'Array',
			$out[ $key ],
			sprintf('%s must not become the literal "Array" from an unguarded cast.', $key)
		);
		$this->assertStringNotContainsStringIgnoringCase(
			'array',
			$out[ $key ],
			sprintf('%s must not carry a stringified array.', $key)
		);
	}

	/**
	 * The settings JSON must be slashed before it reaches post meta.
	 *
	 * update_metadata() runs wp_unslash() on the value before writing, which
	 * strips wp_json_encode()'s own escapes. A footer_text containing a double
	 * quote then lands as broken JSON, json_decode() returns null in get(), and
	 * EVERY setting on that badge silently resets to its default. Verified live
	 * before the fix on a real save; verified after across quotes, diacritics,
	 * apostrophes, backslashes, emoji and HTML — all six round-trip intact.
	 *
	 * Asserted against the source because this suite has no WordPress: the
	 * persistence path cannot be exercised here, which is exactly why the bug
	 * survived the first round of tests. A source assertion is weaker than a
	 * round-trip, and it is what this level can honestly offer.
	 *
	 * @return void
	 */
	public function test_settings_json_is_slashed_before_it_reaches_post_meta()
	{
		$file = file_get_contents(
			(new \ReflectionClass(SBR_Trust_Badge_Store::class))->getFileName()
		);

		$this->assertNotFalse($file);
		$this->assertMatchesRegularExpression(
			'/update_post_meta\([^)]*wp_slash\s*\(/s',
			$file,
			'The settings blob must be wp_slash()ed, or update_metadata() will '
			. 'unslash the JSON escapes and every setting resets on read.'
		);
	}

	/**
	 * The badge NAME needs slashing as much as the settings blob.
	 *
	 * wp_insert_post() and wp_update_post() unslash internally, so a name
	 * carrying a backslash or an escaped quote is mangled on save and mangled
	 * again on every duplicate() round-trip.
	 *
	 * @return void
	 */
	public function test_the_post_title_is_slashed_too()
	{
		$file = file_get_contents(
			(new \ReflectionClass(SBR_Trust_Badge_Store::class))->getFileName()
		);

		$this->assertNotFalse($file);
		// Whitespace-insensitive: phpcbf realigns array arrows on every commit,
		// and adding any longer $postarr key shifts them again — neither changes
		// behaviour, so neither should fail this.
		$this->assertMatchesRegularExpression(
			'/\'post_title\'\s*=>\s*wp_slash\s*\(/',
			$file,
			'post_title must be wp_slash()ed before wp_insert_post/wp_update_post.'
		);
	}

	/**
	 * tint_hue is the only numeric field, and it needs the same non-scalar guard
	 * as the strings: `(int) array` is 1 on PHP 8, so an array-valued POST would
	 * land a hue of 1 rather than falling back to the 250 default.
	 *
	 * @return void
	 */
	public function test_tint_hue_falls_back_on_a_non_scalar_post()
	{
		$out = SBR_Trust_Badge_Store::sanitize_settings(array( 'tint_hue' => array( 'a', 'b' ) ));

		$this->assertSame(
			SBR_Trust_Badge_Store::defaults()['tint_hue'],
			$out['tint_hue'],
			'An array-valued hue must fall back to the default, not become 1.'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function stringFieldProvider()
	{
		return array(
			'style'       => array( 'style' ),
			'theme'       => array( 'theme' ),
			'accent'      => array( 'accent' ),
			'logo_style'  => array( 'logo_style' ),
			'logo_color'  => array( 'logo_color' ),
			'footer_text' => array( 'footer_text' ),
			'footer_url'  => array( 'footer_url' ),
		);
	}
}
