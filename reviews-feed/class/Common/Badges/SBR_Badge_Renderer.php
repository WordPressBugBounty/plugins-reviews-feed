<?php

namespace SmashBalloon\Reviews\Common\Badges;

use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\FeedCache;
use SmashBalloon\Reviews\Common\FeedDisplay;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Common\SBR_Settings;
use SmashBalloon\Reviews\Common\Util;

/**
 * Reviews Feed — Widgets & Badges renderer (SBR-163).
 *
 * ONE renderer behind every delivery mechanism. The shortcode, the block and
 * the widget are all thin adapters that call render(); none of them emits
 * markup of its own. That is what keeps four surfaces from drifting apart.
 *
 * Rating numbers are ALWAYS sourced from Parser::get_average_rating() and
 * Parser::get_num_ratings() — the count-weighted, dedup-aware aggregates the
 * feed header already uses. A badge never recomputes rating maths, because a
 * badge showing the wrong number is worse than no badge at all.
 *
 * Purely additive: no existing class, method, template or CSS selector is
 * modified by this feature.
 *
 * The argument shape is declared ONCE, as an alias. It used to be written out at
 * four call sites, which drifted apart as keys were added — and a declared shape
 * overrides inference, so a key missing from one copy was reported as an offset
 * that does not exist. Adding a key to sanitize_args() now means editing one line.
 *
 * @phpstan-type SbrBadgeArgs array{shape: string, feed: int, sources: array<int, string>, source_map: array<int, string[]>, stars: bool, count: bool, powered_by: bool, provider: string, source: string, theme: string, rating: bool, logo_style: string, logo_color: string, logos: bool, accent_hue: int, tint_hue: int, footer_text: string, footer_url: string, placeholder: bool}
 */
class SBR_Badge_Renderer
{
	/**
	 * Shapes this renderer can produce. Used as a strict allowlist — an
	 * unknown shape renders nothing rather than falling back to a default,
	 * so a typo can never silently publish the wrong badge.
	 *
	 * A shape says what the badge looks like: chip, card or medal. It used to also
	 * say WHERE the badge went — trust, footer, checkout, signature — for the
	 * inline rails, which were removed on 19 Aug along with the shortcode, the
	 * widget and the second block. A saved badge is placed by id, so the spot is
	 * no longer part of its identity.
	 */
	const STYLE_SHAPES = array( 'chip', 'card', 'medal' );

	/** Kept as the allowlist name every call site already uses. */
	const SHAPES = self::STYLE_SHAPES;

	/**
	 * Themes the style shapes support. Tinted derives its whole palette from
	 * `tint_hue`, which is why it cannot be a static colour.
	 */
	const THEMES = array( 'light', 'dark', 'tinted' );

	/**
	 * Platform-logo treatments — layout (full lockup vs mark) and colour.
	 */
	const LOGO_STYLES = array( 'full', 'icon' );

	const LOGO_COLORS = array( 'auto', 'brand', 'mono' );

	/**
	 * Ceilings on a source map, because the block path can carry a forged one.
	 *
	 * Over either one the aggregate REFUSES rather than truncating — a subset of
	 * the author's selection published as the whole is the same false claim the
	 * deleted-feed path already fails closed on. Equal values on purpose: each
	 * source is claimed by one feed, so a complete map never has more feeds than
	 * sources and the feed bound can only ever bite a forged map.
	 */
	const SOURCE_MAP_MAX_FEEDS   = 50;
	const SOURCE_MAP_MAX_SOURCES = 50;

	/**
	 * Seconds a single render may spend building uncached feeds.
	 *
	 * Keeps many-cold-feeds off max_execution_time, which would die before writing
	 * the transient and repeat the work on every request. A bail is not a dead end:
	 * each attempt leaves the per-feed aggregates it did reach cached, so the next
	 * one starts further along.
	 */
	const SOURCE_MAP_TIME_BUDGET = 15.0;



	/**
	 * Narrow header data to a single source, so the badge reports that source's
	 * own rating instead of the whole feed's.
	 *
	 * Two things make this less obvious than a filter:
	 *
	 * 1. SMASH-1412 stamps `feed_aggregated` + `feed_average_rating` on EVERY
	 *    header entry, and Parser::get_average_rating() returns on the first
	 *    entry carrying that flag (Parser.php:147) — get_num_ratings() does the
	 *    same at :289. Filtering alone would therefore still hand back the
	 *    feed-wide number. The flag has to come off the narrowed set so the
	 *    parser falls through to its per-source weighted mean.
	 * 2. Matching is on `info.id`, compared as a string. Woo/EDD product ids are
	 *    ints in the DB and strings in the normalised review, so a loose compare
	 *    would work by accident today and break the day a provider uses a
	 *    numeric-looking string id.
	 *
	 * @param array  $header_data Full header data, one entry per source.
	 * @param string $source_id   Source id to keep (Woo/EDD: the product id).
	 *
	 * @return array Entries for that source, aggregate flags stripped. Empty if none match.
	 */
	protected static function scope_to_source($header_data, $source_id)
	{
		$scoped = array();
		$seen   = array();

		foreach ($header_data as $entry) {
			if (! is_array($entry) || ! isset($entry['info']) || ! is_array($entry['info'])) {
				continue;
			}

			$info = $entry['info'];

			// A single-product source stores the product id AS the source id
			// (WooCommerce_Review_Cache_Handler.php:353 — "Single product sources
			// (account_id = product_id)"). Google, Yelp and the rest land here too,
			// where the id is a place id.
			if (isset($info['id']) && (string) $info['id'] === (string) $source_id) {
				if (isset($seen[ (string) $source_id ])) {
					continue;
				}

				$seen[ (string) $source_id ] = true;
				$entry['info']               = self::strip_feed_aggregate($info);
				$scoped[]                    = $entry;
				continue;
			}

			// A MULTI-product source does not: its `info.id` is the synthetic
			// account id (`wc_multi_…` / `edd_multi_…`) and the per-product
			// numbers live nested. Matching only on info.id would therefore miss
			// every product on exactly the feeds per-product badges exist for.
			$product = self::find_nested_product($info, $source_id);

			if ($product !== null && ! isset($seen[ (string) $source_id ])) {
				// Dedup is load-bearing here, not defensive. Two Woo/EDD sources
				// can overlap on the same product — the case SMASH-1412 exists
				// for (Feed.php:461-467) — and strip_feed_aggregate() has just
				// removed the short-circuit that used to hide it. Two identical
				// entries would make Parser::get_num_ratings() sum the product's
				// reviews twice: 12 renders as 24.
				$seen[ (string) $source_id ] = true;
				$scoped[]                    = array( 'info' => $product );
			}
		}

		return $scoped;
	}

	/**
	 * Last-resort per-product lookup against the feed's saved sources.
	 *
	 * @param array  $sources   Feed settings `sources`.
	 * @param string $source_id Product id being asked for.
	 *
	 * @return array Zero or one source-shaped entry.
	 */
	protected static function scope_from_sources($sources, $source_id)
	{
		if (! is_array($sources)) {
			return array();
		}

		foreach ($sources as $source) {
			if (! is_array($source) || ! isset($source['info'])) {
				continue;
			}

			$info = $source['info'];

			// `info` is stored as JSON on the source row and decoded lazily —
			// Feed.php:435 does the same before reading it.
			if (is_string($info)) {
				$decoded = json_decode($info, true);
				$info    = is_array($decoded) ? $decoded : array();
			}

			if (! is_array($info)) {
				continue;
			}

			$product = self::find_nested_product($info, $source_id);

			if ($product !== null) {
				return array( array( 'info' => $product ) );
			}
		}

		return array();
	}

	/**
	 * Remove the feed-wide aggregate short-circuit from one info array.
	 *
	 * @param array $info Header entry info.
	 *
	 * @return array
	 */
	protected static function strip_feed_aggregate($info)
	{
		unset(
			$info['feed_aggregated'],
			$info['feed_average_rating'],
			$info['feed_total_review_count']
		);

		return $info;
	}

	/**
	 * Find one product inside a multi-product source and shape it like a source.
	 *
	 * The nested lists and their precedence are the plugin's own, not invented
	 * here — Feed.php:621 resolves a multi-product source with
	 * `$info['products'] ?? $info['direct_products'] ?? $info['downloads']`.
	 * `direct_downloads` is EDD's analogue of `direct_products` and is included
	 * for symmetry. Both providers build their entries the same way:
	 * `id / name / review_count / average_rating` (WooCommerce.php:372-377,
	 * EDD.php:607-612).
	 *
	 * The returned array is deliberately shaped like a header `info`, because
	 * Parser::get_average_rating() reads `rating ?? average_rating` and
	 * get_num_ratings() reads `total_rating ?? review_count`. Handing it a
	 * product therefore needs no parser change — the badge keeps consuming the
	 * same aggregation the feed header does, which is the pitch's rule.
	 *
	 * @param array  $info      Header entry info for a multi-product source.
	 * @param string $source_id Product id being asked for.
	 *
	 * @return array|null Source-shaped info for that product, or null.
	 */
	protected static function find_nested_product($info, $source_id)
	{
		$lists = array( 'products', 'direct_products', 'downloads', 'direct_downloads' );

		foreach ($lists as $list) {
			if (empty($info[ $list ]) || ! is_array($info[ $list ])) {
				continue;
			}

			foreach ($info[ $list ] as $product) {
				if (! is_array($product) || ! isset($product['id'])) {
					continue;
				}

				if ((string) $product['id'] !== (string) $source_id) {
					continue;
				}

				// A product with no reviews yet carries review_count 0; keep it
				// rather than skipping, so the badge can say "No reviews yet"
				// for that product instead of vanishing.
				return array(
					'id'             => (string) $product['id'],
					'name'           => isset($product['name']) ? $product['name'] : '',
					'average_rating' => isset($product['average_rating']) ? (float) $product['average_rating'] : 0.0,
					'review_count'   => isset($product['review_count']) ? (int) $product['review_count'] : 0,
				);
			}
		}

		return null;
	}



	/**
	 * Transient prefix for the cached aggregate.
	 *
	 * The trailing number is a PAYLOAD-SHAPE version, separate from the per-feed
	 * version salt. The cached array gained a `resolved` key, and read_cache()
	 * fails safe by treating a missing flag as unresolved — so without a new
	 * namespace every entry written by the previous shape would read back as
	 * unresolved and hide a badge that was rendering correctly, for a full TTL.
	 * Bumped to 3 when `scale`/`word` were added: a pre-Booking entry for a
	 * Booking-only feed cached rating 0.0 (Parser skips sources with no
	 * `info.rating`), and reading it back as a valid 5-scale aggregate would keep
	 * publishing "No reviews yet" for a hotel that has reviews.
	 * Bumped to 4 when `failed` was added: a pre-upgrade negative-cache row reads
	 * back `failed = false`, which the map path treats as "nothing to say" and
	 * skips instead of failing closed.
	 * Bumped to 5 when provider slugs stopped losing their dot: entries written
	 * before that hold `wordpressorg`, which no longer resolves to a platform name,
	 * so a WordPress.org badge would stay nameless for a full TTL.
	 * Bump this whenever the cached array's shape OR the meaning of a value in it
	 * changes.
	 */
	const CACHE_PREFIX = 'sbr_badge_agg5_';

	/**
	 * Option prefix for the per-feed cache version salt.
	 */
	const VERSION_PREFIX = 'sbr_badge_ver_';

	/**
	 * Render a badge.
	 *
	 * @param array      $args      Raw, untrusted arguments (shortcode atts, widget
	 *                              instance, block attributes).
	 * @param array|null $aggregate Pre-resolved aggregate to render from, skipping the
	 *                              lookup. For a caller that must not pay for a possible
	 *                              synchronous provider fetch — see specimen_html(). Must
	 *                              carry `resolved`, or nothing renders, same as a lookup
	 *                              that failed. Omit it and behaviour is unchanged.
	 *
	 * @return string Rendered HTML, or an empty string when the shape is
	 *                unknown or the feed cannot be resolved.
	 */
	public static function render($args = array(), $aggregate = null)
	{
		if (! is_array($args)) {
			$args = array();
		}

		$offered_map = ! empty($args['source_map']) && is_array($args['source_map']);

		// Captured on the RAW args, before sanitising, for the same reason
		// $offered_map is: the sanitiser answers "none" both for a badge that
		// named no sources and for one whose sources it REFUSED, and those two
		// need opposite outcomes.
		$offered_sources = ! empty($args['sources']) && is_array($args['sources']);

		$args = self::sanitize_args($args);

		if ($args['shape'] === '') {
			return '';
		}

		// A map was offered and rejected — past its bounds, or nothing in it
		// survived sanitising. Falling through to the single feed would publish one
		// feed as the whole badge, which is what this map exists to stop.
		if ($offered_map && empty($args['source_map'])) {
			return '';
		}

		// The same rule for SOURCES, which did not have it. sanitize_source_ids()
		// returns an empty array past SOURCE_MAP_MAX_SOURCES, and `sources` is
		// uncapped at save — so a badge saved over 51 sources reached the branch
		// below with no sources and no `failed` flag, fell through to
		// get_aggregate($feed, '') and published the primary feed's ENTIRE rating,
		// including reviews from sources the author never picked. The map path has
		// refused exactly this since it was written; this one was reachable.
		if ($offered_sources && empty($args['sources'])) {
			return '';
		}

		// A saved badge speaks for the sources its author chose, which may span
		// feeds; everything else names one feed and keeps the original path.
		// Map first, single feed only as fallback — same order as
		// aggregate_for_badge(), so the page and the image agree.
		// A caller that already has the numbers hands them over. Everything below the
		// lookup is unchanged, so a supplied aggregate with no `resolved` renders
		// nothing exactly as a failed lookup does.
		if (is_array($aggregate)) {
			$aggregate = self::normalize_aggregate($aggregate);
		} else {
			$aggregate = array();

			// Sources first: that is what the badge selected, and asking for them
			// directly needs no feed to exist behind them.
			if (! empty($args['sources'])) {
				$aggregate = self::get_aggregate_for_sources($args['sources']);
			} elseif (! empty($args['source_map'])) {
				$aggregate = self::get_aggregate_for_map($args['source_map']);
			}

			// A map that refused (a broken feed) must NOT fall through — that would
			// publish the primary feed's whole rating.
			if (empty($aggregate['resolved']) && empty($aggregate['failed'])) {
				$aggregate = self::get_aggregate($args['feed'], $args['source']);
			}
		}

		// Render NOTHING when the feed cannot be resolved at all — a missing
		// `feed` attribute (the block and widget both default to 0), a deleted
		// feed, or a feed with no data. Falling through would publish a badge
		// reading "— / No reviews yet", which is a public claim that this
		// business has no reviews. That is strictly worse than showing nothing,
		// and it is the same reasoning as the `shape === ''` guard above.
		//
		// One question, asked once: can this badge report a rating? Unresolved and
		// resolved-but-unrated are the same answer to an author and to a visitor,
		// so they get the same branch. Testing `resolved` on its own first is what
		// made the editor render nothing for a badge with no sources — it never
		// reached the placeholder below.
		$can_report = ! empty($aggregate['resolved'])
			&& self::is_rated($aggregate['rating'], $aggregate['count']);

		if (! $can_report) {
			// The editor draws the shape so an author can lay a badge out before
			// its sources report anything. No public caller sets this — the block,
			// the widget, the shortcode and SBR_Badge_Service all render saved
			// badges without it — so these two lines are exactly "editor" and
			// "page".
			if (empty($args['placeholder'])) {
				// Publishing "—" and "No reviews yet" was true and still wrong: a
				// badge exists to make a claim, and that is not one a business
				// would choose to make about itself.
				return '';
			}

			$aggregate = self::placeholder_aggregate($aggregate);
		} else {
			// The flag says the EDITOR is asking; it does not say this badge needs
			// a placeholder. A badge that reports a real rating must not be marked
			// as one, or the CSS dims and blurs numbers that are genuinely there.
			$args['placeholder'] = false;
		}

		// Every element hidden left a bordered box with nothing in it — 182 bytes
		// of empty shell on a live page. The editor disables the last toggle, but
		// the sanitiser accepts all four false, so anything saved through the API
		// could reach here.
		if (
			empty($args['rating']) && empty($args['stars'])
			&& empty($args['count']) && empty($args['logos'])
		) {
			return '';
		}

		$badge = self::prepare($args, $aggregate);

		$template = trailingslashit(SBR_PLUGIN_DIR) . 'templates/frontend/badges/' . $args['shape'] . '.php';

		if (! file_exists($template)) {
			return '';
		}

		// Consumed by the template and by stars.php. The star row gets the RATED
		// value from prepare(), never the raw aggregate — see 'stars_rating'.
		$sbr_badge        = $badge;
		// prepare() always sets this; the coalesce is for the analyser, which
		// only sees array<string, mixed>.
		$sbr_badge_rating = $badge['stars_rating'] ?? 0.0;

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * The numbers the editor draws before a badge has any.
	 *
	 * Zero, honestly: 0.0 over "Based on 0 reviews". Not the newest feed's figures,
	 * which is what this replaced — a badge with no sources previewed 4.5 from
	 * 96,864 reviews and then published nothing, so the editor was the only screen
	 * that made a promise.
	 *
	 * @param array<string, mixed> $aggregate The real aggregate, for its scale.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<int, string>}
	 */
	protected static function placeholder_aggregate(array $aggregate)
	{
		$scale = isset($aggregate['scale']) && is_numeric($aggregate['scale']) && (int) $aggregate['scale'] > 0
			? (int) $aggregate['scale']
			: 5;

		return array(
			'rating'    => 0.0,
			'count'     => 0,
			'resolved'  => true,
			'scale'     => $scale,
			'word'      => '',
			'providers' => isset($aggregate['providers']) && is_array($aggregate['providers'])
				? $aggregate['providers']
				: array(),
		);
	}

	/**
	 * Coerce a supplied aggregate into the shape prepare() reads.
	 *
	 * prepare() takes `rating` and `count` without an isset guard, so a caller handing
	 * over a partial array would fatal on PHP 8 rather than render nothing.
	 *
	 * @param array $aggregate Caller-supplied aggregate.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}
	 */
	protected static function normalize_aggregate(array $aggregate)
	{
		// is_numeric / is_string before the casts: casting an array warns and yields
		// "Array" or 1, which would reach a template as a score word or a scale.
		$scale = isset($aggregate['scale']) && is_numeric($aggregate['scale'])
			? (int) $aggregate['scale']
			: 5;

		return array(
			'rating'    => isset($aggregate['rating']) && is_numeric($aggregate['rating'])
				? (float) $aggregate['rating']
				: 0.0,
			'count'     => isset($aggregate['count']) && is_numeric($aggregate['count'])
				? max(0, (int) $aggregate['count'])
				: 0,
			'resolved'  => ! empty($aggregate['resolved']),
			// A zero or negative scale would divide by nothing downstream.
			'scale'     => $scale > 0 ? $scale : 5,
			'word'      => isset($aggregate['word']) && is_string($aggregate['word'])
				? $aggregate['word']
				: '',
			'providers' => isset($aggregate['providers']) && is_array($aggregate['providers'])
				? array_values(array_filter($aggregate['providers'], 'is_string'))
				: array(),
		);
	}

	/**
	 * Normalise and sanitise every incoming argument.
	 *
	 * Shape and size use strict allowlists rather than an `! empty()` check,
	 * so an unexpected value is rejected outright instead of being coerced
	 * into a path fragment.
	 *
	 * @param array $args Raw arguments.
	 *
	 * The alias has to list every key this returns: a declared shape overrides what
	 * the analyser can see, so a key present in the array and missing from the alias
	 * reads as an offset that does not exist. Anything added below belongs there too.
	 *
	 * @return SbrBadgeArgs Sanitised arguments.
	 */
	protected static function sanitize_args($args)
	{
		// 'card' rather than the old 'trust': a caller that names no shape gets the
		// same one a new badge is created with.
		$shape = isset($args['shape']) ? sanitize_key((string) $args['shape']) : 'card';

		return array(
			'shape'        => in_array($shape, self::SHAPES, true) ? $shape : '',
			'feed'         => isset($args['feed']) ? absint($args['feed']) : 0,
			'stars'        => self::to_bool($args, 'stars', true),
			// Editor only. render() reads it before prepare() does; both need it
			// to survive sanitising, and a public caller never sets it.
			'placeholder'  => self::to_bool($args, 'placeholder', false),
			'count'        => self::to_bool($args, 'count', true),
			'powered_by'   => self::to_bool($args, 'powered_by', false),
			'provider'     => isset($args['provider']) ? sanitize_text_field((string) $args['provider']) : '',
			// Scopes the RATING to one source; `product` above is only a label.
			// sanitize_text_field, not absint: Woo/EDD ids are numeric but a
			// Google place id is not, and one key has to serve both.
			'source'       => isset($args['source']) ? sanitize_text_field((string) $args['source']) : '',
			// feed id => wanted source ids, for a saved badge whose sources span
			// more than one feed. Absent everywhere a feed is named directly.
			// The badge's own selection. `source_map` stays for callers that still
			// hand one over; a saved badge no longer builds one.
			'sources'      => self::sanitize_source_ids(
				isset($args['sources']) ? $args['sources'] : null
			),
			'source_map'   => self::sanitize_source_map(
				isset($args['source_map']) ? $args['source_map'] : null
			),
			// Theme applies to all three shapes; SHAPES is STYLE_SHAPES since the
			// placement shapes were removed (19 Aug).
			'theme'        => self::to_allowed($args, 'theme', self::THEMES, 'light'),
			// The numeric value is a togglable element — chip, card and medal all
			// read this.
			'rating'       => self::to_bool($args, 'rating', true),
			// Same defaults as the store, for the same reason — a rail that
			// passes neither must land where a saved badge lands.
			'logo_style'   => self::to_allowed($args, 'logo_style', self::LOGO_STYLES, 'full'),
			'logo_color'   => self::to_allowed($args, 'logo_color', self::LOGO_COLORS, 'auto'),
			'logos'        => self::to_bool($args, 'logos', true),
			// Hues, not colours: the design derives a different star lightness per
			// theme from one hue, so storing the resolved hex would lock the badge
			// to the theme it was picked under. -1 means "no accent, keep gold".
			'accent_hue'   => self::to_hue($args, 'accent_hue'),
			'tint_hue'     => self::to_hue($args, 'tint_hue'),
			'footer_text'  => isset($args['footer_text']) ? sanitize_text_field((string) $args['footer_text']) : '',
			'footer_url'   => isset($args['footer_url']) ? esc_url_raw((string) $args['footer_url']) : '',
		);
	}

	/**
	 * The one source a badge speaks for, or '' when it speaks for several.
	 *
	 * Scoping the aggregate to a single source is only correct when exactly one
	 * is selected. With two or more, taking the first would attribute everyone's
	 * reviews to one platform — so the empty string means "the whole feed", not
	 * "unknown".
	 *
	 * Extracted because this six-token expression was written out at five call
	 * sites (the image endpoint's three, plus the builder's preview args and its
	 * feed lookup), and each copy had to re-derive that `count() === 1` is the
	 * condition rather than `! empty()`.
	 *
	 * @param array<string, mixed> $settings Stored badge settings.
	 *
	 * @return string Source id as a string, or '' for none / several.
	 */
	public static function sole_source_id($settings)
	{
		if (! isset($settings['sources']) || ! is_array($settings['sources'])) {
			return '';
		}

		$sources = array_values($settings['sources']);

		if (count($sources) !== 1) {
			return '';
		}

		// reset() rather than [0]: array_values gives consecutive integer keys,
		// but only reset() tells the type-checker the value is there.
		$first = reset($sources);

		return is_scalar($first) ? (string) $first : '';
	}

	/**
	 * Coerce an argument to a member of an allowlist, or to a default.
	 *
	 * @param array         $args    Raw arguments.
	 * @param string        $key     Key to read.
	 * @param array<string> $allowed Permitted values.
	 * @param string        $default Value used when the key is absent or invalid.
	 *
	 * @return string
	 */
	protected static function to_allowed($args, $key, $allowed, $default)
	{
		if (! isset($args[$key]) || is_array($args[$key]) || is_object($args[$key])) {
			return $default;
		}

		$value = sanitize_key((string) $args[$key]);

		return in_array($value, $allowed, true) ? $value : $default;
	}

	/**
	 * Coerce an argument to a hue on the 0-359 wheel.
	 *
	 * Returns -1 for "unset", which is a real state here and distinct from 0:
	 * hue 0 is red, and an accent of red has to survive a round-trip. Values
	 * outside the wheel are rejected rather than wrapped — a 400 is a mistake,
	 * and silently turning it into 40 hides it.
	 *
	 * @param array  $args Raw arguments.
	 * @param string $key  Key to read.
	 *
	 * @return int Hue, or -1 when unset or invalid.
	 */
	protected static function to_hue($args, $key)
	{
		if (! isset($args[$key]) || is_array($args[$key]) || is_object($args[$key])) {
			return -1;
		}

		$raw = trim((string) $args[$key]);

		if ($raw === '' || ! is_numeric($raw)) {
			return -1;
		}

		$hue = (int) $raw;

		return ($hue >= 0 && $hue <= 359) ? $hue : -1;
	}

	/**
	 * Coerce a loosely-typed argument to bool.
	 *
	 * Shortcode attributes arrive as strings, widget instances as booleans and
	 * block attributes as JSON booleans, so 'false'/'0'/'no' all have to mean
	 * false.
	 *
	 * @param array  $args    Arguments.
	 * @param string $key     Key to read.
	 * @param bool   $default Value when the key is absent.
	 *
	 * @return bool
	 */
	protected static function to_bool($args, $key, $default)
	{
		if (! isset($args[$key])) {
			return $default;
		}

		$value = $args[$key];

		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			return ! in_array(strtolower(trim($value)), array( 'false', '0', 'no', '' ), true);
		}

		return (bool) $value;
	}


	/**
	 * The aggregate a SAVED badge speaks for, however it is being rendered.
	 *
	 * One entry point, so the three surfaces — HTML badge, email PNG and the
	 * `?sb-badge=` endpoint — cannot report different numbers.
	 *
	 * @param array $settings Sanitised badge settings.
	 * @param int   $feed_id  Already-resolved feed, for the fallback.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool} Rating, count and the platforms behind them.
	 */
	// Untyped like get_aggregate(): SBR_Badge_Image forwards its own untyped
	// public $settings straight in, and a null must fold to 0, not fatal.
	public static function aggregate_for_badge($settings, $feed_id)
	{
		$settings = is_array($settings) ? $settings : array();
		$feed_id  = absint($feed_id);

		// A badge speaks for SOURCES, so ask for sources. This used to run a
		// reverse lookup from each source back to a feed that carried it, and a
		// source in no feed took the whole badge down to nothing — see
		// get_aggregate_for_sources() for why that indirection existed and why
		// it no longer has to.
		//
		// An explicit `feed_id` pin still wins: a badge saved before sources
		// existed, and the editor's newest-feed preview, both come through that
		// way and are answered by the single-feed path below.
		$sources = ( empty($settings['feed_id']) && isset($settings['sources']) && is_array($settings['sources']) )
			? $settings['sources']
			: array();

		if (! empty($sources)) {
			$aggregate = self::get_aggregate_for_sources($sources);

			if (! empty($aggregate['resolved']) || ! empty($aggregate['failed'])) {
				return $aggregate;
			}
		}

		// No source resolved to a feed that carries it. The single-feed path
		// still answers for the editor's newest-feed fallback and for a badge
		// saved before sources existed.
		return self::get_aggregate($feed_id, self::sole_source_id($settings));
	}

	/**
	 * The aggregate for a set of sources, asked for directly.
	 *
	 * This is what a badge actually wants. Everything below it exists because a
	 * badge could only be answered through a FEED: source_map_for_badge() ran a
	 * reverse `settings LIKE '%"<id>"%'` over the feeds table to find one
	 * carrying each source, and a source that belonged to no feed made the whole
	 * badge render nothing — no rating, no markup, no explanation. Selecting a
	 * connected source and getting silence is not a state an author can debug.
	 *
	 * The indirection was never about the data. It was about the CACHE, which is
	 * keyed on a feed id. `wp_sbr_feed_caches.feed_id` is a varchar and Review
	 * Alerts already keys it on `review_alert_<hash>`
	 * (SBR_Review_Alert_Frontend.php:629), so a set of sources can have a cache
	 * row of its own. This builds one synthetic feed over exactly the badge's
	 * sources and caches it under `sbr_badge_<hash>`.
	 *
	 * Everything else is the shipped machinery, unchanged: Pro\Feed on Pro so
	 * init() sets the WPML suffix, the same Parser, the same
	 * rows_to_contribution(). What goes away is the reverse lookup, the
	 * source-in-no-feed failure and the per-feed fan-out.
	 *
	 * @param array<int, string> $source_ids Account ids the badge selected.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool}
	 */
	public static function get_aggregate_for_sources(array $source_ids): array
	{
		$empty = array(
			'rating'    => 0.0,
			'count'     => 0,
			'resolved'  => false,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		$ids = array_values(array_unique(array_filter(
			array_map('strval', array_filter($source_ids, 'is_scalar')),
			static function ($id) {
				return $id !== '';
			}
		)));

		if (empty($ids)) {
			return $empty;
		}

		// Still bounded. The endpoint that reaches this is unauthenticated, and
		// `sources` is uncapped at save; the cap is no longer about how many
		// LIKE clauses a query can carry, but a badge speaking for fifty
		// platforms is not a case worth serving from a page view either.
		if (count($ids) > self::SOURCE_MAP_MAX_SOURCES) {
			return self::closed($empty);
		}

		// Sorted for the CACHE KEY only, so the same set of sources in a different
		// order is one cache entry rather than n! of them.
		$keyed = $ids;
		sort($keyed);

		$cache_id = 'sbr_badge_' . md5((string) wp_json_encode($keyed));
		$settings = array_merge(sbr_settings_defaults(), array( 'sources' => $keyed ));

		// One synthetic feed over exactly these sources, then the SAME row
		// processing the per-feed path uses. Both go through header_rows() and
		// contribution_from_built(), so the two cannot report different numbers
		// for the same source.
		$built = self::header_rows(0, $settings, $cache_id);

		// $ids, not $keyed: `chosen` comes back in this order and the logo row is
		// built from it, so the row was being ordered by an unordered `IN (…)`
		// query plus an alphabetical sort of account ids. That mattered while a
		// five-mark cap sliced the row — a real platform with real reviews, an EDD
		// source reported from QA, was dropped for a neighbour that sorted
		// earlier. The cap is gone now and every contributing platform is drawn,
		// but the ORDER is still the author's to control, so it stays theirs.
		$out = self::contribution_from_built($built, $ids);

		// A source that ERRORED still fails closed: a fetch that came back broken
		// may mean the number we would publish is wrong, and being wrong in public
		// is the one outcome worth hiding the badge for.
		if (! empty($out['errored'])) {
			return self::closed($empty);
		}

		// closed(), not a bare empty. NOTHING the author picked contributed, and
		// an unflagged empty sends render() down the single-feed fallback, which
		// publishes the primary feed's ENTIRE rating — sources the author never
		// selected included. The map path has returned closed() here since it was
		// written, for exactly this reason; this branch did not.
		//
		// Distinct from the partial case below: there, SOME source contributed and
		// the badge reports those. Here none did, so there is nothing honest to
		// publish and the badge renders nothing.
		if (empty($out['contribution'])) {
			return self::closed($empty);
		}

		$chosen = array_values(array_unique(array_map('strval', $out['chosen'])));

		// A source with NO DATA YET does not fail the badge. It used to: the rule
		// was that every pick had to contribute, and one lagging source took the
		// whole badge down — measured on a 19-source badge where 18 reported real
		// ratings and one had never fetched, and the badge published nothing while
		// the editor showed a zero.
		//
		// It reads as strict, but it was not protecting anything. A source with no
		// data row contributes no reviews, so leaving it out changes the number by
		// nothing — a source with zero reviews AND a data row was already counted
		// this way. And `unseen_rows()` drops a perfectly healthy source whose rows
		// a sibling already contributed, so the check failed on badges where every
		// source worked.
		//
		// What keeps this honest is `providers`: it is built from $chosen, so the
		// badge never shows a platform's logo whose reviews are not in the rating.
		$aggregate              = $out['contribution'];
		$aggregate['providers'] = self::providers_for_sources($chosen);

		// Named for the EDITOR, which tells the author which of their picks is not
		// loading. No public surface reads this — normalize_aggregate() does not
		// carry the key, and the templates never see it.
		$aggregate['unreported'] = array_values(array_diff($ids, $chosen));

		return $aggregate;
	}

	/**
	 * One source from one feed, and what its answer means for the badge.
	 *
	 * Split out of get_aggregate_for_map() only to keep that method inside the
	 * complexity limit; the decisions are unchanged and each one is described
	 * where it is made.
	 *
	 * @param int    $feed_id   Feed carrying the source.
	 * @param string $source_id The source.
	 *
	 * @return array{refuse: bool, resolved: bool, unreadable: bool, contribution: array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|null, source_id: string}
	 */
	protected static function single_source_contribution(int $feed_id, string $source_id): array
	{
		$out = array(
			'refuse'       => false,
			'resolved'     => false,
			'unreadable'   => false,
			'contribution' => null,
			'source_id'    => $source_id,
		);

		$one = self::get_aggregate($feed_id, $source_id);

		// A broken feed refuses; a feed with nothing to say is skipped.
		if (! empty($one['failed'])) {
			$out['refuse'] = true;

			return $out;
		}

		if (empty($one['resolved'])) {
			return $out;
		}

		$out['resolved'] = true;

		// Reviews but no rating we can read: a Booking source on Lite, where
		// Parser skips it for having no `info.rating` and get_aggregate()'s 0-10
		// branch is Pro-gated. combine() weights by count, so keeping it would
		// drag the badge toward zero. A source with no reviews AT ALL is a
		// different thing.
		if ((float) $one['rating'] <= 0) {
			$out['unreadable'] = (int) $one['count'] > 0;

			return $out;
		}

		$out['contribution'] = $one;

		return $out;
	}

	/**
	 * What one feed's contribution means for the badge.
	 *
	 * The several-sources counterpart of single_source_contribution(), and the
	 * same three outcomes: refuse, skip, or count it. Split out for the same
	 * reason — get_aggregate_for_map() was over the complexity limit.
	 *
	 * @param array{errored: bool, contribution: array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|null, chosen: array<int, string>} $one_feed Result of feed_contribution().
	 *
	 * @return array{refuse: bool, resolved: bool, unreadable: bool, contribution: array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|null, chosen: array<int, string>}
	 */
	protected static function weigh_contribution(array $one_feed): array
	{
		$out = array(
			'refuse'       => ! empty($one_feed['errored']),
			'resolved'     => false,
			'unreadable'   => false,
			'contribution' => null,
			'chosen'       => array(),
		);

		if ($out['refuse'] || empty($one_feed['contribution'])) {
			return $out;
		}

		$out['resolved'] = true;

		// A rating we cannot read drags a count-weighted average toward zero, so
		// it is left out rather than averaged in.
		if ((float) $one_feed['contribution']['rating'] <= 0) {
			$out['unreadable'] = (int) $one_feed['contribution']['count'] > 0;

			return $out;
		}

		$out['contribution'] = $one_feed['contribution'];
		$out['chosen']       = $one_feed['chosen'];

		return $out;
	}

	/**
	 * The aggregate for a set of sources that may span several feeds.
	 *
	 * get_aggregate() answers for ONE feed; this stitches several. It is the
	 * FALLBACK: render() asks get_aggregate_for_sources() first, which needs no
	 * feed at all (SMASH-2012), and only reaches here for a caller that hands
	 * over a feed => sources map. Feed-keyed is this method's signature rather
	 * than debt, so the deferred-work marker that used to sit here is gone —
	 * described rather than named, so a grep for open markers stays honest.
	 *
	 * The maths is not reimplemented — rows are scoped to what the author chose
	 * and handed to the same count-weighted Parser::get_average_rating()
	 * (SMASH-1583) the feed header uses.
	 *
	 * @param array<int, string[]> $map Feed id => the source ids wanted from it.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool} Aggregate, same shape get_aggregate() returns.
	 */
	public static function get_aggregate_for_map(array $map): array
	{
		$empty = array(
			'rating'    => 0.0,
			'count'     => 0,
			'resolved'  => false,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		if (empty($map)) {
			return $empty;
		}

		// Sorted, so two badges that chose the same sources in a different
		// order share a cache row instead of computing the same answer twice.
		$normalised = array();

		foreach ($map as $feed_id => $wanted) {
			$wanted = array_values(array_unique(array_map('strval', (array) $wanted)));
			sort($wanted);
			$normalised[ (int) $feed_id ] = $wanted;
		}

		ksort($normalised);

		$feed_ids = array_keys($normalised);
		$primary  = (int) reset($feed_ids);
		$settings = SBR_Settings::get_settings_by_feed_id($primary, false, false);

		// Same refusal as a missing secondary feed, just before there is a
		// cache key to store it under.
		if (! is_array($settings)) {
			return self::closed($empty);
		}

		// cache_key() salts on the primary's cache_version alone, and
		// clear_cache() bumps one feed at a time — so every feed's version goes
		// into the fingerprint or a secondary refresh leaves this row stale.
		$salt = array();

		foreach ($feed_ids as $id) {
			$salt[] = $id . ':' . self::cache_version((int) $id);
		}

		$cache_key = self::cache_key(
			$primary,
			$settings,
			'set:' . md5((string) wp_json_encode($normalised) . '|' . implode(',', $salt))
		);
		$cached    = self::read_cache($cache_key);

		if ($cached !== null) {
			return $cached;
		}

		// One CONTRIBUTION per source: (rating, count, scale). Combined at the
		// end by a count-weighted mean, which is the same answer as averaging
		// the rows — each scoped aggregate is exactly one business.
		$contributions = array();
		$chosen        = array();
		$started       = microtime(true);

		// Two ways a source can contribute nothing, and they end differently: it has
		// reviews whose rating we cannot read (refuse), or it resolved with no
		// reviews at all (the unrated state is true, so say it).
		$unreadable   = false;
		$resolved_any = false;

		foreach ($normalised as $feed_id => $wanted) {
			// Out of budget with sources still unresolved: refuse rather than
			// publish a partial rating or die mid-request.
			if (microtime(true) - $started > self::SOURCE_MAP_TIME_BUDGET) {
				return self::negative_cache($cache_key, self::closed($empty));
			}

			$feed_settings = $feed_id === $primary
				? $settings
				: SBR_Settings::get_settings_by_feed_id($feed_id, false, false);

			// A deleted feed fails closed — a rating built from part of the
			// author's selection is a false public claim.
			if (! is_array($feed_settings)) {
				return self::negative_cache($cache_key, self::closed($empty));
			}

			// ONE source from this feed: reuse get_aggregate() and its transient.
			// Building a Feed here instead measured 79s / 653 queries on an
			// 11-feed badge, past max_execution_time.
			if (count($wanted) === 1) {
				$single = self::single_source_contribution($feed_id, (string) reset($wanted));

				if ($single['refuse']) {
					return self::negative_cache($cache_key, self::closed($empty));
				}

				$resolved_any = $resolved_any || $single['resolved'];
				$unreadable   = $unreadable || $single['unreadable'];

				if ($single['contribution'] !== null) {
					$contributions[] = $single['contribution'];
					$chosen[]        = $single['source_id'];
				}

				continue;
			}

			// SEVERAL from one feed: one build, and the row path, because two
			// Woo/EDD sources can share a product and only rows can be deduped
			// (SMASH-1412).
			$many = self::weigh_contribution(
				self::feed_contribution($feed_id, $feed_settings, $wanted)
			);

			if ($many['refuse']) {
				return self::negative_cache($cache_key, self::closed($empty));
			}

			$resolved_any = $resolved_any || $many['resolved'];
			$unreadable   = $unreadable || $many['unreadable'];

			if ($many['contribution'] !== null) {
				$contributions[] = $many['contribution'];
				$chosen          = array_merge($chosen, $many['chosen']);
			}
		}

		if (empty($contributions)) {
			// Reviews exist somewhere in the selection but none of them yields a
			// rating: there is no number to publish, so refuse. Flagged, unlike the
			// no-map exit above, or the callers fall through to the single-feed path
			// and publish the primary feed unscoped — sources the author never
			// picked included.
			// Nothing resolved at all goes the same way — an unrated badge for a feed
			// that could not be read is the "No reviews yet" claim render() refuses
			// to make for a business that has them.
			if ($unreadable || ! $resolved_any) {
				return self::negative_cache($cache_key, self::closed($empty));
			}

			// Every selected source resolved and simply has no reviews yet. That is
			// the unrated state, not a refusal: "No reviews yet" is true here, and
			// render() draws it (see its guard on `resolved`).
			$unrated             = $empty;
			$unrated['resolved'] = true;

			$ttl = self::cache_ttl($primary);

			if ($ttl > 0) {
				set_transient($cache_key, $unrated, $ttl);
			}

			return $unrated;
		}

		$aggregate               = self::combine($contributions);
		$aggregate['providers']  = self::providers_for_sources($chosen);

		$ttl = self::cache_ttl($primary);

		if ($ttl > 0) {
			set_transient($cache_key, $aggregate, $ttl);
		}

		return $aggregate;
	}

	/**
	 * One feed's several selected sources, as a single contribution.
	 *
	 * @param int   $feed_id       Feed id.
	 * @param array $feed_settings Its settings.
	 * @param array $wanted        Source ids selected from it.
	 *
	 * @return array{errored: bool, contribution: array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|null, chosen: array<string>}
	 */
	protected static function feed_contribution(int $feed_id, array $feed_settings, array $wanted): array
	{
		return self::contribution_from_built(self::header_rows($feed_id, $feed_settings), $wanted);
	}

	/**
	 * Turn one built feed's rows into a contribution, scoped to the sources asked for.
	 *
	 * The half of feed_contribution() that does not care where the rows came
	 * from. Extracted so the source-keyed path runs the SAME scoping, product
	 * expansion and de-duplication — skipping them read a raw header row as
	 * 2,069 reviews where the scoped path read 894, which is two numbers for one
	 * badge depending on which way it was asked.
	 *
	 * @param array{rows: array, hydrated: array, errored: bool} $built  Built feed.
	 * @param array<int, string>                                 $wanted Source ids.
	 *
	 * @return array{errored: bool, contribution: array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|null, chosen: array<int, string>}
	 */
	protected static function contribution_from_built(array $built, array $wanted): array
	{
		$none = array( 'errored' => false, 'contribution' => null, 'chosen' => array() );

		if (! empty($built['errored'])) {
			return array( 'errored' => true, 'contribution' => null, 'chosen' => array() );
		}

		if (empty($built['rows'])) {
			return $none;
		}

		$merged = array();
		$chosen = array();
		$seen   = array();

		foreach ($wanted as $source_id) {
			$scoped = self::scope_to_source($built['rows'], $source_id);

			// The header can be the FLATTENED shape written by
			// Feed::update_header_cache_from_source(), which drops the nested
			// product lists.
			if (empty($scoped)) {
				$scoped = self::scope_from_sources($built['hydrated'], $source_id);
			}

			if (empty($scoped)) {
				continue;
			}

			$fresh = self::unseen_rows(self::expand_products($scoped), $seen);

			if (empty($fresh)) {
				continue;
			}

			$merged   = array_merge($merged, $fresh);
			$chosen[] = $source_id;
		}

		if (empty($merged)) {
			return $none;
		}

		return array(
			'errored'      => false,
			'contribution' => self::rows_to_contribution($merged),
			'chosen'       => $chosen,
		);
	}

	/**
	 * One feed's merged rows expressed as a single contribution.
	 *
	 * @param array $rows Deduped rows for one feed.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}
	 */
	protected static function rows_to_contribution(array $rows): array
	{
		$parser  = new Parser();
		$booking = FeedDisplay::get_booking_header_rating($rows);
		$count   = (int) $parser->get_num_ratings($rows);

		if (! empty($booking['is_booking_only']) && Util::sbr_is_pro() && (float) $booking['score'] > 0) {
			return array(
				'rating'    => (float) $booking['score'],
				'count'     => $count,
				'resolved'  => true,
				'scale'     => 10,
				'word'      => (string) $booking['word'],
				'providers' => array(),
			);
		}

		// Raw rows, exactly as get_aggregate() hands them to Parser. Normalising a
		// mixed 0-10 source onto the 5-point scale here would make a two-source
		// badge disagree with a one-source badge over the same feed, and on Lite
		// with the feed header on the same page. combine() converts across feeds,
		// where there is no single-feed answer to disagree with.
		return array(
			'rating'    => (float) $parser->get_average_rating($rows),
			'count'     => $count,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);
	}

	/**
	 * Count-weighted mean over contributions.
	 *
	 * All-Booking keeps the native 0-10 score and its word. Mixed converts the
	 * 0-10 ones onto the 5-point scale first — otherwise Booking's reviews
	 * count toward the divisor while its score never moves the number.
	 *
	 * @param array<int, array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}> $contributions Resolved contributions.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}
	 */
	protected static function combine(array $contributions): array
	{
		$all_ten = true;

		foreach ($contributions as $c) {
			if ((int) $c['scale'] !== 10) {
				$all_ten = false;

				break;
			}
		}

		$sum     = 0.0;
		$total   = 0;
		$weights = 0;
		$word    = '';
		$biggest = -1;

		// One loop for the numerator AND the divisor. They were two, each with its
		// own idea of a contributor's weight, which is how they drifted apart.

		foreach ($contributions as $c) {
			$count  = max(0, (int) $c['count']);
			$rating = (float) $c['rating'];

			if (! $all_ten && (int) $c['scale'] === 10) {
				$rating = $rating / 2;
			}

			// A contributor with no count is weighted as one ONLY if it actually
			// carries a rating. The rule used to be an unconditional 1, and a
			// source with no reviews has rating 0.0 — so it fed `0.0 x 1` into the
			// numerator and 1 into the divisor and pulled the mean toward zero.
			// Measured: 4.0 from ten reviews beside one empty source reported 3.6,
			// and 5.0 from five reviews beside two empty ones reported 3.6. Large
			// siblings hide it, which is why it survived — a new site with few
			// reviews is where it shows.
			$weight = $count > 0 ? $count : ( $rating > 0 ? 1 : 0 );

			$sum     += $rating * $weight;
			$total   += $count;
			$weights += $weight;

			if ($all_ten && $count > $biggest) {
				$biggest = $count;
				$word    = (string) $c['word'];
			}
		}

		return array(
			'rating'    => $weights > 0 ? round($sum / $weights, 1) : 0.0,
			'count'     => $total,
			'resolved'  => true,
			'scale'     => $all_ten ? 10 : 5,
			'word'      => $all_ten ? $word : '',
			'providers' => array(),
		);
	}

	/**
	 * Mark an empty aggregate as a deliberate refusal, not a miss.
	 *
	 * Callers fall back to the single-feed path on an unresolved map; without
	 * the flag they would do it after a fail-closed too, publishing the primary
	 * feed's whole rating.
	 *
	 * @param array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>} $empty The empty aggregate.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed: bool} Same, flagged.
	 */
	protected static function closed(array $empty): array
	{
		$empty['failed'] = true;

		return $empty;
	}

	/**
	 * A badge's own source ids, cleaned.
	 *
	 * Scalars only, cast to string, blanks and duplicates dropped, sorted so two
	 * badges over the same set share a cache row. Past the bound it returns
	 * nothing rather than a truncated list: half a selection aggregated as if it
	 * were the whole is the failure this refuses.
	 *
	 * @param mixed $sources Raw value from the caller.
	 *
	 * @return array<int, string>
	 */
	protected static function sanitize_source_ids($sources): array
	{
		if (! is_array($sources)) {
			return array();
		}

		$ids = array();

		foreach ($sources as $source) {
			if (! is_scalar($source)) {
				continue;
			}

			$id = (string) $source;

			if ($id !== '') {
				$ids[ $id ] = true;
			}
		}

		if (count($ids) > self::SOURCE_MAP_MAX_SOURCES) {
			return array();
		}

		$ids = array_keys($ids);
		sort($ids);

		return $ids;
	}

	/**
	 * Normalise and BOUND a feed-to-sources map.
	 *
	 * Derived server-side, but the block passes unregistered attributes straight
	 * through (SBR_Badge_Service::render_block), so a post author can hand-write
	 * one — treated as input. The caps are the point: each entry costs a feed
	 * build, and the transient is salted with the map, so an unbounded one is an
	 * uncached build per entry on every public render.
	 *
	 * @param mixed $map Raw value.
	 *
	 * @return array<int, string[]> Bounded map.
	 */
	protected static function sanitize_source_map($map): array
	{
		if (! is_array($map)) {
			return array();
		}

		// Over the bound: no map at all, so the caller falls back to the single
		// feed it was given instead of aggregating a truncated set. Counted before
		// anything is built, so a forged map costs no queries.
		if (count($map) > self::SOURCE_MAP_MAX_FEEDS) {
			return array();
		}

		$out = array();

		foreach ($map as $feed_id => $sources) {
			$feed_id = absint($feed_id);

			if ($feed_id === 0 || ! is_array($sources)) {
				continue;
			}

			if (count($sources) > self::SOURCE_MAP_MAX_SOURCES) {
				return array();
			}

			$clean = array();

			foreach ($sources as $source) {
				if (! is_scalar($source)) {
					continue;
				}

				$source = sanitize_text_field((string) $source);

				if ($source !== '') {
					$clean[] = $source;
				}
			}

			if (! empty($clean)) {
				$out[ $feed_id ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * Break a multi-product source into the products it is made of.
	 *
	 * Two Woo/EDD sources can hold the same product, and merged as source rows
	 * the double count hides inside their totals — the overlap SMASH-1412 exists
	 * for (Feed.php:461-467). Expanded into products, the business-id dedup sees
	 * it. A source with nothing nested is returned untouched.
	 *
	 * @param array $rows Scoped rows for one source.
	 *
	 * @return array Product rows where there were products, the input otherwise.
	 */
	protected static function expand_products(array $rows): array
	{
		$lists = array( 'products', 'direct_products', 'downloads', 'direct_downloads' );
		$out   = array();

		foreach ($rows as $row) {
			$info     = isset($row['info']) && is_array($row['info']) ? $row['info'] : array();
			$products = array();

			foreach ($lists as $list) {
				if (empty($info[ $list ]) || ! is_array($info[ $list ])) {
					continue;
				}

				foreach ($info[ $list ] as $product) {
					if (! is_array($product) || ! isset($product['id'])) {
						continue;
					}

					$products[] = array(
						'info' => array(
							'id'             => (string) $product['id'],
							'name'           => isset($product['name']) ? $product['name'] : '',
							'average_rating' => isset($product['average_rating']) ? (float) $product['average_rating'] : 0.0,
							'review_count'   => isset($product['review_count']) ? (int) $product['review_count'] : 0,
						),
					);
				}
			}

			if (empty($products)) {
				$out[] = $row;

				continue;
			}

			$out = array_merge($out, $products);
		}

		return $out;
	}

	/**
	 * The rows of a scoped set that are not already in the merge.
	 *
	 * @param array $scoped Rows for one source.
	 * @param array $seen   Business ids already merged, updated in place.
	 *
	 * @return array The rows worth adding.
	 */
	protected static function unseen_rows(array $scoped, array &$seen): array
	{
		$fresh = array();

		foreach ($scoped as $row) {
			$row_id = isset($row['info']['id']) && is_scalar($row['info']['id'])
				? (string) $row['info']['id']
				: '';

			// A row with no id cannot be matched against anything, so it is
			// kept rather than guessed at.
			if ($row_id !== '') {
				if (isset($seen[ $row_id ])) {
					continue;
				}

				$seen[ $row_id ] = true;
			}

			$fresh[] = $row;
		}

		return $fresh;
	}

	/**
	 * How long a badge aggregate stays cached.
	 *
	 * One call site for the filter, so both aggregate paths age together.
	 *
	 * @param int $feed_id Feed the aggregate belongs to.
	 *
	 * @return int Seconds.
	 */
	protected static function cache_ttl(int $feed_id): int
	{
		/**
		 * How long a badge's cached aggregate stays fresh.
		 *
		 * @since 2.11.0
		 *
		 * @param int $ttl     Seconds. Default 1 hour.
		 * @param int $feed_id Feed the aggregate belongs to.
		 */
		// The bundled php-stubs declares apply_filters($hook, $value); WP's is
		// variadic. CI's szepeviktor stubs type it variadic, so this only surfaces
		// locally — same note as the baselined cases.
		return (int) apply_filters('sbr_badge_cache_ttl', HOUR_IN_SECONDS, $feed_id);
	}

	/**
	 * A feed's header rows, built and backfilled — the input the rating maths eats.
	 *
	 * Extracted from get_aggregate() so the multi-source path reuses it rather
	 * than keeping a second copy of the Pro/Lite Feed split.
	 *
	 * @param int   $feed_id  Feed id.
	 * @param array $settings Feed settings, already loaded by the caller.
	 *
	 * @return array{rows: array, hydrated: array, errored: bool} Rows, the feed's
	 *         hydrated sources, and whether the feed itself failed.
	 */
	protected static function header_rows(int $feed_id, array $settings, string $cache_id = ''): array
	{
		// A set of sources caches under its own key rather than a feed's; the
		// column is a varchar and Review Alerts already does this. Everything
		// below is unchanged, so both callers build the feed the same way.
		$cache_id = $cache_id !== '' ? $cache_id : (string) $feed_id;

		// Mirror the canonical render path (SBR_Feed_Builder:219 / Pro
		// ShortcodeService / SBR_Schema_Service::build_feed_object): on Pro build
		// Pro\Feed + Pro\FeedCache, because only Pro\Feed::init() calls
		// FeedCache::set_wpml() (class/Pro/Feed.php:33). Hardcoding the Common\*
		// classes would read and write the UNSUFFIXED cache row on Pro+WPML —
		// a duplicate remote refresh, and a badge rating aggregated from a
		// different language than the feed on the same page displays.
		if (Util::sbr_is_pro()) {
			$feed = new \SmashBalloon\Reviews\Pro\Feed(
				$settings,
				$cache_id,
				new \SmashBalloon\Reviews\Pro\FeedCache($cache_id, 2 * DAY_IN_SECONDS)
			);
		} else {
			$feed = new Feed($settings, $cache_id, new FeedCache($cache_id, 2 * DAY_IN_SECONDS));
		}

		$feed->init();

		// `errored` separates a broken feed from one that simply has no header
		// data. The caller fails closed on the first and skips the second.
		if (! empty($feed->get_errors())) {
			return array( 'rows' => array(), 'hydrated' => array(), 'errored' => true );
		}

		$feed->get_set_cache();

		$header_data = $feed->get_header_data();
		$header_data = is_array($header_data) ? $header_data : array();

		if (empty($header_data)) {
			return array( 'rows' => array(), 'hydrated' => array(), 'errored' => false );
		}

		$parser = new Parser();

		// The HYDRATED sources, because scope_from_sources() matches on a decoded
		// `info` key that raw account_id strings do not have. Handed back rather
		// than re-read: re-hydrating wipes the list (Feed.php:1174/1196).
		$hydrated = $feed->get_settings()['sources'] ?? array();

		return array(
			'rows'     => $parser->backfill_review_counts($header_data, $feed->get_posts()),
			'hydrated' => is_array($hydrated) ? $hydrated : array(),
			'errored'  => false,
		);
	}

	/**
	 * One feed's rating, review count and platforms.
	 *
	 * The single-feed path: the inline shortcode, the widget and the block all
	 * name a feed. A SAVED badge names sources instead and goes through
	 * get_aggregate_for_map(), which may span several feeds.
	 *
	 * @param int    $feed_id   Feed id.
	 * @param string $source_id Narrow to one source within the feed, or '' for
	 *                          the whole feed.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool} Rating, count and the platforms behind them.
	 *
	 * @phpstan-param int|string|null $feed_id
	 * @phpstan-param string          $source_id
	 */
	// Deliberately untyped, with absint() below: a public static with call sites
	// this repo does not own, where narrowing turns a tolerated null into a fatal.
	public static function get_aggregate($feed_id, $source_id = '')
	{
		$feed_id = absint($feed_id);
		$source_id = (string) $source_id;
		$empty   = array(
			'rating'    => 0.0,
			'count'     => 0,
			'resolved'  => false,
			'scale'     => 5,
			'word'      => '',
			'providers' => array(),
		);

		// Read BEFORE touching feed settings. The probe key needs no settings —
		// language resolves from the global option — so a cached hit (positive or
		// negative) short-circuits without a settings query. Reading only after
		// the settings guard would make the negative cache write-only: it would
		// be stored on the missing-settings path and never read back, turning
		// every page view into a fresh set_transient() write.
		$probe_key = self::cache_key($feed_id, array(), $source_id);
		$cached    = self::read_cache($probe_key);

		if ($cached !== null) {
			return $cached;
		}

		$settings = SBR_Settings::get_settings_by_feed_id($feed_id, false, false);

		if (! is_array($settings)) {
			// Deleted feed, or the `feed=0` shortcode default. Flagged so the map
			// path tells it apart from a feed that simply has no data.
			return self::negative_cache($probe_key, self::closed($empty));
		}

		// A feed may override `localization`, in which case its resolved language
		// — and therefore its key — differs from the global probe. Only then is a
		// second read needed.
		$cache_key = self::cache_key($feed_id, $settings, $source_id);

		if ($cache_key !== $probe_key) {
			$cached = self::read_cache($cache_key);

			if ($cached !== null) {
				return $cached;
			}
		}

		// Mirror the canonical render path (SBR_Feed_Builder:219 / Pro
		// ShortcodeService / SBR_Schema_Service::build_feed_object): on Pro build
		// Pro\Feed + Pro\FeedCache, because only Pro\Feed::init() calls
		// FeedCache::set_wpml() (class/Pro/Feed.php:33). Hardcoding the Common\*
		// classes would read and write the UNSUFFIXED cache row on Pro+WPML —
		// a duplicate remote refresh, and a badge rating aggregated from a
		// different language than the feed on the same page displays.
		$parser      = new Parser();
		$built       = self::header_rows($feed_id, $settings);
		$header_data = $built['rows'];

		if (! empty($built['errored'])) {
			return self::negative_cache($cache_key, self::closed($empty));
		}

		if (empty($header_data)) {
			return self::negative_cache($cache_key, $empty);
		}

		// Per-source scoping. The pitch asks for ratings "per-product AND
		// site-wide" and leaves the key open ("Woo/EDD product ID, source?").
		// It is the SOURCE id: WooCommerce_Review_Cache_Handler and its EDD twin
		// both normalise a review with `source.id => (string) $product_id`, so
		// for those providers the source id IS the product id, while for Google
		// it is the place id. One key, every provider.
		if ($source_id !== '') {
			$scoped = self::scope_to_source($header_data, $source_id);

			// The header can be the FLATTENED shape written by
			// Feed::update_header_cache_from_source() (Feed.php:426), which keeps
			// only id/name/rating/total_rating/url and drops the nested product
			// lists — so a valid product id resolves to nothing there. The feed's
			// own sources still carry those lists.
			//
			// Read them, do NOT re-hydrate. init() above already ran
			// hydrate_sources() (Feed.php:85-87), and that method is NOT
			// idempotent: it ends with an unconditional
			// `$this->settings['sources'] = $hydrated_sources` (Feed.php:1196)
			// while its inner match requires `! is_array($single_source)`
			// (:1174). Fed rows it has already hydrated, it matches nothing and
			// assigns an empty array — wiping the very lists we are about to
			// read. Measured on feed 194: 1 source before, 0 after.
			if (empty($scoped)) {
				// Hydrated, not the raw $settings ones: scope_from_sources()
				// matches on a decoded `info` key those strings do not have.
				$scoped = self::scope_from_sources($built['hydrated'], $source_id);
			}

			$header_data = $scoped;

			if (empty($header_data)) {
				// Asked for a source this feed does not carry. Render nothing
				// rather than silently falling back to the feed-wide average,
				// which would put someone else's rating under a product name.
				return self::negative_cache($cache_key, $empty);
			}
		}

		// Booking-only feeds carry a native 0-10 score in info.review_score and
		// frequently NO info.rating at all (see the booking_header() fixture in
		// tests/Unit/Smash1756SchemaServiceTest.php). Parser::get_average_rating()
		// skips sources without a `rating` key, so it would return 0.0 while the
		// count stays real — a hotel with thousands of reviews would render
		// "No reviews yet". Even when a rating IS present, reporting 4.8/5 next
		// to a feed header showing 9.5/10 puts two different numbers for the same
		// feed on one page. The visible header and SBR_Schema_Service both route
		// through get_booking_header_rating() for exactly this reason; so do we.
		// Gated on Pro for the same reason SBR_Schema_Service.php:377 is: the
		// Booking header renders only in templates/frontend/pro/header.php, so on
		// Lite (or a downgraded licence still holding cached Booking data) the
		// visible header and the JSON-LD both stay on the 5-star average. An
		// ungated badge would print "9.5 out of 10" beside a 5-star header — the
		// scale disagreement this whole change exists to remove.
		$booking = FeedDisplay::get_booking_header_rating($header_data);

		// Which platforms this rating is made of, in the feed's own order — the
		// logo row is drawn from it.
		// Derived here rather than in the template because it has to be cached
		// with the numbers it describes — a logo row listing a source that no
		// longer contributes to the average is the same class of lie as a stale
		// rating.
		$providers = self::providers_for_sources($settings['sources'] ?? array(), $source_id);

		if (! empty($booking['is_booking_only']) && Util::sbr_is_pro() && (float) $booking['score'] > 0) {
			$aggregate = array(
				'rating'    => (float) $booking['score'],
				'count'     => (int) $parser->get_num_ratings($header_data),
				'resolved'  => true,
				'scale'     => 10,
				'word'      => (string) $booking['word'],
				'providers' => $providers,
			);
		} else {
			$aggregate = array(
				'rating'    => (float) $parser->get_average_rating($header_data),
				'count'     => (int) $parser->get_num_ratings($header_data),
				'resolved'  => true,
				'scale'     => 5,
				'word'      => '',
				'providers' => $providers,
			);
		}

		$ttl = self::cache_ttl($feed_id);

		if ($ttl > 0) {
			set_transient($cache_key, $aggregate, $ttl);
		}

		return $aggregate;
	}

	/**
	 * Build the transient key for a feed's aggregate.
	 *
	 * Two things matter here.
	 *
	 * LANGUAGE: it must be the RESOLVED per-request language, not the raw
	 * `localization` setting. Util::get_settings_language() returns the setting
	 * itself — on a WPML site that is the literal string 'wpml' for every
	 * translation, which would collapse all languages onto one key and let the
	 * first language rendered pin its rating for the whole TTL. Pro\FeedCache
	 * suffixes its key with $settings['apiCallLanguage'] (Pro\Feed::init():33),
	 * which comes from Util::get_api_call_language() — so we key on the same
	 * value the feed cache itself keys on.
	 *
	 * VERSION: the key carries a per-feed version salt. Invalidation bumps that
	 * integer instead of deleting rows, which is the only approach that works
	 * under a persistent object cache — see clear_cache().
	 *
	 * @param int    $feed_id   Feed ID.
	 * @param array  $settings  Hydrated feed settings, or an empty array to
	 *                          resolve the language from the global option.
	 * @param string $source_id Optional source id; keys a product-scoped badge
	 *                          separately from the feed-wide one.
	 *
	 * @return string
	 */
	protected static function cache_key($feed_id, $settings, $source_id = '')
	{
		$language = Util::get_api_call_language($settings);
		$language = is_string($language) && $language !== '' ? $language : 'default';

		// The tier is part of the key because it changes the VALUE, not just the
		// presentation: a Booking-only feed caches a 0-10 aggregate on Pro and a
		// 5-star one on Lite. Without this, a Pro->Lite downgrade would keep
		// serving the cached scale=10 entry for a full TTL (the read at the top
		// of get_aggregate() returns before the Pro gate runs), printing
		// "9.5 out of 10" beside a Lite 5-star header. Nothing clears badge
		// caches on a licence transition - clear_cache() only fires from
		// sbr_after_cron_refresh - so the key has to carry it.
		$tier = Util::sbr_is_pro() ? 'pro' : 'lite';

		// The source belongs in the key: without it a product-scoped badge and a
		// feed-wide one collide and whichever renders first wins for both.
		// Full digest, not a prefix: two product ids sharing the first 8 hex
		// characters would otherwise share a transient, and the first badge
		// rendered would pin its rating onto the other product for the whole
		// TTL. The whole key is ~77 chars, far under WP's 172-char limit.
		$scope = $source_id !== '' ? '_s' . md5((string) $source_id) : '';

		return self::CACHE_PREFIX . $feed_id . '_' . sanitize_key($language) . '_' . $tier . $scope . '_v' . self::cache_version($feed_id);
	}

	/**
	 * Current cache version for a feed.
	 *
	 * @param int $feed_id Feed ID.
	 *
	 * @return int
	 */
	protected static function cache_version($feed_id)
	{
		return absint(get_option(self::VERSION_PREFIX . absint($feed_id), 1));
	}

	/**
	 * Read a cached aggregate.
	 *
	 * @param string $cache_key Transient key.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed: bool}|null
	 *         Null when there is no usable entry.
	 */
	protected static function read_cache($cache_key)
	{
		$cached = get_transient($cache_key);

		if (! is_array($cached) || ! isset($cached['rating'], $cached['count'])) {
			return null;
		}

		return array(
			'rating'   => (float) $cached['rating'],
			'count'    => (int) $cached['count'],
			'scale'    => isset($cached['scale']) ? (int) $cached['scale'] : 5,
			'word'     => isset($cached['word']) ? (string) $cached['word'] : '',
			// Fail SAFE on a missing flag. An entry written before `resolved`
			// existed could be either a real aggregate or an old negative cache,
			// and we cannot tell them apart. Treating unknown as unresolved makes
			// the badge disappear for up to one TTL; treating it as resolved could
			// publish "No reviews yet" for a business that has reviews. The first
			// is a cosmetic gap, the second is a false public claim.
			'resolved' => isset($cached['resolved']) && (bool) $cached['resolved'],
			// Re-validated on the way out for the same reason the rest of this
			// list is: a transient is editable by anything with DB access, and
			// these slugs go on to pick a file path in logos.php.
			// sanitize_provider_slug(), NOT sanitize_key(): the latter strips the dot
			// out of `wordpress.org`, and provider_label() matches Util::get_providers()
			// by exact type, so the badge would lose that platform's name.
			'providers' => isset($cached['providers']) && is_array($cached['providers'])
				? array_values(
					array_filter(
						array_map(
							static function ($provider) {
								return Util::sanitize_provider_slug($provider);
							},
							array_filter($cached['providers'], 'is_scalar')
						)
					)
				)
				: array(),
			// A refusal has to survive the round trip, or a cached fail-closed
			// reads as a miss and the callers fall through to the single feed.
			'failed'   => ! empty($cached['failed']),
		);
	}

	/**
	 * Cache a failed lookup briefly.
	 *
	 * Without this, a feed that errors (or the `feed=0` shortcode default)
	 * rebuilds a Feed object — and can reach the upstream API through
	 * get_set_cache() → update_posts_cache() — on EVERY page view carrying a
	 * footer or checkout badge. The TTL is deliberately short so a feed that
	 * starts working recovers quickly.
	 *
	 * @param string $cache_key Transient key.
	 * @param array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>}|array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed: bool} $empty The empty aggregate, optionally flagged as a refusal.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool}
	 */
	protected static function negative_cache($cache_key, $empty)
	{
		/**
		 * How long a failed badge lookup is remembered before retrying.
		 *
		 * @since 2.11.0
		 *
		 * @param int $ttl Seconds. Default 5 minutes.
		 */
		$ttl = (int) apply_filters('sbr_badge_negative_cache_ttl', 5 * MINUTE_IN_SECONDS);

		if ($ttl > 0) {
			set_transient($cache_key, $empty, $ttl);
		}

		return $empty;
	}

	/**
	 * Clear a cached badge aggregate.
	 *
	 * @param int $feed_id Feed ID.
	 *
	 * @return void
	 */
	public static function clear_cache($feed_id)
	{
		$feed_id = absint($feed_id);
		$option  = self::VERSION_PREFIX . $feed_id;

		// Bump a version salt rather than deleting rows.
		//
		// The obvious implementation — scanning wp_options for
		// `_transient_<CACHE_PREFIX><id>_%` — is a SILENT NO-OP on any host with
		// a persistent object cache (Redis, Memcached, WP Engine, Kinsta,
		// Pantheon): core's set_transient() stores those in the object cache and
		// writes no wp_options row, so the scan matches nothing and stale badges
		// survive until TTL. Bumping the version changes every future key at
		// once — across all languages — regardless of the cache backend. The
		// orphaned entries expire on their own TTL.
		$next = self::cache_version($feed_id) + 1;

		update_option($option, $next, false);
	}

	/**
	 * The wrapper's CSS classes.
	 *
	 * Split out of prepare(), which was doing two jobs — deciding how the badge
	 * is dressed, and deciding what it says. Together they carried a cyclomatic
	 * complexity of 31 against a limit of 20, and the two halves shared nothing
	 * but $args.
	 *
	 * @param SbrBadgeArgs $args           Sanitised arguments.
	 *
	 * @return array<int, string>
	 */
	protected static function wrapper_classes($args)
	{
		// `sbr-tb-`, not `sbr-`: a badge renders anywhere on the site, outside
		// the feed container that scopes `sbr-`, so it carries its own prefix
		// and its own stylesheet.
		$classes = array( 'sbr-tb-badge', 'sbr-tb-style-' . $args['shape'] );

		if ($args['accent_hue'] >= 0) {
			$classes[] = 'has-accent';
		}

		return $classes;
	}

	/**
	 * The colour treatment the marks actually get.
	 *
	 * Mirrors the design's `resolvedLogos()`. Tinted forces mono whatever is
	 * stored, because the surface is one hue and brand marks fight it; auto
	 * means brand on light, mono on dark. The STORED value is never rewritten,
	 * so leaving tinted gives the author their choice back.
	 *
	 * @param array{theme: string, logo_color: string} $args Sanitised arguments.
	 *
	 * @return string brand|mono
	 */
	public static function resolved_logo_color($args)
	{
		if ($args['theme'] === 'tinted') {
			return 'mono';
		}

		if ($args['logo_color'] !== 'auto') {
			return $args['logo_color'];
		}

		return $args['theme'] === 'light' ? 'brand' : 'mono';
	}

	/**
	 * Full lockup or bare mark.
	 *
	 * Full logo is single-source only: a wordmark beside other icons reads as a
	 * list rather than a badge. Same shape of rule as the colour above, gated on
	 * the source count instead of the theme.
	 *
	 * @param array{logo_style: string} $args           Sanitised arguments.
	 * @param bool                       $is_style_shape Whether this is chip/card/medal.
	 * @param int                        $provider_count How many platforms the badge speaks for.
	 *
	 * @return string full|icon
	 */
	public static function resolved_logo_style($args, $is_style_shape, $provider_count)
	{
		if ($is_style_shape && $args['logo_style'] === 'full' && $provider_count !== 1) {
			return 'icon';
		}

		return $args['logo_style'];
	}

	/**
	 * The cached aggregate for a feed, or null. NEVER builds one.
	 *
	 * get_aggregate() falls through to the feed's refresh path on a miss, which can
	 * fire a synchronous provider fetch. Somewhere that only wants to DECORATE — the
	 * locked upsell wall — must not pay for that, or mutate a feed's cache for a user
	 * who cannot use the feature. Probes the same two keys get_aggregate() probes, then
	 * gives up.
	 *
	 * @param int    $feed_id   Feed id.
	 * @param string $source_id Scope the aggregate to one source, or '' for the feed.
	 *
	 * @return array{rating: float, count: int, resolved: bool, scale: int, word: string, providers: array<string>, failed?: bool}|null
	 */
	public static function cached_aggregate($feed_id, $source_id = '')
	{
		// (int), not absint(): absint(-4) is 4, so a negative id would read feed four's
		// row. Third time that fold has bitten on this ticket.
		$feed_id = is_numeric($feed_id) ? (int) $feed_id : 0;

		if ($feed_id < 1) {
			return null;
		}

		$cached = self::read_cache(self::cache_key($feed_id, array(), $source_id));

		if ($cached !== null) {
			return $cached;
		}

		$settings = SBR_Settings::get_settings_by_feed_id($feed_id, false, false);

		if (! is_array($settings)) {
			return null;
		}

		return self::read_cache(self::cache_key($feed_id, $settings, $source_id));
	}

	/**
	 * Whether an aggregate has a rating worth publishing.
	 *
	 * `resolved` is not the same question: a feed can resolve with nothing rated in
	 * it, and get_aggregate() answers rating 0.0 / count 0 / resolved true. Public so
	 * the email PNG asks the same question the page does — it used to gate on
	 * `resolved` alone and printed "0.0" and "Based on 0 reviews" where the page said
	 * "—" and "No reviews yet".
	 *
	 * @param float $rating Aggregate rating.
	 * @param int   $count  Aggregate review count.
	 *
	 * @return bool
	 */
	public static function is_rated($rating, $count)
	{
		return (int) $count > 0 && (float) $rating > 0;
	}

	/**
	 * The line under the rating.
	 *
	 * Four outcomes, and the order they are tested in is the whole logic:
	 * unrated wins over everything (a count is meaningless without a rating),
	 * then a hidden count, then whether a platform can be named.
	 *
	 * @param string $provider   Resolved platform name, or ''.
	 * @param int    $count      Review count.
	 * @param bool   $is_rated   Whether there is a real rating to show.
	 * @param bool   $show_count Whether the count may appear in the sentence.
	 *
	 * @return string
	 */
	protected static function subtext($provider, $count, $is_rated, $show_count)
	{
		if (! $is_rated) {
			return __('No reviews yet', 'reviews-feed');
		}

		// `count` is a global toggle, so the shapes that bake the count into
		// their subtext (trust / checkout / signature) have to drop it here —
		// only footer renders the count as a separate, already-guarded label.
		if (! $show_count) {
			return $provider !== ''
				? sprintf(
					/* translators: %s: provider name e.g. Google */
					__('Reviews on %s', 'reviews-feed'),
					$provider
				)
				: __('Verified reviews', 'reviews-feed');
		}

		if ($provider !== '') {
			return sprintf(
				/* translators: 1: number of reviews, 2: provider name e.g. Google */
				_n('%1$s review on %2$s', '%1$s reviews on %2$s', $count, 'reviews-feed'),
				number_format_i18n($count),
				$provider
			);
		}

		return sprintf(
			/* translators: %s: number of reviews */
			_n('%s review', '%s reviews', $count, 'reviews-feed'),
			number_format_i18n($count)
		);
	}

	/**
	 * Build the display-ready data array the templates consume.
	 *
	 * All translation and number formatting happens here so the templates stay
	 * pure markup plus escaping.
	 *
	 * @param SbrBadgeArgs $args Sanitised arguments.
	 * @param array{rating: float, count: int, resolved?: bool, scale?: int, word?: string}                                                                                                                                       $aggregate Rating aggregate.
	 *
	 * @return array<string, mixed>
	 */
	protected static function prepare($args, $aggregate)
	{
		$rating     = (float) $aggregate['rating'];
		$count      = (int) $aggregate['count'];
		$is_rated   = self::is_rated($rating, $count);
		$scale      = isset($aggregate['scale']) ? (int) $aggregate['scale'] : 5;
		$score_word = isset($aggregate['word']) ? (string) $aggregate['word'] : '';
		$is_ten     = ($scale === 10);

		$classes = self::wrapper_classes($args);
		$style   = self::style_shape_props($args);

		$provider = $args['provider'];

		// A saved badge names its sources, not a provider string, so the label
		// is derived when the badge speaks for exactly ONE platform.
		$aggregate_providers_count = isset($aggregate['providers']) && is_array($aggregate['providers'])
			? $aggregate['providers']
			: array();

		if ($provider === '') {
			$aggregate_providers = $aggregate_providers_count;

			if (count($aggregate_providers) === 1) {
				$provider = self::provider_label((string) $aggregate_providers[0]);
			}
		}

		// The placeholder shows the shape, not a verdict: a real-looking 0.0 over
		// "Based on 0 reviews", stars drawn full so the row has its true height,
		// and the whole thing dimmed by CSS keyed on data-placeholder. It reads as
		// rated from here down because every element must be visible for the author
		// to lay the badge out; is_placeholder is what tells the page it is not.
		$is_placeholder = ! empty($args['placeholder']);

		if ($is_placeholder) {
			$is_rated = true;
		}

		$rating_label = $is_rated ? number_format_i18n($rating, 1) : '—';

		$subtext = self::subtext($provider, $count, $is_rated, (bool) $args['count']);

		return array(
			'classes'        => implode(' ', $classes),
			'style'          => $style,
			// Read only by the style-shape templates. `data-logos` carries the
			// resolved colour treatment: auto means "brand marks on light, mono on
			// dark", which the stylesheet cannot express on its own because it
			// keys on the attribute, not on the theme.
			'theme'          => $args['theme'],
			'logos_attr'     => self::resolved_logo_color($args),
			'logo_style'     => self::resolved_logo_style($args, true, count($aggregate_providers_count)),
			'show_logos'     => $args['logos'],
			'show_rating'    => $args['rating'],
			// show_stars and show_count are set further down, once $is_ten is
			// known. They were also assigned here, which PHP resolved by keeping
			// the later pair — so the rendering was right, but the unguarded
			// version above read as the authoritative one. Deleting the later
			// line as a duplicate would have put five stars back on a 0-10
			// Booking score with nothing failing.
			// The bare number, for the chip's "(128)". The three styles phrase the
			// count differently — chip parenthesises it, card says "Based on N
			// reviews", medal says "N reviews on Platform" — so each gets the
			// phrasing it needs rather than one label bent three ways.
			'count_raw_label' => number_format_i18n($count),
			'count_based_label' => sprintf(
				/* translators: %s: number of reviews */
				_n('Based on %s review', 'Based on %s reviews', $count, 'reviews-feed'),
				number_format_i18n($count)
			),
			// "on <Platform>" only when the badge speaks for a single platform;
			// with several sources behind one number, naming one of them would
			// attribute the whole rating to it.
			'count_on_label' => ($provider !== '')
				? sprintf(
					/* translators: 1: number of reviews, 2: provider name e.g. Google */
					_n('%1$s review on %2$s', '%1$s reviews on %2$s', $count, 'reviews-feed'),
					number_format_i18n($count),
					$provider
				)
				: sprintf(
					/* translators: %s: number of reviews */
					_n('%s review', '%s reviews', $count, 'reviews-feed'),
					number_format_i18n($count)
				),
			'providers'      => isset($aggregate['providers']) && is_array($aggregate['providers'])
				? $aggregate['providers']
				: array(),
			'footer_text'    => $args['footer_text'],
			'footer_url'     => $args['footer_url'],
			'is_rated'       => $is_rated,
			'rating_label'   => $rating_label,
			'count_label'    => sprintf(
				/* translators: %s: number of reviews */
				_n('%s review', '%s reviews', $count, 'reviews-feed'),
				number_format_i18n($count)
			),
			// Follows the scale. Hardcoding "out of 5" turned a Booking 9.5 into
			// "9.5 out of 5" in the footer shape — the very header/badge
			// disagreement this change set out to remove.
			'out_of_label'   => $is_ten
				? __('out of 10', 'reviews-feed')
				: __('out of 5', 'reviews-feed'),
			'verified_label' => ! $is_rated
				? __('No reviews yet', 'reviews-feed')
				: ( ! $args['count']
					? sprintf(
						/* translators: %s: average rating */
						__('%s average rating', 'reviews-feed'),
						number_format_i18n($rating, 1)
					)
					: sprintf(
					/* translators: 1: average rating, 2: number of reviews */
						_n('%1$s · %2$s verified review', '%1$s · %2$s verified reviews', $count, 'reviews-feed'),
						number_format_i18n($rating, 1),
						number_format_i18n($count)
					) ),
			'rated_by_label' => ! $is_rated
				? __('No reviews yet', 'reviews-feed')
				: ( ! $args['count']
					? __('Verified reviews', 'reviews-feed')
					: sprintf(
					/* translators: %s: number of customers */
						_n('Rated by %s customer', 'Rated by %s customers', $count, 'reviews-feed'),
						number_format_i18n($count)
					) ),
			// Every rail exposes a "Provider label" control, but only the trust
			// shape folded it into its subtext — so setting it on footer,
			// checkout or signature silently did nothing. One translated
			// fragment those three append, rather than duplicating every count
			// string per provider.
			'provider_suffix' => ($is_rated && $provider !== '')
				? sprintf(
					/* translators: %s: provider name, e.g. Google. Appended after a review count. */
					__('on %s', 'reviews-feed'),
					$provider
				)
				: '',
			'subtext'        => $subtext,
			// A 0-10 Booking score cannot be drawn on five stars without silently
			// rescaling it, which is how the badge and the feed header ended up
			// disagreeing. The header solves it by showing the score and its word
			// instead of stars; the badge does the same.
			'show_stars'     => $args['stars'] && ! $is_ten,
			// The star row must draw the RATED value, not the raw one. A feed can
			// report a rating with every count empty (Parser falls back to an
			// unweighted mean — tests/Unit/MultiSourceAggregationTest.php
			// ::test_all_zero_counts_falls_back_to_unweighted_mean), which makes
			// is_rated false. Drawing the raw 4.5 there would put four and a half
			// filled stars and aria-label "4.5 out of 5 stars" next to "—" and
			// "No reviews yet" — the same contradiction score_word was guarded
			// against. Unrated draws the dimmed "Not rated" row instead.
			'stars_rating'   => $is_placeholder ? (float) $scale : ($is_rated ? $rating : 0.0),
			'is_placeholder' => $is_placeholder,
			'scale'          => $scale,
			'score_word'     => $score_word,
			'show_count'     => $args['count'],
			'show_powered_by' => self::show_powered_by($args),
			// FORCED, as distinct from "on". show_powered_by() is true whenever
			// the tier forces it OR a caller opted in; a saved badge has no
			// attribution control and stores powered_by => true by default, so
			// reading that flag in the footer made the attribution win every
			// time and the author's own footer text never rendered.
			'powered_by_forced' => self::show_powered_by(array( 'powered_by' => false )),
			'powered_by_url' => 'https://smashballoon.com/reviews-feed/',
		);
	}


	/**
	 * Decide whether the "Powered by Reviews Feed" line renders.
	 *
	 * Branding removal is a **Pro Plus** capability, matching the tier the rest
	 * of the plugin already uses: SBR_Review_Alert_Service::save_popup_ajax()
	 * forces `show_powered_by = true` for anyone below Pro Plus
	 * (class/Common/ReviewAlerts/SBR_Review_Alert_Service.php:837-845). Gating
	 * badges at plain Pro instead would let a Pro site strip badge attribution
	 * while still being forced to show it on review alerts.
	 *
	 * Enforced HERE, in the renderer, rather than by hiding a control. All three
	 * rails funnel through render(), so a site below Pro Plus cannot re-enable by
	 * hand-writing `powered_by="false"` in a shortcode, POSTing a widget
	 * instance, or setting the block attribute. (Review Alerts enforces the same
	 * rule on save; enforcing at render covers rails that have no save step.)
	 *
	 * Intentionally NOT filterable: a filter would be a supported bypass.
	 *
	 * The tier is injectable so both branches are reachable in a unit test.
	 * `SBR_PRO` is a constant defined by the Pro build, so a test cannot toggle
	 * it — without this parameter the Pro Plus branch would have zero coverage
	 * and a gate wrongly written against sbr_is_pro() would still pass.
	 *
	 * @param array     $args        Sanitised arguments.
	 * @param bool|null $is_pro_plus Tier override; null resolves the real licence.
	 *
	 * @return bool
	 */
	// Public because the email image has to reach the SAME answer. The gate is
	// documented as unfilterable; an endpoint that could not ask it simply
	// skipped it, which is the loudest possible way for a gate to be optional.
	public static function show_powered_by($args, $is_pro_plus = null)
	{
		if ($is_pro_plus === null) {
			$is_pro_plus = Util::sbr_is_pro_plus();
		}

		if (! $is_pro_plus) {
			return true;
		}

		// `! empty()` rather than a direct read: a widget instance saved before
		// this option existed carries no `powered_by` key at all, and an
		// unguarded read would emit an "Undefined array key" warning.
		return ! empty($args['powered_by']);
	}


	/**
	 * Display name for a provider slug.
	 *
	 * Reads Util::get_providers(), the same list the source picker and the feed
	 * header label from, rather than title-casing the slug — that would print
	 * "Aliexpress" and "Tripadvisor" where the brands are AliExpress and
	 * Tripadvisor. An unlisted slug returns empty, and the caller falls back to
	 * phrasing that names no platform at all.
	 *
	 * @param string $slug Provider slug, e.g. 'google'.
	 *
	 * @return string Display name, empty when unknown.
	 */
	// Public alongside providers_for_sources(), for the same reason: the All
	// Badges list needs the display name, and Util::get_providers() is where
	// the plugin already keeps it. A second map — the design sketches one in
	// JS — would be a second place for "WordPress.org" to become "Wordpress".
	public static function provider_label($slug)
	{
		foreach (Util::get_providers() as $provider) {
			if (isset($provider['type'], $provider['name']) && $provider['type'] === $slug) {
				return (string) $provider['name'];
			}
		}

		return '';
	}

	/**
	 * The distinct platforms behind a feed's sources, in the feed's own order.
	 *
	 * Read from the sources table rather than the header: a header entry carries
	 * `info` (id / name / rating / total_rating) and no provider at all, which is
	 * why the subtext has always taken its provider name from an argument. The
	 * feed's `sources` setting holds bare account_id strings — verified live on
	 * feed 197, whose single source is the string '1278886371333186376' — so the
	 * platform has to be looked up.
	 *
	 * One query for all of them, not one per source: a five-source badge on a
	 * cold cache would otherwise cost five round trips before it draws.
	 *
	 * @param array  $sources   Feed `sources` setting.
	 * @param string $source_id When set, the badge is scoped to one source and
	 *                          the row shows only that platform.
	 *
	 * @return array<int, string> Provider slugs, deduplicated.
	 */
	// Public so the All Badges list can label a row with the SAME translation
	// the badge itself uses. A second account_id -> provider lookup for the
	// list would be a second chance to disagree with what the badge draws.
	public static function providers_for_sources($sources, $source_id = '')
	{
		global $wpdb;

		if (! is_array($sources)) {
			return array();
		}

		$account_ids = array();

		foreach ($sources as $source) {
			// Post-hydration rows are arrays; pre-hydration they are the bare id.
			// Both shapes reach here depending on who resolved the settings, so
			// both are handled rather than assumed.
			if (is_array($source)) {
				$candidate = $source['account_id'] ?? ($source['id'] ?? '');
			} else {
				$candidate = $source;
			}

			if (is_scalar($candidate) && (string) $candidate !== '') {
				$account_ids[] = (string) $candidate;
			}
		}

		if ($source_id !== '') {
			$account_ids = array_intersect($account_ids, array( $source_id ));
		}

		$account_ids = array_values(array_unique($account_ids));

		if (empty($account_ids)) {
			return array();
		}

		$table        = $wpdb->prefix . SBR_SOURCES_TABLE;
		$placeholders = implode(',', array_fill(0, count($account_ids), '%s'));

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list, the values are bound below.
		$sql = "SELECT account_id, provider FROM {$table} WHERE account_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; the result is cached with the aggregate by the caller.
		$rows = $wpdb->get_results($wpdb->prepare($sql, $account_ids), ARRAY_A);

		if (! is_array($rows)) {
			return array();
		}

		// Index by account id so the feed's own ordering survives — the query
		// returns rows in whatever order the engine likes, and the logo row
		// should read left-to-right the way the sources are listed.
		$by_account = array();

		foreach ($rows as $row) {
			$account = isset($row['account_id']) ? (string) $row['account_id'] : '';
			// Keeps the dot in `wordpress.org`; sanitize_key() would drop it and
			// provider_label() matches Util::get_providers() by exact type.
			$slug    = isset($row['provider']) ? Util::sanitize_provider_slug($row['provider']) : '';

			if ($account !== '' && $slug !== '') {
				$by_account[$account] = $slug;
			}
		}

		$providers = array();

		foreach ($account_ids as $account) {
			if (isset($by_account[$account]) && ! in_array($by_account[$account], $providers, true)) {
				$providers[] = $by_account[$account];
			}
		}

		return $providers;
	}

	/**
	 * Inline custom properties for a style shape.
	 *
	 * Only hues travel inline. The stylesheet turns each one into a colour per
	 * theme — light and dark derive different star lightnesses from the same
	 * accent, and tinted ignores the accent entirely because its stars are
	 * white. Resolving to a hex here would flatten all three into whichever
	 * theme happened to be selected when the author picked the colour.
	 *
	 * @param SbrBadgeArgs $args Sanitised arguments.
	 *
	 * @return string Style attribute value, empty when nothing is set.
	 */
	protected static function style_shape_props($args)
	{
		$parts = array();

		if ($args['accent_hue'] >= 0) {
			$parts[] = '--sbr-tb-star-h:' . $args['accent_hue'];
		}

		// Only meaningful on tinted, and only emitted there: a stray tint hue on
		// a light badge is dead weight in the attribute.
		if ($args['theme'] === 'tinted' && $args['tint_hue'] >= 0) {
			$parts[] = '--sbr-tb-tint-h:' . $args['tint_hue'];
		}

		return implode(';', $parts);
	}
}
