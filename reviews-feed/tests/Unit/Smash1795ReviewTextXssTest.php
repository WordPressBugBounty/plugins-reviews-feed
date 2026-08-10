<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Common\Util;

/**
 * SMASH-1795 — stored XSS through a review body's emoji alt attribute.
 *
 * The chain that was confirmed executing on a live page before this fix:
 *
 *   1. a review body reaches storage carrying `<img class="emoji" alt="…">`
 *   2. Parser::get_text() html_entity_decode()s on the way out, re-arming any
 *      entity-encoded markup a sanitiser had neutralised
 *   3. wp_kses_post() at the template keeps img + class + src + alt
 *   4. the feed script's stripEmojihtml() does
 *      `.replaceWith($(this).attr('alt'))`, and jQuery parses that string as HTML
 *
 * Every link is closed independently, so no single regression re-opens it. The JS
 * half (step 4) lives in assets/js/sbr-feed.js and its .min twin and is covered by
 * the live PoC recorded on the ticket rather than here.
 */
class Smash1795ReviewTextXssTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		// sbr_kses_review_text() is a global helper in sbr-functions.php. The
		// bootstrap already provides the stubs that make this file requirable —
		// same pattern as SiteMigrationRecoveryTest.
		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';
	}

	/** The alt payload the audit used, and the shape it takes once stored. */
	private const EMOJI_PAYLOAD = '<img class="emoji" alt="&lt;img src=x onerror=alert(1)&gt;"> nice place';

	// ---------- step 3: the render allowlist ----------

	public function test_review_text_allowlist_strips_img(): void
	{
		$out = sbr_kses_review_text(self::EMOJI_PAYLOAD);

		$this->assertStringNotContainsString('<img', $out, 'an img must never survive into a rendered review body');
		$this->assertStringNotContainsString('emoji', $out);
		$this->assertStringContainsString('nice place', $out, 'the prose around it must survive');
	}

	public function test_review_text_allowlist_keeps_the_emoji_when_it_drops_the_img(): void
	{
		// Dropping <img> must not silently delete a staticized emoji. The allowlist
		// resolves the alt to text first — the server-side twin of stripEmojihtml() —
		// so the glyph shows instead of vanishing.
		$out = sbr_kses_review_text('Great stay <img class="emoji" alt="😀" src="s.png"> thanks');
		$this->assertStringNotContainsString('<img', $out);
		$this->assertStringContainsString('😀', $out, 'the emoji must survive as its glyph');
		$this->assertStringContainsString('Great stay', $out);

		// A single-quoted alt and an extra class must resolve too.
		$this->assertStringContainsString(
			'🎉',
			sbr_kses_review_text("<img src='s.png' class='wp-smiley emoji' alt='🎉'>")
		);

		// An emoji img with no alt leaves nothing behind — not the literal string
		// "undefined", which is the JS-side trap the `|| ''` guard exists for.
		$none = sbr_kses_review_text('a <img class="emoji" src="s.png"> b');
		$this->assertStringNotContainsString('undefined', $none);
		$this->assertStringNotContainsString('<img', $none);

		// And the alt lands as TEXT, never markup — this is the payload the ticket is
		// about. The word "onerror" is still PRESENT in the output and that is correct:
		// it is inside an escaped string, so the assertion has to be that nothing is a
		// live tag, not that the substring is absent.
		$evil = sbr_kses_review_text('<img class="emoji" alt="&lt;img src=x onerror=alert(1)&gt;"> hi');
		$this->assertStringNotContainsString('<', $evil, 'the resolved alt must contain no live markup at all');
		$this->assertStringContainsString('&lt;img', $evil, 'it must be present, escaped');
		$this->assertStringContainsString('hi', $evil);
	}

	public function test_review_text_allowlist_strips_script_and_handlers(): void
	{
		$this->assertStringNotContainsString('<script', sbr_kses_review_text('<script>alert(1)</script>hi'));
		$this->assertStringNotContainsString('<iframe', sbr_kses_review_text('<iframe src="//evil"></iframe>hi'));
		$this->assertStringNotContainsString('<svg', sbr_kses_review_text('<svg onload=alert(1)></svg>hi'));
		// Links ARE allowed — WooCommerce/EDD bodies legitimately contain them — but
		// only with a safe protocol. wp_kses() drops the href rather than the tag.
		$js = sbr_kses_review_text('<a href="javascript:alert(1)">x</a>');
		$this->assertStringNotContainsString('javascript:', $js, 'a javascript: href must not survive');
		$ok = sbr_kses_review_text('<a href="https://example.test/p" title="t">shop</a>');
		$this->assertStringContainsString('https://example.test/p', $ok, 'a normal link must survive');

		// Attributes must go too, not just disallowed tags. An allowlist entry with an
		// empty attribute array means "this tag, bare" — a span that keeps an event
		// handler is a sink even though the tag itself is permitted.
		$span = sbr_kses_review_text('<span onmouseover="alert(1)" style="x">hi</span>');
		$this->assertStringContainsString('hi', $span);
		$this->assertStringNotContainsString('onmouseover', $span);
		$this->assertStringNotContainsString('style', $span);
	}

	public function test_review_text_allowlist_keeps_the_formatting_reviews_actually_use(): void
	{
		// nl2br() output and light emphasis are what the templates rely on. Narrowing
		// the allowlist must not cost line breaks — that would be a visible regression
		// on every multi-paragraph review.
		$out = sbr_kses_review_text('First line.<br />Second line. <strong>great</strong> and <em>lovely</em>');

		$this->assertStringContainsString('<br', $out);
		$this->assertStringContainsString('<strong>great</strong>', $out);
		$this->assertStringContainsString('<em>lovely</em>', $out);
	}

	public function test_review_text_allowlist_handles_non_string_input(): void
	{
		$this->assertSame('', sbr_kses_review_text(null));
		$this->assertSame('', sbr_kses_review_text(''));
		$this->assertSame('', sbr_kses_review_text([]));
	}

	public function test_review_text_allowlist_ignores_a_non_array_filter_return(): void
	{
		// wp_kses() treats a STRING second argument as a CONTEXT NAME, so a filter
		// returning 'post' resolves $allowedposttags — which keeps <img class src alt>
		// and re-opens this exact chain. Verified against WordPress:
		// wp_kses('<img class="emoji" src="x" alt="pwn">', 'post') returns it intact.
		// Caught in review. The helper must fall back to its own default instead.
		// add_filter() is a no-op in this harness; the bootstrap injects filter returns
		// through $wp_filter_mock instead.
		global $wp_filter_mock;

		$baseline = sbr_kses_review_text(self::EMOJI_PAYLOAD);
		$this->assertStringNotContainsString('<img', $baseline);

		foreach (['post', 'strip', '', 0] as $bad) {
			$wp_filter_mock['sbr_allowed_review_text_tags'] = $bad;
			try {
				$out = sbr_kses_review_text(self::EMOJI_PAYLOAD);
			} finally {
				unset($wp_filter_mock['sbr_allowed_review_text_tags']);
			}

			// Compared against the UNFILTERED output rather than against a hardcoded
			// expectation, so the assertion holds whatever the harness's wp_kses()
			// does with a string second argument. What it pins is ours: a non-array
			// return must be discarded, leaving behaviour identical to no filter.
			$this->assertSame(
				$baseline,
				$out,
				'a non-array filter return must be discarded, not passed to wp_kses() as a context'
			);
		}
	}

	public function test_review_text_allowlist_does_not_allow_target(): void
	{
		// target without a forced rel="noopener" hands the opened page a window.opener
		// handle, and a review body has no reason to retarget the window. WordPress's
		// own comment allowlist omits it too.
		$out = sbr_kses_review_text('<a href="https://example.test" target="_blank">x</a>');

		$this->assertStringContainsString('https://example.test', $out);
		$this->assertStringNotContainsString('target', $out);
	}

	// ---------- step 2: the read path no longer re-arms encoded markup ----------

	public function test_get_text_does_not_re_decode_stored_markup(): void
	{
		// Entities are decoded ONCE, at ingest. get_text() used to decode a SECOND
		// time, which re-armed a stored `&lt;img …&gt;` into live markup after the
		// write path had already accepted it as inert text — that second decode is
		// what made the double-encoded payload work.
		//
		// This is a regression guard: re-introducing the decode here re-opens the
		// vector for every writer that isn't covered on the write side, and there are
		// several (Woo/EDD pass comment_content straight into $review['text']).
		$parser = new Parser();
		$out    = $parser->get_text(['text' => '&lt;img src=x onerror=alert(1)&gt; hello']);

		$this->assertStringNotContainsString('<img', $out, 'get_text() must not re-decode stored markup');
		$this->assertStringContainsString('&lt;img', $out, 'the encoded form must survive verbatim');

		// And even if it somehow did arrive decoded, the render allowlist is the
		// second, independent layer that disarms it. Both must hold.
		$rendered = sbr_kses_review_text('<img src=x onerror=alert(1)> hello');
		$this->assertStringNotContainsString('<img', $rendered);
		$this->assertStringNotContainsString('onerror', $rendered);
		$this->assertStringContainsString('hello', $rendered);
	}

	public function test_get_reviewer_name_does_not_re_decode_either(): void
	{
		// Same contract as the body: decoded once at ingest, never again on read.
		// The name is the more dangerous of the two because every consumer runs it
		// through esc_html() — a second decode there produced live markup from a
		// value the write path had neutralised.
		$parser = new Parser();

		$this->assertSame(
			'S&oslash;ren Kj&aelig;rgaard',
			$parser->get_reviewer_name(['reviewer' => ['name' => 'S&oslash;ren Kj&aelig;rgaard']]),
			'get_reviewer_name() must return the stored value verbatim'
		);
		// Real UTF-8, which is what the ingest decode actually produces, is untouched.
		$this->assertSame(
			'Søren Kjærgaard',
			$parser->get_reviewer_name(['reviewer' => ['name' => 'Søren Kjærgaard']])
		);
		$this->assertSame('', $parser->get_reviewer_name([]));
	}

	public function test_every_template_escapes_the_reviewer_name(): void
	{
		// Globbed, not a hardcoded pair. The earlier version listed pro/author.php and
		// lite/author.php by hand, so it structurally could not catch a third consumer —
		// and there is one: pro/post-elements/media.php builds an aria-label from the
		// name and escapes it with esc_attr(), which a same-line esc_html() rule would
		// have failed. Caught in review.
		$root = dirname(__DIR__, 2);
		$files = $this->templateFiles($root . '/templates');
		$this->assertNotEmpty($files, 'no templates found — the glob is wrong');

		$seen = 0;
		foreach ($files as $path) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local template on disk
			$src = (string) file_get_contents($path);
			if (strpos($src, 'get_reviewer_name') === false) {
				continue;
			}
			$rel = str_replace($root . '/', '', $path);

			foreach (explode("\n", $src) as $lineNo => $line) {
				if (strpos($line, 'get_reviewer_name') === false) {
					continue;
				}
				$seen++;

				// esc_html for text context, esc_attr for attribute context.
				if (preg_match('/esc_(html|attr)(_e|__)?\s*\(/', $line) === 1) {
					continue;
				}

				// Assign-then-escape is equally valid and is what media.php does: the
				// name goes into $sb_media_label on one line and is escaped with
				// esc_attr() on another.
				//
				// Deliberately strict about what counts. `=(?!=)` so a comparison
				// (`==`, `!=`, `>=`) is not mistaken for an assignment, and the line
				// must not itself emit — otherwise a template could echo the name raw
				// on one line, call esc_attr() on the same variable somewhere else, and
				// still pass the guard that exists to catch exactly that.
				$assigns = preg_match('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)/', $line, $assign) === 1;
				$emits   = preg_match('/(\becho\b|\bprint\b|<\?=)/', $line) === 1;
				if (
					$assigns && ! $emits
					&& preg_match('/esc_(html|attr)(_e|__)?\s*\(\s*' . preg_quote($assign[1], '/') . '\b/', $src) === 1
				) {
					continue;
				}

				$this->fail(
					"{$rel} line " . ($lineNo + 1) . ' uses the reviewer name without esc_html()/esc_attr(),'
					. ' directly or via an escaped variable'
				);
			}
		}

		$this->assertGreaterThan(0, $seen, 'no template renders the reviewer name — the check is vacuous');
	}

	/**
	 * @return list<string>
	 */
	private function templateFiles(string $dir): array
	{
		$out = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->isFile() && $file->getExtension() === 'php') {
				$out[] = $file->getPathname();
			}
		}
		sort($out);
		return $out;
	}

	public function test_get_text_leaves_accented_characters_alone(): void
	{
		$parser = new Parser();

		// This is the case the Feb-2026 Danish-characters fix was about, and the one
		// the read-path decode removal must not regress: real UTF-8 in, real UTF-8 out.
		// The ingest decode is what produces this shape, so it is what reaches render.
		$this->assertSame(
			'Smørrebrød i København – café æøå',
			$parser->get_text(['text' => 'Smørrebrød i København – café æøå'])
		);
	}

	public function test_get_text_still_returns_empty_for_missing_body(): void
	{
		$parser = new Parser();
		$this->assertSame('', $parser->get_text([]));
		$this->assertSame('', $parser->get_text(['text' => '']));
	}

	// ---------- step 1: the shortcode writer ----------



	public function test_render_pipeline_keeps_danish_characters_readable(): void
	{
		// End-to-end BC for the Danish-character path. Two shapes have to survive the
		// whole read+render chain, and they survive by different routes:
		//
		//   real UTF-8 (what ingest stores today) — passes through untouched
		//   entity-encoded (rows cached before the Feb-2026 ingest decode landed in
		//   v2.4.5) — kses leaves a valid entity alone and the BROWSER renders it, so
		//   `sm&oslash;rrebr&oslash;d` still displays as smørrebrød without the read
		//   decode. What it must never become is double-encoded (`&amp;oslash;`),
		//   which is what would show the entity literally on screen.
		$parser = new Parser();

		$utf8 = sbr_kses_review_text(nl2br($parser->get_text([
			'text' => 'Café & bar — smørrebrød',
		])));
		$this->assertStringContainsString('smørrebrød', $utf8);

		$encoded = sbr_kses_review_text(nl2br($parser->get_text([
			'text' => 'Caf&eacute; &amp; bar &mdash; sm&oslash;rrebr&oslash;d',
		])));
		$this->assertStringContainsString('sm&oslash;rrebr&oslash;d', $encoded);
		$this->assertStringNotContainsString('&amp;oslash;', $encoded, 'must not double-encode');
	}

	public function test_avatar_sanitiser_delegates_instead_of_hand_rolling(): void
	{
		// What this test can and cannot prove, stated plainly: the unit harness has no
		// WordPress, so esc_url_raw() here is a stub. Asserting "javascript: is blocked"
		// against it would only prove the stub blocks it. That security property belongs
		// to WordPress and is verified out-of-band, against the real thing:
		//
		//   wp eval: esc_url_raw('javascript:alert(1)')          => ''
		//            esc_url_raw('data:text/html;base64,x')      => ''
		//            esc_url_raw("java\tscript:alert(1)")        => ''
		//            esc_url_raw('jav&#x0A;ascript:alert(1)')    => ''
		//            esc_url_raw('jav&amp;#x0A;ascript:alert(1)')=> ''
		//            esc_url_raw('/wp-content/a.jpg')            => '/wp-content/a.jpg'
		//   (.claude/bin/verify-smash-1795-wp reruns exactly these.)
		//
		// What IS ours to test is that we delegate rather than second-guessing: an
		// earlier version of this helper hand-rolled a scheme regex and returned
		// scheme-less values untouched, which let both entity-obfuscated forms through.
		// So assert equivalence with the delegation, on payloads where a bypass branch
		// would diverge. This fails against that earlier implementation.
		$payloads = [
			'https://example.test/a.png',
			'/wp-content/uploads/a.jpg',
			'https://example.test/my%20photo.jpg',
			'javascript:alert(1)',
			'data:text/html;base64,x',
			"java\tscript:alert(1)",
			'jav&#x0A;ascript:alert(1)',
			'jav&amp;#x0A;ascript:alert(1)',
			'wp-content/uploads/a.jpg',
		];

		foreach ($payloads as $payload) {
			$this->assertSame(
				esc_url_raw(trim(wp_strip_all_tags($payload))),
				Util::sanitize_avatar_url($payload),
				"sanitize_avatar_url() must defer to esc_url_raw(), with no bypass branch: {$payload}"
			);
		}

		// And the part that is genuinely ours: narrowing mixed to a string without a
		// blind cast, so an array or object can never reach a sanitiser.
		$this->assertSame('', Util::sanitize_avatar_url(['nope']));
		$this->assertSame('', Util::sanitize_avatar_url(null));
		$this->assertSame('', Util::sanitize_avatar_url(new \stdClass()));
	}

	// ---------- the shortcode writer ----------

	/**
	 * `[reviews-feed name="…" content="…"]` is Contributor-authorable and never
	 * reaches cache_single_review(), so it carries its own sanitisers.
	 *
	 * @param array<string,mixed> $atts
	 * @return array<string,mixed>
	 */
	private function shortcodeReview(array $atts): array
	{
		$service = (new \ReflectionClass(\SmashBalloon\Reviews\Common\Services\ShortcodeService::class))
			->newInstanceWithoutConstructor();
		$settings = $service->get_single_manual_review_content($atts);

		return $settings['singleManualReviewContent'];
	}

	public function test_shortcode_attributes_are_sanitised(): void
	{
		$out = $this->shortcodeReview([
			'content'  => '<img class="emoji" alt="&lt;img src=x onerror=alert(1)&gt;"> lovely',
			'name'     => '<script>alert(1)</script>Mallory',
			'avatar'   => 'javascript:alert(1)',
			'provider' => 'wordpress.org',
		]);

		$this->assertStringNotContainsString('<img', $out['content']);
		$this->assertStringContainsString('lovely', $out['content']);
		$this->assertStringNotContainsString('<script', $out['name']);
		$this->assertStringNotContainsString('javascript:', $out['avatar']);
	}

	public function test_shortcode_content_keeps_light_formatting(): void
	{
		// BC: sanitize_textarea_field() returns 'It was great and fast' — an existing
		// testimonial silently loses its emphasis. Verified against WordPress.
		// The render allowlist drops the <img> that carries the payload while keeping
		// the formatting reviews actually use, so it is the right tool here.
		$out = $this->shortcodeReview(['content' => 'It was <strong>great</strong> and <em>fast</em>']);

		$this->assertStringContainsString('<strong>great</strong>', $out['content']);
		$this->assertStringContainsString('<em>fast</em>', $out['content']);
	}

	public function test_shortcode_provider_slug_keeps_its_dot(): void
	{
		// sanitize_key('wordpress.org') === 'wordpressorg', which matches no provider:
		// the slug is 'wordpress.org' verbatim in WordpressOrg::$name and in the
		// literal comparisons at RemoteRequest:189, Util:274/:319 and
		// SBR_Feed_Saver_Manager:743. Verified against WordPress.
		$out = $this->shortcodeReview(['provider' => 'wordpress.org']);

		$this->assertSame('wordpress.org', $out['provider']);
	}

	public function test_shortcode_provider_slug_is_lowercased(): void
	{
		// Every downstream provider check is a lowercase case-sensitive literal
		// (Parser:303,309; Feed:786,798,905,1177; text.php:22,50) with no strtolower()
		// on the path, so `provider="Google"` must not reach them as-is or it silently
		// matches nothing. sanitize_key() lowercased; a plain sanitize_text_field()
		// swap would have dropped that. Caught in review.
		$this->assertSame('google', $this->shortcodeReview(['provider' => 'Google'])['provider']);
		$this->assertSame('wordpress.org', $this->shortcodeReview(['provider' => 'WordPress.ORG'])['provider']);
	}

	public function test_shortcode_provider_slug_cannot_traverse_the_icon_path(): void
	{
		// FeedDisplay::provider_icon_url() concatenates the provider straight into
		// `assets/icons/{$provider}-provider.svg`, so a Contributor-authorable
		// attribute reaching it unfiltered is an attacker-chosen <img src>.
		// Caught in review.
		foreach (['../../uploads/x', '../../../wp-config', 'a/../../b'] as $payload) {
			$slug = $this->shortcodeReview(['provider' => $payload])['provider'];
			$this->assertStringNotContainsString('/', $slug, "slash must not survive in `{$payload}`");
			$this->assertStringNotContainsString('..', $slug, "traversal must not survive in `{$payload}`");
		}
	}

	public function test_shortcode_preserves_facebook_rating_sentinels(): void
	{
		// absint('positive') === 0, and Parser::get_rating() maps a falsy rating to 1,
		// so coercing the sentinel turns a 5-star recommendation into 1 star.
		$this->assertSame('positive', $this->shortcodeReview(['rating' => 'positive'])['rating']);
		$this->assertSame('negative', $this->shortcodeReview(['rating' => 'negative'])['rating']);
		$this->assertSame(5, $this->shortcodeReview(['rating' => 5])['rating']);
	}

	public function test_shortcode_rating_falls_back_to_absint_not_zero(): void
	{
		// Collapsing an unparseable rating to 0 is the SAME silent downgrade the
		// sentinels above exist to prevent — Parser::get_rating() maps a falsy rating
		// to 1 star — and absint() is what this replaced, so anything it used to
		// salvage must still be salvaged. Caught in review.
		$this->assertSame(3, $this->shortcodeReview(['rating' => '3 stars'])['rating']);
		// is_numeric('5 ') is FALSE on PHP 7.4, the declared floor, so a trailing space
		// takes the fallback branch there and must not become 1 star.
		$this->assertSame(5, (int) $this->shortcodeReview(['rating' => '5 '])['rating']);
		// Genuinely unusable input still lands on 0, exactly as absint() always did.
		$this->assertSame(0, $this->shortcodeReview(['rating' => 'lovely'])['rating']);
	}

	public function test_shortcode_keeps_a_date_string_time(): void
	{
		// A non-numeric time is a supported shape — the attribute is free-form and
		// FeedDisplay drops the date element entirely on a falsy value, so absint()
		// would make the date disappear rather than merely look odd.
		$this->assertSame('2024-06-01', $this->shortcodeReview(['time' => '2024-06-01'])['time']);
		$this->assertSame(1600000000, $this->shortcodeReview(['time' => 1600000000])['time']);
	}

	public function test_shortcode_avatar_is_url_validated(): void
	{
		$this->assertSame(
			'https://example.test/a.png',
			$this->shortcodeReview(['avatar' => 'https://example.test/a.png'])['avatar']
		);
		$this->assertSame('', $this->shortcodeReview(['avatar' => 'javascript:alert(1)'])['avatar']);
	}

	public function test_shortcode_omitted_attributes_stay_false(): void
	{
		// The `false` sentinel is the pre-existing contract for an absent attribute;
		// sanitising must not turn it into '' or 0.
		$out = $this->shortcodeReview([]);

		foreach (['name', 'content', 'rating', 'avatar', 'time', 'provider'] as $key) {
			$this->assertFalse($out[$key], "an omitted `{$key}` must stay false");
		}
	}

	// ---------- the review-form writer ----------

	public function test_submission_content_is_stored_byte_identically_and_renders_inert(): void
	{
		// Two things have to hold at once here, and an interim version of this fix
		// traded the first away for the second.
		//
		// BC: {prefix}sbr_form_submissions.content is read as PLAIN TEXT by two
		// consumers, so its stored BYTES must not shift.
		//   FormRulesManager::sql_passes_rule():212 builds `AND LENGTH(content) >= N`
		//     for the auto-approve / auto-archive rules, re-evaluated against EXISTING
		//     rows whenever a rule changes — a length shift moves reviews across a
		//     customer's threshold;
		//   SubmissionsManager::transform_to_review() takes substr($content, 0, 40) as
		//     the review title (cited by name, not line: it is in the same file as the
		//     writer under test and shifts every time that file is edited).
		// Adding html_entity_decode() here moved `Fish &amp; Chips` from 16 bytes to 12
		// and `<strong>bold</strong> text` by +17. So the writer is left ALONE.
		//
		// Security: that is safe because nothing decodes on the way out any more, so the
		// encoded payload never becomes an element. Both halves are asserted.
		$store = static function ($content): string {
			$out = \SmashBalloon\Reviews\Pro\Integrations\Forms\SubmissionsManager::get_db_store_data(
				[
					'submission_id' => 'abc',
					'form_id'       => 1,
					'rating'        => 5,
					'content'       => $content,
					'date'          => 1600000000,
					'json_data'     => [],
					'used_in'       => [],
					'archived_in'   => [],
					'deleted_in'    => [],
				],
				['plugin' => 'wpforms', 'id' => 1]
			);
			return $out['content'];
		};

		// BC — byte-identical to sanitize_text_field(), entity-bearing cases included.
		// Entity-free inputs alone would be tautological on old and new code.
		$inputs = [
			'Great stay, would come again',
			'Fish &amp; Chips',
			'Fish & Chips',
			'I love &lt;3 this place!',
			'price &lt; 20 and &gt; 5',
			'Caf&eacute; sm&oslash;rrebr&oslash;d',
			'<strong>bold</strong> text',
			'&lt;img class="emoji" alt="&amp;lt;script&amp;gt;"&gt; nice',
		];
		foreach ($inputs as $in) {
			$this->assertSame(
				sanitize_text_field($in),
				$store($in),
				"stored bytes must not shift for: {$in}"
			);
		}

		// Security — the encoded payload survives storage but is inert once read and
		// rendered, because Parser::get_text() no longer decodes and the allowlist
		// leaves a valid entity as an entity.
		$parser = new Parser();
		foreach (
			['&lt;img class="emoji" alt="&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;"&gt; great place',
			'&lt;script&gt;alert(1)&lt;/script&gt; ok'] as $payload
		) {
			$rendered = sbr_kses_review_text(nl2br($parser->get_text(['text' => $store($payload)])));
			$this->assertStringNotContainsString('<img', $rendered, 'no element may be built from a stored submission');
			$this->assertStringNotContainsString('<script', $rendered);
		}
	}

	// ---------- the JS half, pinned as a file assertion ----------

	public function test_feed_script_and_its_min_twin_never_parse_the_emoji_alt(): void
	{
		// The .min is what the front end loads and there is no build step, so the two
		// files are hand-synced and drift silently. Assert on both.
		//
		// An earlier version of this guard was VACUOUS and passed against un-fixed
		// code: it looked for the bare word "createTextNode", which already appears in
		// an unrelated stylesheet helper, and its negative regex used `\w*` where the
		// real line has `$(this)` — `$` is not a word character, so it never matched.
		// Caught in review. Both assertions below were re-checked against the pre-fix
		// file and do fail on it.
		$root = dirname(__DIR__, 2);

		foreach (['assets/js/sbr-feed.js', 'assets/js/sbr-feed.min.js'] as $rel) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local asset on disk
			$js = (string) file_get_contents($root . '/' . $rel);

			// The dangerous shape: the alt handed straight to replaceWith(), in either
			// the source (`$(this)`) or minified (`t(this)`) form.
			$this->assertDoesNotMatchRegularExpression(
				'/replaceWith\(\s*[\w$]+\(this\)\.attr\(\s*[\x27"]alt[\x27"]\s*\)\s*\)/',
				$js,
				"{$rel} passes the raw emoji alt to replaceWith() — jQuery parses it as HTML"
			);

			// And the safe shape must actually be present on that same call.
			$this->assertMatchesRegularExpression(
				'/replaceWith\(\s*document\.createTextNode\(/',
				$js,
				"{$rel} must build a text node from the emoji alt"
			);

			// The expand path must never blank the card. `.empty()` with no cached nodes
			// wipes the review body, where the old `.html(undefined)` was a jQuery getter
			// and a harmless no-op. jQuery .data() does not survive a .clone() without
			// withDataAndEvents — owl-carousel loop clones — so the nodes can legitimately
			// be absent. Caught in review.
			$this->assertMatchesRegularExpression(
				'/if\s*\([^)]*!\s*[\w$]+\.length\s*\)\s*\{?\s*return/',
				$js,
				"{$rel} must bail instead of emptying the caption when the cached nodes are missing"
			);
			$this->assertDoesNotMatchRegularExpression(
				'/empty\(\)\.append\([^)]*\?[^)]*\.clone\(\)\s*:\s*\[\]\s*\)/',
				$js,
				"{$rel} still uses the ternary that appends nothing — that blanks the review"
			);

			// ORDER matters, not just presence: the bail must come before ANY DOM
			// mutation in the handler. Otherwise the missing-nodes path tears something
			// down and then returns, and the review is left permanently truncated.
			//
			// Asserted structurally and deliberately loosely:
			//   - anchored on the BINDING selector (the bare class also appears in a
			//     commented-out block earlier in the file);
			//   - `//` comments stripped first, or the explanatory comment above the
			//     guard — which mentions `.empty()` — would itself trip the check;
			//   - the `.sbr-expand` selector is NOT named. It is a known typo (the real
			//     class is `sb-expand`) and pinning it would make fixing it fail here;
			//   - no minified identifier is hard-coded, so a re-minify still passes.
			$handlerAt = strpos($js, 'sb-expand button.sb-expand-on-click');
			$this->assertNotFalse($handlerAt, "{$rel} no longer binds the read-more handler");
			$handler = (string) preg_replace('#//[^\n]*#', '', substr($js, $handlerAt, 2500));

			$guardPattern = '/!\s*[\w$]+\.length\s*\)\s*\{?\s*return/';
			$this->assertMatchesRegularExpression(
				$guardPattern,
				$handler,
				"{$rel} read-more handler has no missing-nodes bail"
			);
			preg_match($guardPattern, $handler, $m, PREG_OFFSET_CAPTURE);
			$guardAt = $m[0][1];

			foreach (['.empty(', '.remove('] as $mutation) {
				$at = strpos($handler, $mutation);
				if ($at === false) {
					continue;
				}
				$this->assertLessThan(
					$at,
					$guardAt,
					"{$rel} read-more handler calls {$mutation} before bailing — on the missing-nodes path that mutates the DOM and then returns"
				);
			}

			// The read-more round-trip is the SECOND re-parse sink on this file: it
			// used to serialise the body into a data-text attribute and expand it with
			// .html(), which re-parses. It now keeps detached DOM nodes instead, so
			// neither the attribute write nor the .html() read may come back.
			// Strict substring, not a narrow regex. A loosened version would miss a
			// reintroduction via dataset.text, a concatenated attribute name, or a
			// data-text emitted from a PHP template. Nothing in either file mentions
			// the string any more, so there is no reason to weaken it.
			$this->assertStringNotContainsString(
				'data-text',
				$js,
				"{$rel} must not round-trip the review body through a data-text attribute"
			);
			$this->assertMatchesRegularExpression(
				'/sbrFullText/',
				$js,
				"{$rel} must keep the full review body as detached nodes"
			);
		}
	}
}
