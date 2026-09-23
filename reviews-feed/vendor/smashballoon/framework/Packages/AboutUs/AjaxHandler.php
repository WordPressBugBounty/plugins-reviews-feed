<?php

/**
 * Ajax Handler - Shared AJAX endpoints for plugin install/activate.
 *
 * @package AboutUs
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\AboutUs;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Handles AJAX requests for plugin management from the About Us page.
 */
class AjaxHandler
{
    /**
     * Register AJAX handlers.
     *
     * @return void
     */
    public static function register()
    {
        add_action('wp_ajax_sb_about_install_plugin', [__CLASS__, 'install_plugin']);
        add_action('wp_ajax_sb_about_activate_plugin', [__CLASS__, 'activate_plugin']);
    }
    /**
     * Install a plugin from WordPress.org.
     *
     * @return void
     */
    public static function install_plugin()
    {
        self::verify_request('install_plugins');
        $download_url = isset($_POST['plugin']) ? esc_url_raw(wp_unslash($_POST['plugin'])) : '';
        $error_msg = __('Could not install plugin. Please download and install manually.', 'sb-common');
        if (empty($download_url)) {
            wp_send_json_error($error_msg);
        }
        // Only allow installs from WordPress.org, and only plugins the page offers.
        if (!ContentProvider::is_wporg_download($download_url) || !self::is_allowed($download_url)) {
            wp_send_json_error($error_msg);
        }
        set_current_screen('sb-about-us');
        $url = admin_url('admin.php?page=sb-about-us');
        $creds = request_filesystem_credentials($url, '', \false, \false, null);
        if (\false === $creds || !WP_Filesystem($creds)) {
            wp_send_json_error($error_msg);
        }
        // Prevent translation downloads from breaking JS output.
        remove_action('upgrader_process_complete', ['Language_Pack_Upgrader', 'async_upgrade'], 20);
        // class-wp-upgrader.php require_once's Plugin_Upgrader and every upgrader
        // skin (including WP_Ajax_Upgrader_Skin) at the bottom of the file, so this
        // single include is enough on WP 4.6+.
        if (!class_exists('Plugin_Upgrader') || !class_exists('WP_Ajax_Upgrader_Skin')) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        $installer = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        // Plugin_Upgrader::install() already runs wp_clean_plugins_cache().
        $installer->install($download_url);
        $plugin_basename = $installer->plugin_info();
        if (!$plugin_basename) {
            wp_send_json_error($error_msg);
        }
        // Auto-activate after install, unless only a Network Admin may activate it.
        $activated = self::is_network_managed($plugin_basename) ? new \WP_Error('network_managed') : activate_plugin($plugin_basename);
        wp_send_json_success(['msg' => is_wp_error($activated) ? __('Plugin installed.', 'sb-common') : __('Plugin installed & activated.', 'sb-common'), 'is_activated' => !is_wp_error($activated), 'basename' => $plugin_basename]);
    }
    /**
     * Activate an installed plugin.
     *
     * @return void
     */
    public static function activate_plugin()
    {
        self::verify_request('activate_plugins');
        if (empty($_POST['plugin'])) {
            wp_send_json_error(__('No plugin specified.', 'sb-common'));
        }
        $plugin_basename = sanitize_text_field(wp_unslash($_POST['plugin']));
        // Basic validation of plugin basename format.
        if (!preg_match('/^[a-z0-9_-]+\/[a-z0-9_-]+\.php$/i', $plugin_basename) || !self::is_allowed($plugin_basename)) {
            wp_send_json_error(__('Invalid plugin.', 'sb-common'));
        }
        if (self::is_network_managed($plugin_basename)) {
            wp_send_json_error(__('This plugin is managed network-wide.', 'sb-common'));
        }
        $activated = activate_plugin($plugin_basename);
        if (is_wp_error($activated)) {
            wp_send_json_error(__('Could not activate plugin.', 'sb-common'));
        }
        wp_send_json_success(__('Plugin activated.', 'sb-common'));
    }
    /**
     * Whether the target is one the About Us page offers.
     *
     * @param string $target Plugin basename or download URL.
     *
     * @return bool
     */
    private static function is_allowed($target)
    {
        return in_array($target, PluginDetector::get_allowed_targets(), \true);
    }
    /**
     * Whether only a Network Admin may change this plugin's state.
     *
     * Mirrors the guards in core's wp-admin/plugins.php. admin-ajax.php is never
     * the network admin, so on Multisite this limits the endpoints to per-site plugins.
     *
     * @param string $plugin_basename Plugin basename.
     *
     * @return bool
     */
    private static function is_network_managed($plugin_basename)
    {
        return is_multisite() && !is_network_admin() && (is_network_only_plugin($plugin_basename) || is_plugin_active_for_network($plugin_basename));
    }
    /**
     * Verify nonce and capability.
     *
     * @param string $capability Required capability.
     *
     * @return void
     */
    private static function verify_request($capability)
    {
        check_ajax_referer('sb_about_us_nonce', 'nonce');
        if (!current_user_can($capability)) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'sb-common'));
        }
    }
}
