<?php

/**
 * Booking.com rating-slot — THIS reviewer's own score, on Booking's 0-10 scale.
 * Renders INSIDE `.sb-item-rating` so it occupies the same visual slot as the stars
 * (the legacy `.sb-item-rating-ctn` stays in the DOM, hidden by provider-scoped CSS).
 *
 * Per-card, not per-property. `metadata.review_score` / `review_score_word` are the
 * HOTEL's general rating: the relay stamps the same pair onto every card in the feed, so
 * reading them here made all 20 reviews show "9.5 Exceptional" no matter what each guest
 * actually gave. Verified against the cached rows for source 12166067 — per-review ratings
 * vary (4, 4.5, 5) while metadata.review_score is 9.5 on every one. That was a regression
 * from 6525848, whose real subject was the header; the property score belongs there and
 * only there (FeedDisplay::get_booking_header_rating(), templates/frontend/pro/header.php).
 *
 * Score and band both come from the reviewer's own rating — see
 * sbr_booking_review_score() and sbr_booking_score_word() in class/sbr-functions.php, which
 * carry the scale reasoning and the provenance of every band threshold. Both PHP surfaces
 * and all three JS surfaces call the same two rules; keep them in step:
 * templates/review-alerts/popup.php, assets/js/sbr-review-alerts.js, and the customizer's
 * PostRating.js + ReviewAlertPreview.js.
 *
 * @since SMASH-782 Phase 2
 */

if (!defined('ABSPATH')) {
	exit;
}

$sbr_reviewer_score = sbr_booking_review_score($post);

if ($sbr_reviewer_score <= 0) {
	return;
}

$sbr_score_word = sbr_booking_score_word($sbr_reviewer_score);
?>
<div class="sb-item-rating-score">
	<span class="sb-item-rating-score-badge"><?php echo esc_html(number_format($sbr_reviewer_score, 1)); ?></span>
	<?php if ($sbr_score_word !== '') : ?>
		<span class="sb-item-rating-score-label"><?php echo esc_html($sbr_score_word); ?></span>
	<?php endif; ?>
</div>
