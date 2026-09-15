<?php

/**
 * Reviews Feed — Trust Badge, card style (SBR-163)
 *
 * The wide one: a large value on the left, stars over the count in the middle,
 * platform marks behind a rule on the right. Ported from the design rock's
 * badge-builder-v3.html.
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 * @var array $sbr_badge        Prepared view data.
 * @var float $sbr_badge_rating Rating the star row draws.
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}


// Fills every key the shapes and their partials read, so a partial payload
// renders an empty shell instead of PHP 8 warnings printed into the page.
include __DIR__ . '/tb-view-defaults.php';

?>
<div class="<?php echo esc_attr($sbr_badge['classes']); ?>" data-theme="<?php echo esc_attr($sbr_badge['theme']); ?>" data-logos="<?php echo esc_attr($sbr_badge['logos_attr']); ?>"<?php echo ! empty($sbr_badge['is_placeholder']) ? ' data-placeholder="1"' : ''; ?><?php echo $sbr_badge['style'] !== '' ? ' style="' . esc_attr($sbr_badge['style']) . '"' : ''; ?>>
	<div class="sbr-tb-main">
		<?php if ($sbr_badge['show_rating']) { ?>
			<span class="sbr-tb-val"><?php echo esc_html($sbr_badge['rating_label']); ?></span>
		<?php } ?>

		<span class="sbr-tb-card-col">
			<?php
			if ($sbr_badge['show_stars']) {
				include __DIR__ . '/stars.php';
			}
			?>

			<?php if ($sbr_badge['show_count'] && $sbr_badge['is_rated']) { ?>
				<span class="sbr-tb-cnt"><?php echo esc_html($sbr_badge['count_based_label']); ?></span>
			<?php } ?>
		</span>

		<?php
		if ($sbr_badge['show_logos']) {
			// The left rule belongs to the marks, so the wrapper only renders
			// when there are marks to separate — an empty rule floating at the
			// card's right edge reads as a rendering fault.
			ob_start();
			include __DIR__ . '/logos.php';
			$sbr_badge_logos = trim((string) ob_get_clean());

			if ($sbr_badge_logos !== '') {
				?>
				<span class="sbr-tb-card-src">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output of logos.php, already escaped there.
					echo $sbr_badge_logos;
					?>
				</span>
				<?php
			}
		}
		?>
	</div>

	<?php include __DIR__ . '/tb-footer.php'; ?>
</div>
