<?php

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;

/**
 * SMASH-1131 / PR #426 — BC pin for the templates/frontend/item.php provider-icon gate.
 *
 * The PR turns EDD into a SOURCE provider (alongside the legacy form-collector
 * that lives at Forms/FormProviders/EddReviews.php). Both code paths store
 * with `provider.name = 'edd'` in wp_sbr_reviews_posts:
 *   - Form-collected (existing): SubmissionsManager::transform_to_review() does
 *     NOT set $review['business'], $review['source']['url'] is empty.
 *   - Source-collected (new in PR #426): EDD::normalize_reviews() sets
 *     $post['business']['id'] = $download_id (EDD.php:500,683).
 *
 * The naked PR change removed 'edd' from item.php's $no_icon list — that would
 * have visually regressed existing form-collected EDD reviews by suddenly
 * rendering an EDD icon where they had none. The fix uses `business.id` as the
 * source-vs-form discriminator, preserving the no-icon BC for form-collected
 * while enabling the new icon for source-collected.
 *
 * These tests reproduce the item.php gate logic inline so we can exercise it
 * without booting WordPress (the template body is non-class shared logic
 * extracted into static helpers below).
 */
class EddItemTemplateBcTest extends TestCase
{
	/**
	 * Reproduces the gate logic from templates/frontend/item.php lines 15-23
	 * exactly. If item.php changes, this helper must change in lockstep —
	 * intentionally tight coupling so the test pins the contract.
	 *
	 * @param array $post Shape: ['provider' => ['name' => ...], 'business' => ['id' => ...], ...]
	 * @return bool Whether the provider icon should render.
	 */
	private static function shouldShowIcon(array $post): bool
	{
		$no_icon = ['wpforms', 'formidable', 'edd'];
		$provider_name = $post['provider']['name'] ?? '';
		$is_edd_source = $provider_name === 'edd' && ! empty($post['business']['id'] ?? null);
		return $provider_name !== '' && $provider_name !== 'none'
			&& (! in_array($provider_name, $no_icon, true) || $is_edd_source);
	}

	public function test_form_collected_edd_review_does_not_show_icon(): void
	{
		// Shape produced by SubmissionsManager::transform_to_review() for an
		// EDD form-collected review — no `business` key, `source.url` empty.
		$post = [
			'provider' => ['name' => 'edd', 'id' => 42],
			'source'   => ['id' => 'sbr_collection_1', 'url' => ''],
			'review_id' => 'sub_abc123',
		];
		$this->assertFalse(
			self::shouldShowIcon($post),
			'BC: form-collected EDD reviews (no business.id) must keep the legacy no-icon rendering'
		);
	}

	public function test_source_collected_edd_review_shows_icon(): void
	{
		// Shape produced by EDD::normalize_reviews() for a source-collected
		// EDD review — `business.id` populated with the download_id.
		$post = [
			'provider' => ['name' => 'edd', 'id' => '12345'],
			'business' => ['id' => 12345, 'name' => 'My Plugin Pro'],
			'source'   => ['id' => '12345', 'url' => 'https://example.test/?p=12345'],
			'review_id' => 'comment_67',
		];
		$this->assertTrue(
			self::shouldShowIcon($post),
			'Source-collected EDD reviews (business.id present) must render the EDD icon'
		);
	}

	public function test_wpforms_review_does_not_show_icon(): void
	{
		// Pre-existing behavior — wpforms is a form-only provider with no
		// source-mode, so it stays in $no_icon unconditionally.
		$post = [
			'provider' => ['name' => 'wpforms', 'id' => 1],
		];
		$this->assertFalse(self::shouldShowIcon($post));
	}

	public function test_formidable_review_does_not_show_icon(): void
	{
		$post = [
			'provider' => ['name' => 'formidable', 'id' => 2],
		];
		$this->assertFalse(self::shouldShowIcon($post));
	}

	public function test_woocommerce_review_shows_icon(): void
	{
		// Sanity: WooCommerce is a real source provider — not in $no_icon,
		// not affected by the EDD discriminator. Shows icon as before.
		$post = [
			'provider' => ['name' => 'woocommerce', 'id' => 'wc_123'],
			'source'   => ['id' => 'wc_123', 'url' => 'https://example.test/product/foo/'],
		];
		$this->assertTrue(self::shouldShowIcon($post));
	}

	public function test_google_review_shows_icon(): void
	{
		// Sanity: Google source — always shows icon.
		$post = [
			'provider' => ['name' => 'google', 'id' => 'ChIJxxx'],
		];
		$this->assertTrue(self::shouldShowIcon($post));
	}

	public function test_provider_none_does_not_show_icon(): void
	{
		// Legacy "no provider" sentinel — must not render.
		$post = [
			'provider' => ['name' => 'none', 'id' => 0],
		];
		$this->assertFalse(self::shouldShowIcon($post));
	}

	public function test_missing_provider_does_not_show_icon(): void
	{
		// Defensive: malformed / partial post shapes must not crash + must
		// fall through to "no icon" (safer than rendering broken alt-text).
		$this->assertFalse(self::shouldShowIcon([]));
		$this->assertFalse(self::shouldShowIcon(['provider' => []]));
		$this->assertFalse(self::shouldShowIcon(['provider' => ['name' => '']]));
	}

	public function test_edd_with_empty_business_id_stays_no_icon(): void
	{
		// Defensive: business present but business.id empty (zero, null, "")
		// MUST be treated as form-collected — the discriminator only
		// activates on a truthy, non-empty business.id (matches !empty() in
		// the template).
		foreach ([null, '', 0, '0', false] as $empty) {
			$post = [
				'provider' => ['name' => 'edd'],
				'business' => ['id' => $empty],
			];
			$this->assertFalse(
				self::shouldShowIcon($post),
				'EDD review with empty business.id (' . var_export($empty, true) . ') must stay no-icon'
			);
		}
	}
}
