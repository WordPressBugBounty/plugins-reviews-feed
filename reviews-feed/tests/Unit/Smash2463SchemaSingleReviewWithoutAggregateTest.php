<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

/**
 * SMASH-2463 — Google rejects a node with multiple reviews and no aggregateRating
 * ("Multiple reviews without aggregateRating object"). Since SMASH-1825 the aggregate
 * is Pro-only, so every Free feed with 2+ reviews hit it.
 *
 * Rule (review-snippet docs): "If you include multiple individual reviews, also
 * include an aggregate rating of the individual reviews."
 */
class Smash2463SchemaSingleReviewWithoutAggregateTest extends TestCase
{
	/** @var SBR_Schema_Service */
	private $service;

	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['wp_options_mock']  = [];
		$GLOBALS['wp_filter_mock']   = [];
		$GLOBALS['wp_home_url_mock'] = 'https://example.test';
		$this->service = new SBR_Schema_Service();
	}

	private function header(float $rating, int $count): array
	{
		return [['name' => 'Acme Co', 'info' => ['rating' => $rating, 'total_rating' => $count]]];
	}

	private function review(string $author, int $rating): array
	{
		return ['reviewer' => ['name' => $author], 'rating' => $rating, 'text' => 'Text by ' . $author, 'time' => 1600000000];
	}

	private function booking_review(string $author): array
	{
		return [
			'reviewer' => ['name' => $author],
			'provider' => ['name' => 'booking'],
			'metadata' => ['review_score' => 9.0],
			'rating'   => 4.5,
			'text'     => 'Stayed here',
			'time'     => 1600000000,
		];
	}

	/** @return array<int,array> */
	private function three_reviews(): array
	{
		return [$this->review('Jane', 5), $this->review('Omar', 4), $this->review('Lin', 5)];
	}

	public function test_lite_feed_with_many_reviews_emits_exactly_one(): void
	{
		// The reported case: a Free Google feed. Before the fix this node carried all
		// three reviews and no aggregate.
		$nodes = $this->service->map_feed_to_schema($this->header(4.7, 120), $this->three_reviews(), [['provider' => 'google']], false);

		$this->assertCount(1, $nodes);
		$this->assertArrayNotHasKey('aggregateRating', $nodes[0]);
		$this->assertCount(1, $nodes[0]['review']);
		$this->assertSame('Jane', $nodes[0]['review'][0]['author']['name'], 'the kept review is the first one the feed shows');
	}

	public function test_pro_feed_with_aggregate_keeps_every_review(): void
	{
		$node = $this->service->map_feed_to_schema($this->header(4.7, 120), $this->three_reviews(), [['provider' => 'google']], true)[0];

		$this->assertSame('4.7', $node['aggregateRating']['ratingValue']);
		$this->assertCount(3, $node['review']);
	}

	public function test_pro_feed_without_aggregate_emits_exactly_one(): void
	{
		// Pro, but no count to build an aggregate from: the same Google rule applies.
		$node = $this->service->map_feed_to_schema($this->header(0, 0), $this->three_reviews(), [['provider' => 'google']], true)[0];

		$this->assertArrayNotHasKey('aggregateRating', $node);
		$this->assertCount(1, $node['review']);
	}

	public function test_pro_booking_feed_without_a_count_emits_exactly_one(): void
	{
		$header = [['name' => 'Hotel', 'info' => ['review_score' => 8.5, 'review_score_word' => 'Great']]];
		$node   = $this->service->map_feed_to_schema($header, [$this->booking_review('Al'), $this->booking_review('Bea')], [['provider' => 'booking']], true)[0];

		$this->assertArrayNotHasKey('aggregateRating', $node);
		$this->assertCount(1, $node['review']);
		$this->assertSame('Al', $node['review'][0]['author']['name']);
	}

	public function test_lite_keeps_the_first_ratable_review_when_the_first_card_is_unratable(): void
	{
		// Booking cards resolve to no rating on Lite and are skipped, so the kept review
		// is the first one that carries a rating, not the first post.
		$posts = [$this->booking_review('Max'), $this->review('Gina', 4), $this->review('Hal', 5)];
		$node  = $this->service->map_feed_to_schema($this->header(4.5, 10), $posts, [['provider' => 'booking'], ['provider' => 'google']], false)[0];

		$this->assertCount(1, $node['review']);
		$this->assertSame('Gina', $node['review'][0]['author']['name']);
	}

	public function test_lite_keeps_the_first_review_with_an_author(): void
	{
		// Google requires an author on a Review, so a nameless first card would trade
		// "Multiple reviews without aggregateRating" for "Missing field author".
		$posts = [$this->review('', 5), $this->review('Omar', 4), $this->review('Lin', 5)];
		$node  = $this->service->map_feed_to_schema($this->header(4.7, 120), $posts, [['provider' => 'google']], false)[0];

		$this->assertCount(1, $node['review']);
		$this->assertSame('Omar', $node['review'][0]['author']['name']);
	}

	public function test_lite_publishes_no_review_when_none_has_an_author(): void
	{
		$posts = [$this->review('', 5), $this->review('', 4)];
		$nodes = $this->service->map_feed_to_schema($this->header(4.7, 120), $posts, [['provider' => 'google']], false);

		$this->assertSame([], $nodes, 'no aggregate and no valid review: nothing to publish');
	}

	public function test_lite_drops_a_lone_review_without_an_author(): void
	{
		$nodes = $this->service->map_feed_to_schema($this->header(4.7, 120), [$this->review('', 5)], [['provider' => 'google']], false);

		$this->assertSame([], $nodes);
	}

	public function test_pro_with_aggregate_keeps_reviews_without_an_author(): void
	{
		// Out of scope here: with an aggregate the node is valid without any Review.
		// TODO(SMASH-2486): pins Pro publishing a Review with no author — the real fix drops it on every path (Google: "Missing field author") — left as-is because it changes Pro schema output outside SMASH-2463.
		$posts = [$this->review('', 5), $this->review('Omar', 4)];
		$node  = $this->service->map_feed_to_schema($this->header(4.7, 120), $posts, [['provider' => 'google']], true)[0];

		$this->assertCount(2, $node['review']);
	}

	/**
	 * @dataProvider invariant_cases
	 */
	public function test_multiple_reviews_always_come_with_an_aggregate(array $header, bool $is_pro): void
	{
		$posts = [];
		for ($i = 0; $i < SBR_Schema_Service::MAX_REVIEWS + 3; $i++) {
			$posts[] = $this->review('User' . $i, 5);
		}

		$nodes = $this->service->map_feed_to_schema($header, $posts, [['provider' => 'google']], $is_pro);
		$this->assertNotEmpty($nodes, 'every case has ratable reviews, so a node must be emitted');

		foreach ($nodes as $node) {
			if (count($node['review'] ?? []) > 1) {
				$this->assertArrayHasKey('aggregateRating', $node, 'Google: multiple reviews need an aggregateRating');
			} else {
				$this->assertCount(1, $node['review']);
			}
		}
	}

	/** @return array<string,array{0:array,1:bool}> */
	public static function invariant_cases(): array
	{
		return [
			'lite, real aggregate data' => [[['name' => 'A', 'info' => ['rating' => 4.8, 'total_rating' => 100]]], false],
			'lite, no aggregate data'   => [[['name' => 'A', 'info' => ['rating' => 0, 'total_rating' => 0]]], false],
			'pro, real aggregate data'  => [[['name' => 'A', 'info' => ['rating' => 4.8, 'total_rating' => 100]]], true],
			'pro, rating but no count'  => [[['name' => 'A', 'info' => ['rating' => 4.8, 'total_rating' => 0]]], true],
			'pro, no aggregate data'    => [[['name' => 'A', 'info' => ['rating' => 0, 'total_rating' => 0]]], true],
		];
	}
}
