<?php

/**
 * Zapier admin AJAX (shared engine).
 *
 * Registers the API-key management endpoints the plugin settings UI
 * consumes:
 *
 *   wp_ajax_{prefix}_zapier_generate_key  -> {key, display} (key shown once)
 *   wp_ajax_{prefix}_zapier_revoke_key    -> revokes key + wipes subscriptions/queue
 *   wp_ajax_{prefix}_zapier_get_status    -> {available, key, subscriptions, events}
 *   wp_ajax_{prefix}_zapier_set_enabled   -> {enabled} (site owner's on/off switch)
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Admin_Ajax
 *
 * @since 1.1
 */
class Admin_Ajax
{
    /**
     * Engine service.
     *
     * @var Zapier_Service
     */
    private $service;
    /**
     * Nonce action verified on every request (e.g. 'sbi-admin').
     *
     * @var string
     */
    private $nonce_action;
    /**
     * Constructor.
     *
     * @param Zapier_Service $service      Engine service.
     * @param string         $nonce_action Admin nonce action for this plugin.
     */
    public function __construct(Zapier_Service $service, $nonce_action)
    {
        $this->service = $service;
        $this->nonce_action = $nonce_action;
    }
    /**
     * Register the AJAX handlers.
     *
     * @return void
     */
    public function init()
    {
        $prefix = $this->service->config->prefix;
        add_action('wp_ajax_' . $prefix . '_zapier_generate_key', array($this, 'generate_key'));
        add_action('wp_ajax_' . $prefix . '_zapier_revoke_key', array($this, 'revoke_key'));
        add_action('wp_ajax_' . $prefix . '_zapier_get_status', array($this, 'get_status'));
        add_action('wp_ajax_' . $prefix . '_zapier_set_enabled', array($this, 'set_enabled'));
    }
    /**
     * Capability required to manage the integration.
     *
     * @return string
     */
    private function capability()
    {
        return apply_filters($this->service->config->prefix . '_zapier_capability', 'manage_options');
    }
    /**
     * Verify nonce and capability or die.
     *
     * @return void
     */
    private function guard()
    {
        check_ajax_referer($this->nonce_action, 'nonce');
        if (!current_user_can($this->capability())) {
            wp_send_json_error(array('message' => 'Not allowed.'), 403);
        }
    }
    /**
     * Refuse a write action on installs where the integration is not
     * available, so a manage_options user on a non-Elite site cannot generate
     * an API key through the raw AJAX action even though the UI hides the
     * control. Applied to key generation only — get_status is a read the UI
     * needs to render the upsell, and revoke stays reachable so a downgraded
     * site can still clean up an existing key.
     *
     * Scope note: this guards the *key option* only. The engine tables are
     * created separately by Zapier_Service::maybe_create_tables(), which is
     * hooked on admin_init ungated by availability, so an empty pair of tables
     * can exist on a non-Elite site. That is harmless: without a key no
     * subscribe or delivery is reachable, so the tables carry no data and
     * confer no capability.
     *
     * @return void
     */
    private function require_available()
    {
        if (!$this->service->config->is_available()) {
            wp_send_json_error(array('message' => 'The Zapier integration is not available on this plan.'), 403);
        }
    }
    /**
     * Remove every subscription and any queued deliveries.
     *
     * Shared by revoke_key() and by generate_key() when a key already
     * existed. Rotating or revoking the key must invalidate the Zaps
     * created with it: delivered webhooks carry no credential (Delivery
     * POSTs on the strength of the subscription row alone), so a
     * subscription left live after a rotation keeps firing to its target
     * forever — a leaked key that outlives the rotation the admin
     * believed contained it.
     *
     * @return void
     */
    private function teardown_subscriptions()
    {
        $this->service->subscriptions->delete_all();
        $this->service->queue->delete_all();
    }
    /**
     * Generate (or replace) the API key. Plain key returned once.
     *
     * @return void
     */
    public function generate_key()
    {
        $this->guard();
        $this->require_available();
        $this->service->maybe_create_tables();
        // Rotating an existing key must tear down the subscriptions created
        // under it — see teardown_subscriptions(). This makes the behaviour
        // match the regenerate confirm dialog ("the current key will stop
        // working and any Zaps using it will need reconnecting").
        if ($this->service->api_key->exists()) {
            $this->teardown_subscriptions();
        }
        $plain = $this->service->api_key->generate();
        wp_send_json_success(array('key' => $plain, 'display' => $this->service->api_key->get_display_data()));
    }
    /**
     * Revoke the key and remove all subscriptions and queued deliveries.
     *
     * @return void
     */
    public function revoke_key()
    {
        $this->guard();
        $this->service->api_key->revoke();
        $this->teardown_subscriptions();
        wp_send_json_success();
    }
    /**
     * Save the on/off switch. Turning it off keeps the key and subscriptions,
     * so turning it back on resumes the existing Zaps.
     *
     * @return void
     */
    public function set_enabled()
    {
        $this->guard();
        $this->require_available();
        $enabled = !empty($_POST['enabled']) && '0' !== $_POST['enabled'];
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $this->service->set_enabled($enabled);
        wp_send_json_success(array('enabled' => $enabled));
    }
    /**
     * Current integration status for the settings UI.
     *
     * @return void
     */
    public function get_status()
    {
        $this->guard();
        wp_send_json_success(array('available' => $this->service->config->is_available(), 'key' => $this->service->api_key->get_display_data(), 'subscriptions' => $this->service->subscriptions->get_all(), 'events' => $this->service->config->events));
    }
}
