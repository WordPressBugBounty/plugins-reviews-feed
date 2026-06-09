<?php

/**
 * PHPUnit bootstrap file for sb-reviews plugin tests.
 *
 * These tests are designed to run without the full WordPress environment
 * by mocking WordPress functions and focusing on unit-testable logic.
 */

// Define WordPress stubs for functions used in tested code
if (!defined('ABSPATH')) {
	define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('SBR_RELAY_BASE_URL')) {
	define('SBR_RELAY_BASE_URL', 'https://relay.smashballoon.com/api/v1.0/');
}

// Plugin constants required when tests `require` the full sbr-functions.php.
if (!defined('SBR_PLUGIN_BASENAME')) {
	define('SBR_PLUGIN_BASENAME', 'reviews-feed-pro/sb-reviews-pro.php');
}
if (!defined('SBR_PLUGIN_URL')) {
	define('SBR_PLUGIN_URL', 'https://example.test/wp-content/plugins/reviews-feed-pro/');
}
if (!defined('SBRVER')) {
	define('SBRVER', '2.5.0-test');
}
// Pro-side constants — required for silent-reactivation Pro-path tests.
// Pro tests will have these defined; the Free-skip path is covered by the
// license_key===''  early return, since Free installs never populate a key.
if (!defined('SBR_PLUGIN_NAME')) {
	define('SBR_PLUGIN_NAME', 'Reviews Feed Pro Test');
}
if (!defined('SBR_PRODUCT_ID')) {
	define('SBR_PRODUCT_ID', 9999999);
}

// Mock WordPress functions used in tested code
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($str)
	{
		return trim(strip_tags($str));
	}
}

if (!function_exists('absint')) {
	function absint($maybeint)
	{
		return abs((int) $maybeint);
	}
}

if (!function_exists('get_option')) {
	function get_option($option, $default = false)
	{
		global $wp_options_mock;
		return $wp_options_mock[$option] ?? $default;
	}
}

if (!function_exists('update_option')) {
	// Core signature: update_option($option, $value, $autoload = null).
	// $autoload is accepted for signature parity so other tests exercising code
	// that passes it don't fail with "Too many arguments".
	function update_option($option, $value, $autoload = null)
	{
		global $wp_options_mock;
		if (!is_array($wp_options_mock)) {
			$wp_options_mock = [];
		}
		$wp_options_mock[$option] = $value;
		return true;
	}
}

if (!function_exists('delete_option')) {
	function delete_option($option)
	{
		global $wp_options_mock;
		if (!is_array($wp_options_mock)) {
			$wp_options_mock = [];
			return true;
		}
		unset($wp_options_mock[$option]);
		return true;
	}
}

if (!function_exists('get_home_url')) {
	// Core signature: get_home_url($blog_id = null, $path = '', $scheme = null).
	// Accept and ignore the extra args so callers using the full form don't error.
	function get_home_url($blog_id = null, $path = '', $scheme = null)
	{
		global $wp_home_url_mock;
		return $wp_home_url_mock ?? '';
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (!function_exists('trailingslashit')) {
	function trailingslashit($string)
	{
		return rtrim($string, '/\\') . '/';
	}
}

if (!function_exists('wp_upload_dir')) {
	function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false)
	{
		return [
			'path'    => '/tmp/uploads',
			'url'     => 'https://example.test/wp-content/uploads',
			'subdir'  => '',
			'basedir' => '/tmp/uploads',
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
	}
}

// Stubs that let tests `require_once 'class/sbr-functions.php'` without
// triggering WordPress-only bootstrap calls at the top level.
if (!function_exists('register_activation_hook')) {
	function register_activation_hook($file, $callback)
	{
		// no-op for tests
	}
}
if (!function_exists('add_action')) {
	function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
	{
		// no-op for tests
	}
}
if (!function_exists('add_filter')) {
	function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
	{
		// no-op for tests
	}
}
if (!function_exists('apply_filters')) {
	function apply_filters($hook, $value, ...$args)
	{
		return $value;
	}
}
if (!function_exists('do_action')) {
	function do_action($hook, ...$args)
	{
		// no-op for tests
	}
}

// Transient stubs for silent-reactivation rate-limit + notice-payload tests.
// Stored in a dedicated global ($wp_transients_mock) so tests can manipulate
// them independently from $wp_options_mock.
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
	define('WEEK_IN_SECONDS', 604800);
}
if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
	define('MINUTE_IN_SECONDS', 60);
}
// `wpdb::get_results()` output_type constants — production code passes ARRAY_A.
if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
	define('ARRAY_N', 'ARRAY_N');
}
if (!defined('OBJECT')) {
	define('OBJECT', 'OBJECT');
}
if (!function_exists('get_transient')) {
	function get_transient($key)
	{
		global $wp_transients_mock;
		if (!is_array($wp_transients_mock) || !isset($wp_transients_mock[$key])) {
			return false;
		}
		return $wp_transients_mock[$key];
	}
}
if (!function_exists('set_transient')) {
	function set_transient($key, $value, $ttl = 0)
	{
		global $wp_transients_mock;
		if (!is_array($wp_transients_mock)) {
			$wp_transients_mock = [];
		}
		$wp_transients_mock[$key] = $value;
		return true;
	}
}
if (!function_exists('delete_transient')) {
	function delete_transient($key)
	{
		global $wp_transients_mock;
		if (!is_array($wp_transients_mock)) {
			return true;
		}
		unset($wp_transients_mock[$key]);
		return true;
	}
}

// Stub is_plugin_active for provider-detection tests (EDD provider gate).
// Backed by $wp_active_plugins_mock so tests can flip plugin-presence per case
// without touching real wp-admin includes.
if (!function_exists('is_plugin_active')) {
	function is_plugin_active($plugin_path)
	{
		global $wp_active_plugins_mock;
		if (!is_array($wp_active_plugins_mock)) {
			return false;
		}
		return in_array($plugin_path, $wp_active_plugins_mock, true);
	}
}

// i18n stub used by translatable strings in tested code paths.
if (!function_exists('__')) {
	function __($text, $domain = null)
	{
		return $text;
	}
}

// WP HTTP helpers — never actually invoked in unit tests (SBRelay::call is
// mocked at the `onlyMethods(['call'])` level), but reverify_token_via_register
// has a `function_exists` defense-in-depth guard that bails early if these
// helpers aren't defined. Without these stubs the guard fires in tests and
// reverify never reaches the mocked `call()`.
if (!function_exists('wp_remote_post')) {
	function wp_remote_post($url, $args = [])
	{
		return [];
	}
}
if (!function_exists('wp_remote_get')) {
	function wp_remote_get($url, $args = [])
	{
		return [];
	}
}
if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return false;
	}
}
if (!function_exists('wp_remote_retrieve_body')) {
	function wp_remote_retrieve_body($response)
	{
		return '';
	}
}

// Cron API stubs — namespace-fallback resolution requires these to live in
// the global namespace so callers in `SmashBalloon\Reviews\Pro\Services\BulkUpdate`
// (and elsewhere) can find them via PHP's fallback lookup.
if (!function_exists('wp_schedule_single_event')) {
	function wp_schedule_single_event($timestamp, $hook, $args = [])
	{
		return true;
	}
}

if (!function_exists('wp_next_scheduled')) {
	function wp_next_scheduled($hook, $args = [])
	{
		return false;
	}
}

if (!function_exists('wp_clear_scheduled_hook')) {
	function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false)
	{
		return 0;
	}
}

// Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';
