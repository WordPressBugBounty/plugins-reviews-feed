<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\FeedCache;
use SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * SBR-556: provider failures are no longer shown to the client (Feed::api_request(), is_provider_failure()), but a
 * feed's cached `errors` row written before the update would keep showing them until that feed next refreshes. This
 * drops those entries once; everything else in the cache (licence, the client's own key, "No Posts Found") stays.
 *
 * One-shot via its own flag, not sbr_db_version (see RepairFacebookCollectionRatingsRoutine).
 */
class HideCachedProviderFailuresRoutine extends ServiceProvider
{
	public const DONE_OPTION = 'sbr_hide_cached_provider_failures_done';

	/** @return void */
	public function register()
	{
		if (!get_option(self::DONE_OPTION)) {
			// Marked done first, so a fatal mid-run can't re-fire it on every load.
			update_option(self::DONE_OPTION, time());
			$this->run();
		}
	}

	/** @return void */
	public function run()
	{
		global $wpdb;
		$table = $wpdb->prefix . SBR_FEED_CACHES_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own constant
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id, feed_id, cache_value FROM $table WHERE cache_key = %s", 'errors'),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (!is_array($rows)) {
			return;
		}

		foreach ($rows as $row) {
			$errors = json_decode((string) $row['cache_value'], true);
			if (!is_array($errors)) {
				continue;
			}
			$kept = self::without_provider_failures($errors);
			if (count($kept) !== count($errors)) {
				$wpdb->update($table, array('cache_value' => (string) wp_json_encode($kept)), array('id' => (int) $row['id']), array('%s'), array('%d'));
				// FeedCache::query_feed_caches() reads the object cache first, with no expiry (Redis, Memcached).
				wp_cache_delete(FeedCache::CACHE_KEY . $row['feed_id'] . '_');
			}
		}
	}

	/**
	 * @param array<int|string, mixed> $errors A decoded `errors` cache value.
	 * @return list<mixed> The entries that aren't provider failures, in order.
	 */
	public static function without_provider_failures(array $errors)
	{
		return array_values(array_filter($errors, static function ($error) {
			return !(is_array($error) && isset($error['error_id']) && in_array($error['error_id'], SourceFetchOutcome::CACHED_PROVIDER_FAILURE_IDS, true));
		}));
	}
}
