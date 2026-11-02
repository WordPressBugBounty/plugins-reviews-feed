<?php

/**
 * Shared Zapier settings surface (site URL + API key management).
 *
 * Renders the vanilla (no Vue/React) markup every feed plugin needs on its
 * settings screen to connect Zapier: the site URL and the generate /
 * reveal-once / revoke API key flow.
 *
 * Two host layouts, one mount path. Every host consumes the same fragment
 * markup and the same bundled script, so the generate / reveal-once / revoke
 * behaviour can never drift between plugins:
 *
 * 1. **Customizer "relocate" tabs** (sb-reviews, TikTok, …) — the settings page
 *    is a prebuilt React app whose section renderer has no raw-HTML type, so
 *    the plugin's tab declares placeholder sections with unknown types and this
 *    surface relocates the matching fragment into each section's empty
 *    `.sb-settings-section-input` column. Mirrors the Consent package's
 *    `render_debug_section()` approach.
 *
 * 2. **Slot hosts** — plugins with their own settings app (Instagram Feed's Vue
 *    page) or any plain-PHP settings screen drop an empty
 *    `data-sbc-zapier-slot="<fragment>"` element (`connection` or `upsell`)
 *    wherever the surface should appear; the bundled script injects the
 *    matching fragment into it and keeps it in sync across re-renders.
 *    Passing `render_headings => true` makes the surface print its own
 *    section sub-heading, so a slot host's template shrinks to bare slots
 *    without hand-copying heading chrome into every plugin.
 *
 * The former "read `config()` and render your own markup natively" mode is
 * retired: there is no per-plugin Zapier markup to maintain anymore.
 *
 * Persistence is NOT the plugin's settings model — the key is generated and
 * revoked over admin-ajax, whose action names the consumer supplies.
 *
 * Usage:
 *
 *   Zapier_Settings_Surface::render([
 *       'prefix'       => 'sbr',
 *       'available'    => true,
 *       'key'          => ['last4' => '1234', 'created_on' => '2026-07-29'],
 *       'nonce'        => wp_create_nonce('sbr-admin'),
 *       'generate'     => 'sbr_zapier_generate_key',
 *       'revoke'       => 'sbr_zapier_revoke_key',
 *       'upsell_url'   => 'https://smashballoon.com/reviews-feed/pricing/',
 *       'section_types' => [
 *           'zapier-connection' => 'connection',
 *           'zapier-upsell'     => 'upsell',
 *       ],
 *   ]);
 *
 * @package Smashballoon\Framework
 * @since 1.1
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Class Zapier_Settings_Surface
 *
 * @since 1.1
 */
class Zapier_Settings_Surface
{
    /**
     * Asset handle base.
     *
     * @var string
     */
    const ASSET_HANDLE = 'sbc-zapier-settings';
    /**
     * Smash Balloon doc for the Zapier integration, shared by every plugin.
     *
     * @var string
     */
    const DOCS_URL = 'https://smashballoon.com/doc/smash-balloon-zapier-integration/';
    /**
     * Default section type => fragment name map for customizer tabs.
     *
     * @var array
     */
    const DEFAULT_SECTION_TYPES = array('zapier-connection' => 'connection', 'zapier-upsell' => 'upsell');
    /**
     * Default CSS classes for the surface's controls. A consumer overrides any
     * of these via config['classes'] to render with its own settings-page
     * classes so the surface looks native on that host; anything left unset
     * keeps the self-contained `.sbc-zapier-*` styling.
     *
     *  - field:     extra class appended to the structural `.sbc-zapier-field`
     *               wrapper (e.g. a host class the host scopes its inputs to).
     *  - input:     replaces the input class.
     *  - button:    base button class.
     *  - primary / secondary / danger / upsell: variant modifiers appended to
     *               the base button class.
     *
     * @var array
     */
    const DEFAULT_CLASSES = array(
        'field' => '',
        'input' => 'sbc-zapier-input',
        // Optional host wrappers around each input (outer, inner), e.g. the
        // customizer's `sb-input-ctn` > `sb-input-insider` license-key field.
        'input_wrap' => '',
        'input_inner' => '',
        // API key field once a key exists, like a valid license key: class
        // added to the input wrapper, and the class of the check icon.
        'valid' => 'sbc-zapier-valid',
        'icon' => 'sbc-zapier-icon',
        'button' => 'sbc-zapier-btn',
        'primary' => 'sbc-zapier-btn-primary',
        'secondary' => '',
        'danger' => 'sbc-zapier-btn-danger',
        'upsell' => 'sbc-zapier-btn-primary',
        // Toggle row (config `toggle`): outer row, its label column and body
        // column, and the switch label wrapping the checkbox.
        'row' => 'sbc-zapier-toggle-row',
        'label' => 'sbc-zapier-toggle-label',
        'body' => 'sbc-zapier-toggle-body',
        'switch' => 'sbc-zapier-switch',
        // Switch inner spans. Hosts with a native switcher pass its classes;
        // an empty indicator drops the inner span (e.g. a ::before knob).
        'track' => 'toggle-track',
        'indicator' => 'toggle-indicator',
        // Optional host wrappers so the row inherits the host's own spacing:
        // around the toggle + description, and around the switch alone.
        // `desc` replaces the description class (hosts pass their help-text).
        'toggle_wrap' => '',
        'switch_wrap' => '',
        'desc' => 'sbc-zapier-desc',
    );
    /**
     * Normalize a caller config, filling in defaults.
     *
     * @since 1.1
     *
     * @param array $config Raw config from the consumer plugin.
     *
     * @return array
     */
    public static function config(array $config)
    {
        $prefix = isset($config['prefix']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string) $config['prefix'])) : 'sbc';
        if ($prefix === '') {
            $prefix = 'sbc';
        }
        $defaults = array(
            'prefix' => $prefix,
            'available' => \false,
            'key' => null,
            'nonce' => '',
            'generate' => $prefix . '_zapier_generate_key',
            'revoke' => $prefix . '_zapier_revoke_key',
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => home_url(),
            'upsell_url' => '',
            'mount' => 'sb-app',
            // Slot hosts (mode 2) set this so the surface prints the
            // "Connection Details" sub-heading itself. Customizer hosts (mode 1)
            // leave it false — their section labels already supply the heading
            // in the left column.
            'render_headings' => \false,
            // Toggle row: one "Zapier Integration" row with an on/off switch
            // and the connection panel under it, shown only while on. Elite
            // only: with `toggle` set, a site without Zapier gets no markup at
            // all (no upsell). Slot hosts drop `data-sbc-zapier-slot="row"`.
            'toggle' => \false,
            'enabled' => \false,
            'set_enabled' => $prefix . '_zapier_set_enabled',
            // Customizer hosts set false: their section heading is the label.
            'row_label' => \true,
            // "i" icon after the description, linking to DOCS_URL. The plugin
            // passes its utm_campaign (e.g. 'instagram-pro'). Pass
            // `docs_url => ''` to hide the icon.
            'docs_url' => self::DOCS_URL,
            'docs_campaign' => '',
            'section_types' => self::DEFAULT_SECTION_TYPES,
            'classes' => array(),
            'i18n' => array(),
        );
        $config = array_merge($defaults, $config);
        // Disabled Zaps are otherwise invisible to the site owner. Only read
        // once a key exists, so the tables are known to have been created.
        $config['disabled_count'] = 0;
        if ($config['available'] && !empty($config['key'])) {
            global $wpdb;
            $suppress = $wpdb->suppress_errors(\true);
            $config['disabled_count'] = (new Subscription_Store(Zapier_Config::from_array(array('prefix' => $prefix))))->count_disabled();
            $wpdb->suppress_errors($suppress);
        }
        $config['i18n'] = array_merge(self::strings(), (array) $config['i18n']);
        $config['classes'] = array_merge(self::DEFAULT_CLASSES, (array) $config['classes']);
        if ($config['docs_url'] !== '' && $config['docs_campaign'] !== '') {
            $config['docs_url'] = add_query_arg(array('utm_campaign' => $config['docs_campaign'], 'utm_source' => 'settings', 'utm_medium' => 'docs'), $config['docs_url']);
        }
        // Only advertise the fragments this state actually renders, so the
        // relocate loop never waits on a section that will not appear.
        if ($config['toggle']) {
            $wanted = $config['available'] ? array('row') : array();
        } else {
            $wanted = $config['available'] ? array('connection') : array('upsell');
        }
        $config['section_types'] = array_filter((array) $config['section_types'], function ($fragment) use ($wanted) {
            return in_array($fragment, $wanted, \true);
        });
        return $config;
    }
    /**
     * Translatable strings, overridable per plugin via config['i18n'].
     *
     * Text domain is intentionally 'sb-common' here; consumers that need their
     * own domain pass overrides through config.
     *
     * @since 1.1
     *
     * @return array
     */
    public static function strings()
    {
        return array(
            'intro' => '',
            // Section sub-heading, printed only when render_headings is on
            // (slot hosts). Overridable per plugin via config['i18n'].
            'connectionHeading' => __('Connection Details', 'sb-common'),
            'siteUrlLabel' => __('Site URL', 'sb-common'),
            /* translators: %d: number of paused Zaps. */
            'disabledZaps' => __('%d Zap(s) stopped receiving events after 5 failed deliveries in a row. We retry each one hourly and it resumes on its own once its endpoint responds. Check the Zap in your Zapier account.', 'sb-common'),
            'apiKeyLabel' => __('API Key', 'sb-common'),
            'copy' => __('Copy', 'sb-common'),
            'copied' => __('Copied!', 'sb-common'),
            'generate' => __('Generate API Key', 'sb-common'),
            'revoke' => __('Revoke', 'sb-common'),
            'noKey' => __('No key yet', 'sb-common'),
            'showOnce' => __('Copy it now. It is only shown once.', 'sb-common'),
            'revokeConfirm' => __('Revoke this key? Zaps using it stop working.', 'sb-common'),
            'upsellTitle' => __('Zapier is an Elite feature', 'sb-common'),
            'upsellBody' => __('Upgrade to the Elite plan to connect your feeds to Zapier and automate what happens when new items arrive.', 'sb-common'),
            'upsellButton' => __('Upgrade to Elite', 'sb-common'),
            'genericError' => __('Something went wrong. Please try again.', 'sb-common'),
            'toggleTitle' => __('Zapier Integration', 'sb-common'),
            'toggleDesc' => __('Send feed and account events to Zapier to automate other apps.', 'sb-common'),
            'docsLink' => __('Learn more about Zapier', 'sb-common'),
        );
    }
    /**
     * Cache-busting `ver` for an engine asset: the host's version when given,
     * else the file's mtime. Without a `ver` the URL never changes, so CDNs
     * and browsers keep serving an old copy after a plugin update.
     *
     * @param array  $config        Surface config.
     * @param string $relative_path Asset path relative to this package.
     * @return string|null
     */
    private static function asset_version(array $config, $relative_path)
    {
        if (!empty($config['version'])) {
            // Host version alone is not enough: the framework can change
            // between plugin releases, so add a short content hash.
            $hash = @md5_file(__DIR__ . '/' . $relative_path);
            return $hash ? $config['version'] . '.' . substr($hash, 0, 8) : $config['version'];
        }
        $mtime = @filemtime(__DIR__ . '/' . $relative_path);
        return $mtime ? (string) $mtime : null;
    }
    /**
     * Compute the URL for an asset under this package directory.
     *
     * The package is designed to be vendored under
     * `vendor/smashballoon/framework/Packages/Zapier/` inside a consumer
     * plugin, so `__DIR__` is mapped back to a public URL. We resolve it
     * against the most specific WordPress location it lives under, because
     * `plugins_url()` / `content_url()` follow a symlinked or relocated
     * plugins/content dir, whereas substituting `site_url()` for the `ABSPATH`
     * prefix assumes the on-disk tree maps 1:1 onto the site URL — which is
     * only guaranteed for a standard root install and breaks on symlinked or
     * composer-merged layouts. The raw `ABSPATH`→`site_url()` derivation is
     * kept as a last resort for a package vendored somewhere WordPress exposes
     * no directory-to-URL mapping for. All three paths yield the same URL on a
     * standard install.
     *
     * @since 1.1
     *
     * @param string $relative_path e.g. 'assets/css/zapier-settings.css'.
     *
     * @return string
     */
    public static function asset_url($relative_path)
    {
        $relative_path = ltrim($relative_path, '/');
        $dir = wp_normalize_path(__DIR__);
        // Under the (mu-)plugins dir — the documented layout. plugins_url()
        // resolves the correct URL even when the plugins dir is symlinked or
        // relocated, and handles WPMU_PLUGIN_DIR internally.
        $plugin_dir = wp_normalize_path(\WP_PLUGIN_DIR);
        $mu_dir = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path(\WPMU_PLUGIN_DIR) : '';
        if (strpos($dir, $plugin_dir) === 0 || '' !== $mu_dir && strpos($dir, $mu_dir) === 0) {
            return plugins_url($relative_path, __FILE__);
        }
        // Elsewhere under wp-content (e.g. vendored in a theme). content_url()
        // respects a moved or symlinked WP_CONTENT_DIR the same way.
        $content_dir = wp_normalize_path(\WP_CONTENT_DIR);
        if (strpos($dir, $content_dir) === 0) {
            $relative = ltrim(substr($dir, strlen($content_dir)), '/');
            return content_url('/' . $relative . '/' . $relative_path);
        }
        // Fallback: derive from ABSPATH. Correct for a standard root install,
        // best-effort for anything with no WordPress dir-to-URL mapping.
        $abspath = wp_normalize_path(\ABSPATH);
        if (strpos($dir, $abspath) === 0) {
            $relative = ltrim(substr($dir, strlen($abspath)), '/');
            return site_url('/' . $relative . '/' . $relative_path);
        }
        return plugins_url($relative_path, __FILE__);
    }
    /**
     * Enqueue the surface stylesheet and script, localizing the config.
     *
     * Safe to call more than once — WordPress dedupes by handle, and the
     * localized object is only attached on first registration.
     *
     * @since 1.1
     *
     * @param array $config Normalized config.
     *
     * @return void
     */
    public static function enqueue(array $config)
    {
        // Consumers render from `admin_footer`, which normally still lands
        // before wp_print_footer_scripts (priority 20 on the same hook). If a
        // consumer hooks later than that, enqueuing would silently drop the
        // assets — so print them inline instead.
        if (did_action('admin_print_footer_scripts')) {
            self::print_assets_inline($config);
            return;
        }
        if (!wp_style_is(self::ASSET_HANDLE, 'registered')) {
            wp_enqueue_style(self::ASSET_HANDLE, self::asset_url('assets/css/zapier-settings.css'), array(), self::asset_version($config, 'assets/css/zapier-settings.css'));
        }
        if (!wp_script_is(self::ASSET_HANDLE, 'registered')) {
            wp_enqueue_script(self::ASSET_HANDLE, self::asset_url('assets/js/zapier-settings.js'), array(), self::asset_version($config, 'assets/js/zapier-settings.js'), \true);
            wp_localize_script(self::ASSET_HANDLE, 'sbcZapierSettings', self::script_data($config));
        }
    }
    /**
     * Fallback for consumers that render after footer scripts have printed:
     * emit the stylesheet link, the config global and the script directly.
     *
     * @since 1.1
     *
     * @param array $config Normalized config.
     *
     * @return void
     */
    private static function print_assets_inline(array $config)
    {
        printf('<link rel="stylesheet" href="%s" />', esc_url(self::asset_url('assets/css/zapier-settings.css')));
        printf('<script>window.sbcZapierSettings = %s;</script>', wp_json_encode(self::script_data($config), \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT));
        printf('<script src="%s"></script>', esc_url(self::asset_url('assets/js/zapier-settings.js')));
    }
    /**
     * The config object handed to the browser.
     *
     * @since 1.1
     *
     * @param array $config Normalized config.
     *
     * @return array
     */
    private static function script_data(array $config)
    {
        return array('available' => (bool) $config['available'], 'ajaxUrl' => $config['ajax_url'], 'nonce' => $config['nonce'], 'actions' => array('generate' => $config['generate'], 'revoke' => $config['revoke'], 'enable' => $config['set_enabled']), 'enabled' => (bool) $config['enabled'], 'key' => $config['key'] ? $config['key'] : null, 'sectionTypes' => $config['section_types'], 'mount' => $config['mount'], 'i18n' => $config['i18n'], 'validClass' => $config['classes']['valid']);
    }
    /**
     * Render the hidden fragment holder for a customizer "relocate" tab.
     *
     * Call from `admin_footer` on the settings screen. The bundled script
     * captures these fragments, removes the holder, and injects each into the
     * matching React-rendered section's input column (re-injecting on remount).
     *
     * @since 1.1
     *
     * @param array $config Raw config; see the class docblock.
     *
     * @return void
     */
    public static function render(array $config)
    {
        $config = self::config($config);
        self::enqueue($config);
        $template = __DIR__ . '/templates/settings-tab.php';
        if (!file_exists($template) || empty($config['section_types'])) {
            return;
        }
        echo '<div id="sbc-zapier-holder" style="display:none;">';
        include $template;
        echo '</div>';
    }
}
