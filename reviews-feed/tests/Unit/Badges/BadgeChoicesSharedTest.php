<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Service;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Widget;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * The block picker and the widget picker must offer the same list.
 *
 * They each carried a copy of the same `list_badges()` loop and had already
 * drifted: a literal `100` against a named `CHOICE_LIMIT`, `Badge #%d` against
 * `Badge %d` — two translation strings for one concept — and only the widget
 * guarded `items` or recorded `total`. One implementation now lives on the
 * store; this pins that both callers use it and that neither grows a second.
 */
final class BadgeChoicesSharedTest extends TestCase
{
	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue,
			$wp_posts_mock, $wp_postmeta_mock;

		$this->saved_posts    = $wp_posts_mock;
		$this->saved_postmeta = $wp_postmeta_mock;

		$wp_query_calls       = array();
		$wp_query_found_queue = array();

		// Three exist, two come back on this page — so `total` and the choice count
		// are different numbers and the assertions below can tell them apart.
		$wp_query_found = 3;
		// Every field get() reads. A partial row raises "Undefined property" and
		// phpunit.xml sets failOnWarning, so the fixture has to be complete —
		// which is the same lesson as the template defaults, one layer up.
		$row = static function ($id, $title) {
			return (object) array(
				'ID'                => $id,
				'post_type'         => 'sbr_trust_badge',
				'post_title'        => $title,
				'post_status'       => 'publish',
				'post_modified_gmt' => '2026-09-10 00:00:00',
			);
		};

		$wp_query_posts = array( $row(7, 'Homepage badge'), $row(9, '') );

		// list_badges() hydrates each row through get(), which reads the post and
		// its settings meta — seeded here or every item is dropped and the test
		// compares two empty lists.
		$wp_posts_mock = array(
			7 => $wp_query_posts[0],
			9 => $wp_query_posts[1],
		);

		$wp_postmeta_mock = array(
			7 => array( '_sbr_badge_settings' => wp_json_encode(array( 'style' => 'chip' )) ),
			9 => array( '_sbr_badge_settings' => wp_json_encode(array( 'style' => 'chip' )) ),
		);
	}

	/** @var mixed */
	private $saved_posts;

	/** @var mixed */
	private $saved_postmeta;

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $wp_query_calls, $wp_query_posts, $wp_query_found, $wp_query_found_queue,
			$wp_posts_mock, $wp_postmeta_mock;

		$wp_query_calls       = array();
		$wp_query_posts       = array();
		$wp_query_found       = 0;
		$wp_query_found_queue = array();

		// Restored, not blanked: these are process-wide and other classes seed them.
		$wp_posts_mock    = $this->saved_posts;
		$wp_postmeta_mock = $this->saved_postmeta;

		parent::tearDown();
	}

	/**
	 * @param string $class Class to reach into.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	private function choicesOf($class)
	{
		$method = new ReflectionMethod($class, 'badge_choices');
		$method->setAccessible(true);

		return (array) $method->invoke(null);
	}

	/**
	 * @return void
	 */
	public function test_both_pickers_answer_identically()
	{
		$block  = $this->choicesOf(SBR_Badge_Service::class);
		$widget = $this->choicesOf(SBR_Badge_Widget::class);

		// Prove there is something to compare, or two empty arrays would agree.
		$this->assertNotSame(array(), $block, 'the fixture must produce choices');

		$this->assertSame($block, $widget, 'the two pickers must offer the same list');
	}

	/**
	 * @return void
	 */
	public function test_an_untitled_badge_uses_one_label_for_both()
	{
		$block  = $this->choicesOf(SBR_Badge_Service::class);
		$widget = $this->choicesOf(SBR_Badge_Widget::class);

		$untitled = static function (array $choices) {
			foreach ($choices as $choice) {
				if ((int) $choice['id'] === 9) {
					return (string) $choice['name'];
				}
			}

			return '';
		};

		$label = $untitled($block);

		$this->assertNotSame('', $label, 'the untitled badge must still be selectable');
		$this->assertSame($label, $untitled($widget), 'one string, not one per caller');
	}

	/**
	 * @return void
	 */
	public function test_the_cap_is_one_number()
	{
		$this->assertSame(
			SBR_Trust_Badge_Store::CHOICE_LIMIT,
			SBR_Badge_Widget::CHOICE_LIMIT,
			'the widget must not carry a second ceiling'
		);

		// And the shared helper asks for exactly that.
		global $wp_query_calls;

		$wp_query_calls = array();

		SBR_Trust_Badge_Store::choices();

		$this->assertSame(
			SBR_Trust_Badge_Store::CHOICE_LIMIT,
			$wp_query_calls[0]['posts_per_page'] ?? null,
			'choices() must ask the store for its own ceiling'
		);
	}

	/**
	 * `total` is what EXISTS, so a caller above the cap can say what is hidden.
	 *
	 * @return void
	 */
	public function test_the_total_is_the_number_that_exists()
	{
		$offered = SBR_Trust_Badge_Store::choices();

		$this->assertSame(3, $offered['total'], 'total must be the full count, not the page length');
		$this->assertCount(2, $offered['choices']);
	}
}
