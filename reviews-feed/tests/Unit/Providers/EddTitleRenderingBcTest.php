<?php

declare(strict_types=1);

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

/**
 * SMASH-1553 — BC pin for the templates/frontend/post-elements/text.php
 * provider-title gate.
 *
 * The PR adds a review-title render block to the default text template,
 * gated by a provider allowlist. The gate exists because
 * Util::parse_single_review() at class/Common/Util.php:1421 synthesizes
 * a substring fallback title from the review text whenever the upstream
 * provider omits one:
 *
 *     'title' => isset($review['title']) ? $review['title'] : substr($review['text'], 0, 40)
 *
 * That fallback fires for every review stored via Collection/Import paths
 * (PostAggregator::insert_multiple_reviews at PostAggregator.php:458 and
 * SBR_Feed_Saver_Manager::import_reviews_collection at the equivalent
 * lines). Result: Google / Yelp / Facebook / TripAdvisor / Trustpilot /
 * WP.org / WooCommerce / Airbnb / AliExpress reviews stored via Collection
 * carry a synthesized "first 40 chars of review text" value in $post['title'].
 *
 * A naked `!empty($post['title'])` guard in the template would surface that
 * synthesized substring as a bold title above the full review text — a
 * visible BC regression for every existing Collection feed.
 *
 * These tests reproduce the gate logic from text.php exactly so future
 * edits to the template must touch this helper in lockstep — intentionally
 * tight coupling.
 */
class EddTitleRenderingBcTest extends TestCase {

	/**
	 * Reproduces the gate logic from templates/frontend/post-elements/text.php
	 * (the `$providers_with_review_titles` allowlist block introduced in
	 * SMASH-1553). If text.php changes, this helper must change in lockstep.
	 *
	 * @param array<string,mixed> $post Shape used by the default text template.
	 * @return bool Whether the title block should render.
	 */
	private static function shouldRenderTitle( array $post ): bool {
		$providers_with_review_titles = array( 'edd' );
		$post_provider                = ! empty( $post['provider']['name'] ) ? $post['provider']['name'] : '';
		return in_array( $post_provider, $providers_with_review_titles, true )
			&& ! empty( $post['title'] )
			&& is_string( $post['title'] );
	}

	public function test_edd_review_with_real_title_renders(): void {
		// EDD's submission form makes the title field required, so every
		// real EDD review carries an authoritative customer-typed title.
		$post = array(
			'provider' => array( 'name' => 'edd' ),
			'title'    => 'Excellent plugin — easy to set up',
			'text'     => 'I bought this last week and it just works.',
		);
		$this->assertTrue(
			self::shouldRenderTitle( $post ),
			'EDD reviews with a real title MUST render the title block.'
		);
	}

	public function test_google_review_with_synthesized_title_does_not_render(): void {
		// Shape produced by Util::parse_single_review() at Util.php:1421 when
		// a Google review is stored via the Collection / Import path: the
		// `title` field is synthesized as the first 40 chars of `text`.
		// Pre-SMASH-1553, the default template never read `title`, so this
		// fake value was harmless. The new title block MUST exclude this
		// case via the provider allowlist.
		$post = array(
			'provider' => array( 'name' => 'google' ),
			'title'    => 'Great service! Will definitely come ba',
			'text'     => 'Great service! Will definitely come back next time I am in town.',
		);
		$this->assertFalse(
			self::shouldRenderTitle( $post ),
			'BC: Google reviews carrying a synthesized substring title (from Util.php:1421) MUST NOT render the title block — that would surface fake duplicated text above the review body.'
		);
	}

	public function test_yelp_review_with_synthesized_title_does_not_render(): void {
		$post = array(
			'provider' => array( 'name' => 'yelp' ),
			'title'    => 'Service was a bit slow but the food m',
			'text'     => 'Service was a bit slow but the food more than made up for it.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'BC: Yelp Collection reviews must not render the synthesized title.' );
	}

	public function test_facebook_review_with_synthesized_title_does_not_render(): void {
		$post = array(
			'provider' => array( 'name' => 'facebook' ),
			'title'    => 'Highly recommend this place — friendly',
			'text'     => 'Highly recommend this place — friendly staff and great prices.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'BC: Facebook Collection reviews must not render the synthesized title.' );
	}

	public function test_woocommerce_review_with_empty_title_does_not_render(): void {
		// WooCommerce direct-ingest sets `'title' => ''` (WooCommerce.php:298,427).
		// Even if WooCommerce were on the allowlist, the empty-string check
		// should prevent rendering.
		$post = array(
			'provider' => array( 'name' => 'woocommerce' ),
			'title'    => '',
			'text'     => 'Decent product.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'WooCommerce empty-string title must not render.' );
	}

	public function test_edd_review_with_missing_title_does_not_render(): void {
		// Defensive: EDD reviews predating the title field, or rows where
		// the meta was somehow lost. Empty/missing title must skip the block.
		$post = array(
			'provider' => array( 'name' => 'edd' ),
			'text'     => 'No-title legacy EDD review.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'EDD reviews without a title field must not render an empty heading.' );

		$post['title'] = null;
		$this->assertFalse( self::shouldRenderTitle( $post ), 'EDD reviews with null title must not render.' );

		$post['title'] = '';
		$this->assertFalse( self::shouldRenderTitle( $post ), 'EDD reviews with empty-string title must not render.' );
	}

	public function test_edd_review_with_non_string_title_does_not_render(): void {
		// Defense in depth: if any future ingest path stuffs a non-string
		// value into `title` (legacy data, dev fixture, schema drift), the
		// `is_string()` guard prevents the template's `esc_html()` call from
		// fatalling on PHP 8+ (which requires a string argument).
		$post = array(
			'provider' => array( 'name' => 'edd' ),
			'title'    => array( 'nested', 'shape' ),
			'text'     => 'Review text.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Array title must not render — would fatal on esc_html().' );

		$post['title'] = (object) array( 'value' => 'x' );
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Object title must not render.' );

		$post['title'] = 12345;
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Integer title must not render — defends against silent string-cast surprises.' );
	}

	public function test_missing_provider_name_does_not_render(): void {
		// Defensive: malformed $post with no provider name (unusual, but
		// possible in legacy data or partial migrations).
		$post = array(
			'title' => 'EDD review title',
			'text'  => 'Some text.',
		);
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Missing provider.name must not render the title block.' );

		$post['provider'] = array();
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Empty provider array must not render.' );

		$post['provider'] = array( 'name' => '' );
		$this->assertFalse( self::shouldRenderTitle( $post ), 'Empty provider name must not render.' );
	}
}
