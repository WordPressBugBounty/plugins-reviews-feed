<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * The cache-only aggregate read (SBR-163).
 *
 * The locked upsell wall wants the site's own rating, but must not pay for it:
 * get_aggregate() falls through to the feed's refresh path on a miss and can fire a
 * synchronous provider fetch, which would make the locked screen the slowest page in
 * the plugin and warm a cache for a user who cannot use the feature.
 *
 * cached_aggregate() therefore reads and gives up. These drive its first probe key,
 * which is the one that needs no feed settings.
 */
class BadgeCachedAggregateTest extends TestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $wp_transients_mock;

		$wp_transients_mock = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		// The transient double is a process-wide global: rows left here answer
		// get_transient() for whatever runs next, so the leak shows up as a test
		// that only fails in some orders.
		global $wp_transients_mock;

		$wp_transients_mock = array();

		parent::tearDown();
	}

	/**
	 * The same key cached_aggregate() probes first.
	 *
	 * @param int    $feed_id   Feed id.
	 * @param string $source_id Source scope.
	 *
	 * @return string
	 */
	private function probeKey($feed_id, $source_id = '')
	{
		$method = new ReflectionMethod(SBR_Badge_Renderer::class, 'cache_key');
		$method->setAccessible(true);

		return $method->invoke(null, $feed_id, array(), $source_id);
	}

	/**
	 * @return void
	 */
	public function test_a_warm_row_is_returned_as_it_was_written()
	{
		global $wp_transients_mock;

		$wp_transients_mock[ $this->probeKey(7) ] = array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array( 'google' ),
		);

		$out = SBR_Badge_Renderer::cached_aggregate(7);

		$this->assertNotNull($out);
		$this->assertSame(4.6, $out['rating']);
		$this->assertSame(12, $out['count']);
		$this->assertTrue($out['resolved']);
	}

	/**
	 * A warm read writes nothing back. The MISS path is not asserted here: it falls
	 * through to SBR_Settings::get_settings_by_feed_id(), which needs the container and
	 * the database. Verified live instead — on feed 218 it returned null and the badge
	 * transient count stayed at 0.
	 *
	 * @return void
	 */
	public function test_a_warm_read_writes_nothing_back()
	{
		global $wp_transients_mock;

		$row = array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		$wp_transients_mock[ $this->probeKey(7) ] = $row;

		SBR_Badge_Renderer::cached_aggregate(7);

		$this->assertSame(array( $this->probeKey(7) => $row ), $wp_transients_mock);
	}

	/**
	 * @dataProvider badFeedProvider
	 *
	 * @param mixed $feed_id What arrives.
	 *
	 * @return void
	 */
	public function test_an_id_that_names_no_feed_is_null($feed_id)
	{
		$this->assertNull(SBR_Badge_Renderer::cached_aggregate($feed_id));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function badFeedProvider()
	{
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -4 ),
			'string'   => array( 'abc' ),
		);
	}

	/**
	 * The source scope is part of the key, so a badge pinned to one source does not
	 * read the whole feed's row by accident.
	 *
	 * @return void
	 */
	public function test_a_source_scoped_read_does_not_answer_from_the_feed_row()
	{
		global $wp_transients_mock;

		$wp_transients_mock[ $this->probeKey(7) ] = array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		// The keys differing is necessary but not sufficient: cached_aggregate()
		// could ignore its source argument entirely and the key comparison would
		// still pass. So drive the reader — the feed-wide row is seeded above, and
		// a source-scoped read must not find it.
		$this->assertNull(
			SBR_Badge_Renderer::cached_aggregate(7, 'sbr-qa-google'),
			'A source-scoped read must not be able to answer from the feed row.'
		);

		// And the seeded row IS readable feed-wide, or the assertion above would
		// pass because nothing was cached at all.
		$feed_wide = SBR_Badge_Renderer::cached_aggregate(7);

		$this->assertIsArray($feed_wide, 'the fixture must be readable without a source scope');
		$this->assertSame(4.6, $feed_wide['rating']);

		$this->assertNotSame(
			$this->probeKey(7),
			$this->probeKey(7, 'sbr-qa-google'),
			'the two reads must not share a key'
		);
	}
}
