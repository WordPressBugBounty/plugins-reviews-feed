<?php

/**
 * Reviews Feed — badge star row (SBR-163)
 *
 * Renders the 5-star row for every badge shape. Mirrors the display rule in
 * FeedDisplay::overall_star_rating_display() — the value is floored to the
 * nearest half before rendering — and reuses DisplayElements::get_star_icon()
 * so the badge glyph can never drift from the feed glyph.
 *
 * Deliberately does NOT reuse the feed's `.sb-item-rating-*` classes: those are
 * scoped under [id^=sb-reviews-container-] and a badge renders outside it.
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 * @var float $sbr_badge_rating Average rating, 0-5.
 */

use SmashBalloon\Reviews\Common\DisplayElements;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

$sbr_badge_star_value = isset($sbr_badge_rating) ? (float) $sbr_badge_rating : 0.0;

// Glyphs floor to the nearest half, matching
// FeedDisplay::overall_star_rating_display() — five stars cannot draw 4.8.
$sbr_badge_display = floor($sbr_badge_star_value * 2) / 2;

// The LABEL, however, announces the exact rating rather than the rounded glyph
// value. A screen-reader user asking "how is this rated?" should hear 4.8, not
// the 4.5 the stars happen to be able to draw. This deliberately reads truer
// than the feed header, which announces the rounded value.
if ($sbr_badge_star_value <= 0) {
	$sbr_badge_aria = __('Not rated', 'reviews-feed');
} else {
	$sbr_badge_aria = sprintf(
		/* translators: %s: exact numeric rating out of 5, e.g. 4.8 */
		__('%s out of 5 stars', 'reviews-feed'),
		number_format_i18n($sbr_badge_star_value, 1)
	);
}
?>
<span class="sbr-badge-stars" role="img" aria-label="<?php echo esc_attr($sbr_badge_aria); ?>">
	<?php
	for ($sbr_badge_i = 0; $sbr_badge_i < 5; $sbr_badge_i++) {
		$sbr_badge_remainder = $sbr_badge_display - $sbr_badge_i;

		if ($sbr_badge_remainder >= 1) {
			?>
			<span class="sbr-badge-star">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns a static, safe SVG string
				echo DisplayElements::get_star_icon();
				?>
			</span>
			<?php
		} elseif ($sbr_badge_remainder === 0.5) {
			?>
			<span class="sbr-badge-star sbr-badge-star-half">
				<span class="sbr-badge-star-base">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns a static, safe SVG string
					echo DisplayElements::get_star_icon();
					?>
				</span>
				<span class="sbr-badge-star-fill">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns a static, safe SVG string
					echo DisplayElements::get_star_icon();
					?>
				</span>
			</span>
			<?php
		} else {
			?>
			<span class="sbr-badge-star sbr-badge-star-dimmed">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns a static, safe SVG string
				echo DisplayElements::get_star_icon();
				?>
			</span>
			<?php
		}
	}
	?>
</span>
