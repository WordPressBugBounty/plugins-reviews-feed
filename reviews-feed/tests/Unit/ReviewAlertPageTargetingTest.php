<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Frontend;

/**
 * Pins the Review Alert page-targeting fix (SMASH-1616).
 *
 * Before: the page-targeting dropdown only listed the `page` post type
 * (get_pages), and the frontend matcher matched custom post types only by SLUG
 * (whole type). So a page-builder landing page (a CPT) couldn't be targeted
 * individually — selectable-but-never-fires.
 *
 * After: the builder lists `page` + public custom post types; and the matcher's
 * `custom_post_type` case keeps the slug whole-type match AND adds a per-post-ID
 * fallback against the selected `pages` list, so an individually-selected landing
 * page fires.
 *
 * The `custom_post_type` matcher branch uses only `extract_visibility_ids` (pure),
 * so it runs in the plain-PHPUnit suite via reflection. The builder change is a
 * source-guard (it calls WP `get_post_types`/`get_posts`).
 */
final class ReviewAlertPageTargetingTest extends TestCase
{
	private static function locationMatches(array $location, array $list): bool
	{
		$ref = new \ReflectionClass(SBR_Review_Alert_Frontend::class);
		$obj = $ref->newInstanceWithoutConstructor();
		$m = $ref->getMethod('is_location_in_list');
		$m->setAccessible(true);

		return (bool) $m->invoke($obj, $location, $list);
	}

	public function test_cpt_single_matches_by_post_id_in_pages_list(): void
	{
		// A landing page (CPT) selected individually is stored under `pages` by ID.
		$location = ['type' => 'custom_post_type', 'id' => 'e-landing-page', 'post_id' => 1234];
		$list = ['pages' => [['id' => 1234, 'title' => 'Landing', 'url' => '/x']]];
		$this->assertTrue(self::locationMatches($location, $list), 'CPT single must fire when its post ID is in the pages list');
	}

	public function test_cpt_whole_type_slug_match_preserved(): void
	{
		$location = ['type' => 'custom_post_type', 'id' => 'e-landing-page', 'post_id' => 1234];
		$list = ['custom_post_types' => [['name' => 'e-landing-page']]];
		$this->assertTrue(self::locationMatches($location, $list), 'Whole-type (slug) targeting must still work');
	}

	public function test_cpt_no_match_when_neither_id_nor_slug_listed(): void
	{
		$location = ['type' => 'custom_post_type', 'id' => 'e-landing-page', 'post_id' => 1234];
		$list = ['pages' => [['id' => 9999]], 'custom_post_types' => [['name' => 'other']]];
		$this->assertFalse(self::locationMatches($location, $list));
	}

	public function test_cpt_archive_without_post_id_still_slug_matches(): void
	{
		// A post-type ARCHIVE has no post_id; it must still match by slug.
		$location = ['type' => 'custom_post_type', 'id' => 'e-landing-page'];
		$list = ['custom_post_types' => [['name' => 'e-landing-page']]];
		$this->assertTrue(self::locationMatches($location, $list));
	}

	public function test_cpt_archive_without_post_id_does_not_match_pages(): void
	{
		// No post_id → the per-ID fallback must be skipped (no accidental match).
		$location = ['type' => 'custom_post_type', 'id' => 'e-landing-page'];
		$list = ['pages' => [['id' => 0]]];
		$this->assertFalse(self::locationMatches($location, $list));
	}

	public function test_bc_legacy_plain_page_id_still_matches(): void
	{
		// Old saved format: pages as plain ints. The page-case ID check fires
		// before any WooCommerce conditional, so this needs no WP context.
		$location = ['type' => 'page', 'id' => 55];
		$list = ['pages' => [55]];
		$this->assertTrue(self::locationMatches($location, $list));
	}

	public function test_builder_lists_public_post_types_not_only_pages(): void
	{
		$src = (string) file_get_contents(__DIR__ . '/../../class/Common/ReviewAlerts/SBR_ReviewAlert_Builder.php');
		$this->assertStringContainsString(
			"get_post_types(['public' => true, '_builtin' => false]",
			$src,
			'Builder must enumerate public custom post types, not just pages'
		);
		$this->assertStringNotContainsString(
			"get_pages(['post_status' => 'publish'])",
			$src,
			'Builder must no longer use get_pages() (page-type only)'
		);
	}
}
