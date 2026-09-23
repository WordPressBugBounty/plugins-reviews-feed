<?php

/**
 * Content Provider - Loads About Us page content.
 *
 * Content is fetched once per site from the central endpoint and cached in a
 * shared option (refreshed daily via a single cron event registered in
 * AboutUsManager). The bundled JSON file is the guaranteed fallback when the
 * remote content has never been fetched or is unavailable.
 *
 * @package AboutUs
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\AboutUs;

use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager;
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Loads and caches the About Us page content.
 */
class ContentProvider
{
    /**
     * Shared option name that holds the cached remote content for the whole site.
     *
     * Shape: [ 'update' => <unix timestamp>, 'data' => <content array> ].
     *
     * @var string
     */
    const OPTION_NAME = 'sbc_about_us_content';
    /**
     * Cron hook that refreshes the cached content.
     *
     * @var string
     */
    const CRON_HOOK = 'sbc_about_us_update';
    /**
     * Default remote endpoint. The content is identical across Smash Balloon
     * plugins (only the {plugin_name} token differs, and that is interpolated at
     * render time), so a single canonical file serves every plugin on the site.
     *
     * @var string
     */
    const REMOTE_URL = 'https://plugin.smashballoon.com/about-us.json';
    /**
     * The only origin install links may point to.
     *
     * @var string
     */
    const WPORG_DOWNLOAD_PREFIX = 'https://downloads.wordpress.org/plugin/';
    /**
     * Per-request cache of the resolved content.
     *
     * @var array|null
     */
    private static $data = null;
    /**
     * Get all About Us page content.
     *
     * @return array
     */
    public static function get_content()
    {
        return self::load();
    }
    /**
     * Get a single section of content.
     *
     * @param string $section Section key (e.g. 'our_plugins', 'recommended_plugins').
     *
     * @return array
     */
    public static function get_section($section)
    {
        $data = self::load();
        return isset($data[$section]) ? $data[$section] : [];
    }
    /**
     * Resolve the effective content for this request.
     *
     * Order of preference: freshly fetched remote content → last-good cached
     * content → bundled JSON file.
     *
     * @return array
     */
    private static function load()
    {
        if (null !== self::$data) {
            return self::$data;
        }
        // WP.org compliance: without data-sharing consent, serve the bundled
        // content only — never contact the remote endpoint or read any cached
        // remote copy.
        if (!self::remote_allowed()) {
            self::$data = self::load_bundled();
            return self::$data;
        }
        // Lazily refresh when the cache has never been populated or has expired.
        // The refresh is only ever triggered while viewing the About Us page,
        // and at most once per day; the cron handles background refreshes.
        if (self::needs_refresh()) {
            $fresh = self::refresh();
            if (is_array($fresh) && !empty($fresh)) {
                self::$data = $fresh;
                return self::$data;
            }
        }
        // refresh() may have preserved the last-good data; read the current cache.
        $option = get_option(self::OPTION_NAME, []);
        $option = is_array($option) ? $option : [];
        if (!empty($option['data']) && is_array($option['data'])) {
            self::$data = $option['data'];
            return self::$data;
        }
        self::$data = self::load_bundled();
        return self::$data;
    }
    /**
     * Fetch the remote content and update the shared cache.
     *
     * Runs both from the daily cron and from the lazy on-page check. On failure
     * it keeps the last-good data but still bumps the timestamp, so an
     * unavailable endpoint is not re-hit on every page load.
     *
     * @return array|null The freshly fetched content, or null on failure.
     */
    public static function refresh()
    {
        $option = get_option(self::OPTION_NAME, []);
        $option = is_array($option) ? $option : [];
        $current = isset($option['data']) && is_array($option['data']) ? $option['data'] : [];
        $fetched = self::fetch_remote();
        // Success → store fresh data; failure → retain the previous data.
        $data = is_array($fetched) ? $fetched : $current;
        // Only treat this as a real update when the remote content actually
        // differs from what is already cached. When nothing changed we leave the
        // stored content alone. A consent toggle or the daily cron does not
        // churn the content or fire change-driven work for identical data.
        $changed = self::content_changed($current, $data);
        update_option(self::OPTION_NAME, ['update' => time(), 'data' => $data], \false);
        // Drop the per-request cache so later calls this request see new data.
        self::$data = null;
        if ($changed) {
            /**
             * Fires when the cached About Us content actually changes.
             *
             * @param array $data    The new content that was just stored.
             * @param array $current The previously cached content.
             */
            do_action('sbc_about_us_content_changed', $data, $current);
        }
        return is_array($fetched) && !empty($fetched) ? $fetched : null;
    }
    /**
     * Whether the freshly resolved content differs from what is already cached.
     *
     * Compared as normalised JSON so key order and array pointers don't produce
     * false positives.
     *
     * @param array $old Previously cached content.
     * @param array $new Newly resolved content.
     *
     * @return bool
     */
    private static function content_changed($old, $new)
    {
        return wp_json_encode($old) !== wp_json_encode($new);
    }
    /**
     * Force a content refresh when data-sharing consent is switched on and the
     * cached content is missing or stale.
     *
     * Wired to the shared `sbc_consent_changed` action (see AboutUsManager::boot)
     * so that the moment a user opts in to data sharing — for any Smash Balloon
     * feed plugin — the About Us content is fetched immediately, rather than
     * waiting for the daily cron or for the user to open the About Us page.
     *
     * @param array $flags Consent flags payload: [ 'dsc' => bool, 'notif' => bool ].
     *
     * @return void
     */
    public static function refresh_on_consent($flags = [])
    {
        // Schedule or clear the daily remote-fetch cron to match the new consent
        // state (also handles opt-out: the cron must not linger once DSC is off).
        self::reconcile_cron();
        // Only fetch when data-sharing consent is now ON.
        $dsc = is_array($flags) && !empty($flags['dsc']);
        if (!$dsc || !self::remote_allowed()) {
            return;
        }
        if (self::needs_refresh()) {
            self::refresh();
        }
    }
    /**
     * Keep the daily refresh cron in sync with data-sharing consent.
     *
     * WP.org compliance: a scheduled event whose callback contacts an external
     * URL must not exist while the user has not opted in (a consent gate inside
     * the callback is not sufficient for plan review, per the Consent package).
     * So the event is scheduled only while remote fetching is allowed and is
     * cleared the moment consent is withdrawn. Idempotent and self-healing.
     *
     * @return void
     */
    public static function reconcile_cron()
    {
        $scheduled = (bool) wp_next_scheduled(self::CRON_HOOK);
        if (self::remote_allowed()) {
            if (!$scheduled) {
                wp_schedule_event(time(), 'daily', self::CRON_HOOK);
            }
        } elseif ($scheduled) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }
    /**
     * Whether the cached remote content should be (re)fetched: it has never been
     * stored, holds no data, or is older than a day.
     *
     * @return bool
     */
    private static function needs_refresh()
    {
        $option = get_option(self::OPTION_NAME, []);
        $option = is_array($option) ? $option : [];
        if (empty($option['data']) || !is_array($option['data'])) {
            return \true;
        }
        return empty($option['update']) || time() > (int) $option['update'] + \DAY_IN_SECONDS;
    }
    /**
     * Perform the remote request and decode the response.
     *
     * @return array|null Decoded content, or null on any failure.
     */
    private static function fetch_remote()
    {
        // Compliance guard for the cron path (which calls refresh() directly):
        // no remote request unless the user has opted in to data sharing.
        if (!self::remote_allowed()) {
            return null;
        }
        $url = self::get_remote_url();
        if (empty($url)) {
            return null;
        }
        $response = wp_safe_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }
        $decoded = json_decode($body, \true);
        // Reject a truncated or unrelated file so it never replaces good content.
        if (!is_array($decoded) || empty($decoded['our_plugins']['plugins']) || !is_array($decoded['our_plugins']['plugins'])) {
            return null;
        }
        return self::sanitize_remote($decoded);
    }
    /**
     * Drop malformed plugin entries and any remote install link that is not a
     * WordPress.org download.
     *
     * The remote file drives the Install buttons and the AJAX allow-list, so a
     * tampered file must never be able to point an install at another host.
     * Templates print title/description directly, so they must be strings.
     *
     * @param array $content Decoded remote content.
     *
     * @return array
     */
    private static function sanitize_remote($content)
    {
        foreach (['our_plugins', 'recommended_plugins'] as $section) {
            if (empty($content[$section]['plugins']) || !is_array($content[$section]['plugins'])) {
                continue;
            }
            foreach ($content[$section]['plugins'] as $key => $plugin) {
                if (!is_array($plugin) || !isset($plugin['title'], $plugin['description']) || !is_string($plugin['title']) || !is_string($plugin['description'])) {
                    unset($content[$section]['plugins'][$key]);
                    continue;
                }
                if (isset($plugin['download_url']) && !self::is_wporg_download($plugin['download_url'])) {
                    unset($content[$section]['plugins'][$key]['download_url']);
                }
            }
        }
        $promo = isset($content['featured_promo']) && is_array($content['featured_promo']) ? $content['featured_promo'] : [];
        // Only install buttons carry a download URL; activate buttons carry a basename.
        if (isset($promo['button_action'], $promo['button_plugin']) && 'install' === $promo['button_action'] && !self::is_wporg_download($promo['button_plugin'])) {
            unset($content['featured_promo']['button_plugin']);
        }
        return $content;
    }
    /**
     * Remove the refresh cron and cached content.
     *
     * Call from a consumer's uninstall hook. If another Smash Balloon plugin is
     * still active, AboutUsManager::boot() reschedules the cron on its next load.
     *
     * @return void
     */
    public static function uninstall()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::OPTION_NAME);
        self::$data = null;
    }
    /**
     * Whether a URL is a WordPress.org plugin download.
     *
     * @param mixed $url Candidate URL.
     *
     * @return bool
     */
    public static function is_wporg_download($url)
    {
        return is_string($url) && strpos($url, self::WPORG_DOWNLOAD_PREFIX) === 0;
    }
    /**
     * Whether contacting the remote endpoint is permitted.
     *
     * Gated on the shared data-sharing consent flag (WP.org compliance). When
     * the Consent package is unavailable we default to NOT phoning home, which
     * is the compliance-safe choice; this can be overridden via the filter.
     *
     * @return bool
     */
    private static function remote_allowed()
    {
        if (class_exists(ConsentManager::class)) {
            $allowed = ConsentManager::is_dsc_enabled();
        } else {
            $allowed = \false;
        }
        /**
         * Filter whether the About Us content may be fetched remotely.
         *
         * @param bool $allowed Whether the remote fetch is permitted.
         */
        return (bool) apply_filters('sbc_about_us_remote_allowed', $allowed);
    }
    /**
     * Remote endpoint URL, filterable for overrides (e.g. local development).
     *
     * @return string
     */
    private static function get_remote_url()
    {
        /**
         * Filter the About Us remote content URL.
         *
         * @param string $url Default endpoint.
         */
        return (string) apply_filters('sbc_about_us_remote_url', self::REMOTE_URL);
    }
    /**
     * Load (and decode) the bundled JSON data file. Returns an empty array on failure.
     *
     * @return array
     */
    private static function load_bundled()
    {
        $data = [];
        $json_file = dirname(__FILE__) . '/assets/about-us-data.json';
        if (is_readable($json_file)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $decoded = json_decode(file_get_contents($json_file), \true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        return $data;
    }
}
