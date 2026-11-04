<?php

namespace SmashBalloon\Reviews\Common\Utils;

/**
 * What one reviews fetch means for its source — the single decision point (SMASH-2408, Fallback Sources).
 *
 * Reads whatever the fetch returned (SBRelay::call() / RemoteRequest::fetch(), or a local provider's array) and
 * answers fetched, failed, ambiguous, a site-wide refusal, or unknown. Feed::api_request() turns that into the
 * source's stored `error` state. Rows follow the rock plan's table; each is pinned by a test.
 */
class SourceFetchOutcome
{
	public const FETCHED = 'fetched';
	public const FAILED = 'failed';
	public const AMBIGUOUS = 'ambiguous';
	public const SITE_REFUSAL = 'site_refusal';
	public const UNKNOWN = 'unknown';

	/** Our ids for failures the relay gives no id for. */
	public const ID_INVALID_RESPONSE = 'invalidResponse';
	public const ID_EMPTY_RESPONSE = 'emptyResponse';
	public const ID_PROVIDER_ERROR = 'providerError';
	public const ID_RATE_LIMITED = 'rateLimited';
	/** reviewsError with an errorCode: every upstream provider failed. Its own id, so the stored state tells it apart from a keyed API's reviewsError. */
	public const ID_UPSTREAM_ERROR = 'reviewsUpstreamError';

	public const SCOPE_SOURCE = 'source';
	public const SCOPE_RELAY = 'relay';
	public const SCOPE_CONFIG = 'config';

	/**
	 * Failures the client fixes by reconnecting: their own API key (sourceConnectionError, a keyed API's reviewsError),
	 * and a remote provider's data.error (a Facebook Graph or token error). Shown to them; the badge editor asks them
	 * to reconnect.
	 */
	public const CLIENT_FIXABLE_IDS = array('sourceConnectionError', 'reviewsError', self::ID_PROVIDER_ERROR);

	/** A reviewsError's errorCode for the client's own key or place (relay Traits/Utils.php), not an upstream failure. */
	private const CLIENT_ERROR_CODES = array('invalidKey', 'invalidLocation');

	/**
	 * The site's own outbound HTTP failed (SBRelay.php:373, a WP_Error: firewall, DNS, CA bundle). Our relay never sees
	 * these requests, so only the site owner can fix it: shown, but not a "reconnect" case (CLIENT_FIXABLE_IDS).
	 */
	public const SITE_CONNECTION_IDS = array('relayUnreachable');

	/**
	 * Relay ids a cached error entry carries when it was a provider failure; the entry keeps only its id, so
	 * reviewsError (also a keyed API's own error) is included and comes back on the next refresh if it was that.
	 */
	public const CACHED_PROVIDER_FAILURE_IDS = array('reviewsSourceNotCreated', 'reviewsError', 'relayServerError', self::ID_EMPTY_RESPONSE, self::ID_INVALID_RESPONSE);

	/** Local providers: a data.error from them is a site setup state (plugin inactive, product deleted), not an outage. */
	private const LOCAL_PROVIDERS = array('woocommerce', 'edd');

	/** Refusals that are about the site (licence, quota, token, email), never about one source. */
	private const SITE_REFUSAL_IDS = array(
		'proWeeklyUpdateLimit',
		'freeReviewsLimitExceeded',
		'quotaExceeded',
		'reviewsLicenseNotValid',
		'reviewsNotAllowed',
		'invalidToken',
		'emptyToken',
		'emailNotVerified',
	);

	/** The relay itself could not be reached or answered with no envelope: the same for every source. */
	private const RELAY_FAILURE_IDS = array('relayUnreachable', 'relayServerError');

	/**
	 * @param mixed  $response What the fetch returned.
	 * @param string $provider The source's provider: a local or Pro-only provider's error is a config state.
	 * @param bool   $is_pro   Util::sbr_is_pro(), passed in so this stays pure.
	 * @return array{outcome: string, id: string, scope: string}
	 */
	public static function classify($response, $provider = '', $is_pro = true)
	{
		if (!is_array($response)) {
			// RemoteRequest::fetch() returns '' for a source with no business id.
			return self::result(self::FAILED, self::ID_INVALID_RESPONSE);
		}

		// Any 429, whatever its shape (relay throttle, a proxy page): about the site, not this source.
		if (isset($response['http_status']) && (int) $response['http_status'] === 429) {
			return self::result(self::SITE_REFUSAL, self::ID_RATE_LIMITED);
		}

		if (!isset($response['data'])) {
			if (isset($response['id']) && is_string($response['id']) && $response['id'] !== '') {
				return self::classify_error_id($response['id'], $response);
			}

			// SBRelay's array for an empty or non-JSON 200 / an envelope with no id: nothing usable came back.
			return self::result(self::FAILED, self::ID_EMPTY_RESPONSE, self::SCOPE_RELAY);
		}

		$data = $response['data'];
		if (!is_array($data)) {
			return self::result(self::FAILED, self::ID_INVALID_RESPONSE);
		}

		// Graph / WooCommerce / EDD errors. A Facebook WP_Error arrives as `error => null`, so it lands below instead.
		if (!empty($data['error'])) {
			$config = in_array($provider, self::LOCAL_PROVIDERS, true) || ($provider === 'facebook' && !$is_pro);

			return self::result(self::FAILED, self::ID_PROVIDER_ERROR, $config ? self::SCOPE_CONFIG : self::SCOPE_SOURCE);
		}

		if (!array_key_exists('reviews', $data)) {
			return self::result(self::FAILED, self::ID_INVALID_RESPONSE);
		}

		// The plugin can't tell an honest `[]` from an upstream outage. The relay owns that: a failed WordPress.org scrape
		// falls back to the public feed, and returns reviewsError with an errorCode when both fail (SMASH-2403, SMASH-2382).
		return self::is_review_list($data['reviews'])
			? self::result(self::FETCHED, '')
			: self::result(self::FAILED, self::ID_INVALID_RESPONSE);
	}

	/**
	 * A failure on our side (relay or its providers) that the client can't act on, so it isn't shown to them: we
	 * monitor providers ourselves. The client's own API key, config states (inactive Woo/EDD) and the site failing to
	 * reach the relay at all stay visible.
	 *
	 * @param array{outcome: string, id: string, scope: string} $outcome classify().
	 * @return bool
	 */
	public static function is_provider_failure(array $outcome)
	{
		if ($outcome['outcome'] !== self::FAILED || $outcome['scope'] === self::SCOPE_CONFIG) {
			return false;
		}
		if (in_array($outcome['id'], self::SITE_CONNECTION_IDS, true)) {
			return false;
		}

		return !in_array($outcome['id'], self::CLIENT_FIXABLE_IDS, true);
	}

	/**
	 * A stored state (sources.error), or null when the column holds nothing or something this version didn't write.
	 *
	 * @param string $raw
	 * @return array{id: string, scope: string, attempts: int, since: int}|null
	 */
	public static function decode_state($raw)
	{
		$state = $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($state) || !isset($state['id'], $state['scope'])) {
			return null;
		}
		if (!is_string($state['id']) || !is_string($state['scope'])) {
			return null;
		}

		return array(
			'id'       => $state['id'],
			'scope'    => $state['scope'],
			'attempts' => isset($state['attempts']) ? (int) $state['attempts'] : 0,
			'since'    => isset($state['since']) ? (int) $state['since'] : 0,
		);
	}

	/**
	 * @param string               $raw_id   The envelope's `id`.
	 * @param array<string, mixed> $response A relay error envelope.
	 * @return array{outcome: string, id: string, scope: string}
	 */
	private static function classify_error_id($raw_id, array $response)
	{
		$id = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $raw_id);
		$status = $response['status'] ?? null;

		if (in_array($id, self::SITE_REFUSAL_IDS, true)) {
			return self::result(self::SITE_REFUSAL, $id);
		}
		if (in_array($id, self::RELAY_FAILURE_IDS, true)) {
			return self::result(self::FAILED, $id, self::SCOPE_RELAY);
		}
		if ($id === 'noReviewsFound') {
			return self::result(self::FETCHED, '');
		}
		if ($id === 'reviewsSourceNotCreated') {
			return self::result(self::FAILED, $id);
		}
		if ($id === 'reviewsError') {
			// With errorCode: Booking/Airbnb/AliExpress upstream failure, or WordPress.org's scrape and feed both failing
			// (always set there, 'unknown_error' at worst). Without, a non-numeric status is a keyed
			// API failure (legacy Google OVER_QUERY_LIMIT, Yelp key error); the rest is an empty list (ambiguous).
			if (!empty($response['errorCode'])) {
				// A keyed Google refusal also carries an errorCode (relay Utils::getInvalidApiKeyError()): the client's to fix.
				return in_array($response['errorCode'], self::CLIENT_ERROR_CODES, true)
					? self::result(self::FAILED, $id)
					: self::result(self::FAILED, self::ID_UPSTREAM_ERROR);
			}

			return is_string($status) && $status !== '' && !is_numeric($status)
				? self::result(self::FAILED, $id)
				: self::result(self::AMBIGUOUS, $id);
		}
		if ($id === 'sourceConnectionError') {
			return $status === 'Invalid API Key'
				? self::result(self::FAILED, $id)
				: self::result(self::AMBIGUOUS, $id);
		}
		// TODO(SMASH-2186): the relay can't yet tell "no reviews" from "failed" for these; ambiguous until it does.
		if (in_array($id, array('unknownError', 'sourceNotFound'), true)) {
			return self::result(self::AMBIGUOUS, $id);
		}

		return self::result(self::UNKNOWN, $id);
	}

	/**
	 * A map with a named key, e.g. the relay's error maps sent as "reviews" (`{errorId, error}`, `{error, message}`).
	 * Checks key types, not `0..n-1`, so integer keys with gaps (an unindexed array_filter) are still a list.
	 *
	 * @param mixed $reviews
	 * @return bool
	 */
	public static function is_keyed_map($reviews)
	{
		if (!is_array($reviews)) {
			return false;
		}
		foreach (array_keys($reviews) as $key) {
			if (!is_int($key)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A relay error map sent as "reviews" (refused key, WordPress.org scrape exception): never cached as a review.
	 * The one rule for every cache path (Feed::api_request(), the Pro bulk import). A map of review arrays classifies
	 * as fetched and still caches; a list with a scalar entry is invalidResponse but no map, so it caches (SMASH-1578).
	 *
	 * @param mixed $response A reviews fetch answer.
	 * @return bool
	 */
	public static function is_error_map($response)
	{
		if (!is_array($response) || !is_array($response['data'] ?? null) || !self::is_keyed_map($response['data']['reviews'] ?? null)) {
			return false;
		}

		return self::classify($response)['id'] === self::ID_INVALID_RESPONSE;
	}

	/**
	 * A list of reviews (empty included), without array_is_list(): the plugin still runs on PHP 7.4. Every value an
	 * array; a map whose values are scalars (the relay's `{error, message}` sent as "reviews") is not.
	 *
	 * @param mixed $reviews
	 * @return bool
	 */
	private static function is_review_list($reviews)
	{
		if (!is_array($reviews)) {
			return false;
		}
		foreach ($reviews as $review) {
			if (!is_array($review)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param string $outcome
	 * @param string $id
	 * @param string $scope
	 * @return array{outcome: string, id: string, scope: string}
	 */
	private static function result($outcome, $id, $scope = self::SCOPE_SOURCE)
	{
		return array('outcome' => $outcome, 'id' => $id, 'scope' => $scope);
	}
}
