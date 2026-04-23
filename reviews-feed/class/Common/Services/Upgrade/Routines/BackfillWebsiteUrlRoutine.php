<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * One-shot backfill — writes `sbr_settings['website_url']` for installs that
 * have an `access_token` but no `website_url` (any install that registered
 * before the SMASH-1281 patch shipped).
 *
 * Without this backfill, `SBRelay::detect_site_migration()` short-circuits on
 * the empty `website_url`, so the proactive migration guard would only
 * protect NEW registrations. This routine makes the existing install base
 * eligible for proactive recovery from the next request onward.
 *
 * The routine is self-terminating: once `website_url` is written, the next
 * `will_run()` check returns false and it never runs again.
 *
 * Trade-off: if the plugin is first upgraded on a site that has ALREADY been
 * migrated (e.g. the customer is installing this patch to fix their stuck
 * site), this routine will lock the CURRENT URL in as the baseline. That's
 * acceptable — the reactive `check_token_validity()` path still catches the
 * mismatch on the next relay call via SMASH-1274's `discriminator`, and the
 * resulting wipe clears the bad baseline we just wrote.
 *
 * @see SMASH-1281
 */
class BackfillWebsiteUrlRoutine extends ServiceProvider
{
	protected $target_version = 0;

	public function register()
	{
		if ($this->will_run()) {
			$this->run();
		}
	}

	/**
	 * Runs iff the install has an access_token but no website_url.
	 *
	 * @return bool
	 */
	protected function will_run()
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			return false;
		}
		$has_token       = isset($settings['access_token']) && $settings['access_token'] !== '';
		$has_website_url = isset($settings['website_url'])  && $settings['website_url']  !== '';
		return $has_token && !$has_website_url;
	}

	public function run()
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			$settings = [];
		}
		$settings['website_url'] = get_home_url();
		update_option('sbr_settings', $settings);
	}
}
