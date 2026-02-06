<?php

/**
 * Clear Cache data in the DB
 */

namespace SmashBalloon\Reviews\Common;

if (! defined('ABSPATH')) {
	exit;
}

use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_External_Reviews_Update;
use SmashBalloon\Reviews\Pro\Services\BulkUpdate\Bulk_WooCommerce_Reviews_Update;
use Smashballoon\Stubs\Services\ServiceProvider;

class Clear_Cache extends ServiceProvider
{
	public function register()
	{
		add_action('wp_ajax_sbr_clear_post_header_cache', [ $this , 'sbr_clear_post_header_cache' ]);
		add_action('wp_ajax_sbr_reset_posts', [ $this, 'sbr_reset_posts' ]);
		add_action('wp_ajax_sbr_reset_local_images', [ $this, 'sbr_reset_local_images' ]);
	}

	private static function clear_data($table_name, $column, $where_clause)
	{
		global $wpdb;
		$table_full_name = $wpdb->prefix . $table_name;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table name and column are hardcoded internal values
		$sql = "
		UPDATE $table_full_name
		SET $column = ''
		$where_clause";
		$wpdb->query($sql);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function drop_table($table_name)
	{
		global $wpdb;
		$table_full_name = $wpdb->prefix . $table_name;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Table name is hardcoded internal value
		$sql = "DROP TABLE IF EXISTS $table_full_name";
		$wpdb->query($sql);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	public function sbr_clear_post_header_cache()
	{
		check_ajax_referer('sbr-admin', 'nonce');
		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error();
		}

		$this->clear_post_header_cache();

		// Reset bulk update states and schedule re-fetching of reviews
		$this->reset_bulk_update_states();

		wp_send_json(['success' => true], 200);
	}

	/**
	 * Reset bulk update states for all providers
	 *
	 * This triggers a full resync of reviews from all sources:
	 * - External providers (Airbnb, Booking, AliExpress): Re-fetch from relay API
	 * - WooCommerce: Resync from wp_comments table
	 *
	 * WooCommerce is included to provide users a "nuclear option" for full refresh.
	 * For ongoing changes, event-driven hooks handle individual review updates.
	 *
	 * @see documentation/WOOCOMMERCE_EVENT_DRIVEN_CACHE_ARCHITECTURE.md
	 *
	 * @since 2.3.0
	 */
	private function reset_bulk_update_states(): void
	{
		// External providers (Airbnb, Booking, AliExpress): Reset and schedule background fetch
		if (Util::sbr_is_pro() && class_exists(Bulk_External_Reviews_Update::class)) {
			Bulk_External_Reviews_Update::reset_all_sources(true);
		}

		// WooCommerce: Resync all reviews from wp_comments table
		// This provides users a way to force a full refresh if needed
		if (Util::sbr_is_pro() && class_exists(Bulk_WooCommerce_Reviews_Update::class)) {
			Bulk_WooCommerce_Reviews_Update::reset_all_sources(true);
		}
	}

	public function sbr_reset_posts()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error();
		}


		SinglePostCache::delete_resizing_table_and_images();
		SinglePostCache::create_resizing_table_and_uploads_folder();
		$this->sbr_clear_post_header_cache();
		wp_send_json(['success' => true], 200);
	}

	public function clear_post_header_cache()
	{
		self::clear_data(
			"sbr_feed_caches",
			"cache_value",
			"WHERE cache_key NOT IN ( 'posts_backup', 'header_backup' );"
		);
	}


	public static function clear_feed_caches_by_id($feeds_ids)
	{
		global $wpdb;
		$cache_table_name = $wpdb->prefix . 'sbr_feed_caches';
		$feeds_ids_string = "'" . implode('\', \'', $feeds_ids) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed, feed_ids are sanitized internally
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $cache_table_name
				SET cache_value = ''
				WHERE cache_key NOT IN ( 'posts_backup', 'header_backup' )
				AND feed_id IN ($feeds_ids_string)
				"
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}


	public function reset_images()
	{
		global $wpdb;
		$posts_table_name = $wpdb->prefix . 'sbr_reviews_posts';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is prefixed constant
		$wpdb->query("UPDATE $posts_table_name SET images_done = 0");
	}


	public function sbr_reset_local_images()
	{
		check_ajax_referer('sbr-admin', 'nonce');
		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error();
		}

		SinglePostCache::delete_local_images();
		$this->reset_images();
		$this->clear_post_header_cache();
		wp_send_json(
			[
				'success' => true,
				'message' => __('Local images cleared', 'reviews-feed')
			],
			200
		);
	}
}
