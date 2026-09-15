<?php

namespace SmashBalloon\Reviews\Tests\Doubles;

use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;

/**
 * Neither rasteriser can draw, so nothing may be invented.
 *
 * The other side of BadgeImageWithBlindImagick: the fallback must not turn "no
 * mark available" into a mark.
 */
final class BadgeImageWithNoRasteriser extends SBR_Badge_Image
{
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
		return null;
	}

	/**
	 * @param string $provider Provider key.
	 * @param string $variant  Mark variant.
	 * @param string $ink      Colour to flatten to.
	 * @param int    $w        Target width.
	 * @param int    $h        Target height.
	 *
	 * @return array{png: string, w: int, h: int}|null
	 */
	protected static function gd_mark($provider, $variant, $ink, $w, $h)
	{
		return null;
	}
}
