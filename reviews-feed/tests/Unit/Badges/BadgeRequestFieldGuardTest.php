<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * Every badge AJAX handler coerces its request fields from a scalar (SBR-163).
 *
 * `absint()` on an array returns **1**, not 0 — measured, not assumed:
 *
 *     php -r 'var_dump(absint(array(9)));'   =>   int(1)
 *
 * So `POST id[]=9` with a valid nonce and capability resolved to badge **1**, and
 * `ajax_save` overwrote it and reported success. Three of the five routes reading
 * `$_POST['id']` had only an `isset` guard while `ajax_create_page` did it right, and
 * `parse_delete_ids()` carries a comment describing this exact trap — the single-id
 * paths were written from a different template than the list path.
 *
 * `(string)` on an array is the same shape one type over: a PHP 8 "Array to string
 * conversion" warning and the literal `"Array"` used as the value, so a posted
 * `search[]` searched for the word Array and a posted `settings[]` failed json_decode
 * as a malformed payload rather than as a rejected one.
 *
 * Asserted against the source because these are `$_POST` reads inside handlers that
 * call `guard()` first; standing the whole nonce + capability + tier chain up in a
 * unit test would test WordPress, not the coercion. The failure this catches is an
 * edit — a new handler written from the unguarded template.
 */
class BadgeRequestFieldGuardTest extends TestCase
{
	/**
	 * @return string
	 */
	protected function builder_source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * @return void
	 */
	/**
	 * Every badge class that reads request data, not just the builder.
	 *
	 * The scan used to be one file, so a handler added to a sibling was exactly
	 * the "new handler written from the unguarded template" this test exists to
	 * catch and it passed. Measured at the time: SBR_Badge_Service also reads
	 * `$_GET['sbr_badge_wizard']`, and nothing checked it.
	 *
	 * @return array<string, string> Relative path => source.
	 */
	protected function badge_sources()
	{
		$dir   = dirname(__DIR__, 3) . '/class/Common/Badges';
		$found = array();

		foreach ((array) glob($dir . '/*.php') as $path) {
			$src = (string) file_get_contents($path);

			if (strpos($src, '$_POST[') === false && strpos($src, '$_GET[') === false) {
				continue;
			}

			$found[ basename($path) ] = $src;
		}

		// Prove the sweep found the directory before trusting an empty result.
		$this->assertNotSame(array(), $found, 'no badge class reads request data — the glob is wrong');

		return $found;
	}

	/**
	 * @return void
	 */
	public function test_every_post_read_is_guarded_by_a_type_check()
	{
		$src = $this->builder_source();

		// Tokenised, not regexed. The regex this replaced had three holes the
		// review found: it matched only single-quoted lowercase keys, so
		// `$_POST["id"]`, `$_POST['feedID']` and `$_POST[ self::FIELD ]` were never
		// scanned; it cut a statement at the first `;`, including one inside a
		// string; and it could not see a guard on the line ABOVE the read, so a
		// safe early return read as a violation. PhpSourceDetectionTest drives all
		// four cases against this helper.
		$reads = \SbrPhpSource::statements_reading($src, '$_POST');

		$this->assertNotEmpty($reads, 'The builder must still read $_POST somewhere');

		$unguarded = array();

		foreach ($reads as $read) {
			// Only the reads that coerce. `wp_unslash($_POST['id'])` handed to
			// parse_delete_ids() is checked there, per its own docblock.
			if (! preg_match('/absint\(|\(int\)|\(string\)/', $read['statement'])) {
				continue;
			}

			// A guard in the statement itself OR in the one before it — an early
			// `if (! is_scalar(...)) { return; }` is the safest form there is, and
			// the old check called it a violation.
			$scope = $read['preceding'] . ' ' . $read['statement'];

			if (! preg_match('/is_scalar\(|is_numeric\(/', $scope)) {
				$unguarded[] = trim(preg_replace('/\s+/', ' ', $read['statement']));
			}
		}

		$this->assertSame(
			array(),
			$unguarded,
			"Every coercing \$_POST read needs is_scalar/is_numeric first — absint(array()) is 1, "
			. "and (string) array() is \"Array\" plus a PHP 8 warning:\n" . implode("\n", $unguarded)
		);
	}

	/**
	 * The measurement the guard exists for, so the reason cannot rot into folklore.
	 *
	 * @return void
	 */
	public function test_absint_of_an_array_is_one_not_zero()
	{
		$this->assertSame(1, absint(array( 9 )), 'a non-empty array casts to 1, which is a valid post id');

		// Was written as `assertSame(1, absint(array()) === 0 ? 1 : absint(array(9)))`,
		// which cannot fail: if the condition holds the expression IS 1, and if it
		// does not it falls back to the value asserted on the line above. Both
		// branches pass whatever absint() actually does with an empty array.
		$this->assertSame(0, absint(array()), 'an EMPTY array casts to 0, so it cannot be mistaken for an id');
	}

	/**
	 * The same rule across every badge class, for $_POST and $_GET alike.
	 *
	 * @return void
	 */
	public function test_every_badge_class_guards_its_request_reads()
	{
		$unguarded = array();
		$scanned   = 0;

		foreach ($this->badge_sources() as $file => $src) {
			foreach (array( '$_POST', '$_GET' ) as $superglobal) {
				foreach (\SbrPhpSource::statements_reading($src, $superglobal) as $read) {
					if (! preg_match('/absint\(|\(int\)|\(string\)/', $read['statement'])) {
						continue;
					}

					$scanned++;

					$scope = $read['preceding'] . ' ' . $read['statement'];

					if (! preg_match('/is_scalar\(|is_numeric\(/', $scope)) {
						$unguarded[] = $file . ': ' . trim(preg_replace('/\s+/', ' ', $read['statement']));
					}
				}
			}
		}

		$this->assertGreaterThan(0, $scanned, 'no coercing request read was examined at all');

		$this->assertSame(
			array(),
			$unguarded,
			"Every coercing request read in class/Common/Badges needs is_scalar/is_numeric first:\n"
			. implode("\n", $unguarded)
		);
	}

	/**
	 * @return void
	 */
	public function test_the_guarded_routes_still_default_to_zero()
	{
		$src = $this->builder_source();

		// A guard that defaulted to 1 instead of 0 would pass the test above and
		// still hit badge 1. Every id read falls back to 0.
		// Tokenised like its sibling: the bespoke regex here had the same holes
		// the docblock says were fixed — a `;` inside a string ended the statement,
		// and only a single-quoted lowercase key was matched.
		$reads = array();

		foreach (\SbrPhpSource::statements_reading($src, '$_POST') as $read) {
			if (preg_match('/absint\(/', $read['statement'])) {
				$reads[] = $read['statement'];
			}
		}

		$this->assertNotEmpty($reads, 'the builder must still absint() a posted id');

		foreach ($reads as $read) {
			$this->assertMatchesRegularExpression(
				'/:\s*0\s*;/',
				preg_replace('/\s+/', ' ', $read),
				'An id read must fall back to 0: ' . trim(preg_replace('/\s+/', ' ', $read))
			);
		}
	}
}
