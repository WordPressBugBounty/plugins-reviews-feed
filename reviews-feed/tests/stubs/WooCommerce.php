<?php

/**
 * A body for `class_exists('WooCommerce')`, for the Woo tests only.
 *
 * Fifteen call sites in the plugin branch on this class name, and a PHP class
 * cannot be un-declared — so declaring it in the shared bootstrap would flip
 * behaviour for every other test in the process. The Woo tests are
 * `@runInSeparateProcess`, and this file is required from inside them, so the
 * name exists only there.
 *
 * A file of its own rather than `eval('class WooCommerce {}')`: static analysis
 * has a real declaration to read, and phpcs's one-class-per-file rule refuses a
 * second class inside the test file.
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace -- must be the GLOBAL name the plugin checks for.
if (! class_exists('WooCommerce')) {
	class WooCommerce
	{
	}
}
