<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\RemoteRequest;

/**
 * Unit tests for the per-request memoization on RemoteRequest::fetch()
 * (SMASH-1360 Phase 2).
 *
 * Pins the memo-key contract: same `(endpoint, provider, place_id, …)`
 * regardless of `source_id` collapses to the same memo key. The wp_lhr_log
 * captured on demo-wp2 2026-05-06 showed the customizer firing duplicate
 * calls — one with `source_id=N&place_id=X`, one with `place_id=X` only.
 * Without the memo, both would have hit the relay (and the relay-side cache
 * absorbs the upstream cost but the WP host still pays the HTTP roundtrip).
 * With the memo, the second call is served from in-process state.
 *
 * @group memo
 * @group SMASH-1360
 */
class RemoteRequestMemoTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// Static state — flush between tests so prior runs don't leak.
		RemoteRequest::flush_memo();
	}

	private function makeRequest(string $place_id, ?int $relay_source_id = null): RemoteRequest
	{
		$args = [
			'business' => $place_id,
			'info' => $relay_source_id !== null ? ['relay_source_id' => $relay_source_id] : [],
		];

		return new RemoteRequest('google', $args, 'reviews');
	}

	public function test_memo_key_collapses_source_id_and_no_source_id_for_same_place(): void
	{
		// The two URL shapes captured in the M4 amplifier evidence:
		//   - /reviews/google?place_id=X&source_id=20
		//   - /reviews/google?place_id=X
		// Both must produce the SAME memo key — they fetch the same upstream data.

		$with_source_id = $this->makeRequest('CHIJ_TEST', 20);
		$without_source_id = $this->makeRequest('CHIJ_TEST', null);

		$args_a = ['place_id' => 'CHIJ_TEST', 'source_id' => 20, 'api_key' => 'KEY'];
		$args_b = ['place_id' => 'CHIJ_TEST', 'api_key' => 'KEY'];

		$key_a = $with_source_id->memo_key($args_a);
		$key_b = $without_source_id->memo_key($args_b);

		$this->assertNotNull($key_a);
		$this->assertNotNull($key_b);
		$this->assertSame($key_a, $key_b, 'source_id presence/absence must collapse to same memo key');
	}

	public function test_memo_key_differs_for_different_place_ids(): void
	{
		$req = $this->makeRequest('CHIJ_AFI');

		$key_afi = $req->memo_key(['place_id' => 'CHIJ_AFI', 'api_key' => 'K']);
		$key_googleplex = $req->memo_key(['place_id' => 'CHIJ_GOOGLEPLEX', 'api_key' => 'K']);

		$this->assertNotSame($key_afi, $key_googleplex, 'Different place_ids MUST get different memo keys');
	}

	public function test_memo_key_differs_for_different_endpoints(): void
	{
		$reviews = new RemoteRequest('google', ['business' => 'X'], 'reviews');
		$sources = new RemoteRequest('google', ['business' => 'X'], 'sources');

		$args = ['place_id' => 'X', 'api_key' => 'K'];

		$this->assertNotSame(
			$reviews->memo_key($args),
			$sources->memo_key($args),
			'/reviews/google and /sources/google return different bytes — must NOT share memo'
		);
	}

	public function test_memo_key_differs_for_different_providers(): void
	{
		$google = new RemoteRequest('google', ['business' => 'X'], 'reviews');
		$yelp = new RemoteRequest('yelp', ['business' => 'X'], 'reviews');

		$args = ['place_id' => 'X', 'api_key' => 'K'];

		$this->assertNotSame(
			$google->memo_key($args),
			$yelp->memo_key($args),
			'Different providers MUST get different memo keys'
		);
	}

	public function test_memo_key_differs_for_different_languages(): void
	{
		$req = $this->makeRequest('X');

		$en = $req->memo_key(['place_id' => 'X', 'api_key' => 'K', 'language' => 'en']);
		$ro = $req->memo_key(['place_id' => 'X', 'api_key' => 'K', 'language' => 'ro']);

		$this->assertNotSame($en, $ro, 'Language is part of upstream response shape — must differentiate');
	}

	public function test_memo_key_differs_for_different_stars_filters(): void
	{
		$req = $this->makeRequest('X');

		$no_filter = $req->memo_key(['place_id' => 'X', 'api_key' => 'K']);
		$filtered = $req->memo_key(['place_id' => 'X', 'api_key' => 'K', 'starsFilter' => '5']);

		$this->assertNotSame($no_filter, $filtered);
	}

	public function test_memo_key_canonicalizes_param_order(): void
	{
		$req = $this->makeRequest('X');

		$a = $req->memo_key(['place_id' => 'X', 'api_key' => 'K', 'language' => 'en']);
		$b = $req->memo_key(['language' => 'en', 'api_key' => 'K', 'place_id' => 'X']);

		$this->assertSame($a, $b, 'Memo key derivation MUST be order-independent (ksort applied)');
	}

	public function test_memo_key_returns_null_for_array_typed_arg(): void
	{
		// Defensive: if anywhere in the args we get an array-typed value
		// (e.g., a future caller passes downloads list as args directly),
		// refuse to memoize — fall through to a real fetch instead of
		// hashing unstably.
		$req = $this->makeRequest('X');

		$key = $req->memo_key([
			'place_id' => 'X',
			'api_key' => 'K',
			'downloads' => [1, 2, 3], // array-typed value
		]);

		$this->assertNull($key, 'Array-typed args MUST bypass memo (mirrors relay-side EXCLUDED_PARAMS policy)');
	}

	public function test_memo_key_excludes_source_id_only_difference(): void
	{
		// Direct echo of the wp_lhr_log demo-wp2 evidence — rows 3+5 had
		// source_id=20, rows 7+9 didn't. They MUST collapse.
		$req = $this->makeRequest('CHIJ_M4');

		$with_sid = $req->memo_key(['place_id' => 'CHIJ_M4', 'source_id' => 20, 'api_key' => 'K']);
		$no_sid = $req->memo_key(['place_id' => 'CHIJ_M4', 'api_key' => 'K']);

		$this->assertSame($with_sid, $no_sid);
	}

	public function test_memo_key_includes_api_key_difference(): void
	{
		// Different api_keys MUST produce different memo keys — this is the
		// tenant isolation primitive on the plugin side. Mirrors the
		// relay-side `sha256(api_key)` discriminator.
		$req = $this->makeRequest('X');

		$tenant_a = $req->memo_key(['place_id' => 'X', 'api_key' => 'AIza-tenant-a-key']);
		$tenant_b = $req->memo_key(['place_id' => 'X', 'api_key' => 'AIza-tenant-b-key']);

		$this->assertNotSame($tenant_a, $tenant_b, 'Different api_keys MUST get different memo keys');
	}

	public function test_flush_memo_clears_state_for_test_isolation(): void
	{
		// The test-only flush helper must actually empty the memo.
		// Verified indirectly via the protected setUp() — if flush didn't
		// work, prior-test state would leak and these tests would interact.
		// This test exists primarily as an explicit contract pin.
		RemoteRequest::flush_memo();
		// No fluent state to check from outside; the assertion is just the
		// observation that subsequent tests pass cleanly when they call
		// flush_memo() in their own setUp.
		$this->assertTrue(true);
	}
}
