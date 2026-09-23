<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Our Other Social Media Feed Plugins section template.
 *
 * @var array  $our_sec          Section content from JSON (heading, description).
 * @var array  $sb_plugins       Smash Balloon plugins with status (featured ones flagged).
 * @var array  $config           Plugin configuration.
 * @var string $asset_url        URL to assets directory.
 *
 * @package AboutUs
 */
if (!\defined('ABSPATH')) {
    exit;
}
$section_heading = isset($our_sec['heading']) ? $our_sec['heading'] : \__('Our Other Social Media Feed Plugins', 'sb-common');
$section_desc = isset($our_sec['description']) ? $our_sec['description'] : '';
$plugin_name = isset($config['plugin_name']) ? $config['plugin_name'] : '';
// Replace {plugin_name} placeholder.
$section_desc = \str_replace('{plugin_name}', $plugin_name, $section_desc);
// Split the single plugins list into regular and featured (flagged) cards.
$regular_plugins = \array_filter($sb_plugins, function ($plugin) {
    return empty($plugin['featured']);
});
$featured_plugins = \array_filter($sb_plugins, function ($plugin) {
    return !empty($plugin['featured']);
});
?>
<div class="sbc-about-us-our-plugins">
	<h2><?php 
echo \esc_html($section_heading);
?></h2>
	<?php 
if ($section_desc) {
    ?>
		<div class="sbc-about-us-section-desc"><?php 
    echo sbc_about_us_kses($section_desc);
    ?></div>
	<?php 
}
?>

	<div class="sbc-about-us-plugins-grid">
		<?php 
foreach ($regular_plugins as $key => $plugin) {
    $installed = !empty($plugin['installed']);
    $activated = !empty($plugin['activated']);
    $icon_url = sbc_about_us_bundled_icon($key, 'our_plugins', $asset_url);
    ?>
			<div class="sbc-about-us-plugin-card" data-plugin-key="<?php 
    echo \esc_attr($key);
    ?>">
				<?php 
    if ($icon_url) {
        ?>
					<div class="sbc-about-us-plugin-icon">
						<img src="<?php 
        echo \esc_url($icon_url);
        ?>" alt="" />
					</div>
				<?php 
    }
    ?>

				<div class="sbc-about-us-plugin-info">
					<h3><?php 
    echo \esc_html($plugin['title']);
    ?></h3>
					<?php 
    echo sbc_about_us_kses($plugin['description']);
    ?>
				</div>

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
        echo \esc_attr($plugin['current_basename']);
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
    } elseif (!empty($plugin['pro_url'])) {
        ?>
						<a href="<?php 
        echo \esc_url($plugin['pro_url']);
        ?>" class="sbc-about-us-btn sbc-about-us-btn-secondary" target="_blank" rel="noopener noreferrer">
							<?php 
        \esc_html_e('Get Pro', 'sb-common');
        ?>
						</a>
					<?php 
    }
    ?>
				</div>
			</div>
		<?php 
}
?>
	</div>

	<?php 
if (!empty($featured_plugins)) {
    ?>
		<div class="sbc-about-us-featured-grid">
			<?php 
    foreach ($featured_plugins as $key => $plugin) {
        $installed = !empty($plugin['installed']);
        $activated = !empty($plugin['activated']);
        $icon_url = sbc_about_us_bundled_icon($key, 'our_plugins', $asset_url);
        $demo_url = isset($plugin['demo_url']) ? $plugin['demo_url'] : '';
        ?>
				<div class="sbc-about-us-featured-card" data-plugin-key="<?php 
        echo \esc_attr($key);
        ?>">
					<?php 
        if ($icon_url) {
            ?>
						<div class="sbc-about-us-featured-icon">
							<img src="<?php 
            echo \esc_url($icon_url);
            ?>" alt="" />
						</div>
					<?php 
        }
        ?>

					<div class="sbc-about-us-featured-content">
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
        } elseif ($demo_url) {
            ?>
								<a href="<?php 
            echo \esc_url($demo_url);
            ?>" class="sbc-about-us-btn sbc-about-us-btn-secondary" target="_blank" rel="noopener noreferrer">
									<?php 
            \esc_html_e('See Demo', 'sb-common');
            ?>
									<img src="<?php 
            echo \esc_url($asset_url . 'icons/arrow-top-right.svg');
            ?>" alt="" width="10" height="10" />
								</a>
							<?php 
        } elseif (!empty($plugin['pro_url'])) {
            ?>
								<a href="<?php 
            echo \esc_url($plugin['pro_url']);
            ?>" class="sbc-about-us-btn sbc-about-us-btn-secondary" target="_blank" rel="noopener noreferrer">
									<?php 
            \esc_html_e('Get Pro', 'sb-common');
            ?>
								</a>
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
	<?php 
}
?>
</div>
<?php 
