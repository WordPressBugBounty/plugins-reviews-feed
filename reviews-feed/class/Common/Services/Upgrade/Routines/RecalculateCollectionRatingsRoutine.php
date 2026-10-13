<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * SMASH-1685: until 2.15.0 a collection's rating was saved rounded up to a whole star (ceil), so
 * 5,5,3,5,5 showed 5 instead of 4.6 in the feed header, Review Alerts and Trust Badges. The saved value
 * only changes when a collection is touched, so this recalculates every collection once.
 *
 * One-shot via its own flag, not sbr_db_version (see RepairFacebookCollectionRatingsRoutine).
 */
class RecalculateCollectionRatingsRoutine extends ServiceProvider
{
	public const DONE_OPTION = 'sbr_collection_rating_1685_done';

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
		$table = $wpdb->prefix . SBR_SOURCES_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own constant
		$ids = $wpdb->get_col(
			$wpdb->prepare("SELECT account_id FROM $table WHERE provider = %s", 'collection')
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (empty($ids)) {
			return;
		}

		foreach (array_unique($ids) as $collection_id) {
			SBR_Sources::update_collection_ratings($collection_id);
		}
	}
}
