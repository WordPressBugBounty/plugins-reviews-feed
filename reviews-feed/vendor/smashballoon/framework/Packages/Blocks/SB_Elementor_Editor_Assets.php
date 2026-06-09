<?php

namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

class SB_Elementor_Editor_Assets
{
    /**
     * Register editor script and style for a modern Elementor widget.
     *
     * @param array $config {
     *     @type string $script_handle  Handle for the editor script.
     *     @type string $script_src     URL to the editor JS file.
     *     @type string $style_handle   Handle for the editor style.
     *     @type string $style_name     File basename for the editor CSS.
     *     @type string $localize_var   JS variable name for localized data.
     *     @type array  $localize_data  Data to localize.
     *     @type string $plugin_url     Plugin URL constant.
     *     @type string $plugin_dir     Plugin directory path constant.
     *     @type string $version        Plugin version constant.
     *     @type bool   $is_production  Whether running in production mode.
     * }
     */
    public static function register_modern_editor_assets($config)
    {
        $editor_asset = self::get_editor_asset($config['plugin_dir'], $config['style_name']);
        wp_register_script($config['script_handle'], $config['script_src'], array_unique(array_merge($editor_asset['dependencies'], array('jquery'))), $editor_asset['version'], \true);
        wp_localize_script($config['script_handle'], $config['localize_var'], $config['localize_data']);
        wp_register_style($config['style_handle'], trailingslashit($config['plugin_url']) . SB_Block_Utils::get_style_path($config['style_name']) . '.css', array(), $config['version']);
    }
    /**
     * Enqueue shared Elementor styles (icons, modals, buttons).
     *
     * Call this from each plugin's Elementor base class so the shared
     * sb-elementor.css is loaded once from the framework package.
     *
     * @param string $version Plugin version for cache busting.
     */
    public static function enqueue_shared_elementor_styles($version = '1.0.0')
    {
        if (wp_style_is('sb-elementor-shared-style', 'enqueued')) {
            return;
        }
        wp_enqueue_style('sb-elementor-shared-style', plugin_dir_url(__FILE__) . 'css/sb-elementor.css', array(), $version);
    }
    /**
     * Load the asset manifest for an editor script.
     *
     * @param string $plugin_dir     Plugin directory path.
     * @param string $script_name    Script file basename (without extension).
     *
     * @return array { dependencies: string[], version: string }
     */
    public static function get_editor_asset($plugin_dir, $script_name)
    {
        $asset_path = 'dist/' . $script_name . '.asset.php';
        $full_path = trailingslashit($plugin_dir) . $asset_path;
        if (file_exists($full_path)) {
            return include $full_path;
        }
        return array('dependencies' => array('jquery', 'wp-element'), 'version' => '1.0.0');
    }
}
