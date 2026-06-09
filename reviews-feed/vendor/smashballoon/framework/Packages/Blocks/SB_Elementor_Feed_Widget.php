<?php

namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Blocks;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('\Elementor\Widget_Base')) {
    return;
}
abstract class SB_Elementor_Feed_Widget extends Widget_Base
{
    abstract protected function get_widget_name();
    abstract protected function get_widget_title();
    abstract protected function get_widget_icon();
    abstract protected function get_shortcode_tag();
    abstract protected function get_feeds_options();
    abstract protected function get_text_domain();
    abstract protected function get_script_deps();
    abstract protected function get_style_deps();
    abstract protected function get_output_filter();
    public function get_name()
    {
        return $this->get_widget_name();
    }
    public function get_title()
    {
        return esc_html($this->get_widget_title());
    }
    public function get_icon()
    {
        return $this->get_widget_icon();
    }
    public function get_categories()
    {
        return array(SB_Block_Utils::CATEGORY_SLUG);
    }
    public function get_script_depends()
    {
        return $this->get_script_deps();
    }
    public function get_style_depends()
    {
        return $this->get_style_deps();
    }
    protected function register_controls()
    {
        $this->start_controls_section('section_content', array('label' => sprintf(
            /* translators: %s: feed type name, e.g. "Instagram Feed". */
            esc_html__('%s Settings', $this->get_text_domain()),
            $this->get_widget_title()
        )));
        $this->add_control('feed_id', array('label' => esc_html__('Select a Feed', $this->get_text_domain()), 'type' => Controls_Manager::SELECT, 'label_block' => \true, 'options' => array('' => esc_html__('Select a Feed', $this->get_text_domain())) + $this->get_feeds_options(), 'default' => ''));
        $this->end_controls_section();
    }
    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $feed_id = !empty($settings['feed_id']) ? $settings['feed_id'] : 0;
        $output = SB_Block_Utils::render_feed_shortcode($this->get_shortcode_tag(), $feed_id);
        if (empty($output)) {
            $is_editor = is_admin() || class_exists('\Elementor\Plugin') && (\Elementor\Plugin::$instance->editor->is_edit_mode() || \Elementor\Plugin::$instance->preview->is_preview_mode());
            $output = $is_editor ? '<div id="sbc-elementor-cta-root"></div>' : '';
        }
        echo apply_filters($this->get_output_filter(), $output, $settings);
    }
}
