<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * Which line the saved-badge footer strip renders (SBR-163).
 *
 * BadgePoweredByGateTest pins the tier gate itself — `show_powered_by()` given
 * a tier and a requested value. This pins the thing that consumes it, which is
 * where the bug actually shipped: tb-footer.php picking between three
 * candidates for one strip.
 *
 * It shipped twice in the same spot, in opposite directions:
 *
 *  1. First the template read no gate at all, so chip/card/medal published with
 *     no attribution on a lapsed or below-Pro-Plus licence — the whole new
 *     family bypassing a gate the renderer documents as unfilterable.
 *  2. Fixing that by reading `show_powered_by` overshot: a saved badge stores
 *     `powered_by => true` by default, so on Pro Plus the attribution won and
 *     the footer text an author typed never appeared. The field read as dead.
 *
 * Both reverts leave every other test green, which is why this file exists.
 *
 * The precedence, in order:
 *
 *   tier forces it            -> attribution, whatever the author wrote
 *   author wrote something    -> the author's line
 *   caller opted in           -> attribution
 *   none of the above         -> no strip at all
 *
 * The third case belongs to the INLINE shortcode —
 * `[reviews-badge shape="card" powered_by="true"]` renders card.php, which
 * includes this template, and passes no footer_text. (That shortcode was removed on
 * 19 Aug with the inline rail; the opt-in branch stays because a caller can still pass
 * powered_by, and the test below pins its behaviour.) Not the widget: that
 * validates its shape against PLACEMENT_SHAPES, and none of those four include
 * this file.
 *
 * A saved badge never reaches that third case either, and the reason is worth
 * stating because it was the third bug in this spot: the store defaults
 * `powered_by` to true, so passing it through made every badge with its footer
 * switched off grow an attribution strip nobody asked for. render_saved()
 * passes false, and the tier gate is untouched by that — below Pro Plus it
 * forces the attribution regardless.
 */
class BadgeFooterPrecedenceTest extends TestCase
{
	/**
	 * Render tb-footer.php against a prepared badge array.
	 *
	 * The template is included directly rather than driven through render(),
	 * because the whole point is what THIS file decides given flags that are
	 * already resolved. Driving it end-to-end would test the resolution again
	 * and leave the branch selection uncovered.
	 *
	 * @param array $badge Prepared view data.
	 *
	 * @return string
	 */
	private function render(array $badge)
	{
		$sbr_badge = array_merge(
			array(
				'powered_by_forced' => false,
				'show_powered_by'   => false,
				'footer_text'       => '',
				'footer_url'        => '',
				'powered_by_url'    => 'https://smashballoon.com/reviews-feed/',
			),
			$badge
		);

		ob_start();
		include dirname(__DIR__, 3) . '/templates/frontend/badges/tb-footer.php';

		return (string) ob_get_clean();
	}

	/**
	 * The default footer copy names the COMPANY, not the plugin.
	 *
	 * Every new badge starts with this string in the box, and below Pro Plus the renderer
	 * prints it whatever is stored — so it is the line most badges in the wild will carry.
	 * Three surfaces used to give three answers: the design frame said "Powered by Smash
	 * Balloon", Review Alerts printed "Powered by" plus the Smash Balloon wordmark
	 * (templates/review-alerts/popup.php:331), and the badge said "Reviews Feed". Aligned
	 * onto the company name 2026-09-01. This assertion previously pinned the opposite
	 * decision, from 19 Aug — it is reversed on purpose, not broken.
	 *
	 * Saved badges are deliberately NOT migrated: footer_text is copy an author owns.
	 *
	 * @return void
	 */
	public function test_the_default_footer_copy_names_the_company()
	{
		$this->assertSame(
			'Powered by Smash Balloon',
			SBR_Trust_Badge_Store::defaults()['footer_text'],
			'A new badge must start with the attribution the renderer actually prints.'
		);
		$this->assertStringNotContainsString(
			'Reviews Feed',
			SBR_Trust_Badge_Store::defaults()['footer_text'],
			'Naming the plugin here is the pre-2026-09-01 copy; it drifts from Review Alerts.'
		);
	}

	public function test_tier_forced_attribution_beats_the_authors_line()
	{
		$html = $this->render(array(
			'powered_by_forced' => true,
			'footer_text'       => 'Trusted by Acme',
		));

		$this->assertStringContainsString('Powered by', $html);
		$this->assertStringNotContainsString('Trusted by Acme', $html, 'A forced attribution must not be replaceable by author copy.');
	}

	public function test_author_line_wins_when_the_tier_does_not_force_it()
	{
		$html = $this->render(array(
			'powered_by_forced' => false,
			// True by default on every saved badge — the value that made the
			// attribution win and hid the author's line.
			'show_powered_by'   => true,
			'footer_text'       => 'Trusted by Acme',
		));

		$this->assertStringContainsString('Trusted by Acme', $html);
		$this->assertStringNotContainsString('Powered by', $html);
	}

	public function test_explicit_shortcode_opt_in_with_no_author_line_gets_attribution()
	{
		// `[reviews-badge shape="card" powered_by="true"]` — the inline rail
		// passing the flag DELIBERATELY, with no footer_text of its own.
		//
		// A saved badge cannot reach this state: render_saved() passes
		// powered_by => false precisely because the store defaults it to true,
		// which would have made every badge with its footer switched off grow an
		// attribution strip nobody asked for.
		$html = $this->render(array(
			'powered_by_forced' => false,
			'show_powered_by'   => true,
			'footer_text'       => '',
		));

		$this->assertStringContainsString('Powered by', $html);
	}

	/**
	 * A saved badge with its footer off shows NO strip.
	 *
	 * The state that made the branch above dangerous: the store defaults
	 * powered_by to true, so passing it through counted every default badge as
	 * having opted in. render_saved() is what stops that, and this pins the
	 * decision it makes.
	 *
	 * @return void
	 */
	public function test_saved_badge_with_footer_off_shows_nothing()
	{
		$html = $this->render(array(
			'powered_by_forced' => false,
			// What render_saved() passes for a saved badge, whatever the store
			// happens to have stored.
			'show_powered_by'   => false,
			'footer_text'       => '',
		));

		$this->assertSame('', trim($html));
	}

	public function test_nothing_asked_for_renders_no_strip()
	{
		$this->assertSame('', trim($this->render(array())));
	}

	public function test_author_line_is_linked_safely_when_a_url_is_set()
	{
		$html = $this->render(array(
			'footer_text' => 'Trusted by Acme',
			'footer_url'  => 'https://example.test/about',
		));

		$this->assertStringContainsString('https://example.test/about', $html);
		// Author-controlled outbound link on every page the badge sits on —
		// exactly the shape a link scheme takes.
		$this->assertStringContainsString('nofollow', $html);
		$this->assertStringContainsString('noopener', $html);
	}

	/**
	 * A dangerous scheme in the footer URL does not survive.
	 *
	 * Asserted rather than assumed, because the shim this runs against decides
	 * the answer: the first esc_url() stub was filter_var(FILTER_SANITIZE_URL),
	 * which strips neither javascript: nor data: — so a test naming this attack
	 * would have passed while letting it straight through.
	 *
	 * @return void
	 */
	public function test_a_javascript_url_is_stripped_rather_than_linked()
	{
		$html = $this->render(array(
			'footer_text' => 'Trusted by Acme',
			'footer_url'  => 'javascript:alert(1)',
		));

		$this->assertStringNotContainsString('javascript:', $html);
	}

	public function test_author_line_is_escaped()
	{
		$html = $this->render(array(
			'footer_text' => '<script>alert(1)</script>',
		));

		$this->assertStringNotContainsString('<script>', $html);
	}

	/**
	 * Forced attribution with no URL is text, not a link to nowhere.
	 *
	 * tb-view-defaults.php defaults `powered_by_url` to '' — the renderer
	 * supplies the real address — and esc_url('') gives href="", which resolves
	 * to the current page. On a badge that is a link that silently reloads
	 * whatever it is embedded in.
	 *
	 * @return void
	 */
	public function test_forced_attribution_with_no_url_renders_unlinked()
	{
		$html = $this->render(
			array(
				'powered_by_forced' => true,
				'powered_by_url'    => '',
			)
		);

		$this->assertStringContainsString('Powered by Smash Balloon', $html);

		$this->assertStringNotContainsString('href=""', $html, 'href="" reloads the embedding page');
		$this->assertStringNotContainsString('<a ', $html, 'with no URL there is nothing to link to');
	}

	/**
	 * The linked case, so the test above is not passing on a broken template.
	 *
	 * @return void
	 */
	public function test_forced_attribution_with_a_url_is_still_a_link()
	{
		$html = $this->render(array( 'powered_by_forced' => true ));

		$this->assertStringContainsString('href="https://smashballoon.com/reviews-feed/"', $html);
		$this->assertStringContainsString('rel="nofollow noopener noreferrer"', $html);
	}
}
