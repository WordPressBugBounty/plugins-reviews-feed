<?php

/**
 * Zapier engine orchestrator (shared engine).
 *
 * A plugin boots the engine with a Zapier_Config; the engine wires
 * table creation, queue processing and exposes dispatch() for the
 * plugin's event listeners.
 *
 * Usage in a plugin:
 *
 *   $service = new Zapier_Service(Zapier_Config::from_array([...]));
 *   $service->init();
 *   add_action('sbi_new_post_stored', function ($post, $feed_ids) use ($service) {
 *       $service->dispatch('new_post', $payload, ['feed_id' => $feed_ids[0]]);
 *   }, 10, 2);
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Zapier_Service
 *
 * @since 1.1
 */
class Zapier_Service
{
    /**
     * Zapier tables schema version.
     *
     * 1.1 (SMASH-1965) adds the idempotency-ledger table
     * ({prefix}_zapier_idem) that backs duplicate-delivery collapse. dbDelta
     * creates the new table on an existing install when the stored version
     * lags this constant.
     *
     * @var string
     */
    const DB_VERSION = '1.1';
    /**
     * Engine config.
     *
     * @var Zapier_Config
     */
    public $config;
    /**
     * API key manager.
     *
     * @var Api_Key_Manager
     */
    public $api_key;
    /**
     * Subscription store.
     *
     * @var Subscription_Store
     */
    public $subscriptions;
    /**
     * Delivery queue.
     *
     * @var Delivery_Queue
     */
    public $queue;
    /**
     * Delivery processor.
     *
     * @var Delivery
     */
    public $delivery;
    /**
     * Whether the schema has been confirmed current for this request. Set once
     * maybe_create_tables() has either matched the stored version or created
     * the tables, so repeated dispatches in one request do not each re-read the
     * non-autoloaded {prefix}_zapier_db_version option (a DB hit). A fresh
     * instance on the next request re-checks.
     *
     * @var bool
     */
    private $schema_ready = \false;
    /**
     * Constructor.
     *
     * @param Zapier_Config $config Engine config.
     */
    public function __construct(Zapier_Config $config)
    {
        $this->config = $config;
        $this->api_key = new Api_Key_Manager($config);
        $this->subscriptions = new Subscription_Store($config);
        $this->queue = new Delivery_Queue($config);
        $this->delivery = new Delivery($config, $this->subscriptions, $this->queue);
    }
    /**
     * Wire cron processing and table creation.
     *
     * @return void
     */
    public function init()
    {
        // Switched off: already-queued deliveries are held, not sent.
        if ($this->is_enabled()) {
            add_action($this->queue->cron_hook(), array($this->delivery, 'process_queue'));
        }
        if (is_admin()) {
            add_action('admin_init', array($this, 'maybe_create_tables'));
        }
    }
    /**
     * Queue deliveries for all active subscriptions matching an event.
     *
     * @param string        $event   Event key.
     * @param array         $payload Payload to deliver.
     * @param array         $context Event context matched against subscription
     *                               filters (e.g. feed_id, provider, rating).
     * @param callable|null $matcher Optional custom matcher fn($filters, $context): bool.
     *                               Default: every non-empty subscription filter
     *                               must equal the matching context value, except
     *                               'min_rating' which is a >= comparison on
     *                               $context['rating'].
     * @param string        $idempotency_key Optional caller-supplied stable
     *                               external id for the business event (e.g. a
     *                               review id or Instagram post id). When set,
     *                               repeated dispatches of the same
     *                               (subscription, event, key) triple within the
     *                               idempotency window collapse to a single
     *                               delivery per subscription, so one business
     *                               event can never deliver twice to the same
     *                               Zap. Empty (default) preserves the prior
     *                               behaviour exactly — every dispatch enqueues.
     *
     * @return int Number of deliveries queued (duplicates suppressed by an
     *             idempotency key are not counted).
     */
    public function dispatch($event, $payload, $context = array(), $matcher = null, $idempotency_key = '')
    {
        if (!$this->is_enabled()) {
            return 0;
        }
        // Ensure the schema is current before a (possibly idempotency-keyed)
        // dispatch. On a 1.0 -> 1.1 upgrade the ledger table is created on
        // admin_init, so a front-end dispatch can fire before any admin has
        // loaded wp-admin; the keyed INSERT IGNORE would then hit a missing table
        // and fail open, leaving the new dedup guard silently off. This is a
        // single option read when the stored version already matches (dbDelta
        // only runs when it lags), so it is cheap on the hot path and closes the
        // post-upgrade window on the dispatch/enqueue path.
        $this->maybe_create_tables();
        $queued = 0;
        foreach ($this->subscriptions->get_for_event($event, \true) as $subscription) {
            $matches = is_callable($matcher) ? (bool) call_user_func($matcher, $subscription['filters'], $context) : $this->default_matcher($subscription['filters'], $context);
            if (!$matches) {
                continue;
            }
            // A disabled Zap gets one real event per probe interval; a 2xx
            // re-activates it (Subscription_Store::reset_failures()).
            if ('disabled' === $subscription['status'] && !$this->subscriptions->claim_probe((int) $subscription['id'])) {
                continue;
            }
            if ($this->queue->enqueue((int) $subscription['id'], $event, $payload, $idempotency_key)) {
                $queued++;
            }
        }
        return $queued;
    }
    /**
     * Default subscription filter matching.
     *
     * @param array $filters Subscription filters.
     * @param array $context Event context.
     *
     * @return bool
     */
    private function default_matcher($filters, $context)
    {
        foreach ((array) $filters as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if ($key === 'min_rating') {
                if (isset($context['rating']) && (int) $context['rating'] < (int) $value) {
                    return \false;
                }
                continue;
            }
            if (isset($context[$key]) && (string) $context[$key] !== (string) $value) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Create or update the engine tables when needed.
     *
     * @return void
     */
    public function maybe_create_tables()
    {
        // Already confirmed current this request: skip the option read entirely.
        // dispatch() calls this on every event (to self-heal the 1.0 -> 1.1
        // ledger table), so under a fan-out that would otherwise be N reads of a
        // non-autoloaded option.
        if ($this->schema_ready) {
            return;
        }
        $option = $this->config->prefix . '_zapier_db_version';
        if (get_option($option) === self::DB_VERSION) {
            $this->schema_ready = \true;
            return;
        }
        global $wpdb;
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $hooks_table = $this->subscriptions->table();
        $queue_table = $this->queue->table();
        $idem_table = $this->queue->idem_table();
        dbDelta("CREATE TABLE {$hooks_table} (\n\t\t\t\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\t\t\t\tevent varchar(50) NOT NULL default '',\n\t\t\t\ttarget_url text NOT NULL,\n\t\t\t\tfilters longtext NOT NULL,\n\t\t\t\tstatus varchar(20) NOT NULL default 'active',\n\t\t\t\tfailure_count smallint unsigned NOT NULL default 0,\n\t\t\t\tcreated_on datetime NOT NULL,\n\t\t\t\tPRIMARY KEY  (id),\n\t\t\t\tKEY event (event),\n\t\t\t\tKEY status (status)\n\t\t\t) {$charset_collate};");
        dbDelta("CREATE TABLE {$queue_table} (\n\t\t\t\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\t\t\t\thook_id bigint(20) unsigned NOT NULL,\n\t\t\t\tevent varchar(50) NOT NULL default '',\n\t\t\t\tpayload longtext NOT NULL,\n\t\t\t\tattempts smallint unsigned NOT NULL default 0,\n\t\t\t\tcreated_on datetime NOT NULL,\n\t\t\t\tPRIMARY KEY  (id),\n\t\t\t\tKEY hook_id (hook_id)\n\t\t\t) {$charset_collate};");
        // Idempotency ledger: one row per claimed (hook_id, event, key, window
        // bucket), hashed into key_hash. The UNIQUE(key_hash) index is what makes
        // the INSERT IGNORE in Delivery_Queue::claim_delivery_slot() a race-safe
        // duplicate-collapse guard. dbDelta creates this on an existing install
        // when DB_VERSION lags (1.0 -> 1.1).
        dbDelta("CREATE TABLE {$idem_table} (\n\t\t\t\tid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n\t\t\t\tkey_hash char(40) NOT NULL default '',\n\t\t\t\tcreated_on datetime NOT NULL,\n\t\t\t\tPRIMARY KEY  (id),\n\t\t\t\tUNIQUE KEY key_hash (key_hash)\n\t\t\t) {$charset_collate};");
        // Record the schema version only once the tables actually exist. dbDelta()
        // reports no error on a failed CREATE (insufficient GRANTs, disk full),
        // so recording unconditionally would mark the upgrade done while the
        // ledger is absent -- permanently disabling idempotency with no retry.
        // Verifying first lets the next dispatch / admin_init attempt the create
        // again.
        if ($this->tables_exist(array($hooks_table, $queue_table, $idem_table))) {
            update_option($option, self::DB_VERSION, \false);
            $this->schema_ready = \true;
        }
    }
    /**
     * Whether every named table currently exists. Gates recording the schema
     * version so a silently-failed dbDelta() is retried rather than masked.
     *
     * @param string[] $tables Fully-prefixed table names.
     *
     * @return bool
     */
    private function tables_exist(array $tables)
    {
        global $wpdb;
        foreach ($tables as $table) {
            // esc_like() so the table name's own underscores are matched
            // literally, not as LIKE single-char wildcards.
            $like = $wpdb->esc_like($table);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            if ($found !== $table) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Option holding the site owner's on/off switch ('1' / '0').
     *
     * @return string
     */
    public function enabled_option()
    {
        return $this->config->prefix . '_zapier_active';
    }
    /**
     * Whether the site owner has switched the integration on. Plugins without
     * a switch (config `toggle` false) are always on. With a switch it is off
     * until the site owner turns it on.
     *
     * When off: no webhooks dispatch, queued deliveries are held and the REST
     * routes are not registered.
     *
     * @return bool
     */
    public function is_enabled()
    {
        if (!$this->config->toggle) {
            return \true;
        }
        return '1' === (string) get_option($this->enabled_option(), '0');
    }
    /**
     * Save the site owner's on/off switch.
     *
     * @param bool $enabled New state.
     *
     * @return void
     */
    public function set_enabled($enabled)
    {
        update_option($this->enabled_option(), $enabled ? '1' : '0', \false);
    }
    /**
     * Remove everything the engine created.
     *
     * Drops both tables, deletes the API-key / db-version / lock options and
     * the per-IP auth-failure transients, and unschedules the delivery cron.
     * A consumer plugin calls this from its own uninstall path (register_
     * uninstall_hook or uninstall.php), typically gated on the plugin's
     * "delete all data on uninstall" preference.
     *
     * @return void
     */
    public function uninstall()
    {
        global $wpdb;
        wp_clear_scheduled_hook($this->queue->cron_hook());
        wp_clear_scheduled_hook($this->queue->cron_hook(), array('retry'));
        $hooks_table = $this->subscriptions->table();
        $queue_table = $this->queue->table();
        $idem_table = $this->queue->idem_table();
        $wpdb->query("DROP TABLE IF EXISTS {$hooks_table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$queue_table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$idem_table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        delete_option($this->api_key->option_name());
        delete_option($this->config->prefix . '_zapier_db_version');
        delete_option($this->enabled_option());
        delete_option($this->queue->table() . '_lock');
        $this->delete_auth_transients();
    }
    /**
     * Best-effort removal of the per-IP auth-failure transients. Their name
     * carries a hashed-IP suffix, so they are matched by prefix in the options
     * table (an external object cache stores and expires them itself, out of
     * reach here — acceptable, they are short-lived).
     *
     * @return void
     */
    private function delete_auth_transients()
    {
        global $wpdb;
        $name = $this->config->prefix . '_zapier_auth_fail_';
        $value_like = $wpdb->esc_like('_transient_' . $name) . '%';
        $timeout_like = $wpdb->esc_like('_transient_timeout_' . $name) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $value_like, $timeout_like));
    }
}
