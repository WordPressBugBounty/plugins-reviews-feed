<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder;

/**
 * Which feed claims which source, and when the map is allowed to be incomplete.
 *
 * source_map_for_badge() needs a database, so what is driven here is
 * claim_sources() — the part that decides, given feed rows, who gets what — plus
 * the `complete` rule that hangs off it. The refusal it feeds is a behaviour
 * change and belongs pinned, not inferred.
 */
class BadgeSourceMapResolutionTest extends TestCase
{
	/**
	 * A feed row as the query returns it.
	 *
	 * @param int   $id      Feed id.
	 * @param array $sources Source ids the feed carries.
	 *
	 * @return array
	 */
	private function row($id, array $sources)
	{
		return array(
			'id'       => (string) $id,
			'settings' => (string) wp_json_encode(array( 'sources' => $sources )),
		);
	}

	/**
	 * Run the claiming pass over rows and return what it built.
	 *
	 * @param array $rows   Feed rows, newest first.
	 * @param array $wanted Source ids the badge selected.
	 *
	 * @return array{map: array<int, string[]>, found: array<string, true>}
	 */
	private function claim(array $rows, array $wanted)
	{
		$method = new ReflectionMethod(SBR_Trust_Badge_Builder::class, 'claim_sources');
		$method->setAccessible(true);

		$map   = array();
		$found = array();
		$keyed = array();

		foreach ($wanted as $id) {
			$keyed[ $id ] = true;
		}

		$method->invokeArgs(null, array( $rows, $keyed, &$map, &$found ));

		return array( 'map' => $map, 'found' => $found );
	}

	/**
	 * Sources spread over two feeds both get claimed, by their own feed.
	 *
	 * This is the whole point: before it, a badge listing both published whichever
	 * feed carried the first id.
	 *
	 * @return void
	 */
	public function test_sources_in_different_feeds_are_claimed_by_each()
	{
		$claimed = $this->claim(
			array(
				$this->row(9, array( 'yelp-biz' )),
				$this->row(4, array( 'google-place' )),
			),
			array( 'google-place', 'yelp-biz' )
		);

		$this->assertSame(array( 'yelp-biz' ), $claimed['map'][9]);
		$this->assertSame(array( 'google-place' ), $claimed['map'][4]);
	}

	/**
	 * A source in two feeds is claimed once, by the newer one.
	 *
	 * Rows arrive newest first (`ORDER BY id DESC`), which is also how
	 * feed_for_badge() picks — the two resolvers have to agree. Claiming twice
	 * would count one business twice in a count-weighted mean.
	 *
	 * @return void
	 */
	public function test_a_shared_source_is_claimed_once_by_the_newest_feed()
	{
		$claimed = $this->claim(
			array(
				$this->row(9, array( 'google-place' )),
				$this->row(4, array( 'google-place' )),
			),
			array( 'google-place' )
		);

		$this->assertSame(array( 9 => array( 'google-place' ) ), $claimed['map']);
		$this->assertArrayNotHasKey(4, $claimed['map']);
	}

	/**
	 * Sources the badge did not select are ignored.
	 *
	 * A feed usually carries more than the badge asked for, and aggregating the
	 * rest is the unscoped rating this change exists to stop publishing.
	 *
	 * @return void
	 */
	public function test_sources_the_badge_did_not_select_are_left_alone()
	{
		$claimed = $this->claim(
			array( $this->row(9, array( 'google-place', 'facebook-page' )) ),
			array( 'google-place' )
		);

		$this->assertSame(array( 9 => array( 'google-place' ) ), $claimed['map']);
	}

	/**
	 * A source in none of the rows stays unclaimed, which is what makes a map
	 * incomplete.
	 *
	 * @return void
	 */
	public function test_an_unclaimed_source_is_reported_as_missing()
	{
		$claimed = $this->claim(
			array( $this->row(9, array( 'google-place' )) ),
			array( 'google-place', 'deleted-feed-source' )
		);

		$this->assertArrayHasKey('google-place', $claimed['found']);
		$this->assertArrayNotHasKey('deleted-feed-source', $claimed['found']);
		$this->assertNotSame(2, count($claimed['found']), 'An incomplete map must not look complete.');
	}

	/**
	 * Object-shaped sources are read through `account_id`, then `id`.
	 *
	 * Feed settings hold either bare id strings or hydrated source arrays,
	 * depending on how the feed was saved.
	 *
	 * @return void
	 */
	public function test_object_shaped_sources_are_read_by_account_id()
	{
		$claimed = $this->claim(
			array(
				array(
					'id'       => '9',
					'settings' => (string) wp_json_encode(
						array(
							'sources' => array(
								array( 'account_id' => 'google-place', 'name' => 'Shop' ),
								array( 'id' => 'yelp-biz' ),
							),
						)
					),
				),
			),
			array( 'google-place', 'yelp-biz' )
		);

		$this->assertSame(array( 'google-place', 'yelp-biz' ), $claimed['map'][9]);
	}

	/**
	 * Unparseable settings are skipped, not fatal.
	 *
	 * @return void
	 */
	public function test_a_row_with_broken_settings_is_skipped()
	{
		$claimed = $this->claim(
			array(
				array( 'id' => '9', 'settings' => 'not json' ),
				array( 'id' => '8', 'settings' => (string) wp_json_encode(array( 'no_sources_key' => true )) ),
				$this->row(7, array( 'google-place' )),
			),
			array( 'google-place' )
		);

		$this->assertSame(array( 7 => array( 'google-place' ) ), $claimed['map']);
	}

	/**
	 * BC: a badge pinned to a feed still resolves complete, with no map.
	 *
	 * Badges saved before source maps existed carry `feed_id` and no `sources`.
	 * They must keep rendering exactly as they did — `complete` true so
	 * render_saved() does not refuse, and an empty map so the single-feed path
	 * runs untouched.
	 *
	 * @return void
	 */
	public function test_bc_a_pinned_badge_keeps_the_single_feed_path()
	{
		$resolved = SBR_Trust_Badge_Builder::source_map_for_badge(
			array( 'feed_id' => 12, 'sources' => array( 'google-place' ) )
		);

		$this->assertTrue($resolved['complete'], 'A pinned badge is never refused.');
		$this->assertSame(array(), $resolved['map'], 'A pin beats a map.');
	}

	/**
	 * BC: a badge with no sources at all resolves complete, with no map.
	 *
	 * @return void
	 */
	public function test_bc_a_badge_without_sources_keeps_the_single_feed_path()
	{
		$resolved = SBR_Trust_Badge_Builder::source_map_for_badge(array());

		$this->assertTrue($resolved['complete']);
		$this->assertSame(array(), $resolved['map']);
	}

	/**
	 * A selection past the source bound is incomplete, and costs no query.
	 *
	 * @return void
	 */
	public function test_a_selection_past_the_bound_is_incomplete()
	{
		$sources = array();

		for ($i = 0; $i <= \SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer::SOURCE_MAP_MAX_SOURCES; $i++) {
			$sources[] = 'source-' . $i;
		}

		$resolved = SBR_Trust_Badge_Builder::source_map_for_badge(array( 'sources' => $sources ));

		$this->assertFalse($resolved['complete'], 'Too many sources to resolve is a refusal, not a truncation.');
		$this->assertSame(array(), $resolved['map']);
	}
}
