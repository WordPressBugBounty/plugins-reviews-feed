<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\DisplayElements;
use SmashBalloon\Reviews\Common\FeedDisplay;

/**
 * SMASH-2106 — the feed asked for a provider icon filename that has never shipped.
 *
 * The data slug keeps its dot ('wordpress.org', WordpressOrg::$name) because
 * provider comparisons are literal; asset names cannot, so the shipped artwork is
 * `wordpressorg-provider.svg`. provider_icon_url() concatenated the raw slug, so a
 * WP.org review card emitted `wordpress.org-provider.svg` — a path under the WP
 * root, which WordPress rewrites to index.php and answers with a full 404 page per
 * card rendered. Verified live before the fix: the dotted URL returned HTTP 404
 * text/html, the folded one HTTP 200 image/svg+xml.
 *
 * Eight of the nine icon-path callers already normalised through
 * DisplayElements::provider_asset_slug(); this one did not.
 */
final class Smash2106ProviderIconUrlSlugTest extends TestCase
{
	/**
	 * A FeedDisplay whose parser reports $provider. Reflection rather than the real
	 * constructor, which needs a Feed and a Parser (same approach as
	 * Smash2084FreeHeaderRatingTest).
	 */
	private function display(string $provider): FeedDisplay
	{
		$parser = new class ($provider) {
			/** @var string */
			private $provider;
			public function __construct(string $provider)
			{
				$this->provider = $provider;
			}
			/**
			 * @param  array<string,mixed> $post_or_business
			 * @return string
			 */
			public function get_provider_name($post_or_business): string
			{
				return $this->provider;
			}
		};

		$display = (new \ReflectionClass(FeedDisplay::class))->newInstanceWithoutConstructor();
		$prop    = new \ReflectionProperty(FeedDisplay::class, 'parser');
		$prop->setAccessible(true);
		$prop->setValue($display, $parser);

		return $display;
	}

	/**
	 * The regression itself. Asserts the FULL url, not just "no dot": a test that
	 * only checked for the absence of '.' would also pass on a url that had lost
	 * the .svg extension.
	 */
	public function test_wordpressorg_asks_for_the_file_that_ships(): void
	{
		$url = $this->display('wordpress.org')->provider_icon_url([], []);

		$this->assertSame(
			SBR_PLUGIN_URL . 'assets/icons/wordpressorg-provider.svg',
			$url
		);
	}

	/**
	 * The url must name a file that is actually in the repo — the whole defect was
	 * a name nothing on disk answered to. This is the assertion that would have
	 * caught it without anyone visiting a site.
	 */
	public function test_every_shipped_provider_resolves_to_a_real_file(): void
	{
		$providers = ['wordpress.org', 'google', 'yelp', 'edd', 'woocommerce', 'tripadvisor', 'trustpilot', 'facebook', 'airbnb', 'aliexpress', 'booking'];

		foreach ($providers as $provider) {
			$url  = $this->display($provider)->provider_icon_url([], []);
			$file = substr($url, strlen(SBR_PLUGIN_URL));
			$path = SBR_PLUGIN_DIR . $file;

			$this->assertFileExists($path, "no artwork on disk for provider '{$provider}' (url: {$url})");
		}
	}

	/** An empty provider draws nothing rather than pointing at a stray filename. */
	public function test_empty_provider_returns_no_url(): void
	{
		$this->assertNull($this->display('')->provider_icon_url([], []));
	}

	/**
	 * The PHP and the Review Alerts JS build the same filename from the same slug.
	 * The JS cannot call the PHP helper, so it folds the dot itself
	 * (assets/js/sbr-review-alerts.js) — pin that the two stay in step, since a
	 * divergence here is invisible until a WP.org alert renders.
	 */
	public function test_js_alert_path_folds_the_dot_the_same_way(): void
	{
		$js = file_get_contents(SBR_PLUGIN_DIR . 'assets/js/sbr-review-alerts.js');

		$this->assertNotFalse($js, 'could not read sbr-review-alerts.js');
		$this->assertStringContainsString(
			"replace(/\\./g, '')",
			$js,
			'the alerts JS no longer folds the dot before building the icon url'
		);
		$this->assertStringContainsString(
			"'assets/icons/' + assetSlug + '-provider.svg'",
			$js,
			'the alerts JS builds the icon url from something other than the folded slug'
		);
	}

	/** The helper the fix leans on, pinned at its contract. */
	public function test_provider_asset_slug_folds_the_dot(): void
	{
		$this->assertSame('wordpressorg', DisplayElements::provider_asset_slug('wordpress.org'));
		$this->assertSame('google', DisplayElements::provider_asset_slug('google'));
	}
}
