<?php

/**
 * Zapier delivery queue (shared engine).
 *
 * Events queue as rows and deliver asynchronously on a WP-Cron single
 * event so webhook delivery never blocks feed updates or page loads.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Delivery_Queue
 *
 * @since 1.1
 */
class Delivery_Queue
{
    /**
     * Maximum delivery attempts per queued event (initial + one retry).
     *
     * @var int
     */
    const MAX_ATTEMPTS = 2;
    /**
     * Default idempotency window in seconds. Repeated dispatches of the same
     * (hook_id, event, idempotency_key) triple inside one window collapse to a
     * single delivery. Filterable per plugin via
     * `{prefix}_zapier_idempotency_window`; a non-positive value disables the
     * guard.
     *
     * @var int
     */
    const IDEMPOTENCY_WINDOW = 300;
    /**
     * Engine config.
     *
     * @var Zapier_Config
     */
    private $config;
    /**
     * Whether immediate (0-delay) processing has already been scheduled this
     * request. dispatch() enqueues once per matching subscription and each
     * enqueue would otherwise re-scan the cron array via wp_next_scheduled();
     * after the first, the rest are redundant. Only the 0-delay path is
     * memoized — the positive-delay retry / backlog reschedules must always run.
     *
     * @var bool
     */
    private $scheduled_this_request = \false;
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
        return $wpdb->prefix . $this->config->prefix . '_zapier_queue';
    }
    /**
     * Full idempotency-ledger table name with prefix.
     *
     * A dedicated table (not a column on the queue) because a queue row is
     * deleted the instant its delivery succeeds, so a queue-scoped guard would
     * lose its dedup memory before a second dispatch of the same event arrives
     * seconds-to-minutes later. The ledger row outlives the queue row.
     *
     * @return string
     */
    public function idem_table()
    {
        global $wpdb;
        return $wpdb->prefix . $this->config->prefix . '_zapier_idem';
    }
    /**
     * Cron hook processing this queue.
     *
     * @return string
     */
    public function cron_hook()
    {
        return $this->config->prefix . '_zapier_process_queue';
    }
    /**
     * Queue one delivery and ensure processing is scheduled.
     *
     * When the caller supplies a stable idempotency key, repeated dispatches of
     * the same (hook_id, event, key) triple within the idempotency window are
     * collapsed to a single delivery: only the first enqueues, the rest are
     * dropped. With no key (the empty-string default) behaviour is unchanged —
     * every call enqueues, exactly as before.
     *
     * @param int    $hook_id         Subscription ID.
     * @param string $event           Event key.
     * @param array  $payload         Payload to POST as JSON.
     * @param string $idempotency_key Optional caller-supplied stable external id
     *                                (e.g. a review id or Instagram post id) used
     *                                to collapse duplicate dispatches of one
     *                                business event. Empty = no dedup (default).
     *
     * @return bool True when a row was enqueued; false when suppressed as a
     *              duplicate or when the queue insert itself failed.
     */
    public function enqueue($hook_id, $event, $payload, $idempotency_key = '')
    {
        global $wpdb;
        // Claim the idempotency slot FIRST so the INSERT IGNORE inside
        // claim_delivery_slot() stays the race arbiter (two concurrent dispatches
        // of the same triple: exactly one wins the slot). The value it returns is
        // a 40-char hash = the ledger row we now own; '' = the guard is disabled
        // or failed open, deliver but nothing to release; false = a prior dispatch
        // already owns this window, suppress.
        $claimed_hash = '';
        if ('' !== (string) $idempotency_key) {
            $claimed_hash = $this->claim_delivery_slot((int) $hook_id, (string) $event, (string) $idempotency_key);
            if (\false === $claimed_hash) {
                return \false;
            }
        }
        $inserted = $wpdb->insert($this->table(), array('hook_id' => (int) $hook_id, 'event' => sanitize_text_field($event), 'payload' => wp_json_encode($payload), 'attempts' => 0, 'created_on' => gmdate('Y-m-d H:i:s')), array('%d', '%s', '%s', '%d', '%s'));
        // A claimed slot must not outlive a failed enqueue. If the queue insert
        // fails after we claimed the ledger slot, the row would suppress every
        // retry of this event for the rest of the window and the event would be
        // silently lost -- the guard failing *closed*, inverting the fail-open
        // posture claim_delivery_slot() documents. Release the slot we just
        // claimed so a retry can re-enqueue, and report failure so dispatch()
        // does not count it as queued. Reordering (queue insert then claim)
        // would instead reopen the concurrent-duplicate race the INSERT IGNORE
        // exists to close, so the claim stays first and we compensate on failure.
        if (\false === $inserted) {
            $this->release_delivery_slot($claimed_hash);
            return \false;
        }
        $this->schedule_processing();
        return \true;
    }
    /**
     * Atomically claim the delivery slot for one (hook_id, event, key) triple
     * within the current idempotency window.
     *
     * The window is quantised into fixed buckets and folded into the hashed key,
     * so a single `INSERT IGNORE` against the ledger's UNIQUE(key_hash) index is
     * the whole guard — the DB arbitrates the race with no read-then-write gap.
     * A return of 1 affected row means this call claimed the slot (first dispatch
     * -> deliver); 0 means a prior dispatch of the same triple already claimed it
     * this window (duplicate -> skip). The ledger row outlives the queue row
     * (deleted the moment its delivery succeeds), so a second dispatch arriving
     * after the first has already been delivered is still suppressed — the
     * property a queue-row guard could not provide.
     *
     * Trade-off: two dispatches that straddle a bucket boundary fall in different
     * buckets and both deliver. The window is short and this edge is rare; for
     * once-only events (new_review, *_deleted) it cannot matter, and for
     * repeated-edit events a missed collapse is a duplicate, never a lost
     * delivery. Fails open — any DB error proceeds with the delivery rather than
     * silently dropping it.
     *
     * @param int    $hook_id Subscription id.
     * @param string $event   Event key.
     * @param string $key     Caller-supplied stable external id.
     *
     * @return string|false The claimed ledger key_hash (a 40-char string) when
     *                      this call won the slot -- release it if the enqueue
     *                      then fails; '' when the guard is disabled or failed
     *                      open (deliver, nothing to release); false when a prior
     *                      dispatch already owns this window (suppress).
     */
    private function claim_delivery_slot($hook_id, $event, $key)
    {
        global $wpdb;
        $window = (int) apply_filters($this->config->prefix . '_zapier_idempotency_window', self::IDEMPOTENCY_WINDOW);
        // A non-positive window disables the guard: always deliver, and there is
        // no ledger row to release afterwards.
        if ($window < 1) {
            return '';
        }
        $bucket = self::idempotency_bucket(time(), $window);
        $key_hash = self::idempotency_key_hash($hook_id, $event, $key, $bucket);
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->idem_table()} (key_hash, created_on) VALUES (%s, %s)",
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $key_hash,
            gmdate('Y-m-d H:i:s')
        ));
        // wpdb::query() returns the affected-row count, or false on error.
        //   1     -> row inserted: we claimed this window's slot. Return the hash
        //            so a failed enqueue can release exactly this row.
        //   0     -> duplicate key: a prior dispatch owns this window -> skip.
        //   false -> query error (e.g. the ledger table is absent): fail open,
        //            deliver rather than silently dropping a real event. No row
        //            was written, so there is nothing to release ('').
        if (1 === $result) {
            return $key_hash;
        }
        return 0 === $result ? \false : '';
    }
    /**
     * Release a delivery slot claimed by claim_delivery_slot() when the enqueue
     * it guarded did not go through, so a claimed-but-not-queued event is not
     * suppressed for the rest of its window. Deletes exactly the ledger row we
     * inserted, matched on its unique key_hash, so it can never race a different
     * dispatch's claim. A no-op for the '' sentinel (nothing was claimed).
     *
     * @param string $key_hash The ledger key_hash returned by claim_delivery_slot().
     *
     * @return void
     */
    private function release_delivery_slot($key_hash)
    {
        global $wpdb;
        if ('' === (string) $key_hash) {
            return;
        }
        $wpdb->delete($this->idem_table(), array('key_hash' => (string) $key_hash), array('%s'));
    }
    /**
     * Fixed time bucket for the idempotency window. Two timestamps in the same
     * window (modulo a straddled boundary) share a bucket. Pure/static so the
     * bucketing can be unit-tested without a DB.
     *
     * @param int $time   Unix timestamp.
     * @param int $window Window length in seconds.
     *
     * @return int
     */
    public static function idempotency_bucket($time, $window)
    {
        $window = (int) $window;
        return $window > 0 ? (int) floor((int) $time / $window) : 0;
    }
    /**
     * Stable hash of an idempotency triple plus its time bucket. This is the
     * value stored in the ledger's UNIQUE(key_hash) index. Pure/static so the
     * key derivation can be unit-tested without a DB.
     *
     * @param int    $hook_id Subscription id.
     * @param string $event   Event key.
     * @param string $key     Caller-supplied stable external id.
     * @param int    $bucket  Time bucket from idempotency_bucket().
     *
     * @return string 40-char SHA-1 hex.
     */
    public static function idempotency_key_hash($hook_id, $event, $key, $bucket)
    {
        return sha1((int) $hook_id . '|' . (string) $event . '|' . (string) $key . '|' . (int) $bucket);
    }
    /**
     * Schedule queue processing if not already scheduled.
     *
     * @param int $delay Seconds from now.
     *
     * @return void
     */
    public function schedule_processing($delay = 0)
    {
        $delay = (int) $delay;
        if (0 === $delay && $this->scheduled_this_request) {
            return;
        }
        if (!wp_next_scheduled($this->cron_hook())) {
            wp_schedule_single_event(time() + $delay, $this->cron_hook());
            if (0 === $delay && function_exists('spawn_cron')) {
                spawn_cron();
            }
        }
        if (0 === $delay) {
            $this->scheduled_this_request = \true;
        }
    }
    /**
     * Book the retry run for failed deliveries.
     *
     * Booked under its own args so it never occupies the immediate slot that
     * schedule_processing() checks: a pending retry used to make enqueue()
     * skip scheduling, holding every new event on the site until the retry
     * time (wp_schedule_single_event() also refuses a same-args event within
     * 10 minutes, so the args must differ).
     *
     * @param int $delay Seconds from now.
     *
     * @return void
     */
    public function schedule_retry($delay)
    {
        if (!wp_next_scheduled($this->cron_hook(), array('retry'))) {
            wp_schedule_single_event(time() + (int) $delay, $this->cron_hook(), array('retry'));
        }
    }
    /**
     * Get a batch of queued deliveries, oldest first.
     *
     * @param int $limit Batch size.
     *
     * @return array
     */
    public function claim_batch($limit = 20, $include_retries = \true)
    {
        global $wpdb;
        $table = $this->table();
        // The immediate run only takes fresh rows, so a failed delivery keeps its
        // retry delay and cannot slow down healthy Zaps queued behind it.
        $where = $include_retries ? '' : 'WHERE attempts = 0 ';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} {$where}ORDER BY id ASC LIMIT %d", $limit),
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            \ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }
    /**
     * Remove a queued delivery.
     *
     * @param int $id Queue row ID.
     *
     * @return void
     */
    public function delete($id)
    {
        global $wpdb;
        $wpdb->delete($this->table(), array('id' => (int) $id), array('%d'));
    }
    /**
     * Remove all queued deliveries.
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
     * Increment attempts for a queue row.
     *
     * @param int $id Queue row ID.
     *
     * @return void
     */
    public function bump_attempts($id)
    {
        global $wpdb;
        $table = $this->table();
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", $id));
    }
    /**
     * Delete queued deliveries older than a retention window.
     *
     * Queue rows are pending deliveries — a row is removed on success or on
     * exhausted retries — so anything older than the window is a delivery
     * that will never go out (broken cron, deactivated plugin) and whose
     * payload may carry PII. Filterable per plugin via
     * `{prefix}_zapier_queue_retention_days`.
     *
     * @param int $days Retention window in days.
     *
     * @return void
     */
    public function prune_older_than($days = 7)
    {
        global $wpdb;
        $days = (int) apply_filters($this->config->prefix . '_zapier_queue_retention_days', (int) $days);
        if ($days < 1) {
            return;
        }
        $table = $this->table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * \DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_on < %s", $cutoff));
    }
    /**
     * Delete idempotency-ledger rows older than a short retention window.
     *
     * A ledger row only needs to outlive its own window (plus a margin for a
     * straddled bucket) — once older it can never suppress a live duplicate, so
     * it is dead weight. Retention is two windows or an hour, whichever is
     * larger, so a custom (larger) window is always covered. Runs alongside the
     * queue prune on every processing batch.
     *
     * @return void
     */
    public function prune_idempotency()
    {
        global $wpdb;
        $window = (int) apply_filters($this->config->prefix . '_zapier_idempotency_window', self::IDEMPOTENCY_WINDOW);
        $retain = max(\HOUR_IN_SECONDS, ($window > 0 ? $window : self::IDEMPOTENCY_WINDOW) * 2);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retain);
        $table = $this->idem_table();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_on < %s", $cutoff));
    }
}
