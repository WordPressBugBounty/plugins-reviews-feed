<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Admin\SBR_Plugin_Insltaller;

/**
 * SBR-577 (QA 2.1) -- the plugin install / activate / deactivate AJAX handlers
 * must demand the real plugin-management capabilities, not only access to this
 * plugin's settings.
 *
 * Before the fix a delegated user (or a multisite sub-site admin, or any user on
 * a DISALLOW_FILE_MODS site) holding `manage_reviews_feed_options` could install
 * and activate arbitrary code from any URL. The "denied" cases model that user:
 * they hold the plugin capability and nothing else. Each denial also asserts the
 * handler stopped at the gate, so a later failure cannot mask a missing check.
 */
final class Sbr577PluginInstallerCapabilityTest extends TestCase
{
	private const ORG_PACKAGE = 'https://downloads.wordpress.org/plugin/vibe-ai.zip';

	protected function setUp(): void
	{
		parent::setUp();
		$_POST = [];
		$GLOBALS['sbr_test_nonce_ok']          = true;
		$GLOBALS['sbr_test_caps_checked']      = [];
		$GLOBALS['sbr_test_file_mod_contexts'] = [];
		$GLOBALS['sbr_test_file_mods_allowed'] = true;
		$GLOBALS['sbr_test_user_can']          = ['manage_reviews_feed_options'];

		if (!function_exists('set_current_screen')) {
			// First thing install_plugin() does after every gate has passed.
			eval('function set_current_screen($screen = "") { if (empty($GLOBALS["sbr_test_run_past_screen"])) { throw new \SBR_Test_Halt("past_gates", $screen); } }');
		}
	}

	protected function tearDown(): void
	{
		$_POST = [];
		unset(
			$GLOBALS['sbr_test_nonce_ok'],
			$GLOBALS['sbr_test_caps_checked'],
			$GLOBALS['sbr_test_file_mod_contexts'],
			$GLOBALS['sbr_test_file_mods_allowed'],
			$GLOBALS['sbr_test_user_can']
		);
		parent::tearDown();
	}

	private function run_handler(string $method): \SBR_Test_Halt
	{
		try {
			(new SBR_Plugin_Insltaller())->$method();
		} catch (\SBR_Test_Halt $halt) {
			return $halt;
		}
		$this->fail($method . '() returned without sending a response.');
	}

	public function test_install_is_denied_without_install_plugins(): void
	{
		$_POST['plugin'] = self::ORG_PACKAGE;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertIsString($halt->payload, 'the refusal must be a readable message');
		$this->assertStringContainsString('permission', $halt->payload);
		$this->assertContains('install_plugins', $GLOBALS['sbr_test_caps_checked']);
		$this->assertSame([], $GLOBALS['sbr_test_file_mod_contexts'], 'must stop at the capability gate');
	}

	public function test_install_is_denied_when_file_mods_are_disallowed(): void
	{
		$GLOBALS['sbr_test_user_can']          = ['manage_reviews_feed_options', 'install_plugins'];
		$GLOBALS['sbr_test_file_mods_allowed'] = false;
		$_POST['plugin']                       = self::ORG_PACKAGE;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertStringContainsString('disabled', $halt->payload);
		$this->assertSame(['sbr_install_plugin'], $GLOBALS['sbr_test_file_mod_contexts']);
	}

	/**
	 * @dataProvider foreign_package_urls
	 */
	public function test_install_refuses_packages_outside_wordpress_org(string $package): void
	{
		$GLOBALS['sbr_test_user_can'] = ['manage_reviews_feed_options', 'install_plugins'];
		$_POST['plugin']              = $package;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('wp_send_json_error', $halt->getMessage(), 'must not reach the installer');
		$this->assertStringContainsString('WordPress.org', $halt->payload);
	}

	/** @return array<string, array{0: string}> */
	public static function foreign_package_urls(): array
	{
		return [
			'attacker host'         => ['https://attacker.example/payload.zip'],
			'plain http'            => ['http://downloads.wordpress.org/plugin/vibe-ai.zip'],
			'lookalike host'        => ['https://downloads.wordpress.org.evil.example/plugin/x.zip'],
			'userinfo trick'        => ['https://downloads.wordpress.org@evil.example/plugin/x.zip'],
			'other wordpress.org path' => ['https://downloads.wordpress.org/theme/x.zip'],
			'bare slug'             => ['vibe-ai'],
		];
	}

	public function test_install_of_the_wpvibe_package_passes_every_gate_for_a_real_admin(): void
	{
		$GLOBALS['sbr_test_user_can'] = ['manage_reviews_feed_options', 'install_plugins'];
		$_POST['plugin']              = self::ORG_PACKAGE;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('past_gates', $halt->getMessage(), 'the AI Agents card install button must still work');
	}

	public function test_the_card_button_url_is_an_allowed_package(): void
	{
		$tab = file_get_contents(dirname(__DIR__, 2) . '/class/Common/Settings/Tabs/SBR_General_Tab.php');
		$this->assertStringContainsString('data-sbr-wpvibe-plugin="' . self::ORG_PACKAGE . '"', $tab);
		$this->assertSame(0, strpos(self::ORG_PACKAGE, SBR_Plugin_Insltaller::ALLOWED_PACKAGE_PREFIX));
	}

	public function test_activate_is_denied_without_activate_plugins(): void
	{
		$_POST['plugin'] = 'vibe-ai/vibe-ai.php';

		$halt = $this->run_handler('activate_plugin');

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertStringContainsString('permission', $halt->payload);
		$this->assertContains('activate_plugins', $GLOBALS['sbr_test_caps_checked']);
	}

	public function test_deactivate_is_denied_without_deactivate_plugins(): void
	{
		$_POST['plugin'] = 'vibe-ai/vibe-ai.php';

		$halt = $this->run_handler('deactivate_plugin');

		$this->assertSame('wp_send_json_error', $halt->getMessage());
		$this->assertStringContainsString('permission', $halt->payload);
		$this->assertContains('deactivate_plugins', $GLOBALS['sbr_test_caps_checked']);
	}

	/**
	 * Defines just enough of WordPress and of the upgrader to let install_plugin()
	 * run to the activation step. The fake upgrader classes are declared before the
	 * autoloader can load the real ones (which extend core admin classes), so the
	 * activation tests that call this run in a separate process.
	 */
	private function stub_install_pipeline(): void
	{
		eval('namespace SmashBalloon\\Reviews\\Common\\Helpers;
			class SBR_Install_Skin {}
			class PluginSilentUpgrader {
				public function __construct($skin = null) {}
				public function install($package) { return true; }
				public function plugin_info() { return "vibe-ai/vibe-ai.php"; }
			}');
		foreach (
			[
				'add_query_arg'                  => '$a = null, $u = ""',
				'request_filesystem_credentials' => '$u = "", $t = "", $e = false, $c = false, $x = null',
				'WP_Filesystem'                  => '$c = false',
				'remove_action'                  => '$h = "", $cb = null, $p = 10',
				'wp_cache_flush'                 => '',
				'get_plugins'                    => '',
				'is_plugin_active'               => '$p = ""',
			] as $fn => $args
		) {
			if (function_exists($fn)) {
				continue;
			}
			$body = [
				'add_query_arg'                  => 'return "admin.php";',
				'request_filesystem_credentials' => 'return array();',
				'WP_Filesystem'                  => 'return true;',
				'get_plugins'                    => 'return array();',
				'is_plugin_active'               => 'return false;',
			][$fn] ?? '';
			eval("function {$fn}({$args}) { {$body} }");
		}
		if (!function_exists('activate_plugin')) {
			eval('function activate_plugin($p) { $GLOBALS["sbr_test_activated"][] = $p; return null; }');
		}
		$GLOBALS['sbr_test_activated']       = [];
		$GLOBALS['sbr_test_run_past_screen'] = true;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_without_activate_plugins_installs_but_does_not_activate(): void
	{
		$this->stub_install_pipeline();
		$GLOBALS['sbr_test_user_can'] = ['manage_reviews_feed_options', 'install_plugins'];
		$_POST['plugin']              = self::ORG_PACKAGE;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('wp_send_json_success', $halt->getMessage());
		$this->assertSame('Plugin Installed.', $halt->payload['message']);
		$this->assertSame([], $GLOBALS['sbr_test_activated'], 'nothing may be activated without activate_plugins');
		$this->assertContains('activate_plugins', $GLOBALS['sbr_test_caps_checked']);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_install_with_both_caps_installs_and_activates(): void
	{
		$this->stub_install_pipeline();
		$GLOBALS['sbr_test_user_can'] = ['manage_reviews_feed_options', 'install_plugins', 'activate_plugins'];
		$_POST['plugin']              = self::ORG_PACKAGE;

		$halt = $this->run_handler('install_plugin');

		$this->assertSame('wp_send_json_success', $halt->getMessage());
		$this->assertSame('Plugin Installed & Activated.', $halt->payload['message']);
		$this->assertSame(['vibe-ai/vibe-ai.php'], $GLOBALS['sbr_test_activated']);
	}
}
