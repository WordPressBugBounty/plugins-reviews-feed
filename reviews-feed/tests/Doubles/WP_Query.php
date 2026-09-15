<?php

/**
 * Recording `WP_Query` double, so a test can pin the SHAPE of a query without a
 * database.
 *
 * $wp_query_calls collects every args array in order; $wp_query_posts is what the next
 * instance hands back and $wp_query_found what it reports as the total. Only what the
 * badge store reads is implemented.
 *
 * Global, not namespaced: SBR_Trust_Badge_Store resolves WP_Query at the root. In its
 * own file for the same reason FeedCacheWpdbDouble is (PSR1 — one class per file).
 */

if (! class_exists('WP_Query')) {
	class WP_Query
	{
		/** @var array<int, mixed> */
		public $posts = array();

		/** @var int */
		public $found_posts = 0;

		/**
		 * @param array<string, mixed> $args Query args.
		 */
		public function __construct($args = array())
		{
			global $wp_query_calls, $wp_query_posts, $wp_query_found;

			if (! is_array($wp_query_calls)) {
				$wp_query_calls = array();
			}

			$wp_query_calls[] = $args;

			$this->posts = is_array($wp_query_posts) ? $wp_query_posts : array();

			// A per-call queue, so a test can give the page fetch and the recount
			// DIFFERENT answers — which is what WP really does for a page past the
			// end (found_posts 0, then the recount finds 20). Without it the
			// out-of-range test could only assert the value the defect returned.
			// Falls back to $wp_query_found, so every existing caller is unchanged.
			global $wp_query_found_queue;

			if (is_array($wp_query_found_queue) && $wp_query_found_queue !== array()) {
				$this->found_posts = (int) array_shift($wp_query_found_queue);

				return;
			}

			$this->found_posts = isset($wp_query_found) ? (int) $wp_query_found : count($this->posts);
		}
	}
}
