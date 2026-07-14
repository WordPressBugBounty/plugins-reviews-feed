<?php
/**
 * Airbnb extras — additive per-provider markup (SMASH-782 Phase 2).
 *
 * Renders the host reply block per prototype AirbnbCard.jsx:
 *   sb-item-reply[data-from="host"]
 *     sb-item-reply-header
 *       sb-item-reply-avatar (img)         — when reply.avatar present
 *       sb-item-reply-name                  — when reply.name present
 *       sb-item-reply-label "Host"
 *     sb-item-reply-text
 *
 * Real data only: every sub-element renders ONLY when the relay forwards
 * the corresponding field. No demo placeholders, no fabricated content.
 * If the relay doesn't surface a host reply on a given review, this file
 * emits nothing for that review.
 *
 * Data:
 *   $post['response']         host reply text  (RapidRemoteAirbnbReviewsRepository forwards this)
 *   $post['reply']['avatar']  host avatar URL  (NOT YET forwarded by relay)
 *   $post['reply']['name']    host first name  (NOT YET forwarded by relay)
 *
 * @since SMASH-782 Phase 2
 */

if (!defined('ABSPATH')) {
	exit;
}

$reply_text   = isset($post['response']) ? trim((string) $post['response']) : '';
$reply_name   = isset($post['reply']['name']) ? trim((string) $post['reply']['name']) : '';
$reply_avatar = isset($post['reply']['avatar']) ? trim((string) $post['reply']['avatar']) : '';

if ($reply_text === '') {
	// No host response on this review — render nothing.
	return;
}
?>
<div class="sb-item-reply" data-from="host">
	<div class="sb-item-reply-header">
		<?php if ($reply_avatar !== '') : ?>
			<img class="sb-item-reply-avatar" src="<?php echo esc_url($reply_avatar); ?>" alt="<?php echo esc_attr($reply_name); ?>" loading="lazy" />
		<?php endif; ?>
		<?php if ($reply_name !== '') : ?>
			<span class="sb-item-reply-name"><?php echo esc_html($reply_name); ?></span>
		<?php endif; ?>
		<span class="sb-item-reply-label"><?php echo esc_html__('Host', 'reviews-feed'); ?></span>
	</div>
	<div class="sb-item-reply-text"><?php echo esc_html($reply_text); ?></div>
</div>
