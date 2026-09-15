<?php

namespace SmashBalloon\Reviews\Common\Badges;

use Smashballoon\Customizer\V2\SB_Utils;
use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Builder\SBR_Sources;
use SmashBalloon\Reviews\Common\DisplayElements;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Common\Utils\EmailVerification;

// Same namespace as this class, so no import is needed for the store; the
// renderer is too, but naming it here keeps the dependency visible.

/**
 * Admin surface for saved Trust Badges (SBR-163 / design rock SBR-78).
 *
 * The plugin half of the builder: it owns the submenu, mounts the shared React
 * app, hands it the data it needs, and answers its AJAX. The screens themselves
 * — the All Badges list and the customizer — are React and live in the
 * `smashballoon/customizer` package, exactly as Review Alerts splits.
 *
 * Modelled on SBR_ReviewAlert_Builder so the two behave identically: same
 * parent menu, same `#sb-app` mount, same `reactScreen` switch, same nonce and
 * capability on every AJAX route.
 */
class SBR_Trust_Badge_Builder
{
	/**
	 * Submenu slug.
	 */
	const MENU_SLUG = 'sbr-trust-badges';

	/**
	 * React screen names, matching the `reactScreen` switch in the customizer.
	 */
	const SCREEN_LIST   = 'trustBadges';
	const SCREEN_EDITOR = 'trustBadgeEditor';

	/**
	 * Whether this site may actually use the builder: Pro Plus AND a valid
	 * licence. Everything else falls through to the locked screen.
	 *
	 * @var bool
	 */
	private $licensed = false;

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public function register()
	{
		// Tier alone is not enough. Util::sbr_is_pro_plus() only reads the stored
		// `sbr_statuses['license_tier']`; it never asks whether that licence is
		// still valid. Review Alerts pairs the two (SBR_ReviewAlert_Builder.php:103),
		// so without this an expired Pro Plus site would lose Review Alerts and
		// keep the whole Trust Badges builder — including its create/save/delete
		// routes.
		$this->licensed = Util::sbr_is_pro_plus() ? (bool) check_license_valid() : false;

		add_action('admin_menu', array( $this, 'register_menu' ), 11);

		add_action('wp_ajax_sbr_trust_badge_list', array( $this, 'ajax_list' ));
		add_action('wp_ajax_sbr_trust_badge_save', array( $this, 'ajax_save' ));
		add_action('wp_ajax_sbr_trust_badge_delete', array( $this, 'ajax_delete' ));
		add_action('wp_ajax_sbr_trust_badge_duplicate', array( $this, 'ajax_duplicate' ));
		add_action('wp_ajax_sbr_trust_badge_email_embed', array( $this, 'ajax_email_embed' ));
		add_action('wp_ajax_sbr_trust_badge_preview', array( $this, 'ajax_preview' ));
		add_action('wp_ajax_sbr_trust_badge_create_page', array( $this, 'ajax_create_page' ));
	}

	/**
	 * Add the submenu under Reviews Feed.
	 *
	 * @return void
	 */
	public function register_menu()
	{
		$cap = current_user_can('manage_reviews_feed_options')
			? 'manage_reviews_feed_options'
			: 'manage_options';

		// Same filter Review Alerts and Settings already honour, so a site that
		// has re-mapped who may configure the plugin gets one answer, not three.
		$cap = apply_filters('sbr_settings_pages_capability', $cap);

		$title = __('Trust Badges', 'reviews-feed');

		// sbr_is_pro_plus(), not sbr_is_pro(): the latter is the BUILD constant, so a
		// Pro build with a deactivated licence answered true and the pill vanished on
		// the one screen that needed it. QA reported exactly that.
		//
		// "PRO+", not "PRO", which is what made the old LITE-only test look right:
		// telling a paying Pro Basic customer a feature is "PRO" is a mislabel, but
		// telling them it is "PRO+" is simply true, and it matches the "Plus Feature"
		// the body copy on this screen already uses.
		if (! Util::sbr_is_pro_plus()) {
			$title .= '<span class="sb-men-pro">PRO+</span>';
		}

		$page = add_submenu_page(
			'sbr',
			__('Trust Badges', 'reviews-feed'),
			$title,
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			3
		);

		if ($page) {
			add_action('load-' . $page, array( $this, 'enqueue_assets' ));
		}
	}

	/**
	 * True when the request is editing one badge rather than listing them.
	 *
	 * @return int Badge id being edited, or 0 for the list screen.
	 */
	private function edited_badge_id()
	{
		// Read-only screen switch; the nonce that matters guards the AJAX
		// routes, which are the only things that mutate anything.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// is_numeric, not just isset: `?badge_id[]=99` makes absint() cast an
		// ARRAY, which is 1 on PHP 8 (with a warning) — so the editor opened badge
		// one. Found by widening the request-read guard from one file to the whole
		// badge directory, which is the finding that produced this fix.
		return isset($_GET['badge_id']) && is_numeric($_GET['badge_id'])
			? absint($_GET['badge_id'])
			: 0;
	}

	/**
	 * Mount point for the React app.
	 *
	 * @return void
	 */
	public function render_page()
	{
		if (! $this->licensed) {
			$this->render_upsell();

			return;
		}

		// A bundle without these screens mounts nothing and reports nothing — no
		// console error, no failed request, a 0px `#sb-app`. Say so instead.
		if (! self::bundle_has_screens()) {
			$this->render_missing_screens_notice();

			return;
		}

		echo '<div id="sb-app" class="sb-fs"></div>';
	}

	/**
	 * Filesystem path of the bundle the browser will actually load.
	 *
	 * It has to be derived from `SB_CUSTOMIZER_ASSETS`, the same constant the
	 * enqueue uses, and NOT from `SBR_PLUGIN_DIR`. bootstrap.php:50 defines that
	 * constant behind `if (! defined(...))`, and the free plugin's
	 * bootstrap.php:46 defines it identically — so whichever Smash Balloon
	 * plugin loads first owns it, and it can point at that plugin's own vendor
	 * copy. Probing our own directory in that case reads a bundle nobody loads
	 * and answers "supported" while the browser gets a marker-less one, which is
	 * the silent blank screen this guard exists to prevent.
	 *
	 * @return string Path, or '' when the URL cannot be mapped to one.
	 */
	private static function bundle_path()
	{
		$suffix = '/build/static/js/main.js';

		if (! defined('SB_CUSTOMIZER_ASSETS')) {
			// Nothing enqueued it yet (unit tests, direct calls): our own copy is
			// the one this build would use.
			return SBR_PLUGIN_DIR . 'vendor/smashballoon/customizer/sb-common/sb-customizer' . $suffix;
		}

		$url = SB_CUSTOMIZER_ASSETS . $suffix;

		if (! defined('WP_CONTENT_URL') || ! defined('WP_CONTENT_DIR')) {
			return '';
		}

		// Compare scheme-less: the constant is built by plugin_dir_url(), which
		// follows the request, so http/https can differ from WP_CONTENT_URL.
		// Cast the result: preg_replace() is string|null, and a null here would
		// reach strpos()/substr() below.
		$strip = function ($value) {
			return (string) preg_replace('#^https?://#', '', (string) $value);
		};

		$base = $strip(WP_CONTENT_URL);
		$here = $strip($url);

		if ('' === $base || 0 !== strpos($here, $base)) {
			// Assets served from somewhere we cannot map (a CDN, a relocated
			// content dir). Unknown, not absent — bundle_has_screens() fails open.
			return '';
		}

		return rtrim(WP_CONTENT_DIR, '/') . substr($here, strlen($base));
	}

	/**
	 * Does the shipped customizer bundle contain the Trust Badges screens?
	 *
	 * The screens live in the customizer package, not here, and the two are
	 * versioned separately — so a plugin build composed against a customizer
	 * that predates them enqueues a bundle with no `trustBadges` in it. The
	 * plugin side is all correct in that state, which is exactly why it is hard
	 * to read: `reactScreen` is sent, the nonce is there, and the mount renders
	 * empty forever.
	 *
	 * **Fails OPEN.** A `false` here means the file was read and the marker was
	 * genuinely absent. An unreadable file returns true, because a wrong path
	 * (relocated assets, a broken vendor symlink, restrictive permissions) must
	 * not put a warning over a screen that works — the browser fetches the
	 * bundle by URL, which can succeed where a filesystem read does not.
	 *
	 * @return bool
	 */
	public static function bundle_has_screens()
	{
		$path = self::bundle_path();

		if ('' === $path || ! is_readable($path)) {
			return true;
		}

		$size  = filesize($path);
		$mtime = filemtime($path);

		if (false === $size || false === $mtime) {
			return true;
		}

		// Keyed on the build itself, so a rebuilt bundle is re-probed instead of
		// answering from a stale cache.
		$cache_key = 'sbr_tb_bundle_screens_' . md5($path . '|' . $size . '|' . $mtime);
		$cached    = get_transient($cache_key);

		if ('yes' === $cached || 'no' === $cached) {
			return 'yes' === $cached;
		}

		$found = self::file_contains($path, self::SCREEN_LIST);

		set_transient($cache_key, $found ? 'yes' : 'no', WEEK_IN_SECONDS);

		return $found;
	}

	/**
	 * Stream a file looking for one short marker.
	 *
	 * Read in chunks rather than with file_get_contents(): the bundle is ~1.1MB
	 * today across 2 lines, so there is no line-wise shortcut and no reason to
	 * hold all of it. Chunks overlap by the marker length minus one, which is
	 * every split a match can survive, so a marker on a boundary is still found.
	 *
	 * @param  string $path   File to read.
	 * @param  string $needle Marker to find.
	 * @return bool
	 */
	private static function file_contains($path, $needle)
	{
		// Checked before fopen() rather than relying on its return: fopen on a
		// missing path emits a PHP Warning, and an unreadable bundle is an
		// expected condition here, not a warning-worthy one.
		if (! is_readable($path)) {
			return true;
		}

		$handle = fopen($path, 'rb');

		if (false === $handle) {
			return true;
		}

		$overlap = strlen($needle) - 1;
		$tail    = '';
		$found   = false;

		while (! feof($handle)) {
			$chunk = fread($handle, 524288);

			// A read error mid-stream means the file was never seen to the end, so
			// "the marker is absent" is not a conclusion this can draw. Fail open,
			// like the unreadable-path branch above — returning $found here would
			// put an error notice over a bundle that may well be fine.
			if (false === $chunk) {
				fclose($handle);

				return true;
			}

			// Empty read at EOF: nothing more to search, so $found is the answer.
			if ('' === $chunk) {
				break;
			}

			if (false !== strpos($tail . $chunk, $needle)) {
				$found = true;
				break;
			}

			$tail = $overlap > 0 ? substr($chunk, -$overlap) : '';
		}

		fclose($handle);

		return $found;
	}

	/**
	 * Shown when the build ships a customizer without the badge screens.
	 *
	 * @return void
	 */
	private function render_missing_screens_notice()
	{
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Trust Badges', 'reviews-feed'); ?></h1>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e('Trust Badges cannot be displayed in this build.', 'reviews-feed'); ?></strong>
				</p>
				<p>
					<?php esc_html_e('The Trust Badges screens are part of the Smash Balloon customizer package, and the copy bundled with this build does not include them. Everything else on this site is unaffected, and badges already saved keep rendering on your pages.', 'reviews-feed'); ?>
				</p>
				<p>
					<?php esc_html_e('If you are testing a development build, rebuild it against a customizer that contains the Trust Badges screens. Otherwise, updating Reviews Feed to a release that ships them will restore this screen.', 'reviews-feed'); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The locked screen a Lite or Pro Basic site sees instead of the builder.
	 *
	 * Static PHP, no React — the same shape SBR_ReviewAlert_Builder::render_pro_upsell()
	 * uses, down to the class names, so the two locked pages in this plugin look
	 * like one product. Every LAYOUT class here is shipped in sb-common.css. The
	 * two exceptions are ours and are named as such: `.sbr-badge*`, which the
	 * front-end rails already use, and `.sbr-tb-splash`, added in
	 * assets/css/sbr-badges.css for the hero canvas.
	 *
	 * Two deliberate differences from the Review Alerts version, both flagged by
	 * the design rock as bugs it was reproducing on purpose "so they get seen":
	 *
	 *  1. The "Lite Plugin Users get 50% OFF" notice is shown to LITE ONLY.
	 *     Review Alerts renders one output for both audiences, so a paying Pro
	 *     Basic customer is currently told they qualify for a Lite discount.
	 *  2. The label reads "Plus Feature", not "Pro Feature". Badges are gated at
	 *     Pro Plus, so telling a Pro Basic user this is a "Pro Feature" leaves
	 *     them reading a wall for something they believe they already bought.
	 *
	 * @return void
	 */
	private function render_upsell()
	{
		$upgrade_url = 'https://smashballoon.com/pricing/reviews-feed/?utm_campaign=reviews-free&utm_source=trust-badges&utm_medium=admin-page&utm_content=UpgradeToProPlus';

		// Lite has no Pro build at all; Pro Basic does. Only the former qualifies
		// for the Lite discount.
		$is_lite = ! Util::sbr_is_pro();

		?>
		<div id="sb-app" class="sb-fs">
			<section class="sb-dashboard-header sb-header sb-fs">
				<div class="sb-header-content sb-fs">
					<div class="sb-dashboard-header-logo">
						<svg width="180" height="36" viewBox="0 0 536 107" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M134.266 34.3858C128.863 30.3682 123.737 29.8141 120.551 29.8141C116.256 29.8141 112.377 30.7146 109.121 34.0394C106.351 36.8794 105.104 40.3428 105.104 44.4296C105.104 46.6462 105.45 49.9018 107.875 52.4647C109.676 54.4042 112.169 55.3739 114.316 56.1359L118.126 57.4519C119.442 57.9368 122.282 58.9758 123.529 60.0149C124.499 60.8461 125.122 61.8158 125.122 63.3397C125.122 65.0714 124.36 66.3182 123.46 67.0802C121.936 68.3963 119.996 68.6733 118.611 68.6733C116.464 68.6733 114.594 68.1192 112.793 67.0109C111.546 66.249 109.676 64.6558 108.498 63.4782L102.957 71.0977C104.688 72.8294 107.39 74.9767 109.745 76.1543C112.654 77.6089 115.563 78.0938 118.888 78.0938C121.936 78.0938 127.893 77.6782 131.98 73.3835C134.404 70.8899 136.067 66.7338 136.067 61.9544C136.067 59.2529 135.374 55.9281 132.672 53.3652C130.871 51.6335 128.378 50.5944 126.369 49.8325L122.906 48.5164C119.789 47.3388 118.195 46.9232 117.087 45.8842C116.394 45.2608 116.048 44.4296 116.048 43.3213C116.048 42.1438 116.533 41.1047 117.226 40.4121C118.473 39.0267 120.273 38.8189 121.728 38.8189C123.044 38.8189 126.023 39.0267 129.486 42.1438L134.266 34.3858Z" fill="#141B38"/>
							<path d="M140.486 77.0547H150.461V61.2617C150.461 60.2227 150.53 56.7593 152.4 54.9583C153.37 54.0578 154.548 53.7115 155.864 53.7115C156.903 53.7115 158.149 53.9193 159.188 54.9583C160.712 56.4822 160.782 58.9066 160.782 60.9846V77.0547H170.756V61.6773C170.756 59.6685 170.964 57.2441 172.28 55.5817C173.111 54.4734 174.497 53.7115 176.159 53.7115C177.614 53.7115 178.93 54.3349 179.761 55.3739C181.077 57.0363 181.077 59.807 181.077 61.4002V77.0547H191.052V57.5212C191.052 55.3046 190.844 51.1486 187.865 48.3086C185.995 46.5076 182.947 45.3993 179.207 45.3993C176.783 45.3993 174.774 45.815 172.626 47.131C170.618 48.3779 169.44 49.8325 168.678 51.0793C167.847 49.2091 166.531 47.6852 164.938 46.7847C162.86 45.5379 160.435 45.3993 159.119 45.3993C156.764 45.3993 153.024 45.8842 150.461 49.7632V46.1613H140.486V77.0547Z" fill="#141B38"/>
							<path d="M220.475 49.6247C217.635 45.7457 213.409 45.1223 210.777 45.1223C206.483 45.1223 202.742 46.6462 200.041 49.3476C197.201 52.1876 195.261 56.6207 195.261 61.8158C195.261 65.9026 196.508 69.9202 199.764 73.3835C203.158 76.9855 206.898 78.0938 211.331 78.0938C213.894 78.0938 217.773 77.4703 220.475 73.3143V77.0547H230.449V46.1613H220.475V49.6247ZM213.271 53.7115C215.072 53.7115 217.288 54.4042 218.812 55.8588C220.267 57.2441 221.098 59.3222 221.098 61.5387C221.098 64.1709 219.99 66.1104 218.674 67.3572C217.358 68.6733 215.487 69.5045 213.479 69.5045C211.124 69.5045 208.976 68.5348 207.591 67.0802C206.69 66.1104 205.513 64.3095 205.513 61.5387C205.513 58.768 206.76 56.9671 207.799 55.9281C209.115 54.612 211.124 53.7115 213.271 53.7115Z" fill="#141B38"/>
							<path d="M258.964 47.7545C256.47 46.2998 253.284 45.1223 248.712 45.1223C245.872 45.1223 242.132 45.6764 239.361 48.2393C237.56 49.9018 236.383 52.3954 236.383 55.3739C236.383 57.729 237.145 59.4607 238.599 60.9846C239.915 62.3007 241.785 63.2704 243.586 63.8246L246.08 64.5865C247.535 65.0021 248.435 65.2792 249.128 65.6948C250.028 66.249 250.236 66.9416 250.236 67.4958C250.236 68.2577 249.821 69.0197 249.197 69.5045C248.297 70.1972 246.703 70.1972 246.08 70.1972C244.764 70.1972 243.309 69.9202 241.924 69.2275C240.885 68.7426 239.5 67.7729 238.53 66.9416L234.305 73.6606C238.322 77.1933 242.824 78.0938 246.981 78.0938C250.236 78.0938 253.977 77.6089 257.024 74.5611C258.41 73.1757 260.211 70.5436 260.211 66.4568C260.211 64.1017 259.587 62.2314 257.786 60.569C256.193 59.1144 254.392 58.4217 252.661 57.8676L250.028 57.0363C248.781 56.6207 247.742 56.4129 247.05 55.9973C246.565 55.7203 246.08 55.3046 246.08 54.612C246.08 54.1271 246.357 53.573 246.703 53.2266C247.327 52.6032 248.504 52.3261 249.543 52.3261C251.483 52.3261 253.492 53.1573 255.016 54.0578L258.964 47.7545Z" fill="#141B38"/>
							<path d="M264.339 77.0547H274.313V61.608C274.313 60.0841 274.382 57.1056 276.391 55.1661C276.945 54.612 278.192 53.6422 280.339 53.6422C281.794 53.6422 283.318 54.1271 284.218 55.0276C285.812 56.5515 285.881 59.0451 285.881 61.1924V77.0547H295.855V57.4519C295.855 55.0968 295.648 51.2871 292.808 48.4471C290.106 45.6764 286.504 45.3301 284.08 45.3301C281.863 45.3301 280.132 45.5379 278.054 46.6462C276.876 47.2696 275.56 48.3086 274.313 49.8325V26.5585H264.339V77.0547Z" fill="#141B38"/>
							<path d="M316.822 30.8531V77.0547H334.208C337.048 77.0547 342.59 76.7777 346.399 73.1065C348.2 71.3055 350.001 68.3963 350.001 63.7553C350.001 59.6685 348.547 57.0363 347.023 55.5124C345.36 53.85 342.867 52.7417 340.65 52.3954C341.758 51.9105 343.352 50.9408 344.529 49.0013C345.776 46.9925 346.053 44.9145 346.053 43.1135C346.053 41.1047 345.707 37.2257 342.728 34.3858C339.126 30.9916 333.446 30.8531 330.953 30.8531H316.822ZM327.351 39.1652H329.013C330.953 39.1652 333.031 39.1652 334.555 40.3428C335.386 40.9662 336.355 42.213 336.355 44.2218C336.355 46.2306 335.455 47.6159 334.485 48.3086C332.961 49.4169 330.537 49.6247 329.082 49.6247H327.351V39.1652ZM327.351 57.5212H330.26C332.407 57.5212 336.009 57.5212 337.879 59.2529C338.572 59.8763 339.334 61.1231 339.334 62.9934C339.334 64.6558 338.78 65.9719 337.81 66.8724C335.871 68.6733 332.615 68.7426 329.914 68.7426H327.351V57.5212Z" fill="#141B38"/>
							<path d="M378.384 49.6247C375.544 45.7457 371.318 45.1223 368.686 45.1223C364.392 45.1223 360.651 46.6462 357.95 49.3476C355.11 52.1876 353.17 56.6207 353.17 61.8158C353.17 65.9026 354.417 69.9202 357.673 73.3835C361.067 76.9855 364.807 78.0938 369.24 78.0938C371.803 78.0938 375.682 77.4703 378.384 73.3143V77.0547H388.358V46.1613H378.384V49.6247ZM371.18 53.7115C372.981 53.7115 375.197 54.4042 376.721 55.8588C378.176 57.2441 379.007 59.3222 379.007 61.5387C379.007 64.1709 377.899 66.1104 376.583 67.3572C375.267 68.6733 373.396 69.5045 371.388 69.5045C369.033 69.5045 366.885 68.5348 365.5 67.0802C364.599 66.1104 363.422 64.3095 363.422 61.5387C363.422 58.768 364.669 56.9671 365.708 55.9281C367.024 54.612 369.033 53.7115 371.18 53.7115Z" fill="#141B38"/>
							<path d="M394.292 26.5585V77.0547H404.266V26.5585H394.292Z" fill="#141B38"/>
							<path d="M410.478 26.5585V77.0547H420.452V26.5585H410.478Z" fill="#141B38"/>
							<path d="M461.159 61.608C461.159 57.6597 459.635 53.2266 456.518 50.1096C453.747 47.3388 449.037 45.1223 443.011 45.1223C436.984 45.1223 432.274 47.3388 429.503 50.1096C426.386 53.2266 424.862 57.6597 424.862 61.608C424.862 65.5563 426.386 69.9894 429.503 73.1065C432.274 75.8772 436.984 78.0938 443.011 78.0938C449.037 78.0938 453.747 75.8772 456.518 73.1065C459.635 69.9894 461.159 65.5563 461.159 61.608ZM443.011 53.5729C445.296 53.5729 447.167 54.3349 448.621 55.7895C450.076 57.2441 450.907 59.1144 450.907 61.608C450.907 64.1017 450.076 65.9719 448.621 67.4265C447.167 68.8811 445.296 69.6431 443.08 69.6431C440.448 69.6431 438.647 68.6733 437.4 67.4265C436.222 66.249 435.114 64.448 435.114 61.608C435.114 59.1144 435.945 57.2441 437.4 55.7895C438.855 54.3349 440.725 53.5729 443.011 53.5729Z" fill="#141B38"/>
							<path d="M500.073 61.608C500.073 57.6597 498.549 53.2266 495.432 50.1096C492.661 47.3388 487.951 45.1223 481.925 45.1223C475.899 45.1223 471.189 47.3388 468.418 50.1096C465.301 53.2266 463.777 57.6597 463.777 61.608C463.777 65.5563 465.301 69.9894 468.418 73.1065C471.189 75.8772 475.899 78.0938 481.925 78.0938C487.951 78.0938 492.661 75.8772 495.432 73.1065C498.549 69.9894 500.073 65.5563 500.073 61.608ZM481.925 53.5729C484.211 53.5729 486.081 54.3349 487.536 55.7895C488.99 57.2441 489.822 59.1144 489.822 61.608C489.822 64.1017 488.99 65.9719 487.536 67.4265C486.081 68.8811 484.211 69.6431 481.994 69.6431C479.362 69.6431 477.561 68.6733 476.314 67.4265C475.137 66.249 474.028 64.448 474.028 61.608C474.028 59.1144 474.86 57.2441 476.314 55.7895C477.769 54.3349 479.639 53.5729 481.925 53.5729Z" fill="#141B38"/>
							<path d="M504.215 77.0547H514.19V61.1924C514.19 59.1836 514.467 56.9671 516.129 55.3046C516.891 54.4734 518.207 53.6422 520.285 53.6422C522.086 53.6422 523.333 54.2656 524.095 55.0276C525.688 56.6207 525.758 59.1836 525.758 61.1924V77.0547H535.732V57.5212C535.732 55.0276 535.524 51.3564 532.615 48.4471C529.983 45.815 526.45 45.3301 523.749 45.3301C520.839 45.3301 517.238 45.9535 514.19 49.8325V46.1613H504.215V77.0547Z" fill="#141B38"/>
							<path fill-rule="evenodd" clip-rule="evenodd" d="M80.3445 49.8911C80.3445 22.6311 62.4761 0.532379 40.4263 0.532379C18.3766 0.532379 0.5 22.6311 0.5 49.8911C0.5 76.0241 16.874 97.347 37.6089 99.1355L35.4039 106.118L49.2299 104.942L44.379 99.013C64.5667 96.563 80.3445 75.5096 80.3445 49.8911Z" fill="#FE544F"/>
							<path fill-rule="evenodd" clip-rule="evenodd" d="M49.9446 17.8853L51.8657 37.7078L71.7713 38.2796L57.372 51.6681L68.745 68.1175L49.5854 64.516L43.7769 83.676L34.9576 66.5183L17.1501 74.6779L24.0004 56.2844L6.633 47.5245L25.2243 41.5455L20.0942 23.2308L37.6829 33.2583L49.9446 17.8853Z" fill="white"/>
						</svg>
						<span class="sb-dashboard-header-haeding sb-text-small"> / <?php esc_html_e('Trust Badges', 'reviews-feed'); ?> </span>
					</div>
				</div>
			</section>

			<section class="sb-full-wrapper sb-fs">
				<section class="sb-small-wrapper sbr-trust-badges-upsell">
					<?php
					// Fixed inputs, so this hero is the same on every site. It used to be
					// specimen_html(), which reads the site's own feed and sources and
					// answers '' when there are none — and the column then disappeared on
					// exactly the sites this screen exists for. QA saw it on a
					// licence-deactivated site.
					//
					// Still the real badge through the front-end renderer rather than a
					// screenshot, so the hero cannot go stale when the design changes.
					// The guard below stays: render() answering '' would otherwise frame a
					// 418x318 empty box beside the copy.
					$specimen = self::upsell_specimen_html();
					?>
					<div class="sb-single-collection-empty sb-fs<?php echo $specimen === '' ? ' sbr-tb-upsell-no-specimen' : ''; ?>">
						<?php if ($specimen !== '') : ?>
							<div class="sb-single-collection-icon sb-fs">
								<div class="sbr-tb-splash">
									<?php
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output, escaped at source.
									echo $specimen;
									?>
								</div>
							</div>
						<?php endif; ?>
						<div class="sb-single-collection-empty-text">
							<span class="sbr-pro-feature-label"><?php esc_html_e('Plus Feature', 'reviews-feed'); ?></span>
							<h3 class="sb-h3 sb-fs"><?php esc_html_e('Show your rating where visitors decide', 'reviews-feed'); ?></h3>
							<p class="sb-small-p sb-light-text sb-fs"><?php esc_html_e('A trust badge combines your connected sources into one compact mark you can drop on your homepage, your product pages, or your email signature — and it updates itself.', 'reviews-feed'); ?></p>
							<div class="sb-single-collection-utm-btns sb-fs">
								<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer" class="sb-btn sb-btn-primary sb-btn-medium" style="text-decoration: none; color: #fff;">
									<span><?php esc_html_e('Upgrade to Pro', 'reviews-feed'); ?></span>
									<span class="sb-btn-icon" style="margin-left: 8px; display: flex; align-items: center;">
										<svg width="8" height="10" viewBox="0 0 7 10" xmlns="http://www.w3.org/2000/svg" style="width: 8px; height: 10px;" aria-hidden="true" focusable="false">
											<path d="M1.5 1L5.5 5L1.5 9" style="fill: none;" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</span>
								</a>
								<?php if ($is_lite) : ?>
									<a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener noreferrer" class="sb-notice sb-notice-default sb-upgradecollection-lite-banner-btn" style="text-decoration: none;">
										<span class="sb-notice-icon">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
												<path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/>
											</svg>
										</span>
										<span class="sb-text-tiny">
											<strong class="sb-fs"><?php esc_html_e('Lite Plugin Users get 50% OFF.', 'reviews-feed'); ?></strong>
											<span class="sb-fs"><?php esc_html_e('(auto-applied at checkout)', 'reviews-feed'); ?></span>
										</span>
									</a>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<div class="sb-single-collection-empty-works sb-fs">
						<h4 class="sb-h4 sb-fs"><?php esc_html_e('How it works', 'reviews-feed'); ?></h4>
						<div class="sb-single-collection-steps sb-fs">
							<?php
							// Copy is the design rock's, rewritten there on 2026-08-03 to be the
							// arc a badge travels — build, place, stays current — rather than a
							// list of feature claims. Icons are its three Material Symbols
							// Outlined marks (style / diamond_shine / update), lifted verbatim at
							// the 40px optical size rather than 24px scaled up, so the strokes
							// match the other 40dp marks in the plugin. Hence the -960 viewBox.
							$steps = array(
								array(
									'title' => __('Pick a style, match your site', 'reviews-feed'),
									'text'  => __('Chip, card or medal — light, dark, tinted, or your own accent colour. The preview updates as you change it, so you ship what you see.', 'reviews-feed'),
									'icon'  => 'M154.33-152.67 127.67-162Q96-175.67 84.83-209q-11.16-33.33 5.5-65.67l64-142.66v264.66Zm177.34 81.34q-33.67 0-57.5-23.84-23.84-23.83-23.84-57.5v-218L347-98q2.33 7.67 6 13.83 3.67 6.17 9.33 12.84h-30.66Zm194-17.34q-24.67 10-50-3.66-25.34-13.67-36-39.67L253-640.67q-10.67-26 2-50.83t40-35.17L607.67-840q26-9.33 50.66 3.67 24.67 13 35.34 39.66L880.33-292q8.67 27.33-2.66 53.5-11.34 26.17-38.67 35.83l-313.33 114Zm-83.34-488.66q13.67 0 23.5-9.84 9.84-9.83 9.84-23.5 0-13.66-9.84-23.5Q456-644 442.33-644q-13.66 0-23.5 9.83-9.83 9.84-9.83 23.5 0 13.67 9.83 23.5 9.84 9.84 23.5 9.84Zm61.34 424.66 312.66-114L629.67-776 317-662.67l186.67 510ZM317-662.67 629.67-776 317-662.67Z',
								),
								array(
									'title' => __('Put it where it counts', 'reviews-feed'),
									'text'  => __('A block, a shortcode, or plain HTML you can paste somewhere you don\'t control — an email signature, a marketplace profile.', 'reviews-feed'),
									'icon'  => 'M481.33-80 122-434l198-241.33h322L840.67-434 481.33-80Zm-305-614L100-769l47.67-48 75.66 76.33-47 46.67ZM448-772.67V-880h66.67v107.33H448ZM783.67-694l-47-47L813-817.33 860-769l-76.33 75ZM481.33-173.33l234-230.67h-468l234 230.67ZM352-608.67l-113.67 138h486l-113.66-138H352Z',
								),
								array(
									'title' => __('Keep ratings up to date', 'reviews-feed'),
									'text'  => __('Every connected source folds into one weighted rating that refreshes on its own. Choose which sources count; the badge stays current.', 'reviews-feed'),
									'icon'  => 'M482-120q-75 0-140.83-28.5-65.84-28.5-115-77.33Q177-274.67 148.5-340.5T120-481.33q0-75 28.5-140.17 28.5-65.17 77.67-113.67 49.16-48.5 115-76.66Q407-840 482-840q80.67 0 152.83 35 72.17 35 123.84 97v-102h66.66v218.67H606V-658h106.67q-43-52.67-102.67-84t-128-31.33q-122.33 0-208.83 84.16-86.5 84.17-86.5 205.84 0 123.66 85.83 210.16t209.5 86.5q118.33 0 201.83-81.33 83.5-81.33 88.17-199.33h67.33q-5 145.66-108.5 246.5Q627.33-120 482-120Zm118.67-195.33-153.34-152V-682H514v187.33l134 132-47.33 47.34Z',
								),
							);

							foreach ($steps as $step) :
								?>
								<div class="sb-single-collection-step-item">
									<div class="sb-single-collection-step-item-icon">
										<svg width="40" height="40" viewBox="0 -960 960 960" fill="#0068A0" aria-hidden="true" focusable="false">
											<path d="<?php echo esc_attr($step['icon']); ?>"/>
										</svg>
									</div>
									<div class="sb-single-collection-step-item-text">
										<strong class="sb-fs"><?php echo esc_html($step['title']); ?></strong>
										<p class="sb-small-p"><?php echo esc_html($step['text']); ?></p>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>
			</section>
		</div>
		<?php
	}

	/**
	 * Give the React app what it needs.
	 *
	 * @return void
	 */
	public function enqueue_assets()
	{
		// Below Pro Plus the page is a static PHP wall — no React screen exists
		// for it, so loading the bundle would ship a megabyte of app to render
		// nothing. Only the stylesheet it borrows its classes from. Same posture
		// as SBR_ReviewAlert_Builder::enqueue_admin_scripts():142-153.
		if (! $this->licensed) {
			wp_enqueue_style(
				'sb-customizer-style',
				SB_CUSTOMIZER_ASSETS . '/build/static/css/main.css',
				array(),
				Util::asset_version('vendor/smashballoon/customizer/sb-common/sb-customizer/build/static/css/main.css')
			);

			// BOTH sheets. `sbr_badges` carries .sbr-tb-splash, the panel; the badge
			// inside it is a chip/card/medal, whose ~108 rules and ~35 design tokens
			// live in sbr-trust-badge.css. With only the first, the hero rendered at
			// the browser's initial values — full-size star glyphs and provider marks
			// several hundred pixels tall.
			//
			// Enqueue on load-{$page}, NOT mid-render: by the time render_page()
			// runs, admin_head has flushed the style queue, so a late enqueue lands
			// in the footer and the hero paints unstyled first.
			sbr_badge_enqueue_styles();
			sbr_trust_badge_enqueue_styles();

			return;
		}

		$badge_id  = $this->edited_badge_id();
		$is_editor = $badge_id > 0;

		// The shared React app is not a blank canvas: Header, the icon helper and
		// the modals read a fixed set of keys off `sb_customizer` regardless of
		// which screen is mounted. Sending only our own data leaves iconsList and
		// assetsURL undefined, which throws inside printIcon() and 404s every
		// image — verified in the browser before these were added. This mirrors
		// what SBR_ReviewAlert_Builder::custom_builder_data() supplies.
		$data = array(
			'ajaxHandler'   => admin_url('admin-ajax.php'),
			'adminHomeURL'  => admin_url('admin.php'),
			'adminPostURL'  => admin_url('post.php'),
			'nonce'         => wp_create_nonce('sbr-admin'),
			'assetsURL'     => SB_COMMON_ASSETS,
			'iconsList'     => SB_Utils::get_icons(),
			'isPro'         => Util::sbr_is_pro(),
			'isProPlus'     => Util::sbr_is_pro_plus(),
			'providers'     => Util::get_providers(),
			'pluginStatus'  => ( new AuthorizationStatusCheck() )->get_statuses(),
			'apiKeys'       => get_option('sbr_apikeys', array()),
			'apiKeyLimits'  => get_option('sbr_apikeys_limit', array()),
			'feedData'      => array(),
			'feedsList'     => array(),
			'feedsCount'    => 0,
			'reactScreen'  => $is_editor ? self::SCREEN_EDITOR : self::SCREEN_LIST,
			'trustBadges'  => array(
				'listURL'   => admin_url('admin.php?page=' . self::MENU_SLUG),
				'editURL'   => admin_url('admin.php?page=' . self::MENU_SLUG . '&badge_id='),
				'defaults'  => SBR_Trust_Badge_Store::defaults(),
				// Read from the store's own constants — the same arrays
				// sanitize_settings() validates against. Restating them here
				// would mean hand-syncing across the PHP/React boundary, and on
				// drift React would offer a value the sanitiser rejects: the save
				// returns success and the badge silently reverts to a default.
				'styles'     => SBR_Trust_Badge_Store::STYLES,
				'themes'     => SBR_Trust_Badge_Store::THEMES,
				'logoStyles' => SBR_Trust_Badge_Store::LOGO_STYLES,
				'logoColors' => SBR_Trust_Badge_Store::LOGO_COLORS,
				'isProPlus' => Util::sbr_is_pro_plus(),
				// Which providers actually have lockup artwork, so the sidebar can
				// offer "Full logo" only where one would draw. Derived from the same
				// helper the renderer asks (logos.php:63 via
				// DisplayElements::get_provider_lockup) rather than listed here, or
				// the control and the badge drift the moment artwork is added.
				//
				// A collection is the case that made this necessary: it is a real
				// source row with provider `collection` (SBR_Sources.php:196) and no
				// artwork of any kind, so it never appears in this list.
				'lockupProviders' => self::lockup_providers(),
			),
			// Where a badge can be embedded. Same provider the feed builder uses,
			// so both modals list the same places in the same order.
			'embedTargets' => \SmashBalloon\Reviews\Common\SBR_Embed_Targets::get(),
			// function_exists: wp_is_block_theme() is WP 5.9, readme.txt declares
			// "Requires at least: 4.1". Unguarded this is a fatal on the main
			// admin screen for anyone below that, which is the same guard the
			// codebase already puts on parse_blocks() and
			// get_block_wrapper_attributes().
			'siteEditorURL' => ( function_exists('wp_is_block_theme') && wp_is_block_theme() )
				? admin_url('site-editor.php')
				: '',
			// The widget route, and the reason it has to be here: siteEditorURL is
			// empty on a classic theme, so without these the embed modal offered a
			// badge NO site-wide placement at all — the case CustomizerHeader.js
			// warns about at the widgetsPageURL prop. The modal hides the row
			// itself when the theme has no widget areas.
			'themeSupportsWidgets' => current_theme_supports('widgets'),
			'widgetsPageURL'       => admin_url('widgets.php'),
			// AddSourceModal reads this to decide whether a provider can be added
			// without an API key. Every other screen that can open that modal
			// localises it (Settings, About, Review Alerts, the feed builder) and
			// this one did not, so "Add another Source" threw
			// "Cannot read properties of undefined (reading 'includes')" the
			// moment a provider was picked — HomeScreen defaults the state to an
			// empty ARRAY, which survives the optional chain and then has no
			// `providers` key.
			'freeRetrieverData'    => Util::get_free_retriever_data(),
			// The rest of AddSourceModal's payload contract, and the reason it is
			// listed rather than added a key at a time: freeRetrieverData above was
			// the same omission found through the same crash, and a differential
			// against the feed builder's localised data showed three more keys the
			// modal reads and this screen did not ship. Connect with Facebook threw
			// "Cannot read properties of undefined (reading 'stateURL')".
			//
			// The return URL is this screen, not page=sbr, so the round trip comes
			// back where the author started.
			'connectFBUrls'        => sbr_get_fb_connection_urls(),
			'collectionsPageUrl'   => admin_url('admin.php?page=sbr-collections'),
			'emailVerificationURL' => EmailVerification::build_email_verification_url(
				admin_url('admin.php?page=sbr-trust-badges')
			),
			'sourcesList'  => SBR_Sources::get_sources_list(),
			'sourcesCount' => SBR_Sources::get_sources_count(),
		);

		$badge = $is_editor ? SBR_Trust_Badge_Store::get($badge_id) : null;

		if ($badge !== null) {
			$data['trustBadges']['current'] = $badge;
			// The email step needs three things the browser cannot work out on
			// its own: where the image lives, the markup a signature editor's
			// source view accepts, and the alt text that states the rating in
			// words. All three depend on the aggregate, which only the server has.
			// The URL is gated on the SNIPPET, not offered alongside it. `url()` is
			// pure string building, so it answers for every badge whether or not any
			// bytes exist behind it — and EmailEmbedView gates its only CTA on this
			// key. On a host with no Imagick that shipped an enabled "Copy badge for
			// your signature" over a URL that answers 503 forever, which is a broken
			// image in someone's email signature. snippet() already runs the real
			// render, so its verdict is reused rather than asking a weaker question.
			$snippet = SBR_Badge_Image::snippet($badge_id, $badge['settings']);

			$data['trustBadges']['emailSnippet'] = $snippet;
			$data['trustBadges']['imageURL']     = $snippet === '' ? '' : SBR_Badge_Image::url($badge_id);
			$data['trustBadges']['emailBlocked'] = self::email_blocked_reason($snippet);
			// The editor may borrow a feed for a badge with no sources yet; a
			// visitor may not. See feed_for_badge().
			$data['trustBadges']['previewHtml']  = self::render_saved($badge['settings'], true);
			$data['trustBadges']['unreported']   = self::unreported_sources($badge['settings']);
			$data['trustBadges']['siteURL']      = home_url('/');
		} else {
			// Either the list screen was asked for, or a stale ?badge_id= points
			// at a badge that no longer exists. Both land on the list — and both
			// need its DATA. Flipping only reactScreen left All Badges rendering
			// an empty state on a site that has badges.
			$data['reactScreen']         = self::SCREEN_LIST;
			$data['trustBadges']['list'] = self::with_instances(
				SBR_Trust_Badge_Store::list_badges(array( 'per_page' => SBR_Trust_Badge_Store::PER_PAGE ))
			);
			// The screen divides the total by this to get its page count. Sent, not
			// duplicated, so the two cannot drift apart again.
			$data['trustBadges']['perPage'] = SBR_Trust_Badge_Store::PER_PAGE;

			// The empty state shows a real badge, not a picture of one. Rendered here
			// because only the server has the aggregate; '' when no feed resolves, and
			// the screen then shows its heading and copy alone.
			if (empty($data['trustBadges']['list']['items'])) {
				$data['trustBadges']['specimenHtml'] = self::specimen_html();
			}
		}

		/**
		 * Filter the data handed to the Trust Badges screens.
		 *
		 * @since 2.11.0
		 *
		 * @param array $data Localised builder data.
		 */
		$data = apply_filters('sbr_trust_badge_builder_data', $data);

		// Enqueue the shared customizer bundle exactly the way Review Alerts does
		// (SBR_ReviewAlert_Builder::enqueue_admin_scripts): same handle, same deps,
		// same dev-server switch. Versioned by the FILE via Util::asset_version() —
		// SBRVER busts only on a release, which is why two QA builds of one version
		// served the same cached bundle for thirty days. Localising without enqueuing
		// first is a silent no-op — the handle has to exist.
		$js_file = SB_CUSTOMIZER_ASSETS . '/build/static/js/main.js';

		if (! SB_Utils::is_production()) {
			$js_file = 'http://localhost:3000/static/js/main.js';
		} else {
			wp_enqueue_style(
				'sb-customizer-style',
				SB_CUSTOMIZER_ASSETS . '/build/static/css/main.css',
				array(),
				Util::asset_version('vendor/smashballoon/customizer/sb-common/sb-customizer/build/static/css/main.css')
			);
		}

		wp_enqueue_script(
			'sb-customizer-app',
			$js_file,
			array( 'wp-i18n', 'jquery' ),
			Util::asset_version('vendor/smashballoon/customizer/sb-common/sb-customizer/build/static/js/main.js'),
			true
		);

		// Handle and global name are the ones Review Alerts already uses —
		// verified, not assumed: SBR_ReviewAlert_Builder.php:198-210 enqueues
		// 'sb-customizer-app' and localises it as 'sb_customizer'. The React side
		// reads `window.sb_customizer` (SbUtils.js:93) and aliases it locally as
		// `sbCustomizer` (HomeScreen.js:16) — one global, two names in the source.
		wp_localize_script('sb-customizer-app', 'sb_customizer', $data);

		// The editor previews badges with the same stylesheet the front end uses,
		// rather than an admin copy of it. That is the point: a preview styled by
		// its own CSS can flatter a badge the site then renders differently.
		// Pulls in the tokens and the star rules through its dependencies.
		sbr_trust_badge_enqueue_styles();

		wp_enqueue_media();
		wp_set_script_translations('sb-customizer-app', 'reviews-feed', SBR_PLUGIN_DIR . 'languages/');
	}

	/**
	 * Record or clear this badge's email embed.
	 *
	 * The embed modal calls this the moment it generates an image URL for the
	 * user, and again with remove=1 if they drop it. Recorded rather than
	 * detected: an email badge is a PNG in somebody's signature, so no scan of
	 * this site can ever see it. Without the record the row reads "used in 0
	 * places" for a badge that may be in every outgoing message, and the delete
	 * dialog then says it is safe to remove.
	 *
	 * @return void
	 */
	public function ajax_email_embed()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$id     = isset($_POST['id']) && is_scalar($_POST['id']) ? absint($_POST['id']) : 0;
		$remove = ! empty($_POST['remove']);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$done = $remove
			? SBR_Trust_Badge_Store::clear_email_embed($id)
			: SBR_Trust_Badge_Store::record_email_embed($id);

		if (! $done) {
			wp_send_json_error(array( 'message' => __('Could not update the email embed.', 'reviews-feed') ), 400);
		}

		wp_send_json_success(
			array(
				'id'        => $id,
				'instances' => SBR_Trust_Badge_Store::get_instances($id),
			)
		);
	}

	/**
	 * The badge's own sources that had no data to contribute.
	 *
	 * Named rather than counted, because "3 sources are not loading" does not
	 * tell an author which one to reconnect. Resolved through the sources table
	 * so the editor can print the name the author recognises instead of an
	 * account id.
	 *
	 * Free: the aggregate this reads was already computed for the preview and is
	 * cached on the source set, so this is a cache read rather than a second
	 * pass over the feeds.
	 *
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return array<int, array{id: string, name: string, provider: string, errored: bool, missing: bool}>
	 */
	private static function unreported_sources($settings)
	{
		global $wpdb;

		$settings = is_array($settings) ? $settings : array();

		// Mirrors render_args(): a pinned badge takes the single-feed path and has
		// no per-source reporting to report on.
		if (! empty($settings['feed_id'])) {
			return array();
		}

		$ids = isset($settings['sources']) && is_array($settings['sources']) ? $settings['sources'] : array();

		if ($ids === array()) {
			return array();
		}

		$aggregate = SBR_Badge_Renderer::get_aggregate_for_sources($ids);
		$missing   = isset($aggregate['unreported']) && is_array($aggregate['unreported'])
			? $aggregate['unreported']
			: array();

		if ($missing === array()) {
			return array();
		}

		$table        = $wpdb->prefix . SBR_SOURCES_TABLE;
		$placeholders = implode(',', array_fill(0, count($missing), '%s'));

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list; the values are bound below.
		$sql = "SELECT account_id, provider, name, error FROM {$table} WHERE account_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; admin screen, one badge.
		$rows = $wpdb->get_results($wpdb->prepare($sql, $missing), ARRAY_A);
		$by_id = array();

		foreach ((array) $rows as $row) {
			if (is_array($row) && isset($row['account_id'])) {
				$by_id[(string) $row['account_id']] = $row;
			}
		}

		$out = array();

		foreach ($missing as $id) {
			$id  = (string) $id;
			$row = isset($by_id[$id]) ? $by_id[$id] : array();

			$out[] = array(
				'id' => $id,
				// A source removed from the table still sits in the badge's
				// settings, so fall back to the id rather than an empty label.
				'name'     => isset($row['name']) && (string) $row['name'] !== ''
					? (string) $row['name']
					: $id,
				'provider' => isset($row['provider']) ? (string) $row['provider'] : '',
				// Separates "still fetching" from "the fetch failed", which are
				// different things for the author to do something about.
				'errored'  => isset($row['error']) && (string) $row['error'] !== '',
				// No row at all: disconnected or deleted, and the badge is
				// carrying an id that points at nothing.
				'missing'  => $row === array(),
			);
		}

		return $out;
	}

	/**
	 * Provider slugs that have lockup artwork.
	 *
	 * @return array<int, string>
	 */
	private static function lockup_providers()
	{
		$slugs = array();

		foreach ((array) Util::get_providers() as $provider) {
			$type = is_array($provider) && isset($provider['type']) ? (string) $provider['type'] : '';

			if ($type !== '' && DisplayElements::has_provider_lockup($type)) {
				$slugs[] = $type;
			}
		}

		return array_values(array_unique($slugs));
	}

	/**
	 * Attach placement data to each row of a list.
	 *
	 * Done HERE and not in the store's get(), because it is one LIKE scan of
	 * post_content per badge. The list screen wants it; a render does not, and
	 * paying for it on every read would put a table scan behind every badge on
	 * the front end.
	 *
	 * @param array{items: array<int, array<string, mixed>>, total: int} $list List payload.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	private static function with_instances($list)
	{
		if (empty($list['items']) || ! is_array($list['items'])) {
			return $list;
		}

		foreach ($list['items'] as $index => $badge) {
			// is_scalar as well as isset: the id comes back through a
			// `array<string, mixed>` row, and casting an array to int is a
			// warning plus a 1 — which would then look up badge #1's instances
			// and attribute them to this row.
			if (! is_array($badge) || ! isset($badge['id']) || ! is_scalar($badge['id'])) {
				continue;
			}

			$list['items'][ $index ]['instances'] = SBR_Trust_Badge_Store::get_instances((int) $badge['id']);

			// The provider slugs behind this badge's sources, so the row's
			// sub-line can NAME the platforms at one or two rather than counting
			// them. Stored `sources` are bare account_ids, so the list cannot
			// derive this on its own — and resolving it here, through the same
			// helper the renderer uses, is what stops the label and the badge
			// disagreeing about which platforms a badge speaks for.
			$settings = ( isset($badge['settings']) && is_array($badge['settings']) )
				? $badge['settings']
				: array();

			$sources = ( isset($settings['sources']) && is_array($settings['sources']) )
				? $settings['sources']
				: array();

			// Display NAMES, not slugs. The design sketches a slug->name map in
			// the list's own JS; sending the resolved label instead keeps the
			// only such map in Util::get_providers(), where the rest of the
			// plugin reads it — one place for "WordPress.org" not to become
			// "Wordpress". Unknown slugs pass through as themselves rather than
			// vanishing, so a new provider degrades to a raw slug in one cell
			// instead of an empty sub-line.
			$list['items'][ $index ]['providers'] = array_map(
				static function ($slug) {
					$label = SBR_Badge_Renderer::provider_label((string) $slug);

					return $label !== '' ? $label : (string) $slug;
				},
				SBR_Badge_Renderer::providers_for_sources($sources)
			);
		}

		return $list;
	}

	/**
	 * Shared guard for every AJAX route.
	 *
	 * Nonce first, then capability — the same order and the same pair Review
	 * Alerts uses (SBR_Review_Alert_Service.php:796-798), so one plugin does not
	 * end up with two different answers to "who may configure badges".
	 *
	 * @return void Dies with a JSON error when the caller is not allowed.
	 */
	private function guard()
	{
		check_ajax_referer('sbr-admin', 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(array( 'message' => __('You do not have permission to do this.', 'reviews-feed') ), 403);
		}

		// Note for the handlers: the nonce IS verified, on the first line above.
		// Because that happens in a helper rather than inline, WPCS cannot see it
		// and flags every $_POST read in the four routes, which is why each one
		// carries a phpcs:disable referring back here.

		// Tier is enforced HERE, not only by hiding the screen. `sbr-admin`
		// nonces are minted by every other admin page, so without this a Lite or
		// Pro Basic administrator could POST straight to these routes and create,
		// edit or delete badges on a walled tier — the UI gate in render_page()
		// and enqueue_assets() would never see the request. Review Alerts guards
		// its own save the same way (SBR_Review_Alert_Service.php:811-840).
		if (! $this->licensed) {
			wp_send_json_error(array( 'message' => __('Trust Badges require a valid Plus licence.', 'reviews-feed') ), 403);
		}
	}

	/**
	 * List badges.
	 *
	 * @return void
	 */
	public function ajax_list()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$page   = isset($_POST['page']) && is_numeric($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
		$search = isset($_POST['search']) && is_scalar($_POST['search'])
			? sanitize_text_field(wp_unslash((string) $_POST['search']))
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_send_json_success(
			self::with_instances(
				SBR_Trust_Badge_Store::list_badges(
					array(
						'page'     => $page,
						'search'   => $search,
						'per_page' => SBR_Trust_Badge_Store::PER_PAGE,
					)
				)
			)
		);
	}

	/**
	 * Create or update a badge.
	 *
	 * @return void
	 */
	public function ajax_save()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$id   = isset($_POST['id']) && is_scalar($_POST['id']) ? absint($_POST['id']) : 0;
		$name = isset($_POST['name']) && is_scalar($_POST['name'])
			? sanitize_text_field(wp_unslash((string) $_POST['name']))
			: '';

		// Settings arrive as a JSON string so nested values survive PHP's form
		// decoding intact. wp_unslash FIRST: WP slashes all of $_POST, and
		// json_decode on slashed JSON returns null.
		// Refuse rather than default. save() replaces the whole settings blob, so
		// treating a missing or malformed `settings` as an empty array would let
		// a rename-only save — or one truncated in transit — reset every choice
		// on an existing badge to its default. Review Alerts' own save answers
		// 400 in the same situation.
		$raw = isset($_POST['settings']) && is_scalar($_POST['settings'])
			? wp_unslash((string) $_POST['settings'])
			: '';

		if (! is_string($raw) || $raw === '') {
			wp_send_json_error(array( 'message' => __('Missing badge settings.', 'reviews-feed') ), 400);
		}

		$settings = json_decode($raw, true);

		if (! is_array($settings)) {
			wp_send_json_error(array( 'message' => __('Badge settings could not be read.', 'reviews-feed') ), 400);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$saved = SBR_Trust_Badge_Store::save(
			array(
				'id'       => $id,
				'name'     => $name,
				// The store allowlists every field again; this is not the only
				// line of defence.
				'settings' => $settings,
			)
		);

		if ($saved === null) {
			wp_send_json_error(array( 'message' => __('Could not save this badge.', 'reviews-feed') ), 400);
		}

		$badge = SBR_Trust_Badge_Store::get($saved);

		// A FRESH snippet, additively. Its declared width and height are measured off
		// the rendered PNG, so they move with the ELEMENTS — measured on badge 953,
		// turning logos off takes the width 307 -> 193 and stars off the height 90 -> 79.
		// The page-load copy would hand the author stale numbers to paste. Existing keys
		// are untouched.
		if (is_array($badge)) {
			$snippet = SBR_Badge_Image::snippet($saved, $badge['settings']);

			$badge['emailSnippet'] = $snippet;
			// And the URL, for the same reason one step further back: the page-load
			// payload only carries `imageURL` when an EXISTING badge was loaded, so a
			// badge created in this editor had none at all and the email step offered
			// an empty image. The URL is derived from the id, which a new badge only
			// gets here — and gated on the snippet for the reason given at the
			// page-load site.
			$badge['imageURL']     = $snippet === '' ? '' : SBR_Badge_Image::url($saved);
			$badge['emailBlocked'] = self::email_blocked_reason($snippet);
		}

		wp_send_json_success($badge);
	}

	/**
	 * Why the email route is unavailable, for the view to say out loud.
	 *
	 * Empty string means it IS available. A disabled CTA with no reason is the
	 * thing this exists to prevent — the person cannot tell a missing server
	 * library from a badge they configured wrong.
	 *
	 * @param string $snippet Result of SBR_Badge_Image::snippet().
	 *
	 * @return string '', 'imagick', or 'render'.
	 */
	protected static function email_blocked_reason($snippet)
	{
		if ($snippet !== '') {
			return '';
		}

		// Distinguished because the remedies differ: one is a hosting change the
		// site owner has to ask for, the other is ours to fix.
		return SBR_Badge_Image::can_rasterise() ? 'render' : 'imagick';
	}

	/**
	 * Create a draft page carrying this badge, and answer with its editor URL.
	 *
	 * A route and not a URL flag. The dead `sbr_create_page` query arg this replaces
	 * would have created a post from a GET with no nonce on it, which is a CSRF hole
	 * dressed as a convenience — a link in an email could have made pages on the
	 * visitor's site. Here the nonce and the tier are checked by guard() before
	 * anything is written.
	 *
	 * DRAFT, not published: the author asked for somewhere to put a badge, not for a
	 * new public page on their site. They publish it themselves in the editor that
	 * opens.
	 *
	 * @return void
	 */
	public function ajax_create_page()
	{
		$this->guard();

		// guard() covers our own capability; publishing a page is a different one, and
		// an editor role can hold ours without holding this.
		if (! current_user_can('publish_pages')) {
			wp_send_json_error(array( 'message' => __('You do not have permission to create pages.', 'reviews-feed') ), 403);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$badge_id = isset($_POST['id']) && is_numeric($_POST['id']) ? max(0, (int) $_POST['id']) : 0;
		$title    = isset($_POST['title']) && is_scalar($_POST['title'])
			? sanitize_text_field(wp_unslash((string) $_POST['title']))
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if (SBR_Trust_Badge_Store::get($badge_id) === null) {
			wp_send_json_error(array( 'message' => __('That badge no longer exists.', 'reviews-feed') ), 400);
		}

		if ($title === '') {
			$title = __('Reviews', 'reviews-feed');
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $title,
				// The block, with the badge already chosen — the point of the flow is
				// that the author does not have to find it again in the inserter.
				'post_content' => sprintf(
					'<!-- wp:sbr/trust-badge {"id":%d} /-->',
					$badge_id
				),
			),
			true
		);

		if (is_wp_error($page_id) || ! $page_id) {
			wp_send_json_error(array( 'message' => __('The page could not be created.', 'reviews-feed') ), 400);
		}

		// get_edit_post_link() is preferred — WPML appends the language to it, so the
		// page opens in the one the author is working in — but it answers null when a
		// filter or a post-type rule says the post is not editable. The client opens
		// whatever comes back, so it must never be empty.
		$edit_url = get_edit_post_link((int) $page_id, 'raw');

		wp_send_json_success(
			array(
				'id'      => (int) $page_id,
				'editURL' => ! empty($edit_url)
					? $edit_url
					: admin_url('post.php?post=' . (int) $page_id . '&action=edit'),
			)
		);
	}

	/**
	 * Render the badge the editor currently shows, from UNSAVED settings.
	 *
	 * The preview is rendered by PHP and injected, rather than reimplemented in
	 * React. A JS twin of the badge would be a second renderer to keep in step
	 * with the first — the half-star rule, the Booking 0-10 scale, the tier
	 * limits and the "render nothing rather than a false claim" guards all live
	 * in SBR_Badge_Renderer, and a preview that quietly disagreed with the
	 * published badge is the exact failure this feature cannot afford.
	 *
	 * The round trip costs a request per change; the aggregate behind it is
	 * cached, so what is recomputed is markup, not a provider fetch.
	 *
	 * @return void
	 */
	public function ajax_preview()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$raw = isset($_POST['settings']) && is_scalar($_POST['settings'])
			? wp_unslash((string) $_POST['settings'])
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$settings = json_decode($raw, true);

		if (! is_array($settings)) {
			wp_send_json_error(array( 'message' => __('Badge settings could not be read.', 'reviews-feed') ), 400);
		}

		// Through the store's own sanitiser, so the preview is bound by exactly
		// the rules a save would apply. Anything the store would reject cannot
		// preview as though it had been accepted.
		$settings = SBR_Trust_Badge_Store::sanitize_settings($settings);

		wp_send_json_success(
			array(
				// Editor preview — see feed_for_badge().
				'html'  => self::render_saved($settings, true),
				// Editor only. The badge itself now renders from the sources that
				// DO report, so this is the only place the author is told which
				// of their picks contributed nothing — a public visitor is not
				// shown the gap, and the markup does not carry it.
				'unreported' => self::unreported_sources($settings),
				// Echoed back so the editor can show what the store made of the
				// request — a rejected style silently snapping to `card` in the
				// preview and nowhere else would be baffling.
				'settings' => $settings,
			)
		);
	}

	/**
	 * Turn stored badge settings into rendered markup.
	 *
	 * The single place the two vocabularies meet: the store speaks style +
	 * theme + element toggles, the renderer speaks the argument array every
	 * rail already passes. Both the preview and the frontend shortcode go
	 * through here, so they cannot drift.
	 *
	 * @param array $settings              Sanitised badge settings.
	 * @param bool  $editor_preview Editor only. When the badge cannot report a
	 *                              rating yet, draw a placeholder instead of
	 *                              nothing — see SBR_Badge_Renderer::render().
	 *                              A page must leave this false.
	 *
	 * @return string Rendered HTML, empty when nothing can be rendered.
	 */
	public static function render_saved($settings, $editor_preview = false)
	{
		$settings = is_array($settings) ? $settings : array();

		// The parameter used to be $allow_newest_fallback, and it did something
		// quite different: a badge with no sources previewed the NEWEST FEED on the
		// site — 4.5 from 96,864 reviews on one install — while every public
		// surface for that same badge rendered nothing. The editor was the only
		// screen making a promise, and it was not the author's data.
		$args = self::render_args($settings, array(), false);

		if ($editor_preview) {
			$args['placeholder'] = true;
		}

		return SBR_Badge_Renderer::render($args);
	}

	/**
	 * Renderer arguments for a saved badge's settings.
	 *
	 * Its own method so the empty-state specimen renders through the same mapping as a
	 * real badge; two copies of it is how logo_style/logo_color diverged before.
	 *
	 * @param array $settings              Badge settings.
	 * @param array $source_map            Resolved source map.
	 * @param bool  $allow_newest_fallback Borrow the newest feed when nothing resolves.
	 *
	 * @return array<string, mixed>
	 */
	private static function render_args($settings, $source_map, $allow_newest_fallback = false)
	{
		return array(
			'shape'       => isset($settings['style']) ? (string) $settings['style'] : 'card',
			'theme'       => isset($settings['theme']) ? (string) $settings['theme'] : 'light',
			'feed'        => self::feed_for_badge($settings, $allow_newest_fallback),
			// One selected source scopes the rating to it; several means the
			// badge speaks for the combined feed, and scoping to the first
			// would attribute everyone's reviews to one platform.
			'source'      => SBR_Badge_Renderer::sole_source_id($settings),
			// A pinned badge keeps the single-feed path; every other badge is
			// answered from its own sources, with no feed in between.
			'sources'     => empty($settings['feed_id']) && isset($settings['sources']) && is_array($settings['sources'])
				? $settings['sources']
				: array(),
			// Kept for callers that still hand a map over. `feed` above stays as
			// the fallback when no source resolves.
			'source_map'  => $source_map,
			'rating'      => ! empty($settings['show_rating']),
			'stars'       => ! empty($settings['show_stars']),
			'count'       => ! empty($settings['show_count']),
			'logos'       => ! empty($settings['show_logos']),
			// The STORE's defaults, not a third copy. These two were written
			// out here as 'icon'/'brand' — the pair the store's own comment
			// calls a shipped bug, and the pair this change corrected to
			// 'full'/'auto' in two other places. Three hand-maintained
			// copies is how they diverged the first time.
			'logo_style'  => isset($settings['logo_style'])
				? (string) $settings['logo_style']
				: SBR_Trust_Badge_Store::defaults()['logo_style'],
			'logo_color'  => isset($settings['logo_color'])
				? (string) $settings['logo_color']
				: SBR_Trust_Badge_Store::defaults()['logo_color'],
			'accent_hue'  => isset($settings['accent']) && $settings['accent'] !== '' ? $settings['accent'] : -1,
			'tint_hue'    => isset($settings['tint_hue']) ? $settings['tint_hue'] : 250,
			// powered_by is the tier-enforced attribution; footer_text is the
			// author's own line. Below Pro Plus the renderer re-imposes the
			// first regardless of what is stored, so this only carries the second.
			'footer_text' => ! empty($settings['show_footer']) && isset($settings['footer_text'])
				? (string) $settings['footer_text']
				: '',
			'footer_url'  => isset($settings['footer_url']) ? (string) $settings['footer_url'] : '',
			// FALSE, always. A saved badge has no "show Powered by" control —
			// its Show/Hide list offers Footer, and that footer is the
			// editable line. The store nonetheless defaults powered_by to
			// true, a leftover from the placement shapes, and passing it
			// through meant every default badge counted as having OPTED IN:
			// the attribution strip appeared on a badge whose footer was
			// switched off and nobody had asked for one.
			//
			// The tier gate is unaffected — it does not read this. Below Pro
			// Plus, show_powered_by() forces the attribution whatever is
			// passed here, which is the whole point of it.
			'powered_by'  => false,
		);
	}

	/**
	 * Provider marks on the LOCKED screen's badge.
	 *
	 * Fixed, not this site's own. The locked screen is shown to someone who cannot
	 * use the feature yet, so there is nothing of theirs to be faithful to, and
	 * reading their sources is what made the hero disappear on a site that has none.
	 */
	const UPSELL_SPECIMEN_PROVIDERS = array(
		'aliexpress',
		'tripadvisor',
		'booking',
		'airbnb',
		'google',
	);

	/**
	 * The badge on the LOCKED Trust Badges screen.
	 *
	 * Same renderer as everywhere else, so it cannot drift from the design, but every
	 * input is a constant: this screen must look the same on every site, and on a site
	 * with no feed or no sources specimen_html() returned '' and the whole left column
	 * vanished — reported from QA against a licence-deactivated site.
	 *
	 * Deliberately NOT specimen_html(), which stays site-specific for the two surfaces
	 * a licensed customer sees: the All Badges empty state, and the customizer payload
	 * at `trustBadges.specimenHtml` that TrustBadgesEmptyState.js renders.
	 *
	 * @return string Rendered HTML.
	 */
	public static function upsell_specimen_html()
	{
		$settings = SBR_Trust_Badge_Store::defaults();
		// Explicit, so a change to the store's defaults cannot quietly restyle this
		// screen away from the design. Card is the shape that carries the value, the
		// stars, the count and the marks at once.
		$settings['style'] = 'card';
		$settings['theme'] = 'light';

		// SAMPLE NUMBERS, permanently. Admin-only and behind a locked screen, so this
		// is never a public claim (Rule 9).
		$aggregate = array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			'providers' => self::UPSELL_SPECIMEN_PROVIDERS,
		);

		return SBR_Badge_Renderer::render(
			self::render_args($settings, array(), true),
			$aggregate
		);
	}

	/**
	 * A card badge for the All Badges empty state.
	 *
	 * The design calls for a real badge rather than a screenshot, so the specimen goes
	 * through the same renderer the front end uses and cannot drift from it as the badge
	 * design changes. Card because it is the widest of the three shapes: it carries the
	 * value, the stars, the count and the provider marks at once.
	 *
	 * This one stays SITE-SPECIFIC. The locked screen has its own
	 * upsell_specimen_html(), because a site that cannot use badges yet has no
	 * sources worth being faithful to.
	 *
	 * @return string Rendered HTML, or '' when no feed resolves at all.
	 */
	public static function specimen_html()
	{
		$settings = SBR_Trust_Badge_Store::defaults();
		// Explicit, so a change to the store's defaults cannot quietly restyle this
		// screen away from the design.
		$settings['style'] = 'card';
		$settings['theme'] = 'light';

		$feed = self::feed_for_badge(array(), true);

		if ($feed < 1) {
			// No feed to speak for. The screen keeps its heading and copy.
			return '';
		}

		// The site's own numbers when they are already cached, a specimen otherwise —
		// same posture as the locked wall. cached_aggregate() never builds, so opening
		// All Badges cannot trigger a provider fetch or warm someone's cache.
		$cached = SBR_Badge_Renderer::cached_aggregate($feed);
		$live   = is_array($cached)
			&& SBR_Badge_Renderer::is_rated($cached['rating'], $cached['count']);

		// SAMPLE NUMBERS, deliberately and permanently: 4.6 / 12 stand in only when this
		// site has no cached aggregate. Admin-only, so never a public claim (Rule 9).
		$aggregate = $live ? $cached : array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'word'      => '',
			// The site's OWN platforms, so the marks on the specimen are the ones this
			// badge would actually carry. Card is the shape that shows them, which is
			// why the design picks it here.
			'providers' => self::specimen_providers(),
		);

		return SBR_Badge_Renderer::render(
			self::render_args($settings, array(), true),
			$aggregate
		);
	}

	/**
	 * Provider slugs for the specimen: this site's own, a few of them.
	 *
	 * @return array<string>
	 */
	private static function specimen_providers()
	{
		// get_sources_list() answers a BARE list, not the {items, total} shape the badge
		// list uses. Read as {items} it came back empty and the specimen lost its marks.
		$list  = SBR_Sources::get_sources_list();
		$items = isset($list['items']) && is_array($list['items']) ? $list['items'] : $list;
		$items = is_array($items) ? $items : array();
		$slugs = array();

		foreach ($items as $source) {
			$provider = is_array($source) && isset($source['provider']) ? (string) $source['provider'] : '';

			if ($provider !== '' && ! in_array($provider, $slugs, true)) {
				$slugs[] = $provider;
			}

			if (count($slugs) >= 5) {
				break;
			}
		}

		return $slugs;
	}

	/** Transient prefix for a resolved source map. Bump on shape change. */
	const SOURCE_MAP_CACHE_PREFIX = 'sbr_badge_map1_';

	/**
	 * Candidate rows the source-map query may scan.
	 *
	 * Above the feed cap on purpose: crowd-out costs a refusal, so the window is
	 * generous enough that a real install does not hit one, and still bounded on
	 * an unauthenticated endpoint.
	 */
	const SOURCE_MAP_ROW_LIMIT = 200;

	// Public because the image endpoint has to reach the SAME answer.
	/**
	 * A badge's source map, and whether it covers everything the author chose.
	 *
	 * feed_for_badge() answers with ONE feed from `reset($sources)`, so a badge
	 * listing Google, Yelp and Trustpilot published whichever feed carried the
	 * first id. Only the SET changes here; the rating maths does not.
	 *
	 * `complete` is false when a selected source resolves to no feed, or when the
	 * selection is past SOURCE_MAP_MAX_SOURCES. Callers must refuse on it rather
	 * than aggregate what did resolve — a rating over a subset, published as the
	 * badge's own, is the false claim the deleted-feed path already fails closed
	 * on. It is NOT the same as an empty map: no sources at all is complete, and
	 * sends the caller to the documented single-feed fallback.
	 *
	 * Same SQL shape as feed_for_badge: `sources` is a JSON array in a longtext
	 * column, so LIKE narrows and the decode confirms. One query for all of them,
	 * cached because the anonymous ?sb-badge= endpoint resolves a map on every hit
	 * to fingerprint its PNG.
	 *
	 * @param array $settings Sanitised badge settings.
	 *
	 * @return array{map: array<int, string[]>, complete: bool}
	 */
	public static function source_map_for_badge(array $settings): array
	{
		global $wpdb;

		// An explicit pin wins, as it does in feed_for_badge() — the map beats the
		// pin everywhere it is set, so a pinned badge must not build one.
		if (! empty($settings['feed_id'])) {
			return array( 'map' => array(), 'complete' => true );
		}

		$sources = isset($settings['sources']) && is_array($settings['sources'])
			? array_values(array_filter($settings['sources'], 'is_scalar'))
			: array();

		if (empty($sources)) {
			return array( 'map' => array(), 'complete' => true );
		}

		$wanted = array();

		foreach ($sources as $source) {
			$wanted[ (string) $source ] = true;
		}

		// Past the bound: incomplete by definition, and refused before a query
		// runs. `sources` is uncapped at save and each entry is an OR-clause on an
		// unauthenticated endpoint.
		if (count($wanted) > SBR_Badge_Renderer::SOURCE_MAP_MAX_SOURCES) {
			return array( 'map' => array(), 'complete' => false );
		}

		$ids = array_keys($wanted);
		sort($ids);

		$cache_key = self::SOURCE_MAP_CACHE_PREFIX . md5((string) wp_json_encode($ids));
		$cached    = get_transient($cache_key);

		if (is_array($cached) && isset($cached['map'], $cached['complete']) && is_array($cached['map'])) {
			return array( 'map' => $cached['map'], 'complete' => (bool) $cached['complete'] );
		}

		$table = $wpdb->prefix . SBR_FEEDS_TABLE;
		$like  = array();
		$args  = array();

		foreach (array_keys($wanted) as $id) {
			$like[] = 'settings LIKE %s';
			$args[] = '%' . $wpdb->esc_like('"' . $id . '"') . '%';
		}

		// disable/enable, not ignore: the query is four lines down, out of an
		// ignore's reach. $like holds N copies of the literal 'settings LIKE %s';
		// every value is a %s in $args, and the table is a prefixed constant.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// LIMIT bounds MATCHED ROWS, not the map: a popular source matching many
		// newer feeds can push the only feed carrying a rarer one out of the window.
		// The second pass below asks for those by name, so this window's size is not
		// what decides whether the map comes back complete.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, settings FROM {$table} WHERE " . implode(' OR ', $like)
					. ' ORDER BY id DESC LIMIT ' . (int) self::SOURCE_MAP_ROW_LIMIT,
				$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$map   = array();
		$found = array();

		self::claim_sources((array) $rows, $wanted, $map, $found);

		// Anything the window missed, asked for by name. Several candidates, not one:
		// LIKE matches a substring, so a numeric Woo/EDD id like 77 also matches a
		// feed carrying 177, and claim_sources() only confirms after decoding — a
		// single row could be spent on a feed that does not carry the source at all.
		// Same window feed_for_badge() uses for the same reason.
		foreach (array_keys($wanted) as $id) {
			if (isset($found[ $id ])) {
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefixed constant, not input.
					"SELECT id, settings FROM {$table} WHERE settings LIKE %s ORDER BY id DESC LIMIT 20",
					'%' . $wpdb->esc_like('"' . $id . '"') . '%'
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			self::claim_sources((array) $candidates, $wanted, $map, $found);
		}

		// Every wanted source claimed, or the map does not speak for the badge.
		// A source in no feed has no reviews to contribute, so aggregating the rest
		// would publish a smaller selection than the author made.
		$resolved = array(
			'map'      => $map,
			'complete' => count($found) === count($wanted),
		);

		// Short TTL: a source joins or leaves a feed through the editor, and this
		// only has to outlive a burst of page or image requests.
		set_transient($cache_key, $resolved, 5 * MINUTE_IN_SECONDS);

		return $resolved;
	}

	/**
	 * Assign each wanted source to the newest feed among these rows that has it.
	 *
	 * @param array                $rows   Feed rows, newest first.
	 * @param array<string, true>  $wanted Source ids the badge selected.
	 * @param array<int, string[]> $map    Feed id => claimed sources, built in place.
	 * @param array<string, true>  $found  Sources already claimed, updated in place.
	 *
	 * @return void
	 */
	protected static function claim_sources(array $rows, array $wanted, array &$map, array &$found)
	{
		foreach ($rows as $row) {
			$decoded = json_decode(isset($row['settings']) ? (string) $row['settings'] : '', true);

			if (! is_array($decoded) || ! isset($decoded['sources']) || ! is_array($decoded['sources'])) {
				continue;
			}

			$feed_id = (int) $row['id'];

			foreach ($decoded['sources'] as $source) {
				$candidate = is_array($source)
					? ($source['account_id'] ?? ($source['id'] ?? ''))
					: $source;

				if (! is_scalar($candidate)) {
					continue;
				}

				$candidate = (string) $candidate;

				// Claimed ONCE, by the NEWEST feed that has it — `ORDER BY id DESC`,
				// matching feed_for_badge() so the two resolvers agree. Twice would
				// count one business twice in a count-weighted mean.
				if (! isset($wanted[ $candidate ]) || isset($found[ $candidate ])) {
					continue;
				}

				$found[ $candidate ] = true;
				$map[ $feed_id ][]   = $candidate;
			}
		}
	}

	/**
	 * Which feed's numbers a badge reports.
	 *
	 * A saved badge picks SOURCES, but every rating path below get_aggregate()
	 * is keyed by FEED id — the transient, the WPML language suffix and the
	 * Pro/Lite Feed split all hang off it. So a feed carrying the chosen source
	 * has to be found, and it has to be one that actually carries it: scoping
	 * to a source a feed does not have makes get_aggregate() refuse outright
	 * (correctly — it will not put one business's rating under another's name),
	 * and the badge renders nothing at all. That was the first version of this
	 * method, and it produced a permanently empty preview.
	 *
	 * Prefiltered in SQL rather than by loading every feed and decoding its
	 * settings: `sources` is a JSON array inside a longtext column, so a LIKE
	 * on the quoted id narrows to candidates first and the decode only runs on
	 * those. The decode still happens — LIKE can match a substring of a longer
	 * id — so the match is confirmed against the parsed array.
	 *
	 * That source-keyed aggregate now exists — SBR_Badge_Renderer::
	 * get_aggregate_for_sources() — and rating paths go through it, so a badge
	 * no longer needs a feed to report a number. What is left here is the
	 * explicit `feed_id` pin: badges saved before sources existed, and the
	 * editor's newest-feed preview. See aggregate_for_badge() for the order.
	 *
	 * @param array $settings              Sanitised badge settings.
	 * @param bool  $allow_newest_fallback Whether a badge with NO sources may
	 *                                     borrow the most recent feed. Editor
	 *                                     preview only; a visitor-facing caller
	 *                                     must leave this false.
	 *
	 * @return int Feed id, 0 when no feed carries the source.
	 */
	// Untyped for the same reason get_aggregate() is: a public static whose
	// callers this repo does not own, where a narrowed signature turns a
	// tolerated bad argument into a fatal.
	public static function feed_for_badge($settings, $allow_newest_fallback = false)
	{
		global $wpdb;

		$settings = is_array($settings) ? $settings : array();

		if (! empty($settings['feed_id'])) {
			return absint($settings['feed_id']);
		}

		$table   = $wpdb->prefix . SBR_FEEDS_TABLE;
		$sources = isset($settings['sources']) && is_array($settings['sources'])
			? array_values(array_filter($settings['sources'], 'is_scalar'))
			: array();

		if (empty($sources)) {
			// EDITOR ONLY. A badge with no sources borrowing the most recent
			// feed is a helpful placeholder while an author is still choosing;
			// on a public surface it is a false claim — the shortcode, the block
			// and the unauthenticated ?sb-badge= image would all publish some
			// other feed's rating and platform logos as if they were this
			// badge's. `sources` defaults to empty and no save path requires
			// one, so that was reachable by simply creating a badge.
			//
			// Off by default: every caller that reaches a visitor gets 0, which
			// leaves get_aggregate() unresolved and the renderer's own
			// render-nothing guard does the rest.
			if (! $allow_newest_fallback) {
				return 0;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One id; the aggregate behind it is cached. Table name is a prefixed constant, which prepare() cannot parameterise.
			return (int) $wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1");
		}

		// reset(), not [0]: any one of the sources locates the feed, and unlike
		// the offset it is provably there after the empty() guard above.
		$wanted = (string) reset($sources);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared inline; the aggregate behind the result is cached.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a prefixed constant; the VALUE is a %s placeholder below.
				"SELECT id, settings FROM {$table} WHERE settings LIKE %s ORDER BY id DESC LIMIT 20",
				'%' . $wpdb->esc_like('"' . $wanted . '"') . '%'
			),
			ARRAY_A
		);

		foreach ((array) $candidates as $row) {
			$decoded = json_decode(isset($row['settings']) ? (string) $row['settings'] : '', true);

			if (! is_array($decoded) || ! isset($decoded['sources']) || ! is_array($decoded['sources'])) {
				continue;
			}

			foreach ($decoded['sources'] as $source) {
				$candidate = is_array($source)
					? ($source['account_id'] ?? ($source['id'] ?? ''))
					: $source;

				if (is_scalar($candidate) && (string) $candidate === $wanted) {
					return (int) $row['id'];
				}
			}
		}

		// The source exists but no feed uses it. Return 0 so the renderer's own
		// "cannot resolve" guard fires — rendering the newest feed's rating here
		// would put someone else's number under this badge.
		return 0;
	}

	/**
	 * The ids a delete request is asking for.
	 *
	 * Its own method so a test drives THIS, rather than a copy of it that stays
	 * green while the route drifts.
	 *
	 * Three shapes, in the order Review Alerts' own bulk route takes them
	 * (SBR_Review_Alert_Service::ajax_bulk_delete): a real array, a JSON string,
	 * then the comma-separated form. The last one is what actually arrives —
	 * the client posts through SbUtils.ajaxPost, which puts everything through
	 * FormData.append() and therefore stringifies an array. Testing is_array()
	 * on this side is what made every delete answer 400.
	 *
	 * is_scalar on EVERY element, not just the single-id path: absint() of an
	 * array is 1 on PHP 8, so `ids[0][]=9` or a JSON `[[9]]` would resolve to
	 * badge number one and report a successful delete.
	 *
	 * @param mixed $raw_ids $_POST['ids'], unslashed.
	 * @param mixed $raw_id  $_POST['id'], unslashed, for a single delete.
	 *
	 * @return array<int, int> Positive ids, deduplicated, in the order given.
	 */
	public static function parse_delete_ids($raw_ids, $raw_id = null)
	{
		$ids = array();

		if (is_array($raw_ids)) {
			$ids = $raw_ids;
		} elseif (is_string($raw_ids) && $raw_ids !== '') {
			$decoded = json_decode($raw_ids, true);

			// A LIST, not any decoded array. `{"a":1}` decodes to array('a' => 1),
			// whose scalar element sails past the element guard below and
			// resolves to badge number one. array_keys() === range() rather than
			// array_is_list(), which is PHP 8.1.
			$is_list = is_array($decoded)
				&& ( $decoded === array() || array_keys($decoded) === range(0, count($decoded) - 1) );

			$ids = $is_list ? $decoded : ( is_array($decoded) ? array() : explode(',', $raw_ids) );
		}

		// `id` stays accepted for a single delete, which is the shape the
		// duplicate and save routes already use.
		if (! $ids && $raw_id !== null && is_scalar($raw_id)) {
			$ids = array( $raw_id );
		}

		$ids = array_map(
			static function ($value) {
				return is_scalar($value) ? absint($value) : 0;
			},
			$ids
		);

		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * The success payload a delete answers with.
	 *
	 * `id` is first and is load-bearing: the customizer is versioned separately,
	 * so an installed bundle still reads it and no commit here can update it.
	 * Extracted alongside the parser so a test can assert that rather than
	 * trusting a comment about it.
	 *
	 * @param array<int, int>                                      $deleted Ids removed.
	 * @param array{items: array<int, mixed>, total: int}          $list    Refreshed list.
	 *
	 * @return array<string, mixed>
	 */
	public static function delete_response($deleted, $list)
	{
		return array(
			'id'           => (int) reset($deleted),
			'ids'          => $deleted,
			'deletedCount' => count($deleted),
			'badgesList'   => isset($list['items']) ? $list['items'] : array(),
			'badgesCount'  => isset($list['total']) ? (int) $list['total'] : 0,
		);
	}

	/**
	 * Delete a badge.
	 *
	 * @return void
	 */
	public function ajax_delete()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() checks the nonce; parse_delete_ids() sanitises.
		$ids = self::parse_delete_ids(
			isset($_POST['ids']) ? wp_unslash($_POST['ids']) : '',
			isset($_POST['id']) ? wp_unslash($_POST['id']) : null
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if (! $ids) {
			wp_send_json_error(array( 'message' => __('No badges selected.', 'reviews-feed') ), 400);
		}

		$deleted = array();

		foreach ($ids as $id) {
			if (SBR_Trust_Badge_Store::delete($id)) {
				$deleted[] = $id;
			}
		}

		if (! $deleted) {
			wp_send_json_error(array( 'message' => __('Could not delete this badge.', 'reviews-feed') ), 400);
		}

		// The refreshed list, as the alerts route returns — so the screen shows
		// the server's answer rather than its own guess at it. The list screen sends
		// `page` with every request, so deleting from page two answers with page two
		// rather than swapping the grid back to page one. `search` is still unsent;
		// absent, it reads as no filter.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$page   = isset($_POST['page']) && is_numeric($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
		$search = isset($_POST['search']) && is_scalar($_POST['search'])
			? sanitize_text_field(wp_unslash((string) $_POST['search']))
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$list = self::with_instances(
			SBR_Trust_Badge_Store::list_badges(
				array(
					'page'     => $page,
					'search'   => $search,
					'per_page' => SBR_Trust_Badge_Store::PER_PAGE,
				)
			)
		);

		wp_send_json_success(self::delete_response($deleted, $list));
	}

	/**
	 * Copy a badge.
	 *
	 * @return void
	 */
	public function ajax_duplicate()
	{
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see guard().
		$id  = isset($_POST['id']) && is_scalar($_POST['id']) ? absint($_POST['id']) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$new = SBR_Trust_Badge_Store::duplicate($id);

		if ($new === null) {
			wp_send_json_error(array( 'message' => __('Could not duplicate this badge.', 'reviews-feed') ), 400);
		}

		wp_send_json_success(SBR_Trust_Badge_Store::get($new));
	}
}
