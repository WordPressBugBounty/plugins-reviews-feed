<?php
/**
 * AliExpress extras — additive per-provider markup (SMASH-782 Phase 2).
 *
 * Renders per prototype AliExpressCard.jsx (real-data only — no
 * demo/static placeholders):
 *   sb-item-translated "Translated from original"     — in text-aliexpress.php
 *   sb-item-followup                                   — when metadata.followup
 *     sb-item-followup-header (label + date)
 *     sb-item-followup-text
 *   sb-item-variants > sb-item-variants-pill          — when metadata.item_spec
 *
 * NOTE: The buyer-country flag is rendered INLINE inside .sb-item-author-name
 * by templates/frontend/pro/post-elements/author.php.
 *
 * @since SMASH-782 Phase 2
 */

if (!defined('ABSPATH')) {
	exit;
}

// NOTE: "Translated from original" indicator is rendered BEFORE the text
// by templates/frontend/post-elements/text-aliexpress.php — not here — to
// match the prototype AliExpressCard.jsx layout (indicator above the
// translated paragraph, not below).

// Parse both sections, then render follow-up BEFORE variants to match the
// prototype AliExpressCard.jsx order.
$item_spec = isset($post['metadata']['item_spec']) ? trim((string) $post['metadata']['item_spec']) : '';
$variants  = [];
if ($item_spec !== '') {
	preg_match_all('/([A-Za-z][A-Za-z0-9 _-]*):([^\s][^\s]*(?:\s+[^\s:]+)*?)(?=\s+[A-Za-z][A-Za-z0-9 _-]*:|$)/', $item_spec, $matches);
	$variants = $matches[0] ?? [];
}

$followup = isset($post['metadata']['followup']) && is_array($post['metadata']['followup'])
	? $post['metadata']['followup']
	: null;
$has_followup = $followup !== null && (! empty($followup['text']) || ! empty($followup['date']));
?>
<?php if ($has_followup) : ?>
<div class="sb-item-followup sbr-review-horizontal-element">
	<div class="sb-item-followup-header">
		<span class="sb-item-followup-label"><?php echo esc_html__('Follow-up Review', 'reviews-feed'); ?></span>
		<?php if (!empty($followup['date'])) : ?>
			<span class="sb-item-followup-date"><?php echo esc_html($followup['date']); ?></span>
		<?php endif; ?>
	</div>
	<?php if (!empty($followup['text'])) : ?>
		<div class="sb-item-followup-text"><?php echo esc_html($followup['text']); ?></div>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($variants)) : ?>
<div class="sb-item-variants sb-fs sbr-review-horizontal-element">
	<?php foreach ($variants as $variant) :
		$variant_pretty = preg_replace('/^([^:]+):\s*/', '$1: ', trim($variant));
	?>
	<span class="sb-item-variants-pill"><?php echo esc_html($variant_pretty); ?></span>
	<?php endforeach; ?>
</div>
<?php endif; ?>
