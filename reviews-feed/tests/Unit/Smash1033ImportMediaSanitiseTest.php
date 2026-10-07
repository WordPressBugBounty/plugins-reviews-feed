<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1033 — media from an uploaded collection file is sanitised on the way in.
 *
 * Both import handlers route media through carry_review_media(), so this calls it
 * the way they do: a parsed store row by reference plus the decoded file review.
 */
final class Smash1033ImportMediaSanitiseTest extends TestCase
{
	/**
	 * @param  array<string,mixed> $single_review
	 * @return array<string,mixed> the store row after the call
	 */
	private function carry(array $single_review): array
	{
		$store = ['text' => 'parsed'];
		$m = new \ReflectionMethod(SBR_Feed_Saver_Manager::class, 'carry_review_media');
		$m->setAccessible(true);
		$m->invokeArgs(null, [&$store, $single_review]);
		return $store;
	}

	/** BC: the shape an export writes (provider items plus the plugin's local map) is stored unchanged. */
	public function test_a_valid_media_list_round_trips_unchanged(): void
	{
		$media = [
			[
				'type'  => 'image',
				'url'   => 'https://www.gravatar.com/avatar/00000000000000000000000000000002?s=640&d=identicon',
				'local' => [
					640 => 'http://example.test/wp-content/uploads/sbr-feed-images/google-1-0-640.jpg',
					150 => 'http://example.test/wp-content/uploads/sbr-feed-images/google-1-0-150.jpg',
				],
			],
			['type' => 'image', 'url' => 'https://a0.muscache.com/im/pictures/x.jpg'],
		];
		// Decoded the way the import decodes a file, so int-like keys match real input.
		$decoded = json_decode((string) wp_json_encode($media), true);

		$this->assertSame($decoded, $this->carry(['media' => $decoded])['media']);
	}

	public function test_non_http_urls_are_dropped(): void
	{
		$store = $this->carry(['media' => [
			['type' => 'image', 'url' => 'javascript:alert(1)'],
			['type' => 'image', 'url' => 'data:text/html;base64,PHNjcmlwdD4='],
			['type' => 'image', 'url' => '/wp-content/relative.jpg'],
			['type' => 'image', 'url' => 'https://ok.test/a.jpg'],
		]]);

		$this->assertSame([['type' => 'image', 'url' => 'https://ok.test/a.jpg']], $store['media']);
	}

	/** The scheme is fine but the URL still goes through esc_url_raw(): quotes cannot survive. */
	public function test_an_http_url_is_still_escaped(): void
	{
		$store = $this->carry(['media' => [['type' => 'image', 'url' => 'https://ok.test/a.jpg"onload="x']]]);

		$this->assertStringNotContainsString('"', $store['media'][0]['url']);
		$this->assertStringStartsWith('https://ok.test/a.jpg', $store['media'][0]['url']);
	}

	/**
	 * esc_url_raw() keeps ( ) ; so this URL survives it with its scheme intact, and in an
	 * unquoted CSS url() it closes the value and appends declarations. At write the
	 * brackets are percent-encoded, so no `)` is left to close anything.
	 */
	public function test_a_url_that_could_leave_a_css_url_loses_its_brackets(): void
	{
		$breakout = 'https://a.test/x.jpg);position:fixed;inset:0;z-index:99999;background:url(https://evil.test/o.png';
		$this->assertSame($breakout, esc_url_raw($breakout), 'precondition: esc_url_raw() alone keeps it');

		$url = $this->carry(['media' => [['type' => 'image', 'url' => $breakout]]])['media'][0]['url'];

		$this->assertSame(0, preg_match('/[()\']/', $url), $url);
		$this->assertStringStartsWith('https://a.test/x.jpg%29', $url);
	}

	/** BC: a real image URL with brackets or an apostrophe is kept, encoded, not lost. */
	public function test_a_stored_url_with_brackets_survives_a_resave(): void
	{
		$store = $this->carry(['media' => [
			['type' => 'image', 'url' => 'https://ok.test/Foo_(bar).jpg'],
			['type' => 'image', 'url' => "https://ok.test/it's.jpg"],
		]]);

		$this->assertSame([
			['type' => 'image', 'url' => 'https://ok.test/Foo_%28bar%29.jpg'],
			['type' => 'image', 'url' => 'https://ok.test/it%27s.jpg'],
		], $store['media']);
	}

	public function test_unknown_types_and_non_arrays_are_dropped(): void
	{
		$store = $this->carry(['media' => [
			['type' => 'video', 'url' => 'https://ok.test/v.mp4'],
			['url' => 'https://ok.test/no-type.jpg'],
			'https://ok.test/bare-string.jpg',
			['type' => 'image', 'url' => 'https://ok.test/a.jpg'],
		]]);

		$this->assertSame([['type' => 'image', 'url' => 'https://ok.test/a.jpg']], $store['media']);
	}

	public function test_unknown_keys_and_bad_local_entries_are_dropped(): void
	{
		$store = $this->carry(['media' => [[
			'type'    => 'image',
			'url'     => 'https://ok.test/a.jpg',
			'onerror' => 'alert(1)',
			'style'   => 'x',
			'local'   => [640 => 'javascript:alert(1)', 'big' => 'https://ok.test/b.jpg', 150 => 'https://ok.test/a-150.jpg'],
		]]]);

		$this->assertSame(
			[['type' => 'image', 'url' => 'https://ok.test/a.jpg', 'local' => [150 => 'https://ok.test/a-150.jpg']]],
			$store['media']
		);
	}

	/** A `//cdn` URL (AliExpress) is kept as https:, as Parser::css_url() renders it, not dropped. */
	public function test_a_protocol_relative_url_is_kept_as_https(): void
	{
		$store = $this->carry(['media' => [
			['type' => 'image', 'url' => '//ae01.alicdn.test/kf/a.jpg', 'local' => [640 => '//cdn.test/a-640.jpg']],
		]]);

		$this->assertSame(
			[['type' => 'image', 'url' => 'https://ae01.alicdn.test/kf/a.jpg', 'local' => [640 => 'https://cdn.test/a-640.jpg']]],
			$store['media']
		);
	}

	/** A root-relative `local` path (filtered sbr_resize_url) survives; the fetched `url` still must be http(s). */
	public function test_a_root_relative_local_path_survives_but_not_as_the_url(): void
	{
		$store = $this->carry(['media' => [
			['type' => 'image', 'url' => 'https://ok.test/a.jpg', 'local' => [640 => '/wp-content/uploads/sbr/a-640.jpg']],
			['type' => 'image', 'url' => '/wp-content/uploads/sbr/b.jpg'],
		]]);

		$this->assertSame(
			[['type' => 'image', 'url' => 'https://ok.test/a.jpg', 'local' => [640 => '/wp-content/uploads/sbr/a-640.jpg']]],
			$store['media']
		);
	}

	/** Duplicate and reviews-import leave the resize to the batched feed pass (scale: one request per collection). */
	public function test_duplicate_and_import_do_not_resize_inside_the_request(): void
	{
		foreach (['duplicate_collection', 'import_reviews_collection'] as $fn) {
			$m    = new \ReflectionMethod(SBR_Feed_Saver_Manager::class, $fn);
			$file = (string) file_get_contents((string) $m->getFileName());
			$body = implode('', array_slice(explode("\n", $file), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
			$this->assertStringContainsString('->store()', $body, "{$fn}() still stores the review");
			$this->assertStringNotContainsString('resize_images(', $body, "{$fn}() must not resize synchronously");
			$this->assertStringNotContainsString("'images_done', 1", $body, "{$fn}() must leave images_done = 0 for the feed pass");
		}
	}

	public function test_an_empty_list_when_nothing_survives(): void
	{
		$store = $this->carry(['media' => [['type' => 'image', 'url' => 'javascript:alert(1)']]]);

		$this->assertSame([], $store['media']);
		$this->assertSame('parsed', $store['text']);
	}

	/**
	 * The manual review save reads $_POST and the duplicate re-copies stored rows; both
	 * sit inside handlers that need a live cache, so pin that each one's media goes
	 * through the sanitiser. Bounded to each function so one call cannot cover another.
	 */
	public function test_the_manual_save_and_the_duplicate_sanitise_media_too(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/class/Common/Builder/SBR_Feed_Saver_Manager.php');
		$pins = [
			'create_update_collection_review' => "\$sanitized_review['media'] = self::sanitize_review_media(\$review['media']);",
			'duplicate_collection'            => "\$sanitized_review['media'] = self::sanitize_review_media(\$review_json['media']);",
		];
		$sub  = (string) file_get_contents(dirname(__DIR__, 2) . '/class/Pro/Integrations/Forms/CollectionSubmissionReviews.php');
		$this->assertStringContainsString(
			"\$review_store['media'] = SBR_Feed_Saver_Manager::sanitize_review_media(\$review_store['media']);",
			$sub,
			'form submissions store media through sanitize_review_media()'
		);
		$this->assertStringContainsString(
			"\$review_store['json_data']['media'] = SBR_Feed_Saver_Manager::sanitize_review_media(\$review_store['json_data']['media']);",
			$sub,
			'and the json_data copy transform_to_review() makes, which is the one stored'
		);
		foreach ($pins as $fn => $line) {
			$start = strpos($src, "function {$fn}(");
			$this->assertNotFalse($start, "{$fn}() exists");
			$next = strpos($src, 'function ', $start + 10);
			$body = substr($src, $start, ($next === false ? strlen($src) : $next) - $start);
			$this->assertStringContainsString($line, $body, "{$fn}() stores media through sanitize_review_media()");
		}
	}
}
