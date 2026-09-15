<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The logo row is not emitted when it would be empty.
 *
 * logos.php skips a provider twice: once when nothing bundles a mark for it, and
 * once when the reader hands back ''. Both are reachable — a source whose
 * platform has no artwork yet — and every provider in a badge can hit them.
 *
 * The wrapper used to be opened before the loop, so that produced
 * `<span class="sbr-tb-logos"></span>`. Three consequences, all visible:
 * card.php gates its left rule on this file producing output, so an empty row
 * defeated that guard and drew a rule with nothing beside it; chip and medal put
 * the row in a grid with `gap`, so an empty one costs a row of spacing.
 */
final class BadgeLogoRowEmptyTest extends TestCase
{
	/**
	 * @param array<string, mixed> $badge View data.
	 *
	 * @return string
	 */
	private function render(array $badge)
	{
		$sbr_badge = array_merge(
			array(
				'providers'      => array(),
				'logo_style'     => 'icon',
				'logos_attr'     => 'brand',
				'is_placeholder' => false,
			),
			$badge
		);

		ob_start();
		include dirname(__DIR__, 3) . '/templates/frontend/badges/logos.php';

		return (string) ob_get_clean();
	}

	/**
	 * @return void
	 */
	public function test_a_provider_with_no_bundled_mark_emits_no_row()
	{
		// Not a platform we ship artwork for, and the one the skip exists for.
		$html = $this->render(array( 'providers' => array( 'some-new-platform' ) ));

		$this->assertStringNotContainsString(
			'sbr-tb-logos',
			$html,
			'an empty logo row defeats card.php\'s left-rule guard and costs a grid row on chip and medal'
		);

		$this->assertSame('', trim($html));
	}

	/**
	 * Proves the case above is a real skip and not an empty input.
	 *
	 * @return void
	 */
	public function test_a_provider_with_a_mark_still_emits_the_row()
	{
		$html = $this->render(array( 'providers' => array( 'google' ) ));

		$this->assertStringContainsString('class="sbr-tb-logos"', $html);
		$this->assertStringContainsString('sbr-tb-provider-google', $html);
	}

	/**
	 * A mixed list keeps the row and drops only the unknown platform.
	 *
	 * @return void
	 */
	public function test_a_mixed_list_keeps_the_row_and_drops_the_unknown_one()
	{
		$html = $this->render(array( 'providers' => array( 'google', 'some-new-platform' ) ));

		$this->assertStringContainsString('class="sbr-tb-logos"', $html);
		$this->assertSame(1, substr_count($html, 'class="sbr-tb-provider'));
	}

	/**
	 * The placeholder row is a span, because card.php nests it inside one.
	 *
	 * @return void
	 */
	public function test_the_placeholder_row_is_a_span()
	{
		$html = $this->render(array( 'is_placeholder' => true ));

		$this->assertStringContainsString('sbr-tb-provider-placeholder', $html);

		$this->assertStringNotContainsString(
			'<div',
			$html,
			'card.php puts this row inside .sbr-tb-card-src, which is a span — a div there is invalid nesting'
		);
	}
}
