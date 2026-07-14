<?php

/**
 * Booking.com rating-slot — the hotel's REAL Booking score + label (e.g.
 * "8.5 Very good"), forwarded verbatim by the relay as
 * metadata.review_score / metadata.review_score_word (Booking's 0-10 hotel
 * rating + its own wording). Renders INSIDE `.sb-item-rating` so it occupies
 * the same visual slot as the stars.
 *
 * Real Booking data only — no derived value, no invented band labels. If the
 * relay didn't supply a real score (older cached data, or the source fetch
 * failed), the badge doesn't render.
 *
 * @since SMASH-782 Phase 2
 */

if (!defined('ABSPATH')) {
	exit;
}

$review_score = isset($post['metadata']['review_score']) && is_numeric($post['metadata']['review_score'])
	? floatval($post['metadata']['review_score'])
	: null;
$score_word = isset($post['metadata']['review_score_word'])
	? trim((string) $post['metadata']['review_score_word'])
	: '';

if ($review_score === null || $review_score <= 0) {
	return;
}
?>
<div class="sb-item-rating-score">
	<span class="sb-item-rating-score-badge"><?php echo esc_html(number_format($review_score, 1)); ?></span>
	<?php if ($score_word !== '') : ?>
		<span class="sb-item-rating-score-label"><?php echo esc_html($score_word); ?></span>
	<?php endif; ?>
</div>
