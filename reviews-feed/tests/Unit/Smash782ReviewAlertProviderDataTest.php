<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Frontend;

/**
 * SMASH-782 — Review Alert provider-data pass-through (data layer).
 *
 * The alert popup renders the same provider-specific elements the feed does
 * (Booking pros/cons + 0-10 score + helpful + photos, AliExpress variants /
 * translated / buyer-flag / followup, Airbnb reply). Those all live in the
 * review's `metadata` / `reply` / `title` / `reviewer_photos`, but the frontend
 * formatter previously emitted only a whitelist (id/text/rating/time/reviewer/
 * provider), so the popup never received them. This guards that the formatter
 * now forwards the provider shape (additive — the original keys are unchanged).
 *
 * Reflection is used because format_reviews_for_frontend() is private and reads
 * only its argument (no WP/DB state).
 */
final class Smash782ReviewAlertProviderDataTest extends TestCase
{
	/** @param array<int,array<string,mixed>> $reviews */
	private function format(array $reviews): array
	{
		$fe = (new \ReflectionClass(SBR_Review_Alert_Frontend::class))->newInstanceWithoutConstructor();
		$m = new \ReflectionMethod($fe, 'format_reviews_for_frontend');
		$m->setAccessible(true);
		return $m->invoke($fe, $reviews);
	}

	public function test_booking_score_and_word_are_resolved_server_side_from_the_raw_rating(): void
	{
		// The trap this pins: `rating` is cast to int for the star renderer, so a JS
		// mirror that doubled it would publish 8.0 for a 4.5-star review while the
		// feed card, the popup template and the JSON-LD all say 9.0. The formatter
		// resolves the pair from the RAW rating before that cast happens.
		$out = $this->format([[
			'review_id' => 'b-half',
			'text'      => 'Lovely',
			'rating'    => 4.5,
			'reviewer'  => ['name' => 'Eva', 'avatar' => ''],
			'provider'  => ['name' => 'booking'],
		]]);
		$r = $out[0];

		$this->assertSame(9.0, $r['bookingScore']);
		$this->assertSame('Superb', $r['bookingScoreWord']);
		// The int cast on `rating` stays — the star renderer depends on it.
		$this->assertSame(4, $r['rating']);
	}

	public function test_booking_score_ignores_the_property_wide_metadata_score(): void
	{
		// metadata.review_score is the HOTEL's score, identical on every card. Reading
		// it here is what made the popup show one score no matter who was cycled in.
		$out = $this->format([[
			'review_id' => 'b2',
			'text'      => 'Fine',
			'rating'    => 4,
			'reviewer'  => ['name' => 'Dan', 'avatar' => ''],
			'provider'  => ['name' => 'booking'],
			'metadata'  => ['review_score' => 9.5, 'review_score_word' => 'Exceptional'],
		]]);
		$r = $out[0];

		$this->assertSame(8.0, $r['bookingScore']);
		$this->assertSame('Very good', $r['bookingScoreWord']);
	}

	public function test_preview_and_frontend_resolve_a_booking_score_from_the_same_basis(): void
	{
		// The builder preview computes the badge itself (SbUtils.bookingReviewScore →
		// rating × 2) because there is no PHP round-trip in the editor, so it needs the
		// same rating the frontend resolves from. get_preview_reviews() used to ship
		// `(int) $rating`: a 4.5 review rendered "8.0 Very good" in the popup editor and
		// "9.0 Superb" in the live popup. Ten reviewers in the local cache sit at 4.5, so
		// this was live, not hypothetical.
		//
		// Reflection on the shaping expression only — get_preview_reviews() itself needs
		// the Feed/DB stack. What this pins is that the two sides agree on the number.
		$review = [
			'review_id' => 'b-half',
			'text'      => 'Lovely',
			'rating'    => 4.5,
			'reviewer'  => ['name' => 'Adrian', 'avatar' => ''],
			'provider'  => ['name' => 'booking'],
		];

		$frontend = $this->format([$review])[0];
		$this->assertSame(9.0, $frontend['bookingScore'], 'frontend basis');
		$this->assertSame('Superb', $frontend['bookingScoreWord']);

		// The preview ships the rating itself; both must double to the same score.
		$preview_rating = $this->preview_rating_for('booking', $review);
		$this->assertSame(4.5, $preview_rating, 'preview must not truncate a Booking rating');
		$this->assertSame(
			$frontend['bookingScore'],
			sbr_booking_review_score(['rating' => $preview_rating]),
			'preview and frontend must land on the same 0-10 score'
		);

		// Non-Booking keeps the int cast the star renderer depends on.
		$this->assertSame(4, $this->preview_rating_for('google', ['rating' => 4.5]));
	}

	/**
	 * Mirror of the rating expression in SBR_Review_Alert_Service::get_preview_reviews().
	 * Kept in step by the guard below, which fails if that line stops sending a float.
	 *
	 * @param array<string,mixed> $review
	 * @return float|int
	 */
	private function preview_rating_for(string $provider, array $review)
	{
		return 'booking' === $provider
			? (float) ($review['rating'] ?? 0)
			: (int) $review['rating'];
	}

	public function test_the_preview_payload_sends_booking_ratings_unrounded(): void
	{
		// Guards the mirror above: get_preview_reviews() needs the Feed/DB stack, so
		// there is no DB-free way to call it and the source is what gets pinned. Without
		// this, dropping the Booking branch leaves the test above green while the preview
		// silently drifts a whole band from the live popup.
		//
		// The CAST is the load-bearing part, not just the branch — `(int)` inside the
		// same ternary would truncate exactly as before — so the pattern requires it.
		$src = (string) file_get_contents(
			dirname(__DIR__, 2) . '/class/Common/ReviewAlerts/SBR_Review_Alert_Service.php'
		);

		$this->assertMatchesRegularExpression(
			"/'rating'\s*=>\s*'booking'\s*===\s*\\\$review_provider\s*\?\s*\(float\)/",
			$src,
			'get_preview_reviews() must send Booking ratings as a float — see the test above.'
		);
		// And nothing may re-truncate them on the Booking arm.
		$this->assertDoesNotMatchRegularExpression(
			"/'rating'\s*=>\s*'booking'\s*===\s*\\\$review_provider\s*\?\s*\(int\)/",
			$src
		);
	}

	public function test_non_booking_reviews_carry_no_booking_score_keys(): void
	{
		$out = $this->format([[
			'review_id' => 'g1',
			'text'      => 'Nice',
			'rating'    => 5,
			'reviewer'  => ['name' => 'Gina', 'avatar' => ''],
			'provider'  => ['name' => 'google'],
		]]);

		$this->assertArrayNotHasKey('bookingScore', $out[0]);
		$this->assertArrayNotHasKey('bookingScoreWord', $out[0]);
	}

	public function test_booking_review_forwards_metadata_title_and_photos(): void
	{
		$out = $this->format([[
			'review_id' => 'b1',
			'text'      => 'Great stay',
			'title'     => 'It was excellent',
			'rating'    => 5,
			'reviewer'  => ['name' => 'Alison', 'avatar' => ''],
			'provider'  => ['name' => 'booking'],
			'metadata'  => [
				'pros'         => 'Spacious room',
				'cons'         => 'Pricey breakfast',
				'review_score' => 8.6,
				'review_score_word' => 'Fabulous',
				'helpful_vote_count' => 14,
			],
			'reviewer_photos' => [['90_90' => 'https://x/a.jpg']],
			'source'    => ['id' => '1377073'],
		]]);
		$r = $out[0];
		$this->assertSame('It was excellent', $r['title']);
		$this->assertSame('Spacious room', $r['metadata']['pros']);
		$this->assertSame('Pricey breakfast', $r['metadata']['cons']);
		$this->assertSame(8.6, $r['metadata']['review_score']);
		$this->assertSame(14, $r['metadata']['helpful_vote_count']);
		$this->assertNotEmpty($r['reviewer_photos']);
		$this->assertSame('1377073', $r['source']['id']);
	}

	public function test_aliexpress_review_forwards_variants_translated_flag(): void
	{
		$out = $this->format([[
			'text'     => 'Nice shirt',
			'rating'   => 4,
			'reviewer' => ['name' => 'Shopper'],
			'provider' => ['name' => 'aliexpress'],
			'metadata' => [
				'item_spec'     => 'Color:Black Size:XL',
				'translated'    => true,
				'buyer_country' => 'US',
			],
		]]);
		$md = $out[0]['metadata'];
		$this->assertSame('Color:Black Size:XL', $md['item_spec']);
		$this->assertTrue($md['translated']);
		$this->assertSame('US', $md['buyer_country']);
	}

	public function test_airbnb_review_forwards_reply(): void
	{
		$out = $this->format([[
			'text'     => 'Lovely place',
			'rating'   => 5,
			'reviewer' => ['name' => 'Jamie'],
			'provider' => ['name' => 'airbnb'],
			'response' => 'Thanks for staying!',
			'reply'    => ['name' => 'Host', 'avatar' => ''],
		]]);
		$this->assertSame('Thanks for staying!', $out[0]['response']);
		$this->assertSame('Host', $out[0]['reply']['name']);
	}

	public function test_missing_provider_data_degrades_to_safe_empties_bc(): void
	{
		// A Google/legacy review with none of the new keys must not error and
		// must keep the original contract intact.
		$out = $this->format([[
			'text'     => 'Good',
			'rating'   => 5,
			'reviewer' => ['name' => 'Sam'],
			'provider' => ['name' => 'google'],
		]]);
		$r = $out[0];
		$this->assertSame('Good', $r['text']);
		$this->assertSame([], $r['metadata']);
		$this->assertSame([], $r['reply']);
		$this->assertSame('', $r['response']);
		$this->assertSame([], $r['reviewer_photos']);
		$this->assertSame('', $r['title']);
	}
}
