<?php

/**
 * Reviews Feed — saved-badge footer line (SBR-163)
 *
 * One editable line, shared by chip, card and medal. Author-set, so a badge
 * that silently reworded itself per style would be a bug rather than a feature
 * (the design rock makes the same call, in the same words).
 *
 * Renders nothing when there is no attribution to force, no text to show and
 * nobody asking for one — the empty case is three conditions now, not one.
 *
 * @version 1.0 Reviews Feed by Smash Balloon
 *
 * @var array $sbr_badge Prepared view data from SBR_Badge_Renderer::prepare().
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

$sbr_badge_foot_text = isset($sbr_badge['footer_text']) ? trim((string) $sbr_badge['footer_text']) : '';
$sbr_badge_foot_url  = isset($sbr_badge['footer_url']) ? (string) $sbr_badge['footer_url'] : '';

// Three candidates, one strip. In order:
//
//   the tier forces the attribution  -> attribution, whatever the author wrote
//   the author wrote something       -> the author's line
//   a caller opted in                -> attribution
//   none of the above                -> nothing at all
//
// `powered_by_forced` and not `show_powered_by` for the first test: the latter
// is ALSO true when a caller opted in, and a saved badge stores
// powered_by => true by default — so reading it there made the attribution win
// on every Pro Plus badge and the footer text an author typed never appeared.
//
// The opt-in case reaches this file through an inline shortcode that passes
// powered_by="true" and no footer_text, which without the third test rendered
// an empty strip.
$sbr_badge_attribution = ! empty($sbr_badge['powered_by_forced'])
	|| ( $sbr_badge_foot_text === '' && ! empty($sbr_badge['show_powered_by']) );

if ($sbr_badge_attribution) {
	?>
	<div class="sbr-tb-foot">
		<?php
		// Unlinked when there is no URL. tb-view-defaults.php defaults this to
		// '' — the renderer supplies the real address — and esc_url('') gives
		// href="", which resolves to the current page: a link that reloads
		// whatever the badge is embedded in. The name is still true without it.
		$sbr_badge_pb_url = isset($sbr_badge['powered_by_url']) ? (string) $sbr_badge['powered_by_url'] : '';

		printf(
			/* translators: %s: linked "Smash Balloon" company name */
			esc_html__('Powered by %s', 'reviews-feed'),
			$sbr_badge_pb_url !== ''
				? '<a href="' . esc_url($sbr_badge_pb_url) . '" target="_blank" rel="nofollow noopener noreferrer">'
					. esc_html__('Smash Balloon', 'reviews-feed') . '</a>'
				: esc_html__('Smash Balloon', 'reviews-feed')
		);
		?>
	</div>
	<?php
} elseif ($sbr_badge_foot_text !== '') {
	?>
	<div class="sbr-tb-foot">
		<?php
		if ($sbr_badge_foot_url !== '') {
			// nofollow because this is an author-controlled outbound link on
			// every page the badge sits on — exactly the shape a link scheme
			// takes. noopener because target=_blank without it hands the opener
			// to the destination.
			?>
			<a href="<?php echo esc_url($sbr_badge_foot_url); ?>" target="_blank" rel="noopener noreferrer nofollow">
				<?php echo esc_html($sbr_badge_foot_text); ?>
			</a>
			<?php
		} else {
			echo esc_html($sbr_badge_foot_text);
		}
		?>
	</div>
	<?php
}
