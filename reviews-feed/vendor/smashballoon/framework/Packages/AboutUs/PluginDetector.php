<?php

/**
 * Plugin Detector - Detects plugin install/activate states using JSON data.
 *
 * @package AboutUs
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\AboutUs;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Detects installed/activated status of plugins defined in JSON data file.
 */
class PluginDetector
{
    /**
     * Cached list of installed plugins.
     *
     * @var array|null
     */
    private static $installed_plugins = null;
    /**
     * Get status of all Smash Balloon plugins from JSON data.
     *
     * @return array
     */
    public static function get_sb_plugins_status()
    {
        $installed = self::get_installed_plugins();
        $json_data = ContentProvider::get_section('our_plugins');
        $sb_plugins = isset($json_data['plugins']) ? $json_data['plugins'] : [];
        $plugins = [];
        foreach ($sb_plugins as $key => $plugin) {
            $status = self::detect_plugin_status($plugin, $installed);
            $plugins[$key] = array_merge($plugin, $status);
        }
        return $plugins;
    }
    /**
     * Get status of all recommended third-party plugins from JSON data.
     *
     * @return array
     */
    public static function get_recommended_plugins_status()
    {
        $installed = self::get_installed_plugins();
        $json_data = ContentProvider::get_section('recommended_plugins');
        $rec_plugins = isset($json_data['plugins']) ? $json_data['plugins'] : [];
        $plugins = [];
        foreach ($rec_plugins as $key => $plugin) {
            $plugin_file = isset($plugin['plugin_file']) ? $plugin['plugin_file'] : '';
            $is_installed = $plugin_file && isset($installed[$plugin_file]);
            $is_activated = $is_installed && is_plugin_active($plugin_file);
            $plugins[$key] = array_merge($plugin, ['installed' => $is_installed, 'activated' => $is_activated]);
        }
        return $plugins;
    }
    /**
     * Detect the install/activate status of a Smash Balloon plugin.
     *
     * @param array $plugin    Plugin data from JSON.
     * @param array $installed List of installed plugins.
     *
     * @return array
     */
    private static function detect_plugin_status($plugin, $installed)
    {
        $pro_file = isset($plugin['pro']) ? $plugin['pro'] : '';
        $free_file = isset($plugin['free']) ? $plugin['free'] : '';
        $has_pro = $pro_file && isset($installed[$pro_file]);
        $has_free = $free_file && isset($installed[$free_file]);
        if ($has_pro) {
            return ['type' => 'pro', 'installed' => \true, 'activated' => is_plugin_active($pro_file), 'current_basename' => $pro_file];
        }
        if ($has_free) {
            return ['type' => 'free', 'installed' => \true, 'activated' => is_plugin_active($free_file), 'current_basename' => $free_file];
        }
        return ['type' => 'none', 'installed' => \false, 'activated' => \false, 'current_basename' => ''];
    }
    /**
     * Get every plugin basename and download URL the About Us page offers.
     *
     * The AJAX handlers only act on these, so the endpoints cannot be used to
     * manage arbitrary plugins on the site.
     *
     * @return array
     */
    public static function get_allowed_targets()
    {
        $targets = [];
        foreach (['our_plugins', 'recommended_plugins'] as $section) {
            $json_data = ContentProvider::get_section($section);
            $plugins = isset($json_data['plugins']) && is_array($json_data['plugins']) ? $json_data['plugins'] : [];
            foreach ($plugins as $plugin) {
                foreach (['pro', 'free', 'plugin_file', 'download_url'] as $key) {
                    if (!empty($plugin[$key]) && is_string($plugin[$key])) {
                        $targets[] = $plugin[$key];
                    }
                }
            }
        }
        $promo = ContentProvider::get_section('featured_promo');
        if (!empty($promo['button_plugin']) && is_string($promo['button_plugin'])) {
            $targets[] = $promo['button_plugin'];
        }
        return $targets;
    }
    /**
     * Get list of installed plugins (cached).
     *
     * @return array
     */
    private static function get_installed_plugins()
    {
        if (null === self::$installed_plugins) {
            if (!function_exists('get_plugins')) {
                require_once \ABSPATH . 'wp-admin/includes/plugin.php';
            }
            self::$installed_plugins = get_plugins();
        }
        return self::$installed_plugins;
    }
    /**
     * Reset cached data (useful for testing).
     *
     * @return void
     */
    public static function reset()
    {
        self::$installed_plugins = null;
    }
}
