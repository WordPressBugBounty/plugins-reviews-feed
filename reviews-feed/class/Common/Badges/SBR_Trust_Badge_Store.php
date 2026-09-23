<?php

namespace SmashBalloon\Reviews\Common\Badges;

use SmashBalloon\Reviews\Common\Customizer\DB;
use WP_Query;

/**
 * Storage for saved Trust Badges (SBR-163 / design rock SBR-78).
 *
 * A badge stops being "a set of shortcode attributes" and becomes a saved
 * entity you create once, style once, name, and then place in several spots —
 * the shape the design rock asks for, and the same shape Review Alerts already
 * uses. This class is the storage half of that, modelled directly on
 * SBR_Review_Alert_Service so the two read as one product:
 *
 *   - a private custom post type (`show_ui` false — the React screen is the UI)
 *   - settings as one JSON post meta blob
 *   - get / list / save / delete / duplicate
 *
 * Deliberately NOT in here: rendering. SBR_Badge_Renderer stays the only thing
 * that emits badge markup, and a saved badge is just another source of the same
 * argument array. That keeps the shortcode, block and widget rails working
 * exactly as they do now, and means a saved badge cannot drift from an inline
 * one.
 */
class SBR_Trust_Badge_Store
{
	/**
	 * The whole placement map for the request, badge id => rows.
	 *
	 * @var array<int, array<int, array<int, array{ID: int, post_title: string, post_type: string}>>|false>
	 */
	private static $instance_map = array();

	/**
	 * Memoised builder-meta scans for the request, keyed on the meta-key set.
	 *
	 * @var array<string, array<int, array{ID: int, post_title: string, post_type: string, meta_value: string}>>
	 */
	private static $meta_rows = array();

	/**
	 * Post type holding saved badges.
	 */
	const POST_TYPE = 'sbr_trust_badge';

	/**
	 * Post meta key holding the settings JSON.
	 */
	const META_SETTINGS = '_sbr_badge_settings';

	/**
	 * Rows per page on the All Badges list. 20, matching Review Alerts
	 * (SBR_Review_Alert_Service) and the feeds list.
	 *
	 * The client derives its page count from this, so it is sent to the screen
	 * rather than repeated there — the two used to be separate 50s, and a list of
	 * 23 badges computed one page and rendered no controls at all.
	 */
	const PER_PAGE = 20;

	/**
	 * When this badge's email image was last generated, as a GMT timestamp.
	 *
	 * Its own meta key, NOT part of the settings blob: this is not something the
	 * user chose, it is something that happened. Keeping it out of settings means
	 * sanitize_settings() cannot blank it and a Save from the customizer cannot
	 * quietly un-record an embed that exists.
	 */
	const META_EMAIL_EMBED = '_sbr_badge_email_embed';

	/**
	 * The control vocabulary. Public because the builder hands these to React —
	 * a screen that kept its own copy would drift, and on drift it would offer a
	 * value sanitize_settings() rejects: the save returns success and the badge
	 * silently reverts to a default with nothing surfaced to the user.
	 */
	const STYLES      = array( 'chip', 'card', 'medal' );
	const THEMES      = array( 'light', 'dark', 'tinted' );
	const LOGO_STYLES = array( 'full', 'icon' );
	const LOGO_COLORS = array( 'auto', 'brand', 'mono' );

	/**
	 * Register the post type. Called on `init`.
	 *
	 * @return void
	 */
	public static function register_post_type()
	{
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __('Trust Badges', 'reviews-feed'),
					'singular_name' => __('Trust Badge', 'reviews-feed'),
				),
				// Same posture as Review Alerts: invisible to WP's own UI,
				// never public, never queryable. The badge reaches a page
				// through a shortcode/block/widget, not through a permalink.
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'can_export'          => true,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'capability_type'     => 'post',
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Default settings for a new badge.
	 *
	 * Mirrors the design rock's control set: style x theme x accent, plus the
	 * show/hide element list.
	 *
	 * These are the store's names, not the renderer's. SBR_Trust_Badge_Builder
	 * maps one vocabulary to the other before render — the store speaks
	 * style / theme / accent / logo_*, the renderer speaks shape / theme /
	 * accent_hue / logo_*. A saved badge reaches the front end through
	 * SBR_Badge_Service::render_saved_shortcode(), which reads this class.
	 *
	 * @return array{style: string, theme: string, accent: string, tint_hue: int, sources: array<int, string>, show_rating: bool, show_stars: bool, show_count: bool, show_logos: bool, logo_style: string, logo_color: string, show_footer: bool, footer_text: string, footer_url: string, powered_by: bool}
	 */
	public static function defaults()
	{
		return array(
			'style'         => 'card',
			'theme'         => 'light',
			'accent'        => '',
			'tint_hue'      => 250,
			'sources'       => array(),
			'show_rating'   => true,
			'show_stars'    => true,
			'show_count'    => true,
			'show_logos'    => true,
			// The design's defaults, not ours. `full` auto-expands a
			// single-source badge to the wordmark without anyone touching the
			// control (it resolves to `icon` the moment a second source joins),
			// and `auto` is what makes the logo colour follow the theme —
			// brand on light, mono on dark. We shipped `icon` + `brand`, which
			// meant a new badge never showed a wordmark and its logos stayed
			// brand-coloured on a dark surface.
			'logo_style'    => 'full',
			'logo_color'    => 'auto',
			// The design's pair, and they only make sense together: the footer
			// starts HIDDEN with copy already in the box, so switching it on
			// immediately shows something worth showing. The copy names the
			// company, matching the design and matching Review Alerts, which
			// prints "Powered by" plus the Smash Balloon wordmark
			// (templates/review-alerts/popup.php:331). It said "Reviews Feed"
			// until 2026-09-01; three surfaces gave three answers and this is
			// the one they were aligned onto. Saved badges are NOT migrated —
			// footer_text is copy an author owns.
			//
			// We shipped the inverse — shown, with empty text. The two mistakes
			// cancelled out on screen (an empty footer renders no strip), which
			// is why it read as correct: the badge looked right while the
			// Show/Hide list claimed the Footer was visible and toggling it did
			// nothing either way.
			'show_footer'   => false,
			'footer_text'   => 'Powered by Smash Balloon',
			'footer_url'    => '',
			// Stored but no longer read on any saved-badge path: render_saved()
			// passes false and the image asks the tier directly. Kept because
			// dropping a key from this allowlist makes sanitize_settings()
			// discard it from every blob that already has one, and the value is
			// harmless. The tier gate is what decides the attribution.
			'powered_by'    => true,
			// Which feed supplies the numbers. 0 means "work it out from the
			// sources", which is the normal case and what the design assumes.
			// It exists because SBR_Trust_Badge_Builder::feed_for_badge() reads
			// it — a branch that could never run while the key was absent from
			// this allowlist, since sanitize_settings() drops anything not
			// listed here. Additive and defaulted to 0, so every stored badge
			// keeps resolving exactly as before.
			'feed_id'       => 0,
		);
	}

	/**
	 * Read one badge.
	 *
	 * @param int $id Post ID.
	 *
	 * @return array{id: int, name: string, status: string, modified: string, settings: array{style: string, theme: string, accent: string, tint_hue: int, sources: array<int, string>, show_rating: bool, show_stars: bool, show_count: bool, show_logos: bool, logo_style: string, logo_color: string, show_footer: bool, footer_text: string, footer_url: string, powered_by: bool}}|null
	 *         Null when it does not exist or is the wrong type.
	 */
	public static function get($id)
	{
		$id   = (int) $id;
		$post = $id > 0 ? get_post($id) : null;

		if (! $post || $post->post_type !== self::POST_TYPE) {
			return null;
		}

		$stored = get_post_meta($id, self::META_SETTINGS, true);

		if (is_string($stored)) {
			$decoded = json_decode($stored, true);
			$stored  = is_array($decoded) ? $decoded : array();
		}

		if (! is_array($stored)) {
			$stored = array();
		}

		return array(
			'id'       => $id,
			'name'     => $post->post_title,
			'status'   => $post->post_status,
			'modified' => $post->post_modified_gmt,
			// Sanitised on the way OUT as well as in. Two reasons beyond
			// tidiness: a blob saved before a new option existed reads back with
			// that option at its default rather than missing, so no consumer
			// needs its own isset() dance; and post meta is editable by anything
			// with DB access, so re-validating here means a hand-edited style can
			// never reach a template path.
			'settings' => self::sanitize_settings($stored),
		);
	}

	/**
	 * Badges as pickable choices, with the total so a caller can say what is hidden.
	 *
	 * One implementation for the two pickers. The block service and the widget each
	 * carried a copy of this loop and had already drifted apart: the service
	 * hardcoded `100` where the widget named `CHOICE_LIMIT`, the service labelled an
	 * untitled badge `Badge #%d` and the widget `Badge %d` (two translation strings
	 * for one concept), and only the widget guarded `items` for existence and
	 * recorded `total`. Any fix — paging past the ceiling, say — had to be made
	 * twice.
	 *
	 * `total` is the number that EXISTS, not the number returned, so a caller above
	 * the cap can tell the author the rest are still there.
	 *
	 * @param int $limit How many to offer. Defaults to CHOICE_LIMIT.
	 *
	 * @return array{choices: array<int, array{id: int, name: string}>, total: int}
	 */
	public static function choices($limit = self::CHOICE_LIMIT)
	{
		$limit = max(1, (int) $limit);
		$list  = self::list_badges(array( 'per_page' => $limit ));

		$choices = array();
		$items   = isset($list['items']) && is_array($list['items']) ? $list['items'] : array();

		foreach ($items as $badge) {
			$id = isset($badge['id']) ? absint($badge['id']) : 0;

			if ($id < 1) {
				continue;
			}

			$name = isset($badge['name']) && is_scalar($badge['name']) ? (string) $badge['name'] : '';

			$choices[] = array(
				'id'   => $id,
				// An untitled badge still has to be selectable, so fall back to the
				// id rather than offering a blank row.
				'name' => $name !== '' ? $name : sprintf(
					/* translators: %d: badge ID. */
					__('Badge #%d', 'reviews-feed'),
					$id
				),
			);
		}

		return array(
			'choices' => $choices,
			'total'   => isset($list['total']) && is_numeric($list['total']) ? (int) $list['total'] : 0,
		);
	}

	/**
	 * List badges, newest first.
	 *
	 * @param array<string, mixed> $args Optional `per_page`, `page`, `search`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 *
	 * @phpstan-return array{items: list<array<string, mixed>>, total: int}
	 */
	public static function list_badges($args = array())
	{
		// (int), not absint(): absint folds the sign, so `page=-5` asked for page 5
		// and `per_page=-5` for five rows. Both arrive from a request, and is_numeric
		// keeps a non-number out of the cast rather than letting it read as 0.
		$per_page = isset($args['per_page']) && is_numeric($args['per_page'])
			? max(1, min(100, (int) $args['per_page']))
			: 20;
		$page = isset($args['page']) && is_numeric($args['page'])
			? max(1, (int) $args['page'])
			: 1;

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			// ID breaks the tie, or pagination is not stable: badges saved in the
			// same second share post_modified, MySQL is free to order those two any
			// way per query, and a row then appears on two pages while another is
			// skipped. Measured on 23 fixtures — id 950 came back on page 2 AND 3.
			'orderby'        => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			// Full post objects, NOT 'fields' => 'ids'. get() needs the post and
			// its meta for every row, so asking for ids only skips WP's priming
			// and turns one page into up to 2N uncached queries — the opposite
			// of cheap. Terms are genuinely unused (the CPT registers none), so
			// that one cache stays off.
			'update_post_term_cache' => false,
		);

		if (! empty($args['search'])) {
			$query_args['s'] = sanitize_text_field(is_scalar($args['search']) ? (string) $args['search'] : '');
		}

		$query = new WP_Query($query_args);
		$items = array();

		foreach ($query->posts as $post) {
			// WP_Query::$posts is typed int|WP_Post; with full objects requested
			// these are WP_Post, but narrow explicitly rather than assume.
			$badge = self::get(is_object($post) ? (int) $post->ID : (int) $post);

			if ($badge !== null) {
				$items[] = $badge;
			}
		}

		$total = (int) $query->found_posts;

		// `total` means how many badges exist, never how many this page holds.
		// WP_Query reports found_posts 0 for a page past the end, so asking for
		// page 2 of a 20-badge list answered `total => 0` — and the screen took
		// that as "no badges at all" and drew the empty state. Measured, not
		// assumed: page 1 -> items 20 / total 20, page 2 -> items 0 / total 0.
		//
		// Only costs a query in that one case, so the normal path is unchanged.
		if ($total === 0 && $page > 1) {
			$count_args = $query_args;

			$count_args['posts_per_page'] = 1;
			$count_args['paged']          = 1;
			$count_args['fields']         = 'ids';

			$total = (int) ( new WP_Query($count_args) )->found_posts;
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Create or update a badge.
	 *
	 * @param array<string, mixed> $data `id` (0 to create), `name`, `settings`.
	 *
	 * @return int|null New/updated post ID, or null on failure.
	 */
	public static function save($data)
	{
		if (! is_array($data)) {
			return null;
		}

		$id   = isset($data['id']) ? absint($data['id']) : 0;
		$name = ( isset($data['name']) && is_scalar($data['name']) )
			? sanitize_text_field((string) $data['name'])
			: '';

		if ($name === '') {
			$name = __('Untitled Badge', 'reviews-feed');
		}

		$settings = self::sanitize_settings(isset($data['settings']) ? $data['settings'] : array());

		// Keep whatever status the badge already has; only a new one is born
		// published. get_post_status() returns false for a missing post, so fall
		// back rather than hand wp_update_post() a boolean.
		$status = 'publish';

		if ($id > 0) {
			$existing_status = get_post_status($id);

			if (is_string($existing_status) && $existing_status !== '') {
				$status = $existing_status;
			}
		}

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			// wp_slash for the same reason the meta blob below needs it:
			// wp_insert_post()/wp_update_post() unslash internally, so a name
			// carrying a backslash or an escaped quote is mangled on save — and
			// mangled again on every duplicate() round-trip.
			'post_title'  => wp_slash($name),
			// Only ever 'publish' today. list_badges() also queries 'draft' so a
			// future status toggle has somewhere to land, but until one exists a
			// save must not silently republish something drafted by hand.
			'post_status' => $status,
		);

		if ($id > 0) {
			$existing = get_post($id);

			// Never let a crafted id turn some other post type into a badge.
			if (! $existing || $existing->post_type !== self::POST_TYPE) {
				return null;
			}

			$postarr['ID'] = $id;
			$result        = wp_update_post($postarr, true);
		} else {
			$result = wp_insert_post($postarr, true);
		}

		if (is_wp_error($result) || ! $result) {
			return null;
		}

		$id = (int) $result;

		// wp_slash is NOT optional here. update_metadata() runs wp_unslash() on
		// the value before writing, which strips wp_json_encode()'s own escapes:
		// a footer_text containing a double quote lands as {"footer_text":"Say
		// "hi""}, json_decode() then returns null in get(), and EVERY setting on
		// that badge silently resets to its default. Non-ASCII corrupts the same
		// way (caf\u00e9 -> cafu00e9). Measured on a real save before the fix.
		update_post_meta($id, self::META_SETTINGS, wp_slash((string) wp_json_encode($settings)));

		// Deliberately NOT touching SBR_Badge_Renderer's cache. That cache holds
		// RATING aggregates keyed by feed + language + tier + source; a badge's
		// style, theme or element toggles are not part of it, so a save here
		// cannot make it stale. (It also takes a $feed_id — there is no
		// "clear everything" call, by design: invalidation bumps a per-feed
		// version salt so it survives a persistent object cache.)

		// Flushed AFTER the write, not before it: the map is a 12-hour transient now,
		// so a flush that precedes the write leaves a window in which anything
		// rebuilding the map re-caches pre-write state. Flushing first also threw the
		// cache away for a save that then failed validation. See SMASH-2116.
		self::flush_instance_scans();

		return $id;
	}

	/**
	 * Delete a badge.
	 *
	 * @param int $id Post ID.
	 *
	 * @return bool
	 */
	public static function delete($id)
	{
		$id   = (int) $id;
		$post = $id > 0 ? get_post($id) : null;

		if (! $post || $post->post_type !== self::POST_TYPE) {
			return false;
		}

		// Same reasoning as save(): the rating cache is keyed by feed, not by
		// badge, so deleting a badge cannot stale it.
		//
		// The RENDERED PNG is a different matter and was never cleaned up. The only
		// sweep runs inside the render path, on the next render of that same badge —
		// which after a delete never happens. So a deleted badge's image kept sitting
		// in a web-served uploads directory carrying its rating, review count and
		// platform marks, and survived uninstall. Purged before the post goes, while
		// the id is still meaningful.
		SBR_Badge_Image::purge($id);

		$deleted = (bool) wp_delete_post($id, true);

		self::flush_instance_scans();

		return $deleted;
	}

	/**
	 * Copy a badge, settings and all.
	 *
	 * @param int $id Post ID to copy.
	 *
	 * @return int|null New post ID, or null.
	 */
	public static function duplicate($id)
	{
		// No flush here: this returns through save(), which flushes after its write.
		$badge = self::get($id);

		if ($badge === null) {
			return null;
		}

		return self::save(
			array(
				'id'       => 0,
				'name'     => self::next_copy_name((string) $badge['name']),
				'settings' => $badge['settings'],
			)
		);
	}

	/**
	 * The name a copy of `$name` should carry.
	 *
	 * Appending "(copy)" every time is unbounded: duplicating a badge eleven times
	 * produced "New Trust Badge (copy) (copy) (copy) (copy) (copy) (copy) (copy)
	 * (copy) (copy) (copy) (copy)". That wraps a list row to three lines and makes
	 * the block editor's badge `<select>` unreadable, which is where it was noticed.
	 *
	 * So a copy of a copy counts instead: "(copy)" then "(copy 2)", "(copy 3)".
	 *
	 * The suffix is translatable, so the numbers are read back out of the SAME
	 * templates rather than off a hardcoded English "(copy)" — a site running in
	 * German would otherwise never match its own suffix and would append forever.
	 *
	 * @param string $name Name being copied.
	 *
	 * @return string
	 */
	protected static function next_copy_name($name)
	{
		/* translators: %s: name of the badge being copied. */
		$first = __('%s (copy)', 'reviews-feed');
		/* translators: 1: name of the badge being copied, 2: which copy this is. */
		$nth = __('%1$s (copy %2$d)', 'reviews-feed');

		// "… (copy N)" -> N + 1. The groups are checked, not assumed: a translation
		// that dropped a placeholder builds a pattern with fewer groups than these
		// lines read, and reading a missing one is a PHP 8 warning plus a wrong name.
		$nth_pattern = self::template_pattern($nth, '(.*)', '(\\d+)');

		if (preg_match('/^' . $nth_pattern . '$/', $name, $m) === 1 && isset($m[1], $m[2])) {
			return sprintf($nth, $m[1], max(2, (int) $m[2]) + 1);
		}

		// "… (copy)" -> "… (copy 2)".
		$first_pattern = self::template_pattern($first, '(.*)');

		if (preg_match('/^' . $first_pattern . '$/', $name, $m) === 1 && isset($m[1])) {
			return sprintf($nth, $m[1], 2);
		}

		return sprintf($first, $name);
	}

	/**
	 * A regex body built from a sprintf template, with its placeholders replaced.
	 *
	 * Everything outside the placeholders is quoted, so a translation containing
	 * regex metacharacters — brackets and parentheses, which this very suffix uses —
	 * cannot change what the pattern means.
	 *
	 * @param string $template sprintf template.
	 * @param string ...$parts Replacements for the placeholders, in order.
	 *
	 * @return string
	 */
	protected static function template_pattern($template, ...$parts)
	{
		// Split on %s / %d / %1$s / %2$d, keeping nothing but the literals.
		$literals = preg_split('/%(?:\\d+\\$)?[sd]/', $template);

		if (! is_array($literals)) {
			return preg_quote($template, '/');
		}

		$out = '';

		foreach ($literals as $i => $literal) {
			$out .= preg_quote($literal, '/');

			if (isset($parts[ $i ])) {
				$out .= $parts[ $i ];
			}
		}

		return $out;
	}

	/**
	 * Record that this badge's email image was generated.
	 *
	 * Called by the embed modal at the moment it hands the user an image URL —
	 * RECORDED, never detected. An email badge is a PNG pasted into Gmail or
	 * Outlook, so it leaves no shortcode anywhere on the site and no scan can ever
	 * find it. The design rock hit exactly this: its earlier pass showed
	 * "Used in 0 places" for a badge literally named "Email Signature Badge", and
	 * the delete dialog then told the user it was safe to remove something that
	 * might be in every outgoing message.
	 *
	 * Idempotent by design — re-generating the image refreshes the date rather
	 * than adding a second instance, because there is still only one embed.
	 *
	 * @param int $id Badge id.
	 *
	 * @return bool
	 */
	public static function record_email_embed($id)
	{
		$id = (int) $id;

		if (self::get($id) === null) {
			return false;
		}

		$now = time();

		if (update_post_meta($id, self::META_EMAIL_EMBED, $now)) {
			return true;
		}

		// false also means "already this value", which re-recording inside one second
		// hits. Compare against $now, not > 0: a real write failure leaves an older
		// timestamp, and reporting that as success would show a stale date.
		return self::get_email_embed($id) === $now;
	}

	/**
	 * Forget the email embed, e.g. when the user removes it from the modal.
	 *
	 * @param int $id Badge id.
	 *
	 * @return bool
	 */
	public static function clear_email_embed($id)
	{
		$id = (int) $id;

		// Existence checked as record_email_embed() checks it: without it, get_email_embed()
		// reads 0 for a deleted or foreign post and this would answer success for one.
		if ($id < 1 || self::get($id) === null) {
			return false;
		}

		delete_post_meta($id, self::META_EMAIL_EMBED);

		// false also means "there was no row", which is the state asked for. A real
		// delete failure leaves the timestamp behind and still answers false.
		return self::get_email_embed($id) === 0;
	}

	/**
	 * When the email image was generated, or 0.
	 *
	 * @param int $id Badge id.
	 *
	 * @return int GMT timestamp.
	 */
	public static function get_email_embed($id)
	{
		$stored = get_post_meta((int) $id, self::META_EMAIL_EMBED, true);

		return is_numeric($stored) ? (int) $stored : 0;
	}

	/**
	 * Rows the broad content scan will look at.
	 *
	 * Retained because it is public API; nothing reads it inside this class any
	 * more. The content scan it bounded was a 200-row post_title window over the
	 * whole site's badge-bearing posts, replaced by INSTANCE_SCAN_CEILING in
	 * SMASH-2116. meta_rows() still has a window of its own, written inline.
	 */
	const INSTANCE_SCAN_LIMIT = 200;

	/**
	 * Placements the delete dialog will list, per badge.
	 *
	 * The cap on the ANSWER, not on the scan — INSTANCE_SCAN_CEILING bounds the
	 * content scan, and meta_rows() still bounds the builder-meta one at 200.
	 * Named because both scan loops enforce it and two inline 50s drift.
	 */
	const MAX_INSTANCE_ROWS = 50;

	/**
	 * How long the badge-placement map survives, in seconds.
	 *
	 * Twelve hours, matching the feed-location map. Post writes invalidate it, so
	 * staleness is bounded by edits rather than by the clock. See SMASH-2116.
	 */
	const INSTANCE_MAP_CACHE_TTL = 43200;

	/**
	 * Transient holding the badge-placement map.
	 *
	 * The generation is appended, so invalidation bumps rather than deletes. A
	 * delete plus a fixed key loses a race that matters here: a request that missed
	 * the cache and is mid-scan finishes AFTER an editor's save and writes its
	 * pre-save map back under the same key, for twelve hours, into the count the
	 * delete dialog reads. Bumping means the in-flight scan writes to a key nobody
	 * asks for again. Same reason DB keys its feed map by version.
	 */
	const INSTANCE_MAP_CACHE_KEY = 'sbr_badge_instance_map';

	/**
	 * Option holding the badge-placement map's generation.
	 */
	const INSTANCE_MAP_VERSION_OPTION = 'sbr_badge_instance_map_version';

	/**
	 * Rows one scan will examine.
	 *
	 * High enough that no real site reaches it — the first pass asks only for IDs,
	 * so the ceiling costs nothing until posts actually place a badge. Replaces the
	 * old INSTANCE_SCAN_LIMIT window, which was 200 rows ordered by post_title, so a
	 * badge placed only on a late-alphabet post came back with a count of zero.
	 */
	const INSTANCE_SCAN_CEILING = 20000;

	/**
	 * Rows whose content is pulled in one batch of the second pass.
	 *
	 * Named for the reason MAX_INSTANCE_ROWS is: two inline copies drift. Mirrors
	 * DB::FEED_LOCATIONS_BATCH_SIZE.
	 */
	const INSTANCE_BATCH_SIZE = 200;

	/**
	 * How many badges a picker offers.
	 *
	 * Was a literal 100 in the block service and a CHOICE_LIMIT in the widget;
	 * one number now, next to the store that enforces it.
	 */
	const CHOICE_LIMIT = 100;

	/**
	 * Post types the badge-placement scan reads.
	 *
	 * Shares DB::scanned_post_types() with the feed-location scan — a badge lives
	 * wherever a feed does — but keeps its own filter, so a site can widen one
	 * without the other. Before SMASH-2116 the query named no post type at all,
	 * which is why MySQL had nothing to range-scan on.
	 *
	 * Public because the invalidation hook has to test against this list and not
	 * against DB::scanned_post_types(): a type added through the filter below would
	 * otherwise be scanned and then never invalidated, which is 12 hours of stale
	 * counts on exactly the type the site opted into.
	 *
	 * @return string[]
	 *
	 * @since 2.13.2
	 */
	public static function instance_scan_post_types()
	{
		/**
		 * Filters the post types searched for badge placements.
		 *
		 * Mirrors `sbr_feed_locations_post_types`, and defaults to the same list.
		 *
		 * @param string[] $post_types Public post types plus the block-editor containers.
		 */
		$post_types = apply_filters('sbr_badge_instance_post_types', DB::scanned_post_types());

		return array_values(array_unique(array_filter((array) $post_types, 'is_string')));
	}

	/**
	 * Every badge id the store knows about.
	 *
	 * @return array<int, int>
	 *
	 * @since 2.13.2
	 */
	private static function badge_ids()
	{
		$query = new WP_Query(array(
			'post_type'              => self::POST_TYPE,
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		));

		// WP_Query::$posts is typed int|WP_Post whatever `fields` asked for, so the
		// shape is narrowed here rather than assumed.
		$ids = array();
		foreach ((array) $query->posts as $post) {
			$ids[] = is_object($post) ? (int) $post->ID : (int) $post;
		}

		return $ids;
	}

	/**
	 * Where every badge is placed, in one scan.
	 *
	 * The query this replaces named no post type and no cache beyond the request,
	 * and past 200 badge-bearing posts it ran once PER BADGE — an admin list page
	 * issuing N unindexed full scans of wp_posts. On the site that reported
	 * SMASH-2103 that table is 8.6GB. Same fix as the feed-location scan: filter to
	 * post types an index can serve, ask for IDs first so no LONGTEXT crosses the
	 * wire, batch the content fetch, and answer every badge from one pass.
	 * See SMASH-2116, and DB::get_all_feed_shortcode_locations() for the original.
	 *
	 * @return array<int, array<int, array{ID: int, post_title: string, post_type: string}>>|false
	 *         Placements per badge, or false when the scan could not run. False is
	 *         not an empty map: one means "we do not know", the other "placed
	 *         nowhere", and only the second licenses a sentence with "no" in it
	 *         above a delete button. Mirrors DB::get_all_feed_shortcode_locations().
	 *
	 * @since 2.13.2
	 */
	private static function all_badge_instances()
	{
		global $wpdb;

		// Keyed by blog: the transient is per-blog, so a bare static would hand
		// blog A's map to blog B across switch_to_blog(). Mirrors DB.php:943-945.
		$memo_key = get_current_blog_id();

		if (isset(self::$instance_map[ $memo_key ])) {
			return self::$instance_map[ $memo_key ];
		}

		$cached = get_transient(self::instance_map_cache_key());
		if (is_array($cached)) {
			self::$instance_map[ $memo_key ] = $cached;
			return $cached;
		}

		$post_types = self::instance_scan_post_types();
		$badge_ids  = self::badge_ids();

		if (empty($post_types) || empty($badge_ids)) {
			self::$instance_map[ $memo_key ] = array();
			return array();
		}

		$type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));

		$args = array( '%[reviews-feed-badge%', '%wp:sbr/trust-badge%' );
		$args = array_merge($args, $post_types, array( self::INSTANCE_SCAN_CEILING ));

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in the transient below.
		// The only interpolation is a generated list of %s markers; every value is
		// passed to prepare(). Same shape as DB::feed_location_content_ids().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = self::read_rows(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE ( post_content LIKE %s OR post_content LIKE %s )
				   AND post_type IN ($type_placeholders)
				   AND post_status IN ( 'publish', 'draft', 'pending', 'future', 'private' )
				 ORDER BY post_date DESC
				 LIMIT %d",
				$args
			),
			true
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// get_col() answers array() for a failed query too. Returning an empty map
		// AND caching it would tell every author their badges are placed nowhere,
		// for twelve hours, under a delete button. Answer empty for this request and
		// let the next one try again.
		if ($post_ids === null) {
			// Memoised for the request so a 20-badge list does not run the broken
			// scan 20 times; NOT cached, so the next request tries again.
			self::$instance_map[ $memo_key ] = false;

			return false;
		}

		// read_rows() hands back the column as mixed; narrow it here rather than
		// letting the batch query's %d placeholders take whatever came out.
		$ids = array();
		foreach ($post_ids as $post_id) {
			$ids[] = (int) ( is_scalar($post_id) ? $post_id : 0 );
		}
		$post_ids = $ids;
		$map      = array();

		foreach (array_chunk($post_ids, self::INSTANCE_BATCH_SIZE) as $batch) {
			$id_placeholders = implode(',', array_fill(0, count($batch), '%d'));

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cached in the transient below.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated %d list; $batch goes through prepare().
			$rows = self::read_rows(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
					 WHERE ID IN ($id_placeholders)
					 ORDER BY post_date DESC",
					$batch
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// The first pass already refuses to cache a failed answer; without the
			// same check here a failed batch silently drops every post from that
			// batch on, and set_transient() stores the under-report for 12 hours —
			// under the delete button this count gates.
			if ($rows === null) {
				self::$instance_map[ $memo_key ] = false;

				return false;
			}

			foreach ((array) $rows as $row) {
				if (! is_array($row) || ! isset($row['post_content'])) {
					continue;
				}

				$content = (string) $row['post_content'];
				$place   = array(
					'ID'         => isset($row['ID']) && is_scalar($row['ID']) ? (int) $row['ID'] : 0,
					'post_title' => isset($row['post_title']) && is_scalar($row['post_title']) ? (string) $row['post_title'] : '',
					'post_type'  => isset($row['post_type']) && is_scalar($row['post_type']) ? (string) $row['post_type'] : '',
				);

				// Parsed ONCE per row, not once per row per badge. content_places_badge()
				// runs parse_blocks() on every call, and this loop is rows x badges: at
				// the 20,000-row ceiling with 40 badges that is 800,000 parses of the
				// same strings on one cold request, which would have made the cache fill
				// the new timeout. The id-bearing tests below are cheap by comparison.
				$blocks = ( strpos($content, 'wp:sbr/trust-badge') !== false && function_exists('parse_blocks') )
					? parse_blocks($content)
					: null;

				// The LIKEs carry no badge id — a block serialises its attributes in
				// any order — so which badge a row places is decided here, once for the
				// whole site rather than once per badge per page load.
				foreach ($badge_ids as $badge_id) {
					if (self::content_shortcode_places_badge($content, $badge_id)) {
						$map[ $badge_id ][ $place['ID'] ] = $place;
						continue;
					}

					if ($blocks !== null && self::blocks_place_badge($blocks, $badge_id)) {
						$map[ $badge_id ][ $place['ID'] ] = $place;
					}
				}
			}
		}

		foreach ($map as $badge_id => $places) {
			$map[ $badge_id ] = array_values($places);
		}

		set_transient(self::instance_map_cache_key(), $map, self::INSTANCE_MAP_CACHE_TTL);
		self::$instance_map[ $memo_key ] = $map;

		return $map;
	}

	/**
	 * Builder-meta rows that could place any badge, scanned once per request.
	 *
	 * Same shape as the content scan in all_badge_instances(): the LIKE carries no
	 * badge id, and the id is applied by meta_places_badge() afterwards, so the
	 * query is identical for every badge on the page.
	 *
	 * @param string            $placeholders Generated `%s` list for the meta keys.
	 * @param array<int, mixed> $args         Meta keys followed by the LIKE needle.
	 *
	 * @return array<int, array{ID: int, post_title: string, post_type: string, meta_value: string}>|false
	 *         False when the read failed — an empty result and a failed one look
	 *         identical otherwise, and only one of them means "no placements".
	 */
	private static function meta_rows($placeholders, array $args)
	{
		// Keyed on the meta-key set, which the `sbr_badge_instance_meta_keys`
		// filter can change between calls. Two callers with different key sets
		// must not share one answer.
		$key = md5($placeholders . '|' . (string) wp_json_encode($args));

		if (isset(self::$meta_rows[$key])) {
			return self::$meta_rows[$key];
		}

		global $wpdb;

		// The IN list is interpolated because its LENGTH is dynamic — one
		// placeholder per filtered key, each the literal '%s' from array_fill, so
		// no caller value reaches the SQL text.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = self::read_rows(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, m.meta_value
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ( {$placeholders} )
				   AND m.meta_value LIKE %s
				   AND p.post_status IN ( 'publish', 'draft', 'pending', 'future', 'private' )
				 ORDER BY p.post_title ASC
				 LIMIT 200",
				...$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// The two content reads refuse to treat an empty result as an answer; this is
		// the third read on the same path. Returning array() here would be harmless
		// on its own — no matches looks the same — but memoising it is not: every
		// later badge in the request reuses "no builder placements" from one failed
		// query, and builder-placed badges read as unused. Not memoised, so the next
		// caller tries again.
		if ($rows === null) {
			return false;
		}

		$out = array();

		foreach ((array) $rows as $row) {
			if (! is_array($row)) {
				continue;
			}

			$out[] = array(
				'ID'         => isset($row['ID']) && is_scalar($row['ID']) ? (int) $row['ID'] : 0,
				'post_title' => isset($row['post_title']) && is_scalar($row['post_title']) ? (string) $row['post_title'] : '',
				'post_type'  => isset($row['post_type']) && is_scalar($row['post_type']) ? (string) $row['post_type'] : '',
				'meta_value' => isset($row['meta_value']) && is_scalar($row['meta_value']) ? (string) $row['meta_value'] : '',
			);
		}

		self::$meta_rows[$key] = $out;

		return self::$meta_rows[$key];
	}

	/**
	 * Drop the per-request instance scans.
	 *
	 * Called after a write, so a save followed by a read in the same request
	 * cannot serve rows from before it.
	 *
	 * @return void
	 */
	public static function flush_instance_scans()
	{
		self::$instance_map = array();
		self::$meta_rows    = array();

		// The map now outlives the request, so clearing the statics is no longer
		// enough — a badge saved here would keep its old placements for 12 hours.
		// Bumped rather than deleted: see INSTANCE_MAP_CACHE_KEY for the race a
		// delete loses. The orphan carries its own TTL, so nothing has to sweep it.
		update_option(self::INSTANCE_MAP_VERSION_OPTION, self::instance_map_version() + 1, false);
	}

	/**
	 * Run a prepared read and say whether it failed, rather than leaving the caller
	 * to ask $wpdb afterwards.
	 *
	 * wpdb answers array() both for a failed query and for one that matched
	 * nothing, and on this path those mean opposite things — "we could not check"
	 * versus "placed nowhere", the second of which is printed next to a delete
	 * button. Reading `$wpdb->last_error` at each call site also reads as dead code
	 * to a file-scoped analysis, which narrows it to '' after the first check; a
	 * whole-project run does not, so the two disagreed and CI reported the ignore
	 * that silenced one of them as unmatched. Failure is a property of the call.
	 *
	 * @param string       $sql    Prepared SQL.
	 * @param bool         $column True to fetch a single column.
	 *
	 * @return array<int, mixed>|null Rows, or null when the query failed.
	 *
	 * @since 2.13.2
	 */
	private static function read_rows($sql, $column = false)
	{
		global $wpdb;

		// $sql arrives already prepared — every caller on this path builds it with
		// $wpdb->prepare() — but the sniff can only see a variable here.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $column ? $wpdb->get_col($sql) : $wpdb->get_results($sql, ARRAY_A);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ('' !== (string) $wpdb->last_error) {
			return null;
		}

		return (array) $rows;
	}

	/**
	 * Current generation of the placement map, floored at 1.
	 *
	 * @return int
	 *
	 * @since 2.13.2
	 */
	private static function instance_map_version()
	{
		$version = get_option(self::INSTANCE_MAP_VERSION_OPTION, 1);

		return is_numeric($version) && (int) $version > 0 ? (int) $version : 1;
	}

	/**
	 * Transient key for this generation of the map.
	 *
	 * @return string
	 *
	 * @since 2.13.2
	 */
	public static function instance_map_cache_key()
	{
		return self::INSTANCE_MAP_CACHE_KEY . '_v' . self::instance_map_version();
	}

	/**
	 * Find where a badge is actually placed.
	 *
	 * Scans post content for the badge's shortcode and for the block's saved
	 * attribute. Two things worth knowing about the result:
	 *
	 *  - It is a SCAN, so it only sees placements that live in post content. A
	 *    badge dropped into a theme template, a widget area or a page-builder
	 *    layout will not appear, and the count is a floor rather than a total.
	 *  - The email badge can never be found this way at all: it is a PNG pasted
	 *    into Gmail or Outlook, with no shortcode anywhere on the site. The
	 *    design rock hit exactly this and its fix is to RECORD the email embed on
	 *    the badge when the embed modal generates the image, not to detect it.
	 *    record_email_embed() does that, and get_instances() counts it below. A
	 *    badge placed in a template or a page builder still cannot be found, which
	 *    is why the copy says "used in", never "only used in".
	 *
	 * @param int $id Badge id.
	 *
	 * @return array{count: int, items: array<int, array{name: string, type: string, typeLabel: string}>, status: string}
	 */
	public static function get_instances($id)
	{
		$id = (int) $id;

		if ($id < 1) {
			return array( 'count' => 0, 'items' => array(), 'status' => 'ok' );
		}

		// SQL narrows, PHP confirms. The query cannot do both jobs: a block
		// serialises its attributes as JSON in any order, so `{"id":12}` and
		// `{"align":"center","id":12}` are both valid and no single LIKE matches
		// them without also matching `{"id":120}` or another block's `id`.
		//
		// The needle here was `"badge":<id>`, which the block never writes — it
		// registers the attribute as `id`, so it saves `{"id":12}`. Every block
		// placement was therefore invisible: the list said "used in 0 places"
		// and the delete dialog told the author a live badge was safe to remove.
		//
		// Which badge each post places is now decided once for the whole site, in
		// all_badge_instances(), rather than per badge on this screen. That retires
		// the 200-row post_title window the CONTENT half worked within: it applied
		// to the site's badge-bearing posts rather than to one badge's, so past the
		// limit a badge placed only on a late-alphabet post read as zero.
		//
		// The builder-meta half below still carries that window (meta_rows(), ORDER
		// BY post_title ASC LIMIT 200) and is still memoised per request rather than
		// cached. Its query is served by the meta_key index, so it is not the full
		// table scan SMASH-2116 is about, but the truncation bug is the same one.
		// Left for its own change rather than widened into this one. See SMASH-2116.
		$map     = self::all_badge_instances();
		$unknown = ( $map === false );
		$found   = ( ! $unknown && isset($map[ $id ]) ) ? $map[ $id ] : array();

		$rows = array();
		$seen = array();

		foreach ($found as $candidate) {
			if (count($rows) >= self::MAX_INSTANCE_ROWS) {
				break;
			}

			$rows[]                   = $candidate;
			$seen[ $candidate['ID'] ] = true;
		}

		$meta = self::meta_candidates($id);

		if ($meta === false) {
			// The builder half failed on its own. Same answer as a failed content
			// scan: we do not know, so the caller must not print a zero.
			$unknown = true;
			$meta    = array();
		}

		foreach ($meta as $candidate) {
			if (count($rows) >= self::MAX_INSTANCE_ROWS) {
				break;
			}

			if (! isset($seen[ $candidate['ID'] ])) {
				$rows[]                   = $candidate;
				$seen[ $candidate['ID'] ] = true;
			}
		}

		$items = array();

		// The email embed goes FIRST and counts as one more instance, so the cell
		// keeps its shipped shape: one number, one tooltip. The design rock
		// considered a separate note strip for it and dropped that, precisely so
		// this column does not grow a special case.
		$embedded = self::get_email_embed($id);

		if ($embedded > 0) {
			$items[] = array(
				'name'      => __('Email', 'reviews-feed'),
				/* translators: %s: date the email image was generated, e.g. 12 Jul 2026. */
				'meta'      => sprintf(
					__('Generated %s', 'reviews-feed'),
					// date_i18n over wp_date: this is a display string in the admin's
					// locale, and the stored value is already GMT.
					date_i18n('j M Y', $embedded)
				),
				'type'      => 'email',
				'typeLabel' => __('Email', 'reviews-feed'),
			);
		}

		foreach ((array) $rows as $row) {
			$type   = isset($row['post_type']) ? (string) $row['post_type'] : 'page';
			$object = get_post_type_object($type);

			$items[] = array(
				'name'      => isset($row['post_title']) && $row['post_title'] !== ''
					? (string) $row['post_title']
					: __('(no title)', 'reviews-feed'),
				'type'      => self::icon_for_post_type($type),
				// The post type's OWN singular label, so a custom type reads as
				// itself rather than as "Post". Falls back to the slug only when a
				// type is registered without labels.
				'typeLabel' => ( $object && isset($object->labels->singular_name) )
					? (string) $object->labels->singular_name
					: ucfirst(str_replace(array( '_', '-' ), ' ', $type)),
			);
		}

		return array(
			'count'  => count($items),
			'items'  => $items,
			// Additive: existing readers of count/items are untouched. 'unknown'
			// means the scan could not run, which the list and the delete dialog
			// render as "couldn't check" rather than as zero. SMASH-2117.
			'status' => $unknown ? 'unknown' : 'ok',
		);
	}

	/**
	 * Posts that place this badge from POST META rather than post_content.
	 *
	 * Page builders do not write to post_content. Elementor keeps its whole layout
	 * in `_elementor_data`, so a badge dropped into an Elementor shortcode widget was
	 * invisible to the scan above: the list said "Used in 0 places" and the delete
	 * dialog — which warns "anywhere you have placed it will stop" — told the author
	 * a live badge was safe to remove.
	 *
	 * That is the same failure this file already fixed once for blocks, in the
	 * comment above; builders were the half it did not reach.
	 *
	 * The stored JSON escapes its quotes (`id=\"12\"`), which the shortcode regex
	 * cannot match, so the value is decoded and every string inside it is tested
	 * rather than the raw blob. json_decode over stripslashes: a builder is free to
	 * store any escape sequence, and guessing at them is how a matcher starts lying.
	 *
	 * @param int $id Badge id.
	 *
	 * @return array<int, array{ID: int, post_title: string, post_type: string}>|false
	 *         False when the builder-meta read failed, for the same reason
	 *         all_badge_instances() answers false.
	 */
	protected static function meta_candidates($id)
	{
		global $wpdb;

		$builder_keys = apply_filters(
			'sbr_badge_instance_meta_keys',
			array( '_elementor_data' )
		);

		if (! is_array($builder_keys) || $builder_keys === array()) {
			return array();
		}

		$placeholders = implode(', ', array_fill(0, count($builder_keys), '%s'));
		$args         = array_merge(array_values($builder_keys), array( '%reviews-feed-badge%' ));

		// The IN list is interpolated because its LENGTH is dynamic — one placeholder
		// per filtered key. Every token in it is the literal '%s' produced by
		// array_fill, so no caller value reaches the SQL text; the keys themselves go
		// through prepare() as arguments like any other. The sniff cannot see that,
		// and it fires on a line inside the string, so the pair wraps the statement.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = self::meta_rows($placeholders, $args);

		if ($found === false) {
			return false;
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();

		foreach ($found as $row) {
			if (! self::meta_places_badge($row['meta_value'], $id)) {
				continue;
			}

			$out[] = array(
				'ID'         => $row['ID'],
				'post_title' => $row['post_title'],
				'post_type'  => $row['post_type'],
			);
		}

		return $out;
	}

	/**
	 * Whether a builder's stored blob places THIS badge.
	 *
	 * @param string $raw Raw meta value, usually JSON.
	 * @param int    $id  Badge id.
	 *
	 * @return bool
	 */
	protected static function meta_places_badge($raw, $id)
	{
		if ($raw === '') {
			return false;
		}

		$decoded = json_decode($raw, true);

		// Not JSON: test the raw value, which covers a builder storing plain markup.
		if (! is_array($decoded)) {
			return self::content_places_badge($raw, $id);
		}

		foreach (self::flatten_strings($decoded) as $candidate) {
			if (self::content_places_badge($candidate, $id)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every string inside a decoded structure, at any depth.
	 *
	 * Recursive because a builder nests widgets inside sections inside containers,
	 * and the shortcode sits in a leaf setting several levels down.
	 *
	 * @param mixed $value Decoded value.
	 *
	 * @return array<int, string>
	 */
	protected static function flatten_strings($value)
	{
		if (is_string($value)) {
			return array( $value );
		}

		if (! is_array($value)) {
			return array();
		}

		$out = array();

		foreach ($value as $item) {
			foreach (self::flatten_strings($item) as $string) {
				$out[] = $string;
			}
		}

		return $out;
	}

	/**
	 * Whether a piece of post content really places THIS badge.
	 *
	 * The SQL above matches any post carrying the shortcode or the block; this
	 * decides whether the id matches, which is the part LIKE cannot do without
	 * also matching `id="120"` for badge 12.
	 *
	 * Blocks are parsed rather than pattern-matched, so attribute order and
	 * whitespace stop mattering. Shortcodes use a bounded regex because
	 * `[reviews-feed-badge id="12"]` has no parser of its own until
	 * do_shortcode() runs, and running that here would render the badge.
	 *
	 * @param string $content Post content.
	 * @param int    $id      Badge id.
	 *
	 * @return bool
	 */
	protected static function content_places_badge($content, $id)
	{
		if (self::content_shortcode_places_badge($content, $id)) {
			return true;
		}

		if ((string) $content === '' || strpos((string) $content, 'wp:sbr/trust-badge') === false || ! function_exists('parse_blocks')) {
			return false;
		}

		return self::blocks_place_badge(parse_blocks($content), $id);
	}

	/**
	 * The shortcode half of content_places_badge(), on its own.
	 *
	 * Split out so the site-wide scan can parse a post's blocks once and then test
	 * every badge against that one tree — calling the combined matcher per badge
	 * re-parsed the same content for each of them. See all_badge_instances().
	 *
	 * @param string $content Post content.
	 * @param int    $id      Badge id.
	 *
	 * @return bool
	 *
	 * @since 2.13.2
	 */
	protected static function content_shortcode_places_badge($content, $id)
	{
		if ((string) $content === '') {
			return false;
		}

		// `\b` on the closing quote, not just the digits: without it badge 12
		// matches id="120".
		return 1 === preg_match(
			'/\[reviews-feed-badge[^\]]*\bid=["\']?' . preg_quote((string) $id, '/') . '["\'\]\s]/',
			(string) $content
		);
	}

	/**
	 * Walk parsed blocks, including nested ones.
	 *
	 * Recursive because a badge can sit inside a Group, a Column or a synced
	 * pattern, and a flat scan of the top level would report those as nowhere.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $id     Badge id.
	 *
	 * @return bool
	 */
	protected static function blocks_place_badge($blocks, $id)
	{
		foreach ((array) $blocks as $block) {
			if (! is_array($block)) {
				continue;
			}

			$name  = isset($block['blockName']) ? (string) $block['blockName'] : '';
			$attrs = ( isset($block['attrs']) && is_array($block['attrs']) ) ? $block['attrs'] : array();

			if ($name === 'sbr/trust-badge' && isset($attrs['id']) && (int) $attrs['id'] === $id) {
				return true;
			}

			if (! empty($block['innerBlocks']) && self::blocks_place_badge($block['innerBlocks'], $id)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Which tooltip glyph a post type gets.
	 *
	 * The design draws four: page, product, template and email. Anything else
	 * borrows the page sheet rather than leaving an empty slot in the row — a
	 * missing icon reads as a broken row, a generic one reads as "some content".
	 *
	 * Products and EDD downloads share the box, because in this list they are the
	 * same idea: a thing you sell. Template parts and the reusable-block type get
	 * the layout mark, since a badge there is not on one page but on many.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return string One of page|product|template.
	 */
	private static function icon_for_post_type($post_type)
	{
		if (in_array($post_type, array( 'product', 'download' ), true)) {
			return 'product';
		}

		if (in_array($post_type, array( 'wp_template', 'wp_template_part', 'wp_block' ), true)) {
			return 'template';
		}

		return 'page';
	}

	/**
	 * Read one key as a string, refusing anything that is not scalar.
	 *
	 * A JSON POST can carry an array or object at any key. Two different harms
	 * follow, and they are not equally bad — measured on PHP 8.2:
	 *
	 *   - `(string) array` is a WARNING, not a fatal, and yields the literal
	 *     'Array'. Harmless for the allowlisted fields, which reject it anyway,
	 *     but `footer_text` would render a badge footer reading "Array".
	 *   - `sanitize_hex_color()` hands its argument to preg_match(), whose
	 *     $subject is typed string — an array there is an uncaught TypeError.
	 *     That one IS fatal, and it is the same trap the widget's colour fields
	 *     already guard.
	 *
	 * Returning '' lets the caller's allowlist or sanitiser decide, exactly as
	 * it would for an unknown value.
	 *
	 * @param array<string, mixed> $raw Submitted settings.
	 * @param string               $key Key to read.
	 *
	 * @return string
	 */
	private static function scalar($raw, $key)
	{
		return ( isset($raw[ $key ]) && is_scalar($raw[ $key ]) ) ? (string) $raw[ $key ] : '';
	}

	/**
	 * Clean a settings array coming off the wire.
	 *
	 * Allowlists on every enumerated field rather than trusting the client, for
	 * the same reason SBR_Badge_Renderer does: an unknown style must not become
	 * a path fragment, and an unknown theme must not become a class name.
	 *
	 * @param mixed $raw Submitted settings.
	 *
	 * @return array{style: string, theme: string, accent: string, tint_hue: int, sources: array<int, string>, show_rating: bool, show_stars: bool, show_count: bool, show_logos: bool, logo_style: string, logo_color: string, show_footer: bool, footer_text: string, footer_url: string, powered_by: bool}
	 */
	public static function sanitize_settings($raw)
	{
		$raw      = is_array($raw) ? $raw : array();
		$defaults = self::defaults();
		$out      = $defaults;

		// is_scalar on every cast, not just the hex — see scalar() for why the
		// two harms differ.
		$style = self::scalar($raw, 'style');
		$theme = self::scalar($raw, 'theme');

		$style        = sanitize_key($style);
		$out['style'] = in_array($style, self::STYLES, true) ? $style : $defaults['style'];
		$theme        = sanitize_key($theme);
		$out['theme'] = in_array($theme, self::THEMES, true) ? $theme : $defaults['theme'];

		$logo_style = self::scalar($raw, 'logo_style');
		$logo_color = self::scalar($raw, 'logo_color');

		$logo_style        = sanitize_key($logo_style);
		$logo_color        = sanitize_key($logo_color);
		$out['logo_style'] = in_array($logo_style, self::LOGO_STYLES, true) ? $logo_style : $defaults['logo_style'];
		$out['logo_color'] = in_array($logo_color, self::LOGO_COLORS, true) ? $logo_color : $defaults['logo_color'];

		// The accent is a HUE (0-359 as a string), not a hex.
		//
		// It was sanitize_hex_color() when this store was written, before the
		// three themes existed. That silently blanked every accent the editor
		// sent: the sidebar stores a hue, '0' is not a hex, so the field came
		// back empty and the badge kept gold stars whatever colour was picked.
		//
		// A hue rather than a hex because each theme derives its own star colour
		// from it — light saturates, dark lightens, tinted ignores it entirely.
		// Storing the resolved hex would freeze the badge to whichever theme
		// happened to be active when the colour was chosen.
		//
		// '' means "no accent, keep the default gold", and it is a distinct
		// state from '0', which is red. Rejected rather than clamped: a value
		// outside the wheel is a bug in the caller, and wrapping 400 to 40 hides
		// it. tint_hue below clamps instead, deliberately — it comes from a
		// slider that can overshoot its own track by a pixel.
		$accent        = self::scalar($raw, 'accent');
		$out['accent'] = ( $accent !== '' && is_numeric($accent) && (int) $accent >= 0 && (int) $accent <= 359 )
			? (string) (int) $accent
			: '';

		// Hue is 0-360 and wraps; clamp rather than reject so a slider that
		// overshoots does not blank the field.
		// The only numeric field, and it needs the same guard as the strings:
		// `(int) array` is 1 on PHP 8, so an array-valued POST would land a hue
		// of 1 rather than falling back to the default. Verified on 8.2.
		$hue             = self::scalar($raw, 'tint_hue');
		$hue             = $hue === '' ? $defaults['tint_hue'] : (int) $hue;
		// 359, not 360: the wheel wraps, and SBR_Badge_Renderer::to_hue() treats
		// 360 as out of range and returns "unset". Clamping to 360 here meant a
		// badge dragged to the top of the slider lost its tint on the page (the
		// custom property was omitted, so CSS fell back to hue 250) while the
		// email PNG applied the stored 360 — the same badge, two colours.
		$out['tint_hue'] = max(0, min(359, $hue));

		// absint via the same scalar guard the rest of this method uses — an
		// array-valued POST casts to 1 on PHP 8 and would pin the badge to feed 1.
		$out['feed_id'] = absint(self::scalar($raw, 'feed_id'));

		// array_key_exists, not ! empty() alone: five of these six default to
		// TRUE, so an ABSENT key must keep its default rather than resolve to
		// false. A blob saved before one of these existed would otherwise read
		// back with the element switched off — a blank badge, or the attribution
		// silently dropped — which is exactly the forward-compat promise get()
		// makes. (show_footer is the one that defaults to false; the same
		// reasoning applies in reverse, so the test is the same.)
		foreach (array( 'show_rating', 'show_stars', 'show_count', 'show_logos', 'show_footer', 'powered_by' ) as $flag) {
			$out[ $flag ] = array_key_exists($flag, $raw) ? ! empty($raw[ $flag ]) : $defaults[ $flag ];
		}

		// Same array_key_exists test as the flags above, and for a sharper
		// reason: the default is non-empty copy ("Powered by Smash Balloon"), so
		// falling back on an empty string would put it back every time an author
		// cleared the box. Absent means "never set" and takes the default;
		// present-but-empty means "deliberately cleared" and stays cleared.
		$out['footer_text'] = array_key_exists('footer_text', $raw)
			? sanitize_text_field(self::scalar($raw, 'footer_text'))
			: $defaults['footer_text'];

		$out['footer_url'] = array_key_exists('footer_url', $raw)
			? esc_url_raw(self::scalar($raw, 'footer_url'))
			: $defaults['footer_url'];

		$sources = array();

		if (isset($raw['sources']) && is_array($raw['sources'])) {
			foreach ($raw['sources'] as $source) {
				if (is_scalar($source)) {
					$clean = sanitize_text_field((string) $source);

					if ($clean !== '') {
						$sources[] = $clean;
					}
				}
			}
		}

		$out['sources'] = self::normalize_source_ids(array_values(array_unique($sources)));

		return $out;
	}

	/**
	 * Settle every stored source on one identifier: `account_id`.
	 *
	 * Two shapes can arrive. `account_id` is the one everything downstream
	 * speaks — a feed's own `sources` setting holds account_ids,
	 * SBR_Badge_Renderer::providers_for_sources() queries by account_id, and
	 * SBR_Trust_Badge_Builder::feed_for_badge() matches a badge's source against
	 * the feed's. The other is the source table's primary key, which is what the
	 * shared picker returns when a caller asks for `useDbId`.
	 *
	 * Badges saved while the picker was in that mode still LISTED correctly (the
	 * row lookup checks both keys) but silently stopped scoping their rating and
	 * lost their platform logo. Normalising on the way through means those blobs
	 * repair themselves the next time they are read, with no migration.
	 *
	 * account_id wins when a value could be either. Woo and EDD account_ids are
	 * numeric, so a product id can collide with a row id; treating the value as
	 * a row id first would silently repoint the badge at an unrelated source.
	 * Converting only what matches NO account_id keeps the ambiguous case on the
	 * meaning the rest of the code assumes.
	 *
	 * @param array<int, string> $ids Sanitised source identifiers.
	 *
	 * @return array<int, string> Account ids.
	 */
	protected static function normalize_source_ids($ids)
	{
		global $wpdb;

		if (empty($ids)) {
			return array();
		}

		$table        = $wpdb->prefix . SBR_SOURCES_TABLE;
		$placeholders = implode(',', array_fill(0, count($ids), '%s'));

		// One query for both columns, rather than a lookup per id.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list; values are bound below.
		$sql = "SELECT id, account_id FROM {$table} WHERE account_id IN ({$placeholders}) OR id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; runs on an admin save/read of one badge.
		$rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($ids, $ids)), ARRAY_A);

		$accounts = array();
		$by_row_id = array();

		foreach ((array) $rows as $row) {
			$account = isset($row['account_id']) ? (string) $row['account_id'] : '';
			$row_id  = isset($row['id']) ? (string) $row['id'] : '';

			if ($account === '') {
				continue;
			}

			$accounts[$account] = true;

			if ($row_id !== '') {
				$by_row_id[$row_id] = $account;
			}
		}

		$out = array();

		foreach ($ids as $id) {
			if (isset($accounts[$id])) {
				// Already an account id. Keep it, ambiguity included.
				$out[] = $id;
				continue;
			}

			if (isset($by_row_id[$id])) {
				$out[] = $by_row_id[$id];
				continue;
			}

			// Matches neither column. Kept rather than dropped: a source can be
			// removed while a badge still references it, and silently emptying
			// the list would make the badge start reporting the whole feed
			// instead of showing nothing.
			$out[] = $id;
		}

		return array_values(array_unique($out));
	}
}
