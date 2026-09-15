<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * The placeholder preview, and the silence that pairs with it (SBR-163).
 *
 * A badge that cannot report a rating used to behave two different ways at once. The
 * editor previewed the NEWEST FEED on the site — 4.5 from 96,864 reviews on the install
 * this was found on — while every public surface for that same badge rendered nothing.
 * So the one screen that made a promise was the one showing numbers that were not the
 * author's.
 *
 * Both halves changed, and they are two sides of one rule: the editor draws an honest
 * placeholder (0.0 over "Based on 0 reviews", a neutral chip where a logo will go), and a
 * page draws nothing at all — including for a resolved feed with nothing rated in it,
 * which previously published "—" and "No reviews yet".
 *
 * These tests use the SUPPLIED-aggregate form of render(), so they assert the rendering
 * rule without touching the database.
 */
class BadgePlaceholderPreviewTest extends TestCase
{
	/**
	 * @param array<string, mixed> $over
	 *
	 * @return array<string, mixed>
	 */
	private function args($over = array())
	{
		return array_merge(array(
			'shape'  => 'card',
			'theme'  => 'light',
			'rating' => true,
			'stars'  => true,
			'count'  => true,
			'logos'  => true,
		), $over);
	}

	/**
	 * @param float $rating
	 * @param int   $count
	 *
	 * @return array<string, mixed>
	 */
	private function aggregate($rating, $count)
	{
		return array(
			'rating'    => $rating,
			'count'     => $count,
			'resolved'  => true,
			'scale'     => 5,
			'providers' => array(),
		);
	}

	/**
	 * A page renders nothing when the badge cannot report a rating.
	 *
	 * Not a style choice: a badge exists to make a claim about a business, and
	 * "no reviews yet" is not a claim that business would choose to publish.
	 *
	 * @return void
	 */
	public function test_public_render_is_silent_when_there_is_no_rating()
	{
		$this->assertSame(
			'',
			SBR_Badge_Renderer::render($this->args(), $this->aggregate(0.0, 0)),
			'a resolved feed with nothing rated in it must not publish a badge'
		);
	}

	/**
	 * The same input, with the editor asking, draws the placeholder instead.
	 *
	 * @return void
	 */
	public function test_editor_preview_draws_a_placeholder_instead_of_nothing()
	{
		$html = SBR_Badge_Renderer::render(
			$this->args(array('placeholder' => true)),
			$this->aggregate(0.0, 0)
		);

		$this->assertNotSame('', $html, 'the editor must have something to lay out');
		$this->assertStringContainsString('data-placeholder="1"', $html);
		$this->assertStringContainsString('0.0', $html, 'the placeholder rating reads 0.0, never a dash');
		$this->assertStringContainsString('Based on 0 reviews', $html);
	}

	/**
	 * The flag says the editor is asking. It does not say this badge needs a placeholder.
	 *
	 * A rated badge previewed with the flag on must come back unmarked, or the CSS dims
	 * and blurs numbers that are genuinely the author's.
	 *
	 * @return void
	 */
	public function test_a_rated_badge_is_never_marked_as_a_placeholder()
	{
		$html = SBR_Badge_Renderer::render(
			$this->args(array('placeholder' => true)),
			$this->aggregate(4.9, 4775)
		);

		$this->assertStringNotContainsString('data-placeholder', $html);
		$this->assertStringContainsString('4.9', $html);
	}

	/**
	 * The editor and the page agree on a rated badge, byte for byte.
	 *
	 * @return void
	 */
	public function test_a_rated_badge_renders_identically_with_and_without_the_flag()
	{
		$this->assertSame(
			SBR_Badge_Renderer::render($this->args(), $this->aggregate(4.9, 4775)),
			SBR_Badge_Renderer::render($this->args(array('placeholder' => true)), $this->aggregate(4.9, 4775))
		);
	}

	/**
	 * Every element hidden left a bordered box with nothing inside it.
	 *
	 * The editor disables the last remaining toggle, but sanitize_settings() accepts all
	 * four false, so anything saved through the API could reach a page as an empty shell.
	 *
	 * @return void
	 */
	public function test_a_badge_with_every_element_hidden_renders_nothing()
	{
		$html = SBR_Badge_Renderer::render(
			$this->args(array('rating' => false, 'stars' => false, 'count' => false, 'logos' => false)),
			$this->aggregate(4.9, 4775)
		);

		$this->assertSame('', $html);
	}

	/**
	 * The placeholder is editor-only by construction: without the flag, silence.
	 *
	 * @return void
	 */
	public function test_no_public_argument_shape_can_produce_a_placeholder()
	{
		foreach (array('chip', 'card', 'medal') as $shape) {
			$this->assertSame(
				'',
				SBR_Badge_Renderer::render($this->args(array('shape' => $shape)), $this->aggregate(0.0, 0)),
				$shape . ' must render nothing without the editor flag'
			);
		}
	}

	/**
	 * All three styles place the number and the count, so the shape is layoutable.
	 *
	 * @return void
	 */
	public function test_every_style_draws_the_placeholder()
	{
		foreach (array('chip', 'card', 'medal') as $shape) {
			$html = SBR_Badge_Renderer::render(
				$this->args(array('shape' => $shape, 'placeholder' => true)),
				$this->aggregate(0.0, 0)
			);

			$this->assertStringContainsString('data-placeholder="1"', $html, $shape);
			$this->assertStringContainsString('0.0', $html, $shape);
			$this->assertStringContainsString('sbr-tb-provider-placeholder', $html, $shape . ' needs a chip where the logo goes');
		}
	}

	/**
	 * The placeholder never names a platform it was not given.
	 *
	 * A real brand's mark in a badge nobody pointed at that brand is fakery even at
	 * 2.5px of blur, so the empty row gets a neutral chip instead.
	 *
	 * @return void
	 */
	public function test_the_placeholder_invents_no_brand()
	{
		$html = SBR_Badge_Renderer::render(
			$this->args(array('placeholder' => true)),
			$this->aggregate(0.0, 0)
		);

		foreach (array('google', 'yelp', 'trustpilot', 'facebook', 'tripadvisor') as $brand) {
			$this->assertStringNotContainsString('sbr-tb-provider-' . $brand, $html);
			$this->assertStringNotContainsString('sbr-tb-lockup-' . $brand, $html);
		}
	}
}
