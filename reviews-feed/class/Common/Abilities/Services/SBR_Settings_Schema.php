<?php

namespace SmashBalloon\Reviews\Common\Abilities\Services;

use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed allowlist of the settings exposed through the Abilities API.
 *
 * This is the single source of truth for what an AI agent may read or write.
 * Anything not listed here is never exposed — provider API keys (`sbr_apikeys`),
 * source access tokens, the encrypted per-source `info` blob, license data,
 * and Custom CSS/JS are deliberately absent.
 *
 * Per-feed keys are a curated subset of sbr_settings_defaults()
 * (class/sbr-functions.php) — the plugin defines ~150 feed-setting keys
 * (many of them nested font/padding/margin objects); this schema exposes the
 * scalar layout/display/filter/sort keys an agent can usefully read or set,
 * the same curation shape the canonical Instagram Feed port applies. Global
 * keys map onto the `sbr_settings` option (sbr_plugin_settings_defaults()),
 * restricted to the Advanced tab's live (non-commented-out) toggles.
 *
 * Mirrors InstagramFeed\Abilities\Services\SBI_Settings_Schema (AgDR-0503).
 *
 * @since SBR-499
 */
class SBR_Settings_Schema
{
	const MAX_STRING_LENGTH = 500;

	/**
	 * Hex colour: #rgb, #rrggbb — or empty (plugin default).
	 *
	 * Deliberately 3/6-digit only (AgDR-0504 finding B14): every colour key
	 * here is rendered through DisplayElements::sanitize_color(), which for a
	 * non-`rgba(...)` string delegates to WordPress' own sanitize_hex_color() —
	 * 3 or 6 hex digits only. An 8-digit #rrggbbaa value used to validate here
	 * and then silently vanish on render (sanitize_hex_color() returns null for
	 * anything else), so an agent had no way to know its write was inert. Once
	 * the renderer accepts an alpha channel (e.g. by routing through an
	 * rgba-aware sanitizer), widen this pattern to match — until then, the
	 * schema must not advertise a format the plugin cannot display.
	 */
	const COLOR_PATTERN = '^(#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}))?$';

	/**
	 * Feed settings whose visual effect on the live page comes ONLY from the
	 * pre-rendered `feed_style` CSS (SBR-577, QA 2.2 B).
	 *
	 * The Feed Builder generates `feed_style` in the browser, from the `style`
	 * templates on the controls in SBR_Customize_Tab (e.g. `.sb-item-rating` =>
	 * `color:{{value}}`), and posts it on save (SBR_Feed_Saver_Manager). The
	 * front end prints that stored string verbatim (FeedDisplay::custom_styles())
	 * and PHP has no generator for it, so writing one of these keys through
	 * update-feed-settings saves the value but changes nothing on the page until
	 * someone re-saves the feed in the Feed Builder.
	 *
	 * Derived as: every key of feed_settings() whose SBR_Customize_Tab control
	 * declares a `style` template. Pinned against that file by
	 * Sbr577FeedStyleOnlyWarningTest, so a new styled control cannot slip past.
	 *
	 * @var string[]
	 */
	const FEED_STYLE_ONLY_KEYS = array(
		'verticalSpacing',
		'headingColor',
		'headerButtonColor',
		'headerButtonBg',
		'boxedBackgroundColor',
		'ratingIconColor',
		'loadButtonColor',
		'loadButtonBg',
	);

	/**
	 * Per-feed settings (subset of sbr_settings_defaults()).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all_feed_settings()
	{
		return array(
			// Live slugs only — mirrors SBR_Feed_Builder::get_templates_list();
			// 'grid' is a real switch case in SBR_Feed_Templates_Settings but its
			// UI entry is commented out there, so it is not offered here either
			// (same "commented-out = not exposed" rule global_settings() already
			// applies to the Advanced tab).
			//
			// Create-only (AgDR-0504 finding C2): applied by SBR_Create_Feed at
			// creation time, the same way the Feed Builder's own AJAX handler
			// applies it for a new feed (SBR_Feed_Saver_Manager::builder_update()
			// -> get_feed_settings_by_feed_templates()). Re-templating an
			// existing feed is not something the plugin's own save path does
			// either, so this is marked read-only for update-feed-settings via
			// SBR_Settings_Schema::CREATE_ONLY_KEYS rather than `writable`
			// (create-feed still needs to accept it).
			'feedTemplate' => array(
				'tier' => 'templateModal',
				'type' => 'string',
				'enum' => array(
					'default',
					'simplecards',
					'masonry',
					'singlereview',
					'showcasecarousel',
					'carousel',
					'gridcarousel',
				),
				'description' => 'Feed template slug applied when the feed is created. '
					. 'Overrides several layout/color settings with the template\'s own values — see create-feed\'s `warnings`.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			),
			// AgDR-0504 finding B2: 'grid' stays out of the enum -- same
			// "commented-out = not exposed" rule as feedTemplate above. The
			// toggleset's live options in SBR_Customize_Tab::get_layout_controls()
			// are list/masonry/carousel only; the grid option there is
			// commented out.
			'layout' => array(
				'type' => 'string',
				'enum' => array('list', 'carousel', 'masonry'),
				// Carousel is upsell-locked in the customizer (upsellModal on the option).
				'tier_values' => array('carousel' => 'carouselModal'),
				'description' => 'Feed layout.',
			),
			// Bounds derived from SBR_Customize_Tab::get_layout_controls()'s
			// verticalSpacing/horizontalSpacing sliders (AgDR-0504 finding B2):
			// neither control declares its own min/max, so the customizer's
			// shared Slider.js component falls back to its own defaults
			// (min 0, max 100). The old declared max of 200 was invented and
			// wider than what the UI slider can actually produce.
			'verticalSpacing' => array(
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 100,
				'description' => 'Vertical spacing between reviews, in px.',
			),
			'horizontalSpacing' => array(
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 100,
				'description' => 'Horizontal spacing between reviews, in px.',
			),
			'contentAlignment' => array(
				'type' => 'string',
				'enum' => array('left', 'center', 'right'),
				'description' => 'Text alignment for review content.',
			),
			// AgDR-0504 finding B2: the live control (SBR_Customize_Tab's
			// content_lengthreviews group) is a plain number input with no
			// min/max declared -- so, per "no invented bounds", none is
			// declared here either. The old max of 2000 was made up.
			'contentLength' => array(
				'type' => 'integer',
				'minimum' => 0,
				'description' => 'Max characters shown per review (0 = unlimited).',
			),
			// Bounds derived from SBR_Customize_Tab.php's number_reviews group
			// (AgDR-0504 finding B2): desktop's live control is `min: 0, max:
			// Util::sbr_is_pro() ? 1000 : 10` — the module runs in both editions
			// since SBR-577, so the ternary is evaluated here rather than
			// hardcoding 1000: free reports 10 (its real UI bound), Pro 1000.
			// The drift test pins both values. The old declared max of 200 was already narrower
			// than the Pro UI's 1000, so a customer's `numPostDesktop: 500` read
			// back clamped to 200 and was destroyed on the next agent write.
			// Tablet/mobile declare only `min: 0` in the UI (no upper bound), so
			// no `maximum` is declared here either — inventing one would be
			// exactly the "restate, don't derive" mistake this pass exists to
			// remove.
			'numPostDesktop' => array(
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => Util::sbr_is_pro() ? 1000 : 10,
				'description' => 'Number of reviews shown on desktop.',
			),
			'numPostTablet' => array(
				'tier' => 'moreReviewsModal',
				'type' => 'integer',
				'minimum' => 0,
				'description' => 'Number of reviews shown on tablet.',
			),
			'numPostMobile' => array(
				'tier' => 'moreReviewsModal',
				'type' => 'integer',
				'minimum' => 0,
				'description' => 'Number of reviews shown on mobile.',
			),
			// gridDesktopColumns/gridTabletColumns/gridMobileColumns removed
			// (AgDR-0504 finding B2): they only apply under `layout: grid`,
			// which is not a reachable value -- see `layout`'s enum above.
			'masonryDesktopColumns' => array(
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Masonry layout columns on desktop.',
			),
			'masonryTabletColumns' => array(
				'tier' => 'responsiveModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Masonry layout columns on tablet.',
			),
			'masonryMobileColumns' => array(
				'tier' => 'responsiveModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Masonry layout columns on mobile.',
			),
			'carouselDesktopColumns' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Carousel layout columns on desktop.',
			),
			'carouselTabletColumns' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Carousel layout columns on tablet.',
			),
			'carouselMobileColumns' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 6,
				'description' => 'Carousel layout columns on mobile.',
			),
			// AgDR-0504 finding B16: the only LIVE control (carouselDesktopRows,
			// a `select` in SBR_Customize_Tab.php) offers 1-2, not 1-4 — the
			// list-of-number-inputs version that went up to 4 is commented out
			// in that same file. Tablet/mobile rows have no live control at
			// all (also commented out) but ARE written by every feed template
			// in SBR_Feed_Templates_Settings (gridcarousel's is the highest, at
			// 2), so the same 1-2 bound is derived here rather than the old
			// literal 1-4 ceiling nothing in the plugin ever produces.
			'carouselDesktopRows' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 2,
				'description' => 'Carousel layout rows on desktop.',
			),
			'carouselTabletRows' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 2,
				'description' => 'Carousel layout rows on tablet.',
			),
			'carouselMobileRows' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 1,
				'maximum' => 2,
				'description' => 'Carousel layout rows on mobile.',
			),
			'carouselLoopType' => array(
				'tier' => 'carouselModal',
				'type' => 'string',
				'enum' => array('infinity', 'rewind'),
				'description' => 'Carousel loop behaviour.',
			),
			'carouselIntervalTime' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 500,
				'maximum' => 60000,
				'description' => 'Carousel autoplay interval, in ms.',
			),
			'carouselShowArrows' => array(
				'tier' => 'carouselModal',
				'type' => 'boolean',
				'description' => 'Show carousel navigation arrows.',
			),
			'carouselShowPagination' => array(
				'tier' => 'carouselModal',
				'type' => 'boolean',
				'description' => 'Show carousel pagination dots.',
			),
			'carouselEnableAutoplay' => array(
				'tier' => 'carouselModal',
				'type' => 'boolean',
				'description' => 'Autoplay the carousel.',
			),
			// Bounds derived from SBR_Customize_Tab::get_layout_controls()'s
			// carousel_breakpoints group (AgDR-0504 finding B2): both number
			// inputs declare `min: 0, max: 3000`. The old 200-2000 was
			// narrower than the UI accepts, so a stored value outside that
			// range read back clamped and was destroyed on the next write.
			'carouselBreakpointDesktop' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 3000,
				'description' => 'Viewport width (px) below which the desktop carousel breakpoint no longer applies.',
			),
			'carouselBreakpointTablet' => array(
				'tier' => 'carouselModal',
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 3000,
				'description' => 'Viewport width (px) below which the tablet carousel breakpoint no longer applies.',
			),
			'showHeader' => array(
				'type' => 'boolean',
				'description' => 'Show the feed header.',
			),
			'headerHeadingContent' => array(
				'type' => 'string',
				'description' => 'Header heading text.',
			),
			'headingColor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Header heading text colour (hex).',
			),
			// Coupled pair (AgDR-0504 finding A7 / requirement 2): 'external'
			// needs a URL, and the generic setter had no key to carry one at
			// all (`headerButtonExternalLink` was missing from this schema),
			// so `headerButtonLinkTo: 'external'` validated and stored while
			// leaving the button with nowhere to go. Both keys are read-only
			// here and mutated only through the validating
			// set-header-button-link ability (mirrors Twitter's canonical
			// set-feed-type-and-terms).
			'headerButtonLinkTo' => array(
				'type' => 'string',
				'enum' => array('google', 'external'),
				'writable' => false,
				'description' => 'Where the header "Write a review" button links to. '
					. 'Read-only here — change with set-header-button-link.',
			),
			'headerButtonExternalLink' => array(
				'type' => 'string',
				'maxLength' => 500,
				'writable' => false,
				'description' => 'External URL the header button links to when headerButtonLinkTo is "external". '
					. 'Read-only here — change with set-header-button-link.',
			),
			'headerButtonColor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Header button text colour (hex).',
			),
			'headerButtonBg' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Header button background colour (hex).',
			),
			'postStyle' => array(
				'type' => 'string',
				'enum' => array('regular', 'boxed'),
				'description' => 'Review card style.',
			),
			'boxedBackgroundColor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Boxed review card background colour (hex).',
			),
			'ratingIconColor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Star rating icon colour (hex).',
			),
			'showLoadButton' => array(
				'tier' => 'loadMoreModal',
				'type' => 'boolean',
				'description' => 'Show the Load More button.',
			),
			'loadButtonText' => array(
				'tier' => 'loadMoreModal',
				'type' => 'string',
				'description' => 'Load More button label.',
			),
			'loadButtonColor' => array(
				'tier' => 'loadMoreModal',
				'type' => 'string',
				'format' => 'color',
				'description' => 'Load More button text colour (hex).',
			),
			'loadButtonBg' => array(
				'tier' => 'loadMoreModal',
				'type' => 'string',
				'format' => 'color',
				'description' => 'Load More button background colour (hex).',
			),
			// AgDR-0504 finding A9 (causal — the star-rating filter the UI has
			// and this schema omitted is the reason agents reached for
			// moderation instead, tripping A2 below). Derived from
			// SBR_Settings_Tab.php's filter_reviews_sections checkboxsection,
			// whose five controls are literally the ids 5,4,3,2,1. Empty is
			// safe here — Feed.php's is_star_filters gate treats an empty list
			// as "no filter, show every rating", unlike the moderation
			// allow-list below.
			'includedStarFilters' => array(
				'tier' => 'filtersModal',
				'type' => 'array',
				'items' => array(
					'type' => 'integer',
					'enum' => array(1, 2, 3, 4, 5),
				),
				'maxItems' => 5,
				'description' => 'Only show reviews with these star ratings (1-5). Empty = show every rating.',
			),
			'includeWords' => array(
				'tier' => 'filtersModal',
				'type' => 'string',
				'description' => 'Only show reviews containing one of these comma-separated words.',
			),
			'excludeWords' => array(
				'tier' => 'filtersModal',
				'type' => 'string',
				'description' => 'Hide reviews containing one of these comma-separated words.',
			),
			// filterByImage/filterByVideos removed (AgDR-0504 finding B2):
			// the "By Content" group in SBR_Settings_Tab.php that would
			// control them is commented out -- no live control.
			'sortByDateEnabled' => array(
				'type' => 'boolean',
				'description' => 'Sort reviews by date.',
			),
			'sortByDate' => array(
				'type' => 'string',
				'enum' => array('latest', 'oldest'),
				'description' => 'Date sort direction (when sortByDateEnabled is true).',
			),
			'sortByRatingEnabled' => array(
				'type' => 'boolean',
				'description' => 'Sort reviews by star rating.',
			),
			'sortByRating' => array(
				'type' => 'string',
				'enum' => array('', 'highest', 'lowest'),
				'description' => 'Rating sort direction (when sortByRatingEnabled is true).',
			),
			'sortRandomEnabled' => array(
				'type' => 'boolean',
				'description' => 'Randomize review order.',
			),
			// colorScheme removed (AgDR-0504 finding B2): all of
			// SBR_Customize_Tab's colorpalette_section is commented out --
			// no live control.
			// Coupled 4-key family (AgDR-0504 finding A2 / requirement 2). The
			// harmful path was also the DEFAULT path: moderationType defaults
			// to 'allow', and Feed.php's moderation filter does
			// `in_array($post_id, $moderationAllowList)` — which is false for
			// every review when the allow list is empty, so
			// `moderationEnabled: true` alone (the default moderationType,
			// no list) emptied the whole feed with no signal. All four keys
			// are read-only here; set-moderation validates the set together
			// and warns rather than silently zeroing the feed.
			'moderationEnabled' => array(
				'tier' => 'moderationModal',
				'type' => 'boolean',
				'writable' => false,
				'description' => 'Only show reviews explicitly approved (or hide explicitly blocked ones) via Moderation Mode. '
					. 'Read-only here — change with set-moderation.',
			),
			'moderationType' => array(
				'tier' => 'moderationModal',
				'type' => 'string',
				'enum' => array('allow', 'block'),
				'writable' => false,
				'description' => 'Moderation Mode strategy: an allow list or a block list (when moderationEnabled is true). '
					. 'Read-only here — change with set-moderation.',
			),
			'moderationAllowList' => array(
				'tier' => 'moderationModal',
				'type' => 'array',
				'items' => array('type' => 'string', 'maxLength' => 100),
				'maxItems' => 500,
				'writable' => false,
				'description' => 'Review ids shown when moderationType is "allow". Read-only here — change with set-moderation.',
			),
			'moderationBlockList' => array(
				'tier' => 'moderationModal',
				'type' => 'array',
				'items' => array('type' => 'string', 'maxLength' => 100),
				'maxItems' => 500,
				'writable' => false,
				'description' => 'Review ids hidden when moderationType is "block". Read-only here — change with set-moderation.',
			),
			// Literal list = the keys of Util::get_translation_languages(true), the
			// options the live `localization` select offers (SBR_Settings_Tab,
			// language_section). Pinned by Smash1994FreeEditionAbilitiesTest, not
			// evaluated at request time (AgDR-9005 section 2). 'wpml' is only
			// offered on Pro with WPML active, hence the tier_values entry. It has no
			// matching upsell feature id (not an upsell-data entry), so it appears in
			// pro_only but not in get-pro-features, by design.
			'localization' => array(
				'type' => 'string',
				'enum' => array(
					'default', '', 'en', 'af', 'am', 'ar', 'az', 'be', 'bg', 'bn', 'bs', 'ca', 'cs', 'da', 'de', 'el',
					'en-AU', 'en-GB', 'es', 'es-419', 'et', 'eu', 'fa', 'fi', 'fil', 'fr', 'fr-CA', 'gl', 'gu', 'hi', 'hr',
					'hu', 'hy', 'id', 'is', 'it', 'iw', 'ja', 'ka', 'kk', 'km', 'kn', 'ko', 'ky', 'lo', 'lt', 'lv', 'mk',
					'ml', 'mn', 'mr', 'ms', 'my', 'ne', 'nl', 'no', 'pa', 'pl', 'pt', 'pt-BR', 'pt-PT', 'ro', 'ru', 'si',
					'sk', 'sl', 'sq', 'sr', 'sv', 'sw', 'ta', 'te', 'th', 'tr', 'uk', 'ur', 'uz', 'vi', 'zh', 'zh-CN',
					'zh-HK', 'zh-TW', 'zu',
					'wpml',
				),
				'tier_values' => array('wpml' => 'wpml'),
				'description' => 'Language used for API calls to review providers ("default" = site language, "" = no translation).',
			),
			'filterCharCountMin' => array(
				'tier' => 'filtersModal',
				'type' => 'integer',
				'minimum' => 0,
				'maximum' => 5000,
				'description' => 'Hide reviews shorter than this many characters. 0 = no minimum.',
			),
			// Derived from SBR_Settings_Tab.php's filter_bycharcount group,
			// whose 'max' control declares only `min: 0` — same "no upper
			// bound in the UI, none invented here" reasoning as numPost*
			// above. The plugin's own default is '' and Feed.php's
			// `!empty($settings['filterCharCountMax'])` gate treats both ''
			// and 0 as "no maximum", so — unlike storiestime — resolving the
			// stored-empty sentinel to a different value is not needed here:
			// 0 already IS the plugin's real "unlimited" behaviour.
			'filterCharCountMax' => array(
				'tier' => 'filtersModal',
				'type' => 'integer',
				'minimum' => 0,
				'description' => 'Hide reviews longer than this many characters. 0 = no maximum.',
			),
		);
	}

	/**
	 * Global (plugin-wide) settings stored in the `sbr_settings` option.
	 *
	 * Restricted to the Advanced tab's live toggles
	 * (class/Common/Settings/Tabs/SBR_Advanced_Tab.php) — its
	 * admin_error_notices / feed_issue_reports sections are commented out in
	 * the UI and are deliberately excluded here too.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function all_global_settings()
	{
		return array(
			'optimize_images' => array(
				'type' => 'boolean',
				'description' => 'Create locally-cached, appropriately-sized copies of review images.',
			),
			// 'usagetracking' is deliberately absent -- AgDR-0503 lists usage
			// tracking as never exposed through the Abilities API. Its
			// presence here was drift, called out explicitly in AgDR-9005.
			'enqueue_js_in_header' => array(
				'type' => 'boolean',
				'description' => 'Enqueue the plugin JavaScript in the page head instead of the footer.',
			),
			'enableSchema' => array(
				'type' => 'boolean',
				'description' => 'Emit schema.org review/rating markup for search-engine rich snippets.',
			),
			// Derived from SBR_Feeds_Tab.php's gdpr_section (options auto/yes/no),
			// stored as sbr_settings['gdpr'] and read by SBR_GDPR::doing_gdpr(),
			// which treats a missing/empty value as 'auto'.
			'gdpr' => array(
				'type' => 'string',
				'enum' => array('auto', 'yes', 'no'),
				'description' => 'GDPR mode, as set in Settings → Feeds. "auto" turns it on only when '
					. 'a supported consent plugin is active, "yes" always on, "no" always off. '
					. 'While on, feeds skip third-party content (review avatars) until the '
					. 'visitor consents. It affects legal compliance, so confirm the change with the '
					. 'site owner first.',
			),
		);
	}

	/**
	 * Per-feed settings an agent may WRITE in the current edition.
	 *
	 * Pro: the whole table. Free: edition-filtered (see for_edition()).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feed_settings()
	{
		return self::for_edition(self::all_feed_settings());
	}

	/**
	 * Per-feed settings an agent may READ in the current edition. Same keys as
	 * feed_settings(), but enums keep the Pro-only values so a downgraded feed
	 * reads back the value it actually stores (AgDR-9005 section 3).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feed_settings_read()
	{
		return self::for_edition(self::all_feed_settings(), true);
	}

	/**
	 * Global (plugin-wide) settings exposed in the current edition.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function global_settings()
	{
		return self::for_edition(self::all_global_settings());
	}

	/**
	 * The one choke point between the curated table and what an edition
	 * exposes (AgDR-9005 section 1). Pro returns the table unchanged. Free:
	 *
	 *  - drops every row carrying a `tier` (the control is upsell-locked in
	 *    the customizer, so the key is ABSENT, never `feature_unavailable`);
	 *  - strips `tier_values` options from the WRITE view's enum, and drops a
	 *    key whose write enum would collapse to a single value;
	 *  - keeps those options in the READ view, so stored values round-trip;
	 *  - removes the `tier` / `tier_values` metadata itself.
	 *
	 * The edition signal is the SBR_PRO constant via Util::sbr_is_pro(), never
	 * the licence tier: a stale licence after a Pro-to-free downgrade must not
	 * widen what free exposes.
	 *
	 * @param array<string, array<string, mixed>> $table    Full curated table.
	 * @param bool                                $for_read True for the read (output) view.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_edition(array $table, $for_read = false)
	{
		if (Util::sbr_is_pro()) {
			return $table;
		}
		$out = array();
		foreach ($table as $key => $def) {
			if (isset($def['tier'])) {
				continue;
			}
			if (!$for_read && isset($def['tier_values'], $def['enum']) && is_array($def['tier_values'])) {
				$def['enum'] = array_values(array_diff((array) $def['enum'], array_keys((array) $def['tier_values'])));
				if (count($def['enum']) < 2) {
					continue;
				}
			}
			unset($def['tier'], $def['tier_values']);
			$out[$key] = $def;
		}
		return $out;
	}

	/**
	 * Pro-only settings grouped by the upsell-data id that unlocks them, derived
	 * from the `tier` / `tier_values` tags on the full table (never restated, so a
	 * newly tagged key shows up in the upsell surfaces on its own).
	 *
	 * Each entry is a setting key (a whole row tagged `tier`), or `key=value` for a
	 * single Pro-only value of a key free does expose (`layout=carousel`).
	 * Edition-independent: it reads the raw table; callers decide who may show it.
	 *
	 * @return array<string, string[]> upsell id => settings.
	 */
	public static function pro_only_by_tier()
	{
		$out = array();
		foreach (self::all_feed_settings() as $key => $def) {
			if (isset($def['tier']) && is_string($def['tier'])) {
				$out[$def['tier']][] = (string) $key;
				continue;
			}
			if (!empty($def['tier_values']) && is_array($def['tier_values'])) {
				foreach ($def['tier_values'] as $value => $tier) {
					if (is_string($tier)) {
						$out[$tier][] = (string) $key . '=' . (string) $value;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Keys of $settings whose STORED value is a Pro-only option that the free
	 * edition cannot set (e.g. a feed left on `layout: carousel` after a
	 * downgrade). Empty on Pro. Used for the get-feed downgrade `warnings[]`.
	 *
	 * @param array $settings Raw decoded stored settings.
	 * @return array<string, string> key => stored value.
	 */
	public static function pro_only_stored_values(array $settings)
	{
		if (Util::sbr_is_pro()) {
			return array();
		}
		$found = array();
		foreach (self::all_feed_settings() as $key => $def) {
			if (isset($def['tier']) || empty($def['tier_values']) || !isset($settings[$key]) || !is_scalar($settings[$key])) {
				continue;
			}
			if (array_key_exists((string) $settings[$key], (array) $def['tier_values'])) {
				$found[$key] = (string) $settings[$key];
			}
		}
		return $found;
	}

	/**
	 * Build a JSON Schema `properties` map from a table (drops private keys).
	 *
	 * @param array $table
	 * @return array
	 */
	public static function to_json_properties(array $table)
	{
		$out = array();
		foreach ($table as $key => $def) {
			$prop = array('type' => $def['type']);
			foreach (array('enum', 'minimum', 'maximum', 'maxItems', 'description') as $k) {
				if (isset($def[$k])) {
					$prop[$k] = $def[$k];
				}
			}
			if ($def['type'] === 'string' && !isset($def['enum'])) {
				$prop['maxLength'] = isset($def['maxLength']) ? $def['maxLength'] : self::MAX_STRING_LENGTH;
			}
			if ($def['type'] === 'array') {
				$prop['items'] = isset($def['items']) ? $def['items'] : array('type' => 'string');
			}
			if (isset($def['format']) && $def['format'] === 'color') {
				$prop['pattern']     = self::COLOR_PATTERN;
				$prop['description'] = (isset($prop['description']) ? $prop['description'] . ' ' : '')
					. 'Hex colour (#rgb or #rrggbb) or empty for the default.';
			}
			if (isset($def['writable']) && $def['writable'] === false) {
				$prop['readOnly'] = true;
			}
			$out[$key] = $prop;
		}
		return $out;
	}

	/**
	 * Cast a raw stored value to the declared type for output.
	 *
	 * Stored data is not guaranteed to satisfy the schema (legacy installs,
	 * import/export, a bad save). Core validates our OUTPUT against the
	 * schema and fails the whole ability on one bad value, so anything that
	 * does not satisfy the declared enum / range falls back to $default (the
	 * plugin's own default for that key) rather than being passed through.
	 *
	 * @param array $def     Schema entry for the key.
	 * @param mixed $value   Raw stored value (null when missing).
	 * @param mixed $default Plugin default for the key.
	 * @return mixed
	 */
	public static function cast_out(array $def, $value, $default = null)
	{
		switch ($def['type'] ?? '') {
			case 'boolean':
				// '' is a legitimate "off" in these plugins (sanitize_and_sort_data()'s
				// is_boolean()/cast_boolean() treat '' as false), so only a MISSING
				// value takes the default.
				if ($value === null) {
					$value = $default;
				}
				return $value === true || $value === 'true' || $value === 1 || $value === '1' || $value === 'on';

			case 'integer':
				if ($value === null || $value === '' || !is_numeric($value)) {
					$value = is_numeric($default) ? $default : 0;
				}
				$int = (int) $value;
				// Clamp so the output always satisfies the declared range even if the
				// plugin default itself is ever out of spec.
				if (isset($def['minimum']) && $int < $def['minimum']) {
					$int = (int) $def['minimum'];
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					$int = (int) $def['maximum'];
				}
				return $int;

			case 'array':
				$raw = is_array($value) ? $value : (is_array($default) ? $default : array());
				return self::cast_out_array($def, $raw);

			default:
				return self::cast_out_string($def, $value, $default);
		}
	}

	/**
	 * Normalise a raw scalar value to the declared string schema for output.
	 * Split out of cast_out() to keep its cyclomatic complexity under the
	 * project's phpcs ceiling.
	 *
	 * @param array $def
	 * @param mixed $value
	 * @param mixed $default
	 * @return string
	 */
	private static function cast_out_string(array $def, $value, $default)
	{
		$str = is_scalar($value) ? (string) $value : '';

		// Clamp to the declared hex-colour pattern BEFORE the enum/length
		// checks below: a stored value can predate this schema and not be hex
		// at all. '' always satisfies COLOR_PATTERN (it is optional-empty), so
		// it is always a safe last resort even when $default itself does not
		// match.
		$is_color = isset($def['format']) && $def['format'] === 'color';
		if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
			$fallback_str = is_scalar($default) ? (string) $default : '';
			$str          = preg_match('/' . self::COLOR_PATTERN . '/', $fallback_str) ? $fallback_str : '';
		}

		if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
			$fallback = is_scalar($default) ? (string) $default : '';
			$str      = in_array($fallback, $def['enum'], true) ? $fallback : (string) $def['enum'][0];
		}

		// Same reasoning again for length: to_json_properties() only declares
		// maxLength on plain strings (never on an enum, which is always
		// short), so truncate rather than fail the whole ability on an
		// over-length stored value. Truncate to the SAME per-key limit
		// to_json_properties() declares (falling back to MAX_STRING_LENGTH),
		// so output always satisfies the schema it advertises.
		if (!isset($def['enum'])) {
			$max_length = isset($def['maxLength']) ? $def['maxLength'] : self::MAX_STRING_LENGTH;
			if (mb_strlen($str) > $max_length) {
				$str = mb_substr($str, 0, $max_length);
			}
		}

		return $str;
	}

	/**
	 * Normalise a raw array value for output. Split out of cast_out() to
	 * keep its cyclomatic complexity under the project's phpcs ceiling.
	 *
	 * @param array $def Schema entry (type => 'array').
	 * @param array $raw Raw stored array (already resolved to a real array
	 *                   by the caller — never null/scalar).
	 * @return array
	 */
	private static function cast_out_array(array $def, array $raw)
	{
		$item_def = isset($def['items']) ? $def['items'] : array('type' => 'string');
		$out_arr  = array();
		foreach ($raw as $item) {
			if (($item_def['type'] ?? '') === 'integer') {
				if (!is_numeric($item)) {
					continue;
				}
				$item = (int) $item;
				if (isset($item_def['enum']) && !in_array($item, $item_def['enum'], true)) {
					continue;
				}
			} else {
				$item = is_scalar($item) ? (string) $item : '';
				if ($item === '') {
					continue;
				}
				$max_len = isset($item_def['maxLength']) ? $item_def['maxLength'] : self::MAX_STRING_LENGTH;
				if (mb_strlen($item) > $max_len) {
					$item = mb_substr($item, 0, $max_len);
				}
			}
			$out_arr[] = $item;
		}
		if (isset($def['maxItems'])) {
			$out_arr = array_slice($out_arr, 0, $def['maxItems']);
		}
		return array_values($out_arr);
	}

	/**
	 * Keys that only make sense as part of feed CREATION. `feedTemplate`
	 * forcibly overwrites a dozen other keys (SBR_Feed_Templates_Settings),
	 * the same way the Feed Builder's own AJAX handler applies it only for a
	 * new feed — never marked `writable => false` outright because create-feed
	 * still needs to accept it; see writable_for_update().
	 *
	 * @var string[]
	 */
	const CREATE_ONLY_KEYS = array('feedTemplate');

	/**
	 * Writable subset of a table (keys not flagged `writable => false`).
	 *
	 * @param array $table
	 * @return array
	 */
	public static function writable(array $table)
	{
		return array_filter(
			$table,
			function ($def) {
				return !isset($def['writable']) || $def['writable'] !== false;
			}
		);
	}

	/**
	 * Writable subset for the GENERIC update path (update-feed-settings),
	 * which additionally excludes CREATE_ONLY_KEYS — those stay writable()
	 * (and so still usable) for create-feed alone.
	 *
	 * @param array $table
	 * @return array
	 */
	public static function writable_for_update(array $table)
	{
		$writable = self::writable($table);
		foreach (self::CREATE_ONLY_KEYS as $key) {
			unset($writable[$key]);
		}
		return $writable;
	}

	/**
	 * Validate + normalise ONE incoming value for storage.
	 *
	 * Core has already validated the request against the JSON Schema, so this
	 * is the second, PHP-side line: it never trusts the transport. Returns
	 * the value to store, or a WP_Error (code sbr_invalid_value, status 400).
	 *
	 * @param array  $def
	 * @param mixed  $value
	 * @param string $key
	 * @return mixed|\WP_Error
	 */
	public static function validate_in(array $def, $value, $key)
	{
		$bad = function ($reason) use ($key) {
			return new \WP_Error(
				'sbr_invalid_value',
				sprintf(
					/* translators: 1: setting key, 2: reason */
					__('Invalid value for %1$s: %2$s', 'reviews-feed'),
					$key,
					$reason
				),
				array('status' => 400)
			);
		};

		switch ($def['type'] ?? '') {
			case 'boolean':
				if (is_bool($value)) {
					return $value;
				}
				if (in_array($value, array('true', '1', 1, 'on'), true)) {
					return true;
				}
				if (in_array($value, array('false', '0', 0, 'off', ''), true)) {
					return false;
				}
				return $bad(__('expected a boolean', 'reviews-feed'));

			case 'integer':
				if (!is_numeric($value) || (float) $value !== (float) (int) $value) {
					return $bad(__('expected an integer', 'reviews-feed'));
				}
				$int = (int) $value;
				if (isset($def['minimum']) && $int < $def['minimum']) {
					return $bad(sprintf(__('minimum is %d', 'reviews-feed'), $def['minimum']));
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					return $bad(sprintf(__('maximum is %d', 'reviews-feed'), $def['maximum']));
				}
				return $int;

			case 'array':
				if (!is_array($value)) {
					return $bad(__('expected an array', 'reviews-feed'));
				}
				return self::validate_in_array($def, $value, $bad);

			default:
				if (!is_scalar($value)) {
					return $bad(__('expected a string', 'reviews-feed'));
				}
				return self::validate_in_string($def, (string) $value, $bad);
		}
	}

	/**
	 * Validate + normalise an incoming scalar value against the declared
	 * string schema. Split out of validate_in() to keep its cyclomatic
	 * complexity under the project's phpcs ceiling.
	 *
	 * @param array    $def
	 * @param string   $value
	 * @param \Closure $bad Error factory bound to the calling key.
	 * @return string|\WP_Error
	 */
	private static function validate_in_string(array $def, $value, \Closure $bad)
	{
		$str = sanitize_text_field($value);
		if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
			return $bad(sprintf(__('allowed values: %s', 'reviews-feed'), implode(', ', $def['enum'])));
		}
		$is_color = isset($def['format']) && $def['format'] === 'color';
		if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
			return $bad(__('expected a hex colour such as #ED4944', 'reviews-feed'));
		}
		$max_len = isset($def['maxLength']) ? $def['maxLength'] : self::MAX_STRING_LENGTH;
		if (mb_strlen($str) > $max_len) {
			return $bad(sprintf(__('maximum length is %d characters', 'reviews-feed'), $max_len));
		}
		return $str;
	}

	/**
	 * Validate + normalise an incoming array value. Split out of
	 * validate_in() to keep its cyclomatic complexity under the project's
	 * phpcs ceiling.
	 *
	 * @param array    $def  Schema entry (type => 'array').
	 * @param array    $value
	 * @param \Closure $bad  Error factory bound to the calling key.
	 * @return array|\WP_Error
	 */
	private static function validate_in_array(array $def, array $value, \Closure $bad)
	{
		$item_def = isset($def['items']) ? $def['items'] : array('type' => 'string');
		$out_arr  = array();
		foreach ($value as $item) {
			if (($item_def['type'] ?? '') === 'integer') {
				if (!is_numeric($item) || (float) $item !== (float) (int) $item) {
					return $bad(__('array items must be integers', 'reviews-feed'));
				}
				$item = (int) $item;
				if (isset($item_def['enum']) && !in_array($item, $item_def['enum'], true)) {
					return $bad(
						sprintf(
							/* translators: %s: comma-separated list of allowed values */
							__('array items must be one of: %s', 'reviews-feed'),
							implode(', ', $item_def['enum'])
						)
					);
				}
			} else {
				if (!is_scalar($item)) {
					return $bad(__('array items must be strings', 'reviews-feed'));
				}
				$item    = sanitize_text_field((string) $item);
				$max_len = isset($item_def['maxLength']) ? $item_def['maxLength'] : self::MAX_STRING_LENGTH;
				if (mb_strlen($item) > $max_len) {
					return $bad(
						sprintf(
							/* translators: %d: maximum length in characters */
							__('array item exceeds maximum length of %d characters', 'reviews-feed'),
							$max_len
						)
					);
				}
			}
			$out_arr[] = $item;
		}
		if (isset($def['maxItems']) && count($out_arr) > $def['maxItems']) {
			return $bad(sprintf(__('maximum %d items', 'reviews-feed'), $def['maxItems']));
		}
		return array_values($out_arr);
	}
}
