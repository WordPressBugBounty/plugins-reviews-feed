<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;

/**
 * A badge template must not warn on a partial payload (SBR-163).
 *
 * `SBR_Badge_Renderer::view_data()` always builds the whole set, so nothing in
 * the plugin reaches these templates with a key missing. Templates are an
 * override surface though — a theme copy, a block render callback, a caller
 * assembling the array by hand — and on PHP 8 a missing key is a warning printed
 * into the page, followed by `esc_url(null)`-style coercions downstream.
 *
 * Rule 19: the guard is untested until its trigger fires, so these drive the
 * templates with an EMPTY array rather than a healthy one. phpunit.xml sets
 * failOnWarning, which is what turns an unguarded read into a failure — the
 * three shape templates had 13 bare reads each before tb-view-defaults.php.
 */
final class BadgePartialViewDataTest extends TestCase
{
	/** @var array Every shape template, which pull in the partials themselves. */
	private const SHAPES = array( 'card', 'chip', 'medal' );

	/**
	 * @return string
	 */
	private function dir()
	{
		return dirname(__DIR__, 3) . '/templates/frontend/badges';
	}

	/**
	 * Render a template with whatever view data is handed in.
	 *
	 * @param string $shape     Template basename.
	 * @param array  $sbr_badge View data, deliberately allowed to be partial.
	 *
	 * @return string
	 */
	private function render($shape, array $sbr_badge)
	{
		// The templates read this too; stars.php takes it as a local.
		$sbr_badge_rating = 0.0;

		ob_start();

		include $this->dir() . '/' . $shape . '.php';

		return (string) ob_get_clean();
	}

	/**
	 * @return array<int, array{0: string}>
	 */
	public static function shapeProvider(): array
	{
		return array_map(
			static function ($shape) {
				return array( $shape );
			},
			self::SHAPES
		);
	}

	/**
	 * @dataProvider shapeProvider
	 *
	 * @param string $shape Template basename.
	 *
	 * @return void
	 */
	public function test_an_empty_payload_renders_without_a_warning($shape)
	{
		$html = $this->render($shape, array());

		// failOnWarning turns an unguarded read into a failure, so reaching this
		// line is most of the assertion. The rest states what an empty payload is
		// allowed to produce: a shell, and no invented rating.
		$this->assertStringContainsString('sbr-tb-badge', $html);
		$this->assertStringNotContainsString('sbr-tb-val', $html, 'no rating element without data');
		$this->assertStringNotContainsString('sbr-tb-cnt', $html, 'no count element without data');
		$this->assertStringNotContainsString('Warning', $html);
		$this->assertStringNotContainsString('Undefined', $html);
	}

	/**
	 * A payload that is not an array at all lands on the same defaults.
	 *
	 * @return void
	 */
	public function test_a_non_array_payload_does_not_reach_array_access(): void
	{
		$sbr_badge        = 'not an array';
		$sbr_badge_rating = 0.0;

		ob_start();

		include $this->dir() . '/chip.php';

		$html = (string) ob_get_clean();

		$this->assertStringContainsString('sbr-tb-badge', $html);
	}

	/**
	 * The keys the templates actually read are all defaulted.
	 *
	 * Derived from the templates rather than restated, so a new read added to a
	 * template without a default fails here instead of warning in production.
	 *
	 * @return void
	 */
	public function test_every_key_the_templates_read_has_a_default(): void
	{
		$sbr_badge = array();

		include $this->dir() . '/tb-view-defaults.php';

		$defaulted = array_keys($sbr_badge);
		$read      = array();

		foreach ((array) glob($this->dir() . '/*.php') as $file) {
			$src = (string) file_get_contents($file);

			preg_match_all("/\\\$sbr_badge\\['([a-z_]+)'\\]/", $src, $matches);

			$read = array_merge($read, $matches[1]);
		}

		$read = array_values(array_unique($read));

		// Prove the scan found the reads before trusting an empty diff.
		$this->assertGreaterThan(15, count($read), 'the template scan found almost nothing');

		$missing = array_values(array_diff($read, $defaulted));

		$this->assertSame(
			array(),
			$missing,
			'these keys are read by a template with no default behind them: ' . implode(', ', $missing)
		);
	}

	/**
	 * An explicit null falls back to the default rather than overriding it.
	 *
	 * array_merge lets a null WIN, so the key is present and the partial still
	 * hands esc_html() a null — deprecated since PHP 8.1. A key set to null means
	 * "no value", which is the default's job.
	 *
	 * @return void
	 */
	public function test_an_explicit_null_does_not_override_a_default()
	{
		$sbr_badge = array(
			'theme'            => null,
			'count_raw_label'  => null,
			'powered_by_url'   => null,
		);

		include dirname(__DIR__, 3) . '/templates/frontend/badges/tb-view-defaults.php';

		$this->assertSame('light', $sbr_badge['theme']);
		$this->assertNotNull($sbr_badge['count_raw_label']);
		$this->assertSame('', $sbr_badge['powered_by_url']);
	}
}
