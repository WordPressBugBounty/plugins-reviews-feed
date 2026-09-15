<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * record_email_embed() / clear_email_embed() report state, not rows (SBR-163).
 *
 * WP answers false for "nothing to change" as well as for failure: update_metadata()
 * returns early when the stored value already equals the new one, delete_metadata()
 * returns false when there was no row. Both are the idempotent cases, and returning
 * them raw made ajax_email_embed() reply 400 while the server state was correct.
 *
 * The bootstrap's post-meta shims reproduce those two return values, so a change that
 * went back to returning the raw result fails here.
 */
class BadgeEmailEmbedStateTest extends TestCase
{
	/**
	 * A stored badge, plus one post of another type to prove the type check.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $wp_posts_mock, $wp_postmeta_mock;

		// Every field get() reads, not just post_type — a partial fixture raises
		// undefined-property warnings from inside the code under test.
		$badge                    = new \stdClass();
		$badge->post_type         = SBR_Trust_Badge_Store::POST_TYPE;
		$badge->post_title        = 'QA badge';
		$badge->post_status       = 'publish';
		$badge->post_modified_gmt = '2026-08-18 09:00:00';

		$other                    = new \stdClass();
		$other->post_type         = 'post';
		$other->post_title        = 'A post';
		$other->post_status       = 'publish';
		$other->post_modified_gmt = '2026-08-18 09:00:00';

		$wp_posts_mock    = array( 7 => $badge, 9 => $other );
		$wp_postmeta_mock = array();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $wp_posts_mock, $wp_postmeta_mock;

		$wp_posts_mock    = array();
		$wp_postmeta_mock = array();
	}

	/**
	 * @return void
	 */
	public function test_recording_an_embed_reports_success_and_stores_a_timestamp()
	{
		$this->assertTrue(SBR_Trust_Badge_Store::record_email_embed(7));
		$this->assertGreaterThan(0, SBR_Trust_Badge_Store::get_email_embed(7));
	}

	/**
	 * The case that answered 400 while the state was right: the second call writes
	 * the same second, so update_post_meta() has nothing to change.
	 *
	 * @return void
	 */
	public function test_recording_twice_in_the_same_second_still_reports_success()
	{
		SBR_Trust_Badge_Store::record_email_embed(7);

		$this->assertTrue(SBR_Trust_Badge_Store::record_email_embed(7));
	}

	/**
	 * A write that genuinely fails must NOT be reported as success just because some
	 * older timestamp is sitting there — the client would show a stale date. Simulated
	 * by leaving a value the write cannot match.
	 *
	 * @return void
	 */
	public function test_a_stale_timestamp_is_not_reported_as_a_fresh_record()
	{
		global $wp_postmeta_mock, $wp_postmeta_fail_writes;

		$wp_postmeta_mock[7][ SBR_Trust_Badge_Store::META_EMAIL_EMBED ] = 1;
		$wp_postmeta_fail_writes = true;

		try {
			$this->assertFalse(
				SBR_Trust_Badge_Store::record_email_embed(7),
				'A failed write over an existing timestamp must not report success.'
			);
			$this->assertSame(1, SBR_Trust_Badge_Store::get_email_embed(7), 'The old value is still there.');
		} finally {
			$wp_postmeta_fail_writes = false;
		}
	}

	/**
	 * The other 400: removing an embed from a badge that has none. The end state is
	 * what the caller asked for.
	 *
	 * @return void
	 */
	public function test_clearing_an_embed_that_was_never_recorded_reports_success()
	{
		$this->assertTrue(SBR_Trust_Badge_Store::clear_email_embed(7));
		$this->assertSame(0, SBR_Trust_Badge_Store::get_email_embed(7));
	}

	/**
	 * @return void
	 */
	public function test_clearing_a_recorded_embed_removes_it()
	{
		SBR_Trust_Badge_Store::record_email_embed(7);

		$this->assertTrue(SBR_Trust_Badge_Store::clear_email_embed(7));
		$this->assertSame(0, SBR_Trust_Badge_Store::get_email_embed(7));
	}

	/**
	 * Both methods validate existence the same way. Without it, clear would answer
	 * success for any id, because get_email_embed() reads 0 for a post that is not
	 * there — and ajax_email_embed() would return 200 for a deleted badge.
	 *
	 * @dataProvider unknownIdProvider
	 *
	 * @param int    $id   Id that names no badge.
	 * @param string $why  What the id stands for.
	 *
	 * @return void
	 */
	public function test_neither_method_accepts_an_id_that_is_not_a_badge($id, $why)
	{
		$this->assertFalse(SBR_Trust_Badge_Store::record_email_embed($id), 'record: ' . $why);
		$this->assertFalse(SBR_Trust_Badge_Store::clear_email_embed($id), 'clear: ' . $why);
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function unknownIdProvider()
	{
		return array(
			'zero'            => array( 0, 'no id at all' ),
			'negative'        => array( -3, 'below the guard' ),
			'deleted badge'   => array( 4242, 'no such post' ),
			'another type'    => array( 9, 'a post, not a badge' ),
		);
	}
}
