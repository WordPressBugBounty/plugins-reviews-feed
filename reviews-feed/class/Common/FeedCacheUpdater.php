<?php

/**
 * Class FeedCacheUpdater
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common;

class FeedCacheUpdater {
	/**
	 * @var array
	 */
	private $batch;

	public function __construct($batch)
	{
		$this->batch = $batch;
	}

	public function do_updates()
	{
		$refreshed_feed_ids = array();

		foreach ($this->batch as $single_feed) {
			$feed_id = ! empty($single_feed['feed_id']) ? (int) $single_feed['feed_id'] : 0;
			if ($feed_id <= 0) {
				continue;
			}

			// Hydrate the feed settings from wp_sbr_feeds the same way the
			// shortcode render path does — without this, the Feed constructor
			// gets a near-empty settings array (just `['feed' => N]`) and
			// update_posts_cache()/update_header_cache() short-circuit on the
			// `empty($settings['sources'])` guard, leaving the cron a silent
			// no-op for the cached upstream fetch it is supposed to drive.
			$settings   = SBR_Settings::get_settings_by_feed_id($feed_id, false, false);
			$feed_cache = new FeedCache((string) $feed_id, 0);

			$feed = Util::sbr_is_pro()
				? new \SmashBalloon\Reviews\Pro\Feed($settings, $feed_id, $feed_cache)
				: new \SmashBalloon\Reviews\Common\Feed($settings, $feed_id, $feed_cache);

			$feed->init();
			if (! empty($feed->get_errors())) {
				continue;
			}

			$feed->get_set_cache();
			$refreshed_feed_ids[] = $feed_id;
		}

		// Notify listeners that the upstream-cache rows have been repopulated.
		// SBR_Feed_Saver_Manager subscribes here to re-flush 3rd-party page
		// caches (WP Rocket / LiteSpeed / W3TC etc) so any empty-feed HTML
		// they baked during the cron warm-up window is evicted.
		if (! empty($refreshed_feed_ids)) {
			do_action('sbr_after_cron_refresh', $refreshed_feed_ids);
		}
	}
}
