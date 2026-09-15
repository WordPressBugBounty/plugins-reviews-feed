<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Image;

/**
 * The email badge is offered only when a PNG can actually be produced (SBR-163).
 *
 * Found on an InstaQA box with GD+FreeType and no Imagick: `build_svg()` returned
 * 493 bytes of valid SVG, `render_png()` returned '', and `?sb-badge=<id>` answered
 * **503 with an empty body** for every badge. `snippet()` already refused to emit a
 * broken `<img>` — but `url()` is pure string building, so `imageURL` was set anyway
 * and EmailEmbedView's only CTA is gated on that key. The result was an ENABLED
 * "Copy badge for your signature" over a URL that can never return an image.
 *
 * GD cannot rasterise SVG at all, so `wp_image_editor_supports( 'image/png' )` is
 * the wrong question — it answers **true** on exactly the host where this fails.
 */
class BadgeEmailAvailabilityTest extends TestCase
{
	/**
	 * @return string
	 */
	private function builder()
	{
		$path = dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Trust_Badge_Builder.php';

		$this->assertFileExists($path);

		return (string) file_get_contents($path);
	}

	/**
	 * One method's body, by brace matching.
	 *
	 * Not a regex: the bodies here contain braces, strings and comments, and every
	 * regex that tried to bound them matched either too little or its own comment.
	 *
	 * @param string $src  File contents.
	 * @param string $name Method name.
	 *
	 * @return string Body between the outermost braces, or '' when not found.
	 */
	private function methodBody($src, $name)
	{
		// Tokenised, so a brace inside a string, heredoc or comment cannot move
		// the depth counter and truncate the body — which a character walk did,
		// silently, giving a false pass or a false failure. See
		// tests/Support/PhpSource.php.
		// Leading backslash: these test files are namespaced and the helper is global.
		return \SbrPhpSource::method_body($src, $name);
	}

	/**
	 * Function names actually CALLED in a file, comments and strings excluded.
	 *
	 * @param string $relative Path under the plugin root.
	 *
	 * @return array<int, string>
	 */
	private function calledFunctions($relative)
	{
		$path = dirname(__DIR__, 3) . $relative;

		$this->assertFileExists($path);

		$tokens = token_get_all((string) file_get_contents($path));
		$names  = array();

		foreach ($tokens as $i => $token) {
			if (! is_array($token) || $token[0] !== T_STRING) {
				continue;
			}

			// Followed by '(' — i.e. a call, not a bare identifier.
			for ($j = $i + 1; $j < count($tokens); $j++) {
				$next = $tokens[ $j ];

				if (is_array($next) && in_array($next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true)) {
					continue;
				}

				if ($next === '(') {
					$names[] = $token[1];
				}

				break;
			}
		}

		return array_values(array_unique($names));
	}

	/**
	 * The capability is named, and it asks about Imagick rather than about WP's
	 * image editor.
	 *
	 * @return void
	 */
	public function test_can_rasterise_reports_imagick_specifically()
	{
		$this->assertTrue(
			method_exists(SBR_Badge_Image::class, 'can_rasterise'),
			'callers need a named capability to explain the absence'
		);

		// Tracks the CHAIN, not Imagick alone. It used to be Imagick-only, and
		// widening it is what the GD rung is for — strictly wider, so a host that
		// could draw before still can.
		$this->assertSame(
			SBR_Badge_Image::rasteriser() !== '',
			SBR_Badge_Image::can_rasterise(),
			'can_rasterise() must agree with the rung the chain actually picked'
		);

		$this->assertContains(
			SBR_Badge_Image::rasteriser(),
			array( 'imagick', 'gd', '' ),
			'rasteriser() must name a rung the callers know about'
		);

		// Imagick still wins where it exists, so nothing about the shipped output
		// moves on a host that already had it.
		if (class_exists('\Imagick')) {
			$this->assertSame('imagick', SBR_Badge_Image::rasteriser(), 'Imagick must remain first');
		}

		// The trap this replaced: GD satisfies wp_image_editor_supports() and still
		// cannot draw the badge, so a check built on it reports the feature working.
		//
		// Tokenised, not grepped. The docblock on can_rasterise() NAMES that function
		// to explain why it is wrong, so a string match reports the comment and the
		// assertion passes or fails for the wrong reason — it caught this test out on
		// its first run.
		$called = $this->calledFunctions('/class/Common/Badges/SBR_Badge_Image.php');

		// Prove the tokeniser SEES calls before trusting it not to see one.
		// assertNotContains on an empty array passes for free.
		$this->assertContains('class_exists', $called, 'the tokeniser found no calls — the negative below would be vacuous');
		$this->assertGreaterThan(20, count($called), 'suspiciously few calls parsed');

		$this->assertNotContains(
			'wp_image_editor_supports',
			$called,
			'the capability must not be answered by WP\'s image editor — GD passes it and cannot rasterise SVG'
		);
	}

	/**
	 * render_png() must route through the named capability, so there is one
	 * source of truth rather than an inline class_exists the callers cannot see.
	 *
	 * @return void
	 */
	public function test_render_png_uses_the_named_capability()
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Image.php'
		);

		// Asserted as a ROUTE rather than as the shape of the first line. The old
		// version pinned `if (! self::can_rasterise())` with a regex, which broke the
		// moment the gate became a three-rung dispatch — pinning the letter of an
		// implementation rather than what it has to do.
		$body = $this->methodBody($src, 'render_png');

		$this->assertNotSame('', $body, 'render_png() body not found — this test needs repointing');

		$this->assertStringContainsString(
			'self::rasteriser()',
			$body,
			'render_png must ask the named capability which rung to use'
		);

		$this->assertStringNotContainsString(
			"class_exists('",
			$body,
			'render_png must not re-derive the capability with its own class_exists'
		);

		// The order is the decision, not an accident: Imagick, then GD.
		$chain = $this->methodBody($src, 'rasteriser');

		$this->assertNotSame('', $chain, 'rasteriser() body not found');

		$imagick = strpos($chain, "'imagick'");
		$gd      = strpos($chain, "'gd'");

		$this->assertTrue(
			false !== $imagick && false !== $gd,
			'both rungs must appear in the chain'
		);

		$this->assertTrue(
			$imagick < $gd,
			'the chain must be Imagick, then GD — see .claude/qa/gd-vs-imagick for why'
		);

		// And it ends there. A rung that ships the SVG off-site was tried and
		// dropped: it needs a rendering surface nobody can bound from the plugin,
		// and these two already cover effectively every host.
		$this->assertStringNotContainsString(
			'relay',
			strtolower($chain),
			'the chain must not grow a remote rung without revisiting that decision'
		);
	}

	/**
	 * An Imagick that cannot draw must not end the chain.
	 *
	 * rasteriser() can only ask whether the Imagick CLASS exists — not whether
	 * this build reads SVG. A policy.xml with the SVG/MSVG coder disabled is
	 * standard hardening after CVE-2016-3714, and on such a host
	 * render_png_imagick() throws, caught, and returns ''. That used to end
	 * render_png(): the email badge was unavailable while GD sat there able to
	 * draw it. With NO Imagick the same host works, because rasteriser() picks
	 * GD — so the failure was worse for having Imagick installed.
	 *
	 * Structural because the branch needs an Imagick build that loads and then
	 * refuses SVG, which no machine running this suite has. The mutation that
	 * restores `return ''` is what this catches.
	 *
	 * @return void
	 */
	public function test_an_imagick_that_cannot_draw_falls_through_to_gd()
	{
		$src  = (string) file_get_contents(dirname(__DIR__, 3) . '/class/Common/Badges/SBR_Badge_Image.php');
		$body = $this->methodBody($src, 'render_png');

		$this->assertNotSame('', $body, 'render_png() body not found');

		$imagick_at = strpos($body, 'render_png_imagick');
		$this->assertNotFalse($imagick_at, 'the Imagick rung is gone');

		$after = substr($body, $imagick_at);

		// The result has to be examined rather than returned straight out.
		$this->assertStringContainsString(
			"if (\$png !== '') {",
			$after,
			'render_png() returns the Imagick result unconditionally, so a build that '
			. 'cannot read SVG ends the chain instead of handing over to GD'
		);

		// And GD has to be the thing it hands to.
		$this->assertStringContainsString(
			'SBR_Badge_Gd::available()',
			$after,
			'nothing consults GD after Imagick fails'
		);

		$this->assertStringContainsString(
			'SBR_Badge_Gd::render(',
			$after,
			'GD is consulted but never asked to draw'
		);
	}

	/**
	 * Both payload sites gate the URL on the snippet.
	 *
	 * Asserted on the source because both live inside handlers that end in
	 * wp_send_json_*(), which exits and the unit shim cannot represent.
	 *
	 * @return void
	 */
	public function test_both_payload_sites_gate_the_url_on_a_real_render()
	{
		$src = $this->builder();

		// Two: the page-load payload and the save response.
		$this->assertSame(
			2,
			preg_match_all('/\$snippet === \'\'\s*\?\s*\'\'\s*:\s*SBR_Badge_Image::url\(/', $src),
			'both the page-load payload and the save response must gate imageURL on the snippet'
		);

		// And neither may hand out an ungated URL any more.
		$this->assertSame(
			0,
			preg_match_all('/\[\'imageURL\'\]\s*=\s*SBR_Badge_Image::url\(/', $src),
			'an ungated imageURL is the defect: the CTA goes live over a URL that 503s'
		);
	}

	/**
	 * And the reason is carried, so the view can say why rather than present a
	 * dead control.
	 *
	 * @return void
	 */
	public function test_the_blocked_reason_is_sent_and_distinguishes_the_two_causes()
	{
		$src = $this->builder();

		$this->assertSame(
			2,
			preg_match_all('/\[\'emailBlocked\'\]\s*=\s*self::email_blocked_reason\(\$snippet\)/', $src),
			'both payload sites must carry the reason'
		);

		$reason = new \ReflectionMethod(
			'SmashBalloon\Reviews\Common\Badges\SBR_Trust_Badge_Builder',
			'email_blocked_reason'
		);
		$reason->setAccessible(true);

		$this->assertSame(
			'',
			$reason->invoke(null, '<img src="x">'),
			'a snippet that rendered means the route is available'
		);

		// The two causes need different remedies — a hosting request vs our bug —
		// so they must not collapse into one string.
		$blocked = $reason->invoke(null, '');
		$this->assertContains(
			$blocked,
			array( 'imagick', 'render' ),
			'an empty snippet must name a cause'
		);
		$this->assertSame(
			SBR_Badge_Image::can_rasterise() ? 'render' : 'imagick',
			$blocked,
			'the cause must follow the actual capability of this host'
		);
	}
}
