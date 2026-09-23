<?php

/**
 * ConsentAssets — enqueues the modal CSS/JS and renders the modal template
 * in admin_footer for any registered active plugin's screens.
 *
 * Screen scope: any admin page where at least one registered plugin is
 * active. A consumer plugin's init([]) call is the explicit opt-in. The
 * modal itself only `data-active`s itself if `showRepromptModal` is true,
 * so over-broad eligibility is harmless — render it everywhere, gate the
 * actual display via the localized config.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
class ConsentAssets
{
    const ASSET_HANDLE = 'sbc-consent';
    /**
     * @var bool
     */
    private static $registered = \false;
    /**
     * Idempotent registration. Safe to call from multiple bootstraps.
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = \true;
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_footer', array(__CLASS__, 'render_modal_template'));
    }
    /**
     * Compute the URL for an asset under the package directory. The package
     * lives under `vendor/smashballoon/framework/Packages/Consent/` in each
     * consumer plugin — so we map __DIR__ back to a public URL via the
     * abspath-relative trick.
     *
     * @param string $relative_path  e.g. 'assets/css/consent.css'
     * @return string
     */
    private static function asset_url($relative_path)
    {
        $abspath = wp_normalize_path(\ABSPATH);
        $dir = wp_normalize_path(__DIR__);
        if (strpos($dir, $abspath) === 0) {
            $relative = ltrim(substr($dir, strlen($abspath)), '/');
            return site_url('/' . $relative . '/' . ltrim($relative_path, '/'));
        }
        // Fallback: plugins_url against the consuming plugin's vendor copy.
        // This works when the package is loaded from inside a plugin's
        // vendor/ directory (the typical case).
        return plugins_url(ltrim($relative_path, '/'), __FILE__);
    }
    /**
     * Enqueue CSS + JS and localize the sbcConsent global.
     */
    public static function enqueue()
    {
        if (!ConsentManager::has_active_instance()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_style(self::ASSET_HANDLE, self::asset_url('assets/css/consent.css'), array(), '1.0.3');
        wp_enqueue_script(self::ASSET_HANDLE, self::asset_url('assets/js/consent.js'), array(), '1.0.3', \true);
        $show_modal = self::should_show_reprompt_modal();
        // Localize on the first registered slug (consent is shared, so any
        // slug is fine for the diagnostic field).
        $slugs = ConsentManager::get_registered_slugs();
        $plugin_slug = !empty($slugs) ? reset($slugs) : '';
        wp_localize_script(self::ASSET_HANDLE, 'sbcConsent', array('ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce(ConsentManager::NONCE_ACTION), 'showRepromptModal' => $show_modal, 'plugin_slug' => $plugin_slug, 'restRoot' => esc_url_raw(rest_url('sbc/v1/')), 'restNonce' => wp_create_nonce('wp_rest'), 'isPro' => ConsentManager::is_pro()));
    }
    /**
     * The re-prompt modal targets EXISTING users who upgraded into the
     * consent feature: never opted in (DSC=off), and never explicitly
     * dismissed the prompt.
     *
     * @return bool
     */
    public static function should_show_reprompt_modal()
    {
        if (ConsentManager::is_pro()) {
            return \false;
        }
        if (ConsentManager::is_modal_dismissed()) {
            return \false;
        }
        if (ConsentManager::is_dsc_enabled()) {
            return \false;
        }
        return apply_filters('sbc_consent_show_reprompt_modal', \true);
    }
    /**
     * admin_footer renderer for the re-prompt modal template. Always
     * outputs the markup with `data-active="false"` so the JS can show
     * it on demand without a second DOM round-trip.
     */
    public static function render_modal_template()
    {
        if (!ConsentManager::has_active_instance()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        // Pro builds skip the reprompt modal entirely (per plan step 3).
        if (ConsentManager::is_pro()) {
            return;
        }
        $template = __DIR__ . '/templates/consent-reprompt-popup.php';
        if (is_readable($template)) {
            include $template;
        }
    }
}
