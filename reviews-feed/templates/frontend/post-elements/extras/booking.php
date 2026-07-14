<?php

/**
 * Booking.com extras — additive per-provider markup (SMASH-782 Phase 2).
 *
 * Renders the per-card AFTER `render_post_elements()`:
 *   sb-item-helpful
 *     <svg HelpfulIcon /> "N people found this helpful"
 *
 * The score badge (`.sb-item-rating-score`) is rendered INSIDE
 * `.sb-item-rating` via `rating-extras/booking.php` so it sits in the same
 * visual slot as star ratings (right after the author block) — matches the
 * prototype's BookingCard.jsx layout.
 *
 * Real data only:
 *   - Helpful row: renders ONLY when metadata.helpful_vote_count > 0
 *
 * @since SMASH-782 Phase 2
 */

if (!defined('ABSPATH')) {
	exit;
}

$helpful = isset($post['metadata']['helpful_vote_count']) ? intval($post['metadata']['helpful_vote_count']) : 0;
if ($helpful <= 0) {
	return;
}
$helpful_icon_svg = '<svg class="sb-item-helpful-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path d="M14 5.33h-3.63l.75-3.07a1.14 1.14 0 0 0-2-.91L5.5 5a1.16 1.16 0 0 0-.17.55v6.78c0 .37.13.71.39.97.26.26.6.4.97.4H12c.26 0 .5-.08.73-.23.23-.16.4-.35.5-.57l2-4.7c.04-.08.06-.16.07-.25a3 3 0 0 0 .03-.25V6.67c0-.36-.13-.68-.4-.94a1.3 1.3 0 0 0-.93-.4ZM2.67 14c-.37 0-.7-.13-.97-.4a1.3 1.3 0 0 1-.4-.93v-6c0-.36.13-.69.4-.95.26-.26.6-.39.97-.39s.69.13.95.39c.26.26.39.59.39.95v6c0 .36-.13.68-.4.94-.26.26-.58.39-.94.39Z" fill="#696D80"/></svg>';
?>
<div class="sb-item-helpful sbr-review-horizontal-element">
	<?php echo $helpful_icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG, no user input ?>
	<span class="sb-item-helpful-count">
		<?php echo esc_html(sprintf(
			/* translators: %d = helpful vote count */
			_n('%d person found this helpful', '%d people found this helpful', $helpful, 'reviews-feed'),
			$helpful
		)); ?>
	</span>
</div>
