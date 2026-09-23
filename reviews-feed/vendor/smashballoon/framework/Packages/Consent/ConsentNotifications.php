<?php

/**
 * ConsentNotifications — owns the canonical sbc_* notifications fetch + cache.
 *
 * Lifted from instagram-feed's class-sbi-notifications.php (Phase 0). Only the
 * methods relevant to consent (verify, load_local_fallback, source-switching
 * update, two-store sync refresh_notifications) are kept here. Per-plugin
 * presentation (output() carousel rendering, dismissal endpoints, etc.) stays
 * in each consumer plugin's own subclass — those classes can read from the
 * shared `sbc_notifications` option populated here.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Notification\Notices\SBNotices;
if (!defined('ABSPATH')) {
    exit;
}
class ConsentNotifications
{
    const SOURCE_URL = 'https://plugin.smashballoon.com/notifications.json';
    const OPTION_NAME = 'sbc_notifications';
    /**
     * Short aliases used in the remote feed → canonical plugin slugs used
     * by consumer plugins when calling ConsentManager::init(). Substring
     * matching was prone to over-matching ('in' → 'integrations'); this
     * keeps targeting exact while still supporting the short names already
     * used in the feed.
     *
     * @var array<string,string>
     */
    private static $target_aliases = array('instagram' => 'instagram-feed', 'facebook' => 'custom-facebook-feed', 'twitter' => 'custom-twitter-feeds', 'youtube' => 'feeds-for-youtube', 'tiktok' => 'tiktok-feeds', 'reviews' => 'reviews-feed');
    /**
     * Reverse of $target_aliases: canonical plugin slug → short brand name.
     * Used to build the {plugin}-{edition} utm_campaign value (e.g.
     * 'custom-facebook-feed' → 'facebook') so consent-flow links match the
     * in-plugin UTM convention from SMASH-1074..1080. A slug that is already
     * short (or unknown) is returned unchanged so the campaign degrades to
     * the raw slug rather than dropping out entirely.
     *
     * @param string $slug Canonical slug as passed to ConsentManager::init().
     * @return string Short brand alias, or the input unchanged if no alias.
     */
    public static function short_slug($slug)
    {
        $slug = (string) $slug;
        $short = array_search($slug, self::$target_aliases, \true);
        return $short === \false ? $slug : $short;
    }
    /**
     * Refresh the canonical `sbc_notifications` cache against the current
     * consent source, then clear stale marketing-group notices in every
     * registered plugin's SBNotices store so the next admin_init re-renders
     * from the new cache.
     *
     * Cost: O(N) over registered plugin slugs. One outbound HTTP request
     * only when DSC=on; otherwise a single local file read.
     */
    public static function refresh_notifications()
    {
        $source = ConsentManager::notification_source();
        if ($source === 'remote') {
            $feed = self::fetch_feed();
        } elseif ($source === 'local') {
            $feed = self::load_local_fallback();
        } else {
            $feed = array();
        }
        $existing = get_option(self::OPTION_NAME, array());
        update_option(self::OPTION_NAME, array('update' => time(), 'feed' => $feed, 'events' => isset($existing['events']) ? $existing['events'] : array(), 'dismissed' => isset($existing['dismissed']) ? $existing['dismissed'] : array()));
        // Two-store sync: drop marketing-group notices in every registered
        // plugin's SBNotices instance so on the next admin_init the freshly
        // re-fetched/cleared cache is what the user sees. Without this,
        // remotely-fetched notices linger after DSC flips off.
        foreach (ConsentManager::get_registered_slugs() as $slug) {
            self::reconcile_marketing_store($slug, array());
        }
    }
    /**
     * Reconcile a single plugin's marketing-group notices down to exactly
     * $expected_ids, removing any the current consent source no longer
     * produces. Pass an empty array to clear the whole marketing group (the
     * In-Plugin-Notifications-off / source 'none' case).
     *
     * This is the render-time counterpart to the bulk clear performed in
     * refresh_notifications(): consumer plugins call it from their output()
     * so the persisted marketing store can never drift from the active source
     * between consent changes (e.g. a local<->remote flip leaving both sets
     * rendering at once).
     *
     * @param string $slug         Plugin slug used for SBNotices::instance().
     * @param array  $expected_ids Notice ids the current source legitimately renders.
     * @return void
     */
    public static function reconcile_marketing_store($slug, array $expected_ids = array())
    {
        if (!class_exists(SBNotices::class)) {
            return;
        }
        $notices = SBNotices::instance($slug);
        if (!$notices || !method_exists($notices, 'get_group_notices') || !method_exists($notices, 'remove_notice')) {
            return;
        }
        $groups = $notices->get_group_notices();
        $marketing_ids = !empty($groups['marketing']) ? (array) $groups['marketing'] : array();
        foreach ($marketing_ids as $id) {
            if (!in_array($id, $expected_ids, \true)) {
                $notices->remove_notice($id);
            }
        }
    }
    /**
     * Convenience wrapper around reconcile_marketing_store(): derive the
     * expected marketing-store ids from a rendered notifications array, then
     * reconcile. Consumer plugins call this in one line from output() so the
     * source-switch guard + expected-id loop no longer live in every plugin.
     *
     * A single logical notification can persist under more than one SBNotices
     * id — the multi-step 'review' notice renders as 'review_step_1' and
     * 'review_step_2'. That expansion is the shared default; plugins with
     * other derived ids can adjust via the 'sbc_consent_marketing_ids' filter.
     * Passing an empty/none-source notifications array clears the whole group
     * (the source==='none' case, since get() returns [] then).
     *
     * @param string $slug          Plugin slug used for SBNotices::instance().
     * @param array  $notifications The notifications the plugin is about to render.
     * @return void
     */
    public static function reconcile_from_notifications($slug, array $notifications)
    {
        $expected_ids = array();
        foreach ($notifications as $notification) {
            if (empty($notification['id'])) {
                continue;
            }
            $id = $notification['id'];
            if ('review' === $id) {
                $ids = array('review_step_1', 'review_step_2');
            } else {
                $ids = array($id);
            }
            $ids = apply_filters('sbc_consent_marketing_ids', $ids, $id, $slug);
            foreach ((array) $ids as $marketing_id) {
                $expected_ids[] = $marketing_id;
            }
        }
        self::reconcile_marketing_store($slug, $expected_ids);
    }
    /**
     * Fetch notifications from the remote feed.
     *
     * @return array
     */
    public static function fetch_feed()
    {
        $res = wp_safe_remote_get(self::SOURCE_URL, array('timeout' => 10));
        if (is_wp_error($res)) {
            return array();
        }
        if (200 !== (int) wp_remote_retrieve_response_code($res)) {
            return array();
        }
        $body = wp_remote_retrieve_body($res);
        if (empty($body)) {
            return array();
        }
        return self::verify(json_decode($body, \true));
    }
    /**
     * Load notifications from the bundled local.json fallback. The payload
     * is run through verify() so it is held to the same schema as remote.
     *
     * @param string    $slug   Optional. Calling plugin's short slug (e.g.
     *                          'tiktok'). When given, targeting is scoped to
     *                          this plugin only so a site with several feed
     *                          plugins active doesn't show every plugin's cards
     *                          in every plugin's panel.
     * @param bool|null $is_pro Optional. Calling plugin's edition. When given,
     *                          license filtering uses it instead of the shared
     *                          ConsentManager::is_pro() (which is global across
     *                          all active plugins).
     * @return array
     */
    public static function load_local_fallback($slug = '', $is_pro = null)
    {
        $path = __DIR__ . '/notifications/local.json';
        if (!is_readable($path)) {
            return array();
        }
        $body = file_get_contents($path);
        if (empty($body)) {
            return array();
        }
        $decoded = json_decode($body, \true);
        if (null === $decoded || !is_array($decoded)) {
            return array();
        }
        return self::verify($decoded, $slug, $is_pro);
    }
    /**
     * Schema validation + targeting filters. Mirrors the SBI verify() but
     * is plugin-agnostic — version checks against per-plugin constants
     * (SBIVER, CFFVER, etc.) are skipped at this layer; per-plugin
     * subclasses can re-verify on read if they need version gating.
     *
     * @param mixed     $notifications
     * @param string    $slug   Optional. Scope targeting to this plugin's short
     *                          slug only. Empty keeps the legacy behaviour of
     *                          matching against every registered plugin.
     * @param bool|null $is_pro Optional. Edition for license filtering. Null
     *                          falls back to the shared ConsentManager::is_pro().
     * @return array
     */
    public static function verify($notifications, $slug = '', $is_pro = null)
    {
        $data = array();
        if (!is_array($notifications) || empty($notifications)) {
            return $data;
        }
        $option = get_option(self::OPTION_NAME, array());
        $dismissed = isset($option['dismissed']) ? (array) $option['dismissed'] : array();
        // When a calling plugin identifies itself, scope targeting to that one
        // plugin (its short slug + canonical). Otherwise fall back to every
        // registered plugin — the legacy, multi-plugin-unsafe behaviour.
        $slug = (string) $slug;
        if ($slug !== '') {
            $canonical_self = isset(self::$target_aliases[$slug]) ? self::$target_aliases[$slug] : $slug;
            $scope = array($slug, $canonical_self);
        } else {
            $scope = ConsentManager::get_registered_slugs();
        }
        foreach ($notifications as $notification) {
            if (!is_array($notification)) {
                continue;
            }
            // Plugin targeting. The feed uses short slugs ('instagram',
            // 'facebook', etc.) — accept a notification if it has no plugin
            // constraint OR if a slug in scope matches one of the notification's
            // targets (the alias map handles 'instagram' vs 'instagram-feed').
            if (!empty($notification['plugin']) && is_array($notification['plugin'])) {
                $matches = \false;
                foreach ($notification['plugin'] as $target) {
                    $target = (string) $target;
                    if ($target === '') {
                        continue;
                    }
                    $canonical = isset(self::$target_aliases[$target]) ? self::$target_aliases[$target] : $target;
                    foreach ($scope as $slug_in_scope) {
                        if ($slug_in_scope === $target || $slug_in_scope === $canonical) {
                            $matches = \true;
                            break 2;
                        }
                    }
                }
                if (!$matches) {
                    continue;
                }
            }
            if (!empty($notification['maxwpver']) && version_compare(get_bloginfo('version'), $notification['maxwpver'], '>')) {
                continue;
            }
            if (!empty($notification['minphpver']) && version_compare(\PHP_VERSION, $notification['minphpver'], '<')) {
                continue;
            }
            if (!empty($notification['maxphpver']) && version_compare(\PHP_VERSION, $notification['maxphpver'], '>')) {
                continue;
            }
            if (empty($notification['content']) || empty($notification['type'])) {
                continue;
            }
            // License targeting. Each card ships a free and a pro variant with
            // identical copy; without this filter a free plugin renders both and
            // every card duplicates (and vice versa for pro). Cards typed for
            // both editions ('free','pro') pass either way. Prefer the calling
            // plugin's own edition; the shared is_pro() is global across every
            // active plugin and would be wrong in a mixed free/pro site.
            $edition = $is_pro === null ? ConsentManager::is_pro() : (bool) $is_pro;
            $license = $edition ? 'pro' : 'free';
            if (is_array($notification['type']) && !in_array($license, $notification['type'], \true)) {
                continue;
            }
            if (!empty($notification['end'])) {
                $end_ts = strtotime($notification['end']);
                if ($end_ts !== \false && time() > $end_ts) {
                    continue;
                }
            }
            if (!empty($notification['id']) && in_array((string) $notification['id'], array_map('strval', $dismissed), \true)) {
                continue;
            }
            $data[] = $notification;
        }
        return $data;
    }
}
