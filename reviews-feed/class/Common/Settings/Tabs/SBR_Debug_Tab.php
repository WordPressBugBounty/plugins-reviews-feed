<?php

/**
 * Class Debug Settings Tab
 *
 * Hosts the WordPress-compliance consent surface (data-sharing + in-plugin
 * notification toggles). The settings page is a prebuilt React app that
 * renders sections from a config array via a fixed `switch` on section type
 * — it has NO raw-HTML / callback section type, and we must not rebuild the
 * React bundle. So the tab declares TWO placeholder sections with unknown
 * types (`consent-debug-dsc`, `consent-debug-notif`): React renders each as a
 * native row — heading in `.sb-settings-section-label` (left) + an empty
 * `.sb-settings-section-input` (right) — exactly like the Advanced tab's
 * "switcher" rows. The admin_footer hook renders the package's self-contained
 * debug fragments (templates/debug-tab.php) into a hidden holder and relocates
 * each fragment into its section's input column.
 *
 * We can't use the native `switcher` type because that wires the toggle to the
 * plugin's own settings model; consent must persist to the shared sbc_* store
 * via window.sbcConsent.saveChoice, which the relocate JS handles.
 *
 * Relocation is re-entrant: React unmounts/remounts a tab's sections on every
 * tab switch (destroying any node we moved into them), so the JS keeps the
 * fragment HTML + live toggle state in memory and RE-INJECTS whenever an empty
 * target reappears. That fixes the "section vanishes after switching tabs"
 * issue — a plain move-once approach loses the node on the first remount.
 *
 * @since 1.0
 */

namespace SmashBalloon\Reviews\Common\Settings\Tabs;

use Smashballoon\Customizer\V2\SB_SettingsPage_Tab;
use SmashBalloon\Reviews\Common\Util;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class SBR_Debug_Tab extends SB_SettingsPage_Tab {

	/**
	 * Section type marker => template fragment marker. One section per toggle
	 * so each gets a native heading + empty input column to inject into.
	 */
	const SECTION_TYPES = array(
		'consent-debug-dsc'   => 'dsc',
		'consent-debug-notif' => 'notif',
	);

	/**
	 * Constructor. Wires the footer renderer. This class is only instantiated
	 * by Settings_Builder::settingspage_builder_data() during the settings
	 * page load, so the hook is implicitly scoped to that screen.
	 */
	public function __construct()
	{
		// The Data Sharing tab is shown only when consent is user-manageable. When
		// any Smash Balloon Pro plugin is active, consent + in-plugin notifications
		// are forced on and governed centrally, so the tab is removed entirely (see
		// SBR_Settings_Builder::settingspage_builder_data()) and we skip the renderer.
		if (class_exists('\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager')
			&& !\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::is_locked_by_pro()) {
			add_action('admin_footer', array($this, 'render_consent_surface'));
		}
	}

	/**
	 * Get the Settings Tab info.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_info()
	{
		return [
			'id'   => 'sb-data-controls-tab',
			'name' => __('Data Sharing', 'reviews-feed'),
		];
	}

	/**
	 * Get the Settings Tab Sections. Two placeholder sections, one per consent
	 * toggle; their headings render as the native left-column titles.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	protected function tab_sections()
	{
		return [
			'dsc_section' => [
				'id'      => 'sbc_consent_dsc',
				'type'    => 'consent-debug-dsc',
				'heading' => __('Data sharing', 'reviews-feed'),
			],
			'notif_section' => [
				'id'      => 'sbc_consent_notif',
				'type'    => 'consent-debug-notif',
				'heading' => __('In-Plugin Notifications', 'reviews-feed'),
			],
		];
	}

	/**
	 * Render the package's consent fragments into a hidden holder and relocate
	 * each into its React-rendered section input column. See class docblock.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function render_consent_surface()
	{
		echo '<div id="sbc-consent-debug-holder" style="display:none;">';
		\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::render_debug_section( 'reviews' );
		echo '</div>';
		?>
		<script>
		(function () {
			'use strict';
			var SECTION_TYPES = <?php echo wp_json_encode(self::SECTION_TYPES); ?>;

			// Pull the fragment HTML + initial toggle state out of the holder
			// once, then drop the holder so its inputs don't duplicate IDs.
			var templates = {};
			var state     = { dsc: false, notif: false };
			var holder    = document.getElementById('sbc-consent-debug-holder');
			if (holder) {
				for (var type in SECTION_TYPES) {
					if (!SECTION_TYPES.hasOwnProperty(type)) { continue; }
					var frag = holder.querySelector('[data-sbc-consent-fragment="' + SECTION_TYPES[type] + '"]');
					if (frag) { templates[type] = frag.outerHTML; }
				}
				var d0 = holder.querySelector('#sbc-consent-debug-dsc');
				var n0 = holder.querySelector('#sbc-consent-debug-notif');
				state.dsc   = !!(d0 && d0.checked);
				state.notif = !!(n0 && n0.checked);
				if (holder.parentNode) { holder.parentNode.removeChild(holder); }
			}

			function save() {
				if (!window.sbcConsent || typeof window.sbcConsent.saveChoice !== 'function') {
					return;
				}
				// Either flag on counts as "accept" for the modal-dismiss flag.
				var choice = (state.dsc || state.notif) ? 'accept' : 'skip';
				window.sbcConsent.saveChoice(choice, { dsc: state.dsc, notif: state.notif });
			}

			// Reflect current state onto whichever toggles are live. DSC implies
			// notifications: DSC on => notif forced on + locked.
			function applyState() {
				var d = document.getElementById('sbc-consent-debug-dsc');
				var n = document.getElementById('sbc-consent-debug-notif');
				if (d) { d.checked = state.dsc; }
				if (n) {
					n.checked  = state.dsc ? true : state.notif;
					n.disabled = state.dsc;
				}
			}

			function bind(el) {
				if (!el || el.__sbcBound) { return; }
				el.__sbcBound = true;
				el.addEventListener('change', function () {
					if (el.id === 'sbc-consent-debug-dsc') {
						state.dsc = el.checked;
						if (el.checked) { state.notif = true; }
					} else {
						state.notif = el.checked;
					}
					applyState();
					save();
				});
			}

			// Inject any missing fragment into its (empty) section input column.
			function ensure() {
				for (var type in templates) {
					if (!templates.hasOwnProperty(type)) { continue; }
					var sec = document.querySelector('.sb-settings-section-ctn[data-type="' + type + '"]');
					if (!sec) { continue; }
					var target = sec.querySelector('.sb-settings-section-input') || sec;
					if (target.querySelector('[data-sbc-consent-fragment]')) { continue; }
					target.insertAdjacentHTML('beforeend', templates[type]);
				}
				bind(document.getElementById('sbc-consent-debug-dsc'));
				bind(document.getElementById('sbc-consent-debug-notif'));
				applyState();
			}

			// React remounts the active tab's sections on tab switches, so watch
			// the DOM and re-inject whenever a target (re)appears empty.
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
