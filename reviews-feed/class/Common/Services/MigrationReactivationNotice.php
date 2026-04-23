<?php

namespace SmashBalloon\Reviews\Common\Services;

use Smashballoon\Stubs\Services\ServiceProvider;

/**
 * Admin notice surfaced after a successful silent license re-activation on
 * a migrated site (SMASH-1281). Companion to
 * `SBRelay::attempt_silent_reactivation()`.
 *
 * **Why the notice matters.**
 * The silent path re-registers + re-activates the license on the NEW site
 * URL, but EDD has no "transfer" operation — the OLD site's activation
 * slot stays consumed until the user explicitly frees it. If they did a
 * real one-way move (prod domain rename, backup restore) they almost
 * certainly want to free that slot. The notice points them at the
 * SmashBalloon account page where they can do so.
 *
 * **Lifecycle.**
 *   - Set: `SBRelay::attempt_silent_reactivation()` writes a transient
 *     `sbr_silent_reactivation_notice` on successful re-activation.
 *   - Read: this service renders on `admin_notices` if the transient
 *     is present.
 *   - Dismiss: a per-user option captures dismissal so it doesn't
 *     reappear after a cache flush wipes the transient.
 *   - Auto-expire: transient carries a `WEEK_IN_SECONDS` TTL as a
 *     safety net if the user never sees / dismisses the notice.
 *
 * @see SBRelay::attempt_silent_reactivation()
 * @since SMASH-1281
 */
class MigrationReactivationNotice extends ServiceProvider
{
	public const TRANSIENT_KEY = 'sbr_silent_reactivation_notice';
	public const DISMISS_META  = 'sbr_silent_reactivation_notice_dismissed';
	public const DISMISS_ACTION = 'sbr_dismiss_silent_reactivation_notice';

	public function register()
	{
		if (!function_exists('add_action')) {
			return;
		}
		add_action('admin_notices', [$this, 'maybe_render']);
		add_action('wp_ajax_' . self::DISMISS_ACTION, [$this, 'ajax_dismiss']);
	}

	/**
	 * Render the notice if (a) transient is set, (b) current user hasn't
	 * already dismissed it, (c) current user has the capability to care.
	 *
	 * Restricts to administrators to avoid surfacing the account-page
	 * pointer to editors/authors who couldn't act on it anyway.
	 *
	 * @return void
	 */
	public function maybe_render()
	{
		if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
			return;
		}

		$payload = function_exists('get_transient') ? get_transient(self::TRANSIENT_KEY) : false;
		if (!is_array($payload)) {
			return;
		}

		if (function_exists('get_user_meta') && function_exists('get_current_user_id')) {
			$user_id = get_current_user_id();
			if ($user_id > 0) {
				$dismissed_ts = (int) get_user_meta($user_id, self::DISMISS_META, true);
				// If the user dismissed this specific reactivation event
				// (timestamps match), stay silent. A fresh migration + fresh
				// reactivation writes a new timestamp and re-surfaces the
				// notice — that's desired behavior.
				if ($dismissed_ts > 0 && isset($payload['timestamp']) && (int) $payload['timestamp'] === $dismissed_ts) {
					return;
				}
			}
		}

		$new_url = isset($payload['new_url']) && is_string($payload['new_url']) ? $payload['new_url'] : '';
		$old_url = isset($payload['old_url']) && is_string($payload['old_url']) ? $payload['old_url'] : '';
		$account_url = 'https://smashballoon.com/account/';
		$nonce = function_exists('wp_create_nonce') ? wp_create_nonce(self::DISMISS_ACTION) : '';
		$ajax_url = function_exists('admin_url') ? admin_url('admin-ajax.php') : '';
		$timestamp = isset($payload['timestamp']) ? (int) $payload['timestamp'] : 0;

		// `esc_*` might not be loaded in unusual bootstrap contexts (CLI,
		// test harness). Defensive wrappers — skip rendering if they don't
		// exist, rather than echoing raw data.
		if (
			!function_exists('esc_url')
			|| !function_exists('esc_html')
			|| !function_exists('esc_attr')
			|| !function_exists('esc_html__')
			|| !function_exists('esc_html_e')
		) {
			return;
		}

		$detail_line = '';
		if ($old_url !== '' && $new_url !== '') {
			$detail_line = sprintf(
				/* translators: 1: previous site URL, 2: current site URL */
				esc_html__('Detected change from %1$s to %2$s.', 'reviews-feed'),
				'<code>' . esc_html($old_url) . '</code>',
				'<code>' . esc_html($new_url) . '</code>'
			);
		}

		?>
		<div class="notice notice-success is-dismissible sbr-silent-reactivation-notice"
			data-sbr-dismiss-action="<?php echo esc_attr(self::DISMISS_ACTION); ?>"
			data-sbr-dismiss-nonce="<?php echo esc_attr($nonce); ?>"
			data-sbr-dismiss-ts="<?php echo esc_attr((string) $timestamp); ?>"
			data-sbr-dismiss-url="<?php echo esc_attr($ajax_url); ?>">
			<p>
				<strong><?php esc_html_e('Reviews Feed Pro', 'reviews-feed'); ?>:</strong>
				<?php esc_html_e('Your site URL changed and we automatically reactivated your license on the new URL.', 'reviews-feed'); ?>
				<?php if ($detail_line !== '') : ?>
					<br><?php echo $detail_line; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above ?>
				<?php endif; ?>
			</p>
			<p>
				<?php esc_html_e('Your old site is still using one of your license activation slots. If you have fully migrated and no longer need it, you can free that slot from your SmashBalloon account.', 'reviews-feed'); ?>
				<a href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Manage activations →', 'reviews-feed'); ?></a>
			</p>
		</div>
		<script>
		(function () {
			var notice = document.querySelector('.sbr-silent-reactivation-notice');
			if (!notice) { return; }
			notice.addEventListener('click', function (e) {
				var btn = e.target && e.target.closest('.notice-dismiss');
				if (!btn) { return; }
				var action = notice.getAttribute('data-sbr-dismiss-action');
				var nonce  = notice.getAttribute('data-sbr-dismiss-nonce');
				var ts     = notice.getAttribute('data-sbr-dismiss-ts');
				var url    = notice.getAttribute('data-sbr-dismiss-url');
				if (!action || !nonce || !url) { return; }
				var data = new FormData();
				data.append('action', action);
				data.append('nonce', nonce);
				data.append('timestamp', ts);
				// Fire and forget — a failed dismissal just means the notice
				// re-appears on the next admin page load, which is the
				// pre-existing fallback for users on unusual caching setups.
				if (window.fetch) {
					window.fetch(url, { method: 'POST', credentials: 'same-origin', body: data });
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Persist per-user dismissal so a fresh transient load or server cache
	 * flush doesn't re-surface the same notice event. A subsequent fresh
	 * migration + fresh silent-reactivation writes a new timestamp and
	 * re-surfaces the notice — intended.
	 *
	 * @return void
	 */
	public function ajax_dismiss()
	{
		if (
			!function_exists('check_ajax_referer')
			|| !function_exists('current_user_can')
			|| !function_exists('get_current_user_id')
			|| !function_exists('update_user_meta')
			|| !function_exists('wp_send_json_success')
			|| !function_exists('wp_send_json_error')
		) {
			return;
		}
		check_ajax_referer(self::DISMISS_ACTION, 'nonce');

		if (!current_user_can('manage_options')) {
			// wp_send_json_error exits internally — no explicit return needed.
			wp_send_json_error(null, 403);
		}

		$timestamp = isset($_POST['timestamp']) ? (int) $_POST['timestamp'] : 0;
		if ($timestamp <= 0) {
			wp_send_json_error(null, 400);
		}

		$user_id = get_current_user_id();
		if ($user_id > 0) {
			update_user_meta($user_id, self::DISMISS_META, $timestamp);
		}

		// Opportunistically clear the transient too — other admins on the
		// same site don't need to see the same notice again.
		if (function_exists('delete_transient')) {
			delete_transient(self::TRANSIENT_KEY);
		}

		wp_send_json_success();
	}
}
