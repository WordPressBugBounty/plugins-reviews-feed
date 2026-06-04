<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;

/**
 * Regression tests for SMASH-1544.
 *
 * On PHP 8, when the `sbr_errors` option row EXISTS in the DB but holds a
 * falsy value (boolean false / '' — corrupted, legacy or third-party write),
 * get_option('sbr_errors', []) returns that falsy value, NOT the [] default
 * (the default only fires when the row is absent — see
 * wp-includes/option.php). The handler then passed that bool to foreach()
 * and array_push(), producing:
 *
 *   PHP Warning:  foreach() argument must be of type array|object, false given
 *   PHP Fatal error:  Uncaught TypeError: array_push(): Argument #1 ($array)
 *                     must be of type array, false given
 *
 * which took down the feed builder admin page and the WP-Cron cache update.
 *
 * These tests drive the falsy-stored-value path. failOnWarning="true" in
 * phpunit.xml means even the foreach() warning fails the suite pre-fix.
 *
 * @group SMASH-1544
 * @covers \SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler
 */
class ErrorHandlerFalsyOptionTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		global $wp_options_mock;
		$wp_options_mock = [];
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$wp_options_mock = [];
		parent::tearDown();
	}

	private function sampleError(): array
	{
		return [
			'type'     => 'connection',
			'id'       => 'feed_1',
			'provider' => 'google',
			'message'  => 'API key missing',
		];
	}

	/**
	 * The exact customer condition: option row present, value === false.
	 * Pre-fix this fataled at array_push(false, ...). Post-fix it logs cleanly.
	 */
	public function test_log_error_does_not_fatal_when_option_stored_as_false(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_errors'] = false;

		SBR_Error_Handler::log_error($this->sampleError());

		$stored = SBR_Error_Handler::get_errors();
		$this->assertIsArray($stored);
		$this->assertCount(1, $stored);
		$this->assertSame('feed_1', $stored[0]['id']);
	}

	/** Empty-string is the other falsy shape a corrupted option row can take. */
	public function test_log_error_does_not_fatal_when_option_stored_as_empty_string(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_errors'] = '';

		SBR_Error_Handler::log_error($this->sampleError());

		$this->assertCount(1, SBR_Error_Handler::get_errors());
	}

	/** get_errors() must always hand back an array, whatever the stored shape. */
	public function test_get_errors_always_returns_array(): void
	{
		// `null` is deliberately omitted: the bootstrap get_option() stub uses
		// `$wp_options_mock[$option] ?? $default`, so a stored null collapses to
		// the [] default (same as "option not set") and would not exercise the
		// is_array() coercion. The remaining falsy/non-array shapes do. SMASH-1544.
		global $wp_options_mock;
		foreach ([false, '', 0, 'not-an-array', 42] as $bad) {
			$wp_options_mock['sbr_errors'] = $bad;
			$this->assertIsArray(
				SBR_Error_Handler::get_errors(),
				'get_errors() must coerce non-array stored value to []'
			);
		}
	}

	/** check_error() must not trip foreach() on a falsy stored value. */
	public function test_check_error_handles_falsy_option(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_errors'] = false;

		$this->assertSame('not_defined', SBR_Error_Handler::check_error($this->sampleError()));
	}

	/**
	 * BC: the normal path (option absent, then a real array) is unchanged.
	 * An existing caller relying on append-then-read still works.
	 */
	public function test_existing_array_path_is_preserved(): void
	{
		// Option absent -> [] default -> first log creates the array.
		SBR_Error_Handler::log_error($this->sampleError());

		$second = $this->sampleError();
		$second['id'] = 'feed_2';
		SBR_Error_Handler::log_error($second);

		$stored = SBR_Error_Handler::get_errors();
		$this->assertCount(2, $stored);
		$this->assertSame('feed_1', $stored[0]['id']);
		$this->assertSame('feed_2', $stored[1]['id']);
	}
}
