<?php

namespace SmashBalloon\Reviews\Tests\Unit\Doubles;

/**
 * `wpdb` double for Smash2409FailedSourceRetryTest: answers the three reads FailedSourceRetry makes (sources with an
 * error, feeds, posts cache rows) from arrays, and records update() calls.
 */
class FailedSourceRetryWpdbDouble
{
	/** @var string */
	public $prefix = 'wp_';

	/** @var array<int, array<string, mixed>> */
	public $sources = array();

	/** @var array<int, array<string, mixed>> */
	public $feeds = array();

	/** @var array<int, array<string, mixed>> */
	public $caches = array();

	/** @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}> */
	public $updates = array();

	/**
	 * @param string $sql
	 * @param mixed  $output
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results($sql, $output = null)
	{
		if (strpos($sql, 'sbr_sources') !== false) {
			return array_values(array_filter($this->sources, function ($row) {
				return (string) $row['error'] !== '';
			}));
		}
		if (strpos($sql, 'sbr_feed_caches') !== false) {
			// The real column is `cache_key` (DESCRIBE wp_sbr_feed_caches); any other filter matches nothing, as MySQL
			// would refuse it.
			if (strpos($sql, "cache_key = 'posts'") === false) {
				return array();
			}
			return array_values(array_filter($this->caches, function ($row) {
				return $row['cache_key'] === 'posts';
			}));
		}
		if (strpos($sql, 'sbr_feeds') !== false) {
			return $this->feeds;
		}

		return array();
	}

	/**
	 * @param string               $table
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @return int
	 */
	public function update($table, $data, $where, $format = null, $where_format = null)
	{
		$this->updates[] = array('table' => $table, 'data' => $data, 'where' => $where);

		return 1;
	}
}
