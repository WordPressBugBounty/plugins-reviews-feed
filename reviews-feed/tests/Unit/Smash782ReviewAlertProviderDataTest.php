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
