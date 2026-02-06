<?php

/**
 * Class SinglePostCache
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Helpers\Data_Encryption;
use SmashBalloon\Reviews\Pro\Integrations\Forms\ReviewsSubmissionsRules;

class PostAggregator {
	public const UPLOAD_FOLDER_NAME = 'sbr-feed-images';
	public const POSTS_TABLE_NAME = SBR_POSTS_TABLE;

	/**
	 * @var array
	 */
	private $post_set = array();

	private $upload_dir;

	private $upload_url;

	private $missing_media_found;

	public function __construct()
	{
		$upload = wp_upload_dir();
		$upload_dir = $upload['basedir'];
		$upload_dir = trailingslashit($upload_dir) . self::UPLOAD_FOLDER_NAME;
		$this->upload_dir = $upload_dir;

		$upload_url = trailingslashit($upload['baseurl']) . self::UPLOAD_FOLDER_NAME;
		$this->upload_url = $upload_url;
		$this->missing_media_found = false;
	}

	public function missing_media_found()
	{
		return $this->missing_media_found;
	}

	public function add($post_set)
	{
		return $this->post_set = array_merge($this->post_set, $post_set);
	}

	public function db_post_set($requests_needed)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$where_clause = $this->build_provider_business_where_clause($requests_needed);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd, where_clause built from sanitized values
		$results = $wpdb->get_results(
			"SELECT * FROM $table_name
					WHERE $where_clause ORDER BY time_stamp DESC LIMIT 150",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results  =  self::remove_duplicated_posts_list($results);
		return $results;
	}

	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh -- Pre-existing complexity
	public static function remove_duplicated_posts_list($posts_list, $type = 'db')
	{
		// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh
		$posts_db = [];
		$posts_list_result = [];
		$encryption = new Data_Encryption();
		foreach ($posts_list as $postKey => $post) {
			$post = is_array($post) ? $post : json_decode($encryption->maybe_decrypt($post), true);
			if ($type === 'db') {
				$post['post_content'] = $post['provider'] === 'facebook' ? $encryption->maybe_decrypt($post['post_content']) : $post['post_content'];
				$post['json_data'] = $post['provider'] === 'facebook' ? $encryption->maybe_decrypt($post['json_data']) : $post['json_data'];
				$post_json =  isset($post['json_data']) ? json_decode($post['json_data'], true) : null;
				if (!empty($post_json)) {
					$post_json['source'] = ['id' => $post['provider_id'], 'url' => $post_json['url'] ?? ''];
					if (intval($post['images_done']) === 0) {
						self::cache_review_images($post, $post_json);
					}
				}
			} else {
				$post_json = $post;
			}

			if ($post_json !== null) {
				$search_value = $post_json['source']['id'] . '-' . $post_json['rating'] . '-' . $post_json['reviewer']['name'] . '-' . $post_json['provider']['name'];
				//IF EDD CHECK WITH TIME TOO SINCE A USER CAN POST MULTIPLE REVIEWS
				$search_value .= $post_json['provider']['name'] === 'edd' ? $post_json['time'] : '';
				// Include text content hash to allow same rating from same reviewer with different content
				$review_text = $post_json['text'] ?? '';
				if (! empty($review_text)) {
					$search_value .= '-' . md5($review_text);
				}

				if (!in_array($search_value, $posts_db)) {
					array_push($posts_db, $search_value);
					array_push($posts_list_result, $post);
				}
			}
		}
		return $posts_list_result;
	}

	public static function cache_review_images($post, $post_json)
	{
		return;
		$cache_post = [
			'review_id' => $post_json['review_id'],
			'text' => $post_json['text'],
			'rating' => $post_json['rating'],
			'time' => $post_json['time'],
			'reviewer' => $post_json['reviewer'],
			'source' => [
				'id' => $post_json['source']['id'],
				'url' => $post_json['source']['url']
			],
			'lang' => $post['lang'],
		];
		SBR_Feed_Saver_Manager::cache_single_review(
			$cache_post,
			[
				'id' => $post['provider_id'],
				'provider' => $post['provider'],
				'name' => $post['provider'],
				'url' => $post_json['source']['url']
			],
			$post['lang'],
			sbr_get_no_media_providers(),
			sbr_get_lang_providers()
		);
	}

	// phpcs:disable Generic.Metrics.CyclomaticComplexity.TooHigh -- Pre-existing complexity
	public static function remove_duplicated_posts_routine()
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$posts_db = [];
		$posts_id_todelete = [];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd
		$results = $wpdb->get_results(
			"SELECT * FROM $table_name",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ($results as $post) {
			$post_json =  isset($post['json_data']) ? json_decode($post['json_data'], true) : null;
			if ($post_json !== null) {
				$search_value = $post_json['source']['id'] . '-' . $post_json['rating'] . '-' . $post_json['reviewer']['name'] . '-' . $post_json['provider']['name'];
				//IF EDD CHECK WITH TIME TOO SINCE A USER CAN POST MULTIPLE REVIEWS
				$search_value .= $post_json['provider']['name'] === 'edd' ? $post_json['time'] : '';

				if (in_array($search_value, $posts_db)) {
					array_push($posts_id_todelete, $post['id']);
				} else {
					array_push($posts_db, $search_value);
				}
			}
		}

		if (sizeof($posts_id_todelete) > 0) {
			$posts_ids = implode(',', $posts_id_todelete);
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are integers from DB, table name is esc_sql'd
			$wpdb->query(
				"DELETE FROM $table_name WHERE id IN ($posts_ids)"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
	// phpcs:enable Generic.Metrics.CyclomaticComplexity.TooHigh


	public function db_posts_for_media_finding_and_resizing_set($requests_needed)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);

		$where_clause = $this->build_provider_business_where_clause($requests_needed);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd, where_clause built from sanitized values
		$results = $wpdb->get_results(
			"SELECT * FROM $table_name
					WHERE $where_clause
					AND images_done = 0
					ORDER BY time_stamp DESC LIMIT 150",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $results;
	}



	public function normalize_db_post_set($results)
	{
		$normalized_set = array();
		foreach ($results as $result) {
			if (! empty($result['json_data'])) {
				$post = json_decode($result['json_data'], true);
				if (! empty($post)) {
					$post = self::add_local_image_urls($post, $result);
				}
				if ((int)$result['images_done'] === 0) {
					$this->missing_media_found = true;
				}
				$normalized_set[] = $post;
			}
		}

		return $normalized_set;
	}

	public function add_local_image_urls($post, $result)
	{
		$return     = $post;
		$base_url   = $this->upload_url;
		$resize_url = apply_filters('sbr_resize_url', trailingslashit($base_url));

		if (! empty($post['reviewer']['avatar'])) {
			if (! empty($result['avatar_id']) && $result['avatar_id'] !== 'error') {
				$return['reviewer']['avatar_local'] = $resize_url . $result['avatar_id'] . '.png';
			}
		}

		if (! empty($post['media'])) {
			$sizes = ! empty($result['sizes']) ? json_decode($result['sizes']) : array();
			$i     = 0;
			foreach ($post['media'] as $single_image) {
				if (! empty($result['media_id']) && $result['media_id'] !== 'error') {
					$return['media'][ $i ]['local'] = array();
					$media_id                       = $result['media_id'];
					foreach ($sizes as $size) {
						$local_url_for_size = $resize_url . $media_id . '-' . $i . '-' .  $size . '.jpg';
						$return['media'][ $i ]['local'][ $size ] = $local_url_for_size;
					}
				}
				$i++;
			}
		}

		return $return;
	}

	public function update_last_requested($requests_needed)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$where_clause = $this->build_provider_business_where_clause($requests_needed);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd, where_clause built from sanitized values
		$query = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name
					SET last_requested = %s
					WHERE $where_clause;",
				date('Y-m-d')
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function build_provider_business_where_clause($requests_needed)
	{

		$i            = 1;
		$where_clause = '';
		foreach ($requests_needed as $request) {
			$single_clause = sprintf('provider_id = "%s"', esc_sql($request['account_id']));
			if (isset($request['lang'])) {
				$single_clause .= sprintf(' AND lang = "%s"', esc_sql($request['lang']));
			}
			$where_clause .= '(' . $single_clause . ')';
			if ($i <  count($requests_needed)) {
				$where_clause .= ' OR ';
			}
			$i++;
		}

		return $where_clause;
	}


	/**
	 * Get List Provider/Source Posts
	 *
	 * @param string $provider_id | int $page
	 *
	 * @return array
	 *
	 * @since 1.4
	 */
	public static function get_source_posts_list($provider_id, $page = 1)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$limit = 40;
		$offset = ($page - 1) * $limit;
		$encryption = new Data_Encryption();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM $table_name
				WHERE provider_id = %s
				ORDER BY id DESC
				LIMIT %d
				OFFSET %d
				",
				$provider_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$decrypted_results = [];
		foreach ($results as $s_post) {
			if ($s_post['provider'] === 'facebook') {
				$s_post['post_content'] = $encryption->maybe_decrypt($s_post['post_content']);
				$s_post['json_data'] = $encryption->maybe_decrypt($s_post['json_data']);
				$json_data = json_decode($s_post['json_data'], true);

				if (isset($json_data['rating']) && !is_numeric($json_data['rating'])) {
					$rating = isset($json_data['rating']) && $json_data['rating'] === 'negative' ? 1 : 5;
					$json_data['rating'] = $rating;
				}

				$s_post['json_data'] = wp_json_encode($json_data);
			}
			array_push($decrypted_results, $s_post);
		}
		return $decrypted_results;
	}

	/**
	 * Delete Review from Collection
	 *
	 * @param string $provider_id | string $provider
	 *
	 * @return void
	 *
	 * @since 1.4
	 */
	public static function delete_review($provider_id, $review_id, $provider)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$provider_value = $provider === 'collection' ? 'none' : $provider;

		ReviewsSubmissionsRules::remove_auto_approved($review_id, $provider_id, '', 'single', false);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name
				WHERE provider_id = %s AND provider = %s AND post_id = %s
				",
				$provider_id,
				$provider_value,
				$review_id
			)
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}


	/**
	 * Select a List of reviews By Review ID
	 *
	 * @param array $reviews_ids
	 *
	 * @return array|boolean
	 *
	 * @since 1.4
	 */
	public static function get_list_reviews_by_ids($reviews_ids)
	{
		global $wpdb;
		$table_name = $wpdb->prefix . self::POSTS_TABLE_NAME;

		if (empty($reviews_ids) || ! is_array($reviews_ids)) {
			return false;
		}

		// Filter out empty values
		$reviews_ids = array_filter($reviews_ids, function ($id) {
			return ! empty($id);
		});

		if (empty($reviews_ids)) {
			return false;
		}

		// Build placeholders for prepare() - one %s for each ID
		$placeholders = implode(', ', array_fill(0, count($reviews_ids), '%s'));

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal constant, placeholders are dynamically generated
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE post_id IN ({$placeholders})",
				...$reviews_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $results;
	}

	/**
	 * Select a List of reviews By Review ID
	 *
	 * @param string $provider_id | array $reviews_ids
	 *
	 * @return void
	 *
	 * @since 1.4
	 */
	public static function insert_multiple_reviews($provider_id, $reviews_ids)
	{
		$reviews_to_insert = PostAggregator::get_list_reviews_by_ids($reviews_ids);
		$encryption = new Data_Encryption();
		if (is_array($reviews_to_insert)) {
			foreach ($reviews_to_insert as $rev) {
				$review_id = $provider_id . time() . wp_rand();
				$json_data = $encryption->maybe_decrypt($rev['json_data']);
				$review = json_decode($json_data, true);
				$review_store = Util::parse_single_review($review, $provider_id, $review_id);
				$review_store['json_data'] = $review_store;

				$single_post_cache = new \SmashBalloon\Reviews\Pro\SinglePostCache($review_store, new \SmashBalloon\Reviews\Pro\MediaFinder($review_store['source']));
				$single_post_cache->set_provider_id($provider_id);
				$single_post_cache->store();
			}
		}
	}

	/**
	 * Delete All Reviews from Provider/Source ID
	 *
	 * @param string $provider_id
	 *
	 * @return void
	 *
	 * @since 1.4
	 */
	public static function delete_reviews_by_provide_id($provider_id)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table_name
				WHERE provider_id = %s",
				$provider_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get List Provider/Source Posts
	 *
	 * @param string $provider_id
	 *
	 * @return array
	 *
	 * @since 1.4
	 */
	public static function get_source_all_posts($provider_id)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is esc_sql'd
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM $table_name
				WHERE provider_id = %s
				",
				$provider_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $results;
	}


	/**
	 * Search Reviews of a specific Provider
	 *
	 * @param string $provider_id | string $search_text
	 *
	 * @return array
	 *
	 * @since 1.4
	 */
	public static function search_source_posts_list($provider_id, $search_text)
	{
		global $wpdb;
		$table_name = esc_sql($wpdb->prefix . self::POSTS_TABLE_NAME);
		$search_text_array = explode(' ', $search_text);
		$search_string = implode('.*', $search_text_array);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder -- Table name is esc_sql'd, REGEXP requires quotes
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM $table_name
				WHERE provider_id = %s
				AND json_data REGEXP '%s'
				",
				$provider_id,
				$search_string
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder
		return $results;
	}
}
