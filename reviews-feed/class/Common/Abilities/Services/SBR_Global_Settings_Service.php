<?php

namespace SmashBalloon\Reviews\Common\Abilities\Services;

use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads/writes plugin-wide settings for the Abilities API, restricted to the
 * SBR_Settings_Schema::global_settings() allowlist.
 *
 * Writes go through SettingsManagerService::update_settings() — the SAME
 * method SettingsManagerService::ajax_update_settings() (the Settings page's
 * real save handler) calls — passing only the changed keys. update_settings()
 * itself array_merge()s onto the CURRENT stored option
 * (class/Common/Services/SettingsManagerService.php:52-77), so this never
 * clobbers unrelated options (license fields, api keys, translations, …) the
 * way a synthetic full-model save would; it also carries update_settings()'s
 * own side effect of scheduling/unscheduling the usage-tracking cron when the
 * `usagetracking` key changes.
 *
 * `ai_write_enabled` (the write gate itself, SBR_Write_Gate::OPTION_KEY) is
 * deliberately NOT in the schema — an agent must not be able to re-enable
 * its own write access after a human disables it, mirroring the canonical
 * Instagram Feed port.
 *
 * @since SBR-499
 */
class SBR_Global_Settings_Service
{
	/**
	 * @return array<string, mixed>
	 */
	public function get()
	{
		$settings = (new SettingsManagerService())->get_settings();
		$defaults = $this->defaults();
		$schema   = SBR_Settings_Schema::global_settings();
		$out      = array();
		foreach ($schema as $key => $def) {
			$out[$key] = SBR_Settings_Schema::cast_out(
				$def,
				isset($settings[$key]) ? $settings[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		return $out;
	}

	/**
	 * Apply a partial update to the plugin-wide settings.
	 *
	 * @param array $partial key => value, already schema-validated by core.
	 * @return array{updated: string[], ignored: object, warnings: string[]}|\WP_Error
	 */
	public function update(array $partial)
	{
		$schema   = SBR_Settings_Schema::global_settings();
		$writable = SBR_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = SBR_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			$clean[$key] = $v;
			$updated[]   = $key;
		}

		if (empty($clean)) {
			return array('updated' => array(), 'ignored' => (object) $ignored, 'warnings' => array());
		}

		// Real save path: SettingsManagerService::update_settings() merges these
		// keys onto the current option and applies its own side effects
		// (usage-tracking cron schedule/unschedule + purge on opt-out).
		(new SettingsManagerService())->update_settings($clean);

		/**
		 * Fires after an AI agent updated global settings through the Abilities API.
		 *
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sbr_abilities_global_settings_updated', $updated);

		$warnings = array();
		if (in_array('gdpr', $updated, true) && isset($clean['gdpr']) && $clean['gdpr'] === 'no') {
			$warnings[] = 'GDPR mode is now "no": feeds load review avatars and third-party content '
				. 'before visitors consent. Tell the site owner, since this can affect legal compliance.';
		}

		return array('updated' => $updated, 'ignored' => (object) $ignored, 'warnings' => $warnings);
	}

	/**
	 * Plugin defaults for the global-settings allowlist. `usagetracking`
	 * defaults differently for Free vs Pro (sbr_plugin_settings_defaults()),
	 * so it is resolved live here rather than hardcoded.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults()
	{
		$defaults = function_exists('sbr_plugin_settings_defaults') ? sbr_plugin_settings_defaults() : array();
		// 'gdpr' is not in sbr_plugin_settings_defaults(); SBR_GDPR::doing_gdpr()
		// treats a missing/empty value as 'auto', so report the same.
		if (!isset($defaults['gdpr'])) {
			$defaults['gdpr'] = 'auto';
		}
		if (!isset($defaults['usagetracking'])) {
			$defaults['usagetracking'] = Util::sbr_is_pro();
		}
		return $defaults;
	}
}
