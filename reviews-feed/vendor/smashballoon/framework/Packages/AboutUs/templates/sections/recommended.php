<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Recommended Plugins section template.
 *
 * @var array  $rec_sec     Recommended section content from JSON (heading).
 * @var array  $rec_plugins Recommended plugins with status.
 * @var string $asset_url   URL to assets directory.
 *
 * @package AboutUs
 */
if (!\defined('ABSPATH')) {
    exit;
}
$section_heading = isset($rec_sec['heading']) ? $rec_sec['heading'] : \__('Plugins we recommend', 'sb-common');
?>
<div class="sbc-about-us-recommended">
	<h2><?php 
echo \esc_html($section_heading);
?></h2>

	<div class="sbc-about-us-recommended-grid">
		<?php 
foreach ($rec_plugins as $key => $plugin) {
    $installed = !empty($plugin['installed']);
    $activated = !empty($plugin['activated']);
    $icon_url = sbc_about_us_icon($key, 'recommended_plugins', $plugin, $asset_url);
    ?>
			<div class="sbc-about-us-rec-card" data-plugin-key="<?php 
    echo \esc_attr($key);
    ?>">
				<?php 
    if ($icon_url) {
        ?>
					<div class="sbc-about-us-rec-icon">
						<img src="<?php 
        echo \esc_url($icon_url);
        ?>" alt="" />
					</div>
				<?php 
    }
    ?>

				<div class="sbc-about-us-rec-content">
					<h3><?php 
    echo \esc_html($plugin['title']);
    ?></h3>
					<?php 
    echo sbc_about_us_kses($plugin['description']);
    ?>

					<div class="sbc-about-us-plugin-action">
						<?php 
    if ($activated) {
        ?>
							<button class="sbc-about-us-btn sbc-about-us-btn-installed" disabled>
								<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M13.3 4L6 11.3 2.7 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<?php 
        \esc_html_e('Installed', 'sb-common');
        ?>
							</button>
						<?php 
    } elseif ($installed) {
        ?>
							<button class="sbc-about-us-btn sbc-about-us-btn-secondary sbc-about-us-action-btn"
								data-action="activate"
								data-plugin="<?php 
        echo \esc_attr($plugin['plugin_file']);
        ?>">
								<?php 
        \esc_html_e('Activate', 'sb-common');
        ?>
							</button>
						<?php 
    } elseif (!empty($plugin['download_url'])) {
        ?>
							<button class="sbc-about-us-btn sbc-about-us-btn-secondary sbc-about-us-action-btn"
								data-action="install"
								data-plugin="<?php 
        echo \esc_attr($plugin['download_url']);
        ?>">
								<img src="<?php 
        echo \esc_url($asset_url . 'icons/download.svg');
        ?>" alt="" width="16" height="16" />
								<?php 
        \esc_html_e('Install', 'sb-common');
        ?>
							</button>
						<?php 
    }
    ?>
					</div>
				</div>
			</div>
		<?php 
}
?>
	</div>
</div>
<?php 
