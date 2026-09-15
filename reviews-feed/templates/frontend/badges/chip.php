<?php

/**
 * Reviews Feed — Trust Badge, chip style (SBR-163)
 *
 * The compact one: a single centred row of value, stars and count, with the
 * platform marks beneath. Ported from the design rock's badge-builder-v3.html.
 *
 * The three style templates deliberately stay three files rather than one with
 * branches — they differ in element ORDER, not only in spacing, and a shared
 * template would carry a conditional per element for no reuse.
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
		<div class="sbr-tb-row">
			<?php if ($sbr_badge['show_rating']) { ?>
				<span class="sbr-tb-val"><?php echo esc_html($sbr_badge['rating_label']); ?></span>
			<?php } ?>

			<?php
			if ($sbr_badge['show_stars']) {
				include __DIR__ . '/stars.php';
			}
			?>

			<?php if ($sbr_badge['show_count'] && $sbr_badge['is_rated']) { ?>
				<span class="sbr-tb-cnt">(<?php echo esc_html($sbr_badge['count_raw_label']); ?>)</span>
			<?php } ?>
		</div>

		<?php
		if ($sbr_badge['show_logos']) {
			include __DIR__ . '/logos.php';
		}
		?>
	</div>

	<?php include __DIR__ . '/tb-footer.php'; ?>
</div>
