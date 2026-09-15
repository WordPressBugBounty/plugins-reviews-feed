<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\FeedDisplay;

/**
 * SMASH-2084 — on Free, "Average Review Rating" was reported on and drew nothing.
 *
 * `headerContent` defaults to ['heading', 'button', 'averagereview'] in
 * class/sbr-functions.php:104 and in all six template presets, and those defaults
 * are in Common, so a Free install gets them. `should_show()` is data-driven off
 * that array with no tier check, so it returned true — while
 * templates/frontend/lite/header.php has no markup for a rating at all, and the Pro
 * header is excluded from the Free zip by build/reviews-feed/filter:31.
 *
 * The gate belongs in THIS class and nowhere else: Pro\FeedDisplay extends it and
 * overrides should_show() outright (class/Pro/FeedDisplay.php:50), and the two tiers
 * reach it through different services — Common\Services\ShortcodeService on Free,
 * Pro\Services\ShortcodeService on Pro. So a change here cannot reach a Pro site,
 * which is what keeps the ticket's "no appearance change on either tier" true
 * without touching a single stored setting.
 */
final class Smash2084FreeHeaderRatingTest extends TestCase
{
	/**
	 * A FeedDisplay whose feed reports $settings. Reflection rather than the real
	 * constructor, which needs a Feed, a Parser and get_option().
	 *
	 * @param array<string,mixed> $settings
	 */
	private function display(array $settings): FeedDisplay
	{
		$feed = new class ($settings) {
			/** @var array<string,mixed> */
			private $settings;
			/** @param array<string,mixed> $settings */
			public function __construct(array $settings)
			{
				$this->settings = $settings;
			}
			/** @return array<string,mixed> */
			public function get_settings(): array
			{
				return $this->settings;
			}
		};

		$display = (new \ReflectionClass(FeedDisplay::class))->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty(FeedDisplay::class, 'feed');
		$prop->setAccessible(true);
		$prop->setValue($display, $feed);

		return $display;
	}

	/** The stored defaults a Free install actually ships with. */
	private function defaults(): array
	{
		return [
			'showHeader'    => true,
			'headerContent' => ['heading', 'button', 'averagereview'],
			'authorContent' => ['name'],
		];
	}

	public function test_free_does_not_report_a_header_rating_it_cannot_draw(): void
	{
		// The defect: `averagereview` is in the stored array and the Free header has
		// no markup for it, so reporting true described a rating nobody could see.
		$d = $this->display($this->defaults());

		$this->assertTrue(
			in_array('averagereview', $this->defaults()['headerContent'], true),
			'guard: if the default stops carrying averagereview this test proves nothing'
		);
		$this->assertFalse(
			$d->should_show('header', 'averagereview'),
			'Free renders no header rating, so it must not report one'
		);
	}

	public function test_the_rest_of_the_free_header_is_untouched(): void
	{
		// The gate is one key wide. Everything the Free template DOES draw keeps
		// answering from the stored settings, so no feed changes appearance.
		$d = $this->display($this->defaults());

		$this->assertTrue($d->should_show('header'), 'the header itself still shows');
		$this->assertTrue($d->should_show('header', 'heading'));
		$this->assertTrue($d->should_show('header', 'button'));
		$this->assertTrue($d->should_show('author', 'name'));
		$this->assertFalse($d->should_show('header', 'nonsense'));
	}

	public function test_a_disabled_header_still_wins_over_the_gate(): void
	{
		// Order matters: the whole-header switch is read before any item, so the
		// new branch cannot change what an emptied `showHeader` means.
		$settings = $this->defaults();
		$settings['showHeader'] = false;

		$this->assertFalse($this->display($settings)->should_show('header'));
	}

	public function test_pro_keeps_its_own_answer(): void
	{
		// The other side of the gate. Pro\FeedDisplay overrides should_show() rather
		// than calling parent::, so Free's branch is unreachable there — asserted on
		// the source, because loading the Pro class needs the Pro build's constants.
		$root = dirname(__DIR__, 2);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local class file on disk, not a remote URL
		$pro = (string) file_get_contents($root . '/class/Pro/FeedDisplay.php');

		$this->assertMatchesRegularExpression(
			'/public function should_show\s*\(/',
			$pro,
			'Pro must keep its own should_show — inheriting Free\'s would drop its header rating'
		);
		$this->assertStringNotContainsString(
			'parent::should_show',
			$pro,
			'if Pro ever delegates upward, this gate starts applying to Pro and the ticket reopens'
		);
	}
}
