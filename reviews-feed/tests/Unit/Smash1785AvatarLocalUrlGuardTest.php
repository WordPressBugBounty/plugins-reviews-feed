<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Parser;

/**
 * SMASH-1785 — a localized reviewer avatar whose URL no longer serves generated a
 * per-visitor 404 storm that exhausted a customer's CPU.
 *
 * Root cause: `check_local_image()` took the URL's basename and `file_exists()`'d
 * it in the canonical uploads folder, while the emitted URL was built from
 * `baseurl` + the `sbr_resize_url` filter. The two bases were decoupled, so a
 * filesystem hit could vouch for a URL that 404s (CDN/offload rewrite, migration,
 * or a filter diverting the path). Each miss cost a full WordPress 404 page —
 * measured at 103,846 bytes / 1.6-2.6s on the customer's host, uncacheable — at
 * ~13 avatars per page view.
 *
 * The guard now requires the URL to resolve under the uploads baseurl AND the
 * full relative path (not just the basename) to exist on disk. Anything that
 * cannot be mapped back to a local file is reported unavailable so callers fall
 * back to the remote avatar instead of emitting a dead URL.
 *
 * `test_canonical_local_url_still_resolves` is the backwards-compatibility guard:
 * it exercises the shape every existing caller produces and must pass on both the
 * old and new implementation.
 */
final class Smash1785AvatarLocalUrlGuardTest extends TestCase
{
	/** Matches the tests/bootstrap.php wp_upload_dir() shim. */
	private const BASEDIR = '/tmp/uploads';
	private const BASEURL = 'https://example.test/wp-content/uploads';

	/** @var string */
	private $avatar_dir;

	protected function setUp(): void
	{
		parent::setUp();

		if (!defined('SB_COMMON_ASSETS')) {
			define('SB_COMMON_ASSETS', 'https://example.test/wp-content/plugins/sb-reviews/vendor/smashballoon/customizer/sb-common/');
		}

		$this->avatar_dir = self::BASEDIR . '/sbr-feed-images/';
		if (!is_dir($this->avatar_dir)) {
			mkdir($this->avatar_dir, 0777, true);
		}
		file_put_contents($this->avatar_dir . 'google-1785-avatar-150.png', 'png');

		// GDPR off unless a test opts in.
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['gdpr' => 'no'];
	}

	protected function tearDown(): void
	{
		foreach (glob($this->avatar_dir . 'google-1785*') ?: [] as $f) {
			unlink($f);
		}
		global $wp_options_mock;
		$wp_options_mock = [];
		parent::tearDown();
	}

	private function placeholder(): string
	{
		return SB_COMMON_ASSETS . 'sb-customizer/assets/images/avatar.jpg';
	}

	/**
	 * BACKWARDS COMPATIBILITY: the canonical URL every existing caller emits —
	 * baseurl + sbr-feed-images/<file> with the file present — must still be
	 * treated as a usable local image.
	 */
	public function test_canonical_local_url_still_resolves(): void
	{
		$this->assertTrue(
			Parser::check_local_image(self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png'),
			'A real localized avatar under the uploads baseurl must still be served locally.'
		);
	}

	/**
	 * The regression itself: the file exists in the canonical folder, but the
	 * emitted URL points somewhere else (a CDN/offload rewrite or the
	 * `sbr_resize_url` filter). The old basename-only check returned true here and
	 * emitted a URL that 404s.
	 */
	public function test_diverted_url_is_rejected_even_though_basename_exists(): void
	{
		$this->assertFalse(
			Parser::check_local_image(self::BASEURL . '/sbr-feed-images/cdn-rewritten/google-1785-avatar-150.png'),
			'A URL whose path does not exist on disk must not be vouched for by a basename match.'
		);
	}

	public function test_foreign_host_url_is_rejected(): void
	{
		$this->assertFalse(
			Parser::check_local_image('https://cdn.example.com/wp-content/uploads/sbr-feed-images/google-1785-avatar-150.png'),
			'A URL outside the uploads baseurl cannot be verified by file_exists().'
		);
	}

	/** An http/https difference is not a foreign host — the file still serves. */
	public function test_scheme_mismatch_still_resolves(): void
	{
		$this->assertTrue(
			Parser::check_local_image('http://example.test/wp-content/uploads/sbr-feed-images/google-1785-avatar-150.png'),
			'Only the scheme differs from baseurl; the local file should still be used.'
		);
	}

	public function test_missing_file_under_baseurl_is_rejected(): void
	{
		$this->assertFalse(
			Parser::check_local_image(self::BASEURL . '/sbr-feed-images/google-1785-does-not-exist-150.png')
		);
	}

	public function test_traversal_is_rejected(): void
	{
		$this->assertFalse(
			Parser::check_local_image(self::BASEURL . '/sbr-feed-images/../../../etc/passwd')
		);
	}

	/**
	 * @dataProvider emptyValueProvider
	 * @param mixed $value
	 */
	public function test_empty_and_non_string_values_are_rejected($value): void
	{
		$this->assertFalse(Parser::check_local_image($value));
	}

	public static function emptyValueProvider(): array
	{
		return [
			'empty string' => [''],
			'null'         => [null],
			'false'        => [false],
			'array'        => [[]],
		];
	}

	// ---- fallback URL used by the template's onerror chain ----

	/**
	 * The case the template's onerror chain exists for: the local file passes the
	 * server-side check (so the local URL is emitted) but dies in the browser —
	 * the file was removed after the HTML was generated, or stale cached HTML is
	 * being served. The next thing to try is the remote avatar.
	 */
	public function test_fallback_is_remote_avatar_when_not_doing_gdpr(): void
	{
		$parser = new Parser();
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png',
			],
		];

		$this->assertSame(
			'https://lh3.googleusercontent.com/a/real-remote=s120',
			$parser->get_reviewer_avatar_fallback_url($post)
		);
	}

	/** GDPR must never let the fallback reach out to the remote avatar. */
	public function test_fallback_is_placeholder_under_gdpr(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['gdpr' => 'yes'];

		$parser = new Parser();
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png',
			],
		];

		$this->assertSame(
			$this->placeholder(),
			$parser->get_reviewer_avatar_fallback_url($post),
			'Under GDPR the remote avatar stays suppressed.'
		);
	}

	/** Nothing left to try when the primary is already the placeholder. */
	public function test_fallback_is_empty_when_primary_is_placeholder(): void
	{
		$parser = new Parser();

		$this->assertSame('', $parser->get_reviewer_avatar_fallback_url(['reviewer' => []]));
	}

	/**
	 * When the local file is genuinely gone the primary URL is already the remote
	 * avatar, so the fallback must move on to the placeholder rather than repeat it.
	 */
	public function test_fallback_is_placeholder_when_primary_is_already_remote(): void
	{
		$parser = new Parser();
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => '',
			],
		];

		$this->assertSame($this->placeholder(), $parser->get_reviewer_avatar_fallback_url($post));
	}

	/**
	 * `get_reviewer_avatar_url()` now checks `should_store_local_images()` like its two
	 * siblings (`get_author_remote_avatar()`, `get_media_url()`) so the rendered image
	 * and `data-image-url` can never disagree about whether local images are in use.
	 *
	 * Whatever the setting, the two accessors must return the same thing for a healthy
	 * local avatar.
	 */
	public function test_rendered_avatar_and_data_image_url_agree(): void
	{
		$parser = new Parser();
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png',
			],
		];

		foreach ([true, false] as $optimize) {
			global $wp_options_mock;
			$wp_options_mock['sbr_settings'] = ['gdpr' => 'no', 'optimize_images' => $optimize];

			$this->assertSame(
				$parser->get_author_remote_avatar($post),
				$parser->get_reviewer_avatar_url($post),
				'The rendered image and data-image-url must not diverge (optimize_images='
				. var_export($optimize, true) . ').'
			);
		}
	}

	/**
	 * The Optimize Images switch is live (fixed in SMASH-1033 / #558). It used to end in
	 * `!empty(x) ? x : true`, so every falsy value read as on and the switch did nothing.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function optimizeImagesValues(): array
	{
		return [
			'true'           => [true, true],
			'false'          => [false, false],
			'0'              => [0, false],
			"'0'"            => ['0', false],
			"''"             => ['', false],
			'null'           => [null, false],
			"'false' string" => ['false', false],
			"'true' string"  => ['true', true],
			'1'              => [1, true],
		];
	}

	/**
	 * @dataProvider optimizeImagesValues
	 * @param mixed $value
	 */
	public function test_should_store_local_images_follows_the_setting($value, bool $expected): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['optimize_images' => $value];

		$this->assertSame($expected, \SmashBalloon\Reviews\Common\Util::should_store_local_images());
	}

	public function test_should_store_local_images_defaults_on_when_the_key_is_absent(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['gdpr' => 'no'];
		$this->assertTrue(\SmashBalloon\Reviews\Common\Util::should_store_local_images());

		$wp_options_mock['sbr_settings'] = 'not-an-array';
		$this->assertTrue(\SmashBalloon\Reviews\Common\Util::should_store_local_images());
	}

	public function test_optimize_images_off_serves_the_remote_avatar(): void
	{
		global $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['gdpr' => 'no', 'optimize_images' => false];
		$remote = 'https://lh3.googleusercontent.com/a/real-remote=s120';
		$post   = ['reviewer' => ['avatar' => $remote, 'avatar_local' => self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png']];

		$this->assertSame($remote, (new Parser())->get_reviewer_avatar_url($post));
	}

	/** The primary URL still prefers the local file when it genuinely serves. */
	public function test_primary_prefers_valid_local_avatar(): void
	{
		$parser = new Parser();
		$local  = self::BASEURL . '/sbr-feed-images/google-1785-avatar-150.png';
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => $local,
			],
		];

		$this->assertSame($local, $parser->get_reviewer_avatar_url($post));
	}

	/** ...and falls back to the remote one when the local URL cannot be trusted. */
	public function test_primary_falls_back_to_remote_when_local_url_is_dead(): void
	{
		$parser = new Parser();
		$post   = [
			'reviewer' => [
				'avatar'       => 'https://lh3.googleusercontent.com/a/real-remote=s120',
				'avatar_local' => self::BASEURL . '/sbr-feed-images/cdn-rewritten/google-1785-avatar-150.png',
			],
		];

		$this->assertSame(
			'https://lh3.googleusercontent.com/a/real-remote=s120',
			$parser->get_reviewer_avatar_url($post),
			'A dead local URL must never be emitted to the browser.'
		);
	}
}
