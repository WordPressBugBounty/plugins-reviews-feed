<?php

namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

abstract class SB_Feed_Block
{
    abstract protected function get_block_name();
    abstract protected function get_shortcode_tag();
    abstract protected function get_script_handle();
    abstract protected function get_text_domain();
    abstract protected function get_plugin_dir();
    abstract protected function get_enqueue_scripts_action();
    abstract protected function get_editor_localize_data();
    abstract protected function get_localize_var_name();
    abstract protected function get_feed_block_id();
    /**
     * Get the init function name on the window (e.g. 'ctf_init').
     * Override in subclass if needed.
     *
     * @return string
     */
    protected function get_init_function()
    {
        return '';
    }
    /**
     * Get the corresponding legacy block name for this feed (e.g. 'sbi/sbi-feed-block').
     * When set, the framework's editor script wraps the legacy block's edit() output
     * in a root <div> that applies useBlockProps(), restoring the canvas toolbar /
     * move handles for apiVersion 3 legacy blocks inside the WP 7.0+ iframed editor.
     * Return '' to skip.
     *
     * @return string
     */
    protected function get_legacy_block_name()
    {
        return '';
    }
    /**
     * Get the directory containing block.json.
     * Defaults to the vendor path. Override in subclass if needed.
     *
     * @return string
     */
    protected function get_block_dir()
    {
        return $this->get_plugin_dir() . 'vendor/smashballoon/framework/Packages/Blocks/build/feed-block/' . $this->get_feed_block_id();
    }
    public function register_hooks()
    {
        add_action('init', array($this, 'register_block'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_frontend_styles'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'), 25);
        add_action('enqueue_block_assets', array($this, 'enqueue_block_content_assets'));
        add_filter('block_categories_all', array($this, 'register_block_category'), 10, 2);
        $this->register_with_registry();
    }
    /**
     * Register this block with the central registry if a feed block ID is defined.
     */
    protected function register_with_registry()
    {
        $registry = SB_Feed_Blocks_Registry::instance();
        $registry->register_feed_block(array('id' => $this->get_feed_block_id(), 'localizeVar' => $this->get_localize_var_name(), 'initFunction' => $this->get_init_function(), 'legacyBlockName' => $this->get_legacy_block_name()));
    }
    public function register_block()
    {
        register_block_type($this->get_block_dir(), array('render_callback' => array($this, 'render_block')));
    }
    public function render_block($attributes)
    {
        return SB_Block_Utils::render_feed_shortcode($this->get_shortcode_tag(), !empty($attributes['feedId']) ? $attributes['feedId'] : 0);
    }
    /**
     * Enqueue frontend content assets (CSS/JS) inside the block editor.
     *
     * Uses enqueue_block_assets so assets load inside the iframe in WP 7.0+.
     * Only enqueues in admin context to avoid double-enqueuing on the frontend.
     */
    public function enqueue_block_content_assets()
    {
        if (!is_admin()) {
            return;
        }
        do_action($this->get_enqueue_scripts_action(), \true);
    }
    public function enqueue_editor_assets()
    {
        $registry = SB_Feed_Blocks_Registry::instance();
        $handle = $registry->get_script_handle();
        wp_localize_script($handle, $this->get_localize_var_name(), $this->get_editor_localize_data());
        $this->set_script_translations();
    }
    public function maybe_enqueue_frontend_styles()
    {
        if (has_block($this->get_block_name())) {
            do_action($this->get_enqueue_scripts_action(), \true);
        }
    }
    public function register_block_category($categories, $context)
    {
        $exists = array_search(SB_Block_Utils::CATEGORY_SLUG, array_column($categories, 'slug'));
        if ($exists !== \false) {
            return $categories;
        }
        return array_merge($categories, array(array('slug' => SB_Block_Utils::CATEGORY_SLUG, 'title' => __(SB_Block_Utils::CATEGORY_TITLE, $this->get_text_domain()))));
    }
    public function has_feed_block()
    {
        return has_block($this->get_block_name());
    }
    public function set_script_translations()
    {
        wp_set_script_translations($this->get_script_handle(), $this->get_text_domain(), $this->get_plugin_dir() . 'languages');
    }
}
