<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;

/**
 * Rasters are composited, not embedded as `data:` URIs (SBR-495).
 *
 * ImageMagick's internal SVG renderer treats a `data:` href as a filename and throws for
 * the WHOLE document, so a half star or a bitmap provider mark used to cost the entire
 * email PNG on any host without a librsvg delegate. render_png() now collects the rasters
 * and composites them after rasterising an image-free SVG.
 *
 * These pin the collector, which is the mechanism. Whether Imagick then draws it is a live
 * check, not a unit one — there is no Imagick or database here.
 */
class BadgeImageRasterCompositeTest extends TestCase
{
	/**
	 * @return ReflectionMethod
	 */
	private function rasterTag()
	{
		$method = new ReflectionMethod(SBR_Badge_Image::class, 'raster_tag');
		$method->setAccessible(true);

		return $method;
	}

	/**
	 * With a collector, nothing is written into the SVG and the geometry is recorded for
	 * compositing. This is the assertion that keeps the bug from returning: an `<image>`
	 * here is what threw.
	 *
	 * @return void
	 */
	public function test_a_collected_raster_leaves_no_image_element()
	{
		$rasters = array();
		$markup  = $this->rasterTag()->invokeArgs(null, array( 12, 34, 56, 78, 'PNGBYTES', &$rasters ));

		$this->assertSame('', $markup, 'A collected raster must contribute no markup.');
		$this->assertCount(1, $rasters);
		$this->assertSame(
			array( 'x' => 12, 'y' => 34, 'w' => 56, 'h' => 78, 'png' => 'PNGBYTES' ),
			$rasters[0]
		);
	}

	/**
	 * Several rasters keep their order and their own geometry — the stars and each
	 * provider mark land in different places.
	 *
	 * @return void
	 */
	public function test_rasters_accumulate_in_order()
	{
		$rasters = array();
		$tag     = $this->rasterTag();

		$tag->invokeArgs(null, array( 1, 2, 3, 4, 'STARS', &$rasters ));
		$tag->invokeArgs(null, array( 5, 6, 7, 8, 'MARK', &$rasters ));

		$this->assertCount(2, $rasters);
		$this->assertSame('STARS', $rasters[0]['png']);
		$this->assertSame(5, $rasters[1]['x']);
	}

	/**
	 * Without a collector the document stays self-contained, so anything that wants one
	 * whole SVG still gets it.
	 *
	 * @return void
	 */
	public function test_without_a_collector_the_raster_is_embedded()
	{
		$none   = null;
		$markup = $this->rasterTag()->invokeArgs(null, array( 12, 34, 56, 78, 'PNGBYTES', &$none ));

		$this->assertStringContainsString('<image', $markup);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode('PNGBYTES'), $markup);
		$this->assertStringContainsString('x="12"', $markup);
		$this->assertStringContainsString('height="78"', $markup);
	}

	/**
	 * Geometry is cast, because it arrives from float arithmetic in the layout and an
	 * `<image x="12.5">` is not what the composite step can offset by.
	 *
	 * @return void
	 */
	public function test_geometry_is_integers()
	{
		$rasters = array();
		$this->rasterTag()->invokeArgs(null, array( 12.7, 34.2, 56.9, 78.1, 'PNG', &$rasters ));

		foreach (array( 'x', 'y', 'w', 'h' ) as $key) {
			$this->assertIsInt($rasters[0][$key], $key . ' must be an int');
		}

		$this->assertSame(12, $rasters[0]['x']);
		$this->assertSame(78, $rasters[0]['h']);
	}
}
