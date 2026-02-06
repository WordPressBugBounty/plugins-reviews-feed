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

// Default template for existing sources (Google, Yelp, Facebook, etc.)
?>
<div class="sb-item-text sb-fs">
	<?php echo wp_kses_post(nl2br($this->get_review_text($post))); ?>
</div>
<div class="sb-expand">
	<a href="#" data-link="<?php echo esc_url($this->more_link($post)); ?>">
		<span class="sb-more">...</span>
	</a>
</div>

