<?php

namespace SmashBalloon\Reviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SmashBalloon\Reviews\Common\Admin\Blocks\SB_Reviews_Blocks;

/**
 * The `sbr_wizard` arg contract (SBR-163).
 *
 * The customizer sends this arg when embedding a feed into a page, and it ships
 * separately from the plugin, so the reader has to answer the same way for every
 * value any build of it sends. These pin that answer.
 */
class WizardShortcodeSettingsTest extends TestCase
{
	/**
	 * `1` is a feed id like any other, so a customizer that sends the literal keeps
	 * preselecting feed 1 — the behaviour it has always produced.
	 *
	 * @return void
	 */
	public function test_bc_the_literal_one_still_preselects_feed_one()
	{
		$this->assertSame('feed="1"', SB_Reviews_Blocks::wizard_shortcode_settings('1'));
		$this->assertSame('feed="1"', SB_Reviews_Blocks::wizard_shortcode_settings(1));
	}

	/**
	 * Any other id preselects that feed, which is what makes sending the real one
	 * an improvement rather than a change.
	 *
	 * @return void
	 */
	public function test_a_feed_id_preselects_that_feed()
	{
		$this->assertSame('feed="7"', SB_Reviews_Blocks::wizard_shortcode_settings('7'));
		$this->assertSame('feed="218"', SB_Reviews_Blocks::wizard_shortcode_settings(218));
	}

	/**
	 * A value naming no feed answers `feed="0"`, not ''. get_feed_html() reads an
	 * empty setting as "no feed named" and substitutes the site's first one, so ''
	 * would preview an unrelated feed instead of nothing.
	 *
	 * @return void
	 */
	public function test_a_value_naming_no_feed_stays_a_zero_attribute()
	{
		$this->assertSame('feed="0"', SB_Reviews_Blocks::wizard_shortcode_settings('abc'));
		$this->assertSame('feed="0"', SB_Reviews_Blocks::wizard_shortcode_settings(''));
	}

	/**
	 * A negative names nothing either. This is a deliberate departure: the inline
	 * version emitted `feed="-5"`. absint() is not the fix — it folds the sign and
	 * hands back real feed 5.
	 *
	 * @return void
	 */
	public function test_a_negative_value_names_no_feed()
	{
		$this->assertSame('feed="0"', SB_Reviews_Blocks::wizard_shortcode_settings('-5'));
		$this->assertSame('feed="0"', SB_Reviews_Blocks::wizard_shortcode_settings(-5));
	}

	/**
	 * Same answer for an array, which is what `?sbr_wizard[]=9` sends — reached
	 * without an "Array to string conversion", and matching the '' real WP's
	 * _sanitize_text_fields() returns for one (wp-includes/formatting.php:5634).
	 *
	 * @return void
	 */
	public function test_an_array_arg_answers_zero_without_a_conversion_warning()
	{
		$this->assertSame('feed="0"', @SB_Reviews_Blocks::wizard_shortcode_settings(array( 9 )));

		// The @ above would hide a warning, so assert there was none to hide: the
		// guard exists for this, and without it PHP raises one here.
		$raised = false;
		set_error_handler(
			function () use (&$raised) {
				$raised = true;

				return true;
			}
		);
		SB_Reviews_Blocks::wizard_shortcode_settings(array( 9 ));
		restore_error_handler();

		$this->assertFalse($raised, 'A non-scalar must be answered before it is cast to string.');
	}

	/**
	 * The value lands inside a double-quoted shortcode attribute, so nothing that
	 * is not a number may survive to close it.
	 *
	 * @return void
	 */
	public function test_the_value_cannot_break_out_of_the_attribute()
	{
		$this->assertSame('feed="7"', SB_Reviews_Blocks::wizard_shortcode_settings('7" evil="1'));
		$this->assertSame('feed="0"', SB_Reviews_Blocks::wizard_shortcode_settings('" onerror="x'));
	}
}
