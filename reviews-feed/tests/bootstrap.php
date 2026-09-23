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

// REST namespace — mirrors the runtime define (plugin bootstrap.php) so tests
// exercising the provider REST routes resolve the constant instead of erroring.
if (!defined('SBR_REST_DOMAIN')) {
	define('SBR_REST_DOMAIN', 'SBR/v1');
}

// Unprefixed posts-table name — Common\Customizer\DB reads it as a bare
// constant, so it has to exist globally. Mirrors plugin bootstrap.php:114 and
// phpstan-ci-bootstrap.php:39.
if (!defined('POSTS_TABLE_NAME')) {
	define('POSTS_TABLE_NAME', 'sbr_reviews_posts');
}

// Plugin constants required when tests `require` the full sbr-functions.php.
if (!defined('SBR_PLUGIN_BASENAME')) {
	define('SBR_PLUGIN_BASENAME', 'reviews-feed-pro/sb-reviews-pro.php');
}
// The real plugin root, so Util::get_feature_flags() reads the config file that
// ships rather than a stand-in — provider availability is decided there.
if (!defined('SBR_PLUGIN_DIR')) {
	define('SBR_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('SBR_PLUGIN_URL')) {
	define('SBR_PLUGIN_URL', 'https://example.test/wp-content/plugins/reviews-feed-pro/');
}

// SB_CUSTOMIZER_ASSETS is deliberately NOT defined here. Sbr163BundleScreenProbeTest
// defines it per-process to prove bundle_path() follows the constant the enqueue
// uses — another Smash Balloon plugin can own it — and a constant cannot be
// undeclared, so pre-defining it here made that test resolve our own copy and fail.
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
// Feeds table name — mirrors the runtime define (plugin bootstrap.php) so tests
// exercising queries that use SBR_FEEDS_TABLE (e.g. feed_localizations_for_source)
// resolve the constant instead of erroring on an undefined constant.
if (!defined('SBR_FEEDS_TABLE')) {
	define('SBR_FEEDS_TABLE', 'sbr_feeds');
}
// Reviews-posts table name — mirrors the runtime define (plugin bootstrap.php) so
// tests that load SinglePostCache (POSTS_TABLE_NAME = SBR_POSTS_TABLE class const)
// resolve the constant instead of erroring on class load.
if (!defined('SBR_POSTS_TABLE')) {
	define('SBR_POSTS_TABLE', 'sbr_reviews_posts');
}

// Feed-caches table name — mirrors bootstrap.php / phpstan-ci-bootstrap.php
// for tests exercising ReviewsReporter::get_performance_metrics() (SMASH-1130).
if (!defined('SBR_FEED_CACHES_TABLE')) {
	define('SBR_FEED_CACHES_TABLE', 'sbr_feed_caches');
}

// Sources table name — mirrors the runtime define so tests exercising the
// external-provider refresh path (SBR_Sources::sources_by_providers) resolve
// the constant instead of erroring on an undefined constant.
if (!defined('SBR_SOURCES_TABLE')) {
	define('SBR_SOURCES_TABLE', 'sbr_sources');
}

// Usage-tracking API base — mirrors the runtime define (plugin bootstrap.php)
// so Config::get_api_url() validation tests exercise the real fallback value.
if (!defined('SBR_SMASH_USAGE_TRACKING_API_URL')) {
	define('SBR_SMASH_USAGE_TRACKING_API_URL', 'https://usage.smashballoon.com/api');
}

/**
 * Remove `%XX` octets the way core does — in a loop, re-scanning after each pass.
 *
 * A single global preg_replace diverges once a removal makes two fragments
 * adjacent: `%2%411` leaves `%21` in one pass, where core
 * (wp-includes/formatting.php `_sanitize_text_fields()`) removes `%41`, re-scans,
 * finds the `%21` that formed, and returns ''. These shims exist because a loose
 * one hid the SMASH-2031 endpoint corruption, so the fidelity is the point.
 *
 * @param string $value Raw value.
 *
 * @return string
 */
if (!function_exists('sbr_test_strip_percent_octets')) {
	function sbr_test_strip_percent_octets(string $value): string
	{
		while (preg_match('/%[a-f0-9]{2}/i', $value, $match)) {
			$value = str_replace($match[0], '', $value);
		}

		return $value;
	}
}

// Mock WordPress functions used in tested code
if (!function_exists('sanitize_text_field')) {
	// Faithful on the parts that bite: WP's _sanitize_text_fields() also
	// removes every `%XX` sequence and collapses line breaks. The loose
	// trim(strip_tags()) shim hid a real bug in SMASH-2031, where this
	// function silently rewrote the percent-encoded URL stored in sbr_errors.
	function sanitize_text_field($str)
	{
		$str = strip_tags((string) $str);
		$str = preg_replace('/[\r\n\t ]+/', ' ', $str);
		$str = sbr_test_strip_percent_octets((string) $str);
		return trim($str);
	}
}

// SMASH-1795 — parse_single_review() and sbr_kses_review_text() now use these.
// Approximations of WordPress behaviour, enough for the unit contracts under test:
// tags stripped, newlines kept by the textarea variant, allowlist honoured by kses.
if (!function_exists('sanitize_textarea_field')) {
	function sanitize_textarea_field($str)
	{
		// Newline-preserving, but the `%XX` strip is NOT: core reaches it via
		// _sanitize_text_fields($str, true), and that loop (formatting.php:5661)
		// sits outside the $keep_newlines branch. Same infidelity as the
		// sibling shim above, which hid the SMASH-2031 endpoint corruption.
		$str = strip_tags((string) $str);
		$str = sbr_test_strip_percent_octets((string) $str);
		return trim($str);
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key)
	{
		return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
	}
}

// SMASH-1795 — sbr_kses_review_text() resolves an emoji alt through esc_html().
// These lived only inside individual test files, which made any class relying on
// them pass in a full run and fatal in isolation. They belong here.
if (!function_exists('esc_html')) {
	function esc_html($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

// Templates call the translating escapers, not just the plain ones. These were
// missing here and defined in one unrelated test file instead, so any template
// test passed only while that file happened to load in the same run — and
// fatalled the moment it was run on its own.
if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default')
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_html_e')) {
	function esc_html_e($text, $domain = 'default')
	{
		echo esc_html($text);
	}
}

if (!function_exists('esc_url')) {
	/**
	 * Delegates to the faithful esc_url_raw stub below, then encodes for an
	 * attribute — which is what WordPress's esc_url() does and what a test
	 * asserting "this link is safe" needs it to do.
	 *
	 * filter_var(FILTER_SANITIZE_URL) was the first attempt and is not that: it
	 * strips neither javascript: nor data:, and encodes no quotes, so a test
	 * naming those exact attacks would have passed against a shim that lets
	 * them through.
	 */
	function esc_url($url)
	{
		return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_attr__')) {
	function esc_attr__($text, $domain = 'default')
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url_raw')) {
	/**
	 * Mirrors the parts of WordPress's esc_url() that this ticket depends on, in the
	 * same ORDER — the order is what makes it safe, and a stub that skipped a step
	 * would give false coverage on exactly the attack class under test.
	 *
	 * Verified against a real WP install; all of these return '':
	 *   javascript:alert(1) · data:text/html;base64,x · java<TAB>script:alert(1)
	 *   jav&#x0A;ascript:alert(1) · jav&amp;#x0A;ascript:alert(1)
	 * and these round-trip: /wp-content/a.jpg · https://x.test/my%20photo.jpg
	 * while a scheme-less relative path gains a host: wp-content/a.jpg ->
	 * http://wp-content/a.jpg.
	 */
	function esc_url_raw($url)
	{
		$url = str_replace(' ', '%20', ltrim((string) $url));
		// WP strips every character outside this set BEFORE testing the protocol,
		// which is what disarms `java<TAB>script:` and the entity-encoded forms.
		$url = (string) preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url);
		if ($url === '') {
			return '';
		}
		if (stripos($url, 'mailto:') !== 0) {
			$url = str_ireplace(array('%0d', '%0a'), '', $url);
		}
		$url = str_replace(';//', '://', $url);

		if (strpos($url, ':') !== false) {
			$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
			// A leading '//' is protocol-relative, not a scheme.
			if (
				strpos($url, '//') !== 0
				&& !in_array($scheme, array('http', 'https', 'mailto', 'tel'), true)
			) {
				return '';
			}
			return $url;
		}
		if (!in_array($url[0], array('/', '#', '?'), true)) {
			return 'http://' . $url;
		}
		return $url;
	}
}

if (!function_exists('wp_kses')) {
	/**
	 * strip_tags() alone is NOT a faithful enough stand-in: it keeps every attribute
	 * on an allowed tag, so `<span onmouseover=…>` would survive and a test asserting
	 * that attributes are dropped would pass against a broken implementation. Real
	 * wp_kses() drops any attribute not in the tag's allowlist, so the stub strips
	 * attributes too and only honours the ones explicitly permitted.
	 */
	function wp_kses($string, $allowed_html = array())
	{
		// Real wp_kses() treats a STRING second argument as a CONTEXT NAME and resolves
		// it through wp_kses_allowed_html() — 'post' yields $allowedposttags, which
		// KEEPS <img class src alt>. Reproducing that here is what makes the
		// non-array-filter-return test non-vacuous: a stub that quietly cast the string
		// to an array would let the unguarded implementation pass. Verified against
		// WordPress: wp_kses('<img class="emoji" src="x" alt="pwn">', 'post') returns
		// the img intact.
		if (is_string($allowed_html)) {
			$allowed_html = $allowed_html === 'strip'
				? array()
				: array('img' => array('class' => array(), 'src' => array(), 'alt' => array()),
					'a' => array('href' => array()), 'em' => array(), 'strong' => array(), 'br' => array());
		}
		$allowed_html = (array) $allowed_html;
		$allowed      = array_keys($allowed_html);
		$string       = (string) $string;

		if (empty($allowed)) {
			return strip_tags($string);
		}

		$string = strip_tags($string, '<' . implode('><', $allowed) . '>');

		// Drop attributes that the tag's own allowlist doesn't name.
		return (string) preg_replace_callback(
			'#<([a-zA-Z0-9]+)([^>]*)>#',
			static function ($m) use ($allowed_html) {
				$tag   = strtolower($m[1]);
				$attrs = isset($allowed_html[$tag]) ? (array) $allowed_html[$tag] : array();
				if (empty($attrs)) {
					// Preserve a self-closing marker (`<br />`) but nothing else.
					return substr(rtrim($m[2]), -1) === '/' ? '<' . $tag . ' />' : '<' . $tag . '>';
				}
				$kept = '';
				foreach (array_keys($attrs) as $name) {
					if (preg_match('#\s' . preg_quote($name, '#') . '\s*=\s*("[^"]*"|\'[^\']*\'|\S+)#i', $m[2], $a)) {
						$value = trim($a[1], '"\'');
						// Real wp_kses() runs URL attributes through an allowed-protocol
						// list, so `javascript:` / `data:` hrefs are dropped. Without this
						// the stub would let an `a[href]` allowlist look safe when it isn't.
						if (in_array($name, array('href', 'src', 'cite'), true)) {
							$scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
							if ($scheme !== '' && !in_array($scheme, array('http', 'https', 'mailto', 'tel'), true)) {
								continue;
							}
						}
						$kept .= ' ' . $name . '=' . $a[1];
					}
				}
				return '<' . $tag . $kept . '>';
			},
			$string
		);
	}
}


if (!function_exists('wp_strip_all_tags')) {
	function wp_strip_all_tags($text, $remove_breaks = false)
	{
		$text = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
		$text = strip_tags($text);
		return $remove_breaks ? trim(preg_replace('/[\\r\\n\\t ]+/', ' ', $text)) : trim($text);
	}
}

if (!function_exists('absint')) {
	function absint($maybeint)
	{
		return abs((int) $maybeint);
	}
}

if (!function_exists('get_avatar_url')) {
	function get_avatar_url($id_or_email, $args = [])
	{
		return 'https://example.com/avatar/' . md5((string) (is_scalar($id_or_email) ? $id_or_email : ''));
	}
}

if (!function_exists('get_option')) {
	function get_option($option, $default = false)
	{
		global $wp_options_mock;
		return $wp_options_mock[$option] ?? $default;
	}
}

if (!function_exists('check_ajax_referer')) {
	// Controllable via $GLOBALS['sbr_test_nonce_ok'] (SMASH-1130 listener guard tests).
	// Records the requested action so tests can assert the guard verifies the
	// SAME nonce action the primary handlers use.
	function check_ajax_referer($action, $query_arg = false, $stop = true)
	{
		$GLOBALS['sbr_test_nonce_actions_checked'][] = $action;
		return $GLOBALS['sbr_test_nonce_ok'] ?? false;
	}
}

if (!function_exists('current_user_can')) {
	// Controllable via $GLOBALS['sbr_test_user_can']; the real
	// sbr_current_user_can() (class/sbr-functions.php) delegates here.
	// Records the requested capability so tests can assert it.
	function current_user_can($capability)
	{
		$GLOBALS['sbr_test_caps_checked'][] = $capability;
		return $GLOBALS['sbr_test_user_can'] ?? false;
	}
}

if (!function_exists('get_rest_url')) {
	function get_rest_url($blog_id = null, $path = '/', $scheme = 'rest')
	{
		return 'https://example.test/wp-json/' . ltrim($path, '/');
	}
}

if (!function_exists('is_user_logged_in')) {
	// Controllable via $GLOBALS['sbr_test_user_logged_in'].
	function is_user_logged_in()
	{
		return $GLOBALS['sbr_test_user_logged_in'] ?? false;
	}
}

require_once __DIR__ . '/stubs/class-wp-error.php';

if (!function_exists('current_action')) {
	// Controllable via $GLOBALS['sbr_test_current_action'].
	function current_action()
	{
		return $GLOBALS['sbr_test_current_action'] ?? '';
	}
}

if (!function_exists('wp_unslash')) {
	function wp_unslash($value)
	{
		return is_string($value) ? stripslashes($value) : $value;
	}
}

if (!function_exists('wp_parse_url')) {
	function wp_parse_url($url, $component = -1)
	{
		return parse_url($url, $component);
	}
}

if (!function_exists('wp_parse_args')) {
	function wp_parse_args($args, $defaults = [])
	{
		if (is_object($args)) {
			$args = get_object_vars($args);
		} elseif (!is_array($args)) {
			parse_str((string) $args, $args);
		}
		return array_merge($defaults, $args);
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

if (!function_exists('home_url')) {
	// Core signature: home_url($path = '', $scheme = null).
	// SmashUsageTracking::send_checkin() and RegisterSite::register() call the short form.
	function home_url($path = '', $scheme = null)
	{
		global $wp_home_url_mock;
		$base = $wp_home_url_mock ?? 'https://example.test';
		return rtrim($base, '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
	}
}

if (!function_exists('get_bloginfo')) {
	function get_bloginfo($show = '')
	{
		global $wp_bloginfo_mock;
		if (is_array($wp_bloginfo_mock) && array_key_exists($show, $wp_bloginfo_mock)) {
			return $wp_bloginfo_mock[$show];
		}
		return 'Test Site';
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

if (!function_exists('esc_sql')) {
	function esc_sql($data)
	{
		return is_array($data) ? array_map('esc_sql', $data) : addslashes((string) $data);
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
		// Recorded so tests can assert hook wiring (e.g. SMASH-1130 usage
		// tracking cron); otherwise a no-op.
		$GLOBALS['sbr_test_actions'][$hook][] = ['callback' => $callback, 'priority' => $priority];
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
		// Tests can inject a return value per hook via $wp_filter_mock (e.g. to
		// simulate WPML's wpml_active_languages / wpml_current_language). With no
		// mock set the stub keeps its original passthrough behavior.
		global $wp_filter_mock;
		if (isset($wp_filter_mock[$hook])) {
			return $wp_filter_mock[$hook];
		}
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
if (!defined('OBJECT_K')) {
	define('OBJECT_K', 'OBJECT_K');
}
if (!function_exists('get_transient')) {
	function get_transient($key)
	{
		global $wp_transients_mock, $wp_transient_reads;
		// Counted so a test can pin a per-request memo: without one, code that reads
		// the same transient once per entity still passes every value assertion.
		if (!is_array($wp_transient_reads)) {
			$wp_transient_reads = [];
		}
		$wp_transient_reads[$key] = (isset($wp_transient_reads[$key]) ? $wp_transient_reads[$key] : 0) + 1;

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

// Minimal $wpdb double so DB-touching helpers (e.g. clear_plugin_cache) no-op
// instead of fatalling in unit tests. Guarded so any test that installs its own
// $wpdb wins.
if (!isset($GLOBALS['wpdb'])) {
	$GLOBALS['wpdb'] = new class {
		public $prefix = 'wp_';
		public function query($sql)
		{
			return 0;
		}
		public function get_results($sql, $output = null)
		{
			return [];
		}
		public function get_var($sql)
		{
			return null;
		}
		public function get_row($sql, $output = null)
		{
			return null;
		}
		public function prepare($query, ...$args)
		{
			return $query;
		}
		public function esc_like($text)
		{
			return addcslashes((string) $text, '_%\\');
		}
	};
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
		// Record calls so tests can assert whether an HTTP request was
		// attempted (e.g. the usage-tracking failure backoff must NOT reach
		// the network).
		$GLOBALS['sbr_test_http_posts'][] = ['url' => $url, 'args' => $args];

		global $wp_http_error_mock;

		if ($wp_http_error_mock instanceof \WP_Error) {
			return $wp_http_error_mock;
		}

		return [];
	}
}

if (!function_exists('wp_remote_retrieve_response_code')) {
	function wp_remote_retrieve_response_code($response)
	{
		global $wp_http_response_code_mock;
		return $wp_http_response_code_mock ?? 0;
	}
}
if (!function_exists('wp_remote_get')) {
	function wp_remote_get($url, $args = [])
	{
		// Mockable failure, so the `is_wp_error()` branch in callers is reachable —
		// with the old always-false shim it never was.
		global $wp_http_error_mock;

		if ($wp_http_error_mock instanceof \WP_Error) {
			return $wp_http_error_mock;
		}

		return [];
	}
}
if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		// `return false` was the whole shim, which made 49 production branches
		// unreachable from this suite — every `if (is_wp_error($x))` took the
		// success path even when handed a WP_Error, so an error path could not be
		// asserted and a regression that started returning WP_Error where data was
		// expected would still go green. Same contract as core's.
		return $thing instanceof \WP_Error;
	}
}
if (!function_exists('wp_remote_retrieve_body')) {
	function wp_remote_retrieve_body($response)
	{
		// Mockable, so a test can drive a real relay envelope through
		// SBRelay::call(). Defaults to '' — the behaviour before the hook.
		global $wp_http_body_mock;

		return $wp_http_body_mock ?? '';
	}
}

// Scheduler::schedule() jitters its first run with wp_rand(). Without this, a test
// that reaches schedule() unexpectedly dies on an undefined function instead of
// failing on its own assertion.
if (!function_exists('wp_rand')) {
	function wp_rand($min = 0, $max = 0)
	{
		return $min;
	}
}

// The two request terminators, so an AJAX handler can be driven end to end
// (SMASH-2028 collection import/duplicate tests). Real WP exits; these throw a
// dedicated exception the test catches, which is what makes the handler's
// *early returns* observable instead of ending the process.
if (!class_exists('SBR_Test_Halt')) {
	class SBR_Test_Halt extends \RuntimeException
	{
		/** @var mixed */
		public $payload;

		/** @var int|null HTTP status, when the caller passed one (SMASH-2031). */
		public $status;

		/**
		 * @param mixed    $payload
		 * @param int|null $status
		 */
		public function __construct($kind, $payload = null, $status = null)
		{
			parent::__construct($kind);
			$this->payload = $payload;
			$this->status = $status;
		}
	}
}

if (!function_exists('wp_die')) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- EscapeOutput reads this declaration as a wp_die() call and wants its args escaped; nothing is output, the shim only throws.
	function wp_die($message = '', $title = '', $args = [])
	{
		// Real wp_die() reads its HTTP status from $args['response'], and also
		// accepts a bare integer $args as shorthand for that code — both forms
		// exist in core call paths. Dropping $args left SBR_Test_Halt::$status
		// null for every wp_die(), so a test could not assert the status a caller
		// passed.
		if (is_array($args) && isset($args['response'])) {
			$status = (int) $args['response'];
		} elseif (is_int($args) || (is_string($args) && ctype_digit($args))) {
			$status = (int) $args;
		} else {
			$status = null;
		}

		throw new \SBR_Test_Halt('wp_die', $message, $status);
	}
}

if (!function_exists('wp_send_json_error')) {
	function wp_send_json_error($data = null, $status_code = null, $options = 0)
	{
		throw new \SBR_Test_Halt('wp_send_json_error', $data, $status_code);
	}
}

if (!function_exists('wp_send_json')) {
	function wp_send_json($data, $status_code = null, $options = 0)
	{
		throw new \SBR_Test_Halt('wp_send_json', $data, $status_code);
	}
}

if (!function_exists('wp_send_json_success')) {
	function wp_send_json_success($data = null, $status_code = null, $options = 0)
	{
		throw new \SBR_Test_Halt('wp_send_json_success', $data, $status_code);
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

// Recurring-event recorder so scheduling logic (idempotency, recurrence) is
// unit-testable: wp_schedule_event() records into $wp_scheduled_events_mock and
// wp_next_scheduled() reads it back. Tests reset the global in setUp().
if (!function_exists('wp_schedule_event')) {
	function wp_schedule_event($timestamp, $recurrence, $hook, $args = [], $wp_error = false)
	{
		global $wp_scheduled_events_mock;
		if (!is_array($wp_scheduled_events_mock)) {
			$wp_scheduled_events_mock = [];
		}
		$wp_scheduled_events_mock[$hook] = [
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'args'       => $args,
		];
		return true;
	}
}

if (!function_exists('wp_next_scheduled')) {
	function wp_next_scheduled($hook, $args = [])
	{
		global $wp_scheduled_events_mock;
		if (is_array($wp_scheduled_events_mock) && isset($wp_scheduled_events_mock[$hook])) {
			return $wp_scheduled_events_mock[$hook]['timestamp'];
		}
		return false;
	}
}

if (!function_exists('wp_clear_scheduled_hook')) {
	function wp_clear_scheduled_hook($hook, $args = [], $wp_error = false)
	{
		global $wp_scheduled_events_mock;
		if (is_array($wp_scheduled_events_mock)) {
			unset($wp_scheduled_events_mock[$hook]);
		}
		return 0;
	}
}

// ---------------------------------------------------------------------------
// Feed-location scan (SMASH-2103 / SMASH-2109).
//
// The parsers are NOT stubbed. Common\Customizer\DB reads placements with
// WordPress' own shortcode and block parsers, and the whole point of the tests
// over it is whether that reading is right — a hand-rolled stand-in would test
// the stand-in. So the real wp-includes files are loaded from the WordPress
// install this plugin sits in, and when they are not reachable the functions
// throw rather than quietly answer with something less faithful.
// ---------------------------------------------------------------------------

$sbr_wp_includes = dirname(__DIR__, 4) . '/wp-includes/';

// Declared unconditionally: the block-parser fallback below calls it, and that
// branch can be taken while shortcodes.php was readable.
if (!function_exists('sbr_tests_no_wp_core')) {
	function sbr_tests_no_wp_core($fn)
	{
		throw new RuntimeException(
			$fn . '() needs WordPress core. Looked in ' . dirname(__DIR__, 4) . '/wp-includes/. '
			. 'These tests parse with WordPress\' own parser on purpose and will not substitute a stand-in; '
			. 'run them from a plugin checkout inside a WordPress install.'
		);
	}
}

if (is_readable($sbr_wp_includes . 'shortcodes.php')) {
	require_once $sbr_wp_includes . 'shortcodes.php';
} elseif (!function_exists('get_shortcode_regex')) {
	function get_shortcode_regex($tagnames = null)
	{
		sbr_tests_no_wp_core(__FUNCTION__);
	}
	function shortcode_parse_atts($text)
	{
		sbr_tests_no_wp_core(__FUNCTION__);
	}
}

if (!function_exists('parse_blocks')) {
	$sbr_parser_files = array(
		'class-wp-block-parser-block.php',
		'class-wp-block-parser-frame.php',
		'class-wp-block-parser.php',
	);
	$sbr_have_parser = is_readable($sbr_wp_includes . 'class-wp-block-parser.php');

	if ($sbr_have_parser) {
		foreach ($sbr_parser_files as $sbr_parser_file) {
			if (is_readable($sbr_wp_includes . $sbr_parser_file)) {
				require_once $sbr_wp_includes . $sbr_parser_file;
			}
		}

		function parse_blocks($content)
		{
			// Counted so a test can pin that a site-wide scan parses each post once
			// rather than once per entity it is matching against.
			global $wp_parse_blocks_calls;
			$wp_parse_blocks_calls = (is_int($wp_parse_blocks_calls) ? $wp_parse_blocks_calls : 0) + 1;

			$parser = new WP_Block_Parser();

			return $parser->parse($content);
		}
	} else {
		function parse_blocks($content)
		{
			sbr_tests_no_wp_core(__FUNCTION__);
		}
	}
}

// Post-type registry. Keys are type names, values are the `public` flag, so a
// test can register the non-public containers the scan deliberately opts back in.
if (!function_exists('get_post_types')) {
	function get_post_types($args = array(), $output = 'names', $operator = 'and')
	{
		global $wp_post_types_mock;

		$types = is_array($wp_post_types_mock)
			? $wp_post_types_mock
			: array('post' => true, 'page' => true, 'attachment' => true);

		if (isset($args['public'])) {
			$types = array_filter(
				$types,
				static function ($is_public) use ($args) {
					return (bool) $is_public === (bool) $args['public'];
				}
			);
		}

		$names = array_keys($types);

		return array_combine($names, $names);
	}
}

if (!function_exists('post_type_exists')) {
	function post_type_exists($post_type)
	{
		global $wp_post_types_mock;

		return is_array($wp_post_types_mock) && array_key_exists($post_type, $wp_post_types_mock);
	}
}

// Post-type lookup by ID, for the cache-invalidation hook.
if (!function_exists('get_post_type')) {
	function get_post_type($post = null)
	{
		global $wp_post_type_by_id_mock;

		$id = is_object($post) ? (int) $post->ID : (int) $post;

		return ( is_array($wp_post_type_by_id_mock) && isset($wp_post_type_by_id_mock[$id]) )
			? $wp_post_type_by_id_mock[$id]
			: false;
	}
}

if (!function_exists('wp_is_post_revision')) {
	function wp_is_post_revision($post)
	{
		global $wp_post_revisions_mock;

		$id = is_object($post) ? (int) $post->ID : (int) $post;

		return ( is_array($wp_post_revisions_mock) && in_array($id, $wp_post_revisions_mock, true) ) ? $id : false;
	}
}

if (!function_exists('wp_is_post_autosave')) {
	function wp_is_post_autosave($post)
	{
		global $wp_post_autosaves_mock;

		$id = is_object($post) ? (int) $post->ID : (int) $post;

		return ( is_array($wp_post_autosaves_mock) && in_array($id, $wp_post_autosaves_mock, true) ) ? $id : false;
	}
}

// Cache priming is a pure optimisation in the scan; there is no object cache here.
if (!function_exists('_prime_post_caches')) {
	function _prime_post_caches($ids, $update_term_cache = true, $update_meta_cache = true)
	{
		return null;
	}
}

if (!function_exists('get_the_permalink')) {
	function get_the_permalink($post = 0, $leavename = false)
	{
		$id = is_object($post) ? (int) $post->ID : (int) $post;

		return 'https://example.test/?p=' . $id;
	}
}

if (!function_exists('get_user_locale')) {
	function get_user_locale($user = 0)
	{
		global $wp_user_locale_mock;

		return is_string($wp_user_locale_mock) ? $wp_user_locale_mock : 'en_US';
	}
}

if (!function_exists('get_current_blog_id')) {
	function get_current_blog_id()
	{
		global $wp_current_blog_id_mock;

		return is_numeric($wp_current_blog_id_mock) ? (int) $wp_current_blog_id_mock : 1;
	}
}

// Post-type object, for code that reads a type's own singular label.
if (!function_exists('get_post_type_object')) {
	function get_post_type_object($post_type)
	{
		global $wp_post_types_mock;

		if (!is_array($wp_post_types_mock) || !array_key_exists($post_type, $wp_post_types_mock)) {
			return null;
		}

		$labels = new stdClass();
		$labels->singular_name = ucfirst(str_replace(array('_', '-'), ' ', (string) $post_type));

		$object = new stdClass();
		$object->name   = $post_type;
		$object->labels = $labels;

		return $object;
	}
}

// Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Util::should_store_local_images() passes sbr_plugin_settings_defaults() as
// get_option()'s default, so it is evaluated eagerly even when a test has already
// mocked `sbr_settings`. Load the real helper rather than shadowing it — declaring a
// duplicate here fatals as soon as any test require_once's class/sbr-functions.php,
// which has no function_exists guard. Safe at this point: the add_action /
// register_activation_hook / add_filter stubs above absorb its top-level calls.
// (SMASH-1785)
if (!function_exists('sbr_plugin_settings_defaults')) {
	require_once dirname(__DIR__) . '/class/sbr-functions.php';
}

if (!function_exists('_n')) {
	function _n($single, $plural, $number, $domain = null)
	{
		return ((int) $number === 1) ? $single : $plural;
	}
}

if (!function_exists('number_format_i18n')) {
	function number_format_i18n($number, $decimals = 0)
	{
		return number_format((float) $number, (int) $decimals);
	}
}

// WP_Widget double lives in its own file (PSR1); see Doubles/WP_Widget.php.
require_once __DIR__ . '/Doubles/WP_Widget.php';

if (!function_exists('sanitize_hex_color')) {
	// Mirrors WP's regex exactly: 3 or 6 hex digits, '#' required, null on
	// anything else. A looser shim would let the parity test pass on input the
	// real function discards.
	function sanitize_hex_color($color)
	{
		if ('' === $color) {
			return '';
		}

		return preg_match('|^#([A-Fa-f0-9]{3}){1,2}$|', $color) ? $color : null;
	}
}

// Posts and post meta, for the badge store. Same in-memory shape as
// $wp_options_mock above.
//
// The RETURN semantics are the point, not the storage: update_metadata() answers
// false when the stored value already equals the new one, and delete_metadata()
// answers false when there was no row. SBR_Trust_Badge_Store's email-embed methods
// exist to not mistake either for a failure, so a looser shim would let them pass
// on behaviour real WP does not have.
if (! function_exists('get_post')) {
	function get_post($id = 0)
	{
		global $wp_posts_mock;

		return isset($wp_posts_mock[ (int) $id ]) ? $wp_posts_mock[ (int) $id ] : null;
	}
}

if (! function_exists('get_post_meta')) {
	function get_post_meta($id, $key = '', $single = false)
	{
		global $wp_postmeta_mock;

		$value = isset($wp_postmeta_mock[ (int) $id ][ $key ])
			? $wp_postmeta_mock[ (int) $id ][ $key ]
			: null;

		if ($single) {
			return $value === null ? '' : $value;
		}

		return $value === null ? array() : array( $value );
	}
}

if (! function_exists('update_post_meta')) {
	function update_post_meta($id, $key, $value, $prev_value = '')
	{
		global $wp_postmeta_mock, $wp_postmeta_fail_writes;

		if (! is_array($wp_postmeta_mock)) {
			$wp_postmeta_mock = array();
		}

		$id = (int) $id;

		// Set by a test that needs a genuine write FAILURE — false with the old value
		// left in place — which is the case a state-reporting return must not mistake
		// for the idempotent one.
		if (! empty($wp_postmeta_fail_writes)) {
			return false;
		}

		if (isset($wp_postmeta_mock[ $id ][ $key ]) && $wp_postmeta_mock[ $id ][ $key ] === $value) {
			return false;
		}

		$wp_postmeta_mock[ $id ][ $key ] = $value;

		return true;
	}
}

if (! function_exists('delete_post_meta')) {
	function delete_post_meta($id, $key, $value = '')
	{
		global $wp_postmeta_mock;

		$id = (int) $id;

		if (! isset($wp_postmeta_mock[ $id ][ $key ])) {
			return false;
		}

		unset($wp_postmeta_mock[ $id ][ $key ]);

		return true;
	}
}

// The deterministic core of WP's sanitize_file_name() (wp-includes/formatting.php),
// which is what a provider slug meets on its way to a file path: the special-char
// list, `.{2,}` collapsed to one dot, whitespace to dashes, then a trim of `.-_`.
//
// Left out: remove_accents() and the mime-type branch that renames a dotless name.
// Neither can fire for a provider slug, and shimming them would be guessing at
// behaviour these tests do not exercise.
if (! function_exists('sanitize_file_name')) {
	function sanitize_file_name($filename)
	{
		$special_chars = array(
			'?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$',
			'#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr(0),
		);

		$filename = str_replace($special_chars, '', (string) $filename);
		$filename = str_replace(array( '%20', '+' ), '-', $filename);
		$filename = (string) preg_replace('/\.{2,}/', '.', $filename);
		$filename = (string) preg_replace('/[\r\n\t -]+/', '-', $filename);

		return trim($filename, '.-_');
	}
}

// The recording WP_Query double is a GLOBAL class, so it cannot live in this file
// (PSR1: one class per file) and cannot be namespaced like tests/Unit/Doubles — the
// badge store resolves it at the root.
require_once __DIR__ . '/Doubles/WP_Query.php';

// Tokenising source reader for the guards that assert on a method's source
// because it has no runtime seam yet (SMASH-2074). Its own tests live in
// tests/Unit/PhpSourceExtractionTest.php.
require_once __DIR__ . '/Support/PhpSource.php';

// Badge-image doubles: a host with Imagick installed and unable to draw SVG is
// the only place mark_for()'s fallback matters, and no test machine can be one.
// One class per file, per phpcs.
require_once __DIR__ . '/Doubles/BadgeImageWithBlindImagick.php';
require_once __DIR__ . '/Doubles/BadgeImageWithNoRasteriser.php';
