<?php

namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

class SB_Block_Utils
{
    const CATEGORY_SLUG = 'smashballoon';
    const CATEGORY_TITLE = 'Smash Balloon';
    /**
     * Render a feed shortcode by tag and feed ID.
     *
     * @param string $shortcode_tag The shortcode tag (e.g. 'instagram-feed').
     * @param int    $feed_id       The feed ID.
     *
     * @return string Rendered shortcode output, or empty string if no feed ID.
     */
    public static function render_feed_shortcode($shortcode_tag, $feed_id)
    {
        if (empty($feed_id)) {
            return '';
        }
        return do_shortcode(shortcode_unautop('[' . $shortcode_tag . ' feed=' . (int) $feed_id . ']'));
    }
    /**
     * Resolve the style directory path with RTL support.
     *
     * @param string $style_name    File basename (without extension).
     *
     * @return string Relative path including filename (without extension).
     */
    public static function get_style_path($style_name)
    {
        $rtl = is_rtl() ? '-rtl' : '';
        return 'dist/' . $style_name . $rtl;
    }
}
