<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Services\SettingsManagerService;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Create_Feed;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_Set_Feed_Sources;
use SmashBalloon\Reviews\Pro\Abilities\SBR_Set_Moderation;
use SmashBalloon\Reviews\Common\Abilities\SBR_State_Token;
use SmashBalloon\Reviews\Common\Abilities\Services\SBR_Feed_Settings_Service;

/**
 * SBR-499 review follow-ups (PR #536, Gabriel Nejoiu):
 *
 * - state token consume() is decided by the delete, and create_exclusive() reads its slot back;
 * - can_write() checks the capability BEFORE the gate (no gate leak to non-admins);
 * - duplicate source ids are refused by schema and collapsed by the resolver;
 * - a feed whose stored settings JSON is corrupt is never overwritten;
 * - the Settings page only changes the MCP Write Access toggle when the user flipped it.
 *
 * Each test runs in its own process: they flip globals and define stubs.
 */
class Smash1994AbilitiesReviewFollowupsTest extends TestCase
{
	private static function stubs(): void
	{
		if (!function_exists('get_current_user_id')) {
			eval('function get_current_user_id() { return 7; }');
		}
		if (!class_exists('WP_Error')) {
			eval('class WP_Error {
				public $code; public $message; public $data;
				public function __construct($code = "", $message = "", $data = "") { $this->code = $code; $this->message = $message; $this->data = $data; }
				public function get_error_code() { return $this->code; }
				public function get_error_data() { return $this->data; }
			}');
		}
		$GLOBALS['wp_options_mock']    = array();
		$GLOBALS['wp_transients_mock'] = array();
		$GLOBALS['wp_filter_mock']     = array();
	}

	private static function storage_key(string $token): string
	{
		return SBR_State_Token::PREFIX . hash('sha256', $token);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_consume_is_decided_by_the_delete(): void
	{
		self::stubs();
		$token = SBR_State_Token::create('delete_feed_2');
		$this->assertTrue(SBR_State_Token::peek($token, 'delete_feed_2'));

		// A concurrent request passes peek() too, but its delete finds the row gone.
		$GLOBALS['sbr_test_delete_transient_loses'] = true;
		$this->assertFalse(SBR_State_Token::consume($token, 'delete_feed_2'), 'the request whose delete removed nothing must be refused');
		$GLOBALS['sbr_test_delete_transient_loses'] = false;

		$token = SBR_State_Token::create('delete_feed_2');
		$this->assertTrue(SBR_State_Token::consume($token, 'delete_feed_2'), 'the request that really deleted the row proceeds');
		$this->assertFalse(SBR_State_Token::consume($token, 'delete_feed_2'), 'and it only ever runs once');
		$this->assertTrue(SBR_State_Token::peek(SBR_State_Token::create('x'), 'x'), 'peek() does not consume');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_exclusive_loser_kills_its_own_token_and_keeps_the_winner(): void
	{
		self::stubs();
		$winner_key = self::storage_key('winner-token');
		$GLOBALS['wp_transients_mock'][$winner_key] = array('action' => 'delete_feed_3', 'user' => 7, 'created' => microtime(true));

		// A concurrent mint overwrites the slot right after ours is written.
		$GLOBALS['sbr_test_after_set_transient'] = static function ($key) use ($winner_key) {
			if (strpos($key, 'slot_') !== false) {
				$GLOBALS['wp_transients_mock'][$key] = $winner_key;
				$GLOBALS['sbr_test_after_set_transient'] = null;
			}
		};

		$result = SBR_State_Token::create_exclusive('delete_feed_2', 'delete');
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('sbr_token_conflict', $result->get_error_code());

		$stored = array_keys($GLOBALS['wp_transients_mock']);
		$tokens = array_filter($stored, static fn ($k) => strpos($k, 'slot_') === false);
		$this->assertSame(array($winner_key), array_values($tokens), 'only the winner token survives');
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_exclusive_happy_path_leaves_one_pending_token(): void
	{
		self::stubs();
		$first  = SBR_State_Token::create_exclusive('delete_feed_2', 'delete');
		$second = SBR_State_Token::create_exclusive('delete_feed_3', 'delete');
		$this->assertIsString($second);
		$this->assertFalse(SBR_State_Token::peek($first, 'delete_feed_2'), 'a new mint invalidates the previous one');
		$this->assertTrue(SBR_State_Token::peek($second, 'delete_feed_3'));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_can_write_checks_capability_before_the_gate(): void
	{
		self::stubs();
		// Gate OFF (default). A caller without the capability must not learn that.
		$GLOBALS['sbr_test_user_can'] = false;
		$result = (new SBR_Set_Moderation())->can_write();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame('sbr_forbidden', $result->get_error_code(), 'non-admin never sees the gate state');

		// Admin, gate off: now the gate error is the right answer.
		$GLOBALS['sbr_test_user_can'] = true;
		$result = (new SBR_Set_Moderation())->can_write();
		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertNotSame('sbr_forbidden', $result->get_error_code());

		// Admin, gate on.
		$GLOBALS['wp_options_mock']['sbr_settings'] = array('ai_write_enabled' => true);
		$this->assertTrue((new SBR_Set_Moderation())->can_write());

		// Non-admin with the gate on is still forbidden.
		$GLOBALS['sbr_test_user_can'] = false;
		$this->assertSame('sbr_forbidden', (new SBR_Set_Moderation())->can_write()->get_error_code());
	}

	public function test_source_inputs_declare_unique_items_and_create_feed_requires_sources(): void
	{
		$set = (new SBR_Set_Feed_Sources())->args();
		$this->assertTrue($set['input_schema']['properties']['sources']['uniqueItems']);

		$create = (new SBR_Create_Feed())->args();
		$this->assertTrue($create['input_schema']['properties']['sources']['uniqueItems']);
		$this->assertSame(1, $create['input_schema']['properties']['sources']['minItems']);
		$this->assertContains('sources', $create['input_schema']['required']);
	}

	public function test_resolver_collapses_duplicate_source_ids(): void
	{
		$source = file_get_contents(__DIR__ . '/../../class/Common/Abilities/Services/SBR_Source_Service.php');
		$this->assertMatchesRegularExpression(
			'/\$trimmed\s*=\s*array_values\(array_unique\(\$trimmed\)\);\s*\$by_id\s*=/',
			$source,
			'resolve_allowed_sources() must dedupe before looking ids up and looping'
		);
	}

	public function test_corrupt_stored_settings_json_is_detected(): void
	{
		$service = new SBR_Feed_Settings_Service();
		$m       = new \ReflectionMethod($service, 'settings_column_is_corrupt');
		$m->setAccessible(true);

		$this->assertTrue($m->invoke($service, '{"sources":['), 'truncated JSON');
		$this->assertTrue($m->invoke($service, 'not json'));
		$this->assertTrue($m->invoke($service, '5'), 'valid JSON that is not an object/array');
		$this->assertFalse($m->invoke($service, '{"sources":["a"]}'));
		$this->assertFalse($m->invoke($service, '[]'));
		$this->assertFalse($m->invoke($service, ''), 'a never-saved feed is empty, not corrupt');
		$this->assertFalse($m->invoke($service, null));

		$e = new \ReflectionMethod($service, 'corrupt_settings_error');
		$e->setAccessible(true);
		$err = $e->invoke($service, 4);
		$this->assertSame('sbr_feed_settings_corrupt', $err->get_error_code());
		$this->assertSame(409, $err->get_error_data()['status']);
	}

	private static function reconcile(array $posted): array
	{
		$ref  = new \ReflectionClass(SettingsManagerService::class);
		$svc  = $ref->newInstanceWithoutConstructor();
		$m    = $ref->getMethod('reconcile_ai_write_toggle');
		$m->setAccessible(true);
		return $m->invoke($svc, $posted);
	}

	public function test_settings_page_save_only_changes_the_write_toggle_when_the_user_flipped_it(): void
	{
		$L = SettingsManagerService::AI_WRITE_LOADED_KEY;
		$K = SettingsManagerService::AI_WRITE_KEY;

		// Stale tab: loaded ON, untouched, posted back ON after an admin turned it off elsewhere.
		$out = self::reconcile(array($K => true, $L => true, 'x' => 1));
		$this->assertArrayNotHasKey($K, $out, 'untouched toggle is left to the stored value');
		$this->assertArrayNotHasKey($L, $out, 'the marker never reaches storage');
		$this->assertSame(1, $out['x']);

		// OFF is always honoured, even when it matches the loaded value:
		// same tab, loaded OFF → turned ON + saved → turned OFF + saved. The
		// marker still says OFF, but the second save must reach the option
		// (Rex round 2 on #536) so writes really go off and the epoch records.
		$this->assertFalse(self::reconcile(array($K => false, $L => false))[$K]);

		// Same tab, loaded ON → turned OFF + saved → turned ON again, WITHOUT the
		// page refreshing its marker: fails closed (dropped). The save response
		// refreshes the marker (SBR-577), so a current page sends loaded=false.
		$this->assertArrayNotHasKey($K, self::reconcile(array($K => true, $L => true)));

		// The user flips it: both directions save.
		$this->assertTrue(self::reconcile(array($K => true, $L => false))[$K]);
		$this->assertFalse(self::reconcile(array($K => false, $L => true))[$K]);

		// No marker (an older page): may turn writes OFF, can never turn them ON.
		$this->assertFalse(self::reconcile(array($K => false))[$K]);
		$this->assertArrayNotHasKey($K, self::reconcile(array($K => true)));

		// Toggle absent: nothing to reconcile.
		$this->assertArrayNotHasKey($K, self::reconcile(array('x' => 1, $L => true)));
	}
}
