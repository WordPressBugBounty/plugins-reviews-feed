<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;

/**
 * SBR-577 -- the Settings page save returns the stored MCP Write Access toggle
 * and a refreshed loaded marker, which the page merges into its model
 * (sb-customizer SMASH-2354). That makes off -> save -> on -> save work in one
 * tab, and shows OFF again after a stale ON was refused.
 */
final class Sbr577SettingsSaveResponseTest extends TestCase
{
	private const K = SettingsManagerService::AI_WRITE_KEY;
	private const L = SettingsManagerService::AI_WRITE_LOADED_KEY;

	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
	}

	protected function tearDown(): void
	{
		$_POST = [];
		unset($GLOBALS['sbr_test_nonce_ok'], $GLOBALS['sbr_test_user_can']);
		parent::tearDown();
	}

	/** Runs one Settings page save against a stored toggle and returns the response payload. */
	private function save(bool $stored, array $posted): array
	{
		global $wp_options_mock;
		$wp_options_mock = ['sbr_settings' => [self::K => $stored]];
		$_POST           = ['nonce' => 'n', 'action' => 'sbr_update_settings', 'settings' => addslashes(json_encode($posted + ['translations' => []]))];

		$ref = new \ReflectionClass(SettingsManagerService::class);
		$svc = $ref->newInstanceWithoutConstructor();
		try {
			$svc->ajax_update_settings();
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json_success', $halt->getMessage());
			return (array) $halt->payload;
		}
		$this->fail('no response sent');
	}

	public function test_a_real_flip_to_on_is_stored_and_returned_with_a_refreshed_marker(): void
	{
		$out = $this->save(false, [self::K => true, self::L => false]);

		$this->assertSame([self::K => true, self::L => true], $out['settings']);
	}

	public function test_off_save_returns_off_so_a_second_on_in_the_same_tab_is_judged_correctly(): void
	{
		$out = $this->save(true, [self::K => false, self::L => true]);
		$this->assertSame([self::K => false, self::L => false], $out['settings']);

		// The page merged that response, so its next post carries loaded=false.
		$again = $this->save(false, [self::K => true, self::L => $out['settings'][self::L]]);
		$this->assertTrue($again['settings'][self::K], 'the second ON must be stored');
	}

	public function test_a_refused_stale_on_reports_the_stored_off(): void
	{
		// Stored OFF elsewhere; this tab loaded ON and posts ON back untouched.
		$out = $this->save(false, [self::K => true, self::L => true]);

		$this->assertSame([self::K => false, self::L => false], $out['settings']);
	}
}
