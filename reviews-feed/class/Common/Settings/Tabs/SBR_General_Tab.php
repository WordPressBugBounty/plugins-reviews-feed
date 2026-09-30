<?php

/**
 * Class General Settings Tab
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\Settings\Tabs;

use Smashballoon\Customizer\V2\SB_SettingsPage_Tab;

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

			'sources_section' => [
				'heading' => __('Manage Sources', 'reviews-feed'),
				'inputDescription' => __('Add or remove connected accounts', 'reviews-feed'),
				'type' => 'sources',
			],
			'apikeys_section' => [
				'heading' => __('Manage API Keys', 'reviews-feed'),
				'type' => 'apikeys',
				'inputDescription' => __('Update or modify an API key', 'reviews-feed'),
				'separator' => true
			],

			'preservesettings_section' => [
				'id'        => 'preserve_settings',
				'type'      => 'switcher',
				'heading'   => __('Preserve settings if plugin is removed', 'reviews-feed'),
				'info'      => __('This will make sure that all of your feeds and settings are still saved even if the plugin is uninstalled', 'reviews-feed'),
				'options' => [
					'enabled' => true,
					'disabled' => false
				],
				'separator' => true
			],

			'importfeed_section' => [
				'heading' => __('Import Feed Settings', 'reviews-feed'),
				'type' => 'importfeed',
				'info'      => __('You will need a JSON file previously exported from the Reviews Feed Plugin', 'reviews-feed')
			],
			'exportfeed_section' => [
				'heading' => __('Export Feed Settings', 'reviews-feed'),
				'type' => 'exportfeed',
				'info'      => __('Export settings for one or more of your feeds', 'reviews-feed'),
				'separator' => true
			],

			'importcollection_section' => [
				'heading' => __('Import Collection', 'reviews-feed'),
				'type' => 'importcollection',
				'info'      => __('You will need a JSON file previously exported from the collections Reviews Feed Plugin', 'reviews-feed')
			],
			'exportcollection_section' => [
				'heading' => __('Export Collection', 'reviews-feed'),
				'type' => 'exportcollection',
				'info'      => __('Export collection reviews', 'reviews-feed'),
				'separator' => true
			],
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
