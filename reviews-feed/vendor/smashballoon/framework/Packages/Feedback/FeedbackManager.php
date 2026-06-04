<?php

/**
 * Feedback Manager - Main entry point for the feedback library.
 *
 * @package Feedback
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Feedback;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Manages feedback collection across Smash Balloon plugins.
 * Initializes the deactivation survey and API client.
 */
class FeedbackManager
{
    /**
     * Plugin configuration.
     *
     * @var array
     */
    private static $configs = [];
    /**
     * Whether the manager has been bootstrapped.
     *
     * @var bool
     */
    private static $booted = \false;
    /**
     * Initialize the feedback system for a plugin.
     *
     * @param array $config {
     *     Plugin configuration.
     *
     *     @type string $plugin_slug    Required. Plugin slug (e.g. 'instagram-feed').
     *     @type string $plugin_name    Required. Display name (e.g. 'Smash Balloon Instagram Feed').
     *     @type string $plugin_version Required. Current plugin version.
     *     @type string $plugin_file    Required. Main plugin file path (__FILE__ from main plugin file).
     *     @type string $support_url        Optional. Support page URL. Default: 'https://smashballoon.com/support/'.
     *     @type string $api_endpoint       Optional. Feedback API endpoint URL.
     *     @type bool   $enable_help_widget Optional. Enable the Help Widget on admin pages. Default: false (opt-in per plugin).
     *     @type string $help_url           Optional. Help/docs URL for this plugin.
     *     @type array  $help_widget_screens Optional. Additional screen IDs to show the Help Widget on.
     * }
     *
     * @return void
     */
    public static function init(array $config)
    {
        $defaults = ['plugin_slug' => '', 'plugin_name' => '', 'plugin_version' => '', 'plugin_file' => '', 'support_url' => 'https://smashballoon.com/support/', 'api_endpoint' => '', 'enable_help_widget' => \false, 'help_url' => '', 'help_widget_screens' => []];
        $config = wp_parse_args($config, $defaults);
        if (empty($config['plugin_slug']) || empty($config['plugin_name']) || empty($config['plugin_file'])) {
            return;
        }
        $slug = sanitize_key($config['plugin_slug']);
        // Prevent duplicate registration.
        if (isset(self::$configs[$slug])) {
            return;
        }
        self::$configs[$slug] = $config;
        self::boot();
    }
    /**
     * Bootstrap the feedback system (once).
     *
     * @return void
     */
    private static function boot()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = \true;
        // Hook directly — screen checks happen inside the callbacks.
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
        add_action('admin_footer', [__CLASS__, 'maybe_render']);
        add_action('wp_ajax_sb_deactivation_feedback', [DeactivationSurvey::class, 'handle_ajax']);
        // Help Widget hooks (additive — does not affect deactivation flow).
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue_help_widget']);
        add_action('admin_footer', [__CLASS__, 'maybe_render_help_widget']);
        add_action('wp_ajax_sb_feature_suggestion', [HelpWidget::class, 'handle_ajax']);
    }
    /**
     * Check if we're on the plugins page.
     *
     * @return bool
     */
    private static function is_plugins_page()
    {
        if (!current_user_can('activate_plugins')) {
            return \false;
        }
        global $pagenow;
        return 'plugins.php' === $pagenow;
    }
    /**
     * Enqueue assets if on plugins page.
     *
     * @return void
     */
    public static function maybe_enqueue()
    {
        if (!self::is_plugins_page()) {
            return;
        }
        $survey = new DeactivationSurvey(self::$configs);
        $survey->enqueue_assets();
    }
    /**
     * Render modal if on plugins page.
     *
     * @return void
     */
    public static function maybe_render()
    {
        if (!self::is_plugins_page()) {
            return;
        }
        $survey = new DeactivationSurvey(self::$configs);
        $survey->render_modal();
    }
    /**
     * Check if we're on a Smash Balloon admin screen.
     *
     * Uses the same $_GET['page'] detection pattern that individual SB plugins
     * use internally (e.g. Util::isIFPage() in Instagram Feed Pro).
     *
     * @return bool
     */
    private static function is_sb_admin_screen()
    {
        // No capability gate here on purpose. The SB admin pages already
        // enforce their own caps to be reachable, and the AJAX submit
        // handler enforces manage_options separately. Gating render here
        // would silently hide the FAB for Editor-role users who can
        // legitimately use the plugin's admin screens.
        if (!is_admin()) {
            return \false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection.
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (empty($current_page)) {
            return \false;
        }
        // Only match prefixes owned by plugins that have registered with
        // the Help Widget enabled. This prevents an updated plugin from
        // rendering a FAB on a stale (pre-Help-Widget) sibling plugin's
        // admin page — where its help_url, plugin name, version and
        // feedback-submission attribution would all be wrong. The
        // 'smash-balloon' prefix is kept as a legacy generic-page catch.
        $enabled_slugs = array_keys(self::get_help_widget_configs());
        if (empty($enabled_slugs)) {
            return \false;
        }
        $sb_page_prefixes = array_merge(HelpWidget::get_screen_prefixes_for_slugs($enabled_slugs), ['smash-balloon']);
        foreach ($sb_page_prefixes as $prefix) {
            if (strpos($current_page, $prefix) === 0) {
                return \true;
            }
        }
        // Check plugin-registered extra page slugs from enabled configs only.
        foreach (self::get_help_widget_configs() as $config) {
            if (!empty($config['help_widget_screens'])) {
                $extra = (array) $config['help_widget_screens'];
                if (in_array($current_page, $extra, \true)) {
                    return \true;
                }
            }
        }
        /**
         * Filter whether the current page is an SB admin screen.
         *
         * @param bool   $is_sb_screen Whether the current page is an SB admin screen.
         * @param string $current_page The current admin page slug from $_GET['page'].
         */
        return (bool) apply_filters('sb_help_widget_is_admin_screen', \false, $current_page);
    }
    /**
     * Whether the Help Widget should render on the current admin screen.
     *
     * Hides the widget on Feed Builder edit/customize screens. The feed
     * builder is dense (sidebar + preview canvas + modals) and a global
     * FAB competes with builder UI. Listing and create flows have no
     * feed_id in the URL — those still show the widget.
     *
     * @return bool
     */
    private static function should_render_help_widget()
    {
        if (!self::is_sb_admin_screen()) {
            return \false;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
        $on_builder_edit = !empty($_GET['feed_id']);
        /**
         * Filter whether the Help Widget should render on the current screen.
         *
         * Default: true on SB admin screens, except when ?feed_id= is set
         * (Feed Builder edit/customize). Plugins can override either way.
         *
         * @param bool $should_render
         */
        return (bool) apply_filters('sb_help_widget_should_render', !$on_builder_edit);
    }
    /**
     * Get configs that have the Help Widget enabled.
     *
     * @return array
     */
    private static function get_help_widget_configs()
    {
        return array_filter(self::$configs, function ($config) {
            return !empty($config['enable_help_widget']);
        });
    }
    /**
     * Enqueue Help Widget assets if on an SB admin screen.
     *
     * @return void
     */
    public static function maybe_enqueue_help_widget()
    {
        if (!class_exists(__NAMESPACE__ . '\HelpWidget')) {
            return;
        }
        $configs = self::get_help_widget_configs();
        if (empty($configs)) {
            return;
        }
        if (!self::should_render_help_widget()) {
            return;
        }
        $widget = new HelpWidget($configs);
        $widget->enqueue_assets();
    }
    /**
     * Render Help Widget if on an SB admin screen.
     *
     * @return void
     */
    public static function maybe_render_help_widget()
    {
        if (!class_exists(__NAMESPACE__ . '\HelpWidget')) {
            return;
        }
        $configs = self::get_help_widget_configs();
        if (empty($configs)) {
            return;
        }
        if (!self::should_render_help_widget()) {
            return;
        }
        $widget = new HelpWidget($configs);
        $widget->render_widget();
    }
    /**
     * Get configuration for a specific plugin.
     *
     * @param string $slug Plugin slug.
     * @return array|null
     */
    public static function get_config($slug)
    {
        return isset(self::$configs[$slug]) ? self::$configs[$slug] : null;
    }
    /**
     * Get all registered plugin configurations.
     *
     * @return array
     */
    public static function get_all_configs()
    {
        return self::$configs;
    }
    /**
     * Reset state (useful for testing).
     *
     * @return void
     */
    public static function reset()
    {
        self::$configs = [];
        self::$booted = \false;
    }
}
