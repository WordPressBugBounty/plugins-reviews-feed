<?php

/**
 * SB_Analytics plugin integration for Reviews.
 *
 * Implements the filter callbacks the sb-analytics dashboard
 * dispatches when it needs Reviews-side data: feed list, profile
 * details for a given feed, top-post hydration by review id, and
 * the read-only source/summary/feed/recent-review datasets consumed
 * by the Reviews analytics view.
 */

namespace SmashBalloon\Reviews\Common\Integrations\Analytics;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\Helpers\Data_Encryption;
use SmashBalloon\Reviews\Common\PostAggregator;

class SB_Analytics
{
	/**
	 * Providers whose per-review source URL is always the catalog/landing
	 * page rather than a review permalink (wordpress.org stores the plugin
	 * page on every review; Woo/EDD reviews live on the product page).
	 *
	 * @var string[]
	 */
	private const NO_PERMALINK_PROVIDERS = ['wordpress.org', 'woocommerce', 'edd'];

	/**
	 * Plugin slug used by sb-analytics to identify this integration.
	 *
	 * @var string
	 */
	private static $current_plugin = 'reviews';

	/**
	 * Friendly label rendered for the plugin in the analytics UI.
	 *
	 * @var string
	 */
	private static $current_plugin_label = 'Reviews';

	/**
	 * Register the filter hooks consumed by sb-analytics.
	 */
	public function register()
	{
		add_filter('sb_analytics_filter_feed_list', [$this, 'filter_feed_list'], 10, 2);
		add_filter('sb_analytics_filter_profile_details', [$this, 'filter_profile_details'], 10, 3);
		add_filter('sb_analytics_filter_top_posts', [$this, 'filter_top_posts'], 10, 3);
		add_filter('sb_analytics_reviews_sources', [$this, 'filter_reviews_sources']);
		add_filter('sb_analytics_reviews_summary', [$this, 'filter_reviews_summary']);
		add_filter('sb_analytics_reviews_feeds', [$this, 'filter_reviews_feeds']);
		add_filter('sb_analytics_reviews_recent', [$this, 'filter_reviews_recent'], 10, 2);
	}

	/**
	 * Return the list of Reviews feeds shaped for the analytics feed picker.
	 *
	 * @param array  $feeds       Existing feeds list passed in by sb-analytics.
	 * @param string $plugin_slug The plugin slug being requested.
	 *
	 * @return array
	 */
	public function filter_feed_list($feeds, $plugin_slug)
	{
		if ($plugin_slug !== self::$current_plugin) {
			return $feeds;
		}

		return $this->get_all_feeds();
	}

	/**
	 * Return collapsed feed-level profile details for a single Reviews feed.
	 *
	 * Reviews feeds may carry multiple sources; we surface a single profile
	 * that represents the feed itself, using the first source's avatar/image
	 * when available.
	 *
	 * @param array  $profile     Existing profile passed in by sb-analytics.
	 * @param int    $feed_id     Feed id to resolve.
	 * @param string $plugin_slug The plugin slug being requested.
	 *
	 * @return array
	 */
	public function filter_profile_details($profile, $feed_id, $plugin_slug)
	{
		if ($plugin_slug !== self::$current_plugin) {
			return $profile;
		}

		return $this->get_feed_profile($feed_id);
	}

	/**
	 * Hydrate a list of review post ids into the shape the analytics
	 * dashboard renders in its top-posts widget.
	 *
	 * @param array  $posts       Existing posts passed in by sb-analytics.
	 * @param array  $post_ids    Review post_id values to hydrate.
	 * @param string $plugin_slug The plugin slug being requested.
	 *
	 * @return array
	 */
	public function filter_top_posts($posts, $post_ids, $plugin_slug)
	{
		if ($plugin_slug !== self::$current_plugin) {
			return $posts;
		}

		if (empty($post_ids)) {
			return [];
		}

		return $this->get_posts_by_ids($post_ids);
	}

	/**
	 * Per-request cache of the shaped source records, so the sources and
	 * summary filters firing in the same page load share one query.
	 *
	 * @var array|null
	 */
	private $source_records = null;

	/**
	 * Per-request cache of the raw sbr_sources rows.
	 *
	 * @var array|null
	 */
	private $source_rows = null;

	/**
	 * Per-request cache of the grouped per-provider_id post stats.
	 *
	 * @var array|null
	 */
	private $provider_post_stats = null;

	/**
	 * Return every Reviews source shaped for the analytics sources panel.
	 *
	 * @param array $sources Existing sources passed in by sb-analytics.
	 *
	 * @return array
	 */
	public function filter_reviews_sources($sources)
	{
		return $this->get_source_records();
	}

	/**
	 * Fetch and shape the source records backing the sources panel and the
	 * summary's weighted aggregate. Memoized for the request.
	 *
	 * @return array
	 */
	private function get_source_records()
	{
		if ($this->source_records !== null) {
			return $this->source_records;
		}

		$records = [];
		$stats   = $this->get_provider_post_stats();

		foreach ($this->get_source_rows() as $row) {
			$info = !empty($row['info']) ? json_decode($row['info'], true) : [];
			$info = is_array($info) ? $info : [];

			// Same key coalescing as the feed header aggregate: EDD/Woo
			// store review_count/average_rating, the API providers store
			// total_rating/rating.
			$rating = (float) ($info['rating'] ?? $info['average_rating'] ?? 0);
			$count  = (int) ($info['review_count'] ?? $info['total_rating'] ?? 0);

			// Empty-string or zero aggregates mean "not provided" — a
			// source with reviews never reports 0 — so those fall back to
			// the stored rows instead of reporting a skippable 0.
			$live = $stats[(string) ($row['account_id'] ?? '')] ?? ['rating' => 0.0, 'count' => 0, 'newest' => null];
			if ($rating <= 0) {
				$rating = $live['rating'];
			}
			if ($count <= 0) {
				$count = $live['count'];
			}

			$records[] = [
				'account_id'         => !empty($row['account_id']) ? (string) $row['account_id'] : '',
				'provider'           => !empty($row['provider']) ? $row['provider'] : '',
				'name'               => !empty($row['name']) ? $row['name'] : '',
				'rating'             => (float) $rating,
				'count'              => (int) $count,
				'newest_review_date' => $live['newest'],
			];
		}

		$this->source_records = $records;

		return $this->source_records;
	}

	/**
	 * Raw sbr_sources rows, memoized for the request.
	 *
	 * @return array
	 */
	private function get_source_rows()
	{
		global $wpdb;

		if ($this->source_rows !== null) {
			return $this->source_rows;
		}

		$sources_table = $wpdb->prefix . 'sbr_sources';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, no user input.
		$rows = $wpdb->get_results("SELECT account_id, provider, name, info FROM {$sources_table}", ARRAY_A);

		$this->source_rows = is_array($rows) ? $rows : [];

		return $this->source_rows;
	}

	/**
	 * Stored-row stats per provider_id in one grouped pass: distinct review
	 * count, average of the valid (1–5) ratings, and newest review date.
	 *
	 * One GROUP BY query replaces the per-source correlated MAX() subquery
	 * and the per-source COUNT/AVG fallback query — no index leads on
	 * provider_id, so each of those was a full table scan per source.
	 *
	 * A derived table dedupes to one row per (provider_id, post_id) first,
	 * so WPML language duplicates (lang is part of the unique review key)
	 * weigh once in both the count and the CASE'd AVG; the CASE keeps
	 * unrated rows (rating 0 is storable) from deflating the fallback average.
	 *
	 * @return array Map of provider_id => { rating: float, count: int, newest: string|null }.
	 */
	private function get_provider_post_stats()
	{
		global $wpdb;

		if ($this->provider_post_stats !== null) {
			return $this->provider_post_stats;
		}

		$posts_table = $wpdb->prefix . 'sbr_reviews_posts';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, no user input.
		$rows = $wpdb->get_results(
			"SELECT provider_id,
				COUNT(*) AS cnt,
				AVG(CASE WHEN rating BETWEEN 1 AND 5 THEN rating END) AS avg_rating,
				MAX(time_stamp) AS newest_review_date
			FROM (
				SELECT provider_id, post_id,
					MAX(rating) AS rating,
					MAX(time_stamp) AS time_stamp
				FROM {$posts_table}
				GROUP BY provider_id, post_id
			) AS d
			GROUP BY provider_id",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$stats = [];
		foreach ((array) $rows as $row) {
			if (!isset($row['provider_id'])) {
				continue;
			}
			$stats[(string) $row['provider_id']] = [
				'count'  => (int) $row['cnt'],
				'rating' => $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 1) : 0.0,
				'newest' => !empty($row['newest_review_date']) ? $row['newest_review_date'] : null,
			];
		}

		$this->provider_post_stats = $stats;

		return $this->provider_post_stats;
	}

	/**
	 * Return the aggregate rating summary.
	 *
	 * The headline rating/total is the weighted average of each source's
	 * OFFICIAL aggregate (info.rating × total_rating) — the same math the
	 * feed header's compute_feed_level_aggregate() uses — so the analytics
	 * Reputation card agrees with the sources table and with what the feed
	 * itself displays, instead of averaging only the reviews cached
	 * locally. The star distribution and freshness buckets still come from
	 * the stored rows (providers publish no per-star breakdown), and the
	 * stored-rows average remains the fallback when no source carries an
	 * aggregate.
	 *
	 * Because `total` sums official aggregates while `distribution`/`buckets`
	 * count locally cached rows, they use different denominators — the
	 * payload carries `distribution_total` (the deduped stored-row count)
	 * so consumers can normalise star bars and buckets against the right
	 * base instead of `total`.
	 *
	 * Note the two stored-row counts have different bases: `buckets` count ALL
	 * cached rows by age (any rating, including unrated 0), while
	 * `distribution`/`distribution_total` count only rows rated 1–5.
	 *
	 * @param array $summary Existing summary passed in by sb-analytics.
	 *
	 * @return array
	 */
	public function filter_reviews_summary($summary)
	{
		global $wpdb;

		$posts_table = $wpdb->prefix . 'sbr_reviews_posts';

		$result = [
			'rating'             => 0.0,
			'total'              => 0,
			'distribution'       => ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0],
			'distribution_total' => 0,
			'buckets'            => ['le7' => 0, 'd8_14' => 0, 'd15_30' => 0, 'gt30' => 0],
		];

		// lang is part of the unique review key (a translated review is
		// stored once per language), so counts are over DISTINCT
		// (provider_id, post_id) to keep multilingual sites from
		// multiplying every number by the language count.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, no user input.
		$distribution_rows = $wpdb->get_results(
			"SELECT rating, COUNT(DISTINCT provider_id, post_id) AS cnt FROM {$posts_table} GROUP BY rating",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$stored_total = 0;
		$weighted_sum = 0;
		if (!empty($distribution_rows) && is_array($distribution_rows)) {
			foreach ($distribution_rows as $row) {
				$rating = (int) round((float) $row['rating']);
				$cnt    = (int) $row['cnt'];

				// Unrated rows (rating 0 is storable) stay out of both the
				// total and the sum, or they'd deflate the fallback average.
				if ($rating >= 1 && $rating <= 5) {
					$stored_total                             += $cnt;
					$result['distribution'][(string) $rating] += $cnt;
					$weighted_sum                             += $rating * $cnt;
				}
			}
		}

		$result['distribution_total'] = $stored_total;

		list($source_total, $source_sum) = $this->get_sources_official_aggregate();

		if ($source_total > 0) {
			$result['total']  = $source_total;
			$result['rating'] = round($source_sum / $source_total, 1);
		} elseif ($stored_total > 0) {
			$result['total']  = $stored_total;
			$result['rating'] = round($weighted_sum / $stored_total, 1);
		}

		$now = gmdate('Y-m-d H:i:s');

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, values prepared.
		$bucket_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(DISTINCT CASE WHEN DATEDIFF(%s, time_stamp) <= 7 THEN CONCAT_WS('|', provider_id, post_id) END) AS le7,
					COUNT(DISTINCT CASE WHEN DATEDIFF(%s, time_stamp) BETWEEN 8 AND 14 THEN CONCAT_WS('|', provider_id, post_id) END) AS d8_14,
					COUNT(DISTINCT CASE WHEN DATEDIFF(%s, time_stamp) BETWEEN 15 AND 30 THEN CONCAT_WS('|', provider_id, post_id) END) AS d15_30,
					COUNT(DISTINCT CASE WHEN DATEDIFF(%s, time_stamp) > 30 THEN CONCAT_WS('|', provider_id, post_id) END) AS gt30
				FROM {$posts_table}",
				$now,
				$now,
				$now,
				$now
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if (!empty($bucket_row) && is_array($bucket_row)) {
			$result['buckets'] = [
				'le7'    => (int) ($bucket_row['le7'] ?? 0),
				'd8_14'  => (int) ($bucket_row['d8_14'] ?? 0),
				'd15_30' => (int) ($bucket_row['d15_30'] ?? 0),
				'gt30'   => (int) ($bucket_row['gt30'] ?? 0),
			];
		}

		return $result;
	}

	/**
	 * Weighted total/sum of the sources' official aggregates.
	 *
	 * Provider groups that share an entity space are deduped through
	 * Feed::compute_provider_aggregate() — EDD/Woo sources can list
	 * overlapping downloads/products, and summing per source would count
	 * those twice (SMASH-1412). Providers without a dedup hook fall back
	 * to the per-source sum, which is correct for independent businesses.
	 *
	 * @return array{0:int,1:float} [ total review count, sum of rating × count ].
	 */
	private function get_sources_official_aggregate()
	{
		$rows_by_provider = [];
		foreach ($this->get_source_rows() as $row) {
			$provider                      = !empty($row['provider']) ? (string) $row['provider'] : '';
			$rows_by_provider[$provider][] = $row;
		}

		$records_by_provider = [];
		foreach ($this->get_source_records() as $record) {
			$records_by_provider[$record['provider']][] = $record;
		}

		$total = 0;
		$sum   = 0.0;

		foreach ($records_by_provider as $provider => $provider_records) {
			$agg = Feed::compute_provider_aggregate((string) $provider, $rows_by_provider[$provider] ?? []);

			if ($agg !== null && $agg['count'] > 0) {
				$total += (int) $agg['count'];
				$sum   += (float) $agg['rating'] * (int) $agg['count'];
				continue;
			}

			foreach ($provider_records as $record) {
				$count  = isset($record['count']) ? (int) $record['count'] : 0;
				$rating = isset($record['rating']) ? (float) $record['rating'] : 0.0;
				if ($count > 0 && $rating > 0) {
					$total += $count;
					$sum   += $rating * $count;
				}
			}
		}

		return [$total, $sum];
	}

	/**
	 * Return every Reviews feed with the provider slugs of its sources.
	 *
	 * @param array $feeds Existing feeds passed in by sb-analytics.
	 *
	 * @return array
	 */
	public function filter_reviews_feeds($feeds)
	{
		$feed_rows = $this->get_all_feed_rows();

		if (empty($feed_rows)) {
			return [];
		}

		$provider_map = $this->get_account_provider_map();

		$records = [];

		foreach ($feed_rows as $feed) {
			if (empty($feed['id'])) {
				continue;
			}

			$feed_source_ids = $this->get_feed_source_ids($feed);

			$providers = [];
			foreach ($feed_source_ids as $source_id) {
				if (isset($provider_map[$source_id]) && !in_array($provider_map[$source_id], $providers, true)) {
					$providers[] = $provider_map[$source_id];
				}
			}

			$records[] = [
				'feed_id'    => (int) $feed['id'],
				'name'       => !empty($feed['feed_name']) ? $feed['feed_name'] : '',
				'sources'    => $providers,
				// Source identity (account_id), not collapsed to provider
				// slug — lets the analytics bridge match a specific source
				// against this feed instead of matching by provider, which
				// credits every same-provider source with every feed any one
				// of them appears in.
				'source_ids' => $feed_source_ids,
			];
		}

		return $records;
	}

	/**
	 * Return the newest N reviews PER SOURCE, shaped for the analytics
	 * recent-reviews list.
	 *
	 * @param array $default Existing value passed in by sb-analytics.
	 * @param array $args    Optional args; supports 'limit' (1-100, default 20)
	 *                       — applied per source, not across all sources.
	 *
	 * @return array
	 */
	public function filter_reviews_recent($default, $args = [])
	{
		global $wpdb;

		$posts_table = $wpdb->prefix . 'sbr_reviews_posts';
		$limit       = max(1, min(100, (int) ($args['limit'] ?? 20)));

		// Newest reviews are pulled PER SOURCE, not globally. A single global
		// "newest $limit" is dominated by the highest-volume source, so a
		// quieter source — e.g. a Google business whose latest review is a
		// month old, sitting behind a busier Yelp source — never lands in the
		// list at all. Filtering the dashboard to that source then shows
		// nothing, even though its reviews plainly exist on the feed. Fetching
		// $limit per source guarantees every source the dashboard lists has
		// its most-recent reviews present for the client-side source filter.
		//
		// lang is part of the unique review key, so a review translated into
		// N languages is stored N times; over-fetch (× 3) per source and
		// dedupe down to $limit. No window functions (ROW_NUMBER() OVER) — WP
		// still supports MySQL 5.6 / MariaDB 10.0, where they don't exist — so
		// this is a bounded loop of one small query per source. Sources are
		// platform accounts (a handful), never per-review, so the loop count
		// is small regardless of total review volume.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, values prepared.
		$source_ids = $wpdb->get_col(
			"SELECT DISTINCT COALESCE(provider_id, '') FROM {$posts_table}"
		);

		$rows = [];
		foreach ((array) $source_ids as $source_id) {
			$src_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, provider, provider_id, rating, time_stamp, post_content, json_data
					FROM {$posts_table} WHERE COALESCE(provider_id, '') = %s ORDER BY time_stamp DESC LIMIT %d",
					(string) $source_id,
					$limit * 3
				),
				ARRAY_A
			);

			if (empty($src_rows) || !is_array($src_rows)) {
				continue;
			}

			$seen = [];
			foreach ($src_rows as $row) {
				$key = ($row['provider_id'] ?? '') . '|' . ($row['post_id'] ?? '');
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$rows[]     = $row;
				if (count($seen) === $limit) {
					break;
				}
			}
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if (empty($rows)) {
			return [];
		}

		// Merge the per-source buckets newest-first, so the default
		// "All sources" view still leads with the most recent reviews across
		// the whole site — only the per-source filter relies on the buckets.
		usort(
			$rows,
			static function ($a, $b) {
				return strcmp(
					(string) ($b['time_stamp'] ?? ''),
					(string) ($a['time_stamp'] ?? '')
				);
			}
		);

		$provider_ids = [];
		foreach ($rows as $row) {
			if (!empty($row['provider_id']) && !in_array($row['provider_id'], $provider_ids, true)) {
				$provider_ids[] = $row['provider_id'];
			}
		}

		$encryption  = new Data_Encryption();
		$feed_id_map = $this->feeds_for_provider_ids($provider_ids);

		$records = [];
		foreach ($rows as $row) {
			$records[] = $this->shape_recent_review_row($row, $encryption, $feed_id_map);
		}

		return $this->strip_landing_page_urls($records, $provider_ids);
	}

	/**
	 * Shape one raw posts row into the recent-reviews record contract.
	 *
	 * @param array           $row         Raw posts row.
	 * @param Data_Encryption $encryption  Decryptor for Facebook rows.
	 * @param array           $feed_id_map Map of provider_id => int[] feed ids.
	 *
	 * @return array
	 */
	private function shape_recent_review_row($row, $encryption, $feed_id_map)
	{
		$post_content = $row['post_content'] ?? '';
		$json_data    = $row['json_data'] ?? '';

		if (($row['provider'] ?? '') === 'facebook') {
			$post_content = $encryption->maybe_decrypt($post_content);
			$json_data    = $encryption->maybe_decrypt($json_data);
		}

		$decoded = !empty($json_data) ? json_decode($json_data, true) : [];
		$decoded = is_array($decoded) ? $decoded : [];

		$rating = $row['rating'] ?? 0;
		if (!is_numeric($rating)) {
			$rating = $rating === 'negative' ? 1 : 5;
		}

		$text = $post_content !== '' ? $post_content : ($decoded['text'] ?? '');

		$feed_ids = $feed_id_map[$row['provider_id'] ?? ''] ?? [];

		return [
			'id'                 => (string) ($row['post_id'] ?? ''),
			'author'             => $decoded['reviewer']['name'] ?? '',
			// Both URLs come from provider-supplied JSON and render straight
			// into href/src on the wp-admin dashboard (React doesn't strip a
			// javascript: scheme), so run them through esc_url_raw().
			'avatar'             => !empty($decoded['reviewer']['avatar']) ? esc_url_raw($decoded['reviewer']['avatar']) : null,
			'rating'             => (int) round((float) $rating),
			'text'               => $this->make_text_snippet($text),
			'provider'           => $row['provider'] ?? '',
			'date'               => $row['time_stamp'] ?? '',
			'source_url'         => !empty($decoded['source']['url']) ? esc_url_raw($decoded['source']['url']) : null,
			'sources_feed_count' => count($feed_ids),
			'feed_ids'           => $feed_ids,
		];
	}

	/**
	 * Null out source_url values that are really the source's landing page
	 * rather than a review permalink — "Open on {source}" would not open
	 * the review, so the dashboard disables the link instead.
	 *
	 * Two deterministic signals: providers that never issue per-review
	 * permalinks (wordpress.org stores the plugin page on every review,
	 * Woo/EDD the product page), and the URL matching the source's own
	 * info.url. Both sides of the comparison are untrailingslashit'd.
	 *
	 * @param array    $records      Shaped recent-review records.
	 * @param string[] $provider_ids Distinct posts.provider_id values.
	 *
	 * @return array
	 */
	private function strip_landing_page_urls($records, $provider_ids)
	{
		$landing_urls = $this->get_source_landing_urls($provider_ids);

		foreach ($records as $i => $record) {
			if (empty($record['source_url'])) {
				continue;
			}

			if (in_array($record['provider'], self::NO_PERMALINK_PROVIDERS, true)) {
				$records[$i]['source_url'] = null;
				continue;
			}

			if (in_array(untrailingslashit($record['source_url']), $landing_urls, true)) {
				$records[$i]['source_url'] = null;
			}
		}

		return $records;
	}

	/**
	 * The landing-page URL each source reports about itself (info.url),
	 * untrailingslashed for comparison.
	 *
	 * @param string[] $provider_ids Distinct posts.provider_id values.
	 *
	 * @return string[]
	 */
	private function get_source_landing_urls($provider_ids)
	{
		global $wpdb;

		if (empty($provider_ids)) {
			return [];
		}

		$sources_table = $wpdb->prefix . 'sbr_sources';
		$placeholders  = implode(',', array_fill(0, count($provider_ids), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, ids prepared via dynamic IN() placeholders.
		$rows = $wpdb->get_col(
			$wpdb->prepare("SELECT info FROM {$sources_table} WHERE account_id IN ({$placeholders})", $provider_ids)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$urls = [];
		foreach ((array) $rows as $info) {
			$decoded = !empty($info) ? json_decode($info, true) : [];
			if (is_array($decoded) && !empty($decoded['url'])) {
				$urls[] = untrailingslashit((string) $decoded['url']);
			}
		}

		return $urls;
	}

	/**
	 * Build the analytics-shaped feed list from Reviews' DB.
	 *
	 * @return array
	 */
	private function get_all_feeds()
	{
		$records = [];

		$feeds = $this->get_all_feed_rows();

		if (empty($feeds)) {
			return $records;
		}

		foreach ($feeds as $feed) {
			if (empty($feed['id'])) {
				continue;
			}

			$records[] = [
				'value'  => [
					'feed_id'    => (int) $feed['id'],
					'pluginSlug' => self::$current_plugin,
				],
				'label'  => !empty($feed['feed_name']) ? $feed['feed_name'] : '',
				'plugin' => [
					'slug'  => self::$current_plugin,
					'label' => self::$current_plugin_label,
				],
			];
		}

		return $records;
	}

	/**
	 * Resolve a single feed's profile, using the first source's image as the
	 * profile avatar when available.
	 *
	 * @param int $feed_id Feed id to resolve.
	 *
	 * @return array
	 */
	private function get_feed_profile($feed_id)
	{
		if (empty($feed_id)) {
			return [];
		}

		$db_instance = new DB();
		$feed_rows   = $db_instance->feeds_query(['id' => $feed_id]);

		if (empty($feed_rows) || !is_array($feed_rows)) {
			return [];
		}

		$feed = $feed_rows[0];

		$feed_name = !empty($feed['feed_name']) ? $feed['feed_name'] : '';
		$settings  = !empty($feed['settings']) ? json_decode($feed['settings'], true) : [];

		$source_ids_raw = '';
		if (is_array($settings) && !empty($settings['sources'])) {
			$source_ids_raw = $settings['sources'];
		}

		$image_src = '';

		if (!empty($source_ids_raw)) {
			$sources = SBR_Sources::get_sources_list(['id' => $source_ids_raw]);

			if (!empty($sources) && is_array($sources)) {
				$first_source = $sources[0];
				$info         = !empty($first_source['info']) ? json_decode($first_source['info'], true) : [];

				if (is_array($info)) {
					if (!empty($info['avatar'])) {
						$image_src = $info['avatar'];
					} elseif (!empty($info['image'])) {
						$image_src = $info['image'];
					}
				}
			}
		}

		return [
			'id'         => 'feed_' . $feed_id,
			'pluginSlug' => self::$current_plugin,
			'profile'    => [
				'label'    => $feed_name,
				'imageSrc' => $image_src,
			],
		];
	}

	/**
	 * Hydrate review posts by id into the analytics top-posts shape.
	 *
	 * @param array $post_ids Review post_id values.
	 *
	 * @return array
	 */
	private function get_posts_by_ids($post_ids)
	{
		$records = [];

		$rows = PostAggregator::get_list_reviews_by_ids($post_ids);

		if (empty($rows) || !is_array($rows)) {
			return $records;
		}

		$aggregator   = new PostAggregator();
		$normalized   = $aggregator->normalize_db_post_set($rows);
		$row_index    = $this->index_rows_by_post_id($rows);
		$feed_id_lookup = $this->build_provider_to_feed_lookup($rows);

		foreach ($normalized as $review) {
			if (empty($review) || !is_array($review)) {
				continue;
			}

			$source_id   = $review['source']['id'] ?? '';
			$review_id   = $review['review_id'] ?? '';
			$matched_row = !empty($review_id) && isset($row_index[$review_id]) ? $row_index[$review_id] : null;

			$post_id = '';
			if (!empty($matched_row['post_id'])) {
				$post_id = $matched_row['post_id'];
			} elseif (!empty($review_id)) {
				$post_id = $review_id;
			}

			if ($post_id === '') {
				continue;
			}

			$image_src        = $this->pick_review_image($review);
			$updated_time_ago = $this->format_review_timestamp($matched_row, $review);

			$feed_id = '';
			if (!empty($source_id) && isset($feed_id_lookup[$source_id])) {
				$feed_id = $feed_id_lookup[$source_id];
			}

			$records[$post_id] = [
				'plugin'           => [
					'slug' => self::$current_plugin,
				],
				'feed_id'          => $feed_id,
				'feed_post_id'     => $post_id,
				'text'             => $review['text'] ?? '',
				'imageSrc'         => $image_src,
				'updated_time_ago' => $updated_time_ago,
				'profile'          => [
					'label' => $review['reviewer']['name'] ?? '',
					'url'   => $review['source']['url'] ?? '',
					'id'    => $source_id,
				],
			];
		}

		return $records;
	}

	/**
	 * Resolve the best available image for a normalized review.
	 *
	 * Prefers the first media item (object `url`/`original`, or a bare string URL)
	 * and falls back to the reviewer avatar.
	 *
	 * @param array $review Normalized review.
	 *
	 * @return string Image URL, or '' when none is available.
	 */
	private function pick_review_image($review)
	{
		$image_src = '';

		if (!empty($review['media'][0])) {
			$first_media = $review['media'][0];
			if (is_array($first_media)) {
				$image_src = $first_media['url'] ?? ($first_media['original'] ?? '');
			} elseif (is_string($first_media)) {
				$image_src = $first_media;
			}
		}

		if (empty($image_src) && !empty($review['reviewer']['avatar'])) {
			$image_src = $review['reviewer']['avatar'];
		}

		return $image_src;
	}

	/**
	 * Build the localized "X ago" string for a review.
	 *
	 * Resolves the timestamp from the raw row first, then the normalized review's
	 * `time_stamp`/`time` fields, then formats it relative to now.
	 *
	 * @param array|null $matched_row Raw db row paired with the review, if any.
	 * @param array      $review      Normalized review.
	 *
	 * @return string Localized relative time, or '' when no timestamp is available.
	 */
	private function format_review_timestamp($matched_row, $review)
	{
		$time_stamp = '';
		if (!empty($matched_row['time_stamp'])) {
			$time_stamp = $matched_row['time_stamp'];
		} elseif (!empty($review['time_stamp'])) {
			$time_stamp = $review['time_stamp'];
		} elseif (!empty($review['time'])) {
			$time_stamp = is_numeric($review['time']) ? gmdate('Y-m-d H:i:s', (int) $review['time']) : $review['time'];
		}

		if (empty($time_stamp)) {
			return '';
		}

		$ts = strtotime($time_stamp);
		if (!$ts) {
			return '';
		}

		return sprintf(
			/* translators: %s: human-readable time difference, e.g. "5 minutes" */
			__('%s ago', 'reviews-feed'),
			human_time_diff($ts, time())
		);
	}

	/**
	 * Build a lookup of normalized review review_id => raw db row for
	 * pulling fields (time_stamp, post_id) that the JSON payload omits.
	 *
	 * @param array $rows Raw rows from PostAggregator::get_list_reviews_by_ids().
	 *
	 * @return array
	 */
	private function index_rows_by_post_id($rows)
	{
		$index = [];

		foreach ($rows as $row) {
			if (empty($row['json_data'])) {
				continue;
			}
			$decoded = json_decode($row['json_data'], true);
			if (empty($decoded) || !is_array($decoded)) {
				continue;
			}
			$key = $decoded['review_id'] ?? ($row['post_id'] ?? '');
			if ($key === '') {
				continue;
			}
			$index[$key] = $row;
		}

		return $index;
	}

	/**
	 * Best-effort map of provider_id (source account id) => owning feed id.
	 *
	 * Reviews does not store a feed_id column on posts, so we look at which
	 * feed's settings list each provider_id as a source. Feeds are fetched
	 * once and matched in PHP; falls back to empty when nothing matches.
	 *
	 * @param array $rows Raw posts rows.
	 *
	 * @return array
	 */
	private function build_provider_to_feed_lookup($rows)
	{
		$provider_ids = [];
		foreach ($rows as $row) {
			if (!empty($row['provider_id']) && !in_array($row['provider_id'], $provider_ids, true)) {
				$provider_ids[] = $row['provider_id'];
			}
		}

		if (empty($provider_ids)) {
			return [];
		}

		// Bulk-fetch all feeds once and map provider_id => feed_id in PHP, instead of one
		// LIKE scan per provider_id (N+1) which also risked substring false positives
		// (e.g. provider "5" matching a feed sourcing "15" or "50").
		$all_feeds = $this->get_all_feed_rows();

		$lookup = [];
		foreach ($all_feeds as $feed) {
			$feed_source_ids = $this->get_feed_source_ids($feed);
			foreach ($provider_ids as $pid) {
				if (!isset($lookup[$pid]) && in_array((string) $pid, $feed_source_ids, true)) {
					$lookup[$pid] = (int) $feed['id'];
				}
			}
		}

		return $lookup;
	}

	/**
	 * Build a map of source account_id => provider slug from the sources table.
	 *
	 * @return array
	 */
	private function get_account_provider_map()
	{
		global $wpdb;

		$sources_table = $wpdb->prefix . 'sbr_sources';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, no user input.
		$rows = $wpdb->get_results("SELECT account_id, provider FROM {$sources_table}", ARRAY_A);

		$map = [];

		if (!empty($rows) && is_array($rows)) {
			foreach ($rows as $row) {
				if (!empty($row['account_id'])) {
					$map[(string) $row['account_id']] = $row['provider'] ?? '';
				}
			}
		}

		return $map;
	}

	/**
	 * Extract a feed row's source ids as an array of strings.
	 *
	 * Feed settings store 'sources' as either an array or a CSV string; both
	 * are normalized to string ids so callers can strict-compare.
	 *
	 * @param array $feed Raw feed row (id, feed_name, settings).
	 *
	 * @return string[]
	 */
	private function get_feed_source_ids($feed)
	{
		$settings = !empty($feed['settings']) ? json_decode($feed['settings'], true) : [];
		$sources  = (is_array($settings) && !empty($settings['sources'])) ? $settings['sources'] : '';

		if (empty($sources)) {
			return [];
		}

		$source_ids = is_array($sources) ? $sources : explode(',', $sources);

		return array_map('strval', $source_ids);
	}

	/**
	 * List the feeds that include each given provider_id as a source.
	 *
	 * @param string[] $provider_ids Distinct posts.provider_id values.
	 *
	 * @return array Map of provider_id => int[] feed ids, ordered by feed id ASC.
	 */
	private function feeds_for_provider_ids($provider_ids)
	{
		$feed_ids = [];

		if (empty($provider_ids)) {
			return $feed_ids;
		}

		foreach ($provider_ids as $pid) {
			$feed_ids[$pid] = [];
		}

		foreach ($this->get_all_feed_rows() as $feed) {
			$feed_source_ids = $this->get_feed_source_ids($feed);
			foreach ($provider_ids as $pid) {
				if (in_array((string) $pid, $feed_source_ids, true)) {
					$feed_ids[$pid][] = (int) $feed['id'];
				}
			}
		}

		return $feed_ids;
	}

	/**
	 * Reduce review text to a plain-text snippet of at most 200 characters.
	 *
	 * @param string $text Raw review text (may contain markup/whitespace).
	 *
	 * @return string
	 */
	private function make_text_snippet($text)
	{
		if (empty($text) || !is_string($text)) {
			return '';
		}

		$text = wp_strip_all_tags($text);

		// preg_replace() with /u returns null on malformed UTF-8 (provider
		// text truncated mid-multibyte); keep the uncollapsed text rather
		// than passing null to trim() and losing the snippet.
		$collapsed = preg_replace('/\s+/u', ' ', $text);
		$text      = trim($collapsed !== null ? $collapsed : $text);

		if (function_exists('mb_strlen') && function_exists('mb_substr')) {
			if (mb_strlen($text) > 200) {
				$text = rtrim(mb_substr($text, 0, 200)) . '…';
			}
			return $text;
		}

		if (strlen($text) > 200) {
			$text = rtrim(substr($text, 0, 200)) . '…';
		}

		return $text;
	}

	/**
	 * Fetch every Reviews feed row directly from the DB.
	 *
	 * Bypasses DB::get_feeds_list(), which early-returns when $_GET['feed_id'] is
	 * present and only returns the first page (20 rows) of feeds. The analytics
	 * filters run in request contexts where neither assumption is safe, so we read
	 * all feeds with a single unguarded query — mirroring CTF_Db::all_feeds_query()
	 * in the other plugins' analytics integrations. Rows are ordered by id ASC so
	 * the provider lookup keeps the original "lowest feed id wins" behaviour.
	 *
	 * @return array Raw feed rows (id, feed_name, settings).
	 */
	private function get_all_feed_rows()
	{
		global $wpdb;

		$feeds_table = $wpdb->prefix . 'sbr_feeds';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names only, no user input.
		$rows = $wpdb->get_results("SELECT id, feed_name, settings FROM {$feeds_table} ORDER BY id ASC", ARRAY_A);

		return is_array($rows) ? $rows : [];
	}
}
