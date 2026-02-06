<?php

/**
 * Smash Balloon Reviews Feed Text Template - Booking.com
 * Custom template for Booking.com reviews with title, pros/cons, and photos
 *
 * @package SmashBalloon\Reviews
 * @version 1.0 Reviews Feed by Smash Balloon
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Check if review has title, pros, cons, and photos
$has_title = !empty($post['title']);
$has_pros = !empty($post['metadata']['pros']);
$has_cons = !empty($post['metadata']['cons']);
$reviewer_photos = !empty($post['reviewer_photos']) ? $post['reviewer_photos'] : [];

// Smiley face SVG icons
$pros_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16px" height="16px" style="flex-shrink: 0; margin-right: 8px;"><path fill="#008234" d="M22.5 12c0 5.799-4.701 10.5-10.5 10.5S1.5 17.799 1.5 12 6.201 1.5 12 1.5 22.5 6.201 22.5 12m1.5 0c0-6.627-5.373-12-12-12S0 5.373 0 12s5.373 12 12 12 12-5.373 12-12M5.634 13.5a1.5 1.5 0 0 0-1.414 2 8.25 8.25 0 0 0 15.56 0 1.5 1.5 0 0 0-1.414-2zm0 1.5h12.732a6.75 6.75 0 0 1-12.732 0M16.5 8.625a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5m-9 0a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5"/></svg>';

$cons_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16px" height="16px" style="flex-shrink: 0; margin-right: 8px;"><path fill="#1a1a1a" d="M22.5 12c0 5.799-4.701 10.5-10.5 10.5S1.5 17.799 1.5 12 6.201 1.5 12 1.5 22.5 6.201 22.5 12m1.5 0c0-6.627-5.373-12-12-12S0 5.373 0 12s5.373 12 12 12 12-5.373 12-12m-5.28 5.667a7.502 7.502 0 0 0-13.444 0 .75.75 0 1 0 1.344.666 6.002 6.002 0 0 1 10.756 0 .75.75 0 0 0 1.344-.666M8.25 9.375a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5m7.5 0a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5"/></svg>';
?>

<?php if ($has_title) : ?>
<div class="sb-item-title sb-fs" style="margin-bottom: 12px;">
	<strong><?php echo esc_html($post['title']); ?></strong>
</div>
<?php endif; ?>

<?php
if ($has_pros || $has_cons) :
	$allowed_svg = array(
		'svg'  => array(
			'xmlns'   => array(),
			'viewbox' => array(),
			'width'   => array(),
			'height'  => array(),
			'style'   => array(),
		),
		'path' => array(
			'fill' => array(),
			'd'    => array(),
		),
	);
	?>
<div class="sb-item-pros-cons" style="margin-bottom: 12px;">
	<?php if ($has_pros) : ?>
	<div class="sb-item-pros" style="display: flex; align-items: flex-start; margin-bottom: 8px; clear: both;">
		<?php echo wp_kses($pros_icon, $allowed_svg); ?>
		<span class="sb-pros-text"><?php echo wp_kses_post(nl2br($post['metadata']['pros'])); ?></span>
	</div>
	<?php endif; ?>

	<?php if ($has_cons) : ?>
	<div class="sb-item-cons" style="display: flex; align-items: flex-start;">
		<?php echo wp_kses($cons_icon, $allowed_svg); ?>
		<span class="sb-cons-text"><?php echo wp_kses_post(nl2br($post['metadata']['cons'])); ?></span>
	</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php if (! empty($reviewer_photos)) :
	// Generate unique review ID for lightbox grouping (uses post_id if available, or hash of review data).
	$review_lightbox_id = ! empty($post['post_id']) ? 'booking-' . esc_attr($post['post_id']) : 'booking-' . esc_attr(substr(md5(wp_json_encode($reviewer_photos)), 0, 8));
	?>
<div class="sb-reviewer-photos" style="display: grid; grid-template-columns: repeat(auto-fill, 80px); gap: 10px; margin-top: 12px;">
	<?php foreach ($reviewer_photos as $idx => $photo) :
		// Get full-size image URL (prefer largest available).
		$full_size_url = ! empty($photo['1280_900']) ? $photo['1280_900'] : ( ! empty($photo['500_500']) ? $photo['500_500'] : $photo['90_90'] );
		?>
		<a href="<?php echo esc_url($full_size_url); ?>" class="sb-reviewer-photo sb-reviewer-photo-link" data-sbr-lightbox="<?php echo esc_attr($review_lightbox_id); ?>" data-photo-index="<?php echo esc_attr($idx); ?>" style="display: block; width: 80px; height: 80px; cursor: pointer; overflow: hidden; border-radius: 4px;">
			<img src="<?php echo esc_url($photo['90_90']); ?>" alt="<?php echo esc_attr(sprintf(__('Review photo %d', 'reviews-feed'), $idx + 1)); ?>" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy" />
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="sb-expand">
	<a href="#" data-link="<?php echo esc_url($this->more_link($post)); ?>">
		<span class="sb-more">...</span>
	</a>
</div>
