<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

/**
 * SMASH-2478: the JSON-LD listed every cached review (up to 20) while the page renders only its first
 * set (FeedDisplay::get_post_set_page()), so the markup described reviews a visitor can't see. Google's
 * review-snippet guidelines want marked-up reviews readily available on the page. The aggregate still
 * counts every review: it describes the business, not the page.
 */
final class Smash2478SchemaReviewsOnPageTest extends TestCase
{
	/** @var mixed */
	private $previous_filters;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_filters = $GLOBALS['wp_filter_mock'] ?? null;
		$GLOBALS['wp_filter_mock'] = [];
	}

	protected function tearDown(): void
	{
		if ($this->previous_filters === null) {
			unset($GLOBALS['wp_filter_mock']);
		} else {
			$GLOBALS['wp_filter_mock'] = $this->previous_filters;
		}
		parent::tearDown();
	}

	private function review(string $author, int $rating = 5): array
	{
		return ['reviewer' => ['name' => $author], 'rating' => $rating, 'text' => 'By ' . $author, 'time' => 1600000000, 'source' => ['id' => 'g1']];
	}

	/** @return list<array> the nodes build_all_nodes() makes for one feed backed by $feed */
	private function nodes_for(Feed $feed): array
	{
		$service = new SBR_Schema_Service();
		$memo    = new \ReflectionProperty(SBR_Schema_Service::class, 'feed_memo');
		$memo->setAccessible(true);
		$memo->setValue($service, [7 => $feed]);
		$build = new \ReflectionMethod(SBR_Schema_Service::class, 'build_all_nodes');
		$build->setAccessible(true);

		return $build->invoke($service, [7]);
	}

	/** @param list<array> $all @param list<array> $page */
	private function feed(array $all, array $page, bool $single_manual = false, array $info = ['rating' => 4.8, 'total_rating' => 120]): Feed
	{
		$feed = $this->createMock(Feed::class);
		$feed->method('get_settings')->willReturn(['sources' => [['provider' => 'google']]]);
		$feed->method('get_posts')->willReturn($all);
		$feed->method('get_post_set_page')->willReturn($page);
		$feed->method('is_single_manual_review')->willReturn($single_manual);
		$feed->method('get_header_data')->willReturn([['name' => 'Acme', 'info' => $info]]);

		return $feed;
	}

	public function test_only_the_reviews_on_the_page_are_published(): void
	{
		$all  = [$this->review('Ana'), $this->review('Ben'), $this->review('Cy'), $this->review('Di'), $this->review('Ed'), $this->review('Flo')];
		$page = array_slice($all, 0, 2);

		$nodes = $this->nodes_for($this->feed($all, $page));
		$names = array_map(function ($r) {
			return $r['author']['name'] ?? null;
		}, $nodes[0]['review'] ?? []);

		// On Lite there is no aggregate and SMASH-2463 keeps one review; on Pro both page reviews show.
		// Exactly the page's reviews in page order: [Ana] once #582 is in, [Ana, Ben] without it.
		$this->assertContains(count($names), [1, 2]);
		$this->assertSame(array_slice(['Ana', 'Ben'], 0, count($names)), $names, 'no review from behind "Load more"');
	}

	public function test_the_page_set_is_what_the_feed_renders_first(): void
	{
		$feed = $this->feed([$this->review('Ana')], [$this->review('Ana')]);
		$feed->expects($this->atLeastOnce())->method('get_post_set_page')->with(1);
		$this->nodes_for($feed);
	}

	public function test_a_single_manual_review_feed_is_unchanged(): void
	{
		// get_post_set_page() hydrates the manual review, get_posts() has none: it published nothing before.
		$feed  = $this->feed([], [$this->review('Manual')], true);
		$nodes = $this->nodes_for($feed);
		$this->assertSame([], array_merge(...array_map(function ($n) {
			return $n['review'] ?? [];
		}, $nodes)));
	}

	/**
	 * The aggregate is Pro-only (SMASH-1825) and SBR_PRO is a constant, so this runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_aggregate_still_counts_every_review(): void
	{
		if (! defined('SBR_PRO')) {
			define('SBR_PRO', true);
		}
		$this->assertTrue(\SmashBalloon\Reviews\Common\Util::sbr_is_pro(), 'precondition: Pro');
		$all   = [$this->review('Ana'), $this->review('Ben'), $this->review('Cy'), $this->review('Di')];
		$nodes = $this->nodes_for($this->feed($all, array_slice($all, 0, 2), false, ['id' => 'g1', 'rating' => 5, 'total_rating' => 1]));
		$this->assertSame('4', $nodes[0]['aggregateRating']['reviewCount'] ?? null, 'backfilled to the four cached reviews, not the two on the page');
		$this->assertCount(2, $nodes[0]['review']);
	}
}
