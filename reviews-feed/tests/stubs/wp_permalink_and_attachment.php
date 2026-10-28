<?php

/**
 * `get_permalink()` and `wp_get_attachment_url()` for the Woo source-info test only (required from inside an
 * `@runInSeparateProcess` test, like stubs/WooCommerce.php): the shared bootstrap doesn't define them, and
 * declaring them process-wide could change other tests' paths.
 */

if (! function_exists('get_permalink')) {
	function get_permalink($post = 0)
	{
		return 'https://example.test/?p=' . (int) $post;
	}
}

if (! function_exists('wp_get_attachment_url')) {
	function wp_get_attachment_url($attachment_id = 0)
	{
		return '';
	}
}
