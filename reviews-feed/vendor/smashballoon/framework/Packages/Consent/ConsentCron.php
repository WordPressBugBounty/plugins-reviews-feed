<?php

/**
 * ConsentCron — registers the shared sbcweekly schedule and the
 * sbc_notification_update cron callback.
 *
 * The callback is gated on DSC=on; if it fires while DSC is off (race
 * condition between option flip and unschedule), it is a no-op.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
class ConsentCron
{
    /**
     * @var bool
     */
    private static $registered = \false;
    /**
     * Idempotent registration. Safe to call from multiple plugin bootstraps.
     *
     * SMASH-1245: the shared sbc_notification_update cron has been RETIRED.
     * Notifications are owned per-plugin again — each plugin's own
     * SBI_/CFF_/CTF_/SBY_/SBR_/SBTT_ notifier fetches the feed on the render
     * path (admin page load), consent-gated, with no background cron. This
     * package therefore no longer schedules or services a cron callback; it
     * only self-heals installs that still carry the old schedule by clearing
     * the orphaned event on load.
     */
    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = \true;
        if (wp_next_scheduled(ConsentManager::CRON_HOOK) || wp_next_scheduled(ConsentManager::CRON_HOOK, array('consent-update'))) {
            wp_clear_scheduled_hook(ConsentManager::CRON_HOOK);
        }
    }
    /**
     * Add the sbcweekly schedule (1 week interval).
     *
     * @param array $schedules
     * @return array
     */
    public static function add_schedule($schedules)
    {
        if (!isset($schedules[ConsentManager::CRON_RECUR])) {
            $schedules[ConsentManager::CRON_RECUR] = array('interval' => \WEEK_IN_SECONDS, 'display' => __('Once Weekly (SBC)', 'sb-common'));
        }
        return $schedules;
    }
    /**
     * Cron callback. Clears stale marketing-group notices in every plugin's
     * SBNotices store. Each plugin fetches and caches its own feed.
     */
    public static function on_cron($source = '')
    {
        if (!ConsentManager::is_dsc_enabled()) {
            // Safety net: if the cron somehow fires while DSC is off,
            // do nothing (no remote calls). reconcile_cron() should
            // already have unscheduled, but races happen.
            return;
        }
        ConsentNotifications::refresh_notifications();
    }
}
