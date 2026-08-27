<?php

/**
 * Reviews reporter for Smash Usage Tracking.
 *
 * Single reporter handling both free and pro variants.
 *
 * @package SmashBalloon\Reviews\Common\UsageTracking\Reviews
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\UsageTracking\Reviews;

use SmashBalloon\Reviews\Common\UsageTracking\ReporterInterface;
use SmashBalloon\Reviews\Common\UsageTracking\Config;
use SmashBalloon\Reviews\Common\UsageTracking\EventRecorder;

if (! defined('ABSPATH')) {
	exit;
}

class ReviewsReporter implements ReporterInterface {
	/**
	 * Payload schema version. 1.1 added feeds{} and features_enabled{},
	 * 1.2 added environment{} — this reporter sends all of them, so it declares
	 * 1.2 rather than the 1.1 it originally shipped with.
	 */
	const SCHEMA_VERSION = '1.2';

	/**
	 * License tier integer → string map.
	 *
	 * @var array
	 */
	private static $tier_map = array(
		0 => 'free',
		1 => 'basic',
		2 => 'plus',
		3 => 'elite',
	);

	/**
	 * Whitelisted feed setting keys to include in latest_10_feeds.
	 *
	 * @var string[]
	 */
	private static $feed_settings_whitelist = array(
		'layout',
		'gridDesktopColumns',
		'carouselDesktopColumns',
		'showHeader',
		'postElements',
		'feedTemplate',
	);

	/**
	 * Plugin slug for payload root.
	 *
	 * @return string
	 */
	public function get_plugin_slug()
	{
		return 'reviews';
	}

	/**
	 * Schema version for the report payload.
	 *
	 * @return string
	 */
	public function get_schema_version()
	{
		return self::SCHEMA_VERSION;
	}

	/**
	 * Configuration snapshot.
	 *
	 * @return array
	 */
	public function get_configuration_snapshot()
	{
		$global_settings = $this->get_global_settings();
		$all_feed_data   = $this->get_all_feed_data();

		return array(
			'environment'      => $this->get_environment(),
			'global_settings'  => $global_settings,
			'sources'          => $this->get_sources_summary(),
			'providers'        => $this->get_providers_summary(),
			'latest_10_feeds'  => $this->get_latest_feeds($all_feed_data),
			'feeds'            => $this->get_feeds_summary($all_feed_data),
			'features_enabled' => $this->get_features_enabled($all_feed_data),
			'version'          => defined('SBRVER') ? SBRVER : '',
			'license_tier'     => $this->get_license_tier(),
			'license_status'   => $this->get_license_status(),
			'license_expires'  => $this->get_license_expires(),
			'license_item_id'  => $this->get_license_item_id(),
		);
	}

	/**
	 * Dynamic metrics for the given period.
	 *
	 * @param string|int $period_start Start of period (ISO 8601 or timestamp).
	 * @param string|int $period_end   End of period (ISO 8601 or timestamp).
	 * @return array
	 */
	public function get_dynamic_metrics($period_start, $period_end)
	{
		$ts_start = is_numeric($period_start) ? (int) $period_start : (int) strtotime($period_start);
		$ts_end   = is_numeric($period_end) ? (int) $period_end : (int) strtotime($period_end);

		return array(
			'period_start'     => $period_start,
			'period_end'       => $period_end,
			'performance'      => $this->get_performance_metrics(),
			'errors'           => $this->get_error_metrics(),
			'events'           => $this->get_events_for_period($ts_start, $ts_end),
			'days_active'      => $this->get_days_active((string) $period_start, (string) $period_end),
			'session_duration' => $this->get_session_duration(),
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Configuration snapshot helpers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Environment data (WP, PHP, theme, locale, multisite, install age).
	 *
	 * @return array
	 */
	private function get_environment()
	{
		$install_ts = null;
		$statuses   = $this->get_option_array('sbr_statuses');
		if (! empty($statuses['first_install']) && is_numeric($statuses['first_install'])) {
			$install_ts = (int) $statuses['first_install'];
		}
		$install_age_days = $install_ts ? max(0, (int) ((time() - $install_ts) / DAY_IN_SECONDS)) : 0;

		$theme      = wp_get_theme();
		$theme_name = $theme->exists() ? $theme->get('Name') : '';

		return array(
			'wp_version'           => get_bloginfo('version'),
			'php_version'          => PHP_VERSION,
			'active_theme'         => $theme_name,
			'locale'               => get_locale(),
			'multisite'            => is_multisite(),
			'site_count'           => is_multisite() ? (int) get_blog_count() : 1,
			'active_plugins_count' => count(
				array_unique(
					array_merge(
						(array) get_option('active_plugins', array()),
						array_keys((array) get_site_option('active_sitewide_plugins', array()))
					)
				)
			),
			'install_age_days'     => $install_age_days,
		);
	}

	/**
	 * Global SBR settings from the sbr_settings option.
	 *
	 * @return array
	 */
	private function get_global_settings()
	{
		$settings = $this->get_option_array('sbr_settings');

		return array(
			'feedTemplate'      => isset($settings['feedTemplate']) ? $settings['feedTemplate'] : 'default',
			'layout'            => isset($settings['layout']) ? $settings['layout'] : 'list',
			'preserve_settings' => ! empty($settings['preserve_settings']),
			// Via the helper, not the raw key: with the key absent the default
			// is per-edition, and reading the key directly would make the
			// payload assert usagetracking:false while transmitting.
			'usagetracking'     => Config::is_enabled(),
		);
	}

	/**
	 * Sources summary (connected count, by provider) from sbr_sources table.
	 *
	 * @return array
	 */
	private function get_sources_summary()
	{
		global $wpdb;
		$sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$table_exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sources_table)) === $sources_table;

		$connected_count = 0;
		$by_provider     = array();

		if ($table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$connected_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sources_table}");

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$rows = $wpdb->get_results("SELECT provider, COUNT(*) AS cnt FROM {$sources_table} GROUP BY provider", ARRAY_A);
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$provider                 = sanitize_text_field((string) $row['provider']);
					$by_provider[ $provider ] = (int) $row['cnt'];
				}
			}
		}

		return array(
			'connected_count' => $connected_count,
			'by_provider'     => $by_provider,
		);
	}

	/**
	 * Providers summary (Reviews-unique): connected_sources and error_count per provider.
	 * Queries sbr_sources cross-referenced with sbr_errors for error counts.
	 *
	 * @return array
	 */
	private function get_providers_summary()
	{
		global $wpdb;
		$sources_table = $wpdb->prefix . SBR_SOURCES_TABLE;
		$table_exists  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sources_table)) === $sources_table;

		$providers = array();

		if ($table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$rows = $wpdb->get_results("SELECT provider, COUNT(*) AS cnt FROM {$sources_table} GROUP BY provider", ARRAY_A);
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$provider               = sanitize_text_field((string) $row['provider']);
					$providers[ $provider ] = array(
						'connected_sources' => (int) $row['cnt'],
						'error_count'       => 0,
					);
				}
			}
		}

		// Cross-reference sbr_errors option to get error counts per provider.
		$raw_errors = get_option('sbr_errors', array());
		if (is_array($raw_errors)) {
			foreach ($raw_errors as $err) {
				$provider = isset($err['provider']) ? sanitize_text_field((string) $err['provider']) : 'unknown';
				if (isset($providers[ $provider ])) {
					++$providers[ $provider ]['error_count'];
				} else {
					// Provider has errors but no sources — still report it.
					$providers[ $provider ] = array(
						'connected_sources' => 0,
						'error_count'       => 1,
					);
				}
			}
		}

		return $providers;
	}

	/**
	 * Load every feed's decoded settings plus feed_name, sorted newest-first.
	 * One DB query shared across get_latest_feeds(), get_feeds_summary(), and
	 * get_features_enabled() to avoid multiple table scans per report.
	 *
	 * feed_style (custom CSS) is a COLUMN of the feeds table, not a key
	 * inside the settings JSON — SBR_Feed_Saver writes it as its own field —
	 * so it must be selected explicitly. Only its length is carried: the
	 * payload never needs the CSS itself.
	 *
	 * @return array<int, array{feed_name: string, settings: array, custom_css_length: int}>
	 */
	private function get_all_feed_data(): array
	{
		global $wpdb;
		$table        = $wpdb->prefix . SBR_FEEDS_TABLE;
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;

		if (! $table_exists) {
			return array();
		}

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			"SELECT feed_name, settings, feed_style FROM {$table} ORDER BY last_modified DESC LIMIT 500",
			ARRAY_A
		);

		if (! is_array($rows)) {
			return array();
		}

		$out = array();
		foreach ($rows as $row) {
			$decoded = ! empty($row['settings']) ? json_decode($row['settings'], true) : array();
			$out[]   = array(
				'feed_name'         => isset($row['feed_name']) ? sanitize_text_field((string) $row['feed_name']) : '',
				'settings'          => is_array($decoded) ? $decoded : array(),
				'custom_css_length' => isset($row['feed_style']) && is_string($row['feed_style']) ? strlen(trim($row['feed_style'])) : 0,
			);
		}

		return $out;
	}

	/**
	 * Latest 15 feeds with whitelisted settings.
	 * Payload key kept as 'latest_10_feeds' for backwards compatibility.
	 *
	 * @param array<int, array{feed_name: string, settings: array, custom_css_length: int}> $all_feed_data From get_all_feed_data().
	 * @return array
	 */
	private function get_latest_feeds(array $all_feed_data): array
	{
		$feeds = array();
		foreach (array_slice($all_feed_data, 0, 15) as $row) {
			$feed_name = $row['feed_name'];
			if (strlen($feed_name) > 255) {
				$feed_name = substr($feed_name, 0, 255);
			}
			$feeds[] = array(
				'feed_name'         => $feed_name,
				'settings'          => $this->pick_whitelisted_settings($row['settings']),
				'custom_css_length' => (int) $row['custom_css_length'],
			);
		}
		return $feeds;
	}

	/**
	 * Aggregate feed layout distribution across all feeds.
	 *
	 * @param array<int, array{feed_name: string, settings: array}> $all_feed_data From get_all_feed_data().
	 * @return array { total_count, by_type, by_layout }
	 */
	private function get_feeds_summary(array $all_feed_data): array
	{
		$by_type   = array();
		$by_layout = array();

		foreach ($all_feed_data as $row) {
			$s = $row['settings'];
			// Reviews feeds use 'feedTemplate' as type
			$type   = isset($s['feedTemplate']) ? (string) $s['feedTemplate'] : 'default';
			$layout = isset($s['layout']) ? (string) $s['layout'] : 'list';

			$by_type[ $type ]     = ($by_type[ $type ] ?? 0) + 1;
			$by_layout[ $layout ] = ($by_layout[ $layout ] ?? 0) + 1;
		}

		return array(
			'total_count' => count($all_feed_data),
			'by_type'     => $by_type,
			'by_layout'   => $by_layout,
		);
	}

	/**
	 * Flat boolean feature map for the Laravel dashboard.
	 * Feed-level flags are true when ANY feed on this site uses the feature.
	 * review_form is site-level: the review-submission feature is the Forms
	 * integration (WPForms/Formidable/... collections), whose usage marker is
	 * the sb_connected_forms option — feed settings carry no flag for it.
	 *
	 * @param array<int, array{feed_name: string, settings: array, custom_css_length: int}> $all_feed_data From get_all_feed_data().
	 * @return array<string,bool>
	 */
	private function get_features_enabled(array $all_feed_data): array
	{
		$feed_flags = array(
			'load_more'       => false,
			'show_header'     => false,
			'lightbox'        => false,
			'masonry_layout'  => false,
			'carousel'        => false,
			'moderation_mode' => false,
			'custom_css'      => false,
			'star_filter'     => false,
		);

		foreach ($all_feed_data as $row) {
			$s = $row['settings'];

			if (! $feed_flags['load_more'] && ! empty($s['showLoadButton'])) {
				$feed_flags['load_more'] = true;
			}
			if (! $feed_flags['show_header'] && ! empty($s['showHeader'])) {
				$feed_flags['show_header'] = true;
			}
			if (! $feed_flags['lightbox'] && in_array('media', isset($s['postElements']) ? (array) $s['postElements'] : array(), true)) {
				$feed_flags['lightbox'] = true;
			}
			if (! $feed_flags['masonry_layout'] && isset($s['layout']) && 'masonry' === $s['layout']) {
				$feed_flags['masonry_layout'] = true;
			}
			if (! $feed_flags['carousel'] && isset($s['layout']) && 'carousel' === $s['layout']) {
				$feed_flags['carousel'] = true;
			}
			if (! $feed_flags['moderation_mode'] && ! empty($s['moderationEnabled'])) {
				$feed_flags['moderation_mode'] = true;
			}
			if (! $feed_flags['custom_css'] && $row['custom_css_length'] > 0) {
				$feed_flags['custom_css'] = true;
			}
			if (! $feed_flags['star_filter'] && ! empty($s['includedStarFilters'])) {
				$feed_flags['star_filter'] = true;
			}

			// Early exit once all feed-level flags are confirmed true.
			if (! in_array(false, $feed_flags, true)) {
				break;
			}
		}

		$connected_forms          = get_option('sb_connected_forms', array());
		$feed_flags['review_form'] = is_array($connected_forms) && ! empty($connected_forms);

		return $feed_flags;
	}

	/**
	 * Return only whitelisted feed settings.
	 *
	 * @param array $settings Raw feed settings.
	 * @return array
	 */
	private function pick_whitelisted_settings(array $settings): array
	{
		$out = array();
		foreach (self::$feed_settings_whitelist as $key) {
			if (! array_key_exists($key, $settings)) {
				continue;
			}
			$value = $settings[ $key ];
			if (is_array($value)) {
				$out[ $key ] = $value;
			} elseif (is_scalar($value)) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// License helpers (single reporter handles both free & pro)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * License tier string.
	 *
	 * @return string free|basic|plus|elite
	 */
	private function get_license_tier()
	{
		if (! \SmashBalloon\Reviews\Common\Util::sbr_is_pro()) {
			return 'free';
		}
		$statuses = $this->get_option_array('sbr_statuses');
		$int_tier = isset($statuses['license_tier']) && is_numeric($statuses['license_tier']) ? (int) $statuses['license_tier'] : 0;
		return self::$tier_map[ $int_tier ] ?? 'free';
	}

	/**
	 * License status string or null.
	 *
	 * @return string|null
	 */
	private function get_license_status()
	{
		if (! \SmashBalloon\Reviews\Common\Util::sbr_is_pro()) {
			return null;
		}
		$settings = $this->get_option_array('sbr_settings');
		$status   = $settings['license_status'] ?? null;
		return is_string($status) ? $status : null;
	}

	/**
	 * License expiry date string or null.
	 *
	 * @return string|null
	 */
	private function get_license_expires()
	{
		if (! \SmashBalloon\Reviews\Common\Util::sbr_is_pro()) {
			return null;
		}
		$settings = $this->get_option_array('sbr_settings');
		$info     = $settings['license_info'] ?? array();
		$expires  = is_array($info) ? ($info['expires'] ?? null) : null;
		return is_string($expires) ? $expires : null;
	}

	/**
	 * License item / price ID as integer or null.
	 *
	 * @return int|null
	 */
	private function get_license_item_id()
	{
		if (! \SmashBalloon\Reviews\Common\Util::sbr_is_pro()) {
			return null;
		}
		$settings = $this->get_option_array('sbr_settings');
		$info     = $settings['license_info'] ?? array();
		return is_array($info) && isset($info['price_id']) && is_numeric($info['price_id']) ? (int) $info['price_id'] : null;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Dynamic metrics helpers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Performance metrics: feed caches count, reviews post count.
	 *
	 * @return array
	 */
	private function get_performance_metrics()
	{
		global $wpdb;

		$cache_table         = $wpdb->prefix . SBR_FEED_CACHES_TABLE;
		$reviews_posts_table = $wpdb->prefix . SBR_POSTS_TABLE;

		$cache_table_exists   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cache_table)) === $cache_table;
		$reviews_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $reviews_posts_table)) === $reviews_posts_table;

		$feed_caches_count = 0;
		$reviews_count     = 0;

		if ($cache_table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$feed_caches_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}");
		}
		if ($reviews_table_exists) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$reviews_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$reviews_posts_table}");
		}

		return array(
			'feed_caches_count' => $feed_caches_count,
			'reviews_count'     => $reviews_count,
		);
	}

	/**
	 * Error metrics from sbr_errors and sbr_error_reporter options.
	 *
	 * @return array
	 */
	private function get_error_metrics(): array
	{
		$raw_errors = $this->get_option_array('sbr_errors');          // relay API errors
		$reporter   = $this->get_option_array('sbr_error_reporter');  // aggregated errors
		$settings   = $this->get_option_array('sbr_settings');
		$statuses   = $this->get_option_array('sbr_statuses');

		$relay = $this->collect_relay_errors($raw_errors);

		$accounts   = isset($reporter['accounts']) && is_array($reporter['accounts']) ? $reporter['accounts'] : array();
		$revoked    = isset($reporter['revoked']) && is_array($reporter['revoked']) ? $reporter['revoked'] : array();
		$connection = isset($reporter['connection']) && is_array($reporter['connection']) ? $reporter['connection'] : array();

		$account = $this->collect_account_errors($accounts);

		return array(
			'api_failures'        => $relay['api_failures'],
			'token_errors'        => $relay['token_errors'] + $account['token_errors'],
			'by_provider'         => $relay['by_provider'],
			'by_id'               => $relay['by_id'],
			'account_errors'      => $account['account_errors'],
			'token_revoked'       => count($revoked),
			'license_error'       => $this->get_license_error($settings, $raw_errors),
			'connection_critical' => ! empty($connection['critical']),
			'last_license_check'  => $statuses['last_cron_update'] ?? null,
			'latest'              => array_slice(array_merge($relay['latest'], $account['latest']), 0, 10),
		);
	}

	/**
	 * Aggregate relay API errors from the sbr_errors option.
	 *
	 * @param array $raw_errors Entries from the sbr_errors option.
	 * @return array{api_failures:int,token_errors:int,by_provider:array,by_id:array,latest:array}
	 */
	private function collect_relay_errors(array $raw_errors): array
	{
		$out = array(
			'api_failures' => 0,
			'token_errors' => 0,
			'by_provider'  => array(),
			'by_id'        => array(),
			'latest'       => array(),
		);

		foreach ($raw_errors as $err) {
			++$out['api_failures'];
			$provider = isset($err['provider']) ? sanitize_text_field((string) $err['provider']) : 'unknown';
			$err_id   = isset($err['id']) ? sanitize_text_field((string) $err['id']) : 'unknown';
			$endpoint = isset($err['endpoint']) ? $this->sanitize_endpoint((string) $err['endpoint']) : null;

			$out['by_provider'][ $provider ] = ($out['by_provider'][ $provider ] ?? 0) + 1;
			$out['by_id'][ $err_id ]         = ($out['by_id'][ $err_id ] ?? 0) + 1;

			if ('invalidToken' === $err_id) {
				++$out['token_errors'];
			}

			$out['latest'][] = array(
				'source'   => 'relay',
				'id'       => $err_id,
				'provider' => $provider,
				'endpoint' => $endpoint,
			);
		}

		return $out;
	}

	/**
	 * Aggregate token & permission errors from sbr_error_reporter accounts.
	 *
	 * @param array $accounts Accounts map from the sbr_error_reporter option.
	 * @return array{account_errors:int,token_errors:int,latest:array}
	 */
	private function collect_account_errors(array $accounts): array
	{
		$out = array(
			'account_errors' => 0,
			'token_errors'   => 0,
			'latest'         => array(),
		);

		foreach ($accounts as $account_id => $error_types) {
			if (! is_array($error_types)) {
				continue;
			}
			if (! empty($error_types['accesstoken'])) {
				++$out['token_errors'];
				++$out['account_errors'];
				$out['latest'][] = array(
					'source'   => 'account',
					'id'       => 'accesstoken',
					// The account key is a provider business/place ID —
					// identifying data the rest of the payload avoids, so it
					// is not transmitted.
					'provider' => 'account',
					'critical' => ! empty($error_types['accesstoken']['critical']),
				);
			}
			if (! empty($error_types['api'])) {
				++$out['account_errors'];
				// The stored option is populated from provider API responses —
				// force the code to an int before it enters the payload.
				$code = isset($error_types['api']['error']['code']) && is_scalar($error_types['api']['error']['code'])
					? (int) $error_types['api']['error']['code']
					: null;
				if (in_array($code, array( 190, 104, 999 ), true)) {
					++$out['token_errors'];
				}
				$out['latest'][] = array(
					'source'   => 'account',
					'id'       => 'api_' . (null === $code ? 'unknown' : $code),
					'provider' => 'account',
					'critical' => ! empty($error_types['api']['critical']),
				);
			}
		}

		return $out;
	}

	/**
	 * Resolve the license error state (pro only).
	 *
	 * @param array $settings   The sbr_settings option.
	 * @param array $raw_errors Entries from the sbr_errors option.
	 * @return string|null
	 */
	private function get_license_error(array $settings, array $raw_errors): ?string
	{
		if (! \SmashBalloon\Reviews\Common\Util::sbr_is_pro()) {
			return null;
		}

		$license_status = $settings['license_status'] ?? null;
		$license_error  = null;
		if ('deactivated' === $license_status) {
			$license_error = 'deactivated';
		} elseif ('invalid' === $license_status) {
			$license_error = 'invalid';
		} elseif (empty($settings['license_key'])) {
			$license_error = 'missing_key';
		}

		foreach ($raw_errors as $err) {
			// is_string, not just isset: the option is free-form and an array
			// haystack is an uncaught TypeError on PHP 8.
			if (isset($err['endpoint']) && is_string($err['endpoint']) && strpos($err['endpoint'], 'auth/license') !== false) {
				$license_error = $license_error ?? ('relay_auth_' . sanitize_text_field((string) ($err['id'] ?? 'unknown')));
			}
		}

		return $license_error;
	}

	/**
	 * Number of days in the period when the plugin was actively used.
	 *
	 * @param string $period_start Y-m-d.
	 * @param string $period_end   Y-m-d.
	 * @return int
	 */
	private function get_days_active(string $period_start, string $period_end): int
	{
		$dates = get_option(Config::OPTION_ACTIVE_DATES, array());
		if (! is_array($dates) || empty($dates)) {
			return 0;
		}
		$count = 0;
		$start = strtotime($period_start);
		$end   = strtotime($period_end);
		foreach ($dates as $d) {
			if (! is_string($d)) {
				continue;
			}
			$ts = strtotime($d);
			if (false !== $ts && $ts >= $start && $ts <= $end) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Average of last recorded session durations in seconds, 0 if not tracked.
	 *
	 * @return int
	 */
	private function get_session_duration(): int
	{
		$durations = get_option(Config::OPTION_SESSION_DURATIONS, array());
		if (! is_array($durations) || empty($durations)) {
			return 0;
		}
		return (int) round(array_sum($durations) / count($durations));
	}

	/**
	 * Event counts and last_date for each event from sbr_smash_usage_events.
	 *
	 * The store has held the name-keyed {count,last_date} map since the
	 * feature first shipped — no version ever wrote timestamped list entries,
	 * so there is no legacy-list branch here. If one ever fired it would
	 * double-report: reset_events_after_send() subtracts by event-name key,
	 * which can never match a numerically-keyed list.
	 *
	 * @param int $ts_start Period start timestamp (payload metadata only).
	 * @param int $ts_end   Period end timestamp (payload metadata only).
	 * @return array Event name => [ 'count' => int, 'last_date' => string|null ].
	 */
	private function get_events_for_period($ts_start, $ts_end)
	{
		unset($ts_start, $ts_end);

		$events = get_option(EventRecorder::OPTION_NAME, array());
		if (! is_array($events)) {
			return array();
		}

		// Accumulate-then-clear format: report all stored events regardless
		// of last_date. The period parameters are payload metadata only — filtering
		// by last_date would silently exclude events recorded today (period_end is
		// yesterday) and, on a site used every day, exclude them again every week.
		$out = array();
		foreach ($events as $name => $value) {
			if (! is_string($name) || '' === $name) {
				continue;
			}
			if (is_array($value) && isset($value['count'])) {
				$last_date    = isset($value['last_date']) && is_string($value['last_date']) ? $value['last_date'] : null;
				$out[ $name ] = array(
					'count'     => (int) $value['count'],
					'last_date' => $last_date,
				);
				continue;
			}
			if (is_numeric($value)) {
				$out[ $name ] = array(
					'count'     => (int) $value,
					'last_date' => null,
				);
			}
		}

		return $out;
	}

	/**
	 * Reduce a logged endpoint URL to its path only.
	 *
	 * Relay error entries store the full request URL, whose query string can
	 * carry provider credentials (e.g. Google's bare `key` parameter). Strip
	 * the query entirely and redact anything that still looks sensitive.
	 *
	 * @param string $url Full endpoint URL from the error log.
	 * @return string|null
	 */
	private function sanitize_endpoint($url)
	{
		$path = wp_parse_url($url, PHP_URL_PATH);
		if (! is_string($path) || '' === $path) {
			return null;
		}
		$path = $this->sanitize_error_message(sanitize_text_field($path));

		return '' === $path ? null : $path;
	}

	private function sanitize_error_message(string $message, int $max_len = 300): string
	{
		// Redact known credential key=value patterns
		$message = (string) preg_replace(
			'/\b(access_token|accesstoken|api_key|api_secret|client_id|client_secret|consumer_key|consumer_secret|secret_key|auth_token|refresh_token|private_key|token|key|place_id)\s*[=:]\s*["\']?[^\s&"\'\\\\,\]}\)]{4,}["\']?/i',
			'$1=[REDACTED]',
			$message
		);
		// Redact Bearer tokens
		$message = (string) preg_replace('/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message);
		if (strlen($message) > $max_len) {
			$message = substr($message, 0, $max_len) . '...';
		}
		return $message;
	}

	/**
	 * get_option() wrapper guaranteeing an array, since options can be
	 * corrupted into scalars.
	 *
	 * @param string $name Option name.
	 * @return array
	 */
	private function get_option_array(string $name): array
	{
		$value = get_option($name, array());

		return is_array($value) ? $value : array();
	}
}
