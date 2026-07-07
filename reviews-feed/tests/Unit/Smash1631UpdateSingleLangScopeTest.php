<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\SinglePostCache as CommonSinglePostCache;
use SmashBalloon\Reviews\Pro\SinglePostCache as ProSinglePostCache;
use SmashBalloon\Reviews\Pro\MediaFinder;

/**
 * SMASH-1631 regression: SinglePostCache::update_single() must scope its UPDATE by
 * language.
 *
 * A Google review_id carries no language, so on a WPML multi-language site the same
 * review is cached once per language (unique key: post_id, provider_id, lang). The
 * update path matched on post_id ALONE, so one language's update overwrote every
 * sibling-language row for that review — collapsing them all onto the last-written
 * text (observed: en/fr/ro rows all became the Spanish original after a bulk re-run).
 * db_record() — the existence check that gates update_single() — already keys on
 * (post_id, lang, provider_id); these tests pin that update_single() now mirrors the
 * lang dimension so an update only ever touches the row for its own language.
 *
 * Covers BOTH the Pro override (the runtime bulk-cron path) and the Common base.
 */
class Smash1631UpdateSingleLangScopeTest extends TestCase
{
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void
	{
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		// $wpdb double that records the arguments handed to update() so we can assert
		// the WHERE clause. Returns 1 (rows affected) so update_single() sees success.
		$GLOBALS['wpdb'] = new class {
			/** @var string */
			public $prefix = 'wp_';
			/** @var int */
			public $insert_id = 0;
			/** @var array<string,mixed>|null */
			public $last_update = null;

			public function update($table, $data, $where, $format = null, $where_format = null)
			{
				$this->last_update = compact('table', 'data', 'where', 'format', 'where_format');
				return 1;
			}

			public function prepare($query, ...$args)
			{
				return $query;
			}

			public function get_results($query, $output = null)
			{
				return [];
			}
		};
	}

	protected function tearDown(): void
	{
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	/** A minimal Google review payload (Google is the only lang-aware provider). */
	private function google_review(string $review_id = 'REVIEW_A'): array
	{
		return [
			'review_id' => $review_id,
			'text'      => 'Thank you Roya and Genta.',
			'rating'    => 5,
			'time'      => 1783106917,
			'reviewer'  => ['name' => 'Judi Rawlings'],
			'provider'  => ['name' => 'google'],
		];
	}

	private function make_pro(array $review): ProSinglePostCache
	{
		return new ProSinglePostCache($review, new MediaFinder($review));
	}

	public function test_pro_update_single_scopes_where_by_language(): void
	{
		$cache = $this->make_pro($this->google_review());
		$cache->set_provider_id('place_123');
		$cache->set_lang('es-419');
		$cache->update_single();

		$where = $GLOBALS['wpdb']->last_update['where'];
		$this->assertArrayHasKey('lang', $where, 'update_single() must include lang in its WHERE');
		$this->assertSame('es-419', $where['lang']);
		$this->assertSame('REVIEW_A', $where['post_id']);

		// where_format must stay aligned with the where columns or $wpdb->update breaks.
		$this->assertCount(
			count($where),
			$GLOBALS['wpdb']->last_update['where_format'],
			'where_format count must match the number of WHERE columns'
		);
	}

	public function test_common_update_single_scopes_where_by_language(): void
	{
		$cache = new CommonSinglePostCache($this->google_review());
		$cache->set_provider_id('place_123');
		$cache->set_lang('es-419');
		$cache->update_single();

		$where = $GLOBALS['wpdb']->last_update['where'];
		$this->assertSame('es-419', $where['lang']);
		$this->assertSame('REVIEW_A', $where['post_id']);
	}

	/**
	 * The core regression: two languages of the SAME review must produce DIFFERENT
	 * WHERE clauses, so updating one language can never match (and overwrite) the other.
	 */
	public function test_two_languages_of_same_review_do_not_share_a_where(): void
	{
		$en = $this->make_pro($this->google_review('REVIEW_A'));
		$en->set_provider_id('place_123');
		$en->set_lang('en');
		$en->update_single();
		$en_where = $GLOBALS['wpdb']->last_update['where'];

		$es = $this->make_pro($this->google_review('REVIEW_A'));
		$es->set_provider_id('place_123');
		$es->set_lang('es-419');
		$es->update_single();
		$es_where = $GLOBALS['wpdb']->last_update['where'];

		$this->assertSame($en_where['post_id'], $es_where['post_id'], 'same review id');
		$this->assertNotSame(
			$en_where['lang'],
			$es_where['lang'],
			'the two languages must scope to different rows'
		);
		$this->assertSame('en', $en_where['lang']);
		$this->assertSame('es-419', $es_where['lang']);
	}

	/**
	 * Backwards-compat: a non-lang provider never calls set_lang(), so lang stays the
	 * '' default. The WHERE still carries post_id (unchanged) plus lang='' — which
	 * matches the lang='' rows those providers have always stored, so their update
	 * behaviour is preserved.
	 */
	public function test_non_lang_provider_keeps_empty_lang_where(): void
	{
		$review = [
			'review_id' => 'YELP_1',
			'text'      => 'Great place.',
			'rating'    => 4,
			'time'      => 1783106917,
			'reviewer'  => ['name' => 'Sam'],
			'provider'  => ['name' => 'yelp'],
		];
		$cache = $this->make_pro($review);
		$cache->set_provider_id('yelp_biz_1');
		// No set_lang() — mirrors the non-lang provider path.
		$cache->update_single();

		$where = $GLOBALS['wpdb']->last_update['where'];
		$this->assertSame('YELP_1', $where['post_id']);
		$this->assertSame('', $where['lang'], 'non-lang providers scope on the empty-string default');
	}

	/**
	 * The sibling update() method (used by the media-resize render path,
	 * Pro/Feed::find_and_resize_media) had the identical post_id-only WHERE and leaked
	 * the same cross-language overwrite. It must also scope by language.
	 */
	public function test_pro_update_scopes_where_by_language(): void
	{
		$cache = $this->make_pro($this->google_review());
		$cache->set_provider_id('place_123');
		$cache->set_lang('es-419');
		$cache->update([
			['images_done', 1, '%d'],
			['post_content', 'Gracias Roya', '%s'],
		]);

		$where = $GLOBALS['wpdb']->last_update['where'];
		$this->assertArrayHasKey('lang', $where, 'update() must include lang in its WHERE');
		$this->assertSame('es-419', $where['lang']);
		$this->assertSame('REVIEW_A', $where['post_id']);
		$this->assertCount(count($where), $GLOBALS['wpdb']->last_update['where_format']);
	}

	public function test_common_update_scopes_where_by_language(): void
	{
		$cache = new CommonSinglePostCache($this->google_review());
		$cache->set_provider_id('place_123');
		$cache->set_lang('en');
		$cache->update([['images_done', 1, '%d']]);

		$where = $GLOBALS['wpdb']->last_update['where'];
		$this->assertSame('en', $where['lang']);
		$this->assertSame('REVIEW_A', $where['post_id']);
	}

	/**
	 * Core regression for the render path: resizing the media of one language's row
	 * must not overwrite a sibling language's row. Two languages of the same review
	 * produce different update() WHERE clauses.
	 */
	public function test_update_two_languages_do_not_share_a_where(): void
	{
		$en = $this->make_pro($this->google_review('REVIEW_A'));
		$en->set_provider_id('place_123');
		$en->set_lang('en');
		$en->update([['images_done', 1, '%d']]);
		$en_where = $GLOBALS['wpdb']->last_update['where'];

		$es = $this->make_pro($this->google_review('REVIEW_A'));
		$es->set_provider_id('place_123');
		$es->set_lang('es-419');
		$es->update([['images_done', 1, '%d']]);
		$es_where = $GLOBALS['wpdb']->last_update['where'];

		$this->assertSame($en_where['post_id'], $es_where['post_id']);
		$this->assertNotSame($en_where['lang'], $es_where['lang']);
	}
}
