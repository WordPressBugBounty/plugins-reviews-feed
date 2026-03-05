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

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

// Autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';
