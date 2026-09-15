<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SmashBalloon\Reviews\Common\Helpers\SBR_Error_Handler;
use SmashBalloon\Reviews\Common\Integrations\Providers\BaseProvider;
use SmashBalloon\Reviews\Common\Integrations\Providers\Google;
use SmashBalloon\Reviews\Common\Integrations\SBRelay;
use SmashBalloon\Reviews\Common\Util;

/**
 * SMASH-2031 — unauthenticated stored XSS landing on Reviews Feed > Support.
 *
 * Reported by Wordfence 2026-09-02 against 2.11.0. The chain, each link verified
 * against the tag before this fix:
 *
 *   1. BaseProvider::registerRestEndpoint() published GET /SBR/v1/reviews/{provider}
 *      with permission_callback => '__return_true'
 *   2. getAllReviews() forwarded every request param to the relay untouched
 *   3. the relay echoes the caller's place_id back inside its error envelope
 *      (sb-relay app/Traits/CanSendSourcesResponse.php:30)
 *   4. SBRelay::call() passed that envelope to log_error() as-is, persisting it
 *      in the `sbr_errors` option (last 20 entries, so it sticks)
 *   5. Util::get_settings_page_errors() concatenated json_encode($error) into an
 *      HTML string — json_encode() leaves < and > alone without JSON_HEX_TAG
 *   6. the shared customizer renders that string with dangerouslySetInnerHTML
 *      (SupportPage.js:297), so it executed in the admin's session
 *
 * Four guards close this, matching the CHANGELOG's numbering: (1) the route
 * needs a capability, (2) parameters are checked before the relay call,
 * (3) nothing reaches the error log with markup in it, (4) the sink encodes.
 * Each is closed independently, so no single regression re-opens the hole.
 * Guard 4 is the one that also disarms an option poisoned before the update,
 * which is why it is asserted against a payload already sitting in storage
 * rather than one arriving through the endpoint.
 *
 * `errorsList` in the same page payload is NOT a second sink: SupportPage.js
 * renders those fields as React text nodes ({error?.id} etc.), which React
 * escapes. Verified at customizer SupportPage.js:494-527 — the only
 * dangerouslySetInnerHTML fed by error data is supportInfo.
 */
final class Smash2031SupportPageStoredXssTest extends TestCase
{
	/** The Wordfence proof-of-concept payload, as it arrives in place_id. */
	private const PAYLOAD = '<img src=x onerror=alert(document.domain)>';

	/**
	 * The bootstrap's mock state is shared across the whole suite, so this file
	 * borrows it and puts it back rather than wiping it. Clobbering it in
	 * tearDown made the suite order-dependent: under `--order-by=random` this
	 * file perturbed Smash782ExternalRefreshCronTest into "Undefined array key"
	 * warnings, which a default-order run never showed.
	 *
	 * @var array<string, mixed>
	 */
	private $saved_state = [];

	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/class/sbr-functions.php';

		global $wp_options_mock;
		$this->saved_state = [
			'options' => $wp_options_mock,
			'globals' => [],
		];
		foreach (['sbr_test_user_logged_in', 'sbr_test_user_can'] as $key) {
			$this->saved_state['globals'][$key] = array_key_exists($key, $GLOBALS)
				? ['set' => true, 'value' => $GLOBALS[$key]]
				: ['set' => false, 'value' => null];
		}

		$wp_options_mock = [];
		$GLOBALS['sbr_test_user_logged_in'] = false;
		$GLOBALS['sbr_test_user_can'] = false;
	}

	protected function tearDown(): void
	{
		global $wp_options_mock;
		$wp_options_mock = $this->saved_state['options'];

		foreach ($this->saved_state['globals'] as $key => $prior) {
			if ($prior['set']) {
				$GLOBALS[$key] = $prior['value'];
			} else {
				unset($GLOBALS[$key]);
			}
		}

		// One test below seeds mt_srand() for a reproducible fuzz corpus, and
		// PHPUnit runs the suite in one process — so without this every later test
		// inherits that fixed seed. Nothing else here reaches for mt_rand() today,
		// which is exactly what makes it the kind of thing that surfaces later as
		// "only fails in the full run".
		mt_srand();

		parent::tearDown();
	}

	// ---------- guard 4: the sink, which also disarms an already-poisoned option ----------

	public function test_poisoned_error_option_renders_as_inert_text(): void
	{
		global $wp_options_mock;

		// Exactly the shape the relay stores: a success=false envelope whose
		// placeId echoes what the attacker sent.
		$wp_options_mock['sbr_errors'] = [
			[
				'id' => 'sourceConnectionError',
				'code' => 400,
				'message' => 'Unknown reason',
				'placeId' => self::PAYLOAD,
				'provider' => 'google',
			],
		];

		$output = Util::get_settings_page_errors();

		$this->assertStringNotContainsString(
			'<img',
			$output,
			'a stored payload must not reach the Support page as a live tag'
		);

		// The security property, stated exactly: nothing can open a tag. The
		// `</br>` separators this method writes itself are the only markup.
		$this->assertStringNotContainsString(
			'<',
			str_replace('</br>', '', $output),
			'no angle bracket may survive outside the line-break separators'
		);
		$this->assertStringNotContainsString('>', str_replace('</br>', '', $output));
	}

	/**
	 * The payload still has to be *readable*, because Support info exists to be
	 * pasted into a ticket. Neutralised, not deleted — and readable enough that
	 * escaping is not worth trading the Copy output for.
	 */
	public function test_the_payload_is_still_legible_once_neutralised(): void
	{
		global $wp_options_mock;

		$wp_options_mock['sbr_errors'] = [
			['id' => 'sourceConnectionError', 'placeId' => self::PAYLOAD],
		];

		$output = Util::get_settings_page_errors();

		$this->assertStringContainsString('sourceConnectionError', $output, 'the error id must stay greppable');
		$this->assertStringContainsString('onerror', $output, 'the payload must remain diagnosable as text');
		$this->assertStringContainsString(
			'"placeId"',
			$output,
			'JSON structural quotes stay literal, so a pasted log still reads as JSON'
		);
	}

	/**
	 * A payload that arrives already entity-encoded must not be decoded back
	 * into live markup on the way out — the SMASH-1795 re-arming trap.
	 */
	public function test_entity_encoded_payload_is_not_re_armed(): void
	{
		global $wp_options_mock;

		$wp_options_mock['sbr_errors'] = [
			['id' => 'x', 'placeId' => '&lt;script&gt;alert(1)&lt;/script&gt;'],
		];

		$output = Util::get_settings_page_errors();

		$this->assertStringNotContainsString('<script', $output);
		$this->assertStringNotContainsString('</script', $output);
	}

	/**
	 * BC: the customizer's Copy button does
	 * `supportInfo.replaceAll('</br>', "\n")`, and the block is identified by
	 * its header. Escaping the entries must not disturb either.
	 */
	public function test_header_and_line_break_separators_are_preserved(): void
	{
		global $wp_options_mock;

		$wp_options_mock['sbr_errors'] = [['id' => 'a'], ['id' => 'b']];

		$output = Util::get_settings_page_errors();

		$this->assertStringStartsWith('## ERROR LOGS: ## </br></br>', $output);
		$this->assertGreaterThanOrEqual(
			2,
			substr_count($output, '</br></br>'),
			'each entry keeps its own separator so Copy still yields one per line'
		);
		$this->assertIsString($output);
	}

	/** BC: an empty log still returns just the header, as before. */
	public function test_empty_log_still_returns_the_header_only(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_errors'] = [];

		$this->assertSame('## ERROR LOGS: ## </br></br></br>', Util::get_settings_page_errors());
	}

	/**
	 * The error log was not the only contributor rendered raw. Support info is
	 * eight strings concatenated into one `dangerouslySetInnerHTML`
	 * (SBR_Support_Builder::get_support_info()), and several blocks interpolated
	 * provider-supplied values.
	 *
	 * The catch that makes this subtle: the blob reaches the page through
	 * wp_localize_script(), which html_entity_decode()s every scalar
	 * (wp-includes/class-wp-scripts.php:661). So `esc_html()` is undone in
	 * transit and the payload is live again at the sink — verified in a browser
	 * before the fix, where an escaped source name still executed. Every
	 * assertion below therefore applies that decode first; without it a test
	 * passes against code that is still vulnerable, which is exactly what
	 * happened on the first attempt at this fix.
	 */
	public function test_a_hostile_source_name_is_inert_even_after_the_localize_decode(): void
	{
		$restore = self::installSourceRows([
			[
				'name' => 'Hostile <img src=x onerror=alert(1)> Cafe',
				'provider' => 'google',
				'account_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
				// source_query() reads this to decide whether to look up feed
				// instances; a row without it is not a shape the query returns.
				'used_in' => 0,
			],
		]);

		try {
			$out = Util::get_sources_settings_info();
		} finally {
			$restore();
		}

		$this->assertCannotOpenATagAtTheSink($out);
		$this->assertStringContainsString('Hostile', $out, 'the name must still be readable');
	}

	/**
	 * An already entity-encoded name is the case esc_html() cannot survive: the
	 * decode in transit turns `&lt;img …&gt;` back into a tag. Decoding before
	 * stripping is what closes it.
	 */
	public function test_an_entity_encoded_source_name_is_not_revived_in_transit(): void
	{
		$restore = self::installSourceRows([
			[
				'name' => 'Sneaky &lt;img src=x onerror=alert(1)&gt; Cafe',
				'provider' => 'google',
				'account_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
				'used_in' => 0,
			],
		]);

		try {
			$out = Util::get_sources_settings_info();
		} finally {
			$restore();
		}

		$this->assertCannotOpenATagAtTheSink($out);
	}

	/**
	 * One decode is one short of the sink's own. `&amp;lt;img …&amp;gt;` decodes to
	 * `&lt;img …&gt;`, which holds no tag for wp_strip_all_tags() to remove, and
	 * then wp_localize_script() decodes it the rest of the way into live
	 * markup. Storage really does hold values encoded that deeply:
	 * SubmissionsManager keeps submitted entities on purpose (its own comment
	 * says so, deliberately, since SMASH-1795) and get_posts_table_info()
	 * dumps a row verbatim. Hence the decode-to-fixed-point.
	 *
	 * @dataProvider multiplyEncodedPayloads
	 */
	public function test_a_multiply_encoded_payload_cannot_survive_to_the_sink(string $payload): void
	{
		$restore = self::installSourceRows([
			[
				'name' => $payload,
				'provider' => 'google',
				'account_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
				'used_in' => 0,
			],
		]);

		try {
			$out = Util::get_sources_settings_info();
		} finally {
			$restore();
		}

		$this->assertCannotOpenATagAtTheSink($out);
	}

	/** @return array<string, array{0: string}> */
	public static function multiplyEncodedPayloads(): array
	{
		return [
			'one layer' => ['Sneaky &lt;img src=x onerror=alert(1)&gt; Cafe'],
			'two layers' => ['Sneaky &amp;lt;img src=x onerror=alert(1)&amp;gt; Cafe'],
			'three layers' => ['Sneaky &amp;amp;lt;img src=x onerror=alert(1)&amp;amp;gt; Cafe'],
			'numeric ampersand' => ['Sneaky &#38;lt;svg onload=alert(1)&#38;gt; Cafe'],
			'hex ampersand' => ['Sneaky &#x26;lt;svg onload=alert(1)&#x26;gt; Cafe'],
			'ampersand then numeric' => ['Sneaky &amp;#60;svg onload=alert(1)&amp;#62; Cafe'],
			'javascript href' => ['<a href=javascript:alert(1)>x</a>'],
			// A tag interleaved INSIDE an entity. These are already a decode
			// fixed point, so the decode loop is a no-op — and then stripping
			// the filler tag joins the halves back into `&lt;`. Stacking entity
			// layers alone never produces this shape, which is why the corpus
			// missed it until a review round built it by hand.
			'tag inside entity' => ['Sneaky &amp;<x>lt;img src=x onerror=alert(1)&amp;<x>gt; Cafe'],
			'tag inside entity name' => ['Sneaky &amp;l<b>t;img src=x onerror=alert(1)&amp;g<b>t; Cafe'],
			'tag inside numeric entity' => ['Sneaky &amp;<x>#60;svg onload=alert(1)&amp;<x>#62; Cafe'],
			'tag inside hex entity' => ['Sneaky &amp;<i>#x3C;svg onload=alert(1)&amp;<i>#x3E; Cafe'],
			'comment inside entity' => ['Sneaky &amp;<!--c-->lt;img src=x onerror=alert(1)&amp;<!--c-->gt; Cafe'],
			'script inside entity' => ['Sneaky &amp;<script>x</script>lt;img src=x onerror=alert(1)&amp;<script>x</script>gt; Cafe'],
		];
	}

	/**
	 * The reachable path for the above, and the call site rather than the
	 * helper: get_posts_table_info() does `SELECT *` on the newest row and
	 * dumps every column (Util.php:977-982). Review submissions are a public
	 * writer, and SubmissionsManager stores their entities as submitted.
	 */
	public function test_a_submitted_double_encoded_review_cannot_reach_the_sink(): void
	{
		global $wpdb;
		$previous = $wpdb;

		$wpdb = new class {
			public $prefix = 'wp_';

			// get_posts_table_info() gates each block on `show tables like
			// '<name>'` returning that exact name, so echo the name back.
			public function get_var($sql)
			{
				preg_match("/like '([^']+)'/", $sql, $m);
				return $m[1] ?? null;
			}

			public function get_results($sql, $output = null)
			{
				return [
					(object) [
						'id' => 1,
						'post_content' => 'Sneaky &amp;lt;svg onload=alert(1)&amp;gt; review',
						'json_data' => '{"text":"&amp;lt;img src=x onerror=alert(1)&amp;gt;"}',
					],
				];
			}

			public function prepare($sql, ...$args)
			{
				return $sql;
			}

			public function esc_like($text)
			{
				return addcslashes((string) $text, '_%\\');
			}
		};

		try {
			$out = Util::get_posts_table_info();
		} finally {
			$wpdb = $previous;
		}

		$this->assertCannotOpenATagAtTheSink($out);
		$this->assertStringContainsString('Sneaky', $out, 'the row must still be readable for support');
	}

	/** Ordinary prose and diagnostic URLs must survive the repeated decoding. */
	public function test_the_fixed_point_decode_does_not_damage_normal_values(): void
	{
		$restore = self::installSourceRows([
			[
				'name' => 'Smith & Sons Caf&eacute;',
				'provider' => 'google',
				'account_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
				'used_in' => 0,
			],
		]);

		try {
			$out = Util::get_sources_settings_info();
		} finally {
			$restore();
		}

		$this->assertStringContainsString('Smith & Sons', $out, 'an ampersand in a business name must survive');
		$this->assertStringContainsString('Café', $out, 'an accented name must decode to its glyph, not vanish');
	}

	/**
	 * The corpus above is hand-written, and hand-written corpora are exactly
	 * what missed the tag-inside-entity shape for two review rounds. This
	 * asserts the invariant instead of the examples: whatever combination of
	 * entity and tag fragments goes in, what arrives at the sink after the
	 * transport's decode must not be able to open a tag or a bogus comment.
	 *
	 * Fixed seed, so a failure is reproducible rather than a flake. 2000 cases
	 * here; the same generator was run at 60000 by hand with no failures.
	 */
	public function test_no_combination_of_entity_and_tag_fragments_reaches_the_sink_live(): void
	{
		$fragments = [
			'&amp;', '&', '<x>', '<b>', '</b>', '<!--c-->', '<script>', '</script>',
			'lt;', 'gt;', '#60;', '#62;', '#x3C;', '#x3E;', '&lt;', '&gt;',
			'img src=x onerror=alert(1)', 'svg onload=alert(1)', '<', '>',
			'<%', '<?', '</', '<!', "\0", '<0img', ' ', '&#38;', '&#x26;', '&amp;amp;',
		];

		mt_srand(20310903);
		$count = count($fragments);

		for ($i = 0; $i < 2000; $i++) {
			$value = '';
			for ($j = 0, $parts = mt_rand(2, 9); $j < $parts; $j++) {
				$value .= $fragments[mt_rand(0, $count - 1)];
			}

			$delivered = html_entity_decode(
				self::supportInfoText($value),
				ENT_QUOTES,
				'UTF-8'
			);

			$this->assertDoesNotMatchRegularExpression(
				'/<[a-zA-Z!\/?%]/',
				$delivered,
				'reached the sink able to open a tag, from input: ' . $value
			);
		}
	}

	/** The API-keys block is the same shape, so it gets the same guarantee. */
	public function test_a_hostile_api_key_is_inert_even_after_the_localize_decode(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_apikeys'] = ['google' => '<img src=x onerror=alert(1)>abc'];

		$out = Util::get_api_settings_info();

		$this->assertCannotOpenATagAtTheSink($out);
		$this->assertStringContainsString('abc', $out, 'the key must still be readable for support');
	}

	/**
	 * What the Support page actually does to the string, asserted in one place:
	 * wp_localize_script() decodes entities, then the customizer hands the
	 * result to dangerouslySetInnerHTML. Only `</br>` may be live markup.
	 */
	private function assertCannotOpenATagAtTheSink(string $output): void
	{
		$as_delivered = html_entity_decode($output, ENT_QUOTES, 'UTF-8');
		$body = str_replace('</br>', '', $as_delivered);

		$this->assertDoesNotMatchRegularExpression(
			'/<[a-zA-Z!\/]/',
			$body,
			'nothing may open a tag once wp_localize_script has decoded the blob'
		);
	}

	// ---------- guard 3: nothing attacker-shaped is persisted in the first place ----------

	public function test_relay_error_strings_are_stripped_before_storage(): void
	{
		$out = self::sanitizeErrorPayload([
			'id' => 'sourceConnectionError',
			'placeId' => self::PAYLOAD,
			'nested' => ['reason' => '<script>alert(1)</script>bad id'],
		]);

		$this->assertStringNotContainsString('<img', $out['placeId']);
		$this->assertStringNotContainsString('<script', $out['nested']['reason']);
		$this->assertSame('sourceConnectionError', $out['id'], 'a clean value must pass through unchanged');
	}

	/**
	 * SBR_Error_Handler::check_error() compares `code` and reads `provider`, and
	 * the Support page prints `success`. Sanitising must not retype them.
	 */
	public function test_non_string_fields_survive_sanitisation_untouched(): void
	{
		$out = self::sanitizeErrorPayload([
			'code' => 403,
			'success' => false,
			'ratio' => 1.5,
			'nothing' => null,
		]);

		$this->assertSame(403, $out['code']);
		$this->assertFalse($out['success']);
		$this->assertSame(1.5, $out['ratio']);
		$this->assertNull($out['nothing']);
	}

	/**
	 * Regression: the first cut of this fix used sanitize_text_field(), which
	 * also deletes every `%XX` sequence. It rewrote the stored `endpoint` to
	 * `website_url=httpssb-reviews-plugin.ddev.site` — corrupting the one field
	 * support reads, in the log they read it from. Found on a live relay error,
	 * not here, because the old bootstrap shim was trim(strip_tags()) and could
	 * not reproduce it; the shim is faithful now, so this test can hold.
	 */
	public function test_percent_encoding_in_a_stored_url_is_preserved(): void
	{
		$url = 'https://reviews.smashballoon.com/api/v1.0/reviews/google'
			. '?place_id=ChIJ123&website_url=https%3A%2F%2Fsb-reviews-plugin.ddev.site';

		$out = self::sanitizeErrorPayload(['endpoint' => $url]);

		$this->assertSame($url, $out['endpoint'], 'a diagnostic URL must survive sanitisation byte for byte');
		$this->assertStringContainsString('%3A%2F%2F', $out['endpoint']);
	}

	public function test_percent_encoding_in_a_forwarded_param_is_preserved(): void
	{
		$out = self::sanitizeRequestParams(['website_url' => 'https%3A%2F%2Fexample.test']);

		$this->assertSame('https%3A%2F%2Fexample.test', $out['website_url']);
	}

	/**
	 * Tag removal has to survive the usual evasions, since it is what lets the
	 * sanitiser preserve everything else. Each of these was run against the
	 * live WordPress function before being pinned here.
	 *
	 * @dataProvider tagEvasions
	 */
	public function test_tag_evasions_leave_nothing_that_can_open_a_tag(string $payload): void
	{
		$out = self::sanitizeErrorPayload(['apiMessage' => $payload])['apiMessage'];

		$this->assertDoesNotMatchRegularExpression(
			'/<[a-zA-Z!\/]/',
			$out,
			'nothing that can begin a tag may survive: ' . $payload
		);
	}

	/** @return array<string, array{0: string}> */
	public static function tagEvasions(): array
	{
		return [
			'plain' => ['<img src=x onerror=alert(1)>'],
			'unterminated' => ['<img src=x onerror=alert(1)'],
			'double bracket' => ['<<img src=x onerror=alert(1)>>'],
			'nested' => ['<im<script>g src=x onerror=alert(1)>'],
			'newline in tag' => ["<img\nsrc=x\nonerror=alert(1)>"],
			'case mix' => ['<IMG SRC=x OnErRoR=alert(1)>'],
			'script close' => ['abc</script><script>alert(1)</script>'],
		];
	}

	/**
	 * The guard that matters most for the future. Sanitising at the three
	 * SBRelay call sites only helps the callers that remember to; the option
	 * itself is the trust boundary, and log_error() is its only writer. A
	 * fourth writer already exists (SBR_New_Providers_Manager.php:2028), so
	 * this asserts the choke point rather than the call sites.
	 */
	public function test_the_error_log_writer_strips_markup_whoever_calls_it(): void
	{
		global $wp_options_mock;

		SBR_Error_Handler::log_error([
			'id' => 'someWriter',
			'code' => 500,
			'success' => false,
			'message' => self::PAYLOAD,
			'nested' => ['reason' => '<svg onload=alert(1)></svg>deep'],
		]);

		$stored = $wp_options_mock['sbr_errors'][0];

		$this->assertStringNotContainsString('<img', $stored['message']);
		$this->assertStringNotContainsString('<svg', $stored['nested']['reason']);
		$this->assertStringContainsString('deep', $stored['nested']['reason'], 'the prose around it survives');
		$this->assertSame(500, $stored['code'], 'code must stay an int for check_error()');
		$this->assertFalse($stored['success'], 'success must stay a bool');
	}

	/**
	 * update_errors() is public static and writes the option directly, so a
	 * caller that bypasses log_error() has to be covered too — that is why the
	 * guard sits here rather than in log_error().
	 */
	public function test_a_writer_bypassing_log_error_is_still_sanitised(): void
	{
		global $wp_options_mock;

		SBR_Error_Handler::update_errors([
			['id' => 'directWriter', 'code' => 500, 'message' => self::PAYLOAD],
		], 'no_merge');

		$stored = $wp_options_mock['sbr_errors'][0];

		$this->assertStringNotContainsString('<img', $stored['message']);
		$this->assertSame(500, $stored['code'], 'code must stay an int');
	}

	/** The logged endpoint URL must not lose its percent-encoding here either. */
	public function test_the_error_log_writer_preserves_a_percent_encoded_url(): void
	{
		global $wp_options_mock;

		$url = 'https://r.example.test/api?place_id=ChIJ123&website_url=https%3A%2F%2Fsb.test';
		SBR_Error_Handler::log_error(['id' => 'someWriter', 'endpoint' => $url]);

		$this->assertSame($url, $wp_options_mock['sbr_errors'][0]['endpoint']);
	}

	/**
	 * SBRelay still sanitises its own envelopes, so the two layers are
	 * independent — neither one being removed leaves the option unguarded.
	 */
	public function test_every_log_error_call_in_the_relay_is_sanitised(): void
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 2) . '/class/Common/Integrations/SBRelay.php'
		);

		// Two windows, because the sanitiser can legitimately appear on either
		// side: inline inside the argument, or on the line above where the
		// variable is reassigned from it (which is the form that also sanitises
		// the copy returned to callers). Matching only forwards read the correct
		// second form as a violation.
		preg_match_all('/log_error\(/', $src, $matches, PREG_OFFSET_CAPTURE);

		$this->assertNotEmpty($matches[0], 'SBRelay must still log relay errors');

		foreach ($matches[0] as $match) {
			$at     = (int) $match[1];
			$window = substr($src, max(0, $at - 260), 260 + 80);

			$this->assertStringContainsString(
				'sanitize_error_payload(',
				$window,
				'every log_error() in SBRelay must be handed a sanitised payload, at offset ' . $at
			);
		}
	}

	/**
	 * The envelope handed back to callers is sanitised too, not only the logged copy.
	 *
	 * `call()`'s return value reaches the browser: BaseProvider::getAllReviews()
	 * answers with `wp_send_json($response)`, `SbUtils.ajaxPost` reads
	 * `data.message` off it, and `Notice.js:26` renders that with
	 * dangerouslySetInnerHTML. Sanitising only the argument to log_error() closed
	 * the stored half of SMASH-2031 and left the reflected half open.
	 */
	public function test_the_envelope_returned_to_callers_is_sanitised_as_well(): void
	{
		global $wp_options_mock, $wp_http_response_code_mock, $wp_http_body_mock;

		$wp_http_response_code_mock = 400;
		$wp_http_body_mock = (string) wp_json_encode([
			'success' => false,
			'data'    => [
				'id'      => 'sourceNotFound',
				'code'    => 500,
				'message' => self::PAYLOAD,
			],
		]);

		// finally, not two lines after the call: if call() ever throws instead of
		// returning, these process-wide mocks would leak into whichever test runs
		// next — the same order-dependent flake this file's own class docblock is
		// about for mt_srand() and $wp_options_mock.
		try {
			$returned = ( new SBRelay() )->call('sources/info', [], 'GET', false);
		} finally {
			$wp_http_response_code_mock = null;
			$wp_http_body_mock = null;
		}

		// Proves the envelope actually arrived — an empty return would satisfy
		// every assertion below for the wrong reason.
		$this->assertSame('sourceNotFound', $returned['id'] ?? null);

		$this->assertStringNotContainsString('<img', (string) ( $returned['message'] ?? '' ));
		$this->assertStringNotContainsString('onerror', (string) ( $returned['message'] ?? '' ));

		// Non-strings survive: the Support page prints `code` as-is.
		$this->assertSame(500, $returned['code'] ?? null, 'code must stay an int');

		// And the stored half stays closed — one sanitise, both consumers.
		$stored = $wp_options_mock['sbr_errors'][0] ?? [];
		$this->assertStringNotContainsString('<img', (string) ( $stored['message'] ?? '' ));
	}

	// ---------- guard 2: the parameters never reach the relay unvalidated ----------

	/**
	 * Every id form the endpoint legitimately forwards, taken from the local
	 * sources table rather than imagined: Google, Yelp, TripAdvisor, Trustpilot,
	 * wordpress.org and the Woo / EDD composites.
	 *
	 * @dataProvider realPlaceIds
	 */
	public function test_real_place_ids_are_accepted(string $place_id): void
	{
		$this->assertTrue(self::isValidPlaceId($place_id), $place_id . ' is a live id and must not be refused');
	}

	/** @return array<string, array{0: string}> */
	public static function realPlaceIds(): array
	{
		return [
			'google' => ['ChIJH4gGtHVyAHwR0qC3R8DuLL4'],
			'google with hyphen' => ['ChIJFTrs68AOSUcRgsTW-sRzwZY'],
			'yelp alias' => ['mPZbuFIr7tPPBg4OqNNGFA'],
			'tripadvisor numeric' => ['195020'],
			'tripadvisor g-d form' => ['g60763-d93450'],
			'wordpress.org slug' => ['sbr-qa-wordpress.org'],
			'woocommerce composite' => ['wc_multi_54fc71d16ac017cf2c0d481a43cade09'],
			'edd composite' => ['edd_multi_e3a8313e-6390-490c-b940-ad6a9ea6dffa'],
			// A Trustpilot id IS a URL — the relay returns the schema.org
			// LocalBusiness.url as the source id
			// (sb-relay TrustPilotRemoteSourcesRepository.php:56). An
			// id-shaped-only allow-list would have broken every Trustpilot
			// source, so both of these are load-bearing.
			'trustpilot review url' => ['https://www.trustpilot.com/review/www.nike.com'],
			'trustpilot locale host' => ['https://uk.trustpilot.com/review/example.co.uk'],
			// wordpress.org sends `type/slug`
			// (SBR_Feed_Saver_Manager.php:843), so a slash is legitimate.
			'wordpress.org type and slug' => ['plugin/my-plugin-slug'],
		];
	}

	/** @dataProvider rejectedPlaceIds */
	public function test_attacker_shaped_place_ids_are_refused(string $place_id): void
	{
		$this->assertFalse(self::isValidPlaceId($place_id), 'must never be forwarded to the relay: ' . $place_id);
	}

	/** @return array<string, array{0: string}> */
	public static function rejectedPlaceIds(): array
	{
		return [
			'the wordfence poc' => [self::PAYLOAD],
			'script tag' => ['<script>alert(1)</script>'],
			'attribute break out' => ['abc" onmouseover="alert(1)'],
			'entity encoded' => ['&lt;img src=x onerror=alert(1)&gt;'],
			// No spaces, so only the `&` exclusion refuses this one.
			'compact entity' => ['&lt;img&gt;'],
			'quote' => ["abc'"],
			'backtick' => ['abc`b'],
			'newline injection' => ["abc\nX-Injected: 1"],
			'null byte' => ["abc\0def"],
			'space' => ['abc def'],
			'empty' => [''],
			'over length' => [str_repeat('a', 256)],
		];
	}

	public function test_a_non_string_place_id_is_refused(): void
	{
		$this->assertFalse(self::isValidPlaceId(['nested']));
		$this->assertFalse(self::isValidPlaceId(null));
	}

	public function test_forwarded_params_are_sanitised_and_typed_values_kept(): void
	{
		$out = self::sanitizeRequestParams([
			'place_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
			'note' => self::PAYLOAD,
			'page' => 2,
			'nested' => ['q' => '<b>x</b>'],
		]);

		$this->assertSame('ChIJH4gGtHVyAHwR0qC3R8DuLL4', $out['place_id'], 'a clean id must be forwarded verbatim');
		$this->assertStringNotContainsString('<img', $out['note']);
		$this->assertSame(2, $out['page'], 'a numeric param must stay numeric');
		$this->assertStringNotContainsString('<b>', $out['nested']['q']);
	}

	/**
	 * `sources/*` is the path where the relay itself echoes the submitted id
	 * back (CanSendSourcesResponse), so what leaves here has to be clean too.
	 * Asserted on what actually reaches the relay, since no state observes it.
	 */
	public function test_params_reaching_the_sources_route_are_sanitised(): void
	{
		$provider = self::provider();
		$recorder = new class {
			/** @var array<string, mixed> */
			public $args = [];

			public function call($endpoint, $args = [], $method = 'GET', $flag = false)
			{
				$this->args = $args;
				return ['success' => true, 'data' => []];
			}
		};
		self::injectRelay($provider, $recorder);

		$provider->getSourcesInfo([
			'place_id' => 'https://www.trustpilot.com/review/www.nike.com',
			'note' => self::PAYLOAD,
		]);

		$this->assertStringNotContainsString('<img', $recorder->args['note']);
		$this->assertSame(
			'https://www.trustpilot.com/review/www.nike.com',
			$recorder->args['place_id'],
			'a Trustpilot URL id must reach the relay byte for byte'
		);
	}

	/**
	 * The same path legitimately sends '' (the Trustpilot key probe,
	 * SMASH-1973) and 'XXX' as sentinels, so it must NOT get the place_id
	 * allow-list — that would break the key probe this codebase already fixed.
	 */
	public function test_the_sources_route_still_accepts_its_sentinel_ids(): void
	{
		foreach (['', 'XXX'] as $sentinel) {
			$provider = self::provider();
			$recorder = new class {
				/** @var array<string, mixed> */
				public $args = [];

				public function call($endpoint, $args = [], $method = 'GET', $flag = false)
				{
						$this->args = $args;
						return ['success' => true, 'data' => []];
				}
			};
			self::injectRelay($provider, $recorder);

			$provider->getSourcesInfo(['place_id' => $sentinel]);

			$this->assertSame(
				$sentinel,
				$recorder->args['place_id'],
				'sentinel must survive: ' . var_export($sentinel, true)
			);
		}
	}

	/**
	 * Guard 2 at the call site, not the helper. These drive `getAllReviews()` itself — the
	 * route the Wordfence proof-of-concept hit — because a helper can be
	 * perfectly tested while nothing calls it. A probe during review proved
	 * exactly that: deleting the guard from this method left the suite green.
	 *
	 * Uses this branch's own SBR_Test_Halt terminator shim, so there is one
	 * mechanism for driving a handler that responds and exits, not two.
	 */
	public function test_getallreviews_never_forwards_a_hostile_place_id(): void
	{
		$provider = self::provider();
		$recorder = self::relayRecorder();
		self::injectRelay($provider, $recorder);

		try {
			$provider->getAllReviews(self::request(['place_id' => self::PAYLOAD]));
			$this->fail('a malformed place_id must be refused before the relay call');
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json_error', $halt->getMessage());
			$this->assertSame(400, $halt->status);
		}

		$this->assertFalse($recorder->called, 'the relay must not be called at all');
	}

	public function test_getallreviews_forwards_a_clean_id_and_sanitises_the_rest(): void
	{
		$provider = self::provider();
		$recorder = self::relayRecorder();
		self::injectRelay($provider, $recorder);

		try {
			$provider->getAllReviews(self::request([
				'place_id' => 'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
				'note' => self::PAYLOAD,
				'page' => 2,
			]));
			$this->fail('a clean request must reach the relay and respond');
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json', $halt->getMessage(), 'the relay response must be sent back');
		}

		$this->assertTrue($recorder->called, 'the relay must be called for a clean id');
		$this->assertSame(
			'ChIJH4gGtHVyAHwR0qC3R8DuLL4',
			$recorder->args['place_id'],
			'a clean id must be forwarded verbatim'
		);
		$this->assertStringNotContainsString('<img', $recorder->args['note'], 'other params must be stripped');
		$this->assertSame(2, $recorder->args['page'], 'a numeric param must stay numeric');
	}

	/** A Trustpilot URL id must survive the call site too, not just the helper. */
	public function test_getallreviews_forwards_a_trustpilot_url_id(): void
	{
		$provider = self::provider();
		$recorder = self::relayRecorder();
		self::injectRelay($provider, $recorder);

		$url = 'https://www.trustpilot.com/review/www.nike.com';

		try {
			$provider->getAllReviews(self::request(['place_id' => $url]));
		} catch (\SBR_Test_Halt $halt) {
			$this->assertSame('wp_send_json', $halt->getMessage());
		}

		$this->assertTrue($recorder->called, 'a Trustpilot URL id must not be refused');
		$this->assertSame($url, $recorder->args['place_id']);
	}

	// ---------- guard 1: the endpoint is no longer reachable unauthenticated ----------

	public function test_unauthenticated_request_is_refused_with_401(): void
	{
		$GLOBALS['sbr_test_user_logged_in'] = false;

		$result = self::provider()->check_rest_permission();

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame(401, $result->get_error_data()['status']);
	}

	public function test_logged_in_user_without_the_capability_is_refused_with_403(): void
	{
		$GLOBALS['sbr_test_user_logged_in'] = true;
		$GLOBALS['sbr_test_user_can'] = false;

		$result = self::provider()->check_rest_permission();

		$this->assertInstanceOf(\WP_Error::class, $result);
		$this->assertSame(403, $result->get_error_data()['status']);
	}

	public function test_an_administrator_still_gets_through(): void
	{
		$GLOBALS['sbr_test_user_logged_in'] = true;
		$GLOBALS['sbr_test_user_can'] = true;

		$this->assertTrue(self::provider()->check_rest_permission(), 'the admin builder must keep working');
	}

	/**
	 * The route must not be registered with an open callback again. Asserted at
	 * the source because register_rest_route() needs a running REST server.
	 */
	public function test_the_route_is_not_registered_with_an_open_callback(): void
	{
		$src = (string) file_get_contents(
			dirname(__DIR__, 2) . '/class/Common/Integrations/Providers/BaseProvider.php'
		);

		// Read the callback that is actually registered, rather than grepping
		// the whole file — prose mentioning the old open callback would
		// otherwise fail this, and a real regression would still pass it.
		preg_match_all("/'permission_callback'\s*=>\s*([^\n]+)/", $src, $matches);

		$this->assertNotEmpty($matches[1], 'the route must still declare a permission_callback');

		foreach ($matches[1] as $callback) {
			$this->assertStringContainsString(
				'check_rest_permission',
				$callback,
				'every provider extending this class shares the route: ' . $callback
			);
		}
	}

	/**
	 * BC: registerProvider() feeds `supportedProviders` in MenuService, which the
	 * admin builder reads. Gating the route must not change that contract.
	 */
	public function test_register_provider_contract_is_unchanged(): void
	{
		$providers = self::provider()->registerProvider([]);

		$this->assertCount(1, $providers);
		$this->assertSame(
			['name', 'endpoint', 'uses_connect', 'friendly_name'],
			array_keys($providers[0]),
			'the admin builder reads these four keys'
		);
	}

	// ---------- helpers ----------

	/**
	 * Install a $wpdb whose get_results() returns the given source rows, and
	 * return a closure that puts the previous one back. $wpdb is process-wide,
	 * so it has to be restored or it leaks into every later test — the defect
	 * this file tripped over in FeedCacheUpdateServiceTest.
	 *
	 * @param array<int, array<string, string>> $rows
	 *
	 * @return callable(): void
	 */
	private static function installSourceRows(array $rows): callable
	{
		global $wpdb;
		$previous = $wpdb;

		$wpdb = new class ($rows) {
			public $prefix = 'wp_';
			/** @var array<int, array<string, string>> */
			private $rows;

			public function __construct(array $rows)
			{
				$this->rows = $rows;
			}

			public function prepare($sql, ...$args)
			{
				return $sql;
			}

			public function get_results($sql, $output = null)
			{
				return $this->rows;
			}

			public function get_var($sql)
			{
				return count($this->rows);
			}

			public function esc_like($text)
			{
				return addcslashes((string) $text, '_%\\');
			}
		};

		return static function () use ($previous): void {
			global $wpdb;
			$wpdb = $previous;
		};
	}

	/** @param mixed $value */
	private static function supportInfoText($value): string
	{
		$method = (new ReflectionClass(Util::class))->getMethod('support_info_text');
		$method->setAccessible(true);

		return (string) $method->invoke(null, $value);
	}

	/** Records whether the relay was called, and with what. */
	private static function relayRecorder()
	{
		return new class {
			/** @var bool */
			public $called = false;
			/** @var array<string, mixed> */
			public $args = [];

			public function call($endpoint, $args = [], $method = 'GET', $flag = false)
			{
				$this->called = true;
				$this->args = $args;
				return ['success' => true, 'data' => ['reviews' => []]];
			}
		};
	}

	/**
	 * Stands in for WP_REST_Request: getAllReviews() only needs get_params().
	 *
	 * @param array<string, mixed> $params
	 *
	 * @return object
	 */
	private static function request(array $params)
	{
		return new class ($params) {
			/** @var array<string, mixed> */
			private $params;

			public function __construct(array $params)
			{
				$this->params = $params;
			}

			public function get_params()
			{
				return $this->params;
			}
		};
	}

	/**
	 * Put a recorder in place of the real relay on a constructor-less provider.
	 *
	 * @param object $relay Stand-in exposing call().
	 */
	private static function injectRelay(Google $provider, $relay): void
	{
		$property = new \ReflectionProperty(BaseProvider::class, 'relay');
		$property->setAccessible(true);
		$property->setValue($provider, $relay);
	}

	private static function provider(): Google
	{
		// SBRelay's constructor reads options and would need a live WP; the
		// permission callback and the param guards touch no instance state.
		// Same constraint RelaySlowEndpointsTest documents.
		return (new ReflectionClass(Google::class))->newInstanceWithoutConstructor();
	}

	/** @param mixed $place_id */
	private static function isValidPlaceId($place_id): bool
	{
		return (bool) self::callGuard(Google::class, 'is_valid_place_id', [$place_id]);
	}

	private static function sanitizeRequestParams(array $params): array
	{
		return (array) self::callGuard(Google::class, 'sanitize_request_params', [$params]);
	}

	private static function sanitizeErrorPayload(array $data): array
	{
		$method = (new ReflectionClass(SBRelay::class))->getMethod('sanitize_error_payload');
		$method->setAccessible(true);

		return (array) $method->invoke(
			(new ReflectionClass(SBRelay::class))->newInstanceWithoutConstructor(),
			$data
		);
	}

	/**
	 * @param array<int, mixed> $args
	 *
	 * @return mixed
	 */
	private static function callGuard(string $class, string $method, array $args)
	{
		$reflection = (new ReflectionClass($class))->getMethod($method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(null, $args);
	}
}
