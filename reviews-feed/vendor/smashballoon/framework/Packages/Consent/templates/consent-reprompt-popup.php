<?php

namespace SmashBalloon\Reviews\Vendor;

/**
 * Post-onboarding consent re-prompt modal.
 *
 * Shown to users who completed the onboarding wizard before the consent
 * checkbox existed (or who skipped it). Dismissing or accepting both
 * persist a flag in sbc_consent_modal_dismissed so this modal never
 * appears again for that user.
 *
 * Brand-link URLs default to Smash Balloon framework values; consumers
 * can override via the sbc_consent_*_url filters.
 *
 * @package Smashballoon\Framework\Packages\Consent
 */
if (!\defined('ABSPATH')) {
    exit;
}
$permissions_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('modal', 'permissions');
$terms_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('modal', 'terms');
$privacy_url = \SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Consent\ConsentManager::link_url('modal', 'privacy');
// Icon URL: package may live under wp-content/plugins/<plugin>/vendor/...
// Default to the Consent package's bundled balloon icon. Consumers can override.
$icon_url = \apply_filters('sbc_consent_modal_icon_url', \plugins_url('assets/img/balloon.svg', \dirname(__DIR__) . '/ConsentAssets.php'));
?>
<div id="sbc-consent-reprompt-overlay" class="sbc-consent-reprompt-overlay" data-active="false" tabindex="-1">
	<div class="sbc-consent-reprompt-popup" role="dialog" aria-modal="true" aria-labelledby="sbc-consent-reprompt-title">
		<div class="sbc-consent-reprompt-icon">
			<?php 
if ($icon_url) {
    ?>
				<img src="<?php 
    echo \esc_url($icon_url);
    ?>" alt="<?php 
    echo \esc_attr__('Smash Balloon', 'sb-common');
    ?>" />
			<?php 
}
?>
		</div>
		<div class="sbc-consent-reprompt-body">
			<div class="sbc-consent-reprompt-text">
				<div class="sbc-consent-reprompt-title-block">
					<p class="sbc-consent-reprompt-title" id="sbc-consent-reprompt-title" role="heading" aria-level="2"><?php 
echo \esc_html__("We're updating how data sharing works", 'sb-common');
?></p>
					<p><?php 
echo \esc_html__("Help us build the best Smash Balloon plugins for WordPress. By sharing anonymous usage data, you'll help our team prioritize the features that matter most.", 'sb-common');
?></p>
					<p><?php 
echo \esc_html__("In return, we'll keep you in the loop with product updates and helpful tips. You can change your mind anytime in Settings.", 'sb-common');
?></p>
				</div>
				<div class="sbc-consent-reprompt-links">
					<a href="<?php 
echo \esc_url($permissions_url);
?>" target="_blank" rel="noopener"><?php 
echo \esc_html__('What permissions are being granted?', 'sb-common');
?></a>
					<a href="<?php 
echo \esc_url($terms_url);
?>" target="_blank" rel="noopener"><?php 
echo \esc_html__('Terms & Conditions', 'sb-common');
?></a>
					<a href="<?php 
echo \esc_url($privacy_url);
?>" target="_blank" rel="noopener"><?php 
echo \esc_html__('Privacy', 'sb-common');
?></a>
				</div>
			</div>
			<div class="sbc-consent-reprompt-actions">
				<button type="button" id="sbc-consent-reprompt-skip" class="sbc-consent-reprompt-btn sbc-consent-reprompt-btn--cancel"><?php 
echo \esc_html__('Skip', 'sb-common');
?></button>
				<button type="button" id="sbc-consent-reprompt-accept" class="sbc-consent-reprompt-btn sbc-consent-reprompt-btn--submit">
					<svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<path d="M13.3 4.7L6 12L2.7 8.7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<span><?php 
echo \esc_html__('Accept & Continue', 'sb-common');
?></span>
				</button>
			</div>
		</div>
		<button type="button" id="sbc-consent-reprompt-close" class="sbc-consent-reprompt-close" aria-label="<?php 
echo \esc_attr__('Close', 'sb-common');
?>">
			<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M14 1.41L12.59 0L7 5.59L1.41 0L0 1.41L5.59 7L0 12.59L1.41 14L7 8.41L12.59 14L14 12.59L8.41 7L14 1.41Z" fill="#141B38"/>
			</svg>
		</button>
	</div>
</div>
<?php 
