<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SMASH-1033 — guards that name a variable their own scope never defines.
 *
 * Two shipped instances, both silent because `isset()` on an undefined
 * variable is false rather than a warning:
 *
 *   duplicate_collection()       `isset($review['media'])` where only
 *                               $review_json exists -> media dropped on every
 *                               collection duplication.
 *   import_reviews_collection()  `isset($sanitized_review['media'])` where
 *                               $sanitized_review is never assigned at all ->
 *                               local image storage never ran for imports.
 *
 * A behavioural test would have to stand up SinglePostCache, MediaFinder and
 * PostAggregator to observe a guard that is false either way, so this scans the
 * file instead: for each method, every `isset($v[` / `is_array($v[` must name a
 * variable that method assigns or receives. That is a floor over the whole
 * file, not a pin on the two lines already fixed — a third instance added later
 * fails it too.
 */
final class Smash1033CollectionMediaScopeTest extends TestCase
{
	/** Superglobals and known globals are in scope everywhere. */
	private const AMBIENT = ['_POST', '_GET', '_REQUEST', '_SERVER', '_FILES', 'GLOBALS', 'wpdb', 'this'];

	public function test_no_guard_names_a_variable_its_own_method_never_defines(): void
	{
		$file = dirname(__DIR__, 2) . '/class/Common/Builder/SBR_Feed_Saver_Manager.php';
		$this->assertFileExists($file);
		$src = file_get_contents($file);
		$this->assertNotFalse($src);

		$lines = explode("\n", $src);
		$offenders = [];
		$method = null;
		$assigned = [];
		$in_signature = false;

		foreach ($lines as $i => $raw) {
			// Strip comments first: a comment quoting the old code is not a
			// guard, and matching one is how this test first went red on its
			// own fix.
			$line = preg_replace('~/\*.*?\*/~', '', $raw);
			$line = preg_replace('~(//|\#).*$~', '', (string) $line);
			$line = preg_replace('~^\s*\*.*$~', '', (string) $line);
			$line = (string) $line;

			// New method: reset the in-scope set to its parameters.
			if (preg_match('/function\s+([a-zA-Z0-9_]+)\s*\((.*)$/', $line, $m)) {
				$method   = $m[1];
				$assigned = [];
				$rest     = $m[2];
				// A signature may span lines; keep taking parameters until the
				// closing paren, or a one-line method's own body is read as
				// parameters and every guard in it looks in scope.
				$in_signature = (strpos($rest, ')') === false);
				preg_match_all('/\$([a-zA-Z0-9_]+)/', $in_signature ? $rest : substr($rest, 0, strpos($rest, ')')), $p);
				foreach ($p[1] as $name) {
					$assigned[$name] = true;
				}
				continue;
			}
			if ($method === null) {
				continue;
			}
			if ($in_signature) {
				$head = (strpos($line, ')') === false) ? $line : substr($line, 0, strpos($line, ')'));
				preg_match_all('/\$([a-zA-Z0-9_]+)/', $head, $p);
				foreach ($p[1] as $name) {
					$assigned[$name] = true;
				}
				if (strpos($line, ')') !== false) {
					$in_signature = false;
				}
				continue;
			}
			// Anything bound in this scope counts: assignment, foreach, catch, list().
			if (preg_match_all('/\$([a-zA-Z0-9_]+)\s*(?:\[[^\]]*\])*\s*=(?!=)/', $line, $a)) {
				foreach ($a[1] as $name) {
					$assigned[$name] = true;
				}
			}
			if (preg_match_all('/\bas\s+\$([a-zA-Z0-9_]+)(?:\s*=>\s*\$([a-zA-Z0-9_]+))?/', $line, $f)) {
				foreach (array_merge($f[1], $f[2]) as $name) {
					if ($name !== '') {
						$assigned[$name] = true;
					}
				}
			}
			// The guards themselves.
			if (preg_match_all('/\b(?:isset|is_array|empty)\(\s*\$([a-zA-Z0-9_]+)\s*\[/', $line, $g)) {
				foreach ($g[1] as $name) {
					if (in_array($name, self::AMBIENT, true) || isset($assigned[$name])) {
						continue;
					}
					$offenders[] = sprintf(
						'%s() line %d guards $%s, which the method never assigns: %s',
						$method,
						$i + 1,
						$name,
						trim($raw)
					);
				}
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"Guard reads a variable its scope never defines, so it is always false:\n  " . implode("\n  ", $offenders)
		);
	}

	/**
	 * The scope scan above cannot see this: with the carry-over deleted,
	 * `isset($review_store['media'])` is still a perfectly in-scope guard that
	 * is simply never true — the exact shape of the original bug.
	 *
	 * Pinned for BOTH import handlers. `import_full_collection()` runs the same
	 * parse_single_review() -> json_data -> store() sequence on its own AJAX
	 * action and was still dropping media after the first round of this fix
	 * (raised in review on sb-reviews#548). They now share one helper, and this
	 * asserts each handler calls it.
	 */
	public function test_both_import_handlers_carry_media_over(): void
	{
		$file = dirname(__DIR__, 2) . '/class/Common/Builder/SBR_Feed_Saver_Manager.php';
		$src  = file_get_contents($file);
		$this->assertNotFalse($src);

		$this->assertStringContainsString(
			'private static function carry_review_media(',
			$src,
			'the shared carry-over helper must exist — four hand-copied copies is how this bug class keeps recurring'
		);

		foreach (['import_reviews_collection', 'import_full_collection'] as $handler) {
			$from = strpos($src, 'public static function ' . $handler);
			$this->assertNotFalse($from, $handler . '() must exist');
			// Bound the window to THIS function. A fixed-size window overlaps the
			// next handler, and then one call site satisfies the assertion for
			// both — caught by mutation: dropping the call in one stayed green.
			$next = strpos($src, "\n\tpublic static function ", $from + 1);
			$body = $next === false ? substr($src, $from) : substr($src, $from, $next - $from);

			$this->assertStringContainsString(
				'self::carry_review_media(',
				$body,
				$handler . '() must carry media over: parse_single_review() (Util.php:1835) returns no media key, '
				. 'so without it the guard downstream is in scope and permanently false'
			);
		}
	}
}
