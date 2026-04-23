<?php

namespace SmashBalloon\Reviews\Common\Services;

use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Keeps `sbr_settings['website_url']` in sync with WordPress's own `home` /
 * `siteurl` options when the admin legitimately changes the site URL.
 *
 * **Why this is necessary:**
 *
 * `SBRelay::detect_site_migration()` (SMASH-1281) compares `get_home_url()`
 * against the URL stored at registration. Without this watcher, any admin-
 * initiated URL change (HTTP→HTTPS rollout, `example.com` → `www.example.com`,
 * domain rebrand) would look indistinguishable from a site migration and
 * trigger a full state wipe — punishing customers for doing legitimate WP
 * admin work.
 *
 * The distinction rides on HOW the URL changed:
 *
 *   - Admin edits General Settings → `update_option('home', …)` fires →
 *     this watcher updates `sbr_settings['website_url']` in lock-step →
 *     the detect check sees matching URLs → no wipe. ✅
 *
 *   - External DB copy / WP Engine push → raw INSERT/UPDATE on `wp_options`
 *     by a different process → no WP hook fires → `sbr_settings['website_url']`
 *     stays at the original value → detect check sees a mismatch → wipe. ✅
 *
 * That asymmetry is exactly the signal we want.
 *
 * @see SMASH-1281
 */
class SiteUrlWatcher extends ServiceProvider
{
	public function register()
	{
		if (!function_exists('add_action')) {
			return;
		}
		add_action('update_option_home', [$this, 'on_site_url_changed'], 10, 3);
		add_action('update_option_siteurl', [$this, 'on_site_url_changed'], 10, 3);
	}

	/**
	 * Fired when WP's `home` or `siteurl` option is legitimately updated.
	 * Refreshes `sbr_settings['website_url']` so the proactive migration
	 * detection doesn't spuriously fire on the next request.
	 *
	 * Only acts if the plugin has already registered (access_token present).
	 * For unregistered installs there's nothing to sync — the URL will be
	 * captured fresh on first registration.
	 *
	 * Signature matches WP's `update_option_{$option}` hook: ($old, $new, $option).
	 *
	 * @param mixed  $old_value
	 * @param mixed  $new_value
	 * @param string $option
	 */
	public function on_site_url_changed($old_value, $new_value, $option = '')
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings) || empty($settings['access_token'])) {
			return;
		}
		$home = get_home_url();
		if (empty($home)) {
			return;
		}
		$settings['website_url'] = $home;
		update_option('sbr_settings', $settings);
	}
}
