<?php

namespace SmashBalloon\Reviews\Tests\Doubles;

use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;

/**
 * Imagick is "installed" and draws nothing — the hardened-host combination.
 *
 * A host with Imagick present and its SVG/MSVG coder disabled (standard after
 * CVE-2016-3714) is the only place `mark_for()`'s fallback matters, and no test
 * machine can be asked to become one. So the two questions it asks are separate
 * seams and this double forces the combination.
 *
 * Its own file because phpcs enforces one class per file.
 */
final class BadgeImageWithBlindImagick extends SBR_Badge_Image
{
	/** @var int Times the Imagick rasteriser was asked. */
	public static $rasterise_calls = 0;

	/**
	 * @return bool
	 */
	protected static function imagick_available()
	{
		return true;
	}

	/**
	 * @param string $svg SVG markup.
	 * @param string $ink Colour to flatten to.
	 * @param int    $w   Target width.
	 * @param int    $h   Target height.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function rasterise_mark(string $svg, string $ink, int $w, int $h): ?array
	{
		self::$rasterise_calls++;

		return null;
	}
}
