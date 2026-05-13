<?php

namespace SmashBalloon\Reviews\Tests\Unit\Doubles;

/**
 * Minimal `wpdb` test double for FeedCacheUpdateServiceTest.
 *
 * Captures the SQL handed to prepare()/get_results() so the dedupe + ordering
 * contract on `FeedCacheUpdateService::feed_caches_query()` can be asserted
 * without a live database. Exists in its own file (PSR1 — one class per file)
 * and uses untyped properties (PHP 7.1+ baseline per phpcs.xml).
 */
class FeedCacheWpdbDouble
{
	/** @var string */
	public $prefix = 'wp_';

	/** @var string|null */
	public $last_prepared_sql = null;

	/** @var array<int, mixed> */
	public $last_prepared_args = array();

	/** @var string|null */
	public $last_get_results_sql = null;

	/** @var array<int, array<string, mixed>> */
	public $next_results = array();

	/**
	 * @param string $sql
	 * @param mixed  ...$args
	 * @return string
	 */
	public function prepare($sql, ...$args)
	{
		$this->last_prepared_sql  = $sql;
		$this->last_prepared_args = $args;
		// We don't need a real interpolation — the production code reads the
		// returned string and immediately hands it to get_results, which we
		// also intercept. Returning the raw template is enough for the
		// behavioral assertions in this suite.
		return $sql;
	}

	/**
	 * @param string $sql
	 * @param string $output_type
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results($sql, $output_type = 'OBJECT')
	{
		$this->last_get_results_sql = $sql;
		return $this->next_results;
	}
}
