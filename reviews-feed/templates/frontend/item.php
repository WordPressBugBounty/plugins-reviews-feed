<?php

/**
 * Smash Balloon Reviews Feed Item Template
 * Adds an image, link, and other data for each post in the feed
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

$item_classes = $this->item_classes($post);
// Form-only providers (wpforms, formidable) never have a brand icon. EDD is
// dual-mode: form-collected (no business.id, written by
// SubmissionsManager::transform_to_review) preserves the legacy no-icon
// rendering; source-collected (business.id populated by the EDD source per
// class/Pro/Integrations/Providers/EDD.php:500,683) shows the EDD icon.
// Without this discriminator, removing 'edd' from $no_icon would visually
// regress existing form-collected EDD reviews in wp_sbr_reviews_posts.
$no_icon = ['wpforms', 'formidable', 'edd'];
$provider_name = $post['provider']['name'] ?? '';
$is_edd_source = $provider_name === 'edd' && ! empty($post['business']['id'] ?? null);
$show_icon = $provider_name !== '' && $provider_name !== 'none'
	&& (! in_array($provider_name, $no_icon, true) || $is_edd_source);
?>
<div class="sb-post-item-wrap sb-new <?php echo esc_attr($item_classes); ?>">
	<div class="sb-post-item">
		<?php if ($show_icon) { ?>
			<span class="sb-item-provider-icon">
				<img src="<?php echo esc_html($this->provider_icon_url($post, $settings)); ?>" alt="<?php echo esc_html($this->parser->get_provider_name($post)); ?>" />
			</span>
		<?php } ?>
		<?php $this->render_post_elements($post); ?>
	</div>
</div>
