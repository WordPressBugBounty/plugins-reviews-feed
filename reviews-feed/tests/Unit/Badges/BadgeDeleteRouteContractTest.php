<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder;

/**
 * What `wp_ajax_sbr_trust_badge_delete` accepts and returns (SBR-163).
 *
 * Drives the route's OWN methods. An earlier version of this file re-implemented
 * the parser privately and asserted against that copy, which would have stayed
 * green through every drift it exists to catch — so the parsing and the payload
 * are their own methods now and this calls them.
 *
 * Two things had already gone wrong here:
 *
 *  1. The client posts `ids` through SbUtils.ajaxPost, which puts everything
 *     through FormData.append() and therefore stringifies an array. The handler
 *     tested is_array() and answered 400, so the row disappeared from the screen
 *     while the badge stayed in the database.
 *  2. The success payload dropped `id`. The customizer is versioned separately,
 *     so an installed bundle still reads it — removing a key from a response
 *     this repo cannot update in the same commit is a break with no shim.
 */
class BadgeDeleteRouteContractTest extends TestCase
{
	public function test_accepts_the_comma_string_formdata_actually_sends()
	{
		// The shape that broke it: FormData.append( 'ids', [ 12, 13 ] ).
		$this->assertSame(array( 12, 13 ), SBR_Trust_Badge_Builder::parse_delete_ids('12,13'));
		$this->assertSame(array( 12 ), SBR_Trust_Badge_Builder::parse_delete_ids('12'));
	}

	public function test_accepts_a_json_string()
	{
		$this->assertSame(array( 12, 13 ), SBR_Trust_Badge_Builder::parse_delete_ids('[12,13]'));
	}

	public function test_accepts_a_real_array()
	{
		$this->assertSame(array( 12, 13 ), SBR_Trust_Badge_Builder::parse_delete_ids(array( '12', 13 )));
	}

	public function test_single_id_is_still_accepted()
	{
		$this->assertSame(array( 12 ), SBR_Trust_Badge_Builder::parse_delete_ids('', 12));
		$this->assertSame(array( 12 ), SBR_Trust_Badge_Builder::parse_delete_ids('', '12'));
	}

	public function test_drops_empties_and_non_ids_rather_than_deleting_zero()
	{
		$this->assertSame(array( 12 ), SBR_Trust_Badge_Builder::parse_delete_ids('12,,0'));
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids(''));
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids('abc'));
	}

	public function test_deduplicates()
	{
		$this->assertSame(array( 12, 13 ), SBR_Trust_Badge_Builder::parse_delete_ids('12,13,12'));
	}

	/**
	 * A nested value must not become badge #1.
	 *
	 * absint() of an array is 1 on PHP 8, so `ids[0][]=9`, `ids=[[9]]` and
	 * `ids={"a":1}` would each have deleted badge number one and reported
	 * success. The single-id path was guarded and the list path was not — one
	 * line apart, same hazard.
	 *
	 * @return void
	 */
	public function test_nested_values_are_dropped_not_cast_to_one()
	{
		$this->assertSame(1, absint(array( 'nested' )), 'The hazard this guard exists for.');

		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids(array( array( 9 ) )));
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids('[[9]]'));
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids('', array( 'nested' )));

		// A JSON OBJECT, which this docblock used to claim was covered while
		// asserting only the nested-array cases. It decodes to array('a' => 1),
		// whose element is scalar and sails past the element guard — so the
		// shape has to be rejected before it, not after.
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids('{"a":1}'));
		$this->assertSame(array(), SBR_Trust_Badge_Builder::parse_delete_ids('{"0":1,"2":3}'));

		// A good id alongside a bad one keeps the good one and drops the bad.
		$this->assertSame(array( 9 ), SBR_Trust_Badge_Builder::parse_delete_ids(array( 9, array( 5 ) )));
	}

	/**
	 * `id` is part of the response contract, not decoration.
	 *
	 * @return void
	 */
	public function test_response_keeps_the_id_key_for_the_shipped_customizer()
	{
		$response = SBR_Trust_Badge_Builder::delete_response(
			array( 12, 13 ),
			array( 'items' => array(), 'total' => 4 )
		);

		$this->assertArrayHasKey('id', $response);
		$this->assertSame(12, $response['id'], 'An installed customizer bundle reads data.id.');

		$this->assertSame(array( 12, 13 ), $response['ids']);
		$this->assertSame(2, $response['deletedCount']);
		$this->assertSame(4, $response['badgesCount']);
	}
}
