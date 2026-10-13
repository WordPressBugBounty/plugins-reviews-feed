<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

/**
 * SMASH-2033: four smashballoon.com doc pages emitted LocalBusiness + aggregateRating with no feed on the page,
 * because detect_feed_ids() matched shortcode text WordPress never renders: the escaped [[reviews-feed feed=1]]
 * and the [reviews-feeds feed=1] typo.
 */
final class Smash2033SchemaEscapedShortcodeTest extends TestCase
{
	/** @var SBR_Schema_Service */
	private $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->service = new SBR_Schema_Service();
	}

	public static function not_a_feed(): array
	{
		return [
			'escaped shortcode (the doc pages)' => [ '<p>Use <code>[[reviews-feed feed=1]]</code> to embed.</p>' ],
			'escaped, quoted'                   => [ '[[reviews-feed feed="1"]]' ],
			'typo: plural tag'                  => [ '<p>[reviews-feeds feed=1]</p>' ],
			'trust badge shortcode'             => [ '[reviews-feed-badge id=4 feed=1]' ],
			'escaped, next to another plugin'   => [ '[[reviews-feed feed=1]] [instagram-feed feed=2]' ],
			'another plugin only'               => [ '[instagram-feed feed=2]' ],
		];
	}

	/** @dataProvider not_a_feed */
	public function test_text_wordpress_never_renders_as_a_feed_is_not_detected(string $content): void
	{
		$this->assertSame([], $this->service->detect_feed_ids($content));
	}

	public function test_a_real_feed_next_to_an_escaped_example_is_still_detected(): void
	{
		$content = '<p>Example: [[reviews-feed feed=1]]</p>[reviews-feed feed=2]';
		$this->assertSame([ 2 ], $this->service->detect_feed_ids($content));
	}

	/** BC: the placements that already worked. */
	public static function real_feeds(): array
	{
		return [
			'bare shortcode'        => [ '[reviews-feed feed=12]', [ 12 ] ],
			'double-quoted'         => [ '[reviews-feed feed="12"]', [ 12 ] ],
			'single-quoted'         => [ "[reviews-feed feed='12']", [ 12 ] ],
			'modern block'          => [ '<!-- wp:smashballoon/reviews-feed {"feedId":"3"} /-->', [ 3 ] ],
			'two feeds on one page' => [ '[reviews-feed feed=5][reviews-feed feed=6]', [ 5, 6 ] ],
			'next to another plugin' => [ '[reviews-feed feed=1] [instagram-feed feed=2]', [ 1 ] ],
			'legacy block'          => [ '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\\"34\\" num=6"} /-->', [ 34 ] ],
		];
	}

	/** @dataProvider real_feeds */
	public function test_real_placements_are_still_detected(string $content, array $want): void
	{
		$this->assertSame($want, $this->service->detect_feed_ids($content));
	}
}
