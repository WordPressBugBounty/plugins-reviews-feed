<?php

/**
 * Namespace SmashBalloon\Reviews\Common\Integrations
 */

namespace SmashBalloon\Reviews\Common\Integrations;

/**
 * Class to check if the GDPR is enabled
 * Hide+Show images/avatars depending on the GDPR settings
 */
class SBR_GDPR
{
	public static function doing_gdpr()
	{
		$settings = get_option('sbr_settings');

		$gdpr = !empty($settings['gdpr']) ? $settings['gdpr'] : 'auto';

		if ($gdpr === 'auto') {
			return self::gdpr_plugins_active()  !== false;
		}

		return $gdpr === 'yes' ? true : false;
	}

	/**
	 * Check if a GDPR plugin is active
	 *
	 * @return string|false
	 */
	public static function gdpr_plugins_active()
	{
		// WPConsent by the WPConsent team
		if (function_exists('WPConsent')) {
			return 'WPConsent by the WPConsent team';
		}

		// Real Cookie Banner by devowl.io
		if (defined('RCB_ROOT_SLUG')) {
			return 'Real Cookie Banner by devowl.io';
		}

		// GDPR Cookie Compliance by Moove Agency
		if (function_exists('gdpr_cookie_is_accepted')) {
			return 'GDPR Cookie Compliance by Moove Agency';
		}

		// Cookie Notice by dFactory
		if (class_exists('Cookie_Notice')) {
			return 'Cookie Notice by dFactory';
		}

		// GDPR Cookie Consent by WebToffee
		if (
			function_exists('run_cookie_law_info')
			|| class_exists('Cookie_Law_Info')
		) {
			return 'GDPR Cookie Consent by WebToffee';
		}

		// CookieYes | GDPR Cookie Consent by CookieYes
		if (defined('CKY_APP_ASSETS_URL')) {
			return 'CookieYes | GDPR Cookie Consent by CookieYes';
		}

		// Cookiebot by Cybot A/S
		if (class_exists('Cookiebot_WP')) {
			return 'Cookiebot by Cybot A/S';
		}

		// Complianz by Really Simple Plugins
		if (class_exists('COMPLIANZ')) {
			return 'Complianz by Really Simple Plugins';
		}

		// Borlabs Cookie by Borlabs
		if (
			function_exists('BorlabsCookieHelper')
			|| (defined('BORLABS_COOKIE_VERSION')
			&& version_compare(BORLABS_COOKIE_VERSION, '3.0', '>='))
		) {
			return 'Borlabs Cookie by Borlabs';
		}

		// SBR Feed Builder
		if (
			is_admin()
			&& !empty($_GET['page'])
			&& $_GET['page'] === 'sbr'
		) {
			return false;
		}

		return false;
	}
}
