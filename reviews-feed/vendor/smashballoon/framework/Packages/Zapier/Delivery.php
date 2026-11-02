<?php

/**
 * Zapier webhook delivery (shared engine).
 *
 * Implements the Zapier REST hook delivery contract: 2xx means
 * received, 410 Gone means the Zap was turned off and the
 * subscription must be pruned. Other failures retry once.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Delivery
 *
 * @since 1.1
 */
class Delivery
{
    /**
     * Seconds before a failed delivery is retried.
     *
     * @var int
     */
    const RETRY_DELAY = 300;
    /**
     * Seconds after which a held processing lock is considered abandoned.
     *
     * Comfortably above the worst-case batch time (20 deliveries x 15s
     * timeout would be 300s, but real batches finish in well under that).
     *
     * @var int
     */
    const LOCK_TIMEOUT = 600;
    /**
     * Engine config.
     *
     * @var Zapier_Config
     */
    private $config;
    /**
     * Subscription store.
     *
     * @var Subscription_Store
     */
    private $subscriptions;
    /**
     * Delivery queue.
     *
     * @var Delivery_Queue
     */
    private $queue;
    /**
     * The token written into the lock row for the run currently holding it,
     * so release only deletes a lock this run actually owns.
     *
     * @var string|null
     */
    private $lock_token = null;
    /**
     * Constructor.
     *
     * @param Zapier_Config      $config        Engine config.
     * @param Subscription_Store $subscriptions Subscription store.
     * @param Delivery_Queue     $queue         Delivery queue.
     */
    public function __construct(Zapier_Config $config, Subscription_Store $subscriptions, Delivery_Queue $queue)
    {
        $this->config = $config;
        $this->subscriptions = $subscriptions;
        $this->queue = $queue;
    }
    /**
     * Process a batch of queued deliveries.
     *
     * @param string $mode 'retry' for the retry run (see Delivery_Queue::schedule_retry()),
     *                     empty for the immediate run.
     *
     * @return void
     */
    public function process_queue($mode = '')
    {
        // claim_batch() only SELECTs, so two overlapping runs would deliver the
        // same rows twice (observed: enqueue() spawns cron, which can race a run
        // already in progress). Serialize processing instead.
        if (!$this->acquire_lock()) {
            return;
        }
        try {
            $this->process_batch('retry' === $mode);
        } finally {
            $this->release_lock();
        }
    }
    /**
     * Option name for the processing lock. Derived from the queue table so
     * each consuming plugin locks independently.
     *
     * @return string
     */
    private function lock_name()
    {
        return $this->queue->table() . '_lock';
    }
    /**
     * Take the processing lock.
     *
     * add_option() is NOT usable as a mutex: WordPress writes it as
     * `INSERT ... ON DUPLICATE KEY UPDATE`, which succeeds even when the row
     * exists, and its only guard is a cache-backed read-then-write that this
     * method's own get_option() call defeats. So acquire with a direct
     * `INSERT IGNORE` against the unique `option_name` index instead — a
     * genuinely atomic primitive that stores the row (1 affected) when the
     * lock is free and returns 0 affected — with no duplicate-key error — when
     * a lock row already exists. (A plain INSERT would raise MySQL error 1062
     * on the routine contention path, which wpdb::print_error() writes to the
     * PHP error log unconditionally; IGNORE keeps the mutex without the flood.)
     * The stored value is `token|timestamp`; a lock older than LOCK_TIMEOUT is
     * taken over as abandoned (crashed or fatal'd run), and release() only
     * removes a row still carrying this run's token.
     *
     * A genuine DB error on the acquire is distinguished from routine contention
     * (which IGNORE turns into a silent 0-affected-rows result) via last_error
     * and logged once, so a persistently stalled queue is not invisible.
     *
     * @return bool Whether the lock was acquired.
     */
    private function acquire_lock()
    {
        global $wpdb;
        $name = $this->lock_name();
        // Stale-lock takeover. Legacy locks stored a bare timestamp, which
        // lock_time() reads as 0 and therefore always treats as abandoned.
        // Delete only the exact stale row observed (option_value match), never
        // unconditionally: two runs that both read the same stale value must
        // not both clear it and then both acquire. Only the run whose DELETE
        // matches the observed value removes the row; the INSERT IGNORE below
        // still lets exactly one racer win the freed slot.
        $held = get_option($name);
        if (\false !== $held && time() - $this->lock_time($held) > self::LOCK_TIMEOUT) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held));
            $this->flush_option_cache($name);
        }
        $token = wp_generate_uuid4() . '|' . time();
        // Atomic mutex on the unique option_name key: INSERT IGNORE stores the
        // row and returns 1 when the lock is free, and returns 0 — with no
        // duplicate-key error, incumbent's row untouched — when a lock row
        // already exists. This is the mutual exclusion add_option() lacks,
        // without the error-log flood a plain INSERT raises on contention.
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $token));
        if (1 !== $inserted) {
            // INSERT IGNORE gives three outcomes here:
            //   1     -> row stored, lock acquired (handled below).
            //   0     -> a lock row already exists: routine contention. Stay
            //            silent -- suppressing this duplicate-key noise is the
            //            whole point of IGNORE (a plain INSERT would flood the
            //            log via wpdb::print_error()).
            //   false -> a genuine DB error (IGNORE never turns a duplicate key
            //            into this). Failing closed is right for a mutex, but a
            //            persistent error would stall the queue indefinitely and
            //            invisibly, so surface it once. Gated on last_error so a
            //            contention 0 is never logged.
            if (\false === $inserted && '' !== $wpdb->last_error) {
                // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.DevelopmentFunctions.error_log_error_log -- genuine DB error on the lock write, not routine contention.
                error_log(sprintf('%s zapier: delivery lock acquire failed (DB error): %s', $this->config->prefix, $wpdb->last_error));
            }
            return \false;
        }
        // The direct INSERT IGNORE bypassed the options cache; drop the stale
        // entries so subsequent get_option()/release() see the row we wrote.
        $this->flush_option_cache($name);
        $this->lock_token = $token;
        return \true;
    }
    /**
     * Release the processing lock, but only if the row still carries this
     * run's token — a run that overran LOCK_TIMEOUT and was taken over must
     * not delete its successor's lock.
     *
     * @return void
     */
    private function release_lock()
    {
        global $wpdb;
        if (null === $this->lock_token) {
            return;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $this->lock_name(), $this->lock_token));
        $this->flush_option_cache($this->lock_name());
        $this->lock_token = null;
    }
    /**
     * Timestamp embedded in a stored lock value (`token|timestamp`). Legacy
     * bare-integer values have no delimiter and read as 0 (always stale).
     *
     * @param string $value Stored lock value.
     *
     * @return int
     */
    private function lock_time($value)
    {
        $parts = explode('|', (string) $value);
        return isset($parts[1]) ? (int) $parts[1] : 0;
    }
    /**
     * Drop the options caches for a name written/deleted via raw SQL, so
     * get_option() stays coherent with the row (the direct writes bypass it).
     *
     * The lock row is stored autoload='no', so it is never part of the
     * `alloptions` bucket — flushing that site-wide key would evict every
     * autoloaded option (a cache-stampede risk under a persistent object cache)
     * for no benefit. Only this option's own cache entry and its `notoptions`
     * marker need clearing, and the marker is unset key-wise (core's own
     * pattern) rather than by discarding the whole map.
     *
     * @param string $name Option name.
     *
     * @return void
     */
    private function flush_option_cache($name)
    {
        wp_cache_delete($name, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
    }
    /**
     * Deliver one batch. Always called with the processing lock held.
     *
     * @param bool $retry_run Whether this is the retry run, which also takes
     *                        rows that already failed once.
     *
     * @return void
     */
    private function process_batch($retry_run = \false)
    {
        // Age out queued rows that will never deliver (broken cron, dead
        // endpoint) so their PII-bearing payloads are not retained forever.
        $this->queue->prune_older_than();
        // Age out spent idempotency-ledger rows (past their window, so they can
        // no longer suppress a live duplicate).
        $this->queue->prune_idempotency();
        $batch_size = 20;
        $batch = $this->queue->claim_batch($batch_size, $retry_run);
        if (empty($batch)) {
            return;
        }
        $needs_retry_run = \false;
        foreach ($batch as $row) {
            $subscription = $this->subscriptions->get((int) $row['hook_id']);
            // Disabled rows only reach the queue as the hourly recovery probe
            // (Subscription_Store::get_for_event()), so deliver those too.
            if (!$subscription || !in_array($subscription['status'], array('active', 'disabled'), \true)) {
                $this->queue->delete((int) $row['id']);
                continue;
            }
            $response = wp_safe_remote_post($subscription['target_url'], array('timeout' => 15, 'redirection' => 0, 'headers' => array('Content-Type' => 'application/json', 'X-SB-Event' => $row['event']), 'body' => $row['payload']));
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $this->queue->delete((int) $row['id']);
                $this->subscriptions->reset_failures((int) $subscription['id']);
                continue;
            }
            if ($code === 410) {
                // Zapier contract: the Zap was turned off. Prune the subscription.
                $this->queue->delete((int) $row['id']);
                $this->subscriptions->delete((int) $subscription['id']);
                continue;
            }
            if ((int) $row['attempts'] + 1 >= Delivery_Queue::MAX_ATTEMPTS) {
                $this->queue->delete((int) $row['id']);
                $this->subscriptions->increment_failure((int) $subscription['id']);
            } else {
                $this->queue->bump_attempts((int) $row['id']);
                $needs_retry_run = \true;
            }
        }
        if (count($batch) >= $batch_size) {
            // A full batch was claimed - more rows may be waiting. Run
            // again right away so backlogs are never stranded.
            if ($retry_run) {
                $this->queue->schedule_retry(5);
            } else {
                $this->queue->schedule_processing(5);
            }
        }
        if ($needs_retry_run) {
            $this->queue->schedule_retry(self::RETRY_DELAY);
        }
    }
}
