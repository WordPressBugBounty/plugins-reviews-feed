<?php

/**
 * Recommended Elementor Widgets for suggesting other Smash Balloon plugins.
 *
 * Elementor equivalent of RecommendedBlocks. Registers faux widgets for
 * uninstalled SB plugins so users can discover and install them from the
 * Elementor editor panel.
 *
 * @package Smashballoon\Framework\Packages\Blocks
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('\Elementor\Widget_Base')) {
    return;
}
use Elementor\Widget_Base;
/**
 * Abstract base class for faux Elementor widgets representing uninstalled SB plugins.
 */
abstract class SB_Faux_Elementor_Widget extends Widget_Base
{
    abstract protected function get_plugin_id();
    abstract protected function get_plugin_title();
    abstract protected function get_widget_name_id();
    public function get_name()
    {
        return $this->get_widget_name_id();
    }
    public function get_title()
    {
        return esc_html($this->get_plugin_title());
    }
    public function get_icon()
    {
        return 'sb-elem-icon sb-elem-inactive sb-elem-' . $this->get_plugin_id();
    }
    public function get_categories()
    {
        return array(SB_Block_Utils::CATEGORY_SLUG);
    }
    public function get_script_depends()
    {
        return array('sb-elementor-handler');
    }
    /**
     * Safety net: render nothing if a faux widget is added to the canvas.
     */
    protected function render()
    {
    }
    protected function content_template()
    {
    }
}
/**
 * Concrete faux-widget subclasses, one per recommended SB plugin.
 *
 * Each subclass keeps Elementor's standard `Widget_Base::__construct( $data, $args )`
 * signature so Elementor can re-instantiate the widget from saved document data
 * without an ArgumentCountError. Plugin metadata is hardcoded per subclass.
 */
class SB_Faux_Widget_Facebook extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'facebook';
    }
    protected function get_plugin_title()
    {
        return 'Facebook Feed';
    }
    protected function get_widget_name_id()
    {
        return 'cff-widget';
    }
}
class SB_Faux_Widget_Instagram extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'instagram';
    }
    protected function get_plugin_title()
    {
        return 'Instagram Feed';
    }
    protected function get_widget_name_id()
    {
        return 'sbi-widget';
    }
}
class SB_Faux_Widget_Youtube extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'youtube';
    }
    protected function get_plugin_title()
    {
        return 'YouTube Feed';
    }
    protected function get_widget_name_id()
    {
        return 'sby-widget';
    }
}
class SB_Faux_Widget_Twitter extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'twitter';
    }
    protected function get_plugin_title()
    {
        return 'Twitter Feed';
    }
    protected function get_widget_name_id()
    {
        return 'ctf-widget-faux';
    }
}
class SB_Faux_Widget_Tiktok extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'tiktok';
    }
    protected function get_plugin_title()
    {
        return 'TikTok Feed';
    }
    protected function get_widget_name_id()
    {
        return 'sbtt-widget';
    }
}
class SB_Faux_Widget_Reviews extends SB_Faux_Elementor_Widget
{
    protected function get_plugin_id()
    {
        return 'reviews';
    }
    protected function get_plugin_title()
    {
        return 'Reviews Feed';
    }
    protected function get_widget_name_id()
    {
        return 'sbr-widget';
    }
}
/**
 * Registers faux Elementor widgets for uninstalled Smash Balloon plugins.
 *
 * Usage (in any SB plugin's init):
 *   $recommended = new RecommendedElementorWidgets( 'twitter' );
 *   $recommended->setup();
 */
class RecommendedElementorWidgets
{
    /**
     * Current plugin ID to exclude from recommendations.
     *
     * @var string
     */
    private $current_plugin;
    /**
     * Plugin definitions with metadata for all SB plugins.
     *
     * @var array
     */
    private static $plugins = array('facebook' => array('title' => 'Facebook Feed', 'widget_name_id' => 'cff-widget', 'plugin_path' => 'custom-facebook-feed/custom-facebook-feed.php', 'pro_plugin_path' => 'custom-facebook-feed-pro/custom-facebook-feed.php', 'redirect_option' => 'cff_plugin_do_activation_redirect', 'description' => 'To display a Facebook feed, our Facebook plugin is required. It provides a clean and beautiful way to add your Facebook posts to your website. Grab your visitors\' attention and keep them engaged with your site longer.', 'svgIcon' => '<svg viewBox="0 0 14 15" width="36" height="36"><path d="M7.00016 0.860001C3.3335 0.860001 0.333496 3.85333 0.333496 7.54C0.333496 10.8733 2.7735 13.64 5.96016 14.14V9.47333H4.26683V7.54H5.96016V6.06667C5.96016 4.39333 6.9535 3.47333 8.48016 3.47333C9.20683 3.47333 9.96683 3.6 9.96683 3.6V5.24667H9.12683C8.30016 5.24667 8.04016 5.76 8.04016 6.28667V7.54H9.8935L9.5935 9.47333H8.04016V14.14C9.61112 13.8919 11.0416 13.0903 12.0734 11.88C13.1053 10.6697 13.6704 9.13043 13.6668 7.54C13.6668 3.85333 10.6668 0.860001 7.00016 0.860001Z" fill="rgb(0, 107, 250)"/></svg>'), 'instagram' => array('title' => 'Instagram Feed', 'widget_name_id' => 'sbi-widget', 'plugin_path' => 'instagram-feed/instagram-feed.php', 'pro_plugin_path' => 'instagram-feed-pro/instagram-feed.php', 'redirect_option' => 'sbi_plugin_do_activation_redirect', 'description' => 'To display an Instagram feed, our Instagram plugin is required. It provides a clean and beautiful way to add your Instagram posts to your website. Grab your visitors\' attention and keep them engaged with your site longer.', 'svgIcon' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 9.91406C13.5 9.91406 9.91406 13.5703 9.91406 18C9.91406 22.5 13.5 26.0859 18 26.0859C22.4297 26.0859 26.0859 22.5 26.0859 18C26.0859 13.5703 22.4297 9.91406 18 9.91406ZM18 23.2734C15.1172 23.2734 12.7266 20.9531 12.7266 18C12.7266 15.1172 15.0469 12.7969 18 12.7969C20.8828 12.7969 23.2031 15.1172 23.2031 18C23.2031 20.9531 20.8828 23.2734 18 23.2734ZM28.2656 9.63281C28.2656 8.57812 27.4219 7.73438 26.3672 7.73438C25.3125 7.73438 24.4688 8.57812 24.4688 9.63281C24.4688 10.6875 25.3125 11.5312 26.3672 11.5312C27.4219 11.5312 28.2656 10.6875 28.2656 9.63281ZM33.6094 11.5312C33.4688 9 32.9062 6.75 31.0781 4.92188C29.25 3.09375 27 2.53125 24.4688 2.39062C21.8672 2.25 14.0625 2.25 11.4609 2.39062C8.92969 2.53125 6.75 3.09375 4.85156 4.92188C3.02344 6.75 2.46094 9 2.32031 11.5312C2.17969 14.1328 2.17969 21.9375 2.32031 24.5391C2.46094 27.0703 3.02344 29.25 4.85156 31.1484C6.75 32.9766 8.92969 33.5391 11.4609 33.6797C14.0625 33.8203 21.8672 33.8203 24.4688 33.6797C27 33.5391 29.25 32.9766 31.0781 31.1484C32.9062 29.25 33.4688 27.0703 33.6094 24.5391C33.75 21.9375 33.75 14.1328 33.6094 11.5312ZM30.2344 27.2812C29.7422 28.6875 28.6172 29.7422 27.2812 30.3047C25.1719 31.1484 20.25 30.9375 18 30.9375C15.6797 30.9375 10.7578 31.1484 8.71875 30.3047C7.3125 29.7422 6.25781 28.6875 5.69531 27.2812C4.85156 25.2422 5.0625 20.3203 5.0625 18C5.0625 15.75 4.85156 10.8281 5.69531 8.71875C6.25781 7.38281 7.3125 6.32812 8.71875 5.76562C10.7578 4.92188 15.6797 5.13281 18 5.13281C20.25 5.13281 25.1719 4.92188 27.2812 5.76562C28.6172 6.25781 29.6719 7.38281 30.2344 8.71875C31.0781 10.8281 30.8672 15.75 30.8672 18C30.8672 20.3203 31.0781 25.2422 30.2344 27.2812Z" fill="url(#paint0_linear_sb_inst)"/><defs><linearGradient id="paint0_linear_sb_inst" x1="13.4367" y1="62.5289" x2="79.7836" y2="-5.19609" gradientUnits="userSpaceOnUse"><stop stop-color="white"/><stop offset="0.147864" stop-color="#F6640E"/><stop offset="0.443974" stop-color="#BA03A7"/><stop offset="0.733337" stop-color="#6A01B9"/><stop offset="1" stop-color="#6B01B9"/></linearGradient></defs></svg>'), 'youtube' => array('title' => 'YouTube Feed', 'widget_name_id' => 'sby-widget', 'plugin_path' => 'feeds-for-youtube/youtube-feed.php', 'pro_plugin_path' => 'youtube-feed-pro/youtube-feed.php', 'redirect_option' => 'sby_plugin_do_activation_redirect', 'description' => 'To display a YouTube feed, our YouTube plugin is required. It provides a simple yet powerful way to display videos from YouTube on your website, increasing engagement with your channel while keeping visitors on your website.', 'svgIcon' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 22.5L22.785 18L15 13.5V22.5ZM32.34 10.755C32.535 11.46 32.67 12.405 32.76 13.605C32.865 14.805 32.91 15.84 32.91 16.74L33 18C33 21.285 32.76 23.7 32.34 25.245C31.965 26.595 31.095 27.465 29.745 27.84C29.04 28.035 27.75 28.17 25.77 28.26C23.82 28.365 22.035 28.41 20.385 28.41L18 28.5C11.715 28.5 7.8 28.26 6.255 27.84C4.905 27.465 6.035 26.595 3.66 25.245C3.465 24.54 3.33 23.595 3.24 22.395C3.135 21.195 3.09 20.16 3.09 19.26L3 18C3 14.715 3.24 12.3 3.66 10.755C6.035 9.405 4.905 8.535 6.255 8.16C6.96 7.965 8.25 7.83 10.23 7.74C12.18 7.635 13.965 7.59 15.615 7.59L18 7.5C24.285 7.5 28.2 7.74 29.745 8.16C31.095 8.535 31.965 9.405 32.34 10.755Z" fill="#EB2121"/></svg>'), 'twitter' => array('title' => 'Twitter Feed', 'widget_name_id' => 'ctf-widget-faux', 'plugin_path' => 'custom-twitter-feeds/custom-twitter-feed.php', 'pro_plugin_path' => 'custom-twitter-feeds-pro/custom-twitter-feed.php', 'redirect_option' => 'ctf_plugin_do_activation_redirect', 'description' => 'Custom Twitter Feeds is a highly customizable way to display tweets from your Twitter account. Promote your latest content and update your site content automatically.', 'svgIcon' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M33.6905 9C32.5355 9.525 31.2905 9.87 30.0005 10.035C31.3205 9.24 32.3405 7.98 32.8205 6.465C31.5755 7.215 30.1955 7.74 28.7405 8.04C27.5555 6.75 25.8905 6 26.0005 6C20.4755 6 17.5955 8.88 17.5955 12.435C17.5955 12.945 17.6555 13.44 17.7605 13.905C12.4205 13.635 7.66555 11.07 4.50055 7.185C3.94555 8.13 3.63055 9.24 3.63055 10.41C3.63055 12.645 4.75555 14.625 6.49555 15.75C5.43055 15.75 4.44055 15.45 3.57055 15V15.045C3.57055 18.165 5.79055 20.775 8.73055 21.36C7.78664 21.6183 6.79569 21.6543 5.83555 21.465C6.24296 22.7437 7.04085 23.8626 8.11707 24.6644C9.19329 25.4662 10.4937 25.9105 11.8355 25.935C9.56099 27.7357 6.74154 28.709 3.84055 28.695C3.33055 28.695 2.82055 28.665 2.31055 28.605C5.16055 30.435 8.55055 31.5 12.1805 31.5C26.0005 31.5 30.4955 21.69 30.4955 13.185C30.4955 12.9 30.4955 12.63 30.4805 12.345C31.7405 11.445 32.8205 10.305 33.6905 9Z" fill="#1B90EF"/></svg>'), 'tiktok' => array('title' => 'TikTok Feed', 'widget_name_id' => 'sbtt-widget', 'plugin_path' => 'feeds-for-tiktok/feeds-for-tiktok.php', 'pro_plugin_path' => 'tiktok-feeds-pro/tiktok-feeds-pro.php', 'redirect_option' => '', 'description' => 'To display your TikToks, TikTok Feeds is required. Display your latest TikTok videos in a clean feed featuring a video player so visitors can watch without leaving your site.', 'svgIcon' => '<svg width="26" height="30" viewBox="0 0 26 30" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22.8163 6.63053C22.6189 6.5285 22.4267 6.41664 22.2405 6.29539C21.6989 5.93738 21.2024 5.51552 20.7616 5.03892C19.6587 3.77697 19.2468 2.49674 19.095 1.60039H19.1011C18.9744 0.856382 19.0268 0.375 19.0347 0.375H14.0113V19.7997C14.0113 20.0605 14.0113 20.3182 14.0003 20.5729C14.0003 20.6046 13.9973 20.6339 13.9954 20.668C13.9954 20.682 13.9954 20.6966 13.9924 20.7113C13.9924 20.7149 13.9924 20.7186 13.9924 20.7222C13.9394 21.4192 13.716 22.0925 13.3418 22.6828C12.9676 23.2731 12.454 23.7625 11.8463 24.1078C11.2129 24.4681 10.4965 24.6571 9.76779 24.6562C7.4273 24.6562 5.53041 22.7477 5.53041 20.3908C5.53041 18.0338 7.4273 16.1253 9.76779 16.1253C10.2108 16.1249 10.6512 16.1946 11.0724 16.3319L11.0785 11.2171C9.7997 11.0519 8.50055 11.1535 7.263 11.5155C6.02545 11.8776 4.87636 12.4922 3.88823 13.3205C3.02239 14.0728 2.29447 14.9704 1.73724 15.973C1.52519 16.3386 0.725118 17.8077 0.628232 20.1921C0.567297 21.5455 0.97373 22.9476 1.1675 23.527V23.5392C1.28937 23.8805 1.76161 25.0449 2.53121 26.0266C3.15179 26.814 3.88499 27.5057 4.70718 28.0795V28.0673L4.71937 28.0795C7.15126 29.732 9.84762 29.6235 9.84762 29.6235C10.3144 29.6047 11.878 29.6235 13.6536 28.782C15.623 27.8491 16.7442 26.4592 16.7442 26.4592C17.4605 25.6287 18.03 24.6823 18.4284 23.6605C18.883 22.4656 19.0347 21.0324 19.0347 20.4596V10.1544C19.0956 10.1909 19.9073 10.7278 19.9073 10.7278C19.9073 10.7278 21.0766 11.4773 22.901 11.9653C24.2099 12.3127 25.9733 12.3858 25.9733 12.3858V7.39892C25.3554 7.46594 24.1008 7.27095 22.8163 6.63053Z" fill="#141B38"/></svg>'), 'reviews' => array('title' => 'Reviews Feed', 'widget_name_id' => 'sbr-widget', 'plugin_path' => 'reviews-feed/sb-reviews.php', 'pro_plugin_path' => 'reviews-feed-pro/sb-reviews-pro.php', 'redirect_option' => 'sbr_plugin_do_activation_redirect', 'description' => 'To display reviews in a feed, Reviews Feed is required. Display reviews from Google Reviews or Yelp in a clean feed on your site. Increase conversions with social proof from your latest public reviews.', 'svgIcon' => '<svg width="36" height="36" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M30.2626 3.375H5.66199C3.9707 3.375 2.58691 4.75878 2.58691 6.45007V24.3471C2.58691 26.2261 4.08678 27.7616 5.96528 27.8056L13.2144 27.9756L17.22 33.2391C17.4201 33.502 17.8157 33.502 18.0158 33.2391L22.0214 27.9756H30.2626C31.9539 27.9756 33.3376 26.5918 33.3376 24.9005V6.45007C33.3376 4.75878 31.9539 3.375 30.2626 3.375Z" fill="#FF611E"/><path d="M17.449 9.58077C17.6139 9.10638 18.2848 9.10638 18.4497 9.58078L19.7851 13.4224C19.7996 13.4642 19.8387 13.4926 19.883 13.4935L23.9492 13.5764C24.4514 13.5866 24.6587 14.2247 24.2585 14.5281L21.0175 16.9852C20.9822 17.012 20.9673 17.0579 20.9801 17.1003L22.1578 20.9931C22.3033 21.4738 21.7605 21.8682 21.3482 21.5813L18.0099 19.2583C17.9735 19.233 17.9252 19.233 17.8889 19.2583L14.5505 21.5813C14.1383 21.8682 13.5955 21.4738 13.7409 20.9931L14.9187 17.1003C14.9315 17.0579 14.9166 17.012 14.8813 16.9852L11.6403 14.5281C11.2401 14.2247 11.4474 13.5866 11.9496 13.5764L16.0158 13.4935C16.0601 13.4926 16.0991 13.4642 16.1137 13.4224L17.449 9.58077Z" fill="white"/></svg>'));
    /**
     * Get all plugin definitions.
     *
     * @return array
     */
    public static function get_plugins()
    {
        return self::$plugins;
    }
    /**
     * @param string $current_plugin Plugin ID to exclude (e.g. 'twitter', 'youtube').
     */
    public function __construct($current_plugin)
    {
        $this->current_plugin = $current_plugin;
    }
    /**
     * Register all Elementor hooks.
     */
    public function setup()
    {
        add_action('elementor/widgets/register', array($this, 'register_faux_widgets'));
        add_action('elementor/frontend/after_register_scripts', array($this, 'register_handler_script'));
        add_action('elementor/elements/categories_registered', array($this, 'register_category'));
        add_action('elementor/preview/enqueue_scripts', array($this, 'enqueue_handler_in_preview'));
    }
    /**
     * Enqueue the handler script in the Elementor preview iframe so it runs
     * before any widget interaction (disables drag on faux widgets).
     */
    public function enqueue_handler_in_preview()
    {
        if (!wp_script_is('sb-elementor-handler', 'registered')) {
            $this->register_handler_script();
        }
        wp_enqueue_script('sb-elementor-handler');
    }
    /**
     * Ensure the Smash Balloon widget category exists.
     *
     * @param \Elementor\Elements_Manager $elements_manager Elementor elements manager.
     */
    public function register_category($elements_manager)
    {
        $elements_manager->add_category(SB_Block_Utils::CATEGORY_SLUG, array('title' => esc_html__(SB_Block_Utils::CATEGORY_TITLE, 'smashballoon'), 'icon' => 'fa fa-plug'));
    }
    /**
     * Register faux widgets for uninstalled SB plugins.
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
     */
    public function register_faux_widgets($widgets_manager)
    {
        $active = $this->get_active_plugins();
        // Map plugin id → concrete faux-widget class. Each class hardcodes its
        // metadata so Elementor can re-instantiate via the standard 2-arg
        // `__construct( $data, $args )` signature when restoring saved docs.
        $class_map = array('facebook' => __NAMESPACE__ . '\SB_Faux_Widget_Facebook', 'instagram' => __NAMESPACE__ . '\SB_Faux_Widget_Instagram', 'youtube' => __NAMESPACE__ . '\SB_Faux_Widget_Youtube', 'twitter' => __NAMESPACE__ . '\SB_Faux_Widget_Twitter', 'tiktok' => __NAMESPACE__ . '\SB_Faux_Widget_Tiktok', 'reviews' => __NAMESPACE__ . '\SB_Faux_Widget_Reviews');
        foreach (self::$plugins as $id => $plugin) {
            if ($id === $this->current_plugin) {
                continue;
            }
            if ($active[$id]) {
                continue;
            }
            if (!isset($class_map[$id]) || !class_exists($class_map[$id])) {
                continue;
            }
            $widgets_manager->register(new $class_map[$id]());
        }
    }
    /**
     * Register and localize the handler script + enqueue shared styles.
     */
    public function register_handler_script()
    {
        if (wp_script_is('sb-elementor-handler', 'registered')) {
            return;
        }
        $plugin_data = array();
        $active = $this->get_active_plugins();
        foreach (self::$plugins as $id => $plugin) {
            if ($id === $this->current_plugin) {
                continue;
            }
            if ($active[$id]) {
                continue;
            }
            $is_on_disk = file_exists(\WP_PLUGIN_DIR . '/' . $plugin['plugin_path']) || file_exists(\WP_PLUGIN_DIR . '/' . $plugin['pro_plugin_path']);
            $plugin_data[$id] = array('displayName' => $plugin['title'], 'description' => $plugin['description'], 'svgIcon' => $plugin['svgIcon'], 'download_plugin' => $plugin['plugin_path'], 'pluginInstalled' => $is_on_disk);
        }
        wp_register_script('sb-elementor-handler', plugin_dir_url(__FILE__) . 'js/sb-elementor-handler.js', array('jquery'), '1.0.0', \true);
        wp_localize_script('sb-elementor-handler', 'sbHandler', array('smashPlugins' => $plugin_data, 'nonce' => wp_create_nonce('am_recommended_block_install'), 'ajax_handler' => admin_url('admin-ajax.php')));
        SB_Elementor_Editor_Assets::enqueue_shared_elementor_styles('1.0.0');
    }
    /**
     * Check which SB plugins are active (free or pro).
     *
     * @return array<string, bool> Keyed by plugin ID.
     */
    private function get_active_plugins()
    {
        $active_plugins = (array) get_option('active_plugins', array());
        $result = array();
        foreach (self::$plugins as $id => $plugin) {
            $result[$id] = in_array($plugin['plugin_path'], $active_plugins, \true) || in_array($plugin['pro_plugin_path'], $active_plugins, \true);
        }
        return $result;
    }
}
