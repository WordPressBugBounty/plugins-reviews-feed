<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * The Free upsell must not frame an empty panel (SBR-163).
 *
 * `specimen_html()` returns '' when no feed resolves, and its own comment says the
 * screen then "keeps its heading and copy". The template contradicted that: it
 * emitted `.sb-single-collection-icon` unconditionally inside a
 * `grid-template-columns: 1fr 1fr` row, so a Free site with no feed yet — the
 * first-run case — rendered a 418x318 empty box beside the copy.
 *
 * Measured on demo-wp3 with 0 feeds and 0 sources: the slot contained 14 bytes of
 * whitespace, 0 children, no background image.
 *
 * Asserted on the template source because the method emits HTML and ends inside a
 * page render the unit shim cannot drive.
 */
class BadgeUpsellLayoutTest extends TestCase
{
	/**
	 * @return string
	 */
	private function source()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * The badge column is conditional on there being a badge to draw.
	 *
	 * @return void
	 */
	public function test_the_specimen_column_is_only_rendered_when_a_specimen_exists()
	{
		$src = $this->source();

		// Resolved once, before the grid opens — calling it twice would render the
		// badge twice, and the class on the grid depends on the same answer.
		//
		// upsell_specimen_html(), not specimen_html(): the site-specific one answers
		// '' when there is no feed, and this screen then lost its whole left column
		// on the sites it exists for. The gate below stays anyway — it costs nothing
		// and render() answering '' would still frame an empty box.
		$this->assertMatchesRegularExpression(
			'/\$specimen = self::upsell_specimen_html\(\);/',
			$src,
			'the specimen must be resolved before the grid is opened, and must not read the site'
		);

		$this->assertMatchesRegularExpression(
			'/if \(\$specimen !== \'\'\) :\s*\?>\s*<div class="sb-single-collection-icon/',
			$src,
			'the sized badge column must be gated on the specimen, or it frames an empty box'
		);

		// And the grid has to know, so it can collapse to one column.
		$this->assertMatchesRegularExpression(
			'/sb-single-collection-empty sb-fs<\?php echo \$specimen === \'\' \? \' sbr-tb-upsell-no-specimen\' : \'\'; \?>/',
			$src,
			'the grid needs a modifier class when there is no specimen'
		);
	}

	/**
	 * The helper the template replaced must be gone, not merely unused.
	 *
	 * @return void
	 */
	public function test_the_superseded_helper_is_removed()
	{
		$src = $this->source();

		$this->assertStringNotContainsString(
			'function render_upsell_specimen',
			$src,
			'a private method with no callers is dead code'
		);
	}

	/**
	 * The stylesheet must carry the single-column rule the class asks for — a class
	 * with no rule does nothing, silently (Rule 8.9).
	 *
	 * @return void
	 */
	public function test_the_modifier_class_has_a_rule_behind_it()
	{
		$sheet = dirname(__DIR__, 3) . '/vendor/smashballoon/customizer/sb-common/sb-customizer/src/assets/css/sb-common.css';

		// Asserted, not skipped. A skipped test reads as a pass in the summary, and
		// this is the half that catches a class with no rule behind it.
		$this->assertFileExists($sheet, 'the customizer source must be reachable through the vendor symlink');

		$css = (string) file_get_contents($sheet);

		$this->assertStringContainsString(
			'.sbr-trust-badges-upsell .sb-single-collection-empty.sbr-tb-upsell-no-specimen',
			$css,
			'the modifier class must be styled, or the grid stays two columns with one empty'
		);
		$this->assertMatchesRegularExpression(
			'/sbr-tb-upsell-no-specimen \{[^}]*grid-template-columns/s',
			$css,
			'the rule must actually change the column count'
		);
	}
}
