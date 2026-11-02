<?php

/**
 * ConsentAjax — registers the shared wp_ajax_sbc_save_consent_choice
 * endpoint used by the re-prompt modal and the onboarding checkbox.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
class ConsentAjax
{
    /**
     * @var bool
     */
    private static $registered = \false;
    /**
     * Idempotent registration. Safe to call from multiple plugin bootstraps.
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = \true;
        add_action('wp_ajax_' . ConsentManager::AJAX_ACTION, array(__CLASS__, 'handle'));
        add_action('admin_init', [__CLASS__, 'maybe_handle_page_fallback'], 1);
    }
    /**
     * Fallback save through a normal wp-admin page request, used by the modal
     * when admin-ajax.php fails (some hosts return 500 for every admin-ajax
     * request while admin pages still load). Acts only on its own marker.
     *
     * @return void
     */
    public static function maybe_handle_page_fallback()
    {
        if (\true === empty($_POST['sbc_consent_fallback']) || \false === isset($_POST['action'])) {
            return;
        }
        if ($_POST['action'] !== ConsentManager::AJAX_ACTION) {
            return;
        }
        $nonce = '';
        if (\true === isset($_POST['nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
        }
        if (\false === wp_verify_nonce($nonce, ConsentManager::NONCE_ACTION) || \false === current_user_can('manage_options')) {
            wp_send_json_error(['reason' => 'forbidden'], 403);
        }
        self::apply_choice();
        wp_send_json_success();
    }
    /**
     * Both Accept and Skip flow through here. Either way we mark the modal
     * as dismissed (3rd arg of ConsentManager::update) so it never re-prompts.
     */
    public static function handle()
    {
        check_ajax_referer(ConsentManager::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('reason' => 'capability'));
            return;
        }
        self::apply_choice();
        wp_send_json_success();
    }
    /**
     * Store the posted choice. Shared by the AJAX handler and the page fallback;
     * callers check the nonce and capability first.
     *
     * @return void
     */
    private static function apply_choice()
    {
        $has_dsc = isset($_POST['dsc']);
        $has_notif = isset($_POST['notif']);
        if ($has_dsc || $has_notif) {
            // Per-flag granularity: honor only the keys explicitly sent and
            // preserve the stored value for the absent one — matches the REST
            // endpoint's semantics. Coerce via the shared to_bool() so 'true'
            // / 'yes' / 'on' / '0' all behave the same across AJAX and REST.
            $flags = ConsentManager::flags();
            $dsc = $has_dsc ? ConsentManager::to_bool(sanitize_text_field(wp_unslash($_POST['dsc']))) : $flags['dsc'];
            $notif = $has_notif ? ConsentManager::to_bool(sanitize_text_field(wp_unslash($_POST['notif']))) : $flags['notif'];
        } else {
            $choice = isset($_POST['choice']) ? sanitize_text_field(wp_unslash($_POST['choice'])) : '';
            $accept = $choice === 'accept';
            $dsc = $accept;
            $notif = $accept;
        }
        ConsentManager::update($dsc, $notif, \true);
    }
}
