<?php

namespace SmashBalloon\Reviews\Common\Badges;

use WP_Widget;

/**
 * "Trust Badge" as a native WP widget, for a classic theme's sidebar.
 *
 * The pitch names a native WP_Widget as one of the rock's two genuinely new
 * pieces; the plugin otherwise only registers Elementor widgets. An earlier
 * version of this class was removed on 2026-08-19 (488c499) because it was built
 * on the old model — four placement shapes plus a dozen styling attributes — and
 * duplicated the block under a second name. This one carries the saved-badge
 * model instead, and offers what the Gutenberg block offers: pick a badge, and
 * align it.
 *
 * Rendering goes through `[reviews-feed-badge]`, the same rail the block and any
 * author use, so a badge cannot look one way in a sidebar and another in a post.
 *
 * @since 2.12.0
 */
class SBR_Badge_Widget extends WP_Widget
{
	/**
	 * Alignments offered, matching the block's `supports.align`. Wide and full
	 * are deliberately absent there and absent here.
	 */
	const ALIGNMENTS = array( '', 'left', 'center', 'right' );

	public function __construct()
	{
		parent::__construct(
			'sbr_trust_badge',
			__('Trust Badge', 'reviews-feed'),
			array(
				'description'                 => __('Show a saved trust badge in a sidebar.', 'reviews-feed'),
				'customize_selective_refresh' => true,
			)
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array<string, mixed> $args     Sidebar wrappers.
	 * @param array<string, mixed> $instance Saved settings.
	 *
	 * @return void
	 */
	public function widget($args, $instance)
	{
		$id = isset($instance['badge_id']) ? absint($instance['badge_id']) : 0;

		if ($id < 1) {
			return;
		}

		$html = do_shortcode(sprintf('[reviews-feed-badge id="%d"]', $id));

		// An unresolvable badge renders nothing, and nothing means nothing — no
		// sidebar wrapper and no title either, or the sidebar keeps a heading
		// over an empty space.
		if (trim((string) $html) === '') {
			return;
		}

		$before = isset($args['before_widget']) && is_string($args['before_widget']) ? $args['before_widget'] : '';
		$after  = isset($args['after_widget']) && is_string($args['after_widget']) ? $args['after_widget'] : '';

		echo $before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-supplied wrapper.

		if (! empty($instance['title'])) {
			$title = apply_filters('widget_title', $instance['title'], $instance, $this->id_base);
			$b     = isset($args['before_title']) && is_string($args['before_title']) ? $args['before_title'] : '';
			$a     = isset($args['after_title']) && is_string($args['after_title']) ? $args['after_title'] : '';
			echo $b . esc_html((string) $title) . $a; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme wrappers, title escaped.
		}

		// The block gets its alignment class from get_block_wrapper_attributes();
		// a widget has no block wrapper, so emit the same two classes by hand.
		// Reusing the block's class rather than inventing one means the badge
		// inherits whatever the theme already applies to it.
		$align   = $this->sanitize_align(isset($instance['align']) ? $instance['align'] : '');
		$classes = 'wp-block-sbr-trust-badge' . ($align !== '' ? ' align' . $align : '');

		printf(
			'<div class="%s">%s</div>',
			esc_attr($classes),
			$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by SBR_Badge_Renderer.
		);

		echo $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-supplied wrapper.
	}

	/**
	 * Settings form.
	 *
	 * @param array<string, mixed> $instance Saved settings.
	 *
	 * @return string WP_Widget::form() declares a string return; core's own
	 *                default returns 'noform'. Nothing reads ours, but the
	 *                signature has to match or the override is incompatible.
	 */
	public function form($instance)
	{
		$title    = isset($instance['title']) && is_scalar($instance['title'])
			? (string) $instance['title']
			: '';
		$badge_id = isset($instance['badge_id']) ? absint($instance['badge_id']) : 0;
		$align    = $this->sanitize_align(isset($instance['align']) ? $instance['align'] : '');
		$badges   = self::badge_choices();
		?>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
				<?php esc_html_e('Title:', 'reviews-feed'); ?>
			</label>
			<input class="widefat" type="text"
				id="<?php echo esc_attr($this->get_field_id('title')); ?>"
				name="<?php echo esc_attr($this->get_field_name('title')); ?>"
				value="<?php echo esc_attr($title); ?>">
		</p>

		<p>
			<label for="<?php echo esc_attr($this->get_field_id('badge_id')); ?>">
				<?php esc_html_e('Badge:', 'reviews-feed'); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr($this->get_field_id('badge_id')); ?>"
				name="<?php echo esc_attr($this->get_field_name('badge_id')); ?>">
				<option value="0"><?php esc_html_e('— Select a badge —', 'reviews-feed'); ?></option>
				<?php foreach ($badges as $badge) : ?>
					<option value="<?php echo esc_attr((string) $badge['id']); ?>"
						<?php selected($badge_id, $badge['id']); ?>>
						<?php echo esc_html($badge['name']); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if (empty($badges)) : ?>
				<small><?php esc_html_e('No badges yet. Create one under Reviews Feed → Trust Badges.', 'reviews-feed'); ?></small>
			<?php elseif (self::$choices_total > count($badges)) : ?>
				<small>
					<?php
					printf(
						/* translators: 1: how many badges are listed, 2: how many exist in total. */
						esc_html__('Showing the %1$d most recently edited of %2$d badges. Search for the one you need under Reviews Feed → Trust Badges and use its shortcode if it is not listed.', 'reviews-feed'),
						(int) count($badges),
						(int) self::$choices_total
					);
					?>
				</small>
			<?php else : ?>
				<small><?php esc_html_e('Style, theme and sources are set in the badge customizer.', 'reviews-feed'); ?></small>
			<?php endif; ?>
		</p>

		<p>
			<label for="<?php echo esc_attr($this->get_field_id('align')); ?>">
				<?php esc_html_e('Alignment:', 'reviews-feed'); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr($this->get_field_id('align')); ?>"
				name="<?php echo esc_attr($this->get_field_name('align')); ?>">
				<option value="" <?php selected($align, ''); ?>><?php esc_html_e('None', 'reviews-feed'); ?></option>
				<option value="left" <?php selected($align, 'left'); ?>><?php esc_html_e('Left', 'reviews-feed'); ?></option>
				<option value="center" <?php selected($align, 'center'); ?>><?php esc_html_e('Center', 'reviews-feed'); ?></option>
				<option value="right" <?php selected($align, 'right'); ?>><?php esc_html_e('Right', 'reviews-feed'); ?></option>
			</select>
		</p>
		<?php

		return '';
	}

	/**
	 * Sanitize on save.
	 *
	 * @param array<string, mixed> $new_instance Submitted values.
	 * @param array<string, mixed> $old_instance Previously saved values.
	 *
	 * @return array<string, mixed>
	 */
	public function update($new_instance, $old_instance)
	{
		return array(
			'title'    => isset($new_instance['title']) && is_scalar($new_instance['title'])
				? sanitize_text_field((string) $new_instance['title'])
				: '',
			'badge_id' => isset($new_instance['badge_id']) ? absint($new_instance['badge_id']) : 0,
			'align'    => $this->sanitize_align(isset($new_instance['align']) ? $new_instance['align'] : ''),
		);
	}

	/**
	 * An alignment we recognise, or none. An allowlist rather than an escape,
	 * because the value reaches a class attribute.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string
	 */
	protected function sanitize_align($value)
	{
		$value = is_scalar($value) ? (string) $value : '';

		return in_array($value, self::ALIGNMENTS, true) ? $value : '';
	}

	/** The store's per_page ceiling; asking for more returns this many anyway. */
	/**
	 * Kept as an alias of the store's, not a second number.
	 *
	 * The literal used to live here AND as a bare 100 in SBR_Badge_Service; the
	 * cap now belongs to the store that enforces it. Retained rather than removed
	 * because it is referenced in the form's "showing N of M" copy.
	 */
	const CHOICE_LIMIT = SBR_Trust_Badge_Store::CHOICE_LIMIT;

	/**
	 * How many badges exist, as of the last badge_choices() call.
	 *
	 * @var int
	 */
	private static $choices_total = 0;

		/**
	 * Badge id/name pairs for the picker. Same source and same ceiling as the
	 * block's own picker.
	 *
	 * @return array<int, array{id: int, name: string}>
	 *
	 * @phpstan-return list<array{id: int, name: string}>
	 */
	protected static function badge_choices()
	{
		// One implementation, on the store — this and SBR_Badge_Service each had a
		// copy and they had already drifted.
		$offered = SBR_Trust_Badge_Store::choices(self::CHOICE_LIMIT);

		// The store's own cap, so a site above it silently lost the rest of its
		// badges from this dropdown. Recorded rather than dropped: the form says
		// so, and an author who cannot find a badge is told why instead of
		// concluding it is gone.
		self::$choices_total = $offered['total'];

		return $offered['choices'];
	}
}
