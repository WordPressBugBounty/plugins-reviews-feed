<?php

/**
 * Reviews Feed — Trust Badge view-data defaults (SBR-163)
 *
 * Every key the badge templates read, defaulted to something inert. Included
 * first by each shape template, so the shapes and the two partials they include
 * share one filled array.
 *
 * Why it exists: `SBR_Badge_Renderer::view_data()` always builds the whole set,
 * but templates are an override surface — a theme copy, a block render callback,
 * a future caller assembling the array by hand — and on PHP 8 a missing key is a
 * warning printed into the page, plus `esc_url(null)`-style coercions
 * downstream. `tests/Unit/Badges/BadgePartialViewDataTest.php` drives each
 * template with an empty array and fails on any warning.
 *
 * The defaults are deliberately EMPTY rather than plausible: a shape with no
 * data should render an empty shell, never invent a rating (Rule 9).
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 * @var array $sbr_badge Prepared view data, possibly partial.
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// A non-array payload is the same problem one step earlier, so it lands here too
// rather than reaching array access.
$sbr_badge = isset($sbr_badge) && is_array($sbr_badge) ? $sbr_badge : array();

// An explicit null OVERRIDES the default in array_merge, so the key would be
// present and the partial would still hand esc_html() a null — deprecated since
// PHP 8.1, and a fatal on the string functions the shapes use. A key set to null
// means "no value", which is what the default is for.
$sbr_badge = array_filter($sbr_badge, static function ($sbr_badge_value) {
	return $sbr_badge_value !== null;
});

$sbr_badge = array_merge(
	array(
		// Wrapper
		'classes'           => 'sbr-tb-badge',
		'theme'             => 'light',
		'logos_attr'        => 'brand',
		'style'             => '',
		'is_placeholder'    => false,
		// Rating row
		'show_rating'       => false,
		'rating_label'      => '',
		'show_stars'        => false,
		'show_count'        => false,
		'is_rated'          => false,
		'count_raw_label'   => '',
		'count_based_label' => '',
		'count_on_label'    => '',
		// Marks
		'show_logos'        => false,
		'logo_style'        => 'icon',
		'providers'         => array(),
		// Footer
		'show_powered_by'   => false,
		'powered_by_forced' => false,
		'powered_by_url'    => '',
		'footer_text'       => '',
		'footer_url'        => '',
	),
	$sbr_badge
);
