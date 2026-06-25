<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the REMOVAL of the destructive relay source
 * reconciliation (SMASH-1585 follow-up, 2026-06-24).
 *
 * The relay's source-reconcile endpoint treated an empty `place_ids` keep-list
 * as "remove all". A single false-empty from the plugin (e.g. a transient
 * `$wpdb` read error returning an empty set) on a routine Clear All Caches could
 * therefore wipe EVERY relay-side source for a paying customer. The feature was
 * non-essential — the SMASH-1585 migration fix is the bearer-aware register
 * rebind, and the per-source delete path already keeps the relay in sync — so it
 * was removed outright rather than guarded.
 *
 * These tests fail if anyone reintroduces the destructive call, forcing a
 * deliberate re-review (explicit confirmed remove-all signal + cross-repo
 * place_id/provider pinning) before a keep-list/diff reconcile can return.
 */
final class ReconcileRemovalTest extends TestCase
{
	private static function saverSource(): string
	{
		$path = __DIR__ . '/../../class/Common/Builder/SBR_Feed_Saver_Manager.php';
		self::assertFileExists($path, 'SBR_Feed_Saver_Manager.php not found at expected path');

		return (string) file_get_contents($path);
	}

	public function test_reconcile_relay_sources_method_is_removed(): void
	{
		$this->assertStringNotContainsString(
			'function reconcile_relay_sources',
			self::saverSource(),
			'The destructive reconcile_relay_sources() method must stay removed.'
		);
	}

	public function test_plugin_never_posts_to_sources_reconcile(): void
	{
		$this->assertStringNotContainsString(
			"->call('sources/reconcile'",
			self::saverSource(),
			'The plugin must not POST to sources/reconcile — an empty keep-list wipes all relay sources.'
		);
	}

	public function test_clear_all_caches_does_not_invoke_reconcile(): void
	{
		$this->assertStringNotContainsString(
			'self::reconcile_relay_sources()',
			self::saverSource(),
			'clear_all_caches() must not call reconcile_relay_sources().'
		);
	}
}
