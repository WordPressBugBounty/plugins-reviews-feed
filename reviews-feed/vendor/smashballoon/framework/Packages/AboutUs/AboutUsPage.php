<?php

/**
 * About Us Page - Handles menu registration, asset enqueue, and rendering for one plugin.
 *
 * @package AboutUs
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\AboutUs;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Manages a single plugin's About Us admin page.
 */
class AboutUsPage
{
    /**
     * Plugin configuration.
     *
     * @var array
     */
    private $config;
    /**
     * Constructor.
     *
     * @param array $config Plugin configuration from AboutUsManager.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    /**
     * Register hooks.
     *
     * @return void
     */
    public function register()
    {
        add_action('admin_menu', [$this, 'register_menu'], 20);
    }
    /**
     * Register the About Us submenu page.
     *
     * @return void
     */
    public function register_menu()
    {
        $capability = $this->config['capability'];
        $hook = add_submenu_page($this->config['menu_parent'], __('About Us', 'sb-common'), __('About Us', 'sb-common'), $capability, $this->config['page_slug'], [$this, 'render'], $this->config['menu_position']);
        if ($hook) {
            add_action('load-' . $hook, [$this, 'enqueue_assets']);
        }
    }
    /**
     * Enqueue page assets.
     *
     * @return void
     */
    public function enqueue_assets()
    {
        $asset_url = $this->get_asset_url();
        $version = $this->config['plugin_version'];
        wp_enqueue_style('sb-about-us-style', $asset_url . 'about-us.css', [], $version);
        wp_enqueue_script('sb-about-us-script', $asset_url . 'about-us.js', [], $version, \true);
        wp_localize_script('sb-about-us-script', 'sb_about_us', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sb_about_us_nonce'), 'i18n' => ['installing' => __('Installing...', 'sb-common'), 'activating' => __('Activating...', 'sb-common'), 'activated' => __('Activated', 'sb-common'), 'installed' => __('Installed', 'sb-common'), 'active' => __('Active', 'sb-common'), 'install' => __('Install', 'sb-common'), 'activate' => __('Activate', 'sb-common'), 'failed' => __('Failed', 'sb-common'), 'retry' => __('Retry', 'sb-common')]]);
    }
    /**
     * Render the About Us page.
     *
     * @return void
     */
    public function render()
    {
        $content = ContentProvider::get_content();
        $sb_plugins = PluginDetector::get_sb_plugins_status();
        $rec_plugins = PluginDetector::get_recommended_plugins_status();
        $data = ['config' => $this->config, 'content' => $content, 'sb_plugins' => $sb_plugins, 'recommended_plugins' => $rec_plugins, 'asset_url' => $this->get_asset_url()];
        $template = dirname(__FILE__) . '/templates/page.php';
        if (file_exists($template)) {
            include $template;
        }
    }
    /**
     * Get the URL to the assets directory.
     *
     * @return string
     */
    private function get_asset_url()
    {
        $asset_dir = dirname(__FILE__) . '/assets/';
        $content_dir = wp_normalize_path(\WP_CONTENT_DIR);
        $asset_path = wp_normalize_path($asset_dir);
        if (strpos($asset_path, $content_dir) === 0) {
            $relative = substr($asset_path, strlen($content_dir));
            return content_url($relative);
        }
        // Fallback using plugin_file.
        if (!empty($this->config['plugin_file'])) {
            $plugin_dir = wp_normalize_path(dirname($this->config['plugin_file']));
            if (strpos($asset_path, $plugin_dir) === 0) {
                $relative = substr($asset_path, strlen($plugin_dir));
                return plugins_url($relative, $this->config['plugin_file']);
            }
        }
        return '';
    }
}
