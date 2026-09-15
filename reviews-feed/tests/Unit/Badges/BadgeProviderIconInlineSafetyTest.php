<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * Every provider icon must be safe to drop straight into an HTML page.
 *
 * DisplayElements::get_provider_icon() file_get_contents() the SVG and the badge
 * template echoes it inline. That is a stricter contract than "valid SVG file",
 * and two shipped icons broke it:
 *
 *   - facebook-provider.svg was a raw CorelDRAW export: an XML prolog and a
 *     `<!DOCTYPE svg …>` (both parse errors inside a body), width/height in
 *     INCHES — 14.2222in is 1365px — and its two fills lived in a
 *     `<style><![CDATA[ .fil0 … ]]></style>` block. Inlined, those class names
 *     become global: another icon defining `.fil0`, or a build that tree-shakes
 *     unreferenced classes, leaves both paths with no fill and the logo
 *     invisible. Reported from production as "logos not loading".
 *   - zomato-provider.svg carried a prolog and `720px` dimensions.
 *
 * The trap this guards is that both files opened fine in a browser and in a
 * viewer. Only inlining them breaks, and only on the page.
 */
class BadgeProviderIconInlineSafetyTest extends TestCase
{
	/**
	 * @return string
	 */
	private function dir()
	{
		return dirname(__DIR__, 3) . '/assets/icons';
	}

	/**
	 * @return array<int, string>
	 */
	private function icons()
	{
		return (array) glob($this->dir() . '/*.svg');
	}

	/**
	 * @return void
	 */
	public function test_the_icons_ship()
	{
		$icons = $this->icons();

		// Without this every loop below passes on an empty directory.
		$this->assertGreaterThan(30, count($icons), 'suspiciously few icons — 14 providers with variants');
	}

	/**
	 * @return void
	 */
	public function test_no_icon_carries_an_xml_prolog_or_doctype()
	{
		$bad = array();

		foreach ($this->icons() as $file) {
			$svg = (string) file_get_contents($file);

			if (strpos($svg, '<?xml') !== false || stripos($svg, '<!DOCTYPE') !== false) {
				$bad[] = basename($file);
			}
		}

		$this->assertSame(
			array(),
			$bad,
			'an XML prolog or DOCTYPE inside an HTML body is a parse error, so these icons '
			. 'break the markup around them: ' . implode(', ', $bad)
		);
	}

	/**
	 * @return void
	 */
	public function test_no_icon_depends_on_a_style_block_for_its_fills()
	{
		$bad = array();

		foreach ($this->icons() as $file) {
			$svg = (string) file_get_contents($file);

			// A <style> block inside an inlined SVG is page-global, and generic
			// class names (Corel's .filN, Illustrator's .stN) collide across
			// icons. Fills belong on the shapes.
			if (preg_match('/<style[\s>]/i', $svg) || preg_match('/class="(fil|st|cls)\d/i', $svg)) {
				$bad[] = basename($file);
			}
		}

		$this->assertSame(
			array(),
			$bad,
			'these icons colour themselves through a shared <style> block, so another icon or a '
			. 'CSS build can leave them unfilled and invisible: ' . implode(', ', $bad)
		);
	}

	/**
	 * @return void
	 */
	public function test_no_icon_declares_an_absolute_physical_size()
	{
		$bad = array();

		foreach ($this->icons() as $file) {
			$svg = (string) file_get_contents($file);

			if (! preg_match('/<svg[^>]*>/i', $svg, $tag)) {
				$bad[] = basename($file) . ' (no <svg> element)';
				continue;
			}

			// in / cm / mm / pt / pc cannot be scaled down by the badge CSS the
			// way a unitless viewBox can, and one of them shipped at 1365px.
			if (preg_match('/\b(?:width|height)="[\d.]+(in|cm|mm|pt|pc)"/i', $tag[0], $m)) {
				$bad[] = basename($file) . ' (' . $m[1] . ')';
			}
		}

		$this->assertSame(
			array(),
			$bad,
			'physical units do not scale with the badge: ' . implode(', ', $bad)
		);
	}

	/**
	 * @return void
	 */
	public function test_every_icon_can_actually_paint()
	{
		$bad = array();

		foreach ($this->icons() as $file) {
			$svg = (string) file_get_contents($file);

			if (strpos($svg, '<svg') === false) {
				$bad[] = basename($file) . ' (not an svg)';
				continue;
			}

			// A viewBox is what lets the CSS size it. Every clean icon here has one.
			if (stripos($svg, 'viewbox') === false) {
				$bad[] = basename($file) . ' (no viewBox)';
				continue;
			}

			// Mono variants are tinted by the renderer via currentColor or a fill
			// it sets, so they are allowed to carry no fill of their own.
			if (strpos(basename($file), '-mono') !== false) {
				continue;
			}

			if (! preg_match('/fill\s*[:=]/i', $svg)) {
				$bad[] = basename($file) . ' (nothing to paint with)';
			}
		}

		$this->assertSame(array(), $bad, 'these would render blank: ' . implode(', ', $bad));
	}
}
