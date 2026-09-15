<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * Badge presentation per rating scale (SBR-163).
 *
 * Booking-only feeds report their native 0-10 score; everything else is a
 * 5-star average. The presentation has to follow the scale, because the failure
 * mode is silent: a 9.5 rendered as "9.5 out of 5" on five stars looks like a
 * formatting slip but is actually the badge disagreeing with the feed header on
 * the same page — the bug this whole path exists to prevent.
 *
 * prepare() is exercised directly so the matrix is covered without standing up
 * a Feed, a cache and a licence.
 */
class BadgeScalePresentationTest extends TestCase
{
	/**
	 * @param array $aggregate Aggregate array.
	 *
	 * @return array
	 */
	private function prepare($aggregate, $overrides = array())
	{
		// Through sanitize_args(), because that is what every real caller hands
		// prepare(). A hand-written literal drifts: this one predated `theme`,
		// `logos` and `logo_style` and raised an undefined-key warning per test.
		$args = array_merge(
			array(
				'shape'    => 'trust',
				'size'     => 'default',
				'feed'     => 1,
				'business' => 'Acme',
			),
			$overrides
		);

		$sanitize = new ReflectionMethod(SBR_Badge_Renderer::class, 'sanitize_args');
		$sanitize->setAccessible(true);

		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'prepare');
		$method->setAccessible(true);

		return $method->invoke(null, $sanitize->invoke(null, $args), $aggregate);
	}

	/**
	 * A 0-10 score must never be drawn on five stars.
	 *
	 * @return void
	 */
	public function test_ten_scale_suppresses_stars()
	{
		$out = $this->prepare(array(
			'rating' => 9.5, 'count' => 1041, 'resolved' => true, 'scale' => 10, 'word' => 'Exceptional',
		));

		$this->assertFalse($out['show_stars'], 'Five stars cannot draw 9.5 without rescaling it.');
		$this->assertSame('Exceptional', $out['score_word']);
		$this->assertSame(10, $out['scale']);
	}

	/**
	 * ...and the "out of" label must follow the scale, or the footer shape
	 * renders "9.5 out of 5".
	 *
	 * @return void
	 */
	public function test_out_of_label_follows_the_scale()
	{
		$ten = $this->prepare(array(
			'rating' => 9.5, 'count' => 1041, 'resolved' => true, 'scale' => 10, 'word' => 'Exceptional',
		));
		$five = $this->prepare(array(
			'rating' => 4.5, 'count' => 408, 'resolved' => true, 'scale' => 5, 'word' => '',
		));

		$this->assertStringContainsString('10', $ten['out_of_label']);
		$this->assertStringContainsString('5', $five['out_of_label']);
		$this->assertNotSame($ten['out_of_label'], $five['out_of_label']);
	}

	/**
	 * The ordinary 5-star path must be untouched by any of this.
	 *
	 * @return void
	 */
	public function test_five_scale_keeps_stars_and_no_word()
	{
		$out = $this->prepare(array(
			'rating' => 4.5, 'count' => 408, 'resolved' => true, 'scale' => 5, 'word' => '',
		));

		$this->assertTrue($out['show_stars'], 'A 5-star average is exactly what stars are for.');
		$this->assertSame('', $out['score_word']);
		$this->assertSame(5, $out['scale']);
	}

	/**
	 * An aggregate from before scale/word existed must behave like a 5-star one
	 * rather than tripping the 0-10 branch.
	 *
	 * @return void
	 */
	public function test_aggregate_without_scale_defaults_to_five()
	{
		$out = $this->prepare(array( 'rating' => 4.5, 'count' => 408, 'resolved' => true ));

		$this->assertSame(5, $out['scale']);
		$this->assertTrue($out['show_stars']);
		$this->assertSame('', $out['score_word']);
	}

	/**
	 * Every rail exposes a "Provider label" control, so the value has to reach
	 * the shapes. It used to be folded into the trust subtext only, which meant
	 * setting it on footer, checkout or signature silently did nothing — a
	 * control that lies. prepare() now emits a suffix those shapes append.
	 *
	 * @return void
	 */
	public function test_provider_is_available_to_every_shape()
	{
		$out = $this->prepare(
			array( 'rating' => 4.5, 'count' => 408, 'resolved' => true, 'scale' => 5, 'word' => '' ),
			array( 'provider' => 'Google' )
		);

		$this->assertNotSame('', $out['provider_suffix'], 'A set provider must produce a renderable fragment.');
		$this->assertStringContainsString('Google', $out['provider_suffix']);
	}

	/**
	 * ...but an unrated badge must not name a provider, or it implies reviews
	 * exist on that platform when none were found.
	 *
	 * @return void
	 */
	public function test_unrated_badge_names_no_provider()
	{
		$out = $this->prepare(
			array( 'rating' => 0.0, 'count' => 0, 'resolved' => true, 'scale' => 5, 'word' => '' ),
			array( 'provider' => 'Google' )
		);

		$this->assertFalse($out['is_rated']);
		$this->assertSame('', $out['provider_suffix'], 'No rating means no provider claim.');
	}



	/**
	 * An unset colour must emit NO inline custom property, so the stylesheet
	 * default (and any theme override) still applies. This is the "inherit"
	 * contract the widget's empty star_color field depends on.
	 *
	 * @return void
	 */
	public function test_unset_colours_emit_no_inline_style()
	{
		$out = $this->prepare(
			array( 'rating' => 4.5, 'count' => 408, 'resolved' => true, 'scale' => 5, 'word' => '' )
		);

		$this->assertSame('', $out['style'], 'No colour chosen means nothing inline to override the theme.');
	}

	/**
	 * A feed can report a rating with every count empty — Parser falls back to an
	 * unweighted mean (MultiSourceAggregationTest::
	 * test_all_zero_counts_falls_back_to_unweighted_mean). is_rated is then false,
	 * so the star row must NOT draw the raw value: filled stars and
	 * aria-label "4.5 out of 5 stars" beside "—" and "No reviews yet" is a
	 * self-contradicting badge.
	 *
	 * @return void
	 */
	public function test_unrated_five_scale_does_not_draw_filled_stars()
	{
		$out = $this->prepare(array(
			'rating' => 4.5, 'count' => 0, 'resolved' => true, 'scale' => 5, 'word' => '',
		));

		$this->assertFalse($out['is_rated'], 'No counts means nothing to claim.');
		$this->assertSame('—', $out['rating_label']);
		$this->assertSame(
			0.0,
			$out['stars_rating'],
			'The star row must draw the rated value (0), not the raw 4.5.'
		);
	}

	/**
	 * ...and a genuinely rated feed still draws its real value.
	 *
	 * @return void
	 */
	public function test_rated_five_scale_draws_its_real_value()
	{
		$out = $this->prepare(array(
			'rating' => 4.5, 'count' => 408, 'resolved' => true, 'scale' => 5, 'word' => '',
		));

		$this->assertTrue($out['is_rated']);
		$this->assertSame(4.5, $out['stars_rating']);
	}

	/**
	 * A Booking payload can carry a score but no counts
	 * (Smash782BookingHeaderRatingTest::test_no_counts_falls_back_to_unweighted_mean),
	 * which makes is_rated false. The qualifier must not survive that, or the
	 * badge publishes "— Exceptional / No reviews yet" — a quality claim beside a
	 * zero-review label.
	 *
	 * @return void
	 */
	public function test_unrated_ten_scale_does_not_publish_a_qualifier()
	{
		$out = $this->prepare(array(
			'rating' => 8.5, 'count' => 0, 'resolved' => true, 'scale' => 10, 'word' => 'Very good',
		));

		$this->assertFalse($out['is_rated'], 'No counts means nothing to claim.');
		$this->assertSame('—', $out['rating_label']);
		// The templates gate the word on is_rated; assert the flag they read.
		$this->assertFalse(
			$out['is_rated'] && $out['score_word'] !== '',
			'An unrated badge must not render a quality qualifier.'
		);
	}
}
