<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * render()'s optional second argument: an aggregate the caller already has (SBR-163).
 *
 * The All Badges empty state shows a real badge rather than a screenshot, and it must not
 * pay for that: render() normally resolves the aggregate itself through get_aggregate(),
 * which falls through to the feed's refresh path on a miss and can fire a synchronous
 * provider fetch. Handing the numbers over skips the lookup entirely.
 *
 * Two things to hold: EXISTING callers pass one argument and must be unaffected, and a
 * supplied aggregate has to be treated as untrusted input like every other argument.
 */
class BadgeSuppliedAggregateTest extends TestCase
{
	/**
	 * @return ReflectionMethod
	 */
	private function normalizer()
	{
		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'normalize_aggregate');
		$method->setAccessible(true);

		return $method;
	}

	/**
	 * BC: the signature stays callable the way every existing caller calls it.
	 *
	 * The shortcode, the block, the widget and the email image all call render($args).
	 * A required second parameter would have been a fatal for every one of them.
	 *
	 * @return void
	 */
	public function test_render_is_still_callable_with_one_argument()
	{
		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'render');

		$this->assertSame(
			0,
			$method->getNumberOfRequiredParameters(),
			'render() must keep working for callers that pass only $args.'
		);
		$this->assertSame(2, $method->getNumberOfParameters());

		$aggregate = $method->getParameters()[1];

		$this->assertTrue(
			$aggregate->isOptional(),
			'The aggregate has to be optional or every existing call site breaks.'
		);
		$this->assertNull($aggregate->getDefaultValue());
	}

	/**
	 * An unknown shape still renders nothing, aggregate or no aggregate.
	 *
	 * Supplying the numbers must not become a way past the guards above the lookup.
	 *
	 * @return void
	 */
	public function test_a_supplied_aggregate_does_not_bypass_the_shape_guard()
	{
		$rated = array(
			'rating'   => 4.6,
			'count'    => 12,
			'resolved' => true,
		);

		$this->assertSame(
			'',
			SBR_Badge_Renderer::render(array( 'shape' => 'not-a-shape' ), $rated)
		);
	}

	/**
	 * An unresolved aggregate renders nothing, exactly as a failed lookup does.
	 *
	 * This is the fail-closed rule: a badge with nothing behind it publishes nothing,
	 * because "— / No reviews yet" is a public claim about a business.
	 *
	 * @return void
	 */
	public function test_an_unresolved_supplied_aggregate_renders_nothing()
	{
		$this->assertSame(
			'',
			SBR_Badge_Renderer::render(
				array( 'shape' => 'card' ),
				array( 'rating' => 4.6, 'count' => 12, 'resolved' => false )
			)
		);
	}

	/**
	 * The normaliser guarantees the keys prepare() reads without an isset guard.
	 *
	 * prepare() takes rating and count directly, so a caller handing over a partial
	 * array would fatal on PHP 8 instead of rendering nothing.
	 *
	 * @return void
	 */
	public function test_a_partial_aggregate_is_completed_rather_than_fataling()
	{
		$normalized = $this->normalizer()->invoke(null, array( 'resolved' => true ));

		$this->assertSame(0.0, $normalized['rating']);
		$this->assertSame(0, $normalized['count']);
		$this->assertSame(5, $normalized['scale']);
		$this->assertSame('', $normalized['word']);
		$this->assertSame(array(), $normalized['providers']);
		$this->assertTrue($normalized['resolved']);
	}

	/**
	 * Hostile values are coerced, not trusted.
	 *
	 * A zero or negative scale would divide by nothing downstream, a negative count
	 * would read as a review total, and a providers list of non-strings would reach the
	 * template's platform lookup.
	 *
	 * @return void
	 */
	public function test_hostile_values_are_coerced()
	{
		$normalized = $this->normalizer()->invoke(
			null,
			array(
				'rating'    => 'not-a-number',
				'count'     => -5,
				'scale'     => 0,
				'word'      => array( 'nope' ),
				'providers' => array( 'google', 42, null, 'yelp' ),
				'resolved'  => 1,
			)
		);

		$this->assertSame(0.0, $normalized['rating'], 'A non-numeric rating is not a rating.');
		$this->assertSame(0, $normalized['count'], 'A negative count cannot be a review total.');
		$this->assertSame(5, $normalized['scale'], 'A zero scale would divide by nothing.');
		$this->assertSame(array( 'google', 'yelp' ), $normalized['providers']);
		$this->assertTrue($normalized['resolved']);
		// An array cast to string is a PHP warning and the literal "Array", which would
		// reach a template as this badge's score word. Caught by this test on first run.
		$this->assertSame('', $normalized['word']);
	}

	/**
	 * A negative count folded to a positive one would be worse than rejecting it.
	 *
	 * absint(-5) is 5. That fold has bitten this ticket repeatedly, so it is asserted
	 * here rather than left to the reader.
	 *
	 * @return void
	 */
	public function test_a_negative_count_is_not_folded_to_a_positive_one()
	{
		$normalized = $this->normalizer()->invoke(null, array( 'count' => -12, 'resolved' => true ));

		$this->assertNotSame(12, $normalized['count']);
		$this->assertSame(0, $normalized['count']);
	}
}
