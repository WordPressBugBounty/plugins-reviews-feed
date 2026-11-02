<?php

namespace SmashBalloon\Reviews\Common\Abilities\Services;

use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver;
use SmashBalloon\Reviews\Common\Clear_Cache;
use SmashBalloon\Reviews\Common\Customizer\DB;
use SmashBalloon\Reviews\Common\Customizer\SBR_Feed_Style_Generator;
use SmashBalloon\Reviews\Common\Traits\SBR_Feed_Templates_Settings;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Wraps SBR_Feed_Saver / the `sbr_feeds` table and REDACTS everything an
 * agent must not see. Responses are built from SBR_Settings_Schema's typed
 * allowlist, never from a hydrated settings array — the `sources` key only
 * ever carries source account ids here (SBR_Source_Service::list_sources()
 * is the one place a source's display name/rating is exposed).
 *
 * Raw-row merge (SBR-499 hard rule, mirrors AgDR-0503 / the CFF + CTF ports):
 * SBR_Feed_Saver::update() REPLACES the entire `settings` JSON column with
 * whatever is in $this->data — it does not merge. So every write here reads
 * the CURRENT `settings` column directly with json_decode(), merges the
 * validated partial into THAT, and hands the merged array back to
 * set_data(). Feed_name and feed_style are equally load-bearing: update()
 * unconditionally re-writes both from SBR_Feed_Saver's $feed_name/$feed_style
 * properties (SBR_Feed_Saver.php:274-282) — never touching set_feed_name()/
 * set_feed_style() would silently blank the feed's name and its pre-rendered
 * CSS on every settings-only save, so both are re-set before calling
 * update_or_insert(): the name from the current row, the CSS regenerated from
 * the merged settings the way a Feed Builder save does (SMASH-2598).
 *
 * @since SBR-499
 */
class SBR_Feed_Settings_Service
{
	use SBR_Feed_Templates_Settings;

	/**
	 * Apply a feed template's forced settings the same way the Feed Builder's
	 * own new-feed path does (SBR_Feed_Saver_Manager::builder_update() calls
	 * this exact trait method, in the same last-among-settings position).
	 * Overwrites whatever the caller already set for the keys the template
	 * controls — that is the plugin's real behaviour for a NEW feed, not a
	 * bug this wrapper introduces (AgDR-0504 finding C2). Callers that need to
	 * report the override should diff their input against the return value.
	 *
	 * @param array $settings Partial settings, including `feedTemplate`.
	 * @return array
	 */
	public static function apply_feed_template(array $settings)
	{
		return self::get_feed_settings_by_feed_templates($settings);
	}

	/**
	 * @param int $page 1-based page number (DB::RESULTS_PER_PAGE = 20 per page).
	 * @return array{feeds:array<int,array<string,mixed>>,total:int,per_page:int,total_pages:int}
	 */
	public function list_feeds($page = 1)
	{
		$page = max(1, (int) $page);
		$db   = new DB();
		$rows = (array) $db->feeds_query(array('page' => $page));
		$out  = array();
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['id'])) {
				continue;
			}
			$settings = $this->decode_settings_column(isset($row['settings']) ? $row['settings'] : null);
			$out[]    = array(
				// `id` is the canonical key (matches the other Smash Balloon ports);
				// `feed_id` is kept as a deprecated alias with the same value.
				'id'            => (int) $row['id'],
				'feed_id'       => (int) $row['id'],
				'feed_name'     => isset($row['feed_name']) ? (string) $row['feed_name'] : '',
				'status'        => isset($row['status']) ? (string) $row['status'] : '',
				'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : '',
				'source_count'  => isset($settings['sources']) && is_array($settings['sources'])
					? count($settings['sources'])
					: 0,
			);
		}

		// AgDR-0504 finding C6: list-feeds reported `page` only, letting an
		// agent believe a 20-row page WAS the whole result set. Report the
		// real total so an agent knows to keep paging.
		$total = DB::feeds_list_count();

		return array(
			'feeds'       => $out,
			'total'       => $total,
			'per_page'    => DB::RESULTS_PER_PAGE,
			'total_pages' => max(1, (int) ceil($total / DB::RESULTS_PER_PAGE)),
		);
	}

	/**
	 * @param int $feed_id
	 * @return array|null Null when the feed does not exist.
	 */
	public function get_feed($feed_id)
	{
		$row = $this->get_row($feed_id);
		if ($row === null) {
			return null;
		}
		$settings = $this->decode_settings_column($row['settings']);

		return array(
			'feed_id'       => $row['id'],
			'feed_name'     => $row['feed_name'],
			'status'        => $row['status'],
			'last_modified' => $row['last_modified'],
			'sources'       => isset($settings['sources']) && is_array($settings['sources'])
				? array_values(array_map('strval', $settings['sources']))
				: array(),
			'settings'      => $this->redact_settings($settings),
			'warnings'      => $this->downgrade_warnings($settings),
		);
	}

	/**
	 * Downgrade notes for get-feed (AgDR-9005 section 3). A key free KEEPS but
	 * whose stored value is Pro-only (a feed left on `layout: carousel` after a
	 * Pro-to-free downgrade) reads back as stored; this tells the agent why it
	 * cannot write that value back. Dropped keys are not reported at all.
	 * Always empty on Pro.
	 *
	 * @param array $settings Raw decoded settings.
	 * @return string[]
	 */
	private function downgrade_warnings(array $settings)
	{
		$warnings = array();
		foreach (SBR_Settings_Schema::pro_only_stored_values($settings) as $key => $value) {
			$warnings[] = sprintf(
				/* translators: 1: setting key, 2: stored value */
				__('%1$s is stored as "%2$s", a Reviews Feed Pro option. It is kept as stored, but this edition cannot set it and updates will not change it unless you choose a free value.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				$key,
				$value
			);
		}
		return $warnings;
	}

	/**
	 * @param string $feed_name
	 * @param array  $source_ids Already tier/existence-validated account ids.
	 * @param array  $partial    Schema-validated feed-setting overrides.
	 * @return array{feed_id:int}|\WP_Error
	 */
	public function create_feed($feed_name, array $source_ids, array $partial)
	{
		// `false` = new feed (not yet inserted) — the same call shape as
		// SBR_Feed_Saver_Manager::import_feed().
		$saver = new SBR_Feed_Saver(false);

		// Only the keys passed here are stored; SBR_Feed_Saver::get_feed_settings()
		// fills every other key from sbr_settings_defaults() at READ time
		// (wp_parse_args) — the same partial-write convention the Feed Builder's
		// own builder_update() AJAX handler relies on, so there is no need to
		// materialize a full settings model here.
		$settings            = $partial;
		$settings['sources'] = array_values($source_ids);

		$saver->set_feed_name(sanitize_text_field($feed_name));
		$saver->set_feed_style('');
		$saver->set_data($settings);

		// insert() returns the new row's insert id (never legitimately 0) or
		// `false` on failure — an explicit `=== false` (not a falsy check)
		// keeps this correct if the DB layer's return shape ever changes to an
		// affected-rows count, as feeds_update() already does (see the
		// update_or_insert() note on update_feed_settings() below).
		$feed_id = $saver->update_or_insert();
		if ($feed_id === false) {
			return new \WP_Error(
				'sbr_create_feed_failed',
				__('The feed could not be created.', 'reviews-feed'),
				array('status' => 500)
			);
		}

		// The Builder writes a new feed's CSS on its first save; an agent's feed gets it here (SMASH-2598).
		SBR_Feed_Style_Generator::ensure_stored((int) $feed_id, array_merge($settings, array('feed_name' => sanitize_text_field($feed_name))));

		return array('feed_id' => (int) $feed_id);
	}

	/**
	 * @param int   $feed_id
	 * @param array $partial Schema-validated feed-setting overrides.
	 * @return array{updated:string[]}|\WP_Error
	 */
	public function update_feed_settings($feed_id, array $partial)
	{
		$row = $this->get_row($feed_id);
		if ($row === null) {
			return new \WP_Error(
				'sbr_feed_not_found',
				sprintf(
					/* translators: %d: feed id */
					__('Feed %d was not found.', 'reviews-feed'),
					(int) $feed_id
				),
				array('status' => 404)
			);
		}

		if ($this->settings_column_is_corrupt($row['settings'])) {
			return $this->corrupt_settings_error($feed_id);
		}
		$settings = $this->decode_settings_column($row['settings']);
		$settings = array_merge($settings, $partial);

		// A Builder save regenerates feed_style from the settings, so this does too (SMASH-2598).
		$feed_style = SBR_Feed_Style_Generator::generate(array_merge($settings, array('feed_name' => $row['feed_name'])), (int) $feed_id);

		$saver = new SBR_Feed_Saver((int) $feed_id);
		// Preserve the name — see the raw-row-merge note in the class docblock.
		$saver->set_feed_name($row['feed_name']);
		$saver->set_feed_style($feed_style !== '' ? $feed_style : $row['feed_style']);
		$saver->set_data($settings);

		// update() (an existing feed, the path taken here) returns
		// feeds_update()'s $wpdb->update() result: `false` on a real error, or
		// the number of AFFECTED ROWS — which is legitimately 0 when every
		// column (last_modified included) already holds the value being
		// written, e.g. a same-second no-op re-save. A falsy check treated
		// that success as a failed write and 500'd; only `false` is a failure.
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'sbr_update_feed_failed',
				__('The feed settings could not be updated.', 'reviews-feed'),
				array('status' => 500)
			);
		}

		// Replicate the plugin's own per-feed cache-clear primitive
		// (SBR_Sources::update_collection_ratings() calls the same method) —
		// no invented cache logic, no TRUNCATE.
		Clear_Cache::clear_feed_caches_by_id(array((int) $feed_id, (int) $feed_id . '_CUSTOMIZER'));

		/**
		 * Fires after an AI agent updated one feed's settings through the
		 * Abilities API. Symmetry with sbr_abilities_global_settings_updated.
		 *
		 * @param int      $feed_id
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sbr_abilities_feed_settings_updated', (int) $feed_id, array_keys($partial));

		return array('updated' => array_keys($partial));
	}

	/**
	 * @param int      $feed_id
	 * @param string[] $source_ids Already tier/existence-validated account ids.
	 * @return array{sources:string[]}|\WP_Error
	 */
	public function set_feed_sources($feed_id, array $source_ids)
	{
		$row = $this->get_row($feed_id);
		if ($row === null) {
			return new \WP_Error(
				'sbr_feed_not_found',
				sprintf(
					/* translators: %d: feed id */
					__('Feed %d was not found.', 'reviews-feed'),
					(int) $feed_id
				),
				array('status' => 404)
			);
		}

		if ($this->settings_column_is_corrupt($row['settings'])) {
			return $this->corrupt_settings_error($feed_id);
		}

		$settings             = $this->decode_settings_column($row['settings']);
		$settings['sources']  = array_values($source_ids);

		$saver = new SBR_Feed_Saver((int) $feed_id);
		$saver->set_feed_name($row['feed_name']);
		$saver->set_feed_style($row['feed_style']);
		$saver->set_data($settings);

		// See the update_or_insert() note in update_feed_settings() above: 0
		// affected rows on a same-second no-op re-save is success, not `false`.
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'sbr_update_feed_failed',
				__('The feed sources could not be updated.', 'reviews-feed'),
				array('status' => 500)
			);
		}

		Clear_Cache::clear_feed_caches_by_id(array((int) $feed_id, (int) $feed_id . '_CUSTOMIZER'));

		return array('sources' => array_values($source_ids));
	}

	/**
	 * @param int $feed_id
	 * @return array{id:int,feed_name:string,feed_style:string,status:string,last_modified:string,settings:string}|null
	 *         Null when the feed does not exist.
	 */
	private function get_row($feed_id)
	{
		$db   = new DB();
		$rows = $db->feeds_query(array('id' => (int) $feed_id));
		// feeds_query()'s inherited return type is
		// documented as array|bool; a `true` row-count result is never actually
		// returned for an `id`-keyed lookup, but is_array() below keeps this
		// defensive against that documented shape regardless.
		if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
			return null;
		}
		$row = $rows[0];

		return array(
			'id'            => isset($row['id']) ? (int) $row['id'] : 0,
			'feed_name'     => isset($row['feed_name']) ? (string) $row['feed_name'] : '',
			'feed_style'    => isset($row['feed_style']) ? (string) $row['feed_style'] : '',
			'status'        => isset($row['status']) ? (string) $row['status'] : '',
			'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : '',
			'settings'      => isset($row['settings']) ? (string) $row['settings'] : '',
		);
	}

	/**
	 * True when the stored `settings` JSON is non-empty but does not decode to an
	 * array. The write paths read-modify-write this column; decoding corrupt JSON
	 * as "no settings" and saving the merge would silently wipe every stored
	 * setting (sources included), so they refuse instead.
	 *
	 * @param mixed $raw
	 * @return bool
	 */
	private function settings_column_is_corrupt($raw)
	{
		if (!is_string($raw) || $raw === '') {
			return false;
		}
		$decoded = json_decode($raw, true);
		return json_last_error() !== JSON_ERROR_NONE || !is_array($decoded);
	}

	/**
	 * @param int $feed_id
	 * @return \WP_Error
	 */
	private function corrupt_settings_error($feed_id)
	{
		return new \WP_Error(
			'sbr_feed_settings_corrupt',
			sprintf(
				/* translators: %d: feed id */
				__('Feed %d has unreadable stored settings, so it was not changed. Open the feed in the Reviews Feed editor to repair it.', 'reviews-feed'),
				(int) $feed_id
			),
			array('status' => 409)
		);
	}

	/**
	 * @param mixed $raw The `settings` column's raw JSON string.
	 * @return array
	 */
	private function decode_settings_column($raw)
	{
		if (!is_string($raw) || $raw === '') {
			return array();
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : array();
	}

	/**
	 * @param array $settings Raw decoded settings (unfiltered).
	 * @return array<string, mixed> Allowlisted subset only.
	 */
	private function redact_settings(array $settings)
	{
		// create_feed() deliberately stores only the keys it was given —
		// SBR_Feed_Saver::get_feed_settings() fills every other key from
		// sbr_settings_defaults() at READ time (wp_parse_args), which is what
		// the plugin's own builder relies on to render an unset key correctly.
		// Passing `null` as cast_out()'s default here would instead fall back
		// to the generic per-type zero value (false / 0 / '') for every key
		// this feed never stored — get-feed would misreport nearly every
		// unset field (showHeader as false, numPostDesktop as 1, colours as
		// '', …), and an agent that reads a feed, tweaks one key, and writes
		// the result back would silently persist all those wrong values as
		// real overrides. Threading the plugin's OWN defaults through (same
		// as SBR_Global_Settings_Service::get()) is what makes a
		// read-modify-write round trip safe.
		$defaults = function_exists('sbr_settings_defaults') ? sbr_settings_defaults() : array();
		// READ view: keeps Pro-only enum values so a downgraded feed reads back
		// what it stores (AgDR-9005 section 3).
		$schema   = SBR_Settings_Schema::feed_settings_read();
		$out      = array();
		foreach ($schema as $key => $def) {
			$out[$key] = SBR_Settings_Schema::cast_out(
				$def,
				array_key_exists($key, $settings) ? $settings[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		return $out;
	}
}
