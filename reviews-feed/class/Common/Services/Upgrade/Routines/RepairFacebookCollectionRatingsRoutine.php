<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Helpers\Data_Encryption;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * SMASH-2288: until 2.15.0, every manual Facebook review in a collection was stored with rating 1
 * whatever stars were set, which pulled feed averages down. The stars set by the user are still in
 * json_data, so this copies them back into the rating column and recalculates the collections touched.
 *
 * One-shot via its own flag, not sbr_db_version: Free and Pro routines share that number and live
 * sites are already past any version this could claim (see ReconcileMigratedLicenseRoutine).
 */
class RepairFacebookCollectionRatingsRoutine extends ServiceProvider
{
	public const DONE_OPTION = 'sbr_fb_collection_rating_2288_done';

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
		$table = $wpdb->prefix . SBR_POSTS_TABLE;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is the plugin's own constant
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, provider_id, rating, json_data FROM $table WHERE provider = %s AND provider_id LIKE %s",
				'facebook',
				'%' . $wpdb->esc_like('collection') . '%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if (empty($rows)) {
			return;
		}

		$encryption = new Data_Encryption();
		$touched = [];
		foreach ($rows as $row) {
			$review = json_decode((string) $encryption->maybe_decrypt($row['json_data']), true);
			$stars = is_array($review) ? ($review['rating'] ?? null) : null;
			// Recommendation sentinels ('positive'/'negative') were already stored correctly as 5/1.
			if (!is_numeric($stars) || (int) $stars === (int) $row['rating']) {
				continue;
			}
			$wpdb->update($table, ['rating' => (int) $stars], ['id' => (int) $row['id']], ['%d'], ['%d']);
			$touched[ $row['provider_id'] ] = true;
		}

		foreach (array_keys($touched) as $collection_id) {
			SBR_Sources::update_collection_ratings($collection_id);
		}
	}
}
