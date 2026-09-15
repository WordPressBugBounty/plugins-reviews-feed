<?php

namespace SmashBalloon\Reviews\Common\Badges;

use SmashBalloon\Reviews\Common\Util;
use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Reviews Feed — Widgets & Badges service (SBR-163).
 *
 * Registers the three delivery rails. Each is a thin adapter over
 * SBR_Badge_Renderer::render() — none of them builds markup itself:
 *
 *
 * Purely additive. The existing `reviews-feed` shortcode and
 * `sbr/sbr-feed-block` registrations are untouched, so no existing caller
 * changes behaviour.
 */
class SBR_Badge_Service extends ServiceProvider
{
	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public function register()
	{
		// Saved Trust Badges (design rock SBR-78): a badge as an entity you
		// create once and place many times, rather than attributes repeated per
		// placement. Storage only — SBR_Badge_Renderer stays the single thing
		// that emits markup, so a saved badge cannot drift from an inline one.
		add_action('init', array( SBR_Trust_Badge_Store::class, 'register_post_type' ));

		// The signature image endpoint. Front end, unauthenticated — a mail
		// client fetching it has no session.
		SBR_Badge_Image::register();


		// A saved badge is placed by id: `[reviews-feed-badge id="12"]`. This is
		// the shortcode the All Badges list tells people to copy and the one
		// get_instances() counts placements of — but nothing rendered it, so
		// pasting it on a page produced silence. Its own shortcode rather than an
		// `id` attribute on `reviews-badge`, because the two take different
		// arguments entirely: one is "a shape plus a dozen attributes", the other
		// is "the badge I already configured".
		add_shortcode('reviews-feed-badge', array( $this, 'render_saved_shortcode' ));
		add_action('init', array( $this, 'register_block' ));
		// The pitch's native WP_Widget, for classic themes. Same saved-badge
		// rail as the block and the shortcode.
		add_action('widgets_init', array( $this, 'register_badge_widget' ));
		add_filter('widget_types_to_hide_from_legacy_widget_block', array( $this, 'hide_widget_from_block_inserter' ));
		add_action('enqueue_block_editor_assets', array( $this, 'localize_trust_badge_block' ));

		// Keep the badge aggregate in step with the feed cache it is derived
		// from. FeedCacheUpdater fires this after a cron refresh with the list
		// of feed IDs it repopulated (class/Common/FeedCacheUpdater.php:59).
		add_action('sbr_after_cron_refresh', array( $this, 'clear_badge_cache' ));

		// A cron refresh is not the only thing that moves the numbers. Editing a
		// feed's sources changes both the rating and which platform marks the
		// badge draws, and without this the badge kept reporting the old set for
		// up to a full TTL — reproduced live: a feed whose sources had been
		// swapped still listed the previous platforms until the transient was
		// cleared by hand. Negative caches ("this feed resolves to nothing")
		// outlive the fix that made it resolvable, which is the same bug read
		// from the other side.
		add_action('sbr_after_feed_saved', array( $this, 'clear_badge_cache' ));
	}



	/**
	 * `[reviews-feed-badge id="12"]` handler — a saved Trust Badge.
	 *
	 * Everything about how it looks comes from the stored settings; the only
	 * attribute is which badge. Goes through the same
	 * SBR_Trust_Badge_Builder::render_saved() the editor previews with, so what
	 * an author approves in the customizer is what the page publishes.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string Rendered HTML, empty when the badge is missing or has
	 *                nothing truthful to show.
	 */
	public function render_saved_shortcode($atts = array())
	{
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array($atts) ? $atts : array(),
			'reviews-feed-badge'
		);

		$badge = SBR_Trust_Badge_Store::get(absint($atts['id']));

		// Deleted badge, or an id that was never one. Render nothing rather than
		// a default badge: a page that silently swaps in someone else's settings
		// is worse than a gap where the badge used to be.
		if ($badge === null || empty($badge['settings'])) {
			return '';
		}

		// Store::get() answers for any status by design, and these three rails
		// (shortcode, block, widget) are as anonymous once rendered as the image
		// route, which already refuses a non-published badge at
		// SBR_Badge_Image.php:320.
		if (! isset($badge['status']) || $badge['status'] !== 'publish') {
			return '';
		}

		$html = SBR_Trust_Badge_Builder::render_saved($badge['settings']);

		if ($html !== '') {
			// The saved styles, which pull in the tokens and the shared star
			// rules through their dependencies.
			sbr_trust_badge_enqueue_styles();
		}

		return $html;
	}


	/**
	 * Register the block.
	 *
	 * Server-rendered via render_callback so badge markup has exactly one
	 * source, PLUS a buildless editor script so the block is actually
	 * insertable. Registering the render_callback alone would leave a block
	 * nobody can add — the feed block gets away with that only because it sets
	 * `inserter => false` and is inserted by the feed builder instead
	 * (SB_Reviews_Blocks.php:126-135); a badge has no such flow.
	 *
	 * Guarded because register_block_type only exists on WP 5.0+, matching how
	 * SB_Reviews_Blocks guards its own registration.
	 *
	 * @return void
	 */
	public function register_block()
	{
		if (! function_exists('register_block_type')) {
			return;
		}

		$this->register_trust_badge_block();
	}

	/**
	 * Hand the editor its badge list.
	 *
	 * Deliberately NOT done at registration time. Registration runs on `init`,
	 * which is every request — front end, REST, cron — and badge_choices() walks
	 * list_badges(), whose per-row get() ends in an uncached source-table query.
	 * That put up to a hundred queries on every page view of the site to fill in
	 * data only the block editor ever reads.
	 *
	 * `enqueue_block_editor_assets` fires only where the editor loads, which is
	 * the one place the list is wanted, and still runs before the script prints
	 * so `wp_add_inline_script` lands.
	 *
	 * @return void
	 */
	public function localize_trust_badge_block()
	{
		if (! wp_script_is('sbr-trust-badge-block', 'registered')) {
			return;
		}

		// Localised rather than fetched, because the editor has to answer "does
		// this id still exist?" on first paint to tell the deleted state apart
		// from a preview that has not arrived yet — an async fetch would show
		// "no badges yet" to everyone for a moment.
		wp_add_inline_script(
			'sbr-trust-badge-block',
			'window.sbrTrustBadgeBlock = ' . wp_json_encode(
				array(
					'badges'  => self::badge_choices(),
					'listURL' => admin_url('admin.php?page=' . SBR_Trust_Badge_Builder::MENU_SLUG),
					'editURL' => admin_url('admin.php?page=' . SBR_Trust_Badge_Builder::MENU_SLUG . '&badge_id='),
					// The embed modal's "Add and Go to Page" hands the badge id
					// over in the URL, the same way the feed wizard does
					// (SB_Reviews_Blocks.php:219). Its own flag, because the two
					// insert different blocks — routing a badge through the feed
					// one inserted a feed instead.
					// is_scalar for the same reason parse_delete_ids() checks it:
					// absint() of an array is 1 on PHP 8, so ?sbr_badge_wizard[]=9
					// would have inserted badge number one into someone's post.
					//
					// disable/enable rather than ignore: the annotation covers its
					// own line and the next, and the read is three comment lines
					// below. Written as ignore it suppressed nothing.
					// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only preselect, validated on the line itself.
					'wizardBadge' => ( isset($_GET['sbr_badge_wizard']) && is_scalar($_GET['sbr_badge_wizard']) )
						? absint($_GET['sbr_badge_wizard'])
						: 0,
					// phpcs:enable WordPress.Security.NonceVerification.Recommended
				),
				// Belt to the two braces: a badge name reaches post_title through
				// sanitize_text_field() twice (Builder::ajax_save_badge, then
				// Store::save), and wp_strip_all_tags removes the CONTENTS of a
				// <script>, so `</script>` cannot be in this payload today. The
				// flags mean a future write path that skips one of those gates is
				// not also an escape out of the inline <script>.
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);
	}

	/**
	 * Register the saved-badge block.
	 *
	 * Places a badge by ID and carries no styling attributes: those decisions
	 * were made in the customizer and live on the saved badge.
	 *
	 * @return void
	 */
	protected function register_trust_badge_block()
	{
		wp_register_script(
			'sbr-trust-badge-block',
			trailingslashit(SBR_PLUGIN_URL) . 'assets/js/sbr-trust-badge-block.js',
			// wp-data for the embed modal's hand-off, which subscribes to the
			// editor store and inserts the badge it was sent here to place.
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n', 'wp-data' ),
			SBRVER,
			true
		);

		// The 22 __() calls in that file need BOTH halves of this: a registration
		// so a translation loads, and `assets/js` in the Makefile's make-pot
		// --include list so one is ever extracted. `wp-i18n` above only makes the
		// function available. Without these the strings are English in every
		// locale, permanently, and nothing fails.
		wp_set_script_translations('sbr-trust-badge-block', 'reviews-feed', SBR_PLUGIN_DIR . 'languages/');


		// The editor needs the saved-badge sheet, not the placement one: chip,
		// card and medal live in sbr-trust-badge.css and read ~35 design tokens.
		// sbr_badge_register_styles() is hooked to wp_enqueue_scripts only, which
		// never fires on an admin screen, so the handle does not exist here yet —
		// without this the block previewed as unstyled full-size SVGs.
		if (! wp_style_is('sbr_trust_badge', 'registered')) {
			sbr_badge_register_styles();
		}

		// Inspector and canvas rules, which describe no badge and so have no
		// business on the front end. Depends on the badge sheet for the tokens.
		wp_register_style(
			'sbr-trust-badge-block-editor',
			trailingslashit(SBR_PLUGIN_URL) . 'assets/css/sbr-trust-badge-block-editor.css',
			array( 'sbr_trust_badge' ),
			SBRVER
		);

		register_block_type(
			'sbr/trust-badge',
			array(
				'api_version'     => '3',
				'render_callback' => array( $this, 'render_trust_badge_block' ),
				'editor_script'   => 'sbr-trust-badge-block',
				// Its dependency chain pulls in the badge sheet, the tokens and
				// the shared star rules.
				'editor_style'    => 'sbr-trust-badge-block-editor',
				// Declared HERE as well as in the editor script, and it has to
				// be: get_block_wrapper_attributes() resolves the alignment
				// class from the SERVER-registered supports. With this only on
				// the JS side the editor offered the control, the attribute
				// saved, and the published page came out unaligned.
				'supports'        => array(
					'html'    => false,
					'align'   => array( 'left', 'center', 'right' ),
					// Same reason as align: get_block_wrapper_attributes() builds the
					// margin style from the SERVER registration, so declaring it only
					// in the editor script offers the control and then drops the value
					// on the published page.
					'spacing' => array( 'margin' => true ),
				),
				'attributes'      => array(
					'id'  => array(
						'type'    => 'number',
						'default' => 0,
					),
					// Bumped by the toolbar's "Refresh preview". Registered so the
					// editor can persist it; the render callback ignores it, which
					// is the point — it exists only to change the ServerSideRender
					// request URL so the REST response is not served from cache.
					'rev' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * Badge id/name pairs for the block picker.
	 *
	 * @return array<int, array{id: int, name: string}>
	 *
	 * @phpstan-return list<array{id: int, name: string}>
	 */
	protected static function badge_choices()
	{
		// One implementation, on the store — this and SBR_Badge_Widget each had a
		// copy and they had already drifted (a literal 100 versus CHOICE_LIMIT, and
		// two translation strings for the untitled-badge label).
		return SBR_Trust_Badge_Store::choices()['choices'];
	}

	/**
	 * Register the sidebar widget.
	 *
	 * @return void
	 */
	public function register_badge_widget()
	{
		register_widget(SBR_Badge_Widget::class);
	}

	/**
	 * Keep the widget out of the block widget editor's inserter.
	 *
	 * Both carry the title "Trust Badge", so a block widget area offered two
	 * identical entries with nothing to tell them apart. Where blocks are
	 * available the block is the better of the two; the widget is for classic
	 * widget areas, and this is the same filter core uses to hide its own
	 * widgets that have block equivalents (wp-includes/block-editor.php:261).
	 *
	 * @param mixed $widgets Widget-type IDs core will not offer.
	 *
	 * @return array<int, string>
	 */
	public function hide_widget_from_block_inserter($widgets)
	{
		$widgets = is_array($widgets) ? $widgets : array();

		$widgets[] = 'sbr_trust_badge';

		return $widgets;
	}

	/**
	 * Render callback for `sbr/trust-badge`.
	 *
	 * Shares the shortcode's body so the block, the shortcode and the customizer
	 * preview cannot disagree about what a saved badge looks like.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 *
	 * @return string
	 */
	public function render_trust_badge_block($attributes = array())
	{
		$id   = is_array($attributes) && isset($attributes['id']) ? absint($attributes['id']) : 0;
		$html = $this->render_saved_shortcode(array( 'id' => $id ));

		// A missing badge renders nothing, and nothing means nothing — an empty
		// wrapper div would still occupy its margin on the page.
		if ($html === '') {
			return '';
		}

		// The wrapper is what carries the alignment class core writes from the
		// block's align support. The shortcode deliberately does not get one:
		// there is no alignment to express there, and an author placing a badge
		// mid-sentence should get the badge, not a block-level div.
		if (! function_exists('get_block_wrapper_attributes')) {
			return $html;
		}

		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}



	/**
	 * Drop cached aggregates when the feeds they came from are refreshed.
	 *
	 * `sbr_after_cron_refresh` passes an ARRAY of refreshed feed IDs, not a
	 * single ID — iterate rather than coercing, or only one feed would ever be
	 * invalidated and the rest would serve a stale rating until the TTL ran out.
	 *
	 * @param array<int|string>|int|string $refreshed_feed_ids Refreshed feed IDs.
	 *
	 * @return void
	 */
	public function clear_badge_cache($refreshed_feed_ids = array())
	{
		if (! is_array($refreshed_feed_ids)) {
			$refreshed_feed_ids = array( $refreshed_feed_ids );
		}

		foreach ($refreshed_feed_ids as $feed_id) {
			SBR_Badge_Renderer::clear_cache(absint($feed_id));
		}
	}
}
