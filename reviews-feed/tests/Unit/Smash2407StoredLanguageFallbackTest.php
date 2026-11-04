<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Feed as CommonFeed;
use SmashBalloon\Reviews\Common\PostAggregator;
use SmashBalloon\Reviews\Pro\Feed as ProFeed;

/**
 * SMASH-2407: a Google feed switched to a language it has no rows in went blank until the next allowed fetch.
 * posts_from_db() now re-reads such a feed in each Google source's newest stored language, once, and only when
 * the whole feed came back empty. Drives the real posts_from_db() against a $wpdb double that answers by SQL.
 */
class Smash2407StoredLanguageFallbackTest extends TestCase
{
	/** @var mixed */
	private $original_wpdb;

	/** @var list<string> */
	private $queries = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/**
	 * A $wpdb that holds rows per (provider_id, lang) and logs every SELECT it answers.
	 *
	 * @param array<string, array<string, list<array<string, mixed>>>> $rows provider_id => lang => rows
	 */
	private function install_wpdb(array $rows): void
	{
		$test = $this;
		$GLOBALS['wpdb'] = new class ($rows, $test) {
			public $prefix = 'wp_';
			/** @var array<string, array<string, list<array<string, mixed>>>> */
			private $rows;
			/** @var Smash2407StoredLanguageFallbackTest */
			private $test;

			public function __construct(array $rows, $test)
			{
				$this->rows = $rows;
				$this->test = $test;
			}

			public function prepare($query, ...$args)
			{
				foreach ($args as $arg) {
					$query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
				}

				return $query;
			}

			public function get_results($sql, $output = null)
			{
				$this->test->log_query($sql);
				$out = [];
				foreach ($this->rows as $provider_id => $by_lang) {
					foreach ($by_lang as $lang => $list) {
						$clause = 'provider_id = "' . $provider_id . '"';
						$with_lang = $clause . ' AND lang = "' . $lang . '"';
						// A source clause without a lang filter (non-Google) matches every language it holds.
						$lang_filtered = strpos($sql, $clause . ' AND lang') !== false;
						if (($lang_filtered && strpos($sql, $with_lang) !== false) || (! $lang_filtered && strpos($sql, $clause) !== false)) {
							foreach ($list as $row) {
								// The media pass selects only rows whose images are not done yet.
								if (strpos($sql, 'images_done = 0') === false || (int) $row['images_done'] === 0) {
									$out[] = $row;
								}
							}
						}
					}
				}

				return $out;
			}

			public function get_var($sql)
			{
				$this->test->log_query($sql);
				// The per-source probe: any row for (provider_id, lang)?
				// Like wpdb::get_var(): a '' value comes back as null.
				if (preg_match("/SELECT 1 .* provider_id = '([^']*)' AND lang = '([^']*)'/s", $sql, $m) === 1) {
					return empty($this->rows[ $m[1] ][ $m[2] ]) ? null : '1';
				}
				return null;
			}

			public function get_row($sql, $output = null)
			{
				$this->test->log_query($sql);
				foreach ($this->rows as $provider_id => $by_lang) {
					if (strpos($sql, "provider_id = '" . $provider_id . "'") !== false) {
						// The double's rows are ordered newest language first. Like wpdb, a row keeps '' as ''.
						return ['lang' => (string) array_key_first($by_lang)];
					}
				}

				return null;
			}

			public function query($sql)
			{
				$this->test->log_query($sql);

				return 0;
			}

			/** @var list<array<string, mixed>> where clauses of every UPDATE */
			public $updates = [];

			public $insert_id = 0;

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->updates[] = $where;

				return 1;
			}
		};
	}

	public function log_query(string $sql): void
	{
		$this->queries[] = $sql;
	}

	/** @return array<string, mixed> */
	private function row(string $provider_id, string $lang, string $id, int $images_done = 1): array
	{
		return [
			'post_id'     => $id,
			'provider'    => 'google',
			'provider_id' => $provider_id,
			'lang'        => $lang,
			'images_done' => $images_done,
			'json_data'   => json_encode([
				'review_id' => $id,
				'text'      => 'Text ' . $id,
				'rating'    => 5,
				'time'      => 1725000000,
				'reviewer'  => ['name' => 'Ada ' . $id, 'avatar' => ''],
				'provider'  => ['name' => 'google'],
				'source'    => ['id' => $provider_id, 'url' => ''],
			]),
		];
	}

	/**
	 * A Feed with the given hydrated sources and language; filtering is identity so the read itself is what's tested.
	 *
	 * @param list<array<string, mixed>> $sources
	 */
	private function feed(array $sources, string $language): CommonFeed
	{
		return new class ($sources, $language) extends CommonFeed {
			public function __construct(array $sources, string $language)
			{
				$this->settings = [
					'sources'         => $sources,
					'apiCallLanguage' => $language,
					'numPostDesktop'  => 10,
				];
			}

			public function filter_posts($posts, $settings, $moderatePosts = false)
			{
				return $posts;
			}
		};
	}

	/** @return array<string, mixed> */
	private function google(string $id, string $lang): array
	{
		return ['provider' => 'google', 'account_id' => $id, 'lang' => $lang];
	}

	/** @param list<array<string, mixed>> $posts */
	private function ids(array $posts): array
	{
		return array_map(static fn ($post) => $post['review_id'] ?? null, $posts);
	}

	public function test_a_feed_switched_to_a_language_with_no_rows_shows_its_newest_stored_language(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1'), $this->row('G1', 'en', 'r2')]]]);

		$posts = $this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db();

		$this->assertSame(['r1', 'r2'], $this->ids($posts));
	}

	public function test_a_feed_with_rows_in_its_language_reads_exactly_as_today(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1')], 'ro' => [$this->row('G1', 'ro', 'ro1')]]]);

		$posts = $this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db();

		$this->assertSame(['ro1'], $this->ids($posts));
		// One raw probe per Google source; the newest-language lookup never runs on a healthy feed.
		$this->assertSame([], array_filter($this->queries, static fn ($sql) => stripos($sql, 'SELECT lang') !== false));
	}

	public function test_a_feed_moved_off_the_default_language_shows_the_default_rows(): void
	{
		// The common case, found live on DDEV: rows fetched on the default language carry lang ''.
		$this->install_wpdb(['G1' => ['' => [$this->row('G1', '', 'd1')]]]);

		$this->assertSame(['d1'], $this->ids($this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db()));
	}

	public function test_a_feed_moved_back_to_the_default_language_shows_its_stored_rows(): void
	{
		// The reverse case: the feed is now on the default language ('') and only has 'ro' rows.
		$this->install_wpdb(['G1' => ['ro' => [$this->row('G1', 'ro', 'ro1')]]]);

		$this->assertSame(['ro1'], $this->ids($this->feed([$this->google('G1', '')], '')->posts_from_db()));
	}

	public function test_the_swap_is_per_source_in_a_mixed_feed(): void
	{
		// Seer, 2026-10-19: G1 has rows in the feed's language, so the feed is not empty — G2 must still be swapped.
		$this->install_wpdb([
			'G1' => ['ro' => [$this->row('G1', 'ro', 'g1ro')]],
			'G2' => ['en' => [$this->row('G2', 'en', 'g2en')]],
		]);

		$posts = $this->feed([$this->google('G1', 'ro'), $this->google('G2', 'ro')], 'ro')->posts_from_db();

		$this->assertSame(['g1ro', 'g2en'], $this->ids($posts));
	}

	public function test_every_read_agrees_on_the_sources(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1', 0)]]]);
		$feed = $this->pro_feed([$this->google('G1', 'ro')], 'ro');

		$this->assertSame(['r1'], $this->ids($feed->posts_from_db()));
		$this->assertSame(['r1'], $this->ids($feed->get_posts_for_moderation()));
		$this->assertSame(['r1'], $this->ids($feed->get_posts_for_media_finding_and_resizing()));
	}

	public function test_last_requested_is_stamped_on_the_rows_that_are_shown(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1')]]]);

		$this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db();

		$stamps = array_values(array_filter($this->queries, static fn ($sql) => stripos($sql, 'SET last_requested') !== false));
		$this->assertCount(1, $stamps);
		$this->assertStringContainsString('lang = "en"', $stamps[0]);
	}

	public function test_only_one_language_is_read_so_translations_never_double(): void
	{
		// Newest first: 'de' is the newest stored language, 'en' must not be read too.
		$this->install_wpdb(['G1' => ['de' => [$this->row('G1', 'de', 'de1')], 'en' => [$this->row('G1', 'en', 'en1')]]]);

		$posts = $this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db();

		$this->assertSame(['de1'], $this->ids($posts));
	}

	public function test_a_source_without_a_language_is_never_re_read(): void
	{
		// Non-Google sources carry no `lang` (Feed::hydrate_sources); an empty one is just empty.
		$this->install_wpdb([]);

		$posts = $this->feed([['provider' => 'yelp', 'account_id' => 'Y1']], 'ro')->posts_from_db();

		$this->assertSame([], $posts);
		$this->assertSame([], array_filter($this->queries, static fn ($sql) => stripos($sql, 'SELECT lang') !== false));
	}

	public function test_a_feed_with_no_stored_rows_at_all_stays_empty(): void
	{
		$this->install_wpdb([]);

		$this->assertSame([], $this->feed([$this->google('G1', 'ro')], 'ro')->posts_from_db());
	}

	public function test_the_media_pass_finds_the_stored_language_rows_it_rendered(): void
	{
		// Gate finding 2026-10-19: rendered from 'en' but resized by 'ro', the media pass found nothing, images_done
		// never flipped, and every render re-armed a media check that could never finish.
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1', 0)]]]);
		$feed = new class ([$this->google('G1', 'ro')], 'ro') extends ProFeed {
			public function __construct(array $sources, string $language)
			{
				$this->settings = ['sources' => $sources, 'apiCallLanguage' => $language, 'numPostDesktop' => 10];
			}

			public function filter_posts($posts, $settings, $moderatePosts = false)
			{
				return $posts;
			}
		};

		$this->assertSame(['r1'], $this->ids($feed->get_posts_for_media_finding_and_resizing()));
	}

	public function test_the_media_pass_of_a_healthy_feed_reads_its_own_language(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'e1', 0)], 'ro' => [$this->row('G1', 'ro', 'o1', 0)]]]);
		$feed = new class ([$this->google('G1', 'ro')], 'ro') extends ProFeed {
			public function __construct(array $sources, string $language)
			{
				$this->settings = ['sources' => $sources, 'apiCallLanguage' => $language, 'numPostDesktop' => 10];
			}

			public function filter_posts($posts, $settings, $moderatePosts = false)
			{
				return $posts;
			}
		};

		$this->assertSame(['o1'], $this->ids($feed->get_posts_for_media_finding_and_resizing()));
	}

	/**
	 * A Pro feed exposing the media pass's language choice.
	 *
	 * @param list<array<string, mixed>> $sources
	 */
	private function pro_feed(array $sources, string $language): ProFeed
	{
		return new class ($sources, $language) extends ProFeed {
			public function __construct(array $sources, string $language)
			{
				$this->settings = ['sources' => $sources, 'apiCallLanguage' => $language, 'numPostDesktop' => 10];
			}

			public function filter_posts($posts, $settings, $moderatePosts = false)
			{
				return $posts;
			}

			public function lang_for(string $source_id): string
			{
				$read_langs = array_column($this->db_read_sources(new PostAggregator()), 'lang', 'account_id');

				return $this->resize_lang($read_langs, $source_id);
			}
		};
	}

	public function test_the_media_update_targets_the_row_language_that_was_read(): void
	{
		// The resized row is updated by (post_id, lang, provider_id): it must be 'en', the row that exists.
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1', 0)]]]);

		$this->assertSame('en', $this->pro_feed([$this->google('G1', 'ro')], 'ro')->lang_for('G1'));
	}

	public function test_the_media_update_of_a_source_swapped_to_the_default_language_targets_lang_empty(): void
	{
		// Gate, 2026-10-19: swapped to '' (the default language), the update must say lang '' — not fall back to 'ro'.
		$this->install_wpdb(['G1' => ['' => [$this->row('G1', '', 'd1', 0)]]]);

		$this->assertSame('', $this->pro_feed([$this->google('G1', 'ro')], 'ro')->lang_for('G1'));
	}

	public function test_the_media_update_of_a_healthy_feed_keeps_the_feed_language(): void
	{
		$this->install_wpdb(['G1' => ['ro' => [$this->row('G1', 'ro', 'o1', 0)]]]);

		$this->assertSame('ro', $this->pro_feed([$this->google('G1', 'ro')], 'ro')->lang_for('G1'));
	}

	public function test_find_and_resize_media_updates_the_row_in_the_language_it_read(): void
	{
		global $wp_options_mock;
		$saved = $wp_options_mock;
		$wp_options_mock['sbr_settings'] = ['optimize_images' => false];

		try {
			$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1', 0)]]]);
			$post = [
				'review_id' => 'r1',
				'text'      => 'Text r1',
				'rating'    => 5,
				'time'      => 1725000000,
				'reviewer'  => ['name' => 'Ada', 'avatar' => ''],
				'provider'  => ['name' => 'google'],
				'source'    => ['id' => 'G1', 'url' => ''],
				// Media already on the row: the finder returns early, so nothing reaches the network.
				'media'     => [['type' => 'image', 'url' => 'https://example.test/a.jpg']],
			];

			$this->pro_feed([$this->google('G1', 'ro')], 'ro')->find_and_resize_media([$post]);

			$this->assertSame([['post_id' => 'r1', 'lang' => 'en']], array_map(
				static fn ($where) => ['post_id' => $where['post_id'] ?? null, 'lang' => $where['lang'] ?? null],
				$GLOBALS['wpdb']->updates
			), 'the update must land on the en row the pass read, or images_done never flips');
		} finally {
			$wp_options_mock = $saved;
		}
	}

	public function test_moderation_lists_the_reviews_the_feed_shows(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1')]]]);

		$this->assertSame(['r1'], $this->ids($this->feed([$this->google('G1', 'ro')], 'ro')->get_posts_for_moderation()));
	}

	public function test_newest_stored_lang_reads_one_row_newest_first(): void
	{
		$this->install_wpdb(['G1' => ['en' => [$this->row('G1', 'en', 'r1')]]]);

		$this->assertSame('en', (new PostAggregator())->newest_stored_lang('G1'));
		$this->assertNull((new PostAggregator())->newest_stored_lang('nope'));

		// '' is the default language a Google feed stores with — a real language to fall back to (live, 2026-10-19).
		$this->install_wpdb(['G2' => ['' => [$this->row('G2', '', 'x1')]]]);
		$this->assertSame('', (new PostAggregator())->newest_stored_lang('G2'));
		$last = end($this->queries);
		$this->assertStringContainsString('ORDER BY time_stamp DESC LIMIT 1', (string) $last);
	}
}
