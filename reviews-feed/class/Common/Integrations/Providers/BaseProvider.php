<?php

/**
 * Base Provider
 */

namespace SmashBalloon\Reviews\Common\Integrations\Providers;

if (! defined('ABSPATH')) {
	exit;
}

use SmashBalloon\Reviews\Common\Exceptions\RelayResponseException;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Utils\AjaxUtil;
use Smashballoon\Stubs\Services\ServiceProvider;

abstract class BaseProvider extends ServiceProvider
{
	protected $name;
	protected $endpoint;
	protected $sources_endpoint;
	protected $sources_remove;
	private $relay;
	protected $uses_connect = false;
	protected $friendly_name;

	/**
	 * Characters a provider id may legitimately contain.
	 *
	 * RFC 3986's unreserved and sub-delimiter set minus `&` and the quote and
	 * angle characters. It has to admit a URL, not just a slug: Trustpilot's
	 * id *is* its review-page URL (the relay returns the schema.org
	 * `LocalBusiness.url` as the source id) and wordpress.org's is
	 * `type/slug`, so `:` and `/` are legitimate. The Woo and EDD composite
	 * ids never reach here — those providers extend ServiceProvider and
	 * register their own routes — but the set covers them anyway. What it refuses is anything
	 * that could contribute markup — `< > " '` and backtick — plus `&`, which
	 * no known id needs and which keeps entity-encoded payloads out too, and
	 * whitespace and control characters.
	 *
	 * A format allow-list per provider would buy nothing: the relay validates
	 * the id itself, and the property needed here is only that an id cannot
	 * carry markup into the error log.
	 */
	/** Recursion cap for the value sanitisers. PHP caps request nesting at 64 and JSON at 512. */
	private const MAX_SANITISE_DEPTH = 32;

	private const PLACE_ID_PATTERN = '/\A[A-Za-z0-9._~:\/?#\[\]@!$()*+,;=%-]{1,255}\z/';

	public function __construct(SBRelay $relay)
	{
		$this->relay = $relay;
		$this->endpoint = 'reviews/' . $this->name;
		$this->sources_endpoint = 'sources/' . $this->name;
		$this->sources_remove = 'source/remove';
	}

	public function register()
	{
		add_action('rest_api_init', [$this, 'registerRestEndpoint']);
		add_filter('sbr_supported_providers', [$this, 'registerProvider']);
	}

	/**
	 * @throws RelayResponseException
	 */
	public function getAllReviews($args = []): void
	{
		$params = is_object($args) && method_exists($args, 'get_params') ? $args->get_params() : (array) $args;

		// SMASH-2031: a relay error envelope is persisted to `sbr_errors`, and
		// it can carry the id back — on this route via the upstream provider's
		// own error text (per the Wordfence report), on `sources/*` via the
		// relay itself (CanSendSourcesResponse). Refuse a malformed id here so
		// nothing attacker-shaped is forwarded either way. The storage and sink
		// guards do not depend on which mechanism echoes.
		if (isset($params['place_id']) && !self::is_valid_place_id($params['place_id'])) {
			AjaxUtil::send_json_error(__('Invalid source ID.', 'reviews-feed'), 400);
			return;
		}

		try {
			$response = $this->relay->call($this->endpoint, self::sanitize_request_params($params), 'GET', true);
			wp_send_json($response);
		} catch (RelayResponseException $exception) {
			AjaxUtil::send_json_error($exception->getMessage(), $exception->getCode());
		}
	}

	/**
	 * Whether a place_id is shaped like an id at all.
	 *
	 * @param mixed $place_id Raw request parameter, so any type may arrive.
	 */
	protected static function is_valid_place_id($place_id): bool
	{
		return is_string($place_id) && preg_match(self::PLACE_ID_PATTERN, $place_id) === 1;
	}

	/**
	 * Strip markup out of every string heading to the relay.
	 *
	 * Keeps non-strings as they are so numeric and boolean params survive the
	 * round trip unchanged. wp_strip_all_tags() rather than
	 * sanitize_text_field(), which also deletes every `%XX` sequence and would
	 * silently rewrite a percent-encoded parameter value.
	 */
	protected static function sanitize_request_params(array $params, int $depth = 0): array
	{
		// Bounded, because the input is a REST request. PHP's own limits already
		// cap this — max_input_nesting_level is 64 and json_decode() refuses past
		// 512 (both measured) — so 32 is inside what can arrive rather than a
		// guess, and a structure deeper than that is dropped instead of recursed.
		if ($depth >= self::MAX_SANITISE_DEPTH) {
			return array();
		}

		foreach ($params as $key => $value) {
			if (is_array($value)) {
				$params[$key] = self::sanitize_request_params($value, $depth + 1);
			} elseif (is_string($value)) {
				$params[$key] = wp_strip_all_tags($value);
			}
		}

		return $params;
	}

	/**
	 * @throws RelayResponseException
	 */
	public function getSourcesInfo($args = [])
	{
		// SMASH-2031: `sources/*` is the path where the relay echoes the
		// submitted id straight back (CanSendSourcesResponse), so strip markup
		// here too. No place_id allow-list on this one — it legitimately sends
		// '' for the Trustpilot key probe (SMASH-1973) and 'XXX' as a sentinel.
		$response = $this->relay->call($this->sources_endpoint, self::sanitize_request_params((array) $args), 'GET', true);
		if (isset($response['success']) && false === $response['success']) {
			return wp_json_encode($response);
		}

		if (isset($response['data']) && $response['data']) {
			return wp_json_encode($response['data']);
		}
		return false;
	}

	public function registerRestEndpoint()
	{
		register_rest_route(SBR_REST_DOMAIN, '/' . $this->endpoint, array(
			'methods' => 'GET',
			'callback' => [$this, 'getAllReviews'],
			'permission_callback' => [$this, 'check_rest_permission']
		));
	}

	/**
	 * Check REST API permission
	 *
	 * Requires user to be logged in with appropriate capabilities. Same gate the
	 * EDD and WooCommerce providers have always had on their own routes; this
	 * one carried `__return_true` until SMASH-2031, which let an unauthenticated
	 * visitor drive relay calls and poison the error log.
	 *
	 * Not byte-identical to theirs: this goes through
	 * `sbr_current_user_can('manage_reviews_feed_options')`, which resolves the
	 * same manage_reviews_feed_options-or-manage_options pair but also honours
	 * the `sbr_settings_pages_capability` filter the rest of the plugin's admin
	 * surface respects. Identical on a default site; a site that filters that
	 * capability gets one answer here instead of two.
	 *
	 * The endpoint is consumed only from the admin builder via
	 * registerProvider(), so no front-end caller loses access.
	 *
	 * @return bool|\WP_Error True if permission granted, WP_Error otherwise
	 */
	public function check_rest_permission()
	{
		if (! is_user_logged_in()) {
			return new \WP_Error(
				'rest_forbidden',
				__('You must be logged in to access this endpoint.', 'reviews-feed'),
				array( 'status' => 401 )
			);
		}

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			return new \WP_Error(
				'rest_forbidden',
				__('You do not have permission to access this endpoint.', 'reviews-feed'),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function registerProvider($providers)
	{
		$providers[] = [
			'name' => $this->name,
			'endpoint' => get_rest_url(null, SBR_REST_DOMAIN . '/' . $this->endpoint),
			'uses_connect' => $this->uses_connect,
			'friendly_name' => $this->friendly_name
		];

		return $providers;
	}

	/**
	 * @throws RelayResponseException
	 */
	public function removeSource($args = [])
	{
		// Through the same sanitiser as getAllReviews() and getSourcesInfo().
		// It was the one relay call still forwarding raw params: not exploitable
		// today because SBRelay sanitises the error envelope before persisting
		// it, but the whole point of doing this at the caller is not to depend
		// on that staying true.
		$response = $this->relay->call($this->sources_remove, self::sanitize_request_params((array) $args), 'POST', true);
		if (isset($response)) {
			return wp_json_encode($response);
		}
		return false;
	}
}
