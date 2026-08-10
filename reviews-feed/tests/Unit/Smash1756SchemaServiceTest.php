<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;

// home_url() / get_bloginfo() stubs live in tests/bootstrap.php (global namespace)
// so the service's unqualified calls resolve to them.

/**
 * SMASH-1756 — schema.org rich-snippet output.
 *
 * Covers the two unit-testable cores: feed detection from page content, and the
 * pure feed→schema mapper. Plus the enable gate and the JSON-LD escaping.
 */
class Smash1756SchemaServiceTest extends TestCase
{
	/** @var SBR_Schema_Service */
	private $service;

	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['wp_options_mock']  = [];
		$GLOBALS['wp_filter_mock']   = [];
		// Pin home_url so the assertion is deterministic regardless of test order
		// (a sibling test may leave $wp_home_url_mock set).
		$GLOBALS['wp_home_url_mock'] = 'https://example.test';
		$this->service = new SBR_Schema_Service();
	}

	// ---------- detect_feed_ids ----------

	public function test_detect_feed_ids_from_shortcode(): void
	{
		$this->assertSame([12], $this->service->detect_feed_ids('<p>Hi</p>[reviews-feed feed=12]'));
		$this->assertSame([12], $this->service->detect_feed_ids('[reviews-feed feed="12"]'));
	}

	public function test_detect_feed_ids_from_block_json(): void
	{
		// Gutenberg stores the block's shortcode args JSON-escaped in the comment.
		$content = '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\\"34\\" num=6"} /-->';
		$this->assertSame([34], $this->service->detect_feed_ids($content));
	}

	/**
	 * REGRESSION (found on a QA site, not by these tests): the MODERN Gutenberg block
	 * `smashballoon/reviews-feed` serialises its id as `{"feedId":"3"}` — note the
	 * closing quote of the key sits between `feedId` and the colon. The original
	 * pattern required `feed` to be followed directly by `=` or `:`, so it matched
	 * neither `feedId` nor that quote, detection returned [], and `setup()` bailed —
	 * a page using the block emitted NO schema at all, silently.
	 *
	 * That is the default way most people insert a feed, so this was the majority case.
	 */
	public function test_detect_feed_ids_from_modern_gutenberg_block(): void
	{
		$this->assertSame(
			[3],
			$this->service->detect_feed_ids('<!-- wp:smashballoon/reviews-feed {"feedId":"3"} /-->'),
			'modern block must be detected — it is the default insertion path'
		);

		// With the full attribute set the block actually writes.
		$this->assertSame(
			[41],
			$this->service->detect_feed_ids(
				'<!-- wp:smashballoon/reviews-feed {"blockId":"a1b2","feedId":"41","preview":false} /-->'
			)
		);

		// And when the whole block comment is itself JSON-escaped one level deeper.
		$this->assertSame([17], $this->service->detect_feed_ids('reviews-feed {\\"feedId\\":\\"17\\"}'));
	}

	/** An unconfigured block has `feedId: ""` — there is no feed to look up. */
	public function test_detect_feed_ids_ignores_unconfigured_modern_block(): void
	{
		$this->assertSame(
			[],
			$this->service->detect_feed_ids('<!-- wp:smashballoon/reviews-feed {"feedId":""} /-->')
		);
	}

	/** All three placement forms coexisting on one page, deduped and in order. */
	public function test_detect_feed_ids_across_all_placement_forms(): void
	{
		$content = '[reviews-feed feed=3]'
			. '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\\"7\\""} /-->'
			. '<!-- wp:smashballoon/reviews-feed {"feedId":"41"} /-->'
			. '[reviews-feed feed="3"]';

		$this->assertSame([3, 7, 41], $this->service->detect_feed_ids($content));
	}

	/**
	 * REGRESSION: the Elementor widget's control is `feed_id` (underscore), and its data
	 * lives in the `_elementor_data` postmeta rather than post_content. Verified on a
	 * local page: the feed rendered but the graph carried no rated node at all.
	 * setup() now feeds detect_feed_ids() a haystack that includes the builder data —
	 * this covers the key-name half.
	 */
	public function test_detect_feed_ids_from_elementor_widget(): void
	{
		// Shape Elementor stores (already slash-escaped as it comes out of postmeta).
		$data = '[{"id":"c1","elType":"container","elements":[{"id":"w1","elType":"widget",'
			. '"widgetType":"sb-reviews-feed","settings":{"feed_id":"183"}}]}]';

		$this->assertSame(
			[183],
			$this->service->detect_feed_ids($data),
			'Elementor control is feed_id — with an underscore'
		);
	}

	/** All four placement forms at once — shortcode, both blocks, Elementor. */
	public function test_detect_feed_ids_across_every_placement_form(): void
	{
		$content = '[reviews-feed feed=3]'
			. '<!-- wp:sbr/sbr-feed-block {"shortcodeSettings":"feed=\\"7\\""} /-->'
			. '<!-- wp:smashballoon/reviews-feed {"feedId":"41"} /-->'
			. ' {"widgetType":"sb-reviews-feed","settings":{"feed_id":"183"}}';

		$this->assertSame([3, 7, 41, 183], $this->service->detect_feed_ids($content));
	}

	/** Shortcode spacing/quoting variants a user might actually type. */
	public function test_detect_feed_ids_shortcode_variants(): void
	{
		$this->assertSame([5], $this->service->detect_feed_ids('[reviews-feed  feed = 5 ]'));
		$this->assertSame([8], $this->service->detect_feed_ids("[reviews-feed feed='8']"));
		$this->assertSame([9], $this->service->detect_feed_ids('[reviews-feed feed=9 num=5]'));
	}

	public function test_detect_feed_ids_multiple_unique(): void
	{
		$content = '[reviews-feed feed=1] and [reviews-feed feed="2"] and again [reviews-feed feed=1]';
		$this->assertSame([1, 2], $this->service->detect_feed_ids($content));
	}

	public function test_detect_feed_ids_ignores_unrelated_content(): void
	{
		// No reviews-feed marker → never scans, even with a stray feed=.
		$this->assertSame([], $this->service->detect_feed_ids('<a href="?feed=99">rss</a>'));
		$this->assertSame([], $this->service->detect_feed_ids(''));
		$this->assertSame([], $this->service->detect_feed_ids('plain content'));
	}

	// ---------- map_feed_to_schema ----------

	private function header(float $rating, int $count, string $name = 'Acme Co'): array
	{
		return [['name' => $name, 'info' => ['rating' => $rating, 'total_rating' => $count]]];
	}

	private function review(string $author, int $rating, string $text, int $time = 1600000000): array
	{
		return ['reviewer' => ['name' => $author], 'rating' => $rating, 'text' => $text, 'time' => $time];
	}

	/** Booking-shaped header: native 0-10 score in info.review_score (not info.rating). */
	private function booking_header(float $score, int $count, string $word = 'Fabulous'): array
	{
		return [['name' => 'Grand Hotel', 'info' => [
			'review_score'      => $score,
			'review_score_word' => $word,
			'review_count'      => $count,
			'total_rating'      => $count,
		]]];
	}

	/**
	 * Booking-shaped review, matching the real cached payload: the card badge score
	 * lives in metadata.review_score and is the PROPERTY's overall score (identical
	 * on every review the relay returns), while `rating` carries that reviewer's own
	 * score normalised to 0-5. Verified against live data (SMASH-1793): eight
	 * consecutive reviews of one hotel all had review_score 9.4 while their ratings
	 * were 5, 5, 4.5, 5, 5, 4, 5, 4.
	 *
	 * $own_rating defaults to a value distinct from the property score so a test can
	 * tell which one (if either) reached the markup.
	 */
	private function booking_review(string $author, float $property_score, string $text, int $time = 1600000000, float $own_rating = 5.0): array
	{
		return [
			'reviewer' => ['name' => $author],
			'provider' => ['name' => 'booking'],
			'metadata' => ['review_score' => $property_score],
			'rating'   => $own_rating,
			'text'     => $text,
			'time'     => $time,
		];
	}

	/**
	 * Booking review as the relay emits it when the source lookup FAILS. Two real
	 * shapes, both verified in sb-relay: `review_score => null` (the key is stamped
	 * unconditionally from a `?? null`, and the failure branch hardcodes null), and
	 * the key absent entirely (the whole metadata loop is skipped when
	 * getSourceInfo() returns a non-array).
	 *
	 * These are the shapes a presence-based gate misses, because isset() is false
	 * for null — they used to fall through and emit the author's invisible 0-5
	 * rating, or a fabricated 1 star when `rating` was empty too.
	 *
	 * @param mixed $score null, or a non-numeric value; omit $has_key to drop it.
	 */
	private function booking_review_degraded(string $author, $score = null, bool $has_key = true, ?float $own_rating = 4.0): array
	{
		$post = [
			'reviewer' => ['name' => $author],
			'provider' => ['name' => 'booking'],
			'text'     => 'Stayed here last week',
			'time'     => 1600000000,
		];
		if ($has_key) {
			$post['metadata'] = ['review_score' => $score];
		}
		if ($own_rating !== null) {
			$post['rating'] = $own_rating;
		}
		return $post;
	}

	public function test_map_localbusiness_with_aggregate_and_reviews(): void
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.5, 10),
			[$this->review('Jane', 5, 'Great service')],
			[['provider' => 'google']]
		);

		$this->assertCount(1, $nodes);
		$node = $nodes[0];
		$this->assertSame('LocalBusiness', $node['@type']);
		$this->assertSame('Acme Co', $node['name']);
		$this->assertSame('https://example.test/', $node['url']);
		$this->assertSame('4.5', $node['aggregateRating']['ratingValue']);
		$this->assertSame('10', $node['aggregateRating']['reviewCount']);
		$this->assertSame('5', $node['aggregateRating']['bestRating']);
		$this->assertSame('Review', $node['review'][0]['@type']);
		$this->assertSame('Jane', $node['review'][0]['author']['name']);
		$this->assertSame('5', $node['review'][0]['reviewRating']['ratingValue']);
		$this->assertSame('Great service', $node['review'][0]['reviewBody']);
		$this->assertSame(gmdate('c', 1600000000), $node['review'][0]['datePublished']);
	}

	public function test_map_uses_info_name_url_image(): void
	{
		$header = [['info' => ['name' => 'Island Villa', 'url' => 'https://airbnb.test/rooms/1', 'image' => 'https://img.test/x.jpg', 'rating' => 4.9, 'total_rating' => 26]]];
		$nodes  = $this->service->map_feed_to_schema($header, [$this->review('Zoe', 5, 'Lovely')], [['provider' => 'airbnb']]);
		$this->assertSame('LodgingBusiness', $nodes[0]['@type']);
		$this->assertSame('Island Villa', $nodes[0]['name']);
		$this->assertSame('https://airbnb.test/rooms/1', $nodes[0]['url']);
		$this->assertSame('https://img.test/x.jpg', $nodes[0]['image']);
	}

	public function test_map_product_when_woocommerce_source(): void
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.0, 3),
			[$this->review('Bob', 4, 'Good product')],
			[['provider' => 'woocommerce']]
		);
		$this->assertSame('Product', $nodes[0]['@type']);
		// Product node must not carry the site url (that's the LocalBusiness path).
		$this->assertArrayNotHasKey('url', $nodes[0]);
	}

	public function test_map_product_when_edd_source(): void
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(5.0, 2),
			[$this->review('Ann', 5, 'Nice')],
			[['provider' => 'google'], ['provider' => 'edd']]
		);
		$this->assertSame('Product', $nodes[0]['@type']);
	}

	public function test_map_product_when_aliexpress_source(): void
	{
		// AliExpress is a marketplace product → Product (SMASH-1756 entity mapping).
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.7, 12),
			[$this->review('Lee', 5, 'Fast shipping')],
			[['provider' => 'aliexpress']]
		);
		$this->assertSame('Product', $nodes[0]['@type']);
		$this->assertArrayNotHasKey('url', $nodes[0]); // Product gets no synthetic site url
	}

	public function test_map_lodgingbusiness_when_airbnb_source(): void
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.9, 26),
			[$this->review('Zoe', 5, 'Lovely stay')],
			[['provider' => 'airbnb']]
		);
		$this->assertSame('LodgingBusiness', $nodes[0]['@type']);
		// Place type with no source url → falls back to the site url.
		$this->assertSame('https://example.test/', $nodes[0]['url']);
	}

	public function test_map_booking_keeps_its_0_to_10_aggregate(): void
	{
		// AC #2 (SMASH-1793): the aggregate must NOT regress. Booking's native 0-10
		// header score is visible (feed header + every card badge) so it stays, on
		// its own scale, with bestRating 10 rather than a 5-star conversion.
		$nodes = $this->service->map_feed_to_schema(
			$this->booking_header(8.9, 40),
			[$this->booking_review('Max', 9.0, 'Great location')],
			[['provider' => 'booking']]
		);
		$node = $nodes[0];
		$this->assertSame('LodgingBusiness', $node['@type']);
		$this->assertSame('8.9', $node['aggregateRating']['ratingValue']);
		$this->assertSame('10', $node['aggregateRating']['bestRating']);
		$this->assertSame('40', $node['aggregateRating']['reviewCount']);
		$this->assertLessThanOrEqual(
			(float) $node['aggregateRating']['bestRating'],
			(float) $node['aggregateRating']['ratingValue']
		);
	}

	public function test_map_booking_emits_the_reviewers_own_score_on_the_0_to_10_scale(): void
	{
		// The Booking card's badge shows THIS reviewer's own score on Booking's 0-10
		// scale (rating-extras/booking.php → sbr_booking_review_score()). Schema marks
		// up the same value on the same scale, so bestRating is 10.
		$nodes = $this->service->map_feed_to_schema(
			$this->booking_header(8.9, 40),
			[$this->booking_review('Max', 9.0, 'Great location', 1600000000, 5.0)],
			[['provider' => 'booking']]
		);
		$review = $nodes[0]['review'][0];

		$this->assertSame('Max', $review['author']['name']);
		$this->assertSame('10', $review['reviewRating']['ratingValue']);
		$this->assertSame('10', $review['reviewRating']['bestRating']);
		$this->assertSame('1', $review['reviewRating']['worstRating']);
	}

	public function test_map_booking_never_attributes_the_property_score_to_an_author(): void
	{
		// The regression this guard exists for: three reviewers, one property score.
		// metadata.review_score is 9.4 on all three cards (the relay stamps the same
		// pair onto every one), so a path that reads it publishes an identical 9.4 for
		// each named person. Each Review must carry its OWN doubled rating instead.
		$posts = [
			$this->booking_review('Josh', 9.4, 'Fantastic', 1600000000, 5.0),
			$this->booking_review('Michael', 9.4, 'Superb', 1600000001, 4.5),
			$this->booking_review('Paul', 9.4, 'Very good', 1600000002, 4.0),
		];
		$nodes = $this->service->map_feed_to_schema($this->booking_header(9.4, 1449), $posts, [['provider' => 'booking']]);
		$node  = $nodes[0];

		$values = array_map(
			static fn(array $r): string => $r['reviewRating']['ratingValue'],
			$node['review']
		);
		$this->assertSame(['10', '9', '8'], $values);
		// Distinct per author — the whole point. A property-score read collapses these.
		$this->assertSame($values, array_values(array_unique($values)));
		// The property score is visible on the header, so it may appear on the
		// aggregate — but never inside a Review.
		$this->assertSame('9.4', $node['aggregateRating']['ratingValue']);
		$this->assertStringNotContainsString('9.4', (string) wp_json_encode($node['review']));
	}

	public function test_map_booking_publishes_the_score_the_card_actually_shows(): void
	{
		// Parity with the render layer: the same helper the template calls, so the
		// marked-up number is character-for-character what the visitor reads on the
		// badge. 4.5 stored → 9.0 shown → "9" published.
		$posts = [$this->booking_review('Eva', 9.4, 'Lovely', 1600000000, 4.5)];
		$nodes = $this->service->map_feed_to_schema($this->booking_header(9.4, 100), $posts, [['provider' => 'booking']]);

		$this->assertSame('9', $nodes[0]['review'][0]['reviewRating']['ratingValue']);
		$this->assertSame(
			(string) sbr_booking_review_score($posts[0]),
			$nodes[0]['review'][0]['reviewRating']['ratingValue']
		);
	}

	/**
	 * @dataProvider degraded_booking_payloads
	 * @param mixed $score
	 */
	public function test_map_booking_survives_a_degraded_relay_property_score($score, bool $has_key, string $case): void
	{
		// When the Booking source lookup fails the relay sends review_score as null,
		// or omits it. The per-card score never depended on that field — it comes from
		// the review's own rating — so a degraded property score must not cost us the
		// Review node. (It does cost the AggregateRating; that one is genuinely the
		// property's.) These four shapes are the ones verified in sb-relay.
		$nodes = $this->service->map_feed_to_schema(
			$this->booking_header(9.4, 1449),
			[$this->booking_review_degraded('Josh', $score, $has_key)],
			[['provider' => 'booking']]
		);

		// booking_review_degraded()'s own rating is 4.0 → 8.0 on Booking's scale.
		$this->assertSame('8', $nodes[0]['review'][0]['reviewRating']['ratingValue'], $case);
		$this->assertSame('10', $nodes[0]['review'][0]['reviewRating']['bestRating'], $case);
	}

	/** @return array<string,array{0:mixed,1:bool,2:string}> */
	public static function degraded_booking_payloads(): array
	{
		return [
			'review_score is null'         => [null, true, 'review_score => null'],
			'review_score key is absent'   => [null, false, 'metadata without review_score'],
			'review_score is non-numeric'  => ['N/A', true, 'review_score => "N/A"'],
			'review_score is zero'         => [0, true, 'review_score => 0'],
		];
	}

	public function test_rating_slot_substituted_providers_matches_the_render_layer(): void
	{
		// Drift guard. The invariant that matters is VISIBILITY: if a provider's CSS
		// hides the per-review star block, that provider's reviews carry no rating
		// the visitor can see, so schema must not claim one. Derive the list from the
		// stylesheet itself rather than from the rating-extras templates — hiding the
		// stars is what creates the defect; shipping a replacement badge is optional,
		// and keying on the template would let a CSS-only provider slip through.
		// rating.php's docblock already names Facebook as a planned second case, so
		// this is a live risk rather than a hypothetical one.
		$root = dirname(__DIR__, 2);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet on disk, not a remote URL
		$css = (string) file_get_contents($root . '/assets/css/sbr-styles.css');

		// Match on the rule BODY containing display:none, not on selector presence.
		// The stylesheet already carries cosmetic provider-scoped rating rules (e.g.
		// `.sbr-provider-booking .sb-item-rating { padding-top: 0 }`), so a selector
		// match alone would fail this test for a harmless rule — and the failure
		// message would push the next author to add that provider to the constant,
		// silently stripping its Review nodes. Reading the body keeps the guard tied
		// to the actual invariant: the stars are not visible. Scanning the whole
		// selector text also covers `>` combinators and comma-separated lists.
		// Strip comments FIRST. The selector capture below reaches back to the previous
		// `}`, which would otherwise swallow the comment block above a rule — and this
		// stylesheet's comments name providers in prose ("Facebook recommendation
		// chip"). A slug mentioned only in a comment would be extracted as
		// substituting, and this test's failure message would then tell the next
		// author to add it to the constant, silently stripping that provider's Review
		// nodes in production. The guard must not be able to cause the bug it guards.
		$css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

		$substituting = [];
		preg_match_all('/([^{}]*sb-item-rating-ctn[^{}]*)\{([^}]*)\}/i', $css, $rules, PREG_SET_ORDER);
		foreach ($rules as $rule) {
			if (preg_match('/display\s*:\s*none/i', $rule[2]) !== 1) {
				continue;
			}
			if (preg_match_all('/sbr-provider-([a-z0-9_-]+)/i', $rule[1], $found)) {
				foreach ($found[1] as $provider) {
					$substituting[] = strtolower($provider);
				}
			}
		}
		$substituting = array_values(array_unique($substituting));
		sort($substituting);

		$declared = (new \ReflectionClass(SBR_Schema_Service::class))
			->getConstant('RATING_SLOT_SUBSTITUTED_PROVIDERS');
		$declared = is_array($declared) ? $declared : [];
		sort($declared);

		$this->assertSame(
			$substituting,
			$declared,
			'A provider that replaces the star slot must be listed in '
			. 'RATING_SLOT_SUBSTITUTED_PROVIDERS, otherwise its reviews emit a rating '
			. 'that is not visible on the card.'
		);
	}

	public function test_map_booking_never_fabricates_a_one_star_review(): void
	{
		// No score from the relay AND no rating on the review — nothing is visible on
		// the card, so nothing is attributable. Note this can't go through
		// Parser::get_rating(), which returns 1 for an unrated review and would
		// publish a fabricated 1-star attributed by name to a real person;
		// sbr_booking_review_score() returns 0 for a missing rating and we drop it.
		$nodes = $this->service->map_feed_to_schema(
			$this->booking_header(9.4, 1449),
			[$this->booking_review_degraded('Josh', null, false, null)],
			[['provider' => 'booking']]
		);

		$encoded = (string) wp_json_encode($nodes[0]);
		$this->assertArrayNotHasKey('review', $nodes[0]);
		$this->assertStringNotContainsString('Josh', $encoded);
	}

	public function test_map_booking_without_a_count_still_emits_its_reviews(): void
	{
		// review_score present but no review_count/total_rating → no AggregateRating
		// (Google needs a count). The per-reviewer scores are independent of that
		// count, so the Review nodes still carry the node.
		$header = [['name' => 'Hotel', 'info' => ['review_score' => 8.5, 'review_score_word' => 'Great']]];
		$nodes  = $this->service->map_feed_to_schema($header, [$this->booking_review('Al', 8.5, 'Nice')], [['provider' => 'booking']]);

		$this->assertArrayNotHasKey('aggregateRating', $nodes[0]);
		$this->assertSame('10', $nodes[0]['review'][0]['reviewRating']['ratingValue']);
	}

	public function test_map_booking_on_lite_emits_nothing(): void
	{
		// On Lite the HEADER shows no Booking rating (templates/frontend/lite/header.php
		// emits none, and the pro one is filtered out of the Free zip). The per-card
		// badge does still render there — it resolves via $generic_path — so this pins a
		// deliberate under-claim, not "nothing is visible": the schema stays silent
		// rather than asserting a rating whose surface we don't fully control on Lite.
		// See substituted_slot_rating()'s docblock.
		$nodes = $this->service->map_feed_to_schema(
			$this->booking_header(8.9, 40),
			[$this->booking_review('Max', 9.0, 'Great')],
			[['provider' => 'booking']],
			false
		);
		$this->assertSame([], $nodes);
	}

	public function test_map_mixed_feed_keeps_each_review_on_its_own_scale(): void
	{
		// Mixed feed: header is not booking-only → 5-star aggregate. Each card keeps
		// the scale it renders in, and every Review carries its own bestRating, so a
		// 10-scale Booking review and a 5-scale Google review coexist in one node.
		$header = [['name' => 'Hotel', 'info' => ['rating' => 4.5, 'total_rating' => 10]]];
		$posts  = [
			$this->booking_review('Bo', 9.0, 'Lovely hotel', 1600000000, 4.0),
			$this->review('Gina', 5, 'Great'),
		];
		$nodes = $this->service->map_feed_to_schema($header, $posts, [['provider' => 'booking'], ['provider' => 'google']]);
		$node  = $nodes[0];

		$this->assertSame('5', $node['aggregateRating']['bestRating']);
		$this->assertCount(2, $node['review']);

		$this->assertSame('Bo', $node['review'][0]['author']['name']);
		$this->assertSame('8', $node['review'][0]['reviewRating']['ratingValue']);
		$this->assertSame('10', $node['review'][0]['reviewRating']['bestRating']);

		$this->assertSame('Gina', $node['review'][1]['author']['name']);
		$this->assertSame('5', $node['review'][1]['reviewRating']['ratingValue']);
		$this->assertSame('5', $node['review'][1]['reviewRating']['bestRating']);
	}

	public function test_map_product_wins_over_lodging_in_mixed_feed(): void
	{
		// Precedence: a purchasable product source outranks a lodging source.
		$nodes = $this->service->map_feed_to_schema(
			$this->header(4.5, 8),
			[$this->review('Sam', 4, 'Ok')],
			[['provider' => 'airbnb'], ['provider' => 'aliexpress']]
		);
		$this->assertSame('Product', $nodes[0]['@type']);
	}

	public function test_map_returns_empty_without_rating_or_reviews(): void
	{
		// No aggregate (0/0) and no reviews → nothing to emit.
		$this->assertSame([], $this->service->map_feed_to_schema($this->header(0, 0), [], [['provider' => 'google']]));
	}

	public function test_standard_5star_node_carries_google_required_props(): void
	{
		// Structural gate for the Google Rich Results DoD on a standard 5-star node
		// (Product/LocalBusiness): AggregateRating in range + a genuine per-review
		// reviewRating. (Booking is a distinct contract — 0-10 aggregate, no
		// per-review rating — covered by the Booking tests above.)
		$node = $this->service->map_feed_to_schema(
			$this->header(4.6, 15),
			[$this->review('Dee', 5, 'Great')],
			[['provider' => 'woocommerce']]
		)[0];
		$this->assertArrayHasKey('@type', $node);
		$this->assertArrayHasKey('name', $node);
		// AggregateRating: ratingValue + reviewCount + best/worst bounds.
		$agg = $node['aggregateRating'];
		$this->assertSame('AggregateRating', $agg['@type']);
		foreach (['ratingValue', 'reviewCount', 'bestRating', 'worstRating'] as $k) {
			$this->assertArrayHasKey($k, $agg);
			$this->assertNotSame('', (string) $agg[$k]);
		}
		// worstRating <= ratingValue <= bestRating (Google rejects out-of-range).
		$this->assertGreaterThanOrEqual((float) $agg['worstRating'], (float) $agg['ratingValue']);
		$this->assertLessThanOrEqual((float) $agg['bestRating'], (float) $agg['ratingValue']);
		// Review: author + reviewRating.ratingValue.
		$rev = $node['review'][0];
		$this->assertSame('Review', $rev['@type']);
		$this->assertArrayHasKey('name', $rev['author']);
		$this->assertArrayHasKey('ratingValue', $rev['reviewRating']);
	}

	public function test_map_emits_reviews_even_without_aggregate(): void
	{
		// Reviews present but no usable aggregate → still emit (review-only snippet).
		$nodes = $this->service->map_feed_to_schema($this->header(0, 0), [$this->review('Kim', 5, 'Loved it')], [['provider' => 'google']]);
		$this->assertCount(1, $nodes);
		$this->assertArrayNotHasKey('aggregateRating', $nodes[0]);
		$this->assertCount(1, $nodes[0]['review']);
	}

	public function test_map_caps_reviews_at_max(): void
	{
		$posts = [];
		for ($i = 0; $i < SBR_Schema_Service::MAX_REVIEWS + 5; $i++) {
			$posts[] = $this->review('User' . $i, 5, 'Review ' . $i);
		}
		$nodes = $this->service->map_feed_to_schema($this->header(4.8, 100), $posts, [['provider' => 'google']]);
		$this->assertCount(SBR_Schema_Service::MAX_REVIEWS, $nodes[0]['review']);
		// AggregateRating still carries the true total, not the capped sample.
		$this->assertSame('100', $nodes[0]['aggregateRating']['reviewCount']);
	}

	public function test_map_skips_non_array_and_empty_reviews(): void
	{
		$posts = ['not-an-array', $this->review('', 5, ''), $this->review('Real', 4, 'Words')];
		$nodes = $this->service->map_feed_to_schema($this->header(4.0, 5), $posts, [['provider' => 'google']]);
		$this->assertCount(1, $nodes[0]['review']);
		$this->assertSame('Real', $nodes[0]['review'][0]['author']['name']);
	}

	// ---------- is_enabled ----------

	public function test_is_enabled_defaults_on_when_unset(): void
	{
		$GLOBALS['wp_options_mock']['sbr_settings'] = [];
		$this->assertTrue($this->service->is_enabled());
	}

	public function test_is_enabled_respects_off(): void
	{
		$GLOBALS['wp_options_mock']['sbr_settings'] = ['enableSchema' => false];
		$this->assertFalse($this->service->is_enabled());
	}

	public function test_is_enabled_filter_overrides(): void
	{
		$GLOBALS['wp_options_mock']['sbr_settings'] = ['enableSchema' => true];
		$GLOBALS['wp_filter_mock']['sbr_enable_schema'] = false;
		$this->assertFalse($this->service->is_enabled());
	}

	// ---------- L3 sink: script-breakout neutralized at the shared mapper ----------

	private const BREAKOUT_AUTHOR = 'Mallory</script><script>alert(1)</script>';
	private const BREAKOUT_BODY   = 'nice </script> try';

	private function breakoutNodes(): array
	{
		$nodes = $this->service->map_feed_to_schema(
			$this->header(5.0, 1),
			[$this->review(self::BREAKOUT_AUTHOR, 5, self::BREAKOUT_BODY)],
			[['provider' => 'google']]
		);
		$ref = new \ReflectionProperty(SBR_Schema_Service::class, 'nodes');
		$ref->setAccessible(true);
		$ref->setValue($this->service, $nodes);

		return $nodes;
	}

	public function test_map_preserves_review_text_without_truncation(): void
	{
		// Full text is kept (parity with the visible feed) — not tag-stripped,
		// so a bare `<` in legitimate review text is never truncated.
		$review = $this->service->map_feed_to_schema(
			$this->header(5.0, 1),
			[$this->review('Al <3 fans', 5, 'cheaper than < $10 elsewhere')],
			[['provider' => 'google']]
		)[0]['review'][0];

		$this->assertSame('Al <3 fans', $review['author']['name']);
		$this->assertSame('cheaper than < $10 elsewhere', $review['reviewBody']);
	}

	public function test_map_decodes_entities_into_the_json_ld_data_sink(): void
	{
		// The regression this pins: SMASH-1795 removed the read-path decode in
		// Parser::get_text()/get_reviewer_name() because their other consumers are HTML
		// sinks, where the browser resolves a character reference. JSON-LD is not one —
		// inside <script type="application/ld+json"> nothing resolves it — so without a
		// decode at this boundary a Danish/German row written by one of the non-decoding
		// writers (Woo/EDD comment_content, bulk updaters, review form) publishes
		// "S&oslash;ren" as the author's name in the rich snippet while the feed itself
		// renders "Søren" correctly.
		$review = $this->service->map_feed_to_schema(
			$this->header(4.8, 12),
			[$this->review('S&oslash;ren M&uuml;ller', 5, 'Bedste sm&oslash;rrebr&oslash;d &amp; kaffe i Caf&eacute;')],
			[['provider' => 'google']]
		)[0]['review'][0];

		$this->assertSame('Søren Müller', $review['author']['name']);
		$this->assertSame('Bedste smørrebrød & kaffe i Café', $review['reviewBody']);
	}

	public function test_map_decodes_the_business_name_too(): void
	{
		$node = $this->service->map_feed_to_schema(
			$this->header(4.8, 12, 'Caf&eacute; Nord &amp; Co'),
			[$this->review('Ann', 5, 'Lovely')],
			[['provider' => 'google']]
		)[0];

		$this->assertSame('Café Nord & Co', $node['name']);
	}

	public function test_map_survives_a_non_string_business_name(): void
	{
		// Parser::get_business_name() has no return type and its first branch returns
		// business.name verbatim with no is_string() guard, so a cached feed can hand
		// this method an array. Decoding it uncast would raise a PHP 8 TypeError inside
		// wp_head — a white page instead of a missing snippet.
		$nodes = $this->service->map_feed_to_schema(
			[['business' => ['name' => ['unexpected' => 'array']], 'info' => ['rating' => 4.5, 'total_rating' => 3]]],
			[$this->review('Ann', 5, 'Lovely')],
			[['provider' => 'google']]
		);

		$this->assertIsArray($nodes);
		$this->assertIsString($nodes[0]['name']);
		// A bare (string) cast would have published the literal "Array" here; the
		// non-scalar is rejected so the site-name fallback takes over instead.
		$this->assertNotSame('Array', $nodes[0]['name']);
	}

	public function test_map_decode_does_not_truncate_a_bare_angle_bracket(): void
	{
		// Guards the fix against its own cure: wp_strip_all_tags() would have been the
		// obvious companion to the decode, and it eats everything after an unclosed '<'.
		// Parity with the visible feed matters more here than tidy markup.
		$review = $this->service->map_feed_to_schema(
			$this->header(5.0, 1),
			[$this->review('Al <3 fans', 5, 'cheaper than < $10 elsewhere')],
			[['provider' => 'google']]
		)[0]['review'][0];

		$this->assertSame('Al <3 fans', $review['author']['name']);
		$this->assertSame('cheaper than < $10 elsewhere', $review['reviewBody']);
	}

	public function test_print_json_ld_path_b_hex_escapes_breakout(): void
	{
		$this->breakoutNodes();

		ob_start();
		$this->service->print_json_ld();
		$out = ob_get_clean();

		// Only the wrapper's own tag pair; the adversarial </script> in the data
		// is hex-escaped (JSON_HEX_TAG), not emitted literally.
		$this->assertSame(1, substr_count($out, '<script type="application/ld+json"'));
		$this->assertSame(1, substr_count($out, '</script>'));
		$this->assertStringNotContainsString('</script><script>alert(1)', $out);
		$this->assertStringContainsString('<', $out); // JSON_HEX_TAG escaped the data's `<`
	}

	public function test_merge_into_aioseo_neutralizes_the_smart_tag_trigger(): void
	{
		// AIOSEO resolves #<tag> in every graph string, AFTER this filter returns and
		// after its own sanitising: Schema/Helpers.php:82 (our filter) -> :83
		// cleanAndParseData -> :54 strip -> :57 replaceTags. #custom_field-<key> resolves
		// post meta (Utils/Tags.php:1360, :1448) and #featured_image returns a raw <img>
		// (:1090), so an unauthenticated reviewer could have post meta printed in <head>.
		// We drop the trigger rather than enumerating ~70 tag ids.
		$nodes = $this->service->map_feed_to_schema(
			[[
				'name' => 'Acme #custom_field-_owner_email',
				'info' => [
					'rating' => 5.0, 'total_rating' => 1,
					'url'    => 'https://shop.test/p#custom_field-_secret',
					'image'  => 'https://shop.test/i.png#description',
				],
			]],
			[$this->review('Mallory #author_name', 5, 'see ##custom_field-_secret and ###featured_image')],
			[['provider' => 'google']]
		);
		$ref = new \ReflectionProperty(SBR_Schema_Service::class, 'nodes');
		$ref->setAccessible(true);
		$ref->setValue($this->service, $nodes);

		$graph  = $this->service->merge_into_aioseo([['@type' => 'WebPage']]);
		$node   = $graph[1];
		$review = $node['review'][0];

		// No trigger left anywhere — doubled and tripled included, since there is no
		// pattern to bypass.
		foreach ([$node['name'], $node['url'], $node['image'], $review['author']['name'], $review['reviewBody']] as $v) {
			$this->assertStringNotContainsString('#', $v, "trigger survived in: $v");
		}
		// Free text keeps its words. URLs are truncated at the fragment, which still
		// addresses the same resource — encoding the '#' as %23 would NOT: per RFC 3986
		// that is a literal '#' in the path, so the URL would 404.
		$this->assertStringContainsString('custom_field-_secret', $review['reviewBody']);
		$this->assertSame('https://shop.test/p', $node['url']);
		$this->assertSame('https://shop.test/i.png', $node['image']);
	}

	/**
	 * @dataProvider urlKeyCases
	 */
	public function test_url_keyed_values_are_truncated_at_the_fragment(string $in, string $expected): void
	{
		$m = new \ReflectionMethod(SBR_Schema_Service::class, 'neutralize_smart_tags');
		$m->setAccessible(true);

		$this->assertSame($expected, $m->invoke($this->service, $in, 'url'));
	}

	public static function urlKeyCases(): array
	{
		return [
			'fragment after a path' => ['https://x.test/p#REVIEWS', 'https://x.test/p'],
			'google lrd fragment'   => ['https://maps.test/x#lrd=0xabc', 'https://maps.test/x'],
			'no fragment'           => ['https://x.test/p', 'https://x.test/p'],
			// Not URLs at all — emit nothing rather than a bogus relative URL, and never
			// a live tag. AIOSEO drops empty graph values.
			'fragment only, tag'    => ['#post_title', ''],
			'fragment only, bare'   => ['#', ''],
			'doubled trigger only'  => ['##custom_field-_secret', ''],
		];
	}

	public function test_merge_into_aioseo_path_a_no_raw_angle_bracket(): void
	{
		// Breakout payloads span all vectors: entity NAME (`</script`), review
		// author (`<!--<script` double-escape), and a legit `< $10` in the body.
		$nodes = $this->service->map_feed_to_schema(
			$this->header(5.0, 1, 'Acme</script><script>alert(1)</script>'),
			[$this->review('Mallory<!--<script>', 5, 'cheaper than < $10')],
			[['provider' => 'google']]
		);
		$ref = new \ReflectionProperty(SBR_Schema_Service::class, 'nodes');
		$ref->setAccessible(true);
		$ref->setValue($this->service, $nodes);

		$graph = $this->service->merge_into_aioseo([['@type' => 'WebPage']]);

		// Structural: our node is appended and its keys survive array_map.
		$this->assertSame('LocalBusiness', $graph[1]['@type']);

		// Encode as AIOSEO's worst case: unescaped slashes, NO JSON_HEX_TAG
		// (Schema/Helpers.php:106). No raw `<` may reach the <script> context —
		// this covers </script, <script and <!--<script at once.
		$json = json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('<', $json);
		// Legit text is preserved, just entity-escaped (recoverable, not truncated).
		$this->assertStringContainsString('&lt; $10', $json);
		$this->assertStringContainsString('Acme&lt;', $json);
	}
}
