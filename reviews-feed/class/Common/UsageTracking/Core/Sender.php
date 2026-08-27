<?php

/**
 * Sends the usage report payload to the API.
 *
 * @package SmashBalloon\Reviews\Common\UsageTracking\Core
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\UsageTracking\Core;

use SmashBalloon\Reviews\Common\UsageTracking\Config;

if (! defined('ABSPATH')) {
	exit;
}

class Sender {
	/**
	 * Response body of the last non-2xx response, for error classification.
	 *
	 * @var string
	 */
	private $last_error_body = '';

	/**
	 * Send the usage report payload to the API.
	 * Skips send if payload exceeds max size to avoid timeouts.
	 *
	 * @param array $payload Full JSON-serializable payload.
	 * @return int HTTP status code of the response, or 0 when no request was
	 *             made (encode failure, oversize payload, transport error).
	 *             Callers treat 2xx as success; 401/403/410 mean the site
	 *             token was rejected and should be re-registered.
	 */
	public function send(array $payload): int
	{
		// Reset per send so a stale body from a prior call on the same
		// instance can never influence last_error_rejected_token().
		$this->last_error_body = '';

		if ('' === Config::get_api_url()) {
			// Filter kill switch — no base URL, no request.
			return 0;
		}

		$url  = Config::get_usage_report_url();
		$body = wp_json_encode($payload);
		if (false === $body) {
			return 0;
		}

		$max_bytes = (int) apply_filters('sbr_smash_usage_tracking_max_payload_bytes', Config::MAX_PAYLOAD_BYTES);
		if ($max_bytes > 0 && strlen($body) > $max_bytes) {
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && function_exists('error_log')) {
				error_log('[SBR Usage Tracking] Payload size ' . strlen($body) . ' exceeds max ' . $max_bytes . ', send skipped.');
			}
			return 0;
		}

		$timeout = (int) apply_filters('sbr_smash_usage_tracking_request_timeout', Config::REQUEST_TIMEOUT);
		$timeout = max(15, min(120, $timeout));

		$response = wp_remote_post(
			$url,
			array(
				'method'             => 'POST',
				'timeout'            => $timeout,
				// No redirects and strict TLS: the request body is the full
				// usage payload, so it must only ever reach the validated URL —
				// a redirect (e.g. from a MITM of the API host) could hand it
				// to an arbitrary or internal destination.
				'redirection'        => 0,
				'sslverify'          => true,
				'reject_unsafe_urls' => true,
				'httpversion'        => '1.1',
				'blocking'           => true,
				'headers'            => array(
					'Content-Type' => 'application/json',
				),
				'body'               => $body,
				'user-agent'         => 'SBR/' . (defined('SBRVER') ? SBRVER : '') . '; ' . get_bloginfo('url'),
			)
		);

		if (is_wp_error($response)) {
			return 0;
		}

		$code                  = (int) wp_remote_retrieve_response_code($response);
		$this->last_error_body = ($code < 200 || $code >= 300) ? (string) wp_remote_retrieve_body($response) : '';

		return $code;
	}

	/**
	 * Whether the last failed response rejected the site token specifically.
	 * The API returns 401/403/410 for auth failures, but a Laravel
	 * ValidationException for an unknown token arrives as 422 with a
	 * site_token entry in the errors object — distinguish that from a 422
	 * caused by a malformed payload, where re-registering would be wrong.
	 *
	 * @param int $code HTTP status code returned by send().
	 * @return bool
	 */
	public function last_error_rejected_token(int $code): bool
	{
		if (in_array($code, array( 401, 403, 410 ), true)) {
			return true;
		}
		if (422 !== $code || '' === $this->last_error_body) {
			return false;
		}
		$decoded = json_decode($this->last_error_body, true);

		return is_array($decoded) && isset($decoded['errors']['site_token']);
	}
}
