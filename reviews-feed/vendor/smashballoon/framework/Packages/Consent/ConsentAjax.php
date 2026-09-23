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
        wp_send_json_success();
    }
}
