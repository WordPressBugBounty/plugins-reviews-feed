<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * A screen that hosts AddSourceModal must ship the modal's whole payload (SBR-163).
 *
 * The badge editor's Settings tab hosts the shared source picker, so it opens
 * AddSourceModal — a component written for the feed builder, which reads what that
 * screen localises. This has now failed twice, the same way both times:
 *
 *   1. `freeRetrieverData` missing → "Cannot read properties of undefined (reading
 *      'includes')" the moment a provider was picked.
 *   2. `connectFBUrls` missing → "Cannot read properties of undefined (reading
 *      'stateURL')" on Connect with Facebook. Reported from the live editor.
 *
 * Both were fixed a key at a time, which is why there was a second one. A differential
 * against the feed builder's payload then showed `collectionsPageUrl` and
 * `emailVerificationURL` were absent too — two more crashes waiting for whoever
 * clicked the right thing.
 *
 * So the contract is asserted rather than remembered. The required list is spelled out
 * here so it has teeth in any environment, and a second test re-derives it from the
 * modal's source when that source is present, so the list cannot quietly go stale.
 */
class BadgeAddSourcePayloadTest extends TestCase
{
	/** Read off `sbCustomizer` by AddSourceModal and its sections, 2026-09-09. */
	const REQUIRED = array(
		'ajaxHandler',
		'collectionsPageUrl',
		'connectFBUrls',
		'emailVerificationURL',
		'freeRetrieverData',
		'nonce',
		'pluginStatus',
		'providers',
	);

	/**
	 * Keys the modal reads that are NOT the hosting screen's to provide: they are
	 * set at runtime by the modal's own flow, and absent on the feed builder too.
	 */
	const RUNTIME_ONLY = array( 'feedData', 'isFeedEditor', 'newSourceData' );

	/**
	 * @return string
	 */
	private function builderSource()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileExists($path, 'the badge builder moved; this test needs repointing');

		return (string) file_get_contents($path);
	}

	/**
	 * The keys the builder puts at the TOP LEVEL of its localised array.
	 *
	 * Read from source rather than by booting WordPress — this suite has no WP
	 * runtime. Two things the review found wrong with the previous version, both
	 * fixed by counting depth from tokens instead of matching text:
	 *
	 *   - the comment said "only top-level string keys" and the regex matched keys
	 *     at ANY nesting depth. A REQUIRED key that existed only as a sub-array key
	 *     satisfied the assertion while AddSourceModal still crashed, which is the
	 *     one failure this test exists to prevent.
	 *   - `strpos($src, '$data = array(')` takes the FIRST such literal in the
	 *     file, so a second localised array added later silently retargets the
	 *     test. It is now bounded by the matching close paren of that call.
	 *
	 * @param string $src Builder source.
	 *
	 * @return string[]
	 */
	private function localisedKeys($src)
	{
		$start = strpos($src, '$data = array(');

		$this->assertNotFalse($start, 'the localised array is no longer written as `$data = array(`');

		$end = strpos($src, "wp_localize_script('sb-customizer-app'", $start);

		$this->assertNotFalse($end, 'wp_localize_script call not found after the array');

		$tokens = token_get_all('<?php ' . substr($src, $start, $end - $start));
		$depth  = 0;
		$keys   = array();
		$pending = null;

		foreach ($tokens as $token) {
			// A key named inside a comment must not satisfy the assertion. Two
			// tests in this suite have been fooled by their own comments before.
			if (is_array($token) && in_array($token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true)) {
				continue;
			}

			if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
				$pending = trim($token[1], "'\"");

				continue;
			}

			if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
				// `array(` counts as one level, so the keys of the outermost array
				// sit at depth 1. Anything deeper is a sub-array key.
				if ($depth === 1 && $pending !== null) {
					$keys[] = $pending;
				}

				$pending = null;

				continue;
			}

			if (is_array($token) && $token[0] === T_ARRAY) {
				continue;
			}

			if (! is_array($token) && ( $token === '(' || $token === '[' )) {
				$depth++;
			} elseif (! is_array($token) && ( $token === ')' || $token === ']' )) {
				$depth--;
			}

			$pending = null;
		}

		return array_values(array_unique($keys));
	}

	/**
	 * @return void
	 */
	public function test_the_badge_screen_ships_every_key_add_source_modal_reads()
	{
		$keys = $this->localisedKeys($this->builderSource());

		// Guard against an empty parse passing the loop below on zero observations.
		$this->assertGreaterThan(10, count($keys), 'parsed too few keys to be the real array');

		$missing = array_values(array_diff(self::REQUIRED, $keys));

		$this->assertSame(
			array(),
			$missing,
			'AddSourceModal reads these off sbCustomizer and this screen does not ship them: '
			. implode(', ', $missing)
		);
	}

	/**
	 * @return void
	 */
	public function test_the_required_list_still_matches_what_the_modal_reads()
	{
		$modal = dirname(__DIR__, 3)
			. '/vendor/smashballoon/customizer/sb-common/sb-customizer/src/Components/Modals/AddSourceModal.js';

		// Asserted, not branched. `assertTrue(true)` reported a run that verified
		// nothing as a pass; markTestSkipped reports it as a skip, which is the same
		// misreading in the other direction. The file is present in both dev shapes —
		// the monorepo symlink and a plain `composer install` copy — so its absence
		// means the vendor tree was never installed, which is worth failing on.
		$this->assertFileIsReadable(
			$modal,
			'the customizer source must be reachable, or REQUIRED cannot be checked for drift'
		);

		$src = (string) file_get_contents($modal);

		// Three read forms, not one. Dot access was the only one matched, so
		// `const { emailVerificationURL } = sbCustomizer;` and
		// `sbCustomizer['connectFBUrls']` went unnoticed — and destructuring is the
		// idiomatic form in these components, which makes it the likeliest way a
		// new read lands. (Neither appears in AddSourceModal.js today: 0
		// occurrences, measured. The point is that the drift guard would not see
		// the next one.)
		preg_match_all(
			'/sbCustomizer\??\.([a-zA-Z_][a-zA-Z0-9_]*)'      // sbCustomizer.foo
			. '|sbCustomizer\[[\x27"]([^\x27"]+)[\x27"]\]'      // sbCustomizer['foo']
			. '|\{([^}]*)\}\s*=\s*sbCustomizer/',              // const { foo, bar } = sbCustomizer
			$src,
			$m
		);

		$names = array_merge($m[1], $m[2]);

		// The destructuring group captures a whole list, so split and strip any
		// `foo: bar` renames down to the key actually read.
		foreach ($m[3] as $group) {
			$group = (string) $group;

			// `\{([^}]*)\}` can start at an enclosing brace — an arrow function's
			// body, say — and swallow `const {` with it, which left every piece
			// failing the identifier test and the whole branch silently doing
			// nothing. Only the text after the LAST `{` is the pattern list.
			$brace = strrpos($group, '{');

			if ($brace !== false) {
				$group = substr($group, $brace + 1);
			}

			foreach (explode(',', $group) as $piece) {
				$piece = trim(explode(':', $piece)[0]);
				$piece = trim($piece, " \t\n\r\0\x0B.");

				if ($piece !== '' && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $piece)) {
					$names[] = $piece;
				}
			}
		}

		$read = array_values(array_diff(array_unique(array_filter($names)), self::RUNTIME_ONLY));

		sort($read);

		$known = self::REQUIRED;
		sort($known);

		$unlisted = array_values(array_diff($read, $known));

		$this->assertSame(
			array(),
			$unlisted,
			'AddSourceModal now reads keys this test does not know about: '
			. implode(', ', $unlisted)
			. ' — add them to REQUIRED and ship them from every screen that opens the modal'
		);
	}
}
