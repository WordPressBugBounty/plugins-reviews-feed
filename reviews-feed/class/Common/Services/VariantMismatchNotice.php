<?php

namespace SmashBalloon\Reviews\Common\Services;

use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Admin notice shown when the installed build's `SBR_PRODUCT_ID` differs from
 * the `item_id` the licence activation returns (SMASH-1402, from SMASH-1388).
 *
 * Builds bake `SBR_PRODUCT_ID` in at bundle time (1722768 Pro, 1722779 Plus,
 * 1722784 Elite). A Pro build on a Plus licence activates cleanly and quietly
 * runs at the lower tier; this notice tells the customer to swap the build.
 *
 * - Storage and the build comparison live in `VariantMismatchFlag`.
 * - Rendered on Dashboard, Plugins and Reviews Feed Settings only.
 * - Dismissed per user, keyed to build + licence + detected_at: a different
 *   running build or a new detection re-shows it.
 *
 * @since SMASH-1402
 */
class VariantMismatchNotice extends ServiceProvider
{
	public const DISMISS_META = 'sbr_variant_mismatch_notice_dismissed';
	public const DISMISS_ACTION = 'sbr_dismiss_variant_mismatch_notice';
	public const SCRIPT_HANDLE = 'sbr-variant-mismatch-notice';
	public const SCRIPT_PATH = 'public/js/variant-mismatch-notice.js';

	/**
	 * Submenu screen ids are `<sanitize_title(parent menu title)>_page_<slug>`
	 * (wp-admin/includes/plugin.php:1403), and our parent title is translated
	 * and carries a notification bubble — so only the suffix is stable.
	 */
	private const SETTINGS_SCREEN_SUFFIX = '_page_sbr-settings';

	/** @var array<int, string> */
	private const ALLOWED_CORE_SCREENS = ['dashboard', 'plugins'];

	public function register(): void
	{
		if (! function_exists('add_action')) {
			return;
		}
		add_action('admin_notices', [$this, 'maybe_render']);
		add_action('wp_ajax_' . self::DISMISS_ACTION, [$this, 'ajax_dismiss']);
		// Priority 11: runs after MenuService::remove_admin_notices (priority 10).
		add_action('in_admin_header', [$this, 'restore_on_settings_screen'], 11);
	}

	/**
	 * MenuService::remove_admin_notices() strips every `admin_notices` callback
	 * on `?page=sbr*`. AC#3 wants this notice on Settings, so re-add only this
	 * callback, only on that screen; the builder screens stay stripped.
	 */
	public function restore_on_settings_screen(): void
	{
		if (! self::is_settings_screen(self::current_screen_id())) {
			return;
		}
		// add_action() dedupes an identical callback, so a double add is a no-op.
		add_action('admin_notices', [$this, 'maybe_render']);
	}

	/**
	 * AC#3 allow-list: the dashboard, the plugins list and Reviews Feed Settings.
	 */
	public static function is_allowed_screen(string $screen_id): bool
	{
		return in_array($screen_id, self::ALLOWED_CORE_SCREENS, true) || self::is_settings_screen($screen_id);
	}

	private static function is_settings_screen(string $screen_id): bool
	{
		$suffix_length = strlen(self::SETTINGS_SCREEN_SUFFIX);

		return strlen($screen_id) > $suffix_length
			&& substr($screen_id, -$suffix_length) === self::SETTINGS_SCREEN_SUFFIX;
	}

	private static function current_screen_id(): string
	{
		if (! function_exists('get_current_screen')) {
			return '';
		}
		$screen = get_current_screen();

		return $screen instanceof \WP_Screen ? (string) $screen->id : '';
	}

	public function maybe_render(): void
	{
		// The licence screen's capability (MenuService.php:38-39), so a delegated role that can activate also sees this.
		if (! function_exists('sbr_current_user_can') || ! sbr_current_user_can('manage_reviews_feed_options')) {
			return;
		}

		if (! self::is_allowed_screen(self::current_screen_id())) {
			return;
		}

		$mismatch = VariantMismatchFlag::get_mismatch();
		if ($mismatch === null) {
			return;
		}

		$dismiss_key = VariantMismatchFlag::dismissal_key($mismatch);
		if (self::is_dismissed_by_current_user($dismiss_key)) {
			return;
		}

		$build_label = self::variant_label($mismatch['build']);
		$license_label = self::variant_label($mismatch['license']);
		$account_url = 'https://smashballoon.com/account/';
		$nonce = wp_create_nonce(self::DISMISS_ACTION);
		$ajax_url = admin_url('admin-ajax.php');
		?>
		<div class="notice notice-warning is-dismissible sbr-variant-mismatch-notice"
			data-sbr-dismiss-action="<?php echo esc_attr(self::DISMISS_ACTION); ?>"
			data-sbr-dismiss-nonce="<?php echo esc_attr($nonce); ?>"
			data-sbr-dismiss-key="<?php echo esc_attr($dismiss_key); ?>"
			data-sbr-dismiss-url="<?php echo esc_attr($ajax_url); ?>">
			<p>
				<strong><?php esc_html_e('Reviews Feed Pro', 'reviews-feed'); ?>:</strong>
				<?php
				echo esc_html(sprintf(
					/* translators: 1: installed variant name, 2: license variant name */
					__('You have the %1$s variant installed but your license is for %2$s. To unlock the features your license includes, download the matching plugin variant from your account dashboard and replace this build.', 'reviews-feed'),
					$build_label,
					$license_label
				));
				?>
			</p>
			<p>
				<a href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open account dashboard →', 'reviews-feed'); ?></a>
			</p>
		</div>
		<?php
		self::enqueue_dismiss_script();
	}

	/**
	 * The dismiss handler as a versioned asset rather than an inline <script>, so a strict
	 * CSP (`script-src 'self'`) allows it. Enqueued from admin_notices, so it lands in the footer.
	 */
	private static function enqueue_dismiss_script(): void
	{
		if (! defined('SBR_PLUGIN_URL') || ! function_exists('wp_enqueue_script')) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			SBR_PLUGIN_URL . self::SCRIPT_PATH,
			[],
			defined('SBRVER') ? SBRVER : false,
			true
		);
	}

	private static function is_dismissed_by_current_user(string $dismiss_key): bool
	{
		$user_id = get_current_user_id();
		if ($user_id <= 0) {
			return false;
		}
		$stored = get_user_meta($user_id, self::DISMISS_META, true);

		return is_string($stored) && $stored === $dismiss_key;
	}

	/** Plain text: the caller escapes it. */
	private static function variant_label(int $product_id): string
	{
		return VariantMismatchFlag::VARIANT_LABELS[$product_id] ?? sprintf(
			/* translators: %d: EDD product ID (numeric) */
			__('product ID %d', 'reviews-feed'),
			$product_id
		);
	}

	/**
	 * Per-user dismissal of the notice currently showing. The stored flag is
	 * left alone: it is site-wide and other admins still need it.
	 */
	public function ajax_dismiss(): void
	{
		check_ajax_referer(self::DISMISS_ACTION, 'nonce');

		if (! sbr_current_user_can('manage_reviews_feed_options')) {
			wp_send_json_error(null, 403);
		}

		// A `key[]=` request is refused as a mismatch, not cast (PHP 8 warns on array-to-string).
		$posted = isset($_POST['key']) && is_string($_POST['key'])
			? sanitize_text_field(wp_unslash($_POST['key']))
			: '';
		$mismatch = VariantMismatchFlag::get_mismatch();
		// Only the notice actually showing can be dismissed; a stale page must
		// not silence a newer event its user never saw.
		if ($mismatch === null || $posted !== VariantMismatchFlag::dismissal_key($mismatch)) {
			wp_send_json_error(null, 409);
		}

		update_user_meta(get_current_user_id(), self::DISMISS_META, $posted);

		wp_send_json_success();
	}
}
