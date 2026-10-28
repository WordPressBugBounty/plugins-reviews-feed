<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Create_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_List_Sources;
use SmashBalloon\Reviews\Common\Abilities\SBR_Abilities;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Source_Service;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Builder;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Customizer\Tabs\SBR_Customize_Tab;
use SmashBalloon\Reviews\Common\Customizer\Tabs\SBR_Settings_Tab;
use SmashBalloon\Reviews\Common\ServiceContainer;
use SmashBalloon\Reviews\Common\Settings\Tabs\SBR_General_Tab;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Pro\Abilities\SBR_Pro_Abilities;

/**
 * SBR-577 / AgDR-9005 / AgDR-9006 -- the free edition registers the free
 * Abilities.
 *
 * Replaces the earlier "free has NO abilities" leak test (PR #536 Rex finding
 * B1): that bug is fixed by giving free real abilities behind the AI Agents
 * card, not by hiding the card. The bundled free build ships from this SAME
 * tree (build/reviews-feed/filter strips class/Pro, not class/Common), so
 * `SBR_PRO` being undefined is the free zip's real condition, and every test
 * here that means "free" leaves it undefined.
 *
 * Each test runs in its own process: SBR_PRO / WordPress stubs must not leak.
 */
class Smash1994FreeEditionAbilitiesTest extends TestCase
{
	/** The free per-feed key set, pinned (AgDR-9005 "free key-set snapshot"). Changing it is a deliberate decision. */
	const FREE_FEED_KEYS = array(
		'layout', 'verticalSpacing', 'horizontalSpacing', 'contentAlignment', 'contentLength',
		'numPostDesktop', 'masonryDesktopColumns', 'showHeader', 'headerHeadingContent',
		'headingColor', 'headerButtonLinkTo', 'headerButtonExternalLink', 'headerButtonColor',
		'headerButtonBg', 'postStyle', 'boxedBackgroundColor', 'ratingIconColor',
		'sortByDateEnabled', 'sortByDate', 'sortByRatingEnabled', 'sortByRating',
		'sortRandomEnabled', 'localization',
	);

	const FREE_GLOBAL_KEYS = array('optimize_images', 'enqueue_js_in_header', 'enableSchema', 'gdpr');

	const FREE_ABILITY_SLUGS = array(
		'get-global-settings', 'list-feeds', 'get-feed', 'describe-settings-schema',
		'update-global-settings', 'update-feed-settings', 'list-sources', 'create-feed',
		'set-feed-sources', 'set-header-button-link', 'request-feed-deletion',
		'get-pro-features',
	);

	private static function stubs(): void
	{
		if (!function_exists('apply_filters')) {
			eval('function apply_filters($tag, $value) { return $value; }');
		}
	}

	/** Defines the two core functions SBR_Abilities::is_available() looks for. */
	private static function abilities_api_present(): void
	{
		if (!function_exists('wp_register_ability')) {
			eval('function wp_register_ability($n, $a = array()) { return true; }');
		}
		if (!function_exists('wp_register_ability_category')) {
			eval('function wp_register_ability_category($n, $a = array()) { return true; }');
		}
	}

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
		$out = array();
		foreach ($ref->invoke(new SBR_Abilities()) as $ability) {
			$out[] = $ability->slug();
		}
		return $out;
	}

	/** Full curated table (private on purpose: every consumer goes through for_edition()). */
	private static function full_feed_table(): array
	{
		$ref = new \ReflectionMethod(SBR_Settings_Schema::class, 'all_feed_settings');
		$ref->setAccessible(true);
		return $ref->invoke(null);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ai_agents_card_and_write_toggle_render_in_free_when_abilities_exist(): void
	{
		self::stubs();
		self::abilities_api_present();
		if (!function_exists('get_plugins')) {
			eval('function get_plugins() { return array(); }');
		}
		$this->assertFalse(Util::sbr_is_pro(), 'Guard: this test means free only.');

		$sections = self::general_tab_sections();
		$this->assertArrayHasKey('aiagents_section', $sections, 'free registers abilities now, so the card must show');
		$this->assertArrayHasKey('aiwriteenabled_section', $sections, 'and so must the MCP Write Access toggle');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ai_agents_card_and_write_toggle_hidden_without_the_abilities_api(): void
	{
		self::stubs();
		$this->assertFalse(function_exists('wp_register_ability'), 'Guard: WordPress < 6.9 simulation.');

		$sections = self::general_tab_sections();
		$this->assertArrayNotHasKey('aiagents_section', $sections);
		$this->assertArrayNotHasKey('aiwriteenabled_section', $sections);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_registers_the_free_abilities_and_not_set_moderation(): void
	{
		self::stubs();
		$this->assertFalse(Util::sbr_is_pro());

		$slugs = self::registered_slugs();
		$this->assertEqualsCanonicalizing(self::FREE_ABILITY_SLUGS, $slugs);
		$this->assertCount(12, $slugs);
		$this->assertNotContains('set-moderation', $slugs);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_adds_set_moderation_through_the_sbr_abilities_filter(): void
	{
		self::stubs();
		define('SBR_PRO', true);

		$ref = new \ReflectionMethod(SBR_Abilities::class, 'abilities');
		$ref->setAccessible(true);
		$base = $ref->invoke(new SBR_Abilities());
		$this->assertCount(11, $base, 'the shared module alone is the free set');

		$all   = (new SBR_Pro_Abilities())->add_pro_abilities($base);
		$slugs = array_map(static fn ($a) => $a->slug(), $all);
		$this->assertCount(12, $all);
		$this->assertContains('set-moderation', $slugs);
		$this->assertSame(1, count(array_keys($slugs, 'set-moderation', true)), 'appended exactly once');
	}

	public function test_abilities_service_is_registered_once_in_the_common_container_and_not_in_pro(): void
	{
		$common = new \ReflectionClass(ServiceContainer::class);
		$prop   = $common->getProperty('services');
		$prop->setAccessible(true);
		$services = $prop->getValue($common->newInstanceWithoutConstructor());
		$this->assertSame(1, count(array_keys($services, SBR_Abilities::class, true)), 'Common registers SBR_Abilities exactly once');

		$pro_src = file_get_contents(__DIR__ . '/../../class/Pro/ServiceContainer.php');
		$this->assertDoesNotMatchRegularExpression(
			'/(?<![A-Za-z_])SBR_Abilities::class/',
			$pro_src,
			'Pro must not register SBR_Abilities again -- core fires _doing_it_wrong on a duplicate ability id'
		);
		$this->assertStringContainsString('SBR_Pro_Abilities::class', $pro_src);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_feed_and_global_key_sets_are_pinned(): void
	{
		$this->assertFalse(Util::sbr_is_pro());
		$this->assertEqualsCanonicalizing(self::FREE_FEED_KEYS, array_keys(SBR_Settings_Schema::feed_settings()));
		$this->assertEqualsCanonicalizing(self::FREE_FEED_KEYS, array_keys(SBR_Settings_Schema::feed_settings_read()));
		$this->assertEqualsCanonicalizing(self::FREE_GLOBAL_KEYS, array_keys(SBR_Settings_Schema::global_settings()));
		$this->assertArrayNotHasKey('usagetracking', SBR_Settings_Schema::global_settings());
		$this->assertArrayNotHasKey('ai_write_enabled', SBR_Settings_Schema::global_settings());
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_never_exposes_a_pro_only_key(): void
	{
		$free = SBR_Settings_Schema::feed_settings();
		foreach (array(
			'feedTemplate', 'numPostTablet', 'numPostMobile', 'masonryTabletColumns', 'masonryMobileColumns',
			'carouselDesktopColumns', 'carouselBreakpointDesktop', 'showLoadButton', 'loadButtonText',
			'includedStarFilters', 'includeWords', 'excludeWords', 'filterCharCountMin', 'filterCharCountMax',
			'moderationEnabled', 'moderationType', 'moderationAllowList', 'moderationBlockList',
		) as $pro_key) {
			$this->assertArrayNotHasKey($pro_key, $free, "$pro_key is Pro-only and must be ABSENT in free");
		}
		// ...and none of the inert tier metadata is ever emitted.
		foreach (array_merge($free, SBR_Settings_Schema::feed_settings_read()) as $key => $def) {
			$this->assertArrayNotHasKey('tier', $def, "$key leaks tier metadata");
			$this->assertArrayNotHasKey('tier_values', $def, "$key leaks tier_values metadata");
		}
		foreach (SBR_Settings_Schema::to_json_properties($free) as $key => $prop) {
			$this->assertArrayNotHasKey('tier', $prop, "$key leaks tier metadata into the JSON schema");
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_write_enum_excludes_pro_values_but_read_enum_keeps_them(): void
	{
		$write = SBR_Settings_Schema::feed_settings();
		$read  = SBR_Settings_Schema::feed_settings_read();

		$this->assertSame(array('list', 'masonry'), $write['layout']['enum']);
		$this->assertContains('carousel', $read['layout']['enum'], 'a downgraded feed must read back the stored layout');
		$this->assertNotContains('wpml', $write['localization']['enum']);
		$this->assertSame(10, $write['numPostDesktop']['maximum'], 'free caps desktop reviews at 10, like its control');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_downgraded_carousel_layout_reads_back_stored_with_a_warning(): void
	{
		$this->assertFalse(Util::sbr_is_pro());
		$def = SBR_Settings_Schema::feed_settings_read()['layout'];
		$this->assertSame('carousel', SBR_Settings_Schema::cast_out($def, 'carousel', 'list'));

		$found = SBR_Settings_Schema::pro_only_stored_values(array('layout' => 'carousel', 'numPostTablet' => 7));
		$this->assertSame(array('layout' => 'carousel'), $found, 'only a KEPT key with a Pro-only stored value is reported');
		$this->assertSame(array(), SBR_Settings_Schema::pro_only_stored_values(array('layout' => 'masonry')));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_schema_is_unchanged_by_the_edition_filter(): void
	{
		define('SBR_PRO', true);
		$full = self::full_feed_table();
		$this->assertCount(54, $full, 'Pro per-feed table size (the free port must not move it)');
		$this->assertSame($full, SBR_Settings_Schema::feed_settings());
		$this->assertSame($full, SBR_Settings_Schema::feed_settings_read());
		$this->assertSame($full, SBR_Settings_Schema::for_edition($full));
		$this->assertSame(array('list', 'carousel', 'masonry'), SBR_Settings_Schema::feed_settings()['layout']['enum']);
		$this->assertSame(1000, SBR_Settings_Schema::feed_settings()['numPostDesktop']['maximum']);
		$this->assertSame(array(), SBR_Settings_Schema::pro_only_stored_values(array('layout' => 'carousel')));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_free_write_or_read_enum_value_is_a_value_the_control_offers(): void
	{
		$offered = self::control_locks();
		$layout  = $offered['options']['layout'];
		$this->assertSame('carouselModal', $layout['carousel'], 'carousel is upsell-locked in the layout toggleset');
		$this->assertArrayNotHasKey('grid', $layout, 'grid is commented out of the toggleset');
		$this->assertNull($layout['list']);
		$this->assertNull($layout['masonry']);

		$free = array_keys(array_filter($layout, static fn ($lock) => $lock === null));
		$this->assertEqualsCanonicalizing($free, SBR_Settings_Schema::feed_settings()['layout']['enum']);
	}

	/**
	 * Drift: every `tier` tag on a row equals the `upsellModal` marker on the
	 * control that drives it, and every locked control with a row carries a tag
	 * (the "fails open" half AgDR-9005 asks a coherence test to close).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_tier_tags_match_the_customizers_upsell_markers(): void
	{
		$this->assertFalse(Util::sbr_is_pro());
		$locks = self::control_locks();
		$table = self::full_feed_table();

		foreach ($table as $key => $def) {
			if (!isset($def['tier'])) {
				continue;
			}
			if (strpos($key, 'carousel') === 0) {
				// Every carousel control is unreachable in free because the
				// `carousel` layout option itself is the locked thing.
				$this->assertSame($def['tier'], $locks['options']['layout']['carousel'], "$key tier != the carousel option's upsellModal");
				continue;
			}
			if ($key === 'feedTemplate') {
				// The template picker is not a tab control: its options come from
				// SBR_Feed_Builder::get_templates_list(), where every template but
				// `default` carries the upsellModal.
				$templates = (new \ReflectionClass(SBR_Feed_Builder::class))->newInstanceWithoutConstructor()->get_templates_list();
				$types     = array_column($templates, 'type');
				$locked    = array_column(array_filter($templates, static fn ($t) => isset($t['upsellModal'])), 'type');
				$this->assertSame('templateModal', $templates[1]['upsellModal']);
				$this->assertSame(array_values(array_diff($types, array('default'))), $locked, 'only `default` is free');
				$this->assertEqualsCanonicalizing($types, $def['enum'], 'feedTemplate enum equals the live templates');
				continue;
			}
			if (strpos($key, 'moderation') === 0) {
				// The free tree swaps the whole moderation section for upsell-locked
				// stand-ins (get_moderation_free_controls), so the section marker is
				// the signal that applies to every moderation key.
				$this->assertSame($def['tier'], $locks['sections']['moderation_section'], "$key tier != the moderation section's upsellModal");
				continue;
			}
			$this->assertArrayHasKey($key, $locks['controls'], "$key has a tier but no control in the customizer tree");
			$this->assertSame($def['tier'], $locks['controls'][$key], "$key tier drifted from its control's upsellModal");
		}

		foreach ($locks['controls'] as $id => $modal) {
			if ($modal !== null && isset($table[$id])) {
				$this->assertSame($modal, $table[$id]['tier'] ?? null, "$id is upsell-locked in the UI but its schema row has no matching tier (would leak into free)");
			}
		}

		$this->assertSame(array('carousel' => 'carouselModal'), $table['layout']['tier_values']);
	}

	/**
	 * Drift: bounds that a control declares equal the row's, and the rows
	 * with a free-edition bound carry the free value.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bounds_equal_the_controls_declared_bounds(): void
	{
		$this->assertFalse(Util::sbr_is_pro());
		$flat  = self::control_locks()['flat'];
		$table = self::full_feed_table();

		$checked = 0;
		foreach ($table as $key => $def) {
			if (!isset($flat[$key]) || !isset($flat[$key]['max'])) {
				continue;
			}
			if (isset($flat[$key]['min'])) {
				$this->assertSame($flat[$key]['min'], $def['minimum'] ?? null, "$key minimum drifted from its control");
			}
			$this->assertSame($flat[$key]['max'], $def['maximum'] ?? null, "$key maximum drifted from its control");
			$checked++;
		}
		$this->assertGreaterThan(5, $checked, 'the walk found the bounded controls');
		$this->assertSame(10, SBR_Settings_Schema::feed_settings()['numPostDesktop']['maximum']);
		$this->assertSame(10, $flat['numPostDesktop']['max'], 'free control cap');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_localization_enum_equals_the_languages_the_select_offers(): void
	{
		$this->assertFalse(Util::sbr_is_pro());
		$offered = array_map('strval', array_keys(Util::get_translation_languages(true)));
		$enum    = SBR_Settings_Schema::feed_settings()['localization']['enum'];
		$this->assertEqualsCanonicalizing($offered, $enum);

		$full = self::full_feed_table()['localization']['enum'];
		$this->assertSame(array_merge($offered, array('wpml')), $full, "the Pro-only 'wpml' option is the one extra literal");
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_source_providers_are_google_and_yelp_without_collections(): void
	{
		$GLOBALS['wp_options_mock'] = array();
		$this->assertFalse(Util::sbr_is_pro());
		$this->assertEqualsCanonicalizing(array('google', 'yelp'), SBR_Source_Service::allowed_providers());

		$provider = (new SBR_List_Sources())->args()['output_schema']['properties']['sources']['items']['properties']['provider'];
		$this->assertEqualsCanonicalizing(array('google', 'yelp'), $provider['enum']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_resolver_reports_pro_providers_and_collections_as_missing(): void
	{
		$GLOBALS['wp_options_mock'] = array();
		$GLOBALS['wpdb']            = new class {
			public $prefix = 'wp_';
			public function prepare($q, ...$args)
			{
				return vsprintf(str_replace('%s', "'%s'", $q), $args);
			}
			public function get_results($sql, $mode = null)
			{
				return array(
					array('account_id' => 'g1', 'provider' => 'google'),
					array('account_id' => 'fb1', 'provider' => 'facebook'),
					array('account_id' => 'c1', 'provider' => 'collection'),
				);
			}
		};
		if (!defined('SBR_SOURCES_TABLE')) {
			define('SBR_SOURCES_TABLE', 'sbr_sources');
		}

		$resolved = (new SBR_Source_Service())->resolve_allowed_sources(array('g1', 'fb1', 'c1', 'nope'));
		$this->assertSame(array('g1'), $resolved['ids']);
		$this->assertEqualsCanonicalizing(array('fb1', 'c1', 'nope'), $resolved['missing']);
		$this->assertSame(array(), $resolved['ignored'], 'never feature_unavailable in free: those rows were never listed');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_free_create_feed_has_no_template_input_and_requires_sources(): void
	{
		$args       = (new SBR_Create_Feed())->args();
		$properties = $args['input_schema']['properties']['settings']['properties'];
		$this->assertArrayNotHasKey('feedTemplate', $properties);
		$this->assertArrayHasKey('layout', $properties);
		$this->assertContains('sources', $args['input_schema']['required']);
		$this->assertSame(array('list', 'masonry'), $properties['layout']['enum']);
	}

	public function test_update_feed_settings_merges_onto_the_raw_row_so_stored_pro_keys_survive(): void
	{
		// A downgraded feed still stores Pro-only keys (carousel columns, filters).
		// A free write must merge onto the RAW stored row, never rebuild it from
		// the free schema, or a later re-upgrade finds them gone (AgDR-9005).
		$src = file_get_contents(__DIR__ . '/../../class/Common/Abilities/Services/SBR_Feed_Settings_Service.php');
		$this->assertMatchesRegularExpression(
			'/\$settings\s*=\s*\$this->decode_settings_column\(\$row\[\'settings\'\]\);\s*\$settings\s*=\s*array_merge\(\$settings,\s*\$partial\);/',
			$src
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_provider_allowlist_where_clause_is_parenthesised_and_prepared(): void
	{
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public function prepare($q, ...$args)
			{
				return vsprintf(str_replace('%s', "'%s'", $q), $args);
			}
		};
		$db  = (new \ReflectionClass(DB::class))->newInstanceWithoutConstructor();
		$ref = new \ReflectionMethod(DB::class, 'providers_where_clause');
		$ref->setAccessible(true);

		$this->assertSame(" WHERE provider IN ('google','yelp') ", str_replace(', ', ',', $ref->invoke($db, array('google', 'yelp'), 'provider', '')));
		$this->assertSame(' WHERE 1 = 0 ', $ref->invoke($db, array(), 'provider', ''), 'an empty allowlist matches nothing');
		$with_search = $ref->invoke($db, array('google'), 's.provider', " WHERE s.name LIKE 'x' OR s.provider LIKE 'x' ");
		$this->assertSame(" WHERE (s.name LIKE 'x' OR s.provider LIKE 'x' ) AND (s.provider IN ('google')) ", $with_search);
	}

	/**
	 * Flattens SBR_Customize_Tab + SBR_Settings_Tab into effective locks.
	 * A control is locked when it, an enclosing group, or its section carries
	 * an `upsellModal` (the rule SbUtils.checkSettingIsPro applies when the
	 * site is not Pro). Test-only: nothing walks the tree at request time.
	 *
	 * @return array{controls: array<string, string|null>, sections: array<string, string|null>, options: array<string, array<string, string|null>>, flat: array<string, array>}
	 */
	private static function control_locks(): array
	{
		$out = array('controls' => array(), 'options' => array(), 'flat' => array(), 'sections' => array());
		foreach (array(SBR_Customize_Tab::class, SBR_Settings_Tab::class) as $class) {
			$ref = new \ReflectionMethod($class, 'tab_sections');
			$ref->setAccessible(true);
			foreach ($ref->invoke((new \ReflectionClass($class))->newInstanceWithoutConstructor()) as $name => $section) {
				$out['sections'][$name] = $section['upsellModal'] ?? null;
				self::walk($section['controls'] ?? array(), $section['upsellModal'] ?? null, $out);
			}
		}
		return $out;
	}

	private static function walk(array $nodes, $inherited, array &$out): void
	{
		foreach ($nodes as $node) {
			if (!is_array($node)) {
				continue;
			}
			$lock = $node['upsellModal'] ?? $inherited;
			if (isset($node['id']) && is_string($node['id'])) {
				$out['controls'][$node['id']] = $lock;
				$out['flat'][$node['id']]     = $node;
				if (isset($node['options']) && is_array($node['options'])) {
					foreach ($node['options'] as $option) {
						if (is_array($option) && isset($option['value'])) {
							$out['options'][$node['id']][(string) $option['value']] = $option['upsellModal'] ?? $lock;
						}
					}
				}
			}
			// checkboxsection controls drive their setting through `settingId`.
			if (isset($node['settingId']) && is_string($node['settingId'])) {
				$out['controls'][$node['settingId']] = $lock;
			}
			if (isset($node['controls']) && is_array($node['controls'])) {
				self::walk($node['controls'], $lock, $out);
			}
		}
	}
}
