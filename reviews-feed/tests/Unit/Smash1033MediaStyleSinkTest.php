<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Parser;

/**
 * SMASH-1033 — the review-media sink in post-elements/media.php.
 *
 * esc_url() keeps `)` and `;`, so an unquoted `url(...)` in the style attribute let a
 * stored media URL close it and add its own declarations. Rendered here with a URL that
 * never went through the write-side sanitiser, the way an older stored row would reach it.
 */
final class Smash1033MediaStyleSinkTest extends TestCase
{
	private const BREAKOUT = 'https://a.test/x.jpg);position:fixed;inset:0;z-index:99999;background:url(https://evil.test/o.png';

	/** @param array<string,mixed> $post */
	private function render(array $post): string
	{
		$host = new class {
			/** @var Parser */
			public $parser;
			/** @param array<string,mixed> $post */
			public function render(array $post): string
			{
				ob_start();
				include dirname(__DIR__, 2) . '/templates/frontend/pro/post-elements/media.php';
				return (string) ob_get_clean();
			}
		};
		$host->parser = new Parser();
		return $host->render($post);
	}

	/** @return list<string> the style attribute of each rendered media link */
	private function styles(string $html): array
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
		libxml_clear_errors();
		$out = [];
		foreach ((new DOMXPath($dom))->query('//a[contains(@class,"sb-single-image")]/@style') ?: [] as $attr) {
			$out[] = (string) $attr->nodeValue;
		}
		return $out;
	}

	/**
	 * Stored rows and provider media reach this template without the write-side sanitiser,
	 * so each of these is rendered raw. esc_url() keeps well-formed entities, and the
	 * attribute parser decodes them back to quotes.
	 *
	 * @return array<string,array{string}>
	 */
	public static function hostileUrls(): array
	{
		return [
			'bracket breakout' => [self::BREAKOUT],
			'&quot;'           => ['https://a.test/x&quot;);color:red;x:(&quot;.jpg'],
			'&#34;'            => ['https://a.test/x&#34;);color:red;x:(&#34;.jpg'],
			'&#x22;'           => ['https://a.test/x&#x22;);color:red;x:(&#x22;.jpg'],
			'&apos;'           => ['https://a.test/x&apos;);color:red;x:(&apos;.jpg'],
			'%22'              => ['https://a.test/x%22);color:red;x:(%22.jpg'],
			'raw quote'        => ['https://a.test/x");color:red;x:(".jpg'],
			'newline'          => ["https://a.test/x.jpg\n);color:red;x:("],
			'&amp;quot;'       => ['https://a.test/x&amp;quot;);color:red;x:(&amp;quot;.jpg'],
		];
	}

	/** @dataProvider hostileUrls */
	public function test_a_hostile_media_url_stays_inside_one_quoted_url(string $url): void
	{
		$styles = $this->styles($this->render([
			'provider' => ['name' => 'google'],
			'reviewer' => ['name' => 'A'],
			'media'    => [['type' => 'image', 'url' => $url]],
		]));

		$this->assertCount(1, $styles, 'the media link rendered');
		// The whole attribute is one declaration whose value is a single double-quoted
		// string with no bracket left in it, so nothing can close it.
		$this->assertMatchesRegularExpression('/^background-image:url\("[^"()\\\\\s]*"\)$/', $styles[0], $styles[0]);
	}

	/** BC: a stored `&amp;` still requests `&`, as it did when the template used esc_url(). */
	public function test_an_encoded_ampersand_still_requests_a_plain_one(): void
	{
		$styles = $this->styles($this->render([
			'provider' => ['name' => 'google'],
			'reviewer' => ['name' => 'A'],
			'media'    => [['type' => 'image', 'url' => 'https://cdn.test/p.jpg?w=640&amp;h=480']],
		]));

		$this->assertSame(['background-image:url("https://cdn.test/p.jpg?w=640&h=480")'], $styles);
	}

	/** BC: a protocol-relative CDN image still renders, over https; anything not a web URL renders nothing. */
	public function test_scheme_handling(): void
	{
		$this->assertSame('https://ae01.alicdn.com/kf/a.jpg', Parser::css_url('//ae01.alicdn.com/kf/a.jpg'));
		$this->assertSame('', Parser::css_url('javascript&#58;alert(1)'), 'esc_url_raw() makes it http://javascript:… which is no valid URL');
		$this->assertSame('/wp-content/uploads/sbr-feed-images/a-0-640.jpg', Parser::css_url('/wp-content/uploads/sbr-feed-images/a-0-640.jpg'), 'BC: a root-relative local image still renders');
		$this->assertSame('', Parser::css_url('ftp://a.test/x.jpg'), 'a non-web scheme renders nothing');
		$this->assertSame('', Parser::css_url(''));
	}

	/** Characters that are not URL characters never reach the page: esc_url_raw() strips them first. */
	public function test_non_url_characters_are_stripped(): void
	{
		$this->assertSame('https://a.test/ab.jpg', Parser::css_url('https://a.test/a<b>.jpg'));
	}

	public function test_a_normal_media_url_renders_unchanged_inside_the_quotes(): void
	{
		$url    = 'https://lh3.googleusercontent.com/p/AF1Qip.jpg';
		$styles = $this->styles($this->render([
			'provider' => ['name' => 'google'],
			'reviewer' => ['name' => 'A'],
			'media'    => [['type' => 'image', 'url' => $url]],
		]));

		$this->assertSame(['background-image:url("' . $url . '")'], $styles);
	}
}
