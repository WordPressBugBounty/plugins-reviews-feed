<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\ReviewAlerts\SBR_Review_Alert_Service;

/**
 * SMASH-2220: a Review Alert accent typed as a hex renders as that exact colour, and review
 * counts carry the locale's thousands separator.
 */
final class Smash2220ReviewAlertExactAccentTest extends TestCase
{
	/** @return array<string, array{0: mixed, 1: array{color: string, contrast: string, ink: string}|null}> */
	public static function provideAccents(): array
	{
		return [
			'navy: white text, navy stars'      => ['#00206F', ['color' => '#00206F', 'contrast' => '#FFFFFF', 'ink' => '#00206F']],
			'white: dark text, neutral stars'   => ['#FFFFFF', ['color' => '#FFFFFF', 'contrast' => '#18181B', 'ink' => '#3F3F46']],
			'light grey: too light for stars'   => ['#EEEEEE', ['color' => '#EEEEEE', 'contrast' => '#18181B', 'ink' => '#3F3F46']],
			'gold: dark text, gold stars'       => ['#FFD700', ['color' => '#FFD700', 'contrast' => '#18181B', 'ink' => '#FFD700']],
			'default blue'                      => ['#175CE3', ['color' => '#175CE3', 'contrast' => '#FFFFFF', 'ink' => '#175CE3']],
			'lowercase is normalised'           => ['#00206f', ['color' => '#00206F', 'contrast' => '#FFFFFF', 'ink' => '#00206F']],
			'3-digit hex is not an exact accent' => ['#FFF', null],
			'named colour'                      => ['red', null],
			'not hex'                           => ['#GGGGGG', null],
			'null'                              => [null, null],
		];
	}

	/**
	 * @dataProvider provideAccents
	 *
	 * @param mixed                                                   $hex
	 * @param array{color: string, contrast: string, ink: string}|null $expected
	 */
	public function test_accent_colours(mixed $hex, ?array $expected): void
	{
		$this->assertSame($expected, sbr_review_alert_accent_colors($hex));
	}

	public function test_accent_mode_is_saved_only_as_exact_or_hue(): void
	{
		$this->assertSame('exact', SBR_Review_Alert_Service::sanitize_settings(['accent_mode' => 'exact'])['accent_mode']);
		$this->assertSame('hue', SBR_Review_Alert_Service::sanitize_settings(['accent_mode' => 'hue'])['accent_mode']);
		$this->assertSame('hue', SBR_Review_Alert_Service::sanitize_settings(['accent_mode' => '<b>'])['accent_mode']);
		$this->assertSame('hue', SBR_Review_Alert_Service::sanitize_settings([])['accent_mode']);
	}

	/** BC: alerts saved before 2.15.1 have no accent_mode and keep the hue palette. */
	public function test_existing_alerts_without_a_mode_keep_the_hue_palette(): void
	{
		$this->assertSame('hue', SBR_Review_Alert_Service::get_defaults()['accent_mode']);
		$this->assertNull(SBR_Review_Alert_Service::exact_accent(['accent_color' => '#00206F', 'accent_hue' => '223']));
		$this->assertNull(SBR_Review_Alert_Service::exact_accent(['accent_mode' => 'hue', 'accent_color' => '#00206F']));
	}

	public function test_exact_mode_with_a_valid_hex_gets_the_exact_accent(): void
	{
		$this->assertSame(
			['color' => '#00206F', 'contrast' => '#FFFFFF', 'ink' => '#00206F'],
			SBR_Review_Alert_Service::exact_accent(['accent_mode' => 'exact', 'accent_color' => '#00206F'])
		);
		$this->assertNull(SBR_Review_Alert_Service::exact_accent(['accent_mode' => 'exact', 'accent_color' => 'navy']));
	}

	/**
	 * Renders templates/review-alerts/popup.php and returns its root element.
	 *
	 * @param array<string, mixed> $settings
	 */
	private function render_popup(array $settings, int $total): \DOMElement
	{
		$popup   = ['id' => 5, 'settings' => $settings];
		$reviews = [['reviewer' => ['name' => 'Ana'], 'rating' => 5, 'text' => 'Great', 'time' => time() - 3600, 'provider' => ['name' => 'google']]];
		$config  = ['totalReviews' => $total, 'averageRating' => 4.6];

		ob_start();
		try {
			include dirname(__DIR__, 2) . '/templates/review-alerts/popup.php';
		} finally {
			$html = (string) ob_get_clean();
		}

		$dom = new \DOMDocument();
		@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
		$root = (new \DOMXPath($dom))->query('//div[contains(concat(" ", normalize-space(@class), " "), " sbr-review-alert ")]')->item(0);
		$this->assertInstanceOf(\DOMElement::class, $root);

		return $root;
	}

	private function link_text(\DOMElement $root): string
	{
		$xpath = new \DOMXPath($root->ownerDocument);
		$link  = $xpath->query('.//a[contains(@class, "sbr-review-alert__view-all-link")]/span', $root)->item(0);

		return $link ? trim($link->textContent) : '';
	}

	public function test_aggregate_count_has_a_thousands_separator(): void
	{
		$root = $this->render_popup(['popup_type' => 'aggregate'], 7133);
		$this->assertSame('7,133 Total Reviews', $this->link_text($root));
	}

	public function test_recent_count_has_a_thousands_separator(): void
	{
		$root = $this->render_popup(['popup_type' => 'recent'], 7133);
		$this->assertSame('View All 7,133 Reviews', $this->link_text($root));
	}

	/** BC: below 1,000 nothing changes. */
	public function test_small_counts_are_unchanged(): void
	{
		$root = $this->render_popup(['popup_type' => 'aggregate'], 42);
		$this->assertSame('42 Total Reviews', $this->link_text($root));
	}

	public function test_hue_alert_renders_without_the_exact_accent(): void
	{
		$root = $this->render_popup(['accent_color' => '#00206F', 'accent_hue' => '223'], 5);
		$this->assertStringNotContainsString('sbr-review-alert--exact-accent', $root->getAttribute('class'));
		$this->assertStringNotContainsString('--sbr-popup-accent-exact', $root->getAttribute('style'));
		$this->assertStringContainsString('--sbr-popup-accent-hue: 223;', $root->getAttribute('style'));
	}

	public function test_exact_alert_renders_the_exact_accent(): void
	{
		$root = $this->render_popup(['accent_mode' => 'exact', 'accent_color' => '#ffffff', 'accent_hue' => '0'], 5);
		$this->assertContains('sbr-review-alert--exact-accent', preg_split('/\s+/', trim($root->getAttribute('class'))));
		$style = $root->getAttribute('style');
		$this->assertStringContainsString('--sbr-popup-accent-exact: #FFFFFF;', $style);
		$this->assertStringContainsString('--sbr-popup-accent-contrast: #18181B;', $style);
		$this->assertStringContainsString('--sbr-popup-accent-ink: #3F3F46;', $style);
	}
}
