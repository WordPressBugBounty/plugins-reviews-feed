<?php

/**
 * Smash Balloon Reviews Feed Text Template
 * Adds a review paragraph with provider-specific template support
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Get provider name
$provider = !empty($post['provider']['name']) ? $post['provider']['name'] : '';

// Providers with custom templates (only Booking.com has pros/cons and photos)
$providers_with_custom_templates = ['booking'];

// Check if provider-specific template exists
if (!empty($provider) && in_array($provider, $providers_with_custom_templates)) {
	$provider_template = __DIR__ . '/text-' . $provider . '.php';

	if (file_exists($provider_template)) {
		include $provider_template;
		return;
	}
}

// Default template for existing sources (Google, Yelp, Facebook, etc.).
// SMASH-1553 — review-title block, rendered ONLY when the source provider
// is one we know supplies real customer-typed titles. Cannot use a generic
// `!empty($post['title'])` guard because `Util::parse_single_review()`
// (class/Common/Util.php:1421) synthesizes a substring fallback title from
// the review text whenever the upstream provider omits one — so non-EDD
// reviews stored via Collection / Import paths carry a fake "first 40 chars
// of text" title that would render as a bold heading above the same text.
// Provider allowlist scopes the render to providers where the title is
// authoritative customer input. Add new entries here as providers gain
// genuine review-title support.
$providers_with_review_titles = array( 'edd' );
$has_post_title               = in_array( $provider, $providers_with_review_titles, true )
	&& ! empty( $post['title'] )
	&& is_string( $post['title'] );
?>
<?php if ( $has_post_title ) : ?>
	<?php
	/*
	 * HTML entity decode before esc_html: EDD Reviews' submission handler
	 * runs `esc_html()` on the title BEFORE persisting to comment meta, so
	 * `$post['title']` contains entities like `&amp;` / `&#039;`. A raw
	 * `esc_html( $post['title'] )` here would double-encode (`&amp;amp;`).
	 * Decoding first, then re-encoding once for output, gives single-pass
	 * safe rendering and works equally well for providers that store raw
	 * strings (decode of plain text is a no-op).
	 */
	$display_title = html_entity_decode( $post['title'], ENT_QUOTES, 'UTF-8' );
	?>
<div class="sb-item-title"><?php echo sbr_neutralize_shortcodes(esc_html( $display_title )); ?></div>
<?php endif; ?>
<div class="sb-item-text sb-fs">
	<?php echo sbr_neutralize_shortcodes(wp_kses_post(nl2br($this->get_review_text($post)))); ?>
</div>
<div class="sb-expand">
	<a href="#" data-link="<?php echo esc_url($this->more_link($post)); ?>">
		<span class="sb-more">...</span>
	</a>
</div>

