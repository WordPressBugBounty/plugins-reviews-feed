<?php

namespace SmashBalloon\Reviews\Common;

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Places a feed or a badge can be embedded into.
 *
 * One provider for both, because the embed modal is one component now. Review
 * Alerts' get_wordpress_pages() answers a narrower question — "which single
 * locations can an alert be shown on" — and deliberately leaves blog posts out,
 * since alerts target those by category. An embed target list has no such
 * reason: you embed into whatever you are editing.
 *
 * Returns what the picker actually draws: the group a row belongs to, the icon
 * that group uses, and the status pill for anything not yet public. Grouping is
 * decided here rather than in React so both rails order and label identically.
 *
 * @since 2.10.0
 */
class SBR_Embed_Targets
{
	/**
	 * Post types that are never an embed target.
	 *
	 * Our own screens, and WordPress's editor scaffolding. A badge embedded into
	 * a revision or a nav-menu item is not a thing anyone means to do.
	 */
	const EXCLUDED = array(
		'sbr_trust_badge',
		'sbr_review_alert',
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'oembed_cache',
		'user_request',
	);

	/**
	 * Statuses worth showing, and the pill each one earns.
	 *
	 * Published entries get no pill — that is the expected case, and a badge on
	 * every row is noise. Drafts and scheduled posts get one because embedding
	 * into them will not put anything in front of a visitor yet, and the picker
	 * is the last moment that is cheap to notice.
	 *
	 * @return array<string, string>
	 */
	public static function status_pills()
	{
		return array(
			'publish' => '',
			'draft'   => __('Draft', 'reviews-feed'),
			'pending' => __('Pending', 'reviews-feed'),
			'future'  => __('Scheduled', 'reviews-feed'),
			'private' => __('Private', 'reviews-feed'),
		);
	}

	/**
	 * Every place a feed or badge can go, grouped and ordered for the picker.
	 *
	 * @param int $limit Hard ceiling on rows. Filterable, then clamped: an
	 *                   unbounded value here would query every post on the site
	 *                   to fill a dropdown.
	 *
	 * @return array<int, array{id: int, title: string, url: string, type: string, typeLabel: string, group: string, icon: string, status: string, statusLabel: string}>
	 */
	public static function get($limit = 200)
	{
		// Guarded here as well as at the callers. This returns titles and URLs of
		// PRIVATE, draft and pending posts — every caller today is an admin screen
		// that already checks the nonce and the capability, but a data provider
		// handing out unpublished content should not depend on that being true of
		// the next caller. Same reasoning as guarding at the sink.
		// `||`, not `&&`. The first version was
		// `function_exists(...) && ! sbr_current_user_can(...)`, which only blocks
		// when the helper EXISTS and the check fails — so a context where the
		// helper is not loaded fell straight through and returned private, draft
		// and pending titles and URLs with no gate at all. A missing capability
		// helper is a reason to refuse, not a reason to skip the question.
		if (! function_exists('sbr_current_user_can') || ! sbr_current_user_can('manage_reviews_feed_options')) {
			return array();
		}

		$limit = (int) apply_filters('sbr_embed_targets_limit', $limit);
		$limit = $limit > 0 ? min($limit, 500) : 200;

		$types = get_post_types(array( 'public' => true ), 'objects');
		$names = array();

		foreach ($types as $name => $object) {
			if (in_array($name, self::EXCLUDED, true)) {
				continue;
			}

			$names[] = $name;
		}

		$names = (array) apply_filters('sbr_embed_target_post_types', $names);

		if (empty($names)) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => $names,
				// Drafts and scheduled entries are offered on purpose — people
				// build a page before publishing it, and refusing to embed until
				// it is live would send them away to publish first.
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		// Per POST, not just per screen. The capability above is filterable via
		// `sbr_settings_pages_capability`, so a site can grant the reviews
		// capability to a role that has no business reading other people's
		// private drafts — and this returns titles and permalinks of private,
		// draft, pending and scheduled posts. read_post is the check WordPress
		// itself uses for that question.
		$posts = array_values(array_filter(
			$posts,
			static function ($post) {
				return current_user_can('read_post', $post->ID);
			}
		));

		$pills   = self::status_pills();
		$targets = array();

		foreach ($posts as $post) {
			$object = isset($types[ $post->post_type ]) ? $types[ $post->post_type ] : null;
			$status = (string) $post->post_status;

			$targets[] = array(
				'id'    => (int) $post->ID,
				// A post with no title is legal and common on drafts. Showing the
				// id keeps the row selectable instead of rendering a blank line.
				'title' => $post->post_title !== ''
					? $post->post_title
					: sprintf(
						/* translators: %d: post ID */
						__('(no title) #%d', 'reviews-feed'),
						$post->ID
					),
				'url'         => (string) get_permalink($post),
				'type'        => (string) $post->post_type,
				'typeLabel'   => $object ? (string) $object->labels->singular_name : (string) $post->post_type,
				'group'       => $object ? (string) $object->labels->name : (string) $post->post_type,
				'icon'        => self::icon_for($post->post_type),
				'status'      => $status,
				'statusLabel' => isset($pills[ $status ]) ? $pills[ $status ] : '',
			);
		}

		return self::sort_by_group($targets);
	}

	/**
	 * Which of the picker's three glyphs a post type uses.
	 *
	 * Deliberately coarse. The design draws a page, a post and a product, and a
	 * per-CPT icon set would mean inventing artwork for every page builder that
	 * registers a type. Anything that sells maps to the product mark; anything
	 * dated and authored reads as a post; the rest are pages.
	 *
	 * @param string $post_type Post type name.
	 *
	 * @return string One of page|post|product.
	 */
	protected static function icon_for($post_type)
	{
		if (in_array($post_type, array( 'product', 'download' ), true)) {
			return 'product';
		}

		if ($post_type === 'post') {
			return 'post';
		}

		return 'page';
	}

	/**
	 * Group rows together while keeping Pages first and Posts second.
	 *
	 * The picker reads top-down and most embeds land on a page, so pages lead
	 * regardless of what the site's modification dates say. Everything else
	 * keeps the recency order the query returned, which puts what someone was
	 * just editing near the top of its own group.
	 *
	 * @param array $targets Ungrouped rows.
	 *
	 * @return array Rows ordered by group.
	 */
	protected static function sort_by_group($targets)
	{
		$rank = array( 'page' => 0, 'post' => 1 );
		$buckets = array();

		foreach ($targets as $target) {
			$buckets[ $target['group'] ][] = $target;
		}

		uksort(
			$buckets,
			function ($a, $b) use ($buckets, $rank) {
				$a_type = isset($buckets[ $a ][0]['type']) ? $buckets[ $a ][0]['type'] : '';
				$b_type = isset($buckets[ $b ][0]['type']) ? $buckets[ $b ][0]['type'] : '';
				$a_rank = isset($rank[ $a_type ]) ? $rank[ $a_type ] : 2;
				$b_rank = isset($rank[ $b_type ]) ? $rank[ $b_type ] : 2;

				return $a_rank === $b_rank ? strcasecmp($a, $b) : $a_rank - $b_rank;
			}
		);

		$out = array();

		foreach ($buckets as $rows) {
			foreach ($rows as $row) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
