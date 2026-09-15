<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * A badge never publishes a rating its author did not ask for.
 *
 * render() has one fallback — `get_aggregate($args['feed'], $args['source'])` —
 * and with several sources `sole_source_id()` is '', so that call returns the
 * feed's ENTIRE rating. Every path that cannot answer from the author's own
 * sources therefore has to say so, or the fallback publishes reviews they never
 * selected. The map path has refused since it was written. Two source paths did
 * not, and both were reachable:
 *
 *   - sanitize_source_ids() returns an empty array past SOURCE_MAP_MAX_SOURCES
 *     and `sources` is uncapped at save, so a badge over 51 sources arrived with
 *     no sources and no `failed` flag. Proven live: it published 4.6.
 *   - get_aggregate_for_sources() returned a bare empty when NOTHING the author
 *     picked contributed, which fell through the same way.
 *
 * Asserted on the aggregate shape rather than the rendered badge because
 * render() needs the feed tables; the end-to-end proof is in the commit.
 */
class BadgeFallthroughGuardsTest extends TestCase
{
	/**
	 * @param string $name Method name.
	 *
	 * @return \ReflectionMethod
	 */
	private function method($name)
	{
		$m = new \ReflectionMethod(SBR_Badge_Renderer::class, $name);
		$m->setAccessible(true);

		return $m;
	}

	/**
	 * @return string
	 */
	private function source()
	{
		return (string) file_get_contents(
			dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Renderer.php'
		);
	}

	/**
	 * Past the cap the sanitiser answers "none", which must not read as "no sources".
	 *
	 * @return void
	 */
	public function test_too_many_sources_are_refused_by_the_sanitiser()
	{
		$cap  = (new \ReflectionClass(SBR_Badge_Renderer::class))->getConstant('SOURCE_MAP_MAX_SOURCES');
		$this->assertIsInt($cap);

		$ids = array();

		for ($i = 0; $i <= $cap; $i++) {
			$ids[] = 'src-' . $i;
		}

		$this->assertGreaterThan($cap, count($ids), 'the fixture must exceed the cap to test it');

		$sanitised = $this->method('sanitize_source_ids')->invoke(null, $ids);

		$this->assertSame(
			array(),
			$sanitised,
			'past the cap the sanitiser refuses the set — which is why render() has to tell '
			. 'that apart from a badge that named no sources at all'
		);

		// Within the cap it still works, or the guard would be refusing everything.
		$this->assertNotSame(
			array(),
			$this->method('sanitize_source_ids')->invoke(null, array( 'src-1', 'src-2' )),
			'a normal set must survive'
		);
	}

	/**
	 * render() must refuse when sources were OFFERED and the sanitiser rejected them.
	 *
	 * @return void
	 */
	public function test_render_refuses_offered_sources_that_did_not_survive()
	{
		$body = $this->method('render')->getDeclaringClass()->getName();
		$this->assertSame(SBR_Badge_Renderer::class, $body);

		// Bounded by the method's own closing brace, not by a byte count. A fixed
		// 4000-byte window fails for the wrong reason the moment the method grows
		// past it, and can match unrelated code that follows.
		$head = \SbrPhpSource::method_body($this->source(), 'render');

		$this->assertNotSame('', $head, 'render() must be declared in this class');

		// Captured before sanitising, or it cannot tell the two states apart.
		$this->assertStringContainsString(
			'$offered_sources',
			$head,
			'render() must record whether sources were offered BEFORE sanitising them'
		);

		// Whitespace-insensitive like the rest of the suite: WPCS wants spaces
		// inside parentheses, so `if ( $offered_sources && empty( $args['sources'] ) )`
		// would have failed these two for a reformat that changed nothing. They were
		// the last raw comparisons left in this file.
		$this->assertTrue(
			\SbrPhpSource::contains($head, "if (\$offered_sources && empty(\$args['sources'])) {"),
			'offered-but-rejected sources must refuse, not fall through to the feed — '
			. 'a 52-source badge published 4.6 without this'
		);

		// The map guard it mirrors must still be there too.
		$this->assertTrue(
			\SbrPhpSource::contains($head, "if (\$offered_map && empty(\$args['source_map'])) {"),
			'the map guard this mirrors has gone missing'
		);
	}

	/**
	 * Nothing contributed is a refusal, not an empty.
	 *
	 * @return void
	 */
	public function test_no_contribution_fails_closed()
	{
		$out = $this->method('contribution_from_built')->invoke(
			null,
			array( 'errored' => false, 'rows' => array(), 'hydrated' => array() ),
			array( 'src-a' )
		);

		$this->assertNull($out['contribution'], 'no rows means no contribution');

		$body = $this->method('get_aggregate_for_sources')->getName();
		$this->assertSame('get_aggregate_for_sources', $body);

		$head = \SbrPhpSource::method_body($this->source(), 'get_aggregate_for_sources');

		$this->assertNotSame('', $head, 'get_aggregate_for_sources() must be declared in this class');

		// Whitespace-insensitive on both sides. The needle used to carry a newline
		// and three tabs, so running phpcbf or moving the return one level deeper
		// failed this test with no behavioural change.
		$this->assertTrue(
			\SbrPhpSource::contains($head, "if (empty(\$out['contribution'])) { return self::closed(\$empty);"),
			'nothing contributed must fail CLOSED — a bare empty falls through to the '
			. "feed's whole rating"
		);
	}

	/**
	 * The partial case must still resolve, or the multi-source fix is undone.
	 *
	 * @return void
	 */
	public function test_a_partial_set_still_reports()
	{
		$row = static function ($id, $rating, $count) {
			return array( 'info' => array( 'id' => $id, 'rating' => $rating, 'num_ratings' => $count ) );
		};

		$out = $this->method('contribution_from_built')->invoke(
			null,
			array(
				'errored'  => false,
				'rows'     => array( $row('src-a', 4.5, 100) ),
				'hydrated' => array(),
			),
			array( 'src-a', 'src-lagging' )
		);

		$this->assertNotEmpty(
			$out['contribution'],
			'one reporting source out of two must still produce a rating — closing on this '
			. 'is the bug the 19-source badge had'
		);

		$this->assertSame(array( 'src-a' ), array_values($out['chosen']));
	}
}
