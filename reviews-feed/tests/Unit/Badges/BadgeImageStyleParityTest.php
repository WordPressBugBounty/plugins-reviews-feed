<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;

/**
 * The email image draws the badge's OWN style (SBR-163).
 *
 * SBR_Badge_Image never read `style`, so every badge was drawn to the card's
 * geometry: a chip that showed "4.8 (894)" on the page emailed as a card
 * reading "Based on 894 reviews", and a medal emailed horizontal. Two of the
 * three styles were a different object wearing the same colours, and nothing
 * failed — the endpoint returned a valid PNG every time, which is why it
 * survived a video, a QA table and two review rounds.
 *
 * The phrasing is the sharp end of it. Each template prints a different label
 * (chip.php "(count_raw_label)", card.php count_based_label, medal.php
 * count_on_label), so a single hardcoded phrase cannot be right for more than
 * one of them. These tests pin all three against the templates.
 */
class BadgeImageStyleParityTest extends TestCase
{
	/**
	 * rating_texts() is protected; every caller inside the class reaches it the
	 * same way, and going through it directly keeps the matrix free of a feed,
	 * a cache and a licence.
	 *
	 * @param string             $style     Badge style.
	 * @param int                $count     Review count.
	 * @param array<int, string> $providers Resolved provider slugs.
	 *
	 * @return string The count label.
	 */
	private function label($style, $count = 894, $providers = array())
	{
		$m = new ReflectionMethod(SBR_Badge_Image::class, 'rating_texts');
		$m->setAccessible(true);

		$texts = $m->invoke(
			null,
			array( 'show_rating' => true, 'show_count' => true, 'style' => $style ),
			4.8,
			$count,
			$style,
			$providers
		);

		return $texts[1];
	}

	/**
	 * @return void
	 */
	public function test_chip_parenthesises_the_bare_count()
	{
		$this->assertSame(
			'(894)',
			$this->label('chip'),
			'chip.php prints the count in brackets; the image has to say the same thing.'
		);
	}

	/**
	 * @return void
	 */
	public function test_card_says_based_on()
	{
		$this->assertSame('Based on 894 reviews', $this->label('card'));
	}

	/**
	 * @return void
	 */
	public function test_medal_names_the_platform_when_there_is_exactly_one()
	{
		$this->assertSame(
			'894 reviews on Google',
			$this->label('medal', 894, array( 'google' )),
			'medal.php prints count_on_label, which names the single platform.'
		);
	}

	/**
	 * With several sources behind one number, naming one of them would
	 * attribute the whole rating to it — the renderer's rule, mirrored.
	 *
	 * @return void
	 */
	public function test_medal_names_no_platform_when_several_are_combined()
	{
		$this->assertSame(
			'894 reviews',
			$this->label('medal', 894, array( 'google', 'yelp' ))
		);
	}

	/**
	 * An unrecognised style is a card, matching sanitize_args() on the renderer,
	 * so a badge saved by an older build still emails as something.
	 *
	 * @return void
	 */
	public function test_an_unknown_style_falls_back_to_the_card()
	{
		$this->assertSame('Based on 894 reviews', $this->label('trust'));
	}

	/**
	 * The singular forms, because _n() is easy to wire up backwards and a badge
	 * with one review is the case nobody looks at.
	 *
	 * @return void
	 */
	public function test_one_review_reads_singular_in_every_style()
	{
		$this->assertSame('(1)', $this->label('chip', 1));
		$this->assertSame('Based on 1 review', $this->label('card', 1));
		$this->assertSame('1 review on Google', $this->label('medal', 1, array( 'google' )));
	}

	/**
	 * The three phrasings must not collapse into one. This is the assertion that
	 * would have failed before the fix: all three returned the card's sentence.
	 *
	 * @return void
	 */
	public function test_the_three_styles_do_not_share_one_phrasing()
	{
		$labels = array(
			$this->label('chip'),
			$this->label('card'),
			$this->label('medal', 894, array( 'google' )),
		);

		$this->assertCount(3, array_unique($labels), 'Each style phrases the count its own way.');
	}
}
