<?php

/**
 * Help Widget - Floating help panel with feature suggestions, docs, and cross-sell.
 *
 * @package Feedback
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Feedback;

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Renders a 3-view floating help panel on SB admin pages.
 *
 * Views: Home menu, Feature Request form, Plugins cross-sell.
 * Delivered via Shadow DOM for complete CSS isolation.
 */
class HelpWidget
{
    /**
     * Smash Balloon "All Access" cross-sell URL.
     *
     * Hoisted to a class constant so QA / marketing can update UTM params
     * in one place. Filterable via `sb_help_widget_all_access_url`.
     *
     * @var string
     */
    const ALL_ACCESS_URL = 'https://smashballoon.com/all-access/?utm_source=balloon&utm_medium=help-widget&utm_campaign=cross-sell&utm_content=all-access';
    /**
     * All plugin configurations.
     *
     * @var array
     */
    private $configs;
    /**
     * Track whether render_widget() has already emitted output this request.
     *
     * Replaces self::$rendered to avoid the global-state
     * smell flagged in PR review.
     *
     * @var bool
     */
    private static $rendered = \false;
    /**
     * Map of plugin slug → admin page slug prefixes that plugin owns.
     *
     * Used to attribute the current admin screen to the right registered
     * plugin so the widget shows that plugin's help_url / name / version,
     * regardless of plugin load order (without this, when multiple SB
     * plugins are active the widget picked whichever registered first).
     *
     * @var array
     */
    private static $slug_to_screen_prefixes = ['instagram-feed' => ['sb-instagram', 'sbi'], 'instagram-feed-pro' => ['sb-instagram', 'sbi'], 'custom-facebook-feed' => ['sb-facebook', 'cff'], 'custom-facebook-feed-pro' => ['sb-facebook', 'cff'], 'custom-twitter-feeds' => ['sb-twitter', 'ctf'], 'custom-twitter-feeds-pro' => ['sb-twitter', 'ctf'], 'feeds-for-youtube' => ['sb-youtube', 'sby', 'feeds-for-youtube'], 'feeds-for-youtube-pro' => ['sb-youtube', 'sby', 'feeds-for-youtube'], 'reviews-feed' => ['sb-reviews', 'sbr', 'reviews-feed'], 'reviews-feed-pro' => ['sb-reviews', 'sbr', 'reviews-feed'], 'sb-tiktok-feeds' => ['sb-tiktok', 'sbtt', 'feeds-for-tiktok'], 'sb-tiktok-feeds-pro' => ['sb-tiktok', 'sbtt', 'feeds-for-tiktok'], 'social-wall' => ['social-wall', 'sbsw'], 'wpchat' => ['wpchat', 'wp-chat', 'smashballoon-wpchat'], 'wpchat-pro' => ['wpchat', 'wp-chat', 'smashballoon-wpchat']];
    /**
     * Constructor.
     *
     * @param array $configs All plugin configurations from FeedbackManager.
     */
    public function __construct(array $configs)
    {
        $this->configs = $configs;
    }
    /**
     * Public accessor for the slug→prefix map so FeedbackManager can build
     * its is_sb_admin_screen() prefix list without duplicating knowledge.
     *
     * @return array Flat de-duplicated list of all admin page slug prefixes
     *               owned by any SB plugin family.
     */
    public static function get_screen_prefixes()
    {
        $prefixes = [];
        foreach (self::$slug_to_screen_prefixes as $list) {
            foreach ($list as $prefix) {
                $prefixes[$prefix] = \true;
            }
        }
        return array_keys($prefixes);
    }
    /**
     * Get the flat prefix list owned by a specific subset of plugin slugs.
     *
     * Used by FeedbackManager::is_sb_admin_screen() to scope the prefix
     * match to plugins that have actually registered the Help Widget — so
     * an updated plugin doesn't render the FAB on a stale plugin's admin
     * page where the help_url / context would be wrong and feedback
     * submissions would be attributed to the wrong plugin slug.
     *
     * @param array $slugs Plugin slugs to include.
     * @return array Flat de-duplicated list of prefixes for those slugs.
     */
    public static function get_screen_prefixes_for_slugs(array $slugs)
    {
        $prefixes = [];
        foreach ($slugs as $slug) {
            if (isset(self::$slug_to_screen_prefixes[$slug])) {
                foreach (self::$slug_to_screen_prefixes[$slug] as $prefix) {
                    $prefixes[$prefix] = \true;
                }
            }
        }
        return array_keys($prefixes);
    }
    /**
     * Find the registered config that owns the current admin page.
     *
     * Without this, when multiple SB plugins are active the widget picks
     * whichever plugin was registered first (alphabetical load order),
     * showing the wrong help_url for the page the user is on.
     *
     * @return array|null The active config, or null if no config owns
     *                    the current page.
     */
    private function get_active_config()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if (empty($current_page)) {
            return null;
        }
        // 1. Slug-prefix match — page slug starts with a prefix owned by a
        // registered plugin family. This is the common case.
        foreach ($this->configs as $slug => $config) {
            $prefixes = isset(self::$slug_to_screen_prefixes[$slug]) ? self::$slug_to_screen_prefixes[$slug] : [];
            foreach ($prefixes as $prefix) {
                if (strpos($current_page, $prefix) === 0) {
                    return $config;
                }
            }
        }
        // 2. Plugin-registered extra screens — a plugin explicitly opted
        // its admin page in via the help_widget_screens config key, so it
        // owns that page even if the slug doesn't match any prefix.
        foreach ($this->configs as $config) {
            if (!empty($config['help_widget_screens']) && in_array($current_page, (array) $config['help_widget_screens'], \true)) {
                return $config;
            }
        }
        // 3. Legacy 'smash-balloon' generic pages and pages admitted by the
        // sb_help_widget_is_admin_screen filter — these don't belong to a
        // specific plugin family. Attribute to the first registered config
        // so the FAB still renders with *some* valid context. This mirrors
        // what FeedbackManager::is_sb_admin_screen() admits via the legacy
        // prefix and the filter override.
        $is_legacy = strpos($current_page, 'smash-balloon') === 0;
        /** This filter is documented in FeedbackManager::is_sb_admin_screen() */
        $filter_admitted = (bool) apply_filters('sb_help_widget_is_admin_screen', \false, $current_page);
        if ($is_legacy || $filter_admitted) {
            $first = reset($this->configs);
            return $first ? $first : null;
        }
        return null;
    }
    /**
     * Enqueue widget assets.
     *
     * Mirrors DeactivationSurvey: enqueues the JS via wp_enqueue_script and
     * passes the data blob via wp_add_inline_script. CSS stays inlined
     * inside the Shadow DOM template (rendered by render_widget()) for
     * complete style isolation.
     *
     * @return void
     */
    public function enqueue_assets()
    {
        // Build cross-sell data, excluding currently active plugin(s).
        $current_slugs = array_keys($this->configs);
        $cross_sell = self::get_cross_sell_plugins($current_slugs);
        // Determine which plugin owns the current admin page. If no
        // registered config matches, refuse to render — the FAB context
        // (help_url, plugin name, submission attribution) would be wrong.
        // FeedbackManager::is_sb_admin_screen() already gates the prefix
        // match to enabled configs, so this is defense-in-depth for filter
        // overrides (sb_help_widget_is_admin_screen, help_widget_screens).
        $active_config = $this->get_active_config();
        if (!$active_config) {
            return;
        }
        $active_slug = array_search($active_config, $this->configs, \true);
        // Help URL: prefer the active plugin's, fall back to any registered
        // plugin's, then a generic default.
        $help_url = !empty($active_config['help_url']) ? $active_config['help_url'] : '';
        if (!$help_url) {
            foreach ($this->configs as $config) {
                if (!empty($config['help_url'])) {
                    $help_url = $config['help_url'];
                    break;
                }
            }
        }
        if (!$help_url) {
            $help_url = 'https://smashballoon.com/docs/';
        }
        // Build plugin registry for context metadata.
        $plugins_registry = [];
        foreach ($this->configs as $slug => $config) {
            $plugins_registry[$slug] = ['name' => $config['plugin_name'], 'version' => $config['plugin_version']];
        }
        // Get current user email for form prefill.
        $current_user = wp_get_current_user();
        $user_email = $current_user->user_email ?? '';
        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sb_feature_suggestion'),
            'plugins' => $plugins_registry,
            'primaryPlugin' => $active_slug,
            'primaryName' => $active_config ? $active_config['plugin_name'] : '',
            'primaryVersion' => $active_config ? $active_config['plugin_version'] : '',
            'crossSellPlugins' => $cross_sell,
            'helpUrl' => $help_url,
            'userEmail' => $user_email,
            'supportUrl' => $active_config ? $active_config['support_url'] : 'https://smashballoon.com/support/',
            /**
             * Filter the All Access cross-sell URL surfaced by the Help Widget.
             *
             * @param string $url Default URL with UTM params.
             */
            'allAccessUrl' => apply_filters('sb_help_widget_all_access_url', self::ALL_ACCESS_URL),
            'i18n' => ['descriptionTooShort' => __('Too short. Try adding at least a sentence.', 'sb-common'), 'rateLimitTitle' => __('Too many requests', 'sb-common'), 'rateLimitMessage' => __('Please wait a moment and try again.', 'sb-common'), 'errorTitle' => __('Something went wrong', 'sb-common'), 'errorMessage' => sprintf(
                /* translators: %s: support email link HTML */
                __('Please try again. If the problem persists, email us at %s', 'sb-common'),
                '<a href="mailto:support@smashballoon.com">support@smashballoon.com</a>'
            )],
        ];
        $asset_url = $this->get_asset_url();
        wp_enqueue_script('sb-help-widget', $asset_url . 'help-widget.js', [], $this->get_version(), \true);
        // JSON_HEX_* flags ensure any "</script>" or quote chars in plugin
        // names / user email can't break out of the inline script context.
        $data_inline = sprintf('window.sbHelpWidgetData = %s;', wp_json_encode($data, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT));
        wp_add_inline_script('sb-help-widget', $data_inline, 'before');
    }
    /**
     * Get the URL to the assets directory.
     *
     * Uses the same resolution strategy as DeactivationSurvey:
     * 1. Try to resolve relative to WP_CONTENT_DIR.
     * 2. Fallback to first registered plugin directory.
     *
     * @return string
     */
    private function get_asset_url()
    {
        $asset_dir = dirname(__FILE__) . '/assets/';
        // Try to resolve URL from wp-content.
        $content_dir = wp_normalize_path(\WP_CONTENT_DIR);
        $asset_path = wp_normalize_path($asset_dir);
        if (strpos($asset_path, $content_dir) === 0) {
            $relative = substr($asset_path, strlen($content_dir));
            return content_url($relative);
        }
        // Fallback: use first registered plugin to resolve URL.
        $first_config = reset($this->configs);
        if ($first_config && !empty($first_config['plugin_file'])) {
            $plugin_dir = wp_normalize_path(dirname($first_config['plugin_file']));
            $relative = str_replace($plugin_dir, '', $asset_path);
            return plugins_url($relative, $first_config['plugin_file']);
        }
        return '';
    }
    /**
     * Get version string from first registered plugin.
     *
     * @return string
     */
    private function get_version()
    {
        $first = reset($this->configs);
        return $first ? $first['plugin_version'] : '1.0.0';
    }
    /**
     * Render the Shadow DOM host and template in the admin footer.
     *
     * @return void
     */
    public function render_widget()
    {
        // Guard against duplicate output when multiple plugins register.
        if (!empty(self::$rendered)) {
            return;
        }
        self::$rendered = \true;
        $css_file = dirname(__FILE__) . '/assets/help-widget.css';
        $css = '';
        if (is_readable($css_file)) {
            $contents = file_get_contents($css_file);
            if (\false !== $contents) {
                // Defense-in-depth: strip any literal `</style>` sequence in case
                // the package asset is ever tampered with. The closing tag is
                // never legal inside a CSS block.
                $css = str_ireplace('</style>', '', $contents);
            }
        }
        ?>
		<div id="sb-help-widget-host"></div>
		<template id="sb-help-widget-tpl">
			<style><?php 
        echo $css;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Package CSS file; `</style>` stripped above. 
        ?></style>

			<!-- FAB Trigger -->
			<button type="button" class="sb-hw-fab" aria-label="<?php 
        esc_attr_e('Open help menu', 'sb-common');
        ?>" aria-expanded="false" data-help-trigger>
				<span class="sb-hw-fab-icon sb-hw-fab-icon--help">
					<!-- IoHelp icon (react-icons/io5) — exact SVG from package -->
					<svg width="28" height="28" viewBox="0 0 512 512" fill="currentColor" stroke="currentColor" stroke-width="0"><path fill="none" stroke-linecap="round" stroke-miterlimit="10" stroke-width="40" d="M160 164s1.44-33 33.54-59.46C212.6 88.83 235.49 84.28 256 84c18.73-.23 35.47 2.94 45.48 7.82C318.59 100.2 352 120.6 352 164c0 45.67-29.18 66.37-62.35 89.18S248 298.36 248 324"/><circle cx="248" cy="399.99" r="32"/></svg>
				</span>
				<span class="sb-hw-fab-icon sb-hw-fab-icon--close">
					<!-- Phosphor X (bold) -->
					<svg width="26" height="26" viewBox="0 0 256 256" fill="currentColor"><path d="M208.49,191.51a12,12,0,0,1-17,17L128,145,64.49,208.49a12,12,0,0,1-17-17L111,128,47.51,64.49a12,12,0,0,1,17-17L128,111l63.51-63.52a12,12,0,0,1,17,17L145,128Z"/></svg>
				</span>
			</button>

			<!-- Panel -->
			<div class="sb-hw-panel" role="dialog" aria-modal="true" aria-label="<?php 
        esc_attr_e('Help and Feedback', 'sb-common');
        ?>">

				<!-- Gradient Header Background -->
				<div class="sb-hw-gradient"></div>

				<!-- View: Home -->
				<div class="sb-hw-view sb-hw-view--home sb-hw-view--active" data-view="home">
					<div class="sb-hw-view-content">
						<div class="sb-hw-header">
							<button type="button" class="sb-hw-close" aria-label="<?php 
        esc_attr_e('Close', 'sb-common');
        ?>">
								<svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M208.49,191.51a12,12,0,0,1-17,17L128,145,64.49,208.49a12,12,0,0,1-17-17L111,128,47.51,64.49a12,12,0,0,1,17-17L128,111l63.51-63.52a12,12,0,0,1,17,17L145,128Z"/></svg>
							</button>
							<h2 class="sb-hw-title"><?php 
        /* translators: Line break for visual layout — keeps "How can we" on line 1. */
        echo esc_html__('How can we', 'sb-common') . '<br>' . esc_html__('help you?', 'sb-common');
        ?></h2>
						</div>
						<div class="sb-hw-home-cards">
							<button type="button" class="sb-hw-home-card" data-action="feature">
								<span class="sb-hw-home-card-icon sb-hw-home-card-icon--amber">
									<!-- Phosphor Hand (regular) — exact path from @phosphor-icons/react -->
									<svg width="28" height="28" viewBox="0 0 256 256" fill="currentColor"><path d="M188,48a27.75,27.75,0,0,0-12,2.71V44a28,28,0,0,0-54.65-8.6A28,28,0,0,0,80,60v64l-3.82-6.13a28,28,0,0,0-48.6,27.82c16,33.77,28.93,57.72,43.72,72.69C86.24,233.54,103.2,240,128,240a88.1,88.1,0,0,0,88-88V76A28,28,0,0,0,188,48Zm12,104a72.08,72.08,0,0,1-72,72c-20.38,0-33.51-4.88-45.33-16.85C69.44,193.74,57.26,171,41.9,138.58a6.36,6.36,0,0,0-.3-.58,12,12,0,0,1,20.79-12,1.76,1.76,0,0,0,.14.23l18.67,30A8,8,0,0,0,96,152V60a12,12,0,0,1,24,0v60a8,8,0,0,0,16,0V44a12,12,0,0,1,24,0v76a8,8,0,0,0,16,0V76a12,12,0,0,1,24,0Z"/></svg>
								</span>
								<span class="sb-hw-home-card-text">
									<span class="sb-hw-home-card-title"><?php 
        esc_html_e('I have an idea or feedback', 'sb-common');
        ?></span>
									<span class="sb-hw-home-card-subtitle"><?php 
        esc_html_e('Help shape the product with your input', 'sb-common');
        ?></span>
								</span>
							</button>
							<a href="#" class="sb-hw-home-card" data-action="help" target="_blank" rel="noopener noreferrer">
								<span class="sb-hw-home-card-icon sb-hw-home-card-icon--emerald">
									<!-- Phosphor Question (regular) — exact path from @phosphor-icons/react -->
									<svg width="28" height="28" viewBox="0 0 256 256" fill="currentColor"><path d="M140,180a12,12,0,1,1-12-12A12,12,0,0,1,140,180ZM128,72c-22.06,0-40,16.15-40,36v4a8,8,0,0,0,16,0v-4c0-11,10.77-20,24-20s24,9,24,20-10.77,20-24,20a8,8,0,0,0-8,8v8a8,8,0,0,0,16,0v-.72c18.24-3.35,32-17.9,32-35.28C168,88.15,150.06,72,128,72Zm104,56A104,104,0,1,1,128,24,104.11,104.11,0,0,1,232,128Zm-16,0a88,88,0,1,0-88,88A88.1,88.1,0,0,0,216,128Z"/></svg>
								</span>
								<span class="sb-hw-home-card-text">
									<span class="sb-hw-home-card-title"><?php 
        esc_html_e('I need help', 'sb-common');
        ?></span>
									<span class="sb-hw-home-card-subtitle"><?php 
        esc_html_e('Find answers or talk to support', 'sb-common');
        ?></span>
								</span>
								<span class="sb-hw-home-card-arrow">
									<!-- Phosphor ArrowUpRight (bold) -->
									<svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M200,64V168a8,8,0,0,1-16,0V83.31L69.66,197.66a8,8,0,0,1-11.32-11.32L172.69,72H88a8,8,0,0,1,0-16H192A8,8,0,0,1,200,64Z"/></svg>
								</span>
							</a>
							<button type="button" class="sb-hw-home-card sb-hw-home-card--plugins" data-action="plugins">
								<span class="sb-hw-plugin-icons-fan">
									<!-- Plugin brand icons rendered by JS -->
								</span>
								<span class="sb-hw-home-card-plugins-label">
									<span class="sb-hw-home-card-title"><?php 
        esc_html_e('Explore our other plugins', 'sb-common');
        ?></span>
									<!-- Phosphor CaretRight (bold) -->
									<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M181.66,133.66l-80,80a8,8,0,0,1-11.32-11.32L164.69,128,90.34,53.66a8,8,0,0,1,11.32-11.32l80,80A8,8,0,0,1,181.66,133.66Z"/></svg>
								</span>
							</button>
						</div>
					</div>
				</div>

				<!-- View: Feature Request -->
				<div class="sb-hw-view sb-hw-view--feature" data-view="feature">
					<div class="sb-hw-view-content">
						<div class="sb-hw-header sb-hw-header--sub">
							<button type="button" class="sb-hw-back" aria-label="<?php 
        esc_attr_e('Back to menu', 'sb-common');
        ?>">
								<svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z"/></svg>
							</button>
							<h2 class="sb-hw-title"><?php 
        esc_html_e('Feature Request', 'sb-common');
        ?></h2>
						</div>
						<form class="sb-hw-form" novalidate>
							<!-- Description card -->
							<div class="sb-hw-card">
								<label for="sb-hw-description" class="sb-hw-label"><?php 
        esc_html_e("What's your idea?", 'sb-common');
        ?></label>
								<textarea id="sb-hw-description" class="sb-hw-textarea" rows="5" maxlength="2000" placeholder="<?php 
        esc_attr_e("Describe what you'd like to see and what you're trying to accomplish...", 'sb-common');
        ?>"></textarea>
								<div class="sb-hw-field-footer" style="display:none;">
									<span class="sb-hw-field-error" aria-live="polite"></span>
									<span class="sb-hw-char-counter"></span>
								</div>
							</div>
							<!-- Email card -->
							<div class="sb-hw-card">
								<label for="sb-hw-email" class="sb-hw-label"><?php 
        esc_html_e('Your email', 'sb-common');
        ?></label>
								<span class="sb-hw-label-sub"><?php 
        esc_html_e('Optional — we\'ll only use this to follow up on your suggestion', 'sb-common');
        ?></span>
								<input type="email" id="sb-hw-email" class="sb-hw-input" placeholder="you@example.com" />
								<span class="sb-hw-field-error sb-hw-email-error" aria-live="polite" style="display:none;"></span>
								<label class="sb-hw-checkbox-label">
									<input type="checkbox" id="sb-hw-notify" class="sb-hw-checkbox" />
									<span><?php 
        esc_html_e('Get notified about new features and updates', 'sb-common');
        ?></span>
								</label>
							</div>
							<!-- Submit -->
							<div class="sb-hw-form-submit">
								<button type="submit" class="sb-hw-btn sb-hw-btn--primary sb-hw-submit-btn">
									<svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg>
									<span class="sb-hw-submit-text"><?php 
        esc_html_e('Submit Request', 'sb-common');
        ?></span>
								</button>
							</div>
						</form>

						<!-- Success State -->
						<div class="sb-hw-success" style="display:none;">
							<div class="sb-hw-success-icon">
								<svg width="32" height="32" viewBox="0 0 256 256" fill="currentColor"><path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"/></svg>
							</div>
							<h3 class="sb-hw-success-title"><?php 
        esc_html_e('Feature Request Submitted!', 'sb-common');
        ?></h3>
							<p class="sb-hw-success-message"><?php 
        esc_html_e('Thank you for your suggestion. We review all suggestions and use them to shape our roadmap.', 'sb-common');
        ?></p>
							<button type="button" class="sb-hw-btn sb-hw-btn--secondary sb-hw-reset-btn">
								<!-- Phosphor ArrowCounterClockwise (bold) — exact path from @phosphor-icons/react -->
								<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M228,128a100,100,0,0,1-98.66,100H128a99.39,99.39,0,0,1-68.62-27.29,12,12,0,0,1,16.48-17.45,76,76,0,1,0-1.57-109c-.13.13-.25.25-.39.37L54.89,92H72a12,12,0,0,1,0,24H24a12,12,0,0,1-12-12V56a12,12,0,0,1,24,0V76.72L57.48,57.06A100,100,0,0,1,228,128Z"/></svg>
								<?php 
        esc_html_e('Submit another request', 'sb-common');
        ?>
							</button>
						</div>

						<!-- Error State -->
						<div class="sb-hw-error-state" style="display:none;">
							<div class="sb-hw-error-icon">
								<svg width="32" height="32" viewBox="0 0 256 256" fill="currentColor"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm-8,56a8,8,0,0,1,16,0v56a8,8,0,0,1-16,0Zm8,104a12,12,0,1,1,12-12A12,12,0,0,1,128,184Z"/></svg>
							</div>
							<h3 class="sb-hw-error-title"><?php 
        esc_html_e('Something went wrong', 'sb-common');
        ?></h3>
							<p class="sb-hw-error-message"><?php 
        esc_html_e('Please try again. If the problem persists, email us at', 'sb-common');
        ?> <a href="mailto:support@smashballoon.com">support@smashballoon.com</a></p>
							<button type="button" class="sb-hw-btn sb-hw-btn--primary sb-hw-retry-btn">
								<?php 
        esc_html_e('Try Again', 'sb-common');
        ?>
							</button>
						</div>
					</div>
				</div>

				<!-- View: Plugins Cross-sell -->
				<div class="sb-hw-view sb-hw-view--plugins" data-view="plugins">
					<div class="sb-hw-view-content">
						<div class="sb-hw-header sb-hw-header--sub">
							<button type="button" class="sb-hw-back" aria-label="<?php 
        esc_attr_e('Back to menu', 'sb-common');
        ?>">
								<svg width="20" height="20" viewBox="0 0 256 256" fill="currentColor"><path d="M165.66,202.34a8,8,0,0,1-11.32,11.32l-80-80a8,8,0,0,1,0-11.32l80-80a8,8,0,0,1,11.32,11.32L91.31,128Z"/></svg>
							</button>
							<h2 class="sb-hw-title"><?php 
        esc_html_e('More from Smash Balloon', 'sb-common');
        ?></h2>
						</div>

						<!-- All Access Bundle CTA -->
						<a href="#" class="sb-hw-all-access" target="_blank" rel="noopener noreferrer">
							<div class="sb-hw-all-access-icons">
								<!-- Plugin icons rendered by JS (fanned tiles) -->
							</div>
							<div class="sb-hw-all-access-info">
								<div class="sb-hw-all-access-text">
									<span class="sb-hw-all-access-title"><?php 
        esc_html_e('All Access Bundle', 'sb-common');
        ?></span>
									<span class="sb-hw-all-access-subtitle"><?php 
        esc_html_e('Get all our feed plugins, save 60%', 'sb-common');
        ?></span>
								</div>
								<span class="sb-hw-all-access-arrow">
									<svg width="16" height="16" viewBox="0 0 256 256" fill="currentColor"><path d="M200,64V168a8,8,0,0,1-16,0V83.31L69.66,197.66a8,8,0,0,1-11.32-11.32L172.69,72H88a8,8,0,0,1,0-16H192A8,8,0,0,1,200,64Z"/></svg>
								</span>
							</div>
						</a>

						<div class="sb-hw-plugins-divider">
							<span><?php 
        esc_html_e('Or browse individually', 'sb-common');
        ?></span>
						</div>

						<div class="sb-hw-plugins-list">
							<!-- Plugin cards rendered by JS -->
						</div>

						<div class="sb-hw-trust-signals">
							<div class="sb-hw-trust-item">
								<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M243.31,90.91l-128,128a16,16,0,0,1-22.62,0l-71.62-72a16,16,0,0,1,0-22.61l20-20a16,16,0,0,1,22.37-.27L104,143.87l109.07-107.3a16,16,0,0,1,22.37.26l20,20.37A15.89,15.89,0,0,1,243.31,90.91Z"/></svg>
								<span><?php 
        esc_html_e('Trusted by 1.75 million websites', 'sb-common');
        ?></span>
							</div>
							<div class="sb-hw-trust-item">
								<svg width="14" height="14" viewBox="0 0 256 256" fill="currentColor"><path d="M243.31,90.91l-128,128a16,16,0,0,1-22.62,0l-71.62-72a16,16,0,0,1,0-22.61l20-20a16,16,0,0,1,22.37-.27L104,143.87l109.07-107.3a16,16,0,0,1,22.37.26l20,20.37A15.89,15.89,0,0,1,243.31,90.91Z"/></svg>
								<span><?php 
        esc_html_e('6,000+ 5-star reviews', 'sb-common');
        ?></span>
							</div>
						</div>
					</div>
				</div>

				<!-- Aria live region for view transitions -->
				<div class="sb-hw-sr-only" aria-live="polite" aria-atomic="true"></div>

			</div>
		</template>
		<?php 
    }
    /**
     * Handle AJAX feature suggestion submission.
     *
     * @return void
     */
    public static function handle_ajax()
    {
        check_ajax_referer('sb_feature_suggestion', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }
        $slug = isset($_POST['plugin_slug']) ? sanitize_key($_POST['plugin_slug']) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $notify = isset($_POST['notify_on_ship']) && wp_unslash($_POST['notify_on_ship']) === 'true';
        $page = isset($_POST['current_page']) ? sanitize_text_field($_POST['current_page']) : '';
        // Validate description.
        $description = trim($description);
        if (mb_strlen($description) < 5) {
            wp_send_json_error(['message' => 'Description too short'], 400);
        }
        if (mb_strlen($description) > 2000) {
            wp_send_json_error(['message' => 'Description too long'], 400);
        }
        // Find config for context.
        $config = FeedbackManager::get_config($slug);
        if (!$config) {
            // Use first available config if slug doesn't match.
            $all_configs = FeedbackManager::get_all_configs();
            $config = reset($all_configs);
            $slug = $config ? key($all_configs) : 'unknown';
        }
        $api_data = ['plugin_slug' => $slug, 'description' => $description, 'email' => $email, 'notify_on_ship' => $notify, 'current_page' => $page, 'plugin_version' => $config ? $config['plugin_version'] : '', 'wp_version' => get_bloginfo('version'), 'php_version' => phpversion(), 'site_url' => home_url()];
        // Determine API endpoint. Honour a per-plugin api_endpoint override
        // (matches DeactivationSurvey behaviour) so staging / QA setups can
        // point the submit at a controlled host.
        $endpoint = !empty($config['api_endpoint']) ? $config['api_endpoint'] : ApiClient::get_feature_request_endpoint_for_slug($slug);
        // Send to API. Blocking so we can surface rate-limit (429) feedback,
        // so use a longer timeout than the shared ApiClient::TIMEOUT (5s,
        // fine for fire-and-forget deactivation but tight for an interactive
        // submit hitting an API potentially fronted by a CDN). Filterable.
        $timeout = (int) apply_filters('sb_feature_request_timeout', 15);
        $response = wp_remote_post($endpoint, ['timeout' => $timeout, 'blocking' => \true, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'], 'body' => wp_json_encode($api_data), 'sslverify' => \true]);
        $hook_data = array_merge($api_data, ['locale' => get_locale(), 'timestamp' => current_time('mysql', \true)]);
        /**
         * Fires after a feature suggestion is submitted.
         *
         * @param array $data   Submission data.
         * @param array $config Plugin configuration.
         */
        do_action('sb_feature_request_submitted', $hook_data, $config);
        // Check response for user feedback.
        if (is_wp_error($response)) {
            // Graceful degradation — UI shows success — but leave a
            // breadcrumb in dev environments. Gated behind WP_DEBUG_LOG so
            // a flaky upstream can't fill the host's error log in
            // production. The do_action below is the production-grade
            // integration point.
            if (defined('WP_DEBUG') && \WP_DEBUG && defined('WP_DEBUG_LOG') && \WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- dev-only diagnostics, gated above.
                error_log(sprintf('[SB Feature Request] Transport error for %s: %s', $slug, $response->get_error_message()));
            }
            /**
             * Fires when posting a feature request to the API fails at the
             * transport layer (DNS, TLS, timeout, etc.). Useful for forwarding
             * to Slack / Sentry without monkey-patching wp_remote_post.
             *
             * @param string $error_message WP_Error message.
             * @param array  $hook_data     Submission data.
             */
            do_action('sb_feature_request_transport_failed', $response->get_error_message(), $hook_data);
            wp_send_json_success();
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 429) {
            wp_send_json_error(['message' => 'rate_limit'], 429);
        }
        wp_send_json_success();
    }
    /**
     * Get cross-sell plugin data, excluding specified slugs.
     *
     * @param array|string $exclude_slugs Plugin slug(s) to exclude from the list.
     *
     * @return array Filtered plugin data for cross-sell display.
     */
    public static function get_cross_sell_plugins($exclude_slugs = [])
    {
        if (!is_array($exclude_slugs)) {
            $exclude_slugs = [$exclude_slugs];
        }
        // Also exclude any SB plugin family whose plugin file is active on
        // the site, even if it didn't register with FeedbackManager (e.g.
        // installed but on a pre-Help-Widget release). Without this, the
        // list would suggest installing a plugin the user already has — a
        // stale install simply doesn't show up in $exclude_slugs because
        // it didn't call FeedbackManager::init() with the new keys.
        $active_plugin_files = (array) get_option('active_plugins', []);
        if (is_multisite()) {
            $active_plugin_files = array_merge($active_plugin_files, array_keys((array) get_site_option('active_sitewide_plugins', [])));
        }
        $known_sb_slugs = array_keys(self::$slug_to_screen_prefixes);
        foreach ($active_plugin_files as $plugin_file) {
            foreach ($known_sb_slugs as $slug) {
                if (strpos($plugin_file, $slug . '/') === 0) {
                    $exclude_slugs[] = $slug;
                }
            }
        }
        $exclude_slugs = array_unique($exclude_slugs);
        // Map plugin slugs to friendly campaign names for UTM tracking.
        $campaign_map = ['instagram-feed' => 'instagram-free', 'instagram-feed-pro' => 'instagram-pro', 'custom-facebook-feed' => 'facebook-free', 'custom-facebook-feed-pro' => 'facebook-pro', 'custom-twitter-feeds' => 'twitter-free', 'custom-twitter-feeds-pro' => 'twitter-pro', 'feeds-for-youtube' => 'youtube-free', 'feeds-for-youtube-pro' => 'youtube-pro', 'reviews-feed' => 'reviews-free', 'reviews-feed-pro' => 'reviews-pro', 'sb-tiktok-feeds' => 'tiktok-free', 'sb-tiktok-feeds-pro' => 'tiktok-pro', 'wpchat' => 'wpchat'];
        $utm_campaign = 'help-widget';
        // Fallback.
        foreach ($exclude_slugs as $slug) {
            if (isset($campaign_map[$slug])) {
                $utm_campaign = $campaign_map[$slug];
                break;
            }
        }
        $utm_base = 'utm_source=balloon&utm_medium=help-widget&utm_campaign=' . $utm_campaign;
        $all_plugins = [['key' => 'instagram', 'name' => 'Instagram Feed', 'tagline' => 'Display posts from Instagram', 'color' => '#E4405F', 'url' => "https://smashballoon.com/instagram-feed/?{$utm_base}&utm_content=instagram", 'slugs' => ['instagram-feed', 'instagram-feed-pro'], 'icon' => 'instagram'], ['key' => 'facebook', 'name' => 'Facebook Feed', 'tagline' => 'Embed Facebook pages & groups', 'color' => '#1877F2', 'url' => "https://smashballoon.com/custom-facebook-feed/?{$utm_base}&utm_content=facebook", 'slugs' => ['custom-facebook-feed', 'custom-facebook-feed-pro'], 'icon' => 'facebook'], ['key' => 'twitter', 'name' => 'Twitter / X Feed', 'tagline' => 'Pull in tweets and timelines', 'color' => '#0F1419', 'url' => "https://smashballoon.com/custom-twitter-feeds/?{$utm_base}&utm_content=twitter", 'slugs' => ['custom-twitter-feeds', 'custom-twitter-feeds-pro'], 'icon' => 'twitter'], ['key' => 'youtube', 'name' => 'YouTube Feed', 'tagline' => 'Show channel videos & playlists', 'color' => '#FF0000', 'url' => "https://smashballoon.com/youtube-feed/?{$utm_base}&utm_content=youtube", 'slugs' => ['feeds-for-youtube', 'feeds-for-youtube-pro'], 'icon' => 'youtube'], ['key' => 'reviews', 'name' => 'Reviews Feed', 'tagline' => 'Google, Yelp & Facebook reviews', 'color' => '#FF611E', 'url' => "https://smashballoon.com/reviews-feed/?{$utm_base}&utm_content=reviews", 'slugs' => ['reviews-feed', 'reviews-feed-pro'], 'icon' => 'reviews'], ['key' => 'tiktok', 'name' => 'TikTok Feed', 'tagline' => 'Embed TikTok videos on your site', 'color' => '#000000', 'url' => "https://smashballoon.com/tiktok-feeds/?{$utm_base}&utm_content=tiktok", 'slugs' => ['sb-tiktok-feeds', 'sb-tiktok-feeds-pro'], 'icon' => 'tiktok'], ['key' => 'wpchat', 'name' => 'WPChat', 'tagline' => 'AI chat for WordPress sites', 'color' => '#F53C5E', 'url' => "https://wpchat.com?{$utm_base}&utm_content=wpchat", 'slugs' => ['wpchat'], 'icon' => 'wpchat', 'includedInAllAccess' => \false]];
        // Filter out plugins matching any of the exclude slugs.
        return array_values(array_filter($all_plugins, function ($plugin) use ($exclude_slugs) {
            return empty(array_intersect($plugin['slugs'], $exclude_slugs));
        }));
    }
}
