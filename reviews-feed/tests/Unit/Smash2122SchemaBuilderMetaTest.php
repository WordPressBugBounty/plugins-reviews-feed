<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

if (! class_exists(\WP_Post::class, false)) {
	require_once dirname(__DIR__) . '/Support/WpPostStub.php';
}

/**
 * SMASH-2122: a feed placed inside a page builder that stores its layout in postmeta (SiteOrigin `panels_data`,
 * Beaver `_fl_builder_data`, Bricks `_bricks_page_content_2`) rendered fine but emitted no schema, because the
 * placement haystack read only post_content and `_elementor_data`.
 */
final class Smash2122SchemaBuilderMetaTest extends TestCase
{
	/** @var SBR_Schema_Service */
	private $service;
	/** @var mixed */
	private $previous_meta;

	protected function setUp(): void
	{
		parent::setUp();
		global $wp_postmeta_mock;
		$this->previous_meta = $wp_postmeta_mock;
		$wp_postmeta_mock    = [];
		$this->service       = new SBR_Schema_Service();
	}

	protected function tearDown(): void
	{
		global $wp_postmeta_mock;
		$wp_postmeta_mock = $this->previous_meta;
		parent::tearDown();
	}

	/** @return int[] the feed ids the page would emit schema for */
	private function detected(string $post_content, array $meta): array
	{
		global $wp_postmeta_mock;
		$wp_postmeta_mock = [ 7 => $meta ];
		$post             = new \WP_Post((object) [ 'ID' => 7, 'post_content' => $post_content ]);
		$method           = new \ReflectionMethod(SBR_Schema_Service::class, 'placement_feed_ids');
		$method->setAccessible(true);
		/** @var int[] $ids */
		$ids = $method->invoke($this->service, $post);
		return $ids;
	}

	public function test_siteorigin_widget_with_the_shortcode(): void
	{
		$panels = [ 'widgets' => [ [ 'text' => '<p>[reviews-feed feed="1"]</p>', 'panels_info' => [ 'class' => 'SiteOrigin_Widget_Editor_Widget' ] ] ] ];
		$this->assertSame([ 1 ], $this->detected('', [ 'panels_data' => $panels ]));
	}

	public function test_beaver_builder_module(): void
	{
		$nodes = [ 'n1' => (object) [ 'type' => 'module', 'settings' => (object) [ 'text' => '[reviews-feed feed=4]' ] ] ];
		$this->assertSame([ 4 ], $this->detected('', [ '_fl_builder_data' => $nodes ]));
	}

	public function test_bricks_element(): void
	{
		$els = [ [ 'name' => 'shortcode', 'settings' => [ 'shortcode' => "[reviews-feed feed='9']" ] ] ];
		$this->assertSame([ 9 ], $this->detected('', [ '_bricks_page_content_2' => $els ]));
	}

	public function test_two_feeds_in_one_builder_page_each_count(): void
	{
		$panels = [ 'widgets' => [ [ 'text' => '[reviews-feed feed=2]' ], [ 'text' => '[reviews-feed feed=3]' ] ] ];
		$this->assertSame([ 2, 3 ], $this->detected('', [ 'panels_data' => $panels ]));
	}

	public function test_rendered_feed_html_copied_into_post_content(): void
	{
		// Copied from templates/frontend/pro/feed.php output, as rendered on DDEV (feed 495 -> 5 here).
		$rendered = '<section id="sb-reviews-container-5" class="sbr-feed" aria-label="Customer reviews">'
			. '<section class="sb-feed-container sb-layout-grid" data-layout="grid" data-gutter="20" data-post-style="regular"'
			. ' data-shortcode-atts="{&quot;feed&quot;:&quot;5&quot;}" data-feed-id="5"></section></section>';
		$this->assertSame([ 5 ], $this->detected($rendered, []));
	}

	public function test_a_builder_page_without_a_feed_emits_nothing(): void
	{
		$panels = [ 'widgets' => [ [ 'text' => 'Our feed=99 menu is great' ] ] ];
		$this->assertSame([], $this->detected('', [ 'panels_data' => $panels ]));
	}

	/** BC: the placements that already worked keep working through the same haystack. */
	public function test_post_content_and_elementor_still_detected(): void
	{
		$elementor = '[{"elType":"widget","widgetType":"sb-reviews-feed","settings":{"feed_id":"183"}}]';
		$this->assertSame([ 12, 183 ], $this->detected('[reviews-feed feed=12]', [ '_elementor_data' => $elementor ]));
	}

	/** Every Smash Balloon Elementor widget shares the `feed_id` control; only ours is a Reviews placement. */
	public function test_another_smash_balloon_elementor_widget_is_not_a_reviews_feed(): void
	{
		$elementor = '[{"elType":"widget","settings":{"feed_id":"2"},"widgetType":"sb-instagram-feed"},'
			. '{"elType":"widget","settings":{"feed_id":"183"},"widgetType":"sb-reviews-feed"}]';
		$this->assertSame([ 183 ], $this->detected('', [ '_elementor_data' => $elementor ]));
	}

	public function test_another_plugins_shortcode_in_a_builder_widget_is_not_a_reviews_feed(): void
	{
		$panels = [ 'widgets' => [ [ 'text' => '[instagram-feed feed=2]' ], [ 'text' => '[reviews-feed feed=3]' ] ] ];
		$this->assertSame([ 3 ], $this->detected('', [ 'panels_data' => $panels ]));
	}

	/** BC: the page setup() reads in 2.15.0 — every placement form, content and Elementor meta — gives the same ids. */
	public function test_every_placement_form_on_one_page_is_unchanged(): void
	{
		$content   = '[reviews-feed feed=3]'
			. '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\\"7\\""} /-->'
			. '<!-- wp:smashballoon/reviews-feed {"feedId":"41"} /-->';
		$elementor = '[{"elType":"widget","widgetType":"sb-reviews-feed","settings":{"feed_id":"183"}}]';
		$this->assertSame([ 3, 7, 41, 183 ], $this->detected($content, [ '_elementor_data' => $elementor ]));
	}
}
