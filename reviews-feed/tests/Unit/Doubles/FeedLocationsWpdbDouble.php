<?php

namespace SmashBalloon\Reviews\Tests\Unit\Doubles;

/**
 * Minimal `wpdb` double for the feed-location scan (SMASH-2103 / SMASH-2109).
 *
 * Scripts the queries `DB::get_all_feed_shortcode_locations()` issues so the
 * batching and ordering contract can be asserted without a live database.
 *
 * prepare() interpolates for real, because the batch query's `ID IN (...)` is
 * the only thing that tells this double which batch it is serving — a double
 * that ignored it would hand every row to every batch and the batching under
 * test would never happen. Each batch is sorted by post_date DESC, mirroring the
 * production query's own ORDER BY, so a test asserting "most recent first"
 * across batches is proving the production sort and not the fixture's order.
 *
 * Own file (PSR1), untyped properties (PHP 7.1+ baseline per phpcs.xml).
 */
class FeedLocationsWpdbDouble
{
	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $posts = 'wp_posts';

	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var string */
	public $last_error = '';

	/** @var array<int, array<string, mixed>> Post rows: ID, post_title, post_type, post_status, post_date, post_content. */
	public $rows = array();

	/** @var array<int, array<string, mixed>> postmeta rows: post_id, meta_value. */
	public $meta_rows = array();

	/** @var array<int, string> Every SQL string that reached get_col()/get_results(). */
	public $queries = array();

	/** @var int How many batch queries against wp_posts were served. */
	public $batch_count = 0;

	/** @var bool When true, get_results() reports a failed query, as wpdb does. */
	public $fail_on_results = false;

	/** @var bool When true, only the postmeta read fails. */
	public $fail_on_meta = false;

	/**
	 * @param string $text
	 * @return string
	 */
	public function esc_like($text)
	{
		return addcslashes((string) $text, '_%\\');
	}

	/**
	 * @param string $sql
	 * @param mixed  ...$args
	 * @return string
	 */
	public function prepare($sql, ...$args)
	{
		// Real wpdb accepts either a variadic list or one array; the scan uses both.
		if (count($args) === 1 && is_array($args[0])) {
			$args = $args[0];
		}

		$out   = '';
		$parts = preg_split('/(%[dsfF])/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);

		foreach ($parts as $part) {
			if (preg_match('/^%[dsfF]$/', $part) !== 1) {
				$out .= $part;
				continue;
			}

			$value = array_shift($args);
			$out  .= $part === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
		}

		return $out;
	}

	/**
	 * Candidate IDs. Both marker queries return every post in the fixture —
	 * narrowing is MySQL's job, and the parsing under test happens in pass two.
	 *
	 * @param string $sql
	 * @return array<int, int>
	 */
	public function get_col($sql)
	{
		$this->queries[] = $sql;

		if (false !== strpos($sql, $this->postmeta)) {
			$ids = array();
			foreach ($this->meta_rows as $meta_row) {
				$ids[] = (int) $meta_row['post_id'];
			}

			return array_values(array_unique($ids));
		}

		$ids = array();
		foreach ($this->rows as $row) {
			$ids[] = (int) $row['ID'];
		}

		return $ids;
	}

	/**
	 * @param string $sql
	 * @param mixed  $output
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results($sql, $output = null)
	{
		$this->queries[] = $sql;

		if ($this->fail_on_results) {
			// Real wpdb answers array() and leaves last_error set, which is exactly
			// why an empty result cannot be read as "nothing matched".
			$this->last_error = 'MySQL server has gone away';

			return array();
		}

		$ids = $this->ids_in_where($sql);

		if (false !== strpos($sql, $this->postmeta)) {
			if ($this->fail_on_meta) {
				$this->last_error = 'MySQL server has gone away';

				return array();
			}

			// No id list in the WHERE means the query is not narrowed by post — the
			// badge store's meta scan selects by meta_key alone — so every row is a
			// candidate. Filtering on an empty list would silently answer nothing.
			if (empty($ids)) {
				return $this->meta_rows;
			}

			$matched = array();
			foreach ($this->meta_rows as $meta_row) {
				$post_id = isset($meta_row['post_id']) ? $meta_row['post_id'] : ( isset($meta_row['ID']) ? $meta_row['ID'] : 0 );

				if (in_array((int) $post_id, $ids, true)) {
					$matched[] = $meta_row;
				}
			}

			return $matched;
		}

		$this->batch_count++;

		$matched = array();
		foreach ($this->rows as $row) {
			if (in_array((int) $row['ID'], $ids, true)) {
				$matched[] = $row;
			}
		}

		// Mirrors the production query's ORDER BY post_date DESC, which is per batch.
		usort(
			$matched,
			static function ($a, $b) {
				return strcmp((string) $b['post_date'], (string) $a['post_date']);
			}
		);

		return $matched;
	}

	/**
	 * @param string $sql
	 * @return array<int, int>
	 */
	private function ids_in_where($sql)
	{
		if (preg_match('/(?:ID|post_id) IN \(([^)]*)\)/', $sql, $m) !== 1) {
			return array();
		}

		return array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
	}
}
