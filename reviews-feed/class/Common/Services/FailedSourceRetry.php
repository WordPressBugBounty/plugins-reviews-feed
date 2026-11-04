<?php

/**
 * Feeds holding a failed source, due for an early retry. SMASH-2409 (Fallback Sources T6b).
 */

namespace SmashBalloon\Reviews\Common\Services;

use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Utils\SourceFetchOutcome;

/**
 * A failed source (SMASH-2408's `sources.error` state) is retried by the hourly cache cron after
 * min(2^attempts, 12) hours instead of waiting for the 12-hour refresh. `config` failures are never retried, and a
 * `relay` failure (the relay itself, the same for every source) retries one feed per site per backoff window.
 */
class FailedSourceRetry
{
	public const MAX_BACKOFF_HOURS = 12;

	/** Until this time (unix), no other relay-scope retry runs on this site. */
	public const RELAY_RETRY_OPTION = 'sbr_relay_failure_retry_until';

	/**
	 * Schedule this run's retries and return their feed ids, at most $limit. Writes: each retried source's `attempts`
	 * goes up by one (a scheduled retry counts), and a relay-only retry closes the site's relay window. One feed per
	 * failing account: a source shared by several feeds is retried once per run.
	 *
	 * @param list<int> $already_due Feeds the 12-hour query already refreshes this run; never added twice.
	 * @param int       $limit       Retries this run may add; the rest wait for the next hourly tick.
	 * @return list<int>
	 */
	public function schedule_retries(array $already_due = array(), int $limit = PHP_INT_MAX)
	{
		$failed = $this->retryable_failed_sources();
		if ($failed === array()) {
			return array();
		}

		$this->last_fetched = $this->posts_last_updated();
		$until              = get_option(self::RELAY_RETRY_OPTION, 0);
		$this->relay_open   = (is_numeric($until) ? (int) $until : 0) <= time();
		$due                = array();
		$retried            = array();

		foreach ($this->feed_sources() as $feed_id => $account_ids) {
			if (count($due) >= $limit) {
				break;
			}
			// Sources this run already retries through another feed don't make this one due.
			$failing = array_values(array_diff_key(array_intersect_key($failed, array_flip($account_ids)), $retried));
			if (in_array($feed_id, $already_due, true) || ! $this->is_feed_due($feed_id, $failing)) {
				continue;
			}

			$due[] = $feed_id;
			foreach ($failing as $source) {
				$retried[ $source['account_id'] ] = $source;
			}
		}

		$this->count_attempts($retried);

		return $due;
	}

	/** @var array<int, int> Each feed's last live `posts` write, for the run in progress. */
	private $last_fetched = array();

	/** @var bool Whether a relay-only retry may still run in this window. */
	private $relay_open = true;

	/**
	 * Is this feed due: a failing source, past its backoff, and (relay-only) inside an open relay window? Closes the
	 * window when it lets a relay-only feed through: every source fails the same way, so one feed tells.
	 *
	 * @param list<array{account_id: string, provider: string, id: string, scope: string, attempts: int, since: int}> $failing
	 */
	private function is_feed_due(int $feed_id, array $failing): bool
	{
		if ($failing === array()) {
			return false;
		}
		$relay_only = count(array_filter($failing, fn ($source) => $source['scope'] !== SourceFetchOutcome::SCOPE_RELAY)) === 0;
		if ($relay_only && ! $this->relay_open) {
			return false;
		}

		$backoff = self::backoff_seconds(min(array_map(fn ($source) => $source['attempts'], $failing)));
		if (isset($this->last_fetched[ $feed_id ]) && time() - $this->last_fetched[ $feed_id ] < $backoff) {
			return false;
		}

		if ($relay_only) {
			update_option(self::RELAY_RETRY_OPTION, time() + $backoff, false);
			$this->relay_open = false;
		}

		return true;
	}

	/** min(2^attempts, 12) hours, in seconds. */
	public static function backoff_seconds(int $attempts): int
	{
		$hours = min(self::MAX_BACKOFF_HOURS, 2 ** min(4, max(0, $attempts)));

		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Failed sources by account id, without `config` ones (an inactive Woo/EDD or Pro-only source: a retry can't help).
	 *
	 * @return array<string, array{account_id: string, provider: string, id: string, scope: string, attempts: int, since: int}>
	 */
	private function retryable_failed_sources()
	{
		global $wpdb;
		$table = $wpdb->prefix . SBR_SOURCES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a constant.
		$rows = $wpdb->get_results("SELECT account_id, provider, error FROM $table WHERE error <> ''", ARRAY_A);

		$failed = array();
		foreach ((array) $rows as $row) {
			$state = is_array($row) ? SourceFetchOutcome::decode_state(isset($row['error']) ? (string) $row['error'] : '') : null;
			if ($state === null || $state['scope'] === SourceFetchOutcome::SCOPE_CONFIG || empty($row['account_id'])) {
				continue;
			}
			$failed[ (string) $row['account_id'] ] = $state + array(
				'account_id' => (string) $row['account_id'],
				'provider'   => (string) $row['provider'],
			);
		}

		return $failed;
	}

	/**
	 * Each feed's source account ids, read from its settings in PHP: a SQL LIKE on `42` would also match `142`.
	 *
	 * @return array<int, list<string>>
	 */
	private function feed_sources()
	{
		global $wpdb;
		$table = $wpdb->prefix . SBR_FEEDS_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a constant.
		$rows = $wpdb->get_results("SELECT id, settings FROM $table", ARRAY_A);

		$feeds = array();
		foreach ((array) $rows as $row) {
			$settings = is_array($row) && isset($row['settings']) ? json_decode((string) $row['settings'], true) : null;
			$sources  = is_array($settings) && isset($settings['sources']) ? $settings['sources'] : array();
			if (is_string($sources)) {
				$sources = explode(',', $sources);
			}
			$feeds[ (int) $row['id'] ] = array_values(array_map('strval', array_filter((array) $sources, 'is_scalar')));
		}

		return $feeds;
	}

	/**
	 * When each feed's reviews were last written: its `posts` cache row, WPML-suffixed or not, never a customizer row.
	 *
	 * @return array<int, int>
	 */
	private function posts_last_updated()
	{
		global $wpdb;
		$table = $wpdb->prefix . SBR_FEED_CACHES_TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix and a constant.
		$rows = $wpdb->get_results("SELECT feed_id, last_updated FROM $table WHERE cache_key = 'posts'", ARRAY_A);

		$latest = array();
		foreach ((array) $rows as $row) {
			$key = is_array($row) && isset($row['feed_id']) && is_scalar($row['feed_id']) ? (string) $row['feed_id'] : '';
			// Any customizer row (`5_CUSTOMIZER`, `5_fr_CUSTOMIZER`, `_MODMODE`) is a preview, not the live feed. A WPML
			// suffix is any code Util lists (`es-419`, `fil` included) or `default` (SBR_WPML::get_current_language()).
			if (stripos($key, '_CUSTOMIZER') !== false || preg_match('/^(\d+)(?:_(?:default|[a-z]{2,3}(?:[-_][a-z0-9]+)?))?$/i', $key, $m) !== 1) {
				continue;
			}
			$time = strtotime((string) $row['last_updated'] . ' UTC');
			if ($time !== false) {
				$latest[ (int) $m[1] ] = max($latest[ (int) $m[1] ] ?? 0, $time);
			}
		}

		return $latest;
	}

	/**
	 * One more attempt on each source the cron is about to retry; `id`, `scope` and `since` unchanged.
	 *
	 * @param array<string, array{account_id: string, provider: string, id: string, scope: string, attempts: int, since: int}> $retried
	 */
	private function count_attempts(array $retried): void
	{
		$db = new DB();
		foreach ($retried as $source) {
			$db->source_set_fetch_state($source['account_id'], $source['provider'], (string) wp_json_encode(array(
				'id'       => $source['id'],
				'scope'    => $source['scope'],
				'attempts' => $source['attempts'] + 1,
				'since'    => $source['since'],
			)));
		}
	}
}
