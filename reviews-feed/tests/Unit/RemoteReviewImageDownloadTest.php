<?php

namespace {
	require_once __DIR__ . '/Doubles/FakeImageEditor.php';

	// Minimal shims for the download path. Each records its calls so a test can
	// count downloads and check that no temp file is left behind.
	if (!defined('MB_IN_BYTES')) {
		define('MB_IN_BYTES', 1024 * 1024);
	}
	if (!function_exists('wp_tempnam')) {
		function wp_tempnam($filename = '')
		{
			return tempnam(sys_get_temp_dir(), 'sbr-img-');
		}
	}
	if (!function_exists('wp_delete_file')) {
		function wp_delete_file($file)
		{
			if (is_file($file)) {
				unlink($file);
			}
		}
	}
	if (!function_exists('wp_safe_remote_get')) {
		function wp_safe_remote_get($url, $args = [])
		{
			$GLOBALS['sbr_test_image_gets'][] = ['url' => $url, 'args' => $args];
			$GLOBALS['sbr_test_image_files'][] = $args['filename'] ?? '';

			global $wp_http_error_mock;
			if ($wp_http_error_mock instanceof \WP_Error) {
				return $wp_http_error_mock;
			}

			file_put_contents($args['filename'], $GLOBALS['sbr_test_image_body'] ?? 'image-bytes'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- test shim.
			return [];
		}
	}
	if (!function_exists('wp_get_image_editor')) {
		function wp_get_image_editor($path)
		{
			$GLOBALS['sbr_test_editor_paths'][] = $path;
			if (!empty($GLOBALS['sbr_test_editor_works'])) {
				return new \SmashBalloon\Reviews\Tests\Unit\Doubles\FakeImageEditor($path);
			}
			return new \WP_Error('test_no_editor', 'No editor in unit tests');
		}
	}
}

namespace SmashBalloon\Reviews\Tests\Unit {

	use PHPUnit\Framework\TestCase;
	use SmashBalloon\Reviews\Common\SinglePostCache;
	use SmashBalloon\Reviews\Pro\MediaFinder;
	use SmashBalloon\Reviews\Pro\SinglePostCache as ProSinglePostCache;


	/**
	 * SMASH-2544: remote review images are downloaded through the WordPress HTTP
	 * API before the image editor opens them. Passing the URL straight to
	 * wp_get_image_editor() made GD call exif_imagetype() on it, which printed a
	 * PHP notice on the first render of every Facebook reviews feed.
	 */
	final class RemoteReviewImageDownloadTest extends TestCase
	{
		protected function setUp(): void
		{
			parent::setUp();
			$GLOBALS['sbr_test_image_gets']    = [];
			$GLOBALS['sbr_test_image_files']   = [];
			$GLOBALS['sbr_test_editor_paths']  = [];
			$GLOBALS['wp_http_error_mock']     = null;
			$GLOBALS['wp_http_response_code_mock'] = 200;
			$GLOBALS['sbr_test_image_body']    = null;
			$GLOBALS['sbr_test_editor_works']  = false;
			$GLOBALS['sbr_test_resized']       = [];
			$GLOBALS['sbr_test_saved']         = [];
			$GLOBALS['sbr_test_file_during_resize'] = [];
		}

		protected function tearDown(): void
		{
			$GLOBALS['sbr_test_image_body']        = null;
			$GLOBALS['sbr_test_editor_works']      = false;
			$GLOBALS['wp_http_error_mock']         = null;
			$GLOBALS['wp_http_response_code_mock'] = null;
			parent::tearDown();
		}

		private function cache(array $extra = []): SinglePostCache
		{
			return new SinglePostCache(array_merge([
				'review_id' => 'dl-1',
				'provider'  => ['name' => 'facebook'],
				'reviewer'  => ['name' => 'A', 'avatar' => 'https://platform-lookaside.fbsbx.com/platform/profilepic/?psid=1'],
			], $extra));
		}

		private function assertNoTempFileLeft(): void
		{
			foreach ($GLOBALS['sbr_test_image_files'] as $file) {
				$this->assertFileDoesNotExist($file);
			}
		}

		public function test_editor_opens_a_local_file_not_the_url(): void
		{
			$this->cache()->resize_avatar(150);

			$this->assertCount(1, $GLOBALS['sbr_test_image_gets']);
			$args = $GLOBALS['sbr_test_image_gets'][0]['args'];
			$this->assertTrue($args['stream']);
			$this->assertSame(10, $args['timeout']);
			$this->assertSame(5 * MB_IN_BYTES, $args['limit_response_size']);
			$this->assertSame([$GLOBALS['sbr_test_image_files'][0]], $GLOBALS['sbr_test_editor_paths']);
			$this->assertNoTempFileLeft();
		}

		public function test_avatar_temp_file_still_exists_while_the_editor_resizes(): void
		{
			$GLOBALS['sbr_test_editor_works'] = true;

			$this->cache()->resize_avatar(150);

			$this->assertSame([true], $GLOBALS['sbr_test_file_during_resize']);
			$this->assertNoTempFileLeft();
		}

		public function test_pro_avatar_temp_file_still_exists_while_the_editor_resizes(): void
		{
			$GLOBALS['sbr_test_editor_works'] = true;
			$review = [
				'review_id' => 'dl-pro-av',
				'provider'  => ['name' => 'facebook'],
				'reviewer'  => ['name' => 'A', 'avatar' => 'https://platform-lookaside.fbsbx.com/platform/profilepic/?psid=1'],
			];

			(new ProSinglePostCache($review, new MediaFinder(null)))->resize_avatar(150);

			$this->assertSame([true], $GLOBALS['sbr_test_file_during_resize']);
			$this->assertNoTempFileLeft();
		}

		public function test_failed_download_marks_avatar_error_and_cleans_up(): void
		{
			$GLOBALS['wp_http_error_mock'] = new \WP_Error('http_request_failed', 'cURL error 28');
			$cache = $this->cache();

			$cache->resize_avatar(150);

			$this->assertSame('error', $cache->get_storage_data()['avatar_id']);
			$this->assertSame([], $GLOBALS['sbr_test_editor_paths']);
			$this->assertNoTempFileLeft();
		}

		public function test_http_error_status_marks_avatar_error_and_cleans_up(): void
		{
			$GLOBALS['wp_http_response_code_mock'] = 404;
			$cache = $this->cache();

			$cache->resize_avatar(150);

			$this->assertSame('error', $cache->get_storage_data()['avatar_id']);
			$this->assertSame([], $GLOBALS['sbr_test_editor_paths']);
			$this->assertNoTempFileLeft();
		}

		public function test_each_review_photo_is_downloaded_once_for_all_sizes(): void
		{
			$cache = $this->cache(['media' => [
				['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/a.jpg'],
				['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/b.jpg'],
			]]);

			$cache->resize_images([640, 150]);

			$this->assertCount(2, $GLOBALS['sbr_test_image_gets']);
			$this->assertCount(4, $GLOBALS['sbr_test_editor_paths']);
			$this->assertSame('error', $cache->get_storage_data()['media_id']);
			$this->assertNoTempFileLeft();
		}

		public function test_image_cut_off_at_the_size_cap_is_a_failure(): void
		{
			$GLOBALS['sbr_test_image_body'] = str_repeat('x', 5 * MB_IN_BYTES);
			$cache = $this->cache();

			$cache->resize_avatar(150);

			$this->assertSame('error', $cache->get_storage_data()['avatar_id']);
			$this->assertSame([], $GLOBALS['sbr_test_editor_paths']);
			$this->assertNoTempFileLeft();
		}

		public function test_pro_saves_every_size_from_one_download_per_photo(): void
		{
			$GLOBALS['sbr_test_editor_works'] = true;
			$review = [
				'review_id' => 'dl-pro',
				'provider'  => ['name' => 'facebook'],
				'reviewer'  => ['name' => 'A'],
				'media'     => [
					['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/a.jpg'],
					['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/b.jpg'],
				],
			];
			$cache = new ProSinglePostCache($review, new MediaFinder(null));

			$cache->resize_images([640, 150]);

			$this->assertCount(2, $GLOBALS['sbr_test_image_gets']);
			$this->assertSame([640, 150, 640, 150], $GLOBALS['sbr_test_resized']);
			$this->assertSame(
				['facebook-dl-pro-0-640.jpg', 'facebook-dl-pro-0-150.jpg', 'facebook-dl-pro-1-640.jpg', 'facebook-dl-pro-1-150.jpg'],
				$GLOBALS['sbr_test_saved']
			);
			$data = $cache->get_storage_data();
			$this->assertSame('facebook-dl-pro', $data['media_id']);
			$this->assertSame(1.5, $data['aspect_ratio']);
			$this->assertNoTempFileLeft();
		}
	}
}
