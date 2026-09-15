<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\DisplayElements;

/**
 * Two inline copies of one provider's artwork must not share element ids.
 *
 * The badge templates echo these SVGs inline, so a page with two badges for the
 * same provider — or a badge plus the block editor's preview of it — emits every
 * id twice. A duplicate id resolves to the first occurrence, which is identical
 * today and wrong the moment one copy's artwork is edited; the unreferenced ones
 * are simply invalid markup on a public page.
 *
 * Measured before the fix: edd-lockup REFERENCES its clip-path id, and booking
 * (29 ids), yelp (7) and aliexpress (1) carry ids nothing points at. The mono
 * icons already had this treatment for their `<mask id>`; the lockups did not.
 */
final class BadgeInlineSvgIdUniquenessTest extends TestCase
{
	/**
	 * @return array<string, array{0: string}>
	 */
	public static function providerWithIds(): array
	{
		return array(
			'edd (references its clip-path)' => array( 'edd' ),
			'booking (29 ids)'               => array( 'booking' ),
			'yelp (7 ids)'                   => array( 'yelp' ),
			'aliexpress (root svg id)'       => array( 'aliexpress' ),
		);
	}

	/**
	 * @dataProvider providerWithIds
	 *
	 * @param string $provider Provider slug.
	 *
	 * @return void
	 */
	public function test_two_renders_of_a_lockup_share_no_ids($provider): void
	{
		$first  = DisplayElements::get_provider_lockup($provider);
		$second = DisplayElements::get_provider_lockup($provider);

		$this->assertNotSame('', $first, $provider . ' must have lockup artwork');

		preg_match_all('/id="([^"]+)"/', $first, $a);
		preg_match_all('/id="([^"]+)"/', $second, $b);

		// Prove there are ids at all, or the overlap assertion is vacuous.
		$this->assertNotEmpty($a[1], $provider . ' was chosen because it carries ids');

		$this->assertSame(
			array(),
			array_values(array_intersect($a[1], $b[1])),
			'two inline copies of ' . $provider . ' would emit duplicate DOM ids'
		);
	}

	/**
	 * Renaming the ids must not break the references that use them.
	 *
	 * The half that matters for rendering: edd-lockup clips with `url(#…)`, so a
	 * rename that missed the reference would leave the mark clipped to nothing.
	 *
	 * @dataProvider providerWithIds
	 *
	 * @param string $provider Provider slug.
	 *
	 * @return void
	 */
	public function test_every_reference_still_resolves_inside_its_own_copy($provider): void
	{
		$svg = DisplayElements::get_provider_lockup($provider);

		preg_match_all('/id="([^"]+)"/', $svg, $ids);
		preg_match_all('/url\(#([^)]+)\)/', $svg, $refs);

		$dangling = array_values(array_diff($refs[1], $ids[1]));

		$this->assertSame(
			array(),
			$dangling,
			'these url(#…) references point at no id in the same copy: ' . implode(', ', $dangling)
		);
	}

	/**
	 * The provider ICONS too — the family the first two uniquifiers missed.
	 *
	 * Eight of fifteen carry exporter ids (`clip0_…`, `pattern0_…`,
	 * `Layer_x0020_1`, `Page-1`), and six of those are REFERENCED by `url(#…)` —
	 * the AliExpress and Formidable marks draw their embedded bitmaps through a
	 * pattern. Prefix-based rewriting could never have covered them, which is why
	 * the uniquifier now matches the id syntax instead of a naming convention.
	 *
	 * @return void
	 */
	public function test_two_renders_of_a_provider_icon_share_no_ids(): void
	{
		$checked = 0;

		foreach (array( 'airbnb', 'aliexpress', 'booking', 'facebook', 'formidable', 'woocommerce', 'yelp', 'zomato' ) as $provider) {
			$first = DisplayElements::get_provider_icon($provider);

			preg_match_all('/id="([^"]+)"/', $first, $a);

			if ($a[1] === array()) {
				continue;
			}

			$second = DisplayElements::get_provider_icon($provider);

			preg_match_all('/id="([^"]+)"/', $second, $b);

			$this->assertSame(
				array(),
				array_values(array_intersect($a[1], $b[1])),
				'two inline copies of the ' . $provider . ' icon would emit duplicate ids'
			);

			// And the rewrite must not orphan a clip-path or a pattern.
			preg_match_all('/url\(#([^)]+)\)|href="#([^"]+)"/', $first, $refs);

			$this->assertSame(
				array(),
				array_values(array_diff(array_filter(array_merge($refs[1], $refs[2])), $a[1])),
				$provider . ': a reference points at no id in the same copy'
			);

			$checked++;
		}

		$this->assertGreaterThan(5, $checked, 'too few icons carried ids for this to mean anything');
	}

	/**
	 * The mono marks, which carry the <mask id> the whole rewrite exists for.
	 *
	 * They went through their own prefix-matched uniquifier until it was folded
	 * into uniquify_ids(); asserted separately because get_provider_mono_icon()
	 * is still its own entry point and could stop calling it.
	 *
	 * @return void
	 */
	public function test_two_renders_of_a_mono_icon_share_no_ids(): void
	{
		$checked = 0;

		foreach (array( 'google', 'yelp', 'tripadvisor', 'trustpilot', 'booking' ) as $provider) {
			$first = DisplayElements::get_provider_mono_icon($provider);

			if (strpos($first, 'id="') === false) {
				continue;
			}

			$second = DisplayElements::get_provider_mono_icon($provider);

			preg_match_all('/id="([^"]+)"/', $first, $a);
			preg_match_all('/id="([^"]+)"/', $second, $b);

			$this->assertSame(
				array(),
				array_values(array_intersect($a[1], $b[1])),
				'two inline copies of the ' . $provider . ' mono icon would emit duplicate ids'
			);

			$checked++;
		}

		$this->assertGreaterThan(0, $checked, 'no mono icon carried an id — the loop proved nothing');
	}

	/**
	 * The rewriter does not care how the ids are spelled.
	 *
	 * Both mono marks that carry a `<mask id>` happen to use our own
	 * `sbr-mono-` naming, so the test above stays green with a rewriter gated on
	 * that literal prefix — which is what get_provider_mono_icon() used to have.
	 * The property that gate broke is only observable on an id named some other
	 * way, and no committed file is, so it is asserted on the helper directly.
	 *
	 * @return void
	 */
	public function test_the_id_rewriter_does_not_depend_on_how_ids_are_named(): void
	{
		$method = new \ReflectionMethod(DisplayElements::class, 'uniquify_ids');
		$method->setAccessible(true);

		// Deliberately nothing like our convention, and every reference form the
		// rewriter claims to handle.
		$svg = '<svg><mask id="a1"><path/></mask><clipPath id="Vendor_Clip.2"/>'
			. '<rect mask="url(#a1)" clip-path="url(#Vendor_Clip.2)"/>'
			. '<use href="#a1"/></svg>';

		$first  = (string) $method->invoke(null, $svg, 'probe');
		$second = (string) $method->invoke(null, $svg, 'probe');

		preg_match_all('/id="([^"]+)"/', $first, $a);
		preg_match_all('/id="([^"]+)"/', $second, $b);

		$this->assertCount(2, $a[1], 'both ids must survive the rewrite');

		$this->assertSame(
			array(),
			array_values(array_intersect($a[1], $b[1])),
			'ids that do not follow our naming were left alone — the rewriter is gated on a prefix'
		);

		// A renamed id nothing points at is worse than a duplicate: the mask
		// stops applying and the mark draws as a solid block.
		foreach ($a[1] as $id) {
			$this->assertStringContainsString('url(#' . $id . ')', $first, 'url() reference not rewritten with its id');
		}

		$this->assertStringContainsString('href="#' . $a[1][0] . '"', $first, 'href reference not rewritten with its id');
	}

	/**
	 * The mono path holds no uniquifier of its own.
	 *
	 * It had one, narrowed to a literal prefix. A second local copy of a shared
	 * transform is how the two drift, and this is the assertion the prefix gate
	 * actually fails.
	 *
	 * @return void
	 */
	public function test_the_mono_path_delegates_to_the_shared_rewriter(): void
	{
		$src  = (string) file_get_contents(dirname(__DIR__, 3) . '/class/Common/DisplayElements.php');
		$body = \SbrPhpSource::method_body($src, 'get_provider_mono_icon');

		$this->assertNotSame('', $body, 'get_provider_mono_icon() body not found');

		$this->assertTrue(
			\SbrPhpSource::contains($body, 'self::uniquify_ids($svg'),
			'the mono path must rewrite ids through the shared helper'
		);

		$this->assertFalse(
			\SbrPhpSource::contains($body, "'id=\"sbr-mono-'"),
			'the prefix gate is back: a mono mark named any other way keeps its ids'
		);
	}
}
