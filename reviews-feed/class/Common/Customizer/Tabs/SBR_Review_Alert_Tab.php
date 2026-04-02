<?php

/**
 * Review Alert Customizer Tab
 *
 * Defines the customizer sidebar controls for review alert editing.
 * This tab only displays when editing a review alert, not when editing feeds.
 *
 * @since 2.5.0
 * @package SmashBalloon\Reviews
 */

namespace SmashBalloon\Reviews\Common\Customizer\Tabs;

use Smashballoon\Customizer\V2\SB_Sidebar_Tab;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit;
}

class SBR_Review_Alert_Tab extends SB_Sidebar_Tab
{
	/**
	 * Determine if this tab should be displayed
	 *
	 * Only show in the review alert editor context, not in the feed editor.
	 *
	 * @since 2.5.0
	 *
	 * @return bool
	 */
	public function should_display()
	{
		// Only display when editing a popup (popup_id parameter present)
		// Never display on feed editor pages (feed_id parameter present)
		if (isset($_GET['feed_id'])) {
			return false;
		}

		return isset($_GET['popup_id']);
	}

	/**
	 * Get the Sidebar Tab info
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id' => 'sb-review-alert-tab',
			'name' => __('Review Alert', 'reviews-feed')
		];
	}

	/**
	 * Get the Sidebar Tab Sections
	 *
	 * Structured to match Figma design:
	 * - Theme (click to see theme options)
	 * - Accent Color (click to see color picker)
	 * - Positioning (click to see position options)
	 * - Review Alert (click to see popup settings with nested sections)
	 * - Review Feed (click to see expanded popup settings)
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	protected function tab_sections()
	{
		return [
			'theme_section' => [
				'heading'  => __('Theme', 'reviews-feed'),
				'icon'     => 'theme',
				'controls' => self::get_theme_controls(),
			],
			'accent_color_section' => [
				'heading'  => __('Accent Color', 'reviews-feed'),
				'icon'     => 'color',
				'controls' => self::get_accent_color_controls(),
			],
			'positioning_section' => [
				'heading'  => __('Positioning', 'reviews-feed'),
				'icon'     => 'position',
				'controls' => self::get_positioning_controls(),
			],
			'review_alert_section' => [
				'heading'  => __('Review Alert', 'reviews-feed'),
				'icon'     => 'notification',
				'controls' => self::get_review_alert_controls(),
			],
			'review_feed_section' => [
				'heading'  => __('Review Feed', 'reviews-feed'),
				'icon'     => 'reviews',
				'controls' => self::get_review_feed_controls(),
			],
		];
	}

	/**
	 * Get Theme Controls
	 *
	 * Toggle set options matching Figma design:
	 * Light, Dark, Minimal, Minimal Dark
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_theme_controls()
	{
		return [
			[
				'type'    => 'toggleset',
				'id'      => 'theme',
				'options' => [
					[
						'value' => 'light',
						'label' => __('Light', 'reviews-feed')
					],
					[
						'value'       => 'dark',
						'label'       => __('Dark', 'reviews-feed'),
						'upsellModal' => 'reviewAlertDarkTheme'
					],
					[
						'value'       => 'minimal',
						'label'       => __('Minimal', 'reviews-feed'),
						'upsellModal' => 'reviewAlertMinimalTheme'
					],
					[
						'value'       => 'minimal-dark',
						'label'       => __('Minimal Dark', 'reviews-feed'),
						'upsellModal' => 'reviewAlertMinimalDarkTheme'
					]
				]
			],
		];
	}

	/**
	 * Get Accent Color Controls
	 *
	 * Color picker matching Figma design
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_accent_color_controls()
	{
		return [
			[
				'type'        => 'colorpicker',
				'id'          => 'accent_color',
				'heading'     => __('Choose Color', 'reviews-feed'),
				'layout'      => 'full',
				'default'     => '#1E88E5',
				'upsellModal' => 'reviewAlertCustomColor'
			],
		];
	}

	/**
	 * Get Positioning Controls
	 *
	 * Left/Right toggle options matching Figma design
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_positioning_controls()
	{
		return [
			[
				'type'    => 'toggleset',
				'id'      => 'position',
				'options' => [
					[
						'value' => 'bottom-left',
						'icon'  => 'leftalign',
						'label' => __('Left', 'reviews-feed')
					],
					[
						'value' => 'bottom-right',
						'icon'  => 'rightalign',
						'label' => __('Right', 'reviews-feed')
					]
				]
			],
		];
	}

	/**
	 * Get Review Alert Controls
	 *
	 * Controls for Review Alert settings (closed state)
	 * matching Figma design
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_review_alert_controls()
	{
		return [
			// Popup Type
			[
				'type'    => 'toggleset',
				'id'      => 'popup_type',
				'heading' => __('Popup Type', 'reviews-feed'),
				'options' => [
					[
						'value' => 'aggregate',
						'label' => __('Aggregate Review', 'reviews-feed'),
					],
					[
						'value' => 'recent',
						'label' => __('Recent Reviews', 'reviews-feed'),
					]
				]
			],
			// Timing
			[
				'type'        => 'slider',
				'id'          => 'show_after',
				'heading'     => __('Timing', 'reviews-feed'),
				'label'       => __('Show after', 'reviews-feed'),
				'unit'        => 's',
				'inputNumber' => true,
			],
			// Separator
			[
				'type'   => 'separator',
				'top'    => 15,
				'bottom' => 15,
			],
			// Show/Hide Elements Group
			[
				'type'     => 'group',
				'id'       => 'show_hide_elements',
				'heading'  => __('Show/Hide Elements', 'reviews-feed'),
				'controls' => [
					[
						'type'    => 'switcher',
						'id'      => 'show_rating_number',
						'layout'  => 'half',
						'label'   => __('Rating Number', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_rating_stars',
						'layout'  => 'half',
						'label'   => __('Rating Stars', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_review_count',
						'layout'  => 'half',
						'label'   => __('Review Count', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'        => 'switcher',
						'id'          => 'show_powered_by',
						'layout'      => 'half',
						'label'       => __('Powered By', 'reviews-feed'),
						'upsellModal' => 'reviewAlertBranding',
						'options'     => [
							'enabled'  => true,
							'disabled' => false
						]
					],
				]
			],
		];
	}

	/**
	 * Get Review Feed Controls
	 *
	 * Controls for Review Feed (expanded popup) settings
	 * matching Figma design
	 *
	 * @since 2.5.0
	 *
	 * @return array
	 */
	public static function get_review_feed_controls()
	{
		return [
			// Header Group
			[
				'type'     => 'group',
				'id'       => 'header_group',
				'heading'  => __('Header', 'reviews-feed'),
				'controls' => [
					[
						'type'    => 'switcher',
						'id'      => 'show_heading',
						'layout'  => 'half',
						'label'   => __('Heading', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_button',
						'layout'  => 'half',
						'label'   => __('Button', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
				]
			],
			// Review Cards Group
			[
				'type'     => 'group',
				'id'       => 'review_cards_group',
				'heading'  => __('Review Cards', 'reviews-feed'),
				'controls' => [
					[
						'type'    => 'switcher',
						'id'      => 'show_stars',
						'layout'  => 'half',
						'label'   => __('Stars', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_title',
						'layout'  => 'half',
						'label'   => __('Title', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_text',
						'layout'  => 'half',
						'label'   => __('Content', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_author',
						'layout'  => 'half',
						'label'   => __('Author', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
					[
						'type'    => 'switcher',
						'id'      => 'show_date',
						'layout'  => 'half',
						'label'   => __('Date', 'reviews-feed'),
						'options' => [
							'enabled'  => true,
							'disabled' => false
						]
					],
				]
			],
			// Branding Group
			[
				'type'     => 'group',
				'id'       => 'branding_group',
				'heading'  => __('Branding', 'reviews-feed'),
				'controls' => [
					[
						'type'        => 'switcher',
						'id'          => 'show_powered_by',
						'layout'      => 'half',
						'label'       => __('Powered By Badge', 'reviews-feed'),
						'upsellModal' => 'reviewAlertBranding',
						'options'     => [
							'enabled'  => true,
							'disabled' => false
						]
					],
				]
			],
		];
	}

}
