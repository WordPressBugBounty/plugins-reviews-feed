<?php

namespace SmashBalloon\Reviews\Common;

class DisplayElements {
	public static function get_style_for_setting($feed_id, $item, $settings)
	{
		$return = '#' . sbr_container_id($feed_id);
		$additional = '';
		switch ($item) {
			case '.sb-post-item':
				$additional = ' ' . self::sb_post_item($settings);
				break;
			case '.sb-post-item-wrap':
				$additional = ' ' . self::sb_post_item_wrap($settings);
				break;
			case '.sb-feed-header-heading':
				$additional = ' ' . self::sb_feed_header_heading($settings);
				break;
			case '.sb-feed-header-btn':
				$additional = ' ' . self::sb_feed_header_btn($settings);
				break;
			case '.sb-feed-header-btn:hover':
				$additional = ' ' . self::sb_feed_header_btn_hover($settings);
				break;
			case '.sb-feed-header-rating':
				$additional = ' ' . self::sb_feed_header_rating($settings);
				break;
			case '.sb-feed-header-rating-subtext':
				$additional = ' ' . self::sb_feed_header_rating_subtext($settings);
				break;
			case '.sb-feed-header-rating-icons':
				$additional = ' ' . self::sb_feed_header_rating_icons($settings);
				break;
			case '.sb-feed-header-bottom':
				$additional = ' ' . self::sb_feed_header_bottom($settings);
				break;
			case '.sb-feed-header':
				$additional = ' ' . self::sb_feed_header($settings);
				break;
			case '.sb-item-rating':
				$additional = ' ' . self::sb_item_rating($settings);
				break;
			case '.sb-item-text':
				$additional = ' ' . self::sb_item_text($settings);
				break;
			case '.sb-item-author-name':
				$additional = ' ' . self::sb_item_author_name($settings);
				break;
			case '.sb-item-author-date':
				$additional = ' ' . self::sb_item_author_date($settings);
				break;
			case '.sb-item-author-img':
				$additional = ' ' . self::sb_item_author_img($settings);
				break;
			case '.sb-item-author-ctn':
				$additional = ' ' . self::sb_item_author_ctn($settings);
				break;
			case '.sb-load-button':
				$additional = ' ' . self::sb_load_button($settings);
				break;
			case '.sb-load-button:hover':
				$additional = ' ' . self::sb_load_button_hover($settings);
				break;
		}

		if (! empty(trim($additional))) {
			return $return . $additional . "\n";
		}

		return '';
	}

	public static function sb_post_item($settings)
	{
		$return = '.sb-post-item {';
		$extra = '';
		$props = array();
		if (! empty($settings['verticalSpacing'])) {
			$props[] = 'margin-bottom: ' . (int) $settings['verticalSpacing'] . 'px;';
		}
		if (! empty($settings['postStyle']) && $settings['postStyle']  === 'boxed') {
			if (! empty($settings['boxedBoxShadow']) && ! empty($settings['boxedBoxShadow']['enabled'])) {
				$props[] = 'box-shadow: ' . (int) $settings['boxedBoxShadow']['x'] . 'px ' . (int) $settings['boxedBoxShadow']['y'] . 'px ' . (int) $settings['boxedBoxShadow']['blur'] . 'px ' . (int) $settings['boxedBoxShadow']['spread'] . 'px ' . self::sanitize_color($settings['boxedBoxShadow']['color']) . ';';
			}
			if (! empty($settings['boxedBorderRadius']) && ! empty($settings['boxedBorderRadius']['enabled'])) {
				$props[] = 'border-radius: ' . (int) $settings['boxedBorderRadius']['radius'] . 'px;';
			}
			if (! empty($settings['boxedBackgroundColor'])) {
				$props[] = 'background: ' . self::sanitize_color($settings['boxedBackgroundColor']) . ';';
			}
			if (! empty($settings['boxedBackgroundColor'])) {
				$props[] = 'background: ' . self::sanitize_color($settings['boxedBackgroundColor']) . ';';
			}
			if (! empty($settings['postStroke']) && ! empty($settings['postStroke']['enabled'])) {
				$extra  .= ' [data-post-style="boxed"] .sb-post-item { border: ' . (int) $settings['postStroke']['thickness'] . 'px solid ' . self::sanitize_color($settings['postStroke']['color']) . '; }' ;
			}
		} else {
			if (! empty($settings['postStroke']) && ! empty($settings['postStroke']['enabled'])) {
				$extra  .= ' [data-post-style="regular"] .sb-post-item { border-bottom: ' . (int) $settings['postStroke']['thickness'] . 'px solid ' . self::sanitize_color($settings['postStroke']['color']) . '; }' ;
			}
		}

		if (! empty($settings['postPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['postPadding']);
			$props[] = 'padding: ' . $prop_string;
		} elseif (! empty($settings['layout']) && $settings['layout'] !== 'list') {
			if (! empty($settings['horizontalSpacing'])) {
				$prop_string = floor($settings['horizontalSpacing'] / 2) . 'px';

				$props[] = 'padding: 0 ' . $prop_string . ' 0 ' . $prop_string;
			}
		}


		$return .= implode('; ', $props) . '}' . $extra;
		return $return;
	}

	public static function sb_post_item_wrap($settings)
	{
		$return = '.sb-post-item-wrap {';

		$props = array();
		if (! empty($settings['postStyle']) && $settings['postStyle']  === 'boxed') {
			$props[] = 'padding: 0 5px';
		} else {
			return '';
		}


		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_heading($settings)
	{
		$return = '.sb-feed-header-heading {';
		$props = array();
		if (! empty($settings['headingFont'])) {
			if (! empty($settings['headingFont']['weight'])) {
				$props[] = 'font-weight: ' . (int) $settings['headingFont']['weight'];
			}
			if (! empty($settings['headingFont']['size'])) {
				$props[] = 'font-size: ' . (int) $settings['headingFont']['size'] . 'px';
			}
			if (! empty($settings['headingFont']['height']) && $settings['headingFont']['height'] !== '100%') {
				$props[] = 'height: ' . wp_strip_all_tags($settings['headingFont']['height']);
			}
		}
		if (! empty($settings['headingColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['headingColor']);
		}
		if (! empty($settings['headerHeadingPadding'])) {
			$props[] = 'padding: ' . (int)$settings['headerHeadingPadding'] . 'px';
		}
		if (! empty($settings['headerHeadingMargin']['bottom'])) {
			$props[] = 'margin-bottom: ' . (int) $settings['headerHeadingMargin']['bottom'] . 'px;';
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_btn($settings)
	{
		$return = '.sb-feed-header-btn {';

		$props = array();
		if (! empty($settings['headerButtonFont'])) {
			$this_setting = $settings['headerButtonFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
			if (! empty($settings['headerButtonColor'])) {
				$props[] = 'color: ' . self::sanitize_color($settings['headerButtonColor']);
			}
			if (! empty($settings['headerButtonBg'])) {
				$props[] = 'background-color: ' . self::sanitize_color($settings['headerButtonBg']);
			}
			if (! empty($settings['headerButtonPadding'])) {
				$prop_string = self::padding_and_margin_helper($settings['headerButtonPadding']);

				$props[] = 'padding: ' . $prop_string;
			}
			if (! empty($settings['headerButtonMargin'])) {
				$prop_string = self::padding_and_margin_helper($settings['headerButtonMargin']);

				$props[] = 'margin: ' . $prop_string;
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_btn_hover($settings)
	{
		$return = '.sb-feed-header-btn:hover {';

		$props = array();
		if (! empty($settings['headerButtonFont'])) {
			if (! empty($settings['headerButtonHoverColor'])) {
				$props[] = 'color: ' . self::sanitize_color($settings['headerButtonHoverColor']);
			}
			if (! empty($settings['headerButtonHoverBg'])) {
				$props[] = 'background-color: ' . self::sanitize_color($settings['headerButtonHoverBg']);
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_rating($settings)
	{
		$return = '.sb-feed-header-rating {';

		$props = array();
		if (! empty($settings['headerAvReviewColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['headerAvReviewColor']);
		}
		if (! empty($settings['headerAvReviewFont'])) {
			$this_setting = $settings['headerAvReviewFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_rating_subtext($settings)
	{
		$return = '.sb-feed-header-rating-subtext {';

		$props = array();
		if (! empty($settings['headerAvReviewSubtextColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['headerAvReviewSubtextColor']);
		}
		if (! empty($settings['headerAvSubtextReviewFont'])) {
			$this_setting = $settings['headerAvSubtextReviewFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_rating_icons($settings)
	{
		$return = '.sb-feed-header-rating-icons {';

		$props = array();
		if (! empty($settings['headerAvReviewIconColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['headerAvReviewIconColor']);
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header_bottom($settings)
	{
		$return = '.sb-feed-header-bottom {';

		$props = array();
		if (! empty($settings['headerAvReviewPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['headerAvReviewPadding']);

			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['headerAvReviewMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['headerAvReviewMargin']);

			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_feed_header($settings)
	{
		$return = '.sb-feed-header {';

		$props = array();
		if (! empty($settings['headerPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['headerPadding']);

			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['headerMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['headerMargin']);

			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}
	public static function sb_item_rating($settings)
	{
		$return = '.sb-item-rating {';

		$props = array();
		if (! empty($settings['ratingIconColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['ratingIconColor']);
		}
		if (! empty($settings['ratingIconPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['ratingIconPadding']);
			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['ratingIconMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['ratingIconMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_item_text($settings)
	{
		$return = '.sb-item-text {';

		$props = array();
		if (! empty($settings['paragraphColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['paragraphColor']);
		}
		if (! empty($settings['paragraphFont'])) {
			$this_setting = $settings['paragraphFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
		}
		if (! empty($settings['paragraphPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['paragraphPadding']);
			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['paragraphMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['paragraphMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_item_author_name($settings)
	{
		$return = '.sb-item-author-name {';

		$props = array();
		if (! empty($settings['authorNameColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['authorNameColor']);
		}
		if (! empty($settings['authorNameFont'])) {
			$this_setting = $settings['authorNameFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
		}
		if (! empty($settings['authorNamePadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['authorNamePadding']);
			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['authorNameMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['authorNameMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}
	public static function sb_item_author_date($settings)
	{
		$return = '.sb-item-author-date {';

		$props = array();
		if (! empty($settings['dateColor'])) {
			$props[] = 'color: ' . self::sanitize_color($settings['dateColor']);
		}
		if (! empty($settings['dateFont'])) {
			$this_setting = $settings['dateFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
		}
		if (! empty($settings['datePadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['datePadding']);
			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['dateMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['dateMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_item_author_img($settings)
	{
		$return = '.sb-item-author-img {';

		$props = array();
		if (! empty($settings['authorImageMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['authorImageMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';

		if (! empty($settings['authorImageBorderRadius'])) {
			$return .= '.sb-item-author-img img {';

			$return .= 'border-radius: ' . (int) $settings['authorImageBorderRadius'] . 'px;';
			$return .= '}';
		}
		return $return;
	}

	public static function sb_item_author_ctn($settings)
	{
		$return = '.sb-item-author-ctn {';

		$props = array();
		if (! empty($settings['authorPadding'])) {
			$prop_string = self::padding_and_margin_helper($settings['authorPadding']);
			$props[] = 'padding: ' . $prop_string;
		}
		if (! empty($settings['authorMargin'])) {
			$prop_string = self::padding_and_margin_helper($settings['authorMargin']);
			$props[] = 'margin: ' . $prop_string;
		}
		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_load_button($settings)
	{
		$return = '.sb-load-button {';

		$props = array();
		if (! empty($settings['loadButtonFont'])) {
			$this_setting = $settings['loadButtonFont'];
			if (! empty($this_setting['weight'])) {
				$props[] = 'font-weight: ' . (int) $this_setting['weight'];
			}
			if (! empty($this_setting['size'])) {
				$props[] = 'font-size: ' . (int) $this_setting['size'] . 'px';
			}
			if (! empty($this_setting['height'])) {
				$props[] = 'line-height: ' . wp_strip_all_tags($this_setting['height']);
			}
			if (! empty($settings['loadButtonColor'])) {
				$props[] = 'color: ' . self::sanitize_color($settings['loadButtonColor']);
			}
			if (! empty($settings['loadButtonBg'])) {
				$props[] = 'background-color: ' . self::sanitize_color($settings['loadButtonBg']);
			}
			if (! empty($settings['loadButtonPadding'])) {
				$prop_string = self::padding_and_margin_helper($settings['loadButtonPadding']);

				$props[] = 'padding: ' . $prop_string;
			}
			if (! empty($settings['loadButtonMargin'])) {
				$prop_string = self::padding_and_margin_helper($settings['loadButtonMargin']);

				$props[] = 'margin: ' . $prop_string;
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function sb_load_button_hover($settings)
	{
		$return = '.sb-load-button:hover {';

		$props = array();
		if (! empty($settings['loadButtonFont'])) {
			if (! empty($settings['loadButtonHoverColor'])) {
				$props[] = 'color: ' . self::sanitize_color($settings['loadButtonHoverColor']);
			}
			if (! empty($settings['loadButtonHoverBg'])) {
				$props[] = 'background-color: ' . self::sanitize_color($settings['loadButtonHoverBg']);
			}
		}

		$return .= implode('; ', $props) . '}';
		return $return;
	}

	public static function padding_and_margin_helper($setting)
	{
		$prop_string = '';

		$keys = array( 'top', 'right', 'bottom', 'left' );
		foreach ($keys as $key) {
			if (! empty($setting[ $key ])) {
				$prop_string .= intval($setting[ $key ]) . 'px ';
			} else {
				$prop_string .= '0 ';
			}
		}
		return $prop_string;
	}

	public static function sanitize_color($color)
	{
		if (empty($color) || is_array($color)) {
			return 'rgba(0,0,0,0)';
		}

		if (false === strpos($color, 'rgba')) {
			return sanitize_hex_color($color);
		}

		$color = str_replace(' ', '', $color);
		sscanf($color, 'rgba(%d,%d,%d,%f)', $red, $green, $blue, $alpha);
		return 'rgba(' . $red . ',' . $green . ',' . $blue . ',' . $alpha . ')';
	}

	public static function get_star_icon()
	{
		// Mirrors customizer/sb-common/sb-customizer/assets/icons/star.svg so
		// the frontend feed-header stars and the customizer admin preview
		// render the same glyph at the same size. Previous version used the
		// `popup-star.svg` path inside a 24x24 viewBox — the glyph only
		// filled the inner ~20 units, so at width:18px the visible star was
		// only ~15px (17% smaller than the customizer's 18px star). Path +
		// viewBox below match star.svg exactly so width:18px renders 18px.
		return '<svg viewBox="0 0 20 20" fill="currentColor">
					<path d="M10.0001 16.0074L14.8499 18.9407C15.7381 19.4783 16.8249 18.6836 16.5912 17.6786L15.3057 12.1626L19.5946 8.44634C20.3776 7.76853 19.9569 6.48303 18.9285 6.40122L13.2839 5.92208L11.0752 0.709949C10.6779 -0.236649 9.32225 -0.236649 8.92491 0.709949L6.71618 5.91039L1.07165 6.38954C0.043251 6.47134 -0.377459 7.75685 0.405529 8.43466L4.69444 12.1509L3.40893 17.6669C3.17521 18.6719 4.26204 19.4666 5.15021 18.929L10.0001 16.0074V16.0074Z"/>
				</svg>';
	}

	/**
	 * A provider slug as it appears in an ASSET name and a CSS class.
	 *
	 * The data slug keeps its dot — `wordpress.org` is what provider_label() matches
	 * Util::get_providers() on. Files and CSS cannot: the shipped artwork is
	 * `wordpressorg-mono.svg` / `-lockup.svg`, and `.sbr-tb-lockup-wordpress.org`
	 * parses as two classes, so the height rule would never match.
	 *
	 * @param string $provider Provider slug, dotted or not.
	 *
	 * @return string Slug safe for a file name and a class name.
	 */
	public static function provider_asset_slug($provider)
	{
		return str_replace('.', '', sanitize_file_name((string) $provider));
	}

	/**
	 * Cache of icon files already read this request, keyed by relative path.
	 *
	 * @var array<string, string>
	 */
	private static $icon_file_cache = array();

	/**
	 * An icon file's contents, read at most once per request.
	 *
	 * Caches the FILE, never a getter's return value: get_provider_mono_icon()
	 * rewrites mask ids per call so two badges on one page do not collide, and
	 * caching its output would hand out the same ids again — the exact bug its
	 * uniquifying step exists to prevent.
	 *
	 * @param string $relative Path under the plugin directory.
	 *
	 * @return string Contents, or '' when the file is missing or unreadable.
	 */
	private static function read_icon_file($relative)
	{
		if (isset(self::$icon_file_cache[$relative])) {
			return self::$icon_file_cache[$relative];
		}

		$path = SBR_PLUGIN_DIR . $relative;

		if (! file_exists($path) || ! is_readable($path)) {
			self::$icon_file_cache[$relative] = '';

			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled local SVG.
		$svg = file_get_contents($path);

		$contents = is_string($svg) ? $svg : '';

		self::$icon_file_cache[$relative] = $contents;

		// The local, not the cache slot just written: re-reading the offset is
		// what the analyser cannot prove, and it is the same value.
		return $contents;
	}

	/**
	 * Make every element id in an inlined SVG unique to this render.
	 *
	 * These files are echoed inline, so two marks for one provider on a page — or
	 * a badge plus the block editor's preview of it — emit every id twice, and a
	 * duplicate id resolves to the first occurrence. Identical today, wrong the
	 * moment one copy's artwork is edited, and invalid markup regardless.
	 *
	 * Prefix-agnostic on purpose. The first version rewrote only `sbr-mono-`
	 * (masks) and then only `fl-` (lockups), and the review found a third family
	 * it therefore missed: the provider icons carry exporter ids
	 * (`clip0_5673_10737`, `pattern0_5673_10731`, `Layer_x0020_1`, `Page-1`) in
	 * eight of the fifteen files, six of them REFERENCED by `url(#…)` — which is
	 * how the embedded bitmaps in the AliExpress and Formidable marks are drawn.
	 * Matching the id syntax instead of a naming convention cannot miss the next
	 * family.
	 *
	 * References are rewritten with the definitions, so a clip-path or pattern
	 * still resolves inside its own copy.
	 *
	 * @param string $svg  SVG markup.
	 * @param string $kind Counter to advance, so two families do not collide.
	 *
	 * @return string
	 */
	protected static function uniquify_ids($svg, $kind)
	{
		if ($svg === '' || strpos($svg, 'id="') === false) {
			return $svg;
		}

		if (! isset(self::$inline_svg_counts[ $kind ])) {
			self::$inline_svg_counts[ $kind ] = 0;
		}

		$suffix = '-sbr' . $kind . ( ++self::$inline_svg_counts[ $kind ] );

		preg_match_all('/id="([^"]+)"/', $svg, $matches);

		foreach (array_unique($matches[1]) as $id) {
			// Every reference form, not only the ones the committed files use:
			// two of them write url(#x) and xlink:href="#x" (which the plain
			// href match reaches as a substring), and a quoted url('#x') from a
			// future export would otherwise be left pointing at the old id.
			$svg = str_replace(
				array(
					'id="' . $id . '"',
					'url(#' . $id . ')',
					"url('#" . $id . "')",
					'url("#' . $id . '")',
					'href="#' . $id . '"',
				),
				array(
					'id="' . $id . $suffix . '"',
					'url(#' . $id . $suffix . ')',
					"url('#" . $id . $suffix . "')",
					'url("#' . $id . $suffix . '")',
					'href="#' . $id . $suffix . '"',
				),
				$svg
			);
		}

		return $svg;
	}

	/**
	 * Get provider icon as inline SVG
	 *
	 * Reads the provider SVG file and returns its content for inline rendering.
	 * This matches the admin preview which uses inline SVGs via SbUtils.printIcon().
	 *
	 * @since 2.5.0
	 * @param string $provider Provider name (e.g., 'google', 'yelp', 'edd').
	 * @return string Inline SVG markup or empty string if file not found.
	 */
	public static function get_provider_icon($provider)
	{
		// Sanitize provider name to prevent path traversal, and fold the dot the
		// asset names do not carry.
		$provider = self::provider_asset_slug($provider);

		$svg_content = self::read_icon_file('assets/icons/' . $provider . '-provider.svg');

		// Fallback to the google mark when the provider has no artwork. Kept as a
		// second read rather than folded into read_icon_file(): the fallback is
		// this getter's policy, and the mono and lockup getters deliberately
		// return '' instead of substituting someone else's brand.
		if ($svg_content === '') {
			$svg_content = self::read_icon_file('assets/icons/google-provider.svg');
		}

		return self::uniquify_ids($svg_content, 'icon');
	}

	/**
	 * Whether a provider has a mark of its own.
	 *
	 * get_provider_icon() falls back to the Google mark for an unknown provider,
	 * which is the right call inside the feed — a review row needs an icon and a
	 * generic one beats a hole. On a badge it is not: the logo row is a claim
	 * about which platforms the rating comes from, so drawing Google for a
	 * provider that has no mark would state something untrue. Callers that make
	 * a claim ask this first; callers that just need a glyph do not.
	 *
	 * @since 2.10.0
	 *
	 * @param string $provider Provider slug, e.g. 'google', 'yelp'.
	 *
	 * @return bool
	 */
	public static function has_provider_icon($provider)
	{
		$provider = self::provider_asset_slug($provider);

		if ($provider === '') {
			return false;
		}

		$icon_path = SBR_PLUGIN_DIR . 'assets/icons/' . $provider . '-provider.svg';

		return file_exists($icon_path) && is_readable($icon_path);
	}

	/**
	 * The single-ink version of a provider's mark.
	 *
	 * Recolouring the brand mark does not work, and the reason is structural: a
	 * mark like Facebook or Booking.com is a coloured plate with a lighter glyph
	 * knocked out of it. Flatten every shape to one ink and the glyph merges
	 * into the plate — a solid blob where the logo was. Measured on our own
	 * fifteen: six carry a plate, and CSS cannot tell figure from ground.
	 *
	 * So mono is its own artwork: one silhouette per provider, or — where the
	 * knock-out is the whole point — a `<mask>` that keeps it as a hole. The
	 * design reached the same conclusion and ships masks for exactly the two
	 * marks that broke here, which is the strongest evidence the split is real
	 * rather than a workaround.
	 *
	 * Eleven providers have one. The rest keep the CSS recolour, which is a
	 * worse result but only for marks with no plate to ruin.
	 *
	 * @since 2.10.0
	 *
	 * @param string $provider Provider slug, e.g. 'google', 'yelp'.
	 *
	 * @return string Inline SVG markup, or '' when there is no mono variant.
	 */
	public static function get_provider_mono_icon($provider)
	{
		$provider = self::provider_asset_slug($provider);

		if ($provider === '') {
			return '';
		}

		$svg = self::read_icon_file('assets/icons/' . $provider . '-mono.svg');

		if ($svg === '') {
			return '';
		}

		// Two of these carry a <mask id>. Two badges on one page would emit the
		// same id twice, and a duplicate id resolves to the first occurrence —
		// identical here, so it renders correctly today and would stop the moment
		// the artwork is edited on one of them.
		//
		// Through the shared rewriter rather than a prefix match on `sbr-mono-`:
		// that gate silently skipped any mono mark whose ids were not named to
		// our convention, which is the one case nobody would notice.
		return self::uniquify_ids($svg, 'mono');
	}

	/**
	 * Whether a provider has single-ink artwork of its own.
	 *
	 * @since 2.10.0
	 *
	 * @param string $provider Provider slug.
	 *
	 * @return bool
	 */
	public static function has_provider_mono_icon($provider)
	{
		$provider = self::provider_asset_slug($provider);

		if ($provider === '') {
			return false;
		}

		$path = SBR_PLUGIN_DIR . 'assets/icons/' . $provider . '-mono.svg';

		return file_exists($path) && is_readable($path);
	}

	/**
	 * Renders per inline-SVG family this request, so ids stay unique.
	 *
	 * @var array<string, int>
	 */
	protected static $inline_svg_counts = array();

	/**
	 * The full lockup — mark plus wordmark — for a provider.
	 *
	 * A badge that speaks for exactly ONE platform can name it properly instead
	 * of showing a bare mark; that is the design's "Full logo" layout. Deliberately
	 * NOT a fallback to the mark and NOT a fallback to Google: an absent lockup is
	 * answered by has_provider_lockup() at the call site, which then draws the
	 * mark. Returning something here would make "did we have artwork" unanswerable.
	 *
	 * Eleven of the fifteen providers have one. The rest (Formidable, WPForms,
	 * Zomato) have no supplied lockup, so they keep the mark — which is why the
	 * caller has to ask first.
	 *
	 * @since 2.10.0
	 *
	 * @param string $provider Provider slug, e.g. 'google', 'yelp'.
	 *
	 * @return string Inline SVG markup, or '' when there is no lockup.
	 */
	public static function get_provider_lockup($provider)
	{
		$provider = self::provider_asset_slug($provider);

		if ($provider === '') {
			return '';
		}

		$svg = self::read_icon_file('assets/icons/' . $provider . '-lockup.svg');

		// Same problem the mono icons have, from a different source: these files
		// keep their exporter ids. Measured across the set — edd-lockup REFERENCES
		// its clip-path id, and booking (29), yelp (7) and aliexpress (1) carry ids
		// nothing points at. Two badges for the same provider on one page, or a
		// badge plus the block editor's preview of it, emit each id twice, and a
		// duplicate id resolves to the first occurrence — identical today, wrong
		// the moment one copy's artwork is edited. The unreferenced ones are just
		// invalid markup, which is reason enough on a public page.
		return self::uniquify_ids($svg, 'lockup');
	}

	/**
	 * Whether a provider has a full lockup of its own.
	 *
	 * @since 2.10.0
	 *
	 * @param string $provider Provider slug, e.g. 'google', 'yelp'.
	 *
	 * @return bool
	 */
	public static function has_provider_lockup($provider)
	{
		$provider = self::provider_asset_slug($provider);

		if ($provider === '') {
			return false;
		}

		$lockup_path = SBR_PLUGIN_DIR . 'assets/icons/' . $provider . '-lockup.svg';

		return file_exists($lockup_path) && is_readable($lockup_path);
	}
}
