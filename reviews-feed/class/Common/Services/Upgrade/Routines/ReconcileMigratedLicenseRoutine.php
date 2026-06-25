<?php

namespace SmashBalloon\Reviews\Common\Services\Upgrade\Routines;

use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * One-shot recovery for sites silently wedged by a pre-2.6.3 migration fork
 * (SMASH-1585).
 *
 * The wedge: a site migrated to a new URL on an old build re-registered with no
 * bearer, so the relay forked a fresh free-tier user for the new URL. The paid
 * license stayed on the OLD user; the new URL's user has none. Locally the
 * plugin still reads `license_status = 'valid'`, so the License panel shows the
 * license active with only a Deactivate button — and Deactivate dead-ends
 * (EDD has no activation for the new URL). Updating cannot self-heal it:
 * `detect_site_migration()` sees no mismatch (the fork already rebound
 * `website_url` to the new URL), so no recovery path fires.
 *
 * This routine asks the relay ONCE whether the license is actually active for
 * this site (the `license_active` flag added to /auth/ping in sb-relay
 * SMASH-1585). If the relay is reachable, the token is valid, the URL matches,
 * and `license_active === false`, the local `valid` flag is provably stale, so
 * we clear it — the panel falls back to Activate, and one click re-links the
 * license cleanly.
 *
 * Loop-safety (this flow has a history of register loops — keep it boring):
 *   - One-shot via its OWN completion flag, NOT `sbr_db_version` (a fresh Pro
 *     install must not skip it just because the schema version advanced — see
 *     the per-routine-flag rule). Marked done BEFORE any work, so a mid-run
 *     fatal can never re-fire it on the next load.
 *   - Exactly ONE relay request (the ping), and only on installs that already
 *     think they are licensed. Healthy sites get a single `license_active:true`
 *     and do nothing else.
 *   - NO auto re-activation: that would risk EDD slot churn and staging<->prod
 *     oscillation. We only flip local state to surface Activate; the customer's
 *     click drives the (rate-limited, relay-side) re-activation.
 *   - Inconclusive signals (relay unreachable, error, missing flag) are treated
 *     as "do nothing" — we never clear a healthy license on a soft signal.
 *
 * @see SMASH-1585
 */
class ReconcileMigratedLicenseRoutine extends ServiceProvider
{
	/**
	 * Per-routine completion flag. Deliberately NOT keyed on sbr_db_version.
	 */
	private const DONE_OPTION = 'sbr_license_reconcile_1585_done';

	protected $target_version = 0;

	public function register()
	{
		if ($this->will_run()) {
			$this->run();
		}
	}

	/**
	 * Runs once ever, and only for an install that BELIEVES it is licensed
	 * (local status valid + a key + a token to probe with). Healthy or not,
	 * it runs at most once.
	 *
	 * @return bool
	 */
	protected function will_run()
	{
		if (get_option(self::DONE_OPTION)) {
			return false;
		}
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			return false;
		}
		$status_valid = isset($settings['license_status']) && $settings['license_status'] === 'valid';
		$has_key      = isset($settings['license_key'])    && $settings['license_key']    !== '';
		$has_token    = isset($settings['access_token'])   && $settings['access_token']   !== '';

		return $status_valid && $has_key && $has_token;
	}

	public function run()
	{
		// Mark done FIRST — even a fatal mid-run must not let this re-fire on the
		// next page load. One ping, once, ever.
		if (function_exists('update_option')) {
			update_option(self::DONE_OPTION, time());
		}

		if (!$this->is_wedged($this->probe_ping())) {
			return;
		}

		// Confirmed stale: local says 'valid', relay says the license is not
		// active for this URL/user. Surface Activate instead of the dead
		// "active + Deactivate-only" wedge. license_key is left in place so the
		// re-link is one click with no re-paste.
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			return;
		}
		$settings['license_status'] = '';
		$settings['license_info']   = '';
		update_option('sbr_settings', $settings);

		// Mirror clear_local_license_state() — also reset the cached tier in
		// sbr_statuses. Otherwise AuthorizationStatusCheck::get_license_tier()
		// keeps feature-gating the install as paid (tier >= 2 / === 3) while we
		// surface Activate, leaving a half-cleared state. (#482 review.)
		(new AuthorizationStatusCheck())->update_status([
			'license_info' => '',
			'license_tier' => 0,
		]);
	}

	/**
	 * One diagnostic ping. Returns the decoded relay response, or null on any
	 * failure. Isolated + protected so tests can stub it without HTTP.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function probe_ping()
	{
		try {
			$relay = new SBRelay(new SettingsManagerService());

			return $relay->call('auth/ping', ['url' => get_home_url()], 'GET', true);
		} catch (\Throwable $e) {
			return null;
		}
	}

	/**
	 * Wedged iff the relay is reachable, the token is valid, the URL matches
	 * (pong) AND it explicitly reports license_active === false. Any other shape
	 * — unreachable, error, missing flag, or license_active true — is NOT wedged,
	 * so a healthy or unverifiable license is never cleared.
	 *
	 * @param mixed $ping
	 */
	protected function is_wedged($ping): bool
	{
		return is_array($ping)
			&& !empty($ping['success'])
			&& !empty($ping['pong'])
			&& array_key_exists('license_active', $ping)
			&& $ping['license_active'] === false;
	}
}
