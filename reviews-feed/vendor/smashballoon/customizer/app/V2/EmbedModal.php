<?php

namespace Smashballoon\Customizer\V2;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * The customizer's Embed Feed modal, for builders that are not this React app.
 *
 * Enqueues build/embed-modal (the same EmbedModal component, bundled with its own
 * React 18) and localizes `sbEmbedModalConfig`. The builder then calls
 * `window.SBEmbedModal.open( { feedId: 12 } )` from its Embed button. When
 * enqueue() returns false (WordPress older than 5.8) window.SBEmbedModal is
 * never defined, and the builder offers the shortcode instead.
 * `window.SBEmbedModal.supported` carries is_supported() for callers that
 * check it.
 *
 *     EmbedModal::enqueue( array(
 *         'capability'  => 'manage_instagram_feed_options',
 *         'block_title' => __( 'Instagram Feed', 'instagram-feed' ),
 *         'wizard_arg'  => 'sbi_wizard',
 *         'shortcode'   => '[instagram-feed feed=%s]',
 *     ) );
 *
 * Asset URLs come from this file's own location, so they follow whichever copy of
 * the package (vendored, scoped or not) actually loaded the class.
 */
class EmbedModal
{
    const HANDLE = 'sb-embed-modal';
    /**
     * Enqueue the modal and localize its contract.
     *
     * @param array $args {
     *     @type string $capability  Capability that guards the builder screen.
     *     @type string $block_title The plugin's block title, for the Site Editor hint.
     *     @type string $wizard_arg  Query arg the editor reads to insert the block, carries the feed id.
     *     @type string $shortcode   Shortcode template, `%s` is replaced by the feed id.
     * }
     *
     * @return bool False when the modal was not enqueued, see is_supported().
     */
    public static function enqueue($args)
    {
        if (!self::is_supported()) {
            return \false;
        }
        $args = wp_parse_args($args, array('capability' => 'manage_options', 'block_title' => '', 'wizard_arg' => '', 'shortcode' => ''));
        $root = dirname(__DIR__, 2);
        $dir = $root . '/build/embed-modal/';
        $url = plugins_url('build/embed-modal/', $root . '/composer.json');
        wp_enqueue_style(self::HANDLE, $url . 'embed-modal.css', array(), (string) filemtime($dir . 'embed-modal.css'));
        wp_enqueue_script(self::HANDLE, $url . 'embed-modal.js', array('wp-i18n', 'wp-api-fetch'), (string) filemtime($dir . 'embed-modal.js'), \true);
        $block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
        wp_localize_script(self::HANDLE, 'sbEmbedModalConfig', array('targets' => \Smashballoon\Customizer\V2\EmbedTargets::get($args['capability']), 'shortcode' => (string) $args['shortcode'], 'wizardArg' => sanitize_key($args['wizard_arg']), 'blockTitle' => wp_strip_all_tags((string) $args['block_title']), 'adminPostURL' => admin_url('post.php'), 'siteEditorURL' => $block_theme ? admin_url('site-editor.php') : '', 'widgetsURL' => !$block_theme && current_theme_supports('widgets') ? admin_url('widgets.php') : '', 'supported' => self::is_supported()));
        return \true;
    }
    /**
     * Whether this WordPress can run the whole flow. The bundle carries its own
     * React 18, but "Add and Go to Page" inserts the plugin's feed block, which
     * registers through register_block_type( $dir ) and so needs block.json
     * support, from WordPress 5.8. Older sites keep the shortcode fallback.
     *
     * @return bool
     */
    public static function is_supported()
    {
        return version_compare(get_bloginfo('version'), '5.8', '>=');
    }
}
