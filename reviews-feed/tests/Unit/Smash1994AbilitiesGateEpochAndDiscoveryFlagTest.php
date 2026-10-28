<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Abilities\SBR_Abilities;
use SmashBalloon\Reviews\Common\Abilities\SBR_State_Token;
use SmashBalloon\Reviews\Common\Abilities\SBR_Write_Gate;

/**
 * SBR-499 round 2 (mirrors the approved Instagram Feed Pro design):
 *
 * 1. GATE EPOCH. Disabling MCP Write Access (sbr_settings.ai_write_enabled on -> off,
 *    or deleting sbr_settings) records an epoch; any state token created at or
 *    before it is refused, so re-enabling cannot revive a link.
 * 2. DISCOVERY FLAG LIFECYCLE. The "hide writes from discovery" flag is armed in
 *    begin_discovery() (rest_request_before_callbacks) and always cleared by
 *    end_discovery() (rest_request_after_callbacks).
 * 3. The gate hooks are registered even when the Abilities API is unavailable.
 * 4. Uninstall removes the epoch option, after the settings option.
 *
 * Each test runs in its own process: it defines WordPress stubs that must not leak.
 */
class Smash1994AbilitiesGateEpochAndDiscoveryFlagTest extends TestCase
{
	private static function stubs(): void
	{
		if (!function_exists('get_current_user_id')) {
			eval('function get_current_user_id() { return 1; }');
		}
		if (!function_exists('untrailingslashit')) {
			eval('function untrailingslashit($s) { return rtrim($s, "/"); }');
		}
		if (!function_exists('is_admin')) {
			eval('function is_admin() { return false; }');
		}
		if (!function_exists('wp_get_ability')) {
			eval('function wp_get_ability($n) { return new \Sbr_Stub_Ability($n); }');
		}
		if (!class_exists('Sbr_Stub_Ability')) {
			eval('class Sbr_Stub_Ability {
				private $n;
				public function __construct($n) { $this->n = $n; }
				public function get_name() { return $this->n; }
				public function get_meta() { return array("annotations" => array("readonly" => strpos($this->n, "write") === false)); }
			}');
		}
		if (!class_exists('WP_REST_Request')) {
			eval('class WP_REST_Request {
				private $m; private $r;
				public function __construct($m, $r) { $this->m = $m; $this->r = $r; }
				public function get_method() { return $this->m; }
				public function get_route() { return $this->r; }
			}');
		}
		$GLOBALS['wp_options_mock']      = array();
		$GLOBALS['wp_transients_mock']   = array();
		$GLOBALS['wp_filter_mock']       = array();
		$GLOBALS['sbr_test_actions']     = array();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disable_records_epoch_and_kills_pre_revocation_links(): void
	{
		self::stubs();
		$on  = array('ai_write_enabled' => true);
		$off = array('ai_write_enabled' => false);

		$token   = SBR_State_Token::create('delete_feed_2');
		$created = $GLOBALS['wp_transients_mock']['sb_abilities_sbr_state_' . hash('sha256', $token)]['created'];
		$this->assertTrue(SBR_State_Token::peek($token, 'delete_feed_2'), 'valid when no epoch exists');

		SBR_Write_Gate::on_settings_updated($off, $on);
		$this->assertSame(0.0, SBR_Write_Gate::disabled_since(), 'off -> on records no epoch');
		SBR_Write_Gate::on_settings_updated($on, array('other' => 1) + $on);
		$this->assertSame(0.0, SBR_Write_Gate::disabled_since(), 'unrelated save records no epoch');

		$set = function ($v) {
			update_option(SBR_Write_Gate::EPOCH_OPTION, sprintf('%.6F', $v));
		};
		$set($created - 0.5);
		$this->assertTrue(SBR_State_Token::peek($token, 'delete_feed_2'), 'created AFTER the epoch is valid');
		$set($created);
		$this->assertFalse(SBR_State_Token::peek($token, 'delete_feed_2'), 'created AT the epoch is refused');
		$set($created + 0.5);
		$this->assertFalse(SBR_State_Token::peek($token, 'delete_feed_2'), 'created BEFORE the epoch is refused');

		unset($GLOBALS['wp_options_mock'][SBR_Write_Gate::EPOCH_OPTION]);
		SBR_Write_Gate::on_settings_updated($on, $off);
		$this->assertGreaterThan(0, SBR_Write_Gate::disabled_since(), 'on -> off records an epoch');
		$this->assertFalse(SBR_State_Token::peek($token, 'delete_feed_2'), 'pre-disable link stays dead after re-enabling');
		$this->assertFalse(SBR_State_Token::consume($token, 'delete_feed_2'), 'consume() honours the epoch');

		usleep(2000);
		$later = SBR_State_Token::create('delete_feed_2');
		$this->assertTrue(SBR_State_Token::peek($later, 'delete_feed_2'), 'post-disable link is valid');
		unset($GLOBALS['wp_transients_mock']['sb_abilities_sbr_state_' . hash('sha256', $later)]['created']);
		$this->assertFalse(SBR_State_Token::peek($later, 'delete_feed_2'), 'token without `created` never validates');

		// Deleting the settings resets the toggle to off, so it counts as a disable too.
		unset($GLOBALS['wp_options_mock'][SBR_Write_Gate::EPOCH_OPTION]);
		SBR_Write_Gate::record_disable();
		$this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', get_option(SBR_Write_Gate::EPOCH_OPTION));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_gate_hooks_register_even_when_abilities_api_is_unavailable(): void
	{
		self::stubs();
		$this->assertFalse(SBR_Abilities::is_available(), 'precondition: no Abilities API in the unit env');
		(new SBR_Abilities())->register();
		$this->assertNotEmpty($GLOBALS['sbr_test_actions']['update_option_sbr_settings'] ?? array());
		$this->assertNotEmpty($GLOBALS['sbr_test_actions']['delete_option_sbr_settings'] ?? array());
		$this->assertSame('sbr_settings', SBR_Write_Gate::SETTINGS_OPTION);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_discovery_flag_is_armed_before_callbacks_and_always_cleared_after(): void
	{
		self::stubs();
		$abilities = new SBR_Abilities();
		$write     = new \Sbr_Stub_Ability('reviews-feed/write-thing');
		$read      = new \Sbr_Stub_Ability('reviews-feed/read-thing');
		$disc      = new \WP_REST_Request('GET', '/wp-abilities/v1/abilities');
		$off       = array('ai_write_enabled' => false);
		$on        = array('ai_write_enabled' => true);
		$GLOBALS['wp_options_mock']['sbr_settings'] = $off;

		$this->assertTrue($abilities->filter_discovery_item(true, $write), 'before any dispatch writes are not hidden');
		$abilities->begin_discovery(null, array(), $disc);
		$this->assertFalse($abilities->filter_discovery_item(true, $write), 'inside discovery our write ability is hidden');
		$this->assertTrue($abilities->filter_discovery_item(true, $read), 'our read ability stays');
		$this->assertTrue($abilities->filter_discovery_item(true, new \Sbr_Stub_Ability('other/write-thing')), 'foreign ability untouched');
		$this->assertSame('r', $abilities->end_discovery('r'), 'end_discovery returns the response untouched');
		$this->assertTrue($abilities->filter_discovery_item(true, $write), 'flag cleared after after_callbacks');

		$abilities->begin_discovery(null, array(), new \WP_REST_Request('HEAD', '/wp-abilities/v1/abilities/'));
		$this->assertFalse($abilities->filter_discovery_item(true, $write), 'HEAD with trailing slash is treated like GET');
		$abilities->end_discovery(null);

		$abilities->begin_discovery(null, array(), new \WP_REST_Request('GET', '/wp-abilities/v1/other'));
		$this->assertTrue($abilities->filter_discovery_item(true, $write), 'unrelated route never arms the flag');
		$abilities->end_discovery(null);

		$GLOBALS['wp_options_mock']['sbr_settings'] = $on;
		$abilities->begin_discovery(null, array(), $disc);
		$this->assertTrue($abilities->filter_discovery_item(true, $write), 'gate on: flag not armed');
		$abilities->end_discovery(null);

		$GLOBALS['wp_options_mock']['sbr_settings']                  = $off;
		$GLOBALS['wp_filter_mock']['sbr_abilities_allow_write']      = true;
		$abilities->begin_discovery(null, array(), $disc);
		$this->assertTrue($abilities->filter_discovery_item(true, $write), 'filter override forcing the gate on: not armed');
		$abilities->end_discovery(null);
	}

	public function test_source_wiring_and_uninstall_ordering(): void
	{
		$root = __DIR__ . '/../../';
		$src  = file_get_contents($root . 'class/Common/Abilities/SBR_Abilities.php');
		$this->assertStringNotContainsString("'rest_pre_dispatch'", $src);
		$this->assertStringContainsString("add_filter('rest_request_before_callbacks', array(\$this, 'begin_discovery')", $src);
		$this->assertStringContainsString("add_filter('rest_request_after_callbacks', array(\$this, 'end_discovery')", $src);
		$this->assertLessThan(
			strpos($src, 'if (!self::is_available())'),
			strpos($src, 'SBR_Write_Gate::register_hooks()'),
			'gate hooks register before the is_available() early return'
		);

		$un = file_get_contents($root . 'uninstall.php');
		$this->assertStringContainsString("delete_option('sb_abilities_sbr_gate_epoch')", $un);
		$this->assertGreaterThan(
			strrpos($un, "'sbr_settings',"),
			strpos($un, "delete_option('sb_abilities_sbr_gate_epoch')"),
			'epoch is deleted after the settings option'
		);
		$this->assertSame('sb_abilities_sbr_gate_epoch', SBR_Write_Gate::EPOCH_OPTION);
	}
}
