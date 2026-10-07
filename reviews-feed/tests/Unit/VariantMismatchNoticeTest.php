<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\AuthorizationStatusCheck;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use SmashBalloon\Reviews\Common\Services\VariantMismatchFlag;
use SmashBalloon\Reviews\Common\Services\VariantMismatchNotice;
use SmashBalloon\Reviews\Pro\Integrations\LicenseManagerService;

/**
 * SMASH-1402 — build-vs-licence variant mismatch notice.
 *
 * Pins: only the licence side is stored (sbr_settings['variant_mismatch'] =
 * {license, detected_at}) and compared with the RUNNING build at render; the
 * three-screen allow-list; the per-user dismissal keyed to build + licence +
 * detected_at; the Settings-screen re-add past MenuService's strip; the
 * Settings save not overwriting the server-owned key; manual activation,
 * silent re-activation and deactivation all going through the one recorder;
 * and an unchanged activation response (Rule 6).
 */
class VariantMismatchNoticeTest extends TestCase
{
	private const OTHER_ID = 1722768;

	protected function setUp(): void
	{
		parent::setUp();
		$this->resetGlobals();
	}

	protected function tearDown(): void
	{
		$this->resetGlobals();
		parent::tearDown();
	}

	private function resetGlobals(): void
	{
		global $wp_options_mock;
		$wp_options_mock = [];
		$_POST = [];
		unset($GLOBALS['wp_filter_mock']['sbr_settings_pages_capability']);
		unset(
			$GLOBALS['sbr_test_screen_id'],
			$GLOBALS['sbr_test_user_id'],
			$GLOBALS['sbr_test_user_meta'],
			$GLOBALS['sbr_test_user_can'],
			$GLOBALS['sbr_test_nonce_ok'],
			$GLOBALS['sbr_test_nonce_actions_checked'],
			$GLOBALS['sbr_test_nonce_query_args_checked'],
			$GLOBALS['sbr_test_caps_checked'],
			$GLOBALS['sbr_test_actions'],
			$GLOBALS['sbr_test_translations'],
			$GLOBALS['sbr_test_enqueued_scripts']
		);
	}

	private function licenseId(): int
	{
		return SBR_PRODUCT_ID === self::OTHER_ID ? 1722784 : self::OTHER_ID;
	}

	/** @param array<string, mixed> $flag */
	private function seedFlag(array $flag): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = [
			'license_key' => 'KEEP',
			'license_status' => 'valid',
			VariantMismatchFlag::SETTINGS_KEY => $flag,
		];
	}

	/** @return array<string, mixed> */
	private function settings(): array
	{
		global $wp_options_mock;
		return $wp_options_mock['sbr_settings'] ?? [];
	}

	private function render(string $screen_id): string
	{
		$GLOBALS['sbr_test_screen_id'] = $screen_id;
		$GLOBALS['sbr_test_user_can'] = true;
		$GLOBALS['sbr_test_user_id'] = 1;
		ob_start();
		(new VariantMismatchNotice())->maybe_render();
		return (string) ob_get_clean();
	}

	private function seedRealMismatch(int $detected_at = 1700000000): void
	{
		$this->seedFlag(['license' => $this->licenseId(), 'detected_at' => $detected_at]);
	}

	private function currentKey(int $detected_at = 1700000000): string
	{
		return SBR_PRODUCT_ID . ':' . $this->licenseId() . ':' . $detected_at;
	}

	// ---- record_after_activate: set / clear in sbr_settings ----

	public function test_mismatch_stores_only_the_licence_side_and_keeps_other_keys(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['license_key' => 'KEEP'];

		VariantMismatchFlag::record_after_activate($this->licenseId());

		$flag = $this->settings()[VariantMismatchFlag::SETTINGS_KEY] ?? null;
		$this->assertIsArray($flag);
		$this->assertSame(['license', 'detected_at'], array_keys($flag));
		$this->assertSame($this->licenseId(), $flag['license']);
		$this->assertIsInt($flag['detected_at']);
		$this->assertGreaterThan(0, $flag['detected_at']);
		$this->assertSame('KEEP', $this->settings()['license_key']);
	}

	public function test_matching_activation_clears_the_flag_and_keeps_other_keys(): void
	{
		$this->seedRealMismatch();

		VariantMismatchFlag::record_after_activate(SBR_PRODUCT_ID);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
		$this->assertSame('KEEP', $this->settings()['license_key']);
	}

	public function test_identical_mismatch_keeps_detected_at_so_a_dismissal_holds(): void
	{
		$this->seedRealMismatch(1700000000);

		VariantMismatchFlag::record_after_activate($this->licenseId());

		$this->assertSame(1700000000, $this->settings()[VariantMismatchFlag::SETTINGS_KEY]['detected_at']);
	}

	public function test_different_licence_is_a_new_event(): void
	{
		$this->seedFlag(['license' => 1722779, 'detected_at' => 1700000000]);

		VariantMismatchFlag::record_after_activate($this->licenseId());

		$flag = $this->settings()[VariantMismatchFlag::SETTINGS_KEY];
		$this->assertSame($this->licenseId(), $flag['license']);
		$this->assertNotSame(1700000000, $flag['detected_at']);
	}

	/** @return array<string, array{0: int|string}> */
	public static function bundleItemIds(): array
	{
		return ['All Access Bundle' => [1369549], 'as a numeric string' => ['1369549']];
	}

	/**
	 * @dataProvider bundleItemIds
	 * @param int|string $item_id
	 */
	public function test_a_bundle_licence_creates_no_flag($item_id): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['license_key' => 'KEEP', 'license_status' => 'valid'];

		VariantMismatchFlag::record_after_activate($item_id);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
		$this->assertNull(VariantMismatchFlag::get_mismatch());
		$this->assertSame('', $this->render('plugins'));
	}

	/**
	 * @dataProvider bundleItemIds
	 * @param int|string $item_id
	 */
	public function test_a_bundle_licence_clears_an_earlier_variant_flag($item_id): void
	{
		$this->seedRealMismatch();

		VariantMismatchFlag::record_after_activate($item_id);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
		$this->assertSame('KEEP', $this->settings()['license_key']);
	}

	/** @return array<string, array{0: mixed}> */
	public static function unconfirmableItemIds(): array
	{
		return ['null' => [null], 'non-numeric' => ['not-a-number'], 'zero' => [0], 'false' => [false]];
	}

	/**
	 * @dataProvider unconfirmableItemIds
	 * @param mixed $item_id
	 */
	public function test_unconfirmable_item_id_leaves_an_existing_flag_alone($item_id): void
	{
		$this->seedRealMismatch();
		$before = $this->settings();

		VariantMismatchFlag::record_after_activate($item_id);

		$this->assertSame($before, $this->settings());
	}

	/**
	 * @dataProvider unconfirmableItemIds
	 * @param mixed $item_id
	 */
	public function test_unconfirmable_item_id_creates_no_flag($item_id): void
	{
		VariantMismatchFlag::record_after_activate($item_id);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
	}

	// ---- the three-screen gate ----

	/** @return array<string, array{0: string}> */
	public static function allowedScreens(): array
	{
		return [
			'dashboard' => ['dashboard'],
			'plugins' => ['plugins'],
			'rf settings' => ['reviews-feed_page_sbr-settings'],
			// Parent title is translated and carries a bubble (plugin.php:1403).
			'rf settings, bubble prefix' => ['reviews-feed-1_page_sbr-settings'],
		];
	}

	/** @return array<string, array{0: string}> */
	public static function disallowedScreens(): array
	{
		return [
			'feed builder' => ['toplevel_page_sbr'],
			'collections' => ['reviews-feed_page_sbr-collections'],
			'review alerts' => ['reviews-feed_page_sbr-review-alerts'],
			'trust badges' => ['reviews-feed_page_sbr-trust-badges'],
			'about' => ['reviews-feed_page_sbr-about'],
			'support' => ['reviews-feed_page_sbr-support'],
			'general settings' => ['options-general'],
			'posts list' => ['edit-post'],
			'network dashboard' => ['dashboard-network'],
			'bare suffix' => ['_page_sbr-settings'],
			'suffix lookalike' => ['reviews-feed_page_sbr-settings-extra'],
			'no screen' => [''],
		];
	}

	/** @dataProvider allowedScreens */
	public function test_notice_renders_on_allowed_screen(string $screen_id): void
	{
		$this->seedRealMismatch();

		$this->assertTrue(VariantMismatchNotice::is_allowed_screen($screen_id));
		$this->assertStringContainsString('sbr-variant-mismatch-notice', $this->render($screen_id));
	}

	/** @dataProvider disallowedScreens */
	public function test_notice_does_not_render_on_any_other_screen(string $screen_id): void
	{
		$this->seedRealMismatch();

		$this->assertFalse(VariantMismatchNotice::is_allowed_screen($screen_id));
		$this->assertSame('', $this->render($screen_id));
	}

	public function test_nothing_renders_without_a_flag(): void
	{
		$this->assertSame('', $this->render('plugins'));
	}

	public function test_installing_the_licensed_build_hides_it_without_a_re_activation(): void
	{
		// Recorded on another build; the running build now matches the licence.
		$this->seedFlag(['license' => SBR_PRODUCT_ID, 'detected_at' => 1700000000]);

		$this->assertNull(VariantMismatchFlag::get_mismatch());
		$this->assertSame('', $this->render('plugins'));
	}

	public function test_swapping_to_another_wrong_build_still_shows_it_for_the_running_build(): void
	{
		// The licence is Plus; whatever build runs now is not Plus.
		$this->seedFlag(['license' => 1722779, 'detected_at' => 1700000000]);

		$mismatch = VariantMismatchFlag::get_mismatch();
		$this->assertNotNull($mismatch);
		$this->assertSame(SBR_PRODUCT_ID, $mismatch['build']);
		$this->assertStringContainsString('product ID ' . SBR_PRODUCT_ID . ' variant installed', $this->render('plugins'));
	}

	public function test_a_stored_bundle_flag_renders_nothing(): void
	{
		$this->seedFlag(['license' => 1369549, 'detected_at' => 1700000000]);

		$this->assertNull(VariantMismatchFlag::get_mismatch());
		$this->assertSame('', $this->render('plugins'));
	}

	public function test_notice_follows_the_settings_pages_capability_filter(): void
	{
		$this->seedRealMismatch();
		$GLOBALS['wp_filter_mock']['sbr_settings_pages_capability'] = 'sbr_delegated_cap';

		$this->assertStringContainsString('notice', $this->render('plugins'));
		// The filtered capability is the one that decides, as on the licence screen.
		$this->assertSame('sbr_delegated_cap', end($GLOBALS['sbr_test_caps_checked']));
		$this->assertNotContains('manage_options', $GLOBALS['sbr_test_caps_checked']);
	}

	public function test_dismiss_handler_ships_as_a_footer_asset_not_inline(): void
	{
		$this->seedRealMismatch();

		$html = $this->render('plugins');

		$this->assertStringContainsString('sbr-variant-mismatch-notice', $html);
		$this->assertStringNotContainsString('<script', $html);
		$script = $GLOBALS['sbr_test_enqueued_scripts'][VariantMismatchNotice::SCRIPT_HANDLE] ?? null;
		$this->assertIsArray($script);
		$this->assertSame(SBR_PLUGIN_URL . 'public/js/variant-mismatch-notice.js', $script['src']);
		$this->assertSame(SBRVER, $script['ver']);
		$this->assertTrue($script['in_footer']);
		$this->assertFileExists(SBR_PLUGIN_DIR . VariantMismatchNotice::SCRIPT_PATH);
	}

	public function test_dismiss_handler_is_not_enqueued_when_the_notice_does_not_render(): void
	{
		$this->assertSame('', $this->render('plugins'));
		$this->assertArrayNotHasKey(VariantMismatchNotice::SCRIPT_HANDLE, $GLOBALS['sbr_test_enqueued_scripts'] ?? []);
	}

	public function test_dismiss_asset_posts_the_notice_data_attributes(): void
	{
		$js = (string) file_get_contents(SBR_PLUGIN_DIR . VariantMismatchNotice::SCRIPT_PATH);
		foreach (['action', 'nonce', 'key', 'url'] as $attr) {
			$this->assertStringContainsString("getAttribute('data-sbr-dismiss-{$attr}')", $js);
		}
		$this->assertStringContainsString("closest('.notice-dismiss')", $js);
	}

	/** @return array<string, array{0: string}> */
	public static function nonValidLicenceStatuses(): array
	{
		return ['cleared' => [''], 'expired' => ['expired'], 'invalid' => ['invalid'], 'disabled' => ['disabled']];
	}

	/** @dataProvider nonValidLicenceStatuses */
	public function test_nothing_renders_once_the_licence_is_no_longer_valid(string $status): void
	{
		// e.g. ReconcileMigratedLicenseRoutine clears license_status without
		// touching this key; the flag must not outlive the licence it names.
		global $wp_options_mock;
		$this->seedRealMismatch();
		$wp_options_mock['sbr_settings']['license_status'] = $status;

		$this->assertNull(VariantMismatchFlag::get_mismatch());
		$this->assertSame('', $this->render('plugins'));
	}

	public function test_a_legacy_flag_without_a_licence_renders_nothing(): void
	{
		$this->seedFlag(['build' => 1722768, 'detected_at' => 1700000000]);

		$this->assertSame('', $this->render('plugins'));
	}

	public function test_nothing_renders_for_a_non_admin(): void
	{
		$this->seedRealMismatch();
		$GLOBALS['sbr_test_screen_id'] = 'plugins';
		$GLOBALS['sbr_test_user_can'] = false;

		ob_start();
		(new VariantMismatchNotice())->maybe_render();
		$this->assertSame('', (string) ob_get_clean());
	}

	public function test_rendered_notice_names_both_variants_and_carries_the_dismissal_key(): void
	{
		$this->seedFlag(['license' => 1722779, 'detected_at' => 1700000123]);

		$html = $this->render('dashboard');

		// Bootstrap's SBR_PRODUCT_ID is not a known variant, so it falls back to its id.
		$this->assertStringContainsString('product ID ' . SBR_PRODUCT_ID . ' variant installed', $html);
		$this->assertStringContainsString('license is for Reviews Feed Pro Plus', $html);
		$this->assertStringContainsString('data-sbr-dismiss-key="' . SBR_PRODUCT_ID . ':1722779:1700000123"', $html);
	}

	public function test_translated_fallback_label_is_escaped_once(): void
	{
		// variant_label() returns plain text; the caller's esc_html() is the only escape.
		$GLOBALS['sbr_test_translations']['product ID %d'] = "l'ID produit %d";
		$this->seedFlag(['license' => 1722779, 'detected_at' => 1700000123]);

		$html = $this->render('dashboard');

		$this->assertStringContainsString('l&#039;ID produit ' . SBR_PRODUCT_ID, $html);
		$this->assertStringNotContainsString('&amp;#039;', $html);
	}

	// ---- dismissal keyed to build + licence + detected_at ----

	public function test_dismissal_of_this_notice_hides_it(): void
	{
		$this->seedRealMismatch(1700000000);
		$GLOBALS['sbr_test_user_meta'][1][VariantMismatchNotice::DISMISS_META] = $this->currentKey(1700000000);

		$this->assertSame('', $this->render('plugins'));
	}

	public function test_dismissal_of_an_older_detection_does_not_hide_a_new_one(): void
	{
		$this->seedRealMismatch(1700000999);
		$GLOBALS['sbr_test_user_meta'][1][VariantMismatchNotice::DISMISS_META] = $this->currentKey(1700000000);

		$this->assertStringContainsString('sbr-variant-mismatch-notice', $this->render('plugins'));
	}

	public function test_dismissal_does_not_survive_a_swap_to_another_wrong_build(): void
	{
		// Dismissed while build 1722768 ran; the same licence event, a different build now.
		$this->seedRealMismatch(1700000000);
		$GLOBALS['sbr_test_user_meta'][1][VariantMismatchNotice::DISMISS_META] = '1722768:' . $this->licenseId() . ':1700000000';

		$this->assertStringContainsString('sbr-variant-mismatch-notice', $this->render('plugins'));
	}

	public function test_dismissal_key_changes_with_each_part(): void
	{
		// The running build is a constant in the suite, so the build-swap case
		// is pinned on the key itself.
		$base = ['build' => 1722768, 'license' => 1722779, 'detected_at' => 1700000000];
		$key = VariantMismatchFlag::dismissal_key($base);

		$this->assertNotSame($key, VariantMismatchFlag::dismissal_key(['build' => 1722784] + $base));
		$this->assertNotSame($key, VariantMismatchFlag::dismissal_key(['license' => 1722768] + $base));
		$this->assertNotSame($key, VariantMismatchFlag::dismissal_key(['detected_at' => 1700000001] + $base));
	}

	/** @param array<string, mixed> $post */
	private function dismiss(array $post, bool $nonce_ok = true, bool $user_can = true): \SBR_Test_Halt
	{
		$GLOBALS['sbr_test_nonce_ok'] = $nonce_ok;
		$GLOBALS['sbr_test_user_can'] = $user_can;
		$GLOBALS['sbr_test_user_id'] = 7;
		$_POST = $post;

		try {
			(new VariantMismatchNotice())->ajax_dismiss();
		} catch (\SBR_Test_Halt $halt) {
			return $halt;
		}
		$this->fail('ajax_dismiss must end with a JSON response');
	}

	public function test_ajax_dismiss_stores_the_key_and_keeps_the_site_wide_flag(): void
	{
		$this->seedRealMismatch(1700000000);

		$halt = $this->dismiss(['key' => $this->currentKey(1700000000)]);

		$this->assertSame('wp_send_json_success', $halt->getMessage());
		$this->assertSame($this->currentKey(1700000000), $GLOBALS['sbr_test_user_meta'][7][VariantMismatchNotice::DISMISS_META]);
		$this->assertSame(1700000000, $this->settings()[VariantMismatchFlag::SETTINGS_KEY]['detected_at']);
	}

	public function test_ajax_dismiss_refuses_a_key_for_a_notice_that_is_not_showing(): void
	{
		$this->seedRealMismatch(1700000999);

		$halt = $this->dismiss(['key' => $this->currentKey(1700000000)]);

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertSame(409, $halt->status);
		$this->assertArrayNotHasKey(7, $GLOBALS['sbr_test_user_meta'] ?? []);
	}

	public function test_ajax_dismiss_with_an_array_key_refuses_without_a_php_warning(): void
	{
		$this->seedRealMismatch(1700000000);
		$errors = [];
		set_error_handler(static function (int $errno, string $errstr) use (&$errors): bool {
			$errors[] = $errstr;
			return true;
		});

		try {
			$halt = $this->dismiss(['key' => [$this->currentKey(1700000000)]]);
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $errors);
		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertSame(409, $halt->status);
		$this->assertArrayNotHasKey(7, $GLOBALS['sbr_test_user_meta'] ?? []);
	}

	public function test_ajax_dismiss_with_a_bad_nonce_dies_before_storing_anything(): void
	{
		$this->seedRealMismatch(1700000000);

		$halt = $this->dismiss(['key' => $this->currentKey(1700000000)], false);

		$this->assertSame('wp_die', $halt->getMessage());
		$this->assertSame(403, $halt->status);
		$this->assertSame([VariantMismatchNotice::DISMISS_ACTION], $GLOBALS['sbr_test_nonce_actions_checked']);
		$this->assertSame(['nonce'], $GLOBALS['sbr_test_nonce_query_args_checked']);
		$this->assertArrayNotHasKey(7, $GLOBALS['sbr_test_user_meta'] ?? []);
	}

	public function test_ajax_dismiss_refuses_a_non_admin_with_403(): void
	{
		$this->seedRealMismatch(1700000000);

		$halt = $this->dismiss(['key' => $this->currentKey(1700000000)], true, false);

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertSame(403, $halt->status);
		// sbr_current_user_can(): the plugin cap first, then its manage_options fallback.
		$this->assertSame(['manage_reviews_feed_options', 'manage_options'], $GLOBALS['sbr_test_caps_checked']);
		$this->assertArrayNotHasKey(7, $GLOBALS['sbr_test_user_meta'] ?? []);
	}

	// ---- Settings screen: re-added past MenuService's strip ----

	/** @return array<int, mixed> */
	private function adminNoticeCallbacks(): array
	{
		return array_column($GLOBALS['sbr_test_actions']['admin_notices'] ?? [], 'callback');
	}

	public function test_settings_screen_re_adds_the_notice_after_the_strip(): void
	{
		$notice = new VariantMismatchNotice();
		$GLOBALS['sbr_test_screen_id'] = 'reviews-feed_page_sbr-settings';

		$notice->restore_on_settings_screen();

		$this->assertContains([$notice, 'maybe_render'], $this->adminNoticeCallbacks());
	}

	/** @dataProvider disallowedScreens */
	public function test_other_screens_are_not_re_added(string $screen_id): void
	{
		$notice = new VariantMismatchNotice();
		$GLOBALS['sbr_test_screen_id'] = $screen_id;

		$notice->restore_on_settings_screen();

		$this->assertSame([], $this->adminNoticeCallbacks());
	}

	public function test_register_hooks_the_restore_after_menu_service_priority(): void
	{
		(new VariantMismatchNotice())->register();

		$hooks = $GLOBALS['sbr_test_actions']['in_admin_header'] ?? [];
		$this->assertCount(1, $hooks);
		// MenuService::register adds remove_admin_notices at the default 10.
		$this->assertGreaterThan(10, $hooks[0]['priority']);
	}

	// ---- Settings save must not write the server-owned key ----

	public function test_settings_save_cannot_overwrite_the_server_owned_flag(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['license_key' => 'KEEP'];
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		// A Settings page loaded before a matching activation still holds the old flag.
		$_POST['settings'] = json_encode([
			'translations' => [],
			VariantMismatchFlag::SETTINGS_KEY => ['build' => 1, 'license' => 2, 'detected_at' => 3],
		]);

		try {
			(new SettingsManagerService())->ajax_update_settings();
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json_success', $halt->getMessage());
		}

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
	}

	// ---- Rule 6: the activation response is unchanged ----

	/** @param array<string, mixed> $response */
	private function activate(array $response): \SBR_Test_Halt
	{
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST['license_key'] = 'LICENSE-KEY';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call', 'refresh_access_token_from_settings'])
			->getMock();
		$relay->method('call')->willReturn($response);
		$status = $this->getMockBuilder(AuthorizationStatusCheck::class)
			->disableOriginalConstructor()
			->onlyMethods(['update_status'])
			->getMock();

		try {
			(new LicenseManagerService($relay, new SettingsManagerService(), $status))->ajax_activate_license();
		} catch (\SBR_Test_Halt $halt) {
			return $halt;
		}
		$this->fail('ajax_activate_license must end with a JSON response');
	}

	/** @return array<string, mixed> */
	private function validResponse(int $item_id): array
	{
		return ['success' => true, 'data' => [
			'license' => 'valid',
			'api_data' => ['license' => 'valid', 'item_id' => $item_id, 'price_id' => 2],
		]];
	}

	public function test_activation_response_shape_is_unchanged_on_a_mismatch(): void
	{
		$response = $this->validResponse($this->licenseId());

		$halt = $this->activate($response);

		$this->assertSame('wp_send_json', $halt->getMessage());
		$payload = $halt->payload;
		$this->assertIsArray($payload);
		$this->assertSame(
			['success', 'data', 'license_status', 'license_info', 'license_key', 'pluginStatus'],
			array_keys($payload)
		);
		$this->assertSame($response['data'], $payload['data']);
		$this->assertSame('valid', $payload['license_status']);
		$this->assertSame($response['data']['api_data'], $payload['license_info']);
		$this->assertSame('LICENSE-KEY', $payload['license_key']);
		$this->assertSame($this->licenseId(), $this->settings()[VariantMismatchFlag::SETTINGS_KEY]['license']);
	}

	public function test_matching_activation_clears_a_stored_flag_end_to_end(): void
	{
		$this->seedRealMismatch();

		$this->activate($this->validResponse(SBR_PRODUCT_ID));

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
	}

	public function test_deactivation_clears_the_flag(): void
	{
		$this->seedRealMismatch();
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST['license_key'] = 'LICENSE-KEY';

		$relay = $this->getMockBuilder(SBRelay::class)
			->disableOriginalConstructor()
			->onlyMethods(['call', 'refresh_access_token_from_settings'])
			->getMock();
		$relay->method('call')->willReturn(['success' => true, 'data' => ['license' => 'deactivated']]);
		$status = $this->getMockBuilder(AuthorizationStatusCheck::class)
			->disableOriginalConstructor()
			->onlyMethods(['update_status'])
			->getMock();

		try {
			(new LicenseManagerService($relay, new SettingsManagerService(), $status))->ajax_deactivate_license();
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json', $halt->getMessage());
		}

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
		$this->assertSame('KEEP', $this->settings()['license_key']);
	}

	private function persistSilentActivation(int $item_id): void
	{
		$relay = (new \ReflectionClass(SBRelay::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(SBRelay::class, 'persist_license_state');
		$method->setAccessible(true);
		$method->invoke($relay, ['license' => 'valid', 'api_data' => ['license' => 'valid', 'item_id' => $item_id]]);
	}

	public function test_silent_re_activation_records_a_mismatch(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['license_key' => 'KEEP'];

		$this->persistSilentActivation($this->licenseId());

		$this->assertSame($this->licenseId(), $this->settings()[VariantMismatchFlag::SETTINGS_KEY]['license']);
		$this->assertSame('valid', $this->settings()['license_status']);
	}

	public function test_silent_re_activation_on_the_licensed_build_clears_the_flag(): void
	{
		$this->seedRealMismatch();

		$this->persistSilentActivation(SBR_PRODUCT_ID);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
	}

	public function test_unsuccessful_activation_does_not_touch_the_flag(): void
	{
		$response = ['success' => true, 'data' => [
			'license' => 'invalid',
			'api_data' => ['license' => 'invalid', 'item_id' => $this->licenseId()],
		]];

		$this->activate($response);

		$this->assertArrayNotHasKey(VariantMismatchFlag::SETTINGS_KEY, $this->settings());
	}
}
