<?php

/**
 * Minimal WP_Screen: only the `id` the admin-notice screen gates read.
 * Its own file because phpcs enforces one class per file.
 */

if (! class_exists('WP_Screen')) {
	class WP_Screen
	{
		/** @var string */
		public $id = '';
	}
}
