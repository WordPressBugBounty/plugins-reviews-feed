<?php

/**
 * Class RemoteRequest
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;

/**
 * Summary of RemoteRequest
 */
class RemoteRequest
{
	public const BASE_URL = \SBR_RELAY_BASE_URL;

	/**
	 * Per-request memoization for relay fetches. Key = sha256 of the
	 * (endpoint, provider, place_id, type, slug, language, starsFilter,
	 * api_key) tuple — the same fields that determine the upstream response
	 * shape. Two callers within the SAME PHP request asking for the same
	 * (provider, place_id) collapse to ONE relay round-trip.
	 *
	 * Why this exists: SMASH-1360 Phase 2. The customizer-load path on
	 * `feed_customizer_fly_preview` and the Save path on `builder_update`
	 * both build a `Feed` and call `Feed::do_remote_requests()`, which can
	 * iterate the same source twice with subtly different `info` arrays
	 * (one carrying `relay_source_id`, one not). The relay-side cache (PR #84
	 * `UpstreamResponseCache`) catches these at the upstream-billable
	 * boundary, but they still cost the WP host a full HTTP roundtrip to
	 * the relay. This in-process memo skips even the WP-side roundtrip when
	 * the same data was already fetched milliseconds ago.
	 *
	 * Key INTENTIONALLY excludes `source_id` and any `info[*]` discriminators
	 * that don't reach upstream (matches the relay-side EXCLUDED_PARAMS
	 * design). The two URL shapes the wp_lhr_log captured on demo-wp2
	 * 2026-05-06 (`?source_id=N&place_id=X` vs `?place_id=X`) hash to the
	 * same memo key under this design.
	 *
	 * Lifetime: a single PHP request. Cleared automatically when PHP-FPM
	 * recycles the worker. NOT persisted across requests — that's the
	 * relay-side cache's job. Bounded at 64 keys (typical Pro fleet has
	 * 1-30 sources per feed; we never need more than that in a single
	 * request).
	 *
	 * @var array<string, mixed>
	 */
	private static $memo = [];

	/**
	 * Memo cap — defensive upper bound so a pathological feed with hundreds
	 * of sources doesn't grow the static unbounded.
	 */
	private const MEMO_MAX_KEYS = 64;

	private $provider;

	private $args;

	private $endpoint;

	/**
	 * Summary of __construct
	 * @param mixed $provider
	 * @param mixed $args
	 * @param mixed $endpoint
	 */
	public function __construct($provider, $args, $endpoint = 'reviews')
	{
		$this->provider = $provider;
		$this->args     = $args;
		$this->endpoint = $endpoint;
	}

	/**
	 * Summary of fetch
	 * @return array|string
	 */
	public function fetch()
	{
		if (empty($this->args['business'])) {
			return '';
		}

		$business = $this->args['business'];

		// Build request args - always include place_id for fallback
		// If source_id is available, include it too (preferred - encoding-immune)
		// Relay middleware will use source_id first, fall back to place_id if needed
		$args = [
			'place_id' => $business,
		];

		if (!empty($this->args['info']['relay_source_id'])) {
			$args['source_id'] = (int) $this->args['info']['relay_source_id'];
		}

		// Add additional parameters
		$args = array_merge($args, $this->buildBaseArgs());

		// SMASH-1360 Phase 2: per-request memo — collapse identical
		// (provider, place_id) lookups within a single PHP request.
		// `source_id` is deliberately EXCLUDED from the memo key because
		// it's plugin-side tracking and doesn't change what upstream returns.
		$memo_key = $this->memo_key($args);
		if ($memo_key !== null && isset(self::$memo[$memo_key])) {
			return self::$memo[$memo_key];
		}

		$settings = new SettingsManagerService();
		$relay = new SBRelay($settings);

		$response = $relay->call($this->endpoint . '/' . $this->provider, $args, 'GET', true);

		if ($memo_key !== null) {
			// Defensive cap: if the memo grew past the bound (unlikely in
			// practice — typical Pro feed has <30 sources × <2 endpoints =
			// <60 keys per request), drop the oldest half to prevent
			// unbounded growth in a long-running PHP-FPM worker.
			if (count(self::$memo) >= self::MEMO_MAX_KEYS) {
				self::$memo = array_slice(self::$memo, (int) (self::MEMO_MAX_KEYS / 2), null, true);
			}
			self::$memo[$memo_key] = $response;
		}

		return $response;
	}

	/**
	 * Build the memo key from the args that actually affect the upstream
	 * response. Returns null if the args contain non-scalar values (e.g.,
	 * an array under `info` that we'd hash unstably) — those bypass the
	 * memo and call upstream directly, mirroring the relay-side cache's
	 * conservative array-param policy.
	 *
	 * Public so unit tests can pin the contract.
	 *
	 * @param  array  $args  The post-buildBaseArgs() argument set passed to
	 *                       SBRelay::call().
	 * @return string|null   sha256 hex string, or null when args aren't
	 *                       safely hashable.
	 */
	public function memo_key(array $args): ?string
	{
		$relevant = $args;

		// Plugin-side tracking — NOT in upstream response shape.
		unset($relevant['source_id']);

		foreach ($relevant as $value) {
			if (is_array($value) || is_object($value)) {
				return null;
			}
		}

		ksort($relevant);

		return hash('sha256', $this->endpoint . '|' . $this->provider . '|' . http_build_query($relevant));
	}

	/**
	 * Test-only helper to flush the static memo between tests. PHPUnit
	 * doesn't reset class-level static state automatically; tests that
	 * exercise the memo need to call this in setUp/tearDown.
	 *
	 * Not part of the public plugin API — keep `@internal`.
	 *
	 * @internal
	 */
	public static function flush_memo(): void
	{
		self::$memo = [];
	}

	/**
	 * Build base arguments that are common to all requests
	 *
	 * @return array
	 */
	private function buildBaseArgs()
	{
		$args = [];

		if ($this->provider === 'wordpress.org') {
			$wordpressorg_args = SBR_Feed_Saver_Manager::get_place_id_wordpressorg($this->args['info']['url']);
			$args['type'] = $wordpressorg_args['type'];
			$args['slug'] = $wordpressorg_args['slug'];
		}

		if ($this->provider !== 'facebook') {
			$api_keys = get_option('sbr_apikeys', []);
			if (!empty($api_keys[$this->provider])) {
				$args['api_key'] = $api_keys[$this->provider];
			}
		} else {
			$args['api_key'] = !empty($this->args['access_token']) ? $this->args['access_token'] : '';
		}

		if (!empty($this->args['language']) && $this->args['language'] !== 'default') {
			$args['language'] = $this->args['language'];
		}

		if (!empty($this->args['starsFilter']) && $this->args['starsFilter'] !== '') {
			$args['starsFilter'] = $this->args['starsFilter'];
		}

		return $args;
	}

}
