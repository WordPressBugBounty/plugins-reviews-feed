<?php

namespace SmashBalloon\Reviews\Common\Services;

use SmashBalloon\Reviews\Common\Feed;
use SmashBalloon\Reviews\Common\FeedCache;
use SmashBalloon\Reviews\Common\FeedDisplay;
use SmashBalloon\Reviews\Common\Feed_Locator;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Common\SBR_Settings;
use Smashballoon\Stubs\Services\ServiceProvider;

class ShortcodeService extends ServiceProvider
{
	public function register()
	{
		add_shortcode('reviews-feed', [$this, 'render']);
	}

	public function render($atts = array())
	{
		$feed_id                 = ! empty($atts['feed']) ? $atts['feed'] : 0;
		$is_single_manual_review = isset($atts['name']) && ! empty($atts['name']) ? true : false;

		$settings = SBR_Settings::get_settings_by_feed_id($feed_id, false, $is_single_manual_review);

		if ($is_single_manual_review) {
			$settings = array_merge(
				$settings,
				$this->get_single_manual_review_content($atts)
			);
		}

		do_action('sbr_before_shortcode_render', $settings);

		// Track feed location for usage statistics.
		$this->track_feed_location($feed_id, $atts);

		$feed = new Feed($settings, $feed_id, new FeedCache($feed_id, 2 * DAY_IN_SECONDS));

		$feed->init();
		if (! empty($feed->get_errors())) {
			$feed_display = new FeedDisplay($feed, new Parser());
			return $feed_display->error_html();
		}
		$feed->get_set_cache();

		$feed_display = new FeedDisplay($feed, new Parser());

		return $feed_display->with_wrap();
	}

	/**
	 * Track the feed location for usage statistics.
	 *
	 * @param int|string $feed_id The feed ID.
	 * @param array      $atts    Shortcode attributes.
	 */
	private function track_feed_location($feed_id, $atts)
	{
		// Only track if we have a valid feed ID and post ID.
		if (empty($feed_id)) {
			return;
		}

		// Get the current post ID.
		$post_id = get_the_ID();
		if (! $post_id) {
			// Try to get from global post.
			global $post;
			$post_id = ! empty($post->ID) ? $post->ID : 0;
		}

		if (! $post_id) {
			return;
		}

		// Determine HTML location based on context.
		$html_location = $this->determine_html_location();

		// Check if we should track this request.
		// Track more frequently initially to collect data, then reduce to save DB load.
		$should_track = $this->should_track_location($feed_id, $post_id);
		if (! $should_track) {
			return;
		}

		$feed_details = array(
			'feed_id'  => $feed_id,
			'atts'     => is_array($atts) ? $atts : array( 'feed' => $feed_id ),
			'location' => array(
				'post_id' => $post_id,
				'html'    => $html_location,
			),
		);

		// Ensure feed attribute is set.
		if (! isset($feed_details['atts']['feed'])) {
			$feed_details['atts']['feed'] = $feed_id;
		}

		$locator = new Feed_Locator($feed_details);
		$locator->add_or_update_entry();
	}

	/**
	 * Determine if we should track this feed location.
	 *
	 * Always tracks if this feed/post combination hasn't been tracked yet.
	 * For already tracked combinations, uses random sampling to reduce DB load.
	 *
	 * @param int|string $feed_id The feed ID.
	 * @param int        $post_id The post ID.
	 * @return bool Whether to track this location.
	 */
	private function should_track_location($feed_id, $post_id)
	{
		global $wpdb;

		$feed_locator_table = $wpdb->prefix . SBR_FEED_LOCATOR;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $feed_locator_table WHERE feed_id = %s AND post_id = %d",
				(string) $feed_id,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// If no existing entry, always track (first visit).
		if (empty($existing) || (int) $existing === 0) {
			return true;
		}

		// For existing entries, use random sampling to update periodically (reduce DB load).
		// This helps keep the last_update timestamp fresh.
		return Feed_Locator::should_do_locating();
	}

	/**
	 * Determine the HTML location where the feed is being rendered.
	 *
	 * @return string The HTML location (content, header, footer, sidebar, or unknown).
	 */
	private function determine_html_location()
	{
		// Check if we're in a sidebar/widget.
		if (is_active_widget(false, false, 'text', true) || doing_action('dynamic_sidebar')) {
			return 'sidebar';
		}

		// Check if we're in the header.
		if (doing_action('wp_head') || did_action('wp_head') && ! did_action('wp_footer') && ! in_the_loop()) {
			return 'header';
		}

		// Check if we're in the footer.
		if (doing_action('wp_footer')) {
			return 'footer';
		}

		// Check if we're in the main content loop.
		if (in_the_loop() || is_singular()) {
			return 'content';
		}

		return 'unknown';
	}

	/**
	 * Get single manual review content settings.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return array Settings array.
	 */
	public function get_single_manual_review_content($atts)
	{
		$settings                                = array();
		$settings['singleManualReview']          = true;
		$settings['singleManualReviewContent']   = array(
			'name'     => isset($atts['name']) && ! empty($atts['name']) ? $atts['name'] : false,
			'content'  => isset($atts['content']) && ! empty($atts['content']) ? $atts['content'] : false,
			'rating'   => isset($atts['rating']) && ! empty($atts['rating']) ? $atts['rating'] : false,
			'avatar'   => isset($atts['avatar']) && ! empty($atts['avatar']) ? $atts['avatar'] : false,
			'time'     => isset($atts['time']) && ! empty($atts['time']) ? $atts['time'] : false,
			'provider' => isset($atts['provider']) && ! empty($atts['provider']) ? $atts['provider'] : false,
		);
		$settings['showHeader']                  = false;
		$settings['showLoadButton']              = false;

		// This to remove the multiple columns since we are only showing one Review.
		$settings['gridDesktopColumns']     = 1;
		$settings['gridTabletColumns']      = 1;
		$settings['gridMobileColumns']      = 1;
		$settings['masonryDesktopColumns']  = 1;
		$settings['masonryTabletColumns']   = 1;
		$settings['masonryMobileColumns']   = 1;
		$settings['carouselDesktopColumns'] = 1;
		$settings['carouselTabletColumns']  = 1;
		$settings['carouselMobileColumns']  = 1;

		return $settings;
	}

}
