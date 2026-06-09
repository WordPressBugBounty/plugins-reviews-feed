<?php

namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

class SB_Feed_Blocks_Registry
{
    private static $instance = null;
    private $feed_blocks = array();
    private $elementor_widgets = array();
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    private function __construct()
    {
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'), 20);
        add_action('enqueue_block_assets', array($this, 'enqueue_editor_styles'));
    }
    /**
     * Register a feed block configuration.
     *
     * @param array $config {
     *     @type string $id           Block identifier (e.g. 'twitter').
     *     @type string $localizeVar  Window variable for localized data.
     *     @type string $initFunction Window function to re-init the feed.
     * }
     */
    public function register_feed_block($config)
    {
        $this->feed_blocks[$config['id']] = $config;
    }
    /**
     * Register an Elementor widget configuration.
     *
     * @param array $config {
     *     @type string $blockId    Block identifier (e.g. 'twitter').
     *     @type string $widgetName Elementor widget name.
     *     @type string $globalVar  Window variable for localized data.
     *     @type string $feedInitFn Window function name to re-init the feed.
     * }
     */
    public function register_elementor_widget($config)
    {
        $this->elementor_widgets[$config['blockId']] = $config;
    }
    /**
     * Enqueue the unified feed blocks editor script + localize registry data.
     */
    public function enqueue_editor_assets()
    {
        if (empty($this->feed_blocks)) {
            return;
        }
        $asset_file = plugin_dir_path(__FILE__) . 'dist/sb-feed-blocks.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : array('dependencies' => array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render'), 'version' => '1.0.0');
        wp_enqueue_script('sb-feed-blocks', plugin_dir_url(__FILE__) . 'dist/sb-feed-blocks.js', $asset['dependencies'], $asset['version'], \true);
        // Each scoped plugin (e.g. Twitter, Instagram) compiles its own copy of this
        // registry under a different prefixed namespace, so each holds a separate
        // singleton with only its own block. wp_localize_script would have each call
        // overwrite the previous one — the last plugin loaded wins and the others
        // vanish from the editor. Merge into the existing global instead.
        $json = wp_json_encode(array_values($this->feed_blocks));
        wp_add_inline_script('sb-feed-blocks', 'window.sbFeedBlocksRegistry = (window.sbFeedBlocksRegistry || []).concat(' . $json . ');', 'before');
    }
    /**
     * Enqueue editor styles via enqueue_block_assets so they load inside the
     * iframe in WP 7.0+. Only enqueues in admin to avoid frontend duplication.
     */
    public function enqueue_editor_styles()
    {
        if (!is_admin() || empty($this->feed_blocks)) {
            return;
        }
        $asset_file = plugin_dir_path(__FILE__) . 'dist/sb-feed-blocks.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : array('version' => '1.0.0');
        wp_enqueue_style('sb-feed-blocks', plugin_dir_url(__FILE__) . 'dist/sb-feed-blocks.css', array(), $asset['version']);
    }
    /**
     * Get the script handle for the unified feed blocks script.
     *
     * Consumer plugins localize their own data against this handle.
     *
     * @return string
     */
    public function get_script_handle()
    {
        return 'sb-feed-blocks';
    }
    /**
     * Get the style handle for the unified feed blocks style.
     *
     * @return string
     */
    public function get_style_handle()
    {
        return 'sb-feed-blocks';
    }
    /**
     * Get registered feed block configs.
     *
     * @return array
     */
    public function get_feed_blocks()
    {
        return $this->feed_blocks;
    }
    /**
     * Get registered elementor widget configs.
     *
     * @return array
     */
    public function get_elementor_widgets()
    {
        return $this->elementor_widgets;
    }
    /**
     * Enqueue the unified Elementor editor script + localize registry data.
     *
     * Call this from the Elementor editor assets hook.
     */
    public function enqueue_elementor_assets()
    {
        if (empty($this->elementor_widgets)) {
            return;
        }
        $asset_file = plugin_dir_path(__FILE__) . 'dist/sb-elementor-editor.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : array('dependencies' => array('jquery', 'wp-element'), 'version' => '1.0.0');
        wp_register_script('sb-elementor-editor', plugin_dir_url(__FILE__) . 'dist/sb-elementor-editor.js', array_unique(array_merge($asset['dependencies'], array('jquery'))), $asset['version'], \true);
        // Same scoper-induced multi-singleton problem as in enqueue_editor_assets():
        // merge into the existing global instead of overwriting it.
        $json = wp_json_encode(array_values($this->elementor_widgets));
        wp_add_inline_script('sb-elementor-editor', 'window.sbElementorRegistry = (window.sbElementorRegistry || []).concat(' . $json . ');', 'before');
        wp_register_style('sb-elementor-editor', plugin_dir_url(__FILE__) . 'dist/sb-elementor-editor.css', array(), $asset['version']);
    }
}
