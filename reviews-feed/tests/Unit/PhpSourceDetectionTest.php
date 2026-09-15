<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The superglobal-read detector behind the $_POST guard test (SMASH-2074).
 *
 * `BadgeRequestFieldGuardTest` is presented as the thing that stops
 * `absint(array())`-style request smuggling, so its DETECTION has to be worth
 * that billing. The regex it used had three holes, all found in review, all
 * covered below — plus the false-positive direction, which is the one that
 * wastes a reviewer's afternoon rather than hiding a bug.
 *
 * Each case names the old regex as the control where the old behaviour is
 * demonstrable, so a case both approaches handle cannot read as evidence.
 */
final class PhpSourceDetectionTest extends TestCase
{
	/** The pattern this replaced, kept as the control. */
	private const OLD = '/\$_POST\[\'([a-z_]+)\'\][^;]*?;/s';

	/** A double-quoted key was invisible to the old pattern. */
	public function test_a_double_quoted_key_is_seen(): void
	{
		$src = '<?php $id = absint($_POST["id"]);';

		$this->assertCount(1, \SbrPhpSource::statements_reading($src, '$_POST'));
		$this->assertSame(0, preg_match_all(self::OLD, $src), 'control: the old pattern missed it');
	}

	/** So was a camelCase key. */
	public function test_a_camel_case_key_is_seen(): void
	{
		$src = "<?php \$id = absint(\$_POST['feedID']);";

		$this->assertCount(1, \SbrPhpSource::statements_reading($src, '$_POST'));
		$this->assertSame(0, preg_match_all(self::OLD, $src), 'control: the old pattern missed it');
	}

	/** And a constant key. */
	public function test_a_constant_key_is_seen(): void
	{
		$src = '<?php $id = absint($_POST[ self::FIELD ]);';

		$this->assertCount(1, \SbrPhpSource::statements_reading($src, '$_POST'));
		$this->assertSame(0, preg_match_all(self::OLD, $src), 'control: the old pattern missed it');
	}

	/** A `;` inside a string must not end the statement. */
	public function test_a_semicolon_in_a_string_does_not_cut_the_statement(): void
	{
		$src = "<?php \$id = absint(\$_POST['id']) . 'a;b' . 'tail';";

		$reads = \SbrPhpSource::statements_reading($src, '$_POST');

		$this->assertCount(1, $reads);
		$this->assertStringContainsString('tail', $reads[0]['statement'], 'the statement was truncated at the string');
	}

	/** A guard on the LINE ABOVE is visible, which is the false-positive case. */
	public function test_the_preceding_statement_comes_back_with_the_read(): void
	{
		$src = "<?php\nif (! is_scalar(\$raw)) { return; }\n\$id = absint(\$_POST['id']);\n";

		$reads = \SbrPhpSource::statements_reading($src, '$_POST');

		$this->assertCount(1, $reads);
		$this->assertStringContainsString(
			'is_scalar',
			$reads[0]['preceding'],
			'a guard written above the read must be visible, or it is reported as a violation'
		);
	}

	/** A read inside a comment is not a read. */
	public function test_a_commented_out_read_is_not_counted(): void
	{
		$src = "<?php\n// \$id = absint(\$_POST['id']);\n\$x = 1;\n";

		$this->assertSame(array(), \SbrPhpSource::statements_reading($src, '$_POST'));
		$this->assertSame(1, preg_match_all(self::OLD, $src), 'control: the old pattern counted the comment');
	}

	/** Assigning the whole array is not a keyed read. */
	public function test_replacing_the_whole_superglobal_is_not_a_read(): void
	{
		$src = '<?php $_POST = array();';

		$this->assertSame(array(), \SbrPhpSource::statements_reading($src, '$_POST'));
	}

	/** And it still finds the reads in the file the guard test scans. */
	public function test_it_finds_the_reads_in_the_badge_builder(): void
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 2) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php'
		);

		$reads = \SbrPhpSource::statements_reading($src, '$_POST');

		// A floor, not an exact count: the number moves whenever a handler is
		// added, and pinning it would make this test a maintenance tax with no
		// extra teeth.
		$this->assertGreaterThan(8, count($reads), 'the detector found suspiciously few reads');
	}
}
