<?php

namespace SmashBalloon\Reviews\Tests\Unit\Doubles;

/**
 * Image editor double for RemoteReviewImageDownloadTest: records each resize
 * width, whether the opened file existed at that moment, and saved file name in globals so a test can assert them.
 */
final class FakeImageEditor
{
	private $path;

	public function __construct($path = '')
	{
		$this->path = $path;
	}

	public function get_size()
	{
		return ['width' => 300, 'height' => 200];
	}

	public function resize($width, $height)
	{
		$GLOBALS['sbr_test_resized'][] = $width;
		// WebP editors re-read the opened file during resize(), so it must still exist.
		$GLOBALS['sbr_test_file_during_resize'][] = is_file($this->path);
	}

	public function save($filename)
	{
		$GLOBALS['sbr_test_saved'][] = basename($filename);
		return ['path' => $filename];
	}
}
