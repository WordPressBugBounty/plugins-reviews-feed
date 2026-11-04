<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\FeedCache;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * SMASH-2647: up to 2.16.1, relay master's refused key or place answer had its nested `error` map saved as a review (no
 * review id, no text), which visitors see as a blank card. 2.17.0 stops new ones (SMASH-2382, SMASH-2646); this
 * removes the ones already stored, once. Removed rows are kept in ROLLBACK_OPTION so they can be put back.
 *
 * One-shot via its own flag, not sbr_db_version (see HideCachedProviderFailuresRoutine).
 */
class RemoveBlankErrorReviewsRoutine extends ServiceProvider
{
	public const DONE_OPTION = 'sbr_remove_blank_error_reviews_done';
	public const ROLLBACK_OPTION = 'sbr_removed_blank_error_reviews';
	/** Rows read per query; the match is rare, so the loop normally runs once. */
	public const BATCH = 500;

	/** @return void */
	public function register()
	{
		if (!get_option(self::DONE_OPTION)) {
			// Marked done first, so a fatal mid-run can't re-fire it on every load.
			update_option(self::DONE_OPTION, time());
			if ($this->run() > 0) {
				SBR_Feed_Saver_Manager::clear_plugin_cache();
				$this->forget_feed_object_caches();
			}
		}
	}

	/**
	 * clear_plugin_cache() blanks the cache table, but FeedCache::query_feed_caches() reads the object cache first and
	 * that entry has no expiry (Redis, Memcached), so a cached blank card would outlive the row.
	 *
	 * @return void
	 */
	public function forget_feed_object_caches()
	{
		global $wpdb;
		$table = $wpdb->prefix . FeedCache::CACHES_TABLE_NAME;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own constant
		$feed_ids = $wpdb->get_col("SELECT DISTINCT feed_id FROM $table");
		if (!is_array($feed_ids)) {
			return;
		}
		foreach ($feed_ids as $feed_id) {
			wp_cache_delete(FeedCache::CACHE_KEY . $feed_id . '_');
		}
	}

	/**
	 * @return int How many rows were removed.
	 */
	public function run()
	{
		global $wpdb;
		$table = $wpdb->prefix . SBR_POSTS_TABLE;
		$removed = array();
		$last_id = 0;

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own constant
			$rows = $wpdb->get_results(
				$wpdb->prepare("SELECT id, post_id, json_data FROM $table WHERE post_id = %s AND id > %d ORDER BY id ASC LIMIT %d", '', $last_id, self::BATCH),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if (!is_array($rows)) {
				break;
			}
			foreach ($rows as $row) {
				$last_id = (int) $row['id'];
				if (self::is_blank_error_review((string) $row['post_id'], $row['json_data'])) {
					$wpdb->delete($table, array('id' => $last_id), array('%d'));
					$removed[] = array('id' => $last_id, 'json_data' => (string) $row['json_data']);
				}
			}
		} while (count($rows) === self::BATCH);

		if ($removed !== array()) {
			update_option(self::ROLLBACK_OPTION, $removed, false);
		}

		return count($removed);
	}

	/**
	 * The saved error map, and only it: empty post_id, no review id, no text, and the `error` + `reason` keys both relay
	 * maps carry (refused key and refused place, sb-relay Traits/Utils.php). A real review's post_id is its review id
	 * (SinglePostCache::store()), so a rating-0 or anonymous one stays.
	 *
	 * @param string $post_id   The row's post_id column.
	 * @param mixed  $json_data The row's json_data column.
	 * @return bool
	 */
	public static function is_blank_error_review($post_id, $json_data)
	{
		if ($post_id !== '' || !is_string($json_data)) {
			return false;
		}
		$data = json_decode($json_data, true);
		if (!is_array($data) || !array_key_exists('error', $data) || !array_key_exists('reason', $data)) {
			return false;
		}

		return empty($data['review_id']) && empty($data['text']);
	}
}
