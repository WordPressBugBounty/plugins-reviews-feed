<?php

namespace SmashBalloon\Reviews\Tests\Unit\Doubles;

/**
 * Minimal `wpdb` test double for the usage-tracking ReviewsReporter.
 *
 * get_var() answers the `SHOW TABLES LIKE %s` existence probe (every table
 * exists) and get_results() returns canned feed rows, so the reporter's
 * feed-data SQL and row-shape handling can be asserted without a live
 * database. One class per file per PSR1.
 */
class UsageReporterWpdbDouble
{
	/** @var string */
	public $prefix = 'wp_';

	/** @var array<int, array<string, mixed>> */
	public $next_results = array();

	/** @var string|null */
	public $last_get_results_sql = null;

	/** @var mixed Last value handed to prepare(), i.e. the probed table name. */
	public $last_prepared_arg = null;

	/** @var int Value returned for COUNT(*) queries. */
	public $next_count = 0;

	public function prepare($sql, ...$args)
	{
		$this->last_prepared_arg = $args[0] ?? null;
		return $sql;
	}

	public function get_var($sql)
	{
		// A COUNT(*) is a real scalar query, not the existence probe. Returning the
		// probed table name for it would cast to (int) 0 and quietly satisfy any
		// assertion on connected_count / feed_caches_count / reviews_count.
		if (false !== stripos((string) $sql, 'COUNT(')) {
			return $this->next_count;
		}

		// `SHOW TABLES LIKE` probe — report the probed table as existing.
		return $this->last_prepared_arg;
	}

	public function get_results($sql, $output_type = 'OBJECT')
	{
		$this->last_get_results_sql = $sql;
		return $this->next_results;
	}
}
