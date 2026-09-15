<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use DOMDocument;
use DOMXPath;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;
use SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Store;

/**
 * The badge markup is safe to inject, and this is what says so (SBR-163).
 *
 * Three `dangerouslySetInnerHTML` sinks render this markup in wp-admin — the badge
 * preview, the email view and the empty-state specimen. Reviewers asked about all
 * three across five rounds, every time with the same fair objection: the safety
 * claim lives in PHP that is not in the JS diff, so it cannot be checked from
 * there, and a comment asserting it is only as good as the last person who read it.
 *
 * Answering "I ran it and it was fine" a fifth time does not fix that. This does:
 * it drives the renderer with hostile values in every author-controlled field and
 * asserts the output cannot execute. If a future edit to the renderer or the
 * templates stops escaping one, this fails instead of the annotation quietly
 * becoming a lie.
 */
class BadgeOutputEscapingTest extends TestCase
{
	/** Payloads that execute if they reach the DOM unescaped. */
	const HOSTILE = array(
		'script'      => '<script>alert(1)</script>',
		'img_onerror' => '<img src=x onerror=alert(1)>',
		'attr_break'  => '" onmouseover="alert(1)" x="',
		'js_url'      => 'javascript:alert(document.cookie)',
		'entity'      => '&lt;script&gt;alert(1)&lt;/script&gt;',
	);

	/**
	 * @param array<string, mixed> $over
	 *
	 * @return array<string, mixed>
	 */
	private function args($over = array())
	{
		return array_merge(array(
			'shape'  => 'card',
			'theme'  => 'light',
			'rating' => true,
			'stars'  => true,
			'count'  => true,
			'logos'  => true,
		), $over);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function aggregate()
	{
		return array(
			'rating'    => 4.6,
			'count'     => 12,
			'resolved'  => true,
			'scale'     => 5,
			'providers' => array(),
		);
	}

	/**
	 * The assertions every rendered badge has to satisfy.
	 *
	 * @param string $html  Rendered markup.
	 * @param string $label Which payload produced it.
	 *
	 * @return void
	 */
	private function assertInert($html, $label)
	{
		// Parsed, not grepped. A substring check on 'onerror=' fails a SAFE output:
		// esc_url() turns `<img src=x onerror=alert(1)>` into
		// `http://img%20src=x%20onerror=alert(1)`, which is an inert href that
		// happens to contain those characters. What matters is structural — did the
		// payload introduce an element or an attribute — so that is what is asserted.
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML('<div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);

		$this->assertTrue($loaded, "{$label}: output must be parseable");

		$xpath = new DOMXPath($dom);

		// `script` is not the only element that executes or navigates. iframe and
		// object run their own document, embed and applet run a plugin, base
		// rewrites every relative URL on the page, form and button give a payload
		// somewhere to submit, and style carries its own url() fetch. A badge
		// renders none of these, so any of them is the payload's work.
		$this->assertSame(
			0,
			(int) $xpath->evaluate(
				'count(//script | //iframe | //object | //embed | //applet | //base'
				. ' | //form | //button | //style | //link | //meta | //svg/foreignObject)'
			),
			"{$label}: an executable or navigating element reached the markup"
		);

		foreach ($xpath->query('//@*') as $attr) {
			$this->assertStringStartsNotWith(
				'on',
				strtolower($attr->nodeName),
				"{$label}: an inline event handler attribute reached the markup"
			);
		}

		// Any URL the payload reached must carry a scheme a browser will not
		// execute. Every attribute a browser will FETCH or NAVIGATE, not just
		// href and src: srcdoc is a whole document, formaction and action are a
		// submit target, and xlink:href is how an SVG use element navigates.
		$sinks = '//@href | //@src | //@srcdoc | //@srcset | //@action | //@formaction'
			. ' | //@data | //@poster | //@background | //@*[local-name() = "href"]';

		foreach ($xpath->query($sinks) as $url) {
			$this->assertDoesNotMatchRegularExpression(
				'#^\s*(javascript|data|vbscript|blob|filesystem)\s*:#i',
				(string) $url->nodeValue,
				"{$label}: an executable URL scheme reached {$url->nodeName}"
			);
		}

		// A style attribute is a fetch and, on old engines, an expression().
		foreach ($xpath->query('//@style') as $style) {
			$this->assertDoesNotMatchRegularExpression(
				'#url\s*\(|expression\s*\(#i',
				(string) $style->nodeValue,
				"{$label}: a style attribute reached the markup carrying a fetch"
			);
		}
	}

	/**
	 * The footer is the one place an author types free text and a URL.
	 *
	 * This is the STORE-plus-renderer path, which is what a real save goes
	 * through. It does not reach the template's own `esc_*` calls — a mutation run
	 * proved that, by removing them and watching this stay green. Those are a
	 * third layer and get their own test below.
	 *
	 * @return void
	 */
	public function test_footer_text_and_url_cannot_execute_through_the_store()
	{
		foreach (self::HOSTILE as $label => $payload) {
			$settings = SBR_Trust_Badge_Store::sanitize_settings(array(
				'footer_text' => $payload,
				'footer_url'  => $payload,
				'show_footer' => true,
				'powered_by'  => true,
			));

			$html = SBR_Badge_Renderer::render(
				$this->args(array(
					'footer_text' => $settings['footer_text'],
					'footer_url'  => $settings['footer_url'],
					'powered_by'  => true,
				)),
				$this->aggregate()
			);

			$this->assertNotSame('', $html, "{$label}: the badge must still render");
			$this->assertInert($html, "footer/{$label}");
		}
	}

	/**
	 * Every other field render() accepts, hostile, in one pass each.
	 *
	 * Named individually rather than swept, so a new field added to the renderer
	 * does not silently inherit "covered".
	 *
	 * @return void
	 */
	public function test_every_author_controlled_argument_is_escaped_at_the_sink()
	{
		// Split by whether the field can survive a hostile value, because
		// assertInert('') passes trivially — an empty render satisfies "no script
		// element, no on* attribute" without the sink having been reached at all.
		// Measured which is which rather than assumed: with `<img src=x
		// onerror=…>` in each, theme/logo_style/logo_color/provider/source all
		// still render (5305-8493 bytes) and only `shape` bails.
		$must_render = array( 'theme', 'logo_style', 'logo_color', 'provider', 'source' );

		foreach ($must_render as $field) {
			foreach (self::HOSTILE as $label => $payload) {
				$html = (string) SBR_Badge_Renderer::render(
					$this->args(array( $field => $payload )),
					$this->aggregate()
				);

				$this->assertNotSame(
					'',
					$html,
					"{$field}/{$label}: the badge must still render, or this proves nothing about escaping"
				);

				$this->assertInert($html, "{$field}/{$label}");
			}
		}
	}

	/**
	 * An unknown shape renders NOTHING, which is its own guarantee.
	 *
	 * Separated from the matrix above rather than excused inside it: a shape the
	 * renderer does not know has no template, and guessing one would publish a
	 * badge nobody asked for. Pinned so the bail cannot quietly become a fallback.
	 *
	 * @return void
	 */
	public function test_an_unknown_shape_refuses_rather_than_guessing()
	{
		foreach (self::HOSTILE as $label => $payload) {
			$html = (string) SBR_Badge_Renderer::render(
				$this->args(array( 'shape' => $payload )),
				$this->aggregate()
			);

			$this->assertSame('', $html, "shape/{$label}: an unknown shape must render nothing");
		}
	}

	/**
	 * The store rejects a `javascript:` footer URL outright, before any render.
	 *
	 * Belt and braces with the output escaping above: this is the layer that means
	 * a bad URL is never even stored.
	 *
	 * @return void
	 */
	public function test_the_store_refuses_a_javascript_url()
	{
		$settings = SBR_Trust_Badge_Store::sanitize_settings(array(
			'footer_url' => 'javascript:alert(document.cookie)',
		));

		$this->assertSame('', $settings['footer_url'], 'a javascript: URL must not survive sanitising');
	}

	/**
	 * And it strips markup out of the footer copy.
	 *
	 * @return void
	 */
	public function test_the_store_strips_markup_from_footer_text()
	{
		$settings = SBR_Trust_Badge_Store::sanitize_settings(array(
			'footer_text' => '<img src=x onerror=alert(1)>',
		));

		$this->assertStringNotContainsString('<img', $settings['footer_text']);
		$this->assertStringNotContainsString('onerror', $settings['footer_text']);
	}

	/**
	 * The footer TEMPLATE, driven directly with hostile view data.
	 *
	 * The only way to reach `tb-footer.php`'s `esc_url()` and `esc_html()`: two
	 * layers sanitise before it — `SBR_Trust_Badge_Store::sanitize_settings()` on
	 * save and `SBR_Badge_Renderer::sanitize_args()` on every render — so nothing
	 * hostile arrives through the public API. That is defence in depth working as
	 * intended, and it also means a test that goes through `render()` cannot prove
	 * the sink escapes anything.
	 *
	 * So this includes the template with a `$sbr_badge` array a future caller could
	 * hand it, which is exactly the contract the admin-side
	 * `dangerouslySetInnerHTML` annotations rest on.
	 *
	 * @return void
	 */
	public function test_the_footer_template_escapes_at_the_sink()
	{
		$path = dirname(__DIR__, 3) . '/templates/frontend/badges/tb-footer.php';

		$this->assertFileExists($path);

		foreach (self::HOSTILE as $label => $payload) {
			$sbr_badge = array(
				'footer_text'       => $payload,
				'footer_url'        => $payload,
				'powered_by_forced' => false,
				'show_powered_by'   => false,
				'powered_by_url'    => $payload,
			);

			ob_start();
			include $path;
			$html = (string) ob_get_clean();

			$this->assertNotSame('', $html, "{$label}: the footer must render its line");
			$this->assertInert($html, "tb-footer/{$label}");
		}
	}

	/**
	 * The forced-attribution branch, which builds its own anchor.
	 *
	 * A different code path from the author-text branch above — it interpolates
	 * `powered_by_url` into an `href` inside a `printf`, so it needs its own pass.
	 *
	 * @return void
	 */
	public function test_the_forced_attribution_branch_escapes_its_url()
	{
		$path = dirname(__DIR__, 3) . '/templates/frontend/badges/tb-footer.php';

		foreach (self::HOSTILE as $label => $payload) {
			$sbr_badge = array(
				'footer_text'       => '',
				'footer_url'        => '',
				'powered_by_forced' => true,
				'show_powered_by'   => true,
				'powered_by_url'    => $payload,
			);

			ob_start();
			include $path;
			$html = (string) ob_get_clean();

			$this->assertNotSame('', $html, "{$label}: the attribution must render");
			$this->assertInert($html, "tb-footer/forced/{$label}");
		}
	}

	/**
	 * The card SHELL's attributes, driven directly with hostile view data.
	 *
	 * `render()` sanitises `theme`, `style` and the rest through `sanitize_args()`
	 * before any template sees them, so the shell's `esc_attr()` calls are the same
	 * third layer as the footer's — reachable only by including the template. A
	 * mutation run proved it: removing `esc_attr()` from `data-theme` left every
	 * render-level test green.
	 *
	 * `show_*` are all false so the partials this file includes contribute nothing;
	 * the shell's own attributes are the subject.
	 *
	 * @return void
	 */
	public function test_the_card_shell_escapes_its_attributes()
	{
		$path = dirname(__DIR__, 3) . '/templates/frontend/badges/card.php';

		$this->assertFileExists($path);

		foreach (array( 'theme', 'classes', 'logos_attr', 'style' ) as $field) {
			foreach (self::HOSTILE as $label => $payload) {
				$sbr_badge = array(
					'classes'           => 'sbr-tb-badge sbr-tb-style-card',
					'theme'             => 'light',
					'logos_attr'        => 'brand',
					'style'             => '',
					'is_placeholder'    => false,
					'is_rated'          => true,
					'rating_label'      => '4.6',
					'count_based_label' => '12 reviews',
					'show_rating'       => false,
					'show_stars'        => false,
					'show_count'        => false,
					'show_logos'        => false,
					'footer_text'       => '',
					'footer_url'        => '',
					'powered_by_forced' => false,
					'show_powered_by'   => false,
					'powered_by_url'    => '',
				);

				$sbr_badge[ $field ] = $payload;

				ob_start();
				include $path;
				$html = (string) ob_get_clean();

				$this->assertNotSame('', $html, "{$field}/{$label}: the card must render");
				$this->assertInert($html, "card/{$field}/{$label}");
			}
		}
	}

	/**
	 * Markup the helper must refuse, one case per branch.
	 *
	 * assertInert() is the whole test file's oracle, and an oracle that accepts
	 * everything makes every assertion above vacuous — a review round pointed out
	 * that it only looked for `script`, `on*` and two URL attributes. So each
	 * branch gets a payload it has to reject, and the case is proven by the
	 * assertion FAILING rather than by reading the code.
	 *
	 * @return void
	 */
	public function test_the_inert_helper_rejects_each_class_of_payload()
	{
		$hostile = array(
			'script element'    => '<script>alert(1)</script>',
			'event handler'     => '<span onmouseover="alert(1)">x</span>',
			'javascript href'   => '<a href="javascript:alert(1)">x</a>',
			'iframe'            => '<iframe src="https://evil.test"></iframe>',
			'object'            => '<object data="https://evil.test"></object>',
			'base'              => '<base href="https://evil.test/">',
			'srcdoc'            => '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
			'formaction'        => '<form><button formaction="javascript:alert(1)">x</button></form>',
			'style url'         => '<span style="background:url(https://evil.test/x)">x</span>',
			'data uri image'    => '<img src="data:text/html;base64,PHNjcmlwdD4=">',
		);

		foreach ($hostile as $label => $html) {
			$refused = false;

			try {
				$this->assertInert($html, $label);
			} catch (\PHPUnit\Framework\AssertionFailedError $e) {
				$refused = true;
			}

			$this->assertTrue($refused, "assertInert() accepted a {$label} — every assertion using it is vacuous for that class");
		}

		// And it still passes what a badge actually renders, or it would be
		// refusing everything and proving nothing.
		$this->assertInert(
			'<span class="sbr-tb-foot"><a href="https://smashballoon.com/" rel="nofollow noopener noreferrer">Smash Balloon</a></span>',
			'a real footer'
		);
	}
}
