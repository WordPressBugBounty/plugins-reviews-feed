<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Pro\Integrations\Forms\FormProviders\FormProvider;
use SmashBalloon\Reviews\Common\Parser;
use SmashBalloon\Reviews\Common\SBR_Schema_Service;
use SmashBalloon\Reviews\Pro\Integrations\Forms\FormRulesManager;

/**
 * SMASH-2475: a form without a rating field stored every submission as 5 stars
 * (FormProvider::form_review_scheme() defaulted to 5), so the card, the collection average and the
 * rich-snippet schema showed a rating nobody gave. It is now stored unrated (0).
 *
 * Star-rating moderation rules only judge submissions that have a rating: an unrated submission is
 * neither auto-approved by "N stars or more" nor auto-archived by "N stars or fewer" — it waits for
 * manual moderation. Approving it would publish e.g. a complaint whose author skipped the stars, and
 * a rule save re-applies the approve rule to every stored row.
 */
final class Smash2475FormReviewNoRatingTest extends TestCase
{
	private function scheme(array $json_data): array
	{
		return FormProvider::form_review_scheme('wpforms', 7, $json_data, ['entry_id' => 3, 'timestamp' => 1600000000]);
	}

	public function test_a_form_without_a_rating_field_is_stored_unrated(): void
	{
		$this->assertSame(0, $this->scheme(['text' => 'Great place'])['rating']);
	}

	public function test_a_rated_submission_keeps_its_stars(): void
	{
		$this->assertSame(4, $this->scheme(['text' => 'Good', 'rating' => 4])['rating']);
	}

	private function approve(array $rule, $rating, string $content = 'Lovely visit'): bool
	{
		return FormRulesManager::auto_approve_rules(['autoApprove' => $rule], ['rating' => $rating, 'content' => $content]);
	}

	private function archive(array $rule, $rating, string $content = 'Lovely visit'): bool
	{
		return FormRulesManager::auto_archive_rules(['autoArchive' => $rule], ['rating' => $rating, 'content' => $content]);
	}

	public function test_an_archive_by_low_rating_rule_never_archives_an_unrated_submission(): void
	{
		$this->assertFalse($this->archive(['rating' => 2], 0), 'unrated is not "2 stars or fewer"');
		$this->assertTrue($this->archive(['rating' => 2], 1), 'a real 1-star review is still archived');
		$this->assertFalse($this->archive(['rating' => 2], 4));
	}

	public function test_an_approve_by_rating_rule_does_not_approve_an_unrated_submission(): void
	{
		$this->assertFalse($this->approve(['rating' => 4], 0), 'unrated waits for manual approval');
		$this->assertTrue($this->approve(['rating' => 4], 5));
		$this->assertFalse($this->approve(['rating' => 4], 2), 'a real 2-star review is still held back');
	}

	public function test_a_characters_only_approve_rule_still_approves_an_unrated_submission(): void
	{
		$this->assertTrue($this->approve(['characters' => ['type' => 'longer', 'number' => 5]], 0, 'Long enough text'));
	}

	public function test_the_characters_rule_still_judges_an_unrated_submission(): void
	{
		$rule = ['rating' => 4, 'characters' => ['type' => 'longer', 'number' => 50]];
		$this->assertFalse($this->approve($rule, 0, 'Too short'), 'the length rule still applies');

		$archive = ['rating' => 2, 'characters' => ['type' => 'shorter', 'number' => 50]];
		$this->assertFalse($this->archive($archive, 0, 'Short'), 'both conditions must hold, and unrated fails the rating one');
	}

	public function test_sql_archive_rule_skips_unrated_rows(): void
	{
		$this->assertSame(' AND rating <= 2 AND rating > 0', FormRulesManager::sql_passes_rule(['rating' => 2], 'archive'), 'a bulk archive must not catch unrated rows');
	}

	public function test_sql_approve_rule_does_not_approve_unrated_rows(): void
	{
		$this->assertSame(' AND rating >= 4', FormRulesManager::sql_passes_rule(['rating' => 4]));
		$this->assertSame(' AND ((rating < 4 AND rating > 0))', FormRulesManager::sql_misses_rule(['rating' => 4]), 'a rule save must not un-approve an unrated row approved by hand');
	}

	public function test_sql_archive_rule_combines_with_characters(): void
	{
		$sql = FormRulesManager::sql_passes_rule(['rating' => 2, 'characters' => ['type' => 'shorter', 'number' => 30]], 'archive');
		$this->assertSame(' AND rating <= 2 AND rating > 0 AND LENGTH(content) < 30', $sql);
	}

	public function test_the_rule_rating_is_an_integer_in_sql(): void
	{
		$this->assertSame(' AND rating >= 4', FormRulesManager::sql_passes_rule(['rating' => '4 OR 1=1']));
	}

	public function test_sql_without_a_rating_rule_is_unchanged(): void
	{
		$sql = (string) FormRulesManager::sql_passes_rule(['characters' => ['type' => 'longer', 'number' => 10]]);
		$this->assertStringNotContainsString('rating', $sql);
	}

	/**
	 * The stored 0 only means "unrated" because of the 2.16.0 fixes this branch is based on
	 * (SMASH-2465/2467): no star on the card, left out of the schema.
	 */
	public function test_an_unrated_form_submission_is_not_rated_on_the_card_or_in_the_schema(): void
	{
		$stored = $this->scheme(['text' => 'Great place']);
		$post   = ['reviewer' => ['name' => 'Ana'], 'rating' => $stored['rating'], 'text' => $stored['content'], 'time' => 1600000000];

		$this->assertFalse((new Parser())->has_author_rating($post), 'the card reads "Not rated"');

		$rated = ['reviewer' => ['name' => 'Ben'], 'rating' => 5, 'text' => 'Lovely', 'time' => 1600000000];
		$nodes = (new SBR_Schema_Service())->map_feed_to_schema(
			[['name' => 'Acme', 'info' => ['rating' => 5, 'total_rating' => 2]]],
			[$post, $rated],
			[['provider' => 'collection']],
			true
		);
		$this->assertCount(1, $nodes[0]['review'], 'only the rated review is published');
		$this->assertSame('Ben', $nodes[0]['review'][0]['author']['name']);
	}
}
