<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Onboarding wizard consent checkbox snippet.
 *
 * Plugins that have an onboarding wizard `include` this template inside
 * their wizard's success-page render. The checkbox writes through the
 * same shared sbcConsent.saveChoice JS API as the re-prompt modal, so
 * choosing here marks the modal as dismissed too.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
if (!\defined('ABSPATH')) {
    exit;
}
// Pro editions implicitly opt in: suppress the onboarding consent section entirely.
if (\SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::is_pro()) {
    return;
}
$permissions_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('onboarding', 'permissions');
$terms_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('onboarding', 'terms');
$privacy_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('onboarding', 'privacy');
?>
<div class="sbc-consent-onboarding-ctn sb-fs">
	<label class="sbc-consent-checkbox">
		<input type="checkbox" id="sbc-consent-onboarding-checkbox" />
		<span class="sbc-consent-checkbox-mark"></span>
		<strong><?php 
echo \esc_html__('Help Improve Smash Balloon plugins', 'sb-common');
?></strong>
	</label>
	<p class="sbc-consent-checkbox-desc">
		<?php 
echo \esc_html__("Yes, I'd like to share basic usage data with Smash Balloon to help improve our products.", 'sb-common');
?>
	</p>
	<p class="sbc-consent-checkbox-links">
		<a href="<?php 
echo \esc_url($permissions_url);
?>" target="_blank" rel="noopener">
			<?php 
echo \esc_html__('What permissions are being granted?', 'sb-common');
?>
		</a>
		<a href="<?php 
echo \esc_url($terms_url);
?>" target="_blank" rel="noopener">
			<?php 
echo \esc_html__('Terms & Conditions', 'sb-common');
?>
		</a>
		<a href="<?php 
echo \esc_url($privacy_url);
?>" target="_blank" rel="noopener">
			<?php 
echo \esc_html__('Privacy', 'sb-common');
?>
		</a>
	</p>
</div>
<?php 
