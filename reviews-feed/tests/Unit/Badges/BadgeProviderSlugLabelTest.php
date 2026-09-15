<?php

namespace SmashBalloon\Reviews\Tests\Unit\Badges;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Util;
use SmashBalloon\Reviews\Common\DisplayElements;
use SmashBalloon\Reviews\Common\Badges\SBR_Badge_Renderer;

/**
 * A badge names the platform its rating came from (SBR-163).
 *
 * provider_label() matches Util::get_providers() on an EXACT `type`, and one of those
 * types has a dot in it: `wordpress.org`. Any sanitiser that drops the dot — sanitize_key()
 * does — turns the slug into `wordpressorg`, which matches nothing, so the badge loses
 * the platform name from its subtext and the All Badges row prints the raw slug.
 *
 * Driven off the real registry rather than a list of slugs, so a provider added later
 * with a dot or an underscore is covered without touching this file.
 */
class BadgeProviderSlugLabelTest extends TestCase
{
	/** @var array<string, mixed>|null */
	private $saved_transients;

	protected function setUp(): void
	{
		parent::setUp();

		$this->saved_transients = $GLOBALS['wp_transients_mock'] ?? null;
	}

	/**
	 * Put the transient mock back.
	 *
	 * A test below writes $wp_transients_mock to drive read_cache(). PHPUnit runs
	 * the suite in one process, so leaving it set hands a primed cache to every
	 * later test — which shows up as an order-dependent pass, not a clean failure.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		if ($this->saved_transients === null) {
			unset($GLOBALS['wp_transients_mock']);
		} else {
			$GLOBALS['wp_transients_mock'] = $this->saved_transients;
		}

		parent::tearDown();
	}

	/**
	 * Every provider the plugin ships must survive the sanitiser the badge uses and
	 * still resolve to its own name.
	 *
	 * @return void
	 */
	public function test_every_provider_type_round_trips_to_its_label()
	{
		$checked = 0;

		foreach (Util::get_providers() as $provider) {
			if (! isset($provider['type'], $provider['name'])) {
				continue;
			}

			$type = (string) $provider['type'];
			$slug = Util::sanitize_provider_slug($type);

			$this->assertSame($type, $slug, 'The sanitiser must not alter a real provider type: ' . $type);
			$this->assertSame(
				(string) $provider['name'],
				SBR_Badge_Renderer::provider_label($slug),
				'A sanitised provider type must still name its platform: ' . $type
			);

			++$checked;
		}

		$this->assertGreaterThan(0, $checked, 'The provider registry answered nothing to check.');
	}

	/**
	 * The specific slug that broke, kept explicit so the reason survives even if the
	 * registry is refactored.
	 *
	 * @return void
	 */
	public function test_the_dotted_wordpress_org_slug_keeps_its_dot_and_its_name()
	{
		$this->assertSame('wordpress.org', Util::sanitize_provider_slug('wordpress.org'));
		$this->assertSame(
			'WordPress.org',
			SBR_Badge_Renderer::provider_label(Util::sanitize_provider_slug('wordpress.org'))
		);
	}

	/**
	 * The cache read is one of the two places the badge re-sanitises a slug, and the
	 * one reachable without a database. Drives it directly, so swapping the sanitiser
	 * back to sanitize_key() fails here rather than only in QA.
	 *
	 * @return void
	 */
	public function test_read_cache_keeps_a_dotted_slug_it_read_back()
	{
		global $wp_transients_mock;

		$key = 'sbr_badge_test_wporg';

		$wp_transients_mock = array(
			$key => array(
				'rating'    => 4.8,
				'count'     => 12,
				'resolved'  => true,
				'providers' => array( 'wordpress.org', 'google' ),
			),
		);

		$method = new \ReflectionMethod(SBR_Badge_Renderer::class, 'read_cache');
		$method->setAccessible(true);

		$out = $method->invoke(null, $key);

		$this->assertSame(array( 'wordpress.org', 'google' ), $out['providers']);
		$this->assertSame('WordPress.org', SBR_Badge_Renderer::provider_label($out['providers'][0]));

		$wp_transients_mock = array();
	}

	/**
	 * The other half of the same slug. The data slug keeps its dot so the label
	 * resolves; the artwork and the CSS class must NOT, because the shipped files are
	 * `wordpressorg-*.svg` and `.sbr-tb-lockup-wordpress.org` parses as two classes.
	 * Fixing the label without this traded one regression for another.
	 *
	 * @return void
	 */
	public function test_a_dotted_slug_still_finds_its_mono_and_lockup_artwork()
	{
		$this->assertSame('wordpressorg', DisplayElements::provider_asset_slug('wordpress.org'));

		$this->assertTrue(
			DisplayElements::has_provider_mono_icon('wordpress.org'),
			'The mono mark ships as wordpressorg-mono.svg and must still be found.'
		);
		$this->assertTrue(
			DisplayElements::has_provider_lockup('wordpress.org'),
			'The lockup ships as wordpressorg-lockup.svg and must still be found.'
		);
		$this->assertNotSame('', DisplayElements::get_provider_mono_icon('wordpress.org'));
		$this->assertNotSame('', DisplayElements::get_provider_lockup('wordpress.org'));
		$this->assertNotSame('', DisplayElements::get_provider_icon('wordpress.org'));
	}

	/**
	 * The asset slug is still a file name, so traversal must not survive it either.
	 *
	 * @return void
	 */
	public function test_the_asset_slug_cannot_escape_the_icons_directory()
	{
		$this->assertSame('etcpasswd', DisplayElements::provider_asset_slug('../../etc/passwd'));
		$this->assertSame('googleyelp', DisplayElements::provider_asset_slug('google/yelp'));
		$this->assertSame('', DisplayElements::provider_asset_slug('...'));
	}

	/**
	 * The sanitiser still has to be a sanitiser: a slug reaches logos.php and picks a
	 * file path there, so path traversal and stray characters must not survive.
	 *
	 * @dataProvider hostileProvider
	 *
	 * @param string $raw      What arrives.
	 * @param string $expected What may reach a file path.
	 *
	 * @return void
	 */
	public function test_a_hostile_slug_is_still_stripped($raw, $expected)
	{
		$this->assertSame($expected, Util::sanitize_provider_slug($raw));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function hostileProvider()
	{
		return array(
			'traversal'      => array( '../../etc/passwd', 'etcpasswd' ),
			'dot segments'   => array( 'google..org', 'googleorg' ),
			'slashes'        => array( 'google/yelp', 'googleyelp' ),
			'uppercase'      => array( 'WordPress.Org', 'wordpress.org' ),
			'spaces'         => array( '  yelp  ', 'yelp' ),
			'null byte'      => array( "google\0", 'google' ),
		);
	}
}
