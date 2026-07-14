<?php

/**
 * Smash Balloon Reviews Feed Rating Template
 * Adds a star rating, plus a per-provider rating-replacement slot when
 * the provider's design substitutes stars with a different element
 * (Booking score badge, Facebook recommendation chip, etc.). The legacy
 * star block is kept in DOM for backwards compatibility — providers that
 * substitute it hide `.sb-item-rating-ctn` via CSS scoped to their
 * `.sbr-provider-<name>` wrapper class (see assets/css/sbr-styles.css).
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

$provider_name = $post['provider']['name'] ?? '';
?>
<div class="sb-item-rating sb-fs">
		<span class="sb-relative">
			<div class='sb-item-rating-ctn'>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Method returns safe SVG HTML
				echo $this->star_rating_display($post, $settings);
				?>
			</div>
		</span>
		<?php
		// SMASH-782 Phase 2 — per-provider rating-slot inject. Booking renders
		// a score badge (e.g. "8.7 Very Good") that REPLACES the stars per the
		// prototype design. The legacy `.sb-item-rating-ctn` above stays in
		// DOM but is hidden by provider-scoped CSS. Other providers that need
		// the same treatment in the future drop a file here following the
		// `rating-extras/<provider>.php` convention.
		// Whitelist the provider slug (same rationale as item.php — keep raw
		// `$post['provider']['name']` away from path interpolation).
		if ($provider_name !== '' && preg_match('/^[a-z][a-z0-9_-]*$/', $provider_name)) {
			$rating_extras_path = trailingslashit(SBR_PLUGIN_DIR)
				. 'templates/frontend/post-elements/rating-extras/'
				. $provider_name . '.php';
			if (file_exists($rating_extras_path)) {
				include $rating_extras_path;
			}
		}
		?>
</div>
