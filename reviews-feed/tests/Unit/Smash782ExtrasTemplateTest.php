<?php

// Stub WP escape + i18n helpers in the GLOBAL namespace so plugin templates
// (which call `esc_html`, `esc_attr`, etc. as global functions) can resolve
// them when included from these unit tests. The plugin's tests/bootstrap.php
// already stubs sanitize_text_field / __() / etc., but not these.
namespace {
	if (!function_exists('esc_html')) {
		function esc_html($s)
		{
			return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		}
	}
	if (!function_exists('esc_attr')) {
		function esc_attr($s)
		{
			return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
		}
	}
	if (!function_exists('esc_url')) {
		function esc_url($url)
		{
			return (string) $url;
		}
	}
	if (!function_exists('esc_html__')) {
		function esc_html__($text, $domain = null)
		{
			return esc_html($text);
		}
	}
	if (!function_exists('_n')) {
		function _n($single, $plural, $number, $domain = null)
		{
			return (int) $number === 1 ? $single : $plural;
		}
	}
	if (!function_exists('wp_kses_post')) {
		function wp_kses_post($html)
		{
			return $html;
		}
	}
}

namespace SbReviews\Tests\Unit {

	use PHPUnit\Framework\TestCase;

/**
 * SMASH-782 Phase 2 — plugin template render tests.
 *
 * Each of the new per-provider templates (extras + rating-extras +
 * text-aliexpress) is pure PHP that takes `$post` and outputs HTML.
 * These tests render the templates with fixtures mirroring real relay
 * output and assert the HTML contains the prototype-required classes
 * AND that templates emit NOTHING when real data is missing
 * (no demo / static fallback content).
 */
	class Smash782ExtrasTemplateTest extends TestCase
	{
		private const TEMPLATES_DIR = __DIR__ . '/../../templates/frontend/post-elements';

	/**
		 * Render a template with `$post` in scope and capture output.
		 *
		 * PHPUnit 10 with `beStrictAboutOutputDuringTests="true"` tracks output
		 * buffer count and fails if buffers we open aren't closed. The level guard
		 * below force-closes any buffer left open by the template (even if it
		 * returned early through an exception path), so PHPUnit's count matches.
		 */
		private function render(string $relPath, array $post): string
		{
			$startLevel = ob_get_level();
			ob_start();
			try {
				include self::TEMPLATES_DIR . '/' . $relPath;
			} finally {
				$out = '';
				while (ob_get_level() > $startLevel) {
					$out = ob_get_clean() . $out;
				}
			}
			return $out;
		}

		// -------------------------------------------------------------------------
		// AIRBNB extras
		// -------------------------------------------------------------------------

		public function test_airbnb_reply_renders_full_block_when_response_present(): void
		{
			$out = $this->render('extras/airbnb.php', [
			'response' => 'Thanks for staying!',
			'reply'    => [
				'name'   => 'Bill',
				'avatar' => 'https://muscache/host.jpg',
			],
			'provider' => ['name' => 'airbnb'],
			]);

			$this->assertStringContainsString('class="sb-item-reply"', $out);
			$this->assertStringContainsString('data-from="host"', $out);
			$this->assertStringContainsString('class="sb-item-reply-header"', $out);
			$this->assertStringContainsString('<img class="sb-item-reply-avatar"', $out);
			$this->assertStringContainsString('src="https://muscache/host.jpg"', $out);
			$this->assertStringContainsString('class="sb-item-reply-name">Bill', $out);
			$this->assertStringContainsString('class="sb-item-reply-label">Host', $out);
			$this->assertStringContainsString('class="sb-item-reply-text">Thanks for staying!', $out);
		}

		public function test_airbnb_reply_emits_nothing_when_response_empty(): void
		{
			$out = $this->render('extras/airbnb.php', [
			'response' => '',
			'reply'    => ['name' => 'Bill', 'avatar' => 'https://x.jpg'],
			'provider' => ['name' => 'airbnb'],
			]);
			$this->assertSame('', trim($out), 'No demo fallback — empty response must produce empty output.');
		}

		public function test_airbnb_reply_renders_without_avatar_when_only_text(): void
		{
			$out = $this->render('extras/airbnb.php', [
			'response' => 'Thanks!',
			'reply'    => ['name' => '', 'avatar' => ''],
			'provider' => ['name' => 'airbnb'],
			]);
			$this->assertStringContainsString('class="sb-item-reply"', $out);
			$this->assertStringContainsString('class="sb-item-reply-text">Thanks!', $out);
			$this->assertStringNotContainsString('sb-item-reply-avatar', $out);
			$this->assertStringNotContainsString('sb-item-reply-name', $out);
		}

		// -------------------------------------------------------------------------
		// BOOKING extras + rating-extras
		// -------------------------------------------------------------------------

		public function test_booking_rating_extras_renders_score_badge_inside_rating_slot(): void
		{
			// Booking's REAL 0-10 hotel score + word, forwarded by the relay as
			// metadata.review_score / metadata.review_score_word (no derived value,
			// no invented bands — see rating-extras/booking.php docblock).
			$out = $this->render('rating-extras/booking.php', [
			'metadata' => ['review_score' => 8.7, 'review_score_word' => 'Very Good'],
			'provider' => ['name' => 'booking'],
			]);
			$this->assertStringContainsString('class="sb-item-rating-score"', $out);
			$this->assertStringContainsString('class="sb-item-rating-score-badge">8.7', $out);
			$this->assertStringContainsString('class="sb-item-rating-score-label">Very Good', $out);
		}

		public function test_booking_rating_extras_emits_nothing_when_rating_zero(): void
		{
			$out = $this->render('rating-extras/booking.php', [
			'rating'   => 0,
			'provider' => ['name' => 'booking'],
			]);
			$this->assertSame('', trim($out));
		}

		public function test_booking_score_renders_real_metadata_score_and_word_verbatim(): void
		{
			// The badge shows Booking's real 0-10 score verbatim (number_format 1dp)
			// and the label shows Booking's own word verbatim — never a value/band
			// derived from the 0-5 star rating.
			$cases = [
			['score' => 10.0, 'word' => 'Exceptional'],
			['score' => 8.5,  'word' => 'Very Good'],
			['score' => 7.5,  'word' => 'Good'],
			['score' => 6.5,  'word' => 'Pleasant'],
			];
			foreach ($cases as $c) {
				$out = $this->render('rating-extras/booking.php', [
				'metadata' => ['review_score' => $c['score'], 'review_score_word' => $c['word']],
				]);
				$this->assertStringContainsString('>' . number_format($c['score'], 1) . '<', $out, 'score ' . $c['score']);
				$this->assertStringContainsString($c['word'], $out, 'score ' . $c['score']);
			}

			// Real score present but no word from the relay -> badge only, no label.
			$out = $this->render('rating-extras/booking.php', [
			'metadata' => ['review_score' => 8.0],
			]);
			$this->assertStringContainsString('>8.0<', $out);
			$this->assertStringNotContainsString('rating-score-label', $out);
		}

		public function test_booking_extras_renders_helpful_when_count_positive(): void
		{
			$out = $this->render('extras/booking.php', [
			'metadata' => ['helpful_vote_count' => 14],
			'provider' => ['name' => 'booking'],
			]);
			// class list also carries the SMASH-782 section-spacing class
			// (sbr-review-horizontal-element), so match the class prefix only.
			$this->assertStringContainsString('class="sb-item-helpful', $out);
			$this->assertStringContainsString('class="sb-item-helpful-icon"', $out);
			$this->assertStringContainsString('14 people found this helpful', $out);
		}

		public function test_booking_extras_renders_singular_helpful(): void
		{
			$out = $this->render('extras/booking.php', [
			'metadata' => ['helpful_vote_count' => 1],
			'provider' => ['name' => 'booking'],
			]);
			$this->assertStringContainsString('1 person found this helpful', $out);
		}

		public function test_booking_extras_emits_nothing_when_helpful_zero(): void
		{
			$out = $this->render('extras/booking.php', [
			'metadata' => ['helpful_vote_count' => 0],
			'provider' => ['name' => 'booking'],
			]);
			$this->assertSame('', trim($out));
		}

		// -------------------------------------------------------------------------
		// ALIEXPRESS extras + text-aliexpress
		// -------------------------------------------------------------------------

		public function test_aliexpress_extras_renders_variants_only_when_item_spec_present(): void
		{
			$out = $this->render('extras/aliexpress.php', [
			'metadata' => ['item_spec' => 'Color:Black Size:XL'],
			'provider' => ['name' => 'aliexpress'],
			]);
			$this->assertStringContainsString('class="sb-item-variants', $out);
			$this->assertStringContainsString('class="sb-item-variants-pill">Color: Black</span>', $out);
			$this->assertStringContainsString('class="sb-item-variants-pill">Size: XL</span>', $out);
		}

		public function test_aliexpress_extras_emits_nothing_when_no_variants_no_followup(): void
		{
			$out = $this->render('extras/aliexpress.php', [
			'metadata' => ['item_spec' => '', 'followup' => null],
			'provider' => ['name' => 'aliexpress'],
			]);
			$this->assertSame('', trim($out));
		}

		public function test_aliexpress_extras_renders_followup_block_when_metadata_populated(): void
		{
			$out = $this->render('extras/aliexpress.php', [
			'metadata' => [
				'item_spec' => '',
				'followup'  => [
					'date' => '2 weeks later',
					'text' => 'Still going strong.',
				],
			],
			'provider' => ['name' => 'aliexpress'],
			]);

			// class list also carries the SMASH-782 section-spacing class, so match prefix only.
			$this->assertStringContainsString('class="sb-item-followup', $out);
			$this->assertStringContainsString('class="sb-item-followup-header"', $out);
			$this->assertStringContainsString('class="sb-item-followup-label">Follow-up Review', $out);
			$this->assertStringContainsString('class="sb-item-followup-date">2 weeks later', $out);
			$this->assertStringContainsString('class="sb-item-followup-text">Still going strong.', $out);
		}
	}

} // end namespace SbReviews\Tests\Unit
