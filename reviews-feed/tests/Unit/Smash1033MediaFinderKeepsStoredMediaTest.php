<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\MediaFinder;
use SmashBalloon\Reviews\Pro\SinglePostCache;

/**
 * SMASH-1033 / #558 — the batched image pass calls media_finder_init() for google/yelp/tripadvisor
 * rows. A collection review already carries its media and has no provider page to scrape, so
 * search() returned [] and the pass wrote that over the images (seen live on InstaQA 19510).
 */
final class Smash1033MediaFinderKeepsStoredMediaTest extends TestCase
{
	/** @return MediaFinder&object{searches: int} */
	private function finderReturningNothing(): MediaFinder
	{
		return new class (['info' => '{}']) extends MediaFinder {
			/** @var int */
			public $searches = 0;
			public function construct_url_from_post_and_source()
			{
			}
			public function search()
			{
				$this->searches++;
				return [];
			}
		};
	}

	/** @param array<string,mixed> $review */
	private function initAndRead(array $review, MediaFinder $finder): array
	{
		$cache = new SinglePostCache($review, $finder, 'collection-1');
		$cache->media_finder_init();
		return $cache->get_post_data();
	}

	/** @return array<string,mixed> */
	private function review(array $media): array
	{
		return [
			'review_id' => 'collection-1-r1',
			'provider'  => ['name' => 'google'],
			'source'    => ['id' => 'collection-1', 'url' => ''],
			'reviewer'  => ['name' => 'A'],
			'text'      => 'Great',
			'media'     => $media,
		];
	}

	public function test_a_row_that_already_has_media_keeps_it(): void
	{
		$media  = [['type' => 'image', 'url' => 'https://ok.test/a.jpg'], ['type' => 'image', 'url' => 'https://ok.test/b.jpg']];
		$finder = $this->finderReturningNothing();

		$data = $this->initAndRead($this->review($media), $finder);

		$this->assertSame($media, $data['media']);
		$this->assertSame(0, $finder->searches, 'no scrape for a row that already has media');
	}

	public function test_a_row_without_media_is_still_searched(): void
	{
		$finder = $this->finderReturningNothing();

		$data = $this->initAndRead($this->review([]), $finder);

		$this->assertSame(1, $finder->searches);
		$this->assertSame([], $data['media']);
	}
}
