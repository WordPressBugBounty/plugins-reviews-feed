<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * `total` means how many badges exist, on every page (SBR-163).
 *
 * Found by driving the list screen rather than by reading it: delete the last rows
 * of page 2 and the screen drew "you have no badges" over a site with 20 of them.
 * The delete route answers with the list re-read for the page it was given, and
 * WP_Query reports `found_posts` 0 for a page past the end — so the response said
 * `badgesCount => 0` and the screen believed it.
 *
 * Measured against the real database before the fix:
 *
 *   page 1 -> items 20, total 20
 *   page 2 -> items  0, total  0     <- the defect
 *
 * A total that depends on which page you asked for is the bug; the empty state and
 * the stuck pagination were both symptoms. The client-side step-back added for the
 * same report could not work until this was true, because it gates on a non-zero
 * count.
 *
 * Driven through the bootstrap's recording WP_Query, so these assert the store's
 * contract rather than a database's answer.
 */
class BadgeListTotalOnOutOfRangePageTest extends TestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		// The same four the tearDown below clears. Blanking only three left the
		// found-rows QUEUE to whatever ran before this file: it answers ahead of
		// $wp_query_found, so a leaked entry decides the first assertion here.
		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue;

		$wp_query_calls       = array();
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		// The doubles are process-wide globals; a leaked count would answer for
		// whatever ran next.
		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue;

		$wp_query_calls       = array();
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array();

		parent::tearDown();
	}

	/**
	 * The in-range case is unchanged and costs one query, which is the reason the
	 * recount is conditional rather than always-on.
	 *
	 * @return void
	 */
	public function test_a_page_with_rows_reports_its_own_total_in_one_query()
	{
		global $wp_query_posts, $wp_query_found, $wp_query_calls;

		$wp_query_posts = array();
		$wp_query_found = 20;

		$list = SBR_Trust_Badge_Store::list_badges(array( 'page' => 1, 'per_page' => 20 ));

		$this->assertSame(20, $list['total']);
		$this->assertCount(1, $wp_query_calls, 'the in-range path must not run a second query');
	}

	/**
	 * The defect: page past the end, so WP answers found_posts 0.
	 *
	 * @return void
	 */
	public function test_a_page_past_the_end_still_reports_how_many_badges_exist()
	{
		global $wp_query_posts, $wp_query_found, $wp_query_found_queue;

		// What WP_Query really does for page 2 of a 20-badge list: the page fetch
		// finds nothing and reports found_posts 0; the recount, scoped to page one,
		// finds all 20. Two DIFFERENT answers, which is why the double takes a
		// queue — with one value the assertion below could only pin 0, the number
		// the defect returned, and reverting the fix left it green.
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array( 0, 20 );

		$list = SBR_Trust_Badge_Store::list_badges(array( 'page' => 2, 'per_page' => 20 ));

		$this->assertSame(array(), $list['items'], 'the page genuinely holds nothing');
		$this->assertSame(
			20,
			$list['total'],
			'a page past the end must still report how many badges exist, or the list screen '
			. 'shows a pager over a total of zero'
		);
	}

	/**
	 * The recount is a second query, scoped to count rather than to fetch a page.
	 *
	 * @return void
	 */
	public function test_the_recount_asks_for_page_one_and_only_ids()
	{
		global $wp_query_posts, $wp_query_found, $wp_query_calls;

		$wp_query_posts = array();
		$wp_query_found = 0;

		SBR_Trust_Badge_Store::list_badges(array( 'page' => 3, 'per_page' => 20 ));

		$this->assertCount(2, $wp_query_calls, 'an out-of-range page must trigger the recount');

		$recount = end($wp_query_calls);

		$this->assertSame(1, $recount['paged'], 'the recount must not repeat the out-of-range page');
		$this->assertSame('ids', $recount['fields'], 'counting does not need post objects');
		$this->assertSame('sbr_trust_badge', $recount['post_type']);
	}

	/**
	 * Page 1 can legitimately be empty — a site with no badges at all — and that
	 * must NOT spend a second query proving it.
	 *
	 * @return void
	 */
	public function test_an_empty_first_page_does_not_recount()
	{
		global $wp_query_posts, $wp_query_found, $wp_query_calls;

		$wp_query_posts = array();
		$wp_query_found = 0;

		$list = SBR_Trust_Badge_Store::list_badges(array( 'page' => 1, 'per_page' => 20 ));

		$this->assertSame(0, $list['total']);
		$this->assertCount(1, $wp_query_calls, 'zero badges is a real answer, not an out-of-range page');
	}
}
