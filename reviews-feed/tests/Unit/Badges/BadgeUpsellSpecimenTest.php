<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder;

/**
 * The locked Trust Badges screen draws a FIXED badge; the licensed screens do not.
 *
 * The hero on the locked screen used to be specimen_html(), which reads the site's
 * own feed and sources and answers '' when there are none. On a site with no feed —
 * a Lite install, or the licence-deactivated site QA reported this from — the left
 * column disappeared entirely and the screen lost half its layout.
 *
 * Verified live before this test was written, on demo-wp2 with the feeds table
 * emptied: the old call left `.sbr-tb-splash` at 0 and the wrapper carrying
 * `.sbr-tb-upsell-no-specimen`; the new one renders the badge either way.
 *
 * The pair is the point. specimen_html() MUST stay site-specific: it feeds the All
 * Badges empty state and `trustBadges.specimenHtml`, which TrustBadgesEmptyState.js
 * renders for a licensed customer who has sources of their own.
 */
class BadgeUpsellSpecimenTest extends TestCase
{
	/**
	 * @return string
	 */
	private function builder_source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileIsReadable($path);

		return (string) file_get_contents($path);
	}

	/**
	 * @return void
	 */
	public function test_the_locked_screen_calls_the_fixed_specimen()
	{
		$this->assertStringContainsString(
			'$specimen = self::upsell_specimen_html();',
			$this->builder_source(),
			'the locked screen must not read the site for its hero'
		);
	}

	/**
	 * The fixed specimen reads nothing about the site.
	 *
	 * Asserted on the method body rather than by calling it, because what makes it
	 * safe is the absence of a lookup — and an absence is what a live call on a
	 * populated site cannot show.
	 *
	 * @return void
	 */
	public function test_the_fixed_specimen_has_no_site_lookups()
	{
		$source = $this->builder_source();
		$start  = strpos($source, 'public static function upsell_specimen_html()');
		$this->assertNotFalse($start, 'upsell_specimen_html() must exist');

		$end  = strpos($source, "\n\t/**", $start + 1);
		$body = $end === false ? substr($source, $start) : substr($source, $start, $end - $start);

		foreach (array( 'cached_aggregate', 'specimen_providers', 'get_sources_list' ) as $lookup) {
			$this->assertStringNotContainsString(
				$lookup,
				$body,
				"upsell_specimen_html() must not call {$lookup}() — that is what emptied the hero"
			);
		}
	}

	/**
	 * @return void
	 */
	public function test_the_fixed_specimen_carries_the_sample_numbers_and_marks()
	{
		$source = $this->builder_source();

		$this->assertStringContainsString("'rating'    => 4.6,", $source);
		$this->assertStringContainsString("'count'     => 12,", $source);

		foreach (SBR_Trust_Badge_Builder::UPSELL_SPECIMEN_PROVIDERS as $provider) {
			$this->assertIsString($provider);
			$this->assertNotSame('', $provider);
		}

		$this->assertGreaterThan(
			2,
			count(SBR_Trust_Badge_Builder::UPSELL_SPECIMEN_PROVIDERS),
			'the card shape is chosen because it shows several marks at once'
		);
	}

	/**
	 * The licensed surfaces keep their own, site-specific specimen.
	 *
	 * If someone ever "simplifies" this by pointing both at the fixed one, a
	 * customer with real sources would be shown someone else's platforms.
	 *
	 * @return void
	 */
	public function test_the_customizer_payload_still_gets_the_site_specific_specimen()
	{
		$this->assertStringContainsString(
			"\$data['trustBadges']['specimenHtml'] = self::specimen_html();",
			$this->builder_source(),
			'the customizer empty state must keep reading this site'
		);
	}

	/**
	 * Both menu pills are gated on the licence tier, not on the build constant.
	 *
	 * sbr_is_pro() is the BUILD constant, so a Pro build with a deactivated licence
	 * answered true and the Trust Badges pill vanished — which is how QA found this.
	 *
	 * @return void
	 */
	public function test_both_pro_plus_menu_pills_read_the_tier_and_say_plus()
	{
		$badges = $this->builder_source();

		$this->assertStringContainsString('! Util::sbr_is_pro_plus()', $badges);
		$this->assertStringContainsString('<span class="sb-men-pro">PRO+</span>', $badges);
		$this->assertStringNotContainsString('<span class="sb-men-pro">PRO</span>', $badges);

		$alerts_path = dirname(__DIR__, 3) . '/class/Common/ReviewAlerts/SBR_ReviewAlert_Builder.php';
		$this->assertFileIsReadable($alerts_path);
		$alerts = (string) file_get_contents($alerts_path);

		$this->assertStringContainsString('!Util::sbr_is_pro_plus()', $alerts);
		$this->assertStringContainsString('<span class="sb-men-pro">PRO+</span>', $alerts);
		$this->assertStringNotContainsString('<span class="sb-men-pro">PRO</span>', $alerts);
	}
}
