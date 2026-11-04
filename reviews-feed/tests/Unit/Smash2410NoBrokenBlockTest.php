<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\FeedDisplay;
use SmashBalloon\Reviews\Pro\FeedDisplay as ProFeedDisplay;

/**
 * SMASH-2410 (Fallback Sources, done-bar d): a feed whose source failed and has no stored reviews showed visitors
 * a header and footer around nothing (Phase 0, DDEV). It now shows visitors nothing and admins the error box; an
 * honestly empty feed (filters, a source with no reviews) is untouched.
 */
class Smash2410NoBrokenBlockTest extends TestCase
{
	/** @var mixed */
	private $saved_can;

	protected function setUp(): void
	{
		parent::setUp();
		$this->saved_can = $GLOBALS['sbr_test_user_can'] ?? null;
		$GLOBALS['sbr_test_actions'] = [];
	}

	protected function tearDown(): void
	{
		$GLOBALS['sbr_test_user_can'] = $this->saved_can;
		$GLOBALS['sbr_test_actions'] = [];
		parent::tearDown();
	}

	/**
	 * A real Common Feed with the given cached posts, sources and errors.
	 *
	 * @param list<array<string, mixed>> $posts
	 * @param list<array<string, mixed>> $sources
	 * @param list<array<string, mixed>> $errors
	 */
	private function feed(array $posts, array $sources, array $errors = []): CommonFeed
	{
		$feed = (new \ReflectionClass(CommonFeed::class))->newInstanceWithoutConstructor();
		$set = static function (string $name, $value) use ($feed): void {
			$prop = new \ReflectionProperty(CommonFeed::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($feed, $value);
		};
		$set('settings', ['sources' => $sources, 'apiCallLanguage' => '', 'numPostDesktop' => 4, 'numPostTablet' => 4, 'numPostMobile' => 4]);
		$set('posts', $posts);
		$set('statuses', ['errors' => $errors]);
		$set('feed_id', 7);

		return $feed;
	}

	private function display(CommonFeed $feed, string $class = FeedDisplay::class): FeedDisplay
	{
		$display = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty(FeedDisplay::class, 'feed');
		$prop->setAccessible(true);
		$prop->setValue($display, $feed);

		return $display;
	}

	private function source(string $error): array
	{
		return ['provider' => 'trustpilot', 'account_id' => 'tp-1', 'name' => 'Acme', 'error' => $error];
	}

	private const FAILED = '{"id":"reviewsSourceNotCreated","scope":"source","attempts":0,"since":1}';

	/** A relay error recorded this request, the way add_relay_error() stores it. */
	private const RELAY_ERROR = ['message' => 'The reviews server couldn&#039;t fetch reviews', 'directions' => 'Troubleshoot', 'error_id' => 'relayUnreachable', 'summary' => 'x'];

	/** TripAdvisor's honestly-empty answer reaches the feed as a relay error (add_relay_error) — it is not a failure. */
	private const NO_REVIEWS_ERROR = ['message' => 'x', 'directions' => 'y', 'error_id' => 'noReviewsFound', 'summary' => 'x'];

	public function test_a_visitor_gets_nothing_for_a_failed_empty_feed(): void
	{
		$GLOBALS['sbr_test_user_can'] = false;

		$html = $this->display($this->feed([], [$this->source(self::FAILED)]))->with_wrap();

		$this->assertSame('', $html);
	}

	public function test_an_admin_gets_only_the_error_box(): void
	{
		$GLOBALS['sbr_test_user_can'] = true;

		$html = $this->display($this->feed([], [$this->source(self::FAILED)], [self::RELAY_ERROR]))->with_wrap();

		$this->assertStringContainsString('class="sbr-feed-error"', $html);
		$this->assertStringNotContainsString('sb-feed-header', $html);
		$this->assertStringNotContainsString('sb-feed-container', $html);
	}

	public function test_the_feed_hooks_still_fire(): void
	{
		$GLOBALS['sbr_test_user_can'] = false;

		$this->display($this->feed([], [$this->source(self::FAILED)]))->with_wrap();

		$this->assertSame(['sbr_before_feed', 'sbr_before_feed_end', 'sbr_after_feed'], $GLOBALS['sbr_test_actions']);
	}

	public function test_pro_display_takes_the_same_path(): void
	{
		$GLOBALS['sbr_test_user_can'] = false;

		$this->assertSame('', $this->display($this->feed([], [$this->source(self::FAILED)]), ProFeedDisplay::class)->with_wrap());
	}

	/**
	 * Pre-push Seer: the render that DISCOVERS the failure must already see it. A data.error (Graph, Woo, EDD) carries
	 * no relay id, so only the mirrored state can tell — and a recovery must un-hide in the same render too.
	 */
	public function test_the_same_request_sees_a_failure_it_just_recorded_and_a_recovery_it_just_cleared(): void
	{
		global $wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock;
		$saved = [$wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $GLOBALS['wpdb'] ?? null];
		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			public function update($t, $d, $w, $f = null, $wf = null)
			{
				return 1;
			}
			public function query($s)
			{
				return 0;
			}
			public function get_results($s, $o = null)
			{
				return [];
			}
			public function get_var($s)
			{
				return null;
			}
			public function get_row($s, $o = null)
			{
				return null;
			}
			public function prepare($q, ...$a)
			{
				return $q;
			}
		};
		try {
			$source = ['provider' => 'trustpilot', 'account_id' => 'tp-1', 'name' => 'Acme', 'business' => 'acme.example', 'info' => [], 'error' => ''];
			$feed = $this->feed([], [$source]);
			$set = static function (string $name, $value) use ($feed): void {
				$prop = new \ReflectionProperty(CommonFeed::class, $name);
				$prop->setAccessible(true);
				$prop->setValue($feed, $value);
			};
			$set('providers_languages', ['facebook', 'google']);
			$set('providers_no_media', []);

			$wp_http_error_mock = null;
			$wp_http_response_code_mock = 200;
			$wp_http_body_mock = '{"success":true,"data":{"error":"Graph said no"}}';
			$feed->api_request([$source]);
			$this->assertTrue($feed->has_failed_source(), 'a data.error found this request must count');

			$sources = $feed->get_settings()['sources'];
			$wp_http_body_mock = '{"success":true,"data":{"reviews":[]}}';
			\SmashBalloon\Reviews\Common\RemoteRequest::flush_memo();
			$feed->api_request($sources);
			$this->assertFalse($feed->has_failed_source(), 'a recovery this request must un-hide');
		} finally {
			[$wp_http_body_mock, $wp_http_response_code_mock, $wp_http_error_mock, $GLOBALS['wpdb']] = $saved;
			\SmashBalloon\Reviews\Common\RemoteRequest::flush_memo();
		}
	}

	/** @dataProvider stateProvider */
	public function test_the_failed_empty_state_applies_only_when_a_source_failed_and_nothing_is_stored(array $posts, string $error, array $errors, bool $expected): void
	{
		$this->assertSame($expected, $this->display($this->feed($posts, [$this->source($error)], $errors))->shows_failed_empty_state());
	}

	/** @return array<string, array{0: array, 1: string, 2: array, 3: bool}> */
	public static function stateProvider(): array
	{
		$post = ['review_id' => 'r1', 'text' => 'Great'];

		return [
			'failed, nothing stored'                        => [[], self::FAILED, [], true],
			'a relay error the classifier did not call failed (noReviewsFound, a refusal)' => [[], '', [self::NO_REVIEWS_ERROR], false],
			'failed, but stored reviews show'               => [[$post], self::FAILED, [], false],
			'honestly empty (filters / no reviews)'         => [[], '', [], false],
			'a filter-empty "No Posts Found" error only'    => [[], '', [['message' => 'No Posts Found', 'directions' => '']], false],
			'a value this version did not write'            => [[], 'legacy', [], false],
		];
	}
}
