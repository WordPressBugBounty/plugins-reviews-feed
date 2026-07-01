<?php

/**
 * Review Alert Template - Single Review Widget
 *
 * Matches prototype design from 2025-11-19-review-notification-popup-v2:
 * - Horizontal layout with avatar left, content right
 * - Accent-colored wrapper with white inner card
 * - Hover scale effect on wrapper
 * - "View All Reviews" link with hover fill effect
 * - Powered by footer in accent color
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews
 *
 * @var array $popup  Popup data passed from template loader
 * @var array $reviews Reviews data passed from template loader
 */

use SmashBalloon\Reviews\Common\DisplayElements;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Frontend;

if (! defined('ABSPATH')) {
	exit;
}

// Extract settings for cleaner template code
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variable
$settings = $popup['settings'] ?? [];
$theme = $settings['theme'] ?? 'light';
$variation = $settings['variation'] ?? 'v1';
$popup_type = $settings['popup_type'] ?? 'aggregate'; // 'aggregate' or 'recent'
$position = $settings['position'] ?? 'bottom-right';
$accent_color = $settings['accent_color'] ?? '#175CE3';
$accent_hue = $settings['accent_hue'] ?? '220';
$content_settings = $settings['content'] ?? [];
$review_feed_settings = $settings['review_feed'] ?? [];
$show_powered_by = $content_settings['show_powered_by'] ?? true;

// Build theme class
$theme_class = sprintf('sbr-review-alert--%s', $theme);
if ($variation && $variation !== 'v1') {
	$theme_class .= '-' . $variation;
}
$position_class = sprintf('sbr-review-alert--%s', $position);
$powered_by_class = $show_powered_by ? 'sbr-review-alert--has-powered-by' : '';
$popup_type_class = $popup_type === 'aggregate' ? 'sbr-review-alert--aggregate' : '';

// Get first review for initial display
$first_review = ! empty($reviews[0]) ? $reviews[0] : null;

// Don't render if no reviews.
if (! $first_review) {
	return;
}

// Get total reviews and average rating from config (calculated from ALL matching reviews)
$total_reviews = $config['totalReviews'] ?? count($reviews);
$average_rating = $config['averageRating'] ?? 5.0;

// Get reviewer info
$reviewer_name = $first_review['reviewer']['name'] ?? __('Someone', 'reviews-feed');
$reviewer_avatar = $first_review['reviewer']['avatar'] ?? '';
$review_rating = isset($first_review['rating']) ? (int) $first_review['rating'] : 5;
$review_text = $first_review['text'] ?? '';
// Time can be a Unix timestamp or string - format as relative date
$raw_time = $first_review['time'] ?? '';
$review_date = '';
if (!empty($raw_time) && is_numeric($raw_time)) {
	$timestamp = (int) $raw_time;
	$now = time();
	$diff = $now - $timestamp;
	$diff_mins = (int) floor($diff / 60);
	$diff_hours = (int) floor($diff_mins / 60);
	$diff_days = (int) floor($diff_hours / 24);
	$diff_weeks = (int) floor($diff_days / 7);
	$diff_months = (int) floor($diff_days / 30);
	$diff_years = (int) floor($diff_days / 365);

	if ($diff_years > 0) {
		$review_date = $diff_years === 1 ? '1y ago' : $diff_years . 'y ago';
	} elseif ($diff_months > 0) {
		$review_date = $diff_months === 1 ? '1mo ago' : $diff_months . 'mo ago';
	} elseif ($diff_weeks > 0) {
		$review_date = $diff_weeks === 1 ? '1w ago' : $diff_weeks . 'w ago';
	} elseif ($diff_days > 0) {
		$review_date = $diff_days === 1 ? '1d ago' : $diff_days . 'd ago';
	} elseif ($diff_hours > 0) {
		$review_date = $diff_hours === 1 ? '1h ago' : $diff_hours . 'h ago';
	} elseif ($diff_mins > 0) {
		$review_date = $diff_mins === 1 ? '1m ago' : $diff_mins . 'm ago';
	} else {
		$review_date = __('Just now', 'reviews-feed');
	}
} elseif (!empty($raw_time)) {
	// If already a string, use as-is
	$review_date = $raw_time;
}
// Provider can be a string or array with 'name' key
$provider_data = $first_review['provider'] ?? 'google';
$provider = is_array($provider_data) ? ($provider_data['name'] ?? 'google') : $provider_data;

// Custom accent color inline style (OKLCH-based theming)
// Output the hue value for OKLCH color generation (0-360 matches standard color wheel)
$custom_style = sprintf('--sbr-popup-accent-hue: %s;', esc_attr($accent_hue));
// Also output the hex color as fallback
if ($accent_color) {
	$custom_style .= sprintf(' --sbr-popup-accent-fallback: %s;', esc_attr($accent_color));
}

// Visibility settings with defaults
$show_avatar = $content_settings['show_avatar'] ?? true;
$show_rating = $content_settings['show_rating'] ?? true;
$show_reviewer_name = $content_settings['show_reviewer_name'] ?? true;
$show_review_text = $content_settings['show_review_text'] ?? true;
$show_date = $content_settings['show_date'] ?? true;
$show_platform = $content_settings['show_platform'] ?? true;
$show_total_reviews = $content_settings['show_total_reviews'] ?? true;
$link_url = $content_settings['link_url'] ?? '';
?>

<div
	class="sbr-review-alert <?php echo esc_attr($theme_class); ?> <?php echo esc_attr($position_class); ?> <?php echo esc_attr($powered_by_class); ?> <?php echo esc_attr($popup_type_class); ?>"
	style="<?php echo esc_attr($custom_style); ?>"
	role="complementary"
	aria-label="<?php esc_attr_e('Customer review notification', 'reviews-feed'); ?>"
	data-popup-id="<?php echo esc_attr($popup['id']); ?>"
>
	<!-- Wrapper for hover scale effect -->
	<div class="sbr-review-alert__wrapper">
		<!-- Inner container - accent colored border -->
		<div class="sbr-review-alert__inner">
			<!-- Close button for dismissing the popup -->
			<button type="button" class="sbr-review-alert__close" aria-label="<?php esc_attr_e('Dismiss review notification', 'reviews-feed'); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>
			<?php if ($popup_type === 'aggregate') : ?>
			<!-- AGGREGATE VIEW - Summary rating display (no individual reviews) -->
			<!-- Reuses element structure from recent reviews template for consistent styling -->
			<div class="sbr-review-alert__card">
				<!-- Content Row - Horizontal Layout for Aggregate -->
				<div class="sbr-review-alert__content-row sbr-review-alert__content-row--aggregate">
					<?php if ($show_rating) : ?>
					<!-- Rating Number (replaces avatar) -->
					<div class="sbr-review-alert__rating-number">
						<span class="sbr-review-alert__rating-value"><?php echo esc_html(number_format($average_rating, 1)); ?></span>
					</div>
					<?php endif; ?>

					<!-- Content Section - Reusing __content class from recent reviews -->
					<div class="sbr-review-alert__content<?php echo !$show_rating ? ' sbr-review-alert__content--no-rating' : ''; ?>">
						<?php if ($show_rating) : ?>
						<!-- Stars (decorative — the numeric rating is announced separately) -->
						<div class="sbr-review-alert__stars" aria-hidden="true">
							<?php
							// Half-star fill states come from the one shared formula (full,half,empty),
							// mirrored by the customizer's React starFillStates() so preview == frontend.
							foreach (SBR_Review_Alert_Frontend::star_fill_states((float) $average_rating) as $star_fill) :
								$star_modifier = 'full' === $star_fill ? '' : 'sbr-review-alert__star--' . $star_fill;
								?>
								<span class="sbr-review-alert__star <?php echo esc_attr($star_modifier); ?>">
									<?php echo DisplayElements::get_star_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ('half' === $star_fill) : ?>
										<span class="sbr-review-alert__star-half"><?php echo DisplayElements::get_star_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<?php endif; ?>
								</span>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<?php if ($show_total_reviews) : ?>
						<!-- View All Link - Reusing hover effect from recent reviews -->
						<a href="<?php echo esc_url($link_url); ?>" class="sbr-review-alert__view-all-link">
							<span>
								<?php
								printf(
									/* translators: %d: number of reviews */
									esc_html__('%d Total Reviews', 'reviews-feed'),
									absint($total_reviews)
								);
								?>
							</span>
							<span class="sbr-review-alert__chevron">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
									<polyline points="9 18 15 12 9 6"></polyline>
								</svg>
							</span>
						</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php else : ?>
			<!-- RECENT REVIEWS VIEW - Individual review with cycling -->
			<div class="sbr-review-alert__card sbr-review-alert__review">
				<?php if ($show_date && $review_date) : ?>
				<!-- Date - Top Right -->
				<span class="sbr-review-alert__date"><?php echo esc_html($review_date); ?></span>
				<?php endif; ?>

				<!-- Content Row - Horizontal Layout -->
				<div class="sbr-review-alert__content-row">
					<?php if ($show_avatar) : ?>
					<!-- Avatar with Provider Badge -->
					<div class="sbr-review-alert__avatar-wrapper">
						<?php
						// Fallback SVG for broken avatar images - using data URI
						$fallback_svg = 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56"><rect fill="#e4e4e7" width="56" height="56"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="24" fill="#71717a">?</text></svg>');
						?>
					<img
							class="sbr-review-alert__avatar"
							src="<?php echo esc_url($reviewer_avatar); ?>"
							alt="<?php echo esc_attr($reviewer_name); ?>"
							loading="lazy"
							onerror="this.onerror=null;this.src='<?php echo esc_attr($fallback_svg); ?>';"
						/>
						<?php if ($show_platform) : ?>
						<span class="sbr-review-alert__provider-badge" data-provider="<?php echo esc_attr($provider); ?>">
							<?php echo DisplayElements::get_provider_icon($provider); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG content from trusted source ?>
						</span>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<!-- Content Section -->
					<div class="sbr-review-alert__content">
						<?php if ($show_rating) : ?>
						<!-- Stars -->
						<div class="sbr-review-alert__stars">
							<?php for ($i = 1; $i <= 5; $i++) : ?>
								<span class="sbr-review-alert__star <?php echo esc_attr($i <= $review_rating ? '' : 'sbr-review-alert__star--empty'); ?>">
									<?php echo DisplayElements::get_star_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</span>
							<?php endfor; ?>
						</div>
						<?php endif; ?>

						<?php if ($show_reviewer_name) : ?>
						<!-- Heading (sbr-review-alert__reviewer-name class for JS cycling) -->
						<div class="sbr-review-alert__heading sbr-review-alert__reviewer-name">
							<?php
							printf(
								/* translators: %s: reviewer name */
								esc_html__('%s left us a review', 'reviews-feed'),
								esc_html($reviewer_name)
							);
							?>
						</div>
						<?php endif; ?>

						<?php if ($show_review_text && $review_text) : ?>
						<!-- Review Text -->
						<p class="sbr-review-alert__review-text"><?php echo esc_html($review_text); ?></p>
						<?php endif; ?>

						<?php if ($show_total_reviews) : ?>
						<!-- View All Link -->
						<a href="<?php echo esc_url($link_url); ?>" class="sbr-review-alert__view-all-link">
							<span>
								<?php
								printf(
									/* translators: %d: number of reviews */
									esc_html__('View All %d Reviews', 'reviews-feed'),
									absint($total_reviews)
								);
								?>
							</span>
							<span class="sbr-review-alert__chevron">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
									<polyline points="9 18 15 12 9 6"></polyline>
								</svg>
							</span>
						</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<?php if ($show_powered_by) : ?>
			<!-- Powered By Footer -->
			<div class="sbr-review-alert__powered-by">
				<span class="sbr-review-alert__powered-text"><?php esc_html_e('Powered by', 'reviews-feed'); ?></span>
				<a href="https://smashballoon.com/rf/notification-popup/powered-by?utm_source=reviews-feed-free&utm_medium=reviews-plugin-notification-popup&utm_campaign=powered-by&utm_content=powered-by-link" target="_blank" rel="noopener noreferrer nofollow" class="sbr-review-alert__powered-link">
					<span class="sbr-review-alert__logo">
						<svg viewBox="0 0 536 106" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Smash Balloon">
							<path d="M39.9268 0C61.9762 0.000279087 79.8446 22.0987 79.8447 49.3584C79.8447 74.9769 64.0667 96.0305 43.8789 98.4805L48.7295 104.409L34.9043 105.586L37.1084 98.6035C16.3737 96.8148 0 75.4912 0 49.3584C0.000140403 22.0986 17.8771 0 39.9268 0ZM37.1836 32.7256L19.5947 22.6982L24.7246 41.0137L6.13379 46.9922L23.501 55.752L16.6504 74.1455L34.458 65.9863L43.2773 83.1436L49.0859 63.9834L68.2451 67.585L56.8721 51.1357L71.2715 37.7471L51.3662 37.1758L49.4453 17.3535L37.1836 32.7256Z" fill="white"/>
							<path d="M133.766 33.8531C128.363 29.8356 123.237 29.2815 120.051 29.2815C115.756 29.2815 111.877 30.1819 108.621 33.5068C105.851 36.3468 104.604 39.8102 104.604 43.897C104.604 46.1135 104.95 49.3691 107.375 51.932C109.176 53.8715 111.669 54.8413 113.816 55.6032L117.626 56.9193C118.942 57.4042 121.782 58.4432 123.029 59.4822C123.999 60.3134 124.622 61.2832 124.622 62.8071C124.622 64.5388 123.86 65.7856 122.96 66.5475C121.436 67.8636 119.496 68.1407 118.111 68.1407C115.964 68.1407 114.094 67.5866 112.293 66.4783C111.046 65.7163 109.176 64.1232 107.998 62.9456L102.457 70.5651C104.188 72.2968 106.89 74.4441 109.245 75.6216C112.154 77.0763 115.063 77.5611 118.388 77.5611C121.436 77.5611 127.393 77.1455 131.48 72.8509C133.904 70.3573 135.567 66.2012 135.567 61.4217C135.567 58.7203 134.874 55.3954 132.172 52.8325C130.371 51.1008 127.878 50.0618 125.869 49.2999L122.406 47.9838C119.289 46.8062 117.695 46.3906 116.587 45.3516C115.894 44.7282 115.548 43.897 115.548 42.7887C115.548 41.6111 116.033 40.5721 116.726 39.8794C117.973 38.4941 119.773 38.2863 121.228 38.2863C122.544 38.2863 125.523 38.4941 128.986 41.6111L133.766 33.8531Z" fill="white"/>
							<path d="M139.986 76.5221H149.961V60.7291C149.961 59.69 150.03 56.2266 151.9 54.4257C152.87 53.5252 154.048 53.1789 155.364 53.1789C156.403 53.1789 157.649 53.3867 158.688 54.4257C160.212 55.9496 160.282 58.3739 160.282 60.452V76.5221H170.256V61.1447C170.256 59.1359 170.464 56.7115 171.78 55.0491C172.611 53.9408 173.997 53.1789 175.659 53.1789C177.114 53.1789 178.43 53.8023 179.261 54.8413C180.577 56.5037 180.577 59.2744 180.577 60.8676V76.5221H190.552V56.9886C190.552 54.772 190.344 50.616 187.365 47.776C185.495 45.975 182.447 44.8667 178.707 44.8667C176.282 44.8667 174.274 45.2823 172.126 46.5984C170.118 47.8452 168.94 49.2999 168.178 50.5467C167.347 48.6765 166.031 47.1526 164.438 46.2521C162.36 45.0053 159.935 44.8667 158.619 44.8667C156.264 44.8667 152.524 45.3516 149.961 49.2306V45.6287H139.986V76.5221Z" fill="white"/>
							<path d="M219.975 49.0921C217.135 45.2131 212.909 44.5897 210.277 44.5897C205.983 44.5897 202.242 46.1135 199.541 48.815C196.701 51.655 194.761 56.0881 194.761 61.2832C194.761 65.37 196.008 69.3875 199.264 72.8509C202.658 76.4529 206.398 77.5611 210.831 77.5611C213.394 77.5611 217.273 76.9377 219.975 72.7816V76.5221H229.949V45.6287H219.975V49.0921ZM212.771 53.1789C214.572 53.1789 216.788 53.8715 218.312 55.3262C219.767 56.7115 220.598 58.7896 220.598 61.0061C220.598 63.6383 219.49 65.5778 218.174 66.8246C216.858 68.1407 214.987 68.9719 212.979 68.9719C210.624 68.9719 208.476 68.0022 207.091 66.5475C206.19 65.5778 205.013 63.7768 205.013 61.0061C205.013 58.2354 206.26 56.4344 207.299 55.3954C208.615 54.0793 210.624 53.1789 212.771 53.1789Z" fill="white"/>
							<path d="M258.464 47.2218C255.97 45.7672 252.784 44.5897 248.212 44.5897C245.372 44.5897 241.632 45.1438 238.861 47.7067C237.06 49.3691 235.883 51.8628 235.883 54.8413C235.883 57.1964 236.645 58.9281 238.099 60.452C239.415 61.7681 241.285 62.7378 243.086 63.292L245.58 64.0539C247.035 64.4695 247.935 64.7466 248.628 65.1622C249.528 65.7163 249.736 66.409 249.736 66.9632C249.736 67.7251 249.321 68.4871 248.697 68.9719C247.797 69.6646 246.203 69.6646 245.58 69.6646C244.264 69.6646 242.809 69.3875 241.424 68.6949C240.385 68.21 239 67.2402 238.03 66.409L233.805 73.128C237.822 76.6606 242.324 77.5611 246.481 77.5611C249.736 77.5611 253.477 77.0763 256.524 74.0285C257.91 72.6431 259.711 70.0109 259.711 65.9241C259.711 63.569 259.087 61.6988 257.286 60.0364C255.693 58.5818 253.892 57.8891 252.161 57.3349L249.528 56.5037C248.282 56.0881 247.242 55.8803 246.55 55.4647C246.065 55.1876 245.58 54.772 245.58 54.0793C245.58 53.5945 245.857 53.0403 246.203 52.694C246.827 52.0706 248.004 51.7935 249.043 51.7935C250.983 51.7935 252.992 52.6247 254.516 53.5252L258.464 47.2218Z" fill="white"/>
							<path d="M263.839 76.5221H273.813V61.0754C273.813 59.5515 273.882 56.573 275.891 54.6335C276.445 54.0793 277.692 53.1096 279.839 53.1096C281.294 53.1096 282.818 53.5945 283.718 54.495C285.312 56.0188 285.381 58.5125 285.381 60.6598V76.5221H295.355V56.9193C295.355 54.5642 295.148 50.7545 292.308 47.9145C289.606 45.1438 286.004 44.7975 283.58 44.7975C281.363 44.7975 279.632 45.0053 277.554 46.1135C276.376 46.737 275.06 47.776 273.813 49.2999V26.0259H263.839V76.5221Z" fill="white"/>
							<path d="M316.322 30.3205V76.5221H333.708C336.548 76.5221 342.09 76.245 345.899 72.5739C347.7 70.7729 349.501 67.8636 349.501 63.2227C349.501 59.1359 348.047 56.5037 346.523 54.9798C344.86 53.3174 342.367 52.2091 340.15 51.8628C341.258 51.3779 342.852 50.4081 344.029 48.4686C345.276 46.4599 345.553 44.3818 345.553 42.5809C345.553 40.5721 345.207 36.6931 342.228 33.8531C338.626 30.459 332.946 30.3205 330.453 30.3205H316.322ZM326.851 38.6326H328.513C330.453 38.6326 332.531 38.6326 334.055 39.8102C334.886 40.4336 335.855 41.6804 335.855 43.6892C335.855 45.6979 334.955 47.0833 333.985 47.776C332.461 48.8843 330.037 49.0921 328.582 49.0921H326.851V38.6326ZM326.851 56.9886H329.76C331.907 56.9886 335.509 56.9886 337.379 58.7203C338.072 59.3437 338.834 60.5905 338.834 62.4607C338.834 64.1232 338.28 65.4393 337.31 66.3397C335.371 68.1407 332.115 68.21 329.414 68.21H326.851V56.9886Z" fill="white"/>
							<path d="M377.884 49.0921C375.044 45.2131 370.818 44.5897 368.186 44.5897C363.892 44.5897 360.151 46.1135 357.45 48.815C354.61 51.655 352.67 56.0881 352.67 61.2832C352.67 65.37 353.917 69.3875 357.173 72.8509C360.567 76.4529 364.307 77.5611 368.74 77.5611C371.303 77.5611 375.182 76.9377 377.884 72.7816V76.5221H387.858V45.6287H377.884V49.0921ZM370.68 53.1789C372.481 53.1789 374.697 53.8715 376.221 55.3262C377.676 56.7115 378.507 58.7896 378.507 61.0061C378.507 63.6383 377.399 65.5778 376.083 66.8246C374.767 68.1407 372.896 68.9719 370.888 68.9719C368.533 68.9719 366.385 68.0022 365 66.5475C364.099 65.5778 362.922 63.7768 362.922 61.0061C362.922 58.2354 364.169 56.4344 365.208 55.3954C366.524 54.0793 368.533 53.1789 370.68 53.1789Z" fill="white"/>
							<path d="M393.792 26.0259V76.5221H403.766V26.0259H393.792Z" fill="white"/>
							<path d="M409.977 26.0259V76.5221H419.952V26.0259H409.977Z" fill="white"/>
							<path d="M460.659 61.0754C460.659 57.1271 459.135 52.694 456.018 49.5769C453.247 46.8062 448.537 44.5897 442.511 44.5897C436.484 44.5897 431.774 46.8062 429.003 49.5769C425.886 52.694 424.362 57.1271 424.362 61.0754C424.362 65.0237 425.886 69.4568 429.003 72.5739C431.774 75.3446 436.484 77.5611 442.511 77.5611C448.537 77.5611 453.247 75.3446 456.018 72.5739C459.135 69.4568 460.659 65.0237 460.659 61.0754ZM442.511 53.0403C444.796 53.0403 446.667 53.8023 448.121 55.2569C449.576 56.7115 450.407 58.5818 450.407 61.0754C450.407 63.569 449.576 65.4393 448.121 66.8939C446.667 68.3485 444.796 69.1105 442.58 69.1105C439.948 69.1105 438.147 68.1407 436.9 66.8939C435.722 65.7163 434.614 63.9154 434.614 61.0754C434.614 58.5818 435.445 56.7115 436.9 55.2569C438.355 53.8023 440.225 53.0403 442.511 53.0403Z" fill="white"/>
							<path d="M499.573 61.0754C499.573 57.1271 498.049 52.694 494.932 49.5769C492.161 46.8062 487.451 44.5897 481.425 44.5897C475.399 44.5897 470.689 46.8062 467.918 49.5769C464.801 52.694 463.277 57.1271 463.277 61.0754C463.277 65.0237 464.801 69.4568 467.918 72.5739C470.689 75.3446 475.399 77.5611 481.425 77.5611C487.451 77.5611 492.161 75.3446 494.932 72.5739C498.049 69.4568 499.573 65.0237 499.573 61.0754ZM481.425 53.0403C483.711 53.0403 485.581 53.8023 487.036 55.2569C488.49 56.7115 489.322 58.5818 489.322 61.0754C489.322 63.569 488.49 65.4393 487.036 66.8939C485.581 68.3485 483.711 69.1105 481.494 69.1105C478.862 69.1105 477.061 68.1407 475.814 66.8939C474.637 65.7163 473.528 63.9154 473.528 61.0754C473.528 58.5818 474.36 56.7115 475.814 55.2569C477.269 53.8023 479.139 53.0403 481.425 53.0403Z" fill="white"/>
							<path d="M503.715 76.5221H513.69V60.6598C513.69 58.651 513.967 56.4344 515.629 54.772C516.391 53.9408 517.707 53.1096 519.785 53.1096C521.586 53.1096 522.833 53.733 523.595 54.495C525.188 56.0881 525.258 58.651 525.258 60.6598V76.5221H535.232V56.9886C535.232 54.495 535.024 50.8238 532.115 47.9145C529.483 45.2823 525.95 44.7975 523.249 44.7975C520.339 44.7975 516.738 45.4209 513.69 49.2999V45.6287H503.715V76.5221Z" fill="white"/>
						</svg>
					</span>
				</a>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
