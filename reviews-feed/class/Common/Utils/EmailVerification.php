<?php

/**
 * Summary of namespace SmashBalloon\Reviews\Common\Utils
 */

namespace SmashBalloon\Reviews\Common\Utils;

use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Summary of EmailVerification
 */
class EmailVerification	extends ServiceProvider
{
	/**
	 * Email Verification Data /
	 * @var string
	 */
	public static $email_opt_name = 'sbr_email_verification';

	/**
	 * Get Email Verification Options
	 *
	 * @return array
	 */
	public static function get_email_verification_settings()
	{
		return get_option(self::$email_opt_name, []);
	}

	/**
	 * Get the centralized verification error message
	 *
	 * Single source of truth for the error message shown when
	 * email verification fails.
	 *
	 * @return string
	 */
	public static function get_verification_error_message(): string
	{
		return __('Email verification failed. Please try the verification process again. If the problem persists, contact support.', 'reviews-feed');
	}

	/**
	 * Summary of catch_email_verification
	 *
	 * Catches email verification parameters from redirect URL.
	 * Uses server-side validation as fallback when nonce fails
	 * (e.g., due to session expiry during email verification).
	 *
	 * @return bool
	 */
	public static function catch_email_verification()
	{
		if (!is_admin()) {
			return false;
		}

		// Required parameters must be present
		if (empty($_GET['sbr_email_token']) || empty($_GET['verified_email'])) {
			return false;
		}

		$email = sanitize_email($_GET['verified_email']);
		$token = sanitize_text_field($_GET['sbr_email_token']);

		// Check if already verified with these credentials to avoid redundant API calls
		// This prevents unnecessary relay calls on page refresh when nonce is expired
		$current_settings = self::get_email_verification_settings();
		if (
			!empty($current_settings['email'])
			&& $current_settings['email'] === $email
			&& !empty($current_settings['token'])
			&& $current_settings['token'] === $token
		) {
			return true;
		}

		// Validate email format
		if (!is_email($email)) {
			self::log_verification_attempt($email, 'invalid_email_format');
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = !empty($_GET['con_nonce'])
			? sanitize_text_field(wp_unslash($_GET['con_nonce']))
			: '';

		$nonce_valid = wp_verify_nonce($nonce, 'sbr_con');

		// If nonce is valid, proceed with verification
		if ($nonce_valid) {
			self::save_verification($email, $token);
			self::log_verification_attempt($email, 'success_nonce');
			return true;
		}

		// Nonce failed - use server-side validation as fallback
		self::log_verification_attempt($email, 'nonce_failed_trying_relay');

		$relay_valid = self::validate_token_with_relay($email);

		if ($relay_valid) {
			self::save_verification($email, $token);
			self::log_verification_attempt($email, 'success_relay_fallback');
			return true;
		}

		// Both validations failed - fail closed
		// Error is displayed via React (emailVerificationError in builder data)
		self::log_verification_attempt($email, 'both_validations_failed');

		return false;
	}

	/**
	 * Save verified email and token to options
	 *
	 * @param string $email
	 * @param string $token
	 * @return void
	 */
	private static function save_verification(string $email, string $token): void
	{
		update_option(
			self::$email_opt_name,
			[
				'email' => $email,
				'token' => $token
			]
		);
	}

	/**
	 * Validate email ownership with sb-relay API
	 *
	 * Authenticates via Bearer token and verifies the email is associated
	 * with the authenticated site. The token is never sent in the request
	 * body — only used for Bearer auth — preventing token oracle attacks.
	 *
	 * @param string $email
	 * @return bool
	 */
	private static function validate_token_with_relay(string $email): bool
	{
		// Debounce: skip if already attempted in the last 60 seconds
		// Prevents hammering the relay on rapid page refreshes with expired nonce.
		// Key is scoped per WP user — a failed attempt by admin A must NOT
		// block admin B from performing their own fallback validation on the
		// same site. (Sentry MEDIUM on PR #435.)
		if (get_transient(self::fallback_transient_key())) {
			return false;
		}

		try {
			$relay = new SBRelay();

			// Authenticate via Bearer token (access_token from settings)
			// The token is NOT sent in the body — only email is sent
			$response = $relay->call(
				'email/validate-token',
				[
					'email' => $email,
				],
				'POST',
				true // Bearer auth required — token validates via Authorization header
			);

			// Check for successful validation.
			// Relay's respondWithSuccess() merges payload into the top level —
			// it does NOT nest under a "data" key. So the actual shape is:
			//   { "message": "OK", "success": true, "valid": true }
			// (Verified live against /api/v1.0/email/validate-token — earlier
			// `data.valid` lookup silently returned false, breaking the entire
			// fallback validation flow when the WP nonce expired.)
			if (
				isset($response['success'])
				&& $response['success'] === true
				&& isset($response['valid'])
				&& $response['valid'] === true
			) {
				return true;
			}

			// Log the failure reason and cache to prevent repeated calls
			$error_id = $response['id'] ?? 'unknown';
			self::log_verification_attempt($email, 'relay_validation_failed: ' . $error_id);
			set_transient(self::fallback_transient_key(), true, MINUTE_IN_SECONDS);

			return false;
		} catch (\Exception $e) {
			self::log_verification_attempt($email, 'relay_exception: ' . $e->getMessage());
			set_transient(self::fallback_transient_key(), true, MINUTE_IN_SECONDS);
			return false;
		}
	}

	/**
	 * Transient key prefix for fallback validation debounce.
	 *
	 * The actual key is per-user — see fallback_transient_key(). A single
	 * site-wide key (the original form) let one admin's failed attempt
	 * lock out every other admin for the transient's lifetime.
	 * (Sentry MEDIUM on PR #435.)
	 *
	 * @var string
	 */
	private static $fallback_transient_prefix = 'sbr_fallback_validation_checked';

	/**
	 * Build the per-user fallback-validation transient key.
	 *
	 * `get_current_user_id()` returns 0 for unauthenticated contexts (cron,
	 * REST endpoints hit without auth). Those paths never reach this code
	 * in practice, but a shared `_0` bucket would just mirror the old
	 * single-key behavior — no worse than today.
	 *
	 * @return string
	 */
	private static function fallback_transient_key(): string
	{
		return self::$fallback_transient_prefix . '_' . get_current_user_id();
	}

	/**
	 * Log verification attempt (PII-safe)
	 *
	 * Hashes email for privacy protection in logs.
	 *
	 * @param string $email
	 * @param string $status
	 * @return void
	 */
	private static function log_verification_attempt(string $email, string $status): void
	{
		if (!defined('WP_DEBUG') || !WP_DEBUG) {
			return;
		}

		$hashed_email = self::hash_email_for_log($email);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(sprintf(
			'[SBR Email Verification] email_hash=%s status=%s',
			$hashed_email,
			$status
		));
	}

	/**
	 * Hash email for logging (PII protection)
	 *
	 * @param string $email
	 * @return string
	 */
	private static function hash_email_for_log(string $email): string
	{
		return substr(hash('sha256', $email), 0, 8);
	}

	/**
	 * Build Email Verification URL
	 *
	 * @return string
	 */
	public static function build_email_verification_url($current_page = false)
	{
		$settings = get_option('sbr_settings', []);
		if (!is_array($settings)) {
			$settings = [];
		}
		$args = [
			'state'					=> $current_page !== false ? $current_page : admin_url('admin.php?page=sbr-settings'),
			'wordpress_user'		=> self::get_current_email(),
			'con_nonce' 			=> wp_create_nonce('sbr_con'),
			'site_token'			=> !empty($settings['access_token']) ? $settings['access_token'] : null
		];
		return add_query_arg($args, SBR_CONNECT_SITE_URL);
	}

	/**
	 * Transient key prefix for recovery check cache.
	 *
	 * The actual key is per-user — see recovery_transient_key(). A single
	 * site-wide key (the original form) let one admin's "not verified"
	 * result block recovery checks for every other admin on the same
	 * site for 5 minutes, even if a different admin's email WAS verified.
	 * (Sentry MEDIUM on PR #435.)
	 *
	 * @var string
	 */
	private static $recovery_transient_prefix = 'sbr_recovery_checked';

	/**
	 * Build the per-user recovery-check transient key.
	 *
	 * @return string
	 */
	private static function recovery_transient_key(): string
	{
		return self::$recovery_transient_prefix . '_' . get_current_user_id();
	}

	/**
	 * Check if email is already verified on relay (RECOVERY)
	 *
	 * Handles the stuck loop case where:
	 * 1. User completed verification on relay
	 * 2. sb-connect failed to redirect back to WordPress
	 * 3. WordPress doesn't have the token
	 *
	 * Performance optimizations:
	 * - Skips API call if verification URL params present (just attempted)
	 * - Caches "not verified" result for 5 minutes to avoid repeated calls
	 *
	 * Call this before redirecting to sb-connect to check if
	 * verification already completed and recover locally.
	 *
	 * @param string|null $email Email to check (defaults to current user)
	 * @return bool True if verification was recovered
	 */
	public static function check_and_recover_verification(?string $email = null): bool
	{
		// Already verified locally - no recovery needed
		if (self::check_verified()) {
			return true;
		}

		// Skip recovery if we just attempted verification (presence of URL params)
		// This prevents double API calls: validate-token (in catch_email_verification)
		// followed by check-status (here)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (!empty($_GET['sbr_email_token']) || !empty($_GET['verified_email'])) {
			return false;
		}

		// Check transient cache to avoid repeated API calls on page loads.
		// Cache lasts 5 minutes - user can clear by clicking "Verify Email" again.
		// Per-user scoped: a "not verified" result for admin A must NOT
		// suppress recovery attempts by admin B on the same site.
		// (Sentry MEDIUM on PR #435.)
		if (get_transient(self::recovery_transient_key())) {
			return false;
		}

		$email = $email ?? self::get_current_email();

		if (empty($email) || !is_email($email)) {
			return false;
		}

		try {
			$relay = new SBRelay();

			$response = $relay->call(
				'email/check-status',
				['email' => $email],
				'POST',
				true // Requires auth (site_token)
			);

			// Check if relay says email is already verified.
			// Relay's respondWithSuccess() merges payload into the top level —
			// it does NOT nest under a "data" key. Actual shape:
			//   { "success": true, "verified": true, "email": "...", "token": "..." }
			// (Verified live against /api/v1.0/email/check-status — earlier
			// `data.verified` lookup silently returned false, so the recovery
			// path never recognized an already-verified email and users stayed
			// stuck in the verification loop.)
			if (
				isset($response['success'])
				&& $response['success'] === true
				&& isset($response['verified'])
				&& $response['verified'] === true
				&& !empty($response['token'])
				&& !empty($response['email'])
			) {
				// Recovery successful - save verification locally (sanitize relay response)
				self::save_verification(
					sanitize_email($response['email']),
					sanitize_text_field($response['token'])
				);
				self::log_verification_attempt($email, 'recovery_success');
				// Clear the transient since we're now verified
				delete_transient(self::recovery_transient_key());
				return true;
			}

			// Set transient to cache "not verified" status (5 minutes)
			set_transient(self::recovery_transient_key(), true, 5 * MINUTE_IN_SECONDS);

			return false;
		} catch (\Exception $e) {
			self::log_verification_attempt($email, 'recovery_exception: ' . $e->getMessage());
			// Cache failure to avoid hammering the API
			set_transient(self::recovery_transient_key(), true, 5 * MINUTE_IN_SECONDS);
			return false;
		}
	}

	/**
	 * Get Current User Email
	 *
	 * @return string
	 */
	public static function get_current_email()
	{
		if (!is_user_logged_in()) {
			return get_option('admin_email', '');
		}
		$current_user = wp_get_current_user();
		return $current_user->user_email;
	}

	/**
	 * Check if it's verified
	 *
	 * @return boolean
	 */
	public static function check_verified()
	{
		$data = self::get_email_verification_settings();
		return !empty($data['email']) && !empty($data['token']);
	}

}
