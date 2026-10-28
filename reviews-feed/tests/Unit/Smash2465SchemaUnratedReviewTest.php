<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

/**
 * SMASH-2465 — a review with no rating must not be published as 1 star.
 *
 * Parser::get_rating() floors an empty rating to 1 (class/Common/Parser.php:86), and
 * WooCommerce/EDD store 0 for a review left without stars
 * (WooCommerce_Review_Cache_Handler.php:525), so the schema used to assert a 1-star
 * Review the author never gave.
 */
class Smash2465SchemaUnratedReviewTest extends TestCase
{
	/** @var SBR_Schema_Service */
	private $service;

	/** @var array<string,mixed> setUp() replaces these; tearDown() puts them back */
	private $previous = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous = [
			'options'  => $GLOBALS['wp_options_mock'] ?? null,
			'filter'   => $GLOBALS['wp_filter_mock'] ?? null,
			'home_url' => $GLOBALS['wp_home_url_mock'] ?? null,
		];
		$GLOBALS['wp_options_mock']  = [];
		$GLOBALS['wp_filter_mock']   = [];
		$GLOBALS['wp_home_url_mock'] = 'https://example.test';
		$this->service = new SBR_Schema_Service();
	}

	protected function tearDown(): void
	{
		$GLOBALS['wp_options_mock']  = $this->previous['options'];
		$GLOBALS['wp_filter_mock']   = $this->previous['filter'];
		$GLOBALS['wp_home_url_mock'] = $this->previous['home_url'];
		parent::tearDown();
	}

	private function header(float $rating, int $count): array
	{
		return [['name' => 'Acme Co', 'info' => ['rating' => $rating, 'total_rating' => $count]]];
	}

	/** @param mixed $rating */
	private function review(string $author, $rating, string $provider = 'woocommerce'): array
	{
		$post = ['reviewer' => ['name' => $author], 'text' => 'Text by ' . $author, 'time' => 1600000000, 'provider' => ['name' => $provider]];
		if ($rating !== 'absent') {
			$post['rating'] = $rating;
		}
		return $post;
	}

	/** @return array<int,string> */
	private function authors(array $nodes): array
	{
		return array_map(static fn($r) => $r['author']['name'], $nodes[0]['review'] ?? []);
	}

	/**
	 * @dataProvider unrated_values
	 * @param mixed $rating
	 */
	public function test_an_unrated_review_is_not_published($rating): void
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.5, 10),
			[$this->review('Nora', $rating), $this->review('Ivo', 4)],
			[['provider' => 'woocommerce']],
			true
		);

		$this->assertSame(['Ivo'], $this->authors($nodes), 'the unrated review must be dropped, not published as 1 star');
	}

	/** @return array<string,array{0:mixed}> */
	public static function unrated_values(): array
	{
		return [
			'Woo/EDD store 0'   => [0],
			'float zero'        => [0.0],
			'empty string'      => [''],
			'numeric zero text' => ['0'],
			'null'              => [null],
			'key absent'        => ['absent'],
		];
	}

	public function test_a_real_one_star_review_is_still_published(): void
	{
		// The guard is "no rating", not "low rating" — a genuine 1 star stays.
		$nodes = $this->service->map_feed_to_schema($this->header(3.0, 4), [$this->review('Ada', 1)], [['provider' => 'woocommerce']], true);

		$this->assertSame(['Ada'], $this->authors($nodes));
		$this->assertSame('1', $nodes[0]['review'][0]['reviewRating']['ratingValue']);
	}

	public function test_numeric_string_ratings_are_kept(): void
	{
		$nodes = $this->service->map_feed_to_schema($this->header(4.0, 4), [$this->review('Ben', '4')], [['provider' => 'google']], true);

		$this->assertSame('4', $nodes[0]['review'][0]['reviewRating']['ratingValue']);
	}

	public function test_facebook_recommendations_keep_their_rating(): void
	{
		// 'positive'/'negative' are real recommendations, mapped to 5 and 1 by Parser::get_rating().
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.0, 4),
			[$this->review('Pia', 'positive', 'facebook'), $this->review('Neo', 'negative', 'facebook')],
			[['provider' => 'facebook']],
			true
		);

		$this->assertSame(['Pia', 'Neo'], $this->authors($nodes));
		$this->assertSame('5', $nodes[0]['review'][0]['reviewRating']['ratingValue']);
		$this->assertSame('1', $nodes[0]['review'][1]['reviewRating']['ratingValue']);
	}

	public function test_on_lite_the_first_published_review_is_the_first_rated_card(): void
	{
		// With SMASH-2463 Lite publishes only review[0], so it must be the first card WITH a rating.
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.5, 10),
			[$this->review('Nora', 0), $this->review('Ivo', 4), $this->review('Lea', 5)],
			[['provider' => 'woocommerce']],
			false
		);

		$this->assertSame('Ivo', $this->authors($nodes)[0]);
	}

	public function test_a_lite_feed_of_only_unrated_reviews_emits_nothing(): void
	{
		// No aggregate on Lite and no ratable review → nothing honest to publish.
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.5, 10),
			[$this->review('Nora', 0), $this->review('Omar', '')],
			[['provider' => 'woocommerce']],
			false
		);

		$this->assertSame([], $nodes);
	}
}
