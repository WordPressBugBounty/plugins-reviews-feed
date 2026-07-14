<?php
/**
 * Smash Balloon Reviews Feed Text Template - AliExpress
 *
 * Renders the "Translated from original" indicator IMMEDIATELY BEFORE the
 * review text (per prototype AliExpressCard.jsx layout), then the review
 * text, then the standard expand-to-read-more link.
 *
 * @package SmashBalloon\Reviews
 * @version 1.0 Reviews Feed by Smash Balloon
 * @since SMASH-782 Phase 2
 */

if (! defined('ABSPATH')) {
	exit;
}

// Translated indicator — renders ONLY when relay flagged the review as
// machine-translated by AliExpress (metadata.translated derived from a
// non-empty translation block in the upstream response).
if (! empty($post['metadata']['translated'])) :
?>
<div class="sb-item-translated"><?php echo esc_html__('Translated from original', 'reviews-feed'); ?></div>
<?php endif; ?>

<div class="sb-item-text sb-fs sbr-review-horizontal-element">
	<?php echo sbr_neutralize_shortcodes(wp_kses_post(nl2br($this->get_review_text($post)))); ?>
</div>
<div class="sb-expand">
	<a href="#" data-link="<?php echo esc_url($this->more_link($post)); ?>">
		<span class="sb-more">...</span>
	</a>
</div>
