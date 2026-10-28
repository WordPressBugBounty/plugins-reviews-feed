<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Abilities\Abilities\SBR_List_Feeds;
use SmashBalloon\Reviews\Common\Abilities\SBR_Abilities;
use SmashBalloon\Reviews\Common\Abilities\SBR_State_Token;

/**
 * SBR-499 QA review (PR #536) follow-ups:
 *
 * 1. DISCOVERY PAGING. With MCP Write Access off the abilities list hides write
 *    abilities. On cores without a pre-pagination hook the filtered set is
 *    re-sliced by SBR_Abilities::paginate() and the Link header comes from
 *    link_pages(); these pin the totals QA saw drift.
 * 2. REVOKED LINKS STAY DEAD. SBR_State_Token::revoke_group() kills the current
 *    user's pending deletion token, so re-enabling the gate cannot revive it.
 * 3. TOKEN PREFIX vs CACHE WIPES. State tokens are transients. Every LIKE
 *    pattern in the plugin that targets wp_options names is read out of the
 *    source; none may match a token or slot option name.
 * 4. list-feeds ITEM SHAPE. `id` is canonical, `feed_id` a deprecated alias.
 */
class Smash1994AbilitiesPagingAndRevocationTest extends TestCase
{
	public function test_paginate_slices_filtered_set_and_reports_filtered_totals(): void
	{
		$kept = range(1, 7);
		$p1   = SBR_Abilities::paginate($kept, 1, 3);
		$this->assertSame(array(1, 2, 3), $p1['items']);
		$this->assertSame(7, $p1['total']);
		$this->assertSame(3, $p1['total_pages']);
		$p3 = SBR_Abilities::paginate($kept, 3, 3);
		$this->assertSame(array(7), $p3['items']);
		$this->assertSame(array(7, 3), array($p3['total'], $p3['total_pages']));
		$p4 = SBR_Abilities::paginate($kept, 4, 3);
		$this->assertSame(array(array(), 7, 3), array($p4['items'], $p4['total'], $p4['total_pages']));
		$empty = SBR_Abilities::paginate(array(), 1, 10);
		$this->assertSame(array(0, 0), array($empty['total'], $empty['total_pages']));
		$this->assertSame(array('b'), SBR_Abilities::paginate(array(5 => 'a', 9 => 'b'), 2, 1)['items']);
	}

	public function test_link_pages(): void
	{
		$this->assertSame(array('next' => 2), SBR_Abilities::link_pages(1, 3));
		$this->assertSame(array('prev' => 1, 'next' => 3), SBR_Abilities::link_pages(2, 3));
		$this->assertSame(array('prev' => 2), SBR_Abilities::link_pages(3, 3));
		$this->assertSame(array(), SBR_Abilities::link_pages(1, 1));
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_revoke_group_kills_pending_token_for_current_user_only(): void
	{
		$GLOBALS['wp_transients_mock'] = array();
		// tests/bootstrap.php stubs get_current_user_id() to read
		// $GLOBALS['sbr_test_user_id']; drive the same global so the stub
		// switches users. The eval fallback only runs if it's ever removed.
		$GLOBALS['sbr_test_user_id']   = 7;
		if (!function_exists('get_current_user_id')) {
			eval('function get_current_user_id() { return (int) ($GLOBALS["sbr_test_user_id"] ?? 0); }');
		}

		$mine = SBR_State_Token::create_exclusive('delete_feed_2', 'delete');
		$this->assertTrue(SBR_State_Token::peek($mine, 'delete_feed_2'));

		$GLOBALS['sbr_test_user_id'] = 8;
		$theirs = SBR_State_Token::create_exclusive('delete_feed_3', 'delete');

		// User 7 revokes: only user 7's token dies.
		$GLOBALS['sbr_test_user_id'] = 7;
		SBR_State_Token::revoke_group('delete');
		$this->assertFalse(SBR_State_Token::peek($mine, 'delete_feed_2'));
		$this->assertFalse(SBR_State_Token::consume($mine, 'delete_feed_2'));

		$GLOBALS['sbr_test_user_id'] = 8;
		$this->assertTrue(SBR_State_Token::peek($theirs, 'delete_feed_3'));

		// Revoking when nothing is pending is a harmless no-op.
		SBR_State_Token::revoke_group('delete');
		SBR_State_Token::revoke_group('delete');
		$this->assertFalse(SBR_State_Token::peek($theirs, 'delete_feed_3'));
	}

	public function test_no_cache_wipe_like_pattern_matches_token_option_names(): void
	{
		$root = realpath(__DIR__ . '/../..');
		$it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

		$patterns = array();
		foreach ($it as $file) {
			$path = $file->getPathname();
			$rel  = substr($path, strlen($root));
			if (substr($path, -4) !== '.php' || preg_match('#/(vendor|vendor-bin|node_modules|tests|build|\.claude|\.git)/#', $rel)) {
				continue;
			}
			if (preg_match_all('/LIKE\s*\(?\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($path), $m)) {
				$patterns = array_merge($patterns, $m[1]);
			}
		}
		$option_patterns = array_filter(array_unique($patterns), function ($p) {
			return strpos($p, 'transient') !== false;
		});
		// The explicit uninstall cleanup is the only intended matcher.
		$cleanup = array_values(array_filter($option_patterns, function ($p) {
			return strpos($p, 'abilities') !== false;
		}));
		$wipes   = array_values(array_diff($option_patterns, $cleanup));
		$this->assertGreaterThanOrEqual(5, count($wipes), 'sanity: found the Clear Cache wipe patterns');
		$this->assertCount(2, $cleanup, 'uninstall removes the transient and its timeout row');

		$prefix = SBR_State_Token::PREFIX;
		$names  = array(
			'_transient_' . $prefix . str_repeat('a', 64),
			'_transient_timeout_' . $prefix . str_repeat('a', 64),
			'_transient_' . $prefix . 'slot_delete_1',
			'_transient_timeout_' . $prefix . 'slot_delete_1',
		);
		$matched = array();
		foreach ($wipes as $pattern) {
			foreach ($names as $name) {
				if (preg_match(self::like_to_regex($pattern), $name)) {
					$matched[] = "$pattern => $name";
				}
			}
		}
		$this->assertSame(array(), $matched);

		$uncovered = array();
		foreach ($names as $name) {
			$hit = false;
			foreach ($cleanup as $pattern) {
				$hit = $hit || preg_match(self::like_to_regex($pattern), $name);
			}
			if (!$hit) {
				$uncovered[] = $name;
			}
		}
		$this->assertSame(array(), $uncovered, 'uninstall cleanup covers every token and slot option name');

		// Checker self-test: the OLD prefix really was matched by a Clear Cache wipe.
		$old_hit = false;
		foreach ($wipes as $pattern) {
			$old_hit = $old_hit || preg_match(self::like_to_regex($pattern), '_transient_sbr_abilities_state_' . str_repeat('a', 64));
		}
		$this->assertTrue((bool) $old_hit);
	}

	/** MySQL LIKE to PCRE: `\x` literal, `%` any run, bare `_` any single char. */
	private static function like_to_regex(string $like): string
	{
		$out = '';
		$n   = strlen($like);
		for ($i = 0; $i < $n; $i++) {
			$c = $like[$i];
			if ($c === '\\' && $i + 1 < $n) {
				$out .= preg_quote($like[++$i], '/');
			} elseif ($c === '%') {
				$out .= '.*';
			} elseif ($c === '_') {
				$out .= '.';
			} else {
				$out .= preg_quote($c, '/');
			}
		}
		return '/^' . $out . '$/s';
	}

	public function test_list_feeds_schema_carries_id_and_deprecated_feed_id_alias(): void
	{
		if (!function_exists('__')) {
			$this->markTestSkipped('i18n stub unavailable');
		}
		$args    = (new SBR_List_Feeds())->args();
		$summary = $args['output_schema']['properties']['feeds']['items'];
		$props   = $summary['properties'];
		$this->assertSame('integer', $props['id']['type']);
		$this->assertSame('integer', $props['feed_id']['type']);
		$this->assertStringContainsString('Deprecated', $props['feed_id']['description']);
		$this->assertArrayNotHasKey('additionalProperties', $summary);

		// The service emits both keys with the same value.
		$src = file_get_contents(__DIR__ . '/../../class/Common/Abilities/Services/SBR_Feed_Settings_Service.php');
		$this->assertMatchesRegularExpression("/'id'\s*=>\s*\(int\) \\\$row\['id'\],\s*'feed_id'\s*=>\s*\(int\) \\\$row\['id'\]/", $src);
	}
}
