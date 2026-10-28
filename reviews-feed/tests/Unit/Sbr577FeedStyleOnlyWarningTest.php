<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Create_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Describe_Settings_Schema;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Get_Pro_Features;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Update_Feed_Settings;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

/**
 * SBR-577 (QA 2.2 B and section 3) -- update-feed-settings must not report a
 * silent success for styling the live page cannot show, and the free copy must
 * not promise more than free does.
 *
 * Colours and vertical spacing render from the pre-rendered `feed_style` CSS,
 * which only the Feed Builder generates (in the browser, posted on save). PHP has
 * no generator, so a write through the ability is saved but inert until the feed
 * is re-saved in the builder.
 */
final class Sbr577FeedStyleOnlyWarningTest extends TestCase
{
	private static function stubs(): void
	{
		if (!function_exists('apply_filters')) {
			eval('function apply_filters($tag, $value) { return $value; }');
		}
		$GLOBALS['wp_options_mock'] = array();
	}

	/**
	 * Keys of feed_settings() whose SBR_Customize_Tab control declares a `style`
	 * template, read from the source so a new styled control fails this test.
	 *
	 * @return string[]
	 */
	private static function styled_control_ids(): array
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/class/Common/Customizer/Tabs/SBR_Customize_Tab.php');
		$ids = array();
		$id  = null;
		foreach (preg_split('/\R/', $src) as $line) {
			if (preg_match("/'id'\s*=>\s*'([^']+)'/", $line, $m)) {
				$id = $m[1];
			}
			if ($id !== null && preg_match("/'style'\s*=>/", $line)) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * The full curated table, Pro keys included (private on purpose: consumers
	 * go through for_edition()). The list covers both editions, e.g. the Load
	 * More colours exist in Pro only.
	 *
	 * @return string[]
	 */
	private static function all_feed_keys(): array
	{
		$ref = new \ReflectionMethod(SBR_Settings_Schema::class, 'all_feed_settings');
		$ref->setAccessible(true);
		return array_keys($ref->invoke(null));
	}

	public function test_the_key_list_is_exactly_the_styled_controls_among_the_feed_settings(): void
	{
		self::stubs();
		$schema_keys = self::all_feed_keys();
		$expected    = array_values(array_intersect(self::styled_control_ids(), $schema_keys));

		$actual = SBR_Settings_Schema::FEED_STYLE_ONLY_KEYS;
		sort($expected);
		sort($actual);
		$this->assertSame($expected, $actual);
	}

	public function test_every_listed_key_is_a_real_feed_setting(): void
	{
		self::stubs();
		$all = self::all_feed_keys();
		foreach (SBR_Settings_Schema::FEED_STYLE_ONLY_KEYS as $key) {
			$this->assertContains($key, $all, $key);
		}
	}

	public function test_a_styling_key_adds_one_warning_naming_it_and_the_builder_url(): void
	{
		$warnings = SBR_Update_Feed_Settings::feed_style_warnings(7, array('layout', 'ratingIconColor', 'headingColor'));

		$this->assertCount(1, $warnings);
		$this->assertStringContainsString('ratingIconColor, headingColor', $warnings[0]);
		$this->assertStringNotContainsString('layout', $warnings[0]);
		$this->assertStringContainsString('Feed Builder', $warnings[0]);
		$this->assertStringContainsString('Save', $warnings[0]);
		$this->assertStringContainsString('admin.php?page=sbr&feed_id=7', $warnings[0]);
	}

	/**
	 * @dataProvider styling_keys
	 */
	public function test_each_listed_key_triggers_the_warning(string $key): void
	{
		$this->assertCount(1, SBR_Update_Feed_Settings::feed_style_warnings(3, array($key)));
	}

	/** @return array<string, array{0: string}> */
	public static function styling_keys(): array
	{
		$out = array();
		foreach (SBR_Settings_Schema::FEED_STYLE_ONLY_KEYS as $key) {
			$out[$key] = array($key);
		}
		return $out;
	}

	public function test_keys_that_render_without_the_builder_add_no_warning(): void
	{
		$this->assertSame(
			array(),
			SBR_Update_Feed_Settings::feed_style_warnings(7, array('layout', 'numPostDesktop', 'showHeader', 'sortByDate'))
		);
		$this->assertSame(array(), SBR_Update_Feed_Settings::feed_style_warnings(7, array()));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_description_promises_no_filtering_and_flags_the_builder_step(): void
	{
		self::stubs();
		$d = (new SBR_Update_Feed_Settings())->args()['description'];

		$this->assertStringNotContainsString('filtering', $d);
		$this->assertStringContainsString('sorting', $d);
		$this->assertStringContainsString('Feed Builder', $d);
		$this->assertStringContainsString('ratingIconColor', $d);
		$this->assertStringContainsString('get-pro-features', $d, 'the free upsell pointer must survive');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_description_keeps_its_text_and_still_flags_the_builder_step(): void
	{
		self::stubs();
		define('SBR_PRO', true);
		$d = (new SBR_Update_Feed_Settings())->args()['description'];

		$this->assertStringContainsString('(layout, columns, header, sorting, filtering)', $d);
		$this->assertStringContainsString('Feed Builder', $d);
		$this->assertStringNotContainsString('get-pro-features', $d);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_surfaces_state_the_review_cap(): void
	{
		self::stubs();
		$features = (new SBR_Get_Pro_Features())->execute();
		$this->assertCount(1, $features['free_limits']);
		$this->assertStringContainsString('10', $features['free_limits'][0]);
		$this->assertArrayHasKey('free_limits', (new SBR_Get_Pro_Features())->args()['output_schema']['properties']);

		$describe = new SBR_Describe_Settings_Schema();
		$this->assertSame($features['free_limits'], $describe->execute()['pro_only']['free_limits']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_reports_no_free_limits(): void
	{
		self::stubs();
		define('SBR_PRO', true);
		$this->assertSame(array(), \SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell::free_limits());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_feed_description_flags_the_builder_step(): void
	{
		self::stubs();
		$d = (new SBR_Create_Feed())->args()['description'];

		$this->assertStringContainsString('Feed Builder', $d);
		$this->assertStringContainsString('ratingIconColor', $d);
	}

	public function test_create_feed_warns_only_for_keys_the_agent_requested(): void
	{
		// execute() passes the validated REQUEST keys (taken before the template
		// adds its own colours), so a template's own styling never warns.
		$src = file_get_contents(dirname(__DIR__, 2) . '/class/Common/Abilities/Abilities/SBR_Create_Feed.php');
		$taken    = strpos($src, '$requested_keys = array_keys($clean);');
		$template = strpos($src, 'apply_template_with_warning($clean)');
		$this->assertNotFalse($taken);
		$this->assertLessThan($template, $taken, 'request keys must be captured before the template runs');
		$this->assertStringContainsString('feed_style_warnings(', $src);
		$this->assertStringContainsString('$requested_keys', substr($src, strpos($src, 'feed_style_warnings(')));

		$w = SBR_Update_Feed_Settings::feed_style_warnings(42, array('headingColor'));
		$this->assertStringContainsString('feed_id=42', $w[0]);
	}
}
