<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Admin\Blocks\SBR_Modern_Feed_Block;

/**
 * SMASH-2257 — the block editor localized full feed rows (settings, sourcesList with each
 * source's access_token/info) as sbReviewsFeedBlock.feeds to anyone who can open the
 * editor, Contributors included. Its only reader, sb-feed-blocks.js, maps feeds[].id and
 * feeds[].feed_name into the picker.
 */
final class Smash2257BlockEditorFeedPayloadTest extends TestCase
{
	/**
	 * @param mixed $feeds
	 *
	 * @return array<string, mixed>
	 */
	private function localize($feeds): array
	{
		// Same guarded shims as Smash2173ElementorPayloadEditorOnlyTest, for a run of this file alone.
		if (! function_exists('admin_url')) {
			eval('function admin_url($path = "") { return "https://example.test/wp-admin/" . $path; }');
		}
		if (! function_exists('wp_create_nonce')) {
			eval('function wp_create_nonce($action = -1) { return "nonce-" . $action; }');
		}
		$method = new \ReflectionMethod(SBR_Modern_Feed_Block::class, 'get_editor_localize_data');
		$method->setAccessible(true);

		return $method->invoke(new SBR_Modern_Feed_Block(), $feeds);
	}

	public function test_feed_rows_reach_the_editor_as_id_and_name_only(): void
	{
		$data = $this->localize(array(
			array(
				'id'          => 3,
				'feed_name'   => 'Homepage',
				'settings'    => array('sources' => array('ChIJ1')),
				'sourcesList' => array(array('account_id' => 'ChIJ1', 'access_token' => 'tok', 'info' => '{}')),
				'feed_style'  => '.sb{}',
			),
		));

		$this->assertSame(array(array('id' => 3, 'feed_name' => 'Homepage')), $data['feeds']);
		$this->assertStringNotContainsString('access_token', (string) json_encode($data));
	}

	public function test_no_feeds_is_an_empty_list(): void
	{
		$this->assertSame(array(), $this->localize(array())['feeds']);
		$this->assertSame(array(), $this->localize(false)['feeds']);
	}

	/** Rule 6: the localized object keeps the keys the block script reads. */
	public function test_payload_keeps_its_keys(): void
	{
		$data = $this->localize(array(array('id' => 1, 'feed_name' => 'A')));

		$this->assertSame(array('feeds', 'feed_url', 'nonce'), array_keys($data));
		$this->assertIsString($data['feed_url']);
		$this->assertIsString($data['nonce']);
	}
}
