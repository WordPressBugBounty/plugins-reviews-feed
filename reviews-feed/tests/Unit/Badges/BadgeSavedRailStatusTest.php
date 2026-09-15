<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * The saved-badge rails refuse a badge that is not published.
 *
 * SBR_Badge_Image::maybe_serve() already refuses one (SBR_Badge_Image.php:320)
 * because that route is anonymous. The shortcode, the block and the widget are
 * equally anonymous once rendered, and all three reach
 * SBR_Badge_Service::render_saved_shortcode() — the block calls it directly, the
 * widget through do_shortcode() — while Store::get() answers for any status by
 * design. Raised as an informational note on #525.
 *
 * WHY THIS IS NOT DRIVEN THROUGH THE RENDERER. The obvious test — seed a draft,
 * call the shortcode, assert '' — cannot fail: a badge with no resolvable source
 * renders '' anyway, so deleting the guard leaves it green. Making the published
 * case render needs a live aggregate, and get_aggregate_for_sources() is a public
 * static with no seam to stub. So the ordering is asserted instead, which is the
 * thing the mutation actually moves. Verified by deleting the guard: this file
 * goes red.
 */
class BadgeSavedRailStatusTest extends TestCase
{
	/** @var array<int, object>|null */
	private $posts_backup;

	/** @var array<int, array<string, mixed>>|null */
	private $meta_backup;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->posts_backup = isset($GLOBALS['wp_posts_mock']) ? $GLOBALS['wp_posts_mock'] : null;
		$this->meta_backup  = isset($GLOBALS['wp_postmeta_mock']) ? $GLOBALS['wp_postmeta_mock'] : null;
	}

	/**
	 * Restored, not cleared: another test in this process may have seeded these.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->posts_backup === null) {
			unset($GLOBALS['wp_posts_mock']);
		} else {
			$GLOBALS['wp_posts_mock'] = $this->posts_backup;
		}

		if ($this->meta_backup === null) {
			unset($GLOBALS['wp_postmeta_mock']);
		} else {
			$GLOBALS['wp_postmeta_mock'] = $this->meta_backup;
		}

		parent::tearDown();
	}

	/**
	 * @return string
	 */
	private function service_source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Service.php';

		$this->assertFileIsReadable($path);

		return (string) file_get_contents($path);
	}

	/**
	 * The guard exists, and it runs BEFORE the render.
	 *
	 * Position is the whole assertion. A status check placed after
	 * render_saved() would satisfy a plain grep and still hand a draft badge's
	 * markup to whoever asked for it.
	 *
	 * @return void
	 */
	public function test_the_status_check_runs_before_the_render()
	{
		// Scoped to the method body. The class docblock names render_saved()
		// forty lines earlier, and comparing against that offset made the first
		// version of this test fail on correct code.
		$source = $this->service_source();
		$start  = strpos($source, 'public function render_saved_shortcode(');
		$this->assertNotFalse($start, 'render_saved_shortcode() must exist');

		$end = strpos($source, "\n\tpublic function ", $start + 1);
		$body = $end === false ? substr($source, $start) : substr($source, $start, $end - $start);

		$guard = strpos($body, "\$badge['status'] !== 'publish'");
		$this->assertNotFalse($guard, 'the saved rails must refuse a non-published badge');

		$render = strpos($body, 'SBR_Trust_Badge_Builder::render_saved(');
		$this->assertNotFalse($render, 'render_saved() is the call the guard has to precede');

		$this->assertLessThan(
			$render,
			$guard,
			'the status check sits after the render, so a draft badge is still built'
		);
	}

	/**
	 * One guard, three rails.
	 *
	 * The block and the widget are covered only because they route through the
	 * shortcode body. If either ever grows its own Store::get() call, this test
	 * is the one that should start failing.
	 *
	 * @return void
	 */
	public function test_the_block_rail_still_shares_the_shortcode_body()
	{
		$this->assertStringContainsString(
			'$this->render_saved_shortcode(array( \'id\' => $id ))',
			$this->service_source(),
			'the block must keep sharing the shortcode body, or it needs its own status check'
		);

		$widget = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Widget.php';
		$this->assertFileIsReadable($widget);
		$this->assertStringContainsString(
			'[reviews-feed-badge id=',
			(string) file_get_contents($widget),
			'the widget must keep reaching the badge through the shortcode'
		);
	}

	/**
	 * The store keeps answering for a draft — the guard belongs to the RAIL.
	 *
	 * Behavioural, and it fails if someone pushes the check down into
	 * Store::get(): the admin list and the editor both need a draft back.
	 *
	 * @return void
	 */
	public function test_the_store_still_answers_for_a_draft()
	{
		$post                    = new \stdClass();
		$post->ID                = 4101;
		$post->post_type         = SBR_Trust_Badge_Store::POST_TYPE;
		$post->post_status       = 'draft';
		$post->post_title        = 'Status rail badge';
		$post->post_modified_gmt = '2026-09-15 00:00:00';

		$GLOBALS['wp_posts_mock'][4101] = $post;
		$GLOBALS['wp_postmeta_mock'][4101][ SBR_Trust_Badge_Store::META_SETTINGS ] = wp_json_encode(
			array( 'style' => 'card', 'theme' => 'light' )
		);

		$badge = SBR_Trust_Badge_Store::get(4101);

		$this->assertIsArray($badge, 'Store::get() answers for any status by design');
		$this->assertSame('draft', $badge['status']);
	}
}
