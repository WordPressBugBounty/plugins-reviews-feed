<?php

/**
 * Zapier subscription storage (shared engine).
 *
 * One row per active Zap, created by Zapier's performSubscribe.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Subscription_Store
 *
 * @since 1.1
 */
class Subscription_Store
{
    /**
     * Consecutive delivery failures before a subscription is disabled.
     *
     * @var int
     */
    const MAX_FAILURES = 5;
    /**
     * Seconds between recovery probes of a disabled subscription. A disabled
     * Zap gets at most one real event per interval; a 2xx re-activates it, so
     * a short Zapier outage does not kill a customer's Zap for good.
     *
     * @var int
     */
    const PROBE_INTERVAL = \HOUR_IN_SECONDS;
    /**
     * Maximum active subscriptions per site (across all events). Bounds the
     * table so a key holder cannot amplify the site's own feed-refresh path.
     *
     * @var int
     */
    const MAX_ACTIVE = 50;
    /**
     * Maximum filter entries stored on one subscription.
     *
     * @var int
     */
    const MAX_FILTERS = 10;
    /**
     * Maximum length of a single filter key.
     *
     * @var int
     */
    const MAX_FILTER_KEY_LENGTH = 64;
    /**
     * Maximum length of a single filter value.
     *
     * @var int
     */
    const MAX_FILTER_VALUE_LENGTH = 255;
    /**
     * Engine config.
     *
     * @var Zapier_Config
     */
    private $config;
    /**
     * Constructor.
     *
     * @param Zapier_Config $config Engine config.
     */
    public function __construct(Zapier_Config $config)
    {
        $this->config = $config;
    }
    /**
     * Full table name with prefix.
     *
     * @return string
     */
    public function table()
    {
        global $wpdb;
        return $wpdb->prefix . $this->config->prefix . '_zapier_hooks';
    }
    /**
     * Full queue table name with prefix. Mirrors Delivery_Queue::table() so
     * delete() can purge a hook's queued deliveries without a queue instance.
     *
     * @return string
     */
    private function queue_table()
    {
        global $wpdb;
        return $wpdb->prefix . $this->config->prefix . '_zapier_queue';
    }
    /**
     * Whether a target URL may be subscribed (Zapier hook URLs only,
     * preventing SSRF via the delivery mechanism).
     *
     * @param string $url Target URL.
     *
     * @return bool
     */
    public function is_allowed_target_url($url)
    {
        $valid = (bool) preg_match('#^https://hooks\.zapier\.com/#', (string) $url);
        return (bool) apply_filters($this->config->prefix . '_zapier_allowed_target_url', $valid, $url);
    }
    /**
     * Create a subscription.
     *
     * @param string $event   Event key (must be in config events).
     * @param string $url     Zapier hook URL.
     * @param array  $filters Optional event filters (feed_id, provider, min_rating...).
     *
     * @return int|false
     */
    public function insert($event, $url, $filters = array())
    {
        global $wpdb;
        // Validate the exact value that gets persisted (defence in depth):
        // escape the URL first, then run the SSRF allow-list on that escaped
        // string, so the check can never pass on one value while a different
        // value is written to the row.
        $target_url = esc_url_raw((string) $url);
        if (!in_array($event, $this->config->events, \true) || !$this->is_allowed_target_url($target_url)) {
            return \false;
        }
        $inserted = $wpdb->insert($this->table(), array('event' => $event, 'target_url' => $target_url, 'filters' => wp_json_encode(array_filter($filters)), 'status' => 'active', 'failure_count' => 0, 'created_on' => gmdate('Y-m-d H:i:s')), array('%s', '%s', '%s', '%s', '%d', '%s'));
        return $inserted ? (int) $wpdb->insert_id : \false;
    }
    /**
     * Get one subscription with decoded filters.
     *
     * @param int $id Subscription ID.
     *
     * @return array|null
     */
    public function get($id)
    {
        global $wpdb;
        $table = $this->table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            \ARRAY_A
        );
        return $row ? $this->decode($row) : null;
    }
    /**
     * All subscriptions.
     *
     * @return array
     */
    public function get_all()
    {
        global $wpdb;
        $table = $this->table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", \ARRAY_A);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_map(array($this, 'decode'), is_array($rows) ? $rows : array());
    }
    /**
     * Number of active subscriptions across all events.
     *
     * @return int
     */
    public function count_active()
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'active'");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    /**
     * Find an active subscription identical to the given (event, url,
     * filters) triple so a replayed subscribe is idempotent rather than
     * inserting a duplicate row.
     *
     * @param string $event   Event key.
     * @param string $url     Zapier hook URL.
     * @param array  $filters Filters.
     *
     * @return int Existing subscription id, or 0 when none matches.
     */
    public function find_active_duplicate($event, $url, $filters = array())
    {
        $target = esc_url_raw($url);
        $canonical = $this->canonical_filters($filters);
        foreach ($this->get_for_event($event) as $row) {
            if ((string) $row['target_url'] === $target && $this->canonical_filters($row['filters']) === $canonical) {
                return (int) $row['id'];
            }
        }
        return 0;
    }
    /**
     * Active subscriptions for an event.
     *
     * Filter matching against the event payload happens in the
     * dispatcher, since filter semantics are plugin-specific.
     *
     * @param string $event            Event key.
     * @param bool   $include_disabled Also return disabled rows (dispatch
     *                                 uses them for recovery probes).
     *
     * @return array
     */
    public function get_for_event($event, $include_disabled = \false)
    {
        global $wpdb;
        $table = $this->table();
        $statuses = $include_disabled ? "'active', 'disabled'" : "'active'";
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE event = %s AND status IN ({$statuses})", $event),
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            \ARRAY_A
        );
        return array_map(array($this, 'decode'), is_array($rows) ? $rows : array());
    }
    /**
     * Claim this interval's recovery probe for a disabled subscription.
     *
     * @param int $id Subscription ID.
     *
     * @return bool True at most once per PROBE_INTERVAL per subscription.
     */
    public function claim_probe($id)
    {
        $probe = $this->config->prefix . '_zapier_probe_' . (int) $id;
        if (\false !== get_transient($probe)) {
            return \false;
        }
        set_transient($probe, 1, self::PROBE_INTERVAL);
        return \true;
    }
    /**
     * Delete a subscription.
     *
     * @param int $id Subscription ID.
     *
     * @return bool
     */
    public function delete($id)
    {
        global $wpdb;
        // Also drop this hook's queued deliveries so an unsubscribe (or a 410
        // prune) never orphans PII-bearing payload rows in the queue.
        $wpdb->delete($this->queue_table(), array('hook_id' => (int) $id), array('%d'));
        return (bool) $wpdb->delete($this->table(), array('id' => (int) $id), array('%d'));
    }
    /**
     * Delete all subscriptions (key revoked).
     *
     * @return void
     */
    public function delete_all()
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query("DELETE FROM {$table}");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    /**
     * Record a delivery failure, disabling after MAX_FAILURES.
     *
     * @param int $id Subscription ID.
     *
     * @return void
     */
    public function increment_failure($id)
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET failure_count = failure_count + 1 WHERE id = %d", $id));
        $row = $this->get($id);
        // Only the active -> disabled transition fires the hook; a failed
        // recovery probe leaves an already-disabled row as it is.
        if ($row && 'active' === $row['status'] && (int) $row['failure_count'] >= self::MAX_FAILURES) {
            $wpdb->update($table, array('status' => 'disabled'), array('id' => (int) $id), array('%s'), array('%d'));
            do_action($this->config->prefix . '_zapier_subscription_disabled', (int) $id, $row);
        }
    }
    /**
     * Reset the failure count after a successful delivery, re-activating a
     * disabled subscription whose recovery probe got through.
     *
     * @param int $id Subscription ID.
     *
     * @return void
     */
    public function reset_failures($id)
    {
        global $wpdb;
        $wpdb->update($this->table(), array('failure_count' => 0, 'status' => 'active'), array('id' => (int) $id), array('%d', '%s'), array('%d'));
    }
    /**
     * Number of disabled subscriptions, for the settings warning.
     *
     * @return int
     */
    public function count_disabled()
    {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'disabled'");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
    /**
     * Decode the filters column.
     *
     * @param array $row Raw row.
     *
     * @return array
     */
    private function decode($row)
    {
        $filters = array();
        if (!empty($row['filters'])) {
            $decoded = json_decode($row['filters'], \true);
            if (is_array($decoded)) {
                $filters = $decoded;
            }
        }
        $row['filters'] = $filters;
        return $row;
    }
    /**
     * Stable, order-independent encoding of a filter map, for comparing two
     * subscriptions regardless of the order their filters were stored in.
     *
     * @param array $filters Filters.
     *
     * @return string
     */
    private function canonical_filters($filters)
    {
        $filters = array_filter((array) $filters);
        ksort($filters);
        return (string) wp_json_encode($filters);
    }
}
