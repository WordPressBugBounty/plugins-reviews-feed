<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Customizer\SBR_Feed_Style_Generator;

/**
 * SMASH-2598: a feed created without the Feed Builder gets the same feed_style
 * a Builder save would store.
 *
 * The expected strings in fixtures/smash-2598-builder-styles.json were produced
 * by the Builder's own functions (SbUtils.getFeedStyling() and the helpers it
 * calls, sb-customizer src/Utils/SbUtils.js), run in Node on the same tabs and
 * settings. Regenerate them from that file if the Builder's generator changes.
 */
final class Smash2598FeedStyleGeneratorTest extends TestCase
{
	/** @var array<string, mixed>|null */
	private static $fixture = null;

	/** @return array<string, mixed> */
	private static function fixture(): array
	{
		if (self::$fixture === null) {
			self::$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/smash-2598-builder-styles.json'), true);
		}
		return self::$fixture;
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function cases(): array
	{
		$out = array();
		foreach (array('synthetic', 'real') as $set) {
			foreach (self::fixture()[$set . '_cases'] as $i => $case) {
				$out[$case['name']] = array($set, $i);
			}
		}
		return $out;
	}

	/**
	 * @dataProvider cases
	 */
	public function test_php_output_is_the_builders_output_byte_for_byte(string $set, int $index): void
	{
		$fixture = self::fixture();
		$case    = $fixture[$set . '_cases'][$index];
		$tabs    = $fixture[$set . '_tabs'];

		$css = SBR_Feed_Style_Generator::styles_from_tabs(
			$tabs,
			$case['settings'],
			$case['feed_id'],
			$case['is_pro'],
			static function () use ($case) {
				return $case['providers'];
			}
		);

		$this->assertSame($case['js'], $css);
	}

	public function test_the_fixture_exercises_every_branch_it_claims(): void
	{
		$free = self::fixture()['synthetic_cases'][0]['js'];
		$pro  = self::fixture()['synthetic_cases'][1]['js'];

		// conditionSources: only the feed with a Trustpilot source.
		$this->assertStringContainsString('.s{', $free);
		$this->assertStringNotContainsString('.s{', $pro);
		// hidePro: Pro only.
		$this->assertStringNotContainsString('.r{', $free);
		$this->assertStringContainsString('.r{', $pro);
		// A missing distance still prints an empty declaration, as the Builder does.
		$this->assertStringContainsString('.d{padding:;}', $free);
		// A disabled box shadow prints nothing.
		$this->assertStringNotContainsString('.h{', $free);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ensure_stored_writes_only_an_empty_feed_style_and_sanitises_it(): void
	{
		self::use_tabs(self::fixture()['synthetic_tabs']);
		$wpdb = self::wpdb();

		$settings = SBR_Feed_Style_Generator::ensure_stored(7, array('color' => '#ff0000<script>', 'layout' => 'grid'));

		$this->assertCount(1, $wpdb->updates);
		$this->assertSame(array('id' => 7, 'feed_style' => ''), $wpdb->updates[0]['where'], 'a Builder save landing in between must win');
		$this->assertStringContainsString('#sb-reviews-container-7 .a{color:#ff0000;}', $settings['feed_style']);
		$this->assertStringNotContainsString('<script>', $wpdb->updates[0]['data']['feed_style']);
		$this->assertSame($settings['feed_style'], $wpdb->updates[0]['data']['feed_style']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ensure_stored_never_touches_a_builder_saved_feed_style(): void
	{
		self::use_tabs(self::fixture()['synthetic_tabs']);
		$wpdb = self::wpdb();

		$settings = array('color' => '#ff0000', 'feed_style' => '#sb-reviews-container-7 .x{color:blue}');
		$this->assertSame($settings, SBR_Feed_Style_Generator::ensure_stored(7, $settings));
		$this->assertSame(array(), SBR_Feed_Style_Generator::ensure_stored(0, array()));
		$this->assertCount(0, $wpdb->updates);
	}

	/**
	 * Feed_Builder::customizer_builder_data() loads the tab files with a plain
	 * `require`; autoloading one first would declare the class twice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_nothing_is_loaded_on_the_feed_builder_page(): void
	{
		if (!function_exists('is_admin')) {
			eval('function is_admin() { return true; }');
		}
		// Plugin bootstrap.php:58-64 and SBR_CUSTOMIZER_MENU_SLUG (bootstrap.php:123).
		defined('SBR_CUSTOMIZER_TABS_PATH') || define('SBR_CUSTOMIZER_TABS_PATH', dirname(__DIR__, 2) . '/class/Common/Customizer/Tabs/');
		defined('SBR_CUSTOMIZER_TABS_NAMESPACE') || define('SBR_CUSTOMIZER_TABS_NAMESPACE', 'SmashBalloon\Reviews\Common\Customizer\Tabs\\');
		defined('SBR_CUSTOMIZER_MENU_SLUG') || define('SBR_CUSTOMIZER_MENU_SLUG', 'sbr');
		$_GET['page'] = SBR_CUSTOMIZER_MENU_SLUG;
		$tab_class    = SBR_CUSTOMIZER_TABS_NAMESPACE . 'SBR_Customize_Tab';
		self::wpdb();

		$this->assertFalse(class_exists($tab_class, false));
		$this->assertSame('', SBR_Feed_Style_Generator::generate(array(), 5));
		$this->assertFalse(class_exists($tab_class, false), 'the generator autoloaded a tab class on the Builder page');
	}

	/**
	 * Call site 1: update-feed-settings stores what a Builder save would.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_update_feed_settings_regenerates_the_css_a_builder_save_would_store(): void
	{
		self::use_tabs(self::fixture()['synthetic_tabs']);
		$wpdb = self::wpdb(self::row(12, '#sb-reviews-container-12 .a{color:#000000;}', array('color' => '#000000', 'layout' => 'grid')));

		$result = (new \SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service())->update_feed_settings(12, array('color' => '#ff0000'));

		$this->assertSame(array('updated' => array('color')), $result);
		$saved = self::feed_update($wpdb);
		$this->assertStringContainsString('#sb-reviews-container-12 .a{color:#ff0000;}', $saved['feed_style']);
		$this->assertStringNotContainsString('#000000', $saved['feed_style'], 'the stale Builder CSS must be replaced, as a Builder save replaces it');
		$this->assertSame('Probe', $saved['feed_name']);
	}

	/**
	 * Call site 1, no tabs (the generator returns ''): the stored CSS is kept, never blanked.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_update_feed_settings_keeps_the_stored_css_when_nothing_was_generated(): void
	{
		self::use_tabs(array());
		$wpdb = self::wpdb(self::row(12, '#sb-reviews-container-12 .a{color:#000000;}', array('color' => '#000000')));

		(new \SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service())->update_feed_settings(12, array('color' => '#ff0000'));

		$this->assertSame('#sb-reviews-container-12 .a{color:#000000;}', self::feed_update($wpdb)['feed_style']);
	}

	/**
	 * Call site 2: the render path. An existing caller gets the same array shape;
	 * an empty feed_style is filled once, a stored one is left alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_settings_by_feed_id_fills_only_an_empty_feed_style(): void
	{
		self::use_tabs(self::fixture()['synthetic_tabs']);

		$wpdb    = self::wpdb(self::row(31, '', array('color' => '#ff0000', 'layout' => 'grid')));
		$filled  = \SmashBalloon\Reviews\Common\SBR_Settings::get_settings_by_feed_id(31);
		$this->assertIsArray($filled);
		$this->assertSame('#ff0000', $filled['color']);
		$this->assertSame('Probe', $filled['feed_name']);
		$this->assertStringContainsString('#sb-reviews-container-31 .a{color:#ff0000;}', $filled['feed_style']);
		$this->assertCount(1, $wpdb->updates);
		$this->assertSame(array('id' => 31, 'feed_style' => ''), $wpdb->updates[0]['where']);

		$stored = '#sb-reviews-container-31 .x{color:blue}';
		$wpdb   = self::wpdb(self::row(31, $stored, array('color' => '#ff0000')));
		$kept   = \SmashBalloon\Reviews\Common\SBR_Settings::get_settings_by_feed_id(31);
		$this->assertSame($stored, $kept['feed_style']);
		$this->assertCount(0, $wpdb->updates, 'a Builder-saved feed_style is never rewritten on read');

		$this->assertFalse(\SmashBalloon\Reviews\Common\SBR_Settings::get_settings_by_feed_id(0));
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function row(int $id, string $feed_style, array $settings): array
	{
		return array(
			'id'            => (string) $id,
			'feed_name'     => 'Probe',
			'feed_title'    => '',
			'feed_style'    => $feed_style,
			'settings'      => (string) json_encode($settings),
			'status'        => 'publish',
			'last_modified' => '2026-11-02 00:00:00',
		);
	}

	/**
	 * The one sbr_feeds UPDATE a save makes (feeds_update()).
	 *
	 * @return array<string, mixed>
	 */
	private static function feed_update(object $wpdb): array
	{
		$saves = array_values(array_filter($wpdb->updates, static function ($u) {
			return isset($u['data']['settings']);
		}));
		self::assertCount(1, $saves);
		return $saves[0]['data'];
	}

	/**
	 * @param array<int, array<string, mixed>> $tabs
	 */
	private static function use_tabs(array $tabs): void
	{
		$property = new \ReflectionProperty(SBR_Feed_Style_Generator::class, 'tabs');
		$property->setAccessible(true);
		$property->setValue(null, $tabs);
	}

	/**
	 * A one-row sbr_feeds table: enough for DB::feeds_query() and feeds_update().
	 *
	 * @param array<string, mixed>|null $row
	 * @return object
	 */
	private static function wpdb(?array $row = null)
	{
		defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
		$GLOBALS['wpdb'] = new class ($row) {
			/** @var string */
			public $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public $updates = array();
			/** @var array<string, mixed>|null */
			private $row;

			/** @param array<string, mixed>|null $row */
			public function __construct($row)
			{
				$this->row = $row;
			}

			/**
			 * @param string $table
			 * @param array  $data
			 * @param array  $where
			 * @return int
			 */
			public function update($table, $data, $where)
			{
				$this->updates[] = array('table' => $table, 'data' => $data, 'where' => $where);
				return 1;
			}

			/** @return string */
			public function prepare($query, ...$args)
			{
				return (string) $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results($query = null, $output = null)
			{
				return $this->row === null ? array() : array($this->row);
			}

			/** @return mixed */
			public function __call($name, $args)
			{
				return 0;
			}
		};
		return $GLOBALS['wpdb'];
	}
}
