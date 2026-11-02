<?php

/**
 * Zapier engine configuration.
 *
 * A plugin adapting the shared Zapier engine provides one instance of
 * this config describing its identity, events and data providers.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Zapier_Config
 *
 * @since 1.1
 */
class Zapier_Config
{
    /**
     * Short plugin prefix, e.g. 'sbi' or 'sbr'. Drives option names
     * ({prefix}_zapier_api_key), table names ({prefix}_zapier_hooks,
     * {prefix}_zapier_queue), cron hook ({prefix}_zapier_process_queue)
     * and filter names ({prefix}_zapier_enabled, ...).
     *
     * Lower-cased and constrained to [a-z0-9_] by from_array(); must not
     * sanitise to an empty string.
     *
     * @var string
     */
    public $prefix;
    /**
     * REST namespace, e.g. 'sbi/v1'.
     *
     * @var string
     */
    public $rest_namespace;
    /**
     * Plugin slug reported by the auth-test endpoint.
     *
     * @var string
     */
    public $plugin_slug;
    /**
     * Plugin version reported by the auth-test endpoint.
     *
     * @var string
     */
    public $plugin_version;
    /**
     * Trigger event keys subscriptions may be created for.
     *
     * @var array
     */
    public $events = array();
    /**
     * Callable returning whether the integration is available
     * (license tier gate). Signature: fn(): bool.
     *
     * @var callable
     */
    public $availability_callback;
    /**
     * Whether the plugin shows the site owner an on/off switch for the
     * integration (see Zapier_Service::is_enabled()). Off by default so a
     * consumer without the switch keeps the integration always on.
     *
     * @var bool
     */
    public $toggle = \false;
    /**
     * Polling data providers keyed by collection slug. Each callable
     * receives (\WP_REST_Request $request) and returns an array of
     * items shaped exactly like the matching webhook payload.
     * Registered as GET {rest_namespace}/zapier/{slug}.
     *
     * @var array
     */
    public $collections = array();
    /**
     * Optional whitelist of filter keys a subscription may carry.
     *
     * When empty (the default) any sanitized scalar body param is accepted as
     * a subscription filter; when set, only these keys are kept and the rest
     * are dropped. Lets an adopter lock the subscribe surface down to its
     * known filterable fields. Kept generic — the engine imposes no plugin
     * semantics on the values.
     *
     * @var array
     */
    public $filter_keys = array();
    /**
     * Text domain the engine's REST error strings are translated under. The
     * consumer plugin passes its own already-loaded domain here so the shared
     * package's messages are translated by the plugin that ships them rather than
     * by the package (see the i18n note on Rest_Controller for why the domain is
     * intentionally variable). Defaults to 'default' (WP core's domain) when a
     * consumer does not set it.
     *
     * @var string
     */
    public $text_domain = 'default';
    /**
     * Build a config from an associative array.
     *
     * @param array $args Config values keyed by property name.
     *
     * @return Zapier_Config
     *
     * @throws \InvalidArgumentException When the prefix sanitises to an empty
     *                                   string (e.g. it contained no [a-z0-9_]
     *                                   characters once lower-cased).
     */
    public static function from_array($args)
    {
        $config = new self();
        foreach ($args as $key => $value) {
            if (property_exists($config, $key)) {
                $config->{$key} = $value;
            }
        }
        // The prefix is interpolated into table names and into the CREATE
        // TABLE statements, so constrain it to a safe shape before it can ever
        // reach SQL. Mirrors Zapier_Settings_Surface::config()'s own
        // sanitisation of the same value. Lower-case first so an upper-case
        // prefix (e.g. 'SBI') survives the [a-z0-9_] strip instead of being
        // silently reduced to an empty string.
        $sanitized_prefix = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $config->prefix));
        // An empty prefix yields ambiguous, colliding option and table names
        // (_zapier_api_key, wp__zapier_queue), so fail loudly at construction
        // rather than let a mis-typed or wrong-cased config through silently.
        if ('' === $sanitized_prefix) {
            throw new \InvalidArgumentException(sprintf('Zapier_Config requires a non-empty prefix of [a-z0-9_] characters; received "%s", which sanitises to an empty string.', (string) $config->prefix));
        }
        $config->prefix = $sanitized_prefix;
        return $config;
    }
    /**
     * Whether the integration is currently available.
     *
     * Filterable per plugin as {prefix}_zapier_enabled.
     *
     * @return bool
     */
    public function is_available()
    {
        $available = is_callable($this->availability_callback) ? (bool) call_user_func($this->availability_callback) : \false;
        return (bool) apply_filters($this->prefix . '_zapier_enabled', $available);
    }
}
