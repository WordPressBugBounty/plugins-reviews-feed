<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Create_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Describe_Settings_Schema;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Get_Pro_Features;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Update_Feed_Settings;
use SmashBalloon\Reviews\Common\Abilities\SBR_Abilities;
use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;
use SmashBalloon\Reviews\Common\Settings\Tabs\SBR_General_Tab;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Pro\Abilities\SBR_Pro_Abilities;

/**
 * SBR-577 / AgDR-9006 -- the Pro upsell surfaces of the free Abilities API:
 * the `get-pro-features` ability, the `pro_only` block of describe-settings-schema,
 * the ability descriptions, and the "Do more with AI on Pro" strip in the AI Agents card.
 *
 * Each test runs in its own process (SBR_PRO / WordPress stubs must not leak). A test
 * that means "free" leaves SBR_PRO undefined, which is the free zip's real condition.
 */
class Smash1994ProUpsellTest extends TestCase
{
	private static function stubs(): void
	{
		if (!function_exists('apply_filters')) {
			eval('function apply_filters($tag, $value) { return $value; }');
		}
		$GLOBALS['wp_options_mock'] = array();
	}

	private static function abilities_api_present(): void
	{
		if (!function_exists('wp_register_ability')) {
			eval('function wp_register_ability($n, $a = array()) { return true; }');
		}
		if (!function_exists('wp_register_ability_category')) {
			eval('function wp_register_ability_category($n, $a = array()) { return true; }');
		}
		if (!function_exists('get_plugins')) {
			eval('function get_plugins() { return array(); }');
		}
	}

	/** @return array<string, array> */
	private static function general_tab_sections(): array
	{
		$tab = new SBR_General_Tab();
		$ref = new \ReflectionMethod(SBR_General_Tab::class, 'tab_sections');
		$ref->setAccessible(true);
		return $ref->invoke($tab);
	}

	/** @return string[] */
	private static function registered_slugs(): array
	{
		$ref = new \ReflectionMethod(SBR_Abilities::class, 'abilities');
		$ref->setAccessible(true);
		return array_map(static fn ($a) => $a->slug(), $ref->invoke(new SBR_Abilities()));
	}

	/**
	 * Minimal JSON-Schema check, used ONLY to prove unknown-key and enum rejections
	 * and that known-valid inputs pass. It is deliberately NOT core's validator:
	 * it is stricter on types (integer/number/boolean/string/array/object are checked
	 * exactly) and it skips minimum/maximum/minLength/maxItems/required/pattern, so a
	 * `true` here means "not rejected for an unknown key or a disallowed enum value",
	 * never "valid".
	 *
	 * @param array $schema
	 * @param mixed $value
	 * @return bool True when the value is rejected.
	 */
	private static function rejects(array $schema, $value): bool
	{
		if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
			return true;
		}
		if (($schema['type'] ?? null) === 'object' && is_array($value)) {
			$props = $schema['properties'] ?? array();
			foreach ($value as $k => $v) {
				if (!isset($props[$k])) {
					if (($schema['additionalProperties'] ?? true) === false) {
						return true;
					}
					continue;
				}
				if (self::rejects($props[$k], $v)) {
					return true;
				}
			}
		}
		return false;
	}

	/** Pro-only keys and one Pro-only value, each paired with a plausible value. */
	private static function pro_inputs(): array
	{
		return array(
			array('carouselDesktopColumns' => 3),
			array('numPostTablet' => 4),
			array('masonryTabletColumns' => 2),
			array('showLoadButton' => true),
			array('includeWords' => array('great')),
			array('filterCharCountMin' => 10),
			array('moderationEnabled' => true),
			array('layout' => 'carousel'),
			array('localization' => 'wpml'),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_keys_and_values_are_rejected_by_the_free_input_schemas(): void
	{
		self::stubs();
		$update = (new SBR_Update_Feed_Settings())->args()['input_schema'];
		$create = (new SBR_Create_Feed())->args()['input_schema'];

		foreach (self::pro_inputs() as $settings) {
			$key = (string) array_key_first($settings);
			$this->assertTrue(self::rejects($update, array('feed_id' => 1, 'settings' => $settings)), "update-feed-settings must reject $key");
			$this->assertTrue(self::rejects($create, array('sources' => array('a'), 'settings' => $settings)), "create-feed must reject $key");
		}
		// Known-valid free inputs are not rejected (the validator is not just "reject everything").
		$this->assertFalse(self::rejects($update, array('feed_id' => 1, 'settings' => array('layout' => 'list', 'numPostDesktop' => 4))));
		$this->assertFalse(self::rejects($create, array('sources' => array('a'), 'settings' => array('layout' => 'masonry'))));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_settings_writing_descriptions_point_at_the_upsell_abilities_in_free_only(): void
	{
		self::stubs();
		foreach (array(new SBR_Update_Feed_Settings(), new SBR_Create_Feed()) as $ability) {
			$d = $ability->args()['description'];
			$this->assertStringContainsString('get-pro-features', $d);
			$this->assertStringContainsString('describe-settings-schema (pro_only)', $d);
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_descriptions_and_schema_carry_no_upsell(): void
	{
		self::stubs();
		define('SBR_PRO', true);
		foreach (array(new SBR_Update_Feed_Settings(), new SBR_Create_Feed()) as $ability) {
			$this->assertStringNotContainsString('get-pro-features', $ability->args()['description']);
		}
		$describe = new SBR_Describe_Settings_Schema();
		$this->assertArrayNotHasKey('pro_only', $describe->args()['output_schema']['properties']);
		$this->assertArrayNotHasKey('pro_only', $describe->execute());
		$this->assertSame(array(), SBR_Pro_Upsell::features());
		$this->assertSame(array(), SBR_Pro_Upsell::pro_only_settings());
		$this->assertSame(array(), SBR_Pro_Upsell::banner_feature_names());
		$this->assertSame('', SBR_Pro_Upsell::hint('Carousel', 'carousel-modal'));
		$this->assertSame('', SBR_Pro_Upsell::hint_for_setting('carouselDesktopColumns'));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_describe_settings_schema_reports_pro_only_settings_in_free(): void
	{
		self::stubs();
		$describe = new SBR_Describe_Settings_Schema();
		$this->assertArrayHasKey('pro_only', $describe->args()['output_schema']['properties']);

		$out = $describe->execute();
		$this->assertArrayHasKey('pro_only', $out);
		$settings = $out['pro_only']['settings'];
		foreach (array('carouselDesktopColumns', 'numPostTablet', 'showLoadButton', 'includeWords', 'moderationEnabled', 'feedTemplate', 'layout=carousel') as $expected) {
			$this->assertContains($expected, $settings);
		}
		$this->assertSame($settings, array_values(array_unique($settings)), 'no duplicates');

		$query = self::query($out['pro_only']['upgrade_url']);
		$this->assertSame('describe-settings-schema', $query['utm_medium']);
		$this->assertSame('abilities', $query['utm_source']);
	}

	/**
	 * Drift: every Pro-only setting the upsell surfaces name is real (in the FULL
	 * table) and really absent from what free exposes, and every tier tag points at
	 * an upsell-data id (the only exception is `wpml`, a Pro-only value with no modal).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_only_map_is_derived_from_the_schema_and_matches_the_free_schema(): void
	{
		self::stubs();
		$ref = new \ReflectionMethod(SBR_Settings_Schema::class, 'all_feed_settings');
		$ref->setAccessible(true);
		$full = $ref->invoke(null);
		$free = SBR_Settings_Schema::feed_settings();
		$by   = SBR_Settings_Schema::pro_only_by_tier();
		$this->assertNotEmpty($by);

		$upsell = Util::upsell_modal_content();
		foreach ($by as $tier => $settings) {
			$this->assertTrue(isset($upsell[$tier]) || $tier === 'wpml', "tier $tier is neither upsell data nor the wpml value tag");
			foreach ($settings as $setting) {
				if (strpos($setting, '=') === false) {
					$this->assertArrayHasKey($setting, $full, "$setting is a real key");
					$this->assertArrayNotHasKey($setting, $free, "$setting must be ABSENT from the free schema");
					continue;
				}
				list($key, $value) = explode('=', $setting, 2);
				$this->assertArrayHasKey($key, $free, "$key is exposed by free");
				$this->assertContains($value, $full[$key]['enum'], "$setting is a real Pro value");
				$this->assertNotContains($value, $free[$key]['enum'], "$setting must be ABSENT from the free write enum");
			}
		}
		// Completeness: every row carrying a tier tag shows up in the map.
		foreach ($full as $key => $def) {
			if (isset($def['tier'])) {
				$this->assertContains($key, $by[$def['tier']]);
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_banner_features_exist_in_the_upsell_data(): void
	{
		self::stubs();
		$upsell = Util::upsell_modal_content();
		$this->assertGreaterThanOrEqual(2, count(SBR_Pro_Upsell::BANNER_FEATURES));
		$this->assertLessThanOrEqual(3, count(SBR_Pro_Upsell::BANNER_FEATURES));
		foreach (SBR_Pro_Upsell::BANNER_FEATURES as $id) {
			$this->assertArrayHasKey($id, $upsell, "banner feature $id must be an upsell-data key");
		}
		$names = SBR_Pro_Upsell::banner_feature_names();
		$this->assertCount(count(SBR_Pro_Upsell::BANNER_FEATURES), $names);
		foreach ($names as $name) {
			$this->assertStringNotContainsString('Upgrade to Pro', $name, 'the lead-in is stripped');
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_pro_features_is_a_read_only_derived_ability_registered_only_in_free(): void
	{
		self::stubs();
		$ability = new SBR_Get_Pro_Features();
		$args    = $ability->args();
		$this->assertSame('reviews-feed/get-pro-features', $ability->name());
		$this->assertTrue($args['meta']['annotations']['readonly']);
		$this->assertFalse($args['meta']['annotations']['destructive']);
		$this->assertTrue($args['meta']['annotations']['idempotent']);
		$this->assertArrayNotHasKey('input_schema', $args);
		$this->assertSame(array($ability, 'can_manage'), $args['permission_callback'], 'same capability as the other reads');

		$out = $ability->execute();
		$this->assertSame('free', $out['edition']);
		$this->assertSame('get-pro-features', self::query($out['upgrade_url'])['utm_medium']);
		$this->assertNotEmpty($out['features']);
		foreach ($out['features'] as $feature) {
			$this->assertSame(array('name', 'description', 'settings'), array_keys($feature));
			$this->assertIsString($feature['name']);
			$this->assertNotSame('', $feature['name']);
			$this->assertIsArray($feature['settings']);
		}

		$names = array_column($out['features'], 'name');
		$this->assertContains('Get Carousel layout', $names);
		// Moderation is Pro-only (set-moderation is a Pro ability), so free lists it, with its keys.
		$moderation = array_values(array_filter($out['features'], static fn ($f) => stripos($f['name'], 'moderate') !== false));
		$this->assertCount(1, $moderation);
		$this->assertContains('moderationEnabled', $moderation[0]['settings']);

		$this->assertContains('get-pro-features', self::registered_slugs());
		$this->assertCount(12, self::registered_slugs());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_does_not_register_get_pro_features(): void
	{
		self::stubs();
		define('SBR_PRO', true);
		$slugs = self::registered_slugs();
		$this->assertNotContains('get-pro-features', $slugs);
		$all = (new SBR_Pro_Abilities())->add_pro_abilities(array());
		$this->assertSame(array('set-moderation'), array_map(static fn ($a) => $a->slug(), $all));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrade_url_follows_the_plugins_lite_upgrade_convention(): void
	{
		$url = SBR_Pro_Upsell::upgrade_url('carousel-modal');
		$this->assertStringStartsWith('https://smashballoon.com/reviews-feed/reviews-lite-upgrade/?', $url);
		$this->assertSame(
			array('utm_campaign' => 'reviews-free', 'utm_source' => 'abilities', 'utm_medium' => 'carousel-modal', 'utm_content' => 'Upgrade'),
			self::query($url)
		);
		$this->assertSame('settings', self::query(SBR_Pro_Upsell::upgrade_url('ai-agents-card', 'settings'))['utm_source']);
		$this->assertSame('a%26b', self::query_raw(SBR_Pro_Upsell::upgrade_url('a&b'))['utm_medium'], 'the medium is rawurlencoded');
		$this->assertSame('carousel-modal', SBR_Pro_Upsell::feature_medium('carouselModal'));
		$this->assertSame('wordpress.org-provider', SBR_Pro_Upsell::feature_medium('wordpress.orgProvider'));

		// Same URL shape the customizer's upsell modals carry (campaign + base), so a copy change cannot split them.
		$modal = Util::upsell_modal_content()['carouselModal']['buttons']['upgrade'];
		$this->assertStringStartsWith(SBR_Pro_Upsell::BASE_URL . '?utm_campaign=' . SBR_Pro_Upsell::UTM_CAMPAIGN . '&', $modal);
	}

	/**
	 * No plugin-side refusal in this plugin is caused by a Pro-only feature, so no
	 * hint is wired (AgDR-9006 rule 3): the feed cap applies in Pro too, a provider
	 * outside the edition is deliberately `source_not_found`, free has no template or
	 * collection input, and Pro-only keys are core-schema 400s that cannot carry one.
	 * The helper stays, tested, for a future plugin-side refusal.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hints_are_correct_and_deliberately_not_wired_into_any_ability(): void
	{
		self::stubs();
		$hint = SBR_Pro_Upsell::hint_for_setting('carouselDesktopColumns');
		$this->assertStringContainsString('Get Carousel layout is a Pro feature. Upgrade: https://smashballoon.com/', $hint);
		$this->assertSame('carousel-modal', self::query(substr($hint, strpos($hint, 'https://')))['utm_medium']);
		$this->assertNotSame('', SBR_Pro_Upsell::hint_for_setting('layout', 'carousel'));
		$this->assertSame('', SBR_Pro_Upsell::hint_for_setting('layout', 'list'), 'a free value gets no upsell');
		$this->assertSame('', SBR_Pro_Upsell::hint_for_setting('notARealKey'), 'a typo gets no upsell');

		$dir = __DIR__ . '/../../class/Common/Abilities';
		$it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->getExtension() !== 'php' || $file->getFilename() === 'SBR_Pro_Upsell.php') {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression(
				'/SBR_Pro_Upsell::hint(_for_setting)?\(/',
				(string) file_get_contents($file->getPathname()),
				$file->getFilename() . ' must not attach a hint: it has no Pro-caused plugin-side refusal'
			);
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ai_agents_card_gets_an_escaped_pro_strip_right_after_mcp_write_access_in_free(): void
	{
		self::stubs();
		self::abilities_api_present();
		$sections = self::general_tab_sections();
		$keys     = array_keys($sections);
		$at       = array_search('aiwriteenabled_section', $keys, true);
		$this->assertNotFalse($at);
		$this->assertSame('aiproupsell_section', $keys[$at + 1], 'directly after the MCP Write Access section');

		$html = $sections['aiproupsell_section']['info'];
		$this->assertStringContainsString('Do more with AI on Pro', $html);
		$this->assertStringContainsString(implode(' · ', SBR_Pro_Upsell::banner_feature_names()), $html);
		$this->assertStringContainsString('Upgrade to Pro', $html);
		$this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/smashballoon\.com\/reviews-feed\/reviews-lite-upgrade\/\?[^"]*utm_medium=ai-agents-card[^"]*"/', $html);
		$this->assertStringContainsString('utm_source=settings', $html);
		$this->assertStringContainsString('target="_blank"', $html);
		$this->assertStringContainsString('rel="noopener"', $html);
		$this->assertStringContainsString('<span class="screen-reader-text"> (opens in a new tab)</span>', $html);
		$this->assertStringNotContainsString('<script', $html);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_has_no_strip(): void
	{
		self::stubs();
		self::abilities_api_present();
		define('SBR_PRO', true);
		$sections = self::general_tab_sections();
		$this->assertArrayHasKey('aiwriteenabled_section', $sections);
		$this->assertArrayNotHasKey('aiproupsell_section', $sections);

		$ref = new \ReflectionMethod(SBR_General_Tab::class, 'ai_pro_upsell_html');
		$ref->setAccessible(true);
		$this->assertSame('', $ref->invoke(new SBR_General_Tab()));
	}

	/** @return array<string,string> Decoded query args. */
	private static function query(string $url): array
	{
		parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
		return $q;
	}

	/** @return array<string,string> Query args, NOT decoded. */
	private static function query_raw(string $url): array
	{
		$out = array();
		foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
			list($k, $v) = array_pad(explode('=', $pair, 2), 2, '');
			$out[$k] = $v;
		}
		return $out;
	}
}
