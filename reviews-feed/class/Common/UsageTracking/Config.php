<?php

/**
 * Smash Usage Tracking configuration for Reviews Feed.
 *
 * API URL and option names.
 *
 * @package SmashBalloon\Reviews\Common\UsageTracking
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\UsageTracking;

if (! defined('ABSPATH')) {
	exit;
}

class Config {
	/**
	 * Option key: send-state bookkeeping —
	 * {last_send, last_attempt, last_status, consecutive_failures}.
	 * Consent lives in sbr_settings['usagetracking'] (see is_enabled()).
	 */
	const OPTION_TRACKING = 'sbr_smash_usage_tracking';

	/**
	 * Option key: site token returned by the API.
	 */
	const OPTION_SITE_TOKEN = 'sbr_smash_usage_tracking_site_token';

	/**
	 * Option key: schedule metadata written by earlier builds of this
	 * feature. No longer written or read — retained only so uninstall and
	 * the opt-out purge can delete it from sites that stored it.
	 */
	const OPTION_SCHEDULE = 'sbr_smash_usage_tracking_schedule';

	/**
	 * Option key: dates when plugin was active (Y-m-d), for days_active metric.
	 */
	const OPTION_ACTIVE_DATES = 'sbr_smash_usage_active_dates';

	/**
	 * Option key: last N session durations in seconds, for session_duration metric.
	 */
	const OPTION_SESSION_DURATIONS = 'sbr_smash_usage_session_durations';

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'sbr_smash_usage_tracking_cron';

	/**
	 * Max request timeout in seconds for usage report (large payloads).
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Max payload size in bytes before send is skipped (default 2MB).
	 */
	const MAX_PAYLOAD_BYTES = 2097152;

	/**
	 * Register-site endpoint path (relative to API base).
	 */
	const REGISTER_SITE_PATH = '/v1/register-site';

	/**
	 * Usage report endpoint path (relative to API base).
	 */
	const USAGE_REPORT_PATH = '/v1/usage-report';

	/**
	 * Get the API base URL (filterable, validated).
	 *
	 * The filter is a kill switch (return '' to disable all requests) and a
	 * dev override, but its output is constrained: both outbound requests
	 * carry the full usage payload, so an unvalidated filtered URL would let
	 * any other plugin silently re-route that data, and a cleartext scheme
	 * would expose it in transit. A filtered value must be https on an
	 * allowlisted host or it is discarded in favor of the constant.
	 *
	 * @return string Base URL, or '' when tracking requests are disabled.
	 */
	public static function get_api_url()
	{
		$default = defined('SBR_SMASH_USAGE_TRACKING_API_URL') ? (string) SBR_SMASH_USAGE_TRACKING_API_URL : '';
		$url     = (string) apply_filters('sbr_smash_usage_tracking_api_url', $default);

		if ($url === $default) {
			return $url;
		}
		if ('' === $url) {
			// Explicit kill switch — callers skip the request entirely.
			return '';
		}

		return self::is_allowed_api_url($url) ? $url : $default;
	}

	/**
	 * Whether a filtered API URL may be used: https, on smashballoon.com or
	 * the host the SBR_SMASH_USAGE_TRACKING_API_URL constant points at.
	 *
	 * @param string $url Candidate URL from the filter.
	 * @return bool
	 */
	private static function is_allowed_api_url($url)
	{
		if ('https' !== wp_parse_url($url, PHP_URL_SCHEME)) {
			return false;
		}
		$host = wp_parse_url($url, PHP_URL_HOST);
		if (! is_string($host) || '' === $host) {
			return false;
		}
		$host = strtolower($host);

		$allowed = array( 'smashballoon.com' );
		if (defined('SBR_SMASH_USAGE_TRACKING_API_URL')) {
			$constant_host = wp_parse_url((string) SBR_SMASH_USAGE_TRACKING_API_URL, PHP_URL_HOST);
			if (is_string($constant_host) && '' !== $constant_host) {
				$allowed[] = strtolower($constant_host);
			}
		}

		foreach ($allowed as $allowed_host) {
			if ($host === $allowed_host || substr($host, -(strlen($allowed_host) + 1)) === '.' . $allowed_host) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get full URL for register-site endpoint.
	 *
	 * @return string
	 */
	public static function get_register_site_url()
	{
		return rtrim(self::get_api_url(), '/') . self::REGISTER_SITE_PATH;
	}

	/**
	 * Get full URL for usage-report endpoint.
	 *
	 * @return string
	 */
	public static function get_usage_report_url()
	{
		return rtrim(self::get_api_url(), '/') . self::USAGE_REPORT_PATH;
	}

	/**
	 * Check if tracking is enabled.
	 *
	 * Consent is stored in sbr_settings['usagetracking']. When the key is
	 * absent the default is per edition, a deliberate product decision
	 * (SMASH-1130, signed off 2026-08-05):
	 *
	 * - Pro: enabled. The legacy tracker only ever booted on Pro and the
	 *   Advanced-tab toggle has always displayed as ON there, so default-on
	 *   matches what Pro customers have been shown.
	 * - Free: disabled. Free installs have never transmitted usage data
	 *   (the legacy service was registered by the Pro container only), so
	 *   they require explicit opt-in via the toggle.
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		$settings = get_option('sbr_settings', array());
		if (is_array($settings) && array_key_exists('usagetracking', $settings)) {
			return (bool) $settings['usagetracking'];
		}
		return \SmashBalloon\Reviews\Common\Util::sbr_is_pro();
	}
}
