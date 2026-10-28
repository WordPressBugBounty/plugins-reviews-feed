<?php

/**
 * Class General Settings Tab
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\Settings\Tabs;

use Smashballoon\Customizer\V2\SB_SettingsPage_Tab;
use SmashBalloon\Reviews\Common\Abilities\SBR_Abilities;
use SmashBalloon\Reviews\Common\Abilities\SBR_Pro_Upsell;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SBR_General_Tab extends SB_SettingsPage_Tab {
	/**
	 * Constructor. When consent is forced on by an active Pro plugin, wires the
	 * footer renderer that injects the read-only "Data sharing" info block into
	 * its placeholder section at the end of this tab (see tab_sections() and
	 * render_data_sharing_surface()). In Free, the user-manageable Data Sharing
	 * tab is shown instead, so we add neither the section nor the renderer.
	 *
	 * This class is auto-instantiated by Settings_Builder::settingspage_builder_data()
	 * during the settings page load, so the hook is implicitly scoped to that screen.
	 */
	public function __construct()
	{
		if ($this->show_data_sharing_info()) {
			add_action('admin_footer', array($this, 'render_data_sharing_surface'));
		}
	}

	/**
	 * Whether the Pro-only "Data sharing" info block should be shown — i.e. when
	 * the Consent package is present and consent is locked on by an active Pro plugin.
	 *
	 * @return bool
	 */
	protected function show_data_sharing_info()
	{
		return class_exists('\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager')
			&& \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::is_locked_by_pro();
	}

	/**
	 * Get the Settings Tab info
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id' => 'sb-general-tab',
			'name' => __('General', 'reviews-feed')
		];
	}

	/**
	 * Get the Settings Tab Section
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_sections()
	{
		$sections = [
			'licensekey_section' => [
				'type' => 'licensekey',
				'heading' => __('License Key', 'reviews-feed'),
				'isProSetting' => true,
				'description' => __('Your license key provides access to updates and support', 'reviews-feed'),
				'separator' => true
			],

			'licensekeyfree_section' => [
				'type' => 'licensekeyfree',
				'heading' => __('License Key', 'reviews-feed'),
				'isFreeSetting' => true,
				'description' => __('Your license key provides access to updates and support', 'reviews-feed'),
				'separator' => true
			],
		];

		// SBR-499 (SMASH-1994 cascade) — AI Agents card + MCP Write Access
		// toggle (AgDR-0503). Shown in both editions whenever the Abilities
		// API itself can register (WordPress 6.9+): since SBR-577 the module
		// is a Common service (class/Common/Abilities/SBR_Abilities.php) and
		// the free build exposes the free subset, so there is always
		// something behind both controls when this guard passes. On older
		// WordPress neither control renders -- the write gate would govern
		// nothing.
		if (SBR_Abilities::is_available()) {
			$wpvibe = Util::get_wpvibe_status();

			// Mirrors Instagram Feed Pro's "AI Agents" section
			// (instagram-feed-pro#1583): brand logos + a WPVibe
			// install/activate CTA whose label reflects the plugin's real
			// install state. The customizer's Settings-tab section-type
			// registry (SettingSectionOutput.js) has no custom/HTML/component
			// type -- only a closed switch of pre-built types -- so this
			// renders through the 'info' field's existing raw-HTML support
			// (already used elsewhere in this file for a help-text link)
			// rather than adding a new type to the shared vendor package.
			// See the PR description's "known gap" note: the
			// install/activate button is a real link/click target wired up
			// by assets/admin/js/ai-agents.js (reuses the existing
			// sbr_install_plugin / sbr_activate_plugin AJAX actions), but
			// the page reloads after a successful action instead of
			// flipping state in place the way the Vue component in
			// Instagram Feed Pro does.
			//
			// 'separator' => false ties this card to the MCP Write Access
			// section rendered just below -- dropping the bottom separator
			// fuses the two into one card. Placed right after the license
			// section and before Manage Sources.
			$sections['aiagents_section'] = [
				'id'   => 'ai_agents',
				'type' => 'ai-agents',
				'heading' => __('AI Agents', 'reviews-feed'),
				'info' => $this->ai_agents_info_html($wpvibe),
				'separator' => false,
			];

			// SBR-499 — WordPress Abilities API write gate (AgDR-0503). Moved
			// here from the Advanced tab (SBR-195) so the AI-assistant
			// permission sits next to the AI Agents card that governs the
			// same trust boundary. Rendered unconditionally FOR PRO (SBR-195
			// QA §1, Joel's 2026-09-23 decision): the write gate is a plugin
			// option the Abilities API reads directly and never depends on
			// WPVibe, so gating the toggle's visibility on WPVibe would
			// leave non-WPVibe MCP clients no way to enable writes and could
			// strand an unrevocable permission if WPVibe were later
			// removed.
			$sections['aiwriteenabled_section'] = [
				'id'        => 'ai_write_enabled',
				'type'      => 'switcher',
				'heading'   => __('MCP Write Access', 'reviews-feed'),
				'info'      => __('This will allow your AI agent to make changes to Reviews Feed.', 'reviews-feed'),
				'options' => [
					'enabled' => true,
					'disabled' => false
				],
				'separator' => true
			];

			// AgDR-9006 — "Do more with AI on Pro": a slim strip directly under the
			// MCP Write Access section. FREE edition only (inert in Pro, where the
			// guard below is false and nothing is added). Same raw-HTML 'info'
			// route as the AI Agents card above: the section type is unknown to the
			// customizer's registry, so it renders just the info HTML in its own row.
			if (!Util::sbr_is_pro()) {
				$sections['aiproupsell_section'] = [
					'type'      => 'ai-pro-upsell',
					'info'      => $this->ai_pro_upsell_html(),
					'separator' => true,
				];
			}
		}

		$sections['sources_section'] = [
			'heading' => __('Manage Sources', 'reviews-feed'),
			'inputDescription' => __('Add or remove connected accounts', 'reviews-feed'),
			'type' => 'sources',
		];

		$sections['apikeys_section'] = [
			'heading' => __('Manage API Keys', 'reviews-feed'),
			'type' => 'apikeys',
			'inputDescription' => __('Update or modify an API key', 'reviews-feed'),
			'separator' => true
		];

		$sections['preservesettings_section'] = [
			'id'        => 'preserve_settings',
			'type'      => 'switcher',
			'heading'   => __('Preserve settings if plugin is removed', 'reviews-feed'),
			'info'      => __('This will make sure that all of your feeds and settings are still saved even if the plugin is uninstalled', 'reviews-feed'),
			'options' => [
				'enabled' => true,
				'disabled' => false
			],
			'separator' => true
		];

		$sections['importfeed_section'] = [
			'heading' => __('Import Feed Settings', 'reviews-feed'),
			'type' => 'importfeed',
			'info'      => __('You will need a JSON file previously exported from the Reviews Feed Plugin', 'reviews-feed')
		];
		$sections['exportfeed_section'] = [
			'heading' => __('Export Feed Settings', 'reviews-feed'),
			'type' => 'exportfeed',
			'info'      => __('Export settings for one or more of your feeds', 'reviews-feed'),
			'separator' => true
		];

		$sections['importcollection_section'] = [
			'heading' => __('Import Collection', 'reviews-feed'),
			'type' => 'importcollection',
			'info'      => __('You will need a JSON file previously exported from the collections Reviews Feed Plugin', 'reviews-feed')
		];
		$sections['exportcollection_section'] = [
			'heading' => __('Export Collection', 'reviews-feed'),
			'type' => 'exportcollection',
			'info'      => __('Export collection reviews', 'reviews-feed'),
			'separator' => true
		];

		// Pro-only read-only "Data sharing" block, pinned at the end of General.
		// An unknown section type renders a native heading row with an empty input
		// column; render_data_sharing_surface() injects the info fragment into it.
		if ($this->show_data_sharing_info()) {
			$sections['datasharing_section'] = [
				'type'    => 'consent-data-sharing',
				'heading' => __('Data sharing', 'reviews-feed'),
			];
		}

		return $sections;
	}

	/**
	 * Markup of the "Do more with AI on Pro" strip (free edition only).
	 *
	 * Feature names come from the plugin's own upsell data via
	 * SBR_Pro_Upsell::banner_feature_names(), so the strip carries no copy of its
	 * own beyond the title and button label. The button is a real link that opens
	 * in a new tab, with the new-tab notice for screen readers. Every dynamic
	 * value is escaped here (the section's 'info' is injected as raw HTML).
	 *
	 * @since 2.12.0
	 *
	 * @return string Raw HTML (already escaped), or '' outside the free edition.
	 */
	private function ai_pro_upsell_html()
	{
		if (!SBR_Pro_Upsell::is_free()) {
			return '';
		}
		return '<span class="sbr-ai-pro-upsell">'
			. '<span class="sbr-ai-pro-upsell-text">'
			. '<strong>' . esc_html__('Do more with AI on Pro', 'reviews-feed') . '</strong>'
			. '<span>' . esc_html(implode(' · ', SBR_Pro_Upsell::banner_feature_names())) . '</span>'
			. '</span>'
			. '<a class="sb-btn sb-btn-primary sb-btn-medium sbr-ai-pro-upsell-btn" href="' . esc_url(SBR_Pro_Upsell::upgrade_url('ai-agents-card', 'settings')) . '" target="_blank" rel="noopener">'
			. esc_html__('Upgrade to Pro', 'reviews-feed')
			. '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'reviews-feed') . '</span>'
			. '</a>'
			. '</span>';
	}

	/**
	 * Build the AI Agents card markup rendered through the 'info' field.
	 *
	 * Content/behavior mirrors Instagram Feed Pro's AI Agents card
	 * (instagram-feed-pro#1583) as closely as the customizer's data-driven
	 * section-type registry allows -- see the comment on 'aiagents_section'
	 * in tab_sections() for why this can't be a first-class section type.
	 *
	 * @since 2.12.0
	 *
	 * @param array{status: string, basename: string|null} $wpvibe Return
	 *        value of Util::get_wpvibe_status().
	 *
	 * @return string Raw HTML (already escaped where it needs to be).
	 */
	private function ai_agents_info_html($wpvibe)
	{
		$wpvibe_org_url = 'https://wordpress.org/plugins/vibe-ai/';

		// Hakim (security review, PR #536): these translated strings carry
		// markup (<br>, <a>) the translator supplies, not this plugin --
		// wp_kses() with a minimal allowlist instead of trusting __() output
		// as pre-safe raw HTML.
		$allowed_html = array(
			'br' => array(),
			'a'  => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);

		/* translators: %s: URL to the WPVibe plugin on WordPress.org */
		$description = wp_kses(
			sprintf(__('Manage Reviews Feed from your favorite AI agent.<br>Works through <a href="%s" target="_blank" rel="noopener">WPVibe</a>, a free plugin you install once.', 'reviews-feed'), esc_url($wpvibe_org_url)), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			$allowed_html
		);

		$apps_label = __('Works with ChatGPT, Cursor and Claude', 'reviews-feed');

		$logos = '<span class="sbr-ai-agents-apps" role="img" aria-label="' . esc_attr($apps_label) . '">'
			. '<span class="sbr-ai-agents-app sbr-ai-agents-app-chatgpt" title="ChatGPT">'
			. '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></svg>'
			. '</span>'
			. '<span class="sbr-ai-agents-app sbr-ai-agents-app-cursor" title="Cursor">'
			. '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23"/></svg>'
			. '</span>'
			. '<span class="sbr-ai-agents-app sbr-ai-agents-app-claude" title="Claude">'
			. '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"/></svg>'
			. '</span>'
			. '</span>';

		switch ($wpvibe['status']) {
			case 'active':
				$heading = __('WPVibe is connected to Reviews Feed.', 'reviews-feed');
				$state_description = wp_kses(
					__('Manage Reviews Feed from your favorite AI agent.<br>Connect Claude, ChatGPT, Cursor or any MCP client in WPVibe.', 'reviews-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					$allowed_html
				);
				$action = '<a href="' . esc_url(admin_url('admin.php?page=vibe-ai')) . '" class="sb-btn sb-btn-secondary sb-btn-medium sbr-ai-agents-action-btn">' . esc_html__('Open WPVibe', 'reviews-feed') . '</a>';
				break;

			case 'inactive':
				$heading = __('Use Reviews Feed with Claude, ChatGPT and more.', 'reviews-feed');
				$state_description = $description;
				$action = '<button type="button" class="sb-btn sb-btn-primary sb-btn-medium sbr-ai-agents-action-btn" data-sbr-wpvibe-action="activate" data-sbr-wpvibe-plugin="' . esc_attr((string) $wpvibe['basename']) . '">' . esc_html__('Activate WPVibe', 'reviews-feed') . '</button>'
					. '<noscript><a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Activate WPVibe from the Plugins screen', 'reviews-feed') . '</a></noscript>';
				break;

			default: // not_installed.
				$heading = __('Use Reviews Feed with Claude, ChatGPT and more.', 'reviews-feed');
				$state_description = $description;
				$action = '<button type="button" class="sb-btn sb-btn-primary sb-btn-medium sbr-ai-agents-action-btn" data-sbr-wpvibe-action="install" data-sbr-wpvibe-plugin="https://downloads.wordpress.org/plugin/vibe-ai.zip">' . esc_html__('Install WPVibe for free', 'reviews-feed') . '</button>'
					. '<noscript><a href="' . esc_url($wpvibe_org_url) . '" target="_blank" rel="noopener">' . esc_html__('Install WPVibe from WordPress.org', 'reviews-feed') . '</a></noscript>';
				break;
		}

		return $logos
			. '<span class="sbr-ai-agents-heading">' . esc_html($heading) . '</span>'
			. '<span class="help-text">' . $state_description . '</span>'
			. '<span class="sbr-ai-agents-action">' . $action . '</span>'
			. '<span class="sbr-ai-agents-error" hidden>'
			/* translators: %s: URL to the Plugins screen */
			. sprintf(esc_html__('Couldn\'t complete the request. %s instead.', 'reviews-feed'), '<a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Use the Plugins screen', 'reviews-feed') . '</a>')
			. '</span>';
	}

	/**
	 * Render the package's data-sharing info fragment into a hidden holder and
	 * relocate it into the React-rendered placeholder section's input column.
	 *
	 * Mirrors SBR_Debug_Tab's relocate technique (re-entrant via MutationObserver
	 * so the block survives React tab remounts) but is toggle-free — purely
	 * informational, so there is no state to bind or persist.
	 *
	 * @return void
	 */
	public function render_data_sharing_surface()
	{
		$permissions_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'permissions', 'reviews');
		$terms_url       = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'terms', 'reviews');
		$privacy_url     = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('settings', 'privacy', 'reviews');
		?>
		<div id="sbc-data-sharing-holder" style="display:none;">
			<div data-sbc-data-sharing-fragment>
				<p style="margin:0 0 10px;"><?php echo esc_html__('To keep your Smash Balloon plugins secure and up to date, we collect limited, anonymised usage data. We never collect your feed content or your visitors’ personal information. We’ll also send you occasional in-plugin notifications about updates, fixes, and new features.', 'reviews-feed'); ?></p>
				<div>
					<a href="<?php echo esc_url($permissions_url); ?>" target="_blank" rel="noopener" style="margin-right:16px;"><?php echo esc_html__('What data is collected?', 'reviews-feed'); ?></a>
					<a href="<?php echo esc_url($terms_url); ?>" target="_blank" rel="noopener" style="margin-right:16px;"><?php echo esc_html__('Terms & Conditions', 'reviews-feed'); ?></a>
					<a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener"><?php echo esc_html__('Privacy', 'reviews-feed'); ?></a>
				</div>
			</div>
		</div>
		<script>
		(function () {
			'use strict';
			var SECTION_TYPE = 'consent-data-sharing';

			var template = null;
			var holder   = document.getElementById('sbc-data-sharing-holder');
			if (holder) {
				var frag = holder.querySelector('[data-sbc-data-sharing-fragment]');
				if (frag) { template = frag.outerHTML; }
				if (holder.parentNode) { holder.parentNode.removeChild(holder); }
			}

			// Inject the fragment into the (empty) section input column whenever
			// it (re)appears — React remounts the active tab's sections on switch.
			function ensure() {
				if (!template) { return; }
				var sec = document.querySelector('.sb-settings-section-ctn[data-type="' + SECTION_TYPE + '"]');
				if (!sec) { return; }
				var target = sec.querySelector('.sb-settings-section-input') || sec;
				if (target.querySelector('[data-sbc-data-sharing-fragment]')) { return; }
				target.insertAdjacentHTML('beforeend', template);
			}

			var app = document.getElementById('sb-app') || document.body;
			new MutationObserver(ensure).observe(app, { childList: true, subtree: true });

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', ensure);
			} else {
				ensure();
			}
		})();
		</script>
		<?php
	}
}
