<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * Cached-aggregate shape BC (SBR-163).
 *
 * The cached payload has grown twice: `resolved`, then `scale` + `word`. Each
 * time, entries written by the previous shape stay live until their TTL expires,
 * so read_cache() has to cope with them without emitting warnings (phpunit.xml
 * sets failOnWarning) and without changing what a normal 5-scale badge renders.
 *
 * `resolved` fails SAFE on a legacy entry — unknown means "render nothing"
 * rather than risk publishing "No reviews yet" for a business that has reviews.
 * `scale`/`word` fail OPEN to the 5-star default, because that is what every
 * pre-existing entry actually was.
 */
class BadgeAggregateShapeBcTest extends TestCase
{
	/**
	 * @param array $cached Raw cached payload.
	 *
	 * @return array|null
	 */
	private function read($cached)
	{
		// Only the probe key is cleared. Blanking the whole global wiped state this
		// file does not own — no save/restore needed if nothing shared is touched.
		global $wp_transients_mock;

		if (! is_array($wp_transients_mock)) {
			$wp_transients_mock = array();
		}

		unset($wp_transients_mock['sbr_badge_bc_probe']);

		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'read_cache');
		$method->setAccessible(true);

		set_transient('sbr_badge_bc_probe', $cached, 60);

		return $method->invoke(null, 'sbr_badge_bc_probe');
	}

	/**
	 * An entry from the ORIGINAL shape (rating + count only) must not warn and
	 * must default to the 5 scale.
	 *
	 * @return void
	 */
	public function test_legacy_entry_without_scale_defaults_to_five()
	{
		$out = $this->read(array( 'rating' => 4.5, 'count' => 408 ));

		$this->assertIsArray($out);
		$this->assertSame(5, $out['scale'], 'A legacy entry predates Booking support, so it is a 5-scale rating.');
		$this->assertSame('', $out['word'], 'No qualifier word existed before Booking support.');
	}

	/**
	 * The same legacy entry must fail SAFE on `resolved`, so a stale negative
	 * cache can never be mistaken for a real aggregate.
	 *
	 * @return void
	 */
	public function test_legacy_entry_is_treated_as_unresolved()
	{
		$out = $this->read(array( 'rating' => 4.5, 'count' => 408 ));

		$this->assertFalse(
			$out['resolved'],
			'Unknown resolution must fail safe — a missing badge beats a false "No reviews yet".'
		);
	}

	/**
	 * A current-shape Booking entry round-trips intact.
	 *
	 * @return void
	 */
	public function test_current_shape_round_trips()
	{
		$out = $this->read(array(
			'rating'   => 9.5,
			'count'    => 1041,
			'resolved' => true,
			'scale'    => 10,
			'word'     => 'Exceptional',
		));

		$this->assertSame(9.5, $out['rating']);
		$this->assertSame(10, $out['scale']);
		$this->assertSame('Exceptional', $out['word']);
		$this->assertTrue($out['resolved']);
	}

	/**
	 * A malformed entry yields null rather than a half-built aggregate.
	 *
	 * @return void
	 */
	public function test_malformed_entry_is_rejected()
	{
		$this->assertNull($this->read(array( 'rating' => 4.5 )), 'A payload missing `count` is not usable.');
	}
}
