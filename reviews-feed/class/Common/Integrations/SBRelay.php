<?php

/**
 * SBRelay API integration class
 *
 * @package SmashBalloon\Reviews\Common\Integrations
 */

namespace SmashBalloon\Reviews\Common\Integrations;

use SmashBalloon\Reviews\Common\Exceptions\RelayResponseException;
use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;

/**
 * SBRelay - Unified relay class for all review providers
 *
 * Handles API calls to the Relay API for all providers:
 * - Google, Yelp, Trustpilot, WordPress.org
 * - Airbnb, Booking.com, AliExpress, WooCommerce
 */
class SBRelay
{
	public const BASE_URL = SBR_RELAY_BASE_URL;

	/**
	 * @var string|null
	 */
	private $access_token;

	/**
	 * A list of endpoints that needs a bigger timeout
	 *
	 * @var array
	 */
	private $slow_endpoints;

	/**
	 * External provider endpoint configurations
	 *
	 * @var array
	 */
	private $provider_endpoints = [
		'airbnb' => [
			'reviews' => 'reviews/airbnb',
			'source' => 'sources/airbnb',
		],
		'booking' => [
			'reviews' => 'reviews/booking',
			'source' => 'sources/booking',
			'resolve' => 'sources/booking/resolve',
		],
		'aliexpress' => [
			'reviews' => 'reviews/aliexpress',
			'source' => 'sources/aliexpress',
		],
	];

	/**
	 * Constructor - accepts optional SettingsManagerService for flexibility
	 *
	 * @param SettingsManagerService|null $settings
	 */
	public function __construct(?SettingsManagerService $settings = null)
	{
		if ($settings) {
			$saved_settings = $settings->get_settings();
			$this->access_token = $saved_settings['access_token'] ?? '';
		} else {
			$saved_settings = get_option('sbr_settings', []);
			$this->access_token = $saved_settings['access_token'] ?? '';
		}

		$this->slow_endpoints = [
			'auth/license',
			'sources/trustpilot',
			'reviews/trustpilot',
			'sources/wordpress.org',
			'reviews/wordpress.org',
			'sources/yelp',
			'reviews/yelp',
			'sources/google',
			'reviews/google',
			'sources/airbnb',
			'reviews/airbnb',
			'sources/booking',
			'reviews/booking',
			'sources/booking/resolve',
			'sources/aliexpress',
			'reviews/aliexpress',
		];
	}

	/**
	 * Check if running in local development environment
	 *
	 * Used to disable SSL verification for local HTTPS endpoints
	 * that may use self-signed certificates.
	 *
	 * @return bool
	 */
	private function isLocalDev(): bool
	{
		return strpos(self::BASE_URL, '.ddev.site') !== false
			|| strpos(self::BASE_URL, 'localhost') !== false
			|| strpos(self::BASE_URL, '127.0.0.1') !== false
			|| strpos(self::BASE_URL, 'host.docker.internal') !== false
			|| strpos(self::BASE_URL, 'ddev-sb-relay-web') !== false;
	}

	/**
	 * Check if the relay is configured with a valid token
	 *
	 * @return bool
	 */
	public function isConfigured(): bool
	{
		return !empty($this->access_token);
	}

	/**
	 * Check if a provider is supported for callProvider method
	 *
	 * @param string $provider
	 * @return bool
	 */
	public function isProviderSupported(string $provider): bool
	{
		return isset($this->provider_endpoints[$provider]);
	}

	/**
	 * Get list of supported external providers
	 *
	 * @return array
	 */
	public function getSupportedProviders(): array
	{
		return array_keys($this->provider_endpoints);
	}

	/**
	 * Call method for external providers (Airbnb, Booking, AliExpress)
	 *
	 * @param string $provider Provider name (airbnb, booking, aliexpress)
	 * @param array $data Request parameters
	 * @param string $endpoint_type Type of endpoint (reviews, source)
	 * @param string|null $method HTTP method (default: GET)
	 * @return array
	 *
	 * @throws RelayResponseException
	 */
	public function callProvider(string $provider, array $data, string $endpoint_type = 'reviews', ?string $method = null): array
	{
		if (!isset($this->provider_endpoints[$provider])) {
			throw new RelayResponseException('Unsupported provider: ' . $provider, 400);
		}

		if (empty($this->access_token)) {
			throw new RelayResponseException('Relay API is not configured', 500);
		}

		$endpoint = $this->provider_endpoints[$provider][$endpoint_type]
			?? $this->provider_endpoints[$provider]['reviews'];

		$response = $this->call($endpoint, $data, $method ?? 'GET', true);

		// Extract data from { success: true, data: {...} } format
		if (isset($response['success']) && $response['success'] === true && isset($response['data'])) {
			return $response['data'];
		}

		return $response;
	}

	/**
	 * Make a call to the Relay API
	 *
	 * @param string $endpoint
	 * @param array $data
	 * @param string $method
	 * @param bool $require_auth
	 * @return array
	 *
	 * @throws RelayResponseException
	 */
	public function call(string $endpoint, array $data, string $method = 'POST', bool $require_auth = false): array
	{
		$headers = [
			'Accept' => 'application/json',
			'Content-Type' => 'application/json'
		];
		if (true === $require_auth) {
			$headers['Authorization'] = 'Bearer ' . $this->access_token;
		}

		switch ($method) {
			case 'GET':
				$callback = 'wp_remote_get';
				break;
			default:
				$callback = 'wp_remote_post';
				break;
		}

		if (
			isset($data['language'])
			&& (
				empty($data['language'])
				|| $data['language'] === 'default'
				|| $data['language'] === null
			)
		) {
			unset($data['language']);
		}

		$data = $this->apply_new_google_args($endpoint, $data);
		$data = $this->add_site_info($endpoint, $data);

		// For GET requests, params go in URL query string
		// For POST requests, params go in JSON body
		if ($method === 'GET') {
			$url = $this->format_url($endpoint, $data);
			$args = [
				'method' => $method,
				'headers' => $headers,
				'sslverify' => !$this->isLocalDev()
			];
		} else {
			$url = $this->format_url($endpoint);
			$args = [
				'method' => $method,
				'headers' => $headers,
				'body' => json_encode($data),
				'sslverify' => !$this->isLocalDev()
			];
		}

		if (in_array($endpoint, $this->slow_endpoints)) {
			$args['timeout'] = 120;
		}

		$response = $callback($url, $args);

		$body = !is_wp_error($response)
			? json_decode(wp_remote_retrieve_body($response), true)
			: [];

		//Log API Error
		if (
			empty($body['success']) ||
			(false === $body['success'] && !empty($body['data']['id']))
		) {
			$body['data']['endpoint'] = $url;
			SBR_Error_Handler::log_error($body['data']);
			$this->check_token_validity($body['data']);
			return !empty($body['data']) ? $body['data'] : $body;
		}

		return $body !== null ? $body : [];
	}

	/**
	 * Summary of apply_new_google_args
	 *
	 * @param mixed $endpoint
	 * @param mixed $data
	 *
	 * @return array
	 */
	public function apply_new_google_args($endpoint, $data)
	{
		if (strpos($endpoint, 'google') !== false) {
			$api_keys = get_option('sbr_apikeys', []);

			if (
				!empty($api_keys['googleApiType'])
				&& !empty($data['place_id'])
			) {
				$data['api_type'] = $api_keys['googleApiType']; // Only add google type if we are getting new source or new reviews
			}
		}

		return $data;
	}

	private function format_url($endpoint, $query = []): string
	{
		// Remove trailing slash from BASE_URL if present to avoid double slashes
		$base = rtrim(self::BASE_URL, '/');
		$url = $base . '/' . stripslashes($endpoint);

		if (!empty($query)) {
			$query_string = http_build_query($query);
			$url .= '?' . $query_string;
		}

		return $url;
	}

	/**
	 * @return string|null
	 */
	public function getAccessToken(): ?string
	{
		return $this->access_token;
	}

	/**
	 * @param string|null $access_token
	 */
	public function setAccessToken(?string $access_token): void
	{
		$this->access_token = $access_token;
	}

	private function flatten_errors($errors)
	{
		if (is_array($errors)) {
			$mapped_errors = array_column($errors, 0);

			return implode(', ', $mapped_errors);
		}
		return $errors;
	}

	public function add_site_info($endpoint, $data)
	{
		if (
			empty($data['website_url'])
			&& $endpoint !== 'auth/register'
		) {
			$data['website_url'] = get_home_url();
		}
		return $data;
	}

	public function check_token_validity($response)
	{
		if (
			isset($response['success'])
			&& $response['success'] === false
			&& !empty($response['id'])
			&& $response['id'] === 'invalidToken'
		) {
			$sbr_settings = get_option('sbr_settings', []);
			if (isset($sbr_settings['access_token'])) {
				unset($sbr_settings['access_token']);
				update_option('sbr_settings', $sbr_settings);
			}
		}
	}
}
