<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * The badge list pages deterministically (SBR-163).
 *
 * `orderby => modified` alone is not a total order: badges saved in the same second
 * share post_modified, and MySQL may order those rows differently per query. Measured
 * on 23 local fixtures, id 950 came back on page 2 AND page 3 while another row was
 * never returned at all. ID breaks the tie.
 *
 * Driven through the bootstrap's recording WP_Query, so these assert the query SHAPE
 * rather than a database's answer.
 */
class BadgeListPaginationTest extends TestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue;

		$wp_query_calls       = array();
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array();
	}

	/**
	 * The doubles are process-wide globals, so what this class sets has to stop
	 * here — `test_the_total_is_the_full_count_not_the_page_length()` left
	 * `$wp_query_found = 23` for whatever ran next. Its sibling
	 * BadgeListTotalOnOutOfRangePageTest already does this, with a comment saying
	 * why; the omission here was the inconsistency.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue;

		$wp_query_calls       = array();
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array();

		parent::tearDown();
	}

	/**
	 * @return array<string, mixed> The args of the query that FETCHED THE PAGE.
	 *
	 * The first recorded call, not the last. An out-of-range page makes the store
	 * run a second, count-only query to answer `total` correctly
	 * (BadgeListTotalOnOutOfRangePageTest), and these tests are about the page
	 * fetch — `end()` silently started reading the recount instead, which showed
	 * up as "1 is identical to 4" on the paged assertion.
	 */
	private function pageQuery()
	{
		global $wp_query_calls;

		$this->assertNotEmpty($wp_query_calls, 'The store ran no query.');

		return $wp_query_calls[0];
	}

	/**
	 * The tiebreaker, which is the whole point.
	 *
	 * @return void
	 */
	public function test_the_order_carries_a_deterministic_tiebreaker()
	{
		SBR_Trust_Badge_Store::list_badges(array( 'page' => 2, 'per_page' => 3 ));

		$args = $this->pageQuery();

		$this->assertIsArray($args['orderby'], 'A bare string orderby cannot carry a tiebreaker.');
		$this->assertArrayHasKey('ID', $args['orderby'], 'ID must break the tie on equal post_modified.');
		$this->assertSame('DESC', $args['orderby']['modified'], 'Newest first, as before.');
		$this->assertSame('DESC', $args['orderby']['ID']);
	}

	/**
	 * The page the caller asked for reaches the query, which is what the list screen's
	 * pagination depends on.
	 *
	 * @return void
	 */
	public function test_the_requested_page_and_size_reach_the_query()
	{
		SBR_Trust_Badge_Store::list_badges(array( 'page' => 4, 'per_page' => 50 ));

		$args = $this->pageQuery();

		$this->assertSame(4, $args['paged']);
		$this->assertSame(50, $args['posts_per_page']);
	}

	/**
	 * A page below one is not a page. Left as a clamp rather than an error because the
	 * value arrives from a query string.
	 *
	 * @dataProvider badPageProvider
	 *
	 * @param mixed $page What arrives.
	 *
	 * @return void
	 */
	public function test_a_page_below_one_clamps_to_the_first($page)
	{
		SBR_Trust_Badge_Store::list_badges(array( 'page' => $page ));

		$this->assertSame(1, $this->pageQuery()['paged']);
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function badPageProvider()
	{
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
			'string'   => array( 'abc' ),
		);
	}

	/**
	 * Both drafts and published badges are listed — a badge is never "published" in
	 * the WP sense, so filtering to `publish` would hide half the screen.
	 *
	 * @return void
	 */
	public function test_the_query_covers_drafts_as_well_as_published()
	{
		SBR_Trust_Badge_Store::list_badges(array());

		$statuses = $this->pageQuery()['post_status'];

		$this->assertContains('publish', $statuses);
		$this->assertContains('draft', $statuses);
	}

	/**
	 * The total comes from found_posts, not from the number of rows on this page —
	 * the list screen divides it by the page size to count pages.
	 *
	 * @return void
	 */
	public function test_the_total_is_the_full_count_not_the_page_length()
	{
		global $wp_query_posts, $wp_query_found;

		$wp_query_posts = array();
		$wp_query_found = 23;

		$out = SBR_Trust_Badge_Store::list_badges(array( 'page' => 1, 'per_page' => 3 ));

		$this->assertSame(23, $out['total']);
	}
}
