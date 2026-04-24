<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use Smashballoon\Stubs\Services\ServiceProvider;

class RegisterWebsiteRoutine extends ServiceProvider
{
	private const RETRY_TRANSIENT_KEY = 'sbr_register_retry_cooldown';

	protected $target_version = 0;

	public function register()
	{
		if ($this->will_run()) {
			$this->run();
		}
	}

	protected function will_run()
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			return true; // corrupted state — re-register and rewrite as array
		}
		if (isset($settings['access_token']) && $settings['access_token'] !== '') {
			return false;
		}
		// Rate-limit re-registration so a misbehaving caller can't flood /auth/register.
		if (function_exists('get_transient') && get_transient(self::RETRY_TRANSIENT_KEY)) {
			return false;
		}
		return true;
	}


	public function run()
	{
		// Claim the quota slot before the relay call — a mid-call fatal still counts.
		if (function_exists('set_transient')) {
			$cooldown = defined('MINUTE_IN_SECONDS') ? 5 * MINUTE_IN_SECONDS : 300;
			set_transient(self::RETRY_TRANSIENT_KEY, time(), $cooldown);
		}

		$args = [
			'url' => get_home_url()
		];

		$relay = new SBRelay(new SettingsManagerService());
		$response = $relay->call(
			'auth/register',
			$args,
			'POST',
			false
		);

		// Token may be at root level (new registration) or nested in data (existing user)
		$token = $response['data']['token'] ?? $response['token'] ?? null;
		if ($token) {
			$settings = get_option('sbr_settings', []);
			// `$settings` is written below as an array. A corrupted non-array
			// value would PHP-fatal on the offset write in PHP 8+ — normalize
			// first so recovery can proceed instead of crashing.
			if (!is_array($settings)) {
				$settings = [];
			}
			$settings['access_token'] = $token;
			$settings['website_url']  = get_home_url();
			update_option('sbr_settings', $settings);

			// DO NOT clear the cooldown on success. Earlier revisions did — with
			// unintended consequence: when an upstream bug (e.g. detect_site_migration
			// false-positives wiping access_token every request), the loop was
			// register → store → clear cooldown → next request repeats, so the
			// cooldown never engaged and the rate-limit was a no-op. Keeping the
			// cooldown active bounds per-site register traffic to 1 per 5 minutes
			// regardless of what happens elsewhere. A legitimate re-registration
			// (admin migration, manual reset) simply waits up to 5 minutes.
		}
	}
}
