<?php

/**
 * Zapier settings fragments (vanilla, no Vue/React).
 *
 * Consumed by two host layouts (see Zapier_Settings_Surface):
 *
 *  - Customizer "relocate" tabs, where the bundled script moves each fragment
 *    into a placeholder section's empty input column.
 *  - Slot hosts (a Vue settings app, a plain-PHP screen), where the script
 *    injects each fragment into a `data-sbc-zapier-slot="<fragment>"` element.
 *
 * All three API-key states are rendered up front and toggled by the script, so
 * re-injection after a remount never needs to re-template.
 *
 * Every button/input class comes from the normalized `classes` map so a host
 * can swap in its own settings-page classes and the surface looks native there.
 * Defaults keep the self-contained `.sbc-zapier-*` look for hosts that pass none.
 *
 * Expects $config (normalized by Zapier_Settings_Surface::config()).
 *
 * @package Smashballoon\Framework\Packages\Zapier
 */
namespace SmashBalloon\Reviews\Vendor\Smashballoon\Framework\Packages\Zapier;

if (!defined('ABSPATH')) {
    exit;
}
/** @var array $config */
$sbc_zapier_text = $config['i18n'];
$sbc_zapier_classes = $config['classes'];
// Field wrapper: the structural `.sbc-zapier-field` (owns spacing) plus any
// host extra (e.g. Instagram Feed's `.sb-form-field`, which is what its own
// input styling is scoped to).
$sbc_zapier_field = trim('sbc-zapier-field ' . $sbc_zapier_classes['field']);
// Build a button class from the base plus a variant modifier.
$sbc_zapier_btn = function ($variant) use ($sbc_zapier_classes) {
    return trim($sbc_zapier_classes['button'] . ' ' . $sbc_zapier_classes[$variant]);
};
// Read-only input, inside the host's input wrappers when it supplies them.
// $icon adds the valid-key check mark (shown by the script).
$sbc_zapier_input = function ($id, $value, $placeholder = '', $icon = \false) use ($sbc_zapier_classes) {
    $input = sprintf('<input type="text" id="%s" class="%s" readonly value="%s" placeholder="%s">', esc_attr($id), esc_attr($sbc_zapier_classes['input']), esc_attr($value), esc_attr($placeholder));
    if ($icon) {
        $input .= '<span class="' . esc_attr($sbc_zapier_classes['icon']) . '" data-sbc-zapier-valid hidden>' . '<svg width="13" height="10" viewBox="0 0 13 10" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" clip-rule="evenodd" d="M4.46447 6.88917L10.8284 0.525204L12.2426 1.93942L4.46447 9.71759L0.221826 5.47495L1.63604 4.06074L4.46447 6.88917Z"/></svg>' . '</span>';
    }
    if ('' !== $sbc_zapier_classes['input_inner']) {
        $input = '<div class="' . esc_attr($sbc_zapier_classes['input_inner']) . '">' . $input . '</div>';
    }
    return '<div class="' . esc_attr(trim('sbc-zapier-input-wrap ' . $sbc_zapier_classes['input_wrap'])) . '">' . $input . '</div>';
};
if (!empty($config['toggle'])) {
    // Elite only: no row, no upsell, nothing on sites without Zapier.
    if (empty($config['available'])) {
        return;
    }
    ?>
	<div data-sbc-zapier-fragment="row" class="<?php 
    echo esc_attr(trim('sbc-zapier ' . $sbc_zapier_classes['row']));
    ?>">
		<?php 
    if (!empty($config['row_label'])) {
        ?>
			<div class="<?php 
        echo esc_attr($sbc_zapier_classes['label']);
        ?>">
				<h3><?php 
        echo esc_html($sbc_zapier_text['toggleTitle']);
        ?></h3>
			</div>
		<?php 
    }
    ?>
		<div class="<?php 
    echo esc_attr($sbc_zapier_classes['body']);
    ?>">
			<div class="<?php 
    echo esc_attr(trim('sbc-zapier-toggle ' . $sbc_zapier_classes['toggle_wrap']));
    ?>">
				<?php 
    if ($sbc_zapier_classes['switch_wrap'] !== '') {
        ?><div class="<?php 
        echo esc_attr($sbc_zapier_classes['switch_wrap']);
        ?>"><?php 
    }
    ?>
				<label for="sbc-zapier-enabled" class="<?php 
    echo esc_attr($sbc_zapier_classes['switch']);
    ?>">
					<input type="checkbox" role="switch" id="sbc-zapier-enabled" data-sbc-zapier-toggle
						   aria-label="<?php 
    echo esc_attr($sbc_zapier_text['toggleTitle']);
    ?>"
						   <?php 
    checked(!empty($config['enabled']));
    ?>>
					<span class="<?php 
    echo esc_attr($sbc_zapier_classes['track']);
    ?>"><?php 
    if ($sbc_zapier_classes['indicator'] !== '') {
        ?><span class="<?php 
        echo esc_attr($sbc_zapier_classes['indicator']);
        ?>"></span><?php 
    }
    ?></span>
				</label>
				<?php 
    if ($sbc_zapier_classes['switch_wrap'] !== '') {
        ?></div><?php 
    }
    ?>
				<span class="<?php 
    echo esc_attr($sbc_zapier_classes['desc']);
    ?>">
					<?php 
    echo esc_html($sbc_zapier_text['toggleDesc']);
    ?>
					<?php 
    if (!empty($config['docs_url'])) {
        ?>
						<a class="sbc-zapier-info" href="<?php 
        echo esc_url($config['docs_url']);
        ?>" target="_blank" rel="noopener noreferrer"
						   aria-label="<?php 
        echo esc_attr($sbc_zapier_text['docsLink']);
        ?>" title="<?php 
        echo esc_attr($sbc_zapier_text['docsLink']);
        ?>">
							<svg aria-hidden="true" width="20" height="21" viewBox="0 0 20 21" fill="none"><path d="M9.1665 8H10.8332V6.33333H9.1665V8ZM9.99984 17.1667C6.32484 17.1667 3.33317 14.175 3.33317 10.5C3.33317 6.825 6.32484 3.83333 9.99984 3.83333C13.6748 3.83333 16.6665 6.825 16.6665 10.5C16.6665 14.175 13.6748 17.1667 9.99984 17.1667ZM9.99984 2.16666C8.90549 2.16666 7.82186 2.38221 6.81081 2.801C5.79976 3.21979 4.8811 3.83362 4.10728 4.60744C2.54448 6.17024 1.6665 8.28986 1.6665 10.5C1.6665 12.7101 2.54448 14.8298 4.10728 16.3926C4.8811 17.1664 5.79976 17.7802 6.81081 18.199C7.82186 18.6178 8.90549 18.8333 9.99984 18.8333C12.21 18.8333 14.3296 17.9554 15.8924 16.3926C17.4552 14.8298 18.3332 12.7101 18.3332 10.5C18.3332 9.40565 18.1176 8.32202 17.6988 7.31097C17.28 6.29992 16.6662 5.38126 15.8924 4.60744C15.1186 3.83362 14.1999 3.21979 13.1889 2.801C12.1778 2.38221 11.0942 2.16666 9.99984 2.16666ZM9.1665 14.6667H10.8332V9.66666H9.1665V14.6667Z" fill="currentColor"/></svg>
						</a>
					<?php 
    }
    ?>
				</span>
			</div>
			<div class="sbc-zapier-panel" data-sbc-zapier-panel<?php 
    echo empty($config['enabled']) ? ' hidden' : '';
    ?>>
	<?php 
}
if (empty($config['toggle']) && empty($config['available'])) {
    ?>
	<div data-sbc-zapier-fragment="upsell" class="sbc-zapier sbc-zapier-upsell">
		<p class="sbc-zapier-upsell-title"><?php 
    echo esc_html($sbc_zapier_text['upsellTitle']);
    ?></p>
		<p class="sbc-zapier-desc"><?php 
    echo esc_html($sbc_zapier_text['upsellBody']);
    ?></p>
		<?php 
    if (!empty($config['upsell_url'])) {
        ?>
			<a class="<?php 
        echo esc_attr($sbc_zapier_btn('upsell'));
        ?>"
			   href="<?php 
        echo esc_url($config['upsell_url']);
        ?>"
			   target="_blank" rel="noopener noreferrer">
				<?php 
        echo esc_html($sbc_zapier_text['upsellButton']);
        ?>
			</a>
		<?php 
    }
    ?>
	</div>
	<?php 
    return;
}
?>
<div data-sbc-zapier-fragment="connection" class="sbc-zapier">
	<?php 
if (!empty($config['render_headings']) && !empty($sbc_zapier_text['connectionHeading'])) {
    ?>
		<h4 class="sbc-zapier-heading"><?php 
    echo esc_html($sbc_zapier_text['connectionHeading']);
    ?></h4>
	<?php 
}
?>
	<?php 
if (!empty($sbc_zapier_text['intro'])) {
    ?>
		<p class="sbc-zapier-desc"><?php 
    echo esc_html($sbc_zapier_text['intro']);
    ?></p>
	<?php 
}
?>
	<?php 
if (!empty($config['disabled_count'])) {
    ?>
		<p class="sbc-zapier-desc sbc-zapier-warning" role="status"><?php 
    echo esc_html(sprintf($sbc_zapier_text['disabledZaps'], (int) $config['disabled_count']));
    ?></p>
	<?php 
}
?>

	<div class="<?php 
echo esc_attr($sbc_zapier_field);
?>">
		<label for="sbc-zapier-site-url"><?php 
echo esc_html($sbc_zapier_text['siteUrlLabel']);
?></label>
		<div class="sbc-zapier-row">
			<?php 
echo $sbc_zapier_input('sbc-zapier-site-url', $config['site_url']);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. 
?>
			<button type="button" class="<?php 
echo esc_attr($sbc_zapier_btn('secondary'));
?>" data-sbc-zapier-copy="site-url"
					data-label="<?php 
echo esc_attr($sbc_zapier_text['copy']);
?>"
					data-copied="<?php 
echo esc_attr($sbc_zapier_text['copied']);
?>">
				<?php 
echo esc_html($sbc_zapier_text['copy']);
?>
			</button>
		</div>
	</div>

	<?php 
// API key: behaves like the license key field. Key saved: masked + check + Revoke. No key: Generate. 
?>
	<div class="<?php 
echo esc_attr($sbc_zapier_field);
?>">
		<label for="sbc-zapier-api-key"><?php 
echo esc_html($sbc_zapier_text['apiKeyLabel']);
?></label>
		<div class="sbc-zapier-row">
			<?php 
echo $sbc_zapier_input('sbc-zapier-api-key', '', $sbc_zapier_text['noKey'], \true);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. 
?>
			<button type="button" class="<?php 
echo esc_attr($sbc_zapier_btn('secondary'));
?>"
					data-sbc-zapier-copy="api-key" hidden
					data-label="<?php 
echo esc_attr($sbc_zapier_text['copy']);
?>"
					data-copied="<?php 
echo esc_attr($sbc_zapier_text['copied']);
?>">
				<?php 
echo esc_html($sbc_zapier_text['copy']);
?>
			</button>
			<button type="button" class="<?php 
echo esc_attr($sbc_zapier_btn('primary'));
?>"
					data-sbc-zapier-action="generate">
				<?php 
echo esc_html($sbc_zapier_text['generate']);
?>
			</button>
			<button type="button" class="<?php 
echo esc_attr($sbc_zapier_btn('secondary'));
?>"
					data-sbc-zapier-action="revoke" hidden>
				<?php 
echo esc_html($sbc_zapier_text['revoke']);
?>
			</button>
		</div>
		<p class="sbc-zapier-warning" data-sbc-zapier-state="revealed" hidden><?php 
echo esc_html($sbc_zapier_text['showOnce']);
?></p>
	</div>
</div>
<?php 
if (!empty($config['toggle'])) {
    ?>
			</div>
		</div>
	</div>
<?php 
}
