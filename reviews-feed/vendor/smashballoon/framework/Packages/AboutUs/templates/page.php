<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Main About Us page template.
 *
 * @var array $data {
 *     @type array  $config              Plugin configuration.
 *     @type array  $content             JSON content (team section, featured promo, etc.).
 *     @type array  $sb_plugins          Smash Balloon plugins with status (featured ones flagged).
 *     @type array  $recommended_plugins Recommended plugins with status.
 *     @type string $asset_url           URL to assets directory.
 * }
 *
 * @package AboutUs
 */
if (!\defined('ABSPATH')) {
    exit;
}
if (!\function_exists('SmashBalloon\Reviews\Vendor\sbc_about_us_kses')) {
    /**
     * Sanitize About Us rich-text descriptions. Allows the basic formatting the
     * in-plugin notifications support (links, bold, italic, spans) plus lists —
     * everything else (scripts, images, iframes, etc.) is stripped.
     *
     * @param string $html Raw description HTML.
     * @return string Sanitized HTML.
     */
    function sbc_about_us_kses($html)
    {
        return \wp_kses((string) $html, array('a' => array('href' => array(), 'target' => array(), 'rel' => array()), 'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'span' => array('style' => array()), 'br' => array(), 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array()));
    }
}
if (!\function_exists('SmashBalloon\Reviews\Vendor\sbc_about_us_icon')) {
    /**
     * Resolve a plugin card icon from the content in use: the remote
     * about-us.json when data-sharing consent is on, the bundled file when it
     * is off (ContentProvider never serves remote data without consent). A
     * plugin that exists only in the remote JSON therefore gets its icon from
     * there. An empty icon falls back to the bundled icon for the same key.
     *
     * @param string $key       Plugin key (e.g. 'facebook', 'feed_analytics').
     * @param string $section   'our_plugins' | 'recommended_plugins'.
     * @param array  $plugin    Plugin entry from the content in use.
     * @param string $asset_url Assets base URL.
     * @return string Resolved icon URL, or '' when there is none.
     */
    function sbc_about_us_icon($key, $section, $plugin, $asset_url)
    {
        static $bundled = null;
        $icon = isset($plugin['icon']) && \is_string($plugin['icon']) ? $plugin['icon'] : '';
        if ('' === $icon) {
            if (null === $bundled) {
                $file = \dirname(__FILE__) . '/../assets/about-us-data.json';
                $decoded = \is_readable($file) ? \json_decode(\file_get_contents($file), \true) : [];
                $bundled = \is_array($decoded) ? $decoded : [];
            }
            $icon = isset($bundled[$section]['plugins'][$key]['icon']) ? $bundled[$section]['plugins'][$key]['icon'] : '';
        }
        if ('' === $icon) {
            return '';
        }
        return \filter_var($icon, \FILTER_VALIDATE_URL) ? $icon : $asset_url . $icon;
    }
}
$config = $data['config'];
$content = $data['content'];
$sb_plugins = $data['sb_plugins'];
$rec_plugins = $data['recommended_plugins'];
$asset_url = $data['asset_url'];
$team = isset($content['team_section']) ? $content['team_section'] : [];
$promo = isset($content['featured_promo']) ? $content['featured_promo'] : [];
$our_sec = isset($content['our_plugins']) ? $content['our_plugins'] : [];
$rec_sec = isset($content['recommended_plugins']) ? $content['recommended_plugins'] : [];
?>
<div class="wrap">
<div id="sbc-about-us-app" class="sbc-about-us-wrap">

	<div class="sbc-about-us-header">
		<h1><?php 
\esc_html_e('About Us', 'sb-common');
?></h1>
	</div>

	<?php 
include \dirname(__FILE__) . '/sections/team.php';
?>

	<?php 
include \dirname(__FILE__) . '/sections/featured-promo.php';
?>

	<?php 
include \dirname(__FILE__) . '/sections/our-plugins.php';
?>

	<?php 
include \dirname(__FILE__) . '/sections/recommended.php';
?>

</div>
</div>
<?php 
