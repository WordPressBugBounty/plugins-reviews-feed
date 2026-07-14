<?php

/**
 * Smash Balloon Reviews Feed Text Template - Booking.com
 * Custom template for Booking.com reviews with title, pros/cons, and photos
 *
 * @package SmashBalloon\Reviews
 * @version 1.0 Reviews Feed by Smash Balloon
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Check if review has title, pros, cons (review photos render in the media element).
$has_title = !empty($post['title']);
$has_pros = !empty($post['metadata']['pros']);
$has_cons = !empty($post['metadata']['cons']);

// Per-section read-more. The shared sbr-feed.js truncation only handles a
// single `.sb-item-text` per card, which Booking doesn't have (its body is
// the two pros/cons sections). So each section truncates server-side and
// reveals the rest with a pure-CSS toggle.
//
// The limit follows the feed's "Content Length" setting so Booking honors the
// same trim the customizer preview applies — PostText.js runs
// SbUtils.printText(pros/cons, feedSettings), trimming each section to
// feedSettings.contentLength. Previously this was hard-coded to 140, so the
// setting had no effect on Booking frontend cards. contentLength <= 0 means
// "no limit" (mirrors sbr-feed.js `if (text_limit < 1) text_limit = 99999`).
$sbr_feed_settings = $this->feed->get_settings();
$prosncons_limit = isset($sbr_feed_settings['contentLength'])
	? intval($sbr_feed_settings['contentLength'])
	: 280;
if ($prosncons_limit < 1) {
	$prosncons_limit = PHP_INT_MAX;
}
// Stable, page-unique base id for the toggle inputs (mirrors the lightbox id scheme).
$prosncons_uid = ! empty($post['post_id'])
	? 'sbr-rm-' . $post['post_id']
	: 'sbr-rm-' . substr(md5(($post['title'] ?? '') . ($post['metadata']['pros'] ?? '') . ($post['metadata']['cons'] ?? '')), 0, 10);

/**
 * Render a pros/cons body with per-section read-more. Short (cut on a word
 * boundary) + a pure-CSS expand label when over the limit; plain text otherwise.
 *
 * @param string $text Section text (pros or cons).
 * @param string $uid  Unique id for the toggle input/label pair.
 */
$render_prosncons = function (string $text, string $uid) use ($prosncons_limit): void {
	$len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
	if ($len <= $prosncons_limit) {
		echo sbr_neutralize_shortcodes(wp_kses_post(nl2br($text)));
		return;
	}
	$short = function_exists('mb_substr') ? mb_substr($text, 0, $prosncons_limit) : substr($text, 0, $prosncons_limit);
	// Trim back to a word boundary so we don't cut mid-word (matches the JS behaviour).
	$last_space = function_exists('mb_strrpos') ? mb_strrpos($short, ' ') : strrpos($short, ' ');
	if ($last_space !== false && $last_space > 0) {
		$short = function_exists('mb_substr') ? mb_substr($short, 0, $last_space) : substr($short, 0, $last_space);
	}
	$id = esc_attr($uid);
	// All dynamic parts escaped via esc_attr() / wp_kses_post(); static markup is safe.
	echo '<input type="checkbox" id="' . $id . '" class="sbr-readmore-toggle" hidden />'
		. '<span class="sbr-readmore-short">' . sbr_neutralize_shortcodes(wp_kses_post(nl2br($short)))
		. '<label for="' . $id . '" class="sb-expand sb-readmore-label"><span class="sb-more">&hellip;</span></label></span>'
		. '<span class="sbr-readmore-full">' . sbr_neutralize_shortcodes(wp_kses_post(nl2br($text))) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
};

// Smiley face SVG icons
$pros_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16px" height="16px" style="flex-shrink: 0; margin-right: 8px;"><path fill="#008234" d="M22.5 12c0 5.799-4.701 10.5-10.5 10.5S1.5 17.799 1.5 12 6.201 1.5 12 1.5 22.5 6.201 22.5 12m1.5 0c0-6.627-5.373-12-12-12S0 5.373 0 12s5.373 12 12 12 12-5.373 12-12M5.634 13.5a1.5 1.5 0 0 0-1.414 2 8.25 8.25 0 0 0 15.56 0 1.5 1.5 0 0 0-1.414-2zm0 1.5h12.732a6.75 6.75 0 0 1-12.732 0M16.5 8.625a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5m-9 0a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5"/></svg>';

$cons_icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16px" height="16px" style="flex-shrink: 0; margin-right: 8px;"><path fill="#1a1a1a" d="M22.5 12c0 5.799-4.701 10.5-10.5 10.5S1.5 17.799 1.5 12 6.201 1.5 12 1.5 22.5 6.201 22.5 12m1.5 0c0-6.627-5.373-12-12-12S0 5.373 0 12s5.373 12 12 12 12-5.373 12-12m-5.28 5.667a7.502 7.502 0 0 0-13.444 0 .75.75 0 1 0 1.344.666 6.002 6.002 0 0 1 10.756 0 .75.75 0 0 0 1.344-.666M8.25 9.375a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5m7.5 0a.375.375 0 1 1 0-.75.375.375 0 0 1 0 .75.75.75 0 0 0 0-1.5 1.125 1.125 0 1 0 0 2.25 1.125 1.125 0 0 0 0-2.25.75.75 0 0 0 0 1.5"/></svg>';
?>

<?php if ($has_title) : ?>
<div class="sb-item-title sb-fs sbr-review-horizontal-element">
	<?php echo sbr_neutralize_shortcodes(esc_html($post['title'])); ?>
</div>
<?php endif; ?>

<?php
if ($has_pros || $has_cons) :
	$allowed_svg = array(
		'svg'  => array(
			'xmlns'   => array(),
			'viewbox' => array(),
			'width'   => array(),
			'height'  => array(),
			'style'   => array(),
		),
		'path' => array(
			'fill' => array(),
			'd'    => array(),
		),
	);
	?>
<div class="sb-item-pros-cons sbr-review-horizontal-element">
	<?php if ($has_pros) : ?>
	<div class="sb-item-pros" style="display: flex; align-items: flex-start; margin-bottom: 8px; clear: both;">
		<span class="sb-item-pros-icon" style="display: inline-flex; align-items: center; height: 1lh; flex-shrink: 0;"><?php echo wp_kses($pros_icon, $allowed_svg); ?></span>
		<span class="sb-pros-text"><?php $render_prosncons((string) $post['metadata']['pros'], $prosncons_uid . '-pros'); ?></span>
	</div>
	<?php endif; ?>

	<?php if ($has_cons) : ?>
	<div class="sb-item-cons" style="display: flex; align-items: flex-start;">
		<span class="sb-item-cons-icon" style="display: inline-flex; align-items: center; height: 1lh; flex-shrink: 0;"><?php echo wp_kses($cons_icon, $allowed_svg); ?></span>
		<span class="sb-cons-text"><?php $render_prosncons((string) $post['metadata']['cons'], $prosncons_uid . '-cons'); ?></span>
	</div>
	<?php endif; ?>
</div>
<?php endif; ?>
