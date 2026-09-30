<?php

/**
 * About Us Manager - Main entry point for the About Us page library.
 *
 * @package AboutUs
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\AboutUs;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Manages the About Us page across Smash Balloon plugins.
 * Follows the same static pattern as FeedbackManager.
 */
class AboutUsManager
{
    /**
     * Plugin configurations.
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
     * Initialize the About Us page for a plugin.
     *
     * @param array $config {
     *     Plugin configuration.
     *
     *     @type string $plugin_slug    Required. Plugin slug (e.g. 'instagram-feed').
     *     @type string $plugin_name    Required. Display name (e.g. 'Smash Balloon Instagram Feed').
     *     @type string $plugin_version Required. Current plugin version.
     *     @type string $plugin_file    Required. Main plugin file path (__FILE__).
     *     @type string $menu_parent    Required. Parent menu slug for submenu.
     *     @type string $page_slug      Required. Submenu page slug.
     *     @type string $capability     Optional. Required capability. Default 'manage_options'.
     *     @type int    $menu_position  Optional. Menu position. Default 4.
     * }
     *
     * @return void
     */
    public static function init(array $config)
    {
        $defaults = ['plugin_slug' => '', 'plugin_name' => '', 'plugin_version' => '', 'plugin_file' => '', 'menu_parent' => '', 'page_slug' => '', 'capability' => 'manage_options', 'menu_position' => 4];
        $config = wp_parse_args($config, $defaults);
        // Validate required fields.
        $required = ['plugin_slug', 'plugin_name', 'plugin_file', 'menu_parent', 'page_slug'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                return;
            }
        }
        $slug = sanitize_key($config['plugin_slug']);
        // Prevent duplicate registration.
        if (isset(self::$configs[$slug])) {
            return;
        }
        self::$configs[$slug] = $config;
        self::boot();
        // Create and register the page for this plugin.
        $page = new AboutUsPage($config);
        $page->register();
    }
    /**
     * Bootstrap shared components (once).
     *
     * @return void
     */
    private static function boot()
    {
        if (self::$booted) {
            return;
        }
        self::$booted = \true;
        // Register shared AJAX handlers.
        AjaxHandler::register();
        // A single site-wide cron refreshes the cached About Us content for all
        // Smash Balloon plugins. Scheduled lazily (rather than via a per-plugin
        // activation hook) so it works no matter which plugin loaded the shared
        // framework, and self-heals across activate/deactivate/update.
        //
        // WP.org compliance: the event is scheduled only while data-sharing
        // consent is granted (reconcile_cron), never unconditionally, so plan
        // review never sees a scheduled hook that contacts an external URL for a
        // user who has not opted in.
        add_action(ContentProvider::CRON_HOOK, [ContentProvider::class, 'refresh']);
        ContentProvider::reconcile_cron();
        // The moment a user opts in to data sharing (for any Smash Balloon feed
        // plugin), force-fetch the About Us content if the cache is empty or
        // stale — don't wait for the cron or an About Us page view.
        add_action('sbc_consent_changed', [ContentProvider::class, 'refresh_on_consent']);
    }
    /**
     * Get configuration for a specific plugin.
     *
     * @param string $slug Plugin slug.
     *
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
