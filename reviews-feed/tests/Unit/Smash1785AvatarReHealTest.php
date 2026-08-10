<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SinglePostCache;

/**
 * SMASH-1785 AC #3 — a localized avatar file that goes missing must come back.
 *
 * `resize_avatar()` only ran for brand-new reviews (`!db_record_exists()`), and
 * `avatar_id` was written once at insert: `db_record_exists()` never mirrored it and
 * `update_single()` never wrote it. So after "Clear Local Images", a migration, or a
 * host cleanup, an already-cached review pointed at a file that no longer existed and
 * nothing ever rebuilt it.
 *
 * `localized_avatar_missing()` is the detection the fetch path now uses to decide
 * whether to re-resize. `'error'` is deliberately excluded: that download already
 * failed once, and retrying every fetch would hammer a permanently bad remote URL.
 */
final class Smash1785AvatarReHealTest extends TestCase
{
	/** Matches the tests/bootstrap.php wp_upload_dir() shim. */
	private const BASEDIR = '/tmp/uploads';

	/** @var string */
	private $avatar_dir;

	protected function setUp(): void
	{
		parent::setUp();
		$this->avatar_dir = self::BASEDIR . '/sbr-feed-images/';
		if (!is_dir($this->avatar_dir)) {
			mkdir($this->avatar_dir, 0777, true);
		}
	}

	protected function tearDown(): void
	{
		foreach (glob($this->avatar_dir . 'google-reheal*') ?: [] as $f) {
			unlink($f);
		}
		parent::tearDown();
	}

	/**
	 * Build a cache object with only the storage_data we care about. The constructor
	 * needs a review array, so give it the minimum shape.
	 */
	private function cacheWithAvatarId($avatar_id): SinglePostCache
	{
		$cache = new SinglePostCache([
			'review_id' => 'reheal-1',
			'rating'    => 5,
			'time'      => 1700000000,
			'text'      => 'x',
			'provider'  => ['name' => 'google'],
			'reviewer'  => ['name' => 'A', 'avatar' => 'https://lh3.googleusercontent.com/a/x=s120'],
		]);
		$cache->set_storage_data('avatar_id', $avatar_id);

		return $cache;
	}

	/**
	 * REGRESSION GUARD: `localized_avatar_missing()` is defined once on
	 * Common\SinglePostCache, but BOTH that class and Pro\SinglePostCache declare
	 * `private $storage_data`. A method defined on the parent therefore only ever sees
	 * the parent's copy — which the Pro subclass never populates — so reading
	 * `$this->storage_data` directly made the Pro path silently answer "not missing"
	 * for every review. It reads through the overridden `get_storage_data()` instead.
	 *
	 * Caught live: the Pro object reported `false` while the file was demonstrably
	 * absent. Pro is the class that actually runs for paying customers, so this must
	 * stay covered.
	 */
	public function test_detection_works_on_the_pro_subclass_too(): void
	{
		$review = [
			'review_id' => 'reheal-pro',
			'rating'    => 5,
			'time'      => 1700000000,
			'text'      => 'x',
			'provider'  => ['name' => 'google'],
			'reviewer'  => ['name' => 'A', 'avatar' => 'https://lh3.googleusercontent.com/a/x=s120'],
		];

		$pro = new \SmashBalloon\Reviews\Pro\SinglePostCache(
			$review,
			new \SmashBalloon\Reviews\Pro\MediaFinder(null)
		);
		$pro->set_storage_data('avatar_id', 'google-reheal-pro-gone-avatar-150');

		$this->assertTrue(
			$pro->localized_avatar_missing(),
			'Pro\SinglePostCache must see its own storage_data — read via get_storage_data(), '
			. 'never $this->storage_data, because both classes declare it private.'
		);

		file_put_contents($this->avatar_dir . 'google-reheal-pro-here-avatar-150.png', 'png');
		$pro->set_storage_data('avatar_id', 'google-reheal-pro-here-avatar-150');

		$this->assertFalse(
			$pro->localized_avatar_missing(),
			'A healthy avatar must not be flagged on the Pro path either.'
		);
	}

	/**
	 * PR #510 review finding: `update_single()` must not clobber a stored `avatar_id`.
	 *
	 * The write is safe only when `db_record_exists()` has mirrored the stored value
	 * first — and NOT every caller routes through it.
	 * `SBR_Feed_Saver_Manager::create_update_collection_review()` branches on its own
	 * `$is_new` flag and reaches `update_single()` with `storage_data` still at the
	 * constructor default, so an unconditional write blanked that review's avatar_id
	 * and un-localized its avatar. The column is now omitted when we hold no value.
	 *
	 * Captures the real `$wpdb->update()` payload rather than asserting on source text.
	 */
	public function test_update_single_omits_avatar_id_when_not_mirrored(): void
	{
		$captured = $this->captureUpdatePayload(null);

		$this->assertArrayNotHasKey(
			'avatar_id',
			$captured,
			'update_single() must NOT write avatar_id when storage_data holds none — '
			. 'a caller that skipped db_record_exists() would clobber the stored value.'
		);
		$this->assertArrayHasKey('json_data', $captured, 'the rest of the update must still be written');
	}

	/** ...and it must still persist a regenerated avatar_id when we do hold one. */
	public function test_update_single_writes_avatar_id_when_present(): void
	{
		$captured = $this->captureUpdatePayload('google-reheal-write-avatar-150');

		$this->assertSame(
			'google-reheal-write-avatar-150',
			$captured['avatar_id'] ?? null,
			'a mirrored/regenerated avatar_id must be persisted, else the re-heal never sticks.'
		);
	}

	/**
	 * Runs update_single() against a $wpdb double and returns the $data array it wrote.
	 *
	 * @param string|null $avatar_id storage_data value to seed, or null to leave unset.
	 * @return array<string,mixed>
	 */
	private function captureUpdatePayload($avatar_id): array
	{
		$previous = $GLOBALS['wpdb'] ?? null;

		$GLOBALS['wpdb'] = new class {
			public $prefix = 'wp_';
			/** wpdb exposes this after a write; update_single() reads it. */
			public $insert_id = 0;
			/** @var array<string,mixed> */
			public $captured = [];
			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->captured = $data;
				return 1;
			}
			public function prepare($query, ...$args)
			{
				return $query;
			}
			public function get_row($sql, $output = null)
			{
				return null;
			}
			public function get_var($sql)
			{
				return null;
			}
			public function query($sql)
			{
				return 0;
			}
		};

		$cache = new SinglePostCache([
			'review_id' => 'reheal-update',
			'rating'    => 5,
			'time'      => 1700000000,
			'text'      => 'x',
			'provider'  => ['name' => 'google'],
			'reviewer'  => ['name' => 'A', 'avatar' => 'https://lh3.googleusercontent.com/a/x=s120'],
		]);
		$cache->set_provider_id('place-1');
		if ($avatar_id !== null) {
			$cache->set_storage_data('avatar_id', $avatar_id);
		}
		$cache->update_single();

		$captured = $GLOBALS['wpdb']->captured;
		if ($previous !== null) {
			$GLOBALS['wpdb'] = $previous;
		}

		return is_array($captured) ? $captured : [];
	}

	public function test_missing_avatar_file_is_detected(): void
	{
		$cache = $this->cacheWithAvatarId('google-reheal-gone-avatar-150');

		$this->assertTrue(
			$cache->localized_avatar_missing(),
			'A row pointing at a file that is not on disk must be flagged for re-resize.'
		);
	}

	public function test_present_avatar_file_is_not_flagged(): void
	{
		file_put_contents($this->avatar_dir . 'google-reheal-here-avatar-150.png', 'png');
		$cache = $this->cacheWithAvatarId('google-reheal-here-avatar-150');

		$this->assertFalse(
			$cache->localized_avatar_missing(),
			'A healthy localized avatar must not trigger pointless re-downloads.'
		);
	}

	/**
	 * `error` means the first download already failed. Re-resizing on every fetch
	 * would hammer a permanently bad remote URL, so it stays excluded.
	 */
	public function test_error_marker_is_not_retried(): void
	{
		$cache = $this->cacheWithAvatarId('error');

		$this->assertFalse($cache->localized_avatar_missing());
	}

	/**
	 * @dataProvider emptyAvatarIdProvider
	 * @param mixed $avatar_id
	 */
	public function test_reviews_without_a_localized_avatar_are_not_flagged($avatar_id): void
	{
		$cache = $this->cacheWithAvatarId($avatar_id);

		$this->assertFalse($cache->localized_avatar_missing());
	}

	public static function emptyAvatarIdProvider(): array
	{
		return [
			'empty string' => [''],
			'null'         => [null],
			'array'        => [[]],
		];
	}

	/**
	 * BACKWARDS COMPATIBILITY: `update_single()` now writes `avatar_id`, which it did
	 * not before. That is only safe because `db_record_exists()` mirrors the stored
	 * value first — otherwise every update would clobber avatar_id with a default and
	 * un-localize every review on the site.
	 *
	 * This pins the pairing: the write list must contain avatar_id, and the read must
	 * populate it.
	 */
	public function test_avatar_id_is_both_mirrored_on_read_and_written_on_update(): void
	{
		$common = file_get_contents(dirname(__DIR__, 2) . '/class/Common/SinglePostCache.php');
		$pro    = file_get_contents(dirname(__DIR__, 2) . '/class/Pro/SinglePostCache.php');

		foreach (['Common' => $common, 'Pro' => $pro] as $label => $src) {
			$this->assertIsString($src, "could not read {$label} SinglePostCache");

			$this->assertMatchesRegularExpression(
				'/db_record_exists\(\).*?storage_data\[.avatar_id.\]\s*=\s*\$feed_id_match/s',
				$src,
				"{$label}::db_record_exists() must mirror the stored avatar_id, otherwise "
				. "update_single() writing avatar_id would wipe it."
			);

			$this->assertMatchesRegularExpression(
				'/update_single\(.*?array\(.avatar_id.,/s',
				$src,
				"{$label}::update_single() must persist avatar_id so a regenerated avatar sticks."
			);
		}
	}

	/**
	 * The re-heal must sit on the fetch path (`cache_single_posts_from_set`) and be
	 * gated on the local-images setting, so it never runs per page view and never
	 * downloads when the user asked us not to store images locally.
	 */
	public function test_reheal_is_gated_and_on_the_fetch_path(): void
	{
		foreach (['Pro', 'Common'] as $layer) {
			$src = file_get_contents(dirname(__DIR__, 2) . "/class/{$layer}/Feed.php");
			$this->assertIsString($src, "could not read {$layer}/Feed.php");

			$this->assertMatchesRegularExpression(
				'/should_store_local_images\(\)\s*&&\s*\$single_post_cache->localized_avatar_missing\(\)\s*\)\s*\{\s*\$single_post_cache->resize_avatar\(150\);/s',
				$src,
				"{$layer}/Feed.php must re-resize a missing localized avatar on the fetch "
				. "path, gated on should_store_local_images()."
			);
		}
	}
}
