<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The plugin's own front-end assets carry the same guards as the customizer's (SBR-163).
 *
 * Two classes have now recurred here specifically because the customizer's guards
 * cannot see this repo:
 *
 * 1. **noreferrer.** Fixed in the customizer's EmbedModal, then missed in
 *    CustomizerHeader when that code was extracted, then guarded by
 *    `window-open-hardening.test.js` — which walks `src/Components` in the CUSTOMIZER
 *    package. `assets/js/sbr-trust-badge-block.js` is in this repo, so the guard
 *    never covered it and it shipped with bare `'noopener'`. Third occurrence of one
 *    missing token.
 *
 * 2. **Text domain.** 63 customizer strings were tagged a domain the plugin neither
 *    registers nor extracts; `i18n-text-domain.test.js` now pins that — in the
 *    customizer. The block's 22 `__()` calls are one directory outside its reach,
 *    and were broken at BOTH ends: no `wp_set_script_translations` for the
 *    `sbr-trust-badge-block` handle, and no `assets/js` in make-pot's `--include`.
 *
 * The lesson is not "add another guard"; it is that a guard scoped to one half of a
 * two-repo feature reports green for the half it cannot see. This is the other half.
 */
class BadgeAssetHardeningTest extends TestCase
{
	/**
	 * @param string $relative
	 * @return string
	 */
	protected function read($relative)
	{
		$path = dirname(__DIR__, 3) . '/' . $relative;

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * @return array<int, string>
	 */
	protected function plugin_js_files()
	{
		$dir   = dirname(__DIR__, 3) . '/assets/js';
		$files = glob($dir . '/*.js');

		$this->assertNotEmpty($files, 'assets/js must still hold the plugin JS this guards');

		return $files;
	}

	/**
	 * @return void
	 */
	public function test_the_plugin_js_opens_new_tabs_with_links_not_window_open()
	{
		$found = array();

		foreach ($this->plugin_js_files() as $path) {
			$src = (string) file_get_contents($path);

			preg_match_all('/window\.open\((?:[^()]|\([^()]*\))*\)/s', $src, $calls);

			foreach ($calls[0] as $call) {
				$found[] = basename($path) . ': ' . trim(preg_replace('/\s+/', ' ', $call));
			}
		}

		// Zero, not "zero unhardened ones". The five call sites this file used to
		// police became anchors because as buttons they lost cmd-click,
		// middle-click and "open in new tab", and announced as "button" rather
		// than "link" — so a window.open is a regression whether or not it names
		// noopener. The hardening rule for the links that replaced them is the
		// test below, which carries its own floor.
		//
		// The offender-list version of this test could not fail once the set went
		// empty, which is why the assertion is the count.
		$this->assertSame(
			array(),
			$found,
			'new-tab navigation belongs in a link: ' . implode("\n", $found)
		);
	}

	/**
	 * Every new-tab navigation the plugin JS renders as a LINK carries both tokens.
	 *
	 * The five controls that used `window.open` are anchors now — they navigate to
	 * wp-admin screens, and as buttons they lost cmd-click, middle-click and "open
	 * in new tab", and announced as "button" rather than "link". Which means the
	 * sibling test above has no `window.open` left to inspect and now passes because
	 * its set is empty: a clean result and a broken matcher look identical.
	 *
	 * So the guard follows the code. This asserts the shape that replaced it, with a
	 * floor — which is the thing that stops THIS one going quiet the same way.
	 *
	 * @return void
	 */
	public function test_every_blank_link_in_the_plugin_js_sets_noopener_and_noreferrer()
	{
		$checked   = 0;
		$offenders = array();

		foreach ($this->plugin_js_files() as $path) {
			$src = (string) file_get_contents($path);

			preg_match_all("/target:\\s*'_blank'/", $src, $targets, PREG_OFFSET_CAPTURE);

			foreach ($targets[0] as $target) {
				$checked++;

				// The rel sits beside target in the same props object.
				$window = substr($src, max(0, $target[1] - 200), 400);

				if (strpos($window, 'noopener') === false || strpos($window, 'noreferrer') === false) {
					$offenders[] = basename($path) . ': ' . trim(preg_replace('/\\s+/', ' ', $window));
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			5,
			$checked,
			'the plugin JS renders five new-tab links; finding fewer means the matcher '
			. 'broke, not that the risk went away'
		);

		$this->assertSame(array(), $offenders, implode("\n", $offenders));
	}

	/**
	 * @return void
	 */
	public function test_every_blank_target_in_a_badge_template_sets_noreferrer()
	{
		$dir       = dirname(__DIR__, 3) . '/templates/frontend/badges';
		$templates = glob($dir . '/*.php');
		$offenders = array();
		$checked   = 0;

		$this->assertNotEmpty($templates);

		foreach ($templates as $path) {
			$src = (string) file_get_contents($path);

			/*
			 * Anchored on the target attribute, not on `<a … >`. These templates
			 * carry BOTH shapes — one anchor is literal markup with a PHP echo in
			 * its href, the other is assembled by string concatenation inside PHP —
			 * and neither a markup regex nor a strip-the-PHP-first regex sees both.
			 *
			 * The first draft did strip PHP blocks and matched one of two anchors.
			 * It reported clean. The floor below is what caught it, which is the
			 * argument for having one.
			 */
			preg_match_all('/target=(?:"|\')_blank(?:"|\')/', $src, $targets, PREG_OFFSET_CAPTURE);

			foreach ($targets[0] as $target) {
				$checked++;

				// The rel may sit either side of target, and in the concatenated
				// shape there is PHP between them, so inspect a window rather than
				// trying to bound the element.
				$from   = max(0, $target[1] - 250);
				$window = substr($src, $from, 500);

				if (strpos($window, 'noreferrer') === false || strpos($window, 'noopener') === false) {
					$offenders[] = basename($path) . ': ' . trim(preg_replace('/\s+/', ' ', $window));
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			2,
			$checked,
			'The matcher must still find both footer anchors; too few matches is a '
			. 'broken regex, not a clean result'
		);

		$this->assertSame(
			array(),
			$offenders,
			"A badge template's _blank anchor needs both tokens, so the PR does not ship "
			. "one convention in JS and another in PHP:\n" . implode("\n", $offenders)
		);
	}

	public function test_every_enqueued_script_using_translations_is_registered_for_them()
	{
		$service = $this->read('class/Common/Badges/SBR_Badge_Service.php');
		$offenders = array();

		foreach ($this->plugin_js_files() as $path) {
			$src = (string) file_get_contents($path);

			if (! preg_match('/\b__\(/', $src)) {
				continue;
			}

			$handle = 'sbr-' . str_replace('.js', '', basename($path));
			$handle = str_replace('sbr-sbr-', 'sbr-', $handle);

			// Only files this plugin actually enqueues under a handle it names.
			if (strpos($service, "'" . $handle . "'") === false) {
				continue;
			}

			if (strpos($service, "wp_set_script_translations('" . $handle . "'") === false) {
				$offenders[] = $handle . ' (' . basename($path) . ') uses __() with no wp_set_script_translations';
			}
		}

		$this->assertSame(array(), $offenders, implode("\n", $offenders));
	}

	/**
	 * @return void
	 */
	public function test_deleting_a_badge_purges_its_rendered_files()
	{
		$store = $this->read('class/Common/Badges/SBR_Trust_Badge_Store.php');

		/*
		 * The only sweep used to live in the render path, so it ran on the NEXT
		 * render of the same badge — which after a delete never happens. The image
		 * outlived the badge in a web-served uploads directory, carrying its rating,
		 * review count and platform marks, and survived uninstall.
		 */
		$this->assertMatchesRegularExpression(
			'/SBR_Badge_Image::purge\(\s*\$id\s*\)/',
			$store,
			'Store::delete() must purge the badge\'s rendered files'
		);

		// Before wp_delete_post, while the id still means something.
		$purge_at  = strpos($store, 'SBR_Badge_Image::purge');
		$delete_at = strpos($store, 'wp_delete_post($id, true)');

		$this->assertNotFalse($purge_at);
		$this->assertNotFalse($delete_at);
		$this->assertLessThan($delete_at, $purge_at, 'Purge must run before the post is deleted');
	}

	/**
	 * @return void
	 */
	public function test_the_purge_glob_can_reach_a_stranded_temp_file()
	{
		$image = $this->read('class/Common/Badges/SBR_Badge_Image.php');

		/*
		 * The writer renders to `<file>.<random>.tmp` and renames. A glob ending in
		 * `.png` cannot match that by construction, so a process death between the
		 * write and the rename stranded a file nothing would ever remove — in the
		 * directory an unauthenticated endpoint serves from.
		 *
		 * Asserted as two plain substrings rather than a regex: the first version
		 * used one, and its inner quotes closed the PHP string.
		 */
		$suffix_free = 'absint($badge_id) . \'-*\'';
		$png_bound   = 'absint($badge_id) . \'-*.png\'';

		$this->assertStringContainsString(
			$suffix_free,
			$image,
			'purge() must glob badge-<id>-* so it can also reach a .png.<rand>.tmp temp file'
		);

		$this->assertStringNotContainsString(
			$png_bound,
			$image,
			'A glob bounded to .png can never match the temp file the writer creates'
		);
	}

	public function test_make_pot_extracts_the_plugin_js()
	{
		$makefile = $this->read('Makefile');

		preg_match('/make-pot[^\n]*--include="([^"]+)"/', $makefile, $found);
		$this->assertNotEmpty($found, 'The Makefile must still declare a make-pot --include list');

		$included = array_map('trim', explode(',', $found[1]));

		$this->assertContains(
			'assets/js',
			$included,
			'assets/js must be extracted, or every __() in the plugin JS is invisible to translators'
		);
	}
}
