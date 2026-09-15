<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The source reader those guards depend on (SMASH-2074).
 *
 * Several tests in this suite assert on a method's source because the thing they
 * protect has no runtime seam yet. That is tracked and being removed, but while
 * it stands the EXTRACTION must not be the weak part — a truncated body gives a
 * silent false pass, which is worse than the coupling it was accepted for.
 *
 * The version this replaced walked characters and counted braces, so a `{` in a
 * string, a heredoc or a comment moved the depth counter. Each case below fails
 * against that walker and passes against the tokenised one.
 */
final class PhpSourceExtractionTest extends TestCase
{
	/**
	 * The character-walking implementation this replaced, kept as the control.
	 *
	 * Every case below asserts BOTH that the tokenised reader is right and that
	 * this one is wrong — otherwise a case that both handle would look like
	 * evidence for the fix while proving nothing.
	 */
	private function characterWalk(string $src, string $name): string
	{
		$at = strpos($src, 'function ' . $name . '(');

		if (false === $at) {
			return '';
		}

		$open = strpos($src, '{', $at);

		if (false === $open) {
			return '';
		}

		$depth = 0;
		$len   = strlen($src);

		for ($i = $open; $i < $len; $i++) {
			if ('{' === $src[ $i ]) {
				$depth++;
			} elseif ('}' === $src[ $i ]) {
				$depth--;

				if (0 === $depth) {
					return substr($src, $open + 1, $i - $open - 1);
				}
			}
		}

		return '';
	}

	/** A brace inside an interpolated string must not close the body. */
	public function test_a_brace_in_a_string_does_not_truncate_the_body(): void
	{
		$src = <<<'SRC'
<?php
function target($foo)
{
	$label = "{$foo} }";
	return $label . 'THE_END';
}
SRC;

		$this->assertStringContainsString('THE_END', \SbrPhpSource::method_body($src, 'target'));

		// The control: the old walker stopped at the `}` inside the string.
		$this->assertStringNotContainsString('THE_END', $this->characterWalk($src, 'target'));
	}

	/** A brace inside a comment must not close the body either. */
	public function test_a_brace_in_a_comment_does_not_truncate_the_body(): void
	{
		$src = <<<'SRC'
<?php
function target()
{
	// a stray } in a comment
	return 'THE_END';
}
SRC;

		$this->assertStringContainsString('THE_END', \SbrPhpSource::method_body($src, 'target'));
		$this->assertStringNotContainsString('THE_END', $this->characterWalk($src, 'target'));
	}

	/**
	 * A heredoc carrying an UNBALANCED brace must not close the body.
	 *
	 * Unbalanced on purpose: `SELECT { } FROM t` does not desync a character walk
	 * at all, so it would have looked like evidence while proving nothing.
	 */
	public function test_a_brace_in_a_heredoc_does_not_truncate_the_body(): void
	{
		$src = "<?php\nfunction target()\n{\n\t\$sql = <<<SQL\n\tSELECT } FROM t\nSQL;\n\treturn 'THE_END';\n}\n";

		$this->assertStringContainsString('THE_END', \SbrPhpSource::method_body($src, 'target'));
		$this->assertStringNotContainsString('THE_END', $this->characterWalk($src, 'target'));
	}

	/** A name that only APPEARS in a call must not be mistaken for a declaration. */
	public function test_a_call_site_is_not_a_declaration(): void
	{
		$src = <<<'SRC'
<?php
function caller()
{
	return other_thing();
}
function other_thing()
{
	return 'THE_BODY';
}
SRC;

		$this->assertStringContainsString('THE_BODY', \SbrPhpSource::method_body($src, 'other_thing'));
	}

	/** A bodyless declaration is not the one being asked for. */
	public function test_a_bodyless_declaration_is_skipped(): void
	{
		$src = <<<'SRC'
<?php
interface I { public function target(); }
class C { public function target() { return 'THE_REAL_BODY'; } }
SRC;

		// Before: the walk started at the interface's declaration, ran on to the
		// class's brace and returned the declaration line plus half the real body
		// — `public function target() { return 'THE_REAL_BODY';`.
		$body = \SbrPhpSource::method_body($src, 'target');

		$this->assertStringContainsString('THE_REAL_BODY', $body);
		$this->assertStringNotContainsString('function target', $body, 'the signature is not part of the body');
	}

	/** Two declarations of one name are ambiguous, and ambiguity is not a guess. */
	public function test_a_duplicated_name_returns_nothing_rather_than_the_first(): void
	{
		$src = <<<'SRC'
<?php
class A { public function target() { return 'FIRST'; } }
class B { public function target() { return 'SECOND'; } }
SRC;

		// '' rather than 'FIRST': every caller asserts the body is non-empty, so
		// this turns "I cannot tell which one you mean" into a loud failure instead
		// of a guard silently reading the wrong method.
		$this->assertSame('', \SbrPhpSource::method_body($src, 'target'));
	}

	/** An undeclared name returns '', so a caller can assert on it. */
	public function test_a_missing_name_returns_an_empty_string(): void
	{
		$src = "<?php\nfunction target()\n{\n\treturn 1;\n}\n";

		$this->assertSame('', \SbrPhpSource::method_body($src, 'renamed_away'));
	}

	/** Nested blocks close at the method's own brace, not the first inner one. */
	public function test_nested_blocks_do_not_end_the_body_early(): void
	{
		$src = <<<'SRC'
<?php
function target($x)
{
	if ($x) {
		foreach ([] as $y) {
			$x++;
		}
	}
	return 'THE_END';
}
SRC;

		$this->assertStringContainsString('THE_END', \SbrPhpSource::method_body($src, 'target'));
	}

	/**
	 * And on the real files, it agrees with the walker it replaced.
	 *
	 * The swap had to be behaviour-preserving for the guards that use it, so this
	 * pins that rather than trusting the four methods happened to be clean.
	 */
	public function test_it_matches_the_old_walker_on_the_methods_actually_guarded(): void
	{
		$cases = array(
			'class/Common/Badges/SBR_Badge_Renderer.php' => array( 'get_aggregate_for_sources', 'render' ),
			'class/Common/Badges/SBR_Badge_Image.php'    => array( 'render_png', 'rasteriser' ),
			'class/Common/Feed.php'                      => array( 'api_request' ),
		);

		$compared = 0;

		foreach ($cases as $relative => $methods) {
			$src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);

			$this->assertNotSame('', $src, $relative . ' must be readable');

			foreach ($methods as $method) {
				$tokenised = \SbrPhpSource::method_body($src, $method);

				$this->assertNotSame('', $tokenised, $method . ' must be found');

				$this->assertSame(
					$this->characterWalk($src, $method),
					$tokenised,
					$method . ': the swap must not change what the guards read'
				);

				$compared++;
			}
		}

		// Or an empty $cases would make the loop above vacuous.
		$this->assertSame(5, $compared);
	}
}
