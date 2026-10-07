<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Builder\SBR_Feed_Saver_Manager;

/**
 * SMASH-1706 (re-scoped 2026-08-27) — TripAdvisor inputs with no usable location id.
 *
 * The extractor only understands the `-d<digits>` listing token (and a bare id).
 * Everything else went to the relay as a non-numeric `place_id`: geo/list pages
 * (`Tourism-g…`, `Attractions-g…`, `Restaurants-g…`, `Hotels-g…`), member
 * profiles, free text, and — because the regex demanded 4+ digits — real
 * listings with a short id. Each cost a relay round trip that could not resolve.
 *
 * add_source() runs the input through esc_url_raw() before it gets here, so the
 * server sees `http://2422991` for a bare id and `http://my%20business` for free
 * text (measured with the real WP function). Both shapes are pinned below.
 */
final class Smash1706TripAdvisorNonListingUrlTest extends TestCase
{
	protected function setUp(): void
	{
		$GLOBALS['sbr_test_http_gets']  = [];
		$GLOBALS['sbr_test_http_posts'] = [];
		$GLOBALS['wp_options_mock']     = [];
	}

	protected function tearDown(): void
	{
		unset($GLOBALS['sbr_test_http_gets'], $GLOBALS['sbr_test_http_posts'], $GLOBALS['wp_options_mock']);
	}

	/**
	 * AC1 — a short listing id is a listing id.
	 */
	public function test_a_listing_id_of_any_length_is_extracted(): void
	{
		$this->assertSame(
			'123',
			SBR_Feed_Saver_Manager::get_place_id_tripadvisor('https://www.tripadvisor.com/Attraction_Review-g154948-d123')
		);
		$this->assertSame(
			'7',
			SBR_Feed_Saver_Manager::get_place_id_tripadvisor('https://www.tripadvisor.com/Restaurant_Review-g60763-d7-Reviews-X.html')
		);
		$this->assertNull(
			SBR_Feed_Saver_Manager::get_tripadvisor_input_error('https://www.tripadvisor.com/Attraction_Review-g154948-d123')
		);
	}

	/**
	 * BC (Rule 6 / AC6) — the forms the ticket measured as working today keep
	 * the same id and are not rejected by the new guard.
	 *
	 * @dataProvider workingFormProvider
	 */
	public function test_forms_that_work_today_still_resolve_and_pass_the_guard(string $input, string $expected): void
	{
		$this->assertSame($expected, SBR_Feed_Saver_Manager::get_place_id_tripadvisor($input), $input);
		$this->assertNull(SBR_Feed_Saver_Manager::get_tripadvisor_input_error($input), $input);

		// What add_source() actually hands the extractor.
		$sanitised = esc_url_raw($input);
		$this->assertSame($expected, SBR_Feed_Saver_Manager::get_place_id_tripadvisor($sanitised), $sanitised);
		$this->assertNull(SBR_Feed_Saver_Manager::get_tripadvisor_input_error($sanitised), $sanitised);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function workingFormProvider(): array
	{
		$base = 'https://www.tripadvisor.com/';

		return [
			'Attraction_Review short'  => [$base . 'Attraction_Review-g154948-d2422991', '2422991'],
			'Attraction_Review .html'  => [$base . 'Attraction_Review-g154948-d2422991-Reviews-The_Adventure_Group-Whistler_British_Columbia.html', '2422991'],
			'Restaurant_Review'        => [$base . 'Restaurant_Review-g60763-d477942-Reviews-Katz_s_Delicatessen-New_York_City_New_York.html', '477942'],
			'paged -or10-'             => [$base . 'Attraction_Review-g154948-d2422991-Reviews-or10-The_Adventure_Group-Whistler_British_Columbia.html', '2422991'],
			'ShowUserReviews -r<id>'   => [$base . 'ShowUserReviews-g154948-d2422991-r912345678-The_Adventure_Group-Whistler_British_Columbia.html', '2422991'],
			'AttractionProductReview'  => [$base . 'AttractionProductReview-g60763-d11447537-Statue_of_Liberty_Tour-New_York_City_New_York.html', '11447537'],
			'VacationRentalReview'     => [$base . 'VacationRentalReview-g154948-d12345678-Ski_In_Condo-Whistler_British_Columbia.html', '12345678'],
			'trailing slash'           => [$base . 'Attraction_Review-g154948-d2422991/', '2422991'],
			'?query'                   => [$base . 'Attraction_Review-g154948-d2422991?m=19905', '2422991'],
			'#fragment'                => [$base . 'Attraction_Review-g154948-d2422991#REVIEWS', '2422991'],
			'Airline_Review (no -g)'   => [$base . 'Airline_Review-d8729157-Reviews-Delta-Air-Lines', '8729157'],
			'.com.au'                  => ['https://www.tripadvisor.com.au/Attraction_Review-g154948-d2422991', '2422991'],
			'.co.nz'                   => ['https://www.tripadvisor.co.nz/Attraction_Review-g154948-d2422991', '2422991'],
			'uppercase -D'             => [$base . 'Attraction_Review-g154948-D2422991', '2422991'],
			'bare id'                  => ['2422991', '2422991'],
			// The extractor's legacy last-segment fallback, no -d token at all.
			'numeric last segment'     => [$base . 'Attraction_Review/2422991', '2422991'],
		];
	}

	/**
	 * AC2 / AC3 / AC4 — inputs that carry no location id are named for what they are.
	 *
	 * @dataProvider nonListingProvider
	 */
	public function test_inputs_with_no_location_id_are_rejected_with_a_reason(string $input, string $needle): void
	{
		foreach ([$input, esc_url_raw($input)] as $candidate) {
			$error = SBR_Feed_Saver_Manager::get_tripadvisor_input_error($candidate);

			$this->assertIsString($error, "Not rejected: '{$candidate}'");
			$this->assertStringContainsString($needle, $error, "Wrong reason for '{$candidate}'");
		}
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function nonListingProvider(): array
	{
		$base = 'https://www.tripadvisor.com/';

		return [
			'Tourism geo page'     => [$base . 'Tourism-g154948-Whistler_British_Columbia-Vacations.html', 'category page'],
			'Attractions list'     => [$base . 'Attractions-g154948-Activities-Whistler_British_Columbia.html', 'category page'],
			'Restaurants list'     => [$base . 'Restaurants-g60763-New_York_City.html', 'category page'],
			'Hotels list'          => [$base . 'Hotels-g60763-New_York_City-Hotels.html', 'category page'],
			'member profile'       => [$base . 'Profile/SomeTraveller', 'member profile'],
			'profile, id-ish name' => [$base . 'Profile/Traveller-d2422991', 'member profile'],
			'empty'                => ['', 'Enter a listing URL or ID first.'],
			'whitespace'           => ["   \t", 'Enter a listing URL or ID first.'],
			'free text'            => ['my business', 'location ID'],
			'site root'            => [$base, 'location ID'],
			// get_place_id_tripadvisor() reads "http://2422991/" as an empty id.
			'bare id, trailing /'  => ['2422991/', 'location ID'],
		];
	}

	/**
	 * AC5 — the guard runs at the real call site, before any relay request.
	 *
	 * @dataProvider callSiteRejectProvider
	 */
	public function test_add_source_path_makes_no_relay_call_for_an_unresolvable_input(string $input): void
	{
		$result = SBR_Feed_Saver_Manager::process_source_apikey([
			'provider'      => 'tripadvisor',
			'providerIdUrl' => esc_url_raw($input),
			'apiKey'        => 'test-key',
		]);

		$this->assertSame([], $GLOBALS['sbr_test_http_gets'], 'A relay GET was sent for: ' . $input);
		$this->assertSame([], $GLOBALS['sbr_test_http_posts'], 'A relay POST was sent for: ' . $input);
		$this->assertIsArray($result);
		$this->assertSame('invalidSourceUrl', $result['error'] ?? null);
		$this->assertFalse($result['success'] ?? null);
		$this->assertSame(SBR_Feed_Saver_Manager::get_tripadvisor_input_error(esc_url_raw($input)), $result['message'] ?? null);
		// Same housekeeping keys every other early return carries.
		$this->assertArrayHasKey('apiKeyLimits', $result);
		$this->assertArrayHasKey('pluginNotices', $result);
	}

	/**
	 * Control for the test above: a real listing URL does reach the relay, with
	 * the extracted id. Without this, "no request recorded" could mean the
	 * recorder never sees the sources call at all.
	 */
	public function test_add_source_path_still_calls_the_relay_for_a_listing(): void
	{
		SBR_Feed_Saver_Manager::process_source_apikey([
			'provider'      => 'tripadvisor',
			'providerIdUrl' => esc_url_raw('https://www.tripadvisor.com/Attraction_Review-g154948-d123'),
			'apiKey'        => 'test-key',
		]);

		$this->assertCount(1, $GLOBALS['sbr_test_http_gets']);
		$this->assertStringContainsString('place_id=123', (string) $GLOBALS['sbr_test_http_gets'][0]['url']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function callSiteRejectProvider(): array
	{
		return [
			'geo page'  => ['https://www.tripadvisor.com/Tourism-g154948-Whistler_British_Columbia-Vacations.html'],
			'profile'   => ['https://www.tripadvisor.com/Profile/SomeTraveller'],
			'free text' => ['my business'],
			'empty'     => [''],
		];
	}

	/**
	 * Whitespace around a pasted id or URL is not part of it. esc_url_raw() only
	 * ltrim()s and turns a trailing space into %20, so " 2422991 " used to reach
	 * the guard as "http://2422991%20" and be refused as having no location id.
	 *
	 * @dataProvider paddedInputProvider
	 */
	public function test_add_source_trims_the_pasted_input_before_it_is_parsed(string $input): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST = [
			'provider'      => 'tripadvisor',
			'providerIdUrl' => $input,
			'apiKey'        => 'test-key',
		];

		ob_start();
		try {
			SBR_Feed_Saver_Manager::add_source();
		} catch (\SBR_Test_Halt $halt) {
			// add_source() ends with wp_die() once it has echoed the response.
		} finally {
			$body = (string) ob_get_clean();
			unset($GLOBALS['sbr_test_nonce_ok'], $GLOBALS['sbr_test_user_can'], $GLOBALS['sbr_test_nonce_actions_checked']);
			$_POST = [];
		}

		$response = json_decode($body, true);
		$this->assertIsArray($response, 'add_source() echoed: ' . substr($body, 0, 200));
		$this->assertNotSame('invalidSourceUrl', $response['error'] ?? null, 'Refused: ' . ($response['message'] ?? ''));
		$this->assertCount(1, $GLOBALS['sbr_test_http_gets'], 'The listing must reach the relay');
		$this->assertStringContainsString('place_id=2422991', (string) $GLOBALS['sbr_test_http_gets'][0]['url']);
		$this->assertStringNotContainsString('%20', (string) $GLOBALS['sbr_test_http_gets'][0]['url']);
	}

	public function test_add_source_with_an_array_input_refuses_without_a_php_warning(): void
	{
		$GLOBALS['sbr_test_nonce_ok'] = true;
		$GLOBALS['sbr_test_user_can'] = true;
		$_POST = [
			'provider'      => 'tripadvisor',
			'providerIdUrl' => ['2422991'],
			'apiKey'        => 'test-key',
		];
		$warnings = [];
		set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
			$warnings[] = $errstr;
			return true;
		});

		ob_start();
		try {
			SBR_Feed_Saver_Manager::add_source();
		} catch (\SBR_Test_Halt $halt) {
			// add_source() ends with wp_die() once it has echoed the response.
		} finally {
			$body = (string) ob_get_clean();
			restore_error_handler();
			unset($GLOBALS['sbr_test_nonce_ok'], $GLOBALS['sbr_test_user_can'], $GLOBALS['sbr_test_nonce_actions_checked']);
			$_POST = [];
		}

		$this->assertSame([], $warnings);
		$response = json_decode($body, true);
		$this->assertIsArray($response, 'add_source() echoed: ' . substr($body, 0, 200));
		$this->assertSame('invalidSourceUrl', $response['error'] ?? null);
		$this->assertSame([], $GLOBALS['sbr_test_http_gets'], 'An array input must not reach the relay');
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function paddedInputProvider(): array
	{
		return [
			'bare id, both sides'  => [' 2422991 '],
			'bare id, trailing'    => ['2422991 '],
			'bare id, tab/newline' => ["\t2422991\n"],
			'url, trailing space'  => ['https://www.tripadvisor.com/Attraction_Review-g154948-d2422991 '],
		];
	}

	/**
	 * BC (Rule 6) — the key-only probe from update_api_key() sends no providerIdUrl
	 * at all. The guard must not fire there: that path has always sent 'XXX'.
	 */
	public function test_the_key_only_probe_is_not_rejected(): void
	{
		$result = SBR_Feed_Saver_Manager::process_source_apikey([
			'provider' => 'tripadvisor',
			'apiKey'   => 'test-key',
		]);

		$this->assertIsArray($result);
		$this->assertNotSame('invalidSourceUrl', $result['error'] ?? null);
	}
}
