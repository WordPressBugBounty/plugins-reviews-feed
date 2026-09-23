<?php

namespace SmashBalloon\Reviews\Tests\Unit;

require_once __DIR__ . '/Doubles/FeedLocationsWpdbDouble.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Tests\Unit\Doubles\FeedLocationsWpdbDouble;

/**
 * SMASH-2103 / SMASH-2109 — the feed-location scan.
 *
 * The scan decides the number printed next to "Used in N places" and the page
 * list a delete dialog shows, so a parsing miss reads to an author as "this feed
 * is used nowhere" directly above a delete button. These cover the four
 * placement forms, the post types the scan reads, the ordering the display limit
 * truncates, and the cache invalidation that keeps the 12h map honest.
 *
 * The shortcode and block parsers are WordPress' own (loaded from wp-includes in
 * tests/bootstrap.php, not stubbed) because whether we read a placement the way
 * WordPress writes it IS the thing under test.
 */
class Smash2109FeedLocationScanTest extends TestCase
{
	/** @var FeedLocationsWpdbDouble */
	private $wpdb;

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void
	{
		parent::setUp();

		$this->original_wpdb = isset($GLOBALS['wpdb']) ? $GLOBALS['wpdb'] : null;
		$this->wpdb          = new FeedLocationsWpdbDouble();
		$GLOBALS['wpdb']     = $this->wpdb;

		$GLOBALS['wp_options_mock']          = array();
		$GLOBALS['wp_transients_mock']       = array();
		$GLOBALS['wp_filter_mock']           = array();
		$GLOBALS['wp_user_locale_mock']      = 'en_US';
		$GLOBALS['wp_current_blog_id_mock']  = 1;
		$GLOBALS['wp_post_revisions_mock']   = array();
		$GLOBALS['wp_post_autosaves_mock']   = array();
		$GLOBALS['wp_post_type_by_id_mock']  = array();
		$GLOBALS['wp_post_types_mock']       = array(
			'post'            => true,
			'page'            => true,
			'product'         => true,
			'edd_license_log' => false,
			'wp_block'        => false,
			'wp_template_part' => false,
		);

		$this->clear_memo();
	}

	protected function tearDown(): void
	{
		// The memo is a static on a production class; leaving it populated would
		// hand the next test this one's map.
		$this->clear_memo();

		$GLOBALS['wpdb'] = $this->original_wpdb;

		unset(
			$GLOBALS['wp_options_mock'],
			$GLOBALS['wp_transients_mock'],
			$GLOBALS['wp_filter_mock'],
			$GLOBALS['wp_user_locale_mock'],
			$GLOBALS['wp_current_blog_id_mock'],
			$GLOBALS['wp_post_revisions_mock'],
			$GLOBALS['wp_post_autosaves_mock'],
			$GLOBALS['wp_post_type_by_id_mock'],
			$GLOBALS['wp_post_types_mock']
		);

		parent::tearDown();
	}

	// ---------- post types the scan reads ----------

	public function test_scanned_post_types_excludes_non_public_types(): void
	{
		$types = DB::scanned_post_types();

		$this->assertContains('post', $types);
		$this->assertContains('page', $types);
		// The 3.98M rows that made the page hang. SMASH-2103.
		$this->assertNotContains('edd_license_log', $types);
	}

	public function test_scanned_post_types_includes_registered_block_containers(): void
	{
		$types = DB::scanned_post_types();

		// Not public, but real author content: a feed in a synced pattern is placed
		// on every page using it. SMASH-2109.
		$this->assertContains('wp_block', $types);
		$this->assertContains('wp_template_part', $types);
	}

	public function test_scanned_post_types_omits_containers_that_are_not_registered(): void
	{
		unset($GLOBALS['wp_post_types_mock']['wp_template_part']);

		$this->assertNotContains('wp_template_part', DB::scanned_post_types());
	}

	public function test_scanned_post_types_filter_can_re_admit_a_non_public_type(): void
	{
		$GLOBALS['wp_filter_mock']['sbr_feed_locations_post_types'] = array('post', 'my_builder_layout');

		$this->assertContains('my_builder_layout', DB::scanned_post_types());
	}

	public function test_scanned_post_types_survives_a_filter_returning_junk(): void
	{
		// A filter that desyncs the list from the %s placeholder count would break
		// prepare() on the marker queries, so the sanitisation runs after the filter.
		$GLOBALS['wp_filter_mock']['sbr_feed_locations_post_types'] = array('post', 'post', 17, null, 'page');

		$this->assertSame(array('post', 'page'), DB::scanned_post_types());
	}

	// ---------- placement form 1: the shortcode ----------

	public function test_shortcode_feed_id_is_parsed_not_prefix_matched(): void
	{
		// The LIKE '%feed=12%' this replaced also matched feed=127, so feed 12
		// claimed pages it was not on.
		$this->assertSame(array(12 => 12), $this->feed_ids_in_content('[reviews-feed feed=12]'));
		$this->assertSame(array(127 => 127), $this->feed_ids_in_content('[reviews-feed feed=127]'));
	}

	public function test_shortcode_id_is_read_quoted_or_bare(): void
	{
		$this->assertSame(array(8 => 8), $this->feed_ids_in_content('<p>x</p>[reviews-feed feed="8"]'));
		$this->assertSame(array(8 => 8), $this->feed_ids_in_content("[reviews-feed feed='8' num=3]"));
	}

	public function test_shortcode_without_a_feed_attribute_places_nothing(): void
	{
		$this->assertSame(array(), $this->feed_ids_in_content('[reviews-feed]'));
	}

	// ---------- placement forms 2 and 3: the two blocks ----------

	public function test_modern_block_feed_id_is_found(): void
	{
		$content = '<!-- wp:smashballoon/reviews-feed {"feedId":"3"} /-->';

		$this->assertSame(array(3 => 3), $this->feed_ids_in_content($content));
	}

	/**
	 * The wizard's shape: `feed="34"`, shortcode ATTRIBUTES rather than a shortcode
	 * (SB_Reviews_Blocks::wizard_shortcode_settings():206).
	 */
	public function test_legacy_block_wizard_attribute_string_is_found(): void
	{
		$content = '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\"34\" num=6"} /-->';

		$this->assertSame(array(34 => 34), $this->feed_ids_in_content($content));
	}

	/**
	 * The editor's shape: a bare number, because the block's number control assigns
	 * its parseInt() result (assets/js/sbr-blocks.js:203). get_feed_html():293-297
	 * renders a feed for it, so it is a placement, and reading it as post content
	 * found nothing at all.
	 */
	public function test_legacy_block_bare_numeric_setting_is_found(): void
	{
		$this->assertSame(
			array(34 => 34),
			$this->feed_ids_in_content('<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"34"} /-->')
		);
		// Defensive only — nothing we ship writes a JSON number into this attribute.
		$this->assertSame(
			array(34 => 34),
			$this->feed_ids_in_content('<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":34} /-->')
		);
	}

	/**
	 * KNOWN GAP, pinned so it is visible rather than mistaken for correct: an
	 * unconfigured legacy block renders the FIRST feed
	 * (SB_Reviews_Blocks::get_feed_html():292-302), so it IS a placement of that
	 * feed and this scan reports it as none. Pre-existing — the shortcode-only
	 * scan missed it too — and resolving it here would make the parser depend on
	 * feed-list ordering, so it is reported on the PR rather than guessed at.
	 *
	 * Feed 0 genuinely places nothing, and that part is a real assertion.
	 */
	public function test_unconfigured_legacy_block_is_a_known_under_report(): void
	{
		$this->assertSame(
			array(),
			$this->feed_ids_in_content('<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":""} /-->'),
			'current behaviour, NOT the desired one — the block renders the first feed'
		);
		$this->assertSame(
			array(),
			$this->feed_ids_in_content('<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"0"} /-->')
		);
	}

	public function test_block_nested_inside_containers_is_found(): void
	{
		// A flat pass over the top level reported this as nowhere.
		$content = '<!-- wp:columns --><!-- wp:column --><!-- wp:group -->'
			. '<!-- wp:smashballoon/reviews-feed {"feedId":5} /-->'
			. '<!-- /wp:group --><!-- /wp:column --><!-- /wp:columns -->';

		$this->assertSame(array(5 => 5), $this->feed_ids_in_content($content));
	}

	public function test_one_post_placing_a_feed_twice_yields_one_id(): void
	{
		$content = '[reviews-feed feed=4]<!-- wp:smashballoon/reviews-feed {"feedId":"4"} /-->';

		$this->assertSame(array(4 => 4), $this->feed_ids_in_content($content));
	}

	public function test_content_with_no_marker_places_nothing(): void
	{
		$this->assertSame(array(), $this->feed_ids_in_content('<p>Just a paragraph.</p>'));
	}

	// ---------- placement form 4: builder meta ----------

	public function test_elementor_widget_feed_id_is_found(): void
	{
		$meta = wp_json_encode(array(
			array(
				'elType'   => 'section',
				'elements' => array(
					array(
						'elType'     => 'widget',
						'widgetType' => 'sb-reviews-feed',
						'settings'   => array('feed_id' => '9'),
					),
				),
			),
		));

		$this->assertSame(array(9 => 9), $this->feed_ids_in_meta($meta));
	}

	public function test_another_plugins_widget_with_a_feed_id_is_not_ours(): void
	{
		// `feed_id` is defined on the base class every Smash Balloon plugin's
		// Elementor widget extends, so matching on the control alone attributed an
		// Instagram feed's id to a Reviews feed — on the list a delete dialog reads.
		$meta = wp_json_encode(array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'sb-instagram-feed',
				'settings'   => array('feed_id' => '9'),
			),
		));

		$this->assertSame(array(), $this->feed_ids_in_meta($meta));
	}

	public function test_widget_types_filter_admits_a_custom_wrapper(): void
	{
		$GLOBALS['wp_filter_mock']['sbr_feed_locations_widget_types'] = array('sb-reviews-feed', 'agency-reviews-wrapper');

		$meta = wp_json_encode(array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'agency-reviews-wrapper',
				'settings'   => array('feed_id' => '11'),
			),
		));

		$this->assertSame(array(11 => 11), $this->feed_ids_in_meta($meta));
	}

	public function test_builder_meta_that_is_not_json_is_read_as_content(): void
	{
		$this->assertSame(array(6 => 6), $this->feed_ids_in_meta('<div>[reviews-feed feed=6]</div>'));
	}

	// ---------- ordering under the display limit ----------

	/**
	 * The old per-feed query was `ORDER BY post_date DESC LIMIT $limit`, so a
	 * truncated list always showed the most recent pages. Candidates now arrive in
	 * scan order and are processed in 200-row batches sorted only within
	 * themselves, so the per-feed list has to be re-sorted before it is sliced.
	 *
	 * The fixture is built so this fails without that sort: the newest posts are
	 * the last IDs, which land in the second batch, while the limit takes from the
	 * front. Delete the uksort() in get_all_feed_shortcode_locations() and this
	 * returns 200, 199, 198 instead.
	 */
	public function test_truncated_locations_are_most_recent_first_across_batches(): void
	{
		$rows = array();
		for ($id = 1; $id <= 210; $id++) {
			$rows[] = array(
				'ID'           => $id,
				'post_title'   => 'Page ' . $id,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				// Strictly increasing with ID, so the newest posts are the last IDs
				// and land in the second batch while the limit takes from the front.
				'post_date'    => sprintf('2026-01-%02d %02d:00:00', 1 + intdiv($id - 1, 24), ($id - 1) % 24),
				'post_content' => '[reviews-feed feed=1]',
			);
		}
		$this->wpdb->rows = $rows;

		$locations = DB::get_feed_shortcode_locations(1, 3);

		$this->assertGreaterThan(1, $this->wpdb->batch_count, 'fixture must span more than one batch');
		$this->assertSame(
			array(210, 209, 208),
			array_map(static function ($location) {
				return (int) $location['post_id'];
			}, $locations)
		);
	}

	// ---------- "we could not check" is not "zero" ----------

	public function test_a_failed_query_reports_unknown_rather_than_empty(): void
	{
		$this->wpdb->last_error = 'MySQL server has gone away';

		$this->assertFalse(DB::get_all_feed_shortcode_locations());
		// The older public contract still hands back an array for existing callers.
		$this->assertSame(array(), DB::get_feed_shortcode_locations(1, 20));
		$this->assertSame(0, DB::count_feed_shortcode_locations(1));
	}

	// ---------- cache invalidation scoping ----------

	public function test_writing_an_unscanned_post_type_does_not_bust_the_cache(): void
	{
		// The reporting site writes millions of these. Flushing on them re-ran the
		// scan on nearly every page load, which is the cost SMASH-2103 removed.
		$GLOBALS['wp_post_type_by_id_mock'][77] = 'edd_license_log';

		sbr_flush_feed_locations_cache(77);

		$this->assertArrayNotHasKey(DB::FEED_LOCATIONS_VERSION_OPTION, $GLOBALS['wp_options_mock']);
	}

	public function test_writing_a_scanned_post_type_busts_the_cache(): void
	{
		$GLOBALS['wp_post_type_by_id_mock'][88] = 'page';

		sbr_flush_feed_locations_cache(88);

		$this->assertSame(2, $GLOBALS['wp_options_mock'][DB::FEED_LOCATIONS_VERSION_OPTION]);
	}

	public function test_editing_a_synced_pattern_busts_the_cache(): void
	{
		$GLOBALS['wp_post_type_by_id_mock'][89] = 'wp_block';

		sbr_flush_feed_locations_cache(89);

		$this->assertSame(2, $GLOBALS['wp_options_mock'][DB::FEED_LOCATIONS_VERSION_OPTION]);
	}

	/**
	 * `deleted_post` fires after the row is gone (wp-includes/post.php:3996), so
	 * get_post_type() answers false and a type check that relied on it would skip
	 * every deletion — leaving a deleted page listed as a location for 12 hours.
	 * The post object the action passes is the only source left.
	 */
	public function test_deletion_busts_the_cache_using_the_post_object(): void
	{
		$post = (object) array('ID' => 90, 'post_type' => 'page');

		$this->assertFalse(get_post_type(90), 'the row is gone by the time deleted_post fires');

		sbr_flush_feed_locations_cache(90, $post);

		$this->assertSame(2, $GLOBALS['wp_options_mock'][DB::FEED_LOCATIONS_VERSION_OPTION]);
	}

	/**
	 * The scoping is what the $post object buys us on the delete path: without it
	 * every deletion would flush, since the row is already gone.
	 */
	public function test_deleting_an_unscanned_post_type_does_not_bust_the_cache(): void
	{
		$post = (object) array('ID' => 93, 'post_type' => 'edd_license_log');

		sbr_flush_feed_locations_cache(93, $post);

		$this->assertArrayNotHasKey(DB::FEED_LOCATIONS_VERSION_OPTION, $GLOBALS['wp_options_mock']);
	}

	/**
	 * WP 5.0–5.4 pass no $post (core added it in 5.5, wp-includes/post.php:3991) and
	 * readme.txt:5 still declares 4.1. With the row gone the type is unknowable, so
	 * the hook flushes rather than returns: a needless re-scan beats a deleted page
	 * staying listed as a location for 12 hours.
	 */
	public function test_deletion_on_pre_5_5_core_still_busts_the_cache(): void
	{
		$this->assertFalse(get_post_type(94), 'no row, and no $post argument');

		sbr_flush_feed_locations_cache(94);

		$this->assertSame(2, $GLOBALS['wp_options_mock'][DB::FEED_LOCATIONS_VERSION_OPTION]);
	}

	public function test_revisions_and_autosaves_do_not_bust_the_cache(): void
	{
		$GLOBALS['wp_post_type_by_id_mock'][91] = 'page';
		$GLOBALS['wp_post_type_by_id_mock'][92] = 'page';
		$GLOBALS['wp_post_revisions_mock']      = array(91);
		$GLOBALS['wp_post_autosaves_mock']      = array(92);

		sbr_flush_feed_locations_cache(91);
		sbr_flush_feed_locations_cache(92);

		$this->assertArrayNotHasKey(DB::FEED_LOCATIONS_VERSION_OPTION, $GLOBALS['wp_options_mock']);
	}

	// ---------- helpers ----------

	/**
	 * @param string $content
	 * @return array<int, int>
	 */
	private function feed_ids_in_content($content)
	{
		return $this->call_private('feed_ids_in_content', array($content));
	}

	/**
	 * @param string $raw
	 * @return array<int, int>
	 */
	private function feed_ids_in_meta($raw)
	{
		return $this->call_private('feed_ids_in_meta', array($raw));
	}

	/**
	 * The parsers are private on purpose — they are not a contract we owe anyone.
	 * Reflection tests the shipped method rather than a public copy of it.
	 *
	 * @param string $name
	 * @param array  $args
	 * @return mixed
	 */
	private function call_private($name, array $args)
	{
		$method = (new ReflectionClass(DB::class))->getMethod($name);
		$method->setAccessible(true);

		return $method->invokeArgs(null, $args);
	}

	private function clear_memo(): void
	{
		$property = (new ReflectionClass(DB::class))->getProperty('feed_locations_memo');
		$property->setAccessible(true);
		$property->setValue(null, array());
	}
}
