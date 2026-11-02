<?php

/**
 * Zapier integration bootstrap (shared engine).
 *
 * The identical skeleton every consuming plugin used to hand-write around the
 * engine: build the service from a config, wire cron + tables, register the
 * REST controller and (in admin) the settings AJAX, then attach the plugin's
 * own event listeners — but only when a delivery is actually possible.
 *
 * A plugin composes one of these rather than subclassing it, because the two
 * first adopters have incompatible base classes (Reviews Feed's integration
 * extends a ServiceProvider; Instagram Feed's is a plain static class). A
 * helper object sidesteps PHP single inheritance and stays trivially
 * unit-testable.
 *
 * Usage in a plugin's integration class:
 *
 *   $this->zapier = Integration_Bootstrap::from_config([
 *       'prefix' => 'sbi', 'rest_namespace' => 'sbi/v1', 'events' => [...],
 *       'availability_callback' => [__CLASS__, 'check_license'],
 *       'collections' => ['feeds' => [$providers, 'feeds']],
 *   ]);
 *   $this->zapier->boot(function ($service) {
 *       // Attach event listeners here — runs only when licensed AND keyed.
 *       add_action('sbi_new_post_stored', ...);
 *   });
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Integration_Bootstrap
 *
 * @since 1.1
 */
class Integration_Bootstrap
{
    /**
     * Shared engine service this integration is built around.
     *
     * @var Zapier_Service
     */
    private $service;
    /**
     * Admin nonce action verified by the settings AJAX (e.g. 'sbi-admin').
     * Defaults to '{prefix}-admin', the convention both first adopters follow.
     *
     * @var string
     */
    private $nonce_action;
    /**
     * Memoized can_dispatch() result for this request. Null until first
     * computed, so the license gate and API-key existence read happen at most
     * once per request no matter how many events fire — the single cheap early
     * bail. Cleared implicitly by a fresh instance on the next request.
     *
     * @var bool|null
     */
    private $can_dispatch = null;
    /**
     * Constructor.
     *
     * @param Zapier_Service $service      Shared engine service.
     * @param string         $nonce_action Admin nonce action for the settings
     *                                      AJAX. Empty falls back to
     *                                      '{prefix}-admin'.
     */
    public function __construct(Zapier_Service $service, $nonce_action = '')
    {
        $this->service = $service;
        $this->nonce_action = '' !== (string) $nonce_action ? (string) $nonce_action : $service->config->prefix . '-admin';
    }
    /**
     * Build a bootstrap from a config array (or a ready Zapier_Config), so a
     * plugin never has to touch Zapier_Service directly.
     *
     * @param array|Zapier_Config $config       Engine config.
     * @param string              $nonce_action Optional admin nonce action.
     *
     * @return Integration_Bootstrap
     */
    public static function from_config($config, $nonce_action = '')
    {
        $resolved = $config instanceof Zapier_Config ? $config : Zapier_Config::from_array((array) $config);
        return new self(new Zapier_Service($resolved), $nonce_action);
    }
    /**
     * The shared engine service, for a plugin's event listeners to dispatch
     * through and for its settings tab to read key metadata from.
     *
     * @return Zapier_Service
     */
    public function service()
    {
        return $this->service;
    }
    /**
     * Boot the integration.
     *
     * Always wires the request-agnostic surface — cron + table creation, the
     * REST controller (its permission callback reports a clear error on
     * unlicensed sites, so routes register regardless), and the settings AJAX
     * in admin. Then, only when the integration can actually dispatch
     * (licensed AND an API key exists), hands the service to the caller's
     * listener-wiring callable.
     *
     * Gating listener attachment on can_dispatch() is what keeps a site that
     * has not set Zapier up from doing any per-event work: with no key the
     * listeners never attach, so there is nothing to bail out of. A plugin
     * that needs an availability-only hook can wire it itself off
     * is_available().
     *
     * @param callable|null $attach_listeners Wiring callback
     *                                        fn(Zapier_Service $service): void,
     *                                        called once when the integration
     *                                        can dispatch.
     *
     * @return $this
     */
    public function boot($attach_listeners = null)
    {
        $this->service->init();
        // Routes register even when switched off: permission_check() answers
        // with a {prefix}_zapier_disabled 403, which the Zapier app turns into
        // a readable message instead of a bare rest_no_route 404.
        (new Rest_Controller($this->service))->init();
        if (is_admin()) {
            (new Admin_Ajax($this->service, $this->nonce_action))->init();
        }
        if ($this->can_dispatch() && is_callable($attach_listeners)) {
            call_user_func($attach_listeners, $this->service);
        }
        return $this;
    }
    /**
     * Whether the integration is available (license tier gate + filter).
     *
     * @return bool
     */
    public function is_available()
    {
        return $this->service->config->is_available();
    }
    /**
     * Whether an API key has been generated.
     *
     * @return bool
     */
    public function api_key_exists()
    {
        return $this->service->api_key->exists();
    }
    /**
     * Whether event listeners may dispatch right now: the integration is
     * available AND an API key exists. Memoized per request so the six-or-so
     * handlers a plugin wires do not each re-run the license check and re-read
     * the key option — the one guard the pre-engine adapters repeated inline.
     *
     * @return bool
     */
    public function can_dispatch()
    {
        if (null === $this->can_dispatch) {
            $this->can_dispatch = $this->service->is_enabled() && $this->is_available() && $this->api_key_exists();
        }
        return $this->can_dispatch;
    }
    /**
     * Non-secret API key display metadata (last4 + created_on), or null when
     * no key has been generated. Bridges the settings tab to the engine's
     * Api_Key_Manager.
     *
     * @return array|null
     */
    public function key_display()
    {
        return $this->service->api_key->get_display_data();
    }
    /**
     * Remove every trace of the engine for a plugin, from its uninstall path.
     *
     * The names of everything the engine created derive solely from the prefix,
     * so a minimal prefix-only config is enough here — an uninstall request
     * loads neither the plugin's main file nor its constants, so building the
     * full boot config would reference undefined values.
     *
     * @param string $prefix The plugin's engine prefix (e.g. 'sbi', 'sbr').
     *
     * @return void
     */
    public static function uninstall($prefix)
    {
        (new Zapier_Service(Zapier_Config::from_array(array('prefix' => $prefix))))->uninstall();
    }
}
