<?php

/**
 * A body for `wc_get_product()`, for the Woo tests only: returns the products a test registered in
 * $GLOBALS['sbr_test_wc_products'] (id => object), false otherwise.
 *
 * Several call sites branch on `function_exists('wc_get_product')` (Feed.php, the Woo cache handler,
 * the source builder), and a function cannot be un-declared — so it is required from inside
 * `@runInSeparateProcess` tests only, like stubs/WooCommerce.php.
 */

if (! function_exists('wc_get_product')) {
	function wc_get_product($id)
	{
		return $GLOBALS['sbr_test_wc_products'][ (int) $id ] ?? false;
	}
}
