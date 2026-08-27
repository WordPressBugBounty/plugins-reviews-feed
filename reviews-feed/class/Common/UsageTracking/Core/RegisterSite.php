<?php

/**
 * Register-site API client. Fetches site_token from the API and stores it.
 *
 * @package SmashBalloon\Reviews\Common\UsageTracking\Core
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\UsageTracking\Core;

use SmashBalloon\Reviews\Common\UsageTracking\Config;
use SmashBalloon\Reviews\Common\UsageTracking\ReporterInterface;

if (! defined('ABSPATH')) {
	exit;
}

class RegisterSite {
	/**
	 * Register the site with the API and store the returned site_token.
	 *
	 * @param ReporterInterface $reporter Plugin reporter (for slug/version).
	 * @return string|null Site token on success, null on failure.
	 */
	public function register(ReporterInterface $reporter)
	{
		$existing = get_option(Config::OPTION_SITE_TOKEN, '');
		if ('' !== $existing && is_string($existing)) {
			return $existing;
		}

		if ('' === Config::get_api_url()) {
			// Filter kill switch — no base URL, no request.
			return null;
		}

		$url  = Config::get_register_site_url();
		$body = array(
			'site_url'       => home_url(),
			'plugin_slug'    => $reporter->get_plugin_slug(),
			'plugin_version' => defined('SBRVER') ? SBRVER : '',
		);

		// Same timeout filter and clamp as Sender::send(), so register and report
		// requests behave alike. Runs in the cron send path behind a 2-minute lock, so
		// no page render waits on it.
		$timeout = (int) apply_filters('sbr_smash_usage_tracking_request_timeout', Config::REQUEST_TIMEOUT);
		$timeout = max(15, min(120, $timeout));

		$response = wp_remote_post(
			$url,
			array(
				'method'             => 'POST',
				'timeout'            => $timeout,
				// Mirrors Sender: no redirects, strict TLS, core URL validation.
				'redirection'        => 0,
				'sslverify'          => true,
				'reject_unsafe_urls' => true,
				'headers'            => array(
					'Content-Type' => 'application/json',
				),
				'body'               => wp_json_encode($body),
			)
		);

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return null;
		}

		$body_raw = wp_remote_retrieve_body($response);
		$data     = json_decode($body_raw, true);
		if (! is_array($data)) {
			return null;
		}

		$token = isset($data['site_token']) ? $data['site_token'] : (isset($data['token']) ? $data['token'] : null);
		if (! is_string($token) || ! self::is_valid_token($token)) {
			return null;
		}

		update_option(Config::OPTION_SITE_TOKEN, $token, false);
		return $token;
	}

	/**
	 * Validate a site token before persisting it.
	 *
	 * sanitize_text_field() alone caps neither length nor charset: a hostile
	 * or buggy response returning a multi-megabyte "token" would be stored,
	 * make every payload exceed MAX_PAYLOAD_BYTES, and wedge the feature
	 * permanently (send() returns 0, which is not a token-rejection code, so
	 * the poisoned token would never be dropped).
	 *
	 * @param string $token Candidate token from the API response.
	 * @return bool
	 */
	public static function is_valid_token(string $token): bool
	{
		return 1 === preg_match('/^[A-Za-z0-9._\-]{8,191}$/', $token);
	}
}
