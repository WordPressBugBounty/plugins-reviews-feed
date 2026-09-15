<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The two design-token copies must declare the same tokens (SBR-163).
 *
 * The layer is vendored as a file in two places — this plugin's
 * `assets/css/design-tokens/sb-tokens.css` and the customizer package's copy — and
 * both `:root` blocks can end up on the same admin screen, where they resolve by
 * load order. Any drift between them becomes a colour difference nobody can
 * explain from the code.
 *
 * A review round on sb-customizer#192 pointed out there was no check that they
 * still match, and it was the right thing to ask: the headers had already diverged
 * (deliberately — they document different things) while the declarations had not,
 * and nothing distinguished "headers differ" from "a token drifted".
 *
 * So this compares declarations with comments stripped, which is the thing that has
 * to agree, and lets the headers say whatever each copy needs.
 */
class DesignTokenParityTest extends TestCase
{
	/**
	 * @return string
	 */
	protected function plugin_copy()
	{
		return dirname(__DIR__, 3) . '/assets/css/design-tokens/sb-tokens.css';
	}

	/**
	 * @return string
	 */
	protected function customizer_copy()
	{
		return dirname(__DIR__, 3)
			. '/vendor/smashballoon/customizer/sb-common/sb-customizer/src/assets/css/design-tokens/sb-tokens.css';
	}

	/**
	 * Declarations only: comments gone, blank lines gone, whitespace normalised.
	 *
	 * @param string $path Stylesheet path.
	 *
	 * @return array<int, string>
	 */
	protected function declarations($path)
	{
		$css = (string) file_get_contents($path);
		$css = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $css);

		$out = array();

		foreach (explode("\n", $css) as $line) {
			$line = trim($line);

			if ($line !== '') {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * @return void
	 */
	public function test_both_copies_declare_exactly_the_same_tokens()
	{
		$plugin = $this->plugin_copy();
		$vendor = $this->customizer_copy();

		$this->assertFileExists($plugin, 'the plugin ships its own copy of the token layer');

		// Asserted, not skipped — one rule across the suite: a missing VENDORED FILE
		// means the tree was never installed, which is worth failing on, while a
		// missing machine CAPABILITY (Imagick, in BadgeMarkAssetsTest) is a skip.
		// This used to skip while its two siblings failed, which is the
		// inconsistency the review round found.
		$this->assertFileIsReadable(
			$vendor,
			'the customizer copy must be reachable, or drift between the two cannot be detected'
		);

		$this->assertSame(
			$this->declarations($plugin),
			$this->declarations($vendor),
			'a token drifted between the plugin copy and the customizer copy. Both :root blocks can '
				. 'load on one screen, so they resolve by load order and the difference is invisible. '
				. 'Refresh both through .claude/bin/sync-design-tokens.'
		);
	}

	/**
	 * The header counts are load-bearing enough that a reader trusts them, so they
	 * are asserted rather than left to rot.
	 *
	 * @return void
	 */
	public function test_the_header_counts_match_the_file()
	{
		$css  = (string) file_get_contents($this->plugin_copy());
		$body = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $css);

		preg_match_all('/^\s*(--sb-[a-z0-9-]+):/m', $body, $matches);

		$total  = count($matches[1]);
		$unique = count(array_unique($matches[1]));

		$this->assertSame(223, $unique, 'the header claims 223 unique custom properties');
		$this->assertSame(321, $total, 'the header claims 321 declarations');
		$this->assertSame(
			98,
			$total - $unique,
			'the header claims 98 overrides, broken down as 85 oklch + 8 reduced-motion + 5 color-mix'
		);
	}
}
