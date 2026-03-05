<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\PostAggregator;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Migration routine to add unique index on reviews posts table.
 *
 * This prevents duplicate reviews from being inserted due to race conditions
 * when multiple requests try to cache the same review simultaneously.
 *
 * @since 2.4.5
 */
class AddUniqueReviewIndexRoutine extends ServiceProvider
{
	protected $target_version = 1.3;

	public function register()
	{
		if ($this->will_run()) {
			$success = $this->run();
			// Only update version if migration succeeded
			if ($success) {
				$this->update_db_version();
			}
		}
	}

	protected function will_run()
	{
		$current_schema = (float) get_option('sbr_db_version', 0);

		return $current_schema < (float) $this->target_version;
	}

	protected function update_db_version()
	{
		update_option('sbr_db_version', $this->target_version);
	}

	/**
	 * Run the migration to add unique index.
	 *
	 * @return bool True if migration succeeded or index already exists, false on failure.
	 */
	public function run()
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . PostAggregator::POSTS_TABLE_NAME);

		// First, remove any existing duplicates
		PostAggregator::remove_duplicated_posts_routine();

		// Check if unique index already exists
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
		$index_exists = $wpdb->get_var(
			"SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
			 WHERE table_schema = DATABASE()
			 AND table_name = '$table_name'
			 AND index_name = 'idx_unique_review'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Index already exists, migration is complete
		if ($index_exists) {
			return true;
		}

		// Add unique index to prevent duplicate reviews
		// Using prefix lengths to stay within MySQL index size limits
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed from $wpdb->prefix
		$result = $wpdb->query(
			"ALTER TABLE $table_name
			 ADD UNIQUE INDEX idx_unique_review (post_id(100), provider_id(100), lang(50))"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// $wpdb->query() returns false on error, number of rows affected on success
		// For ALTER TABLE, it returns 0 on success (no rows affected)
		if ($result === false) {
			// Log the error for debugging
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional logging for migration failure
			error_log('SBR Migration Error: Failed to add unique index idx_unique_review. Error: ' . $wpdb->last_error);
			return false;
		}

		return true;
	}
}
