<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

require_once __DIR__ . '/../Doubles/FeedLocationsWpdbDouble.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;
use SmashBalloon\Reviews\Tests\Unit\Doubles\FeedLocationsWpdbDouble;

/**
 * SMASH-2116 — badge instance counts stop scanning all of wp_posts.
 *
 * The query named no post type at all, so MySQL had nothing to range-scan on, and
 * past 200 badge-bearing posts it ran once PER BADGE with only a per-request memo
 * behind it. The Badges list therefore issued N unindexed full table scans per
 * page load. Fourth appearance of the shape SMASH-1052, SMASH-1591 and SMASH-2103
 * each met in the feed-location scan.
 */
class Smash2116BadgeInstanceScanTest extends TestCase
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

		$GLOBALS['wp_options_mock']         = array();
		$GLOBALS['wp_transients_mock']      = array();
		$GLOBALS['wp_filter_mock']          = array();
		$GLOBALS['wp_post_meta_mock']       = array();
		$GLOBALS['wp_post_revisions_mock']  = array();
		$GLOBALS['wp_post_autosaves_mock']  = array();
		$GLOBALS['wp_post_type_by_id_mock'] = array();
		$GLOBALS['wp_query_calls']          = array();
		$GLOBALS['wp_current_blog_id_mock'] = 1;
		$GLOBALS['wp_transient_reads']      = array();
		$GLOBALS['wp_parse_blocks_calls']   = 0;
		$GLOBALS['wp_query_posts']          = array( 7, 8 );
		$GLOBALS['wp_post_types_mock']      = array(
			'post'             => true,
			'page'             => true,
			'edd_license_log'  => false,
			'wp_block'         => false,
			'wp_template_part' => false,
		);

		$this->reset_map();
	}

	protected function tearDown(): void
	{
		$this->reset_map();

		$GLOBALS['wpdb'] = $this->original_wpdb;

		unset(
			$GLOBALS['wp_options_mock'],
			$GLOBALS['wp_transients_mock'],
			$GLOBALS['wp_filter_mock'],
			$GLOBALS['wp_post_meta_mock'],
			$GLOBALS['wp_post_revisions_mock'],
			$GLOBALS['wp_post_autosaves_mock'],
			$GLOBALS['wp_post_type_by_id_mock'],
			$GLOBALS['wp_query_calls'],
			$GLOBALS['wp_current_blog_id_mock'],
			$GLOBALS['wp_transient_reads'],
			$GLOBALS['wp_parse_blocks_calls'],
			$GLOBALS['wp_query_posts'],
			$GLOBALS['wp_post_types_mock']
		);

		parent::tearDown();
	}

	// ---------- the query shape that made this a full table scan ----------

	public function test_the_scan_names_post_types_so_an_index_can_serve_it(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		SBR_Trust_Badge_Store::get_instances(7);

		$scan = $this->first_posts_scan();

		$this->assertStringContainsString('post_type IN (', $scan, 'the query must narrow by post type');
		$this->assertStringContainsString("'page'", $scan);
		// The 3.98M rows that made SMASH-2103 a ten-minute page.
		$this->assertStringNotContainsString('edd_license_log', $scan);
	}

	public function test_the_scan_no_longer_orders_by_title(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		SBR_Trust_Badge_Store::get_instances(7);

		// ORDER BY post_title ASC LIMIT 200 windowed the SITE's badge-bearing posts,
		// so past the limit it held the alphabetically-first ones whatever badge they
		// placed. See the late-alphabet test below.
		$this->assertStringNotContainsString('post_title ASC', $this->first_posts_scan());
	}

	public function test_one_scan_answers_every_badge(): void
	{
		$GLOBALS['wp_query_posts'] = array( 7, 8, 9 );
		$this->seed(array(
			$this->post(101, 'One', '[reviews-feed-badge id="7"]'),
			$this->post(102, 'Two', '[reviews-feed-badge id="8"]'),
			$this->post(103, 'Three', '[reviews-feed-badge id="9"]'),
		));

		SBR_Trust_Badge_Store::get_instances(7);
		SBR_Trust_Badge_Store::get_instances(8);
		SBR_Trust_Badge_Store::get_instances(9);

		// Was one candidate query per badge once the site passed the scan limit,
		// which is what an admin list of N badges turned into N full scans.
		$this->assertSame(1, $this->count_posts_scans(), 'the Badges list must scan once, not once per badge');
	}

	/**
	 * The scan itself is covered by the transient, so this pins what the in-request
	 * memo is actually for: a 100-badge list otherwise reads the stored map 100
	 * times, which on a site with no persistent object cache is 100 option reads.
	 */
	public function test_the_stored_map_is_read_once_per_request_not_once_per_badge(): void
	{
		$GLOBALS['wp_query_posts'] = array( 7, 8, 9 );
		$this->seed(array( $this->post(101, 'One', '[reviews-feed-badge id="7"]') ));

		SBR_Trust_Badge_Store::get_instances(7);
		$GLOBALS['wp_transient_reads'] = array();

		SBR_Trust_Badge_Store::get_instances(8);
		SBR_Trust_Badge_Store::get_instances(9);

		$key   = SBR_Trust_Badge_Store::instance_map_cache_key();
		$reads = isset($GLOBALS['wp_transient_reads'][ $key ]) ? $GLOBALS['wp_transient_reads'][ $key ] : 0;

		$this->assertSame(0, $reads, 'the memo must answer the rest of the page');
	}

	public function test_each_badge_still_gets_only_its_own_placements(): void
	{
		$GLOBALS['wp_query_posts'] = array( 7, 8 );
		$this->seed(array(
			$this->post(101, 'Seven here', '[reviews-feed-badge id="7"]'),
			$this->post(102, 'Eight here', '[reviews-feed-badge id="8"]'),
		));

		$seven = SBR_Trust_Badge_Store::get_instances(7);
		$eight = SBR_Trust_Badge_Store::get_instances(8);

		$this->assertSame(1, $seven['count']);
		$this->assertSame('Seven here', $seven['items'][0]['name']);
		$this->assertSame(1, $eight['count']);
		$this->assertSame('Eight here', $eight['items'][0]['name']);
	}

	/**
	 * The bug the old 200-row `ORDER BY post_title ASC` window caused: the limit
	 * applied to every badge-bearing post on the site, so a badge placed only on a
	 * late-alphabet post came back with count 0 — under a delete button.
	 */
	public function test_a_badge_placed_only_on_a_late_alphabet_post_is_found(): void
	{
		$rows = array();
		for ($i = 0; $i < 250; $i++) {
			$rows[] = $this->post(200 + $i, sprintf('Aardvark %03d', $i), '[reviews-feed-badge id="8"]');
		}
		$rows[] = $this->post(999, 'Zebra, the only page with badge 7', '[reviews-feed-badge id="7"]');
		$this->seed($rows);

		$result = SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(1, $result['count']);
		$this->assertSame('Zebra, the only page with badge 7', $result['items'][0]['name']);
	}

	// ---------- the cache that did not survive the request ----------

	public function test_the_map_is_reused_from_the_transient(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		SBR_Trust_Badge_Store::get_instances(7);
		$this->assertSame(1, $this->count_posts_scans());

		// A second request: the static memo is gone, the transient is not.
		$this->forget_statics();
		$this->wpdb->queries = array();

		$result = SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(0, $this->count_posts_scans(), 'the map must outlive the request');
		$this->assertSame(1, $result['count']);
	}

	public function test_flushing_drops_the_stored_map(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertNotFalse($this->stored_map());

		SBR_Trust_Badge_Store::flush_instance_scans();

		$this->assertFalse($this->stored_map());
	}

	/**
	 * A failed query and an empty site both hand back no rows. Caching that for
	 * twelve hours would tell every author their badges are placed nowhere, which
	 * is the message printed above the delete button.
	 */
	public function test_a_failed_scan_is_not_cached_as_zero(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->last_error = 'MySQL server has gone away';

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertFalse($this->stored_map(), 'a failed scan must be retried, not remembered');
	}

	/**
	 * The ID pass failing with NO rows back is the case only its own guard catches:
	 * with no ids there is no batch loop, so the batch guard never runs and an empty
	 * map would be cached for twelve hours.
	 */
	public function test_a_failed_id_pass_with_no_rows_is_not_cached(): void
	{
		$this->seed(array());
		$this->wpdb->last_error = 'Lost connection to MySQL server during query';

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertFalse($this->stored_map(), 'an empty result from a failed query is not "no badges are placed"');
	}

	public function test_the_post_types_filter_can_widen_the_scan(): void
	{
		$GLOBALS['wp_filter_mock']['sbr_badge_instance_post_types'] = array( 'page', 'my_builder_layout' );
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertStringContainsString('my_builder_layout', $this->first_posts_scan());
	}

	/**
	 * A batch query that fails mid-scan drops every post from that batch on. Caching
	 * that would store an under-report for twelve hours, under the delete button —
	 * the harm this ticket exists to remove, re-entering through the second query.
	 */
	public function test_a_failed_batch_is_not_cached_either(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->fail_on_results = true;

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertFalse($this->stored_map(), 'a failed batch must be retried, not remembered');
	}

	/**
	 * content_places_badge() calls parse_blocks() every time. Testing it per badge
	 * re-parsed the same post for each one: at the 20,000-row ceiling with 40 badges
	 * that is 800,000 parses of the same strings on one cold request.
	 */
	public function test_each_post_is_parsed_once_not_once_per_badge(): void
	{
		$GLOBALS['wp_query_posts'] = array( 7, 8, 9, 10 );
		$this->seed(array(
			$this->post(101, 'Block page', '<!-- wp:sbr/trust-badge {"id":7} /-->'),
			$this->post(102, 'Other block page', '<!-- wp:sbr/trust-badge {"id":8} /-->'),
		));
		$GLOBALS['wp_parse_blocks_calls'] = 0;

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(2, $GLOBALS['wp_parse_blocks_calls'], 'one parse per row, not per row per badge');
	}

	public function test_a_block_placed_badge_is_still_found_after_the_parse_hoist(): void
	{
		$GLOBALS['wp_query_posts'] = array( 7, 8 );
		$this->seed(array(
			$this->post(101, 'Block page', '<!-- wp:sbr/trust-badge {"id":7} /-->'),
		));

		$this->assertSame(1, SBR_Trust_Badge_Store::get_instances(7)['count']);
		$this->assertSame(0, SBR_Trust_Badge_Store::get_instances(8)['count']);
	}

	/**
	 * The delete dialog lists at most MAX_INSTANCE_ROWS placements. The cap moved
	 * from the candidate loop to the map, so it needs re-pinning there.
	 */
	public function test_the_answer_is_still_capped_at_max_instance_rows(): void
	{
		$rows = array();
		for ($i = 0; $i < SBR_Trust_Badge_Store::MAX_INSTANCE_ROWS + 20; $i++) {
			$rows[] = $this->post(300 + $i, 'Page ' . $i, '[reviews-feed-badge id="7"]');
		}
		$this->seed($rows);

		$this->assertSame(
			SBR_Trust_Badge_Store::MAX_INSTANCE_ROWS,
			SBR_Trust_Badge_Store::get_instances(7)['count']
		);
	}

	/**
	 * The content half went through the map; the builder-meta half did not. The
	 * merge in get_instances() has to keep answering for both, and dedupe a post
	 * that places the badge twice over.
	 */
	public function test_content_and_builder_meta_placements_merge(): void
	{
		$this->seed(array( $this->post(101, 'Shortcode page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->meta_rows = array(
			array( 'ID' => 202, 'post_title' => 'Elementor page', 'post_type' => 'page', 'meta_value' => '[reviews-feed-badge id="7"]' ),
		);

		$names = array_column(SBR_Trust_Badge_Store::get_instances(7)['items'], 'name');

		$this->assertContains('Shortcode page', $names);
		$this->assertContains('Elementor page', $names);
	}

	/**
	 * Invalidation bumps a generation rather than deleting, so a request that
	 * missed the cache and is still scanning cannot finish after an editor's save
	 * and write its pre-save map back under the live key.
	 */
	public function test_invalidation_moves_the_key_rather_than_emptying_it(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$before = SBR_Trust_Badge_Store::instance_map_cache_key();

		SBR_Trust_Badge_Store::flush_instance_scans();

		$after = SBR_Trust_Badge_Store::instance_map_cache_key();

		$this->assertNotSame($before, $after, 'the live key must move, or an in-flight scan can overwrite the flush');

		// The in-flight scan lands on the old key, and nothing reads it again.
		set_transient($before, array( 7 => array( array( 'ID' => 999, 'post_title' => 'Stale', 'post_type' => 'page' ) ) ), 60);

		$this->assertFalse($this->stored_map(), 'a late write under the old key must not resurface');
	}

	/**
	 * The memo is keyed by blog: a bare static hands blog A's map to blog B across
	 * switch_to_blog(), and the transient it shadows is per-blog in real WordPress.
	 */
	public function test_the_memo_does_not_leak_between_blogs(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);
		$this->assertSame(1, $this->count_posts_scans());

		// Another blog, whose own transient is empty.
		$GLOBALS['wp_current_blog_id_mock'] = 2;
		$GLOBALS['wp_transients_mock']      = array();
		$this->wpdb->queries                = array();

		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(1, $this->count_posts_scans(), 'blog B must scan for itself, not read blog A memo');
	}

	/**
	 * The flush runs after the write, so a save that never happens does not throw
	 * a 12-hour cache away. It used to be the first statement in save().
	 */
	public function test_a_save_that_fails_validation_does_not_flush(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$key = SBR_Trust_Badge_Store::instance_map_cache_key();

		$this->assertNull(SBR_Trust_Badge_Store::save('not an array'));

		$this->assertSame($key, SBR_Trust_Badge_Store::instance_map_cache_key());
		$this->assertNotFalse($this->stored_map());
	}

	/**
	 * Within one request, a flush has to clear the memo too — otherwise the badge
	 * that was just saved keeps answering from the map built before the save.
	 */
	public function test_flushing_clears_the_memo_within_the_same_request(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);
		$this->assertSame(1, $this->count_posts_scans());

		SBR_Trust_Badge_Store::flush_instance_scans();
		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(2, $this->count_posts_scans(), 'the memo must not survive a flush');
	}

	/**
	 * A failed builder-meta query returns no rows, which looks exactly like "no
	 * builder placements". Memoising that would hand the same wrong answer to every
	 * later badge in the request.
	 */
	public function test_a_failed_builder_meta_query_is_not_memoised(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->fail_on_meta = true;

		SBR_Trust_Badge_Store::get_instances(7);
		$first = $this->count_meta_queries();

		SBR_Trust_Badge_Store::get_instances(8);

		$this->assertGreaterThan($first, $this->count_meta_queries(), 'a failed meta read must be retried');
	}

	// ---------- SMASH-2117: "could not check" is not "placed nowhere" ----------

	public function test_a_scan_that_ran_reports_ok(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		$this->assertSame('ok', SBR_Trust_Badge_Store::get_instances(7)['status']);
		// A badge that is genuinely placed nowhere is still 'ok' — that is an answer.
		$this->assertSame('ok', SBR_Trust_Badge_Store::get_instances(8)['status']);
	}

	public function test_a_failed_id_pass_reports_unknown(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->last_error = 'Lost connection to MySQL server during query';

		$this->assertSame('unknown', SBR_Trust_Badge_Store::get_instances(7)['status']);
	}

	public function test_a_failed_batch_reports_unknown(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->fail_on_results = true;

		$this->assertSame('unknown', SBR_Trust_Badge_Store::get_instances(7)['status']);
	}

	/**
	 * The builder half can fail on its own while the content half answered. The
	 * count would then be a real number that is nonetheless incomplete, so it is
	 * still not something to print a confident zero from.
	 */
	public function test_a_failed_builder_meta_read_reports_unknown(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		$this->wpdb->fail_on_meta = true;

		$this->assertSame('unknown', SBR_Trust_Badge_Store::get_instances(7)['status']);
	}

	/**
	 * BC (Rule 6): `status` is additive. Anything reading count/items — the list
	 * cell and the delete dialog both did before SMASH-2117 — sees what it saw.
	 */
	public function test_the_existing_count_and_items_contract_is_unchanged(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));

		$result = SBR_Trust_Badge_Store::get_instances(7);

		$this->assertSame(1, $result['count']);
		$this->assertSame('A page', $result['items'][0]['name']);
		$this->assertArrayHasKey('type', $result['items'][0]);
		$this->assertArrayHasKey('typeLabel', $result['items'][0]);

		$empty = SBR_Trust_Badge_Store::get_instances(0);
		$this->assertSame(array( 'count' => 0, 'items' => array(), 'status' => 'ok' ), $empty);
	}

	// ---------- invalidation scoping ----------

	public function test_writing_an_unscanned_post_type_does_not_drop_the_map(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$GLOBALS['wp_post_type_by_id_mock'][77] = 'edd_license_log';

		sbr_flush_badge_instances_cache(77);

		$this->assertNotFalse($this->stored_map());
	}

	public function test_writing_a_scanned_post_type_drops_the_map(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$GLOBALS['wp_post_type_by_id_mock'][88] = 'page';

		sbr_flush_badge_instances_cache(88);

		$this->assertFalse($this->stored_map());
	}

	public function test_deletion_drops_the_map_using_the_post_object(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$this->assertFalse(get_post_type(90), 'the row is gone by the time deleted_post fires');

		sbr_flush_badge_instances_cache(90, (object) array( 'ID' => 90, 'post_type' => 'page' ));

		$this->assertFalse($this->stored_map());
	}

	public function test_deleting_an_unscanned_post_type_does_not_drop_the_map(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		sbr_flush_badge_instances_cache(93, (object) array( 'ID' => 93, 'post_type' => 'edd_license_log' ));

		$this->assertNotFalse($this->stored_map());
	}

	/**
	 * The scan reads `sbr_badge_instance_post_types`, so the flush has to as well.
	 * Testing against DB::scanned_post_types() would scan a filtered-in type and
	 * then never invalidate it.
	 */
	public function test_a_type_added_through_the_filter_can_also_drop_the_map(): void
	{
		$GLOBALS['wp_filter_mock']['sbr_badge_instance_post_types'] = array( 'page', 'my_builder_layout' );
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$GLOBALS['wp_post_type_by_id_mock'][95] = 'my_builder_layout';

		sbr_flush_badge_instances_cache(95);

		$this->assertFalse($this->stored_map());
	}

	public function test_revisions_and_autosaves_do_not_drop_the_map(): void
	{
		$this->seed(array( $this->post(101, 'A page', '[reviews-feed-badge id="7"]') ));
		SBR_Trust_Badge_Store::get_instances(7);

		$GLOBALS['wp_post_type_by_id_mock'][91] = 'page';
		$GLOBALS['wp_post_revisions_mock']      = array( 91 );

		sbr_flush_badge_instances_cache(91);

		$this->assertNotFalse($this->stored_map());
	}

	// ---------- helpers ----------

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function seed(array $rows): void
	{
		$this->wpdb->rows      = $rows;
		$this->wpdb->meta_rows = array();
		$this->wpdb->queries   = array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function post($id, $title, $content): array
	{
		return array(
			'ID'           => $id,
			'post_title'   => $title,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_date'    => '2026-01-01 00:00:00',
			'post_content' => $content,
		);
	}

	private function first_posts_scan(): string
	{
		foreach ($this->wpdb->queries as $sql) {
			if (false !== strpos($sql, 'post_content LIKE')) {
				return $sql;
			}
		}

		$this->fail('no candidate scan was issued');
	}

	private function count_meta_queries(): int
	{
		$n = 0;
		foreach ($this->wpdb->queries as $sql) {
			if (false !== strpos($sql, 'wp_postmeta')) {
				$n++;
			}
		}

		return $n;
	}

	private function count_posts_scans(): int
	{
		$n = 0;
		foreach ($this->wpdb->queries as $sql) {
			if (false !== strpos($sql, 'post_content LIKE')) {
				$n++;
			}
		}

		return $n;
	}

	/**
	 * What a new PHP request gets: no statics, whatever the transient holds.
	 */
	private function forget_statics(): void
	{
		$class = new ReflectionClass(SBR_Trust_Badge_Store::class);

		// Both statics, because a new PHP request resets both — and meta_rows is
		// memoised on the meta-key set, so leaving it populated hands the next test
		// the previous one's builder rows.
		foreach (array( 'instance_map' => array(), 'meta_rows' => array() ) as $name => $empty) {
			$property = $class->getProperty($name);
			$property->setAccessible(true);
			$property->setValue(null, $empty);
		}
	}

	private function reset_map(): void
	{
		$this->forget_statics();

		$GLOBALS['wp_transients_mock'] = array();
		$GLOBALS['wp_options_mock']    = array();
	}

	/**
	 * The map as a later request would find it, or false when there is none.
	 *
	 * Asked by key-for-the-current-generation rather than by key presence: the
	 * flush bumps the generation, so the previous entry is still in storage and
	 * simply unreachable. Presence would report it as a live cache.
	 *
	 * @return mixed
	 */
	private function stored_map()
	{
		return get_transient(SBR_Trust_Badge_Store::instance_map_cache_key());
	}
}
