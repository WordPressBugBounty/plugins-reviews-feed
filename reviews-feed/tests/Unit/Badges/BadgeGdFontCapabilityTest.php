<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Gd;

/**
 * GD counts as available only when it can also draw TEXT.
 *
 * The rung needs three things and a host can have the first two without the
 * third: the extension, FreeType, and a readable font file. FONT_CANDIDATES is
 * eight absolute system paths and the plugin ships none of its own, so on a host
 * whose `open_basedir` is the webroot — standard on shared hosting, which is the
 * host class this rung exists for — every probe fails and there is no font.
 *
 * Observed on a live QA box: eight `is_readable(): open_basedir restriction in
 * effect` warnings per render, `available()` false, and `?sb-badge=N` answering
 * 503. The 503 is the CORRECT outcome — the chain reported a capability it does
 * not have rather than drawing a textless badge — and that is exactly what this
 * test protects. Drop the font check from available() and the same host stops
 * reporting "cannot draw" and starts producing a badge with no numbers on it,
 * which is worse than nothing and would reach a customer's inbox.
 *
 * Deliberately NOT asserting that a font exists here: that is a property of the
 * machine, and this suite runs on machines with and without one. The invariant
 * is the AGREEMENT between the two answers.
 */
class BadgeGdFontCapabilityTest extends TestCase
{
	/**
	 * @return string
	 */
	private function font()
	{
		return (string) SBR_Badge_Gd::font(false);
	}

	/** @var array|null The production font cache as it was before this test touched it. */
	private $original_font_cache = null;

	/**
	 * @return \ReflectionProperty
	 */
	private function fontCacheProperty()
	{
		$cache = new \ReflectionProperty(SBR_Badge_Gd::class, 'font_cache');
		$cache->setAccessible(true);

		return $cache;
	}

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->original_font_cache = (array) $this->fontCacheProperty()->getValue();
	}

	/**
	 * Put the production static back.
	 *
	 * The suite runs in one process, so a cleared cache would make any later test
	 * re-probe the disk — or see a different answer depending on execution order.
	 * Emptying it is this file's whole technique, which makes restoring it this
	 * file's responsibility.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		$this->fontCacheProperty()->setValue(null, $this->original_font_cache);

		parent::tearDown();
	}

	/**
	 * Empty the resolved-font cache so the next call really probes the disk.
	 *
	 * Without this the silence assertion below measured a cache hit left by
	 * whichever test ran first and could not fail however noisy the probing was.
	 *
	 * @return void
	 */
	private function forgetFont()
	{
		$this->fontCacheProperty()->setValue(null, array());
	}

	/**
	 * @return void
	 */
	public function test_available_and_font_never_disagree()
	{
		$font = $this->font();

		if ($font === '') {
			$this->assertFalse(
				SBR_Badge_Gd::available(),
				'no readable font, so GD cannot draw the badge text — claiming availability here '
				. 'produces a badge with no numbers instead of an honest "cannot draw"'
			);

			return;
		}

		// A font was found, so it has to be usable — a path that is not readable
		// fails at the draw call with nothing said.
		$this->assertFileIsReadable($font, 'font() returned a path the process cannot read');

		if (extension_loaded('gd') && function_exists('imagettftext') && function_exists('imagefilledpolygon')) {
			$this->assertTrue(
				SBR_Badge_Gd::available(),
				'every capability is present, so the rung must be offered'
			);
		}
	}

	/**
	 * available() must GATE on the font, not merely mention it.
	 *
	 * Asserted on the source, and only because the behavioural version cannot
	 * fail on a machine that has a font: font() memoises its answer in a static,
	 * so the fontless state cannot be induced here once anything has called it —
	 * which makes the agreement test above a pass-by-absence on this machine and
	 * a real check only on a host like the QA box.
	 *
	 * This half has teeth everywhere. Removing the font condition is a one-line
	 * edit that turns an honest 503 into a badge with no numbers on it.
	 *
	 * @return void
	 */
	public function test_available_gates_on_the_font()
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Gd.php'
		);

		// The shared tokenised reader, not a character walk. This used to find the
		// body with `strpos($src, '{')` and end it at the first "\n\t}" — the exact
		// technique that gave a false pass elsewhere in this suite, and it would
		// also have ended the body early at any nested closing brace indented one
		// tab. Whitespace is squeezed on both sides too, so reformatting available()
		// cannot fail this for a non-reason.
		$body = \SbrPhpSource::method_body($src, 'available');

		$this->assertNotSame('', $body, 'available() not found');

		$this->assertTrue(
			\SbrPhpSource::contains($body, 'font('),
			'available() no longer consults font(), so a host with GD but no readable font '
			. 'is told the badge can be drawn — and the email badge renders without its numbers'
		);

		foreach (array( 'extension_loaded', 'imagettftext', 'imagefilledpolygon' ) as $capability) {
			$this->assertTrue(
				\SbrPhpSource::contains($body, $capability),
				"available() must still check {$capability}"
			);
		}
	}

	/**
	 * @return void
	 */
	public function test_every_candidate_is_an_absolute_path()
	{
		$reflection = new \ReflectionClass(SBR_Badge_Gd::class);
		$candidates = $reflection->getConstant('FONT_CANDIDATES');

		$this->assertIsArray($candidates);
		$this->assertNotEmpty($candidates);

		foreach ($candidates as $candidate) {
			// A relative entry would resolve against the CWD, which for a WordPress
			// request is not a place fonts live.
			$this->assertStringStartsWith('/', (string) $candidate, 'candidate is not absolute: ' . $candidate);
		}
	}

	/**
	 * Probing a candidate must not emit a warning.
	 *
	 * `is_readable()` on a path outside `open_basedir` raises a PHP warning, and
	 * eight candidates means eight warnings per render in the host's error log —
	 * measured on the QA box. Whatever font() does, it must not be noisy about a
	 * path it is only guessing at.
	 *
	 * @return void
	 */
	public function test_probing_for_a_font_is_silent()
	{
		// Cleared FIRST, or this measures a cached return.
		$this->forgetFont();

		$warnings = array();

		set_error_handler(
			static function ($errno, $errstr) use (&$warnings) {
				$warnings[] = $errstr;

				return true;
			},
			// E_ALL, not a hand-picked mask: the first version listed
			// E_WARNING | E_NOTICE | E_DEPRECATED, and a mutation that raised an
			// E_USER_WARNING during probing sailed straight through it. Whatever
			// the level, a diagnostic emitted while guessing at paths is the
			// thing being guarded against.
			E_ALL
		);

		try {
			$found = $this->font();
		} finally {
			restore_error_handler();
		}

		// Prove the probe actually ran — and prove it in a way that CAN fail.
		// `is_string($found)` could not: font() is `(string)`-cast at the call
		// above, so it held whether or not the cache was cleared and whether or not
		// a candidate was ever examined. What distinguishes the states is the
		// cache: empty before the call, populated after, and the value either ''
		// or one of the paths font_candidates() offers.
		$cache = $this->fontCacheProperty()->getValue();

		$this->assertNotSame(
			array(),
			$cache,
			'font() returned without memoising, so it never reached the probe'
		);

		$candidates = new \ReflectionMethod(SBR_Badge_Gd::class, 'font_candidates');
		$candidates->setAccessible(true);

		$this->assertTrue(
			$found === '' || in_array($found, (array) $candidates->invoke(null), true),
			'font() answered "' . $found . '", which is neither empty nor a candidate it was offered'
		);

		$this->assertSame(
			array(),
			$warnings,
			'font() raised ' . count($warnings) . ' diagnostic(s) while guessing at paths: '
			. implode(' | ', array_slice($warnings, 0, 3))
		);
	}
}
