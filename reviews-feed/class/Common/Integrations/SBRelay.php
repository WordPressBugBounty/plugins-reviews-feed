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
use SmashBalloon\Reviews\Common\Support\UrlNormalization;

/**
 * SBRelay - Unified relay class for all review providers
 *
 * Handles API calls to the Relay API for all providers:
 * - Google, Yelp, Trustpilot, WordPress.org
 * - Airbnb, Booking.com, AliExpress, WooCommerce
 */
class SBRelay
{
	use UrlNormalization;

	public const BASE_URL = SBR_RELAY_BASE_URL;

	/**
	 * @var string|null
	 */
	private $access_token;

	/**
	 * Per-instance one-shot guard for `reverify_token_via_register()`.
	 * Multiple invalidToken responses on the same SBRelay instance only
	 * trigger one reverify round-trip; subsequent ones treat the token
	 * as already-confirmed (whatever state it ended in).
	 *
	 * Multiple SBRelay instances within a single PHP request can each
	 * trigger their own reverify, but each round-trip hits PR #84's relay-side
	 * cache (~10ms warm), and each successive instance reads the
	 * post-reverify sbr_settings so its first-time reverify is correct
	 * for the current state. Trade-off: bounded N-per-request (where N is
	 * the number of SBRelay constructions, typically 1-3) instead of
	 * strictly 1-per-request via a class-level static — the latter would
	 * leak state between PHPUnit test cases.
	 *
	 * Note: untyped property declaration. The codebase's `phpcs.xml` sets
	 * `testVersion = "7.1-"` so PHPCompatibility flags PHP 7.4+
	 * typed-property syntax even though composer.json + readme declare
	 * PHP >= 7.4. Aligning the phpcs target with composer is a separate
	 * follow-up; for now the codebase is uniformly on docblock-only
	 * properties to satisfy the existing CI.
	 *
	 * @var bool
	 */
	private $reverify_attempted = false;

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
		// Detect site migration (WP Engine staging→live push, DB clone, domain
		// rename) BEFORE loading the access_token — so that if we've moved sites,
		// the stale token gets wiped and this instance starts unconfigured.
		// See SMASH-1281.
		$this->detect_site_migration();

		if ($settings) {
			$saved_settings = $settings->get_settings();
			$this->access_token = $saved_settings['access_token'] ?? '';
		} else {
			$saved_settings = get_option('sbr_settings', []);
			if (!is_array($saved_settings)) {
				$saved_settings = [];
			}
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
	 * Re-read the stored access_token from `sbr_settings` into this instance.
	 *
	 * Required when an external code path (RegisterWebsiteRoutine running via
	 * admin_init, silent_reactivation during migration recovery, cron handlers)
	 * persisted a fresh token to wp_options AFTER this SBRelay was constructed.
	 * Without this refresh the in-memory `$access_token` stays at whatever was
	 * loaded at construction (typically empty if the constructor ran before the
	 * register-routine wrote the token), and the next authenticated call sends
	 * an empty/old Bearer → 401 → customer must click twice. See SMASH-1274 LR3.
	 *
	 * Callers that know they're racing with a parallel rotation (license
	 * activate/deactivate handlers, post-migration recovery flows) should call
	 * this immediately before `call(..., require_auth: true)`. Idempotent and
	 * safe to call repeatedly; non-array sbr_settings is treated as a no-op
	 * (keeps existing in-memory token rather than wiping it on corrupted state).
	 */
	public function refresh_access_token_from_settings(): void
	{
		if (!function_exists('get_option')) {
			return;
		}
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			// Corrupted state — keep current in-memory token rather than wipe;
			// the rest of the recovery flow handles non-array settings already.
			return;
		}
		$token = $settings['access_token'] ?? '';
		$this->access_token = is_string($token) ? $token : '';
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
			// Re-read the persisted token from `sbr_settings` before every
			// authenticated call. This SBRelay instance is DI'd at admin_init,
			// BEFORE RegisterWebsiteRoutine (also wired to admin_init) writes
			// the canonical token. Without this refresh the in-memory
			// `$this->access_token` stays at its construction-time value —
			// typically empty for cold starts, or stale after a server-side
			// rotation — sending the wrong Bearer to the relay → 401
			// `invalidToken` → spurious recovery / customer-visible error
			// (e.g. `email/check-status` and the email-verify confirm-link
			// path observed during SMASH-1274 staging QA).
			//
			// Generalizes the round-15 caller-side refresh
			// (LicenseManagerService::ajax_activate_license / ajax_deactivate_license)
			// to ALL authenticated callers in one place. `get_option` is
			// hot-cached by WP autoload so the cost is microseconds. Existing
			// caller-side refreshes remain as defensive duplicates and are
			// no-ops once the in-memory token matches sbr_settings.
			//
			// Bound math: zero new requests. The refresh is a pure local read
			// (no HTTP, no recursion path), and it eliminates the spurious
			// 401 → reverify_register cycle that the in-memory race used to
			// trigger — so the typical authenticated call drops from
			// 3 requests (call → 401 → register → retry) to 1 request.
			$this->refresh_access_token_from_settings();
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

		// GET requests: params go in URL query string (HTTP standard)
		// POST requests: params go in JSON body
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
			// Defensive: a malformed/proxy-truncated error response may lack
			// the `data` key entirely. Initialize it before mutating so we
			// don't trigger an "Undefined index" notice and feed `null`
			// into the error handler.
			if (!isset($body['data']) || !is_array($body['data'])) {
				$body['data'] = [];
			}
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
		if (!is_array($response)) {
			return;
		}

		// Accept either the full relay body (with `data` sub-array) or the data
		// sub-array directly. `SBRelay::call()` passes `$body['data']` today;
		// external callers may pass the whole body. Normalize to the inner
		// payload — this is what carries `id`, `success`, and `discriminator`.
		if (
			isset($response['data']) && is_array($response['data'])
			&& isset($response['data']['id'])
		) {
			$response = $response['data'];
		}

		if (
			!isset($response['success'])
			|| $response['success'] !== false
			|| empty($response['id'])
			|| $response['id'] !== 'invalidToken'
		) {
			return;
		}

		// When the relay signals that the token is valid for SOME user but the
		// URL doesn't match (discriminator: url_mismatch), the customer's site
		// was migrated. Clearing only the access_token leaves stale email /
		// license state that blocks re-registration — support has to manually
		// delete_option('sbr_settings'). Do a broader reset instead.
		// Relay-side fix: SMASH-1274 PR #75 (commit 6ac5d61).
		$is_migration = !empty($response['discriminator'])
			&& $response['discriminator'] === 'url_mismatch';

		// Migration confirmed by relay (url_mismatch discriminator) — wipe
		// immediately. No reverify needed: the relay just told us the token
		// belongs to a different URL, so the site moved domains. This path
		// drives attempt_silent_reactivation() with full state cleanup.
		if ($is_migration) {
			// Clear in-memory token BEFORE the reset_registration_state(true)
			// call, NOT after. reset_registration_state(true) can run
			// attempt_silent_reactivation → persist_fresh_registration, which
			// sets $this->access_token to a freshly minted token for the
			// remainder of this request. Clearing afterwards would clobber
			// that successful silent reactivation and leave subsequent
			// SBRelay->call() invocations in the same page load
			// unauthenticated → cascading invalidToken 401s. Clearing first
			// prevents the stale Bearer from leaking out during the small
			// window between wipe-of-options and silent-reactivation, while
			// preserving any token the reactivation establishes downstream.
			$this->access_token = null;
			$this->reset_registration_state(true);

			return;
		}

		// Bare invalidToken — could be a real revocation, but could also be
		// transient (relay deployment 401, WAF, stale cache, parallel-call race,
		// load balancer mid-failover). Don't wipe on a single 401: re-verify
		// via /auth/register first. PR #84 cache replay makes this near-free
		// (~10ms on warm cache). Only wipe if the reverify itself can't
		// confirm a working token.
		if (! $this->reverify_token_via_register()) {
			$this->reset_registration_state(false);
			// Same in-memory cleanup as above — see comment in migration path.
			$this->access_token = null;
		}
	}

	/**
	 * Confirm or recover the access_token via an idempotent /auth/register call.
	 *
	 * Prevents the "false-positive 401 → wipe → register loop" pattern observed
	 * on sites with broken transient storage where the 5-minute cooldown gate
	 * doesn't engage. Instead of trusting a single invalidToken response, ask
	 * the relay what the canonical token for this URL is.
	 *
	 * Outcomes:
	 *   - Same token returned     → 401 was a false positive. Keep token. Return true.
	 *   - Different token returned → token was rotated server-side. Silently
	 *                                 update access_token + website_url; license
	 *                                 / email state preserved (URL unchanged).
	 *                                 Return true.
	 *   - No usable response       → reverify failed; caller falls back to wipe.
	 *                                 Return false.
	 *
	 * Per-instance one-shot via the `$reverify_attempted` instance flag —
	 * multiple invalidToken responses on the same SBRelay instance trigger
	 * at most one reverify round-trip. Multiple SBRelay constructions
	 * within a single request CAN each trigger their own reverify; that's
	 * acceptable because each one hits PR #84's cache (~10ms warm) and
	 * each successive instance reads the post-reverify sbr_settings so
	 * its first 401 evaluation is fresh and correct. See the property
	 * docblock for the rationale on per-instance vs per-request scope.
	 */
	private function reverify_token_via_register(): bool
	{
		if ($this->reverify_attempted) {
			// Already reverified once on this SBRelay instance. Treat the
			// token as already-confirmed (whatever state it ended in) —
			// don't wipe on subsequent 401s. A fresh SBRelay instantiated
			// later in the same request reads the post-reverify
			// sbr_settings, so its first-time reverify is correct.
			return true;
		}
		$this->reverify_attempted = true;

		if (! function_exists('get_home_url')) {
			return false;
		}
		$url = (string) get_home_url();
		if ($url === '') {
			return false;
		}

		// WP HTTP helpers guard. SBRelay::call() depends on wp_remote_post +
		// is_wp_error + wp_remote_retrieve_body. In non-WP contexts (CLI
		// loaded outside WP, static analysis scaffolding, partial bootstraps)
		// those may be undefined — calling through would fatal. Bail to
		// caller's wipe-fallback path so the calling SBRelay path stays
		// safe even in unusual hosting contexts.
		if (
			! function_exists('wp_remote_post')
			|| ! function_exists('is_wp_error')
			|| ! function_exists('wp_remote_retrieve_body')
		) {
			return false;
		}

		// Idempotent register — relay returns the existing token for existing
		// users (res_code 201, success:true). PR #84 serves this from cache
		// after the first hit. Auth not required.
		// Wrapped in try-catch matching the pattern used by silent_reregister:
		// any exception surfaced by call() (RelayResponseException, transport
		// errors) becomes a clean reverify-failed signal so the caller can fall
		// back to the old wipe path instead of bubbling up.
		try {
			// SMASH-1585 — send the bearer (require_auth=true). On a bare 401 the
			// stored token is still present (this path does NOT wipe), so the
			// relay receives the current token and, when it recognizes it
			// (bearer-aware register, sb-relay SMASH-1585), rebinds the new URL
			// onto the SAME user instead of forking a fresh free account — which
			// is what silently orphaned the paid license on migrated sites.
			// require_auth=true only adds the Authorization header from a local
			// sbr_settings read: ZERO extra requests, and the one-shot
			// $reverify_attempted guard above still caps this to a single
			// register round-trip per instance, so no 401→register loop. On an
			// older relay the header is simply ignored (register is
			// unauthenticated), so this is safe to ship ahead of the relay.
			$response = $this->call('auth/register', ['url' => $url], 'POST', true);
		} catch (\Throwable $e) {
			return false;
		}
		if (! is_array($response)) {
			return false;
		}
		// Read nested-then-flat token defensively. Pre-PHP 8 returned null
		// silently when `data` was null/scalar; PHP 8+ raises TypeError on
		// `null['token']` / `"string"['token']`. is_array guard protects
		// the nested read; flat fallback covers the new-user response shape
		// (token at top level only).
		$nested = (isset($response['data']) && is_array($response['data']))
			? ($response['data']['token'] ?? null)
			: null;
		$relay_token = $nested ?? $response['token'] ?? null;
		if (! is_string($relay_token) || $relay_token === '') {
			return false;
		}

		if ($relay_token === $this->access_token) {
			// Same token — original 401 was a false positive. Keep it.
			return true;
		}

		// Token rotated server-side (DB cleanup, manual reset, etc.). Save the
		// new value without a full reset — URL didn't change, so license + email
		// state remains valid for this site. Reuse the existing helper so the
		// "fresh registration persisted" code path stays single-source.
		$this->persist_fresh_registration($relay_token, $url);

		return true;
	}

	/**
	 * Detect a site migration by comparing the current home URL against the
	 * URL stored at registration. If they differ (after normalization), reset
	 * the registration state proactively — BEFORE any relay call is made —
	 * so the plugin doesn't round-trip through a 401 to discover the mismatch.
	 *
	 * No-op when the plugin has never registered (no stored url or no token),
	 * or when `get_home_url()` returns empty.
	 *
	 * @return bool  True when a migration was detected and state was reset.
	 */
	public function detect_site_migration()
	{
		if (!function_exists('get_option') || !function_exists('get_home_url')) {
			return false;
		}
		$settings = get_option('sbr_settings', []);
		// Defensive: the option may be corrupted (string/bool) on old/weird installs;
		// unconditionally reading array offsets on non-array in PHP 8+ is a fatal TypeError.
		if (!is_array($settings)) {
			return false;
		}
		if (empty($settings['website_url']) || empty($settings['access_token'])) {
			return false;
		}
		$current = get_home_url();
		if (empty($current)) {
			return false;
		}

		// Host-only compare — same host = same site. Covers http/https variance
		// AND WPML/Polylang language-path oscillation (/pt-br/, /en/ etc) that
		// produced the residual traffic pattern after the scheme-agnostic fix.
		// Migration is a domain change, not a subpath change.
		if ($this->normalize_url_host_only($current) === $this->normalize_url_host_only((string) $settings['website_url'])) {
			return false;
		}

		$this->reset_registration_state(true);
		$this->access_token = null;
		return true;
	}

	/**
	 * Clear relay-binding state from sbr_settings (and, on migration, the
	 * separate `sbr_email_verification` option).
	 *
	 * Default (non-migration) behavior mirrors the original check_token_validity:
	 * only the access_token is removed.
	 *
	 * On a detected migration, also removes state bound to the OLD site:
	 *   - `sbr_settings.website_url` / `license_info` / `license_status`
	 *   - the `sbr_email_verification` option in full (where verification state
	 *     actually lives — see `Common\Utils\EmailVerification::$email_opt_name`).
	 *     Clearing this is what forces the plugin to re-prompt verification on
	 *     the next boot, not touching `sbr_settings.email_verified_at` (which
	 *     is not the load-bearing flag for the verification check).
	 *
	 * Preserves `sbr_settings.email` and `sbr_settings.license_key` (the
	 * customer's credentials) so they don't have to re-enter them.
	 *
	 * Additionally, on migration, attempts a silent re-registration and license
	 * re-activation so users whose sites were moved don't see the "Activate"
	 * screen after updating. Gated behind a 24-hour transient to prevent
	 * license-slot churn on staging<->prod oscillation. See
	 * `attempt_silent_reactivation()` for the full design + edge cases.
	 *
	 * @param bool $migration_detected
	 */
	private function reset_registration_state($migration_detected = false)
	{
		$sbr_settings = get_option('sbr_settings', []);
		if (!is_array($sbr_settings)) {
			$sbr_settings = [];
		}

		// Capture these BEFORE the wipe so the silent re-activation path +
		// admin notice have what they need.
		$preserved_license_key = isset($sbr_settings['license_key']) && is_string($sbr_settings['license_key'])
			? $sbr_settings['license_key']
			: '';
		$old_website_url = isset($sbr_settings['website_url']) && is_string($sbr_settings['website_url'])
			? $sbr_settings['website_url']
			: '';

		unset($sbr_settings['access_token']);

		if ($migration_detected) {
			unset(
				$sbr_settings['email_verified_at'],  // legacy/soft flag — clear for BC with older Pro builds that do read it
				$sbr_settings['website_url'],
				$sbr_settings['license_info'],
				$sbr_settings['license_status']
			);
		}

		update_option('sbr_settings', $sbr_settings);

		// Clear the verification-state option — this is where EmailVerification
		// actually looks for verified credentials (`check_verified()` reads the
		// `sbr_email_verification` option, not `sbr_settings.email_verified_at`).
		// Without this step, the plugin would skip re-verification after a
		// migration despite the access_token being wiped.
		if ($migration_detected && function_exists('delete_option')) {
			delete_option('sbr_email_verification');
		}

		// Attempt silent re-activation — best-effort. Falls through to the
		// manual "Activate" screen on any failure. Runs only on migration
		// (not the access-token-only wipe path).
		if ($migration_detected && $preserved_license_key !== '') {
			$this->attempt_silent_reactivation($preserved_license_key, $old_website_url);
		}
	}

	/**
	 * Attempt a silent re-registration + license re-activation after a
	 * migration, so the user doesn't see the "Activate" screen if their
	 * site URL just changed (WP Engine push, domain rename, backup restore).
	 *
	 * Design decisions (all deliberate):
	 *
	 *   - **Rate-limited to once per 24h** via a transient. Without this,
	 *     a staging<->prod oscillator would fire the detect + silent
	 *     reactivate loop on every page load at each URL, burning EDD
	 *     activation slots. The transient is set FIRST (before any relay
	 *     work) so even a fatal mid-attempt still counts against the quota.
	 *
	 *   - **Best-effort, no exceptions.** Any failure (relay unreachable,
	 *     EDD over-limit, expired license, revoked key) leaves the plugin
	 *     in the already-wiped state, which renders as the "Activate"
	 *     screen — the existing manual fallback. We never regress UX.
	 *
	 *   - **Pro-only.** The `SBR_PLUGIN_NAME` + `SBR_PRODUCT_ID` constants
	 *     are only defined in the Pro bootstrap. Free installs skip here
	 *     (and wouldn't have a `license_key` to preserve anyway).
	 *
	 *   - **Admin notice on success.** Sets a separate transient consumed
	 *     by `MigrationReactivationNotice` so users see a one-time,
	 *     dismissible confirmation plus a pointer to their account page
	 *     (where they can free up the OLD site's activation slot if they
	 *     truly migrated and don't need it anymore).
	 *
	 * @param string $license_key     Preserved from before the wipe.
	 * @param string $old_website_url Preserved from before the wipe — surfaced in the admin notice.
	 * @return void
	 */
	private function attempt_silent_reactivation($license_key, $old_website_url = '')
	{
		// Preconditions: Pro-only constants defined, license key + home URL present.
		if (!$this->silent_reactivation_preconditions_met($license_key)) {
			return;
		}
		$new_url = (string) get_home_url();

		// Rate limit — set BEFORE any work so even a fatal counts against the quota.
		if (!$this->claim_silent_reactivation_quota()) {
			return;
		}

		// Step 1: re-register to obtain a fresh access_token for the current URL.
		$new_token = $this->silent_reregister($new_url);
		if ($new_token === null) {
			return;
		}
		$this->persist_fresh_registration($new_token, $new_url);

		// Step 2: activate the preserved license against the new URL.
		$license_payload = $this->silent_activate_license($license_key, $new_url);
		if ($license_payload === null) {
			return;
		}

		$this->persist_license_state($license_payload);
		$this->queue_silent_reactivation_notice($old_website_url, $new_url);
	}

	/**
	 * Guard: silent re-activation needs the Pro constants, a non-empty
	 * license key, and a non-empty home URL. Keeps the main flow readable.
	 */
	private function silent_reactivation_preconditions_met($license_key)
	{
		if (!defined('SBR_PLUGIN_NAME') || !defined('SBR_PRODUCT_ID')) {
			return false;
		}
		if (!is_string($license_key) || $license_key === '') {
			return false;
		}
		if (!function_exists('get_home_url')) {
			return false;
		}
		return ((string) get_home_url()) !== '';
	}

	/**
	 * Rate-limit gate: returns true iff this attempt can proceed. Claims the
	 * quota via a 24h transient so a subsequent migration detect on the same
	 * day (staging<->prod oscillation) skips silently.
	 */
	private function claim_silent_reactivation_quota()
	{
		$rate_limit_key = 'sbr_silent_reactivate_last_attempt';
		if (function_exists('get_transient') && (bool) get_transient($rate_limit_key)) {
			return false;
		}
		if (function_exists('set_transient') && defined('DAY_IN_SECONDS')) {
			set_transient($rate_limit_key, time(), DAY_IN_SECONDS);
		}
		return true;
	}

	/**
	 * Extract the fresh token from the register response, tolerating both
	 * the nested `data.token` and flat `token` shapes (same tolerance as
	 * RegisterWebsiteRoutine).
	 *
	 * @return string|null  Null on any failure — caller bails.
	 */
	private function silent_reregister($new_url)
	{
		try {
			$response = $this->call('auth/register', ['url' => $new_url], 'POST', false);
		} catch (\Throwable $e) {
			return null;
		}
		if (!is_array($response)) {
			return null;
		}
		$nested = $response['data']['token'] ?? null;
		if (is_string($nested) && $nested !== '') {
			return $nested;
		}
		$flat = $response['token'] ?? null;
		return (is_string($flat) && $flat !== '') ? $flat : null;
	}

	/**
	 * Persist the freshly-issued access_token + URL so the same SBRelay
	 * instance can authenticate the subsequent license-activation call.
	 */
	private function persist_fresh_registration($new_token, $new_url)
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			$settings = [];
		}
		$settings['access_token'] = $new_token;
		$settings['website_url']  = $new_url;
		update_option('sbr_settings', $settings);
		$this->access_token = $new_token;
	}

	/**
	 * Activate the preserved license key against EDD via the relay. Returns
	 * the EDD payload only when license status is exactly 'valid'; null on
	 * any other outcome (expired, revoked, over-limit, network exception,
	 * malformed response). Callers use null as the "fall through to manual
	 * Activate screen" signal.
	 *
	 * @return array<string,mixed>|null
	 */
	private function silent_activate_license($license_key, $new_url)
	{
		try {
			$response = $this->call(
				'auth/license',
				[
					'license_key' => $license_key,
					'url'         => $new_url,
					'action'      => 'activate',
					'item_name'   => constant('SBR_PLUGIN_NAME'),
					'item_id'     => constant('SBR_PRODUCT_ID'),
				],
				'POST',
				true
			);
		} catch (\Throwable $e) {
			return null;
		}
		if (!is_array($response)) {
			return null;
		}
		$payload = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : $response;
		$status  = isset($payload['license']) && is_string($payload['license']) ? $payload['license'] : '';
		return ($status === 'valid') ? $payload : null;
	}

	/**
	 * Merge a successful EDD activation payload into sbr_settings.
	 */
	private function persist_license_state(array $license_payload)
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			$settings = [];
		}
		$settings['license_status'] = 'valid';
		$settings['license_info']   = isset($license_payload['api_data']) && is_array($license_payload['api_data'])
			? $license_payload['api_data']
			: [];
		update_option('sbr_settings', $settings);
	}

	/**
	 * Flag the admin notice consumed by `MigrationReactivationNotice`.
	 * Skipped silently if the transient API is unavailable — the notice
	 * isn't load-bearing; the license is already restored by this point.
	 */
	private function queue_silent_reactivation_notice($old_url, $new_url)
	{
		if (!function_exists('set_transient') || !defined('WEEK_IN_SECONDS')) {
			return;
		}
		set_transient(
			'sbr_silent_reactivation_notice',
			[
				'timestamp' => time(),
				'old_url'   => (string) $old_url,
				'new_url'   => $new_url,
			],
			WEEK_IN_SECONDS
		);
	}
}
