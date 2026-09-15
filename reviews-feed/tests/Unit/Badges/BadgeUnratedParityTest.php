<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * The page and the email PNG agree about an unrated badge (SBR-163).
 *
 * `resolved` and `rated` are different questions. A feed with nothing rated in it
 * resolves, and get_aggregate() answers rating 0.0 / count 0 / resolved true. The page
 * has always gated on rated and prints "—" / "No reviews yet"; the image gated on
 * resolved alone and printed "0.0" / "Based on 0 reviews", which is a zero-star claim
 * in someone's email signature.
 */
class BadgeUnratedParityTest extends TestCase
{
	/**
	 * The aggregate a feed with no rated reviews produces.
	 *
	 * @return array<string, mixed>
	 */
	private function unrated()
	{
		return array(
			'rating'    => 0.0,
			'count'     => 0,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);
	}

	/**
	 * @return void
	 */
	public function test_resolved_is_not_the_same_question_as_rated()
	{
		$this->assertTrue((bool) $this->unrated()['resolved'], 'The fixture is a RESOLVED aggregate.');
		$this->assertFalse(SBR_Badge_Renderer::is_rated(0.0, 0));
		$this->assertFalse(SBR_Badge_Renderer::is_rated(4.5, 0), 'A rating with no reviews behind it is not rated.');
		$this->assertFalse(SBR_Badge_Renderer::is_rated(0.0, 12), 'Reviews with no rating are not rated either.');
		$this->assertTrue(SBR_Badge_Renderer::is_rated(4.5, 12));
	}

	/**
	 * The page's answer, which is the one the image has to match.
	 *
	 * @return void
	 */
	public function test_the_page_prints_a_dash_and_no_reviews_yet()
	{
		$sanitize = new ReflectionMethod(SBR_Badge_Renderer::class, 'sanitize_args');
		$sanitize->setAccessible(true);

		$prepare = new ReflectionMethod(SBR_Badge_Renderer::class, 'prepare');
		$prepare->setAccessible(true);

		$out = $prepare->invoke(
			null,
			$sanitize->invoke(null, array( 'shape' => 'card', 'feed' => 1, 'business' => 'Acme' )),
			$this->unrated()
		);

		$this->assertFalse($out['is_rated']);
		$this->assertSame('—', $out['rating_label']);
		$this->assertStringContainsString('No reviews yet', $out['subtext']);
	}

	/**
	 * The image's answer, which used to be "0.0" and "Based on 0 reviews".
	 *
	 * @return void
	 */
	public function test_the_email_png_prints_the_same_dash_and_sentence()
	{
		$measure = new ReflectionMethod(SBR_Badge_Image::class, 'measure');
		$measure->setAccessible(true);

		$content = $measure->invoke(
			null,
			array( 'show_rating' => true, 'show_count' => true, 'show_stars' => false, 'show_logos' => false ),
			$this->unrated(),
			array( 'ink' => '#101828', 'muted' => '#434960' ),
			0.0,
			0,
			array(
				'pad_x'     => 16,
				'pad_y'     => 14,
				'gap'       => 14,
				'col_gap'   => 4,
				'val_size'  => 24,
				'cnt_size'  => 12,
				'star_size' => 16,
				'star_gap'  => 2,
				'foot_h'    => 25,
				'scale'     => 2,
			)
		);

		$this->assertSame('—', $content['value'], 'The PNG must not print 0.0 where the page prints a dash.');
		$this->assertSame('No reviews yet', $content['count_label'], 'Nor "Based on 0 reviews".');
	}

	/**
	 * A rated badge is untouched by any of this — the guard must not swallow the real
	 * case it exists to protect.
	 *
	 * @return void
	 */
	public function test_a_rated_badge_still_prints_its_rating_and_count()
	{
		$measure = new ReflectionMethod(SBR_Badge_Image::class, 'measure');
		$measure->setAccessible(true);

		$rated = array(
			'rating'    => 4.8,
			'count'     => 128,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		$content = $measure->invoke(
			null,
			array( 'show_rating' => true, 'show_count' => true, 'show_stars' => false, 'show_logos' => false ),
			$rated,
			array( 'ink' => '#101828', 'muted' => '#434960' ),
			4.8,
			128,
			array(
				'pad_x'     => 16,
				'pad_y'     => 14,
				'gap'       => 14,
				'col_gap'   => 4,
				'val_size'  => 24,
				'cnt_size'  => 12,
				'star_size' => 16,
				'star_gap'  => 2,
				'foot_h'    => 25,
				'scale'     => 2,
			)
		);

		$this->assertSame('4.8', $content['value']);
		$this->assertStringContainsString('128', $content['count_label']);
	}
}
