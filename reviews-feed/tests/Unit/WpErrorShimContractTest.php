<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;

/**
 * `is_wp_error()` in the test bootstrap must answer the way core does.
 *
 * It returned `false` unconditionally, which made every `if (is_wp_error($x))`
 * in the plugin — 49 call sites — take the success path in this suite even when
 * handed a WP_Error. So error handling could not be asserted at all, and a
 * regression that started returning WP_Error where data was expected would have
 * stayed green.
 *
 * Rule 19: a shim's fidelity is only proven by driving the branch it gates, so
 * this also runs the relay's unreachable-server path end to end.
 */
final class WpErrorShimContractTest extends TestCase
{
	/**
	 * @return void
	 */
	public function test_a_wp_error_is_recognised(): void
	{
		$this->assertTrue(is_wp_error(new \WP_Error('x', 'y')));
	}

	/**
	 * @return void
	 */
	public function test_ordinary_values_are_not(): void
	{
		foreach (array( null, false, 0, '', 'string', array(), new \stdClass() ) as $value) {
			$this->assertFalse(is_wp_error($value), 'only a WP_Error is a WP_Error');
		}
	}

	/**
	 * The branch this unlocks, driven for real.
	 *
	 * `SBRelay::call()` answers a user-facing envelope when the HTTP layer hands
	 * back a WP_Error. With the old shim that branch was unreachable: the code
	 * fell through and tried to read a response code off the error object.
	 *
	 * @return void
	 */
	public function test_the_relay_answers_an_unreachable_server_envelope(): void
	{
		global $wp_http_error_mock;

		$wp_http_error_mock = new \WP_Error('http_request_failed', 'cURL error 6: Could not resolve host');

		try {
			$out = ( new SBRelay() )->call('sources/info', array(), 'GET', false);
		} finally {
			$wp_http_error_mock = null;
		}

		$this->assertSame('relayUnreachable', $out['id'] ?? null, 'the WP_Error branch must be the one taken');
		$this->assertFalse($out['success'] ?? true);

		// The message is ours, not the transport's — the cURL text is logged, not shown.
		$this->assertStringNotContainsString('cURL', (string) ( $out['message'] ?? '' ));
		$this->assertStringContainsString("Couldn't reach", (string) ( $out['message'] ?? '' ));
	}
}
