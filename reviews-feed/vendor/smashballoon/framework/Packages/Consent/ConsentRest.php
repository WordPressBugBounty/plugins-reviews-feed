<?php

/**
 * ConsentRest — exposes GET/POST /sbc/v1/consent so React SPAs and
 * Customizer-based consumers can read and persist consent flags without
 * a server-rendered template. Identical semantics to the per-flag AJAX
 * path; dismiss-modal is intentionally untouched here.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
class ConsentRest
{
    /**
     * Whether routes have been wired. Prevents duplicate
     * register_rest_route calls when multiple plugins init().
     *
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
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }
    /**
     * Wire the two endpoints under sbc/v1/consent. Must be public — WP's
     * hook dispatcher calls it via call_user_func_array from global scope,
     * which can't reach a private method.
     */
    public static function register_routes()
    {
        register_rest_route('sbc/v1', '/consent', array(array('methods' => 'GET', 'callback' => array(__CLASS__, 'get_state'), 'permission_callback' => array(__CLASS__, 'permissions')), array('methods' => 'POST', 'callback' => array(__CLASS__, 'update_state'), 'permission_callback' => array(__CLASS__, 'permissions'))));
    }
    /**
     * @return bool
     */
    public static function permissions()
    {
        return current_user_can('manage_options');
    }
    /**
     * @return \WP_REST_Response
     */
    public static function get_state()
    {
        return rest_ensure_response(self::current_state());
    }
    /**
     * Honors any subset of {dataSharingConsent, inPluginNotifications};
     * absent keys preserve their stored value. Dismiss-flag stays untouched
     * so REST callers can't accidentally suppress the reprompt modal.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function update_state($request)
    {
        $flags = ConsentManager::flags();
        $raw_dsc = $request->get_param('dataSharingConsent');
        $raw_notif = $request->get_param('inPluginNotifications');
        $new_dsc = $raw_dsc !== null ? ConsentManager::to_bool($raw_dsc) : $flags['dsc'];
        $new_notif = $raw_notif !== null ? ConsentManager::to_bool($raw_notif) : $flags['notif'];
        // DSC=true forces NOTIF=true inside ConsentManager::update(). Surface
        // the coercion in the response so SPA callers can detect "I posted X
        // but the server stored Y" instead of silently dropping the write.
        $requested_notif = $raw_notif !== null ? ConsentManager::to_bool($raw_notif) : null;
        ConsentManager::update($new_dsc, $new_notif, null);
        $response = self::current_state();
        if ($new_dsc && $requested_notif === \false) {
            $response['coerced'] = array('inPluginNotifications');
        }
        return rest_ensure_response($response);
    }
    /**
     * @return array{dataSharingConsent:bool, inPluginNotifications:bool, isPro:bool}
     */
    private static function current_state()
    {
        $flags = ConsentManager::flags();
        return array('dataSharingConsent' => (bool) $flags['dsc'], 'inPluginNotifications' => (bool) $flags['notif'], 'isPro' => ConsentManager::is_pro());
    }
}
