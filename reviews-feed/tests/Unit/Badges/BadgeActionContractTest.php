<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The badge AJAX action names are a JS->PHP contract (SBR-163).
 *
 * A review round pointed out the names were inline string literals in four JS
 * files — save twice, plus list/duplicate/delete in a third — so renaming one in
 * PHP meant chasing it by grep with nothing to fail if a site was missed. They now
 * live in one place, `Utils/BadgeSettings.js`'s `BADGE_ACTIONS`.
 *
 * Which only helps if the two sides still agree, so this asserts that: every name
 * the JS sends is an action PHP actually registers, and the fallback admin URL
 * matches the registered menu slug. A rename on either side fails here rather than
 * becoming a button that does nothing.
 */
class BadgeActionContractTest extends TestCase
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

	protected function store_source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Store.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * The customizer's BadgeSettings.js, asserted rather than returned as null.
	 *
	 * One policy in one place. Two of the three callers skipped on null and the
	 * third passed it into preg_match(), which is a PHP 8.1 deprecation on
	 * $subject and then failed with "must still export DEFAULT_PER_PAGE" — a
	 * message that reads as drift when the real cause is a missing vendor tree.
	 *
	 * Hard-fail is the suite's rule for a missing VENDORED FILE (a missing machine
	 * CAPABILITY skips — see BadgeMarkAssetsTest): the file ships in both dev
	 * shapes, the monorepo symlink and a plain `composer install` copy, so its
	 * absence means the tree was never installed.
	 *
	 * @return string
	 */
	protected function badge_settings_js()
	{
		$path = dirname(__DIR__, 3)
			. '/vendor/smashballoon/customizer/sb-common/sb-customizer/src/Utils/BadgeSettings.js';

		$this->assertFileIsReadable(
			$path,
			'the customizer source must be reachable, or these contracts cannot be compared'
		);

		return (string) file_get_contents($path);
	}

	/**
	 * @return void
	 */
	public function test_every_action_the_js_sends_is_registered_in_php()
	{
		$js = $this->badge_settings_js();

		preg_match('/export const BADGE_ACTIONS = \{(.*?)\};/s', $js, $block);
		$this->assertNotEmpty($block, 'BADGE_ACTIONS must still be exported from BadgeSettings.js');

		preg_match_all("/'(sbr_trust_badge_[a-z_]+)'/", $block[1], $found);
		$actions = $found[1];

		$this->assertNotEmpty($actions, 'BADGE_ACTIONS must name at least one action');

		$php = $this->builder_source();

		foreach ($actions as $action) {
			// The REGISTRATION, not a mention. `assertStringContainsString($action)`
			// was satisfied by the name appearing in a comment, a docblock or a dead
			// branch — so removing `add_action('wp_ajax_…')` while leaving any
			// reference behind passed, which is the one thing this test exists to
			// catch. Whitespace-insensitive, so WPCS spacing cannot fail it.
			$this->assertTrue(
				\SbrPhpSource::contains($php, "add_action('wp_ajax_{$action}'"),
				"the JS sends '{$action}' but SBR_Trust_Badge_Builder registers no "
					. "wp_ajax_{$action} handler — a rename on one side only, which reaches the "
					. 'user as a control that does nothing'
			);
		}
	}

	/**
	 * The page size the JS assumes before the localised value arrives must match the
	 * store's own, or the pager mis-totals for any request served before
	 * `trustBadges.perPage` is populated. A reviewer flagged the duplicated literal;
	 * this is the shared source of truth they asked for.
	 */
	public function test_the_js_default_page_size_matches_the_store()
	{
		$js = $this->badge_settings_js();

		preg_match('/export const DEFAULT_PER_PAGE = (\d+);/', $js, $found);
		$this->assertNotEmpty($found, 'BadgeSettings.js must still export DEFAULT_PER_PAGE');

		// Read from source, like MENU_SLUG above: these unit tests do not autoload the
		// plugin's classes, so the constant is compared as it is written.
		preg_match('/const PER_PAGE = (\d+);/', $this->store_source(), $php);
		$this->assertNotEmpty($php, 'SBR_Trust_Badge_Store must still declare PER_PAGE');

		$this->assertSame(
			(int) $php[1],
			(int) $found[1],
			'DEFAULT_PER_PAGE in BadgeSettings.js must equal SBR_Trust_Badge_Store::PER_PAGE'
		);
	}

	public function test_the_fallback_list_url_matches_the_registered_slug()
	{
		$js = $this->badge_settings_js();

		preg_match("/export const FALLBACK_LIST_URL = '([^']+)';/", $js, $found);
		$this->assertNotEmpty($found, 'FALLBACK_LIST_URL must still be exported');

		preg_match("/const MENU_SLUG = '([^']+)';/", $this->builder_source(), $slug);
		$this->assertNotEmpty($slug, 'SBR_Trust_Badge_Builder must still declare MENU_SLUG');

		$this->assertSame(
			'admin.php?page=' . $slug[1],
			$found[1],
			'the JS fallback URL and the registered menu slug must agree'
		);
	}
}
