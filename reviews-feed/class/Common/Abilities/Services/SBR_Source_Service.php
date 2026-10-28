<?php

namespace SmashBalloon\Reviews\Common\Abilities\Services;

use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Connected-source (review-provider account/place) operations for the
 * Abilities API.
 *
 * Always redacted: built from an explicit allowlist, never from the hydrated
 * `sbr_sources` row. That row carries an `access_token` COLUMN (encrypted,
 * Facebook-only — DB::source_query()) and an `info` JSON blob that can itself
 * embed the same encrypted access_token — both structurally absent from
 * every method here. Only display fields (provider, place/business name,
 * rating, review count, how many feeds use it) are ever returned.
 *
 * No `get_connect_url()` here, unlike the Facebook/Twitter/Instagram ports:
 * Reviews Feed has no OAuth handshake to bootstrap. Google/Yelp/TripAdvisor/
 * TrustPilot/WordPress.org sources are added by looking up a business/place
 * name through the relay (SBR_Feed_Saver_Manager::process_source_apikey());
 * Facebook sources are added by pasting an access token retrieved from Meta
 * directly (Facebook::connect_manual_source()). Neither is a link an agent
 * can mint and hand to a human to complete.
 *
 * @since SBR-499
 */
class SBR_Source_Service
{
	/**
	 * ONE page of connected sources (DB::SOURCES_PER_PAGE = 20 per page) —
	 * the same page size the Settings page's own source list uses. Use
	 * count_sources() for the total.
	 *
	 * @param int $page 1-based page number.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sources($page = 1)
	{
		$out = array();
		foreach ((array) SBR_Sources::get_sources_list($this->list_args(array('page' => max(1, (int) $page)))) as $row) {
			if (!is_array($row) || empty($row['account_id'])) {
				continue;
			}
			$info = array();
			if (!empty($row['info']) && is_string($row['info'])) {
				$decoded = json_decode($row['info'], true);
				if (is_array($decoded)) {
					$info = $decoded;
				}
			}

			$out[] = array(
				'id'            => (string) $row['account_id'],
				'provider'      => isset($row['provider']) ? (string) $row['provider'] : '',
				'name'          => isset($row['name']) && $row['name'] !== ''
					? (string) $row['name']
					: (isset($info['name']) ? (string) $info['name'] : ''),
				'rating'        => isset($info['rating']) ? (float) $info['rating'] : 0.0,
				'review_count'  => isset($row['reviews_number'])
					? (int) $row['reviews_number']
					: (isset($info['total_rating']) ? (int) $info['total_rating'] : 0),
				'has_error'     => !empty($row['error']),
				'used_in_feeds' => isset($row['used_in']) ? (int) $row['used_in'] : 0,
			);
		}
		return $out;
	}

	/**
	 * @return int Total connected sources, across every page.
	 */
	public function count_sources()
	{
		return (int) SBR_Sources::get_sources_count($this->list_args(array()));
	}

	/**
	 * Free lists (and counts) only the providers its edition can use:
	 * `WHERE provider IN (...)` runs in the query so paging and `total` agree.
	 * Pro is unchanged -- it lists every connected row, and a provider its
	 * licence tier does not cover is reported by the write abilities
	 * (`feature_unavailable`) instead. Rows for providers free cannot use
	 * (a feed's sources after a Pro-to-free downgrade) are never shown.
	 *
	 * @param array<string, mixed> $args Base query args.
	 * @return array<string, mixed>
	 */
	private function list_args(array $args)
	{
		if (!Util::sbr_is_pro()) {
			$args['providers'] = self::allowed_providers();
		}
		return $args;
	}

	/**
	 * Providers a source may belong to for this edition + licence tier.
	 * The tier call is the SAME one the Settings page and Feed Builder use
	 * (single source of truth); free returns google + yelp. A Review
	 * Collection is a first-class `collection` row that CONTAINS already
	 * tier-checked sources, but Collections are a Pro feature, so it is
	 * allowlisted on Pro only.
	 *
	 * @return string[]
	 */
	public static function allowed_providers()
	{
		$allowed = (new AuthorizationStatusCheck())->get_tier_allowed_providers();
		if (Util::sbr_is_pro()) {
			$allowed[] = 'collection';
		}
		return array_values(array_unique($allowed));
	}

	/**
	 * account_id => provider for a set of requested ids, in ONE prepared query.
	 *
	 * Deliberately bypasses SBR_Sources::get_sources_list()/DB::source_query():
	 * its bare-args branch runs an unindexable `feeds.settings LIKE` join plus
	 * a correlated per-row post-count subquery — up to 21 queries for one
	 * 20-row page — to build display data (name, rating, feed usage, error
	 * state) resolution doesn't need; its `id`-array branch interpolates
	 * esc_sql()'d values into the SQL string instead of using $wpdb->prepare().
	 * resolve_allowed_sources() only ever needs account_id -> provider for the
	 * (≤20, per the input schemas) ids a single write request names, so this
	 * is one indexed, properly parameterised query instead — which also
	 * shrinks the in-memory footprint of anything read from `sbr_sources` to
	 * exactly two non-secret columns.
	 *
	 * @param string[] $ids Non-empty account_id values (may contain duplicates).
	 * @return array<string, string> account_id => provider.
	 */
	private function lookup_providers_by_ids(array $ids)
	{
		$ids = array_values(array_unique($ids));
		if (empty($ids)) {
			return array();
		}

		global $wpdb;
		$table        = $wpdb->prefix . SBR_SOURCES_TABLE;
		$placeholders = implode(', ', array_fill(0, count($ids), '%s'));
		// $table is $wpdb->prefix + the SBR_SOURCES_TABLE constant (not user
		// input), and $placeholders is a run of literal `%s` tokens sized to
		// count($ids) — every actual value is bound through $wpdb->prepare()
		// via the ...$ids spread below. Same dynamic-IN()-list pattern (and
		// same phpcs:ignore) as DB::sources_by_providers().
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$query = "SELECT account_id, provider FROM {$table} WHERE account_id IN ({$placeholders})";
		$sql   = $wpdb->prepare($query, ...$ids); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query's placeholders are filled by this same prepare() call.
		$rows  = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the string returned by $wpdb->prepare() above.

		$by_id = array();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (is_array($row) && isset($row['account_id'])) {
					$by_id[(string) $row['account_id']] = isset($row['provider']) && is_scalar($row['provider'])
						? (string) $row['provider']
						: '';
				}
			}
		}
		return $by_id;
	}

	/**
	 * Resolve requested source ids to rows that actually exist, split by
	 * whether the connected license tier is allowed to use that provider.
	 *
	 * Tier gate is AuthorizationStatusCheck::get_tier_allowed_providers() —
	 * the SAME live check the Settings page / Feed Builder use (Common/
	 * AuthorizationStatusCheck.php) — instantiated fresh here rather than
	 * cached, so a just-activated or just-expired license takes effect
	 * immediately. On Pro, `collection` is explicitly allowlisted regardless
	 * of that list: a Review Collection (provider = 'collection', see
	 * SBR_Feed_Saver_Manager.php:131-139) is a first-class sbr_sources row
	 * that CONTAINS already tier-checked sources — it is not itself a
	 * provider a license tier gates. On Free it is not allowed at all.
	 *
	 * Empty-string entries in $requested_ids are dropped up front rather than
	 * reported as `missing` — a blank id is never a real source reference, so
	 * surfacing it would only render as a bare comma in a "not found" message
	 * (see SBR_Ability_Response::source_not_found()'s own, second guard for
	 * the same thing).
	 *
	 * @param string[] $requested_ids account_id values (SBR_List_Sources' `id`).
	 * @return array{ids: string[], ignored: array<string,string>, missing: string[], providers: array<string,string>}
	 *         `ignored` maps id => 'feature_unavailable' for tier-locked providers (Pro only; on
	 *         Free a provider outside the edition is reported in `missing`).
	 *         `providers` maps every resolved id (in `ids`) to its provider slug.
	 */
	public function resolve_allowed_sources(array $requested_ids)
	{
		$trimmed = array();
		foreach ($requested_ids as $requested) {
			$requested = trim((string) $requested);
			if ($requested !== '') {
				$trimmed[] = $requested;
			}
		}
		// Repeated ids would otherwise be stored twice on the feed (and reported twice).
		$trimmed = array_values(array_unique($trimmed));

		$by_id = $this->lookup_providers_by_ids($trimmed);

		$allowed_providers = self::allowed_providers();
		$is_pro            = Util::sbr_is_pro();

		$ids       = array();
		$ignored   = array();
		$missing   = array();
		$providers = array();

		foreach ($trimmed as $requested) {
			if (!isset($by_id[$requested])) {
				$missing[] = $requested;
				continue;
			}
			$provider = $by_id[$requested];
			if ($provider !== '' && !in_array($provider, $allowed_providers, true)) {
				if (!$is_pro) {
					// Free never listed this row (list_sources() filters to the
					// edition's providers), so it is "not found" here, not a
					// tier lock an agent could try to unlock.
					$missing[] = $requested;
					continue;
				}
				$ignored[$requested] = 'feature_unavailable';
				continue;
			}
			$ids[]                  = $requested;
			$providers[$requested]  = $provider;
		}

		return array('ids' => $ids, 'ignored' => $ignored, 'missing' => $missing, 'providers' => $providers);
	}
}
