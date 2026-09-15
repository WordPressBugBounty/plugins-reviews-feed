<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;

/**
 * What actually lands in `sbr_errors`, driven through the public API.
 *
 * The existing guard for this greps SBRelay.php for `sanitize_error_payload(`
 * at the call sites. Reviewers rightly pointed out that a refactor which
 * preserves behaviour would fail it, and one that preserves the text could
 * still break behaviour. This is the behavioural half they asked for — and it
 * turns out to guard something better than the call sites, because the strip is
 * applied in `update_errors()`, the ONE function that writes the option. Every
 * caller reaches it, including any future one that forgets to sanitise first.
 *
 * The option is the trust boundary: the Support page renders it (SMASH-2031).
 */
class BadgeErrorLogSanitisationTest extends TestCase
{
	/** @var mixed */
	private $saved_options;

	protected function setUp(): void
	{
		parent::setUp();

		$this->saved_options = $GLOBALS['wp_options_mock'] ?? null;
		$GLOBALS['wp_options_mock'] = array();
	}

	protected function tearDown(): void
	{
		$GLOBALS['wp_options_mock'] = $this->saved_options;

		parent::tearDown();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function stored()
	{
		$errors = $GLOBALS['wp_options_mock']['sbr_errors'] ?? array();

		return is_array($errors) ? $errors : array();
	}

	/**
	 * @return string Everything written, flattened, for substring assertions.
	 */
	private function storedText()
	{
		$flat = '';

		array_walk_recursive(
			$this->stored(),
			static function ($value) use (&$flat) {
				$flat .= is_scalar($value) ? (string) $value . "\n" : '';
			}
		);

		return $flat;
	}

	/**
	 * @return void
	 */
	public function test_markup_in_a_string_never_reaches_the_option()
	{
		SBR_Error_Handler::log_error(array(
			'id'      => 'relayServerError',
			'message' => 'broken <script>alert(1)</script> thing',
		));

		// Prove something was written before asserting anything about it.
		$this->assertNotSame('', $this->storedText(), 'nothing was logged, so this test proves nothing');

		$this->assertStringNotContainsString('<script', $this->storedText());
		$this->assertStringContainsString('broken', $this->storedText(), 'the diagnostic text itself must survive');
	}

	/**
	 * @return void
	 */
	public function test_markup_nested_in_an_array_is_stripped_too()
	{
		SBR_Error_Handler::log_error(array(
			'id'   => 'nested',
			'data' => array( 'inner' => array( 'deep' => '<img src=x onerror=alert(1)>' ) ),
		));

		$this->assertStringNotContainsString('<img', $this->storedText());
		$this->assertStringNotContainsString('onerror', $this->storedText());
	}

	/**
	 * The gap a reviewer found: objects fell through the type chain untouched.
	 *
	 * @return void
	 */
	public function test_a_stringable_object_carrying_markup_is_stripped()
	{
		SBR_Error_Handler::log_error(array(
			'id'      => 'stringable',
			'message' => new class {
				public function __toString(): string
				{
					return 'upstream said <script>alert(1)</script>';
				}
			},
		));

		$text = $this->storedText();

		$this->assertStringNotContainsString('<script', $text, 'an object\'s __toString bypassed the strip');
		$this->assertStringContainsString('upstream said', $text, 'the message itself is worth keeping');
	}

	/**
	 * @return void
	 */
	public function test_a_plain_object_is_reduced_to_something_renderable()
	{
		SBR_Error_Handler::log_error(array(
			'id'      => 'plain-object',
			'payload' => new \stdClass(),
		));

		$stored = $this->stored();
		$found  = false;

		array_walk_recursive(
			$stored,
			static function ($value) use (&$found) {
				if (is_object($value)) {
					$found = true;
				}
			}
		);

		$this->assertFalse($found, 'an object reached the option, where the Support page has to render it');
	}
}
