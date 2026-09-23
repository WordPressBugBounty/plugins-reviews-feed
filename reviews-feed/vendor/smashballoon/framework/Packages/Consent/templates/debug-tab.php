<?php

/**
 * Debug-tab consent fragments (vanilla, no Vue/React).
 *
 * Consumed ONLY by the customizer "relocate" tabs (sb-reviews, TikTok) — see
 * each plugin's DebugTab / SBR_Debug_Tab. The settings page is a prebuilt
 * React app whose section renderer has no raw-HTML type, so those tabs declare
 * two placeholder sections (`consent-debug-dsc`, `consent-debug-notif`) and
 * relocate the matching fragment below into each section's empty
 * `.sb-settings-section-input` column. The section's own `heading` supplies the
 * left-column title (native `.sb-h4`), so these fragments are title-less and
 * carry only the toggle + description (+ legal links) — giving the same
 * label | control layout as the native "switcher" rows on the Advanced tab.
 *
 * Persistence is NOT the plugin's settings model: the relocate JS binds these
 * toggles to the shared `window.sbcConsent.saveChoice` endpoint (sbc_* options)
 * and owns the "DSC implies notifications" lock. This template is markup only.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent;

if (!defined('ABSPATH')) {
    exit;
}
$flags = ConsentManager::flags();
$dsc_checked = !empty($flags['dsc']);
$notif_checked = !empty($flags['notif']);
$permissions_url = ConsentManager::link_url('settings', 'permissions');
$terms_url = ConsentManager::link_url('settings', 'terms');
$privacy_url = ConsentManager::link_url('settings', 'privacy');
// When any Smash Balloon Pro plugin is active, consent is forced on and governed
// centrally — the toggles are hidden on this tab; only the description + links
// remain. The relocate JS is null-safe when the toggle inputs are absent.
$locked = ConsentManager::is_locked_by_pro();
?>
<div class="sbc-consent-debug-fragments">
	<div class="sbc-consent-debug-field" data-sbc-consent-fragment="dsc">
		<?php 
if (!$locked) {
    ?>
		<label class="sbc-consent-toggle">
			<input
				type="checkbox"
				id="sbc-consent-debug-dsc"
				name="sbc_data_sharing_consent"
				<?php 
    checked($dsc_checked);
    ?>
			/>
			<span class="sbc-consent-toggle-track"><span class="sbc-consent-toggle-thumb"></span></span>
		</label>
		<?php 
}
?>
		<p class="sbc-consent-debug-desc">
			<?php 
echo esc_html__('We share limited, non-sensitive usage data to keep your plugins updated and improve our products. We never collect your feed content or your visitors’ personal information.', 'sb-common');
?>
		</p>
		<div class="sbc-consent-debug-links">
			<a href="<?php 
echo esc_url($permissions_url);
?>" target="_blank" rel="noopener"><?php 
echo esc_html__('What permissions are being granted?', 'sb-common');
?></a>
			<a href="<?php 
echo esc_url($terms_url);
?>" target="_blank" rel="noopener"><?php 
echo esc_html__('Terms & Conditions', 'sb-common');
?></a>
			<a href="<?php 
echo esc_url($privacy_url);
?>" target="_blank" rel="noopener"><?php 
echo esc_html__('Privacy', 'sb-common');
?></a>
		</div>
	</div>

	<div class="sbc-consent-debug-field" data-sbc-consent-fragment="notif">
		<?php 
if (!$locked) {
    ?>
		<label class="sbc-consent-toggle">
			<input
				type="checkbox"
				id="sbc-consent-debug-notif"
				name="sbc_in_plugin_notifications"
				<?php 
    checked($notif_checked || $dsc_checked);
    ?>
				<?php 
    disabled($dsc_checked);
    ?>
			/>
			<span class="sbc-consent-toggle-track"><span class="sbc-consent-toggle-thumb"></span></span>
		</label>
		<?php 
}
?>
		<p class="sbc-consent-debug-desc">
			<?php 
echo esc_html__('We send you in-plugin notifications about updates, fixes, and new features.', 'sb-common');
?>
		</p>
	</div>
</div>
<?php 
