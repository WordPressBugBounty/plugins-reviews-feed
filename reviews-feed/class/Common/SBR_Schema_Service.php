<?php

/**
 * Schema.org rich-snippet output for Reviews Feed (SMASH-1756 / rock SBR-146).
 *
 * Emits Review / AggregateRating / Product|LocalBusiness JSON-LD for feeds on
 * the current page so reviews can surface as star rich snippets in Google.
 *
 * Two emission paths, same schema:
 *  - Path A (AIOSEO active): merge our nodes into AIOSEO's `@graph` via the
 *    `aioseo_schema_output` filter (keeps AIOSEO's own graph properties).
 *  - Path B (no AIOSEO): print our own `<script type="application/ld+json">`
 *    in `wp_head`, tag/amp-hardened.
 *
 * @package SmashBalloon\Reviews\Common
 */

namespace SmashBalloon\Reviews\Common;

if (! defined('ABSPATH')) {
	exit;
}

use Smashballoon\Stubs\Services\ServiceProvider;

class SBR_Schema_Service extends ServiceProvider
{
	/**
	 * Max reviews emitted per feed node. Keeps the JSON-LD payload bounded on
	 * high-review feeds; Google only needs a representative sample for the
	 * review snippet, and the AggregateRating carries the true totals.
	 */
	public const MAX_REVIEWS = 20;

	/**
	 * Providers whose source is a purchasable product → schema.org Product.
	 * AliExpress is a marketplace product listing, like WooCommerce/EDD store items.
	 * Product AggregateRating is the type Google reliably renders review stars for.
	 *
	 * @var string[]
	 */
	private const PRODUCT_PROVIDERS = ['woocommerce', 'edd', 'aliexpress'];

	/**
	 * Providers whose source is a place to stay → schema.org LodgingBusiness (a
	 * LocalBusiness subtype). More accurate than a bare LocalBusiness for Airbnb
	 * listings and Booking.com properties.
	 *
	 * @var string[]
	 */
	private const LODGING_PROVIDERS = ['airbnb', 'booking'];

	/**
	 * Providers whose card replaces the per-reviewer star rating with a different
	 * element, so their Review node's rating can't be read off the stars. What the
	 * replacement badge actually shows decides the rating — substituted_slot_rating().
	 *
	 * Booking substitutes the stars with a score badge
	 * (templates/.../rating-extras/booking.php, dispatched by slug at
	 * templates/.../rating.php:40) and hides the star block outright —
	 * `.sbr-provider-booking .sb-item-rating-ctn { display: none !important }`
	 * in assets/css/sbr-styles.css, applied to EVERY Booking card regardless of
	 * whether the badge itself renders.
	 *
	 * Keyed on the provider slug because that is the axis every other Booking
	 * behaviour already uses (the template dispatch above, review-alerts
	 * popup.php, and both sbr-review-alerts.js layouts).
	 *
	 * @var string[]
	 */
	private const RATING_SLOT_SUBSTITUTED_PROVIDERS = ['booking'];

	/**
	 * Node keys whose values are URLs, so the value is truncated at the fragment rather
	 * than having the `#` stripped — see neutralize_smart_tags(). Only the keys this
	 * mapper actually emits; add one here if map_feed_to_schema() starts emitting it.
	 *
	 * @var string[]
	 */
	private const URL_KEYS = ['url', 'image'];

	/**
	 * Schema graph nodes computed for the current request.
	 *
	 * @var array<int,array>
	 */
	private $nodes = [];

	/**
	 * Request-level memo of built feed objects, keyed by feed ID. Building a feed
	 * calls get_set_cache() (a potential relay refresh), so it must run at most
	 * once per feed per request. detect_feed_ids() already de-dupes IDs on the
	 * current path, so this is defence for any repeat/future caller (e.g. a filter
	 * that re-runs the build). `false` memoizes a feed that resolved to null so a
	 * dead ID isn't retried.
	 *
	 * @var array<int,Feed|false>
	 */
	private $feed_memo = [];

	public function register(): void
	{
		// template_redirect: front-end only, after the main query is resolved
		// (so is_singular()/get_post() are reliable) but before wp_head + the_content.
		add_action('template_redirect', [$this, 'setup']);
	}

	/**
	 * Detect feeds on the current page, build their schema nodes, and wire the
	 * correct emission path. No-ops (early return) leave the page untouched.
	 */
	public function setup(): void
	{
		if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		if (! $this->is_enabled() || ! is_singular()) {
			return;
		}

		$post = get_post();
		if (! $post instanceof \WP_Post) {
			return;
		}

		$feed_ids = $this->detect_feed_ids($this->placement_haystack($post));
		if (empty($feed_ids)) {
			return;
		}

		$this->nodes = $this->build_all_nodes($feed_ids);
		if (empty($this->nodes)) {
			return;
		}

		if (function_exists('aioseo')) {
			// Path A — hand our nodes to AIOSEO's schema generator.
			add_filter('aioseo_schema_output', [$this, 'merge_into_aioseo']);
		} else {
			// Path B — emit our own JSON-LD.
			add_action('wp_head', [$this, 'print_json_ld'], 20);
		}
	}

	/**
	 * Global on/off (default ON). Stored in the shared `sbr_settings` option
	 * alongside the other plugin-level toggles; overridable via filter so it can
	 * be controlled before the settings-page checkbox lands.
	 */
	public function is_enabled(): bool
	{
		$settings = get_option('sbr_settings', []);
		$enabled  = ! is_array($settings) || ! array_key_exists('enableSchema', $settings)
			? true
			: ! empty($settings['enableSchema']);

		return (bool) apply_filters('sbr_enable_schema', $enabled);
	}

	/**
	 * Everything on this page that could reference a feed.
	 *
	 * `post_content` alone is not enough: Elementor keeps its layout in the
	 * `_elementor_data` postmeta, so a feed placed with the Elementor widget is
	 * invisible to a content-only scan — verified on a local page where the feed
	 * rendered 61 review cards while the schema graph carried no rated node at all.
	 * The widget's control is `feed_id`, which detect_feed_ids() also matches.
	 *
	 * Cheap: postmeta for a singular post is already primed by the main query, and
	 * the string is only appended when the meta actually exists.
	 *
	 * @param  \WP_Post $post
	 * @return string
	 */
	private function placement_haystack(\WP_Post $post): string
	{
		$content = (string) $post->post_content;

		$elementor = get_post_meta($post->ID, '_elementor_data', true);
		if (is_string($elementor) && $elementor !== '') {
			$content .= ' ' . $elementor;
		}

		return $content;
	}

	/**
	 * Extract Reviews Feed feed IDs referenced on the page. Covers all three ways a
	 * feed can be placed:
	 *   - `[reviews-feed feed=N]` shortcode (bare, quoted or single-quoted)
	 *   - legacy `sbr/sbr-feed-block`, whose `shortcodeSettings` embeds `feed=\"N\"`
	 *   - modern `smashballoon/reviews-feed` block, serialised as `{"feedId":"N"}`
	 *   - Elementor widget `sb-reviews-feed`, whose control is `feed_id` and whose data
	 *     lives in the `_elementor_data` postmeta (see placement_haystack())
	 * Gated on a marker so an unrelated `feed=` elsewhere on the page can't trigger
	 * a lookup. An unconfigured block (`"feedId":""`) correctly yields nothing.
	 *
	 * @param  string $content Raw post content.
	 * @return int[]           Unique positive feed IDs.
	 */
	public function detect_feed_ids($content): array
	{
		if (! is_string($content) || $content === '') {
			return [];
		}
		if (strpos($content, 'reviews-feed') === false && strpos($content, 'sbr/sbr-feed-block') === false) {
			return [];
		}

		$ids = [];
		// Every way a feed can be placed on a page serialises its id differently, and
		// missing one means the page silently gets no schema at all:
		//   shortcode      [reviews-feed feed=3]              -> feed=3
		//   legacy block   sbr/sbr-feed-block                 -> shortcodeSettings with feed=\"3\"
		//   modern block   smashballoon/reviews-feed          -> {"feedId":"3"}
		// The `feedId` form was missed originally (the optional `Id` below), so a feed
		// inserted with the modern Gutenberg block emitted nothing. Caught on a QA site
		// after the demo page was switched from the shortcode to the block.
		if (preg_match_all('/feed(?:Id|_id)?\\\\?["\']?\s*[=:]\s*\\\\?["\']?(\d+)/i', $content, $matches)) {
			foreach ($matches[1] as $raw) {
				$id = (int) $raw;
				if ($id > 0) {
					$ids[$id] = $id;
				}
			}
		}

		return array_values($ids);
	}

	/**
	 * Build schema nodes for every detected feed.
	 *
	 * @param  int[] $feed_ids
	 * @return array<int,array>
	 */
	private function build_all_nodes(array $feed_ids): array
	{
		$all    = [];
		$is_pro = Util::sbr_is_pro();
		foreach ($feed_ids as $feed_id) {
			// Visibility gate, BEFORE build. The empty/error path is already covered —
			// a feed that resolves to null (missing/no sources) or produces no rating
			// (map returns []) emits nothing. But schema sits in <head>, before the
			// feed renders in <body>, so a body-level gate (membership/paywall that
			// strips the shortcode for this visitor) can't be detected server-side.
			// This filter lets such integrations suppress schema per feed — checked
			// before build_feed_object() so a suppressed feed never pays its
			// get_set_cache() (a potential relay refresh).
			if (! apply_filters('sbr_schema_emit_for_feed', true, $feed_id)) {
				continue;
			}
			$feed = $this->build_feed_object($feed_id);
			if ($feed === null) {
				continue;
			}
			$settings = $feed->get_settings();
			$sources  = isset($settings['sources']) && is_array($settings['sources']) ? $settings['sources'] : [];

			$posts  = (array) $feed->get_posts();
			// Backfill per-source counts from the actual reviews BEFORE computing the
			// aggregate — the visible feed header does the same (FeedDisplay), so the
			// schema reviewCount/ratingValue must match what the page shows (Google
			// flags structured-data ≠ visible-content). Matters for under-reporting
			// sources like Facebook in multi-source feeds (SMASH-1583).
			$header = (new Parser())->backfill_review_counts((array) $feed->get_header_data(), $posts);

			foreach ($this->map_feed_to_schema($header, $posts, $sources, $is_pro) as $node) {
				$all[] = $node;
			}
		}

		return $all;
	}

	/**
	 * Resolve a feed ID to a populated Feed (same pipeline the shortcode render
	 * uses). Returns null when the feed is missing, has no sources, or errors —
	 * so schema is only ever built from a real, source-backed feed.
	 *
	 * @param  int $feed_id
	 * @return Feed|null
	 */
	private function build_feed_object($feed_id)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}
		if (array_key_exists($feed_id, $this->feed_memo)) {
			return $this->feed_memo[$feed_id] === false ? null : $this->feed_memo[$feed_id];
		}

		$settings = SBR_Settings::get_settings_by_feed_id($feed_id, false, false);
		if (empty($settings) || ! is_array($settings)) {
			$this->feed_memo[$feed_id] = false;
			return null;
		}

		// Mirror the canonical render path (SBR_Feed_Builder::219 / Pro
		// ShortcodeService): on Pro, build Pro\Feed + Pro\FeedCache so the
		// WPML-suffixed cache key matches the row the rendered feed reads/writes
		// (Pro\Feed::init() calls FeedCache::set_wpml). Hardcoding the Common\*
		// classes here would hit the unsuffixed row on Pro+WPML — a duplicate
		// remote refresh, and schema built from a different language than the
		// page shows (the mismatch the line-below comment warns about).
		if (Util::sbr_is_pro()) {
			$feed = new \SmashBalloon\Reviews\Pro\Feed(
				$settings,
				$feed_id,
				new \SmashBalloon\Reviews\Pro\FeedCache((string) $feed_id, 2 * DAY_IN_SECONDS)
			);
		} else {
			$feed = new Feed($settings, $feed_id, new FeedCache((string) $feed_id, 2 * DAY_IN_SECONDS));
		}
		$feed->init();

		$hydrated = $feed->get_settings();
		if (empty($hydrated['sources'])) {
			$this->feed_memo[$feed_id] = false;
			return null;
		}

		$feed->get_set_cache();

		$this->feed_memo[$feed_id] = $feed;
		return $feed;
	}

	/**
	 * Pure mapper: feed data → schema.org graph nodes. Extracted so it can be
	 * unit-tested without WordPress. Returns [] when there's no rating data to
	 * emit (Google needs a rating/review to render a snippet).
	 *
	 * @param  array $header_data Per-source aggregate (Feed::get_header_data()).
	 * @param  array $posts       Review list (Feed::get_posts()).
	 * @param  array $sources     Hydrated sources (for the entity-type decision).
	 * @param  bool  $is_pro      Whether the Pro build is active. Booking's native
	 *                            0-10 score renders only in the Pro header template,
	 *                            so it's only emitted as schema on Pro (match visible).
	 * @return array<int,array>
	 */
	public function map_feed_to_schema(array $header_data, array $posts, array $sources, bool $is_pro = true): array
	{
		$parser = new Parser();
		$avg    = $parser->get_average_rating($header_data);
		$count  = $parser->get_num_ratings($header_data);

		$identity = $this->entity_identity($header_data, $parser);
		if ($identity['name'] === '') {
			return [];
		}
		$node = [
			'@type' => $this->entity_type($sources),
			'name'  => $identity['name'],
		];
		if ($identity['url'] !== '') {
			$node['url'] = $identity['url'];
		} elseif ($node['@type'] !== 'Product') {
			// Place types (LocalBusiness/LodgingBusiness) want a url; fall back to
			// the site. A Product without a real url stays url-less rather than
			// pointing a product node at the site root.
			$node['url'] = home_url('/');
		}
		if ($identity['image'] !== '') {
			$node['image'] = $identity['image'];
		}

		// AGGREGATE scale must match the visible header. A Booking-ONLY feed shows
		// Booking's native 0-10 score (info.review_score, count-weighted) via
		// FeedDisplay::get_booking_header_rating() — so bestRating 10, not 5. That
		// header renders only in the Pro template, so the 0-10 aggregate is emitted
		// only on Pro (Lite/downgraded shows no Booking rating). Everything else
		// (incl. mixed feeds, where the header falls back to a 5-star average) uses
		// the 5-star weighted average.
		$booking = FeedDisplay::get_booking_header_rating($header_data);
		if (! empty($booking['is_booking_only']) && $is_pro && (float) $booking['score'] > 0) {
			$rating_value = (float) $booking['score'];
			$best_rating  = 10;
		} else {
			$rating_value = is_numeric($avg) ? (float) $avg : 0.0;
			$best_rating  = 5;
		}
		// Clamp guarantees ratingValue <= bestRating — never emit an out-of-range
		// rating that Google would reject.
		$best_rating = max($best_rating, (int) ceil($rating_value), 1);

		if ($rating_value > 0 && is_numeric($count) && (int) $count > 0) {
			$node['aggregateRating'] = [
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $rating_value,
				'reviewCount' => (string) (int) $count,
				'bestRating'  => (string) $best_rating,
				'worstRating' => '1',
			];
		}

		// PER-REVIEW rating is resolved per review, not per feed: in a mixed feed the
		// star-provider cards emit their own 1-5 rating while the Booking cards emit
		// their own 0-10 score. Each Review carries its own bestRating, so the two
		// scales coexist. Reviews we can't honestly rate are never attached.
		$reviews = $this->map_reviews($posts, $parser, $is_pro);
		if (! empty($reviews)) {
			$node['review'] = $reviews;
		}

		// Emit only if the node carries an actual rating — an AggregateRating or at
		// least one (always-rated) Review. Otherwise there's nothing for a rich
		// result, so emit nothing rather than empty markup.
		if (empty($node['aggregateRating']) && empty($node['review'])) {
			return [];
		}

		return [$node];
	}

	/**
	 * Decide the schema.org entity type from the feed's sources. Precedence:
	 * Product (purchasable item — Google renders self-hosted AggregateRating for
	 * it) > LodgingBusiness (place to stay) > LocalBusiness (default).
	 *
	 * NOTE (self-serving policy): for LocalBusiness/LodgingBusiness, Google may not
	 * render a rich-result star for a *self-serving* AggregateRating (a site rating
	 * its own business). We still emit valid markup — the type is correct and the
	 * data matches the visible feed — and the type stays within Product +
	 * LocalBusiness(-subtype) as the rock scopes it. Product sources are unaffected.
	 *
	 * @param  array  $sources
	 * @return string 'Product' | 'LodgingBusiness' | 'LocalBusiness'
	 */
	private function entity_type(array $sources): string
	{
		$has_lodging = false;
		foreach ($sources as $source) {
			$provider = isset($source['provider']) ? strtolower((string) $source['provider']) : '';
			if (in_array($provider, self::PRODUCT_PROVIDERS, true)) {
				return 'Product';
			}
			if (in_array($provider, self::LODGING_PROVIDERS, true)) {
				$has_lodging = true;
			}
		}

		return $has_lodging ? 'LodgingBusiness' : 'LocalBusiness';
	}

	/**
	 * Resolve the entity's name / url / image from the per-source header. The
	 * business/product identity lives in each source's `info` (name/url/image);
	 * falls back to the top-level business name, then the site name.
	 *
	 * @param  array  $header_data
	 * @param  Parser $parser
	 * @return array{name:string,url:string,image:string}
	 */
	private function entity_identity(array $header_data, Parser $parser): array
	{
		$name = '';
		$url  = '';
		$image = '';

		foreach ($header_data as $business) {
			if (! is_array($business)) {
				continue;
			}
			$info = isset($business['info']) && is_array($business['info']) ? $business['info'] : [];

			if ($name === '') {
				// Parser::get_business_name() has no return type and its first branch
				// (Parser.php:127) returns business.name verbatim with no is_string()
				// guard — unlike the branch below it — so a cached feed can hand back an
				// array. Left unguarded that reaches html_entity_decode() and raises a
				// PHP 8 TypeError on the wp_head path: a white page rather than a missing
				// snippet. A bare (string) cast is not the fix either — it downgrades the
				// fatal to an "Array to string conversion" warning and publishes the
				// literal name "Array". Accept a scalar, otherwise leave $name empty so
				// the get_bloginfo() fallback below takes over.
				if (! empty($info['name'])) {
					$name = is_scalar($info['name']) ? (string) $info['name'] : '';
				} else {
					$business_name = $parser->get_business_name($business);
					$name          = is_scalar($business_name) ? (string) $business_name : '';
				}
			}
			if ($url === '' && ! empty($info['url'])) {
				$url = (string) $info['url'];
			}
			if ($image === '' && ! empty($info['image']) && is_string($info['image'])) {
				$image = $info['image'];
			}
			if ($name !== '' && $url !== '' && $image !== '') {
				break;
			}
		}

		if ($name === '') {
			$name = (string) get_bloginfo('name');
		}

		// Same data-sink reasoning as map_reviews(): the business name reaches JSON-LD,
		// where nothing resolves a character reference, and no read path decodes it
		// (Parser::get_business_name() never did). A source stored as
		// "Caf&eacute; Nord" would otherwise publish that string literally.
		$name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return ['name' => $name, 'url' => $url, 'image' => $image];
	}

	/**
	 * @param  array  $posts
	 * @param  Parser $parser
	 * @param  bool   $is_pro
	 * @return array<int,array>
	 */
	private function map_reviews(array $posts, Parser $parser, bool $is_pro = true): array
	{
		$reviews = [];
		foreach ($posts as $post) {
			if (! is_array($post)) {
				continue;
			}
			// Decode here, and ONLY here among the read paths. JSON-LD is a DATA sink:
			// inside <script type="application/ld+json"> the HTML parser treats the
			// block as raw text, so nothing ever resolves a character reference the way
			// a browser does for the feed's HTML. Parser::get_text()/get_reviewer_name()
			// deliberately stopped decoding on read (SMASH-1795) because their other
			// consumers ARE HTML sinks — see the surface audit in Parser::get_text().
			// Without the decode, a row written by one of the non-decoding writers
			// (Woo/EDD comment_content, the bulk updaters, the review form) publishes
			// "S&oslash;ren" / "Bed &amp; Breakfast" verbatim in the rich snippet while
			// the feed itself renders the same row correctly.
			//
			// No tag-stripping on purpose. reviewBody keeps parity with the visible feed
			// (test_map_preserves_review_text_without_truncation pins that a legitimate
			// "cheaper than < $10" is never truncated, which is exactly what
			// wp_strip_all_tags would do to it). A stored &lt;script&gt; therefore
			// decodes to literal text in the JSON value — safe, because both emission
			// paths escape it again: print_json_ld() uses JSON_HEX_TAG and
			// escape_markup_for_aioseo() rewrites '<' to '&lt;'. Markup that survives
			// into reviewBody is a structured-data quality question, not a safety one,
			// and it is tracked separately.
			$author = html_entity_decode($parser->get_reviewer_name($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$body   = html_entity_decode($parser->get_text($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($author === '' && $body === '') {
				continue;
			}

			// Google requires a reviewRating per Review, so a review we can't rate is
			// dropped rather than attached rating-less (which would invalidate the node).
			// Caveat worth knowing: Parser::get_rating() returns 1, not 0, for a review
			// with no rating, so for star providers the `<= 0` arm below is effectively
			// unreachable and an unrated review publishes as 1 star. Pre-existing since
			// SMASH-1756, left alone here on purpose — it needs its own ticket and its
			// own QA, and widening this fix to cover it would hide the Booking change.
			$rating = $this->review_rating($post, $parser, $is_pro);
			if ($rating === null) {
				continue;
			}

			$review = [
				'@type'        => 'Review',
				'reviewRating' => [
					'@type'       => 'Rating',
					'ratingValue' => (string) $rating['value'],
					'bestRating'  => (string) $rating['best'],
					'worstRating' => '1',
				],
			];
			if ($author !== '') {
				$review['author'] = ['@type' => 'Person', 'name' => $author];
			}
			if ($body !== '') {
				$review['reviewBody'] = $body;
			}
			$time = (int) $parser->get_time($post);
			if ($time > 0) {
				$review['datePublished'] = gmdate('c', $time);
			}

			$reviews[] = $review;
			if (count($reviews) >= self::MAX_REVIEWS) {
				break;
			}
		}

		return $reviews;
	}

	/**
	 * Resolve one review's rating on its OWN source's scale. Returns null when the
	 * review has no rating we can honestly attribute to its author, so the caller
	 * can drop it.
	 *
	 * A RATING_SLOT_SUBSTITUTED_PROVIDERS provider hides its star block, so its rating
	 * is whatever the replacement badge shows — resolved by substituted_slot_rating().
	 *
	 * Scope: this reasoning is about the FEED surface, which is what the schema is
	 * built from.
	 *
	 * The AggregateRating is unaffected — see map_feed_to_schema().
	 *
	 * @param  array  $post
	 * @param  Parser $parser
	 * @param  bool   $is_pro
	 * @return array{value:float,best:int}|null
	 */
	private function review_rating(array $post, Parser $parser, bool $is_pro = true): ?array
	{
		// (string) cast is load-bearing: get_provider_name() has no return type and
		// hands back the JSON-decoded relay value verbatim, so a non-string payload
		// would TypeError inside template_redirect and white-screen the front end on
		// PHP 8. Same guard as entity_identity() uses on the sibling path.
		$provider = strtolower((string) $parser->get_provider_name($post));
		if (in_array($provider, self::RATING_SLOT_SUBSTITUTED_PROVIDERS, true)) {
			return $this->substituted_slot_rating($provider, $post, $is_pro);
		}

		// Star providers carry a per-review rating the card actually renders.
		$value = (float) $parser->get_rating($post);
		if ($value <= 0) {
			return null;
		}

		return ['value' => $value, 'best' => max(5, (int) ceil($value))];
	}

	/**
	 * Rating for a provider whose star slot is replaced by its own score badge.
	 *
	 * Booking is the only such provider today, and the badge shows THIS reviewer's own
	 * score on Booking's 0-10 scale — the same value from the same helper the template
	 * renders (sbr_booking_review_score(), mirrored in rating-extras/booking.php), so
	 * the markup matches the visible card exactly. bestRating is 10, not 5, because
	 * that is the scale the visitor reads.
	 *
	 * NOT `metadata.review_score`: that is the PROPERTY's general score and the relay
	 * stamps the identical pair onto every card, so attributing it to a named person
	 * would publish the same rating for every reviewer. It belongs on the aggregate
	 * only (map_feed_to_schema()).
	 *
	 * Pro-gated deliberately, NOT because the badge is Pro-only — it isn't. The card
	 * template resolves through `$generic_path` (sbr-functions.php:385-390) and
	 * `templates/frontend/post-elements/` is not in the Free rsync filter
	 * (`build/reviews-feed/filter` excludes only `templates/frontend/pro`), so the badge
	 * renders on Lite too. What IS Pro-only is the header rating and the whole alert
	 * surface. The gate stays because under-claiming is the safe direction: on a
	 * Pro→Lite downgrade with cached Booking reviews the card can show a score the
	 * schema doesn't assert, which Google is fine with — the reverse would not be.
	 *
	 * Any OTHER provider that lands in the constant (rating.php names Facebook as a
	 * candidate) returns null: its stars are hidden and we have no verified replacement
	 * scale, so there is nothing visible to mark up. Deliberately conservative — a new
	 * provider has to be handled here on purpose, not inherit Booking's scale.
	 *
	 * @param  string $provider Lower-cased provider slug.
	 * @param  array  $post
	 * @param  bool   $is_pro
	 * @return array{value:float,best:int}|null
	 */
	private function substituted_slot_rating(string $provider, array $post, bool $is_pro): ?array
	{
		if ($provider !== 'booking' || ! $is_pro) {
			return null;
		}

		// Below Booking's own scale floor (worstRating 1) there is nothing valid to
		// emit — including the 0 the helper returns for an unrated review.
		$score = sbr_booking_review_score($post);
		if ($score < 1) {
			return null;
		}

		// Same clamp both sibling paths apply (review_rating() and the aggregate in
		// map_feed_to_schema()): a stored rating above 5 would otherwise publish a
		// ratingValue larger than bestRating, which Google rejects outright.
		return ['value' => $score, 'best' => max(10, (int) ceil($score))];
	}

	/**
	 * Path A — append our nodes to AIOSEO's schema `@graph`.
	 *
	 * @param  mixed $graph
	 * @return mixed
	 */
	public function merge_into_aioseo($graph)
	{
		if (! is_array($graph)) {
			return $graph;
		}
		// Per-sink hardening. AIOSEO owns the <script> tag and JSON encoding here,
		// and it never applies JSON_HEX_TAG: ?aioseo-dev / its validator use
		// JSON_UNESCAPED_SLASHES, and the default branch uses flags=0 — neither
		// escapes `<` (Schema/Helpers.php:106-107). A raw `<` in untrusted
		// provider/admin data (review author/body OR the entity name/url/image)
		// can drive the HTML script-data tokenizer (`</script`, `<script`, and the
		// double-escape `<!--<script`) and break out of / swallow the JSON-LD
		// block. So we escape the one dangerous character at the encoder layer —
		// no raw `<` reaches the sink — rather than blacklisting a token.
		//
		// NOT byte-lossless, and worth being precise about (PR #510 review): the
		// value AIOSEO emits carries `&lt;` where the source had `<`, so a
		// reviewBody of "great for < $10" reaches Google's parser as
		// "great for &lt; $10". What it *is* is non-truncating — unlike
		// strip_tags(), no text is dropped — and reversible by any consumer that
		// HTML-decodes. Accepted for the small share of review text containing a
		// literal `<`; correctness of the sink wins over byte fidelity here.
		// Path B keeps the raw text and applies JSON_HEX_TAG itself, so it round-trips.
		foreach ($this->nodes as $node) {
			$graph[] = $this->escape_markup_for_aioseo($node);
		}

		return $graph;
	}

	/**
	 * Recursively escape `<` → `&lt;` in every string value so no raw `<` reaches
	 * AIOSEO's (non-JSON_HEX_TAG) JSON-LD <script> block, and neutralise AIOSEO's
	 * smart-tag trigger. Array keys are preserved.
	 *
	 * @param  mixed           $value
	 * @param  int|string|null $key   Key the value came from; `null` at the top level.
	 * @return mixed
	 */
	private function escape_markup_for_aioseo($value, $key = null)
	{
		if (is_array($value)) {
			$escaped = [];
			foreach ($value as $k => $v) {
				$escaped[$k] = $this->escape_markup_for_aioseo($v, $k);
			}

			return $escaped;
		}
		if (is_string($value)) {
			return $this->neutralize_smart_tags(str_replace('<', '&lt;', $value), $key);
		}

		return $value;
	}

	/**
	 * Remove AIOSEO's smart-tag trigger from a value we hand to its `@graph`.
	 *
	 * AIOSEO resolves `#<tag>` in EVERY graph string, and it does so after this filter
	 * returns and after its own sanitising, so nothing we do at the encoder layer
	 * protects the value:
	 *   Schema/Helpers.php:82   apply_filters('aioseo_schema_output', $graph)   <- us
	 *   Schema/Helpers.php:83   cleanAndParseData()
	 *   Schema/Helpers.php:54     wp_strip_all_tags()
	 *   Schema/Helpers.php:57     aioseo()->tags->replaceTags($v, get_the_ID())
	 *
	 * Two of those tags make review text dangerous rather than merely surprising:
	 * `#custom_field-<key>` resolves post meta / ACF (Utils/Tags.php:1360, :1448), so an
	 * unauthenticated reviewer could have a site's post meta printed in <head>; and
	 * `#featured_image` returns a raw `<img>` (Utils/Tags.php:1090), injected after both
	 * the strip and our `<` escape.
	 *
	 * Deliberately drops the trigger character instead of enumerating tag ids. AIOSEO
	 * ships ~70 of them and adds more per release, so an id list is a correctness
	 * dependency on their inventory — and every id-matching scheme has to get doubled
	 * triggers (`##custom_field-x`), word boundaries and regex failure modes right. No
	 * `#` means no tag, whatever AIOSEO adds later, with no list to keep in sync and
	 * nothing that can fail.
	 *
	 * URL-valued keys are truncated at the fragment rather than having the `#` removed
	 * or encoded. A fragment is client-side only, so dropping it still addresses the same
	 * resource, whereas both alternatives break the link: removing the `#` glues the
	 * fragment onto the path, and `%23` is a literal `#` inside the path per RFC 3986
	 * §2.4, so `…/p%23reviews` requests `/p#reviews` and 404s. Real provider URLs carry
	 * fragments (TripAdvisor `…html#REVIEWS`, Google `…#lrd=0x…`), so this matters.
	 *
	 * Free text just loses the character: a reviewer's "#1 in town" reads "1 in town" in
	 * the structured data only. The rendered page is untouched — this runs on the AIOSEO
	 * merge path alone.
	 *
	 * @param  string          $value
	 * @param  int|string|null $key
	 * @return string
	 */
	private function neutralize_smart_tags($value, $key)
	{
		if (strpos($value, '#') === false) {
			return $value;
		}

		if (! in_array((string) $key, self::URL_KEYS, true)) {
			return str_replace('#', '', $value);
		}

		$parts = explode('#', $value, 2);

		// A value that is nothing but a fragment ("#", "#post_title") is not a URL, so
		// there is nothing to truncate to. Return empty rather than a bogus relative URL
		// like "post_title": AIOSEO drops empty graph values (Schema/Helpers.php:60-62),
		// so the key disappears instead of publishing something unfetchable.
		return (string) reset($parts);
	}

	/**
	 * Path B — print our own JSON-LD document in the head.
	 */
	public function print_json_ld(): void
	{
		if (empty($this->nodes)) {
			return;
		}
		$document = [
			'@context' => 'https://schema.org',
			'@graph'   => $this->nodes,
		];
		// JSON_HEX_TAG|JSON_HEX_AMP neutralize `<`/`>`/`&` so review text/author
		// (user-supplied) can't break out of the <script> block. JSON-LD must be
		// emitted as raw JSON, so the script body is intentionally not esc_html'd.
		$json = wp_json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
		if ($json === false) {
			return;
		}

		echo "\n" . '<script type="application/ld+json" class="sbr-schema-graph">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
