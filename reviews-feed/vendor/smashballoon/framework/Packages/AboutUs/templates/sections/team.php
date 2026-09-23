<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Team section template - Figma-matched design.
 *
 * @var array  $team      Team section content from JSON.
 * @var string $asset_url URL to assets directory.
 *
 * @package AboutUs
 */
if (!\defined('ABSPATH')) {
    exit;
}
$heading = isset($team['heading']) ? $team['heading'] : '';
$description = isset($team['description']) ? $team['description'] : '';
$paragraph_1 = isset($team['paragraph_1']) ? $team['paragraph_1'] : '';
$paragraph_2 = isset($team['paragraph_2']) ? $team['paragraph_2'] : '';
$avatars = isset($team['avatars']) ? $team['avatars'] : [];
?>
<div class="sbc-about-us-section sbc-about-us-team">
	<?php 
if (!empty($avatars)) {
    ?>
		<div class="sbc-about-us-team-header">
			<div class="sbc-about-us-team-avatars">
				<?php 
    foreach ($avatars as $avatar) {
        ?>
					<div class="sbc-about-us-avatar">
						<img src="<?php 
        echo \esc_url(\filter_var($avatar, \FILTER_VALIDATE_URL) ? $avatar : $asset_url . $avatar);
        ?>" alt="" />
					</div>
				<?php 
    }
    ?>
			</div>
		</div>
	<?php 
}
?>

	<div class="sbc-about-us-team-content">
		<div class="sbc-about-us-team-left">
			<h2><?php 
echo \esc_html($heading);
?></h2>
		</div>
		<div class="sbc-about-us-team-right">
			<?php 
if ('' !== $description) {
    ?>
				<?php 
    echo sbc_about_us_kses($description);
    ?>
			<?php 
} else {
    ?>
				<p><?php 
    echo sbc_about_us_kses($paragraph_1);
    ?></p>
				<p><?php 
    echo sbc_about_us_kses($paragraph_2);
    ?></p>
			<?php 
}
?>
		</div>
	</div>
</div>
<?php 
