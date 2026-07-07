<?php

/**
 * SB_Analytics plugin integration for Reviews.
 *
 * Implements the three filter callbacks the sb-analytics dashboard
 * dispatches when it needs Reviews-side data: feed list, profile
 * details for a given feed, and top-post hydration by review id.
 */

namespace SmashBalloon\Reviews\Common\Integrations\Analytics;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\PostAggregator;

class SB_Analytics
{
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
			$settings        = !empty($feed['settings']) ? json_decode($feed['settings'], true) : [];
			$sources         = (is_array($settings) && !empty($settings['sources'])) ? $settings['sources'] : '';
			$feed_source_ids = is_array($sources) ? $sources : explode(',', $sources);
			$feed_source_ids = array_map('strval', $feed_source_ids);
			foreach ($provider_ids as $pid) {
				if (!isset($lookup[$pid]) && in_array((string) $pid, $feed_source_ids, true)) {
					$lookup[$pid] = (int) $feed['id'];
				}
			}
		}

		return $lookup;
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

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table name only, no user input.
		$rows = $wpdb->get_results("SELECT id, feed_name, settings FROM {$feeds_table} ORDER BY id ASC", ARRAY_A);

		return is_array($rows) ? $rows : [];
	}
}
