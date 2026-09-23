<?php

/**
 * ConsentManager — single source of truth for the WP.org-compliance consent flags.
 *
 * Two top-level wp_options drive a 3-state notification source switch:
 *   sbc_data_sharing_consent     (DSC)   — opt-in to remote calls
 *   sbc_in_plugin_notifications  (NOTIF) — opt-in to seeing notifications
 *
 * Cron schedule for sbc_notification_update is bound to DSC so that WP.org
 * review never sees a scheduled hook that hits an external URL when the user
 * has not opted in. A no-op-inside-the-callback is NOT enough.
 *
 * This package is shared across all Smash Balloon free plugins. Each plugin
 * calls ::init([config]) from its own bootstrap; all instances read/write
 * the SAME canonical sbc_* options so consent is unified across products.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
class ConsentManager
{
    const OPT_DSC = 'sbc_data_sharing_consent';
    const OPT_NOTIF = 'sbc_in_plugin_notifications';
    const OPT_MODAL_DISMISS = 'sbc_consent_modal_dismissed';
    const CRON_HOOK = 'sbc_notification_update';
    const CRON_RECUR = 'sbcweekly';
    const AJAX_ACTION = 'sbc_save_consent_choice';
    const NONCE_ACTION = 'sbc-consent';
    const NOTICES_GROUP = 'sbc_marketing';
    /**
     * Registered plugin instances keyed by plugin_slug.
     *
     * @var array<string, array{plugin_slug:string, plugin_name:string}>
     */
    private static $instances = array();
    /**
     * Whether the shared hooks (cron, AJAX, footer modal) have been registered.
     * Prevents duplicate registration when multiple plugins call init().
     *
     * @var bool
     */
    private static $hooks_registered = \false;
    /**
     * Per-request cache for is_pro(). Resolved lazily so the filter chain
     * runs once even when flags() / is_dsc_enabled() / is_notif_enabled()
     * all need the default.
     *
     * @var bool|null
     */
    private static $is_pro_cache = null;
    /**
     * Slug of the plugin currently rendering a consent surface (set by
     * render_debug_section() / render_onboarding_section() for the duration of
     * the template include). Lets the shared templates — which call link_url()
     * with no slug — resolve the correct brand without each template having to
     * thread the slug through. Empty outside a render call.
     *
     * @var string
     */
    private static $render_context_slug = '';
    /**
     * Bootstrap: register a plugin with the shared consent package.
     *
     * @param array $config {
     *     @type string        $plugin_slug      Required. e.g. 'instagram-feed'.
     *     @type string        $plugin_name      Required. Human-readable name.
     *     @type bool          $is_pro           Optional. true registers sbc_consent_is_pro.
     *     @type string        $utm_slug         Optional. Campaign slug for link_url() (sbc_consent_plugin_slug).
     *     @type string        $permissions_url  Optional. Overrides the data-sharing permissions link.
     *     @type string        $terms_url        Optional. Overrides the terms link.
     *     @type string        $privacy_url      Optional. Overrides the privacy link.
     *     @type bool|callable  $show_reprompt_modal Optional. Gate for the post-onboarding re-prompt
     *                                            modal. A callable receives the current $show bool.
     *     @type string        $pro_init_option  Optional. When set AND is_pro, force DSC+NOTIF on once,
     *                                            guarded by this plugin-owned option name (so installs
     *                                            that already migrated never re-fire).
     * }
     */
    public static function init(array $config)
    {
        if (empty($config['plugin_slug']) || empty($config['plugin_name'])) {
            return;
        }
        $slug = (string) $config['plugin_slug'];
        // Per-plugin global back-compat class (SBI_Consent, CFF_Consent, …).
        // Historically a single global SBI_Consent shim shipped via composer
        // `files` autoload into EVERY plugin, so all active plugins declared the
        // same global class — a fatal "Cannot declare class SBI_Consent" when
        // more than one feed plugin was active. Each plugin now passes its own
        // legacy_class and we register a uniquely named alias here, lazily
        // (after ABSPATH, inside the plugin's own init) pointing at THIS
        // plugin's ConsentManager. Distinct names per plugin => no cross-plugin
        // collision; the class_exists guard keeps it idempotent.
        if (!empty($config['legacy_class'])) {
            $legacy_class = (string) $config['legacy_class'];
            if (!class_exists($legacy_class, \false)) {
                class_alias(__CLASS__, $legacy_class);
            }
        }
        self::$instances[$slug] = array(
            'plugin_slug' => $slug,
            'plugin_name' => (string) $config['plugin_name'],
            // UTM campaign slug for this plugin's consent links, stored per
            // instance (keyed by plugin_slug). link_url() resolves the brand
            // for the CALLING plugin from here instead of the shared, global
            // sbc_consent_plugin_slug filter — that filter is last-write-wins,
            // so with several feed plugins active every init() overrides it and
            // whichever plugin loads last hijacks every other plugin's links.
            'utm_slug' => !empty($config['utm_slug']) ? (string) $config['utm_slug'] : $slug,
        );
        // Pro identity — MUST be registered before the first is_pro()/flags()
        // read (including the one-time migration below), since is_pro() caches.
        if (!empty($config['is_pro'])) {
            add_filter('sbc_consent_is_pro', '__return_true');
            // A consent read earlier in THIS request has already cached false: the
            // About Us package boots before each plugin's Consent init and its
            // ContentProvider::reconcile_cron() calls is_dsc_enabled(). Drop the
            // cache so the Pro identity registered above is actually honoured.
            self::$is_pro_cache = null;
        }
        // UTM campaign slug used by link_url(); distinct from plugin_slug.
        if (!empty($config['utm_slug'])) {
            $utm_slug = (string) $config['utm_slug'];
            add_filter('sbc_consent_plugin_slug', function () use ($utm_slug) {
                return $utm_slug;
            });
        }
        // Brand link overrides.
        $url_filters = array('permissions_url' => 'sbc_consent_permissions_url', 'terms_url' => 'sbc_consent_terms_url', 'privacy_url' => 'sbc_consent_privacy_url');
        foreach ($url_filters as $config_key => $filter) {
            if (!empty($config[$config_key])) {
                $url = (string) $config[$config_key];
                add_filter($filter, function () use ($url) {
                    return $url;
                });
            }
        }
        // Re-prompt modal gating: bool, or a callable($show) for plugins that
        // gate on their own state (e.g. an active onboarding wizard).
        if (isset($config['show_reprompt_modal'])) {
            $reprompt = $config['show_reprompt_modal'];
            if (is_callable($reprompt)) {
                add_filter('sbc_consent_show_reprompt_modal', $reprompt);
            } else {
                $reprompt = (bool) $reprompt;
                add_filter('sbc_consent_show_reprompt_modal', function () use ($reprompt) {
                    return $reprompt;
                });
            }
        }
        self::register_hooks();
        // Pro no longer PERSISTS a consent value on install. Data-sharing consent
        // is forced ON for Pro at READ time (see flags()/is_dsc_enabled()), and
        // the stored options are never written by Pro presence — so deactivating
        // every Pro plugin reverts consent to the Free user's prior stored choice
        // with no migration to undo. The old one-time pro_init_option write was
        // removed for this reason; the config key is accepted but ignored for
        // back-compat with callers that still pass it.
    }
    /**
     * One-time registration of the shared hooks.
     *
     * Cron schedule, cron callback, AJAX handler, asset enqueue + footer
     * modal render are all framework-owned. Plugins subscribe to flag
     * changes via the public `sbc_consent_changed` action.
     */
    private static function register_hooks()
    {
        if (self::$hooks_registered) {
            return;
        }
        self::$hooks_registered = \true;
        ConsentCron::register();
        ConsentAjax::register();
        ConsentAssets::register();
        ConsentRest::register();
    }
    /**
     * @return array<string,array>  Registered plugin instances.
     */
    public static function get_instances()
    {
        return self::$instances;
    }
    /**
     * @return string[]  Slugs of all registered plugins.
     */
    public static function get_registered_slugs()
    {
        return array_keys(self::$instances);
    }
    /**
     * @return bool  True if at least one consumer plugin has called init().
     */
    public static function has_active_instance()
    {
        return !empty(self::$instances);
    }
    /**
     * Raw consent flags. Used by Settings tab JS-localization seeds and
     * by callers that need both flags at once. Missing-option default is
     * computed lazily via is_pro() so the filter chain doesn't fire for
     * the common stored-value path.
     *
     * @return array{dsc:bool,notif:bool}
     */
    public static function flags()
    {
        // Pro active: both flags are forced ON at READ time. The stored options
        // are deliberately left untouched, so deactivating every Pro plugin
        // reverts consent to the Free user's prior choice automatically — there
        // is no persisted Pro value to undo. See is_locked_by_pro().
        if (self::is_pro()) {
            return array('dsc' => \true, 'notif' => \true);
        }
        // Free: the stored value, defaulting OFF (explicit opt-in required).
        return array('dsc' => (bool) get_option(self::OPT_DSC, \false), 'notif' => (bool) get_option(self::OPT_NOTIF, \false));
    }
    /**
     * @return bool
     */
    public static function is_dsc_enabled()
    {
        // Runtime override: Pro forces ON without persisting. Free reads the
        // stored value, default OFF.
        return self::is_pro() ? \true : (bool) get_option(self::OPT_DSC, \false);
    }
    /**
     * @return bool
     */
    public static function is_notif_enabled()
    {
        // Runtime override: Pro forces ON without persisting. Free reads the
        // stored value, default OFF.
        return self::is_pro() ? \true : (bool) get_option(self::OPT_NOTIF, \false);
    }
    /**
     * Whether the consent flags are currently forced ON by an active Pro plugin.
     *
     * The Free Settings UI uses this to render the data-sharing and in-plugin
     * notification toggles checked-and-disabled with an explanatory notice,
     * since the values are governed centrally while any Pro plugin is active.
     * Equivalent to is_pro(); named for intent at the call site.
     *
     * @return bool
     */
    public static function is_locked_by_pro()
    {
        return self::is_pro();
    }
    /**
     * Whether the post-onboarding re-prompt modal has been dismissed.
     *
     * @return bool
     */
    public static function is_modal_dismissed()
    {
        return (bool) get_option(self::OPT_MODAL_DISMISS, \false);
    }
    /**
     * Current notification source per the consent matrix.
     *
     * @return string  'remote' | 'local' | 'none'
     */
    public static function notification_source()
    {
        $flags = self::flags();
        if (!$flags['notif']) {
            return 'none';
        }
        return $flags['dsc'] ? 'remote' : 'local';
    }
    /**
     * Whether the host is a Pro edition. Filter-based; defaults to false (Free).
     * Pro plugins opt in via add_filter('sbc_consent_is_pro', '__return_true').
     *
     * The result is cached for the lifetime of the request, so the
     * `sbc_consent_is_pro` filter MUST be attached before the first call to
     * is_pro() / flags() / is_dsc_enabled() / is_notif_enabled(). In practice
     * that means hooking the filter at or before `plugins_loaded` priority 10
     * — the same place ConsentManager::init() is typically called from.
     *
     * @return bool
     */
    public static function is_pro()
    {
        if (self::$is_pro_cache !== null) {
            return self::$is_pro_cache;
        }
        self::$is_pro_cache = (bool) apply_filters('sbc_consent_is_pro', \false);
        return self::$is_pro_cache;
    }
    /**
     * Build a UTM-tagged outbound URL for a consent surface.
     *
     * @param string $surface 'onboarding' | 'modal' | 'settings'
     * @param string $type    'permissions' | 'terms' | 'privacy'
     * @param string $slug    Optional. The calling plugin's slug or utm_slug.
     *                        Pass this from multi-plugin contexts (each plugin's
     *                        settings localization) so the campaign reflects the
     *                        caller rather than whichever plugin registered the
     *                        global slug filter last.
     * @return string
     */
    public static function link_url($surface, $type, $slug = '')
    {
        $bases = array('permissions' => apply_filters('sbc_consent_permissions_url', 'https://smashballoon.com/data-sharing-permissions/'), 'terms' => apply_filters('sbc_consent_terms_url', 'https://smashballoon.com/terms-and-conditions/'), 'privacy' => apply_filters('sbc_consent_privacy_url', 'https://smashballoon.com/app-privacy/'));
        $sources = array('onboarding' => 'onboarding-consent', 'modal' => 'consent-modal', 'settings' => 'settings-consent');
        $mediums = array('permissions' => 'docs', 'terms' => 'legal', 'privacy' => 'legal');
        if (!isset($bases[$type])) {
            return '';
        }
        // utm_campaign = {plugin}-{edition} (e.g. facebook-free, facebook-pro).
        // Suffix tracks the running edition via is_pro() so Pro and Free links
        // are attributed separately.
        // Slug source priority (highest first):
        //   1. The explicit $slug argument — the multi-plugin-safe path: each
        //      plugin identifies itself, so its links stay correct no matter
        //      what other active plugins did.
        //   2. The render-context slug set by render_debug_section() /
        //      render_onboarding_section() while a shared template is included.
        //   3. The legacy sbc_consent_plugin_slug filter (single-plugin only;
        //      last-write-wins across plugins, so unreliable when several are
        //      active — kept for back-compat).
        //   4. The first registered instance.
        // The resolved slug is mapped via $instances[*]['utm_slug'] when it
        // names a registered plugin, then to the short brand alias so
        // 'custom-facebook-feed' → 'facebook'.
        $args = array();
        if ($slug === '') {
            $slug = self::$render_context_slug;
        }
        if ($slug === '') {
            $slug = (string) apply_filters('sbc_consent_plugin_slug', '');
        }
        if ($slug === '') {
            $first = reset(self::$instances);
            $slug = is_array($first) ? (string) $first['plugin_slug'] : '';
        }
        // Map a registered plugin_slug to its configured utm_slug.
        if ($slug !== '' && isset(self::$instances[$slug]['utm_slug'])) {
            $slug = (string) self::$instances[$slug]['utm_slug'];
        }
        if ($slug !== '') {
            $args['utm_campaign'] = ConsentNotifications::short_slug($slug) . (self::is_pro() ? '-pro' : '-free');
        }
        if (isset($sources[$surface])) {
            $args['utm_source'] = $sources[$surface];
        }
        if (isset($mediums[$type])) {
            $args['utm_medium'] = $mediums[$type];
        }
        return add_query_arg($args, $bases[$type]);
    }
    /**
     * Coerce a scalar to bool with the same semantics as the REST endpoint:
     * accepts true/false, 1/0, '1'/'0', and the usual truthy/falsy spellings
     * ('true', 'yes', 'on'). Shared by AJAX and REST so on-the-wire booleans
     * are interpreted identically regardless of which surface is talking.
     *
     * @param mixed $v
     * @return bool
     */
    public static function to_bool($v)
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            return in_array($s, array('1', 'true', 'yes', 'on'), \true);
        }
        return (bool) $v;
    }
    /**
     * Render the onboarding consent checkbox section.
     * Defense-in-depth with the template's own Pro guard.
     */
    public static function render_onboarding_section($slug = '')
    {
        if (self::is_pro()) {
            return;
        }
        self::$render_context_slug = (string) $slug;
        include __DIR__ . '/templates/onboarding-consent-checkbox.php';
        self::$render_context_slug = '';
    }
    /**
     * One-line API for consumer plugins to drop the consent debug surface
     * into their Settings tab. Renders for both Free and Pro.
     */
    public static function render_debug_section($slug = '')
    {
        ConsentAssets::enqueue();
        self::$render_context_slug = (string) $slug;
        include __DIR__ . '/templates/debug-tab.php';
        self::$render_context_slug = '';
    }
    /**
     * Single write API used by all consent touchpoints.
     *
     * @param bool      $dsc           Data-sharing consent.
     * @param bool      $notif         In-plugin notifications. Forced on when $dsc is true
     *                                 (one-way coupling matching wpchat: enabling data sharing
     *                                 implies enabling in-plugin notifications).
     * @param bool|null $dismiss_modal When non-null, sets sbc_consent_modal_dismissed.
     *                                 Pass true from onboarding/modal flows so the
     *                                 re-prompt modal does not nag again. Pass null
     *                                 from the Settings page so dismiss state is
     *                                 unaffected.
     */
    public static function update($dsc, $notif, $dismiss_modal = null)
    {
        // While any Pro plugin is active, consent is forced ON at read time and
        // the stored options are intentionally NOT written — otherwise a routine
        // Settings save (which always submits the consent toggles) would persist
        // `true` and the value could not revert to the Free user's prior choice
        // once Pro is removed. Pro exposes no consent toggle, so there is no
        // legitimate write to persist in this state.
        if (self::is_pro()) {
            return;
        }
        $dsc = (bool) $dsc;
        $notif = $dsc ? \true : (bool) $notif;
        update_option(self::OPT_DSC, $dsc ? '1' : '');
        update_option(self::OPT_NOTIF, $notif ? '1' : '');
        if ($dismiss_modal !== null) {
            update_option(self::OPT_MODAL_DISMISS, (bool) $dismiss_modal ? '1' : '');
        }
        // SMASH-1245: the shared notification cron is retired (notifications are
        // owned per-plugin again, fetched on the render path and consent-gated).
        // This call now only ensures the old sbc_notification_update event is
        // never left scheduled when consent changes.
        self::reconcile_cron();
        // Public action: any plugin can subscribe. Payload reflects the FINAL
        // stored state (post-coupling), not the raw arguments — subscribers
        // always see what was actually persisted.
        do_action('sbc_consent_changed', array('dsc' => $dsc, 'notif' => $notif));
    }
    /**
     * Idempotent schedule manager. The remote-fetching cron must be
     * unscheduled whenever DSC is OFF (WP.org review requirement).
     */
    public static function reconcile_cron()
    {
        // SMASH-1245: the shared sbc_notification_update cron has been retired —
        // notifications are owned per-plugin again (each plugin's own notifier
        // fetches on the render path, consent-gated), so the framework no longer
        // schedules a background remote-fetch cron. This method now only
        // GUARANTEES the event is not scheduled, self-healing installs that
        // still carry the old recurring/single events from a previous version.
        if (wp_next_scheduled(self::CRON_HOOK) || wp_next_scheduled(self::CRON_HOOK, array('consent-update'))) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }
}
