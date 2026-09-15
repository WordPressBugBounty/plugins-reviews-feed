<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;

/**
 * The customizer bundle's cache key must move when the bundle does (SBR-163).
 *
 * Two comments in this codebase claimed SBRVER "busts the browser cache on a
 * rebuilt bundle". It does not — it busts on a VERSION change. Two QA builds of
 * 2.12.0 are byte-different behind an identical `?ver=2.12.0`, and the bundle is
 * served `Cache-Control: max-age=2592000`.
 *
 * Measured on an InstaQA box 2026-09-09, after swapping in a rebuilt plugin:
 * `cf-cache-status: HIT`, `age: 3008`, `last-modified` an hour stale, serving the
 * PREVIOUS bundle against the new PHP. That combination crashes the app on mount —
 * a blank admin screen that reproduces on nobody's machine, because every local
 * environment fetches the asset fresh.
 */
class BadgeAssetVersionTest extends TestCase
{
	/** @var string */
	private $fixtureDir = '';

	/**
	 * Removed here rather than at the end of the test body.
	 *
	 * Cleanup in the body only runs when every assertion before it passed, so the
	 * first failure used to leave the directory behind and the next run inherited
	 * it. tearDown runs either way.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->fixtureDir !== '' && is_dir($this->fixtureDir)) {
			foreach ((array) glob($this->fixtureDir . '/*') as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}

			rmdir($this->fixtureDir);
		}

		$this->fixtureDir = '';

		parent::tearDown();
	}

	/**
	 * A fixture directory INSIDE the plugin, and the path to reach it.
	 *
	 * Under SBR_PLUGIN_DIR on purpose. asset_version() resolves its argument
	 * against that constant, so a fixture in the system temp dir could only be
	 * reached by counting the segments of SBR_PLUGIN_DIR and walking back up with
	 * `../` — which breaks the moment the plugin sits at a different depth. A
	 * genuinely relative path needs no walking and tests the real code path.
	 *
	 * @return string Relative directory, with a trailing slash.
	 */
	private function fixtureRelativeDir()
	{
		$this->assertTrue(defined('SBR_PLUGIN_DIR'), 'SBR_PLUGIN_DIR is required to place a fixture');

		$relative = 'tests/.fixtures-asset-version-' . getmypid();
		$this->fixtureDir = rtrim(SBR_PLUGIN_DIR, '/') . '/' . $relative;

		// Asserted, not suppressed: a fixture that was never created makes every
		// assertion below measure the missing-file branch instead.
		$this->assertTrue(
			is_dir($this->fixtureDir) || mkdir($this->fixtureDir, 0777, true),
			'could not create the fixture directory: ' . $this->fixtureDir
		);

		return $relative . '/';
	}

	/**
	 * @return void
	 */
	public function test_the_version_changes_when_the_file_changes()
	{
		$relDir = $this->fixtureRelativeDir();
		$rel    = $relDir . 'bundle.js';
		$path   = rtrim(SBR_PLUGIN_DIR, '/') . '/' . $rel;

		file_put_contents($path, 'one');
		touch($path, 1000000000);
		clearstatcache(true, $path);

		// SBR_PLUGIN_DIR is what asset_version() resolves against.
		$first = Util::asset_version($rel);

		touch($path, 1000000001);
		clearstatcache(true, $path);

		$second = Util::asset_version($rel);

		$this->assertNotSame(
			$first,
			$second,
			'a rebuilt bundle must produce a different ?ver= or the edge serves the old one for 30 days'
		);

		// And it still carries the plugin version, so the string stays readable in
		// a network panel rather than becoming a bare timestamp.
		$this->assertStringStartsWith(
			defined('SBRVER') ? SBRVER : '0',
			(string) $first,
			'the human-readable plugin version must survive'
		);

		// Cleanup is in tearDown, so a failure above cannot leak the directory.
	}

	/**
	 * A missing asset must not break the enqueue — it falls back to what shipped
	 * before this existed.
	 *
	 * @return void
	 */
	public function test_a_missing_asset_falls_back_to_the_plugin_version()
	{
		$this->assertSame(
			defined('SBRVER') ? SBRVER : false,
			Util::asset_version('definitely-not-here-' . getmypid() . '.js'),
			'an unreadable asset must degrade to SBRVER, not to false or a fatal'
		);
	}

	/**
	 * Every customizer-bundle enqueue must use it — one missed call site is a
	 * screen that still serves a stale bundle.
	 *
	 * @return void
	 */
	public function test_no_customizer_enqueue_still_versions_by_the_plugin_version_alone()
	{
		$files = array(
			'/class/Common/Badges/SBR_Trust_Badge_Builder.php',
			'/class/Common/ReviewAlerts/SBR_ReviewAlert_Builder.php',
		);

		$total = 0;

		foreach ($files as $file) {
			$path = dirname(__DIR__, 3) . $file;
			$this->assertFileExists($path);
			// Comments stripped before counting. Two regexes in this session matched a
			// COMMENT that named the very thing being asserted — including one in the
			// file under test, which claims `Util::asset_version()` in prose. Counting
			// code-only text removes the category instead of tightening the pattern
			// a third time.
			$src = $this->codeOnly((string) file_get_contents($path));

			// Count the customizer bundle enqueues, then require an asset_version()
			// for each — a bare SBRVER or `false` beside one of these is the defect.
			$bundles = preg_match_all('#SB_CUSTOMIZER_ASSETS \. \'/build/static/(js|css)/main\.(js|css)\'#', $src);
			$versions = preg_match_all('/Util::asset_version\(/', $src);

			$this->assertGreaterThan(0, $bundles, "no customizer bundle enqueue found in {$file}");
			$this->assertSame(
				$bundles,
				$versions,
				"every customizer bundle enqueue in {$file} must be versioned by the file"
			);

			$total += $versions;
		}

		$this->assertGreaterThanOrEqual(6, $total, 'expected at least six enqueue sites across the two builders');
	}

	/**
	 * Source with every comment removed, so a pattern cannot match prose.
	 *
	 * @param string $src PHP source.
	 *
	 * @return string
	 */
	private function codeOnly($src)
	{
		$out = '';

		foreach (token_get_all($src) as $token) {
			if (is_array($token)) {
				if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
					continue;
				}

				$out .= $token[1];
				continue;
			}

			$out .= $token;
		}

		return $out;
	}

}
