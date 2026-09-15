<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder;

// One class per file (PSR1), so the failing-stream double lives in tests/Doubles.
require_once __DIR__ . '/../Doubles/SbrFailingHalfwayStream.php';

/**
 * The customizer-bundle probe behind the Trust Badges screen (SBR-163).
 *
 * The screens ship in the customizer package, which is versioned separately, so
 * a build composed against a customizer that predates them enqueues a bundle
 * with no `trustBadges` in it and the mount renders empty forever — no console
 * error, no failed request. QA hit exactly that on a Build-for-QA artifact.
 * These pin the probe that turns that silence into a message.
 */
class Sbr163BundleScreenProbeTest extends TestCase
{
	/**
	 * The transient shim (tests/bootstrap.php) is a process-wide global, so a
	 * key written here would outlive this class and answer for whatever ran
	 * next. Restored rather than trusted to be harmless.
	 *
	 * @var array<string, mixed>|null
	 */
	private $transients_before = null;

	/**
	 * Fixture files this test wrote, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		global $wp_transients_mock;

		$this->transients_before = isset($wp_transients_mock) ? $wp_transients_mock : null;
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $wp_transients_mock;

		if (null === $this->transients_before) {
			unset($wp_transients_mock);
		} else {
			$wp_transients_mock = $this->transients_before;
		}

		// Cleaned here rather than after the assertions: a failing assertion
		// returns early, and these fixtures are up to 512KB each.
		foreach ($this->temp_files as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}

		$this->temp_files = array();

		parent::tearDown();
	}

	/**
	 * @return ReflectionMethod
	 */
	private function file_contains()
	{
		$method = new ReflectionMethod(SBR_Trust_Badge_Builder::class, 'file_contains');
		$method->setAccessible(true);

		return $method;
	}

	/**
	 * @param  string $contents
	 * @return string Path to a temp file the test owns.
	 */
	private function write_temp($contents)
	{
		$path = tempnam(sys_get_temp_dir(), 'sbr-bundle-');
		file_put_contents($path, $contents);

		$this->temp_files[] = $path;

		return $path;
	}

	/**
	 * A bundle carrying the screen marker reports supported.
	 *
	 * @return void
	 */
	public function test_a_bundle_with_the_marker_is_supported()
	{
		$path = $this->write_temp('var x=1;window.sb_customizer.reactScreen==="trustBadges";');

		$this->assertTrue($this->file_contains()->invoke(null, $path, 'trustBadges'));
	}

	/**
	 * A bundle without it reports unsupported — the case that produces the blank
	 * screen, and the only case allowed to raise the notice.
	 *
	 * @return void
	 */
	public function test_a_bundle_without_the_marker_is_not_supported()
	{
		$path = $this->write_temp('var x=1;window.sb_customizer.reactScreen==="reviewAlerts";');

		$this->assertFalse($this->file_contains()->invoke(null, $path, 'trustBadges'));
	}

	/**
	 * The reader streams the file in 512KB chunks, so a marker lying across a
	 * chunk boundary is the case a naive loop misses. The shipped bundle is
	 * ~1.1MB over 2 lines, so this is not hypothetical: where the marker falls
	 * is decided by whatever the minifier emitted that day.
	 *
	 * @return void
	 */
	public function test_a_marker_straddling_the_chunk_boundary_is_found()
	{
		$chunk = 524288;

		foreach (array( 5, 1, 10 ) as $overhang) {
			$path = $this->write_temp(
				str_repeat('x', $chunk - $overhang) . 'trustBadges' . str_repeat('y', 64)
			);

			$this->assertTrue(
				$this->file_contains()->invoke(null, $path, 'trustBadges'),
				"marker split with {$overhang} bytes before the boundary should still be found"
			);
		}
	}

	/**
	 * Fails OPEN. An unreadable path means "cannot tell", and a wrong path must
	 * never put an error notice over a screen that works — the browser fetches
	 * the bundle by URL, which can succeed where a filesystem read does not.
	 *
	 * @return void
	 */
	public function test_an_unreadable_path_is_treated_as_supported()
	{
		$this->assertTrue(
			$this->file_contains()->invoke(null, '/nonexistent/sbr-not-here.js', 'trustBadges')
		);
	}

	/**
	 * An empty file is readable and genuinely has no marker, so it is a real
	 * negative rather than a "cannot tell".
	 *
	 * @return void
	 */
	public function test_an_empty_bundle_is_not_supported()
	{
		$path = $this->write_temp('');

		$this->assertFalse($this->file_contains()->invoke(null, $path, 'trustBadges'));
	}

	/**
	 * A read that fails PART WAY through must also fail open.
	 *
	 * The file was never seen to the end, so "the marker is absent" is not a
	 * conclusion available — and drawing it would put an error notice over a
	 * bundle that is probably fine. Only reachable on a stream that errors
	 * mid-read (network or FUSE mounts), hence the wrapper: `fread()` on a local
	 * file effectively never does this, which is why the branch went untested
	 * until mutation-check reported it SURVIVED.
	 *
	 * @return void
	 */
	public function test_a_read_error_part_way_through_is_treated_as_supported()
	{
		stream_wrapper_register('sbrprobefail', SbrFailingHalfwayStream::class);

		try {
			$this->assertTrue(
				$this->file_contains()->invoke(null, 'sbrprobefail://bundle.js', 'trustBadges'),
				'a mid-stream read error must fail open, not report the marker missing'
			);
		} finally {
			stream_wrapper_unregister('sbrprobefail');
		}
	}

	/**
	 * The screen names the PHP localises are the strings the probe looks for. If
	 * either constant is renamed without the customizer following, the probe
	 * would report every bundle unsupported — so pin them.
	 *
	 * @return void
	 */
	public function test_the_screen_names_are_the_ones_the_customizer_switches_on()
	{
		$this->assertSame('trustBadges', SBR_Trust_Badge_Builder::SCREEN_LIST);
		$this->assertSame('trustBadgeEditor', SBR_Trust_Badge_Builder::SCREEN_EDITOR);
	}

	/**
	 * The probed path must come from `SB_CUSTOMIZER_ASSETS` — the constant the
	 * enqueue uses — and not from `SBR_PLUGIN_DIR`.
	 *
	 * bootstrap.php:50 defines that constant behind `if (! defined(...))`, and
	 * the free plugin's bootstrap.php:46 defines it identically, so whichever
	 * Smash Balloon plugin loads first owns it and it can point at that plugin's
	 * vendor copy. Probing our own directory then reads a bundle nobody loads.
	 *
	 * Runs isolated because a constant cannot be undeclared, so defining these
	 * here would change the answer for every later test in the process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @return void
	 */
	public function test_the_probed_path_follows_the_constant_the_enqueue_uses()
	{
		$content_dir = sys_get_temp_dir() . '/sbr-content-' . uniqid('', false);
		$assets      = '/plugins/some-other-sb-plugin/vendor/smashballoon/customizer/sb-common/sb-customizer';

		define('WP_CONTENT_DIR', $content_dir);
		define('WP_CONTENT_URL', 'https://example.test/wp-content');
		// Deliberately http where WP_CONTENT_URL is https: plugin_dir_url()
		// follows the request, so the two genuinely differ on mixed-scheme sites.
		define('SB_CUSTOMIZER_ASSETS', 'http://example.test/wp-content' . $assets);

		$path_method = new ReflectionMethod(SBR_Trust_Badge_Builder::class, 'bundle_path');
		$path_method->setAccessible(true);

		$this->assertSame(
			$content_dir . $assets . '/build/static/js/main.js',
			$path_method->invoke(null),
			'the probe must read the other plugin\'s bundle, which is the one the browser loads'
		);
	}

	/**
	 * A licensed builder whose render path is not guarded would echo the mount
	 * regardless, so these two drive `render_page()` itself rather than the
	 * helper it calls — otherwise the helpers are tested and the route is not.
	 *
	 * @param  bool $supported What the probe should answer.
	 * @return string Captured output of render_page().
	 */
	private function render_with_probe_answering($supported)
	{
		$path_method = new ReflectionMethod(SBR_Trust_Badge_Builder::class, 'bundle_path');
		$path_method->setAccessible(true);
		$path = $path_method->invoke(null);

		// Skipped rather than errored where vendor/ is not built: filesize() on a
		// missing path warns, and phpunit.xml sets failOnWarning="true", so this
		// would fail for the wrong reason on a fresh checkout or in CI.
		if ('' === $path || ! is_readable($path)) {
			$this->markTestSkipped('customizer bundle not present at ' . $path . ' — vendor/ not built');
		}

		// Drive the answer through the class's own cache rather than by touching
		// the shipped bundle. Same key it computes, so no file is read.
		$key = 'sbr_tb_bundle_screens_' . md5($path . '|' . filesize($path) . '|' . filemtime($path));
		set_transient($key, $supported ? 'yes' : 'no', WEEK_IN_SECONDS);

		$class   = new \ReflectionClass(SBR_Trust_Badge_Builder::class);
		$builder = $class->newInstanceWithoutConstructor();

		$licensed = $class->getProperty('licensed');
		$licensed->setAccessible(true);
		$licensed->setValue($builder, true);

		ob_start();
		$builder->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * The supported case still mounts the app and says nothing extra — the
	 * guard must not become a warning over a screen that works.
	 *
	 * @return void
	 */
	public function test_a_supported_bundle_still_mounts_the_app()
	{
		$out = $this->render_with_probe_answering(true);

		$this->assertStringContainsString('id="sb-app"', $out);
		$this->assertStringNotContainsString('notice-error', $out);
	}

	/**
	 * The unsupported case replaces the mount with a visible message. Mounting
	 * anyway is the original defect: a 0px container and total silence.
	 *
	 * @return void
	 */
	public function test_an_unsupported_bundle_explains_itself_instead_of_mounting()
	{
		$out = $this->render_with_probe_answering(false);

		$this->assertStringNotContainsString('id="sb-app"', $out);
		$this->assertStringContainsString('notice-error', $out);
		$this->assertStringContainsString('cannot be displayed in this build', $out);
	}
}
