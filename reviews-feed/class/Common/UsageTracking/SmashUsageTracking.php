<?php

/**
 * Orchestrates Smash Usage Tracking for Reviews Feed: schedule, ensure site token, build payload, send.
 *
 * @package SmashBalloon\Reviews\Common\UsageTracking
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\UsageTracking;

use SmashBalloon\Reviews\Common\UsageTracking\Core\RegisterSite;
use SmashBalloon\Reviews\Common\UsageTracking\Core\Sender;
use SmashBalloon\Reviews\Common\UsageTracking\Core\PayloadBuilder;
use SmashBalloon\Reviews\Common\UsageTracking\Core\Scheduler;
use SmashBalloon\Reviews\Common\UsageTracking\Reviews\ReviewsReporter;

if (! defined('ABSPATH')) {
	exit;
}

class SmashUsageTracking {
	/**
	 * After this many consecutive failed attempts the cadence degrades to
	 * monthly, so a permanently-rejected payload (e.g. schema mismatch after
	 * a rollback of the API) stops hammering weekly.
	 */
	const FAILURE_BACKOFF_THRESHOLD = 4;

	/**
	 * After this many consecutive attempts where no request completed at all
	 * (send() returned 0), the stored site token is dropped and re-registered:
	 * an invalid stored token that inflates the payload past the size cap
	 * never produces a token-rejection status code, so this is the only path
	 * that can recover from a poisoned token without manual option surgery.
	 */
	const ZERO_STATUS_REREGISTER_THRESHOLD = 3;

	/**
	 * @var ReporterInterface
	 */
	private $reporter;

	/**
	 * @var RegisterSite
	 */
	private $register_site;

	/**
	 * @var Sender
	 */
	private $sender;

	/**
	 * @var PayloadBuilder
	 */
	private $payload_builder;

	/**
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * Source-connection AJAX hooks → provider slug recorded for each.
	 * Most add-source endpoints don't send a `provider` request field, so the
	 * provider is derived from the hook itself; null means the endpoint is
	 * generic and the (nonce-verified) request field is used instead.
	 *
	 * @var array<string, string|null>
	 */
	private static $source_hook_providers = array(
		'sbr_feed_saver_manager_add_source'              => null,
		'sbr_feed_saver_manager_add_facebook_source'     => 'facebook',
		// Misspelled variant still sent by the bundled customizer JS.
		'sbr_feed_saver_manager_add_facebook_souce'      => 'facebook',
		'sbr_feed_saver_manager_connect_manual_facebook' => 'facebook',
		'sbr_add_woocommerce_source'                     => 'woocommerce',
		'sbr_add_woocommerce_source_multi'               => 'woocommerce',
		'sbr_add_edd_source'                             => 'edd',
		'sbr_add_edd_source_multi'                       => 'edd',
		'sbr_add_airbnb_source'                          => 'airbnb',
		'sbr_add_booking_source'                         => 'booking',
		'sbr_add_aliexpress_source'                      => 'aliexpress',
		'sbr_add_external_source'                        => null,
	);

	public function __construct()
	{
		$this->reporter        = new ReviewsReporter();
		$this->register_site   = new Core\RegisterSite();
		$this->sender          = new Core\Sender();
		$this->payload_builder = new Core\PayloadBuilder($this->reporter);
		$this->scheduler       = new Core\Scheduler();
	}

	/**
	 * Attach all hooks. Called once by the ServiceContainer loop.
	 */
	public function register(): void
	{
		// ── Cron + session ────────────────────────────────────────────────────
		add_action('init', array( $this, 'maybe_schedule' ));
		add_filter('cron_schedules', array( $this->scheduler, 'add_schedules' ));
		add_action(Config::CRON_HOOK, array( $this, 'send_checkin' ));
		add_action('current_screen', array( $this, 'maybe_record_active_day' ));
		add_action('admin_enqueue_scripts', array( $this, 'enqueue_session_script' ), 20);
		add_action('wp_ajax_sbr_smash_usage_record_session', array( $this, 'ajax_record_session' ));

		// ── Feed events (priority 5 = runs BEFORE main handler) ───────────────
		add_action('wp_ajax_sbr_feed_saver_manager_builder_update', array( $this, 'on_feed_saved' ), 5);

		// ── Source connection events ───────────────────────────────────────────
		foreach (array_keys(self::$source_hook_providers) as $hook) {
			add_action('wp_ajax_' . $hook, array( $this, 'on_source_connected' ), 5);
		}

		// ── License & upgrade ─────────────────────────────────────────────────
		add_action('wp_ajax_sbr_activate_license', array( $this, 'on_license_activated' ), 5);
		add_action('wp_ajax_sbr_install_plugin', array( $this, 'on_upgrade_initiated' ), 5);

		// ── Permission revocation ─────────────────────────────────────────────
		add_action('sbr_app_permission_revoked', array( $this, 'on_permission_revoked' ));
	}

	/**
	 * Schedule cron if enabled and not already scheduled. Legacy cron/config
	 * cleanup happens in Scheduler::schedule(). Here we also migrate the interim
	 * `smash_usage_tracking` settings key to `usagetracking`.
	 */
	public function maybe_schedule(): void
	{
		$settings = get_option('sbr_settings', array());
		if (is_array($settings) && array_key_exists('smash_usage_tracking', $settings)) {
			if (! array_key_exists('usagetracking', $settings)) {
				$settings['usagetracking'] = (bool) $settings['smash_usage_tracking'];
			}
			unset($settings['smash_usage_tracking']);
			update_option('sbr_settings', $settings);
		}

		$this->scheduler->schedule();
	}

	/**
	 * Cron callback: ensure site token, build payload, send, update last_send.
	 */
	public function send_checkin(): void
	{
		if (! Config::is_enabled()) {
			return;
		}

		$host = wp_parse_url(home_url(), PHP_URL_HOST);
		$host = is_string($host) ? $host : '';
		if ('smashballoon.com' === $host || '.smashballoon.com' === substr($host, -17)) {
			return;
		}

		$opt          = get_option(Config::OPTION_TRACKING, array());
		$opt          = is_array($opt) ? $opt : array();
		$last_send    = isset($opt['last_send']) ? (int) $opt['last_send'] : 0;
		$last_attempt = isset($opt['last_attempt']) ? (int) $opt['last_attempt'] : 0;
		$failures     = isset($opt['consecutive_failures']) ? (int) $opt['consecutive_failures'] : 0;
		// -6 days, not -1 week: last_send is stamped AFTER the send completes,
		// so with punctual cron the next weekly run fires slightly less than
		// 7 days later and an exact-week guard would skip every other run.
		if ($last_send > strtotime('-6 days')) {
			return;
		}
		// Back off to a monthly cadence once sends fail persistently. Same
		// -1 day slack as the weekly guard, for the same reason.
		if ($failures >= self::FAILURE_BACKOFF_THRESHOLD && $last_attempt > strtotime('-27 days')) {
			return;
		}

		// The last_send guard alone can't stop two overlapping runners (e.g.
		// multi-server wp-cron) — it is only updated after the up-to-30s send
		// completes. A second concurrent send would double-report and then
		// double-subtract events in reset_events_after_send().
		if (false !== get_transient('sbr_smash_usage_sending_lock')) {
			return;
		}
		set_transient('sbr_smash_usage_sending_lock', 1, 2 * MINUTE_IN_SECONDS);

		$site_token = get_option(Config::OPTION_SITE_TOKEN, '');
		if ('' === $site_token || ! is_string($site_token)) {
			$site_token = $this->register_site->register($this->reporter);
			if (null === $site_token) {
				delete_transient('sbr_smash_usage_sending_lock');
				return;
			}
		}

		$period_end   = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
		$period_start = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);

		// Snapshot the durations present before the payload is built so the
		// post-send reset removes exactly those entries (by value), even when
		// the store's 10-entry cap displaces old entries during the send.
		$durations_before = get_option(Config::OPTION_SESSION_DURATIONS, array());
		$durations_before = is_array($durations_before) ? array_values($durations_before) : array();

		$payload = $this->payload_builder->build($site_token, $period_start, $period_end);
		$code    = $this->sender->send($payload);
		$now     = time();

		if ($code >= 200 && $code < 300) {
			update_option(
				Config::OPTION_TRACKING,
				array(
					'last_send'            => $now,
					'last_attempt'         => $now,
					'last_status'          => $code,
					'consecutive_failures' => 0,
				),
				false
			);
			$sent_events = isset($payload['dynamic_metrics']['events']) && is_array($payload['dynamic_metrics']['events'])
				? $payload['dynamic_metrics']['events']
				: array();
			$this->reset_events_after_send($sent_events, $durations_before);
		} else {
			++$failures;
			update_option(
				Config::OPTION_TRACKING,
				array(
					'last_send'            => $last_send,
					'last_attempt'         => $now,
					'last_status'          => $code,
					'consecutive_failures' => $failures,
				),
				false
			);
			$this->debug_log('send failed with status ' . $code . ' (consecutive failures: ' . $failures . ')');

			if ($this->sender->last_error_rejected_token($code)) {
				// The API rejected the site token (revoked/unknown). Drop it so the
				// next weekly run re-registers instead of retrying a dead token forever.
				delete_option(Config::OPTION_SITE_TOKEN);
			} elseif (0 === $code && $failures >= self::ZERO_STATUS_REREGISTER_THRESHOLD) {
				// No request completed at all several runs in a row — the one
				// state that produces this permanently is a stored token that
				// pushes the payload over the size cap. Re-registering is cheap
				// and self-heals it; for transient network failures it is a
				// harmless re-fetch of the same token.
				delete_option(Config::OPTION_SITE_TOKEN);
			}
		}

		delete_transient('sbr_smash_usage_sending_lock');
	}

	/**
	 * Log a tracking diagnostic behind the standard debug gate.
	 *
	 * @param string $message Message body (prefixed automatically).
	 */
	private function debug_log(string $message): void
	{
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && function_exists('error_log')) {
			error_log('[SBR Usage Tracking] ' . $message);
		}
	}

	/**
	 * Delete every option this feature stores: collected-but-unsent data
	 * (events, active dates, session durations), the site token, and the
	 * send-state bookkeeping. Called when the user opts out — disabling
	 * tracking must not be weaker than uninstalling (uninstall.php deletes
	 * the same keys) — and the list must stay in sync with uninstall.php.
	 */
	public static function purge_stored_data(): void
	{
		delete_option(EventRecorder::OPTION_NAME);
		delete_option(Config::OPTION_ACTIVE_DATES);
		delete_option(Config::OPTION_SESSION_DURATIONS);
		delete_option(Config::OPTION_SITE_TOKEN);
		delete_option(Config::OPTION_TRACKING);
		delete_option(Config::OPTION_SCHEDULE);
	}

	/**
	 * Remove only the event keys that were included in the sent payload.
	 * Events recorded by concurrent AJAX requests after the payload was built
	 * are left intact so they roll over into the next reporting period.
	 *
	 * @param array $sent_events        Events map from the sent payload.
	 * @param array $reported_durations Session-duration entries that existed
	 *                                  when the payload was built.
	 */
	private function reset_events_after_send(array $sent_events, array $reported_durations = array()): void
	{
		if (! empty($sent_events)) {
			$stored = get_option(EventRecorder::OPTION_NAME, array());
			if (is_array($stored)) {
				foreach ($sent_events as $key => $sent) {
					if (! isset($stored[ $key ])) {
						continue;
					}

					// Subtract the reported count rather than unsetting the key: a
					// concurrent request can increment an event AFTER the payload was
					// built but BEFORE this runs, and unsetting would discard those
					// extra occurrences. Only drop the key once nothing is left.
					$sent_count   = is_array($sent) && isset($sent['count']) ? (int) $sent['count'] : 0;
					$stored_count = is_array($stored[ $key ]) && isset($stored[ $key ]['count'])
						? (int) $stored[ $key ]['count']
						: 0;
					$remaining    = $stored_count - $sent_count;

					// The is_array() repeat is deliberate: without it this write depends on
					// $stored_count above having forced $remaining <= 0 for a scalar entry,
					// which only holds while counts cannot go negative. A malformed entry
					// gets dropped rather than silently skipped.
					if ($remaining > 0 && is_array($stored[ $key ])) {
						$stored[ $key ]['count'] = $remaining;
					} else {
						unset($stored[ $key ]);
					}
				}
				update_option(EventRecorder::OPTION_NAME, $stored, false);
			}
		}

		// Same concurrency care as events: remove one occurrence of each
		// REPORTED value (multiset diff) rather than slicing by count — the
		// store keeps only the last 10 entries, so a count-based slice would
		// delete the new unreported sessions whenever the cap displaced old
		// reported ones during the send window.
		$durations = get_option(Config::OPTION_SESSION_DURATIONS, array());
		$durations = is_array($durations) ? array_values($durations) : array();
		foreach ($reported_durations as $reported) {
			$idx = array_search($reported, $durations, true);
			if (false !== $idx) {
				unset($durations[ $idx ]);
			}
		}
		update_option(Config::OPTION_SESSION_DURATIONS, array_values($durations), false);
	}

	/**
	 * Unschedule cron (call when disabling tracking).
	 */
	public function unschedule(): void
	{
		$this->scheduler->unschedule();
	}

	/**
	 * Record active day and optionally settings_page_viewed when on an SBR admin page.
	 *
	 * @param \WP_Screen|null $screen Current screen from the current_screen hook.
	 */
	public function maybe_record_active_day(?\WP_Screen $screen): void
	{
		if (! $screen || strpos($screen->id, 'sbr') === false) {
			return;
		}
		if (! Config::is_enabled()) {
			return;
		}
		EventRecorder::record_active_day();
		if (strpos($screen->id, 'sbr-settings') !== false) {
			EventRecorder::record('settings_page_viewed');
		}
	}

	/**
	 * AJAX: record session duration from JS (seconds).
	 */
	public function ajax_record_session(): void
	{
		check_ajax_referer('sbr_smash_usage_record_session', 'nonce');
		// sbr_current_user_can() applies sbr_settings_pages_capability, which the other
		// handlers in this class already go through — a site filtering that capability
		// otherwise gets this one endpoint enforced differently.
		if (! sbr_current_user_can('manage_reviews_feed_options') || ! Config::is_enabled()) {
			wp_send_json_error();
		}
		$seconds = isset($_POST['duration_seconds']) ? (int) $_POST['duration_seconds'] : 0;
		EventRecorder::record_session_duration($seconds);
		wp_send_json_success();
	}

	/**
	 * Enqueue the session-duration script on SBR admin pages.
	 */
	public function enqueue_session_script(): void
	{
		if (! Config::is_enabled()) {
			return;
		}
		if (! function_exists('get_current_screen')) {
			return;
		}
		$screen = get_current_screen();
		if (! $screen || strpos($screen->id, 'sbr') === false) {
			return;
		}

		// Both constants come from bootstrap.php before the container registers;
		// bail rather than guess if either is missing (a plugins_url() fallback
		// resolved against this file would double the public/js path segment).
		if (! defined('SBR_PLUGIN_DIR') || ! defined('SBR_PLUGIN_URL')) {
			return;
		}

		$script_path = 'public/js/smash-usage-session.js';
		$path        = SBR_PLUGIN_DIR . $script_path;

		if (! file_exists($path)) {
			return;
		}

		wp_enqueue_script(
			'sbr-smash-usage-session',
			SBR_PLUGIN_URL . $script_path,
			array( 'jquery' ),
			defined('SBRVER') ? SBRVER : '1.0',
			true
		);
		wp_localize_script(
			'sbr-smash-usage-session',
			'sbrSmashUsageSession',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('sbr_smash_usage_record_session'),
			)
		);
	}

	// ── Event handler methods ─────────────────────────────────────────────────

	/**
	 * Non-dying replica of the primary AJAX handlers' checks. The event
	 * listeners run at priority 5 — BEFORE the primary handler verifies the
	 * request — so each listener must gate its option writes on the same
	 * 'sbr-admin' nonce and capability the primary handler enforces, without
	 * ending the request (the primary handler still owns the response).
	 *
	 * @return bool
	 */
	private function verify_admin_ajax_request(): bool
	{
		if (false === check_ajax_referer('sbr-admin', 'nonce', false)) {
			return false;
		}
		if (function_exists('sbr_current_user_can')) {
			return (bool) sbr_current_user_can('manage_reviews_feed_options');
		}
		return current_user_can('manage_options');
	}

	/**
	 * Record feed_saved event. Runs at priority 5 — must NOT send any response.
	 */
	public function on_feed_saved(): void
	{
		if (! Config::is_enabled() || ! $this->verify_admin_ajax_request()) {
			return;
		}
		EventRecorder::record('feed_saved');
	}

	/**
	 * Record source_connected event (and provider-specific variant). Priority 5.
	 */
	public function on_source_connected(): void
	{
		if (! Config::is_enabled() || ! $this->verify_admin_ajax_request()) {
			return;
		}
		EventRecorder::record('source_connected');

		$hook     = (string) preg_replace('/^wp_ajax_/', '', (string) current_action());
		$provider = self::$source_hook_providers[ $hook ] ?? null;
		if (null === $provider) {
			// Generic endpoint — provider comes from the request, which
			// verify_admin_ajax_request() has already nonce-checked above.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
		}

		$allowed = array(
			'google',
			'facebook',
			'yelp',
			'trustpilot',
			'woocommerce',
			'edd',
			'airbnb',
			'booking',
			'aliexpress',
			'tripadvisor',
			'wordpress.org',
		);
		if ('' !== $provider && in_array($provider, $allowed, true)) {
			EventRecorder::record('source_connected_' . $provider);
		}
	}

	/**
	 * Record license_activated event. Priority 5.
	 */
	public function on_license_activated(): void
	{
		if (! Config::is_enabled() || ! $this->verify_admin_ajax_request()) {
			return;
		}
		EventRecorder::record('license_activated');
	}

	/**
	 * Record upgrade_initiated event. Priority 5.
	 */
	public function on_upgrade_initiated(): void
	{
		if (! Config::is_enabled() || ! $this->verify_admin_ajax_request()) {
			return;
		}
		EventRecorder::record('upgrade_initiated');
	}

	/**
	 * Record permission_revoked event.
	 */
	public function on_permission_revoked(): void
	{
		if (! Config::is_enabled()) {
			return;
		}
		EventRecorder::record('permission_revoked');
	}

	/**
	 * Build the usage report payload without sending (for preview/debug).
	 *
	 * @return array The full payload that would be sent to the API.
	 */
	public function get_payload_preview()
	{
		$period_end   = gmdate('Y-m-d', time() - DAY_IN_SECONDS);
		$period_start = gmdate('Y-m-d', time() - 7 * DAY_IN_SECONDS);
		$site_token   = 'preview-no-api';

		return $this->payload_builder->build($site_token, $period_start, $period_end);
	}
}
