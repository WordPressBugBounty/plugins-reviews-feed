<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * A multi-source badge renders from the sources that report.
 *
 * It used to require that EVERY chosen source contributed, and fail closed
 * otherwise. The effect on a real badge: 19 sources, 18 reporting genuine
 * ratings (4.6 from 254,766 reviews), one QA fixture that had never fetched —
 * and the public badge rendered nothing while the editor showed a zero. Reported
 * twice from QA as "multi-source badge not rendering".
 *
 * The rule was not protecting anything either. A source with no data row
 * contributes no reviews, so leaving it out changes the number by nothing — a
 * source with zero reviews AND a data row was already counted that way. Worse,
 * `unseen_rows()` drops a healthy source whose rows a sibling already
 * contributed, so the check failed on badges where every source worked.
 *
 * What keeps it honest is that `providers` is built from the CONTRIBUTING set,
 * so the badge never shows a platform's logo whose reviews are not in the
 * rating. That is asserted here.
 *
 * A source that ERRORED still fails closed, and that is deliberate: a broken
 * fetch may mean the number would be wrong, and being wrong in public is worth
 * hiding the badge for.
 *
 * NOTE on coverage. The policy lives in get_aggregate_for_sources(), which
 * reaches the feed tables, so the end-to-end proof is the live one: 39 of 39
 * badges on DDEV and badge 52 on the QA site render identically on both
 * surfaces after this change. What is asserted below is the seam that decides
 * which sources contributed, plus the policy applied to its result.
 */
class BadgeMultiSourcePartialTest extends TestCase
{
	/**
	 * A header row in the shape scope_to_source() reads.
	 *
	 * @param string $id     Source id.
	 * @param float  $rating Rating.
	 * @param int    $count  Review count.
	 *
	 * @return array<string, mixed>
	 */
	private function row($id, $rating, $count)
	{
		return array( 'info' => array( 'id' => $id, 'rating' => $rating, 'num_ratings' => $count ) );
	}

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
	 * @param string $src  File source.
	 * @param string $name Method name.
	 *
	 * @return string
	 */
	private function methodBody($src, $name)
	{
		// Tokenised, so a brace inside a string, heredoc or comment cannot move
		// the depth counter and truncate the body — which a character walk did,
		// silently, giving a false pass or a false failure. See
		// tests/Support/PhpSource.php.
		// Leading backslash: these test files are namespaced and the helper is global.
		return \SbrPhpSource::method_body($src, $name);
	}

	/**
	 * A source with no rows drops out, and that is NOT an error.
	 *
	 * @return void
	 */
	public function test_a_source_with_no_rows_is_excluded_without_erroring()
	{
		$built = array(
			'errored'  => false,
			'rows'     => array( $this->row('src-a', 4.5, 100), $this->row('src-b', 3.5, 50) ),
			'hydrated' => array(),
		);

		$out = $this->method('contribution_from_built')
			->invoke(null, $built, array( 'src-a', 'src-b', 'src-never-fetched' ));

		$this->assertSame(
			array( 'src-a', 'src-b' ),
			array_values($out['chosen']),
			'the reporting sources must still be chosen'
		);

		$this->assertEmpty(
			$out['errored'],
			'a source that has not fetched is not a failure — only an errored fetch is'
		);

		$this->assertNotEmpty(
			$out['contribution'],
			'there is real data here, so there is a rating to publish'
		);

		$this->assertTrue(
			! empty($out['contribution']['resolved']),
			'the contribution from the reporting sources must resolve'
		);
	}

	/**
	 * An errored fetch still takes the badge down.
	 *
	 * @return void
	 */
	public function test_an_errored_source_still_fails_closed()
	{
		$out = $this->method('contribution_from_built')->invoke(
			null,
			array( 'errored' => true, 'rows' => array( $this->row('src-a', 4.5, 100) ), 'hydrated' => array() ),
			array( 'src-a' )
		);

		$this->assertTrue((bool) $out['errored'], 'an errored build must be reported as errored');
		$this->assertNull($out['contribution'], 'nothing may be published from an errored build');
	}

	/**
	 * The policy: no count-equality bail, and the gap is named for the editor.
	 *
	 * @return void
	 */
	public function test_the_aggregate_no_longer_bails_on_a_partial_set()
	{
		$body = $this->methodBody($this->source(), 'get_aggregate_for_sources');

		$this->assertNotSame('', $body, 'get_aggregate_for_sources() body not found');

		// All four match with whitespace squeezed out of both sides. It matters most
		// for the NEGATIVE one: WPCS wants spaces inside parentheses, so a
		// reintroduced bail written `count( $chosen ) !== count( $ids )` slipped
		// straight past the old literal comparison — the assertion would have been
		// green with the bug back in place.
		$this->assertFalse(
			\SbrPhpSource::contains($body, 'count($chosen) !== count($ids)'),
			'the all-or-nothing bail is back: one source that has not fetched will blank the badge'
		);

		// The guard that must stay.
		$this->assertTrue(
			\SbrPhpSource::contains($body, "empty(\$out['errored'])"),
			'an errored source must still fail closed'
		);

		// The editor needs to be able to name what did not count.
		$this->assertTrue(
			\SbrPhpSource::contains($body, "'unreported'"),
			'the aggregate must carry the sources that contributed nothing, or the editor cannot name them'
		);

		// Honesty: logos come from the CONTRIBUTING set, never the chosen ids.
		$this->assertTrue(
			\SbrPhpSource::contains($body, 'providers_for_sources($chosen)'),
			'providers must be derived from the contributing sources, or the badge shows a logo '
			. 'for a platform whose reviews are not in the rating'
		);
	}

	/**
	 * The public shape never carries the editor-only key.
	 *
	 * @return void
	 */
	public function test_normalize_aggregate_does_not_carry_unreported_to_a_template()
	{
		$normalized = $this->method('normalize_aggregate')->invoke(
			null,
			array(
				'rating'     => 4.6,
				'count'      => 120,
				'resolved'   => true,
				'unreported' => array( 'src-never-fetched' ),
			)
		);

		$this->assertArrayNotHasKey(
			'unreported',
			$normalized,
			'a visitor is not shown which of the author\'s sources are lagging'
		);
	}
}
