<?php

namespace SmashBalloon\Reviews\Common\Services;

/**
 * Storage for the SMASH-1402 build-vs-licence mismatch.
 *
 * Only the licence side is stored: `sbr_settings['variant_mismatch']` =
 * {license, detected_at}, no expiry. Written by `record_after_activate()`
 * (manual Activate and silent re-activation), removed by `clear()` (matching
 * activation, deactivation). It is compared with the RUNNING `SBR_PRODUCT_ID`
 * at read time, so installing the licensed build hides it with no
 * re-activation and a swap to another wrong build still shows it.
 *
 * @since SMASH-1402
 */
class VariantMismatchFlag
{
	public const OPTION = 'sbr_settings';
	public const SETTINGS_KEY = 'variant_mismatch';

	/**
	 * The EDD download ids of the three Pro builds. Only a licence for one of
	 * these can disagree with the build; a bundle licence (e.g. All Access)
	 * covers every variant.
	 *
	 * @var array<int, string>
	 */
	public const VARIANT_LABELS = [
		1722768 => 'Reviews Feed Pro',
		1722779 => 'Reviews Feed Pro Plus',
		1722784 => 'Reviews Feed Pro Elite',
	];

	/**
	 * The mismatch between the stored licence and the RUNNING build, or null.
	 *
	 * @return array{build: int, license: int, detected_at: int}|null
	 */
	public static function get_mismatch(): ?array
	{
		// Every path that drops the licence (deactivate, expiry, a reconciled
		// migration) leaves license_status non-valid, so none can orphan this.
		$settings = get_option(self::OPTION, []);
		if (! is_array($settings) || ($settings['license_status'] ?? '') !== 'valid') {
			return null;
		}
		$flag = self::read_stored_flag();
		$build = self::running_build();
		if ($flag === null || $build <= 0 || $build === $flag['license'] || ! isset(self::VARIANT_LABELS[$flag['license']])) {
			return null;
		}

		return ['build' => $build, 'license' => $flag['license'], 'detected_at' => $flag['detected_at']];
	}

	/**
	 * Per-user dismissal key: the same licence event on a different running
	 * build is a different notice.
	 *
	 * @param array{build: int, license: int, detected_at: int} $mismatch
	 */
	public static function dismissal_key(array $mismatch): string
	{
		return $mismatch['build'] . ':' . $mismatch['license'] . ':' . $mismatch['detected_at'];
	}

	/**
	 * @return array{license: int, detected_at: int}|null
	 */
	private static function read_stored_flag(): ?array
	{
		$settings = get_option(self::OPTION, []);
		$flag = is_array($settings) && isset($settings[self::SETTINGS_KEY]) ? $settings[self::SETTINGS_KEY] : null;
		if (! is_array($flag)) {
			return null;
		}

		$license = isset($flag['license']) && is_numeric($flag['license']) ? (int) $flag['license'] : 0;
		$detected_at = isset($flag['detected_at']) && is_numeric($flag['detected_at']) ? (int) $flag['detected_at'] : 0;
		if ($license <= 0 || $detected_at <= 0) {
			return null;
		}

		return ['license' => $license, 'detected_at' => $detected_at];
	}

	private static function running_build(): int
	{
		return defined('SBR_PRODUCT_ID') ? (int) SBR_PRODUCT_ID : 0;
	}

	/**
	 * Called after every successful (`valid`) activation, manual or silent.
	 *
	 * - `item_id` matches the running build → `clear()`.
	 * - `item_id` is not one of the three variants (a bundle licence) →
	 *   `clear()`: it covers whichever build is installed.
	 * - `item_id` is another variant → store the licence side; the same licence already
	 *   stored keeps its `detected_at`, so a re-activation does not re-show a
	 *   dismissed notice.
	 * - `item_id` missing / non-numeric → leave the stored state alone: it
	 *   confirms neither a mismatch nor a fix.
	 *
	 * @param mixed $license_item_id `api_data.item_id` from the activate response.
	 */
	public static function record_after_activate($license_item_id): void
	{
		$build_id = self::running_build();
		$license_id = is_numeric($license_item_id) ? (int) $license_item_id : 0;
		if ($build_id <= 0 || $license_id <= 0) {
			return;
		}

		if ($build_id === $license_id || ! isset(self::VARIANT_LABELS[$license_id])) {
			self::clear();

			return;
		}

		$existing = self::read_stored_flag();
		if ($existing !== null && $existing['license'] === $license_id) {
			return;
		}

		$settings = get_option(self::OPTION, []);
		$settings = is_array($settings) ? $settings : [];
		$settings[self::SETTINGS_KEY] = [
			'license' => $license_id,
			'detected_at' => time(),
		];
		update_option(self::OPTION, $settings);
	}

	/**
	 * Remove the stored flag (matching activation, licence deactivated).
	 */
	public static function clear(): void
	{
		$settings = get_option(self::OPTION, []);
		if (is_array($settings) && array_key_exists(self::SETTINGS_KEY, $settings)) {
			unset($settings[self::SETTINGS_KEY]);
			update_option(self::OPTION, $settings);
		}
	}
}
