<?php

/**
 * Settings Manager Service
 */

namespace SmashBalloon\Reviews\Common\Services;

if (! defined('ABSPATH')) {
	exit;
}

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Utils\AjaxUtil;
use Smashballoon\Stubs\Services\ServiceProvider;

class SettingsManagerService extends ServiceProvider
{
	private $settings_options = 'sbr_settings';

	/** Stored MCP Write Access toggle (SBR_Write_Gate::OPTION_KEY; Pro-only, kept literal so Common never imports Pro). */
	public const AI_WRITE_KEY = 'ai_write_enabled';

	/**
	 * Not a real setting: the value the Settings page was LOADED with for the
	 * toggle above, round-tripped inside the posted pluginSettings so the server
	 * can tell "the user flipped the switcher" from "a stale tab posted back what
	 * it loaded". Always stripped before saving.
	 */
	public const AI_WRITE_LOADED_KEY = 'ai_write_enabled_loaded';

	public function register(): void
	{
		add_action('wp_ajax_sbr_update_settings', [$this, 'ajax_update_settings']);
	}

	public function ajax_update_settings(): void
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (!sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error();
		}

		unset($_POST['nonce'], $_POST['action']);

		$settings = json_decode(stripslashes($_POST['settings']), true);

		// Server-owned (SMASH-1402): the Settings page posts back the whole
		// pluginSettings it loaded, so a stale copy would undo an activation's clear.
		if (is_array($settings)) {
			unset($settings[VariantMismatchFlag::SETTINGS_KEY]);
			$settings = $this->reconcile_ai_write_toggle($settings);
		}

		$update_array = $this->sanitize_settings($settings) ;

		foreach ($settings['translations'] as $key => $value) {
			$update_array['translations'][$key] = sanitize_text_field($value);
		}


		if ($this->update_settings($update_array)) {
			// The page merges `settings` back into its model (sb-customizer
			// SMASH-2354), so the toggle shows what is really stored and the
			// loaded marker follows it.
			wp_send_json_success(['settings' => $this->stored_ai_write_settings()]);
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * Only accept `ai_write_enabled` from the Settings page when the user actually
	 * changed it in that tab.
	 *
	 * The page posts back the WHOLE pluginSettings it loaded, so a tab opened while
	 * MCP Write Access was on would, on any later unrelated save, write `true` back
	 * over a toggle an admin has since turned off, silently re-enabling agent writes.
	 * The page therefore carries the value it loaded the toggle with
	 * (AI_WRITE_LOADED_KEY); the posted toggle counts only when it differs from
	 * that. An unchanged value is dropped, so update_settings() keeps the stored one.
	 * A real flip in the switcher still saves, and an on -> off save still reaches
	 * update_option(), where SBR_Write_Gate records the revocation epoch.
	 * A post with no loaded-value marker (a page rendered by an older version) can
	 * only turn the toggle OFF; it fails closed rather than enabling writes.
	 *
	 * @param array $settings Decoded posted settings.
	 * @return array
	 */
	private function reconcile_ai_write_toggle(array $settings): array
	{
		$loaded = array_key_exists(self::AI_WRITE_LOADED_KEY, $settings)
			? filter_var($settings[self::AI_WRITE_LOADED_KEY], FILTER_VALIDATE_BOOLEAN)
			: null;
		unset($settings[self::AI_WRITE_LOADED_KEY]);

		if (array_key_exists(self::AI_WRITE_KEY, $settings)) {
			$posted = filter_var($settings[self::AI_WRITE_KEY], FILTER_VALIDATE_BOOLEAN);
			// Turning writes OFF is always honoured, so it always reaches
			// update_option (and records the gate epoch). Turning writes ON is only
			// accepted when this page loaded it OFF, so a stale tab (loaded ON,
			// never touched) can't re-enable writes an admin disabled elsewhere.
			// No marker (older page) -> ON is dropped. The save response returns
			// the stored toggle and a refreshed marker (stored_ai_write_settings()),
			// which the page merges back, so off->save->on->save in one tab works.
			// A page without that merge (older customizer) needs a reload to
			// re-enable after turning it off.
			if ($posted && $loaded !== false) {
				unset($settings[self::AI_WRITE_KEY]);
			}
		}
		return $settings;
	}

	/**
	 * The MCP Write Access toggle as it is now stored, plus the loaded marker set
	 * to the same value. Returned in the save response so the Settings page can
	 * refresh both: a later ON after an OFF save in the same tab is then judged
	 * against the real stored state, and a refused stale ON shows OFF again.
	 *
	 * @return array{ai_write_enabled: bool, ai_write_enabled_loaded: bool}
	 */
	private function stored_ai_write_settings(): array
	{
		$stored = !empty($this->get_settings()[self::AI_WRITE_KEY]);
		return [
			self::AI_WRITE_KEY        => $stored,
			self::AI_WRITE_LOADED_KEY => $stored,
		];
	}

	public function update_settings($settings): bool
	{
		$current_settings = $this->get_settings();
		$updated_settings = array_merge($current_settings, $settings);

		// Legacy interim key; consent now lives in the 'usagetracking' key.
		unset($updated_settings['smash_usage_tracking']);

		// update_option() returns false when the value is unchanged; that is not
		// a failed save, so treat a no-op save as success.
		$result = $updated_settings === $current_settings
			|| update_option($this->settings_options, $updated_settings);

		// Schedule/unschedule the usage tracking cron based on consent.
		if (array_key_exists('usagetracking', $updated_settings)) {
			$scheduler = new \SmashBalloon\Reviews\Common\UsageTracking\Core\Scheduler();
			if (! empty($updated_settings['usagetracking'])) {
				$scheduler->schedule();
			} else {
				$scheduler->unschedule();
				// Opting out must not be weaker than uninstalling: drop the
				// already-collected data and the site token, or they would be
				// retained indefinitely and transmitted after a later re-opt-in.
				\SmashBalloon\Reviews\Common\UsageTracking\SmashUsageTracking::purge_stored_data();
			}
		}

		return $result;
	}

	public function get_settings(): array
	{
		$settings = get_option($this->settings_options, []);
		// Return type is `array` — coerce corrupted non-array state to [] to
		// avoid a TypeError and let SMASH-1281's recovery flow re-register.
		return is_array($settings) ? $settings : [];
	}

	public function sanitize_settings($data)
	{
		$sanitized_and_sorted = [];

		foreach ($data as $key => $value) {
			$data_type = SBR_Feed_Saver_Manager::get_data_type($key);
			$sanitized_values = array();
			if (is_array($value)) {
				$sanitized_values[] = $value;
			} elseif (is_object($value)) {
				$sanitized_values[] = $value;
			} else {
				$type = SBR_Feed_Saver_Manager::is_boolean($value) ? 'boolean' : $data_type['sanitization'];
				$sanitized_values = SBR_Feed_Saver_Manager::sanitize($type, $value);
			}

			$sanitized_and_sorted[$key] = $sanitized_values;
		}
		return $sanitized_and_sorted;
	}
}
