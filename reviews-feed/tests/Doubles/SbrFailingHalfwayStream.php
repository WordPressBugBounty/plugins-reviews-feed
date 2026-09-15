<?php

namespace SmashBalloon\Reviews\Tests\Unit;

/**
 * A stream that hands back one good chunk and then errors, so the mid-read
 * failure branch of `file_contains()` can be reached at all. Verified that a
 * userland wrapper's `false` from stream_read() reaches fread() as false.
 */
class SbrFailingHalfwayStream
{
	/** @var resource|null */
	public $context;

	/** @var int */
	private $reads = 0;

	/**
	 * @param  string $path
	 * @param  string $mode
	 * @param  int    $options
	 * @param  string $opened_path
	 * @return bool
	 */
	public function stream_open($path, $mode, $options, &$opened_path)
	{
		return true;
	}

	/**
	 * @param  int $count
	 * @return string|false
	 */
	public function stream_read($count)
	{
		$this->reads++;

		// One chunk of content that does NOT contain the marker, then an error —
		// so a branch that returned $found would answer "absent".
		return 1 === $this->reads ? str_repeat('a', 32) : false;
	}

	/**
	 * @return bool
	 */
	public function stream_eof()
	{
		return false;
	}

	/**
	 * @return bool
	 */
	public function stream_close()
	{
		return true;
	}

	/**
	 * @return array<string, int>
	 */
	public function stream_stat()
	{
		return array( 'size' => 32 );
	}

	/**
	 * @param  string $path
	 * @param  int    $flags
	 * @return array<string, int>
	 */
	public function url_stat($path, $flags)
	{
		return array( 'size' => 32, 'mode' => 0100644 );
	}
}
