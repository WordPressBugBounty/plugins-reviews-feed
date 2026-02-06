<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.Security.EscapeOutput.OutputNotEscaped
// Note: Legacy file with pre-existing PHPCS issues. Direct DB queries and LIKE wildcards required for customizer functionality.

namespace SmashBalloon\Reviews\Common\Customizer;

use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\SBR_Settings;
use SmashBalloon\Reviews\Common\Helpers\Data_Encryption;

class DB extends \Smashballoon\Customizer\V2\DB{
	/**
	 * Number of feeds per page (override parent for testing: set to 2)
	 * Production value: 20
	 */
	const RESULTS_PER_PAGE = 20;

	/**
	 * Number of sources per page (for testing: set to 2)
	 * Production value: 40
	 */
	const SOURCES_PER_PAGE = 20;

	protected $feeds_table = SBR_FEEDS_TABLE;
	protected $sources_table = SBR_SOURCES_TABLE;
	protected $caches_table = SBR_FEED_CACHES_TABLE;
	protected $post_tables = POSTS_TABLE_NAME;
	protected $custom_source_table = true;

	public function __construct()
	{
		global $wpdb;
		$this->feeds_table = $wpdb->prefix . SBR_FEEDS_TABLE;
		$this->sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$this->caches_table = $wpdb->prefix . SBR_FEED_CACHES_TABLE;
		$this->post_tables = $wpdb->prefix . POSTS_TABLE_NAME;
		$this->custom_source_table = true;
	}

	/**
	 * Query the feeds table
	 * Porcess to define the name of the feed when adding new
	 *
	 * @param array $args
	 *
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public static function feeds_query_name($feedname)
	{
		global $wpdb;
		$feeds_table_name = $wpdb->prefix . SBR_FEEDS_TABLE;
		$sql = $wpdb->prepare(
			"SELECT * FROM $feeds_table_name
			WHERE feed_name LIKE %s;",
			$wpdb->esc_like($feedname) . '%'
		);
		$count = sizeof($wpdb->get_results($sql, ARRAY_A));
		return ($count == 0) ? $feedname : $feedname . ' (' . ($count + 1) . ')';
	}

	/**
	 * Query to Duplicate a Single Feed
	 *
	 * @param array $args
	 *
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public static function duplicate_feed_query($feed_id)
	{
		global $wpdb;
		$feeds_table_name = $wpdb->prefix . SBR_FEEDS_TABLE;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $feeds_table_name (feed_name, settings, author, status)
				SELECT CONCAT(feed_name, ' (copy)'), settings, author, status
				FROM $feeds_table_name
				WHERE id = %d; ",
				$feed_id
			)
		);

		echo sbr_json_encode(
			[
				'feedsList' => DB::get_feeds_list(),
				'feedsCount' => DB::feeds_list_count()
			]
		);
		wp_die();
	}

	/**
	 * Query to Remove Feeds from Database
	 *
	 * @param array $args
	 *
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public static function delete_feeds_query($feed_ids_array)
	{
		global $wpdb;
		$feeds_table_name = $wpdb->prefix . SBR_FEEDS_TABLE;
		$feed_caches_table_name = $wpdb->prefix . SBR_FEED_CACHES_TABLE;

		// Sanitize IDs - ensure they are integers
		$feed_ids_array = array_map('absint', $feed_ids_array);
		$feed_ids_array = array_filter($feed_ids_array);

		if (empty($feed_ids_array)) {
			echo sbr_json_encode([
				'feedsList' => DB::get_feeds_list(),
				'feedsCount' => DB::feeds_list_count()
			]);
			wp_die();
		}

		$feed_ids_string = implode(',', $feed_ids_array);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized with absint()
		$wpdb->query("DELETE FROM $feeds_table_name WHERE id IN ($feed_ids_string)");
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized with absint()
		$wpdb->query("DELETE FROM $feed_caches_table_name WHERE feed_id IN ($feed_ids_string)");

		echo sbr_json_encode(
			[
				'feedsList' => DB::get_feeds_list(),
				'feedsCount' => DB::feeds_list_count()
			]
		);
		wp_die();
	}

	/**
	* Creates Sources Table
	*
	*
	*
	* @since 1.0
	*/
	public function create_sources_table()
	{
		if (!function_exists('dbDelta')) {
			require_once ABSPATH . '/wp-admin/includes/upgrade.php';
		}
		global $wpdb;
		$max_index_length = 191;
		$charset_collate = '';
		if (method_exists($wpdb, 'get_charset_collate')) { // get_charset_collate introduced in WP3.5
			$charset_collate = $wpdb->get_charset_collate();
		}
		if ($wpdb->get_var("show tables like '$this->sources_table'") !== $this->sources_table) {
			$sql = '
				CREATE TABLE ' . $this->sources_table . " (
				id bigint(20) unsigned NOT NULL auto_increment,
				account_id varchar(255) NOT NULL default '',
				provider varchar(255) NOT NULL default '',
				access_token varchar(1000) NOT NULL default '',
				name varchar(255) NOT NULL default '',
				info text NOT NULL default '',
				error text NOT NULL default '',
				expires datetime NOT NULL,
				last_updated datetime NOT NULL,
				author bigint(20) unsigned NOT NULL default '1',
				PRIMARY KEY (id),
				KEY author (author)
				) $charset_collate;";
			$wpdb->query($sql);
		}
	}


	/**
	 * Query the sbi_sources table
	 *
	 * @param array $args
	 *
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public function source_query($args = array())
	{
		global $wpdb;

		$page = 0;
		if (isset($args['page'])) {
			$page = (int) $args['page'] - 1;
			unset($args['page']);
		}

		$limit = self::SOURCES_PER_PAGE;
		$offset = max(0, $page * $limit);

		// Handle search parameter
		$search = '';
		if (isset($args['search'])) {
			$search = sanitize_text_field($args['search']);
			unset($args['search']);
		}

		if (empty($args)) {
			// Build WHERE clause for search
			$where_clause = '';
			if (! empty($search)) {
				$search_term = '%' . $wpdb->esc_like($search) . '%';
				$where_clause = $wpdb->prepare(
					" WHERE s.name LIKE %s OR s.provider LIKE %s ",
					$search_term,
					$search_term
				);
			}

			$sql = "SELECT s.id, s.account_id, s.provider, s.access_token, s.name, s.info, s.error, s.expires, count(f.id) as used_in,
				(SELECT count(p.id) FROM $this->post_tables p WHERE s.account_id = p.provider_id) as reviews_number
				FROM $this->sources_table s
				LEFT JOIN $this->feeds_table f ON f.settings LIKE CONCAT('%', s.account_id, '%')
				{$where_clause}
				GROUP BY s.account_id
				LIMIT $limit
				OFFSET $offset;
				";

			$results = $wpdb->get_results($sql, ARRAY_A);

			if (empty($results)) {
				return array();
			}

			$i = 0;
			foreach ($results as $result) {
				if ((int) $result['used_in'] > 0) {
					$results[ $i ]['instances'] = $wpdb->get_results($wpdb->prepare(
						"SELECT *
						FROM $this->feeds_table
						WHERE settings LIKE CONCAT('%', %s, '%')
						GROUP BY id
						LIMIT 100;
						",
						$result['account_id']
					), ARRAY_A);
				}
				$i++;
			}

			return $results;
		}


		if (! empty($args['name'])) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"
						SELECT * FROM $this->sources_table
						WHERE name = %s;
					",
					$args['name']
				),
				ARRAY_A
			);
		}


		if (! isset($args['id'])) {
			return false;
		}

		if (is_array($args['id'])) {
			$id_array = array();
			foreach ($args['id'] as $id) {
				$id_array[] = esc_sql($id);
			}
		} elseif (strpos($args['id'], ',') !== false) {
			$id_array = explode(',', str_replace(' ', '', esc_sql($args['id'])));
		}

		if (isset($id_array)) {
			$id_array = array_filter($id_array, function ($value) {
				return !is_null($value) && $value !== '' && !empty($value) && !is_array($value);
			});
			$id_string = "'" . implode("' , '", array_map('esc_sql', $id_array)) . "'";
		}

		$privilege = '';
		if (isset($id_string)) {
			$sql = "
				SELECT * FROM $this->sources_table
				WHERE account_id IN ($id_string);
			";
		} else {
			$sql = $wpdb->prepare(
				"
				SELECT * FROM $this->sources_table
				WHERE account_id = %s;
				",
				$args['id']
			);
		}
		return $wpdb->get_results($sql, ARRAY_A);
	}


	/**
	 * Query the sbi_sources table to get number of sources
	 *
	 * @param array $args Optional args with 'search' parameter
	 *
	 * @return int
	 *
	 * @since 1.0
	 */
	public function source_query_count($args = array())
	{
		global $wpdb;

		$where_clause = '';
		if (! empty($args['search'])) {
			$search_term = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
			$where_clause = $wpdb->prepare(
				" WHERE name LIKE %s OR provider LIKE %s ",
				$search_term,
				$search_term
			);
		}

		$source_count = $wpdb->get_var("SELECT count(*) FROM $this->sources_table {$where_clause}");
		return (int) $source_count;
	}

	/**
	 * Query the feeds table with search support
	 *
	 * @param array $args
	 *
	 * @return array|bool
	 *
	 * @since 2.3.0
	 */
	public function feeds_query($args = array())
	{
		global $wpdb;

		$page = 0;
		if (isset($args['page'])) {
			$page = (int) $args['page'] - 1;
			unset($args['page']);
		}

		$offset = max(0, $page * self::RESULTS_PER_PAGE);

		// Handle search parameter
		$search = '';
		if (isset($args['search'])) {
			$search = sanitize_text_field($args['search']);
			unset($args['search']);
		}

		if (isset($args['id'])) {
			$sql = $wpdb->prepare(
				"SELECT * FROM $this->feeds_table WHERE id = %d;",
				$args['id']
			);
		} else {
			// Build WHERE clause for search
			$where_clause = '';
			if (! empty($search)) {
				$search_term = '%' . $wpdb->esc_like($search) . '%';
				$where_clause = $wpdb->prepare(
					" WHERE feed_name LIKE %s ",
					$search_term
				);
			}

			$sql = $wpdb->prepare(
				"SELECT * FROM $this->feeds_table {$where_clause} ORDER BY last_modified DESC LIMIT %d OFFSET %d;",
				self::RESULTS_PER_PAGE,
				$offset
			);
		}

		return $wpdb->get_results($sql, ARRAY_A);
	}
	/**
	 * New source (connected account) data is added to the
	 * sbr_sources table and the new insert ID is returned
	 *
	 * @param array $to_insert
	 *
	 * @return false|int
	 *
	 * @since 1.0
	 */
	public function source_insert($to_insert)
	{
		global $wpdb;
		$data   = array();
		$format = array();
		$where  = array();
		$where_format = array();
		if (isset($to_insert['account_id'])) {
			$data['account_id'] = $to_insert['account_id'];
			$format[]           = '%s';
		}
		if (isset($to_insert['provider'])) {
			$data['provider'] = $to_insert['provider'];
			$format[]             = '%s';
		}
		if (isset($to_insert['name'])) {
			$data['name'] = $to_insert['name'];
			$format[]         = '%s';
		}
		if (isset($to_insert['info'])) {
			$data['info'] = $to_insert['info'];
			$format[]     = '%s';
		}
		if (isset($to_insert['access_token'])) {
			$data['access_token'] = $to_insert['access_token'];
			$format[] = '%s';
		}
		if (isset($to_insert['error'])) {
			$data['error'] = $to_insert['error'];
			$format[]      = '%s';
		}
		if (isset($to_insert['expires'])) {
			$data['expires'] = $to_insert['expires'];
			$format[]        = '%s';
		} else {
			$data['expires'] = '2100-12-30 00:00:00';
			$format[]        = '%s';
		}
		$data['last_updated'] = gmdate('Y-m-d H:i:s');
		$format[]             = '%s';
		if (isset($to_insert['author'])) {
			$data['author'] = $to_insert['author'];
			$format[]       = '%d';
		} else {
			$data['author'] = get_current_user_id();
			$format[]       = '%d';
		}
		$affected = $wpdb->insert($this->sources_table, $data, $format);

		return $affected;
	}

	/**
	 * Update a source (connected account)
	 *
	 * @param array $to_update
	 * @param array $where_data
	 *
	 * @return false|int
	 *
	 * @since 1.0
	 */
	public function source_update($to_update, $where_data)
	{
		global $wpdb;

		$data         = array();
		$where        = array();
		$format       = array();
		$where_format = array();

		if (isset($to_update['name'])) {
			$data['name'] = $to_update['name'];
			$format[] = '%s';
		}
		if (isset($to_update['info'])) {
			$data['info'] = $to_update['info'];
			$format[] = '%s';
		}
		if (isset($to_update['last_updated'])) {
			$data['last_updated'] = $to_update['last_updated'];
			$format[] = '%s';
		}
		if (isset($to_update['access_token'])) {
			$data['access_token'] = $to_update['access_token'];
			$format[] = '%s';
		}
		if (isset($where_data['id'])) {
			$where['account_id'] = $where_data['id'];
			$where_format[] = '%s';
		}
		if (isset($where_data['provider'])) {
			$where['provider'] = $where_data['provider'];
			$where_format[] = '%s';
		}


		$affected = $wpdb->update($this->sources_table, $data, $where, $format, $where_format);
		return $affected;
	}

	 /**
	 * Update a source (connected account)
	 *
	 * @param array $to_update
	 * @param array $where_data
	 *
	 * @return false|int
	 *
	 * @since 1.0
	 */
	public function get_single_source($args)
	{
		global $wpdb;
		$query = "SELECT s.*, count(f.id) as used_in FROM $this->sources_table as s  LEFT JOIN $this->feeds_table f ON f.settings LIKE CONCAT('%', s.account_id, '%') WHERE s.account_id = %s AND s.provider = %s";
		$sql = $wpdb->prepare(
			$query,
			$args['id'],
			$args['provider']
		);
		$affected = $wpdb->get_results($sql, ARRAY_A);
		$i = 0;
		foreach ($affected as $result) {
			if ((int) $result['used_in'] > 0) {
				$affected[ $i ]['instances'] = $wpdb->get_results($wpdb->prepare(
					"SELECT id
					FROM $this->feeds_table
					WHERE settings LIKE CONCAT('%', %s, '%')
					GROUP BY id
					LIMIT 100;
					",
					$result['account_id']
				), ARRAY_A);
			}
			$i++;
		}
		return !isset($affected[0]['account_id']) || is_null($affected[0]['account_id']) ? [] : $affected;
	}



	public static function get_feeds_list($feeds_args = array())
	{

		if (! empty($_GET['feed_id'])) {
			return array();
		}
		$db = new DB();
		$feeds_data = $db->feeds_query($feeds_args);

		$i = 0;
		foreach ($feeds_data as $single_feed) {
			// Use direct content scanning to find shortcode usage
			// This finds shortcodes in drafts, unpublished content, and all post types
			$locations = self::get_feed_shortcode_locations($single_feed['id'], DB::RESULTS_PER_PAGE);
			$count     = self::count_feed_shortcode_locations($single_feed['id']);

			$feeds_data[ $i ]['instance_count']   = $count;
			$feeds_data[ $i ]['location_summary'] = $locations;
			$settings                             = json_decode($feeds_data[ $i ]['settings'], true);

			$settings['feed'] = $single_feed['id'];

			$reviews_feed_settings = new SBR_Settings($settings, sbr_settings_defaults());

			$feeds_data[ $i ]['settings'] = $reviews_feed_settings->get_settings();
			$feeds_data[ $i ]['sourcesList'] = SBR_Sources::get_sources_list([
				'id'   => $feeds_data[ $i ]['settings']['sources']
			]);
			$i++;
		}
		return $feeds_data;
	}

	/**
	 * Query to Remove Source from Database
	 *
	 * @param array $source_id
	 *
	 * @since 6.0
	 */
	public function delete_source($source_id)
	{
		global $wpdb;
		return $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $this->sources_table WHERE id = %d; ",
				$source_id
			)
		);
	}

	/**
	 * Count the sbr_feeds table
	 *
	 * @param array $args Optional args with 'search' parameter
	 *
	 * @return int
	 *
	 * @since 4.0
	 */
	public static function feeds_list_count($args = array())
	{
		global $wpdb;
		$feeds_table_name = $wpdb->prefix . SBR_FEEDS_TABLE;

		$where_clause = '';
		if (! empty($args['search'])) {
			$search_term = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
			$where_clause = $wpdb->prepare(
				" WHERE feed_name LIKE %s ",
				$search_term
			);
		}

		$results = $wpdb->get_results(
			"SELECT COUNT(*) AS num_entries FROM $feeds_table_name {$where_clause}",
			ARRAY_A
		);
		return isset($results[0]['num_entries']) ? (int)$results[0]['num_entries'] : 0;
	}

	/**
	 * Get Facebook Sources List
	 *
	 * @return array
	 *
	 * @since X.X
	 */
	public static function get_facebook_sources()
	{
		global $wpdb;
		$source_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$query = "SELECT * FROM $source_table as s WHERE  s.provider = %s";
		$sql = $wpdb->prepare(
			$query,
			'facebook'
		);
		$results = $wpdb->get_results($sql, ARRAY_A);
		return $results;
	}

	/**
	 * Query to Remove Source from Database
	 *
	 * @param int $source_id
	 *
	 * @return array|bool
	 *
	 * @since X.X
	 */
	public static function delete_source_by_id($source_id)
	{
		global $wpdb;
		$sources_table_name = $wpdb->prefix . SBR_SOURCES_TABLE;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $sources_table_name WHERE id = %d; ",
				$source_id
			)
		);
	}

	/**
	 * Remove ALL Facebook Posts
	 *
	 * @return void
	 *
	 * @since X.X
	 */
	public static function clear_facebook_feed_posts()
	{
		global $wpdb;
		$posts_table_name = $wpdb->prefix . SBR_POSTS_TABLE;

		if ($wpdb->get_var("show tables like '$posts_table_name'") === $posts_table_name) {
			$wpdb->query("DELETE FROM $posts_table_name WHERE provider = 'facebook'");
		}
	}

	/**
	 * Remove ALL Facebook Sources
	 *
	 * @return void
	 *
	 * @since X.X
	 */
	public static function clear_facebook_sources()
	{
		global $wpdb;
		$sources_table_name = $wpdb->prefix . SBR_SOURCES_TABLE;

		if ($wpdb->get_var("show tables like '$sources_table_name'") === $sources_table_name) {
			$wpdb->query("DELETE FROM $sources_table_name WHERE provider = 'facebook'");
		}
	}



	/**
	 * Get Facebook Cached Posts List
	 *
	 * @return array
	 *
	 * @since X.X
	 */
	public static function get_facebook_cached_posts()
	{
		global $wpdb;
		$posts_table = $wpdb->prefix . SBR_POSTS_TABLE;
		$query = "SELECT * FROM $posts_table as s WHERE  s.provider = %s";
		$sql = $wpdb->prepare(
			$query,
			'facebook'
		);
		$results = $wpdb->get_results($sql, ARRAY_A);
		return $results;
	}


	/**
	 * Update a single Post
	 *
	 * @param array $to_update
	 * @param array $where_data
	 *
	 * @return void
	 *
	 * @since 1.0
	 */
	public static function single_post_update($to_update, $where_data)
	{
		global $wpdb;
		$data         = [];
		$where        = [];
		$format       = [];
		$where_format = [];
		if (isset($to_update['json_data'])) {
			$data['json_data'] = $to_update['json_data'];
			$format[] = '%s';
		}
		if (isset($to_update['post_content'])) {
			$data['post_content'] = $to_update['post_content'];
			$format[] = '%s';
		}
		if (isset($where_data['id'])) {
			$where['id'] = $where_data['id'];
			$where_format[] = '%s';
		}
		if (isset($where_data['provider'])) {
			$where['provider'] = $where_data['provider'];
			$where_format[] = '%s';
		}
		$feeds_posts_table_name = $wpdb->prefix . 'sbr_reviews_posts';
		$wpdb->update($feeds_posts_table_name, $data, $where, $format, $where_format);
	}

	/**
	 * Get Collections List
	 *
	 * @return array
	 *
	 * @since X.X
	 */
	public static function get_collections_list()
	{
		global $wpdb;
		$sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$query = "SELECT * FROM $sources_table as s WHERE  s.provider = %s";
		$sql = $wpdb->prepare(
			$query,
			'collection'
		);
		$results = $wpdb->get_results($sql, ARRAY_A);
		return $results;
	}

	/**
	 * Find all posts/pages containing a specific feed shortcode.
	 *
	 * Scans post_content directly in the database to find shortcode usage,
	 * including drafts and unpublished content that may not have been rendered yet.
	 *
	 * @param int|string $feed_id The feed ID to search for.
	 * @param int        $limit   Maximum number of results to return.
	 *
	 * @return array Array of locations where the feed shortcode is used.
	 *
	 * @since 2.3.0
	 */
	public static function get_feed_shortcode_locations($feed_id, $limit = 20)
	{
		global $wpdb;

		if (empty($feed_id)) {
			return array();
		}

		$feed_id = (int) $feed_id;

		// Search for shortcode patterns in post_content
		// Matches: [reviews-feed feed=123] or [reviews-feed feed="123"] or [reviews-feed feed='123']
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status
				FROM {$wpdb->posts}
				WHERE post_content LIKE %s
				AND post_type NOT IN ('revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation')
				AND post_status NOT IN ('auto-draft', 'inherit', 'trash')
				ORDER BY post_date DESC
				LIMIT %d",
				'%[reviews-feed%feed=' . $wpdb->esc_like((string) $feed_id) . '%',
				$limit
			),
			ARRAY_A
		);

		if (empty($results)) {
			return array();
		}

		$locations = array();
		foreach ($results as $row) {
			$page_text = ! empty($row['post_title']) ? $row['post_title'] : __('(no title)', 'reviews-feed');

			// Add status indicator for non-published posts
			if ($row['post_status'] !== 'publish') {
				$status_labels = array(
					'draft'   => __('Draft', 'reviews-feed'),
					'pending' => __('Pending', 'reviews-feed'),
					'private' => __('Private', 'reviews-feed'),
					'future'  => __('Scheduled', 'reviews-feed'),
				);
				$status_label = isset($status_labels[ $row['post_status'] ])
					? $status_labels[ $row['post_status'] ]
					: ucfirst($row['post_status']);
				$page_text .= ' — ' . $status_label;
			}

			$locations[] = array(
				'link'      => esc_url(get_the_permalink($row['ID'])),
				'page_text' => $page_text,
				'post_id'   => $row['ID'],
				'post_type' => $row['post_type'],
			);
		}

		return $locations;
	}

	/**
	 * Count posts/pages containing a specific feed shortcode.
	 *
	 * @param int|string $feed_id The feed ID to search for.
	 *
	 * @return int Number of posts containing the shortcode.
	 *
	 * @since 2.3.0
	 */
	public static function count_feed_shortcode_locations($feed_id)
	{
		global $wpdb;

		if (empty($feed_id)) {
			return 0;
		}

		$feed_id = (int) $feed_id;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_content LIKE %s
				AND post_type NOT IN ('revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation')
				AND post_status NOT IN ('auto-draft', 'inherit', 'trash')",
				'%[reviews-feed%feed=' . $wpdb->esc_like((string) $feed_id) . '%'
			)
		);

		return (int) $count;
	}

	/**
	 * Get All Collection Reviews
	 *
	 * @return array
	 *
	 * @since X.X
	 */

	public static function get_collections_reviews($account_id)
	{
		global $wpdb;
		$post_table = $wpdb->prefix . SBR_POSTS_TABLE;
		$source_table = $wpdb->prefix . SBR_SOURCES_TABLE;

		$collection = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $source_table WHERE account_id = %s",
				$account_id
			),
			ARRAY_A
		);

		if (!isset($collection['account_id'])) {
			return [];
		}

		$posts_list = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $post_table WHERE provider_id = %s",
				$collection['account_id']
			),
			ARRAY_A
		);

		$posts_list_result = [];
		//Loop over all posts to decrypt Facebook Posts
		$encryption = new Data_Encryption();

		foreach ($posts_list as $post) {
			$post['post_content'] = $post['provider'] === 'facebook' ? $encryption->maybe_decrypt($post['post_content']) : $post['post_content'];
			$post['json_data'] = $post['provider'] === 'facebook' ? $encryption->maybe_decrypt($post['json_data']) : $post['json_data'];
			array_push($posts_list_result, $post);
		}

		$collection['reviewsList'] = $posts_list_result;
		return $collection;
	}
}
