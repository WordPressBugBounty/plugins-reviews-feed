<?php

/**
 * Zapier REST endpoints (shared engine).
 *
 * Registers the full Zapier route set for a plugin from its config:
 *
 *   GET    {ns}/zapier/me            Auth test.
 *   GET    {ns}/zapier/{collection}  Polling endpoints from config->collections
 *                                    (dynamic dropdowns + performList samples).
 *   POST   {ns}/zapier/hooks         Subscribe (Zapier performSubscribe).
 *   DELETE {ns}/zapier/hooks/{id}    Unsubscribe (Zapier performUnsubscribe).
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

use WP_Error;
use WP_REST_Response;
use WP_REST_Server;
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Rest_Controller
 *
 * Internationalisation note: the user-facing error strings below are passed
 * through __() with a *variable* text domain (Zapier_Config::$text_domain),
 * flagged `phpcs:ignore WordPress.WP.I18n`. This is deliberate. The engine is a
 * shared package vendored into each consumer plugin, and each consumer supplies
 * its own already-loaded text domain through the config (Instagram Feed Pro
 * passes 'instagram-feed', Reviews Feed passes 'reviews-feed'), so these strings
 * are translated by the plugin that ships them rather than by the package. A
 * literal 'sb-common' domain was rejected because no consumer loads such a
 * domain, so it would change runtime behaviour -- turning strings the consumer
 * *can* translate today into ones that always fall back to English. The
 * trade-off accepted here is that string extraction (wp i18n make-pot) cannot
 * resolve a non-literal domain, so a consumer that wants these translated must
 * fold the package into its own .pot scan (or add the entries), the same way it
 * already scans the rest of its vendored code. The settings-surface strings take
 * the other path -- a literal 'sb-common' plus a per-consumer i18n override
 * array -- because that surface has an override channel these REST responses do
 * not.
 *
 * @since 1.1
 */
class Rest_Controller
{
    /**
     * Failed auth attempts allowed per IP per window.
     *
     * @var int
     */
    const AUTH_FAILURE_LIMIT = 10;
    /**
     * Failed auth window in seconds.
     *
     * @var int
     */
    const AUTH_FAILURE_WINDOW = 600;
    /**
     * Engine service.
     *
     * @var Zapier_Service
     */
    private $service;
    /**
     * Constructor.
     *
     * @param Zapier_Service $service Engine service.
     */
    public function __construct(Zapier_Service $service)
    {
        $this->service = $service;
    }
    /**
     * Hook route registration.
     *
     * @return void
     */
    public function init()
    {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    /**
     * Register all Zapier routes for this plugin.
     *
     * @return void
     */
    public function register_routes()
    {
        $ns = $this->service->config->rest_namespace;
        $permission = array($this, 'permission_check');
        register_rest_route($ns, '/zapier/me', array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_me'), 'permission_callback' => $permission, 'args' => array()));
        foreach (array_keys($this->service->config->collections) as $slug) {
            // Slugs are code-supplied, but a regex metacharacter would be
            // interpolated straight into the route pattern below — skip any
            // slug outside a safe shape rather than register a surprising
            // (or route-shadowing) pattern.
            if (!is_string($slug) || !preg_match('/^[a-z0-9_-]+$/', $slug)) {
                continue;
            }
            register_rest_route($ns, '/zapier/' . $slug, array('methods' => WP_REST_Server::READABLE, 'callback' => array($this, 'get_collection'), 'permission_callback' => $permission, 'args' => $this->collection_args()));
        }
        register_rest_route($ns, '/zapier/hooks', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'subscribe'),
            'permission_callback' => $permission,
            // Type + sanitize only. The url/event *semantic* checks
            // (allow-list, known-event) live in subscribe(), not here: WP
            // runs arg validate_callbacks BEFORE the permission callback, so
            // a validator would answer unauthenticated callers and would
            // change the public error codes (WP wraps a per-arg WP_Error as
            // `rest_invalid_param`). Keeping them in the handler preserves
            // both the post-auth boundary and the documented error codes.
            'args' => array('url' => array('type' => 'string', 'sanitize_callback' => 'esc_url_raw'), 'event' => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field')),
        ));
        register_rest_route($ns, '/zapier/hooks/(?P<id>\d+)', array('methods' => WP_REST_Server::DELETABLE, 'callback' => array($this, 'unsubscribe'), 'permission_callback' => $permission, 'args' => array('id' => array('type' => 'integer', 'required' => \true, 'sanitize_callback' => 'absint', 'validate_callback' => array($this, 'validate_numeric')))));
    }
    /**
     * Declarative arg schema shared by every polling collection route. The
     * engine only coerces a conventional limit/per_page through absint; the
     * plugin's collection callable stays responsible for its own final bound.
     *
     * No validate_callback: absint already coerces any input (empty string,
     * non-numeric) to a safe non-negative integer, so a bare `?limit=` keeps
     * coercing to the callable's default rather than hard-failing 400 — the
     * behaviour before this arg schema existed.
     *
     * @return array
     */
    private function collection_args()
    {
        return array('limit' => array('type' => 'integer', 'required' => \false, 'sanitize_callback' => 'absint'), 'per_page' => array('type' => 'integer', 'required' => \false, 'sanitize_callback' => 'absint'));
    }
    /**
     * Validate that a param is numeric (used for the absint'd integer id arg
     * on unsubscribe). Kept a validator because the DELETE route embeds id as
     * `(?P<id>\d+)`, so a non-numeric value never reaches it; this is a
     * belt-and-braces check with no reachable rejection path.
     *
     * @param mixed $value Raw param value.
     *
     * @return bool
     */
    public function validate_numeric($value)
    {
        return is_numeric($value);
    }
    /**
     * Route permission callback: feature gate + API key + rate limit.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return true|WP_Error
     */
    public function permission_check($request)
    {
        $prefix = $this->service->config->prefix;
        if (!$this->service->config->is_available()) {
            return new WP_Error(
                $prefix . '_zapier_not_available',
                __('The Zapier integration is not available on this plan.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 403)
            );
        }
        if (!$this->service->is_enabled()) {
            return new WP_Error(
                $prefix . '_zapier_disabled',
                __('The Zapier integration is switched off on this site. Turn it on in the plugin settings to reconnect.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 403)
            );
        }
        // Keyed on REMOTE_ADDR only — never X-Forwarded-For, which is
        // client-controlled and would hand an attacker unlimited counters.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $transient = $prefix . '_zapier_auth_fail_' . md5($ip);
        // Verify the key FIRST. verify() only reads an option and runs a
        // constant-time hash compare (no writes), so a valid key is never
        // rate-limited. This matters on the common reverse-proxy install
        // where REMOTE_ADDR is the proxy's address and every caller shares
        // one counter: counting before verifying would let ten anonymous
        // bad-key requests lock the real integration out for the whole window.
        $key = $this->service->api_key->from_request($request);
        if ($this->service->api_key->verify($key)) {
            delete_transient($transient);
            return \true;
        }
        // The limiter is defence-in-depth against credential-stuffing noise,
        // not brute force — the key is 256 bits of CSPRNG entropy. Only failed
        // attempts are counted; once a caller is already over the limit we
        // short-circuit without writing the transient again.
        $failures = (int) get_transient($transient);
        if ($failures >= self::AUTH_FAILURE_LIMIT) {
            return new WP_Error(
                $prefix . '_zapier_too_many_attempts',
                __('Too many failed authentication attempts. Try again later.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 429)
            );
        }
        set_transient($transient, $failures + 1, self::AUTH_FAILURE_WINDOW);
        return new WP_Error(
            $prefix . '_zapier_invalid_key',
            __('Invalid API key.', $this->service->config->text_domain),
            // phpcs:ignore WordPress.WP.I18n
            array('status' => 401)
        );
    }
    /**
     * GET /zapier/me — auth test.
     *
     * @return WP_REST_Response
     */
    public function get_me()
    {
        return new WP_REST_Response(array('site' => get_bloginfo('name'), 'url' => home_url(), 'plugin' => $this->service->config->plugin_slug, 'version' => $this->service->config->plugin_version), 200);
    }
    /**
     * GET /zapier/{collection} — polling data from the config callable.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_collection($request)
    {
        $route = $request->get_route();
        $slug = substr($route, strrpos($route, '/') + 1);
        $collections = $this->service->config->collections;
        if (!isset($collections[$slug]) || !is_callable($collections[$slug])) {
            return new WP_Error(
                $this->service->config->prefix . '_zapier_unknown_collection',
                __('Unknown collection.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 404)
            );
        }
        $items = call_user_func($collections[$slug], $request);
        return new WP_REST_Response(is_array($items) ? array_values($items) : array(), 200);
    }
    /**
     * POST /zapier/hooks — Zapier performSubscribe.
     *
     * Body: {url, event, ...filters}. url (Zapier hook allow-list) and event
     * (known-event list) are validated here in the handler — after the
     * permission callback has run — so the public error codes
     * ({prefix}_zapier_invalid_url / _invalid_event, both 400) are unchanged
     * and every error path stays behind authentication. Unknown scalar body
     * keys become bounded subscription filters (see collect_filters()).
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function subscribe($request)
    {
        $prefix = $this->service->config->prefix;
        $url = esc_url_raw((string) $request->get_param('url'));
        $event = sanitize_text_field((string) $request->get_param('event'));
        // Semantic validation, post-auth. Runs before find_active_duplicate()
        // and insert() so neither is reached with an unvalidated event or URL.
        if (empty($url) || !$this->service->subscriptions->is_allowed_target_url($url)) {
            return new WP_Error(
                $prefix . '_zapier_invalid_url',
                __('A valid Zapier hook URL is required.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 400)
            );
        }
        if (!in_array($event, $this->service->config->events, \true)) {
            return new WP_Error(
                $prefix . '_zapier_invalid_event',
                __('Unknown trigger event.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 400)
            );
        }
        $filters = $this->collect_filters($request);
        $this->service->maybe_create_tables();
        // Idempotent: an identical active subscription (same event, target
        // and filters) returns its existing id with 200 instead of inserting
        // a second row. This survives Zapier's subscribe retries and stops a
        // replayed POST from growing the table.
        $existing = $this->service->subscriptions->find_active_duplicate($event, $url, $filters);
        if ($existing) {
            return new WP_REST_Response(array('id' => $existing, 'url' => $url, 'event' => $event), 200);
        }
        // Bound the table. Without a ceiling a key holder can turn every new
        // item into a large SELECT + N decodes + N inserts on the site's own
        // feed-refresh path, which on many installs runs on a visitor page load.
        if ($this->service->subscriptions->count_active() >= Subscription_Store::MAX_ACTIVE) {
            return new WP_Error(
                $prefix . '_zapier_subscription_limit',
                __('The maximum number of Zapier subscriptions for this site has been reached.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 409)
            );
        }
        $id = $this->service->subscriptions->insert($event, $url, $filters);
        if (!$id) {
            return new WP_Error(
                $prefix . '_zapier_subscribe_failed',
                __('Could not store the subscription.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 500)
            );
        }
        return new WP_REST_Response(array('id' => $id, 'url' => $url, 'event' => $event), 201);
    }
    /**
     * Build the bounded subscription filter map from unrecognised request
     * params. At most Subscription_Store::MAX_FILTERS entries; each key is
     * sanitize_key'd and skipped if longer than MAX_FILTER_KEY_LENGTH; each
     * value is sanitize_text_field'd and truncated to MAX_FILTER_VALUE_LENGTH.
     * When the plugin config declares filter_keys, only those keys are kept.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return array
     */
    private function collect_filters($request)
    {
        $filters = array();
        $allowed = (array) $this->service->config->filter_keys;
        $params = $request->get_params();
        foreach ($params as $key => $value) {
            if (in_array($key, array('url', 'event', 'id'), \true) || !is_scalar($value)) {
                continue;
            }
            $key = sanitize_key($key);
            if ($key === '' || strlen($key) > Subscription_Store::MAX_FILTER_KEY_LENGTH) {
                continue;
            }
            if (!empty($allowed) && !in_array($key, $allowed, \true)) {
                continue;
            }
            $value = sanitize_text_field((string) $value);
            if (function_exists('mb_substr')) {
                $value = mb_substr($value, 0, Subscription_Store::MAX_FILTER_VALUE_LENGTH);
            } else {
                $value = substr($value, 0, Subscription_Store::MAX_FILTER_VALUE_LENGTH);
            }
            $filters[$key] = $value;
            if (count($filters) >= Subscription_Store::MAX_FILTERS) {
                break;
            }
        }
        return $filters;
    }
    /**
     * DELETE /zapier/hooks/{id} — Zapier performUnsubscribe.
     *
     * @param \WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function unsubscribe($request)
    {
        $id = absint($request->get_param('id'));
        if (!$this->service->subscriptions->get($id)) {
            return new WP_Error(
                $this->service->config->prefix . '_zapier_not_found',
                __('Subscription not found.', $this->service->config->text_domain),
                // phpcs:ignore WordPress.WP.I18n
                array('status' => 404)
            );
        }
        $this->service->subscriptions->delete($id);
        return new WP_REST_Response(null, 204);
    }
}
