<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * A badge placed by a page builder counts as placed (SBR-163).
 *
 * `get_instances()` scanned `post_content` only. Page builders do not write there —
 * Elementor keeps its whole layout in `_elementor_data` — so a badge dropped into an
 * Elementor shortcode widget read as "Used in 0 places", and the delete dialog, which
 * warns "anywhere you have placed it will stop", told the author it was safe to
 * remove. Found by placing a badge on a real Elementor page and watching the list
 * report one placement while the badge was live on two.
 *
 * This drives the matcher rather than the query: the query is a LIKE that narrows,
 * and the deciding step is whether a decoded blob really names THIS id. The escaping
 * is the part that bites — the stored JSON writes `id=\"12\"`, which the shortcode
 * regex cannot match against the raw blob.
 */
class BadgeBuilderInstanceTest extends TestCase
{
	/**
	 * @param string $raw
	 * @param int    $id
	 * @return bool
	 */
	protected function places($raw, $id)
	{
		$m = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'meta_places_badge');
		$m->setAccessible(true);

		return (bool) $m->invoke(null, $raw, $id);
	}

	/**
	 * The real shape, copied from a live page rather than invented.
	 *
	 * @param int|string $id
	 * @return string
	 */
	protected function elementor_blob($id)
	{
		return '[{"id":"c1","elType":"container","settings":[],"elements":['
			. '{"id":"h1","elType":"widget","widgetType":"heading","settings":{"title":"Trusted"},"elements":[]},'
			. '{"id":"b1","elType":"widget","widgetType":"shortcode","settings":'
			. '{"shortcode":"[reviews-feed-badge id=\\"' . $id . '\\"]"},"elements":[]}]}]';
	}

	/**
	 * @return void
	 */
	public function test_a_badge_in_an_elementor_shortcode_widget_counts()
	{
		$this->assertTrue($this->places($this->elementor_blob(1387), 1387));
	}

	/**
	 * The bug the shortcode regex has guarded against since it was written, now on
	 * the builder path too: badge 12 must not match id="120".
	 *
	 * @return void
	 */
	public function test_a_longer_id_is_not_a_match()
	{
		$this->assertFalse($this->places($this->elementor_blob(120), 12));
		$this->assertTrue($this->places($this->elementor_blob(120), 120));
	}

	/**
	 * @return void
	 */
	public function test_another_badge_is_not_a_match()
	{
		$this->assertFalse($this->places($this->elementor_blob(9), 1387));
	}

	/**
	 * Depth matters: a builder nests widgets several levels down, and a flat scan of
	 * the top level reports a real placement as nowhere.
	 *
	 * @return void
	 */
	public function test_it_finds_a_shortcode_nested_several_levels_deep()
	{
		$deep = '{"a":{"b":{"c":[{"d":{"shortcode":"[reviews-feed-badge id=\\"77\\"]"}}]}}}';

		$this->assertTrue($this->places($deep, 77));
	}

	/**
	 * A builder is free to store markup rather than JSON.
	 *
	 * @return void
	 */
	public function test_a_non_json_blob_falls_back_to_the_content_matcher()
	{
		$this->assertTrue($this->places('<div>[reviews-feed-badge id="5"]</div>', 5));
		$this->assertFalse($this->places('<div>nothing here</div>', 5));
	}

	/**
	 * @return void
	 */
	public function test_an_empty_or_useless_blob_is_not_a_match()
	{
		$this->assertFalse($this->places('', 1387));
		$this->assertFalse($this->places('null', 1387));
		$this->assertFalse($this->places('[]', 1387));
	}

	/**
	 * Duplicating repeatedly numbers the copy instead of appending forever.
	 *
	 * Eleven duplications produced "New Trust Badge (copy) (copy) (copy) (copy)
	 * (copy) (copy) (copy) (copy) (copy) (copy) (copy)", which wrapped a list row to
	 * three lines and made the block editor's badge <select> unreadable. Noticed in
	 * that dropdown, not in the list.
	 *
	 * @return void
	 */
	public function test_copy_names_are_numbered_rather_than_repeated()
	{
		$next = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'next_copy_name');
		$next->setAccessible(true);

		$name  = 'My Badge';
		$names = array();

		for ($i = 0; $i < 5; $i++) {
			$name    = (string) $next->invoke(null, $name);
			$names[] = $name;
		}

		$this->assertSame(
			array(
				'My Badge (copy)',
				'My Badge (copy 2)',
				'My Badge (copy 3)',
				'My Badge (copy 4)',
				'My Badge (copy 5)',
			),
			$names
		);

		// The suffix never accumulates, whatever the depth.
		foreach ($names as $candidate) {
			$this->assertSame(
				1,
				preg_match_all('/\(copy/', $candidate),
				"a copy name must carry exactly one suffix: {$candidate}"
			);
		}
	}

	/**
	 * An ordinary bracket at the end of a name is not a copy suffix.
	 *
	 * @return void
	 */
	public function test_a_normal_bracketed_name_is_not_mistaken_for_a_copy()
	{
		$next = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'next_copy_name');
		$next->setAccessible(true);

		$this->assertSame('Badge (EU) (copy)', $next->invoke(null, 'Badge (EU)'));
		$this->assertSame('Badge (copy 100)', $next->invoke(null, 'Badge (copy 99)'));
	}

	/**
	 * A translation that loses a placeholder must not make the copy suffix read a
	 * capture group that is not there.
	 *
	 * `next_copy_name` builds its patterns out of the SAME translatable templates it
	 * writes back, so a .po file whose "%1$s (copy %2$d)" came back as just "(kopie)"
	 * yields a pattern with fewer groups than the code reads. Reading $m[2] there is
	 * a PHP 8 "Undefined array key" warning and a wrong name, so both offsets are
	 * gated on isset.
	 *
	 * Measured, since the count is not obvious: with no placeholders to split on,
	 * `template_pattern` has one literal, appends only the FIRST replacement after
	 * it, and drops the rest — "(kopie)" builds `\(kopie\)(.*)`, one group, not two.
	 * So offset 1 is present and offset 2 is the one that is missing.
	 *
	 * Fired at `template_pattern`, which is where the group count is decided.
	 * `next_copy_name` calls `__()` itself and this bootstrap's `__()` returns its
	 * input, so the end-to-end translated path CANNOT be reached from here — the
	 * mechanism is proved, the full path is not, and shimming `__()` for one test
	 * would make the shim the experiment.
	 *
	 * @return void
	 */
	public function test_a_translation_missing_its_placeholders_yields_too_few_groups()
	{
		$pattern = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'template_pattern');
		$pattern->setAccessible(true);

		// What next_copy_name asks for, against a "translation" carrying no placeholders.
		$built = $pattern->invoke(null, '(kopie)', '(.*)', '(\\d+)');

		$this->assertSame(
			1,
			preg_match('/^' . $built . '$/', '(kopie)', $m),
			'the placeholder-less template still has to match its own literal'
		);

		// The gate in next_copy_name is exactly this. Ungated, $m[2] is a warning.
		$this->assertArrayHasKey(1, $m);
		$this->assertArrayNotHasKey(2, $m);
		$this->assertFalse(isset($m[1], $m[2]));
	}

	/**
	 * The intact templates DO produce the groups the code reads — otherwise the gate
	 * above would be silently swallowing every rename rather than a broken .po file.
	 *
	 * @return void
	 */
	public function test_the_shipped_templates_produce_the_groups_the_code_reads()
	{
		$pattern = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'template_pattern');
		$pattern->setAccessible(true);

		$nth = $pattern->invoke(null, '%1$s (copy %2$d)', '(.*)', '(\\d+)');
		$this->assertSame(1, preg_match('/^' . $nth . '$/', 'My Badge (copy 3)', $m));
		$this->assertTrue(isset($m[1], $m[2]));
		$this->assertSame('My Badge', $m[1]);
		$this->assertSame('3', $m[2]);

		$first = $pattern->invoke(null, '%s (copy)', '(.*)');
		$this->assertSame(1, preg_match('/^' . $first . '$/', 'My Badge (copy)', $m2));
		$this->assertTrue(isset($m2[1]));
		$this->assertSame('My Badge', $m2[1]);
	}

	/**
	 * The post_content path must keep working — this change added a SECOND source of
	 * candidates and must not have replaced the first.
	 *
	 * Asserted on the shortcode branch, not the block branch: `content_places_badge`
	 * hands blocks to `parse_blocks()`, which this unit bootstrap does not provide,
	 * so the block branch returns false here for want of a parser rather than for
	 * want of a match. Shimming a block parser would make the shim the experiment.
	 * The block branch is exercised live and by TrustBadgeStoreTest.
	 *
	 * @return void
	 */
	public function test_the_post_content_path_still_matches()
	{
		$m = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'content_places_badge');
		$m->setAccessible(true);

		$content = '<p>hi</p>[reviews-feed-badge id="1387"]';

		$this->assertTrue((bool) $m->invoke(null, $content, 1387));
		$this->assertFalse((bool) $m->invoke(null, $content, 138));
	}

	/**
	 * The block branch is unreachable in this bootstrap, and that is recorded rather
	 * than hidden: if a future bootstrap gains `parse_blocks`, this starts asserting
	 * instead of explaining.
	 *
	 * @return void
	 */
	public function test_the_block_branch_needs_a_parser_this_bootstrap_lacks()
	{
		if (! function_exists('parse_blocks')) {
			$this->assertFalse(
				function_exists('parse_blocks'),
				'documented gap: the block branch of content_places_badge cannot run here'
			);

			return;
		}

		$m = new ReflectionMethod(SBR_Trust_Badge_Store::class, 'content_places_badge');
		$m->setAccessible(true);

		$block = '<!-- wp:sbr/trust-badge {"id":1387} /-->';

		$this->assertTrue((bool) $m->invoke(null, $block, 1387));
		$this->assertFalse((bool) $m->invoke(null, $block, 138));
	}
}
