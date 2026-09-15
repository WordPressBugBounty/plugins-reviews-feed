<?php

/**
 * Reading PHP source the way the parser does, for the tests that have to.
 *
 * Several guards in this suite assert on a method's SOURCE because the thing they
 * protect has no runtime seam yet — an AJAX handler that calls `exit`, a font
 * lookup that stats the filesystem. SMASH-2074 tracks giving those seams and
 * converting the guards. Until then the extraction itself must not be the weak
 * part, and it has been twice: a docblock satisfied an assertion, and a
 * forward-only window read a correct change as a violation.
 *
 * So the brace walk runs over TOKENS, not characters. A `{` inside a string
 * (`"{$foo}"`), a heredoc, or a comment cannot move the depth counter, because
 * the tokeniser hands those over as single tokens.
 *
 * Verified behaviour-preserving on the four methods this replaced a
 * character-walker for: identical output, byte for byte
 * (get_aggregate_for_sources 4289, render_png 1232, rasteriser 131).
 */
class SbrPhpSource
{
	/** Statements before a read that count as its guard scope — see statements_reading(). */
	private const PRECEDING_WINDOW = 4;

	/**
	 * A named function's body, braces excluded.
	 *
	 * Two ways this used to be wrong, both found in review and both silent:
	 *
	 *   - a BODYLESS declaration (`function foo();` in an interface or an abstract
	 *     class) has no brace of its own, so the walk ran on to the NEXT method's
	 *     brace and returned a span covering the wrong code. Measured: for
	 *     `interface I { function foo(); } class C { function foo() { … } }` it
	 *     returned `public function foo() { return 'REAL';` — the declaration line
	 *     plus half the real body.
	 *   - two declarations of the same NAME in one file: the first won with no
	 *     signal, so a guard could be reading a method it was not aiming at.
	 *
	 * So: bodyless declarations are skipped, and ambiguity returns '' rather than
	 * guessing. Every caller already asserts the body is non-empty, which turns
	 * "I cannot tell which one you mean" into a loud failure.
	 *
	 * @param string $src  Whole file source.
	 * @param string $name Function or method name.
	 *
	 * @return string Body source, or '' when the name is not declared here, is
	 *                declared without a body, or is declared more than once.
	 */
	public static function method_body($src, $name)
	{
		$tokens = token_get_all((string) $src);
		$count  = count($tokens);
		$starts = self::declaration_indexes($tokens, (string) $name);

		// Ambiguous is not the same as absent, and both are '' — but the docblock
		// says so and the callers assert on it, which is the loud half.
		if (count($starts) !== 1) {
			return '';
		}

		$depth         = 0;
		$interpolation = 0;
		$body          = '';
		$started       = false;

		for ($i = $starts[0]; $i < $count; $i++) {
			$token = $tokens[$i];
			$text  = is_array($token) ? $token[1] : $token;

			if (is_array($token)) {
				// `"{$foo}"` and `"${foo}"` open with a TOKEN whose closer is a
				// literal `}`. Counting that closer as a block end is the same bug
				// as the character walk, one layer down — this is what the first
				// version of this helper got wrong, and the test that caught it is
				// test_a_brace_in_a_string_does_not_truncate_the_body.
				if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
					$interpolation++;
				}
			} else {
				// Comments, heredoc bodies and plain string text arrive as single
				// array tokens, so their braces never reach here.
				if ($token === '{') {
					$depth++;

					// The body starts after the opening brace, not at it.
					if (! $started) {
						$started = true;

						continue;
					}
				} elseif ($token === '}') {
					if ($interpolation > 0) {
						$interpolation--;
					} else {
						$depth--;

						if ($depth === 0) {
							return $body;
						}
					}
				}
			}

			if ($started) {
				$body .= $text;
			}
		}

		return '';
	}

	/**
	 * Indexes of every `function <name>` declaration that HAS a body.
	 *
	 * A declaration ending in `;` — an interface method, an abstract method — is
	 * not one: it owns no braces, and treating it as a start makes the brace walk
	 * read the next method instead.
	 *
	 * @param array  $tokens token_get_all() output.
	 * @param string $name   Function name.
	 *
	 * @return int[] Token indexes of the name, in source order.
	 */
	private static function declaration_indexes(array $tokens, $name)
	{
		$count = count($tokens);
		$found = array();

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (! is_array($token) || $token[0] !== T_FUNCTION) {
				continue;
			}

			$at = -1;

			for ($j = $i + 1; $j < $count; $j++) {
				$next = $tokens[$j];

				if (is_array($next) && $next[0] === T_WHITESPACE) {
					continue;
				}

				// `function &foo()` puts a bare '&' between the keyword and the
				// name. Without this the declaration is invisible and the caller
				// gets '' — which reads as "no such method" rather than "cannot
				// parse this one".
				if ($next === '&') {
					continue;
				}

				if (is_array($next) && $next[0] === T_STRING && $next[1] === $name) {
					$at = $j;
				}

				// Anything else here means this declaration is not the one — a
				// closure, or a differently-named function.
				break;
			}

			if ($at === -1) {
				continue;
			}

			if (self::has_body($tokens, $at)) {
				$found[] = $at;
			}
		}

		return $found;
	}

	/**
	 * Whether the declaration at $at is followed by a body rather than a `;`.
	 *
	 * @param array $tokens token_get_all() output.
	 * @param int   $at     Index of the function name.
	 *
	 * @return bool
	 */
	private static function has_body(array $tokens, $at)
	{
		$count = count($tokens);
		$depth = 0;

		for ($i = $at; $i < $count; $i++) {
			$token = $tokens[$i];

			if (is_array($token)) {
				continue;
			}

			// Walk the parameter list, and any default value's own brackets, before
			// deciding what follows it.
			if ($token === '(' || $token === '[') {
				$depth++;

				continue;
			}

			if ($token === ')' || $token === ']') {
				$depth--;

				continue;
			}

			if ($depth > 0) {
				continue;
			}

			// First `{` or `;` at depth zero after the signature settles it. A
			// return type sits between them and contributes neither.
			if ($token === '{') {
				return true;
			}

			if ($token === ';') {
				return false;
			}
		}

		return false;
	}

	/**
	 * Source with every run of whitespace removed.
	 *
	 * For the guards that have to match a literal expression until the code under
	 * them grows a seam (SMASH-2074). Without this they pin FORMATTING: a phpcbf
	 * run, or WPCS's preferred `count( $chosen )` spacing, breaks them with no
	 * behavioural change — and worse, a reintroduced bail written with the spaces
	 * slips past a negative assertion, which is the assertion being green with the
	 * bug back in place.
	 *
	 * @param string $src Source text.
	 *
	 * @return string
	 */
	public static function squeeze($src)
	{
		return (string) preg_replace('/\s+/', '', (string) $src);
	}

	/**
	 * Whether $haystack contains $needle, ignoring whitespace on both sides.
	 *
	 * @param string $haystack Source text.
	 * @param string $needle   Expression to look for, written however reads best.
	 *
	 * @return bool
	 */
	public static function contains($haystack, $needle)
	{
		return strpos(self::squeeze($haystack), self::squeeze($needle)) !== false;
	}

	/**
	 * Every statement that reads a superglobal, with the statement before it.
	 *
	 * Written for the guard test on `$_POST` reads, whose regex had three holes the
	 * review found: it only saw single-quoted lowercase keys (so `$_POST["id"]`,
	 * `$_POST['feedID']` and `$_POST[ self::FIELD ]` were never scanned at all), it
	 * cut statements at the first `;` including one inside a string literal, and it
	 * could not see a guard written on the LINE ABOVE the read — reporting a
	 * perfectly safe early return as a violation.
	 *
	 * Tokens fix all three: the name matches as a T_VARIABLE whatever the key looks
	 * like, a `;` inside a string is not a statement end, and the preceding
	 * statement comes back alongside so a caller can accept a guard there.
	 *
	 * @param string $src  Whole file source.
	 * @param string $name Superglobal, e.g. '$_POST'.
	 *
	 * @return array<int, array{statement: string, preceding: string}>
	 */
	public static function statements_reading($src, $name)
	{
		$tokens     = token_get_all((string) $src);
		$statements = array();
		$current    = '';

		foreach ($tokens as $token) {
			// Comments carry code-shaped text and would satisfy a match. Dropped
			// rather than trusted — two tests here were already fooled by a docblock.
			if (is_array($token) && in_array($token[0], array( T_COMMENT, T_DOC_COMMENT ), true)) {
				continue;
			}

			$current .= is_array($token) ? $token[1] : $token;

			// Only a real `;` `{` or `}` TOKEN ends a statement, so one inside a
			// string or a comment cannot.
			if (! is_array($token) && in_array($token, array( ';', '{', '}' ), true)) {
				$statements[] = $current;
				$current      = '';
			}
		}

		if (trim($current) !== '') {
			$statements[] = $current;
		}

		$out = array();

		foreach ($statements as $index => $statement) {
			// The name then an opening bracket: assigning the whole array
			// (`$_POST = []`) is not a keyed read and is not what the guard is about.
			if (! preg_match('/' . preg_quote($name, '/') . '\s*\[/', $statement)) {
				continue;
			}

			// A WINDOW, not one statement. `if (! is_scalar($raw)) { return; }` is
			// four statements to the tokeniser — the condition, the brace, the
			// return and the closing brace — so `$index - 1` was `}` and the guard
			// was two further back. Four covers that shape; it is a read-only
			// window, so being generous costs nothing but a false negative in the
			// direction of trusting the author.
			$window = array_slice($statements, max(0, $index - self::PRECEDING_WINDOW), min($index, self::PRECEDING_WINDOW));

			$out[] = array(
				'statement' => $statement,
				'preceding' => implode('', $window),
			);
		}

		return $out;
	}
}
