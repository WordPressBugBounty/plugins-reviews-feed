<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Featured promo section template - generic, configurable via JSON.
 *
 * @var array  $promo      Featured promo content from JSON.
 * @var string $asset_url  URL to assets directory.
 *
 * @package AboutUs
 */
if (!\defined('ABSPATH')) {
    exit;
}
$badge_icon = isset($promo['badge_icon']) ? $promo['badge_icon'] : '';
$badge_text = isset($promo['badge_text']) ? $promo['badge_text'] : '';
$heading = isset($promo['heading']) ? $promo['heading'] : '';
$description = isset($promo['description']) ? $promo['description'] : '';
$button_text = isset($promo['button_text']) ? $promo['button_text'] : '';
$button_url = isset($promo['button_url']) ? $promo['button_url'] : '';
$button_action = isset($promo['button_action']) && \in_array($promo['button_action'], ['install', 'activate'], \true) ? $promo['button_action'] : '';
$button_plugin = isset($promo['button_plugin']) ? $promo['button_plugin'] : '';
$promo_image = isset($promo['promo_image']) ? $promo['promo_image'] : '';
if (empty($heading)) {
    return;
}
?>
<div class="sbc-about-us-section sbc-about-us-featured-promo">
	<div class="sbc-about-us-promo-inner">
		<div class="sbc-about-us-promo-content">
			<?php 
if ($badge_text) {
    ?>
				<div class="sbc-about-us-promo-badge">
					<?php 
    if ($badge_icon) {
        ?>
						<img src="<?php 
        echo \esc_url(\filter_var($badge_icon, \FILTER_VALIDATE_URL) ? $badge_icon : $asset_url . $badge_icon);
        ?>" alt="" class="sbc-about-us-promo-badge-icon" />
					<?php 
    }
    ?>
					<span><?php 
    echo \esc_html($badge_text);
    ?></span>
				</div>
			<?php 
}
?>

			<h3><?php 
echo \esc_html($heading);
?></h3>

			<?php 
if ($description) {
    ?>
				<?php 
    // Sanitize first: remote content may wrap the description in tags
    // kses strips (e.g. WPChat), leaving bare text. If the sanitized
    // result has no tags left, wrap it in a paragraph so it inherits the
    // promo paragraph styling (colour + spacing) and stays readable on
    // the dark promo background.
    $description_html = sbc_about_us_kses($description);
    if ($description_html === \wp_strip_all_tags($description_html)) {
        $description_html = '<p>' . $description_html . '</p>';
    }
    ?>
				<?php 
    echo $description_html;
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above via sbc_about_us_kses(). 
    ?>
			<?php 
}
?>

			<?php 
if ($button_text) {
    ?>
				<?php 
    if ($button_url) {
        ?>
					<a href="<?php 
        echo \esc_url($button_url);
        ?>" class="sbc-about-us-promo-btn" target="_blank" rel="noopener noreferrer">
						<?php 
        echo \esc_html($button_text);
        ?>
						<img src="<?php 
        echo \esc_url($asset_url . 'icons/angle-right.svg');
        ?>" alt="" width="16" height="16" />
					</a>
				<?php 
    } elseif ($button_action && $button_plugin) {
        ?>
					<button class="sbc-about-us-promo-btn sbc-about-us-action-btn"
						data-action="<?php 
        echo \esc_attr($button_action);
        ?>"
						data-plugin="<?php 
        echo \esc_attr($button_plugin);
        ?>">
						<?php 
        echo \esc_html($button_text);
        ?>
						<img src="<?php 
        echo \esc_url($asset_url . 'icons/angle-right.svg');
        ?>" alt="" width="16" height="16" />
					</button>
				<?php 
    }
    ?>
			<?php 
}
?>
		</div>

		<?php 
if ($promo_image) {
    ?>
			<div class="sbc-about-us-promo-image">
				<img src="<?php 
    echo \esc_url(\filter_var($promo_image, \FILTER_VALIDATE_URL) ? $promo_image : $asset_url . $promo_image);
    ?>" alt="" />
			</div>
		<?php 
}
?>
	</div>
</div>
<?php 
