<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use Smashballoon\Stubs\Services\ServiceProvider;

class RegisterWebsiteRoutine extends ServiceProvider
{
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
		return !isset($settings['access_token']) || $settings['access_token'] === '';
	}


	public function run()
	{
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
			$settings['access_token'] = $token;
			update_option('sbr_settings', $settings);
		}
	}
}
