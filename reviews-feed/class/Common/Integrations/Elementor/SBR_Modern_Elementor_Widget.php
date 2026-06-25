<?php

namespace SmashBalloon\Reviews\Common\Integrations\Elementor;

use SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Feed_Widget;
use SmashBalloon\Reviews\Common\Customizer\DB;

if (! defined('ABSPATH')) {
	exit;
}

if (! class_exists('\Elementor\Widget_Base')) {
	return;
}

class SBR_Modern_Elementor_Widget extends SB_Elementor_Feed_Widget {
	protected function get_widget_name()
	{
		return 'sb-reviews-feed';
	}

	protected function get_widget_title()
	{
		return __('Reviews Feed', 'reviews-feed');
	}

	protected function get_widget_icon()
	{
		return 'sb-elem-icon sb-elem-reviews';
	}

	protected function get_shortcode_tag()
	{
		return 'reviews-feed';
	}

	protected function get_feeds_options()
	{
		// SMASH-1052: editor feed-picker only needs id + feed_name, so skip
		// the expensive shortcode-location scans here too (consistency with
		// the front-end loader).
		$feeds   = DB::get_feeds_list(array(), true);
		$options = array();

		if (is_array($feeds)) {
			foreach ($feeds as $feed) {
				$options[ $feed['id'] ] = $feed['feed_name'];
			}
		}

		return $options;
	}

	protected function get_text_domain()
	{
		return 'reviews-feed';
	}

	protected function get_script_deps()
	{
		return array( 'sbr_scripts', 'sb-elementor-editor' );
	}

	protected function get_style_deps()
	{
		return array( 'sbr_styles', 'sb-elementor-editor' );
	}

	protected function get_output_filter()
	{
		return 'sbr_output';
	}
}
