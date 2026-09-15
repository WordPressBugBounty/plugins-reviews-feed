<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * A saved badge answers with everything the email step needs (SBR-163).
 *
 * The page-load payload sets `imageURL` and `emailSnippet` only when an EXISTING
 * badge is loaded — `$badge !== null` in `enqueue_data()`. A badge created in the
 * editor has neither, because it had no id until the save that just happened. So
 * the save response has to carry both, or the Embed → Email step for a
 * newly-created badge offers an empty image.
 *
 * `emailSnippet` was already returned for a related reason (its width and height
 * are measured off the rendered PNG and move with the elements). `imageURL` was
 * not, which a review round on sb-customizer#192 caught.
 *
 * Asserted on the source rather than by invoking the AJAX handler: it calls
 * `wp_send_json_success()`, which exits, and the shim cannot represent that.
 */
class BadgeSaveResponseTest extends TestCase
{
	/**
	 * @return string
	 */
	private function builder()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * The save handler must attach both, from the id it has just settled.
	 *
	 * @return void
	 */
	public function test_the_save_response_carries_a_fresh_image_url_and_snippet()
	{
		$src = $this->builder();

		// The snippet is computed once into $snippet and assigned from it, because
		// the URL is now gated on that same verdict.
		$this->assertMatchesRegularExpression(
			'/\$snippet\s*=\s*SBR_Badge_Image::snippet\(\$saved,/',
			$src,
			'the save response must carry a snippet measured off the just-saved badge'
		);

		$this->assertMatchesRegularExpression(
			'/\$badge\[\'emailSnippet\'\]\s*=\s*\$snippet/',
			$src,
			'the snippet must reach the response'
		);

		$this->assertMatchesRegularExpression(
			'/\$badge\[\'imageURL\'\]\s*=\s*\$snippet === \'\'\s*\?\s*\'\'\s*:\s*SBR_Badge_Image::url\(\$saved\)/',
			$src,
			'the image URL must be gated on a real render — an ungated URL 503s forever on a host that cannot rasterise'
		);
	}

	/**
	 * And both must be derived from `$saved`, not from the incoming `$id`.
	 *
	 * A new badge arrives with `id` 0; the id it ends up with comes back from the
	 * store as `$saved`. Deriving either from `$id` would point at badge 0.
	 *
	 * @return void
	 */
	public function test_both_are_derived_from_the_saved_id_not_the_posted_one()
	{
		$src = $this->builder();

		// Whitespace-insensitive, because these are NEGATIVE: WPCS wants spaces
		// inside parentheses, so `url( $id )` slipped past a literal comparison —
		// the assertion would have been green with the wrong id being used.
		$this->assertFalse(
			\SbrPhpSource::contains($src, 'SBR_Badge_Image::url($id)'),
			'the image URL must come from the saved id; a new badge posts id 0'
		);

		$this->assertFalse(
			\SbrPhpSource::contains($src, 'SBR_Badge_Image::snippet($id,'),
			'the snippet must come from the saved id; a new badge posts id 0'
		);
	}

	/**
	 * The page-load payload still sets both for an existing badge.
	 *
	 * The save-response addition is additive; this pins that it did not replace the
	 * page-load path, which is what an editor reopened on a saved badge reads.
	 *
	 * @return void
	 */
	public function test_page_load_still_supplies_both_for_an_existing_badge()
	{
		$src = $this->builder();

		$this->assertStringContainsString(
			"\$data['trustBadges']['imageURL']",
			$src,
			'reopening a saved badge must still get its image URL at page load'
		);
		$this->assertStringContainsString(
			"\$data['trustBadges']['emailSnippet']",
			$src,
			'reopening a saved badge must still get its snippet at page load'
		);
	}
}
