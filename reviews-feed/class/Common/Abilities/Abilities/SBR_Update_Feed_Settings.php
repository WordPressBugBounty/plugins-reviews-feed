<?php

namespace SmashBalloon\Reviews\Common\Abilities\Abilities;

use SmashBalloon\Reviews\Common\Abilities\SBR_Ability_Response;
use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * reviews-feed/update-feed-settings
 *
 * Gated by SBR_Write_Gate, then capability. Reviews Feed's tier gate is
 * about which review PROVIDERS a license may use, not per-feed-setting
 * features (unlike Instagram/Facebook/Twitter's carousel-tier keys) — none
 * of SBR_Settings_Schema::feed_settings() is tier-gated, so there is no
 * per-key feature check here (see SBR_Set_Feed_Sources / SBR_Create_Feed for
 * where the tier gate actually applies: connecting/attaching a source).
 *
 * @since SBR-499
 */
class SBR_Update_Feed_Settings extends SBR_Ability
{
	public function slug()
	{
		return 'update-feed-settings';
	}

	public function args()
	{
		// writable_for_update(), not writable(): feedTemplate is create-only
		// (AgDR-0504 finding C2) — it stays writable() for create-feed alone.
		$writable = SBR_Settings_Schema::writable_for_update(SBR_Settings_Schema::feed_settings());
		$props    = array(
			'feed_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Feed id from list-feeds.',
			),
			'settings' => array(
				'type'                 => 'object',
				'properties'           => SBR_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
				'description'          => 'Settings to change. Only the keys you pass are changed; '
					. 'other stored values are re-saved as-is.',
			),
		);

		return array(
			'label'               => __('Update a Reviews Feed', 'reviews-feed'),
			'description'         => $this->description_text() . $this->pro_settings_note(),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $props,
				'required'             => array('feed_id', 'settings'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(array('feed_id' => array('type' => 'integer'))),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
		);
	}

	/**
	 * The edition-accurate lead sentence. Free has no filtering, so it is not
	 * promised there (SBR-577, QA 3).
	 *
	 * @return string
	 */
	private function description_text()
	{
		if (SBR_Pro_Upsell::is_free()) {
			return __('Changes one or more settings of a feed (layout, columns, spacing, header, colors, sorting). Only the keys you pass are changed. Requires AI write access to be enabled in Reviews Feed → Settings → General. Returns updated and ignored keys.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		}
		return __('Changes one or more settings of a feed (layout, columns, header, sorting, filtering). Only the keys you pass are changed. Requires AI write access to be enabled in Reviews Feed → Settings → General. Returns updated and ignored keys.', 'reviews-feed'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
	}

	/**
	 * Kept for callers outside the plugin; nothing needs a Feed Builder save any more.
	 *
	 * @deprecated 2.16.1 The feed's CSS is generated server-side (SMASH-2598).
	 *
	 * @return string Always ''.
	 */
	public static function feed_style_note()
	{
		return '';
	}

	/**
	 * Kept for callers outside the plugin; nothing needs a Feed Builder save any more.
	 *
	 * @deprecated 2.16.1 The feed's CSS is generated server-side (SMASH-2598).
	 *
	 * @param int      $feed_id
	 * @param string[] $written Keys that were written.
	 * @return string[] Always empty.
	 */
	public static function feed_style_warnings($feed_id, array $written)
	{
		return array();
	}

	/**
	 * @param mixed $input
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBR_Ability_Response::invalid_feed_id();
		}
		$requested = isset($input['settings']) ? $this->normalize_input($input['settings']) : array();

		$schema   = SBR_Settings_Schema::feed_settings();
		$writable = SBR_Settings_Schema::writable_for_update($schema);
		$clean    = array();
		$ignored  = array();
		foreach ($requested as $key => $value) {
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
		}

		if (empty($clean)) {
			return array(
				'feed_id'  => $feed_id,
				'updated'  => array(),
				'ignored'  => (object) $ignored,
				'warnings' => array(),
			);
		}

		$result = (new SBR_Feed_Settings_Service())->update_feed_settings($feed_id, $clean);
		if (is_wp_error($result)) {
			return $result;
		}

		return array_merge(
			array('feed_id' => $feed_id),
			$result,
			array(
				'ignored'  => (object) $ignored,
				'warnings' => array(),
			)
		);
	}
}
