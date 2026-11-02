<?php

/**
 * Zapier event dispatcher (shared engine).
 *
 * A thin helper a plugin's event layer composes so its handlers stay pure
 * payload shaping. It plumbs the stable external id through to the engine as
 * the idempotency key, collapses the per-request duplicates a feed can trigger
 * when one refresh fires the underlying hook once per cache record, and owns
 * the timestamp normalisation every payload builder repeats.
 *
 * The dispatch mechanics are shared; the payload *shape* stays in the plugin
 * (Instagram fields vs review fields), so this class deliberately holds no
 * plugin business logic.
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Event_Dispatcher
 *
 * @since 1.1
 */
class Event_Dispatcher
{
    /**
     * Shared engine service events dispatch through.
     *
     * @var Zapier_Service
     */
    private $service;
    /**
     * Per-request dispatch-once guard, keyed by "{event}|{external_id}".
     *
     * @var array
     */
    private $dispatched = array();
    /**
     * Constructor.
     *
     * @param Zapier_Service $service Shared engine service.
     */
    public function __construct(Zapier_Service $service)
    {
        $this->service = $service;
    }
    /**
     * Dispatch an event through the shared engine, passing the stable external
     * id as the idempotency key so a duplicate dispatch of the same business
     * event never delivers twice.
     *
     * @param string $event           Event key.
     * @param array  $payload         Payload delivered to the Zap.
     * @param array  $context         Match context for subscription filters.
     * @param string $idempotency_key Stable external id (review id, post id,
     *                                feed id...). Empty = no cross-request dedup.
     *
     * @return int Number of deliveries queued.
     */
    public function dispatch($event, array $payload, array $context = array(), $idempotency_key = '')
    {
        return $this->service->dispatch($event, $payload, $context, null, (string) $idempotency_key);
    }
    /**
     * Dispatch an event at most once per request for a given external id, on
     * top of the engine's cross-request idempotency. Returns whether it
     * dispatched (false when this (event, id) already went out this request).
     *
     * The external id doubles as the idempotency key, so a refresh that fires
     * its hook once per cache record collapses both in-request (here) and
     * across the retry window (engine).
     *
     * @param string $event       Event key.
     * @param string $external_id Stable external id, also the idempotency key.
     * @param array  $payload     Payload delivered to the Zap.
     * @param array  $context     Match context for subscription filters.
     *
     * @return bool True when the event was dispatched this call.
     */
    public function dispatch_once($event, $external_id, array $payload, array $context = array())
    {
        $external_id = (string) $external_id;
        $memo = $event . '|' . $external_id;
        if (isset($this->dispatched[$memo])) {
            return \false;
        }
        $this->dispatched[$memo] = \true;
        $this->dispatch($event, $payload, $context, $external_id);
        return \true;
    }
    /**
     * Normalise a MySQL datetime (or any strtotime-parseable string) to
     * ISO-8601, or '' when empty or unparseable. The one timestamp conversion
     * every payload builder repeats.
     *
     * @param string $when Source datetime.
     *
     * @return string
     */
    public static function iso8601($when)
    {
        $when = (string) $when;
        if ('' === $when) {
            return '';
        }
        $time = strtotime(str_replace(' ', 'T', $when));
        return \false !== $time ? gmdate('c', $time) : '';
    }
    /**
     * The current time as an ISO-8601 event-occurrence timestamp.
     *
     * @return string
     */
    public static function now()
    {
        return gmdate('c');
    }
    /**
     * The delivering site URL every payload stamps.
     *
     * @return string
     */
    public static function site_url()
    {
        return home_url();
    }
}
