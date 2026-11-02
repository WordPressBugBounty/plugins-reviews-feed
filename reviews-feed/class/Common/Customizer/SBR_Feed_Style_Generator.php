<?php

/**
 * Server-side twin of the Feed Builder's feed_style generator.
 *
 * @package SmashBalloon\Reviews\Common\Customizer
 */

namespace SmashBalloon\Reviews\Common\Customizer;

use Smashballoon\Customizer\V2\SB_Sidebar_Tab;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * Builds a feed's `feed_style` CSS in PHP, the way the Feed Builder builds it in
 * the browser (SbUtils.getFeedStyling(), sb-customizer src/Utils/SbUtils.js:329).
 *
 * The front end prints the stored string verbatim (FeedDisplay::custom_styles()),
 * so a feed that never went through a Builder save (AI agent, WP-CLI, seeding
 * script) rendered unstyled (SMASH-2598). The control definitions are read from
 * the same tab classes the Builder receives, so the templates live in one place.
 *
 * @since 2.16.1
 */
class SBR_Feed_Style_Generator
{
	/**
	 * Tab definitions for this request, once loaded.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static $tabs = null;

	/**
	 * Generate, sanitise and store the CSS of a feed whose `feed_style` is empty.
	 *
	 * A non-empty value is never touched: the Builder's own output stays the
	 * source of truth. The UPDATE matches `feed_style = ''` too, so a Builder
	 * save landing in between wins.
	 *
	 * @param int|string $feed_id
	 * @param array      $settings Feed settings, defaults merged (Feed_Saver::get_feed_settings()).
	 *
	 * @return array The same settings, with `feed_style` filled when it was generated.
	 */
	public static function ensure_stored($feed_id, array $settings)
	{
		if (absint($feed_id) < 1 || !empty($settings['feed_style'])) {
			return $settings;
		}

		$css = self::generate($settings, $feed_id);
		if ($css === '') {
			return $settings;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . SBR_FEEDS_TABLE,
			array('feed_style' => $css),
			array(
				'id'         => absint($feed_id),
				'feed_style' => '',
			),
			array('%s'),
			array('%d', '%s')
		);

		$settings['feed_style'] = $css;
		return $settings;
	}

	/**
	 * The feed_style a Builder save of these settings would store.
	 *
	 * @param array      $settings Feed settings.
	 * @param int|string $feed_id
	 *
	 * @return string Sanitised CSS, as SBR_Feed_Saver stores it; '' when the tabs cannot be loaded.
	 */
	public static function generate(array $settings, $feed_id)
	{
		$tabs = self::get_tabs();
		if (empty($tabs)) {
			return '';
		}

		$settings = wp_parse_args($settings, sbr_settings_defaults());
		$css      = self::styles_from_tabs($tabs, $settings, (string) absint($feed_id), Util::sbr_is_pro(), array(__CLASS__, 'source_providers'));

		// SBR_Feed_Saver.php:314 stores the Builder's string through the same sanitiser.
		return sanitize_text_field($css);
	}

	/**
	 * SbUtils.getFeedStyling(): every section of every tab, recursively.
	 *
	 * @param array    $tabs             Tab definitions (SB_Sidebar_Tab::get_tab()).
	 * @param array    $settings         Feed settings.
	 * @param string   $feed_id
	 * @param bool     $is_pro
	 * @param callable $source_providers Returns the providers of the feed's sources, for `conditionSources`.
	 *
	 * @return string Unsanitised CSS, byte for byte what the browser builds.
	 */
	public static function styles_from_tabs(array $tabs, array $settings, $feed_id, $is_pro, callable $source_providers)
	{
		$css = '';
		foreach ($tabs as $tab) {
			if (empty($tab['sections']) || !is_array($tab['sections'])) {
				continue;
			}
			foreach ($tab['sections'] as $section) {
				$css .= self::styles_recursive($section, $settings, $feed_id, $is_pro, $source_providers);
			}
		}
		return $css;
	}

	/**
	 * SbUtils.getFeedStylingRecursive(). A child is styled whatever its parent's condition.
	 *
	 * @param mixed    $element
	 * @param array    $settings
	 * @param string   $feed_id
	 * @param bool     $is_pro
	 * @param callable $source_providers
	 *
	 * @return string
	 */
	private static function styles_recursive($element, array $settings, $feed_id, $is_pro, callable $source_providers)
	{
		if (!is_array($element) || empty($element['controls']) || !is_array($element['controls'])) {
			return '';
		}

		$css = '';
		foreach ($element['controls'] as $control) {
			if (!is_array($control)) {
				continue;
			}
			$css .= self::control_style($control, $settings, $feed_id, $is_pro, $source_providers);
			if (isset($control['controls'])) {
				$css .= self::styles_recursive($control, $settings, $feed_id, $is_pro, $source_providers);
			}
		}
		return $css;
	}

	/**
	 * SbUtils.getSingleControlStyle().
	 *
	 * @param array    $control
	 * @param array    $settings
	 * @param string   $feed_id
	 * @param bool     $is_pro
	 * @param callable $source_providers
	 *
	 * @return string
	 */
	private static function control_style(array $control, array $settings, $feed_id, $is_pro, callable $source_providers)
	{
		if (!isset($control['style']) || !is_array($control['style'])) {
			return '';
		}
		if (self::condition_applies($control, $settings, $is_pro, $source_providers) === false) {
			return '';
		}

		$id        = isset($control['id']) && (is_string($control['id']) || is_int($control['id'])) ? $control['id'] : null;
		$has_value = $id !== null && array_key_exists($id, $settings);
		$value     = self::css_value(isset($control['type']) ? (string) $control['type'] : '', $has_value ? ($settings[$id] ?? null) : null, $has_value);
		if ($value === null) {
			return '';
		}

		$css       = '';
		$container = '#sb-reviews-container-' . $feed_id;
		foreach ($control['style'] as $selector => $template) {
			$css .= $container . ' ' . $selector . '{' . self::js_replace_first((string) $template, '{{value}}', $value) . '}';
		}
		return $css;
	}

	/**
	 * SbUtils.checkControlCondition(). Returns true, false or 'dimmed' (which still styles).
	 *
	 * @param array    $control
	 * @param array    $settings
	 * @param bool     $is_pro
	 * @param callable $source_providers
	 *
	 * @return bool|string
	 */
	private static function condition_applies(array $control, array $settings, $is_pro, callable $source_providers)
	{
		if (!empty($control['conditionSources']) && is_array($control['conditionSources'])) {
			$providers = (array) call_user_func($source_providers, $settings);
			return count(array_intersect($providers, $control['conditionSources'])) > 0;
		}

		if (isset($control['hidePro']) && $control['hidePro'] === true && $is_pro !== true) {
			return false;
		}

		if (!isset($control['condition']) || !is_array($control['condition'])) {
			return true;
		}

		$met = 0;
		foreach ($control['condition'] as $key => $allowed) {
			$has = array_key_exists($key, $settings);
			if (self::js_index_of($allowed, $has ? ($settings[$key] ?? null) : null, $has) !== -1) {
				++$met;
			}
		}

		$show = $met === count($control['condition']);
		return $show === false && isset($control['conditionDimmed']) && $control['conditionDimmed'] === true ? 'dimmed' : $show;
	}

	/**
	 * SbUtils.createCssStyle(), already coerced to the string a template literal makes.
	 *
	 * @param string $type
	 * @param mixed  $value
	 * @param bool   $has_value False mirrors JavaScript's `undefined`.
	 *
	 * @return string|null Null when the browser skips the rule.
	 */
	private static function css_value($type, $value, $has_value)
	{
		switch ($type) {
			case 'boxshadow':
				if (!self::is_enabled($value)) {
					return null;
				}
				return self::add_css_unit(self::field($value, 'x')) . ' ' . self::add_css_unit(self::field($value, 'y')) . ' '
					. self::add_css_unit(self::field($value, 'blur')) . ' ' . self::add_css_unit(self::field($value, 'spread')) . ' '
					. self::js_string_field($value, 'color');

			case 'borderradius':
				if (!self::is_enabled($value)) {
					return null;
				}
				return self::js_string_field($value, 'radius') . 'px';

			case 'stroke':
				if (!self::is_enabled($value)) {
					return null;
				}
				return self::js_string_field($value, 'thickness') . 'px solid ' . self::js_string_field($value, 'color');

			case 'distance':
				// `{}` is truthy in JavaScript, so only a non-object returns ''.
				if (!is_array($value)) {
					return '';
				}
				$distances = '';
				foreach (array('top', 'right', 'bottom', 'left') as $side) {
					$distances .= self::not_empty(self::field($value, $side)) ? self::js_string_field($value, $side) . 'px ' : '0px ';
				}
				return $distances;

			case 'font':
				if (!is_array($value)) {
					return '';
				}
				$fonts = '';
				foreach (array('family', 'weight', 'size', 'height') as $part) {
					$part_value = self::field($value, $part);
					if (self::not_empty($part_value) && $part_value !== 'inherit') {
						$fonts .= ($part === 'height' ? 'line' : 'font') . '-' . $part . ':' . self::js_string($part_value) . ($part === 'size' ? 'px' : '') . ';';
					}
				}
				return $fonts;

			default:
				if (!$has_value || $value === null) {
					return null;
				}
				return self::js_string($value);
		}
	}

	/**
	 * `value?.enabled === undefined || value?.enabled === false` means off.
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	private static function is_enabled($value)
	{
		return is_array($value) && array_key_exists('enabled', $value) && $value['enabled'] !== false;
	}

	/**
	 * SbUtils.addCssUnit().
	 *
	 * @param mixed $value Null for a missing key.
	 *
	 * @return string
	 */
	private static function add_css_unit($value)
	{
		$string = self::js_string($value);
		foreach (array('px', 'em', '%', 'vh', 'vw') as $unit) {
			if (strpos($string, $unit) !== false) {
				return $string;
			}
		}
		return (self::not_empty($value) ? $string : '0') . 'px';
	}

	/**
	 * SbUtils.checkNotEmpty(): only spaces are stripped, as `/ /gi` does.
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	private static function not_empty($value)
	{
		return $value !== null && $value !== 'undefined' && str_replace(' ', '', self::js_string($value)) !== '';
	}

	/**
	 * @param mixed  $value
	 * @param string $key
	 *
	 * @return mixed Null when missing.
	 */
	private static function field($value, $key)
	{
		return is_array($value) && array_key_exists($key, $value) ? $value[$key] : null;
	}

	/**
	 * A missing key in a template literal prints "undefined".
	 *
	 * @param mixed  $value
	 * @param string $key
	 *
	 * @return string
	 */
	private static function js_string_field($value, $key)
	{
		return is_array($value) && array_key_exists($key, $value) ? self::js_string($value[$key]) : 'undefined';
	}

	/**
	 * String(value) as JavaScript prints a JSON-decoded value.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private static function js_string($value)
	{
		if (is_string($value)) {
			return $value;
		}
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if ($value === null) {
			return 'null';
		}
		if (is_int($value)) {
			return (string) $value;
		}
		if (is_float($value)) {
			if (is_finite($value) && floor($value) === $value && abs($value) < 1e21) {
				return number_format($value, 0, '.', '');
			}
			return (string) wp_json_encode($value);
		}
		if (is_array($value)) {
			if ($value !== array() && array_keys($value) !== range(0, count($value) - 1)) {
				return '[object Object]';
			}
			// Array.prototype.join() prints null as an empty string.
			return implode(',', array_map(static function ($item) {
				return $item === null ? '' : self::js_string($item);
			}, $value));
		}
		return '';
	}

	/**
	 * `haystack.indexOf(needle)`: strict match in an array, substring position in a string.
	 *
	 * @param mixed $haystack
	 * @param mixed $needle
	 * @param bool  $has_needle False mirrors `undefined`.
	 *
	 * @return int
	 */
	private static function js_index_of($haystack, $needle, $has_needle)
	{
		if (is_string($haystack)) {
			$position = strpos($haystack, $has_needle ? self::js_string($needle) : 'undefined');
			return $position === false ? -1 : $position;
		}
		if (!is_array($haystack) || !$has_needle) {
			return -1;
		}
		foreach (array_values($haystack) as $index => $item) {
			// JavaScript has one number type: 1 === 1.0.
			$numbers = (is_int($item) || is_float($item)) && (is_int($needle) || is_float($needle));
			if ($numbers ? $item == $needle : $item === $needle) {
				return $index;
			}
		}
		return -1;
	}

	/**
	 * String.prototype.replace() with a string pattern: first match only, `$` patterns honoured.
	 *
	 * @param string $subject
	 * @param string $search
	 * @param string $replacement
	 *
	 * @return string
	 */
	private static function js_replace_first($subject, $search, $replacement)
	{
		$position = strpos($subject, $search);
		if ($position === false) {
			return $subject;
		}
		$before   = substr($subject, 0, $position);
		$after    = substr($subject, $position + strlen($search));
		$expanded = preg_replace_callback(
			'/\$([$&`\'])/',
			static function ($match) use ($search, $before, $after) {
				switch ($match[1]) {
					case '$':
						return '$';
					case '&':
						return $search;
					case '`':
						return $before;
					default:
						return $after;
				}
			},
			$replacement
		);
		return $before . $expanded . $after;
	}

	/**
	 * Providers of the feed's sources, as the Builder hydrates them for `conditionSources`.
	 *
	 * @param array $settings
	 *
	 * @return string[]
	 */
	public static function source_providers(array $settings)
	{
		$ids = isset($settings['sources']) && is_array($settings['sources']) ? $settings['sources'] : array();
		if (empty($ids)) {
			return array();
		}
		$providers = array();
		foreach ((array) SBR_Sources::get_sources_list(array('id' => $ids)) as $source) {
			if (is_array($source) && isset($source['provider'], $source['account_id']) && in_array($source['account_id'], $ids, true)) {
				$providers[] = (string) $source['provider'];
			}
		}
		return $providers;
	}

	/**
	 * The tabs Feed_Builder::customizer_builder_data() hands the Builder.
	 *
	 * Feed_Builder loads these files with a plain `require`, so a class this
	 * generator autoloaded first would be declared twice on the Builder page. On
	 * that page nothing is loaded here: the Builder writes the CSS itself on save.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_tabs()
	{
		if (self::$tabs !== null) {
			return self::$tabs;
		}

		$tabs  = array();
		$files = defined('SBR_CUSTOMIZER_TABS_PATH') && is_dir(SBR_CUSTOMIZER_TABS_PATH) ? scandir(SBR_CUSTOMIZER_TABS_PATH) : false;
		if (!is_array($files)) {
			return array();
		}

		$on_builder_page = is_admin() && isset($_GET['page']) && sanitize_key(wp_unslash($_GET['page'])) === SBR_CUSTOMIZER_MENU_SLUG; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check
		foreach ($files as $file) {
			if (substr($file, -4) !== '.php') {
				continue;
			}
			$class = SBR_CUSTOMIZER_TABS_NAMESPACE . substr($file, 0, -4);
			if ($on_builder_page && !class_exists($class, false)) {
				return array();
			}
			if (!class_exists($class) || !is_subclass_of($class, SB_Sidebar_Tab::class)) {
				continue;
			}
			$tab = new $class();
			if ($tab->should_display()) {
				$tabs[] = $tab->get_tab();
			}
		}

		self::$tabs = $tabs;
		return $tabs;
	}
}
