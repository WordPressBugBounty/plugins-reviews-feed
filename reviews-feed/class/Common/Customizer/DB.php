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
	 * Number of sources per page.
	 */
	const SOURCES_PER_PAGE = 20;

	/**
	 * Number of collections per page on the Collections screen.
	 *
	 * Matches SOURCES_PER_PAGE deliberately, but is its own constant because the
	 * two lists are paged by different queries and one may need to move without
	 * the other. The front end must read this from the `perPage` the server
	 * sends rather than hardcode it — a second copy of the number is how the
	 * badge list ended up with a pager that disagreed with its own rows
	 * (SBR-163).
	 *
	 * @since SMASH-1599
	 */
	const COLLECTIONS_PER_PAGE = 20;

	/**
	 * Cached map of feed ID => shortcode locations. See SMASH-2103.
	 */
	const FEED_LOCATIONS_CACHE_KEY = 'sbr_feed_locations_map';

	/**
	 * 12 hours. Long enough that a slow scan never expires mid-page-load;
	 * post writes flush it, so edits are still picked up immediately.
	 */
	const FEED_LOCATIONS_CACHE_TTL = 43200;

	/**
	 * Ceiling on how many embedding posts one scan lists. Counts posts that embed a
	 * feed at all, not rows in wp_posts, and the query that hits it selects only IDs,
	 * so this is far past any real site. Filter: `sbr_feed_locations_scan_limit`.
	 */
	const FEED_LOCATIONS_SCAN_LIMIT = 20000;

	/**
	 * How many posts' post_content are held in memory at once while parsing.
	 */
	const FEED_LOCATIONS_BATCH_SIZE = 200;

	/**
	 * Bumped to invalidate every cached map at once. Keys carry it, so one write
	 * retires all of them without enumerating per-locale variants.
	 */
	const FEED_LOCATIONS_VERSION_OPTION = 'sbr_feed_locations_version';

	/**
	 * Per-request memo, keyed by blog + cache key, so N feeds do not read the
	 * transient N times.
	 *
	 * @var array<string, array>
	 */
	private static $feed_locations_memo = array();

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

		self::copy_feed_style_to_duplicate((int) $feed_id, (int) $wpdb->insert_id);

		echo sbr_json_encode(
			[
				'feedsList' => DB::get_feeds_list(),
				'feedsCount' => DB::feeds_list_count()
			]
		);
		wp_die();
	}

	/**
	 * Carry the source feed's rendered CSS onto its duplicate, re-scoped.
	 *
	 * `feed_style` is pre-rendered CSS whose every selector is scoped to the
	 * source feed's container id, so the INSERT above deliberately leaves it out
	 * — copying it verbatim would style the original, not the copy. The column
	 * being absent is what made a duplicate render with base styling only until
	 * someone opened and re-saved it, because the render path reads this column
	 * rather than regenerating from settings (Feed.php:57).
	 *
	 * @param int $source_id Feed the duplicate was copied from.
	 * @param int $new_id    Freshly inserted duplicate.
	 *
	 * @return void
	 */
	private static function copy_feed_style_to_duplicate($source_id, $new_id)
	{
		global $wpdb;

		if ($source_id <= 0 || $new_id <= 0) {
			return;
		}

		$feeds_table_name = $wpdb->prefix . SBR_FEEDS_TABLE;

		// Already-sanitised DB content going back to the DB: feed_style is passed
		// through sanitize_text_field() on save (SBR_Feed_Saver.php:280), which is
		// the only thing standing between it and the unescaped echo at
		// FeedDisplay.php:87. No new taint enters here, but this path
		// will faithfully propagate whatever is in the column — so a future write
		// path that skips that sanitiser would reach the duplicate too.
		$source_style = $wpdb->get_var(
			$wpdb->prepare("SELECT feed_style FROM $feeds_table_name WHERE id = %d", $source_id)
		);

		if (!is_string($source_style) || $source_style === '') {
			return;
		}

		// \b so a source id of 19 cannot match the 194 in another feed's selector.
		$rescoped = preg_replace(
			'/#' . preg_quote(sbr_container_id($source_id), '/') . '\b/',
			'#' . sbr_container_id($new_id),
			$source_style
		);

		if (!is_string($rescoped) || $rescoped === '') {
			return;
		}

		$wpdb->update(
			$feeds_table_name,
			['feed_style' => $rescoped],
			['id' => $new_id],
			['%s'],
			['%d']
		);
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
						$wpdb->esc_like($result['account_id'])
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
					$wpdb->esc_like($result['account_id'])
				), ARRAY_A);
			}
			$i++;
		}
		return !isset($affected[0]['account_id']) || is_null($affected[0]['account_id']) ? [] : $affected;
	}


	/**
	 * Get feeds list with optional location data.
	 *
	 * @param array $feeds_args     Query arguments.
	 * @param bool  $skip_locations Skip expensive shortcode location queries. Default false.
	 *
	 * @return array
	 */
	public static function get_feeds_list($feeds_args = array(), $skip_locations = false)
	{

		if (! empty($_GET['feed_id'])) {
			return array();
		}
		$db = new DB();
		$feeds_data = $db->feeds_query($feeds_args);

		// feeds_query() is typed array|bool. The loop below indexes it, and every
		// caller treats the result as a list, so a non-array is an empty list rather
		// than something to iterate.
		if (! is_array($feeds_data)) {
			return array();
		}

		// Once for the whole list. A failed scan is deliberately not memoized, so the
		// next page load retries — which also means every call re-runs the queries.
		// The branch below skips the per-feed readers entirely when this says
		// unknown, or a list of N feeds would pay 2N broken scans to learn what this
		// line already knows.
		$location_status = 'skipped';
		if (! $skip_locations) {
			$location_status = false === self::get_all_feed_shortcode_locations() ? 'unknown' : 'ok';
		}

		$i = 0;
		foreach ($feeds_data as $single_feed) {
			if ($skip_locations) {
				$feeds_data[ $i ]['instance_count']   = 0;
				$feeds_data[ $i ]['location_summary'] = array();
				// Not scanned, so not answered. The caller asked us to skip.
				$feeds_data[ $i ]['location_status']  = 'skipped';
			} elseif ('unknown' === $location_status) {
				// The scan already failed. Both readers below would call it again,
				// and a failed scan is deliberately not memoized, so asking per feed
				// re-runs the broken queries twice per row. They would return an empty
				// list and a zero anyway; location_status is what carries the meaning.
				$feeds_data[ $i ]['instance_count']   = 0;
				$feeds_data[ $i ]['location_summary'] = array();
				$feeds_data[ $i ]['location_status']  = $location_status;
			} else {
				// Use direct content scanning to find shortcode usage
				// This finds shortcodes in drafts, unpublished content, and all post types
				$locations = self::get_feed_shortcode_locations($single_feed['id'], DB::RESULTS_PER_PAGE);
				$count     = self::count_feed_shortcode_locations($single_feed['id']);
				$feeds_data[ $i ]['instance_count']   = $count;
				$feeds_data[ $i ]['location_summary'] = $locations;
				// Additive: an empty list means "nothing embeds this feed" only when
				// this says 'ok'. The delete dialog reads it before claiming a feed is
				// unused. See SMASH-2109.
				$feeds_data[ $i ]['location_status']  = $location_status;
			}

			$settings = json_decode($feeds_data[ $i ]['settings'], true);

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
	 * One page of collections, in the shape the Collections screen renders.
	 *
	 * Deliberately NOT a variant of get_collections_list(): that one answers
	 * "every collection" and the export on the Settings screen depends on it
	 * answering exactly that. This answers "the Nth page of collections, with
	 * the counts the list needs". Two questions, two methods.
	 *
	 * The SELECT mirrors source_query()'s paginated branch — same columns, same
	 * `used_in` / `reviews_number` derivations — because the rows land in the
	 * same React components. What it adds is a `provider = 'collection'`
	 * predicate, so the page is a page OF COLLECTIONS rather than a page of all
	 * sources that happens to contain some, which is the bug (SMASH-1599).
	 *
	 * ORDER BY is not optional. Without it MySQL may return grouped rows in any
	 * order, and LIMIT/OFFSET over an unordered result can skip or repeat a row
	 * between pages.
	 *
	 * Ordering by `s.id` is well defined here for a narrower reason than "it is
	 * the primary key": under GROUP BY, the selected `s.id` is an arbitrary
	 * representative of its group, and MySQL does not promise which. It is
	 * stable because a collection's `account_id` is
	 * `'collection-' . time() . wp_rand()` (SBR_Feed_Saver_Manager::create_new_collection),
	 * so it is unique and every group holds exactly one row — which makes the
	 * GROUP BY a no-op and `s.id` the row's own id. The GROUP BY is kept only to
	 * match source_query()'s shape, and get_collections_count() counts DISTINCT
	 * account_id so the two agree even if that ever stops being true.
	 *
	 * Selecting and ordering by a non-grouped column alongside GROUP BY is
	 * accepted because WordPress strips ONLY_FULL_GROUP_BY from the session
	 * sql_mode (wpdb::$incompatible_modes, wp-includes/class-wpdb.php:646) — the
	 * same reason source_query() has worked in this shape for years.
	 *
	 * @param int $page 1-based page number.
	 *
	 * @return array
	 *
	 * @since SMASH-1599
	 */
	public static function get_collections_page($page = 1)
	{
		global $wpdb;

		$per_page = self::COLLECTIONS_PER_PAGE;
		$page     = max(1, (int) $page);
		$offset   = ($page - 1) * $per_page;

		$sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$feeds_table   = $wpdb->prefix . SBR_FEEDS_TABLE;
		$posts_table   = $wpdb->prefix . POSTS_TABLE_NAME;

		$sql = $wpdb->prepare(
			"SELECT s.id, s.account_id, s.provider, s.access_token, s.name, s.info, s.error, s.expires,
				count(f.id) as used_in,
				(SELECT count(p.id) FROM $posts_table p WHERE s.account_id = p.provider_id) as reviews_number
			FROM $sources_table s
			LEFT JOIN $feeds_table f ON f.settings LIKE CONCAT('%', s.account_id, '%')
			WHERE s.provider = %s
			GROUP BY s.account_id
			ORDER BY s.id ASC
			LIMIT %d OFFSET %d",
			'collection',
			$per_page,
			$offset
		);

		$results = $wpdb->get_results($sql, ARRAY_A);

		if (empty($results)) {
			return array();
		}

		// Same per-row instance fetch source_query() does. The delete dialog
		// names the feeds a collection is used in, so a row without `instances`
		// warns about nothing and the customer deletes blind.
		foreach ($results as $i => $result) {
			if ((int) $result['used_in'] > 0) {
				$results[ $i ]['instances'] = $wpdb->get_results($wpdb->prepare(
					"SELECT *
					FROM $feeds_table
					WHERE settings LIKE CONCAT('%', %s, '%')
					GROUP BY id
					LIMIT 100;
					",
					$wpdb->esc_like($result['account_id'])
				), ARRAY_A);
			}
		}

		return $results;
	}

	/**
	 * How many collections exist.
	 *
	 * COUNT(DISTINCT account_id) rather than COUNT(*), because the page above
	 * groups by account_id. A plain COUNT(*) over duplicate account_ids would
	 * report more collections than any number of pages can show, and the last
	 * page would render short with the pager still offering a next one.
	 *
	 * @return int
	 *
	 * @since SMASH-1599
	 */
	public static function get_collections_count()
	{
		global $wpdb;

		$sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;

		$count = $wpdb->get_var($wpdb->prepare(
			"SELECT count(DISTINCT account_id) FROM $sources_table WHERE provider = %s",
			'collection'
		));

		return (int) $count;
	}

	/**
	 * Scan post_content once for every reviews-feed shortcode on the site.
	 *
	 * Replaces the two per-feed scans this used to run. The old queries filtered
	 * post_type with a NOT IN blacklist, which MySQL cannot serve from an index —
	 * on a site whose wp_posts is mostly non-content rows (4M edd_license_log on
	 * staging) that is a full 8.6GB table scan, ~50s each, twice per feed. Filtering
	 * to public post types instead lets the type_status_date index range-scan, and
	 * doing it once for all feeds makes the cost independent of feed count.
	 * Measured on a 2M-row / 4.1GB clone, 2 feeds, cold cache: 146s -> under 0.1s
	 * (median 0.006s over five runs). The 0.3s this line used to quote was the
	 * earlier per-feed variant, before the scan became one two-pass query.
	 * See SMASH-2103.
	 *
	 * @return array|false Map of feed ID => list of locations, or false when the scan
	 *                     could not run. False is not the same as an empty map: one
	 *                     means "we do not know", the other "nothing embeds a feed".
	 *
	 * @since 2.13.1
	 */
	public static function get_all_feed_shortcode_locations()
	{
		global $wpdb;

		// Status labels are translated into the cached rows, and WordPress has a
		// per-user admin language, so the locale belongs in the key.
		$cache_key = self::FEED_LOCATIONS_CACHE_KEY . '_v' . self::feed_locations_version() . '_' . get_user_locale();
		// The transient is per-blog; the memo has to be too or switch_to_blog()
		// would hand blog A's map to blog B.
		$memo_key  = get_current_blog_id() . ':' . $cache_key;

		if (isset(self::$feed_locations_memo[ $memo_key ])) {
			return self::$feed_locations_memo[ $memo_key ];
		}

		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			self::$feed_locations_memo[ $memo_key ] = $cached;
			return $cached;
		}

		$post_types = self::scanned_post_types();

		if (empty($post_types)) {
			return array();
		}
		$type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));

		/**
		 * Filters how many embedding posts one scan examines.
		 *
		 * @param int $limit Default self::FEED_LOCATIONS_SCAN_LIMIT.
		 */
		$scan_limit = (int) apply_filters('sbr_feed_locations_scan_limit', self::FEED_LOCATIONS_SCAN_LIMIT);
		if ($scan_limit < 1) {
			$scan_limit = self::FEED_LOCATIONS_SCAN_LIMIT;
		}

		/**
		 * Filters the post meta keys searched for builder-placed feeds.
		 *
		 * Elementor keeps its layout in `_elementor_data`; another builder can be
		 * added here. Mirrors `sbr_badge_instance_meta_keys`.
		 *
		 * @param string[] $meta_keys
		 */
		$meta_keys = apply_filters('sbr_feed_locations_meta_keys', array('_elementor_data'));
		$meta_keys = array_values(array_unique(array_filter((array) $meta_keys, 'is_string')));
		$key_placeholders = empty($meta_keys)
			? ''
			: implode(',', array_fill(0, count($meta_keys), '%s'));

		// Two passes. The first asks only for IDs, so no LONGTEXT crosses the wire
		// and the ceiling can be high enough that no real site reaches it. Either
		// query returning false means we could not answer, which is not the same as
		// answering zero.
		$post_ids = self::feed_location_content_ids($post_types, $type_placeholders, $scan_limit);
		if (false === $post_ids) {
			return false;
		}

		$meta_post_ids = self::feed_location_meta_ids(
			$post_types,
			$type_placeholders,
			$meta_keys,
			$key_placeholders,
			$scan_limit
		);
		if (false === $meta_post_ids) {
			return false;
		}

		$post_ids = array_values(array_unique(array_merge($post_ids, $meta_post_ids)));

		$locations_by_feed = array();
		// Batches are sorted individually, so the per-feed list has to be re-sorted
		// globally before it is truncated. See the sort below the loop.
		$dates_by_post = array();

		// Second pass, batched, so post_content memory stays bounded by the batch
		// rather than by how many posts embed a feed.
		foreach (array_chunk($post_ids, self::FEED_LOCATIONS_BATCH_SIZE) as $batch) {
			// get_the_permalink() below is a get_post() per row otherwise.
			_prime_post_caches($batch, false, false);

			$id_placeholders = implode(',', array_fill(0, count($batch), '%d'));
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status, post_date, post_content
					FROM {$wpdb->posts}
					WHERE ID IN ($id_placeholders)
					ORDER BY post_date DESC",
					$batch
				),
				ARRAY_A
			);

			// Builder meta for this batch, in one query rather than one per post.
			$meta_by_post = array();
			if (! empty($meta_keys)) {
				$batch_meta = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_value
						FROM {$wpdb->postmeta}
						WHERE post_id IN ($id_placeholders)
						AND meta_key IN ($key_placeholders)",
						array_merge($batch, $meta_keys)
					),
					ARRAY_A
				);

				foreach ((array) $batch_meta as $meta_row) {
					$meta_by_post[ (int) $meta_row['post_id'] ][] = (string) $meta_row['meta_value'];
				}
			}

			foreach ((array) $rows as $row) {
				$post_id  = (int) $row['ID'];
				$feed_ids = self::feed_ids_in_content($row['post_content']);

				$dates_by_post[ $post_id ] = (string) $row['post_date'];

				foreach (isset($meta_by_post[ $post_id ]) ? $meta_by_post[ $post_id ] : array() as $raw) {
					foreach (self::feed_ids_in_meta($raw) as $id) {
						$feed_ids[ $id ] = $id;
					}
				}

				foreach ($feed_ids as $feed_id) {
					// A post embedding the same feed twice is still one location, which is
					// what the COUNT(*) it replaces reported.
					if (isset($locations_by_feed[ $feed_id ][ $post_id ])) {
						continue;
					}

					$locations_by_feed[ $feed_id ][ $post_id ] = self::format_feed_location($row);
				}
			}
		}

		// The caller slices this list to a display limit, and the query it replaced
		// ordered by post_date DESC before its own LIMIT. Candidate IDs now arrive
		// content-pass first, then meta-pass, in 200-row batches sorted only within
		// themselves, so without this a truncated list showed whichever pages the
		// batching happened to reach first rather than the most recent ones.
		foreach ($locations_by_feed as $feed_id => $locations) {
			$locations_by_feed[ $feed_id ] = self::sort_locations_by_date($locations, $dates_by_post);
		}

		// Long TTL: the scan must not expire before the page that triggered it finishes.
		// save_post/deleted_post flush this, so staleness is bounded by edits, not by time.
		set_transient($cache_key, $locations_by_feed, self::FEED_LOCATIONS_CACHE_TTL);
		self::$feed_locations_memo[ $memo_key ] = $locations_by_feed;

		return $locations_by_feed;
	}

	/**
	 * Candidate post IDs whose post_content mentions a feed, by any placement form.
	 *
	 * Three markers, not one: a feed reaches a page as a shortcode, as the modern
	 * block or as the legacy block, and neither block form writes `[reviews-feed`
	 * into post_content. Matching only the shortcode reported a block-placed feed
	 * as used in no places. See SMASH-2109.
	 *
	 * @param string[] $post_types        Post types to search.
	 * @param string   $type_placeholders Prepared placeholder list for them.
	 * @param int      $scan_limit        Row ceiling.
	 *
	 * @return array<int, int>|false IDs, or false when the query failed.
	 *
	 * @since 2.13.1
	 */
	private static function feed_location_content_ids($post_types, $type_placeholders, $scan_limit)
	{
		global $wpdb;

		$markers = array(
			'[reviews-feed',
			'wp:smashballoon/reviews-feed',
			'wp:sbr/sbr-feed-block',
		);
		$marker_sql = implode(' OR ', array_fill(0, count($markers), 'post_content LIKE %s'));

		$args = array();
		foreach ($markers as $marker) {
			$args[] = '%' . $wpdb->esc_like($marker) . '%';
		}
		$args = array_merge($args, $post_types, array($scan_limit));

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE ($marker_sql)
				AND post_type IN ($type_placeholders)
				AND post_status NOT IN ('auto-draft', 'inherit', 'trash')
				ORDER BY post_date DESC
				LIMIT %d",
				$args
			)
		);

		// get_col() returns array() on a failed query too, and an empty map cached
		// for 12 hours tells every feed it is used nowhere, right under a delete
		// button. Say "we do not know" instead.
		if ('' !== (string) $wpdb->last_error) {
			return false;
		}

		return array_map('intval', (array) $ids);
	}

	/**
	 * Candidate post IDs whose builder meta mentions a feed.
	 *
	 * Page builders do not write to post_content, so a content-only scan never sees
	 * them. Mirrors SBR_Trust_Badge_Store::meta_candidates().
	 *
	 * This is another leading-wildcard LIKE, which is what SMASH-2103 was about, so:
	 * meta_key is indexed and compared for equality, and EXPLAIN confirms it is used
	 * (type: ref, key: meta_key) with the posts join on PRIMARY. The LIKE therefore
	 * runs over one row per builder post, not over wp_postmeta. The blacklist that
	 * caused SMASH-2103 had no index it could use at all.
	 *
	 * @param string[] $post_types        Post types to search.
	 * @param string   $type_placeholders Prepared placeholder list for them.
	 * @param string[] $meta_keys         Builder meta keys.
	 * @param string   $key_placeholders  Prepared placeholder list for them.
	 * @param int      $scan_limit        Row ceiling.
	 *
	 * @return array<int, int>|false IDs, or false when the query failed.
	 *
	 * @since 2.13.1
	 */
	private static function feed_location_meta_ids($post_types, $type_placeholders, $meta_keys, $key_placeholders, $scan_limit)
	{
		global $wpdb;

		if (empty($meta_keys)) {
			return array();
		}

		// One marker per thing feed_ids_in_builder_tree() can recognise, not just the
		// default widget's name. A type added through sbr_feed_locations_widget_types
		// need not contain "reviews-feed" anywhere, and a single hardcoded pre-filter
		// would drop those rows before the parser ever saw them — reintroducing
		// "used in 0 places" for exactly the sites that reached for the filter.
		$markers = array_values(array_unique(array_merge(
			array('reviews-feed'),
			self::builder_widget_types()
		)));
		$marker_sql = implode(' OR ', array_fill(0, count($markers), 'm.meta_value LIKE %s'));

		$marker_args = array();
		foreach ($markers as $marker) {
			$marker_args[] = '%' . $wpdb->esc_like($marker) . '%';
		}

		$args = array_merge(
			$meta_keys,
			$marker_args,
			$post_types,
			array($scan_limit)
		);

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key IN ($key_placeholders)
				AND ($marker_sql)
				AND p.post_type IN ($type_placeholders)
				AND p.post_status NOT IN ('auto-draft', 'inherit', 'trash')
				ORDER BY p.post_date DESC
				LIMIT %d",
				$args
			)
		);

		if ('' !== (string) $wpdb->last_error) {
			return false;
		}

		return array_map('intval', (array) $ids);
	}

	/**
	 * Builder widget types that place a Reviews feed.
	 *
	 * @return string[]
	 *
	 * @since 2.13.1
	 */
	private static function builder_widget_types()
	{
		/**
		 * Filters the builder widget types treated as a Reviews feed placement.
		 *
		 * @param string[] $types Elementor widget names.
		 */
		$types = apply_filters('sbr_feed_locations_widget_types', array('sb-reviews-feed'));

		return array_values(array_filter((array) $types, 'is_string'));
	}

	/**
	 * Every feed ID placed by a piece of post content.
	 *
	 * A feed reaches a page four ways and each serialises its id differently, so a
	 * scan that knows only one of them reports the other three as "used in 0 places"
	 * — directly under a delete button. See SMASH-2109.
	 *
	 * Parsed rather than pattern-matched wherever WordPress gives us a parser, which
	 * is what keeps `feed=12` from matching `feed=127`. Same approach, and the same
	 * reason, as SBR_Trust_Badge_Store::content_places_badge().
	 *
	 * @param string $content Post content.
	 *
	 * @return array<int, int> Feed IDs, keyed by themselves.
	 *
	 * @since 2.13.1
	 */
	private static function feed_ids_in_content($content)
	{
		$ids = array();

		if (! is_string($content) || $content === '') {
			return $ids;
		}

		if (false !== strpos($content, '[reviews-feed')) {
			$pattern = get_shortcode_regex(array('reviews-feed'));

			if (preg_match_all('/' . $pattern . '/', $content, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $shortcode) {
					if (! isset($shortcode[3])) {
						continue;
					}

					// shortcode_parse_atts() hands back the raw string when a shortcode
					// carries no attributes; only an array can hold a feed ID.
					$atts = shortcode_parse_atts($shortcode[3]);
					if (! is_array($atts) || empty($atts['feed'])) {
						continue;
					}

					$id = (int) $atts['feed'];
					if ($id > 0) {
						$ids[ $id ] = $id;
					}
				}
			}
		}

		$has_block = false !== strpos($content, 'wp:smashballoon/reviews-feed')
			|| false !== strpos($content, 'wp:sbr/sbr-feed-block');

		if ($has_block && function_exists('parse_blocks')) {
			foreach (self::feed_ids_in_blocks(parse_blocks($content)) as $id) {
				$ids[ $id ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Walk parsed blocks, including nested ones.
	 *
	 * Recursive because a feed can sit inside a Group, a Column or a synced pattern,
	 * and a flat pass over the top level would report those as nowhere.
	 *
	 * @param array $blocks Parsed blocks.
	 *
	 * @return array<int, int>
	 *
	 * @since 2.13.1
	 */
	private static function feed_ids_in_blocks($blocks)
	{
		$ids = array();

		foreach ((array) $blocks as $block) {
			if (! is_array($block)) {
				continue;
			}

			$name  = isset($block['blockName']) ? (string) $block['blockName'] : '';
			$attrs = ( isset($block['attrs']) && is_array($block['attrs']) ) ? $block['attrs'] : array();

			// Modern block: the id is an attribute (block.json declares `feedId`).
			if ($name === 'smashballoon/reviews-feed' && isset($attrs['feedId'])) {
				$id = (int) $attrs['feedId'];
				if ($id > 0) {
					$ids[ $id ] = $id;
				}
			}

			if ($name === 'sbr/sbr-feed-block' && isset($attrs['shortcodeSettings'])) {
				foreach (self::feed_ids_in_shortcode_settings($attrs['shortcodeSettings']) as $id) {
					$ids[ $id ] = $id;
				}
			}

			if (! empty($block['innerBlocks'])) {
				foreach (self::feed_ids_in_blocks($block['innerBlocks']) as $id) {
					$ids[ $id ] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Feed IDs from the legacy block's `shortcodeSettings` attribute.
	 *
	 * It holds shortcode ATTRIBUTES, not a shortcode, and in two shapes: the wizard
	 * writes `feed="3"` (SB_Reviews_Blocks::wizard_shortcode_settings():206), while
	 * the block's feed picker writes the bare id — its onChange stores the selected
	 * option's value (assets/js/sbr-blocks.js:203), and those values are feed ids
	 * (get_feed_list_options():172). SB_Reviews_Blocks::get_feed_html() renders a
	 * feed for either, at :303-304 for the bare id. Reading this as post content
	 * found neither, which left the legacy block reporting "used in 0 places" —
	 * the bug SMASH-2109 is about.
	 *
	 * NOT covered: an unconfigured block, whose empty attribute renders the FIRST
	 * feed (SB_Reviews_Blocks::get_feed_html():292-302). Resolving that here would
	 * make the parser depend on feed-list ordering, so it is reported rather than
	 * guessed at. See the PR thread on this method.
	 *
	 * @param mixed $raw Attribute value as stored in the block comment.
	 *
	 * @return array<int, int>
	 *
	 * @since 2.13.1
	 */
	private static function feed_ids_in_shortcode_settings($raw)
	{
		$ids = array();

		// Defence only: the attribute is declared `type: string` and the picker
		// stores a string, so nothing we ship writes a JSON number here.
		if (is_int($raw) || is_float($raw)) {
			$raw = (string) $raw;
		}

		if (! is_string($raw) || $raw === '') {
			return $ids;
		}

		// A whole shortcode, for content pasted into the attribute.
		foreach (self::feed_ids_in_content($raw) as $id) {
			$ids[ $id ] = $id;
		}

		if (is_numeric($raw)) {
			$id = (int) $raw;
			if ($id > 0) {
				$ids[ $id ] = $id;
			}

			return $ids;
		}

		$atts = shortcode_parse_atts($raw);
		if (is_array($atts) && ! empty($atts['feed'])) {
			$id = (int) $atts['feed'];
			if ($id > 0) {
				$ids[ $id ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Feed IDs placed from POST META rather than post_content.
	 *
	 * Page builders do not write to post_content. Elementor keeps its whole layout in
	 * `_elementor_data`, so a feed dropped into the Reviews Feed widget was invisible
	 * to a content-only scan. SBR_Trust_Badge_Store hit this first and fixed it the
	 * same way for badges; SBR_Schema_Service reads the same meta for the same reason.
	 *
	 * @param string $raw Raw meta value.
	 *
	 * @return array<int, int>
	 *
	 * @since 2.13.1
	 */
	private static function feed_ids_in_meta($raw)
	{
		if (! is_string($raw) || $raw === '') {
			return array();
		}

		$decoded = json_decode($raw, true);

		// Not JSON: test it as content, which covers a builder storing plain markup.
		if (! is_array($decoded)) {
			return self::feed_ids_in_content($raw);
		}

		return self::feed_ids_in_builder_tree($decoded);
	}

	/**
	 * Walk a decoded builder layout for feed placements.
	 *
	 * Two ways a feed appears in one: as the Reviews Feed widget, whose control is
	 * `feed_id` (SB_Elementor_Feed_Widget.php:72), or as a shortcode typed into a
	 * text widget, which is why every string is also read as content.
	 *
	 * The widget type has to match. `feed_id` is defined on the SHARED base class
	 * every Smash Balloon plugin's Elementor widget extends, so a page holding a
	 * Reviews widget next to an Instagram one would otherwise report the Instagram
	 * widget's feed id as a Reviews placement — on the list a delete dialog reads.
	 *
	 * @param mixed $value Decoded value.
	 *
	 * @return array<int, int>
	 *
	 * @since 2.13.1
	 */
	private static function feed_ids_in_builder_tree($value)
	{
		$ids = array();

		if (is_string($value)) {
			return self::feed_ids_in_content($value);
		}

		if (! is_array($value)) {
			return $ids;
		}

		if (
			isset($value['widgetType'], $value['settings']['feed_id'])
			&& in_array((string) $value['widgetType'], self::builder_widget_types(), true)
		) {
			$id = (int) $value['settings']['feed_id'];
			if ($id > 0) {
				$ids[ $id ] = $id;
			}
		}

		foreach ($value as $item) {
			foreach (self::feed_ids_in_builder_tree($item) as $id) {
				$ids[ $id ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Post types the feed-location scan looks at.
	 *
	 * Shared with the invalidation hook in sbr-functions.php: a write to a type that
	 * is not scanned cannot change the map, and flushing on one re-ran the scan on
	 * exactly the high-volume non-content rows SMASH-2103 exists to stay away from.
	 * The two lists have to be the same list, so they are.
	 *
	 * Public types only — a shortcode in a log or a font-face row is not a feed
	 * location, and a blacklist could never keep up with what plugins register.
	 * The two block-editor containers are the exception: not public, but real author
	 * content, since a feed in a synced pattern or a template part is placed on every
	 * page that uses it. See SMASH-2109.
	 *
	 * @return string[]
	 *
	 * @since 2.13.1
	 */
	public static function scanned_post_types()
	{
		// get_post_types() already returns a flat list of names; the array_values()
		// below normalises the keys after the containers are appended.
		$post_types = get_post_types(array('public' => true));

		foreach (array( 'wp_block', 'wp_template_part' ) as $container) {
			if (post_type_exists($container)) {
				$post_types[] = $container;
			}
		}

		/**
		 * Filters the post types the feed-location scan looks at.
		 *
		 * register_post_type() defaults to `public => false`, so a plugin storing
		 * real content in a non-public type is reachable only through this. The
		 * invalidation hook reads the same filtered list, so a type added here is
		 * both scanned and able to flush the cache.
		 *
		 * @param string[] $post_types Public post types plus the block-editor containers.
		 */
		$post_types = apply_filters('sbr_feed_locations_post_types', $post_types);

		return array_values(array_unique(array_filter((array) $post_types, 'is_string')));
	}

	/**
	 * Most-recent-first, for a feed's collected locations.
	 *
	 * @param array<int, array<string, mixed>> $locations     Locations keyed by post ID.
	 * @param array<int, string>               $dates_by_post post_date per post ID.
	 *
	 * @return array<int, array<string, mixed>> Locations, keys dropped.
	 *
	 * @since 2.13.1
	 */
	private static function sort_locations_by_date($locations, $dates_by_post)
	{
		uksort(
			$locations,
			static function ($a, $b) use ($dates_by_post) {
				$date_a = isset($dates_by_post[ $a ]) ? $dates_by_post[ $a ] : '';
				$date_b = isset($dates_by_post[ $b ]) ? $dates_by_post[ $b ] : '';

				// Same timestamp is common on imported content; fall back to ID so
				// the order is stable rather than dependent on scan order.
				return $date_a === $date_b ? ($b <=> $a) : strcmp($date_b, $date_a);
			}
		);

		return array_values($locations);
	}

	/**
	 * Build the display shape for one located post.
	 *
	 * @param array{ID: int|string, post_title: string, post_type: string, post_status: string} $row Located post row.
	 *
	 * @return array{link: string, page_text: string, post_id: int|string, post_type: string}
	 *
	 * @since 2.13.1
	 */
	private static function format_feed_location($row)
	{
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

		return array(
			'link'      => esc_url(get_the_permalink((int) $row['ID'])),
			'page_text' => $page_text,
			'post_id'   => $row['ID'],
			'post_type' => $row['post_type'],
		);
	}

	/**
	 * Drop the cached location map. Hooked to post writes in sbr-functions.php.
	 *
	 * @since 2.13.1
	 */
	public static function flush_feed_shortcode_locations(): void
	{
		self::$feed_locations_memo = array();

		// Bumping the version retires every locale's entry at once. The orphans
		// carry their own TTL, so nothing has to enumerate them.
		update_option(self::FEED_LOCATIONS_VERSION_OPTION, self::feed_locations_version() + 1, false);
	}

	/**
	 * Current cache generation, floored at 1.
	 *
	 * @return int
	 *
	 * @since 2.13.1
	 */
	private static function feed_locations_version(): int
	{
		$version = get_option(self::FEED_LOCATIONS_VERSION_OPTION, 1);

		return is_numeric($version) && (int) $version > 0 ? (int) $version : 1;
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
		if (empty($feed_id)) {
			return array();
		}

		$feed_id = (int) $feed_id;
		$all     = self::get_all_feed_shortcode_locations();

		// Unchanged contract: this has always returned an array. A failed scan reads
		// as "no locations" here; get_feeds_list() carries the distinction instead.
		$locations = ( is_array($all) && isset($all[ $feed_id ]) ) ? $all[ $feed_id ] : array();

		return array_slice($locations, 0, max(0, (int) $limit));
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
		if (empty($feed_id)) {
			return 0;
		}

		$feed_id = (int) $feed_id;
		$all     = self::get_all_feed_shortcode_locations();

		return ( is_array($all) && isset($all[ $feed_id ]) ) ? count($all[ $feed_id ]) : 0;
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
