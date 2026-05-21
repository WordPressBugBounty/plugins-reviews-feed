<?php

namespace SmashBalloon\Reviews\Tests\Unit\Providers;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Providers\EDD;

/**
 * EDD provider Add-Source gate.
 *
 * Covers the strict pluginRequired check used by Util::get_providers()
 * and EDD::registerProvider() so the customizer modal disables the EDD
 * tile (and shows the precise tooltip) when either EDD core or the
 * EDD Reviews extension is missing.
 *
 * Matrix:
 *   - no EDD core           → gate blocks, message points at EDD core
 *   - EDD core only         → gate blocks, message points at EDD Reviews extension
 *   - EDD core + extension  → gate opens
 *   - alternate extension path → gate opens (covers easy-digital-downloads-reviews/edd-reviews.php)
 */
class EddProviderGateTest extends TestCase
{
	const EDD_CORE_PATH        = 'easy-digital-downloads/easy-digital-downloads.php';
	const EDD_REVIEWS_PATH     = 'edd-reviews/edd-reviews.php';
	const EDD_REVIEWS_ALT_PATH = 'easy-digital-downloads-reviews/edd-reviews.php';

	protected function setUp(): void
	{
		parent::setUp();

		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [];
	}

	protected function tearDown(): void
	{
		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [];

		parent::tearDown();
	}

	/**
	 * Cell 1: nothing installed — message must steer the user to install
	 * EDD core, not the extension.
	 */
	public function test_gate_blocks_and_points_at_core_when_nothing_installed(): void
	{
		$this->assertFalse(EDD::is_active_static());
		$this->assertSame(
			'Enable Easy Digital Downloads plugin to use it as a source',
			EDD::plugin_required_message()
		);
	}

	/**
	 * Cell 2: EDD core present, extension absent — exactly the QA failure
	 * mode that motivated this fix. The gate must stay closed and the
	 * message must call out the missing extension.
	 */
	public function test_gate_blocks_and_points_at_extension_when_only_core(): void
	{
		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [self::EDD_CORE_PATH];

		$this->assertFalse(EDD::is_active_static());
		$this->assertSame(
			'EDD Reviews extension is required. Install and activate EDD Reviews to use it as a source.',
			EDD::plugin_required_message()
		);
	}

	/**
	 * Cell 3: both plugins active — gate opens. Message returned in this
	 * state is unused by the modal (tile isn't disabled), but we keep it
	 * deterministic regardless.
	 */
	public function test_gate_opens_when_core_and_reviews_extension_active(): void
	{
		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [self::EDD_CORE_PATH, self::EDD_REVIEWS_PATH];

		$this->assertTrue(EDD::is_active_static());
	}

	/**
	 * Cell 4: alternate slug used by older bundles of the EDD Reviews
	 * extension (`easy-digital-downloads-reviews/edd-reviews.php`). The
	 * gate must accept it — otherwise legitimate Pro Plus customers on
	 * older installs would be blocked.
	 */
	public function test_gate_opens_for_alternate_extension_slug(): void
	{
		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [self::EDD_CORE_PATH, self::EDD_REVIEWS_ALT_PATH];

		$this->assertTrue(EDD::is_active_static());
	}

	/**
	 * BC contract: the static gate must NOT fall back to "DB has orphan
	 * edd_review rows". The runtime is_edd_active() instance method keeps
	 * that fallback (so existing sources still display when the extension
	 * was uninstalled later), but the Add-Source flow needs the live
	 * extension to capture new reviews — otherwise the integration would
	 * silently stop syncing for the customer.
	 *
	 * If a future refactor reintroduces the DB fallback here, this test
	 * fails and forces the author to revisit the trade-off.
	 */
	public function test_gate_does_not_fallback_to_db_when_extension_missing(): void
	{
		global $wp_active_plugins_mock;
		$wp_active_plugins_mock = [self::EDD_CORE_PATH];

		// Note: we don't touch $wpdb here. The test passing means the
		// static gate didn't query the DB — which is the contract.
		$this->assertFalse(EDD::is_active_static());
	}
}
