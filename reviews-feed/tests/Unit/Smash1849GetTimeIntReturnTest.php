<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Parser;

/**
 * SMASH-1849 — Parser::get_time() must always return an int unix timestamp.
 *
 * Callers do arithmetic on the return value, and SBR_Schema_Service.php:575-577
 * feeds it straight into `gmdate('c', (int) $parser->get_time($post))`. When
 * get_time() returned a raw datetime string, `(int) '2026-07-08 10:00:00'`
 * evaluated to 2026, so JSON-LD datePublished came out as 1970-01-01. These
 * tests pin the int contract so that regression can't come back silently.
 */
class Smash1849GetTimeIntReturnTest extends TestCase
{
	/** @var Parser */
	private $parser;

	protected function setUp(): void
	{
		parent::setUp();
		$this->parser = new Parser();
	}

	public function test_datetime_string_returns_int_timestamp(): void
	{
		$time = $this->parser->get_time(['time' => '2026-07-08 10:00:00']);

		$this->assertIsInt($time);
		$this->assertSame(strtotime('2026-07-08 10:00:00'), $time);
	}

	public function test_integer_input_is_preserved(): void
	{
		$this->assertSame(1704708000, $this->parser->get_time(['time' => 1704708000]));
	}

	public function test_numeric_string_input_returns_same_int(): void
	{
		$this->assertSame(1704708000, $this->parser->get_time(['time' => '1704708000']));
	}

	public function test_empty_time_returns_zero(): void
	{
		$this->assertSame(0, $this->parser->get_time([]));
		$this->assertSame(0, $this->parser->get_time(['time' => '']));
	}

	public function test_unparseable_string_returns_zero(): void
	{
		$this->assertSame(0, $this->parser->get_time(['time' => 'not a date']));
	}

	/**
	 * The exact regression: reproduce SBR_Schema_Service's datePublished
	 * expression and assert a datetime string no longer collapses to 1970.
	 */
	public function test_schema_date_published_is_not_1970(): void
	{
		$post = ['time' => '2026-07-08 10:00:00'];

		$date_published = gmdate('c', (int) $this->parser->get_time($post));

		$this->assertStringStartsWith('2026-07-08', $date_published);
		$this->assertStringStartsNotWith('1970', $date_published);
	}
}
