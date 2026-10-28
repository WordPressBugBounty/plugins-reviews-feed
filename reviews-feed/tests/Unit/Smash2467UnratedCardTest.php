<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\FeedDisplay;
use SmashBalloon\Reviews\Common\Parser;

/**
 * SMASH-2467 — a review left without stars showed 1 star on the feed card.
 *
 * Parser::get_rating() floors an empty rating to 1 (class/Common/Parser.php:86), so
 * star_rating_display()'s own "Not rated" branch could never run. The feed editor's
 * preview already draws such a review as five grey stars (PostRating.js), so the
 * front end now matches it.
 */
class Smash2467UnratedCardTest extends TestCase
{
	private function display(): FeedDisplay
	{
		$display = (new \ReflectionClass(FeedDisplay::class))->newInstanceWithoutConstructor();
		$prop    = new \ReflectionProperty(FeedDisplay::class, 'parser');
		$prop->setAccessible(true);
		$prop->setValue($display, new Parser());

		return $display;
	}

	/**
	 * @param  mixed $rating
	 * @return array{label:string, lit:int, dimmed:int}
	 */
	private function render($rating): array
	{
		$post = ['reviewer' => ['name' => 'Nora'], 'text' => 'x'];
		if ($rating !== 'absent') {
			$post['rating'] = $rating;
		}
		$html = $this->display()->star_rating_display($post, []);

		preg_match('/aria-label="([^"]*)"/', $html, $m);
		$dimmed = preg_match_all('/sb-item-rating-icon\s+sb-item-rating-icon-dimmed/', $html);
		$all    = preg_match_all('/class="sb-item-rating-icon\b/', $html);

		return ['label' => $m[1] ?? '', 'lit' => $all - $dimmed, 'dimmed' => $dimmed];
	}

	/**
	 * @dataProvider unrated_values
	 * @param mixed $rating
	 */
	public function test_an_unrated_review_shows_no_lit_star($rating): void
	{
		$card = $this->render($rating);

		$this->assertSame('Not rated', $card['label']);
		$this->assertSame(0, $card['lit'], 'no star the author did not give');
		$this->assertSame(5, $card['dimmed']);
	}

	/** @return array<string,array{0:mixed}> */
	public static function unrated_values(): array
	{
		return [
			'Woo/EDD store 0' => [0],
			'float zero'      => [0.0],
			'empty string'    => [''],
			'zero text'       => ['0'],
			'null'            => [null],
			'key absent'      => ['absent'],
		];
	}

	public function test_a_real_one_star_review_still_shows_one_star(): void
	{
		$card = $this->render(1);

		$this->assertSame('1 out of 5 stars', $card['label']);
		$this->assertSame(1, $card['lit']);
		$this->assertSame(4, $card['dimmed']);
	}

	public function test_five_stars_are_unchanged(): void
	{
		$this->assertSame(['label' => '5 out of 5 stars', 'lit' => 5, 'dimmed' => 0], $this->render(5));
	}

	public function test_facebook_recommendations_are_unchanged(): void
	{
		$this->assertSame(5, $this->render('positive')['lit']);
		$this->assertSame(1, $this->render('negative')['lit']);
	}

	public function test_get_rating_keeps_its_contract(): void
	{
		// Backwards compatibility: callers outside the card still get the old value.
		$parser = new Parser();
		$this->assertSame(1, $parser->get_rating(['rating' => 0]));
		$this->assertSame(1, $parser->get_rating([]));
		$this->assertSame(5, $parser->get_rating(['rating' => 'positive']));
	}

	/**
	 * @dataProvider has_author_rating_cases
	 * @param mixed $rating
	 */
	public function test_has_author_rating($rating, bool $expected): void
	{
		$post = $rating === 'absent' ? [] : ['rating' => $rating];
		$this->assertSame($expected, (new Parser())->has_author_rating($post));
	}

	/** @return array<string,array{0:mixed,1:bool}> */
	public static function has_author_rating_cases(): array
	{
		return [
			'zero'            => [0, false],
			'empty string'    => ['', false],
			'null'            => [null, false],
			'absent'          => ['absent', false],
			'one'             => [1, true],
			'four point five' => [4.5, true],
			'numeric text'    => ['4', true],
			'trailing space'  => ['5 ', true],
			'blank text'      => ['  ', false],
			'positive'        => ['positive', true],
			'negative'        => ['negative', true],
		];
	}
}
