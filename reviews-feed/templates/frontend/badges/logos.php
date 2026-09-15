<?php

/**
 * Reviews Feed — platform logo row for the saved-badge styles (SBR-163)
 *
 * Reuses DisplayElements::get_provider_icon(), the same reader the feed header
 * and the admin preview go through, so a badge can never show a mark the feed
 * does not. The 15 provider SVGs already ship; nothing new is added here.
 *
 * Colour is not decided in this file. `data-logos` on the badge root selects
 * brand or mono, and sbr-trust-badge.css recolours the marks — a mono badge
 * flattens every fill to one ink. Doing it in CSS keeps one SVG per provider
 * instead of one per treatment.
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 * @var array $sbr_badge Prepared view data from SBR_Badge_Renderer::prepare().
 */

use SmashBalloon\Reviews\Common\DisplayElements;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

$sbr_badge_providers = isset($sbr_badge['providers']) && is_array($sbr_badge['providers'])
	? $sbr_badge['providers']
	: array();

// Every contributing platform is shown. There was a cap of five here, and it
// silently dropped real ones: a badge counting eight platforms' reviews drew
// five marks, so the rating included reviews from platforms the badge did not
// credit — an EDD source with reviews was reported missing from QA. Which five
// survived was not even stable, because the set was ordered by an unordered
// `IN (…)` query.
//
// The row wraps instead (see .sbr-tb-logos in sbr-trust-badge.css), so width is
// still not spent — the badge grows downward by a row rather than overflowing.

// A placeholder badge has no platform yet, so there is no mark to draw and no
// honest way to invent one — a real brand's SVG in a badge nobody pointed at
// that brand is fakery even at 2.5px of blur. A neutral chip keeps the row at
// the height the finished badge will have, which is the only job it has here.
if (! empty($sbr_badge['is_placeholder']) && empty($sbr_badge_providers)) {
	// A span, not a div: card.php nests this row inside `.sbr-tb-card-src`,
	// which is itself a span.
	echo '<span class="sbr-tb-logos sbr-tb-logos-icon"><span class="sbr-tb-provider sbr-tb-provider-placeholder" aria-hidden="true"></span></span>';

	return;
}

// FULL LOCKUP — the design's "Full logo" layout, and the reason the option is
// locked to a single source upstream: a wordmark beside other icons reads as a
// list rather than a badge. The renderer has already resolved that lock, so by
// the time this file sees 'full' the count is guaranteed to be one.
//
// A provider without supplied lockup artwork falls through to its mark rather
// than showing nothing, because the mark is still true — the layout degrades,
// the claim does not.
$sbr_badge_logo_style = isset($sbr_badge['logo_style']) ? (string) $sbr_badge['logo_style'] : 'icon';

// The RESOLVED colour treatment, not the stored one: tinted forces mono whatever
// the author picked, and auto means mono on anything but light. prepare() has
// already worked that out — reading `logo_color` here would put the artwork and
// the stylesheet on different answers.
$sbr_badge_mono_row = isset($sbr_badge['logos_attr']) && $sbr_badge['logos_attr'] === 'mono';

if ($sbr_badge_logo_style === 'full' && count($sbr_badge_providers) === 1) {
	$sbr_badge_provider = (string) reset($sbr_badge_providers);
	$sbr_badge_lockup   = DisplayElements::get_provider_lockup($sbr_badge_provider);

	if ($sbr_badge_lockup !== '') {
		?>
		<span class="sbr-tb-logos sbr-tb-logos-full">
			<span class="sbr-tb-lockup sbr-tb-lockup-<?php echo esc_attr(DisplayElements::provider_asset_slug($sbr_badge_provider)); ?>">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reads a bundled provider SVG through the same helper the mark row uses.
				echo $sbr_badge_lockup;
				?>
			</span>
		</span>
		<?php

		return;
	}
}

if (! empty($sbr_badge_providers)) {
	// Buffered so the wrapper is only emitted once a mark has survived the two
	// skips below. Every provider skipping is reachable — a source whose
	// platform has no bundled artwork — and card.php gates its left rule on
	// this file producing output, so an empty `.sbr-tb-logos` defeated that
	// guard and drew a rule with nothing beside it. Chip and medal put the row
	// in a gapped grid, so an empty one costs a row of spacing there.
	ob_start();

	foreach ($sbr_badge_providers as $sbr_badge_provider) {
		// Mono is its own artwork, not the brand mark recoloured. Six of the
		// fifteen marks are a coloured plate with a glyph knocked out of it,
		// and flattening both to one ink turns them into a solid blob — see
		// DisplayElements::get_provider_mono_icon(). Where that artwork
		// exists it wins; where it does not, the CSS recolour still applies
		// and the marks it applies to have no plate to ruin.
		$sbr_badge_is_mono = $sbr_badge_mono_row
			&& DisplayElements::has_provider_mono_icon($sbr_badge_provider);

		// Decided BEFORE fetching. get_provider_icon() falls back to the Google
		// mark when a provider has no SVG, and showing Google for, say, Airbnb
		// would be a false claim about where the reviews came from — so an
		// unknown provider draws nothing. Asking first also stops the fallback
		// being read off disk only to be thrown away on the next line.
		if (! DisplayElements::has_provider_icon($sbr_badge_provider)) {
			continue;
		}

		$sbr_badge_icon = $sbr_badge_is_mono
			? DisplayElements::get_provider_mono_icon($sbr_badge_provider)
			: DisplayElements::get_provider_icon($sbr_badge_provider);

		if ($sbr_badge_icon === '') {
			continue;
		}
		?>
		<span class="sbr-tb-provider<?php echo $sbr_badge_is_mono ? ' sbr-tb-provider-ink' : ''; ?> sbr-tb-provider-<?php echo esc_attr(DisplayElements::provider_asset_slug($sbr_badge_provider)); ?>">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reads a bundled provider SVG through the same helper the feed header uses.
			echo $sbr_badge_icon;
			?>
		</span>
		<?php
	}

	$sbr_badge_marks = (string) ob_get_clean();

	if (trim($sbr_badge_marks) !== '') {
		?>
		<span class="sbr-tb-logos">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered output of the loop above, escaped there.
			echo $sbr_badge_marks;
			?>
		</span>
		<?php
	}
}
